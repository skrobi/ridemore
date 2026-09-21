<?php
/**
 * OSRM Proxy - Routing endpoint with security, validation & error handling
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ============================================================================
// CONFIG
// ============================================================================
define('OSRM_SERVER', 'http://router.project-osrm.org'); // Zmień na własny serwer
define('MAX_DISTANCE_KM', 500);        // Max dystans single route
define('MAX_WAYPOINTS', 25);           // Max punkty w request
define('REQUEST_TIMEOUT', 15);         // Timeout w sekundach
define('RATE_LIMIT_PER_MINUTE', 60);   // Max requests per IP per minute

// ============================================================================
// RATE LIMITING
// ============================================================================
function checkRateLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheFile = sys_get_temp_dir() . '/osrm_rate_' . md5($ip);
    
    $requests = [];
    if (file_exists($cacheFile)) {
        $requests = json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    
    // Clean old requests (older than 1 minute)
    $now = time();
    $requests = array_filter($requests, fn($t) => $t > $now - 60);
    
    if (count($requests) >= RATE_LIMIT_PER_MINUTE) {
        return false;
    }
    
    $requests[] = $now;
    file_put_contents($cacheFile, json_encode($requests));
    return true;
}

// ============================================================================
// VALIDATION
// ============================================================================
function validateInput($waypoints, $profile) {
    // Profile whitelist
    $allowedProfiles = ['bike', 'car', 'foot'];
    if (!in_array($profile, $allowedProfiles)) {
        return ['error' => 'Invalid profile. Allowed: ' . implode(', ', $allowedProfiles)];
    }
    
    // Parse waypoints
    $points = explode(';', $waypoints);
    
    if (count($points) < 2) {
        return ['error' => 'Minimum 2 waypoints required'];
    }
    
    if (count($points) > MAX_WAYPOINTS) {
        return ['error' => 'Maximum ' . MAX_WAYPOINTS . ' waypoints allowed'];
    }
    
    // Validate coordinate format
    foreach ($points as $point) {
        $coords = explode(',', $point);
        if (count($coords) !== 2) {
            return ['error' => 'Invalid waypoint format: ' . $point];
        }
        
        $lng = floatval($coords[0]);
        $lat = floatval($coords[1]);
        
        // Validate bounds
        if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
            return ['error' => 'Coordinates out of bounds: ' . $point];
        }
    }
    
    // Rough distance check (haversine for first-last)
    $first = explode(',', $points[0]);
    $last = explode(',', $points[count($points) - 1]);
    $roughDist = haversineDistance(
        floatval($first[1]), floatval($first[0]),
        floatval($last[1]), floatval($last[0])
    );
    
    if ($roughDist > MAX_DISTANCE_KM) {
        return ['error' => 'Route too long. Maximum: ' . MAX_DISTANCE_KM . ' km (straight-line distance: ' . round($roughDist) . ' km)'];
    }
    
    return ['valid' => true];
}

function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

// ============================================================================
// MAIN
// ============================================================================
try {
    // Rate limit
    if (!checkRateLimit()) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded. Maximum ' . RATE_LIMIT_PER_MINUTE . ' requests per minute.'
        ]);
        exit;
    }
    
    // Get params
    $waypoints = $_GET['waypoints'] ?? null;
    $profile = $_GET['profile'] ?? 'bike';
    
    if (!$waypoints) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing waypoints parameter']);
        exit;
    }
    
    // Validate
    $validation = validateInput($waypoints, $profile);
    if (isset($validation['error'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $validation['error']]);
        exit;
    }
    
    // Build OSRM URL
    $osrmUrl = OSRM_SERVER . "/route/v1/$profile/$waypoints";
    $osrmUrl .= '?overview=full&geometries=geojson&steps=false&annotations=false';
    
    // Call OSRM
    $ch = curl_init($osrmUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'RideMore.Bike/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Handle CURL errors
    if ($curlError) {
        error_log("OSRM CURL error: $curlError");
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Routing service temporarily unavailable'
        ]);
        exit;
    }
    
    // Handle HTTP errors
    if ($httpCode !== 200) {
        error_log("OSRM HTTP error: $httpCode");
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Routing service returned error (HTTP ' . $httpCode . ')'
        ]);
        exit;
    }
    
    // Parse response
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['routes'][0])) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'No route found between these points'
        ]);
        exit;
    }
    
    $route = $data['routes'][0];
    
    // Additional validation
    if ($route['distance'] > MAX_DISTANCE_KM * 1000) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Calculated route exceeds maximum distance (' . MAX_DISTANCE_KM . ' km)'
        ]);
        exit;
    }
    
    // Success response
    echo json_encode([
        'success' => true,
        'data' => [
            'geometry' => $route['geometry'],
            'distance' => round($route['distance'], 1),
            'duration' => round($route['duration'], 1)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("OSRM Proxy exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}