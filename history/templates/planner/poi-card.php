<!-- POI Card Template -->
<div class="poi-card" 
     @click="
        // Zoom to POI
        const coords = poi.poi.geometry.coordinates;
        PlannerMap.getMap().setView([coords[1], coords[0]], 15, { animate: true });
        
        // Show POI details popup (reuse from POILayer)
        // TODO: Implement POI details modal or use existing POI popup system
     "
     :data-poi-id="poi.poi.properties.poi_id">
    
    <div class="poi-card-content">
        <!-- Icon -->
        <div class="poi-card-icon">
            <img :src="'<?= asset('icons/') ?>' + (poi.poi.properties.icon || 'map-pin') + '.svg'" 
                 alt=""
                 :style="`filter: ${poi.poi.properties.color ? 'invert(42%) sepia(98%) saturate(2679%) hue-rotate(251deg) brightness(102%) contrast(101%)' : ''}`"
                 style="width: 20px; height: 20px;">
        </div>
        
        <!-- Info -->
        <div class="poi-card-info">
            <div class="poi-card-name" x-text="poi.poi.properties.name"></div>
            <div class="poi-card-meta">
                <span class="poi-category" x-text="poi.poi.properties.subtype_name || poi.poi.properties.category_name"></span>
                <span class="poi-distance">
                    <img src="<?= asset('icons/navigation.svg') ?>" alt="" style="width: 10px; height: 10px; opacity: 0.5;">
                    <span x-text="poi.poi._distance"></span>m
                </span>
            </div>
        </div>
        
        <!-- Thumbnail (if available) -->
        <template x-if="poi.poi.properties.has_photo">
            <div class="poi-card-thumb">
                <img :src="'<?= get_base_url() ?>/api/photos/thumbnail.php?poi_id=' + poi.poi.properties.poi_id + '&size=100'" 
                     alt=""
                     style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px;">
            </div>
        </template>
    </div>
    
    <!-- Arrow indicator -->
    <div class="poi-card-arrow">
        <img src="<?= asset('icons/chevron-right.svg') ?>" alt="" style="width: 12px; height: 12px; opacity: 0.4;">
    </div>
</div>