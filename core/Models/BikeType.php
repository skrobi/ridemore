<?php
// core/Models/BikeType.php
//
// TYPY ROWERÓW (2026-09-19, tasks/active/warstwa-routingu-ridemore.md, Etap 3).
// Słownik `bike_type` — ten sam co w wydarzeniach i preferencjach — jako
// jedno miejsce prawdy o sprzęcie: z czym przejechano przejazd
// (`rider_activities.bike_type_item_id`, migr. 091) i jak planer ma dla danego
// roweru wybierać trasę. Żadnej nowej tabeli: konfiguracja siedzi w
// `dictionary_items.meta` (wzorzec `map_layer`, migr. 072):
//
//   planner.speedKmh        założona prędkość (szacowany czas)
//   planner.minAsphaltPct   znana trasa z mniejszym % asfaltu = niezgodna (null = bez progu)
//   planner.legalRatio      ile dłużej może jechać silnik od wejścia do wyjścia korytarza
//   planner.trailBonus      premia do RidemoreScore za znaną trasę terenową
//   planner.countsRidesOf   przejazdy jakich typów są „dowodem" (pusta = wszystkie;
//                           przejazd bez typu zawsze się liczy — brak wiedzy to nie kara)
//   planner.engines.{silnik}.profile|baseUrl   profil silnika routingu dla tego roweru
//   aliases                 nazwy rodzaju aktywności z importów (Garmin, Polar/Wahoo, GPX)
//
// Edycja: /admin/taksonomia?dict=bike_type → „Planer" (Admin\TaxonomyController).
// Typ bez konfiguracji (dodany w panelu) dostaje DEFAULT_PLANNER.
namespace Models;

use Core\Database;
use Utils\RoutingPreferences;

class BikeType
{
    public const DICTIONARY = 'bike_type';

    public const DEFAULT_PLANNER = [
        'speedKmh'      => 20.0,
        'minAsphaltPct' => null,
        'legalRatio'    => 1.3,
        'trailBonus'    => 0.0,
        'countsRidesOf' => [],
        'engines'       => [],
    ];

    private const DEFAULT_ENGINE_PROFILE = 'cycling';
    private const MAX_ALIASES = 40;

    /** @var array<int,array>|null w obrębie żądania — słownik czyta się raz */
    private static ?array $cache = null;

    /**
     * Typy rowerów z konfiguracją, w kolejności ze słownika.
     *
     * @return list<array{id:int,code:string,name:string,active:bool,planner:array,aliases:list<string>}>
     */
    public static function all(bool $onlyActive = true): array
    {
        if (self::$cache === null) {
            $stmt = Database::connection()->prepare(
                'SELECT di.id, di.code, di.name, di.is_active, di.meta
                   FROM dictionary_items di
                   JOIN dictionaries d ON d.id = di.dictionary_id
                  WHERE d.code = :dict
                  ORDER BY di.sort_order ASC, di.name ASC'
            );
            $stmt->execute(['dict' => self::DICTIONARY]);
            self::$cache = [];
            foreach ($stmt->fetchAll() as $row) {
                $meta = $row['meta'] !== null ? (json_decode((string) $row['meta'], true) ?: []) : [];
                self::$cache[] = [
                    'id'      => (int) $row['id'],
                    'code'    => (string) $row['code'],
                    'name'    => \Core\Lang::isDefault() ? (string) $row['name'] : __((string) $row['name']),
                    'active'  => (bool) $row['is_active'],
                    'planner' => self::normalizePlanner(is_array($meta['planner'] ?? null) ? $meta['planner'] : []),
                    'aliases' => self::normalizeAliases($meta['aliases'] ?? []),
                ];
            }
        }
        return $onlyActive
            ? array_values(array_filter(self::$cache, static fn(array $t): bool => $t['active']))
            : self::$cache;
    }

    public static function byCode(?string $code, bool $onlyActive = true): ?array
    {
        foreach (self::all($onlyActive) as $type) {
            if ($type['code'] === $code) {
                return $type;
            }
        }
        return null;
    }

    public static function byId(?int $id, bool $onlyActive = true): ?array
    {
        foreach (self::all($onlyActive) as $type) {
            if ($type['id'] === $id) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Typ roweru z nazwy rodzaju aktywności z importu (Garmin `road_biking`,
     * Polar `ROAD_BIKING`, Strava `MountainBikeRide`, `<type>` w GPX…) —
     * po aliasach z konfiguracji. null = nie wiadomo (np. ogólne „cycling").
     */
    public static function idForAlias(?string $external): ?int
    {
        $key = self::normalizeAlias((string) $external);
        if ($key === '') {
            return null;
        }
        foreach (self::all() as $type) {
            if (in_array($key, $type['aliases'], true)) {
                return $type['id'];
            }
        }
        return null;
    }

    /** „Mountain Bike-Ride" → „mountain_bike_ride". */
    public static function normalizeAlias(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[\s\-]+/', '_', $value);
        return preg_match('/^[a-z0-9_]{1,64}$/', $value) === 1 ? $value : '';
    }

    /**
     * Zasady dla Utils\RidemoreRouting (opcja `profile`). Brak typu = zasady
     * domyślne pierwszego aktywnego typu albo DEFAULT_PLANNER.
     *
     * @return array{code:string,legalRatio:float,minAsphaltPct:?int,trailBonus:float,countsRidesOf:list<string>}
     */
    public static function plannerRules(?array $type): array
    {
        $planner = $type['planner'] ?? self::normalizePlanner([]);
        return [
            'code'          => (string) ($type['code'] ?? ''),
            'legalRatio'    => $planner['legalRatio'],
            'minAsphaltPct' => $planner['minAsphaltPct'],
            'trailBonus'    => $planner['trailBonus'],
            'countsRidesOf' => $planner['countsRidesOf'],
        ];
    }

    /**
     * Warstwa preferencji POD istniejącym typem roweru. `character` nie jest
     * nowym profilem — np. Gravel + offroad daje „Gravel terenowy".
     */
    public static function routingPreferences(?array $type, mixed $character = 'balanced', mixed $overrides = []): array
    {
        return RoutingPreferences::resolve(
            (string) ($type['code'] ?? ''),
            $character,
            is_array($overrides) ? $overrides : []
        );
    }

    /**
     * Profil silnika routingu dla typu roweru. `baseUrl` null = adres silnika
     * z config.php; ustawiony = ten typ jedzie innym serwerem (np. osobny
     * OSRM z profilem MTB).
     *
     * @return array{profile:string,baseUrl:?string}
     */
    public static function engineProfile(?array $type, string $engine): array
    {
        $cfg = $type['planner']['engines'][$engine] ?? [];
        return [
            'profile' => $cfg['profile'] ?? self::DEFAULT_ENGINE_PROFILE,
            'baseUrl' => ($cfg['baseUrl'] ?? '') !== '' ? $cfg['baseUrl'] : null,
        ];
    }

    /**
     * Zapis sekcji „Planer" z panelu — pozostałe klucze `meta` zostają.
     * false = pozycja nie należy do słownika typów rowerów.
     */
    public static function savePlannerConfig(int $itemId, array $input): bool
    {
        $item = Dictionary::findItem($itemId);
        if ($item === null || $item['dictionaryCode'] !== self::DICTIONARY) {
            return false;
        }
        $db = Database::connection();
        $stmt = $db->prepare('SELECT meta FROM dictionary_items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        $raw = $stmt->fetchColumn();
        $meta = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        $knownCodes = array_column(self::all(false), 'code');
        $planner = self::normalizePlanner($input);
        $planner['countsRidesOf'] = array_values(array_intersect($planner['countsRidesOf'], $knownCodes));
        $meta['planner'] = $planner;
        $aliases = $input['aliases'] ?? [];
        $meta['aliases'] = self::normalizeAliases(is_string($aliases) ? preg_split('/[,\n]+/', $aliases) : $aliases);

        $db->prepare('UPDATE dictionary_items SET meta = :meta WHERE id = :id')
            ->execute(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'id' => $itemId]);
        self::$cache = null;
        return true;
    }

    /**
     * Konfiguracja przycięta do rozsądnych granic — z panelu przychodzą
     * napisy, a zła wartość nie może rozregulować wyboru tras.
     */
    public static function normalizePlanner(array $raw): array
    {
        $d = self::DEFAULT_PLANNER;
        $num = static fn($v, float $def, float $min, float $max): float
            => is_numeric($v) ? max($min, min($max, (float) $v)) : $def;

        $asphalt = $raw['minAsphaltPct'] ?? $d['minAsphaltPct'];
        $asphalt = is_numeric($asphalt) && $asphalt !== '' ? (int) max(0, min(100, (float) $asphalt)) : null;

        $counts = [];
        foreach (is_array($raw['countsRidesOf'] ?? null) ? $raw['countsRidesOf'] : [] as $code) {
            if (is_string($code) && preg_match('/^[a-z0-9_]{1,64}$/', $code) === 1) {
                $counts[] = $code;
            }
        }

        $engines = [];
        foreach (is_array($raw['engines'] ?? null) ? $raw['engines'] : [] as $engine => $cfg) {
            if (!is_string($engine) || preg_match('/^[a-z0-9_]{1,32}$/', $engine) !== 1 || !is_array($cfg)) {
                continue;
            }
            $profile = trim((string) ($cfg['profile'] ?? ''));
            $baseUrl = trim((string) ($cfg['baseUrl'] ?? ''));
            $engines[$engine] = [
                'profile' => preg_match('/^[a-z0-9_-]{1,32}$/', $profile) === 1 ? $profile : self::DEFAULT_ENGINE_PROFILE,
                'baseUrl' => self::isHttpUrl($baseUrl) ? rtrim($baseUrl, '/') : '',
            ];
        }

        return [
            'speedKmh'      => $num($raw['speedKmh'] ?? null, $d['speedKmh'], 5.0, 60.0),
            'minAsphaltPct' => $asphalt,
            'legalRatio'    => $num($raw['legalRatio'] ?? null, $d['legalRatio'], 1.0, 3.0),
            'trailBonus'    => $num($raw['trailBonus'] ?? null, $d['trailBonus'], 0.0, 0.5),
            'countsRidesOf' => array_values(array_unique($counts)),
            'engines'       => $engines,
        ];
    }

    /** @return list<string> */
    private static function normalizeAliases(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $alias) {
            $key = is_string($alias) ? self::normalizeAlias($alias) : '';
            if ($key !== '' && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return array_slice($out, 0, self::MAX_ALIASES);
    }

    private static function isHttpUrl(string $url): bool
    {
        return $url !== '' && strlen($url) <= 300
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
