<?php
/**
 * API: Get challenge routes as GeoJSON for planner
 * GET /api/planner/challenge-routes.php?challenge_id=1&user_local_id=xxx
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['challenge_id'])) {
    sendError('challenge_id parameter is required');
}

$challengeId = (int) $_GET['challenge_id'];
$userLocalId = $_GET['user_local_id'] ?? null;

try {
    $db = getDB();
    $challengeModel = new ChallengeModel($db);
    $routeModel = new RouteModel($db);
    
    // Get challenge basic info
    $challenge = $challengeModel->getChallengeById($challengeId);
    
    if (!$challenge) {
        sendError('Challenge not found', 404);
    }
    
    // Get completed routes if user provided
    $completedRoutes = [];
    if ($userLocalId) {
        $user = getOrCreateUser($userLocalId);
        $userId = $user['user_id'];
        
        $progress = $challengeModel->getUserChallengeProgressForChallangeId($userId, $challengeId);
        
        if ($progress && !empty($progress['completed_routes'])) {
            $decoded = json_decode($progress['completed_routes'], true);
            $completedRoutes = is_array($decoded) ? $decoded : [];
        }
    }
    
    // Get all routes with geometry
    $routes = $challengeModel->getChallengeRoutes($challengeId);
    
    // Build GeoJSON FeatureCollection
    $features = [];
    
    foreach ($routes as $route) {
        $routeId = (int)$route['route_id'];
        
        // Get full route details (includes geometry_json)
        $routeDetails = $routeModel->getRouteById($routeId);
        
        if (!$routeDetails || empty($routeDetails['geometry_json'])) {
            continue;
        }
        
        $isCompleted = in_array($routeId, $completedRoutes);
        
        $features[] = [
            'type' => 'Feature',
            'geometry' => json_decode($routeDetails['geometry_json'], true),
            'properties' => [
                'route_id' => $routeId,
                'name' => $route['name'],
                'distance_km' => (float)$route['distance_km'],
                'ascent_m' => (int)$route['ascent_m'],
                'is_completed' => $isCompleted
            ]
        ];
    }
    
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features
    ];
    
    sendJSON(true, [
        'challenge' => [
            'challenge_id' => (int)$challenge['challenge_id'],
            'name' => $challenge['name'],
            'color' => $challenge['color'] ?? '#8b5cf6',
            'icon' => $challenge['icon'] ?? '🎯',
            'total_routes' => count($features),
            'bbox' => $challengeModel->getChallengeBbox($challengeId),
            'total_distance_km' => (float)$challenge['total_distance_km']
        ],
        'routes' => $geojson
    ]);
    
} catch (Exception $e) {
    log_debug('Challenge routes error: ' . $e->getMessage(), 'error');
    sendError('Failed to load challenge routes: ' . $e->getMessage(), 500);
}