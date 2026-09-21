<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['email'])) {
    sendError('email is required');
}

$email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    sendError('Invalid email format');
}

$userLocalId = $input['user_local_id'] ?? null;

try {
    $db = getDB();
    $userModel = new UserModel($db);
    
    // ✅ 1. Find user by email
    $existingUser = $userModel->getUserByEmail($email);
    
    if ($existingUser) {
        $userId = $existingUser['user_id'];
        
        // ✅ Check if merge needed
        if ($userLocalId && $existingUser['user_local_id'] !== $userLocalId) {
            $guestUser = $userModel->getUserByLocalId($userLocalId);
            
            if ($guestUser && $guestUser['user_id'] !== $userId) {
                // ✅ Merge guest into existing user
                $success = $userModel->mergeGuestIntoUser($guestUser['user_id'], $userId);
                
                if ($success) {
                    log_debug("Merged guest {$guestUser['user_id']} into {$userId}", 'info');
                }
            }
        }
        
    } elseif ($userLocalId) {
        // ✅ Guest upgrade
        $user = getOrCreateUser($userLocalId);
        $userId = $user['user_id'];
        $userModel->updateUserEmail($userId, $email);
        
    } else {
        // ✅ New user
        $newLocalId = bin2hex(random_bytes(16));
        $userId = $userModel->createUserWithEmail($newLocalId, $email);
    }
    
    // ✅ Generate token
    $userModel->deleteOldAuthTokens($userId);
    
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + MAGIC_LINK_EXPIRY);
    
    $userModel->createAuthToken($userId, $token, $userLocalId, $expiresAt);
    
    // Generate link
    $magicLink = rtrim(APP_URL, '/') . '/api/auth/verify.php?token=' . $token;
    
    // Send email
    $emailBody = renderEmailTemplate('magic_link', [
        'magic_link' => $magicLink,
        'expires_minutes' => MAGIC_LINK_EXPIRY / 60
    ]);
    
    $emailSent = sendEmail($email, "Zaloguj się do Ridemore.bike", $emailBody);
    
    if (!$emailSent) {
        sendError('Failed to send email', 500);
    }
    
    sendJSON(true, [
        'message' => 'Magic link sent to ' . $email
    ]);
    
} catch (Exception $e) {
    log_debug('Send magic link error: ' . $e->getMessage(), 'error');
    sendError('Failed to send magic link: ' . $e->getMessage(), 500);
}