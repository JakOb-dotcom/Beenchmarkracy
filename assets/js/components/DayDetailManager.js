/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// TAGES-DETAIL-MODAL
// ══════════════════════════════════════════════════════

async function showDayDetail(dateStr) {
    const entry = state.gtsData.find(e => e.date === dateStr);
    if (!entry) return;

    const modal = document.getElementById('day-detail-modal');
    modal.style.display = 'flex';

    let triggeredHtml = `<p style="color:var(--text-muted);font-size:.85rem;">${t('Lade Marker-Auswertung...')}</p>`;
    document.getElementById('day-detail-content').innerHTML = buildDayDetailHtml(dateStr, entry, triggeredHtml);

    try {
        const triggered = await apiFetch(
            `marker_evaluate&location_id=${state.selectedLocationId}&date=${dateStr}`
        );

        const sevIcons = { info: 'ℹ️', warning: '⚠️', critical: '🚨' };
        if (triggered.length > 0) {
            triggeredHtml = triggered.map(tr => `
                <div style="border-left:3px solid ${tr.marker.color};padding:.5rem .8rem;margin-bottom:.4rem;background:var(--bg-input);border-radius:0 6px 6px 0;">
                    <span style="color:${tr.marker.color};font-weight:600;">
                        ${sevIcons[tr.marker.severity] || 'ℹ️'} ${esc(tr.marker.name)}
                    </span>
                    ${tr.message ? `<div style="font-size:.82rem;color:var(--text-muted);margin-top:.2rem;">${esc(tr.message)}</div>` : ''}
                </div>
            `).join('');
        } else {
            triggeredHtml = `<p style="color:var(--text-muted);font-size:.85rem;">${t('Keine Marker an diesem Tag ausgelöst.')}</p>`;
        }

        document.getElementById('day-detail-content').innerHTML = buildDayDetailHtml(dateStr, entry, triggeredHtml);
    } catch (_) {}
}

function buildDayDetailHtml(dateStr, entry, triggeredHtml) {
    return `
        <h2>📅 ${dateStr}</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">${entry.gts.toFixed(1)}</div>
                <div class="stat-label">GTS</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${entry.temp_mean.toFixed(1)}°C</div>
                <div class="stat-label">${t('Temperatur (Ø)')}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${entry.precipitation.toFixed(1)}</div>
                <div class="stat-label">${t('Niederschlag (mm)')}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${entry.contribution.toFixed(2)}</div>
                <div class="stat-label">${t('Tagesbeitrag')} (×${entry.factor})</div>
            </div>
        </div>
        <h3 style="margin:1rem 0 .5rem;">${t('Ausgelöste Marker')}:</h3>
        ${triggeredHtml}
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeDayDetail()">${t('Schließen')}</button>
        </div>
    `;
}

function closeDayDetail() {
    document.getElementById('day-detail-modal').style.display = 'none';
}
