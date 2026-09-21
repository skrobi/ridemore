<?php
/* ============================================================================
   GET /api/auth/integration_status.php
   Zwraca status WSZYSTKICH integracji dla zalogowanego usera
   Wymaga: JWT
   ============================================================================ */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

$userData = requireAuth();

$db = getDB();
$manager = new IntegrationModel($db);

$statuses = $manager->getAllStatuses($userData['user_id']);

sendJSON(true, $statuses);