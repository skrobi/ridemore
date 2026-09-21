<?php
/* ============================================================================
   TRACKING CONFIG MODAL - 4 stany: brak sesji / aktywna / zakończona / błędne wyzwanie
   ============================================================================ */
?>
<div x-show="trackingConfigModal.show" 
     x-cloak
     @click.self="closeTrackingConfigModal()"
     @keydown.escape.window="closeTrackingConfigModal()"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal">

        <!-- HEADER -->
        <div class="modal-header">
            <div>
                <h3>Tracking GPS</h3>
                <p>Nagrywaj swoją trasę w czasie rzeczywistym</p>
            </div>
            <button @click="closeTrackingConfigModal()" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>

        <!-- BODY -->
        <div class="modal-body">

            <!-- STAN 1: Brak sesji - przycisk START -->
            <template x-if="!trackingConfigModal.batch_id">
                <div class="edit-route-modal__form-group">
                    <p style="margin-bottom: 16px; color: #6b7280; font-size: 14px;">
                        Kliknij poniżej aby utworzyć sesję trackingu GPS dla tego wyzwania.
                    </p>
                    <button 
                        @click="createTrackingSession(selectedChallenge?.challenge_id)"
                        class="btn btn-primary btn-block btn-lg"
                        :disabled="trackingLoading">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="16"/>
                            <line x1="8" y1="12" x2="16" y2="12"/>
                        </svg>
                        <span x-text="trackingLoading ? 'Tworzenie...' : 'ROZPOCZNIJ TRACKING'"></span>
                    </button>
                </div>
            </template>

            <!-- STAN 2, 3 & 4: Sesja istnieje -->
            <template x-if="trackingConfigModal.batch_id">
                <div>
                    
                    <!-- ⚠️ STAN 4: OSTRZEŻENIE - Tracking dla innego wyzwania -->
                    <template x-if="trackingConfigModal.wrong_challenge">
                        <div class="edit-route-modal__form-group">
                            <div style="padding: 16px; background: #fef2f2; border: 2px solid #ef4444; border-radius: 8px; margin-bottom: 20px;">
                                <div style="display: flex; gap: 12px; align-items: flex-start;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #dc2626; flex-shrink: 0; margin-top: 2px;">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                    </svg>
                                    <div style="flex: 1;">
                                        <strong style="display: block; color: #991b1b; margin-bottom: 8px; font-size: 15px;">
                                            ⚠️ Masz już aktywny tracking dla innego wyzwania!
                                        </strong>
                                        <p style="margin: 0 0 12px 0; color: #7f1d1d; font-size: 14px; line-height: 1.5;">
                                            Aktualnie nagrywasz trasę dla: 
                                            <strong x-text="trackingConfigModal.challenge_name || 'Wyzwanie #' + trackingConfigModal.current_race_id"></strong>
                                        </p>
                                        <p style="margin: 0; color: #7f1d1d; font-size: 13px;">
                                            Nie możesz mieć dwóch aktywnych sesji jednocześnie. 
                                            Zakończ poprzedni tracking przed rozpoczęciem nowego.
                                        </p>
                                    </div>
                                </div>
                                
                                <!-- Info o batch_id -->
                                <div style="margin-top: 12px; padding: 8px; background: white; border-radius: 4px;">
                                    <span style="font-size: 12px; color: #6b7280;">
                                        Batch ID: <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 11px;" x-text="trackingConfigModal.batch_id"></code>
                                    </span>
                                </div>
                                
                                <!-- Przycisk: Zakończ poprzedni tracking -->
                                <button 
                                    @click="stopGPSTracking()"
                                    class="btn btn-block"
                                    style="background: #dc2626; border-color: #dc2626; color: white; margin-top: 12px;"
                                    :disabled="trackingLoading">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                                    </svg>
                                    <span x-text="trackingLoading ? 'Zatrzymywanie...' : 'ZAKOŃCZ POPRZEDNI TRACKING'"></span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- STAN 2 & 3: Sesja dla właściwego wyzwania -->
                    <template x-if="!trackingConfigModal.wrong_challenge">
                        <div>
                            <!-- Info o sesji - kolor zależy od stanu -->
                            <div class="edit-route-modal__form-group">
                                <div :style="{
                                    padding: '12px',
                                    background: trackingConfigModal.is_active ? '#f0fdf4' : '#f3f4f6',
                                    border: trackingConfigModal.is_active ? '1px solid #86efac' : '1px solid #d1d5db',
                                    borderRadius: '8px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '12px'
                                }">
                                    <span style="font-size: 24px;" x-text="trackingConfigModal.is_active ? '✅' : 'ℹ️'"></span>
                                    <div>
                                        <strong :style="{
                                            display: 'block',
                                            color: trackingConfigModal.is_active ? '#166534' : '#6b7280',
                                            marginBottom: '4px'
                                        }" x-text="trackingConfigModal.is_active ? 'Sesja aktywna' : 'Sesja zakończona'"></strong>
                                        <span :style="{
                                            fontSize: '13px',
                                            color: trackingConfigModal.is_active ? '#15803d' : '#9ca3af'
                                        }">
                                            ID: <code style="background: white; padding: 2px 6px; border-radius: 4px; font-size: 11px;" x-text="trackingConfigModal.batch_id"></code>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- STAN 2: Przyciski - tylko dla aktywnej sesji -->
                            <template x-if="trackingConfigModal.is_active">
                                <div>
                                    <!-- Przycisk: Otwórz OwnTracks -->
                                    <div class="edit-route-modal__form-group">
                                        <button 
                                            @click="openTrackingDeepLink()"
                                            class="btn btn-primary btn-block btn-lg">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                                <polyline points="15 3 21 3 21 9"/>
                                                <line x1="10" y1="14" x2="21" y2="3"/>
                                            </svg>
                                            OTWÓRZ OWNTRACKS
                                        </button>
                                        <span class="edit-route-modal__form-hint">Konfiguruje aplikację i rozpoczyna nagrywanie</span>
                                    </div>

                                    <div class="divider" style="margin: 20px 0;"></div>

                                    <!-- Instrukcja ręczna -->
                                    <div class="edit-route-modal__form-group">
                                        <label class="edit-route-modal__form-label">Nie działa automatycznie?</label>
                                        <button @click="copyTrackingConfig()" class="btn btn-secondary btn-block">
                                            📋 Kopiuj konfigurację JSON
                                        </button>
                                        <span class="edit-route-modal__form-hint">
                                            Wklej w OwnTracks: Settings → Configuration Management → Import
                                        </span>
                                    </div>

                                    <div class="divider" style="margin: 20px 0;"></div>

                                    <!-- Przycisk: Zakończ tracking -->
                                    <div class="edit-route-modal__form-group">
                                        <button 
                                            @click="stopGPSTracking()"
                                            class="btn btn-block"
                                            style="background: #dc2626; border-color: #dc2626; color: white;"
                                            :disabled="trackingLoading">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="6" y="6" width="12" height="12" rx="2"/>
                                            </svg>
                                            <span x-text="trackingLoading ? 'Zatrzymywanie...' : 'ZAKOŃCZ TRACKING'"></span>
                                        </button>
                                        <span class="edit-route-modal__form-hint" style="color: #dc2626;">
                                            ⚠️ Kończy nagrywanie i zapisuje trasę
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <!-- STAN 3: Komunikat dla zakończonej sesji -->
                            <template x-if="!trackingConfigModal.is_active">
                                <div class="edit-route-modal__form-group">
                                    <div style="padding: 12px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; display: flex; gap: 8px; align-items: flex-start;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #f59e0b; flex-shrink: 0; margin-top: 2px;">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="16" x2="12" y2="12"/>
                                            <line x1="12" y1="8" x2="12.01" y2="8"/>
                                        </svg>
                                        <span style="font-size: 13px; color: #78350f; line-height: 1.5;">
                                            <strong style="color: #92400e;">Tracking zakończony.</strong> Twoja trasa zostanie przetworzona za kilka minut i pojawi się w zakładce "Moje trasy".
                                        </span>
                                    </div>
                                </div>
                            </template>

                        </div>
                    </template>

                </div>
            </template>

        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
            <button @click="closeTrackingConfigModal()" class="btn btn-secondary">
                Zamknij
            </button>
        </div>

    </div>
</div>