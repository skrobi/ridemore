<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    $user = optionalAuth();
    $userId = $user['user_id'] ?? 1; // Guest default
    
    $filter = $_GET['filter'] ?? 'global'; // 'global' | 'user'
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $limit = min($limit, 100); // Max 100
    
    $feedManager = new ActivityFeedManager(getDB());
    
    // ✅ Jeden manager, różne filtry
    $events = $feedManager->getFormattedFeed($userId, $filter, $limit);
    
    sendJSON(true, [
        'events' => $events,
        'filter' => $filter,
        'count' => count($events),
        'has_more' => count($events) === $limit
    ]);
    
} catch (Exception $e) {
    log_debug('Feed error: ' . $e->getMessage(), 'error');
    sendError('Failed to load feed', 500);
}