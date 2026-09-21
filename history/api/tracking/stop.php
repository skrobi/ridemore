<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

/**
 * Stop GPS Tracking Session
 */

// ================= AUTH =================
$userData = requireAuth();
$userId = $userData['user_id'];

// ================= INPUT =================
$input = json_decode(file_get_contents('php://input'), true);
$batchId = $input['batch_id'] ?? null;

if (!$batchId) {
    sendError('missing batch_id', 400);
}

// ================= CHECK SESSION =================
$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT user_id, is_active
    FROM tracking_sessions 
    WHERE batch_id = ?
");
$stmt->execute([$batchId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    sendError('session not found', 404);
}

// Sprawdź ownership
if ((int)$session['user_id'] !== $userId) {
    sendError('unauthorized', 403);
}

// Sprawdź czy już nie zakończona
if (!$session['is_active']) {
    sendError('session already stopped', 400);
}

// ================= STOP SESSION =================
$stmt = $pdo->prepare("
    UPDATE tracking_sessions 
    SET is_active = 0, stopped_at = NOW()
    WHERE batch_id = ? AND user_id = ?
");
$stmt->execute([$batchId, $userId]);

// ================= RESPONSE =================
sendJSON(true, [
    'message' => 'tracking stopped',
    'batch_id' => $batchId,
    'stopped_at' => date('Y-m-d H:i:s')
]);