<?php
require_once __DIR__ . '/../config.php';

// Parse tile coordinates
$z = (int)$_GET['z'];
$x = (int)$_GET['x'];
$y = (int)$_GET['y'];
$layer = $_GET['layer'] ?? 'official_routes'; // official_routes, user_routes, planned_routes

// Validate
if ($z < 0 || $z > 18 || $x < 0 || $y < 0) {
    http_response_code(400);
    exit('Invalid tile coordinates');
}

try {
    $db = getDB();
    
    // Calculate tile bbox
    $bbox = tileToBbox($z, $x, $y);
    $bboxWkt = bboxToPolygon($bbox);
    
    // Map layer slug to filter
    $layerFilters = [
        'official_routes' => "r.route_purpose = 'reference' AND r.visibility = 'public'",
        'user_routes' => "r.route_purpose = 'user_shared' AND r.visibility = 'public'",
        'planned_routes' => "r.route_purpose = 'planned' AND r.visibility = 'public'"
    ];
    
    if (!isset($layerFilters[$layer])) {
        http_response_code(400);
        exit('Invalid layer');
    }
    
    $whereClause = $layerFilters[$layer];
    
    // ✅ Query dla MVT - zwraca binary MVT tile
    $sql = "
        SELECT ST_AsMVT(tile, :layer_name, 4096, 'geom') as mvt
        FROM (
            SELECT 
                r.route_id,
                r.name,
                r.source,
                r.route_purpose,
                r.distance_km,
                r.ascent_m,
                r.avg_edge_score,
                ST_AsMVTGeom(
                    r.geometry,
                    ST_GeomFromText(:bbox_wkt, 4326),
                    4096,
                    256,
                    true
                ) AS geom
            FROM routes r
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
    
    if ($result && $result['mvt']) {
        header('Content-Type: application/x-protobuf');
        header('Content-Encoding: gzip');
        header('Cache-Control: public, max-age=86400'); // 24h cache
        echo gzencode($result['mvt']);
    } else {
        // Empty tile
        header('Content-Type: application/x-protobuf');
        echo '';
    }
    
} catch (Exception $e) {
    log_debug('Tile generation error: ' . $e->getMessage(), 'error');
    http_response_code(500);
    exit('Tile generation failed');
}

function tileToBbox($z, $x, $y) {
    $n = pow(2, $z);
    $lon_min = $x / $n * 360.0 - 180.0;
    $lat_min = rad2deg(atan(sinh(pi() * (1 - 2 * ($y + 1) / $n))));
    $lon_max = ($x + 1) / $n * 360.0 - 180.0;
    $lat_max = rad2deg(atan(sinh(pi() * (1 - 2 * $y / $n))));
    
    return [$lon_min, $lat_min, $lon_max, $lat_max];
}