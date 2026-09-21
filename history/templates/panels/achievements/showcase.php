<!-- SHOWCASE - TOP 3 MEDALS -->
<div x-show="!loadingAchievements && userAchievements.filter(a => a.earned).length > 0" 
     style="padding: 20px 24px; border-bottom: 1px solid var(--color-border-light);" 
     x-cloak>
  
  <div style="font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 12px; letter-spacing: 0.05em; text-transform: uppercase;">
    Twoja Gablota
  </div>
  
  <div style="display: flex; gap: 12px; overflow-x: auto; padding-bottom: 4px;">
    
    <!-- Showcased medals -->
    <template x-for="badge in userAchievements.filter(a => a.is_in_showcase).slice(0, 3)" :key="badge.level_id">
      <div style="flex-shrink: 0; width: 80px; text-align: center; cursor: pointer;" @click="openMedalModal(badge)">
        
        <!-- Medal ring (smaller for showcase) -->
        <div style="position: relative; width: 64px; height: 64px; margin: 0 auto 8px;">
          
          <!-- Background ring -->
          <svg style="position: absolute; top: 0; left: 0; transform: rotate(-90deg);" width="64" height="64" viewBox="0 0 64 64">
            <circle cx="32" cy="32" r="29" fill="none" stroke="rgba(251, 191, 36, 0.2)" stroke-width="3"/>
            <circle 
              cx="32" cy="32" r="29" 
              fill="none" 
              stroke="#fbbf24"
              stroke-width="3"
              stroke-linecap="round"
              stroke-dasharray="182"
              stroke-dashoffset="0"/>
          </svg>
          
          <!-- Medal image -->
          <div class="medal-image-container" style="width: 48px; height: 48px;">
            <template x-if="badge.badge_image_url">
              <img :src="badge.badge_image_url" 
                   :alt="badge.medal_name" 
                   class="medal-image">
            </template>
            
            <template x-if="!badge.badge_image_url">
              <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" :fill="badge.badge_color || '#fbbf24'" stroke="none">
                <circle cx="12" cy="8" r="7"/>
                <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>
              </svg>
            </template>
          </div>
          
          <!-- Star badge -->
          <div style="position: absolute; top: -4px; right: -4px; background: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="#fbbf24" stroke="none">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>
          </div>
        </div>
        
        <div class="medal-name" x-text="badge.medal_name"></div>
      </div>
    </template>
    
    <!-- Empty slots -->
    <template x-for="i in Math.max(0, 3 - (userAchievements.filter(a => a.is_in_showcase).length))" :key="'empty-' + i">
      <div style="flex-shrink: 0; width: 80px; text-align: center;">
        <div style="width: 64px; height: 64px; margin: 0 auto 8px; background: rgba(0,0,0,0.02); border: 2px dashed var(--color-border); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
        </div>
        <div style="font-size: 9px; color: var(--color-text-light);">Puste</div>
      </div>
    </template>
  </div>
</div>