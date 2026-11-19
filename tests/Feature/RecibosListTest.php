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
use Tests\TestCase;

class RecibosListTest extends TestCase
{
    use RefreshDatabase;

    protected Prestamo $bct;

    protected Prestamo $bncr;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->actingAs(User::factory()->create());

        $bctLine = LineaPrestamo::factory()->create(['nombre' => 'BCT DOLARES', 'banco_id' => Banco::factory()->create(['nombre_banco' => 'BCT'])->id]);
        $bncrLine = LineaPrestamo::factory()->create(['nombre' => 'BNCR DOLARES', 'banco_id' => Banco::factory()->create(['nombre_banco' => 'BNCR'])->id]);

        $this->bct = Prestamo::factory()->create(['id_lineas_prestamos' => $bctLine->id, 'numero_prestamo' => '9090']);
        $this->bncr = Prestamo::factory()->create(['id_lineas_prestamos' => $bncrLine->id, 'numero_prestamo' => '5555']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function receipt(Prestamo $loan, array $attributes = []): Recibo
    {
        return Recibo::factory()->create($attributes + ['prestamo_id' => $loan->id]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        auth()->logout();

        $this->get(route('recibos.index'))->assertRedirect(route('login'));
    }

    public function test_the_menu_item_is_enabled_and_links_to_the_receipts_list(): void
    {
        $content = $this->get(route('recibos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('recibos.index').'"', $content);
        $this->assertDoesNotMatchRegularExpression('/is-disabled[^>]*>\s*<svg.*?<\/svg>\s*Recibos\s*<span class="nav-badge">/s', $content);
        $this->assertMatchesRegularExpression('/class="nav-link is-active">\s*<svg.*?<\/svg>\s*Recibos/s', $content);
    }

    public function test_it_lists_receipts_of_every_sub_prestamo_newest_first(): void
    {
        $this->receipt($this->bct, ['fecha_pago' => '2026-04-01', 'usuario' => 'jperez']);
        $this->receipt($this->bncr, ['fecha_pago' => '2026-05-01', 'tipo' => TipoRecibo::SoloPrincipal, 'numero_cuota' => null, 'monto_principal' => 1000, 'monto_interes' => 0]);

        $response = $this->get(route('recibos.index'))
            ->assertOk()
            ->assertSee('Recibos')
            ->assertSee('9090')
            ->assertSee('5555')
            ->assertSee('jperez')
            ->assertSee('3. Solo pago a principal')
            ->assertSee('01/04/2026')
            ->assertSee('01/05/2026');

        $this->assertLessThan(strpos($response->getContent(), '9090</strong>'), strpos($response->getContent(), '5555</strong>'));
    }

    public function test_voided_receipts_are_hidden_by_default_and_can_be_shown(): void
    {
        $this->receipt($this->bct, ['usuario' => 'activo_user']);
        $this->receipt($this->bncr, ['usuario' => 'anulado_user', 'estado' => Recibo::STATUS_VOIDED, 'anulado_at' => now(), 'motivo_anulacion' => 'Error']);

        $this->get(route('recibos.index'))->assertSee('activo_user')->assertDontSee('anulado_user');
        $this->get(route('recibos.index', ['estado' => 'ANULADO']))->assertSee('anulado_user')->assertDontSee('activo_user');
        $this->get(route('recibos.index', ['estado' => 'TODOS']))->assertSee('activo_user')->assertSee('anulado_user');
        $this->get(route('recibos.index', ['estado' => 'basura']))->assertSee('activo_user')->assertDontSee('anulado_user');
    }

    public function test_it_filters_by_type_and_by_a_date_range_typed_in_the_configured_format(): void
    {
        $this->receipt($this->bct, ['fecha_pago' => '2026-04-01', 'usuario' => 'cuota_abril']);
        $this->receipt($this->bct, ['fecha_pago' => '2026-06-01', 'usuario' => 'cuota_junio']);
        $this->receipt($this->bncr, ['fecha_pago' => '2026-06-15', 'usuario' => 'abono_junio', 'tipo' => TipoRecibo::SoloPrincipal, 'numero_cuota' => null]);

        $this->get(route('recibos.index', ['tipo' => 'SOLO_PRINCIPAL']))->assertSee('abono_junio')->assertDontSee('cuota_abril');

        $this->get(route('recibos.index', ['_dates' => ['desde', 'hasta'], 'desde' => '01/05/2026', 'hasta' => '30/06/2026']))
            ->assertOk()
            ->assertSee('cuota_junio')
            ->assertSee('abono_junio')
            ->assertDontSee('cuota_abril')
            ->assertSee('value="01/05/2026"', false)
            ->assertSee('value="30/06/2026"', false);

        $this->get(route('recibos.index', ['desde' => '2026-06-10']))->assertSee('abono_junio')->assertDontSee('cuota_junio');
        $this->get(route('recibos.index', ['desde' => 'no-es-fecha']))->assertOk()->assertSee('cuota_abril');
    }

    public function test_it_searches_by_sub_prestamo_bank_line_or_user(): void
    {
        $this->receipt($this->bct, ['usuario' => 'user_uno']);
        $this->receipt($this->bncr, ['usuario' => 'user_dos', 'observaciones' => 'pago especial']);

        $this->get(route('recibos.index', ['q' => '9090']))->assertSee('user_uno')->assertDontSee('user_dos');
        $this->get(route('recibos.index', ['q' => 'bncr']))->assertSee('user_dos')->assertDontSee('user_uno');
        $this->get(route('recibos.index', ['q' => 'dos']))->assertSee('user_dos')->assertDontSee('user_uno');
        $this->get(route('recibos.index', ['q' => 'especial']))->assertSee('user_dos')->assertDontSee('user_uno');
        $this->get(route('recibos.index', ['q' => 'zzz']))->assertOk()->assertSee('No hay recibos con estos filtros.');
    }

    public function test_totals_add_up_the_active_receipts_of_the_search(): void
    {
        $this->receipt($this->bct, ['monto_principal' => 1000, 'monto_interes' => 200, 'monto_otros' => 30]);
        $this->receipt($this->bncr, ['monto_principal' => 2000, 'monto_interes' => 400, 'monto_otros' => 0]);
        $this->receipt($this->bncr, ['monto_principal' => 99999, 'monto_interes' => 99999, 'estado' => Recibo::STATUS_VOIDED, 'anulado_at' => now()]);

        $this->get(route('recibos.index', ['estado' => 'TODOS']))
            ->assertOk()
            ->assertSee('$3,000.00')
            ->assertSee('$600.00')
            ->assertSee('$3,630.00')
            ->assertSee('Incluye otros rubros $30.00');

        $this->get(route('recibos.index', ['q' => '9090']))->assertSee('$1,230.00');
    }

    public function test_the_list_is_paginated_and_keeps_the_filters(): void
    {
        Recibo::factory()->count(20)->create(['prestamo_id' => $this->bct->id]);

        $this->get(route('recibos.index'))->assertOk()->assertSee('Mostrando 1–15 de 20');
        $this->get(route('recibos.index', ['page' => 2, 'tipo' => 'CUOTA_COMPLETA']))->assertOk()->assertSee('Mostrando 16–20 de 20');
        $this->get(route('recibos.index', ['tipo' => 'CUOTA_COMPLETA']))->assertSee('tipo=CUOTA_COMPLETA', false);
    }

    public function test_pagination_links_do_not_carry_the_date_marker(): void
    {
        Recibo::factory()->count(20)->create(['prestamo_id' => $this->bct->id, 'fecha_pago' => '2026-06-01']);

        $content = $this->get(route('recibos.index', ['_dates' => ['desde'], 'desde' => '01/05/2026']))->assertOk()->getContent();

        $this->assertStringNotContainsString('_dates', preg_replace('/<input type="hidden" name="_dates\[\]"[^>]*>/', '', $content));
        $this->assertStringContainsString('desde=2026-05-01', $content);
    }
}
