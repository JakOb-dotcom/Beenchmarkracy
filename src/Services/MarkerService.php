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
 * Marker-Service – Benutzerdefinierte Marker (GTS, Waldtracht, Komplex)
 */

require_once __DIR__ . '/../../config/database.php';

class MarkerService
{
    /**
     * Alle Marker eines Benutzers abrufen.
     */
    public static function getAllByUser(int $userId): array
    {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM markers WHERE user_id = :uid ORDER BY COALESCE(threshold_value, 999999)');
        $stmt->execute([':uid' => $userId]);
        $markers = $stmt->fetchAll();

        // Rules JSON dekodieren
        return array_map(function ($m) {
            if (!empty($m['rules']) && is_string($m['rules'])) {
                $m['rules'] = json_decode($m['rules'], true);
            }
            return $m;
        }, $markers);
    }

    /**
     * Einzelnen Marker laden.
     */
    public static function getById(int $id, int $userId): ?array
    {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM markers WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $m = $stmt->fetch();
        if (!$m) return null;

        if (!empty($m['rules']) && is_string($m['rules'])) {
            $m['rules'] = json_decode($m['rules'], true);
        }
        return $m;
    }

    /**
     * Neuen Marker erstellen (einfach oder komplex).
     */
    public static function create(
        int     $userId,
        string  $name,
        string  $type,
        ?float  $thresholdValue,
        string  $color = '#4caf50',
        ?string $description = null,
        string  $severity = 'info',
        ?string $alertMessage = null,
        ?array  $rules = null
    ): int {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO markers (user_id, name, type, threshold_value, color, severity, description, alert_message, rules)
             VALUES (:uid, :name, :type, :tv, :color, :sev, :desc, :alert, :rules)'
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':name'  => $name,
            ':type'  => $type,
            ':tv'    => $thresholdValue,
            ':color' => $color,
            ':sev'   => $severity,
            ':desc'  => $description,
            ':alert' => $alertMessage,
            ':rules' => $rules !== null ? json_encode($rules) : null,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Marker aktualisieren.
     */
    public static function update(
        int     $id,
        int     $userId,
        string  $name,
        string  $type,
        ?float  $thresholdValue,
        string  $color,
        ?string $description,
        string  $severity = 'info',
        ?string $alertMessage = null,
        ?array  $rules = null
    ): bool {
        $db = getDB();
        $stmt = $db->prepare(
            'UPDATE markers SET name = :name, type = :type, threshold_value = :tv,
                                color = :color, severity = :sev, description = :desc,
                                alert_message = :alert, rules = :rules
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            ':name'  => $name,
            ':type'  => $type,
            ':tv'    => $thresholdValue,
            ':color' => $color,
            ':sev'   => $severity,
            ':desc'  => $description,
            ':alert' => $alertMessage,
            ':rules' => $rules !== null ? json_encode($rules) : null,
            ':id'    => $id,
            ':uid'   => $userId,
        ]);
        // rowCount() ist 0, wenn sich keine Spalte geändert hat – das ist kein Fehler.
        return $stmt->rowCount() > 0 || self::getById($id, $userId) !== null;
    }

    /**
     * Marker löschen.
     */
    public static function delete(int $id, int $userId): bool
    {
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM markers WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Marker einem Standort zuordnen.
     */
    public static function assignToLocation(int $markerId, int $locationId): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT IGNORE INTO marker_locations (marker_id, location_id) VALUES (:mid, :lid)'
        );
        $stmt->execute([':mid' => $markerId, ':lid' => $locationId]);
    }

    /**
     * Marker von einem Standort entfernen.
     */
    public static function removeFromLocation(int $markerId, int $locationId): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            'DELETE FROM marker_locations WHERE marker_id = :mid AND location_id = :lid'
        );
        $stmt->execute([':mid' => $markerId, ':lid' => $locationId]);
    }

    /**
     * Alle Marker für einen Standort laden.
     */
    public static function getForLocation(int $locationId, int $userId): array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT m.* FROM markers m
            JOIN marker_locations ml ON ml.marker_id = m.id
            WHERE ml.location_id = :lid AND m.user_id = :uid
            ORDER BY m.threshold_value
        ");
        $stmt->execute([':lid' => $locationId, ':uid' => $userId]);
        $markers = $stmt->fetchAll();

        // Rules JSON dekodieren (wie in getAllByUser)
        return array_map(function ($m) {
            if (!empty($m['rules']) && is_string($m['rules'])) {
                $m['rules'] = json_decode($m['rules'], true);
            }
            return $m;
        }, $markers);
    }

    /**
     * Prüft welche GTS-Marker an einem bestimmten Datum erreicht sind.
     */
    public static function checkGTSMarkers(int $locationId, int $userId, float $currentGTS): array
    {
        $markers = self::getForLocation($locationId, $userId);
        $reached = [];

        foreach ($markers as $marker) {
            if ($marker['type'] === 'gts' && $currentGTS >= (float) $marker['threshold_value']) {
                $reached[] = $marker;
            }
        }

        return $reached;
    }
}
