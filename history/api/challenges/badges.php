<?php
/* ============================================================================
   API: User Badges - FIXED - Finds CLOSEST locked medal
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['user_local_id'])) {
    sendError('user_local_id parameter is required');
}

$userLocalId = $_GET['user_local_id'];

try {
    $db = getDB();
    
    $user = getOrCreateUser($userLocalId);
    $userId = $user['user_id'];
    
    log_debug("Loading badges for user: $userId", 'info');
    
    // ========================================================================
    // Use Models
    // ========================================================================
    $challengeModel = new ChallengeModel($db);
    $medalModel = new MedalAchievementsModel($db);
    $rankingModel = new RankingModel($db);
    
    // Get showcase
    $showcaseMedalIds = $medalModel->getShowcaseMedalIds($userId);
    
    $badges = [];
    
    // Get all user's active challenges
    $userChallenges = $challengeModel->getUserActiveChallenges($userId);
    
    log_debug("Found " . count($userChallenges) . " active challenges for user", 'info');
    
    foreach ($userChallenges as $challenge) {
        $challengeId = $challenge['challenge_id'];
        
        // ✅ Get full challenge data if needed fields are missing
        if (!isset($challenge['challenge_name']) || !isset($challenge['challenge_icon'])) {
            $fullChallenge = $challengeModel->getChallengeById($challengeId);
            
            if (!$fullChallenge) {
                log_debug("Challenge $challengeId not found, skipping", 'warning');
                continue;
            }
            
            // Merge user progress with full challenge data
            $challengeData = array_merge($fullChallenge, [
                'progress_pct' => $challenge['progress_pct'] ?? 0,
                'completed_count' => $challenge['completed_count'] ?? 0,
                'total_count' => $challenge['total_count'] ?? 0,
                'streak_days' => $challenge['streak_days'] ?? 0,
                'longest_streak' => $challenge['longest_streak'] ?? 0,
                'last_activity_date' => $challenge['last_activity_date'] ?? null,
            ]);
            
            // Rename fields to match formatBadgeResponse expectations
            $challengeData['challenge_name'] = $challengeData['name'];
            $challengeData['challenge_description'] = $challengeData['description'];
            $challengeData['challenge_icon'] = $challengeData['icon'];
            $challengeData['challenge_color'] = $challengeData['color'];
            $challengeData['challenge_total_routes'] = $challengeData['total_routes'];
            $challengeData['challenge_end_date'] = $challengeData['end_date'];
            
        } else {
            // Already has all needed fields
            $challengeData = $challenge;
        }
        
        $currentPct = (float)($challengeData['progress_pct'] ?? 0);
        
        log_debug("Processing challenge {$challengeData['challenge_name']} (ID: $challengeId, progress: {$currentPct}%)", 'info');
        
        // Get all medals for this challenge
        $allMedals = $medalModel->getAllMedalsForChallenge($challengeId);
        
        if (empty($allMedals)) {
            log_debug("No medals found for challenge $challengeId", 'warning');
            continue;
        }
        
        log_debug("Found " . count($allMedals) . " medals for challenge $challengeId", 'info');
        
        // Get earned medals
        $earnedMedals = $medalModel->getEarnedMedalsForChallenge($userId, $challengeId);
        $earnedMedalsMap = [];
        foreach ($earnedMedals as $earned) {
            $earnedMedalsMap[$earned['level_id']] = $earned;
        }
        
        $highestEarnedMedal = null;
        $nextLockedMedal = null;
        $earnedCount = 0;
        $closestDistance = PHP_FLOAT_MAX;
        
        // ✅ FIXED: Find highest earned AND closest locked
        foreach ($allMedals as $medal) {
            $isEarned = isset($earnedMedalsMap[$medal['level_id']]);
            $requiredPct = (float)$medal['required_percent_min'];
            
            if ($isEarned) {
                // Track earned
                $earnedCount++;
                $highestEarnedMedal = $medal;
                log_debug("  - Medal '{$medal['medal_name']}' is EARNED", 'info');
                
            } else {
                // ✅ Find CLOSEST locked medal (smallest distance from current progress)
                $distance = $requiredPct - $currentPct;
                
                log_debug("  - Medal '{$medal['medal_name']}' is LOCKED (req: {$requiredPct}%, dist: {$distance}%)", 'info');
                
                if ($distance > 0 && $distance < $closestDistance) {
                    $closestDistance = $distance;
                    $nextLockedMedal = $medal;
                    log_debug("    → This is now the CLOSEST locked medal", 'info');
                }
            }
        }
        
        $totalTiers = count($allMedals);
        
        log_debug("Summary: earned={$earnedCount}, closest locked=" . ($nextLockedMedal ? $nextLockedMedal['medal_name'] : 'none'), 'info');
        
        // Add HIGHEST EARNED medal (if any)
        if ($highestEarnedMedal) {
            $earnedData = $earnedMedalsMap[$highestEarnedMedal['level_id']];
            
            $badge = $medalModel->formatBadgeResponse(
                $highestEarnedMedal,
                $challengeData,
                true, // earned
                $earnedData,
                $currentPct,
                $totalTiers,
                $earnedCount,
                in_array($highestEarnedMedal['level_id'], $showcaseMedalIds),
                $userId   
            );
            
            // ✅ ADD RANKING DATA
            $ranking = $rankingModel->getUserChallengeRank($userId, $challengeId);
            
            $badge['ranking'] = $ranking ? [
                'rank' => (int) $ranking['rank'],
                'total_participants' => (int) $ranking['total_participants'],
                'total_score' => (int) $ranking['total_score'],
                'coverage_pct' => (float) $ranking['coverage_pct']
            ] : null;
            
            $badges[] = $badge;
            
            log_debug("✅ Added EARNED badge: {$highestEarnedMedal['medal_name']}", 'info');
        }
        
        // Add NEXT LOCKED medal (if any)
        if ($nextLockedMedal) {
            $badges[] = $medalModel->formatBadgeResponse(
                $nextLockedMedal,
                $challengeData,
                false, // not earned
                null,
                $currentPct,
                $totalTiers,
                $earnedCount,
                false,
                $userId // locked medals can't be in showcase
            );
            
            log_debug("✅ Added LOCKED badge: {$nextLockedMedal['medal_name']}", 'info');
        }
    }
    
    // Stats
    $totalEarned = count(array_filter($badges, fn($b) => $b['earned']));
    $totalLocked = count(array_filter($badges, fn($b) => !$b['earned']));
    
    $rarityBreakdown = $medalModel->calculateRarityBreakdown($badges);
    
    log_debug("Badges loaded: $totalEarned earned, $totalLocked locked, total: " . count($badges), 'info');
    
    sendJSON(true, [
        'badges' => $badges,
        'showcase' => $showcaseMedalIds,
        'stats' => [
            'total' => count($badges),
            'earned' => $totalEarned,
            'locked' => $totalLocked,
            'rarity_breakdown' => $rarityBreakdown
        ]
    ]);
    
} catch (Exception $e) {
    log_debug('Badges error: ' . $e->getMessage(), 'error');
    sendError('Failed to load badges: ' . $e->getMessage(), 500);
}