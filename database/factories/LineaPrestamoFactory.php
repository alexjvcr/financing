<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\LineaPrestamo;
use App\Models\TipoTasa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineaPrestamo>
 */
class LineaPrestamoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'banco_id' => null,
            'nombre' => fake()->unique()->company().' DOLARES',
            'tipo_linea' => 'CAPITAL',
            'monto_maximo' => 65000000,
            'plazo_maximo_meses' => 96,
            'modalidad_pago' => 'MENSUAL',
            'estado' => LineaPrestamo::STATUS_ACTIVE,
            'fecha_vencmiento' => '2032-03-07',
            'capital_trabajo' => 0,
            'moneda' => 'DOLARES',
            'forma_pago' => 'VENCIMIENTO',
            'calendario' => '360/360',
            'tipo_cuota' => 'NOMINAL',
            'periodicidad_pago' => 12,
            'tipo_interes' => 'BASE_SPREAD',
            'modalidad_cuota' => 'CONSTANTE',
            'variacion_porcentual' => 0,
            'tipotasa_id' => TipoTasa::factory(),
            'tasa_interes' => 2,
            'tasa_spread' => 2,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['estado' => LineaPrestamo::STATUS_INACTIVE]);
    }
}
