<?php
/**
 * GET /api/poi/details.php?id=123
 * 
 * Zwraca pełne szczegóły POI wraz ze zdjęciami
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $poiId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if (!$poiId) {
        throw new Exception('Missing poi_id parameter');
    }
    $db = getDB();
    $poiModel = new POIModel($db);
    $photoModel = new PhotoModel($db);
    
    // 1. Get POI details
    $poi = $poiModel->getPOIById($poiId);
    
    if (!$poi) {
        throw new Exception('POI not found');
    }
    
    // 2. Get associated photos
    $photos = $photoModel->getEntityPhotos('poi', $poiId);
    
    // 3. Increment view counter
    $poiModel->incrementViewCount($poiId);
    
    // 4. Format response
    $response = [
        'poi_id' => (int)$poi['poi_id'],
        'name' => $poi['name'],
        'description' => $poi['description'],
        'description_source' => $poi['description_source'],
        'lat' => (float)$poi['lat'],
        'lon' => (float)$poi['lon'],
        'elevation_m' => $poi['elevation_m'] ? (float)$poi['elevation_m'] : null,
        'category' => [
            'id' => (int)$poi['category_id'],
            'name' => $poi['category_name'],
            'slug' => $poi['category_slug']
        ],
        'subtype' => $poi['subtype_id'] ? [
            'id' => (int)$poi['subtype_id'],
            'name' => $poi['subtype_name'],
            'slug' => $poi['subtype_slug']
        ] : null,
        'icon' => $poi['icon'],
        'color' => $poi['color'],
        'weight' => (float)$poi['weight'],
        'contact' => [
            'url' => $poi['url'],
            'phone' => $poi['phone'],
            'email' => $poi['email'],
            'opening_hours' => $poi['opening_hours']
        ],
        'address' => $poi['address'],
        'stats' => [
            'photo_count' => (int)$poi['photo_count'],
            'view_count' => (int)$poi['view_count'],
            'popularity_score' => (float)$poi['popularity_score']
        ],
        'photos' => array_map(function($photo) {
            return [
                'photo_id' => (int)$photo['photo_id'],
                'file_path' => $photo['file_path'],
                'url' => get_photo_url($photo['file_path']),
                'thumbnail_url' => get_photo_url($photo['file_path'], 200),
                'caption' => $photo['caption_override'] ?: $photo['caption'],
                'is_primary' => (bool)$photo['is_primary'],
                'taken_at' => $photo['taken_at'],
                'uploaded_by' => $photo['uploaded_by_username'],
                'camera' => $photo['camera_make'] && $photo['camera_model'] 
                    ? "{$photo['camera_make']} {$photo['camera_model']}"
                    : null,
                'exif' => [
                    'focal_length' => $photo['focal_length'],
                    'aperture' => $photo['aperture'],
                    'iso' => $photo['iso'],
                    'exposure_time' => $photo['exposure_time']
                ]
            ];
        }, $photos),
        'created_at' => $poi['created_at'],
        'updated_at' => $poi['updated_at']
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $response
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
