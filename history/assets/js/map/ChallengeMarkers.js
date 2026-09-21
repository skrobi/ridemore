/* ============================================================================
 CHALLENGE MARKERS - Lightweight challenge markers for zoom out
 ============================================================================ */

class ChallengeMarkers {
    constructor(map) {
        this.map = map;
        this.markers = L.layerGroup();
        this.challenges = [];
    }

    init() {
        this.map.addLayer(this.markers);
        this.load();
    }

    async load() {
        try {
            const url = window.APP_CONFIG.api('challenges/markers.php');
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                this.challenges = data.data;
                this.render();
            }
        } catch (error) {
            console.error('❌ Failed to load challenge markers:', error);
        }
    }

    render() {
        this.markers.clearLayers();

        this.challenges.forEach(challenge => {
            const icon = L.divIcon({
                html: `
                    <div style="
                        background: ${challenge.color || '#10b981'};
                        width: 40px;
                        height: 40px;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 20px;
                        color: white;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                        border: 3px solid white;
                        cursor: pointer;
                    ">
                        ${challenge.icon}
                    </div>
                `,
                className: 'challenge-marker',
                iconSize: [40, 40]
            });
            console.log('marker wyzwania');
            const marker = L.marker(challenge.center, { icon })
                .bindPopup(`
                    <div style="text-align: center;">
                        <strong>${challenge.name}</strong><br>
                        <small>${challenge.participants_count} uczestników</small><br>
                        <button onclick="window.ChallengesModule.openChallengeDetails(window.app, ${challenge.challenge_id})" 
                                style="margin-top: 8px; padding: 6px 12px; background: ${challenge.color}; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            Zobacz wyzwanie
                        </button>
                    </div>
                `);

            this.markers.addLayer(marker);
        });
    }

    show() {
        if (!this.map.hasLayer(this.markers)) {
            this.map.addLayer(this.markers);
        }
    }

    hide() {
        if (this.map.hasLayer(this.markers)) {
            this.map.removeLayer(this.markers);
        }
    }
}

window.ChallengeMarkers = ChallengeMarkers;