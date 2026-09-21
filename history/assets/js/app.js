/* ============================================================================
 APP.JS - Main orchestrator
 ============================================================================ */

document.addEventListener('alpine:init', () => {
    Alpine.data('app', () => ({
            // Import state
            ...window.AppState,

            async init() {
                // ✅ GLOBALNY guard - zapobiega ponownej inicjalizacji
                if (window._appInitialized) {
                    return;
                }

                try {
                    await window.UserModule.initUser(this);
                    window.SettingsModule.loadSettings(this);

                    if (!localStorage.getItem('welcome_seen')) {
                        this.showWelcomeModal = true;
                    }

                    // Inicjalizuj mapę tylko raz
                    if (typeof initMap === 'function' && !this.map) {
                        this.map = await initMap(this);
                    }

                    if (this.user.token) {
                        await window.TrackingModule.checkActiveSession(this);
                    }

                    await Promise.all([
                        window.ChallengesModule.loadUserChallenges(this),
                        window.ChallengesModule.loadAvailableChallenges(this),
                        window.AchievementsModule.loadUserAchievements(this),
                        window.TracksModule.loadUserTracks(this),
                        window.FeedModule.loadFeed(this, 'global'),
                        window.FeedModule.loadNotifications(this),
                        window.PlannedModule.loadUserPlanned(this),
                        this.loadTopPanel()
                    ]);

                    if (typeof window.PoiCreatorModule !== 'undefined') {
                        window.PoiCreatorModule.init(this);
                        //console.log('✅ PoiCreatorModule initialized');
                    } else {
                        console.error('❌ PoiCreatorModule not loaded!');
                    }

                    //window.UserModule.checkAuthToken(this);
                    this.setupEventListeners();

                    // ✅ Oznacz GLOBALNIE (nie tylko this._initialized)
                    window._appInitialized = true;
                    this._initialized = true;

                    if (this.user.token) {
                        await window.IntegrationsModule.init(this);
                    }

                    if (window._seoHydrate) {
                        this.hydrateFromURL();
                    }


                } catch (error) {
                    console.error('❌ Initialization error:', error);
                    // ✅ Reset flag on error
                    window._appInitialized = false;
                    this._initialized = false;
                }
            },
            hydrateFromURL() {
                const hydrate = window._seoHydrate;

                if (!hydrate)
                    return;

                console.log('🔄 Hydrating from URL:', hydrate);

                if (hydrate.type === 'challenge') {
                    // Otwórz panel challenges
                    this.openPanel('challenges');

                    // Otwórz szczegóły challenge
                    setTimeout(() => {
                        this.openChallengeDetails(hydrate.id);
                    }, 300);

                } else if (hydrate.type === 'route') {
                    // Otwórz szczegóły route
                    setTimeout(() => {
                        this.openRouteDetails(hydrate.id);
                    }, 300);
                }

                // Clean up
                delete window._seoHydrate;
            },
            async refreshAllData() {
                await Promise.all([
                    window.PlannedModule.loadUserPlanned(this),
                    window.TracksModule.loadUserTracks(this),
                    window.ChallengesModule.loadUserChallenges(this),
                    window.AchievementsModule.loadUserAchievements(this)
                ]);
            },

            async loadUserPlanned() {
                await window.PlannedModule.loadUserPlanned(this);
            },

            async deletePlanned(routeId) {
                await window.PlannedModule.deletePlanned(this, routeId);
            },

            async showPlannedOnMap(routeId) {
                await window.PlannedModule.showPlannedOnMap(this, routeId);
            },

            getGroupedPlanned() {
                return window.PlannedModule.getGroupedPlanned(this);
            },

            formatPlannedDateHeader(dateKey) {
                return window.PlannedModule.formatPlannedDateHeader(dateKey);
            },

            formatPlannedTime(datetime) {
                return window.PlannedModule.formatPlannedTime(datetime);
            },
            // ========================================================================
            // WELCOME MODAL
            // ========================================================================

            closeWelcomeModal() {
                localStorage.setItem('welcome_seen', 'true');
                this.showWelcomeModal = false;
            },

            async openProfileModal() {
                await window.ProfileModule.openProfileModal(this);
            },

            closeProfileModal() {
                window.ProfileModule.closeProfileModal(this);
            },

            switchProfileTab(tab) {
                window.ProfileModule.switchTab(this, tab);
            },

            async updateProfile(tab) {
                await window.ProfileModule.updateProfile(this, tab);
            },

            async regenerateApiKey() {
                await window.ProfileModule.regenerateApiKey(this);
            },

            async copyApiKey() {
                await window.ProfileModule.copyApiKey(this);
            },

            connectIntegration(provider) {
                window.IntegrationsModule.connectProvider(provider);
            },

            async unlinkIntegration(provider) {
                await window.IntegrationsModule.unlinkProvider(this, provider);
            },

            // W Alpine.data('app', () => ({ ... })) dodaj:

            // ========================================================================
            // TRACKING
            // ========================================================================

            async startGPSTracking(challengeId) {
                await window.TrackingModule.startGPSTracking(this, challengeId);
            },

            async stopGPSTracking() {
                await window.TrackingModule.stopGPSTracking(this);
            },

            async createTrackingSession(challengeId) {
                await window.TrackingModule.createTrackingSession(this, challengeId);
            },

            openTrackingDeepLink() {
                window.TrackingModule.openDeepLink(this);
            },

            async copyTrackingConfig() {
                await window.TrackingModule.copyConfig(this);
            },

            closeTrackingConfigModal() {
                this.trackingConfigModal.show = false;
            },

            isMobileDevice() {
                return window.TrackingModule.isMobileDevice();
            },

            // ========================================================================
            // MOBILE POI CREATOR (FAB)
            // ========================================================================

            openMobilePoiCreator() {
                window.PoiCreatorModule.openMobilePoiCreator(this);
            },

            handleMobilePoiPhoto(event) {
                window.PoiCreatorModule.handleMobilePoiPhoto(this, event);
            },

            cleanup() {

                // Clear map
                if (window.mapManager && window.mapManager.map) {
                    window.mapManager.map.remove();
                    window.mapManager.initialized = false;
                    window.mapManager._eventsInitialized = false;
                }

                // Clear state
                window._appInitialized = false;
                this._initialized = false;

            },

            loadPoiSubtypes() {
                window.PoiCreatorModule.loadPoiSubtypes(this);
            },

            handlePoiPhotoSelect(event) {
                window.PoiCreatorModule.handlePoiPhotoSelect(this, event);
            },

            removePoiPhoto() {
                window.PoiCreatorModule.removePoiPhoto(this);
            },

            async submitNewPoi() {
                await window.PoiCreatorModule.submitNewPoi(this);
            },

            handleUploadClick() {
                window.IntegrationsModule.handleUploadClick(this);
            },
            openProviderImport(provider) {
                window.IntegrationsModule.openProviderImport(this, provider);
            },

            setupEventListeners() {
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        window.PanelsModule.closeAll(this);
                    }

                    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                        e.preventDefault();
                        window.PanelsModule.openPanel(this, 'library');
                    }
                });

                window.addEventListener('popstate', () => {
                    window.PanelsModule.closeAll(this);
                });

                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden && this.user.token) {
                        window.ChallengesModule.loadUserChallenges(this);
                    }
                });
            },

            // ========================================================================
            // PHOTO
            // ========================================================================

            openPhotoLightbox(photo) {
                this.photoLightbox = {
                    open: true,
                    url: photo.url,
                    caption: photo.caption,
                    exif: {
                        camera: photo.camera,
                        focal_length: photo.exif?.focal_length,
                        aperture: photo.exif?.aperture,
                        iso: photo.exif?.iso,
                        exposure_time: photo.exif?.exposure_time
                    }
                };
            },

            closePhotoLightbox() {
                this.photoLightbox.open = false;
            },

            // ========================================================================
            // RECOMMENDATIONS (NEW)
            // ========================================================================

            async loadTopPanel() {
                if (!this.map) {
                    console.warn('Map not ready');
                    return;
                }

                const bounds = this.map.getBounds();
                const bbox = [
                    bounds.getWest(),
                    bounds.getSouth(),
                    bounds.getEast(),
                    bounds.getNorth()
                ];

                await window.RecommendationsModule.loadAllRecommendations(this, bbox);
            },

            async randomRoute() {
                if (!this.map) {
                    console.warn('Map not ready');
                    return;
                }

                const bounds = this.map.getBounds();
                const bbox = [
                    bounds.getWest(),
                    bounds.getSouth(),
                    bounds.getEast(),
                    bounds.getNorth()
                ];

                await window.RecommendationsModule.randomRoute(this, bbox);
            },

            // W app.js - Alpine data() - DODAJ:

            highlightRouteOnMap(routeId) {
                window.RecommendationsModule.highlightRouteOnMap(routeId);
            },

            unhighlightRouteOnMap(routeId) {
                window.RecommendationsModule.unhighlightRouteOnMap(routeId);
            },

            centerOnRoute(routeId) {
                window.RecommendationsModule.centerOnRoute(routeId);
            },
            async loadTopRoutesInView(bbox) {
                await window.RoutesModule.loadTopRoutesInView(this, bbox);
            },

            async openRouteDetails(routeId) {
                await window.RoutesModule.openRouteDetails(this, routeId);
            },

            async showChallengeCoverage(routeId) {
                await window.RoutesModule.showChallengeCoverage(this, routeId);
            },

            openChallengeRanking() {
                window.ChallengesModule.openRanking(this);
            },

            highlightRoute(routeId) {
                window.RoutesModule.highlightRouteOnMap(routeId);
            },

            unhighlightRoute(routeId) {
                window.RoutesModule.unhighlightRoute(routeId);
            },

            downloadGPX(routeId) {
                window.RoutesModule.downloadGPX(routeId);
            },

            showOnMap(routeId) {
                window.RoutesModule.showOnMap(routeId);
            },

            editRoute(routeId) {
                window.RoutesModule.editRoute(this, routeId);
            },

            async saveEdit() {
                await window.RoutesModule.saveEdit(this);
            },

            closeEditModal() {
                window.RoutesModule.closeEditModal(this);
            },

            openRatingModal(routeId) {
                window.RoutesModule.openRatingModal(this, routeId);
            },

            setRating(value) {
                window.RoutesModule.setRating(this, value);
            },

            async submitRating() {
                await window.RoutesModule.submitRating(this);
            },

            closeRatingModal() {
                window.RoutesModule.closeRatingModal(this);
            },

            // ========================================================================
            // TRACKS
            // ========================================================================

            async loadUserTracks() {
                await window.TracksModule.loadUserTracks(this);
            },

            async handleGPXUpload(event) {
                await window.TracksModule.handleGPXUpload(this, event);
            },

            async deleteTrack(trackId) {
                await window.TracksModule.deleteTrack(this, trackId);
                await this.refreshAllData();
            },

            async showTrackOnMap(routeId) {
                await window.TracksModule.showTrackOnMap(this, routeId);
            },

            // TRACKS - Helper functions
            getGroupedTracks() {
                return window.TracksModule.getGroupedTracks(this);
            },

            formatDateHeader(dateKey) {
                return window.TracksModule.formatDateHeader(dateKey);
            },

            formatTime(datetime) {
                return window.TracksModule.formatTime(datetime);
            },

            getSourceLabel(source) {
                return window.TracksModule.getSourceLabel(source);
            },

            getSourceColor(source) {
                return window.TracksModule.getSourceColor(source);
            },

            // ========================================================================
            // CHALLENGES
            // ========================================================================

            async loadUserChallenges() {
                await window.ChallengesModule.loadUserChallenges(this);
            },

            async loadAvailableChallenges() {
                await window.ChallengesModule.loadAvailableChallenges(this);
            },

            searchChallenges() {
                window.ChallengesModule.searchChallenges(this, this.challengeSearch);
            },

            async startChallenge(challengeId) {
                await window.ChallengesModule.startChallenge(this, challengeId);
            },

            openChallengeDetails(challengeId) {
                window.ChallengesModule.openChallengeDetails(this, challengeId);
            },

            switchChallengeTab(tab) {
                window.ChallengesModule.switchTab(this, tab);
            },

            viewChallengeRoutes(challengeId) {
                if (!challengeId && this.selectedChallenge) {
                    challengeId = this.selectedChallenge.challenge_id;
                }
                window.ChallengesModule.viewChallengeRoutes(this, challengeId);
            },

            loadAllChallengeRoutes() {
                window.ChallengesModule.loadAllChallengeRoutes(this);
            },

            getFilteredChallengeRoutes() {
                return window.ChallengesModule.getFilteredChallengeRoutes(this);
            },

            // ========================================================================
            // ACHIEVEMENTS
            // ========================================================================

            async loadUserAchievements() {
                await window.AchievementsModule.loadUserAchievements(this);
            },

            async checkMyMedals() {
                await window.AchievementsModule.checkMyMedals(this);
            },

            openMedalModal(badge) {
                window.AchievementsModule.openMedalModal(this, badge);
            },

            closeMedalModal() {
                window.AchievementsModule.closeMedalModal(this);
            },

            async addToShowcase(levelId) {
                await window.AchievementsModule.addToShowcase(this, levelId);
            },

            async removeFromShowcase(levelId) {
                await window.AchievementsModule.removeFromShowcase(this, levelId);
            },

            shareAchievement(badge, platform) {
                window.AchievementsModule.shareAchievement(this, badge, platform);
            },

            getFilteredAchievements() {
                return window.AchievementsModule.getFilteredAchievements(this);
            },

            setAchievementFilter(filter) {
                window.AchievementsModule.setFilter(this, filter);
            },
            async toggleShowcase(levelId) {
                await window.AchievementsModule.toggleShowcase(this, levelId);
            },

            async addToShowcase(levelId) {
                await window.AchievementsModule.addToShowcase(this, levelId);
            },

            async removeFromShowcase(levelId) {
                await window.AchievementsModule.removeFromShowcase(this, levelId);
            },

            async shareAchievement(badge, platform) {
                await window.AchievementsModule.shareAchievement(this, badge, platform);
            },

            // ========================================================================
            // LIBRARY
            // ========================================================================

            searchLibrary() {
                window.LibraryModule.searchLibrary(this);
            },

            async applyFilters() {
                await window.LibraryModule.applyFilters(this);
            },

            handleUploadFileSelect(event) {
                window.LibraryModule.handleUploadFileSelect(this, event);
            },

            async uploadRoute() {
                await window.LibraryModule.uploadRoute(this);
            },

            resetUploadForm() {
                window.LibraryModule.resetUploadForm(this);
            },
            highlightLibraryRoute(routeId) {
                window.LibraryModule.highlightRoute(routeId);
            },

            unhighlightLibraryRoute(routeId) {
                window.LibraryModule.unhighlightRoute(routeId);
            },

            async centerOnLibraryRoute(routeId) {
                await window.LibraryModule.centerOnRoute(routeId);
            },

            async showLibraryRouteOnMap(routeId) {
                await window.LibraryModule.showRouteOnMap(this, routeId);
            },

            // ========================================================================
            // PANELS
            // ========================================================================

            openPanel(panel) {
                window.PanelsModule.openPanel(this, panel);
            },

            closeAll() {
                window.PanelsModule.closeAll(this);
            },

            onMapMove(bbox) {
                window.PanelsModule.onMapMove(this, bbox);
            },

            toggleMapLayers() {
                window.PanelsModule.toggleMapLayers(this);
            },

            findMe() {
                window.PanelsModule.findMe(this);
            },

            handlePhotoUpload(event) {
                window.PanelsModule.handlePhotoUpload(event);
            },

            // =================================
            // photos
            // ===========================

            async openPhotoUpload() {
                await window.RoutesModule.openPhotoUpload(this);
            },

            handlePhotoDrop(event) {
                window.RoutesModule.handlePhotoDrop(this, event);
            },

            async setPrimaryPhoto(photoId) {
                await window.RoutesModule.setPrimaryPhoto(this, photoId);
            },

            async deletePhoto(photoId) {
                await window.RoutesModule.deletePhoto(this, photoId);
            },

            editPhotoCaption(photo) {
                this.selectedPhotoForEdit = photo.photo_id;
                this.selectedPhotoCaption = photo.caption_override || photo.caption || '';
            },

            async savePhotoCaption() {
                // TODO: Implement caption update API endpoint
                //console.log('💾 Saving caption for photo:', this.selectedPhotoForEdit);
                //console.log('   Caption:', this.selectedPhotoCaption);

                // For now, just close the editor
                this.selectedPhotoForEdit = null;

                // TODO: Call API to update caption_override in photo_associations
                // await fetch('/api/photos/photos.php?action=update_caption', {
                //     method: 'PATCH',
                //     body: JSON.stringify({
                //         photo_id: this.selectedPhotoForEdit,
                //         caption_override: this.selectedPhotoCaption
                //     })
                // });
            },

            // ========================================================================
            // ROAD TYPES HELPERS (używają Utils)
            // ========================================================================

            getRoadTypesSum() {
                return window.Utils.roadTypes.getSum(this.roadTypes);
            },

            normalizeRoadTypes() {
                window.Utils.roadTypes.normalize(this.roadTypes);
            },

            validateRoadTypes() {
                // Ensure integers
                this.roadTypes.asphalt_pct = parseInt(this.roadTypes.asphalt_pct) || 0;
                this.roadTypes.gravel_pct = parseInt(this.roadTypes.gravel_pct) || 0;
                this.roadTypes.trail_pct = parseInt(this.roadTypes.trail_pct) || 0;

                // Clamp to 0-100
                this.roadTypes.asphalt_pct = Math.max(0, Math.min(100, this.roadTypes.asphalt_pct));
                this.roadTypes.gravel_pct = Math.max(0, Math.min(100, this.roadTypes.gravel_pct));
                this.roadTypes.trail_pct = Math.max(0, Math.min(100, this.roadTypes.trail_pct));
            },

            // ========================================================================
            // LAYER MANAGEMENT
            // ========================================================================

            toggleLayerId(layerId) {
                const index = this.editData.layer_ids.indexOf(layerId);

                if (index > -1) {
                    // Remove
                    this.editData.layer_ids.splice(index, 1);
                } else {
                    // Add
                    this.editData.layer_ids.push(layerId);
                }

                //console.log('📋 Layer IDs updated:', this.editData.layer_ids);
            },

            // ========================================================================
            // USER
            // ========================================================================

            async sendMagicLink() {
                await window.UserModule.sendMagicLink(this);
            },

            logout() {
                window.UserModule.logout(this);
            },

            // ========================================================================
            // SETTINGS
            // ========================================================================

            saveSettings() {
                window.SettingsModule.saveSettings(this);
            },

            // fab

            openTracksUpload() {
                window.TracksModule.openUploadPanel(this);
            },

            // ========================================================================
            // UTILITIES
            // ========================================================================

            generateUUID() {
                return window.UserModule.generateUUID();
            },

            validateEmail(email) {
                return window.UserModule.validateEmail(email);
            },

            // W Alpine.data('app') - DODAJ metody:

            async loadFeed(filter) {
                await window.FeedModule.loadFeed(this, filter);
            },

            async loadNotifications() {
                await window.FeedModule.loadNotifications(this);
            },

            switchFeedFilter(filter) {
                window.FeedModule.switchFeedFilter(this, filter);
            },

            async markNotificationAsRead(notificationId) {
                await window.FeedModule.markAsRead(this, notificationId);
            },

            async markAllNotificationsAsRead() {
                await window.FeedModule.markAllAsRead(this);
            },
            getRoadTypesSum() {
                return window.Utils.roadTypes.getSum(this.roadTypes);
            },

            normalizeRoadTypes() {
                window.Utils.roadTypes.normalize(this.roadTypes);
            },

            validateRoadTypes() {
                // Ensure integers
                this.roadTypes.asphalt_pct = parseInt(this.roadTypes.asphalt_pct) || 0;
                this.roadTypes.gravel_pct = parseInt(this.roadTypes.gravel_pct) || 0;
                this.roadTypes.trail_pct = parseInt(this.roadTypes.trail_pct) || 0;

                // Clamp to 0-100
                this.roadTypes.asphalt_pct = Math.max(0, Math.min(100, this.roadTypes.asphalt_pct));
                this.roadTypes.gravel_pct = Math.max(0, Math.min(100, this.roadTypes.gravel_pct));
                this.roadTypes.trail_pct = Math.max(0, Math.min(100, this.roadTypes.trail_pct));
            }
        }));
});

// ============================================================================
// GLOBAL HELPERS
// ============================================================================
const getApp = () => {
    const appElement = document.querySelector('[x-data="app"]');
    if (appElement) {
        return Alpine.$data(appElement);
    }
    return null;
};

window.downloadGPX = function (routeId) {
    const app = Alpine.$data(document.body.querySelector('[x-data="app"]'));
    if (app)
        app.downloadGPX(routeId);
};

window.openPhoto = function (photoData) {
    const app = getApp();
    if (app?.openPhotoLightbox) {
        app.openPhotoLightbox(photoData);
    } else {
        console.error('openPhotoLightbox not available');
    }
};

window.showOnMap = function (routeId) {
    const app = Alpine.$data(document.body.querySelector('[x-data="app"]'));
    if (app)
        app.showOnMap(routeId);
};

// Delete track from card (onclick handler)
window.deleteTrackFromCard = function (routeId) {
    const app = Alpine.$data(document.body.querySelector('[x-data="app"]'));
    if (app) {
        app.deleteTrack(routeId);
    }
};

// Event delegation dla przycisków challenge
document.addEventListener('click', function (e) {
    if (e.target.dataset.action === 'start-challenge') {
        const challengeId = parseInt(e.target.dataset.challengeId);
        const app = Alpine.$data(document.querySelector('[x-data="app"]'));
        if (app && challengeId) {
            app.startChallenge(challengeId);
        }
    }
});


// ============================================================================
// SERVICE WORKER
// ============================================================================

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(window.APP_CONFIG.asset('sw.js')).then(() => {
            //console.log('✅ Service Worker registered');
        }).catch(() => {
            //console.log('⚠️ Service Worker registration failed');
        });
    });
}