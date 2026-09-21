/* ============================================================================
 OVERLAY LAYER - WITH 3-LAYER RENDERING
 Version: 4.3 - Edge Browser Compatible
 ============================================================================ */

class OverlayLayer extends BaseLayer {
    constructor(layerConfig) {
        super({
            type: layerConfig.slug,
            apiEndpoint: window.APP_CONFIG.api('routes/list.php')
        });
        this.config = layerConfig;

        //console.log('🔧 OverlayLayer config:', layerConfig.name);
        //console.log('  - stroke_width:', layerConfig.stroke_width);
        //console.log('  - opacity:', layerConfig.opacity);
        //console.log('  - color:', layerConfig.color);
        //console.log('  - visible_default:', layerConfig.visible_default);

        this.selectedRoute = null;
        this.hoveredRoute = null;
        this.sourceFilter = layerConfig.source_filter || null;
        
        // ✅ Edge compatibility: Track active tooltips
        this.activeTooltips = new Map();
        
        // ✅ Edge: Throttle hover events
        this.hoverThrottleTimers = new Map();
    }

    // ========================================================================
    // FETCH - WITH CACHE
    // ========================================================================

    async fetch(bbox, filters) {
        //console.log(`📍 ${this.config.name}: fetching routes...`);

        // ✅ Cache
        const cacheKey = `${this.config.slug}_${bbox.join(',')}`;
        const cached = await this.cache.get(this.config.slug, cacheKey);

        if (cached && !this.shouldRefresh(cached)) {
            //console.log(`✅ ${this.config.name}: from cache (${cached.data?.features?.length || 0} routes)`);
            return cached.data;
        }

        const params = new URLSearchParams({
            bbox: bbox.join(','),
            limit: 500
        });

        if (this.config.slug) {
            params.append('layers', this.config.slug);
        }

        const userLocalId = localStorage.getItem('user_local_id');
        if (userLocalId) {
            params.append('user_local_id', userLocalId);
        }

        const url = `${this.apiEndpoint}?${params}`;
        //console.log(`🌐 ${this.config.name}: ${url}`);

        const response = await fetch(url);
        const data = await response.json();

        //console.log(`📦 ${this.config.name}: ${data.data?.features?.length || 0} routes`);

        if (data.success) {
            // Save to cache
            await this.cache.set(this.config.slug, cacheKey, {
                data: data.data,
                timestamp: Date.now()
            });
        }

        return data.success ? data.data : null;
    }

    // ========================================================================
    // RENDER - WITH SMART CACHE & SELECTED ROUTE PROTECTION
    // ========================================================================

    render(geojson) {
        if (!geojson || !geojson.features || geojson.features.length === 0) {
            return;
        }

        //console.log(`🎨 ${this.config.name}: rendering ${geojson.features.length} routes`);

        // ✅ KROK 1: Zbierz ID nowych tras
        const newRouteIds = new Set(
            geojson.features.map(f => f.properties.route_id)
        );

        // ✅ KROK 2: Usuń TYLKO trasy które wyszły z bbox (ALE NIE SELECTED!)
        const toRemove = [];
        for (const [layerKey, layerGroup] of this.items.entries()) {
            const parts = layerKey.split('_');
            const routeId = parseInt(parts[parts.length - 1]);

            // ✅ GUARD 1: Nie usuwaj selected route
            if (this.selectedRoute && routeId === this.selectedRoute) {
                //console.log(`🔒 Keeping selected route ${layerKey} (protected)`);
                continue;
            }

            // ✅ GUARD 2: Nie usuwaj trasy załadowanej w ostatnich 5 sekundach
            if (layerGroup._loadedAt) {
                const ageMs = Date.now() - layerGroup._loadedAt;
                if (ageMs < 5000) {
                    //console.log(`🔒 Keeping recently loaded route ${layerKey}`);
                    continue;
                }
            }

            if (!newRouteIds.has(routeId)) {
                toRemove.push(layerKey);
            }
        }

        toRemove.forEach(key => {
            //console.log(`🗑️ Removing route ${key} (out of bbox)`);
            this.removeItem(key);
        });

        // ✅ KROK 3: Dodaj TYLKO nowe trasy
        let addedCount = 0;

        geojson.features.forEach(feature => {
            // ✅ Walidacja
            if (!feature.geometry?.coordinates || !Array.isArray(feature.geometry.coordinates)) {
                console.warn('⚠️ Invalid geometry, skipping route:', feature.properties?.route_id);
                return;
            }

            const props = feature.properties;
            const routeId = props.route_id;
            const layerRouteKey = `${this.config.slug}_${routeId}`;

            // Pomiń jeśli już istnieje
            if (this.hasItem(layerRouteKey)) {
                return;
            }

            addedCount++;

            const initialState = (this.selectedRoute === routeId) ? 'selected' : 'normal';

            const zoom = window.map ? window.map.getZoom() : 12;
            const layers3 = window.RouteStyles.render3Layer(this.config, zoom, initialState, props);
            const coords = feature.geometry.coordinates.map(c => [c[1], c[0]]);

            // Create 3 layers
            const outlineLayer = L.polyline(coords, layers3.outline);
            const mainLayer = L.polyline(coords, layers3.main);
            const coreLayer = L.polyline(coords, layers3.core);

            // ✅ mainLayer NA GÓRZE (przechwytuje eventy)
            const layerGroup = L.layerGroup([outlineLayer, coreLayer, mainLayer]);

            // ✅ Setup interactions na MAINLAYER
            this.setupInteractions(mainLayer, props, layerGroup);

            // Add to map and items
            layerGroup.addTo(this.layerGroup);
            this.addItem(layerRouteKey, layerGroup);

            // ✅ DODAJ TIMESTAMP (chroni trasę przez 5 sekund po załadowaniu)
            layerGroup._loadedAt = Date.now();

            // Store reference to individual layers
            layerGroup._layers3 = {
                outline: outlineLayer,
                main: mainLayer,
                core: coreLayer
            };
        });
        
        if (this.selectedRoute) {
            const selectedKey = `${this.config.slug}_${this.selectedRoute}`;
            const selectedGroup = this.getItem(selectedKey);

            if (selectedGroup?._layers3) {
                const props = selectedGroup._layers3.main.routeData;
                this.applySelectedState(selectedGroup, props);
                //console.log('🔴 Re-applied selected state to route:', this.selectedRoute);
            }
        }
        
        //console.log(`✅ ${this.config.name}: +${addedCount} new, -${toRemove.length} removed, total: ${this.items.size}`);
        this.updateLayerCount();
    }

    updateLayerCount() {
        const checkbox = document.querySelector(`input[data-layer="${this.config.slug}"]`);
        if (checkbox) {
            const label = checkbox.closest('label');
            if (label) {
                const countSpan = label.querySelector('span:last-child');
                if (countSpan) {
                    countSpan.textContent = `(${this.items.size})`;
                }
            }
        }
    }

    // ========================================================================
    // STATE MANAGEMENT - UNIFIED WITH GUARDS
    // ========================================================================

    applyState(layerGroup, props, stateName) {
        if (!layerGroup._layers3) return;

        const zoom = window.map ? window.map.getZoom() : 12;
        const layers3 = window.RouteStyles.render3Layer(this.config, zoom, stateName, props);

        const targetPaneName = layers3.main.pane;
        
        ['outline', 'main', 'core'].forEach(layerType => {
            const layer = layerGroup._layers3[layerType];
            const newStyle = layers3[layerType];
            
            // ✅ Jeśli pane się zmienił - usuń i dodaj do nowego pane
            if (layer.options.pane !== targetPaneName) {
                // Usuń z mapy
                layer.remove();
                
                // Zmień pane w options
                layer.options.pane = targetPaneName;
                
                // Dodaj z powrotem (Leaflet sam użyje nowego pane)
                layer.addTo(this.layerGroup);
            }
            
            // Zawsze aktualizuj style
            layer.setStyle(newStyle);
        });
    }

    applyHoverState(layerGroup, props) {
        this.applyState(layerGroup, props, 'hover');
    }

    applyNormalState(layerGroup, props) {
        //console.log('⚪ Applying NORMAL state for route:', props.route_id);
        this.applyState(layerGroup, props, 'normal');
    }

    applySelectedState(layerGroup, props) {
        //console.log('🔴 APPLYING SELECTED STATE for route:', props.route_id);
        this.applyState(layerGroup, props, 'selected');
    }

    applyHighlightState(layerGroup, props) {
        if (!layerGroup._layers3) return;

        const zoom = window.map ? window.map.getZoom() : 12;

        // ✅ Użyj HOVER state (niebieski) zamiast selected (czerwony)
        const layers3 = window.RouteStyles.render3Layer(this.config, zoom, 'card-hover', props);

        layerGroup._layers3.outline.setStyle(layers3.outline);
        layerGroup._layers3.main.setStyle(layers3.main);
        layerGroup._layers3.core.setStyle(layers3.core);

        layerGroup._layers3.outline.bringToFront();
        layerGroup._layers3.core.bringToFront();
        layerGroup._layers3.main.bringToFront();
    }

    // ========================================================================
    // INTERACTIONS - EDGE COMPATIBLE
    // ========================================================================

    setupInteractions(mainLayer, props, layerGroup) {
        const routeId = props.route_id;

        mainLayer.routeId = routeId;
        mainLayer.routeData = props;

        // ✅ Edge fix: Remove old handlers more aggressively
        this.cleanupHandlers(mainLayer);

        mainLayer._handlers = {};

        // ========== CLICK - EDGE COMPATIBLE ==========
        mainLayer._handlers.click = (e) => {
            //console.log('🖱️ CLICK on route:', routeId, 'layer:', this.config.slug);

            // ✅ Edge: Use both Leaflet AND native event stopping
            if (e.originalEvent) {
                e.originalEvent.stopPropagation();
                e.originalEvent.preventDefault();
            }
            L.DomEvent.stopPropagation(e);
            L.DomEvent.preventDefault(e);

            // ✅ Edge: Force tooltip cleanup before action
            this.forceCloseTooltip(mainLayer);

            if (window.map && window.map.closePopup) {
                try {
                    window.map.closePopup();
                } catch (err) {
                    console.warn('Popup close error:', err);
                }
            }

            this.selectRoute(routeId);
            this.openRouteDetailsPanel(routeId);
        };

        // ========== HOVER - EDGE COMPATIBLE ==========
        mainLayer._handlers.mouseover = (e) => {
            //console.log('🔵 HOVER on route:', routeId);

            if (this.selectedRoute === routeId) return;

            // ✅ Edge: Check if we're already hovering this route
            if (this.hoveredRoute === routeId) {
                //console.log('⏭️ Already hovering route:', routeId);
                return;
            }

            // ✅ Edge: Throttle rapid hover events
            const existingTimer = this.hoverThrottleTimers.get(routeId);
            if (existingTimer) {
                //console.log('⏸️ Throttling hover for route:', routeId);
                return;
            }

            // Set throttle timer
            this.hoverThrottleTimers.set(routeId, true);
            setTimeout(() => {
                this.hoverThrottleTimers.delete(routeId);
            }, 200); // 200ms throttle

            this.applyHighlightState(layerGroup, props);
            this.hoveredRoute = routeId;

            if (!this.isPanelOpen()) {
                this.showTooltip(mainLayer, props);
            }
        };

        // ========== MOUSEOUT - EDGE COMPATIBLE ==========
        mainLayer._handlers.mouseout = (e) => {
            //console.log('⚪ MOUSEOUT from route:', routeId);

            if (this.selectedRoute === routeId) {
                //console.log('⚠️ Ignoring mouseout - route is selected');
                return;
            }

            this.applyNormalState(layerGroup, props);
            this.hoveredRoute = null;
            
            // ✅ Edge: Force cleanup tooltip with delay
            setTimeout(() => {
                this.forceCloseTooltip(mainLayer);
            }, 50);
        };

        // ✅ Edge: Use standard event binding
        mainLayer.on('click', mainLayer._handlers.click);
        mainLayer.on('mouseover', mainLayer._handlers.mouseover);
        mainLayer.on('mouseout', mainLayer._handlers.mouseout);

        // ✅ Edge: Additional safeguard - mouseleave
        mainLayer.on('mouseleave', () => {
            if (this.selectedRoute !== routeId) {
                setTimeout(() => {
                    this.forceCloseTooltip(mainLayer);
                }, 50);
            }
        });
    }

    // ========================================================================
    // TOOLTIP MANAGEMENT - EDGE COMPATIBLE
    // ========================================================================

    showTooltip(layer, props) {
        // ✅ Edge: Don't create tooltip if one already exists for this layer
        if (layer._tooltip && layer._tooltip._container && layer._tooltip._container.parentNode) {
            //console.log('ℹ️ Tooltip already exists, skipping creation');
            return;
        }

        // ✅ Edge: Force close any existing tooltip first
        this.forceCloseTooltip(layer);

        const tooltipContent = `<strong>${props.name}</strong><br>📏 ${props.distance_km} km • 📈 ${props.ascent_m} m`;

        const tooltip = L.tooltip({
            permanent: false,
            direction: 'top',
            className: 'route-tooltip',
            opacity: 0.9,
            interactive: false,  // ✅ Critical for Edge
            offset: [0, -15],
            sticky: true
        }).setContent(tooltipContent);

        layer.bindTooltip(tooltip);

        // ✅ Edge: Use requestAnimationFrame for smoother rendering
        requestAnimationFrame(() => {
            if (layer.openTooltip && typeof layer.openTooltip === 'function') {
                try {
                    layer.openTooltip();
                    this.activeTooltips.set(layer._leaflet_id, layer);
                    //console.log('✅ Tooltip created for:', props.name);
                } catch (err) {
                    console.warn('Tooltip open error:', err);
                }
            }
        });
    }

    forceCloseTooltip(layer) {
        if (!layer) return;

        // ✅ Edge: Multi-step cleanup
        try {
            // Step 1: Close if open
            if (layer.closeTooltip && typeof layer.closeTooltip === 'function') {
                layer.closeTooltip();
            }

            // Step 2: Unbind
            if (layer.unbindTooltip && typeof layer.unbindTooltip === 'function') {
                layer.unbindTooltip();
            }

            // Step 3: Remove from active tracking
            if (layer._leaflet_id) {
                this.activeTooltips.delete(layer._leaflet_id);
            }

            // Step 4: Force remove tooltip DOM element (Edge workaround)
            if (layer._tooltip && layer._tooltip._container) {
                const container = layer._tooltip._container;
                if (container.parentNode) {
                    //console.log('🗑️ Removing tooltip from DOM:', container.textContent?.substring(0, 30));
                    container.parentNode.removeChild(container);
                }
                // ✅ CRITICAL: Clear tooltip reference
                layer._tooltip = null;
                delete layer._tooltip;
            }
            
        } catch (err) {
            console.warn('Force tooltip cleanup error:', err);
        }
    }

    // ✅ NEW: Remove ALL orphaned tooltips from DOM
    cleanupOrphanedTooltips() {
        // ✅ REMOVED - causing infinite loop in Edge
        // This method is now deprecated
    }

    // ✅ NEW: Cleanup all tooltips for this layer
    cleanupAllTooltips() {
        this.activeTooltips.forEach((layer, id) => {
            this.forceCloseTooltip(layer);
        });
        this.activeTooltips.clear();
        
        // ✅ EDGE FIX: Final sweep - remove ALL .route-tooltip elements
        setTimeout(() => {
            const allTooltips = document.querySelectorAll('.route-tooltip');
            allTooltips.forEach(tooltip => {
                if (tooltip.parentNode) {
                    //console.log('🧹 Final cleanup - removing tooltip');
                    tooltip.parentNode.removeChild(tooltip);
                }
            });
        }, 100);
    }

    // ========================================================================
    // HANDLER CLEANUP - EDGE COMPATIBLE
    // ========================================================================

    cleanupHandlers(mainLayer) {
        if (mainLayer._handlers) {
            // ✅ Edge: Remove all event types
            ['click', 'mouseover', 'mouseout', 'mouseleave'].forEach(eventType => {
                if (mainLayer._handlers[eventType]) {
                    try {
                        mainLayer.off(eventType, mainLayer._handlers[eventType]);
                    } catch (err) {
                        console.warn(`Handler cleanup error (${eventType}):`, err);
                    }
                }
            });
            delete mainLayer._handlers;
        }

        // ✅ Edge: Force tooltip cleanup
        this.forceCloseTooltip(mainLayer);
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    openRouteDetailsPanel(routeId) {
        const appEl = document.querySelector('[x-data="app"]');
        if (appEl && window.Alpine) {
            const app = Alpine.$data(appEl);
            if (app?.openRouteDetails) {
                app.openRouteDetails(routeId);
            }
        }
    }

    isPanelOpen() {
        const appEl = document.querySelector('[x-data="app"]');
        if (appEl && window.Alpine) {
            const app = Alpine.$data(appEl);
            return app?.selectedRoute != null;
        }
        return false;
    }

    // ========================================================================
    // ROUTE SELECTION
    // ========================================================================

    async selectRoute(routeId) {
        if (window.mapManager) {
            window.mapManager.deselectAllRoutes();
        }

        const layerRouteKey = `${this.config.slug}_${routeId}`;
        let layerGroup = this.getItem(layerRouteKey);

        if (!layerGroup) {
            //console.log('📡 Route not on map, loading...');
            await this.loadSingleRoute(routeId);
            layerGroup = this.getItem(layerRouteKey);
        }

        if (layerGroup?._layers3) {
            const data = layerGroup._layers3.main.routeData;

            if (!this.visible) {
                this.show();
            }

            if (!window.map.hasLayer(this.layerGroup)) {
                window.map.addLayer(this.layerGroup);
                this.visible = true;
            }

            this.selectedRoute = routeId;
            //console.log('🔴 Setting selectedRoute:', routeId);

            this.applySelectedState(layerGroup, data);
            //console.log('🎨 Applied selected state');

            const bounds = layerGroup._layers3.main.getBounds();
            if (window.map) {
                window.map.fitBounds(bounds, {
                    padding: [50, 50],
                    maxZoom: 14
                });
            }
        }
    }

    async loadSingleRoute(routeId) {
        try {
            const url = window.APP_CONFIG.api(`routes/details.php?id=${routeId}`);
            const response = await fetch(url);
            const data = await response.json();

            if (data.success && data.data.geometry) {
                const feature = {
                    type: 'Feature',
                    geometry: data.data.geometry,
                    properties: {
                        route_id: data.data.route_id,
                        name: data.data.name,
                        type: data.data.type,
                        distance_km: data.data.distance_km,
                        ascent_m: data.data.ascent_m,
                        descent_m: data.data.descent_m,
                        difficulty_score: data.data.difficulty_score,
                        asphalt_pct: data.data.asphalt_pct,
                        gravel_pct: data.data.gravel_pct,
                        final_score: data.data.final_score,
                        in_top_layer: data.data.in_top_layer,
                        in_medal_layer: data.data.in_medal_layer,
                        is_loop: data.data.is_loop,
                        is_completed: data.data.is_completed || false
                    }
                };

                this.render({features: [feature]});
            }
        } catch (error) {
            console.error('❌ Failed to load single route:', error);
        }
    }

    deselectRoute() {
        if (!this.selectedRoute) {
            //console.log('ℹ️ No route selected in', this.config.slug);
            return;
        }

        //console.log('🔄 Deselecting route:', this.selectedRoute, 'in layer:', this.config.slug);

        const layerRouteKey = `${this.config.slug}_${this.selectedRoute}`;
        const layerGroup = this.getItem(layerRouteKey);

        if (layerGroup?._layers3) {
            const data = layerGroup._layers3.main.routeData;
            const zoom = window.map ? window.map.getZoom() : 12;

            // ✅ FIX: Użyj 'normal' zamiast routeId (które nie istnieje)
            const normalStyles = window.RouteStyles.render3Layer(
                this.config,
                zoom,
                'normal',
                data
            );

            //console.log('🎨 Setting NORMAL styles:', normalStyles);

            layerGroup._layers3.outline.setStyle(normalStyles.outline);
            layerGroup._layers3.main.setStyle(normalStyles.main);
            layerGroup._layers3.core.setStyle(normalStyles.core);

            // ✅ DODAJ: Przenieś z powrotem do overlayPane
            const targetPane = window.map.getPane('overlayPane');
            if (targetPane) {
                ['outline', 'main', 'core'].forEach(layerType => {
                    const layer = layerGroup._layers3[layerType];
                    const svgPath = layer._path;

                    if (svgPath) {
                        let svgElement = svgPath.parentNode;
                        if (svgElement && svgElement.tagName === 'g') {
                            svgElement = svgElement.parentNode;
                        }

                        if (svgElement && svgElement.tagName === 'svg' && svgElement.parentNode !== targetPane) {
                            targetPane.appendChild(svgElement);
                        }
                    }
                });
            }

            //console.log('✅ Deselected route:', this.selectedRoute);
        } else {
            console.warn('⚠️ Layer not found for deselect:', layerRouteKey);
        }

        this.selectedRoute = null;
    }

    // ========================================================================
    // CLEANUP - ENHANCED FOR EDGE
    // ========================================================================

    removeItem(routeId) {
        const layer = this.getItem(routeId);
        if (layer) {
            // ✅ Edge: Cleanup handlers from mainLayer
            if (layer._layers3?.main) {
                this.cleanupHandlers(layer._layers3.main);
            }

            this.layerGroup.removeLayer(layer);
            this.items.delete(routeId);
        }
    }

    clear() {
        // ✅ Edge: Cleanup all tooltips first
        this.cleanupAllTooltips();

        // ✅ Edge: Cleanup handlers from all layers
        this.items.forEach((layerGroup, routeId) => {
            if (layerGroup._layers3?.main) {
                this.cleanupHandlers(layerGroup._layers3.main);
            }
        });

        this.layerGroup.clearLayers();
        this.items.clear();
    }
}

window.OverlayLayer = OverlayLayer;
//console.log('✅ OverlayLayer v4.3 loaded (Edge compatible)');