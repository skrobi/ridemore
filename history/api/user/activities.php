<?php
/* ============================================================================
   USER ACTIVITIES API v4.0 - REFACTORED with RouteModel
   GET - lista aktywności użytkownika
   
   LOCATION: api/user/activities.php
   NOTE: DELETE moved to routes/delete.php (universal endpoint)
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed. Use routes/delete.php for DELETE', 405);
}

$userLocalId = $_GET['user_local_id'] ?? null;

try {
    $db = getDB();
    $user = getUserFromAuth($userLocalId);
    
    log_debug("📊 Activities GET: user_id={$user['user_id']}, email={$user['email']}", 'info');
    
    // Pagination
    $page = (int)($_GET['page'] ?? 1);
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = ($page - 1) * $limit;
    
    // ✅ USE RouteModel
    $routeModel = new RouteModel($db);
    $routes = $routeModel->getUserActivities($user['user_id'], $limit, $offset);
    
    // ✅ Format data
    $activities = [];
    
    foreach ($routes as $route) {
        // Get full route data with geometry
        $fullRoute = $routeModel->getRouteById($route['route_id']);
        if (!$fullRoute) continue;
        
        $geometry = json_decode($fullRoute['geometry_json'], true);
        
        $activity = [
            'id' => (int)$route['route_id'],
            'route_id' => (int)$route['route_id'],
            'name' => $route['name'],
            'source' => $fullRoute['source'],
            'visibility' => $route['visibility'],
            'type' => $route['route_type'],
            'distance_km' => round((float)$route['distance_km'], 2),
            'ascent_m' => (int)$route['ascent_m'],
            'descent_m' => (int)$fullRoute['descent_m'],
            'segment_count' => (int)$fullRoute['segment_count'],
            'avg_edge_score' => round((float)($fullRoute['avg_edge_score'] ?? 0), 3),
            'backbone_pct' => round((float)($fullRoute['backbone_pct'] ?? 0), 1),
            'status' => $route['status'],
            'created_at' => $route['created_at'],
            'updated_at' => $fullRoute['updated_at'],
            'date' => date('Y-m-d', strtotime($route['created_at'])),
            
            'difficulty_level' => $fullRoute['difficulty_level'],
            'description' => $fullRoute['description'],
            'gpx_file_path' => $fullRoute['gpx_file_path'],
            'cover_image_url' => $route['cover_image_url'],
            'rating' => round((float)($route['rating'] ?? 0), 1),
            'avg_rating' => round((float)($route['rating'] ?? 0), 1),
            'rating_count' => (int)($route['rating_count'] ?? 0),
            'views_count' => (int)($route['views_count'] ?? 0),
            
            'in_challenge' => false,
            'is_completed' => false,
            
            'geometry' => $geometry,
            
            // Helper flags
            'can_edit' => true,
            'can_delete' => $fullRoute['source'] !== 'admin_upload' // nie można usunąć oficjalnych
        ];
        
        // GPX URL
        if (!empty($activity['gpx_file_path'])) {
            $activity['gpx_url'] = url($activity['gpx_file_path']);
        }
        
        $activities[] = $activity;
    }
    
    // Total count
    $stats = $routeModel->getUserStats($user['user_id']);
    $total = (int)$stats['total_activities'];
    
    sendJSON(true, [
        'activities' => $activities,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    log_debug('Activities GET error: ' . $e->getMessage(), 'error');
    sendError('Failed to fetch activities: ' . $e->getMessage(), 500);
}