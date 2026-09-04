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

/**
 * Kapselt den eingehenden HTTP-Request (Action, Methode, Query, JSON-/Form-Body, Header).
 */
class Request {
    public string $action;
    public string $method;
    /** Query-Parameter ($_GET) */
    public array $query;
    /** JSON-Body oder Formulardaten ($_POST) */
    public array $input;
    public array $headers;

    public function __construct() {
        $this->action  = (string) ($_GET['action'] ?? '');
        $this->method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->query   = $_GET;
        $this->input   = $this->readJsonInput();
        $this->headers = $this->getAllHeaders();
    }

    private function readJsonInput(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $_POST ?: [];
        }
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            if (str_contains($contentType, 'application/json')) {
                // Explizit als JSON deklariert, aber nicht parsebar: nicht stillschweigend ignorieren
                Response::error('Ungültiger JSON-Body: ' . json_last_error_msg(), 400);
            }
            return $_POST ?: [];
        }
        return $decoded;
    }

    private function getAllHeaders(): array {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) {
                return $h;
            }
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    public function getHeader(string $name): ?string {
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return (string) $value;
            }
        }
        return null;
    }

    /** Query-Parameter als Integer (0 wenn fehlend/ungültig). */
    public function queryInt(string $key, int $default = 0): int {
        $v = $this->query[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** Query-Parameter als getrimmter String. */
    public function queryString(string $key, string $default = ''): string {
        $v = $this->query[$key] ?? null;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /** Body-Feld als Integer (0 wenn fehlend/ungültig). */
    public function inputInt(string $key, int $default = 0): int {
        $v = $this->input[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** Body-Feld als getrimmter String. */
    public function inputString(string $key, string $default = ''): string {
        $v = $this->input[$key] ?? null;
        return is_scalar($v) ? trim((string) $v) : $default;
    }
}
