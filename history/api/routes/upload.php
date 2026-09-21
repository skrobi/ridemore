<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../includes/classes/GPXUploader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

if (!isset($_FILES['file'])) {
    sendError('Missing file parameter');
}

// Auth
$user = requireAuth();
$userId = $user['user_id'];

try {
    $db = getDB();
    
    // Options from POST
    $options = [
        'name' => $_POST['name'] ?? null,
        'description' => $_POST['description'] ?? null,
        'route_type' => $_POST['route_type'] ?? null,
        'difficulty' => $_POST['difficulty'] ?? null,
        'route_purpose' => $_POST['route_purpose'] ?? 'reference',
        'visibility' => $_POST['visibility'] ?? 'public',
        'source' => $_POST['source'] ?? 'admin_upload'
    ];
    
    // ✅ Upload using GPXUploader
    $uploader = new GPXUploader($db, $userId);
    $result = $uploader->upload($_FILES['file'], $options);
    
    // ✅ Auto-assign to layer
    $layerModel = new LayerModel($db);
    $layerModel->autoAssignRouteToLayer(
        $result['route_id'],
        $options['route_purpose'],
        $options['source'],
        $options['visibility']
    );

    sendJSON(true, $result);

} catch (Exception $e) {
    log_debug('Upload error: ' . $e->getMessage(), 'error');
    sendError($e->getMessage(), 400);
}