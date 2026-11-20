<?php

namespace Tests\Feature\Lending;

use App\Enums\TipoRecibo;
use App\Models\DetallePago;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\TipoPago;
use App\Models\User;
use App\Services\Lending\ReceiptRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TipoPagoAndDetailTest extends TestCase
{
    use RefreshDatabase;

    protected LineaPrestamo $line;

    protected Prestamo $loan;

    protected ReceiptRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');
        $this->actingAs(User::factory()->create(['email' => 'jperez@corp.obgroup.com']));

        $this->line = LineaPrestamo::factory()->create(['nombre' => 'BCT DOLARES', 'monto_maximo' => 65000000]);
        $this->loan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $this->line->id,
            'numero_prestamo' => '9090',
            'cuota_calculada' => 73290.81,
        ]);
        $this->registrar = app(ReceiptRegistrar::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function installment(array $overrides = []): array
    {
        return $overrides + [
            'tipo' => TipoRecibo::CuotaCompleta,
            'fecha_pago' => '2026-04-01',
            'numero_cuota' => 1,
            'monto_principal' => 53290.81,
            'monto_interes' => 20000,
        ];
    }

    /**
     * @return array<string, float>
     */
    protected function lineAmounts(Recibo $receipt): array
    {
        return $receipt->detalles()->with('tipoPago')->get()->mapWithKeys(fn (DetallePago $line): array => [$line->tipoPago->codigo => $line->monto])->all();
    }

    public function test_the_six_system_payment_types_are_seeded_by_the_migration(): void
    {
        $types = TipoPago::orderBy('id')->get()->keyBy('codigo');

        $this->assertSame(['PP', 'PI', 'CT', 'PA', 'PC', 'OT'], $types->keys()->all());
        $this->assertSame('Pago principal', $types['PP']->nombre);
        $this->assertSame('Pago interés', $types['PI']->nombre);
        $this->assertSame('Cancelación total', $types['CT']->nombre);
        $this->assertSame('Pago anticipado', $types['PA']->nombre);
        $this->assertSame('Pago parcial', $types['PC']->nombre);
        $this->assertSame('Otros rubros', $types['OT']->nombre);
        $this->assertTrue($types->every(fn (TipoPago $type): bool => $type->sistema));
        $this->assertSame(['PP', 'CT', 'PA'], $types->filter->afecta_saldo->keys()->all());
    }

    public function test_a_full_installment_is_detailed_as_principal_and_interest_with_the_plan_cuota(): void
    {
        $receipt = $this->registrar->register($this->loan, $this->installment(), 'jperez');

        $this->assertSame(['PP' => 53290.81, 'PI' => 20000.0], $this->lineAmounts($receipt));

        $line = DetallePago::where('recibo_id', $receipt->id)->firstOrFail();
        $this->assertSame($this->loan->id, $line->prestamo_id);
        $this->assertSame(1, $line->numero_cuota);
        $this->assertSame('2026-04-01', $line->fecha_cuota->toDateString());
        $this->assertSame('2026-04-01', $line->fecha_pago->toDateString());
    }

    public function test_an_early_payment_keeps_the_payment_date_and_the_cuota_date_apart(): void
    {
        $receipt = $this->registrar->register($this->loan, $this->installment(['fecha_pago' => '2026-03-20']), 'jperez');

        $line = $receipt->detalles()->first();
        $this->assertSame('2026-03-20', $line->fecha_pago->toDateString());
        $this->assertSame('2026-04-01', $line->fecha_cuota->toDateString());
    }

    public function test_an_interest_only_receipt_is_detailed_as_a_partial_payment(): void
    {
        $receipt = $this->registrar->register($this->loan, $this->installment(['tipo' => TipoRecibo::ParcialInteres, 'monto_principal' => 0]), 'jperez');

        $this->assertSame(['PC' => 20000.0], $this->lineAmounts($receipt));
    }

    public function test_principal_and_advance_payments_are_detailed_with_their_own_type(): void
    {
        $this->registrar->register($this->loan, $this->installment(), 'jperez');

        $principal = $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::SoloPrincipal, 'fecha_pago' => '2026-04-10', 'monto_principal' => 100000,
        ], 'jperez');
        $advance = $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::AdelantoCuota, 'fecha_pago' => '2026-04-11', 'monto_principal' => 73290.81,
        ], 'jperez');

        $this->assertSame(['PP' => 100000.0], $this->lineAmounts($principal));
        $this->assertSame(['PA' => 73290.81], $this->lineAmounts($advance));

        $applied = $advance->detalles()->first();
        $this->assertSame(2, $applied->numero_cuota);
        $this->assertSame('2026-05-01', $applied->fecha_cuota->toDateString());
    }

    public function test_a_payoff_is_detailed_as_balance_plus_accrued_interest(): void
    {
        $receipt = $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::CancelacionTotal, 'fecha_pago' => '2026-03-16', 'monto_principal' => 6000000, 'monto_interes' => 10000,
        ], 'jperez');

        $this->assertSame(['CT' => 6000000.0, 'PI' => 10000.0], $this->lineAmounts($receipt));
    }

    public function test_other_items_are_recorded_without_changing_the_balance(): void
    {
        $withoutOthers = $this->registrar->register($this->loan, $this->installment(), 'jperez');
        $balance = $this->loan->fresh()->saldo_prestamo;
        $this->registrar->void($withoutOthers, 'Prueba', 'tester');

        $receipt = $this->registrar->register($this->loan, $this->installment() + [
            'monto_otros' => 500, 'descripcion_otros' => 'Comisión de desembolso',
        ], 'jperez');

        $this->assertSame(['PP' => 53290.81, 'PI' => 20000.0, 'OT' => 500.0], $this->lineAmounts($receipt));
        $this->assertSame(500.0, $receipt->fresh()->monto_otros);
        $this->assertSame(73790.81, $receipt->fresh()->totalAmount());
        $this->assertSame($balance, $this->loan->fresh()->saldo_prestamo);
        $this->assertSame('Comisión de desembolso', $receipt->detalles()->where('monto', 500)->firstOrFail()->descripcion);
    }

    public function test_a_custom_payment_type_can_be_used_for_other_items_but_a_system_one_cannot(): void
    {
        $custom = TipoPago::factory()->create(['codigo' => 'SG', 'nombre' => 'Seguro']);
        $principal = TipoPago::where('codigo', 'PP')->firstOrFail();

        $receipt = $this->registrar->register($this->loan, $this->installment() + ['monto_otros' => 200, 'tipo_pago_otros_id' => $custom->id], 'jperez');
        $this->assertSame(['PP' => 53290.81, 'PI' => 20000.0, 'SG' => 200.0], $this->lineAmounts($receipt));
        $this->registrar->void($receipt, 'Prueba', 'tester');

        try {
            $this->registrar->register($this->loan, $this->installment() + ['monto_otros' => 200, 'tipo_pago_otros_id' => $principal->id], 'jperez');
            $this->fail('A system type should not be accepted for other items.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tipo_pago_otros_id', $exception->errors());
        }
    }

    public function test_voiding_a_receipt_keeps_its_detail_marked_through_the_receipt(): void
    {
        $receipt = $this->registrar->register($this->loan, $this->installment(), 'jperez');
        $this->registrar->void($receipt, 'Error', 'tester');

        $this->assertSame(2, DetallePago::where('recibo_id', $receipt->id)->count());
        $this->get(route('prestamos.show', $this->loan))->assertOk()->assertSee('row-voided', false)->assertSee('Anulado');
    }

    public function test_every_receipt_updates_the_loan_and_the_available_credit_of_its_line(): void
    {
        $receipt = $this->registrar->register($this->loan, $this->installment(), 'jperez');

        $loan = $this->loan->fresh();
        $this->assertEqualsWithDelta(5946709.19, $loan->saldo_prestamo, 0.001);
        $this->assertSame('2026-05-01', $loan->proximo_pago->toDateString());
        $this->assertEqualsWithDelta(65000000 - 5946709.19, $this->line->fresh()->disponible, 0.001);

        $this->registrar->void($receipt, 'Error', 'tester');

        $this->assertEqualsWithDelta(65000000 - 6000000, $this->line->fresh()->disponible, 0.001);
    }

    public function test_the_available_credit_follows_loans_being_created_edited_and_deleted(): void
    {
        $this->post(route('prestamos.store'), [
            'numero_prestamo' => '1111', 'id_lineas_prestamos' => $this->line->id, 'monto_prestamo' => 10000000,
            'formalizacion' => '2026-03-01', 'plazo_meses' => 12, 'tasa_interes' => 2, 'tasa_spread' => 2,
        ])->assertSessionHasNoErrors();
        $created = Prestamo::where('numero_prestamo', '1111')->firstOrFail();

        $this->assertEqualsWithDelta(65000000 - 6000000 - 10000000, $this->line->fresh()->disponible, 0.001);

        $this->put(route('prestamos.update', $created), [
            'numero_prestamo' => '1111', 'id_lineas_prestamos' => $this->line->id, 'monto_prestamo' => 4000000,
            'formalizacion' => '2026-03-01', 'plazo_meses' => 12, 'tasa_interes' => 2, 'tasa_spread' => 2,
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(65000000 - 6000000 - 4000000, $this->line->fresh()->disponible, 0.001);

        $this->delete(route('prestamos.destroy', $created));
        $this->assertEqualsWithDelta(65000000 - 6000000, $this->line->fresh()->disponible, 0.001);
    }

    public function test_the_available_credit_is_recalculated_when_the_line_limit_changes(): void
    {
        $this->registrar->register($this->loan, $this->installment(), 'jperez');

        $payload = $this->line->only([
            'nombre', 'empresa_id', 'banco_id', 'tipo_linea', 'moneda', 'capital_trabajo', 'plazo_maximo_meses', 'modalidad_pago',
            'modalidad_cuota', 'forma_pago', 'calendario', 'tipo_cuota', 'tipo_interes', 'tipotasa_id', 'tasa_interes', 'tasa_spread',
            'variacion_porcentual', 'estado',
        ]) + ['monto_maximo' => 70000000, 'fecha_vencmiento' => '2032-03-07'];

        $this->put(route('lineas.update', $this->line->id), $payload)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(70000000 - 5946709.19, $this->line->fresh()->disponible, 0.001);
        $this->get(route('lineas.index'))->assertOk()->assertSee('Disponible')->assertSee('64,053,290.81');
    }

    public function test_the_detail_tab_lists_the_payment_lines_and_the_receipt_form_offers_other_items(): void
    {
        $this->registrar->register($this->loan, $this->installment() + ['monto_otros' => 150, 'descripcion_otros' => 'Seguro'], 'jperez');

        $this->get(route('prestamos.show', $this->loan))
            ->assertOk()
            ->assertSee('data-tab="details"', false)
            ->assertSee('Detalle de pagos (3)')
            ->assertSee('Pago principal')
            ->assertSee('Pago interés')
            ->assertSee('Otros rubros')
            ->assertSee('Seguro')
            ->assertSee('name="monto_otros"', false)
            ->assertSee('name="tipo_pago_otros_id"', false);
    }

    public function test_receipts_can_be_registered_with_other_items_from_the_screen(): void
    {
        $this->post(route('prestamos.recibos.store', $this->loan), $this->installment(['tipo' => 'CUOTA_COMPLETA']) + [
            'monto_otros' => 75, 'descripcion_otros' => 'Comisión',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['PP' => 53290.81, 'PI' => 20000.0, 'OT' => 75.0], $this->lineAmounts(Recibo::firstOrFail()));
        $this->post(route('prestamos.recibos.store', $this->loan), ['tipo' => 'SOLO_PRINCIPAL', 'fecha_pago' => '2026-05-01', 'monto_principal' => 10, 'monto_otros' => -5])
            ->assertSessionHasErrors('monto_otros');
    }

    public function test_payment_types_have_a_maintenance_screen_in_the_configuration_menu(): void
    {
        $this->get(route('tipos-pago.index'))
            ->assertOk()
            ->assertSee('Tipos de pago')
            ->assertSee('Pago principal')
            ->assertSee('Otros rubros')
            ->assertSee('Sistema');

        $this->get(route('dashboard'))->assertSee(route('tipos-pago.index'), false);
        $this->get(route('tipos-pago.create'))->assertOk()->assertSee('Código')->assertSee('Reduce el saldo');
    }

    public function test_payment_types_can_be_created_updated_searched_and_deleted(): void
    {
        $this->post(route('tipos-pago.store'), ['codigo' => 'mo', 'nombre' => 'Mora', 'descripcion' => 'Intereses moratorios', 'afecta_saldo' => 0])
            ->assertRedirect(route('tipos-pago.index'));

        $type = TipoPago::where('codigo', 'MO')->firstOrFail();
        $this->assertFalse($type->sistema);
        $this->assertFalse($type->afecta_saldo);

        $this->get(route('tipos-pago.index', ['q' => 'morato']))->assertOk()->assertSee('Mora')->assertDontSee('Pago principal');
        $this->get(route('tipos-pago.edit', $type->id))->assertOk()->assertSee('value="MO"', false);

        $this->put(route('tipos-pago.update', $type->id), ['codigo' => 'MO', 'nombre' => 'Mora y recargos', 'afecta_saldo' => 0])
            ->assertRedirect(route('tipos-pago.index'));
        $this->assertSame('Mora y recargos', $type->fresh()->nombre);

        $this->delete(route('tipos-pago.destroy', $type->id))->assertRedirect(route('tipos-pago.index'));
        $this->assertNull(TipoPago::where('codigo', 'MO')->first());
    }

    public function test_payment_type_codes_must_be_unique_short_and_alphanumeric(): void
    {
        $this->post(route('tipos-pago.store'), ['codigo' => 'pp', 'nombre' => 'Duplicado', 'afecta_saldo' => 0])->assertSessionHasErrors('codigo');
        $this->post(route('tipos-pago.store'), ['codigo' => 'ABCDEF', 'nombre' => 'Largo', 'afecta_saldo' => 0])->assertSessionHasErrors('codigo');
        $this->post(route('tipos-pago.store'), ['codigo' => 'A-B', 'nombre' => 'Raro', 'afecta_saldo' => 0])->assertSessionHasErrors('codigo');
        $this->post(route('tipos-pago.store'), ['codigo' => '', 'nombre' => '', 'afecta_saldo' => ''])->assertSessionHasErrors(['codigo', 'nombre', 'afecta_saldo']);
    }

    public function test_system_payment_types_cannot_change_their_code_or_effect_nor_be_deleted(): void
    {
        $principal = TipoPago::where('codigo', 'PP')->firstOrFail();

        $this->get(route('tipos-pago.edit', $principal->id))->assertOk()->assertSee('Tipo del sistema');

        $this->put(route('tipos-pago.update', $principal->id), ['codigo' => 'ZZ', 'nombre' => 'Pago a principal', 'afecta_saldo' => 0]);
        $principal->refresh();
        $this->assertSame('PP', $principal->codigo);
        $this->assertTrue($principal->afecta_saldo);
        $this->assertSame('Pago a principal', $principal->nombre);

        $this->delete(route('tipos-pago.destroy', $principal->id))
            ->assertSessionHasErrors('delete');
        $this->assertNotNull(TipoPago::where('codigo', 'PP')->first());
        $this->get(route('tipos-pago.index'))->assertSee('En uso');
    }

    public function test_a_payment_type_with_detail_lines_cannot_be_deleted(): void
    {
        $custom = TipoPago::factory()->create(['codigo' => 'SG', 'nombre' => 'Seguro']);
        $this->registrar->register($this->loan, $this->installment() + ['monto_otros' => 200, 'tipo_pago_otros_id' => $custom->id], 'jperez');

        $this->delete(route('tipos-pago.destroy', $custom->id))
            ->assertSessionHasErrors(['delete' => 'No se puede eliminar «SG»: tiene 1 línea(s) en el detalle de pagos.']);
        $this->assertNotNull(TipoPago::where('codigo', 'SG')->first());
    }

    public function test_guests_cannot_reach_the_payment_type_screens(): void
    {
        auth()->logout();

        $this->get(route('tipos-pago.index'))->assertRedirect(route('login'));
    }
}
