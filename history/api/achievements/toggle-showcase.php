<?php
/* ============================================================================
   API: Toggle Showcase - Add/Remove medal from showcase
   NEW STRUCTURE: achievement_showcases with display_order
   Max 3 medals per user
   ============================================================================ */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$userLocalId = $input['user_local_id'] ?? null;
$levelId = (int)($input['level_id'] ?? 0);
$action = $input['action'] ?? 'toggle'; // 'toggle', 'add', 'remove'

if (!$userLocalId) {
    sendError('user_local_id is required');
}

if (!$levelId) {
    sendError('level_id is required');
}

try {
    $db = getDB();
    
    // Get user
    $user = getOrCreateUser($userLocalId);
    $userId = $user['user_id'];
    
    log_debug("Toggle showcase: user=$userId, level=$levelId, action=$action", 'info');
    
    // Check if user has this medal
    $stmt = $db->prepare("
        SELECT uma.id, ml.name as medal_name
        FROM user_medal_achievements uma
        JOIN medal_levels ml ON uma.level_id = ml.level_id
        WHERE uma.user_id = ? AND uma.level_id = ?
    ");
    $stmt->execute([$userId, $levelId]);
    $medal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$medal) {
        sendError('Medal not earned yet');
    }
    
    // Check if currently in showcase
    $stmt = $db->prepare("
        SELECT id, display_order
        FROM achievement_showcases
        WHERE user_id = ? AND level_id = ?
    ");
    $stmt->execute([$userId, $levelId]);
    $showcaseEntry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $isInShowcase = (bool)$showcaseEntry;
    
    // Determine action
    if ($action === 'toggle') {
        $shouldAdd = !$isInShowcase;
    } elseif ($action === 'add') {
        $shouldAdd = true;
    } elseif ($action === 'remove') {
        $shouldAdd = false;
    } else {
        sendError('Invalid action');
    }
    
    // Execute action
    if ($shouldAdd && !$isInShowcase) {
        // ADD TO SHOWCASE
        
        // Check limit
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM achievement_showcases
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $count = (int)$stmt->fetchColumn();
        
        if ($count >= 3) {
            sendError('Gablota może zawierać maksymalnie 3 medale. Usuń jeden medal aby dodać nowy.');
        }
        
        // Get next display_order
        $stmt = $db->prepare("
            SELECT COALESCE(MAX(display_order), 0) + 1 as next_order
            FROM achievement_showcases
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $displayOrder = (int)$stmt->fetchColumn();
        
        // Insert
        $stmt = $db->prepare("
            INSERT INTO achievement_showcases (user_id, level_id, display_order)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $levelId, $displayOrder]);
        
        $message = "Medal '{$medal['medal_name']}' dodany do gabloty";
        $inShowcase = true;
        
        log_debug($message, 'info');
        
    } elseif (!$shouldAdd && $isInShowcase) {
        // REMOVE FROM SHOWCASE
        
        $removedOrder = $showcaseEntry['display_order'];
        
        // Delete entry
        $stmt = $db->prepare("
            DELETE FROM achievement_showcases
            WHERE user_id = ? AND level_id = ?
        ");
        $stmt->execute([$userId, $levelId]);
        
        // Reorder remaining medals (fill the gap)
        $stmt = $db->prepare("
            UPDATE achievement_showcases
            SET display_order = display_order - 1
            WHERE user_id = ? AND display_order > ?
        ");
        $stmt->execute([$userId, $removedOrder]);
        
        $message = "Medal '{$medal['medal_name']}' usunięty z gabloty";
        $inShowcase = false;
        
        log_debug($message, 'info');
        
    } else {
        // No change needed
        $message = $isInShowcase 
            ? "Medal już jest w gablocie" 
            : "Medal nie jest w gablocie";
        $inShowcase = $isInShowcase;
    }
    
    sendJSON(true, [
        'message' => $message,
        'is_in_showcase' => $inShowcase,
        'level_id' => $levelId
    ]);
    
} catch (Exception $e) {
    log_debug('Toggle showcase error: ' . $e->getMessage(), 'error');
    sendError('Failed to update showcase: ' . $e->getMessage(), 500);
}