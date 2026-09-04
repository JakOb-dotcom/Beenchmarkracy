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
<div id="tab-aufzeichnungen" class="tab-content" x-show="activeTab === 'aufzeichnungen'" style="display: none;">
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <h2>📝 <span data-i18n="Aufzeichnungen">Aufzeichnungen</span></h2>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="window.loadAufzeichnungen()">
                    <span class="icon">🔄</span> <span data-i18n="Aktualisieren">Aktualisieren</span>
                </button>
            </div>
        </div>
        <div class="card-body">

            <div style="display:flex; justify-content: space-between; gap: 20px; align-items: flex-start; flex-wrap: wrap;">

                <!-- STAMMDATEN / EINSTELLUNGEN -->
                <div style="flex: 1; min-width: 300px; background: var(--bg-body); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <h4>⚙️ <span data-i18n="Kategorien verwalten">Kategorien verwalten</span></h4>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px;" data-i18n="Definiere Honigsorten, Futterarten und Varroabehandlungen.">Definiere Honigsorten, Futterarten und Varroabehandlungen.</p>
                    <div style="display:flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center;">
                        <select id="record-category-select" class="form-control" style="flex: 1; min-width: 150px;" onchange="window.updateRecordUnitOptions()">
                            <option value="harvest" data-i18n="🍯 Honigsorte">🍯 Honigsorte</option>
                            <option value="feed" data-i18n="🌾 Futterart">🌾 Futterart</option>
                            <option value="varroa" data-i18n="🔬 Varroabehandlung">🔬 Varroabehandlung</option>
                        </select>
                        <input type="text" id="record-setting-name" class="form-control" placeholder="Bezeichnung..." data-i18n-placeholder="Bezeichnung..." style="flex: 2; min-width: 150px;">
                        <input type="text" id="record-setting-short" class="form-control" placeholder="Kürzel" data-i18n-placeholder="Kürzel" style="width: 100px;" title="Optionales Kürzel (z.B. BW für Blütenwald)" data-i18n-title="Optionales Kürzel (z.B. BW für Blütenwald)">
                        <select id="record-setting-unit" class="form-control" style="width: 80px;"></select>
                        <button class="btn btn-primary" style="flex-shrink: 0;" onclick="window.addRecordSetting()" data-i18n="Hinzufügen">Hinzufügen</button>
                    </div>
                    <div id="feed-recipe-inputs" style="display:none; gap: 10px; margin-bottom: 15px; padding: 10px; background: rgba(0,0,0,0.03); border: 1px solid var(--border-color); border-radius: 5px; align-items: center;">
                        <span style="font-size: 0.85rem; font-weight:bold; color:var(--text-muted);" data-i18n="Optional (Bedarf):">Optional (Bedarf):</span>
                        <input type="number" id="record-setting-sugar" class="form-control" placeholder="Gramm Zucker (z.B. 600)" data-i18n-placeholder="Gramm Zucker (z.B. 600)" style="flex:1; min-width:120px;" step="1">
                        <input type="number" id="record-setting-water" class="form-control" placeholder="ml Wasser (z.B. 400)" data-i18n-placeholder="ml Wasser (z.B. 400)" style="flex:1; min-width:120px;" step="1">
                        <span style="font-size: 0.8rem; color:var(--text-muted);" data-i18n="pro Einheit (kg/l)">pro Einheit (kg/l)</span>
                    </div>
                    <div id="record-settings-list" style="max-height: 200px; overflow-y: auto;">
                        <p style="color:var(--text-muted); font-size: 0.9rem;" data-i18n="Lade Kategorien...">Lade Kategorien...</p>
                    </div>
                </div>

                <!-- NEUER EINTRAG (BULK MODE) -->
                <div style="flex: 2; min-width: 350px; background: var(--bg-body); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <h4>✍️ <span data-i18n="Neuer Eintrag">Neuer Eintrag</span></h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">
                        <div>
                            <label style="font-size: 0.85rem; font-weight: bold; display: block; margin-bottom: 5px;" data-i18n="Bienenstand (Kohorte):">Bienenstand (Kohorte):</label>
                            <select id="new-record-location" class="form-control" style="width: 100%;" onchange="window.renderBulkGrid()"></select>
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; font-weight: bold; display: block; margin-bottom: 5px;" data-i18n="Datum:">Datum:</label>
                            <input type="date" id="new-record-date" class="form-control" style="width: 100%;">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; font-weight: bold; display: block; margin-bottom: 5px;" data-i18n="Aktion / Typ:">Aktion / Typ:</label>
                            <select id="new-record-type" class="form-control" style="width: 100%;" onchange="window.updateRecordFormUI()">
                                <option value="harvest" data-i18n="🍯 Honigernte">🍯 Honigernte</option>
                                <option value="feed" data-i18n="🍬 Einfütterung">🍬 Einfütterung</option>
                                <option value="varroa" data-i18n="🔬 Varroabehandlung">🔬 Varroabehandlung</option>
                                <option value="stockkarte" data-i18n="📋 Stockkarte (Status)">📋 Stockkarte (Status)</option>
                            </select>
                        </div>
                        <div id="div-record-setting">
                            <label style="font-size: 0.85rem; font-weight: bold; display: block; margin-bottom: 5px;" data-i18n="Art / Sorte:">Art / Sorte:</label>
                            <select id="new-record-setting" class="form-control" style="width: 100%;" onchange="window.renderBulkGrid()">
                            </select>
                        </div>
                        <div id="div-global-amount">
                            <label style="font-size: 0.85rem; font-weight: bold; display: block; margin-bottom: 5px;" data-i18n="Globale Menge (für alle):">Globale Menge (für alle):</label>
                            <input type="number" id="new-record-global-amount" class="form-control" style="width: 100%;" placeholder="z.B. 1.5" step="0.1" oninput="window.applyGlobalAmount()">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; font-weight: bold; display: block; margin-bottom: 5px;" data-i18n="Globale Anmerkung:">Globale Anmerkung:</label>
                            <input type="text" id="new-record-notes" class="form-control" style="width: 100%;" placeholder="Optionaler Text..." data-i18n-placeholder="Optionaler Text...">
                        </div>
                    </div>

                    <div style="margin-top: 15px; background: var(--bg-hover); padding: 10px; border-radius: 5px;">
                        <table style="width:100%; text-align: left; font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th style="padding-bottom: 5px;" data-i18n="Volk">Volk</th>
                                    <th style="padding-bottom: 5px; width: 120px;" data-i18n="Menge / Option">Menge / Option</th>
                                    <th style="padding-bottom: 5px; width: 60px;" data-i18n="Einheit">Einheit</th>
                                </tr>
                            </thead>
                            <tbody id="bulk-hives-body">
                                <tr><td colspan="3" style="text-align: center; color: var(--text-muted);" data-i18n="Bitte wähle einen Bienenstand.">Bitte wähle einen Bienenstand.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-outline" style="font-size: 0.85rem;" onclick="window.generatePrintableRecordForm()">
                                <span class="icon">🖨️</span> <span data-i18n="Leeres Formular drucken">Leeres Formular drucken</span>
                            </button>
                            <button class="btn btn-outline" style="font-size: 0.85rem;" onclick="window.generatePrintableStockkarte()">
                                <span class="icon">🖨️</span> <span data-i18n="Stockkarte drucken">Stockkarte drucken</span>
                            </button>
                        </div>
                        <button class="btn btn-primary" onclick="window.addRecordsBulk()" data-i18n="Einträge speichern">Einträge speichern</button>
                    </div>
                </div>

                <!-- BELEG-SCAN / OCR UPLOAD -->
                <div style="flex: 1; min-width: 300px; background: var(--bg-body); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <h4>📸 <span data-i18n="Beleg-Scan / Foto Upload">Beleg-Scan / Foto Upload</span></h4>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px;" data-i18n="Lade ein Foto (JPEG/PNG) eines ausgedruckten Formulars oder eines Varroa-Bodenschiebers hoch. Der OCR-Worker liest die Daten im Hintergrund aus.">Lade ein Foto (JPEG/PNG) eines ausgedruckten Formulars oder eines Varroa-Bodenschiebers hoch. Der OCR-Worker liest die Daten im Hintergrund aus.</p>
                    <div style="display:flex; flex-direction: column; gap: 10px;">
                        <input type="file" id="ocr-upload-input" class="form-control" accept="image/jpeg,image/png" capture="environment" style="width: 100%;">
                        <button id="ocr-upload-btn" class="btn btn-outline" onclick="window.uploadOcrImage()" data-i18n="Hochladen">Hochladen</button>
                    </div>
                    <div style="margin-top: 12px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong style="font-size:0.85rem;" data-i18n="Letzte Uploads">Letzte Uploads</strong>
                            <button class="btn-icon" onclick="window.loadOcrJobs()" title="Aktualisieren" data-i18n-title="Aktualisieren">🔄</button>
                        </div>
                        <div id="ocr-jobs-list" style="max-height: 180px; overflow-y: auto;">
                            <p style="color:var(--text-muted); font-size:0.8rem; margin:0;" data-i18n="Noch keine Uploads.">Noch keine Uploads.</p>
                        </div>
                    </div>
                </div>

            </div>

            <hr style="margin: 25px 0;">

            <!-- EDIT MODAL -->
            <div id="edit-record-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
                <div class="modal" style="width: 420px;">
                    <span class="close" onclick="document.getElementById('edit-record-modal').style.display='none'" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>
                    <h3 data-i18n="Eintrag bearbeiten">Eintrag bearbeiten</h3>
                    <input type="hidden" id="edit-record-id">
                    <div style="margin-top: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9rem;" data-i18n="Datum:">Datum:</label>
                        <input type="date" id="edit-record-date" class="form-control" style="width: 100%; margin-bottom: 15px;">

                        <label style="display:block; margin-bottom:5px; font-size:0.9rem;" data-i18n="Menge:">Menge:</label>
                        <input type="number" id="edit-record-amount" class="form-control" step="0.1" style="width: 100%; margin-bottom: 15px;">

                        <label style="display:block; margin-bottom:5px; font-size:0.9rem;" data-i18n="Einheit:">Einheit:</label>
                        <input type="text" id="edit-record-unit" class="form-control" style="width: 100%; margin-bottom: 15px;">

                        <label style="display:block; margin-bottom:5px; font-size:0.9rem;" data-i18n="Anmerkung:">Anmerkung:</label>
                        <input type="text" id="edit-record-notes" class="form-control" style="width: 100%; margin-bottom: 15px;">

                        <button class="btn btn-primary" style="width:100%;" onclick="window.saveRecordEdit()" data-i18n="Änderungen speichern">Änderungen speichern</button>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3>📋 <span data-i18n="Historie der Aufzeichnungen">Historie der Aufzeichnungen</span></h3>
                <div style="display:flex; gap: 10px;">
                    <button class="btn btn-outline" style="font-size: 0.85rem;" onclick="window.exportRecordsCSV()">📥 <span data-i18n="Als CSV exportieren">Als CSV exportieren</span></button>
                    <select id="filter-record-location" class="form-control" onchange="window.loadRecordsOptions(); window.loadRecords();">
                        <option value="" data-i18n="Alle Standorte">Alle Standorte</option>
                    </select>
                    <select id="filter-record-hive" class="form-control" onchange="window.loadRecords()">
                        <option value="" data-i18n="Alle Völker">Alle Völker</option>
                    </select>
                    <select id="filter-record-type" class="form-control" onchange="window.loadRecords()">
                        <option value="" data-i18n="Alle Aktionen">Alle Aktionen</option>
                        <option value="harvest" data-i18n="🍯 Honigernten">🍯 Honigernten</option>
                        <option value="feed" data-i18n="🍬 Fütterungen">🍬 Fütterungen</option>
                        <option value="varroa" data-i18n="🔬 Varroabehandlungen">🔬 Varroabehandlungen</option>
                        <option value="varroa_drop" data-i18n="🔴 Varroaabfall (KI-Scan)">🔴 Varroaabfall (KI-Scan)</option>
                    </select>
                </div>
            </div>

            <div id="records-list-container" style="overflow-x: auto;">
                <table class="data-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th data-i18n="Datum">Datum</th>
                            <th data-i18n="Volk (Standort)">Volk (Standort)</th>
                            <th data-i18n="Aktion">Aktion</th>
                            <th data-i18n="Art / Sorte">Art / Sorte</th>
                            <th data-i18n="Menge">Menge</th>
                            <th data-i18n="Anmerkung">Anmerkung</th>
                            <th style="text-align:right;" data-i18n="Aktionen">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="records-list-body">
                        <tr><td colspan="7" style="text-align:center; color:var(--text-muted);" data-i18n="Lade Daten...">Lade Daten...</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
