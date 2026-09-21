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
            <span class="snap-badge"><i class="fas fa-magnet"></i> Snap</span>
        </template>
    </div>
    
    <button class="wp-remove" @click.stop="removeWaypoint(wp.idx)" title="Usuń punkt">
        <i class="fas fa-trash-alt"></i>
    </button>
</div>