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
 * Gemeinsamer Bootstrap für Web-Einstieg (index.php) und API (api/index.php).
 *
 * - lädt Konfiguration und DB-Helfer
 * - setzt Fehlerbehandlung/Logging
 * - startet die Session mit gehärteten Cookie-Parametern
 * - stellt den CSRF-Token bereit
 * - sendet Basis-Sicherheits-Header
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// ── Fehlerbehandlung ──────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (APP_LOG_FILE !== '') {
    $__logDir = dirname(APP_LOG_FILE);
    if (!is_dir($__logDir)) {
        @mkdir($__logDir, 0750, true);
    }
    if (is_dir($__logDir) && is_writable($__logDir)) {
        ini_set('error_log', APP_LOG_FILE);
    }
    unset($__logDir);
}

// ── Session ───────────────────────────────────────────────
function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function app_start_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $secure = SESSION_COOKIE_SECURE === null ? app_is_https() : (bool) SESSION_COOKIE_SECURE;

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,          // Browser-Sitzung; der serverseitige Idle-Timeout regelt den Ablauf
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    session_start();

    // Serverseitiger Idle-Timeout
    $now = time();
    if (isset($_SESSION['__last_activity']) && ($now - (int) $_SESSION['__last_activity']) > SESSION_LIFETIME) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['__last_activity'] = $now;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function app_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=()');
    if (app_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
