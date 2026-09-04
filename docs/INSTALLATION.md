# Installation & Operation

## Requirements

| Component  | Version   | Notes |
|------------|-----------|-------|
| PHP        | ≥ 8.2     | Extensions: `pdo_mysql`, `fileinfo`, `mbstring`, `json`, `openssl` |
| MariaDB    | ≥ 10.4    | or MySQL ≥ 8.0 (JSON column support required) |
| Web server | Apache (mod_rewrite not required) or the PHP built-in server for testing |
| Python     | 3.10–3.12 | only for the OCR worker, see [OCR_WORKER.md](OCR_WORKER.md) |

Outgoing HTTPS connections to `api.open-meteo.com` and `archive-api.open-meteo.com` must be possible.

## Fresh installation

1. **Copy the files** into the web directory (XAMPP: `htdocs/forecasting`). The web server must be able to write to `uploads/` and `logs/`.
2. **Database**
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   The script creates the `forecasting` database (utf8mb4) with all tables. To use a different name, replace `forecasting` in `schema.sql` and configure `DB_NAME`.
3. **Configuration**
   ```bash
   cp config/config.local.example.php config/config.local.php
   ```
   All keys are optional; they override `config/config.php`. Alternatively the same names can be set as environment variables (`DB_PASS=…`).

   | Key | Default | Meaning |
   |-----|---------|---------|
   | `APP_ENV` | `production` | `development` enables error output and disables the SSL check for Open-Meteo |
   | `APP_DEBUG` | `false` (`true` in development) | show error messages in the browser/JSON |
   | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | `localhost`, `3306`, `forecasting`, `root`, empty | database access |
   | `METEO_VERIFY_SSL` | `true` (production) | set to `false` only locally without a CA bundle |
   | `SESSION_COOKIE_SECURE` | automatic | `true` forces Secure cookies (HTTPS only) |
   | `OCR_ENABLED` | `true` | enable photo upload / OCR worker |
   | `OCR_PYTHON_BIN` | empty = `.venv` detected automatically | path to the worker's Python interpreter |
   | `APP_TIMEZONE` | `Europe/Vienna` | time zone |
4. **Create a user** (there is deliberately no self-registration)
   ```bash
   php scripts/create_user.php admin            # prompts for the password
   php scripts/create_user.php admin 'secret'   # or pass it directly
   ```
   The same command sets a new password for an existing user.
5. **Open** `http://localhost/forecasting/` – log in and create a location.

## Daily cron job (recommended)

```
0 6 * * * /usr/bin/php /path/to/forecasting/cron/daily_update.php >> /path/to/forecasting/logs/cron.log 2>&1
```

The job deletes expired forecasts, adds yesterday to the history, fetches the new
16-day forecast and cleans up old OCR jobs and failed login attempts. Without the cron
job the application fetches missing days itself when a location is opened (at most
30 days per call); this runs in the background, the page renders from the database first.

## Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, `METEO_VERIFY_SSL=true`
- Enable HTTPS; the session cookie then automatically gets the `Secure` flag
- Use a dedicated DB user with privileges only on the `forecasting` database (not `root`)
- The `.htaccess` files in `config/`, `src/`, `database/`, `cron/`, `scripts/`, `logs/`, `uploads/`, `PythonWorkerOCR/` block direct access (Apache with `AllowOverride All`). With nginx, add the corresponding `location` blocks with `deny all`.
- Ideally keep only `index.php`, `api/`, `assets/` and `views/` in the document root and place the rest above it.
- Logs: `logs/app.log` (PHP), `logs/ocr_worker.log` (worker), `logs/cron.log`

## Local testing with XAMPP

1. Start XAMPP Apache and MySQL.
2. Copy the project to `xampp/htdocs/forecasting` (or develop there directly).
3. Create `config/config.local.php` with `APP_ENV => 'development'` and `METEO_VERIFY_SSL => false`.
4. Without Apache, the built-in server also works: `php -S localhost:8080` in the project folder.

## Running the tests

```bash
php tests/run.php
```

Creates a throw-away database `forecasting_test_<random>` from `database/schema.sql` using
the credentials from `config/config.local.php` (override with `TEST_DB_HOST`, `TEST_DB_PORT`,
`TEST_DB_USER`, `TEST_DB_PASS`), runs the unit tests and the HTTP API tests (PHP built-in
server on port 8097, change with `TEST_API_PORT`), and drops the database again. The DB
user needs `CREATE`/`DROP DATABASE`. No internet access is required. On XAMPP run it with
the bundled interpreter, e.g. `..\xampp\php\php.exe tests\run.php`.

The browser smoke test (`tests/browser_smoke.py`) needs Python with `playwright` installed,
Microsoft Edge or Chromium, and a running installation with at least one location that
already has weather data. It creates and removes its own user; see the file header for the
environment variables.

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| "Invalid server response (no JSON)" | PHP error; check `logs/app.log` or set `APP_DEBUG=true` |
| Weather data stays empty | outgoing HTTPS blocked or CA bundle missing (`METEO_VERIFY_SSL=false` only locally) |
| "Too many failed attempts" at login | 5 failed attempts per IP within 15 minutes; wait or `DELETE FROM login_attempts` |
| Upload: "OCR worker could not be started" | Python/venv not found → set `OCR_PYTHON_BIN`, check `logs/app.log` |
| Upload stays at "Pending" | worker starts but crashes → see `logs/ocr_worker.log` |
