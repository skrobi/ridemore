<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

if (empty($_COOKIE['auth_token'])) {
    sendError('Unauthorized', 401);
}

try {
    $payload = verifyJWT($_COOKIE['auth_token']);

    // opcjonalnie: sprawdzenie statusu / roli
    if ($payload['status'] !== 'verified') {
        sendError('User not verified', 403);
    }

    sendJSON(true, [
        'user_id'        => $payload['user_id'],
        'user_local_id' => $payload['user_local_id'],
        'email'         => $payload['email'],
        'role'          => $payload['role']
    ]);

} catch (Exception $e) {
    sendError('Invalid token', 401);
}
