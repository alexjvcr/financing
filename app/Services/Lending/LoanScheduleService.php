<?php

namespace App\Services\Lending;

use App\Models\Prestamo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Loads a loan's receipts and revisions and rebuilds its payment plan.
 */
class LoanScheduleService
{
    public function __construct(protected LoanScheduleBuilder $builder) {}

    public function scheduleFor(Prestamo $loan, ?Carbon $today = null): LoanSchedule
    {
        $loan->loadMissing('linea');

        return $this->builder->build(
            $loan,
            $loan->linea,
            $loan->recibos()->active()->get(),
            $loan->revisiones()->active()->get(),
            $today,
        );
    }

    /**
     * Rebuilt plans of every active loan.
     *
     * @return Collection<int, array{loan: Prestamo, schedule: LoanSchedule}>
     */
    public function activeSchedules(?Carbon $today = null): Collection
    {
        return Prestamo::query()
            ->active()
            ->with([
                'linea.banco',
                'recibos' => fn ($query) => $query->active(),
                'revisiones' => fn ($query) => $query->active(),
            ])
            ->orderBy('numero_prestamo')
            ->get()
            ->map(fn (Prestamo $loan): array => [
                'loan' => $loan,
                'schedule' => $this->builder->build($loan, $loan->linea, $loan->recibos, $loan->revisiones, $today),
            ]);
    }

    /**
     * Quote what it takes to pay the loan off on a given date.
     *
     * @return array{principal: float, interest: float}
     */
    public function payoffQuote(Prestamo $loan, Carbon $date): array
    {
        $loan->loadMissing('linea');

        return $this->builder->payoffQuote(
            $loan,
            $loan->linea,
            $loan->recibos()->active()->get(),
            $loan->revisiones()->active()->get(),
            $date,
        );
    }

    /**
     * Store the figures derived from the rebuilt plan on the loan row and refresh
     * the available credit of its line.
     *
     * The contractual installment (cuota_calculada) is deliberately left
     * alone because the plan uses it as its starting installment.
     */
    public function refreshSnapshot(Prestamo $loan): LoanSchedule
    {
        $schedule = $this->scheduleFor($loan);

        // Written through the query builder so the row is updated even when this
        // in-memory model already holds the same values as a stale copy of the row.
        $loan->newQuery()->whereKey($loan->getKey())->update([
            'saldo_prestamo' => $schedule->outstandingPrincipal(),
            'estado' => $schedule->isSettled() ? Prestamo::STATUS_SETTLED : Prestamo::STATUS_ACTIVE,
            'proximo_pago' => $schedule->nextOpenInstallment()?->date ?? $loan->proximo_pago,
            'vencimiento' => $schedule->maturityDate() ?? $loan->vencimiento,
            'updated_at' => now(),
        ]);
        $loan->refresh();

        $loan->linea->refreshAvailable();

        return $schedule;
    }
}
