<?php

namespace App\Reports;

use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Services\Lending\LoanSchedule;
use App\Services\Lending\LoanScheduleService;
use App\Services\Lending\ScheduleRow;
use App\Support\DateFormat;
use App\Support\XlsxWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Posición consolidada de deuda por banco: cuánto se debe, a qué costo, cuándo vence
 * y dónde queda cupo, para decidir qué línea usar, qué deuda pagar primero
 * y qué línea renegociar.
 *
 * Todo proviene de los planes de pago reconstruidos de los sub préstamos activos. Los montos
 * de monedas distintas nunca se suman.
 */
final class DebtPositionReport extends Report
{
    /** Meses hacia adelante de las ventanas de servicio de la deuda. */
    private const SERVICE_MONTHS = [3, 6, 12];

    /** Utilización (%) a partir de la cual se marca una línea. */
    private const UTILIZATION_WARNING = 80.0;

    /** Días hacia adelante en los que se marca una línea o préstamo por vencer. */
    private const EXPIRY_WARNING_DAYS = 365;

    /** Participación (%) de la deuda de una moneda en un banco a partir de la cual se marca la concentración. */
    private const CONCENTRATION_WARNING = 50.0;

    public function __construct(private readonly LoanScheduleService $schedules) {}

    public function slug(): string
    {
        return 'posicion-deuda';
    }

    public function title(): string
    {
        return 'Posición consolidada de deuda';
    }

    public function description(): string
    {
        return 'Deuda, cupo disponible, costo y vencimientos por banco y línea, con una guía para decidir qué línea usar, qué pagar primero y qué renegociar.';
    }

    public function icon(): string
    {
        return 'layers';
    }

    /**
     * Monedas de las líneas de crédito activas.
     *
     * @return array<int, string>
     */
    public function currencies(): array
    {
        return LineaPrestamo::query()->active()->distinct()->orderBy('moneda')->pluck('moneda')->all();
    }

    public function filters(array $query): array
    {
        $currency = strtoupper(trim((string) ($query['moneda'] ?? '')));

        return ['currency' => in_array($currency, $this->currencies(), true) ? $currency : null];
    }

    public function build(array $filters, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $currency = $filters['currency'] ?? null;

        $plansByLine = $this->schedules->activeSchedules($today)
            ->groupBy(fn (array $plan): int => $plan['loan']->id_lineas_prestamos);

        $lines = LineaPrestamo::query()
            ->active()
            ->with('banco')
            ->when($currency, fn ($query) => $query->where('moneda', $currency))
            ->orderBy('nombre')
            ->get();

        $loanRows = collect();
        foreach ($lines as $line) {
            foreach ($plansByLine->get($line->id, collect()) as $plan) {
                $loanRows->push($this->loanRow($plan, $line, $this->bankName($line), $today));
            }
        }

        $lineRows = $lines->map(function (LineaPrestamo $line) use ($loanRows): array {
            return $this->summarize($loanRows->where('line_id', $line->id), (float) $line->monto_maximo) + [
                'line_id' => $line->id,
                'bank' => $this->bankName($line),
                'line' => $line->nombre,
                'currency' => $line->moneda,
                'line_type' => $line->tipo_linea,
                'line_rate' => round((float) $line->tasa_interes + (float) $line->tasa_spread, 4),
                'expires' => $line->fecha_vencmiento,
            ];
        })->values();

        $totals = $lineRows->groupBy('currency')->map(function (Collection $rows, string $moneda) use ($loanRows): array {
            return $this->summarize($loanRows->where('currency', $moneda), (float) $rows->sum('limit')) + [
                'currency' => $moneda,
                'lines_count' => $rows->count(),
                'banks_count' => $rows->pluck('bank')->unique()->count(),
                'concentration' => 100.0,
            ];
        });

        $banks = $lineRows->groupBy(fn (array $row): string => $row['bank'].'|'.$row['currency'])
            ->map(function (Collection $rows) use ($loanRows, $totals): array {
                $first = $rows->first();
                $bankLoans = $loanRows->where('bank', $first['bank'])->where('currency', $first['currency']);
                $summary = $this->summarize($bankLoans, (float) $rows->sum('limit'));
                $currencyBalance = (float) ($totals[$first['currency']]['balance'] ?? 0);

                return $summary + [
                    'bank' => $first['bank'],
                    'currency' => $first['currency'],
                    'lines_count' => $rows->count(),
                    'concentration' => $currencyBalance > 0 ? round($summary['balance'] / $currencyBalance * 100, 2) : 0.0,
                ];
            })
            ->sortBy([['currency', 'asc'], ['balance', 'desc']])
            ->values();

        return [
            'as_of' => $today,
            'filters' => $filters,
            'currencies' => $this->currencies(),
            'banks' => $banks,
            'lines' => $lineRows,
            'loans' => $loanRows->sortBy([['bank', 'asc'], ['line', 'asc'], ['number', 'asc']])->values(),
            'totals' => $totals->values(),
            'service_months' => self::SERVICE_MONTHS,
            'guidance' => $this->guidance($banks, $lineRows, $loanRows, $totals, $today),
        ];
    }

    public function workbook(array $data): XlsxWriter
    {
        $money = fn (string $key, string $label, int $width = 18): array => ['key' => $key, 'label' => $label, 'type' => 'money', 'width' => $width];
        $percent = fn (string $key, string $label, int $width = 12): array => ['key' => $key, 'label' => $label, 'type' => 'percent', 'width' => $width];
        $text = fn (string $key, string $label, int $width = 24): array => ['key' => $key, 'label' => $label, 'type' => 'text', 'width' => $width];
        $integer = fn (string $key, string $label, int $width = 10): array => ['key' => $key, 'label' => $label, 'type' => 'integer', 'width' => $width];
        $date = fn (string $key, string $label, int $width = 14): array => ['key' => $key, 'label' => $label, 'type' => 'date', 'width' => $width];

        $titles = [
            $this->title(),
            'Fecha de corte: '.DateFormat::format($data['as_of']),
            'Moneda: '.($data['filters']['currency'] ?? 'Todas').' · Los montos de monedas distintas no se suman',
        ];
        $notes = [
            'Saldo = principal pendiente según el plan de pagos reconstruido. Disponible = límite de la línea − saldo.',
            'Servicio = principal + interés de las cuotas abiertas (incluye las vencidas) con fecha hasta 3, 6 o 12 meses desde el corte.',
            'Tasa ponderada = tasa anual (tasa + spread) de cada sub préstamo ponderada por su saldo. Interés anual estimado = saldo × tasa.',
        ];

        $bankRows = $data['banks']->map(fn (array $row): array => $row)->all();
        foreach ($data['totals'] as $total) {
            $bankRows[] = ['bank' => 'TOTAL '.$total['currency'], '_total' => true] + $total;
        }

        $serviceColumns = collect($data['service_months'])->map(fn (int $months): array => $money("service_{$months}", "Servicio {$months} meses"))->all();

        $guidanceRows = [];
        foreach ($data['guidance']['pay_first'] as $item) {
            $guidanceRows[] = ['kind' => 'Pagar primero (mayor costo)', 'item' => "{$item['bank']} · {$item['line']} · sub préstamo {$item['number']}", 'currency' => $item['currency'], 'rate' => $item['rate'], 'amount' => $item['balance'], 'note' => 'Interés anual estimado: '.number_format($item['annual_interest'], 2)];
        }
        foreach ($data['guidance']['use_first'] as $item) {
            $guidanceRows[] = ['kind' => 'Usar primero (cupo más barato)', 'item' => "{$item['bank']} · {$item['line']}", 'currency' => $item['currency'], 'rate' => $item['line_rate'], 'amount' => $item['available'], 'note' => 'Disponible para nuevos desembolsos'];
        }
        foreach ($data['guidance']['attention'] as $item) {
            $guidanceRows[] = ['kind' => 'Atención ('.$item['severity_label'].')', 'item' => $item['scope'], 'currency' => $item['currency'] ?? '', 'rate' => null, 'amount' => null, 'note' => $item['text']];
        }

        return (new XlsxWriter)
            ->addSheet('Resumen por banco', array_merge(
                [$text('bank', 'Banco', 28), $text('currency', 'Moneda', 11), $integer('lines_count', 'Líneas'), $money('limit', 'Límite aprobado'), $money('balance', 'Saldo'), $money('available', 'Disponible'),
                    $percent('utilization', '% utilización'), $percent('wavg_rate', 'Tasa ponderada'), $percent('concentration', '% de la deuda'), $money('annual_interest', 'Interés anual estimado')],
                $serviceColumns,
                [$integer('overdue_count', 'Cuotas vencidas'), $money('overdue_amount', 'Monto vencido'), $date('next_due', 'Próxima cuota'), $money('next_amount', 'Monto próxima cuota'), ['key' => 'wam_years', 'label' => 'Plazo promedio (años)', 'type' => 'decimal', 'width' => 12]],
            ), $bankRows, $titles, $notes)
            ->addSheet('Detalle por línea', array_merge(
                [$text('bank', 'Banco', 24), $text('line', 'Línea', 26), $text('currency', 'Moneda', 11), $money('limit', 'Límite aprobado'), $money('balance', 'Saldo'), $money('available', 'Disponible'), $percent('utilization', '% utilización'),
                    $percent('line_rate', 'Tasa de la línea'), $percent('wavg_rate', 'Tasa ponderada deuda'), $integer('loans_count', 'Sub préstamos'), $money('annual_interest', 'Interés anual estimado')],
                $serviceColumns,
                [$integer('overdue_count', 'Cuotas vencidas'), $money('overdue_amount', 'Monto vencido'), $date('next_due', 'Próxima cuota'), $date('expires', 'Vence la línea')],
            ), $data['lines']->all(), $titles, $notes)
            ->addSheet('Sub préstamos', array_merge(
                [$text('bank', 'Banco', 24), $text('line', 'Línea', 26), $text('number', 'Sub préstamo', 14), $text('currency', 'Moneda', 11), $money('original', 'Monto original'), $money('balance', 'Saldo'), $percent('rate', 'Tasa anual'), $money('annual_interest', 'Interés anual estimado'), $money('installment', 'Cuota actual')],
                $serviceColumns,
                [$date('next_due', 'Próxima cuota'), $integer('overdue_count', 'Cuotas vencidas'), $money('overdue_amount', 'Monto vencido'), $date('maturity', 'Vencimiento final'), ['key' => 'years', 'label' => 'Años al vencimiento', 'type' => 'decimal', 'width' => 12], $text('conditions', 'Condiciones de cálculo', 60)],
            ), $data['loans']->all(), $titles, $notes)
            ->addSheet('Guía de decisión', [
                $text('kind', 'Recomendación', 30), $text('item', 'Detalle', 52), $text('currency', 'Moneda', 11), $percent('rate', 'Tasa'), $money('amount', 'Monto'), $text('note', 'Nota', 70),
            ], $guidanceRows, $titles, [
                'Guía automática basada en reglas: pagar primero lo más caro, usar primero el cupo más barato y revisar utilización ≥ 80 %, vencimientos en 12 meses, concentración ≥ 50 % por banco y cuotas vencidas.',
            ]);
    }

    public function fileName(array $data): string
    {
        $currency = $data['filters']['currency'] ?? null;

        return 'posicion-deuda-'.($currency ? strtolower($currency).'-' : '').$data['as_of']->format('Ymd').'.xlsx';
    }

    private function bankName(LineaPrestamo $line): string
    {
        return $line->banco?->nombre_banco ?? $line->nombre;
    }

    /**
     * @param  array{loan: Prestamo, schedule: LoanSchedule}  $plan
     * @return array<string, mixed>
     */
    private function loanRow(array $plan, LineaPrestamo $line, string $bank, Carbon $today): array
    {
        $loan = $plan['loan'];
        $schedule = $plan['schedule'];
        $open = $schedule->openInstallments();
        $next = $open->first();
        $overdue = $schedule->overdueInstallments();
        $balance = $schedule->outstandingPrincipal();
        $rate = $schedule->currentAnnualRate;
        $maturity = $schedule->maturityDate();

        $row = [
            'line_id' => $line->id,
            'bank' => $bank,
            'line' => $line->nombre,
            'currency' => $line->moneda,
            'number' => $loan->numero_prestamo,
            'original' => (float) $loan->monto_prestamo,
            'balance' => $balance,
            'rate' => $rate,
            'annual_interest' => round($balance * $rate / 100, 2),
            'installment' => $next?->total(),
            'next_due' => $next?->date,
            'next_amount' => $next?->total(),
            'overdue_count' => $overdue->count(),
            'overdue_amount' => round($overdue->sum(fn (ScheduleRow $row): float => $row->total()), 2),
            'maturity' => $maturity,
            'years' => $maturity ? round(max((float) $today->diffInDays($maturity, false), 0) / 365, 2) : 0.0,
            'conditions' => $schedule->conditions?->describe(),
        ];

        foreach (self::SERVICE_MONTHS as $months) {
            $cutoff = $today->copy()->addMonthsNoOverflow($months);
            $row["service_{$months}"] = round($open->filter(fn (ScheduleRow $installment): bool => $installment->date->lte($cutoff))->sum(fn (ScheduleRow $installment): float => $installment->total()), 2);
        }

        return $row;
    }

    /**
     * Totales y promedios ponderados de un grupo de sub préstamos frente a un límite de crédito.
     *
     * @param  Collection<int, array<string, mixed>>  $loans
     * @return array<string, mixed>
     */
    private function summarize(Collection $loans, float $limit): array
    {
        $balance = round((float) $loans->sum('balance'), 2);
        $next = $loans->filter(fn (array $loan): bool => $loan['next_due'] !== null)
            ->sortBy(fn (array $loan): int => $loan['next_due']->getTimestamp())
            ->first();

        $summary = [
            'loans_count' => $loans->count(),
            'limit' => round($limit, 2),
            'balance' => $balance,
            'available' => round($limit - $balance, 2),
            'utilization' => $limit > 0 ? round($balance / $limit * 100, 2) : 0.0,
            'wavg_rate' => $balance > 0 ? round($loans->sum(fn (array $loan): float => $loan['balance'] * $loan['rate']) / $balance, 4) : null,
            'annual_interest' => round((float) $loans->sum('annual_interest'), 2),
            'overdue_count' => (int) $loans->sum('overdue_count'),
            'overdue_amount' => round((float) $loans->sum('overdue_amount'), 2),
            'next_due' => $next['next_due'] ?? null,
            'next_amount' => $next['next_amount'] ?? null,
            'wam_years' => $balance > 0 ? round($loans->sum(fn (array $loan): float => $loan['balance'] * $loan['years']) / $balance, 2) : null,
        ];

        foreach (self::SERVICE_MONTHS as $months) {
            $summary["service_{$months}"] = round((float) $loans->sum("service_{$months}"), 2);
        }

        return $summary;
    }

    /**
     * Sugerencias basadas en reglas: qué pagar primero, qué crédito usar primero y qué requiere atención.
     *
     * @param  Collection<int, array<string, mixed>>  $banks
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  Collection<int, array<string, mixed>>  $loans
     * @param  Collection<string, array<string, mixed>>  $totals
     * @return array{pay_first: array<int, array<string, mixed>>, use_first: array<int, array<string, mixed>>, attention: array<int, array<string, mixed>>}
     */
    private function guidance(Collection $banks, Collection $lines, Collection $loans, Collection $totals, Carbon $today): array
    {
        $payFirst = $loans->where('balance', '>', 0)
            ->sortBy([['rate', 'desc'], ['balance', 'desc']])
            ->take(5)->values()->all();

        $useFirst = $lines->where('available', '>', 0)
            ->sortBy([['line_rate', 'asc'], ['available', 'desc']])
            ->take(5)->values()->all();

        $attention = [];
        $money = fn (float $value, string $currency): string => number_format($value, 2).' '.$currency;
        $lineLimit = $today->copy()->addDays(self::EXPIRY_WARNING_DAYS);

        foreach ($loans->where('overdue_count', '>', 0) as $loan) {
            $attention[] = ['severity' => 'danger', 'scope' => "Sub préstamo {$loan['number']} · {$loan['bank']}", 'currency' => $loan['currency'], 'text' => "{$loan['overdue_count']} cuota(s) vencida(s) por ".$money($loan['overdue_amount'], $loan['currency']).'. Regularícelas para evitar mora y renegociaciones forzadas.'];
        }

        foreach ($lines as $line) {
            $scope = "Línea {$line['line']} · {$line['bank']}";

            if ($line['expires'] !== null && $line['expires']->lt($today)) {
                $attention[] = ['severity' => 'danger', 'scope' => $scope, 'currency' => $line['currency'], 'text' => 'La línea venció el '.DateFormat::format($line['expires']).'. Renuévela o cancélela.'];
            } elseif ($line['expires'] !== null && $line['expires']->lte($lineLimit)) {
                $attention[] = ['severity' => 'warning', 'scope' => $scope, 'currency' => $line['currency'], 'text' => 'La línea vence el '.DateFormat::format($line['expires']).' (en menos de 12 meses): candidata a renegociar o renovar.'];
            }

            if ($line['utilization'] >= self::UTILIZATION_WARNING) {
                $attention[] = ['severity' => 'warning', 'scope' => $scope, 'currency' => $line['currency'], 'text' => 'Utilización de '.number_format($line['utilization'], 1).' %: queda poco cupo ('.$money($line['available'], $line['currency']).').'];
            }
        }

        foreach ($loans as $loan) {
            if ($loan['balance'] > 0 && $loan['maturity'] !== null && $loan['maturity']->lte($lineLimit)) {
                $attention[] = ['severity' => 'info', 'scope' => "Sub préstamo {$loan['number']} · {$loan['bank']}", 'currency' => $loan['currency'], 'text' => 'Vence el '.DateFormat::format($loan['maturity']).' con saldo de '.$money($loan['balance'], $loan['currency']).': prevea su refinanciamiento.'];
            }
        }

        foreach ($banks as $bank) {
            $banksInCurrency = $totals[$bank['currency']]['banks_count'] ?? 0;

            if ($banksInCurrency >= 2 && $bank['concentration'] >= self::CONCENTRATION_WARNING) {
                $attention[] = ['severity' => 'warning', 'scope' => "Banco {$bank['bank']}", 'currency' => $bank['currency'], 'text' => 'Concentra el '.number_format($bank['concentration'], 1).' % de la deuda en '.$bank['currency'].': riesgo de concentración.'];
            }
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        $labels = ['danger' => 'Urgente', 'warning' => 'Importante', 'info' => 'Informativo'];

        $attention = collect($attention)
            ->sortBy(fn (array $item): int => $order[$item['severity']])
            ->map(fn (array $item): array => $item + ['severity_label' => $labels[$item['severity']]])
            ->values()->all();

        return ['pay_first' => $payFirst, 'use_first' => $useFirst, 'attention' => $attention];
    }
}
