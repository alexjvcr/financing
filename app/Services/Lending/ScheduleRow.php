<?php

namespace App\Services\Lending;

use App\Enums\EstadoCuota;
use App\Models\Recibo;
use Illuminate\Support\Carbon;

/**
 * One line of a rebuilt payment plan: an installment, a principal
 * prepayment or the final payoff.
 */
final class ScheduleRow
{
    public const KIND_INSTALLMENT = 'cuota';

    public const KIND_PREPAYMENT = 'abono';

    public const KIND_PAYOFF = 'cancelacion';

    /**
     * @param  float  $principal  Paid principal for settled rows, scheduled principal for open ones.
     * @param  float  $interest  Paid interest for settled rows, scheduled interest for open ones.
     * @param  float  $balance  Principal balance after this row.
     * @param  float  $scheduledPrincipal  What the plan expected. For a payoff row, the balance owed on that date.
     * @param  float  $scheduledInterest  What the plan expected. For a payoff row, the interest accrued to that date.
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
     * Whether what was paid differs from what the plan expected.
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
