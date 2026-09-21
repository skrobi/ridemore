/* ============================================================================
 ROUTES MODULE - COMPLETE WITH SEGMENTS VISUALIZATION
 Version: 4.0
 
 NEW in v4.0:
 - Route segments visualization on map
 - Completion progress display
 - Challenge/official routes highlighting
 ============================================================================ */

window.RoutesModule = {

    selectedRouteId: null,
    routeDetails: null,
    loadingRouteDetails: false,
    highlightedRouteId: null,
    segmentsLayer: null, // Layer for completed/incomplete segments

    // ========================================================================
    // LOAD & DISPLAY ROUTES
    // ========================================================================

    async openRouteDetails(state, routeId, render = true) {
        routeId = parseInt(routeId);
        if (!routeId || isNaN(routeId)) {
            console.error('❌ Invalid route ID:', routeId);
            return;
        }

        // ✅ DODAJ DEBOUNCE
        if (state._detailsTimeout) {
            console.warn('⚠️ Duplicate call blocked');
            return;
        }

        state._detailsTimeout = true;

        setTimeout(() => {
            state._detailsTimeout = false;
        }, 300);

        // ✅ Zapobiegaj loadowaniu tej samej trasy
        if (state.loadingRouteDetails) {
            console.warn('⚠️ Already loading, skipping');
            return;
        }

        // Zapobiegaj loadowaniu tej samej trasy dwa razy
        if (state.selectedRoute === routeId && state.routeDetails?.route_id === routeId) {
            //console.log('ℹ️ Route already loaded');
            return;
        }

        //console.log('🎯 Opening route details:', routeId); // ← DODAJ TO

        state.selectedRoute = routeId;
        state.loadingRouteDetails = true;

        try {
            const token = localStorage.getItem('auth_token');
            const userLocalId = localStorage.getItem('user_local_id');

            let url = `${window.APP_CONFIG.apiUrl}/routes/details.php?id=${routeId}&render=${render ? 1 : 0}`;

            if (userLocalId) {
                url += `&user_local_id=${userLocalId}`;
            }

            const headers = {'Content-Type': 'application/json'};
            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }

            const response = await fetch(url, {method: 'GET', headers});

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load');
            }

            state.routeDetails = data.data;

            // ✅ NOWE: Load coverage if challenge route
            if (data.data.route_purpose === 'challenge') {
                window.RouteCoverageVisualizer.load(routeId, userLocalId);
            }

            if (window.mapManager) {
                window.mapManager.showRoute(routeId);
            }

            return data.data;

        } catch (error) {
            console.error('❌ Load failed:', error);
            state.routeDetails = null;
            alert('Nie udało się załadować trasy');
            return null;

        } finally {
            state.loadingRouteDetails = false;
    }
    },

    waitForContainer(id, callback, timeout = 3000) {
        const startTime = Date.now();

        const checkContainer = () => {
            const container = document.getElementById(id);

            if (container) {
                //console.log('✅ Container found');
                callback();
            } else if (Date.now() - startTime < timeout) {
                requestAnimationFrame(checkContainer);
            } else {
                console.error('❌ Container not found within timeout');
            }
        };

        checkContainer();
    },

    closeRouteDetails(state) {
        state.selectedRoute = null;
        state.routeDetails = null;
        state.loadingRouteDetails = false;

        // Deselect na mapie
        if (window.mapManager) {
            window.mapManager.deselectAllRoutes();
        }

        // Cleanup segments
        if (window.RouteSegmentsUI) {
            window.RouteSegmentsUI.destroy();
        }

        if (window.RouteCoverageVisualizer) {
            window.RouteCoverageVisualizer.destroy();
        }

        if (window.RouteSegmentsLayer) {
            window.RouteSegmentsLayer.clear(state.map);
        }

        //console.log('✅ Route details closed');
    },
    // W RoutesModule w routes.js, dodaj nową metodę:

    async showChallengeCoverage(state, routeId) {
        //console.log('📊 Loading challenge coverage for:', routeId);

        // ✅ Validate route is challenge/reference
        if (!state.routeDetails) {
            console.error('❌ No route details loaded');
            return;
        }

        const purpose = state.routeDetails.route_purpose;
        if (!['challenge', 'reference'].includes(purpose)) {
            alert('Ta funkcja działa tylko dla tras wyzwań');
            return;
        }

        // ✅ Check user authenticated
        if (!state.user.local_id) {
            alert('Musisz być zalogowany, aby zobaczyć pokrycie');
            return;
        }

        // ✅ Show loading state
        const btn = event?.target;
        const originalHTML = btn?.innerHTML;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `
            <svg class="animate-spin" style="display: inline-block; width: 18px; height: 18px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            ŁADOWANIE...
        `;
        }

        try {
            // ✅ Pass user_local_id from state
            await window.RouteCoverageVisualizer.load(routeId, state.user.local_id);

            //console.log('✅ Coverage loaded and displayed');

        } catch (error) {
            console.error('❌ Coverage error:', error);
            alert(`Nie udało się załadować pokrycia:\n${error.message || 'Nieznany błąd'}`);

        } finally {
            // ✅ Restore button
            if (btn && originalHTML) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }
    },

    highlightRouteOnMap(routeId) {
        // TODO: Implement map highlight
    },

    unhighlightRoute(routeId) {
        // TODO: Remove map highlight
    },

    showOnMap(routeId) {
        if (window.mapManager) {
            window.mapManager.showRoute(routeId);
        }
    },

    // ========================================================================
    // DELETE ROUTE
    // ========================================================================

    async deleteRoute(state, routeId) {
        if (!confirm('⚠️ Czy na pewno chcesz usunąć tę trasę?\n\nTej operacji nie można cofnąć.')) {
            return;
        }

        const token = localStorage.getItem('auth_token');
        if (!token) {
            alert('❌ Musisz być zalogowany');
            return;
        }

        try {
            const response = await fetch(
                    `${window.APP_CONFIG.apiUrl}/routes/delete.php?id=${routeId}`,
                    {method: 'DELETE', headers: {'Authorization': `Bearer ${token}`}}
            );

            const data = await response.json();

            if (data.success) {
                alert('✅ Trasa usunięta');

                // ✅ ZAWSZE zamknij details
                this.closeRouteDetails(state);

                // ✅ ZAWSZE reload tracks (nawet jeśli panel nieaktywny)
                await window.TracksModule.loadUserTracks(state);

                // ✅ Jeśli library otwarty - też reload
                if (state.activePanel === 'library') {
                    await window.LibraryModule.applyFilters(state);
                }

                // ✅ Jeśli był w challenges - reload
                if (state.activePanel === 'challenges') {
                    await window.ChallengesModule.loadUserChallenges(state);
                }

            } else {
                alert('❌ ' + (data.error || 'Błąd usuwania'));
            }
        } catch (error) {
            console.error('❌ Delete failed:', error);
            alert('❌ Błąd: ' + error.message);
        }
    },


    // ========================================================================
    // EDIT ROUTE
    // ========================================================================

    async editRoute(state, routeId) {
        if (state.availableLayers.length === 0) {
            await this.loadAvailableLayers(state);
        }

        if (state.routeDetails && state.routeDetails.route_id === routeId) {

            // ✅ Load layer assignments
            const layerData = await this.loadRouteLayerAssignments(state, routeId);
            //console.log('✅ Layer data loaded:', layerData);

            // ✅ Extract layer_ids (może być różny format)
            let layerIds = [];

            if (Array.isArray(layerData)) {
                // Format: [22, 24, 25]
                layerIds = layerData.map(id => parseInt(id));
            } else if (layerData && layerData.layer_ids) {
                // Format: {route_id: 84, layer_ids: [22, 24]}
                layerIds = layerData.layer_ids.map(id => parseInt(id));
            }

            //console.log('✅ Extracted layer IDs:', layerIds);

            // ✅ Load photos (skip if API doesn't exist yet)
            try {
                await this.loadRoutePhotos(state, routeId);
            } catch (e) {
                console.warn('⚠️ Photos API not available yet:', e);
                state.editPhotos = [];
            }

            // ✅ Parse road_types_json using Utils
            const roadTypes = window.Utils.roadTypes.parse(state.routeDetails.road_types_json);

            // ✅ Set editData
            state.editData = {
                route_id: routeId,
                name: state.routeDetails.name || '',
                description: state.routeDetails.description || '',
                tagline: state.routeDetails.tagline || '',
                route_type: state.routeDetails.route_type || '',
                difficulty_level: state.routeDetails.difficulty_level || '',
                estimated_time_hours: state.routeDetails.estimated_time_hours ?
                        parseFloat(state.routeDetails.estimated_time_hours) : null,
                visibility: state.routeDetails.visibility || 'private',

                // ✅ Use extracted layerIds
                layer_ids: layerIds,

                distance_km: parseFloat(state.routeDetails.distance_km) || 0,
                ascent_m: parseInt(state.routeDetails.ascent_m) || 0,
                descent_m: parseInt(state.routeDetails.descent_m) || 0,
                segment_count: parseInt(state.routeDetails.segment_count) || 0,
                views_count: parseInt(state.routeDetails.views_count) || 0,
                gpx_file_path: state.routeDetails.gpx_file_path || null
            };

            state.roadTypes = roadTypes;
            state.editTab = 'basic';
            state.showEditModal = true;

            //console.log('📝 Edit data loaded');
            //console.log('   Name:', state.editData.name);
            //console.log('   Layer IDs:', state.editData.layer_ids);
            //console.log('🛣️ Road types:', state.roadTypes);
        }
    },

    async loadRouteLayerAssignments(state, routeId) {
        try {
            const url = window.APP_CONFIG.api(`routes/layer_assignments.php?route_id=${routeId}`);
            //console.log('🔗 Loading layer assignments from:', url);

            const response = await fetch(url);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            //console.log('📦 Layer assignments response:', data);

            if (data.success) {
                return data.data; // {route_id: 84, layer_ids: [22]}
            }

            console.warn('⚠️ API returned success=false');
            return {route_id: routeId, layer_ids: []};

        } catch (error) {
            console.error('❌ Failed to load layer assignments:', error);
            return {route_id: routeId, layer_ids: []};
        }
    },

// ============================================================================
// SAVE EDIT - ENHANCED
// ============================================================================

    async saveEdit(state) {
        // ✅ Validate required fields
        if (!state.editData.name) {
            state.editError = 'Nazwa jest wymagana';
            return;
        }

        // ✅ Validate road types using Utils
        const validationError = window.Utils.roadTypes.getValidationError(state.roadTypes);
        if (validationError) {
            state.editError = validationError;
            return;
        }

        state.editingRoute = true;
        state.editError = null;

        try {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                state.editError = 'Musisz być zalogowany';
                state.editingRoute = false;
                return;
            }

            // ✅ Prepare payload
            const payload = {
                // Routes table fields
                name: state.editData.name,
                visibility: state.editData.visibility,

                // Routes_meta fields
                description: state.editData.description || null,
                tagline: state.editData.tagline || null,
                route_type: state.editData.route_type || null,
                difficulty_level: state.editData.difficulty_level || null,
                estimated_time_hours: state.editData.estimated_time_hours || null,

                // Layer assignments
                layer_ids: state.editData.layer_ids || []
            };

            // ✅ Convert road types to JSON using Utils
            const roadTypesJSON = window.Utils.roadTypes.toJSON(state.roadTypes);
            if (roadTypesJSON) {
                payload.road_types_json = roadTypesJSON;
                //console.log('✅ Including road_types_json:', roadTypesJSON);
            } else {
                //console.log('ℹ️ Skipping road_types_json (not set or invalid)');
            }

            //console.log('  Sending update payload:', payload);

            const response = await fetch(
                    `${window.APP_CONFIG.apiUrl}/routes/update.php?id=${state.editData.route_id}`,
                    {
                        method: 'PATCH',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    }
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            //console.log('📥 Update response:', data);

            if (data.success) {
                await this.openRouteDetails(state, state.editData.route_id);

                // 2. Show success
                state.editSuccess = true;

                // 3. Close modal (resetuje editData)
                setTimeout(() => {
                    state.showEditModal = false;
                    state.editSuccess = false;
                    this.closeEditModal(state); // ← resetuje wszystko
                }, 1500);
            } else {
                state.editError = data.error || 'Błąd zapisu';
            }
        } catch (error) {
            console.error('❌ Edit failed:', error);
            state.editError = 'Błąd połączenia: ' + error.message;
        } finally {
            state.editingRoute = false;
        }
    },

    closeEditModal(state) {
        state.showEditModal = false;
        state.editError = null;
        state.editSuccess = false;
        state.editData = {
            route_id: null,
            name: '',
            description: '',
            tagline: '',
            route_type: '',
            difficulty_level: '',
            estimated_time_hours: null,
            visibility: 'private',
            layer_ids: [],
            distance_km: 0,
            ascent_m: 0,
            descent_m: 0,
            segment_count: 0,
            views_count: 0,
            gpx_file_path: null
        };
        state.roadTypes = {
            asphalt_pct: 0,
            gravel_pct: 0,
            trail_pct: 0
        };
        state.editPhotos = [];
        state.editTab = 'basic';
    },

    // ========================================================================
    // PHOTO MANAGEMENT
    // ========================================================================

    // W routes.js - ZMIEŃ metody photo na delegujące:

    /**
     * Load photos for route being edited
     */
    async loadRoutePhotos(state, routeId) {
        try {
            const photos = await window.PhotoModule.loadPhotos('route', routeId);
            state.editPhotos = photos;
        } catch (error) {
            console.error('Failed to load route photos:', error);
            state.editPhotos = [];
        }
    },

    /**
     * Set photo as primary (cover) for route
     */
    async setPrimaryPhoto(state, photoId) {
        try {
            await window.PhotoModule.setPrimary(photoId, 'route', state.editData.route_id);

            // Update local state
            state.editPhotos.forEach(p => {
                p.is_primary = (p.photo_id === photoId);
            });

        } catch (error) {
            console.error('❌ Set primary error:', error);
            alert('Nie udało się ustawić zdjęcia głównego');
        }
    },

    /**
     * Delete photo from route
     */
    async deletePhoto(state, photoId) {
        try {
            const deleted = await window.PhotoModule.remove(photoId);

            if (deleted) {
                state.editPhotos = state.editPhotos.filter(p => p.photo_id !== photoId);
            }

        } catch (error) {
            console.error('❌ Delete photo error:', error);
        }
    },

    /**
     * Open photo upload dialog
     */
    async openPhotoUpload(state) {
        if (!state.editData.route_id) {
            alert('Błąd: Brak ID trasy');
            return;
        }

        state.uploadingPhotos = true;
        state.photoUploadProgress = 0;

        try {
            const results = await window.PhotoModule.pickAndUpload(
                    'route',
                    state.editData.route_id,
                    {
                        multiple: true,
                        auto_create_poi: true,
                        is_public: state.editData.visibility === 'public' ? 1 : 0
                    }
            );

            if (!results) {
                //console.log('ℹ️ No files selected');
                return;
            }

            //console.log(`✅ Upload complete:`, results);

            await this.loadRoutePhotos(state, state.editData.route_id);

            if (results.failed_upload > 0 || results.failed_association > 0) {
                alert(
                        `✅ Dodano ${results.associated} zdjęć\n` +
                        `❌ Błędy uploadu: ${results.failed_upload}\n` +
                        `❌ Błędy przypisania: ${results.failed_association}`
                        );
            } else {
                state.editSuccess = true;
                setTimeout(() => state.editSuccess = false, 3000);
            }

        } catch (error) {
            console.error('❌ Photo upload error:', error);
            state.editError = 'Błąd uploadu zdjęć: ' + error.message;
            setTimeout(() => state.editError = null, 5000);

        } finally {
            state.uploadingPhotos = false;
            state.photoUploadProgress = 0;
        }
    },

    /**
     * Handle drag & drop photos
     */
    async handlePhotoDrop(state, event) {
        state.photoDropActive = false;

        if (!state.editData.route_id) {
            alert('Błąd: Brak ID trasy');
            return;
        }

        state.uploadingPhotos = true;

        try {
            const results = await window.PhotoModule.handleDrop(
                    event,
                    'route',
                    state.editData.route_id,
                    {
                        auto_create_poi: true,
                        is_public: state.editData.visibility === 'public' ? 1 : 0
                    }
            );

            if (!results) {
                return;
            }

            await this.loadRoutePhotos(state, state.editData.route_id);

            if (results.failed_upload > 0 || results.failed_association > 0) {
                alert(`✅ Dodano ${results.associated}/${results.total} zdjęć`);
            }

        } catch (error) {
            console.error('❌ Drop upload error:', error);
            alert('Błąd uploadu: ' + error.message);

        } finally {
            state.uploadingPhotos = false;
        }
    },

    // ========================================================================
    // SAVE EDIT - ENHANCED
    // ========================================================================



    async loadAvailableLayers(state) {
        try {
            const response = await fetch(window.APP_CONFIG.api('layers/config.php?active_only=1'));
            const data = await response.json();

            if (data.success && data.data.overlays) {
                state.availableLayers = data.data.overlays.map(layer => ({
                        layer_id: layer.layer_id,
                        name: layer.name,
                        slug: layer.slug
                    }));
            }
        } catch (error) {
            console.error('Failed to load layers:', error);
            state.availableLayers = [];
        }
    },

    // ========================================================================
    // RATING
    // ========================================================================

    openRatingModal(state, routeId) {
        //console.log(`⭐ Opening rating modal for route: ${routeId}`);

        state.ratingModal = {
            show: true,
            routeId: routeId,
            rating: 0,
            comment: '',
            submitting: false,
            error: null
        };

        state.showRatingModal = true;
    },

    setRating(state, value) {
        state.ratingModal.rating = value;
    },

    async submitRating(state) {
        if (state.ratingModal.rating === 0) {
            state.ratingModal.error = 'Wybierz ocenę';
            return;
        }

        state.ratingModal.submitting = true;
        state.ratingModal.error = null;

        try {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                state.ratingModal.error = 'Musisz być zalogowany';
                state.ratingModal.submitting = false;
                return;
            }

            const response = await fetch(
                    `${window.APP_CONFIG.apiUrl}/routes/ratings.php?id=${state.ratingModal.routeId}`,
                    {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            rating: state.ratingModal.rating,
                            comment: state.ratingModal.comment
                        })
                    }
            );

            const data = await response.json();

            if (data.success) {
                await this.openRouteDetails(state, state.ratingModal.routeId);
                state.showRatingModal = false;
                alert('✅ Ocena dodana!');
            } else {
                state.ratingModal.error = data.error || 'Błąd zapisu';
            }
        } catch (error) {
            console.error('❌ Rating failed:', error);
            state.ratingModal.error = 'Błąd połączenia: ' + error.message;
        } finally {
            state.ratingModal.submitting = false;
        }
    },

    closeRatingModal(state) {
        state.showRatingModal = false;
        state.ratingModal.error = null;
    },

    // ========================================================================
    // UTILITIES
    // ========================================================================

    copyRouteLink(routeId) {
        const url = `${window.location.origin}/?route=${routeId}`;
        navigator.clipboard.writeText(url).then(() => {
            //console.log('✅ Link copied:', url);

            // Visual feedback
            const btn = event?.target?.closest('button');
            if (btn) {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '✓ Skopiowano!';
                btn.style.background = '#10b981';
                btn.style.color = 'white';

                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.background = '';
                    btn.style.color = '';
                }, 2000);
            }
        }).catch(error => {
            console.error('Copy failed:', error);
            alert('Nie udało się skopiować linku');
        });
    },

    downloadGPX(routeId) {
        //console.log(`📥 Download GPX: ${routeId}`);
        window.location.href = `${window.APP_CONFIG.apiUrl}/routes/download.php?id=${routeId}`;
    }
};

// ============================================================================
// GLOBAL FUNCTIONS (for onclick handlers in HTML templates)
// ============================================================================

window.openRouteDetails = (routeId) => {
    const appEl = document.querySelector('[x-data="app"]');
    if (!appEl || !Alpine) {
        console.error('Alpine not ready');
        return;
    }
    const app = Alpine.$data(appEl);
    window.RoutesModule.openRouteDetails(app, routeId);
};

window.closeRouteDetails = () => {
    const appEl = document.querySelector('[x-data="app"]');
    if (!appEl || !Alpine)
        return;
    const app = Alpine.$data(appEl);
    window.RoutesModule.closeRouteDetails(app);
};

window.deleteRoute = (routeId) => {
    const appEl = document.querySelector('[x-data="app"]');
    if (!appEl || !Alpine) {
        console.error('Alpine not ready');
        return;
    }
    const app = Alpine.$data(appEl);
    window.RoutesModule.deleteRoute(app, routeId);
};


window.openEditModal = (routeId) => {
    const appEl = document.querySelector('[x-data="app"]');
    if (!appEl || !Alpine) {
        console.error('Alpine not ready');
        return;
    }
    const app = Alpine.$data(appEl);
    window.RoutesModule.editRoute(app, routeId);
};

window.copyRouteLink = (routeId) => {
    window.RoutesModule.copyRouteLink(routeId);
};

window.downloadGPX = (routeId) => {
    window.RoutesModule.downloadGPX(routeId);
};

window.openRatingModal = async (routeId) => {
    const appEl = document.querySelector('[x-data="app"]');
    if (!appEl || !Alpine) {
        console.error('Alpine not ready');
        return;
    }
    const app = Alpine.$data(appEl);
    window.RoutesModule.openRatingModal(app, routeId);
};


window.showChallengeCoverage = (routeId) => {
    //console.log('📊 Global handler: loading coverage for route:', routeId);

    const appEl = document.querySelector('[x-data="app"]');
    if (!appEl || !Alpine) {
        console.error('❌ Alpine not ready');
        return;
    }

    const app = Alpine.$data(appEl);

    // ✅ Use RoutesModule method with proper state
    window.RoutesModule.showChallengeCoverage(app, routeId)
            .then(() => console.log('✅ Coverage loaded'))
            .catch(err => {
                console.error('❌ Coverage error:', err);
                alert('Nie udało się załadować pokrycia');
            });
};