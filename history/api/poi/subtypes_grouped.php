<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    $poiModel = new POIModel($db);
    $subtypes = $poiModel->getSubtypesGrouped();
    
    echo json_encode([
        'success' => true,
        'data' => $subtypes
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}