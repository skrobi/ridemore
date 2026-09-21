<?php
/* ============================================================================
  Challenge Routes Panel - z RouteCardRenderer
  ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: !showChallengeRoutes }">
    <div class="panel-header">
        <h2>TRASY W WYZWANIU</h2>
        <button class="close-btn" @click="showChallengeRoutes = false">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <!-- Challenge name -->
    <div style="padding: 12px 20px; background: rgba(245, 245, 247, 0.5); border-bottom: 1px solid var(--color-border-light);">
        <div style="display: flex; align-items: center; gap: 12px;">
            <!-- Icon/Image -->
            <div class="challenge-card-icon-wrapper">
                <template x-if="selectedChallenge?.image_url">
                    <img 
                        :src="selectedChallenge.image_url" 
                        :alt="selectedChallenge.name"
                        class="challenge-card-icon-image"
                        @error="$event.target.style.display='none';">
                </template>
                <div 
                    class="challenge-card-icon-emoji" 
                    x-text="selectedChallenge?.icon"
                    x-show="!selectedChallenge?.image_url">
                </div>
            </div>

            <!-- Text -->
            <div style="flex: 1;">
                <h3 style="font-size: 14px; font-weight: 600; margin: 0 0 4px 0;" x-text="selectedChallenge?.name"></h3>
                <div style="font-size: 12px; color: var(--color-text-muted);">
                    <span x-text="selectedChallenge?.completed_count"></span> z 
                    <span x-text="selectedChallenge?.total_routes"></span> tras zaliczonych
                </div>
            </div>
        </div>
    </div>

    <!-- Loading -->
    <div x-show="loadingChallengeRoutes" x-cloak style="text-align: center; padding: 40px 0;">
        <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
            <line x1="12" y1="2" x2="12" y2="6"/>
            <line x1="12" y1="18" x2="12" y2="22"/>
        </svg>
    </div>

    <!-- Routes list -->
    <div x-show="!loadingChallengeRoutes" style="padding: 0 20px 20px;" x-cloak>

        <!-- Filter buttons -->
        <!-- Filter buttons -->
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin: 16px 0;">
            <button 
                class="btn btn-sm"
                :class="challengeRoutesFilter === 'all' ? 'btn-primary' : 'btn-outline'"
                @click="challengeRoutesFilter = 'all'"
                style="font-size: 12px; position: relative;">
                Wszystkie
                <span class="badge" x-text="selectedChallenge?.routes?.length || 0"></span>
            </button>

            <button 
                class="btn btn-sm"
                :class="challengeRoutesFilter === 'completed' ? 'btn-primary' : 'btn-outline'"
                @click="challengeRoutesFilter = 'completed'"
                style="font-size: 12px; position: relative;">
                Zaliczone
                <span class="badge" x-text="selectedChallenge?.routes?.filter(r => r.completed).length || 0"></span>
            </button>

            <button 
                class="btn btn-sm"
                :class="challengeRoutesFilter === 'remaining' ? 'btn-primary' : 'btn-outline'"
                @click="challengeRoutesFilter = 'remaining'"
                style="font-size: 12px; position: relative;">
                Do zrobienia
                <span class="badge" x-text="selectedChallenge?.routes?.filter(r => !r.completed).length || 0"></span>
            </button>
        </div>

        <!-- Routes - używa RouteCardRenderer -->
        <template x-for="route in getFilteredChallengeRoutes()" :key="route.route_id">
            <div x-html="window.RouteCardRenderer.render({
                 ...route,
                 is_completed: route.completed,
                 in_challenge: true
                 })" 
                 @click="openRouteDetails(route.route_id)"
                 style="cursor: pointer;">
            </div>
        </template>

        <!-- Empty state -->
        <div x-show="getFilteredChallengeRoutes().length === 0" class="empty-state" x-cloak>
            <img src="<?= asset('icons/target.svg') ?>" alt="Brak tras" style="width: 100px;">
            <div class="empty-state-title">Brak tras</div>
            <p style="font-size: 13px; color: var(--color-text-light); margin-top: 8px;">
                <span x-show="challengeRoutesFilter === 'completed'">Jeszcze nie zaliczyłeś żadnej trasy</span>
                <span x-show="challengeRoutesFilter === 'remaining'">Wszystkie trasy zaliczone!</span>
            </p>
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
</style>