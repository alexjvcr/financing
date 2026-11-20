<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Empresa;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\TipoTasa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalculationConditionsScreensTest extends TestCase
{
    use RefreshDatabase;

    protected LineaPrestamo $line;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->actingAs(User::factory()->create());

        $this->line = LineaPrestamo::factory()->create(['nombre' => 'BCT DOLARES', 'monto_maximo' => 65000000, 'calendario' => '360/360']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function linePayload(array $overrides = []): array
    {
        return $overrides + [
            'nombre' => 'NUEVA LINEA',
            'empresa_id' => Empresa::factory()->create()->id,
            'banco_id' => Banco::factory()->create()->id,
            'tipo_linea' => 'CAPITAL',
            'moneda' => 'DOLARES',
            'monto_maximo' => 10000000,
            'capital_trabajo' => 0,
            'plazo_maximo_meses' => 96,
            'fecha_vencmiento' => '2032-03-07',
            'modalidad_pago' => 'MENSUAL',
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

    public function test_the_credit_line_form_offers_every_calculation_condition(): void
    {
        $this->get(route('lineas.create'))
            ->assertOk()
            ->assertSee('Tipo de cálculo de cuota')
            ->assertSee('Cuota nivelada (constante)')
            ->assertSee('Amortización lineal (decreciente)')
            ->assertSee('Solo intereses (bullet)')
            ->assertSee('Cuota creciente (graduada)')
            ->assertSee('Base de días (calendario)')
            ->assertSee('Actual/360')
            ->assertSee('Actual/365')
            ->assertSee('30/360')
            ->assertSee('Tipo de cálculo de interés')
            ->assertSee('Tasa efectiva anual (capitalizable)')
            ->assertSee('Periodicidad de pago')
            ->assertSee('Trimestral')
            ->assertSee('Período de gracia')
            ->assertSee('Cuota global (balloon)')
            ->assertSee('Crecimiento de la cuota');
    }

    public function test_the_credit_line_stores_grace_balloon_growth_and_the_chosen_calendar(): void
    {
        $this->post(route('lineas.store'), $this->linePayload([
            'modalidad_cuota' => 'CRECIENTE', 'calendario' => '365/365', 'tipo_cuota' => 'EFECTIVA', 'modalidad_pago' => 'TRIMESTRAL',
            'meses_gracia' => 6, 'porcentaje_global' => 15.5, 'variacion_porcentual' => 1.25,
        ]))->assertSessionHasNoErrors();

        $line = LineaPrestamo::where('nombre', 'NUEVA LINEA')->firstOrFail();
        $this->assertSame('CRECIENTE', $line->modalidad_cuota);
        $this->assertSame('365/365', $line->calendario);
        $this->assertSame('EFECTIVA', $line->tipo_cuota);
        $this->assertSame(6, $line->meses_gracia);
        $this->assertSame(15.5, $line->porcentaje_global);
        $this->assertSame(1.25, $line->variacion_porcentual);
        $this->assertSame(4, $line->periodicidad_pago);
    }

    public function test_grace_and_balloon_default_to_zero_and_are_validated(): void
    {
        $this->post(route('lineas.store'), $this->linePayload())->assertSessionHasNoErrors();
        $line = LineaPrestamo::where('nombre', 'NUEVA LINEA')->firstOrFail();
        $this->assertSame(0, $line->meses_gracia);
        $this->assertSame(0.0, $line->porcentaje_global);

        $this->post(route('lineas.store'), $this->linePayload(['nombre' => 'OTRA', 'meses_gracia' => -1, 'porcentaje_global' => 150, 'calendario' => 'XX']))
            ->assertSessionHasErrors(['meses_gracia', 'porcentaje_global', 'calendario']);
    }

    public function test_the_sub_prestamo_form_has_the_optional_calculation_tab_and_shows_the_line_conditions(): void
    {
        $response = $this->get(route('prestamos.create'))
            ->assertOk()
            ->assertSee('Condiciones de cálculo')
            ->assertSee('Heredar de la línea')
            ->assertSee('name="calendario"', false)
            ->assertSee('name="modalidad_cuota"', false)
            ->assertSee('name="meses_gracia"', false)
            ->assertSee('Cálculo de la línea');

        $this->assertStringContainsString('"conditions":"Cuota nivelada (constante)', $response->getContent());
    }

    public function test_a_sub_prestamo_can_override_the_calendar_of_its_line_and_the_cuota_follows_it(): void
    {
        $this->post(route('prestamos.store'), $this->loanPayload(['calendario' => '365/360']))->assertSessionHasNoErrors();

        $loan = Prestamo::firstOrFail();
        $this->assertSame('365/360', $loan->calendario);
        $this->assertNull($loan->modalidad_cuota);
        $this->assertNull($loan->meses_gracia);
        // Con Actual/360 la cuota nivelada de 6,000,000 al 4 % a 96 meses es la 73,290.81 del banco.
        $this->assertEqualsWithDelta(73290.81, $loan->cuota_calculada, 0.01);
    }

    public function test_without_overrides_the_sub_prestamo_inherits_the_line_and_stores_nothing(): void
    {
        $this->post(route('prestamos.store'), $this->loanPayload([
            'calendario' => '', 'modalidad_cuota' => '', 'tipo_cuota' => '', 'modalidad_pago' => '', 'forma_pago' => '',
            'meses_gracia' => '', 'porcentaje_global' => '', 'variacion_porcentual' => '',
        ]))->assertSessionHasNoErrors();

        $loan = Prestamo::firstOrFail();
        foreach (['calendario', 'modalidad_cuota', 'tipo_cuota', 'modalidad_pago', 'forma_pago', 'meses_gracia', 'porcentaje_global', 'variacion_porcentual'] as $column) {
            $this->assertNull($loan->{$column}, "{$column} should be inherited");
        }
        $this->assertEqualsWithDelta(73135.65, $loan->cuota_calculada, 0.01);
    }

    public function test_a_sub_prestamo_can_override_the_installment_type_and_the_grace_period(): void
    {
        $this->post(route('prestamos.store'), $this->loanPayload([
            'monto_prestamo' => 1200000, 'plazo_meses' => 12, 'modalidad_cuota' => 'DECRECIENTE', 'meses_gracia' => 3,
        ]))->assertSessionHasNoErrors();

        $loan = Prestamo::firstOrFail();

        $this->assertSame('DECRECIENTE', $loan->modalidad_cuota);
        $this->assertSame(3, $loan->meses_gracia);
        // Durante la gracia la primera cuota es solo de intereses: 1,200,000 × 4 % / 12.
        $this->assertEqualsWithDelta(4000.0, $loan->cuota_calculada, 0.01);
        $this->assertSame('2026-04-01', $loan->proximo_pago->toDateString());
    }

    public function test_invalid_overrides_are_rejected(): void
    {
        $this->post(route('prestamos.store'), $this->loanPayload([
            'calendario' => 'XX', 'modalidad_cuota' => 'ESPIRAL', 'tipo_cuota' => 'RARA', 'modalidad_pago' => 'DIARIA',
            'meses_gracia' => -1, 'porcentaje_global' => 101, 'variacion_porcentual' => -2,
        ]))->assertSessionHasErrors(['calendario', 'modalidad_cuota', 'tipo_cuota', 'modalidad_pago', 'meses_gracia', 'porcentaje_global', 'variacion_porcentual']);

        $this->assertSame(0, Prestamo::count());
    }

    public function test_the_detail_describes_the_calculation_conditions_in_force(): void
    {
        $this->post(route('prestamos.store'), $this->loanPayload(['calendario' => '365/360']));
        $loan = Prestamo::firstOrFail();

        $this->get(route('prestamos.show', $loan))
            ->assertOk()
            ->assertSee('Cálculo:')
            ->assertSee('Cuota nivelada (constante)')
            ->assertSee('365/360')
            ->assertSee('pago mensual');
    }

    public function test_the_edit_form_shows_the_saved_override_selected_and_locks_it_with_activity(): void
    {
        $this->post(route('prestamos.store'), $this->loanPayload(['calendario' => '365/360']));
        $loan = Prestamo::firstOrFail();

        $this->get(route('prestamos.edit', $loan))
            ->assertOk()
            ->assertSeeInOrder(['name="calendario"', 'value="365/360"', 'selected']);

        $this->post(route('prestamos.revisiones.store', $loan), ['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1]);

        $this->put(route('prestamos.update', $loan), $this->loanPayload(['calendario' => '360/360', 'numero_prestamo' => '9091']))->assertRedirect();

        $loan->refresh();
        $this->assertSame('9091', $loan->numero_prestamo);
        $this->assertSame('365/360', $loan->calendario);
    }
}
