/* ============================================================================
 LIVE TRACKING LAYER - Real-time user locations
 ============================================================================ */

class LiveTrackingLayer extends BaseLayer {
    constructor(config) {
        super({
            type: 'live',
            apiEndpoint: window.APP_CONFIG.api('tracking/live.php')
        });

        this.config = config;
        this.refreshInterval = null;
        this.refreshRate = 120000; // 2 min = 120000ms

        console.log('🔴 LiveTrackingLayer initialized:', config.name);
    }

    // ✅ Fetch live users
    async fetch() {
        const url = this.apiEndpoint;
        console.log('🌐 Fetching live users:', url);

        const response = await fetch(url);
        const data = await response.json();

        console.log('📦 Live users response:', data);

        return data.success ? data.data : null;
    }

    render(data) {
        console.log('🎨 Rendering live users...');

        if (!data || !data.geojson || !data.geojson.features || data.geojson.features.length === 0) {
            console.log('ℹ️ No live users to render');
            return;
        }

        const features = data.geojson.features;
        console.log(`📍 Rendering ${features.length} live users`);

        // Clear existing markers
        this.layerGroup.clearLayers();
        this.items.clear();

        features.forEach(feature => {
            const props = feature.properties;
            const coords = feature.geometry.coordinates;
            const latlng = [coords[1], coords[0]]; // GeoJSON is [lon, lat]

            // Create avatar marker
            const marker = this.createAvatarMarker(latlng, props);

            // Popup content
            const popupContent = `
            <div style="min-width: 200px;">
                <div style="font-weight: bold; margin-bottom: 4px;">
                    ${props.username || props.initials}
                </div>
                ${props.challenge ? `
                    <div style="font-size: 12px; color: #666;">
                        📍 ${props.challenge.name}
                    </div>
                ` : ''}
                <div style="font-size: 11px; color: #999; margin-top: 4px;">
                    Aktualizacja ${props.minutes_ago} min temu
                </div>
            </div>
        `;

            marker.bindPopup(popupContent);

            // Add to layer
            this.layerGroup.addLayer(marker);
            this.items.set(`user_${props.user_id}`, marker);
        });

        console.log(`✅ Rendered ${this.items.size} live users`);
        this.updateLayerCounter();
    }

    /**
     * Create avatar marker with initials
     */
    createAvatarMarker(latlng, props) {
        const borderColor = props.challenge?.color || this.config.color || '#10b981';
        const bgColor = this.stringToColor(props.username || 'User');
        const initials = props.initials || this.getInitials(props.username);

        // Create custom div icon
        const iconHtml = `
        <div style="
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: ${bgColor};
            border: 3px solid ${borderColor};
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            cursor: pointer;
            transition: transform 0.2s;
        " onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
            ${initials}
        </div>
    `;

        const icon = L.divIcon({
            html: iconHtml,
            className: 'live-user-marker',
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -20]
        });

        return L.marker(latlng, {icon});
    }

    /**
     * Get initials from username
     */
    getInitials(username) {
        if (!username)
            return '?';

        const parts = username.trim().split(/\s+/);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
        }
        return username.substring(0, 2).toUpperCase();
    }

    /**
     * Generate consistent color from string
     */
    stringToColor(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            hash = str.charCodeAt(i) + ((hash << 5) - hash);
        }

        // Generate vibrant colors
        const hue = Math.abs(hash % 360);
        return `hsl(${hue}, 65%, 50%)`;
    }

    /**
     * Update layer counter in UI
     */
    updateLayerCounter() {
        const checkbox = document.querySelector(`input[data-layer="${this.config.slug}"]`);
        if (checkbox) {
            const label = checkbox.closest('label');
            const countSpan = label?.querySelector('span:last-child');
            if (countSpan) {
                countSpan.textContent = `(${this.items.size})`;
                countSpan.style.color = this.config.color || '#10b981';
            }
        }
    }

    // ✅ Auto-refresh when visible
    show() {
        if (!this.visible) {
            window.map.addLayer(this.layerGroup);
            this.visible = true;
            this.startAutoRefresh();
            console.log('✅ Live tracking layer shown');
        }
    }

    hide() {
        if (this.visible) {
            window.map.removeLayer(this.layerGroup);
            this.visible = false;
            this.stopAutoRefresh();
            console.log('❌ Live tracking layer hidden');
        }
    }

    startAutoRefresh() {
        if (this.refreshInterval)
            return; // Already running

        console.log(`⏱️ Starting auto-refresh (${this.refreshRate / 1000}s)`);

        // Initial load
        this.refresh();

        // Periodic refresh
        this.refreshInterval = setInterval(() => {
            console.log('🔄 Auto-refreshing live users...');
            this.refresh();
        }, this.refreshRate);
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
            console.log('⏹️ Stopped auto-refresh');
        }
    }

    async refresh() {
        try {
            const data = await this.fetch();
            if (data) {
                this.render(data);
            }
        } catch (error) {
            console.error('❌ Failed to refresh live users:', error);
        }
    }

    clear() {
        this.layerGroup.clearLayers();
        this.items.clear();
    }
}

window.LiveTrackingLayer = LiveTrackingLayer;
console.log('✅ LiveTrackingLayer loaded');