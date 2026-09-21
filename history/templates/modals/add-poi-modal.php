<?php
/* ============================================================================
   ADD POI MODAL - Unified wrapper with mobile support
   ============================================================================ */
?>
<div x-show="addPoiModal.open" 
     x-cloak
     @click.self="addPoiModal.open = false"
     @keydown.escape.window="addPoiModal.open = false"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal" style="max-width: 600px; margin: 16px;">

        <!-- HEADER -->
        <div class="modal-header">
            <div>
                <h3 x-text="addPoiModal.mobileMode ? 'Dodaj nowe miejsce' : 'Dodaj nowy punkt POI'"></h3>
                <p x-text="`Współrzędne: ${addPoiModal.lat?.toFixed(6)}, ${addPoiModal.lon?.toFixed(6)}`" style="font-size: 13px; color: #666; margin: 4px 0 0 0;"></p>
            </div>
            <button @click="addPoiModal.open = false" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>

        <!-- BODY -->
        <div class="modal-body" style="max-height: 70vh; overflow-y: auto; padding: 16px 24px;">
            
            <!-- Error info (na górze jeśli jest błąd) -->
            <div x-show="addPoiModal.error" 
                 x-cloak
                 style="padding: 16px; background: #fee2e2; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 20px; color: #dc2626;">
                <div style="display: flex; align-items: start; gap: 12px;">
                    <img src="<?= asset('icons/circle-alert.svg') ?>" alt="" style="width: 20px; height: 20px; flex-shrink: 0; margin-top: 2px;">
                    <div style="flex: 1;">
                        <div style="font-weight: 600; margin-bottom: 4px;">Błąd lokalizacji</div>
                        <div style="font-size: 14px; line-height: 1.5;" x-text="addPoiModal.error"></div>
                        <div style="margin-top: 12px; font-size: 13px; color: #991b1b;">
                            💡 <strong>Jak włączyć lokalizację:</strong><br>
                            • Otwórz ustawienia telefonu<br>
                            • Przejdź do Lokalizacja / GPS<br>
                            • Włącz usługi lokalizacyjne<br>
                            • Odśwież stronę i spróbuj ponownie
                        </div>
                    </div>
                </div>
            </div>

            <!-- Zawartość formularza (ukryj jeśli błąd bez GPS) -->
            <div x-show="!addPoiModal.error || (addPoiModal.lat && addPoiModal.lon)">
            
            <!-- Mobile: Zdjęcie na górze (jeśli już jest) -->
            <div x-show="addPoiModal.mobileMode && addPoiModal.data.photo_preview" 
                 style="margin-bottom: 20px;">
                <img :src="addPoiModal.data.photo_preview" 
                     alt="Podgląd"
                     style="width: 100%; max-height: 200px; object-fit: cover; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            </div>

            <!-- Kategoria (FIRST on mobile) -->
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">Kategoria *</label>
                <select x-model="addPoiModal.data.category_id" 
                        @change="loadPoiSubtypes()"
                        class="edit-route-modal__form-input"
                        required>
                    <option value="">Wybierz kategorię</option>
                    <template x-for="cat in addPoiModal.categories" :key="cat.category_id">
                        <option :value="cat.category_id" x-text="cat.name"></option>
                    </template>
                </select>
            </div>

            <!-- Subkategoria -->
            <div class="edit-route-modal__form-group" x-show="addPoiModal.subtypes.length > 0">
                <label class="edit-route-modal__form-label">Typ</label>
                <select x-model="addPoiModal.data.subtype_id" 
                        class="edit-route-modal__form-input">
                    <option value="">Wybierz typ (opcjonalnie)</option>
                    <template x-for="subtype in addPoiModal.subtypes" :key="subtype.subtype_id">
                        <option :value="subtype.subtype_id" x-text="subtype.subtype_name"></option>
                    </template>
                </select>
            </div>
            
            <!-- Nazwa -->
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">Nazwa *</label>
                <input type="text" 
                       x-model="addPoiModal.data.name" 
                       class="edit-route-modal__form-input" 
                       placeholder="np. Punkt widokowy Trzy Korony"
                       required>
                <span class="edit-route-modal__form-hint" x-show="addPoiModal.mobileMode">Wypełniono automatycznie - możesz zmienić</span>
            </div>

            <!-- Opis -->
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">Opis</label>
                <textarea x-model="addPoiModal.data.description" 
                          class="edit-route-modal__form-input" 
                          rows="4"
                          placeholder="Krótki opis punktu..."></textarea>
            </div>

            <!-- Zdjęcie (DESKTOP ONLY - mobile już ma) -->
            <div class="edit-route-modal__form-group" x-show="!addPoiModal.mobileMode">
                <label class="edit-route-modal__form-label">Zdjęcie</label>
                
                <div x-show="!addPoiModal.data.photo_preview" 
                     @click="$refs.photoInput.click()"
                     style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 24px; text-align: center; cursor: pointer; transition: all 0.2s;"
                     @mouseenter="$el.style.borderColor = '#3b82f6'"
                     @mouseleave="$el.style.borderColor = '#cbd5e1'">
                    <img src="<?= asset('icons/image.svg') ?>" alt="" style="width: 48px; height: 48px; margin: 0 auto 12px; opacity: 0.5;">
                    <p style="margin: 0; color: #64748b; font-size: 14px;">Kliknij aby dodać zdjęcie</p>
                    <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 12px;">JPG, PNG max 10MB</p>
                </div>

                <div x-show="addPoiModal.data.photo_preview" 
                     style="position: relative; border-radius: 8px; overflow: hidden;">
                    <img :src="addPoiModal.data.photo_preview" 
                         alt="Podgląd"
                         style="width: 100%; max-height: 300px; object-fit: cover; border-radius: 8px;">
                    <button @click="removePoiPhoto()" 
                            type="button"
                            style="position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,0.7); color: white; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                        <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 16px; height: 16px; filter: invert(1);">
                    </button>
                </div>

                <input type="file" 
                       x-ref="photoInput"
                       @change="handlePoiPhotoSelect($event)"
                       accept="image/jpeg,image/png,image/jpg"
                       style="display: none;">
            </div>

            <!-- Dodatkowe pola (opcjonalne) -->
            <div x-show="addPoiModal.showExtraFields" style="margin-top: 16px;">
                
                <!-- URL -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Link (Wikipedia, strona)</label>
                    <input type="url" 
                           x-model="addPoiModal.data.url" 
                           class="edit-route-modal__form-input" 
                           placeholder="https://example.com">
                </div>

                <!-- Telefon -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Telefon</label>
                    <input type="tel" 
                           x-model="addPoiModal.data.phone" 
                           class="edit-route-modal__form-input" 
                           placeholder="+48 123 456 789">
                </div>

                <!-- Email -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Email</label>
                    <input type="email" 
                           x-model="addPoiModal.data.email" 
                           class="edit-route-modal__form-input" 
                           placeholder="kontakt@example.com">
                </div>

                <!-- Godziny otwarcia -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Godziny otwarcia</label>
                    <input type="text" 
                           x-model="addPoiModal.data.opening_hours" 
                           class="edit-route-modal__form-input" 
                           placeholder="Pn-Pt: 9:00-17:00">
                </div>

                <!-- Adres -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Adres</label>
                    <textarea x-model="addPoiModal.data.address" 
                              class="edit-route-modal__form-input" 
                              rows="2"
                              placeholder="ul. Przykładowa 123, 00-001 Warszawa"></textarea>
                </div>
            </div>

            <!-- Toggle dodatkowych pól -->
            <button @click="addPoiModal.showExtraFields = !addPoiModal.showExtraFields"
                    type="button"
                    style="width: 100%; padding: 8px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; font-size: 13px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 12px;">
                <img src="<?= asset('icons/plus.svg') ?>" alt="" style="width: 14px; height: 14px;">
                <span x-text="addPoiModal.showExtraFields ? 'Ukryj dodatkowe pola' : 'Pokaż dodatkowe pola'"></span>
            </button>

            </div>
            <!-- Koniec zawartości formularza -->

        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
            <button @click="addPoiModal.open = false" 
                    class="btn btn-secondary">
                <span x-text="addPoiModal.error && !addPoiModal.lat ? 'Zamknij' : 'Anuluj'"></span>
            </button>
            <button @click="submitNewPoi()" 
                    x-show="addPoiModal.lat && addPoiModal.lon"
                    :disabled="addPoiModal.submitting || !addPoiModal.data.name || !addPoiModal.data.category_id"
                    class="btn btn-primary">
                <span x-show="!addPoiModal.submitting">
                    <img src="<?= asset('icons/map-pin.svg') ?>" alt="" style="width: 16px; height: 16px; margin-right: 6px;">
                    Dodaj POI
                </span>
                <span x-show="addPoiModal.submitting" x-cloak>
                    <svg style="width: 16px; height: 16px; margin-right: 6px; animation: spin 1s linear infinite; display: inline-block; vertical-align: middle;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Dodawanie...
                </span>
            </button>
        </div>

    </div>
</div>