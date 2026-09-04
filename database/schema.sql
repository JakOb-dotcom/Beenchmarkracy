-- =====================================================
-- Beenchmarkracy – Vollständiges Datenbank-Schema
-- Stand: 2026-09-04
--
-- Neuinstallation:
--   mysql -u root -p < database/schema.sql
--   php scripts/create_user.php <benutzername>
--
-- Bestehende Installation aktualisieren:
--   siehe database/migrations/
-- =====================================================

CREATE DATABASE IF NOT EXISTS `forecasting`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `forecasting`;

SET NAMES utf8mb4;

-- ── Benutzer ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(100)  NOT NULL UNIQUE,
    `password_hash` VARCHAR(255)  NOT NULL COMMENT 'password_hash() / bcrypt',
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Login-Fehlversuche (Brute-Force-Schutz) ─────────
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip`           VARCHAR(45)  NOT NULL,
    `username`     VARCHAR(100) NULL,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_la_ip_time` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Standorte ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `locations` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(200) NOT NULL,
    `latitude`    DECIMAL(9,6) NOT NULL,
    `longitude`   DECIMAL(9,6) NOT NULL,
    `altitude`    INT          NULL COMMENT 'Höhe in m (optional)',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `user_id`     INT UNSIGNED NOT NULL,
    KEY `idx_loc_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Historische Wetterdaten (pro Tag & Standort) ─────
CREATE TABLE IF NOT EXISTS `weather_history` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `location_id`     INT UNSIGNED NOT NULL,
    `date`            DATE         NOT NULL,
    `temp_mean`       DECIMAL(5,2) NULL COMMENT '°C Tagesmittel',
    `temp_min`        DECIMAL(5,2) NULL,
    `temp_max`        DECIMAL(5,2) NULL,
    `precipitation`   DECIMAL(7,2) NULL COMMENT 'mm',
    `pressure`        DECIMAL(7,2) NULL COMMENT 'hPa',
    `soil_moisture`   DECIMAL(7,4) NULL COMMENT 'm³/m³',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_loc_date` (`location_id`, `date`),
    KEY `idx_wh_date` (`date`),
    FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Vorhersagedaten (werden täglich rotiert) ─────────
CREATE TABLE IF NOT EXISTS `weather_forecast` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `location_id`     INT UNSIGNED NOT NULL,
    `date`            DATE         NOT NULL,
    `temp_mean`       DECIMAL(5,2) NULL,
    `temp_min`        DECIMAL(5,2) NULL,
    `temp_max`        DECIMAL(5,2) NULL,
    `precipitation`   DECIMAL(7,2) NULL,
    `pressure`        DECIMAL(7,2) NULL,
    `soil_moisture`   DECIMAL(7,4) NULL,
    `fetched_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_fc_loc_date` (`location_id`, `date`),
    KEY `idx_wf_date` (`date`),
    FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 30-jährige Klimanormale ──────────────────────────
CREATE TABLE IF NOT EXISTS `climate_normals` (
    `location_id`      INT UNSIGNED     NOT NULL,
    `month`            TINYINT UNSIGNED NOT NULL COMMENT '1-12',
    `avg_temp`         DECIMAL(5,2)     NULL COMMENT 'Ø Tagesmitteltemperatur des Monats (°C)',
    `avg_precip`       DECIMAL(7,2)     NULL COMMENT 'Ø Monatsniederschlag gesamt (mm)',
    `reference_period` VARCHAR(20)      DEFAULT '1995-2024' COMMENT 'Referenzzeitraum',
    `updated_at`       TIMESTAMP        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`location_id`, `month`),
    FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Notizen für Standorte ────────────────────────────
CREATE TABLE IF NOT EXISTS `location_notes` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `location_id` INT UNSIGNED NOT NULL,
    `note`        TEXT NOT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ln_location` (`location_id`),
    FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Benutzerdefinierte Marker ────────────────────────
CREATE TABLE IF NOT EXISTS `markers` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `name`            VARCHAR(200) NOT NULL,
    `type`            ENUM('gts','temperature_deviation','precipitation_deviation','complex','custom') NOT NULL DEFAULT 'gts',
    `threshold_value` DECIMAL(10,2) NULL COMMENT 'Einfacher GTS-Schwellwert',
    `color`           VARCHAR(7)   NOT NULL DEFAULT '#4caf50',
    `severity`        ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `description`     TEXT         NULL,
    `alert_message`   TEXT         NULL COMMENT 'Nachricht bei Auslösung des Markers',
    `rules`           JSON         NULL COMMENT 'Komplexe Regeln als JSON-Regelbaum (siehe src/Engine/RuleEngine.php)',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_markers_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Marker-Standort-Zuordnung ────────────────────────
CREATE TABLE IF NOT EXISTS `marker_locations` (
    `marker_id`   INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`marker_id`, `location_id`),
    FOREIGN KEY (`marker_id`)   REFERENCES `markers`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Bienenvölker ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `hives` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `location_id` INT UNSIGNED NOT NULL,
    `name`        VARCHAR(100) NOT NULL,
    `genetics`    VARCHAR(100) DEFAULT '',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_hives_location` (`location_id`),
    FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Zucht-Bewertungen der Völker (Noten 1-10) ────────
CREATE TABLE IF NOT EXISTS `hive_evaluations` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hive_id`          INT UNSIGNED NOT NULL,
    `evaluation_date`  DATE NOT NULL,
    `score_honey`      TINYINT UNSIGNED DEFAULT NULL,
    `score_gentleness` TINYINT UNSIGNED DEFAULT NULL,
    `score_steadiness` TINYINT UNSIGNED DEFAULT NULL,
    `score_swarming`   TINYINT UNSIGNED DEFAULT NULL,
    `score_varroa`     TINYINT UNSIGNED DEFAULT NULL,
    `notes`            VARCHAR(255) DEFAULT NULL COMMENT 'z.B. Herkunft [KI OCR]',
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_he_hive_date` (`hive_id`, `evaluation_date`),
    FOREIGN KEY (`hive_id`) REFERENCES `hives`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Notizen für Völker ───────────────────────────────
CREATE TABLE IF NOT EXISTS `hive_notes` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hive_id`     INT UNSIGNED NOT NULL,
    `note`        TEXT NOT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_hn_hive` (`hive_id`),
    FOREIGN KEY (`hive_id`) REFERENCES `hives`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Kategorien für Aufzeichnungen ────────────────────
-- category: harvest (Honigsorte) | feed (Futterart) | varroa (Behandlungsmittel)
-- sugar_g / water_ml: optionale Rezeptur pro Einheit bei Futterarten
CREATE TABLE IF NOT EXISTS `record_settings` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `category`   VARCHAR(50)  NOT NULL,
    `name`       VARCHAR(100) NOT NULL,
    `short_code` VARCHAR(20)  NULL COMMENT 'Kürzel auf gedruckten OCR-Formularen',
    `unit`       VARCHAR(20)  NULL,
    `sugar_g`    INT          NULL,
    `water_ml`   INT          NULL,
    `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rs_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Aufzeichnungen pro Volk ──────────────────────────
-- type: harvest | feed | varroa | varroa_drop (KI-Bodenschieber-Scan) | status (Stockkarte)
-- Stockkarten-Status wird in notes kodiert: "[Stockkarte] KL:X SS:X SW:X HR:2 BW:3 <Freitext>"
CREATE TABLE IF NOT EXISTS `hive_records` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hive_id`     INT UNSIGNED NOT NULL,
    `record_date` DATE         NOT NULL,
    `type`        VARCHAR(50)  NOT NULL,
    `setting_id`  INT UNSIGNED NULL,
    `amount`      DECIMAL(10,2) NULL,
    `unit`        VARCHAR(20)  NULL,
    `notes`       TEXT         NULL,
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_hr_hive_date` (`hive_id`, `record_date`),
    KEY `idx_hr_type` (`type`),
    FOREIGN KEY (`hive_id`)    REFERENCES `hives`(`id`)           ON DELETE CASCADE,
    FOREIGN KEY (`setting_id`) REFERENCES `record_settings`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── OCR-Aufträge (Foto-Upload → Python-Worker) ───────
-- status: pending | processing | done | failed
CREATE TABLE IF NOT EXISTS `ocr_jobs` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT UNSIGNED NOT NULL,
    `filename`      VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    `form_type`     VARCHAR(50)  NULL COMMENT 'Erkannter Formulartyp bzw. bottom_board',
    `records_saved` INT          NULL,
    `message`       TEXT         NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_oj_user` (`user_id`, `id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- Ersten Benutzer anlegen (empfohlen per CLI-Skript):
--   php scripts/create_user.php admin
--
-- Alternativ manuell (Hash mit PHP erzeugen:
--   php -r "echo password_hash('geheim', PASSWORD_DEFAULT);"):
-- INSERT INTO users (username, password_hash) VALUES ('admin', '$2y$10$...');
-- =====================================================
