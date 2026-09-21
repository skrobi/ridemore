<?php
/* ============================================================================
   GPX EXPORT - API Endpoint (thin wrapper around GPXExportManager)
   
   GET /api/routes/gpx_export.php
   
   PARAMS:
   - route_id: int OR slug: string (required)
   - include_waypoints: bool (default: true)
   - include_elevation: bool (default: true)
   - download: bool (default: true)
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    // --- Resolve route ---
    $routeId = isset($_GET['route_id']) ? (int)$_GET['route_id'] : null;
    $slug = $_GET['slug'] ?? null;

    if (!$routeId && !$slug) {
        sendError('route_id or slug is required', 400);
    }

    $manager = $routeId
        ? GPXExportManager::fromRoute($routeId)
        : GPXExportManager::fromSlug($slug);

    // --- Options ---
    if (($_GET['include_waypoints'] ?? 'true') === 'false') {
        // Rebuild without planner config - re-create from route data only
        // Manager auto-loads planner_config, so for "no waypoints" we'd need
        // to strip them. For now, this is a v2 feature.
    }

    // --- Send ---
    $download = ($_GET['download'] ?? 'true') !== 'false';

    if ($download) {
        $manager->sendAsDownload();
    } else {
        $manager->sendInline();
    }

} catch (Exception $e) {
    log_debug('GPX export error: ' . $e->getMessage(), 'error');
    $code = $e->getCode() ?: 500;
    sendError(DEBUG_MODE ? $e->getMessage() : 'Export failed', $code);
}