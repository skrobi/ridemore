<?php

/* ============================================================================
  ROUTE DETAILS API - v6.0 FULLY REFACTORED with Models
  GET /api/routes/details.php?id=123&render=1&user_local_id=xxx

  ZERO SQL - All queries through models
  NO BaseRouteAction - everything in models
  ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (isset($_GET['id'])) {
    $routeId = validateRouteId($_GET['id']);
} elseif (isset($_GET['slug']) && !empty($_GET['slug'])) {
    $routeId = null; // resolved below after model init
} else {
    sendError('route_id or slug is required');
}



$userLocalId = $_GET['user_local_id'] ?? null;

try {
    $db = getDB();

    // ✅ Initialize models
    $routeModel = new RouteModel($db);
    $layerModel = new LayerModel($db);
    $challengeModel = new ChallengeModel($db);

    // Optional auth
    $userData = optionalAuth();

    /* ====================================================================
      LOAD ROUTE
      ==================================================================== */

    if ($routeId) {
        $route = $routeModel->getRouteById($routeId);
    } else {
        // Resolve slug → route
        $slug = preg_replace('/[^a-z0-9\-]/i', '', $_GET['slug']);
        $route = $routeModel->getRouteBySlug($slug);
    }

    if (!$route) {
        sendError('Route not found', 404);
    }

// Zrobiony – reszta details.php działa bez zmian
// $routeId jest już dostępne z $route['route_id']
    $routeId = (int) $route['route_id'];

    if (!$route) {
        sendError('Route not found', 404);
    }

    /* ====================================================================
      INCREMENT VIEWS (non-blocking)
      ==================================================================== */

    $routeModel->incrementViews($routeId);

    /* ====================================================================
      TYPE CASTING
      ==================================================================== */

    $route['route_id'] = (int) $route['route_id'];
    $route['user_id'] = (int) $route['user_id'];
    $route['distance_km'] = round((float) $route['distance_km'], 2);
    $route['ascent_m'] = (int) $route['ascent_m'];
    $route['descent_m'] = (int) $route['descent_m'];
    $route['segment_count'] = (int) $route['segment_count'];
    $route['avg_edge_score'] = round((float) ($route['avg_edge_score'] ?? 0), 3);
    $route['backbone_pct'] = round((float) ($route['backbone_pct'] ?? 0), 1);
    $route['rating'] = round((float) ($route['rating'] ?? 0), 1);
    $route['rating_count'] = (int) ($route['rating_count'] ?? 0);
    $route['views_count'] = (int) ($route['views_count'] ?? 0);

    // Activity metadata
    $route['moving_time_min'] = $route['moving_time_min'] ? (int) $route['moving_time_min'] : null;
    $route['avg_speed_kmh'] = $route['avg_speed_kmh'] ? round((float) $route['avg_speed_kmh'], 2) : null;
    $route['max_speed_kmh'] = $route['max_speed_kmh'] ? round((float) $route['max_speed_kmh'], 2) : null;
    $route['avg_heart_rate'] = $route['avg_heart_rate'] ? (int) $route['avg_heart_rate'] : null;
    $route['max_heart_rate'] = $route['max_heart_rate'] ? (int) $route['max_heart_rate'] : null;
    $route['avg_cadence'] = $route['avg_cadence'] ? (int) $route['avg_cadence'] : null;
    $route['avg_power'] = $route['avg_power'] ? (int) $route['avg_power'] : null;
    $route['avg_temperature'] = $route['avg_temperature'] ? round((float) $route['avg_temperature'], 1) : null;
    $route['elapsed_time_sec'] = $route['elapsed_time_sec'] ? (int) $route['elapsed_time_sec'] : null;

    // Parse geometry
    $route['geometry'] = json_decode($route['geometry_json'], true);
    unset($route['geometry_json']);

    /* ====================================================================
      LOAD RATINGS
      ==================================================================== */

    $route['recent_ratings'] = $routeModel->getRouteRatings($routeId, 5);

    log_debug("Loaded " . count($route['recent_ratings']) . " ratings for route $routeId", 'info');

    /* ====================================================================
      CHECK COMPLETION - using user_challenge_progress
      ==================================================================== */

    $route['is_completed'] = false;
    $route['completion_pct'] = 0;
    $route['in_challenge'] = false;
    $route['challenge_id'] = null;

    if ($userLocalId) {
        $user = getOrCreateUser($userLocalId);

        // ✅ Check if user has progress in challenge containing this route
        $progress = $challengeModel->getUserChallengeProgressForRoute($user['user_id'], $routeId);

        if ($progress) {
            $route['in_challenge'] = true;
            $route['challenge_id'] = (int) $progress['challenge_id'];
            $route['completion_pct'] = (float) $progress['progress_pct'];
            $route['is_completed'] = (bool) $progress['is_completed'];

            log_debug("Route $routeId (challenge {$route['challenge_id']}): {$route['completion_pct']}% completed", 'info');
        } else {
            log_debug("Route $routeId: not in any active challenge", 'info');

            // ✅ Check if route is in ANY challenge (even if user not enrolled)
            $route['in_challenge'] = $challengeModel->isRouteInChallenge($routeId);
        }
    } else {
        // ✅ No user - just check if route is in challenge
        $route['in_challenge'] = $challengeModel->isRouteInChallenge($routeId);
    }

    /* ====================================================================
      CHECK IF USER CAN EDIT/DELETE
      ==================================================================== */

    $route['can_edit'] = false;
    $route['can_delete'] = false;

    if ($userData && isset($userData['user_id'])) {
        $isOwner = ((int) $route['user_id'] === (int) $userData['user_id']);
        $isAdmin = (($userData['status'] ?? '') === 'admin');

        $route['can_edit'] = $isOwner || $isAdmin;

        // Can delete if owner/admin AND not reference AND not in challenge
        $route['can_delete'] = ($isOwner || $isAdmin) && ($route['route_purpose'] !== 'reference') && !$route['in_challenge'];
    }

    /* ====================================================================
      LOAD RELATED DATA - using models
      ==================================================================== */

    // ✅ Get assigned layers using LayerModel
    $route['layers'] = $layerModel->getRouteLayers($routeId);

    // ✅ Get challenges using ChallengeModel
    if ($route['in_challenge']) {
        $route['challenges'] = $challengeModel->getChallengesForRoute($routeId);
    } else {
        $route['challenges'] = [];
    }

    $photoModel = new PhotoModel($db);
    $route['photos'] = $photoModel->getEntityPhotos('route', $routeId);

    // Format photo URLs
    foreach ($route['photos'] as &$photo) {
        $photo['url'] = url($photo['file_path']);
        $photo['has_gps'] = !empty($photo['lat']) && !empty($photo['lon']);
    }

    /* ====================================================================
      SEGMENTS - REMOVED (will be handled separately in future)
      ==================================================================== */

    $route['segments_geojson'] = null;

    /* ====================================================================
      RENDER HTML (optional)
      ==================================================================== */

    if (isset($_GET['render']) && $_GET['render'] == 1) {
        $user = [
            'user_id' => $userData['user_id'] ?? null,
            'user_local_id' => $userData['user_local_id'] ?? null,
            'status' => $userData['status'] ?? 'guest'
        ];

        ob_start();
        include __DIR__ . '/../../templates/routes/details.php';
        $route['html'] = ob_get_clean();
    }

    /* ====================================================================
      RESPONSE
      ==================================================================== */

    sendJSON(true, $route);
} catch (Exception $e) {
    log_debug('Route details error: ' . $e->getMessage(), 'error');
    log_debug('Stack trace: ' . $e->getTraceAsString(), 'error');
    sendError('Failed to fetch route details: ' . $e->getMessage(), 500);
}