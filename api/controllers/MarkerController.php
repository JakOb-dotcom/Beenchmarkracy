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
use MarkerService;
use RuleEngine;
use Validation;

class MarkerController {

    private const TYPES      = ['gts', 'temperature_deviation', 'precipitation_deviation', 'complex', 'custom'];
    private const SEVERITIES = ['info', 'warning', 'critical'];

    private function checkLocationAccess(int $locationId): void {
        if (!\LocationService::getById($locationId, Auth::userId())) {
            Response::error('Keine Berechtigung oder Standort nicht gefunden', 403);
        }
    }

    /**
     * Validiert die Marker-Felder aus dem Request-Body.
     * @return array{name:string,type:string,threshold:?float,color:string,description:?string,severity:string,alert:?string,rules:?array}
     */
    private function markerPayload(array $input): array {
        $name = Validation::text($input['name'] ?? null, 200);
        if ($name === null) {
            Response::error('Marker-Name ist erforderlich', 400);
        }
        $rules = $input['rules'] ?? null;
        if ($rules !== null && !is_array($rules)) {
            Response::error('Ungültige Regeldefinition', 400);
        }
        if (is_array($rules) && strlen(json_encode($rules)) > 20000) {
            Response::error('Regeldefinition zu groß', 400);
        }
        return [
            'name'        => $name,
            'type'        => Validation::oneOf($input['type'] ?? null, self::TYPES, 'gts'),
            'threshold'   => Validation::decimalOrNull($input['threshold_value'] ?? null),
            'color'       => Validation::color($input['color'] ?? null),
            'description' => Validation::text($input['description'] ?? null, 2000),
            'severity'    => Validation::oneOf($input['severity'] ?? null, self::SEVERITIES, 'info'),
            'alert'       => Validation::text($input['alert_message'] ?? null, 2000),
            'rules'       => $rules,
        ];
    }

    public function index(Request $request): void {
        $userId = Auth::userId();
        if ($request->method === 'GET') {
            $locationId = $request->queryInt('location_id');
            if ($locationId > 0) {
                Response::json(MarkerService::getForLocation($locationId, $userId));
            }
            Response::json(MarkerService::getAllByUser($userId));
        }
        if ($request->method !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        $p  = $this->markerPayload($request->input);
        $id = MarkerService::create($userId, $p['name'], $p['type'], $p['threshold'], $p['color'], $p['description'], $p['severity'], $p['alert'], $p['rules']);
        Response::json(['success' => true, 'id' => $id]);
    }

    public function update(Request $request): void {
        $p  = $this->markerPayload($request->input);
        $ok = MarkerService::update($request->inputInt('id'), Auth::userId(), $p['name'], $p['type'], $p['threshold'], $p['color'], $p['description'], $p['severity'], $p['alert'], $p['rules']);
        Response::json(['success' => $ok]);
    }

    public function predictions(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $year       = $request->queryInt('year', (int) date('Y'));
        $this->checkLocationAccess($locationId);

        $gtsData = GTSService::calculate($locationId, $year);
        Response::json(RuleEngine::predictMarkers($locationId, Auth::userId(), $gtsData));
    }

    public function ruleTransitions(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $year       = $request->queryInt('year', (int) date('Y'));
        $this->checkLocationAccess($locationId);

        $gtsData = GTSService::calculate($locationId, $year);
        Response::json(RuleEngine::predictGTSRuleTransitions($locationId, Auth::userId(), $gtsData));
    }

    public function evaluate(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $date       = Validation::dateOr($request->query['date'] ?? null);
        $year       = (int) substr($date, 0, 4);
        $this->checkLocationAccess($locationId);

        $gtsData = GTSService::calculate($locationId, $year);
        Response::json(RuleEngine::evaluateForDate($locationId, Auth::userId(), $date, $gtsData));
    }

    public function templates(Request $request): void {
        Response::json(RuleEngine::getTemplates());
    }

    public function historicalStats(Request $request): void {
        $locationId = $request->queryInt('location_id');
        $this->checkLocationAccess($locationId);

        if (empty($request->input)) {
            Response::error('location_id und Bedingungsdefinition erforderlich', 400);
        }
        Response::json(RuleEngine::getHistoricalStats($locationId, $request->input));
    }

    public function delete(Request $request): void {
        $ok = MarkerService::delete($request->inputInt('id'), Auth::userId());
        Response::json(['success' => $ok]);
    }

    public function assign(Request $request): void {
        $markerId = $request->inputInt('marker_id');
        $locId    = $request->inputInt('location_id');

        if (!MarkerService::getById($markerId, Auth::userId()) || !\LocationService::getById($locId, Auth::userId())) {
            Response::error('Keine Berechtigung', 403);
        }
        MarkerService::assignToLocation($markerId, $locId);
        Response::json(['success' => true]);
    }

    public function remove(Request $request): void {
        $markerId = $request->inputInt('marker_id');
        $locId    = $request->inputInt('location_id');

        if (!MarkerService::getById($markerId, Auth::userId()) || !\LocationService::getById($locId, Auth::userId())) {
            Response::error('Keine Berechtigung', 403);
        }
        MarkerService::removeFromLocation($markerId, $locId);
        Response::json(['success' => true]);
    }
}
