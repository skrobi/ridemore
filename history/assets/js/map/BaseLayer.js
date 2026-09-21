/* ============================================================================
 BASE LAYER - Abstract class for all map layers
 ============================================================================ */

class BaseLayer {
    constructor(config) {
        this.type = config.type;
        this.apiEndpoint = config.apiEndpoint;
        this.renderer = config.renderer;
        this.layerGroup = L.layerGroup();
        this.cache = CacheManager.getInstance();
        this.items = new Map();
        this.visible = true;
        this.filters = {};
    }

    async load(bbox, filters = {}, silentMode = false) {
        this.filters = {...this.filters, ...filters};

        if (!silentMode) {
            //console.log(`🔄 ${this.type}: loading...`);
        }

        try {
            const cacheKey = this.getCacheKey(bbox);
            const cached = await this.cache.get(this.type, cacheKey);

            if (cached && !this.shouldRefresh(cached)) {
                if (!silentMode)
                    //console.log(`✅ ${this.type} from cache`);
                return this.render(cached);
            }

            const data = await this.fetch(bbox, this.filters);

            if (data) {
                await this.cache.set(this.type, cacheKey, data);
                return this.render(data);
            }
        } catch (error) {
            console.error(`❌ ${this.type} load error:`, error);
    }
    }

    async fetch(bbox, filters) {
        throw new Error('fetch() must be implemented in child class');
    }

    render(data) {
        throw new Error('render() must be implemented in child class');
    }

    show() {
        //console.log(`👁️ ${this.type}: show() called`);
        //console.log(`  - Current visible:`, this.visible);
        //console.log(`  - Map available:`, !!window.map);
        //console.log(`  - layerGroup exists:`, !!this.layerGroup);
        //console.log(`  - layerGroup has layers:`, this.layerGroup.getLayers().length);

        if (!this.visible) {
            if (!window.map) {
                console.error('❌ Map not available!');
                return;
            }

            window.map.addLayer(this.layerGroup);
            this.visible = true;

            //console.log(`✅ ${this.type}: layer added to map`);
            //console.log(`  - layerGroup on map now:`, window.map.hasLayer(this.layerGroup));
        } else {
            //console.log(`ℹ️ ${this.type}: already visible`);
        }
    }

    hide() {
        //console.log(`👁️‍🗨️ ${this.type}: hide() called`);
        //console.log(`  - Current visible:`, this.visible);
        //console.log(`  - Map available:`, !!window.map);

        if (this.visible) {
            if (!window.map) {
                console.error('❌ Map not available in hide()!');
                return;
            }

            if (window.map.hasLayer(this.layerGroup)) {
                window.map.removeLayer(this.layerGroup);
                //console.log(`✅ ${this.type}: layer removed from map`);
            } else {
                console.warn(`⚠️ ${this.type}: layer was not on map`);
            }

            this.visible = false;
        } else {
            //console.log(`ℹ️ ${this.type}: already hidden`);
        }
    }

    destroy() {
        //console.log(`🗑️ ${this.type}: destroying...`);

        this.clear();

        if (this.layerGroup && window.map) {
            if (window.map.hasLayer(this.layerGroup)) {
                window.map.removeLayer(this.layerGroup);
            }
        }

        this.items.clear();
        this.visible = false;

        //console.log(`✅ ${this.type}: destroyed`);
    }

    toggle() {
        this.visible ? this.hide() : this.show();
    }

    clear() {
        // ✅ Clean all handlers
        this.items.forEach((layer, id) => {
            if (layer._handlers) {
                layer.off('click', layer._handlers.click);
                layer.off('mouseover', layer._handlers.mouseover);
                layer.off('mouseout', layer._handlers.mouseout);
                delete layer._handlers;
            }
        });

        this.layerGroup.clearLayers();
        this.items.clear();
    }

    getItem(id) {
        return this.items.get(id);
    }

    hasItem(id) {
        return this.items.has(id);
    }

    addItem(id, layer) {
        this.items.set(id, layer);
    }

    removeItem(id) {
        const layer = this.items.get(id);
        if (layer) {
            // ✅ Clean handlers if exist
            if (layer._handlers) {
                layer.off('click', layer._handlers.click);
                layer.off('mouseover', layer._handlers.mouseover);
                layer.off('mouseout', layer._handlers.mouseout);
                delete layer._handlers;
            }

            this.layerGroup.removeLayer(layer);
            this.items.delete(id);
        }
    }

    getCacheKey(bbox) {
        return `${bbox.join(',')}_${JSON.stringify(this.filters)}`;
    }

    shouldRefresh(cached) {
        return false;
    }

    getZoomLevel() {
        return window.map ? window.map.getZoom() : 7;
    }

    isInView(latlng) {
        if (!window.map)
            return false;
        return window.map.getBounds().contains(latlng);
    }
}

window.BaseLayer = BaseLayer;