<?php
/* ============================================================================
   GET /api/auth/me.php
   
   Zwraca dane zalogowanego użytkownika (wymaga JWT)
   + sprawdza dostęp do manage panel
   ============================================================================ */
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

// Verify JWT
$userData = requireAuth();

try {
    $db = getDB();
    
    // ✅ Use UserModel
    $userModel = new UserModel($db);
    
    // Get user data
    $user = $userModel->getUserById($userData['user_id']);
    
    if (!$user) {
        sendError('User not found', 404);
    }
    
    // ✅ Get manage access
    $manageAccess = $userModel->getManageAccess($user['role']);
    $user = array_merge($user, $manageAccess);
    
    // ✅ Update last active
    $userModel->updateLastActive($userData['user_id']);
    
    // Remove sensitive data
    unset($user['subscription_expires_at']);
    
    sendJSON(true, $user);
    
} catch (Exception $e) {
    sendError('Failed to fetch user data: ' . $e->getMessage(), 500);
}