<?php
/* ============================================================================
   PLIK: api/manage/groups.php
   API - CRUD dla grup wyzwań
   DELETE endpoint
   ============================================================================ */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../manage/auth.php';

header('Content-Type: application/json');

$user = requireManageAccess();

// Tylko admin może usuwać grupy
if (!isAdmin()) {
    sendError('Tylko administrator może zarządzać grupami', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $groupId = (int)($_GET['id'] ?? 0);
    
    if (!$groupId) {
        sendError('Brak ID grupy');
    }
    
    try {
        $db = getDB();
        
        // Sprawdź czy grupa ma wyzwania
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM challenges 
            WHERE group_id = ? AND active = 1
        ");
        $stmt->execute([$groupId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            sendError('Nie można usunąć grupy - zawiera aktywne wyzwania');
        }
        
        // Usuń grupę
        $stmt = $db->prepare("DELETE FROM challenge_groups WHERE group_id = ?");
        $stmt->execute([$groupId]);
        
        if ($stmt->rowCount() === 0) {
            sendError('Grupa nie istnieje', 404);
        }
        
        sendJSON(true, ['message' => 'Grupa została usunięta']);
        
    } catch (Exception $e) {
        error_log('Delete group error: ' . $e->getMessage());
        sendError('Błąd usuwania grupy', 500);
    }
}

sendError('Nieobsługiwana metoda', 405);