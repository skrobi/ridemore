<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (!isset($_GET['token'])) {
    showErrorPage('Brak tokenu', 'Link weryfikacyjny jest nieprawidłowy.');
}

$token = $_GET['token'];

if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    showErrorPage('Nieprawidłowy token', 'Format tokenu jest niepoprawny.');
}

try {
    $db = getDB();
    $userModel = new UserModel($db);

    // Get token data
    $tokenData = $userModel->getAuthToken($token);

    if (!$tokenData) {
        header('Location: ' . APP_URL . '/error.php?title=' . urlencode('Nieprawidłowy link') . '&message=' . urlencode('Ten link weryfikacyjny jest nieprawidłowy lub został już użyty.') . '&retry=1');
exit;
    }

    if ($tokenData['used']) {
        header('Location: ' . APP_URL . '/error.php?title=' . urlencode('Link już użyty') . '&message=' . urlencode('Ten link weryfikacyjny jest nieprawidłowy lub został już użyty.') . '&retry=1');
    }

    if (strtotime($tokenData['expires_at']) < time()) {
        header('Location: ' . APP_URL . '/error.php?title=' . urlencode('Link wygasł') . '&message=' . urlencode('Ten link weryfikacyjny wygasł. Wygeneruj nowy link logowania.') . '&retry=1');
    
    }

    // Mark as used
    $userModel->markTokenAsUsed($tokenData['token_id']);

    // Merge if needed
    $frontendLocalId = $tokenData['user_local_id'];

    if ($frontendLocalId && $frontendLocalId !== $tokenData['db_local_id']) {
        $guestUser = $userModel->getUserByLocalId($frontendLocalId);

        if ($guestUser && $guestUser['user_id'] !== $tokenData['user_id']) {
            $userModel->mergeGuestIntoUser(
                    $guestUser['user_id'],
                    $tokenData['user_id']
            );
        }
    }

    // Verify user
    $userModel->verifyUser($tokenData['user_id']);

    // Generate JWT and redirect
    $jwt = generateJWT([
        'user_id' => $tokenData['user_id'],
        'user_local_id' => $tokenData['db_local_id'],
        'email' => $tokenData['email'],
        'status' => 'verified',
        'role' => $tokenData['role']
    ]);

    setcookie(
            'auth_token',
            $jwt,
            [
                'expires' => time() + JWT_EXPIRY,
                'path' => '/',
                'httponly' => false,
                'secure' => true,
                'samesite' => 'Lax'
            ]
    );

    $redirectUrl = rtrim(APP_URL, '/') . "/index.php?auth_token=" . urlencode($jwt)
            . "&email=" . urlencode($tokenData['email'])
            . "&sync_local_id=" . urlencode($tokenData['db_local_id']);

    header("Location: $redirectUrl");
    exit;
} catch (Exception $e) {
    log_debug('Verify error: ' . $e->getMessage(), 'error');
    $redirectUrl = rtrim(APP_URL, '/') . "/error.php";
    header("Location: $redirectUrl");

}