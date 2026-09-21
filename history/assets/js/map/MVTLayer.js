/* ============================================================================
   MVT LAYER - Vector tiles z zoom strategy
   FIX: Tile gaps w Chrome - dodano tileSize + padding
   ============================================================================ */

class MVTLayer extends BaseLayer {
  constructor(layerConfig) {
    super({ type: 'mvt' });
    
    this.config = layerConfig;
    this.tileUrl = layerConfig.tile_url;
    this.tileConfig = layerConfig.tile_config || { internal_layers: ['segments'], zoom_filter: { min: 6, max: 18 } };
    this.vectorGrid = null;
    this.currentZoom = null;
    this.visible = layerConfig.visible_default || false;
  }
  
  init(map) {
    //console.log('🗺️ Initializing MVTLayer:', this.config.slug);
    
    let tileUrl = this.tileUrl;
    
    if (!tileUrl) {
        console.warn(`⚠️ ${this.config.slug}: brak tile_url, pomijam inicjalizację`);
        return null;
    }
    
    if (tileUrl && !tileUrl.startsWith('http')) {
      if (!tileUrl.startsWith('/')) {
        tileUrl = '/' + tileUrl;
      }
      tileUrl = window.APP_CONFIG.baseUrl + tileUrl;
    }
    
    this.tileUrl = tileUrl;
    
    //console.log('📍 Tile URL (fixed):', this.tileUrl);
    
    const styles = {};
    this.tileConfig.internal_layers.forEach(layerName => {
      styles[layerName] = (props) => this.getFeatureStyle(props, layerName);
    });
    
    // ✅ FIX: Dodano padding do tile'ów żeby uniknąć luk
    this.vectorGrid = L.vectorGrid.protobuf(this.tileUrl, {
      interactive: true,
      vectorTileLayerStyles: styles,
      getFeatureId: (f) => f.properties.segment_id || f.properties.q2vt_fid || f.properties.edge_id,
      rendererFactory: L.canvas.tile,
      maxNativeZoom: this.tileConfig.zoom_filter.max || 14,
      minNativeZoom: this.tileConfig.zoom_filter.min || 6,
      // ✅ KLUCZOWE FIXY dla Chrome:
      tileSize: 256,           // Standardowy rozmiar
      padding: 0.5,            // Padding 0.5 tile eliminuje luki
      updateWhenZooming: false, // Wyłącz update podczas zoom (redukuje artefakty)
      updateWhenIdle: true     // Update tylko gdy mapa się zatrzyma
    });
    
    this.setupZoomBehavior(map);
    this.setupInteractions();
    
    if (this.visible) {
      this.vectorGrid.addTo(map);
      //console.log('✅ MVT layer added to map (visible)');
    } else {
      //console.log('ℹ️ MVT layer initialized (hidden)');
    }
    
    return this.vectorGrid;
  }
  
  setupZoomBehavior(map) {
    const applyZoomFilter = () => {
      const zoom = map.getZoom();
      
      if (zoom === this.currentZoom) return;
      this.currentZoom = zoom;
      
      //console.log(`🔍 Zoom ${zoom} - applying filter`);
      
      if (zoom >= 6 && zoom <= 9) {
        this.showLayers(['backbone']);
      } else if (zoom >= 10 && zoom <= 12) {
        this.showLayers(['backbone', 'popular']);
      } else if (zoom >= 13 && zoom <= 14) {
        this.showLayers(['backbone', 'popular']);
      } else if (zoom >= 15) {
        this.showLayers(['backbone', 'popular', 'local']);
      }
    };
    
    map.on('zoomend', applyZoomFilter);
    applyZoomFilter();
  }
  
  showLayers(visibleLayers) {
    const layersSet = new Set(visibleLayers);
    
    this.tileConfig.internal_layers.forEach(layerName => {
      if (layersSet.has(layerName)) {
        this.vectorGrid.options.vectorTileLayerStyles[layerName] = (props) => 
          this.getFeatureStyle(props, layerName);
      } else {
        this.vectorGrid.options.vectorTileLayerStyles[layerName] = () => ({
          stroke: false,
          fill: false
        });
      }
    });
    
    this.vectorGrid.redraw();
  }
  
  getFeatureStyle(props, layerName) {
    const score = props.edge_score || 0;
    const classification = props.classification || layerName;
    
    let color = '#94a3b8';
    
    if (classification === 'backbone' || score > 80) {
      color = '#ef4444';
    } else if (classification === 'popular' || score > 50) {
      color = '#f59e0b';
    } else if (classification === 'local' || score > 20) {
      color = '#10b981';
    }
    
    // ✅ FIX: Dodano fill: false + fillOpacity: 0
    return {
      color: color,
      weight: classification === 'backbone' ? 3 : (classification === 'popular' ? 2 : 1.5),
      opacity: 0.7,
      fill: false,          // ✅ KLUCZOWE
      fillOpacity: 0,       // ✅ KLUCZOWE
      lineCap: 'round',
      lineJoin: 'round'
    };
  }
  
  setupInteractions() {
    this.vectorGrid.on('click', (e) => {
      const props = e.layer.properties;
      //console.log('📍 Clicked segment:', props);
      
      if (window.Alpine && this.config.onSegmentClick) {
        this.config.onSegmentClick(props);
      }
    });
    
    this.vectorGrid.on('mouseover', (e) => {
      const id = e.layer.properties.segment_id || e.layer.properties.q2vt_fid || e.layer.properties.edge_id;
      if (id) {
        this.vectorGrid.setFeatureStyle(id, {
          color: '#ffffff',
          weight: 4,
          opacity: 1,
          fill: false,
          fillOpacity: 0
        });
      }
    });
    
    this.vectorGrid.on('mouseout', (e) => {
      const id = e.layer.properties.segment_id || e.layer.properties.q2vt_fid || e.layer.properties.edge_id;
      if (id) {
        this.vectorGrid.resetFeatureStyle(id);
      }
    });
  }
  
  show() {
    if (this.vectorGrid && window.map) {
      this.vectorGrid.addTo(window.map);
      this.visible = true;
      //console.log('✅ MVT layer shown');
    }
  }
  
  hide() {
    if (this.vectorGrid && window.map) {
      window.map.removeLayer(this.vectorGrid);
      this.visible = false;
      //console.log('ℹ️ MVT layer hidden');
    }
  }
  
  toggle() {
    this.visible ? this.hide() : this.show();
  }
  
  async load(bbox) {
    //console.log('ℹ️ MVT layer load() called (no-op)');
  }
}

window.MVTLayer = MVTLayer;