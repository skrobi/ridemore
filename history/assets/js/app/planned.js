/* ============================================================================
 PLANNED MODULE - User planned routes
 ============================================================================ */

window.PlannedModule = {

    async loadUserPlanned(state) {
        state.loadingPlanned = true;

        try {
            const url = window.APP_CONFIG.api(
                    `planner/planned.php?user_local_id=${state.user.local_id}`
                    );

            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            const response = await fetch(url, {headers});
            const data = await response.json();

            if (data.success) {
                state.userTracksPlanned = (data.data.planned || []).map(route => ({
                        route_id: route.route_id || route.id,
                        id: route.route_id || route.id,
                        name: route.name || 'Trasa bez nazwy',
                        distance_km: parseFloat(route.distance_km || 0),
                        ascent_m: parseInt(route.ascent_m || 0),
                        difficulty_level: route.difficulty_level || 'medium',
                        source: 'planned',
                        activity_date: route.activity_date || route.created_at,
                        visibility: route.visibility || 'private'
                    }));

                console.log(`✅ Loaded ${state.userTracksPlanned.length} planned routes`);
            } else {
                console.error('❌ Failed to load planned:', data.error);
                state.userTracksPlanned = [];
            }
        } catch (error) {
            console.error('❌ Failed to load planned:', error);
            state.userTracksPlanned = [];
        } finally {
            state.loadingPlanned = false;
        }
    },

    async deletePlanned(state, routeId) {
        if (!confirm('Czy na pewno chcesz usunąć tę trasę?')) {
            return;
        }

        try {
            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            const response = await fetch(
                    window.APP_CONFIG.api(`routes/delete.php?id=${routeId}`),
                    {
                        method: 'DELETE',
                        headers
                    }
            );

            const data = await response.json();

            if (data.success) {
                console.log('✅ Planned route deleted:', routeId);
                await Promise.all([
                    this.loadUserPlanned(state),
                    window.TracksModule.loadUserTracks(state)
                ]);
            } else {
                alert('❌ ' + data.error);
            }
        } catch (error) {
            console.error('❌ Delete failed:', error);
            alert('❌ Nie udało się usunąć trasy');
        }
    },

    async showPlannedOnMap(state, routeId) {
        console.log('🗺️ Showing planned route on map:', routeId);

        if (!window.mapManager) {
            console.warn('❌ MapManager not available');
            return;
        }

        try {
            await window.mapManager.showRoute(routeId);
            console.log('✅ Planned route displayed on map');
        } catch (error) {
            console.error('❌ Failed to show route on map:', error);
        }
    },

    getGroupedPlanned(state) {
        const filtered = this.getFilteredPlanned(state);
        const grouped = {};

        filtered.forEach(route => {
            const date = route.activity_date ? route.activity_date.split(' ')[0] : 'unknown';
            if (!grouped[date]) {
                grouped[date] = [];
            }
            grouped[date].push(route);
        });

        // Sort by date desc
        const sorted = Object.keys(grouped)
                .sort((a, b) => b.localeCompare(a))
                .reduce((acc, key) => {
                    acc[key] = grouped[key];
                    return acc;
                }, {});

        return sorted;
    },

    getFilteredPlanned(state) {
        if (state.plannedFilter === 'all') {
            return state.userTracksPlanned;
        }

        const now = new Date();
        const filterDate = new Date();

        if (state.plannedFilter === 'week') {
            filterDate.setDate(now.getDate() - 7);
        } else if (state.plannedFilter === 'month') {
            filterDate.setMonth(now.getMonth() - 1);
        }

        return state.userTracksPlanned.filter(route => {
            if (!route.activity_date)
                return false;
            const routeDate = new Date(route.activity_date);
            return routeDate >= filterDate;
        });
    },

    formatPlannedDateHeader(dateKey) {
        if (dateKey === 'unknown')
            return 'Brak daty';

        const date = new Date(dateKey);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        const dateStr = date.toISOString().split('T')[0];
        const todayStr = today.toISOString().split('T')[0];
        const yesterdayStr = yesterday.toISOString().split('T')[0];

        if (dateStr === todayStr)
            return 'Dzisiaj';
        if (dateStr === yesterdayStr)
            return 'Wczoraj';

        const days = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
        const months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
            'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

        return `${days[date.getDay()]}, ${date.getDate()} ${months[date.getMonth()]}`;
    },

    formatPlannedTime(datetime) {
        if (!datetime)
            return '';
        const date = new Date(datetime);
        return date.toLocaleTimeString('pl-PL', {hour: '2-digit', minute: '2-digit'});
    }
};

console.log('✅ Planned module loaded');