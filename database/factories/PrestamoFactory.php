<?php

namespace Database\Factories;

use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prestamo>
 */
class PrestamoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_lineas_prestamos' => LineaPrestamo::factory(),
            'numero_prestamo' => (string) fake()->unique()->numerify('####'),
            'monto_prestamo' => 6000000,
            'formalizacion' => '2026-03-01',
            'plazo_meses' => 96,
            'tasa_interes' => 2,
            'tasa_spread' => 2,
            'cuota_calculada' => 73135.65,
            'vencimiento' => '2034-03-01',
            'proximo_pago' => '2026-04-01',
            'saldo_prestamo' => 6000000,
            'estado' => Prestamo::STATUS_ACTIVE,
            'observacion' => null,
        ];
    }

    public function settled(): static
    {
        return $this->state(['estado' => Prestamo::STATUS_SETTLED, 'saldo_prestamo' => 0]);
    }
}
