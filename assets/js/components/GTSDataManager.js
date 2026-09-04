/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// GTS-DATEN, STATISTIK & CHART
// ══════════════════════════════════════════════════════

async function loadGTSData() {
    if (!state.selectedLocationId) return;

    try {
        const [gtsData, comparison, transitions] = await Promise.all([
            apiFetch(`gts&location_id=${state.selectedLocationId}&year=${state.currentYear}`),
            apiFetch(`gts_comparison&location_id=${state.selectedLocationId}&year=${state.currentYear}`),
            apiFetch(`marker_rule_transitions&location_id=${state.selectedLocationId}&year=${state.currentYear}`).catch(() => [])
        ]);
        state.gtsData = gtsData;
        state.gtsComparison = comparison;
        state.markerTransitions = Array.isArray(transitions) ? transitions : [];
        renderGTSStats();
        renderGTSChart();
        if (window.renderCalendarMarkerEvents) window.renderCalendarMarkerEvents();
    } catch (err) {
        console.error('GTS-Fehler:', err);
    }
}

function renderGTSStats() {
    const today = formatDate(new Date());
    const todayEntry = state.gtsData.find(e => e.date === today);
    const latestEntry = state.gtsData[state.gtsData.length - 1];

    document.getElementById('stat-gts-current').textContent =
        todayEntry ? todayEntry.gts.toFixed(1) : (latestEntry ? latestEntry.gts.toFixed(1) : '-');

    document.getElementById('stat-gts-date').textContent =
        todayEntry ? today : (latestEntry ? latestEntry.date : '-');

    // Reached markers + Predictions
    const currentGTS = todayEntry?.gts || latestEntry?.gts || 0;
    const markersContainer = document.getElementById('reached-markers');
    const simpleMarkers = state.markers.filter(m => m.type === 'gts' && m.threshold_value);
    const reached = simpleMarkers.filter(m => currentGTS >= m.threshold_value);
    const upcoming = simpleMarkers.filter(m => currentGTS < m.threshold_value)
        .sort((a, b) => a.threshold_value - b.threshold_value);

    let html = reached.map(m =>
        `<span class="marker-chip" style="background:${m.color}33;color:${m.color}">
            <span class="dot" style="background:${m.color}"></span>
            ${esc(m.name)} (${m.threshold_value}) ✓
        </span>`
    ).join('');

    if (upcoming.length > 0) {
        // Schätze Datum anhand der Vorhersage
        const nextMarker = upcoming[0];
        const forecastEntry = state.gtsData.find(e => e.gts >= nextMarker.threshold_value);
        const daysInfo = forecastEntry
            ? `≈ ${Math.ceil((new Date(forecastEntry.date) - new Date()) / 86400000)} ${t('Tage')} (${forecastEntry.date})`
            : `${t('noch')} ${(nextMarker.threshold_value - currentGTS).toFixed(0)} GTS`;

        html += `<span class="marker-chip" style="background:var(--bg-input);color:var(--primary)">
            ⏳ ${esc(nextMarker.name)} (${nextMarker.threshold_value}) - ${daysInfo}
        </span>`;
    }

    markersContainer.innerHTML = html || `<span style="color:var(--text-muted)">${t('Keine Marker definiert')}</span>`;

    // Predictions panel
    renderPredictionsPanel();
}

function renderGTSChart() {
    const canvas = document.getElementById('gts-chart');
    if (!canvas || state.gtsData.length === 0) return;

    const ctx = canvas.getContext('2d');
    const rect = canvas.parentElement.getBoundingClientRect();
    // Container unsichtbar (Tab display:none) → Größe 0. Dann nicht zeichnen,
    // sondern auf den nächsten Aufruf (beim Tab-Wechsel) warten; sonst bliebe
    // das Canvas dauerhaft 0×0 und leer.
    if (rect.width === 0 || rect.height === 0) return;
    canvas.width = rect.width;
    canvas.height = rect.height;

    const data = state.gtsData;
    const maxGTS = Math.max(...data.map(d => d.gts), 100);

    const padding = { top: 30, right: 20, bottom: 40, left: 60 };
    const w = canvas.width - padding.left - padding.right;
    const h = canvas.height - padding.top - padding.bottom;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Grid
    ctx.strokeStyle = '#334155';
    ctx.lineWidth = 0.5;
    for (let i = 0; i <= 5; i++) {
        const y = padding.top + (h / 5) * i;
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(canvas.width - padding.right, y);
        ctx.stroke();

        ctx.fillStyle = '#94a3b8';
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxGTS - (maxGTS / 5) * i), padding.left - 8, y + 4);
    }

    // X-Axis labels (months)
    const months = localizedMonthNames('short');
    ctx.fillStyle = '#94a3b8';
    ctx.textAlign = 'center';
    for (let m = 0; m < 12; m++) {
        const x = padding.left + (w / 12) * m + (w / 24);
        ctx.fillText(months[m], x, canvas.height - 10);
    }

    // Historical average line
    if (Object.keys(state.gtsComparison).length > 0) {
        ctx.beginPath();
        ctx.strokeStyle = '#64748b';
        ctx.lineWidth = 1.5;
        ctx.setLineDash([5, 5]);
        let first = true;
        for (let doy = 1; doy <= 366; doy++) {
            if (state.gtsComparison[doy] !== undefined) {
                const x = padding.left + ((doy - 1) / 365) * w;
                const y = padding.top + h - (state.gtsComparison[doy] / maxGTS) * h;
                if (first) { ctx.moveTo(x, y); first = false; }
                else ctx.lineTo(x, y);
            }
        }
        ctx.stroke();
        ctx.setLineDash([]);
    }

    // Current year GTS line
    ctx.beginPath();
    ctx.strokeStyle = '#f59e0b';
    ctx.lineWidth = 2.5;
    data.forEach((entry, i) => {
        const doy = dayOfYear(new Date(entry.date));
        const x = padding.left + ((doy - 1) / 365) * w;
        const y = padding.top + h - (entry.gts / maxGTS) * h;
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.stroke();

    // GTS Marker lines
    state.markers.filter(m => m.type === 'gts').forEach(marker => {
        const val = parseFloat(marker.threshold_value);
        if (val > maxGTS) return;
        const y = padding.top + h - (val / maxGTS) * h;

        ctx.beginPath();
        ctx.strokeStyle = marker.color;
        ctx.lineWidth = 1;
        ctx.setLineDash([3, 3]);
        ctx.moveTo(padding.left, y);
        ctx.lineTo(canvas.width - padding.right, y);
        ctx.stroke();
        ctx.setLineDash([]);

        ctx.fillStyle = marker.color;
        ctx.font = 'bold 10px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(`${marker.name} (${val})`, padding.left + 5, y - 4);
    });

    // Legend
    ctx.font = '11px sans-serif';
    ctx.fillStyle = '#f59e0b';
    ctx.fillText('● ' + t('Aktuelles Jahr'), padding.left + 10, 18);
    ctx.fillStyle = '#64748b';
    const histYears = window.APP_CONFIG && window.APP_CONFIG.historyYears;
    const histLabel = histYears ? `${t('Historischer Ø')} (${t('letzte')} ${histYears} ${t('Jahre')})` : t('Historischer Ø');
    ctx.fillText('--- ' + histLabel, padding.left + 150, 18);
}


