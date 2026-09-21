<?php

/**
 * POST /api/routes/match-activities.php
 * 
 * Match user's activities to a reference/challenge route
 * ✅ v2.0 - Saves coverage even for non-challenge routes
 */
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['reference_route_id'])) {
    sendError('reference_route_id is required');
}

$referenceRouteId = intval($input['reference_route_id']);
$userLocalId = $input['user_local_id'] ?? null;

try {
    $db = getDB();

    $routeModel = new RouteModel($db);
    $activityModel = new ActivityModel($db);
    $challengeModel = new ChallengeModel($db);

    $user = getUserFromAuth($userLocalId);
    $userId = $user['user_id'];

    log_debug("Match request: reference=$referenceRouteId, user=$userId", 'info');

    $referenceRoute = $routeModel->getRouteById($referenceRouteId);
    if (!$referenceRoute) {
        sendError('Reference route not found', 404);
    }

    if (!in_array($referenceRoute['route_purpose'], ['reference', 'user_shared'])) {
        sendError('Route is not a reference or challenge route', 400);
    }

    $bbox = $routeModel->getRouteBbox($referenceRouteId);
    if (!$bbox) {
        sendError('Cannot get route bounding box', 500);
    }

    $userActivities = $activityModel->getActivitiesInBbox($userId, $bbox);

    if (empty($userActivities)) {
        sendJSON(true, [
            'activities_found' => 0,
            'activities_matched' => 0,
            'total_coverage_pct' => 0,
            'message' => 'Nie znaleziono aktywności w okolicy tej trasy'
        ]);
        exit;
    }

    log_debug("Found " . count($userActivities) . " activities in bbox", 'info');

    require_once __DIR__ . '/../../includes/classes/SegmentMatcher.php';
    require_once __DIR__ . '/../../includes/classes/RouteSegmenter.php';

    $matcher = new SegmentMatcher($db);

    $matches = [];
    $matchedCount = 0;
    $totalTime = 0;

    foreach ($userActivities as $activity) {
        $activityRouteId = $activity['route_id'];

        // ✅ Check if already matched
        if ($activityModel->hasMatchingVectors($activityRouteId, $referenceRouteId)) {
            log_debug("Activity $activityRouteId already matched, skipping", 'info');

            $coverage = $activityModel->getRouteCoverage($activityRouteId, $referenceRouteId);
            $matches[] = [
                'activity_id' => $activityRouteId,
                'activity_name' => $activity['name'],
                'activity_date' => $activity['activity_date'],
                'status' => 'already_matched',
                'coverage_pct' => $coverage['coverage_pct']
            ];
            $matchedCount++;
            continue;
        }

        log_debug("Matching activity $activityRouteId vs reference $referenceRouteId", 'info');

        $startTime = microtime(true);

        try {
            $result = $matcher->matchRoutes($activityRouteId, $referenceRouteId);

            $elapsed = microtime(true) - $startTime;
            $totalTime += $elapsed;

            if ($result['success'] && $result['has_matches']) {
                // ✅ Get challenge info (może być NULL)
                $challengeId = null;
                $minCompletionPct = 100.0;

                $challenges = $challengeModel->getChallengesForRoute($referenceRouteId);
                if (!empty($challenges)) {
                    $challengeId = $challenges[0]['challenge_id'];
                    $challenge = $challengeModel->getChallengeById($challengeId);
                    $minCompletionPct = $challenge['min_completion_pct'] ?? 100.0;
                }

                $isCompleted = ($result['total_coverage_pct'] >= $minCompletionPct);
                $context = ($referenceRoute['distance_km'] < 20) ? 'urban' : 'rural';

                // ✅ ZAPISZ ZAWSZE (nawet jeśli challengeId = NULL)
                $activityModel->saveActivityCoverage(
                    $activityRouteId,
                    $referenceRouteId,
                    $userId,
                    $challengeId,  // ← może być NULL dla standalone reference routes
                    $result['matched_count'],
                    $result['total_coverage_pct'],
                    $result['avg_match_score'],
                    $result['max_hausdorff'],
                    $isCompleted,
                    $context,
                    SegmentMatcher::ALGORITHM_VERSION,
                    $activity['activity_date'],
                    $result['coverage_ranges'] ?? null
                );

                $matches[] = [
                    'activity_id' => $activityRouteId,
                    'activity_name' => $activity['name'],
                    'activity_date' => $activity['activity_date'],
                    'status' => 'matched',
                    'coverage_pct' => $result['total_coverage_pct'],
                    'matched_count' => $result['matched_count'],
                    'processing_time' => round($elapsed, 2),
                    'in_challenge' => $challengeId !== null
                ];
                $matchedCount++;
                
            } else {
                $matches[] = [
                    'activity_id' => $activityRouteId,
                    'activity_name' => $activity['name'],
                    'activity_date' => $activity['activity_date'],
                    'status' => 'no_match',
                    'coverage_pct' => 0,
                    'processing_time' => round($elapsed, 2)
                ];
            }
        } catch (Exception $e) {
            log_debug("Matching error for activity $activityRouteId: " . $e->getMessage(), 'error');

            $matches[] = [
                'activity_id' => $activityRouteId,
                'activity_name' => $activity['name'],
                'activity_date' => $activity['activity_date'],
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    // ✅ Calculate unique coverage (from coverage_ranges)
    $uniqueCoverage = $challengeModel->calculateUniqueCoverage($referenceRouteId, $userId);

    // ✅ Update challenge progress (only if route is in challenge)
    require_once __DIR__ . '/../../includes/classes/ChallengeProgressManager.php';

    $challenges = $challengeModel->getChallengesForRoute($referenceRouteId);

    if (!empty($challenges)) {
        $progressManager = new ChallengeProgressManager($db);

        foreach ($challenges as $challenge) {
            $challengeId = $challenge['challenge_id'];

            $userProgress = $challengeModel->getUserChallengeProgressForChallangeId($userId, $challengeId);

            if ($userProgress) {
                log_debug("Updating progress for challenge $challengeId", 'info');

                try {
                    $progressManager->recalculateUserProgress($userId, $challengeId);

                    // ✅ Flag for medal check
                    $stmt = $db->prepare("
                        UPDATE user_challenge_progress 
                        SET needs_medal_check = 1 
                        WHERE user_id = ? AND challenge_id = ?
                    ");
                    $stmt->execute([$userId, $challengeId]);
                } catch (Exception $e) {
                    log_debug("Challenge progress update failed: " . $e->getMessage(), 'warning');
                }
            }
        }
    }

    sendJSON(true, [
        'activities_found' => count($userActivities),
        'activities_matched' => $matchedCount,
        'total_coverage_pct' => $uniqueCoverage['coverage_pct'],
        'total_covered_km' => $uniqueCoverage['covered_km'],
        'total_route_km' => $uniqueCoverage['total_km'],
        'processing_time_total' => round($totalTime, 2),
        'matches' => $matches
    ]);
    
} catch (Exception $e) {
    log_debug('Match activities error: ' . $e->getMessage(), 'error');
    log_debug('Stack trace: ' . $e->getTraceAsString(), 'error');
    sendError('Failed to match activities: ' . $e->getMessage(), 500);
}