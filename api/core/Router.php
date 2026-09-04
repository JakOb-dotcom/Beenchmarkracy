<?php
/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

namespace Api\Core;

use Auth;

/**
 * Minimaler Action-Router: ?action=<name> → Handler.
 *
 * Pro Route: Auth-Pflicht, CSRF-Pflicht (für alle Nicht-GET-Requests) und
 * optional eine Beschränkung auf bestimmte HTTP-Methoden.
 */
class Router {
    private array $routes = [];

    /**
     * @param string[]|null $methods  z.B. ['POST']; null = alle Methoden erlaubt
     */
    public function register(
        string $action,
        callable|array $handler,
        bool $requiresAuth = true,
        bool $requiresCsrf = true,
        ?array $methods = null
    ): void {
        $this->routes[$action] = [
            'handler'      => $handler,
            'requiresAuth' => $requiresAuth,
            'requiresCsrf' => $requiresCsrf,
            'methods'      => $methods,
        ];
    }

    public function dispatch(Request $request): void {
        $action = $request->action;

        if (!isset($this->routes[$action])) {
            Response::error('Action not found', 404);
        }

        $route = $this->routes[$action];

        // 1. Methode
        if ($route['methods'] !== null && !in_array($request->method, $route['methods'], true)) {
            Response::error('Method not allowed', 405);
        }

        // 2. Auth
        if ($route['requiresAuth'] && !Auth::isLoggedIn()) {
            Response::error('Nicht authentifiziert', 401);
        }

        // 3. CSRF für alle zustandsändernden (Nicht-GET) Requests
        if ($route['requiresCsrf'] && $request->method !== 'GET') {
            $token = $request->getHeader('X-CSRF-TOKEN') ?? (string) ($request->input['csrf_token'] ?? '');
            if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
                Response::error('Ungültiger oder fehlender CSRF-Token. Bitte Seite neu laden.', 403);
            }
        }

        // 4. Ausführen
        try {
            call_user_func($route['handler'], $request);
        } catch (\Throwable $e) {
            error_log(sprintf('API Error in action %s: %s in %s:%d', $action, $e->getMessage(), $e->getFile(), $e->getLine()));
            $message = APP_DEBUG ? ('Interner Serverfehler: ' . $e->getMessage()) : 'Interner Serverfehler';
            Response::error($message, 500);
        }
    }
}
