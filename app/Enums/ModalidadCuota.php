<?php

namespace App\Enums;

/**
 * Cómo se amortiza el principal de un préstamo (modalidad_cuota).
 */
enum ModalidadCuota: string
{
    /** Cuota nivelada (sistema francés): pago constante de principal más intereses. */
    case Constante = 'CONSTANTE';

    /** Amortización lineal (sistema alemán): principal constante, cuota decreciente. */
    case Decreciente = 'DECRECIENTE';

    /** Solo intereses durante todo el plazo; el principal se paga al vencimiento. */
    case Bullet = 'BULLET';

    /** Cuota graduada: crece un porcentaje fijo cada período. */
    case Creciente = 'CRECIENTE';

    public static function fromCode(?string $code): self
    {
        return self::tryFrom((string) $code) ?? self::Constante;
    }

    public function label(): string
    {
        return match ($this) {
            self::Constante => 'Cuota nivelada (constante)',
            self::Decreciente => 'Amortización lineal (decreciente)',
            self::Bullet => 'Solo intereses (bullet)',
            self::Creciente => 'Cuota creciente (graduada)',
        };
    }

    /**
     * Indica si la cuota contractual guardada es la cuota inicial.
     */
    public function usesContractualInstallment(): bool
    {
        return in_array($this, [self::Constante, self::Creciente], true);
    }
}
