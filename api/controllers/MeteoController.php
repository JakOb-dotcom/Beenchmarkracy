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
use GTSService;
use MeteoService;
use Validation;

class MeteoController {

    private function requireLocation(int $locationId): array {
        $loc = \LocationService::getById($locationId, Auth::userId());
        if (!$loc) {
            Response::error('Keine Berechtigung oder Standort nicht gefunden', 403);
        }
        return $loc;
    }

    public function gts(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $year       = $request->queryInt('year', (int) date('Y'));
        $this->requireLocation($locationId);
        Response::json(GTSService::calculate($locationId, $year));
    }

    public function gtsDate(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $date       = Validation::dateOr($request->query['date'] ?? null);
        $this->requireLocation($locationId);

        $data   = GTSService::calculate($locationId, (int) substr($date, 0, 4));
        $result = null;
        foreach (array_reverse($data) as $d) {
            if ($d['date'] <= $date) {
                $result = $d;
                break;
            }
        }
        Response::json($result);
    }

    public function gtsComparison(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $year       = $request->queryInt('year', (int) date('Y'));
        $this->requireLocation($locationId);
        Response::json(GTSService::getHistoricalComparison($locationId, $year));
    }

    /** @deprecated Alias für gts_comparison (ältere Clients). */
    public function historyVergleich(Request $request): void {
        $this->gtsComparison($request);
    }

    public function historyVergleichSeries(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $this->requireLocation($locationId);
        Response::json(GTSService::getHistoryComparison($locationId));
    }

    public function climateNormals(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $this->requireLocation($locationId);
        Response::json(MeteoService::getClimateNormals($locationId));
    }

    public function refreshNormals(Request $request): void {
        $locationId = $request->inputInt('location_id');
        $loc = $this->requireLocation($locationId);
        set_time_limit(180);

        $count = MeteoService::fetchClimateNormals($locationId, (float) $loc['latitude'], (float) $loc['longitude']);
        if ($count > 0) {
            Response::json(['success' => true, 'months' => $count]);
        }
        Response::error('Fehler beim Aktualisieren der Klimanormalwerte', 500);
    }

    /** Historische Tageswerte eines Zeitraums. */
    public function weather(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $startDate  = Validation::dateOr($request->query['start'] ?? null, date('Y-01-01'));
        $endDate    = Validation::dateOr($request->query['end'] ?? null, date('Y-m-d'));
        $this->requireLocation($locationId);

        $stmt = getDB()->prepare(
            'SELECT date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture
             FROM weather_history WHERE location_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC'
        );
        $stmt->execute([$locationId, $startDate, $endDate]);
        Response::json($stmt->fetchAll());
    }

    /** Vorhersagewerte ab heute. */
    public function weatherForecast(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $this->requireLocation($locationId);

        $stmt = getDB()->prepare(
            'SELECT date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture
             FROM weather_forecast WHERE location_id = ? AND date >= CURRENT_DATE ORDER BY date ASC'
        );
        $stmt->execute([$locationId]);
        Response::json($stmt->fetchAll());
    }
}
