/* ============================================================================
 PLANNER POI - POI cards along route
 Finds POI near planned route and displays as cards between waypoints
 ============================================================================ */

window.PlannerPOI = {
    _pois: [],
    _config: {
        enabled: false,
        subtypes: [],
        bufferM: 200
    },

    // ========================================================================
    // INIT - Load config from localStorage
    // ========================================================================
    init() {
        const saved = localStorage.getItem('plannerPOIConfig');
        if (saved) {
            try {
                this._config = JSON.parse(saved);
            } catch (e) {
                console.warn('Invalid POI config in localStorage');
            }
        }

        console.log('✅ PlannerPOI initialized, config:', this._config);
    },

    // ========================================================================
    // GET CONFIG
    // ========================================================================
    getConfig() {
        return {...this._config};
    },

    // ========================================================================
    // UPDATE CONFIG
    // ========================================================================
    updateConfig(newConfig) {
        this._config = {...this._config, ...newConfig};
        localStorage.setItem('plannerPOIConfig', JSON.stringify(this._config));
        PlannerEvents.emit('poi:config-changed', this._config);
    },

    // ========================================================================
    // LOAD POI FOR SEGMENT (incremental)
    // ========================================================================
    async loadForSegment(segmentCoords, subtypes) {
        if (!this._config.enabled || subtypes.length === 0 || !segmentCoords || segmentCoords.length < 2) {
            return;
        }

        const lats = segmentCoords.map(c => c[0]);
        const lngs = segmentCoords.map(c => c[1]);

        // Buffer 15%
        const latRange = Math.max(...lats) - Math.min(...lats);
        const lngRange = Math.max(...lngs) - Math.min(...lngs);
        const buffer = 0.15;

        const bbox = [
            Math.min(...lngs) - (lngRange * buffer || 0.01), // min 0.01 dla krótkich segmentów
            Math.min(...lats) - (latRange * buffer || 0.01),
            Math.max(...lngs) + (lngRange * buffer || 0.01),
            Math.max(...lats) + (latRange * buffer || 0.01)
        ];

        console.log('📦 Loading POI for segment bbox:', bbox);

        try {
            const params = new URLSearchParams({
                bbox: bbox.join(','),
                subtypes: subtypes.join(','),
                limit: 500
            });

            const url = `${window.PLANNER_CONFIG.baseUrl}/api/poi/list.php?${params}`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.success && data.data?.features) {
                // ✅ Merge z istniejącymi (deduplikacja po poi_id)
                const existingIds = new Set(this._pois.map(p => p.properties.poi_id));
                const newPois = data.data.features.filter(p => !existingIds.has(p.properties.poi_id));

                this._pois.push(...newPois);
                console.log(`📍 Added ${newPois.length} new POI (total: ${this._pois.length})`);
            }
        } catch (error) {
            console.error('❌ Failed to load POI for segment:', error);
        }
    },

    // ========================================================================
    // LOAD POI (legacy - keep for compatibility)
    // ========================================================================
    async load(bbox, subtypes) {
        if (!this._config.enabled || subtypes.length === 0) {
            this._pois = [];
            return [];
        }

        try {
            const params = new URLSearchParams({
                bbox: bbox.join(','),
                subtypes: subtypes.join(','),
                limit: 500
            });

            const url = `${window.PLANNER_CONFIG.baseUrl}/api/poi/list.php?${params}`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.success && data.data?.features) {
                this._pois = data.data.features;
                console.log(`📍 Loaded ${this._pois.length} POI`);
                return this._pois;
            }

            this._pois = [];
            return [];
        } catch (error) {
            console.error('❌ Failed to load POI:', error);
            this._pois = [];
            return [];
        }
    },

    // ========================================================================
    // FILTER POI NEAR ROUTE
    // ========================================================================
    filterNearRoute(routeCoords, bufferM = 200) {
        if (!routeCoords || routeCoords.length < 2) {
            return [];
        }

        const nearbyPOI = [];

        this._pois.forEach(poi => {
            const poiCoords = poi.geometry.coordinates; // [lng, lat]
            const poiLat = poiCoords[1];
            const poiLng = poiCoords[0];

            // Find minimum distance to any segment
            let minDistance = Infinity;
            let closestSegmentIdx = -1;

            for (let i = 0; i < routeCoords.length - 1; i++) {
                const segStart = routeCoords[i]; // [lat, lng]
                const segEnd = routeCoords[i + 1];

                const dist = this._distanceToSegment(
                        poiLat, poiLng,
                        segStart[0], segStart[1],
                        segEnd[0], segEnd[1]
                        );

                if (dist < minDistance) {
                    minDistance = dist;
                    closestSegmentIdx = i;
                }
            }

            if (minDistance <= bufferM) {
                nearbyPOI.push({
                    ...poi,
                    _distance: Math.round(minDistance),
                    _segmentIdx: closestSegmentIdx
                });
            }
        });

        // Sort by segment index (order along route)
        nearbyPOI.sort((a, b) => a._segmentIdx - b._segmentIdx);

        console.log(`🎯 Found ${nearbyPOI.length} POI within ${bufferM}m of route`);
        return nearbyPOI;
    },
    // Klik na kartę POI w sidebarze → popup na mapie
    openPopup(feature) {
        const coords = feature.geometry.coordinates; // [lng, lat]
        const props = feature.properties;
        const map = PlannerMap.getMap();

        // Centruj mapę
        map.setView([coords[1], coords[0]], Math.max(map.getZoom(), 15), {animate: true});

        // Stwórz tymczasowy marker z popupem (reużywa logiki POILayer)
        const tempLayer = new POILayer();
        const marker = tempLayer.createMarker([coords[1], coords[0]], props);
        marker.addTo(map);

        // Załaduj szczegóły i otwórz popup
        tempLayer.loadPOIDetails(props.poi_id, marker);

        // Usuń marker gdy popup się zamknie
        marker.on('popupclose', () => map.removeLayer(marker));
    },

    // ========================================================================
    // DISTANCE POINT TO LINE SEGMENT (Haversine)
    // ========================================================================
    _distanceToSegment(pointLat, pointLng, seg1Lat, seg1Lng, seg2Lat, seg2Lng) {
        const dx = seg2Lng - seg1Lng;
        const dy = seg2Lat - seg1Lat;

        const lengthSquared = dx * dx + dy * dy;

        if (lengthSquared < 1e-10) {
            return this._haversineDistance(pointLat, pointLng, seg1Lat, seg1Lng);
        }

        let t = ((pointLng - seg1Lng) * dx + (pointLat - seg1Lat) * dy) / lengthSquared;
        t = Math.max(0, Math.min(1, t));

        const projLat = seg1Lat + t * dy;
        const projLng = seg1Lng + t * dx;

        return this._haversineDistance(pointLat, pointLng, projLat, projLng);
    },

    // ========================================================================
    // HAVERSINE DISTANCE (meters)
    // ========================================================================
    _haversineDistance(lat1, lng1, lat2, lng2) {
        const R = 6371000;
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lng2 - lng1) * Math.PI / 180;

        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                Math.cos(φ1) * Math.cos(φ2) *
                Math.sin(Δλ / 2) * Math.sin(Δλ / 2);

        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return R * c;
    },

    // ========================================================================
    // CLEAR
    // ========================================================================
    clear() {
        this._pois = [];
    }
};

console.log('✅ PlannerPOI module loaded');