<?php
/* ============================================================================
   PLIK: templates/partials/scripts.php
   All JavaScript includes
   ============================================================================ */
?>
<!-- Leaflet -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Leaflet plugins -->
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet.vectorgrid@1.3.0/dist/Leaflet.VectorGrid.bundled.js"></script>

<!-- Map Core - POPRAWIONA KOLEJNOŚĆ Z MODUŁAMI -->
<script src="<?= asset('js/map/CacheManager.js') ?>"></script>
<script src="<?= asset('js/exif.js') ?>"></script>
<script src="<?= asset('js/map/BaseLayer.js') ?>"></script>
<script src="<?= asset('js/map/POILayer.js') ?>"></script>
<script src="<?= asset('js/map/OverlayLayer.js') ?>"></script>
<script src="<?= asset('js/map/MVTLayer.js') ?>"></script>
<script src="<?= asset('js/map/MVTOverlayLayer.js') ?>"></script>

<!-- Map Modules (NOWE - przed MapManager) -->
<script src="<?= asset('js/map/ChallengeMarkers.js') ?>"></script>
<script src="<?= asset('js/map/MapUtils.js') ?>"></script>
<script src="<?= asset('js/map/LayersManager.js') ?>"></script>
<script src="<?= asset('js/map/LayerControlsUI.js') ?>"></script>
<script src="<?= asset('js/map/POIManager.js') ?>"></script>
<script src="<?= asset('js/map/RouteDisplay.js') ?>"></script>
<script src="<?= asset('js/map/LiveTrackingLayer.js') ?>"></script>

<!-- Map Orchestrator -->
<script src="<?= asset('js/map/MapManager.js') ?>"></script>
<script src="<?= asset('js/map/CanvasTileLayer.js') ?>"></script>
<script src="<?= asset('js/map.js') ?>"></script>

<!-- Utils -->
<script src="<?= asset('js/utils.js') ?>"></script>
<script src="<?= asset('js/components/RouteHighlight.js') ?>"></script>

<!-- App Modules (ładuj PRZED app.js) -->
<script src="<?= asset('js/app/state.js') ?>"></script>
<script src="<?= asset('js/app/user.js') ?>"></script>
<script src="<?= asset('js/app/routes.js') ?>"></script>
<script src="<?= asset('js/app/recommendations.js') ?>"></script>
<script src="<?= asset('js/app/challengeVerify.js') ?>"></script>
<script src="<?= asset('js/components/RouteCoverageVisualizer.js') ?>"></script>
<script src="<?= asset('js/components/MedalRenderer.js') ?>"></script>
<script src="<?= asset('js/components/PhotoModule.js') ?>"></script>
<script src="<?= asset('js/components/PoiCreator.js') ?>"></script>
<script src="<?= asset('js/components/IntegrationsModule.js') ?>"></script>
<script src="<?= asset('js/components/TrackingModule.js') ?>"></script>

<script src="<?= asset('js/app/tracks.js') ?>"></script>
<script src="<?= asset('js/app/challenges.js') ?>"></script>
<script src="<?= asset('js/app/achievements.js') ?>"></script>
<script src="<?= asset('js/app/feed.js') ?>"></script>
<script src="<?= asset('js/components/RouteCardRenderer.js') ?>"></script>
<script src="<?= asset('js/app/library.js') ?>"></script>
<script src="<?= asset('js/app/panels.js') ?>"></script>
<script src="<?= asset('js/app/settings.js') ?>"></script>
<script src="<?= asset('js/app/rankingModal.js') ?>"></script>
<script src="<?= asset('js/app/profileModule.js') ?>"></script>
<script src="<?= asset('js/app/planned.js') ?>"></script>

<!-- App orchestrator (ostatni) -->
<script src="<?= asset('js/app.js') ?>"></script>

<!-- Service Worker -->
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= asset('sw.js') ?>').catch(() => {});
  }
</script>

<!-- Fallback renderer for JS -->
<script>
    
  function toggleGradientColoring(routeId) {
    if (!window.mapManager) return;
    
    for (const layer of Object.values(window.mapManager.layers)) {
      if (layer.hasItem && layer.hasItem(routeId)) {
        layer.renderWithGradient(routeId);
        break;
      }
    }
  }
</script>