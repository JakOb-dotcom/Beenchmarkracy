# REST API

All endpoints: `api/index.php?action=<name>`. Responses are JSON.

- **Authentication:** session cookie (`forecasting_sid`). All endpoints except `login`, `check_auth` and `logout` require a login (otherwise `401`).
- **CSRF:** every non-GET request must send the token from `<meta name="csrf-token">` in the `X-CSRF-TOKEN` header (or as a `csrf_token` field), otherwise `403`. The token is returned by `login` and `check_auth`.
- **Body:** JSON (`Content-Type: application/json`) or form data; uploads as `multipart/form-data`.
- **Errors:** `{ "success": false, "error": "…" }` with a matching HTTP status (400, 401, 403, 404, 405, 429, 500).
- Access to locations, colonies, markers and records is always restricted to the logged-in user.

## Auth

| Action | Method | Parameters | Response |
|--------|--------|------------|----------|
| `login` | POST | `username`, `password` | `success, username, csrf_token, needs_bootstrap_sync` – after 5 failed attempts per IP within 15 min: `429` |
| `check_auth` | GET | – | `logged_in, username, csrf_token, needs_bootstrap_sync` |
| `logout` | POST | – | `success` |
| `bootstrap_sync` | POST | – | `location_ids[]` of the user (all locations), `skipped` if the session flag is already cleared |
| `bootstrap_sync_complete` | POST | – | clears the session flag `needs_bootstrap_sync` |

`needs_bootstrap_sync` is set to `true` on every login. The bundled frontend does not use
these two endpoints (it syncs the selected location via `location_sync` instead), so the
flag simply stays `true` for the session; they exist for external clients that want to
sync all locations once after login.

## Locations

| Action | Method | Parameters | Response |
|--------|--------|------------|----------|
| `locations` | GET | – | list of locations |
| `locations` | POST | `name`, `latitude`, `longitude`, `altitude?` | `id` of the new location (weather data is **not** loaded automatically → `location_refresh`) |
| `location_delete` | POST | `id` | `success` (CASCADE deletes weather data, colonies, records) |
| `location_refresh` | POST | `id`, `mode` = `plan` \| `history_chunk` (+`year`) \| `forecast` \| `normals` (+ optional `part` = 1..N) | `plan`: `years[]`, `normal_parts[[from,to],…]`; otherwise `inserted`. With `part`, each 10-year block of the climate normals is one request (partial sums kept in the session, the last block writes the table) so no single request hits the PHP time limit; every Open-Meteo call is capped at 45 s |
| `location_sync` | POST | `id` | `details {history, forecast, normals, updated, latest_history}` – fetches missing days |
| `location_notes_get` | GET | `location_id` | `notes[]` |
| `location_notes_add` | POST | `location_id`, `note` | `success` |
| `location_note_delete` | POST | `id` | `success` |

## Colonies & breeding values

| Action | Method | Parameters | Response |
|--------|--------|------------|----------|
| `hives_count_all` | GET | – | `count` |
| `hives_get_all` | GET | – | `hives[]` (with `evaluations[]`, `location_name`) |
| `hives_get` | GET | `location_id` | `hives[]` (with `evaluations[]`, `notes[]`) |
| `hive_create` | POST | `location_id`, `name?` | `id` |
| `hive_update` | POST | `hive_id`, `name?`, `genetics?` | `success` |
| `hive_bulk_update_genetics` | POST | `hive_ids[]`, `genetics` | `success` |
| `hive_delete` | POST | `hive_id` | `success` |
| `hive_transfer` | POST | `hive_id`, `target_location_id` | `success` |
| `hive_evaluation_add` | POST | `hive_id`, `date?`, `score_honey?`, `score_gentleness?`, `score_steadiness?`, `score_swarming?`, `score_varroa?` (1–10) | `success` – one evaluation per colony and date (upsert) |
| `hive_evaluation_update` | POST | `eval_id`, scores | `success` |
| `hive_evaluation_delete` | POST | `eval_id` | `success` |
| `hive_note_add` | POST | `hive_id`, `note` | `success` |
| `hive_note_delete` | POST | `id` | `success` |

## Markers & rules

| Action | Method | Parameters | Response |
|--------|--------|------------|----------|
| `markers` | GET | `location_id?` | all markers of the user, or those assigned to a location |
| `markers` | POST | `name`, `type` (`gts`,`temperature_deviation`,`precipitation_deviation`,`complex`,`custom`), `threshold_value?`, `color?`, `severity?` (`info`,`warning`,`critical`), `description?`, `alert_message?`, `rules?` | `id` |
| `marker_update` | POST | `id` + fields as above | `success` |
| `marker_delete` | POST | `id` | `success` |
| `marker_assign` / `marker_remove` | POST | `marker_id`, `location_id` | `success` |
| `marker_predictions` | GET | `location_id`, `year?` | `[{marker, date, days_until, status: upcoming\|active\|reached, message}]` |
| `marker_rule_transitions` | GET | `location_id`, `year?` | start/end events of pure multi-rule GTS markers `[{marker, date, days_until, kind, title, gts}]` |
| `marker_evaluate` | GET | `location_id`, `date` | markers triggered on that day |
| `marker_templates` | GET | – | templates for complex markers |
| `historical_stats` | POST | query `location_id`, body = condition definition | `avg, min, max, years{}` over the previous years |

The rule-tree format (`rules`) is documented at the top of `src/Engine/RuleEngine.php`.

## Weather & GTS

| Action | Method | Parameters | Response |
|--------|--------|------------|----------|
| `gts` | GET | `location_id`, `year?` | daily list `[{date, temp_mean, precipitation, factor, contribution, gts}]` |
| `gts_date` | GET | `location_id`, `date` | GTS entry of the day (or the last one before it) |
| `gts_comparison` | GET | `location_id`, `year?` | average GTS of the previous 11 years per day of year `{doy: gts}` |
| `historie_vergleich` | GET | like `gts_comparison` | *deprecated*, alias |
| `historie_vergleich_series` | GET | `location_id` | forest honey-flow indicators per month (previous + current year) |
| `climate_normals` | GET | `location_id` | `{month: {avg_temp, avg_precip, reference_period}}` |
| `refresh_normals` | POST | `location_id` | reloads the 30-year climate normals |
| `weather` | GET | `location_id`, `start?`, `end?` | historical daily values |
| `weather_forecast` | GET | `location_id` | forecast values from today onwards |

## Records & OCR

| Action | Method | Parameters | Response |
|--------|--------|------------|----------|
| `record_settings_get` | GET | – | `settings[]` (`category`: `harvest`,`feed`,`varroa`) |
| `record_setting_add` | POST | `category`, `name`, `short_code?`, `unit?`, `sugar_g?`, `water_ml?` | `id` |
| `record_setting_delete` | POST | `id` | `success` |
| `records_get` | GET | `hive_id?`, `location_id?`, `type?`, `export?` | `records[]` (max. 500 without `export`) |
| `record_add` | POST | `records[]` each with `hive_id`, `record_date`, `type` (`harvest`,`feed`,`varroa`,`varroa_drop`,`status`), `setting_id?`, `amount?`, `unit?`, `notes?` – or a single record | `ids[]`, `skipped` |
| `record_edit` | POST | `id`, `record_date`, `amount?`, `unit?`, `notes?` | `success` |
| `record_delete` | POST | `id` | `success` |
| `record_upload_ocr` | POST (multipart) | file `ocrUpload` (JPEG/PNG, ≤ 20 MB) | `job_id`, `filename` – starts the Python worker |
| `ocr_jobs_get` | GET | `limit?` | `jobs[]` with `status` (`pending`,`processing`,`done`,`failed`), `message`, `form_type`, `records_saved` |
