<?php
// core/Utils/ElevationLookup.php
namespace Utils;

// ROUTE PLANNER, ETAP 1 — przewyższenie dla trasy jeszcze NIEPRZEJECHANEJ.
// Ridemore dziś liczy wysokość wyłącznie z realnych plików GPX
// (Gpx::sampleProfile) — dla trasy dopiero zaplanowanej takiego pliku nie ma,
// więc jedyne źródło to darmowe, publiczne API elevation (zweryfikowane
// w spike'u: api.opentopodata.org, SRTM 30m, bez klucza).
//
// Wołane WYŁĄCZNIE przy zapisie trasy (nie przy każdym przeliczeniu podczas
// przeciągania punktów) — publiczne API elevation jest w praktyce mniej
// stabilne niż OSRM, więc panel reliability/cost świadomie ograniczył liczbę
// zapytań do jednego per zapis, nie per segment. Brak wyniku NIE blokuje
// zapisu: ascent/descent zostają NULL, PlannerController zapisuje trasę mimo
// to (patrz Models\PlannedRoute — kolumny są nullable z dokładnie tego powodu).
class ElevationLookup
{
    private const REQUEST_TIMEOUT = 10;
    // Publiczna instancja opentopodata przyjmuje do 100 lokalizacji naraz —
    // próbkujemy trasę do tej liczby punktów zamiast pytać o każdy punkt
    // geometrii (potrafi mieć ich tysiące).
    private const MAX_LOCATIONS = 100;
    private const CACHE_TTL_SECONDS = 90 * 24 * 3600;
    private const MAX_CACHE_FILES = 2000;

    /**
     * @param  list<array{0:float,1:float}> $coords [lat,lng] wzdłuż trasy, w kolejności
     * @return array{ascentM:int,descentM:int}|null
     */
    public static function forRoute(array $coords): ?array
    {
        $coords = array_values($coords);
        if (count($coords) < 2) {
            return null;
        }
        $sampled = self::sample($coords, self::MAX_LOCATIONS);

        $cacheKey = self::cacheKey($sampled);
        $cached = self::cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached['result'];
        }

        $elevations = self::fetchElevations($sampled);
        if ($elevations === null) {
            return null;
        }

        $ascent = 0.0;
        $descent = 0.0;
        for ($i = 1; $i < count($elevations); $i++) {
            $diff = $elevations[$i] - $elevations[$i - 1];
            if ($diff > 0) {
                $ascent += $diff;
            } else {
                $descent += -$diff;
            }
        }

        $result = ['ascentM' => (int) round($ascent), 'descentM' => (int) round($descent)];
        self::cachePut($cacheKey, $result);
        return $result;
    }

    /** @param list<array{0:float,1:float}> $points @return list<float>|null */
    private static function fetchElevations(array $points): ?array
    {
        $locations = implode('|', array_map(
            static fn(array $p): string => $p[0] . ',' . $p[1],
            $points
        ));

        $baseUrl = (string) (APP_CONFIG['planner']['elevation_base_url'] ?? 'https://api.opentopodata.org/v1/srtm30m');
        $url = $baseUrl . '?locations=' . urlencode($locations);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['User-Agent: ridemore.bike ElevationLookup/1.0 (+https://ridemore.bike)'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            error_log('ElevationLookup: API niedostępne (HTTP ' . $code . ($err ? ", $err" : '') . ')');
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || ($data['status'] ?? null) !== 'OK' || empty($data['results'])) {
            error_log('ElevationLookup: nieoczekiwana odpowiedź API');
            return null;
        }

        $out = [];
        foreach ($data['results'] as $r) {
            if (!isset($r['elevation']) || $r['elevation'] === null) {
                return null; // luka w danych (SRTM bez pokrycia) — cała próbka staje się bezużyteczna
            }
            $out[] = (float) $r['elevation'];
        }
        return $out;
    }

    /** Równomierne przerzedzenie do $max punktów — ten sam pomysł co Gpx::simplify(), bez zależności od niej (inny kształt punktu). */
    private static function sample(array $coords, int $max): array
    {
        $total = count($coords);
        if ($total <= $max) {
            return $coords;
        }
        $step = ($total - 1) / ($max - 1);
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $coords[(int) round($i * $step)];
        }
        return $out;
    }

    private static function cacheDir(): string
    {
        $dir = CORE_PATH . '/../storage/elevation-cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function cacheKey(array $points): string
    {
        $parts = array_map(
            static fn(array $p): string => round($p[0], 4) . ',' . round($p[1], 4),
            $points
        );
        return hash('sha256', implode(';', $parts));
    }

    private static function cacheGet(string $key): ?array
    {
        $file = self::cacheDir() . '/' . $key . '.json';
        if (!is_file($file) || (time() - filemtime($file)) > self::CACHE_TTL_SECONDS) {
            return null;
        }
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) && array_key_exists('result', $data) ? $data : null;
    }

    private static function cachePut(string $key, array $result): void
    {
        @file_put_contents(self::cacheDir() . '/' . $key . '.json', json_encode(['result' => $result]));
        if (random_int(1, 50) === 1) {
            self::pruneCache();
        }
    }

    private static function pruneCache(): void
    {
        $dir = self::cacheDir();
        $files = glob($dir . '/*.json') ?: [];
        if (count($files) <= self::MAX_CACHE_FILES) {
            return;
        }
        usort($files, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - self::MAX_CACHE_FILES) as $old) {
            @unlink($old);
        }
    }
}
