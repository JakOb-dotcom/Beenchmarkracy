/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// I18N & UI-HELPERS
// Übersetzungs-Konzept (gettext-Stil): Der deutsche Text ist
// der Schlüssel. t() schlägt nur für Nicht-DE-Sprachen im
// Wörterbuch (globalState.js) nach und fällt sonst auf den
// deutschen Text zurück.
// ══════════════════════════════════════════════════════

window.t = function(key) {
    if (window.currentLang === 'de') return key;
    const dict = window.i18n && window.i18n[window.currentLang];
    return (dict && dict[key]) || key;
};

window.changeLanguage = function(lang) {
    if (!['de', 'en'].includes(lang)) lang = 'de';
    window.currentLang = lang;
    localStorage.setItem('lang', lang);
    window.applyTranslations();
    window.dispatchEvent(new CustomEvent('language-changed', { detail: lang }));
};

window.applyTranslations = function() {
    document.documentElement.lang = window.currentLang;

    document.querySelectorAll('[data-i18n]').forEach(el => {
        el.textContent = window.t(el.getAttribute('data-i18n'));
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        el.placeholder = window.t(el.getAttribute('data-i18n-placeholder'));
    });
    document.querySelectorAll('[data-i18n-title]').forEach(el => {
        el.title = window.t(el.getAttribute('data-i18n-title'));
    });

    const langSwitcher = document.getElementById('lang-switcher');
    if (langSwitcher) langSwitcher.value = window.currentLang;
};

/**
 * Alpine klont <template x-for/x-if>-Inhalte erst beim Rendern. applyTranslations()
 * läuft aber nur bei DOMContentLoaded und beim Sprachwechsel und sieht diese Klone
 * nicht (template.content ist kein Teil des Dokuments). Deshalb werden neu
 * eingefügte Knoten mit data-i18n hier nachträglich übersetzt.
 */
window.translateSubtree = function(root) {
    if (!root || root.nodeType !== 1) return;
    if (root.hasAttribute('data-i18n')) root.textContent = window.t(root.getAttribute('data-i18n'));
    root.querySelectorAll('[data-i18n]').forEach(el => {
        el.textContent = window.t(el.getAttribute('data-i18n'));
    });
    root.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        el.placeholder = window.t(el.getAttribute('data-i18n-placeholder'));
    });
    root.querySelectorAll('[data-i18n-title]').forEach(el => {
        el.title = window.t(el.getAttribute('data-i18n-title'));
    });
};

document.addEventListener('DOMContentLoaded', () => {
    const observer = new MutationObserver(mutations => {
        for (const m of mutations) {
            m.addedNodes.forEach(node => window.translateSubtree(node));
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
});

// Locale für Datumsformatierung (toLocaleDateString etc.)
window.uiLocale = function() {
    return window.currentLang === 'en' ? 'en-GB' : 'de-DE';
};

window.toast = function(msg, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => el.remove(), 4000);
};

window.showLoading = function(msg = null, progress = null) {
    const overlay = document.getElementById('loading-overlay');
    const msgEl = document.getElementById('loading-msg');
    const pContainer = document.getElementById('loading-progress-container');
    const pBar = document.getElementById('loading-progress-bar');

    if (overlay) overlay.style.display = 'flex';
    if (msgEl) msgEl.textContent = msg || window.t('Laden...');

    if (pContainer && pBar) {
        if (progress !== null) {
            pContainer.style.display = 'block';
            pBar.style.width = Math.min(100, Math.max(0, progress)) + '%';
        } else {
            pContainer.style.display = 'none';
            pBar.style.width = '0%';
        }
    }
};

window.hideLoading = function() {
    const overlay = document.getElementById('loading-overlay');
    const pContainer = document.getElementById('loading-progress-container');
    const pBar = document.getElementById('loading-progress-bar');

    if (overlay) overlay.style.display = 'none';
    if (pContainer) pContainer.style.display = 'none';
    if (pBar) pBar.style.width = '0%';
};

window.formatDate = function(d) {
    if (!(d instanceof Date) || isNaN(d)) return '';
    return d.toISOString().split('T')[0];
};

window.dayOfYear = function(date) {
    if (!(date instanceof Date) || isNaN(date)) return 0;
    const start = new Date(date.getFullYear(), 0, 0);
    return Math.floor((date - start) / 86400000);
};

window.esc = function(str) {
    if (str === null || str === undefined) return '';
    if (typeof str !== 'string') str = String(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
};

// Druckt ein vollständiges HTML-Dokument über einen versteckten iframe.
// Vorteile gegenüber window.open(): kein Pop-up-Blocker, kein hängendes
// Fenster und kein blockierter Fokus im Hauptdokument (das war der Grund,
// warum die UI nach einem Druckversuch nicht mehr klickbar war).
window.printHtmlDocument = function(html) {
    const iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    const cleanup = () => {
        // Verzögert entfernen, damit der Druckdialog das Dokument noch lesen kann.
        setTimeout(() => { if (iframe.parentNode) iframe.parentNode.removeChild(iframe); }, 1000);
    };

    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();

    const triggerPrint = () => {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch (e) {
            console.error('Druck fehlgeschlagen:', e);
        }
        // Fokus zurück ins Hauptfenster geben, sonst bleibt die UI „eingefroren".
        window.focus();
        cleanup();
    };

    // Auf vollständiges Laden (Bilder/Fonts) warten, dann drucken.
    if (iframe.contentWindow.document.readyState === 'complete') {
        setTimeout(triggerPrint, 250);
    } else {
        iframe.onload = () => setTimeout(triggerPrint, 250);
    }
};
