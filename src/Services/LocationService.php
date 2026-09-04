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
 * Standort-Service – CRUD für Bienenstandorte
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/MeteoService.php';

class LocationService
{
    private static function bootstrapHistoryStartDate(): string
    {
        return date('Y-01-01');
    }

    /**
     * Alle Standorte eines Benutzers abrufen.
     */
    public static function getAllByUser(int $userId): array
    {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM locations WHERE user_id = :uid ORDER BY name');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Einzelnen Standort laden.
     */
    public static function getById(int $id, int $userId): ?array
    {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM locations WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $loc = $stmt->fetch();
        return $loc ?: null;
    }

    /**
     * Neuen Standort anlegen und historische + Forecast-Daten laden.
     * Gibt die neue Location-ID zurück.
     */
    public static function create(int $userId, string $name, float $lat, float $lon, ?int $altitude = null): int
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO locations (user_id, name, latitude, longitude, altitude)
             VALUES (:uid, :name, :lat, :lon, :alt)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':name' => $name,
            ':lat'  => $lat,
            ':lon'  => $lon,
            ':alt'  => $altitude,
        ]);
        $locationId = (int) $db->lastInsertId();

        // 503 FIX (World4You / Shared Hosting)
        // Beim Anlegen des Standorts GAR KEINE Daten mehr vom MeteoService laden!
        // Stattdessen triggert das Frontend sofort nach dem Anlegen den regulären
        // Refresh-Prozess (Chunking), welcher die Daten sicher und ohne Zeitlimits lädt.

        return $locationId;
    }

    /**
     * Standort löschen (CASCADE löscht auch die Wetterdaten).
     */
    public static function delete(int $id, int $userId): bool
    {
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM locations WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Standort aktualisieren.
     */
    public static function update(int $id, int $userId, string $name, float $lat, float $lon, ?int $altitude = null): bool
    {
        $db = getDB();
        $stmt = $db->prepare(
            'UPDATE locations SET name = :name, latitude = :lat, longitude = :lon, altitude = :alt
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            ':name' => $name,
            ':lat'  => $lat,
            ':lon'  => $lon,
            ':alt'  => $altitude,
            ':id'   => $id,
            ':uid'  => $userId,
        ]);
        return $stmt->rowCount() > 0;
    }
}
