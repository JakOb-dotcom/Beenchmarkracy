/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// MARKERS
// ══════════════════════════════════════════════════════

async function loadMarkers() {
    state.markers = await apiFetch('markers');
    renderMarkerList();
    if (typeof window.loadAllLocationsOverview === 'function') { window.loadAllLocationsOverview(); }
}

function markerTypeLabels() {
    return {
        gts: t('GTS-Schwellwert'),
        temperature_deviation: t('Temp.-Abweichung'),
        precipitation_deviation: t('Niederschl.-Abweichung'),
        complex: t('Komplex (Regeln)'),
        custom: t('Benutzerdefiniert')
    };
}

function renderMarkerList() {
    const tbody = document.getElementById('marker-tbody');
    if (!tbody) return;

    if (state.markers.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">
            ${t('Noch keine Marker angelegt.')}</td></tr>`;
        return;
    }

    const typeLabels = markerTypeLabels();
    const sevIcons = { info: 'ℹ️', warning: '⚠️', critical: '🚨' };

    tbody.innerHTML = state.markers.map(m => {
        const rulesCount = m.rules?.conditions?.length || 0;
        const rulesInfo = m.type === 'complex' && rulesCount > 0
            ? `<br><span style="font-size:.7rem;color:var(--text-muted)">${rulesCount} ${rulesCount > 1 ? t('Bedingungen') : t('Bedingung')} (${m.rules.logic || 'AND'})</span>`
            : '';

        const editBtn = m.type !== 'complex'
            ? `<button class="btn-icon" onclick="editSimpleMarker(${m.id})" title="${t('Bearbeiten')}">✏️</button>`
            : '';

        return `
        <tr>
            <td>
                <span class="marker-chip" style="background:${m.color}33;color:${m.color}">
                    <span class="dot" style="background:${m.color}"></span>
                    ${esc(m.name)}
                </span>
            </td>
            <td>${typeLabels[m.type] || esc(m.type)}${rulesInfo}</td>
            <td>${m.threshold_value ?? '–'}</td>
            <td>${sevIcons[m.severity] || 'ℹ️'} ${esc(m.severity || 'info')}</td>
            <td style="max-width:250px;font-size:.82rem;">${esc(m.alert_message || m.description || '–')}</td>
            <td>
                ${editBtn}
                <button class="btn-icon" onclick="viewMarkerRules(${m.id})" title="${t('Regeln ansehen')}">📋</button>
                <button class="btn-icon danger" onclick="deleteMarker(${m.id})" title="${t('Löschen')}">🗑️</button>
            </td>
        </tr>`;
    }).join('');
}

// Einfaches Marker-Formular in den Bearbeitungsmodus versetzen
function editSimpleMarker(id) {
    const m = state.markers.find(x => x.id == id);
    if (!m) return;

    const form = document.querySelector('#tab-marker form');
    if (!form) return;

    form.querySelector('[name="id"]').value = m.id;
    form.querySelector('[name="name"]').value = m.name || '';
    form.querySelector('[name="type"]').value = m.type || 'gts';
    form.querySelector('[name="threshold_value"]').value = m.threshold_value ?? '';
    form.querySelector('[name="color"]').value = m.color || '#4caf50';
    form.querySelector('[name="description"]').value = m.description || '';

    const hint = document.getElementById('simple-marker-edit-hint');
    if (hint) {
        hint.style.display = 'block';
        hint.textContent = `${t('Bearbeite Marker:')} ${m.name}`;
    }
    const cancelBtn = document.getElementById('simple-marker-cancel-btn');
    if (cancelBtn) cancelBtn.style.display = 'inline-block';
    const submitBtn = document.getElementById('simple-marker-submit-btn');
    if (submitBtn) submitBtn.innerHTML = '💾 ' + t('Änderungen speichern');

    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function resetSimpleMarkerForm() {
    const form = document.querySelector('#tab-marker form');
    if (!form) return;
    form.reset();
    form.querySelector('[name="id"]').value = '';
    form.querySelector('[name="color"]').value = '#4caf50';

    const hint = document.getElementById('simple-marker-edit-hint');
    if (hint) { hint.style.display = 'none'; hint.textContent = ''; }
    const cancelBtn = document.getElementById('simple-marker-cancel-btn');
    if (cancelBtn) cancelBtn.style.display = 'none';
    const submitBtn = document.getElementById('simple-marker-submit-btn');
    if (submitBtn) submitBtn.innerHTML = '🏷️ <span data-i18n="Marker erstellen">' + t('Marker erstellen') + '</span>';
}

async function addMarker(e) {
    e.preventDefault();
    const form = e.target;
    const editId = form.querySelector('[name="id"]').value;

    const payload = {
        name: form.querySelector('[name="name"]').value.trim(),
        type: form.querySelector('[name="type"]').value,
        threshold_value: parseFloat(form.querySelector('[name="threshold_value"]').value) || null,
        color: form.querySelector('[name="color"]').value,
        description: form.querySelector('[name="description"]').value.trim() || null,
    };

    try {
        if (editId) {
            // Bestehende severity/alert_message/rules mitschicken, sonst
            // setzt der Update-Endpoint sie auf Default-Werte zurück.
            const existing = state.markers.find(m => m.id == editId) || {};
            await apiFetch('marker_update', 'POST', {
                id: Number(editId),
                ...payload,
                severity: existing.severity || 'info',
                alert_message: existing.alert_message || null,
                rules: existing.rules || null
            });
            toast(t('Marker aktualisiert!'), 'success');
        } else {
            await apiFetch('markers', 'POST', payload);
            toast(t('Marker erstellt!'), 'success');
        }
        resetSimpleMarkerForm();
        await loadMarkers();
        await loadPredictions();
        if (typeof window.loadAllLocationsOverview === 'function') { window.loadAllLocationsOverview(); }
        renderCalendar();
        renderGTSStats();
        renderGTSChart();
    } catch (err) {
        toast(t('Fehler:') + ' ' + err.message, 'error');
    }
}

async function deleteMarker(id) {
    if (!confirm(t('Marker wirklich löschen?'))) return;
    try {
        await apiFetch('marker_delete', 'POST', { id });
        toast(t('Marker gelöscht'), 'success');
        await loadMarkers();
        await loadPredictions();
        if (typeof window.loadAllLocationsOverview === 'function') { window.loadAllLocationsOverview(); }
        renderCalendar();
    } catch (err) {
        toast(t('Fehler:') + ' ' + err.message, 'error');
    }
}

function loadDefaultMarkers() {
    const defaults = [
        { name: 'Haselblüte', type: 'gts', threshold_value: 20, color: '#f0c929' },
        { name: 'Schneeglöckchen', type: 'gts', threshold_value: 35, color: '#e0e0e0' },
        { name: 'Forsythienblüte', type: 'gts', threshold_value: 100, color: '#ffe135' },
        { name: 'Kirschblüte', type: 'gts', threshold_value: 200, color: '#ffb7c5' },
        { name: 'Apfelblüte', type: 'gts', threshold_value: 250, color: '#ff69b4' },
        { name: 'Vollfrühling', type: 'gts', threshold_value: 350, color: '#66bb6a' },
        { name: 'Robinienblüte', type: 'gts', threshold_value: 500, color: '#c8e6c9' },
        { name: 'Lindenblüte', type: 'gts', threshold_value: 600, color: '#a5d6a7' },
    ];

    (async () => {
        showLoading(t('Standard-Marker werden geladen...'));
        for (const m of defaults) {
            try { await apiFetch('markers', 'POST', m); } catch (_) {}
        }
        await loadMarkers();
        await loadPredictions();
        if (typeof window.loadAllLocationsOverview === 'function') { window.loadAllLocationsOverview(); }
        hideLoading();
        toast(t('Standard-Marker geladen!'), 'success');
        renderCalendar();
        renderGTSChart();
    })();
}
