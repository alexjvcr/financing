<?php

namespace App\Services\Lending;

use App\Enums\ConvencionTasa;
use App\Enums\DayCount;
use App\Enums\EstadoCuota;
use App\Enums\ModalidadCuota;
use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\RevisionPrestamo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconstruye desde cero el plan de pagos de un préstamo cada vez que se solicita.
 *
 * Las entradas son los términos del préstamo, las condiciones de su línea (y las
 * sobrescrituras propias del préstamo), los recibos activos y las revisiones activas
 * de tasa/plazo; no se lee ni se escribe nada en la base de datos.
 *
 * Condiciones (ver LoanConditions):
 *  - Tipo de cuota: nivelada (francés), lineal (alemán), solo intereses (bullet)
 *    o graduada, cada una con período de gracia opcional y un pago global final.
 *  - Base de días: Actual/360, Actual/365 o 30/360.
 *  - Convención de tasa: nominal (simple) o efectiva anual.
 *  - Frecuencia de pago: mensual, trimestral, semestral o anual.
 *
 * Reglas:
 *  - El interés se devenga sobre el saldo pendiente. Los eventos dentro de un período
 *    (abonos, revisiones) lo dividen en segmentos.
 *  - Un recibo numerado (cuota completa o solo interés) cierra esa
 *    cuota con los montos realmente pagados.
 *  - Un abono a principal reduce el saldo desde su fecha. La cuota
 *    se mantiene igual, así que el préstamo termina antes.
 *  - Un recibo de solo interés difiere el principal: la cuota se
 *    recalcula sobre los períodos restantes.
 *  - Una revisión de tasa/plazo recalcula la cuota desde el siguiente período.
 *  - Un recibo de cancelación cierra el préstamo en su fecha.
 *  - Las cuotas impagas se proyectan como si se pagaran a tiempo.
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
        $conditions = LoanConditions::resolve($loan, $line);
        $months = $conditions->monthsPerPeriod;
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
        $balloon = round($conditions->balloonPercent / 100 * $balance, 2);
        $base = $loan->tasa_interes;
        $spread = $loan->tasa_spread;
        $rate = $base + $spread;
        $remaining = max((int) ceil($loan->plazo_meses / $months), 1);
        $graceLeft = min($conditions->gracePeriods(), $remaining - 1);

        $installment = null;
        $fixedPrincipal = 0.0;
        $growthIndex = 0;
        $contractualPending = $loan->cuota_calculada > 0 && $conditions->modality->usesContractualInstallment();
        $needsRecalculation = false;

        /** @var Collection<int, ScheduleRow> $rows */
        $rows = collect();
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
                    $graceLeft = min($graceLeft, $remaining - 1);
                }

                $needsRecalculation = true;
            }

            // La cuota se calcula cuando inicia la amortización y después de cada evento que la modifica.
            if ($graceLeft === 0 && ($installment === null || $needsRecalculation)) {
                [$installment, $fixedPrincipal] = $this->amortization($conditions, $balance, $rate, $remaining, min($balloon, $balance));

                if ($contractualPending) {
                    $installment = round($loan->cuota_calculada, 2);
                }

                $contractualPending = false;
                $needsRecalculation = false;
                $growthIndex = 0;
            }

            $paidReceipt = $installmentReceipts->get($number);

            if ($payoff !== null && $this->payoffFallsInPeriod($payoff, $previousDue, $due, $paidReceipt !== null)) {
                [$accrued, $balanceAtPayoff, $payoffRate, $prepaymentRows] = $this->accrue(
                    $conditions, $previousDue, $payoff->fecha_pago, $previousDue, $due, $balance, $base, $spread, $prepayments, $revisions,
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
                $conditions, $previousDue, $due, $previousDue, $due, $balance, $base, $spread, $prepayments, $revisions,
            );
            $interest = round($interest, 2);
            $rows = $rows->concat($prepaymentRows);

            $scheduledPrincipal = $this->scheduledPrincipal(
                $conditions, $installment ?? 0.0, $fixedPrincipal, $growthIndex, $interest, $balanceAtDue, $remaining, $graceLeft,
            );

            if ($paidReceipt !== null) {
                $paidPrincipal = $paidReceipt->tipo === TipoRecibo::CuotaCompleta ? $paidReceipt->monto_principal : 0.0;
                $status = $paidReceipt->tipo === TipoRecibo::CuotaCompleta ? EstadoCuota::Pagada : EstadoCuota::ParcialInteres;
                $principal = $paidPrincipal;
                $paidInterest = $paidReceipt->monto_interes;
                $needsRecalculation = $needsRecalculation || $paidReceipt->tipo === TipoRecibo::ParcialInteres;
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

            if ($graceLeft > 0) {
                $graceLeft--;
            } else {
                $growthIndex++;
            }
        }

        return new LoanSchedule(
            $this->sortRows($rows),
            round($loan->monto_prestamo, 2),
            $isClosed,
            $this->rateInForce($loan, $revisions, $today),
            $conditions,
        );
    }

    /**
     * Tasa anual en porcentaje que aplica en la fecha dada.
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
     * Cotiza lo necesario para cancelar el préstamo en una fecha dada.
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
     * Tasa de un período usada para dimensionar la cuota, para un período promedio.
     *
     * Nominal: tasa anual × fracción del año (Actual/360 usa 365/360 de un año por período,
     * que es como el banco dimensiona la cuota nivelada). Efectiva: (1 + tasa) ^ fracción − 1.
     */
    private function periodRate(LoanConditions $conditions, float $annualRate): float
    {
        $yearFraction = $conditions->monthsPerPeriod / 12;

        if ($conditions->rateConvention === ConvencionTasa::Efectiva) {
            return (1 + $annualRate / 100) ** $yearFraction - 1;
        }

        $actual360 = $conditions->dayCount->usesActualDays() && $conditions->dayCount->basis() === 360;

        return $annualRate / 100 * $yearFraction * ($actual360 ? 365 / 360 : 1);
    }

    /**
     * Cuota (o primera cuota cuando es graduada) y principal fijo para el saldo y plazo dados.
     *
     * @param  float  $balloon  Principal que queda por pagar en el último período.
     * @return array{0: float, 1: float}
     */
    private function amortization(LoanConditions $conditions, float $balance, float $annualRate, int $periods, float $balloon): array
    {
        $periodRate = $this->periodRate($conditions, $annualRate);
        $periods = max($periods, 1);
        $presentBalloon = $balloon / (1 + $periodRate) ** $periods;
        $financed = $balance - $presentBalloon;

        $installment = match ($conditions->modality) {
            ModalidadCuota::Bullet, ModalidadCuota::Decreciente => 0.0,
            ModalidadCuota::Constante => $periodRate > 0
                ? $financed * $periodRate / (1 - (1 + $periodRate) ** -$periods)
                : ($balance - $balloon) / $periods,
            ModalidadCuota::Creciente => $this->graduatedFirstInstallment($financed, $periodRate, $conditions->growthPercent / 100, $periods),
        };

        $fixedPrincipal = match ($conditions->modality) {
            ModalidadCuota::Decreciente => ($balance - $balloon) / $periods,
            default => 0.0,
        };

        return [round($installment, 2), round($fixedPrincipal, 2)];
    }

    /**
     * Primera cuota de una serie que crece $growth cada período y amortiza $financed.
     */
    private function graduatedFirstInstallment(float $financed, float $periodRate, float $growth, int $periods): float
    {
        if (abs($periodRate - $growth) < 1e-12) {
            return $financed * (1 + $periodRate) / $periods;
        }

        return $financed * ($periodRate - $growth) / (1 - ((1 + $growth) / (1 + $periodRate)) ** $periods);
    }

    private function scheduledPrincipal(
        LoanConditions $conditions,
        float $installment,
        float $fixedPrincipal,
        int $growthIndex,
        float $interest,
        float $balance,
        int $remaining,
        int $graceLeft,
    ): float {
        if ($graceLeft > 0) {
            return 0.0;
        }

        if ($remaining <= 1) {
            return $balance;
        }

        $principal = match ($conditions->modality) {
            ModalidadCuota::Bullet => 0.0,
            ModalidadCuota::Decreciente => $fixedPrincipal,
            ModalidadCuota::Constante => max($installment - $interest, 0.0),
            ModalidadCuota::Creciente => max($installment * (1 + $conditions->growthPercent / 100) ** $growthIndex - $interest, 0.0),
        };

        return round(min($principal, $balance), 2);
    }

    /**
     * Indica si un recibo de cancelación cierra el préstamo dentro del período que termina en $due.
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
     * Interés devengado de $from a $to, dividiendo el período en cada
     * abono y revisión que cae estrictamente dentro de él.
     *
     * @param  Collection<int, Recibo>  $prepayments
     * @param  Collection<int, RevisionPrestamo>  $revisions
     * @return array{0: float, 1: float, 2: float, 3: list<ScheduleRow>} Interés, saldo en $to, tasa en $to y filas de abonos.
     */
    private function accrue(
        LoanConditions $conditions,
        Carbon $from,
        Carbon $to,
        Carbon $periodStart,
        Carbon $periodEnd,
        float $balance,
        float $base,
        float $spread,
        Collection $prepayments,
        Collection $revisions,
    ): array {
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

        $accrueTo = function (Carbon $point) use (&$interest, &$cursor, &$balance, &$base, &$spread, $conditions, $periodStart, $periodEnd): void {
            $interest += $balance * $this->interestFactor($conditions, $base + $spread, $cursor, $point, $periodStart, $periodEnd);
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
     * Interés por unidad de saldo entre dos fechas, según la base de días y la convención de tasa.
     */
    private function interestFactor(
        LoanConditions $conditions,
        float $annualRate,
        Carbon $from,
        Carbon $to,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): float {
        if ($conditions->dayCount->usesActualDays()) {
            $days = DayCount::actualDays($from, $to);
            $yearFraction = $days / $conditions->dayCount->basis();
            $effectiveFraction = $days / 365;
        } else {
            // 30/360: un período completo equivale exactamente a meses/12 de un año; los eventos dentro de él lo reparten proporcionalmente.
            $periodDays = max($this->days360($periodStart, $periodEnd), 1);
            $yearFraction = $effectiveFraction = $this->days360($from, $to) / $periodDays * ($conditions->monthsPerPeriod / 12);
        }

        if ($conditions->rateConvention === ConvencionTasa::Efectiva) {
            return (1 + $annualRate / 100) ** $effectiveFraction - 1;
        }

        return $annualRate / 100 * $yearFraction;
    }

    /**
     * Conteo de días entre dos fechas con la convención 30/360.
     */
    private function days360(Carbon $from, Carbon $to): int
    {
        $fromDay = min($from->day, 30);
        $toDay = $to->day === 31 && $fromDay === 30 ? 30 : $to->day;

        return 360 * ($to->year - $from->year) + 30 * ($to->month - $from->month) + ($toDay - $fromDay);
    }

    /**
     * Ordena las filas por fecha, con las cuotas antes que los abonos el mismo día.
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
