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
<!-- ═══════════════════════════════════════════ -->
<!-- LOGIN SCREEN                                -->
<!-- ═══════════════════════════════════════════ -->
<div id="login-screen">
    <div class="login-box">
        <div class="logo">🐝</div>
        <h1>Beenchmarkracy</h1>
        <p class="subtitle" data-i18n="Imkerei Ploder – Wetterdaten &amp; Grünlandtemperatursumme">Imkerei Ploder – Wetterdaten &amp; Grünlandtemperatursumme</p>

        <form onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="login-user" data-i18n="Benutzername">Benutzername</label>
                <input type="text" id="login-user" placeholder="Benutzername" data-i18n-placeholder="Benutzername" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="login-pass" data-i18n="Passwort">Passwort</label>
                <input type="password" id="login-pass" placeholder="Passwort" data-i18n-placeholder="Passwort" autocomplete="current-password" required>
            </div>
            <button type="submit" id="login-btn" class="btn btn-primary" style="width:100%;margin-top:.5rem;">🔑 <span data-i18n="Anmelden">Anmelden</span></button>
        </form>
    </div>
</div>
