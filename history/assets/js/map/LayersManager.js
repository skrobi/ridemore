/* ============================================================================
 LAYERS MANAGER
 Zarządzanie warstwami MVT, Overlay, POI
 ============================================================================ */

class LayersManager {
    constructor(map, layersRef) {
        this.map = map;
        this.layers = layersRef; // Referencja do obiektu layers z MapManager
    }

    async init() {
        //console.log('🎨 Loading layers from API...');

        try {
            const config = await this.loadLayersConfig();
            const stats = await this.loadLayerStats();
            //console.log('📦 Loaded config:', config);


            if (config.tiles && config.tiles.length > 0) {
                //console.log(`🗺️ Found ${config.tiles.length} tile layers`);

                config.tiles.forEach(tileConfig => {
                    this.initMVTLayer(tileConfig);
                });
            } else {
                //console.log('ℹ️ No tile layers configured');
            }

            // ✅ ODKOMENTUJ Overlay layers:
            if (config.overlays && config.overlays.length > 0) {
                //console.log(`📍 Found ${config.overlays.length} overlay layers`);

                config.overlays.forEach((rawConfig, index) => {
                    rawConfig.initial_count = stats[rawConfig.slug]?.count || 0;
                    this.initOverlayLayer(rawConfig, index);
                });
            } else {
                //console.log('ℹ️ No overlay layers configured');
            }

            // POI layer
            if (config.poi && config.poi.length > 0) {
                //console.log('📍 Initializing POI layer');
                this.initPOILayer();
            }

            //console.log('✅ Layers initialized:', Object.keys(this.layers));

        } catch (error) {
            console.error('❌ Failed to load layers:', error);
            console.error('Error stack:', error.stack);
        }
    }

    /**
     * ✅ NOWA FUNKCJA: Load layer statistics
     */
    async loadLayerStats() {
        try {
            const response = await fetch(window.APP_CONFIG.api('layers/stats.php'));
            const data = await response.json();

            if (data.success) {
                //console.log('📊 Layer stats:', data.data);
                return data.data;
            }
        } catch (error) {
            console.error('Failed to load layer stats:', error);
        }
        return {};
    }

    async loadLayersConfig() {
        const url = window.APP_CONFIG.api('layers/config.php?active_only=1');
        //console.log('🌐 Fetching layers config from:', url);

        const response = await fetch(url);
        const data = await response.json();

        //console.log('📦 API response:', data);

        if (!data.success) {
            throw new Error('Failed to load layers config: ' + (data.error || 'Unknown error'));
        }

        return data.data;
    }

    initMVTLayer(tileConfig) {
        //console.log('🗺️ Creating MVT layer:', tileConfig.slug);

        if (typeof MVTLayer !== 'undefined') {
            const mvtLayer = new MVTLayer(tileConfig);
            mvtLayer.init(this.map);
            this.layers[tileConfig.slug] = mvtLayer;
            //console.log('✅ MVT layer created:', tileConfig.slug);
        } else {
            console.warn('⚠️ MVTLayer class not found, skipping tile layer');
        }
    }

    initOverlayLayer(rawConfig, index) {
        // ✅ TRANSFORM: Wypłaszcz zagnieżdżony obiekt style
        const overlayConfig = this.transformOverlayConfig(rawConfig);

        //console.log(`🔍 Overlay ${index}: ${overlayConfig.slug}`);
        //console.log('  Transformed config:', {
        //    color: overlayConfig.color,
        //    stroke_width: overlayConfig.stroke_width,
        //    opacity: overlayConfig.opacity,
        //    has_outline: overlayConfig.has_outline
        //});

        if (typeof OverlayLayer === 'undefined') {
            console.error('❌ OverlayLayer class not found!');
            return;
        }

        const overlayLayer = new OverlayLayer(overlayConfig);
        this.layers[overlayConfig.slug] = overlayLayer;

        if (overlayConfig.visible_default) {
            this.map.addLayer(overlayLayer.layerGroup);
            overlayLayer.visible = true;
            //console.log(`✅ ${overlayConfig.name} added to map (visible)`);
        } else {
            //console.log(`ℹ️ ${overlayConfig.name} created (hidden)`);
        }
    }

    /*initOverlayLayer(rawConfig, index) {
     const overlayConfig = this.transformOverlayConfig(rawConfig);
     
     //console.log(`🔍 Overlay ${index}: ${overlayConfig.slug}`);
     
     // ✅ Use MVT instead of GeoJSON
     if (typeof MVTOverlayLayer !== 'undefined') {
     const mvtLayer = new MVTOverlayLayer(overlayConfig);
     mvtLayer.init(this.map);
     this.layers[overlayConfig.slug] = mvtLayer;
     //console.log('✅ MVT overlay layer created:', overlayConfig.slug);
     } else {
     console.error('❌ MVTOverlayLayer class not found!');
     }
     }*/

    /**
     * ✅ FIX: Transformacja zagnieżdżonego obiektu style
     * API zwraca: { style: { color, weight, opacity } }
     * OverlayLayer oczekuje: { color, stroke_width, opacity }
     */
    transformOverlayConfig(rawConfig) {
        const transformed = {...rawConfig};

        // Jeśli istnieje zagnieżdżony obiekt style
        if (rawConfig.style && typeof rawConfig.style === 'object') {
            const style = rawConfig.style;

            // Mapowanie: weight → stroke_width
            transformed.stroke_width = style.weight || style.stroke_width || 4;

            // Mapowanie: color
            transformed.color = style.color || '#00d4ff';

            // Mapowanie: opacity
            transformed.opacity = style.opacity ?? 0.8;

            // Outline (jeśli istnieje)
            if (style.outline_color) {
                transformed.has_outline = true;
                transformed.outline_color = style.outline_color;
            }

            //console.log('🔧 Style transformation:', {
            //    from: style,
            //    to: {
            //        color: transformed.color,
            //        stroke_width: transformed.stroke_width,
            //        opacity: transformed.opacity
            //    }
            //});
        }

        return transformed;
    }

    initPOILayer() {
        const poiLayer = new POILayer();
        this.layers['poi'] = poiLayer;
        this.map.addLayer(poiLayer.layerGroup);
        poiLayer.visible = true;
        //console.log('✅ POI layer added');
    }

    async loadVisibleLayers(bbox) {
        //console.log('🔄 Loading visible layers for bbox:', bbox);

        // Zbierz overlay layers
        const overlayLayers = Object.values(this.layers).filter(layer =>
            layer.visible &&
                    layer.config?.slug !== 'poi' &&
                    layer.config?.slug !== 'segments' && // Skip MVT
                    layer.config?.slug !== 'live_tracking' && // ✅ Skip Live Tracking
                    typeof OverlayLayer !== 'undefined' &&
                    layer instanceof OverlayLayer
        );

        if (overlayLayers.length === 0) {
            //console.log('ℹ️ No overlay layers to load');
            return;
        }

        // Batch request
        const slugs = overlayLayers.map(l => l.config.slug).join(',');
        const userLocalId = localStorage.getItem('user_local_id');

        let url = `${window.APP_CONFIG.apiUrl}/routes/list.php?bbox=${bbox.join(',')}&layers=${slugs}&limit=500`;
        if (userLocalId) {
            url += `&user_local_id=${userLocalId}`;
        }

        //console.log(`🌐 Batch loading: ${slugs}`);

        try {
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                // Rozdziel features po warstwach
                overlayLayers.forEach(layer => {
                    const layerFeatures = data.data.features.filter(f =>
                        f.properties.layers && f.properties.layers.includes(layer.config.slug)
                    );

                    //console.log(`✅ ${layer.config.name}: ${layerFeatures.length} routes`);

                    layer.render({
                        type: 'FeatureCollection',
                        features: layerFeatures
                    });
                });
            }
        } catch (error) {
            console.error('❌ Batch load failed:', error);
        }

        //console.log('✅ All visible layers loaded');
    }

    getLayer(name) {
        return this.layers[name];
    }

    showLayer(name) {
        const layer = this.layers[name];
        if (layer) {
            layer.show();
            const bounds = this.map.getBounds();
            const bbox = [
                bounds.getWest(),
                bounds.getSouth(),
                bounds.getEast(),
                bounds.getNorth()
            ];
            layer.load(bbox);
        }
    }

    hideLayer(name) {
        const layer = this.layers[name];
        if (layer)
            layer.hide();
    }
}

window.LayersManager = LayersManager;