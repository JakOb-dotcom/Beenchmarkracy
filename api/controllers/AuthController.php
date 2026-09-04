<?php
/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use Auth;
use LoginThrottle;

class AuthController {

    public function login(Request $request): void {
        $username = $request->inputString('username');
        $password = (string) ($request->input['password'] ?? '');

        if ($username === '' || $password === '' || mb_strlen($username) > 100) {
            Response::error('Ungültige Zugangsdaten', 401);
        }

        $ip = LoginThrottle::clientIp();
        $locked = LoginThrottle::lockedForSeconds($ip);
        if ($locked > 0) {
            Response::error('Zu viele Fehlversuche. Bitte in ' . (int) ceil($locked / 60) . ' Minuten erneut versuchen.', 429);
        }

        $user = Auth::attempt($username, $password);
        if (!$user) {
            LoginThrottle::recordFailure($ip, $username);
            Response::error('Ungültige Zugangsdaten', 401);
        }

        LoginThrottle::clear($ip);
        Auth::login($user);
        Response::json([
            'success'              => true,
            'username'             => $user['username'],
            'csrf_token'           => $_SESSION['csrf_token'],
            'needs_bootstrap_sync' => !empty($_SESSION['needs_bootstrap_sync']),
        ]);
    }

    public function logout(Request $request): void {
        Auth::logout();
        Response::json(['success' => true]);
    }

    public function check_auth(Request $request): void {
        Response::json([
            'logged_in'            => Auth::isLoggedIn(),
            'username'             => Auth::isLoggedIn() ? ($_SESSION['username'] ?? null) : null,
            'csrf_token'           => Auth::isLoggedIn() ? ($_SESSION['csrf_token'] ?? null) : null,
            'needs_bootstrap_sync' => !empty($_SESSION['needs_bootstrap_sync']),
        ]);
    }

    public function bootstrapSync(Request $request): void {
        if (empty($_SESSION['needs_bootstrap_sync'])) {
            Response::json(['success' => true, 'skipped' => true, 'location_ids' => []]);
        }

        $userId = Auth::userId();
        $locationIds = array_values(array_map(
            static fn(array $location): int => (int) ($location['id'] ?? 0),
            array_filter(\LocationService::getAllByUser($userId), static fn(array $location): bool => (int) ($location['id'] ?? 0) > 0)
        ));

        Response::json(['success' => true, 'skipped' => false, 'location_ids' => $locationIds]);
    }

    public function bootstrapSyncComplete(Request $request): void {
        $_SESSION['needs_bootstrap_sync'] = false;
        Response::json(['success' => true]);
    }
}
