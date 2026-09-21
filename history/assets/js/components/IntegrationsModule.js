/* ============================================================================
   INTEGRATIONS MODULE – Strava / Garmin / Polar...
   Jeden moduł dla wszystkich providerów
   ============================================================================ */

window.IntegrationsModule = {

    // Provider metadata (ikony, kolory, labels)
    PROVIDERS: {
        strava: {
            label: 'Strava',
            color: '#FC5425',
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M15.5 2.5L11 12h3.5L10 22l8-11h-4l4-8.5z"/></svg>',
        },
        garmin: {
            label: 'Garmin',
            color: '#003087',
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        },
    },

    // ========================================================================
    // INIT & STATUS
    // ========================================================================

    async init(state) {
        this.handleCallbackParams(state);

        if (state.user && state.user.token) {
            await this.loadStatuses(state);
        }
    },

    async loadStatuses(state) {
        state.integrations.loading = true;

        const result = await Utils.fetchAPI(
            window.APP_CONFIG.api('auth/integration_status.php')
        );

        if (result.success && result.data && result.data.data) {
            const data = result.data.data;
            for (const provider of ['strava', 'garmin']) {
                if (data[provider]) {
                    state.integrations[provider] = data[provider];
                }
            }
        }

        state.integrations.loading = false;
    },

    // ========================================================================
    // CONNECT / UNLINK
    // ========================================================================

    connectProvider(provider) {
        window.location.href = window.APP_CONFIG.api(`auth/${provider}/auth.php`);
    },

    async unlinkProvider(state, provider) {
        const label = this.PROVIDERS[provider]?.label || provider;

        if (!confirm(`Czy na pewno chcesz odłączyć ${label}?`)) return;

        const result = await Utils.fetchAPI(
            window.APP_CONFIG.api(`auth/integration_unlink.php?provider=${provider}`),
            { method: 'DELETE' }
        );

        if (result.success) {
            state.integrations[provider] = { linked: false, expires_at: null, expired: false };
            console.log(`✅ ${label} unlinked`);
        } else {
            console.error(`❌ Unlink failed:`, result.error);
            alert(`Nie udało się odłączyć ${label}`);
        }
    },

    // ========================================================================
    // PROVIDER IMPORT – universal dla każdego providera
    // ========================================================================

    /**
     * Otwórz modal importu dla danego providera
     * @param {object} state
     * @param {string} provider – 'strava' | 'garmin'
     */
    async openProviderImport(state, provider) {
        if (!state.integrations[provider]?.linked) {
            alert(`Najpierw podłącz ${this.PROVIDERS[provider]?.label || provider}`);
            return;
        }

        state.providerImport.provider  = provider;
        state.providerImport.show      = true;
        state.providerImport.loading   = true;
        state.providerImport.error     = null;
        state.providerImport.activities    = [];
        state.providerImport.selected      = [];
        state.providerImport.importResults = null;
        state.providerImport.importing     = false;
        state.providerImport.currentPage   = 1;
        state.providerImport.hasMore       = true;

        await this.fetchActivities(state, provider, 1);
    },

    /**
     * Pobierz stronę aktywności z providera
     */
    async fetchActivities(state, provider, page = 1) {
        state.providerImport.loading = true;

        const result = await Utils.fetchAPI(
            window.APP_CONFIG.api(`auth/${provider}/activities.php?page=${page}`)
        );

        if (result.success && result.data && result.data.data) {
            const newActivities = result.data.data.activities || [];

            if (page === 1) {
                state.providerImport.activities = newActivities;
            } else {
                state.providerImport.activities = state.providerImport.activities.concat(newActivities);
            }

            state.providerImport.currentPage = page;
            // has_more idzie z servera – on wie ile Strava zwróciła przed filtracją
            state.providerImport.hasMore = result.data.data.has_more ?? false;
        } else {
            state.providerImport.error = result.data?.error || 'Failed to fetch activities';
        }

        state.providerImport.loading = false;
    },

    /**
     * Grupuj aktywności po dacie
     */
    getGroupedActivities(state) {
        const activities = state.providerImport.activities;
        const groups = {};

        activities.forEach(a => {
            const date = a.date ? a.date.split('T')[0] : 'unknown';
            if (!groups[date]) groups[date] = [];
            groups[date].push(a);
        });

        return Object.keys(groups)
            .sort((a, b) => b.localeCompare(a))
            .map(date => ({
                date,
                label: this.formatGroupDate(date),
                activities: groups[date]
            }));
    },

    formatGroupDate(dateStr) {
        if (dateStr === 'unknown') return 'Brak daty';

        const date    = new Date(dateStr + 'T12:00:00');
        const days    = ['Niedziela','Poniedziałek','Wtorek','Środa','Czwartek','Piątek','Sobota'];
        const months  = ['stycznia','lutego','marca','kwietnia','maja','czerwca',
                         'lipca','sierpnia','września','października','listopada','grudnia'];

        return `${days[date.getDay()]}, ${date.getDate()} ${months[date.getMonth()]}`;
    },

    /**
     * Toggle zaznaczenie
     */
    toggleActivity(state, stravaId) {
        const idx = state.providerImport.selected.indexOf(stravaId);
        if (idx > -1) {
            state.providerImport.selected.splice(idx, 1);
        } else {
            state.providerImport.selected.push(stravaId);
        }
    },

    isSelected(state, id) {
        return state.providerImport.selected.includes(id);
    },

    /**
     * Import zaznaczone aktywności
     */
    async importSelected(state) {
        const provider = state.providerImport.provider;

        if (state.providerImport.selected.length === 0) {
            alert('Zaznacz przynajmniej jedną aktywność');
            return;
        }

        state.providerImport.importing     = true;
        state.providerImport.importResults = null;
        state.providerImport.error         = null;

        const result = await Utils.fetchAPI(
            window.APP_CONFIG.api(`auth/${provider}/import.php`),
            {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ activity_ids: state.providerImport.selected })
            }
        );

        state.providerImport.importing = false;

        if (result.success && result.data && result.data.data) {
            state.providerImport.importResults = result.data.data;

            // Reload tracks
            await window.TracksModule.loadUserTracks(state);

            // Zamknij modal po successful import
            setTimeout(() => {
                state.providerImport.show = false;
            }, 1500); // 1.5s żeby zdążył się pokazać result

            // Mark imported jako already_imported
            const importedIds = result.data.data.results
                .filter(r => r.status === 'imported')
                .map(r => r.strava_id || r.activity_id);

            state.providerImport.activities.forEach(a => {
                const id = a.strava_id || a.activity_id;
                if (importedIds.includes(id)) {
                    a.already_imported = true;
                }
            });

            state.providerImport.selected = [];
        } else {
            state.providerImport.error = result.data?.error || 'Import failed';
        }
    },

    closeProviderImport(state) {
        state.providerImport.show = false;
    },

    // ========================================================================
    // UPLOAD MENU
    // ========================================================================

    /**
     * Obsługa klika na przycisk upload
     * Jeśli integracja linked → pokaż menu, jeśli nie → od razu GPX
     */
    handleUploadClick(state) {
        const hasIntegration = state.integrations.strava.linked || state.integrations.garmin.linked;

        if (!hasIntegration) {
            const input = document.querySelector('[x-ref="activityInput"]');
            if (input) input.click();
            return;
        }

        state.uploadMenuOpen = !state.uploadMenuOpen;
    },

    uploadGPX() {
        const input = document.querySelector('[x-ref="activityInput"]');
        if (input) input.click();
    },

    // ========================================================================
    // CALLBACK PARAMS (po powrocie z OAuth)
    // ========================================================================

    handleCallbackParams(state) {
        const params = new URLSearchParams(window.location.search);
        let needsClean = false;

        const providers = ['strava', 'garmin', 'polar'];

        for (const provider of providers) {
            const label = this.PROVIDERS[provider]?.label || provider;

            if (params.has(`${provider}_linked`)) {
                console.log(`✅ ${label} linked successfully`);
                setTimeout(() => alert(`✅ ${label} podłączony pomyślnie!`), 500);
                params.delete(`${provider}_linked`);
                needsClean = true;
            }

            if (params.has(`${provider}_error`)) {
                const error = params.get(`${provider}_error`);
                console.error(`❌ ${label} error:`, error);
                setTimeout(() => alert(`❌ Błąd podłączenia ${label}: ${error}`), 500);
                params.delete(`${provider}_error`);
                needsClean = true;
            }
        }

        if (needsClean) {
            const cleanUrl = params.toString()
                ? window.location.pathname + '?' + params.toString()
                : window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }
};

console.log('✅ IntegrationsModule loaded');