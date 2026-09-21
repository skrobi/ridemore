/* ============================================================================
 LAYER CONTROLS UI
 UI kontrolki dla warstw i POI
 ============================================================================ */

class LayerControlsUI {
    constructor(map, layersRef) {
        this.map = map;
        this.layers = layersRef;
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

        const savedCollapsed = localStorage.getItem('layers_control_collapsed') === 'true';

        let html = `
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #eee;">
        <div style="font-weight: bold; font-size: 14px;">🗺️ Warstwy</div>
        <button class="toggle-control-btn" style="background: none; border: none; cursor: pointer; font-size: 16px; color: #6b7280; padding: 4px;">
          <i class="fas fa-chevron-${savedCollapsed ? 'down' : 'up'}"></i>
        </button>
      </div>
      <div class="control-content" style="display: ${savedCollapsed ? 'none' : 'block'};">
    `;

        // ✅ Posortowane warstwy
        const mvtLayers = [];
        const overlayLayers = [];
        const liveLayers = []; // ✅ DODANE

        Object.values(this.layers).forEach(layer => {
            if (!layer.config || !layer.config.slug || layer.config.slug === 'poi')
                return;

            if (typeof MVTLayer !== 'undefined' && layer instanceof MVTLayer) {
                mvtLayers.push(layer);
            } else if (typeof OverlayLayer !== 'undefined' && layer instanceof OverlayLayer) {
                overlayLayers.push(layer);
            } else if (typeof LiveTrackingLayer !== 'undefined' && layer instanceof LiveTrackingLayer) {
                liveLayers.push(layer); // ✅ DODANE
            }
        });

        console.log('🎨 Layer control - MVT:', mvtLayers.length, 'Overlays:', overlayLayers.length, 'Live:', liveLayers.length);

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

        // ✅ Live Tracking Layers
        if (liveLayers.length > 0) {
            html += '<div style="margin-bottom: 12px;"><div style="font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 6px;">NA ŻYWO</div>';
            liveLayers.forEach(layer => {
                html += this.createLiveLayerCheckbox(layer); // ✅ Osobna metoda
            });
            html += '</div>';
        }

        // Show message if no layers
        if (mvtLayers.length === 0 && overlayLayers.length === 0 && liveLayers.length === 0) {
            html += '<div style="color: #9ca3af; font-size: 12px; padding: 10px; text-align: center;">Brak skonfigurowanych warstw</div>';
        }

        html += '</div>';
        controlDiv.innerHTML = html;

        L.DomEvent.disableClickPropagation(controlDiv);
        L.DomEvent.disableScrollPropagation(controlDiv);

        // Toggle collapse
        const toggleBtn = controlDiv.querySelector('.toggle-control-btn');
        const content = controlDiv.querySelector('.control-content');
        let collapsed = savedCollapsed;

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                collapsed = !collapsed;
                content.style.display = collapsed ? 'none' : 'block';
                toggleBtn.querySelector('i').className = collapsed ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
                localStorage.setItem('layers_control_collapsed', collapsed);
            });
        }

        // ✅ Event listeners dla checkboxów
        controlDiv.querySelectorAll('input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', (e) => {
                const slug = e.target.dataset.layer;
                const layer = this.layers[slug];

                if (!layer) {
                    console.error('❌ Layer not found:', slug);
                    return;
                }

                console.log('🔄 Toggle layer:', slug, 'checked:', e.target.checked);

                if (e.target.checked) {
                    layer.show();

                    // ✅ Batch reload dla overlay layers
                    if (typeof OverlayLayer !== 'undefined' && layer instanceof OverlayLayer) {
                        const bounds = this.map.getBounds();
                        const bbox = [
                            bounds.getWest(),
                            bounds.getSouth(),
                            bounds.getEast(),
                            bounds.getNorth()
                        ];

                        // ✅ Użyj batch loading zamiast layer.load()
                        const mapManager = window.mapManager;
                        if (mapManager?.layersManager) {
                            mapManager.layersManager.loadVisibleLayers(bbox);
                        }
                    }

                    // ✅ Refresh dla live tracking
                    if (typeof LiveTrackingLayer !== 'undefined' && layer instanceof LiveTrackingLayer) {
                        layer.refresh(); // Natychmiastowy load
                    }

                } else {
                    layer.hide();
                }

                // Reload recommendations if needed
                const appEl = document.querySelector('[x-data="app"]');
                if (appEl && window.Alpine) {
                    const app = Alpine.$data(appEl);
                    if (app && app.activePanel === 'top-routes') {
                        const bounds = this.map.getBounds();
                        const bbox = [
                            bounds.getWest(),
                            bounds.getSouth(),
                            bounds.getEast(),
                            bounds.getNorth()
                        ];
                        window.RecommendationsModule.loadAllRecommendations(app, bbox);
                    }
                }
            });
        });

        const control = L.Control.extend({
            onAdd: () => controlDiv
        });

        new control({position: 'topright'}).addTo(this.map);
    }

    /**
     * Create checkbox for live tracking layer (with user count)
     */
    createLiveLayerCheckbox(layer) {
        const checked = layer.visible ? 'checked' : '';

        // ✅ Live tracking pokazuje liczbę użytkowników
        const count = layer.items && layer.items.size > 0
                ? `(${layer.items.size})`
                : '(0)';

        return `
      <label style="display: flex; align-items: center; margin: 6px 0; cursor: pointer; font-size: 13px; padding: 6px; border-radius: 4px; transition: background 0.2s;">
        <input type="checkbox" ${checked} data-layer="${layer.config.slug}" 
               style="margin-right: 8px; cursor: pointer; width: 16px; height: 16px;">
        <span style="color: ${layer.config.color || '#10b981'}; margin-right: 6px;">●</span>
        <span style="flex: 1;">${layer.config.name}</span>
        <span style="font-size: 11px; color: ${layer.config.color || '#10b981'}; margin-left: 4px; font-weight: 600;">${count}</span>
      </label>
    `;
    }

    createLayerCheckbox(layer) {
        const checked = layer.visible ? 'checked' : '';

        let count = '';
        if (typeof OverlayLayer !== 'undefined' && layer instanceof OverlayLayer) {
            const displayCount = layer.items.size > 0 ? layer.items.size : (layer.config.initial_count || 0);
            count = `(${displayCount})`;
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

    async addPOIControl(poiManager) {
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
          `;

        // ✅ Pobierz zapisany stan
        const savedCollapsed = localStorage.getItem('poi_control_collapsed') === 'true';

        let html = `
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #eee;">
        <div style="font-weight: bold; font-size: 14px;">Punkty POI</div>
        <button class="toggle-poi-btn" style="background: none; border: none; cursor: pointer; font-size: 16px; color: #6b7280; padding: 4px;">
          <i class="fas fa-chevron-${savedCollapsed ? 'down' : 'up'}"></i>
        </button>
      </div>
      <div class="poi-content" style="display: ${savedCollapsed ? 'none' : 'block'};">
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
                const iconSvg = subtype.icon
                        ? `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/${subtype.icon}.svg" width="16" height="16" style="display: inline-block; vertical-align: middle;" alt="">`
                        : '❓';

                html += `
                    <label style="display: flex; align-items: center; margin: 6px 0; cursor: pointer; font-size: 13px; padding: 4px 6px; border-radius: 4px; transition: background 0.15s;">
                        <input type="checkbox" ${checked} data-subtype="${subtype.slug}" class="poi-subtype-toggle"
                               style="margin-right: 8px; cursor: pointer; width: 14px; height: 14px;">
                        <span style="margin-right: 6px;">${iconSvg}</span>
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
        let collapsed = savedCollapsed; // ✅ Ustaw początkowy stan

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

            // ✅ Zapisz stan do localStorage
            localStorage.setItem('poi_control_collapsed', collapsed);
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
            poiManager.reloadPOI();
        });

        const subtypeToggles = controlDiv.querySelectorAll('.poi-subtype-toggle');

        subtypeToggles.forEach((input) => {
            input.addEventListener('change', () => {
                poiManager.reloadPOI();
            });
        });

        const control = L.Control.extend({
            onAdd: () => controlDiv
        });

        new control({position: 'topright'}).addTo(this.map);

        // Initial load
        setTimeout(() => {
            const initialSubtypes = Array.from(
                    document.querySelectorAll('.poi-subtype-toggle:checked')
                    ).map(cb => cb.dataset.subtype);

            poiManager.setLastActiveSubtypes(new Set(initialSubtypes));

            if (initialSubtypes.length > 0) {
                const bounds = this.map.getBounds();
                const bbox = [
                    bounds.getWest(),
                    bounds.getSouth(),
                    bounds.getEast(),
                    bounds.getNorth()
                ];

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
}

window.LayerControlsUI = LayerControlsUI;