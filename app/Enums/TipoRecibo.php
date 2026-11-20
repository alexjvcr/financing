<?php

namespace App\Enums;

enum TipoRecibo: string
{
    case CuotaCompleta = 'CUOTA_COMPLETA';
    case ParcialInteres = 'PARCIAL_INTERES';
    case SoloPrincipal = 'SOLO_PRINCIPAL';
    case AdelantoCuota = 'ADELANTO_CUOTA';
    case CancelacionTotal = 'CANCELACION_TOTAL';

    public function code(): int
    {
        return match ($this) {
            self::CuotaCompleta => 1,
            self::ParcialInteres => 2,
            self::SoloPrincipal => 3,
            self::AdelantoCuota => 4,
            self::CancelacionTotal => 5,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CuotaCompleta => 'Cuota completa (principal e interés)',
            self::ParcialInteres => 'Cuota parcial (solo interés)',
            self::SoloPrincipal => 'Solo pago a principal',
            self::AdelantoCuota => 'Adelanto de cuota',
            self::CancelacionTotal => 'Pago de cancelación total',
        };
    }

    /**
     * Recibos que cancelan una cuota numerada del plan.
     */
    public function isInstallmentPayment(): bool
    {
        return in_array($this, [self::CuotaCompleta, self::ParcialInteres], true);
    }

    /**
     * Recibos que reducen el saldo fuera de las cuotas regulares.
     */
    public function isPrincipalPrepayment(): bool
    {
        return in_array($this, [self::SoloPrincipal, self::AdelantoCuota], true);
    }
}
