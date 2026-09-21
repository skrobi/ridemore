/* ============================================================================
 PANELS MODULE - Panel & UI state management
 REFACTORED: używa RecommendationsModule zamiast RoutesModule
 ============================================================================ */

window.PanelsModule = {

    openPanel(state, panel) {
        if (state.activePanel === panel) {
            this.closeAll(state);
        } else {
            state.activePanel = panel;
            state.selectedRoute = null;
            state.selectedChallenge = null;
            state.showTopOverlay = false;

            // ✅ FIXED: Lazy load data when panel opens
            if (panel === 'library' && state.libraryRoutes.length === 0) {
                window.LibraryModule.applyFilters(state);
            }

            if (panel === 'planned') {
                window.PlannedModule.loadUserPlanned(state);
            }

            if (panel === 'challenges') {
                window.ChallengesModule.loadUserChallenges(state);
                window.ChallengesModule.loadAvailableChallenges(state);
            }

            if (panel === 'achievements') {
                window.AchievementsModule.loadUserAchievements(state);
            }

            // ✅ FIXED: Load recommendations for top-routes panel
            if (panel === 'top-routes') {
                this.loadTopPanel(state);
            }

            //console.log('📂 Opened panel:', panel);
        }
    },

    closeAll(state) {
        state.activePanel = null;
        state.selectedRoute = null;
        state.fabOpen = false;
        state.selectedChallenge = null;
        state.showTopOverlay = false;

        // Deselect route on map
        if (window.mapManager) {
            window.mapManager.deselectRoute();
        }
    },

    // ✅ NEW: Load top panel recommendations
    loadTopPanel(state) {
        if (!state.map) {
            console.warn('⚠️ Map not ready, skipping top panel load');
            return;
        }

        const bounds = state.map.getBounds();
        const bbox = [
            bounds.getWest(),
            bounds.getSouth(),
            bounds.getEast(),
            bounds.getNorth()
        ];

        //console.log('📡 Loading top panel for bbox:', bbox);

        window.RecommendationsModule.loadAllRecommendations(state, bbox);
    },

    onMapMove(state, bbox) {
        state.filters.bbox = bbox;

        // ✅ FIXED: Auto-refresh top-routes panel on map move
        if (state.activePanel === 'top-routes') {
            clearTimeout(state.mapMoveTimeout);
            state.mapMoveTimeout = setTimeout(() => {
                //console.log('🗺️ Map moved, refreshing top panel...');
                window.RecommendationsModule.loadAllRecommendations(state, bbox);
            }, 500);
        }

        // Auto-refresh library if checkbox is active AND panel is open
        if (state.activePanel === 'library' && state.filters.useMapBbox) {
            //console.log('🗺️ Map moved with library open + useMapBbox ON, refreshing...');
            window.LibraryModule.searchLibrary(state);
        }

        // Note: Nie ładujemy top routes tutaj, bo to się dzieje w top-routes panel
    },

    toggleMapLayers(state) {
        if (typeof window.toggleMapLayers === 'function') {
            window.toggleMapLayers(state.settings.showAllLayers);
        }
    },

    findMe(state) {
        if (!navigator.geolocation) {
            alert('Geolokalizacja nie jest dostępna');
            return;
        }

        //console.log('📍 Finding location...');

        navigator.geolocation.getCurrentPosition(
                (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            if (state.map) {
                state.map.setView([lat, lng], 13);
                //console.log('✅ Location found');
            }
        },
                (error) => {
            console.error('Geolocation error:', error);
            alert('Nie można pobrać lokalizacji');
        },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
        );
    },

    handlePhotoUpload(event) {
        const file = event.target.files[0];
        if (!file)
            return;

        //console.log('📸 Photo selected:', file.name);
        alert('Upload zdjęć - wkrótce!');

        event.target.value = '';
    }
};

//console.log('✅ PanelsModule loaded (with RecommendationsModule integration)');