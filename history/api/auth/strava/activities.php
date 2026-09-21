<?php
/* ============================================================================
   GET /api/auth/strava/activities.php
   Pobierz listę aktywności z Strava dostępnych do importu
   Filtruje: tylko cycling, wyklucza już zaimportowane (dedup po external_id)
   Wymaga: JWT
   ============================================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../../includes/classes/providers/StravaProvider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

$userData = requireAuth();
$userId  = $userData['user_id'];

$db       = getDB();
$manager  = new IntegrationModel($db);
$provider = new StravaProvider();

// ============================================================
// 1. Sprawdź czy Strava linked + refresh token jeśli trzeba
// ============================================================
$tokens = $manager->getTokens($userId, 'strava');

if (!$tokens) {
    sendError('Strava not linked', 403);
}

if ($manager->needsRefresh($userId, 'strava')) {
    $newTokens = $provider->refreshToken($tokens['refresh_token']);

    if ($newTokens) {
        $manager->saveTokens(
            $userId, 'strava',
            $newTokens['access_token'],
            $tokens['refresh_token'],       // keep existing
            $newTokens['expires_at'],
            $tokens['provider_user_id']     // keep existing
        );
        $tokens['access_token'] = $newTokens['access_token'];
    } else {
        sendError('Token refresh failed. Please reconnect Strava.', 401);
    }
}

// ============================================================
// 2. Pobierz aktywności z Strava API
// ============================================================
$page       = max(1, (int)($_GET['page'] ?? 1));
$activities = $provider->getActivities($tokens['access_token'], 30, $page);

if ($activities === false) {
    sendError('Failed to fetch activities from Strava', 502);
}

// ============================================================
// 3. Filtruj – tylko cycling
// ============================================================
$cycling = array_values(
    array_filter($activities, fn($a) => $provider->isCyclingActivity($a))
);

// ============================================================
// 4. Pobierz już zaimportowane external_ids dla tego usera
// ============================================================
$stmt = $db->prepare("
    SELECT rm.external_id
    FROM routes_meta rm
    JOIN routes r ON rm.route_id = r.route_id
    WHERE r.user_id = ? AND rm.external_source = 'strava'
");
$stmt->execute([$userId]);
$importedIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'external_id');

// ============================================================
// 5. Formatuj response
// ============================================================
$result = [];
foreach ($cycling as $activity) {
    $stravaId = (string)$activity['id'];

    $result[] = [
        'strava_id'        => $stravaId,
        'name'             => $activity['name'] ?? 'Aktywność ' . $stravaId,
        'type'             => $activity['sport_type'] ?? $activity['type'] ?? 'Ride',
        'date'             => $activity['start_date_local'] ?? null,
        'distance_km'      => round(($activity['distance'] ?? 0) / 1000, 2),
        'ascent_m'         => (int)($activity['total_elevation_gain'] ?? 0),
        'moving_time_min'  => (int)(($activity['moving_time'] ?? 0) / 60),
        'already_imported' => in_array($stravaId, $importedIds),
    ];
}

// has_more = Strava zwróciła pełną stronę (30) – może być więcej
$hasMore = count($activities) >= 30;

sendJSON(true, [
    'activities' => $result,
    'page'       => $page,
    'count'      => count($result),
    'has_more'   => $hasMore,
]);