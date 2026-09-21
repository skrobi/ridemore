class MVTOverlayLayer extends BaseLayer {
    constructor(layerConfig) {
        super({ type: 'mvt' });
        
        this.config = layerConfig;
        this.tileUrl = `${window.APP_CONFIG.baseUrl}/api/tiles/route_tiles.php?layer=${layerConfig.slug}&z={z}&x={x}&y={y}`;
        this.vectorGrid = null;
        this.visible = layerConfig.visible_default || false;
    }
    
    init(map) {
        console.log('🗺️ Initializing MVT route layer:', this.config.slug);
        
        this.vectorGrid = L.vectorGrid.protobuf(this.tileUrl, {
            interactive: true,
            vectorTileLayerStyles: {
                [this.config.slug]: (props) => ({
                    color: this.config.color || '#00d4ff',
                    weight: this.config.stroke_width || 3,
                    opacity: this.config.opacity || 0.8,
                    fill: false,
                    fillOpacity: 0,
                    lineCap: 'round',
                    lineJoin: 'round'
                })
            },
            getFeatureId: (f) => f.properties.route_id,
            rendererFactory: L.canvas.tile,
            maxNativeZoom: 14,
            minNativeZoom: 6,
            tileSize: 256,
            padding: 0.5
        });
        
        this.setupInteractions();
        
        if (this.visible) {
            this.vectorGrid.addTo(map);
            console.log('✅ MVT route layer shown');
        }
        
        return this.vectorGrid;
    }
    
    setupInteractions() {
        // Click → open route details
        this.vectorGrid.on('click', (e) => {
            const props = e.layer.properties;
            const appEl = document.querySelector('[x-data="app"]');
            if (appEl && window.Alpine) {
                Alpine.$data(appEl).openRouteDetails(props.route_id);
            }
        });
        
        // Hover → highlight
        this.vectorGrid.on('mouseover', (e) => {
            const id = e.layer.properties.route_id;
            const props = e.layer.properties;
            
            if (id) {
                this.vectorGrid.setFeatureStyle(id, {
                    color: '#ffffff',
                    weight: (this.config.stroke_width || 3) + 2,
                    opacity: 1
                });
                
                // Tooltip
                L.tooltip({
                    permanent: false,
                    direction: 'top',
                    className: 'route-tooltip'
                })
                .setContent(`<strong>${props.name}</strong><br>📏 ${props.distance_km} km • 📈 ${props.ascent_m} m`)
                .setLatLng(e.latlng)
                .addTo(window.map);
            }
        });
        
        this.vectorGrid.on('mouseout', (e) => {
            const id = e.layer.properties.route_id;
            if (id) {
                this.vectorGrid.resetFeatureStyle(id);
                window.map.closeTooltip();
            }
        });
    }
    
    show() {
        if (this.vectorGrid && window.map) {
            this.vectorGrid.addTo(window.map);
            this.visible = true;
        }
    }
    
    hide() {
        if (this.vectorGrid && window.map) {
            window.map.removeLayer(this.vectorGrid);
            this.visible = false;
        }
    }
    
    async load(bbox) {
        // No-op - tiles load automatically
    }
}

window.MVTOverlayLayer = MVTOverlayLayer;