/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// LOCATIONS
// ══════════════════════════════════════════════════════

async function loadLocations() {
    state.locations = await apiFetch('locations');

    const locationIds = new Set(state.locations.map(loc => Number(loc.id)));
    selectedLocationIds = new Set([...selectedLocationIds].filter(id => locationIds.has(Number(id))));

    if (state.selectedLocationId && !locationIds.has(Number(state.selectedLocationId))) {
        state.selectedLocationId = null;
    }

    renderLocationList();
    renderLocationSelectors();
    updateLocationBulkDeleteButton();

    if (state.locations.length > 0 && !state.selectedLocationId) {
        state.selectedLocationId = state.locations[0].id;
        updateLocationSelectors();
    }

    const selectAllEl = document.getElementById('select-all-locations');
    if (selectAllEl) {
        selectAllEl.checked = state.locations.length > 0 && state.locations.every(loc => selectedLocationIds.has(Number(loc.id)));
    }

    if (typeof window.loadAllLocationsOverview === 'function') {
        window.loadAllLocationsOverview();
    }
}

function renderLocationList() {
    const tbody = document.getElementById('location-tbody');
    if (!tbody) return;

    if (state.locations.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">
            ${t('Noch keine Standorte angelegt. Füge deinen ersten Bienenstandort hinzu!')}</td></tr>`;
        return;
    }

    tbody.innerHTML = state.locations.map(loc => `
        <tr>
            <td>
                <input type="checkbox" class="location-select-checkbox" data-id="${loc.id}" ${selectedLocationIds.has(Number(loc.id)) ? 'checked' : ''}
                    onchange="toggleLocationSelection(${loc.id}, this.checked)">
            </td>
            <td><strong>${esc(loc.name)}</strong></td>
            <td>${loc.latitude}</td>
            <td>${loc.longitude}</td>
            <td>${loc.altitude || '-'}</td>
            <td>
                <button class="btn-icon" onclick="refreshLocation(${loc.id})" title="${t('Wetterdaten neu laden')}">🔄</button>
                <button class="btn-icon danger" onclick="deleteLocation(${loc.id})" title="${t('Löschen')}">🗑️</button>
            </td>
        </tr>
    `).join('');
}

function toggleLocationSelection(id, checked) {
    const numericId = Number(id);
    if (checked) selectedLocationIds.add(numericId);
    else selectedLocationIds.delete(numericId);

    const allChecked = state.locations.length > 0 && state.locations.every(loc => selectedLocationIds.has(Number(loc.id)));
    const selectAllEl = document.getElementById('select-all-locations');
    if (selectAllEl) selectAllEl.checked = allChecked;

    updateLocationBulkDeleteButton();
}

function toggleAllLocationSelection(checked) {
    if (checked) {
        selectedLocationIds = new Set(state.locations.map(loc => Number(loc.id)));
    } else {
        selectedLocationIds.clear();
    }

    document.querySelectorAll('.location-select-checkbox').forEach(cb => {
        cb.checked = checked;
    });

    updateLocationBulkDeleteButton();
}

function updateLocationBulkDeleteButton() {
    const btn = document.getElementById('delete-selected-locations-btn');
    if (!btn) return;

    const count = selectedLocationIds.size;
    btn.disabled = count === 0;
    btn.textContent = '🗑️ ' + t('Ausgewählte löschen') + (count > 0 ? ` (${count})` : '');
}

async function deleteSelectedLocations() {
    if (selectedLocationIds.size === 0) return;

    const ids = [...selectedLocationIds];
    if (!confirm(`${ids.length} ${t('ausgewählte Standorte wirklich löschen? Alle Wetterdaten werden ebenfalls gelöscht.')}`)) return;

    const deletedCurrent = state.selectedLocationId && ids.includes(Number(state.selectedLocationId));

    showLoading(t('Lösche ausgewählte Standorte...'));
    try {
        for (const id of ids) {
            await apiFetch('location_delete', 'POST', { id });
        }

        selectedLocationIds.clear();
        await loadLocations();

        if (deletedCurrent && state.locations.length > 0) {
            await onLocationChange(state.locations[0].id);
        }

        toast(`${ids.length} ${t('Standorte gelöscht')}`, 'success');
    } catch (err) {
        toast(t('Fehler beim Löschen:') + ' ' + err.message, 'error');
    } finally {
        hideLoading();
        updateLocationBulkDeleteButton();
    }
}

async function deleteCurrentLocationFromDashboard() {
    if (!state.selectedLocationId) return;
    await deleteLocation(state.selectedLocationId);
}

function renderLocationSelectors() {
    document.querySelectorAll('.loc-select').forEach(sel => {
        const val = sel.value;
        sel.innerHTML = state.locations.map(l =>
            `<option value="${l.id}">${esc(l.name)}</option>`
        ).join('');
        if (val) sel.value = val;
    });

    const zuchtSelect = document.getElementById('zuchtwerte-loc-select');
    if (zuchtSelect) {
        const currentVal = zuchtSelect.value;
        zuchtSelect.innerHTML = `<option value="all">${t('Alle Standorte')}</option>` + state.locations.map(l =>
            `<option value="${l.id}">${esc(l.name)}</option>`
        ).join('');
        if (currentVal && (currentVal === 'all' || state.locations.find(l => String(l.id) === String(currentVal)))) {
            zuchtSelect.value = currentVal;
        }
    }
}

function updateLocationSelectors() {
    document.querySelectorAll('.loc-select').forEach(sel => {
        sel.value = state.selectedLocationId;
    });

    // Standort-ID auf dem Dashboard anzeigen
    const locIdValue = document.getElementById('dashboard-loc-id-value');
    if (locIdValue && state.selectedLocationId && state.locations) {
        const index = state.locations.findIndex(l => String(l.id) === String(state.selectedLocationId));
        locIdValue.textContent = index !== -1 ? String(index + 1) : '-';
    }
}

async function addLocation(e) {
    e.preventDefault();
    const form = e.target;
    const name = form.querySelector('[name="name"]').value.trim();
    const lat  = parseFloat(form.querySelector('[name="latitude"]').value);
    const lon  = parseFloat(form.querySelector('[name="longitude"]').value);
    const alt  = form.querySelector('[name="altitude"]').value;

    if (!name || isNaN(lat) || isNaN(lon)) {
        toast(t('Bitte alle Pflichtfelder ausfüllen'), 'error');
        return;
    }
    if (lat < -90 || lat > 90 || lon < -180 || lon > 180) {
        toast(t('Koordinaten außerhalb des gültigen Bereichs.'), 'error');
        return;
    }

    showLoading(t('Standort wird angelegt...'));

    try {
        const response = await apiFetch('locations', 'POST', {
            name, latitude: lat, longitude: lon,
            altitude: alt ? parseInt(alt) : null
        });

        toast(t('Standort erfolgreich angelegt! Lade nun die Historien-Daten in kleinen Schritten...'), 'success');
        form.reset();
        await loadLocations();

        const newSelectedId = response.id;
        state.selectedLocationId = newSelectedId;
        updateLocationSelectors();

        hideLoading();
        // refreshLocation lädt die komplette Historie in Jahres-Chunks
        // ohne Timeout-Probleme. askConfirm=false, da neuer Standort.
        await refreshLocation(newSelectedId, false);

        // Anschließend die normalen Daten laden
        await Promise.all([loadGTSData(), loadPredictions(), loadClimateNormals(), loadLocationNotes(), loadHives()]);
        renderCalendar();

    } catch (err) {
        hideLoading();
        toast(t('Fehler:') + ' ' + err.message, 'error');
    }
}

async function deleteLocation(id) {
    if (!confirm(t('Standort wirklich löschen? Alle Wetterdaten werden ebenfalls gelöscht.'))) return;

    const numericId = Number(id);
    const deletedCurrent = Number(state.selectedLocationId) === numericId;

    try {
        await apiFetch('location_delete', 'POST', { id: numericId });
        toast(t('Standort gelöscht'), 'success');

        selectedLocationIds.delete(numericId);
        await loadLocations();

        if (deletedCurrent) {
            if (state.locations.length > 0) {
                await onLocationChange(state.locations[0].id);
            } else {
                state.selectedLocationId = null;
                updateLocationSelectors();
                state.gtsData = [];
                state.predictions = [];
                state.climateNormals = {};
                renderCalendar();
                renderPredictionsPanel();
            }
        }

        if (typeof window.loadAllLocationsOverview === 'function') { window.loadAllLocationsOverview(); }
    } catch (err) {
        toast(t('Fehler:') + ' ' + err.message, 'error');
    }
}

async function refreshLocation(id, askConfirm = true) {
    if (askConfirm && !confirm(t('Alle Wetterdaten für diesen Standort neu von Open-Meteo laden?') + '\n' + t('Das überschreibt vorhandene Daten und kann einige Minuten dauern.'))) return;

    try {
        showLoading(t('Lade Refresh-Plan...'), 0);
        const plan = await apiFetch('location_refresh', 'POST', { id, mode: 'plan' });

        const years = Array.isArray(plan.years) ? plan.years : [];
        // Klimanormale in Jahresblöcken (je ein Request), damit kein Request das PHP-Zeitlimit reißt
        const normalParts = Array.isArray(plan.normal_parts) && plan.normal_parts.length > 0 ? plan.normal_parts : [null];
        let totalHistory = 0;
        let totalSteps = years.length + 1 + normalParts.length;
        let currentStep = 0;
        const failedYears = [];

        for (let i = 0; i < years.length; i++) {
            const year = years[i];
            let progress = (currentStep / totalSteps) * 100;
            showLoading(`${t('Lade Historie')} (${i+1}/${years.length}): ${t('Jahr')} ${year}...`, progress);

            try {
                const chunkRes = await apiFetch('location_refresh', 'POST', { id, mode: 'history_chunk', year });
                totalHistory += (chunkRes.inserted || 0);
                // Ein abgeschlossenes Jahr ohne eine einzige Zeile = Open-Meteo hat nicht geliefert
                if (!(chunkRes.inserted > 0) && year < state.currentYear) failedYears.push(year);
            } catch (err) {
                console.error('Historie-Jahr fehlgeschlagen:', year, err);
                failedYears.push(year);
            }
            currentStep++;
        }

        let progressFc = (currentStep / totalSteps) * 100;
        showLoading(t('Lade aktuelle Vorhersagedaten...'), progressFc);
        const fcRes = await apiFetch('location_refresh', 'POST', { id, mode: 'forecast' });
        currentStep++;

        let normInserted = 0;
        let normFailed = false;
        for (let p = 0; p < normalParts.length; p++) {
            const range = normalParts[p];
            let progressNorm = (currentStep / totalSteps) * 100;
            const label = range ? ` (${range[0]}–${range[1]})` : '';
            showLoading(t('Lade Klima-Referenzdaten...') + label, progressNorm);
            const body = range ? { id, mode: 'normals', part: p + 1 } : { id, mode: 'normals' };
            const normRes = await apiFetch('location_refresh', 'POST', body);
            normInserted += (normRes.inserted || 0);
            if (normRes.ok === false) normFailed = true;
            currentStep++;
        }

        showLoading(t('Fast fertig...'), 100);

        toast(`${t('Daten aktualisiert:')} ${totalHistory} ${t('Historie')}, ${fcRes.inserted || 0} ${t('Vorhersage')}, ${normInserted} ${t('Normale')}`, 'success');
        if (failedYears.length > 0 || normFailed) {
            const parts = [];
            if (failedYears.length > 0) parts.push(`${t('Historie')}: ${failedYears.join(', ')}`);
            if (normFailed) parts.push(t('Klima-Referenzdaten'));
            toast(`${t('Nicht vollständig geladen (Open-Meteo nicht erreichbar):')} ${parts.join(' · ')} – ${t('später über 🔄 erneut laden.')}`, 'warning');
        }

        // GTS-Daten und Klimanormale neu laden falls dieser Standort ausgewählt ist
        if (state.selectedLocationId === id) {
            await ensureDailySync(id);
            await loadGTSData();
            await loadClimateNormals();
            await loadPredictions();
            window.dispatchEvent(new Event('reload-historie'));
            renderCalendar();
        }
        // Übersichtskarten (GTS je Standort) mit den frischen Daten neu aufbauen
        if (typeof window.loadAllLocationsOverview === 'function') window.loadAllLocationsOverview();
    } catch (err) {
        toast(t('Fehler beim Aktualisieren:') + ' ' + err.message, 'error');
    } finally {
        hideLoading();
    }
}

async function onLocationChange(locId) {
    const selectedId = parseInt(locId, 10);
    const token = ++locationChangeToken;

    state.selectedLocationId = selectedId;
    updateLocationSelectors();

    // Sofortiges Re-Rendern für direkte UI-Reaktion.
    renderCalendar();

    // Erst die wichtigsten Panels laden.
    await Promise.all([
        loadGTSData(),
        loadPredictions(),
        loadClimateNormals()
    ]);
    const kalenderTabActive = document.getElementById('tab-kalender')?.classList.contains('active');
    if (kalenderTabActive) window.dispatchEvent(new Event('reload-historie'));

    if (token !== locationChangeToken || state.selectedLocationId !== selectedId) return;

    renderCalendar();

    // Sekundäre Panels ohne Blockierung nachladen.
    Promise.all([
        loadLocationNotes(),
        loadHives(),
    ]).catch(err => {
        console.error('Sekundäres Nachladen fehlgeschlagen:', err);
    });

    // Sync im Hintergrund; nur bei echten Änderungen erneut laden.
    window.syncLocationInBackground(selectedId, token);
}
