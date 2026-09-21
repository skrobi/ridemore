/* ============================================================================
 MAP MANAGER - Main orchestrator
 ============================================================================ */

class MapManager {
    static instance = null;

    constructor(appInstance) {
        if (MapManager.instance) {
            return MapManager.instance;
        }

        this.app = appInstance;
        this.map = null;
        this.layers = {};
        this.initialized = false;
        this.lastActiveSubtypes = new Set();
        this.moveEndHandler = null;
        this.clickHandler = null;
        this._poiReloadTimeout = null;

        MapManager.instance = this;
    }

    static getInstance(appInstance) {
        if (!MapManager.instance) {
            MapManager.instance = new MapManager(appInstance);
        }
        return MapManager.instance;
    }

    async init() {
        // ✅ Guard - zapobiega ponownej inicjalizacji
        if (this.initialized) {
            console.log('✅ Map already initialized, skipping');
            return this.map;
        }

        console.log('🗺️ Initializing MapManager...');
        this.initialized = true;

        // ========================================================================
        // 1. CREATE MAP
        // ========================================================================
        this.map = L.map('map', {
            center: [52.0, 19.0],
            zoom: 7,
            minZoom: 6,
            maxZoom: 18,
            zoomControl: false
        });

        // ========================================================================
        // 2. INITIALIZE ROUTE STYLES PANES
        // ========================================================================
        if (window.RouteStyles && typeof window.RouteStyles.initializePanes === 'function') {
            window.RouteStyles.initializePanes(this.map);
            console.log('✅ RouteStyles panes initialized');
        } else {
            console.warn('⚠️ RouteStyles not loaded or initializePanes missing');
        }

        // ========================================================================
        // 3. ADD BASE TILE LAYER
        // ========================================================================
        L.control.zoom({position: 'topright'}).addTo(this.map);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: 'OpenStreetMap - RideMore.Bike',
            maxZoom: 19,
            tileSize: 256
        }).addTo(this.map);

        // ========================================================================
        // 4. INITIALIZE LAYERS VIA LAYERSMANAGER
        // ========================================================================
        console.log('📡 Initializing layers...');

        this.layersManager = new LayersManager(this.map, this.layers);
        await this.layersManager.init();

        // Live Tracking - dodaj ręcznie
        try {
            const response = await fetch(window.APP_CONFIG.api('layers/config.php?active_only=1'));
            const data = await response.json();

            if (data.success && data.data.live && data.data.live.length > 0) {
                if (typeof LiveTrackingLayer !== 'undefined') {
                    const liveConfig = data.data.live[0]; // ✅ brakująca linijka!
                    const liveLayer = new LiveTrackingLayer(liveConfig);
                    this.layers[liveConfig.slug] = liveLayer;

                    if (liveConfig.visible_by_default) {
                        liveLayer.show(); // ✅ show() już dodaje layerGroup do mapy i startuje refresh
                    } else {
                        // Tylko dodaj layerGroup do mapy bez pokazywania
                        this.map.addLayer(liveLayer.layerGroup);
                    }

                    console.log('✅ Live Tracking layer created');
                }
            }
        } catch (error) {
            console.error('❌ Failed to load live tracking:', error);
        }

        console.log('✅ All layers initialized:', Object.keys(this.layers));

        // ========================================================================
        // 5. ADD UI CONTROLS
        // ========================================================================
        this.layerControlsUI = new LayerControlsUI(this.map, this.layers);
        this.layerControlsUI.addLayerControl();

        this.poiManager = new POIManager(this.map, this.layers);
        await this.layerControlsUI.addPOIControl(this.poiManager);

        // ========================================================================
        // 6. SETUP EVENT LISTENERS
        // ========================================================================
        this.setupEvents();

        // ========================================================================
        // 7. INITIALIZE CHALLENGE MARKERS
        // ========================================================================
        if (typeof ChallengeMarkers !== 'undefined') {
            this.challengeMarkers = new ChallengeMarkers(this.map);
            this.challengeMarkers.init();
            console.log('✅ Challenge markers initialized');
        } else {
            console.warn('⚠️ ChallengeMarkers not loaded');
            this.challengeMarkers = null;
        }

        // ========================================================================
        // 8. EXPOSE GLOBALS
        // ========================================================================
        window.map = this.map;
        window.mapManager = this;

        console.log('✅ Map initialized successfully');

        // ========================================================================
        // 9. INITIAL DATA LOAD
        // ========================================================================
        setTimeout(() => {
            console.log('🎬 Initial data load...');

            const bbox = this.getBbox();

            // Batch load overlay layers
            if (this.layersManager) {
                this.layersManager.loadVisibleLayers(bbox);
            }

            // loadBasedOnZoom dla challenge markers, POI
            this.loadBasedOnZoom();
        }, 1500);

        return this.map;
    }

    setupEvents() {
        // ✅ Zapobiegaj wielokrotnej rejestracji
        if (this._eventsInitialized) {
            //console.log('⚠️ Events already initialized, skipping');
            return;
        }

        if (typeof ChallengeMarkers !== 'undefined') {
            this.challengeMarkers = new ChallengeMarkers(this.map);
            this.challengeMarkers.init();
        } else {
            console.warn('⚠️ ChallengeMarkers not loaded yet, skipping');
            this.challengeMarkers = null; // ← WAŻNE
        }

        // ✅ NOWE: Initialize challenge markers
        this.challengeMarkers = new ChallengeMarkers(this.map);
        this.challengeMarkers.init();

        // ✅ ZMIENIONE: Debounce z progressive loading
        const debouncedReload = this.debounce(() => {
            this.loadBasedOnZoom(); // ← Zamiast bezpośredniego loadVisibleLayers
        }, 500);

        this.moveEndHandler = () => {
            const bbox = this.getBbox();

            if (this.app && typeof this.app.onMapMove === 'function') {
                this.app.onMapMove(bbox);
            }

            debouncedReload();
        };

        // ✅ ZMIENIONE: Dodaj zoomend (bo progressive loading reaguje na zoom)
        this.map.on('zoomend moveend', this.moveEndHandler);

        this.clickHandler = (e) => {
            const clickedElement = e.originalEvent.target;

            //console.log('🗺️ MAP CLICK:', clickedElement.tagName, clickedElement.className);

            const clickedOnMarker = clickedElement.closest('.leaflet-marker-icon');
            const clickedOnPopup = clickedElement.closest('.leaflet-popup');
            const clickedOnControl = clickedElement.closest('.leaflet-control');

            // ✅ DODAJ: Sprawdź czy kliknięto na route
            const clickedOnRoute = clickedElement.classList &&
                    (clickedElement.classList.contains('route-main') ||
                            clickedElement.classList.contains('leaflet-interactive'));

            if (!clickedOnMarker && !clickedOnPopup && !clickedOnControl && !clickedOnRoute) {
                //console.log('❌ Click outside interactive elements - closing panels');

                if (this.app && typeof this.app.closeAll === 'function') {
                    this.app.closeAll();
                }
            } else {
                //console.log('✅ Click on interactive element - ignoring');
            }
        };

        this.map.on('click', this.clickHandler);

        // ✅ Oznacz jako zainicjalizowane
        this._eventsInitialized = true;

        //console.log('✅ Event listeners attached (ONCE) with progressive loading');
    }

    deselectRoute() {
        this.deselectAllRoutes();
    }

// ✅ NOWA METODA: Progressive loading based on zoom
    async loadBasedOnZoom() {
        if (this._skipNextReload) {
            //console.log('⏭️ Skipping loadBasedOnZoom (flag set)');
            return;
        }

        const zoom = this.map.getZoom();
        const bbox = this.getBbox();

        //console.log(`🔍 Zoom: ${zoom}, loading appropriate data...`);

        if (zoom < 6) {  // ✅ ZMIENIONE z 8 na 6 (prawie nigdy się nie wydarzy)
            //console.log('📍 Zoom < 6: Showing only challenge markers');

            if (this.challengeMarkers) {
                this.challengeMarkers.show();
            }

            //console.log('🧹 Hiding all overlays...');
            this.hideAllOverlays();

            if (this.app && this.app.activePanel === 'top-routes') {
                this.app.loadingTopPanel = true;
                this.app.topPanelData = {hero: null, top_quality: [], feed: []};
            }

        } else if (zoom < 11) {
            //console.log('🎯 Zoom 6-11: Loading recommendations + challenge routes');
            this.reloadPOIOnMove();
            if (this.challengeMarkers) {
                this.challengeMarkers.hide();
            }

            if (this.app && this.app.activePanel === 'top-routes') {
                await window.RecommendationsModule.loadAllRecommendations(this.app, bbox);
            }

            const activeLayers = this.getActiveOverlayLayers();
            //console.log(`📦 Loading ${activeLayers.length} active layers...`);

            for (const layer of activeLayers) {
                //console.log(`  → Loading layer: ${layer.config.name}`);
                await layer.load(bbox);
            }

        } else {
            //console.log('🗺️ Zoom > 11: Loading all visible layers');

            if (this.challengeMarkers) {
                this.challengeMarkers.hide();
            }

            //console.log('📦 Loading via LayersManager...');
            await this.layersManager.loadVisibleLayers(bbox);
            this.reloadPOIOnMove();

            if (this.app && this.app.activePanel === 'top-routes') {
                await window.RecommendationsModule.loadAllRecommendations(this.app, bbox);
            }
        }

        //console.log('✅ loadBasedOnZoom() finished');
    }

// ✅ HELPER: Get active overlay layers
    getActiveOverlayLayers() {
        const layers = Object.values(this.layers).filter(layer =>
            layer.visible &&
                    layer instanceof OverlayLayer &&
                    layer.config?.slug !== 'poi'
        );

        // ✅ SORTUJ wg priorytetu: challenge routes (reference) OSTATNIE = na wierzchu
        return layers.sort((a, b) => {
            const priorityA = a.config?.slug === 'official_routes' ? 1 : 0;
            const priorityB = b.config?.slug === 'official_routes' ? 1 : 0;
            return priorityA - priorityB; // User routes pierwsze, official ostatnie
        });
    }

    hideAllOverlays() {
        this.getActiveOverlayLayers().forEach(layer => {
            if (layer.layerGroup) {
                layer.layerGroup.clearLayers();
            }
        });
    }

    async initLayers() {
        //console.log('🎨 Loading layers from API...');

        try {
            // 1. Załaduj konfigurację z nowego API
            const config = await this.loadLayersConfig();

            //console.log('📦 Loaded config:', config);

            // 2. Inicjalizuj warstwy MVT (tiles)
            if (config.tiles && config.tiles.length > 0) {
                //console.log(`🗺️ Found ${config.tiles.length} tile layers`);

                config.tiles.forEach(tileConfig => {
                    //console.log('🗺️ Creating MVT layer:', tileConfig.slug);

                    if (typeof MVTLayer !== 'undefined') {
                        const mvtLayer = new MVTLayer(tileConfig);
                        mvtLayer.init(this.map);
                        this.layers[tileConfig.slug] = mvtLayer;
                        //console.log('✅ MVT layer created:', tileConfig.slug);
                    } else {
                        console.warn('⚠️ MVTLayer class not found, skipping tile layer');
                    }
                });
            } else {
                //console.log('ℹ️ No tile layers configured');
            }

            // 3. Inicjalizuj warstwy overlay (routes)
            if (config.overlays && config.overlays.length > 0) {
                //console.log(`📍 Found ${config.overlays.length} overlay layers`);

                config.overlays.forEach(overlayConfig => {
                    //console.log('🔍 Creating overlay layer:', overlayConfig.slug);
                    //console.log('  - name:', overlayConfig.name);
                    //console.log('  - visible_default:', overlayConfig.visible_default);  // ✅ DODAJ TO
                    //console.log('  - color:', overlayConfig.color);
                    //console.log('  - stroke_width:', overlayConfig.stroke_width);
                    //console.log('  - opacity:', overlayConfig.opacity);  // ✅ SPRAWDŹ czy nie 0

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
                });
            } else {
                //console.log('ℹ️ No overlay layers configured');
            }

            // 4. Inicjalizuj POI layer
            if (config.poi && config.poi.length > 0) {
                //console.log('📍 Initializing POI layer');

                const poiLayer = new POILayer();
                this.layers['poi'] = poiLayer;
                this.map.addLayer(poiLayer.layerGroup);
                poiLayer.visible = true;
                //console.log('✅ POI layer added');
            }

            // 5. UI Controls
            this.addLayerControl();
            await this.addPOIControl();

            //console.log('✅ Layers initialized:', Object.keys(this.layers));

        } catch (error) {
            console.error('❌ Failed to load layers:', error);
            console.error('Error stack:', error.stack);
        }
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

    addLayerControl() {
        const controlDiv = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
        controlDiv.style.cssText = `
      background: white;
      padding: 10px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      max-width: 280px;
      max-height: 450px;
      overflow-y: auto;
      z-index: 1000;
    `;

        let html = `
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #eee;">
        <div style="font-weight: bold; font-size: 14px;">🗺️ Warstwy</div>
        <button class="toggle-control-btn" style="background: none; border: none; cursor: pointer; font-size: 16px; color: #6b7280; padding: 4px;">
          <i class="fas fa-chevron-up"></i>
        </button>
      </div>
      <div class="control-content">
    `;

        // Group by type
        const mvtLayers = [];
        const overlayLayers = [];

        Object.values(this.layers).forEach(layer => {
            if (!layer.config || !layer.config.slug || layer.config.slug === 'poi')
                return;

            if (typeof MVTLayer !== 'undefined' && layer instanceof MVTLayer) {
                mvtLayers.push(layer);
            } else if (typeof OverlayLayer !== 'undefined' && layer instanceof OverlayLayer) {
                overlayLayers.push(layer);
            }
        });

        //console.log('🎨 Layer control - MVT:', mvtLayers.length, 'Overlays:', overlayLayers.length);

        // MVT Layers
        if (mvtLayers.length > 0) {
            html += '<div style="margin-bottom: 12px;"><div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 6px;">SEGMENTY</div>';
            mvtLayers.forEach(layer => {
                html += this.createLayerCheckbox(layer);
            });
            html += '</div>';
        }

        // Overlay Layers
        if (overlayLayers.length > 0) {
            html += '<div style="margin-bottom: 12px;"><div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 6px;">TRASY</div>';
            overlayLayers.forEach(layer => {
                html += this.createLayerCheckbox(layer);
            });
            html += '</div>';
        }

        // Show message if no layers
        if (mvtLayers.length === 0 && overlayLayers.length === 0) {
            html += '<div style="color: #9ca3af; font-size: 12px; padding: 10px; text-align: center;">Brak skonfigurowanych warstw</div>';
        }

        html += '</div>';
        controlDiv.innerHTML = html;

        // Event listeners
        L.DomEvent.disableClickPropagation(controlDiv);
        L.DomEvent.disableScrollPropagation(controlDiv);

        // Toggle collapse
        const toggleBtn = controlDiv.querySelector('.toggle-control-btn');
        const content = controlDiv.querySelector('.control-content');
        let collapsed = false;

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                collapsed = !collapsed;
                content.style.display = collapsed ? 'none' : 'block';
                toggleBtn.querySelector('i').className = collapsed ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
            });
        }

        // Checkboxes
        controlDiv.querySelectorAll('input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', (e) => {
                const slug = e.target.dataset.layer;
                const layer = this.layers[slug];

                if (!layer) {
                    console.error('❌ Layer not found:', slug);
                    return;
                }

                //console.log('🔄 Toggle layer:', slug, 'checked:', e.target.checked);

                if (e.target.checked) {
                    layer.show();

                    // Load overlay layers
                    if (typeof OverlayLayer !== 'undefined' && layer instanceof OverlayLayer) {
                        const bbox = this.getBbox();
                        layer.load(bbox);
                    }
                } else {
                    layer.hide();
                }
            });
        });

        const control = L.Control.extend({
            onAdd: () => controlDiv
        });

        new control({position: 'topright'}).addTo(this.map);
    }

    createLayerCheckbox(layer) {
        const checked = layer.visible ? 'checked' : '';

        let count = '';
        if (typeof OverlayLayer !== 'undefined' && layer instanceof OverlayLayer) {
            count = `(${layer.items.size})`;
        }

        return `
      <label style="display: flex; align-items: center; margin: 6px 0; cursor: pointer; font-size: 13px; padding: 6px; border-radius: 4px; transition: background 0.2s;">
        <input type="checkbox" ${checked} data-layer="${layer.config.slug}" 
               style="margin-right: 8px; cursor: pointer; width: 16px; height: 16px;">
        <span style="flex: 1;">${layer.config.name}</span>
        <span style="font-size: 11px; color: #999; margin-left: 4px;">${count}</span>
      </label>
    `;
    }

    async addPOIControl() {
        const response = await fetch(window.APP_CONFIG.api('poi/subtypes_grouped.php'));
        const data = await response.json();

        if (!data.success) {
            console.error('Failed to load POI subtypes');
            return;
        }

        const controlDiv = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
        controlDiv.style.cssText = `
      background: white;
      padding: 10px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      max-width: 280px;
      max-height: 500px;
      overflow-y: auto;
      z-index: 1000;
      margin-top: 10px;
      transition: all 0.3s ease;
    `;

        let html = `
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #eee;">
        <div style="font-weight: bold; font-size: 14px;">📍 Punkty POI</div>
        <button class="toggle-poi-btn" style="background: none; border: none; cursor: pointer; font-size: 16px; color: #6b7280; padding: 4px;">
          <i class="fas fa-chevron-up"></i>
        </button>
      </div>
      <div class="poi-content">
    `;

        html += `
      <label style="display: flex; align-items: center; margin-bottom: 12px; padding: 8px; background: #f0f9ff; border-radius: 4px; cursor: pointer; font-weight: 600;">
        <input type="checkbox" id="poi-master-toggle" checked style="margin-right: 8px; width: 16px; height: 16px;">
        Wszystkie POI
      </label>
    `;

        const grouped = {};
        data.data.forEach(subtype => {
            if (!grouped[subtype.category_name]) {
                grouped[subtype.category_name] = [];
            }
            grouped[subtype.category_name].push(subtype);
        });

        Object.entries(grouped).forEach(([categoryName, subtypes]) => {
            const categoryCount = subtypes.reduce((sum, st) => sum + parseInt(st.poi_count), 0);

            html += `
        <div style="margin-bottom: 16px;">
          <div style="font-weight: 600; font-size: 12px; color: #6b7280; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
            ${categoryName} (${categoryCount})
          </div>
          <div style="margin-left: 8px;">
      `;

            subtypes.forEach(subtype => {
                const checked = subtype.visible_default ? 'checked' : '';
                html += `
          <label style="display: flex; align-items: center; margin: 6px 0; cursor: pointer; font-size: 13px; padding: 4px 6px; border-radius: 4px; transition: background 0.15s;">
            <input type="checkbox" ${checked} data-subtype="${subtype.slug}" class="poi-subtype-toggle"
                   style="margin-right: 8px; cursor: pointer; width: 14px; height: 14px;">
            <span style="font-size: 16px; margin-right: 6px;">${subtype.icon}</span>
            <span style="flex: 1;">${subtype.subtype_name}</span>
            <span style="font-size: 10px; color: #999; margin-left: 4px;">(${subtype.poi_count})</span>
          </label>
        `;
            });

            html += `
          </div>
        </div>
      `;
        });

        html += '</div>';
        controlDiv.innerHTML = html;

        L.DomEvent.disableClickPropagation(controlDiv);
        L.DomEvent.disableScrollPropagation(controlDiv);

        const toggleBtn = controlDiv.querySelector('.toggle-poi-btn');
        const content = controlDiv.querySelector('.poi-content');
        let collapsed = false;

        toggleBtn.addEventListener('click', () => {
            collapsed = !collapsed;
            if (collapsed) {
                content.style.display = 'none';
                toggleBtn.querySelector('i').className = 'fas fa-chevron-down';
                controlDiv.style.maxHeight = 'auto';
            } else {
                content.style.display = 'block';
                toggleBtn.querySelector('i').className = 'fas fa-chevron-up';
                controlDiv.style.maxHeight = '500px';
            }
        });

        controlDiv.querySelectorAll('label').forEach(label => {
            if (!label.querySelector('#poi-master-toggle')) {
                label.addEventListener('mouseenter', () => label.style.background = '#f0f9ff');
                label.addEventListener('mouseleave', () => label.style.background = 'transparent');
            }
        });

        const masterToggle = controlDiv.querySelector('#poi-master-toggle');
        masterToggle.addEventListener('change', (e) => {
            const checkboxes = controlDiv.querySelectorAll('.poi-subtype-toggle');
            checkboxes.forEach(cb => {
                cb.checked = e.target.checked;
            });
            this.reloadPOI();
        });

        const subtypeToggles = controlDiv.querySelectorAll('.poi-subtype-toggle');

        subtypeToggles.forEach((input) => {
            input.addEventListener('change', () => {
                //console.log('✅ Checkbox changed:', input.dataset.subtype, 'checked:', input.checked);
                this.reloadPOI();
            });
        });

        const control = L.Control.extend({
            onAdd: () => controlDiv
        });

        new control({position: 'topright'}).addTo(this.map);

        setTimeout(() => {
            const initialSubtypes = Array.from(
                    document.querySelectorAll('.poi-subtype-toggle:checked')
                    ).map(cb => cb.dataset.subtype);

            this.lastActiveSubtypes = new Set(initialSubtypes);
            //console.log('🎬 Initial POI subtypes:', initialSubtypes);

            if (initialSubtypes.length > 0) {
                const bbox = this.getBbox();
                const poiLayer = this.layers['poi'];

                if (poiLayer) {
                    poiLayer.fetch(bbox, {subtypes: initialSubtypes})
                            .then(data => {
                                if (data) {
                                    poiLayer.render(data);
                                }
                            });
                }
            }
        }, 500);
    }

    reloadPOI() {
        clearTimeout(this._poiReloadTimeout);

        this._poiReloadTimeout = setTimeout(() => {
            // ✅ Cancel poprzednie requesty
            if (this._currentPOIRequest) {
                //console.log('⚠️ Cancelling previous POI request');
                // AbortController would be ideal, but for now just flag it
                this._poiRequestCancelled = true;
            }

            const poiLayer = this.layers['poi'];
            if (!poiLayer) {
                console.error('❌ POI layer not found!');
                return;
            }

            const bbox = this.getBbox();
            const checkboxes = document.querySelectorAll('.poi-subtype-toggle:checked');
            const activeSubtypes = Array.from(checkboxes).map(cb => cb.dataset.subtype);
            const activeSet = new Set(activeSubtypes);

            //console.log('🔄 Reloading POI');
            //console.log('📋 Active subtypes:', activeSubtypes);
            //console.log('📋 Previous subtypes:', Array.from(this.lastActiveSubtypes));

            const added = activeSubtypes.filter(s => !this.lastActiveSubtypes.has(s));
            const removed = Array.from(this.lastActiveSubtypes).filter(s => !activeSet.has(s));

            //console.log('➕ Added:', added);
            //console.log('➖ Removed:', removed);

            if (activeSubtypes.length === 0) {
                //console.log('⚠️ No active subtypes - clearing POI layer');
                poiLayer.clear();
                this.lastActiveSubtypes = new Set();
                return;
            }

            if (removed.length > 0) {
                this.removePOIBySubtypes(poiLayer, removed);
            }

            //console.log('🌐 Fetching POI for ALL active subtypes:', activeSubtypes);

            // ✅ Mark request as active
            this._poiRequestCancelled = false;
            this._currentPOIRequest = poiLayer.fetch(bbox, {subtypes: activeSubtypes})
                    .then(data => {
                        // ✅ Check if request was cancelled
                        if (this._poiRequestCancelled) {
                            //console.log('⚠️ Request cancelled, ignoring results');
                            return;
                        }

                        if (data) {
                            //console.log('✅ Got POI data, rendering...');
                            poiLayer.render(data);
                        } else {
                            console.warn('⚠️ No data returned from fetch');
                        }
                    })
                    .catch(error => {
                        if (!this._poiRequestCancelled) {
                            console.error('❌ Fetch error:', error);
                        }
                    })
                    .finally(() => {
                        this._currentPOIRequest = null;
                    });

            this.lastActiveSubtypes = activeSet;
        }, 300);
    }

    removePOIBySubtypes(poiLayer, subtypeSlugs) {
        const slugsSet = new Set(subtypeSlugs);

        for (const [poiId, marker] of poiLayer.items.entries()) {
            if (marker.poiSubtype && slugsSet.has(marker.poiSubtype)) {
                //console.log(`  🗑️ Removing POI ${poiId} (${marker.poiSubtype})`);
                poiLayer.clusterGroup.removeLayer(marker);
                poiLayer.items.delete(poiId);
            }
        }

        //console.log(`✅ After removal: ${poiLayer.items.size} POIs on map`);
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    reloadPOIOnMove() {
        const poiLayer = this.layers['poi'];
        if (!poiLayer)
            return;

        const bbox = this.getBbox();

        const activeSubtypes = Array.from(
                document.querySelectorAll('.poi-subtype-toggle:checked')
                ).map(cb => cb.dataset.subtype);

        if (activeSubtypes.length === 0) {
            poiLayer.clear();
            return;
        }

        //console.log('🗺️ Map moved - reloading POI with subtypes:', activeSubtypes);

        poiLayer.fetch(bbox, {subtypes: activeSubtypes})
                .then(data => {
                    if (data) {
                        poiLayer.render(data);
                    }
                });
    }

    async loadVisibleLayers(bbox) {
        //console.log('🔄 Loading visible layers for bbox:', bbox);

        const promises = [];

        for (const layer of Object.values(this.layers)) {
            if (layer.config && layer.config.slug === 'poi') {
                continue;
            }

            if (layer.visible) {
                //console.log(`📡 Loading layer: ${layer.config?.name || 'unknown'}`);
                promises.push(layer.load(bbox));  // ✅ Równoległe ładowanie
            }
        }

        await Promise.all(promises);  // ✅ Poczekaj na wszystkie
        //console.log('✅ All visible layers loaded');
    }

    getLayer(name) {
        return this.layers[name];
    }

    showLayer(name) {
        const layer = this.layers[name];
        if (layer) {
            layer.show();
            const bbox = this.getBbox();
            layer.load(bbox);
        }
    }

    hideLayer(name) {
        const layer = this.layers[name];
        if (layer)
            layer.hide();
    }

    /**
     * Show route on map - uses existing overlay layers (NO temporary layer)
     */
    async showRoute(routeId) {
        //console.log('🗺️ MapManager: showing route', routeId);

        if (!this.map) {
            console.error('❌ Map not initialized');
            return;
        }

        routeId = parseInt(routeId);
        //console.log('  → Parsed routeId:', routeId); // ✅ DODAJ

        // Deselect all first
        this.deselectAllRoutes();

        // Try to find and select route in existing overlay layers
        const overlayLayers = this.getOverlayLayers();
        //console.log(`  → Checking ${overlayLayers.length} overlay layers`); // ✅ DODAJ


        for (const layer of overlayLayers) {
            const layerKey = `${layer.config.slug}_${routeId}`;
            const layerGroup = layer.getItem(layerKey);

            if (layerGroup) {
                //console.log('✅ Route found in layer:', layer.config.slug);

                // Ensure layer is visible
                if (!layer.visible) {
                    layer.show();
                }

                // Select route (applies selected style + fitBounds)
                await layer.selectRoute(routeId);

                return true;
            }
        }

        // Route not on map - need to load it
        //console.log('📡 Route not on map, loading...');

        // Try to determine which layer should have this route
        const targetLayer = await this.findLayerForRoute(routeId);

        if (targetLayer) {
            await targetLayer.loadSingleRoute(routeId);
            await targetLayer.selectRoute(routeId);
            return true;
        }

        console.error('❌ Could not determine layer for route:', routeId);
        return false;
    }

    /**
     * Find appropriate layer for a route
     */
    async findLayerForRoute(routeId) {
        try {
            const url = window.APP_CONFIG.api(`routes/details.php?id=${routeId}`);
            const response = await fetch(url);
            const data = await response.json();

            if (!data.success) {
                return null;
            }

            const route = data.data;
            const overlayLayers = this.getOverlayLayers();

            // Find layer by route's assigned layers
            if (route.layers && route.layers.length > 0) {
                for (const layer of overlayLayers) {
                    if (route.layers.includes(layer.config.slug)) {
                        //console.log('✅ Found target layer:', layer.config.slug);
                        return layer;
                    }
                }
            }

            // Fallback: use first overlay layer
            return overlayLayers[0] || null;

        } catch (error) {
            console.error('❌ Failed to find layer for route:', error);
            return null;
        }
    }

    /**
     * Get all overlay layers (routes)
     * @returns {Array<OverlayLayer>}
     */
    getOverlayLayers() {
        const overlayLayers = [];

        for (const [slug, layer] of Object.entries(this.layers)) {
            if (typeof OverlayLayer !== 'undefined' && layer instanceof OverlayLayer) {
                overlayLayers.push(layer);
            }
        }

        //console.log(`📦 Found ${overlayLayers.length} overlay layers`);
        return overlayLayers;
    }

    deselectAllRoutes() {  // ✅ ZMIEŃ nazwę z deselectRoute()
        //console.log('🔄 Global deselect - all layers');
        Object.values(this.layers).forEach(layer => {
            if (layer.deselectRoute && typeof layer.deselectRoute === 'function') {
                layer.deselectRoute();
            }
        });
    }

    // ✅ DODAJ ALIAS dla kompatybilności wstecznej
    deselectRoute() {
        this.deselectAllRoutes();
    }

    getBbox() {
        const bounds = this.map.getBounds();
        return [
            bounds.getWest(),
            bounds.getSouth(),
            bounds.getEast(),
            bounds.getNorth()
        ];
    }

    flyTo(lat, lng, zoom = 13) {
        this.map.flyTo([lat, lng], zoom, {
            duration: 1.5,
            easeLinearity: 0.25
        });
    }

    fitBounds(bounds, options = {}) {
        this.map.fitBounds(bounds, {padding: [50, 50], ...options});
    }

    getCenter() {
        const center = this.map.getCenter();
        return [center.lat, center.lng];
    }

    getZoom() {
        return this.map.getZoom();
    }

    setView(latlng, zoom) {
        this.map.setView(latlng, zoom);
    }
}

window.showRouteOnMap = function (routeId) {
    const manager = MapManager.getInstance();
    if (manager)
        manager.showRoute(routeId);
};

window.showRouteDetails = function (routeId) {
    const app = Alpine.$data(document.querySelector('[x-data="app"]'));
    if (app)
        app.openRouteDetails(routeId);
};

window.MapManager = MapManager;