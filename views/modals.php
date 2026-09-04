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
<!-- ═══ TOAST ═══ -->
<div id="toast-container" class="toast-container"></div>

<!-- ═══ LOADING ═══ -->
<div id="loading-overlay" class="loading-overlay" style="display:none">
    <div style="background: white; padding: 2rem; border-radius: var(--radius); text-align: center; max-width: 400px; width: 90%; box-shadow: var(--shadow);">
        <div class="spinner" style="margin: 0 auto 1rem auto;"></div>
        <p id="loading-msg" style="font-weight: 600; margin-bottom: 1rem;" data-i18n="Laden...">Laden...</p>
        <div id="loading-progress-container" style="display:none; width: 100%; background: #e2e8f0; border-radius: 8px; height: 12px; overflow: hidden;">
            <div id="loading-progress-bar" style="height: 100%; width: 0%; background: var(--primary-color); transition: width 0.3s;"></div>
        </div>
    </div>
</div>

<!-- ═══ DAY DETAIL MODAL ═══ -->
<div id="day-detail-modal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeDayDetail()">
    <div class="modal" id="day-detail-content"></div>
</div>
