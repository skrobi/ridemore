/* ============================================================================
   SETTINGS MODULE - App settings & preferences
   ============================================================================ */

window.SettingsModule = {
  
  loadSettings(state) {
    const saved = localStorage.getItem('app_settings');
    if (saved) {
      try {
        const parsed = JSON.parse(saved);
        state.settings = { ...state.settings, ...parsed };
      } catch (error) {
        console.error('Failed to load settings:', error);
      }
    }
  },
  
  saveSettings(state) {
    localStorage.setItem('app_settings', JSON.stringify(state.settings));
  }
};