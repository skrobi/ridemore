<?php
/* ============================================================================
   Moje Aktywności Panel - z datami i źródłem
   ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'tracks' }">
  
  <!-- HEADER -->
  <div class="panel-header">
    <h2>MOJE AKTYWNOŚCI</h2>
    <button class="close-btn" @click="closeAll()">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>
  
  <!-- UPLOAD BUTTON + CONTEXT MENU -->
  <div style="padding: 0 24px 16px; position: relative;">

    <!-- Main button -->
    <button class="btn btn-primary btn-block" @click="handleUploadClick()">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="17 8 12 3 7 8"/>
        <line x1="12" y1="3" x2="12" y2="15"/>
      </svg>
      ZAŁADUJ AKTYWNOŚĆ
    </button>

    <!-- Context menu – pojawia się tylko gdy jest linked integracja -->
    <div x-show="uploadMenuOpen"
         x-cloak
         @click.outside="uploadMenuOpen = false"
         style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--bg-card, #fff); border: 1px solid var(--color-border, #e2e8f0); border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.18); z-index: 100; overflow: hidden;">

      <!-- GPX Upload -->
      <button @click="IntegrationsModule.uploadGPX(); uploadMenuOpen = false;"
              style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: none; border: none; color: var(--color-text); font-size: 14px; cursor: pointer; text-align: left;"
              @mouseenter="$el.style.background = 'rgba(0,0,0,0.04)'"
              @mouseleave="$el.style.background = 'none'">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: #6366f1; flex-shrink: 0;">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/>
          <line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        <span>Upload GPX</span>
      </button>

      <!-- Divider -->
      <div style="border-top: 1px solid var(--color-border-light, #f1f5f9); margin: 2px 0;"></div>

      <!-- Strava – tylko jeśli linked -->
      <button x-show="integrations.strava.linked"
              @click="openProviderImport('strava'); uploadMenuOpen = false;"
              style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: none; border: none; color: var(--color-text); font-size: 14px; cursor: pointer; text-align: left;"
              @mouseenter="$el.style.background = 'rgba(0,0,0,0.04)'"
              @mouseleave="$el.style.background = 'none'">
        <div style="width: 24px; height: 24px; border-radius: 6px; background: #FC5425; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="white">
            <path d="M15.5 2.5L11 12h3.5L10 22l8-11h-4l4-8.5z"/>
          </svg>
        </div>
        <span>Import z Strava</span>
      </button>

      <!-- Garmin – tylko jeśli linked (disabled bo w przygotowaniu) -->
      <button x-show="integrations.garmin.linked"
              disabled
              style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: none; border: none; color: var(--color-text); font-size: 14px; cursor: not-allowed; text-align: left; opacity: 0.4;">
        <div style="width: 24px; height: 24px; border-radius: 6px; background: #003087; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
          <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
          </svg>
        </div>
        <span style="flex: 1;">Import z Garmin</span>
        <span style="font-size: 10px; background: var(--color-border, #e2e8f0); color: var(--color-text-muted); padding: 2px 6px; border-radius: 4px;">Wkrótce</span>
      </button>

    </div>

    <!-- Hidden file input for GPX -->
    <input 
      type="file" 
      accept=".gpx" 
      x-ref="activityInput" 
      @change="handleGPXUpload($event)" 
      style="display: none;">
  </div>
  
  <!-- FILTERS -->
  <div style="padding: 0 24px 16px;">
    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
      <button 
        class="btn btn-sm"
        :class="tracksFilter === 'all' ? 'btn-primary' : 'btn-outline'"
        @click="tracksFilter = 'all'"
        style="font-size: 12px;">
        Wszystkie
      </button>
      <button 
        class="btn btn-sm"
        :class="tracksFilter === 'week' ? 'btn-primary' : 'btn-outline'"
        @click="tracksFilter = 'week'"
        style="font-size: 12px;">
        Ten tydzień
      </button>
      <button 
        class="btn btn-sm"
        :class="tracksFilter === 'month' ? 'btn-primary' : 'btn-outline'"
        @click="tracksFilter = 'month'"
        style="font-size: 12px;">
        Ten miesiąc
      </button>
    </div>
  </div>
  
  <!-- LOADING -->
  <div x-show="loadingTracks" x-cloak style="text-align: center; padding: 40px 0;">
    <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
      <line x1="12" y1="2" x2="12" y2="6"/>
      <line x1="12" y1="18" x2="12" y2="22"/>
    </svg>
  </div>
  
  <!-- ACTIVITIES LIST - grouped by date -->
  <div x-show="!loadingTracks" style="padding: 0 24px 24px;" x-cloak>
    
    <template x-for="[dateKey, tracks] in Object.entries(getGroupedTracks())" :key="dateKey">
      <div style="margin-bottom: 24px;">
        <!-- Date header -->
        <div style="font-size: 11px; font-weight: 600; color: var(--color-text-muted); margin-bottom: 12px; letter-spacing: 0.05em; text-transform: uppercase; padding-bottom: 8px; border-bottom: 1px solid var(--color-border-light);">
          <span x-text="formatDateHeader(dateKey)"></span>
          <span style="font-weight: 400; color: var(--color-text-light); margin-left: 8px;">
            (<span x-text="tracks.length"></span>)
          </span>
        </div>
        
        <!-- Tracks in this date -->
        <template x-for="track in tracks" :key="track.route_id">
          <div style="margin-bottom: 12px; position: relative;">
            <!-- Source badge -->
            <div style="position: absolute; top: 12px; right: 12px; z-index: 10;">
              <div :style="`background: ${getSourceColor(track.source)}; padding: 4px 10px; border-radius: 12px; font-size: 9px; font-weight: 600; color: white; letter-spacing: 0.03em; text-transform: uppercase; box-shadow: 0 2px 4px rgba(0,0,0,0.1);`">
                <span x-text="getSourceLabel(track.source)"></span>
              </div>
            </div>
            
            <!-- Activity card -->
            <div x-html="window.RouteCardRenderer.render({
              route_id: track.route_id,
              name: track.name,
              distance_km: track.distance_km,
              ascent_m: track.ascent_m || 0,
              difficulty_level: track.difficulty_level || 'medium',
              rating: 0,
              rating_count: 0,
              is_completed: false,
              is_top: false,
              in_challenge: false,
              source: track.source,
              activity_date: track.activity_date
            }, { 
              showDelete: true,
              showDifficulty: false,
              showRating: false,
              showBadges: false
            })" 
            @click="openRouteDetails(track.route_id)"
            style="cursor: pointer;">
            </div>
            
            <!-- Time -->
            <div style="padding: 0 8px; margin-top: 8px;">
              <span style="font-size: 11px; color: var(--color-text-light);" x-text="formatTime(track.activity_date)"></span>
            </div>
          </div>
        </template>
      </div>
    </template>
    
    <!-- EMPTY STATE -->
    <div x-show="Object.keys(getGroupedTracks()).length === 0" class="empty-state" x-cloak>
      <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/>
        <polyline points="12 6 12 12 16 14"/>
      </svg>
      <div class="empty-state-title">Brak aktywności</div>
      <p style="font-size: 13px; color: var(--color-text-light); margin-top: 8px;">
        <span x-show="tracksFilter === 'all'">Załaduj swój pierwszy plik GPX lub podłącz Strava</span>
        <span x-show="tracksFilter === 'week'">Brak aktywności w tym tygodniu</span>
        <span x-show="tracksFilter === 'month'">Brak aktywności w tym miesiącu</span>
      </p>
    </div>
  </div>
  
</div>

<style>
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.animate-spin {
  animation: spin 1s linear infinite;
}
</style>