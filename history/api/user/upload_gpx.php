<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../includes/classes/ActivityUploader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

if (!isset($_FILES['file'])) {
    sendError('Missing file parameter');
}

// ✅ AUTH - użyj requireAuth() + cookie fallback
try {
    $user = requireAuth();
    $userId = $user['user_id'];
    
    log_debug("Activity upload by user {$userId}", 'info');
    
} catch (Exception $e) {
    sendError('Authentication required', 401);
}

// UPLOAD
try {
    $db = getDB();
    
    // ✅ Options from POST
    $options = [
        'name' => $_POST['name'] ?? null,
        'description' => $_POST['description'] ?? null,
        'route_type' => $_POST['route_type'] ?? null,
        'difficulty' => $_POST['difficulty'] ?? null,
        'external_id' => $_POST['external_id'] ?? null,
        'route_purpose' => 'activity',  // ✅ ZAWSZE activity
        'visibility' => $_POST['visibility'] ?? 'private',
        'source' => 'gpx_upload'
    ];
    
    // ✅ Upload using ActivityUploader
    $activityUploader = new ActivityUploader($db, $userId);
    $result = $activityUploader->uploadActivity($_FILES['file'], $options);
    
    sendJSON(true, array_merge($result, [
        'message' => 'Activity uploaded successfully'
    ]));
    
} catch (Exception $e) {
    log_debug('Activity upload error: ' . $e->getMessage(), 'error');
    
    $message = $e->getMessage();
    
    if (strpos($message, 'timestamps') !== false) {
        sendError('Invalid activity: ' . $message, 400);
    } elseif (strpos($message, 'Duplicate') !== false) {
        sendError($message, 409);
    } else {
        sendError('Activity upload failed: ' . $message, 500);
    }
}