<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

/**
 * OwnTracks GPS Tracking Endpoint
 * - Aktywna sesja → zapisz z race_id
 * - Nieaktywna sesja → zapisz jako loose track (race_id=0)
 * - Nieznany batch_id → utwórz nową loose session
 */

// ================= PARSE BASIC AUTH =================
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
    ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');

if (!$authHeader || !preg_match('/Basic\s+(.*)$/i', $authHeader, $matches)) {
    header('WWW-Authenticate: Basic realm="OwnTracks"');
    sendError('missing basic auth', 401);
}

$credentials = base64_decode($matches[1]);
list($username, $password) = explode(':', $credentials, 2);

$providedUserId = (int)$username;
$providedBatchId = $password;

if (!$providedUserId || !$providedBatchId) {
    sendError('invalid credentials format', 401);
}

// ================= SPRAWDŹ SESJĘ =================
$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT user_id, race_id, is_active 
    FROM tracking_sessions 
    WHERE batch_id = ? AND user_id = ?
");
$stmt->execute([$providedBatchId, $providedUserId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

// ================= OKREŚL TRYB =================
if (!$session) {
    $userId = $providedUserId;
    $raceId = 0;
    $batchId = 'loose-' . $providedBatchId;
    $mode = 'loose';
    
} elseif (!$session['is_active']) {
    $userId = (int)$session['user_id'];
    $raceId = 0;
    $batchId = 'loose-' . $providedBatchId;
    $mode = 'loose';
    
} else {
    // ✅ Sesja aktywna → normalny tracking
    $userId = (int)$session['user_id'];
    $raceId = (int)$session['race_id'];
    $batchId = $providedBatchId;
    $mode = 'active';
}

// ================= PARSE OWNTRACKS JSON =================
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['_type'])) {
    sendError('invalid owntracks payload', 400);
}

if ($data['_type'] !== 'location') {
    sendJSON(true, ['message' => 'ignored non-location event']);
}

// ================= EXTRACT DATA =================
$lat = (float)($data['lat'] ?? 0);
$lon = (float)($data['lon'] ?? 0);
$timestamp = $data['tst'] ?? null;
$accuracy = isset($data['acc']) ? (float)$data['acc'] : null;
$elevation = isset($data['alt']) ? (float)$data['alt'] : null;
$velocity = isset($data['vel']) ? (float)$data['vel'] : null;

// Convert velocity from m/s to km/h
$speedKmh = $velocity !== null ? ($velocity * 3.6) : null;

// Convert unix timestamp to MySQL datetime(3) - milisekundy!
if (!$timestamp) {
    sendError('missing timestamp (tst)', 400);
}

// ✅ Format: YYYY-MM-DD HH:MM:SS.mmm (3 cyfry milisekund)
$recordedAt = gmdate('Y-m-d H:i:s', $timestamp) . '.' . str_pad((string)(($timestamp * 1000) % 1000), 3, '0', STR_PAD_LEFT);

// ================= VALIDATE =================
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    sendError('invalid coordinates', 400);
}

// ================= SAVE POINT =================
$pointUid = uniqid('ot-', true);

$isValid = 1;
$rejectReason = null;

// Optional: reject poor accuracy
if (defined('MAX_ACCURACY_M') && $accuracy !== null && $accuracy > MAX_ACCURACY_M) {
    $isValid = 0;
    $rejectReason = 'poor_accuracy';
}

// ✅ Dopasowane do Twojej tabeli
$stmt = $pdo->prepare("
    INSERT INTO live_tracking_points
    (race_id, user_id, batch_id, point_uid, recorded_at, 
     lat, lon, elevation, accuracy_m, speed_kmh, 
     is_valid, reject_reason)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    $raceId,
    $userId,
    $batchId,
    $pointUid,
    $recordedAt,
    $lat,
    $lon,
    $elevation,
    $accuracy,
    $speedKmh,
    $isValid,
    $rejectReason
]);

// ================= RESPONSE =================
sendJSON(true, [
    'point_saved' => true,
    'user_id' => $userId,
    'batch_id' => $batchId,
    'race_id' => $raceId,
    'mode' => $mode,
    'is_valid' => (bool)$isValid,
    'point_id' => (int)$pdo->lastInsertId()
]);