# Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU Affero General Public License as published by the Free
# Software Foundation, either version 3 of the License, or (at your option) any
# later version. See the LICENSE file in the project root for details.

import os
os.environ['FLAGS_enable_pir_api'] = '0'
os.environ['FLAGS_use_mkldnn'] = '0'

import cv2
import re
import math
import logging
import numpy as np
from collections import Counter
from paddleocr import PaddleOCR

logger = logging.getLogger('htr')

TARGET_WIDTH     = 1500
Y_TOLERANCE      = 40    # px – gleiche Zeile (Fallback-Pfad)
HEADER_RATIO     = 0.30  # obere 30% = Header-Scan-Bereich
HEADER_MAX_RATIO = 0.45  # bis 45% für Kategorie-Erkennung
INK_THRESHOLD    = 0.02  # Mindest-Tintendichte (Anteil dunkler Pixel im Zellinneren)
CELL_SHRINK      = 0.14  # Rand-Anteil der pro Zelle abgeschnitten wird
RESCAN_MIN_CONF  = 0.30  # Mindestkonfidenz für Zweitpass-OCR

X_MARK_RE = re.compile(r'[xX×✗✘*+vV]')

# Exakte Header-Begriffe die in Datenzeilen als Rauschen gelten.
# Bewusst KEINE generische Großbuchstaben-Regel: handschriftliche Notizen
# (STARK, WENIG, ...) sind ebenfalls in Großbuchstaben.
_HEADER_NOISE = {
    'VOLK', 'VOLK ID', 'VOLKID', 'ID', 'MENGE', 'DOSIS', 'MENGE / DOSIS',
    'MENGE/DOSIS', 'NOTIZ', 'NOTIZ:', 'BEMERKUNG', 'NOTIZ / BEMERKUNG',
    'NOTIZ/BEMERKUNG', 'ERNTEMENGE', 'ZAHL', '(ZAHL)', '(X)', '(H)', '(G)',
    '(S)', '(W)', '(V)',
}

# Formulartyp → Kategorie in record_settings (für die Kürzel-Erkennung im Kopf)
_FORM_CATEGORY = {
    'Honigernte':       'harvest',
    'Einfütterung':     'feed',
    'Varroabehandlung': 'varroa',
}

_COLUMN_TEMPLATES = {
    'Zuchtwerte': ['id', 'val1', 'val2', 'val3', 'val4', 'val5'],
    'Stockkarte': ['id', 'kl', 'ss', 'sw', 'boxes', 'frames'],
    'default':    ['id', 'val1', 'notiz'],
}


def _is_header_noise(text: str) -> bool:
    t = text.upper().strip()
    return t in _HEADER_NOISE or t.startswith('/')


def fix_digits(t: str) -> str:
    """Korrigiert häufige OCR-Fehllesungen bei Ziffern."""
    t = t.upper()
    t = t.replace('O', '0').replace('S', '5').replace('Z', '2')
    t = t.replace('T', '1').replace('I', '1').replace('L', '1').replace('P', '1')
    t = t.replace('B', '8').replace('&', '8')
    t = t.replace('A', '4').replace('+', '4').replace('H', '4').replace('Y', '4')
    t = t.replace('G', '9').replace('Q', '9')
    t = t.replace('V', '5')
    return re.sub(r'[^0-9,.]', '', t)


def _clamp_zuchtwert(v: str) -> str:
    """Zuchtwerte sind 1-10. Werte darüber sind Fehllesungen."""
    if not v:
        return v
    try:
        n = int(float(v.replace(',', '.')))
    except ValueError:
        return ''
    if n > 10:
        # '70' ist fast immer eine 1↔7-Verwechslung von '10'
        return '10' if v.endswith('0') else ''
    return v


def _parse_date(header_words: list) -> str:
    """Datum aus dem Header, bevorzugt hinter 'DAT', mit O→0/Z→2-Korrektur."""
    full = ' '.join(w['text'] for w in header_words).upper().replace('O', '0').replace('Z', '2')
    m = re.search(r'DAT\s*[:\.]?\s*(\d{1,2})[.\/ -]+(\d{1,2})[.\/ -]+(\d{2,4})', full)
    if not m:
        m = re.search(r'(\d{1,2})[.\/ -]+(\d{1,2})[.\/ -]+(\d{2,4})', full)
    if not m:
        return ''
    d, mo, y = m.group(1), m.group(2), m.group(3)
    year = '20' + y if len(y) == 2 else y
    return f'{year}-{mo.zfill(2)}-{d.zfill(2)}'


def _detect_form_type(header_string: str) -> str:
    h = header_string.upper()
    if 'STOCKKARTE' in h:
        return 'Stockkarte'
    if 'ZUCHT' in h:
        return 'Zuchtwerte'
    if 'HONIG' in h:
        return 'Honigernte'
    if 'VARROA' in h:
        return 'Varroabehandlung'
    if 'EINF' in h or 'TTERUNG' in h or 'FUTTER' in h:
        return 'Einfütterung'
    return 'Unbekannt'


def _quad_is_sane(tl, tr, br, bl) -> bool:
    """Prüft ob das Marker-Viereck nahezu rechteckig ist (gegen Fehl-Detektionen)."""
    pts = [np.array(p, dtype=np.float64) for p in (tl, tr, br, bl)]
    # Gegenüberliegende Seiten dürfen max. 15% abweichen
    top    = np.linalg.norm(pts[1] - pts[0])
    bottom = np.linalg.norm(pts[2] - pts[3])
    left   = np.linalg.norm(pts[3] - pts[0])
    right  = np.linalg.norm(pts[2] - pts[1])
    if min(top, bottom) == 0 or min(left, right) == 0:
        return False
    if max(top, bottom) / min(top, bottom) > 1.15:
        return False
    if max(left, right) / min(left, right) > 1.15:
        return False
    # Alle Innenwinkel nahe 90°
    for i in range(4):
        a, b, c = pts[(i - 1) % 4], pts[i], pts[(i + 1) % 4]
        v1, v2 = a - b, c - b
        cos = np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2) + 1e-9)
        angle = math.degrees(math.acos(np.clip(cos, -1, 1)))
        if not (75 <= angle <= 105):
            return False
    return True


def _rectify_perspective(img: np.ndarray):
    """
    Entzerrt das Foto über die vier L-Passermarken in den Ecken.
    Behebt Keystone-Verzerrung, die Gitterlinien für die Morphologie
    unsichtbar macht.
    Gibt (rektifiziertes Bild, Homographie-Matrix) oder None zurück.
    """
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
    h, w = gray.shape
    _, thresh = cv2.threshold(gray, 100, 255, cv2.THRESH_BINARY_INV)
    contours, _ = cv2.findContours(thresh, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)

    # Pro Ecke der beste Kandidat (am nächsten zur Bildecke)
    zones = {'tl': None, 'tr': None, 'bl': None, 'br': None}
    img_corners = {'tl': (0, 0), 'tr': (w, 0), 'bl': (0, h), 'br': (w, h)}

    for cnt in contours:
        area = cv2.contourArea(cnt)
        if not (300 < area < 20000):
            continue
        x, y, cw, ch = cv2.boundingRect(cnt)
        if ch == 0 or not (0.4 <= cw / ch <= 2.5):
            continue
        if area / (cw * ch) >= 0.65:  # L-Form hat geringen Füllgrad
            continue

        cx, cy = x + cw / 2, y + ch / 2
        zone = None
        if cx < w * 0.30 and cy < h * 0.30:
            zone = 'tl'
        elif cx > w * 0.70 and cy < h * 0.30:
            zone = 'tr'
        elif cx < w * 0.30 and cy > h * 0.70:
            zone = 'bl'
        elif cx > w * 0.70 and cy > h * 0.70:
            zone = 'br'
        if zone is None:
            continue

        pts = cnt.reshape(-1, 2).astype(np.float64)
        icx, icy = img_corners[zone]
        # Äußerster Punkt der Marke = der zur Bildecke nächste Konturpunkt
        d = (pts[:, 0] - icx) ** 2 + (pts[:, 1] - icy) ** 2
        outer = tuple(pts[int(np.argmin(d))])
        dist_to_corner = float(np.min(d))
        if zones[zone] is None or dist_to_corner < zones[zone][1]:
            zones[zone] = (outer, dist_to_corner)

    found = {k: v[0] for k, v in zones.items() if v is not None}
    if len(found) < 3:
        return None

    # Fehlende vierte Ecke als Parallelogramm ergänzen
    if len(found) == 3:
        if 'tl' not in found:
            found['tl'] = (found['tr'][0] + found['bl'][0] - found['br'][0],
                           found['tr'][1] + found['bl'][1] - found['br'][1])
        elif 'tr' not in found:
            found['tr'] = (found['tl'][0] + found['br'][0] - found['bl'][0],
                           found['tl'][1] + found['br'][1] - found['bl'][1])
        elif 'bl' not in found:
            found['bl'] = (found['tl'][0] + found['br'][0] - found['tr'][0],
                           found['tl'][1] + found['br'][1] - found['tr'][1])
        else:
            found['br'] = (found['tr'][0] + found['bl'][0] - found['tl'][0],
                           found['tr'][1] + found['bl'][1] - found['tl'][1])

    tl, tr = np.array(found['tl']), np.array(found['tr'])
    bl, br = np.array(found['bl']), np.array(found['br'])

    avg_w = (np.linalg.norm(tr - tl) + np.linalg.norm(br - bl)) / 2
    avg_h = (np.linalg.norm(bl - tl) + np.linalg.norm(br - tr)) / 2
    if avg_w < w * 0.4 or avg_h < h * 0.3:
        return None  # Marken unplausibel weit innen

    if not _quad_is_sane(found['tl'], found['tr'], found['br'], found['bl']):
        logger.debug("Marker-Viereck nicht rechteckig genug – keine Rektifizierung")
        return None

    margin = 40
    dst_w = TARGET_WIDTH
    dst_h = int((dst_w - 2 * margin) * (avg_h / avg_w)) + 2 * margin

    src = np.float32([found['tl'], found['tr'], found['br'], found['bl']])
    dst = np.float32([[margin, margin], [dst_w - margin, margin],
                      [dst_w - margin, dst_h - margin], [margin, dst_h - margin]])
    M = cv2.getPerspectiveTransform(src, dst)
    rectified = cv2.warpPerspective(img, M, (dst_w, dst_h),
                                    flags=cv2.INTER_LINEAR,
                                    borderValue=(255, 255, 255))
    logger.debug(f"Perspektive rektifiziert über {len(found)} Passermarken")
    return rectified, M


def _deskew(img: np.ndarray) -> np.ndarray:
    """Richtet leicht gedrehte Fotos anhand der dominanten horizontalen Linien aus."""
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
    edges = cv2.Canny(gray, 50, 150)
    lines = cv2.HoughLinesP(edges, 1, np.pi / 360, 120,
                            minLineLength=img.shape[1] // 4, maxLineGap=8)
    if lines is None:
        return img
    angles = []
    for line in lines:
        x1, y1, x2, y2 = line[0]
        ang = math.degrees(math.atan2(y2 - y1, x2 - x1))
        if abs(ang) <= 10:
            angles.append(ang)
    if len(angles) < 3:
        return img
    angle = float(np.median(angles))
    if abs(angle) < 0.3:
        return img
    h, w = img.shape[:2]
    M = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
    return cv2.warpAffine(img, M, (w, h), flags=cv2.INTER_LINEAR,
                          borderMode=cv2.BORDER_REPLICATE)


def _cluster_positions(indices: np.ndarray, gap: int = 8) -> list:
    """Fasst benachbarte Pixel-Indizes zu Linien-Positionen zusammen."""
    if len(indices) == 0:
        return []
    clusters = []
    start = prev = int(indices[0])
    for i in indices[1:]:
        i = int(i)
        if i - prev > gap:
            clusters.append((start + prev) // 2)
            start = i
        prev = i
    clusters.append((start + prev) // 2)
    return clusters


def _accept_id(candidate: int, last_id: int, valid_ids: list) -> bool:
    if not valid_ids or candidate in valid_ids:
        return True
    return abs(candidate - (last_id + 1)) <= 5


def _repair_duplicate_ids(records: list) -> list:
    """
    Repariert doppelte Volk-IDs über Nachbar-Kontext.
    Handschriftliche 7 wird oft als 1 gelesen → Duplikat. Wenn der
    Vorgänger+1 frei ist und zum Nachfolger passt, ist das die wahre ID.
    Formulare mit nicht-sequenziellen IDs (keine Duplikate) bleiben unberührt.
    """
    ids = [r.get('volk_id') for r in records]
    n = len(ids)
    if n < 3:
        return records

    for _ in range(3):
        counts = Counter(ids)
        if all(v == 1 for v in counts.values()):
            break
        for i in range(n):
            if counts[ids[i]] <= 1:
                continue
            if i == 0:
                cand = ids[1] - 1
            else:
                cand = ids[i - 1] + 1
                if i + 1 < n:
                    nxt = ids[i + 1]
                    # Nachfolger vertrauenswürdig → Kandidat muss davor passen
                    if counts[nxt] == 1 and cand >= nxt:
                        continue
            if cand >= 1 and counts.get(cand, 0) == 0:
                ids[i] = cand
                counts = Counter(ids)

    for r, vid in zip(records, ids):
        r['volk_id'] = vid
    return records


def _repair_sequential_ids(records: list) -> list:
    """
    Repariert Ausreißer-IDs in sequenziell nummerierten Formularen.
    Wenn die Mehrheit der IDs dem Muster index+offset folgt, werden
    Ausreißer ersetzt – aber nur bei Duplikat oder Monotonie-Bruch,
    damit echte Lücken (übersprungene Zeile) erhalten bleiben.
    """
    ids = [r.get('volk_id') for r in records]
    n = len(ids)
    if n < 5:
        return records

    offsets = [ids[i] - i for i in range(n)]
    mode, cnt = Counter(offsets).most_common(1)[0]
    if cnt / n < 0.6:
        return records  # Formular ist nicht sequenziell nummeriert

    for i in range(n):
        if offsets[i] == mode:
            continue
        is_dup = ids.count(ids[i]) > 1
        prev_ok = i == 0 or ids[i] > ids[i - 1]
        next_ok = i == n - 1 or ids[i] < ids[i + 1]
        if is_dup or not (prev_ok and next_ok):
            expected = i + mode
            if expected >= 1:
                ids[i] = expected

    for r, vid in zip(records, ids):
        r['volk_id'] = vid
    return records


class HTRExtractor:
    def __init__(self):
        self.ocr = PaddleOCR(use_angle_cls=True, lang='de', drop_score=0.3)

    # ── Haupteinstieg ─────────────────────────────────────────────────────

    def extract_form(self, image_path: str, user_id=None) -> dict:
        img = cv2.imread(image_path)
        if img is None:
            raise ValueError(f"Bild konnte nicht geladen werden: {image_path}")

        h, w = img.shape[:2]
        scale = TARGET_WIDTH / float(w)
        target_height = int(h * scale)
        img_resized = cv2.resize(img, (TARGET_WIDTH, target_height))
        img_resized = _deskew(img_resized)
        target_height = img_resized.shape[0]

        # OCR auf dem (nur rotations-korrigierten) Original: beste Textqualität.
        results = self.ocr.ocr(img_resized)
        if not results or not results[0]:
            return {'form_type': 'Unbekannt', 'date': '', 'setting_id': None,
                    'records': [], 'raw_text': ''}

        words = []
        header_words = []
        for line in results[0]:
            box  = line[0]
            text = line[1][0]
            conf = line[1][1]
            cy = sum(p[1] for p in box) / 4
            cx = sum(p[0] for p in box) / 4
            words.append({'text': text, 'x': cx, 'y': cy, 'conf': conf})
            if cy < target_height * HEADER_RATIO:
                header_words.append({'text': text, 'x': cx, 'y': cy})

        header_string = ' '.join(w['text'] for w in header_words)
        form_type = _detect_form_type(header_string)
        form_date = _parse_date(header_words)
        setting_id = self._detect_setting_id(words, target_height, form_type, user_id)
        global_note = self._extract_global_note(words, target_height)
        valid_ids = self._load_valid_hive_ids(user_id)

        # Gitter-Erkennung auf dem perspektiv-entzerrten Bild: beste Geometrie.
        # Wortkoordinaten werden per Homographie in den Gitter-Raum gemappt.
        records = None
        rect = _rectify_perspective(img_resized)
        if rect is not None:
            rectified, M = rect
            gray_rect = cv2.cvtColor(rectified, cv2.COLOR_BGR2GRAY)
            grid = self._detect_table_grid(gray_rect)
            if grid is not None:
                pts = np.float32([[wd['x'], wd['y']] for wd in words]).reshape(-1, 1, 2)
                mapped = cv2.perspectiveTransform(pts, M).reshape(-1, 2)
                grid_words = []
                for wd, (gx, gy) in zip(words, mapped):
                    gw = dict(wd)
                    gw['x'] = float(gx)
                    gw['y'] = float(gy)
                    grid_words.append(gw)
                records = self._extract_via_grid(
                    gray_rect, grid_words, grid, form_type, form_date,
                    global_note, valid_ids)

        # Fallback: X-Koordinaten-Heuristik auf dem Originalbild
        if records is None:
            records = self._extract_via_columns(
                words, target_height, form_type, form_date, global_note, valid_ids)

        records = _repair_duplicate_ids(records)
        records = _repair_sequential_ids(records)

        return {
            'form_type':  form_type,
            'date':       form_date,
            'setting_id': setting_id,
            'records':    records,
            'raw_text':   ' '.join(w['text'] for w in words),
        }

    # ── Gitter-Erkennung ──────────────────────────────────────────────────

    def _detect_table_grid(self, gray: np.ndarray):
        """
        Findet das Tabellengitter über Morphologie.
        Liefert Zeilen-/Spaltenlinien-Positionen und eine Inhalts-Maske
        (Binärbild ohne Gitterlinien) oder None wenn kein Gitter erkennbar.
        """
        h, w = gray.shape
        binary = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C,
                                       cv2.THRESH_BINARY_INV, 31, 12)

        horiz = cv2.morphologyEx(binary, cv2.MORPH_OPEN,
                                 cv2.getStructuringElement(cv2.MORPH_RECT, (w // 12, 1)))
        vert = cv2.morphologyEx(binary, cv2.MORPH_OPEN,
                                cv2.getStructuringElement(cv2.MORPH_RECT, (1, h // 14)))

        grid_mask = cv2.bitwise_or(horiz, vert)
        contours, _ = cv2.findContours(grid_mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if not contours:
            return None

        big = max(contours, key=cv2.contourArea)
        bx, by, bw, bh = cv2.boundingRect(big)
        if bw < w * 0.5 or bh < h * 0.25:
            return None

        sub_h = horiz[by:by + bh, bx:bx + bw]
        sub_v = vert[by:by + bh, bx:bx + bw]
        h_proj = (sub_h > 0).sum(axis=1)
        v_proj = (sub_v > 0).sum(axis=0)

        row_lines = _cluster_positions(np.where(h_proj > bw * 0.40)[0])
        col_lines = _cluster_positions(np.where(v_proj > bh * 0.40)[0])
        if len(row_lines) < 4 or len(col_lines) < 3:
            return None

        row_lines = [by + r for r in row_lines]
        col_lines = [bx + c for c in col_lines]

        line_mask = cv2.dilate(grid_mask, np.ones((5, 5), np.uint8))
        content = cv2.bitwise_and(binary, cv2.bitwise_not(line_mask))

        logger.debug(f"Gitter: {len(row_lines)} Zeilenlinien, {len(col_lines)} Spaltenlinien")
        return {'rows': row_lines, 'cols': col_lines, 'content': content}

    def _cell_ink(self, content: np.ndarray, x0: int, y0: int, x1: int, y1: int) -> float:
        """Anteil dunkler Pixel im Zellinneren (Gitterlinien bereits entfernt)."""
        mx = int((x1 - x0) * CELL_SHRINK)
        my = int((y1 - y0) * CELL_SHRINK)
        cell = content[y0 + my:y1 - my, x0 + mx:x1 - mx]
        if cell.size == 0:
            return 0.0
        return float((cell > 0).mean())

    @staticmethod
    def _first_text_conf(res):
        """Findet das erste (text, conf)-Paar in verschachtelten OCR-Resultaten."""
        if isinstance(res, (list, tuple)):
            if (len(res) == 2 and isinstance(res[0], str)
                    and isinstance(res[1], (int, float))):
                return res
            for el in res:
                r = HTRExtractor._first_text_conf(el)
                if r:
                    return r
        return None

    def _ocr_cell(self, gray: np.ndarray, x0: int, y0: int, x1: int, y1: int) -> str:
        """
        Zweitpass: einzelne Zelle zuschneiden und gezielt erkennen.
        Erst rec-only (ganze Zelle als eine Textzeile), bei Misserfolg
        mit Detektion.
        """
        px = max(2, int((x1 - x0) * 0.06))
        py = max(2, int((y1 - y0) * 0.06))
        crop = gray[y0 + py:y1 - py, x0 + px:x1 - px]
        if crop.size == 0 or crop.shape[0] < 8 or crop.shape[1] < 8:
            return ''
        crop = cv2.resize(crop, None, fx=3.0, fy=3.0, interpolation=cv2.INTER_CUBIC)
        crop_bgr = cv2.cvtColor(crop, cv2.COLOR_GRAY2BGR)

        try:
            res = self.ocr.ocr(crop_bgr, det=False, cls=False)
            pair = self._first_text_conf(res)
            if pair and pair[1] >= RESCAN_MIN_CONF and pair[0].strip():
                return pair[0]
            # Fallback: volle Detektion auf dem Zellen-Ausschnitt
            res = self.ocr.ocr(crop_bgr)
            if res and res[0]:
                texts = [ln[1][0] for ln in res[0] if ln[1][1] >= RESCAN_MIN_CONF]
                return ' '.join(texts)
        except Exception as e:
            logger.debug(f"Zell-Rescan fehlgeschlagen: {e}")
        return ''

    # ── Gitter-basierte Extraktion ────────────────────────────────────────

    def _extract_via_grid(self, gray, words, grid, form_type, form_date,
                          global_note, valid_ids):
        rows, cols = grid['rows'], grid['cols']
        content = grid['content']

        row_bands = [(rows[i], rows[i + 1]) for i in range(len(rows) - 1)
                     if rows[i + 1] - rows[i] > 18]
        col_bands = [(cols[i], cols[i + 1]) for i in range(len(cols) - 1)
                     if cols[i + 1] - cols[i] > 15]
        if len(row_bands) < 3 or len(col_bands) < 2:
            return None

        def band_idx(bands, pos):
            for i, (a, b) in enumerate(bands):
                if a <= pos < b:
                    return i
            return None

        cell_words: dict = {}
        for w in words:
            ri = band_idx(row_bands, w['y'])
            ci = band_idx(col_bands, w['x'])
            if ri is not None and ci is not None:
                cell_words.setdefault((ri, ci), []).append(w)

        # Header-Zeile = Band das 'VOLK' enthält (sonst erstes Band)
        header_ri = 0
        for (ri, _), ws in cell_words.items():
            if any('VOLK' in x['text'].upper() for x in ws):
                header_ri = ri
                break

        # Spaltenrollen: positional wenn Spaltenzahl zur Vorlage passt,
        # sonst über Header-Texte
        template = _COLUMN_TEMPLATES.get(form_type, _COLUMN_TEMPLATES['default'])
        if len(col_bands) == len(template):
            roles = {i: template[i] for i in range(len(col_bands))}
        else:
            roles = self._classify_columns_by_header(cell_words, header_ri,
                                                     len(col_bands), form_type)
            if 'id' not in roles.values():
                logger.debug("Gitter-Spalten nicht zuordenbar – Fallback")
                return None

        records = []
        last_id = 0

        for ri in range(header_ri + 1, len(row_bands)):
            y0, y1 = row_bands[ri]
            vals = {'val1': '', 'val2': '', 'val3': '', 'val4': '', 'val5': ''}
            flags = {'kl': False, 'ss': False, 'sw': False}
            nums = {'boxes': '0', 'frames': '0'}
            notiz_parts = []
            id_digits = ''

            for ci, (x0, x1) in enumerate(col_bands):
                role = roles.get(ci)
                if role is None:
                    continue
                ws = cell_words.get((ri, ci), [])
                text = ' '.join(x['text'] for x in ws).strip()
                ink = self._cell_ink(content, x0, y0, x1, y1)

                if role == 'id':
                    digits = re.sub(r'[^0-9]', '', fix_digits(text))
                    if not digits and ink > INK_THRESHOLD:
                        digits = re.sub(r'[^0-9]', '',
                                        fix_digits(self._ocr_cell(gray, x0, y0, x1, y1)))
                    id_digits = digits[:3]

                elif role in flags:
                    # Tintendichte ist für X-Markierungen zuverlässiger als OCR
                    flags[role] = ink > INK_THRESHOLD or bool(X_MARK_RE.search(text))

                elif role in nums:
                    v = re.sub(r'[^0-9]', '', fix_digits(text))
                    if not v and ink > INK_THRESHOLD:
                        v = re.sub(r'[^0-9]', '',
                                   fix_digits(self._ocr_cell(gray, x0, y0, x1, y1)))
                    nums[role] = v[:2] if v else '0'

                elif role in vals:
                    v = fix_digits(text)
                    if not v and ink > INK_THRESHOLD:
                        v = fix_digits(self._ocr_cell(gray, x0, y0, x1, y1))
                    if form_type == 'Zuchtwerte':
                        v = _clamp_zuchtwert(v)
                    vals[role] = v

                elif role == 'notiz':
                    for x in ws:
                        t = x['text'].strip()
                        if not _is_header_noise(t):
                            notiz_parts.append(t)

            # Volk-ID bestimmen
            hive_id = last_id + 1
            if id_digits:
                cand = int(id_digits)
                if _accept_id(cand, last_id, valid_ids):
                    hive_id = cand

            notiz = ' '.join(notiz_parts).strip()
            if global_note and not notiz:
                notiz = global_note

            # Hat die Zeile verwertbare Daten?
            if form_type == 'Zuchtwerte':
                has_values = any(vals.values())
            elif form_type == 'Stockkarte':
                has_values = bool(id_digits) or any(flags.values()) \
                    or nums['boxes'] != '0' or nums['frames'] != '0'
            else:
                has_values = bool(vals['val1'])

            if not has_values:
                continue

            last_id = hive_id
            if form_type == 'Stockkarte':
                records.append({
                    'volk_id': hive_id,
                    'q_not_laying': flags['kl'],
                    's_mood':       flags['ss'],
                    's_swarm':      flags['sw'],
                    'boxes':        nums['boxes'],
                    'frames':       nums['frames'],
                    'record_date':  form_date,
                    'notiz':        notiz,
                })
            else:
                records.append({
                    'volk_id': hive_id,
                    'value_1': vals['val1'],
                    'value_2': vals['val2'],
                    'value_3': vals['val3'],
                    'value_4': vals['val4'],
                    'value_5': vals['val5'],
                    'notiz':   notiz,
                    'record_date': form_date,
                })

        if not records:
            return None
        return records

    def _classify_columns_by_header(self, cell_words, header_ri, n_cols, form_type):
        roles = {}
        for ci in range(n_cols):
            ws = cell_words.get((header_ri, ci), [])
            t = ' '.join(x['text'] for x in ws).upper()
            if not t:
                continue
            if 'VOLK' in t or t.strip() == 'ID':
                roles[ci] = 'id'
            elif form_type == 'Zuchtwerte':
                if 'HONIG' in t:    roles[ci] = 'val1'
                elif 'SANFT' in t:  roles[ci] = 'val2'
                elif 'WABEN' in t:  roles[ci] = 'val3'
                elif 'SCHWARM' in t: roles[ci] = 'val4'
                elif 'VARROA' in t: roles[ci] = 'val5'
            elif form_type == 'Stockkarte':
                if 'NIGIN' in t or 'LEGT' in t or 'NICHT' in t: roles[ci] = 'kl'
                elif 'STIMMUNG' in t:  roles[ci] = 'ss'
                elif 'SCHWARM' in t:   roles[ci] = 'sw'
                elif 'HONIGR' in t or 'AKT' in t: roles[ci] = 'boxes'
                elif 'BW' in t or 'ENTNOMM' in t: roles[ci] = 'frames'
            else:
                if 'MENGE' in t or 'ERNTE' in t or 'DOSIS' in t: roles[ci] = 'val1'
                elif 'NOTIZ' in t or 'BEMERK' in t: roles[ci] = 'notiz'
        return roles

    # ── Fallback: X-Koordinaten-Heuristik (wenn kein Gitter erkennbar) ────

    def _extract_via_columns(self, words, target_height, form_type, form_date,
                             global_note, valid_ids):
        header_ys = [
            w['y'] for w in words
            if any(kw in w['text'].upper() for kw in ('VOLK', 'MENGE', 'NOTIZ', 'VARROA', 'HONIG'))
            and w['y'] < target_height * HEADER_MAX_RATIO
        ]
        header_y_threshold = max(header_ys) + 45 if header_ys else target_height * HEADER_RATIO

        table_words = [w for w in words if w['y'] > header_y_threshold]
        table_words.sort(key=lambda x: x['y'])

        lines = []
        current = []
        last_y = 0.0
        for w in table_words:
            if not current:
                current = [w]
                last_y = w['y']
            elif abs(w['y'] - last_y) <= Y_TOLERANCE:
                current.append(w)
                last_y = (last_y * (len(current) - 1) + w['y']) / len(current)
            else:
                current.sort(key=lambda x: x['x'])
                lines.append(current)
                current = [w]
                last_y = w['y']
        if current:
            current.sort(key=lambda x: x['x'])
            lines.append(current)

        records = []
        last_id = 0
        for line in lines:
            clean = [w for w in line if not _is_header_noise(w['text'])]
            if not clean:
                continue
            rec = self._parse_line_by_x(clean, form_type, last_id, valid_ids,
                                        form_date, global_note)
            if rec is not None:
                last_id = rec.get('volk_id', last_id)
                records.append(rec)
        return records

    def _parse_line_by_x(self, line, form_type, last_hive_id, valid_ids,
                         form_date, global_note):
        hive_id = last_hive_id + 1
        val1 = val2 = val3 = val4 = val5 = notiz = ''
        q_not_laying = s_mood = s_swarm = False
        boxes = frames = '0'

        for w in line:
            rel_x = w['x'] / TARGET_WIDTH
            text = w['text']

            id_threshold = {'Zuchtwerte': 0.30, 'Stockkarte': 0.22}.get(form_type, 0.28)
            if rel_x < id_threshold:
                digits = re.sub(r'[^0-9]', '', fix_digits(text))
                if digits and _accept_id(int(digits[:3]), last_hive_id, valid_ids):
                    hive_id = int(digits[:3])
                continue

            if form_type == 'Stockkarte':
                if rel_x < 0.34:
                    q_not_laying = bool(X_MARK_RE.search(text))
                elif rel_x < 0.46:
                    s_mood = bool(X_MARK_RE.search(text))
                elif rel_x < 0.57:
                    s_swarm = bool(X_MARK_RE.search(text))
                elif rel_x < 0.73:
                    boxes = fix_digits(text) or '0'
                else:
                    frames = fix_digits(text) or '0'
            elif form_type == 'Zuchtwerte':
                if rel_x < 0.42:
                    val1 = _clamp_zuchtwert(fix_digits(text))
                elif rel_x < 0.53:
                    val2 = _clamp_zuchtwert(fix_digits(text))
                elif rel_x < 0.64:
                    val3 = _clamp_zuchtwert(fix_digits(text))
                elif rel_x < 0.75:
                    val4 = _clamp_zuchtwert(fix_digits(text))
                elif rel_x < 0.90:
                    val5 = _clamp_zuchtwert(fix_digits(text))
            else:
                if rel_x < 0.52:
                    val1 = fix_digits(text)
                elif not re.fullmatch(r'[0-9.,]+', text.strip()):
                    notiz = (notiz + ' ' + text).strip() if notiz else text

        if form_type == 'Zuchtwerte':
            has_values = bool(val1 or val2 or val3 or val4 or val5)
        elif form_type == 'Stockkarte':
            has_values = True
        else:
            has_values = bool(val1)
        if not has_values:
            return None

        if global_note and not notiz:
            notiz = global_note

        if form_type == 'Stockkarte':
            return {
                'volk_id': hive_id,
                'q_not_laying': q_not_laying,
                's_mood': s_mood,
                's_swarm': s_swarm,
                'boxes': boxes,
                'frames': frames,
                'record_date': form_date,
                'notiz': notiz,
            }
        return {
            'volk_id': hive_id,
            'value_1': val1, 'value_2': val2, 'value_3': val3,
            'value_4': val4, 'value_5': val5,
            'notiz': notiz,
            'record_date': form_date,
        }

    # ── Header-Hilfen ─────────────────────────────────────────────────────

    def _extract_global_note(self, words, target_height) -> str:
        """
        Globale Notiz aus dem Formularkopf (z.B. 'NOTIZ: ERSTE BEWERTUNG').
        Der Tabellen-Spaltenkopf 'NOTIZ / BEMERKUNG' wird explizit ignoriert.
        """
        for w in words:
            if 'NOTIZ' not in w['text'].upper():
                continue
            if w['y'] >= target_height * HEADER_RATIO:
                continue
            val = re.sub(r'.*NOTIZ\s*:?', '', w['text'].upper()).strip()
            if val and 'BEMERK' not in val and not val.startswith('/'):
                return val
        return ''

    def _detect_setting_id(self, words, target_height, form_type='Unbekannt', user_id=None):
        settings = self._load_settings(user_id)
        # Nur Kategorien der passenden Formularart berücksichtigen (Kürzel können sich überschneiden)
        category = _FORM_CATEGORY.get(form_type)
        if category:
            settings = [s for s in settings if s.get('category') in (None, category)]
        if not settings:
            return None

        kat_words = [
            w for w in words
            if w['y'] < target_height * HEADER_MAX_RATIO and w['x'] > TARGET_WIDTH * 0.40
        ]
        x_marks = [
            w for w in kat_words
            if re.fullmatch(r'[xX\*\+vV×✓]\.?:?', w['text'].strip())
        ]

        def _matches(sc, text):
            return sc.upper() in text.upper()

        if x_marks:
            best_dist = float('inf')
            best_id = None
            for kw in kat_words:
                if kw in x_marks:
                    continue
                matched = next((s['id'] for s in settings
                                if _matches(s['short_code'], kw['text'])), None)
                if matched is None:
                    continue
                for xw in x_marks:
                    dist = math.hypot(xw['x'] - kw['x'], xw['y'] - kw['y'])
                    if dist < best_dist:
                        best_dist = dist
                        best_id = matched
            if best_id is not None:
                return best_id

        for kw in kat_words:
            for s in settings:
                if _matches(s['short_code'], kw['text']):
                    return s['id']
        return None

    # ── DB-Hilfen ─────────────────────────────────────────────────────────

    def _load_valid_hive_ids(self, user_id=None) -> list:
        if user_id is None:
            return []
        try:
            from core.db import Database
            return Database().valid_hive_ids(user_id)
        except Exception as e:
            logger.warning(f"Konnte Volk-IDs nicht laden: {e}")
            return []

    def _load_settings(self, user_id=None) -> list:
        if user_id is None:
            return []
        try:
            from core.db import Database
            return Database().record_settings(user_id)
        except Exception as e:
            logger.warning(f"Konnte Kategorien nicht laden: {e}")
            return []


_extractor = None


def get_form_type_and_data(image_path: str, user_id=None) -> dict:
    """
    Liest ein fotografiertes Aufzeichnungsformular aus.

    user_id: beschränkt Volk-ID-Plausibilisierung und Kategorie-Erkennung auf die
    Daten dieses Benutzers. Ohne user_id (z.B. test_ocr.py) wird die DB nicht befragt.
    """
    global _extractor
    if _extractor is None:
        _extractor = HTRExtractor()
    return _extractor.extract_form(image_path, user_id=user_id)
