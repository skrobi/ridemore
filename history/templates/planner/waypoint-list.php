<div class="waypoints-section">
    <template x-if="waypoints.length === 0">
        <div class="empty-state">
            <img src="<?= asset('icons/map-pin.svg') ?>" alt="" style="width: 48px; height: 48px; opacity: 0.3;">
            <p>Kliknij na mapę aby dodać<br>punkt trasy</p>
        </div>
    </template>

    <template x-for="day in enrichedDays" :key="'day-' + day.num">
        <div class="day-block">
            <?php include 'day-header.php'; ?>

            <div x-show="!isDayCollapsed(day.num)" 
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 transform -translate-y-2"
                 x-transition:enter-end="opacity-100 transform translate-y-0">

                <template x-for="item in day.waypoints" :key="
                          item.type === 'poi'
                          ? 'poi-' + item.poi.properties.poi_id
                          : item.type === 'poi-group-header'
                          ? 'poi-group-' + item.role + '-' + item.idx
                          : 'wp-' + item.id + '-' + item.idx
                          ">
                    <div class="waypoint-wrapper">

                        <!-- Normal waypoint -->
                        <template x-if="item.type !== 'poi' && item.type !== 'poi-group-header'">
                            <div x-data="{ wp: item }">
                                <!-- Waypoint item -->
                                <div class="waypoint-item" 
                                     @click="focusWaypoint(wp.idx)"
                                     @mouseenter="highlightWaypoint(wp.idx)" 
                                     @mouseleave="unhighlightWaypoint(wp.idx)"
                                     @contextmenu.prevent="showWaypointMenu($event, wp.idx)"
                                     style="cursor: pointer;">

                                    <div class="wp-marker" :class="getWaypointType(wp.idx)">
                                        <span x-text="getWaypointLabel(wp.idx)"></span>
                                    </div>

                                    <div class="wp-info">
                                        <div class="wp-label" x-text="wp.label || getDefaultLabel(wp.idx)"></div>
                                        <div class="wp-coords" x-text="wp.lat.toFixed(5) + ', ' + wp.lng.toFixed(5)"></div>
                                        <template x-if="wp.snapped">
                                            <span class="snap-badge">
                                                <img src="<?= asset('icons/magnet.svg') ?>" alt="" style="width: 10px; height: 10px;">
                                                Snap
                                            </span>
                                        </template>
                                    </div>

                                    <button class="wp-remove" @click.stop="removeWaypoint(wp.idx)" title="Usuń punkt">
                                        <img src="<?= asset('icons/trash-2.svg') ?>" alt="" style="width: 14px; height: 14px;">
                                    </button>
                                </div>

                                <!-- Segment stats -->
                                <template x-if="wp.idx < waypoints.length - 1 && routeSegments[wp.idx] && wp.type !== 'day-break'">
                                    <div class="segment-stats" @contextmenu.prevent="showSegmentMenu($event, wp.idx)">
                                        <span>
                                            <img src="<?= asset('icons/navigation.svg') ?>" alt="" style="width: 11px; height: 11px; opacity: 0.5;">
                                            <span x-text="(routeSegments[wp.idx].distance / 1000).toFixed(1)"></span> km
                                        </span>
                                        <span>
                                            <img src="<?= asset('icons/clock.svg') ?>" alt="" style="width: 11px; height: 11px; opacity: 0.5;">
                                            <span x-text="Math.round(routeSegments[wp.idx].duration / 60)"></span> min
                                        </span>
                                    </div>
                                </template>

                                <!-- Day separator -->
                                <template x-if="wp.type === 'day-break'">
                                    <div class="day-separator">
                                        <img src="<?= asset('icons/moon.svg') ?>" alt="" style="width: 16px; height: 16px;">
                                        <span>Koniec dnia <span x-text="day.num"></span></span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <!-- POI Group Header -->
                        <template x-if="item.type === 'poi-group-header'">
                            <div class="poi-group-header" :class="'poi-group--' + item.role">
                                <img :src="'<?= asset('icons/') ?>' + (item.role === 'attraction' ? 'star' : item.role === 'logistics' ? 'wrench' : 'alert-triangle') + '.svg'"
                                     alt="" style="width: 12px; height: 12px; opacity: 0.7;">
                                <span x-text="item.role === 'attraction' ? 'Warto zobaczyć' : item.role === 'logistics' ? 'Logistyka' : 'Ważne'"></span>
                                <span class="poi-group-count" x-text="'(' + item.count + ')'"></span>
                            </div>
                        </template>

                        <!-- POI Card -->
                        <template x-if="item.type === 'poi'">
                            <div x-data="{ poi: item }">
                                <div class="poi-card"
                                     @click="PlannerPOI.openPopup(poi.poi)"
                                     :data-poi-id="poi.poi.properties.poi_id">

                                    <div class="poi-card-content">
                                        <div class="poi-card-icon">
                                            <img :src="'<?= asset('icons/') ?>' + (poi.poi.properties.icon || 'map-pin') + '.svg'"
                                                 alt="" style="width: 20px; height: 20px;">
                                        </div>

                                        <div class="poi-card-info">
                                            <div class="poi-card-name" x-text="poi.poi.properties.name"></div>
                                            <div class="poi-card-meta">
                                                <span class="poi-category" x-text="poi.poi.properties.subtype_name || poi.poi.properties.category_name"></span>
                                                <!-- ✅ Dystans od startu trasy -->
                                                <span class="poi-route-km">
                                                    <img src="<?= asset('icons/navigation.svg') ?>" alt="" style="width: 10px; height: 10px; opacity: 0.5;">
                                                    km <span x-text="poi.routeDistanceKm"></span>
                                                </span>
                                                <!-- Odległość od ścieżki -->
                                                <span class="poi-distance">
                                                    <img src="<?= asset('icons/minimize-2.svg') ?>" alt="" style="width: 10px; height: 10px; opacity: 0.5;">
                                                    <span x-text="poi.poi._distance"></span>m
                                                </span>
                                            </div>
                                        </div>

                                        <template x-if="poi.poi.properties.has_photo">
                                            <div class="poi-card-thumb">
                                                <img :src="'<?= get_base_url() ?>/api/photos/thumbnail.php?poi_id=' + poi.poi.properties.poi_id + '&size=100'"
                                                     alt="" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px;">
                                            </div>
                                        </template>
                                    </div>

                                    <div class="poi-card-arrow">
                                        <img src="<?= asset('icons/chevron-right.svg') ?>" alt="" style="width: 12px; height: 12px; opacity: 0.4;">
                                    </div>
                                </div>
                            </div>
                        </template>

                    </div>
                </template>
            </div>
        </div>
    </template>
</div>