<?php

namespace App\Services;

use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Services\Lending\LoanSchedule;
use App\Services\Lending\LoanScheduleService;
use App\Services\Lending\ScheduleRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures for the dashboard, derived from every active loan's rebuilt plan.
 */
class DashboardMetrics
{
    /** @var array<int, int> */
    protected const AVAILABILITY_HORIZONS = [3, 6, 9, 12, 18, 24];

    /** @var array<string, Collection<int, array{loan: Prestamo, schedule: LoanSchedule}>> */
    protected array $scheduleCache = [];

    public function __construct(protected LoanScheduleService $schedules) {}

    /**
     * Headline figures for the dashboard cards.
     *
     * @return array{
     *     outstanding_balance: float,
     *     active_loans: int,
     *     active_credit_lines: int,
     *     credit_lines_limit: float,
     *     overdue_installments: int,
     *     overdue_amount: float,
     * }
     */
    public function summary(?Carbon $today = null): array
    {
        $plans = $this->plans($today);
        $overdue = $plans->flatMap(fn (array $plan): Collection => $plan['schedule']->overdueInstallments());
        $creditLines = LineaPrestamo::query()->active()->get(['id', 'monto_maximo']);

        return [
            'outstanding_balance' => round($plans->sum(fn (array $plan): float => $plan['schedule']->outstandingPrincipal()), 2),
            'active_loans' => $plans->count(),
            'active_credit_lines' => $creditLines->count(),
            'credit_lines_limit' => (float) $creditLines->sum('monto_maximo'),
            'overdue_installments' => $overdue->count(),
            'overdue_amount' => round($overdue->sum(fn (ScheduleRow $row): float => $row->total()), 2),
        ];
    }

    /**
     * Available credit per bank now and projected after each horizon.
     *
     * Available = line limits - loan balances. Each projection adds back the
     * principal of every open installment due within the horizon.
     *
     * @param  array<int, int>  $horizons  Months ahead to project.
     * @return array{
     *     horizons: array<int, int>,
     *     rows: Collection<int, object{name: string, currency: string, limit: float, balance: float, available: float, projected: array<int, float>}>,
     *     totals: Collection<int, object{currency: string, limit: float, balance: float, available: float, projected: array<int, float>}>,
     *     plan_ends_on: Carbon|null,
     * }
     */
    public function creditAvailability(?Carbon $today = null, array $horizons = self::AVAILABILITY_HORIZONS): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $plans = $this->plans($today);
        $plansByLine = $plans->groupBy(fn (array $plan): int => $plan['loan']->id_lineas_prestamos);

        $rows = LineaPrestamo::query()
            ->active()
            ->with('banco')
            ->get()
            ->groupBy(fn (LineaPrestamo $line): string => ($line->banco?->nombre_banco ?? $line->nombre).'|'.$line->moneda)
            ->map(function (Collection $lines) use ($plansByLine, $horizons, $today): object {
                $first = $lines->first();
                $linePlans = $lines->flatMap(fn (LineaPrestamo $line): Collection => $plansByLine->get($line->id, collect()));

                $limit = (float) $lines->sum('monto_maximo');
                $balance = round($linePlans->sum(fn (array $plan): float => $plan['schedule']->outstandingPrincipal()), 2);

                $projected = [];
                foreach ($horizons as $months) {
                    $cutoff = $today->copy()->addMonthsNoOverflow($months);
                    $amortized = $linePlans->sum(fn (array $plan): float => $plan['schedule']->openInstallments()
                        ->filter(fn (ScheduleRow $row): bool => $row->date->lte($cutoff))
                        ->sum('principal'));

                    $projected[$months] = round($limit - max($balance - $amortized, 0), 2);
                }

                return (object) [
                    'name' => $first->banco?->nombre_banco ?? $first->nombre,
                    'currency' => $first->moneda,
                    'limit' => $limit,
                    'balance' => $balance,
                    'available' => round($limit - $balance, 2),
                    'projected' => $projected,
                ];
            })
            ->sortBy('name')
            ->values();

        $totals = $rows->groupBy('currency')->map(fn (Collection $group, string $currency): object => (object) [
            'currency' => $currency,
            'limit' => $group->sum('limit'),
            'balance' => $group->sum('balance'),
            'available' => $group->sum('available'),
            'projected' => collect($horizons)
                ->mapWithKeys(fn (int $months): array => [$months => round($group->sum(fn (object $row): float => $row->projected[$months]), 2)])
                ->all(),
        ])->values();

        return [
            'horizons' => $horizons,
            'rows' => $rows,
            'totals' => $totals,
            'plan_ends_on' => $plans
                ->map(fn (array $plan): ?Carbon => $plan['schedule']->maturityDate())
                ->filter()
                ->max(),
        ];
    }

    /**
     * Open installments of every active loan ordered by due date, overdue ones first.
     *
     * @return Collection<int, object{loan_number: string, credit_line: string, installment_number: int, due_date: string, amount: float, is_overdue: bool}>
     */
    public function pendingInstallments(int $limit = 8, ?Carbon $today = null): Collection
    {
        return $this->openInstallments($today)->take($limit)->values();
    }

    /**
     * The first installment that has not yet reached its due date.
     *
     * @return object{loan_number: string, credit_line: string, installment_number: int, due_date: string, amount: float, is_overdue: bool}|null
     */
    public function nextInstallment(?Carbon $today = null): ?object
    {
        return $this->openInstallments($today)->first(fn (object $installment): bool => ! $installment->is_overdue);
    }

    /**
     * @return Collection<int, object{loan_number: string, credit_line: string, installment_number: int, due_date: string, amount: float, is_overdue: bool}>
     */
    protected function openInstallments(?Carbon $today): Collection
    {
        return $this->plans($today)
            ->flatMap(fn (array $plan): Collection => $plan['schedule']->openInstallments()->map(fn (ScheduleRow $row): object => (object) [
                'loan_number' => $plan['loan']->numero_prestamo,
                'credit_line' => $plan['loan']->linea->nombre,
                'installment_number' => $row->number,
                'due_date' => $row->date->toDateString(),
                'amount' => $row->total(),
                'is_overdue' => $row->status->value === 'VENCIDA',
            ]))
            ->sortBy(fn (object $installment): string => $installment->due_date.str_pad((string) $installment->installment_number, 4, '0', STR_PAD_LEFT).$installment->loan_number)
            ->values();
    }

    /**
     * @return Collection<int, array{loan: Prestamo, schedule: LoanSchedule}>
     */
    protected function plans(?Carbon $today): Collection
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return $this->scheduleCache[$today->toDateString()] ??= $this->schedules->activeSchedules($today);
    }
}
