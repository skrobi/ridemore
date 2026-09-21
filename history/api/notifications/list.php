<?php
require_once __DIR__ . '/../config.php';

$user = requireAuth();
$userId = $user['user_id'];



sendJSON(true, [
    'notifications' => [],
    'unread_count' => []
]);