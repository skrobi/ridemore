<!-- Route Details Section (collapsible) -->
<div class="route-details-section" x-data="routeDetails">

    <!-- Details Header (always visible) -->
    <div class="details-header" @click="toggleDetails()">
        <div class="details-title">
            <img src="<?= asset('icons/file-text.svg') ?>" alt="" style="width: 16px; height: 16px; opacity: 0.7;">
            <span>Szczegóły trasy</span>
        </div>
        <img src="<?= asset('icons/chevron-down.svg') ?>" alt=""
             style="width: 12px; height: 12px; transition: transform 0.2s;"
             :style="detailsOpen ? 'transform: rotate(180deg)' : ''">
    </div>

    <!-- Details Body (collapsible) -->
    <div class="details-body"
         x-show="detailsOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0">

        <!-- Route Name (required) -->
        <div class="detail-item">
            <label class="detail-label required">
                <img src="<?= asset('icons/tag.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Nazwa trasy
            </label>
            <input type="text"
                   x-model="routeName"
                   placeholder="np. Pętla przez Bieszczady"
                   class="detail-input"
                   maxlength="100">
            <div class="detail-hint">Wymagane - maksymalnie 100 znaków</div>
        </div>

        <!-- Tagline (optional) -->
        <div class="detail-item">
            <label class="detail-label">
                <img src="<?= asset('icons/message-square.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Hasło zachęcające
            </label>
            <input type="text"
                   x-model="tagline"
                   placeholder="np. Jeśli masz tylko jeden dzień w Bieszczadach..."
                   class="detail-input"
                   maxlength="250">
            <div class="detail-hint">Opcjonalne - krótkie zachęcające hasło (max 250 znaków)</div>
        </div>

        <!-- Description (optional) -->
        <div class="detail-item">
            <label class="detail-label">
                <img src="<?= asset('icons/align-left.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Opis trasy
            </label>
            <textarea x-model="description"
                      placeholder="Opisz trasę, najważniejsze punkty, trudności, atrakcje..."
                      class="detail-textarea"
                      rows="4"
                      maxlength="2000"></textarea>
            <div class="detail-hint">Opcjonalne - maksymalnie 2000 znaków</div>
        </div>

        <!-- Route Type -->
        <div class="detail-item">
            <label class="detail-label">
                <img src="<?= asset('icons/compass.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Typ trasy
            </label>
            <select x-model="routeType" class="detail-select">
                <option value="road">Szosa</option>
                <option value="gravel">Gravel</option>
                <option value="mtb">MTB</option>
                <option value="mixed">Mieszana</option>
            </select>
        </div>

        <!-- Difficulty Level -->
        <div class="detail-item">
            <label class="detail-label">
                <img src="<?= asset('icons/activity.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Poziom trudności
            </label>
            <select x-model="difficultyLevel" class="detail-select">
                <option value="easy">Łatwa</option>
                <option value="medium">Średnia</option>
                <option value="hard">Trudna</option>
                <option value="extreme">Ekstremalna</option>
            </select>
        </div>

        <!-- Visibility -->
        <div class="detail-item">
            <label class="detail-label">
                <img src="<?= asset('icons/eye.svg') ?>" alt="" style="width: 14px; height: 14px;">
                Widoczność
            </label>
            <select x-model="visibility" class="detail-select">
                <option value="private">Prywatna (tylko ja)</option>
                <option value="public">Publiczna (wszyscy)</option>
            </select>
            <div class="detail-hint">Możesz zmienić to później</div>
        </div>

    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('routeDetails', () => ({
        detailsOpen: false,
        routeName: '',
        description: '',
        tagline: '',
        difficultyLevel: 'medium',
        routeType: 'road',
        visibility: 'private',

        init() {
            console.log('✅ Route Details Alpine component initialized');

            // Restore collapsed state
            const detailsState = localStorage.getItem('plannerDetailsOpen');
            this.detailsOpen = detailsState === 'true';

            // Sync routeName → PlannerState
            this.$watch('routeName', (val) => {
                PlannerState.routeName = val;
            });

            // ✅ Fallback: event mógł przylecieć zanim ten komponent się zainicjalizował
            if (window._loadedRouteData) {
                console.log('📦 Route data already loaded, populating from cache');
                this._populateFromRoute(window._loadedRouteData);
            }

            // Nasłuchuj przyszłych ładowań
            PlannerEvents.on('route:loaded', (routeData) => {
                console.log('🔔 route:loaded received in routeDetails');
                this._populateFromRoute(routeData);
            });
        },

        _populateFromRoute(routeData) {
            this.routeName       = routeData.name             || '';
            this.description     = routeData.description      || '';
            this.tagline         = routeData.tagline          || '';
            this.difficultyLevel = routeData.difficulty_level || 'medium';
            this.routeType       = routeData.route_type       || 'road';
            this.visibility      = routeData.visibility       || 'private';
            console.log('✅ Route details populated:', this.routeName);
        },

        toggleDetails() {
            this.detailsOpen = !this.detailsOpen;
            localStorage.setItem('plannerDetailsOpen', this.detailsOpen);
        }
    }));
});
</script>