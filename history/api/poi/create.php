<?php
/**
 * POST /api/poi/create.php
 * 
 * Tworzenie nowego POI przez użytkownika
 * Body: JSON { name, category_id, subtype_id, lat, lon, description, ... }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

try {
    $user = requireAuth();
    $input = get_json_body();
    $db = getDB();
    // Validate required fields
    if (empty($input['name'])) {
        throw new Exception('Nazwa jest wymagana');
    }
    if (empty($input['category_id'])) {
        throw new Exception('Kategoria jest wymagana');
    }
    if (!isset($input['lat']) || !isset($input['lon'])) {
        throw new Exception('Współrzędne są wymagane');
    }

    $poiModel = new POIModel($db);
    $photoModel = new PhotoModel($db);

    // Validate category exists
    $category = $poiModel->getCategoryById((int)$input['category_id']);
    if (!$category) {
        throw new Exception('Kategoria nie istnieje');
    }

    // Validate subtype if provided
    $subtype = null;
    if (!empty($input['subtype_id'])) {
        $subtype = $poiModel->getSubtypeById((int)$input['subtype_id']);
        if (!$subtype) {
            throw new Exception('Podtyp nie istnieje');
        }
        if ((int)$subtype['category_id'] !== (int)$input['category_id']) {
            throw new Exception('Podtyp nie należy do wybranej kategorii');
        }
    }

    // Icon/color: subtype → category fallback
    $icon = $subtype['icon'] ?? $category['default_icon'] ?? null;
    $color = $subtype['color'] ?? $category['default_color'] ?? null;
    $poiSubtype = $subtype['slug'] ?? null;

    // Create POI
    $poiId = $poiModel->createPOI([
        'category_id' => (int)$input['category_id'],
        'subtype_id' => !empty($input['subtype_id']) ? (int)$input['subtype_id'] : null,
        'lat' => (float)$input['lat'],
        'lon' => (float)$input['lon'],
        'name' => $input['name'],
        'poi_type' => 'user_created',
        'poi_subtype' => $poiSubtype,
        'icon' => $icon,
        'color' => $color,
        'description' => $input['description'] ?? null,
        'url' => $input['url'] ?? null,
        'phone' => $input['phone'] ?? null,
        'email' => $input['email'] ?? null,
        'opening_hours' => $input['opening_hours'] ?? null,
        'address' => $input['address'] ?? null,
        'created_by_user_id' => $user['user_id']
    ]);

    // Associate photo if provided
    if (!empty($input['photo_id'])) {
        $photoModel->associatePhoto(
            (int)$input['photo_id'],
            'poi',
            $poiId,
            $user['user_id'],
            true  // is_primary
        );

        // Update photo_count cache
        $poiModel->updatePhotoCount($poiId);
    }

    sendJSON(true, [
        'poi_id' => $poiId,
        'message' => 'POI created successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}