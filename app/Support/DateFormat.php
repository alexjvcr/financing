<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Single source of truth for how dates are shown and typed (DATE_FORMAT in .env).
 *
 * Dates are always stored and validated as yyyy-mm-dd. This class only converts
 * between that ISO form and the configured display format.
 */
final class DateFormat
{
    /**
     * Formats a user can choose with DATE_FORMAT.
     *
     * @var array<int, string>
     */
    public const SUPPORTED = ['d/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y', 'm-d-Y', 'Y-m-d'];

    /**
     * Value that no date rule accepts, used for malformed input so it is
     * rejected instead of being read in another order.
     */
    public const INVALID = 'invalid-date';

    private const DEFAULT = 'd/m/Y';

    /**
     * The configured PHP date format, for example d/m/Y.
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
     * Order of the parts, for example ['d', 'm', 'Y'].
     *
     * @return array<int, string>
     */
    public static function order(): array
    {
        return explode(self::separator(), self::pattern());
    }

    /**
     * Hint shown inside date fields, for example dd/mm/aaaa.
     */
    public static function placeholder(): string
    {
        $labels = ['d' => 'dd', 'm' => 'mm', 'Y' => 'aaaa'];

        return implode(self::separator(), array_map(fn (string $part): string => $labels[$part], self::order()));
    }

    /**
     * Regular expression body (no delimiters) that matches the display format.
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
     * Convert typed text to yyyy-mm-dd, or the INVALID marker for impossible dates.
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
     * Format a date for display in tables and messages. Empty when there is no date.
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
     * Format a date and time for display, for example 18/11/2026 14:30.
     */
    public static function formatDateTime(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format(self::pattern().' H:i') : '';
    }

    /**
     * Value to put inside a date field: stored dates and flashed input become
     * the display format, text the user typed is kept as it is.
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
     * Settings the browser needs to mask and parse date fields.
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
