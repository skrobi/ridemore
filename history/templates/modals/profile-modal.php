<?php
/* ============================================================================
   PROFILE MODAL - User profile editor with tabs
   ============================================================================ */
?>
<div x-show="showProfileModal" 
     x-cloak
     @click.self="closeProfileModal()"
     @keydown.escape.window="showProfileModal && closeProfileModal()"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal" style="max-width: 800px; max-height: 90vh; display: flex; flex-direction: column;">

        <!-- HEADER -->
        <div class="modal-header">
            <h3>Mój profil</h3>
            <button @click="closeProfileModal()" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>

        <!-- BODY WITH TABS -->
        <div style="display: flex; flex: 1; overflow: hidden;">
            
            <!-- LEFT SIDEBAR - TABS -->
            <div style="width: 200px; border-right: 1px solid rgba(0,0,0,0.08); padding: 16px; flex-shrink: 0;">
                <button @click="switchProfileTab('personal')"
                        :class="profileActiveTab === 'personal' ? 'bg-gray-100' : ''"
                        style="width: 100%; text-align: left; padding: 10px 12px; border-radius: 6px; font-size: 14px; margin-bottom: 4px; border: none; background: transparent; cursor: pointer; transition: background 0.2s;">
                    <div style="font-weight: 500;">Dane osobowe</div>
                    <div style="font-size: 12px; color: #666; margin-top: 2px;">Imię, adres</div>
                </button>

                <button @click="switchProfileTab('account')"
                        :class="profileActiveTab === 'account' ? 'bg-gray-100' : ''"
                        style="width: 100%; text-align: left; padding: 10px 12px; border-radius: 6px; font-size: 14px; margin-bottom: 4px; border: none; background: transparent; cursor: pointer; transition: background 0.2s;">
                    <div style="font-weight: 500;">Dane konta</div>
                    <div style="font-size: 12px; color: #666; margin-top: 2px;">API, email</div>
                </button>

                <button @click="switchProfileTab('settings')"
                        :class="profileActiveTab === 'settings' ? 'bg-gray-100' : ''"
                        style="width: 100%; text-align: left; padding: 10px 12px; border-radius: 6px; font-size: 14px; border: none; background: transparent; cursor: pointer; transition: background 0.2s;">
                    <div style="font-weight: 500;">Ustawienia</div>
                    <div style="font-size: 12px; color: #666; margin-top: 2px;">Mapa, jednostki</div>
                </button>
            </div>

            <!-- RIGHT CONTENT -->
            <div style="flex: 1; padding: 24px; overflow-y: auto;">

                <!-- Loading state -->
                <div x-show="profileLoading" style="text-align: center; padding: 40px; color: #666;">
                    Ładowanie...
                </div>

                <!-- Error message -->
                <div x-show="profileError" 
                     x-cloak
                     style="background: #fee; border: 1px solid #fcc; color: #c33; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 14px;">
                    <span x-text="profileError"></span>
                </div>

                <!-- Success message -->
                <div x-show="profileSuccess" 
                     x-cloak
                     style="background: #efe; border: 1px solid #cfc; color: #3a3; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 14px;">
                    Zapisano zmiany!
                </div>

                <!-- TAB: Dane osobowe -->
                <div x-show="profileActiveTab === 'personal' && !profileLoading" x-cloak>
                    <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 20px 0;">Dane osobowe</h4>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Nazwa użytkownika</label>
                        <input type="text" 
                               x-model="profileData.username" 
                               class="edit-route-modal__form-input" 
                               placeholder="jan_kowalski"
                               pattern="[a-zA-Z0-9_]{3,20}"
                               maxlength="20">
                        <span class="edit-route-modal__form-hint">3-20 znaków: litery, cyfry, podkreślnik</span>
                    </div>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Imię</label>
                        <input type="text" 
                               x-model="profileData.first_name" 
                               class="edit-route-modal__form-input" 
                               placeholder="Jan">
                    </div>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Nazwisko</label>
                        <input type="text" 
                               x-model="profileData.last_name" 
                               class="edit-route-modal__form-input" 
                               placeholder="Kowalski">
                    </div>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Telefon</label>
                        <input type="tel" 
                               x-model="profileData.phone" 
                               class="edit-route-modal__form-input" 
                               placeholder="+48 123 456 789">
                        <span class="edit-route-modal__form-hint">Opcjonalnie</span>
                    </div>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Adres korespondencyjny</label>
                        <textarea x-model="profileData.shipping_address" 
                                  class="edit-route-modal__form-input" 
                                  rows="4"
                                  placeholder="ul. Przykładowa 123&#10;00-000 Warszawa&#10;Polska"></textarea>
                        <span class="edit-route-modal__form-hint">Wymagany do zamawiania medali</span>
                    </div>

                    <button @click="updateProfile('personal')" 
                            :disabled="profileSaving"
                            class="btn btn-primary"
                            style="width: 100%;">
                        <span x-show="!profileSaving">Zapisz dane osobowe</span>
                        <span x-show="profileSaving" x-cloak>Zapisywanie...</span>
                    </button>
                </div>

                <!-- TAB: Dane konta -->
                <div x-show="profileActiveTab === 'account' && !profileLoading" x-cloak>
                    <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 20px 0;">Dane konta</h4>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Email</label>
                        <input type="email" 
                               :value="user.email || 'Nie podano'" 
                               class="edit-route-modal__form-input" 
                               disabled
                               style="background: #f5f5f5; cursor: not-allowed;">
                        <span class="edit-route-modal__form-hint">Email można zmienić kontaktując się z supportem</span>
                    </div>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Klucz API</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" 
                                   :value="profileData.api_key" 
                                   class="edit-route-modal__form-input" 
                                   readonly
                                   style="flex: 1; font-family: monospace; font-size: 13px;">
                            <button @click="copyApiKey()" 
                                    class="btn btn-secondary"
                                    style="white-space: nowrap;">
                                Kopiuj
                            </button>
                        </div>
                        <span class="edit-route-modal__form-hint">Użyj tego klucza do autoryzacji w API</span>
                    </div>

                    <button @click="regenerateApiKey()" 
                            :disabled="profileSaving"
                            class="btn btn-outline"
                            style="width: 100%;">
                        Wygeneruj nowy klucz API
                    </button>
                </div>

                <!-- TAB: Ustawienia -->
                <div x-show="profileActiveTab === 'settings' && !profileLoading" x-cloak>
                    <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 20px 0;">Ustawienia aplikacji</h4>

                    <div class="edit-route-modal__form-group">
                        <label style="display: flex; align-items: center; justify-content: space-between; cursor: pointer;">
                            <div>
                                <div style="font-weight: 500; margin-bottom: 4px;">Automatyczne centrum mapy</div>
                                <div style="font-size: 13px; color: #666;">Mapa automatycznie centruje się na trasie</div>
                            </div>
                            <input type="checkbox" x-model="settings.autoCenter" @change="saveSettings()">
                        </label>
                    </div>

                    <div class="edit-route-modal__form-group">
                        <label style="display: flex; align-items: center; justify-content: space-between; cursor: pointer;">
                            <div>
                                <div style="font-weight: 500; margin-bottom: 4px;">Pokaż wszystkie warstwy</div>
                                <div style="font-size: 13px; color: #666;">Wyświetl wszystkie warstwy tras na mapie</div>
                            </div>
                            <input type="checkbox" x-model="settings.showAllLayers" @change="toggleMapLayers()">
                        </label>
                    </div>

                    <div class="edit-route-modal__form-group">
                        <label class="edit-route-modal__form-label">Jednostki</label>
                        <select x-model="settings.units" 
                                @change="saveSettings()"
                                class="edit-route-modal__form-input">
                            <option value="metric">Metryczne (km, m)</option>
                            <option value="imperial">Imperialne (mi, ft)</option>
                        </select>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>