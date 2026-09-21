<?php
/* ============================================================================
   PLIK: api/manage/search-routes.php
   API - wyszukiwanie tras do dodania do wyzwania
   ============================================================================ */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../manage/auth.php';

header('Content-Type: application/json');

$user = requireManageAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    sendError('Zapytanie musi mieć minimum 2 znaki');
}

try {
    $db = getDB();
    
    // Wyszukaj trasy
    
    $sql = "
        SELECT 
            r.route_id,
            r.name,
            r.distance_km,
            r.ascent_m,
            rm.difficulty_level
        FROM routes r
        LEFT JOIN routes_meta rm ON r.route_id = rm.route_id
        WHERE r.name LIKE ?
        AND r.visibility IN ('public', 'curated')
        AND r.status = 'active'
        ORDER BY r.name
        LIMIT 20
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(['%' . $query . '%']);
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format wyników
    foreach ($routes as &$route) {
        $route['route_id'] = (int)$route['route_id'];
        $route['distance_km'] = (float)$route['distance_km'];
        $route['ascent_m'] = (int)$route['ascent_m'];
    }
    
    sendJSON(true, $routes);
    
} catch (Exception $e) {
    error_log('Search routes error: ' . $e->getMessage());
    sendError('Błąd wyszukiwania', 500);
}