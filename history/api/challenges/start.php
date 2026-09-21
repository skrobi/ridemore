<?php

/* ============================================================================
  START CHALLENGE - v3.0 FULLY WITH MODEL
  POST /api/challenges/start.php

  Creates user_challenge_progress header by aggregating activity_route_coverage
  ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_local_id']) || !isset($input['challenge_id'])) {
    sendError('user_local_id and challenge_id are required');
}

$userLocalId = $input['user_local_id'];
$challengeId = (int) $input['challenge_id'];

try {
    $db = getDB();

    // ✅ Initialize model
    $challengeModel = new ChallengeModel($db);

    // Get user
    $user = getOrCreateUser($userLocalId);
    $userId = $user['user_id'];

    // ✅ Check if challenge exists
    $challenge = $challengeModel->getChallengeById($challengeId);

    if (!$challenge) {
        sendError('Challenge not found or inactive', 404);
    }

    // Check dates
    $now = date('Y-m-d');

    if ($challenge['start_date'] && $challenge['start_date'] > $now) {
        sendError('Challenge has not started yet. Starts on: ' . $challenge['start_date'], 400);
    }

    if ($challenge['end_date'] && $challenge['end_date'] < $now) {
        sendError('Challenge has already ended on: ' . $challenge['end_date'], 400);
    }

    // ✅ Check if already started
    if ($challengeModel->isUserEnrolled($userId, $challengeId)) {
        sendError('Challenge already started', 409);
    }

    // ✅ START CHALLENGE - all logic in model
    $result = $challengeModel->startChallenge($userId, $challengeId);

    createFeedEvent(
            'challenge_started',
            $userId,
            ['challenge_name' => $challenge['name']],
            'challenge',
            $challengeId
    );

    $message = $result['segments']['completed'] > 0 ? "Challenge started! You already completed {$result['segments']['completed']}/{$result['segments']['total']} segments ({$result['segments']['progress_pct']}%)" : 'Challenge started successfully';

    sendJSON(true, [
        'progress_id' => $result['progress_id'],
        'initial_progress' => $result,
        'message' => $message
    ]);
} catch (Exception $e) {
    log_debug('Start challenge error: ' . $e->getMessage(), 'error');
    sendError('Failed to start challenge: ' . $e->getMessage(), 500);
}