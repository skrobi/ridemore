<?php
/* ============================================================================
   Library Panel - Advanced Search with Regions
   ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'library' }">
  
  <!-- HEADER -->
  <div class="panel-header">
    <h2>BIBLIOTEKA TRAS</h2>
    <div style="display: flex; gap: 8px; align-items: center;">
      <button class="btn btn-primary btn-sm" @click="showUploadModal = true">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        UPLOAD
      </button>
      <button class="close-btn" @click="closeAll()">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
  </div>
  
  <!-- SEARCH -->
  <div style="padding: 0 24px 16px;">
    <div class="search-input-wrapper">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"/>
        <path d="m21 21-4.35-4.35"/>
      </svg>
      <input type="text" 
             x-model="librarySearch" 
             @input="searchLibrary()" 
             placeholder="Szukaj tras po nazwie..." 
             class="form-input">
    </div>
  </div>
  
  <!-- REGION SELECTOR -->
<div style="padding: 0 24px 16px;">
  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 12px; background: rgba(139, 92, 246, 0.05); border: 1px solid rgba(139, 92, 246, 0.15); border-radius: var(--radius-md);">
    <input type="checkbox" 
           x-model="filters.useMapBbox" 
           @change="searchLibrary()"
           style="width: 16px; height: 16px; cursor: pointer; accent-color: var(--color-primary);">
    <div>
      <div style="font-size: 13px; font-weight: 500; color: var(--color-text);">
        🗺️ Szukaj w widocznym obszarze mapy
      </div>
      <div style="font-size: 11px; color: var(--color-text-light); margin-top: 2px;">
        Wyniki zostaną ograniczone do aktualnego widoku mapy
      </div>
    </div>
  </label>
</div>
  
  <!-- QUICK FILTERS -->
  <div style="padding: 0 24px 16px;">
    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
      <button 
        class="btn btn-sm"
        :class="filters.sort === 'rating' ? 'btn-primary' : 'btn-outline'"
        @click="filters.sort = 'rating'; searchLibrary()"
        style="font-size: 11px;">
        ⭐ Ocena
      </button>
      <button 
        class="btn btn-sm"
        :class="filters.sort === 'distance' ? 'btn-primary' : 'btn-outline'"
        @click="filters.sort = 'distance'; searchLibrary()"
        style="font-size: 11px;">
        📏 Dystans
      </button>
      <button 
        class="btn btn-sm"
        :class="filters.sort === 'newest' ? 'btn-primary' : 'btn-outline'"
        @click="filters.sort = 'newest'; searchLibrary()"
        style="font-size: 11px;">
        🆕 Najnowsze
      </button>
    </div>
  </div>
  
  <!-- ADVANCED FILTERS -->
  <div style="padding: 0 24px 16px;">
    <details>
      <summary style="cursor: pointer; font-size: 11px; font-weight: 500; color: var(--color-text); letter-spacing: 0.05em; text-transform: uppercase; padding: 8px 0;">
        Filtry zaawansowane
      </summary>
      
      <div style="padding: 12px 0; display: flex; flex-direction: column; gap: 12px;">
        
        <!-- Distance Range -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px; letter-spacing: 0.05em; text-transform: uppercase;">
            Dystans (km)
          </label>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
            <input type="number" x-model="filters.distance_min" placeholder="Min" class="form-input">
            <input type="number" x-model="filters.distance_max" placeholder="Max" class="form-input">
          </div>
        </div>
        
        <!-- Ascent Range -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px; letter-spacing: 0.05em; text-transform: uppercase;">
            Przewyższenie (m)
          </label>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
            <input type="number" x-model="filters.ascent_min" placeholder="Min" class="form-input">
            <input type="number" x-model="filters.ascent_max" placeholder="Max" class="form-input">
          </div>
        </div>
        
        <!-- Difficulty -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px; letter-spacing: 0.05em; text-transform: uppercase;">
            Trudność
          </label>
          <select x-model="filters.difficulty" class="form-input">
            <option value="">Wszystkie</option>
            <option value="easy">Łatwa</option>
            <option value="medium">Średnia</option>
            <option value="hard">Trudna</option>
            <option value="extreme">Ekstremalna</option>
          </select>
        </div>
        
        <!-- Route Type -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px; letter-spacing: 0.05em; text-transform: uppercase;">
            Typ trasy
          </label>
          <select x-model="filters.route_type" class="form-input">
            <option value="">Wszystkie</option>
            <option value="road">Szosa</option>
            <option value="gravel">Gravel</option>
            <option value="mtb">MTB</option>
            <option value="mixed">Mieszana</option>
          </select>
        </div>
        
        <!-- Min Rating -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px; letter-spacing: 0.05em; text-transform: uppercase;">
            Min. ocena
          </label>
          <select x-model="filters.min_rating" class="form-input">
            <option value="">Wszystkie</option>
            <option value="4.5">⭐⭐⭐⭐⭐ 4.5+</option>
            <option value="4.0">⭐⭐⭐⭐ 4.0+</option>
            <option value="3.5">⭐⭐⭐ 3.5+</option>
            <option value="3.0">⭐⭐⭐ 3.0+</option>
          </select>
        </div>
        
        <!-- Source -->
        <div>
          <label style="display: block; font-size: 11px; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px; letter-spacing: 0.05em; text-transform: uppercase;">
            Źródło
          </label>
          <select x-model="filters.source" class="form-input">
            <option value="">Wszystkie</option>
            <option value="admin_upload">Oficjalne</option>
            <option value="user_upload">Użytkowników</option>
          </select>
        </div>
        
        <!-- Action Buttons -->
        <div style="display: flex; gap: 8px; margin-top: 8px;">
          <button class="btn btn-primary" @click="applyFilters()" style="flex: 1; font-size: 12px;">
            Zastosuj
          </button>
          <button class="btn btn-outline" @click="resetFilters()" style="flex: 1; font-size: 12px;">
            Reset
          </button>
        </div>
        
      </div>
    </details>
  </div>
  
  <!-- RESULTS COUNT -->
  <div style="padding: 0 24px 12px;">
    <div style="font-size: 11px; color: var(--color-text-light);">
      Znaleziono: <strong x-text="libraryTotal"></strong> tras
      <span x-show="libraryRoutes.length < libraryTotal">
        (pokazuję <span x-text="libraryRoutes.length"></span>)
      </span>
    </div>
  </div>
  
  <!-- RESULTS -->
  <div style="padding: 0 24px 24px;">
    
    <!-- Loading -->
    <div x-show="loadingLibrary" x-cloak style="text-align: center; padding: 40px 0;">
      <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
        <line x1="12" y1="2" x2="12" y2="6"/>
        <line x1="12" y1="18" x2="12" y2="22"/>
      </svg>
    </div>
    
    <!-- Route Cards -->
    <div x-show="!loadingLibrary" x-cloak>
      <template x-for="route in libraryRoutes" :key="route.route_id">
    <div 
        @mouseenter="highlightLibraryRoute(route.route_id)"
        @mouseleave="unhighlightLibraryRoute(route.route_id)"
        @click="showLibraryRouteOnMap(route.route_id)"
        x-html="window.RouteCardRenderer.render(route, { 
            showDelete: false,
            showDifficulty: true, 
            showRating: true, 
            showBadges: true,
            compact: false 
        })"
    >
    </div>
</template>
      
      <!-- Load More Button -->
      <button 
        x-show="libraryRoutes.length < libraryTotal"
        @click="loadMore()"
        class="btn btn-outline btn-block"
        style="margin-top: 16px; font-size: 12px;"
        x-cloak>
        Załaduj więcej (<span x-text="libraryTotal - libraryRoutes.length"></span>)
      </button>
      
      <!-- Empty State -->
      <div class="empty-state" x-show="libraryRoutes.length === 0" x-cloak>
        <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/>
          <path d="m21 21-4.35-4.35"/>
        </svg>
        <div class="empty-state-title">Nie znaleziono tras</div>
        <p style="font-size: 13px; color: var(--color-text-light); margin-top: 8px;">
          Spróbuj zmienić filtry lub wybrać inny region
        </p>
      </div>
    </div>
  </div>
  
</div>

<!-- ============================================================
     UPLOAD MODAL - WITH ROUTE PURPOSE
     ============================================================ -->
<div x-show="showUploadModal" 
     x-cloak
     @click.self="showUploadModal = false"
     style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px;">
  
  <div @click.stop class="modal">
    
    <!-- HEADER -->
    <div class="modal-header">
      <h3>📤 DODAJ TRASĘ DO BIBLIOTEKI</h3>
      <button @click="showUploadModal = false" class="close-btn">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    
    <!-- BODY -->
    <div class="modal-body">
      
      <!-- File input -->
      <div style="margin-bottom: 20px;">
        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 8px; color: var(--color-text); letter-spacing: 0.02em;">
          📁 Plik GPX *
        </label>
        <input 
          type="file" 
          accept=".gpx"
          data-type="library"
          x-ref="libraryGpxInput"
          @change="handleUploadFileSelect($event)"
          class="form-input">
        <p style="font-size: 11px; color: var(--color-text-light); margin-top: 4px;">
          Maksymalnie 10MB • Format: GPX
        </p>
      </div>
      
      <!-- Name -->
      <div style="margin-bottom: 20px;">
        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 8px; color: var(--color-text); letter-spacing: 0.02em;">
          📝 Nazwa trasy *
        </label>
        <input type="text" 
               x-model="uploadData.name" 
               placeholder="np. Mazurska pętla"
               class="form-input">
      </div>
      
      <!-- Description -->
      <div style="margin-bottom: 20px;">
        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 8px; color: var(--color-text); letter-spacing: 0.02em;">
          📄 Opis (opcjonalnie)
        </label>
        <textarea x-model="uploadData.description" 
                  placeholder="Krótki opis trasy..."
                  class="form-input"
                  rows="3"></textarea>
      </div>
      
      <!-- Type & Difficulty Grid -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
        <div>
          <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 8px; color: var(--color-text); letter-spacing: 0.02em;">
            🚴 Typ trasy
          </label>
          <select x-model="uploadData.route_type" class="form-input">
            <option value="">-- Wybierz --</option>
            <option value="road">🚴 Szosa</option>
            <option value="gravel">🏞️ Gravel</option>
            <option value="mtb">⛰️ MTB</option>
            <option value="mixed">🔀 Mieszana</option>
          </select>
        </div>
        
        <div>
          <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 8px; color: var(--color-text); letter-spacing: 0.02em;">
            📊 Trudność
          </label>
          <select x-model="uploadData.difficulty" class="form-input">
            <option value="">-- Wybierz --</option>
            <option value="easy">🟢 Łatwa</option>
            <option value="medium">🟡 Średnia</option>
            <option value="hard">🔴 Trudna</option>
            <option value="extreme">⚫ Ekstremalna</option>
          </select>
        </div>
      </div>
      
      <!-- ✅ ROUTE PURPOSE (Admin only) -->
      <div 
           x-cloak
           style="margin-bottom: 20px; padding: 16px; background: rgba(139, 92, 246, 0.05); border: 1px solid rgba(139, 92, 246, 0.15); border-radius: var(--radius-md);">
        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 12px; color: var(--color-text); letter-spacing: 0.02em;">
          Typ trasy 
        </label>
        
        <div style="display: flex; flex-direction: column; gap: 12px;">
          
          <!-- User shared (default) -->
          <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; padding: 12px; border-radius: var(--radius-sm); transition: background 0.15s;"
                 :style="uploadData.route_purpose === 'user_shared' ? 'background: rgba(139, 92, 246, 0.1)' : 'background: transparent'"
                 @mouseenter="$el.style.background = 'rgba(0,0,0,0.02)'"
                 @mouseleave="$el.style.background = uploadData.route_purpose === 'user_shared' ? 'rgba(139, 92, 246, 0.1)' : 'transparent'">
            <input type="radio" 
                   name="route_purpose"
                   value="user_shared"
                   x-model="uploadData.route_purpose"
                   style="margin-top: 2px; accent-color: var(--color-primary);">
            <div>
              <div style="font-size: 13px; color: var(--color-text); font-weight: 500; margin-bottom: 4px;">
                Trasa użytkownika
              </div>
              <div style="font-size: 11px; color: var(--color-text-light); line-height: 1.4;">
                Publiczna trasa dodana przez użytkownika (community)
              </div>
            </div>
          </label>
          
          <!-- Reference (official) -->
          <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; padding: 12px; border-radius: var(--radius-sm); transition: background 0.15s;"
                 :style="uploadData.route_purpose === 'reference' ? 'background: rgba(139, 92, 246, 0.1)' : 'background: transparent'"
                 @mouseenter="$el.style.background = 'rgba(0,0,0,0.02)'"
                 @mouseleave="$el.style.background = uploadData.route_purpose === 'reference' ? 'rgba(139, 92, 246, 0.1)' : 'transparent'">
            <input type="radio" 
                   name="route_purpose"
                   value="reference"
                   x-model="uploadData.route_purpose"
                   style="margin-top: 2px; accent-color: var(--color-primary);">
            <div>
              <div style="font-size: 13px; color: var(--color-text); font-weight: 500; margin-bottom: 4px;">
                ⭐ Trasa referencyjna (oficjalna)
              </div>
              <div style="font-size: 11px; color: var(--color-text-light); line-height: 1.4;">
                Oficjalna trasa do śledzenia postępów w wyzwaniach
              </div>
            </div>
          </label>
        </div>
      </div>
      
      <!-- INFO BOX -->
      <div style="padding: 12px; background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.15); border-radius: var(--radius-md); margin-bottom: 20px;">
        <div style="display: flex; align-items: flex-start; gap: 8px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #3b82f6; flex-shrink: 0; margin-top: 1px;">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
          </svg>
          
        </div>
      </div>
      
      <!-- Error -->
      <div x-show="uploadError" 
           x-cloak
           style="padding: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-md); margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 8px; color: #dc2626; font-size: 13px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span x-text="uploadError"></span>
        </div>
      </div>
      
      <!-- Success -->
      <div x-show="uploadSuccess" 
           x-cloak
           style="padding: 12px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius-md); margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 8px; color: #059669; font-size: 13px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
          </svg>
          <span x-text="uploadSuccessMessage"></span>
        </div>
      </div>
    </div>
    
    <!-- FOOTER -->
    <div class="modal-footer">
      <button @click="showUploadModal = false; resetUploadForm()" class="btn btn-secondary">
        Anuluj
      </button>
      <button @click="uploadRoute()" 
              :disabled="uploadingRoute || !uploadData.file || !uploadData.name"
              class="btn btn-primary">
        <span x-show="!uploadingRoute">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="17 8 12 3 7 8"/>
            <line x1="12" y1="3" x2="12" y2="15"/>
          </svg>
          DODAJ DO BIBLIOTEKI
        </span>
        <span x-show="uploadingRoute" x-cloak>
          <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block;">
            <line x1="12" y1="2" x2="12" y2="6"/>
            <line x1="12" y1="18" x2="12" y2="22"/>
          </svg>
          Przetwarzanie...
        </span>
      </button>
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