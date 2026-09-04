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
<div id="tab-zuchtwerte" class="tab-content" x-show="activeTab === 'zuchtwerte'" style="display: none;">
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <h2>👑 <span data-i18n="Zuchtwerte">Zuchtwerte</span></h2>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="printZuchtwerteFormular()"><span class="icon">🖨️</span> <span data-i18n="Formular drucken">Formular drucken</span></button>
                <button class="btn btn-outline" onclick="exportZuchtwerteCSV()"><span class="icon">📄</span> <span data-i18n="Export CSV">Export CSV</span></button>
                <select id="zuchtwerte-loc-select" class="form-control" style="width: auto;" onchange="loadZuchtwerteOverview(this.value)">
                    <option value="all" data-i18n="Alle Standorte">Alle Standorte</option>
                </select>
                <button class="btn btn-outline" onclick="loadZuchtwerteOverview(document.getElementById('zuchtwerte-loc-select').value)">
                    <span class="icon">🔄</span> <span data-i18n="Aktualisieren">Aktualisieren</span>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="card" style="margin-bottom: 20px; background: var(--bg-body); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">
                <h3 style="margin-top:0; font-size: 1.1rem; margin-bottom: 10px;" data-i18n="Neue Zuchtwerte erfassen">Neue Zuchtwerte erfassen</h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <div style="flex:1; min-width:200px;">
                        <label style="font-size:0.85rem;font-weight:bold;display:block;margin-bottom:2px;" data-i18n="Volk auswählen">Volk auswählen</label>
                        <select id="zw-new-hive" class="form-control">
                            <option value="" data-i18n="-- Lade Völker --">-- Lade Völker --</option>
                        </select>
                    </div>
                    <div style="width:70px;">
                        <label style="font-size:0.85rem;font-weight:bold;display:block;margin-bottom:2px;" title="Honig" data-i18n-title="Honig">🍯</label>
                        <input type="number" id="zw-new-h" class="form-control" min="1" max="10" placeholder="1-10">
                    </div>
                    <div style="width:70px;">
                        <label style="font-size:0.85rem;font-weight:bold;display:block;margin-bottom:2px;" title="Sanftmut" data-i18n-title="Sanftmut">😇</label>
                        <input type="number" id="zw-new-g" class="form-control" min="1" max="10" placeholder="1-10">
                    </div>
                    <div style="width:70px;">
                        <label style="font-size:0.85rem;font-weight:bold;display:block;margin-bottom:2px;" title="Wabensitz" data-i18n-title="Wabensitz">🐝</label>
                        <input type="number" id="zw-new-s" class="form-control" min="1" max="10" placeholder="1-10">
                    </div>
                    <div style="width:70px;">
                        <label style="font-size:0.85rem;font-weight:bold;display:block;margin-bottom:2px;" title="Schwarmneigung" data-i18n-title="Schwarmneigung">🌪️</label>
                        <input type="number" id="zw-new-w" class="form-control" min="1" max="10" placeholder="1-10">
                    </div>
                    <div style="width:70px;">
                        <label style="font-size:0.85rem;font-weight:bold;display:block;margin-bottom:2px;" title="Varroaresistenz" data-i18n-title="Varroaresistenz">🔬</label>
                        <input type="number" id="zw-new-v" class="form-control" min="1" max="10" placeholder="1-10">
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="submitNewZuchtwerte()" data-i18n="Speichern">Speichern</button>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <p data-i18n="Durchschnittliche Zuchtwerte basierend auf der Gewichtung oben. Höhere Werte sind besser.">Durchschnittliche Zuchtwerte basierend auf der Gewichtung oben. Höhere Werte sind besser.</p>
            </div>

            <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 20px; background: var(--bg-body); padding: 15px; border-radius: 8px;">
                <div style="flex:1; min-width:120px;">
                    <label style="font-size:0.85rem;font-weight:bold;" data-i18n="Honig (Gewichtung)">Honig (Gewichtung)</label>
                    <input type="number" id="weight-h" value="1.0" step="0.1" min="0" class="form-control" onchange="loadZuchtwerteOverview(document.getElementById('zuchtwerte-loc-select').value)">
                </div>
                <div style="flex:1; min-width:120px;">
                    <label style="font-size:0.85rem;font-weight:bold;" data-i18n="Sanftmut (Gewichtung)">Sanftmut (Gewichtung)</label>
                    <input type="number" id="weight-g" value="1.0" step="0.1" min="0" class="form-control" onchange="loadZuchtwerteOverview(document.getElementById('zuchtwerte-loc-select').value)">
                </div>
                <div style="flex:1; min-width:120px;">
                    <label style="font-size:0.85rem;font-weight:bold;" data-i18n="Wabensitz (Gewichtung)">Wabensitz (Gewichtung)</label>
                    <input type="number" id="weight-s" value="1.0" step="0.1" min="0" class="form-control" onchange="loadZuchtwerteOverview(document.getElementById('zuchtwerte-loc-select').value)">
                </div>
                <div style="flex:1; min-width:120px;">
                    <label style="font-size:0.85rem;font-weight:bold;" data-i18n="Schwarm (Gewichtung)">Schwarm (Gewichtung)</label>
                    <input type="number" id="weight-w" value="1.0" step="0.1" min="0" class="form-control" onchange="loadZuchtwerteOverview(document.getElementById('zuchtwerte-loc-select').value)">
                </div>
                <div style="flex:1; min-width:120px;">
                    <label style="font-size:0.85rem;font-weight:bold;" data-i18n="Varroa (Gewichtung)">Varroa (Gewichtung)</label>
                    <input type="number" id="weight-v" value="1.0" step="0.1" min="0" class="form-control" onchange="loadZuchtwerteOverview(document.getElementById('zuchtwerte-loc-select').value)">
                </div>
            </div>

            <div id="zuchtwerte-overview-container">
                <p style="color:var(--text-muted);text-align:center;" data-i18n="Lade Zuchtwerte...">Lade Zuchtwerte...</p>
            </div>
        </div>
    </div>
</div>
