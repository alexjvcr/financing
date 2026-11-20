<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Única fuente de verdad de cómo se muestran y se digitan las fechas (DATE_FORMAT en .env).
 *
 * Las fechas siempre se guardan y validan como yyyy-mm-dd. Esta clase solo convierte
 * entre esa forma ISO y el formato de visualización configurado.
 */
final class DateFormat
{
    /**
     * Formatos que se pueden elegir con DATE_FORMAT.
     *
     * @var array<int, string>
     */
    public const SUPPORTED = ['d/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y', 'm-d-Y', 'Y-m-d'];

    /**
     * Valor que ninguna regla de fecha acepta; se usa con entradas mal formadas
     * para que se rechacen en lugar de leerse en otro orden.
     */
    public const INVALID = 'invalid-date';

    private const DEFAULT = 'd/m/Y';

    /**
     * El formato de fecha de PHP configurado, por ejemplo d/m/Y.
     */
    public static function pattern(): string
    {
        $format = config('formats.date');

        return in_array($format, self::SUPPORTED, true) ? $format : self::DEFAULT;
    }

    public static function separator(): string
    {
        return str_contains(self::pattern(), '/') ? '/' : '-';
    }

    /**
     * Orden de las partes, por ejemplo ['d', 'm', 'Y'].
     *
     * @return array<int, string>
     */
    public static function order(): array
    {
        return explode(self::separator(), self::pattern());
    }

    /**
     * Pista mostrada dentro de los campos de fecha, por ejemplo dd/mm/aaaa.
     */
    public static function placeholder(): string
    {
        $labels = ['d' => 'dd', 'm' => 'mm', 'Y' => 'aaaa'];

        return implode(self::separator(), array_map(fn (string $part): string => $labels[$part], self::order()));
    }

    /**
     * Cuerpo de la expresión regular (sin delimitadores) que coincide con el formato de visualización.
     */
    public static function regex(): string
    {
        $parts = array_map(fn (string $part): string => $part === 'Y' ? '\d{4}' : '\d{1,2}', self::order());

        return implode(preg_quote(self::separator(), '#'), $parts);
    }

    public static function matches(string $text): bool
    {
        return preg_match('#^'.self::regex().'$#', trim($text)) === 1;
    }

    /**
     * Convierte el texto digitado a yyyy-mm-dd, o a la marca INVALID si la fecha es imposible.
     */
    public static function toIso(string $text): string
    {
        $pieces = preg_split('#[/-]#', trim($text));
        $values = array_combine(self::order(), array_map('intval', $pieces));

        return checkdate($values['m'], $values['d'], $values['Y'])
            ? sprintf('%04d-%02d-%02d', $values['Y'], $values['m'], $values['d'])
            : self::INVALID;
    }

    /**
     * Da formato a una fecha para mostrarla en tablas y mensajes. Vacío cuando no hay fecha.
     */
    public static function format(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(self::pattern());
        }

        if (is_string($value) && preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $value, $iso)) {
            return self::compose((int) $iso[1], (int) $iso[2], (int) $iso[3]);
        }

        return '';
    }

    /**
     * Da formato a una fecha y hora para mostrarla, por ejemplo 18/11/2026 14:30.
     */
    public static function formatDateTime(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format(self::pattern().' H:i') : '';
    }

    /**
     * Valor que se coloca dentro de un campo de fecha: las fechas guardadas y la entrada
     * reenviada pasan al formato de visualización; el texto digitado por el usuario se conserva tal cual.
     */
    public static function toDisplay(mixed $value): string
    {
        $formatted = self::format($value);

        if ($formatted !== '') {
            return $formatted;
        }

        return is_string($value) && $value !== self::INVALID && self::matches($value) ? trim($value) : '';
    }

    /**
     * Ajustes que el navegador necesita para enmascarar e interpretar los campos de fecha.
     *
     * @return array{order: array<int, string>, separator: string, placeholder: string}
     */
    public static function forJavaScript(): array
    {
        return [
            'order' => self::order(),
            'separator' => self::separator(),
            'placeholder' => self::placeholder(),
        ];
    }

    private static function compose(int $year, int $month, int $day): string
    {
        $parts = ['d' => sprintf('%02d', $day), 'm' => sprintf('%02d', $month), 'Y' => sprintf('%04d', $year)];

        return implode(self::separator(), array_map(fn (string $part): string => $parts[$part], self::order()));
    }
}
