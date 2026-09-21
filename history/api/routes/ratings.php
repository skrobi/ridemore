<?php
/* ============================================================================
   ROUTE RATINGS API - v3.0 FULLY WITH MODELS
   GET    /api/routes/ratings.php?id=123       - List ratings
   POST   /api/routes/ratings.php?id=123       - Add/update rating
   DELETE /api/routes/ratings.php?id=123       - Delete rating
   ============================================================================ */

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];

// Validate route_id
if (!isset($_GET['id'])) {
    sendError('route_id is required', 400);
}

try {
    $db = getDB();
    $routeId = validateRouteId($_GET['id']);
    
    // ✅ Initialize models
    $routeModel = new RouteModel($db);
    
    // ========================================================================
    // GET - List ratings (public)
    // ========================================================================
    
    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
        
        // ✅ USE MODEL
        $ratings = $routeModel->getRouteRatings($routeId, $limit);
        $summary = $routeModel->getRatingSummary($routeId);
        $breakdown = $routeModel->getRatingBreakdown($routeId);
        
        sendJSON(true, [
            'route_id' => $routeId,
            'ratings' => $ratings,
            'count' => $summary['total_count'],
            'average' => $summary['average'],
            'breakdown' => $breakdown,
            'has_more' => count($ratings) >= $limit && $summary['total_count'] > $limit
        ]);
    }
    
    // ========================================================================
    // POST - Add/update rating (authenticated)
    // ========================================================================
    
    elseif ($method === 'POST') {
        $user = requireAuth();
        $input = get_json_body();
        
        if (empty($input)) {
            sendError('Request body is required', 400);
        }
        
        // ✅ USE RouteManager
        $routeManager = new RouteManager($db);
        $result = $routeManager->rateRoute($routeId, $user['user_id'], $input);
        
        log_debug("User {$user['user_id']} rated route $routeId: {$input['rating']}", 'info');
        
        sendJSON(true, $result);
    }
    
    // ========================================================================
    // DELETE - Delete rating (authenticated)
    // ========================================================================
    
    elseif ($method === 'DELETE') {
        $user = requireAuth();
        
        // ✅ USE RouteManager
        $routeManager = new RouteManager($db);
        $result = $routeManager->deleteRating($routeId, $user['user_id']);
        
        log_debug("User {$user['user_id']} deleted rating for route $routeId", 'info');
        
        sendJSON(true, $result);
    }
    
    // ========================================================================
    // Other methods not allowed
    // ========================================================================
    
    else {
        http_response_code(405);
        header('Allow: GET, POST, DELETE');
        sendError('Method not allowed. Allowed: GET, POST, DELETE', 405);
    }
    
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    log_debug('Ratings API error: ' . $e->getMessage(), 'error');
    sendError($e->getMessage(), $code);
}