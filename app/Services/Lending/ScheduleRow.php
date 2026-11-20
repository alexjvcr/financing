<?php

namespace App\Services\Lending;

use App\Enums\EstadoCuota;
use App\Models\Recibo;
use Illuminate\Support\Carbon;

/**
 * Una línea de un plan de pagos reconstruido: una cuota, un abono a principal
 * o la cancelación final.
 */
final class ScheduleRow
{
    public const KIND_INSTALLMENT = 'cuota';

    public const KIND_PREPAYMENT = 'abono';

    public const KIND_PAYOFF = 'cancelacion';

    /**
     * @param  float  $principal  Principal pagado en las filas saldadas, principal programado en las abiertas.
     * @param  float  $interest  Interés pagado en las filas saldadas, interés programado en las abiertas.
     * @param  float  $balance  Saldo de principal después de esta fila.
     * @param  float  $scheduledPrincipal  Lo que esperaba el plan. En una fila de cancelación, el saldo adeudado en esa fecha.
     * @param  float  $scheduledInterest  Lo que esperaba el plan. En una fila de cancelación, el interés acumulado a esa fecha.
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $number,
        public readonly Carbon $date,
        public readonly EstadoCuota $status,
        public readonly float $annualRate,
        public readonly float $principal,
        public readonly float $interest,
        public readonly float $balance,
        public readonly float $scheduledPrincipal,
        public readonly float $scheduledInterest,
        public readonly ?Recibo $receipt = null,
    ) {}

    public function total(): float
    {
        return round($this->principal + $this->interest, 2);
    }

    public function isInstallment(): bool
    {
        return $this->kind === self::KIND_INSTALLMENT;
    }

    public function isOpen(): bool
    {
        return $this->isInstallment() && $this->status->isOpen();
    }

    /**
     * Indica si lo pagado difiere de lo que esperaba el plan.
     */
    public function hasVariance(): bool
    {
        if (! $this->isInstallment() || $this->status->isOpen()) {
            return false;
        }

        return abs($this->principal - $this->scheduledPrincipal) > 0.01
            || abs($this->interest - $this->scheduledInterest) > 0.01;
    }
}
