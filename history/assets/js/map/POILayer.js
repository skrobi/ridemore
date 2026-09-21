/* ============================================================================
 POI LAYER - Points of Interest
 ============================================================================ */

class POILayer extends BaseLayer {
    constructor() {
        super({
            type: 'poi',
            apiEndpoint: window.APP_CONFIG.api('poi/list.php'),
            renderer: null
        });
        this.config = {
            slug: 'poi',
            name: 'Punkty POI',
            icon: '📍',
            color: '#00d4ff',
            visible_by_default: 1
        };

        this.clusterGroup = L.markerClusterGroup({
            maxClusterRadius: 50,
            spiderfyOnMaxZoom: true,
            showCoverageOnHover: false,
            zoomToBoundsOnClick: true,
            iconCreateFunction: function (cluster) {
                const count = cluster.getChildCount();
                return L.divIcon({
                    html: `<div style="background: #00d4ff; color: white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-weight: bold; box-shadow: 0 2px 8px rgba(0,0,0,0.3);">${count}</div>`,
                    className: 'poi-cluster',
                    iconSize: L.point(40, 40)
                });
            }
        });
    }

    async fetch(bbox, filters = {}) {
        //console.log('🗺️ POILayer.fetch() called');
        //console.log('📍 BBox:', bbox);
        //console.log('🎯 Filters:', filters);

        const params = new URLSearchParams({
            bbox: bbox.join(','),
            limit: 500
        });

        // Używaj subtypes (nowe API)
        if (filters.subtypes && filters.subtypes.length > 0) {
            params.append('subtypes', filters.subtypes.join(','));
            //console.log('✅ Filtering by subtypes:', filters.subtypes);
        }
        // Fallback dla starego API (categories)
        else if (filters.categories && filters.categories.length > 0) {
            params.append('categories', filters.categories.join(','));
            //console.log('✅ Filtering by categories (fallback):', filters.categories);
        }

        const url = `${this.apiEndpoint}?${params}`;
        //console.log('🌐 Fetching POI:', url);

        const response = await fetch(url);
        const data = await response.json();

        //console.log('📦 POI Response:', data);
        //console.log('✅ Features:', data.data?.features?.length || 0);

        return data.success ? data.data : null;
    }

    render(geojson) {
        //console.log('🎨 POILayer.render() called');

        if (!geojson || !geojson.features || geojson.features.length === 0) {
            //console.warn('⚠️ No POI data to render');
            return;
        }

        //console.log(`📍 Rendering ${geojson.features.length} POIs`);

        // Zamiast clear() - usuń tylko te które nie są już w nowych danych
        const newPOIIds = new Set(geojson.features.map(f => f.properties.poi_id));

        // Usuń stare POI które już nie istnieją
        for (const [poiId, marker] of this.items.entries()) {
            if (!newPOIIds.has(poiId)) {
                this.clusterGroup.removeLayer(marker);
                this.items.delete(poiId);
            }
        }

        // Dodaj nowe POI
        geojson.features.forEach((feature, index) => {
            const props = feature.properties;
            const coords = feature.geometry.coordinates;

            // Pomiń jeśli już istnieje
            if (this.hasItem(props.poi_id)) {
                return;
            }

            //console.log(`  📌 POI ${index + 1}:`, props.name, coords);

            const marker = this.createMarker([coords[1], coords[0]], props);
            marker.poiSubtype = props.subtype_slug; // Zapisz subtype dla późniejszego filtrowania

            this.clusterGroup.addLayer(marker);
            this.addItem(props.poi_id, marker);
        });

        // Upewnij się że clusterGroup jest na mapie
        if (!this.layerGroup.hasLayer(this.clusterGroup)) {
            this.clusterGroup.addTo(this.layerGroup);
        }

        //console.log(`✅ Rendered ${this.items.size} POIs in clusters`);
    }

    // Nowa metoda - tylko DODAJE, nigdy nie usuwa
    renderAdditive(geojson) {
        //console.log('➕ POILayer.renderAdditive() called');

        if (!geojson || !geojson.features || geojson.features.length === 0) {
            console.warn('⚠️ No POI data to add');
            return;
        }

        //console.log(`📍 Adding ${geojson.features.length} new POIs`);

        geojson.features.forEach((feature) => {
            const props = feature.properties;
            const coords = feature.geometry.coordinates;
            const poiId = props.poi_id;

            // Jeśli już istnieje - pomiń
            if (this.hasItem(poiId)) {
                //console.log(`  ⏭️ Skipping existing POI ${poiId}`);
                return;
            }

            //console.log(`  ➕ Adding POI ${poiId}:`, props.name);

            const marker = this.createMarker([coords[1], coords[0]], props);
            marker.poiSubtype = props.subtype_slug; // Zapisz subtype

            this.clusterGroup.addLayer(marker);
            this.addItem(poiId, marker);
        });

        // Upewnij się że clusterGroup jest na mapie
        if (!this.layerGroup.hasLayer(this.clusterGroup)) {
            this.clusterGroup.addTo(this.layerGroup);
        }

        //console.log(`✅ Total POIs on map: ${this.items.size}`);
    }

    createMarker(latlng, props) {
        // ✅ SVG icon
        const iconSvg = props.icon
                ? `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/${props.icon}.svg" width="20" height="20" style="display: block;" alt="">`
                : `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/poi-default.svg" width="20" height="20" style="display: block;" alt="">`;

        const icon = L.divIcon({
            className: 'custom-poi-marker',
            html: `
            <div style="
                background: white;
                border: 3px solid ${props.color || '#00d4ff'};
                border-radius: 50%;
                width: 36px;
                height: 36px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                cursor: pointer;
                transition: transform 0.2s;
            " onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                ${iconSvg}
            </div>
        `,
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18]
        });

        const marker = L.marker(latlng, {icon});

        // ✅ SVG w popupie
        const popupIconSvg = props.icon
                ? `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/${props.icon}.svg" width="24" height="24" style="display: inline-block; vertical-align: middle;" alt="">`
                : `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/poi-default.svg" width="24" height="24" style="display: inline-block; vertical-align: middle;" alt="">`;

        marker.bindPopup(`
        <div style="padding: 10px; min-width: 200px;">
            <h4 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                ${popupIconSvg}
                ${props.name}
            </h4>
            <div style="font-size: 12px; color: #6b7280; margin-bottom: 6px;">
                ${props.category_name || 'POI'}
            </div>
            ${props.subtype_name ? `<div style="font-size: 11px; color: #9ca3af; margin-bottom: 8px;">${props.subtype_name}</div>` : ''}
        </div>
    `, {
            maxWidth: 250,
            className: 'poi-popup',
            autoClose: true,
            closeOnClick: false
        });

        marker.on('click', async (e) => {
            L.DomEvent.stopPropagation(e);

            // ✅ Lazy load details
            await this.loadPOIDetails(props.poi_id, marker);
        });

        return marker;
    }

    async loadPOIDetails(poiId, marker) {
        try {
            //console.log('📡 Loading POI details:', poiId);

            const response = await fetch(
                    window.APP_CONFIG.api(`poi/details.php?id=${poiId}`)
                    );
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error);
            }

            const poi = data.data;

            // ✅ Photos - klik otwiera lightbox
            const photosHtml = poi.photos.length > 0
                    ? `<div class="poi-photos" style="margin-top: 10px; display: flex; gap: 4px; flex-wrap: wrap;">
                ${poi.photos.slice(0, 3).map((p, idx) => `
                    <img src="${p.thumbnail_url}" 
                         data-photo-index="${idx}"
                         style="width: 80px; height: 60px; object-fit: cover; border-radius: 4px; cursor: pointer; transition: transform 0.2s;"
                         onmouseover="this.style.transform='scale(1.05)'"
                         onmouseout="this.style.transform='scale(1)'"
                         alt="">
                `).join('')}
                ${poi.photos.length > 3
                    ? `<span style="color: #9ca3af; font-size: 11px; align-self: center;">+${poi.photos.length - 3} więcej</span>`
                    : ''}
               </div>`
                    : '';

            const descriptionHtml = poi.description
                    ? `<p style="font-size: 12px; color: #4b5563; margin: 8px 0; line-height: 1.4;">${poi.description}</p>`
                    : '';

            const contactHtml = poi.contact.url || poi.contact.phone
                    ? `<div style="font-size: 11px; color: #6b7280; margin-top: 8px; display: flex; gap: 12px;">
                ${poi.contact.url ? `<a href="${poi.contact.url}" target="_blank" style="color: #3b82f6; text-decoration: none;">🔗 Strona</a>` : ''}
                ${poi.contact.phone ? `<span>📞 ${poi.contact.phone}</span>` : ''}
               </div>`
                    : '';

            const iconSvg = poi.icon
                    ? `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/${poi.icon}.svg" width="24" height="24" style="display: inline-block; vertical-align: middle;" alt="">`
                    : `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/poi-default.svg" width="24" height="24" style="display: inline-block; vertical-align: middle;" alt="">`;

            marker.setPopupContent(`
            <div style="padding: 10px; min-width: 250px; max-width: 350px;">
                <h4 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    ${iconSvg}
                    ${poi.name}
                </h4>
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                    ${poi.category.name}${poi.subtype ? ` · ${poi.subtype.name}` : ''}
                </div>
                ${descriptionHtml}
                ${photosHtml}
                ${contactHtml}
                <div style="font-size: 10px; color: #9ca3af; margin-top: 8px; border-top: 1px solid #e5e7eb; padding-top: 6px;">
                    👁️ ${poi.stats.view_count} wyświetleń
                    ${poi.stats.photo_count > 0 ? `· 📷 ${poi.stats.photo_count} zdjęć` : ''}
                </div>
            </div>
        `);

            marker.openPopup();

            // ✅ Attach click handlers to photos AFTER popup opens
            setTimeout(() => {
                const photoElements = marker.getPopup().getElement().querySelectorAll('[data-photo-index]');

                photoElements.forEach((img, index) => {
                    img.addEventListener('click', (e) => {
                        e.stopPropagation();

                        const photo = poi.photos[index];

                        // Get Alpine app instance
                        const appEl = document.querySelector('[x-data="app"]');
                        if (appEl && window.Alpine) {
                            const app = Alpine.$data(appEl);
                            app.openPhotoLightbox(photo);
                        }
                    });
                });
            }, 100);

        } catch (error) {
            console.error('❌ Failed to load POI details:', error);

            const iconSvg = `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/poi-default.svg" width="24" height="24" alt="">`;

            marker.setPopupContent(`
            <div style="padding: 10px;">
                <h4 style="margin: 0; display: flex; align-items: center; gap: 6px;">
                    ${iconSvg}
                    Błąd ładowania
                </h4>
                <p style="font-size: 12px; color: #ef4444; margin: 8px 0 0 0;">
                    Nie udało się załadować szczegółów POI
                </p>
            </div>
        `);

            marker.openPopup();
        }
    }

    show() {
        if (!this.visible) {
            window.map.addLayer(this.layerGroup);
            this.visible = true;
            //console.log('✅ POI layer shown');
        }
    }

    hide() {
        if (this.visible) {
            window.map.removeLayer(this.layerGroup);
            this.visible = false;
            //console.log('❌ POI layer hidden');
        }
    }

    clear() {
        this.clusterGroup.clearLayers();
        this.items.clear();
    }
}

window.POILayer = POILayer;