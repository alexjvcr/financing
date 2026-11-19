<?php

namespace App\Services\Lending;

use App\Models\Prestamo;
use App\Models\RevisionPrestamo;
use App\Support\DateFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers and voids rate and term revisions.
 */
class RevisionRegistrar
{
    public function __construct(protected LoanScheduleService $schedules) {}

    /**
     * @param  array{fecha_efectiva: Carbon|string, tasa_interes?: float|int|string|null, tasa_spread?: float|int|string|null, plazo_restante_meses?: int|string|null, observacion?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function register(Prestamo $loan, array $data, string $user): RevisionPrestamo
    {
        $date = Carbon::parse($data['fecha_efectiva'])->startOfDay();
        $base = $this->nullableFloat($data['tasa_interes'] ?? null);
        $spread = $this->nullableFloat($data['tasa_spread'] ?? null);
        $term = isset($data['plazo_restante_meses']) && $data['plazo_restante_meses'] !== '' ? (int) $data['plazo_restante_meses'] : null;

        $this->validate($loan, $date, $base, $spread, $term);

        return DB::transaction(function () use ($loan, $date, $base, $spread, $term, $data, $user): RevisionPrestamo {
            $revision = $loan->revisiones()->create([
                'fecha_efectiva' => $date,
                'tasa_interes' => $base,
                'tasa_spread' => $spread,
                'plazo_restante_meses' => $term,
                'observacion' => $data['observacion'] ?? null,
                'usuario' => $user,
            ]);

            $this->schedules->refreshSnapshot($loan);

            return $revision;
        });
    }

    /**
     * Void a revision. Only the latest active one can be voided.
     *
     * @throws ValidationException
     */
    public function void(RevisionPrestamo $revision): RevisionPrestamo
    {
        $loan = $revision->prestamo;
        $latest = $loan->revisiones()->active()->get()->last();

        if (! $revision->isActive()) {
            throw ValidationException::withMessages(['revision' => 'La revisión ya está anulada.']);
        }

        if ($latest?->id !== $revision->id) {
            throw ValidationException::withMessages(['revision' => 'Solo se puede anular la última revisión registrada del sub préstamo.']);
        }

        return DB::transaction(function () use ($revision, $loan): RevisionPrestamo {
            $revision->update(['anulado_at' => now()]);

            $this->schedules->refreshSnapshot($loan);

            return $revision;
        });
    }

    /**
     * @throws ValidationException
     */
    protected function validate(Prestamo $loan, Carbon $date, ?float $base, ?float $spread, ?int $term): void
    {
        if (! $loan->isActive()) {
            $this->fail('fecha_efectiva', 'El sub préstamo está liquidado y no admite revisiones.');
        }

        if ($base === null && $spread === null && $term === null) {
            $this->fail('tasa_interes', 'Indique la nueva tasa, el nuevo spread o el nuevo plazo restante.');
        }

        if (($base !== null && $base < 0) || ($spread !== null && $spread < 0)) {
            $this->fail('tasa_interes', 'Las tasas no pueden ser negativas.');
        }

        if ($term !== null && $term < 1) {
            $this->fail('plazo_restante_meses', 'El plazo restante debe ser de al menos 1 mes.');
        }

        if ($date->lt($loan->formalizacion)) {
            $this->fail('fecha_efectiva', 'La fecha no puede ser anterior a la formalización ('.DateFormat::format($loan->formalizacion).').');
        }

        $latest = $loan->revisiones()->active()->get()->last();
        if ($latest !== null && $date->lt($latest->fecha_efectiva)) {
            $this->fail('fecha_efectiva', 'La fecha no puede ser anterior a la última revisión ('.DateFormat::format($latest->fecha_efectiva).').');
        }

        $lastPaid = $this->schedules->scheduleFor($loan)->rows
            ->filter(fn (ScheduleRow $row): bool => $row->isInstallment() && ! $row->status->isOpen())
            ->last();
        if ($lastPaid !== null && $date->lt($lastPaid->date)) {
            $this->fail('fecha_efectiva', 'La revisión no puede ser retroactiva a una cuota ya pagada ('.DateFormat::format($lastPaid->date).').');
        }
    }

    protected function nullableFloat(float|int|string|null $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 4);
    }

    /**
     * @throws ValidationException
     */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
