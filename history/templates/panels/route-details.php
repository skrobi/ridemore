<?php
/* ============================================================================
   PLIK: templates/panels/route-details.php
   Panel: Route details (right side)
   ============================================================================ */
?>
<div class="left-panel route-details-panel" 
     :class="{ 
       collapsed: selectedRoute === null,
       'route-details-panel--expanded': detailsExpanded 
     }">
  <div class="panel-header">
    <h2>Szczegóły trasy</h2>
    <button class="close-btn" @click="closeRouteDetails()">
        <i class="fas fa-times"></i>
    </button>
  </div>
  
  <!-- Loading state -->
  <div x-show="loadingRouteDetails" class="loading-spinner" x-cloak>
    <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--color-primary);"></i>
    <div style="margin-top: 12px; font-size: 14px; color: var(--color-text-muted);">
      Ładowanie szczegółów trasy...
    </div>
  </div>
  
  <!-- Rendered content from API -->
  <div x-show="!loadingRouteDetails && routeDetails" x-cloak>
    <div x-html="routeDetails?.html || ''"></div>
  </div>
</div>