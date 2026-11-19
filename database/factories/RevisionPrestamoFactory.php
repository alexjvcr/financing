<?php

namespace Database\Factories;

use App\Models\Prestamo;
use App\Models\RevisionPrestamo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RevisionPrestamo>
 */
class RevisionPrestamoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prestamo_id' => Prestamo::factory(),
            'fecha_efectiva' => '2026-07-01',
            'tasa_interes' => 1.5,
            'tasa_spread' => 2,
            'plazo_restante_meses' => null,
            'observacion' => null,
            'usuario' => 'tester',
        ];
    }
}
