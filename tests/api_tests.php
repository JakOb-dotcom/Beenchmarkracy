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
 * End-to-end API tests: starts PHP's built-in web server on the test database and
 * exercises every endpoint over HTTP (auth, CSRF, ownership, validation, CRUD).
 * Network calls to Open-Meteo are avoided (location_refresh is only run in modes
 * that do not fetch). Run via tests/run.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/config/database.php';

if (DB_NAME !== TEST_DB_NAME) {
    exit("Refusing to run: application is not pointed at the test database\n");
}

$db  = getDB();
$ids = test_seed_fixtures($db);
$year  = (int) date('Y');
$today = date('Y-m-d');

// ── built-in server ────────────────────────────────────────
$port = (int) (getenv('TEST_API_PORT') ?: 8097);
$base = "http://127.0.0.1:$port/api/index.php?action=";
$env  = array_merge(getenv(), test_app_env());
$desc = [0 => ['pipe', 'r'], 1 => ['file', TEST_ROOT . '/logs/test-server.log', 'w'], 2 => ['file', TEST_ROOT . '/logs/test-server.log', 'a']];
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', TEST_ROOT], $desc, $pipes, TEST_ROOT, $env, ['bypass_shell' => true]);
if (!is_resource($server)) {
    exit("Could not start PHP built-in server\n");
}
register_shutdown_function(function () use ($server) {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $ch = curl_init($base . 'check_auth');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $r = curl_exec($ch);
    curl_close($ch);
    if ($r !== false) { $up = true; break; }
    usleep(200_000);
}
if (!$up) {
    exit("Test server did not come up on port $port\n");
}

// ── client ─────────────────────────────────────────────────
final class ApiClient
{
    private string $cookie;
    public ?string $csrf = null;
    public array $last = [];

    public function __construct(private string $base, string $name)
    {
        $this->cookie = sys_get_temp_dir() . "/forecasting-test-$name-" . getmypid() . '.txt';
        @unlink($this->cookie);
    }

    /** @return array{0:int,1:mixed,2:string} [status, decoded json (or null), raw] */
    public function call(string $action, string $method = 'GET', ?array $body = null, bool $csrf = true, array $files = []): array
    {
        $ch = curl_init($this->base . $action);
        $headers = [];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookie,
            CURLOPT_COOKIEFILE     => $this->cookie,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($method !== 'GET') {
            if ($csrf && $this->csrf !== null) {
                $headers[] = 'X-CSRF-TOKEN: ' . $this->csrf;
            }
            if ($files) {
                $post = $body ?? [];
                foreach ($files as $field => $path) {
                    $post[$field] = new CURLFile($path);
                }
                $opts[CURLOPT_POSTFIELDS] = $post;
            } else {
                $headers[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode($raw, true);
        $this->last = [$status, $json, $raw];
        return [$status, $json, $raw];
    }

    public function get(string $action): array { return $this->call($action); }
    public function post(string $action, array $body = []): array { return $this->call($action, 'POST', $body); }

    public function login(string $user, string $pass): array
    {
        [$s, $j] = $this->post('login', ['username' => $user, 'password' => $pass]);
        if ($s === 200 && isset($j['csrf_token'])) {
            $this->csrf = $j['csrf_token'];
        }
        return [$s, $j];
    }
}

$c  = new ApiClient($base, 'tester');
$o  = new ApiClient($base, 'other');
$L1 = $ids['l1']; $L2 = $ids['l2']; $L3 = $ids['l3'];

// ═══════════════════════════════════════════════════════════
T::section('API: auth, CSRF, routing');
[$s, $j] = $c->get('check_auth');
T::eq(200, $s, 'check_auth reachable'); T::eq(false, $j['logged_in'] ?? null, 'not logged in initially');
[$s] = $c->get('locations'); T::eq(401, $s, 'protected action without login → 401');
[$s] = $c->get('nonexistent_action'); T::eq(404, $s, 'unknown action → 404');
[$s] = $c->call('', 'GET'); T::eq(400, $s, 'missing action → 400');
[$s] = $c->post('login', ['username' => 'tester', 'password' => 'wrong']); T::eq(401, $s, 'wrong password → 401');
[$s] = $c->post('login', ['username' => '', 'password' => '']); T::eq(401, $s, 'empty credentials → 401');
[$s] = $c->call('login', 'POST', null, false); // invalid JSON body? (empty object) → 401 not 500
T::ok(in_array($s, [400, 401], true), 'login with empty body → 4xx');
$db->exec("DELETE FROM login_attempts");
for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) { $c->post('login', ['username' => 'tester', 'password' => 'wrong']); }
[$s] = $c->post('login', ['username' => 'tester', 'password' => 'Test12345!']);
T::eq(429, $s, 'throttled after ' . LOGIN_MAX_ATTEMPTS . ' failures even with correct password');
$db->exec("DELETE FROM login_attempts");
[$s, $j] = $c->login('tester', 'Test12345!');
T::eq(200, $s, 'login ok'); T::ok(!empty($c->csrf), 'csrf token returned');
[$s, $j] = $c->get('check_auth'); T::eq(true, $j['logged_in'] ?? null, 'check_auth logged in'); T::eq('tester', $j['username'] ?? null, 'username');
[$s] = $c->call('locations', 'POST', ['name' => 'x', 'latitude' => 1, 'longitude' => 1], false); T::eq(403, $s, 'POST without CSRF → 403');
[$s] = $c->call('location_delete', 'GET'); T::eq(405, $s, 'GET on POST-only action → 405');
[$s] = $c->post('bootstrap_sync'); T::eq(200, $s, 'bootstrap_sync ok'); T::ok(in_array($L1, $c->last[1]['location_ids'] ?? [], true), 'bootstrap_sync lists own location');
[$s] = $c->post('bootstrap_sync_complete'); T::eq(200, $s, 'bootstrap_sync_complete ok');
[$s, $j] = $c->post('bootstrap_sync'); T::eq(true, $j['skipped'] ?? null, 'bootstrap_sync skipped after complete');
[$s] = $o->login('other', 'Other12345!'); T::eq(200, $s, 'second user login');

// ═══════════════════════════════════════════════════════════
T::section('API: locations & ownership');
[$s, $j] = $c->get('locations'); T::eq(200, $s, 'locations list'); T::eq(2, count($j), 'tester owns 2 locations');
[$s] = $c->post('locations', ['name' => 'Bad', 'latitude' => 100, 'longitude' => 0]); T::eq(400, $s, 'latitude 100 rejected');
[$s] = $c->post('locations', ['name' => '', 'latitude' => 47, 'longitude' => 15]); T::eq(400, $s, 'empty name rejected');
[$s] = $c->post('locations', ['name' => 'NoCoords']); T::eq(400, $s, 'missing coordinates rejected');
[$s, $j] = $c->post('locations', ['name' => str_repeat('N', 300), 'latitude' => '47.1', 'longitude' => '15.2', 'altitude' => '12.7']);
T::eq(200, $s, 'create location'); $newLoc = (int) ($j['id'] ?? 0); T::ok($newLoc > 0, 'new id returned');
[$s, $j] = $c->get('locations'); $created = array_values(array_filter($j, fn($l) => (int) $l['id'] === $newLoc))[0] ?? null;
T::eq(200, mb_strlen($created['name'] ?? ''), 'name truncated to 200 chars'); T::eq(12, (int) ($created['altitude'] ?? -1), 'altitude cast to int');
[$s] = $c->get("gts&location_id=$L2"); T::eq(403, $s, 'foreign location gts → 403');
[$s] = $c->get("hives_get&location_id=$L2"); T::eq(403, $s, 'foreign location hives → 403');
[$s, $j] = $c->post('location_delete', ['id' => $L2]); T::eq(false, $j['success'] ?? null, 'cannot delete foreign location');
[$s] = $o->get("gts&location_id=$L2"); T::eq(200, $s, 'owner can read own (empty) location'); T::eq([], $o->last[1], 'empty gts for location without data');
[$s, $j] = $c->post('location_notes_add', ['location_id' => $L1, 'note' => '  hello  ']); T::eq(200, $s, 'add note');
[$s] = $c->post('location_notes_add', ['location_id' => $L1, 'note' => '   ']); T::eq(400, $s, 'blank note rejected');
[$s] = $c->post('location_notes_add', ['location_id' => $L2, 'note' => 'x']); T::eq(403, $s, 'note on foreign location → 403');
[$s, $j] = $c->get("location_notes_get&location_id=$L1"); T::eq(1, count($j['notes'] ?? []), 'one note'); T::eq('hello', $j['notes'][0]['note'] ?? null, 'note trimmed');
$noteId = (int) $j['notes'][0]['id'];
[$s] = $o->post('location_note_delete', ['id' => $noteId]); T::eq(403, $s, 'other user cannot delete note');
[$s] = $c->post('location_note_delete', ['id' => $noteId]); T::eq(200, $s, 'owner deletes note');
[$s, $j] = $c->post('location_refresh', ['id' => $L1, 'mode' => 'plan']);
T::eq(200, $s, 'refresh plan'); T::eq(METEO_HISTORY_YEARS + 1, count($j['years'] ?? []), 'plan lists 11 years + current'); T::eq(3, count($j['normal_parts'] ?? []), 'plan lists 3 normal parts');
[$s, $j] = $c->post('location_refresh', ['id' => $L1, 'mode' => 'history_chunk', 'year' => 1900]); T::eq(0, $j['inserted'] ?? -1, 'history_chunk for year < 1940 → 0 without network');
[$s] = $c->post('location_refresh', ['id' => $L1, 'mode' => 'normals', 'part' => 9]); T::eq(400, $s, 'invalid normals part → 400');
[$s] = $c->post('location_refresh', ['id' => $L2, 'mode' => 'plan']); T::eq(403, $s, 'refresh of foreign location → 403');

// ═══════════════════════════════════════════════════════════
T::section('API: weather & GTS');
[$s, $j] = $c->get("gts&location_id=$L1"); T::eq(200, $s, 'gts'); T::ok(count($j) > 200, 'gts has rows'); T::ok(isset($j[0]['gts'], $j[0]['factor']), 'gts row shape');
[$s, $j] = $c->get("gts&location_id=$L1&year=1990"); T::eq([], $j, 'gts for empty year → []');
[$s, $j] = $c->get("gts_date&location_id=$L1&date=$today"); T::eq($today, $j['date'] ?? null, 'gts_date today');
[$s, $j] = $c->get("gts_date&location_id=$L1&date=$year-01-01"); T::eq("$year-01-01", $j['date'] ?? null, 'gts_date Jan 1');
[$s, $j] = $c->get("gts_date&location_id=$L1&date=1990-01-01"); T::eq(null, $j, 'gts_date before any data → null');
[$s, $j] = $c->get("gts_date&location_id=$L1&date=garbage"); T::eq(200, $s, 'gts_date with invalid date falls back to today'); T::eq($today, $j['date'] ?? null, 'fallback date is today');
[$s, $j] = $c->get("gts_comparison&location_id=$L1"); T::eq(200, $s, 'gts_comparison'); T::ok(isset($j['200']) || isset($j[200]), 'doy 200 present');
[$s, $j] = $c->get("historie_vergleich&location_id=$L1"); T::eq(200, $s, 'deprecated alias works');
[$s, $j] = $c->get("historie_vergleich_series&location_id=$L1"); T::eq(200, $s, 'historie_vergleich_series'); T::eq(12 + (int) date('n'), count($j), 'series length');
[$s, $j] = $c->get("historie_vergleich_series&location_id=$L3"); T::eq(200, $s, 'series without climate normals (fallback SQL) → 200');
[$s, $j] = $c->get("climate_normals&location_id=$L1"); T::eq(12, count($j), '12 climate normals');
[$s, $j] = $c->get("climate_normals&location_id=$L3"); T::eq([], $j, 'no normals → empty object/array');
[$s, $j] = $c->get("weather&location_id=$L1&start=$year-03-01&end=$year-03-31"); T::eq(31, count($j), 'weather March = 31 rows');
[$s, $j] = $c->get("weather&location_id=$L1&start=bad&end=bad"); T::eq(200, $s, 'weather with bad dates falls back');
[$s, $j] = $c->get("weather_forecast&location_id=$L1"); T::eq(16, count($j), '16 forecast rows from today');

// ═══════════════════════════════════════════════════════════
T::section('API: hives, evaluations, notes, transfer');
[$s, $j] = $c->post('hive_create', ['location_id' => $L1, 'name' => 'Volk A']); T::eq(200, $s, 'hive_create'); $h1 = (int) ($j['id'] ?? 0); T::ok($h1 > 0, 'hive id');
[$s, $j] = $c->post('hive_create', ['location_id' => $L1]); $h2 = (int) ($j['id'] ?? 0); T::ok($h2 > 0, 'hive without name gets default');
[$s] = $c->post('hive_create', ['location_id' => $L2, 'name' => 'x']); T::eq(403, $s, 'hive on foreign location → 403');
[$s, $j] = $c->get('hives_count_all'); T::eq(2, $j['count'] ?? null, 'hives_count_all = 2');
[$s, $j] = $c->get("hives_get&location_id=$L1"); T::eq(2, count($j['hives'] ?? []), 'hives_get 2'); T::eq('Neues Volk', $j['hives'][1]['name'] ?? null, 'default name');
[$s] = $c->post('hive_update', ['hive_id' => $h1, 'name' => 'Volk A2', 'genetics' => 'Carnica']); T::eq(200, $s, 'hive_update');
[$s, $j] = $c->get('hives_get_all'); $hA = array_values(array_filter($j['hives'], fn($h) => (int) $h['id'] === $h1))[0];
T::eq('Volk A2', $hA['name'], 'name updated'); T::eq('Carnica', $hA['genetics'], 'genetics updated'); T::eq('Fixture Apiary', $hA['location_name'], 'location_name joined');
[$s] = $o->post('hive_update', ['hive_id' => $h1, 'name' => 'hacked']); T::eq(403, $s, 'foreign hive update → 403');
[$s] = $c->post('hive_bulk_update_genetics', ['hive_ids' => [$h1, $h2], 'genetics' => 'Buckfast']); T::eq(200, $s, 'bulk genetics');
[$s] = $c->post('hive_bulk_update_genetics', ['hive_ids' => [], 'genetics' => 'x']); T::eq(400, $s, 'bulk genetics without ids → 400');
[$s] = $c->post('hive_evaluation_add', ['hive_id' => $h1, 'date' => '2026-05-01', 'score_honey' => 8, 'score_gentleness' => 15, 'score_varroa' => 0]); T::eq(200, $s, 'evaluation add');
[$s] = $c->post('hive_evaluation_add', ['hive_id' => $h1, 'date' => '2026-05-01', 'score_honey' => 9]); T::eq(200, $s, 'evaluation same date (upsert)');
[$s, $j] = $c->get("hives_get&location_id=$L1"); $ev = array_values(array_filter($j['hives'], fn($h) => (int) $h['id'] === $h1))[0]['evaluations'];
T::eq(1, count($ev), 'upsert keeps one evaluation per date'); T::eq(9, (int) $ev[0]['score_honey'], 'score updated'); T::eq(null, $ev[0]['score_gentleness'], 'missing scores reset to null on upsert');
$evId = (int) $ev[0]['id'];
[$s] = $c->post('hive_evaluation_add', ['hive_id' => $h1, 'date' => '2026-05-02', 'score_gentleness' => 15]); T::eq(200, $s, 'second evaluation');
[$s, $j] = $c->get("hives_get&location_id=$L1"); $ev = array_values(array_filter($j['hives'], fn($h) => (int) $h['id'] === $h1))[0]['evaluations'];
T::eq(10, (int) $ev[1]['score_gentleness'], 'score clamped to 10');
[$s] = $c->post('hive_evaluation_update', ['eval_id' => $evId, 'score_honey' => 3]); T::eq(200, $s, 'evaluation update');
[$s] = $o->post('hive_evaluation_update', ['eval_id' => $evId, 'score_honey' => 1]); T::eq(403, $s, 'foreign evaluation update → 403');
[$s] = $o->post('hive_evaluation_delete', ['eval_id' => $evId]); T::eq(403, $s, 'foreign evaluation delete → 403');
[$s] = $c->post('hive_evaluation_delete', ['eval_id' => $evId]); T::eq(200, $s, 'evaluation delete');
[$s] = $c->post('hive_note_add', ['hive_id' => $h1, 'note' => 'queen marked']); T::eq(200, $s, 'hive note add');
[$s] = $c->post('hive_note_add', ['hive_id' => $h1, 'note' => '']); T::eq(400, $s, 'empty hive note → 400');
[$s, $j] = $c->get("hives_get&location_id=$L1"); $notes = array_values(array_filter($j['hives'], fn($h) => (int) $h['id'] === $h1))[0]['notes'];
T::eq(1, count($notes), 'one hive note');
[$s] = $o->post('hive_note_delete', ['id' => (int) $notes[0]['id']]); T::eq(403, $s, 'foreign note delete → 403');
[$s] = $c->post('hive_note_delete', ['id' => (int) $notes[0]['id']]); T::eq(200, $s, 'note delete');
[$s] = $c->post('hive_transfer', ['hive_id' => $h2, 'target_location_id' => $L3]); T::eq(200, $s, 'transfer to own location');
[$s] = $c->post('hive_transfer', ['hive_id' => $h2, 'target_location_id' => $L2]); T::eq(403, $s, 'transfer to foreign location → 403');
[$s] = $c->post('hive_transfer', ['hive_id' => $h2]); T::eq(400, $s, 'transfer without target → 400');
[$s, $j] = $c->get("hives_get&location_id=$L3"); T::eq(1, count($j['hives']), 'hive now at L3');
[$s] = $o->post('hive_delete', ['hive_id' => $h2]); T::eq(403, $s, 'foreign hive delete → 403');

// ═══════════════════════════════════════════════════════════
T::section('API: markers & rules');
[$s, $j] = $c->post('markers', ['name' => 'GTS 200', 'type' => 'gts', 'threshold_value' => 200, 'color' => '#00FF00', 'severity' => 'warning']);
T::eq(200, $s, 'create gts marker'); $m1 = (int) ($j['id'] ?? 0);
[$s] = $c->post('markers', ['type' => 'gts', 'threshold_value' => 1]); T::eq(400, $s, 'marker without name → 400');
[$s] = $c->post('markers', ['name' => 'x', 'rules' => 'not-an-object']); T::eq(400, $s, 'rules must be an object');
[$s, $j] = $c->get('marker_templates'); T::ok(count($j) >= 5, 'templates available'); $tpl = $j[0];
[$s, $j] = $c->post('markers', ['name' => $tpl['name'], 'type' => 'complex', 'rules' => $tpl['rules'], 'severity' => 'nonsense', 'color' => 'zzz']);
T::eq(200, $s, 'create complex marker from template'); $m2 = (int) $j['id'];
[$s, $j] = $c->get('markers'); T::eq(2, count($j), 'two markers');
$m2row = array_values(array_filter($j, fn($m) => (int) $m['id'] === $m2))[0];
T::eq('info', $m2row['severity'], 'invalid severity → default info'); T::eq('#4caf50', $m2row['color'], 'invalid color → default'); T::ok(is_array($m2row['rules']), 'rules decoded as array');
[$s, $j] = $c->post('marker_update', ['id' => $m1, 'name' => 'GTS 200', 'type' => 'gts', 'threshold_value' => 200, 'color' => '#00ff00', 'severity' => 'warning']);
T::eq(true, $j['success'] ?? null, 'update with identical values succeeds (regression)');
[$s, $j] = $c->post('marker_update', ['id' => $m1, 'name' => 'GTS 250', 'type' => 'gts', 'threshold_value' => 250]); T::eq(true, $j['success'] ?? null, 'update changes');
[$s, $j] = $o->post('marker_update', ['id' => $m1, 'name' => 'hijack', 'type' => 'gts']); T::eq(false, $j['success'] ?? null, 'foreign marker update fails');
[$s] = $c->post('marker_assign', ['marker_id' => $m1, 'location_id' => $L1]); T::eq(200, $s, 'assign');
[$s] = $c->post('marker_assign', ['marker_id' => $m1, 'location_id' => $L1]); T::eq(200, $s, 'assign twice is idempotent');
[$s] = $c->post('marker_assign', ['marker_id' => $m1, 'location_id' => $L2]); T::eq(403, $s, 'assign to foreign location → 403');
[$s, $j] = $c->get("markers&location_id=$L1"); T::eq(1, count($j), 'markers for location'); T::eq('GTS 250', $j[0]['name'], 'assigned marker');
[$s] = $c->post('marker_remove', ['marker_id' => $m1, 'location_id' => $L1]); T::eq(200, $s, 'remove');
[$s, $j] = $c->get("markers&location_id=$L1"); T::eq(0, count($j), 'no markers after remove');
[$s, $j] = $c->get("marker_predictions&location_id=$L1"); T::eq(200, $s, 'predictions'); T::ok(is_array($j), 'predictions array');
$p1 = array_values(array_filter($j, fn($p) => (int) $p['marker']['id'] === $m1));
T::eq(0, count($p1), 'pure GTS marker reached months ago is expired (not listed)');
[$s, $gtsRows] = $c->get("gts&location_id=$L1");
$plus4 = array_values(array_filter($gtsRows, fn($r) => $r['date'] === date('Y-m-d', strtotime("$today +4 days"))))[0];
[$s, $j] = $c->post('marker_update', ['id' => $m1, 'name' => 'Soon', 'type' => 'gts', 'threshold_value' => $plus4['gts'] - 0.001]);
[$s, $j] = $c->get("marker_predictions&location_id=$L1");
$p1 = array_values(array_filter($j, fn($p) => (int) $p['marker']['id'] === $m1));
T::ok(count($p1) === 1 && $p1[0]['status'] === 'upcoming' && $p1[0]['days_until'] === 4, 'marker reached in 4 days → upcoming, days_until 4');
[$s, $j] = $c->get("marker_rule_transitions&location_id=$L1"); T::eq(200, $s, 'rule transitions'); T::ok(is_array($j), 'transitions array');
[$s, $j] = $c->get("marker_evaluate&location_id=$L1&date=$today"); T::eq(200, $s, 'evaluate today'); T::ok(is_array($j), 'evaluate array');
[$s, $j] = $c->call("historical_stats&location_id=$L1", 'POST', ['type' => 'period_aggregate', 'period_type' => 'month', 'period_month' => 5, 'field' => 'precipitation', 'aggregation' => 'sum']);
T::eq(200, $s, 'historical_stats'); T::eq(METEO_HISTORY_YEARS, count($j['years'] ?? []), 'stats per year');
[$s] = $c->call("historical_stats&location_id=$L1", 'POST', []); T::eq(400, $s, 'historical_stats without body → 400');
[$s, $j] = $o->post('marker_delete', ['id' => $m1]); T::eq(false, $j['success'] ?? null, 'foreign marker delete fails');
[$s, $j] = $c->post('marker_delete', ['id' => $m2]); T::eq(true, $j['success'] ?? null, 'marker delete');

// ═══════════════════════════════════════════════════════════
T::section('API: records & settings');
[$s, $j] = $c->post('record_setting_add', ['category' => 'feed', 'name' => 'Sirup 3:2', 'short_code' => 'S32', 'unit' => 'kg', 'sugar_g' => 600, 'water_ml' => 400]);
T::eq(200, $s, 'setting add'); $set1 = (int) $j['id'];
[$s] = $c->post('record_setting_add', ['category' => 'bogus', 'name' => 'x']); T::eq(400, $s, 'invalid category → 400');
[$s] = $c->post('record_setting_add', ['category' => 'harvest']); T::eq(400, $s, 'missing name → 400');
[$s, $j] = $c->get('record_settings_get'); T::eq(1, count($j['settings']), 'one setting'); T::eq(600, (int) $j['settings'][0]['sugar_g'], 'sugar stored');
[$s, $j] = $o->get('record_settings_get'); T::eq(0, count($j['settings']), 'settings are per user');
[$s, $j] = $c->post('record_add', ['records' => [
    ['hive_id' => $h1, 'record_date' => '2026-07-01', 'type' => 'harvest', 'amount' => '12.5', 'unit' => 'kg', 'notes' => 'Frühtracht'],
    ['hive_id' => $h1, 'record_date' => '2026-07-02', 'type' => 'feed', 'setting_id' => $set1, 'amount' => 3],
    ['hive_id' => 999999, 'record_date' => '2026-07-03', 'type' => 'feed'],          // foreign/unknown hive
    ['hive_id' => $h1, 'record_date' => '2026-02-30', 'type' => 'varroa'],           // invalid date
    ['hive_id' => $h1, 'record_date' => '2026-07-04', 'type' => 'unknown_type'],     // invalid type
    'not-an-object',
]]);
T::eq(200, $s, 'bulk record_add'); T::eq(2, count($j['ids'] ?? []), '2 inserted'); T::eq(4, $j['skipped'] ?? null, '4 skipped');
$r1 = (int) $j['ids'][0];
[$s, $j] = $c->post('record_add', ['hive_id' => $h1, 'record_date' => '2026-07-05', 'type' => 'status', 'notes' => '[Stockkarte] KL:X']); T::eq(1, count($j['ids']), 'single record form');
[$s, $j] = $c->get("records_get&hive_id=$h1"); T::eq(3, count($j['records']), 'records for hive'); T::eq('2026-07-05', $j['records'][0]['record_date'], 'newest first');
[$s, $j] = $c->get("records_get&type=feed"); T::eq(1, count($j['records']), 'type filter'); T::eq('Sirup 3:2', $j['records'][0]['setting_name'], 'setting joined');
[$s, $j] = $c->get("records_get&location_id=$L1"); T::eq(3, count($j['records']), 'location filter');
[$s, $j] = $c->get("records_get&location_id=$L3"); T::eq(0, count($j['records']), 'other location has none');
[$s, $j] = $c->get("records_get&export=1"); T::eq(3, count($j['records']), 'export returns all');
[$s] = $o->get("records_get&hive_id=$h1"); T::eq(403, $s, 'foreign hive records → 403');
[$s, $j] = $o->get('records_get'); T::eq(0, count($j['records']), 'other user sees no records');
[$s] = $c->post('record_edit', ['id' => $r1, 'record_date' => '2026-13-01']); T::eq(400, $s, 'edit with invalid date → 400');
[$s] = $c->post('record_edit', ['id' => $r1, 'record_date' => '2026-07-10', 'amount' => '14', 'unit' => 'kg', 'notes' => 'edited']); T::eq(200, $s, 'edit ok');
[$s, $j] = $c->get("records_get&hive_id=$h1"); $edited = array_values(array_filter($j['records'], fn($r) => (int) $r['id'] === $r1))[0];
T::eq('2026-07-10', $edited['record_date'], 'date edited'); T::eq('edited', $edited['notes'], 'notes edited');
[$s] = $o->post('record_edit', ['id' => $r1, 'record_date' => '2026-07-11']); T::eq(403, $s, 'foreign record edit → 403');
[$s] = $o->post('record_delete', ['id' => $r1]); T::eq(403, $s, 'foreign record delete → 403');
[$s] = $c->post('record_delete', ['id' => $r1]); T::eq(200, $s, 'record delete');
[$s, $j] = $c->post('record_setting_delete', ['id' => $set1]); T::eq(true, $j['success'], 'setting delete');
[$s, $j] = $c->get("records_get&type=feed"); T::eq(1, count($j['records']), 'record survives setting delete');
T::ok(array_key_exists('setting_id', $j['records'][0]) && $j['records'][0]['setting_id'] === null, 'setting_id becomes NULL (ON DELETE SET NULL)');
[$s, $j] = $c->get('ocr_jobs_get'); T::eq(200, $s, 'ocr_jobs_get'); T::eq([], $j['jobs'], 'no jobs');
[$s] = $c->call('record_upload_ocr', 'POST', []); T::eq(400, $s, 'upload without file → 400');
$tmpTxt = sys_get_temp_dir() . '/forecasting-test-notimage.txt';
file_put_contents($tmpTxt, 'this is not an image');
[$s] = $c->call('record_upload_ocr', 'POST', [], true, ['ocrUpload' => $tmpTxt]); T::eq(400, $s, 'non-image upload rejected');
@unlink($tmpTxt);

// ═══════════════════════════════════════════════════════════
T::section('API: cascade delete & logout');
[$s, $j] = $c->post('location_delete', ['id' => $newLoc]); T::eq(true, $j['success'], 'delete empty location');
[$s] = $c->post('hive_create', ['location_id' => $newLoc]); T::eq(403, $s, 'deleted location not usable');
[$s, $j] = $c->post('location_delete', ['id' => $L3]); T::eq(true, $j['success'], 'delete location with hive');
[$s, $j] = $c->get('hives_count_all'); T::eq(1, $j['count'], 'hive at deleted location cascaded');
T::eq('0', (string) $db->query("SELECT COUNT(*) FROM weather_history WHERE location_id = $L3")->fetchColumn(), 'weather cascaded');
[$s] = $c->post('logout'); T::eq(200, $s, 'logout');
[$s] = $c->get('locations'); T::eq(401, $s, 'session gone after logout');

proc_terminate($server);
exit(T::summary() > 0 ? 1 : 0);
