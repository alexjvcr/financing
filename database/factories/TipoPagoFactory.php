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
     * A custom (non system) payment type, usable as an "other items" concept.
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
