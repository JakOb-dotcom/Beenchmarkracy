# OCR worker (PythonWorkerOCR)

The worker reads photographed paper forms (handwriting) and varroa bottom boards and
writes the results directly into the database. It runs as a separate process that the
PHP backend starts once per upload.

## Setup

```bash
cd PythonWorkerOCR
python -m venv .venv
.venv\Scripts\pip install -r requirements.txt          # Windows
# .venv/bin/pip install -r requirements.txt           # Linux/macOS
```

- PaddleOCR downloads its models (`lang='de'`) automatically into `~/.paddleocr/` on the first run. The server needs internet access once for this.
- **YOLO/varroa:** `ultralytics` and `torch` are large; without them, forms are still processed but bottom-board photos end with an error message in the job. The CPU build is sufficient: `pip install torch --index-url https://download.pytorch.org/whl/cpu`.
- **Import order (Windows):** `torch` must be loaded before `paddle`, otherwise `torch/lib/shm.dll` fails with *WinError 127* (both packages ship their own `libiomp5md.dll`). `worker.py` therefore imports torch/ultralytics first; your own scripts must do the same.
- The varroa model comes from [VarroDetector](https://github.com/jodivaso/VarroDetector) (AGPL-3.0) – origin, license and citation in `PythonWorkerOCR/model/README.md` and `THIRD_PARTY_NOTICES.md`.
- The PHP backend finds `.venv/Scripts/python.exe` or `.venv/bin/python` automatically. For a different interpreter set `OCR_PYTHON_BIN` in `config/config.local.php`.
- The web server user must be allowed to start the worker and needs write access to `logs/` and `uploads/ocr_forms/`.

## Upload flow

1. The frontend (`RecordManager.js → uploadOcrImage`) sends the image to `record_upload_ocr`.
2. `OcrWorkerService::storeUpload` checks size (≤ 20 MB), MIME type (JPEG/PNG via `finfo`) and decodability, and stores the file under `uploads/ocr_forms/` (blocked via `.htaccess`).
3. A row in `ocr_jobs` (status `pending`) is created and the worker is started:
   ```
   python worker.py <image> --user-id <id> --job-id <id> --delete
   ```
   Database access and the log path are passed as environment variables (`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`, `OCR_LOG_FILE`).
4. The worker sets the job to `processing`, classifies the image (`core/classifier.py`), processes it and writes `done`/`failed` with a message, the detected form type and the number of saved rows. The image is deleted afterwards.
5. The frontend polls `ocr_jobs_get` every 5 s (max. 5 min) and reloads the records.

All writes verify that the colony (or the category) belongs to the uploading user;
foreign colony IDs on a form are skipped.

## Forms

The printable forms (tabs "Records" and "Breeding values") are tuned for recognition:

- four L-shaped registration marks in the corners (perspective correction),
- a German title as type anchor: `HONIGERNTE`, `EINFÜTTERUNG`, `VARROABEHANDLUNG`, `STOCKKARTE`, `ZUCHTWERTE`,
- a date field `DAT:` (format `DD.MM.YYYY`),
- category boxes with the short codes from `record_settings.short_code` (an X marks the category),
- a table with a fixed column order (`VOLK ID` = numeric colony ID from the database).

The headings must not be translated – `core/htr.py` detects form type and columns from them.

| Form | Target table | Details |
|------|--------------|---------|
| Honey harvest / feeding / varroa treatment | `hive_records` (`harvest` / `feed` / `varroa`) | quantity in `amount`, note prefixed with `[KI OCR]`, category from the ticked short code |
| Hive card (Stockkarte) | `hive_records` (`status`) | boxes/numbers in `notes`: `[Stockkarte] KL:X SS:X SW:X HR:2 BW:3 [KI OCR]` – identical to the web input |
| Breeding values | `hive_evaluations` | scores 1–10, exactly one evaluation per colony and date (upsert) |
| Bottom-board photo | `hive_records` (`varroa_drop`) | colony ID from the lower right corner, mite count via YOLO |

## Pipeline (core/htr.py)

1. Scale the image to 1500 px width, correct rotation via dominant lines (deskew).
2. OCR (PaddleOCR PP-OCRv3, `lang='de'`) on the deskewed image – best text quality.
3. Header area (top 30 %) → form type, date, global note, category (short code next to the X).
4. Perspective rectification via the registration marks; detect the table grid by morphology; map word coordinates into grid space via homography; assign cells.
5. Evaluate boxes by ink density, re-read empty cells that contain ink (cell rescan), correct digit misreads, sanity-check colony IDs via duplicate/sequence repair.
6. Fallback without grid: column assignment by relative X coordinates.

## Testing the pipeline

Run the worker directly on a photo of one of the printable forms or of a bottom board;
without `--job-id` it only logs to the console and to `logs/ocr_worker.log`:

```powershell
cd PythonWorkerOCR
$env:DB_USER="root"; $env:DB_PASS=""; $env:DB_NAME="forecasting"
.venv\Scripts\python.exe worker.py "path	o\photo.jpg" --user-id 1
```

The colony IDs on the form must belong to the given user, otherwise every row is
rejected (that is the ownership check working, not a recognition error). For
recognition quality, the yardstick from development was 92 of 102 checks on a set of
twelve real photos; the remaining deviations were single-digit confusions (1↔7, 3↔1,
4↔2) of the printed-text model on handwriting. Those photos and the matching regression
script are not part of the repository because they show a real apiary's records; keep
your own set of photos with known values if you change `core/htr.py`.

## Log & troubleshooting

- `logs/ocr_worker.log` (rotating, 2 MB × 3) – every run with job number, result and traceback.
- Status/message are also visible in the `ocr_jobs` table and in the "Records" tab under "Recent uploads".
- `ultralytics/torch nicht verfügbar` in the log: torch could not be loaded. Forms still work. Check: `.venv\Scripts\python -c "import torch, paddle"` must succeed; `"import paddle, torch"` (wrong order) is expected to fail on Windows. If the correct order also fails: `pip install --force-reinstall torch --index-url https://download.pytorch.org/whl/cpu`.
- `Formular-Typ konnte nicht bestimmt werden` (form type could not be determined): title line not readable – take the photo straight, well lit, with all four registration marks in frame.
