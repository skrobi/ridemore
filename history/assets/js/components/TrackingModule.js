/* ============================================================================
 TRACKING MODULE - GPS tracking z OwnTracks
 ============================================================================ */

window.TrackingModule = {

    /**
     * Sprawdź czy użytkownik ma aktywną sesję tracking
     */
    async checkActiveSession(app) {
        if (!app.user.token)
            return;

        const batchId = localStorage.getItem('active_batch_id');
        if (!batchId) {
            app.trackingActive = false;
            return;
        }

        try {
            const response = await fetch(window.APP_CONFIG.api('tracking/status.php'), {
                headers: {
                    'Authorization': 'Bearer ' + app.user.token,
                    'Content-Type': 'application/json'
                },
                method: 'POST',
                body: JSON.stringify({batch_id: batchId})
            });

            const data = await response.json();

            if (data.success && data.data.is_active) {
                app.trackingActive = true;
                app.currentBatchId = batchId;
                app.currentTrackingRaceId = data.data.race_id;
            } else {
                // Sesja nieaktywna - wyczyść
                localStorage.removeItem('active_batch_id');
                app.trackingActive = false;
                app.currentBatchId = null;
            }

        } catch (error) {
            console.error('Check session error:', error);
            // Błąd - wyczyść żeby nie blokować
            localStorage.removeItem('active_batch_id');
            app.trackingActive = false;
        }
    },

    /**
     * Otwórz modal trackingu
     */
    async startGPSTracking(app, challengeId) {
        // Sprawdź czy ma już sesję
        if (app.trackingActive && app.currentBatchId) {
            // ✅ Pobierz pełne dane sesji (z configiem)
            const status = await this.getTrackingStatus(app);
            
            if (status && status.is_active) {
                
                // ✅ WALIDACJA: Czy tracking jest dla TEGO wyzwania?
                if (status.race_id === challengeId) {
                    // ✅ TAK - pokaż modal z aktywną sesją
                    app.trackingConfigModal = {
                        show: true,
                        batch_id: status.batch_id,
                        is_active: true,
                        deep_link: status.deep_link,
                        config_json: status.config_json,
                        qr_code_url: status.qr_code_url,
                        challenge_name: status.challenge_name,
                        wrong_challenge: false
                    };
                    return;
                    
                } else {
                    // ❌ NIE - ma aktywny tracking dla INNEGO wyzwania!
                    app.trackingConfigModal = {
                        show: true,
                        batch_id: status.batch_id,
                        is_active: true,
                        deep_link: status.deep_link,
                        config_json: status.config_json,
                        qr_code_url: status.qr_code_url,
                        challenge_name: status.challenge_name,
                        wrong_challenge: true, // ✅ Flaga
                        current_race_id: status.race_id,
                        requested_race_id: challengeId
                    };
                    return;
                }
            }
        }

        // Brak sesji LUB sesja nieaktywna - pokaż modal z przyciskiem START
        app.trackingConfigModal = {
            show: true,
            batch_id: null,
            is_active: false,
            deep_link: null,
            config_json: null,
            wrong_challenge: false
        };
    },

    /**
     * Zakończ tracking
     */
    async stopGPSTracking(app) {
        if (!app.currentBatchId)
            return;

        if (!confirm('Czy na pewno chcesz zakończyć tracking? Trasa zostanie zapisana.')) {
            return;
        }

        app.trackingLoading = true;

        try {
            const response = await fetch(window.APP_CONFIG.api('tracking/stop.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + app.user.token
                },
                body: JSON.stringify({
                    batch_id: app.currentBatchId
                })
            });

            const data = await response.json();

            if (data.success) {
                app.trackingActive = false;
                app.currentBatchId = null;
                app.currentTrackingRaceId = null;
                localStorage.removeItem('active_batch_id');

                // ✅ Zamknij modal
                app.trackingConfigModal.show = false;

                // Odśwież listę tras
                if (window.TracksModule?.loadUserTracks) {
                    await window.TracksModule.loadUserTracks(app);
                }

                alert('✅ Tracking zakończony! Trasa zostanie przetworzona za kilka minut.');

            } else {
                throw new Error(data.message || 'Błąd zatrzymywania');
            }

        } catch (error) {
            console.error('Stop tracking error:', error);
            alert('Błąd: ' + error.message);
        } finally {
            app.trackingLoading = false;
        }
    },

    /**
     * Otwórz deep link (mobile) lub skopiuj (desktop)
     */
    openDeepLink(app) {
        if (!app.trackingConfigModal.deep_link)
            return;

        // Sprawdź czy mobile
        if (this.isMobileDevice()) {
            // Mobile - otwórz deep link
            window.location.href = app.trackingConfigModal.deep_link;

            // Info że aplikacja się otworzy
            setTimeout(() => {
                alert('✅ Jeśli OwnTracks nie otworzył się automatycznie, skopiuj konfigurację ręcznie.');
            }, 1000);

        } else {
            // Desktop - skopiuj link
            this.copyConfig(app);
            alert('📋 Konfiguracja skopiowana! Otwórz ją na telefonie lub użyj przycisku "Kopiuj JSON" poniżej.');
        }
    },

    /**
     * Skopiuj konfigurację JSON
     */
    async copyConfig(app) {
        if (!app.trackingConfigModal.config_json)
            return;

        try {
            await navigator.clipboard.writeText(app.trackingConfigModal.config_json);
            alert('✅ Konfiguracja skopiowana do schowka');
        } catch (error) {
            console.error('Copy error:', error);
            // Fallback
            const textarea = document.createElement('textarea');
            textarea.value = app.trackingConfigModal.config_json;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            alert('✅ Konfiguracja skopiowana');
        }
    },

    /**
     * Sprawdź czy to urządzenie mobilne
     */
    isMobileDevice() {
        return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    },

    /**
     * Utwórz nową sesję trackingu (bez otwierania deep linka)
     */
    async createTrackingSession(app, challengeId) {
        app.trackingLoading = true;

        try {
            const response = await fetch(window.APP_CONFIG.api('tracking/start.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + app.user.token
                },
                body: JSON.stringify({
                    race_id: challengeId
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Błąd tworzenia sesji');
            }

            // Zapisz stan
            app.trackingActive = true;
            app.currentBatchId = data.data.batch_id;
            app.currentTrackingRaceId = challengeId;
            localStorage.setItem('active_batch_id', data.data.batch_id);

            // Zaktualizuj modal - teraz pokaże przyciski OTWÓRZ + ZAKOŃCZ
            app.trackingConfigModal.batch_id = data.data.batch_id;
            app.trackingConfigModal.is_active = true;
            app.trackingConfigModal.deep_link = data.data.deep_link;
            app.trackingConfigModal.config_json = data.data.config_json;
            app.trackingConfigModal.wrong_challenge = false;

        } catch (error) {
            console.error('Create session error:', error);
            alert('Błąd: ' + error.message);
        } finally {
            app.trackingLoading = false;
        }
    },

    /**
     * Pobierz status aktywnego trackingu
     */
    async getTrackingStatus(app) {
        if (!app.currentBatchId || !app.user.token)
            return null;

        try {
            const response = await fetch(window.APP_CONFIG.api('tracking/status.php'), {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + app.user.token,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    batch_id: app.currentBatchId
                })
            });

            const data = await response.json();
            
            if (data.success) {
                console.log('📡 Status response:', data.data);
                return data.data;
            }
            
            return null;

        } catch (error) {
            console.error('Get status error:', error);
            return null;
        }
    }
};
