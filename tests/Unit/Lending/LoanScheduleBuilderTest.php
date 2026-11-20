<?php

namespace Tests\Unit\Lending;

use App\Enums\EstadoCuota;
use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\RevisionPrestamo;
use App\Services\Lending\LoanSchedule;
use App\Services\Lending\LoanScheduleBuilder;
use App\Services\Lending\ScheduleRow;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanScheduleBuilderTest extends TestCase
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
            'cuota_calculada' => 73290.81,
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
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function receipt(TipoRecibo $type, string $date, float $principal, float $interest, ?int $number = null, array $attributes = []): Recibo
    {
        return new Recibo($attributes + [
            'tipo' => $type,
            'numero_cuota' => $number,
            'fecha_pago' => $date,
            'monto_principal' => $principal,
            'monto_interes' => $interest,
            'estado' => Recibo::STATUS_ACTIVE,
        ]);
    }

    /**
     * Las dos cuotas ya pagadas en la tabla heredada planpagos.
     *
     * @return array<int, Recibo>
     */
    protected function legacyPaidReceipts(): array
    {
        return [
            $this->receipt(TipoRecibo::CuotaCompleta, '2026-04-01', 53290.81, 20000.00, 1),
            $this->receipt(TipoRecibo::CuotaCompleta, '2026-05-01', 53468.45, 19822.36, 2),
        ];
    }

    /**
     * @param  array<int, Recibo>  $receipts
     * @param  array<int, RevisionPrestamo>  $revisions
     */
    protected function build(array $receipts = [], array $revisions = [], string $today = '2026-11-18', ?Prestamo $loan = null, ?LineaPrestamo $line = null): LoanSchedule
    {
        return $this->builder->build(
            $loan ?? $this->loan(),
            $line ?? $this->line(),
            $receipts,
            $revisions,
            Carbon::parse($today),
        );
    }

    protected function installment(LoanSchedule $schedule, int $number): ScheduleRow
    {
        return $schedule->rows->first(fn (ScheduleRow $row): bool => $row->isInstallment() && $row->number === $number);
    }

    public function test_first_installments_match_the_legacy_plan_figures(): void
    {
        $schedule = $this->build();

        $first = $this->installment($schedule, 1);
        $second = $this->installment($schedule, 2);

        $this->assertSame('2026-04-01', $first->date->toDateString());
        $this->assertEqualsWithDelta(20000.00, $first->interest, 0.001);
        $this->assertEqualsWithDelta(53290.81, $first->principal, 0.001);
        $this->assertEqualsWithDelta(5946709.19, $first->balance, 0.001);
        $this->assertEqualsWithDelta(19822.36, $second->interest, 0.001);
        $this->assertEqualsWithDelta(53468.45, $second->principal, 0.001);
        $this->assertEqualsWithDelta(5893240.74, $second->balance, 0.001);
    }

    public function test_plan_covers_the_whole_term_and_ends_at_zero(): void
    {
        $schedule = $this->build();

        $this->assertSame(96, $schedule->rows->count());
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.001);
        $this->assertSame('2034-03-01', $schedule->maturityDate()->toDateString());
        $this->assertFalse($schedule->isSettled());
        $this->assertEqualsWithDelta(6000000.0, $schedule->rows->sum('principal'), 0.05);
    }

    public function test_installment_is_calculated_when_the_loan_has_none(): void
    {
        $schedule = $this->build(loan: $this->loan(['cuota_calculada' => 0]));

        $this->assertEqualsWithDelta(73135.65, $this->installment($schedule, 1)->total(), 0.01);
        $this->assertSame(96, $schedule->rows->count());
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.001);
    }

    public function test_unpaid_installments_are_flagged_overdue_or_pending_by_today(): void
    {
        $schedule = $this->build(today: '2026-11-18');

        $this->assertSame(8, $schedule->overdueInstallments()->count());
        $this->assertSame('2026-04-01', $schedule->nextOpenInstallment()->date->toDateString());
        $this->assertSame('2026-12-01', $schedule->nextUpcomingInstallment()->date->toDateString());
        $this->assertSame(EstadoCuota::Pendiente, $this->installment($schedule, 10)->status);
    }

    public function test_paid_receipts_close_installments_and_rebuild_the_balance(): void
    {
        $schedule = $this->build($this->legacyPaidReceipts());

        $this->assertSame(EstadoCuota::Pagada, $this->installment($schedule, 1)->status);
        $this->assertSame(EstadoCuota::Pagada, $this->installment($schedule, 2)->status);
        $this->assertEqualsWithDelta(5893240.74, $schedule->outstandingPrincipal(), 0.001);

        $third = $this->installment($schedule, 3);
        $this->assertEqualsWithDelta(53646.67, $third->principal, 0.001);
        $this->assertEqualsWithDelta(19644.14, $third->interest, 0.001);
        $this->assertSame(3, $schedule->nextOpenInstallment()->number);
    }

    public function test_voided_receipts_are_ignored(): void
    {
        $voided = $this->receipt(TipoRecibo::CuotaCompleta, '2026-04-01', 53290.81, 20000, 1, ['estado' => Recibo::STATUS_VOIDED]);

        $schedule = $this->build([$voided]);

        $this->assertSame(EstadoCuota::Vencida, $this->installment($schedule, 1)->status);
        $this->assertEqualsWithDelta(6000000.0, $schedule->outstandingPrincipal(), 0.001);
    }

    public function test_paying_different_amounts_is_reported_as_variance(): void
    {
        $schedule = $this->build([
            $this->receipt(TipoRecibo::CuotaCompleta, '2026-04-01', 60000, 20000, 1),
        ]);

        $first = $this->installment($schedule, 1);
        $this->assertTrue($first->hasVariance());
        $this->assertEqualsWithDelta(5940000.0, $first->balance, 0.001);
    }

    public function test_principal_prepayment_on_a_due_date_shortens_the_term_keeping_the_installment(): void
    {
        $withoutPrepayment = $this->build($this->legacyPaidReceipts());
        $prepayment = $this->receipt(TipoRecibo::SoloPrincipal, '2026-05-01', 500000, 0);

        $schedule = $this->build([...$this->legacyPaidReceipts(), $prepayment]);

        $abono = $schedule->rows->first(fn (ScheduleRow $row): bool => $row->kind === ScheduleRow::KIND_PREPAYMENT);
        $this->assertSame(EstadoCuota::Abono, $abono->status);
        $this->assertEqualsWithDelta(5393240.74, $abono->balance, 0.001);

        $third = $this->installment($schedule, 3);
        $this->assertEqualsWithDelta(17977.47, $third->interest, 0.001);
        $this->assertEqualsWithDelta(73290.81, $third->total(), 0.01);

        $this->assertLessThan(
            $withoutPrepayment->rows->filter->isInstallment()->count(),
            $schedule->rows->filter->isInstallment()->count(),
        );
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.001);
        $this->assertEqualsWithDelta(5393240.74, $schedule->outstandingPrincipal(), 0.001);
    }

    public function test_advance_payment_behaves_like_a_principal_prepayment(): void
    {
        $advance = $this->receipt(TipoRecibo::AdelantoCuota, '2026-05-01', 73290.81, 0);

        $schedule = $this->build([...$this->legacyPaidReceipts(), $advance]);

        $this->assertEqualsWithDelta(5893240.74 - 73290.81, $schedule->outstandingPrincipal(), 0.001);
        $this->assertSame(EstadoCuota::Abono, $schedule->rows->first(fn (ScheduleRow $row): bool => $row->kind === ScheduleRow::KIND_PREPAYMENT)->status);
    }

    public function test_prepayment_inside_a_period_prorates_the_interest_on_30_360(): void
    {
        $prepayment = $this->receipt(TipoRecibo::SoloPrincipal, '2026-05-16', 600000, 0);

        $schedule = $this->build([...$this->legacyPaidReceipts(), $prepayment]);

        $expected = round(5893240.74 * 0.04 / 12 * 0.5 + (5893240.74 - 600000) * 0.04 / 12 * 0.5, 2);
        $this->assertEqualsWithDelta($expected, $this->installment($schedule, 3)->interest, 0.01);

        $abono = $schedule->rows->first(fn (ScheduleRow $row): bool => $row->kind === ScheduleRow::KIND_PREPAYMENT);
        $this->assertSame('2026-05-16', $abono->date->toDateString());
        $this->assertSame(
            ['2026-04-01', '2026-05-01', '2026-05-16', '2026-06-01'],
            $schedule->rows->take(4)->map(fn (ScheduleRow $row): string => $row->date->toDateString())->all(),
        );
    }

    public function test_interest_only_receipt_defers_principal_and_recalculates_the_installment(): void
    {
        $interestOnly = $this->receipt(TipoRecibo::ParcialInteres, '2026-04-01', 0, 20000, 1);

        $schedule = $this->build([$interestOnly]);

        $first = $this->installment($schedule, 1);
        $this->assertSame(EstadoCuota::ParcialInteres, $first->status);
        $this->assertEqualsWithDelta(6000000.0, $first->balance, 0.001);

        $second = $this->installment($schedule, 2);
        $this->assertEqualsWithDelta(20000.0, $second->interest, 0.001);
        $this->assertEqualsWithDelta(73789.12, $second->total(), 0.01);
        $this->assertSame(96, $schedule->rows->count());
    }

    public function test_rate_revision_recalculates_the_installment_from_the_next_period(): void
    {
        $revision = new RevisionPrestamo(['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1, 'tasa_spread' => 2]);

        $schedule = $this->build($this->legacyPaidReceipts(), [$revision]);

        $fourth = $this->installment($schedule, 4);
        $fifth = $this->installment($schedule, 5);

        $this->assertEqualsWithDelta(4.0, $fourth->annualRate, 0.0001);
        $this->assertEqualsWithDelta(3.0, $fifth->annualRate, 0.0001);
        $this->assertEqualsWithDelta($fourth->balance * 0.03 / 12, $fifth->interest, 0.01);
        $this->assertEqualsWithDelta(3.0, $schedule->currentAnnualRate, 0.0001);

        $periods = 96 - 4;
        $periodRate = 0.03 / 12;
        $expected = $fourth->balance * $periodRate / (1 - (1 + $periodRate) ** -$periods);
        $this->assertEqualsWithDelta($expected, $fifth->total(), 0.01);
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.01);
    }

    public function test_rate_revision_inside_a_period_splits_the_interest(): void
    {
        $revision = new RevisionPrestamo(['fecha_efectiva' => '2026-05-16', 'tasa_interes' => 0, 'tasa_spread' => 2]);

        $schedule = $this->build($this->legacyPaidReceipts(), [$revision]);

        $expected = round(5893240.74 * 0.04 / 12 * 0.5 + 5893240.74 * 0.02 / 12 * 0.5, 2);
        $this->assertEqualsWithDelta($expected, $this->installment($schedule, 3)->interest, 0.01);
    }

    public function test_term_revision_resets_the_remaining_periods(): void
    {
        $revision = new RevisionPrestamo(['fecha_efectiva' => '2026-04-01', 'plazo_restante_meses' => 24]);

        $schedule = $this->build([$this->legacyPaidReceipts()[0]], [$revision]);

        $this->assertSame(25, $schedule->rows->filter->isInstallment()->count());
        $this->assertSame('2028-04-01', $schedule->maturityDate()->toDateString());
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.01);
    }

    public function test_revisions_that_are_voided_do_not_apply(): void
    {
        $revision = new RevisionPrestamo(['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1, 'tasa_spread' => 2, 'anulado_at' => now()]);

        $schedule = $this->build($this->legacyPaidReceipts(), [$revision]);

        $this->assertEqualsWithDelta(4.0, $this->installment($schedule, 5)->annualRate, 0.0001);
        $this->assertEqualsWithDelta(4.0, $schedule->currentAnnualRate, 0.0001);
    }

    public function test_payoff_quote_returns_balance_plus_accrued_interest(): void
    {
        $quote = $this->builder->payoffQuote(
            $this->loan(), $this->line(), $this->legacyPaidReceipts(), [], Carbon::parse('2026-05-16'),
        );

        $this->assertEqualsWithDelta(5893240.74, $quote['principal'], 0.001);
        $this->assertEqualsWithDelta(9822.07, $quote['interest'], 0.01);
    }

    public function test_payoff_receipt_closes_the_loan_and_ends_the_plan(): void
    {
        $payoff = $this->receipt(TipoRecibo::CancelacionTotal, '2026-05-16', 5893240.74, 9822.07);

        $schedule = $this->build([...$this->legacyPaidReceipts(), $payoff]);

        $last = $schedule->rows->last();
        $this->assertSame(ScheduleRow::KIND_PAYOFF, $last->kind);
        $this->assertSame(EstadoCuota::Cancelacion, $last->status);
        $this->assertEqualsWithDelta(0.0, $last->balance, 0.001);
        $this->assertSame(3, $schedule->rows->count());
        $this->assertTrue($schedule->isSettled());
        $this->assertEqualsWithDelta(0.0, $schedule->outstandingPrincipal(), 0.001);
        $this->assertSame('2026-05-16', $schedule->maturityDate()->toDateString());
    }

    public function test_payoff_on_a_due_date_after_that_installment_was_paid_has_no_accrued_interest(): void
    {
        $quote = $this->builder->payoffQuote(
            $this->loan(), $this->line(), $this->legacyPaidReceipts(), [], Carbon::parse('2026-05-01'),
        );

        $this->assertEqualsWithDelta(5893240.74, $quote['principal'], 0.001);
        $this->assertEqualsWithDelta(0.0, $quote['interest'], 0.001);
    }

    public function test_full_prepayment_settles_the_loan(): void
    {
        $prepayment = $this->receipt(TipoRecibo::SoloPrincipal, '2026-05-01', 5893240.74, 0);

        $schedule = $this->build([...$this->legacyPaidReceipts(), $prepayment]);

        $this->assertTrue($schedule->isSettled());
        $this->assertEqualsWithDelta(0.0, $schedule->outstandingPrincipal(), 0.001);
        $this->assertSame(0, $schedule->openInstallments()->count());
    }

    public function test_decreasing_installments_keep_a_constant_principal(): void
    {
        $schedule = $this->build(
            loan: $this->loan(['monto_prestamo' => 1200000, 'plazo_meses' => 12, 'cuota_calculada' => 0]),
            line: $this->line(['modalidad_cuota' => 'DECRECIENTE']),
        );

        $this->assertSame(12, $schedule->rows->count());
        $this->assertEqualsWithDelta(100000.0, $this->installment($schedule, 1)->principal, 0.01);
        $this->assertEqualsWithDelta(100000.0, $this->installment($schedule, 7)->principal, 0.01);
        $this->assertGreaterThan($this->installment($schedule, 12)->total(), $this->installment($schedule, 1)->total());
        $this->assertEqualsWithDelta(0.0, $schedule->rows->last()->balance, 0.01);
    }

    public function test_bullet_loans_pay_only_interest_until_the_last_installment(): void
    {
        $schedule = $this->build(
            loan: $this->loan(['monto_prestamo' => 1000000, 'plazo_meses' => 6, 'cuota_calculada' => 0]),
            line: $this->line(['modalidad_cuota' => 'BULLET']),
        );

        $this->assertSame(6, $schedule->rows->count());
        $this->assertEqualsWithDelta(0.0, $this->installment($schedule, 3)->principal, 0.001);
        $this->assertEqualsWithDelta(1000000.0, $this->installment($schedule, 6)->principal, 0.001);
    }

    public function test_quarterly_lines_accrue_a_quarter_of_the_annual_rate_per_period(): void
    {
        $schedule = $this->build(
            loan: $this->loan(['monto_prestamo' => 1000000, 'plazo_meses' => 24, 'cuota_calculada' => 0]),
            line: $this->line(['modalidad_pago' => 'TRIMESTRAL']),
        );

        $first = $this->installment($schedule, 1);
        $this->assertSame('2026-06-01', $first->date->toDateString());
        $this->assertEqualsWithDelta(10000.0, $first->interest, 0.001);
        $this->assertSame(8, $schedule->rows->count());
    }

    public function test_month_end_formalization_keeps_due_dates_on_the_last_day(): void
    {
        $schedule = $this->build(loan: $this->loan(['formalizacion' => '2026-01-31', 'plazo_meses' => 4, 'cuota_calculada' => 0]));

        $this->assertSame(
            ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            $schedule->rows->map(fn (ScheduleRow $row): string => $row->date->toDateString())->all(),
        );
        $this->assertEqualsWithDelta(20000.0, $this->installment($schedule, 1)->interest, 0.001);
    }
}
