<?php

namespace Database\Factories;

use App\Enums\TipoRecibo;
use App\Models\Prestamo;
use App\Models\Recibo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recibo>
 */
class ReciboFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prestamo_id' => Prestamo::factory(),
            'tipo' => TipoRecibo::CuotaCompleta,
            'numero_cuota' => 1,
            'fecha_pago' => '2026-04-01',
            'monto_principal' => 53135.65,
            'monto_interes' => 20000,
            'observaciones' => null,
            'usuario' => 'tester',
            'estado' => Recibo::STATUS_ACTIVE,
        ];
    }

    public function voided(): static
    {
        return $this->state([
            'estado' => Recibo::STATUS_VOIDED,
            'anulado_at' => now(),
            'motivo_anulacion' => 'Prueba',
        ]);
    }
}
