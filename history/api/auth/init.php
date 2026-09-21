<?php
/* ============================================================================
   Pierwszy kontakt gościa - zapisz user_local_id do bazy
   ============================================================================ */

require_once '../config.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_local_id'])) {
    sendError('user_local_id is required');
}

$localId = $input['user_local_id'];


try {
    $user = getOrCreateUser($localId);
    
    sendJSON(true, [
        'user_id' => $user['user_id'],
        'user_local_id' => $user['user_local_id'],
        'status' => $user['status']
    ]);
} catch (Exception $e) {
    sendError('Failed to initialize user: ' . $e->getMessage(), 500);
}