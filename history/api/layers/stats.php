<?php
require_once __DIR__ . '/../config.php';

try {
    $db = getDB();
    $layerModel = new LayerModel($db);
    
    $stats = $layerModel->getLayerStats();
    
    sendJSON(true, $stats);
    
} catch (Exception $e) {
    sendError('Failed to load layer stats: ' . $e->getMessage(), 500);
}