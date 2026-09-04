# Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU Affero General Public License as published by the Free
# Software Foundation, either version 3 of the License, or (at your option) any
# later version. See the LICENSE file in the project root for details.

"""
OCR-Testskript – kein DB-Zugriff, keine Schreiboperationen.
Führt Classifier, HTR und Colony-Erkennung aus und vergleicht mit Sollwerten.
"""
import os, sys, json
os.environ["PADDLE_DISABLE_PIR"] = "1"
os.environ["FLAGS_enable_pir_api"] = "0"
os.environ["FLAGS_use_mkldnn"] = "0"

import cv2
from core.classifier import classify_image
from core.htr import get_form_type_and_data
from core.ocr import detect_colony_number

SAMPLES = os.path.join(os.path.dirname(__file__), "Sample images")

# ── Sollwerte ────────────────────────────────────────────────────────────────
EXPECTED = {
    "varroa.jpeg": {
        "classifier": "form",
        "form_type":  "Varroabehandlung",
        "date":       "2026-04-04",
        "rows": 16,
        "spot_checks": [
            {"volk_id": 1,  "value_1": "20"},
            {"volk_id": 3,  "value_1": "45"},
            {"volk_id": 6,  "value_1": "25"},
            {"volk_id": 10, "value_1": "26"},
            {"volk_id": 16, "value_1": "69"},
        ],
    },
    "varroa2.jpeg": {
        "classifier": "form",
        "form_type":  "Varroabehandlung",
        "date":       "2026-04-04",
        "rows": 16,
        "spot_checks": [
            {"volk_id": 1,  "value_1": "30"},
            {"volk_id": 3,  "value_1": "28"},
            {"volk_id": 8,  "value_1": "26"},
            {"volk_id": 15, "value_1": "47"},
            {"volk_id": 23, "value_1": "49"},
        ],
    },
    "stockkarte1.jpeg": {
        "classifier": "form",
        "form_type":  "Stockkarte",
        "date":       "2026-04-05",
        "rows": 16,
        "spot_checks": [
            {"volk_id": 7,  "q_not_laying": True,  "s_swarm": True,  "boxes": "1"},
            {"volk_id": 3,  "s_mood": True,                           "boxes": "2", "frames": "3"},
            {"volk_id": 13, "s_mood": True, "s_swarm": True,          "boxes": "1", "frames": "3"},
            {"volk_id": 27, "q_not_laying": True,                     "frames": "3"},
        ],
    },
    "honey.jpeg": {
        "classifier": "form",
        "form_type":  "Honigernte",
        "date":       "2026-04-04",
        "rows": 16,
        "spot_checks": [
            {"volk_id": 1,  "value_1": "25"},
            {"volk_id": 4,  "value_1": "12"},
            {"volk_id": 6,  "value_1": "39"},
            {"volk_id": 15, "value_1": "53"},
            {"volk_id": 16, "value_1": "32"},
        ],
    },
    "Feeding.jpeg": {
        "classifier": "form",
        "form_type":  "Einfütterung",
        "date":       "2026-04-04",
        "rows": 16,
        "spot_checks": [
            {"volk_id": 1,  "value_1": "2"},
            {"volk_id": 13, "value_1": "3"},
            {"volk_id": 14, "value_1": "4"},
            {"volk_id": 15, "value_1": "1"},
            {"volk_id": 16, "value_1": "6"},
        ],
    },
    "breeding.jpeg": {
        "classifier": "form",
        "form_type":  "Zuchtwerte",
        "date":       "2026-04-04",
        "rows": 16,
        "spot_checks": [
            {"volk_id": 1,  "value_1": "5", "value_2": "4", "value_3": "3", "value_4": "2", "value_5": "6"},
            {"volk_id": 2,  "value_1": "4", "value_2": "1", "value_3": "5", "value_4": "7", "value_5": "9"},
            {"volk_id": 10, "value_1": "2", "value_2": "8", "value_3": "6", "value_4": "5", "value_5": "1"},
            {"volk_id": 16, "value_1": "5", "value_2": "7", "value_3": "9", "value_4": "3", "value_5": "1"},
        ],
    },
    "breeding2.jpeg": {
        "classifier": "form",
        "form_type":  "Zuchtwerte",
        "date":       "2026-04-03",
        "rows": 16,
        "spot_checks": [
            {"volk_id": 1,  "value_1": "1", "value_2": "7", "value_3": "3", "value_4": "4", "value_5": "6"},
            {"volk_id": 12, "value_1": "2", "value_2": "8", "value_3": "9", "value_4": "10","value_5": "7"},
            {"volk_id": 23, "value_1": "7", "value_2": "2", "value_3": "10","value_4": "8", "value_5": "4"},
            {"volk_id": 39, "value_1": "2", "value_2": "4", "value_3": "8", "value_4": "9", "value_5": "1"},
        ],
    },
    "IMG_6187.jpg": {
        "classifier": "bottom_board",
        "colony_id":  "16",
    },
}

# ── Farb-Helpers ─────────────────────────────────────────────────────────────
GREEN  = "\033[92m"
RED    = "\033[91m"
YELLOW = "\033[93m"
RESET  = "\033[0m"
BOLD   = "\033[1m"

def ok(msg):  print(f"  {GREEN}OK{RESET}   {msg}")
def err(msg): print(f"  {RED}FAIL {msg}{RESET}")
def warn(msg):print(f"  {YELLOW}WARN {msg}{RESET}")

passed = failed = 0

def check(label, got, want):
    global passed, failed
    got_s  = str(got).strip()
    want_s = str(want).strip()
    if got_s == want_s:
        ok(f"{label}: '{got_s}'")
        passed += 1
    else:
        err(f"{label}: got '{got_s}', want '{want_s}'")
        failed += 1

def check_approx(label, got, want):
    """Toleranz ±1 für Ziffern-Fehllesungen."""
    global passed, failed
    try:
        if abs(int(got) - int(want)) <= 1:
            ok(f"{label}: '{got}' ≈ '{want}'")
            passed += 1
            return
    except (ValueError, TypeError):
        pass
    check(label, got, want)

# ── Tests ─────────────────────────────────────────────────────────────────────
for filename, exp in EXPECTED.items():
    path = os.path.join(SAMPLES, filename)
    print(f"\n{BOLD}{'-'*60}{RESET}")
    print(f"{BOLD}Bild: {filename}{RESET}")

    if not os.path.exists(path):
        warn(f"Datei nicht gefunden, übersprungen.")
        continue

    # ── Classifier ───────────────────────────────────────────────────────────
    try:
        img_type = classify_image(path)
        check("Typ", img_type, exp["classifier"])
    except Exception as e:
        err(f"Classifier-Fehler: {e}")
        continue

    # ── Varroa-Bodenschieber → nur Colony-ID ─────────────────────────────────
    if exp["classifier"] == "bottom_board":
        img_cv = cv2.imread(path)
        if img_cv is None:
            err("Bild konnte nicht mit OpenCV geladen werden.")
            continue
        col_id, bbox = detect_colony_number(img_cv)
        check("Colony-ID", col_id, exp.get("colony_id"))
        if bbox:
            ok(f"Bounding-Box: {bbox}")
        continue

    # ── Formular-Pipeline ────────────────────────────────────────────────────
    try:
        result = get_form_type_and_data(path)
    except Exception as e:
        err(f"HTR-Fehler: {e}")
        import traceback; traceback.print_exc()
        continue

    check("Formular-Typ", result.get("form_type"), exp["form_type"])
    check("Datum",        result.get("date"),       exp["date"])

    records = result.get("records", [])
    row_count = len(records)
    if row_count >= exp["rows"]:
        ok(f"Zeilen: {row_count} (erwartet ≥{exp['rows']})")
        passed += 1
    else:
        err(f"Zeilen: {row_count} (erwartet ≥{exp['rows']})")
        failed += 1

    # Index für schnellen Zugriff
    by_id = {r["volk_id"]: r for r in records}

    for sc in exp.get("spot_checks", []):
        vid = sc["volk_id"]
        row = by_id.get(vid)
        if row is None:
            err(f"Volk {vid}: nicht gefunden (vorhandene IDs: {sorted(by_id.keys())})")
            failed += 1
            continue
        for field, want in sc.items():
            if field == "volk_id":
                continue
            got = row.get(field, "MISSING")
            label = f"Volk {vid} [{field}]"
            if field in ("value_1","value_2","value_3","value_4","value_5"):
                check_approx(label, got, want)
            else:
                check(label, got, want)

    # Alle Zeilen zur Übersicht ausgeben
    print(f"\n  {BOLD}Alle erkannten Zeilen:{RESET}")
    for r in records:
        print(f"    {json.dumps(r, ensure_ascii=False)}")

# ── Zusammenfassung ───────────────────────────────────────────────────────────
total = passed + failed
print(f"\n{BOLD}{'='*60}{RESET}")
print(f"{BOLD}Ergebnis: {GREEN}{passed}{RESET}{BOLD}/{total} Checks bestanden"
      + (f", {RED}{failed} fehlgeschlagen{RESET}" if failed else "") + RESET)
