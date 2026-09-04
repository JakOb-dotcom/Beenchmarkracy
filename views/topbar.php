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
<!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-brand">
            🐝 <span>Beenchmarkracy</span>
        </div>
        <div class="topbar-right">
            <select id="lang-switcher" onchange="changeLanguage(this.value)" style="margin-right: 1rem; padding: 0.2rem; border-radius: 4px; border: 1px solid var(--border-color);">
                <option value="de">🇩🇪 Deutsch</option>
                <option value="en">🇬🇧 English</option>
            </select>
            <span class="topbar-user">👤 <span id="user-display"></span></span>
            <button class="btn btn-sm btn-secondary" onclick="handleLogout()" data-i18n="Abmelden">Abmelden</button>
        </div>
    </div>

    <!-- TAB BAR -->
    <div class="tab-bar">
        <button class="tab-btn active" data-tab="uebersicht" onclick="switchTab('uebersicht')">🗺️ <span data-i18n="Übersicht &amp; Standorte">Übersicht &amp; Standorte</span></button>
        <button class="tab-btn" data-tab="dashboard" onclick="switchTab('dashboard')">📊 <span data-i18n="Stände-Dashboard">Stände-Dashboard</span></button>
        <button class="tab-btn" data-tab="zuchtwerte" onclick="switchTab('zuchtwerte')">👑 <span data-i18n="Zuchtwerte">Zuchtwerte</span></button>
        <button class="tab-btn" data-tab="kalender" onclick="switchTab('kalender')">📅 <span data-i18n="Kalender &amp; Historie">Kalender &amp; Historie</span></button>
        <button class="tab-btn" data-tab="marker" onclick="switchTab('marker')">🏷️ <span data-i18n="Marker">Marker</span></button>
        <button class="tab-btn" data-tab="aufzeichnungen" onclick="switchTab('aufzeichnungen')">📝 <span data-i18n="Aufzeichnungen">Aufzeichnungen</span></button>
    </div>
