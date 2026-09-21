/* ============================================================================
   POI MANAGER
   Zarządzanie punktami POI i ich filtrami
   ============================================================================ */

class POIManager {
  constructor(map, layersRef) {
    this.map = map;
    this.layers = layersRef;
    this.lastActiveSubtypes = new Set();
  }
  
  setLastActiveSubtypes(subtypes) {
    this.lastActiveSubtypes = subtypes;
  }
  
  reloadPOI() {
    const poiLayer = this.layers['poi'];
    if (!poiLayer) {
      console.error('❌ POI layer not found!');
      return;
    }
    
    const bounds = this.map.getBounds();
    const bbox = [
      bounds.getWest(),
      bounds.getSouth(),
      bounds.getEast(),
      bounds.getNorth()
    ];
    
    const checkboxes = document.querySelectorAll('.poi-subtype-toggle:checked');
    const activeSubtypes = Array.from(checkboxes).map(cb => cb.dataset.subtype);
    const activeSet = new Set(activeSubtypes);
    
    //console.log('🔄 Reloading POI');
    //console.log('📋 Active subtypes:', activeSubtypes);
    
    const removed = Array.from(this.lastActiveSubtypes).filter(s => !activeSet.has(s));
    
    if (activeSubtypes.length === 0) {
      //console.log('⚠️ No active subtypes - clearing POI layer');
      poiLayer.clear();
      this.lastActiveSubtypes = new Set();
      return;
    }
    
    if (removed.length > 0) {
      this.removePOIBySubtypes(poiLayer, removed);
    }
    
    //console.log('🌐 Fetching POI for subtypes:', activeSubtypes);
    poiLayer.fetch(bbox, { subtypes: activeSubtypes })
      .then(data => {
        if (data) {
          poiLayer.render(data);
        }
      })
      .catch(error => {
        console.error('❌ Fetch error:', error);
      });
    
    this.lastActiveSubtypes = activeSet;
  }
  
  removePOIBySubtypes(poiLayer, subtypeSlugs) {
    const slugsSet = new Set(subtypeSlugs);
    
    for (const [poiId, marker] of poiLayer.items.entries()) {
      if (marker.poiSubtype && slugsSet.has(marker.poiSubtype)) {
        poiLayer.clusterGroup.removeLayer(marker);
        poiLayer.items.delete(poiId);
      }
    }
  }
  
  reloadPOIOnMove() {
    const poiLayer = this.layers['poi'];
    if (!poiLayer) return;
    
    const bounds = this.map.getBounds();
    const bbox = [
      bounds.getWest(),
      bounds.getSouth(),
      bounds.getEast(),
      bounds.getNorth()
    ];
    
    const activeSubtypes = Array.from(
      document.querySelectorAll('.poi-subtype-toggle:checked')
    ).map(cb => cb.dataset.subtype);
    
    if (activeSubtypes.length === 0) {
      poiLayer.clear();
      return;
    }
    
    poiLayer.fetch(bbox, { subtypes: activeSubtypes })
      .then(data => {
        if (data) {
          poiLayer.render(data);
        }
      });
  }
}

window.POIManager = POIManager;