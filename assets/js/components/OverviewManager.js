/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

// ══════════════════════════════════════════════════════
// ÜBERSICHT – alle Standorte mit aktueller GTS und
// bevorstehenden/aktiven Marker-Ereignissen (Alpine-Komponente)
// ══════════════════════════════════════════════════════

document.addEventListener('alpine:init', () => {
    Alpine.data('overviewManager', () => ({
        overviews: [],
        isLoading: false,

        init() {
            if (this.activeTab === 'uebersicht') {
                this.loadOverview();
            }
            this.$watch('activeTab', tab => {
                if (tab === 'uebersicht' && this.overviews.length === 0) {
                    this.loadOverview();
                }
            });

            window.addEventListener('overview-refresh', () => {
                if (this.activeTab === 'uebersicht') {
                    this.loadOverview();
                } else {
                    this.overviews = [];
                }
            });
        },

        async loadOverview() {
            if (!window.state || !window.state.locations || window.state.locations.length === 0) {
                this.overviews = [];
                return;
            }
            this.isLoading = true;
            this.overviews = [];

            try {
                const results = await Promise.all(
                    window.state.locations.map(async loc => {
                        const item = { location: loc, gts: null, gtsDate: null, events: [], ok: true };
                        try {
                            const [gtsRes, predictions] = await Promise.all([
                                window.apiFetch('gts_date&date=' + window.state.currentDate + '&location_id=' + loc.id),
                                window.apiFetch('marker_predictions&location_id=' + loc.id)
                            ]);
                            if (gtsRes && gtsRes.gts !== undefined) {
                                item.gts = gtsRes.gts;
                                item.gtsDate = gtsRes.date;
                            }
                            // marker_predictions liefert [{marker, date, days_until, status, message}]
                            if (Array.isArray(predictions)) {
                                item.events = predictions
                                    .filter(p => p.status === 'upcoming' || p.status === 'active')
                                    .map(p => ({
                                        id: p.marker.id + '_' + p.date,
                                        date: p.date,
                                        marker: { name: p.marker.name, type: p.marker.type, color: p.marker.color },
                                        severity: p.marker.severity || 'info',
                                        status: p.status,
                                        message: p.message
                                    }))
                                    .sort((a, b) => new Date(a.date) - new Date(b.date));
                            }
                        } catch (e) {
                            item.ok = false;
                        }
                        return item;
                    })
                );
                this.overviews = results;
            } catch (e) {
                console.error('Error loading overview:', e);
            } finally {
                this.isLoading = false;
            }
        },

        changeLocation(locId) {
            const loc = window.state.locations.find(l => l.id == locId);
            if (loc) {
                if (window.onLocationChange) {
                    window.onLocationChange(loc.id);
                }
                this.$dispatch('set-active-tab', 'dashboard');
            }
        },

        getSevColor(ev) {
            if (ev.severity === 'critical') return { raw: '#ef4444', css: 'var(--danger)' };
            if (ev.severity === 'warning') return { raw: '#f59e0b', css: 'var(--warning)' };
            return { raw: '#3b82f6', css: 'var(--info)' };
        },

        getWhen(ev) {
            const diff = Math.round((new Date(ev.date) - new Date(window.state.currentDate)) / 86400000);
            if (diff === 0) return t('Heute');
            if (diff === 1) return t('Morgen');
            if (diff === -1) return t('Gestern');
            // Platzhalter-Übersetzung wegen unterschiedlicher Wortstellung (DE/EN)
            const tpl = diff > 1 ? t('In {n} Tagen') : t('Vor {n} Tagen');
            return tpl.replace('{n}', Math.abs(diff));
        }
    }));
});

window.loadAllLocationsOverview = function() {
    window.dispatchEvent(new Event('overview-refresh'));
};
