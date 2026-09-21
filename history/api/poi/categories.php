<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT 
            category_id,
            name,
            slug,
            description,
            default_icon,
            default_color,
            sort_order,
            visible_default
        FROM poi_categories
        WHERE active = 1
        ORDER BY sort_order
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    sendJSON(true, $categories);
    
} catch (Exception $e) {
    log_debug('POI categories error: ' . $e->getMessage(), 'error');
    sendError('Failed to load categories', 500);
}