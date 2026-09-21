<?php
// core/Utils/RoutingPreferences.php
namespace Utils;

/**
 * Preferencje dróg NAD istniejącym typem roweru.
 *
 * Typ roweru (`Models\BikeType`) pozostaje nadrzędny. Ta klasa jedynie
 * rozwija jego charakter trasy (drogowy / standard / terenowy), nakłada
 * bezpieczne override'y użytkownika i zamienia semantyczne poziomy -2..2
 * na korektę kosztu wyrażoną w metrach. Brak danych jest zawsze neutralny.
 */
final class RoutingPreferences
{
    public const CHARACTERS = ['road', 'balanced', 'offroad'];

    public const SURFACES = [
        'asphalt', 'concrete', 'gravel', 'compacted', 'dirt', 'ground',
        'sand', 'mud', 'unknown',
    ];

    public const ROAD_CLASSES = [
        'motorway', 'trunk', 'primary', 'secondary', 'tertiary',
        'unclassified', 'residential', 'service', 'track', 'path',
        'cycleway', 'footway', 'pedestrian', 'unknown',
    ];

    /**
     * @return array{character:string,surface:array<string,int>,roadClass:array<string,int>,popularityStrength:float}
     */
    public static function resolve(string $bikeCode, mixed $character = 'balanced', mixed $overrides = []): array
    {
        $character = self::character($character);
        $preset = self::preset($bikeCode, $character);
        $overrides = is_array($overrides) ? $overrides : [];

        foreach (['surface' => self::SURFACES, 'roadClass' => self::ROAD_CLASSES] as $group => $keys) {
            $raw = is_array($overrides[$group] ?? null) ? $overrides[$group] : [];
            foreach ($keys as $key) {
                if ($key === 'unknown' || !array_key_exists($key, $raw) || !is_numeric($raw[$key])) {
                    continue;
                }
                $preset[$group][$key] = (int) max(-2, min(2, (int) round((float) $raw[$key])));
            }
            // Nieznane dane nigdy nie dostają kary ani premii, także gdy klient
            // spróbuje przysłać inną wartość ręcznie.
            $preset[$group]['unknown'] = 0;
        }
        if (isset($overrides['popularityStrength']) && is_numeric($overrides['popularityStrength'])) {
            $preset['popularityStrength'] = max(0.0, min(1.0, (float) $overrides['popularityStrength']));
        }
        $preset['character'] = $character;
        return $preset;
    }

    public static function character(mixed $value): string
    {
        return is_string($value) && in_array($value, self::CHARACTERS, true) ? $value : 'balanced';
    }

    /** Stabilny odcisk do cache warstwy i sygnatury segmentu. */
    public static function fingerprint(array $preferences): string
    {
        $normalized = self::resolve('', $preferences['character'] ?? 'balanced', $preferences);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Korekta kosztu korytarza. Dodatnia = droga mniej pożądana, ujemna =
     * preferowana. Surface i highway są uśredniane, a nie sumowane, aby jedna
     * cecha OSM nie została policzona dwukrotnie.
     *
     * $attributes może zawierać dokładne histogramy (`surface`, `roadClass`)
     * albo starsze trzy procenty Ridemore (`asphalt`, `gravel`, `trail`).
     *
     * @return array{adjustmentM:float,coverage:float,surfaceLevel:float,roadLevel:float}
     */
    public static function costAdjustment(array $preferences, array $attributes, float $lengthM): array
    {
        $surface = self::distribution($attributes['surface'] ?? null, self::SURFACES);
        if (!$surface && (isset($attributes['asphalt']) || isset($attributes['gravel']) || isset($attributes['trail']))) {
            $surface = self::legacySurface($attributes);
        }
        $roads = self::distribution($attributes['roadClass'] ?? null, self::ROAD_CLASSES);

        [$surfaceLevel, $surfaceCoverage] = self::level($surface, $preferences['surface'] ?? []);
        [$roadLevel, $roadCoverage] = self::level($roads, $preferences['roadClass'] ?? []);
        $sourceCoverage = self::sourceCoverage($attributes);
        $surfaceCoverage *= $sourceCoverage;
        $roadCoverage *= $sourceCoverage;
        $levels = [];
        if ($surfaceCoverage > 0.0) {
            $levels[] = [$surfaceLevel, $surfaceCoverage];
        }
        if ($roadCoverage > 0.0) {
            $levels[] = [$roadLevel, $roadCoverage];
        }
        if (!$levels) {
            return ['adjustmentM' => 0.0, 'coverage' => 0.0, 'surfaceLevel' => 0.0, 'roadLevel' => 0.0];
        }

        $weight = array_sum(array_column($levels, 1));
        $level = array_sum(array_map(static fn(array $v): float => $v[0] * $v[1], $levels)) / $weight;
        $coverage = max($surfaceCoverage, $roadCoverage);
        // Unikanie jest silniejsze niż preferowanie. Maksymalna kara 32%
        // długości korytarza przewyższa maksymalny bonus popularności (20%),
        // ale twardy limit objazdu nadal rozstrzyga przed tym kosztem.
        $factor = $level < 0.0 ? -$level * 0.16 : -$level * 0.08;
        return [
            'adjustmentM' => $lengthM * $factor * $coverage,
            'coverage' => $coverage,
            'surfaceLevel' => $surfaceLevel,
            'roadLevel' => $roadLevel,
        ];
    }

    /** Kara zgodności bazowego profilu; nigdy twardy zakaz nawierzchni. */
    public static function compatibilityPenalty(?int $minAsphaltPct, array $attributes, float $lengthM): float
    {
        if ($minAsphaltPct === null) {
            return 0.0;
        }
        $surface = self::distribution($attributes['surface'] ?? null, self::SURFACES);
        $asphalt = null;
        $knownCoverage = self::sourceCoverage($attributes);
        if ($surface) {
            $total = array_sum($surface);
            $known = array_sum(array_filter(
                $surface,
                static fn(string $key): bool => $key !== 'unknown',
                ARRAY_FILTER_USE_KEY
            ));
            $paved = ($surface['asphalt'] ?? 0.0) + ($surface['concrete'] ?? 0.0);
            $asphalt = $known > 0.0 ? 100.0 * $paved / $known : null;
            $knownCoverage *= $total > 0.0 ? $known / $total : 0.0;
        } elseif (isset($attributes['asphalt']) && is_numeric($attributes['asphalt'])) {
            $asphalt = (float) $attributes['asphalt'];
        }
        if ($asphalt === null || $asphalt >= $minAsphaltPct) {
            return 0.0;
        }
        $shortage = ($minAsphaltPct - $asphalt) / max(1.0, (float) $minAsphaltPct);
        return $lengthM * (0.20 + 0.25 * $shortage) * $knownCoverage;
    }

    /**
     * Histogram dla lokalnego wycinka geometrii. Segmenty cache mają zakres
     * 0..1 po oryginalnym śladzie; niepokryta część zostaje neutralna.
     */
    public static function sliceAttributes(array $attributes, float $from, float $to): array
    {
        $segments = is_array($attributes['segments'] ?? null) ? $attributes['segments'] : [];
        if (!$segments) {
            return $attributes;
        }
        [$from, $to] = [max(0.0, min($from, $to)), min(1.0, max($from, $to))];
        $span = $to - $from;
        if ($span <= 0.0) {
            return ['surface' => [], 'roadClass' => [], 'coverage' => 0.0];
        }
        $surface = [];
        $roads = [];
        $matched = 0.0;
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $a = max($from, (float) ($segment['from'] ?? 0.0));
            $b = min($to, (float) ($segment['to'] ?? 0.0));
            $overlap = $b - $a;
            if ($overlap <= 0.0) {
                continue;
            }
            $matched += $overlap;
            $surfaceKey = in_array($segment['surface'] ?? null, self::SURFACES, true)
                ? $segment['surface'] : 'unknown';
            $roadKey = in_array($segment['roadClass'] ?? null, self::ROAD_CLASSES, true)
                ? $segment['roadClass'] : 'unknown';
            $surface[$surfaceKey] = ($surface[$surfaceKey] ?? 0.0) + $overlap;
            $roads[$roadKey] = ($roads[$roadKey] ?? 0.0) + $overlap;
        }
        $percentages = static function (array $amounts) use ($span): array {
            foreach ($amounts as $key => $amount) {
                $amounts[$key] = 100.0 * $amount / $span;
            }
            return $amounts;
        };
        return [
            'surface' => $percentages($surface),
            'roadClass' => $percentages($roads),
            'coverage' => min(1.0, $matched / $span),
        ];
    }

    /** Atrybut w konkretnym miejscu śladu; null oznacza rzeczywisty brak danych. */
    public static function attributeAt(array $attributes, float $ratio): ?array
    {
        foreach (is_array($attributes['segments'] ?? null) ? $attributes['segments'] : [] as $segment) {
            if (is_array($segment) && $ratio >= (float) ($segment['from'] ?? 0.0)
                && $ratio <= (float) ($segment['to'] ?? 0.0)) {
                return [
                    'surface' => in_array($segment['surface'] ?? null, self::SURFACES, true)
                        ? $segment['surface'] : 'unknown',
                    'roadClass' => in_array($segment['roadClass'] ?? null, self::ROAD_CLASSES, true)
                        ? $segment['roadClass'] : 'unknown',
                ];
            }
        }
        return null;
    }

    /**
     * Domyślne presety istniejących profili. Kody spoza listy zachowują
     * bezpieczny, neutralny charakter roweru ogólnego.
     */
    private static function preset(string $bikeCode, string $character): array
    {
        $surface = array_fill_keys(self::SURFACES, 0);
        $roads = array_fill_keys(self::ROAD_CLASSES, 0);
        $bike = strtolower($bikeCode);

        if (in_array($bike, ['szosowy', 'road'], true)) {
            $surface = array_merge($surface, ['asphalt' => 2, 'concrete' => 2, 'gravel' => -1, 'compacted' => -1, 'dirt' => -2, 'ground' => -2, 'sand' => -2, 'mud' => -2]);
            $roads = array_merge($roads, ['motorway' => -2, 'trunk' => -2, 'primary' => -2, 'secondary' => -1, 'tertiary' => 1, 'unclassified' => 2, 'residential' => 2, 'service' => 1, 'track' => -2, 'path' => -2, 'cycleway' => 2, 'footway' => -2, 'pedestrian' => -1]);
        } elseif ($bike === 'mtb') {
            $surface = array_merge($surface, ['asphalt' => -2, 'concrete' => -2, 'gravel' => 1, 'compacted' => 1, 'dirt' => 2, 'ground' => 2, 'sand' => 1, 'mud' => 1]);
            $roads = array_merge($roads, ['motorway' => -2, 'trunk' => -2, 'primary' => -2, 'secondary' => -2, 'tertiary' => -1, 'unclassified' => 0, 'residential' => -1, 'service' => 0, 'track' => 2, 'path' => 2, 'cycleway' => 1, 'footway' => 0, 'pedestrian' => -1]);
        } elseif ($bike === 'gravel') {
            $surface = array_merge($surface, ['asphalt' => 0, 'concrete' => 0, 'gravel' => 2, 'compacted' => 2, 'dirt' => 1, 'ground' => 1, 'sand' => -1, 'mud' => -1]);
            $roads = array_merge($roads, ['motorway' => -2, 'trunk' => -2, 'primary' => -2, 'secondary' => -1, 'tertiary' => 0, 'unclassified' => 1, 'residential' => 1, 'service' => 1, 'track' => 2, 'path' => 0, 'cycleway' => 1, 'footway' => -1, 'pedestrian' => -1]);
        }

        if ($character === 'road') {
            foreach (['asphalt', 'concrete'] as $key) { $surface[$key] = min(2, $surface[$key] + 1); }
            foreach (['dirt', 'ground', 'sand', 'mud'] as $key) { $surface[$key] = max(-2, $surface[$key] - 1); }
            foreach (['track', 'path', 'footway'] as $key) { $roads[$key] = max(-2, $roads[$key] - 1); }
            foreach (['tertiary', 'unclassified', 'residential', 'cycleway'] as $key) { $roads[$key] = min(2, $roads[$key] + 1); }
        } elseif ($character === 'offroad') {
            foreach (['asphalt', 'concrete'] as $key) { $surface[$key] = max(-2, $surface[$key] - 1); }
            foreach (['gravel', 'compacted', 'dirt', 'ground'] as $key) { $surface[$key] = min(2, $surface[$key] + 1); }
            foreach (['primary', 'secondary', 'residential'] as $key) { $roads[$key] = max(-2, $roads[$key] - 1); }
            foreach (['track', 'path'] as $key) { $roads[$key] = min(2, $roads[$key] + 1); }
        }
        $surface['unknown'] = 0;
        $roads['unknown'] = 0;
        return ['character' => $character, 'surface' => $surface, 'roadClass' => $roads, 'popularityStrength' => 1.0];
    }

    private static function distribution(mixed $raw, array $allowed): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($allowed as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key]) && (float) $raw[$key] > 0.0) {
                $out[$key] = (float) $raw[$key];
            }
        }
        return $out;
    }

    private static function legacySurface(array $raw): array
    {
        $out = [];
        if (isset($raw['asphalt']) && is_numeric($raw['asphalt'])) { $out['asphalt'] = max(0.0, (float) $raw['asphalt']); }
        if (isset($raw['gravel']) && is_numeric($raw['gravel'])) { $out['gravel'] = max(0.0, (float) $raw['gravel']); }
        if (isset($raw['trail']) && is_numeric($raw['trail'])) { $out['ground'] = max(0.0, (float) $raw['trail']); }
        return $out;
    }

    /** @return array{0:float,1:float} średni poziom, pokrycie 0..1 */
    private static function level(array $distribution, array $weights): array
    {
        $total = array_sum($distribution);
        if ($total <= 0.0) {
            return [0.0, 0.0];
        }
        $known = 0.0;
        $sum = 0.0;
        foreach ($distribution as $key => $amount) {
            if ($key === 'unknown' || !array_key_exists($key, $weights)) {
                continue;
            }
            $known += $amount;
            $sum += $amount * (float) $weights[$key];
        }
        return $known > 0.0 ? [$sum / $known, min(1.0, $known / $total)] : [0.0, 0.0];
    }

    private static function sourceCoverage(array $attributes): float
    {
        return isset($attributes['coverage']) && is_numeric($attributes['coverage'])
            ? max(0.0, min(1.0, (float) $attributes['coverage']))
            : 1.0;
    }
}
