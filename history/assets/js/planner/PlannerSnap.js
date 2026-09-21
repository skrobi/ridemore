/* ============================================================================
   PLANNER SNAP - Spatial grid index for fast snap-to-challenge
   O(k) instead of O(n) where k ≈ 10-50 points in nearby cells
   ============================================================================ */

window.PlannerSnap = {
    _grid: {},           // "cellX_cellY" → [{lat, lng}]
    _cellSize: 0.001,    // ~100m in degrees (approximate)
    _built: false,

    // ========================================================================
    // BUILD INDEX from challenge route coordinates
    // ========================================================================
    build(challengeGeojson) {
        this._grid = {};
        this._built = false;

        if (!challengeGeojson?.features) return;

        let count = 0;

        challengeGeojson.features.forEach(feature => {
            const coords = feature.geometry?.coordinates;
            if (!coords) return;

            coords.forEach(c => {
                const lng = c[0], lat = c[1];
                const key = this.cellKey(lat, lng);

                if (!this._grid[key]) this._grid[key] = [];
                this._grid[key].push({ lat, lng });
                count++;
            });
        });

        this._built = true;
        const cells = Object.keys(this._grid).length;
        console.log(`✅ PlannerSnap: indexed ${count} points in ${cells} cells`);
    },

    // ========================================================================
    // FIND nearest snap point within threshold
    // ========================================================================
    find(lat, lng, thresholdM = 500) {
        if (!this._built) return null;

        const p = L.latLng(lat, lng);

        // Search radius in cells (threshold / ~111km per degree)
        const cellRadius = Math.ceil(thresholdM / 111000 / this._cellSize) + 1;
        const cx = Math.floor(lng / this._cellSize);
        const cy = Math.floor(lat / this._cellSize);

        let minDist = Infinity;
        let closest = null;

        // Check surrounding cells
        for (let dx = -cellRadius; dx <= cellRadius; dx++) {
            for (let dy = -cellRadius; dy <= cellRadius; dy++) {
                const key = `${cx + dx}_${cy + dy}`;
                const pts = this._grid[key];
                if (!pts) continue;

                for (const pt of pts) {
                    const d = p.distanceTo(L.latLng(pt.lat, pt.lng));
                    if (d < minDist) {
                        minDist = d;
                        closest = pt;
                    }
                }
            }
        }

        if (minDist <= thresholdM && closest) {
            return { lat: closest.lat, lng: closest.lng, distance: minDist };
        }

        return null;
    },

    // ========================================================================
    // CLEAR
    // ========================================================================
    clear() {
        this._grid = {};
        this._built = false;
    },

    // ========================================================================
    // UTILS
    // ========================================================================
    cellKey(lat, lng) {
        const cx = Math.floor(lng / this._cellSize);
        const cy = Math.floor(lat / this._cellSize);
        return `${cx}_${cy}`;
    }
};

console.log('✅ PlannerSnap module loaded');