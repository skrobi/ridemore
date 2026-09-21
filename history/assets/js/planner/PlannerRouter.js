/* ============================================================================
 PLANNER ROUTER - OSRM routing with snap-to-challenge support
 Optimized: only re-routes affected segments
 ============================================================================ */

window.PlannerRouter = {
    _queue: [],
    _processing: false,
    _cache: new Map(),
    _lastRequestTime: 0,
    _minInterval: 250, // ms between OSRM requests (rate limit)
    _retryLimit: 1,

    // ========================================================================
    // INIT
    // ========================================================================
    init() {
        PlannerEvents.on('waypoint:added', (d) => this.onWaypointAdded(d));
        PlannerEvents.on('waypoint:removed', (d) => this.onWaypointRemoved(d));
        PlannerEvents.on('waypoint:moved', (d) => this.onWaypointMoved(d));
        PlannerEvents.on('waypoints:cleared', () => this.onCleared());

        console.log('✅ PlannerRouter initialized');
    },

    // ========================================================================
    // EVENT HANDLERS - OPTIMIZED (only affected segments)
    // ========================================================================
    onWaypointAdded( { idx }) {
        if (PlannerState.isLoadingRoute)
            return;

        const wps = PlannerState.waypoints;
        if (wps.length < 2)
            return;

        if (idx === 0) {
            this.shiftSegmentsUp(0);
            this.requestSegment(0);
        } else if (idx === wps.length - 1) {
            this.requestSegment(idx - 1);
            this.requestSegment(idx - 2); // odświeź też przedostatni segment
        } else {
            this.shiftSegmentsUp(idx);
            PlannerRouteRenderer.removeSegment(idx - 1);
            this.requestSegment(idx - 1);
            this.requestSegment(idx);
    }
    },

    onWaypointRemoved( { idx }) {
        const wps = PlannerState.waypoints;

        if (wps.length < 1) {
            this.onCleared();
            return;
        }

        if (idx === 0) {
            PlannerRouteRenderer.removeSegment(0);
            this.shiftSegmentsDown(1);
        } else if (idx === wps.length) {
            PlannerRouteRenderer.removeSegment(idx - 1);
            PlannerState.routeSegments[idx - 1] = null;
        } else {
            PlannerRouteRenderer.removeSegment(idx - 1);
            PlannerRouteRenderer.removeSegment(idx);
            this.shiftSegmentsDown(idx + 1);

            if (idx > 0 && idx < wps.length) {
                this.requestSegment(idx - 1);
            }
    }
    },

    onWaypointMoved( { idx }) {
        const wps = PlannerState.waypoints;

        if (idx > 0) {
            this.requestSegment(idx - 1);
        }
        if (idx < wps.length - 1) {
            this.requestSegment(idx);
    }
    },

    onCleared() {
        this._queue = [];
        PlannerState.routeSegments = [];
        PlannerState.recalcTotals();
        PlannerRouteRenderer.clear();
    },

    // ========================================================================
    // SEGMENT SHIFTING (for insert/remove optimization)
    // ========================================================================
    shiftSegmentsUp(fromIdx) {
        const wps = PlannerState.waypoints;
        const segs = PlannerState.routeSegments;
        for (let i = wps.length - 2; i >= fromIdx; i--) {
            if (segs[i]) {
                segs[i + 1] = segs[i];
                segs[i + 1].fromIdx = i + 1;
                segs[i + 1].toIdx = i + 2;
                if (segs[i + 1].coords) {
                    PlannerRouteRenderer.renderSegment(i + 1, segs[i + 1].coords);
                }
            }
        }
        segs[fromIdx] = null;

        // Wymuś hit area dla ostatniego segmentu
        const lastSegIdx = wps.length - 2;
        if (PlannerState.routeSegments[lastSegIdx]?.coords) {
            PlannerWaypoints.updateSegmentHit(lastSegIdx, PlannerState.routeSegments[lastSegIdx].coords);
        }
    },

    shiftSegmentsDown(fromIdx) {
        const wps = PlannerState.waypoints;
        const segs = PlannerState.routeSegments;

        for (let i = fromIdx; i < wps.length; i++) {
            if (segs[i]) {
                segs[i - 1] = segs[i];
                segs[i - 1].fromIdx = i - 1;
                segs[i - 1].toIdx = i;

                if (segs[i - 1].coords) {
                    PlannerRouteRenderer.renderSegment(i - 1, segs[i - 1].coords);
                }
            }
        }

        const lastIdx = wps.length;
        if (segs[lastIdx]) {
            PlannerRouteRenderer.removeSegment(lastIdx);
            segs[lastIdx] = null;
        }
    },

    // ========================================================================
    // REQUEST SEGMENT
    // ========================================================================
    requestSegment(fromIdx) {
        const wps = PlannerState.waypoints;
        const from = wps[fromIdx];
        const to = wps[fromIdx + 1];
        if (!from || !to)
            return;

        // Cancel pending request for same segment
        this._queue = this._queue.filter(q => q.fromIdx !== fromIdx);

        // Check cache (only for non-snap routing)
        const snapEnabled = PlannerState.snapEnabled && PlannerState.challenge;
        if (!snapEnabled) {
            const cacheKey = this.cacheKey(from, to);
            const cached = this._cache.get(cacheKey);
            if (cached) {
                this.applySegment(fromIdx, cached);
                return;
            }
        }

        // Add to queue
        this._queue.push({fromIdx, from, to, retries: 0});
        this.processQueue();
    },

    // ========================================================================
    // REBUILD ALL (fallback for complex operations)
    // ========================================================================
    rebuildAllSegments() {
        PlannerRouteRenderer.clear();
        PlannerState.routeSegments = [];
        this._queue = [];

        const wps = PlannerState.waypoints;
        for (let i = 0; i < wps.length - 1; i++) {
            this.requestSegment(i);
        }
    },

    // ========================================================================
    // QUEUE PROCESSOR
    // ========================================================================
    async processQueue() {
        if (this._processing || this._queue.length === 0)
            return;
        this._processing = true;
        PlannerState.isRouting = true;

        while (this._queue.length > 0) {
            const job = this._queue.shift();

            // Rate limiting (only for OSRM requests)
            const snapEnabled = PlannerState.snapEnabled && PlannerState.challenge;
            if (!snapEnabled) {
                const now = Date.now();
                const elapsed = now - this._lastRequestTime;
                if (elapsed < this._minInterval) {
                    await this.sleep(this._minInterval - elapsed);
                }
            }

            try {
                const result = await this._routeBetween(job.from, job.to);

                if (!snapEnabled) {
                    this._lastRequestTime = Date.now();
                }

                if (result) {
                    // Cache only non-snap routes
                    if (!snapEnabled) {
                        this._cache.set(this.cacheKey(job.from, job.to), result);
                    }
                    await this.applySegment(job.fromIdx, result); // ✅ await async
                }
            } catch (err) {
                console.error('Routing error:', err);
                if (job.retries < this._retryLimit) {
                    job.retries++;
                    this._queue.push(job);
                } else {
                    this.applyFallback(job.fromIdx, job.from, job.to);
                }
            }
        }

        this._processing = false;
        PlannerState.isRouting = false;
        PlannerState.recalcTotals();
    },

    // ========================================================================
    // ROUTE BETWEEN TWO WAYPOINTS (with snap support)
    // ========================================================================
    async _routeBetween(fromWp, toWp) {
        console.log('🔀 Routing between waypoints');

        const snapEnabled = PlannerState.snapEnabled && PlannerState.challenge;

        // SNAP ROUTING: Use challenge route geometry instead of OSRM
        if (snapEnabled) {
            const snappedRoute = await this._extractChallengeSegment(fromWp, toWp);
            if (snappedRoute) {
                console.log('✅ Using challenge route (snap mode)');
                return snappedRoute;
            }
            console.log('⚠️ Snap failed, falling back to OSRM');
        }

        // NORMAL ROUTING: Use OSRM
        try {
            const route = await this.fetchRoute(
                    {lat: fromWp.lat, lng: fromWp.lng},
                    {lat: toWp.lat, lng: toWp.lng}
            );

            console.log(`✅ OSRM route: ${(route.distance / 1000).toFixed(1)}km`);
            return route;

        } catch (err) {
            console.error('❌ Routing failed:', err);
            throw err;
        }
    },

    // ========================================================================
    // SNAP TO CHALLENGE ROUTE
    // ========================================================================
    // ========================================================================
// SNAP TO CHALLENGE ROUTE - przeprojektowany
// Zastąp całą sekcję "SNAP TO CHALLENGE ROUTE" w PlannerRouter.js
// ========================================================================

    /**
     * Próbuje wyciągnąć segment wzdłuż trasy challenge.
     * Zwraca null jeśli snap nie ma sensu → fallback do OSRM.
     *
     * Przypadki:
     * A) fromWp i toWp na tej samej trasie, normalny kierunek → snap cały segment
     * B) fromWp blisko końca trasy, toWp za gapem → snap do końca trasy + OSRM gap
     * C) fromWp nie jest blisko żadnej trasy → null (OSRM)
     * D) Segment snapowany byłby >1.5x dłuższy niż prostoliniowy → null (OSRM)
     */
    async  _extractChallengeSegment(fromWp, toWp) {
        const routes = PlannerState.challengeRoutes;
        if (!routes?.features)
            return null;

        const SNAP_THRESHOLD = 100;       // m - max odległość od trasy żeby snap miał sens
        const DETOUR_RATIO = 1.8;       // snap nie może być dłuższy niż 1.8x odległość OSRM
        const END_ZONE = 0.15;      // ostatnie 15% trasy = "strefa końcowa" (gap detection)

        // Znajdź najlepszą trasę dla fromWp
        const fromMatch = this._findBestRouteMatch(routes.features, fromWp, SNAP_THRESHOLD);
        if (!fromMatch)
            return null; // fromWp poza wszystkimi trasami → OSRM

        const {feature, fromIdx} = fromMatch;
        const coords = feature.geometry.coordinates; // [lng, lat]
        const totalPoints = coords.length;

        // Znajdź toWp na tej samej trasie
        const toResult = this._findClosestPointOnLine(coords, toWp);

        // Sprawdź czy toWp jest blisko tej trasy
        const toOnSameRoute = toResult.distance <= SNAP_THRESHOLD * 3; // luźniejszy próg dla toWp

        // ====================================================================
        // CASE A: oba punkty na tej samej trasie, normalny kierunek
        // ====================================================================
        if (toOnSameRoute && toResult.index >= fromIdx) {
            const segment = this._sliceRoute(coords, fromIdx, toResult.index);
            if (!segment)
                return null;

            const snapDist = this._calculateDistance(segment);
            const directDist = this._haversineDistance(fromWp.lat, fromWp.lng, toWp.lat, toWp.lng);

            // Jeśli snap jedzie za długą drogą → oddaj OSRM
            if (snapDist > directDist * DETOUR_RATIO) {
                console.log(`⚠️ Snap detour ratio ${(snapDist / directDist).toFixed(1)}x > ${DETOUR_RATIO} → OSRM`);
                return null;
            }

            console.log(`✅ Snap CASE A: wzdłuż trasy ${fromIdx}→${toResult.index} (${(snapDist / 1000).toFixed(1)}km)`);
            return this._buildSegmentResult(segment, snapDist);
        }

        // ====================================================================
        // CASE B: fromWp w strefie końcowej trasy, toWp za gapem
        // Jedź snap do końca trasy, reszta przez OSRM
        // ====================================================================
        const fromPositionRatio = fromIdx / (totalPoints - 1);
        const isInEndZone = fromPositionRatio >= (1 - END_ZONE);

        if (isInEndZone && !toOnSameRoute) {
            console.log(`🔀 Snap CASE B: gap detected, snap do końca trasy + OSRM gap`);
            return await this._buildGapSegment(coords, fromIdx, toWp);
        }

        // ====================================================================
        // CASE C: toWp jest przed fromWp (odwrócony kierunek) → OSRM
        // ====================================================================
        if (toOnSameRoute && toResult.index < fromIdx) {
            const reverseSegmentLength = fromIdx - toResult.index;
            const forwardSegmentLength = (totalPoints - fromIdx) + toResult.index;

            // Jeśli jazda "w tył" jest znacząco krótsza niż "na około" → snap w tył
            if (reverseSegmentLength < forwardSegmentLength * 0.5) {
                const segment = this._sliceRoute(coords, toResult.index, fromIdx);
                if (segment) {
                    segment.reverse();
                    const snapDist = this._calculateDistance(segment);
                    console.log(`✅ Snap CASE C: krótki powrót (${(snapDist / 1000).toFixed(1)}km)`);
                    return this._buildSegmentResult(segment, snapDist);
                }
            }

            console.log(`⚠️ Snap CASE C: odwrócony kierunek, za długo → OSRM`);
            return null;
        }

        // ====================================================================
        // CASE D: toWp na innej trasie challenge → OSRM między trasami
        // ====================================================================
        console.log(`🗺️ Snap CASE D: różne trasy challenge → OSRM`);
        return null;
    },

    /**
     * Znajdź trasę challenge najbliższą fromWp w promieniu threshold
     */
    _findBestRouteMatch(features, wp, thresholdM) {
        let best = null;
        let bestDist = Infinity;

        for (const feature of features) {
            if (feature.geometry?.type !== 'LineString')
                continue;
            const coords = feature.geometry.coordinates;
            const result = this._findClosestPointOnLine(coords, wp);

            if (result.distance < bestDist && result.distance <= thresholdM) {
                bestDist = result.distance;
                best = {feature, fromIdx: result.index, distance: result.distance};
            }
        }

        return best;
    },

    /**
     * Wytnij segment z coords[[lng,lat]] i zamień na [[lat,lng]] dla Leaflet
     */
    _sliceRoute(coords, fromIdx, toIdx) {
        const slice = coords.slice(fromIdx, toIdx + 1);
        if (slice.length < 2)
            return null;
        return slice.map(c => [c[1], c[0]]); // [lng,lat] → [lat,lng]
    },

    /**
     * CASE B: snap do końca trasy + OSRM przez gap do toWp
     * Async — skleja snap część z routingiem przez gap
     */
    async _buildGapSegment(coords, fromIdx, toWp) {
        // Snap od fromIdx do końca trasy
        const snapPart = coords.slice(fromIdx).map(c => [c[1], c[0]]); // [lat,lng]
        if (snapPart.length < 1)
            return null;

        const routeEnd = snapPart[snapPart.length - 1]; // [lat, lng]
        const gapDist = this._haversineDistance(routeEnd[0], routeEnd[1], toWp.lat, toWp.lng);

        console.log(`🔀 Gap: snap(${snapPart.length}pts) + OSRM gap(${(gapDist / 1000).toFixed(1)}km)`);

        try {
            // OSRM tylko dla gap: koniec trasy → toWp
            const gapRoute = await this.fetchRoute(
                    {lat: routeEnd[0], lng: routeEnd[1]},
                    {lat: toWp.lat, lng: toWp.lng}
            );

            if (!gapRoute?.coords) {
                console.warn('⚠️ OSRM gap failed → fallback prosta linia');
                const combined = [...snapPart, [toWp.lat, toWp.lng]];
                return this._buildSegmentResult(combined, this._calculateDistance(combined));
            }

            // Sklej: snap + gap (pomiń pierwszy punkt gap = ostatni punkt snap)
            const gapCoords = gapRoute.coords.slice(1);
            const combined = [...snapPart, ...gapCoords];
            const dist = this._calculateDistance(combined);

            console.log(`✅ Gap segment sklejony: ${snapPart.length}+${gapCoords.length}pts, ${(dist / 1000).toFixed(1)}km`);
            return this._buildSegmentResult(combined, dist);

        } catch (err) {
            console.error('❌ Gap OSRM error:', err);
            // Fallback: prosta linia przez gap
            const combined = [...snapPart, [toWp.lat, toWp.lng]];
            return this._buildSegmentResult(combined, this._calculateDistance(combined));
        }
    },

    /**
     * Buduj wynik segmentu
     */
    _buildSegmentResult(coords, distance) {
        const duration = (distance / 1000 / PlannerState.averageSpeed) * 3600;
        return {coords, distance, duration};
    },

    /**
     * Find closest point on linestring to waypoint
     * Returns: { index: number, distance: number }
     */
    _findClosestPointOnLine(lineCoords, waypoint) {
        let minDist = Infinity;
        let closestIdx = 0;

        for (let i = 0; i < lineCoords.length; i++) {
            const coord = lineCoords[i];
            const dist = this._haversineDistance(
                    waypoint.lat,
                    waypoint.lng,
                    coord[1], // lat
                    coord[0]  // lng
                    );

            if (dist < minDist) {
                minDist = dist;
                closestIdx = i;
            }
        }

        return {index: closestIdx, distance: minDist};
    },

    /**
     * Haversine distance in meters
     */
    _haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371000; // Earth radius in meters
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

    /**
     * Calculate distance along path
     */
    _calculateDistance(coords) {
        let total = 0;
        for (let i = 0; i < coords.length - 1; i++) {
            total += this._haversineDistance(
                    coords[i][0], coords[i][1],
                    coords[i + 1][0], coords[i + 1][1]
                    );
        }
        return total;
    },

    // ========================================================================
    // FETCH FROM OSRM PROXY
    // ========================================================================
    async fetchRoute(from, to) {
        const profile = PlannerState.routingProfile;
        const wps = `${from.lng},${from.lat};${to.lng},${to.lat}`;
        const url = `${window.PLANNER_CONFIG.osrmProxy}?waypoints=${wps}&profile=${profile}`;

        const resp = await fetch(url);
        const data = await resp.json();

        if (!data.success || !data.data?.geometry) {
            throw new Error(data.error || 'No route returned');
        }

        const r = data.data;
        const coords = r.geometry.coordinates.map(c => [c[1], c[0]]);

        // Calculate duration based on custom speed
        const distanceKm = r.distance / 1000;
        const customDuration = (distanceKm / PlannerState.averageSpeed) * 3600;

        return {
            coords,
            distance: r.distance,
            duration: customDuration
        };
    },

    // ========================================================================
// APPLY SEGMENT RESULT
// ========================================================================
    async applySegment(fromIdx, result) {
        PlannerState.routeSegments[fromIdx] = {
            fromIdx,
            toIdx: fromIdx + 1,
            coords: result.coords,
            distance: result.distance,
            duration: result.duration
        };

        PlannerRouteRenderer.renderSegment(fromIdx, result.coords);

        // ✅ Load POI BEFORE emitting events (synchronous)
        await this._loadPOIForSegment(fromIdx, result.coords);

        // Now emit events after POI loaded
        PlannerEvents.emit('route:segment', {fromIdx, coords: result.coords});
        PlannerEvents.emit('segment:added', {
            segmentIdx: fromIdx,
            coords: result.coords
        });

        PlannerState.recalcTotals();
    },

// ========================================================================
// LOAD POI FOR SEGMENT (internal helper)
// ========================================================================
    async _loadPOIForSegment(segmentIdx, coords) {
        const config = PlannerPOI.getConfig();
        if (config.enabled && config.subtypes.length > 0) {
            console.log(`📍 Loading POI for segment ${segmentIdx} before emit`);
            await PlannerPOI.loadForSegment(coords, config.subtypes);
        }
    },
    // ========================================================================
    // FALLBACK (straight line when routing fails)
    // ========================================================================
    applyFallback(fromIdx, from, to) {
        const coords = [[from.lat, from.lng], [to.lat, to.lng]];
        const distance = this._haversineDistance(from.lat, from.lng, to.lat, to.lng);
        const duration = (distance / 1000 / PlannerState.averageSpeed) * 3600;

        this.applySegment(fromIdx, {
            coords,
            distance,
            duration
        });
    },

    // ========================================================================
    // UTILS
    // ========================================================================
    cacheKey(from, to) {
        return `${from.lat.toFixed(5)},${from.lng.toFixed(5)}→${to.lat.toFixed(5)},${to.lng.toFixed(5)}`;
    },

    sleep(ms) {
        return new Promise(r => setTimeout(r, ms));
    },

    clearCache() {
        this._cache.clear();
    }
};

console.log('✅ PlannerRouter module loaded');