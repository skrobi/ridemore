<?php
/* ============================================================================
   DELETE ROUTE API - v2.0 SIMPLIFIED
   DELETE /api/routes/delete.php?id=48
   
   Uses RouteManager (no BaseRouteAction needed)
   Universal endpoint for ALL routes (activities, planned, etc.)
   ============================================================================ */

require_once __DIR__ . '/../config.php';

// Validate method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    header('Allow: DELETE');
    sendError('Method not allowed. Use DELETE', 405);
}

// Validate route_id
if (!isset($_GET['id'])) {
    sendError('route_id is required', 400);
}

try {
    $db = getDB();
    $routeId = validateRouteId($_GET['id']);
    $user = requireAuth();
    
    // ✅ Execute delete using RouteManager
    $routeManager = new RouteManager($db);
    $result = $routeManager->deleteRoute($routeId, $user['user_id']);
    
    // Log
    log_debug("User {$user['user_id']} deleted route $routeId", 'info');
    
    // Response
    sendJSON(true, $result);
    
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    log_debug('Delete route error: ' . $e->getMessage(), 'error');
    sendError($e->getMessage(), $code);
}