/* ============================================================================
 RECOMMENDATIONS MODULE - Universal recommendation system
 Version: 1.0.0
 ============================================================================ */

window.RecommendationsModule = {

    /**
     * Load recommendations for all sections
     * @param {Object} state - App state
     * @param {Array} bbox - Bbox coordinates [minLng, minLat, maxLng, maxLat]
     */
    async loadAllRecommendations(state, bbox) {
        if (!bbox || bbox.length !== 4) {
            console.warn('⚠️ Invalid bbox for recommendations');
            return;
        }

        state.loadingTopPanel = true;
        state.topPanelData = {
            hero: null,
            top_quality: [],
            for_ambitious: [],
            feed: []
        };

        try {
            const bboxString = bbox.join(',');
            const userLocalId = localStorage.getItem('user_local_id');

            //console.log('📡 Loading recommendations for bbox:', bboxString);

            // Parallel requests for better performance
            const [heroResponse, topResponse, ambitiousResponse, feedResponse] = await Promise.all([
                this.fetchRecommendation('hero_daily', bboxString, userLocalId),
                this.fetchRecommendation('top_quality', bboxString, userLocalId),
                this.fetchRecommendation('for_ambitious', bboxString, userLocalId),
                this.fetchFeed(userLocalId)
            ]);

            // Process hero (with fallback)
            if (heroResponse.success && heroResponse.data.results.length > 0) {
                state.topPanelData.hero = heroResponse.data.results[0];
            } else if (topResponse.success && topResponse.data.results.length > 0) {
                //console.log('⚠️ Hero empty, using first from top_quality as fallback');
                state.topPanelData.hero = topResponse.data.results[0];
            }

            // Process top_quality
            if (topResponse.success) {
                state.topPanelData.top_quality = topResponse.data.results || [];
            }

            // Process for_ambitious
            if (ambitiousResponse.success) {
                state.topPanelData.for_ambitious = ambitiousResponse.data.results || [];
            }

            // Process feed
            if (feedResponse.success) {
                state.topPanelData.feed = feedResponse.data.feed || [];
            }


        } catch (error) {
            console.error('❌ Failed to load recommendations:', error);
        } finally {
            state.loadingTopPanel = false;
        }
    },

    /**
     * Fetch single recommendation
     */
    async fetchRecommendation(recipe, bbox, userLocalId) {
        try {
            let url = `${window.APP_CONFIG.apiUrl}/routes/recommend.php?recipe=${recipe}&bbox=${bbox}`;

            if (userLocalId) {
                url += `&user_local_id=${userLocalId}`;
            }

            // ✅ Active layers
            const activeLayers = this.getActiveLayers();
            //console.log('🔍 DEBUG active layers:', activeLayers); // ← nawiasy ()

            if (activeLayers.length > 0) {
                url += `&layers=${activeLayers.join(',')}`;
                //console.log(`🏷️ Recommending from layers: ${activeLayers.join(', ')}`); // ← nawiasy ()
            }

            //console.log(`📡 Fetching ${recipe}:`, url); // ← nawiasy ()

            const response = await fetch(url);
            const data = await response.json();

            if (!data.success) {
                console.error(`❌ Recipe ${recipe} failed:`, data.error); // ← nawiasy ()
            }

            return data;

        } catch (error) {
            console.error(`❌ Failed to fetch ${recipe}:`, error); // ← nawiasy ()
            return {success: false, error: error.message, data: {results: []}};
        }
    },

    /**
     * Fetch activity feed
     */
    /**
     * Fetch activity feed
     */
    async fetchFeed(userLocalId) {
        try {
            let url = `${window.APP_CONFIG.apiUrl}/feed/list.php?filter=global&limit=5`;

            if (userLocalId) {
                url += `&user_local_id=${userLocalId}`;
            }

            //console.log('📡 Fetching feed:', url);

            const response = await fetch(url);
            const data = await response.json();

            // Format response to match old structure
            if (data.success && data.data.events) {
                return {
                    success: true,
                    data: {
                        feed: data.data.events.map(event => ({
                                ...event,
                                relative_time: event.time_ago // Backend już zwraca time_ago
                            }))
                    }
                };
            }

            return {success: false, error: 'No events', data: {feed: []}};

        } catch (error) {
            console.error('❌ Failed to fetch feed:', error);
            return {success: false, error: error.message, data: {feed: []}};
        }
    },

    /**
     * Random route (button click)
     */
    async randomRoute(state, bbox) {
        if (!bbox || bbox.length !== 4) {
            alert('Przesuń mapę aby wybrać obszar');
            return;
        }

        state.loadingTopPanel = true;

        try {
            const bboxString = bbox.join(',');
            const userLocalId = localStorage.getItem('user_local_id');

            //console.log('🎲 Fetching random route...');

            const response = await this.fetchRecommendation('random_adventure', bboxString, userLocalId);

            if (response.success && response.data.results.length > 0) {
                const route = response.data.results[0];

                //console.log('✅ Random route:', route.name);

                // Open route details
                await window.RoutesModule.openRouteDetails(state, route.route_id);
            } else {
                alert('Nie znaleziono tras w tym obszarze. Przesuń mapę i spróbuj ponownie.');
            }

        } catch (error) {
            console.error('❌ Random route failed:', error);
            alert('Błąd podczas losowania trasy');
        } finally {
            state.loadingTopPanel = false;
        }
    },

    /**
     * Get relative time string (Polish)
     */
    getRelativeTime(timestamp) {
        const now = new Date();
        const date = new Date(timestamp);
        const diff = Math.floor((now - date) / 1000); // seconds

        if (diff < 60)
            return 'przed chwilą';
        if (diff < 3600)
            return `${Math.floor(diff / 60)} min temu`;
        if (diff < 86400)
            return `${Math.floor(diff / 3600)} godz. temu`;
        if (diff < 604800)
            return `${Math.floor(diff / 86400)} dni temu`;

        return date.toLocaleDateString('pl-PL');
    },

    /**
     * Get active overlay layer slugs
     */
    getActiveLayers() {
        if (!window.mapManager || !window.mapManager.layers) {
            return [];
        }

        const layers = Object.values(window.mapManager.layers);

        return layers
                .filter(layer =>
                    layer.visible &&
                            layer.config?.slug !== 'poi' &&
                            layer.config?.slug !== 'segments' &&
                            typeof OverlayLayer !== 'undefined' &&
                            layer instanceof OverlayLayer
                )
                .map(layer => layer.config.slug);
    },

    highlightRouteOnMap(routeId) {
        window.RouteHighlightModule.highlightRoute(routeId);
    },

    unhighlightRouteOnMap(routeId) {
        window.RouteHighlightModule.unhighlightRoute(routeId);
    },

    async centerOnRoute(routeId) {
        await window.RouteHighlightModule.centerOnRoute(routeId);
    }

};


//console.log('✅ RecommendationsModule v1.0.0 loaded');