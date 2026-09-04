/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// ERWEITERTER REGEL-BUILDER v2
// ══════════════════════════════════════════════════════

// -- Konfigurationstabellen --
// Labels sind deutsche i18n-Schlüssel und werden beim Rendern via t() übersetzt.

const CONDITION_TYPES = {
    daily:            { label: 'Tageswert',                icon: '📅', desc: 'Vergleich eines Tageswerts (z.B. Temperatur, Niederschlag, GTS)' },
    period_aggregate: { label: 'Zeitraum-Aggregation',     icon: '📊', desc: 'Summe/Durchschnitt/Min/Max über einen Zeitraum (z.B. Niederschlag Oktober)' },
    day_count:        { label: 'Tage zählen',              icon: '🔢', desc: 'Anzahl Tage mit bestimmter Bedingung in einem Zeitraum' },
    consecutive_days: { label: 'Aufeinanderfolgende Tage', icon: '📏', desc: 'Länge der längsten Serie von Tagen mit bestimmter Bedingung' },
    temp_sum:         { label: 'Temperatursumme',          icon: '🌡️', desc: 'Summe aller Temperaturen über einem Schwellwert in einem Zeitraum' },
};

const WEATHER_FIELDS = {
    // Tageswerte
    gts:            { label: 'Grünlandtemperatursumme (GTS)', unit: '',      group: 'GTS' },
    temp_mean:      { label: 'Tagesmitteltemperatur',         unit: '°C',    group: 'Temperatur' },
    temp_min:       { label: 'Tagesminimum (Frost)',          unit: '°C',    group: 'Temperatur' },
    temp_max:       { label: 'Tageshöchstwert',               unit: '°C',    group: 'Temperatur' },
    precipitation:  { label: 'Niederschlag',                  unit: 'mm',    group: 'Wasser' },
    pressure:       { label: 'Luftdruck',                     unit: 'hPa',   group: 'Sonstige' },
    soil_moisture:  { label: 'Bodenfeuchte',                  unit: 'm³/m³', group: 'Wasser' },
    // Tageswerte extra (nur daily)
    month:          { label: 'Monat (1-12)',                  unit: '',      group: 'Zeit', dailyOnly: true },
    day_of_year:    { label: 'Tag des Jahres (1-366)',        unit: '',      group: 'Zeit', dailyOnly: true },
    is_frost:       { label: 'Frost? (0/1)',                  unit: '',      group: 'Bool', dailyOnly: true },
    is_heavy_rain:  { label: 'Starkregen >15mm? (0/1)',       unit: '',      group: 'Bool', dailyOnly: true },
    // Fenster-Felder (nur daily)
    'precip_sum_2d':     { label: 'Niederschlag Σ 2 Tage',    unit: 'mm', group: 'Fenster', dailyOnly: true },
    'precip_sum_3d':     { label: 'Niederschlag Σ 3 Tage',    unit: 'mm', group: 'Fenster', dailyOnly: true },
    'precip_sum_5d':     { label: 'Niederschlag Σ 5 Tage',    unit: 'mm', group: 'Fenster', dailyOnly: true },
    'precip_sum_7d':     { label: 'Niederschlag Σ 7 Tage',    unit: 'mm', group: 'Fenster', dailyOnly: true },
    'temp_mean_avg_3d':  { label: 'Ø Temp. 3 Tage',           unit: '°C', group: 'Fenster', dailyOnly: true },
    'temp_mean_avg_5d':  { label: 'Ø Temp. 5 Tage',           unit: '°C', group: 'Fenster', dailyOnly: true },
    'temp_mean_avg_7d':  { label: 'Ø Temp. 7 Tage',           unit: '°C', group: 'Fenster', dailyOnly: true },
    'temp_min_min_3d':   { label: 'Min. Temp. 3 Tage',        unit: '°C', group: 'Fenster', dailyOnly: true },
    'temp_min_min_5d':   { label: 'Min. Temp. 5 Tage',        unit: '°C', group: 'Fenster', dailyOnly: true },
    'temp_max_max_3d':   { label: 'Max. Temp. 3 Tage',        unit: '°C', group: 'Fenster', dailyOnly: true },
    'temp_max_max_5d':   { label: 'Max. Temp. 5 Tage',        unit: '°C', group: 'Fenster', dailyOnly: true },
    temp_deviation:      { label: 'Temp.-Abweichung vom Ø',   unit: '°C', group: 'Abweichung', dailyOnly: true },
    precip_deviation:    { label: 'Niederschl.-Abw. vom Ø',   unit: 'mm', group: 'Abweichung', dailyOnly: true },
};

// Felder die für Perioden-basierte Bedingungen verwendet werden können
const PERIOD_FIELDS = ['temp_mean', 'temp_min', 'temp_max', 'precipitation', 'pressure', 'soil_moisture'];

const OPERATOR_LABELS = {
    '>':  'größer als (>)',
    '<':  'kleiner als (<)',
    '>=': 'größer gleich (≥)',
    '<=': 'kleiner gleich (≤)',
    '==': 'gleich (=)',
    '!=': 'ungleich (≠)',
    'between': 'zwischen',
    'in':      'einer von (Liste)',
};

const AGGREGATION_LABELS = {
    sum: 'Summe (Σ)',
    avg: 'Durchschnitt (Ø)',
    min: 'Minimum',
    max: 'Maximum',
};

const YEAR_REF_LABELS = {
    current:  'Aktuelles Jahr',
    previous: 'Vorjahr',
};

// -- Rule-Builder State --

let ruleBuilderState = {
    logic: 'AND',
    conditions: [],
    name: '',
    color: '#ef4444',
    severity: 'warning',
    alertMessage: '',
    description: '',
};

function newCondition(type = 'daily') {
    const base = {
        type,
        field: type === 'daily' ? 'gts' : 'precipitation',
        operator: '>=',
        value: 0,
    };

    if (type === 'daily') return base;

    // Perioden-Bedingung
    const period = {
        ...base,
        year_ref: 'current',
        period_type: 'month',
        period_month: new Date().getMonth() + 1,
        period_start: '01-01',
        period_end: '12-31',
    };

    if (type === 'period_aggregate') {
        return { ...period, aggregation: 'sum', compare_mode: 'absolute' };
    }
    if (type === 'day_count' || type === 'consecutive_days') {
        return { ...period, sub_operator: '>', sub_value: 0 };
    }
    if (type === 'temp_sum') {
        return { ...period, field: 'temp_mean', threshold: 0, compare_mode: 'absolute' };
    }
    return period;
}

// -- Haupt-Renderer --

function renderRuleBuilder() {
    const container = document.getElementById('rule-builder-area');
    if (!container) return;

    container.innerHTML = `
        <div class="card">
            <div class="card-header">
                <span class="card-title">🔧 ${t('Erweiterten Marker erstellen')}</span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>${t('Marker-Name')}</label>
                    <input type="text" id="rb-name" value="${esc(ruleBuilderState.name)}"
                           placeholder="${t('z.B. Spätfrost bei Obstblüte')}">
                </div>
                <div class="form-group" style="max-width:100px;">
                    <label>${t('Farbe')}</label>
                    <input type="color" id="rb-color" value="${ruleBuilderState.color}" style="height:38px;width:100%;cursor:pointer;">
                </div>
                <div class="form-group">
                    <label>${t('Dringlichkeit')}</label>
                    <select id="rb-severity">
                        <option value="info"     ${ruleBuilderState.severity === 'info' ? 'selected' : ''}>ℹ️ ${t('Info')}</option>
                        <option value="warning"  ${ruleBuilderState.severity === 'warning' ? 'selected' : ''}>⚠️ ${t('Warnung')}</option>
                        <option value="critical" ${ruleBuilderState.severity === 'critical' ? 'selected' : ''}>🚨 ${t('Kritisch')}</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>${t('Verknüpfung der Bedingungen')}</label>
                    <select id="rb-logic" onchange="ruleBuilderState.logic=this.value;renderConditionsList()">
                        <option value="AND" ${ruleBuilderState.logic === 'AND' ? 'selected' : ''}>${t('UND - Alle müssen zutreffen')}</option>
                        <option value="OR"  ${ruleBuilderState.logic === 'OR' ? 'selected' : ''}>${t('ODER - Mindestens eine')}</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>${t('Alarm-Nachricht')}</label>
                <textarea id="rb-alert" rows="2" placeholder="${t('z.B. Achtung: Morgenfrost bei laufender Obstblüte!')}">${esc(ruleBuilderState.alertMessage)}</textarea>
            </div>
            <div class="form-group">
                <label>${t('Beschreibung / Notizen (optional)')}</label>
                <textarea id="rb-description" rows="1" placeholder="${t('Interne Notiz')}">${esc(ruleBuilderState.description)}</textarea>
            </div>

            <hr style="border-color:var(--border);margin:1.2rem 0;">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.5rem;">
                <h3 style="font-size:1rem;margin:0;">${t('Bedingungen')}
                    <span class="logic-badge">${ruleBuilderState.logic}</span>
                </h3>
                <div class="condition-add-btns">
                    ${Object.entries(CONDITION_TYPES).map(([k, v]) =>
                        `<button class="btn btn-sm btn-secondary" onclick="addCondition('${k}')" title="${t(v.desc)}">
                            ${v.icon} ${t(v.label)}
                        </button>`
                    ).join('')}
                </div>
            </div>

            <div id="conditions-list"></div>

            <div style="margin-top:1.2rem;display:flex;gap:.8rem;justify-content:flex-end;">
                <button class="btn btn-secondary" onclick="resetRuleBuilder()">${t('Zurücksetzen')}</button>
                <button class="btn btn-primary" onclick="saveComplexMarker()">🏷️ ${t('Marker speichern')}</button>
            </div>
        </div>
    `;

    renderConditionsList();
}

// -- Bedingungsliste rendern --

function renderConditionsList() {
    const container = document.getElementById('conditions-list');
    if (!container) return;

    if (ruleBuilderState.conditions.length === 0) {
        container.innerHTML = `
            <div class="empty-conditions">
                <p>${t('Noch keine Bedingungen.')}</p>
                <p style="font-size:.85rem;margin:.8rem 0;">${t('Füge beliebig viele Bedingungen hinzu - du brauchst keine Vorlage!')}</p>
                <div class="condition-add-btns" style="justify-content:center;margin-top:.5rem;">
                    ${Object.entries(CONDITION_TYPES).map(([k, v]) =>
                        `<button class="btn btn-sm btn-secondary" onclick="addCondition('${k}')" title="${t(v.desc)}">
                            ${v.icon} ${t(v.label)}
                        </button>`
                    ).join('')}
                </div>
            </div>`;
        return;
    }

    container.innerHTML = ruleBuilderState.conditions.map((cond, i) => {
        const typeInfo = CONDITION_TYPES[cond.type] || CONDITION_TYPES.daily;

        return `
        <div class="condition-card">
            <div class="condition-header">
                <span class="condition-type-badge">${typeInfo.icon} ${t(typeInfo.label)}</span>
                ${i > 0 ? `<span class="logic-badge">${ruleBuilderState.logic}</span>` : ''}
                <button class="btn-icon danger" onclick="removeCondition(${i})" title="${t('Entfernen')}" style="margin-left:auto;">✕</button>
            </div>
            <div class="condition-body">
                ${renderConditionFields(cond, i)}
            </div>
            <div class="condition-summary">${buildConditionSummary(cond)}</div>
        </div>`;
    }).join('');
}

// -- Bedingungs-Felder je nach Typ --

function renderConditionFields(cond, idx) {
    const type = cond.type || 'daily';

    switch (type) {
        case 'daily':           return renderDailyFields(cond, idx);
        case 'period_aggregate': return renderPeriodAggregateFields(cond, idx);
        case 'day_count':       return renderDayCountFields(cond, idx);
        case 'consecutive_days': return renderConsecutiveDaysFields(cond, idx);
        case 'temp_sum':        return renderTempSumFields(cond, idx);
        default:                return renderDailyFields(cond, idx);
    }
}

function renderDailyFields(cond, idx) {
    return `
        <div class="form-row" style="grid-template-columns:1fr auto 1fr;">
            ${fieldSelect(idx, 'field', cond.field, false)}
            ${operatorSelect(idx, 'operator', cond.operator)}
            ${valueInput(idx, cond)}
        </div>`;
}

function renderPeriodAggregateFields(cond, idx) {
    return `
        <div class="form-row" style="grid-template-columns:1fr 1fr;">
            ${yearRefSelect(idx, cond.year_ref)}
            ${periodTypeSelect(idx, cond)}
        </div>
        ${renderPeriodInputs(cond, idx)}
        <div class="form-row" style="grid-template-columns:1fr 1fr;">
            ${fieldSelect(idx, 'field', cond.field, true)}
            ${aggregationSelect(idx, cond.aggregation)}
        </div>
        <div class="form-row" style="grid-template-columns:1fr 1fr;">
            ${compareModeSelect(idx, cond.compare_mode)}
            <div></div>
        </div>
        <div class="form-row" style="grid-template-columns:auto 1fr;">
            ${operatorSelect(idx, 'operator', cond.operator, true)}
            ${simpleValueInput(idx, 'value', cond.value, cond.compare_mode === 'historical_percent' ? t('% des Ø') : '')}
        </div>
        ${renderHistoricalPreview(idx)}`;
}

function renderDayCountFields(cond, idx) {
    return `
        <div class="form-row" style="grid-template-columns:1fr 1fr;">
            ${yearRefSelect(idx, cond.year_ref)}
            ${periodTypeSelect(idx, cond)}
        </div>
        ${renderPeriodInputs(cond, idx)}
        <div class="cond-section-label">${t('Tagesbedingung: Zähle Tage wo...')}</div>
        <div class="form-row" style="grid-template-columns:1fr auto 1fr;">
            ${fieldSelect(idx, 'field', cond.field, true)}
            ${operatorSelect(idx, 'sub_operator', cond.sub_operator)}
            ${simpleValueInput(idx, 'sub_value', cond.sub_value)}
        </div>
        <div class="cond-section-label">${t('Anzahl dieser Tage muss sein...')}</div>
        <div class="form-row" style="grid-template-columns:auto 1fr;">
            ${operatorSelect(idx, 'operator', cond.operator, true)}
            ${simpleValueInput(idx, 'value', cond.value, t('Anzahl Tage'))}
        </div>`;
}

function renderConsecutiveDaysFields(cond, idx) {
    return `
        <div class="form-row" style="grid-template-columns:1fr 1fr;">
            ${yearRefSelect(idx, cond.year_ref)}
            ${periodTypeSelect(idx, cond)}
        </div>
        ${renderPeriodInputs(cond, idx)}
        <div class="cond-section-label">${t('Tagesbedingung: Aufeinanderfolgende Tage wo...')}</div>
        <div class="form-row" style="grid-template-columns:1fr auto 1fr;">
            ${fieldSelect(idx, 'field', cond.field, true)}
            ${operatorSelect(idx, 'sub_operator', cond.sub_operator)}
            ${simpleValueInput(idx, 'sub_value', cond.sub_value)}
        </div>
        <div class="cond-section-label">${t('Längste Serie muss sein...')}</div>
        <div class="form-row" style="grid-template-columns:auto 1fr;">
            ${operatorSelect(idx, 'operator', cond.operator, true)}
            ${simpleValueInput(idx, 'value', cond.value, t('Tage am Stück'))}
        </div>`;
}

function renderTempSumFields(cond, idx) {
    return `
        <div class="form-row" style="grid-template-columns:1fr 1fr;">
            ${yearRefSelect(idx, cond.year_ref)}
            ${periodTypeSelect(idx, cond)}
        </div>
        ${renderPeriodInputs(cond, idx)}
        <div class="form-row" style="grid-template-columns:1fr 1fr 1fr;">
            ${fieldSelect(idx, 'field', cond.field, true, ['temp_mean', 'temp_min', 'temp_max'])}
            <div class="form-group">
                <label style="font-size:.75rem;">${t('Nur Tage über (Schwellwert)')}</label>
                <input type="number" step="any" value="${cond.threshold ?? 0}"
                       onchange="updateCond(${idx},'threshold',parseFloat(this.value)||0)">
            </div>
            ${compareModeSelect(idx, cond.compare_mode)}
        </div>
        <div class="form-row" style="grid-template-columns:auto 1fr;">
            ${operatorSelect(idx, 'operator', cond.operator, true)}
            ${simpleValueInput(idx, 'value', cond.value, cond.compare_mode === 'historical_percent' ? t('% des Ø') : t('Gradsumme'))}
        </div>
        ${renderHistoricalPreview(idx)}`;
}

// -- Wiederverwendbare Formular-Bausteine --

function fieldSelect(idx, key, current, periodOnly = false, restrictTo = null) {
    const entries = Object.entries(WEATHER_FIELDS).filter(([k, v]) => {
        if (restrictTo) return restrictTo.includes(k);
        if (periodOnly) return PERIOD_FIELDS.includes(k);
        return true;
    });

    // Gruppiert nach group
    const groups = {};
    entries.forEach(([k, v]) => {
        if (!groups[v.group]) groups[v.group] = [];
        groups[v.group].push([k, v]);
    });

    let html = `<div class="form-group"><label style="font-size:.75rem;">${t('Messgröße')}</label><select onchange="updateCond(${idx},'${key}',this.value)">`;
    for (const [group, items] of Object.entries(groups)) {
        html += `<optgroup label="${t(group)}">`;
        items.forEach(([k, v]) => {
            html += `<option value="${k}" ${current === k ? 'selected' : ''}>${t(v.label)}${v.unit ? ' (' + v.unit + ')' : ''}</option>`;
        });
        html += `</optgroup>`;
    }
    html += `</select></div>`;
    return html;
}

function operatorSelect(idx, key, current, noList = false) {
    const ops = Object.entries(OPERATOR_LABELS).filter(([k]) => !noList || (k !== 'in' && k !== 'between'));
    return `<div class="form-group"><label style="font-size:.75rem;">${t('Operator')}</label>
        <select onchange="updateCond(${idx},'${key}',this.value);renderConditionsList()" style="min-width:120px;">
        ${ops.map(([k, v]) => `<option value="${k}" ${current === k ? 'selected' : ''}>${t(v)}</option>`).join('')}
        </select></div>`;
}

function valueInput(idx, cond) {
    const op = cond.operator;
    const val = cond.value;

    if (op === 'in') {
        const str = Array.isArray(val) ? val.join(', ') : (val ?? '');
        return `<div class="form-group"><label style="font-size:.75rem;">${t('Werte (kommagetrennt)')}</label>
            <input type="text" value="${str}" placeholder="z.B. 5, 6, 7"
                   onchange="updateCondList(${idx},this.value)"></div>`;
    }
    if (op === 'between') {
        const v1 = Array.isArray(val) ? val[0] : val;
        const v2 = Array.isArray(val) && val.length > 1 ? val[1] : '';
        return `<div class="form-group"><label style="font-size:.75rem;">${t('Von - Bis')}</label>
            <div style="display:flex;gap:.3rem;">
                <input type="number" step="any" value="${v1}" placeholder="${t('Von')}" onchange="updateCondRange(${idx},0,this.value)">
                <input type="number" step="any" value="${v2}" placeholder="${t('Bis')}" onchange="updateCondRange(${idx},1,this.value)">
            </div></div>`;
    }

    return simpleValueInput(idx, 'value', val);
}

function simpleValueInput(idx, key, val, placeholder = '') {
    const label = placeholder || t('Wert');
    return `<div class="form-group"><label style="font-size:.75rem;">${esc(label)}</label>
        <input type="number" step="any" value="${val ?? ''}" placeholder="${esc(label)}"
               onchange="updateCond(${idx},'${key}',parseFloat(this.value))"></div>`;
}

function yearRefSelect(idx, current) {
    return `<div class="form-group"><label style="font-size:.75rem;">${t('Jahr')}</label>
        <select onchange="updateCond(${idx},'year_ref',this.value)">
            ${Object.entries(YEAR_REF_LABELS).map(([k, v]) =>
                `<option value="${k}" ${current === k ? 'selected' : ''}>${t(v)}</option>`
            ).join('')}
        </select></div>`;
}

function periodTypeSelect(idx, cond) {
    return `<div class="form-group"><label style="font-size:.75rem;">${t('Zeitraumtyp')}</label>
        <select onchange="updateCond(${idx},'period_type',this.value);renderConditionsList()">
            <option value="month"      ${cond.period_type === 'month' ? 'selected' : ''}>${t('Monat')}</option>
            <option value="date_range" ${cond.period_type === 'date_range' ? 'selected' : ''}>${t('Datumsbereich')}</option>
            <option value="full_year"  ${cond.period_type === 'full_year' ? 'selected' : ''}>${t('Ganzes Jahr')}</option>
        </select></div>`;
}

function renderPeriodInputs(cond, idx) {
    if (cond.period_type === 'month') {
        const monthNames = localizedMonthNames('long');
        return `<div class="form-row" style="grid-template-columns:1fr;">
            <div class="form-group"><label style="font-size:.75rem;">${t('Monat')}</label>
                <select onchange="updateCond(${idx},'period_month',parseInt(this.value))">
                    ${monthNames.map((m, i) =>
                        `<option value="${i+1}" ${cond.period_month == (i+1) ? 'selected' : ''}>${m}</option>`
                    ).join('')}
                </select>
            </div>
        </div>`;
    }
    if (cond.period_type === 'date_range') {
        return `<div class="form-row" style="grid-template-columns:1fr 1fr;">
            <div class="form-group"><label style="font-size:.75rem;">${t('Von')} (MM-TT)</label>
                <input type="text" value="${cond.period_start || '01-01'}" placeholder="MM-TT z.B. 10-01"
                       onchange="updateCond(${idx},'period_start',this.value)">
            </div>
            <div class="form-group"><label style="font-size:.75rem;">${t('Bis')} (MM-TT)</label>
                <input type="text" value="${cond.period_end || '12-31'}" placeholder="MM-TT z.B. 10-31"
                       onchange="updateCond(${idx},'period_end',this.value)">
            </div>
        </div>`;
    }
    return ''; // full_year braucht keine Eingabe
}

function aggregationSelect(idx, current) {
    return `<div class="form-group"><label style="font-size:.75rem;">${t('Aggregation')}</label>
        <select onchange="updateCond(${idx},'aggregation',this.value)">
            ${Object.entries(AGGREGATION_LABELS).map(([k, v]) =>
                `<option value="${k}" ${current === k ? 'selected' : ''}>${t(v)}</option>`
            ).join('')}
        </select></div>`;
}

function compareModeSelect(idx, current) {
    return `<div class="form-group"><label style="font-size:.75rem;">${t('Vergleich gegen')}</label>
        <select onchange="updateCond(${idx},'compare_mode',this.value);renderConditionsList()">
            <option value="absolute"           ${current === 'absolute' ? 'selected' : ''}>${t('Absoluter Wert')}</option>
            <option value="historical_percent" ${current === 'historical_percent' ? 'selected' : ''}>${t('% des langjährigen Ø')}</option>
        </select></div>`;
}

function renderHistoricalPreview(idx) {
    return `<div id="hist-preview-${idx}" class="hist-preview">
        <button class="btn btn-sm btn-secondary" onclick="loadHistoricalPreview(${idx})">
            📊 ${t('Historischen Durchschnitt anzeigen')}
        </button>
    </div>`;
}

// -- Zusammenfassung einer Bedingung als lesbarer Text --

function buildConditionSummary(cond) {
    const type = cond.type || 'daily';
    const fieldInfo = WEATHER_FIELDS[cond.field] || { label: cond.field, unit: '' };
    const fieldLabel = t(fieldInfo.label);
    const opLabel = t(OPERATOR_LABELS[cond.operator] || cond.operator);

    if (type === 'daily') {
        const valStr = Array.isArray(cond.value) ? cond.value.join(' - ') : cond.value;
        return `📅 <strong>${fieldLabel}</strong> ${opLabel} <strong>${valStr}</strong>${fieldInfo.unit ? ' ' + fieldInfo.unit : ''}`;
    }

    const yearLabel = t(YEAR_REF_LABELS[cond.year_ref] || cond.year_ref);
    const periodLabel = cond.period_type === 'month'
        ? localizedMonthNames('long')[(cond.period_month || 1) - 1] || ''
        : cond.period_type === 'date_range'
            ? `${cond.period_start} ${t('bis')} ${cond.period_end}`
            : t('ganzes Jahr');

    if (type === 'period_aggregate') {
        const aggLabel = t(AGGREGATION_LABELS[cond.aggregation] || cond.aggregation);
        const modeLabel = cond.compare_mode === 'historical_percent' ? t('% des langjährigen Ø') : '';
        return `📊 ${aggLabel} ${t('von')} <strong>${fieldLabel}</strong> ${t('im')} <strong>${yearLabel} ${periodLabel}</strong> ${opLabel} <strong>${cond.value}${modeLabel}</strong>`;
    }

    if (type === 'day_count') {
        const subOp = t(OPERATOR_LABELS[cond.sub_operator] || cond.sub_operator);
        return `🔢 ${t('Tage in')} <strong>${yearLabel} ${periodLabel}</strong> ${t('wo')} ${fieldLabel} ${subOp} ${cond.sub_value} → ${t('Anzahl')} ${opLabel} <strong>${cond.value}</strong>`;
    }

    if (type === 'consecutive_days') {
        const subOp = t(OPERATOR_LABELS[cond.sub_operator] || cond.sub_operator);
        return `📏 ${t('Aufeinanderfolgende Tage in')} <strong>${yearLabel} ${periodLabel}</strong> ${t('wo')} ${fieldLabel} ${subOp} ${cond.sub_value} → ${t('Serie')} ${opLabel} <strong>${cond.value}</strong>`;
    }

    if (type === 'temp_sum') {
        const modeLabel = cond.compare_mode === 'historical_percent' ? t('% des langjährigen Ø') : '';
        return `🌡️ ${t('Temperatursumme von')} <strong>${fieldLabel}</strong> (>${cond.threshold}°) ${t('in')} <strong>${yearLabel} ${periodLabel}</strong> ${opLabel} <strong>${cond.value}${modeLabel}</strong>`;
    }

    return JSON.stringify(cond);
}

// -- Aktionen --

function addCondition(type = 'daily') {
    ruleBuilderState.conditions.push(newCondition(type));
    renderConditionsList();
}

function removeCondition(index) {
    ruleBuilderState.conditions.splice(index, 1);
    renderConditionsList();
}

function updateCond(index, key, value) {
    ruleBuilderState.conditions[index][key] = value;
}

function updateCondList(index, valueStr) {
    ruleBuilderState.conditions[index].value = valueStr.split(',').map(v => parseFloat(v.trim())).filter(v => !isNaN(v));
}

function updateCondRange(index, pos, value) {
    const cond = ruleBuilderState.conditions[index];
    if (!Array.isArray(cond.value)) cond.value = [0, 0];
    cond.value[pos] = parseFloat(value) || 0;
}

async function loadHistoricalPreview(idx) {
    const cond = ruleBuilderState.conditions[idx];
    if (!state.selectedLocationId) {
        toast(t('Bitte zuerst einen Standort auswählen.'), 'error');
        return;
    }

    const target = document.getElementById(`hist-preview-${idx}`);
    target.innerHTML = `<span style="color:var(--text-muted);font-size:.8rem;">${t('Laden...')}</span>`;

    try {
        const data = await apiFetch(
            `historical_stats&location_id=${state.selectedLocationId}`,
            'POST',
            cond
        );

        if (data.avg === null) {
            target.innerHTML = `<span style="color:var(--text-muted);font-size:.8rem;">${t('Keine historischen Daten verfügbar.')}</span>`;
            return;
        }

        let yearsHtml = '';
        if (data.years) {
            yearsHtml = Object.entries(data.years).map(([yr, val]) =>
                `<span class="hist-year-chip">${yr}: ${val}</span>`
            ).join('');
        }

        target.innerHTML = `
            <div class="hist-stats">
                <div class="hist-stat"><span class="hist-stat-label">Ø ${t('Durchschnitt')}</span><strong>${data.avg}</strong></div>
                <div class="hist-stat"><span class="hist-stat-label">Min</span><strong>${data.min}</strong></div>
                <div class="hist-stat"><span class="hist-stat-label">Max</span><strong>${data.max}</strong></div>
            </div>
            ${yearsHtml ? `<div class="hist-years">${yearsHtml}</div>` : ''}`;
    } catch (err) {
        target.innerHTML = `<span style="color:var(--danger);font-size:.8rem;">${t('Fehler:')} ${esc(err.message)}</span>`;
    }
}

function resetRuleBuilder() {
    ruleBuilderState = {
        logic: 'AND', conditions: [], name: '', color: '#ef4444',
        severity: 'warning', alertMessage: '', description: '',
    };
    renderRuleBuilder();
}

async function saveComplexMarker() {
    const name = document.getElementById('rb-name')?.value.trim();
    const color = document.getElementById('rb-color')?.value || '#ef4444';
    const severity = document.getElementById('rb-severity')?.value || 'warning';
    const alertMessage = document.getElementById('rb-alert')?.value.trim();
    const description = document.getElementById('rb-description')?.value.trim();

    if (!name) {
        toast(t('Bitte gib einen Marker-Namen ein.'), 'error');
        return;
    }
    if (ruleBuilderState.conditions.length === 0) {
        toast(t('Mindestens eine Bedingung erforderlich.'), 'error');
        return;
    }

    const rules = {
        logic: ruleBuilderState.logic,
        conditions: ruleBuilderState.conditions,
    };

    try {
        await apiFetch('markers', 'POST', {
            name,
            type: 'complex',
            threshold_value: null,
            color,
            severity,
            description: description || null,
            alert_message: alertMessage || null,
            rules,
        });
        toast(t('Marker gespeichert!'), 'success');
        resetRuleBuilder();
        await loadMarkers();
        await loadPredictions();
        renderGTSChart();
        renderCalendar();
    } catch (err) {
        toast(t('Fehler:') + ' ' + err.message, 'error');
    }
}

// -- Marker-Regeln anzeigen (readonly) --

function viewMarkerRules(markerId) {
    const marker = state.markers.find(m => m.id == markerId);
    if (!marker) return;

    const modal = document.getElementById('day-detail-modal');
    modal.style.display = 'flex';

    let rulesHtml = '';
    if (marker.rules && marker.rules.conditions) {
        rulesHtml = marker.rules.conditions.map(c => {
            const summary = buildConditionSummary(c);
            return `<div class="rule-display-item">${summary}</div>`;
        }).join(`<div class="rule-logic-sep">${marker.rules.logic || 'AND'}</div>`);
    } else if (marker.type === 'gts' && marker.threshold_value) {
        rulesHtml = `<div class="rule-display-item">🌿 GTS ≥ ${marker.threshold_value}</div>`;
    } else {
        rulesHtml = `<p style="color:var(--text-muted)">${t('Keine Regeln definiert.')}</p>`;
    }

    document.getElementById('day-detail-content').innerHTML = `
        <h2 style="color:${marker.color}">📋 ${esc(marker.name)}</h2>
        ${marker.alert_message ? `<div class="prediction-message" style="margin-bottom:1rem;">${esc(marker.alert_message)}</div>` : ''}
        <h3 style="margin-bottom:.5rem;">${t('Regelbaum')} (${marker.rules?.logic || 'AND'}):</h3>
        <div class="rule-display-tree">${rulesHtml}</div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeDayDetail()">${t('Schließen')}</button>
        </div>
    `;
}
