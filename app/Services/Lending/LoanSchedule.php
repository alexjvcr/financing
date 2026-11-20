<?php

namespace App\Services\Lending;

use App\Enums\EstadoCuota;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Un plan de pagos reconstruido a partir de los términos del préstamo, los recibos y las revisiones.
 */
final class LoanSchedule
{
    /**
     * @param  Collection<int, ScheduleRow>  $rows  Filas en orden cronológico.
     */
    public function __construct(
        public readonly Collection $rows,
        public readonly float $loanAmount,
        public readonly bool $hasPayoff,
        public readonly float $currentAnnualRate,
        public readonly ?LoanConditions $conditions = null,
    ) {}

    /**
     * Principal pagado hasta ahora mediante cuotas, abonos y cancelación.
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
     * Indica si el préstamo fue cancelado o si todo lo adeudado se pagó realmente.
     */
    public function isSettled(): bool
    {
        return $this->hasPayoff || round($this->loanAmount - $this->paidPrincipal(), 2) <= 0.005;
    }

    /**
     * Principal que aún se adeuda hoy.
     */
    public function outstandingPrincipal(): float
    {
        if ($this->isSettled()) {
            return 0.0;
        }

        return round($this->loanAmount - $this->paidPrincipal(), 2);
    }

    /**
     * Cuotas que aún están por pagar, las vencidas primero.
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
     * La primera cuota que todavía debe pagarse.
     */
    public function nextOpenInstallment(): ?ScheduleRow
    {
        return $this->openInstallments()->first();
    }

    /**
     * La primera cuota que aún no está vencida.
     */
    public function nextUpcomingInstallment(): ?ScheduleRow
    {
        return $this->rows->first(fn (ScheduleRow $row): bool => $row->isInstallment() && $row->status === EstadoCuota::Pendiente);
    }

    /**
     * Fecha de la última cuota, o de la cancelación cuando el préstamo fue cancelado.
     */
    public function maturityDate(): ?Carbon
    {
        return $this->rows
            ->filter(fn (ScheduleRow $row): bool => $row->kind !== ScheduleRow::KIND_PREPAYMENT)
            ->last()?->date;
    }

    /**
     * Monto de la cuota vigente actualmente.
     */
    public function currentInstallmentAmount(): float
    {
        return $this->nextOpenInstallment()?->total() ?? 0.0;
    }
}
