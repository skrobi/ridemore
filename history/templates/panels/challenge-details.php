<?php
/* ============================================================================
  Challenge Details Panel - REFACTORED v2.0
  Używa tylko klas CSS z components.css i badge.css
  ============================================================================ */
?>
<div class="left-panel challenge-details-panel" :class="{ collapsed: selectedChallenge === null }">

    <!-- HEADER -->
    <div class="panel-header">
        <h2>SZCZEGÓŁY WYZWANIA</h2>
        <button class="close-btn" @click="selectedChallenge = null">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <div class="challenge-details-content">

        <!-- Loading -->
        <div x-show="loadingChallengeDetails" class="challenge-loading" x-cloak>
            <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="2" x2="12" y2="6"/>
                <line x1="12" y1="18" x2="12" y2="22"/>
            </svg>
            <div>Ładowanie...</div>
        </div>

        <!-- Content -->
        <div x-show="!loadingChallengeDetails && selectedChallenge" x-cloak>

            <!-- SEKCJA 1: HERO -->
           <div class="challenge-hero">
    <!-- Plan button - lewy górny róg z tekstem -->
    <a 
        :href="`<?= url('planner.php?challenge_id=') ?>${selectedChallenge?.challenge_id}`"
        class="challenge-plan-btn"
        title="Zaplanuj trasę dla tego wyzwania"
        @click.stop>
        <img src="<?= asset('icons/route.svg') ?>" class="svg-white" width="14" height="14">
        <span>Zaplanuj</span>
    </a>

    <div class="challenge-icon-wrapper">
        <template x-if="selectedChallenge?.image_url">
            <img 
                :src="selectedChallenge.image_url" 
                :alt="selectedChallenge.name"
                class="challenge-icon-image"
                @error="$event.target.style.display='none';">
        </template>
        <div 
            x-show="!selectedChallenge?.image_url"
            x-text="selectedChallenge?.icon"
            class="challenge-icon-emoji">
        </div>
    </div>

    <h1 class="challenge-title" x-text="selectedChallenge?.name"></h1>
    <div class="challenge-group" x-text="selectedChallenge?.group_name"></div>
</div>
            <!-- SEKCJA 1B: STATS GRID -->
            <div class="challenge-stats-section">
                <div class="challenge-stats-grid" 
                     :style="selectedChallenge?.total_estimated_time_hours > 0 ? 'grid-template-columns: repeat(4, 1fr);' : ''">

                    <!-- Distance -->
                    <div class="stat-card">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" class="stat-card-icon">
                            <polygon points="3 11 22 2 13 21 11 13 3 11"/>
                        </svg>
                        <div class="stat-card-value" x-text="selectedChallenge?.total_distance_km?.toFixed(1)"></div>
                        <div class="stat-card-label">KM</div>
                    </div>

                    <!-- Ascent -->
                    <div class="stat-card">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" class="stat-card-icon">
                            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>
                            <polyline points="16 7 22 7 22 13"/>
                        </svg>
                        <div class="stat-card-value" x-text="selectedChallenge?.total_ascent_m || 0"></div>
                        <div class="stat-card-label">WZNIESIEŃ</div>
                    </div>

                    <!-- Time (tylko jeśli > 0) -->
                    <template x-if="selectedChallenge?.total_estimated_time_hours > 0">
                        <div class="stat-card">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" class="stat-card-icon">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <div class="stat-card-value" 
                                 x-text="Math.floor(selectedChallenge?.total_estimated_time_hours) + 'h' + Math.round((selectedChallenge?.total_estimated_time_hours % 1) * 60) + 'm'">
                            </div>
                            <div class="stat-card-label">CZAS</div>
                        </div>
                    </template>

                    <!-- Routes -->
                    <div class="stat-card">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" class="stat-card-icon">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        <div class="stat-card-value" x-text="selectedChallenge?.total_routes"></div>
                        <div class="stat-card-label">TRAS</div>
                    </div>
                </div>
            </div>

            <!-- SEKCJA 2: PROGRESS BAR (jeśli started) -->
            <div x-show="selectedChallenge?.started" class="challenge-progress-section" x-cloak>
                <div class="progress-header">
                    <span class="progress-label">Twój postęp</span>
                    <span class="progress-value" x-text="selectedChallenge?.progress_pct?.toFixed(0) + '%'"></span>
                </div>

                <div class="progress-bar-wrapper">
                    <div class="progress-bar-fill" :style="`width: ${selectedChallenge?.progress_pct || 0}%`"></div>
                </div>

                <div class="progress-stats">
                    <span>
                        <strong x-text="selectedChallenge?.completed_routes_count || 0"></strong>
                        /
                        <span x-text="selectedChallenge?.total_routes"></span> tras
                    </span>
                    <span>
                        <strong x-text="(selectedChallenge?.completed_distance_km || 0).toFixed(1)"></strong>
                        /
                        <span x-text="(selectedChallenge?.total_distance_km || 0).toFixed(1)"></span> km
                    </span>
                </div>
            </div>

            <!-- SEKCJA 3: DESCRIPTION -->
            <div class="challenge-description" x-show="selectedChallenge?.description">
                <p x-text="selectedChallenge?.description"></p>
            </div>

            <!-- SEKCJA 4: TRASY PREVIEW -->
            <div class="challenge-routes-section">
                <h3 class="section-title">
                    Trasy w wyzwaniu (<span x-text="selectedChallenge?.total_routes"></span>)
                </h3>

                <div class="routes-preview">
                    <template x-for="route in (selectedChallenge?.routes || []).slice(0, 3)" :key="route.route_id">
                        <div x-html="window.RouteCardRenderer.render({
                             route_id: route.route_id,
                             name: route.name,
                             distance_km: route.distance_km,
                             ascent_m: route.ascent_m,
                             difficulty_level: route.difficulty_level || 'medium',
                             rating: 0,
                             rating_count: 0,
                             is_completed: route.completed,
                             is_top: false,
                             in_challenge: true
                             }, { 
                             showDifficulty: true,
                             showRating: false,
                             showBadges: true,
                             compact: true
                             })" 
                             @click="openRouteDetails(route.route_id)">
                        </div>
                    </template>
                </div>

                <button 
                    x-show="selectedChallenge?.total_routes > 3"
                    @click="viewChallengeRoutes(selectedChallenge?.challenge_id)"
                    class="btn btn-secondary btn-block">
                    Zobacz wszystkie (<span x-text="selectedChallenge?.total_routes"></span>)
                </button>
            </div>

            <!-- SEKCJA 5: SOCIAL PROOF -->
            <div class="challenge-social-section">
                <div class="social-header">
                    <h3 class="section-title">Społeczność</h3>
                    <button class="ranking-btn" @click="openChallengeRanking()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6" x2="21" y2="6"></line>
                            <line x1="8" y1="12" x2="21" y2="12"></line>
                            <line x1="8" y1="18" x2="21" y2="18"></line>
                            <line x1="3" y1="6" x2="3.01" y2="6"></line>
                            <line x1="3" y1="12" x2="3.01" y2="12"></line>
                            <line x1="3" y1="18" x2="3.01" y2="18"></line>
                        </svg>
                        RANKING
                    </button>
                </div>

                <div class="social-stats">
                    <div class="social-stat">
                        <div class="stat-value" x-text="selectedChallenge?.participants_count || 0"></div>
                        <div class="stat-label">Uczestników</div>
                    </div>
                    <div class="social-stat">
                        <div class="stat-value completion" x-text="(selectedChallenge?.completion_rate || 0) + '%'"></div>
                        <div class="stat-label">Ukończono</div>
                    </div>
                    <div class="social-stat">
                        <div class="stat-value rating" x-text="selectedChallenge?.avg_rating || '—'"></div>
                        <div class="stat-label">Ocena</div>
                    </div>
                </div>
            </div>

            <!-- SEKCJA 6: MEDALE -->
            <div class="challenge-medals-wrapper" 
                 x-show="selectedChallenge?.medals && selectedChallenge.medals.length > 0"
                 x-cloak>

                <!-- Zdobyte medale -->
                <template x-if="(selectedChallenge?.medals || []).filter(m => m.earned).length > 0">
                    <div class="challenge-medals-earned">
                        <h3 class="section-title">Zdobyte medale</h3>
                        <div class="medals-grid">
                            <template x-for="medal in (selectedChallenge?.medals || []).filter(m => m.earned)" :key="medal.level_id">
                                <div x-html="MedalRenderer.render(medal)"></div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- Medale do zdobycia -->
                <template x-if="(selectedChallenge?.medals || []).filter(m => !m.earned).length > 0">
                    <div class="challenge-medals-section">
                        <h3 class="section-title">Medale do zdobycia</h3>

                        <div class="medals">
                            <template x-for="medal in (selectedChallenge?.medals || []).filter(m => !m.earned)" :key="medal.level_id">
                                <div class="medal">
                                    <div class="dot" :style="`background: ${medal.badge_color}`">
                                        <span x-text="medal.badge_icon"></span>
                                    </div>
                                    <div class="content">
                                        <div class="name" x-text="medal.name"></div>
                                        <div class="range">
                                            <span x-text="Math.floor(medal.required_percent_min)"></span>% –
                                            <span x-text="Math.floor(medal.required_percent_max)"></span>%
                                        </div>
                                        <div class="price" x-show="medal.has_physical" :style="`color: ${medal.badge_color}`">
                                            Medal fizyczny: <span x-text="medal.physical_price_pln"></span> zł
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="medals-info">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="16" x2="12" y2="12"/>
                                <line x1="12" y1="8" x2="12.01" y2="8"/>
                            </svg>
                            Medale cyfrowe dostaniesz automatycznie.
                        </div>
                    </div>
                </template>
            </div>

            <!-- SEKCJA 7: CTA -->
            <div class="challenge-cta-section">

                <!-- Started && !completed -->
                <template x-if="selectedChallenge?.started && !selectedChallenge?.completed">
                    <div>
                        <!-- Status badge -->
                        <div class="challenge-started-badge" x-cloak>
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            WYZWANIE W TRAKCIE
                        </div>

                        <!-- Tracking button -->
                        <button 
                            @click="startGPSTracking(selectedChallenge?.challenge_id)"
                            class="btn btn-success btn-block btn-lg"
                            style="margin-top: 12px;"
                            :disabled="trackingLoading">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                <circle cx="12" cy="10" r="3"/>
                            </svg>
                            <span x-text="trackingActive && currentTrackingRaceId === selectedChallenge?.challenge_id ? 'ZARZĄDZAJ TRACKINGIEM' : (trackingLoading ? 'Przygotowywanie...' : 'ROZPOCZNIJ TRACKING GPS')"></span>
                        </button>
                    </div>
                </template>

                <!-- Nie started -->
                <button 
                    x-show="!selectedChallenge?.started"
                    @click="startChallenge(selectedChallenge?.challenge_id)"
                    class="btn btn-primary btn-block btn-lg">
                    PODEJMUJĘ SIĘ
                </button>

                <!-- Completed -->
                <div x-show="selectedChallenge?.completed" 
                     class="challenge-completed-badge"
                     x-cloak>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="white">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                    </svg>
                    WYZWANIE UKOŃCZONE
                </div>
            </div>

        </div>
    </div>
</div>