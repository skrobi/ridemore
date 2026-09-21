<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    // Get bbox
    $bbox = $_GET['bbox'] ?? null;
    
    if (!$bbox) {
        throw new Exception('Missing bbox parameter');
    }
    
    $coords = explode(',', $bbox);
    
    if (count($coords) !== 4) {
        throw new Exception('Invalid bbox format. Expected: minLon,minLat,maxLon,maxLat');
    }
    
    [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $coords);
    
    // Filters
    $filters = [];
    
    // Subtypes filter (preferred)
    if (!empty($_GET['subtypes'])) {
        $filters['subtypes'] = explode(',', $_GET['subtypes']);
    }
    
    // Categories filter (fallback)
    if (!empty($_GET['categories'])) {
        $filters['categories'] = explode(',', $_GET['categories']);
    }
    
    // Limit
    $filters['limit'] = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
    
    // Get POI
    $poiModel = new POIModel(getDB());
    $pois = $poiModel->getPOIInBbox($minLon, $minLat, $maxLon, $maxLat, $filters);
    
    // Convert to GeoJSON
    $features = [];
    
    foreach ($pois as $poi) {
        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float)$poi['lon'], (float)$poi['lat']]
            ],
            'properties' => [
                'poi_id' => (int)$poi['poi_id'],
                'name' => $poi['name'],
                'category_id' => (int)$poi['category_id'],
                'category_name' => $poi['category_name'],
                'category_slug' => $poi['category_slug'],
                'subtype_id' => $poi['subtype_id'] ? (int)$poi['subtype_id'] : null,
                'subtype_name' => $poi['subtype_name'],
                'subtype_slug' => $poi['subtype_slug'],
                'icon' => $poi['icon'],
                'color' => $poi['color'],
                'weight' => (float)$poi['weight'],
                'poi_role'        => $poi['poi_role'] ?? 'attraction',
                'route_highlight' => (bool)($poi['route_highlight'] ?? 0),  
            ]
        ];
    }
    
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $geojson,
        'count' => count($features)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}