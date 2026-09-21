<?php
/* ============================================================================
   PHOTOS API - DEBUG VERSION
   ============================================================================ */

require_once __DIR__ . '/../config.php';

// ✅ MEGA DEBUG - loguj wszystko
log_debug("=== PHOTOS API DEBUG START ===");
log_debug("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
log_debug("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
log_debug("QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'empty'));
log_debug("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
log_debug("GET params: " . print_r($_GET, true));
log_debug("POST params: " . print_r($_POST, true));
log_debug("FILES: " . print_r($_FILES, true));

// Get action
$action = $_GET['action'] ?? null;

log_debug("Parsed action: " . ($action ?? 'NULL'));
log_debug("=== PHOTOS API DEBUG END ===");

// ✅ Set JSON content-type for responses
header('Content-Type: application/json; charset=utf-8');

if (!$action) {
    log_debug("ERROR: No action provided!");
    sendError('action parameter is required. Allowed: upload, list, associate, delete, set_primary');
}

log_debug("Proceeding with action: $action");

try {
    log_debug("Calling getDB()...");
    $db = getDB();
    log_debug("✅ DB connection OK");
    
    log_debug("Creating PhotoModel...");
    $photoModel = new PhotoModel($db);
    log_debug("✅ PhotoModel created");
    
    log_debug("Creating PhotoManager...");
    $photoManager = new PhotoManager($db);
    log_debug("✅ PhotoManager created");
    
    log_debug("Switch statement - action: $action");
    
    log_debug("Switch statement - action: $action");
    
    switch ($action) {
        
        // ====================================================================
        // UPLOAD - POST multipart/form-data
        // ====================================================================
        case 'upload':
            log_debug("=== UPLOAD CASE ENTERED ===");
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                log_debug("ERROR: Method not POST, got: " . $_SERVER['REQUEST_METHOD']);
                sendError('Method not allowed. Use POST', 405);
            }
            
            log_debug("Checking authentication...");
            $user = requireAuth();
            log_debug("✅ User authenticated: user_id=" . $user['user_id']);
            
            if (!isset($_FILES['image'])) {
                log_debug("ERROR: No image file in FILES array");
                log_debug("FILES array: " . print_r($_FILES, true));
                sendError('No image file provided');
            }
            
            log_debug("✅ Image file found: " . $_FILES['image']['name']);
            
            $options = [
                'caption' => $_POST['caption'] ?? null,
                'is_public' => isset($_POST['is_public']) ? (int)$_POST['is_public'] : 1,
                'auto_create_poi' => isset($_POST['auto_create_poi']) ? (int)$_POST['auto_create_poi'] : 1
            ];
            
            log_debug("Upload options: " . print_r($options, true));
            log_debug("Calling photoManager->uploadPhoto()...");
            
            $result = $photoManager->uploadPhoto($_FILES['image'], $user['user_id'], $options);
            
            log_debug("✅ Upload successful! photo_id=" . $result['photo_id']);
            
            sendJSON(true, [
                'photo_id' => $result['photo_id'],
                'file_path' => $result['file_path'],
                'url' => get_image_url($result['file_path']),
                'has_gps' => $result['has_gps'],
                'poi_id' => $result['poi_id'],
                'exif' => $result['exif'],
                'message' => 'Photo uploaded successfully'
            ]);
            break;
            
        // ====================================================================
        // LIST - GET ?entity_type=route&entity_id=123
        // ====================================================================
        case 'list':
            log_debug("=== LIST CASE ENTERED ===");
            
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                sendError('Method not allowed. Use GET', 405);
            }
            
            // List photos for entity
            if (isset($_GET['entity_type']) && isset($_GET['entity_id'])) {
                $entityType = $_GET['entity_type'];
                $entityId = (int)$_GET['entity_id'];
                
                $allowedTypes = ['route', 'poi', 'challenge', 'user', 'achievement', 'activity'];
                if (!in_array($entityType, $allowedTypes)) {
                    sendError('Invalid entity_type. Allowed: ' . implode(', ', $allowedTypes));
                }
                
                $photos = $photoModel->getEntityPhotos($entityType, $entityId);
                
                // Add full URLs
                foreach ($photos as &$photo) {
                    $photo['url'] = get_image_url($photo['file_path']);
                    $photo['thumbnail_url'] = get_image_url($photo['file_path'], 400);
                }
                
                sendJSON(true, [
                    'photos' => $photos,
                    'count' => count($photos),
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ]);
                
            // List user's photos
            } elseif (isset($_GET['user_id'])) {
                $user = requireAuth();
                
                if ($_GET['user_id'] === 'me' || (int)$_GET['user_id'] === $user['user_id']) {
                    $userId = $user['user_id'];
                } else {
                    sendError('Unauthorized', 403);
                }
                
                $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
                $photos = $photoModel->getUserPhotos($userId, $limit);
                
                foreach ($photos as &$photo) {
                    $photo['url'] = get_image_url($photo['file_path']);
                }
                
                sendJSON(true, [
                    'photos' => $photos,
                    'count' => count($photos),
                    'user_id' => $userId
                ]);
                
            } else {
                sendError('Required: entity_type + entity_id OR user_id');
            }
            break;
            
        // ====================================================================
        // ASSOCIATE - POST JSON
        // ====================================================================
        case 'associate':
            log_debug("=== ASSOCIATE CASE ENTERED ===");
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendError('Method not allowed. Use POST', 405);
            }
            
            $user = requireAuth();
            $input = get_json_body();
            
            log_debug("Associate input: " . print_r($input, true));
            
            if (!isset($input['photo_id'], $input['entity_type'], $input['entity_id'])) {
                log_debug("ERROR: Missing required fields in associate");
                sendError('Missing required fields: photo_id, entity_type, entity_id');
            }
            
            $allowedTypes = ['route', 'poi', 'challenge', 'user', 'achievement', 'activity'];
            if (!in_array($input['entity_type'], $allowedTypes)) {
                sendError('Invalid entity_type. Allowed: ' . implode(', ', $allowedTypes));
            }
            
            $photo = $photoModel->getPhotoById($input['photo_id']);
            if (!$photo) {
                sendError('Photo not found', 404);
            }
            
            if ($photo['uploaded_by_user_id'] !== $user['user_id']) {
                sendError('Unauthorized', 403);
            }
            
            $associationId = $photoModel->associatePhoto(
                $input['photo_id'],
                $input['entity_type'],
                $input['entity_id'],
                $user['user_id'],
                $input['is_primary'] ?? false,
                $input['caption_override'] ?? null
            );
            
            sendJSON(true, [
                'association_id' => $associationId,
                'photo_id' => $input['photo_id'],
                'entity_type' => $input['entity_type'],
                'entity_id' => $input['entity_id'],
                'message' => 'Photo associated successfully'
            ]);
            break;
            
        // ====================================================================
        // DELETE - DELETE ?photo_id=123
        // ====================================================================
        case 'delete':
            log_debug("=== DELETE CASE ENTERED ===");
            
            if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
                sendError('Method not allowed. Use DELETE', 405);
            }
            
            $user = requireAuth();
            
            if (!isset($_GET['photo_id'])) {
                sendError('photo_id is required');
            }
            
            $photoId = (int)$_GET['photo_id'];
            
            $deleted = $photoModel->deletePhoto($photoId, $user['user_id']);
            
            if (!$deleted) {
                sendError('Photo not found or unauthorized', 404);
            }
            
            sendJSON(true, [
                'photo_id' => $photoId,
                'message' => 'Photo deleted successfully'
            ]);
            break;
            
        // ====================================================================
        // SET PRIMARY - PATCH JSON
        // ====================================================================
        case 'set_primary':
            log_debug("=== SET_PRIMARY CASE ENTERED ===");
            
            if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendError('Method not allowed. Use PATCH or POST', 405);
            }
            
            $user = requireAuth();
            $input = get_json_body();
            
            if (!isset($input['photo_id'], $input['entity_type'], $input['entity_id'])) {
                sendError('Missing required fields: photo_id, entity_type, entity_id');
            }
            
            $photo = $photoModel->getPhotoById($input['photo_id']);
            if (!$photo) {
                sendError('Photo not found', 404);
            }
            
            if ($photo['uploaded_by_user_id'] !== $user['user_id']) {
                sendError('Unauthorized', 403);
            }
            
            $success = $photoModel->setPrimaryPhoto(
                $input['photo_id'],
                $input['entity_type'],
                $input['entity_id']
            );
            
            if (!$success) {
                sendError('Failed to set primary photo', 500);
            }
            
            sendJSON(true, [
                'photo_id' => $input['photo_id'],
                'entity_type' => $input['entity_type'],
                'entity_id' => $input['entity_id'],
                'message' => 'Primary photo set successfully'
            ]);
            break;
            
        default:
            log_debug("ERROR: Invalid action: $action");
            sendError('Invalid action. Allowed: upload, list, associate, delete, set_primary');
    }
    
} catch (Exception $e) {
    log_debug('EXCEPTION: ' . $e->getMessage(), 'error');
    log_debug('STACK: ' . $e->getTraceAsString(), 'error');
    $code = $e->getCode() ?: 500;
    sendError($e->getMessage(), $code);
}