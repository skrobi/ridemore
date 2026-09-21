/* ============================================================================
 ROUTE COVERAGE VISUALIZER v3.0
 Shows challenge route coverage by user activities using merged vectors
 
 FEATURES:
 - Toggle visibility with state tracking
 - Merged vectors from all activities (no duplicates)
 - Auto-cleanup on route change
 - Uses RouteStyles for consistent colors
 ============================================================================ */

window.RouteCoverageVisualizer = {

    layer: null,
    data: null,
    visible: false, // ✅ DODAJ
    currentChallengeRouteId: null, // ✅ DODAJ

    /**
     * Load and display coverage for a challenge route
     */
    async load(challengeRouteId, userLocalId) {
        //console.log('📊 Loading coverage for challenge:', challengeRouteId, 'user:', userLocalId);

        // ✅ Validate inputs
        if (!challengeRouteId || !userLocalId) {
            throw new Error('Missing required parameters: challengeRouteId or userLocalId');
        }

        // ✅ Check map exists
        if (!window.map) {
            throw new Error('Map not initialized');
        }

        this.currentChallengeRouteId = challengeRouteId;

        try {
            const url = window.APP_CONFIG.api(
                    `routes/route-coverage.php?challenge_route_id=${challengeRouteId}&user_local_id=${userLocalId}`
                    );

            //console.log('🔗 Fetching:', url);

            const response = await fetch(url);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            //console.log('📦 API Response:', result);

            if (!result.success) {
                throw new Error(result.error || 'API returned success: false');
            }

            // ✅ Validate response structure
            if (!result.data || !result.data.challenge) {
                throw new Error('Invalid API response structure');
            }

            this.data = result.data;


            // ✅ Check if any coverage exists
            if (!this.data.merged_vectors || this.data.merged_vectors.length === 0) {
                alert('Nie masz jeszcze pokrycia tej trasy.\n\nWgraj pliki GPX swoich przejazdów!');
                return this.data;
            }

            this.render();

            return this.data;

        } catch (error) {
            console.error('❌ Coverage load failed:', error);
            throw error;
        }
    },

    /**
     * Render coverage on map (merged vectors only)
     */
    render() {
        if (!this.data || !window.map) {
            console.error('❌ Cannot render - missing data or map');
            return;
        }

        //console.log('🎨 Starting render...');

        this.clear();

        

        this.layer = L.layerGroup();

        // ✅ TYLKO MERGED VECTORS (green - unique matched coverage)
        if (this.data.merged_vectors && this.data.merged_vectors.length > 0) {
            //console.log('🟢 Rendering merged vectors:', this.data.merged_vectors.length);
            const vectors = this.createMergedVectors(this.data.merged_vectors);
            vectors.addTo(this.layer);
        } else {
            console.warn('⚠️ No coverage yet - upload activities!');
            return;
        }

        this.layer.addTo(window.map);
        this.visible = true;
        this.setupAutoCleanup();

        //console.log('✅ Coverage layer added to map');
        //console.log('🎉 Render complete! Coverage:', this.data.total_coverage_pct + '%');
    },

    setupAutoCleanup() {
        // Nasłuchuj na event 'route:selected' z mapy
        if (window.map && !this.cleanupListener) {
            this.cleanupListener = (e) => {
                // Jeśli selected route !== challenge route → hide
                if (e.routeId !== this.currentChallengeRouteId && this.visible) {
                    //console.log('🧹 Auto-hiding coverage (different route selected)');
                    this.hide();

                    const btn = document.getElementById('coverage-toggle-btn');
                    if (btn)
                        btn.innerHTML = 'Pokaż pokrycie na mapie';
                }
            };

            window.map.on('route:selected', this.cleanupListener);
        }
    },

    /**
     * Create merged vectors (green matched only)
     */
    createMergedVectors(vectors) {
        const group = L.layerGroup();
        const style = window.RouteStyles.getCoverageStyles().vectorMatched;

        vectors.forEach((vector) => {
            if (!vector.start || !vector.end || !Array.isArray(vector.start) || !Array.isArray(vector.end)) {
                console.warn('⚠️ Invalid vector:', vector);
                return;
            }

            // WGS84 from API: [lon, lat] → Leaflet needs [lat, lon]
            const line = L.polyline(
                    [[vector.start[1], vector.start[0]], [vector.end[1], vector.end[0]]],
                    {
                        ...style,
                        pane: 'coveragePane' // ✅ WAŻNE: Użyj dedykowanego pane
                    }
            );

            line.bindPopup(`
            <div style="padding: 8px; font-size: 12px; min-width: 180px;">
                <div style="font-weight: 600; margin-bottom: 8px; color: #10b981;">
                    ✅ POKRYTE
                </div>
                <div style="font-size: 11px; color: #666;">
                    Vector #${vector.index}<br>
                    Odległość: ${vector.distance != null ? vector.distance.toFixed(1) + 'm' : 'N/A'}<br>
                    Wynik: ${vector.match_score != null ? vector.match_score.toFixed(1) + '%' : 'N/A'}
                </div>
            </div>
        `);

            line.addTo(group);
        });

        //console.log(`  Merged vectors rendered: ${vectors.length} 🟢`);
        return group;
    },

    /**
     * Check if coverage is currently visible
     */
    isVisible() {
        return this.visible && this.layer !== null;
    },

    /**
     * Hide coverage layer (keep data)
     */
    hide() {
        if (this.layer && window.map) {
            window.map.removeLayer(this.layer);
            this.layer = null;
            this.visible = false;
            //console.log('🙈 Coverage layer hidden');
        }
    },

    /**
     * Clear coverage layer
     */
    clear() {
        if (this.layer && window.map) {
            window.map.removeLayer(this.layer);
            this.layer = null;
        }
        this.visible = false;
    },

    /**
     * Destroy and cleanup
     */
    destroy() {
        // ✅ Odrejestruj listener
        if (this.cleanupListener && window.map) {
            window.map.off('route:selected', this.cleanupListener);
            this.cleanupListener = null;
            //console.log('🧹 Cleanup listener removed');
        }

        this.clear();
        this.data = null;
        this.currentChallengeRouteId = null;
        //console.log('🧹 RouteCoverageVisualizer destroyed');
    }
};

//console.log('✅ RouteCoverageVisualizer v3.0 loaded');