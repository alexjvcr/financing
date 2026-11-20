<?php

namespace App\Enums;

/**
 * Cómo se convierte la tasa anual en el interés de un período (tipo_cuota).
 */
enum ConvencionTasa: string
{
    /** Tasa nominal simple: interés = saldo × tasa × fracción del año. */
    case Nominal = 'NOMINAL';

    /** Tasa efectiva anual: interés = saldo × ((1 + tasa) ^ fracción − 1). */
    case Efectiva = 'EFECTIVA';

    public static function fromCode(?string $code): self
    {
        return self::tryFrom((string) $code) ?? self::Nominal;
    }

    public function label(): string
    {
        return match ($this) {
            self::Nominal => 'Tasa nominal (interés simple)',
            self::Efectiva => 'Tasa efectiva anual (capitalizable)',
        };
    }
}
