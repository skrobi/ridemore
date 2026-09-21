<!-- CHECK MEDALS BUTTON -->
<div style="padding: 20px 24px;">
  <button 
    @click="checkMyMedals()"
    :disabled="loadingAchievements"
    class="btn btn-primary btn-block"
    style="display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 13px; font-weight: 500;">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
    </svg>
    <span x-text="loadingAchievements ? 'Sprawdzam...' : 'SPRAWDŹ MEDALE'"></span>
  </button>
</div>