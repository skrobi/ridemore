/* ============================================================================
 PLANNER GEO - Shared geometry utilities for Planner modules
 Replaces duplicated code in PlannerRouter.js and PlannerPOI.js
 ============================================================================ */

window.PlannerGeo = {

    /**
     * Haversine distance between two points
     * @returns {number} distance in meters
     */
    haversineDistance(lat1, lng1, lat2, lng2) {
        const R = 6371000;
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lng2 - lng1) * Math.PI / 180;

        const a = Math.sin(Δφ / 2) ** 2 +
                  Math.cos(φ1) * Math.cos(φ2) * Math.sin(Δλ / 2) ** 2;

        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    },

    /**
     * Distance from point to line segment
     * @returns {number} distance in meters
     */
    distanceToSegment(pLat, pLng, aLat, aLng, bLat, bLng) {
        const dx = bLng - aLng;
        const dy = bLat - aLat;
        const lenSq = dx * dx + dy * dy;

        if (lenSq < 1e-10) {
            return this.haversineDistance(pLat, pLng, aLat, aLng);
        }

        const t = Math.max(0, Math.min(1,
            ((pLng - aLng) * dx + (pLat - aLat) * dy) / lenSq
        ));

        return this.haversineDistance(pLat, pLng, aLat + t * dy, aLng + t * dx);
    },

    /**
     * Total distance along array of [lat, lng] coords
     * @returns {number} distance in meters
     */
    calculatePathDistance(coords) {
        let total = 0;
        for (let i = 0; i < coords.length - 1; i++) {
            total += this.haversineDistance(
                coords[i][0], coords[i][1],
                coords[i + 1][0], coords[i + 1][1]
            );
        }
        return total;
    }
};

console.log('✅ PlannerGeo loaded');