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
 * Einfache Brute-Force-Bremse für den Login (pro Client-IP).
 *
 * Speichert Fehlversuche in der Tabelle login_attempts. Nach LOGIN_MAX_ATTEMPTS
 * Fehlversuchen innerhalb von LOGIN_LOCKOUT_SECONDS wird der Login für diese IP
 * bis zum Ablauf des Fensters abgelehnt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

class LoginThrottle
{
    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return substr($ip, 0, 45);
    }

    /**
     * Verbleibende Sperrzeit in Sekunden (0 = nicht gesperrt).
     */
    public static function lockedForSeconds(string $ip): int
    {
        $db = getDB();
        $windowStart = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_SECONDS);

        $stmt = $db->prepare('SELECT COUNT(*) AS c, MAX(attempted_at) AS last
                              FROM login_attempts WHERE ip = :ip AND attempted_at >= :ws');
        $stmt->execute([':ip' => $ip, ':ws' => $windowStart]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['c'] < LOGIN_MAX_ATTEMPTS) {
            return 0;
        }
        $unlockAt = strtotime((string) $row['last']) + LOGIN_LOCKOUT_SECONDS;
        return max(0, $unlockAt - time());
    }

    public static function recordFailure(string $ip, string $username): void
    {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO login_attempts (ip, username) VALUES (:ip, :u)');
        $stmt->execute([':ip' => $ip, ':u' => substr($username, 0, 100)]);

        // Gelegentlich alte Einträge entsorgen
        if (random_int(1, 20) === 1) {
            $db->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff')
               ->execute([':cutoff' => date('Y-m-d H:i:s', time() - 2 * LOGIN_LOCKOUT_SECONDS)]);
        }
    }

    public static function clear(string $ip): void
    {
        getDB()->prepare('DELETE FROM login_attempts WHERE ip = :ip')->execute([':ip' => $ip]);
    }
}
