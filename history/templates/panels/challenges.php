<?php
/* ============================================================================
  CHALLENGES PANEL - 3 TABS VERSION
  W TRAKCIE | UKOŃCZONE | DOSTĘPNE
  ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'challenges' }">

    <!-- HEADER -->
   <div style="padding: 24px 24px 16px; border-bottom: 1px solid var(--color-border-light);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
      <h1 style="font-size: 20px; font-weight: 300; letter-spacing: 0.02em; margin: 0; color: var(--color-text); display: flex; align-items: center; gap: 12px;">
        <img src="<?= asset('icons/target.svg') ?>" alt="" class="icon-svg">
        WYZWANIA
      </h1>
      <button class="close-btn" @click="closeAll()" style="background: transparent; border: none; cursor: pointer; padding: 8px; color: var(--color-text-muted); transition: color 0.2s;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div style="height: 4px; width: 60px; background: linear-gradient(90deg, #11c4f2 0%, #054b5d 100%); border-radius: 2px; opacity: 0.6;"></div>
  </div>

    <!-- TABS: W trakcie / Ukończone / Dostępne -->
    <div style="padding: 0 24px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0; border-bottom: 1px solid var(--color-border-light); margin-bottom: 16px; padding: 12px 0px">
            
            <!-- W TRAKCIE -->
            <button 
                @click="activeChallengeTab = 'started'"
                style="padding: 12px 8px; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: 12px; font-weight: 500; letter-spacing: 0.02em; transition: all 0.2s; text-align: center;"
                :style="activeChallengeTab === 'started' ? 'border-bottom-color: var(--color-primary); color: var(--color-primary);' : 'color: var(--color-text-light);'">
                W TRAKCIE
                <span x-show="userChallenges.filter(c => !c.completed).length > 0" 
                      style="display: block; margin-top: 2px; font-size: 10px; padding: 2px 6px; border-radius: 10px;"
                      :style="activeChallengeTab === 'started' ? 'background: var(--color-primary); color: white;' : 'background: var(--color-border); color: var(--color-text-muted);'"
                      x-text="userChallenges.filter(c => !c.completed).length"></span>
            </button>

            <!-- UKOŃCZONE -->
            <button 
                @click="activeChallengeTab = 'completed'"
                style="padding: 12px 8px; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: 12px; font-weight: 500; letter-spacing: 0.02em; transition: all 0.2s; text-align: center;"
                :style="activeChallengeTab === 'completed' ? 'border-bottom-color: var(--color-primary); color: var(--color-primary);' : 'color: var(--color-text-light);'">
                UKOŃCZONE
                <span x-show="userChallenges.filter(c => c.completed).length > 0" 
                      style="display: block; margin-top: 2px; font-size: 10px; padding: 2px 6px; border-radius: 10px;"
                      :style="activeChallengeTab === 'completed' ? 'background: var(--color-primary); color: white;' : 'background: var(--color-border); color: var(--color-text-muted);'"
                      x-text="userChallenges.filter(c => c.completed).length"></span>
            </button>

            <!-- DOSTĘPNE -->
            <button 
                @click="activeChallengeTab = 'available'"
                style="padding: 12px 8px; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: 12px; font-weight: 500; letter-spacing: 0.02em; transition: all 0.2s; text-align: center;"
                :style="activeChallengeTab === 'available' ? 'border-bottom-color: var(--color-primary); color: var(--color-primary);' : 'color: var(--color-text-light);'">
                DOSTĘPNE
            </button>
        </div>
    </div>

    <!-- ========================================================================
         TAB 1: W TRAKCIE (started && !completed)
         ======================================================================== -->
    <div x-show="activeChallengeTab === 'started'" x-cloak style="padding: 0 24px 24px;">

        <!-- Loading -->
        <div x-show="loadingChallenges" style="text-align: center; padding: 40px 0;">
            <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
                <line x1="12" y1="2" x2="12" y2="6"/>
                <line x1="12" y1="18" x2="12" y2="22"/>
            </svg>
        </div>

        <!-- Lista -->
        <div x-show="!loadingChallenges" x-cloak>
            <template x-for="challenge in userChallenges.filter(c => !c.completed)" :key="challenge.challenge_id">
                <div style="background: rgba(245, 245, 247, 0.5); border: 1px solid var(--color-border-light); border-radius: var(--radius-lg); padding: 16px; margin-bottom: 12px; transition: all 0.2s;">

                    <!-- Header -->
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <div class="challenge-card-icon-wrapper">
                            <template x-if="challenge.image_url">
                                <img 
                                    :src="challenge.image_url" 
                                    :alt="challenge.name"
                                    class="challenge-card-icon-image"
                                    @error="$event.target.style.display='none';">
                            </template>
                            <div 
                                class="challenge-card-icon-emoji" 
                                x-text="challenge.icon"
                                x-show="!challenge.image_url">
                            </div>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="font-size: 15px; font-weight: 600; margin: 0 0 4px 0;" x-text="challenge.name"></h4>
                            <div style="font-size: 11px; color: var(--color-text-light);" x-text="challenge.group_name"></div>
                        </div>
                    </div>

                    <!-- Progress -->
                    <div style="margin-bottom: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 11px; font-weight: 500; color: var(--color-text); letter-spacing: 0.05em; text-transform: uppercase;">Postęp</span>
                            <span style="font-size: 18px; font-weight: 300;" x-text="challenge.progress_pct.toFixed(0) + '%'"></span>
                        </div>
                        <div style="height: 6px; background: var(--color-border-light); border-radius: 3px; overflow: hidden;">
                            <div :style="`width: ${challenge.progress_pct}%; background: var(--gradient-easy); height: 100%; transition: width 0.5s ease;`"></div>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div style="display: flex; gap: 16px; font-size: 12px; color: var(--color-text-muted); margin-bottom: 12px;">
                        <span><span x-text="challenge.total_routes"></span> tras</span>
                        <span x-show="challenge.total_distance_km">
                            <strong x-text="challenge.total_distance_km"></strong> km
                        </span>
                    </div>

                    <!-- Buttons -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button 
                            @click="openChallengeDetails(challenge.challenge_id)"
                            class="btn btn-secondary"
                            style="font-size: 13px;">
                            SZCZEGÓŁY
                        </button>
                        <button 
                            @click="viewChallengeRoutes(challenge.challenge_id)"
                            class="btn btn-primary"
                            style="font-size: 13px;">
                            TRASY
                        </button>
                    </div>
                </div>
            </template>

            <!-- Empty state -->
            <div x-show="userChallenges.filter(c => !c.completed).length === 0" class="empty-state">
                <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <circle cx="12" cy="12" r="6"/>
                    <circle cx="12" cy="12" r="2"/>
                </svg>
                <div class="empty-state-title">Brak wyzwań w trakcie</div>
                <p style="font-size: 13px; color: var(--color-text-light); margin-top: 8px;">
                    Przejdź do zakładki "Dostępne"
                </p>
            </div>
        </div>
    </div>

    <!-- ========================================================================
         TAB 2: UKOŃCZONE (completed)
         ======================================================================== -->
    <div x-show="activeChallengeTab === 'completed'" x-cloak style="padding: 0 24px 24px;">

        <!-- Loading -->
        <div x-show="loadingChallenges" style="text-align: center; padding: 40px 0;">
            <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
                <line x1="12" y1="2" x2="12" y2="6"/>
                <line x1="12" y1="18" x2="12" y2="22"/>
            </svg>
        </div>

        <!-- Lista -->
        <div x-show="!loadingChallenges" x-cloak>
            <template x-for="challenge in userChallenges.filter(c => c.completed)" :key="challenge.challenge_id">
                <div style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.05) 0%, rgba(245, 158, 11, 0.02) 100%); border: 1px solid rgba(251, 191, 36, 0.2); border-radius: var(--radius-lg); padding: 16px; margin-bottom: 12px; position: relative; overflow: hidden;">

                    <!-- Completed badge -->
                    <div style="position: absolute; top: -20px; right: -20px; width: 80px; height: 80px; background: radial-gradient(circle, rgba(251, 191, 36, 0.15), transparent); border-radius: 50%;"></div>

                    <!-- Header -->
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px; position: relative; z-index: 1;">
                        <div class="challenge-card-icon-wrapper">
                            <template x-if="challenge.image_url">
                                <img 
                                    :src="challenge.image_url" 
                                    :alt="challenge.name"
                                    class="challenge-card-icon-image"
                                    @error="$event.target.style.display='none';">
                            </template>
                            <div 
                                class="challenge-card-icon-emoji" 
                                x-text="challenge.icon"
                                x-show="!challenge.image_url">
                            </div>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="font-size: 15px; font-weight: 600; margin: 0 0 4px 0;" x-text="challenge.name"></h4>
                            <div style="font-size: 11px; color: var(--color-text-light);" x-text="challenge.group_name"></div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="#fbbf24">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                    </div>

                    <!-- Stats -->
                    <div style="display: flex; gap: 16px; font-size: 12px; color: var(--color-text-muted); margin-bottom: 12px; position: relative; z-index: 1;">
                        <span><span x-text="challenge.total_routes"></span> tras</span>
                        <span x-show="challenge.total_distance_km">
                            <strong x-text="challenge.total_distance_km"></strong> km
                        </span>
                        <span style="color: #f59e0b; font-weight: 600;">✓ 100%</span>
                    </div>

                    <!-- Button -->
                    <button 
                        @click="openChallengeDetails(challenge.challenge_id)"
                        class="btn btn-secondary btn-block"
                        style="font-size: 13px; position: relative; z-index: 1;">
                        ZOBACZ SZCZEGÓŁY
                    </button>
                </div>
            </template>

            <!-- Empty state -->
            <div x-show="userChallenges.filter(c => c.completed).length === 0" class="empty-state">
                <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                <div class="empty-state-title">Brak ukończonych wyzwań</div>
                <p style="font-size: 13px; color: var(--color-text-light); margin-top: 8px;">
                    Dokończ swoje wyzwania, aby zobaczyć je tutaj
                </p>
            </div>
        </div>
    </div>

    <!-- ========================================================================
         TAB 3: DOSTĘPNE (nie podjęte)
         ======================================================================== -->
    <div x-show="activeChallengeTab === 'available'" x-cloak style="padding: 0 24px 24px;">

        <!-- Search -->
        <div style="margin-bottom: 16px;">
            <div class="search-input-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <input type="text" 
                       x-model="challengeSearch" 
                       @input="ChallengesModule.searchChallenges($data, challengeSearch)" 
                       placeholder="Poszukaj wyzwania dla siebie ..." 
                       class="form-input">
            </div>
        </div>

        <!-- Loading -->
        <div x-show="loadingChallenges" style="text-align: center; padding: 40px 0;">
            <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
                <line x1="12" y1="2" x2="12" y2="6"/>
                <line x1="12" y1="18" x2="12" y2="22"/>
            </svg>
        </div>

        <!-- Accordion grup -->
        <div x-show="!loadingChallenges" x-cloak>
            <template x-for="group in challengeGroups" :key="group.group_id">
                <details open style="margin-bottom: 12px; border: 1px solid var(--color-border-light); border-radius: var(--radius-lg); overflow: hidden;">
                    <summary style="padding: 12px 16px; cursor: pointer; font-weight: 600; background: rgba(0,0,0,0.02); display: flex; align-items: center; gap: 8px; font-size: 13px;">
                        <span style="font-size: 20px;" x-text="group.icon"></span>
                        <span style="flex: 1;" x-text="group.name"></span>
                        <span style="font-size: 11px; color: var(--color-text-light);" x-text="group.challenges.filter(c => !c.started).length + ' wyzwań'"></span>
                    </summary>

                    <div style="padding: 12px;">
                        <template x-for="challenge in group.challenges.filter(c => !c.started)" :key="challenge.challenge_id">
                            <div style="background: white; border: 1px solid var(--color-border-light); border-radius: var(--radius-md); padding: 12px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: start; gap: 12px; margin-bottom: 12px;">
                                    <div class="challenge-card-icon-wrapper">
                                        <template x-if="challenge.image_url">
                                            <img 
                                                :src="challenge.image_url" 
                                                :alt="challenge.name"
                                                class="challenge-card-icon-image"
                                                @error="$event.target.style.display='none';">
                                        </template>
                                        <div 
                                            class="challenge-card-icon-emoji" 
                                            x-text="challenge.icon"
                                            x-show="!challenge.image_url">
                                        </div>
                                    </div>
                                    <div style="flex: 1;">
                                        <h5 style="font-size: 14px; font-weight: 600; margin: 0 0 4px 0;" x-text="challenge.name"></h5>
                                        <p style="font-size: 12px; color: var(--color-text-muted); margin: 0 0 8px 0; line-height: 1.4;" 
                                           x-text="Utils.truncate(challenge.description, 150)">
                                        </p>
                                        <div style="font-size: 11px; color: var(--color-text-light);">
                                            <strong x-text="challenge.total_routes"></strong> tras
                                            <span x-show="challenge.total_distance_km">
                                                • <strong x-text="challenge.total_distance_km"></strong> km
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Buttons -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <button 
                                        @click="openChallengeDetails(challenge.challenge_id)"
                                        class="btn btn-secondary"
                                        style="font-size: 13px;">
                                        SZCZEGÓŁY
                                    </button>
                                    <button 
                                        @click="startChallenge(challenge.challenge_id)"
                                        class="btn btn-primary"
                                        style="font-size: 13px;">
                                        PODEJMUJĘ SIĘ
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </details>
            </template>
        </div>

    </div>

</div>