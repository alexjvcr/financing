<?php

namespace Tests\Unit\Lending;

use App\Enums\DayCount;
use App\Enums\EstadoCuota;
use App\Enums\ModalidadCuota;
use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\RevisionPrestamo;
use App\Services\Lending\LoanConditions;
use App\Services\Lending\LoanSchedule;
use App\Services\Lending\LoanScheduleBuilder;
use App\Services\Lending\ScheduleRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CalculationConditionsTest extends TestCase
{
    protected LoanScheduleBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new LoanScheduleBuilder;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function loan(array $overrides = []): Prestamo
    {
        return new Prestamo($overrides + [
            'monto_prestamo' => 6000000,
            'formalizacion' => '2026-03-01',
            'plazo_meses' => 96,
            'tasa_interes' => 2,
            'tasa_spread' => 2,
            'cuota_calculada' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function line(array $overrides = []): LineaPrestamo
    {
        return new LineaPrestamo($overrides + [
            'modalidad_pago' => 'MENSUAL',
            'modalidad_cuota' => 'CONSTANTE',
            'calendario' => '360/360',
            'tipo_cuota' => 'NOMINAL',
        ]);
    }

    /**
     * @param  array<int, RevisionPrestamo>  $revisions
     */
    protected function build(Prestamo $loan, LineaPrestamo $line, array $revisions = []): LoanSchedule
    {
        return $this->builder->build($loan, $line, [], $revisions, Carbon::parse('2026-11-18'));
    }

    /**
     * @return Collection<int, ScheduleRow>
     */
    protected function installments(LoanSchedule $schedule)
    {
        return $schedule->rows->filter->isInstallment()->values();
    }

    protected function annuity(float $balance, float $periodRate, int $periods): float
    {
        return $balance * $periodRate / (1 - (1 + $periodRate) ** -$periods);
    }

    public function test_actual_360_charges_the_real_days_over_360_and_sizes_the_installment_like_the_bank(): void
    {
        $schedule = $this->build($this->loan(), $this->line(['calendario' => '365/360']));
        $first = $this->installments($schedule)->first();

        // Del 01/03 al 01/04 son 31 días: 6,000,000 × 4 % × 31 / 360.
        $this->assertEqualsWithDelta(20666.67, $first->interest, 0.005);
        // La cuota nivelada usa 4 % × 365/360: los mismos 73,290.81 que cotiza el banco para este préstamo.
        $this->assertEqualsWithDelta(73290.81, $first->total(), 0.01);
        $this->assertEqualsWithDelta(52624.14, $first->principal, 0.01);
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.01);
        $this->assertGreaterThanOrEqual(95, $this->installments($schedule)->count());
        $this->assertLessThanOrEqual(97, $this->installments($schedule)->count());
    }

    public function test_actual_365_charges_the_real_days_over_365(): void
    {
        $schedule = $this->build($this->loan(), $this->line(['calendario' => '365/365']));
        $first = $this->installments($schedule)->first();

        $this->assertEqualsWithDelta(round(6000000 * 0.04 * 31 / 365, 2), $first->interest, 0.005);
        $this->assertEqualsWithDelta(73135.65, $first->total(), 0.01);
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.01);
    }

    public function test_thirty_360_keeps_every_month_equal(): void
    {
        $schedule = $this->build($this->loan(), $this->line(['calendario' => '360/360']));

        $this->assertEqualsWithDelta(20000.00, $this->installments($schedule)->first()->interest, 0.001);
        $this->assertEqualsWithDelta(73135.65, $this->installments($schedule)->first()->total(), 0.01);
        $this->assertSame(96, $this->installments($schedule)->count());
    }

    public function test_actual_day_counts_make_the_interest_follow_the_length_of_each_month(): void
    {
        $installments = $this->installments($this->build($this->loan(), $this->line(['calendario' => '365/360'])));

        // Febrero (28 días) devenga menos interés que marzo (31 días) con un saldo casi igual.
        $february = $installments[10];
        $march = $installments[11];
        $this->assertSame('2027-02-01', $february->date->toDateString());
        $this->assertLessThan($installments[9]->interest, $february->interest);
        $this->assertSame('2027-03-01', $march->date->toDateString());
    }

    public function test_an_unknown_or_empty_calendar_falls_back_to_30_360(): void
    {
        $unknown = $this->build($this->loan(), $this->line(['calendario' => 'XYZ']));
        $empty = $this->build($this->loan(), new LineaPrestamo(['modalidad_pago' => 'MENSUAL', 'modalidad_cuota' => 'CONSTANTE']));

        $this->assertEqualsWithDelta(20000.00, $this->installments($unknown)->first()->interest, 0.001);
        $this->assertEqualsWithDelta(20000.00, $this->installments($empty)->first()->interest, 0.001);
    }

    public function test_effective_annual_rate_compounds_the_period_instead_of_simple_interest(): void
    {
        $schedule = $this->build($this->loan(['tasa_interes' => 4, 'tasa_spread' => 0]), $this->line(['tipo_cuota' => 'EFECTIVA']));
        $first = $this->installments($schedule)->first();

        $periodRate = 1.04 ** (1 / 12) - 1;
        $this->assertEqualsWithDelta(round(6000000 * $periodRate, 2), $first->interest, 0.01);
        $this->assertEqualsWithDelta(round($this->annuity(6000000, $periodRate, 96), 2), $first->total(), 0.01);
        $this->assertLessThan(20000.0, $first->interest);
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.01);
    }

    public function test_linear_amortization_keeps_the_principal_constant_with_actual_days(): void
    {
        $schedule = $this->build(
            $this->loan(['monto_prestamo' => 1200000, 'plazo_meses' => 12]),
            $this->line(['modalidad_cuota' => 'DECRECIENTE', 'calendario' => '365/360']),
        );
        $installments = $this->installments($schedule);

        $this->assertCount(12, $installments);
        $this->assertEqualsWithDelta(100000.0, $installments[0]->principal, 0.01);
        $this->assertEqualsWithDelta(100000.0, $installments[7]->principal, 0.01);
        $this->assertEqualsWithDelta(round(1200000 * 0.04 * 31 / 360, 2), $installments[0]->interest, 0.01);
        $this->assertEqualsWithDelta(0.0, $installments->last()->balance, 0.01);
    }

    public function test_interest_only_bullet_pays_the_principal_at_maturity(): void
    {
        $schedule = $this->build(
            $this->loan(['monto_prestamo' => 1000000, 'plazo_meses' => 6]),
            $this->line(['modalidad_cuota' => 'BULLET', 'calendario' => '365/360']),
        );
        $installments = $this->installments($schedule);

        $this->assertCount(6, $installments);
        $this->assertEqualsWithDelta(0.0, $installments[2]->principal, 0.001);
        $this->assertEqualsWithDelta(round(1000000 * 0.04 * 31 / 360, 2), $installments[2]->interest, 0.01);
        $this->assertEqualsWithDelta(1000000.0, $installments->last()->principal, 0.001);
    }

    public function test_grace_period_pays_only_interest_and_then_amortizes_over_the_remaining_term(): void
    {
        $schedule = $this->build(
            $this->loan(['monto_prestamo' => 1200000, 'plazo_meses' => 12]),
            $this->line(['meses_gracia' => 3]),
        );
        $installments = $this->installments($schedule);

        $this->assertCount(12, $installments);
        foreach ([0, 1, 2] as $index) {
            $this->assertEqualsWithDelta(0.0, $installments[$index]->principal, 0.001);
            $this->assertEqualsWithDelta(4000.0, $installments[$index]->interest, 0.01);
            $this->assertEqualsWithDelta(1200000.0, $installments[$index]->balance, 0.01);
        }

        $periodRate = 0.04 / 12;
        $this->assertEqualsWithDelta(round($this->annuity(1200000, $periodRate, 9), 2), $installments[3]->total(), 0.01);
        $this->assertGreaterThan(0, $installments[3]->principal);
        $this->assertEqualsWithDelta(0.0, $installments->last()->balance, 0.01);
    }

    public function test_balloon_leaves_a_percentage_of_the_amount_for_the_last_installment(): void
    {
        $schedule = $this->build(
            $this->loan(['monto_prestamo' => 1200000, 'plazo_meses' => 12]),
            $this->line(['porcentaje_global' => 20]),
        );
        $installments = $this->installments($schedule);

        $periodRate = 0.04 / 12;
        $expected = (1200000 - 240000 / (1 + $periodRate) ** 12) * $periodRate / (1 - (1 + $periodRate) ** -12);
        $this->assertEqualsWithDelta(round($expected, 2), $installments[0]->total(), 0.01);

        // Antes del final el saldo nunca baja del pago global, y la última cuota lo paga.
        $this->assertGreaterThanOrEqual(240000.0, $installments[10]->balance);
        $this->assertGreaterThan(240000.0, $installments->last()->principal);
        $this->assertEqualsWithDelta(0.0, $installments->last()->balance, 0.01);
        $this->assertEqualsWithDelta(1200000.0, $installments->sum('principal'), 0.05);
    }

    public function test_graduated_installments_grow_by_the_configured_percentage(): void
    {
        $schedule = $this->build(
            $this->loan(['monto_prestamo' => 1200000, 'plazo_meses' => 12]),
            $this->line(['modalidad_cuota' => 'CRECIENTE', 'variacion_porcentual' => 2]),
        );
        $installments = $this->installments($schedule);

        $this->assertCount(12, $installments);
        $this->assertEqualsWithDelta(1.02, $installments[1]->total() / $installments[0]->total(), 0.0005);
        $this->assertEqualsWithDelta(1.02, $installments[5]->total() / $installments[4]->total(), 0.0005);
        $this->assertLessThan($installments[10]->total(), $installments[0]->total());
        $this->assertEqualsWithDelta(0.0, $installments->last()->balance, 0.01);
        $this->assertEqualsWithDelta(1200000.0, $installments->sum('principal'), 0.05);
    }

    public function test_quarterly_payments_use_the_real_days_of_each_quarter(): void
    {
        $schedule = $this->build(
            $this->loan(['monto_prestamo' => 1000000, 'plazo_meses' => 24]),
            $this->line(['modalidad_pago' => 'TRIMESTRAL', 'calendario' => '365/360']),
        );
        $installments = $this->installments($schedule);

        // Del 01/03 al 01/06 son 92 días.
        $this->assertSame('2026-06-01', $installments[0]->date->toDateString());
        $this->assertEqualsWithDelta(round(1000000 * 0.04 * 92 / 360, 2), $installments[0]->interest, 0.01);
        $this->assertCount(8, $installments);
    }

    public function test_a_revision_inside_a_period_splits_the_interest_by_actual_days(): void
    {
        $revision = new RevisionPrestamo(['fecha_efectiva' => '2026-03-11', 'tasa_interes' => 0, 'tasa_spread' => 2]);

        $schedule = $this->build($this->loan(), $this->line(['calendario' => '365/360']), [$revision]);

        // 10 días al 4 % y 21 días al 2 %, sobre 360.
        $expected = 6000000 * (0.04 * 10 + 0.02 * 21) / 360;
        $this->assertEqualsWithDelta(round($expected, 2), $this->installments($schedule)->first()->interest, 0.01);
    }

    public function test_the_loan_overrides_the_conditions_of_its_line_and_blank_values_are_inherited(): void
    {
        $line = $this->line(['calendario' => '360/360', 'modalidad_cuota' => 'CONSTANTE', 'meses_gracia' => 0]);

        $inherited = LoanConditions::resolve($this->loan(), $line);
        $overridden = LoanConditions::resolve($this->loan([
            'calendario' => '365/365', 'modalidad_cuota' => 'DECRECIENTE', 'modalidad_pago' => 'TRIMESTRAL', 'meses_gracia' => 6, 'tipo_cuota' => 'EFECTIVA',
        ]), $line);

        $this->assertSame(DayCount::Thirty360, $inherited->dayCount);
        $this->assertSame(ModalidadCuota::Constante, $inherited->modality);
        $this->assertSame(1, $inherited->monthsPerPeriod);

        $this->assertSame(DayCount::Actual365, $overridden->dayCount);
        $this->assertSame(ModalidadCuota::Decreciente, $overridden->modality);
        $this->assertSame(3, $overridden->monthsPerPeriod);
        $this->assertSame(2, $overridden->gracePeriods());

        $schedule = $this->build($this->loan(['calendario' => '365/365']), $line);
        $this->assertEqualsWithDelta(round(6000000 * 0.04 * 31 / 365, 2), $this->installments($schedule)->first()->interest, 0.01);
    }

    public function test_conditions_are_described_for_the_screens(): void
    {
        $text = LoanConditions::resolve($this->loan(), $this->line([
            'modalidad_cuota' => 'CRECIENTE', 'calendario' => '365/360', 'meses_gracia' => 3, 'porcentaje_global' => 10, 'variacion_porcentual' => 1.5,
        ]))->describe();

        foreach (['Cuota creciente', '365/360', 'gracia de 3 meses', 'cuota global 10 %', 'crece 1.5 % por período', 'pago mensual'] as $part) {
            $this->assertStringContainsString($part, $text);
        }
    }

    public function test_payoff_quote_accrues_the_real_days_since_the_last_installment(): void
    {
        $loan = $this->loan(['cuota_calculada' => 73290.81]);
        $line = $this->line(['calendario' => '365/360']);
        $firstInstallmentPaid = new Recibo([
            'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => 1, 'fecha_pago' => '2026-04-01',
            'monto_principal' => 52624.14, 'monto_interes' => 20666.67, 'estado' => Recibo::STATUS_ACTIVE,
        ]);

        $quote = $this->builder->payoffQuote($loan, $line, [$firstInstallmentPaid], [], Carbon::parse('2026-04-20'));

        // El interés se devenga desde la última cuota (01/04) hasta el 20/04 = 19 días reales sobre 360.
        $this->assertEqualsWithDelta(5947375.86, $quote['principal'], 0.001);
        $this->assertEqualsWithDelta(round(5947375.86 * 0.04 * 19 / 360, 2), $quote['interest'], 0.01);
    }

    public function test_status_of_unpaid_installments_does_not_depend_on_the_calendar(): void
    {
        $schedule = $this->build($this->loan(), $this->line(['calendario' => '365/360']));

        $this->assertSame(EstadoCuota::Vencida, $this->installments($schedule)->first()->status);
        $this->assertSame(EstadoCuota::Pendiente, $this->installments($schedule)->last()->status);
    }
}
