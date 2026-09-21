<?php
/* ============================================================================
   API: Reorder Showcase - Change display order (drag-and-drop)
   ============================================================================ */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$userLocalId = $input['user_local_id'] ?? null;
$newOrder = $input['order'] ?? null; // Array: [level_id1, level_id2, level_id3]

if (!$userLocalId || !is_array($newOrder)) {
    sendError('user_local_id and order array required');
}

if (count($newOrder) > 3) {
    sendError('Max 3 medals in showcase');
}

try {
    $db = getDB();
    
    $user = getOrCreateUser($userLocalId);
    $userId = $user['user_id'];
    
    log_debug("Reordering showcase for user $userId", 'info');
    
    $db->beginTransaction();
    
    // Update display_order for each medal
    $displayOrder = 1;
    foreach ($newOrder as $levelId) {
        $stmt = $db->prepare("
            UPDATE achievement_showcases
            SET display_order = ?
            WHERE user_id = ? AND level_id = ?
        ");
        $stmt->execute([$displayOrder, $userId, (int)$levelId]);
        $displayOrder++;
    }
    
    $db->commit();
    
    log_debug("Showcase reordered successfully", 'info');
    
    sendJSON(true, [
        'message' => 'Kolejność medali zaktualizowana',
        'order' => $newOrder
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    log_debug('Reorder error: ' . $e->getMessage(), 'error');
    sendError('Failed to reorder: ' . $e->getMessage(), 500);
}