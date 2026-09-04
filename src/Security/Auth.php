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
 * Auth-Service – Login / Logout / Session
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

class Auth
{
    /**
     * Prüft Benutzername + Passwort gegen die users-Tabelle.
     * Gibt User-Array bei Erfolg oder false zurück.
     */
    public static function attempt(string $username, string $password): array|false
    {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            // Zeitverhalten angleichen, damit gültige Benutzernamen nicht per Timing erkennbar sind
            password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalid');
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Hash transparent auf aktuellen Algorithmus/Cost anheben
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $upd = $db->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $upd->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
        }

        unset($user['password_hash']);
        return $user;
    }

    /**
     * Startet eine authentifizierte Session.
     */
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['logged_in'] = true;
        $_SESSION['needs_bootstrap_sync'] = true;

        // CSRF-Token bei Login rotieren
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Beendet die Session vollständig.
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function userId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['logged_in']) && self::userId() > 0;
    }
}
