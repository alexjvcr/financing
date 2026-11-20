<?php

namespace App\Enums;

enum EstadoCuota: string
{
    case Pagada = 'PAGADA';
    case ParcialInteres = 'PARCIAL';
    case Vencida = 'VENCIDA';
    case Pendiente = 'PENDIENTE';
    case Abono = 'ABONO';
    case Cancelacion = 'CANCELACION';

    public function label(): string
    {
        return match ($this) {
            self::Pagada => 'Pagada',
            self::ParcialInteres => 'Solo interés',
            self::Vencida => 'Vencida',
            self::Pendiente => 'Pendiente',
            self::Abono => 'Abono a principal',
            self::Cancelacion => 'Cancelación total',
        };
    }

    /**
     * Modificador CSS que usa el componente de insignia.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pagada, self::Cancelacion => 'badge-success',
            self::ParcialInteres, self::Abono => 'badge-info',
            self::Vencida => 'badge-danger',
            self::Pendiente => 'badge-neutral',
        };
    }

    /**
     * Indica si la cuota todavía debe pagarse.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Vencida, self::Pendiente], true);
    }
}
