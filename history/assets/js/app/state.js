window.AppState = {
    // UI State
    activePanel: null,
    selectedRoute: null,
    fabOpen: false,
    showTopOverlay: false,
    showLoginModal: false,
    showWelcomeModal: false,

    trackingActive: false,
    currentBatchId: null,
    currentTrackingRaceId: null,
    trackingLoading: false,
    trackingConfigModal: {
        show: false,
        batch_id: null,
        deep_link: null,
        config_json: null,
        is_active: true  // ✅ DODAJ
    },

    userTracksPlanned: [],
    loadingPlanned: false,
    plannedFilter: 'all', // 'all' | 'week' | 'month'

    // ✅ DODAJ:
    loadingTopPanel: false,
    topPanelData: {
        hero: null,
        top_quality: [],
        feed: []
    },

    // Profile modal
    showProfileModal: false,
    profileActiveTab: 'personal', // 'personal' | 'account' | 'settings'
    profileLoading: false,
    profileSaving: false,
    profileError: null,
    profileSuccess: false,
    profileData: {
        username: '',
        first_name: '',
        last_name: '',
        phone: '',
        shipping_address: '',
        api_key: ''
    },

    integrations: {
        loading: false,
        strava: {linked: false, expires_at: null, expired: false},
        garmin: {linked: false, expires_at: null, expired: false}
    },

    uploadMenuOpen: false,

    providerImport: {
        show: false,
        provider: null,
        loading: false,
        importing: false,
        activities: [],
        selected: [],
        currentPage: 1,
        hasMore: true,
        error: null,
        importResults: null,
    },

    addPoiModal: {
        open: false,
        lat: null,
        lon: null,
        categories: [],
        subtypes: [],
        showExtraFields: false,
        mobileMode: false,
        data: {
            name: '',
            category_id: '',
            subtype_id: '',
            description: '',
            url: '',
            phone: '',
            email: '',
            opening_hours: '',
            address: '',
            photo_file: null,
            photo_preview: null
        },
        submitting: false,
        error: null
    },

    photoLightbox: {
        open: false,
        url: null,
        caption: null,
        exif: null
    },

    feedEvents: [],
    loadingFeed: false,
    unreadNotificationsCount: 0,
    notifications: [],
    loadingNotifications: false,
    feedFilter: 'global', // 'global' | 'user'

    // Loading states
    loadingRouteDetails: false,
    loadingTracks: false,
    loadingChallenges: false,
    loadingAchievements: false,
    loadingLibrary: false,

    // Data collections
    userTracks: [],
    libraryRoutes: [],
    libraryTotal: 0,
    libraryLimit: 50,
    libraryOffset: 0,
    availableRegions: [],
    routeDetails: null,
    detailsExpanded: false,

    // CHALLENGES
    challengeGroups: [],
    challengeStats: {total: 0, started: 0, completed: 0},
    userChallenges: [],
    _originalChallengeGroups: [],
    availableChallenges: [],
    challengeSearch: '',
    filteredChallenges: [],
    selectedChallenge: null,
    activeChallengeTab: 'started',
    loadingChallengeDetails: false,
    showChallengeRoutes: false,
    loadingChallengeRoutes: false,
    challengeRoutesFilter: 'all',
    challengeSearchTimeout: null,

    // ACHIEVEMENTS
    userAchievements: [],
    achievementFilter: 'all',
    achievementStats: {},
    showcaseMedals: [], // Array of level_ids in showcase

    // MEDAL MODAL (NEW)
    medalModal: {
        show: false,
        badge: null,
        loading: false
    },

    // TRACKS
    tracksFilter: 'all', // 'all' | 'week' | 'month'

    // User
    user: {
        local_id: null,
        email: null,
        username: null,
        status: 'guest',
        token: null,
        role: null, // ✅ DODAJ
        has_manage_access: false, // ✅ DODAJ
        manage_role: null              // ✅ DODAJ
    },

    // Settings
    settings: {
        autoCenter: true,
        showAllLayers: true,
        darkMode: false,
        units: 'metric'
    },

    // Forms
    loginEmail: '',
    librarySearch: '',

    // EDIT MODAL
    showEditModal: false,
    editTab: 'basic', // 'basic' | 'photos' | 'advanced'
    editData: {
        route_id: null,
        name: '',
        description: '',
        tagline: '', // ✅ NEW
        route_type: '',
        difficulty_level: '',
        estimated_time_hours: null, // ✅ NEW
        visibility: 'private',
        route_purpose: 'user_shared',
        layer_ids: [],
        distance_km: 0,
        ascent_m: 0,
        descent_m: 0,
        segment_count: 0,
        views_count: 0,
        gpx_file_path: null
    },
    editingRoute: false,
    editError: null,
    editSuccess: false,
    availableLayers: [],

    // PHOTOS - NEW
    editPhotos: [], // Photos for current route being edited
    uploadingPhotos: false,
    photoUploadProgress: 0,
    photoDropActive: false,
    selectedPhotoForEdit: null,
    selectedPhotoCaption: '',

    // ROAD TYPES - NEW
    roadTypes: {
        asphalt_pct: 0,
        gravel_pct: 0,
        trail_pct: 0
    },

    // RATING MODAL
    showRatingModal: false,
    ratingModal: {
        show: false,
        routeId: null,
        rating: 0,
        comment: '',
        submitting: false,
        error: null
    },

    // UPLOAD MODAL
    showUploadModal: false,
    uploadData: {
        file: null,
        name: '',
        description: '',
        route_type: '',
        difficulty: '',
        match_segments: false
    },
    uploadingRoute: false,
    uploadProgress: 0,
    uploadStatus: '',
    uploadError: null,
    uploadSuccess: false,
    uploadSuccessMessage: '',

    // FILTERS (Library + Map)
    filters: {
        useMapBbox: false, // checkbox "szukaj w widocznym obszarze"
        bbox: null,
        // Library filters
        region: null, // województwo (slug)
        difficulty: null, // easy|medium|hard|extreme
        route_type: null, // road|gravel|mtb|mixed
        min_rating: null, // 0-5
        source: null, // admin_upload|user_published
        sort: 'rating', // rating|distance|difficulty|newest

        // Shared filters
        distance_min: null,
        distance_max: null,
        ascent_min: null,
        ascent_max: null,

        // Legacy map filters (deprecated, kept for backwards compatibility)
        difficulty_min: 1,
        difficulty_max: 10,
        sources: ['admin_upload', 'user_published'],
        layers: []
    },

    // Map reference
    map: null,
    currentBbox: null,
    currentElevationProfile: null,

    // Timers
    mapMoveTimeout: null,
    searchTimeout: null
};