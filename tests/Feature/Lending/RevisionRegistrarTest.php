<?php

namespace Tests\Feature\Lending;

use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Services\Lending\LoanScheduleService;
use App\Services\Lending\ReceiptRegistrar;
use App\Services\Lending\RevisionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RevisionRegistrarTest extends TestCase
{
    use RefreshDatabase;

    protected Prestamo $loan;

    protected RevisionRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');

        $line = LineaPrestamo::factory()->create();
        $this->loan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $line->id,
            'cuota_calculada' => 73290.81,
        ]);
        $this->registrar = app(RevisionRegistrar::class);
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

    public function test_rate_revision_is_stored_and_changes_the_plan(): void
    {
        $revision = $this->registrar->register($this->loan, [
            'fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1, 'tasa_spread' => 2,
        ], 'jperez');

        $this->assertSame('jperez', $revision->usuario);
        $this->assertSame(3.0, app(LoanScheduleService::class)->scheduleFor($this->loan)->currentAnnualRate);
    }

    public function test_a_revision_needs_at_least_one_change(): void
    {
        $this->assertRejected(['fecha_efectiva' => '2026-07-01'], 'tasa_interes');
        $this->assertRejected(['fecha_efectiva' => '2026-07-01', 'tasa_interes' => -1], 'tasa_interes');
        $this->assertRejected(['fecha_efectiva' => '2026-07-01', 'plazo_restante_meses' => 0], 'plazo_restante_meses');
    }

    public function test_term_revision_shortens_the_maturity(): void
    {
        $this->registrar->register($this->loan, [
            'fecha_efectiva' => '2026-04-01', 'plazo_restante_meses' => 24,
        ], 'tester');

        $this->assertSame('2028-04-01', $this->loan->fresh()->vencimiento->toDateString());
    }

    public function test_dates_cannot_precede_the_loan_or_the_previous_revision(): void
    {
        $this->assertRejected(['fecha_efectiva' => '2026-02-01', 'tasa_interes' => 1], 'fecha_efectiva');

        $this->registrar->register($this->loan, ['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1], 'tester');
        $this->assertRejected(['fecha_efectiva' => '2026-06-01', 'tasa_interes' => 1], 'fecha_efectiva');
    }

    public function test_revisions_cannot_be_retroactive_to_a_paid_installment(): void
    {
        app(ReceiptRegistrar::class)->register($this->loan, [
            'tipo' => TipoRecibo::CuotaCompleta, 'fecha_pago' => '2026-04-01', 'numero_cuota' => 1,
            'monto_principal' => 53290.81, 'monto_interes' => 20000,
        ], 'tester');

        $this->assertRejected(['fecha_efectiva' => '2026-03-15', 'tasa_interes' => 1], 'fecha_efectiva');

        $this->registrar->register($this->loan, ['fecha_efectiva' => '2026-04-01', 'tasa_interes' => 1], 'tester');
        $this->assertSame(1, $this->loan->revisiones()->active()->count());
    }

    public function test_only_the_latest_revision_can_be_voided(): void
    {
        $first = $this->registrar->register($this->loan, ['fecha_efectiva' => '2026-07-01', 'tasa_interes' => 1], 'tester');
        $second = $this->registrar->register($this->loan, ['fecha_efectiva' => '2026-09-01', 'tasa_interes' => 0.5], 'tester');

        try {
            $this->registrar->void($first, 'Motivo de prueba', 'tester');
            $this->fail('Voiding an older revision should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }

        $this->registrar->void($second, 'Motivo de prueba', 'tester');

        $this->assertNotNull($second->fresh()->anulado_at);
        $this->assertSame(3.0, app(LoanScheduleService::class)->scheduleFor($this->loan)->currentAnnualRate);
    }
}
