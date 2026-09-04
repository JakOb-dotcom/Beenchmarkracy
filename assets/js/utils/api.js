/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// API-CLIENT
// Alle Aufrufe gehen über api/index.php?action=<name>.
// Nicht-GET-Requests tragen automatisch den CSRF-Token.
// ══════════════════════════════════════════════════════

window.API_URL = 'api/index.php';

window.getCsrfToken = function() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
};

window.setCsrfToken = function(token) {
    if (!token) return;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
};

// Session abgelaufen → zurück zum Login (Seite neu laden zeigt den Login-Screen)
function handleUnauthorized(action) {
    if (action === 'login' || action === 'check_auth' || action === 'logout') return;
    if (window.__sessionExpiredHandled) return;
    window.__sessionExpiredHandled = true;
    if (window.toast) window.toast(window.t ? window.t('Sitzung abgelaufen. Bitte erneut anmelden.') : 'Sitzung abgelaufen. Bitte erneut anmelden.', 'warning');
    setTimeout(() => location.reload(), 1500);
}

async function parseResponse(res, action) {
    const rawText = await res.text();
    let data;
    try {
        data = JSON.parse(rawText);
    } catch (e) {
        console.error('API response is not valid JSON. Raw response:', rawText.substring(0, 500));
        throw new Error(window.t ? window.t('Ungültige Server-Antwort (kein JSON). Bitte Seite neu laden.') : 'Ungültige Server-Antwort (kein JSON). Bitte Seite neu laden.');
    }

    if (res.status === 401) handleUnauthorized(action);
    if (!res.ok) throw new Error((data && data.error) ? data.error : 'API-Fehler');
    return data;
}

/**
 * JSON-API-Aufruf.
 * @param {string} action   Action-Name, optional mit weiteren Query-Parametern ("gts&location_id=1")
 * @param {string} method   GET | POST
 * @param {object} body     JSON-Body (nur POST)
 */
window.apiFetch = async function(action, method = 'GET', body = null) {
    const url = `${window.API_URL}?action=${action}`;
    const opts = { method, headers: {}, credentials: 'same-origin' };

    if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    if (method !== 'GET') {
        opts.headers['X-CSRF-TOKEN'] = window.getCsrfToken();
    }

    const res = await fetch(url, opts);
    return parseResponse(res, action.split('&')[0]);
};

/**
 * Multipart-Upload (Dateien) an eine Action.
 * @param {string} action
 * @param {FormData} formData
 */
window.apiUpload = async function(action, formData) {
    const res = await fetch(`${window.API_URL}?action=${action}`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': window.getCsrfToken() },
        body: formData,
        credentials: 'same-origin'
    });
    return parseResponse(res, action);
};
