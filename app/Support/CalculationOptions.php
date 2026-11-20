<?php

namespace App\Support;

use App\Enums\ConvencionTasa;
use App\Enums\DayCount;
use App\Enums\ModalidadCuota;
use App\Models\LineaPrestamo;

/**
 * Listas valor => etiqueta de las condiciones de cálculo, compartidas por los
 * formularios de la línea de crédito y del sub préstamo.
 */
final class CalculationOptions
{
    /**
     * @return array<string, string>
     */
    public static function modalities(): array
    {
        return collect(ModalidadCuota::cases())->mapWithKeys(fn (ModalidadCuota $case): array => [$case->value => $case->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public static function calendars(): array
    {
        return collect(DayCount::cases())->mapWithKeys(fn (DayCount $case): array => [$case->value => $case->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public static function rateConventions(): array
    {
        return collect(ConvencionTasa::cases())->mapWithKeys(fn (ConvencionTasa $case): array => [$case->value => $case->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public static function paymentModes(): array
    {
        return [
            'MENSUAL' => 'Mensual (12 cuotas al año)',
            'TRIMESTRAL' => 'Trimestral (4 cuotas al año)',
            'SEMESTRAL' => 'Semestral (2 cuotas al año)',
            'ANUAL' => 'Anual (1 cuota al año)',
            'UNICO' => 'Único (un pago al vencimiento)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paymentForms(): array
    {
        return [
            'VENCIMIENTO' => 'Interés vencido (se paga al vencimiento de cada cuota)',
            'ADELANTADO' => 'Interés adelantado (hoy se calcula como vencido)',
        ];
    }

    /**
     * Pagos por año de cada modalidad de pago (se usa para validar la modalidad).
     *
     * @return array<int, string>
     */
    public static function paymentModeCodes(): array
    {
        return array_keys(LineaPrestamo::PAYMENTS_PER_YEAR);
    }
}
