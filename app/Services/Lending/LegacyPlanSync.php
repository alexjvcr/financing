<?php

namespace App\Services\Lending;

use App\Enums\EstadoCuota;
use App\Enums\TipoRecibo;
use App\Models\Prestamo;
use App\Models\Recibo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mantiene la tabla heredada planpagos al día con el plan de pagos.
 *
 * El plan se reconstruye de forma dinámica, pero la tabla antigua aún guarda una fila por
 * cuota con estado pendiente (P) o pagada (C). Esta clase escribe en ella las cuotas
 * calculadas y las marca como pagadas o pendientes conforme se registran o
 * anulan recibos.
 */
class LegacyPlanSync
{
    public const PAID = 'C';

    public const PENDING = 'P';

    /**
     * Marca como pagada la cuota saldada por un recibo de cuota completa.
     */
    public function markPaid(Recibo $receipt): void
    {
        if (! $this->appliesTo($receipt)) {
            return;
        }

        DB::table('planpagos')
            ->where('prestamo_id', $receipt->prestamo_id)
            ->where('numero_cuota', $receipt->numero_cuota)
            ->update(['estado' => self::PAID, 'fecha_pago_real' => $receipt->fecha_pago->toDateString(), 'updated_at' => now()]);
    }

    /**
     * Devuelve a pendiente la cuota de un recibo anulado.
     */
    public function restore(Recibo $receipt): void
    {
        if (! $this->appliesTo($receipt)) {
            return;
        }

        DB::table('planpagos')
            ->where('prestamo_id', $receipt->prestamo_id)
            ->where('numero_cuota', $receipt->numero_cuota)
            ->update(['estado' => self::PENDING, 'fecha_pago_real' => null, 'updated_at' => now()]);
    }

    /**
     * Escribe en planpagos las cuotas calculadas de un préstamo.
     *
     * - Las cuotas faltantes se insertan y las guardadas se actualizan.
     * - Las filas pendientes más allá del final del plan (por ejemplo, cuando los abonos lo acortan) se eliminan.
     * - Una fila ya marcada como pagada cuya cuota aún no tiene recibo se deja intacta.
     *
     * @return array{inserted: int, updated: int, deleted: int}
     */
    public function reflect(Prestamo $loan, LoanSchedule $schedule, bool $dryRun = false): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

        if (! Schema::hasTable('planpagos')) {
            return $result;
        }

        $installments = $schedule->rows->filter(fn (ScheduleRow $row): bool => $row->isInstallment());
        $existing = DB::table('planpagos')->where('prestamo_id', $loan->id)->get()->keyBy(fn (object $row): int => (int) $row->numero_cuota);
        $now = now();

        $apply = function () use ($loan, $installments, $existing, $now, $dryRun, &$result): void {
            foreach ($installments as $row) {
                $current = $existing->get($row->number);

                if ($current !== null && $current->estado === self::PAID && $row->status !== EstadoCuota::Pagada) {
                    continue;
                }

                $values = $this->valuesFor($row);

                if ($current === null) {
                    $result['inserted']++;
                    $dryRun || DB::table('planpagos')->insert($values + ['prestamo_id' => $loan->id, 'numero_cuota' => $row->number, 'created_at' => $now, 'updated_at' => $now]);

                    continue;
                }

                if ($this->differs($current, $values)) {
                    $result['updated']++;
                    $dryRun || DB::table('planpagos')->where('id', $current->id)->update($values + ['updated_at' => $now]);
                }
            }

            $kept = $installments->pluck('number')->all();
            $obsolete = $existing->filter(fn (object $row, int $number): bool => ! in_array($number, $kept, true) && $row->estado !== self::PAID);

            $result['deleted'] = $obsolete->count();
            if (! $dryRun && $obsolete->isNotEmpty()) {
                DB::table('planpagos')->whereIn('id', $obsolete->pluck('id')->all())->delete();
            }
        };

        $dryRun ? $apply() : DB::transaction($apply);

        return $result;
    }

    /**
     * Elimina todas las filas de cuotas de un préstamo (se usa cuando se borra el préstamo).
     */
    public function purge(Prestamo $loan): void
    {
        if (Schema::hasTable('planpagos')) {
            DB::table('planpagos')->where('prestamo_id', $loan->id)->delete();
        }
    }

    /**
     * Cantidad de cuotas del préstamo ya saldadas en la tabla heredada.
     */
    public function paidInstallments(Prestamo $loan): int
    {
        if (! Schema::hasTable('planpagos')) {
            return 0;
        }

        return DB::table('planpagos')->where('prestamo_id', $loan->id)->where('estado', self::PAID)->count();
    }

    /**
     * Valores de columna de una fila de planpagos para una fila del plan.
     *
     * @return array<string, mixed>
     */
    protected function valuesFor(ScheduleRow $row): array
    {
        $isPaid = $row->status === EstadoCuota::Pagada;
        $isOpen = $row->status->isOpen();

        return [
            'fecha_pago' => $row->date->toDateString(),
            'monto_principal' => $row->principal,
            'monto_interes' => $row->interest,
            'tasa_interes' => round($row->annualRate, 2),
            'saldo_principal' => $isOpen ? $row->principal : 0,
            'saldo_interes' => $isOpen ? $row->interest : 0,
            'saldo_prestamo' => $row->balance,
            'estado' => $isPaid ? self::PAID : self::PENDING,
            'fecha_pago_real' => $isPaid ? $row->receipt?->fecha_pago->toDateString() : null,
            'observaciones' => $row->status === EstadoCuota::ParcialInteres ? 'Pago parcial: solo interés' : null,
        ];
    }

    /**
     * Indica si una fila guardada difiere de los valores calculados para ella.
     *
     * @param  array<string, mixed>  $values
     */
    protected function differs(object $current, array $values): bool
    {
        foreach (['monto_principal', 'monto_interes', 'tasa_interes', 'saldo_principal', 'saldo_interes', 'saldo_prestamo'] as $column) {
            if (abs((float) $current->{$column} - (float) $values[$column]) > 0.004) {
                return true;
            }
        }

        return substr((string) $current->fecha_pago, 0, 10) !== $values['fecha_pago']
            || $current->estado !== $values['estado']
            || substr((string) $current->fecha_pago_real, 0, 10) !== substr((string) $values['fecha_pago_real'], 0, 10)
            || ($current->observaciones ?? null) !== $values['observaciones'];
    }

    /**
     * Solo las cuotas completas cierran una fila del plan antiguo, y solo cuando esa tabla existe.
     */
    protected function appliesTo(Recibo $receipt): bool
    {
        return $receipt->tipo === TipoRecibo::CuotaCompleta
            && $receipt->numero_cuota !== null
            && Schema::hasTable('planpagos');
    }
}
