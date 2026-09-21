<?php
/* ============================================================================
   PLIK: templates/panels/achievements.php
   Panel: User achievements - CLEAN REFACTORED
   ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'achievements' }">
  
  <!-- HEADER -->
  <div style="padding: 24px 24px 16px; border-bottom: 1px solid var(--color-border-light);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
      <h1 style="font-size: 20px; font-weight: 300; letter-spacing: 0.02em; margin: 0; color: var(--color-text); display: flex; align-items: center; gap: 12px;">
        <img src="<?= asset('icons/medal.svg') ?>" alt="" class="icon-svg">
        OSIĄGNIĘCIA
      </h1>
      <button class="close-btn" @click="closeAll()" style="background: transparent; border: none; cursor: pointer; padding: 8px; color: var(--color-text-muted); transition: color 0.2s;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div style="height: 4px; width: 60px; background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%); border-radius: 2px; opacity: 0.6;"></div>
  </div>
  
  <?php include __DIR__ . '/achievements/showcase.php'; ?>
  
  <?php include __DIR__ . '/achievements/check-button.php'; ?>
  
  <?php include __DIR__ . '/achievements/filters.php'; ?>
  
  <?php include __DIR__ . '/achievements/loading.php'; ?>
  
  <?php include __DIR__ . '/achievements/medals-grid.php'; ?>
  
  <?php include __DIR__ . '/achievements/empty-state.php'; ?>
  
</div>

<?php include __DIR__ . '/../modals/achievements-modal.php'; ?>

<style>
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.animate-spin {
  animation: spin 1s linear infinite;
}

/* Shield Filters */
.filter-shield {
  background: linear-gradient(135deg, rgba(245, 245, 247, 0.8) 0%, rgba(229, 231, 235, 0.5) 100%);
  color: var(--color-text-muted);
  border: 1px solid var(--color-border-light);
}

.filter-shield:hover {
  background: linear-gradient(135deg, rgba(245, 245, 247, 1) 0%, rgba(229, 231, 235, 0.8) 100%);
  border-color: var(--color-border);
  transform: translateY(-1px);
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.filter-shield-active {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
  color: white;
  border: 1px solid #2563eb;
  box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.filter-shield-active:hover {
  background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

/* Medal Cards */
.achievement-card {
  background: white;
  border: 1.5px solid;
  border-radius: 16px;
  padding: 16px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.achievement-card-earned {
  border-color: rgba(59, 130, 246, 0.2);
}

.achievement-card-locked {
  border-color: rgba(0,0,0,0.08);
}

.achievement-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 16px rgba(0,0,0,0.1);
}
</style>