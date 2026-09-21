<?php
/* ============================================================================
   IMPORT MODAL – universal dla Strava / Garmin / każdego providera
   Sterowany przez: state.providerImport.provider
   ============================================================================ */
?>
<div x-show="providerImport.show"
     x-cloak
     @click.self="IntegrationsModule.closeProviderImport($data)"
     @keydown.escape.window="IntegrationsModule.closeProviderImport($data)"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal">

        <!-- HEADER -->
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <!-- Provider icon -->
                <div x-bind:style="`width:32px; height:32px; border-radius:8px; background:${IntegrationsModule.PROVIDERS[providerImport.provider]?.color || '#999'}; display:flex; align-items:center; justify-content:center; flex-shrink:0;`"
                     x-html="IntegrationsModule.PROVIDERS[providerImport.provider]?.icon || ''"></div>
                <div>
                    <h3 x-text="`Import z ${IntegrationsModule.PROVIDERS[providerImport.provider]?.label || providerImport.provider}`"></h3>
                    <p>Wybierz aktywności do pobrania</p>
                </div>
            </div>
            <button @click="IntegrationsModule.closeProviderImport($data)" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>

        <!-- BODY -->
        <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">

            <!-- Loading (pierwszy fetch) -->
            <div x-show="providerImport.loading && providerImport.activities.length === 0"
                 style="text-align: center; padding: 40px 0; color: var(--color-text-muted); font-size: 14px;">
                Ładowanie aktywności...
            </div>

            <!-- Error -->
            <div x-show="providerImport.error"
                 x-cloak
                 style="padding: 12px; background: #fee2e2; border: 1px solid #fecaca; border-radius: var(--radius-md); margin-bottom: 16px; color: #dc2626; display: flex; align-items: center; gap: 8px;">
                <img src="<?= asset('icons/circle-alert.svg') ?>" alt="" style="width: 16px; height: 16px;">
                <span x-text="providerImport.error"></span>
            </div>

            <!-- Import results -->
            <div x-show="providerImport.importResults"
                 style="padding: 12px; background: #dcfce7; border: 1px solid #bbf7d0; border-radius: var(--radius-md); margin-bottom: 16px;">
                <div style="color: #16a34a; font-size: 14px; font-weight: 600; margin-bottom: 4px;"
                     x-text="`✓ Zaimportowano ${providerImport.importResults?.imported ?? 0} aktywność(ów)`"></div>
                <div x-show="providerImport.importResults?.skipped > 0"
                     style="color: #64748b; font-size: 12px;"
                     x-text="`${providerImport.importResults?.skipped ?? 0} pominięto (już zaimportowane)`"></div>
                <div x-show="providerImport.importResults?.errors > 0"
                     style="color: #dc2626; font-size: 12px;"
                     x-text="`${providerImport.importResults?.errors ?? 0} błędów`"></div>
            </div>

            <!-- Grupowane aktywności -->
            <template x-for="group in IntegrationsModule.getGroupedActivities($data)" :key="group.date">
                <div style="margin-bottom: 16px;">

                    <!-- Date header -->
                    <div style="font-size: 12px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em; padding: 4px 0 8px;"
                         x-text="group.label"></div>

                    <!-- Activities -->
                    <template x-for="activity in group.activities" :key="activity.strava_id || activity.activity_id">
                        <div :style="activity.already_imported ? 'opacity: 0.4;' : ''"
                             style="display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-color, #e2e8f0);">

                            <!-- Checkbox -->
                            <input type="checkbox"
                                   :disabled="activity.already_imported"
                                   :checked="IntegrationsModule.isSelected($data, activity.strava_id || activity.activity_id)"
                                   @change="IntegrationsModule.toggleActivity($data, activity.strava_id || activity.activity_id)"
                                   style="width: 18px; height: 18px; accent-color: var(--color-primary); cursor: pointer; flex-shrink: 0;">

                            <!-- Info -->
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 14px; font-weight: 500; color: var(--color-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                     x-text="activity.name"></div>
                                <div style="font-size: 12px; color: var(--color-text-muted); margin-top: 2px;">
                                    <span x-text="`${activity.distance_km} km`"></span>
                                    <span style="margin: 0 6px;">·</span>
                                    <span x-text="`↑${activity.ascent_m}m`"></span>
                                    <span style="margin: 0 6px;">·</span>
                                    <span x-text="`${activity.moving_time_min} min`"></span>
                                </div>
                            </div>

                            <!-- Already imported badge -->
                            <div x-show="activity.already_imported"
                                 style="font-size: 11px; color: var(--color-text-muted); background: var(--bg-muted, #f1f5f9); padding: 3px 8px; border-radius: 4px; white-space: nowrap;">
                                Już import.
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Load more -->
            <div x-show="!providerImport.loading && providerImport.activities.length > 0 && providerImport.hasMore"
                 style="text-align: center; padding: 12px 0;">
                <button @click="IntegrationsModule.fetchActivities($data, providerImport.provider, providerImport.currentPage + 1)"
                        class="btn btn-secondary"
                        style="padding: 6px 16px; font-size: 13px;">
                    Załaduj więcej
                </button>
            </div>

            <!-- Loading more -->
            <div x-show="providerImport.loading && providerImport.activities.length > 0"
                 style="text-align: center; padding: 12px 0; color: var(--color-text-muted); font-size: 13px;">
                Ładowanie...
            </div>

        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
            <button @click="IntegrationsModule.closeProviderImport($data)"
                    :disabled="providerImport.importing"
                    class="btn btn-secondary">
                Anuluj
            </button>
            <button @click="IntegrationsModule.importSelected($data)"
                    :disabled="providerImport.selected.length === 0 || providerImport.importing"
                    class="btn btn-primary">
                <span x-show="!providerImport.importing"
                      x-text="`Import (${providerImport.selected.length} wybranych)`"></span>
                <span x-show="providerImport.importing" x-cloak>
                    <svg style="width: 16px; height: 16px; margin-right: 6px; animation: spin 1s linear infinite; display: inline-block; vertical-align: middle;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Importowanie...
                </span>
            </button>
        </div>

    </div>
</div>