<?php
/**
 * GET /api/routes/route-coverage.php?challenge_route_id=90&user_local_id=xxx
 * 
 * Shows all user activities that cover this challenge route
 * 
 * ENHANCED v3.0:
 * - Merged unique vectors from all activities
 * - Only matched vectors returned
 * - Activities sorted by date (oldest first)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../includes/coordinate_transform.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['challenge_route_id'])) {
    sendError('challenge_route_id is required');
}

$challengeRouteId = intval($_GET['challenge_route_id']);

// Get user from JWT or user_local_id
$localId = $_GET['user_local_id'] ?? null;
$user = getUserFromAuth($localId);
$userId = $user['user_id'];

log_debug("Fetching coverage for challenge $challengeRouteId, user $userId");

try {
    $db = getDB();
    
    // ✅ Initialize models
    $routeModel = new RouteModel($db);
    $activityModel = new ActivityModel($db);
    $challengeModel = new ChallengeModel($db);
    
    // ✅ Get challenge route
    $challenge = $challengeModel->getChallengeRouteById($challengeRouteId);
    if (!$challenge) {
        sendError('Challenge route not found', 404);
    }
      
    // ✅ Get activities for this challenge
    $activities = $activityModel->getActivitiesForChallenge($challengeRouteId, $userId);
    log_debug("Found " . count($activities) . " activities");
    
    if (empty($activities)) {
        sendJSON(true, [
            'challenge' => formatChallenge($challenge, $routeModel),
            'total_coverage_pct' => 0,
            'activities' => [],
            'activity_count' => 0,
            'merged_vectors' => [] // ✅ Empty merged vectors
        ]);
        exit;
    }
    
    // ✅ Sort activities by date (oldest first)
    usort($activities, function($a, $b) {
        $dateA = strtotime($a['activity_date'] ?? '0');
        $dateB = strtotime($b['activity_date'] ?? '0');
        return $dateA - $dateB;
    });
    
    // ✅ Collect all matched vectors from all activities
    $allMatchedVectors = [];
    
    foreach ($activities as $key => $activity) {
        $vectors = $activityModel->getMatchedVectors($activity['route_id'], $challengeRouteId);
        
        foreach ($vectors as $v) {
            $vectorKey = $v['vector_index']; // Use vector_index as unique key
            
            // Store only if not already stored (first activity wins)
            if (!isset($allMatchedVectors[$vectorKey])) {
                $allMatchedVectors[$vectorKey] = $v;
            }
        }
        
        // ✅ Add activity metadata
        $activities[$key]['distance_km'] = round((float)$activity['distance_km'], 2);
        $activities[$key]['activity_date'] = $activity['activity_date'] ?? 'Brak daty';
        $activities[$key]['total_coverage_pct'] = round((float)$activity['total_coverage_pct'], 1);
        unset($activities[$key]['geometry_wkt']);
    }
    
    // ✅ Sort merged vectors by index
    ksort($allMatchedVectors);
    
    // ✅ Format merged vectors
    $mergedVectors = formatVectors(array_values($allMatchedVectors), $activityModel);
    
    log_debug("Merged " . count($mergedVectors) . " unique matched vectors");
    
    // Calculate total coverage (best activity)
    $totalCoverage = !empty($activities) 
        ? max(array_column($activities, 'total_coverage_pct')) 
        : 0;
    
    sendJSON(true, [
        'challenge' => formatChallenge($challenge, $routeModel),
        'total_coverage_pct' => round($totalCoverage, 1),
        'activities' => $activities,
        'activity_count' => count($activities),
        'merged_vectors' => $mergedVectors // ✅ Unique matched vectors only
    ]);
    
} catch (Exception $e) {
    log_debug('Challenge coverage API error: ' . $e->getMessage(), 'error');
    log_debug('Stack trace: ' . $e->getTraceAsString(), 'error');
    sendError('Failed to fetch coverage: ' . $e->getMessage(), 500);
}

// ============================================================================
// Helper Functions
// ============================================================================

function formatChallenge(array $challenge, RouteModel $routeModel): array {
    return [
        'route_id' => (int)$challenge['route_id'],
        'challenge_id' => (int)($challenge['challenge_id'] ?? 0),
        'name' => $challenge['name'],
        'distance_km' => round((float)$challenge['distance_km'], 2)
    ];
}

function formatVectors(array $vectors, ActivityModel $activityModel): array {
    $formatted = [];
    
    foreach ($vectors as $idx => $v) {
        $startPoint = $activityModel->parsePoint($v['start_wkt']);
        $endPoint = $activityModel->parsePoint($v['end_wkt']);
        
        if (!$startPoint || !$endPoint) {
            log_debug("Invalid geometry for vector $idx", 'warning');
            continue;
        }
        
        // Convert EPSG:2180 to WGS84
        $startWGS = CoordinateTransform::epsg2180_to_wgs84($startPoint[0], $startPoint[1]);
        $endWGS = CoordinateTransform::epsg2180_to_wgs84($endPoint[0], $endPoint[1]);
        
        // Calculate match score (0-100) based on distance
        $distance = (float)$v['distance_to_route'];
        $matchScore = max(0, 100 - ($distance / 2)); // 0-50m distance = 100-75 score
        
        $formatted[] = [
            'index' => (int)$v['vector_index'],
            'start' => $startWGS,  // [lon, lat]
            'end' => $endWGS,      // [lon, lat]
            'distance' => round($distance, 1),
            'match_score' => round($matchScore, 1)
        ];
    }
    
    return $formatted;
}