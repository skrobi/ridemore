<?php
/* ============================================================================
   DELETE /api/auth/integration_unlink.php?provider=strava
   Usuwa linking konkretnego providera
   Wymaga: JWT + query param 'provider'
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendError('Method not allowed', 405);
}

$userData = requireAuth();

// Sprawdź provider z query string
$provider = $_GET['provider'] ?? null;

if (!$provider) {
    sendError('Missing provider parameter');
}

$db = getDB();
$manager = new IntegrationModel($db);

if (!$manager->isValidProvider($provider)) {
    sendError("Invalid provider: $provider");
}

// Sprawdź czy w ogóle linked
$status = $manager->getProviderStatus($userData['user_id'], $provider);

if (!$status['linked']) {
    sendError("$provider is not linked", 404);
}

// Unlink
$result = $manager->unlink($userData['user_id'], $provider);

if (!$result) {
    sendError("Failed to unlink $provider", 500);
}

log_debug("Integration unlink: user {$userData['user_id']} unlinked $provider", 'info');

sendJSON(true, [
    'message' => "$provider unlinked successfully",
    'provider' => $provider,
]);