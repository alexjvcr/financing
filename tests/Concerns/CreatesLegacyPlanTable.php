<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla heredada planpagos con las mismas columnas que la base de datos de producción.
 */
trait CreatesLegacyPlanTable
{
    protected function createLegacyPlanTable(): void
    {
        Schema::create('planpagos', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('prestamo_id');
            $table->integer('numero_cuota');
            $table->date('fecha_pago');
            $table->decimal('monto_principal', 18, 2)->default(0);
            $table->decimal('monto_interes', 18, 2)->default(0);
            $table->decimal('tasa_interes', 15, 2)->default(0);
            $table->decimal('saldo_principal', 15, 2)->default(0);
            $table->decimal('saldo_interes', 15, 2)->default(0);
            $table->decimal('saldo_prestamo', 15, 2)->default(0);
            $table->string('estado', 18)->default('P');
            $table->dateTime('fecha_pago_real')->nullable();
            $table->string('observaciones', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Inserta una fila de cuota heredada con valores por defecto razonables.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function insertLegacyRow(array $attributes): void
    {
        DB::table('planpagos')->insert($attributes + [
            'fecha_pago' => '2026-01-01',
            'monto_principal' => 0,
            'monto_interes' => 0,
            'estado' => 'P',
        ]);
    }

    /**
     * Estados (P / C) de las primeras cuotas de un préstamo en planpagos.
     *
     * @return array<int, string>
     */
    protected function legacyStatuses(int $loanId, int $upTo = 4): array
    {
        return DB::table('planpagos')
            ->where('prestamo_id', $loanId)
            ->where('numero_cuota', '<=', $upTo)
            ->orderBy('numero_cuota')
            ->pluck('estado')
            ->all();
    }
}
