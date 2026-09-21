<?php
/* ============================================================================
   RATING MODAL - Unified wrapper v2.0
   ============================================================================ */
?>
<div x-show="showRatingModal" 
     x-cloak
     @click.self="showRatingModal = false"
     @keydown.escape.window="showRatingModal = false"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal">

        <!-- HEADER -->
        <div class="modal-header">
            <h3>Oceń trasę</h3>
            <p>Podziel się swoją opinią o tej trasie</p>
            <button @click="showRatingModal = false" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>

        <!-- BODY -->
        <div class="modal-body">
            
            <!-- Star Rating -->
            <div style="margin-bottom: 24px;">
                <label style="display: block; font-weight: 600; margin-bottom: 12px; text-align: center; font-size: 13px; color: var(--color-text);">
                    Twoja ocena
                </label>
                <div style="display: flex; justify-content: center; gap: 8px;">
                    <template x-for="star in [1,2,3,4,5]" :key="star">
                        <button @click="ratingModal.rating = star"
                                type="button"
                                style="background: none; border: none; cursor: pointer; padding: 4px; transition: all 0.2s;"
                                @mouseenter="$el.style.transform = 'scale(1.2)'"
                                @mouseleave="$el.style.transform = 'scale(1)'">
                            <img :src="star <= ratingModal.rating ? '<?= asset('icons/star.svg') ?>' : '<?= asset('icons/star.svg') ?>'" 
                                 alt=""
                                 style="width: 32px; height: 32px;"
                                 :style="star <= ratingModal.rating ? 'filter: invert(62%) sepia(98%) saturate(1613%) hue-rotate(1deg) brightness(102%) contrast(101%);' : 'filter: invert(85%) sepia(0%) saturate(0%) brightness(95%) contrast(90%);'">
                        </button>
                    </template>
                </div>
                <div x-show="ratingModal.rating > 0" 
                     x-text="['','Słabo','Średnio','Dobrze','Bardzo dobrze','Doskonale'][ratingModal.rating]"
                     style="text-align: center; margin-top: 8px; font-size: 14px; color: var(--color-text-muted);">
                </div>
            </div>

            <!-- Comment -->
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">Komentarz (opcjonalnie)</label>
                <textarea x-model="ratingModal.comment" 
                          class="edit-route-modal__form-textarea" 
                          rows="4"
                          maxlength="1000"
                          placeholder="Podziel się swoimi wrażeniami z trasy..."></textarea>
                <div class="edit-route-modal__form-hint">Max 1000 znaków</div>
            </div>

            <!-- Error -->
            <div x-show="ratingModal.error" 
                 x-cloak
                 style="padding: 12px; background: #fee2e2; border: 1px solid #fecaca; border-radius: var(--radius-md); margin-top: 16px; color: #dc2626; display: flex; align-items: center; gap: 8px;">
                <img src="<?= asset('icons/circle-alert.svg') ?>" alt="" style="width: 16px; height: 16px;">
                <span x-text="ratingModal.error"></span>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
            <button @click="showRatingModal = false" 
                    :disabled="ratingModal.submitting"
                    class="btn btn-secondary">
                Anuluj
            </button>
            <button @click="submitRating()" 
                    :disabled="ratingModal.submitting || ratingModal.rating === 0"
                    class="btn btn-primary">
                <span x-show="!ratingModal.submitting">
                    <img src="<?= asset('icons/send.svg') ?>" alt="" class="svg-white" style="width: 16px; height: 16px; margin-right: 6px;">
                    Wyślij ocenę
                </span>
                <span x-show="ratingModal.submitting" x-cloak>
                    <svg style="width: 16px; height: 16px; margin-right: 6px; animation: spin 1s linear infinite; display: inline-block; vertical-align: middle;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Wysyłanie...
                </span>
            </button>
        </div>

    </div>
</div>