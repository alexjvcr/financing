<?php

namespace App\Services\Lending;

use App\Enums\TipoRecibo;
use App\Models\Recibo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the legacy planpagos table in step with the receipts.
 *
 * The payment plan is rebuilt dynamically, but the old table still holds one
 * row per installment with a pending (P) or paid (C) status. Paying a full
 * installment marks its row as paid; voiding the receipt restores it.
 */
class LegacyPlanSync
{
    public const PAID = 'C';

    public const PENDING = 'P';

    /**
     * Mark the installment settled by a full-installment receipt as paid.
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
     * Put the installment of a voided receipt back to pending.
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
     * Only full installments close a row of the old plan, and only when that table exists.
     */
    protected function appliesTo(Recibo $receipt): bool
    {
        return $receipt->tipo === TipoRecibo::CuotaCompleta
            && $receipt->numero_cuota !== null
            && Schema::hasTable('planpagos');
    }
}
