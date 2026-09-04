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
 * Open-Meteo API Service
 *
 * Lädt historische Wetterdaten (Archive API) und Vorhersagen (Forecast API).
 * Enthält Rate-Limit-Schutz über konfigurierbare Pause zwischen Requests.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

class MeteoService
{
    /** Tägliche Wettervariablen für Open-Meteo API (Archive + Forecast) */
    private const DAILY_VARS = 'temperature_2m_mean,temperature_2m_min,temperature_2m_max,precipitation_sum,surface_pressure_mean,soil_moisture_0_to_7cm_mean';

    // ─────────────────────────────────────────────────
    // Historische Daten laden (Archive API)
    // ─────────────────────────────────────────────────

    /**
     * Lädt die historischen Daten der letzten METEO_HISTORY_YEARS Jahre
     * für einen Standort in Jahres-Chunks, um Rate-Limits zu vermeiden.
     */
    public static function fetchHistory(int $locationId, float $lat, float $lon): int
    {
        $startYear = (int) date('Y') - METEO_HISTORY_YEARS;
        $startDate = "$startYear-01-01";

        return self::fetchHistoryRange($locationId, $lat, $lon, $startDate, date('Y-m-d', strtotime('yesterday')));
    }

    /**
     * Lädt historische Wetterdaten für einen frei definierbaren Zeitraum.
     */
    public static function fetchHistoryRange(int $locationId, float $lat, float $lon, string $startDate, string $endDate): int
    {
        $totalInserted = 0;

        foreach (self::yearChunks($startDate, $endDate) as [$chunkStart, $chunkEnd]) {
            $data = self::callArchiveAPI($lat, $lon, $chunkStart, $chunkEnd);
            if ($data !== null) {
                $totalInserted += self::storeHistoryData($locationId, $data);
            }
            // Rate-Limit-Schutz
            usleep(METEO_RATE_DELAY_MS * 1000);
        }

        return $totalInserted;
    }

    /**
     * Teilt einen Zeitraum in Kalenderjahr-Blöcke [[start, end], …] (beide inklusive).
     * Leer, wenn start > end. Der Folgeblock beginnt immer am 1. Januar des nächsten
     * Jahres – nicht bei "Blockende + 1 Tag", das bei einem Blockende mitten im Jahr
     * (laufendes Jahr bis gestern) wieder im selben Jahr landete (Endlosschleife).
     *
     * @return array<int, array{0:string,1:string}>
     */
    public static function yearChunks(string $startDate, string $endDate): array
    {
        $chunks = [];
        if ($startDate > $endDate) {
            return $chunks;
        }
        $chunkStart = $startDate;
        while ($chunkStart <= $endDate) {
            $year     = (int) substr($chunkStart, 0, 4);
            $chunkEnd = min("$year-12-31", $endDate);
            $chunks[] = [$chunkStart, $chunkEnd];
            $chunkStart = ($year + 1) . '-01-01';
        }
        return $chunks;
    }

    /**
     * Lädt nur die Daten eines einzelnen Tages (für täglichen Cronjob).
     */
    public static function fetchSingleDay(int $locationId, float $lat, float $lon, string $date): int
    {
        $data = self::callArchiveAPI($lat, $lon, $date, $date);
        if ($data === null) {
            return 0;
        }
        return self::storeHistoryData($locationId, $data);
    }

    // ─────────────────────────────────────────────────
    // Vorhersage laden (Forecast API)
    // ─────────────────────────────────────────────────

    /**
     * Lädt die aktuelle 16-Tage-Vorhersage und speichert sie.
     */
    public static function fetchForecast(int $locationId, float $lat, float $lon): int
    {
        $params = http_build_query([
            'latitude'    => $lat,
            'longitude'   => $lon,
            'daily'       => self::DAILY_VARS,
            'timezone'    => 'Europe/Berlin',
            'forecast_days' => 16,
        ]);

        $url  = METEO_FORECAST_URL . '?' . $params;
        $json = self::httpGet($url);
        if ($json === null) {
            return 0;
        }

        $response = json_decode($json, true);
        if (!isset($response['daily']['time'])) {
            return 0;
        }

        return self::storeForecastData($locationId, $response['daily']);
    }

    // ─────────────────────────────────────────────────
    // API-Aufrufe
    // ─────────────────────────────────────────────────

    private static function callArchiveAPI(float $lat, float $lon, string $start, string $end): ?array
    {
        $params = http_build_query([
            'latitude'    => $lat,
            'longitude'   => $lon,
            'start_date'  => $start,
            'end_date'    => $end,
            'daily'       => self::DAILY_VARS,
            'timezone'    => 'Europe/Berlin',
            'models'      => 'era5_seamless',  // ERA5 + ERA5-Land (~9km) für bessere Niederschlagsauflösung
        ]);

        $url  = METEO_ARCHIVE_URL . '?' . $params;
        $json = self::httpGet($url);
        if ($json === null) {
            return null;
        }

        $response = json_decode($json, true);
        if (!isset($response['daily']['time'])) {
            error_log("MeteoService: Keine Daten von Archive API für $start bis $end");
            return null;
        }

        return $response['daily'];
    }

    /** Verbindungsaufbau-Limit (s) und Gesamt-Limit (s) pro Open-Meteo-Aufruf */
    private const HTTP_CONNECT_TIMEOUT = 10;
    private const HTTP_TOTAL_TIMEOUT   = 45;

    /**
     * HTTP-GET mit hartem Gesamt-Timeout. Der Stream-Timeout von file_get_contents
     * gilt nur pro Lesevorgang; eine langsam tröpfelnde Antwort konnte damit das
     * PHP-Zeitlimit (180 s) reißen und einen Fatal Error statt JSON liefern.
     * cURL begrenzt die Gesamtdauer; ohne cURL-Erweiterung greift der Stream-Fallback.
     */
    private static function httpGet(string $url): ?string
    {
        $response = self::httpGetOnce($url, $status);
        if ($response === null && ($status === 0 || $status === 429 || $status >= 500)) {
            // Kurz warten und einmal wiederholen (Rate-Limit, Timeout, Serverfehler)
            usleep(2000 * 1000);
            $response = self::httpGetOnce($url, $status);
        }
        return $response;
    }

    private static function httpGetOnce(string $url, ?int &$status = null): ?string
    {
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::HTTP_TOTAL_TIMEOUT,
                CURLOPT_USERAGENT      => 'Beenchmarkracy/1.0',
                CURLOPT_ENCODING       => '',   // gzip/deflate akzeptieren – kleinere Antworten
                // METEO_VERIFY_SSL nur in lokalen XAMPP-Umgebungen ohne cacert.pem auf false setzen
                // (config.local.php). Im Produktivbetrieb bleibt die Zertifikatsprüfung aktiv.
                CURLOPT_SSL_VERIFYPEER => METEO_VERIFY_SSL,
                CURLOPT_SSL_VERIFYHOST => METEO_VERIFY_SSL ? 2 : 0,
            ]);
            $response = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            if ($response === false || $status >= 400) {
                $reason = '';
                if (is_string($response)) {
                    $decoded = json_decode($response, true);
                    $reason  = is_array($decoded) ? (string) ($decoded['reason'] ?? '') : '';
                }
                error_log("MeteoService: HTTP-Fehler ($status) bei $url" . ($err !== '' ? " – $err" : '') . ($reason !== '' ? " – $reason" : ''));
                return null;
            }
            return $response;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => self::HTTP_TOTAL_TIMEOUT,
                'header'  => "User-Agent: Beenchmarkracy/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => METEO_VERIFY_SSL,
                'verify_peer_name' => METEO_VERIFY_SSL,
            ]
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($response === false || $status >= 400) {
            error_log("MeteoService: HTTP-Fehler ($status) bei $url");
            return null;
        }

        return $response;
    }

    // ─────────────────────────────────────────────────
    // Datenbank-Speicherung
    // ─────────────────────────────────────────────────

    private static function storeHistoryData(int $locationId, array $daily): int
    {
        $db  = getDB();
        $sql = 'INSERT INTO weather_history
                    (location_id, date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture)
                VALUES
                    (:lid, :d, :tm, :tmin, :tmax, :prec, :pres, :sm)
                ON DUPLICATE KEY UPDATE
                    temp_mean     = VALUES(temp_mean),
                    temp_min      = VALUES(temp_min),
                    temp_max      = VALUES(temp_max),
                    precipitation = VALUES(precipitation),
                    pressure      = VALUES(pressure),
                    soil_moisture = VALUES(soil_moisture)';

        $stmt = $db->prepare($sql);
        $count = 0;

        foreach ($daily['time'] as $i => $date) {
            $stmt->execute([
                ':lid'  => $locationId,
                ':d'    => $date,
                ':tm'   => $daily['temperature_2m_mean'][$i]        ?? null,
                ':tmin' => $daily['temperature_2m_min'][$i]         ?? null,
                ':tmax' => $daily['temperature_2m_max'][$i]         ?? null,
                ':prec' => $daily['precipitation_sum'][$i]          ?? null,
                ':pres' => $daily['surface_pressure_mean'][$i]      ?? null,
                ':sm'   => $daily['soil_moisture_0_to_7cm_mean'][$i] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    private static function storeForecastData(int $locationId, array $daily): int
    {
        $db = getDB();

        // Alte Forecasts für diesen Standort löschen
        $db->prepare('DELETE FROM weather_forecast WHERE location_id = :lid')
           ->execute([':lid' => $locationId]);

        $sql = 'INSERT INTO weather_forecast
                    (location_id, date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture)
                VALUES
                    (:lid, :d, :tm, :tmin, :tmax, :prec, :pres, :sm)';

        $stmt  = $db->prepare($sql);
        $count = 0;

        foreach ($daily['time'] as $i => $date) {
            $stmt->execute([
                ':lid'  => $locationId,
                ':d'    => $date,
                ':tm'   => $daily['temperature_2m_mean'][$i]        ?? null,
                ':tmin' => $daily['temperature_2m_min'][$i]         ?? null,
                ':tmax' => $daily['temperature_2m_max'][$i]         ?? null,
                ':prec' => $daily['precipitation_sum'][$i]          ?? null,
                ':pres' => $daily['surface_pressure_mean'][$i]      ?? null,
                ':sm'   => $daily['soil_moisture_0_to_7cm_mean'][$i] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    // ─────────────────────────────────────────────────
    // Klimanormale (30-Jahres-Durchschnitt direkt aus API)
    // ─────────────────────────────────────────────────

    /**
     * Berechnet die 30-jährigen Klimanormale (monatliche Ø-Temperatur und Ø-Niederschlag)
     * direkt aus der Open-Meteo Archive API und speichert sie in der climate_normals Tabelle.
     * Daten werden in 10-Jahres-Chunks geladen, um die Antwortgröße handhabbar zu halten.
     *
     * @return int Anzahl gespeicherter Monate (sollte 12 sein)
     */
    public static function fetchClimateNormals(int $locationId, float $lat, float $lon): int
    {
        $acc = self::newNormalsAccumulator();
        foreach (self::climateNormalParts() as [$from, $to]) {
            if (!self::accumulateClimateNormals($lat, $lon, $from, $to, $acc)) {
                // Lieber keine Normale als ein Mittel aus 20 statt 30 Jahren; der nächste
                // Sync versucht es erneut (climate_normals bleibt unter 12 Zeilen).
                error_log("MeteoService: Klimanormale für Standort $locationId unvollständig ({$from}-{$to} fehlt), nicht gespeichert");
                return 0;
            }
            usleep(METEO_RATE_DELAY_MS * 1000);
        }
        return self::storeClimateNormals($locationId, $acc);
    }

    /**
     * Jahresblöcke (max. 10 Jahre) des Referenzzeitraums, z.B. [[1995,2004],[2005,2014],[2015,2024]].
     * Das Frontend lädt beim Standort-Refresh jeden Block in einem eigenen Request,
     * damit kein einzelner Request das PHP-Zeitlimit erreicht.
     *
     * @return array<int, array{0:int,1:int}>
     */
    public static function climateNormalParts(): array
    {
        $parts = [];
        for ($y = METEO_NORMAL_START; $y <= METEO_NORMAL_END; $y += 10) {
            $parts[] = [$y, min($y + 9, METEO_NORMAL_END)];
        }
        return $parts;
    }

    /** Leerer Akkumulator: Temperatur-Summe/-Anzahl und Niederschlag je Jahr-Monat, pro Monat. */
    public static function newNormalsAccumulator(): array
    {
        $acc = ['temp_sum' => [], 'temp_n' => [], 'precip_ym' => [], 'failed' => false];
        for ($m = 1; $m <= 12; $m++) {
            $acc['temp_sum'][$m]  = 0.0;
            $acc['temp_n'][$m]    = 0;
            $acc['precip_ym'][$m] = [];
        }
        return $acc;
    }

    /**
     * Lädt einen Jahresblock von Open-Meteo und addiert ihn auf den Akkumulator.
     * Für Temperatur: alle Tageswerte → Monats-Ø. Für Niederschlag: Tageswerte je
     * Jahr-Monat summieren → Ø der Monatssummen über die Jahre.
     *
     * @return bool false, wenn der Block nicht geladen werden konnte
     */
    public static function accumulateClimateNormals(float $lat, float $lon, int $fromYear, int $toYear, array &$acc): bool
    {
        $data = self::callArchiveAPI($lat, $lon, "$fromYear-01-01", "$toYear-12-31");
        if ($data === null) {
            return false;
        }
        self::addDailyToNormalsAccumulator($data, $acc);
        return true;
    }

    /**
     * Addiert eine Open-Meteo-Tagesreihe (time[], temperature_2m_mean[], precipitation_sum[])
     * auf den Akkumulator. Reine Funktion, ohne Netz/DB – testbar.
     */
    public static function addDailyToNormalsAccumulator(array $data, array &$acc): void
    {
        foreach ($data['time'] as $i => $date) {
            $dt    = strtotime($date);
            $month = (int) date('n', $dt);
            $ym    = date('Y', $dt) . '-' . $month;

            $temp   = $data['temperature_2m_mean'][$i] ?? null;
            $precip = $data['precipitation_sum'][$i] ?? null;

            if ($temp !== null) {
                $acc['temp_sum'][$month] += (float) $temp;
                $acc['temp_n'][$month]++;
            }
            if ($precip !== null) {
                if (!isset($acc['precip_ym'][$month][$ym])) {
                    $acc['precip_ym'][$month][$ym] = 0.0;
                }
                $acc['precip_ym'][$month][$ym] += (float) $precip;
            }
        }
    }

    /**
     * Schreibt die Monatsmittel aus dem Akkumulator in climate_normals.
     * @return int Anzahl gespeicherter Monate (12)
     */
    public static function storeClimateNormals(int $locationId, array $acc): int
    {
        $monthlyTemps  = [];
        $monthlyPrecip = [];
        for ($m = 1; $m <= 12; $m++) {
            // Für die Ø-Bildung unten genügt Summe/Anzahl; Struktur wie bisher beibehalten.
            $monthlyTemps[$m]  = $acc['temp_n'][$m] > 0 ? [$acc['temp_sum'][$m] / $acc['temp_n'][$m]] : [];
            $monthlyPrecip[$m] = $acc['precip_ym'][$m];
        }

        // In Datenbank speichern
        $db   = getDB();
        $sql  = 'INSERT INTO climate_normals (location_id, month, avg_temp, avg_precip, reference_period)
                 VALUES (:lid, :m, :temp, :precip, :ref)
                 ON DUPLICATE KEY UPDATE
                     avg_temp    = VALUES(avg_temp),
                     avg_precip  = VALUES(avg_precip),
                     reference_period = VALUES(reference_period),
                     updated_at  = CURRENT_TIMESTAMP';
        $stmt = $db->prepare($sql);

        $refPeriod = METEO_NORMAL_START . '-' . METEO_NORMAL_END;
        $count     = 0;

        for ($m = 1; $m <= 12; $m++) {
            $temps = $monthlyTemps[$m];
            $avgTemp = !empty($temps)
                ? round(array_sum($temps) / count($temps), 2)
                : null;

            $precipMonths = $monthlyPrecip[$m];
            $avgPrecip = !empty($precipMonths)
                ? round(array_sum($precipMonths) / count($precipMonths), 2)
                : null;

            $stmt->execute([
                ':lid'    => $locationId,
                ':m'      => $m,
                ':temp'   => $avgTemp,
                ':precip' => $avgPrecip,
                ':ref'    => $refPeriod,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Synchronisiert fehlende aktuelle Wetterdaten, wenn kein Cronjob läuft.
     */
    public static function syncRecentData(int $locationId, float $lat, float $lon): array
    {
        return self::syncRecentDataInternal($locationId, $lat, $lon, true);
    }

    public static function syncRecentDataWithoutNormals(int $locationId, float $lat, float $lon): array
    {
        return self::syncRecentDataInternal($locationId, $lat, $lon, false);
    }

    private static function syncRecentDataInternal(int $locationId, float $lat, float $lon, bool $includeNormals): array
    {
        $db = getDB();
        $yesterday = date('Y-m-d', strtotime('yesterday'));
        $today = date('Y-m-d');

        $stmtHistory = $db->prepare('SELECT MAX(date) FROM weather_history WHERE location_id = :lid');
        $stmtHistory->execute([':lid' => $locationId]);
        $latestHistory = $stmtHistory->fetchColumn() ?: null;

        $historyInserted = 0;
        if ($latestHistory === null) {
            // Bei einem ganz neuen Standort holen wir hier keine riesigen Historien,
            // da dies sonst in einen 503-Timeout auf Shared-Hostern rennen kann.
            // Die Historie wird via Chunking (Refresh-Prozess) geladen.
            // Für den reinen Daily-Sync belassen wir es bei max. 30 Tagen.
            $historyInserted = self::fetchHistoryRange(
                $locationId,
                $lat,
                $lon,
                date('Y-m-d', strtotime('-30 days')),
                $yesterday
            );
        } elseif ($latestHistory < $yesterday) {
            $missingStart = date('Y-m-d', strtotime($latestHistory . ' +1 day'));
            
            // Wenn die letzte Historie extrem lange her ist (z.B. > 60 Tage), 
            // vermeiden wir auch hier einen Mega-Sync in diesem Request.
            $diffDays = (strtotime($yesterday) - strtotime($missingStart)) / 86400;
            if ($diffDays > 60) {
                // Beschränke auf die letzten 30 Tage, falls es zu viel ist.
                $missingStart = date('Y-m-d', strtotime('-30 days'));
            }

            $historyInserted = self::fetchHistoryRange($locationId, $lat, $lon, $missingStart, $yesterday);
        }

        $stmtForecast = $db->prepare('SELECT COALESCE(MAX(DATE(fetched_at)), "1000-01-01") FROM weather_forecast WHERE location_id = :lid');
        $stmtForecast->execute([':lid' => $locationId]);
        $forecastFetchedDate = $stmtForecast->fetchColumn();

        $stmtForecastRows = $db->prepare('SELECT COUNT(*) FROM weather_forecast WHERE location_id = :lid AND date >= CURDATE()');
        $stmtForecastRows->execute([':lid' => $locationId]);
        $forecastRows = (int) $stmtForecastRows->fetchColumn();

        $forecastInserted = 0;
        if ($forecastRows === 0 || $forecastFetchedDate < $today) {
            $forecastInserted = self::fetchForecast($locationId, $lat, $lon);
        }

        $normalsInserted = 0;
        if ($includeNormals) {
            $stmtNormals = $db->prepare('SELECT COUNT(*) FROM climate_normals WHERE location_id = :lid');
            $stmtNormals->execute([':lid' => $locationId]);
            $normalCount = (int) $stmtNormals->fetchColumn();

            if ($normalCount < 12) {
                $normalsInserted = self::fetchClimateNormals($locationId, $lat, $lon);
            }
        }

        return [
            'history' => $historyInserted,
            'forecast' => $forecastInserted,
            'normals' => $normalsInserted,
            'updated' => $historyInserted > 0 || $forecastInserted > 0 || $normalsInserted > 0,
            'latest_history' => $latestHistory,
        ];
    }

    /**
     * Klimanormale aus DB lesen (alle 12 Monate).
     * @return array [month => ['avg_temp' => ..., 'avg_precip' => ...]]
     */
    public static function getClimateNormals(int $locationId): array
    {
        $db   = getDB();
        $stmt = $db->prepare('SELECT month, avg_temp, avg_precip, reference_period
                              FROM climate_normals WHERE location_id = :lid ORDER BY month');
        $stmt->execute([':lid' => $locationId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['month']] = [
                'month'            => (int) $row['month'],
                'avg_temp'         => $row['avg_temp'] !== null ? (float) $row['avg_temp'] : null,
                'avg_precip'       => $row['avg_precip'] !== null ? (float) $row['avg_precip'] : null,
                'reference_period' => $row['reference_period'],
            ];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────
    // Aufräumen
    // ─────────────────────────────────────────────────

    /**
     * Löscht abgelaufene Vorhersagen (Datum < heute).
     */
    public static function purgeOldForecasts(): int
    {
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM weather_forecast WHERE date < CURDATE()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
