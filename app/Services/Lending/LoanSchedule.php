<?php

namespace App\Services\Lending;

use App\Enums\EstadoCuota;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A payment plan rebuilt from the loan terms, receipts and revisions.
 */
final class LoanSchedule
{
    /**
     * @param  Collection<int, ScheduleRow>  $rows  Chronological rows.
     */
    public function __construct(
        public readonly Collection $rows,
        public readonly float $loanAmount,
        public readonly bool $hasPayoff,
        public readonly float $currentAnnualRate,
    ) {}

    /**
     * Principal paid so far through installments, prepayments and payoff.
     */
    public function paidPrincipal(): float
    {
        return round($this->rows
            ->filter(fn (ScheduleRow $row): bool => in_array($row->status, [
                EstadoCuota::Pagada, EstadoCuota::ParcialInteres, EstadoCuota::Abono, EstadoCuota::Cancelacion,
            ], true))
            ->sum('principal'), 2);
    }

    public function paidInterest(): float
    {
        return round($this->rows
            ->filter(fn (ScheduleRow $row): bool => ! $row->status->isOpen() && $row->status !== EstadoCuota::Abono)
            ->sum('interest'), 2);
    }

    /**
     * Whether the loan was cancelled or everything owed has really been paid.
     */
    public function isSettled(): bool
    {
        return $this->hasPayoff || round($this->loanAmount - $this->paidPrincipal(), 2) <= 0.005;
    }

    /**
     * Principal still owed today.
     */
    public function outstandingPrincipal(): float
    {
        if ($this->isSettled()) {
            return 0.0;
        }

        return round($this->loanAmount - $this->paidPrincipal(), 2);
    }

    /**
     * Installments that are still to be paid, overdue ones first.
     *
     * @return Collection<int, ScheduleRow>
     */
    public function openInstallments(): Collection
    {
        return $this->rows->filter(fn (ScheduleRow $row): bool => $row->isOpen())->values();
    }

    /**
     * @return Collection<int, ScheduleRow>
     */
    public function overdueInstallments(): Collection
    {
        return $this->rows->filter(fn (ScheduleRow $row): bool => $row->isInstallment() && $row->status === EstadoCuota::Vencida)->values();
    }

    /**
     * The first installment that still has to be paid.
     */
    public function nextOpenInstallment(): ?ScheduleRow
    {
        return $this->openInstallments()->first();
    }

    /**
     * The first installment that is not overdue yet.
     */
    public function nextUpcomingInstallment(): ?ScheduleRow
    {
        return $this->rows->first(fn (ScheduleRow $row): bool => $row->isInstallment() && $row->status === EstadoCuota::Pendiente);
    }

    /**
     * Date of the last installment, or of the payoff when the loan was cancelled.
     */
    public function maturityDate(): ?Carbon
    {
        return $this->rows
            ->filter(fn (ScheduleRow $row): bool => $row->kind !== ScheduleRow::KIND_PREPAYMENT)
            ->last()?->date;
    }

    /**
     * Installment amount currently in force.
     */
    public function currentInstallmentAmount(): float
    {
        return $this->nextOpenInstallment()?->total() ?? 0.0;
    }
}
