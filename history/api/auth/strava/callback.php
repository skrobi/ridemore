<?php
/* ============================================================================
   GET /api/auth/strava/callback.php
   Callback od Strava po OAuth authorize
   Wymiana authorization code na tokeny + zapis do users_extension
   ============================================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../../includes/classes/providers/StravaProvider.php';


// Strava redirectuje tutaj – to jest GET request od browser
// Nie ma JWT w tym request, więc identyfikujemy usera z cookie auth_token
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    redirectWithError('Method not allowed');
}

$appUrl = get_base_url();

// ============================================================
// 1. Sprawdź czy Strava zwrócił error (user odrzucił authorize)
// ============================================================
if (isset($_GET['error'])) {
    $error = $_GET['error'] ?? 'unknown';
    log_debug("StravaCallback: user denied auth. Error: $error", 'warning');
    redirectWithError('Authorization denied by user');
}

// ============================================================
// 2. Sprawdź presence of authorization code
// ============================================================
if (!isset($_GET['code']) || empty($_GET['code'])) {
    redirectWithError('Missing authorization code');
}

$code = $_GET['code'];

// ============================================================
// 3. Identyfikuj usera z cookie (JWT)
// ============================================================
$token = getBearerToken();

if (!$token) {
    redirectWithError('Session expired. Please log in again.');
}

$userData = verifyJWT($token);

if (!$userData || !isset($userData['user_id'])) {
    redirectWithError('Invalid session. Please log in again.');
}

$userId = $userData['user_id'];

// ============================================================
// 4. Wymień code na tokeny
// ============================================================
$provider = new StravaProvider();
$tokens = $provider->exchangeToken($code);

if (!$tokens) {
    log_debug("StravaCallback: exchangeToken failed for user $userId", 'error');
    redirectWithError('Failed to exchange authorization code');
}

// ============================================================
// 5. Zapisz tokeny do users_extension
// ============================================================
$db = getDB();
$manager = new IntegrationModel($db);

$saved = $manager->saveTokens(
    $userId,
    'strava',
    $tokens['access_token'],
    $tokens['refresh_token'],
    $tokens['expires_at'],
    $tokens['provider_user_id']
);

if (!$saved) {
    log_debug("StravaCallback: saveTokens failed for user $userId", 'error');
    redirectWithError('Failed to save integration');
}

// ============================================================
// 6. Success – redirect back to app
// ============================================================
log_debug("StravaCallback: SUCCESS - linked Strava athlete {$tokens['provider_user_id']} to user $userId", 'info');

header("Location: {$appUrl}/?strava_linked=1");
exit;


// ============================================================
// HELPER: Redirect z error message do aplikacji
// ============================================================
function redirectWithError(string $message): void {
    $appUrl = get_base_url();
    log_debug("StravaCallback redirect error: $message", 'warning');
    header("Location: {$appUrl}/?strava_error=" . urlencode($message));
    exit;
}