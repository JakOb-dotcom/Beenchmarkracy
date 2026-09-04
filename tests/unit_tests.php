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
 * Unit tests for the calculation cores (GTSService, RuleEngine, MeteoService pure
 * functions, Validation, Auth/LoginThrottle) against the temporary test database.
 * Run via tests/run.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/config/database.php';
require_once TEST_ROOT . '/src/Support/Validation.php';
require_once TEST_ROOT . '/src/Security/Auth.php';
require_once TEST_ROOT . '/src/Security/LoginThrottle.php';
require_once TEST_ROOT . '/src/Services/LocationService.php';
require_once TEST_ROOT . '/src/Services/MeteoService.php';
require_once TEST_ROOT . '/src/Services/GTSService.php';
require_once TEST_ROOT . '/src/Services/MarkerService.php';
require_once TEST_ROOT . '/src/Engine/RuleEngine.php';

if (DB_NAME !== TEST_DB_NAME) {
    exit("Refusing to run: application is not pointed at the test database (" . DB_NAME . ")\n");
}

$db   = getDB();
$ids  = test_seed_fixtures($db);
$L1   = $ids['l1'];
$L3   = $ids['l3'];
$year = (int) date('Y');
$today = date('Y-m-d');

// ═══════════════════════════════════════════════════════════
T::section('Validation');
T::eq(true,  Validation::isDate('2024-02-29'), 'leap day 2024 valid');
T::eq(false, Validation::isDate('2023-02-29'), 'Feb 29 2023 invalid');
T::eq(false, Validation::isDate('2026-13-01'), 'month 13 invalid');
T::eq(false, Validation::isDate('2026-1-1'),   'unpadded date rejected');
T::eq(false, Validation::isDate(20260101),     'integer rejected');
T::eq('2026-05-05', Validation::dateOr('2026-05-05'), 'dateOr passes valid date');
T::eq($today, Validation::dateOr('nonsense'), 'dateOr falls back to today');
T::eq('abc', Validation::text('  abc  ', 10), 'text trims');
T::eq('abcde', Validation::text('abcdefgh', 5), 'text truncates to max');
T::eq(null, Validation::text('   ', 5), 'blank → null');
T::eq('', Validation::text(null, 5, false), 'non-nullable blank → empty string');
T::eq('#aabbcc', Validation::color('#AABBCC'), 'color lower-cased');
T::eq('#4caf50', Validation::color('red'), 'invalid color → default');
T::eq('feed', Validation::oneOf('feed', ['harvest', 'feed'], 'harvest'), 'oneOf accepts');
T::eq('harvest', Validation::oneOf('x', ['harvest', 'feed'], 'harvest'), 'oneOf default');
T::eq(1.5, Validation::decimalOrNull('1.5'), 'decimal parses');
T::eq(null, Validation::decimalOrNull(''), 'empty decimal → null');
T::eq(null, Validation::decimalOrNull('1,5'), 'comma decimal → null (not numeric)');

// ═══════════════════════════════════════════════════════════
T::section('Auth & LoginThrottle');
T::ok(Auth::attempt('tester', 'Test12345!') !== false, 'correct password accepted');
T::eq(false, Auth::attempt('tester', 'wrong'), 'wrong password rejected');
T::eq(false, Auth::attempt('nobody', 'x'), 'unknown user rejected');
LoginThrottle::clear('10.0.0.1');
T::eq(0, LoginThrottle::lockedForSeconds('10.0.0.1'), 'fresh IP not locked');
for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
    LoginThrottle::recordFailure('10.0.0.1', 'tester');
}
T::ok(LoginThrottle::lockedForSeconds('10.0.0.1') > 0, 'locked after ' . LOGIN_MAX_ATTEMPTS . ' failures');
T::eq(0, LoginThrottle::lockedForSeconds('10.0.0.2'), 'other IP unaffected');
LoginThrottle::clear('10.0.0.1');
T::eq(0, LoginThrottle::lockedForSeconds('10.0.0.1'), 'clear() unlocks');

// ═══════════════════════════════════════════════════════════
T::section('MeteoService pure functions');
T::eq([['2026-01-01', '2026-09-03']], MeteoService::yearChunks('2026-01-01', '2026-09-03'), 'partial year = one chunk (regression: infinite loop)');
T::eq([['2024-06-15', '2024-12-31'], ['2025-01-01', '2025-12-31'], ['2026-01-01', '2026-02-01']],
    MeteoService::yearChunks('2024-06-15', '2026-02-01'), 'multi-year split at Jan 1');
T::eq([], MeteoService::yearChunks('2026-02-01', '2026-01-01'), 'start > end → no chunks');
T::eq([['2026-12-31', '2026-12-31']], MeteoService::yearChunks('2026-12-31', '2026-12-31'), 'single day on Dec 31');
$parts = MeteoService::climateNormalParts();
T::eq(3, count($parts), 'normals in 3 parts');
T::eq([1995, 2004], $parts[0], 'first part 1995–2004');
T::eq([2015, 2024], $parts[2], 'last part 2015–2024');

$acc = MeteoService::newNormalsAccumulator();
T::eq(false, $acc['failed'], 'accumulator starts clean');
// two synthetic years: January 2020 = 0 °C / 10 mm total, January 2021 = 4 °C / 30 mm total
$mk = function (int $y, float $t, float $pPerDay): array {
    $d = ['time' => [], 'temperature_2m_mean' => [], 'precipitation_sum' => []];
    for ($i = 1; $i <= 10; $i++) {
        $d['time'][] = sprintf('%d-01-%02d', $y, $i);
        $d['temperature_2m_mean'][] = $t;
        $d['precipitation_sum'][] = $pPerDay;
    }
    return $d;
};
MeteoService::addDailyToNormalsAccumulator($mk(2020, 0.0, 1.0), $acc);
MeteoService::addDailyToNormalsAccumulator($mk(2021, 4.0, 3.0), $acc);
$stored = MeteoService::storeClimateNormals($L3, $acc);
T::eq(12, $stored, 'storeClimateNormals writes 12 months');
$n = MeteoService::getClimateNormals($L3);
T::approx(2.0, $n[1]['avg_temp'], 0.01, 'January temp = mean of all daily values (0,4 → 2)');
T::approx(20.0, $n[1]['avg_precip'], 0.01, 'January precip = mean of monthly totals (10,30 → 20)');
T::eq(null, $n[2]['avg_temp'], 'months without data → null');
T::eq('1995-2024', $n[1]['reference_period'], 'reference period label');
$db->prepare('DELETE FROM climate_normals WHERE location_id = ?')->execute([$L3]);

$db->prepare('INSERT INTO weather_forecast (location_id, date, temp_mean) VALUES (?, ?, 5)')->execute([$L3, '2000-01-01']);
T::ok(MeteoService::purgeOldForecasts() >= 1, 'purgeOldForecasts removes past rows');
$cnt = $db->query("SELECT COUNT(*) FROM weather_forecast WHERE date < CURDATE()")->fetchColumn();
T::eq('0', (string) $cnt, 'no past forecast rows remain');

// ═══════════════════════════════════════════════════════════
T::section('GTSService::calculate');
$gts = GTSService::calculate($L1, $year);
$expectedDays = fx_doy($today) - 1 + 16; // history up to yesterday + 16 forecast days
T::eq($expectedDays, count($gts), 'history + forecast rows, no duplicates');
T::eq("$year-01-01", $gts[0]['date'], 'starts Jan 1');
T::eq(date('Y-m-d', strtotime("$today +15 days")), end($gts)['date'], 'ends today+15 (forecast)');
$dates = array_column($gts, 'date');
T::eq(count($dates), count(array_unique($dates)), 'dates unique');
T::eq(0.5, $gts[0]['factor'], 'January factor 0.5');
$feb = array_values(array_filter($gts, fn($e) => substr($e['date'], 5, 2) === '02'));
$mar = array_values(array_filter($gts, fn($e) => substr($e['date'], 5, 2) === '03'));
T::eq(0.75, $feb[0]['factor'], 'February factor 0.75');
T::eq(1.0, $mar[0]['factor'], 'March factor 1.0');
$neg = array_filter($gts, fn($e) => $e['temp_mean'] < 0);
T::ok(count($neg) > 0, 'fixture contains negative days');
T::ok(count(array_filter($neg, fn($e) => $e['contribution'] != 0)) === 0, 'negative temperatures contribute 0');
$mono = true;
for ($i = 1; $i < count($gts); $i++) {
    if ($gts[$i]['gts'] < $gts[$i - 1]['gts']) { $mono = false; break; }
}
T::ok($mono, 'GTS is monotonically non-decreasing');
// manual recomputation
$sum = 0.0;
foreach ($gts as $e) {
    $m = (int) substr($e['date'], 5, 2);
    $f = $m === 1 ? 0.5 : ($m === 2 ? 0.75 : 1.0);
    if ($e['temp_mean'] > 0) $sum += $e['temp_mean'] * $f;
}
T::approx($sum, end($gts)['gts'], 0.05, 'cumulative GTS matches manual sum');
T::eq([], GTSService::calculate($L1, 1990), 'year without data → empty');
T::ok(GTSService::getForDate($L1, $today) !== null, 'getForDate today (forecast row) found');
T::eq(null, GTSService::getForDate($L1, '1990-05-05'), 'getForDate without data → null');
$range = GTSService::getForRange($L1, "$year-03-01", "$year-03-10");
T::eq(10, count($range), 'getForRange returns 10 days');

// ═══════════════════════════════════════════════════════════
T::section('GTSService::getHistoricalComparison');
$cmp = GTSService::getHistoricalComparison($L1, $year);
T::ok(isset($cmp[1]) && isset($cmp[365]), 'day-of-year 1..365 present');
T::ok(!isset($cmp[367]), 'no doy > 366');
$manual = function (int $doy) use ($year): float {
    $vals = [];
    for ($y = $year - METEO_HISTORY_YEARS; $y < $year; $y++) {
        $cum = 0.0;
        for ($d = 1; $d <= $doy; $d++) {
            $date = date('Y-m-d', strtotime("$y-01-01 +" . ($d - 1) . ' days'));
            $m = (int) substr($date, 5, 2);
            $f = $m === 1 ? 0.5 : ($m === 2 ? 0.75 : 1.0);
            $t = fx_temp($y, $d);
            if ($t > 0) $cum += $t * $f;
        }
        $vals[] = $cum;
    }
    return array_sum($vals) / count($vals);
};
T::approx($manual(60), $cmp[60], 0.1, 'doy 60 average over 11 previous years');
T::approx($manual(200), $cmp[200], 0.1, 'doy 200 average over 11 previous years');
T::ok($cmp[200] > $cmp[60], 'average increases through the year');
T::eq([], GTSService::getHistoricalComparison($ids['l2'], $year), 'location without data → empty');

// ═══════════════════════════════════════════════════════════
T::section('GTSService forest-honey indicators & history comparison');
$ind = GTSService::getForestHoneyIndicators($L1, $year, 6);
T::ok(is_numeric($ind['historical_avg_temp']) && $ind['historical_avg_temp'] > 10, 'June normal temp from climate_normals');
T::ok(is_numeric($ind['current_avg_temp']), 'June current temp numeric');
$ind3 = T::noThrow(fn() => GTSService::getForestHoneyIndicators($L3, $year, 6), 'fallback without climate_normals runs (regression: unknown column)');
if ($ind3) {
    T::ok($ind3['historical_avg_temp'] > 10, 'fallback computes historical temp from weather_history');
    T::ok($ind3['historical_avg_precip'] > 0, 'fallback computes historical precip');
}
$empty = GTSService::getForestHoneyIndicators($ids['l2'], $year, 6);
T::eq('-', $empty['current_avg_temp'], 'no data → "-" placeholder');
$series = GTSService::getHistoryComparison($L1);
T::eq(12 + (int) date('n'), count($series), 'series covers previous year + months of current year');
T::eq($year - 1, $series[0]['year'], 'series starts in previous year');
T::eq((int) date('n'), end($series)['month'], 'series ends with current month');

// ═══════════════════════════════════════════════════════════
T::section('RuleEngine::evaluate – logic & operators');
$ctx = ['gts' => 120, 'temp_min' => -1, 'temp_max' => 18, 'month' => 4, 'precipitation' => 0];
$leaf = fn($f, $op, $v) => ['type' => 'daily', 'field' => $f, 'operator' => $op, 'value' => $v];
T::eq(true,  RuleEngine::evaluate(['logic' => 'AND', 'conditions' => [$leaf('gts', '>=', 100), $leaf('temp_min', '<', 0)]], $ctx), 'AND true');
T::eq(false, RuleEngine::evaluate(['logic' => 'AND', 'conditions' => [$leaf('gts', '>=', 100), $leaf('temp_min', '>', 0)]], $ctx), 'AND false');
T::eq(true,  RuleEngine::evaluate(['logic' => 'OR',  'conditions' => [$leaf('gts', '>', 999), $leaf('temp_min', '<', 0)]], $ctx), 'OR true');
T::eq(false, RuleEngine::evaluate(['logic' => 'OR',  'conditions' => [$leaf('gts', '>', 999), $leaf('temp_min', '>', 0)]], $ctx), 'OR false');
T::eq(true,  RuleEngine::evaluate(['logic' => 'AND', 'conditions' => []], $ctx), 'empty group → true');
T::eq(true,  RuleEngine::evaluate(['logic' => 'AND', 'conditions' => [
    ['logic' => 'OR', 'conditions' => [$leaf('month', '==', 4), $leaf('month', '==', 5)]],
    $leaf('gts', 'between', [100, 200]),
]], $ctx), 'nested groups + between');
T::eq(true,  RuleEngine::evaluate($leaf('month', 'in', [4, 5]), $ctx), 'in operator');
T::eq(false, RuleEngine::evaluate($leaf('month', 'in', [6, 7]), $ctx), 'in operator false');
T::eq(true,  RuleEngine::evaluate($leaf('month', '!=', 5), $ctx), '!= operator');
T::eq(false, RuleEngine::evaluate($leaf('gts', 'between', [100]), $ctx), 'between with bad array → false');
T::eq(false, RuleEngine::evaluate($leaf('does_not_exist', '>', 0), $ctx), 'unknown field → false (no exception)');
T::eq(false, RuleEngine::evaluate($leaf('gts', '~', 0), $ctx), 'unknown operator → false');
T::eq(false, RuleEngine::evaluate(['type' => 'marker_reference', 'marker_id' => 999], $ctx), 'marker_reference to unknown marker → false');

// ═══════════════════════════════════════════════════════════
T::section('RuleEngine::buildContext');
$gtsData = $gts;
$day = "$year-06-10";
$entry = null;
foreach ($gtsData as $e) { if ($e['date'] === $day) { $entry = $e; break; } }
$c = RuleEngine::buildContext($L1, $day, $entry, $gtsData);
$doy = fx_doy($day);
T::approx(fx_temp($year, $doy) - 5, $c['temp_min'], 0.01, 'temp_min from weather_history');
T::approx(fx_temp($year, $doy) + 5, $c['temp_max'], 0.01, 'temp_max from weather_history');
T::eq(6, $c['month'], 'month');
T::eq($doy, $c['day_of_year'], 'day_of_year');
T::eq(0, $c['is_forecast'], 'past day is not forecast');
$p3 = 0; for ($i = 0; $i < 3; $i++) $p3 += fx_precip($doy - $i);
T::approx($p3, $c['precip_sum_3d'], 0.01, 'precip_sum_3d');
$p7 = 0; for ($i = 0; $i < 7; $i++) $p7 += fx_precip($doy - $i);
T::approx($p7, $c['precip_sum_7d'], 0.01, 'precip_sum_7d');
$tmax7 = -99; for ($i = 0; $i < 7; $i++) $tmax7 = max($tmax7, fx_temp($year, $doy - $i) + 5);
T::approx($tmax7, $c['temp_max_max_7d'], 0.01, 'temp_max_max_7d');
$tmin2 = 99; for ($i = 0; $i < 2; $i++) $tmin2 = min($tmin2, fx_temp($year, $doy - $i) - 5);
T::approx($tmin2, $c['temp_min_min_2d'], 0.01, 'temp_min_min_2d');
T::ok(is_numeric($c['temp_deviation']), 'temp_deviation numeric (normals path)');
$cf = RuleEngine::buildContext($L1, date('Y-m-d', strtotime("$today +3 days")), null, $gtsData);
T::eq(1, $cf['is_forecast'], 'future day is forecast');
T::ok($cf['temp_min'] !== null, 'forecast day gets temp_min from weather_forecast');
$c3 = T::noThrow(fn() => RuleEngine::buildContext($L3, "$year-06-10", $entry, $gtsData), 'context without climate_normals (±7 day fallback) runs');
if ($c3) T::ok(is_numeric($c3['temp_deviation']), 'fallback deviation numeric');
$cj = T::noThrow(fn() => RuleEngine::buildContext($L3, "$year-01-01", null, []), 'context on Jan 1 (window crosses year, no gts data)');
if ($cj) T::approx(0.0, $cj['precip_sum_7d'], 0.0001, 'no gts data → precip window 0');
$cn = RuleEngine::buildContext($ids['l2'], "$year-06-10", null, []);
T::eq(null, $cn['temp_min'], 'no weather at all → temp_min null');
T::eq(false, RuleEngine::evaluate($leaf('temp_min', '<', 0), $cn), 'null field never matches');
$cJan = RuleEngine::buildContext($L1, "$year-01-15", null, $gtsData);
T::eq(1, $cJan['is_frost'], 'January fixture day is frost');

// ═══════════════════════════════════════════════════════════
T::section('RuleEngine period conditions');
RuleEngine::clearCache();
$ctxToday = RuleEngine::buildContext($L1, $today, null, $gtsData);
$pa = ['type' => 'period_aggregate', 'year_ref' => 'previous', 'period_type' => 'month', 'period_month' => 10,
       'field' => 'precipitation', 'aggregation' => 'sum', 'operator' => '>', 'value' => 0];
T::eq(true, RuleEngine::evaluate($pa, $ctxToday), 'period_aggregate previous October sum > 0');
$octSum = 0; for ($d = fx_doy(($year - 1) . '-10-01'); $d <= fx_doy(($year - 1) . '-10-31'); $d++) $octSum += fx_precip($d);
$pa['operator'] = '=='; $pa['value'] = round($octSum, 2);
T::eq(true, RuleEngine::evaluate($pa, $ctxToday), 'period_aggregate sum equals manual October total (' . $octSum . ')');
$pa['compare_mode'] = 'historical_percent'; $pa['operator'] = 'between'; $pa['value'] = [80, 120];
T::eq(true, RuleEngine::evaluate($pa, $ctxToday), 'historical_percent: identical fixture years → ≈100 %');
$paAvg = ['type' => 'period_aggregate', 'year_ref' => 'current', 'period_type' => 'date_range', 'period_start' => '03-01',
          'period_end' => '03-31', 'field' => 'temp_mean', 'aggregation' => 'avg', 'operator' => '>', 'value' => -50];
T::eq(true, RuleEngine::evaluate($paAvg, $ctxToday), 'date_range avg evaluates');
$paEmpty = ['type' => 'period_aggregate', 'year_ref' => 1950, 'period_type' => 'full_year', 'field' => 'precipitation',
            'aggregation' => 'sum', 'operator' => '>=', 'value' => 0];
T::eq(false, RuleEngine::evaluate($paEmpty, $ctxToday), 'period without data → false (not true)');
$paBad = ['type' => 'period_aggregate', 'year_ref' => 'current', 'period_type' => 'date_range', 'period_start' => '02-31',
          'period_end' => 'xx', 'field' => 'precipitation', 'aggregation' => 'sum', 'operator' => '>=', 'value' => 0];
T::noThrow(fn() => RuleEngine::evaluate($paBad, $ctxToday), 'invalid period dates (02-31 / xx) do not throw');

$dc = ['type' => 'day_count', 'year_ref' => 'current', 'period_type' => 'month', 'period_month' => 4,
       'field' => 'temp_max', 'sub_operator' => '>', 'sub_value' => 15, 'operator' => '==', 'value' => 0];
$aprCount = 0; for ($d = fx_doy("$year-04-01"); $d <= fx_doy("$year-04-30"); $d++) if (fx_temp($year, $d) + 5 > 15) $aprCount++;
$dc['value'] = $aprCount;
T::eq(true, RuleEngine::evaluate($dc, $ctxToday), "day_count April temp_max>15 equals manual count ($aprCount)");
$dcFrost = ['type' => 'day_count', 'year_ref' => 'current', 'period_type' => 'date_range', 'period_start' => '01-01',
            'period_end' => '02-28', 'field' => 'is_frost', 'sub_operator' => '==', 'sub_value' => 1, 'operator' => '>', 'value' => 10];
T::eq(true, RuleEngine::evaluate($dcFrost, $ctxToday), 'day_count on computed field is_frost');

$cd = ['type' => 'consecutive_days', 'year_ref' => 'current', 'period_type' => 'date_range', 'period_start' => '06-01',
       'period_end' => '08-31', 'field' => 'precipitation', 'sub_operator' => '<', 'sub_value' => 1, 'operator' => '==', 'value' => 0];
// fixture: rain every 3rd/7th day → longest dry streak is 2
$cd['value'] = 2;
T::eq(true, RuleEngine::evaluate($cd, $ctxToday), 'consecutive_days longest dry streak = 2');
$cd['operator'] = '>='; $cd['value'] = 3;
T::eq(false, RuleEngine::evaluate($cd, $ctxToday), 'consecutive_days ≥ 3 false');

$ts = ['type' => 'temp_sum', 'year_ref' => 'current', 'period_type' => 'month', 'period_month' => 5,
       'field' => 'temp_mean', 'threshold' => 5, 'operator' => '==', 'value' => 0];
$maySum = 0; for ($d = fx_doy("$year-05-01"); $d <= fx_doy("$year-05-31"); $d++) { $t = fx_temp($year, $d); if ($t > 5) $maySum += $t - 5; }
$ts['value'] = round($maySum, 2);
T::eq(true, RuleEngine::evaluate($ts, $ctxToday), 'temp_sum May above 5 °C equals manual (' . round($maySum, 2) . ')');
$ts['compare_mode'] = 'historical_percent'; $ts['operator'] = 'between'; $ts['value'] = [90, 110];
T::eq(true, RuleEngine::evaluate($ts, $ctxToday), 'temp_sum historical_percent ≈ 100 %');

// Prozentvergleich mit dem Vorjahr: das Vorjahr darf nicht in seiner eigenen Referenz stecken
$prevSum = 0; $others = [];
for ($y = $year - METEO_HISTORY_YEARS; $y < $year; $y++) {
    $sumY = 0;
    for ($d = fx_doy("$y-05-01"); $d <= fx_doy("$y-05-31"); $d++) { $t = fx_temp($y, $d); if ($t > 5) $sumY += $t - 5; }
    if ($y === $year - 1) $prevSum = $sumY; else $others[] = $sumY;
}
$pctExcl = $prevSum / (array_sum($others) / count($others)) * 100;                 // korrekt
$pctIncl = $prevSum / ((array_sum($others) + $prevSum) / (count($others) + 1)) * 100; // alt (Vorjahr in Referenz)
$tsPrev = ['type' => 'temp_sum', 'year_ref' => 'previous', 'period_type' => 'month', 'period_month' => 5,
           'field' => 'temp_mean', 'threshold' => 5, 'compare_mode' => 'historical_percent', 'operator' => 'between', 'value' => [$pctExcl - 0.05, $pctExcl + 0.05]];
T::ok(abs($pctExcl - $pctIncl) > 0.2, sprintf('fixture distinguishes both variants (%.2f %% vs %.2f %%)', $pctExcl, $pctIncl));
T::eq(true, RuleEngine::evaluate($tsPrev, $ctxToday), 'temp_sum previous-year percent excludes the compared year from its reference');
$tsPrev['value'] = [$pctIncl - 0.05, $pctIncl + 0.05];
T::eq(false, RuleEngine::evaluate($tsPrev, $ctxToday), 'old behaviour (compared year inside reference) no longer matches');
$statsPrev = RuleEngine::getHistoricalStats($L1, $tsPrev);
T::eq(METEO_HISTORY_YEARS - 1, count($statsPrev['years']), 'historical_stats preview for previous-year percent drops the compared year');
T::ok(!isset($statsPrev['years'][$year - 1]), 'previous year absent from preview reference');
$paPrevPct = $pa; $paPrevPct['compare_mode'] = 'historical_percent';
$paPrevCnt = RuleEngine::getHistoricalStats($L1, ['type' => 'period_aggregate', 'year_ref' => 'previous', 'period_type' => 'month', 'period_month' => 10, 'field' => 'precipitation', 'aggregation' => 'sum']);
T::eq(METEO_HISTORY_YEARS, count($paPrevCnt['years']), 'absolute comparison keeps all reference years');

$stats = RuleEngine::getHistoricalStats($L1, $pa);
T::eq(METEO_HISTORY_YEARS - 1, count($stats['years']), 'historical_stats: one value per reference year ($pa is previous-year percent → compared year excluded)');
T::approx($octSum, $stats['avg'], 0.5, 'historical_stats avg ≈ October total');
T::ok($stats['min'] <= $stats['avg'] && $stats['avg'] <= $stats['max'], 'min ≤ avg ≤ max');
$statsEmpty = RuleEngine::getHistoricalStats($ids['l2'], $pa);
T::eq(null, $statsEmpty['avg'], 'historical_stats without data → null avg');
foreach (['temp_sum' => $ts, 'day_count' => $dc, 'consecutive_days' => $cd] as $name => $def) {
    $s = RuleEngine::getHistoricalStats($L1, $def);
    T::ok(is_numeric($s['avg']), "historical_stats type $name numeric");
}

// ═══════════════════════════════════════════════════════════
T::section('RuleEngine predictions');
$userId = $ids['user'];
$mkMarker = function (string $name, string $type, ?float $thr, ?array $rules = null) use ($userId): int {
    return MarkerService::create($userId, $name, $type, $thr, '#ff0000', null, 'info', "msg $name", $rules);
};
$mLow  = $mkMarker('Low', 'gts', 30.0);       // reached in spring → expires after 21 days (pure gts >=)
$mHuge = $mkMarker('Huge', 'gts', 99999.0);   // never reached
// upcoming: threshold just below GTS of today+5
$plus5 = null; foreach ($gtsData as $e) { if ($e['date'] === date('Y-m-d', strtotime("$today +5 days"))) $plus5 = $e; }
$mUp   = $mkMarker('Upcoming', 'gts', (float) ($plus5['gts'] - 0.001));
// reached long ago but not expiring (has a non-gts condition) → checks DST-safe day difference
$mOld  = $mkMarker('OldComplex', 'complex', null, ['logic' => 'AND', 'conditions' => [
    $leaf('gts', '>=', 30), $leaf('temp_mean', '>', -100)]]);
$mPast = $mkMarker('PastOnly', 'complex', null, ['logic' => 'AND', 'conditions' => [
    $leaf('gts', '>=', 30), $leaf('month', 'in', [3, 4])]]);   // triggered March/April only
$mPeriod = $mkMarker('PeriodOnly', 'complex', null, ['logic' => 'AND', 'conditions' => [$pa]]);
$mRef  = $mkMarker('Ref', 'complex', null, ['logic' => 'AND', 'conditions' => [['type' => 'marker_reference', 'marker_id' => $mUp]]]);
$mSelf = $mkMarker('Self', 'complex', null, ['logic' => 'AND', 'conditions' => [['type' => 'marker_reference', 'marker_id' => 0]]]);
$db->prepare('UPDATE markers SET rules = ? WHERE id = ?')->execute([json_encode(['logic' => 'AND', 'conditions' => [['type' => 'marker_reference', 'marker_id' => $mSelf]]]), $mSelf]);

$preds = RuleEngine::predictMarkers($L1, $userId, $gtsData);
$byName = []; foreach ($preds as $p) $byName[$p['marker']['name']] = $p;
T::ok(!isset($byName['Huge']), 'unreachable marker not predicted');
T::ok(!isset($byName['Low']), 'pure GTS marker reached > 21 days ago expires');
T::ok(isset($byName['Upcoming']), 'upcoming marker predicted');
if (isset($byName['Upcoming'])) {
    T::eq('upcoming', $byName['Upcoming']['status'], 'status upcoming');
    T::eq(5, $byName['Upcoming']['days_until'], 'days_until = 5');
    T::eq($plus5['date'], $byName['Upcoming']['date'], 'predicted date = today+5');
}
T::ok(isset($byName['OldComplex']), 'complex marker reached in spring still listed');
if (isset($byName['OldComplex'])) {
    $first = $byName['OldComplex']['date'];
    $exact = -(int) (new DateTime($first))->diff(new DateTime($today))->days;
    T::eq($exact, $byName['OldComplex']['days_until'], "days_until exact calendar difference across DST ($first → $today = $exact)");
    T::eq('active', $byName['OldComplex']['status'], 'still triggered today → status active');
}
T::ok(isset($byName['PeriodOnly']) && $byName['PeriodOnly']['status'] === 'active', 'period-only marker evaluated once → active');
T::ok(isset($byName['PastOnly']) && $byName['PastOnly']['status'] === 'reached' && $byName['PastOnly']['days_until'] < -100, 'marker triggered only in spring → reached, negative days_until');
T::ok(isset($byName['Ref']) && $byName['Ref']['date'] === $plus5['date'], 'marker_reference resolves referenced marker');
T::ok(!isset($byName['Self']), 'self-referencing marker does not recurse');
T::eq(-5, RuleEngine::dayDiff('2026-03-30', '2026-03-25'), 'dayDiff negative');
T::eq(1, RuleEngine::dayDiff('2026-03-28', '2026-03-29'), 'dayDiff across spring DST switch = 1');
T::eq(365, RuleEngine::dayDiff('2025-09-04', '2026-09-04'), 'dayDiff full year');

$mTrans = $mkMarker('Window', 'complex', null, ['logic' => 'AND', 'conditions' => [
    $leaf('gts', '>=', (float) $plus5['gts'] - 0.001), $leaf('gts', '<', (float) $gtsData[count($gtsData) - 3]['gts'])]]);
$events = RuleEngine::predictGTSRuleTransitions($L1, $userId, $gtsData);
$evWindow = array_values(array_filter($events, fn($e) => $e['marker']['id'] == $mTrans));
T::eq(2, count($evWindow), 'pure multi-rule GTS marker yields begin + end');
if (count($evWindow) === 2) {
    T::eq('begin', $evWindow[0]['kind'], 'first event is begin');
    T::eq(5, $evWindow[0]['days_until'], 'begin in 5 days');
    T::eq('end', $evWindow[1]['kind'], 'second event is end');
    T::ok($evWindow[1]['days_until'] > 5, 'end after begin');
}
$trig = RuleEngine::evaluateForDate($L1, $userId, $plus5['date'], $gtsData);
$trigNames = array_map(fn($t) => $t['marker']['name'], $trig);
T::ok(in_array('Upcoming', $trigNames, true), 'evaluateForDate: Upcoming triggered on its date');
T::ok(in_array('OldComplex', $trigNames, true), 'evaluateForDate: OldComplex triggered');
T::ok(!in_array('Huge', $trigNames, true), 'evaluateForDate: Huge not triggered');

T::section('RuleEngine templates');
foreach (RuleEngine::getTemplates() as $tpl) {
    T::noThrow(fn() => RuleEngine::evaluate($tpl['rules'], $ctxToday), 'template evaluates: ' . $tpl['name']);
}
T::eq(true, MarkerService::update($mUp, $userId, 'Upcoming', 'gts', (float) ($plus5['gts'] - 0.001), '#ff0000', null, 'info', 'msg Upcoming', null),
    'MarkerService::update with unchanged values reports success (regression: rowCount)');
T::eq(false, MarkerService::update(999999, $userId, 'x', 'gts', 1.0, '#ff0000', null), 'update of unknown marker → false');

exit(T::summary() > 0 ? 1 : 0);
