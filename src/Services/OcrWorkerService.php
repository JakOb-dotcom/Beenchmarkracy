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
 * OCR-Worker-Service
 *
 * Kapselt alles rund um den Foto-Upload für die Handschrift-Erkennung:
 *   - Validierung und Ablage der hochgeladenen Bilddatei
 *   - Job-Verwaltung in der Tabelle ocr_jobs
 *   - asynchroner Start des Python-Workers (PythonWorkerOCR/worker.py)
 *
 * Der Worker bekommt DB-Zugangsdaten per Umgebungsvariable und die
 * Benutzer-/Job-ID als Argument, damit er nur Völker dieses Benutzers
 * beschreibt und den Job-Status zurückmelden kann.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

class OcrWorkerService
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];

    /**
     * Validiert eine hochgeladene Datei ($_FILES-Eintrag) und verschiebt sie ins Upload-Verzeichnis.
     * Gibt [absoluterPfad, dateiname] zurück oder wirft InvalidArgumentException.
     */
    public static function storeUpload(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload fehlgeschlagen oder keine Datei übermittelt.');
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > OCR_MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Datei zu groß (max. ' . (int) (OCR_MAX_UPLOAD_BYTES / 1048576) . ' MB).');
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new InvalidArgumentException('Ungültiger Upload.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new InvalidArgumentException('Nur JPEG- oder PNG-Bilder werden unterstützt.');
        }
        // Zusätzlich sicherstellen, dass es ein dekodierbares Bild ist
        if (@getimagesize($file['tmp_name']) === false) {
            throw new InvalidArgumentException('Datei ist kein gültiges Bild.');
        }

        $dir = OCR_UPLOAD_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new RuntimeException('Upload-Verzeichnis kann nicht angelegt werden.');
        }
        self::ensureHtaccess($dir);

        $filename = sprintf('ocr_u%d_%s_%s.%s', $userId, date('Ymd_His'), bin2hex(random_bytes(4)), self::ALLOWED_MIME[$mime]);
        $dest     = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }
        @chmod($dest, 0640);

        return [$dest, $filename];
    }

    /** Legt einen neuen Job an und gibt dessen ID zurück. */
    public static function createJob(int $userId, string $filename, string $originalName): int
    {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO ocr_jobs (user_id, filename, original_name, status) VALUES (:u, :f, :o, :s)');
        $stmt->execute([
            ':u' => $userId,
            ':f' => $filename,
            ':o' => mb_substr($originalName, 0, 255),
            ':s' => 'pending',
        ]);
        return (int) $db->lastInsertId();
    }

    public static function updateJob(int $jobId, string $status, ?string $message = null): void
    {
        $stmt = getDB()->prepare('UPDATE ocr_jobs SET status = :s, message = :m WHERE id = :id');
        $stmt->execute([':s' => $status, ':m' => $message, ':id' => $jobId]);
    }

    /** Letzte Jobs eines Benutzers (neueste zuerst). */
    public static function listJobs(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = getDB()->prepare(
            "SELECT id, original_name, status, message, form_type, records_saved, created_at, updated_at
             FROM ocr_jobs WHERE user_id = :u ORDER BY id DESC LIMIT $limit"
        );
        $stmt->execute([':u' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Ermittelt den zu verwendenden Python-Interpreter.
     */
    public static function pythonBinary(): ?string
    {
        if (OCR_PYTHON_BIN !== '') {
            return OCR_PYTHON_BIN;
        }
        $venv = APP_ROOT . '/PythonWorkerOCR/.venv/';
        foreach (['Scripts/python.exe', 'bin/python3', 'bin/python'] as $candidate) {
            if (is_file($venv . $candidate)) {
                return $venv . $candidate;
            }
        }
        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    /**
     * Startet den Worker asynchron (nicht blockierend) und gibt true zurück, wenn
     * der Prozess gestartet werden konnte.
     */
    public static function launch(string $imagePath, int $userId, int $jobId): bool
    {
        $workerScript = APP_ROOT . '/PythonWorkerOCR/worker.py';
        if (!is_file($workerScript)) {
            error_log('OcrWorkerService: worker.py nicht gefunden: ' . $workerScript);
            return false;
        }

        $python = self::pythonBinary();
        if ($python === null) {
            return false;
        }

        // DB-Zugang & Log-Pfad per Umgebung an den Kindprozess weiterreichen
        $env = [
            'DB_HOST'      => DB_HOST,
            'DB_PORT'      => (string) DB_PORT,
            'DB_USER'      => DB_USER,
            'DB_PASS'      => DB_PASS,
            'DB_NAME'      => DB_NAME,
            'OCR_LOG_FILE' => OCR_LOG_FILE,
            'PYTHONIOENCODING' => 'utf-8',
        ];
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }

        $logDir = dirname(OCR_LOG_FILE);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        $args = sprintf(
            '%s %s --user-id %d --job-id %d --delete',
            escapeshellarg($workerScript),
            escapeshellarg($imagePath),
            $userId,
            $jobId
        );

        if (PHP_OS_FAMILY === 'Windows') {
            // "start" braucht einen (leeren) Fenstertitel, sonst wird das erste
            // Argument in Anführungszeichen als Titel interpretiert.
            $cmd = 'start /B "" ' . escapeshellarg($python) . ' ' . $args . ' > NUL 2>&1';
            $handle = popen($cmd, 'r');
        } else {
            $cmd = escapeshellarg($python) . ' ' . $args . ' > /dev/null 2>&1 &';
            $handle = popen($cmd, 'r');
        }

        if ($handle === false) {
            error_log('OcrWorkerService: Worker konnte nicht gestartet werden: ' . $cmd);
            return false;
        }
        pclose($handle);
        return true;
    }

    private static function ensureHtaccess(string $dir): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($file)) {
            @file_put_contents($file, "Require all denied\n");
        }
    }
}
