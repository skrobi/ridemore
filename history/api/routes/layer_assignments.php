<?php
// api/routes/layer_assignments.php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['route_id'])) {
    sendError('route_id required');
}

$routeId = validateRouteId($_GET['route_id']);

try {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT layer_id 
        FROM route_layer_assignments 
        WHERE route_id = ?
    ");
    $stmt->execute([$routeId]);
    $layerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    sendJSON(true, [
        'route_id' => $routeId,
        'layer_ids' => array_map('intval', $layerIds)
    ]);
    
} catch (Exception $e) {
    log_debug('Layer assignments error: ' . $e->getMessage(), 'error');
    sendError('Failed to load layer assignments', 500);
}