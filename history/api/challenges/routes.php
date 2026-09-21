<?php
/* ============================================================================
   CHALLENGE ROUTES API - v2.0 WITH MODELS
   GET /api/challenges/routes.php?challenge_id=1&user_local_id=xxx
   
   Returns all routes in a challenge with completion status
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['challenge_id'])) {
    sendError('challenge_id parameter is required');
}

$challengeId = (int) $_GET['challenge_id'];

try {
    $db = getDB();
    
    // ✅ Initialize models
    $challengeModel = new ChallengeModel($db);
    $routeModel = new RouteModel($db);
    
    // ✅ Get user (prioritize JWT token over local_id)
    $userLocalId = $_GET['user_local_id'] ?? null;
    $user = getUserFromAuth($userLocalId);
    $userId = $user['user_id'];
    
    // ✅ Get user's completed routes from progress
    $completedRoutes = [];
    if ($userId) {
        $progress = $challengeModel->getUserChallengeProgressForChallangeId($userId, $challengeId);
        
        if ($progress) {
            $completedRoutes = json_decode($progress['completed_routes'], true) ?: [];
        }
    }
    
    // ✅ Get all routes using model
    $routes = $challengeModel->getChallengeRoutes($challengeId);
    
    // ✅ Format and add completion status
    foreach ($routes as &$route) {
        $route['route_id'] = (int) $route['route_id'];
        $route['distance_km'] = (float) $route['distance_km'];
        $route['ascent_m'] = (int) $route['ascent_m'];
        $route['required'] = (bool) $route['required'];
        $route['completed'] = in_array($route['route_id'], $completedRoutes);
        
        // Get additional route meta if available
        $routeMeta = $routeModel->getRouteMeta($route['route_id']);
        if ($routeMeta) {
            $route['difficulty_level'] = $routeMeta['difficulty_level'];
            $route['difficulty_score'] = (float)($routeMeta['difficulty_score'] ?? 0);
            $route['route_type'] = $routeMeta['route_type'];
        }
    }
    
    sendJSON(true, [
        'routes' => $routes,
        'total' => count($routes)
    ]);
    
} catch (Exception $e) {
    log_debug('Challenge routes error: ' . $e->getMessage(), 'error');
    sendError('Failed to load routes: ' . $e->getMessage(), 500);
}