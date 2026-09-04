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
use OcrWorkerService;
use PDO;
use Validation;

/**
 * Aufzeichnungen (Honigernte, Fütterung, Varroa, Stockkarte) sowie
 * Kategorien (record_settings) und der OCR-Foto-Upload.
 */
class RecordController {

    /** Erlaubte Aufzeichnungstypen in hive_records.type */
    public const ALLOWED_TYPES = ['harvest', 'feed', 'varroa', 'varroa_drop', 'status'];

    /** Erlaubte Kategorien in record_settings.category */
    public const ALLOWED_CATEGORIES = ['harvest', 'feed', 'varroa'];

    private function db(): PDO {
        return \getDB();
    }

    /** Prüft, ob das Volk dem eingeloggten Benutzer gehört. */
    private function hiveBelongsToUser(int $hiveId, int $userId): bool {
        $stmt = $this->db()->prepare(
            'SELECT h.id FROM hives h JOIN locations l ON h.location_id = l.id WHERE h.id = ? AND l.user_id = ?'
        );
        $stmt->execute([$hiveId, $userId]);
        return (bool) $stmt->fetch();
    }

    /** Prüft, ob eine Kategorie dem eingeloggten Benutzer gehört. */
    private function settingBelongsToUser(int $settingId, int $userId): bool {
        $stmt = $this->db()->prepare('SELECT id FROM record_settings WHERE id = ? AND user_id = ?');
        $stmt->execute([$settingId, $userId]);
        return (bool) $stmt->fetch();
    }

    /** Lädt einen Datensatz inkl. Besitzprüfung oder bricht mit 403 ab. */
    private function requireOwnedRecord(int $recordId, int $userId): void {
        if ($recordId <= 0) {
            Response::error('ID missing');
        }
        $stmt = $this->db()->prepare(
            'SELECT r.id FROM hive_records r JOIN hives h ON r.hive_id = h.id JOIN locations l ON h.location_id = l.id
             WHERE r.id = ? AND l.user_id = ?'
        );
        $stmt->execute([$recordId, $userId]);
        if (!$stmt->fetch()) {
            Response::error('Keine Berechtigung', 403);
        }
    }

    // ── Kategorien ────────────────────────────────────────

    public function getSettings(Request $request): void {
        $stmt = $this->db()->prepare(
            'SELECT id, category, name, short_code, unit, sugar_g, water_ml
             FROM record_settings WHERE user_id = ? ORDER BY category, name'
        );
        $stmt->execute([Auth::userId()]);
        Response::json(['success' => true, 'settings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function addSetting(Request $request): void {
        $data      = $request->input;
        $category  = Validation::oneOf($data['category'] ?? null, self::ALLOWED_CATEGORIES, '');
        $name      = Validation::text($data['name'] ?? null, 100);
        $shortCode = Validation::text($data['short_code'] ?? null, 20);
        $unit      = Validation::text($data['unit'] ?? null, 20);
        $sugarG    = ($category === 'feed' && isset($data['sugar_g']) && is_numeric($data['sugar_g'])) ? max(0, (int) $data['sugar_g']) : null;
        $waterMl   = ($category === 'feed' && isset($data['water_ml']) && is_numeric($data['water_ml'])) ? max(0, (int) $data['water_ml']) : null;

        if ($category === '' || $name === null) {
            Response::error('Fehlende oder ungültige Eingaben', 400);
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO record_settings (user_id, category, name, short_code, unit, sugar_g, water_ml) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([Auth::userId(), $category, $name, $shortCode, $unit, $sugarG, $waterMl]);

        Response::json(['success' => true, 'id' => (int) $this->db()->lastInsertId()]);
    }

    public function deleteSetting(Request $request): void {
        $id = $request->inputInt('id');
        if ($id <= 0) {
            Response::error('ID missing');
        }
        $stmt = $this->db()->prepare('DELETE FROM record_settings WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, Auth::userId()]);
        Response::json(['success' => $stmt->rowCount() > 0]);
    }

    // ── Aufzeichnungen ────────────────────────────────────

    public function getRecords(Request $request): void {
        $userId     = Auth::userId();
        $hiveId     = $request->queryInt('hive_id');
        $locationId = $request->queryInt('location_id');
        $type       = Validation::oneOf($request->query['type'] ?? null, self::ALLOWED_TYPES, '');
        $export     = !empty($request->query['export']);

        if ($hiveId > 0 && !$this->hiveBelongsToUser($hiveId, $userId)) {
            Response::error('Keine Berechtigung', 403);
        }

        $sql = "SELECT r.id, r.hive_id, r.record_date, r.type, r.setting_id, r.amount, r.unit, r.notes, r.created_at,
                       s.name AS setting_name, s.short_code AS setting_short_code, s.sugar_g, s.water_ml,
                       h.name AS hive_name, l.id AS location_id, l.name AS location_name
                FROM hive_records r
                JOIN hives h ON r.hive_id = h.id
                JOIN locations l ON h.location_id = l.id
                LEFT JOIN record_settings s ON r.setting_id = s.id
                WHERE l.user_id = ?";
        $params = [$userId];

        if ($hiveId > 0) {
            $sql .= ' AND r.hive_id = ?';
            $params[] = $hiveId;
        }
        if ($locationId > 0) {
            $sql .= ' AND l.id = ?';
            $params[] = $locationId;
        }
        if ($type !== '') {
            $sql .= ' AND r.type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY r.record_date DESC, r.id DESC';
        if (!$export) {
            $sql .= ' LIMIT 500';
        }

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        Response::json(['success' => true, 'records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function addRecord(Request $request): void {
        $data   = $request->input;
        $userId = Auth::userId();
        $db     = $this->db();

        $records = isset($data['records']) && is_array($data['records']) ? $data['records'] : [$data];
        if (count($records) > 500) {
            Response::error('Zu viele Datensätze in einem Request (max. 500)', 400);
        }

        $insert = $db->prepare(
            'INSERT INTO hive_records (hive_id, record_date, type, setting_id, amount, unit, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $insertedIds = [];
        $skipped     = 0;

        $db->beginTransaction();
        try {
            foreach ($records as $rec) {
                if (!is_array($rec)) { $skipped++; continue; }

                $hiveId = isset($rec['hive_id']) && is_numeric($rec['hive_id']) ? (int) $rec['hive_id'] : 0;
                $type   = Validation::oneOf($rec['type'] ?? null, self::ALLOWED_TYPES, '');
                $date   = $rec['record_date'] ?? null;

                if ($hiveId <= 0 || $type === '' || !Validation::isDate($date) || !$this->hiveBelongsToUser($hiveId, $userId)) {
                    $skipped++;
                    continue;
                }

                $settingId = isset($rec['setting_id']) && is_numeric($rec['setting_id']) ? (int) $rec['setting_id'] : null;
                if ($settingId !== null && ($settingId <= 0 || !$this->settingBelongsToUser($settingId, $userId))) {
                    $settingId = null;
                }

                $insert->execute([
                    $hiveId,
                    $date,
                    $type,
                    $settingId,
                    Validation::decimalOrNull($rec['amount'] ?? null),
                    Validation::text($rec['unit'] ?? null, 20),
                    Validation::text($rec['notes'] ?? null, 2000),
                ]);
                $insertedIds[] = (int) $db->lastInsertId();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json(['success' => true, 'ids' => $insertedIds, 'skipped' => $skipped]);
    }

    public function editRecord(Request $request): void {
        $data   = $request->input;
        $id     = $request->inputInt('id');
        $userId = Auth::userId();
        $this->requireOwnedRecord($id, $userId);

        $date = $data['record_date'] ?? null;
        if (!Validation::isDate($date)) {
            Response::error('Ungültiges Datum', 400);
        }

        $stmt = $this->db()->prepare('UPDATE hive_records SET record_date = ?, amount = ?, unit = ?, notes = ? WHERE id = ?');
        $stmt->execute([
            $date,
            Validation::decimalOrNull($data['amount'] ?? null),
            Validation::text($data['unit'] ?? null, 20),
            Validation::text($data['notes'] ?? null, 2000),
            $id,
        ]);

        Response::json(['success' => true]);
    }

    public function deleteRecord(Request $request): void {
        $id = $request->inputInt('id');
        $this->requireOwnedRecord($id, Auth::userId());

        $stmt = $this->db()->prepare('DELETE FROM hive_records WHERE id = ?');
        $stmt->execute([$id]);
        Response::json(['success' => true]);
    }

    // ── OCR-Upload ────────────────────────────────────────

    public function uploadOcrForm(Request $request): void {
        $userId = Auth::userId();

        if (!OCR_ENABLED) {
            Response::error('OCR-Verarbeitung ist auf diesem Server deaktiviert.', 503);
        }
        if (!isset($_FILES['ocrUpload'])) {
            Response::error('Keine Datei übermittelt.', 400);
        }

        try {
            [$path, $filename] = OcrWorkerService::storeUpload($_FILES['ocrUpload'], $userId);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        }

        $jobId = OcrWorkerService::createJob($userId, $filename, (string) ($_FILES['ocrUpload']['name'] ?? ''));

        if (!OcrWorkerService::launch($path, $userId, $jobId)) {
            OcrWorkerService::updateJob($jobId, 'failed', 'Worker konnte nicht gestartet werden (Python/OCR-Umgebung prüfen).');
            Response::error('OCR-Worker konnte nicht gestartet werden. Bitte Serverkonfiguration prüfen.', 500);
        }

        Response::json(['success' => true, 'job_id' => $jobId, 'filename' => $filename]);
    }

    public function getOcrJobs(Request $request): void {
        $limit = $request->queryInt('limit', 10);
        Response::json(['success' => true, 'jobs' => OcrWorkerService::listJobs(Auth::userId(), $limit)]);
    }
}
