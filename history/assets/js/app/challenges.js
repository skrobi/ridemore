/* ============================================================================
 PLIK: assets/js/app/challenges.js
 CHALLENGES MODULE - User challenges & progress
 
 REFACTORED + DEBUG MODE
 ============================================================================ */

window.ChallengesModule = {

    // ========================================================================
    // LOAD USER CHALLENGES (Podjęte wyzwania)
    // ========================================================================

    async loadUserChallenges(state) {


        state.loadingChallenges = true;

        try {
            const url = window.APP_CONFIG.api(`challenges/user-progress.php?user_local_id=${state.user.local_id}`);

            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            const response = await fetch(url, {headers});
            const data = await response.json();

            if (data.success) {
                // Filtruj tylko started challenges
                // Filtruj WSZYSTKIE (nie tylko started && !completed)
                state.userChallenges = [];

                data.data.groups.forEach(group => {
                    group.challenges.forEach(ch => {
                        if (ch.started) {  // ✅ Wszystkie podjęte (w trakcie + ukończone)
                            state.userChallenges.push({
                                ...ch,
                                group_name: group.name,
                                group_icon: group.icon,
                                group_color: group.color,
                                image_url: ch.image_url
                            });
                        }
                    });
                });

                state.challengeStats = data.data.stats;

            } else {
                console.error('❌ API returned error:', data.error);
            }
        } catch (error) {
            console.error('❌ Failed to load user challenges:', error);
        } finally {
            state.loadingChallenges = false;
            //console.log('🔄 loadUserChallenges() END');
        }
    },

    async loadAvailableChallenges(state) {

        state.loadingChallenges = true;

        try {
            const url = window.APP_CONFIG.api(`challenges/user-progress.php?user_local_id=${state.user.local_id}`);

            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                // ✅ Zapisz oryginał (BACKUP)
                state._originalChallengeGroups = JSON.parse(JSON.stringify(data.data.groups));

                // Zapisz całe grupy (do accordion)
                state.challengeGroups = data.data.groups;

                // Flatten wszystkie challenges dla search
                state.availableChallenges = [];
                data.data.groups.forEach(group => {
                    group.challenges.forEach(ch => {
                        state.availableChallenges.push({
                            ...ch,
                            group_name: group.name,
                            group_icon: group.icon,
                            group_color: group.color,
                            image_url: ch.image_url
                        });
                    });
                });

                state.filteredChallenges = state.availableChallenges;

                //console.log(`✅ Loaded ${state.availableChallenges.length} available challenges`);
                //console.log('  - challengeGroups:', state.challengeGroups);
            } else {
                console.error('❌ API returned error:', data.error);
            }
        } catch (error) {
            console.error('❌ Failed to load available challenges:', error);
        } finally {
            state.loadingChallenges = false;
            //console.log('🔄 loadAvailableChallenges() END');
        }
    },

    searchChallenges(state, query) {

        if (!query || query.length < 2) {
            // ✅ PRZYWRÓĆ Z BACKUPU
            state.challengeGroups = JSON.parse(JSON.stringify(state._originalChallengeGroups));
            state.filteredChallenges = state.availableChallenges;

            return;
        }

        const q = query.toLowerCase();

        // Filtruj płaską listę
        state.filteredChallenges = state.availableChallenges.filter(ch =>
            ch.name.toLowerCase().includes(q) ||
                    ch.group_name.toLowerCase().includes(q) ||
                    (ch.description && ch.description.toLowerCase().includes(q))
        );

        // ✅ Filtruj z oryginału (nie z nadpisanego!)
        state.challengeGroups = state._originalChallengeGroups
                .map(group => ({
                        ...group,
                        challenges: group.challenges.filter(ch =>
                            ch.name.toLowerCase().includes(q) ||
                                    ch.group_name?.toLowerCase().includes(q) ||
                                    (ch.description && ch.description.toLowerCase().includes(q))
                        )
                    }))
                .filter(group => group.challenges.length > 0);

    },

    // ========================================================================
    // LOAD CHALLENGE DETAILS (dla details panel)
    // ========================================================================

    async loadChallengeDetails(state, challengeId) {

        state.loadingChallengeDetails = true;
        state.selectedChallenge = null;

        try {
            const url = window.APP_CONFIG.api(
                    `challenges/details.php?challenge_id=${challengeId}&user_local_id=${state.user.local_id}`
                    );


            const response = await fetch(url);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();


            if (data.success) {
                state.selectedChallenge = data.data;

            } else {
                console.error('❌ Failed to load details:', data.error);
                alert('Nie udało się załadować szczegółów wyzwania: ' + data.error);
            }
        } catch (error) {
            console.error('❌ Challenge details error:', error);
            alert('Błąd połączenia: ' + error.message);
        } finally {
            state.loadingChallengeDetails = false;
            //console.log('🔄 loadChallengeDetails() END');
        }
    },

    // ========================================================================
    // START CHALLENGE (Podejmuję się)
    // ========================================================================

    async startChallenge(state, challengeId) {
        //console.log('🔄 startChallenge() START');
        //console.log('  - challengeId:', challengeId);
        //console.log('  - user_local_id:', state.user.local_id);

        try {
            const url = window.APP_CONFIG.api('challenges/start.php');
            //console.log('  - Calling:', url);

            const body = {
                user_local_id: state.user.local_id,
                challenge_id: challengeId
            };
            //console.log('  - Body:', body);

            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            });

            const data = await response.json();
            //console.log('  - Response:', data);

            if (data.success) {
                //console.log('✅ Challenge started:', challengeId);

                // Reload both lists
                await this.loadUserChallenges(state);
                await this.loadAvailableChallenges(state);

                // If details panel open - reload details
                if (state.selectedChallenge && state.selectedChallenge.challenge_id === challengeId) {
                    //console.log('  - Reloading details panel...');
                    await this.loadChallengeDetails(state, challengeId);
                }

                // Switch to "Podjęte" tab
                state.activeChallengeTab = 'started';

                alert('✅ Wyzwanie podjęte! Powodzenia!');
                return true;
            } else {
                console.error('❌ Start failed:', data.error);
                alert('❌ ' + (data.error || 'Nie udało się podjąć wyzwania'));
            }
        } catch (error) {
            console.error('❌ Failed to start challenge:', error);
            alert('❌ Błąd podczas podejmowania wyzwania: ' + error.message);
        } finally {
            //console.log('🔄 startChallenge() END');
        }
        return false;
    },

    // ========================================================================
    // OPEN CHALLENGE DETAILS (wrapper for UI)
    // ========================================================================

    openChallengeDetails(state, challengeId) {
        //console.log('📖 openChallengeDetails()');

        if (!challengeId) {
            console.error('❌ Invalid challengeId:', challengeId);
            alert('Błąd: Nieprawidłowe ID wyzwania');
            return;
        }

        // Load details via API
        this.loadChallengeDetails(state, challengeId);

        if (!window._seoHydrate) {
            const challenge = state.availableChallenges.find(c => c.challenge_id === challengeId)
                    || state.userChallenges.find(c => c.challenge_id === challengeId);

            if (challenge && challenge.slug) {
                history.pushState(
                        {type: 'challenge', id: challengeId},
                        '',
                        `/challenge/${challenge.slug}`
                        );
            }
        }
    },

    // ========================================================================
    // SWITCH TAB
    // ========================================================================

    switchTab(state, tab) {
        //console.log(`📑 switchTab(): ${tab}`);
        state.activeChallengeTab = tab;
    },

    // ========================================================================
    // VIEW CHALLENGE ROUTES
    // ========================================================================

    async loadAllChallengeRoutes(state) {
        //console.log('📄 loadAllChallengeRoutes() START');

        if (!state.selectedChallenge) {
            console.warn('❌ No challenge selected');
            return;
        }

        //console.log('  - challenge_id:', state.selectedChallenge.challenge_id);
        //console.log('  - user_local_id:', state.user.local_id);

        state.loadingChallengeRoutes = true;

        try {
            // ✅ NOWE: Użyj auth_token jeśli istnieje
            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            const url = window.APP_CONFIG.api(
                    `challenges/routes.php?challenge_id=${state.selectedChallenge.challenge_id}&user_local_id=${state.user.local_id}`
                    );
            //console.log('  - Calling:', url);

            const response = await fetch(url, {headers});
            const data = await response.json();

            //console.log('  - Response:', data);

            if (data.success) {
                state.selectedChallenge.routes = data.data.routes;
                state.selectedChallenge.has_more_routes = false;

                //console.log(`✅ Loaded all ${data.data.routes.length} routes`);
            } else {
                console.error('❌ Failed:', data.error);
            }
        } catch (error) {
            console.error('❌ Failed to load all routes:', error);
        } finally {
            state.loadingChallengeRoutes = false;
            //console.log('📄 loadAllChallengeRoutes() END');
        }
    },

    viewChallengeRoutes(state, challengeId) {
        //console.log('📋 viewChallengeRoutes()', challengeId);

        if (!challengeId) {
            console.error('❌ Missing challengeId');
            return;
        }

        // Jeśli nie ma selectedChallenge lub inny challenge
        if (!state.selectedChallenge || state.selectedChallenge.challenge_id !== challengeId) {
            //console.log('  - Loading details first');
            this.loadChallengeDetails(state, challengeId).then(() => {
                state.showChallengeRoutes = true;
                this.loadAllChallengeRoutes(state);
            });
            return;
        }

        // Mamy już details
        state.showChallengeRoutes = true;

        // Załaduj wszystkie trasy jeśli jeszcze nie ma
        if (!state.selectedChallenge.routes || state.selectedChallenge.routes.length <= 3) {
            this.loadAllChallengeRoutes(state);
        }
    },

    openRanking(state) {
        if (!state.selectedChallenge) {
            console.error('No challenge selected');
            return;
        }

        openRankingModal(
                state.selectedChallenge.challenge_id,
                state.selectedChallenge.name,
                state.user.local_id
                );
    },

    // ========================================================================
    // FILTER CHALLENGE ROUTES
    // ========================================================================

    getFilteredChallengeRoutes(state) {
        if (!state.selectedChallenge?.routes) {
            //console.log('⚠️ No routes to filter');
            return [];
        }

        const filter = state.challengeRoutesFilter;
        //console.log('🔍 Filtering routes by:', filter);

        switch (filter) {
            case 'completed':
                return state.selectedChallenge.routes.filter(r => r.completed);
            case 'remaining':
                return state.selectedChallenge.routes.filter(r => !r.completed);
            default:
                return state.selectedChallenge.routes;
        }
    }
};
