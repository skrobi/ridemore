<?php
/* ============================================================================
   /api/user/profile.php
   
   GET - Get user profile data
   PATCH - Update user profile
   ============================================================================ */

require_once '../config.php';

// Verify JWT
$userData = requireAuth();

try {
    $db = getDB();
    $userModel = new UserModel($db);
    
    // GET - Fetch profile
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $profile = $userModel->getUserById($userData['user_id']);
        
        if (!$profile) {
            sendError('User not found', 404);
        }
        
        // Check if API key exists, if not generate one
        if (empty($profile['api_key'])) {
            $profile['api_key'] = $userModel->generateApiKey($userData['user_id']);
        }
        
        // Remove sensitive data
        unset($profile['strava_access_token']);
        unset($profile['strava_refresh_token']);
        unset($profile['garmin_access_token']);
        unset($profile['garmin_refresh_token']);
        
        sendJSON(true, $profile);
    }
    
    // PATCH - Update profile
    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON input');
        }
        
        // Validate tab
        $tab = $input['tab'] ?? 'personal';
        
        if (!in_array($tab, ['personal', 'account', 'settings'])) {
            sendError('Invalid tab');
        }
        
        // Handle different tabs
        if ($tab === 'personal') {
            // Validate and sanitize
            $data = [
                'username' => isset($input['username']) ? trim($input['username']) : null,
                'first_name' => isset($input['first_name']) ? trim($input['first_name']) : null,
                'last_name' => isset($input['last_name']) ? trim($input['last_name']) : null,
                'phone' => isset($input['phone']) ? trim($input['phone']) : null,
                'shipping_address' => isset($input['shipping_address']) ? trim($input['shipping_address']) : null
            ];
            
            // Validate username (3-20 chars, alphanumeric + underscore)
            if ($data['username'] && !preg_match('/^[a-zA-Z0-9_]{3,20}$/', $data['username'])) {
                sendError('Username must be 3-20 characters (letters, numbers, underscore only)');
            }
            
            // Validate phone format (optional)
            if ($data['phone'] && !preg_match('/^[0-9\s\+\-\(\)]{9,20}$/', $data['phone'])) {
                sendError('Invalid phone number format');
            }
            
            try {
                $userModel->updateProfile($userData['user_id'], $data);
                sendJSON(true, ['message' => 'Profile updated successfully']);
            } catch (Exception $e) {
                sendError($e->getMessage());
            }
        }
        
        if ($tab === 'account') {
            // For now, just regenerate API key if requested
            if (isset($input['regenerate_api_key']) && $input['regenerate_api_key'] === true) {
                $newApiKey = $userModel->generateApiKey($userData['user_id']);
                sendJSON(true, ['api_key' => $newApiKey]);
            }
            
            sendJSON(true, ['message' => 'No changes']);
        }
        
        if ($tab === 'settings') {
            // Settings are handled client-side in localStorage for now
            sendJSON(true, ['message' => 'Settings updated']);
        }
        
        sendError('Unknown operation');
    }
    
    sendError('Method not allowed', 405);
    
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}