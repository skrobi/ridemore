<?php
// api/layers/list.php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT 
            layer_id,
            name,
            slug,
            icon,
            color,
            stroke_width,
            stroke_style,
            opacity,
            has_outline,
            outline_color,
            is_animated,
            visible_default,
            min_zoom,
            max_zoom,
            sort_order,
            route_count
        FROM v_route_layer_control
        ORDER BY sort_order
    ");
    $stmt->execute();
    $layers = $stmt->fetchAll();
    
    foreach ($layers as &$layer) {
        $layer['layer_id'] = (int)$layer['layer_id'];
        $layer['stroke_width'] = (int)$layer['stroke_width'];
        $layer['opacity'] = (float)$layer['opacity'];
        $layer['has_outline'] = (bool)$layer['has_outline'];
        $layer['is_animated'] = (bool)$layer['is_animated'];
        $layer['visible_by_default'] = (bool)$layer['visible_default']; // Rename dla JS
        $layer['min_zoom'] = (int)$layer['min_zoom'];
        $layer['max_zoom'] = (int)$layer['max_zoom'];
        $layer['display_order'] = (int)$layer['sort_order']; // Rename dla JS
        $layer['route_count'] = (int)$layer['route_count'];
    }
    
    sendJSON(true, [
        'layers' => $layers
    ]);
    
} catch (Exception $e) {
    log_debug('Layers list error: ' . $e->getMessage(), 'error');
    sendError('Failed to load layers: ' . $e->getMessage(), 500);
}