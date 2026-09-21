/* ============================================================================
   ROUTE DISPLAY
   Wyświetlanie pojedynczych tras na mapie
   ============================================================================ */

class RouteDisplay {
  constructor(map, layersRef) {
    this.map = map;
    this.layers = layersRef;
    this.temporaryRouteLayer = null;
  }
  
  async showRoute(routeId) {
    //console.log('🗺️ RouteDisplay: showing route', routeId);
    
    // Sprawdź czy trasa jest w jakiejś warstwie
    for (const layer of Object.values(this.layers)) {
      if (layer.hasItem && layer.hasItem(routeId)) {
        //console.log('✅ Found in layer:', layer.config?.name);
        
        if (layer.visible) {
          await layer.selectRoute(routeId);
          return;
        }
        
        //console.log('ℹ️ Layer hidden, drawing directly');
        break;
      }
    }
    
    // Pobierz geometrię z API
    //console.log('📡 Fetching route geometry from API...');
    
    try {
      const response = await fetch(`${window.APP_CONFIG.apiUrl}/routes/details.php?id=${routeId}`);
      const data = await response.json();
      
      if (!data.success || !data.data || !data.data.geometry) {
        console.error('❌ No geometry in response');
        return;
      }
      
      const route = data.data;
      const geometry = route.geometry;
      
      if (geometry.type !== 'LineString' || !geometry.coordinates || geometry.coordinates.length === 0) {
        console.error('❌ Invalid geometry');
        return;
      }
      
      // Usuń poprzednią tymczasową trasę
      if (this.temporaryRouteLayer) {
        this.map.removeLayer(this.temporaryRouteLayer);
        this.temporaryRouteLayer = null;
      }
      
      // Pobierz styl
      const style = window.RouteStyles ? 
        window.RouteStyles.getStyle(route, 'temporary') :
        { 
          color: '#00d4ff', 
          weight: 5, 
          opacity: 1,
          fill: false,
          fillOpacity: 0
        };
      
      //console.log('🎨 Using style:', style);
      
      // Swap coordinates [lng,lat] → [lat,lng] dla Leaflet
      const coords = geometry.coordinates.map(c => {
        if (!Array.isArray(c) || c.length < 2) {
          console.error('❌ Invalid coordinate:', c);
          return null;
        }
        return [c[1], c[0]];
      }).filter(c => c !== null);
      
      if (coords.length === 0) {
        console.error('❌ No valid coordinates after swap');
        return;
      }
      
      // Rysuj trasę
      this.temporaryRouteLayer = L.polyline(coords, style);
      this.temporaryRouteLayer.addTo(this.map);
      
      const bounds = this.temporaryRouteLayer.getBounds();
      
      this.map.fitBounds(bounds, {
        padding: [50, 50],
        maxZoom: 14
      });
      
      this.map.fire('route:selected', { routeId: routeId });
      
      this.temporaryRouteLayer.bindTooltip(route.name, {
        permanent: false,
        direction: 'top'
      });
      
      //console.log('✅ Route displayed on map successfully');
      
    } catch (error) {
      console.error('❌ Error in showRoute():', error);
    }
  }
  
  deselectRoute() {
    Object.values(this.layers).forEach(layer => {
      layer.deselectRoute && layer.deselectRoute();
    });
    
    // Usuń tymczasową trasę
    if (this.temporaryRouteLayer) {
      this.map.removeLayer(this.temporaryRouteLayer);
      this.temporaryRouteLayer = null;
    }
  }
}

window.RouteDisplay = RouteDisplay;