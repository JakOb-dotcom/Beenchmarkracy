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
 * Test runner:  php tests/run.php [unit|api]
 *
 * Creates a temporary database (forecasting_test_<random>) from database/schema.sql,
 * runs the unit tests and the HTTP API tests in separate PHP processes, drops the
 * database again and exits non-zero if anything failed.
 *
 * Requirements: MySQL/MariaDB reachable with the credentials from config.local.php
 * (or TEST_DB_HOST/PORT/USER/PASS), the pdo_mysql and curl extensions, and the
 * right to CREATE/DROP databases. No network access is needed.
 */

declare(strict_types=1);

$dbName = 'forecasting_test_' . substr(md5((string) microtime(true)), 0, 8);
putenv("TEST_DB_NAME=$dbName");
require_once __DIR__ . '/bootstrap.php';

$which = $argv[1] ?? 'all';
$suites = [];
if ($which === 'all' || $which === 'unit') $suites[] = 'unit_tests.php';
if ($which === 'all' || $which === 'api')  $suites[] = 'api_tests.php';

echo "Creating test database $dbName ...\n";
test_create_database();

$failed = 0;
try {
    foreach ($suites as $suite) {
        echo "\n" . str_repeat('#', 60) . "\n# $suite\n" . str_repeat('#', 60) . "\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $suite);
        passthru($cmd, $code);
        if ($code !== 0) {
            $failed++;
        }
    }
} finally {
    echo "\nDropping test database ...\n";
    test_drop_database();
}

echo $failed === 0 ? "\nALL SUITES PASSED\n" : "\n$failed SUITE(S) FAILED\n";
exit($failed === 0 ? 0 : 1);
