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
 * Zentrale Konfiguration – Beenchmarkracy
 *
 * Reihenfolge der Auflösung pro Einstellung:
 *   1. config/config.local.php  (nicht versioniert, siehe config.local.example.php)
 *   2. Umgebungsvariable gleichen Namens (z.B. DB_PASS)
 *   3. Standardwert aus dieser Datei
 *
 * Es werden bewusst Konstanten definiert, damit der restliche Code
 * (Services, Controller, Cron) ohne Container/Autoloader auskommt.
 */

declare(strict_types=1);

$__localConfigFile = __DIR__ . '/config.local.php';
$__localConfig     = is_file($__localConfigFile) ? (require $__localConfigFile) : [];
if (!is_array($__localConfig) || getenv('APP_IGNORE_LOCAL_CONFIG') === '1') {
    // APP_IGNORE_LOCAL_CONFIG=1 nutzt die Testsuite, um gegen eine temporäre DB zu laufen
    $__localConfig = [];
}

/**
 * Liest eine Einstellung aus config.local.php, sonst aus der Umgebung, sonst Default.
 */
$__cfg = static function (string $key, mixed $default) use ($__localConfig): mixed {
    if (array_key_exists($key, $__localConfig)) {
        return $__localConfig[$key];
    }
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        if (is_bool($default)) {
            return in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_int($default)) {
            return (int) $env;
        }
        return $env;
    }
    return $default;
};

// ── Anwendung ─────────────────────────────────────────────
define('APP_NAME',  'Beenchmarkracy');
define('APP_ENV',   (string) $__cfg('APP_ENV', 'production'));   // production | development
define('APP_DEBUG', (bool)   $__cfg('APP_DEBUG', APP_ENV === 'development'));
define('APP_ROOT',  dirname(__DIR__));

// ── Datenbank ─────────────────────────────────────────────
define('DB_HOST',    (string) $__cfg('DB_HOST', 'localhost'));
define('DB_PORT',    (int)    $__cfg('DB_PORT', 3306));
define('DB_NAME',    (string) $__cfg('DB_NAME', 'forecasting'));
define('DB_USER',    (string) $__cfg('DB_USER', 'root'));
define('DB_PASS',    (string) $__cfg('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ── Open-Meteo API ────────────────────────────────────────
define('METEO_ARCHIVE_URL',  'https://archive-api.open-meteo.com/v1/archive');
define('METEO_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast');
define('METEO_RATE_DELAY_MS', 500);   // Pause zwischen API-Calls (ms)
define('METEO_HISTORY_YEARS', 11);    // Jahre Historie, die pro Standort geladen werden
define('METEO_NORMAL_START', 1995);   // Beginn des 30-Jahres-Referenzzeitraums
define('METEO_NORMAL_END',   2024);   // Ende des 30-Jahres-Referenzzeitraums
// SSL-Prüfung nur in lokalen XAMPP-Umgebungen ohne cacert.pem deaktivieren!
define('METEO_VERIFY_SSL', (bool) $__cfg('METEO_VERIFY_SSL', APP_ENV !== 'development'));

// ── Session & Login ───────────────────────────────────────
define('SESSION_NAME',     'forecasting_sid');
define('SESSION_LIFETIME', 86400);    // 24 h Idle-Timeout
// null = automatisch (Secure-Flag nur bei HTTPS); true erzwingt HTTPS-only Cookies
define('SESSION_COOKIE_SECURE', $__cfg('SESSION_COOKIE_SECURE', null));
define('LOGIN_MAX_ATTEMPTS',    5);   // Fehlversuche pro IP …
define('LOGIN_LOCKOUT_SECONDS', 900); // … innerhalb dieses Zeitfensters (15 min)

// ── OCR-Worker (PythonWorkerOCR) ──────────────────────────
define('OCR_ENABLED',          (bool)   $__cfg('OCR_ENABLED', true));
// Leer = automatisch: PythonWorkerOCR/.venv, sonst "python" im PATH
define('OCR_PYTHON_BIN',       (string) $__cfg('OCR_PYTHON_BIN', ''));
define('OCR_UPLOAD_DIR',       (string) $__cfg('OCR_UPLOAD_DIR', APP_ROOT . '/uploads/ocr_forms'));
define('OCR_MAX_UPLOAD_BYTES', 20 * 1024 * 1024); // 20 MB
define('OCR_LOG_FILE',         (string) $__cfg('OCR_LOG_FILE', APP_ROOT . '/logs/ocr_worker.log'));

// ── Logging ───────────────────────────────────────────────
define('APP_LOG_FILE', (string) $__cfg('APP_LOG_FILE', APP_ROOT . '/logs/app.log'));

// ── Zeitzone ──────────────────────────────────────────────
date_default_timezone_set((string) $__cfg('APP_TIMEZONE', 'Europe/Vienna'));

unset($__localConfigFile, $__localConfig, $__cfg);
