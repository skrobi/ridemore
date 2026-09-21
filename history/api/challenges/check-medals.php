<?php
/* ============================================================================
   API: Check Medals - REFACTORED
   Force medal check for user's challenges
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_local_id'])) {
    sendError('user_local_id is required');
}

$userLocalId = $input['user_local_id'];

try {
    $db = getDB();
    
    $user = getOrCreateUser($userLocalId);
    $userId = $user['user_id'];
    
    log_debug("Manual medal check for user: $userId", 'info');
    
    $medalModel = new MedalAchievementsModel($db);
    
    // Get challenges needing check
    $challenges = $medalModel->getChallengesNeedingMedalCheck($userId);
    
    $newMedals = 0;
    $processedChallenges = 0;
    
    foreach ($challenges as $challenge) {
        $challengeId = $challenge['challenge_id'];
        $progressPct = (float)$challenge['progress_pct'];
        
        $medal = $medalModel->processMedalAwards($userId, $challengeId, $progressPct);
        
        if ($medal) {
            $newMedals++;
        }
        
        $processedChallenges++;
    }
    
    log_debug("Medal check done: $newMedals new, $processedChallenges checked", 'info');
    
    sendJSON(true, [
        'message' => 'Medals checked successfully',
        'new_medals' => $newMedals,
        'processed_challenges' => $processedChallenges
    ]);
    
} catch (Exception $e) {
    log_debug('Check medals error: ' . $e->getMessage(), 'error');
    sendError('Failed to check medals: ' . $e->getMessage(), 500);
}