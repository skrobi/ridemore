/* ============================================================================
 LIBRARY MODULE - Route library & advanced search
 
 Version: 3.0
 - Supports both bbox (map) and parametric (library) search
 - Region filters with predefined bboxes
 - Advanced filters: distance, ascent, difficulty, rating
 - Sort options: rating, distance, difficulty, newest
 
 ============================================================================ */

window.LibraryModule = {

    /**
     * Search library - debounced
     */
    searchLibrary(state) {
        clearTimeout(state.searchTimeout);

        state.searchTimeout = setTimeout(() => {
            this.applyFilters(state);
        }, 500);
    },

    /**
     * Apply filters and search
     * Uses new /library/search.php endpoint with parametric search
     */
    async applyFilters(state) {
        state.loadingLibrary = true;

        try {
            const params = new URLSearchParams();

            // Text search
            if (state.librarySearch) {
                params.append('query', state.librarySearch);
            }

            // ✅ BBOX filter (from map OR region)
            if (state.filters.useMapBbox && state.filters.bbox) {
                // Use map bbox
                params.append('bbox', state.filters.bbox.join(','));
            }

            // Distance range
            if (state.filters.distance_min) {
                params.append('distance_min', state.filters.distance_min);
            }
            if (state.filters.distance_max) {
                params.append('distance_max', state.filters.distance_max);
            }

            // Ascent range
            if (state.filters.ascent_min) {
                params.append('ascent_min', state.filters.ascent_min);
            }
            if (state.filters.ascent_max) {
                params.append('ascent_max', state.filters.ascent_max);
            }

            // Difficulty
            if (state.filters.difficulty) {
                params.append('difficulty', state.filters.difficulty);
            }

            // Route type
            if (state.filters.route_type) {
                params.append('route_type', state.filters.route_type);
            }

            // Min rating
            if (state.filters.min_rating) {
                params.append('min_rating', state.filters.min_rating);
            }

            // Source filter
            if (state.filters.source) {
                params.append('source', state.filters.source);
            }

            // Sort order
            if (state.filters.sort) {
                params.append('sort', state.filters.sort);
            }

            // Pagination
            params.append('limit', state.libraryLimit || 50);
            params.append('offset', state.libraryOffset || 0);

            // User context for completion check
            const userLocalId = localStorage.getItem('user_local_id');
            if (userLocalId) {
                params.append('user_local_id', userLocalId);
            }

            const url = window.APP_CONFIG.api(`library/search.php?${params.toString()}`);
            //console.log('🔍 Fetching library routes:', url);

            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                //console.log('📦 Library API response:', {
                //    routesCount: data.data.routes.length,
                //    total: data.data.total,
                //    limit: data.data.limit,
                //    offset: data.data.offset,
                //    hasRegions: !!data.data.available_regions
                //});

                // Store routes (already flat objects, not GeoJSON)
                state.libraryRoutes = data.data.routes;
                state.libraryTotal = data.data.total;
                state.libraryLimit = data.data.limit;
                state.libraryOffset = data.data.offset;

                // Store available regions (for select dropdown)
                if (data.data.available_regions) {
                    state.availableRegions = data.data.available_regions;
                }

                //console.log(`✅ Library search: ${state.libraryRoutes.length} results (total: ${state.libraryTotal})`);

                // Debug first route
                if (state.libraryRoutes.length > 0) {
                    //console.log('📦 First route:', state.libraryRoutes[0]);
                    //console.log('   - route_id:', state.libraryRoutes[0].route_id);
                    //console.log('   - name:', state.libraryRoutes[0].name);
                    //console.log('   - distance:', state.libraryRoutes[0].distance_km);
                    //console.log('   - is_completed:', state.libraryRoutes[0].is_completed);
                }

            } else {
                console.error('❌ Library search failed:', data.error);
                state.libraryRoutes = [];
                state.libraryTotal = 0;
            }

        } catch (error) {
            console.error('❌ Library search error:', error);
            state.libraryRoutes = [];
            state.libraryTotal = 0;
        } finally {
            state.loadingLibrary = false;
        }
    },

    /**
     * Reset all filters
     */
    resetFilters(state) {
        state.filters = {
            useMapBbox: false, // ✅ DODAJ
            bbox: null,
            distance_min: null,
            distance_max: null,
            ascent_min: null,
            ascent_max: null,
            difficulty: null,
            route_type: null,
            min_rating: null,
            source: null,
            sort: 'rating'
        };
        state.librarySearch = '';
        state.libraryOffset = 0;

        this.applyFilters(state);
    },

    /**
     * Load more results (pagination)
     */
    async loadMore(state) {
        if (state.loadingLibrary)
            return;
        if (state.libraryRoutes.length >= state.libraryTotal)
            return;

        state.libraryOffset = state.libraryRoutes.length;

        //console.log(`📄 Loading more... offset: ${state.libraryOffset}`);

        // Temporarily store current routes
        const currentRoutes = [...state.libraryRoutes];

        await this.applyFilters(state);

        // Append new results to existing
        if (state.libraryRoutes.length > 0) {
            state.libraryRoutes = [...currentRoutes, ...state.libraryRoutes];
        }
    },

    /**
     * Change sort order
     */
    changeSort(state, sortBy) {
        state.filters.sort = sortBy;
        state.libraryOffset = 0;
        this.applyFilters(state);
    },

    /**
     * Handle file selection for upload
     */
    handleUploadFileSelect(state, event) {
        const file = event.target.files[0];
        if (!file)
            return;

        state.uploadData.file = file;

        // Auto-generate name from filename
        if (!state.uploadData.name) {
            state.uploadData.name = file.name
                    .replace('.gpx', '')
                    .replace(/[-_]/g, ' ')
                    .replace(/\b\w/g, l => l.toUpperCase());
        }

        //console.log('📁 File selected:', file.name, `(${(file.size / 1024 / 1024).toFixed(2)}MB)`);
    },

    /**
     * Upload route to library
     */
    async uploadRoute(state) {
        if (!state.uploadData.file) {
            state.uploadError = 'Wybierz plik GPX';
            return;
        }

        if (!state.uploadData.name) {
            state.uploadError = 'Podaj nazwę trasy';
            return;
        }

        state.uploadingRoute = true;
        state.uploadError = null;
        state.uploadStatus = 'Wysyłanie pliku...';

        const formData = new FormData();
        formData.append('file', state.uploadData.file);
        formData.append('name', state.uploadData.name);
        formData.append('description', state.uploadData.description || '');
        formData.append('route_type', state.uploadData.route_type || '');
        formData.append('difficulty', state.uploadData.difficulty || '');

        // ✅ Route purpose (default: user_shared)
        const routePurpose = state.uploadData.route_purpose || 'user_shared';

        // ✅ CRITICAL: Set correct source, visibility based on purpose
        if (routePurpose === 'reference') {
            // Official reference route
            formData.append('source', 'admin_upload');
            formData.append('route_purpose', 'reference');
            formData.append('visibility', 'public');
        } else {
            // User shared route (default)
            formData.append('source', 'user_published');
            formData.append('route_purpose', 'user_shared');
            formData.append('visibility', 'public');
        }

        // ✅ Always match segments (hidden from user)
        formData.append('match_segments', '1');

        // ✅ DEBUG
        //console.log('📤 Library upload:', {
        //    name: state.uploadData.name,
        //    route_purpose: routePurpose,
        //    visibility: 'public',
        //    source: routePurpose === 'reference' ? 'admin_upload' : 'user_published'
        //});

        try {
            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            const response = await fetch(window.APP_CONFIG.api('routes/upload.php'), {
                method: 'POST',
                body: formData,
                headers
            });

            const data = await response.json();

            if (data.success) {
                state.uploadSuccess = true;

                // ✅ FIX: Use routePurpose instead of undefined visibility
                if (routePurpose === 'reference') {
                    state.uploadSuccessMessage = `✅ Trasa "${state.uploadData.name}" dodana jako oficjalna!`;
                } else {
                    state.uploadSuccessMessage = `✅ Trasa "${state.uploadData.name}" dodana do biblioteki!`;
                }

                //console.log('✅ Route uploaded:', data.data);

                // Reset form after 2 sec
                setTimeout(() => {
                    state.showUploadModal = false;
                    this.resetUploadForm(state);

                    // ✅ Reload library (always public from library upload)
                    this.applyFilters(state);

                }, 2000);

            } else {
                state.uploadError = data.error || 'Błąd uploadu';
                console.error('❌ Upload failed:', data.error);
            }

        } catch (error) {
            console.error('❌ Upload failed:', error);
            state.uploadError = 'Błąd połączenia: ' + error.message;
        } finally {
            state.uploadingRoute = false;
            state.uploadStatus = '';
        }
    },

    /**
     * Reset upload form
     */
    resetUploadForm(state) {
        state.uploadData = {
            file: null,
            name: '',
            description: '',
            route_type: '',
            difficulty: '',
            route_purpose: 'user_shared'  // ✅ Default: user_shared (NOT private!)
        };
        state.uploadError = null;
        state.uploadSuccess = false;
        state.uploadSuccessMessage = '';
        state.uploadProgress = 0;
    },

    /**
     * Highlight route on map (card hover)
     */
    highlightRoute(routeId) {
        window.RouteHighlightModule.highlightRoute(routeId);
    },

    /**
     * Unhighlight route (card mouseout)
     */
    unhighlightRoute(routeId) {
        window.RouteHighlightModule.unhighlightRoute(routeId);
    },

    /**
     * Center map on route
     */
    async centerOnRoute(routeId) {
        await window.RouteHighlightModule.centerOnRoute(routeId);
    },

    /**
     * Show route on map (load + highlight + center)
     */
    async showRouteOnMap(state, routeId) {
        await window.RouteHighlightModule.showRouteOnMap(routeId);

        // ✅ Otwórz panel szczegółów trasy
        if (window.RoutesModule && window.RoutesModule.openRouteDetails) {
            await window.RoutesModule.openRouteDetails(state, routeId);
        }
    }

};

//console.log('✅ Library module v3.0 loaded (Advanced Search + Regions)');