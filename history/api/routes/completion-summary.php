<?php
/* ============================================================================
   COMPLETION SUMMARY - Podsumowanie ukończonych tras
   
   GET /api/routes/completion-summary.php?user_local_id=xxx
   
   Zwraca:
   - Podsumowanie oficjalnych tras (% ukończenia)
   - Podsumowanie każdego challenge (% ukończenia)
   - Lista tras z completion %
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['user_local_id'])) {
    sendError('user_local_id is required');
}

try {
    $db = getDB();
    $user = getOrCreateUser($_GET['user_local_id']);
    $userId = $user['user_id'];
    
    /* ====================================================================
       OFFICIAL ROUTES SUMMARY
       ==================================================================== */
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_routes,
            SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) as completed_routes,
            ROUND(AVG(completion_pct), 1) as avg_completion_pct,
            SUM(total_segments) as total_segments,
            SUM(completed_segments) as completed_segments
        FROM user_route_completion
        WHERE user_id = ?
          AND route_source = 'admin_upload'
    ");
    
    $stmt->execute([$userId]);
    $officialSummary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    /* ====================================================================
       CHALLENGES SUMMARY (per challenge)
       ==================================================================== */
    
    $stmt = $db->prepare("
        SELECT 
            c.challenge_id,
            c.name as challenge_name,
            c.slug,
            c.icon,
            c.color,
            COUNT(DISTINCT urc.target_route_id) as total_routes,
            SUM(CASE WHEN urc.is_completed = 1 THEN 1 ELSE 0 END) as completed_routes,
            ROUND(AVG(urc.completion_pct), 1) as avg_completion_pct,
            ROUND(
                SUM(CASE WHEN urc.is_completed = 1 THEN 1 ELSE 0 END) / 
                COUNT(DISTINCT urc.target_route_id) * 100,
                1
            ) as challenge_completion_pct
        FROM challenges c
        JOIN challenge_routes cr ON c.challenge_id = cr.challenge_id
        JOIN user_route_completion urc ON urc.target_route_id = cr.route_id
        WHERE urc.user_id = ?
          AND c.active = 1
        GROUP BY c.challenge_id
        ORDER BY challenge_completion_pct DESC
    ");
    
    $stmt->execute([$userId]);
    $challengesSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    /* ====================================================================
       TOP INCOMPLETE ROUTES (najbliższe ukończenia)
       ==================================================================== */
    
    $stmt = $db->prepare("
        SELECT 
            target_route_id as route_id,
            route_name,
            route_source,
            completion_pct,
            is_completed,
            challenge_names
        FROM user_route_completion
        WHERE user_id = ?
          AND is_completed = 0
          AND completion_pct > 0
        ORDER BY completion_pct DESC
        LIMIT 10
    ");
    
    $stmt->execute([$userId]);
    $topIncomplete = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    /* ====================================================================
       RESPONSE
       ==================================================================== */
    
    sendJSON(true, [
        'official_routes' => [
            'total' => (int)$officialSummary['total_routes'],
            'completed' => (int)$officialSummary['completed_routes'],
            'avg_completion_pct' => (float)$officialSummary['avg_completion_pct'],
            'total_segments' => (int)$officialSummary['total_segments'],
            'completed_segments' => (int)$officialSummary['completed_segments']
        ],
        'challenges' => array_map(function($ch) {
            return [
                'challenge_id' => (int)$ch['challenge_id'],
                'name' => $ch['challenge_name'],
                'slug' => $ch['slug'],
                'icon' => $ch['icon'],
                'color' => $ch['color'],
                'total_routes' => (int)$ch['total_routes'],
                'completed_routes' => (int)$ch['completed_routes'],
                'avg_completion_pct' => (float)$ch['avg_completion_pct'],
                'challenge_completion_pct' => (float)$ch['challenge_completion_pct']
            ];
        }, $challengesSummary),
        'top_incomplete' => $topIncomplete
    ]);
    
} catch (Exception $e) {
    log_debug('Completion summary error: ' . $e->getMessage(), 'error');
    sendError('Failed to fetch completion summary: ' . $e->getMessage(), 500);
}