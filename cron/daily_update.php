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
 * Täglicher Cronjob
 *
 * Aufgaben:
 *   1. Abgelaufene Forecasts löschen
 *   2. Gestrige Wetterdaten (historisch) nachladen
 *   3. Neue Forecasts laden
 *   4. Alte OCR-Job-Einträge und Login-Fehlversuche aufräumen
 *
 * Einrichtung (crontab):
 *   0 6 * * * /usr/bin/php /path/to/forecasting/cron/daily_update.php >> /path/to/forecasting/logs/cron.log 2>&1
 *
 * Hinweis: Läuft kein Cronjob, holt die Web-App fehlende Tage beim Öffnen
 * eines Standorts selbst nach (location_sync).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur per CLI ausführbar.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/MeteoService.php';

$start = microtime(true);
$log   = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
};

$log('=== Täglicher Cronjob gestartet ===');

$db = getDB();

// ── 1. Alte Forecasts aufräumen ──────────────────────
$purged = MeteoService::purgeOldForecasts();
$log("Abgelaufene Forecasts gelöscht: $purged");

// ── 2./3. Alle Standorte aktualisieren ───────────────
$locations = $db->query('SELECT id, name, latitude, longitude FROM locations')->fetchAll();
$log('Standorte gefunden: ' . count($locations));

$yesterday = date('Y-m-d', strtotime('yesterday'));

foreach ($locations as $loc) {
    $log("Verarbeite Standort: {$loc['name']} (ID {$loc['id']})");
    try {
        $inserted = MeteoService::fetchSingleDay((int) $loc['id'], (float) $loc['latitude'], (float) $loc['longitude'], $yesterday);
        $log("  Historische Daten ($yesterday): $inserted Einträge");
        usleep(METEO_RATE_DELAY_MS * 1000);

        $forecasted = MeteoService::fetchForecast((int) $loc['id'], (float) $loc['latitude'], (float) $loc['longitude']);
        $log("  Forecast aktualisiert: $forecasted Einträge");
        usleep(METEO_RATE_DELAY_MS * 1000);
    } catch (Throwable $e) {
        $log('  FEHLER: ' . $e->getMessage());
    }
}

// ── 4. Aufräumen ─────────────────────────────────────
$deletedJobs = $db->prepare('DELETE FROM ocr_jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
$deletedJobs->execute();
$log('Alte OCR-Jobs gelöscht: ' . $deletedJobs->rowCount());

$deletedAttempts = $db->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
$deletedAttempts->execute();
$log('Alte Login-Fehlversuche gelöscht: ' . $deletedAttempts->rowCount());

$elapsed = round(microtime(true) - $start, 2);
$log("=== Cronjob abgeschlossen in {$elapsed}s ===");
