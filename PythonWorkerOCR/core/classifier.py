# Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU Affero General Public License as published by the Free
# Software Foundation, either version 3 of the License, or (at your option) any
# later version. See the LICENSE file in the project root for details.

import cv2
import numpy as np
import logging

logger = logging.getLogger('classifier')

_TARGET_WIDTH          = 1500
_CORNER_MARKER_MIN_AREA = 500
_CORNER_MARKER_MAX_AREA = 15000
_MIN_CORNER_MARKERS     = 3
# Tabellenformular braucht viele Linien in BEIDE Richtungen, ausgeglichen
_MIN_H_LINES = 8
_MIN_V_LINES = 3
_MAX_V_TO_H_RATIO = 6.0  # Bodenschieber hat viel mehr V als H (Kunststoffkanten)


def classify_image(image_path: str) -> str:
    """
    Klassifiziert ein Bild als 'form' (Aufzeichnungsformular) oder
    'bottom_board' (Varroa-Bodenschieber).

    Strategie:
    1. Suche nach L-förmigen Passermarken in den Ecken (sicherstes Merkmal).
    2. Fallback: Echtes Linien-Gitter (viele H + V Linien) → Form.
    3. Standard: bottom_board.
    """
    img = cv2.imread(image_path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        raise ValueError(f"Bild konnte nicht geladen werden: {image_path}")

    h, w = img.shape[:2]
    ratio = _TARGET_WIDTH / w if w > _TARGET_WIDTH else 1.0
    img_resized = cv2.resize(img, (int(w * ratio), int(h * ratio)))

    if _has_corner_markers(img_resized):
        logger.debug("Passermarken gefunden -> form")
        return 'form'

    if _has_grid_lines(img_resized):
        logger.debug("Tabellenlinien-Gitter gefunden -> form")
        return 'form'

    logger.debug("Kein Formular erkannt -> bottom_board")
    return 'bottom_board'


_CORNER_ZONE = 0.20  # Marker müssen in den äußeren 20% des Bildes liegen


def _has_corner_markers(img: np.ndarray) -> bool:
    """
    Erkennt L-förmige Fiducial-Passermarken in den vier Ecken.
    Kandidaten die nicht nahe einer Ecke sind werden ignoriert.
    """
    h, w = img.shape[:2]
    _, thresh = cv2.threshold(img, 100, 255, cv2.THRESH_BINARY_INV)
    contours, _ = cv2.findContours(thresh, cv2.RETR_TREE, cv2.CHAIN_APPROX_SIMPLE)

    # Jede Ecke bekommt ihr eigenes Flag
    corners_hit = [False, False, False, False]  # TL, TR, BL, BR

    for cnt in contours:
        area = cv2.contourArea(cnt)
        if not (_CORNER_MARKER_MIN_AREA < area < _CORNER_MARKER_MAX_AREA):
            continue

        x, y, cw, ch = cv2.boundingRect(cnt)
        aspect = cw / ch if ch > 0 else 0
        if not (0.5 <= aspect <= 2.0):
            continue

        extent = area / (cw * ch)
        if extent >= 0.6:
            continue

        hull = cv2.convexHull(cnt)
        hull_area = cv2.contourArea(hull)
        solidity = area / hull_area if hull_area > 0 else 0
        if solidity >= 0.7:
            continue

        # Prüfe ob der Kandidat in einer Ecken-Zone liegt
        cx, cy = x + cw / 2, y + ch / 2
        in_left  = cx < w * _CORNER_ZONE
        in_right = cx > w * (1 - _CORNER_ZONE)
        in_top   = cy < h * _CORNER_ZONE
        in_bot   = cy > h * (1 - _CORNER_ZONE)

        if in_top and in_left:
            corners_hit[0] = True
        elif in_top and in_right:
            corners_hit[1] = True
        elif in_bot and in_left:
            corners_hit[2] = True
        elif in_bot and in_right:
            corners_hit[3] = True

    found = sum(corners_hit)
    return found >= _MIN_CORNER_MARKERS


def _has_grid_lines(img: np.ndarray) -> bool:
    """
    Prüft ob das Bild ein echtes Tabellen-Gitter enthält.
    Ein Bodenschieber-Foto hat allenfalls einen Außenrahmen,
    aber kein internes Gitter aus vielen H- UND V-Linien.
    """
    edges = cv2.Canny(img, 50, 150, apertureSize=3)
    lines = cv2.HoughLinesP(edges, 1, np.pi / 180, 80, minLineLength=100, maxLineGap=15)

    if lines is None:
        return False

    h_lines = 0
    v_lines = 0
    for line in lines:
        x1, y1, x2, y2 = line[0]
        angle = abs(np.degrees(np.arctan2(y2 - y1, x2 - x1)))
        if angle < 15 or angle > 165:
            h_lines += 1
        elif 75 < angle < 105:
            v_lines += 1

    # Ein echtes Formular hat viele H- UND V-Linien in ausgeglichenem Verhältnis.
    # Ein Bodenschieber hat extrem viele V-Linien (Materialstreifen) aber wenige H.
    ratio_ok = v_lines == 0 or (v_lines / max(h_lines, 1)) <= _MAX_V_TO_H_RATIO
    is_grid = h_lines >= _MIN_H_LINES and v_lines >= _MIN_V_LINES and ratio_ok
    logger.debug(f"Linien-Check: H={h_lines}, V={v_lines}, ratio={v_lines/max(h_lines,1):.1f}, Gitter={is_grid}")
    return is_grid
