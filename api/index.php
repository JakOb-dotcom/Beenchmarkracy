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
 * REST-API – Einstiegspunkt
 *
 * Alle Endpunkte werden über ?action=<name> angesprochen.
 * Siehe docs/API.md für die vollständige Übersicht.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/../src/Security/Auth.php';
require_once __DIR__ . '/../src/Security/LoginThrottle.php';
require_once __DIR__ . '/../src/Support/Validation.php';
require_once __DIR__ . '/../src/Services/LocationService.php';
require_once __DIR__ . '/../src/Services/GTSService.php';
require_once __DIR__ . '/../src/Services/MarkerService.php';
require_once __DIR__ . '/../src/Services/MeteoService.php';
require_once __DIR__ . '/../src/Services/OcrWorkerService.php';
require_once __DIR__ . '/../src/Engine/RuleEngine.php';

require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/LocationController.php';
require_once __DIR__ . '/controllers/HiveController.php';
require_once __DIR__ . '/controllers/MarkerController.php';
require_once __DIR__ . '/controllers/MeteoController.php';
require_once __DIR__ . '/controllers/RecordController.php';

use Api\Core\Request;
use Api\Core\Response;
use Api\Core\Router;
use Api\Controllers\AuthController;
use Api\Controllers\LocationController;
use Api\Controllers\HiveController;
use Api\Controllers\MarkerController;
use Api\Controllers\MeteoController;
use Api\Controllers\RecordController;

app_send_security_headers();
app_start_session();
header('Content-Type: application/json; charset=utf-8');

$router = new Router();
$auth   = new AuthController();
$loc    = new LocationController();
$hive   = new HiveController();
$marker = new MarkerController();
$meteo  = new MeteoController();
$record = new RecordController();

// ── Auth (ohne Login / ohne CSRF) ─────────────────────────
$router->register('login',      [$auth, 'login'],     false, false, ['POST']);
$router->register('check_auth', [$auth, 'check_auth'], false, false);
$router->register('logout',     [$auth, 'logout'],    false, false, ['POST']);

$router->register('bootstrap_sync',          [$auth, 'bootstrapSync'],         true, true, ['POST']);
$router->register('bootstrap_sync_complete', [$auth, 'bootstrapSyncComplete'], true, true, ['POST']);

// ── Standorte ─────────────────────────────────────────────
$router->register('locations',            [$loc, 'index']);
$router->register('location_delete',      [$loc, 'delete'],     true, true, ['POST']);
$router->register('location_notes_get',   [$loc, 'getNotes']);
$router->register('location_notes_add',   [$loc, 'addNote'],    true, true, ['POST']);
$router->register('location_note_delete', [$loc, 'deleteNote'], true, true, ['POST']);
$router->register('location_refresh',     [$loc, 'refresh'],    true, true, ['POST']);
$router->register('location_sync',        [$loc, 'sync'],       true, true, ['POST']);

// ── Völker ────────────────────────────────────────────────
$router->register('hives_count_all',            [$hive, 'countAll']);
$router->register('hives_get_all',              [$hive, 'getAll']);
$router->register('hives_get',                  [$hive, 'getByLocation']);
$router->register('hive_create',                [$hive, 'create'],             true, true, ['POST']);
$router->register('hive_update',                [$hive, 'update'],             true, true, ['POST']);
$router->register('hive_bulk_update_genetics',  [$hive, 'bulkUpdateGenetics'], true, true, ['POST']);
$router->register('hive_evaluation_add',        [$hive, 'addEvaluation'],      true, true, ['POST']);
$router->register('hive_evaluation_update',     [$hive, 'updateEvaluation'],   true, true, ['POST']);
$router->register('hive_evaluation_delete',     [$hive, 'deleteEvaluation'],   true, true, ['POST']);
$router->register('hive_delete',                [$hive, 'delete'],             true, true, ['POST']);
$router->register('hive_note_add',              [$hive, 'addNote'],            true, true, ['POST']);
$router->register('hive_note_delete',           [$hive, 'deleteNote'],         true, true, ['POST']);
$router->register('hive_transfer',              [$hive, 'transfer'],           true, true, ['POST']);

// ── Marker & Regel-Engine ─────────────────────────────────
$router->register('markers',                 [$marker, 'index']);
$router->register('marker_update',           [$marker, 'update'],  true, true, ['POST']);
$router->register('marker_predictions',      [$marker, 'predictions']);
$router->register('marker_rule_transitions', [$marker, 'ruleTransitions']);
$router->register('marker_evaluate',         [$marker, 'evaluate']);
$router->register('marker_templates',        [$marker, 'templates']);
$router->register('historical_stats',        [$marker, 'historicalStats'], true, true, ['POST']);
$router->register('marker_delete',           [$marker, 'delete'],  true, true, ['POST']);
$router->register('marker_assign',           [$marker, 'assign'],  true, true, ['POST']);
$router->register('marker_remove',           [$marker, 'remove'],  true, true, ['POST']);

// ── Wetter / GTS ──────────────────────────────────────────
$router->register('gts',                       [$meteo, 'gts']);
$router->register('gts_date',                  [$meteo, 'gtsDate']);
$router->register('gts_comparison',            [$meteo, 'gtsComparison']);
$router->register('historie_vergleich',        [$meteo, 'historyVergleich']);
$router->register('historie_vergleich_series', [$meteo, 'historyVergleichSeries']);
$router->register('climate_normals',           [$meteo, 'climateNormals']);
$router->register('refresh_normals',           [$meteo, 'refreshNormals'], true, true, ['POST']);
$router->register('weather',                   [$meteo, 'weather']);
$router->register('weather_forecast',          [$meteo, 'weatherForecast']);

// ── Aufzeichnungen & OCR ──────────────────────────────────
$router->register('records_get',           [$record, 'getRecords']);
$router->register('record_add',            [$record, 'addRecord'],     true, true, ['POST']);
$router->register('record_edit',           [$record, 'editRecord'],    true, true, ['POST']);
$router->register('record_delete',         [$record, 'deleteRecord'],  true, true, ['POST']);
$router->register('record_upload_ocr',     [$record, 'uploadOcrForm'], true, true, ['POST']);
$router->register('ocr_jobs_get',          [$record, 'getOcrJobs']);
$router->register('record_settings_get',   [$record, 'getSettings']);
$router->register('record_setting_add',    [$record, 'addSetting'],    true, true, ['POST']);
$router->register('record_setting_delete', [$record, 'deleteSetting'], true, true, ['POST']);

// ── Dispatch ──────────────────────────────────────────────
$request = new Request();
if ($request->action === '') {
    Response::error('No action specified', 400);
}
$router->dispatch($request);
