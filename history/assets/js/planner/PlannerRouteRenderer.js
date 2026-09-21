/* ============================================================================
   PLANNER ROUTE RENDERER - 3-layer route display (outline/main/core)
   Consistent with RouteStyles pattern from main app.
   ============================================================================ */

window.PlannerRouteRenderer = {
    _segments: new Map(),  // segIdx → {outline, main, core}

    // ========================================================================
    // RENDER SEGMENT
    // ========================================================================
    renderSegment(segIdx, coords) {
        const map = PlannerMap.getMap();
        const zoom = map.getZoom();
        const w = this.getWeights(zoom);

        // Remove old layers for this segment
        this.removeSegment(segIdx);

        // Outline (white border)
        const outline = L.polyline(coords, {
            color: '#ffffff',
            weight: w.outline,
            opacity: 0.7,
            lineCap: 'round',
            lineJoin: 'round',
            interactive: false,
            pane: 'routeOutlinePane'
        }).addTo(map);

        // Main (blue)
        const main = L.polyline(coords, {
            color: '#3b82f6',
            weight: w.main,
            opacity: 0.9,
            lineCap: 'round',
            lineJoin: 'round',
            interactive: false,
            pane: 'routeMainPane'
        }).addTo(map);

        // Core (dark center line)
        const core = L.polyline(coords, {
            color: '#1e3a5f',
            weight: w.core,
            opacity: 0.6,
            lineCap: 'round',
            lineJoin: 'round',
            interactive: false,
            pane: 'routeCorePane'
        }).addTo(map);

        this._segments.set(segIdx, { outline, main, core });
    },

    // ========================================================================
    // REMOVE SEGMENT
    // ========================================================================
    removeSegment(segIdx) {
        const layers = this._segments.get(segIdx);
        if (!layers) return;

        const map = PlannerMap.getMap();
        map.removeLayer(layers.outline);
        map.removeLayer(layers.main);
        map.removeLayer(layers.core);
        this._segments.delete(segIdx);
    },

    // ========================================================================
    // CLEAR ALL
    // ========================================================================
    clear() {
        const map = PlannerMap.getMap();
        this._segments.forEach(layers => {
            map.removeLayer(layers.outline);
            map.removeLayer(layers.main);
            map.removeLayer(layers.core);
        });
        this._segments.clear();
    },

    // ========================================================================
    // ZOOM-ADAPTIVE WEIGHTS (consistent with RouteStyles)
    // ========================================================================
    getWeights(zoom) {
        if (zoom < 10) return { outline: 10, main: 6, core: 2.5 };
        if (zoom < 14) return { outline: 9, main: 5.5, core: 2 };
        return { outline: 8, main: 5, core: 1.8 };
    },

    // ========================================================================
    // UPDATE STYLES ON ZOOM (call from map zoomend)
    // ========================================================================
    onZoomChange() {
        const zoom = PlannerMap.getZoom();
        const w = this.getWeights(zoom);

        this._segments.forEach(layers => {
            layers.outline.setStyle({ weight: w.outline });
            layers.main.setStyle({ weight: w.main });
            layers.core.setStyle({ weight: w.core });
        });
    },

    // ========================================================================
    // GET FULL ROUTE COORDS (for export/save)
    // ========================================================================
    getFullRouteCoords() {
        const allCoords = [];
        const segs = PlannerState.routeSegments;

        segs.forEach((seg, idx) => {
            if (!seg?.coords) return;
            // Skip first point of subsequent segments (it's same as last of previous)
            const startIdx = (idx > 0 && allCoords.length > 0) ? 1 : 0;
            for (let i = startIdx; i < seg.coords.length; i++) {
                allCoords.push(seg.coords[i]);
            }
        });

        return allCoords;
    }
};

console.log('✅ PlannerRouteRenderer module loaded');