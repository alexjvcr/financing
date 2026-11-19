<?php

namespace Tests\Feature;

use App\Enums\TipoRecibo;
use App\Models\Banco;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\Lending\LoanScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Prestamo $paidLoan;

    protected Prestamo $freshLoan;

    protected Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->today = today();

        $bct = Banco::factory()->create(['nombre_banco' => 'BCT']);
        $bncr = Banco::factory()->create(['nombre_banco' => 'BNCR']);

        $bctLine = LineaPrestamo::factory()->create(['nombre' => 'BCT DOLARES', 'banco_id' => $bct->id, 'monto_maximo' => 65000000]);
        LineaPrestamo::factory()->create(['nombre' => 'BNCR DOLARES', 'banco_id' => $bncr->id, 'monto_maximo' => 35000000]);
        LineaPrestamo::factory()->inactive()->create(['nombre' => 'LINEA CERRADA', 'monto_maximo' => 9000000]);

        $this->paidLoan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $bctLine->id, 'numero_prestamo' => '9090', 'cuota_calculada' => 73290.81,
        ]);
        $this->freshLoan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $bctLine->id, 'numero_prestamo' => '4040', 'cuota_calculada' => 73290.81,
        ]);
        Prestamo::factory()->settled()->create(['id_lineas_prestamos' => $bctLine->id, 'numero_prestamo' => '0001']);

        Recibo::factory()->create([
            'prestamo_id' => $this->paidLoan->id, 'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => 1,
            'fecha_pago' => '2026-04-01', 'monto_principal' => 53290.81, 'monto_interes' => 20000,
        ]);
        Recibo::factory()->create([
            'prestamo_id' => $this->paidLoan->id, 'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => 2,
            'fecha_pago' => '2026-05-01', 'monto_principal' => 53468.45, 'monto_interes' => 19822.36,
        ]);
    }

    protected function metrics(): DashboardMetrics
    {
        return app(DashboardMetrics::class);
    }

    public function test_guests_cannot_see_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_summary_derives_balances_from_receipts_and_ignores_settled_loans_and_lines(): void
    {
        $summary = $this->metrics()->summary();

        $this->assertEqualsWithDelta(5893240.74 + 6000000, $summary['outstanding_balance'], 0.01);
        $this->assertSame(2, $summary['active_loans']);
        $this->assertSame(2, $summary['active_credit_lines']);
        $this->assertSame(100000000.0, $summary['credit_lines_limit']);
    }

    public function test_overdue_installments_come_from_the_rebuilt_plan(): void
    {
        $service = app(LoanScheduleService::class);
        $paid = $service->scheduleFor($this->paidLoan, $this->today)->overdueInstallments();
        $fresh = $service->scheduleFor($this->freshLoan, $this->today)->overdueInstallments();

        $summary = $this->metrics()->summary();

        $this->assertSame(6, $paid->count());
        $this->assertSame(8, $fresh->count());
        $this->assertSame(14, $summary['overdue_installments']);
        $this->assertEqualsWithDelta($paid->sum->total() + $fresh->sum->total(), $summary['overdue_amount'], 0.01);
    }

    public function test_pending_installments_are_ordered_by_date_with_overdue_first(): void
    {
        $installments = $this->metrics()->pendingInstallments(40);

        $dates = $installments->pluck('due_date')->all();
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);

        $this->assertSame('4040', $installments->first()->loan_number);
        $this->assertTrue($installments->first()->is_overdue);
        $this->assertFalse($installments->last()->is_overdue);
        $this->assertCount(5, $this->metrics()->pendingInstallments(5));
    }

    public function test_next_installment_skips_overdue_ones(): void
    {
        $next = $this->metrics()->nextInstallment();

        $this->assertSame('2026-12-01', $next->due_date);
        $this->assertFalse($next->is_overdue);
        $this->assertSame('4040', $next->loan_number);
    }

    public function test_availability_is_grouped_by_bank_and_projected_from_open_installments(): void
    {
        $availability = $this->metrics()->creditAvailability($this->today, [3]);

        $this->assertSame(['BCT', 'BNCR'], $availability['rows']->pluck('name')->all());

        [$bct, $bncr] = $availability['rows']->all();
        $balance = 5893240.74 + 6000000;
        $this->assertEqualsWithDelta(65000000 - $balance, $bct->available, 0.01);
        $this->assertSame(35000000.0, $bncr->available);
        $this->assertSame([3 => 35000000.0], $bncr->projected);

        $cutoff = $this->today->copy()->addMonthsNoOverflow(3);
        $service = app(LoanScheduleService::class);
        $amortized = collect([$this->paidLoan, $this->freshLoan])
            ->flatMap(fn (Prestamo $loan) => $service->scheduleFor($loan, $this->today)->openInstallments())
            ->filter(fn ($row) => $row->date->lte($cutoff))
            ->sum('principal');
        $this->assertEqualsWithDelta(65000000 - ($balance - $amortized), $bct->projected[3], 0.01);

        $total = $availability['totals']->sole();
        $this->assertSame('DOLARES', $total->currency);
        $this->assertEqualsWithDelta(100000000 - $balance, $total->available, 0.01);
        $this->assertSame('2034-03-01', $availability['plan_ends_on']->toDateString());
    }

    public function test_lines_without_a_bank_fall_back_to_the_line_name(): void
    {
        LineaPrestamo::factory()->create(['nombre' => 'LINEA SIN BANCO', 'monto_maximo' => 1000]);

        $names = $this->metrics()->creditAvailability()['rows']->pluck('name')->all();

        $this->assertContains('LINEA SIN BANCO', $names);
    }

    public function test_dashboard_renders_menu_header_and_both_logout_options(): void
    {
        $user = User::factory()->create(['name' => 'Juan Perez', 'email' => 'jperez@corp.obgroup.com']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Juan Perez')
            ->assertSee('jperez@corp.obgroup.com')
            ->assertSee('id="theme-toggle"', false)
            ->assertSee('id="sidebar"', false)
            ->assertSee('Cuotas pendientes')
            ->assertSee('Disponible por banco')
            ->assertSee('24 meses')
            ->assertSee('BCT');

        $this->assertSame(2, substr_count($response->getContent(), 'action="'.route('logout').'"'));
    }
}
