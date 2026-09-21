<?php
/* ============================================================================
   PLIK: templates/panels/settings.php
   Panel: App settings and user profile
   ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'settings' }">
  <div class="panel-header">
    <h2>USTAWIENIA</h2>
    <button class="close-btn" @click="closeAll()">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>
  
  <!-- User Profile -->
  <div class="card">
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-primary);">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
      <div style="flex: 1; min-width: 0;">
        <div style="font-weight: 600; overflow: hidden; text-overflow: ellipsis;" x-text="user.email || 'Niezalogowany'"></div>
        <div style="font-size: 12px; color: var(--color-text-muted);" x-text="user.status === 'guest' ? 'Konto tymczasowe' : 'Konto zweryfikowane'"></div>
      </div>
    </div>
    
    <button class="btn btn-outline btn-block" 
            x-show="!user.email" 
            @click="showLoginModal = true"
            x-cloak>
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
      Zaloguj się
    </button>
    
    <!-- Edit Profile Button (for verified users) -->
    <button class="btn btn-primary btn-block" 
            x-show="user.email" 
            @click="openProfileModal()"
            x-cloak
            style="margin-bottom: 8px;">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
      </svg>
      Edytuj profil
    </button>
    
    <button class="btn btn-secondary btn-block" 
            x-show="user.email" 
            @click="logout()"
            x-cloak>
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Wyloguj
    </button>
  </div>

  <!-- ======================================================== -->
  <!-- INTEGRATIONS (tylko dla verified users)                    -->
  <!-- ======================================================== -->
  <div x-show="user.status === 'verified'" x-cloak>
    <div class="divider"></div>

    <div class="card">
      <h4 style="font-size: 14px; font-weight: 600; margin: 0 0 14px 0;">Integracje</h4>

      <!-- Loading state -->
      <div x-show="integrations.loading" style="text-align: center; padding: 16px 0; color: var(--color-text-muted); font-size: 13px;">
        Ładowanie...
      </div>

      <div x-show="!integrations.loading">

        <!-- ── Strava ── -->
        <div style="display: flex; align-items: center; gap: 12px; padding: 10px 0;">
          <!-- Icon -->
          <div style="width: 36px; height: 36px; border-radius: 8px; background: #FC5425; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white">
              <path d="M15.5 2.5L11 12h3.5L10 22l8-11h-4l4-8.5z"/>
            </svg>
          </div>
          <!-- Info -->
          <div style="flex: 1; min-width: 0;">
            <div style="font-size: 14px; font-weight: 600;">Strava</div>
            <div style="font-size: 12px; color: var(--color-text-muted);"
                 x-text="integrations.strava.linked
                    ? (integrations.strava.expired ? '⚠️ Token wygasł — podłącz ponownie' : '✓ Podłączony')
                    : 'Nie podłączony'">
            </div>
          </div>
          <!-- Nie linked → Connect -->
          <button x-show="!integrations.strava.linked"
                  @click="connectIntegration('strava')"
                  style="padding: 6px 14px; font-size: 13px; font-weight: 500; background: var(--color-primary); color: white; border: none; border-radius: 6px; cursor: pointer; white-space: nowrap;">
            Podłącz
          </button>
          <!-- Linked + świeży token → Unlink -->
          <button x-show="integrations.strava.linked && !integrations.strava.expired"
                  @click="unlinkIntegration('strava')"
                  style="padding: 6px 14px; font-size: 13px; background: rgba(229,62,62,0.08); color: #e53e3e; border: 1px solid rgba(229,62,62,0.2); border-radius: 6px; cursor: pointer; white-space: nowrap;">
            Odłączyć
          </button>
          <!-- Linked ale token wygasł → Reconnect -->
          <button x-show="integrations.strava.linked && integrations.strava.expired"
                  @click="connectIntegration('strava')"
                  style="padding: 6px 14px; font-size: 13px; font-weight: 500; background: #e53e3e; color: white; border: none; border-radius: 6px; cursor: pointer; white-space: nowrap;">
            Ponownie
          </button>
        </div>

        <!-- Separator -->
        <div style="border-top: 1px solid rgba(0,0,0,0.06); margin: 2px 0;"></div>

        <!-- ── Garmin (disabled – w przygotowaniu) ── -->
        <div style="display: flex; align-items: center; gap: 12px; padding: 10px 0; opacity: 0.4;">
          <!-- Icon -->
          <div style="width: 36px; height: 36px; border-radius: 8px; background: #003087; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>
          </div>
          <!-- Info -->
          <div style="flex: 1; min-width: 0;">
            <div style="font-size: 14px; font-weight: 600;">Garmin</div>
            <div style="font-size: 12px; color: var(--color-text-muted);">W przygotowaniu</div>
          </div>
          <!-- Badge -->
          <div style="padding: 5px 10px; font-size: 11px; color: var(--color-text-muted); border: 1px solid rgba(0,0,0,0.12); border-radius: 6px; white-space: nowrap;">
            Wkrótce
          </div>
        </div>

      </div>
    </div>
  </div>
  <!-- ======================================================== -->

  <!-- Settings sections -->
  <div class="divider"></div>
  
  <!-- Manage Panel Link (jeśli ma dostęp) -->
  <div x-show="user.has_manage_access" x-cloak>
    <div class="divider"></div>
    
    <div style="padding: 0 24px 24px;">
      <h4 style="font-size: 12px; font-weight: 500; color: var(--color-text-light); margin: 0 0 12px 0; letter-spacing: 0.05em; text-transform: uppercase;">
        Zarządzanie
      </h4>
      
        <a href="<?php print get_base_url(''); ?>/manage/challenges/" 
         style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: linear-gradient(135deg, var(--color-primary) 0%, #764ba2 100%); border-radius: var(--radius-md); text-decoration: none; color: white; transition: transform 0.2s;"
         @mouseenter="$el.style.transform='translateX(4px)'"
         @mouseleave="$el.style.transform='translateX(0)'">
        <div>
          <div style="font-size: 14px; font-weight: 600; margin-bottom: 4px;">
            Panel Zarządzania
          </div>
          <div style="font-size: 12px; opacity: 0.9;">
            Zarządzaj wyzwaniami i grupami
          </div>
        </div>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>
    </div>
  </div>
  
  <!-- App info -->
  <div class="card" style="background: rgba(0,0,0,0.02);">
    <div style="font-size: 12px; color: var(--color-text-muted); text-align: center;">
      <div>rideMore.bike</div>
      <div style="margin-top: 8px;">
        <a href="#" style="color: var(--color-primary);">Pomoc</a> · 
        <a href="#" style="color: var(--color-primary);">Regulamin</a>
      </div>
    </div>
  </div>
  
</div>