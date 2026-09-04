/**
 * Beenchmarkracy – Copyright (C) 2026 Imkerei Ploder
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. See the LICENSE file in the project root for details.
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('historyComparisonManager', () => ({
        localSelectedLocationId: '',
        isLoading: false,
        hasError: false,
        series: [],

        init() {
            // Sync local selected location with global store initially
            if (window.state && window.state.selectedLocationId) {
                this.localSelectedLocationId = window.state.selectedLocationId;
                this.loadData();
            }

            // Watch for when global location changes (if bound via state somehow)
            // Instead, we will listen for active tab and check state
            this.$watch('activeTab', tab => {
                if (tab === 'kalender') {
                    if (window.state && window.state.selectedLocationId && this.localSelectedLocationId !== window.state.selectedLocationId) {
                        this.localSelectedLocationId = window.state.selectedLocationId;
                        this.loadData();
                    } else if (this.localSelectedLocationId && this.series.length === 0 && !this.isLoading) {
                        this.loadData();
                    }
                }
            });

            // Listen for explicit reload requests
            window.addEventListener('reload-historie', () => {
                if (window.state && window.state.selectedLocationId) {
                    this.localSelectedLocationId = window.state.selectedLocationId;
                }
                if (this.localSelectedLocationId) {
                    this.loadData();
                }
            });
        },

        onLocationChange(val) {
            if (!val) return;
            this.localSelectedLocationId = val;
            if (window.onLocationChange) {
                window.onLocationChange(val); // Update global state
            }
            this.loadData();
        },

        async loadData() {
            if (!this.localSelectedLocationId) return;

            this.isLoading = true;
            this.hasError = false;
            this.series = [];

            try {
                const data = await window.apiFetch('historie_vergleich_series&location_id=' + this.localSelectedLocationId);
                if (data && data.length > 0) {
                    this.series = data.filter(item => item && item.indicators).map(item => {
                        // Label sprachabhängig im Frontend bilden (Backend liefert year/month)
                        if (item.year && item.month && window.localizedMonthNames) {
                            item.label = localizedMonthNames('long')[item.month - 1] + ' ' + item.year;
                        }
                        return item;
                    });
                }
            } catch (err) {
                console.error('Historie-Vergleich Fehler:', err);
                this.hasError = true;
            } finally {
                this.isLoading = false;
            }
        }
    }));
});
