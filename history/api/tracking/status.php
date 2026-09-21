<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

/**
 * Check tracking session status + regenerate config
 */

// ================= AUTH =================
$userData = requireAuth();
$userId = $userData['user_id'];

// ================= INPUT =================
$input = json_decode(file_get_contents('php://input'), true);
$batchId = $input['batch_id'] ?? null;

if (!$batchId) {
    sendError('missing batch_id', 400);
}

// ================= CHECK SESSION =================
$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT 
        ts.batch_id,
        ts.race_id,
        ts.is_active,
        ts.started_at,
        ts.stopped_at,
        c.name as challenge_name,
        COUNT(ltp.id) as points_count,
        MAX(ltp.recorded_at) as last_point_at
    FROM tracking_sessions ts
    LEFT JOIN challenges c ON ts.race_id = c.challenge_id
    LEFT JOIN live_tracking_points ltp ON ts.batch_id = ltp.batch_id AND ltp.is_valid = 1
    WHERE ts.batch_id = ? AND ts.user_id = ?
    GROUP BY ts.batch_id
");

$stmt->execute([$batchId, $userId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    sendError('session not found', 404);
}

// ================= REGENERATE CONFIG (jeśli aktywna) =================
$deepLink = null;
$configJson = null;
$qrCodeUrl = null;

if ($session['is_active']) {
    $baseUrl = rtrim(get_base_url(), '/');
    
    $owntracksConfig = [
        '_type' => 'configuration',
        'mode' => 3,
        'url' => $baseUrl . '/track',
        'auth' => true,
        'username' => (string)$userId,
        'password' => $batchId,
        'headers' => [
            'X-Race-ID' => (string)$session['race_id']
        ],
        'monitoring' => 2,
        'locatorInterval' => 30,
        'locatorDisplacement' => 50,
        'extendedData' => true,
        'deviceId' => 'user' . $userId,
        'tid' => substr(md5((string)$userId), 0, 2)
    ];
    
    $configJson = json_encode($owntracksConfig, JSON_PRETTY_PRINT);
    $configBase64 = base64_encode(json_encode($owntracksConfig));
    $deepLink = 'owntracks:///config?inline=' . $configBase64;
    $qrCodeUrl = $baseUrl . '/api/qr.php?data=' . urlencode($deepLink);
}

// ================= RESPONSE =================
sendJSON(true, [
    'batch_id' => $session['batch_id'],
    'race_id' => (int)$session['race_id'],
    'is_active' => (bool)$session['is_active'],
    'started_at' => $session['started_at'],
    'stopped_at' => $session['stopped_at'],
    'challenge_name' => $session['challenge_name'],
    'points_count' => (int)$session['points_count'],
    'last_point_at' => $session['last_point_at'],
    
    // ✅ DODAJ CONFIG (tylko dla aktywnych)
    'deep_link' => $deepLink,
    'config_json' => $configJson,
    'qr_code_url' => $qrCodeUrl
]);