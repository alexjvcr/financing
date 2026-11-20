<?php

namespace App\Services\Lending;

use App\Enums\TipoRecibo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\TipoPago;
use App\Support\DateFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra y anula recibos, haciendo cumplir las reglas que mantienen bien
 * definido el plan reconstruido.
 */
class ReceiptRegistrar
{
    public function __construct(
        protected LoanScheduleService $schedules,
        protected ReceiptDetailWriter $details,
        protected LegacyPlanSync $legacyPlan,
    ) {}

    /**
     * Registra un recibo y su detalle de pago, y actualiza el préstamo y su línea de crédito.
     *
     * @param  array{tipo: TipoRecibo|string, fecha_pago: Carbon|string, numero_cuota?: int|null, monto_principal?: float|int|string|null, monto_interes?: float|int|string|null, monto_otros?: float|int|string|null, tipo_pago_otros_id?: int|string|null, descripcion_otros?: string|null, observaciones?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function register(Prestamo $loan, array $data, string $user): Recibo
    {
        $type = $data['tipo'] instanceof TipoRecibo ? $data['tipo'] : TipoRecibo::from($data['tipo']);
        $date = Carbon::parse($data['fecha_pago'])->startOfDay();
        $principal = round((float) ($data['monto_principal'] ?? 0), 2);
        $interest = round((float) ($data['monto_interes'] ?? 0), 2);
        $other = round((float) ($data['monto_otros'] ?? 0), 2);
        $number = $type->isInstallmentPayment() ? (int) ($data['numero_cuota'] ?? 0) : null;

        $schedule = $this->validate($loan, $type, $date, $number, $principal, $interest);
        $otherType = $this->resolveOtherType($other, $data['tipo_pago_otros_id'] ?? null);
        $next = $schedule->nextOpenInstallment();

        return DB::transaction(function () use ($loan, $type, $date, $number, $principal, $interest, $other, $otherType, $next, $data, $user): Recibo {
            $receipt = $loan->recibos()->create([
                'tipo' => $type,
                'numero_cuota' => $number,
                'fecha_pago' => $date,
                'monto_principal' => $principal,
                'monto_interes' => $interest,
                'monto_otros' => $other,
                'observaciones' => $data['observaciones'] ?? null,
                'usuario' => $user,
                'estado' => Recibo::STATUS_ACTIVE,
            ]);

            $this->details->write($receipt, $next?->number, $next?->date, $otherType, $data['descripcion_otros'] ?? null);
            $this->legacyPlan->markPaid($receipt);

            $this->schedules->refreshSnapshot($loan);

            return $receipt;
        });
    }

    /**
     * Anula un recibo. Los recibos se anulan del más reciente al más antiguo, de modo que la
     * secuencia de pagos nunca tenga un hueco: un pago no se puede deshacer mientras exista uno posterior.
     *
     * La anulación restaura el saldo del préstamo, devuelve la cuota a pendiente en el plan
     * (y en la tabla heredada planpagos), actualiza el crédito disponible de la línea y
     * deja la huella de auditoría: quién lo anuló, cuándo y por qué.
     *
     * @throws ValidationException
     */
    public function void(Recibo $receipt, string $reason, string $user): Recibo
    {
        $loan = $receipt->prestamo;

        if (! $receipt->isActive()) {
            throw ValidationException::withMessages(['recibo' => 'El recibo ya está anulado.']);
        }

        $latest = $this->latestActive($loan);

        if ($latest?->id !== $receipt->id) {
            throw ValidationException::withMessages(['recibo' => 'Los recibos se anulan del más reciente al más antiguo. Anule primero el recibo #'.$latest->id.' del '.DateFormat::format($latest->fecha_pago).'.']);
        }

        return DB::transaction(function () use ($receipt, $loan, $reason, $user): Recibo {
            $receipt->update([
                'estado' => Recibo::STATUS_VOIDED,
                'anulado_at' => now(),
                'anulado_por' => $user,
                'motivo_anulacion' => $reason,
            ]);

            $this->legacyPlan->restore($receipt);
            $this->schedules->refreshSnapshot($loan);

            return $receipt;
        });
    }

    /**
     * El recibo activo más reciente de un préstamo: el único que se puede anular ahora.
     */
    public function latestActive(Prestamo $loan): ?Recibo
    {
        return $loan->recibos()->active()->get()->last();
    }

    /**
     * Tipo de pago usado para el monto de "otros rubros": el elegido u OT.
     *
     * @throws ValidationException
     */
    protected function resolveOtherType(float $amount, int|string|null $typeId): ?TipoPago
    {
        if ($amount <= 0) {
            return null;
        }

        $type = $typeId ? TipoPago::find($typeId) : TipoPago::where('codigo', TipoPago::OTHER)->first();

        if ($type === null) {
            $this->fail('tipo_pago_otros_id', 'El tipo de pago de los otros rubros no existe.');
        }

        if ($type->isSystem() && $type->codigo !== TipoPago::OTHER) {
            $this->fail('tipo_pago_otros_id', "El tipo {$type->codigo} se calcula solo; elija OT u otro tipo propio para los otros rubros.");
        }

        return $type;
    }

    /**
     * @throws ValidationException
     */
    protected function validate(Prestamo $loan, TipoRecibo $type, Carbon $date, ?int $number, float $principal, float $interest): LoanSchedule
    {
        if (! $loan->isActive()) {
            $this->fail('tipo', 'El sub préstamo está liquidado y no admite más recibos.');
        }

        if ($principal < 0 || $interest < 0) {
            $this->fail('monto_principal', 'Los montos no pueden ser negativos.');
        }

        if ($date->lte($loan->formalizacion)) {
            $this->fail('fecha_pago', 'La fecha del recibo debe ser posterior a la formalización del sub préstamo ('.DateFormat::format($loan->formalizacion).').');
        }

        $latest = $loan->recibos()->active()->get()->last();
        if ($latest !== null && $date->lt($latest->fecha_pago)) {
            $this->fail('fecha_pago', 'La fecha no puede ser anterior al último recibo registrado ('.DateFormat::format($latest->fecha_pago).').');
        }

        $schedule = $this->schedules->scheduleFor($loan);
        $outstanding = $schedule->outstandingPrincipal();

        match ($type) {
            TipoRecibo::CuotaCompleta => $this->validateInstallment($schedule, $number, $principal, $interest, requirePrincipal: true),
            TipoRecibo::ParcialInteres => $this->validateInstallment($schedule, $number, $principal, $interest, requirePrincipal: false),
            TipoRecibo::SoloPrincipal, TipoRecibo::AdelantoCuota => $this->validatePrepayment($outstanding, $principal, $interest),
            TipoRecibo::CancelacionTotal => $this->validatePayoff($loan, $date, $principal, $schedule),
        };

        return $schedule;
    }

    /**
     * @throws ValidationException
     */
    protected function validateInstallment(LoanSchedule $schedule, ?int $number, float $principal, float $interest, bool $requirePrincipal): void
    {
        $next = $schedule->nextOpenInstallment();

        if ($next === null) {
            $this->fail('numero_cuota', 'El sub préstamo no tiene cuotas pendientes.');
        }

        if ($number !== $next->number) {
            $this->fail('numero_cuota', "La siguiente cuota por pagar es la número {$next->number}.");
        }

        if ($requirePrincipal && $principal <= 0 && $next->scheduledPrincipal > 0) {
            $this->fail('monto_principal', 'Una cuota completa debe incluir pago a principal.');
        }

        if (! $requirePrincipal && $principal > 0) {
            $this->fail('monto_principal', 'Un pago parcial de solo interés no puede incluir principal.');
        }

        if ($interest <= 0) {
            $this->fail('monto_interes', 'Indique el interés pagado.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validatePrepayment(float $outstanding, float $principal, float $interest): void
    {
        if ($principal <= 0) {
            $this->fail('monto_principal', 'Indique el monto a principal.');
        }

        if ($interest > 0) {
            $this->fail('monto_interes', 'Este tipo de recibo solo aplica a principal.');
        }

        if ($principal >= $outstanding - 0.005) {
            $this->fail('monto_principal', 'El monto cubre todo el saldo ('.number_format($outstanding, 2).'). Registre un pago de cancelación total.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validatePayoff(Prestamo $loan, Carbon $date, float $principal, LoanSchedule $schedule): void
    {
        $overdue = $schedule->openInstallments()->first(fn (ScheduleRow $row): bool => $row->date->lt($date));

        if ($overdue !== null) {
            $this->fail('fecha_pago', "Antes de cancelar debe pagar las cuotas con vencimiento anterior a esa fecha (la cuota {$overdue->number} del ".DateFormat::format($overdue->date).').');
        }

        $quote = $this->schedules->payoffQuote($loan, $date);

        if (abs($principal - $quote['principal']) > 0.01) {
            $this->fail('monto_principal', 'La cancelación total debe pagar el saldo a esa fecha ('.number_format($quote['principal'], 2).').');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
