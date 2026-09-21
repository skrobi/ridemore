<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

// ========================================================================
// AUTH
// ========================================================================
$user = requireAuth();
$userId = $user['user_id'];

// ========================================================================
// INPUT
// ========================================================================
$routeId = (int) ($_GET['route_id'] ?? 0);
if (!$routeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing route_id']);
    exit;
}

try {
    $db = getDB();
    $routeModel = new RouteModel($db);

    $route = $routeModel->getRouteById($routeId);

    if (!$route) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Route not found']);
        exit;
    }

    if ((int) $route['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    if ($route['source'] !== 'planner') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only planner routes can be edited']);
        exit;
    }

    // Parse
    $plannerConfig = !empty($route['planner_config'])
        ? json_decode($route['planner_config'], true)
        : null;

    $geometry = json_decode($route['geometry_json'], true); // {type, coordinates: [[lng,lat],...]}

    // ========================================================================
    // SPLIT GEOMETRY INTO SEGMENTS
    // ========================================================================
    if (
        $geometry &&
        !empty($geometry['coordinates']) &&
        $plannerConfig &&
        !empty($plannerConfig['waypoints']) &&
        count($plannerConfig['waypoints']) >= 2
    ) {
        $plannerConfig['segments'] = splitGeometryIntoSegments(
            $geometry['coordinates'],    // [[lng, lat], ...]
            $plannerConfig['waypoints']  // [{lat, lng, type, ...}, ...]
        );
    }

    // ========================================================================
    // RESPONSE
    // ========================================================================
    echo json_encode([
        'success' => true,
        'route' => [
            'route_id'        => (int) $route['route_id'],
            'name'            => $route['name'],
            'slug'            => $route['slug'],
            'distance_km'     => (float) $route['distance_km'],
            'ascent_m'        => (int) ($route['ascent_m'] ?? 0),
            'geometry'        => $geometry,
            'planner_config'  => $plannerConfig, // zawiera teraz 'segments'
            'description'     => $route['description'] ?? null,
            'tagline'         => $route['tagline'] ?? null,
            'route_type'      => $route['route_type'] ?? null,
            'difficulty_level'=> $route['difficulty_level'] ?? null,
            'visibility'      => $route['visibility'] ?? 'private',
            'can_edit'        => (int) $route['user_id'] === $userId
        ]
    ]);

} catch (Exception $e) {
    log_debug("❌ Planner load failed: " . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ========================================================================
// HELPERS
// ========================================================================

/**
 * Dzieli pełną geometrię na segmenty między waypointami.
 * Coords wejście: GeoJSON [lng, lat]
 * Coords wyjście: Leaflet [lat, lng]
 */
function splitGeometryIntoSegments(array $coords, array $waypoints): array
{
    if (count($waypoints) < 2 || count($coords) < 2) {
        return [];
    }

    // Znajdź indeks najbliższego punktu geometrii dla każdego waypointa
    $indices = [];
    foreach ($waypoints as $wp) {
        $indices[] = findClosestCoordIndex($coords, (float)$wp['lat'], (float)$wp['lng']);
    }

    // Upewnij się że indeksy są ściśle rosnące
    $indices = ensureAscending($indices, count($coords) - 1);

    // Buduj segmenty
    $segments = [];
    for ($i = 0; $i < count($indices) - 1; $i++) {
        $from = $indices[$i];
        $to   = $indices[$i + 1];

        $slice = array_slice($coords, $from, $to - $from + 1);

        if (count($slice) < 2) {
            // Fallback: prosta linia
            $slice = [$coords[$from], $coords[$to]];
        }

        // Zamień [lng, lat] → [lat, lng] dla Leaflet
        $leaflet = array_map(fn($c) => [$c[1], $c[0]], $slice);

        $segments[] = [
            'fromIdx'  => $i,
            'toIdx'    => $i + 1,
            'coords'   => $leaflet,
            'distance' => calculatePathDistance($slice), // metry, coords GeoJSON
            'duration' => null, // JS uzupełni z avg_speed jeśli potrzeba
        ];
    }

    return $segments;
}

function findClosestCoordIndex(array $coords, float $lat, float $lng): int
{
    $minDist = PHP_FLOAT_MAX;
    $closest = 0;

    foreach ($coords as $i => $c) {
        // GeoJSON: $c = [lng, lat]
        $dist = haversineDistance($lat, $lng, $c[1], $c[0]);
        if ($dist < $minDist) {
            $minDist = $dist;
            $closest = $i;
        }
    }

    return $closest;
}

function ensureAscending(array $indices, int $maxIdx): array
{
    for ($i = 1; $i < count($indices); $i++) {
        if ($indices[$i] <= $indices[$i - 1]) {
            $indices[$i] = min($indices[$i - 1] + 1, $maxIdx);
        }
    }
    return $indices;
}

function calculatePathDistance(array $coords): float
{
    $total = 0.0;
    for ($i = 0; $i < count($coords) - 1; $i++) {
        // GeoJSON: [lng, lat]
        $total += haversineDistance($coords[$i][1], $coords[$i][0], $coords[$i+1][1], $coords[$i+1][0]);
    }
    return round($total, 2);
}

function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R  = 6371000;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a  = sin($dp/2)**2 + cos($p1) * cos($p2) * sin($dl/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}