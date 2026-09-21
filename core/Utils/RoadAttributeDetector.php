<?php
// core/Utils/RoadAttributeDetector.php
namespace Utils;

/**
 * Dopasowuje przebieg Ridemore do najbliższych odcinków OSM i zachowuje
 * prawdziwe tagi `surface`/`highway`. To wzbogacenie metadanych, nie router:
 * nie buduje grafu, nie wyznacza połączeń i nie działa podczas planowania.
 */
final class RoadAttributeDetector
{
    private const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';
    private const MATCH_M = 35.0;
    private const GRID_M = 75.0;
    private const MAX_POINTS = 350;
    private const MAX_WAYS = 6000;
    private const MAX_BBOX_DEGREES = 0.75;
    private const MAX_HEADING_DEGREES = 75.0;
    private const CHUNK_PAUSE_US = 500000;

    /** @param list<array{0:float,1:float}|array{lat:float,lon:float}> $points */
    public static function analyze(array $points): ?array
    {
        $points = self::normalizePoints($points);
        if (count($points) < 2) {
            return null;
        }
        $totalM = self::lineLengthM($points);
        if ($totalM <= 0.0) {
            return null;
        }
        $surface = [];
        $roads = [];
        $segments = [];
        $matchedM = 0.0;
        $offsetM = 0.0;
        foreach (self::analysisChunks($points) as $chunkIndex => $chunk) {
            if ($chunkIndex > 0) {
                usleep(self::CHUNK_PAUSE_US);
            }
            $chunkM = self::lineLengthM($chunk);
            $ways = self::fetchWays($chunk);
            $details = $ways ? self::matchDetails($chunk, $ways) : null;
            if ($details !== null) {
                foreach ($details['surfaceM'] as $key => $amount) {
                    $surface[$key] = ($surface[$key] ?? 0.0) + $amount;
                }
                foreach ($details['roadM'] as $key => $amount) {
                    $roads[$key] = ($roads[$key] ?? 0.0) + $amount;
                }
                $matchedM += $details['matchedM'];
                foreach ($details['segments'] as $segment) {
                    $segments[] = [
                        'from' => ($offsetM + $segment['fromM']) / $totalM,
                        'to' => ($offsetM + $segment['toM']) / $totalM,
                        'surface' => $segment['surface'],
                        'roadClass' => $segment['roadClass'],
                    ];
                }
            }
            $offsetM += $chunkM;
        }
        if ($matchedM <= 0.0) {
            return null;
        }
        return [
            'surface' => self::percentages($surface, $totalM),
            'roadClass' => self::percentages($roads, $totalM),
            'coverage' => min(1.0, $matchedM / $totalM),
            'segments' => self::mergeSegments($segments),
        ];
    }

    /** Czysta część używana także przez testy bez sieci. */
    public static function match(array $points, array $ways): ?array
    {
        $points = self::normalizePoints($points);
        $details = self::matchDetails($points, $ways);
        if ($details === null) {
            return null;
        }
        return [
            'surface' => self::percentages($details['surfaceM'], $details['totalM']),
            'roadClass' => self::percentages($details['roadM'], $details['totalM']),
            'coverage' => min(1.0, $details['matchedM'] / $details['totalM']),
            'segments' => self::mergeSegments(array_map(
                static fn(array $segment): array => [
                    'from' => $segment['fromM'] / $details['totalM'],
                    'to' => $segment['toM'] / $details['totalM'],
                    'surface' => $segment['surface'],
                    'roadClass' => $segment['roadClass'],
                ],
                $details['segments']
            )),
        ];
    }

    private static function matchDetails(array $points, array $ways): ?array
    {
        if (count($points) < 2 || !$ways) {
            return null;
        }
        $surface = [];
        $roads = [];
        $segments = [];
        $matchedM = 0.0;
        $totalM = 0.0;
        $lat0 = array_sum(array_column($points, 0)) / count($points);
        $index = self::segmentIndex(array_slice($ways, 0, self::MAX_WAYS), $lat0);
        $previousWay = null;
        for ($i = 1; $i < count($points); $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $len = RouteSnap::haversineM($a[0], $a[1], $b[0], $b[1]);
            if ($len <= 0.0) {
                continue;
            }
            $fromM = $totalM;
            $totalM += $len;
            $mid = [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
            $best = null;
            $bestScore = INF;
            [$gx, $gy] = self::gridCell($mid, $lat0);
            foreach ($index[$gx . ':' . $gy] ?? [] as $segment) {
                $distance = self::pointSegmentM($mid, $segment[0], $segment[1]);
                if ($distance >= self::MATCH_M) {
                    continue;
                }
                $heading = self::headingDelta($a, $b, $segment[0], $segment[1], $lat0);
                if ($heading > self::MAX_HEADING_DEGREES) {
                    continue;
                }
                $score = $distance + 20.0 * ($heading / self::MAX_HEADING_DEGREES) ** 2;
                if ($previousWay !== null && $segment[3] === $previousWay) {
                    $score -= 4.0;
                }
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = $segment;
                }
            }
            if ($best === null) {
                $previousWay = null;
                continue;
            }
            $previousWay = $best[3];
            $surfaceKey = self::surface((string) ($best[2]['surface'] ?? ''));
            $roadKey = self::roadClass((string) ($best[2]['highway'] ?? ''));
            $matchedM += $len;
            $surface[$surfaceKey] = ($surface[$surfaceKey] ?? 0.0) + $len;
            $roads[$roadKey] = ($roads[$roadKey] ?? 0.0) + $len;
            $segments[] = [
                'fromM' => $fromM, 'toM' => $totalM,
                'surface' => $surfaceKey, 'roadClass' => $roadKey,
            ];
        }
        return $totalM > 0.0 && $matchedM > 0.0 ? [
            'surfaceM' => $surface, 'roadM' => $roads, 'segments' => $segments,
            'matchedM' => $matchedM, 'totalM' => $totalM,
        ] : null;
    }

    private static function fetchWays(array $points): array
    {
        $lats = array_column($points, 0);
        $lons = array_column($points, 1);
        // analyze() dzieli długą trasę; ten guard odrzuca tylko pojedynczy
        // uszkodzony kawałek z odległym punktem.
        if (max($lats) - min($lats) > self::MAX_BBOX_DEGREES
            || max($lons) - min($lons) > self::MAX_BBOX_DEGREES) {
            return [];
        }
        $pad = 0.001;
        $bbox = implode(',', [min($lats) - $pad, min($lons) - $pad, max($lats) + $pad, max($lons) + $pad]);
        $query = '[out:json][timeout:25];way["highway"](' . $bbox . ');out tags geom;';
        $ch = curl_init(self::OVERPASS_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_USERAGENT => 'RidemoreBike/1.0 routing-attribute-backfill',
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status < 200 || $status >= 300 || strlen($raw) > 25_000_000) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data['elements'] ?? null)
            ? array_values(array_filter($data['elements'], static fn($e): bool => is_array($e) && ($e['type'] ?? null) === 'way'))
            : [];
    }

    private static function normalizePoints(array $points): array
    {
        $out = [];
        foreach ($points as $point) {
            if (!is_array($point)) { continue; }
            $lat = $point['lat'] ?? $point[0] ?? null;
            $lon = $point['lon'] ?? $point['lng'] ?? $point[1] ?? null;
            if (is_numeric($lat) && is_numeric($lon)) {
                $out[] = [(float) $lat, (float) $lon];
            }
        }
        return $out;
    }

    private static function surface(string $value): string
    {
        $value = strtolower(trim(explode(';', $value)[0]));
        return in_array($value, RoutingPreferences::SURFACES, true) ? $value : 'unknown';
    }

    private static function roadClass(string $value): string
    {
        $value = strtolower(trim(explode(';', $value)[0]));
        return in_array($value, RoutingPreferences::ROAD_CLASSES, true) ? $value : 'unknown';
    }

    private static function percentages(array $amounts, float $total): array
    {
        $out = [];
        foreach ($amounts as $key => $amount) {
            $out[$key] = round(100.0 * $amount / $total, 2);
        }
        return $out;
    }

    /**
     * Indeks segmentów OSM w siatce metrycznej. Bez niego 350 próbek × tysiące
     * ways dawały koszt kwadratowy i backfill dużego miasta był niepraktyczny.
     * Segment trafia do każdej komórki przecinanej przez bbox poszerzony o
     * promień dopasowania; próbka czyta więc tylko jedną lokalną listę.
     *
     * @return array<string,list<array{0:array,1:array,2:array,3:string|int}>>
     */
    private static function segmentIndex(array $ways, float $lat0): array
    {
        $index = [];
        $kx = 111320.0 * cos(deg2rad($lat0));
        $ky = 111320.0;
        foreach ($ways as $wayIndex => $way) {
            $geometry = self::normalizePoints($way['geometry'] ?? []);
            $tags = is_array($way['tags'] ?? null) ? $way['tags'] : [];
            $wayKey = is_scalar($way['id'] ?? null) ? (string) $way['id'] : $wayIndex;
            for ($i = 1; $i < count($geometry); $i++) {
                $a = $geometry[$i - 1];
                $b = $geometry[$i];
                $minX = min($a[1], $b[1]) * $kx - self::MATCH_M;
                $maxX = max($a[1], $b[1]) * $kx + self::MATCH_M;
                $minY = min($a[0], $b[0]) * $ky - self::MATCH_M;
                $maxY = max($a[0], $b[0]) * $ky + self::MATCH_M;
                for ($gx = (int) floor($minX / self::GRID_M); $gx <= (int) floor($maxX / self::GRID_M); $gx++) {
                    for ($gy = (int) floor($minY / self::GRID_M); $gy <= (int) floor($maxY / self::GRID_M); $gy++) {
                        $index[$gx . ':' . $gy][] = [$a, $b, $tags, $wayKey];
                    }
                }
            }
        }
        return $index;
    }

    /** @return array{0:int,1:int} */
    private static function gridCell(array $point, float $lat0): array
    {
        return [
            (int) floor(($point[1] * 111320.0 * cos(deg2rad($lat0))) / self::GRID_M),
            (int) floor(($point[0] * 111320.0) / self::GRID_M),
        ];
    }

    /** Equirectangular distance point→segment, wystarczająca dla 35 m. */
    private static function pointSegmentM(array $p, array $a, array $b): float
    {
        $lat0 = deg2rad($p[0]);
        $kx = 111320.0 * cos($lat0);
        $ky = 111320.0;
        $px = ($p[1] - $a[1]) * $kx;
        $py = ($p[0] - $a[0]) * $ky;
        $bx = ($b[1] - $a[1]) * $kx;
        $by = ($b[0] - $a[0]) * $ky;
        $l2 = $bx * $bx + $by * $by;
        $t = $l2 > 0.0 ? max(0.0, min(1.0, ($px * $bx + $py * $by) / $l2)) : 0.0;
        return hypot($px - $t * $bx, $py - $t * $by);
    }

    /** Różnica kierunku 0..90°; drogi są dwukierunkowe na potrzeby dopasowania. */
    private static function headingDelta(array $a, array $b, array $c, array $d, float $lat0): float
    {
        $kx = 111320.0 * cos(deg2rad($lat0));
        $ky = 111320.0;
        $ux = ($b[1] - $a[1]) * $kx;
        $uy = ($b[0] - $a[0]) * $ky;
        $vx = ($d[1] - $c[1]) * $kx;
        $vy = ($d[0] - $c[0]) * $ky;
        $den = hypot($ux, $uy) * hypot($vx, $vy);
        if ($den <= 0.0) {
            return 90.0;
        }
        $cos = max(-1.0, min(1.0, abs(($ux * $vx + $uy * $vy) / $den)));
        return rad2deg(acos($cos));
    }

    /** Kawałki są ciągłe i współdzielą wyłącznie punkt graniczny. */
    private static function analysisChunks(array $points): array
    {
        $chunks = [];
        $current = [$points[0]];
        for ($i = 1; $i < count($points); $i++) {
            $trial = array_merge($current, [$points[$i]]);
            $lats = array_column($trial, 0);
            $lons = array_column($trial, 1);
            $tooLarge = count($trial) > self::MAX_POINTS
                || max($lats) - min($lats) > self::MAX_BBOX_DEGREES
                || max($lons) - min($lons) > self::MAX_BBOX_DEGREES;
            if ($tooLarge && count($current) >= 2) {
                $chunks[] = $current;
                $current = [$current[count($current) - 1], $points[$i]];
                continue;
            }
            $current[] = $points[$i];
        }
        if (count($current) >= 2) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private static function lineLengthM(array $points): float
    {
        $length = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $length += RouteSnap::haversineM($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }
        return $length;
    }

    private static function mergeSegments(array $segments): array
    {
        $out = [];
        foreach ($segments as $segment) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['surface'] === $segment['surface']
                && $out[$last]['roadClass'] === $segment['roadClass']
                && abs($out[$last]['to'] - $segment['from']) < 1e-9) {
                $out[$last]['to'] = min(1.0, (float) $segment['to']);
                continue;
            }
            $segment['from'] = max(0.0, min(1.0, (float) $segment['from']));
            $segment['to'] = max($segment['from'], min(1.0, (float) $segment['to']));
            $out[] = $segment;
        }
        return $out;
    }
}
