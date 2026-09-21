/* ============================================================================
 TRACKS MODULE - User activities & GPX upload
 ============================================================================ */

window.TracksModule = {

    async loadUserTracks(state) {
        state.loadingTracks = true;

        try {
            // Correct API endpoint (user not users)
            const url = window.APP_CONFIG.api(
                    `user/activities.php?user_local_id=${state.user.local_id}`
                    );

            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            const response = await fetch(url, {headers});
            const data = await response.json();

            if (data.success) {
                // Normalizuj dane z API
                state.userTracks = (data.data.activities || []).map(track => ({
                        route_id: track.route_id || track.id,
                        id: track.route_id || track.id,
                        name: track.name || 'Trasa bez nazwy',
                        distance_km: parseFloat(track.distance_km || 0),
                        ascent_m: parseInt(track.ascent_m || 0),
                        difficulty_level: track.difficulty_level || 'medium',
                        source: track.source || 'gpx_upload',
                        activity_date: track.activity_date || track.created_at,
                        visibility: track.visibility || 'private'
                    }));

                //console.log(`✅ Loaded ${state.userTracks.length} user tracks`);
            } else {
                console.error('❌ Failed to load tracks:', data.error);
                state.userTracks = [];
            }
        } catch (error) {
            console.error('❌ Failed to load tracks:', error);
            state.userTracks = [];
        } finally {
            state.loadingTracks = false;
        }
    },
    
    openUploadPanel(state) {
    // Otwórz panel aktywności
    state.activePanel = 'tracks';
    
    // Po krótkim opóźnieniu kliknij input
    setTimeout(() => {
        const input = document.querySelector('[x-ref="activityInput"]');
        if (input) {
            input.click();
        }
    }, 100);
},

    async handleGPXUpload(state, event) {
        const file = event.target.files[0];
        if (!file)
            return;

        if (!file.name.toLowerCase().endsWith('.gpx')) {
            alert('Plik musi mieć rozszerzenie .gpx');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            alert('Plik jest zbyt duży (max 10MB)');
            return;
        }

        //console.log('📤 Uploading GPX:', file.name);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('user_local_id', state.user.local_id);

        // ✅ OKREŚL ENDPOINT - Activities vs Library
        const isActivityUpload = event.target.dataset.type === 'activity';
        const endpoint = isActivityUpload ? 'user/upload_activity.php' : 'user/upload_gpx.php';

        if (isActivityUpload) {
            // Activity-specific params
            formData.append('source', 'gpx_upload');
            formData.append('match_segments', '0');
        }

        try {
            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
                //console.log('🔑 Sending token:', state.user.token.substring(0, 20) + '...');
            } else {
                console.warn('⚠️ No token available!');
            }

            state.loadingTracks = true;

            const response = await fetch(window.APP_CONFIG.api(endpoint), {
                method: 'POST',
                body: formData,
                headers  // ✅ Headers z tokenem
            });

            const data = await response.json();

            if (data.success) {
                //console.log('✅ Upload success:', data.data);

                if (isActivityUpload) {
                    // Activity upload - show detailed info
                    let message = `✅ Aktywność załadowana!\n\n`;
                    message += `📍 ${data.data.name}\n`;
                    message += `📏 ${data.data.distance_km} km\n`;

                    if (data.data.moving_time_min) {
                        message += `⏱️ ${data.data.moving_time_min} min\n`;
                    }

                    if (data.data.has_hr)
                        message += `❤️ Puls: TAK\n`;
                    if (data.data.has_cadence)
                        message += `🔄 Kadencja: TAK\n`;
                    if (data.data.has_power)
                        message += `⚡ Moc: TAK\n`;

                    alert(message);

                    // Show matched challenges
                    if (data.data.matched_challenges && data.data.matched_challenges.length > 0) {
                        const challenges = data.data.matched_challenges
                                .map(c => `• ${c.challenge_name}: ${c.routes_completed} (${c.progress_pct}%)`)
                                .join('\n');

                        setTimeout(() => {
                            alert(`🎉 Zaktualizowane wyzwania:\n\n${challenges}`);
                        }, 500);
                    }

                } else {
                    // Library upload - simple message
                    alert('✅ GPX załadowany pomyślnie!');

                    // Notify about matched routes
                    if (data.data.matched_routes && data.data.matched_routes.length > 0) {
                        setTimeout(() => {
                            alert(`🎉 Znaleziono ${data.data.matched_routes.length} pasujących tras!`);
                        }, 500);
                    }
                }

                await this.loadUserTracks(state);

                // ✅ Jeśli w challenges - reload
                if (state.activePanel === 'challenges') {
                    await window.ChallengesModule.loadUserChallenges(state);
                }

                // Show track on map
                if (data.data.route_id && typeof window.mapManager !== 'undefined') {
                    window.mapManager.showRoute(data.data.route_id);
                }

            } else {
                alert('❌ ' + data.error);
            }
        } catch (error) {
            console.error('❌ GPX upload failed:', error);
            alert('❌ Nie udało się załadować pliku');
        } finally {
            state.loadingTracks = false;
        }

        // Reset input
        event.target.value = '';
    },

    async deleteTrack(state, trackId) {
        if (!confirm('Czy na pewno chcesz usunąć tę aktywność?')) {
            return;
        }

        try {
            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            const response = await fetch(
                    window.APP_CONFIG.api(`routes/delete.php?id=${trackId}`),
                    {
                        method: 'DELETE',
                        headers
                    }
            );

            const data = await response.json();

            if (data.success) {
                //console.log('✅ Track deleted:', trackId);

                // Reload tracks
                await this.loadUserTracks(state);
            } else {
                alert('❌ ' + data.error);
            }
        } catch (error) {
            console.error('❌ Delete failed:', error);
            alert('❌ Nie udało się usunąć aktywności');
        }
    },

    /**
     * Show track on map
     */
    async showTrackOnMap(state, routeId) {
        //console.log('🗺️ Showing track on map:', routeId);

        if (!window.mapManager) {
            console.warn('❌ MapManager not available');
            return;
        }

        try {
            // Używamy istniejącej funkcji showRoute z MapManager
            await window.mapManager.showRoute(routeId);

            //console.log('✅ Track displayed on map');
        } catch (error) {
            console.error('❌ Failed to show track on map:', error);
        }
    },

    /**
     * Group tracks by date
     */
    getGroupedTracks(state) {
        const filtered = this.getFilteredTracks(state);
        const grouped = {};

        filtered.forEach(track => {
            const date = track.activity_date ? track.activity_date.split(' ')[0] : 'unknown';
            if (!grouped[date]) {
                grouped[date] = [];
            }
            grouped[date].push(track);
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

    /**
     * Filter tracks by time period
     */
    getFilteredTracks(state) {
        if (state.tracksFilter === 'all') {
            return state.userTracks;
        }

        const now = new Date();
        const filterDate = new Date();

        if (state.tracksFilter === 'week') {
            filterDate.setDate(now.getDate() - 7);
        } else if (state.tracksFilter === 'month') {
            filterDate.setMonth(now.getMonth() - 1);
        }

        return state.userTracks.filter(track => {
            if (!track.activity_date)
                return false;
            const trackDate = new Date(track.activity_date);
            return trackDate >= filterDate;
        });
    },

    /**
     * Format date header (Dzisiaj, Wczoraj, etc.)
     */
    formatDateHeader(dateKey) {
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

    /**
     * Format time from datetime (14:30)
     */
    formatTime(datetime) {
        if (!datetime)
            return '';
        const date = new Date(datetime);
        return date.toLocaleTimeString('pl-PL', {hour: '2-digit', minute: '2-digit'});
    },

    /**
     * Get source label
     */
    getSourceLabel(source) {
        const labels = {
            'gpx_upload': 'GPX',
            'strava': 'STRAVA',
            'garmin': 'GARMIN',
            'komoot': 'KOMOOT',
            'user_upload': 'UPLOAD',
            'live_tracking': 'LIVE',
            'admin_upload': 'OFICJALNE',
            'planner': 'PLANNER'
        };
        return labels[source] || 'GPX';
    },

    /**
     * Get source color
     */
    getSourceColor(source) {
        const colors = {
            'gpx_upload': '#6366f1',
            'strava': '#fc4c02',
            'garmin': '#007CC3',
            'komoot': '#6AA127',
            'user_upload': '#8b5cf6',
            'live_tracking': '#10b981',
            'admin_upload': '#ef4444',
            'planner': '#f59e0b'
        };
        return colors[source] || '#6366f1';
    }
};

//console.log('✅ Tracks module loaded');