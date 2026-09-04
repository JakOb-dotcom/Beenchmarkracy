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
 * Rule Engine v2 – Erweiterter Regelbaum-Evaluator für komplexe Marker
 *
 * Unterstützt 5 Bedingungstypen:
 *
 * ═══════════════════════════════════════════════════════════
 * 1. DAILY – Tageswert-Vergleich (wie bisher)
 *    {"type":"daily", "field":"temp_min", "operator":"<", "value":0}
 *
 * 2. PERIOD_AGGREGATE – Aggregation über einen Zeitraum
 *    Summe, Durchschnitt, Min, Max eines Feldes über frei
 *    wählbaren Zeitraum, ggf. im Vorjahr, ggf. als % des
 *    langjährigen Durchschnitts.
 *    { "type":"period_aggregate", "year_ref":"previous",
 *      "period_type":"date_range", "period_start":"10-01",
 *      "period_end":"10-31", "field":"precipitation",
 *      "aggregation":"sum", "compare_mode":"historical_percent",
 *      "operator":">", "value":120 }
 *
 * 3. DAY_COUNT – Tage zählen, die eine Bedingung erfüllen
 *    { "type":"day_count", "year_ref":"current",
 *      "period_type":"month", "period_month":4,
 *      "field":"temp_max", "sub_operator":">", "sub_value":15,
 *      "operator":">=", "value":20 }
 *
 * 4. CONSECUTIVE_DAYS – Aufeinanderfolgende Tage
 *    { "type":"consecutive_days", "year_ref":"current",
 *      "period_type":"date_range", "period_start":"06-01",
 *      "period_end":"08-31", "field":"precipitation",
 *      "sub_operator":"<", "sub_value":1,
 *      "operator":">=", "value":7 }
 *
 * 5. TEMP_SUM – Temperatursumme über Zeitraum (eigener Schwellwert)
 *    { "type":"temp_sum", "year_ref":"current",
 *      "period_type":"date_range", "period_start":"04-01",
 *      "period_end":"04-30", "field":"temp_mean",
 *      "threshold":0, "operator":">=", "value":350 }
 *
 * ═══════════════════════════════════════════════════════════
 * FELDER: gts, temp_mean, temp_min, temp_max, precipitation,
 *   pressure, soil_moisture, month, day_of_year,
 *   is_frost, is_heavy_rain, is_forecast,
 *   precip_sum_Nd, temp_mean_avg_Nd, temp_min_min_Nd,
 *   temp_max_max_Nd (N=2,3,5,7),
 *   temp_deviation, precip_deviation
 *
 * OPERATOREN: >  <  >=  <=  ==  !=  between  in
 *
 * YEAR_REF: "current", "previous", oder konkrete Jahreszahl
 * PERIOD_TYPE: "month", "date_range", "full_year"
 * AGGREGATION: "sum", "avg", "min", "max"
 * COMPARE_MODE: "absolute", "historical_percent"
 * ═══════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../../config/database.php';

class RuleEngine
{
    // Cache für Perioden-Abfragen (vermeidet wiederholte DB-Queries)
    private static array $periodCache = [];

    /**
     * Cache leeren (z.B. zwischen verschiedenen Marker-Evaluierungen)
     */
    public static function clearCache(): void
    {
        self::$periodCache = [];
    }

    // ══════════════════════════════════════════════════
    // HAUPTEVALUATION
    // ══════════════════════════════════════════════════

    /**
     * Evaluiert einen Regelbaum rekursiv.
     */
    public static function evaluate(array $rule, array $ctx): bool
    {
        // Einzelne Bedingung (Blatt) – hat 'field' oder 'type'
        if (isset($rule['field']) || isset($rule['type'])) {
            return self::evaluateCondition($rule, $ctx);
        }

        // Logik-Gruppe (Knoten)
        $logic      = strtoupper($rule['logic'] ?? 'AND');
        $conditions = $rule['conditions'] ?? [];

        if (empty($conditions)) return true;

        foreach ($conditions as $cond) {
            $result = self::evaluate($cond, $ctx);
            if ($logic === 'OR'  && $result)  return true;
            if ($logic === 'AND' && !$result) return false;
        }

        return $logic === 'AND';
    }

    /**
     * Dispatcht Bedingungen nach Typ.
     */
    private static function evaluateCondition(array $cond, array $ctx): bool
    {
        $type = $cond['type'] ?? 'daily';

        return match ($type) {
            'marker_reference'   => self::evaluateMarkerReference($cond, $ctx),
            'period_aggregate'  => self::evaluatePeriodAggregate($cond, $ctx),
            'day_count'         => self::evaluateDayCount($cond, $ctx),
            'consecutive_days'  => self::evaluateConsecutiveDays($cond, $ctx),
            'temp_sum'          => self::evaluateTempSum($cond, $ctx),
            default             => self::evaluateDailyCondition($cond, $ctx),
        };
    }

    private static function evaluateMarkerReference(array $cond, array $ctx): bool
    {
        $markerId = (int) ($cond['marker_id'] ?? 0);
        if ($markerId <= 0) {
            return false;
        }

        $markers = $ctx['__markers'] ?? [];
        $stack = $ctx['__marker_stack'] ?? [];

        if (in_array($markerId, $stack, true)) {
            return false;
        }

        $marker = $markers[$markerId] ?? null;
        if ($marker === null) {
            return false;
        }

        $rules = self::resolveMarkerRules($marker);
        if ($rules === null) {
            return false;
        }

        $refCtx = $ctx;
        $refCtx['__marker_stack'] = array_merge($stack, [$markerId]);

        return self::evaluate($rules, $refCtx);
    }

    // ──────────────────────────────────────────────────
    // Typ 1: DAILY – Tageswert (bisheriges Verhalten)
    // ──────────────────────────────────────────────────

    private static function evaluateDailyCondition(array $cond, array $ctx): bool
    {
        $field    = $cond['field'] ?? '';
        $operator = $cond['operator'] ?? '>=';
        $value    = $cond['value'] ?? 0;

        $fieldValue = $ctx[$field] ?? null;
        if ($fieldValue === null) return false;

        return self::compare($fieldValue, $operator, $value);
    }

    // ──────────────────────────────────────────────────
    // Typ 2: PERIOD_AGGREGATE – Zeitraum-Aggregation
    // ──────────────────────────────────────────────────

    private static function evaluatePeriodAggregate(array $cond, array $ctx): bool
    {
        $locationId  = $ctx['location_id'] ?? 0;
        $currentYear = (int) date('Y', strtotime($ctx['date']));
        $year        = self::resolveYear($cond['year_ref'] ?? 'current', $currentYear);

        [$startDate, $endDate] = self::resolvePeriodDates($cond, $year);

        $field       = $cond['field'] ?? 'precipitation';
        $aggregation = $cond['aggregation'] ?? 'sum';
        $compareMode = $cond['compare_mode'] ?? 'absolute';
        $operator    = $cond['operator'] ?? '>=';
        $value       = $cond['value'] ?? 0;

        $cacheKey = "pa_{$locationId}_{$startDate}_{$endDate}_{$field}_{$aggregation}";
        if (isset(self::$periodCache[$cacheKey])) {
            $aggregatedValue = self::$periodCache[$cacheKey];
        } else {
            $rows = self::getWeatherDataForPeriod($locationId, $startDate, $endDate);
            $aggregatedValue = self::aggregateField($rows, $field, $aggregation);
            self::$periodCache[$cacheKey] = $aggregatedValue;
        }

        if ($aggregatedValue === null) return false;

        // Historischer Prozentwert-Vergleich
        if ($compareMode === 'historical_percent') {
            $histAvg = self::getHistoricalAvgForPeriod($locationId, $cond, $currentYear);
            if ($histAvg === null || $histAvg == 0) return false;
            $percentOfAvg = ($aggregatedValue / $histAvg) * 100;
            return self::compare($percentOfAvg, $operator, $value);
        }

        return self::compare($aggregatedValue, $operator, $value);
    }

    // ──────────────────────────────────────────────────
    // Typ 3: DAY_COUNT – Tage zählen
    // ──────────────────────────────────────────────────

    private static function evaluateDayCount(array $cond, array $ctx): bool
    {
        $locationId  = $ctx['location_id'] ?? 0;
        $currentYear = (int) date('Y', strtotime($ctx['date']));
        $year        = self::resolveYear($cond['year_ref'] ?? 'current', $currentYear);

        [$startDate, $endDate] = self::resolvePeriodDates($cond, $year);

        $field       = $cond['field'] ?? 'temp_max';
        $subOperator = $cond['sub_operator'] ?? '>';
        $subValue    = $cond['sub_value'] ?? 0;
        $operator    = $cond['operator'] ?? '>=';
        $value       = $cond['value'] ?? 0;

        $cacheKey = "dc_{$locationId}_{$startDate}_{$endDate}_{$field}_{$subOperator}_{$subValue}";
        if (isset(self::$periodCache[$cacheKey])) {
            $count = self::$periodCache[$cacheKey];
        } else {
            $rows  = self::getWeatherDataForPeriod($locationId, $startDate, $endDate);
            $count = 0;
            foreach ($rows as $row) {
                $fv = self::extractField($row, $field);
                if ($fv !== null && self::compare($fv, $subOperator, $subValue)) {
                    $count++;
                }
            }
            self::$periodCache[$cacheKey] = $count;
        }

        return self::compare($count, $operator, $value);
    }

    // ──────────────────────────────────────────────────
    // Typ 4: CONSECUTIVE_DAYS – Aufeinanderfolgende Tage
    // ──────────────────────────────────────────────────

    private static function evaluateConsecutiveDays(array $cond, array $ctx): bool
    {
        $locationId  = $ctx['location_id'] ?? 0;
        $currentYear = (int) date('Y', strtotime($ctx['date']));
        $year        = self::resolveYear($cond['year_ref'] ?? 'current', $currentYear);

        [$startDate, $endDate] = self::resolvePeriodDates($cond, $year);

        $field       = $cond['field'] ?? 'precipitation';
        $subOperator = $cond['sub_operator'] ?? '<';
        $subValue    = $cond['sub_value'] ?? 1;
        $operator    = $cond['operator'] ?? '>=';
        $value       = $cond['value'] ?? 7;

        $cacheKey = "cd_{$locationId}_{$startDate}_{$endDate}_{$field}_{$subOperator}_{$subValue}";
        if (isset(self::$periodCache[$cacheKey])) {
            $maxStreak = self::$periodCache[$cacheKey];
        } else {
            $rows      = self::getWeatherDataForPeriod($locationId, $startDate, $endDate);
            $maxStreak = 0;
            $current   = 0;

            foreach ($rows as $row) {
                $fv = self::extractField($row, $field);
                if ($fv !== null && self::compare($fv, $subOperator, $subValue)) {
                    $current++;
                    $maxStreak = max($maxStreak, $current);
                } else {
                    $current = 0;
                }
            }
            self::$periodCache[$cacheKey] = $maxStreak;
        }

        return self::compare($maxStreak, $operator, $value);
    }

    // ──────────────────────────────────────────────────
    // Typ 5: TEMP_SUM – Temperatursumme über Zeitraum
    // ──────────────────────────────────────────────────

    private static function evaluateTempSum(array $cond, array $ctx): bool
    {
        $locationId  = $ctx['location_id'] ?? 0;
        $currentYear = (int) date('Y', strtotime($ctx['date']));
        $year        = self::resolveYear($cond['year_ref'] ?? 'current', $currentYear);

        [$startDate, $endDate] = self::resolvePeriodDates($cond, $year);

        $field       = $cond['field'] ?? 'temp_mean';
        $threshold   = $cond['threshold'] ?? 0;      // Nur Tage über diesem Wert
        $compareMode = $cond['compare_mode'] ?? 'absolute';
        $operator    = $cond['operator'] ?? '>=';
        $value       = $cond['value'] ?? 0;

        $cacheKey = "ts_{$locationId}_{$startDate}_{$endDate}_{$field}_{$threshold}";
        if (isset(self::$periodCache[$cacheKey])) {
            $tempSum = self::$periodCache[$cacheKey];
        } else {
            $rows    = self::getWeatherDataForPeriod($locationId, $startDate, $endDate);
            $tempSum = 0;
            foreach ($rows as $row) {
                $fv = self::extractField($row, $field);
                if ($fv !== null && $fv > $threshold) {
                    $tempSum += ($fv - $threshold);
                }
            }
            $tempSum = round($tempSum, 2);
            self::$periodCache[$cacheKey] = $tempSum;
        }

        if ($compareMode === 'historical_percent') {
            $histAvg = self::getHistoricalTempSumForPeriod($locationId, $cond, $currentYear);
            if ($histAvg === null || $histAvg == 0) return false;
            $percentOfAvg = ($tempSum / $histAvg) * 100;
            return self::compare($percentOfAvg, $operator, $value);
        }

        return self::compare($tempSum, $operator, $value);
    }

    // ══════════════════════════════════════════════════
    // VERGLEICHSOPERATOREN
    // ══════════════════════════════════════════════════

    private static function compare($fieldValue, string $operator, $value): bool
    {
        return match ($operator) {
            '>'       => $fieldValue > $value,
            '<'       => $fieldValue < $value,
            '>='      => $fieldValue >= $value,
            '<='      => $fieldValue <= $value,
            '=='      => $fieldValue == $value,
            '!='      => $fieldValue != $value,
            'between' => is_array($value) && count($value) === 2
                         && $fieldValue >= $value[0] && $fieldValue <= $value[1],
            'in'      => is_array($value) && in_array($fieldValue, $value),
            default   => false,
        };
    }

    // ══════════════════════════════════════════════════
    // HILFSFUNKTIONEN FÜR ZEITRAUM-BEDINGUNGEN
    // ══════════════════════════════════════════════════

    /**
     * Löst die Jahresreferenz auf.
     */
    private static function resolveYear(string|int $yearRef, int $currentYear): int
    {
        if (is_numeric($yearRef) && (int)$yearRef > 1900) {
            return (int) $yearRef;
        }
        return match ((string) $yearRef) {
            'previous', 'prev', 'last' => $currentYear - 1,
            'current', 'this'          => $currentYear,
            default                    => $currentYear,
        };
    }

    /**
     * Berechnet Start- und Enddatum aus Perioden-Definition.
     * @return string[] [startDate, endDate] im Format YYYY-MM-DD
     */
    private static function resolvePeriodDates(array $cond, int $year): array
    {
        $periodType = $cond['period_type'] ?? 'full_year';

        return match ($periodType) {
            'month' => (function () use ($cond, $year) {
                $m = max(1, min(12, (int) ($cond['period_month'] ?? 1)));
                $start = sprintf('%d-%02d-01', $year, $m);
                $end   = date('Y-m-t', strtotime($start));
                return [$start, $end];
            })(),
            'date_range' => (function () use ($cond, $year) {
                $start = $year . '-' . self::sanitizeMonthDay($cond['period_start'] ?? null, '01-01');
                $end   = $year . '-' . self::sanitizeMonthDay($cond['period_end'] ?? null, '12-31');
                return [$start, $end];
            })(),
            'full_year' => ["$year-01-01", "$year-12-31"],
            default     => ["$year-01-01", "$year-12-31"],
        };
    }

    /**
     * Prüft ein Benutzer-Datum im Format MM-DD; ungültige Werte fallen auf den Default zurück.
     */
    private static function sanitizeMonthDay(mixed $value, string $default): string
    {
        if (is_string($value) && preg_match('/^(\d{2})-(\d{2})$/', $value, $m)
            && (int) $m[1] >= 1 && (int) $m[1] <= 12 && (int) $m[2] >= 1 && (int) $m[2] <= 31) {
            return $value;
        }
        return $default;
    }

    /**
     * Holt alle Wetterdaten für einen Zeitraum (History + Forecast kombiniert).
     * @return array[] Sortierte Zeilen mit allen Feldern
     */
    private static function getWeatherDataForPeriod(int $locationId, string $startDate, string $endDate): array
    {
        $cacheKey = "wdata_{$locationId}_{$startDate}_{$endDate}";
        if (isset(self::$periodCache[$cacheKey])) {
            return self::$periodCache[$cacheKey];
        }

        $db = getDB();
        $sql = "
            SELECT date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture
            FROM (
                SELECT date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture
                FROM weather_history
                WHERE location_id = :lid AND date BETWEEN :s AND :e
                UNION ALL
                SELECT date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture
                FROM weather_forecast
                WHERE location_id = :lid2 AND date BETWEEN :s2 AND :e2
                  AND date NOT IN (
                      SELECT date FROM weather_history
                      WHERE location_id = :lid3 AND date BETWEEN :s3 AND :e3
                  )
            ) combined
            ORDER BY date
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':lid'  => $locationId, ':s'  => $startDate, ':e'  => $endDate,
            ':lid2' => $locationId, ':s2' => $startDate, ':e2' => $endDate,
            ':lid3' => $locationId, ':s3' => $startDate, ':e3' => $endDate,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::$periodCache[$cacheKey] = $rows;
        return $rows;
    }

    /**
     * Extrahiert einen Feldwert aus einer DB-Zeile.
     */
    private static function extractField(array $row, string $field): ?float
    {
        // Direkte DB-Felder
        $directFields = ['temp_mean', 'temp_min', 'temp_max', 'precipitation', 'pressure', 'soil_moisture'];
        if (in_array($field, $directFields) && isset($row[$field])) {
            return (float) $row[$field];
        }
        // Berechnete Felder
        return match ($field) {
            'is_frost'      => isset($row['temp_min']) ? ((float)$row['temp_min'] < 0 ? 1.0 : 0.0) : null,
            'is_heavy_rain' => isset($row['precipitation']) ? ((float)$row['precipitation'] > 15 ? 1.0 : 0.0) : null,
            default         => isset($row[$field]) ? (float) $row[$field] : null,
        };
    }

    /**
     * Berechnet eine Aggregation über ein Feld.
     */
    private static function aggregateField(array $rows, string $field, string $aggregation): ?float
    {
        $values = [];
        foreach ($rows as $row) {
            $v = self::extractField($row, $field);
            if ($v !== null) $values[] = $v;
        }
        if (empty($values)) return null;

        return match ($aggregation) {
            'sum' => round(array_sum($values), 2),
            'avg' => round(array_sum($values) / count($values), 2),
            'min' => round(min($values), 2),
            'max' => round(max($values), 2),
            default => round(array_sum($values), 2),
        };
    }

    /**
     * Berechnet den langjährigen Durchschnitt einer Feld-Aggregation
     * über den selben Zeitraum (Monat + Tag) in allen Vorjahren.
     */
    private static function getHistoricalAvgForPeriod(int $locationId, array $cond, int $currentYear): ?float
    {
        $field       = $cond['field'] ?? 'precipitation';
        $aggregation = $cond['aggregation'] ?? 'sum';
        $years       = [];
        $compared    = self::resolveYear($cond['year_ref'] ?? 'current', $currentYear);

        $startHistYear = $currentYear - METEO_HISTORY_YEARS;
        for ($y = $startHistYear; $y < $currentYear; $y++) {
            if ($y === $compared) continue; // das verglichene Jahr gehört nicht in seine eigene Referenz
            [$s, $e] = self::resolvePeriodDates($cond, $y);
            $rows = self::getWeatherDataForPeriod($locationId, $s, $e);
            $agg  = self::aggregateField($rows, $field, $aggregation);
            if ($agg !== null) $years[] = $agg;
        }

        if (empty($years)) return null;
        return round(array_sum($years) / count($years), 2);
    }

    /**
     * Historischer Durchschnitt einer Temperatursumme für einen Zeitraum.
     */
    private static function getHistoricalTempSumForPeriod(int $locationId, array $cond, int $currentYear): ?float
    {
        $field     = $cond['field'] ?? 'temp_mean';
        $threshold = $cond['threshold'] ?? 0;
        $sums      = [];
        $compared  = self::resolveYear($cond['year_ref'] ?? 'current', $currentYear);

        $startHistYear = $currentYear - METEO_HISTORY_YEARS;
        for ($y = $startHistYear; $y < $currentYear; $y++) {
            if ($y === $compared) continue; // das verglichene Jahr gehört nicht in seine eigene Referenz
            [$s, $e] = self::resolvePeriodDates($cond, $y);
            $rows = self::getWeatherDataForPeriod($locationId, $s, $e);
            $ts   = 0;
            foreach ($rows as $row) {
                $fv = self::extractField($row, $field);
                if ($fv !== null && $fv > $threshold) $ts += ($fv - $threshold);
            }
            $sums[] = round($ts, 2);
        }

        if (empty($sums)) return null;
        return round(array_sum($sums) / count($sums), 2);
    }

    /**
     * API-Methode: Gibt historische Durchschnitte für eine Perioden-Definition
     * zurück (für Frontend-Preview).
     */
    public static function getHistoricalStats(int $locationId, array $condDef): array
    {
        $currentYear   = (int) date('Y');
        $type          = $condDef['type'] ?? 'period_aggregate';
        $field         = $condDef['field'] ?? 'precipitation';
        $aggregation   = $condDef['aggregation'] ?? 'sum';
        $startHistYear = $currentYear - METEO_HISTORY_YEARS;
        // Nur bei Prozentvergleich: das verglichene Jahr (z.B. "previous") aus der Referenz nehmen,
        // damit die Vorschau dieselbe Referenz zeigt, die die Auswertung benutzt.
        $excludeYear = ($condDef['compare_mode'] ?? 'absolute') === 'historical_percent'
            ? self::resolveYear($condDef['year_ref'] ?? 'current', $currentYear)
            : null;

        $yearlyValues = [];
        for ($y = $startHistYear; $y < $currentYear; $y++) {
            if ($y === $excludeYear) continue;
            [$s, $e] = self::resolvePeriodDates($condDef, $y);
            $rows = self::getWeatherDataForPeriod($locationId, $s, $e);

            $val = null;

            if ($type === 'temp_sum') {
                // Temperatursumme über Schwellwert
                $threshold = $condDef['threshold'] ?? 0;
                $ts = 0;
                foreach ($rows as $row) {
                    $fv = self::extractField($row, $field);
                    if ($fv !== null && $fv > $threshold) {
                        $ts += ($fv - $threshold);
                    }
                }
                $val = round($ts, 2);
            } elseif ($type === 'day_count') {
                // Tage zählen
                $subOp  = $condDef['sub_operator'] ?? '>';
                $subVal = $condDef['sub_value'] ?? 0;
                $count  = 0;
                foreach ($rows as $row) {
                    $fv = self::extractField($row, $field);
                    if ($fv !== null && self::compare($fv, $subOp, $subVal)) {
                        $count++;
                    }
                }
                $val = $count;
            } elseif ($type === 'consecutive_days') {
                // Längste Serie
                $subOp  = $condDef['sub_operator'] ?? '<';
                $subVal = $condDef['sub_value'] ?? 1;
                $maxStreak = 0;
                $current   = 0;
                foreach ($rows as $row) {
                    $fv = self::extractField($row, $field);
                    if ($fv !== null && self::compare($fv, $subOp, $subVal)) {
                        $current++;
                        $maxStreak = max($maxStreak, $current);
                    } else {
                        $current = 0;
                    }
                }
                $val = $maxStreak;
            } else {
                // period_aggregate (Standard)
                $val = self::aggregateField($rows, $field, $aggregation);
            }

            if ($val !== null) {
                $yearlyValues[$y] = $val;
            }
        }

        $values = array_values($yearlyValues);
        if (empty($values)) {
            return ['avg' => null, 'min' => null, 'max' => null, 'years' => []];
        }

        return [
            'avg'   => round(array_sum($values) / count($values), 2),
            'min'   => round(min($values), 2),
            'max'   => round(max($values), 2),
            'years' => $yearlyValues,
        ];
    }

    // ══════════════════════════════════════════════════
    // KONTEXT-BUILDER
    // ══════════════════════════════════════════════════

    public static function buildContext(
        int    $locationId,
        string $date,
        ?array $gtsEntry,
        array  $allData = [],
        ?array $historicalAvg = null
    ): array {
        $dt    = strtotime($date);
        $month = (int) date('n', $dt);
        $doy   = (int) date('z', $dt) + 1;
        $today = strtotime(date('Y-m-d'));

        $ctx = [
            'location_id'   => $locationId,
            'date'          => $date,
            'gts'           => $gtsEntry['gts'] ?? 0,
            'temp_mean'     => $gtsEntry['temp_mean'] ?? null,
            'precipitation' => $gtsEntry['precipitation'] ?? 0,
            'month'         => $month,
            'day_of_year'   => $doy,
            'is_forecast'   => $dt > $today ? 1 : 0,
        ];

        $dayData = self::getDayWeatherData($locationId, $date);
        if ($dayData) {
            $ctx['temp_min']       = (float) $dayData['temp_min'];
            $ctx['temp_max']       = (float) $dayData['temp_max'];
            $ctx['pressure']       = (float) $dayData['pressure'];
            $ctx['soil_moisture']  = (float) $dayData['soil_moisture'];
            $ctx['is_frost']       = ($ctx['temp_min'] < 0) ? 1 : 0;
            $ctx['is_heavy_rain']  = ($ctx['precipitation'] > 15) ? 1 : 0;
        } else {
            $ctx['temp_min']       = null;
            $ctx['temp_max']       = null;
            $ctx['pressure']       = null;
            $ctx['soil_moisture']  = null;
            $ctx['is_frost']       = 0;
            $ctx['is_heavy_rain']  = 0;
        }

        $ctx = array_merge($ctx, self::computeWindowFields($locationId, $date, $allData));

        // Wenn keine historischen Durchschnitte übergeben, selbst berechnen
        if ($historicalAvg === null) {
            $historicalAvg = self::computeHistoricalAvgForDay($locationId, $date);
        }

        $ctx['temp_deviation']   = ($ctx['temp_mean'] !== null && $historicalAvg['avg_temp'] !== null)
            ? round($ctx['temp_mean'] - $historicalAvg['avg_temp'], 2) : 0;
        $ctx['precip_deviation'] = ($historicalAvg['avg_precip_day'] !== null)
            ? round($ctx['precipitation'] - $historicalAvg['avg_precip_day'], 2) : 0;

        return $ctx;
    }

    private static function getDayWeatherData(int $locationId, string $date): ?array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT temp_min, temp_max, pressure, soil_moisture, precipitation
            FROM weather_history WHERE location_id = :lid AND date = :d LIMIT 1
        ");
        $stmt->execute([':lid' => $locationId, ':d' => $date]);
        $row = $stmt->fetch();
        if (!$row) {
            $stmt = $db->prepare("
                SELECT temp_min, temp_max, pressure, soil_moisture, precipitation
                FROM weather_forecast WHERE location_id = :lid AND date = :d LIMIT 1
            ");
            $stmt->execute([':lid' => $locationId, ':d' => $date]);
            $row = $stmt->fetch();
        }
        return $row ?: null;
    }

    /**
     * Berechnet historische Durchschnittswerte für einen bestimmten Tag.
     * Nutzt zuerst Klimanormale aus der climate_normals-Tabelle (30-Jahres-Ø via API),
     * falls vorhanden. Fällt sonst auf weather_history (±7 Tage Fenster) zurück.
     * Ergebnis wird gecacht um wiederholte Queries zu vermeiden.
     */
    private static function computeHistoricalAvgForDay(int $locationId, string $date): array
    {
        $cacheKey = "hist_avg_{$locationId}_{$date}";
        if (isset(self::$periodCache[$cacheKey])) {
            return self::$periodCache[$cacheKey];
        }

        $month = (int) date('n', strtotime($date));
        $db    = getDB();

        // Versuche zuerst Klimanormale aus API-Daten (genauer, 30 Jahre)
        $normalCacheKey = "climate_normal_{$locationId}_{$month}";
        if (!isset(self::$periodCache[$normalCacheKey])) {
            $stmt = $db->prepare("SELECT avg_temp, avg_precip FROM climate_normals
                                  WHERE location_id = :lid AND month = :m LIMIT 1");
            $stmt->execute([':lid' => $locationId, ':m' => $month]);
            self::$periodCache[$normalCacheKey] = $stmt->fetch() ?: null;
        }

        $normal = self::$periodCache[$normalCacheKey];
        if ($normal && $normal['avg_temp'] !== null) {
            // avg_precip ist der Monatstotal-Ø → teile durch Anzahl Tage im Monat für Tages-Ø
            $daysInMonth = (int) date('t', strtotime($date));
            $result = [
                'avg_temp'       => round((float) $normal['avg_temp'], 2),
                'avg_precip_day' => round((float) $normal['avg_precip'] / $daysInMonth, 2),
            ];
            self::$periodCache[$cacheKey] = $result;
            return $result;
        }

        // Fallback: weather_history (±7 Tage um den Kalendertag)
        $monthDay    = date('m-d', strtotime($date));
        $currentYear = (int) date('Y', strtotime($date));
        $refDoy      = (int) date('z', strtotime("{$currentYear}-{$monthDay}")) + 1;

        $stmt = $db->prepare("
            SELECT AVG(temp_mean) as avg_temp, AVG(precipitation) as avg_precip_day
            FROM weather_history
            WHERE location_id = :lid
              AND YEAR(date) < :year
              AND DAYOFYEAR(date) BETWEEN :doyStart AND :doyEnd
        ");
        $stmt->execute([
            ':lid'      => $locationId,
            ':year'     => $currentYear,
            ':doyStart' => $refDoy - 7,
            ':doyEnd'   => $refDoy + 7,
        ]);
        $row = $stmt->fetch();

        $result = [
            'avg_temp'      => $row && $row['avg_temp'] !== null ? round((float) $row['avg_temp'], 2) : null,
            'avg_precip_day' => $row && $row['avg_precip_day'] !== null ? round((float) $row['avg_precip_day'], 2) : null,
        ];

        self::$periodCache[$cacheKey] = $result;
        return $result;
    }

    private static function computeWindowFields(int $locationId, string $date, array $allData): array
    {
        $fields = [];
        $windows = [2, 3, 5, 7];
        $maxWindow = max($windows);

        // GTS-Daten indexieren (enthalten temp_mean, precipitation)
        $dataByDate = [];
        foreach ($allData as $entry) {
            $dataByDate[$entry['date']] = $entry;
        }

        // Alle benötigten Daten für das größte Fenster mit einer Query laden
        $startDate = date('Y-m-d', strtotime($date . " -" . ($maxWindow - 1) . " days"));
        $cacheKey  = "wd_{$locationId}_{$startDate}_{$date}";

        if (!isset(self::$periodCache[$cacheKey])) {
            $db = getDB();
            $sql = "
                SELECT date, temp_min, temp_max
                FROM (
                    SELECT date, temp_min, temp_max FROM weather_history
                    WHERE location_id = :lid AND date BETWEEN :s AND :e
                    UNION ALL
                    SELECT date, temp_min, temp_max FROM weather_forecast
                    WHERE location_id = :lid2 AND date BETWEEN :s2 AND :e2
                      AND date NOT IN (
                          SELECT date FROM weather_history
                          WHERE location_id = :lid3 AND date BETWEEN :s3 AND :e3
                      )
                ) combined
                ORDER BY date
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':lid'  => $locationId, ':s'  => $startDate, ':e'  => $date,
                ':lid2' => $locationId, ':s2' => $startDate, ':e2' => $date,
                ':lid3' => $locationId, ':s3' => $startDate, ':e3' => $date,
            ]);
            $windowRows = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $windowRows[$row['date']] = $row;
            }
            self::$periodCache[$cacheKey] = $windowRows;
        }

        $windowData = self::$periodCache[$cacheKey];

        foreach ($windows as $n) {
            $precipSum = 0; $tempSum = 0;
            $tempMinMin = PHP_FLOAT_MAX; $tempMaxMax = -PHP_FLOAT_MAX;
            $count = 0;

            for ($i = 0; $i < $n; $i++) {
                $d = date('Y-m-d', strtotime($date . " -$i days"));
                if (isset($dataByDate[$d])) {
                    $precipSum += $dataByDate[$d]['precipitation'] ?? 0;
                    $tempSum   += $dataByDate[$d]['temp_mean'] ?? 0;
                    $count++;
                }
                if (isset($windowData[$d])) {
                    $tempMinMin = min($tempMinMin, (float) $windowData[$d]['temp_min']);
                    $tempMaxMax = max($tempMaxMax, (float) $windowData[$d]['temp_max']);
                }
            }

            $fields["precip_sum_{$n}d"]    = round($precipSum, 2);
            $fields["temp_mean_avg_{$n}d"] = $count > 0 ? round($tempSum / $count, 2) : 0;
            $fields["temp_min_min_{$n}d"]  = $tempMinMin !== PHP_FLOAT_MAX ? round($tempMinMin, 2) : null;
            $fields["temp_max_max_{$n}d"]  = $tempMaxMax !== -PHP_FLOAT_MAX ? round($tempMaxMax, 2) : null;
        }

        return $fields;
    }

    // ══════════════════════════════════════════════════
    // MARKER-VORHERSAGE
    // ══════════════════════════════════════════════════

    public static function predictMarkers(int $locationId, int $userId, array $gtsData): array
    {
        require_once __DIR__ . '/../Services/MarkerService.php';
        self::clearCache();

        $markers     = MarkerService::getAllByUser($userId);
        $markersById = [];
        foreach ($markers as $marker) {
            $markersById[(int) $marker['id']] = $marker;
        }
        $today       = date('Y-m-d');
        $predictions = [];

        foreach ($markers as $marker) {
            $rules = self::resolveMarkerRules($marker);
            if ($rules === null) continue;

            // Prüfen ob der Marker Perioden-Bedingungen hat
            // (die nicht tagesabhängig ausgewertet werden)
            $hasDailyConditions  = self::hasConditionType($rules, ['daily', '']);
            $hasPeriodConditions = self::hasConditionType($rules, ['period_aggregate', 'day_count', 'consecutive_days', 'temp_sum']);

            // Wenn NUR Perioden-Bedingungen: einmalig evaluieren
            if ($hasPeriodConditions && !$hasDailyConditions) {
                $ctx = self::buildContext($locationId, $today, null, $gtsData);
                $ctx['__markers'] = $markersById;
                $ctx['__marker_stack'] = [(int) $marker['id']];
                $triggered = self::evaluate($rules, $ctx);

                if ($triggered) {
                    $predictions[] = [
                        'marker'     => $marker,
                        'date'       => $today,
                        'days_until' => 0,
                        'status'     => 'active',
                        'message'    => $marker['alert_message'] ?? $marker['description'] ?? '',
                    ];
                }
                continue;
            }

            // Tagesweise Auswertung
            $firstTriggered    = null;
            $isCurrentlyActive = false;

            foreach ($gtsData as $entry) {
                $ctx       = self::buildContext($locationId, $entry['date'], $entry, $gtsData);
                $ctx['__markers'] = $markersById;
                $ctx['__marker_stack'] = [(int) $marker['id']];
                $triggered = self::evaluate($rules, $ctx);

                if ($triggered && $firstTriggered === null) {
                    $firstTriggered = $entry['date'];
                }
                if ($entry['date'] === $today && $triggered) {
                    $isCurrentlyActive = true;
                }
            }

            if ($firstTriggered === null) continue;

            $daysUntil = self::dayDiff($today, $firstTriggered);

            if (self::shouldExpireAfter21Days($rules) && $daysUntil < -21) {
                continue;
            }

            $status    = $daysUntil > 0 ? 'upcoming' : ($isCurrentlyActive ? 'active' : 'reached');

            $predictions[] = [
                'marker'     => $marker,
                'date'       => $firstTriggered,
                'days_until' => $daysUntil,
                'status'     => $status,
                'message'    => $marker['alert_message'] ?? $marker['description'] ?? '',
            ];
        }

        usort($predictions, function ($a, $b) {
            $order = ['upcoming' => 0, 'active' => 1, 'reached' => 2];
            $diff  = ($order[$a['status']] ?? 3) - ($order[$b['status']] ?? 3);
            return $diff !== 0 ? $diff : $a['days_until'] - $b['days_until'];
        });

        return $predictions;
    }

    public static function predictGTSRuleTransitions(int $locationId, int $userId, array $gtsData): array
    {
        require_once __DIR__ . '/../Services/MarkerService.php';
        self::clearCache();

        $markers = MarkerService::getAllByUser($userId);
        $today = date('Y-m-d');
        $events = [];

        foreach ($markers as $marker) {
            $rules = self::resolveMarkerRules($marker);
            if ($rules === null || !self::isPureGTSMultiRule($rules)) {
                continue;
            }

            $previousTriggered = false;

            foreach ($gtsData as $entry) {
                $ctx = self::buildContext($locationId, $entry['date'], $entry, $gtsData);
                $triggered = self::evaluate($rules, $ctx);

                if ($entry['date'] >= $today) {
                    if ($triggered && !$previousTriggered) {
                        $events[] = self::buildTransitionEvent($marker, $entry, $today, 'begin');
                    } elseif (!$triggered && $previousTriggered) {
                        $events[] = self::buildTransitionEvent($marker, $entry, $today, 'end');
                    }
                }

                $previousTriggered = $triggered;
            }
        }

        usort($events, function ($a, $b) {
            $diff = $a['days_until'] - $b['days_until'];
            if ($diff !== 0) {
                return $diff;
            }
            return strcmp($a['title'], $b['title']);
        });

        return $events;
    }

    public static function evaluateForDate(int $locationId, int $userId, string $date, array $gtsData): array
    {
        require_once __DIR__ . '/../Services/MarkerService.php';
        self::clearCache();

        $markers  = MarkerService::getAllByUser($userId);
        $markersById = [];
        foreach ($markers as $marker) {
            $markersById[(int) $marker['id']] = $marker;
        }
        $gtsEntry = null;
        foreach ($gtsData as $entry) {
            if ($entry['date'] === $date) { $gtsEntry = $entry; break; }
        }

        $ctx       = self::buildContext($locationId, $date, $gtsEntry, $gtsData);
        $triggered = [];

        foreach ($markers as $marker) {
            $rules = self::resolveMarkerRules($marker);
            if ($rules === null) continue;

            $markerCtx = $ctx;
            $markerCtx['__markers'] = $markersById;
            $markerCtx['__marker_stack'] = [(int) $marker['id']];

            if (self::evaluate($rules, $markerCtx)) {
                $triggered[] = [
                    'marker'  => $marker,
                    'context' => $markerCtx,
                    'message' => $marker['alert_message'] ?? $marker['description'] ?? '',
                ];
            }
        }

        return $triggered;
    }

    // ══════════════════════════════════════════════════
    // INTERNE HELFER
    // ══════════════════════════════════════════════════

    private static function resolveMarkerRules(array $marker): ?array
    {
        if (!empty($marker['rules'])) {
            return is_string($marker['rules']) ? json_decode($marker['rules'], true) : $marker['rules'];
        }
        if ($marker['type'] === 'gts' && $marker['threshold_value'] !== null) {
            return [
                'logic'      => 'AND',
                'conditions' => [
                    ['field' => 'gts', 'operator' => '>=', 'value' => (float) $marker['threshold_value']],
                ],
            ];
        }
        return null;
    }

    private static function shouldExpireAfter21Days(array $rule): bool
    {
        $conditions = self::collectLeafConditions($rule);
        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? 'daily';
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? null;

            if (($type !== 'daily' && $type !== '') || $field !== 'gts') {
                return false;
            }

            if (!in_array($operator, ['>', '>='], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kalendertage von $from bis $to (negativ, wenn $to vor $from liegt).
     * Bewusst nicht über strtotime()/86400: über die Sommerzeit-Umstellung hinweg
     * hat ein Tag 23 oder 25 Stunden, die Ganzzahl-Division wäre um einen Tag daneben.
     */
    public static function dayDiff(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC'));
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    private static function buildTransitionEvent(array $marker, array $entry, string $today, string $kind): array
    {
        $daysUntil = self::dayDiff($today, $entry['date']);

        return [
            'marker'       => $marker,
            'date'         => $entry['date'],
            'days_until'   => $daysUntil,
            'kind'         => $kind,
            'title'        => ($kind === 'begin' ? 'Beginn ' : 'Ende ') . $marker['name'],
            'gts'          => isset($entry['gts']) ? round((float) $entry['gts'], 2) : null,
            'contribution' => isset($entry['contribution']) ? round((float) $entry['contribution'], 2) : null,
        ];
    }

    private static function isPureGTSMultiRule(array $rule): bool
    {
        if (strtoupper($rule['logic'] ?? 'AND') !== 'AND') {
            return false;
        }

        $conditions = self::collectLeafConditions($rule);
        if (count($conditions) < 2) {
            return false;
        }

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? 'daily';
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? null;

            if (($type !== 'daily' && $type !== '') || $field !== 'gts') {
                return false;
            }

            if (!in_array($operator, ['>', '<', '>=', '<=', '=='], true)) {
                return false;
            }
        }

        return true;
    }

    private static function collectLeafConditions(array $rule): array
    {
        if (isset($rule['field']) || isset($rule['type'])) {
            return [$rule];
        }

        $leaves = [];
        foreach ($rule['conditions'] ?? [] as $condition) {
            $leaves = array_merge($leaves, self::collectLeafConditions($condition));
        }

        return $leaves;
    }

    /**
     * Prüft ob ein Regelbaum mindestens eine Bedingung der angegebenen Typen enthält.
     */
    private static function hasConditionType(array $rule, array $types): bool
    {
        if (isset($rule['field']) || isset($rule['type'])) {
            $type = $rule['type'] ?? 'daily';
            if ($type === '' || $type === null) $type = 'daily';
            return in_array($type, $types);
        }
        foreach ($rule['conditions'] ?? [] as $c) {
            if (self::hasConditionType($c, $types)) return true;
        }
        return false;
    }

    // ══════════════════════════════════════════════════
    // VORLAGEN FÜR KOMPLEXE MARKER
    // ══════════════════════════════════════════════════

    public static function getTemplates(): array
    {
        return [
            // ── Tageswert-basierte Marker ──
            [
                'name'          => 'Spätfrost-Gefahr bei Obstblüte',
                'severity'      => 'critical',
                'color'         => '#ef4444',
                'type'          => 'complex',
                'alert_message' => 'Achtung: Morgenfrost bei laufender Obstblüte! Blüten wahrscheinlich geschädigt.',
                'description'   => 'Frost (temp_min < 0°C) bei GTS > 300',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        ['type' => 'daily', 'field' => 'gts',      'operator' => '>=', 'value' => 300],
                        ['type' => 'daily', 'field' => 'temp_min', 'operator' => '<',  'value' => 0],
                    ],
                ],
            ],
            [
                'name'          => 'Platzregen spült Honigtau ab',
                'severity'      => 'warning',
                'color'         => '#f97316',
                'type'          => 'complex',
                'alert_message' => 'Starkregen in der Waldtrachtsaison – Honigtau abgespült, mind. 2 Tage kaum Eintrag.',
                'description'   => 'Starkregen (>15mm) im Juni/Juli',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        ['type' => 'daily', 'field' => 'month',         'operator' => 'in', 'value' => [6, 7]],
                        ['type' => 'daily', 'field' => 'precipitation', 'operator' => '>',  'value' => 15],
                    ],
                ],
            ],
            [
                'name'          => 'Schwarmwetter',
                'severity'      => 'warning',
                'color'         => '#f59e0b',
                'type'          => 'complex',
                'alert_message' => 'Typisches Schwarmwetter: warm nach Regenphase. Völker kontrollieren!',
                'description'   => 'Mai/Juni, >22°C, Regen in letzten 3 Tagen, dann trocken',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        ['type' => 'daily', 'field' => 'month',         'operator' => 'in', 'value' => [5, 6]],
                        ['type' => 'daily', 'field' => 'temp_max',      'operator' => '>=', 'value' => 22],
                        ['type' => 'daily', 'field' => 'precip_sum_3d', 'operator' => '>=', 'value' => 5],
                        ['type' => 'daily', 'field' => 'precipitation', 'operator' => '<',  'value' => 1],
                    ],
                ],
            ],
            [
                'name'          => 'Rapstracht möglich',
                'severity'      => 'info',
                'color'         => '#fde047',
                'type'          => 'complex',
                'alert_message' => 'GTS im Raps-Blühniveau. Rapstracht möglich wenn Raps in Flugreichweite.',
                'description'   => 'GTS 350-500, Temp > 12°C',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        ['type' => 'daily', 'field' => 'gts',       'operator' => 'between', 'value' => [350, 500]],
                        ['type' => 'daily', 'field' => 'temp_mean', 'operator' => '>=',      'value' => 12],
                    ],
                ],
            ],

            // ── Zeitraum-Aggregationen ──
            [
                'name'          => 'Herbst-Niederschlag Vorjahr überdurchschnittlich',
                'severity'      => 'info',
                'color'         => '#0ea5e9',
                'type'          => 'complex',
                'alert_message' => 'Vorjahres-Herbst hatte überdurchschnittlich viel Niederschlag – Bodenfeuchte hoch, gute Voraussetzung für Frühtracht.',
                'description'   => 'Vorjahr Okt: Niederschlag > 120% des langjährigen Ø',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        [
                            'type'         => 'period_aggregate',
                            'year_ref'     => 'previous',
                            'period_type'  => 'date_range',
                            'period_start' => '10-01',
                            'period_end'   => '10-31',
                            'field'        => 'precipitation',
                            'aggregation'  => 'sum',
                            'compare_mode' => 'historical_percent',
                            'operator'     => '>',
                            'value'        => 120,
                        ],
                    ],
                ],
            ],
            [
                'name'          => 'Warmer April – viele Flugtage',
                'severity'      => 'info',
                'color'         => '#22c55e',
                'type'          => 'complex',
                'alert_message' => 'Überdurchschnittlich viele warme Tage im April – hervorragende Bedingungen für Frühtracht!',
                'description'   => 'April: mehr als 20 Tage mit Tageshöchstwert > 15°C',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        [
                            'type'         => 'day_count',
                            'year_ref'     => 'current',
                            'period_type'  => 'month',
                            'period_month' => 4,
                            'field'        => 'temp_max',
                            'sub_operator' => '>',
                            'sub_value'    => 15,
                            'operator'     => '>=',
                            'value'        => 20,
                        ],
                    ],
                ],
            ],
            [
                'name'          => 'Anhaltende Trockenphase Sommer',
                'severity'      => 'warning',
                'color'         => '#eab308',
                'type'          => 'complex',
                'alert_message' => 'Mehr als 7 aufeinanderfolgende Tage ohne nennenswerten Regen im Sommer – Trachtlücke wahrscheinlich, Fütterung prüfen!',
                'description'   => 'Juni-Aug: ≥7 aufeinanderfolgende Tage mit < 1mm Regen',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        [
                            'type'         => 'consecutive_days',
                            'year_ref'     => 'current',
                            'period_type'  => 'date_range',
                            'period_start' => '06-01',
                            'period_end'   => '08-31',
                            'field'        => 'precipitation',
                            'sub_operator' => '<',
                            'sub_value'    => 1,
                            'operator'     => '>=',
                            'value'        => 7,
                        ],
                    ],
                ],
            ],
            [
                'name'          => 'Wintertemperatursumme zu hoch',
                'severity'      => 'warning',
                'color'         => '#6366f1',
                'type'          => 'complex',
                'alert_message' => 'Warmer Winter: hohe Temperatursumme Jan-Feb über dem Schwellwert – früherer Vegetationsbeginn wahrscheinlich, Varroa-Behandlung prüfen.',
                'description'   => 'Temperatursumme Jan-Feb (>0°C) übertrifft 150% des langjährigen Ø',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        [
                            'type'         => 'temp_sum',
                            'year_ref'     => 'current',
                            'period_type'  => 'date_range',
                            'period_start' => '01-01',
                            'period_end'   => '02-28',
                            'field'        => 'temp_mean',
                            'threshold'    => 0,
                            'compare_mode' => 'historical_percent',
                            'operator'     => '>',
                            'value'        => 150,
                        ],
                    ],
                ],
            ],

            // ── Kombinierte Marker (tageswert + zeitraum) ──
            [
                'name'          => 'Frost + Obstblüte + nasser Vorwinter',
                'severity'      => 'critical',
                'color'         => '#dc2626',
                'type'          => 'complex',
                'alert_message' => 'KRITISCH: Frost bei Obstblüte und die Bäume hatten gute Wasserversorgung im Herbst – Frostschaden besonders wahrscheinlich!',
                'description'   => 'Kombination: Tages-Frost bei GTS>300 + Vorjahr Herbst überdurchschnittlich feucht',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        ['type' => 'daily', 'field' => 'gts',      'operator' => '>=', 'value' => 300],
                        ['type' => 'daily', 'field' => 'temp_min', 'operator' => '<',  'value' => 0],
                        [
                            'type'         => 'period_aggregate',
                            'year_ref'     => 'previous',
                            'period_type'  => 'date_range',
                            'period_start' => '09-01',
                            'period_end'   => '11-30',
                            'field'        => 'precipitation',
                            'aggregation'  => 'sum',
                            'compare_mode' => 'historical_percent',
                            'operator'     => '>',
                            'value'        => 100,
                        ],
                    ],
                ],
            ],
            [
                'name'          => 'Ideale Waldtracht – warm, feucht, schwül',
                'severity'      => 'info',
                'color'         => '#10b981',
                'type'          => 'complex',
                'alert_message' => 'Optimale Waldtracht-Bedingungen: warm, feuchter Boden, genug Frosttage im Winter für Läuse-Population.',
                'description'   => 'Tagesmittel >20°C, Bodenfeuchte >0.25, Winter hatte >30 Frosttage',
                'rules'         => [
                    'logic' => 'AND',
                    'conditions' => [
                        ['type' => 'daily', 'field' => 'temp_mean',     'operator' => '>=', 'value' => 20],
                        ['type' => 'daily', 'field' => 'soil_moisture', 'operator' => '>=', 'value' => 0.25],
                        ['type' => 'daily', 'field' => 'month',         'operator' => 'in', 'value' => [6, 7]],
                        [
                            'type'         => 'day_count',
                            'year_ref'     => 'current',
                            'period_type'  => 'date_range',
                            'period_start' => '01-01',
                            'period_end'   => '02-28',
                            'field'        => 'temp_min',
                            'sub_operator' => '<',
                            'sub_value'    => 0,
                            'operator'     => '>=',
                            'value'        => 30,
                        ],
                    ],
                ],
            ],
        ];
    }
}
