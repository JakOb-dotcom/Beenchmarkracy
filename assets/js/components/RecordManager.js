/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// AUFZEICHNUNGEN (RECORDS)
// ══════════════════════════════════════════════════════

let currentRecordSettings = [];
let currentHives = [];

document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('new-record-date');
    if (dateInput) {
        dateInput.valueAsDate = new Date();
    }
    window.updateRecordUnitOptions();
});

// Einheiten-Auswahl an die gewählte Kategorie anpassen
// (war früher ein Inline-Script in tab_aufzeichnungen.php)
window.updateRecordUnitOptions = function() {
    const catSelect = document.getElementById('record-category-select');
    const unitSelect = document.getElementById('record-setting-unit');
    const feedInputs = document.getElementById('feed-recipe-inputs');
    if (!catSelect || !unitSelect) return;

    const cat = catSelect.value;
    if (feedInputs) {
        if (cat === 'feed') {
            feedInputs.style.display = 'flex';
        } else {
            feedInputs.style.display = 'none';
            const sugar = document.getElementById('record-setting-sugar');
            const water = document.getElementById('record-setting-water');
            if (sugar) sugar.value = '';
            if (water) water.value = '';
        }
    }

    unitSelect.innerHTML = '';
    let options = [];
    if (cat === 'harvest') options = ['kg'];
    else if (cat === 'feed') options = ['l', 'kg'];
    else if (cat === 'varroa') options = ['ml', 'g', 'Stk'];

    options.forEach(opt => {
        const el = document.createElement('option');
        el.value = opt; el.textContent = opt;
        unitSelect.appendChild(el);
    });
};

window.loadAufzeichnungen = async function() {
    await window.loadRecordSettings();
    await window.loadRecordHives();
    await window.loadRecords();
    window.updateRecordFormUI();
    window.loadOcrJobs();
};

window.loadRecordSettings = async function() {
    try {
        const response = await window.apiFetch('record_settings_get');
        if (response && response.success) {
            currentRecordSettings = response.settings || [];
            window.renderRecordSettings();
        }
    } catch (err) {
        console.error("Fehler beim Laden der Kategorien:", err);
    }
};

window.renderRecordSettings = function() {
    const list = document.getElementById('record-settings-list');
    if (!list) return;

    if (currentRecordSettings.length === 0) {
        list.innerHTML = `<p style="color:var(--text-muted); font-size: 0.9rem;">${t('Noch keine Kategorien definiert.')}</p>`;
    } else {
        const grouped = { 'harvest': [], 'feed': [], 'varroa': [] };
        currentRecordSettings.forEach(s => {
            if (grouped[s.category]) grouped[s.category].push(s);
        });

        const badges = { 'harvest': '🍯', 'feed': '🌾', 'varroa': '🔬' };

        let html = '';
        for (const cat in grouped) {
            if (grouped[cat].length === 0) continue;
            html += '<div style="margin-bottom: 8px;">';
            grouped[cat].forEach(s => {
                const shortStr = s.short_code
                    ? `<strong style="color:var(--primary); margin-left: 4px;">(${window.esc(s.short_code)})</strong>`
                    : '';
                const unitStr = s.unit
                    ? `<span style="font-size:0.75rem; color:#666;">[${window.esc(s.unit)}]</span>`
                    : '';
                html += `
                    <span style="display:inline-block; background:rgba(0,0,0,0.05); border:1px solid var(--border-color); border-radius: 20px; padding: 2px 10px; font-size: 0.8rem; margin: 2px;">
                        ${badges[cat] || ''} ${window.esc(s.name)} ${shortStr} ${unitStr}
                        <span onclick="window.deleteRecordSetting(${s.id})" style="color:var(--danger-color); cursor:pointer; margin-left:5px; font-weight:bold;" title="${t('Löschen')}">&times;</span>
                    </span>
                `;
            });
            html += '</div>';
        }
        list.innerHTML = html;
    }
    window.updateRecordFormUI();
};

window.addRecordSetting = async function() {
    const category = document.getElementById('record-category-select').value;
    const nameInput = document.getElementById('record-setting-name');
    const shortInput = document.getElementById('record-setting-short');
    const unitSelect = document.getElementById('record-setting-unit');
    const sugarInput = document.getElementById('record-setting-sugar');
    const waterInput = document.getElementById('record-setting-water');

    const name = nameInput.value.trim();
    const short_code = shortInput ? shortInput.value.trim() : null;
    const unit = unitSelect ? unitSelect.value : null;
    const sugar_g = (category === 'feed' && sugarInput && sugarInput.value) ? sugarInput.value : null;
    const water_ml = (category === 'feed' && waterInput && waterInput.value) ? waterInput.value : null;

    if (!name) {
        window.toast(t('Bitte Bezeichnung eingeben.'), "error");
        return;
    }

    try {
        await window.apiFetch('record_setting_add', 'POST', { category, name, short_code, unit, sugar_g, water_ml });
        window.toast(t('Kategorie gespeichert.'), "success");
        nameInput.value = '';
        if (shortInput) shortInput.value = '';
        if (sugarInput) sugarInput.value = '';
        if (waterInput) waterInput.value = '';
        await window.loadRecordSettings();
    } catch (err) {
        window.toast(t('Fehler beim Speichern der Kategorie.'), "error");
        console.error(err);
    }
};

window.deleteRecordSetting = async function(id) {
    if (!confirm(t('Diese Kategorie wirklich löschen?'))) return;
    try {
        await window.apiFetch('record_setting_delete', 'POST', { id });
        window.toast(t('Kategorie gelöscht.'), "success");
        await window.loadRecordSettings();
    } catch (err) {
        window.toast(t('Fehler beim Löschen.'), "error");
    }
};

window.loadRecordHives = async function() {
    try {
        const response = await window.apiFetch('hives_get_all');
        if (response && response.success) {
            currentHives = response.hives || [];

            const locMap = {};
            currentHives.forEach(h => {
                if (!locMap[h.location_id]) locMap[h.location_id] = { id: h.location_id, name: h.location_name, hives: [] };
                locMap[h.location_id].hives.push(h);
            });

            const selectLocation = document.getElementById('new-record-location');
            const selectFilterLoc = document.getElementById('filter-record-location');

            if (selectLocation) {
                const prev = selectLocation.value;
                let options = `<option value="">${t('-- Bienenstand wählen --')}</option>`;
                Object.values(locMap).forEach(l => {
                    options += `<option value="${l.id}">${window.esc(l.name)}</option>`;
                });
                selectLocation.innerHTML = options;
                if (prev) selectLocation.value = prev;
            }

            if (selectFilterLoc) {
                const prev = selectFilterLoc.value;
                let options = `<option value="">${t('Alle Standorte')}</option>`;
                Object.values(locMap).forEach(l => {
                    options += `<option value="${l.id}">${window.esc(l.name)}</option>`;
                });
                selectFilterLoc.innerHTML = options;
                if (prev) selectFilterLoc.value = prev;
            }

            window.loadRecordsOptions();
        }
    } catch (err) {
        console.error("Fehler beim Laden der Völker:", err);
    }
};

window.loadRecordsOptions = function() {
    const locId = document.getElementById('filter-record-location')?.value;
    const selectFilterHive = document.getElementById('filter-record-hive');
    if (!selectFilterHive) return;

    const filteredHives = locId ? currentHives.filter(h => h.location_id == locId) : currentHives;

    const prev = selectFilterHive.value;
    let options = `<option value="">${t('Alle Völker')}</option>`;
    filteredHives.forEach(h => {
        options += `<option value="${h.id}">${window.esc(h.name)} (${window.esc(h.location_name)})</option>`;
    });
    selectFilterHive.innerHTML = options;
    if (prev && filteredHives.some(h => String(h.id) === String(prev))) selectFilterHive.value = prev;
};

window.updateRecordFormUI = function() {
    const typeSelect = document.getElementById("new-record-type");
    if (!typeSelect) return;
    const type = typeSelect.value;
    const selectSetting = document.getElementById("new-record-setting");
    const divSetting = document.getElementById("div-record-setting");
    const divGlobalAmount = document.getElementById("div-global-amount");

    if (type === "stockkarte") {
        if (divSetting) divSetting.style.display = "none";
        if (divGlobalAmount) divGlobalAmount.style.display = "none";
    } else {
        if (divSetting) divSetting.style.display = "block";
        if (divGlobalAmount) divGlobalAmount.style.display = "block";

        if (selectSetting) {
            const relevantSettings = currentRecordSettings.filter(s => s.category === type);
            if (relevantSettings.length === 0) {
                selectSetting.innerHTML = `<option value="">${t('-- Keine vordefiniert --')}</option>`;
            } else {
                selectSetting.innerHTML = relevantSettings.map(s => {
                    const shortLabel = s.short_code ? `[${window.esc(s.short_code)}]` : '';
                    return `<option value="${s.id}">${window.esc(s.name)} ${shortLabel}</option>`;
                }).join('');
            }
        }
    }
    window.renderBulkGrid();
};

window.renderBulkGrid = function() {
    const tbody = document.getElementById('bulk-hives-body');
    if (!tbody) return;
    const locId = document.getElementById('new-record-location').value;
    const type = document.getElementById('new-record-type').value;
    const settingId = document.getElementById('new-record-setting').value;

    if (!locId) {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--text-muted);">${t('Bitte wähle einen Bienenstand.')}</td></tr>`;
        return;
    }

    let currentUnit = '-';
    if (settingId && type !== 'stockkarte') {
        const settingObj = currentRecordSettings.find(s => s.id == settingId);
        if (settingObj && settingObj.unit) currentUnit = settingObj.unit;
    }

    const hivesAtLoc = currentHives.filter(h => h.location_id == locId);

    if (hivesAtLoc.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--text-muted);">${t('Keine Völker an diesem Stand gefunden.')}</td></tr>`;
        return;
    }

    const globalAmount = document.getElementById('new-record-global-amount')?.value ?? '';

    let html = '';
    hivesAtLoc.forEach(h => {
        if (type === 'stockkarte') {
            html += `
                <tr data-hive-id="${h.id}" class="bulk-row">
                    <td style="font-weight: bold; width: 30%;">${window.esc(h.name)}</td>
                    <td colspan="2">
                        <div style="display:flex; gap:10px; flex-wrap:wrap; font-size:0.85rem;">
                             <label title="${t('Königin legt nicht')}"><input type="checkbox" class="bulk-kl"> KL</label>
                             <label title="${t('Schwarmstimmung')}"><input type="checkbox" class="bulk-ss"> SS</label>
                             <label title="${t('Schwarm')}"><input type="checkbox" class="bulk-sw"> SW</label>
                             <input type="number" class="form-control bulk-hr" placeholder="HR" title="${t('Honigräume (Anzahl)')}" style="width:70px; padding:2px;">
                             <input type="number" class="form-control bulk-bw" placeholder="BW" title="${t('Brutwaben entnommen (Anzahl)')}" style="width:70px; padding:2px;">
                        </div>
                    </td>
                </tr>
            `;
        } else {
            html += `
                <tr data-hive-id="${h.id}" class="bulk-row">
                    <td style="font-weight: bold; width: 40%;">${window.esc(h.name)}</td>
                    <td><input value="${window.esc(globalAmount)}" type="number" step="0.1" class="form-control bulk-amount" placeholder="${t('Menge')}" style="width: 100px; min-width: 100px;"></td>
                    <td class="bulk-unit">${window.esc(currentUnit)}</td>
                </tr>
            `;
        }
    });
    tbody.innerHTML = html;
};

window.applyGlobalAmount = function() {
    const globalVal = document.getElementById('new-record-global-amount').value;
    document.querySelectorAll('.bulk-amount').forEach(input => {
        input.value = globalVal;
    });
};

// ──────────────────────────────────────────────────────
// DRUCKFORMULARE
// WICHTIG: Die gedruckten Formulare bleiben bewusst auf Deutsch.
// Die OCR-Pipeline (PythonWorkerOCR/core/htr.py) erkennt Formulartyp
// und Spalten anhand der deutschen Überschriften (z.B. HONIGERNTE,
// VOLK ID, NOTIZ / BEMERKUNG, DAT:). Nicht übersetzen!
// ──────────────────────────────────────────────────────

window.generatePrintableRecordForm = function() {
    const typeSelect = document.getElementById('new-record-type');
    const type = typeSelect.value;
    const locId = document.getElementById('new-record-location').value;

    let hivesToPrint = currentHives;
    if (locId) {
        hivesToPrint = currentHives.filter(h => h.location_id == locId);
    }

    if (hivesToPrint.length === 0) {
        window.toast(t('Keine Völker gefunden.'), "error");
        return;
    }

    // Deutscher Formulartitel unabhängig von der UI-Sprache (OCR-Anker)
    const printTitles = { harvest: 'Honigernte', feed: 'Einfütterung', varroa: 'Varroabehandlung' };
    const typeName = printTitles[type] || 'Aufzeichnung';

    const settingsForType = currentRecordSettings.filter(s => s.category === type);
    const settingsHtml = settingsForType.map(s => {
        const sName = s.short_code ? s.short_code : s.name.substring(0, 3);
        return '<label style="margin-right: 20px; display: inline-flex; align-items: center; font-size: 1.2em; font-weight: bold; font-family: monospace;">' +
              '<div style="width: 25px; height: 25px; border: 3px solid #000; margin-right: 10px; display: inline-block;"></div> ' + window.esc(sName) +
          '</label>';
    }).join('');

    let trs = '';
    for (let i = 0; i < 16; i++) {
        trs += `<tr>
            <td style="border: 3px solid #000; padding: 25px; font-size: 1.4em; font-family: monospace; font-weight: bold; text-align: center;"></td>
            <td style="border: 3px solid #000; padding: 15px;"></td>
            <td style="border: 3px solid #000; padding: 15px;"></td>
        </tr>`;
    }

    const headerStr = type === 'harvest' ? 'ERNTEMENGE' : (type === 'feed' ? 'FUTTERMENGE' : 'MENGE / DOSIS');

    window.printHtmlDocument(`
        <html>
        <head>
            <meta charset="UTF-8">
            <title>OCR Formular: ${window.esc(typeName)}</title>
            <style>
                body { font-family: monospace; padding: 40px; margin: 0; color: #000; background: #fff; line-height: 1.2; box-sizing: border-box; }
                table { width: 100%; border-collapse: collapse; margin-top: 40px; border: 4px solid #000; }
                th { border: 3px solid #000; padding: 15px; text-align: center; font-size: 1.3em; background-color: #f2f2f2; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; margin-top: 20px; }
                .corner-left-top { position: absolute; top: 15px; left: 15px; width: 40px; height: 40px; border-top: 6px solid #000; border-left: 6px solid #000; }
                .corner-right-top { position: absolute; top: 15px; right: 15px; width: 40px; height: 40px; border-top: 6px solid #000; border-right: 6px solid #000; }
                .corner-left-bottom { position: fixed; bottom: 15px; left: 15px; width: 40px; height: 40px; border-bottom: 6px solid #000; border-left: 6px solid #000; }
                .corner-right-bottom { position: fixed; bottom: 15px; right: 15px; width: 40px; height: 40px; border-bottom: 6px solid #000; border-right: 6px solid #000; }
            </style>
        </head>
        <body>
            <div class="corner-left-top"></div><div class="corner-right-top"></div>
            <div class="header">
                <div style="flex: 1; padding-left: 20px;">
                    <h1 style="margin: 0 0 20px 0; font-size: 2.5em; text-transform: uppercase; letter-spacing: 2px;">${window.esc(typeName)}</h1>
                    <div style="font-size: 1.5em; border: 3px solid #000; padding: 10px; width: 300px; margin-bottom: 10px;">DAT: </div>
                    <div style="font-size: 1em; font-style: italic;">OCR-bereites Formular</div>
                </div>
                <div style="width: 45%; border: 4px solid #000; padding: 20px; background: #fff; margin-right: 20px;">
                    <div style="font-weight: bold; margin-bottom: 20px; font-size: 1.4em; border-bottom: 2px solid #000; padding-bottom: 10px;">KATEGORIE (NUR EINE):</div>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        ${settingsHtml || '<em>Keine Kategorien in der Datenbank</em>'}
                    </div>
                </div>
            </div>
            <table style="margin-left: 20px; width: calc(100% - 40px);">
                <thead>
                    <tr>
                        <th style="width: 25%;">VOLK ID</th>
                        <th style="width: 35%;">${window.esc(headerStr)}</th>
                        <th style="width: 40%;">NOTIZ / BEMERKUNG</th>
                    </tr>
                </thead>
                <tbody>
                    ${trs}
                </tbody>
            </table>
            <div class="corner-left-bottom"></div><div class="corner-right-bottom"></div>
        </body>
        </html>
    `);
};

window.generatePrintableStockkarte = function() {
    let trs = '';
    for (let i = 0; i < 16; i++) {
        trs += `<tr>
            <td style="border: 3px solid #000; padding: 20px; font-size: 1.4em; font-family: monospace; font-weight: bold; text-align: center;"></td>
            <td style="border: 3px solid #000; padding: 15px;"></td>
            <td style="border: 3px solid #000; padding: 15px;"></td>
            <td style="border: 3px solid #000; padding: 15px;"></td>
            <td style="border: 3px solid #000; padding: 15px;"></td>
            <td style="border: 3px solid #000; padding: 15px;"></td>
        </tr>`;
    }

    const html = `
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Stockkarte / Aufzeichnungen</title>
        <style>
            body { font-family: monospace; padding: 40px; margin: 0; color: #000; background: #fff; line-height: 1.2; box-sizing: border-box; }
            table { width: 100%; border-collapse: collapse; margin-top: 40px; border: 4px solid #000; }
            th { border: 3px solid #000; padding: 10px; text-align: center; font-size: 1.1em; background-color: #f2f2f2; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; margin-top: 20px; }
            .corner-left-top { position: absolute; top: 15px; left: 15px; width: 40px; height: 40px; border-top: 6px solid #000; border-left: 6px solid #000; }
            .corner-right-top { position: absolute; top: 15px; right: 15px; width: 40px; height: 40px; border-top: 6px solid #000; border-right: 6px solid #000; }
            .corner-left-bottom { position: fixed; bottom: 15px; left: 15px; width: 40px; height: 40px; border-bottom: 6px solid #000; border-left: 6px solid #000; }
            .corner-right-bottom { position: fixed; bottom: 15px; right: 15px; width: 40px; height: 40px; border-bottom: 6px solid #000; border-right: 6px solid #000; }
            @media print { button.print-btn { display: none !important; } }
        </style>
    </head>
    <body>
        <div class="corner-left-top"></div><div class="corner-right-top"></div>
        <div class="header">
            <div style="flex: 1; padding-left: 20px;">
                <h1 style="margin: 0 0 20px 0; font-size: 2.5em; text-transform: uppercase; letter-spacing: 2px;">STOCKKARTE</h1>
                <div style="font-size: 1.5em; border: 3px solid #000; padding: 10px; width: 300px; margin-bottom: 10px;">DAT: </div>
                <div style="font-size: 1em; font-style: italic;">OCR-bereites Formular</div>
            </div>
            <div style="width: 45%; border: 4px solid #000; padding: 20px; background: #fff; margin-right: 20px;">
                <div style="font-weight: bold; margin-bottom: 10px; font-size: 1.2em;">Hinweise:</div>
                <div style="font-size: 0.9em; line-height: 1.5;">
                    - Setzen Sie ein <strong style="font-size: 1.2em;">X</strong> in die K&auml;stchen (KL, SS, SW)<br>
                    - Tragen Sie Zahlen in HR (Honigr&auml;ume) und BW (Brutwaben entnommen) ein.<br>
                    - Leere X-Felder = Nein, leere Zahlen = 0
                </div>
            </div>
        </div>
        <table style="margin-left: 20px; width: calc(100% - 40px);">
            <thead>
                <tr>
                    <th style="width: 15%;">VOLK ID</th>
                    <th style="width: 13%;">K&ouml;nigin legt<br>nicht (X)</th>
                    <th style="width: 13%;">Schwarm-<br>stimmung (X)</th>
                    <th style="width: 13%;">Schwarm (X)</th>
                    <th style="width: 23%;">Akt. Honigr&auml;ume<br>(Zahl)</th>
                    <th style="width: 23%;">BW entnommen<br>(Zahl)</th>
                </tr>
            </thead>
            <tbody>
                ${trs}
            </tbody>
        </table>
        <div class="corner-left-bottom"></div><div class="corner-right-bottom"></div>
    </body>
    </html>
    `;

    window.printHtmlDocument(html);
};

window.addRecordsBulk = async function() {
    const locId = document.getElementById('new-record-location').value;
    const date = document.getElementById('new-record-date').value;
    const type = document.getElementById('new-record-type').value;
    const settingId = document.getElementById('new-record-setting').value;
    const globalNotes = document.getElementById('new-record-notes').value;

    if (!locId || !date || !type) {
        window.toast(t('Standort, Datum und Aktion sind Pflichtfelder.'), "error");
        return;
    }

    let currentUnit = null;
    if (settingId) {
        const settingObj = currentRecordSettings.find(s => s.id == settingId);
        if (settingObj && settingObj.unit) currentUnit = settingObj.unit;
    }

    const records = [];
    document.querySelectorAll('#bulk-hives-body tr.bulk-row').forEach(tr => {
        const hiveId = tr.getAttribute('data-hive-id');
        let amount = null;
        let notes = globalNotes;

        if (type === 'stockkarte') {
            const kl = tr.querySelector('.bulk-kl').checked;
            const ss = tr.querySelector('.bulk-ss').checked;
            const sw = tr.querySelector('.bulk-sw').checked;
            const hr = tr.querySelector('.bulk-hr').value.trim();
            const bw = tr.querySelector('.bulk-bw').value.trim();

            const statusParts = [];
            if (kl) statusParts.push("KL:X");
            if (ss) statusParts.push("SS:X");
            if (sw) statusParts.push("SW:X");
            if (hr) statusParts.push(`HR:${hr}`);
            if (bw) statusParts.push(`BW:${bw}`);

            const statusStr = statusParts.join(" ");
            if (statusStr) notes = `[Stockkarte] ${statusStr} ${notes}`.trim();
        } else {
            const amountInput = tr.querySelector('.bulk-amount').value;
            amount = amountInput.trim() !== '' ? amountInput : null;
        }

        records.push({
            hive_id: hiveId,
            record_date: date,
            type: type === 'stockkarte' ? 'status' : type,
            setting_id: (settingId && type !== 'stockkarte') ? settingId : null,
            amount: amount,
            unit: type === 'stockkarte' ? '-' : currentUnit,
            notes: notes
        });
    });

    if (records.length === 0) {
        window.toast(t('Keine Völker zu speichern.'), "error");
        return;
    }

    try {
        await window.apiFetch('record_add', 'POST', { records });
        window.toast(t('Aufzeichnungen gespeichert!'), "success");

        document.getElementById('new-record-notes').value = '';
        document.querySelectorAll('.bulk-amount').forEach(i => i.value = '');

        window.loadRecords();
    } catch (err) {
        window.toast(t('Fehler beim Speichern.'), "error");
        console.error(err);
    }
};

// Anzeige-Labels für Aufzeichnungstypen
function recordTypeBadges() {
    return {
        'harvest': '🍯 ' + t('Honigernte'),
        'feed': '🌾 ' + t('Fütterung'),
        'varroa': '🔬 ' + t('Varroa'),
        'varroa_drop': '🔴 ' + t('Varroaabfall (KI-Scan)'),
        'status': '📋 ' + t('Stockkarte')
    };
}

window.loadRecords = async function() {
    const tbody = document.getElementById('records-list-body');
    if (!tbody) return;

    const locFilter = document.getElementById('filter-record-location')?.value ?? '';
    const hiveFilter = document.getElementById('filter-record-hive')?.value ?? '';
    const typeFilter = document.getElementById('filter-record-type')?.value ?? '';

    let url = 'records_get';
    if (locFilter) url += `&location_id=${encodeURIComponent(locFilter)}`;
    if (hiveFilter) url += `&hive_id=${encodeURIComponent(hiveFilter)}`;
    if (typeFilter) url += `&type=${encodeURIComponent(typeFilter)}`;

    try {
        const res = await window.apiFetch(url);
        if (!res.success || !res.records) return;

        if (res.records.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--text-muted);">${t('Keine Aufzeichnungen gefunden.')}</td></tr>`;
            return;
        }

        const badges = recordTypeBadges();

        let html = '';
        res.records.forEach(r => {
            const dateObj = new Date(r.record_date);
            const dateStr = isNaN(dateObj) ? r.record_date : dateObj.toLocaleDateString(window.uiLocale());

            let amountStr = '-';
            if (r.amount !== null && r.amount !== undefined) {
                amountStr = r.amount + ' ' + (r.unit || '');
            }

            const settingShortStr = r.setting_short_code
                ? `<strong>(${window.esc(r.setting_short_code)})</strong>`
                : '';
            let recipeStr = '';
            if (r.type === 'feed' && r.sugar_g && r.water_ml) {
                recipeStr = `<br><span style="font-size:0.75rem; color:#888; display: block; margin-top: 3px;">(${window.esc(r.sugar_g)}g ${t('Zucker')} / ${window.esc(r.water_ml)}ml ${t('Wasser')})</span>`;
            }

            const settingNameDisplay = (r.type === 'varroa_drop' && !r.setting_name)
                ? t('Bodenschieber-Auswertung')
                : (r.setting_name || '-');

            html += `<tr data-record-id="${r.id}">
                <td>${dateStr}</td>
                <td style="font-weight:bold; color:var(--primary);">${window.esc(r.hive_name)} <span style="font-size:0.8rem; font-weight:normal; color:var(--text-muted);">(${window.esc(r.location_name)})</span></td>
                <td>${badges[r.type] || window.esc(r.type)}</td>
                <td>${window.esc(settingNameDisplay)} ${settingShortStr}${recipeStr}</td>
                <td>${window.esc(amountStr)}</td>
                <td>${window.esc(r.notes || '')}</td>
                <td style="text-align:right;">
                    <button class="btn-icon primary record-edit-btn" data-id="${r.id}" data-date="${window.esc(r.record_date)}" data-amount="${r.amount ?? ''}" data-unit="${window.esc(r.unit || '')}" data-notes="${window.esc(r.notes || '')}" style="padding:0; font-size:1.1rem; margin-right: 5px;" title="${t('Bearbeiten')}">&#x270E;</button>
                    <button class="btn-icon danger record-delete-btn" data-id="${r.id}" style="padding:0; font-size:1.1rem;" title="${t('Löschen')}">&#x1F5D1;&#xFE0F;</button>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;

        tbody.querySelectorAll('.record-edit-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                window.editRecordModal(
                    btn.dataset.id,
                    btn.dataset.date,
                    btn.dataset.amount,
                    btn.dataset.unit,
                    btn.dataset.notes
                );
            });
        });

        tbody.querySelectorAll('.record-delete-btn').forEach(btn => {
            btn.addEventListener('click', () => window.deleteRecord(btn.dataset.id));
        });

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger-color);">${t('Fehler beim Laden.')}</td></tr>`;
        console.error(err);
    }
};

window.editRecordModal = function(id, date, amount, unit, notes) {
    document.getElementById('edit-record-id').value = id;
    document.getElementById('edit-record-date').value = date;
    document.getElementById('edit-record-amount').value = (amount === '' || amount === null || amount === undefined) ? '' : amount;
    document.getElementById('edit-record-unit').value = unit || '';
    document.getElementById('edit-record-notes').value = notes || '';
    document.getElementById('edit-record-modal').style.display = 'flex';
};

window.saveRecordEdit = async function() {
    const id = document.getElementById('edit-record-id').value;
    const date = document.getElementById('edit-record-date').value;
    const amount = document.getElementById('edit-record-amount').value;
    const unit = document.getElementById('edit-record-unit').value;
    const notes = document.getElementById('edit-record-notes').value;

    if (!id || !date) {
        window.toast(t('Datum wird benötigt.'), "warning");
        return;
    }

    try {
        const res = await window.apiFetch('record_edit', 'POST', { id, record_date: date, amount, unit, notes });
        if (res.success) {
            window.toast(t('Eintrag aktualisiert!'), "success");
            document.getElementById('edit-record-modal').style.display = 'none';
            window.loadRecords();
        } else {
            window.toast(t('Fehler beim Speichern:') + ' ' + (res.error || ''), "error");
        }
    } catch (err) {
        console.error(err);
        window.toast(t('Netzwerkfehler'), "error");
    }
};

// ──────────────────────────────────────────────────────
// OCR-UPLOAD & JOB-STATUS
// Der Upload legt serverseitig einen Job an und startet den Python-Worker.
// Danach wird der Status einige Male abgefragt, bis der Job fertig ist.
// ──────────────────────────────────────────────────────

let ocrPollTimer = null;

window.uploadOcrImage = async function() {
    const input = document.getElementById('ocr-upload-input');
    const btn = document.getElementById('ocr-upload-btn');
    const file = input.files[0];
    if (!file) {
        window.toast(t('Bitte wähle zuerst ein Foto (JPEG/PNG) aus.'), "warning");
        return;
    }

    const maxBytes = 20 * 1024 * 1024;
    if (file.size > maxBytes) {
        window.toast(t('Die Datei ist zu groß (max. 20 MB).'), "error");
        return;
    }
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        window.toast(t('Nur JPEG- oder PNG-Bilder werden unterstützt.'), "error");
        return;
    }

    const formData = new FormData();
    formData.append('ocrUpload', file);

    if (btn) btn.disabled = true;
    try {
        const data = await window.apiUpload('record_upload_ocr', formData);
        if (data.success) {
            window.toast(t('Upload erfolgreich! Die OCR-Auswertung läuft im Hintergrund.'), "success");
            input.value = '';
            await window.loadOcrJobs();
            window.startOcrJobPolling();
        } else {
            window.toast(t('Upload-Fehler:') + ' ' + (data.error || t('Unbekannt')), "error");
        }
    } catch (err) {
        console.error("Upload failed", err);
        window.toast(t('Upload-Fehler:') + ' ' + (err.message || t('Fehler beim Hochladen.')), "error");
    } finally {
        if (btn) btn.disabled = false;
    }
};

window.loadOcrJobs = async function() {
    const container = document.getElementById('ocr-jobs-list');
    if (!container) return [];
    try {
        const res = await window.apiFetch('ocr_jobs_get&limit=8');
        const jobs = (res && res.jobs) ? res.jobs : [];
        window.renderOcrJobs(jobs);
        return jobs;
    } catch (err) {
        console.error('OCR-Jobs konnten nicht geladen werden:', err);
        return [];
    }
};

window.renderOcrJobs = function(jobs) {
    const container = document.getElementById('ocr-jobs-list');
    if (!container) return;
    if (!jobs || jobs.length === 0) {
        container.innerHTML = `<p style="color:var(--text-muted); font-size:0.8rem; margin:0;">${t('Noch keine Uploads.')}</p>`;
        return;
    }
    const icons = { pending: '⏳', processing: '⚙️', done: '✅', failed: '❌' };
    const labels = {
        pending: t('Wartet'),
        processing: t('In Bearbeitung'),
        done: t('Fertig'),
        failed: t('Fehlgeschlagen')
    };
    container.innerHTML = jobs.map(j => {
        const dateObj = new Date(String(j.created_at).replace(' ', 'T'));
        const when = isNaN(dateObj) ? j.created_at : dateObj.toLocaleString(window.uiLocale(), { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        const color = j.status === 'done' ? 'var(--success)' : (j.status === 'failed' ? 'var(--danger)' : 'var(--text-muted)');
        return `<div style="font-size:0.8rem; padding:4px 0; border-bottom:1px solid var(--border-color);">
            <span title="${labels[j.status] || ''}">${icons[j.status] || '•'}</span>
            <span style="color:var(--text-muted);">${when}</span>
            <strong style="color:${color};">${labels[j.status] || window.esc(j.status)}</strong>
            ${j.message ? `<div style="color:var(--text-muted); margin-left:1.4rem;">${window.esc(j.message)}</div>` : ''}
        </div>`;
    }).join('');
};

window.startOcrJobPolling = function() {
    if (ocrPollTimer) clearInterval(ocrPollTimer);
    let ticks = 0;
    ocrPollTimer = setInterval(async () => {
        ticks++;
        const jobs = await window.loadOcrJobs();
        const busy = jobs.some(j => j.status === 'pending' || j.status === 'processing');
        if (!busy) {
            clearInterval(ocrPollTimer);
            ocrPollTimer = null;
            window.loadRecords();
        } else if (ticks >= 60) { // max. 5 Minuten
            clearInterval(ocrPollTimer);
            ocrPollTimer = null;
        }
    }, 5000);
};

window.deleteRecord = async function(id) {
    if (!confirm(t('Eintrag wirklich löschen?'))) return;
    try {
        await window.apiFetch('record_delete', 'POST', { id });
        window.loadRecords();
    } catch (err) {
        window.toast(t('Fehler beim Löschen.'), "error");
    }
};

window.exportRecordsCSV = async function() {
    const locFilter = document.getElementById('filter-record-location')?.value ?? '';
    const hiveFilter = document.getElementById('filter-record-hive')?.value ?? '';
    const typeFilter = document.getElementById('filter-record-type')?.value ?? '';

    let url = 'records_get&export=1';
    if (locFilter) url += `&location_id=${encodeURIComponent(locFilter)}`;
    if (hiveFilter) url += `&hive_id=${encodeURIComponent(hiveFilter)}`;
    if (typeFilter) url += `&type=${encodeURIComponent(typeFilter)}`;

    try {
        window.toast(t('Exportiere Daten...'), "info");
        const res = await window.apiFetch(url);
        if (!res.success || !res.records) {
            window.toast(t('Fehler beim Abrufen der Export-Daten'), "error");
            return;
        }

        if (res.records.length === 0) {
            window.toast(t('Keine Daten zum Exportieren gefunden.'), "warning");
            return;
        }

        const badges = {
            'harvest': t('Honigernte'),
            'feed': t('Fütterung'),
            'varroa': t('Varroa'),
            'varroa_drop': t('Varroaabfall (KI-Scan)'),
            'status': t('Stockkarte')
        };

        const csvRows = [
            [t('Datum'), t('Standort'), t('Volk'), t('Aktion'), t('Kategorie'), t('Kategorie (Kurz)'), t('Menge'), t('Einheit'), t('Zucker (g)'), t('Wasser (ml)'), t('Notiz')].join(";")
        ];

        res.records.forEach(r => {
            const dateObj = new Date(r.record_date);
            const dateStr = isNaN(dateObj) ? r.record_date : dateObj.toLocaleDateString(window.uiLocale());
            const row = [
                dateStr,
                r.location_name || '',
                r.hive_name || '',
                badges[r.type] || r.type,
                r.setting_name || '',
                r.setting_short_code || '',
                r.amount !== null && r.amount !== undefined ? String(r.amount).replace('.', ',') : '',
                r.unit || '',
                r.sugar_g || '',
                r.water_ml || '',
                (r.notes || '').replace(/;/g, ',').replace(/\n/g, ' ')
            ].map(s => '"' + String(s).replace(/"/g, '""') + '"');
            csvRows.push(row.join(";"));
        });

        const blob = new Blob(["﻿" + csvRows.join("\n")], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = `Aufzeichnungen_Export_${new Date().toISOString().split('T')[0]}.csv`;
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    } catch (err) {
        console.error("Export Error:", err);
        window.toast(t('Export fehlgeschlagen.'), "error");
    }
};
