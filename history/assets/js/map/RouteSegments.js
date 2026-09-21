/* ============================================================================
   ROUTE SEGMENTS VISUALIZATION - Pokazuje ukończone/nieukończone segmenty
   ============================================================================ */

window.RouteSegmentsLayer = {
  
  layer: null,
  
  /**
   * Show route segments with completion status
   */
  showSegments(map, segmentsGeoJSON) {
    this.clear(map);
    
    if (!segmentsGeoJSON || !segmentsGeoJSON.features) {
      return;
    }
    
    this.layer = L.geoJSON(segmentsGeoJSON, {
      style: (feature) => {
        const isCompleted = feature.properties.is_completed;
        
        return {
          color: isCompleted ? '#10b981' : '#ef4444',
          weight: 6,
          opacity: 0.8,
          lineCap: 'round',
          lineJoin: 'round'
        };
      },
      onEachFeature: (feature, layer) => {
        const props = feature.properties;
        const status = props.is_completed ? 'Ukończony' : 'Nieukończony';
        const color = props.is_completed ? '#10b981' : '#ef4444';
        
        layer.bindPopup(`
          <div style="padding: 8px;">
            <div style="font-weight: 600; margin-bottom: 4px; color: ${color};">
              ${status}
            </div>
            <div style="font-size: 12px; color: #666;">
              Długość: ${(props.length_m / 1000).toFixed(2)} km
            </div>
          </div>
        `);
      }
    }).addTo(map);
    
    //console.log('✅ Route segments layer added');
  },
  
  /**
   * Clear segments layer
   */
  clear(map) {
    if (this.layer) {
      map.removeLayer(this.layer);
      this.layer = null;
    }
  }
};