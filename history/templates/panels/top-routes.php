<?php
/* ============================================================================
  TOP ROUTES PANEL - Recommendation Engine v2 + Interactive Hover
  ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'top-routes' }">

    <!-- HEADER -->
    <div style="padding: 24px 24px 16px; border-bottom: 1px solid var(--color-border-light);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
            <h1 style="font-size: 20px; font-weight: 300; letter-spacing: 0.02em; margin: 0; color: var(--color-text); display: flex; align-items: center; gap: 12px;">
                <img src="<?= asset('icons/map.svg') ?>" alt="" class="icon-svg">
                    PROPOZYCJE RIDEMORE.BIKE
            </h1>
            <button class="close-btn" @click="closeAll()" style="background: transparent; border: none; cursor: pointer; padding: 8px; color: var(--color-text-muted); transition: color 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div style="height: 4px; width: 60px; background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%); border-radius: 2px; opacity: 0.6;"></div>
    </div>

    <!-- LOADING -->
    <div x-show="loadingTopPanel" style="text-align: center; padding: 40px;" x-cloak>
        <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="2" x2="12" y2="6"/>
            <line x1="12" y1="18" x2="12" y2="22"/>
        </svg>
        <div style="margin-top: 12px; color: var(--color-text-muted); font-size: 13px;">Ładowanie...</div>
    </div>

    <!-- CONTENT -->
    <div x-show="!loadingTopPanel" x-cloak>

        <!-- ========================================================= -->
        <!-- HERO CARD -->
        <!-- ========================================================= -->
        <div 
            x-show="topPanelData?.hero" 
            @mouseenter="highlightRouteOnMap(topPanelData?.hero?.route_id)"
            @mouseleave="unhighlightRouteOnMap(topPanelData?.hero?.route_id)"
            @click="openRouteDetails(topPanelData?.hero?.route_id)" 
            style="margin: 24px; padding: 0; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s;" 
            class="hero-card"
            x-cloak>

            <!-- Image -->
           <div style="position: relative; height: 180px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <!-- DEBUG -->
    
    
    <!-- Docelowy obrazek -->
    <template x-if="topPanelData?.hero?.cover_image_url">
        <img :src="window.APP_CONFIG.apiUrl + '/photos/thumbnail.php?size=800&path=' + encodeURIComponent(topPanelData?.hero?.cover_image_url)" 
             style="width: 100%; height: 100%; object-fit: cover;">
    </template>

    <!-- Badge -->
    <div style="position: absolute; top: 16px; left: 16px; background: rgba(255,255,255,0.95); padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; color: #667eea; backdrop-filter: blur(8px);">
        ⭐ REKOMENDACJA DNIA
    </div>
</div>

            <!-- Content -->
            <div style="padding: 20px;">
                <h3 style="font-size: 18px; font-weight: 600; margin: 0 0 12px 0; color: var(--color-text);" x-text="topPanelData?.hero?.name"></h3>

                <!-- Stats Row -->
                <div style="display: flex; gap: 16px; margin-bottom: 16px; font-size: 13px; color: var(--color-text-muted);">
                    <span><strong x-text="topPanelData?.hero?.distance_km"></strong> km</span>
                    <span>↗️ <strong x-text="topPanelData?.hero?.ascent_m"></strong> m</span>
                    <span x-show="topPanelData?.hero?.difficulty_score">
                        ⚡ <strong x-text="Math.round(topPanelData?.hero?.difficulty_score || 0)"></strong>/10
                    </span>
                </div>

                <!-- Quality Bar -->
                <div style="margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span style="font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Jakość trasy</span>
                        <span style="font-size: 16px; font-weight: 600; color: #667eea;" x-text="topPanelData?.hero?.quality_display + '/10'"></span>
                    </div>
                    <div style="height: 6px; background: var(--color-border-light); border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); transition: width 0.3s;" :style="`width: ${(topPanelData?.hero?.quality_display || 0) * 10}%`"></div>
                    </div>
                </div>

                <!-- Challenges -->
                <div x-show="topPanelData?.hero?.challenge_count > 0" style="padding: 12px; background: rgba(59, 130, 246, 0.05); border-radius: 10px; border-left: 3px solid #3b82f6;" x-cloak>
                    <div style="font-size: 11px; font-weight: 600; color: #3b82f6; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.03em;">
                        🎯 OTWIERA <span x-text="topPanelData?.hero?.challenge_count"></span> <span x-text="topPanelData?.hero?.challenge_count === 1 ? 'WYZWANIE' : 'WYZWANIA'"></span>
                    </div>
                    <div style="font-size: 12px; color: var(--color-text); line-height: 1.5;">
                        <template x-for="(ch, idx) in (topPanelData?.hero?.challenges || []).slice(0, 2)" :key="ch.challenge_id">
                            <span>
                                <span x-text="ch.name"></span><span x-show="idx < Math.min(2, (topPanelData?.hero?.challenges || []).length) - 1">, </span>
                            </span>
                        </template>
                        <span x-show="topPanelData?.hero?.challenge_count > 2" style="color: var(--color-text-muted);">...</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- TOP QUALITY (5 tras) -->
        <!-- ========================================================= -->
        <div style="padding: 0 24px 24px;">
            <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: var(--color-text); display: flex; align-items: center; gap: 8px;">
                <img src="<?= asset('icons/book-open-check.svg') ?>" alt="" class="icon-svg">
                    <span>CZĘSTO WYBIERANE</span>
            </h3>

            <template x-for="(route, idx) in topPanelData?.top_quality || []" :key="route.route_id">
                <div 
                    @mouseenter="highlightRouteOnMap(route.route_id)"
                    @mouseleave="unhighlightRouteOnMap(route.route_id)"
                    @click="openRouteDetails(route.route_id)" 
                    style="background: white; border: 1px solid var(--color-border-light); border-radius: 12px; padding: 16px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s;" 
                    class="top-quality-card">

                    <div style="display: flex; align-items: start; justify-content: space-between; margin-bottom: 10px;">
                        <div style="flex: 1; min-width: 0;">
                            <h4 style="font-size: 14px; font-weight: 600; margin: 0 0 6px 0; color: var(--color-text);" x-text="route.name"></h4>

                            <div style="display: flex; gap: 12px; font-size: 12px; color: var(--color-text-muted);">
                                <span><span x-text="route.distance_km"></span> km</span>
                                <span>↗️ <span x-text="route.ascent_m"></span> m</span>

                                <!-- ✅ NOWE: Challenge badge -->
                                <span x-show="route.challenge_count > 0" style="color: #8b5cf6; font-weight: 600;">
                                    🎯 <span x-text="route.challenge_count"></span>
                                </span>
                            </div>

                            <!-- ✅ NOWE: Challenge names (opcjonalnie) -->
                            <div x-show="route.challenges && route.challenges.length > 0" style="margin-top: 6px; font-size: 11px; color: #8b5cf6;" x-cloak>
                                <template x-for="(ch, idx) in (route.challenges || []).slice(0, 2)" :key="ch.challenge_id">
                                    <span>
                                        <span x-text="ch.icon || '🎯'"></span>
                                        <span x-text="ch.name"></span>
                                        <span x-show="idx < Math.min(2, route.challenges.length) - 1">, </span>
                                    </span>
                                </template>
                                <span x-show="route.challenge_count > 2">+<span x-text="route.challenge_count - 2"></span></span>
                            </div>
                        </div>

                        <!-- Quality Score -->
                        <div style="display: flex; flex-direction: column; align-items: center; margin-left: 12px;">
                            <div style="font-size: 20px; font-weight: 600; color: #667eea; line-height: 1;" x-text="route.quality_display"></div>
                            <div style="font-size: 9px; color: var(--color-text-muted); margin-top: 2px;">/10</div>
                        </div>
                    </div>

                    <!-- Difficulty Bar (10 segments) -->
                    <div style="display: flex; gap: 3px;">
                        <template x-for="i in 10" :key="i">
                            <div style="flex: 1; height: 4px; border-radius: 2px; transition: background 0.2s;" 
                                 :style="i <= Math.round(route.difficulty_score || 0) ? 'background: linear-gradient(90deg, #fbbf24, #f59e0b);' : 'background: var(--color-border-light);'">
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Empty state -->
            <div x-show="!topPanelData?.top_quality || topPanelData.top_quality.length === 0" style="text-align: center; padding: 40px 20px; color: var(--color-text-muted);" x-cloak>
                <img src="<?= asset('images/ride-map.png'); ?>" alt="Brak tras">
                    <div style="font-size: 14px; font-weight: 500; margin-bottom: 6px;">Brak tras</div>
                    <div style="font-size: 12px;">Przesuń mapę aby zobaczyć trasy</div>
            </div>
        </div>
        <!-- ========================================================= -->
        <!-- DLA AMBITNYCH -->
        <!-- ========================================================= -->
        <div x-show="topPanelData?.for_ambitious?.length > 0" style="padding: 0 24px 24px;" x-cloak>
            <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: var(--color-text); display: flex; align-items: center; gap: 8px;">
                <img src="<?= asset('icons/flame.svg') ?>" alt="" class="icon-svg">
                    <span>DLA AMBITNYCH</span>
            </h3>

            <template x-for="(route, idx) in topPanelData?.for_ambitious || []" :key="route.route_id">
                <div 
                    @mouseenter="highlightRouteOnMap(route.route_id)"
                    @mouseleave="unhighlightRouteOnMap(route.route_id)"
                    @click="openRouteDetails(route.route_id)" 
                    style="background: white; border: 1px solid var(--color-border-light); border-radius: 12px; padding: 16px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s; border-left: 3px solid #f59e0b;" 
                    class="top-quality-card">

                    <div style="display: flex; align-items: start; justify-content: space-between; margin-bottom: 10px;">
                        <div style="flex: 1; min-width: 0;">
                            <h4 style="font-size: 14px; font-weight: 600; margin: 0 0 6px 0; color: var(--color-text);" x-text="route.name"></h4>
                            <div style="display: flex; gap: 12px; font-size: 12px; color: var(--color-text-muted);">
                                <span><span x-text="route.distance_km"></span> km</span>
                                <span>↗️ <span x-text="route.ascent_m"></span> m</span>
                            </div>
                        </div>

                        <!-- Difficulty Score -->
                        <div style="display: flex; flex-direction: column; align-items: center; margin-left: 12px;">
                            <div style="font-size: 20px; font-weight: 600; color: #f59e0b; line-height: 1;" x-text="Math.round(route.difficulty_score || 0)"></div>
                            <div style="font-size: 9px; color: var(--color-text-muted); margin-top: 2px;">/10</div>
                        </div>
                    </div>

                    <!-- Difficulty Bar -->
                    <div style="display: flex; gap: 3px;">
                        <template x-for="i in 10" :key="i">
                            <div style="flex: 1; height: 4px; border-radius: 2px; transition: background 0.2s;" 
                                 :style="i <= Math.round(route.difficulty_score || 0) ? 'background: linear-gradient(90deg, #ef4444, #f59e0b);' : 'background: var(--color-border-light);'">
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
        <!-- ========================================================= -->
        <!-- FEED -->
        <!-- ========================================================= -->
        <div x-show="topPanelData?.feed?.length > 0" style="padding: 0 24px 24px;" x-cloak>
            <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: var(--color-text); display: flex; align-items: center; gap: 8px;">
                <img src="<?= asset('icons/activity.svg') ?>" alt="" style="width: 16px; height: 16px;">
                    <span>CO SIĘ DZIEJE</span>
            </h3>

            <!-- Cards wrapper -->
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <template x-for="event in topPanelData?.feed || []" :key="event.feed_id">
                    <!-- Card -->
                    <div
                        @click="event.context_type === 'challenge' && event.context_id ? openChallengeDetails(event.context_id) : null"
                        style="
                        padding: 12px;
                        background: #ffffff;
                        border: 1px solid var(--color-border-light);
                        border-radius: 10px;
                        cursor: pointer;
                        transition: box-shadow 0.15s, transform 0.1s;
                        "
                        @mouseover="
                        $el.style.boxShadow='0 4px 12px rgba(0,0,0,0.06)';
                        $el.style.transform='translateY(-1px)';
                        "
                        @mouseout="
                        $el.style.boxShadow='none';
                        $el.style.transform='none';
                        "
                        >
                        <div style="display: flex; gap: 10px; align-items: flex-start;">

                            <!-- Avatar -->
                            <div style="
                                 width: 32px;
                                 height: 32px;
                                 border-radius: 50%;
                                 background: #5b21b6;
                                 display: flex;
                                 align-items: center;
                                 justify-content: center;
                                 color: white;
                                 font-weight: 600;
                                 font-size: 13px;
                                 flex-shrink: 0;
                                 ">
                                <span x-text="(event.username || 'U').charAt(0).toUpperCase()"></span>
                            </div>

                            <!-- Content -->
                            <div style="flex: 1; min-width: 0;">
                                <div
                                    style="font-size: 13px; line-height: 1.45; color: var(--color-text);"
                                    x-html="event.message">
                                </div>

                                <div style="margin-top: 2px; font-size: 11px; color: var(--color-text-muted);">
                                    <span x-text="event.time_ago"></span>
                                </div>
                            </div>

                            <!-- Icon -->
                            <div style="flex-shrink: 0;">
                                <img :src="event.icon_url" alt="" style="width: 20px; height: 20px; opacity: 0.5;">
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

    </div>
</div>

<style>
    @keyframes spin {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }

    .animate-spin {
        animation: spin 1s linear infinite;
    }

    /* ✅ Dodaj hover effect dla hero */
    .hero-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(102, 126, 234, 0.2);
    }

    .top-quality-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border-color: #667eea;
    }

    .top-quality-card:active,
    .hero-card:active {
        transform: translateY(0);
    }
</style>