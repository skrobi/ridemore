<?php
/* ============================================================================
   KORONA ROWEROWA POLSKI - TOP IN VIEW
   GET /api/routes/top_in_view.php?bbox=minLng,minLat,maxLng,maxLat&limit=10
   
   Zwraca top N tras w widocznym obszarze (sorted by final_score)
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['bbox'])) {
    sendError('bbox parameter is required');
}

try {
    $db = getDB();
    
    // Parse bbox
    $bbox = parseBbox($_GET['bbox']);
    $bboxPolygon = bboxToPolygon($bbox);
    
    // Limit
    $limit = isset($_GET['limit']) ? min(intval($_GET['limit']), 50) : 10;
    
    $sql = "
        SELECT 
            r.route_id,
            r.name,
            r.type,
            r.distance_km,
            r.ascent_m,
            r.difficulty_score,
            r.final_score,
            r.in_top_layer,
            r.in_medal_layer,
            r.is_loop,
            COALESCE(re.rating, 0) as avg_rating,
            COALESCE(re.rating_count, 0) as rating_count
        FROM routes r
        LEFT JOIN routes_extension re ON r.route_id = re.route_id
        WHERE ST_Intersects(r.geometry, ST_GeomFromText(:bbox))
        ORDER BY r.final_score DESC
        LIMIT :limit
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':bbox', $bboxPolygon);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $routes = $stmt->fetchAll();
    
    // Format output
    $result = array_map(function($route) {
        return [
            'route_id' => (int)$route['route_id'],
            'name' => $route['name'],
            'type' => $route['type'],
            'distance_km' => round((float)$route['distance_km'], 1),
            'ascent_m' => (int)$route['ascent_m'],
            'difficulty_score' => round((float)$route['difficulty_score'], 1),
            'final_score' => round((float)$route['final_score'], 1),
            'in_top_layer' => (bool)$route['in_top_layer'],
            'in_medal_layer' => (bool)$route['in_medal_layer'],
            'is_loop' => (bool)$route['is_loop'],
            'avg_rating' => round((float)$route['avg_rating'], 1),
            'rating_count' => (int)$route['rating_count']
        ];
    }, $routes);
    
    sendJSON(true, [
        'routes' => $result,
        'count' => count($result)
    ]);
    
} catch (Exception $e) {
    log_debug('Top in view error: ' . $e->getMessage(), 'error');
    sendError('Failed to fetch top routes: ' . $e->getMessage(), 500);
}