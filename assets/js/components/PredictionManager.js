/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// MARKER-VORHERSAGEN
// ══════════════════════════════════════════════════════

async function loadPredictions() {
    if (!state.selectedLocationId) return;
    try {
        state.predictions = await apiFetch(
            `marker_predictions&location_id=${state.selectedLocationId}&year=${state.currentYear}`
        );
        renderPredictionsPanel();
    } catch (err) {
        console.error('Predictions-Fehler:', err);
    }
}

function renderPredictionsPanel() {
    const container = document.getElementById('predictions-panel');
    if (!container) return;

    if (state.predictions.length === 0) {
        container.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Keine Marker-Vorhersagen verfügbar.')}</p>`;
        return;
    }

    const severityIcons = { info: 'ℹ️', warning: '⚠️', critical: '🚨' };
    const statusLabels = { upcoming: t('Bevorstehend'), active: t('Aktiv heute'), reached: t('Erreicht') };
    const statusColors = { upcoming: 'var(--info)', active: 'var(--primary)', reached: 'var(--success)' };

    let html = '';

    // Upcoming & Active zuerst
    const important = state.predictions.filter(p => p.status === 'upcoming' || p.status === 'active');
    const reached = state.predictions.filter(p => p.status === 'reached');

    if (important.length > 0) {
        html += important.map(p => {
            const sev = p.marker.severity || 'info';
            const borderColor = sev === 'critical' ? 'var(--danger)' : sev === 'warning' ? '#f97316' : 'var(--info)';
            const daysText = p.days_until > 0
                ? `${t('in')} ≈ ${p.days_until} ${p.days_until !== 1 ? t('Tagen') : t('Tag')} (${p.date})`
                : p.days_until === 0 ? t('HEUTE') : `${t('seit')} ${Math.abs(p.days_until)} ${t('Tag(en)')}`;

            return `
                <div class="prediction-card" style="border-left:4px solid ${borderColor}">
                    <div class="prediction-header">
                        <span>${severityIcons[sev]} <strong style="color:${p.marker.color}">${esc(p.marker.name)}</strong></span>
                        <span class="prediction-status" style="color:${statusColors[p.status]}">
                            ${statusLabels[p.status]}
                        </span>
                    </div>
                    <div class="prediction-timing">${daysText}</div>
                    ${p.message ? `<div class="prediction-message">${esc(p.message)}</div>` : ''}
                </div>`;
        }).join('');
    }

    // Reached (kompakt)
    if (reached.length > 0) {
        html += `<div style="margin-top:.8rem;">
            <span style="font-size:.8rem;color:var(--text-muted)">${t('Bereits erreicht:')}</span><br>`;
        html += reached.map(p =>
            `<span class="marker-chip" style="background:${p.marker.color}22;color:${p.marker.color};font-size:.75rem;">
                <span class="dot" style="background:${p.marker.color}"></span>
                ${esc(p.marker.name)} ${p.date ? `(${p.date})` : ''}
            </span>`
        ).join('');
        html += '</div>';
    }

    container.innerHTML = html;
}
