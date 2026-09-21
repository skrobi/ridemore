<?php

/* ============================================================================
  API: Recommend - Universal recommendation endpoint
  Version: 1.0.0
  ============================================================================ */

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display in output
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/api_errors.log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../includes/classes/RecommendationEngine.php';

// Ensure JSON output even on fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    $db = getDB();

    // Initialize engine
    try {
        $engine = new RecommendationEngine($db);
    } catch (Exception $e) {
        log_debug("❌ RecommendationEngine init failed: " . $e->getMessage(), 'error');

        // Return JSON error instead of throwing
        echo json_encode([
            'success' => false,
            'error' => 'Configuration error: ' . $e->getMessage(),
            'hint' => 'Check if /api/config/recommendation_recipes.json exists'
        ]);
        exit;
    }

    $recipeSlug = $_GET['recipe'] ?? null;

    // List available recipes if no recipe specified
    if (!$recipeSlug) {
        sendJSON(true, [
            'available_recipes' => $engine->getAvailableRecipes(),
            'endpoint' => '/api/routes/recommend.php',
            'usage' => 'GET /api/routes/recommend.php?recipe=RECIPE_SLUG&bbox=minLng,minLat,maxLng,maxLat'
        ]);
        exit;
    }

    // Build params
    $params = [];

    // Bbox
    if (isset($_GET['bbox'])) {
        try {
            $bbox = parseBbox($_GET['bbox']);
            $params['bbox'] = bboxToPolygon($bbox);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid bbox format: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    if (isset($_GET['layers'])) {
        $layerSlugs = explode(',', $_GET['layers']);
        $layerSlugs = array_filter(array_map('trim', $layerSlugs));

        if (!empty($layerSlugs)) {
            $params['active_layers'] = $layerSlugs;
            log_debug("🏷️ Active layers for recommendation: " . implode(', ', $layerSlugs), 'info');
        }
    }

    // User context (optional)
    if (isset($_GET['user_local_id'])) {
        try {
            $user = getOrCreateUser($_GET['user_local_id']);
            $params['user_id'] = $user['user_id'];
        } catch (Exception $e) {
            log_debug("⚠️ User context failed: " . $e->getMessage(), 'warning');
            // Continue without user context
        }
    }

    // Execute recommendation
    $results = $engine->recommend($recipeSlug, $params);

    sendJSON(true, [
        'recipe' => $recipeSlug,
        'results' => $results,
        'count' => count($results)
    ]);
} catch (Exception $e) {
    log_debug("❌ Recommendation error: " . $e->getMessage(), 'error');

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => DEBUG_MODE ? $e->getTraceAsString() : null
    ]);
    exit;
}