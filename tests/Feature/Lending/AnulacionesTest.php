<?php

namespace Tests\Feature\Lending;

use App\Enums\TipoRecibo;
use App\Models\Banco;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\RevisionPrestamo;
use App\Models\User;
use App\Services\Lending\ReceiptRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesLegacyPlanTable;
use Tests\TestCase;

class AnulacionesTest extends TestCase
{
    use CreatesLegacyPlanTable;
    use RefreshDatabase;

    protected LineaPrestamo $line;

    protected Prestamo $loan;

    protected ReceiptRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18 10:30:00');
        $this->actingAs(User::factory()->create(['email' => 'auditor@corp.obgroup.com']));

        $this->line = LineaPrestamo::factory()->create([
            'nombre' => 'BCT DOLARES', 'monto_maximo' => 65000000, 'banco_id' => Banco::factory()->create(['nombre_banco' => 'BCT'])->id,
        ]);
        $this->loan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $this->line->id, 'numero_prestamo' => '9090', 'cuota_calculada' => 73290.81,
        ]);
        $this->registrar = app(ReceiptRegistrar::class);
    }

    /**
     * Registra las tres primeras cuotas completas: secuencia de pagos estilo enero.
     *
     * @return array<int, Recibo>
     */
    protected function payThreeInstallments(): array
    {
        $payments = [
            [1, '2026-04-01', 53290.81, 20000.00],
            [2, '2026-05-01', 53468.45, 19822.36],
            [3, '2026-06-01', 53646.67, 19644.14],
        ];

        return array_map(fn (array $payment): Recibo => $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => $payment[0], 'fecha_pago' => $payment[1],
            'monto_principal' => $payment[2], 'monto_interes' => $payment[3],
        ], 'jperez'), $payments);
    }

    protected function createLegacyPlan(): void
    {
        $this->createLegacyPlanTable();

        foreach ([1, 2, 3, 4] as $number) {
            $this->insertLegacyRow(['prestamo_id' => $this->loan->id, 'numero_cuota' => $number]);
        }
    }

    public function test_receipts_must_be_voided_from_the_most_recent_to_the_oldest(): void
    {
        [$first, $second, $third] = $this->payThreeInstallments();

        foreach ([$first, $second] as $older) {
            try {
                $this->registrar->void($older, 'Error', 'auditor');
                $this->fail('An older receipt must not be voided while a later one stands.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('del más reciente al más antiguo', $exception->errors()['recibo'][0]);
                $this->assertStringContainsString('#'.$third->id.' del 01/06/2026', $exception->errors()['recibo'][0]);
            }
        }

        $this->registrar->void($third, 'Error', 'auditor');
        $this->registrar->void($second, 'Error', 'auditor');
        $this->registrar->void($first, 'Error', 'auditor');

        $this->assertSame(0, Recibo::where('estado', Recibo::STATUS_ACTIVE)->count());
        $this->assertEqualsWithDelta(6000000.0, $this->loan->fresh()->saldo_prestamo, 0.001);
    }

    public function test_an_older_date_cannot_be_paid_when_a_later_installment_is_already_paid(): void
    {
        $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => 1, 'fecha_pago' => '2026-05-10',
            'monto_principal' => 53290.81, 'monto_interes' => 20000,
        ], 'jperez');

        try {
            $this->registrar->register($this->loan, [
                'tipo' => TipoRecibo::SoloPrincipal, 'fecha_pago' => '2026-04-15', 'monto_principal' => 1000,
            ], 'jperez');
            $this->fail('A payment dated before an existing one must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fecha_pago', $exception->errors());
        }
    }

    public function test_voiding_restores_the_balance_the_installment_and_the_available_credit(): void
    {
        $this->createLegacyPlan();
        [$first, $second] = $this->payThreeInstallments();

        $this->assertSame(['C', 'C', 'C', 'P'], $this->legacyStatuses($this->loan->id));
        $this->assertEqualsWithDelta(5839594.07, $this->loan->fresh()->saldo_prestamo, 0.001);

        $third = Recibo::where('numero_cuota', 3)->firstOrFail();
        $this->registrar->void($third, 'Pago duplicado', 'auditor');

        $loan = $this->loan->fresh();
        $this->assertEqualsWithDelta(5893240.74, $loan->saldo_prestamo, 0.001);
        $this->assertSame('2026-06-01', $loan->proximo_pago->toDateString());
        $this->assertEqualsWithDelta(65000000 - 5893240.74, $this->line->fresh()->disponible, 0.001);

        $this->assertSame(['C', 'C', 'P', 'P'], $this->legacyStatuses($this->loan->id));
        $this->assertNull(DB::table('planpagos')->where('numero_cuota', 3)->value('fecha_pago_real'));
        $this->assertNotNull(DB::table('planpagos')->where('numero_cuota', 2)->value('fecha_pago_real'));
    }

    public function test_voiding_leaves_the_audit_trail_with_date_user_and_reason(): void
    {
        [$first] = $this->payThreeInstallments();
        Recibo::where('numero_cuota', 3)->firstOrFail();
        $third = Recibo::where('numero_cuota', 3)->firstOrFail();

        $this->registrar->void($third, 'Monto incorrecto', 'auditor@corp.obgroup.com');

        $third->refresh();
        $this->assertSame(Recibo::STATUS_VOIDED, $third->estado);
        $this->assertSame('2026-11-18 10:30:00', $third->anulado_at->format('Y-m-d H:i:s'));
        $this->assertSame('auditor@corp.obgroup.com', $third->anulado_por);
        $this->assertSame('Monto incorrecto', $third->motivo_anulacion);
        $this->assertSame('jperez', $third->usuario);
        $this->assertSame(2, $third->detalles()->count());
    }

    public function test_after_voiding_the_installment_can_be_paid_again_with_a_new_receipt(): void
    {
        $this->createLegacyPlan();
        $this->payThreeInstallments();
        $this->registrar->void(Recibo::where('numero_cuota', 3)->firstOrFail(), 'Error', 'auditor');

        $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::CuotaCompleta, 'numero_cuota' => 3, 'fecha_pago' => '2026-06-05',
            'monto_principal' => 53646.67, 'monto_interes' => 19644.14,
        ], 'jperez');

        $this->assertSame(['C', 'C', 'C', 'P'], $this->legacyStatuses($this->loan->id));
        $this->assertSame(1, Recibo::where('numero_cuota', 3)->where('estado', Recibo::STATUS_ACTIVE)->count());
        $this->assertSame(2, Recibo::where('numero_cuota', 3)->count());
    }

    public function test_the_anulaciones_screen_only_offers_the_most_recent_receipt_of_each_loan(): void
    {
        $this->payThreeInstallments();
        $other = Prestamo::factory()->create(['id_lineas_prestamos' => $this->line->id, 'numero_prestamo' => '4040', 'cuota_calculada' => 73290.81]);
        Recibo::factory()->create(['prestamo_id' => $other->id, 'numero_cuota' => 1, 'fecha_pago' => '2026-04-01', 'usuario' => 'otro_usuario']);

        $latest = Recibo::where('prestamo_id', $this->loan->id)->where('numero_cuota', 3)->firstOrFail();
        $content = $this->get(route('anulaciones.index'))->assertOk()->assertSee('Recibos por anular (2)')->getContent();

        $this->assertStringContainsString(route('recibos.anular', $latest), $content);
        $this->assertStringNotContainsString(route('recibos.anular', Recibo::where('numero_cuota', 1)->where('prestamo_id', $this->loan->id)->firstOrFail()), $content);
        $this->assertStringContainsString('2 más', $content);
        $this->assertStringContainsString('name="volver" value="anulaciones"', $content);
        $this->assertStringContainsString('data-confirm="¿Anular el recibo #'.$latest->id.' del sub préstamo 9090?', $content);
    }

    public function test_voiding_from_the_screen_records_the_logged_in_user_and_returns_to_it(): void
    {
        $this->payThreeInstallments();
        $latest = Recibo::where('numero_cuota', 3)->firstOrFail();

        $this->post(route('recibos.anular', $latest), ['motivo' => 'Pago equivocado', 'volver' => 'anulaciones'])
            ->assertRedirect(route('anulaciones.index'))
            ->assertSessionHas('status');

        $this->assertSame('auditor@corp.obgroup.com', $latest->fresh()->anulado_por);
        $this->assertSame('Pago equivocado', $latest->fresh()->motivo_anulacion);
    }

    public function test_the_reason_is_required_and_older_receipts_are_rejected_through_the_screen(): void
    {
        [$first] = $this->payThreeInstallments();
        $latest = Recibo::where('numero_cuota', 3)->firstOrFail();

        $this->post(route('recibos.anular', $latest), ['motivo' => ''])->assertSessionHasErrors('motivo');

        $this->post(route('recibos.anular', $first), ['motivo' => 'Quiero anular el primero', 'volver' => 'anulaciones'])
            ->assertSessionHasErrors('recibo');

        $this->assertSame(Recibo::STATUS_ACTIVE, $first->fresh()->estado);
    }

    public function test_the_history_lists_who_voided_when_and_why(): void
    {
        $this->payThreeInstallments();
        $this->registrar->void(Recibo::where('numero_cuota', 3)->firstOrFail(), 'Fecha mal digitada', 'supervisor@corp.obgroup.com');

        $this->get(route('anulaciones.index'))
            ->assertOk()
            ->assertSee('Recibos anulados (1)')
            ->assertSee('supervisor@corp.obgroup.com')
            ->assertSee('Fecha mal digitada')
            ->assertSee('18/11/2026 10:30');
    }

    public function test_revisions_are_voided_from_the_most_recent_with_a_reason_and_audit(): void
    {
        $this->post(route('prestamos.revisiones.store', $this->loan), ['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1]);
        $this->post(route('prestamos.revisiones.store', $this->loan), ['fecha_efectiva' => '2026-09-01', 'tasa_interes' => 0.5]);
        [$older, $newer] = $this->loan->revisiones()->get()->all();

        $this->post(route('revisiones.anular', $older), ['motivo' => 'x'])->assertSessionHasErrors('revision');
        $this->post(route('revisiones.anular', $newer), [])->assertSessionHasErrors('motivo');

        $this->get(route('anulaciones.index'))->assertSee('Revisiones por anular (1)');

        $this->post(route('revisiones.anular', $newer), ['motivo' => 'Tasa errónea', 'volver' => 'anulaciones'])
            ->assertRedirect(route('anulaciones.index'));

        $newer->refresh();
        $this->assertNotNull($newer->anulado_at);
        $this->assertSame('auditor@corp.obgroup.com', $newer->anulado_por);
        $this->assertSame('Tasa errónea', $newer->motivo_anulacion);
        $this->assertInstanceOf(RevisionPrestamo::class, $older->fresh());

        $this->get(route('anulaciones.index'))->assertSee('Revisiones anuladas (1)')->assertSee('Tasa errónea');
    }

    public function test_voiding_a_payoff_reactivates_the_loan_and_the_credit_line(): void
    {
        $payoff = $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::CancelacionTotal, 'fecha_pago' => '2026-03-16', 'monto_principal' => 6000000, 'monto_interes' => 10000,
        ], 'jperez');
        $this->assertSame(Prestamo::STATUS_SETTLED, $this->loan->fresh()->estado);
        $this->assertEqualsWithDelta(65000000, $this->line->fresh()->disponible, 0.001);

        $this->registrar->void($payoff, 'Cancelación errónea', 'auditor');

        $this->assertSame(Prestamo::STATUS_ACTIVE, $this->loan->fresh()->estado);
        $this->assertEqualsWithDelta(6000000.0, $this->loan->fresh()->saldo_prestamo, 0.001);
        $this->assertEqualsWithDelta(65000000 - 6000000, $this->line->fresh()->disponible, 0.001);
    }

    public function test_the_menu_item_is_enabled_and_the_screen_requires_login(): void
    {
        $content = $this->get(route('anulaciones.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="nav-link is-active">\s*<svg.*?<\/svg>\s*Anulaciones/s', $content);
        $this->assertStringContainsString('href="'.route('anulaciones.index').'"', $content);

        auth()->logout();
        $this->get(route('anulaciones.index'))->assertRedirect(route('login'));
    }

    public function test_the_screen_can_be_searched_by_sub_prestamo(): void
    {
        $this->payThreeInstallments();
        $other = Prestamo::factory()->create(['id_lineas_prestamos' => $this->line->id, 'numero_prestamo' => '4040', 'cuota_calculada' => 73290.81]);
        Recibo::factory()->create(['prestamo_id' => $other->id, 'numero_cuota' => 1, 'usuario' => 'otro_usuario']);

        $this->get(route('anulaciones.index', ['q' => '4040']))->assertSee('Recibos por anular (1)')->assertSee('otro_usuario');
        $this->get(route('anulaciones.index', ['q' => 'zzz']))->assertSee('No hay recibos para anular.');
    }
}
