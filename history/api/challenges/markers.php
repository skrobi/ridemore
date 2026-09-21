<?php
require_once __DIR__ . '/../config.php';

try {
    $db = getDB();
    
    $stmt = $db->query("
        SELECT 
            challenge_id,
            name,
            icon,
            color,
            ST_Y(ST_Centroid(boundary)) as lat,
            ST_X(ST_Centroid(boundary)) as lng,
            (SELECT COUNT(*) FROM user_challenge_progress WHERE challenge_id = challenges.challenge_id) as participants_count
        FROM challenges
        WHERE active = 1
          AND boundary IS NOT NULL
        ORDER BY name
    ");
    
    $markers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $markers[] = [
            'challenge_id' => (int)$row['challenge_id'],
            'name' => $row['name'],
            'icon' => $row['icon'] ?: '🏆',
            'color' => $row['color'] ?: '#10b981',
            'center' => [(float)$row['lat'], (float)$row['lng']],
            'participants_count' => (int)$row['participants_count']
        ];
    }
    
    sendJSON(true, $markers);
    
} catch (Exception $e) {
    sendError('Failed to load markers: ' . $e->getMessage(), 500);
}