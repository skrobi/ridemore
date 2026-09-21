<!-- Config Section (collapsible) -->
<div class="planner-config" x-data="plannerConfig">

    <!-- Config Header (always visible) -->
    <div class="config-header" @click="toggleConfig()">
        <div class="config-title">
            <img src="<?= asset('icons/settings.svg') ?>" alt="" style="width: 16px; height: 16px; opacity: 0.7;">
            <span>Konfiguracja</span>
        </div>
        <img src="<?= asset('icons/chevron-down.svg') ?>" alt=""
             style="width: 12px; height: 12px; transition: transform 0.2s;"
             :style="configOpen ? 'transform: rotate(180deg)' : ''">
    </div>

    <!-- Config Body (collapsible) -->
    <div class="config-body"
         x-show="configOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0">

        <!-- Bike Profile -->
        <div class="config-item">
            <label class="config-label">
                <img src="<?= asset('icons/bike.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Profil roweru
            </label>
            <div class="profile-selector-inline">
                <template x-for="profile in profiles" :key="profile.id">
                    <button class="profile-option-btn"
                            :class="{ active: bikeType === profile.id }"
                            @click="selectProfile(profile)">
                        <img :src="'<?= asset('icons/') ?>' + profile.icon" alt="" style="width: 14px; height: 14px;">
                        <span x-text="profile.name"></span>
                        <span class="speed-mini" x-text="profile.speed"></span>
                    </button>
                </template>
            </div>

            <!-- Custom speed -->
            <button class="btn-custom-speed" @click="setCustomSpeed()">
                <img src="<?= asset('icons/zap.svg') ?>" alt="" style="width: 12px; height: 12px;">
                Własna prędkość (<span x-text="speed"></span> km/h)
            </button>
        </div>

        <!-- POI Toggle -->
        <div class="config-item">
            <label class="config-toggle">
                <input type="checkbox"
                       x-model="poiEnabled"
                       @change="updatePOIConfig()">
                <img src="<?= asset('icons/map-pin.svg') ?>" alt="" style="width: 14px; height: 14px;">
                <span>Pokaż POI wzdłuż trasy</span>
            </label>
        </div>

        <!-- POI Subtypes (if enabled) -->
        <div class="config-item" x-show="poiEnabled" x-data="poiSubtypesSelector">

            <label class="config-label">
                <img src="<?= asset('icons/filter.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Kategorie POI
            </label>

            <template x-if="!subtypesLoaded">
                <div class="poi-loading">
                    <img src="<?= asset('icons/loader.svg') ?>" alt="" class="animate-spin" style="width: 16px; height: 16px;">
                    Ładowanie...
                </div>
            </template>

            <template x-if="subtypesLoaded">
                <div class="poi-checkboxes">
                    <template x-for="(subtypes, categoryName) in subtypesGrouped" :key="categoryName">
                        <div class="poi-category-group">
                            <div class="poi-category-header" x-text="categoryName"></div>
                            <template x-for="subtype in subtypes" :key="subtype.slug">
                                <label class="poi-checkbox-item">
                                    <input type="checkbox"
                                           :value="subtype.slug"
                                           :checked="isChecked(subtype.slug)"
                                           @change="toggleSubtype(subtype.slug)">
                                    <img :src="'<?= asset('icons/') ?>' + (subtype.icon || 'map-pin') + '.svg'"
                                         alt=""
                                         style="width: 14px; height: 14px;">
                                    <span x-text="subtype.subtype_name"></span>
                                    <span class="poi-count-badge" x-text="'(' + subtype.poi_count + ')'"></span>
                                </label>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            <div class="poi-count" x-show="getPOISubtypesCount() > 0">
                Wybrano: <span x-text="getPOISubtypesCount()"></span> kategorii
            </div>
        </div>

        <!-- POI Buffer (if enabled) -->
        <div class="config-item" x-show="poiEnabled">
            <label class="config-label">
                <img src="<?= asset('icons/crosshair.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Zasięg (<span x-text="poiBuffer"></span>m)
            </label>
            <input type="range"
                   x-model="poiBuffer"
                   @change="updatePOIConfig()"
                   min="100"
                   max="1000"
                   step="50"
                   class="poi-range">
            <div class="range-labels">
                <span>100m</span>
                <span>1km</span>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {

    // ========================================================================
    // MAIN CONFIG COMPONENT
    // ========================================================================
    Alpine.data('plannerConfig', () => ({
        configOpen: false,
        bikeType: 'road',
        speed: 25,
        poiEnabled: false,
        poiBuffer: 200,
        profiles: [
            {id: 'road',   icon: 'bike.svg',     name: 'Szosa',    speed: 25},
            {id: 'gravel', icon: 'compass.svg',  name: 'Gravel',   speed: 20},
            {id: 'mtb',    icon: 'mountain.svg', name: 'MTB/Tour', speed: 15}
        ],

        init() {
            console.log('✅ Planner Config Alpine component initialized');

            // Load POI config from PlannerPOI (który czyta localStorage)
            const cfg = PlannerPOI.getConfig();
            this.poiEnabled = cfg.enabled || false;
            this.poiBuffer  = cfg.bufferM || 200;

            console.log('✅ POI Config loaded:', {
                enabled:  this.poiEnabled,
                subtypes: cfg.subtypes.length,
                buffer:   this.poiBuffer
            });

            // Restore collapsed state
            const configState = localStorage.getItem('plannerConfigOpen');
            this.configOpen = configState === 'true';

            // Sync bike profile when route is loaded
            PlannerEvents.on('route:loaded', (routeData) => {
                const cfg = routeData.planner_config;
                if (!cfg) return;
                if (cfg.bike_type) this.bikeType = cfg.bike_type;
                if (cfg.avg_speed) this.speed    = cfg.avg_speed;
            });
        },

        toggleConfig() {
            this.configOpen = !this.configOpen;
            localStorage.setItem('plannerConfigOpen', this.configOpen);
        },

        selectProfile(profile) {
            this.bikeType = profile.id;
            this.speed    = profile.speed;
            PlannerState.bikeType      = profile.id;
            PlannerState.averageSpeed  = profile.speed;
            PlannerRouter.clearCache();
            PlannerRouter.rebuildAllSegments();
        },

        setCustomSpeed() {
            const s = prompt('Średnia prędkość (km/h):', this.speed);
            if (s && !isNaN(s) && s > 0 && s <= 60) {
                this.speed            = parseFloat(s);
                PlannerState.averageSpeed = this.speed;
                PlannerRouter.clearCache();
                PlannerRouter.rebuildAllSegments();
            }
        },

        // Zapisuje enabled + buffer (subtypes zarządza poiSubtypesSelector)
        updatePOIConfig() {
            const cfg = PlannerPOI.getConfig();
            PlannerPOI.updateConfig({
                enabled:  this.poiEnabled,
                subtypes: cfg.subtypes,        // nie ruszaj subtypes tutaj
                bufferM:  parseInt(this.poiBuffer)
            });
            console.log('💾 POI config updated:', {enabled: this.poiEnabled, buffer: this.poiBuffer});
        }
    }));

    // ========================================================================
    // POI SUBTYPES SELECTOR (nested component)
    // ========================================================================
    Alpine.data('poiSubtypesSelector', () => ({
        subtypesLoaded: false,
        subtypesGrouped: {},

        async init() {
            try {
                const response = await fetch('<?= get_base_url() ?>/api/poi/subtypes_grouped.php');
                const data     = await response.json();

                if (data.success) {
                    const grouped = {};
                    data.data.forEach(subtype => {
                        if (!grouped[subtype.category_name]) {
                            grouped[subtype.category_name] = [];
                        }
                        grouped[subtype.category_name].push(subtype);
                    });
                    this.subtypesGrouped = grouped;
                    this.subtypesLoaded  = true;
                }
            } catch (error) {
                console.error('Failed to load POI subtypes:', error);
            }
        },

        // ✅ Czyta zawsze świeży stan z PlannerPOI (nie z $root)
        isChecked(slug) {
            return PlannerPOI.getConfig().subtypes.includes(slug);
        },

        // ✅ Liczba wybranych kategorii
        getPOISubtypesCount() {
            return PlannerPOI.getConfig().subtypes.length;
        },

        // ✅ Toggle subtype - bezpośrednio przez PlannerPOI
        // PlannerPOI.updateConfig() zapisuje do localStorage i emituje poi:config-changed
        // plannerApp nasłuchuje poi:config-changed i wywołuje reloadPOI()
        toggleSubtype(slug) {
            const cfg      = PlannerPOI.getConfig();
            const subtypes = [...cfg.subtypes];
            const idx      = subtypes.indexOf(slug);

            if (idx > -1) {
                subtypes.splice(idx, 1);
            } else {
                subtypes.push(slug);
            }

            PlannerPOI.updateConfig({
                enabled:  cfg.enabled,
                subtypes: subtypes,
                bufferM:  cfg.bufferM
            });

            console.log(`🔘 toggleSubtype(${slug}): ${subtypes.length} aktywnych subtypes`);
        }
    }));
});
</script>