<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

/**
 * Live Tracking Map Endpoint
 * Returns GeoJSON with users currently tracking
 */

// ✅ Opcjonalna autoryzacja
$userData = optionalAuth();

// ✅ Get live tracking users
$userModel = new UserModel(getDB());
$liveUsers = $userModel->getUsersTrackingLive();

// ✅ Convert to GeoJSON
$features = [];

foreach ($liveUsers as $user) {
    // Get user initials for avatar fallback
    $initials = '';
    if ($user['username']) {
        $parts = explode(' ', $user['username']);
        $initials = strtoupper(
            (isset($parts[0]) ? substr($parts[0], 0, 1) : '') .
            (isset($parts[1]) ? substr($parts[1], 0, 1) : '')
        );
        if (strlen($initials) === 0) {
            $initials = strtoupper(substr($user['username'], 0, 2));
        }
    } else {
        $initials = 'U' . substr((string)$user['user_id'], 0, 1);
    }
    
    // Challenge color
    $challengeColor = $user['race_id'] > 0 ? '#3b82f6' : '#10b981'; // Blue for challenge, green for loose
    
    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float)$user['last_location_lon'], (float)$user['last_location_lat']]
        ],
        'properties' => [
            'user_id' => (int)$user['user_id'],
            'username' => $user['username'] ?? 'Użytkownik #' . $user['user_id'],
            'initials' => $initials,
            'challenge' => $user['race_id'] > 0 ? [
                'id' => (int)$user['race_id'],
                'name' => $user['challenge_name'],
                'slug' => $user['challenge_slug'],
                'color' => $challengeColor
            ] : null,
            'batch_id' => $user['batch_id'],
            'updated_at' => $user['last_location_updated_at'],
            'minutes_ago' => (int)$user['minutes_ago'],
            'color' => $challengeColor
        ]
    ];
}

$geojson = [
    'type' => 'FeatureCollection',
    'features' => $features
];

sendJSON(true, [
    'geojson' => $geojson,
    'total' => count($features),
    'updated_at' => date('Y-m-d H:i:s')
]);