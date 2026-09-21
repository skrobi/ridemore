<?php
/* ============================================================================
   MEDAL MODAL - Unified with MedalRenderer v3.0
   ============================================================================ */
?>
<div x-show="medalModal.show" 
     x-cloak
     @click.self="closeMedalModal()"
     @keydown.escape.window="closeMedalModal()"
     class="edit-route-modal__overlay"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100">

    <div @click.stop 
         class="modal"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="transform scale-95 opacity-0"
         x-transition:enter-end="transform scale-100 opacity-100">

        <!-- HEADER - Medal Card via Renderer -->
        <div class="modal-header" style="padding: 40px 24px 24px; border-bottom: 1px solid var(--color-border-light); position: relative;">
            
            <!-- Close button -->
            <button @click="closeMedalModal()" class="close-btn" style="position: absolute; top: 16px; right: 16px; z-index: 100; cursor: pointer;">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>
        <div class="modal-header" style="padding: 40px 24px 24px; border-bottom: 1px solid var(--color-border-light); position: relative; justify-content: center; align-items: center;">
            <!-- Medal centered wrapper -->
            <div style="display: flex; ">
                <div x-html="MedalRenderer.render(medalModal.badge || {}, { size: 'large' })"></div>
            </div>
        </div>

        <!-- BODY -->
        <div class="modal-body">

            <!-- Earned Info Bar -->
            <template x-if="medalModal.badge?.earned">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: rgba(16, 185, 129, 0.08); border-radius: var(--radius-md); margin-bottom: 20px; border: 1px solid rgba(16, 185, 129, 0.15);">
                    
                    <!-- Ranking -->
                    <div x-show="medalModal.badge?.ranking">
                        <div style="font-size: 10px; color: #059669; font-weight: 600; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.05em;">Ranking</div>
                        <div style="font-size: 18px; font-weight: 700; color: #10b981;">
                            #<span x-text="medalModal.badge?.ranking?.rank"></span>
                            <span style="font-size: 12px; font-weight: 400; color: var(--color-text-muted);">
                                z <span x-text="medalModal.badge?.ranking?.total_participants"></span>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Separator -->
                    <div x-show="medalModal.badge?.ranking" style="width: 1px; height: 32px; background: rgba(16, 185, 129, 0.2);"></div>
                    
                    <!-- Date Earned -->
                    <div style="text-align: right;">
                        <div style="font-size: 10px; color: #059669; font-weight: 600; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.05em;">Zdobyty</div>
                        <div style="font-size: 12px; font-weight: 600; color: var(--color-text);" 
                             x-text="medalModal.badge?.earned_at ? new Date(medalModal.badge.earned_at).toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'">
                        </div>
                    </div>
                </div>
            </template>

            <!-- Stats Grid -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px;">
                
                <!-- Rarity -->
                <div style="text-align: center; background: rgba(139, 92, 246, 0.1); border-radius: 12px; padding: 16px;">
                    <div style="font-size: 24px; font-weight: 700; color: #8b5cf6;" 
                         x-text="(medalModal.badge?.rarity_pct || 0).toFixed(1) + '%'"></div>
                    <div style="font-size: 10px; color: #8b5cf6; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">
                        Rzadkość
                    </div>
                </div>

                <!-- Tier Progress -->
                <div x-show="!medalModal.badge?.is_prestige"
                     style="text-align: center; background: rgba(59, 130, 246, 0.1); border-radius: 12px; padding: 16px;">
                    <div style="font-size: 24px; font-weight: 700; color: #3b82f6;">
                        <span x-text="medalModal.badge?.earned_tiers || 0"></span>/<span x-text="medalModal.badge?.total_tiers || 0"></span>
                    </div>
                    <div style="font-size: 10px; color: #3b82f6; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">
                        Zdobyte poziomy
                    </div>
                </div>
            </div>

            <!-- All Medal Tiers -->
            <div x-show="!medalModal.badge?.is_prestige && medalModal.badge?.all_tiers && medalModal.badge.all_tiers.length > 0" 
                 style="margin-bottom: 20px;">
                
                <h3 style="font-size: 12px; font-weight: 600; color: var(--color-text); margin: 0 0 16px 0; text-transform: uppercase; letter-spacing: 0.05em;">
                    Wszystkie poziomy
                </h3>

                <div class="medals" style="margin-left: 10px;">
                    <template x-for="tier in medalModal.badge?.all_tiers" :key="tier.level_id">
                        <div class="medal">
                            
                            <div class="dot" :style="`background: ${tier.earned ? tier.badge_color : '#d1d5db'}`">
                                <template x-if="tier.earned">
                                    <img src="<?= asset('icons/check.svg') ?>" alt="" 
                                         style="width: 14px; height: 14px; filter: brightness(0) invert(1);">
                                </template>
                                <template x-if="!tier.earned">
                                    <span x-text="tier.badge_icon"></span>
                                </template>
                            </div>

                            <div class="content">
                                <div class="name" 
                                     :style="`color: ${tier.earned ? 'var(--color-text)' : 'var(--color-text-muted)'}`"
                                     x-text="tier.name"></div>
                                <div class="range">
                                    <span x-text="Math.floor(tier.required_percent_min)"></span>% –
                                    <span x-text="Math.floor(tier.required_percent_max)"></span>%
                                </div>
                                <div class="price" 
                                     x-show="tier.has_physical" 
                                     :style="`color: ${tier.badge_color || '#888'}`">
                                    Medal fizyczny: <span x-text="tier.physical_price_pln"></span> zł
                                </div>
                                <div x-show="tier.earned" 
                                     style="margin-top: 4px; display: inline-block; background: rgba(16, 185, 129, 0.1); color: #059669; padding: 2px 8px; border-radius: 6px; font-size: 9px; font-weight: 600;">
                                    ✓ ZDOBYTY
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Challenge Info - CLICKABLE -->
            <div @click="closeMedalModal(); window.ChallengeVerify.openChallengeDetails(medalModal.badge?.challenge_id)" 
                 style="padding: 16px; background: rgba(0,0,0,0.02); border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s; margin-bottom: 20px;"
                 @mouseenter="$el.style.background = 'rgba(0,0,0,0.04)'"
                 @mouseleave="$el.style.background = 'rgba(0,0,0,0.02)'">

                <div style="font-size: 11px; font-weight: 600; color: var(--color-text-muted); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.05em;">
                    Kliknij aby zobaczyć wyzwanie
                </div>

                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="flex-shrink: 0; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: rgba(59, 130, 246, 0.1); border: 2px solid rgba(59, 130, 246, 0.2);">
                        <img src="<?= asset('icons/shield.svg') ?>" alt="" 
                             style="width: 24px; height: 24px; filter: invert(42%) sepia(98%) saturate(2679%) hue-rotate(201deg) brightness(102%) contrast(101%);">
                    </div>

                    <div style="flex: 1;">
                        <div style="font-size: 14px; font-weight: 600; color: var(--color-text);" 
                             x-text="medalModal.badge?.challenge_name"></div>
                        <div style="font-size: 11px; color: var(--color-text-muted);" 
                             x-text="medalModal.badge?.group_name"></div>
                    </div>

                    <img src="<?= asset('icons/chevron-right.svg') ?>" alt="" 
                         style="width: 20px; height: 20px; filter: invert(85%);">
                </div>

                <!-- Challenge Description -->
                <div x-show="medalModal.badge?.challenge_description" 
                     style="margin-top: 12px; padding: 12px; background: white; border-radius: 8px;">
                    <div style="font-size: 12px; color: var(--color-text); line-height: 1.5;" 
                         x-text="medalModal.badge?.challenge_description"></div>
                </div>

                <!-- Next Milestone -->
                <div x-show="!medalModal.badge?.is_prestige && medalModal.badge?.next_milestone" 
                     style="margin-top: 12px; padding: 12px; background: rgba(59, 130, 246, 0.05); border-radius: 8px; border-left: 3px solid #3b82f6;">
                    <div style="font-size: 10px; font-weight: 600; color: #3b82f6; margin-bottom: 4px; text-transform: uppercase;">
                        Następny poziom
                    </div>
                    <div style="font-size: 12px; color: var(--color-text);" 
                         x-text="medalModal.badge?.next_milestone?.message"></div>
                </div>

                <!-- Days Remaining Warning -->
                <template x-if="medalModal.badge?.days_remaining > 0 && medalModal.badge?.days_remaining <= 30">
                    <div style="margin-top: 12px; padding: 12px; border-radius: 8px; display: flex; align-items: center; gap: 8px;"
                         :style="medalModal.badge?.days_remaining <= 7 
                             ? 'background: rgba(239, 68, 68, 0.1); border-left: 3px solid #dc2626;' 
                             : 'background: rgba(251, 191, 36, 0.1); border-left: 3px solid #d97706;'">
                        <img src="<?= asset('icons/circle-alert') ?>" alt="" 
                             style="width: 16px; height: 16px;"
                             :style="medalModal.badge?.days_remaining <= 7 
                                 ? 'filter: invert(27%) sepia(98%) saturate(2679%) hue-rotate(346deg);' 
                                 : 'filter: invert(62%) sepia(98%) saturate(1613%) hue-rotate(1deg);'">
                        <div>
                            <div style="font-size: 10px; font-weight: 600; margin-bottom: 2px;" 
                                 :style="medalModal.badge?.days_remaining <= 7 ? 'color: #dc2626;' : 'color: #d97706;'">
                                KOŃCZY SIĘ
                            </div>
                            <div style="font-size: 12px; font-weight: 500; color: var(--color-text);" 
                                 x-text="`Zostało ${medalModal.badge?.days_remaining} dni`"></div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Toggle Showcase Button -->
            <template x-if="medalModal.badge?.earned">
                <button 
                    @click="toggleShowcase(medalModal.badge.level_id)" 
                    class="btn btn-primary btn-block">
                    <template x-if="!medalModal.badge.is_in_showcase">
                        <span>
                            <img src="<?= asset('icons/star.svg') ?>" alt="" 
                                 style="width: 16px; height: 16px; margin-right: 6px; filter: brightness(0) invert(1);">
                            Dodaj do gabloty
                        </span>
                    </template>
                    <template x-if="medalModal.badge.is_in_showcase">
                        <span>
                            <img src="<?= asset('icons/check.svg') ?>" alt="" 
                                 style="width: 16px; height: 16px; margin-right: 6px; filter: brightness(0) invert(1);">
                            W gablocie
                        </span>
                    </template>
                </button>
            </template>

        </div>

    </div>
</div>