# Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU Affero General Public License as published by the Free
# Software Foundation, either version 3 of the License, or (at your option) any
# later version. See the LICENSE file in the project root for details.

"""
Datenbankzugriff des OCR-Workers.

Alle Schreiboperationen sind auf Völker des übergebenen Benutzers beschränkt
(hive_belongs_to_user), damit ein Formular mit einer fremden Volk-ID keine
Daten anderer Benutzer verändern kann.

Die Tabellen-/Typ-Konventionen entsprechen dem PHP-Backend
(api/controllers/RecordController.php, assets/js/components/RecordManager.js).
"""
import logging
import os
from contextlib import contextmanager

import mysql.connector

logger = logging.getLogger('db')

# Formulartyp (aus dem Kopf des gedruckten Formulars) → hive_records.type + Standard-Einheit
_FORM_TYPE_MAP = {
    'Honigernte':       ('harvest', 'kg'),
    'Varroabehandlung': ('varroa',  'ml'),
    'Einfütterung':     ('feed',    'l/kg'),
}

_OCR_TAG = '[KI OCR]'


class Database:
    def __init__(self):
        self.host     = os.environ.get('DB_HOST', 'localhost')
        self.port     = int(os.environ.get('DB_PORT', '3306') or 3306)
        self.user     = os.environ.get('DB_USER', 'root')
        self.password = os.environ.get('DB_PASS', '')
        self.database = os.environ.get('DB_NAME', 'forecasting')

    def _connect(self):
        return mysql.connector.connect(
            host=self.host,
            port=self.port,
            user=self.user,
            password=self.password,
            database=self.database,
            charset='utf8mb4',
        )

    @contextmanager
    def _cursor(self, dictionary: bool = False):
        conn = self._connect()
        try:
            cur = conn.cursor(dictionary=dictionary)
            yield cur
            conn.commit()
        except mysql.connector.Error:
            conn.rollback()
            raise
        finally:
            conn.close()

    # ── Lesen ────────────────────────────────────────────────────────────

    def hive_belongs_to_user(self, hive_id, user_id: int) -> bool:
        try:
            with self._cursor() as cur:
                cur.execute(
                    "SELECT 1 FROM hives h JOIN locations l ON h.location_id = l.id "
                    "WHERE h.id = %s AND l.user_id = %s LIMIT 1",
                    (hive_id, user_id),
                )
                return cur.fetchone() is not None
        except mysql.connector.Error as err:
            logger.error(f"DB-Fehler bei Volk-Prüfung: {err}")
            return False

    def valid_hive_ids(self, user_id: int) -> list:
        """IDs aller Völker des Benutzers (für die ID-Plausibilisierung der OCR)."""
        try:
            with self._cursor() as cur:
                cur.execute(
                    "SELECT h.id FROM hives h JOIN locations l ON h.location_id = l.id "
                    "WHERE l.user_id = %s ORDER BY h.id ASC",
                    (user_id,),
                )
                return [row[0] for row in cur.fetchall()]
        except mysql.connector.Error as err:
            logger.warning(f"Konnte Volk-IDs nicht laden: {err}")
            return []

    def record_settings(self, user_id: int) -> list:
        """Kategorien mit Kürzel des Benutzers (für die Kategorie-Erkennung im Formularkopf)."""
        try:
            with self._cursor(dictionary=True) as cur:
                cur.execute(
                    "SELECT id, category, short_code FROM record_settings "
                    "WHERE user_id = %s AND short_code IS NOT NULL AND short_code <> ''",
                    (user_id,),
                )
                return cur.fetchall()
        except mysql.connector.Error as err:
            logger.warning(f"Konnte Kategorien nicht laden: {err}")
            return []

    # ── Job-Status ───────────────────────────────────────────────────────

    def update_job(self, job_id, status: str, message, form_type=None, records_saved=None) -> None:
        if not job_id:
            return
        try:
            with self._cursor() as cur:
                cur.execute(
                    "UPDATE ocr_jobs SET status = %s, message = %s, "
                    "form_type = COALESCE(%s, form_type), records_saved = COALESCE(%s, records_saved) "
                    "WHERE id = %s",
                    (status, (message or '')[:2000] or None, form_type, records_saved, job_id),
                )
        except mysql.connector.Error as err:
            logger.error(f"DB-Fehler beim Job-Update: {err}")

    # ── Schreiben ────────────────────────────────────────────────────────

    def save_varroa_scan(self, hive_id, mite_count: int) -> bool:
        query = """
            INSERT INTO hive_records (hive_id, record_date, type, amount, unit, notes)
            VALUES (%s, CURDATE(), 'varroa_drop', %s, 'Milben',
                    'Automatischer KI Varroa-Scan (Bodenschieber)')
        """
        try:
            with self._cursor() as cur:
                cur.execute(query, (hive_id, mite_count))
            logger.info(f"Varroa-Scan: Volk {hive_id} – {mite_count} Milben gespeichert.")
            return True
        except mysql.connector.Error as err:
            logger.error(f"DB-Fehler beim Speichern des Varroa-Scans: {err}")
            return False

    def save_form_data(self, record_type: str, records: list, user_id: int, setting_id=None) -> tuple:
        """Speichert alle erkannten Zeilen; gibt (gespeichert, fehler) zurück."""
        if not records:
            return 0, 0

        if setting_id is not None and not self._setting_belongs_to_user(setting_id, user_id):
            logger.warning(f"Kategorie {setting_id} gehört nicht zu Benutzer {user_id} – wird ignoriert.")
            setting_id = None

        saved = 0
        errors = 0
        for rec in records:
            hive_id = rec.get('volk_id')
            try:
                if not hive_id:
                    raise ValueError('keine Volk-ID')
                if not self.hive_belongs_to_user(hive_id, user_id):
                    raise ValueError(f'Volk {hive_id} gehört nicht zu Benutzer {user_id}')
                self._insert_record(record_type, rec, setting_id)
                saved += 1
            except Exception as e:
                logger.error(f"Fehler bei Volk {hive_id or '?'} ('{record_type}'): {e}")
                errors += 1

        logger.info(f"Formular '{record_type}': {saved} gespeichert" + (f", {errors} Fehler" if errors else ""))
        return saved, errors

    def _setting_belongs_to_user(self, setting_id, user_id: int) -> bool:
        try:
            with self._cursor() as cur:
                cur.execute("SELECT 1 FROM record_settings WHERE id = %s AND user_id = %s", (setting_id, user_id))
                return cur.fetchone() is not None
        except mysql.connector.Error:
            return False

    def _insert_record(self, record_type: str, rec: dict, setting_id) -> None:
        hive_id     = rec.get('volk_id')
        notiz       = (rec.get('notiz') or '').strip()
        record_date = rec.get('record_date') or None

        if record_type == 'Zuchtwerte':
            self._upsert_zuchtwerte(hive_id, rec, notiz, record_date)
        elif record_type == 'Stockkarte':
            self._insert_stockkarte(hive_id, rec, notiz, record_date)
        elif record_type in _FORM_TYPE_MAP:
            self._insert_hive_record(record_type, hive_id, rec, notiz, record_date, setting_id)
        else:
            raise ValueError(f"Unbekannter Formulartyp '{record_type}'")

    @staticmethod
    def _parse_score(val, label: str, notiz: str):
        """Zuchtwert 1-10 oder None; unlesbare Werte wandern als Hinweis in die Notiz."""
        if not val:
            return None, notiz
        try:
            n = int(round(float(str(val).replace(',', '.'))))
        except ValueError:
            return None, f"[{label}:{val}] {notiz}".strip()
        return max(1, min(10, n)), notiz

    def _upsert_zuchtwerte(self, hive_id, rec: dict, notiz: str, record_date) -> None:
        h_val, notiz = self._parse_score(rec.get('value_1', ''), 'H', notiz)
        g_val, notiz = self._parse_score(rec.get('value_2', ''), 'G', notiz)
        s_val, notiz = self._parse_score(rec.get('value_3', ''), 'S', notiz)
        w_val, notiz = self._parse_score(rec.get('value_4', ''), 'W', notiz)
        v_val, notiz = self._parse_score(rec.get('value_5', ''), 'V', notiz)

        final_notes = f'{_OCR_TAG} {notiz}'.strip()[:255]

        with self._cursor() as cur:
            # Pro Volk und Datum nur eine Bewertung (wie im PHP-Backend)
            cur.execute(
                "SELECT id FROM hive_evaluations WHERE hive_id = %s AND evaluation_date = COALESCE(%s, CURDATE())",
                (hive_id, record_date),
            )
            row = cur.fetchone()
            if row:
                cur.execute(
                    "UPDATE hive_evaluations SET score_honey=%s, score_gentleness=%s, score_steadiness=%s, "
                    "score_swarming=%s, score_varroa=%s, notes=%s WHERE id=%s",
                    (h_val, g_val, s_val, w_val, v_val, final_notes, row[0]),
                )
            else:
                cur.execute(
                    "INSERT INTO hive_evaluations (hive_id, evaluation_date, score_honey, score_gentleness, "
                    "score_steadiness, score_swarming, score_varroa, notes) "
                    "VALUES (%s, COALESCE(%s, CURDATE()), %s, %s, %s, %s, %s, %s)",
                    (hive_id, record_date, h_val, g_val, s_val, w_val, v_val, final_notes),
                )

    def _insert_stockkarte(self, hive_id, rec: dict, notiz: str, record_date) -> None:
        """
        Stockkarte wird – wie im Web-Frontend – als hive_records-Eintrag vom Typ
        'status' gespeichert; die Kästchen/Zahlen stehen kodiert in notes:
        "[Stockkarte] KL:X SS:X SW:X HR:2 BW:3 <Freitext>"
        """
        hr_raw = str(rec.get('boxes', '') or '')
        bw_raw = str(rec.get('frames', '') or '')
        hr_num = int(hr_raw) if hr_raw.isdigit() else 0
        bw_num = int(bw_raw) if bw_raw.isdigit() else 0

        parts = []
        if rec.get('q_not_laying'):
            parts.append('KL:X')
        if rec.get('s_mood'):
            parts.append('SS:X')
        if rec.get('s_swarm'):
            parts.append('SW:X')
        if hr_num:
            parts.append(f'HR:{hr_num}')
        if bw_num:
            parts.append(f'BW:{bw_num}')

        notes = ' '.join(['[Stockkarte]'] + parts + [_OCR_TAG] + ([notiz] if notiz else []))

        with self._cursor() as cur:
            cur.execute(
                "INSERT INTO hive_records (hive_id, record_date, type, setting_id, amount, unit, notes) "
                "VALUES (%s, COALESCE(%s, CURDATE()), 'status', NULL, NULL, '-', %s)",
                (hive_id, record_date, notes[:2000]),
            )

    def _insert_hive_record(self, record_type: str, hive_id, rec: dict, notiz: str, record_date, setting_id) -> None:
        db_type, default_unit = _FORM_TYPE_MAP[record_type]

        amount = None
        val1 = (rec.get('value_1') or '').strip()
        if val1:
            try:
                amount = float(val1.replace(',', '.'))
            except ValueError:
                notiz = f'[{val1}] {notiz}'.strip()

        final_notes = f'{_OCR_TAG} {notiz}'.strip()

        with self._cursor() as cur:
            cur.execute(
                "INSERT INTO hive_records (hive_id, record_date, type, setting_id, amount, unit, notes) "
                "VALUES (%s, COALESCE(%s, CURDATE()), %s, %s, %s, %s, %s)",
                (hive_id, record_date, db_type, setting_id, amount, default_unit, final_notes[:2000]),
            )
