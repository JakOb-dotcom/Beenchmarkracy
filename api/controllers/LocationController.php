<?php
/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use Auth;
use LocationService;
use Validation;

class LocationController {

    private function requireLocation(int $locationId): array {
        $loc = LocationService::getById($locationId, Auth::userId());
        if (!$loc) {
            Response::error('Keine Berechtigung oder Standort nicht gefunden', 403);
        }
        return $loc;
    }

    public function index(Request $request): void {
        $userId = Auth::userId();
        if ($request->method === 'GET') {
            Response::json(LocationService::getAllByUser($userId));
        }
        if ($request->method !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        if (empty($request->input)) {
            Response::error('Ungültiger Request: JSON-Body oder Formulardaten erforderlich');
        }
        $name = Validation::text($request->input['name'] ?? null, 200);
        $lat  = $request->input['latitude'] ?? null;
        $lon  = $request->input['longitude'] ?? null;
        $alt  = isset($request->input['altitude']) && is_numeric($request->input['altitude']) ? (int) $request->input['altitude'] : null;

        if ($name === null || !is_numeric($lat) || !is_numeric($lon)) {
            Response::error('Name, Latitude und Longitude sind erforderlich');
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            Response::error('Koordinaten außerhalb des gültigen Bereichs');
        }

        $id = LocationService::create($userId, $name, $lat, $lon, $alt);
        Response::json([
            'success' => true,
            'id'      => $id,
            'message' => 'Standort erfolgreich angelegt.',
        ]);
    }

    public function delete(Request $request): void {
        $id = $request->inputInt('id');
        $ok = LocationService::delete($id, Auth::userId());
        Response::json(['success' => $ok]);
    }

    public function getNotes(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $this->requireLocation($locationId);

        $stmt = getDB()->prepare('SELECT id, note, created_at FROM location_notes WHERE location_id = ? ORDER BY created_at DESC');
        $stmt->execute([$locationId]);
        Response::json(['success' => true, 'notes' => $stmt->fetchAll()]);
    }

    public function addNote(Request $request): void {
        $locationId = $request->inputInt('location_id');
        $noteText   = Validation::text($request->input['note'] ?? null, 5000);
        $this->requireLocation($locationId);
        if ($noteText === null) {
            Response::error('Notiz darf nicht leer sein');
        }
        getDB()->prepare('INSERT INTO location_notes (location_id, note) VALUES (?, ?)')->execute([$locationId, $noteText]);
        Response::json(['success' => true]);
    }

    public function deleteNote(Request $request): void {
        $noteId = $request->inputInt('id');
        $db = getDB();
        $stmt = $db->prepare('SELECT ln.id FROM location_notes ln JOIN locations l ON ln.location_id = l.id WHERE ln.id = ? AND l.user_id = ?');
        $stmt->execute([$noteId, Auth::userId()]);
        if (!$stmt->fetch()) {
            Response::error('Keine Berechtigung oder Notiz nicht gefunden', 403);
        }
        $db->prepare('DELETE FROM location_notes WHERE id = ?')->execute([$noteId]);
        Response::json(['success' => true]);
    }

    /**
     * Wetterdaten-Refresh in Schritten (Chunking), damit Shared-Hosting-Zeitlimits nicht greifen:
     *   mode=plan          → Liste der zu ladenden Jahre
     *   mode=history_chunk → ein Jahr Historie (Parameter year)
     *   mode=forecast      → 16-Tage-Vorhersage
     *   mode=normals       → 30-jährige Klimanormale; optional part=1..N (Jahresblöcke aus dem Plan),
     *                        Zwischenstand liegt in der Session, der letzte Block schreibt die DB
     */
    public function refresh(Request $request): void {
        $locationId = $request->inputInt('id');
        $mode       = Validation::oneOf($request->input['mode'] ?? null, ['plan', 'history_chunk', 'forecast', 'normals'], 'forecast');
        $year       = $request->inputInt('year');
        $part       = $request->inputInt('part');

        $loc = $this->requireLocation($locationId);
        set_time_limit(180);

        $lat = (float) $loc['latitude'];
        $lon = (float) $loc['longitude'];

        if ($mode === 'plan') {
            $currentYear = (int) date('Y');
            $years = [];
            for ($y = $currentYear - \METEO_HISTORY_YEARS; $y <= $currentYear; $y++) {
                $years[] = $y;
            }
            Response::json([
                'success'      => true,
                'years'        => $years,
                'normal_parts' => \MeteoService::climateNormalParts(),
            ]);
        }

        if ($mode === 'normals' && $part > 0) {
            $this->refreshNormalsPart($locationId, $lat, $lon, $part);
        }

        $inserted = match ($mode) {
            'history_chunk' => ($year >= 1940 && $year <= (int) date('Y'))
                ? \MeteoService::fetchHistoryRange($locationId, $lat, $lon, "$year-01-01", min("$year-12-31", date('Y-m-d', strtotime('yesterday'))))
                : 0,
            'normals'       => \MeteoService::fetchClimateNormals($locationId, $lat, $lon),
            default         => \MeteoService::fetchForecast($locationId, $lat, $lon),
        };

        Response::json(['success' => true, 'inserted' => $inserted]);
    }

    /**
     * Ein Jahresblock der Klimanormale. Der Akkumulator wird in der Session gehalten;
     * beim letzten Block werden die Monatsmittel gespeichert.
     */
    private function refreshNormalsPart(int $locationId, float $lat, float $lon, int $part): void {
        $parts = \MeteoService::climateNormalParts();
        $total = count($parts);
        if ($part > $total) {
            Response::error('Ungültiger Normal-Block', 400);
        }

        $key = 'normals_acc_' . $locationId;
        $acc = ($part === 1 || empty($_SESSION[$key]))
            ? \MeteoService::newNormalsAccumulator()
            : $_SESSION[$key];

        [$from, $to] = $parts[$part - 1];
        $ok = \MeteoService::accumulateClimateNormals($lat, $lon, $from, $to, $acc);
        if (!$ok) {
            $acc['failed'] = true;
        }

        if ($part < $total) {
            $_SESSION[$key] = $acc;
            Response::json(['success' => true, 'inserted' => 0, 'part' => $part, 'parts' => $total, 'ok' => $ok]);
        }

        unset($_SESSION[$key]);
        if (!empty($acc['failed'])) {
            // Unvollständige Normale nicht speichern – der nächste Sync holt sie erneut.
            Response::json(['success' => true, 'inserted' => 0, 'part' => $part, 'parts' => $total, 'ok' => false]);
        }
        $inserted = \MeteoService::storeClimateNormals($locationId, $acc);
        Response::json(['success' => true, 'inserted' => $inserted, 'part' => $part, 'parts' => $total, 'ok' => true]);
    }

    public function sync(Request $request): void {
        $locationId = $request->inputInt('id') ?: $request->queryInt('location_id');
        $loc = $this->requireLocation($locationId);
        set_time_limit(180);
        $result = \MeteoService::syncRecentData($locationId, (float) $loc['latitude'], (float) $loc['longitude']);
        Response::json(['success' => true, 'details' => $result]);
    }
}
