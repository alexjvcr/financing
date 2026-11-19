<?php

namespace App\Services\Lending;

use App\Enums\TipoRecibo;
use App\Models\DetallePago;
use App\Models\Recibo;
use App\Models\TipoPago;
use Illuminate\Support\Carbon;

/**
 * Writes the payment detail lines (detalle_pago) of a receipt.
 *
 * Each receipt type breaks down into payment types of the tipos_pago catalog:
 *  - Full installment: PP (principal) + PI (interest)
 *  - Interest-only installment: PC (partial payment)
 *  - Principal only: PP
 *  - Advance payment: PA
 *  - Payoff: CT (balance) + PI (accrued interest)
 * Other items paid with the receipt (monto_otros) become one extra line, OT by default.
 */
class ReceiptDetailWriter
{
    /**
     * @param  int|null  $installmentNumber  Installment the payment is applied to, taken from the payment plan.
     * @param  Carbon|null  $installmentDate  Due date of that installment.
     * @return array<int, DetallePago>
     */
    public function write(
        Recibo $receipt,
        ?int $installmentNumber,
        ?Carbon $installmentDate,
        ?TipoPago $otherType = null,
        ?string $otherDescription = null,
    ): array {
        $codes = $this->codesFor($receipt);
        $types = TipoPago::whereIn('codigo', [...array_keys($codes), TipoPago::OTHER])->get()->keyBy('codigo');

        $lines = [];

        foreach ($codes as $code => $amount) {
            if ($amount > 0) {
                $lines[] = [$types[$code], $amount, null];
            }
        }

        if ($receipt->monto_otros > 0) {
            $lines[] = [$otherType ?? $types[TipoPago::OTHER], $receipt->monto_otros, $otherDescription];
        }

        return array_map(fn (array $line): DetallePago => DetallePago::create([
            'recibo_id' => $receipt->id,
            'prestamo_id' => $receipt->prestamo_id,
            'tipo_pago_id' => $line[0]->id,
            'numero_cuota' => $installmentNumber,
            'fecha_cuota' => $installmentDate,
            'fecha_pago' => $receipt->fecha_pago,
            'monto' => $line[1],
            'descripcion' => $line[2],
        ]), $lines);
    }

    /**
     * Payment type code => amount for the principal and interest of a receipt.
     *
     * @return array<string, float>
     */
    protected function codesFor(Recibo $receipt): array
    {
        return match ($receipt->tipo) {
            TipoRecibo::CuotaCompleta => [TipoPago::PRINCIPAL => $receipt->monto_principal, TipoPago::INTEREST => $receipt->monto_interes],
            TipoRecibo::ParcialInteres => [TipoPago::PARTIAL => $receipt->monto_interes],
            TipoRecibo::SoloPrincipal => [TipoPago::PRINCIPAL => $receipt->monto_principal],
            TipoRecibo::AdelantoCuota => [TipoPago::ADVANCE => $receipt->monto_principal],
            TipoRecibo::CancelacionTotal => [TipoPago::PAYOFF => $receipt->monto_principal, TipoPago::INTEREST => $receipt->monto_interes],
        };
    }
}
