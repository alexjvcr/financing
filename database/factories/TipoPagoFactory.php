<?php

namespace Database\Factories;

use App\Models\TipoPago;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoPago>
 */
class TipoPagoFactory extends Factory
{
    /**
     * Un tipo de pago personalizado (no de sistema), utilizable como concepto de "otros rubros".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => strtoupper(fake()->unique()->lexify('??')),
            'nombre' => fake()->unique()->words(2, true),
            'descripcion' => null,
            'afecta_saldo' => false,
            'sistema' => false,
        ];
    }
}
