<?php
/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */
?>
    <!-- ═══════════════════════════════════════ -->
    <!-- TAB: DASHBOARD                          -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-dashboard" class="tab-content">
        <div class="location-selector" style="flex-direction: column; align-items: flex-start;">
            <div style="display: flex; align-items: center; width: 100%; gap: 1rem;">
                <label style="margin:0" data-i18n="Standort:">Standort:</label>
                <select class="loc-select" onchange="onLocationChange(this.value)" style="flex:1;"></select>
                <button class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="deleteCurrentLocationFromDashboard()">🗑️ <span data-i18n="Aktuellen Stand löschen">Aktuellen Stand löschen</span></button>
            </div>
            <div id="dashboard-loc-id" style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem; margin-left: 5rem;"><span data-i18n="Standort-ID:">Standort-ID:</span> <span id="dashboard-loc-id-value">-</span></div>
        </div>

        <!-- GTS Statistik -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" id="stat-gts-current">–</div>
                <div class="stat-label" data-i18n="Aktuelle GTS">Aktuelle GTS</div>
                <div class="stat-sublabel" id="stat-gts-date"></div>
            </div>
        </div>

        <!-- Vorhersagen & Alarme -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <span class="card-title">🔮 <span data-i18n="Marker-Vorhersagen &amp; Alarme">Marker-Vorhersagen &amp; Alarme</span></span>
            </div>
            <div id="predictions-panel">
                <p style="color:var(--text-muted);text-align:center;"><span data-i18n="Vorhersagen werden geladen...">Vorhersagen werden geladen...</span></p>
            </div>
        </div>

        <!-- Erreichte Marker -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <span class="card-title">🏷️ <span data-i18n="Marker-Status">Marker-Status</span></span>
            </div>
            <div id="reached-markers"></div>
        </div>

        <!-- GTS Chart -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <span class="card-title">📉 <span data-i18n="GTS-Verlauf">GTS-Verlauf</span> <span id="gts-chart-year"><?= date('Y') ?></span></span>
            </div>
            <div class="chart-container">
                <canvas id="gts-chart"></canvas>
            </div>
        </div>

        <!-- Anmerkungen -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <span class="card-title">📝 <span data-i18n="Anmerkungen für diesen Standort">Anmerkungen für diesen Standort</span></span>
            </div>
            <div style="margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
                <textarea id="location-note-input" rows="3" style="width: 100%; border: 1px solid var(--border-color); border-radius: var(--radius); padding: 0.5rem; resize: vertical;" placeholder="Hier eine neue Anmerkung eingeben..." data-i18n-placeholder="Hier eine neue Anmerkung eingeben..."></textarea>
                <div style="text-align: right;">
                    <button class="btn btn-primary" onclick="addLocationNote()"><span data-i18n="Anmerkung speichern">Anmerkung speichern</span></button>
                </div>
            </div>
            <div id="location-notes-list" style="display: flex; flex-direction: column; gap: 0.5rem;">
                <p style="color:var(--text-muted);text-align:center;"><span data-i18n="Lade Anmerkungen...">Lade Anmerkungen...</span></p>
            </div>
        </div>

        <!-- Völker & Zucht -->
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <span class="card-title">🐝 <span data-i18n="Völker &amp; Zucht">Völker &amp; Zucht</span></span>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <label style="display:flex;align-items:center;gap:.4rem;margin:0;color:var(--text-muted);font-size:.85rem;cursor:pointer;user-select:none;">
                        <input id="select-all-hives" type="checkbox" onclick="event.stopPropagation()" onchange="toggleAllHivesSelection(this.checked)" title="Alle Völker auswählen" data-i18n-title="Alle Völker auswählen" style="width:16px !important; height:16px !important; margin:0; cursor:pointer;"> <span data-i18n="Alle">Alle</span></label>
                    <button id="genetics-selected-hives-btn" class="btn btn-sm btn-secondary" onclick="updateSelectedHivesGenetics()" disabled>🧬 <span data-i18n="Genetik setzen">Genetik setzen</span></button>
                    <button id="delete-selected-hives-btn" class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="deleteSelectedHives()" disabled>🗑️ <span data-i18n="Ausgewählte löschen">Ausgewählte löschen</span></button>
                    <button class="btn btn-sm btn-primary" onclick="addHive()">+ <span data-i18n="Neues Volk">Neues Volk</span></button>
                </div>
            </div>
            <div id="hives-list" style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem;">
                <p style="color:var(--text-muted);text-align:center;"><span data-i18n="Lade Völker...">Lade Völker...</span></p>
            </div>
        </div>
    </div>
