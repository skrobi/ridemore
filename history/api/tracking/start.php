<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

/**
 * Start GPS Tracking Session
 * Creates batch_id and returns OwnTracks configuration
 */

// ================= AUTH =================
$userData = requireAuth();
$userId = $userData['user_id'];

// ================= INPUT =================
$input = json_decode(file_get_contents('php://input'), true);
$raceId = (int)($input['race_id'] ?? 0);

if ($raceId < 0) {
    sendError('invalid race_id', 400);
}

// ================= CHECK FOR EXISTING ACTIVE SESSION =================
$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT batch_id, started_at
    FROM tracking_sessions 
    WHERE user_id = ? AND race_id = ? AND is_active = 1
    LIMIT 1
");
$stmt->execute([$userId, $raceId]);
$existingSession = $stmt->fetch(PDO::FETCH_ASSOC);

// Jeśli już ma aktywną sesję dla tego challenge - zwróć istniejącą
if ($existingSession) {
    $batchId = $existingSession['batch_id'];
    
    // Generuj nowy token dla istniejącej sesji
    $jwtPayload = [
        'user_id' => $userId,
        'batch_id' => $batchId,
        'race_id' => $raceId,
        'exp' => time() + (7 * 24 * 3600)
    ];
    $sessionToken = generateJWT($jwtPayload);
    
    $baseUrl = rtrim(get_base_url(), '/');
    
    $owntracksConfig = [
        '_type' => 'configuration',
        'mode' => 3,
        'url' => $baseUrl . '/track',
        'auth' => true,
        'username' => '',
        'password' => '',
        'headers' => [
            'Authorization' => 'Bearer ' . $sessionToken,
            'X-Batch-ID' => $batchId,
            'X-Race-ID' => (string)$raceId
        ],
        'monitoring' => 2,
        'locatorInterval' => 30,
        'locatorDisplacement' => 50,
        'extendedData' => true,
        'deviceId' => 'user' . $userId,
        'tid' => substr(md5((string)$userId), 0, 2)
    ];
    
    $configJson = json_encode($owntracksConfig);
    $configBase64 = base64_encode($configJson);
    $deepLink = 'owntracks:///config?inline=' . $configBase64;
    $qrCodeUrl = $baseUrl . '/api/qr.php?data=' . urlencode($deepLink);
    
    sendJSON(true, [
        'batch_id' => $batchId,
        'session_token' => $sessionToken,
        'owntracks_config' => $owntracksConfig,
        'deep_link' => $deepLink,
        'qr_code_url' => $qrCodeUrl,
        'expires_at' => date('Y-m-d H:i:s', $jwtPayload['exp']),
        'existing_session' => true, // ✅ Informacja że to istniejąca sesja
        'started_at' => $existingSession['started_at']
    ]);
}

// ================= GENERATE BATCH (jeśli nie ma aktywnej sesji) =================
$batchId = 'batch-' . uniqid() . '-' . time();

// ================= CREATE SESSION =================
$stmt = $pdo->prepare("
    INSERT INTO tracking_sessions 
    (batch_id, user_id, race_id, started_at, is_active)
    VALUES (?, ?, ?, NOW(), 1)
");
$stmt->execute([$batchId, $userId, $raceId]);

// ================= GENERATE JWT FOR THIS SESSION =================
$jwtPayload = [
    'user_id' => $userId,
    'batch_id' => $batchId,
    'race_id' => $raceId,
    'exp' => time() + (7 * 24 * 3600)
];
$sessionToken = generateJWT($jwtPayload);

// ================= OWNTRACKS CONFIG =================
// ================= OWNTRACKS CONFIG =================
$baseUrl = rtrim(get_base_url(), '/');

$owntracksConfig = [
    '_type' => 'configuration',
    'mode' => 3, // HTTP
    'url' => $baseUrl . '/track',
    'auth' => true, // ✅ WŁĄCZ AUTH
    'username' => (string)$userId, // ✅ user_id jako username
    'password' => $batchId, // ✅ batch_id jako password
    'headers' => [
        'X-Race-ID' => (string)$raceId
    ],
    'monitoring' => 2,
    'locatorInterval' => 30,
    'locatorDisplacement' => 50,
    'extendedData' => true,
    'deviceId' => 'user' . $userId,
    'tid' => substr(md5((string)$userId), 0, 2)
];

// ================= DEEP LINK & QR =================
$configJson = json_encode($owntracksConfig);
$configBase64 = base64_encode($configJson);
$deepLink = 'owntracks:///config?inline=' . $configBase64;
$qrCodeUrl = $baseUrl . '/api/qr.php?data=' . urlencode($deepLink);

// ================= RESPONSE =================
sendJSON(true, [
    'batch_id' => $batchId,
    'session_token' => $sessionToken,
    'owntracks_config' => $owntracksConfig,
    'deep_link' => $deepLink,
    'qr_code_url' => $qrCodeUrl,
    'expires_at' => date('Y-m-d H:i:s', $jwtPayload['exp']),
    'existing_session' => false // ✅ Nowa sesja
]);