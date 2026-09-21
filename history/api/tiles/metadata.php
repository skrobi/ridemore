<?php
/**
 * GET /api/tiles/metadata.php?layer=segments
 * 
 * Zwraca metadata dla konkretnego tilesetu
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['layer'])) {
    sendError('layer parameter is required');
}

try {
    $db = getDB();
    $layer = $_GET['layer'];
    
    $stmt = $db->prepare("
        SELECT 
            layer,
            last_generated_at,
            segments_count,
            routes_included,
            h3_cells_count,
            generation_duration_sec,
            tiles_size_mb,
            updated_at
        FROM tile_metadata
        WHERE layer = :layer
    ");
    
    $stmt->execute([':layer' => $layer]);
    $meta = $stmt->fetch();
    
    if (!$meta) {
        sendError('Metadata not found for layer: ' . $layer, 404);
    }
    
    sendJSON(true, [
        'layer' => $meta['layer'],
        'last_generated' => $meta['last_generated_at'],
        'segments_count' => (int)$meta['segments_count'],
        'routes_included' => (int)$meta['routes_included'],
        'h3_cells_count' => (int)$meta['h3_cells_count'],
        'generation_duration_sec' => (int)$meta['generation_duration_sec'],
        'tiles_size_mb' => (float)$meta['tiles_size_mb'],
        'updated_at' => $meta['updated_at']
    ]);
    
} catch (Exception $e) {
    log_debug('Tile metadata error: ' . $e->getMessage(), 'error');
    sendError('Failed to fetch metadata: ' . $e->getMessage(), 500);
}