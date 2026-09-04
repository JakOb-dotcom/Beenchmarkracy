# Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU Affero General Public License as published by the Free
# Software Foundation, either version 3 of the License, or (at your option) any
# later version. See the LICENSE file in the project root for details.

"""
Headless OCR- & Varroa-Worker

Wird vom PHP-Backend (src/Services/OcrWorkerService.php) pro hochgeladenem
Bild asynchron gestartet:

    python worker.py <bild> --user-id <id> --job-id <id> [--delete]

Ablauf:
  1. Bildtyp bestimmen (Aufzeichnungsformular oder Varroa-Bodenschieber)
  2. Formular: Handschrift-Erkennung (core/htr.py) → hive_records / hive_evaluations
     Bodenschieber: Volk-ID per OCR + Milbenzählung per YOLO → hive_records
  3. Job-Status in ocr_jobs zurückmelden

DB-Zugangsdaten kommen aus Umgebungsvariablen (DB_HOST, DB_PORT, DB_USER,
DB_PASS, DB_NAME); das PHP-Backend setzt sie beim Start automatisch.
"""
import argparse
import logging
import logging.handlers
import os
import sys

os.environ.setdefault("PADDLE_DISABLE_PIR", "1")
os.environ.setdefault("FLAGS_enable_pir_api", "0")
os.environ.setdefault("FLAGS_use_mkldnn", "0")

# Immer relativ zum Skript importieren, egal von wo der Worker gestartet wird
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

# WICHTIG – Importreihenfolge: torch MUSS vor paddle/paddleocr geladen werden.
# Beide Pakete bringen unter Windows eine eigene libiomp5md.dll mit; wird die
# Paddle-Version zuerst geladen, schlägt das Laden von torch/shm.dll fehl
# (WinError 127). Deshalb hier ganz oben, bevor core.* importiert wird.
try:
    import torch  # noqa: F401
    from ultralytics import YOLO
    YOLO_AVAILABLE = True
    _YOLO_IMPORT_ERROR = None
except Exception as _err:  # ImportError, aber auch OSError bei defekten DLLs
    YOLO_AVAILABLE = False
    _YOLO_IMPORT_ERROR = _err

import cv2  # noqa: E402

from core.classifier import classify_image  # noqa: E402
from core.db import Database  # noqa: E402
from core.ocr import detect_colony_number  # noqa: E402


def _setup_logging() -> logging.Logger:
    fmt = logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s')
    root = logging.getLogger()
    root.setLevel(logging.INFO)

    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(fmt)
    root.addHandler(stream)

    log_file = os.environ.get('OCR_LOG_FILE') or os.path.join(
        os.path.dirname(os.path.abspath(__file__)), '..', 'logs', 'ocr_worker.log')
    try:
        os.makedirs(os.path.dirname(os.path.abspath(log_file)), exist_ok=True)
        fh = logging.handlers.RotatingFileHandler(log_file, maxBytes=2_000_000, backupCount=3, encoding='utf-8')
        fh.setFormatter(fmt)
        root.addHandler(fh)
    except OSError as e:  # Logdatei ist optional
        root.warning(f"Logdatei nicht schreibbar ({log_file}): {e}")

    # PaddleOCR ist sehr gesprächig
    logging.getLogger('ppocr').setLevel(logging.WARNING)
    return logging.getLogger('worker')


logger = _setup_logging()

if not YOLO_AVAILABLE:
    logger.warning(f"ultralytics/torch nicht verfügbar – Varroa-Scan deaktiviert: {_YOLO_IMPORT_ERROR}")


class WorkerResult:
    """Ergebnis eines Laufs, wird in ocr_jobs zurückgeschrieben."""

    def __init__(self):
        self.status = 'failed'
        self.form_type = None
        self.records_saved = 0
        self.message = ''


def run_varroa_pipeline(image_path: str, db: Database, user_id: int, result: WorkerResult) -> None:
    result.form_type = 'bottom_board'

    if not YOLO_AVAILABLE:
        result.message = 'Varroa-Scan nicht verfügbar: ultralytics/torch fehlt oder ist defekt.'
        logger.error(result.message)
        return

    model_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "model", "weights", "best.pt")
    if not os.path.exists(model_path):
        result.message = f'YOLO-Modell nicht gefunden: {model_path}'
        logger.error(result.message)
        return

    image_cv = cv2.imread(image_path)
    if image_cv is None:
        result.message = 'Bild konnte nicht geladen werden.'
        logger.error(result.message)
        return

    detected_id, _ = detect_colony_number(image_cv)
    if not detected_id:
        result.message = 'Volk-ID konnte in der Bildecke nicht gelesen werden – Scan nicht gespeichert.'
        logger.warning(result.message)
        return

    hive_id = int(detected_id)
    if not db.hive_belongs_to_user(hive_id, user_id):
        result.message = f'Volk-ID {hive_id} gehört nicht zu diesem Benutzer – Scan nicht gespeichert.'
        logger.warning(result.message)
        return

    model = YOLO(model_path)
    mite_count = 0
    try:
        results = model(image_path, imgsz=6016, max_det=2000, conf=0.1, iou=0.5, verbose=False)
        if results and len(results) > 0:
            mite_count = len(results[0].boxes)
        logger.info(f"YOLO: {mite_count} Milben erkannt.")
    except Exception as yolo_err:
        result.message = f'YOLO-Erkennungsfehler: {yolo_err}'
        logger.error(result.message)
        return

    if db.save_varroa_scan(hive_id, mite_count):
        result.status = 'done'
        result.records_saved = 1
        result.message = f'Volk {hive_id}: {mite_count} Milben gezählt.'
    else:
        result.message = 'Varroa-Scan konnte nicht gespeichert werden.'


def run_form_pipeline(image_path: str, db: Database, user_id: int, result: WorkerResult) -> None:
    from core.htr import get_form_type_and_data

    form_data = get_form_type_and_data(image_path, user_id=user_id)
    result.form_type = form_data['form_type']
    logger.info(
        f"Formular-Typ: {form_data['form_type']} | Datum: {form_data['date']} | "
        f"Setting-ID: {form_data['setting_id']} | {len(form_data['records'])} Zeilen"
    )

    if form_data['form_type'] == 'Unbekannt':
        result.message = 'Formular-Typ konnte nicht bestimmt werden – nichts gespeichert.'
        logger.warning(result.message)
        return

    if not form_data['records']:
        result.message = 'Keine Tabellenzeilen erkannt – nichts gespeichert.'
        logger.warning(result.message)
        return

    saved, errors = db.save_form_data(
        form_data['form_type'],
        form_data['records'],
        user_id=user_id,
        setting_id=form_data['setting_id'],
    )
    result.records_saved = saved
    if saved > 0:
        result.status = 'done'
        result.message = f"{form_data['form_type']} ({form_data['date'] or 'ohne Datum'}): {saved} Zeilen gespeichert"
        if errors:
            result.message += f", {errors} übersprungen"
        result.message += '.'
    else:
        result.message = f"{form_data['form_type']}: keine Zeile konnte gespeichert werden ({errors} Fehler)."


def main() -> int:
    parser = argparse.ArgumentParser(description="Headless OCR & Varroa Scanner Worker")
    parser.add_argument('image_path', help="Pfad zum hochgeladenen Bild")
    parser.add_argument('--user-id', type=int, required=True, help="Benutzer-ID, dem die Völker gehören müssen")
    parser.add_argument('--job-id', type=int, default=None, help="ID in ocr_jobs für Statusmeldungen")
    parser.add_argument('--delete', action='store_true', help="Bild nach Verarbeitung löschen")
    args = parser.parse_args()

    image_path = args.image_path
    result = WorkerResult()
    db = Database()

    if not os.path.exists(image_path):
        logger.error(f"Datei nicht gefunden: {image_path}")
        db.update_job(args.job_id, 'failed', 'Datei nicht gefunden.')
        return 1

    logger.info(f"Verarbeite Bild: {image_path} (Benutzer {args.user_id}, Job {args.job_id})")
    db.update_job(args.job_id, 'processing', None)

    try:
        image_type = classify_image(image_path)
        logger.info(f"Bild-Typ erkannt: {image_type}")

        if image_type == 'bottom_board':
            run_varroa_pipeline(image_path, db, args.user_id, result)
        elif image_type == 'form':
            run_form_pipeline(image_path, db, args.user_id, result)
        else:
            result.message = f'Unbekannter Bild-Typ: {image_type}'
            logger.error(result.message)

    except Exception as e:
        result.status = 'failed'
        result.message = f'Unerwarteter Fehler: {e}'
        logger.error(result.message, exc_info=True)

    finally:
        db.update_job(args.job_id, result.status, result.message,
                      form_type=result.form_type, records_saved=result.records_saved)
        logger.info(f"Job {args.job_id}: {result.status} – {result.message}")
        if args.delete:
            try:
                os.remove(image_path)
                logger.info(f"Bild gelöscht: {image_path}")
            except OSError as e:
                logger.error(f"Fehler beim Löschen: {e}")

    return 0 if result.status == 'done' else 1


if __name__ == '__main__':
    sys.exit(main())
