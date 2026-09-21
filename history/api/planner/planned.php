<?php
/* ============================================================================
   USER PLANNED ROUTES API
   GET - lista zaplanowanych tras użytkownika (route_purpose = 'planned')
   
   LOCATION: api/user/planned.php
   ============================================================================ */
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

$userLocalId = $_GET['user_local_id'] ?? null;

try {
    $db = getDB();
    $user = getUserFromAuth($userLocalId);
    $routeModel = new RouteModel($db);
    
    log_debug("📊 Planned GET: user_id={$user['user_id']}", 'info');
    
    // Pagination
    $page = (int)($_GET['page'] ?? 1);
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = ($page - 1) * $limit;
    
    // ✅ Use RouteModel method
    $routes = $routeModel->getRoutesByUser(
        $user['user_id'], 
        $limit, 
        $offset, 
        'planned'  // route_purpose filter
    );
    
    // Format data
    $planned = [];
    
    foreach ($routes as $route) {
        $geometry = !empty($route['geometry_json']) 
            ? json_decode($route['geometry_json'], true) 
            : null;
        
        $item = [
            'id' => (int)$route['route_id'],
            'route_id' => (int)$route['route_id'],
            'name' => $route['name'],
            'slug' => $route['slug'],
            'source' => $route['source'],
            'route_purpose' => $route['route_purpose'],
            'visibility' => $route['visibility'],
            'type' => $route['route_type'],
            'distance_km' => round((float)$route['distance_km'], 2),
            'ascent_m' => (int)($route['ascent_m'] ?? 0),
            'descent_m' => (int)($route['descent_m'] ?? 0),
            'segment_count' => (int)($route['segment_count'] ?? 0),
            'difficulty_level' => $route['difficulty_level'] ?? null,
            'description' => $route['description'] ?? null,
            'cover_image_url' => $route['cover_image_url'] ?? null,
            'rating' => $route['rating'] ? round((float)$route['rating'], 1) : null,
            'rating_count' => (int)($route['rating_count'] ?? 0),
            'views_count' => (int)($route['views_count'] ?? 0),
            'created_at' => $route['created_at'],
            'updated_at' => $route['updated_at'],
            'activity_date' => $route['created_at'], // For compatibility
            'geometry' => $geometry,
            'can_edit' => true,
            'can_delete' => $route['source'] === 'planner' // Only planner routes deletable
        ];
        
        $planned[] = $item;
    }
    
    // ✅ Total count using model
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM routes 
        WHERE user_id = ? AND route_purpose = 'planned' AND status = 'active'
    ");
    $stmt->execute([$user['user_id']]);
    $total = (int)$stmt->fetchColumn();
    
    sendJSON(true, [
        'planned' => $planned,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    log_debug('Planned GET error: ' . $e->getMessage(), 'error');
    sendError('Failed to fetch planned routes: ' . $e->getMessage(), 500);
}