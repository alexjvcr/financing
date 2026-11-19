<?php

namespace Database\Factories;

use App\Models\TipoTasa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoTasa>
 */
class TipoTasaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre_tipo_tasa' => strtoupper(fake()->unique()->word()),
            'base' => fake()->randomFloat(5, 1, 8),
        ];
    }
}
