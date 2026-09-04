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
 * Grünlandtemperatursumme (GTS) Service
 *
 * Logik:
 *   Summe aller positiven Tagesmitteltemperaturen ab 1. Januar
 *   Gewichtung: Januar × 0.5, Februar × 0.75, ab März × 1.0
 */

require_once __DIR__ . '/../../config/database.php';

class GTSService
{
    /**
     * Berechnet die kumulative GTS für ein bestimmtes Jahr und einen Standort.
     * Gibt ein Array mit ['date' => ..., 'gts' => ..., 'temp_mean' => ..., 'precipitation' => ...] zurück.
     */
    public static function calculate(int $locationId, int $year): array
    {
        $db = getDB();
        $startStr = "$year-01-01";
        $endStr = "$year-12-31";

        // Historische + Forecast-Daten kombinieren
        $sql = "
            SELECT date, temp_mean, precipitation
            FROM (
                SELECT date, temp_mean, precipitation FROM weather_history      
                WHERE location_id = :lid AND date >= :s1 AND date <= :e1
                UNION ALL
                SELECT date, temp_mean, precipitation FROM weather_forecast     
                WHERE location_id = :lid2 AND date >= :s2 AND date <= :e2
                  AND date NOT IN (
                      SELECT date FROM weather_history
                      WHERE location_id = :lid3 AND date >= :s3 AND date <= :e3
                  )
            ) combined
            ORDER BY date
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':lid'  => $locationId,
            ':s1'   => $startStr,
            ':e1'   => $endStr,
            ':lid2' => $locationId,
            ':s2'   => $startStr,
            ':e2'   => $endStr,
            ':lid3' => $locationId,
            ':s3'   => $startStr,
            ':e3'   => $endStr,
        ]);
        
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $cumulative = 0.0;
        $results    = [];

        foreach ($rows as $row) {
            $tempMean = (float) $row['temp_mean'];
            $month    = (int) date('n', strtotime($row['date']));

            // Gewichtungsfaktor
            $factor = match (true) {
                $month === 1  => 0.5,
                $month === 2  => 0.75,
                default       => 1.0,
            };

            // Nur positive Temperaturen zählen
            $contribution = 0.0;
            if ($tempMean > 0) {
                $contribution = $tempMean * $factor;
            }

            $cumulative += $contribution;

            $results[] = [
                'date'          => $row['date'],
                'temp_mean'     => $tempMean,
                'precipitation' => (float) $row['precipitation'],
                'factor'        => $factor,
                'contribution'  => round($contribution, 2),
                'gts'           => round($cumulative, 2),
            ];
        }

        return $results;
    }

    /**
     * GTS an einem bestimmten Tag.
     */
    public static function getForDate(int $locationId, string $date): ?array
    {
        $year = (int) date('Y', strtotime($date));
        $all  = self::calculate($locationId, $year);

        foreach ($all as $entry) {
            if ($entry['date'] === $date) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * GTS für einen Datumsbereich (Kalenderansicht).
     */
    public static function getForRange(int $locationId, string $startDate, string $endDate): array
    {
        $year = (int) date('Y', strtotime($startDate));
        $all  = self::calculate($locationId, $year);

        return array_filter($all, function ($entry) use ($startDate, $endDate) {
            return $entry['date'] >= $startDate && $entry['date'] <= $endDate;
        });
    }

    /**
     * Vergleicht die GTS des aktuellen Jahres mit dem Durchschnitt der Vorjahre.
     */
    public static function getHistoricalComparison(int $locationId, int $currentYear): array
    {
        $startYear = $currentYear - METEO_HISTORY_YEARS;
        $db = getDB();

        $startStr = "$startYear-01-01";
        $endStr = ($currentYear - 1) . "-12-31";

        // Alle relevanten historischen Daten auf einmal laden (viel schneller als N = 11 Einzelabfragen)
        $sql = "
            SELECT date, temp_mean
            FROM weather_history
            WHERE location_id = :lid AND date >= :startStr AND date <= :endStr
            ORDER BY date ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':lid'      => $locationId,
            ':startStr' => $startStr,
            ':endStr'   => $endStr
        ]);
        
        $rows = $stmt->fetchAll();
        $yearsData = [];
        
        // Zunächst in Jahre und Tage gruppieren
        foreach ($rows as $row) {
            $year = (int)date('Y', strtotime($row['date']));
            $yearsData[$year][] = $row;
        }

        $historicalByDOY = [];
        
        // GTS pro Jahr berechnen
        foreach ($yearsData as $y => $dayRows) {
            $cumulative = 0.0;
            foreach ($dayRows as $row) {
                $tempMean = (float) $row['temp_mean'];
                $month = (int) date('n', strtotime($row['date']));
                $doy = (int) date('z', strtotime($row['date'])) + 1; // 1-366
                
                $factor = match (true) {
                    $month === 1 => 0.5,
                    $month === 2 => 0.75,
                    default      => 1.0,
                };
                
                if ($tempMean > 0) {
                    $cumulative += $tempMean * $factor;
                }
                
                if (!isset($historicalByDOY[$doy])) {
                    $historicalByDOY[$doy] = [];
                }
                $historicalByDOY[$doy][] = $cumulative;
            }
        }

        // Durchschnitt aller Jahre pro Tag
        $avgByDOY = [];
        foreach ($historicalByDOY as $doy => $values) {
            if (count($values) > 0) {
                $avgByDOY[$doy] = round(array_sum($values) / count($values), 2);
            }
        }

        return $avgByDOY;
    }

    /**
     * Analysiert Waldtracht-Indikatoren:
     * - Durchschnittstemperatur-Abweichungen
     * - Niederschlagsabweichungen
     */
    public static function getForestHoneyIndicators(int $locationId, int $year, int $month): array
    {
        $db = getDB();

        $startStr = sprintf("%04d-%02d-01", $year, $month);
        $endStr = date("Y-m-t", strtotime($startStr));

        // Aktuelles Jahr / Monat
        $stmt = $db->prepare("
            SELECT
                AVG(temp_mean) as avg_temp,
                SUM(precipitation) as total_precip,
                COUNT(*) as days
            FROM weather_history
            WHERE location_id = :lid AND date >= :sStr AND date <= :eStr
        ");
        $stmt->execute([':lid' => $locationId, ':sStr' => $startStr, ':eStr' => $endStr]);
        $current = $stmt->fetch();

        // 30-Jährigen Durchschnitt holen (falls vorhanden)
        $stmtNormal = $db->prepare("SELECT avg_temp, avg_precip FROM climate_normals 
                                     WHERE location_id = :lid AND month = :m LIMIT 1");
        $stmtNormal->execute([':lid' => $locationId, ':m' => $month]);
        $normal = $stmtNormal->fetch();

        if ($normal && $normal['avg_temp'] !== null) {
            // Verwende Klimanormale aus API
            $historicalTemp   = (float) $normal['avg_temp'];
            $historicalPrecip = (float) $normal['avg_precip'];
        } else {
            // Fallback: Historischer Durchschnitt aus eigenen Daten
            $startYear = $year - METEO_HISTORY_YEARS;
            $hStartStr = sprintf("%04d-%02d-01", $startYear, 1);
            $hEndStr = sprintf("%04d-%02d-31", $year - 1, 12);
            $stmt = $db->prepare("
                SELECT
                    AVG(avg_temp) as avg_temp,
                    AVG(monthly_precip) as avg_precip
                FROM (
                    SELECT YEAR(date) as yr, AVG(temp_mean) as avg_temp, SUM(precipitation) as monthly_precip
                    FROM weather_history
                    WHERE location_id = :lid AND MONTH(date) = :m
                      AND date >= :hs AND date <= :he
                    GROUP BY YEAR(date)
                ) yearly
            ");
            $stmt->execute([':lid' => $locationId, ':m' => $month, ':hs' => $hStartStr, ':he' => $hEndStr]);
            $historical = $stmt->fetch();
            $historicalTemp   = (float) ($historical['avg_temp'] ?? 0);
            $historicalPrecip = (float) ($historical['avg_precip'] ?? 0);
        }

        return [
            'current_avg_temp'     => $current['days'] > 0 ? round((float) $current['avg_temp'], 2) : '-',   
            'current_total_precip' => $current['days'] > 0 ? round((float) $current['total_precip'], 2) : '-',
            'historical_avg_temp'  => round($historicalTemp, 2),
            'historical_avg_precip'=> round($historicalPrecip, 2),
            'temp_deviation'       => $current['days'] > 0 ? round((float) $current['avg_temp'] - $historicalTemp, 2) : 0,
            'precip_deviation'     => $current['days'] > 0 ? round((float) $current['total_precip'] - $historicalPrecip, 2) : 0,
        ];
    }

    /**
     * Liefert Waldtracht-Indikatoren in einer Sammelantwort:
     * komplettes Vorjahr plus Monate des laufenden Jahres bis einschließlich aktuellem Monat.
     */
    public static function getHistoryComparison(int $locationId): array
    {
        $months = [];
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $start = new DateTimeImmutable(($currentYear - 1) . '-01-01');
        $end = new DateTimeImmutable($currentYear . '-' . str_pad((string) $currentMonth, 2, '0', STR_PAD_LEFT) . '-01');

        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
            $months[] = [
                'year' => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
                'label' => self::formatGermanMonthLabel((int) $cursor->format('n'), (int) $cursor->format('Y')),
                'is_current_year' => (int) $cursor->format('Y') === $currentYear,
                'indicators' => self::getForestHoneyIndicators(
                    $locationId,
                    (int) $cursor->format('Y'),
                    (int) $cursor->format('n')
                ),
            ];
        }

        return $months;
    }

    private static function formatGermanMonthLabel(int $month, int $year): string
    {
        $monthNames = [
            1 => 'Januar',
            2 => 'Februar',
            3 => 'März',
            4 => 'April',
            5 => 'Mai',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'August',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Dezember',
        ];

        return ($monthNames[$month] ?? (string) $month) . ' ' . $year;
    }
}
