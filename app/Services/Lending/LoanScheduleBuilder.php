<?php

namespace App\Services\Lending;

use App\Enums\EstadoCuota;
use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\RevisionPrestamo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rebuilds a loan's payment plan from scratch every time it is requested.
 *
 * Inputs are the loan terms, the active receipts and the active rate/term
 * revisions; nothing is read from or written to the database.
 *
 * Rules:
 *  - Interest accrues on the outstanding balance at (annual rate / 12) per
 *    month, prorated on 30/360 when an event falls inside a period.
 *  - A numbered receipt (full installment or interest only) closes that
 *    installment with the amounts actually paid.
 *  - A principal prepayment lowers the balance from its date. The installment
 *    stays the same, so the loan finishes earlier.
 *  - An interest-only receipt defers the principal: the installment is
 *    recalculated over the remaining periods.
 *  - A rate/term revision recalculates the installment from the next period.
 *  - A payoff receipt closes the loan on its date.
 *  - Unpaid installments are projected as if they were paid on time.
 */
final class LoanScheduleBuilder
{
    private const MAX_PERIODS = 1200;

    private const EPSILON = 0.005;

    /**
     * @param  iterable<Recibo>  $receipts
     * @param  iterable<RevisionPrestamo>  $revisions
     */
    public function build(
        Prestamo $loan,
        LineaPrestamo $line,
        iterable $receipts,
        iterable $revisions,
        ?Carbon $today = null,
    ): LoanSchedule {
        $today = ($today ?? today())->copy()->startOfDay();
        $months = max($line->monthsPerPeriod(), 1);
        $modality = $line->modalidad_cuota;
        $start = $loan->formalizacion->copy()->startOfDay();

        $receipts = collect($receipts)
            ->filter(fn (Recibo $receipt): bool => $receipt->isActive())
            ->sortBy([['fecha_pago', 'asc'], ['id', 'asc']])
            ->values();
        $installmentReceipts = $receipts
            ->filter(fn (Recibo $receipt): bool => $receipt->tipo->isInstallmentPayment())
            ->keyBy('numero_cuota');
        $prepayments = $receipts
            ->filter(fn (Recibo $receipt): bool => $receipt->tipo->isPrincipalPrepayment())
            ->values();
        $payoff = $receipts->first(fn (Recibo $receipt): bool => $receipt->tipo === TipoRecibo::CancelacionTotal);

        $revisions = collect($revisions)
            ->filter(fn (RevisionPrestamo $revision): bool => $revision->isActive())
            ->sortBy([['fecha_efectiva', 'asc'], ['id', 'asc']])
            ->values();
        $revisionIndex = 0;

        $balance = round($loan->monto_prestamo, 2);
        $base = $loan->tasa_interes;
        $spread = $loan->tasa_spread;
        $rate = $base + $spread;
        $remaining = max((int) ceil($loan->plazo_meses / $months), 1);

        [$installment, $fixedPrincipal] = $this->amortization($balance, $rate, $remaining, $months);
        if ($modality === 'CONSTANTE' && $loan->cuota_calculada > 0) {
            $installment = round($loan->cuota_calculada, 2);
        }

        /** @var Collection<int, ScheduleRow> $rows */
        $rows = collect();
        $needsRecalculation = false;
        $isClosed = false;
        $previousDue = $start;

        for ($number = 1; $balance > self::EPSILON && $number <= self::MAX_PERIODS; $number++) {
            $due = $start->copy()->addMonthsNoOverflow($number * $months);

            while ($revisionIndex < $revisions->count() && $revisions[$revisionIndex]->fecha_efectiva->lte($previousDue)) {
                /** @var RevisionPrestamo $revision */
                $revision = $revisions[$revisionIndex++];
                $base = $revision->tasa_interes ?? $base;
                $spread = $revision->tasa_spread ?? $spread;
                $rate = $base + $spread;

                if ($revision->plazo_restante_meses !== null) {
                    $remaining = max((int) ceil($revision->plazo_restante_meses / $months), 1);
                }

                $needsRecalculation = true;
            }

            if ($needsRecalculation) {
                [$installment, $fixedPrincipal] = $this->amortization($balance, $rate, $remaining, $months);
                $needsRecalculation = false;
            }

            $paidReceipt = $installmentReceipts->get($number);

            if ($payoff !== null && $this->payoffFallsInPeriod($payoff, $previousDue, $due, $paidReceipt !== null)) {
                [$accrued, $balanceAtPayoff, $payoffRate, $prepaymentRows] = $this->accrue(
                    $previousDue, $payoff->fecha_pago, $previousDue, $due, $balance, $base, $spread, $months, $prepayments, $revisions,
                );

                $rows = $rows->concat($prepaymentRows);
                $rows->push(new ScheduleRow(
                    ScheduleRow::KIND_PAYOFF, null, $payoff->fecha_pago->copy(), EstadoCuota::Cancelacion, $payoffRate,
                    $payoff->monto_principal, $payoff->monto_interes, max(round($balanceAtPayoff - $payoff->monto_principal, 2), 0.0),
                    $balanceAtPayoff, round($accrued, 2), $payoff,
                ));

                $balance = 0.0;
                $isClosed = true;

                break;
            }

            [$interest, $balanceAtDue, $rateAtDue, $prepaymentRows] = $this->accrue(
                $previousDue, $due, $previousDue, $due, $balance, $base, $spread, $months, $prepayments, $revisions,
            );
            $interest = round($interest, 2);
            $rows = $rows->concat($prepaymentRows);

            $scheduledPrincipal = $this->scheduledPrincipal($modality, $installment, $fixedPrincipal, $interest, $balanceAtDue, $remaining);

            if ($paidReceipt !== null) {
                $paidPrincipal = $paidReceipt->tipo === TipoRecibo::CuotaCompleta ? $paidReceipt->monto_principal : 0.0;
                $status = $paidReceipt->tipo === TipoRecibo::CuotaCompleta ? EstadoCuota::Pagada : EstadoCuota::ParcialInteres;
                $principal = $paidPrincipal;
                $paidInterest = $paidReceipt->monto_interes;
                $needsRecalculation = $paidReceipt->tipo === TipoRecibo::ParcialInteres;
            } else {
                $status = $due->lt($today) ? EstadoCuota::Vencida : EstadoCuota::Pendiente;
                $principal = $scheduledPrincipal;
                $paidInterest = $interest;
            }

            $balanceAfter = max(round($balanceAtDue - $principal, 2), 0.0);

            $rows->push(new ScheduleRow(
                ScheduleRow::KIND_INSTALLMENT, $number, $due->copy(), $status, $rateAtDue,
                $principal, $paidInterest, $balanceAfter, $scheduledPrincipal, $interest, $paidReceipt,
            ));

            foreach ($prepayments->filter(fn (Recibo $receipt): bool => $receipt->fecha_pago->equalTo($due)) as $prepayment) {
                $amount = min($prepayment->monto_principal, $balanceAfter);
                if ($amount <= 0) {
                    continue;
                }

                $balanceAfter = round($balanceAfter - $amount, 2);
                $rows->push(new ScheduleRow(
                    ScheduleRow::KIND_PREPAYMENT, null, $due->copy(), EstadoCuota::Abono, $rateAtDue,
                    $amount, 0.0, $balanceAfter, $amount, 0.0, $prepayment,
                ));
            }

            $balance = $balanceAfter;
            $remaining = max($remaining - 1, 1);
            $previousDue = $due;
        }

        return new LoanSchedule(
            $this->sortRows($rows),
            round($loan->monto_prestamo, 2),
            $isClosed,
            $this->rateInForce($loan, $revisions, $today),
        );
    }

    /**
     * Annual rate in percent that applies on the given date.
     *
     * @param  Collection<int, RevisionPrestamo>  $revisions
     */
    private function rateInForce(Prestamo $loan, Collection $revisions, Carbon $date): float
    {
        $base = $loan->tasa_interes;
        $spread = $loan->tasa_spread;

        foreach ($revisions->filter(fn (RevisionPrestamo $revision): bool => $revision->fecha_efectiva->lte($date)) as $revision) {
            $base = $revision->tasa_interes ?? $base;
            $spread = $revision->tasa_spread ?? $spread;
        }

        return $base + $spread;
    }

    /**
     * Quote what it takes to pay the loan off on a given date.
     *
     * @return array{principal: float, interest: float}
     */
    public function payoffQuote(
        Prestamo $loan,
        LineaPrestamo $line,
        iterable $receipts,
        iterable $revisions,
        Carbon $date,
    ): array {
        $probe = new Recibo([
            'tipo' => TipoRecibo::CancelacionTotal,
            'fecha_pago' => $date,
            'monto_principal' => 0,
            'monto_interes' => 0,
            'estado' => Recibo::STATUS_ACTIVE,
        ]);

        $schedule = $this->build($loan, $line, collect($receipts)->push($probe), $revisions, $date);
        $row = $schedule->rows->first(fn (ScheduleRow $row): bool => $row->kind === ScheduleRow::KIND_PAYOFF);

        return [
            'principal' => $row?->scheduledPrincipal ?? 0.0,
            'interest' => $row?->scheduledInterest ?? 0.0,
        ];
    }

    /**
     * Installment amount and fixed principal for the given balance and term.
     *
     * @return array{0: float, 1: float}
     */
    private function amortization(float $balance, float $annualRate, int $periods, int $months): array
    {
        $periodRate = $annualRate / 100 * $months / 12;

        $installment = $periodRate > 0
            ? $balance * $periodRate / (1 - (1 + $periodRate) ** -$periods)
            : $balance / $periods;

        return [round($installment, 2), round($balance / $periods, 2)];
    }

    private function scheduledPrincipal(
        string $modality,
        float $installment,
        float $fixedPrincipal,
        float $interest,
        float $balance,
        int $remaining,
    ): float {
        if ($remaining <= 1) {
            return $balance;
        }

        $principal = match ($modality) {
            'BULLET' => 0.0,
            'DECRECIENTE' => $fixedPrincipal,
            default => max($installment - $interest, 0.0),
        };

        return round(min($principal, $balance), 2);
    }

    /**
     * Whether a payoff receipt closes the loan inside the period that ends at $due.
     */
    private function payoffFallsInPeriod(Recibo $payoff, Carbon $previousDue, Carbon $due, bool $installmentIsPaid): bool
    {
        $date = $payoff->fecha_pago;

        if ($date->lt($previousDue)) {
            return false;
        }

        return $date->lt($due) || ($date->equalTo($due) && ! $installmentIsPaid);
    }

    /**
     * Interest accrued from $from to $to, splitting the period at every
     * prepayment and revision that falls strictly inside it.
     *
     * @param  Collection<int, Recibo>  $prepayments
     * @param  Collection<int, RevisionPrestamo>  $revisions
     * @return array{0: float, 1: float, 2: float, 3: list<ScheduleRow>} Interest, balance at $to, rate at $to and prepayment rows.
     */
    private function accrue(
        Carbon $from,
        Carbon $to,
        Carbon $periodStart,
        Carbon $periodEnd,
        float $balance,
        float $base,
        float $spread,
        int $months,
        Collection $prepayments,
        Collection $revisions,
    ): array {
        $periodDays = max($this->days360($periodStart, $periodEnd), 1);
        $strictlyInside = fn (Carbon $date): bool => $date->gt($from) && $date->lt($to);

        $points = $prepayments->map(fn (Recibo $receipt): Carbon => $receipt->fecha_pago)
            ->concat($revisions->map(fn (RevisionPrestamo $revision): Carbon => $revision->fecha_efectiva))
            ->filter($strictlyInside)
            ->unique(fn (Carbon $date): string => $date->toDateString())
            ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
            ->values();

        $interest = 0.0;
        $rows = [];
        $cursor = $from;

        $accrueTo = function (Carbon $point) use (&$interest, &$cursor, &$balance, &$base, &$spread, $periodDays, $months): void {
            $fraction = $this->days360($cursor, $point) / $periodDays * ($months / 12);
            $interest += $balance * (($base + $spread) / 100) * $fraction;
            $cursor = $point;
        };

        foreach ($points as $point) {
            $accrueTo($point);

            foreach ($revisions->filter(fn (RevisionPrestamo $revision): bool => $revision->fecha_efectiva->equalTo($point)) as $revision) {
                $base = $revision->tasa_interes ?? $base;
                $spread = $revision->tasa_spread ?? $spread;
            }

            foreach ($prepayments->filter(fn (Recibo $receipt): bool => $receipt->fecha_pago->equalTo($point)) as $prepayment) {
                $amount = min($prepayment->monto_principal, $balance);
                if ($amount <= 0) {
                    continue;
                }

                $balance = round($balance - $amount, 2);
                $rows[] = new ScheduleRow(
                    ScheduleRow::KIND_PREPAYMENT, null, $point->copy(), EstadoCuota::Abono, $base + $spread,
                    $amount, 0.0, $balance, $amount, 0.0, $prepayment,
                );
            }
        }

        $accrueTo($to);

        return [$interest, $balance, $base + $spread, $rows];
    }

    /**
     * Day count between two dates on the 30/360 convention.
     */
    private function days360(Carbon $from, Carbon $to): int
    {
        $fromDay = min($from->day, 30);
        $toDay = $to->day === 31 && $fromDay === 30 ? 30 : $to->day;

        return 360 * ($to->year - $from->year) + 30 * ($to->month - $from->month) + ($toDay - $fromDay);
    }

    /**
     * Order rows by date, with installments before prepayments on the same day.
     *
     * @param  Collection<int, ScheduleRow>  $rows
     * @return Collection<int, ScheduleRow>
     */
    private function sortRows(Collection $rows): Collection
    {
        $kindOrder = [
            ScheduleRow::KIND_INSTALLMENT => 0,
            ScheduleRow::KIND_PREPAYMENT => 1,
            ScheduleRow::KIND_PAYOFF => 2,
        ];

        return $rows
            ->sortBy(fn (ScheduleRow $row): string => $row->date->format('Ymd').$kindOrder[$row->kind].str_pad((string) ($row->number ?? 0), 4, '0', STR_PAD_LEFT))
            ->values();
    }
}
