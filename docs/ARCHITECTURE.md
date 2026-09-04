# Architecture

## Overview

```
Browser (Alpine.js + Vanilla JS)
   │  fetch  api/index.php?action=…   (JSON, session cookie, CSRF header)
   ▼
PHP API  ──►  Controller  ──►  Services / RuleEngine  ──►  PDO  ──►  MariaDB
   │                                   │
   │  popen (asynchronous)             │  HTTPS
   ▼                                   ▼
PythonWorkerOCR/worker.py        Open-Meteo API
   (PaddleOCR, YOLO)  ──► MySQL
```

Deliberately without a framework, Composer or a build step: the project runs on shared
hosting via plain file upload. There is therefore no autoloader – `api/index.php` and
`src/Bootstrap.php` include everything explicitly.

## Backend (PHP)

| Layer | Location | Responsibility |
|-------|----------|----------------|
| Bootstrap | `src/Bootstrap.php` | load configuration, logging, session (HttpOnly, SameSite=Lax, Secure on HTTPS, idle timeout, `use_strict_mode`), CSRF token, security headers |
| Routing | `api/index.php`, `api/core/Router.php` | action table: handler, login required, CSRF required, allowed HTTP methods; catches `Throwable` and responds with 500 |
| Request/Response | `api/core/Request.php`, `Response.php` | query/JSON body/header access with typed helpers; JSON output with `Cache-Control: no-store` |
| Controllers | `api/controllers/*` | validation (`src/Support/Validation.php`), ownership check (every resource is resolved to the user via `locations.user_id`), delegation to services |
| Services | `src/Services/*` | `LocationService` (CRUD), `MeteoService` (Open-Meteo, chunking, climate normals), `GTSService` (temperature sums, comparisons), `MarkerService` (CRUD + assignment), `OcrWorkerService` (upload, jobs, process start) |
| Engine | `src/Engine/RuleEngine.php` | rule-tree evaluator with five condition types, context builder (window values, deviations from climate normals), prediction/transitions |
| Security | `src/Security/Auth.php`, `LoginThrottle.php` | bcrypt login with rehash, session rotation, per-IP brute-force throttle |
| CLI | `scripts/create_user.php`, `cron/daily_update.php` | user creation, daily weather sync |

### Configuration
`config/config.php` defines constants with defaults; `config/config.local.php`
(gitignored) or environment variables override them. No secrets in the repository.

### Data model
Fully defined in `database/schema.sql`.

```
users ─┬─ locations ─┬─ weather_history / weather_forecast / climate_normals
       │             ├─ location_notes
       │             ├─ marker_locations ── markers (user_id)
       │             └─ hives ─┬─ hive_evaluations (breeding scores 1-10, notes)
       │                       ├─ hive_notes
       │                       └─ hive_records (harvest|feed|varroa|varroa_drop|status) ── record_settings (user_id)
       ├─ ocr_jobs
       └─ login_attempts (per IP)
```

All child tables delete via `ON DELETE CASCADE`; ownership is always checked through
`locations.user_id` (or `user_id` directly), never through client-side IDs alone.

### Reference periods
Two different look-back periods are used side by side:

- **GTS comparison** (dashed "historical average" line, `RuleEngine` historical stats): rolling average of the last `METEO_HISTORY_YEARS` (11) complete years from `weather_history`.
- **Monthly deviations** in the calendar: fixed 30-year climate normals `METEO_NORMAL_START`–`METEO_NORMAL_END` (1995–2024) from `climate_normals`.

When a location is created, 11 years plus the current year of daily data, the 16-day forecast and the climate normals are fetched from Open-Meteo in chunks (`location_refresh`). The reasoning behind the GTS approach, the absence of built-in plant thresholds and the calibration the user has to do is in [METHODOLOGY.md](METHODOLOGY.md).

## Frontend (JS)

- `assets/js/store/globalState.js` – global `window.state` (locations, selected location, GTS data, markers, predictions, climate normals) and the i18n dictionary (German text = key).
- `assets/js/utils/api.js` – `apiFetch` (JSON) and `apiUpload` (multipart); set the CSRF header and reload the page on `401`.
- `assets/js/utils/authAndInit.js` – login/logout, `initApp` (renders from the database first), `syncLocationInBackground` (daily Open-Meteo sync in the background; reloads only when `details.updated` is true – shared by `initApp` and `onLocationChange`). Constants such as history years and the normals period reach the frontend via `window.APP_CONFIG` from `index.php`.
- `assets/js/utils/helpers.js` – `t()`, toasts, loading overlay, dates, `esc()`, iframe printing.
- `assets/js/components/*Manager.js` – one module per tab/domain; classic global functions called from the views via `onclick`. `OverviewManager` and `HistoryComparisonManager` are Alpine components.
- `views/*.php` – plain templates; texts carry `data-i18n` for translation.
- `assets/vendor/alpine.min.js` – Alpine 3.13.3 bundled locally (no CDN, works offline, no third-party scripts).

Rendering uses `innerHTML` with `esc()` for all user-provided values.

## Tests

- `tests/run.php` – runner: temporary database from `schema.sql`, then `unit_tests.php` (GTSService, RuleEngine, MeteoService pure functions, Validation, Auth/LoginThrottle against deterministic synthetic weather) and `api_tests.php` (every endpoint over HTTP via the built-in server: auth, CSRF, ownership, validation, CRUD, cascades). No PHPUnit/Composer; `tests/bootstrap.php` holds the small assertion helper and the fixtures. `config.php` honours `APP_IGNORE_LOCAL_CONFIG=1` so the suite can point the app at the test database through environment variables.
- `tests/browser_smoke.py` – Playwright run through all tabs against a live instance (see file header).
- `PythonWorkerOCR/test_ocr.py` – OCR pipeline on the bundled sample photos (no database).

## OCR worker (Python)

See [OCR_WORKER.md](OCR_WORKER.md). Key points: separate process, user-scoped via
`--user-id`, status feedback through `ocr_jobs`, DB conventions identical to the PHP backend
(`core/db.py` is the only place containing SQL).

## Security concept

| Topic | Implementation |
|-------|----------------|
| Authentication | `password_hash`/`password_verify` (bcrypt), automatic rehash, session ID rotation on login, 24 h idle timeout |
| Brute force | `login_attempts`: 5 failed attempts per IP → 15 min lockout (HTTP 429) |
| CSRF | token per session, rotated on login, required for all non-GET actions |
| Session cookie | `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, `use_strict_mode` |
| Authorization | every resource resolved through the logged-in user (location → colony → record); the worker re-checks colony ownership |
| Input | whitelists for types/categories/operators, date validation, length limits, prepared statements everywhere |
| Uploads | MIME via `finfo` + `getimagesize`, size ≤ 20 MB, random file name, stored behind `.htaccess deny`, deleted after processing |
| Process start | paths via `escapeshellarg`, no user input on the command line, secrets only via environment variables |
| Headers | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, HSTS on HTTPS |
| Errors | no stack traces to the outside (except with `APP_DEBUG`), logging to `logs/app.log` |
| File access | `.htaccess` blocks `config/`, `src/`, `database/`, `cron/`, `scripts/`, `logs/`, `uploads/`, `PythonWorkerOCR/` |

Known limitations: no role/permission model (all users have equal rights but are
isolated from each other), no self-registration, no password reset via e-mail
(→ `scripts/create_user.php`).
