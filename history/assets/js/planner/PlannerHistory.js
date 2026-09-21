/* ============================================================================
   PLANNER HISTORY - Undo/Redo stack
   Stores waypoint snapshots. On undo/redo restores full state.
   ============================================================================ */

window.PlannerHistory = {
    _stack: [],
    _pointer: -1,
    _maxSize: 50,
    _ignoreNext: false,   // flag to prevent recording during restore

    // ========================================================================
    // INIT
    // ========================================================================
    init() {
        PlannerEvents.on('history:push', (d) => this.push(d));
        PlannerEvents.on('history:undo', () => this.undo());
        PlannerEvents.on('history:redo', () => this.redo());

        console.log('✅ PlannerHistory initialized');
    },

    // ========================================================================
    // PUSH (record action)
    // ========================================================================
    push({ type, data }) {
        if (this._ignoreNext) return;

        // Take snapshot of current waypoints
        const snapshot = PlannerState.waypoints.map(wp => ({
            id: wp.id, lat: wp.lat, lng: wp.lng,
            type: wp.type, label: wp.label, snapped: wp.snapped
        }));

        // Truncate redo stack if we're not at the end
        if (this._pointer < this._stack.length - 1) {
            this._stack = this._stack.slice(0, this._pointer + 1);
        }

        this._stack.push({ type, data, snapshot });

        // Enforce max size
        if (this._stack.length > this._maxSize) {
            this._stack.shift();
        } else {
            this._pointer++;
        }

        this.updateFlags();
    },

    // ========================================================================
    // UNDO
    // ========================================================================
    undo() {
        if (this._pointer < 0) return;

        // Current entry has the snapshot BEFORE this action was applied
        // We need the previous snapshot
        const entry = this._stack[this._pointer];
        this._pointer--;

        if (this._pointer >= 0) {
            const prev = this._stack[this._pointer];
            this.restore(prev.snapshot);
        } else {
            // Nothing left - clear all
            this.restore([]);
        }

        this.updateFlags();
    },

    // ========================================================================
    // REDO
    // ========================================================================
    redo() {
        if (this._pointer >= this._stack.length - 1) return;

        this._pointer++;
        const entry = this._stack[this._pointer];
        this.restore(entry.snapshot);

        this.updateFlags();
    },

    // ========================================================================
    // RESTORE STATE
    // ========================================================================
    restore(snapshot) {
        this._ignoreNext = true;

        // Restore waypoints through PlannerWaypoints
        PlannerEvents.emit('history:restore-waypoints', { waypoints: snapshot });

        // Re-route all
        PlannerRouter.rebuildAllSegments();

        this._ignoreNext = false;
    },

    // ========================================================================
    // UPDATE FLAGS
    // ========================================================================
    updateFlags() {
        PlannerState.canUndo = this._pointer >= 0;
        PlannerState.canRedo = this._pointer < this._stack.length - 1;
        PlannerEvents.emit('history:changed', {
            canUndo: PlannerState.canUndo,
            canRedo: PlannerState.canRedo
        });
    },

    // ========================================================================
    // CLEAR
    // ========================================================================
    clear() {
        this._stack = [];
        this._pointer = -1;
        this.updateFlags();
    }
};

console.log('✅ PlannerHistory module loaded');