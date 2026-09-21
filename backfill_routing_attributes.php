<?php
// backfill_routing_attributes.php — przygotowuje metadane OSM poza żądaniem planera.
putenv('APP_ENV=prod');
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/core/bootstrap.php';

use Core\Database;
use Models\GpxGeometry;
use Utils\RoadAttributeCache;
use Utils\RoadAttributeDetector;
use Utils\TileGrid;

$force = in_array('--force', $argv, true);
$limit = 5;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $limit = max(1, (int) $m[1]); }
}

$hashes = Database::connection()->query(
    'SELECT gpx_hash FROM gpx_geometry WHERE point_count > 1 ORDER BY gpx_hash'
)->fetchAll(\PDO::FETCH_COLUMN);
$done = 0;
foreach ($hashes as $hash) {
    if (!$force && RoadAttributeCache::has((string) $hash)) { continue; }
    $geometry = GpxGeometry::load([(string) $hash])[(string) $hash] ?? null;
    if ($geometry === null) { continue; }
    $points = [];
    for ($i = 0; $i + 1 < count($geometry['pts']); $i += 2) {
        $points[] = TileGrid::toLatLon((int) $geometry['pts'][$i], (int) $geometry['pts'][$i + 1]);
    }
    $attributes = RoadAttributeDetector::analyze($points);
    if ($attributes !== null && RoadAttributeCache::put((string) $hash, $attributes)) {
        echo $hash . ' coverage=' . round(100 * $attributes['coverage']) . "%\n";
    } else {
        echo $hash . " brak wyniku — spróbuj ponownie później\n";
    }
    $done++;
    if ($done >= $limit) { break; }
}
echo "Przetworzono: $done\n";
