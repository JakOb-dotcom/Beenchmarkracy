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
 * Test harness (no PHPUnit/Composer needed).
 *
 * - Creates a temporary database from database/schema.sql (name from TEST_DB_NAME).
 * - Points the application config at it via environment variables
 *   (APP_IGNORE_LOCAL_CONFIG=1 makes config.php skip config.local.php).
 * - Provides tiny assertion helpers and deterministic weather fixtures.
 *
 * DB credentials: TEST_DB_HOST/PORT/USER/PASS env vars, otherwise the values from
 * config/config.local.php, otherwise localhost/3306/root/empty.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('TEST_ROOT', dirname(__DIR__));

// ── credentials ────────────────────────────────────────────
$__local = is_file(TEST_ROOT . '/config/config.local.php') ? (require TEST_ROOT . '/config/config.local.php') : [];
if (!is_array($__local)) {
    $__local = [];
}
function test_cred(string $key, array $local, string $default): string
{
    $env = getenv('TEST_' . $key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return isset($local[$key]) ? (string) $local[$key] : $default;
}
define('TEST_DB_HOST', test_cred('DB_HOST', $__local, 'localhost'));
define('TEST_DB_PORT', test_cred('DB_PORT', $__local, '3306'));
define('TEST_DB_USER', test_cred('DB_USER', $__local, 'root'));
define('TEST_DB_PASS', test_cred('DB_PASS', $__local, ''));
define('TEST_DB_NAME', getenv('TEST_DB_NAME') ?: ('forecasting_test_' . substr(md5((string) microtime(true)), 0, 8)));
unset($__local);

/** Environment the application (and the API test server) must run with. */
function test_app_env(): array
{
    return [
        'APP_IGNORE_LOCAL_CONFIG' => '1',
        'APP_ENV'                 => 'development',
        'APP_DEBUG'               => '1',
        'DB_HOST'                 => TEST_DB_HOST,
        'DB_PORT'                 => TEST_DB_PORT,
        'DB_NAME'                 => TEST_DB_NAME,
        'DB_USER'                 => TEST_DB_USER,
        'DB_PASS'                 => TEST_DB_PASS,
        'APP_LOG_FILE'            => TEST_ROOT . '/logs/test.log',
        'OCR_ENABLED'             => '1',
        'METEO_VERIFY_SSL'        => '0',
    ];
}
foreach (test_app_env() as $k => $v) {
    putenv("$k=$v");
}

// ── temp database ──────────────────────────────────────────
function test_server_pdo(): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', TEST_DB_HOST, (int) TEST_DB_PORT);
    return new PDO($dsn, TEST_DB_USER, TEST_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function test_create_database(): void
{
    $pdo = test_server_pdo();
    $pdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
    $sql = file_get_contents(TEST_ROOT . '/database/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('schema.sql not readable');
    }
    $sql = str_replace('`forecasting`', '`' . TEST_DB_NAME . '`', $sql);
    // Execute statement by statement (comments stripped) – works with every PDO build.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

function test_drop_database(): void
{
    try {
        test_server_pdo()->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
    } catch (Throwable $e) {
        fwrite(STDERR, 'Could not drop test DB: ' . $e->getMessage() . "\n");
    }
}

// ── assertions ─────────────────────────────────────────────
final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
    public static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n## $name\n";
    }

    public static function ok(bool $cond, string $msg): bool
    {
        if ($cond) {
            self::$pass++;
            echo "  ok   $msg\n";
        } else {
            self::$fail++;
            self::$failures[] = self::$section . ' → ' . $msg;
            echo "  FAIL $msg\n";
        }
        return $cond;
    }

    public static function eq(mixed $expected, mixed $actual, string $msg): bool
    {
        $ok = $expected === $actual;
        return self::ok($ok, $msg . ($ok ? '' : ' [expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ']'));
    }

    public static function approx(float $expected, mixed $actual, float $tol, string $msg): bool
    {
        $ok = is_numeric($actual) && abs($expected - (float) $actual) <= $tol;
        return self::ok($ok, $msg . ($ok ? '' : " [expected ≈ $expected ± $tol, got " . var_export($actual, true) . ']'));
    }

    public static function throws(callable $fn, string $msg): bool
    {
        try {
            $fn();
            return self::ok(false, $msg . ' [no exception thrown]');
        } catch (Throwable $e) {
            return self::ok(true, $msg);
        }
    }

    public static function noThrow(callable $fn, string $msg): mixed
    {
        try {
            $r = $fn();
            self::ok(true, $msg);
            return $r;
        } catch (Throwable $e) {
            self::ok(false, $msg . ' [' . get_class($e) . ': ' . $e->getMessage() . ']');
            return null;
        }
    }

    public static function summary(): int
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo 'Result: ' . self::$pass . ' passed, ' . self::$fail . " failed\n";
        foreach (self::$failures as $f) {
            echo "  - $f\n";
        }
        return self::$fail;
    }
}

// ── deterministic weather fixtures ─────────────────────────
/** Daily mean temperature: seasonal sine, small per-year offset; negative in January. */
function fx_temp(int $year, int $doy): float
{
    return round(10 + 12 * sin(2 * M_PI * ($doy - 100) / 365) + (($year % 3) - 1) * 0.5, 2);
}
function fx_precip(int $doy): float
{
    return $doy % 7 === 0 ? 12.0 : ($doy % 3 === 0 ? 2.0 : 0.0);
}
function fx_doy(string $date): int
{
    return (int) date('z', strtotime($date)) + 1;
}

/**
 * Seeds users, locations and weather data. Returns ids.
 *  - user 'tester' (Test12345!) owns L1 (full history, forecast, normals) and L3 (2 years history, no normals)
 *  - user 'other'  (Other12345!) owns L2 (no weather data)
 */
function test_seed_fixtures(PDO $db, int $historyYears = 11): array
{
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['hive_records', 'hive_evaluations', 'hive_notes', 'hives', 'record_settings', 'marker_locations', 'markers',
              'location_notes', 'climate_normals', 'weather_forecast', 'weather_history', 'ocr_jobs', 'login_attempts',
              'locations', 'users'] as $t) {
        $db->exec("TRUNCATE TABLE `$t`");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');

    $ins = $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
    $ins->execute(['tester', password_hash('Test12345!', PASSWORD_DEFAULT)]);
    $userId = (int) $db->lastInsertId();
    $ins->execute(['other', password_hash('Other12345!', PASSWORD_DEFAULT)]);
    $otherId = (int) $db->lastInsertId();

    $insLoc = $db->prepare('INSERT INTO locations (user_id, name, latitude, longitude, altitude) VALUES (?, ?, ?, ?, ?)');
    $insLoc->execute([$userId, 'Fixture Apiary', 46.78, 15.77, 300]);
    $l1 = (int) $db->lastInsertId();
    $insLoc->execute([$otherId, 'Foreign Apiary', 47.0, 15.4, 400]);
    $l2 = (int) $db->lastInsertId();
    $insLoc->execute([$userId, 'Sparse Apiary', 46.9, 15.6, null]);
    $l3 = (int) $db->lastInsertId();

    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('yesterday'));
    $year      = (int) date('Y');

    $insH = $db->prepare('INSERT INTO weather_history (location_id, date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture) VALUES (?,?,?,?,?,?,?,?)');
    $db->beginTransaction();
    for ($y = $year - $historyYears; $y <= $year; $y++) {
        $d = new DateTimeImmutable("$y-01-01");
        while ($d->format('Y') === (string) $y && $d->format('Y-m-d') <= $yesterday) {
            $doy  = (int) $d->format('z') + 1;
            $temp = fx_temp($y, $doy);
            $insH->execute([$l1, $d->format('Y-m-d'), $temp, $temp - 5, $temp + 5, fx_precip($doy), 1000, 0.3]);
            if ($y >= $year - 1) {
                $insH->execute([$l3, $d->format('Y-m-d'), $temp, $temp - 5, $temp + 5, fx_precip($doy), 1000, 0.3]);
            }
            $d = $d->modify('+1 day');
        }
    }
    $insF = $db->prepare('INSERT INTO weather_forecast (location_id, date, temp_mean, temp_min, temp_max, precipitation, pressure, soil_moisture) VALUES (?,?,?,?,?,?,?,?)');
    for ($i = 0; $i < 16; $i++) {
        $d    = date('Y-m-d', strtotime("$today +$i days"));
        $doy  = fx_doy($d);
        $temp = fx_temp((int) substr($d, 0, 4), $doy);
        $insF->execute([$l1, $d, $temp, $temp - 5, $temp + 5, fx_precip($doy), 1000, 0.3]);
        $insF->execute([$l3, $d, $temp, $temp - 5, $temp + 5, fx_precip($doy), 1000, 0.3]);
    }
    // climate normals for L1 from the same formula (year offset 0 → a year with year % 3 === 1)
    $insN = $db->prepare('INSERT INTO climate_normals (location_id, month, avg_temp, avg_precip, reference_period) VALUES (?,?,?,?,?)');
    for ($m = 1; $m <= 12; $m++) {
        $sum = 0; $n = 0; $p = 0;
        $d = new DateTimeImmutable(sprintf('2023-%02d-01', $m));
        while ((int) $d->format('n') === $m) {
            $doy = (int) $d->format('z') + 1;
            $sum += fx_temp(2023, $doy); $n++; $p += fx_precip($doy);
            $d = $d->modify('+1 day');
        }
        $insN->execute([$l1, $m, round($sum / $n, 2), round($p, 2), '1995-2024']);
    }
    $db->commit();

    return ['user' => $userId, 'other' => $otherId, 'l1' => $l1, 'l2' => $l2, 'l3' => $l3];
}
