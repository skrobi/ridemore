<?php
/* ============================================================================
   PLIK: templates/partials/top-overlay.php
   Mobile overlay for quick route view
   UPDATED: używa topPanelData + SVG icons
   ============================================================================ */
?>
<div id="top-overlay" x-show="showTopOverlay" x-cloak>
  <div class="overlay-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="display: inline-block; margin-right: 8px; vertical-align: middle;">
      <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
    </svg>
    Top w tym obszarze
  </div>
  
  <template x-for="route in (topPanelData?.top_quality || []).slice(0, 3)" :key="route.route_id">
    <div class="overlay-item" @click="openRouteDetails(route.route_id)">
      <span x-text="route.name"></span> 
      <span style="color: var(--color-text-muted);">•</span> 
      <span x-text="route.distance_km + ' km'"></span>
    </div>
  </template>
  
  <!-- Empty state -->
  <div x-show="!topPanelData?.top_quality || topPanelData.top_quality.length === 0" 
       style="padding: 20px; text-align: center; color: var(--color-text-muted);">
    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display: block; margin: 0 auto 12px; opacity: 0.3;">
      <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
      <circle cx="12" cy="10" r="3"/>
    </svg>
    <div style="font-size: 13px;">Brak tras w tym obszarze</div>
  </div>
  
  <button class="close-overlay" @click="showTopOverlay = false">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" y1="6" x2="6" y2="18"/>
      <line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  </button>
</div>