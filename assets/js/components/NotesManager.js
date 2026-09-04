/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// STANDORT-ANMERKUNGEN
// ══════════════════════════════════════════════════════

async function loadLocationNotes() {
    if (!state.selectedLocationId) return;

    const notesList = document.getElementById('location-notes-list');
    if (!notesList) return;
    notesList.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Lade Anmerkungen...')}</p>`;

    try {
        const response = await apiFetch(`location_notes_get&location_id=${state.selectedLocationId}`);
        if (!response || !response.success || !response.notes) {
            notesList.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Fehler beim Laden der Anmerkungen.')}</p>`;
            return;
        }

        if (response.notes.length === 0) {
            notesList.innerHTML = `<p style="color:var(--text-muted);text-align:center;font-style:italic;">${t('Noch keine Anmerkungen vorhanden.')}</p>`;
            return;
        }

        let html = '';
        response.notes.forEach(note => {
            // MySQL DateTime "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM:SS" für JS
            const jsDateStr = note.created_at.replace(' ', 'T');
            const dateStr = new Date(jsDateStr).toLocaleString(window.uiLocale(), {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit'
            });
            html += `
                <div style="background:var(--bg-color); border:1px solid var(--border-color); border-radius:var(--radius); padding:0.75rem; position:relative;">
                    <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.25rem;">
                        ${dateStr}
                    </div>
                    <div style="white-space:pre-wrap; font-size:0.95rem; margin-right: 2rem;">${esc(note.note)}</div>
                    <button onclick="deleteLocationNote(${note.id})" style="position:absolute; top:0.5rem; right:0.5rem; background:none; border:none; color:var(--danger-color); cursor:pointer; font-size:1.2rem; padding: 0.2rem;" title="${t('Löschen')}">🗑️</button>
                </div>
            `;
        });
        notesList.innerHTML = html;
    } catch (err) {
        console.error(err);
        notesList.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${t('Fehler beim Laden der Anmerkungen.')}</p>`;
    }
}

async function addLocationNote() {
    if (!state.selectedLocationId) return;

    const inputEl = document.getElementById('location-note-input');
    if (!inputEl) return;

    const inputStr = inputEl.value.trim();
    if (!inputStr) {
        toast(t('Bitte eine Anmerkung eingeben.'), 'warning');
        return;
    }

    try {
        await apiFetch('location_notes_add', 'POST', {
            location_id: state.selectedLocationId,
            note: inputStr
        });
        inputEl.value = '';
        await loadLocationNotes();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Speichern der Anmerkung'), 'error');
    }
}

async function deleteLocationNote(id) {
    if (!confirm(t('Anmerkung wirklich löschen?'))) return;
    try {
        await apiFetch('location_note_delete', 'POST', { id: id });
        await loadLocationNotes();
    } catch (err) {
        console.error(err);
        toast(t('Fehler beim Löschen der Anmerkung'), 'error');
    }
}
