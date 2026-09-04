/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// LOGIN / LOGOUT / APP-INITIALISIERUNG
// ══════════════════════════════════════════════════════

function showAppShell(username) {
    document.getElementById('login-screen').style.display = 'none';
    document.getElementById('app-shell').style.display = 'block';
    const userDisplay = document.getElementById('user-display');
    if (userDisplay) userDisplay.textContent = username || '';
}

window.handleLogin = async function(e) {
    e.preventDefault();
    const username = document.getElementById('login-user').value.trim();
    const password = document.getElementById('login-pass').value;
    const btn = document.getElementById('login-btn');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + window.t('Anmelden...');

    let loginSuccess = false;
    let usernameResult = '';

    try {
        const result = await window.apiFetch('login', 'POST', { username, password });
        if (result.success) {
            loginSuccess = true;
            usernameResult = result.username;
            window.setCsrfToken(result.csrf_token);
        }
    } catch (err) {
        console.error('LOGIN ERROR: ', err);
        window.toast(err.message || window.t('Ungültige Zugangsdaten'), 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🔑 <span data-i18n="Anmelden">' + window.t('Anmelden') + '</span>';
    }

    if (loginSuccess) {
        document.getElementById('login-pass').value = '';
        showAppShell(usernameResult);
        try {
            await window.initApp();
        } catch (initErr) {
            console.error('Error during app initialization:', initErr);
            window.toast(window.t('Fehler beim Initialisieren der App'), 'error');
        }
    }
};

window.handleLogout = async function() {
    try {
        await window.apiFetch('logout', 'POST');
    } catch (err) {
        console.error('Logout-Fehler:', err);
    }
    location.reload();
};

window.checkAuth = async function() {
    let loggedIn = false;
    let username = '';
    try {
        const result = await window.apiFetch('check_auth');
        if (result.logged_in) {
            loggedIn = true;
            username = result.username;
            // Token der laufenden Session übernehmen (z.B. nach Reload)
            window.setCsrfToken(result.csrf_token);
        }
    } catch (_) { /* nicht eingeloggt */ }

    if (loggedIn) {
        showAppShell(username);
        try {
            await window.initApp();
        } catch (initErr) {
            console.error('Error during app init:', initErr);
        }
    }
};

window.initApp = async function() {
    if (window.loadLocations) await window.loadLocations();
    if (window.loadMarkers) await window.loadMarkers();

    const locationId = window.state.selectedLocationId;
    if (locationId) {
        // Gleicher Ablauf wie onLocationChange: zuerst aus der DB rendern,
        // der Open-Meteo-Sync läuft danach im Hintergrund und zeichnet nur
        // bei echten Änderungen neu. So blockiert der Start nicht auf dem Netz.
        const token = ++window.locationChangeToken;

        const loaderPromises = [];
        if (window.loadGTSData) loaderPromises.push(window.loadGTSData());
        if (window.loadPredictions) loaderPromises.push(window.loadPredictions());
        if (window.loadClimateNormals) loaderPromises.push(window.loadClimateNormals());
        if (window.loadLocationNotes) loaderPromises.push(window.loadLocationNotes());
        if (window.loadHives) loaderPromises.push(window.loadHives());
        await Promise.all(loaderPromises);

        if (window.renderCalendar) window.renderCalendar();
        if (window.loadAllLocationsOverview) window.loadAllLocationsOverview();

        window.syncLocationInBackground(locationId, token);
        return;
    }
    if (window.renderCalendar) window.renderCalendar();
    if (window.loadAllLocationsOverview) window.loadAllLocationsOverview();
};

window.ensureDailySync = async function(locationId) {
    try {
        return await window.apiFetch('location_sync', 'POST', { id: locationId });
    } catch (err) {
        console.error('Täglicher Sync-Fehler:', err);
        return null;
    }
};

/**
 * Prüft, ob der Sync (location_sync) tatsächlich neue Zeilen geschrieben hat.
 * Die Antwort hat die Form { success, details: { history, forecast, normals, updated, ... } }.
 */
window.syncResultHasChanges = function(syncResult) {
    const details = syncResult && typeof syncResult === 'object' ? (syncResult.details || syncResult) : null;
    if (!details || typeof details !== 'object') return false;
    if (typeof details.updated === 'boolean') return details.updated;
    return Object.keys(details).some(key => key !== 'success' && typeof details[key] === 'number' && details[key] > 0);
};

/**
 * Sync im Hintergrund; nur bei echten Änderungen die Panels neu laden und zeichnen.
 * token = window.locationChangeToken zum Zeitpunkt des Aufrufs, damit ein
 * zwischenzeitlicher Standortwechsel das Nachladen verwirft.
 */
window.syncLocationInBackground = function(locationId, token) {
    const stillCurrent = () => token === window.locationChangeToken && window.state.selectedLocationId === locationId;

    return window.ensureDailySync(locationId)
        .then(async (syncResult) => {
            if (!stillCurrent()) return;
            if (!window.syncResultHasChanges(syncResult)) return;

            await Promise.all([
                window.loadGTSData ? window.loadGTSData() : null,
                window.loadPredictions ? window.loadPredictions() : null,
                window.loadClimateNormals ? window.loadClimateNormals() : null,
            ]);
            if (!stillCurrent()) return;

            if (document.getElementById('tab-kalender')?.classList.contains('active')) {
                window.dispatchEvent(new Event('reload-historie'));
            }
            if (window.renderCalendar) window.renderCalendar();
            if (window.loadAllLocationsOverview) window.loadAllLocationsOverview();
        })
        .catch(err => {
            console.error('Hintergrund-Sync-Fehler:', err);
        });
};

window.loadClimateNormals = async function() {
    if (!window.state.selectedLocationId) return;
    try {
        const data = await window.apiFetch(`climate_normals&location_id=${window.state.selectedLocationId}`);
        window.state.climateNormals = (data && typeof data === 'object' && !Array.isArray(data)) ? data : {};
    } catch (err) {
        console.error('Klimanormale-Fehler:', err);
        window.state.climateNormals = {};
    }
};
