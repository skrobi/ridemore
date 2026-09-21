<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['challenge_id'])) {
    sendError('challenge_id required');
}

$challengeId = (int)$_GET['challenge_id'];
$page = (int)($_GET['page'] ?? 1);
$perPage = (int)($_GET['per_page'] ?? 50);
$userLocalId = $_GET['user_local_id'] ?? null;

try {
    $db = getDB();
    $rankingManager = new RankingManager($db);
    
    // Get user
    $user = getOrCreateUser($userLocalId);
    $userId = $user['user_id'];
    
    // ✅ Get challenge info
    $challengeModel = new ChallengeModel($db);
    $challenge = $challengeModel->getChallengeById($challengeId);
    
    if (!$challenge) {
        sendError('Challenge not found', 404);
    }
    
    // Get leaderboard
    $leaderboard = $rankingManager->getChallengeLeaderboard($challengeId, $page, $perPage);
    
    // Get current user's rank
    $currentUserRank = $rankingManager->getUserChallengeRank($userId, $challengeId);
    
    sendJSON(true, [
        'challenge' => [
            'challenge_id' => (int)$challenge['challenge_id'],
            'name' => $challenge['name'],
            'total_distance_km' => (float)$challenge['total_distance_km'],
            'total_routes' => (int)$challenge['total_routes']
        ],
        'leaderboard' => $leaderboard['data'],
        'pagination' => $leaderboard['pagination'],
        'current_user' => $currentUserRank
    ]);
    
} catch (Exception $e) {
    log_debug('Leaderboard API error: ' . $e->getMessage(), 'error');
    sendError('Failed to load leaderboard: ' . $e->getMessage(), 500);
}