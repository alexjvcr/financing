<?php

namespace Tests\Feature;

use App\Enums\TipoRecibo;
use App\Models\Banco;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrestamoScreensTest extends TestCase
{
    use RefreshDatabase;

    protected LineaPrestamo $line;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->actingAs(User::factory()->create(['email' => 'jperez@corp.obgroup.com']));
        $this->line = LineaPrestamo::factory()->create(['nombre' => 'BCT DOLARES', 'monto_maximo' => 65000000]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function loanPayload(array $overrides = []): array
    {
        return $overrides + [
            'numero_prestamo' => '9090',
            'id_lineas_prestamos' => $this->line->id,
            'monto_prestamo' => 6000000,
            'formalizacion' => '2026-03-01',
            'plazo_meses' => 96,
            'tasa_interes' => 2,
            'tasa_spread' => 2,
        ];
    }

    protected function createLoan(array $overrides = []): Prestamo
    {
        $this->post(route('prestamos.store'), $this->loanPayload($overrides))->assertSessionHasNoErrors();

        return Prestamo::where('numero_prestamo', $overrides['numero_prestamo'] ?? '9090')->firstOrFail();
    }

    public function test_loan_is_created_with_the_calculated_installment_and_dates(): void
    {
        $loan = $this->createLoan();

        $this->assertEqualsWithDelta(73135.65, $loan->cuota_calculada, 0.01);
        $this->assertSame('2026-04-01', $loan->proximo_pago->toDateString());
        $this->assertSame('2034-03-01', $loan->vencimiento->toDateString());
        $this->assertSame(6000000.0, $loan->saldo_prestamo);
        $this->assertSame(Prestamo::STATUS_ACTIVE, $loan->estado);
    }

    public function test_agreed_installment_is_kept_when_given(): void
    {
        $loan = $this->createLoan(['cuota_calculada' => 73290.81]);

        $this->assertSame(73290.81, $loan->cuota_calculada);
    }

    public function test_loan_cannot_exceed_the_available_credit_of_its_line(): void
    {
        $this->createLoan(['numero_prestamo' => '1111', 'monto_prestamo' => 60000000]);

        $this->post(route('prestamos.store'), $this->loanPayload(['numero_prestamo' => '2222', 'monto_prestamo' => 6000000]))
            ->assertSessionHasErrors('monto_prestamo');
        $this->assertSame(1, Prestamo::count());
    }

    public function test_loan_number_must_be_unique_and_the_line_active(): void
    {
        $this->createLoan();

        $this->post(route('prestamos.store'), $this->loanPayload())->assertSessionHasErrors('numero_prestamo');

        $inactive = LineaPrestamo::factory()->inactive()->create();
        $this->post(route('prestamos.store'), $this->loanPayload(['numero_prestamo' => '3333', 'id_lineas_prestamos' => $inactive->id]))
            ->assertSessionHasErrors('id_lineas_prestamos');
    }

    public function test_index_lists_loans_and_filters_by_status(): void
    {
        $this->createLoan();
        Prestamo::factory()->settled()->create(['id_lineas_prestamos' => $this->line->id, 'numero_prestamo' => '0001']);

        $this->get(route('prestamos.index'))->assertOk()->assertSee('9090')->assertSee('0001');
        $this->get(route('prestamos.index', ['estado' => 'ACTIVO']))->assertOk()->assertSee('9090')->assertDontSee('0001');
    }

    public function test_detail_shows_the_rebuilt_plan_with_all_installments(): void
    {
        $loan = $this->createLoan();

        $response = $this->get(route('prestamos.show', $loan))->assertOk();

        $response->assertSee('Plan de pagos')->assertSee('Registrar recibo')->assertSee('Revisión de tasa o plazo');
        $this->assertSame(96, substr_count($response->getContent(), '<td>Cuota</td>'));
    }

    public function test_recibo_flow_through_the_screens(): void
    {
        $loan = $this->createLoan(['cuota_calculada' => 73290.81]);

        $this->post(route('prestamos.recibos.store', $loan), [
            'tipo' => TipoRecibo::CuotaCompleta->value, 'fecha_pago' => '2026-04-01', 'numero_cuota' => 1,
            'monto_principal' => 53290.81, 'monto_interes' => 20000,
        ])->assertRedirect(route('prestamos.show', $loan))->assertSessionHas('status');

        $receipt = Recibo::firstOrFail();
        $this->assertSame('jperez@corp.obgroup.com', $receipt->usuario);
        $this->assertEqualsWithDelta(5946709.19, $loan->fresh()->saldo_prestamo, 0.001);

        $this->get(route('prestamos.show', $loan))->assertOk()->assertSee('1. Cuota completa')->assertSee('Anular');

        $this->post(route('recibos.anular', $receipt), ['motivo' => 'Error de captura'])->assertRedirect(route('prestamos.show', $loan));
        $this->assertSame(Recibo::STATUS_VOIDED, $receipt->fresh()->estado);
        $this->assertSame(6000000.0, $loan->fresh()->saldo_prestamo);
    }

    public function test_recibo_business_errors_are_shown_back_to_the_form(): void
    {
        $loan = $this->createLoan();

        $this->from(route('prestamos.show', $loan))
            ->post(route('prestamos.recibos.store', $loan), [
                'tipo' => TipoRecibo::CuotaCompleta->value, 'fecha_pago' => '2026-04-01', 'numero_cuota' => 5,
                'monto_principal' => 100, 'monto_interes' => 10,
            ])
            ->assertRedirect(route('prestamos.show', $loan))
            ->assertSessionHasErrors('numero_cuota');

        $this->post(route('prestamos.recibos.store', $loan), ['tipo' => 'NO_EXISTE', 'fecha_pago' => 'x'])
            ->assertSessionHasErrors(['tipo', 'fecha_pago']);
        $this->assertSame(0, Recibo::count());
    }

    public function test_revision_flow_through_the_screens(): void
    {
        $loan = $this->createLoan();

        $this->post(route('prestamos.revisiones.store', $loan), [
            'fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1, 'tasa_spread' => 2,
        ])->assertRedirect(route('prestamos.show', $loan))->assertSessionHas('status');

        $this->get(route('prestamos.show', $loan))->assertOk()->assertSee('3.00%');

        $revision = $loan->revisiones()->firstOrFail();
        $this->post(route('revisiones.anular', $revision))->assertRedirect(route('prestamos.show', $loan));
        $this->assertNotNull($revision->fresh()->anulado_at);

        $this->post(route('prestamos.revisiones.store', $loan), ['fecha_efectiva' => '2026-07-01'])->assertSessionHasErrors('tasa_interes');
    }

    public function test_terms_are_locked_once_the_loan_has_receipts(): void
    {
        $loan = $this->createLoan(['cuota_calculada' => 73290.81]);
        $this->post(route('prestamos.recibos.store', $loan), [
            'tipo' => TipoRecibo::CuotaCompleta->value, 'fecha_pago' => '2026-04-01', 'numero_cuota' => 1,
            'monto_prestamo' => 1, 'monto_principal' => 53290.81, 'monto_interes' => 20000,
        ]);

        $this->get(route('prestamos.edit', $loan))->assertOk()->assertSee('solo puede cambiar el número');

        $this->put(route('prestamos.update', $loan), $this->loanPayload(['monto_prestamo' => 1000, 'numero_prestamo' => '9091', 'observacion' => 'Nota']))
            ->assertRedirect(route('prestamos.show', $loan));

        $loan->refresh();
        $this->assertSame('9091', $loan->numero_prestamo);
        $this->assertSame('Nota', $loan->observacion);
        $this->assertSame(6000000.0, $loan->monto_prestamo);
    }

    public function test_terms_can_change_while_the_loan_has_no_activity(): void
    {
        $loan = $this->createLoan();

        $this->put(route('prestamos.update', $loan), $this->loanPayload(['plazo_meses' => 24]))->assertRedirect();

        $this->assertSame('2028-03-01', $loan->fresh()->vencimiento->toDateString());
    }

    public function test_payoff_quote_endpoint_returns_balance_and_accrued_interest(): void
    {
        $loan = $this->createLoan();

        $this->getJson(route('prestamos.cotizacion', ['prestamo' => $loan, 'fecha' => '2026-03-16']))
            ->assertOk()
            ->assertJson(['principal' => 6000000, 'interest' => 10000]);

        $this->getJson(route('prestamos.cotizacion', $loan))->assertStatus(422);
    }

    public function test_create_form_is_grouped_in_sections_and_shows_the_line_panel(): void
    {
        $this->get(route('prestamos.create'))
            ->assertOk()
            ->assertSee('Identificación')
            ->assertSee('Condiciones financieras')
            ->assertSee('Tasa y cuota')
            ->assertSee('Línea seleccionada')
            ->assertSee('BCT DOLARES')
            ->assertSee('Guardar');
    }

    public function test_create_form_guides_the_user_to_create_a_line_first(): void
    {
        $this->line->update(['estado' => LineaPrestamo::STATUS_INACTIVE]);

        $this->get(route('prestamos.create'))
            ->assertOk()
            ->assertSee('Primero necesita una línea de crédito')
            ->assertSee(route('lineas.create'), false)
            ->assertDontSee('name="numero_prestamo"', false);
    }

    public function test_line_panel_reports_the_available_credit_of_each_line(): void
    {
        $this->createLoan(['monto_prestamo' => 6000000]);

        $response = $this->get(route('prestamos.create'))->assertOk();

        $this->assertStringContainsString('"available":59000000', $response->getContent());
    }

    public function test_edit_form_renders_with_the_current_values(): void
    {
        $loan = $this->createLoan();

        $this->get(route('prestamos.edit', $loan))
            ->assertOk()
            ->assertSee('Editar sub préstamo')
            ->assertSee('value="9090"', false);
    }

    public function test_credit_line_form_is_grouped_in_sections(): void
    {
        $this->get(route('lineas.create'))
            ->assertOk()
            ->assertSee('Datos generales')
            ->assertSee('Montos y plazos')
            ->assertSee('Condiciones de pago')
            ->assertSee('Tasas');
    }

    public function test_loans_can_be_searched_by_number_bank_or_line(): void
    {
        $bank = Banco::factory()->create(['nombre_banco' => 'BNCR']);
        $other = LineaPrestamo::factory()->create(['nombre' => 'OTRA LINEA', 'banco_id' => $bank->id]);
        $this->createLoan(['numero_prestamo' => '9090']);
        Prestamo::factory()->create(['id_lineas_prestamos' => $other->id, 'numero_prestamo' => '5555']);

        $this->get(route('prestamos.index', ['q' => '9090']))->assertOk()->assertSee('9090')->assertDontSee('5555');
        $this->get(route('prestamos.index', ['q' => 'bncr']))->assertOk()->assertSee('5555')->assertDontSee('9090');
        $this->get(route('prestamos.index', ['q' => 'BCT']))->assertOk()->assertSee('9090')->assertDontSee('5555');
        $this->get(route('prestamos.index', ['q' => 'nada']))->assertOk()->assertSee('Ningún sub préstamo coincide');
    }

    public function test_loans_with_receipts_revisions_or_legacy_installments_cannot_be_deleted(): void
    {
        $withReceipt = $this->createLoan(['numero_prestamo' => '1001', 'cuota_calculada' => 73290.81]);
        $this->post(route('prestamos.recibos.store', $withReceipt), [
            'tipo' => TipoRecibo::CuotaCompleta->value, 'fecha_pago' => '2026-04-01', 'numero_cuota' => 1,
            'monto_principal' => 53290.81, 'monto_interes' => 20000,
        ]);
        $withRevision = $this->createLoan(['numero_prestamo' => '1002']);
        $this->post(route('prestamos.revisiones.store', $withRevision), ['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1]);

        Schema::create('planpagos', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('prestamo_id');
        });
        $withLegacy = $this->createLoan(['numero_prestamo' => '1003']);
        DB::table('planpagos')->insert(['prestamo_id' => $withLegacy->id]);

        $this->delete(route('prestamos.destroy', $withReceipt))->assertSessionHasErrors(['delete' => 'No se puede eliminar el sub préstamo 1001: tiene 1 recibo(s) registrado(s).']);
        $this->delete(route('prestamos.destroy', $withRevision))->assertSessionHasErrors(['delete' => 'No se puede eliminar el sub préstamo 1002: tiene 1 revisión(es) de tasa o plazo.']);
        $this->delete(route('prestamos.destroy', $withLegacy))->assertSessionHasErrors(['delete' => 'No se puede eliminar el sub préstamo 1003: tiene 1 cuota(s) en la tabla histórica planpagos.']);
        $this->assertSame(3, Prestamo::count());

        $this->get(route('prestamos.index'))->assertOk()->assertSee('En uso');
        $this->get(route('prestamos.show', $withReceipt))->assertOk()->assertSee('disabled', false)->assertDontSee('data-confirm="¿Eliminar el sub préstamo', false);
    }

    public function test_a_loan_without_activity_is_deleted_after_confirmation(): void
    {
        $loan = $this->createLoan();

        $this->get(route('prestamos.show', $loan))->assertOk()->assertSee('data-confirm="¿Eliminar el sub préstamo 9090?', false);
        $this->get(route('prestamos.index'))->assertOk()->assertSee('data-confirm="¿Eliminar «Sub Préstamo 9090»?', false);

        $this->delete(route('prestamos.destroy', $loan))
            ->assertRedirect(route('prestamos.index'))
            ->assertSessionHas('status', 'Sub Préstamo 9090 eliminado correctamente.');
        $this->assertSame(0, Prestamo::count());
    }

    public function test_detail_page_is_organised_in_tabs_and_voiding_asks_for_confirmation(): void
    {
        $loan = $this->createLoan(['cuota_calculada' => 73290.81]);
        $this->post(route('prestamos.recibos.store', $loan), [
            'tipo' => TipoRecibo::CuotaCompleta->value, 'fecha_pago' => '2026-04-01', 'numero_cuota' => 1,
            'monto_principal' => 53290.81, 'monto_interes' => 20000,
        ]);

        $this->get(route('prestamos.show', $loan))
            ->assertOk()
            ->assertSee('data-tab="plan"', false)
            ->assertSee('data-tab="receipt"', false)
            ->assertSee('data-tab="revision"', false)
            ->assertSee('Recibos (1)')
            ->assertSee('data-confirm="¿Anular este recibo?', false);
    }

    public function test_the_receipt_tab_opens_when_the_receipt_form_has_errors(): void
    {
        $loan = $this->createLoan();

        $this->from(route('prestamos.show', $loan))
            ->post(route('prestamos.recibos.store', $loan), ['tipo' => 'CUOTA_COMPLETA', 'fecha_pago' => '2026-04-01', 'numero_cuota' => 9, 'monto_principal' => 1, 'monto_interes' => 1]);

        $response = $this->followingRedirects()->get(route('prestamos.show', $loan));

        $this->assertMatchesRegularExpression('/data-panel="receipt"[^>]*>/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-panel="receipt"[^>]*hidden/', $response->getContent());
    }

    public function test_sidebar_shows_upcoming_options_as_disabled_items_marked_pronto(): void
    {
        $content = $this->get(route('dashboard'))->assertOk()->getContent();

        foreach (['Anulaciones', 'Reportes', 'Estado de cuenta', 'Alertas de vencimiento', 'Roles y permisos', 'Auditoría'] as $label) {
            $this->assertMatchesRegularExpression('/class="nav-link is-disabled"[^>]*>\s*<svg.*?<\/svg>\s*'.preg_quote($label, '/').'\s*<span class="nav-badge">Pronto<\/span>/s', $content, "{$label} should be a disabled menu item.");
        }

        $this->assertSame(6, substr_count($content, '<span class="nav-badge">Pronto</span>'));
        $this->assertStringNotContainsString('href="#"', $content);
    }

    public function test_sidebar_links_every_catalog(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('empresas.index'), false)
            ->assertSee(route('bancos.index'), false)
            ->assertSee(route('lineas.index'), false)
            ->assertSee(route('tipos-tasa.index'), false)
            ->assertSee(route('tipos-pago.index'), false)
            ->assertSee(route('prestamos.index'), false);
    }
}
