/* ============================================================================
 ROUTE HIGHLIGHT MODULE
 Unified route highlighting for recommendations & library
 Version: 2.0 - Hover over selected routes enabled
 ============================================================================ */

window.RouteHighlightModule = {
    
    /**
     * Highlight route on map (card hover)
     * @param {number} routeId 
     */
    highlightRoute(routeId) {
        //console.log('🔵 Highlighting route:', routeId);
        
        if (!window.mapManager) {
            console.warn('⚠️ MapManager not available');
            return;
        }
        
        const overlayLayers = window.mapManager.getOverlayLayers();
        
        for (const layer of overlayLayers) {
            const layerKey = `${layer.config.slug}_${routeId}`;
            const layerGroup = layer.getItem(layerKey);
            
            if (layerGroup?._layers3) {
                const props = layerGroup._layers3.main.routeData;
                
                // ✅ ZMIANA: Zawsze aplikuj highlight, nawet jeśli selected
                // Card-hover ma wyższy z-index (550) niż selected (500)
                layer.applyHighlightState(layerGroup, props);
                
                // ✅ CRITICAL: Force bring to front (w razie problemów z z-index)
                if (layerGroup._layers3.outline.bringToFront) {
                    layerGroup._layers3.outline.bringToFront();
                }
                if (layerGroup._layers3.core.bringToFront) {
                    layerGroup._layers3.core.bringToFront();
                }
                if (layerGroup._layers3.main.bringToFront) {
                    layerGroup._layers3.main.bringToFront();
                }
                
                //console.log(`✅ Highlighted in layer: ${layer.config.slug} (even if selected)`);
                return;
            }
        }
        
        console.warn(`⚠️ Route ${routeId} not found on map`);
    },
    
    /**
     * Remove highlight (card mouseout)
     * @param {number} routeId 
     */
    unhighlightRoute(routeId) {
        //console.log('⚪ Unhighlighting route:', routeId);
        
        if (!window.mapManager) return;
        
        const overlayLayers = window.mapManager.getOverlayLayers();
        
        for (const layer of overlayLayers) {
            const layerKey = `${layer.config.slug}_${routeId}`;
            const layerGroup = layer.getItem(layerKey);
            
            if (layerGroup?._layers3) {
                const props = layerGroup._layers3.main.routeData;
                
                // ✅ ZMIANA: Przywróć odpowiedni stan (selected lub normal)
                if (layer.selectedRoute === routeId) {
                    // Jeśli trasa jest selected - przywróć selected state
                    //console.log(`🔴 Restoring SELECTED state for route ${routeId}`);
                    layer.applySelectedState(layerGroup, props);
                } else {
                    // Jeśli nie jest selected - przywróć normal
                    layer.applyNormalState(layerGroup, props);
                }
                
                //console.log(`✅ Unhighlighted in layer: ${layer.config.slug}`);
                return;
            }
        }
    },
    
    /**
     * Center map on route
     * @param {number} routeId 
     */
    async centerOnRoute(routeId) {
        //console.log('🎯 Centering on route:', routeId);
        
        if (!window.mapManager || !window.map) {
            console.warn('⚠️ Map not available');
            return;
        }
        
        const overlayLayers = window.mapManager.getOverlayLayers();
        
        // Try to find route in existing layers
        for (const layer of overlayLayers) {
            const layerKey = `${layer.config.slug}_${routeId}`;
            const layerGroup = layer.getItem(layerKey);
            
            if (layerGroup?._layers3) {
                const bounds = layerGroup._layers3.main.getBounds();
                window.map.fitBounds(bounds, {
                    padding: [50, 50],
                    maxZoom: 14
                });
                //console.log(`✅ Centered on route in layer: ${layer.config.slug}`);
                return;
            }
        }
        
        // Route not on map - load it
        //console.log('📡 Route not on map, loading...');
        await this.loadAndCenterRoute(routeId);
    },
    
    /**
     * Load single route and center on it
     * @param {number} routeId 
     */
    async loadAndCenterRoute(routeId) {
        try {
            const url = window.APP_CONFIG.api(`routes/details.php?id=${routeId}`);
            const response = await fetch(url);
            const data = await response.json();
            
            if (!data.success) {
                console.error('❌ Failed to load route');
                return;
            }
            
            const route = data.data;
            
            // Find appropriate layer
            const overlayLayers = window.mapManager.getOverlayLayers();
            let targetLayer = null;
            
            if (route.layers && route.layers.length > 0) {
                for (const layer of overlayLayers) {
                    if (route.layers.includes(layer.config.slug)) {
                        targetLayer = layer;
                        break;
                    }
                }
            }
            
            if (!targetLayer && overlayLayers.length > 0) {
                targetLayer = overlayLayers[0];
            }
            
            if (!targetLayer) {
                console.error('❌ No overlay layer available');
                return;
            }
            
            // Ensure layer is visible
            if (!targetLayer.visible) {
                targetLayer.show();
            }
            
            // ✅ DODAJ FLAGĘ - blokuj automatyczne reload podczas fitBounds
            window.mapManager._skipNextReload = true;
            
            // Load route into layer
            await targetLayer.loadSingleRoute(routeId);
            
            // Center on it
            const layerKey = `${targetLayer.config.slug}_${routeId}`;
            const layerGroup = targetLayer.getItem(layerKey);
            
            if (layerGroup?._layers3) {
                const bounds = layerGroup._layers3.main.getBounds();
                window.map.fitBounds(bounds, {
                    padding: [50, 50],
                    maxZoom: 14
                });
                //console.log(`✅ Route loaded and centered`);
                
                // ✅ Reset flag po 1 sekundzie (po zakończeniu animacji fitBounds)
                setTimeout(() => {
                    window.mapManager._skipNextReload = false;
                }, 1000);
            }
            
        } catch (error) {
            console.error('❌ Error loading route:', error);
        }
    },
    
    /**
     * Show route on map (load if needed, highlight, center)
     * @param {number} routeId 
     */
    async showRouteOnMap(routeId) {
        //console.log('🗺️ Showing route on map:', routeId);
        
        // First try to highlight existing
        this.highlightRoute(routeId);
        
        // Then center (will load if needed)
        await this.centerOnRoute(routeId);
    }
};

//console.log('✅ RouteHighlightModule v2.0 loaded (hover over selected enabled)');