<?php
/**
 * Shared helper functions.
 */

if (!function_exists('format_display_date')) {
    /**
     * Convert a date string (typically Y-m-d) to display format DD.MM.YYYY.
     *
     * @param string|null $value Raw date value from database or input.
     * @return string|null Formatted date or null when input is empty/invalid.
     */
    function format_display_date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }
        return $dt->format('d.m.Y');
    }
}

if (!function_exists('format_display_datetime')) {
    /**
     * Convert a datetime string to display format DD.MM.YYYY HH:MM (optionally with seconds).
     *
     * @param string|null $value Raw datetime value from database or input.
     * @param bool $withSeconds Whether to include seconds component.
     * @return string|null Formatted datetime or null when input is empty/invalid.
     */
    function format_display_datetime(?string $value, bool $withSeconds = false): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }
        $format = $withSeconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i';
        return $dt->format($format);
    }
}
