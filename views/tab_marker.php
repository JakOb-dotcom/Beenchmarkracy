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
    <!-- TAB: MARKER                             -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-marker" class="tab-content">
        <!-- Einfachen Marker erstellen -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <span class="card-title" id="simple-marker-form-title">➕ <span data-i18n="Einfachen GTS-Marker erstellen">Einfachen GTS-Marker erstellen</span></span>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button class="btn btn-sm btn-secondary" onclick="loadDefaultMarkers()">
                        📋 <span data-i18n="Standard-Marker laden">Standard-Marker laden</span>
                    </button>
                    <button class="btn btn-sm btn-secondary" id="simple-marker-cancel-btn" onclick="resetSimpleMarkerForm()" style="display:none;" data-i18n="Abbrechen">
                        Abbrechen
                    </button>
                </div>
            </div>
            <form onsubmit="addMarker(event)">
                <input type="hidden" name="id" value="">
                <div id="simple-marker-edit-hint" class="marker-edit-hint" style="display:none;"></div>
                <div class="form-row">
                    <div class="form-group">
                        <label data-i18n="Marker-Name">Marker-Name</label>
                        <input type="text" name="name" placeholder="z.B. Kirschblüte" data-i18n-placeholder="z.B. Kirschblüte" required>
                    </div>
                    <div class="form-group">
                        <label data-i18n="Typ">Typ</label>
                        <select name="type">
                            <option value="gts" data-i18n="GTS-Schwellwert">GTS-Schwellwert</option>
                            <option value="temperature_deviation" data-i18n="Temperatur-Abweichung">Temperatur-Abweichung</option>
                            <option value="precipitation_deviation" data-i18n="Niederschlags-Abweichung">Niederschlags-Abweichung</option>
                            <option value="custom" data-i18n="Benutzerdefiniert">Benutzerdefiniert</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label data-i18n="Schwellwert">Schwellwert</label>
                        <input type="number" name="threshold_value" step="0.1" placeholder="z.B. 200 (GTS für Kirschblüte)" data-i18n-placeholder="z.B. 200 (GTS für Kirschblüte)">
                    </div>
                    <div class="form-group">
                        <label data-i18n="Farbe">Farbe</label>
                        <input type="color" name="color" value="#4caf50" style="height:38px;cursor:pointer;">
                    </div>
                </div>
                <div class="form-group">
                    <label data-i18n="Beschreibung (optional)">Beschreibung (optional)</label>
                    <textarea name="description" rows="2" placeholder="z.B. Beginn der Kirschblüte bei GTS ~200" data-i18n-placeholder="z.B. Beginn der Kirschblüte bei GTS ~200"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" id="simple-marker-submit-btn">🏷️ <span data-i18n="Marker erstellen">Marker erstellen</span></button>
            </form>
        </div>

        <!-- Komplexer Regel-Builder -->
        <div id="rule-builder-area" style="margin-bottom:1.5rem;">
            <!-- Wird dynamisch von renderRuleBuilder() befüllt -->
        </div>

        <!-- Markerliste -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 <span data-i18n="Meine Marker">Meine Marker</span></span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><span data-i18n="Name">Name</span></th>
                        <th><span data-i18n="Typ">Typ</span></th>
                        <th><span data-i18n="Schwellwert">Schwellwert</span></th>
                        <th><span data-i18n="Dringlichkeit">Dringlichkeit</span></th>
                        <th><span data-i18n="Nachricht">Nachricht</span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="marker-tbody"></tbody>
            </table>
        </div>
    </div>
