<?php
/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

/**
 * Kleine, abhängigkeitsfreie Validierungshelfer für Controller.
 */

declare(strict_types=1);

final class Validation
{
    /** Prüft ein Datum im Format YYYY-MM-DD auf Gültigkeit. */
    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    /** Gibt das Datum zurück oder den Fallback (heute), wenn ungültig/leer. */
    public static function dateOr(mixed $value, ?string $fallback = null): string
    {
        return self::isDate($value) ? $value : ($fallback ?? date('Y-m-d'));
    }

    /** Getrimmter String, auf $max Zeichen begrenzt; null wenn leer und $nullable. */
    public static function text(mixed $value, int $max, bool $nullable = true): ?string
    {
        if ($value === null || (!is_scalar($value))) {
            return $nullable ? null : '';
        }
        $s = trim((string) $value);
        if ($s === '' && $nullable) {
            return null;
        }
        return mb_substr($s, 0, $max);
    }

    /** Dezimalzahl oder null (leerer String / nicht numerisch → null). */
    public static function decimalOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /** Hex-Farbe (#rrggbb) oder Default. */
    public static function color(mixed $value, string $default = '#4caf50'): string
    {
        return (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) ? strtolower($value) : $default;
    }

    /** Wert aus einer Whitelist oder Default. */
    public static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return (is_string($value) && in_array($value, $allowed, true)) ? $value : $default;
    }
}
