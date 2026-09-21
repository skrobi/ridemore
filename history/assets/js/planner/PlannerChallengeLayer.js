/* ============================================================================
 PLANNER CHALLENGE LAYER - Ghost rendering of challenge routes
 Renders reference routes as semi-transparent background.
 Builds snap index via PlannerSnap.
 ============================================================================ */

window.PlannerChallengeLayer = {
    _layerGroup: null,

    // ========================================================================
    // INIT
    // ========================================================================
    init() {
        PlannerEvents.on('challenge:clear-request', () => this.clear());
        console.log('✅ PlannerChallengeLayer initialized');
    },

    // ========================================================================
    // LOAD CHALLENGE
    // ========================================================================
    async load(challengeId) {
        const cfg = window.PLANNER_CONFIG;
        const userLocalId = localStorage.getItem('user_local_id') || '';
        console.log("load challange");
        console.log(challengeId);
        let url = `${cfg.challengeRoutesApi}?challenge_id=${challengeId}`;
        if (userLocalId)
            url += `&user_local_id=${userLocalId}`;

        try {
            const resp = await fetch(url);
            const data = await resp.json();

            if (!data.success) {
                console.error('Challenge load failed:', data.error);
                return;
            }

            const {challenge, routes} = data.data;

            PlannerState.challenge = challenge;
            PlannerState.challengeRoutes = routes;

            // RENDER BEFORE FITBOUNDS
            this.render(routes, challenge.color);

            // WAIT for render, then fit
            requestAnimationFrame(() => {
                try {
                    // Preferuj bbox z API (dokładniejszy)
                    if (challenge.bbox) {
                        const [minLng, minLat, maxLng, maxLat] = challenge.bbox;
                        PlannerMap.fitBounds(
                                [[minLat, minLng], [maxLat, maxLng]],
                                {padding: [50, 50], maxZoom: 12}
                        );
                    }
                    // Fallback: oblicz z wyrenderowanej warstwy
                    else if (this._layerGroup && routes.features.length > 0) {
                        const bounds = this._layerGroup.getBounds();
                        if (bounds.isValid()) {
                            PlannerMap.fitBounds(bounds, {padding: [20, 20]});
                        }
                    }
                } catch (err) {
                    console.warn('Could not fit bounds:', err);
                }
            });

            PlannerSnap.build(routes);
            PlannerEvents.emit('challenge:loaded', {challenge, routes});

        } catch (err) {
            console.error('Challenge load error:', err);
        }
    },

    // ========================================================================
    // RENDER
    // ========================================================================
    render(geojson, color = '#8b5cf6') {
        if (!geojson || !geojson.features || geojson.features.length === 0) {
            console.warn('No features to render');
            return;
        }
        const map = PlannerMap.getMap();

        if (this._layerGroup) {
            map.removeLayer(this._layerGroup);
        }

        this._layerGroup = L.layerGroup();

        geojson.features.forEach(feature => {
            const geom = feature.geometry;
            if (!geom?.coordinates || geom.coordinates.length < 2)
                return;

            const coords = geom.coordinates.map(c => [c[1], c[0]]);
            const props = feature.properties;
            const isCompleted = props.is_completed;

            // ✅ Użyj RouteStyles
            const zoom = map.getZoom();
            const styles = RouteStyles.getChallengeGhostStyles(zoom, isCompleted);

// Halo (najgrubsza biała)
            L.polyline(coords, styles.halo).addTo(this._layerGroup);

// Outline (kolorowa)
            L.polyline(coords, styles.outline).addTo(this._layerGroup);

// Main (biały środek - ghost)
            const main = L.polyline(coords, styles.main).addTo(this._layerGroup);
          

            // Tooltip
            if (props.name) {
                let tip = props.name;
                if (props.distance_km)
                    tip += ` · ${props.distance_km} km`;
                if (isCompleted)
                    tip += ' ✅';

                main.bindTooltip(tip, {
                    permanent: false,
                    direction: 'top',
                    className: 'challenge-tooltip',
                    sticky: true
                });
            }

            // Start marker
            if (coords.length >= 2) {
                const routeColor = isCompleted ? '#10b981' : color;
                const startIcon = L.divIcon({
                    className: '',
                    html: `<div style="width:10px;height:10px;border-radius:50%;background:${routeColor};border:2px solid white;opacity:0.8;"></div>`,
                    iconSize: [10, 10],
                    iconAnchor: [5, 5]
                });

                L.marker(coords[0], {
                    icon: startIcon,
                    interactive: false,
                    pane: 'challengePane'
                }).addTo(this._layerGroup);
            }
        });

        this._layerGroup.addTo(map);
    },

    // ========================================================================
    // CLEAR
    // ========================================================================
    clear() {
        const map = PlannerMap.getMap();
        if (this._layerGroup) {
            map.removeLayer(this._layerGroup);
            this._layerGroup = null;
        }
        PlannerState.challenge = null;
        PlannerState.challengeRoutes = null;
        PlannerSnap.clear();
        PlannerEvents.emit('challenge:cleared');
    }
};

console.log('✅ PlannerChallengeLayer module loaded');