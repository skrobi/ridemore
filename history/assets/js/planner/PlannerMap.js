/* ============================================================================
 PLANNER MAP - Leaflet setup, panes, click handling
 ============================================================================ */

window.PlannerMap = {
    map: null,

    init() {
        // Guard - prevent double init
        if (this.map) {
            console.log('⚠️ PlannerMap already initialized, skipping');
            return this.map;
        }

        const cfg = window.PLANNER_CONFIG;

        this.map = L.map('planner-map', {
            center: cfg.defaultCenter,
            zoom: cfg.defaultZoom,
            zoomControl: false,
            doubleClickZoom: false
        });

        L.control.zoom({position: 'bottomright'}).addTo(this.map);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap',
            maxZoom: 19
        }).addTo(this.map);

        // ====================================================================
        // CUSTOM PANES (z-index hierarchy)
        // ====================================================================
        this.createPane('challengePane', 350, 0.35);  // Ghost challenge routes
        this.createPane('routeOutlinePane', 400);      // Route outline (white)
        this.createPane('routeMainPane', 410);          // Route main (blue)
        this.createPane('routeCorePane', 420);           // Route core (dark)
        this.createPane('segmentHitPane', 430);          // Invisible drag-hit areas
        this.createPane('waypointPane', 500);            // Waypoint markers
        this.createPane('dayMarkerPane', 510);           // Day break markers
        this.createPane('poiPane', 600);
        
        // ====================================================================
        // MAP CLICK → add waypoint (only in route/freehand mode)
        // ====================================================================
        this.map.on('click', (e) => {
            const mode = PlannerState.mode;
            if (mode === 'route' || mode === 'freehand') {
                PlannerEvents.emit('map:clicked', {
                    lat: e.latlng.lat,
                    lng: e.latlng.lng,
                    mode
                });
            }
        });

        // Expose globally
        window.plannerMap = this.map;
        setTimeout(() => {
            this.map.invalidateSize();
        }, 100);
        console.log('✅ PlannerMap initialized');
        return this.map;
    },

    createPane(name, zIndex, opacity = null) {
        if (!this.map.getPane(name)) {
            const pane = this.map.createPane(name);
            pane.style.zIndex = zIndex;
            if (opacity !== null)
                pane.style.opacity = opacity;
    }
    },

    getMap() {
        return this.map;
    },

    getBbox() {
        const b = this.map.getBounds();
        return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()];
    },

    fitBounds(bounds, opts = {}) {
        this.map.fitBounds(bounds, {padding: [20, 20], ...opts});
    },

    getZoom() {
        return this.map.getZoom();
    },

    setCursor(cursor) {
        this.map.getContainer().style.cursor = cursor;
    }
};

console.log('✅ PlannerMap module loaded');