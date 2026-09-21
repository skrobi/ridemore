/* ============================================================================
 PLANNER STATE - Central state + Event Bus
 All modules communicate through events, never import each other.
 ============================================================================ */

window.PlannerEvents = {
    _listeners: {},

    emit(event, data = {}) {
        const key = `planner:${event}`;
        console.log(`📡 Emitting event: ${key}`, data); // ✅ DODAJ
        document.dispatchEvent(new CustomEvent(key, {detail: data}));
    },

    on(event, handler) {
        const key = `planner:${event}`;
        const wrapped = (e) => handler(e.detail);
        if (!this._listeners[key])
            this._listeners[key] = [];
        this._listeners[key].push({original: handler, wrapped});
        document.addEventListener(key, wrapped);
    },

    off(event, handler) {
        const key = `planner:${event}`;
        const entries = this._listeners[key] || [];
        const entry = entries.find(e => e.original === handler);
        if (entry) {
            document.removeEventListener(key, entry.wrapped);
            this._listeners[key] = entries.filter(e => e !== entry);
        }
    }
};

window.PlannerState = {
    // Mode
    mode: 'route',
    routeReversed: false,
    isLoadingRoute: false,

    // Waypoints & routing
    waypoints: [],
    routeSegments: [],

    // Challenge
    challenge: null,
    challengeRoutes: null,
    challengeCoverage: {
        totalDistance: 0, // Total km in challenge
        coveredDistance: 0, // km covered by planned route
        coveragePercent: 0, // %
        routesCovered: [], // Array of route_ids with coverage > 0
        routesCompleted: []    // Array of route_ids with coverage >= 95%
    },

    // Days
    days: [],

    // Totals
    totalDistance: 0,
    totalDuration: 0,

    // UI flags
    isRouting: false,
    snapEnabled: true,
    snapThreshold: 500,
    canUndo: false,
    canRedo: false,
    routeName: 'Nowa trasa',

    routingProfile: 'bike', // 'bike', 'car', 'foot'
    averageSpeed: 20, // km/h - domyślna dla bike

    // Counters
    _wpIdCounter: 0,

    // ========================================================================
    // WAYPOINT HELPERS (Alpine reactivity-safe + pure functions)
    // ========================================================================

    nextWpId() {
        return `wp_${++this._wpIdCounter}`;
    },

    // W sekcji WAYPOINT HELPERS dodaj:

    setWaypointAsDayBreak(idx) {
        if (idx < 0 || idx >= this.waypoints.length)
            return;
        if (idx === 0 || idx === this.waypoints.length - 1)
            return; // Nie można na start/end

        const wp = this.waypoints[idx];
        wp.type = 'day-break';

        // Dodaj do listy dni jeśli jeszcze nie ma
        const existingDay = this.days.find(d => d.afterWpIdx === idx);
        if (!existingDay) {
            const dayNum = this.days.filter(d => d.afterWpIdx < idx).length + 1;
            this.days.push({dayNum, afterWpIdx: idx});
            this.days.sort((a, b) => a.afterWpIdx - b.afterWpIdx);
        }

        // Force reactivity
        this.waypoints = [...this.waypoints];
        PlannerEvents.emit('day:added', {idx});
    },

    removeWaypointDayBreak(idx) {
        if (idx < 0 || idx >= this.waypoints.length)
            return;

        const wp = this.waypoints[idx];
        if (wp.type !== 'day-break')
            return;

        wp.type = 'via';

        // Usuń z listy dni
        this.days = this.days.filter(d => d.afterWpIdx !== idx);

        // Force reactivity - REASSIGN tablicy
        this.waypoints = [...this.waypoints];

        PlannerEvents.emit('day:removed', {idx});
    },

    addWaypoint(wp, idx = null) {
        if (idx !== null) {
            this.waypoints.splice(idx, 0, wp);
        } else {
            this.waypoints.push(wp);
        }
        // Force Alpine reactivity
        this.waypoints = [...this.waypoints];
    },

    removeWaypoint(idx) {
        this.waypoints.splice(idx, 1);
        // Force Alpine reactivity
        this.waypoints = [...this.waypoints];
    },

    clearWaypoints() {
        this.waypoints = [];
    },

    restoreWaypoints(waypoints) {
        this.waypoints = [...waypoints];
    },

    // Pure functions - accept waypoints array as parameter
    getWpType(idx, waypoints = null) {
        const wps = waypoints || this.waypoints;
        const len = wps.length;
        if (len === 0)
            return 'start';
        if (idx === 0)
            return 'start';
        if (idx === len - 1 && len > 1)
            return 'end';
        const wp = wps[idx];
        if (wp && wp.type === 'day-break')
            return 'day-break';
        return 'via';
    },

    getWpLabel(idx, waypoints = null) {
        const type = this.getWpType(idx, waypoints);
        if (type === 'start')
            return 'A';
        if (type === 'end')
            return 'B';
        if (type === 'day-break')
            return '🏕';
        return String(idx);
    },

    getWpDefaultName(idx, waypoints = null) {
        const type = this.getWpType(idx, waypoints);
        if (type === 'start')
            return 'Start';
        if (type === 'end')
            return 'Koniec';
        if (type === 'day-break') {
            const dayNum = this.days.filter(d => d.afterWpIdx <= idx).length + 1;
            return `Koniec dnia ${dayNum}`;
        }
        return `Punkt ${idx}`;
    },

    // ========================================================================
    // TOTALS
    // ========================================================================


    recalcTotals() {
        let dist = 0, dur = 0;
        this.routeSegments.forEach(seg => {
            if (seg) {
                dist += seg.distance || 0;
                dur += seg.duration || 0;
            }
        });

        this.totalDistance = dist;
        this.totalDuration = dur;

        // Recalculate challenge coverage if challenge loaded
        if (this.challenge && this.challengeRoutes) {
            this.calculateChallengeCoverage();
        }

        PlannerEvents.emit('route:updated');
    },

    // NEW: Calculate challenge coverage
    calculateChallengeCoverage() {
        if (!this.challenge || !this.challengeRoutes || !this.challengeRoutes.features) {
            this.challengeCoverage = {
                totalDistance: 0,
                coveredDistance: 0,
                coveragePercent: 0,
                routesCovered: [],
                routesCompleted: []
            };
            return;
        }

        const features = this.challengeRoutes.features;
        const totalDistance = features.reduce((sum, f) => sum + (f.properties.distance_km || 0), 0);

        const routeCoverage = new Map(); // route_id -> { distance, covered }

        // Initialize all routes
        features.forEach(f => {
            const routeId = f.properties.route_id;
            routeCoverage.set(routeId, {
                totalDistance: f.properties.distance_km * 1000, // to meters
                coveredDistance: 0,
                geometry: f.geometry.coordinates
            });
        });

        // Check each segment against challenge routes
        this.routeSegments.forEach(seg => {
            if (!seg || !seg.coords)
                return;

            // For each segment coordinate, find which challenge route it's on
            seg.coords.forEach(coord => {
                const [lat, lng] = coord;

                features.forEach(f => {
                    const routeId = f.properties.route_id;
                    const routeData = routeCoverage.get(routeId);

                    // Check if this point is near this route (within 50m)
                    const isNear = this._isPointNearRoute(lat, lng, f.geometry.coordinates, 50);

                    if (isNear) {
                        // Approximate coverage by segment length / number of points
                        const segmentLength = seg.distance / seg.coords.length;
                        routeData.coveredDistance += segmentLength;
                    }
                });
            });
        });

        // Calculate totals
        let totalCovered = 0;
        const routesCovered = [];
        const routesCompleted = [];

        routeCoverage.forEach((data, routeId) => {
            const coverage = Math.min(100, (data.coveredDistance / data.totalDistance) * 100);

            if (coverage > 0) {
                routesCovered.push({routeId, coverage: coverage.toFixed(1)});
                totalCovered += data.coveredDistance;
            }

            if (coverage >= 95) {
                routesCompleted.push(routeId);
            }
        });

        this.challengeCoverage = {
            totalDistance: totalDistance,
            coveredDistance: totalCovered / 1000, // to km
            coveragePercent: totalDistance > 0 ? ((totalCovered / 1000) / totalDistance * 100).toFixed(1) : 0,
            routesCovered,
            routesCompleted
        };
    },

    // Helper: Check if point is near route
    _isPointNearRoute(lat, lng, routeCoords, maxDistanceMeters) {
        for (let i = 0; i < routeCoords.length; i++) {
            const [rLng, rLat] = routeCoords[i];
            const dist = this._haversineDistance(lat, lng, rLat, rLng);

            if (dist <= maxDistanceMeters) {
                return true;
            }
        }
        return false;
    },

    // Haversine distance
    _haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371000;
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lon2 - lon1) * Math.PI / 180;

        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                Math.cos(φ1) * Math.cos(φ2) *
                Math.sin(Δλ / 2) * Math.sin(Δλ / 2);

        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return R * c;
    },

    // ========================================================================
    // RESET
    // ========================================================================

    reset() {
        this.waypoints = [];
        this.routeSegments = [];
        this.totalDistance = 0;
        this.totalDuration = 0;
        this.routeName = '';
        this.routeReversed = false;  // ✅ DODAJ
        this.challenge = null;
        this.challengeRoutes = null;
        this.challengeCoverage = {coveragePercent: 0, routesCovered: [], routesCompleted: []};
        PlannerEvents.emit('waypoints:cleared');
    }
};

console.log('✅ PlannerState + PlannerEvents loaded');