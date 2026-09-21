<?php

/* ============================================================================
  PLIK: api/challenges/details.php
  GET /api/challenges/details.php?challenge_id=1&user_local_id=xxx

  Zwraca szczegółowe informacje o wyzwaniu
  v2.0 - REFACTORED with Models
  ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['challenge_id'])) {
    sendError('challenge_id parameter is required');
}

$challengeId = (int) $_GET['challenge_id'];
$userLocalId = $_GET['user_local_id'] ?? null;
$userId = null;

try {
    $db = getDB();

    // ✅ Initialize models
    $challengeModel = new ChallengeModel($db);

    // Get or create user
    $user = getOrCreateUser($userLocalId);
    $userId = $user['user_id'];

    // ========================================================================
    // 1. BASIC INFO - challenge data
    // ========================================================================
    $challenge = $challengeModel->getChallengeById($challengeId);

    if (!$challenge) {
        sendError('Challenge not found', 404);
    }

    // Format data
    $challenge['challenge_id'] = (int) $challenge['challenge_id'];
    $challenge['group_id'] = (int) $challenge['group_id'];
    $challenge['total_routes'] = (int) $challenge['total_routes'];
    $challenge['total_distance_km'] = (float) $challenge['total_distance_km'];
    $challenge['total_ascent_m'] = (int) $challenge['total_ascent_m'];
    $challenge['is_public'] = (bool) $challenge['is_public'];
    $challenge['image_url'] = $challenge['image_url'];

    log_debug($challenge);
    // ========================================================================
    // 2. USER PROGRESS
    // ========================================================================

    $completedRoutes = [];

    if ($userLocalId) {
        // ✅ Get progress using model
        $progress = $challengeModel->getUserChallengeProgressForChallangeId($userId, $challengeId);

        if ($progress) {
            $challenge['started'] = true;
            $challenge['completed_count'] = (int) $progress['completed_count'];
            $challenge['required_count'] = (int) $progress['required_count'];
            $challenge['total_count'] = (int) $progress['total_count'];
            $challenge['progress_pct'] = (float) $progress['progress_pct'];
            $challenge['current_level'] = $progress['current_level'];

            $totalDistanceKm = (float) $challenge['total_distance_km'];
            $progressPct = (float) $progress['progress_pct'];
            $challenge['completed_distance_km'] = round(($totalDistanceKm * $progressPct) / 100, 2);
            $challenge['completed_routes_count'] = count($completedRoutes);

            $completedRoutes = [];
            if (!empty($progress['completed_routes'])) {
                $decoded = json_decode($progress['completed_routes'], true);
                $completedRoutes = is_array($decoded) ? $decoded : [];
            }

            // ✅ Calculate completed status
            $minPct = (float) ($challenge['min_completion_pct'] ?? 100);

            // Get medal info
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM medal_levels WHERE challenge_id = ? AND active = 1");
            $stmt->execute([$challengeId]);
            $totalMedals = (int) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) as earned FROM user_medal_achievements WHERE user_id = ? AND challenge_id = ?");
            $stmt->execute([$userId, $challengeId]);
            $earnedMedals = (int) $stmt->fetchColumn();

            $challenge['completed'] = $challenge['progress_pct'] >= $minPct && ($totalMedals === 0 || $earnedMedals >= $totalMedals);
        } else {
            $challenge['started'] = false;
            $challenge['completed'] = false;
            $challenge['completed_count'] = 0;
            $challenge['required_count'] = 0;
            $challenge['progress_pct'] = 0;
            $challenge['current_level'] = 'none';
        }
    } else {
        $challenge['started'] = false;
        $challenge['completed'] = false;
        $challenge['completed_count'] = 0;
        $challenge['progress_pct'] = 0;
        $challenge['current_level'] = 'none';
    }

    // ========================================================================
    // 3. SOCIAL PROOF
    // ========================================================================
    // ✅ Use model method
    $stats = $challengeModel->getChallengeStats($challengeId);

    $challenge['participants_count'] = (int) ($stats['total_participants'] ?? 0);
    $completedCount = (int) ($stats['completed_users'] ?? 0);
    $challenge['completion_rate'] = $challenge['participants_count'] > 0 ? round(($completedCount / $challenge['participants_count']) * 100, 1) : 0;

    // Average rating (TODO: implement ratings system)
    $challenge['avg_rating'] = 0;

    // ========================================================================
// 4. MEDAL LEVELS
// ========================================================================
    $medalModel = new MedalAchievementsModel($db);
    $medals = $medalModel->getMedalsForChallengeDetails($challengeId, $userId);

    foreach ($medals as &$medal) {
        $medal['level_id'] = (int) $medal['level_id'];
        $medal['required_percent_min'] = (float) $medal['required_percent_min'];
        $medal['required_percent_max'] = (float) $medal['required_percent_max'];
        $medal['has_physical'] = (bool) $medal['has_physical'];
        $medal['physical_price_pln'] = $medal['physical_price_pln'] ? (float) $medal['physical_price_pln'] : null;
        $medal['earned'] = (bool) $medal['earned'];
        $medal['total_tiers'] = (int) $medal['total_tiers'];
        $medal['earned_tiers'] = (int) $medal['earned_tiers'];

        // ✅ DODAJ tier_label
        if ($medal['earned']) {
            $medal['tier_label'] = $medal['earned_tiers'] . '/' . $medal['total_tiers'];
            $medal['remaining_tiers'] = $medal['total_tiers'] - $medal['earned_tiers'];
        } else {
            $nextTier = $medal['earned_tiers'] + 1;
            $medal['tier_label'] = $nextTier . '/' . $medal['total_tiers'];
            $medal['remaining_tiers'] = $medal['total_tiers'] - $nextTier;
        }
    }

    $challenge['medals'] = $medals;


    // ========================================================================
    // 5. ROUTES PREVIEW + CALCULATE TOTAL TIME
    // ========================================================================
    $limit = $challenge['total_routes'] <= 20 ? $challenge['total_routes'] : 3;

    // ✅     Use model
    $routes = $challengeModel->getChallengeRoutes($challengeId);

    // ✅ Calculate total estimated time from ALL routes
    $totalSeconds = 0;

    // Slice for preview
    $routes = array_slice($routes, 0, $limit);

    foreach ($routes as &$route) {
        $route['route_id'] = (int) $route['route_id'];
        $route['distance_km'] = (float) $route['distance_km'];
        $route['ascent_m'] = (int) $route['ascent_m'];
        $route['required'] = (bool) $route['required'];
        $totalSeconds += (int) ($route['elapsed_time_sec'] ?? 0);

        // Check if completed by user
        $route['completed'] = in_array($route['route_id'], $completedRoutes);
    }
    
    $challenge['total_estimated_time_hours'] = $totalSeconds > 0 ? round($totalSeconds / 3600, 1) : null;
    $challenge['routes'] = $routes;
    $challenge['has_more_routes'] = $challenge['total_routes'] > $limit;

    // ========================================================================
    // RESPONSE - FLAT STRUCTURE
    // ========================================================================
    sendJSON(true, $challenge);
} catch (Exception $e) {
    log_debug('Challenge details error: ' . $e->getMessage(), 'error');
    sendError('Failed to load challenge details: ' . $e->getMessage(), 500);
}