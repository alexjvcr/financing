<?php

namespace App\Services\Lending;

use App\Enums\ConvencionTasa;
use App\Enums\DayCount;
use App\Enums\ModalidadCuota;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;

/**
 * Las condiciones de cálculo que aplican a un sub préstamo.
 *
 * Toda condición se define en la línea de crédito y un sub préstamo puede sobrescribirla;
 * un valor nulo en el sub préstamo significa "heredar de la línea".
 */
final class LoanConditions
{
    public function __construct(
        public readonly ModalidadCuota $modality,
        public readonly DayCount $dayCount,
        public readonly ConvencionTasa $rateConvention,
        public readonly int $monthsPerPeriod,
        public readonly int $graceMonths,
        public readonly float $balloonPercent,
        public readonly float $growthPercent,
        public readonly string $paymentForm,
    ) {}

    /**
     * Resuelve las condiciones de un préstamo: su propia sobrescritura o la de su línea.
     */
    public static function resolve(Prestamo $loan, LineaPrestamo $line): self
    {
        $pick = fn (string $column): mixed => $loan->{$column} ?? $line->{$column};

        $paymentsPerYear = LineaPrestamo::PAYMENTS_PER_YEAR[$pick('modalidad_pago')] ?? 12;

        return new self(
            ModalidadCuota::fromCode($pick('modalidad_cuota')),
            DayCount::fromCalendar($pick('calendario')),
            ConvencionTasa::fromCode($pick('tipo_cuota')),
            max(intdiv(12, $paymentsPerYear), 1),
            max((int) $pick('meses_gracia'), 0),
            max((float) $pick('porcentaje_global'), 0.0),
            max((float) $pick('variacion_porcentual'), 0.0),
            (string) ($pick('forma_pago') ?? 'VENCIMIENTO'),
        );
    }

    /**
     * Cantidad de períodos iniciales en los que solo se paga interés.
     */
    public function gracePeriods(): int
    {
        return intdiv($this->graceMonths, $this->monthsPerPeriod);
    }

    /**
     * Descripción de una línea para las pantallas, por ejemplo "Cuota nivelada · Actual/360 · mensual".
     */
    public function describe(): string
    {
        $period = match ($this->monthsPerPeriod) {
            1 => 'mensual',
            3 => 'trimestral',
            6 => 'semestral',
            default => 'anual',
        };

        $parts = [$this->modality->label(), $this->dayCount->label(), $this->rateConvention->label(), 'pago '.$period];

        if ($this->graceMonths > 0) {
            $parts[] = "gracia de {$this->graceMonths} meses";
        }

        if ($this->balloonPercent > 0) {
            $parts[] = 'cuota global '.rtrim(rtrim(number_format($this->balloonPercent, 4), '0'), '.').' %';
        }

        if ($this->modality === ModalidadCuota::Creciente) {
            $parts[] = 'crece '.rtrim(rtrim(number_format($this->growthPercent, 4), '0'), '.').' % por período';
        }

        return implode(' · ', $parts);
    }
}
