/* ============================================================================
 PLANNER APP - Alpine.js orchestrator (THIN - logic in modules)
 ============================================================================ */
console.log('🔥 PlannerApp.js EXECUTING');
document.addEventListener('alpine:init', () => {
    console.log('🟢 alpine:init EVENT FIRED');

    Alpine.data('plannerApp', () => ({
            // Data properties (not getters) - Alpine tracks these
            waypoints: [],
            routeSegments: [],
            challenge: null,
            totalDistance: 0,
            totalDuration: 0,
            isRouting: false,
            snapToChallenge: true,
            canUndo: false,
            canRedo: false,
            collapsedDays: new Set(),
            coverage: {},
            poiConfig: PlannerPOI.getConfig(), // ✅ POI config

            // ✅ Alias dla HTML które używa $root.poiSubtypes
            get poiSubtypes() {
                return this.poiConfig?.subtypes ?? [];
            },

            // ====================================================================
            // COMPUTED: Group waypoints by days
            // ====================================================================
            get groupedDays() {
                if (!this.waypoints || this.waypoints.length === 0)
                    return [];

                const days = [];
                let currentDay = {num: 1, waypoints: [], stats: {distance: 0, duration: 0}};

                this.waypoints.forEach((wp, idx) => {
                    currentDay.waypoints.push({...wp, idx});

                    if (this.routeSegments[idx]) {
                        currentDay.stats.distance += this.routeSegments[idx].distance || 0;
                        currentDay.stats.duration += this.routeSegments[idx].duration || 0;
                    }

                    if (wp.type === 'day-break') {
                        days.push(currentDay);
                        currentDay = {num: days.length + 1, waypoints: [], stats: {distance: 0, duration: 0}};
                    }
                });

                if (currentDay.waypoints.length > 0)
                    days.push(currentDay);
                return days;
            },

            // ====================================================================
            // COMPUTED: Days with POI cards
            // ====================================================================
            get enrichedDays() {
                const days = this.groupedDays;

                // ════════════════════════════════════════════
                // DEBUG PUNKT 1 — czy getter w ogóle odpala?
                // ════════════════════════════════════════════
                console.group('🔍 enrichedDays CALLED');
                console.log('1️⃣ days:', days.length);
                console.log('2️⃣ poiConfig.enabled:', this.poiConfig.enabled);
                console.log('3️⃣ poiConfig.subtypes:', [...this.poiConfig.subtypes]);
                console.log('4️⃣ PlannerPOI._pois.length:', PlannerPOI._pois.length);
                console.log('5️⃣ routeSegments z coords:', this.routeSegments.filter(s => s?.coords).length);

                if (!this.poiConfig.enabled || days.length === 0) {
                    console.warn('⛔ EARLY RETURN — disabled lub brak dni');
                    console.groupEnd();
                    return days;
                }

                const routeCoords = [];
                this.routeSegments.forEach(seg => {
                    if (seg?.coords)
                        routeCoords.push(...seg.coords);
                });

                // ════════════════════════════════════════════
                // DEBUG PUNKT 2 — czy mamy coords trasy?
                // ════════════════════════════════════════════
                console.log('6️⃣ routeCoords total:', routeCoords.length);

                if (routeCoords.length < 2) {
                    console.warn('⛔ EARLY RETURN — za mało coords');
                    console.groupEnd();
                    return days;
                }

                // Dystans kumulatywny
                const cumDist = [0];
                for (let i = 1; i < routeCoords.length; i++) {
                    cumDist.push(cumDist[i - 1] + PlannerGeo.haversineDistance(
                            routeCoords[i - 1][0], routeCoords[i - 1][1],
                            routeCoords[i][0], routeCoords[i][1]
                            ));
                }

                const nearbyPOI = PlannerPOI.filterNearRoute(routeCoords, this.poiConfig.bufferM);

                // ════════════════════════════════════════════
                // DEBUG PUNKT 3 — co zwraca filterNearRoute?
                // ════════════════════════════════════════════
                console.log('7️⃣ nearbyPOI po filterNearRoute:', nearbyPOI.length);
                console.log('8️⃣ sample POI (pierwsze 3):', nearbyPOI.slice(0, 3).map(p => ({
                        name: p.properties?.name,
                        role: p.properties?.poi_role,
                        subtype: p.properties?.subtype_slug
                    })));

                if (nearbyPOI.length === 0) {
                    console.warn('⛔ EARLY RETURN — brak POI w pobliżu');
                    console.groupEnd();
                    return days;
                }

                nearbyPOI.forEach(poi => {
                    poi._waypointSegment = this._getWaypointSegmentForCoordIdx(poi._segmentIdx);
                    poi._routeDistanceM = cumDist[poi._segmentIdx] ?? 0;
                });

                const result = days.map(day => {
                    const enrichedWaypoints = [];

                    day.waypoints.forEach(wp => {
                        enrichedWaypoints.push(wp);

                        const poisAfter = nearbyPOI.filter(p => p._waypointSegment === wp.idx);

                        // ════════════════════════════════════════════
                        // DEBUG PUNKT 4 — ile POI trafia do każdego WP?
                        // ════════════════════════════════════════════
                        if (poisAfter.length > 0) {
                            console.log(`9️⃣ WP idx=${wp.idx}: ${poisAfter.length} POI`,
                                    poisAfter.map(p => p.properties?.name));
                        }

                        if (poisAfter.length === 0)
                            return;

                        const attractions = poisAfter.filter(p => (p.properties.poi_role ?? 'attraction') === 'attraction');
                        const logistics = poisAfter.filter(p => p.properties.poi_role === 'logistics');
                        const safety = poisAfter.filter(p => p.properties.poi_role === 'safety');

                        // ════════════════════════════════════════════
                        // DEBUG PUNKT 5 — podział na grupy
                        // ════════════════════════════════════════════
                        console.log(`🔀 WP idx=${wp.idx} grupy:`, {
                            attractions: attractions.length,
                            logistics: logistics.length,
                            safety: safety.length
                        });

                        const addGroup = (pois, role) => {
                            if (pois.length === 0)
                                return;
                            enrichedWaypoints.push({
                                type: 'poi-group-header',
                                role,
                                count: pois.length,
                                idx: wp.idx
                            });
                            pois.forEach(poi => {
                                enrichedWaypoints.push({
                                    type: 'poi',
                                    poi,
                                    idx: wp.idx,
                                    routeDistanceKm: +(poi._routeDistanceM / 1000).toFixed(1)
                                });
                            });
                        };

                        addGroup(safety, 'safety');
                        addGroup(attractions, 'attraction');
                        addGroup(logistics, 'logistics');
                    });

                    return {...day, waypoints: enrichedWaypoints};
                });

                // ════════════════════════════════════════════
                // DEBUG PUNKT 6 — finalny wynik
                // ════════════════════════════════════════════
                console.log('🏁 RESULT — dni:', result.length,
                        'łączne waypoints+poi:', result.reduce((s, d) => s + d.waypoints.length, 0));
                console.groupEnd();

                return result;
            },

            // Map route coord index to waypoint segment index
            _getWaypointSegmentForCoordIdx(coordIdx) {
                let cumulativeCoords = 0;

                for (let i = 0; i < this.routeSegments.length; i++) {
                    const seg = this.routeSegments[i];
                    if (!seg?.coords)
                        continue;

                    const segCoordsCount = seg.coords.length;

                    // Check if coordIdx falls within this segment
                    if (coordIdx < cumulativeCoords + segCoordsCount) {
                        return i; // This waypoint segment
                    }

                    cumulativeCoords += segCoordsCount - 1; // -1 because last coord = first coord of next segment
                }

                return this.routeSegments.length - 1; // Default to last segment
            },

            // ====================================================================
            // CHALLENGE COVERAGE STATS
            // ====================================================================
            get challengeCoverageStats() {
                if (!this.challenge)
                    return null;
                return this.coverage;
            },

            // ====================================================================
            // INIT
            // ====================================================================
            async init() {
                console.log('🚀 INIT CALLED');
                if (window._plannerInitialized)
                    return;
                window._plannerInitialized = true;

                PlannerMap.init();
                setTimeout(() => PlannerMap.getMap().invalidateSize(), 100);
                PlannerHistory.init();
                PlannerRouter.init();
                PlannerWaypoints.init();
                PlannerChallengeLayer.init();
                PlannerToolbar.init('.map-toolbar');
                PlannerPOI.init();
                PlannerMap.setCursor('crosshair');

                // Zoom-adaptive weights
                PlannerMap.getMap().on('zoomend', () => PlannerRouteRenderer.onZoomChange());

                this.poiConfig = PlannerPOI.getConfig();
                console.log('🔧 Force synced POI config:', this.poiConfig);

                // Watch POI config changes
                PlannerEvents.on('poi:config-changed', async (config) => {
                    this.poiConfig = config;
                    PlannerPOI.clear();
                    await this.reloadPOI();
                    window._plannerSidebarPOIManager?.reloadPOI(); 
                    this.waypoints = [...PlannerState.waypoints];
                });

                // Initial POI load if enabled
                setTimeout(() => {
                    if (this.poiConfig.enabled && this.poiConfig.subtypes.length > 0) {
                        console.log('🚀 Initial POI load triggered');
                        this.reloadPOI();
                    }
                }, 1000);

                // ================================================================
                // Sync Alpine data with PlannerState on events
                // ================================================================
                const refresh = () => {
                    if (PlannerState.isLoadingRoute)
                        return;
                    this.waypoints = [...PlannerState.waypoints];
                    this.routeSegments = [...PlannerState.routeSegments];
                    this.challenge = PlannerState.challenge;
                    this.totalDistance = PlannerState.totalDistance;
                    this.totalDuration = PlannerState.totalDuration;
                    this.isRouting = PlannerState.isRouting;
                    this.canUndo = PlannerState.canUndo;
                    this.canRedo = PlannerState.canRedo;
                    this.coverage = {...PlannerState.challengeCoverage};

                    if (this.poiConfig.enabled) {
                        this.debouncedPOIReload();
                    }
                };

                ['route:updated', 'waypoint:added', 'waypoint:removed',
                    'waypoint:moved', 'history:changed', 'challenge:loaded',
                    'challenge:cleared', 'waypoints:cleared',
                    'day:added', 'day:removed'].forEach(e => {
                    PlannerEvents.on(e, refresh);
                });

                this._refresh = refresh;
                refresh();

                this.$watch('snapToChallenge', (val) => {
                    PlannerState.snapEnabled = val;
                });

                if (window.PLANNER_CONFIG.challengeId) {
                    await PlannerChallengeLayer.load(window.PLANNER_CONFIG.challengeId);
                }

                if (window.PLANNER_CONFIG.editRouteId) {
                    await this.loadRoute(window.PLANNER_CONFIG.editRouteId);
                }

                // ================================================================
                // POI WARSTWA MAPOWA — zarządzana przez LayerControlsUI + checkboxy DOM
                // ================================================================
                const poiLayerMap = new POILayer();
                poiLayerMap.map = PlannerMap.getMap();
                poiLayerMap.layerGroup = L.layerGroup().addTo(PlannerMap.getMap());
                poiLayerMap.clusterGroup = this._createClusterGroup();
                poiLayerMap.clusterGroup.addTo(poiLayerMap.layerGroup);
                poiLayerMap.visible = true;

                // Manager mapowy — czyta z checkboxów DOM (useSidebarConfig: false domyślnie)
                const poiManager = new PlannerPOIManager(PlannerMap.getMap(), {poi: poiLayerMap});
                window._plannerPOIManager = poiManager;

                // ================================================================
                // POI WARSTWA SIDEBARA — zarządzana przez PlannerPOI.getConfig()
                // ================================================================
                const poiLayerSidebar = new POILayer();
                poiLayerSidebar.map = PlannerMap.getMap();
                poiLayerSidebar.layerGroup = L.layerGroup().addTo(PlannerMap.getMap());
                poiLayerSidebar.clusterGroup = this._createClusterGroup();
                poiLayerSidebar.clusterGroup.addTo(poiLayerSidebar.layerGroup);
                poiLayerSidebar.visible = true;

                // Manager sidebara — czyta z PlannerPOI.getConfig()
                const sidebarPoiManager = new PlannerPOIManager(
                        PlannerMap.getMap(),
                        {poi: poiLayerSidebar},
                        {useSidebarConfig: true}
                );
                window._plannerSidebarPOIManager = sidebarPoiManager;

                // ================================================================
                // LayerControlsUI dostaje tylko warstwę mapową
                // ================================================================
                const controlsUI = new LayerControlsUI(PlannerMap.getMap(), {poi: poiLayerMap});
                controlsUI.addPOIControl(poiManager);

                PlannerMap.getMap().on('moveend', () => {
                    poiManager.reloadPOIOnMove();
                    sidebarPoiManager.reloadPOIOnMove();
                });

                console.log('✅ PlannerApp initialized');
            },

// ================================================================
// HELPER — tworzy clusterGroup (DRY)
// ================================================================
            _createClusterGroup() {
                return L.markerClusterGroup({
                    maxClusterRadius: 50,
                    spiderfyOnMaxZoom: true,
                    showCoverageOnHover: false,
                    zoomToBoundsOnClick: true,
                    iconCreateFunction(cluster) {
                        const count = cluster.getChildCount();
                        return L.divIcon({
                            html: `<div style="background: #00d4ff; color: white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-weight: bold; box-shadow: 0 2px 8px rgba(0,0,0,0.3);">${count}</div>`,
                            className: 'poi-cluster',
                            iconSize: L.point(40, 40)
                        });
                    }
                });
            },

            // ====================================================================
            // POI RELOAD (debounced)
            // ====================================================================
            _poiReloadTimeout: null,

            debouncedPOIReload() {
                clearTimeout(this._poiReloadTimeout);
                this._poiReloadTimeout = setTimeout(() => this.reloadPOI(), 500);
            },

            async reloadPOI() {
                if (!this.poiConfig.enabled || this.poiConfig.subtypes.length === 0) {
                    PlannerPOI.clear();
                    return;
                }

                // ✅ Load POI for all route segments incrementally
                for (let i = 0; i < this.routeSegments.length; i++) {
                    const seg = this.routeSegments[i];
                    if (seg?.coords && seg.coords.length > 0) {
                        await PlannerPOI.loadForSegment(seg.coords, this.poiConfig.subtypes);
                    }
                }
            },

            // ====================================================================
            // WAYPOINT METHODS
            // ====================================================================
            focusWaypoint(idx) {
                const wp = PlannerState.waypoints[idx];
                if (!wp)
                    return;

                const map = PlannerMap.getMap();
                map.setView([wp.lat, wp.lng], Math.max(map.getZoom(), 15), {
                    animate: true,
                    duration: 0.5
                });

                const marker = PlannerWaypoints._markers.get(wp.id);
                if (marker) {
                    const el = marker.getElement();
                    if (el) {
                        el.classList.add('wp-pulse');
                        setTimeout(() => el.classList.remove('wp-pulse'), 2000);
                    }
                }
            },

            // ====================================================================
            // DAY METHODS
            // ====================================================================
            toggleDay(dayNum) {
                if (this.collapsedDays.has(dayNum)) {
                    this.collapsedDays.delete(dayNum);
                } else {
                    this.collapsedDays.add(dayNum);
                }
                this.collapsedDays = new Set(this.collapsedDays);
            },

            isDayCollapsed(dayNum) {
                return this.collapsedDays.has(dayNum);
            },

            // ====================================================================
            // SIDEBAR METHODS
            // ====================================================================
            removeWaypoint(idx) {
                PlannerWaypoints.remove(idx);
            },

            highlightWaypoint(idx) {
                const wp = PlannerState.waypoints[idx];
                if (wp) {
                    const m = PlannerWaypoints._markers.get(wp.id);
                    if (m)
                        m.getElement()?.classList.add('wp-highlighted');
                }
            },

            unhighlightWaypoint(idx) {
                const wp = PlannerState.waypoints[idx];
                if (wp) {
                    const m = PlannerWaypoints._markers.get(wp.id);
                    if (m)
                        m.getElement()?.classList.remove('wp-highlighted');
                }
            },

            showWaypointMenu(event, wpIdx) {
                PlannerWaypoints.showContextMenu(event, wpIdx);
            },

            showSegmentMenu(event, segIdx) {
                const menu = document.createElement('div');
                menu.className = 'planner-context-menu';
                menu.style.cssText = `position: fixed; left: ${event.clientX}px; top: ${event.clientY}px; background: white; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.15); padding: 4px 0; z-index: 9999; min-width: 180px; font-size: 13px;`;

                const items = [
                    {
                        label: '➕ Dodaj punkt pośredni',
                        action: () => {
                            const seg = this.routeSegments[segIdx];
                            if (!seg || !seg.coords)
                                return;
                            const midIdx = Math.floor(seg.coords.length / 2);
                            const midCoord = seg.coords[midIdx];
                            PlannerWaypoints.insertBetween(segIdx, midCoord[0], midCoord[1]);
                        }
                    },
                    {label: '📝 Dodaj notatkę', action: () => alert('Notatki - w przygotowaniu')}
                ];

                items.forEach(item => {
                    const el = document.createElement('div');
                    el.textContent = item.label;
                    el.style.cssText = 'padding: 8px 16px; cursor: pointer; transition: background 0.1s;';
                    el.onmouseenter = () => el.style.background = '#f3f4f6';
                    el.onmouseleave = () => el.style.background = '';
                    el.onclick = () => {
                        item.action();
                        menu.remove();
                    };
                    menu.appendChild(el);
                });

                document.body.appendChild(menu);
                setTimeout(() => {
                    document.addEventListener('click', () => menu.remove(), {once: true});
                }, 10);
            },

            getWaypointType(idx) {
                return PlannerState.getWpType(idx, this.waypoints);
            },

            getWaypointLabel(idx) {
                return PlannerState.getWpLabel(idx, this.waypoints);
            },

            getDefaultLabel(idx) {
                return PlannerState.getWpDefaultName(idx, this.waypoints);
            },

            clearAll() {
                if (!confirm('Wyczyścić całą trasę?'))
                    return;
                PlannerWaypoints.clearAll();
                PlannerRouteRenderer.clear();
                PlannerHistory.clear();
                PlannerState.reset();
            },

            clearChallenge() {
                PlannerChallengeLayer.clear();
            },

            // ====================================================================
            // LOAD ROUTE (edit mode)
            // ====================================================================
            async loadRoute(routeId) {
                try {
                    console.log('📂 Loading route:', routeId);

                    const resp = await fetch(
                            `${window.PLANNER_CONFIG.baseUrl}/api/planner/load.php?route_id=${routeId}`,
                            {
                                headers: {
                                    'Authorization': `Bearer ${localStorage.getItem('auth_token') || ''}`
                                }
                            }
                    );

                    const data = await resp.json();

                    if (!data.success) {
                        alert('❌ Błąd: ' + (data.error || 'Nie udało się wczytać trasy'));
                        return;
                    }

                    const route = data.route;
                    const cfg = route.planner_config;

                    console.log('✅ Route loaded:', route);

                    // ✅ Set route name in PlannerState
                    PlannerState.routeName = route.name;
                    console.log('📤 Emitting route:loaded event with data:', {
                        name: route.name,
                        route_type: route.route_type,
                        difficulty_level: route.difficulty_level
                    });
                    // ✅ Zapisz globalnie — dla komponentów które init() później niż event
                    window._loadedRouteData = route;
                    PlannerEvents.emit('route:loaded', route);

                    if (!cfg)
                        return;

                    // Bike profile
                    if (cfg.bike_type) {
                        PlannerState.bikeType = cfg.bike_type;
                    }
                    if (cfg.avg_speed) {
                        PlannerState.averageSpeed = cfg.avg_speed;
                    }

                    // ✅ Restore reversed state
                    if (cfg.route_reversed) {
                        PlannerState.routeReversed = cfg.route_reversed;
                        console.log('🔄 Route was reversed');
                    }

                    // Load challenge if referenced
                    if (cfg.challenge_id && !PlannerState.challenge) {
                        await PlannerChallengeLayer.load(cfg.challenge_id);
                    }

                    // ====================================================================
                    // RESTORE WAYPOINTS + SEGMENTS (bez reroutingu przez OSRM)
                    // ====================================================================
                    if (cfg.waypoints?.length >= 2) {
                        console.log(`📍 Restoring ${cfg.waypoints.length} waypoints`);

                        // 1. Zablokuj router i refresh podczas restore
                        PlannerState.isLoadingRoute = true;

                        // 2. Dodaj waypoints (router śpi)
                        for (const wp of cfg.waypoints) {
                            PlannerWaypoints.add(wp.lat, wp.lng, null, {
                                label: wp.label,
                                type: wp.type === 'day-break' ? 'via' : (wp.type || 'via'),
                                snapped: wp.snapped || false,
                                skipSnap: true
                            });
                        }

                        // 3. Ustaw day-breaks
                        cfg.waypoints.forEach((wp, idx) => {
                            if (wp.type === 'day-break') {
                                PlannerWaypoints.setDayBreak(idx);
                            }
                        });

                        // 4. Odblokuj router
                        PlannerState.isLoadingRoute = false;

                        // 5. Wczytaj segmenty z PHP (bez OSRM)
                        if (cfg.segments?.length > 0) {
                            console.log(`✅ Restoring ${cfg.segments.length} segments from saved geometry`);
                            this._restoreSegments(cfg.segments, cfg.avg_speed || PlannerState.averageSpeed);
                        } else {
                            // Fallback: reroutuj (stare zapisy bez segmentów)
                            console.warn('⚠️ No segments in planner_config, falling back to OSRM reroute');
                            PlannerRouter.rebuildAllSegments();
                        }

                        // 6. Jeden rerender Alpine po wszystkim
                        this.waypoints = [...PlannerState.waypoints];
                        this.routeSegments = [...PlannerState.routeSegments];
                        this.totalDistance = PlannerState.totalDistance;
                        this.totalDuration = PlannerState.totalDuration;
                        this.coverage = {...PlannerState.challengeCoverage};

                        // 7. invalidateSize + fit bounds
                        setTimeout(() => {
                            // ✅ Leaflet musi poznać rozmiar kontenera po restore
                            PlannerMap.getMap().invalidateSize();

                            const validWaypoints = cfg.waypoints.filter(w => w.type !== 'day-break');
                            if (validWaypoints.length > 0) {
                                const bounds = L.latLngBounds(
                                        validWaypoints.map(w => [w.lat, w.lng])
                                        );
                                PlannerMap.getMap().fitBounds(bounds, {padding: [50, 50]});
                            }
                            this._waitForPOIConfigAndReload();
                        }, 500);
                    }

                } catch (err) {
                    console.error('Load route error:', err);
                    alert('❌ Błąd wczytywania trasy');
                }
            },

            // ====================================================================
            // RESTORE SEGMENTS (z danych PHP, bez OSRM)
            // ====================================================================
            _restoreSegments(segments, avgSpeed) {
                PlannerRouteRenderer.clear();
                PlannerState.routeSegments = [];

                segments.forEach(seg => {
                    const duration = avgSpeed > 0
                            ? (seg.distance / 1000 / avgSpeed) * 3600
                            : (seg.duration ?? 0);

                    PlannerState.routeSegments[seg.fromIdx] = {
                        fromIdx: seg.fromIdx,
                        toIdx: seg.toIdx,
                        coords: seg.coords, // już [lat, lng] z PHP
                        distance: seg.distance,
                        duration: duration
                    };

                    PlannerRouteRenderer.renderSegment(seg.fromIdx, seg.coords);
                    PlannerWaypoints.updateSegmentHit(seg.fromIdx, seg.coords);
                });

                // Ręczne przeliczenie totals bez emitowania route:updated
                // (żeby uniknąć triggera refresh() podczas restore)
                let dist = 0, dur = 0;
                PlannerState.routeSegments.forEach(s => {
                    if (s) {
                        dist += s.distance || 0;
                        dur += s.duration || 0;
                    }
                });
                PlannerState.totalDistance = dist;
                PlannerState.totalDuration = dur;

                // ✅ POI reload po restore (nie triggeruje przez route:updated)
                if (this.poiConfig.enabled && this.poiConfig.subtypes.length > 0) {
                    this.debouncedPOIReload();
                }

                console.log(`✅ Restored ${segments.length} segments from saved geometry`);
            },

            _waitForPOIConfigAndReload(attempt = 0) {
                const MAX_ATTEMPTS = 20;
                const INTERVAL = 100;

                const cfg = PlannerPOI.getConfig();

                if (cfg.enabled && cfg.subtypes.length > 0) {
                    console.log(`✅ POI config ready (attempt ${attempt})`);
                    PlannerPOI.clear();
                    this.reloadPOI().then(() => {
                        this.waypoints = [...PlannerState.waypoints];
                    });
                    return;
                }

                if (attempt === 0 && !cfg.enabled) {
                    console.log('ℹ️ POI disabled, skipping');
                    return;
                }

                if (attempt < MAX_ATTEMPTS) {
                    setTimeout(() => this._waitForPOIConfigAndReload(attempt + 1), INTERVAL);
                } else {
                    console.warn('⚠️ POI config not ready after 2s');
            }
            },

            // ====================================================================
            // SAVE
            // ====================================================================
            async saveRoute() {
                if (PlannerState.waypoints.length < 2) {
                    alert('Dodaj co najmniej 2 punkty do trasy.');
                    return;
                }

                const coords = PlannerRouteRenderer.getFullRouteCoords();
                if (coords.length === 0) {
                    alert('Brak wyznaczonej trasy do zapisania.');
                    return;
                }

                // ✅ Try to get data from details form (if Alpine ready)
                const detailsSection = document.querySelector('.route-details-section');
                console.log('🔍 Details section:', detailsSection);

                let name = '';
                let description = null;
                let tagline = null;
                let difficultyLevel = null;
                let routeType = null;
                let visibility = 'private';

                // ✅ Access Alpine data via _x_dataStack (Alpine 3.x)
                if (detailsSection?._x_dataStack?.[0]) {
                    const details = detailsSection._x_dataStack[0];
                    name = details.routeName?.trim() || '';
                    description = details.description?.trim() || null;
                    tagline = details.tagline?.trim() || null;
                    difficultyLevel = details.difficultyLevel || null;
                    routeType = details.routeType || null;
                    visibility = details.visibility || 'private';

                    console.log('✅ Got name from form:', name);
                    console.log('📋 Full details:', details);
                }

                console.log('🔍 Final name before prompt check:', name);
                if (!name) {
                    console.log('❌ Name is empty, showing prompt');
                    name = prompt('Nazwa trasy:', PlannerState.routeName || '');
                    if (!name || !name.trim()) {
                        return;
                    }
                    name = name.trim();
                } else {
                    console.log('✅ Name exists, skipping prompt');
                }

                // Convert [lat, lng] → [lng, lat] for GeoJSON
                const geoCoords = coords.map(c => [c[1], c[0]]);

                const payload = {
                    name: name,
                    description: description,
                    tagline: tagline,
                    difficulty_level: difficultyLevel,
                    route_type: routeType || this.inferRouteType(),
                    visibility: visibility,
                    geometry: {
                        type: 'LineString',
                        coordinates: geoCoords
                    },
                    distance_km: +(PlannerState.totalDistance / 1000).toFixed(2),
                    estimated_time_hours: +(PlannerState.totalDuration / 3600).toFixed(1),
                    challenge_id: PlannerState.challenge?.challenge_id || null,
                    bike_type: PlannerState.bikeType || 'road',
                    avg_speed: PlannerState.averageSpeed || 25,
                    waypoints: PlannerState.waypoints.map(wp => ({
                            id: wp.id,
                            lat: wp.lat,
                            lng: wp.lng,
                            type: wp.type,
                            label: wp.label,
                            snapped: wp.snapped
                        })),
                    route_reversed: PlannerState.routeReversed || false,
                    ascent_m: null,
                    descent_m: null
                };

                const isEditing = window.PLANNER_CONFIG.editRouteId !== null;
                if (isEditing) {
                    payload.route_id = window.PLANNER_CONFIG.editRouteId;
                }

                try {
                    const endpoint = isEditing
                            ? '/api/planner/update.php'
                            : '/api/planner/save.php';

                    const resp = await fetch(`${window.PLANNER_CONFIG.baseUrl}${endpoint}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': `Bearer ${localStorage.getItem('auth_token') || ''}`
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await resp.json();

                    if (data.success) {
                        const action = isEditing ? 'zaktualizowana' : 'zapisana';
                        alert(`✅ Trasa ${action}!`);
                        PlannerState.routeName = name;

                        if (!isEditing && data.route_id) {
                            window.PLANNER_CONFIG.editRouteId = data.route_id;
                            window.history.replaceState(null, '', `?route_id=${data.route_id}`);
                        }

                    } else {
                        alert('❌ Błąd: ' + (data.error || 'Nieznany błąd'));
                    }
                } catch (err) {
                    console.error('Save error:', err);
                    alert('❌ Błąd zapisu trasy.');
                }
            },

            // Helper: Infer route type from bike profile
            inferRouteType() {
                const type = PlannerState.bikeType || 'road';
                const mapping = {
                    'road': 'road',
                    'gravel': 'gravel',
                    'mtb': 'mtb'
                };
                return mapping[type] || 'mixed';
            },

            // ====================================================================
            // UTILS
            // ====================================================================
            formatDuration(s) {
                if (!s)
                    return '0 min';
                const h = Math.floor(s / 3600);
                const m = Math.round((s % 3600) / 60);
                return h > 0 ? `${h}h ${m}min` : `${m} min`;
            }
        }));
});

console.log('✅ PlannerApp orchestrator loaded');