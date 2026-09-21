<?php
/* ============================================================================
   POST /api/auth/strava/import.php
   Import selected activities from Strava to routes
   Body: { "activity_ids": ["12345", "67890"] }
   Requires: JWT
   
   ✅ REFACTORED to use GPXUploader::uploadFromPolyline()
   ============================================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../../includes/classes/providers/StravaProvider.php';
require_once __DIR__ . '/../../../includes/classes/GPXUploader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$userData = requireAuth();
$userId = $userData['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['activity_ids']) || !is_array($input['activity_ids']) || empty($input['activity_ids'])) {
    sendError('activity_ids array required');
}

$activityIds = $input['activity_ids'];

if (count($activityIds) > 50) {
    sendError('Max 50 activities per request');
}

$db = getDB();
$manager = new IntegrationModel($db);
$provider = new StravaProvider();
$routeModel = new RouteModel($db);
$gpxUploader = new GPXUploader($db, $userId);

// ============================================================
// 1. Check Strava linked + refresh token
// ============================================================
$tokens = $manager->getTokens($userId, 'strava');

if (!$tokens) {
    sendError('Strava not linked', 403);
}

if ($manager->needsRefresh($userId, 'strava')) {
    $newTokens = $provider->refreshToken($tokens['refresh_token']);

    if ($newTokens) {
        $manager->saveTokens(
            $userId,
            'strava',
            $newTokens['access_token'],
            $tokens['refresh_token'],
            $newTokens['expires_at'],
            $tokens['provider_user_id']
        );
        $tokens['access_token'] = $newTokens['access_token'];
    } else {
        sendError('Token refresh failed. Please reconnect Strava.', 401);
    }
}

// ============================================================
// 2. Loop – fetch, upload via GPXUploader, save metadata
// ============================================================
$results = [];
$imported = 0;
$skipped = 0;

foreach ($activityIds as $activityId) {
    $activityId = (string) $activityId;

    try {
        // --- Check duplicate ---
        $exists = $db->prepare("
            SELECT r.route_id, r.name 
            FROM routes_meta rm
            JOIN routes r ON rm.route_id = r.route_id
            WHERE r.user_id = ? 
              AND rm.external_source = 'strava' 
              AND rm.external_id = ?
        ");
        $exists->execute([$userId, $activityId]);

        if ($row = $exists->fetch(PDO::FETCH_ASSOC)) {
            $skipped++;
            $results[] = [
                'strava_id' => $activityId,
                'status' => 'skipped',
                'reason' => 'already imported',
                'route_id' => $row['route_id'],
                'name' => $row['name']
            ];
            continue;
        }

        // --- Fetch full activity from Strava ---
        $activity = $provider->getActivity($tokens['access_token'], $activityId);

        if (!$activity) {
            $results[] = [
                'strava_id' => $activityId,
                'status' => 'error',
                'reason' => 'fetch failed'
            ];
            continue;
        }

        // --- Validate polyline ---
        $polyline = $activity['map']['polyline'] ?? '';

        if (empty($polyline)) {
            $results[] = [
                'strava_id' => $activityId,
                'status' => 'error',
                'reason' => 'no polyline'
            ];
            continue;
        }

        // --- Calculate activity_date ---
        $activityDate = null;
        if (!empty($activity['start_date_local'])) {
            $activityDate = date('Y-m-d', strtotime($activity['start_date_local']));
        }

        // ✅ UPLOAD VIA GPXUploader with automatic surface detection
        $uploadResult = $gpxUploader->uploadFromPolyline(
            $polyline,
            $activity,
            [
                'name' => $activity['name'] ?? 'Strava Activity ' . $activityId,
                'source' => 'strava',
                'route_purpose' => 'activity',
                'visibility' => 'private',
                'external_id' => $activityId,
                'activity_date' => $activityDate,
                'check_duplicates' => true
            ]
        );

        $routeId = $uploadResult['route_id'];

        log_debug("Strava activity $activityId uploaded as route $routeId", 'info');

        // --- Calculate timestamps ---
        $startedAt = null;
        $finishedAt = null;

        if (!empty($activity['start_date'])) {
            $startedAt = date('Y-m-d H:i:s', strtotime($activity['start_date']));

            if (!empty($activity['elapsed_time'])) {
                $finishedAt = date(
                    'Y-m-d H:i:s',
                    strtotime($activity['start_date']) + (int) $activity['elapsed_time']
                );
            }
        }

        // ✅ SAVE ACTIVITY METADATA (HR, power, speeds, etc.)
        $metaData = [
            'external_source' => 'strava',
            'external_id' => $activityId,
            'external_url' => 'https://www.strava.com/activities/' . $activityId,
            'activity_started_at' => $startedAt,
            'activity_finished_at' => $finishedAt,
            'moving_time_min' => !empty($activity['moving_time']) ? (int) ($activity['moving_time'] / 60) : null,
            'elapsed_time_sec' => !empty($activity['elapsed_time']) ? (int) $activity['elapsed_time'] : null,
            'avg_speed_kmh' => !empty($activity['average_speed']) ? round($activity['average_speed'] * 3.6, 2) : null,
            'max_speed_kmh' => !empty($activity['max_speed']) ? round($activity['max_speed'] * 3.6, 2) : null,
            'avg_heart_rate' => isset($activity['average_heartrate']) ? (int) $activity['average_heartrate'] : null,
            'max_heart_rate' => isset($activity['max_heartrate']) ? (int) $activity['max_heartrate'] : null,
            'avg_cadence' => isset($activity['average_cadence']) ? (int) $activity['average_cadence'] : null,
            'avg_power' => isset($activity['average_watts']) ? (int) $activity['average_watts'] : null,
            'avg_temperature' => isset($activity['average_temp']) ? (float) $activity['average_temp'] : null,
        ];

        $routeModel->updateRouteMeta($routeId, $metaData);

        log_debug("Activity metadata saved for route $routeId", 'info');

        $imported++;
        $results[] = [
            'strava_id' => $activityId,
            'status' => 'imported',
            'route_id' => $routeId,
            'name' => $activity['name'] ?? 'Strava Activity',
            'distance_km' => $uploadResult['data']['distance_km'],
        ];
    } catch (Exception $e) {
        log_debug("Strava import failed for activity $activityId: " . $e->getMessage(), 'error');
        $results[] = [
            'strava_id' => $activityId,
            'status' => 'error',
            'reason' => $e->getMessage()
        ];
    }
}

sendJSON(true, [
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => count($activityIds) - $imported - $skipped,
    'results' => $results,
]);