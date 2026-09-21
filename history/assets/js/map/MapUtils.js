/* ============================================================================
   MAP UTILS
   Narzędzia pomocnicze dla mapy
   ============================================================================ */

class MapUtils {
  constructor(map) {
    this.map = map;
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
    this.map.fitBounds(bounds, { 
      padding: [50, 50], 
      ...options 
    });
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

window.MapUtils = MapUtils;