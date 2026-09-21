/* ============================================================================
 PLANNER POI MANAGER - extends POIManager
 Obsługuje dwa źródła konfiguracji:
 - useSidebarConfig: true  → czyta z PlannerPOI.getConfig() (karty w sidebarze)
 - useSidebarConfig: false → czyta z checkboxów DOM (markery na mapie)
 ============================================================================ */

class PlannerPOIManager extends POIManager {

    constructor(map, layersRef, options = {}) {
        super(map, layersRef);
        this.useSidebarConfig = options.useSidebarConfig ?? false;
    }

    // ========================================================================
    // ŹRÓDŁO KONFIGURACJI — jedyna różnica między trybami
    // ========================================================================
    _getActiveSubtypes() {
        if (this.useSidebarConfig) {
            const cfg = PlannerPOI.getConfig();
            return cfg.enabled ? cfg.subtypes : [];
        }
        // Oryginalna logika POIManager — checkboxy DOM (LayerControlsUI)
        return Array.from(
                document.querySelectorAll('.poi-subtype-toggle:checked')
                ).map(cb => cb.dataset.subtype);
    }

    // ========================================================================
    // RELOAD POI
    // ========================================================================
    reloadPOI() {
        const poiLayer = this.layers['poi'];
        if (!poiLayer)
            return;

        const activeSubtypes = this._getActiveSubtypes();

        if (activeSubtypes.length === 0) {
            poiLayer.clear();
            this.lastActiveSubtypes = new Set();
            return;
        }

        const bbox = this._getBbox();
        const activeSet = new Set(activeSubtypes);
        const removed = Array.from(this.lastActiveSubtypes).filter(s => !activeSet.has(s));

        if (removed.length > 0) {
            this.removePOIBySubtypes(poiLayer, removed);
        }

        // ✅ Jeśli nie useSidebarConfig — wyczyść przed renderem
        // żeby POI dodane przez sidebar nie blokowały re-renderu
        if (!this.useSidebarConfig) {
            poiLayer.clear();
        }

        poiLayer.fetch(bbox, {subtypes: activeSubtypes})
                .then(data => {
                    if (data)
                        poiLayer.render(data);
                })
                .catch(err => console.error('❌ PlannerPOIManager.reloadPOI:', err));

        this.lastActiveSubtypes = activeSet;
    }

    // ========================================================================
    // RELOAD ON MAP MOVE
    // ========================================================================
    reloadPOIOnMove() {
        const poiLayer = this.layers['poi'];
        if (!poiLayer)
            return;

        const activeSubtypes = this._getActiveSubtypes();

        if (activeSubtypes.length === 0) {
            poiLayer.clear();
            return;
        }

        poiLayer.fetch(this._getBbox(), {subtypes: activeSubtypes})
                .then(data => {
                    if (data)
                        poiLayer.render(data);
                })
                .catch(err => console.error('❌ PlannerPOIManager.reloadPOIOnMove:', err));
    }

    // ========================================================================
    // BBOX
    // ========================================================================
    _getBbox() {
        const b = this.map.getBounds();
        return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()];
    }
}

window.PlannerPOIManager = PlannerPOIManager;

// ============================================================================
// Nasłuchuj poi:config-changed — odśwież warstwę mapową
// Rejestracja po alpine:init żeby PlannerEvents był gotowy
// ============================================================================
document.addEventListener('alpine:init', () => {
    setTimeout(() => {
        PlannerEvents.on('poi:config-changed', () => {
            window._plannerPOIManager?.reloadPOI();

        });
        console.log('✅ PlannerPOIManager poi:config-changed listener registered');
    }, 500);
});

console.log('✅ PlannerPOIManager loaded');