<div class="sidebar-footer">
    <div class="total-stats" x-show="waypoints.length >= 2">
        <div class="stat">
            <img src="<?= asset('icons/navigation.svg') ?>" alt="" style="width: 16px; height: 16px; opacity: 0.7;">
            <span class="stat-val" x-text="(totalDistance / 1000).toFixed(1)"></span>
            <span>km</span>
        </div>
        <div class="stat">
            <img src="<?= asset('icons/clock.svg') ?>" alt="" style="width: 16px; height: 16px; opacity: 0.7;">
            <span class="stat-val" x-text="formatDuration(totalDuration)"></span>
        </div>
        <div class="stat" x-show="waypoints.length">
            <img src="<?= asset('icons/map-pin.svg') ?>" alt="" style="width: 16px; height: 16px; opacity: 0.7;">
            <span class="stat-val" x-text="waypoints.length"></span>
            <span>pkt</span>
        </div>
    </div>
    
    <!-- Challenge Coverage Stats -->
    <div class="challenge-coverage" 
         x-show="challenge && challengeCoverageStats" 
         x-data="{ expanded: false }">
        
        <div class="coverage-header" @click="expanded = !expanded">
            <div class="coverage-title">
                <img src="<?= asset('icons/trophy.svg') ?>" alt="" 
                     style="width: 16px; height: 16px; filter: invert(42%) sepia(98%) saturate(2679%) hue-rotate(251deg) brightness(102%) contrast(101%);">
                <span>Pokrycie wyzwania</span>
            </div>
            <div class="coverage-summary">
                <span class="coverage-percent" 
                      :style="`color: ${challengeCoverageStats?.coveragePercent >= 100 ? '#10b981' : challengeCoverageStats?.coveragePercent >= 50 ? '#f59e0b' : '#8b5cf6'}`"
                      x-text="challengeCoverageStats?.coveragePercent + '%'">
                </span>
                <img src="<?= asset('icons/chevron-down.svg') ?>" alt="" 
                     style="width: 12px; height: 12px; transition: transform 0.2s;"
                     :style="expanded ? 'transform: rotate(180deg)' : ''">
            </div>
        </div>
        
        <div class="coverage-details" 
             x-show="expanded"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0">
            
            <!-- Progress Bar -->
            <div class="coverage-progress">
                <div class="progress-bar">
                    <div class="progress-fill" 
                         :style="`width: ${challengeCoverageStats?.coveragePercent}%; background: ${challengeCoverageStats?.coveragePercent >= 100 ? 'linear-gradient(90deg, #10b981, #059669)' : challengeCoverageStats?.coveragePercent >= 50 ? 'linear-gradient(90deg, #f59e0b, #d97706)' : 'linear-gradient(90deg, #8b5cf6, #7c3aed)'}`">
                    </div>
                </div>
                <div class="progress-text">
                    <span x-text="challengeCoverageStats?.coveredDistance?.toFixed(1)">0</span>km
                    <span class="text-muted">/</span>
                    <span x-text="challengeCoverageStats?.totalDistance?.toFixed(1)">0</span>km
                </div>
            </div>
            
            <!-- Stats -->
            <div class="coverage-routes">
                <div class="stat-row">
                    <img src="<?= asset('icons/map.svg') ?>" alt="" style="width: 14px; height: 14px; opacity: 0.6;">
                    <span class="stat-label">Tras w trakcie</span>
                    <span class="stat-value" 
                          x-text="challengeCoverageStats?.routesCovered?.length || 0">
                    </span>
                </div>
                <div class="stat-row">
                    <img src="<?= asset('icons/check-circle.svg') ?>" alt="" 
                         style="width: 14px; height: 14px; filter: invert(48%) sepia(79%) saturate(2476%) hue-rotate(86deg) brightness(97%) contrast(97%);">
                    <span class="stat-label">Tras ukończonych</span>
                    <span class="stat-value success" 
                          x-text="challengeCoverageStats?.routesCompleted?.length || 0">
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-actions">
        <button class="btn-clear" @click="clearAll()" :disabled="waypoints.length === 0">
            <img src="<?= asset('icons/trash.svg') ?>" alt="" class="svg-white" style="width: 16px; height: 16px;">
            Wyczyść
        </button>
        <button class="btn-save" @click="saveRoute()" :disabled="waypoints.length < 2 || isRouting">
            <img src="<?= asset('icons/save.svg') ?>" alt="" class="svg-white" style="width: 16px; height: 16px;">
            Zapisz
        </button>
    </div>
</div>