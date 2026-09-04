/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// CALENDAR
// ══════════════════════════════════════════════════════

// Lokalisierte Monatsnamen (lang: 'long' => Januar/January, 'short' => Jan)
function localizedMonthNames(format = 'long') {
    const names = [];
    for (let m = 0; m < 12; m++) {
        names.push(new Date(2000, m, 1).toLocaleDateString(window.uiLocale(), { month: format }));
    }
    return names;
}

function localizedWeekdayNames() {
    const names = [];
    // 3.1.2000 war ein Montag
    for (let d = 0; d < 7; d++) {
        names.push(new Date(2000, 0, 3 + d).toLocaleDateString(window.uiLocale(), { weekday: 'short' }));
    }
    return names;
}

function renderCalendar() {
    const container = document.getElementById('calendar-grid');
    if (!container) return;

    const year = state.currentYear;
    const month = state.currentMonth;
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDow = (firstDay.getDay() + 6) % 7; // Monday = 0
    const today = formatDate(new Date());

    document.getElementById('calendar-month-label').textContent =
        `${localizedMonthNames('long')[month]} ${year}`;

    // Quick-Select und Year-Input aktualisieren
    renderMonthQuickSelect();
    const yearInput = document.getElementById('calendar-year-input');
    if (yearInput) yearInput.value = year;

    // GTS-Chart-Jahresanzeige aktuell halten
    const chartYear = document.getElementById('gts-chart-year');
    if (chartYear) chartYear.textContent = year;

    // -- Monats-Statistiken berechnen --
    renderMonthlyStats(year, month);

    // Headers
    let html = localizedWeekdayNames().map(d =>
        `<div class="cal-header">${d}</div>`
    ).join('');

    // Leere Tage vor dem 1.
    for (let i = 0; i < startDow; i++) {
        html += '<div class="cal-day empty"></div>';
    }

    // Tage
    for (let day = 1; day <= lastDay.getDate(); day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const entry = state.gtsData.find(e => e.date === dateStr);
        const isToday = dateStr === today;
        const isForecast = new Date(dateStr) > new Date();

        let classes = 'cal-day';
        if (isToday) classes += ' today';
        if (isForecast) classes += ' forecast';

        let content = `<div class="cal-day-num">${day}</div>`;

        if (entry) {
            content += `<div class="cal-day-gts">GTS: ${entry.gts.toFixed(0)}</div>`;
            content += `<div class="cal-day-temp">${entry.temp_mean.toFixed(1)}°C</div>`;
            content += `<div class="cal-day-precip">💧 ${entry.precipitation.toFixed(1)}</div>`;

            // Marker dots
            const reachedMarkers = state.markers.filter(m =>
                m.type === 'gts' && entry.gts >= m.threshold_value
            );
            if (reachedMarkers.length > 0) {
                content += '<div>';
                const latest = reachedMarkers[reachedMarkers.length - 1];
                content += `<span class="cal-marker-dot" style="background:${latest.color}" title="${esc(latest.name)}"></span>`;
                content += '</div>';
            }
        }

        html += `<div class="${classes}" onclick="showDayDetail('${dateStr}')">${content}</div>`;
    }

    container.innerHTML = html;
    renderCalendarMarkerEvents();
}

/**
 * Berechnet und rendert Monats- und Jahresstatistiken oberhalb des Kalenders.
 */
function renderMonthlyStats(year, month) {
    const container = document.getElementById('calendar-stats');
    if (!container) return;

    // Monatsdaten aus gtsData extrahieren
    const monthEntries = state.gtsData.filter(e => {
        const d = e.date.split('-');
        return parseInt(d[1]) === month + 1;
    });

    if (monthEntries.length === 0) {
        container.innerHTML = `<p style="color:var(--text-muted);text-align:center;font-size:.85rem;">${t('Keine Daten für diesen Monat verfügbar.')}</p>`;
        return;
    }

    // -- Monatswerte berechnen --
    const totalPrecip = monthEntries.reduce((s, e) => s + (e.precipitation || 0), 0);
    const avgTemp = monthEntries.reduce((s, e) => s + (e.temp_mean || 0), 0) / monthEntries.length;
    const gtsEnd = monthEntries[monthEntries.length - 1]?.gts || 0;
    const gtsStart = month === 0 ? 0 : ((() => {
        const prevMonthEntries = state.gtsData.filter(e => {
            const d = e.date.split('-');
            return parseInt(d[1]) === month;
        });
        return prevMonthEntries.length > 0 ? prevMonthEntries[prevMonthEntries.length - 1].gts : 0;
    })());
    const gtsMonthContrib = gtsEnd - gtsStart;

    // -- Klimanormale für diesen Monat --
    const normal = state.climateNormals[month + 1] || null;
    const precipDev = normal && normal.avg_precip !== null ? totalPrecip - normal.avg_precip : null;
    const tempDev = normal && normal.avg_temp !== null ? avgTemp - normal.avg_temp : null;

    // -- GTS historischer Ø am Monatsende --
    const lastDoyOfMonth = dayOfYear(new Date(year, month + 1, 0));
    const gtsHistEnd = state.gtsComparison[lastDoyOfMonth] || null;
    const gtsDev = gtsHistEnd !== null ? gtsEnd - gtsHistEnd : null;

    // -- Jahresniederschlag bis zum aktuellen Monat --
    const monthEndDate = new Date(year, month + 1, 0);
    const ytdEntries = state.gtsData.filter(e => new Date(e.date) <= monthEndDate);
    const ytdPrecip = ytdEntries.reduce((s, e) => s + (e.precipitation || 0), 0);

    // Normaler Jahresniederschlag bis zu diesem Monat (Summe der Monatsnormale 1..month+1)
    let ytdNormalPrecip = null;
    if (normal) {
        ytdNormalPrecip = 0;
        for (let m = 1; m <= month + 1; m++) {
            const mn = state.climateNormals[m];
            if (mn && mn.avg_precip !== null) ytdNormalPrecip += mn.avg_precip;
        }
    }
    const ytdPrecipDev = ytdNormalPrecip !== null ? ytdPrecip - ytdNormalPrecip : null;

    // -- Referenzzeitraum --
    const refPeriod = normal?.reference_period || '';

    // -- HTML aufbauen --
    const devHtml = (val, unit, invert = false) => {
        if (val === null) return '<span style="color:var(--text-muted)">-</span>';
        const cls = invert
            ? (val > 0 ? 'dev-neg' : val < 0 ? 'dev-pos' : 'dev-neutral')
            : (val > 0 ? 'dev-pos' : val < 0 ? 'dev-neg' : 'dev-neutral');
        return `<span class="${cls}">(${val > 0 ? '+' : ''}${val.toFixed(1)}${unit})</span>`;
    };

    container.innerHTML = `
        <div class="monthly-stats-bar">
            <div class="monthly-stat">
                <span class="monthly-stat-icon">💧</span>
                <div>
                    <div class="monthly-stat-value">${totalPrecip.toFixed(1)} mm</div>
                    <div class="monthly-stat-label">${t('Monat')} ${devHtml(precipDev, ' mm')}</div>
                </div>
            </div>
            <div class="monthly-stat">
                <span class="monthly-stat-icon">🌡️</span>
                <div>
                    <div class="monthly-stat-value">${avgTemp.toFixed(1)} °C</div>
                    <div class="monthly-stat-label">Ø ${t('Temp.')} ${devHtml(tempDev, '°C')}</div>
                </div>
            </div>
            <div class="monthly-stat">
                <span class="monthly-stat-icon">🌿</span>
                <div>
                    <div class="monthly-stat-value">${gtsEnd.toFixed(0)}</div>
                    <div class="monthly-stat-label">GTS ${devHtml(gtsDev, '')}</div>
                </div>
            </div>
            <div class="monthly-stat">
                <span class="monthly-stat-icon">☔</span>
                <div>
                    <div class="monthly-stat-value">${ytdPrecip.toFixed(0)} mm</div>
                    <div class="monthly-stat-label">${t('Jahr bisher')} ${devHtml(ytdPrecipDev, ' mm')}</div>
                </div>
            </div>
        </div>
        ${refPeriod ? `<div style="text-align:right;font-size:.65rem;color:var(--text-muted);margin-top:.2rem;">${t('Referenz:')} ${esc(refPeriod)} (${t('30-Jahres-Ø via Open-Meteo')})</div>` : ''}
    `;
}

function prevMonth() {
    state.currentMonth--;
    if (state.currentMonth < 0) {
        state.currentMonth = 11;
        state.currentYear--;
        loadGTSData().then(() => loadClimateNormals()).then(() => renderCalendar());
    }
    renderCalendar();
}

function nextMonth() {
    state.currentMonth++;
    if (state.currentMonth > 11) {
        state.currentMonth = 0;
        state.currentYear++;
        loadGTSData().then(() => loadClimateNormals()).then(() => renderCalendar());
    }
    renderCalendar();
}

function jumpToMonth(monthIndex) {
    state.currentMonth = parseInt(monthIndex);
    renderCalendar();
}

function jumpToYear(year) {
    const newYear = parseInt(year);
    if (isNaN(newYear) || newYear < 2000 || newYear > 2100) return;
    if (newYear !== state.currentYear) {
        state.currentYear = newYear;
        loadGTSData().then(() => loadClimateNormals()).then(() => renderCalendar());
    }
    renderCalendar();
}

function jumpToToday() {
    const now = new Date();
    const needReload = state.currentYear !== now.getFullYear();
    state.currentYear = now.getFullYear();
    state.currentMonth = now.getMonth();
    if (needReload) {
        loadGTSData().then(() => loadClimateNormals()).then(() => renderCalendar());
    }
    renderCalendar();
}

function renderMonthQuickSelect() {
    const container = document.getElementById('month-quick-select');
    if (!container) return;
    const monthNames = localizedMonthNames('short');

    container.innerHTML = monthNames.map((m, i) =>
        `<button class="month-quick-btn ${i === state.currentMonth ? 'active' : ''}"
                 onclick="jumpToMonth(${i})">${m}</button>`
    ).join('');
}

/**
 * Beginn-/Ende-Ereignisse von GTS-Mehrregelmarkern (API: marker_rule_transitions)
 * unterhalb des Kalenders auflisten. Daten kommen aus state.markerTransitions
 * (werden in loadGTSData mitgeladen).
 */
function renderCalendarMarkerEvents() {
    const container = document.getElementById('calendar-marker-events');
    if (!container) return;

    const events = state.markerTransitions || [];
    if (events.length === 0) {
        container.innerHTML = `<p style="color:var(--text-muted);font-size:.85rem;margin:0;">${t('Keine GTS-Mehrregelmarker mit Ereignissen im gewählten Jahr.')}</p>`;
        return;
    }

    container.innerHTML = events.map(ev => {
        const color = ev.marker?.color || 'var(--primary)';
        const kind = ev.kind === 'begin' ? t('Beginn') : t('Ende');
        const days = ev.days_until;
        const when = days === 0 ? t('Heute')
            : days === 1 ? t('Morgen')
            : days > 1 ? t('In {n} Tagen').replace('{n}', days)
            : t('Vor {n} Tagen').replace('{n}', Math.abs(days));
        return `<div style="display:flex;align-items:center;gap:.6rem;padding:.4rem .6rem;border-left:3px solid ${color};margin-bottom:.35rem;background:var(--bg-input);border-radius:0 6px 6px 0;font-size:.85rem;">
            <span style="font-weight:600;color:${color};">${ev.kind === 'begin' ? '▶' : '■'} ${kind}: ${esc(ev.marker?.name || '')}</span>
            <span style="color:var(--text-muted);">${ev.date} (${when})</span>
            ${ev.gts !== null && ev.gts !== undefined ? `<span style="margin-left:auto;color:var(--text-muted);">GTS ${Number(ev.gts).toFixed(0)}</span>` : ''}
        </div>`;
    }).join('');
}
window.renderCalendarMarkerEvents = renderCalendarMarkerEvents;

// closeDayDetail ist in DayDetailManager.js definiert.
