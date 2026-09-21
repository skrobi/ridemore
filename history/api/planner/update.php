<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// ========================================================================
// AUTH
// ========================================================================
$user = requireAuth();
$userId = $user['user_id'];

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ========================================================================
// INPUT
// ========================================================================
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// ========================================================================
// VALIDATION
// ========================================================================
$routeId = (int)($input['route_id'] ?? 0);

if (!$routeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing route_id']);
    exit;
}

$required = ['name', 'geometry', 'distance_km'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

// Validate geometry
if (!isset($input['geometry']['type']) || $input['geometry']['type'] !== 'LineString') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Geometry must be LineString']);
    exit;
}

if (empty($input['geometry']['coordinates']) || count($input['geometry']['coordinates']) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Geometry must have at least 2 coordinates']);
    exit;
}

// ========================================================================
// UPDATE ROUTE
// ========================================================================
try {
    $db = getDB();
    $routeModel = new RouteModel($db);
    $routeManager = new RouteManager($db);
    
    // Verify route exists and is planner source
    $route = $routeModel->getRouteById($routeId);
    
    if (!$route) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Route not found']);
        exit;
    }
    
    if ($route['source'] !== 'planner') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only planner routes can be edited']);
        exit;
    }
    
    log_debug("📝 Updating planner route ID: $routeId", 'info');
    
    // ✅ Prepare update data - RouteManager now handles geometry!
    $updateData = [
        'name' => trim($input['name']),
        'geometry' => $input['geometry'],  // ✅ RouteManager will handle this
        'distance_km' => round((float)$input['distance_km'], 2),
        'ascent_m' => isset($input['ascent_m']) ? (int)$input['ascent_m'] : null,
        'descent_m' => isset($input['descent_m']) ? (int)$input['descent_m'] : null,
        'route_type' => $input['route_type'] ?? null,
        'estimated_time_hours' => isset($input['estimated_time_hours']) 
            ? round((float)$input['estimated_time_hours'], 1) 
            : null,
        'planner_config' => json_encode([
            'challenge_id' => $input['challenge_id'] ?? null,
            'bike_type' => $input['bike_type'] ?? 'road',
            'avg_speed' => $input['avg_speed'] ?? 25,
            'waypoints' => $input['waypoints'] ?? [],
            'route_reversed' => $input['route_reversed'] ?? false,
            'created_with' => 'planner_v1',
            'updated_at' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE)
    ];
    
    // ✅ Use RouteManager - handles everything
    $result = $routeManager->updateRoute($routeId, $userId, $updateData);
    
    if (!$result['success']) {
        throw new Exception($result['error'] ?? 'Update failed');
    }
    
    log_debug("✅ Updated planner route $routeId", 'info');
    
    // ========================================================================
    // RESPONSE
    // ========================================================================
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'route_id' => $routeId,
        'slug' => $route['slug'],
        'url' => get_base_url() . "/routes/{$route['slug']}",
        'message' => 'Route updated successfully'
    ]);
    
} catch (Exception $e) {
    log_debug("❌ Planner update failed: " . $e->getMessage(), 'error');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}