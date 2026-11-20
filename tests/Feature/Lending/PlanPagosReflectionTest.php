<?php

namespace Tests\Feature\Lending;

use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\User;
use App\Services\Lending\LoanScheduleService;
use App\Services\Lending\ReceiptRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesLegacyPlanTable;
use Tests\TestCase;

class PlanPagosReflectionTest extends TestCase
{
    use CreatesLegacyPlanTable;
    use RefreshDatabase;

    protected LineaPrestamo $line;

    protected Prestamo $loan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->actingAs(User::factory()->create(['email' => 'jperez@corp.obgroup.com']));

        $this->line = LineaPrestamo::factory()->create(['monto_maximo' => 65000000]);
        $this->loan = Prestamo::factory()->create(['id_lineas_prestamos' => $this->line->id, 'numero_prestamo' => '9090', 'cuota_calculada' => 73135.65]);

        $this->createLegacyPlanTable();
    }

    protected function refresh(?Prestamo $loan = null): void
    {
        app(LoanScheduleService::class)->refreshSnapshot($loan ?? $this->loan);
    }

    protected function registrar(): ReceiptRegistrar
    {
        return app(ReceiptRegistrar::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function payInstallment(int $number, string $date, float $principal, float $interest, array $overrides = []): Recibo
    {
        return $this->registrar()->register($this->loan, $overrides + [
            'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => $number, 'fecha_pago' => $date,
            'monto_principal' => $principal, 'monto_interes' => $interest,
        ], 'jperez');
    }

    protected function row(int $number, ?Prestamo $loan = null): object
    {
        return DB::table('planpagos')->where('prestamo_id', ($loan ?? $this->loan)->id)->where('numero_cuota', $number)->first();
    }

    public function test_every_installment_of_the_plan_is_written_to_planpagos(): void
    {
        $this->refresh();

        $this->assertSame(96, DB::table('planpagos')->where('prestamo_id', $this->loan->id)->count());

        $first = $this->row(1);
        $this->assertSame('2026-04-01', substr($first->fecha_pago, 0, 10));
        $this->assertEqualsWithDelta(53135.65, $first->monto_principal, 0.001);
        $this->assertEqualsWithDelta(20000.00, $first->monto_interes, 0.001);
        $this->assertEqualsWithDelta(4.0, $first->tasa_interes, 0.001);
        $this->assertEqualsWithDelta(53135.65, $first->saldo_principal, 0.001);
        $this->assertEqualsWithDelta(20000.00, $first->saldo_interes, 0.001);
        $this->assertEqualsWithDelta(5946864.35, $first->saldo_prestamo, 0.001);
        $this->assertSame('P', $first->estado);
        $this->assertNull($first->fecha_pago_real);

        $this->assertEqualsWithDelta(0.0, $this->row(96)->saldo_prestamo, 0.01);
    }

    public function test_refreshing_again_does_not_duplicate_or_rewrite_unchanged_rows(): void
    {
        $this->refresh();
        $before = DB::table('planpagos')->orderBy('id')->get(['id', 'updated_at'])->all();

        Carbon::setTestNow('2026-11-19');
        $this->refresh();

        $this->assertSame(96, DB::table('planpagos')->count());
        $this->assertEquals($before, DB::table('planpagos')->orderBy('id')->get(['id', 'updated_at'])->all());
    }

    public function test_creating_a_sub_prestamo_from_the_screen_writes_its_plan(): void
    {
        $this->post(route('prestamos.store'), [
            'numero_prestamo' => '7070', 'id_lineas_prestamos' => $this->line->id, 'monto_prestamo' => 1200000,
            'formalizacion' => '2026-03-01', 'plazo_meses' => 12, 'tasa_interes' => 2, 'tasa_spread' => 2,
        ])->assertSessionHasNoErrors();

        $created = Prestamo::where('numero_prestamo', '7070')->firstOrFail();

        $this->assertSame(12, DB::table('planpagos')->where('prestamo_id', $created->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->row(12, $created)->saldo_prestamo, 0.01);
    }

    public function test_paying_an_installment_marks_it_paid_with_the_real_amounts_and_date(): void
    {
        $this->refresh();
        $this->payInstallment(1, '2026-04-05', 53135.65, 20000);

        $paid = $this->row(1);
        $this->assertSame('C', $paid->estado);
        $this->assertSame('2026-04-05', substr($paid->fecha_pago_real, 0, 10));
        $this->assertEqualsWithDelta(0.0, $paid->saldo_principal, 0.001);
        $this->assertEqualsWithDelta(0.0, $paid->saldo_interes, 0.001);
        $this->assertSame('P', $this->row(2)->estado);
        $this->assertSame(96, DB::table('planpagos')->count());
    }

    public function test_voiding_the_receipt_restores_the_installment(): void
    {
        $this->refresh();
        $receipt = $this->payInstallment(1, '2026-04-05', 53135.65, 20000);

        $this->registrar()->void($receipt, 'Error', 'auditor');

        $restored = $this->row(1);
        $this->assertSame('P', $restored->estado);
        $this->assertNull($restored->fecha_pago_real);
        $this->assertEqualsWithDelta(53135.65, $restored->saldo_principal, 0.001);
    }

    public function test_an_interest_only_payment_stays_pending_and_recalculates_the_next_installments(): void
    {
        $this->refresh();
        $this->payInstallment(1, '2026-04-01', 0, 20000, ['tipo' => TipoRecibo::ParcialInteres]);

        $partial = $this->row(1);
        $this->assertSame('P', $partial->estado);
        $this->assertSame('Pago parcial: solo interés', $partial->observaciones);
        $this->assertEqualsWithDelta(0.0, $partial->monto_principal, 0.001);
        $this->assertEqualsWithDelta(73789.12, $this->row(2)->monto_principal + $this->row(2)->monto_interes, 0.01);
    }

    public function test_a_rate_revision_rewrites_the_future_installments(): void
    {
        $this->refresh();
        $before = $this->row(8);

        $this->post(route('prestamos.revisiones.store', $this->loan), ['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1, 'tasa_spread' => 2])
            ->assertSessionHasNoErrors();

        $after = $this->row(8);
        $this->assertEqualsWithDelta(3.0, $after->tasa_interes, 0.001);
        $this->assertLessThan($before->monto_interes, $after->monto_interes);
        $this->assertEqualsWithDelta(4.0, $this->row(2)->tasa_interes, 0.001);
    }

    public function test_a_prepayment_shortens_the_plan_and_removes_the_obsolete_pending_rows(): void
    {
        $this->refresh();
        $this->payInstallment(1, '2026-04-01', 53135.65, 20000);
        $this->registrar()->register($this->loan, [
            'tipo' => TipoRecibo::SoloPrincipal, 'fecha_pago' => '2026-04-10', 'monto_principal' => 3000000,
        ], 'jperez');

        $count = DB::table('planpagos')->where('prestamo_id', $this->loan->id)->count();

        $this->assertLessThan(60, $count);
        $this->assertSame($count, (int) DB::table('planpagos')->max('numero_cuota'));
        $this->assertSame('C', $this->row(1)->estado);
    }

    public function test_a_paid_row_without_a_receipt_is_left_untouched(): void
    {
        $this->insertLegacyRow(['prestamo_id' => $this->loan->id, 'numero_cuota' => 1, 'estado' => 'C', 'monto_principal' => 999, 'monto_interes' => 111, 'fecha_pago_real' => '2026-03-09 00:00:00']);

        $this->refresh();

        $row = $this->row(1);
        $this->assertSame('C', $row->estado);
        $this->assertEqualsWithDelta(999.0, $row->monto_principal, 0.001);
        $this->assertSame(96, DB::table('planpagos')->where('prestamo_id', $this->loan->id)->count());
    }

    public function test_a_sub_prestamo_with_only_pending_rows_is_deleted_together_with_them(): void
    {
        $this->refresh();
        $this->assertSame(96, DB::table('planpagos')->count());

        $this->delete(route('prestamos.destroy', $this->loan))->assertRedirect(route('prestamos.index'));

        $this->assertSame(0, Prestamo::count());
        $this->assertSame(0, DB::table('planpagos')->count());
    }

    public function test_a_sub_prestamo_with_paid_rows_in_planpagos_cannot_be_deleted(): void
    {
        $this->insertLegacyRow(['prestamo_id' => $this->loan->id, 'numero_cuota' => 1, 'estado' => 'C']);

        $this->delete(route('prestamos.destroy', $this->loan))
            ->assertSessionHasErrors(['delete' => 'No se puede eliminar el sub préstamo 9090: tiene 1 cuota(s) pagada(s) en la tabla histórica planpagos.']);

        $this->assertSame(1, Prestamo::count());
        $this->assertSame(1, DB::table('planpagos')->count());
    }

    public function test_the_rebuild_command_can_be_simulated_without_writing(): void
    {
        $this->artisan('lending:rebuild-planpagos', ['--dry-run' => true])
            ->expectsOutputToContain('[simulación] sub préstamo 9090: 96 cuotas calculadas · 96 nuevas')
            ->expectsOutputToContain('Se harían: 96 nuevas, 0 actualizadas y 0 eliminadas')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('planpagos')->count());
    }

    public function test_the_rebuild_command_writes_the_plan_backs_up_existing_rows_and_is_idempotent(): void
    {
        $this->insertLegacyRow(['prestamo_id' => $this->loan->id, 'numero_cuota' => 3, 'estado' => 'P', 'monto_principal' => 5, 'monto_interes' => 6]);

        // El reloj está congelado en las pruebas, así que el nombre del respaldo es predecible.
        $backup = storage_path('app/backups/planpagos-'.now()->format('Ymd-His').'.json');
        File::delete($backup);

        $this->artisan('lending:rebuild-planpagos')->expectsOutputToContain('Respaldo de 1 filas')->assertSuccessful();

        $this->assertFileExists($backup);
        $this->assertStringContainsString('"numero_cuota": 3', File::get($backup));
        File::delete($backup);

        $this->assertSame(96, DB::table('planpagos')->where('prestamo_id', $this->loan->id)->count());
        $this->assertEqualsWithDelta(53135.65 + 0, round($this->row(1)->monto_principal, 2), 0.01);

        $this->artisan('lending:rebuild-planpagos', ['--dry-run' => true])
            ->expectsOutputToContain('Se harían: 0 nuevas, 0 actualizadas y 0 eliminadas')
            ->assertSuccessful();
    }

    public function test_the_rebuild_command_can_target_one_loan(): void
    {
        $other = Prestamo::factory()->create(['id_lineas_prestamos' => $this->line->id, 'numero_prestamo' => '4040', 'cuota_calculada' => 73135.65]);

        $this->artisan('lending:rebuild-planpagos', ['--loan' => '4040'])->assertSuccessful();

        $this->assertSame(96, DB::table('planpagos')->where('prestamo_id', $other->id)->count());
        $this->assertSame(0, DB::table('planpagos')->where('prestamo_id', $this->loan->id)->count());
    }

    public function test_interest_only_installments_of_a_grace_period_can_be_paid_as_a_full_installment(): void
    {
        $graceLine = LineaPrestamo::factory()->create(['meses_gracia' => 3, 'monto_maximo' => 65000000]);
        $graceLoan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $graceLine->id, 'numero_prestamo' => '8080', 'monto_prestamo' => 1200000, 'plazo_meses' => 12, 'cuota_calculada' => 0,
        ]);

        $receipt = $this->registrar()->register($graceLoan, [
            'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => 1, 'fecha_pago' => '2026-04-01', 'monto_principal' => 0, 'monto_interes' => 4000,
        ], 'jperez');

        $this->assertSame(['PI'], $receipt->detalles()->with('tipoPago')->get()->pluck('tipoPago.codigo')->all());
        $this->assertEqualsWithDelta(1200000.0, $graceLoan->fresh()->saldo_prestamo, 0.001);

        // Fuera de un período de gracia, una cuota completa aún requiere capital.
        try {
            $this->registrar()->register($this->loan, [
                'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => 1, 'fecha_pago' => '2026-04-01', 'monto_principal' => 0, 'monto_interes' => 20000,
            ], 'jperez');
            $this->fail('Principal is required outside a grace period.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('monto_principal', $exception->errors());
        }
    }

    public function test_a_payoff_requires_the_installments_due_before_it_to_be_paid(): void
    {
        try {
            $this->registrar()->register($this->loan, [
                'tipo' => TipoRecibo::CancelacionTotal, 'fecha_pago' => '2026-04-20', 'monto_principal' => 6000000, 'monto_interes' => 12000,
            ], 'jperez');
            $this->fail('A payoff with unpaid earlier installments must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Antes de cancelar debe pagar las cuotas', $exception->errors()['fecha_pago'][0]);
            $this->assertStringContainsString('la cuota 1 del 01/04/2026', $exception->errors()['fecha_pago'][0]);
        }

        $this->payInstallment(1, '2026-04-01', 53135.65, 20000);
        $quote = app(LoanScheduleService::class)->payoffQuote($this->loan, Carbon::parse('2026-04-20'));

        $this->registrar()->register($this->loan, [
            'tipo' => TipoRecibo::CancelacionTotal, 'fecha_pago' => '2026-04-20', 'monto_principal' => $quote['principal'], 'monto_interes' => $quote['interest'],
        ], 'jperez');

        $this->assertSame(Prestamo::STATUS_SETTLED, $this->loan->fresh()->estado);
    }
}
