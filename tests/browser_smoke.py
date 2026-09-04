"""
Browser smoke test for Beenchmarkracy (Playwright + Edge/Chromium).

Runs against a live installation (default http://localhost/forecasting/), creates a
temporary user, clones an existing location's weather data for it, drives every tab
(overview, dashboard, breeding values, calendar across the year boundary, markers incl.
rule builder, records incl. CSV export and a real OCR upload), switches the language,
deletes the location again and removes the user. Fails on any console error or HTTP
error. The OCR upload uses PythonWorkerOCR/Sample images/honey.jpeg; the colony IDs on
that form belong to another user, so the worker is expected to reject all 16 rows.

Requirements: Python 3.10+, `pip install playwright`, Microsoft Edge (or set
SMOKE_BROWSER_CHANNEL=chromium after `playwright install chromium`), a running web
server + MySQL, and one location with weather data in the database.

Environment (all optional):
  SMOKE_BASE_URL          default http://localhost/forecasting/
  SMOKE_PROJECT_ROOT      default: parent directory of this file
  SMOKE_PHP_BIN           default: php            (XAMPP: ../xampp/php/php.exe)
  SMOKE_MYSQL_BIN         default: mysql          (XAMPP: ../xampp/mysql/bin/mysql.exe)
  SMOKE_DB_NAME/USER/PASS default: forecasting / root / (empty)
  SMOKE_SOURCE_LOCATION   id of the location whose weather data is cloned (default 1)
  SMOKE_BROWSER_CHANNEL   default msedge
  SMOKE_SCREENSHOT_DIR    default: system temp dir
"""
import asyncio, subprocess, sys, time, os, tempfile
from playwright.async_api import async_playwright

BASE    = os.environ.get("SMOKE_BASE_URL", "http://localhost/forecasting/")
PROJECT = os.environ.get("SMOKE_PROJECT_ROOT", os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PHP     = os.environ.get("SMOKE_PHP_BIN", "php")
MYSQL   = os.environ.get("SMOKE_MYSQL_BIN", "mysql")
DB      = os.environ.get("SMOKE_DB_NAME", "forecasting")
DB_USER = os.environ.get("SMOKE_DB_USER", "root")
DB_PASS = os.environ.get("SMOKE_DB_PASS", "")
SRC_LOC = int(os.environ.get("SMOKE_SOURCE_LOCATION", "1"))
CHANNEL = os.environ.get("SMOKE_BROWSER_CHANNEL", "msedge")
OUT     = os.environ.get("SMOKE_SCREENSHOT_DIR", tempfile.gettempdir())
SAMPLE  = os.path.join(PROJECT, "PythonWorkerOCR", "Sample images", "honey.jpeg")
USER, PW = "smoketest_user", "Smoke12345!"

results = []
STEP = ["start"]
def check(cond, msg):
    STEP[0] = msg
    results.append((bool(cond), msg))
    print(("  ok   " if cond else "  FAIL ") + msg)

def sql(q):
    args = [MYSQL, "-u", DB_USER] + (["-p" + DB_PASS] if DB_PASS else []) + [DB, "-N", "-e", q]
    r = subprocess.run(args, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()

def setup():
    subprocess.run([PHP, os.path.join(PROJECT, "scripts", "create_user.php"), USER, PW], check=True, capture_output=True, cwd=PROJECT)
    uid = sql(f"SELECT id FROM users WHERE username='{USER}'")
    sql(f"""INSERT INTO locations (name, latitude, longitude, altitude, user_id) SELECT 'SMOKE Apiary', latitude, longitude, altitude, {uid} FROM locations WHERE id={SRC_LOC};
    SET @nid = LAST_INSERT_ID();
    INSERT INTO weather_history (location_id,date,temp_mean,temp_min,temp_max,precipitation,pressure,soil_moisture) SELECT @nid,date,temp_mean,temp_min,temp_max,precipitation,pressure,soil_moisture FROM weather_history WHERE location_id={SRC_LOC};
    INSERT INTO weather_forecast (location_id,date,temp_mean,temp_min,temp_max,precipitation,pressure,soil_moisture,fetched_at) SELECT @nid,date,temp_mean,temp_min,temp_max,precipitation,pressure,soil_moisture,fetched_at FROM weather_forecast WHERE location_id={SRC_LOC};
    INSERT INTO climate_normals (location_id,month,avg_temp,avg_precip,reference_period) SELECT @nid,month,avg_temp,avg_precip,reference_period FROM climate_normals WHERE location_id={SRC_LOC};""")
    return uid

def cleanup(uid):
    sql(f"DELETE FROM locations WHERE user_id={uid}; DELETE FROM markers WHERE user_id={uid}; DELETE FROM record_settings WHERE user_id={uid}; DELETE FROM ocr_jobs WHERE user_id={uid}; DELETE FROM users WHERE id={uid} AND username='{USER}';")

async def main():
    uid = setup()
    print("test user id", uid)
    errors, bad = [], []
    try:
        async with async_playwright() as p:
            browser = await p.chromium.launch(channel=CHANNEL, headless=True)
            ctx = await browser.new_context(viewport={"width": 1400, "height": 1000}, accept_downloads=True)
            page = await ctx.new_page()
            page.on("console", lambda m: errors.append(f"[{STEP[0]}] " + m.text[:400]) if (m.type == "error" or (m.type == "warning" and "Alpine" in m.text)) else None)
            page.on("pageerror", lambda e: errors.append(f"[{STEP[0]}] PAGEERROR " + str(e)[:200] + " @ " + (e.stack or "")[:400].replace(chr(10), " | ")))
            page.on("response", lambda r: bad.append((r.status, r.url[-120:])) if r.status >= 400 and "api/index.php" in r.url else None)
            page.on("dialog", lambda d: asyncio.ensure_future(d.accept("2") if d.type == "prompt" else d.accept()))

            async def tab(name):
                STEP[0] = "tab:" + name
                await page.evaluate(f"switchTab('{name}')")
                await page.wait_for_timeout(600)

            # ── login & overview ──
            await page.goto(BASE)
            await page.evaluate("localStorage.setItem('lang','de')")
            await page.reload()
            await page.fill("#login-user", USER); await page.fill("#login-pass", PW); await page.click("#login-btn")
            await page.wait_for_function("() => window.state && window.state.gtsData && window.state.gtsData.length > 0", timeout=20000)
            await page.wait_for_timeout(1500)
            print("\n## Übersicht")
            ov = await page.evaluate("document.querySelector('#tab-uebersicht .card div[style*=\"min-height\"]').innerText.replace(/\\s+/g,' ')")
            check("SMOKE Apiary" in ov and "Aktuelle GTS" in ov and "-" not in ov.split("Aktuelle GTS")[1][:8], "Übersichtskarte mit GTS: " + ov[:80])
            loc_id = await page.evaluate("window.state.selectedLocationId")

            # ── dashboard ──
            print("\n## Dashboard")
            await tab("dashboard")
            gts_txt = await page.inner_text("#stat-gts-current")
            check(gts_txt not in ("-", "–", ""), f"Aktuelle GTS angezeigt: {gts_txt}")
            check(await page.evaluate("document.getElementById('gts-chart').width > 100"), "GTS-Chart gezeichnet")
            check((await page.inner_text("#predictions-panel")).strip() != "", "Vorhersage-Panel hat Inhalt")
            await page.fill("#location-note-input", "Smoke-Notiz")
            await page.click("text=Anmerkung speichern")
            await page.wait_for_timeout(800)
            check("Smoke-Notiz" in await page.inner_text("#location-notes-list"), "Standort-Anmerkung gespeichert und gelistet")
            await page.evaluate("addHive()")   # prompt → '2'
            await page.wait_for_function("() => document.querySelectorAll('#hives-list > *').length >= 2", timeout=10000)
            n_hives = await page.evaluate("document.querySelectorAll('#hives-list > *').length")
            check(n_hives == 2, f"2 Völker angelegt (Liste: {n_hives})")
            hive_ids = [int(x) for x in sql(f"SELECT id FROM hives WHERE location_id={loc_id} ORDER BY id").split()]
            await page.screenshot(path=os.path.join(OUT, "smoke_dashboard.png"), full_page=True)

            # ── zuchtwerte ──
            print("\n## Zuchtwerte")
            await tab("zuchtwerte")
            await page.wait_for_timeout(800)
            opts = await page.evaluate("[...document.querySelectorAll('#zw-new-hive option')].map(o=>o.value)")
            check(str(hive_ids[0]) in opts, "Volk in Zuchtwert-Auswahl")
            await page.select_option("#zw-new-hive", str(hive_ids[0]))
            for k, v in [("h", "8"), ("g", "7"), ("s", "6"), ("w", "5"), ("v", "9")]:
                await page.fill(f"#zw-new-{k}", v)
            await page.evaluate("submitNewZuchtwerte()")
            await page.wait_for_timeout(1200)
            zw = await page.inner_text("#zuchtwerte-overview-container")
            check("Volk" in zw and ("8" in zw), "Zuchtwert-Übersicht zeigt Bewertung")
            n_eval = sql(f"SELECT COUNT(*) FROM hive_evaluations WHERE hive_id={hive_ids[0]}")
            check(n_eval == "1", f"Bewertung in DB ({n_eval})")

            # ── kalender ──
            print("\n## Kalender")
            await tab("kalender")
            await page.wait_for_timeout(800)
            cells = await page.evaluate("document.querySelectorAll('#calendar-grid .cal-day:not(.empty)').length")
            check(cells >= 28, f"Kalender-Tage gerendert ({cells})")
            check("GTS" in await page.inner_text("#calendar-grid"), "Tageszellen enthalten GTS")
            check((await page.inner_text("#calendar-stats")).strip() != "", "Monatsstatistik vorhanden")
            await page.wait_for_function("() => [...document.querySelectorAll('#tab-kalender [x-data] *')].some(e => e.textContent.includes('Ø'))", timeout=15000)
            year0 = await page.evaluate("window.state.currentYear")
            month0 = await page.evaluate("window.state.currentMonth")
            for _ in range(month0 + 1):     # zurück bis Dezember des Vorjahres
                await page.evaluate("prevMonth()")
            await page.wait_for_function(f"() => window.state.currentYear === {year0 - 1} && window.state.gtsData.length > 300", timeout=15000)
            await page.wait_for_timeout(500)
            label = await page.inner_text("#calendar-month-label")
            check(str(year0 - 1) in label and "Dez" in label, f"Navigation ins Vorjahr: {label}")
            dec_cells = await page.evaluate("[...document.querySelectorAll('#calendar-grid .cal-day:not(.empty)')].filter(c=>c.textContent.includes('GTS')).length")
            check(dec_cells == 31, f"Dezember Vorjahr vollständig mit Daten ({dec_cells}/31)")
            await page.evaluate("nextMonth()")
            await page.wait_for_function(f"() => window.state.currentYear === {year0} && window.state.currentMonth === 0", timeout=15000)
            await page.wait_for_timeout(500)
            jan_label = await page.inner_text("#calendar-month-label")
            check("Jan" in jan_label and str(year0) in jan_label, f"Zurück ins laufende Jahr: {jan_label}")
            await page.evaluate(f"window.state.currentMonth = {month0}; renderCalendar()")
            await page.wait_for_timeout(300)
            await page.evaluate("showDayDetail(window.state.currentDate)")
            await page.wait_for_timeout(800)
            check(await page.evaluate("getComputedStyle(document.getElementById('day-detail-modal')).display !== 'none'"), "Tagesdetail-Modal geöffnet")
            check((await page.inner_text("#day-detail-content")).strip() != "", "Tagesdetail hat Inhalt")
            await page.evaluate("closeDayDetail()")
            await page.screenshot(path=os.path.join(OUT, "smoke_kalender.png"), full_page=True)

            # ── marker ──
            print("\n## Marker")
            await tab("marker")
            form = page.locator("#tab-marker form")
            await form.locator("input[name=name]").fill("Smoke GTS 300")
            await form.locator("input[name=threshold_value]").fill("300")
            await form.locator("button[type=submit]").click()
            await page.wait_for_timeout(1000)
            check("Smoke GTS 300" in await page.inner_text("#marker-tbody"), "Einfacher Marker in Tabelle")
            await page.fill("#rb-name", "Smoke Komplex")
            await page.evaluate("addCondition('daily')")
            await page.wait_for_timeout(300)
            await page.evaluate("updateCond(0,'field','temp_max'); updateCond(0,'operator','>='); updateCond(0,'value',20)")
            await page.evaluate("addCondition('day_count')")
            await page.wait_for_timeout(300)
            await page.evaluate("saveComplexMarker()")
            await page.wait_for_timeout(1500)
            tb = await page.inner_text("#marker-tbody")
            check("Smoke Komplex" in tb, "Komplexer Marker gespeichert")
            n_markers = sql(f"SELECT COUNT(*) FROM markers WHERE user_id={uid}")
            check(n_markers == "2", f"2 Marker in DB ({n_markers})")
            await page.evaluate("loadDefaultMarkers()")
            await page.wait_for_timeout(1500)
            n_markers2 = int(sql(f"SELECT COUNT(*) FROM markers WHERE user_id={uid}"))
            check(n_markers2 > 2, f"Standard-Marker geladen ({n_markers2})")
            await tab("dashboard")
            await page.wait_for_timeout(1200)
            rm = await page.inner_text("#reached-markers")
            check("300" in rm or "Smoke" in rm, "Dashboard zeigt Marker-Chips")
            await page.screenshot(path=os.path.join(OUT, "smoke_marker.png"), full_page=True)

            # ── aufzeichnungen ──
            print("\n## Aufzeichnungen")
            await tab("aufzeichnungen")
            await page.wait_for_timeout(1200)
            await page.select_option("#record-category-select", "harvest")
            await page.evaluate("window.updateRecordUnitOptions()")
            await page.fill("#record-setting-name", "Blütenhonig")
            await page.fill("#record-setting-short", "BL")
            await page.evaluate("window.addRecordSetting()")
            await page.wait_for_timeout(1000)
            check("Blütenhonig" in await page.inner_text("#record-settings-list"), "Kategorie angelegt")
            await page.select_option("#new-record-location", str(loc_id))
            await page.evaluate("window.renderBulkGrid()")
            await page.fill("#new-record-date", "2026-07-15")
            await page.select_option("#new-record-type", "harvest")
            await page.evaluate("window.updateRecordFormUI()")
            await page.wait_for_timeout(300)
            setting_vals = await page.evaluate("[...document.querySelectorAll('#new-record-setting option')].map(o=>o.value).filter(v=>v)")
            if setting_vals:
                await page.select_option("#new-record-setting", setting_vals[0])
                await page.evaluate("window.renderBulkGrid()")
            await page.wait_for_timeout(300)
            rows = await page.evaluate("document.querySelectorAll('#bulk-hives-body tr.bulk-row').length")
            check(rows == 2, f"Bulk-Tabelle mit 2 Völkern ({rows})")
            await page.fill("#new-record-global-amount", "12.5")
            await page.evaluate("window.applyGlobalAmount()")
            await page.evaluate("window.addRecordsBulk()")
            await page.wait_for_timeout(1500)
            n_rec = sql(f"SELECT COUNT(*) FROM hive_records WHERE hive_id IN ({hive_ids[0]},{hive_ids[1]})")
            check(n_rec == "2", f"2 Aufzeichnungen gespeichert ({n_rec})")
            await page.evaluate("window.loadRecords()")
            await page.wait_for_timeout(800)
            check("12.5" in await page.inner_text("#records-list-body"), "Aufzeichnungen in Liste")
            rec_id = sql(f"SELECT MIN(id) FROM hive_records WHERE hive_id={hive_ids[0]}")
            await page.evaluate(f"window.editRecordModal({rec_id}, '2026-07-15', 12.5, 'kg', '')")
            await page.fill("#edit-record-amount", "13")
            await page.fill("#edit-record-notes", "bearbeitet")
            await page.evaluate("window.saveRecordEdit()")
            await page.wait_for_timeout(1000)
            check(sql(f"SELECT amount FROM hive_records WHERE id={rec_id}").startswith("13"), "Aufzeichnung bearbeitet")
            async with page.expect_download(timeout=10000) as dl:
                await page.evaluate("window.exportRecordsCSV()")
            d = await dl.value
            check(d.suggested_filename.endswith(".csv"), f"CSV-Export: {d.suggested_filename}")
            # OCR upload
            await page.set_input_files("#ocr-upload-input", SAMPLE)
            await page.evaluate("window.uploadOcrImage()")
            await page.wait_for_timeout(1500)
            job = sql(f"SELECT id, status FROM ocr_jobs WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
            check(job != "", f"OCR-Job angelegt: {job}")
            t0 = time.time(); status = ""
            while time.time() - t0 < 300:
                status = sql(f"SELECT status FROM ocr_jobs WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
                if status in ("done", "failed"): break
                await page.wait_for_timeout(5000)
            msg = sql(f"SELECT CONCAT(status,' | ',IFNULL(form_type,''),' | ',IFNULL(message,'')) FROM ocr_jobs WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
            # Die Volk-IDs 1–16 auf dem Musterformular gehören dem Admin, nicht dem Testbenutzer:
            # der Worker muss das Formular erkennen und alle 16 Zeilen als fremd ablehnen.
            check(status in ("done", "failed") and "Honigernte" in msg and "16" in msg, f"OCR-Pipeline durchlaufen nach {time.time()-t0:.0f}s: {msg}")
            await page.evaluate("window.loadOcrJobs()")
            await page.wait_for_timeout(800)
            jl = await page.inner_text("#ocr-jobs-list")
            check("Honigernte" in jl, "OCR-Job mit erkanntem Formulartyp in Liste sichtbar")
            await page.screenshot(path=os.path.join(OUT, "smoke_records.png"), full_page=True)

            # ── Sprache ──
            print("\n## Sprache")
            await page.evaluate("changeLanguage('en')")
            await page.wait_for_timeout(500)
            check("Records" in await page.inner_text("button[data-tab=aufzeichnungen]"), "Tab-Beschriftung auf Englisch")
            await tab("uebersicht")
            await page.wait_for_timeout(800)
            check("Open location" in await page.inner_text("#tab-uebersicht"), "Übersicht (Alpine-Template) auf Englisch")
            await page.evaluate("changeLanguage('de')")

            # ── Standort löschen ──
            print("\n## Löschen")
            await tab("dashboard")
            await page.evaluate("deleteCurrentLocationFromDashboard()")
            await page.wait_for_timeout(2000)
            check(sql(f"SELECT COUNT(*) FROM locations WHERE user_id={uid}") == "0", "Standort gelöscht")
            check(sql(f"SELECT COUNT(*) FROM hives WHERE id IN ({hive_ids[0]},{hive_ids[1]})") == "0", "Völker kaskadiert gelöscht")
            await tab("uebersicht")
            await page.wait_for_timeout(800)
            check("Noch keine Standorte" in await page.inner_text("#tab-uebersicht"), "Übersicht leer nach Löschen")
            await browser.close()
    finally:
        cleanup(uid)
        print("\ncleanup done")

    print("\n## Konsole / HTTP")
    check(not errors, "keine Konsolenfehler" if not errors else "Konsolenfehler: " + " || ".join(errors[:6]))
    check(not bad, "keine HTTP-Fehler ≥ 400" if not bad else "HTTP-Fehler: " + str(bad[:6]))
    fails = [m for ok, m in results if not ok]
    print(f"\nRESULT: {len(results)-len(fails)} ok, {len(fails)} failed")
    for m in fails: print("  -", m)
    sys.exit(1 if fails else 0)

asyncio.run(main())
