<?php

/* ============================================================================
  GET /api/routes/list.php - v3.1: Layer filtering + visibility control

  CHANGES v3.1:
  - Filtruje po route_layer_assignments (lista wybranych warstw)
  - Respektuje visibility (private tylko dla właściciela)
  - Używa VIEW user_route_completion dla completion data
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

    // User context dla completion check + visibility
    $userLocalId = $_GET['user_local_id'] ?? null;
    $userId = null;

    if ($userLocalId) {
        $user = getOrCreateUser($userLocalId);
        $userId = $user['user_id'];
    }

    // Parse bbox
    $bbox = parseBbox($_GET['bbox']);
    $bboxPolygon = bboxToPolygon($bbox);

    // Build WHERE clauses
    $where = ["ST_Intersects(r.geometry, ST_GeomFromText(:bbox))"];
    $params = [':bbox' => $bboxPolygon];

    /* ====================================================================
      ✅ LAYER FILTER - Primary method
      Jeśli podano layers, WYMUSZA filtrowanie po route_layer_assignments
      ==================================================================== */

    if (isset($_GET['layers'])) {
        $layerSlugs = explode(',', $_GET['layers']);
        $layerSlugs = array_filter(array_map('trim', $layerSlugs));

        if (!empty($layerSlugs)) {
            log_debug("Filtering by layers: " . implode(', ', $layerSlugs), 'info');

            // Get layer IDs from slugs
            $placeholders = [];
            foreach ($layerSlugs as $i => $slug) {
                $key = ":layer_slug$i";
                $placeholders[] = $key;
                $params[$key] = $slug;
            }

            $where[] = "EXISTS (
                SELECT 1 
                FROM route_layer_assignments rla
                JOIN map_layers ml ON rla.layer_id = ml.layer_id
                WHERE rla.route_id = r.route_id
                AND ml.slug IN (" . implode(',', $placeholders) . ")
                AND ml.active = 1
            )";
        }
    }

    /* ====================================================================
      ✅ VISIBILITY FILTER - Respects privacy
      - public: wszyscy widzą
      - private: tylko właściciel
      ==================================================================== */

    if ($userId) {
        // Zalogowany user: widzi public + swoje private
        $where[] = "(r.visibility = 'public' OR r.user_id = :visibility_user_id)";
        $params[':visibility_user_id'] = $userId;
    } else {
        // Niezalogowany: tylko public
        $where[] = "r.visibility = 'public'";
    }

    /* ====================================================================
      LEGACY FILTERS (opcjonalne, dla kompatybilności)
      ==================================================================== */

    // Source filter (deprecated, używaj layers zamiast)
    if (isset($_GET['sources']) && !isset($_GET['layers'])) {
        $sources = explode(',', $_GET['sources']);
        $sources = array_filter($sources);

        if (!empty($sources)) {
            $placeholders = [];
            foreach ($sources as $i => $src) {
                $key = ":source$i";
                $placeholders[] = $key;
                $params[$key] = trim($src);
            }
            $where[] = "r.source IN (" . implode(',', $placeholders) . ")";
            log_debug("⚠️ Using deprecated 'sources' filter, consider using 'layers'", 'warning');
        }
    }

    // Layer ID filter (legacy)
    if (isset($_GET['layer_id']) && !isset($_GET['layers'])) {
        $layerId = (int) $_GET['layer_id'];

        $where[] = "EXISTS (
            SELECT 1 FROM route_layer_assignments rla 
            WHERE rla.route_id = r.route_id 
            AND rla.layer_id = :layer_id
        )";
        $params[':layer_id'] = $layerId;
        log_debug("⚠️ Using deprecated 'layer_id' filter", 'warning');
    }

    // Difficulty filter
    if (isset($_GET['difficulty_min'])) {
        $diffMap = ['easy' => 1, 'medium' => 2, 'hard' => 3, 'extreme' => 4];
        $diffMin = $_GET['difficulty_min'];
        if (isset($diffMap[$diffMin])) {
            $where[] = "CASE 
                WHEN rm.difficulty_level = 'easy' THEN 1
                WHEN rm.difficulty_level = 'medium' THEN 2
                WHEN rm.difficulty_level = 'hard' THEN 3
                WHEN rm.difficulty_level = 'extreme' THEN 4
                ELSE 0
            END >= :diff_min";
            $params[':diff_min'] = $diffMap[$diffMin];
        }
    }

    // Distance filter
    if (isset($_GET['distance_min'])) {
        $where[] = "r.distance_km >= :dist_min";
        $params[':dist_min'] = safe_float($_GET['distance_min']);
    }

    if (isset($_GET['distance_max'])) {
        $where[] = "r.distance_km <= :dist_max";
        $params[':dist_max'] = safe_float($_GET['distance_max']);
    }

    // Ascent filter
    if (isset($_GET['ascent_min'])) {
        $where[] = "r.ascent_m >= :asc_min";
        $params[':asc_min'] = safe_int($_GET['ascent_min']);
    }

    if (isset($_GET['ascent_max'])) {
        $where[] = "r.ascent_m <= :asc_max";
        $params[':asc_max'] = safe_int($_GET['ascent_max']);
    }

    // Limit
    $limit = isset($_GET['limit']) ? min(safe_int($_GET['limit'], 100), 500) : 100;

    /* ====================================================================
      MAIN QUERY - z LEFT JOIN do user_route_completion VIEW
      ==================================================================== */

    $sql = "
    SELECT 
        r.route_id,
        r.name,
        r.source,
        r.route_purpose,
        r.visibility,
        r.user_id,
        rm.route_type as type,
        r.distance_km,
        r.ascent_m,
        r.descent_m,
        rm.difficulty_level,
        rm.rating,
        rm.rating_count,
        r.avg_edge_score,
        r.backbone_pct,
        r.segment_count,
        ST_AsGeoJSON(r.geometry) as geometry_json,
        EXISTS(SELECT 1 FROM challenge_routes WHERE route_id = r.route_id) as in_challenge,
        -- ✅ Lista warstw przypisanych do trasy
        (
            SELECT GROUP_CONCAT(ml.slug SEPARATOR ',')
            FROM route_layer_assignments rla
            JOIN map_layers ml ON rla.layer_id = ml.layer_id
            WHERE rla.route_id = r.route_id
            AND ml.active = 1
        ) as layer_slugs
        " . ($userId ? ",
        urc.completion_pct,
        urc.is_completed
        " : "") . "
    FROM routes r
    LEFT JOIN routes_meta rm ON r.route_id = rm.route_id
    " . ($userId ? "
    LEFT JOIN (
        SELECT 
            challenge_route_id,
            user_id,
            MAX(total_coverage_pct) as completion_pct,
            MAX(is_completed) as is_completed
        FROM activity_route_coverage
        GROUP BY challenge_route_id, user_id
    ) urc ON urc.challenge_route_id = r.route_id 
        AND urc.user_id = :user_id
    " : "") . "
    WHERE " . implode(" AND ", $where) . "
    ORDER BY r.avg_edge_score DESC, r.distance_km DESC
    LIMIT :limit
";
    $db->beginTransaction();
    $stmt = $db->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    if ($userId) {
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    $stmt->execute();
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $db->commit();
    log_debug("Loaded " . count($routes) . " routes in bbox" . ($userId ? " with completion data" : ""), 'info');

    /* ====================================================================
      BUILD GEOJSON FEATURECOLLECTION
      ==================================================================== */

    $features = [];

    foreach ($routes as $route) {
        $geometry = json_decode($route['geometry_json'], true);

        $properties = [
            'route_id' => (int) $route['route_id'],
            'name' => $route['name'],
            'source' => $route['source'],
            'route_purpose' => $route['route_purpose'],
            'visibility' => $route['visibility'],
            'type' => $route['type'],
            'distance_km' => round((float) $route['distance_km'], 2),
            'ascent_m' => (int) $route['ascent_m'],
            'descent_m' => (int) $route['descent_m'],
            'difficulty_level' => $route['difficulty_level'],
            'rating' => round((float) ($route['rating'] ?? 0), 1),
            'rating_count' => (int) ($route['rating_count'] ?? 0),
            'avg_edge_score' => round((float) $route['avg_edge_score'], 3),
            'backbone_pct' => round((float) $route['backbone_pct'], 1),
            'segment_count' => (int) $route['segment_count'],
            'in_challenge' => (bool) $route['in_challenge'],
            'is_completed' => false,
            'completion_pct' => 0,
            'layers' => $route['layer_slugs'] ? explode(',', $route['layer_slugs']) : []  // ✅ Array of layer slugs
        ];

        // Completion data from VIEW (jeśli user jest zalogowany)
        if ($userId && isset($route['completion_pct'])) {
            $properties['completion_pct'] = (float) $route['completion_pct'];
            $properties['is_completed'] = (bool) $route['is_completed'];
        }

        $features[] = [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => $properties
        ];
    }

    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
        'metadata' => [
            'count' => count($features),
            'limit' => $limit,
            'bbox' => $bbox,
            'user_authenticated' => $userId !== null,
            'filters_applied' => [
                'layers' => $_GET['layers'] ?? null,
                'sources' => $_GET['sources'] ?? null,
                'layer_id' => $_GET['layer_id'] ?? null
            ]
        ]
    ];

    sendJSON(true, $geojson);
} catch (Exception $e) {
    log_debug('Routes list error: ' . $e->getMessage(), 'error');
    log_debug('Stack trace: ' . $e->getTraceAsString(), 'error');
    sendError('Failed to fetch routes: ' . (DEBUG_MODE ? $e->getMessage() : 'Internal error'), 500);
}