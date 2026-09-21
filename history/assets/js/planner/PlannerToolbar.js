/* ============================================================================
 PLANNER TOOLBAR - Generic pluginable toolbar system
 Modules register buttons; toolbar renders & manages state.
 ============================================================================ */

window.PlannerToolbar = {
    _buttons: [], // all registered buttons
    _container: null, // DOM container
    _shortcuts: {}, // key → button id

    // ========================================================================
    // REGISTER BUTTON
    // ========================================================================
    register(config) {
        // config: {id, group, icon, tooltip, shortcut, toggle, active, visible, disabled, onClick}
        const btn = {
            id: config.id,
            group: config.group || 'default',
            icon: config.icon,
            tooltip: config.tooltip || '',
            shortcut: config.shortcut || null,
            toggle: config.toggle || false, // true = toggleable on/off
            active: config.active || false, // initial state
            visible: config.visible || (() => true),
            disabled: config.disabled || (() => false),
            onClick: config.onClick
        };

        this._buttons.push(btn);

        if (btn.shortcut) {
            this._shortcuts[btn.shortcut.toLowerCase()] = btn.id;
        }
    },

    // ========================================================================
    // INIT - render toolbar + setup keyboard
    // ========================================================================
    init(containerSelector = '.map-toolbar') {
        this._container = document.querySelector(containerSelector);
        if (!this._container) {
            console.error('❌ Toolbar container not found:', containerSelector);
            return;
        }

        this.render();
        this.setupKeyboard();

        // Re-render on state changes
        PlannerEvents.on('mode:changed', () => this.render());
        PlannerEvents.on('snap:toggled', () => this.render());
        PlannerEvents.on('history:changed', () => this.render());
        PlannerEvents.on('challenge:loaded', () => this.render());
        PlannerEvents.on('challenge:cleared', () => this.render());

        console.log('✅ PlannerToolbar initialized with', this._buttons.length, 'buttons');
    },

    // ========================================================================
    // RENDER
    // ========================================================================
    render() {
        if (!this._container)
            return;

        // Group buttons
        const groups = {};
        this._buttons.forEach(btn => {
            const visible = typeof btn.visible === 'function' ? btn.visible() : btn.visible;
            if (!visible)
                return;
            if (!groups[btn.group])
                groups[btn.group] = [];
            groups[btn.group].push(btn);
        });

        let html = '';
        Object.entries(groups).forEach(([groupName, buttons], gIdx) => {
            if (gIdx > 0)
                html += '<div class="toolbar-separator"></div>';

            buttons.forEach(btn => {
                // ✅ Handle spacer (empty element)
                if (btn.group === 'spacer') {
                    html += '<div class="toolbar-spacer"></div>';
                    return;
                }

                const disabled = typeof btn.disabled === 'function' ? btn.disabled() : btn.disabled;
                const active = btn.active;

                const classes = [
                    'toolbar-btn',
                    active ? 'active' : '',
                    disabled ? 'disabled' : ''
                ].filter(Boolean).join(' ');

                const title = btn.tooltip + (btn.shortcut ? ` (${btn.shortcut.toUpperCase()})` : '');

                html += `<button class="${classes}" data-btn-id="${btn.id}" title="${title}" ${disabled ? 'disabled' : ''}>
                            <i class="${btn.icon}"></i>
                        </button>`;
            });
        });

        this._container.innerHTML = html;

        // Attach click handlers
        this._container.querySelectorAll('[data-btn-id]').forEach(el => {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = el.dataset.btnId;
                this.handleClick(id);
            });
        });
    },

    // ========================================================================
    // CLICK HANDLER
    // ========================================================================
    handleClick(btnId) {
        const btn = this._buttons.find(b => b.id === btnId);
        if (!btn)
            return;

        const disabled = typeof btn.disabled === 'function' ? btn.disabled() : btn.disabled;
        if (disabled)
            return;

        if (btn.group && !btn.toggle) {
            // Radio group: deactivate others in same group
            this._buttons.forEach(b => {
                if (b.group === btn.group && b.id !== btn.id)
                    b.active = false;
            });
            btn.active = true;
        } else if (btn.toggle) {
            btn.active = !btn.active;
        }

        if (btn.onClick)
            btn.onClick(btn.active);
        this.render();
    },

    // ========================================================================
    // KEYBOARD SHORTCUTS
    // ========================================================================
    setupKeyboard() {
        document.addEventListener('keydown', (e) => {
            // Don't handle if typing in input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')
                return;

            let key = '';
            if (e.ctrlKey || e.metaKey)
                key += 'ctrl+';
            if (e.shiftKey)
                key += 'shift+';
            key += e.key.toLowerCase();

            const btnId = this._shortcuts[key];
            if (btnId) {
                e.preventDefault();
                this.handleClick(btnId);
            }
        });
    },

    // ========================================================================
    // PROGRAMMATIC STATE
    // ========================================================================
    setActive(btnId, active) {
        const btn = this._buttons.find(b => b.id === btnId);
        if (btn) {
            btn.active = active;
            this.render();
        }
    },

    isActive(btnId) {
        const btn = this._buttons.find(b => b.id === btnId);
        return btn ? btn.active : false;
    },

    getButton(btnId) {
        return this._buttons.find(b => b.id === btnId);
    }
};

// ============================================================================
// REGISTER DEFAULT BUTTONS
// ============================================================================

// Mode: Route
PlannerToolbar.register({
    id: 'mode-route', group: 'mode',
    icon: 'fas fa-route',
    tooltip: 'Routing po drogach',
    shortcut: 'r',
    active: true,
    onClick: () => {
        PlannerState.mode = 'route';
        PlannerMap.setCursor('crosshair');
        PlannerEvents.emit('mode:changed', {mode: 'route'});
    }
});

// Mode: Freehand (Faza 4)
PlannerToolbar.register({
    id: 'mode-freehand', group: 'mode',
    icon: 'fas fa-pencil-alt',
    tooltip: 'Rysuj odręcznie',
    shortcut: 'f',
    onClick: () => {
        PlannerState.mode = 'freehand';
        PlannerMap.setCursor('crosshair');
        PlannerEvents.emit('mode:changed', {mode: 'freehand'});
    }
});

// Undo
PlannerToolbar.register({
    id: 'undo', group: 'history',
    icon: 'fas fa-undo',
    tooltip: 'Cofnij',
    shortcut: 'ctrl+z',
    disabled: () => !PlannerState.canUndo,
    onClick: () => PlannerEvents.emit('history:undo')
});

// Redo
PlannerToolbar.register({
    id: 'redo', group: 'history',
    icon: 'fas fa-redo',
    tooltip: 'Ponów',
    shortcut: 'ctrl+y',
    disabled: () => !PlannerState.canRedo,
    onClick: () => PlannerEvents.emit('history:redo')
});

// Snap toggle
PlannerToolbar.register({
    id: 'snap-toggle', group: 'tools',
    icon: 'fas fa-magnet',
    tooltip: 'Przyciągaj do wyzwania',
    shortcut: 's',
    toggle: true,
    active: true,
    visible: () => !!PlannerState.challenge,
    onClick: (active) => {
        PlannerState.snapEnabled = active;
        PlannerEvents.emit('snap:toggled', {enabled: active});
    }
});

// Delete last waypoint
PlannerToolbar.register({
    id: 'delete-last', group: 'tools',
    icon: 'fas fa-backspace',
    tooltip: 'Usuń ostatni punkt',
    shortcut: 'backspace',
    disabled: () => PlannerState.waypoints.length === 0,
    onClick: () => {
        const len = PlannerState.waypoints.length;
        if (len > 0)
            PlannerEvents.emit('waypoint:request-remove', {idx: len - 1});
    }
});

// Separator (empty spacer)
PlannerToolbar.register({
    id: 'spacer-1',
    group: 'spacer',
    icon: '', // no icon
    tooltip: '',
    visible: () => true,
    onClick: () => {
    } // no action
});


// Reverse route direction (swap start/end)
PlannerToolbar.register({
    id: 'reverse-route',
    group: 'route-actions',
    icon: 'fas fa-sync-alt',
    tooltip: 'Odwróć kierunek trasy (zamień start z końcem)',
    shortcut: 'ctrl+r',
    disabled: () => PlannerState.waypoints.length < 2,
    onClick: () => {
        console.log('🔄 Reversing route: swapping start ↔️ end');
        
        const wps = PlannerState.waypoints;
        if (wps.length < 2) return;
        
        // Get first and last
        const firstIdx = 0;
        const lastIdx = wps.length - 1;
        
        const firstWp = {...wps[firstIdx]};
        const lastWp = {...wps[lastIdx]};
        
        // Swap coordinates
        wps[firstIdx].lat = lastWp.lat;
        wps[firstIdx].lng = lastWp.lng;
        wps[firstIdx].label = lastWp.label;
        
        wps[lastIdx].lat = firstWp.lat;
        wps[lastIdx].lng = firstWp.lng;
        wps[lastIdx].label = firstWp.label;
        
        // Toggle flag
        PlannerState.routeReversed = !PlannerState.routeReversed;
        
        // Update markers on map
        const firstMarker = PlannerWaypoints._markers.get(wps[firstIdx].id);
        const lastMarker = PlannerWaypoints._markers.get(wps[lastIdx].id);
        
        if (firstMarker) firstMarker.setLatLng([wps[firstIdx].lat, wps[firstIdx].lng]);
        if (lastMarker) lastMarker.setLatLng([wps[lastIdx].lat, wps[lastIdx].lng]);
        
        // Re-route all segments
        PlannerRouter.rebuildAllSegments();
        
        console.log(`✅ Start ↔️ End swapped (reversed: ${PlannerState.routeReversed})`);
    }
});

console.log('✅ PlannerToolbar module loaded');

