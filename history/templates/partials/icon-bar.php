<?php
/* ============================================================================
   PLIK: templates/partials/icon-bar.php
   Left sidebar with navigation icons (6 icons)
   ============================================================================ */
?>
<aside id="icon-bar">
  <div id="logo" @click="closeAll()">
    <img src="<?= asset('logo-56x56.png') ?>">
  </div>
  
  <!-- Top Routes -->
  <button class="icon-btn" 
          :class="{ active: activePanel === 'top-routes' }"
          @click="openPanel('top-routes')"
          title="Top w tym obszarze">
    <img src="<?= asset('icons/map.svg') ?>" alt="" class="icon-svg">
    <span class="badge" x-show="topPanelData?.top_quality?.length > 0" x-text="topPanelData?.top_quality?.length || 0"></span>
  </button>
  
  <!-- Feed / Activity -->
  <button class="icon-btn"
          :class="{ active: activePanel === 'feed' }"
          @click="openPanel('feed')"
          title="Aktywność">
    <img src="<?= asset('icons/rss.svg') ?>" alt="" class="icon-svg">
    <span class="badge" 
          x-show="unreadNotificationsCount > 0" 
          x-text="unreadNotificationsCount > 99 ? '99+' : unreadNotificationsCount" 
          x-cloak></span>
  </button>
  
  <!-- My Tracks -->
  <button class="icon-btn"
          :class="{ active: activePanel === 'tracks' }"
          @click="openPanel('tracks')"
          title="Moje ślady">
    <img src="<?= asset('icons/bike.svg') ?>" alt="" class="icon-svg">
    <span class="badge" x-show="userTracks.length > 0" x-text="userTracks.length" x-cloak></span>
  </button>
  
    <!-- My Tracks -->
  <button class="icon-btn"
          :class="{ active: activePanel === 'planned' }"
          @click="openPanel('planned')"
          title="Zaplanowa trasy">
    <img src="<?= asset('icons/route.svg') ?>" alt="" class="icon-svg">
    <span class="badge" x-show="userTracksPlanned.length > 0" x-text="userTracksPlanned.length" x-cloak></span>
  </button>
  
  <!-- Challenges -->
  <button class="icon-btn"
          :class="{ active: activePanel === 'challenges' }"
          @click="openPanel('challenges')"
          title="Wyzwania">
    <img src="<?= asset('icons/target.svg') ?>" alt="" class="icon-svg">
    <span class="badge" 
          x-show="userChallenges.length > 0" 
          x-text="userChallenges.length" 
          x-cloak></span>
  </button>
  
  <!-- Achievements -->
  <button class="icon-btn"
          :class="{ active: activePanel === 'achievements' }"
          @click="openPanel('achievements')"
          title="Twoje osiągnięcia">
    <img src="<?= asset('icons/medal.svg') ?>" alt="" class="icon-svg">
    <span class="badge" 
          x-show="userAchievements.filter(a => a.earned).length > 0" 
          x-text="userAchievements.filter(a => a.earned).length" 
          x-cloak></span>
  </button>
  
  <!-- Library -->
  <button class="icon-btn"
          :class="{ active: activePanel === 'library' }"
          @click="openPanel('library')"
          title="Biblioteka tras">
    <img src="<?= asset('icons/folder.svg') ?>" alt="" class="icon-svg">
  </button>
  
  <!-- Settings -->
  <button class="icon-btn"
          :class="{ active: activePanel === 'settings' }"
          @click="openPanel('settings')"
          style="margin-top: auto;"
          title="Ustawienia">
    <img src="<?= asset('icons/settings.svg') ?>" alt="" class="icon-svg">
  </button>
</aside>