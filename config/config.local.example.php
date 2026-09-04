<?php
/**
 * Lokale Konfiguration – Vorlage
 *
 * Kopieren nach config/config.local.php (wird von Git ignoriert) und anpassen.
 * Jeder Schlüssel ist optional; fehlende Werte fallen auf config.php zurück.
 */
return [
    'APP_ENV'   => 'development',   // production | development
    'APP_DEBUG' => true,

    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'forecasting',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // Nur lokal (XAMPP ohne gültiges CA-Bundle) auf false setzen
    'METEO_VERIFY_SSL' => false,

    // OCR-Worker: Pfad zum Python-Interpreter (leer = PythonWorkerOCR/.venv automatisch erkennen)
    'OCR_ENABLED'    => true,
    'OCR_PYTHON_BIN' => '',
];
