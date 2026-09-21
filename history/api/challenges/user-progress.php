<?php
/* ============================================================================
   USER PROGRESS - Optimized with medal completion logic + DEBUG LOGS
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

// Sprawdź auth_token
$authToken = null;
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authToken = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
}

$userId = null;

// Priorytet: token > user_local_id
if ($authToken) {
    try {
        $tokenData = validateToken($authToken);
        if ($tokenData && isset($tokenData['user_id'])) {
            $userId = $tokenData['user_id'];
        }
    } catch (Exception $e) {
        ////log_debug("Invalid token: " . $e->getMessage(), 'warning');
    }
}

if (!$userId) {
    if (!isset($_GET['user_local_id'])) {
        sendError('user_local_id required');
    }
    $user = getOrCreateUser($_GET['user_local_id']);
    $userId = $user['user_id'];
}

try {
    $db = getDB();
    
    //log_debug("=== USER PROGRESS START (user_id: $userId) ===", 'info');
    
    // ========================================================================
    // QUERY 1: Wszystkie grupy + challenges (bez user data)
    // ========================================================================
    $stmt = $db->prepare("
        SELECT 
            cg.group_id,
            cg.name as group_name,
            cg.icon as group_icon,
            cg.color as group_color,
            c.challenge_id,
            c.name as challenge_name,
            c.icon as challenge_icon,
            c.color as challenge_color,
            c.image_url,
            c.type,
            c.description,
            c.total_routes,
            c.total_distance_km,
            c.total_ascent_m,
            c.min_completion_pct
        FROM challenge_groups cg
        LEFT JOIN challenges c ON cg.group_id = c.group_id AND c.active = 1
        WHERE cg.active = 1
        ORDER BY cg.sort_order, c.challenge_id
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    //log_debug("Loaded " . count($rows) . " challenge rows", 'info');
    
    // ========================================================================
    // QUERY 2: User progress - TYLKO dla tego usera (1 query)
    // ========================================================================
    $stmt = $db->prepare("
        SELECT 
            challenge_id,
            progress_id,
            completed_count,
            progress_pct,
            current_level
        FROM user_challenge_progress
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    
    $userProgress = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $userProgress[(int)$p['challenge_id']] = [
            'progress_id' => (int)$p['progress_id'],
            'completed_count' => (int)$p['completed_count'],
            'progress_pct' => (float)$p['progress_pct'],
            'current_level' => $p['current_level']
        ];
    }
    
    ////log_debug("User has progress in " . count($userProgress) . " challenges", 'info');
    
    // ========================================================================
    // QUERY 3: Medal info per challenge
    // ========================================================================
    $stmt = $db->prepare("
        SELECT 
            ml.challenge_id,
            COUNT(*) as total_medals,
            MAX(ml.required_percent_min) as highest_medal_threshold
        FROM medal_levels ml
        WHERE ml.active = 1
        GROUP BY ml.challenge_id
    ");
    $stmt->execute();
    $medalInfo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $medalInfo[(int)$m['challenge_id']] = [
            'total_medals' => (int)$m['total_medals'],
            'highest_threshold' => (float)$m['highest_medal_threshold']
        ];
    }
    
    //log_debug("Loaded medal info for " . count($medalInfo) . " challenges", 'info');
    
    // ========================================================================
    // QUERY 4: User's earned medals per challenge
    // ========================================================================
    $stmt = $db->prepare("
        SELECT challenge_id, COUNT(*) as earned_medals
        FROM user_medal_achievements
        WHERE user_id = ?
        GROUP BY challenge_id
    ");
    $stmt->execute([$userId]);
    $earnedMedals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $earnedMedals[(int)$e['challenge_id']] = (int)$e['earned_medals'];
    }
    
    //log_debug("User has earned medals in " . count($earnedMedals) . " challenges", 'info');
    
    // ========================================================================
    // MERGE: Połącz challenges z user progress
    // ========================================================================
    $groups = [];
    $totalChallenges = 0;
    $startedChallenges = 0;
    $completedChallenges = 0;
    
    foreach ($rows as $row) {
        $groupId = (int)$row['group_id'];
        
        // Utwórz grupę jeśli nie istnieje
        if (!isset($groups[$groupId])) {
            $groups[$groupId] = [
                'group_id' => $groupId,
                'name' => $row['group_name'],
                'icon' => $row['group_icon'],
                'color' => $row['group_color'],
                'challenges' => []
            ];
        }
        
        // Dodaj challenge jeśli istnieje
        if ($row['challenge_id']) {
            $challengeId = (int)$row['challenge_id'];
            $totalChallenges++;
            
            // Pobierz progress usera (jeśli istnieje)
            $progress = $userProgress[$challengeId] ?? null;
            
            $started = $progress !== null;
            if ($started) $startedChallenges++;
            
            $minPct = (float)($row['min_completion_pct'] ?? 100);
            $medals = $medalInfo[$challengeId] ?? null;
            $earned = $earnedMedals[$challengeId] ?? 0;
            
            // Completed = min % reached AND all medals earned (or no medals)
            $hasMedals = $medals !== null;
            $allMedalsEarned = !$hasMedals || $earned >= $medals['total_medals'];
            $reachedMinPct = $progress && $progress['progress_pct'] >= $minPct;
            
            $completed = $reachedMinPct && $allMedalsEarned;

            if ($completed) $completedChallenges++;
            
            $groups[$groupId]['challenges'][] = [
                'challenge_id' => $challengeId,
                'name' => $row['challenge_name'],
                'description' => $row['description'],
                'icon' => $row['challenge_icon'],
                'color' => $row['challenge_color'],
                'image_url' => $row['image_url'],
                'type' => $row['type'],
                'total_routes' => (int)$row['total_routes'],
                'total_distance_km' => (float)$row['total_distance_km'],
                'total_ascent_m' => (int)$row['total_ascent_m'],
                'min_completion_pct' => $minPct,
                'started' => $started,
                'completed' => $completed,
                'completed_count' => $progress ? $progress['completed_count'] : 0,
                'progress_pct' => $progress ? $progress['progress_pct'] : 0,
                'current_level' => $progress ? $progress['current_level'] : 'none',
                'group_name' => $row['group_name'],
                'total_medals' => $medals ? $medals['total_medals'] : 0,
                'earned_medals' => $earned
            ];
        }
    }
    
    //log_debug("=== SUMMARY ===", 'info');
    //log_debug("Total: $totalChallenges, Started: $startedChallenges, Completed: $completedChallenges", 'info');
    
    // ========================================================================
    // RESPONSE - identyczna struktura jak wcześniej
    // ========================================================================
    sendJSON(true, [
        'groups' => array_values($groups),
        'stats' => [
            'total' => $totalChallenges,
            'started' => $startedChallenges,
            'completed' => $completedChallenges
        ]
    ]);
    
} catch (Exception $e) {
    //log_debug('User progress error: ' . $e->getMessage(), 'error');
    sendError('Failed to load progress: ' . $e->getMessage(), 500);
}