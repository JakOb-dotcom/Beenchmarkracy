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
use PDO;
use Validation;

class HiveController {

    private const EVAL_SELECT = 'SELECT hive_id, id, evaluation_date AS date, score_honey, score_gentleness,
                                        score_steadiness, score_swarming, score_varroa, notes
                                 FROM hive_evaluations WHERE hive_id IN (%s) ORDER BY evaluation_date ASC';

    private function checkHiveAccess(int $hiveId): void {
        $db = getDB();
        $stmt = $db->prepare('SELECT h.id FROM hives h JOIN locations l ON h.location_id = l.id WHERE h.id = ? AND l.user_id = ?');
        $stmt->execute([$hiveId, Auth::userId()]);
        if (!$stmt->fetch()) {
            Response::error('Keine Berechtigung', 403);
        }
    }

    /** Hängt Bewertungen (und optional Notizen) an eine Liste von Völkern. */
    private function attachRelations(PDO $db, array $hives, bool $withNotes): array {
        if (count($hives) === 0) {
            return $hives;
        }
        $hiveIds      = array_column($hives, 'id');
        $placeholders = implode(',', array_fill(0, count($hiveIds), '?'));

        $stmtEvals = $db->prepare(sprintf(self::EVAL_SELECT, $placeholders));
        $stmtEvals->execute($hiveIds);
        $allEvals = $stmtEvals->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

        $allNotes = [];
        if ($withNotes) {
            $stmtNotes = $db->prepare("SELECT hive_id, id, note, created_at FROM hive_notes WHERE hive_id IN ($placeholders) ORDER BY created_at DESC");
            $stmtNotes->execute($hiveIds);
            $allNotes = $stmtNotes->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        }

        foreach ($hives as &$hive) {
            $hive['evaluations'] = $allEvals[$hive['id']] ?? [];
            if ($withNotes) {
                $hive['notes'] = $allNotes[$hive['id']] ?? [];
            }
        }
        unset($hive);
        return $hives;
    }

    public function countAll(Request $request): void {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM hives h JOIN locations l ON h.location_id = l.id WHERE l.user_id = ?');
        $stmt->execute([Auth::userId()]);
        Response::json(['success' => true, 'count' => (int) $stmt->fetchColumn()]);
    }

    public function getAll(Request $request): void {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT h.id, h.location_id, h.name, h.genetics, h.created_at, l.name AS location_name
            FROM hives h
            JOIN locations l ON h.location_id = l.id
            WHERE l.user_id = ?
            ORDER BY l.name ASC, h.id ASC
        ');
        $stmt->execute([Auth::userId()]);
        Response::json(['success' => true, 'hives' => $this->attachRelations($db, $stmt->fetchAll(), false)]);
    }

    public function getByLocation(Request $request): void {
        $locationId = $request->queryInt('location_id');
        if (!\LocationService::getById($locationId, Auth::userId())) {
            Response::error('Keine Berechtigung', 403);
        }
        $db = getDB();
        $stmt = $db->prepare('SELECT id, location_id, name, genetics, created_at FROM hives WHERE location_id = ? ORDER BY id ASC');
        $stmt->execute([$locationId]);
        Response::json(['success' => true, 'hives' => $this->attachRelations($db, $stmt->fetchAll(), true)]);
    }

    public function create(Request $request): void {
        $locationId = $request->inputInt('location_id');
        $name       = Validation::text($request->input['name'] ?? null, 100) ?? 'Neues Volk';
        if (!\LocationService::getById($locationId, Auth::userId())) {
            Response::error('Keine Berechtigung', 403);
        }

        $db = getDB();
        $stmt = $db->prepare('INSERT INTO hives (location_id, name) VALUES (?, ?)');
        $stmt->execute([$locationId, $name]);
        Response::json(['success' => true, 'id' => (int) $db->lastInsertId()]);
    }

    public function update(Request $request): void {
        $hiveId = $request->inputInt('hive_id');
        $this->checkHiveAccess($hiveId);

        $name     = Validation::text($request->input['name'] ?? null, 100);
        $genetics = array_key_exists('genetics', $request->input) ? (Validation::text($request->input['genetics'], 100) ?? '') : null;

        $db = getDB();
        if ($name !== null) {
            $db->prepare('UPDATE hives SET name = ? WHERE id = ?')->execute([$name, $hiveId]);
        }
        if ($genetics !== null) {
            $db->prepare('UPDATE hives SET genetics = ? WHERE id = ?')->execute([$genetics, $hiveId]);
        }
        Response::json(['success' => true]);
    }

    public function bulkUpdateGenetics(Request $request): void {
        $hiveIds  = $request->input['hive_ids'] ?? [];
        $genetics = array_key_exists('genetics', $request->input) ? (Validation::text($request->input['genetics'], 100) ?? '') : null;
        if (!is_array($hiveIds) || count($hiveIds) === 0 || $genetics === null) {
            Response::error('Fehlende Parameter', 400);
        }
        $hiveIds = array_values(array_unique(array_map('intval', $hiveIds)));
        foreach ($hiveIds as $id) {
            $this->checkHiveAccess($id);
        }
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($hiveIds), '?'));
        $stmt = $db->prepare("UPDATE hives SET genetics = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$genetics], $hiveIds));
        Response::json(['success' => true]);
    }

    public function delete(Request $request): void {
        $hiveId = $request->inputInt('hive_id');
        $this->checkHiveAccess($hiveId);
        getDB()->prepare('DELETE FROM hives WHERE id = ?')->execute([$hiveId]);
        Response::json(['success' => true]);
    }

    private function clampScore(mixed $val): ?int {
        if ($val === null || $val === '' || !is_numeric($val)) {
            return null;
        }
        return max(1, min(10, (int) $val));
    }

    /** @return int[]|null[] [honey, gentleness, steadiness, swarming, varroa] */
    private function scoresFromInput(array $input): array {
        return [
            $this->clampScore($input['score_honey'] ?? null),
            $this->clampScore($input['score_gentleness'] ?? null),
            $this->clampScore($input['score_steadiness'] ?? null),
            $this->clampScore($input['score_swarming'] ?? null),
            $this->clampScore($input['score_varroa'] ?? null),
        ];
    }

    public function addEvaluation(Request $request): void {
        $hiveId = $request->inputInt('hive_id');
        $this->checkHiveAccess($hiveId);

        $date   = Validation::dateOr($request->input['date'] ?? null);
        $scores = $this->scoresFromInput($request->input);

        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM hive_evaluations WHERE hive_id = ? AND evaluation_date = ?');
        $stmt->execute([$hiveId, $date]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare('UPDATE hive_evaluations SET score_honey=?, score_gentleness=?, score_steadiness=?, score_swarming=?, score_varroa=? WHERE id=?');
            $stmt->execute([...$scores, $existing['id']]);
        } else {
            $stmt = $db->prepare('INSERT INTO hive_evaluations (hive_id, evaluation_date, score_honey, score_gentleness, score_steadiness, score_swarming, score_varroa) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$hiveId, $date, ...$scores]);
        }
        Response::json(['success' => true]);
    }

    public function updateEvaluation(Request $request): void {
        $evalId = $request->inputInt('eval_id');

        $db = getDB();
        $stmt = $db->prepare('SELECT he.id FROM hive_evaluations he JOIN hives h ON he.hive_id = h.id JOIN locations l ON h.location_id = l.id WHERE he.id = ? AND l.user_id = ?');
        $stmt->execute([$evalId, Auth::userId()]);
        if (!$stmt->fetch()) {
            Response::error('Keine Berechtigung oder Bewertung nicht gefunden', 403);
        }

        $scores = $this->scoresFromInput($request->input);
        $stmt = $db->prepare('UPDATE hive_evaluations SET score_honey=?, score_gentleness=?, score_steadiness=?, score_swarming=?, score_varroa=? WHERE id=?');
        $stmt->execute([...$scores, $evalId]);

        Response::json(['success' => true]);
    }

    public function deleteEvaluation(Request $request): void {
        $evalId = $request->inputInt('eval_id');
        $db = getDB();
        $stmt = $db->prepare('SELECT he.id FROM hive_evaluations he JOIN hives h ON he.hive_id = h.id JOIN locations l ON h.location_id = l.id WHERE he.id = ? AND l.user_id = ?');
        $stmt->execute([$evalId, Auth::userId()]);
        if (!$stmt->fetch()) {
            Response::error('Keine Berechtigung', 403);
        }
        $db->prepare('DELETE FROM hive_evaluations WHERE id = ?')->execute([$evalId]);
        Response::json(['success' => true]);
    }

    public function addNote(Request $request): void {
        $hiveId   = $request->inputInt('hive_id');
        $noteText = Validation::text($request->input['note'] ?? null, 5000);
        if ($noteText === null) {
            Response::error('Notiz darf nicht leer sein');
        }
        $this->checkHiveAccess($hiveId);

        getDB()->prepare('INSERT INTO hive_notes (hive_id, note) VALUES (?, ?)')->execute([$hiveId, $noteText]);
        Response::json(['success' => true]);
    }

    public function deleteNote(Request $request): void {
        $noteId = $request->inputInt('id');
        $db = getDB();
        $stmt = $db->prepare('SELECT hn.id FROM hive_notes hn JOIN hives h ON hn.hive_id = h.id JOIN locations l ON h.location_id = l.id WHERE hn.id = ? AND l.user_id = ?');
        $stmt->execute([$noteId, Auth::userId()]);
        if (!$stmt->fetch()) {
            Response::error('Keine Berechtigung', 403);
        }
        $db->prepare('DELETE FROM hive_notes WHERE id = ?')->execute([$noteId]);
        Response::json(['success' => true]);
    }

    public function transfer(Request $request): void {
        $hiveId = $request->inputInt('hive_id');
        // 'location_id' als Fallback für ältere Clients akzeptieren
        $targetLocationId = $request->inputInt('target_location_id') ?: $request->inputInt('location_id');

        if ($hiveId <= 0 || $targetLocationId <= 0) {
            Response::error('Fehlende Parameter', 400);
        }

        $this->checkHiveAccess($hiveId);
        if (!\LocationService::getById($targetLocationId, Auth::userId())) {
            Response::error('Ziel-Standort nicht gefunden oder keine Berechtigung', 403);
        }

        getDB()->prepare('UPDATE hives SET location_id = ? WHERE id = ?')->execute([$targetLocationId, $hiveId]);
        Response::json(['success' => true]);
    }
}
