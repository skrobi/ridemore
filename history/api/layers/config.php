<?php
/**
 * GET /api/layers/config.php?active_only=1
 * 
 * Zwraca konfigurację wszystkich warstw pogrupowanych po typie:
 * - tiles: warstwy MVT z tile_config
 * - overlays: warstwy GeoJSON z api_endpoint i source_filter
 * - poi: warstwy POI
 * - live: warstwy Live Tracking
 */
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    $db = getDB();
    
    $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] == '1';
    $where = $activeOnly ? "WHERE active = 1" : "";
    
    $stmt = $db->prepare("
        SELECT 
            layer_id,
            name,
            slug,
            layer_type,
            tile_url_template,
            tile_config,
            metadata_url,
            api_endpoint,
            source_filter,
            color,
            stroke_width,
            stroke_style,
            opacity,
            visible_default,
            min_zoom,
            max_zoom,
            sort_order
        FROM map_layers
        $where
        ORDER BY sort_order
    ");
    
    $stmt->execute();
    $layers = $stmt->fetchAll();
    
    // Group by type
    $config = [
        'tiles' => [],
        'overlays' => [],
        'poi' => [],
        'live' => []  // ✅ DODANE
    ];
    
    foreach ($layers as $layer) {
        $base = [
            'layer_id' => (int)$layer['layer_id'],
            'slug' => $layer['slug'],
            'name' => $layer['name'],
            'visible_default' => (bool)$layer['visible_default'],
            'min_zoom' => (int)$layer['min_zoom'],
            'max_zoom' => (int)$layer['max_zoom'],
            'sort_order' => (int)$layer['sort_order']
        ];
        
        if ($layer['layer_type'] === 'tile') {
            $base['tile_url'] = $layer['tile_url_template'];
            $base['tile_config'] = json_decode($layer['tile_config'], true);
            $base['metadata_url'] = $layer['metadata_url'];
            $config['tiles'][] = $base;
            
        } elseif ($layer['layer_type'] === 'overlay') {
            $base['api_endpoint'] = $layer['api_endpoint'];
            $base['source_filter'] = json_decode($layer['source_filter'], true);
            $base['style'] = [
                'color' => $layer['color'],
                'weight' => (int)$layer['stroke_width'],
                'style' => $layer['stroke_style'],
                'opacity' => (float)$layer['opacity']
            ];
            $config['overlays'][] = $base;
            
        } elseif ($layer['layer_type'] === 'poi') {
            $base['api_endpoint'] = '/api/poi/list.php';
            $config['poi'][] = $base;
            
        } elseif ($layer['layer_type'] === 'live') {  // ✅ DODANE
            $base['api_endpoint'] = $layer['api_endpoint'] ?: 'tracking/live.php';
            $base['color'] = $layer['color'] ?: '#10b981';
            $base['refresh_rate'] = 120000; // 2 min w ms
            $config['live'][] = $base;
        }
    }
    
    sendJSON(true, $config);
    
} catch (Exception $e) {
    log_debug('Layers config error: ' . $e->getMessage(), 'error');
    sendError('Failed to load layer config: ' . $e->getMessage(), 500);
}