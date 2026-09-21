<?php
/* ============================================================================
   GET /api/auth/strava/auth.php
   Redirect usera do Strava OAuth authorize page
   Wymaga: zalogowanego usera (JWT)
   ============================================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../../includes/classes/providers/StravaProvider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

// Tylko verified user może linkować
$userData = requireAuth();

$db = getDB();
$userModel = new UserModel($db);
$user = $userModel->getUserById($userData['user_id']);

if (!$user || $user['status'] !== 'verified') {
    sendError('Account must be verified to link integrations', 403);
}

// Sprawdź czy już linked
$manager = new IntegrationModel($db);
$status = $manager->getProviderStatus($userData['user_id'], 'strava');

if ($status['linked'] && !$status['expired']) {
    sendError('Strava already linked', 400);
}
include_once '/';
// Redirect do Strava
$provider = new StravaProvider();
$authorizeUrl = $provider->getAuthorizeUrl();

header("Location: $authorizeUrl");
exit;