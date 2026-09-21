<?php

/**
 * GET /api/routes/challenges-verify.php?route_id=91&user_local_id=xxx
 * 
 * Returns challenge verification data for route details page
 * Supports both challenge routes and user activities
 * 
 * LOGIC:
 * - If route_purpose = 'reference' (not in challenge) → check if user activities match it
 * - If route in challenge → show challenge progress + this route's coverage
 * - If route_purpose = 'activity' → show which challenges it contributes to
 */
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['route_id'])) {
    sendError('route_id is required');
}

$routeId = intval($_GET['route_id']);
$userLocalId = $_GET['user_local_id'] ?? null;

try {
    $db = getDB();

    $routeModel = new RouteModel($db);
    $challengeModel = new ChallengeModel($db);
    $activityModel = new ActivityModel($db);
  
    // ✅ TYLKO TEN ZOSTAJE
    $user = getUserFromAuth($userLocalId);
    $userId = $user['user_id'];

    // Load route
    $route = $routeModel->getRouteById($routeId);
    if (!$route) {
        sendError('Route not found', 404);
    }

    $routePurpose = $route['route_purpose'];
    $inChallenge = $challengeModel->isRouteInChallenge($routeId);

    // ========================================================================
    // SCENARIO 1: REFERENCE ROUTE (not in challenge)
    // ========================================================================
    log_debug('4');
    if ($routePurpose === 'reference') {
        if (!$inChallenge) {
            // Standalone reference - check if user has matching activities
            if (!$userId) {
                sendJSON(true, [
                    'type' => 'reference_no_challenge',
                    'message' => 'Zaloguj się, aby sprawdzić swoje pokrycie tej trasy'
                ]);
                exit;
            }

            $result = handleReferenceRoute($routeId, $userId, $routeModel, $activityModel);
            sendJSON(true, $result);
            exit;
        }
    }

    // ========================================================================
    // SCENARIO 2: CHALLENGE ROUTE
    // ========================================================================
    log_debug('5');
    if ($routePurpose === 'reference' || $routePurpose === 'user_shared') {
        $challenges = $challengeModel->getChallengesForRoute($routeId);

        if (empty($challenges)) {
            sendJSON(true, [
                'type' => 'no_challenges',
                'message' => 'Ta trasa nie należy do żadnego wyzwania'
            ]);
            exit;
        }

        // User not logged in
        if (!$userId) {
            sendJSON(true, [
                'type' => 'challenge_guest',
                'challenges' => $challenges,
                'message' => 'Zaloguj się, aby śledzić postęp w wyzwaniach'
            ]);
            exit;
        }

        $result = handleChallengeRoute($routeId, $userId, $challenges, $challengeModel, $activityModel);
        sendJSON(true, $result);
        exit;
    }

    // ========================================================================
    // SCENARIO 3: USER ACTIVITY
    // ========================================================================
    log_debug('6');
    if ($routePurpose === 'activity') {
        if (!$userId || (int) $route['user_id'] !== $userId) {
            sendJSON(true, [
                'type' => 'activity_not_owner',
                'message' => 'To nie Twoja aktywność'
            ]);
            exit;
        }


        $result = handleUserActivity($routeId, $userId, $challengeModel, $activityModel, $routeModel);  // ✅ DODAJ $routeModel
        sendJSON(true, $result);
        exit;
    }

    // ========================================================================
    // FALLBACK
    // ========================================================================

    sendJSON(true, [
        'type' => 'unknown',
        'message' => 'Nie można określić typu trasy'
    ]);
} catch (Exception $e) {
    log_debug('Challenge verify error: ' . $e->getMessage(), 'error');
    sendError('Failed to verify challenges: ' . $e->getMessage(), 500);
}

// ============================================================================
// HANDLERS
// ============================================================================

/**
 * Handle standalone reference route
 */
function handleReferenceRoute(int $routeId, int $userId, RouteModel $routeModel, ActivityModel $activityModel): array {
    // Check if user has activities in route's bbox
    $bbox = $routeModel->getRouteBbox($routeId);
    $userActivities = $activityModel->getActivitiesInBbox($userId, $bbox);

    if (empty($userActivities)) {
        return [
            'type' => 'reference_no_activities',
            'message' => 'Nie masz jeszcze aktywności w tym obszarze',
            'can_match' => false
        ];
    }

    // Check which activities have been matched
    $matchedActivities = [];
    $unmatchedActivities = [];
    log_debug('handle 1');
    foreach ($userActivities as $activity) {
        $hasVectors = $activityModel->hasMatchingVectors($activity['route_id'], $routeId);

        if ($hasVectors) {
            $coverage = $activityModel->getRouteCoverage($activity['route_id'], $routeId);
            $matchedActivities[] = array_merge($activity, $coverage);
        } else {
            $unmatchedActivities[] = $activity;
        }
    }
    log_debug('handle 2');
    $challengeModel = new ChallengeModel($activityModel->getDb());
    $totalCoverage = $challengeModel->calculateUniqueCoverage($routeId, $userId);

    return [
        'type' => 'reference_with_activities',
        'route_distance_km' => $routeModel->getRouteDistance($routeId),
        'total_coverage_pct' => $totalCoverage['coverage_pct'],
        'total_covered_km' => $totalCoverage['covered_km'],
        'matched_activities' => $matchedActivities,
        'unmatched_activities' => $unmatchedActivities,
        'can_match' => !empty($unmatchedActivities)
    ];
}

/**
 * Handle challenge route
 */
function handleChallengeRoute(
        int $routeId,
        int $userId,
        array $challenges,
        ChallengeModel $challengeModel,
        ActivityModel $activityModel
): array {
    // Take first challenge (route can be in multiple challenges)
    $challengeId = $challenges[0]['challenge_id'];
    $challenge = $challengeModel->getChallengeById($challengeId);
    log_debug('handle 3');
    // Check if user is enrolled
    $userProgress = $challengeModel->getUserChallengeProgressForChallangeId($userId, $challengeId);

    if (!$userProgress) {
        return [
            'type' => 'challenge_not_enrolled',
            'challenge' => $challenge,
            'message' => 'Nie jesteś zapisany do tego wyzwania'
        ];
    }
    log_debug('handle 4');
    // Get this route's coverage
    $routeCoverage = getRouteCoverageData($routeId, $userId, $activityModel, $challengeModel);
    log_debug('handle 5');
    // Get challenge routes
    $challengeRoutes = $challengeModel->getChallengeRoutes($challengeId);

    return [
        'type' => 'challenge_enrolled',
        'challenge' => $challenge,
        'user_progress' => [
            'progress_pct' => (float) $userProgress['progress_pct'],
            'completed_routes' => json_decode($userProgress['completed_routes'] ?? '[]', true),
            'total_routes' => count($challengeRoutes)
        ],
        'this_route' => $routeCoverage,
        'challenge_routes' => $challengeRoutes
    ];
}

/**
 * Handle user activity
 */

/**
 * Handle user activity
 */
function handleUserActivity(
        int $activityRouteId,
        int $userId,
        ChallengeModel $challengeModel,
        ActivityModel $activityModel,
        RouteModel $routeModel
): array {
    // ✅ STEP 1: Find matched challenges
    $contributions = $activityModel->getActivityChallengeContributions($activityRouteId);
    log_debug('handle 7');
    // Enrich with enrollment status
    foreach ($contributions as &$contrib) {
        $userProgress = $challengeModel->getUserChallengeProgressForChallangeId(
                $userId,
                $contrib['challenge_id']
        );

        $contrib['is_enrolled'] = $userProgress !== null;
        $contrib['user_progress_pct'] = $userProgress ? (float) $userProgress['progress_pct'] : 0;
    }
    unset($contrib);
    log_debug('handle 8');
    // ✅ STEP 2: Find potential challenges WITH enrollment status
    $potentialChallenges = $challengeModel->getPotentialChallengesForActivity($activityRouteId, $userId);
    log_debug('handle 9');
    // Remove already matched challenges from potential
    $matchedChallengeIds = array_column($contributions, 'challenge_id');
    log_debug('handle 10');
    $potentialChallenges = array_filter($potentialChallenges, function ($challenge) use ($matchedChallengeIds) {
        return !in_array($challenge['challenge_id'], $matchedChallengeIds);
    });

    // ✅ Convert is_enrolled to boolean in potential challenges
    foreach ($potentialChallenges as &$challenge) {
        $challenge['is_enrolled'] = (bool) ($challenge['is_enrolled'] ?? false);
        $challenge['user_progress_pct'] = (float) ($challenge['user_progress_pct'] ?? 0);
    }
    unset($challenge);

    // ✅ STEP 3: Build response
    if (empty($contributions) && empty($potentialChallenges)) {
        return [
            'type' => 'activity_no_challenges',
            'message' => 'Ta aktywność nie przecina się z żadnym wyzwaniem',
            'matched_challenges' => [],
            'potential_challenges' => []
        ];
    }

    return [
        'type' => 'activity_with_challenges',
        'matched_challenges' => $contributions,
        'potential_challenges' => array_values($potentialChallenges),
        'message' => sprintf(
                'Ta aktywność: %d zmatchowanych, %d potencjalnych wyzwań',
                count($contributions),
                count($potentialChallenges)
        )
    ];
}

/**
 * Get route coverage data with activities list
 */
function getRouteCoverageData(int $routeId, int $userId, ActivityModel $activityModel, ChallengeModel $challengeModel): array {
    $activities = $activityModel->getActivitiesForRoute($routeId, $userId);

    if (empty($activities)) {
        return [
            'covered_km' => 0,
            'total_km' => $activityModel->getRouteDistance($routeId),
            'coverage_pct' => 0,
            'activities' => [],
            'is_completed' => false
        ];
    }

    $totalCoverage = $challengeModel->calculateUniqueCoverage($routeId, $userId);

    return [
        'covered_km' => $totalCoverage['covered_km'],
        'total_km' => $totalCoverage['total_km'],
        'coverage_pct' => $totalCoverage['coverage_pct'],
        'activities' => $activities,
        'is_completed' => $totalCoverage['coverage_pct'] >= 95.0
    ];
}
