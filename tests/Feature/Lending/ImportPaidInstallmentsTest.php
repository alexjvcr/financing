<?php

namespace Tests\Feature\Lending;

use App\Enums\TipoRecibo;
use App\Models\DetallePago;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\TipoPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportPaidInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    protected Prestamo $loan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18');

        Schema::create('planpagos', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('prestamo_id');
            $table->integer('numero_cuota');
            $table->date('fecha_pago');
            $table->decimal('monto_principal', 18, 2);
            $table->decimal('monto_interes', 18, 2);
            $table->string('estado');
            $table->dateTime('fecha_pago_real')->nullable();
        });

        $line = LineaPrestamo::factory()->create();
        $this->loan = Prestamo::factory()->create([
            'id_lineas_prestamos' => $line->id,
            'cuota_calculada' => 73290.81,
        ]);

        DB::table('planpagos')->insert([
            ['prestamo_id' => $this->loan->id, 'numero_cuota' => 1, 'fecha_pago' => '2026-04-01', 'monto_principal' => 53290.81, 'monto_interes' => 20000, 'estado' => 'C', 'fecha_pago_real' => null],
            ['prestamo_id' => $this->loan->id, 'numero_cuota' => 2, 'fecha_pago' => '2026-05-01', 'monto_principal' => 53468.45, 'monto_interes' => 19822.36, 'estado' => 'C', 'fecha_pago_real' => '2026-03-09 00:00:00'],
            ['prestamo_id' => $this->loan->id, 'numero_cuota' => 3, 'fecha_pago' => '2026-06-01', 'monto_principal' => 53646.67, 'monto_interes' => 19644.14, 'estado' => 'P', 'fecha_pago_real' => null],
        ]);
    }

    public function test_paid_installments_become_receipts_and_the_loan_is_refreshed(): void
    {
        $this->artisan('lending:import-paid-installments')->assertSuccessful();

        $this->assertSame(2, Recibo::count());

        $second = Recibo::where('numero_cuota', 2)->firstOrFail();
        $this->assertSame(TipoRecibo::CuotaCompleta, $second->tipo);
        $this->assertSame('2026-04-01', $second->fecha_pago->toDateString());
        $this->assertStringContainsString('fecha real de pago: 09/03/2026', $second->observaciones);
        $this->assertSame(Recibo::where('numero_cuota', 1)->firstOrFail()->fecha_pago->toDateString(), '2026-04-01');

        $this->loan->refresh();
        $this->assertEqualsWithDelta(5893240.74, $this->loan->saldo_prestamo, 0.001);
        $this->assertSame('2026-06-01', $this->loan->proximo_pago->toDateString());

        $this->assertSame(4, DetallePago::count());
        $principalLine = DetallePago::where('numero_cuota', 2)->where('tipo_pago_id', TipoPago::where('codigo', 'PP')->value('id'))->firstOrFail();
        $this->assertSame('2026-05-01', $principalLine->fecha_cuota->toDateString());
        $this->assertSame('2026-04-01', $principalLine->fecha_pago->toDateString());
        $this->assertSame(53468.45, $principalLine->monto);
        $this->assertEqualsWithDelta(65000000 - 5893240.74, $this->loan->linea->fresh()->disponible, 0.001);
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('lending:import-paid-installments')->assertSuccessful();
        $this->artisan('lending:import-paid-installments')->assertSuccessful();

        $this->assertSame(2, Recibo::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('lending:import-paid-installments', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Recibo::count());
        $this->assertEqualsWithDelta(6000000.0, $this->loan->fresh()->saldo_prestamo, 0.001);
    }
}
