<?php
/* ============================================================================
  EDIT ROUTE MODAL - v4.0 BEM + CSS Classes
  ============================================================================ */
?>
<div x-show="showEditModal" 
     x-cloak
     @click.self="closeEditModal()"
     @keydown.escape.window="closeEditModal()"
     class="edit-route-modal__overlay">

    <div @click.stop class="edit-route-modal__container">

        <!-- HEADER -->
        <div class="edit-route-modal__header">
            <div class="edit-route-modal__header-content">
                <h2 class="edit-route-modal__title">Edytuj trasę</h2>
                <p class="edit-route-modal__subtitle">
                    <span x-text="editData.name || 'Bez nazwy'"></span>
                    <span class="edit-route-modal__subtitle-separator">-</span>
                    <span x-text="editData.distance_km ? editData.distance_km + ' km' : ''"></span>
                </p>
            </div>
            <button @click="closeEditModal()" class="edit-route-modal__close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" class="icon-svg">
            </button>
        </div>

        <!-- BODY - 3 COLUMNS -->
        <div class="edit-route-modal__body">

            <!-- COLUMN 1: BASIC INFO -->
            <div class="edit-route-modal__column edit-route-modal__column--basic">
                <h3 class="edit-route-modal__column-title">
                    <img src="<?= asset('icons/edit.svg') ?>" alt="" class="edit-route-modal__column-icon">
                    Podstawowe informacje
                </h3>

                <!-- Name -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Nazwa trasy *</label>
                    <input type="text" 
                           x-model="editData.name" 
                           class="edit-route-modal__form-input"
                           placeholder="np. Korona Sudetów - Etap 1">
                </div>

                <!-- Tagline -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">
                        Tagline / Zachęta
                        <span class="edit-route-modal__form-hint">Krótka fraza zachęcająca (max 250 znaków)</span>
                    </label>
                    <textarea x-model="editData.tagline" 
                              class="edit-route-modal__form-textarea"
                              rows="2"
                              maxlength="250"
                              placeholder="Jeśli masz tylko jeden dzień w Sudetach..."></textarea>
                    <div class="edit-route-modal__char-counter">
                        <span x-text="(editData.tagline || '').length"></span> / 250
                    </div>
                </div>

                <!-- Description -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">
                        Opis
                        <span class="edit-route-modal__form-hint">Szczegółowy opis trasy, atrakcje, porady</span>
                    </label>
                    <textarea x-model="editData.description" 
                              class="edit-route-modal__form-textarea"
                              rows="5"
                              placeholder="Piękna trasa przez..."></textarea>
                </div>

                <!-- Route Type -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Typ trasy</label>
                    <select x-model="editData.route_type" class="edit-route-modal__form-select">
                        <option value="">-- Wybierz --</option>
                        <option value="road">Szosa</option>
                        <option value="gravel">Gravel</option>
                        <option value="mtb">MTB</option>
                        <option value="mixed">Mieszana</option>
                    </select>
                </div>

                <!-- Difficulty -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Trudność</label>
                    <select x-model="editData.difficulty_level" class="edit-route-modal__form-select">
                        <option value="">-- Wybierz --</option>
                        <option value="easy">Łatwa</option>
                        <option value="medium">Średnia</option>
                        <option value="hard">Trudna</option>
                        <option value="extreme">Ekstremalna</option>
                    </select>
                </div>

                <!-- Estimated Time -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">
                        Szacowany czas przejazdu
                        <span class="edit-route-modal__form-hint">W godzinach (np. 4.5)</span>
                    </label>
                    <input type="number" 
                           x-model="editData.estimated_time_hours" 
                           class="edit-route-modal__form-input"
                           step="0.5"
                           min="0"
                           max="24"
                           placeholder="np. 4.5">
                    <div class="edit-route-modal__time-display" x-show="editData.estimated_time_hours">
                        ≈ <span x-text="Math.floor(editData.estimated_time_hours)"></span>h 
                        <span x-text="Math.round((editData.estimated_time_hours % 1) * 60)"></span>min
                    </div>
                </div>

                <!-- Visibility -->
                <div class="edit-route-modal__form-group">
                    <label class="edit-route-modal__form-label">Widoczność</label>
                    <select x-model="editData.visibility" class="edit-route-modal__form-select">
                        <option value="private">Prywatna (tylko ja)</option>
                        <option value="public">Publiczna (wszyscy)</option>
                    </select>
                </div>

            </div>

            <!-- COLUMN 2: PHOTO GALLERY -->
            <div class="edit-route-modal__column edit-route-modal__column--photos">
                <h3 class="edit-route-modal__column-title">
                    <img src="<?= asset('icons/image.svg') ?>" alt="" class="edit-route-modal__column-icon">
                    Galeria zdjęć
                </h3>

                <!-- Upload Zone -->
                <div class="edit-route-modal__photo-upload"
                     @drop.prevent="handlePhotoDrop($event)"
                     @dragover.prevent
                     @dragenter="photoDropActive = true"
                     @dragleave="photoDropActive = false"
                     :class="{ 'edit-route-modal__photo-upload--drag-active': photoDropActive }"
                     @click="openPhotoUpload()">

                    <div x-show="!uploadingPhotos">
                        <svg class="edit-route-modal__photo-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <circle cx="8.5" cy="8.5" r="1.5"/>
                            <polyline points="21 15 16 10 5 21"/>
                        </svg>
                        <div class="edit-route-modal__photo-upload-title">
                            Kliknij lub przeciągnij zdjęcia
                        </div>
                        <div class="edit-route-modal__photo-upload-subtitle">
                            JPEG, PNG, WEBP • Max 10MB
                        </div>
                    </div>

                    <!-- Upload Progress -->
                    <div x-show="uploadingPhotos" x-cloak class="edit-route-modal__photo-upload-progress">
                        <svg class="edit-route-modal__photo-upload-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <div class="edit-route-modal__photo-upload-title">
                            Uploadowanie... <span x-text="photoUploadProgress"></span>%
                        </div>
                    </div>
                </div>

                <!--Photo Grid -->
<div x-show="editPhotos && editPhotos.length > 0" x-cloak>
<div class="edit-route-modal__photo-grid">
<template x-for="photo in editPhotos" :key="photo.photo_id">
<div class="edit-route-modal__photo-card" 
                              :class="{ 'edit-route-modal__photo-card--primary': photo.is_primary }">
                            <!-- Image -->
                            <img :src="photo.url" 
                                 :alt="photo.caption || 'Photo'"
                                 class="edit-route-modal__photo-img">

                            <!-- Primary Badge -->
                            <div x-show="photo.is_primary" 
                                 class="edit-route-modal__photo-badge edit-route-modal__photo-badge--primary">
                                <img src="<?= asset('icons/star.svg') ?>" alt="" class="edit-route-modal__photo-badge-icon">
                                GŁÓWNE
                            </div>

                            <!-- GPS Badge -->
                            <div x-show="photo.lat && photo.lon"
                                 class="edit-route-modal__photo-badge edit-route-modal__photo-badge--gps">
                                <img src="<?= asset('icons/map-pin.svg') ?>" alt="" class="edit-route-modal__photo-badge-icon">
                                GPS
                            </div>

                            <!-- Actions Overlay -->
                            <div class="edit-route-modal__photo-actions">
                                <button @click.stop="setPrimaryPhoto(photo.photo_id)"
                                        x-show="!photo.is_primary"
                                        class="edit-route-modal__photo-btn">
                                    <img src="<?= asset('icons/star.svg') ?>" alt="" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 2px;">
                                    Ustaw główne
                                </button>

                                <button @click.stop="deletePhoto(photo.photo_id)"
                                        class="edit-route-modal__photo-btn edit-route-modal__photo-btn--delete">
                                    <img src="<?= asset('icons/trash-2.svg') ?>" alt="" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle;">
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Caption Editor -->
                <div x-show="selectedPhotoForEdit" x-cloak class="edit-route-modal__caption-editor">
                    <label class="edit-route-modal__form-label">Podpis do zdjęcia</label>
                    <textarea x-model="selectedPhotoCaption" 
                              class="edit-route-modal__form-textarea" 
                              rows="2"
                              placeholder="Dodaj opis do tego zdjęcia..."></textarea>
                    <div class="edit-route-modal__caption-actions">
                        <button @click="savePhotoCaption()" class="edit-route-modal__btn edit-route-modal__btn--primary">Zapisz</button>
                        <button @click="selectedPhotoForEdit = null" class="edit-route-modal__btn edit-route-modal__btn--outline">Anuluj</button>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div x-show="!editPhotos || editPhotos.length === 0" x-cloak class="edit-route-modal__empty-state">
                <svg class="edit-route-modal__empty-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <p class="edit-route-modal__empty-title">Brak zdjęć</p>
                <p class="edit-route-modal__empty-subtitle">Dodaj pierwsze zdjęcie do tej trasy</p>
            </div>

        </div>

        <!-- COLUMN 3: ADVANCED -->
        <div class="edit-route-modal__column edit-route-modal__column--advanced">
            <h3 class="edit-route-modal__column-title">
                <img src="<?= asset('icons/settings.svg') ?>" alt="" class="edit-route-modal__column-icon">
                Zaawansowane
            </h3>

            <!-- Layer Assignment -->
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">
                    Warstwy mapy
                    <span class="edit-route-modal__form-hint">Wybierz warstwy, w których trasa się pojawi</span>
                </label>
                <div class="edit-route-modal__layer-list">
                    <template x-for="layer in availableLayers" :key="layer.layer_id">
                        <label class="edit-route-modal__layer-item"
                               :class="{ 'edit-route-modal__layer-item--checked': editData.layer_ids.includes(layer.layer_id) }">
                            <input type="checkbox" 
                                   :value="layer.layer_id"
                                   x-model="editData.layer_ids"
                                   class="edit-route-modal__layer-checkbox">
                            <span x-text="layer.name" class="edit-route-modal__layer-name"></span>
                        </label>
                    </template>
                </div>
                <div class="edit-route-modal__layer-count">
                    Zaznaczono: <span x-text="editData.layer_ids.length"></span>
                </div>
            </div>

            <!-- Road Types -->
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">
                    Typy nawierzchni
                    <span class="edit-route-modal__form-hint">Procentowy rozkład nawierzchni (0-100%)</span>
                </label>

                <div class="edit-route-modal__road-types">
                    <!-- Asfalt -->
                    <div class="edit-route-modal__road-type-row">
                        <span class="edit-route-modal__road-type-label">
                            <img src="<?= asset('icons/minus.svg') ?>" alt="" class="edit-route-modal__road-type-icon">
                            Asfalt
                        </span>
                        <input type="number" 
                               :value="roadTypes.asphalt_pct"
                               @input="roadTypes.asphalt_pct = parseInt($event.target.value) || 0"
                               min="0" max="100" step="1"
                               class="edit-route-modal__road-type-input"
                               placeholder="0">
                    </div>

                    <!-- Gravel -->
                    <div class="edit-route-modal__road-type-row">
                        <span class="edit-route-modal__road-type-label">
                            <img src="<?= asset('icons/circle.svg') ?>" alt="" class="edit-route-modal__road-type-icon">
                            Gravel/Szuter
                        </span>
                        <input type="number" 
                               :value="roadTypes.gravel_pct"
                               @input="roadTypes.gravel_pct = parseInt($event.target.value) || 0"
                               min="0" max="100" step="1"
                               class="edit-route-modal__road-type-input"
                               placeholder="0">
                    </div>

                    <!-- Trail -->
                    <div class="edit-route-modal__road-type-row">
                        <span class="edit-route-modal__road-type-label">
                            <img src="<?= asset('icons/triangle.svg') ?>" alt="" class="edit-route-modal__road-type-icon">
                            Trasa/SingleTrack
                        </span>
                        <input type="number" 
                               :value="roadTypes.trail_pct"
                               @input="roadTypes.trail_pct = parseInt($event.target.value) || 0"
                               min="0" max="100" step="1"
                               class="edit-route-modal__road-type-input"
                               placeholder="0">
                    </div>
                </div>

                <!-- Total check -->
                <div class="edit-route-modal__road-types-summary">
                    <div class="edit-route-modal__road-types-total"
                         x-data="{ sum: getRoadTypesSum() }"
                         x-init="$watch('roadTypes', () => sum = getRoadTypesSum())"
                         :class="{
                            'edit-route-modal__road-types-total--invalid': sum !== 100 && sum > 0,
                            'edit-route-modal__road-types-total--valid': sum === 100
                         }">
                        Suma: <span x-text="sum"></span>%
                        <span x-show="sum > 0 && sum !== 100">
                            (powinno być 100%)
                        </span>
                    </div>

                    <!-- Auto-normalize button -->
                    <button 
                        x-show="getRoadTypesSum() > 0 && getRoadTypesSum() !== 100"
                        @click.prevent="normalizeRoadTypes()"
                        type="button"
                        class="edit-route-modal__road-types-normalize">
                        Znormalizuj do 100%
                    </button>
                </div>
            </div>

            <!-- Stats (read-only) -->
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">Statystyki (tylko odczyt)</label>
                <div class="edit-route-modal__stats">
                    <div class="edit-route-modal__stat-row">
                        <span class="edit-route-modal__stat-label">Długość:</span>
                        <span class="edit-route-modal__stat-value" x-text="editData.distance_km + ' km'"></span>
                    </div>
                    <div class="edit-route-modal__stat-row">
                        <span class="edit-route-modal__stat-label">Przewyższenie:</span>
                        <span class="edit-route-modal__stat-value" x-text="editData.ascent_m + ' m'"></span>
                    </div>
                    <div class="edit-route-modal__stat-row">
                        <span class="edit-route-modal__stat-label">Segmentów:</span>
                        <span class="edit-route-modal__stat-value" x-text="editData.segment_count || 0"></span>
                    </div>
                    <div class="edit-route-modal__stat-row">
                        <span class="edit-route-modal__stat-label">Wyświetleń:</span>
                        <span class="edit-route-modal__stat-value" x-text="editData.views_count || 0"></span>
                    </div>
                </div>
            </div>

            <!-- GPX File Info -->
            <div class="edit-route-modal__form-group" x-show="editData.gpx_file_path">
                <label class="edit-route-modal__form-label">Plik GPX</label>
                <div class="edit-route-modal__gpx-info">
                    <div class="edit-route-modal__gpx-path" x-text="editData.gpx_file_path"></div>
                    <a :href="editData.gpx_file_path" 
                       download
                       class="edit-route-modal__gpx-download">
                        <img src="<?= asset('icons/download.svg') ?>" alt="" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 4px;">
                        Pobierz GPX
                    </a>
                </div>
            </div>

        </div>

    </div>

    <!-- FOOTER -->
    <div class="edit-route-modal__footer">

        <!-- Left: Error/Success -->
        <div class="edit-route-modal__footer-left">
            <div x-show="editError" 
                 x-cloak
                 class="edit-route-modal__alert edit-route-modal__alert--error">
                <img src="<?= asset('icons/circle-alert.svg') ?>" alt="" class="edit-route-modal__alert-icon">
                <span x-text="editError"></span>
            </div>

            <div x-show="editSuccess" 
                 x-cloak
                 class="edit-route-modal__alert edit-route-modal__alert--success">
                <img src="<?= asset('icons/circle-check.svg') ?>" alt="" class="edit-route-modal__alert-icon">
                Zapisano pomyślnie!
            </div>
        </div>

        <!-- Right: Actions -->
        <div class="edit-route-modal__footer-right">
            <button @click="closeEditModal()" class="edit-route-modal__btn edit-route-modal__btn--outline">
                Anuluj
            </button>
            <button @click="saveEdit()" 
                    :disabled="editingRoute || !editData.name"
                    class="edit-route-modal__btn edit-route-modal__btn--primary">
                <span x-show="!editingRoute">
                    <img src="<?= asset('icons/save.svg') ?>" alt="" class="svg-white edit-route-modal__btn-icon">
                    Zapisz zmiany
                </span>
                <span x-show="editingRoute" x-cloak>
                    <svg class="edit-route-modal__btn-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Zapisywanie...
                </span>
            </button>
        </div>
    </div>

</div>
</div>
