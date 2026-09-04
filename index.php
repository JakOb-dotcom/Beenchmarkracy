<?php
/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

/**
 * Beenchmarkracy – Haupteinstiegspunkt (Single-Page-App)
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

app_send_security_headers();
app_start_session();
header('Content-Type: text/html; charset=utf-8');

// Cache-Busting über Datei-Änderungszeit
function asset(string $path): string {
    $file = __DIR__ . '/' . $path;
    $v = is_file($file) ? filemtime($file) : 1;
    return htmlspecialchars($path, ENT_QUOTES) . '?v=' . $v;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
    <title><?= htmlspecialchars(APP_NAME) ?> | Imkerei Ploder</title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐝</text></svg>">
    <script defer src="<?= asset('assets/vendor/alpine.min.js') ?>"></script>
</head>
<body x-data="{ activeTab: 'uebersicht' }" @set-active-tab.window="activeTab = $event.detail; switchTab($event.detail)">

<?php include __DIR__ . '/views/modals.php'; ?>

<?php include __DIR__ . '/views/login.php'; ?>

<!-- ------------------------------------------- -->
<!-- APP SHELL                                   -->
<!-- ------------------------------------------- -->
<div id="app-shell">

    <?php include __DIR__ . '/views/topbar.php'; ?>

    <?php include __DIR__ . '/views/tab_uebersicht.php'; ?>
    <?php include __DIR__ . '/views/tab_dashboard.php'; ?>
    <?php include __DIR__ . '/views/tab_zuchtwerte.php'; ?>
    <?php include __DIR__ . '/views/tab_kalender.php'; ?>
    <?php include __DIR__ . '/views/tab_marker.php'; ?>
    <?php include __DIR__ . '/views/tab_aufzeichnungen.php'; ?>

</div><!-- /app-shell -->

<script>
    window.APP_CONFIG = {
        historyYears: <?= (int) METEO_HISTORY_YEARS ?>,
        normalStart:  <?= (int) METEO_NORMAL_START ?>,
        normalEnd:    <?= (int) METEO_NORMAL_END ?>
    };
</script>
<script src="<?= asset('assets/js/store/globalState.js') ?>"></script>
<script src="<?= asset('assets/js/utils/api.js') ?>"></script>
<script src="<?= asset('assets/js/utils/helpers.js') ?>"></script>
<script src="<?= asset('assets/js/utils/authAndInit.js') ?>"></script>
<script src="<?= asset('assets/js/components/LocationManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/GTSDataManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/CalendarManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/MarkerManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/RuleBuilder.js') ?>"></script>
<script src="<?= asset('assets/js/components/PredictionManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/DayDetailManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/NotesManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/HiveManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/OverviewManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/HistoryComparisonManager.js') ?>"></script>
<script src="<?= asset('assets/js/components/RecordManager.js') ?>"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
