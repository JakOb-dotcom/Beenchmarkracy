/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

/**
 * Beenchmarkracy – Frontend-Einstieg
 * Tab-Steuerung, Initialisierung & Sprachwechsel-Re-Render
 */

function switchTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    // Sync to Alpine state if available
    try {
        if (window.Alpine) {
            const bodyData = Alpine.$data(document.body);
            if (bodyData && bodyData.activeTab !== undefined && bodyData.activeTab !== tabName) {
                bodyData.activeTab = tabName;
            }
        }
    } catch(e) {}

    const tabBtn = document.querySelector(`[data-tab="${tabName}"]`);
    if(tabBtn) tabBtn.classList.add('active');
    const tabBody = document.getElementById(`tab-${tabName}`);
    if(tabBody) tabBody.classList.add('active');

    if (tabName === 'dashboard') {
        // Das Dashboard zeigt immer das laufende Jahr. Wurde im Kalender in ein anderes
        // Jahr geblättert, liegen in state.gtsData dessen Daten – zurückholen.
        const thisYear = new Date().getFullYear();
        if (window.state.currentYear !== thisYear && window.state.selectedLocationId) {
            window.state.currentYear = thisYear;
            window.state.currentMonth = new Date().getMonth();
            Promise.all([window.loadGTSData(), window.loadPredictions(), window.loadClimateNormals()])
                .then(() => { if (window.renderCalendar) window.renderCalendar(); })
                .catch(err => console.error('Jahreswechsel Dashboard:', err));
        }
        // Chart muss neu gezeichnet werden, sobald der Tab sichtbar ist –
        // beim ersten Laden hatte das Canvas Breite/Höhe 0 (display:none).
        if (window.renderGTSStats && window.state.gtsData.length > 0) window.renderGTSStats();
        if (window.renderGTSChart && window.state.gtsData.length > 0) window.renderGTSChart();
    }
    if (tabName === 'kalender' && window.state.selectedLocationId && window.renderCalendar) {
        window.renderCalendar();
    }
    if (tabName === 'zuchtwerte' && window.loadZuchtwerteOverview) {
        const sel = document.getElementById('zuchtwerte-loc-select');
        window.loadZuchtwerteOverview(sel ? sel.value : 'all');
    }
    if (tabName === 'marker') {
        if(window.renderMarkerList) window.renderMarkerList();
        if(window.renderRuleBuilder) window.renderRuleBuilder();
    }
    if (tabName === 'aufzeichnungen' && window.loadAufzeichnungen) {
        window.loadAufzeichnungen();
    }
}

// Nach einem Sprachwechsel alle dynamisch gerenderten Bereiche neu aufbauen.
// Jede Funktion verwendet bereits geladene Daten aus window.state bzw. den
// Komponenten-Caches – es werden keine API-Aufrufe ausgelöst.
window.addEventListener('language-changed', () => {
    if (window.renderLocationList) window.renderLocationList();
    if (window.renderLocationSelectors) window.renderLocationSelectors();
    if (window.updateLocationBulkDeleteButton) window.updateLocationBulkDeleteButton();
    if (window.updateHiveBulkDeleteButton) window.updateHiveBulkDeleteButton();
    if (window.renderCalendar && window.state.selectedLocationId) window.renderCalendar();
    if (window.renderMarkerList) window.renderMarkerList();
    // Marker-Formular aus dem Bearbeiten-Modus zurücksetzen, damit der dynamisch
    // gesetzte Button-Text nicht halb-übersetzt stehen bleibt.
    if (window.resetSimpleMarkerForm) window.resetSimpleMarkerForm();
    if (window.renderRuleBuilder && document.getElementById('rule-builder-area')) window.renderRuleBuilder();
    if (window.renderPredictionsPanel) window.renderPredictionsPanel();
    if (window.renderGTSStats && window.state.gtsData.length > 0) window.renderGTSStats();
    if (window.renderGTSChart && window.state.gtsData.length > 0) window.renderGTSChart();
    if (window.loadHives && window.state.selectedLocationId) window.loadHives();
    if (window.loadAllLocationsOverview) window.loadAllLocationsOverview();

    // Aufzeichnungen-Tab nur neu rendern, wenn er bereits Daten hat
    const recordsTab = document.getElementById('tab-aufzeichnungen');
    if (recordsTab && recordsTab.classList.contains('active') && window.loadAufzeichnungen) {
        window.loadAufzeichnungen();
    } else if (window.renderRecordSettings) {
        window.renderRecordSettings();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    if(window.applyTranslations) window.applyTranslations();
    if(window.checkAuth) window.checkAuth();

    // Init tab visibility
    switchTab('uebersicht');

    // Resize handler for chart
    window.addEventListener('resize', () => {
        if (window.state && window.state.gtsData && window.state.gtsData.length > 0 && window.renderGTSChart) {
            window.renderGTSChart();
        }
    });
});
