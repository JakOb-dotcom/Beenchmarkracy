# Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU Affero General Public License as published by the Free
# Software Foundation, either version 3 of the License, or (at your option) any
# later version. See the LICENSE file in the project root for details.

import os
os.environ['FLAGS_enable_pir_api'] = '0'
os.environ['FLAGS_use_mkldnn'] = '0'

import re
import logging
import cv2
import numpy as np

logger = logging.getLogger('ocr')

_ocr_instance = None
_MIN_CONF = 0.5   # Mindestkonfidenz für OCR-Treffer
_MAX_DIGITS = 4   # Volk-IDs haben max. 4 Stellen


def _get_paddle_ocr():
    global _ocr_instance
    if _ocr_instance is None:
        from paddleocr import PaddleOCR
        _ocr_instance = PaddleOCR(use_angle_cls=True, lang='de', drop_score=_MIN_CONF)
    return _ocr_instance


def detect_colony_number(image_cv: np.ndarray) -> tuple[str | None, list | None]:
    """
    Liest die Volk-ID aus der unteren rechten Ecke eines Varroa-Scan-Bilds.

    Gibt (id_string, bounding_box) zurück oder (None, None) bei Misserfolg.
    Wählt den Treffer mit der höchsten OCR-Konfidenz aus.
    """
    try:
        ocr = _get_paddle_ocr()
        h, w = image_cv.shape[:2]

        # Untere rechte 30 % ausschneiden
        crop_h = int(h * 0.3)
        crop_w = int(w * 0.3)
        crop_y = h - crop_h
        crop_x = w - crop_w
        corner = image_cv[crop_y:h, crop_x:w]

        results = ocr.ocr(corner)
        if not results or not results[0]:
            return None, None

        best_number = None
        best_conf = -1.0
        best_bbox = None

        for line in results[0]:
            box = line[0]
            text, conf = line[1]

            digits = re.sub(r'[^0-9]', '', text)
            if not digits or len(digits) > _MAX_DIGITS:
                continue

            if conf > best_conf:
                best_conf = conf
                best_number = digits
                xs = [p[0] + crop_x for p in box]
                ys = [p[1] + crop_y for p in box]
                best_bbox = [int(min(xs)), int(min(ys)), int(max(xs)), int(max(ys))]

        if best_number:
            logger.info(f"Volk-ID erkannt: {best_number} (Konfidenz: {best_conf:.2f})")
            return best_number, best_bbox

        logger.warning("Keine Volk-ID in der Ecke gefunden.")
        return None, None

    except Exception as e:
        logger.error(f"OCR-Fehler in detect_colony_number: {e}", exc_info=True)
        return None, None
