<?php

namespace Tests\Feature\Lending;

use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Services\Lending\ReceiptRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReceiptRegistrarTest extends TestCase
{
    use RefreshDatabase;

    protected Prestamo $loan;

    protected ReceiptRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');

        $line = LineaPrestamo::factory()->create();
        $this->loan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $line->id,
            'cuota_calculada' => 73290.81,
        ]);
        $this->registrar = app(ReceiptRegistrar::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function installmentData(array $overrides = []): array
    {
        return $overrides + [
            'tipo' => TipoRecibo::CuotaCompleta,
            'fecha_pago' => '2026-04-01',
            'numero_cuota' => 1,
            'monto_principal' => 53290.81,
            'monto_interes' => 20000,
        ];
    }

    protected function assertRejected(array $data, string $field): void
    {
        try {
            $this->registrar->register($this->loan, $data, 'tester');
            $this->fail('Expected a validation error on '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_full_installment_is_stored_and_refreshes_the_loan_snapshot(): void
    {
        $receipt = $this->registrar->register($this->loan, $this->installmentData(), 'jperez');

        $this->assertSame(TipoRecibo::CuotaCompleta, $receipt->tipo);
        $this->assertSame('jperez', $receipt->usuario);
        $this->assertSame(73290.81, $receipt->totalAmount());

        $this->loan->refresh();
        $this->assertEqualsWithDelta(5946709.19, $this->loan->saldo_prestamo, 0.001);
        $this->assertSame('2026-05-01', $this->loan->proximo_pago->toDateString());
        $this->assertSame(73290.81, $this->loan->cuota_calculada);
        $this->assertSame(Prestamo::STATUS_ACTIVE, $this->loan->estado);
    }

    public function test_installments_must_be_registered_in_sequence(): void
    {
        $this->assertRejected($this->installmentData(['numero_cuota' => 2]), 'numero_cuota');

        $this->registrar->register($this->loan, $this->installmentData(), 'tester');
        $this->assertRejected($this->installmentData(['numero_cuota' => 1, 'fecha_pago' => '2026-05-01']), 'numero_cuota');
    }

    public function test_dates_must_follow_formalization_and_previous_receipts(): void
    {
        $this->assertRejected($this->installmentData(['fecha_pago' => '2026-03-01']), 'fecha_pago');

        $this->registrar->register($this->loan, $this->installmentData(['fecha_pago' => '2026-05-10']), 'tester');
        $this->assertRejected($this->installmentData(['numero_cuota' => 2, 'fecha_pago' => '2026-05-09']), 'fecha_pago');
    }

    public function test_full_installment_requires_principal_and_interest(): void
    {
        $this->assertRejected($this->installmentData(['monto_principal' => 0]), 'monto_principal');
        $this->assertRejected($this->installmentData(['monto_interes' => 0]), 'monto_interes');
    }

    public function test_interest_only_receipt_cannot_include_principal_and_defers_it(): void
    {
        $this->assertRejected($this->installmentData(['tipo' => TipoRecibo::ParcialInteres, 'monto_principal' => 100]), 'monto_principal');

        $this->registrar->register($this->loan, $this->installmentData([
            'tipo' => TipoRecibo::ParcialInteres, 'monto_principal' => 0,
        ]), 'tester');

        $this->loan->refresh();
        $this->assertEqualsWithDelta(6000000.0, $this->loan->saldo_prestamo, 0.001);
        $this->assertSame('2026-05-01', $this->loan->proximo_pago->toDateString());
    }

    public function test_principal_prepayment_lowers_the_balance_and_shortens_the_term(): void
    {
        $this->registrar->register($this->loan, $this->installmentData(), 'tester');
        $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::SoloPrincipal, 'fecha_pago' => '2026-05-01', 'monto_principal' => 500000,
        ], 'tester');

        $this->loan->refresh();
        $this->assertEqualsWithDelta(5446709.19, $this->loan->saldo_prestamo, 0.001);
        $this->assertLessThan('2034-03-01', $this->loan->vencimiento->toDateString());
    }

    public function test_prepayment_rules(): void
    {
        $this->assertRejected(['tipo' => TipoRecibo::SoloPrincipal, 'fecha_pago' => '2026-04-10', 'monto_principal' => 0], 'monto_principal');
        $this->assertRejected(['tipo' => TipoRecibo::SoloPrincipal, 'fecha_pago' => '2026-04-10', 'monto_principal' => 100, 'monto_interes' => 5], 'monto_interes');
        $this->assertRejected(['tipo' => TipoRecibo::AdelantoCuota, 'fecha_pago' => '2026-04-10', 'monto_principal' => 6000000], 'monto_principal');
    }

    public function test_payoff_must_match_the_balance_and_liquidates_the_loan(): void
    {
        $this->registrar->register($this->loan, $this->installmentData(), 'tester');

        $this->assertRejected([
            'tipo' => TipoRecibo::CancelacionTotal, 'fecha_pago' => '2026-04-16', 'monto_principal' => 1000, 'monto_interes' => 100,
        ], 'monto_principal');

        $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::CancelacionTotal, 'fecha_pago' => '2026-04-16', 'monto_principal' => 5946709.19, 'monto_interes' => 9911.18,
        ], 'tester');

        $this->loan->refresh();
        $this->assertSame(Prestamo::STATUS_SETTLED, $this->loan->estado);
        $this->assertEqualsWithDelta(0.0, $this->loan->saldo_prestamo, 0.001);

        $this->assertRejected($this->installmentData(['numero_cuota' => 2, 'fecha_pago' => '2026-05-01']), 'tipo');
    }

    public function test_only_the_latest_receipt_can_be_voided_and_the_loan_is_restored(): void
    {
        $first = $this->registrar->register($this->loan, $this->installmentData(), 'tester');
        $second = $this->registrar->register($this->loan, $this->installmentData([
            'numero_cuota' => 2, 'fecha_pago' => '2026-05-01', 'monto_principal' => 53468.45, 'monto_interes' => 19822.36,
        ]), 'tester');

        try {
            $this->registrar->void($first, 'Error');
            $this->fail('Voiding an older receipt should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recibo', $exception->errors());
        }

        $this->registrar->void($second, 'Monto incorrecto');

        $this->assertSame(Recibo::STATUS_VOIDED, $second->fresh()->estado);
        $this->assertSame('Monto incorrecto', $second->fresh()->motivo_anulacion);
        $this->loan->refresh();
        $this->assertEqualsWithDelta(5946709.19, $this->loan->saldo_prestamo, 0.001);
        $this->assertSame('2026-05-01', $this->loan->proximo_pago->toDateString());

        $this->registrar->register($this->loan, $this->installmentData([
            'numero_cuota' => 2, 'fecha_pago' => '2026-05-01', 'monto_principal' => 53468.45, 'monto_interes' => 19822.36,
        ]), 'tester');
        $this->assertSame(3, Recibo::count());
    }

    public function test_voiding_the_payoff_reactivates_the_loan(): void
    {
        $payoff = $this->registrar->register($this->loan, [
            'tipo' => TipoRecibo::CancelacionTotal, 'fecha_pago' => '2026-03-16', 'monto_principal' => 6000000, 'monto_interes' => 10000,
        ], 'tester');
        $this->assertSame(Prestamo::STATUS_SETTLED, $this->loan->fresh()->estado);

        $this->registrar->void($payoff, 'Duplicado');

        $this->assertSame(Prestamo::STATUS_ACTIVE, $this->loan->fresh()->estado);
        $this->assertEqualsWithDelta(6000000.0, $this->loan->fresh()->saldo_prestamo, 0.001);
    }
}
