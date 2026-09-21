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
// PREPARE DATA
// ========================================================================
try {
    $db = getDB();
    $routeModel = new RouteModel($db);

    // Generate slug
    $slug = generateSlug($input['name'], $routeModel);

    // Routes table data
    $routeData = [
        'user_id' => $userId,
        'name' => trim($input['name']),
        'slug' => $slug,
        'source' => 'planner',
        'route_purpose' => 'planned',
        'visibility' => 'private',
        'geometry' => $input['geometry'],
        'distance_km' => round((float) $input['distance_km'], 2),
        'ascent_m' => isset($input['ascent_m']) ? (int) $input['ascent_m'] : null,
        'descent_m' => isset($input['descent_m']) ? (int) $input['descent_m'] : null,
        'activity_date' => null
    ];

    // ========================================================================
    // INSERT ROUTE
    // ========================================================================
    $routeId = $routeModel->insertRoute($routeData);

    if (!$routeId) {
        throw new Exception('Failed to create route');
    }

    log_debug("✅ Created planner route ID: $routeId", 'info');


    // ========================================================================
// INSERT ROUTES_META
// ========================================================================
    $metaData = [
        'external_source' => 'planner',
        'route_type' => $input['route_type'] ?? null,
        'estimated_time_hours' => isset($input['estimated_time_hours']) ? round((float) $input['estimated_time_hours'], 1) : null,
        'planner_config' => json_encode([
            'challenge_id' => $input['challenge_id'] ?? null,
            'bike_type' => $input['bike_type'] ?? 'road',
            'avg_speed' => $input['avg_speed'] ?? 25,
            'waypoints' => $input['waypoints'] ?? [],
            'route_reversed' => $input['route_reversed'] ?? false,
            'created_with' => 'planner_v1',
            'created_at' => date('Y-m-d H:i:s')
                ], JSON_UNESCAPED_UNICODE)
    ];

// ✅ DODAJ DEBUG
    log_debug("📦 Meta data to save: " . json_encode($metaData), 'info');
    log_debug("📦 planner_config length: " . strlen($metaData['planner_config']), 'info');

    $result = $routeModel->updateRouteMeta($routeId, $metaData);

    log_debug("📦 updateRouteMeta result: " . ($result ? 'true' : 'false'), 'info');

    if (!$result) {
        log_debug("❌ updateRouteMeta failed!", 'error');
    }

    // ========================================================================
    // RESPONSE
    // ========================================================================
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'route_id' => $routeId,
        'slug' => $slug,
        'url' => get_base_url() . "/routes/$slug",
        'message' => 'Route saved successfully'
    ]);
} catch (Exception $e) {
    log_debug("❌ Planner save failed: " . $e->getMessage(), 'error');

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// ========================================================================
// HELPER: Generate slug
// ========================================================================
function generateSlug(string $name, RouteModel $routeModel): string {
    $slug = strtolower(trim($name));
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 200);

    // Ensure uniqueness
    $baseSlug = $slug;
    $counter = 1;

    while ($routeModel->slugExists($slug)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    return $slug;
}
