<?php

namespace App\Services\Lending;

use App\Enums\TipoRecibo;
use App\Models\DetallePago;
use App\Models\Recibo;
use App\Models\TipoPago;
use Illuminate\Support\Carbon;

/**
 * Escribe las líneas de detalle de pago (detalle_pago) de un recibo.
 *
 * Cada tipo de recibo se desglosa en tipos de pago del catálogo tipos_pago:
 *  - Cuota completa: PP (principal) + PI (interés)
 *  - Cuota de solo interés: PC (pago parcial)
 *  - Solo principal: PP
 *  - Adelanto de cuota: PA
 *  - Cancelación: CT (saldo) + PI (interés devengado)
 * Los otros rubros pagados con el recibo (monto_otros) se convierten en una línea adicional, OT por defecto.
 */
class ReceiptDetailWriter
{
    /**
     * @param  int|null  $installmentNumber  Cuota a la que se aplica el pago, tomada del plan de pagos.
     * @param  Carbon|null  $installmentDate  Fecha de vencimiento de esa cuota.
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
     * Código de tipo de pago => monto para el principal y el interés de un recibo.
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
