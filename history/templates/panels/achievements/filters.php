<!-- FILTERS -->
<div style="padding: 0 24px 20px;">
  <div style="display: flex; gap: 8px;">
    <button 
      @click="setAchievementFilter('all')"
      :class="achievementFilter === 'all' ? 'filter-shield-active' : 'filter-shield'"
      style="flex: 1; position: relative; padding: 10px 12px; border: none; border-radius: 8px; cursor: pointer; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s;">
      <svg style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0.1; pointer-events: none;" viewBox="0 0 100 100" preserveAspectRatio="none">
        <path d="M50 5 L90 20 L90 50 Q90 80 50 95 Q10 80 10 50 L10 20 Z" fill="currentColor"/>
      </svg>
      <span style="position: relative; z-index: 1;">Wszystkie</span>
    </button>
    
    <button 
      @click="setAchievementFilter('earned')"
      :class="achievementFilter === 'earned' ? 'filter-shield-active' : 'filter-shield'"
      style="flex: 1; position: relative; padding: 10px 12px; border: none; border-radius: 8px; cursor: pointer; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s;">
      <svg style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0.1; pointer-events: none;" viewBox="0 0 100 100" preserveAspectRatio="none">
        <path d="M50 5 L90 20 L90 50 Q90 80 50 95 Q10 80 10 50 L10 20 Z" fill="currentColor"/>
      </svg>
      <span style="position: relative; z-index: 1;">Zdobyte</span>
    </button>
    
    <button 
      @click="setAchievementFilter('locked')"
      :class="achievementFilter === 'locked' ? 'filter-shield-active' : 'filter-shield'"
      style="flex: 1; position: relative; padding: 10px 12px; border: none; border-radius: 8px; cursor: pointer; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s;">
      <svg style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0.1; pointer-events: none;" viewBox="0 0 100 100" preserveAspectRatio="none">
        <path d="M50 5 L90 20 L90 50 Q90 80 50 95 Q10 80 10 50 L10 20 Z" fill="currentColor"/>
      </svg>
      <span style="position: relative; z-index: 1;">Zablokowane</span>
    </button>
  </div>
</div>