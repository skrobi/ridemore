<?php
/* ============================================================================
  Zaplanowane Trasy Panel
  ============================================================================ */
?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'planned' }">

    <!-- HEADER -->
    <div class="panel-header">
        <h2>ZAPLANOWANE TRASY</h2>
        <button class="close-btn" @click="closeAll()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <!-- PLAN ROUTE BUTTON -->
    <div style="padding: 0 24px 16px;">
        <a href="<?= url('planner.php') ?>" class="btn btn-primary btn-block" style="display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            ZAPLANUJ TRASĘ
        </a>
    </div>

    <!-- FILTERS -->
    <div style="padding: 0 24px 16px;">
        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
            <button 
                class="btn btn-sm"
                :class="plannedFilter === 'all' ? 'btn-primary' : 'btn-outline'"
                @click="plannedFilter = 'all'"
                style="font-size: 12px;">
                Wszystkie
            </button>
            <button 
                class="btn btn-sm"
                :class="plannedFilter === 'week' ? 'btn-primary' : 'btn-outline'"
                @click="plannedFilter = 'week'"
                style="font-size: 12px;">
                Ten tydzień
            </button>
            <button 
                class="btn btn-sm"
                :class="plannedFilter === 'month' ? 'btn-primary' : 'btn-outline'"
                @click="plannedFilter = 'month'"
                style="font-size: 12px;">
                Ten miesiąc
            </button>
        </div>
    </div>

    <!-- LOADING -->
    <div x-show="loadingPlanned" x-cloak style="text-align: center; padding: 40px 0;">
        <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
            <line x1="12" y1="2" x2="12" y2="6"/>
            <line x1="12" y1="18" x2="12" y2="22"/>
        </svg>
    </div>

    <!-- PLANNED ROUTES LIST - grouped by date -->
    <div x-show="!loadingPlanned" style="padding: 0 24px 24px;" x-cloak>

        <template x-for="[dateKey, routes] in Object.entries(getGroupedPlanned())" :key="dateKey">
            <div style="margin-bottom: 24px;">
                <!-- Date header -->
                <div style="font-size: 11px; font-weight: 600; color: var(--color-text-muted); margin-bottom: 12px; letter-spacing: 0.05em; text-transform: uppercase; padding-bottom: 8px; border-bottom: 1px solid var(--color-border-light);">
                    <span x-text="formatPlannedDateHeader(dateKey)"></span>
                    <span style="font-weight: 400; color: var(--color-text-light); margin-left: 8px;">
                        (<span x-text="routes.length"></span>)
                    </span>
                </div>

                <!-- Routes in this date -->
                <template x-for="route in routes" :key="route.route_id">
                    <div style="margin-bottom: 12px; position: relative;">
                        <!-- Planned badge -->
                        <div style="position: absolute; top: 12px; right: 12px; z-index: 10;">
                            <div style="background: #f59e0b; padding: 4px 10px; border-radius: 12px; font-size: 9px; font-weight: 600; color: white; letter-spacing: 0.03em; text-transform: uppercase; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                PLANNED
                            </div>
                        </div>

                        <!-- Route card -->
                        <div x-html="window.RouteCardRenderer.render({
                             route_id: route.route_id,
                             name: route.name,
                             distance_km: route.distance_km,
                             ascent_m: route.ascent_m || 0,
                             difficulty_level: route.difficulty_level || 'medium',
                             rating: 0,
                             rating_count: 0,
                             is_completed: false,
                             is_top: false,
                             in_challenge: false,
                             source: 'planned',
                             activity_date: route.activity_date
                             }, { 
                             showDelete: true,
                             showDifficulty: true,
                             showRating: false,
                             showBadges: false,
                             editUrl: '<?= url('planner.php') ?>?route_id=' + route.route_id,
                             showEdit: true
                             })" 
                             @click="if(!$event.target.closest('.btn-actions-menu') && !$event.target.closest('.actions-dropdown')) openRouteDetails(route.route_id)"
                             style="cursor: pointer;">
                        </div>

                      
                    </div>
                </template>
            </div>
        </template>

        <!-- EMPTY STATE -->
        <div x-show="Object.keys(getGroupedPlanned()).length === 0" class="empty-state" x-cloak>
            <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <div class="empty-state-title">Brak zaplanowanych tras</div>
            <p style="font-size: 13px; color: var(--color-text-light); margin-top: 8px;">
                <span x-show="plannedFilter === 'all'">Kliknij "Zaplanuj trasę" aby stworzyć nową</span>
                <span x-show="plannedFilter === 'week'">Brak tras z tego tygodnia</span>
                <span x-show="plannedFilter === 'month'">Brak tras z tego miesiąca</span>
            </p>
        </div>
    </div>

</div>