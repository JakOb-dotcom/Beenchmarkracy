/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// VÖLKER & ZUCHT (HIVES)
// ══════════════════════════════════════════════════════

let expandedHiveId = null;

function calcEvalAverages(evals) {
    let sumH = 0, sumG = 0, sumS = 0, sumW = 0, sumV = 0;
    let cH = 0, cG = 0, cS = 0, cW = 0, cV = 0;
    evals.forEach(ev => {
        if (ev.score_honey    !== null && ev.score_honey    !== undefined && ev.score_honey    !== '') { sumH += parseInt(ev.score_honey);    cH++; }
        if (ev.score_gentleness !== null && ev.score_gentleness !== undefined && ev.score_gentleness !== '') { sumG += parseInt(ev.score_gentleness); cG++; }
        if (ev.score_steadiness !== null && ev.score_steadiness !== undefined && ev.score_steadiness !== '') { sumS += parseInt(ev.score_steadiness); cS++; }
        if (ev.score_swarming !== null && ev.score_swarming !== undefined && ev.score_swarming !== '') { sumW += parseInt(ev.score_swarming); cW++; }
        if (ev.score_varroa   !== null && ev.score_varroa   !== undefined && ev.score_varroa   !== '') { sumV += parseInt(ev.score_varroa);   cV++; }
    });
    return {
        h: cH > 0 ? (sumH / cH) : null,
        g: cG > 0 ? (sumG / cG) : null,
        s: cS > 0 ? (sumS / cS) : null,
        w: cW > 0 ? (sumW / cW) : null,
        v: cV > 0 ? (sumV / cV) : null,
        count: evals.length
    };
}

function updateHiveSelectAllCheckboxState() {
    const box = document.getElementById('select-all-hives');
    if (!box) return;

    const total = Array.isArray(lastLoadedHiveIds) ? lastLoadedHiveIds.length : 0;
    if (total === 0) {
        box.checked = false;
        box.indeterminate = false;
        box.disabled = true;
        return;
    }

    box.disabled = false;
    let selectedCount = 0;
    for (const id of lastLoadedHiveIds) {
        if (selectedHiveIds.has(Number(id))) selectedCount++;
    }

    box.checked = selectedCount === total;
    box.indeterminate = selectedCount > 0 && selectedCount < total;
}

function toggleAllHivesSelection(checked) {
    if (!Array.isArray(lastLoadedHiveIds) || lastLoadedHiveIds.length === 0) return;

    if (checked) {
        for (const id of lastLoadedHiveIds) selectedHiveIds.add(Number(id));
    } else {
        for (const id of lastLoadedHiveIds) selectedHiveIds.delete(Number(id));
    }

    const list = document.getElementById('hives-list');
    if (list) {
        list.querySelectorAll('input.hive-select-checkbox').forEach(cb => {
            cb.checked = checked;
        });
    }

    updateHiveBulkDeleteButton();
    updateHiveSelectAllCheckboxState();
}

function updateHiveBulkDeleteButton() {
    const btn = document.getElementById('delete-selected-hives-btn');
    if (!btn) return;

    const count = selectedHiveIds.size;
    btn.disabled = count === 0;
    btn.textContent = '🗑️ ' + t('Ausgewählte löschen') + (count > 0 ? ` (${count})` : '');

    const geneticsBtn = document.getElementById('genetics-selected-hives-btn');
    if (geneticsBtn) {
        geneticsBtn.disabled = count === 0;
        geneticsBtn.textContent = '🧬 ' + t('Genetik setzen') + (count > 0 ? ` (${count})` : '');
    }

    updateHiveSelectAllCheckboxState();
}

function toggleHiveSelection(id, checked) {
    const numericId = Number(id);
    if (checked) selectedHiveIds.add(numericId);
    else selectedHiveIds.delete(numericId);
    updateHiveBulkDeleteButton();
    updateHiveSelectAllCheckboxState();
}

async function deleteSelectedHives() {
    if (selectedHiveIds.size === 0) return;

    const ids = [...selectedHiveIds];
    if (!confirm(`${ids.length} ${t('ausgewählte Völker wirklich löschen?')}`)) return;

    showLoading(t('Lösche ausgewählte Völker...'));
    try {
        for (const id of ids) {
            await apiFetch('hive_delete', 'POST', { hive_id: id });
        }

        selectedHiveIds.clear();
        await loadHives();
        toast(`${ids.length} ${t('Völker gelöscht')}`, 'success');
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Löschen der Völker'), 'error');
    } finally {
        hideLoading();
        updateHiveBulkDeleteButton();
    }
}

async function updateSelectedHivesGenetics() {
    if (selectedHiveIds.size === 0) return;

    const genetics = prompt(t('Welche Genetik soll für die ausgewählten Völker gesetzt werden?'), "Carnica");
    if (genetics === null) return;

    const ids = [...selectedHiveIds];
    showLoading(t('Setze Genetik...'));
    try {
        await apiFetch('hive_bulk_update_genetics', 'POST', {
            hive_ids: ids,
            genetics: genetics
        });
        await loadHives();
        toast(t('Genetik aktualisiert!'), 'success');
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Setzen der Genetik'), 'error');
    } finally {
        hideLoading();
    }
}

function toggleHiveDetails(id) {
    if (expandedHiveId === id) {
        expandedHiveId = null;
    } else {
        expandedHiveId = id;
    }
    loadHives(); // Liste nahtlos neu rendern
}

async function loadHives() {
    if (!state.selectedLocationId) return;

    const currentLocationId = Number(state.selectedLocationId);
    if (hiveSelectionLocationId !== currentLocationId) {
        selectedHiveIds.clear();
        hiveSelectionLocationId = currentLocationId;
        lastLoadedHiveIds = [];
    }

    const list = document.getElementById('hives-list');
    if (!list) return;

    if (list.innerHTML.trim() === '') {
        list.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Lade Völker...')}</p>`;
    }

    try {
        const response = await apiFetch(`hives_get&location_id=${state.selectedLocationId}`);
        if (!response || !response.success || !response.hives) {
            list.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Fehler beim Laden der Völker.')}</p>`;
            lastLoadedHiveIds = [];
            updateHiveBulkDeleteButton();
            return;
        }

        lastLoadedHiveIds = response.hives.map(hive => Number(hive.id));

        const existingHiveIds = new Set(response.hives.map(hive => Number(hive.id)));
        selectedHiveIds = new Set([...selectedHiveIds].filter(id => existingHiveIds.has(Number(id))));

        if (response.hives.length === 0) {
            list.innerHTML = `<p style="color:var(--text-muted);text-align:center;font-style:italic;">${t('Noch keine Völker an diesem Standort angelegt.')}</p>`;
            selectedHiveIds.clear();
            lastLoadedHiveIds = [];
            updateHiveBulkDeleteButton();
            return;
        }

        let html = '';
        response.hives.forEach(hive => {
            const evals = hive.evaluations || [];
            const avg = calcEvalAverages(evals);

            const txtH = avg.h !== null ? avg.h.toFixed(1) : '-';
            const txtG = avg.g !== null ? avg.g.toFixed(1) : '-';
            const txtS = avg.s !== null ? avg.s.toFixed(1) : '-';
            const txtW = avg.w !== null ? avg.w.toFixed(1) : '-';
            const txtV = avg.v !== null ? avg.v.toFixed(1) : '-';

            let notesHtml = '';
            if (hive.notes && hive.notes.length > 0) {
                hive.notes.forEach(note => {
                    const jsDateStr = note.created_at.replace(' ', 'T');
                    const dateStr = new Date(jsDateStr).toLocaleString(window.uiLocale(), {
                        day: '2-digit', month: '2-digit', year: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
                    notesHtml += `
                        <div style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:var(--radius); padding:0.5rem; margin-bottom:0.5rem; position:relative; font-size: 0.9rem;">
                            <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.25rem;">${dateStr}</div>
                            <div style="white-space:pre-wrap; margin-right: 2rem;">${esc(note.note)}</div>
                            <button onclick="deleteHiveNote(${note.id})" style="position:absolute; top:0.25rem; right:0.25rem; background:none; border:none; color:var(--danger-color); cursor:pointer; font-size:1rem;" title="${t('Notiz löschen')}">🗑️</button>
                        </div>
                    `;
                });
            }

            const isExpanded = expandedHiveId === hive.id;
            const displayStyle = isExpanded ? 'block' : 'none';
            const chevron = isExpanded ? '▼' : '▶';

            let evalsHtml = '';
            if (evals && evals.length > 0) {
                let tableRows = evals.map(ev => {
                    const d = ev.date ? new Date(ev.date).toLocaleDateString(window.uiLocale()) : '-';
                    return `<tr>
                        <td>${d}</td>
                        <td>${ev.score_honey || '-'}</td>
                        <td>${ev.score_gentleness || '-'}</td>
                        <td>${ev.score_steadiness || '-'}</td>
                        <td>${ev.score_swarming || '-'}</td>
                        <td>${ev.score_varroa || '-'}</td>
                        <td style="text-align:right;">
                            <button onclick="editHiveEvaluation(${ev.id}, ${ev.score_honey || 'null'}, ${ev.score_gentleness || 'null'}, ${ev.score_steadiness || 'null'}, ${ev.score_swarming || 'null'}, ${ev.score_varroa || 'null'})" class="btn-icon primary" style="padding:0; font-size:1.1rem; margin-right:0.5rem;" title="${t('Bearbeiten')}">✏️</button>
                            <button onclick="deleteHiveEvaluation(${ev.id})" class="btn-icon danger" style="padding:0; font-size:1.1rem;" title="${t('Löschen')}">🗑️</button>
                        </td>
                    </tr>`;
                }).join('');

                evalsHtml = `<div style="margin-bottom: 1.5rem;">
                    <label style="font-weight:bold; font-size:0.9rem; margin-bottom:0.5rem; display:block;">${t('Bisherige Zuchtwertschätzungen:')}</label>
                    <div style="border:1px solid var(--border-color); border-radius:var(--radius); overflow:hidden;">
                        <table class="data-table" style="width:100%; font-size:0.85rem; margin:0;">
                            <thead style="background:rgba(0,0,0,0.05);">
                                <tr>
                                    <th style="padding:0.4rem 0.8rem;">${t('Datum')}</th>
                                    <th style="padding:0.4rem 0.8rem;" title="${t('Honig')}">🍯</th>
                                    <th style="padding:0.4rem 0.8rem;" title="${t('Sanftmut')}">😇</th>
                                    <th style="padding:0.4rem 0.8rem;" title="${t('Wabensitz')}">🐝</th>
                                    <th style="padding:0.4rem 0.8rem;" title="${t('Schwarmneigung')}">🌪️</th>
                                    <th style="padding:0.4rem 0.8rem;" title="${t('Varroaresistenz')}">🔬</th>
                                    <th style="padding:0.4rem 0.8rem;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows}
                            </tbody>
                        </table>
                    </div>
                </div>`;
            } else {
                evalsHtml = `<div style="margin-bottom: 1.5rem;">
                    <label style="font-weight:bold; font-size:0.9rem; margin-bottom:0.5rem; display:block;">${t('Bisherige Zuchtwertschätzungen:')}</label>
                    <p style="color:var(--text-muted); font-size:0.85rem; font-style:italic;">${t('Noch keine Werte erfasst.')}</p>
                </div>`;
            }

            html += `
                <div class="card hive-card" style="margin-bottom: 0.5rem; padding: 0; width: 100%; max-width: 920px; margin-left: auto; margin-right: auto; display: flex; flex-direction: column;">
                    <div class="hive-card-header" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; cursor:pointer; padding:.8rem 1rem;" onclick="toggleHiveDetails(${hive.id})">

                        <div style="display:flex; align-items:center; gap:1rem; flex:1; min-width:0;">
                            <input class="hive-select-checkbox" data-hive-id="${hive.id}" type="checkbox" ${selectedHiveIds.has(Number(hive.id)) ? 'checked' : ''} onclick="event.stopPropagation()" onchange="toggleHiveSelection(${hive.id}, this.checked)" title="${t('Volk auswählen')}" style="width:18px !important; height:18px !important; padding:0; margin:0; flex: 0 0 18px; cursor:pointer;">

                            <div style="flex:1; min-width:0;">
                                <h4 style="margin:0; font-size:1.05rem; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    <span>${esc(hive.name)}</span>
                                </h4>
                                <div style="font-size:.8rem; color:var(--text-muted); margin-top:.35rem; line-height:1.35; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    Ø ${t('Honig')}: ${txtH} | ${t('Sanftmut')}: ${txtG} | ${t('Wabensitz')}: ${txtS} | ${t('Schwarm')}: ${txtW} | ${t('Varroa')}: ${txtV}
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; gap:0.8rem; flex: 0 0 auto;">
                            <button onclick="event.stopPropagation(); deleteHive(${hive.id})" class="btn-icon danger" title="${t('Volk löschen')}">🗑️</button>
                            <span style="font-size: 1.2rem; color: var(--text-muted); width: 20px; text-align:center;">${chevron}</span>
                        </div>
                    </div>

                    <div id="hive-details-${hive.id}" style="display: ${displayStyle}; border-top: 1px solid var(--border-color); padding: 1rem; background: rgba(0,0,0,0.02);" onclick="event.stopPropagation()">

                        <div style="margin-bottom: 1.5rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                            <div style="display:flex; gap:0.5rem; align-items:center;">
                                <label style="font-weight:bold; font-size:0.9rem; margin:0;">${t('Name')}:</label>
                                <input type="text" class="hive-name-input" data-id="${hive.id}" value="${esc(hive.name)}" onchange="updateHive(${hive.id})" style="width:200px; border:1px solid var(--border-color); padding:0.4rem; border-radius:var(--radius);">
                            </div>
                            <div style="display:flex; gap:0.5rem; align-items:center;">
                                <label style="font-weight:bold; font-size:0.9rem; margin:0;">${t('Genetik')}:</label>
                                <input type="text" class="hive-genetics-input" data-id="${hive.id}" value="${esc(hive.genetics || '')}" onchange="updateHive(${hive.id})" placeholder="z.B. Carnica" style="width:200px; border:1px solid var(--border-color); padding:0.4rem; border-radius:var(--radius);">
                            </div>
                            <div style="display:flex; gap:0.5rem; align-items:center; margin-left: auto;">
                                <label style="font-weight:bold; font-size:0.9rem; margin:0;">${t('Transferieren')}:</label>
                                <select id="transfer-loc-${hive.id}" style="width:150px; border:1px solid var(--border-color); padding:0.4rem; border-radius:var(--radius);">
                                    ${window.state.locations.filter(l => l.id !== hive.location_id).map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}
                                </select>
                                <button class="btn btn-sm btn-outline" onclick="transferHive(${hive.id})">${t('Verschieben')}</button>
                            </div>
                        </div>

                        ${evalsHtml}

                        <div>
                            ${notesHtml}
                        </div>
                    </div>
                </div>
            `;
        });
        list.innerHTML = html;
        updateHiveBulkDeleteButton();
    } catch (err) {
        console.error(err);
        list.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Fehler beim Laden der Völker.')}</p>`;
        updateHiveBulkDeleteButton();
    }
}

async function addHive() {
    if (!state.selectedLocationId) return;

    let countStr = prompt(t('Wie viele Völker möchtest du hier erstellen?'), "1");
    if (!countStr) return;
    let count = parseInt(countStr, 10);
    if (isNaN(count) || count < 1) {
        toast(t('Bitte eine gültige Zahl eingeben.'), "error");
        return;
    }

    try {
        const response = await apiFetch('hives_count_all');
        let startNum = 1;
        if (response && response.success && typeof response.count !== 'undefined') {
            startNum = response.count + 1;
        }

        let firstId = null;
        for (let i = 0; i < count; i++) {
            let res = await apiFetch('hive_create', 'POST', {
                location_id: state.selectedLocationId,
                name: "Volk " + (startNum + i)
            });
            if (i === 0 && res && res.id) firstId = res.id;
        }

        if (firstId) expandedHiveId = firstId;
        toast(count + " " + t('Volk/Völker erfolgreich erstellt.'), "success");
        await loadHives();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Erstellen der Völker'), "error");
    }
}

async function updateHive(id) {
    const nameInput = document.querySelector(`.hive-name-input[data-id="${id}"]`);
    const geneticsInput = document.querySelector(`.hive-genetics-input[data-id="${id}"]`);
    if (!nameInput) return;

    try {
        await apiFetch('hive_update', 'POST', {
            hive_id: id,
            name: nameInput.value.trim(),
            genetics: geneticsInput ? geneticsInput.value.trim() : undefined
        });
        toast(t('Volk aktualisiert'), "success");
        await loadHives();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Aktualisieren'), "error");
    }
}

async function transferHive(id) {
    const selector = document.getElementById(`transfer-loc-${id}`);
    if(!selector) return;
    const locId = selector.value;
    if(!locId) return;

    if(!confirm(t('Möchtest du das Volk wirklich verschieben?'))) return;
    try {
        await apiFetch('hive_transfer', 'POST', {
            hive_id: id,
            target_location_id: locId
        });
        toast(t('Volk erfolgreich transferiert'), "success");
        await loadLocations();
        await loadHives();
    } catch(err) {
        toast(t('Fehler beim Transferieren'), "error");
    }
}

async function deleteHiveEvaluation(evalId) {
    if (!confirm(t('Diese Zuchtwertschätzung wirklich löschen?'))) return;
    try {
        await apiFetch('hive_evaluation_delete', 'POST', { eval_id: evalId });
        await loadHives();
        toast(t('Bewertung gelöscht'), "success");
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Löschen'), "error");
    }
}

async function editHiveEvaluation(evalId, curH, curG, curS, curW, curV) {
    const curVals = [curH, curG, curS, curW, curV].map(v => v === null ? '' : v).join(',');
    const input = prompt(t('Zuchtwerte bearbeiten (Honig, Sanftmut, Wabensitz, Schwarm, Varroa) Werte 1-10:'), curVals);
    if (input === null) return;

    const parts = input.split(',').map(s => s.trim());
    if (parts.length !== 5) {
        toast(t('Bitte genau 5 Werte (oder leer) kommagetrennt eingeben.'), "error"); return;
    }

    const [honey, gentleness, steadiness, swarming, varroa] = parts.map(p => p ? parseInt(p, 10) : null);

    const isValid = (val) => val === null || (!isNaN(val) && val >= 1 && val <= 10);
    if (!isValid(honey) || !isValid(gentleness) || !isValid(steadiness) || !isValid(swarming) || !isValid(varroa)) {
        toast(t('Werte müssen zwischen 1 und 10 liegen!'), "error"); return;
    }

    try {
        await apiFetch('hive_evaluation_update', 'POST', {
            eval_id: evalId,
            score_honey: honey,
            score_gentleness: gentleness,
            score_steadiness: steadiness,
            score_swarming: swarming,
            score_varroa: varroa
        });
        toast(t('Zuchtwerte aktualisiert'), "success");
        await loadHives();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Aktualisieren'), "error");
    }
}

async function deleteHive(id) {
    if (!confirm(t('Dieses Volk und alle zugehörigen Notizen wirklich löschen?'))) return;
    try {
        await apiFetch('hive_delete', 'POST', { hive_id: id });
        await loadHives();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Löschen'), "error");
    }
}

async function addHiveNote(hiveId) {
    const input = document.getElementById(`new-note-${hiveId}`);
    if (!input || !input.value.trim()) return;

    try {
        await apiFetch('hive_note_add', 'POST', {
            hive_id: hiveId,
            note: input.value.trim()
        });
        await loadHives();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Hinzufügen der Notiz'), "error");
    }
}

async function deleteHiveNote(noteId) {
    if (!confirm(t('Notiz wirklich löschen?'))) return;
    try {
        await apiFetch('hive_note_delete', 'POST', { id: noteId });
        await loadHives();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Löschen der Notiz'), "error");
    }
}

// ══════════════════════════════════════════════════════
// ZUCHTWERTE-ÜBERSICHT
// ══════════════════════════════════════════════════════

async function loadZuchtwerteOverview(locationFilter = 'all') {
    const container = document.getElementById('zuchtwerte-overview-container');
    if (!container) return;

    container.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Lade Zuchtwerte...')}</p>`;

    try {
        const response = await apiFetch('hives_get_all');
        if (!response || !response.success) {
            container.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Fehler beim Laden.')}</p>`;
            return;
        }

        let hives = response.hives || [];

        // Filter by location if not 'all'
        if (locationFilter !== 'all') {
            hives = hives.filter(h => String(h.location_id) === String(locationFilter));
        }

        const hiveSelect = document.getElementById('zw-new-hive');
        if (hiveSelect) {
            let opts = `<option value="">${t('-- Volk auswählen --')}</option>`;
            hives.forEach(h => {
                opts += `<option value="${h.id}">${esc(h.name)} - ${esc(h.location_name || '')}</option>`;
            });
            hiveSelect.innerHTML = opts;
        }
        // Get Weights
        const wH = parseFloat(document.getElementById('weight-h')?.value) || 1.0;
        const wG = parseFloat(document.getElementById('weight-g')?.value) || 1.0;
        const wS = parseFloat(document.getElementById('weight-s')?.value) || 1.0;
        const wW = parseFloat(document.getElementById('weight-w')?.value) || 1.0;
        const wV = parseFloat(document.getElementById('weight-v')?.value) || 1.0;

        // Calculate averages
        let rowsData = [];
        hives.forEach(hive => {
            const evals = hive.evaluations || [];
            if (evals.length === 0) return;

            const avg = calcEvalAverages(evals);
            const valH = avg.h !== null ? avg.h.toFixed(1) : 0;
            const valG = avg.g !== null ? avg.g.toFixed(1) : 0;
            const valS = avg.s !== null ? avg.s.toFixed(1) : 0;
            const valW = avg.w !== null ? avg.w.toFixed(1) : 0;
            const valV = avg.v !== null ? avg.v.toFixed(1) : 0;

            const totalScore = (parseFloat(valH) * wH) +
                               (parseFloat(valG) * wG) +
                               (parseFloat(valS) * wS) +
                               (parseFloat(valW) * wW) +
                               (parseFloat(valV) * wV);

            rowsData.push({
                hiveName: hive.name,
                genetics: hive.genetics || '',
                locationName: hive.location_name,
                evalCount: evals.length,
                valH: valH, valG: valG, valS: valS, valW: valW, valV: valV,
                totalScore: totalScore.toFixed(1)
            });
        });

        if (rowsData.length === 0) {
            container.innerHTML = `<p style="color:var(--text-muted);text-align:center;font-style:italic;">${t('Es wurden noch keine Zuchtwertschätzungen für diese Völker erfasst.')}</p>`;
            return;
        }

        // Sort by total score descending
        rowsData.sort((a, b) => b.totalScore - a.totalScore);

        let tableHtml = `
            <table class="data-table" style="width:100%; white-space:nowrap;">
                <thead>
                    <tr>
                        <th title="${t('Rang')}">#</th>
                        <th>${t('Volk')}</th>
                        <th>${t('Genetik')}</th>
                        ${locationFilter === 'all' ? `<th>${t('Standort')}</th>` : ''}
                        <th style="text-align:center;" title="${t('Anzahl Bewertungen')}">📊</th>
                        <th style="text-align:center;" title="${t('Honig')}">🍯</th>
                        <th style="text-align:center;" title="${t('Sanftmut')}">😇</th>
                        <th style="text-align:center;" title="${t('Wabensitz')}">🐝</th>
                        <th style="text-align:center;" title="${t('Schwarmneigung')}">🌪️</th>
                        <th style="text-align:center;" title="${t('Varroaresistenz')}">🔬</th>
                        <th style="text-align:right; font-weight:bold; color:var(--primary);">${t('Gesamt (gewichtet)')}</th>
                    </tr>
                </thead>
                <tbody>
        `;

        rowsData.forEach((r, index) => {
            let medal = '';
            if (index === 0) medal = '🥇';
            else if (index === 1) medal = '🥈';
            else if (index === 2) medal = '🥉';
            else medal = (index + 1) + '.';

            tableHtml += `
                <tr style="${index < 3 ? 'background:rgba(234, 179, 8, 0.05);' : ''}">
                    <td style="font-weight:bold;">${medal}</td>
                    <td style="font-weight:bold; color:var(--primary);">${esc(r.hiveName)}</td>
                    <td style="color:var(--text-muted); font-size:0.9rem;">${esc(r.genetics)}</td>
                    ${locationFilter === 'all' ? `<td><span style="font-size:0.8rem; color:var(--text-muted);">${esc(r.locationName)}</span></td>` : ''}
                    <td style="text-align:center;"><span style="background:var(--bg-input); padding:2px 6px; border-radius:10px; font-size:0.8rem;">${r.evalCount}</span></td>
                    <td style="text-align:center;">${r.valH === 0 ? '-' : r.valH}</td>
                    <td style="text-align:center;">${r.valG === 0 ? '-' : r.valG}</td>
                    <td style="text-align:center;">${r.valS === 0 ? '-' : r.valS}</td>
                    <td style="text-align:center;">${r.valW === 0 ? '-' : r.valW}</td>
                    <td style="text-align:center;">${r.valV === 0 ? '-' : r.valV}</td>
                    <td style="text-align:right; font-weight:bold; font-size:1.1rem; color:var(--primary);">${r.totalScore}</td>
                </tr>
            `;
        });

        tableHtml += `</tbody></table>`;
        container.innerHTML = tableHtml;

    } catch (err) {
        console.error(err);
        container.innerHTML = `<p style="color:var(--danger);text-align:center;">${t('Fehler beim Laden der Zuchtwerte.')}</p>`;
    }
}

async function submitNewZuchtwerte() {
    const hiveId = document.getElementById('zw-new-hive').value;
    if (!hiveId) {
        toast(t('Bitte ein Volk auswählen'), "error");
        return;
    }

    let honey = document.getElementById('zw-new-h').value;
    let gentleness = document.getElementById('zw-new-g').value;
    let steadiness = document.getElementById('zw-new-s').value;
    let swarming = document.getElementById('zw-new-w').value;
    let varroa = document.getElementById('zw-new-v').value;

    if (!honey && !gentleness && !steadiness && !swarming && !varroa) {
        toast(t('Bitte mindestens einen Wert eingeben'), "error");
        return;
    }

    const isValid = (val) => !val || (Number(val) >= 1 && Number(val) <= 10);
    if (!isValid(honey) || !isValid(gentleness) || !isValid(steadiness) || !isValid(swarming) || !isValid(varroa)) {
        toast(t('Bewertungen müssen zwischen 1 und 10 liegen!'), "error");
        return;
    }

    try {
        await apiFetch('hive_evaluation_add', 'POST', {
            hive_id: hiveId,
            score_honey: honey || null,
            score_gentleness: gentleness || null,
            score_steadiness: steadiness || null,
            score_swarming: swarming || null,
            score_varroa: varroa || null
        });
        toast(t('Zuchtwerte erfolgreich gespeichert'), "success");
        document.getElementById('zw-new-h').value = '';
        document.getElementById('zw-new-g').value = '';
        document.getElementById('zw-new-s').value = '';
        document.getElementById('zw-new-w').value = '';
        document.getElementById('zw-new-v').value = '';
        await loadZuchtwerteOverview(document.getElementById('zuchtwerte-loc-select').value);
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Speichern'), "error");
    }
}

function exportZuchtwerteCSV() {
    const table = document.querySelector('#zuchtwerte-overview-container table');
    if(!table) {
        toast(t('Keine Daten zum Exportieren'), "error");
        return;
    }

    let csv = [];
    const rows = table.querySelectorAll('tr');

    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(';'));
    }

    let csvString = csv.join('\n');
    let a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent('﻿' + csvString);
    a.download = 'Zuchtwerte_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// Druckformular bleibt bewusst Deutsch – die OCR-Pipeline erkennt
// den Formulartyp anhand des Titels "ZUCHTWERTE" (siehe htr.py).
function printZuchtwerteFormular() {
    let rowsHtml = '';
    for (let i = 0; i < 16; i++) {
        rowsHtml += `
        <tr>
            <td style="border: 3px solid #000; padding: 25px; font-size: 1.4em; font-family: monospace; font-weight: bold; text-align: center;"></td>
            <td style="border: 3px solid #000;"></td>
            <td style="border: 3px solid #000;"></td>
            <td style="border: 3px solid #000;"></td>
            <td style="border: 3px solid #000;"></td>
            <td style="border: 3px solid #000;"></td>
        </tr>`;
    }

    window.printHtmlDocument(`
        <html>
        <head>
            <meta charset="UTF-8">
            <title>OCR Formular: Zuchtwerte</title>
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
                    <h1 style="margin: 0 0 20px 0; font-size: 2.5em; text-transform: uppercase; letter-spacing: 2px;">ZUCHTWERTE</h1>
                    <div style="display:flex; gap: 20px;">
                        <div style="font-size: 1.5em; border: 3px solid #000; padding: 10px; width: 300px;">DAT: </div>
                        <div style="font-size: 1.5em; border: 3px solid #000; padding: 10px; flex: 1;">NOTIZ: </div>
                    </div>
                    <div style="font-size: 1em; font-style: italic; margin-top: 10px;">OCR-bereites Formular | Notensystem: 1-10</div>
                </div>
            </div>
            <table style="margin-left: 20px; width: calc(100% - 40px);">
                <thead>
                    <tr>
                        <th style="width: 30%;">VOLK ID</th>
                        <th style="width: 14%;">HONIG (H)</th>
                        <th style="width: 14%;">SANFTMUT (G)</th>
                        <th style="width: 14%;">WABENSITZ (S)</th>
                        <th style="width: 14%;">SCHWARM (W)</th>
                        <th style="width: 14%;">VARROA (V)</th>
                    </tr>
                </thead>
                <tbody>
                    ${rowsHtml}
                </tbody>
            </table>
            <div class="corner-left-bottom"></div><div class="corner-right-bottom"></div>
        </body>
        </html>
    `);
}
