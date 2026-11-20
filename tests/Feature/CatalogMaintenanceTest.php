<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Empresa;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\TipoTasa;
use App\Models\TipoTasaHistorial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['email' => 'jperez@corp.obgroup.com']));
    }

    public function test_guests_are_sent_to_login_from_every_catalog(): void
    {
        auth()->logout();

        foreach (['empresas', 'bancos', 'tipos-tasa', 'lineas', 'prestamos'] as $route) {
            $this->get(route($route.'.index'))->assertRedirect(route('login'));
        }
    }

    public function test_company_crud(): void
    {
        $this->post(route('empresas.store'), ['nombre_empresa' => 'ATI'])->assertRedirect(route('empresas.index'));
        $company = Empresa::firstOrFail();

        $this->get(route('empresas.index'))->assertOk()->assertSee('ATI');

        $this->put(route('empresas.update', $company->id), ['nombre_empresa' => 'ATI S.A.'])->assertRedirect(route('empresas.index'));
        $this->assertSame('ATI S.A.', $company->fresh()->nombre_empresa);

        $this->delete(route('empresas.destroy', $company->id))->assertRedirect(route('empresas.index'));
        $this->assertSame(0, Empresa::count());
    }

    public function test_company_name_is_required(): void
    {
        $this->post(route('empresas.store'), ['nombre_empresa' => ''])->assertSessionHasErrors('nombre_empresa');
    }

    public function test_records_in_use_cannot_be_deleted(): void
    {
        $line = LineaPrestamo::factory()->for(Banco::factory()->create(), 'banco')->create();

        $this->delete(route('empresas.destroy', $line->empresa_id))->assertSessionHasErrors('delete');
        $this->delete(route('bancos.destroy', $line->banco_id))->assertSessionHasErrors('delete');
        $this->delete(route('tipos-tasa.destroy', $line->tipotasa_id))->assertSessionHasErrors('delete');

        $this->assertSame(1, Empresa::count());
        $this->assertSame(1, Banco::count());
        $this->assertSame(1, TipoTasa::count());
    }

    public function test_bank_crud(): void
    {
        $this->post(route('bancos.store'), ['nombre_banco' => 'BCT'])->assertRedirect(route('bancos.index'));
        $bank = Banco::firstOrFail();

        $this->put(route('bancos.update', $bank->id), ['nombre_banco' => 'BCT Dólares'])->assertRedirect(route('bancos.index'));
        $this->assertSame('BCT Dólares', $bank->fresh()->nombre_banco);

        $this->delete(route('bancos.destroy', $bank->id))->assertRedirect(route('bancos.index'));
        $this->assertSame(0, Banco::count());
    }

    public function test_rate_type_changes_are_logged_in_its_history(): void
    {
        $this->post(route('tipos-tasa.store'), ['nombre_tipo_tasa' => 'PRIME', 'base' => 5.5])->assertRedirect();
        $type = TipoTasa::firstOrFail();
        $this->assertSame(1, $type->historial()->count());

        $this->put(route('tipos-tasa.update', $type->id), ['nombre_tipo_tasa' => 'PRIME', 'base' => 5.5]);
        $this->assertSame(1, $type->historial()->count());

        $this->put(route('tipos-tasa.update', $type->id), ['nombre_tipo_tasa' => 'PRIME', 'base' => 6.25]);

        $latest = $type->historial()->first();
        $this->assertSame(5.5, $latest->base_anterior);
        $this->assertSame(6.25, $latest->base_nueva);
        $this->assertSame('jperez@corp.obgroup.com', $latest->usuario);

        $this->get(route('tipos-tasa.edit', $type->id))->assertOk()->assertSee('Historial de cambios');
    }

    public function test_credit_line_is_created_with_the_periodicity_of_its_payment_mode(): void
    {
        $payload = [
            'nombre' => 'BCT DOLARES',
            'empresa_id' => Empresa::factory()->create()->id,
            'banco_id' => Banco::factory()->create()->id,
            'tipo_linea' => 'CAPITAL',
            'moneda' => 'DOLARES',
            'monto_maximo' => 65000000,
            'capital_trabajo' => 0,
            'plazo_maximo_meses' => 96,
            'fecha_vencmiento' => '2032-03-07',
            'modalidad_pago' => 'TRIMESTRAL',
            'modalidad_cuota' => 'CONSTANTE',
            'forma_pago' => 'VENCIMIENTO',
            'calendario' => '365/360',
            'tipo_cuota' => 'NOMINAL',
            'tipo_interes' => 'BASE_SPREAD',
            'tipotasa_id' => TipoTasa::factory()->create()->id,
            'tasa_interes' => 2,
            'tasa_spread' => 2,
            'variacion_porcentual' => 0,
            'estado' => 'ACTIVO',
        ];

        $this->post(route('lineas.store'), $payload)->assertRedirect(route('lineas.index'));

        $line = LineaPrestamo::firstOrFail();
        $this->assertSame(4, $line->periodicidad_pago);
        $this->assertSame(3, $line->monthsPerPeriod());

        $this->get(route('lineas.index'))->assertOk()->assertSee('BCT DOLARES');
        $this->get(route('lineas.edit', $line->id))->assertOk()->assertSee('TRIMESTRAL');
        $this->get(route('lineas.create'))->assertOk();
    }

    public function test_each_catalog_can_be_searched(): void
    {
        Empresa::factory()->create(['nombre_empresa' => 'ATI']);
        Empresa::factory()->create(['nombre_empresa' => 'Holding BCT']);
        Banco::factory()->create(['nombre_banco' => 'BNCR']);
        Banco::factory()->create(['nombre_banco' => 'Davivienda']);
        TipoTasa::factory()->create(['nombre_tipo_tasa' => 'PRIME']);
        TipoTasa::factory()->create(['nombre_tipo_tasa' => 'TRI 96']);

        $this->get(route('empresas.index', ['q' => 'bct']))->assertOk()->assertSee('Holding BCT')->assertDontSee('ATI');
        $this->get(route('bancos.index', ['q' => 'davi']))->assertOk()->assertSee('Davivienda')->assertDontSee('BNCR');
        $this->get(route('tipos-tasa.index', ['q' => 'tri']))->assertOk()->assertSee('TRI 96')->assertDontSee('PRIME');
    }

    public function test_credit_lines_can_be_searched_by_their_bank_or_company(): void
    {
        $bank = Banco::factory()->create(['nombre_banco' => 'BNCR']);
        LineaPrestamo::factory()->create(['nombre' => 'LINEA UNO', 'banco_id' => $bank->id]);
        LineaPrestamo::factory()->create(['nombre' => 'LINEA DOS']);

        $this->get(route('lineas.index', ['q' => 'BNCR']))->assertOk()->assertSee('LINEA UNO')->assertDontSee('LINEA DOS');
        $this->get(route('lineas.index', ['q' => 'dos']))->assertOk()->assertSee('LINEA DOS')->assertDontSee('LINEA UNO');
    }

    public function test_search_without_matches_says_so(): void
    {
        Empresa::factory()->create(['nombre_empresa' => 'ATI']);

        $this->get(route('empresas.index', ['q' => 'zzz']))->assertOk()->assertSee('Ningún registro coincide con «zzz»');
    }

    public function test_lists_are_paginated_and_keep_the_search_term(): void
    {
        Empresa::factory()->count(20)->create();

        $this->get(route('empresas.index'))->assertOk()->assertSee('Mostrando 1–15 de 20')->assertSee('Siguiente');
        $this->get(route('empresas.index', ['page' => 2]))->assertOk()->assertSee('Mostrando 16–20 de 20');
    }

    public function test_delete_buttons_ask_for_confirmation_and_are_locked_for_records_in_use(): void
    {
        $used = Empresa::factory()->create(['nombre_empresa' => 'Con lineas']);
        LineaPrestamo::factory()->count(2)->create(['empresa_id' => $used->id]);
        Empresa::factory()->create(['nombre_empresa' => 'Sin uso']);

        $response = $this->get(route('empresas.index'))->assertOk();

        $response->assertSee('data-confirm="¿Eliminar «Sin uso»? Esta acción no se puede deshacer."', false)
            ->assertDontSee('data-confirm="¿Eliminar «Con lineas»', false)
            ->assertSee('En uso')
            ->assertSee('tiene 2 línea(s) de crédito asociada(s).');
    }

    public function test_deleting_a_record_in_use_explains_which_dependencies_block_it(): void
    {
        $company = Empresa::factory()->create(['nombre_empresa' => 'ATI']);
        LineaPrestamo::factory()->count(2)->create(['empresa_id' => $company->id]);

        $this->from(route('empresas.index'))
            ->delete(route('empresas.destroy', $company->id))
            ->assertRedirect(route('empresas.index'))
            ->assertSessionHasErrors(['delete' => 'No se puede eliminar «ATI»: tiene 2 línea(s) de crédito asociada(s).']);

        $this->assertSame(1, Empresa::where('nombre_empresa', 'ATI')->count());
    }

    public function test_a_credit_line_with_loans_cannot_be_deleted(): void
    {
        $line = LineaPrestamo::factory()->create(['nombre' => 'BCT DOLARES']);
        Prestamo::factory()->create(['id_lineas_prestamos' => $line->id]);

        $this->delete(route('lineas.destroy', $line->id))
            ->assertSessionHasErrors(['delete' => 'No se puede eliminar «BCT DOLARES»: tiene 1 sub préstamo(s) asociado(s).']);
        $this->assertSame(1, LineaPrestamo::count());
    }

    public function test_deleting_a_rate_type_removes_its_change_history(): void
    {
        $this->post(route('tipos-tasa.store'), ['nombre_tipo_tasa' => 'PRIME', 'base' => 5.5]);
        $type = TipoTasa::firstOrFail();
        $this->put(route('tipos-tasa.update', $type->id), ['nombre_tipo_tasa' => 'PRIME', 'base' => 6]);
        $this->assertSame(2, TipoTasaHistorial::count());

        $this->delete(route('tipos-tasa.destroy', $type->id))
            ->assertRedirect(route('tipos-tasa.index'))
            ->assertSessionHas('status', 'Tipo de tasa «PRIME» eliminado correctamente.');

        $this->assertSame(0, TipoTasa::count());
        $this->assertSame(0, TipoTasaHistorial::count());
    }

    public function test_credit_line_rejects_unsupported_values(): void
    {
        $this->post(route('lineas.store'), ['modalidad_pago' => 'DIARIA', 'modalidad_cuota' => 'ESPIRAL'])
            ->assertSessionHasErrors(['modalidad_pago', 'modalidad_cuota', 'nombre', 'empresa_id']);
    }
}
