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
    <!-- TAB: KALENDER                           -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-kalender" class="tab-content">
        <div class="location-selector">
            <label style="margin:0" data-i18n="Standort:">Standort:</label>
            <select class="loc-select" onchange="onLocationChange(this.value)"></select>
        </div>

        <!-- Schnellauswahl -->
        <div class="card" style="margin-bottom:1rem;padding:1rem;">
            <div style="display:flex;align-items:center;gap:.8rem;flex-wrap:wrap;">
                <button class="btn btn-sm btn-secondary" onclick="prevMonth()">◀</button>
                <span id="calendar-month-label" style="min-width:150px;text-align:center;font-weight:600;font-size:1.1rem;"></span>
                <button class="btn btn-sm btn-secondary" onclick="nextMonth()">▶</button>
                <div style="margin-left:auto;display:flex;align-items:center;gap:.5rem;">
                    <label style="margin:0;font-size:.8rem;" data-i18n="Jahr:">Jahr:</label>
                    <input type="number" id="calendar-year-input" style="width:80px;padding:.3rem .5rem;text-align:center;"
                           value="<?= date('Y') ?>" min="2000" max="2100"
                           onchange="jumpToYear(this.value)">
                    <button class="btn btn-sm btn-primary" onclick="jumpToToday()" style="white-space:nowrap;" data-i18n="Heute">Heute</button>
                </div>
            </div>
            <div id="month-quick-select" class="month-quick-bar" style="margin-top:.6rem;"></div>
        </div>

        <!-- Monatsstatistiken -->
        <div id="calendar-stats" style="margin-bottom:1rem;"></div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">📅 <span data-i18n="Tagesübersicht – GTS, Temperatur &amp; Niederschlag">Tagesübersicht – GTS, Temperatur &amp; Niederschlag</span></span>
            </div>
            <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:1rem;">
                <span data-i18n="Klicke auf einen Tag für Details. Blau markierte Tage = Vorhersage.">Klicke auf einen Tag für Details. Blau markierte Tage = Vorhersage.</span>
            </p>
            <div id="calendar-grid" class="calendar-grid"></div>
        </div>

        <div class="card" style="margin-top:1rem;">
            <div class="card-header">
                <span class="card-title">🏷️ <span data-i18n="GTS-Mehrregelmarker">GTS-Mehrregelmarker</span></span>
            </div>
            <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:1rem;">
                <span data-i18n="Beginn- und Ende-Ereignisse für Mehrregelmarker, die nur aus GTS-Vergleichen bestehen.">Beginn- und Ende-Ereignisse für Mehrregelmarker, die nur aus GTS-Vergleichen bestehen.</span>
            </p>
            <div id="calendar-marker-events"></div>
        </div>

        <div class="card" style="margin-top:1rem;" x-data="historyComparisonManager">
            <div class="card-header">
                <span class="card-title">📖 <span data-i18n="Historie-Vergleich">Historie-Vergleich</span></span>
            </div>

            <div style="padding: 1.5rem;">
                <!-- Not Selected -->
                <template x-if="!localSelectedLocationId">
                    <p style="color:var(--text-muted);text-align:center;">
                        <span data-i18n="Wähle einen Standort aus, um den Historie-Vergleich zu sehen.">Wähle einen Standort aus, um den Historie-Vergleich zu sehen.</span>
                    </p>
                </template>

                <!-- Loading -->
                <template x-if="localSelectedLocationId && isLoading">
                    <p style="color:var(--text-muted);text-align:center;"><span data-i18n="Lade Historien-Daten...">Lade Historien-Daten...</span></p>
                </template>

                <!-- Error -->
                <template x-if="localSelectedLocationId && !isLoading && hasError">
                    <p style="color:var(--danger);text-align:center;"><span data-i18n="Fehler beim Laden der Daten.">Fehler beim Laden der Daten.</span></p>
                </template>

                <!-- Empty -->
                <template x-if="localSelectedLocationId && !isLoading && !hasError && series.length === 0">
                    <p style="text-align:center;"><span data-i18n="Keine Daten verfügbar.">Keine Daten verfügbar.</span></p>
                </template>
                <!-- Data Grid -->
                <!-- x-show statt x-if: Alpine 3.13 wirft bei x-for unter einem x-if einen Fehler, wenn das x-if false wird -->
                    <div class="stats-grid" x-show="localSelectedLocationId && !isLoading && !hasError && series.length > 0">
                        <template x-for="item in series" :key="item.label">
                            <div class="stat-card">
                                <div class="stat-label" style="margin-bottom:.5rem;font-weight:600" x-text="item.label"></div>

                                <div style="font-size:.85rem;">
                                    🌡️ Ø <span x-text="item.indicators.current_avg_temp"></span>°C
                                    <span :class="item.indicators.temp_deviation > 0 ? 'dev-pos' : (item.indicators.temp_deviation < 0 ? 'dev-neg' : 'dev-neutral')"
                                        x-text="'(' + (item.indicators.temp_deviation > 0 ? '+' : '') + item.indicators.temp_deviation + '°C)'"></span>
                                </div>

                                <div style="font-size:.85rem;margin-top:.3rem;">
                                    💧 <span x-text="item.indicators.current_total_precip"></span> mm
                                    <span :class="item.indicators.precip_deviation > 0 ? 'dev-neg' : (item.indicators.precip_deviation < 0 ? 'dev-pos' : 'dev-neutral')"
                                        x-text="'(' + (item.indicators.precip_deviation > 0 ? '+' : '') + item.indicators.precip_deviation + ' mm)'"></span>
                                </div>

                                <div class="stat-sublabel" style="margin-top:.4rem;">
                                    <span data-i18n="Hist. Ø:">Hist. Ø:</span> <span x-text="item.indicators.historical_avg_temp"></span>°C / <span x-text="item.indicators.historical_avg_precip"></span> mm
                                </div>
                            </div>
                        </template>
                    </div>
            </div>
        </div>
    </div>
