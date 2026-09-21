<?php
/* ============================================================================
   Dynamic MVT Tile Server with on-disk caching
   
   Features:
   - Generates tiles on-demand from database
   - Caches generated tiles on disk (24h expiry)
   - Automatically invalidates cache when routes change
   ============================================================================ */

require_once __DIR__ . '/../config.php';

// Parse request
$layer = $_GET['layer'] ?? null;  // official_routes, user_routes, planned_routes
$z = (int)($_GET['z'] ?? 0);
$x = (int)($_GET['x'] ?? 0);
$y = (int)($_GET['y'] ?? 0);

// Validate
if (!$layer || !in_array($layer, ['official_routes', 'user_routes', 'planned_routes'])) {
    http_response_code(400);
    exit('Invalid layer');
}

if ($z < 6 || $z > 14 || $x < 0 || $y < 0) {
    http_response_code(400);
    exit('Invalid tile coordinates');
}

try {
    $db = getDB();
    
    // ✅ Check cache first
    $cacheDir = base_path("cache/tiles/$layer/$z/$x");
    $cachePath = "$cacheDir/$y.pbf";
    
    if (file_exists($cachePath)) {
        $cacheAge = time() - filemtime($cachePath);
        
        // Cache valid for 24h (or until routes_updated)
        $lastUpdate = getLastRouteUpdate($db, $layer);
        
        if ($cacheAge < 86400 && filemtime($cachePath) > strtotime($lastUpdate)) {
            // Serve from cache
            header('Content-Type: application/x-protobuf');
            header('Content-Encoding: gzip');
            header('Cache-Control: public, max-age=86400');
            header('X-Tile-Cache: HIT');
            readfile($cachePath);
            exit;
        }
    }
    
    // ✅ Generate tile from database
    $bbox = tileToBbox($z, $x, $y);
    $mvt = generateTile($db, $layer, $bbox, $z, $x, $y);
    
    if ($mvt) {
        // Save to cache
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $compressed = gzencode($mvt);
        file_put_contents($cachePath, $compressed);
        
        // Serve
        header('Content-Type: application/x-protobuf');
        header('Content-Encoding: gzip');
        header('Cache-Control: public, max-age=86400');
        header('X-Tile-Cache: MISS');
        echo $compressed;
    } else {
        // Empty tile
        header('Content-Type: application/x-protobuf');
        header('X-Tile-Cache: EMPTY');
        echo '';
    }
    
} catch (Exception $e) {
    log_debug('Tile error: ' . $e->getMessage(), 'error');
    http_response_code(500);
    exit('Tile generation failed');
}

/**
 * Generate MVT tile from database
 */
function generateTile($db, $layer, $bbox, $z, $x, $y) {
    // Map layer to SQL filter
    $filters = [
        'official_routes' => "r.route_purpose = 'reference' AND r.visibility = 'public'",
        'user_routes' => "r.route_purpose = 'user_shared' AND r.visibility = 'public'",
        'planned_routes' => "r.route_purpose = 'planned' AND r.visibility = 'public'"
    ];
    
    $whereClause = $filters[$layer];
    $bboxWkt = bboxToPolygon($bbox);
    
    // ✅ MVT query
    $sql = "
        SELECT ST_AsMVT(tile, :layer_name, 4096, 'geom') as mvt
        FROM (
            SELECT 
                r.route_id,
                r.name,
                r.source,
                r.route_purpose,
                ROUND(r.distance_km, 1) as distance_km,
                r.ascent_m,
                rm.difficulty_level,
                ROUND(rm.rating, 1) as rating,
                ST_AsMVTGeom(
                    r.geometry,
                    ST_GeomFromText(:bbox_wkt, 4326),
                    4096,
                    256,
                    true
                ) AS geom
            FROM routes r
            LEFT JOIN routes_meta rm ON r.route_id = rm.route_id
            WHERE ST_Intersects(r.geometry, ST_GeomFromText(:bbox_wkt, 4326))
            AND $whereClause
            LIMIT 100
        ) AS tile
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':layer_name' => $layer,
        ':bbox_wkt' => $bboxWkt
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['mvt'] ?? null;
}

/**
 * Get last route update timestamp for cache invalidation
 */
function getLastRouteUpdate($db, $layer) {
    $filters = [
        'official_routes' => "route_purpose = 'reference'",
        'user_routes' => "route_purpose = 'user_shared'",
        'planned_routes' => "route_purpose = 'planned'"
    ];
    
    $sql = "SELECT MAX(updated_at) as last_update FROM routes WHERE {$filters[$layer]}";
    $stmt = $db->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['last_update'] ?? '1970-01-01';
}

/**
 * Convert tile coordinates to bbox
 */
function tileToBbox($z, $x, $y) {
    $n = pow(2, $z);
    $lon_min = $x / $n * 360.0 - 180.0;
    $lat_min = rad2deg(atan(sinh(pi() * (1 - 2 * ($y + 1) / $n))));
    $lon_max = ($x + 1) / $n * 360.0 - 180.0;
    $lat_max = rad2deg(atan(sinh(pi() * (1 - 2 * $y / $n))));
    
    return [$lon_min, $lat_min, $lon_max, $lat_max];
}

function bboxToPolygon($bbox) {
    return sprintf(
        'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
        $bbox[0], $bbox[1],
        $bbox[2], $bbox[1],
        $bbox[2], $bbox[3],
        $bbox[0], $bbox[3],
        $bbox[0], $bbox[1]
    );
}