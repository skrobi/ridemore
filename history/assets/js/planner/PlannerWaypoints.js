/* ============================================================================
 PLANNER WAYPOINTS - CRUD, markers, drag, segment drag-to-split
 ============================================================================ */

window.PlannerWaypoints = {
    _markers: new Map(), // wpId → L.marker
    _segmentHits: new Map(), // segIdx → L.polyline (invisible hit area)
    _dragPreview: null, // Temporary marker during segment drag

    // ========================================================================
    // INIT
    // ========================================================================
    init() {
        PlannerEvents.on('map:clicked', (d) => this.onMapClick(d));
        PlannerEvents.on('waypoint:request-remove', (d) => this.remove(d.idx));
        PlannerEvents.on('route:segment', (d) => this.updateSegmentHit(d.fromIdx, d.coords));
        PlannerEvents.on('history:restore-waypoints', (d) => this.restoreState(d));
        PlannerEvents.on('day:add', (d) => this.setDayBreak(d.afterWpIdx));

        console.log('✅ PlannerWaypoints initialized');
    },

    setDayBreak(idx) {
        PlannerState.setWaypointAsDayBreak(idx);
        this.refreshAllMarkers(); // Odśwież żeby zmienić ikonkę

        // History
        PlannerEvents.emit('history:push', {
            type: 'day:add',
            data: {idx}
        });
    },

    // ========================================================================
    // MAP CLICK → ADD WAYPOINT
    // ========================================================================
    onMapClick( { lat, lng, mode }) {
        if (mode !== 'route' && mode !== 'freehand')
            return;
        this.add(lat, lng);
    },

    // ========================================================================
    // ADD
    // ========================================================================
    add(lat, lng, insertIdx = null, opts = {}) {
        console.log('🔵 ADD WAYPOINT', lat, lng, 'current count:', PlannerState.waypoints.length);
        const map = PlannerMap.getMap();
        const state = PlannerState;

        // Snap
        let snapped = false;
        if (state.snapEnabled && state.challenge && !opts.skipSnap) {
            const snap = PlannerSnap.find(lat, lng, state.snapThreshold);
            if (snap) {
                lat = snap.lat;
                lng = snap.lng;
                snapped = true;
            }
        }

        const wp = {
            id: state.nextWpId(),
            lat, lng,
            type: opts.type || 'via',
            label: opts.label || null,
            snapped
        };

        // Insert at position or append
        const idx = (insertIdx !== null) ? insertIdx : state.waypoints.length;
        PlannerState.addWaypoint(wp, idx);

        // Create marker
        this.createMarker(wp, idx);
        this.refreshAllMarkers();

        // Emit
        PlannerEvents.emit('waypoint:added', {idx, wp});

        // History
        PlannerEvents.emit('history:push', {
            type: 'waypoint:add',
            data: {idx, wp: {...wp}}
        });
        console.log('✅ WAYPOINT ADDED, new count:', PlannerState.waypoints.length);
        return idx;
    },

    // ========================================================================
    // INSERT BETWEEN (from segment drag)
    // ========================================================================
    insertBetween(segIdx, lat, lng) {
        const insertIdx = segIdx + 1;
        this.add(lat, lng, insertIdx, {skipSnap: false});
    },

    // ========================================================================
    // REMOVE
    // ========================================================================
    remove(idx) {
        const state = PlannerState;
        if (idx < 0 || idx >= state.waypoints.length)
            return;

        const wp = state.waypoints[idx];

        // Remove marker
        const marker = this._markers.get(wp.id);
        if (marker) {
            PlannerMap.getMap().removeLayer(marker);
            this._markers.delete(wp.id);
        }

        state.removeWaypoint(idx);
        this.refreshAllMarkers();

        PlannerEvents.emit('waypoint:removed', {idx, wp});
        PlannerEvents.emit('history:push', {
            type: 'waypoint:remove',
            data: {idx, wp: {...wp}}
        });
    },

    // ========================================================================
    // MOVE (drag end)
    // ========================================================================
    move(wpId, lat, lng) {
        const state = PlannerState;
        const idx = state.waypoints.findIndex(w => w.id === wpId);
        if (idx < 0)
            return;

        const wp = state.waypoints[idx];
        const oldLat = wp.lat, oldLng = wp.lng;

        // Snap
        let snapped = false;
        if (state.snapEnabled && state.challenge) {
            const snap = PlannerSnap.find(lat, lng, state.snapThreshold);
            if (snap) {
                lat = snap.lat;
                lng = snap.lng;
                snapped = true;
            }
        }

        wp.lat = lat;
        wp.lng = lng;
        wp.snapped = snapped;

        // Update marker position (in case snap moved it)
        const marker = this._markers.get(wpId);
        if (marker)
            marker.setLatLng([lat, lng]);

        PlannerEvents.emit('waypoint:moved', {
            idx, wp,
            oldPos: {lat: oldLat, lng: oldLng}
        });
        PlannerEvents.emit('history:push', {
            type: 'waypoint:move',
            data: {idx, wp: {...wp}, oldLat, oldLng}
        });
    },

    // ========================================================================
    // CLEAR ALL
    // ========================================================================
    clearAll() {
        const map = PlannerMap.getMap();
        this._markers.forEach(m => map.removeLayer(m));
        this._markers.clear();
        this._segmentHits.forEach(h => map.removeLayer(h));
        this._segmentHits.clear();
        if (this._dragPreview) {
            map.removeLayer(this._dragPreview);
            this._dragPreview = null;
        }
        PlannerState.clearWaypoints();
        PlannerEvents.emit('waypoints:cleared');
    },

    // ========================================================================
    // MARKER CREATION
    // ========================================================================
    createMarker(wp, idx) {
        const map = PlannerMap.getMap();
        const type = PlannerState.getWpType(idx);
        const label = PlannerState.getWpLabel(idx);

        const icon = L.divIcon({
            className: '',
            html: `<div class="planner-wp-marker ${type}" data-wp-id="${wp.id}">${label}</div>`,
            iconSize: [28, 28],
            iconAnchor: [14, 14]
        });

        const marker = L.marker([wp.lat, wp.lng], {
            icon,
            draggable: true,
            pane: 'waypointPane'
        }).addTo(map);

        // Drag
        marker.on('dragend', (e) => {
            const pos = e.target.getLatLng();
            this.move(wp.id, pos.lat, pos.lng);
        });

        // Context menu (right click)
        marker.on('contextmenu', (e) => {
            L.DomEvent.preventDefault(e);
            const wpIdx = PlannerState.waypoints.findIndex(w => w.id === wp.id);
            this.showContextMenu(e.originalEvent, wpIdx);
        });

        this._markers.set(wp.id, marker);
        return marker;
    },

    // ========================================================================
    // REFRESH ALL MARKERS (after add/remove, types & labels change)
    // ========================================================================
    refreshAllMarkers() {
        PlannerState.waypoints.forEach((wp, idx) => {
            const marker = this._markers.get(wp.id);
            if (!marker)
                return;

            const type = PlannerState.getWpType(idx);
            const label = PlannerState.getWpLabel(idx);

            marker.setIcon(L.divIcon({
                className: '',
                html: `<div class="planner-wp-marker ${type}" data-wp-id="${wp.id}">${label}</div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            }));
        });
    },

    // ========================================================================
    // SEGMENT HIT AREAS (invisible fat polylines for drag-to-split)
    // ========================================================================
    updateSegmentHit(segIdx, coords) {
        const map = PlannerMap.getMap();

        // Remove old
        const old = this._segmentHits.get(segIdx);
        if (old)
            map.removeLayer(old);

        if (!coords || coords.length < 2)
            return;

        // Invisible fat polyline
        const hit = L.polyline(coords, {
            weight: 20,
            opacity: 0,
            pane: 'segmentHitPane',
            interactive: true,
            bubblingMouseEvents: false
        }).addTo(map);

        hit._segIdx = segIdx;

        // Drag-to-split
        hit.on('mousedown', (e) => {
            L.DomEvent.stopPropagation(e);
            this.startSegmentDrag(e, segIdx);
        });

        // ✅ FIX: nie zmieniaj kursora gdy mysz jest nad markerem waypointa
        hit.on('mouseover', (e) => {
            const target = e.originalEvent?.target;
            const isOverMarker = target?.closest?.('.planner-wp-marker') ||
                    target?.classList?.contains('leaflet-marker-icon');
            if (!isOverMarker) {
                map.getContainer().style.cursor = 'grab';
            }
        });

        hit.on('mouseout', () => {
            if (!this._isDraggingSegment) {
                map.getContainer().style.cursor = PlannerState.mode === 'route' ? 'crosshair' : '';
            }
        });

        this._segmentHits.set(segIdx, hit);
    },

    clearAllSegmentHits() {
        const map = PlannerMap.getMap();
        this._segmentHits.forEach(h => map.removeLayer(h));
        this._segmentHits.clear();
    },

    // ========================================================================
    // SEGMENT DRAG TO SPLIT
    // ========================================================================
    _isDraggingSegment: false,

    startSegmentDrag(e, segIdx) {
        const map = PlannerMap.getMap();
        this._isDraggingSegment = true;
        map.dragging.disable();
        map.getContainer().style.cursor = 'grabbing';

        // Create preview marker
        const latlng = e.latlng;
        this._dragPreview = L.circleMarker(latlng, {
            radius: 8, color: '#3b82f6', fillColor: '#3b82f6',
            fillOpacity: 0.8, weight: 2, pane: 'waypointPane'
        }).addTo(map);

        const onMove = (ev) => {
            if (this._dragPreview)
                this._dragPreview.setLatLng(ev.latlng);
        };

        const onUp = (ev) => {
            map.off('mousemove', onMove);
            map.off('mouseup', onUp);
            map.dragging.enable();
            this._isDraggingSegment = false;
            map.getContainer().style.cursor = 'crosshair';

            if (this._dragPreview) {
                map.removeLayer(this._dragPreview);
                this._dragPreview = null;
            }

            // Insert new waypoint at drop position
            this.insertBetween(segIdx, ev.latlng.lat, ev.latlng.lng);
        };

        map.on('mousemove', onMove);
        map.on('mouseup', onUp);
    },

    // ========================================================================
    // CONTEXT MENU (right-click on waypoint)
    // ========================================================================
    showContextMenu(event, wpIdx) {
        this.hideContextMenu();

        const state = PlannerState;
        const wp = state.waypoints[wpIdx];
        if (!wp)
            return;

        const menu = document.createElement('div');
        menu.className = 'planner-context-menu';
        menu.style.cssText = `
        position: fixed; left: ${event.clientX}px; top: ${event.clientY}px;
        background: white; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        padding: 4px 0; z-index: 9999; min-width: 160px; font-size: 13px;
    `;

        const items = [];

        // Usuń punkt
        items.push({label: '🗑 Usuń punkt', action: () => this.remove(wpIdx)});

        // Koniec dnia / Usuń koniec dnia
        const isDayBreak = wp.type === 'day-break';
        const isStartOrEnd = wpIdx === 0 || wpIdx === state.waypoints.length - 1;

        if (!isStartOrEnd) {
            if (isDayBreak) {
                items.push({
                    label: '❌ Usuń koniec dnia',
                    action: () => {
                        PlannerState.removeWaypointDayBreak(wpIdx);
                        this.refreshAllMarkers();
                    }
                });
            } else {
                items.push({
                    label: '🏕 Ustaw koniec dnia',
                    action: () => PlannerEvents.emit('day:add', {afterWpIdx: wpIdx})
                });
            }
        }

        items.forEach(item => {
            const el = document.createElement('div');
            el.textContent = item.label;
            el.style.cssText = 'padding: 8px 16px; cursor: pointer; transition: background 0.1s;';
            el.onmouseenter = () => el.style.background = '#f3f4f6';
            el.onmouseleave = () => el.style.background = '';
            el.onclick = () => {
                item.action();
                this.hideContextMenu();
            };
            menu.appendChild(el);
        });

        document.body.appendChild(menu);
        this._contextMenu = menu;

        setTimeout(() => {
            document.addEventListener('click', this._closeCtxHandler = () => this.hideContextMenu(), {once: true});
        }, 10);
    },

    hideContextMenu() {
        if (this._contextMenu) {
            this._contextMenu.remove();
            this._contextMenu = null;
        }
    },

    // ========================================================================
// RESTORE STATE (for undo/redo)
// ========================================================================
    restoreState( { waypoints }) {
        this.clearAll();
        PlannerState.restoreWaypoints(waypoints);
        waypoints.forEach((wp, idx) => {
            this.createMarker(wp, idx);
        });
        this.refreshAllMarkers();
    }
};

console.log('✅ PlannerWaypoints module loaded');