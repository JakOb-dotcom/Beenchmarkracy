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
 * CLI: Benutzer anlegen oder Passwort setzen.
 *
 *   php scripts/create_user.php <benutzername> [passwort]
 *
 * Ohne Passwort-Argument wird das Passwort interaktiv (ohne Echo, sofern
 * möglich) abgefragt. Existiert der Benutzer bereits, wird nur das Passwort
 * aktualisiert.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur per CLI ausführbar.\n");
}

require_once __DIR__ . '/../config/database.php';

$username = trim((string) ($argv[1] ?? ''));
if ($username === '' || mb_strlen($username) > 100) {
    fwrite(STDERR, "Verwendung: php scripts/create_user.php <benutzername> [passwort]\n");
    exit(1);
}

$password = (string) ($argv[2] ?? '');
if ($password === '') {
    fwrite(STDOUT, "Passwort für '$username': ");
    if (PHP_OS_FAMILY !== 'Windows') {
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (PHP_OS_FAMILY !== 'Windows') {
        shell_exec('stty echo');
    }
    fwrite(STDOUT, "\n");
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Das Passwort muss mindestens 8 Zeichen lang sein.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$db   = getDB();

$stmt = $db->prepare('SELECT id FROM users WHERE username = :u');
$stmt->execute([':u' => $username]);
$existing = $stmt->fetch();

if ($existing) {
    $db->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => $hash, ':id' => $existing['id']]);
    echo "Passwort für Benutzer '$username' (ID {$existing['id']}) aktualisiert.\n";
} else {
    $db->prepare('INSERT INTO users (username, password_hash) VALUES (:u, :h)')->execute([':u' => $username, ':h' => $hash]);
    echo "Benutzer '$username' angelegt (ID " . $db->lastInsertId() . ").\n";
}
