<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Base de conteo de días que convierte los días de un período en una fracción del año.
 *
 * Los valores son los códigos de "calendario" guardados en lineas_prestamos y prestamos.
 */
enum DayCount: string
{
    /** Días reales / 360: la base habitual de los préstamos en dólares (SOFR, Prime) en Costa Rica y en el exterior. */
    case Actual360 = '365/360';

    /** Días reales / 365: común en préstamos en colones y bancos locales. */
    case Actual365 = '365/365';

    /** Mes comercial de 30 días sobre un año de 360 días. */
    case Thirty360 = '360/360';

    /**
     * Base para un código de calendario desconocido o vacío: 30/360.
     */
    public static function fromCalendar(?string $code): self
    {
        return self::tryFrom((string) $code) ?? self::Thirty360;
    }

    public function label(): string
    {
        return match ($this) {
            self::Actual360 => '365/360 · Actual/360 (días reales sobre 360)',
            self::Actual365 => '365/365 · Actual/365 (días reales sobre 365)',
            self::Thirty360 => '360/360 · 30/360 (mes comercial de 30 días)',
        };
    }

    public function usesActualDays(): bool
    {
        return $this !== self::Thirty360;
    }

    /**
     * Días del año entre los que divide la base.
     */
    public function basis(): int
    {
        return $this === self::Actual365 ? 365 : 360;
    }

    /**
     * Días reales entre dos fechas (días completos).
     */
    public static function actualDays(Carbon $from, Carbon $to): int
    {
        return (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false));
    }
}
