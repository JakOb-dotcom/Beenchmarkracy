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
    <!-- TAB: ÜBERSICHT                          -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-uebersicht" x-show="activeTab === 'uebersicht'" class="tab-content" x-data="overviewManager" style="display: none;" :style="{ display: activeTab === 'uebersicht' ? 'block' : 'none' }">
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title">🗺️ <span data-i18n="Alle Stände &amp; Ereignisse">Alle Stände &amp; Ereignisse</span></span>
            <button class="btn btn-sm btn-secondary" @click="loadOverview()" :disabled="isLoading"><span data-i18n="Aktualisieren">Aktualisieren</span></button>
        </div>
        <div style="min-height:100px;padding:1rem;">
            <template x-if="isLoading && overviews.length === 0">
                <p style="color:var(--text-muted);text-align:center;"><span data-i18n="Übersicht wird geladen...">Übersicht wird geladen...</span></p>
            </template>
            <template x-if="!isLoading && overviews.length === 0">
                <p style="color:var(--text-muted);text-align:center;"><span data-i18n="Noch keine Standorte vorhanden.">Noch keine Standorte vorhanden.</span></p>
            </template>
            <!-- x-show statt x-if: Alpine 3.13 wirft bei x-for unter einem x-if einen Fehler, wenn das x-if false wird -->
                <div x-show="overviews.length > 0" style="display:flex;flex-direction:column;gap:0.75rem;">
                    <template x-for="item in overviews" :key="item.location.id">
                        <div style="border:1px solid var(--border-color);border-radius:var(--radius);padding:0.75rem;background:var(--bg-color);">
                            <div style="display:flex;justify-content:space-between;gap:0.75rem;align-items:flex-start;flex-wrap:wrap;">
                                <div>
                                    <div style="font-weight:700;" x-text="item.location.name"></div>
                                    <div style="font-size:0.82rem;color:var(--text-muted);margin-top:0.2rem;">
                                        <span data-i18n="Aktuelle GTS">Aktuelle GTS</span>: <span x-text="item.gts !== null && item.gts !== undefined ? Number(item.gts).toFixed(1) : '-'"></span>
                                        <template x-if="item.gtsDate">
                                            <span x-text="`(${item.gtsDate})`"></span>
                                        </template>
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-secondary" @click="changeLocation(item.location.id)"><span data-i18n="Standort öffnen">Standort öffnen</span></button>
                            </div>
                            <div style="margin-top:0.5rem;">
                                <template x-if="!item.ok">
                                    <span style="color:var(--danger);font-size:0.85rem;"><span data-i18n="Daten konnten nicht geladen werden.">Daten konnten nicht geladen werden.</span></span>
                                </template>
                                <template x-if="item.ok && item.events.length === 0">
                                    <span style="color:var(--text-muted);font-size:0.85rem;"><span data-i18n="Keine bevorstehenden Ereignisse.">Keine bevorstehenden Ereignisse.</span></span>
                                </template>
                                    <div x-show="item.ok && item.events.length > 0">
                                        <template x-for="ev in item.events" :key="ev.id">
                                            <span style="display:inline-block;border-radius:999px;padding:0.2rem 0.55rem;font-size:0.75rem;margin:0.1rem;"
                                                :style="`
                                                    background:${getSevColor(ev).raw}22;
                                                    color:${getSevColor(ev).raw};
                                                    border:1px solid ${getSevColor(ev).raw}44;
                                                `"
                                                x-text="`${ev.marker.name}: ${getWhen(ev)}`"
                                            ></span>
                                        </template>
                                    </div>
                            </div>
                        </div>
                    </template>
                </div>
        </div>
    </div>

    <!-- Standort-Verwaltung -->
    <div style="margin-top: 2rem;">
        <h3 style="margin-bottom: 1rem;"><span data-i18n="Standort-Verwaltung">Standort-Verwaltung</span></h3>

        <!-- Neuen Standort hinzufügen -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <span class="card-title">➕ <span data-i18n="Neuen Standort hinzufügen">Neuen Standort hinzufügen</span></span>
            </div>
            <form onsubmit="addLocation(event)">
                <div class="form-group">
                    <label data-i18n="Standortname">Standortname</label>
                    <input type="text" name="name" placeholder="z.B. Heimbienenstand Ploder" data-i18n-placeholder="z.B. Heimbienenstand Ploder" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label data-i18n="Breitengrad (Latitude)">Breitengrad (Latitude)</label>
                        <input type="number" name="latitude" step="0.000001" min="-90" max="90" placeholder="z.B. 47.0707" required>
                    </div>
                    <div class="form-group">
                        <label data-i18n="Längengrad (Longitude)">Längengrad (Longitude)</label>
                        <input type="number" name="longitude" step="0.000001" min="-180" max="180" placeholder="z.B. 15.4395" required>
                    </div>
                </div>
                <div class="form-group">
                    <label data-i18n="Höhe in Metern (optional)">Höhe in Metern (optional)</label>
                    <input type="number" name="altitude" placeholder="z.B. 450">
                </div>
                <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:1rem;">
                    ⚠️ <span data-i18n="Beim Anlegen eines neuen Standorts werden die Tageswetterdaten der letzten">Beim Anlegen eines neuen Standorts werden die Tageswetterdaten der letzten</span> <?= METEO_HISTORY_YEARS ?> <span data-i18n="Jahre plus laufendes Jahr, die 16-Tage-Vorhersage sowie die 30-jährige Klimareferenz">Jahre plus laufendes Jahr, die 16-Tage-Vorhersage sowie die 30-jährige Klimareferenz</span> (<?= METEO_NORMAL_START ?>–<?= METEO_NORMAL_END ?>) <span data-i18n="von Open-Meteo geladen. Das kann einige Minuten dauern.">von Open-Meteo geladen. Das kann einige Minuten dauern.</span>
                </p>
                <button type="submit" class="btn btn-primary">📍 <span data-i18n="Standort anlegen">Standort anlegen</span></button>
            </form>
        </div>

        <!-- Standortliste -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span class="card-title">📋 <span data-i18n="Meine Standorte">Meine Standorte</span></span>
                <button id="delete-selected-locations-btn" class="btn btn-sm" style="background:var(--danger);color:#fff;" onclick="deleteSelectedLocations()" disabled>🗑️ <span data-i18n="Ausgewählte löschen">Ausgewählte löschen</span></button>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="select-all-locations" onclick="toggleAllLocationSelection(this.checked)"></th>
                        <th><span data-i18n="Name">Name</span></th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th><span data-i18n="Höhe">Höhe</span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="location-tbody"></tbody>
            </table>
        </div>
    </div>
</div>
