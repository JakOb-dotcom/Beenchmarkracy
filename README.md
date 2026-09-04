# 🐝 Beenchmarkracy

*Less beeurocracy, more honey.* Benchmarks for your bees, and a bureaucracy you will
actually enjoy.

Beenchmarkracy is a web application that **forecasts the beekeeping season** for each of
your apiaries and keeps the paperwork around it in one place.

**Forecasting.** For every location it pulls daily weather from Open-Meteo (11 years of
history, a 16-day forecast and 30-year climate normals) and turns it into the grassland
temperature sum (GTS, *Grünlandtemperatursumme*), the classic phenological clock of
beekeeping. On top of that you define markers: simple GTS thresholds ("cherry bloom at
GTS 200") or complex rules combining daily values, period aggregates, day counts, dry
streaks and temperature sums. The app tells you which markers are reached, which are
coming up in the next days and on which date, compares the current year with the
historical average, and flags risks such as late frost during fruit bloom, swarm weather
or a summer dearth.

**Management.** Colonies per apiary with breeding-value scores and a weighted ranking,
records for honey harvest, feeding, varroa treatment, mite fall and hive cards, CSV
export, printable forms, and handwriting recognition (OCR) that reads photographed
paper forms straight into the database, including automatic varroa mite counting on
bottom-board photos.

Built by and for a small apiary in Styria, Austria; the database and folder are still
called `forecasting` from the project's first life as a pure honey-flow forecast.

| Area          | Technology                                                            |
|---------------|-----------------------------------------------------------------------|
| Backend       | PHP 8.2+, PDO, MariaDB 10.4+ / MySQL 8 (no framework, no Composer)     |
| Frontend      | Vanilla JS + Alpine.js (bundled locally), canvas chart, i18n DE/EN     |
| Weather data  | [Open-Meteo](https://open-meteo.com) archive and forecast API          |
| OCR worker    | Python 3.12, PaddleOCR; optional YOLO (Ultralytics) for varroa counting |

## Project status & disclaimer

This is a hobby project that grew out of one beekeeping operation and its own needs. It is
used in production there, but it has **not** been tested on other setups, browsers or
hosting environments. Expect rough edges:

- Bugs can and will occur. Weather-based markers, GTS values and OCR results are aids for
  your own judgement, not a substitute for it – always double-check before acting on them.
- The app ships **no plant-specific thresholds**. Phenology depends on site, altitude,
  plant variety and the weather pattern of the year; every marker has to be calibrated
  from your own observations, regional phenology networks and local literature. How and
  why is explained in [docs/METHODOLOGY.md](docs/METHODOLOGY.md).
- The OCR and varroa-counting pipeline is tuned to the bundled printable forms and
  smartphone photos of bottom boards; other forms or poor photos will produce wrong
  numbers or nothing at all.
- The calculation cores and the API are covered by automated tests (see below), the
  browser UI by a smoke test. That catches regressions, not every edge case.
- There is no automatic update path, no roles/permissions model and no password reset;
  see the known limitations in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
- The user interface starts in German; a language switch to English is built in.
  Form titles used by the OCR must stay in German (see [docs/OCR_WORKER.md](docs/OCR_WORKER.md)).

The software is provided "as is", without warranty of any kind (see the license).
Bug reports and pull requests are welcome.

## Quick start

```bash
# 1. Create the database (creates the "forecasting" DB with all tables)
mysql -u root -p < database/schema.sql

# 2. Configuration (optional – default: localhost / root / no password)
cp config/config.local.example.php config/config.local.php

# 3. Create the first user
php scripts/create_user.php admin

# 4. Point the web server at the project directory (XAMPP: htdocs/forecasting)
#    or, for testing:  php -S localhost:8080
```

Then log in in the browser and create a location with coordinates. The weather
history (11 years plus the current year), the 16-day forecast and the 30-year
climate normals (1995–2024) are loaded automatically in small steps.

The OCR worker is optional; see [docs/OCR_WORKER.md](docs/OCR_WORKER.md) for setup.

## Tests

```bash
php tests/run.php          # unit + API tests against a temporary database (no network needed)
php tests/run.php unit     # only the calculation cores (GTS, rule engine, Open-Meteo helpers, validation, auth)
php tests/run.php api      # only the HTTP endpoints (built-in PHP server: auth, CSRF, ownership, CRUD)
```

`tests/browser_smoke.py` drives the whole UI in a headless browser (Playwright) against a
running installation, including a real OCR upload; see its docstring for setup. The OCR
pipeline has its own test: `PythonWorkerOCR/test_ocr.py`.

## Documentation

- [docs/METHODOLOGY.md](docs/METHODOLOGY.md) – what the GTS forecast is and is not, phenological variability, how to calibrate your own markers, limitations
- [docs/INSTALLATION.md](docs/INSTALLATION.md) – installation, configuration, cron job, production checklist
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) – backend, frontend and worker structure, data model, security concept
- [docs/API.md](docs/API.md) – all REST endpoints
- [docs/OCR_WORKER.md](docs/OCR_WORKER.md) – Python environment, forms, processing flow, tests, limitations

## Features at a glance

- **Overview & locations** – all apiaries with current GTS and upcoming marker events; create/delete locations, reload weather data.
- **Apiary dashboard** – GTS curve against the historical average, marker predictions, notes, colonies with breeding values, and transfers between apiaries.
- **Breeding values** – weighted ranking of all colonies (honey, gentleness, comb steadiness, swarming tendency, varroa), CSV export, printable OCR form.
- **Calendar & history** – daily view with GTS/temperature/precipitation, monthly statistics against climate normals, start/end events of multi-rule GTS markers, forest honey-flow indicators.
- **Markers** – simple GTS thresholds or complex rules (daily values, period aggregates, day counts, streaks, temperature sums) with a historical preview.
- **Records** – bulk entry per apiary, categories with units/recipes, history with filters and CSV export, printable forms, photo upload with OCR evaluation.

## License

This project is free software under the **GNU Affero General Public License v3.0 (AGPL-3.0)**,
see [LICENSE](LICENSE). Anyone who modifies the application and runs it as a network
service must make the source code of the modified version available to its users.

Everything in this repository was written for Beenchmarkracy, **except** the third-party
components listed below.

---

## Third-party components

| Component | What it does here | Origin | License |
|---|---|---|---|
| **VarroDetector model** (`PythonWorkerOCR/model/weights/best.pt`) | counts varroa mites on bottom-board photos | [jodivaso/VarroDetector](https://github.com/jodivaso/VarroDetector), Jose Divasón et al. | AGPL-3.0 |
| Ultralytics YOLO | runs the model above | [ultralytics/ultralytics](https://github.com/ultralytics/ultralytics) | AGPL-3.0 |
| PaddleOCR / PaddlePaddle | handwriting recognition on the printable forms | [PaddlePaddle/PaddleOCR](https://github.com/PaddlePaddle/PaddleOCR) | Apache-2.0 |
| Alpine.js 3.13.3 (`assets/vendor/`) | reactive UI components | [alpinejs/alpine](https://github.com/alpinejs/alpine) | MIT |
| Open-Meteo | weather history, forecast and climate normals, fetched at runtime | [open-meteo.com](https://open-meteo.com) | CC BY 4.0 (data) |

Full notices, copyright holders and license texts: [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

### VarroDetector (varroa detection model)

*Not written by this project.* The YOLOv11 weights used for mite counting are taken
unchanged from the [VarroDetector](https://github.com/jodivaso/VarroDetector) project by
Jose Divasón et al. (AGPL-3.0) and integrated with the author's consent. They live in
`PythonWorkerOCR/model/weights/` and are additionally maintained in a GitHub fork of the
original project ([JakOb-dotcom/VarroDetector](https://github.com/JakOb-dotcom/VarroDetector));
copyright notices and the license text are preserved (see
[PythonWorkerOCR/model/README.md](PythonWorkerOCR/model/README.md)).

For scientific use, please cite the underlying study:

> Yániz, J., Casalongue, M., Martinez-de-Pison, F. J., Silvestre, M. A., Consortium, B., Santolaria, P., & Divasón, J. (2025).
> *An AI-Based Open-Source Software for Varroa Mite Fall Analysis in Honeybee Colonies.*
> Agriculture, 15(9), 969. https://doi.org/10.3390/agriculture15090969

```bibtex
@article{VarroDetector,
  title     = {An AI-Based Open-Source Software for Varroa Mite Fall Analysis in Honeybee Colonies},
  volume    = {15},
  ISSN      = {2077-0472},
  url       = {http://dx.doi.org/10.3390/agriculture15090969},
  DOI       = {10.3390/agriculture15090969},
  number    = {9},
  journal   = {Agriculture},
  publisher = {MDPI AG},
  author    = {Yániz, Jesús and Casalongue, Matías and Martinez-de-Pison, Francisco Javier and Silvestre, Miguel Angel and Consortium, Beeguards and Santolaria, Pilar and Divasón, Jose},
  year      = {2025}
}
```
