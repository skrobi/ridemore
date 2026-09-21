<?php
/* ============================================================================
   UPDATE ROUTE API - v2.1 WITH TRANSACTION HANDLING
   PATCH /api/routes/update.php?id=48
   ============================================================================ */

require_once __DIR__ . '/../config.php';

// Validate method
if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: PATCH, POST');
    sendError('Method not allowed. Use PATCH or POST', 405);
}

// Validate route_id
if (!isset($_GET['id'])) {
    sendError('route_id is required', 400);
}

try {
    $db = getDB();
    $routeId = validateRouteId($_GET['id']);
    $user = requireAuth();
    $input = get_json_body();
    
    if (empty($input)) {
        sendError('Request body is required', 400);
    }
    
    // ✅ Execute update using RouteManager
    $routeManager = new RouteManager($db);
    
    try {
        $result = $routeManager->updateRoute($routeId, $user['user_id'], $input);
        
        // Log
        log_debug("User {$user['user_id']} updated route $routeId", 'info');
        
        // Response
        sendJSON(true, $result);
        
    } catch (Exception $e) {
        // ✅ Ensure rollback if transaction is active
        if ($db->inTransaction()) {
            $db->rollBack();
            log_debug("Transaction rolled back due to error", 'warning');
        }
        throw $e;
    }
    
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    log_debug('Update route error: ' . $e->getMessage(), 'error');
    sendError($e->getMessage(), $code);
}