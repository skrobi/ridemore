<!-- EMPTY STATE -->
<div x-show="!loadingAchievements && getFilteredAchievements().length === 0" style="text-align: center; padding: 60px 24px; color: var(--color-text-muted);" x-cloak>
  <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; opacity: 0.3;">
    <circle cx="12" cy="8" r="7"/>
    <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>
  </svg>
  <div style="font-size: 15px; font-weight: 500; color: var(--color-text); margin-bottom: 8px;">Brak osiągnięć</div>
  <div style="font-size: 13px; line-height: 1.6;">Podejmij wyzwania aby<br>zdobywać medale</div>
</div>