<?php
// core/Utils/RoadAttributeCache.php
namespace Utils;

/** Pochodny cache tagów OSM dopasowanych do geometrii Ridemore. */
final class RoadAttributeCache
{
    public static function get(string $hash): ?array
    {
        if (!self::validHash($hash)) {
            return null;
        }
        $file = self::dir() . '/' . $hash . '.json';
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        // Schema 1 miała wyłącznie histogram całej trasy. Użycie go dla
        // krótkiego wycinka dawało fałszywą precyzję, więc starszy cache jest
        // celowo neutralny do czasu ponownego backfillu.
        return is_array($decoded) && ($decoded['schema'] ?? null) === 2 ? $decoded : null;
    }

    /** @param list<string> $hashes */
    public static function many(array $hashes): array
    {
        $out = [];
        foreach (array_unique($hashes) as $hash) {
            $value = is_string($hash) ? self::get($hash) : null;
            if ($value !== null) {
                $out[$hash] = $value;
            }
        }
        return $out;
    }

    public static function put(string $hash, array $attributes): bool
    {
        if (!self::validHash($hash)) {
            return false;
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $payload = [
            'schema' => 2,
            'source' => 'openstreetmap-overpass',
            'surface' => is_array($attributes['surface'] ?? null) ? $attributes['surface'] : [],
            'roadClass' => is_array($attributes['roadClass'] ?? null) ? $attributes['roadClass'] : [],
            'coverage' => max(0.0, min(1.0, (float) ($attributes['coverage'] ?? 0.0))),
            'segments' => self::segments($attributes['segments'] ?? []),
            'updatedAt' => gmdate(DATE_ATOM),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $tmp = $dir . '/.' . $hash . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $dir . '/' . $hash . '.json')) {
            @unlink($tmp);
            return false;
        }
        // Odcisk warstwy planera zawiera ten token, więc nowy/odświeżony
        // backfill od razu unieważnia wynik policzony wcześniej bez tagów.
        @file_put_contents($dir . '/.epoch', gmdate('c') . '-' . sprintf('%.6f', microtime(true)), LOCK_EX);
        return true;
    }

    public static function has(string $hash): bool
    {
        // Starszy schema=1 ma zostać automatycznie przeliczony przez zwykły
        // backfill, a nie blokować się samą obecnością pliku.
        return self::get($hash) !== null;
    }

    public static function fingerprint(): string
    {
        $epoch = self::dir() . '/.epoch';
        return is_file($epoch) ? hash_file('sha256', $epoch) ?: '0' : '0';
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/routing-attributes';
    }

    private static function validHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
    }

    private static function segments(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $segment) {
            if (!is_array($segment) || !is_numeric($segment['from'] ?? null)
                || !is_numeric($segment['to'] ?? null)) {
                continue;
            }
            $from = max(0.0, min(1.0, (float) $segment['from']));
            $to = max($from, min(1.0, (float) $segment['to']));
            if ($to <= $from) {
                continue;
            }
            $out[] = [
                'from' => $from,
                'to' => $to,
                'surface' => in_array($segment['surface'] ?? null, RoutingPreferences::SURFACES, true)
                    ? $segment['surface'] : 'unknown',
                'roadClass' => in_array($segment['roadClass'] ?? null, RoutingPreferences::ROAD_CLASSES, true)
                    ? $segment['roadClass'] : 'unknown',
            ];
        }
        return $out;
    }
}
