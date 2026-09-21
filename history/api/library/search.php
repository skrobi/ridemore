<?php
/* ============================================================================
   LIBRARY SEARCH - Advanced route discovery
   
   Wyszukiwarka tras z zaawansowanymi filtrami i regionami
   
   METHOD: GET
   
   PARAMS:
   - query: string (full-text search in name/description)
   - distance_min: float (km)
   - distance_max: float (km)
   - ascent_min: int (m)
   - ascent_max: int (m)
   - difficulty: string (easy|medium|hard|extreme)
   - route_type: string (road|gravel|mtb|mixed)
   - min_rating: float (0-5)
   - region: string (slug regionu - patrz REGIONS)
   - source: string (admin_upload|user_published)
   - sort: string (rating|distance|difficulty|newest) - default: rating
   - limit: int (default 20, max 100)
   - offset: int (default 0)
   - user_local_id: string (dla is_completed check)
   
   REGIONS:
   - malopolska, podkarpackie, mazowieckie, wielkopolska, etc.
   - Custom bbox dla każdego regionu
   
   RESPONSE:
   {
     "success": true,
     "data": {
       "routes": [...],
       "total": 150,
       "limit": 20,
       "offset": 0,
       "filters": {...},
       "available_regions": [...]
     }
   }
   ============================================================================ */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    $db = getDB();
    
    // ✅ START TRANSACTION
    $db->beginTransaction();
    
    // USER CONTEXT
    $userLocalId = $_GET['user_local_id'] ?? null;
    $userId = null;
    
    if ($userLocalId) {
        $user = getOrCreateUser($userLocalId);
        $userId = $user['user_id'];
    }
    
    // EXTRACT FILTERS
    $query = $_GET['query'] ?? '';
    $distanceMin = isset($_GET['distance_min']) ? (float)$_GET['distance_min'] : null;
    $distanceMax = isset($_GET['distance_max']) ? (float)$_GET['distance_max'] : null;
    $ascentMin = isset($_GET['ascent_min']) ? (int)$_GET['ascent_min'] : null;
    $ascentMax = isset($_GET['ascent_max']) ? (int)$_GET['ascent_max'] : null;
    $difficulty = $_GET['difficulty'] ?? null;
    $routeType = $_GET['route_type'] ?? null;
    $minRating = isset($_GET['min_rating']) ? (float)$_GET['min_rating'] : null;
    $source = $_GET['source'] ?? null;
    $sort = $_GET['sort'] ?? 'rating';
    
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // BUILD QUERY
    $where = [
        "r.visibility IN ('public')",
        "r.route_purpose IN ('reference', 'activity', 'user_shared')",
        "r.status = 'active'"
    ];
    $params = [];
    
    // ✅ BBOX filter
    if (isset($_GET['bbox'])) {
        $bboxParam = $_GET['bbox'];
        $bboxParts = explode(',', $bboxParam);
        
        if (count($bboxParts) === 4) {
            $bbox = array_map('floatval', $bboxParts);
            
            // ✅ Konwersja bbox na POLYGON WKT
            $west = $bbox[0];
            $south = $bbox[1];
            $east = $bbox[2];
            $north = $bbox[3];
            
            $bboxPolygon = "POLYGON(($west $south, $east $south, $east $north, $west $north, $west $south))";
            
            $where[] = "ST_Intersects(r.geometry, ST_GeomFromText(:bbox, 4326))";
            $params[':bbox'] = $bboxPolygon;
            
            log_debug("BBOX filter: " . json_encode($bbox) . " -> " . $bboxPolygon, 'info');
        }
    }
    
    
    // Full-text search
    if (!empty($query)) {
        $where[] = "(r.name LIKE :query OR rm.description LIKE :query)";
        $searchTerm = '%' . $query . '%';
        $params[':query'] = $searchTerm;
    }
    
    if ($distanceMin !== null) {
        $where[] = "r.distance_km >= :dist_min";
        $params[':dist_min'] = $distanceMin;
    }
    if ($distanceMax !== null) {
        $where[] = "r.distance_km <= :dist_max";
        $params[':dist_max'] = $distanceMax;
    }
    
    if ($ascentMin !== null) {
        $where[] = "r.ascent_m >= :asc_min";
        $params[':asc_min'] = $ascentMin;
    }
    if ($ascentMax !== null) {
        $where[] = "r.ascent_m <= :asc_max";
        $params[':asc_max'] = $ascentMax;
    }
    
    if ($difficulty) {
        $where[] = "rm.difficulty_level = :difficulty";
        $params[':difficulty'] = $difficulty;
    }
    
    if ($routeType) {
        $where[] = "rm.route_type = :route_type";
        $params[':route_type'] = $routeType;
    }
    
    if ($minRating !== null) {
        $where[] = "rm.rating >= :min_rating";
        $params[':min_rating'] = $minRating;
    }
    
    if ($source) {
        $where[] = "r.source = :source";
        $params[':source'] = $source;
    }
    
    // SORTING
    $orderBy = match($sort) {
        'distance' => "r.distance_km ASC",
        'difficulty' => "CASE 
            WHEN rm.difficulty_level = 'easy' THEN 1
            WHEN rm.difficulty_level = 'medium' THEN 2
            WHEN rm.difficulty_level = 'hard' THEN 3
            WHEN rm.difficulty_level = 'extreme' THEN 4
            ELSE 0
        END DESC",
        'newest' => "r.created_at DESC",
        default => "rm.rating DESC, rm.rating_count DESC"
    };
    
    // COUNT TOTAL
    $countSql = "
        SELECT COUNT(*) 
        FROM routes r
        LEFT JOIN routes_meta rm ON r.route_id = rm.route_id
        WHERE " . implode(" AND ", $where);
    
    $countStmt = $db->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
    
    // GET RESULTS
    $sql = "
        SELECT 
            r.*,
            rm.difficulty_level,
            rm.elapsed_time_sec,
            rm.tagline,
            rm.route_type,
            rm.description,
            rm.rating,
            rm.rating_count,
            rm.cover_image_url,
            EXISTS(SELECT 1 FROM challenge_routes WHERE route_id = r.route_id) as in_challenge,
            r.created_at
        FROM routes r
        LEFT JOIN routes_meta rm ON r.route_id = rm.route_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ✅ COMMIT
    $db->commit();
    
    // PROCESS RESULTS
    $result = [];
    foreach ($routes as $route) {
        $routeData = [
            'route_id' => (int)$route['route_id'],
            'name' => $route['name'],
            'slug' => $route['slug'],
            'source' => $route['source'],
            'route_purpose' => $route['route_purpose'],
            'elapsed_time_sec' => $route['elapsed_time_sec'],
            'tagline' => $route['tagline'],
            'visibility' => $route['visibility'],
            'distance_km' => round((float)$route['distance_km'], 2),
            'ascent_m' => (int)$route['ascent_m'],
            'descent_m' => (int)$route['descent_m'],
            'difficulty_level' => $route['difficulty_level'],
            'route_type' => $route['route_type'],
            'description' => $route['description'] ? substr($route['description'], 0, 200) : null,
            'rating' => round((float)($route['rating'] ?? 0), 1),
            'rating_count' => (int)($route['rating_count'] ?? 0),
            'cover_image_url' => $route['cover_image_url'],
            'in_challenge' => (bool)$route['in_challenge'],
            'is_completed' => false,
            'completion_pct' => 0,
            'created_at' => $route['created_at']
        ];
        $result[] = $routeData;
    }
    
    // RESPONSE
    sendJSON(true, [
        'routes' => $result,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'filters' => [
            'query' => $query,
            'bbox' => $bboxParam ?? null,
            'distance_min' => $distanceMin,
            'distance_max' => $distanceMax,
            'ascent_min' => $ascentMin,
            'ascent_max' => $ascentMax,
            'difficulty' => $difficulty,
            'route_type' => $routeType,
            'min_rating' => $minRating,
            'source' => $source,
            'sort' => $sort
        ]
    ]);
    
} catch (Exception $e) {
    // ✅ ROLLBACK on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    log_debug('Library search error: ' . $e->getMessage(), 'error');
    sendError('Search failed: ' . (DEBUG_MODE ? $e->getMessage() : 'Internal error'), 500);
}