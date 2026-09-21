<?php
// core/Utils/RoutingProxy.php
namespace Utils;

// ROUTE PLANNER, ETAP 1 (2026-09-17, tasks/active/route-planner.md) — silnik
// routingu to PUBLICZNY serwer OSRM. Świadomie BEZ własnej infrastruktury —
// panel architektoniczny odrzucił self-hosted OSRM/Valhalla (brak stałego
// procesu na hostingu współdzielonym) i formalną warstwę pluginową providerów
// jako przedwczesną. Jedyny "szew wymiany" jest TUTAJ: metoda zwraca WŁASNY,
// znormalizowany kształt (coords/distanceM/durationS), nigdy surowy JSON
// OSRM-a — żadne inne miejsce w kodzie nie zna formatu odpowiedzi dostawcy,
// więc zmiana silnika (self-hosted OSRM, BRouter, openrouteservice) zostaje
// zmianą TEJ JEDNEJ klasy. URL z configu (core/config.php: 'planner'), nie
// stała w kodzie — z tego samego powodu.
//
// PROFIL W ADRESIE NIE WYBIERA PROFILU (2026-09-18). Spike sprawdził, że
// `/route/v1/cycling/` odpowiada — nie to, że liczy rowerowo. Serwer demo
// router.project-osrm.org ma wyłącznie profil samochodowy i nazwę z adresu
// pomija, więc do tego dnia planer rysował trasy dla aut. Profil wybiera
// SERWER (config: `osrm_base_url` = rowerowy OSRM FOSSGIS); `cycling`
// w adresie zostaje, bo osrm-routed i tak go nie sprawdza.
//
// TYPY ROWERÓW (2026-09-19): każdy typ ze słownika `bike_type` może mieć
// w panelu własny profil i własny adres serwera dla silnika z config.php
// (`planner.engine`, Models\BikeType::engineProfile) — np. osobny OSRM
// z profilem MTB. Metody przyjmują je jako `$profile` i `$baseUrl`.
// ZMIANA SILNIKA = gałąź dla niego tutaj (dziś tylko `osrm`) + profile
// typów w panelu; reszta planera nie zna silnika.
//
// DŁAWIK: rowerowy serwer pozwala na jedno zapytanie na sekundę. Każde
// wyjście do sieci rezerwuje najpierw swój termin w pliku `.throttle` (wspólny
// dla wszystkich procesów PHP); trafienia w cache nie czekają wcale.
//
// null jest SPODZIEWANYM wynikiem (serwer niedostępny, rate-limit, za długa
// kolejka, trasa poza zasięgiem) — wołający musi zdegradować się łagodnie,
// nie traktować tego jak błąd (patrz Controllers\PlannerController::calculate).
class RoutingProxy
{
    private const REQUEST_TIMEOUT = 12;
    private const MAX_WAYPOINTS = 25;
    // 90 dni: sieć dróg zmienia się rzadko, wynik jest czysto pochodny
    // (utrata pliku cache = najwyżej jedno powtórzone zapytanie do OSRM).
    private const CACHE_TTL_SECONDS = 90 * 24 * 3600;
    // Prosty limit rozmiaru cache na dysku (hosting współdzielony, limit
    // miejsca) — bez tabeli/crona: przy każdym zapisie niewielka szansa na
    // przycięcie najstarszych plików, tak jak Utils\RateLimiter.
    private const MAX_CACHE_FILES = 4000;
    // Profil silnika przychodzi z konfiguracji typu roweru (panel admina) —
    // sprawdzamy KSZTAŁT (idzie do ścieżki adresu), nie listę nazw.
    private const PROFILE_PATTERN = '/^[a-z0-9_-]{1,32}$/';
    // Jedyny silnik zaimplementowany w tej klasie. Inny w config.php
    // (`planner.engine`) = null z każdej metody + wpis w logu, dopóki nie
    // dopisze się tu jego gałęzi.
    private const ENGINE_OSRM = 'osrm';
    // Dłużej nie stoimy w kolejce do serwera: przy tłoku lepiej oddać null
    // (wołający degraduje się łagodnie) niż wisieć do limitu czasu PHP.
    private const MAX_QUEUE_SECONDS = 8.0;

    /**
     * @param  list<array{lat:float,lng:float}> $waypoints kolejność ma znaczenie
     * @return array{coords:list<array{0:float,1:float}>,distanceM:float,durationS:float}|null
     *         coords = [[lat,lng], ...] — WŁASNY kształt, nie [lng,lat] jak w GeoJSON OSRM-a
     */
    public static function route(array $waypoints, string $profile = 'cycling', ?string $baseUrl = null): ?array
    {
        $waypoints = array_values($waypoints);
        if (count($waypoints) < 2 || count($waypoints) > self::MAX_WAYPOINTS) {
            return null;
        }
        if (!self::engineReady()) {
            return null;
        }
        $profile = self::profileOrDefault($profile);
        foreach ($waypoints as $wp) {
            if (!isset($wp['lat'], $wp['lng']) || !is_numeric($wp['lat']) || !is_numeric($wp['lng'])) {
                return null;
            }
        }

        $cacheKey = self::cacheKey('route', $waypoints, $profile, '', $baseUrl);
        $cached = self::cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached['result'];
        }

        $data = self::fetch("/route/v1/{$profile}/" . self::coordsParam($waypoints)
            . '?overview=full&geometries=geojson&steps=false&annotations=false', $baseUrl);
        if ($data === null || empty($data['routes'][0]['geometry']['coordinates'])) {
            error_log('RoutingProxy: OSRM nie zwrócił trasy dla podanych punktów');
            return null;
        }

        $route = $data['routes'][0];
        $coords = array_map(
            static fn(array $c): array => [(float) $c[1], (float) $c[0]], // GeoJSON [lng,lat] -> [lat,lng]
            $route['geometry']['coordinates']
        );
        if (count($coords) < 2) {
            return null;
        }

        $result = [
            'coords'    => $coords,
            'distanceM' => (float) $route['distance'],
            'durationS' => (float) $route['duration'],
        ];
        self::cachePut($cacheKey, $result);
        return $result;
    }

    /**
     * Macierz odległości drogowych — JEDNO zapytanie zamiast osobnej trasy na
     * każde połączenie (warstwa Ridemore wycenia tak wszystkie warianty naraz:
     * START → wejścia, wyjścia → CEL, wyjście → wejście, wejście → wyjście).
     *
     * @param  list<array{lat:float,lng:float}> $points
     * @param  list<int> $sources      indeksy w $points
     * @param  list<int> $destinations indeksy w $points
     * @return array{distances:list<list<float|null>>,durations:list<list<float|null>>}|null
     *         [wiersz źródła][kolumna celu]; null w komórce = brak połączenia
     */
    public static function table(array $points, array $sources, array $destinations, string $profile = 'cycling', ?string $baseUrl = null): ?array
    {
        $points = array_values($points);
        $n = count($points);
        if ($n < 2 || $n > self::MAX_WAYPOINTS || !$sources || !$destinations) {
            return null;
        }
        foreach (array_merge($sources, $destinations) as $i) {
            if (!is_int($i) || $i < 0 || $i >= $n) {
                return null;
            }
        }
        if (!self::engineReady()) {
            return null;
        }
        $profile = self::profileOrDefault($profile);

        $cacheKey = self::cacheKey('table', $points, $profile, implode(',', $sources) . '>' . implode(',', $destinations), $baseUrl);
        $cached = self::cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached['result'];
        }

        $data = self::fetch("/table/v1/{$profile}/" . self::coordsParam($points)
            . '?sources=' . implode(';', $sources) . '&destinations=' . implode(';', $destinations)
            . '&annotations=distance,duration', $baseUrl);
        if ($data === null || !isset($data['distances'], $data['durations'])) {
            error_log('RoutingProxy: OSRM nie zwrócił macierzy odległości');
            return null;
        }

        $norm = static fn(array $rows): array => array_map(
            static fn(array $row): array => array_map(static fn($v): ?float => is_numeric($v) ? (float) $v : null, $row),
            $rows
        );
        $result = ['distances' => $norm($data['distances']), 'durations' => $norm($data['durations'])];
        self::cachePut($cacheKey, $result);
        return $result;
    }

    /**
     * Trasa przez kolejne punkty z geometrią KAŻDEGO odcinka osobno — warstwa
     * Ridemore bierze z niej dojazd do korytarza i zjazd z niego, a odcinek
     * wejście → wyjście zastępuje geometrią korytarza. Jedno zapytanie zamiast
     * osobnego na każdy odcinek (limit: jedno na sekundę).
     *
     * @param  list<array{lat:float,lng:float}> $waypoints
     * @return array{legs:list<array{coords:list<array{0:float,1:float}>,distanceM:float,durationS:float}>}|null
     */
    public static function routeLegs(array $waypoints, string $profile = 'cycling', ?string $baseUrl = null): ?array
    {
        $waypoints = array_values($waypoints);
        if (count($waypoints) < 2 || count($waypoints) > self::MAX_WAYPOINTS) {
            return null;
        }
        if (!self::engineReady()) {
            return null;
        }
        $profile = self::profileOrDefault($profile);

        $cacheKey = self::cacheKey('legs', $waypoints, $profile, '', $baseUrl);
        $cached = self::cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached['result'];
        }

        // Geometria odcinków siedzi w krokach (steps) — pełna linia trasy nie
        // mówi, gdzie kończy się jeden odcinek, a zaczyna następny.
        $data = self::fetch("/route/v1/{$profile}/" . self::coordsParam($waypoints)
            . '?overview=false&geometries=geojson&steps=true&annotations=false', $baseUrl);
        $legsIn = $data['routes'][0]['legs'] ?? null;
        if (!is_array($legsIn) || count($legsIn) !== count($waypoints) - 1) {
            error_log('RoutingProxy: OSRM nie zwrócił odcinków trasy');
            return null;
        }

        $legs = [];
        foreach ($legsIn as $leg) {
            $coords = [];
            foreach ($leg['steps'] ?? [] as $step) {
                foreach ($step['geometry']['coordinates'] ?? [] as $c) {
                    $point = [(float) $c[1], (float) $c[0]];
                    if ($coords && end($coords) === $point) {
                        continue; // kroki stykają się końcami
                    }
                    $coords[] = $point;
                }
            }
            if (!$coords) {
                return null;
            }
            if (count($coords) === 1) {
                $coords[] = $coords[0]; // punkt pośredni tuż przy poprzednim — odcinek zerowej długości
            }
            $legs[] = ['coords' => $coords, 'distanceM' => (float) $leg['distance'], 'durationS' => (float) $leg['duration']];
        }

        $result = ['legs' => $legs];
        self::cachePut($cacheKey, $result);
        return $result;
    }

    /**
     * Termin, w którym wolno wysłać następne zapytanie: nie wcześniej niż
     * teraz i nie wcześniej niż $interval sekund po poprzednim terminie.
     */
    public static function nextSlot(float $last, float $now, float $interval): float
    {
        return max($now, $last + $interval);
    }

    // --- Sieć: jedno miejsce, które zna adres serwera i jego limit ---

    /** Odpowiedź OSRM z `code: Ok` albo null (sieć, HTTP, kolejka, błąd serwera). */
    private static function fetch(string $path, ?string $baseUrl = null): ?array
    {
        if (!self::reserveSlot()) {
            error_log('RoutingProxy: za długa kolejka do OSRM — pomijam zapytanie');
            return null;
        }

        $ch = curl_init(self::baseUrl($baseUrl) . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['User-Agent: ridemore.bike RoutingProxy/1.0 (+https://ridemore.bike)'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            error_log('RoutingProxy: OSRM niedostępny (HTTP ' . $code . ($err ? ", $err" : '') . ')');
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) && ($data['code'] ?? null) === 'Ok' ? $data : null;
    }

    /**
     * Adres serwera: własny adres typu roweru (panel: „Planer" → silnik →
     * adres), a bez niego — adres silnika z config.php.
     */
    private static function baseUrl(?string $override = null): string
    {
        if ($override !== null && preg_match('#^https?://#i', $override) === 1) {
            return rtrim($override, '/');
        }
        return rtrim((string) (APP_CONFIG['planner']['osrm_base_url'] ?? 'https://routing.openstreetmap.de/routed-bike'), '/');
    }

    /** Nazwa silnika z config.php (`planner.engine`) — klucz konfiguracji profili w typach rowerów. */
    public static function engine(): string
    {
        return (string) (APP_CONFIG['planner']['engine'] ?? self::ENGINE_OSRM);
    }

    private static function engineReady(): bool
    {
        if (self::engine() === self::ENGINE_OSRM) {
            return true;
        }
        error_log('RoutingProxy: silnik „' . self::engine() . '" nie jest zaimplementowany — dopisz jego gałąź w Utils\\RoutingProxy');
        return false;
    }

    private static function profileOrDefault(string $profile): string
    {
        return preg_match(self::PROFILE_PATTERN, $profile) === 1 ? $profile : 'cycling';
    }

    /** @param list<array{lat:float,lng:float}> $points */
    private static function coordsParam(array $points): string
    {
        return implode(';', array_map(
            static fn(array $p): string => $p['lng'] . ',' . $p['lat'],
            $points
        ));
    }

    /**
     * Rezerwacja terminu wyjścia do sieci. Blokada pliku trwa tylko na czas
     * odczytu i zapisu terminu — czekanie odbywa się JUŻ PO jej zdjęciu, więc
     * kolejne procesy ustawiają się po swoje terminy, zamiast wisieć na flock.
     * false = kolejka dłuższa niż MAX_QUEUE_SECONDS.
     */
    private static function reserveSlot(): bool
    {
        $interval = max(0, (int) (APP_CONFIG['planner']['osrm_min_interval_ms'] ?? 1000)) / 1000;
        if ($interval <= 0) {
            return true;
        }
        $fh = @fopen(self::cacheDir() . '/.throttle', 'c+');
        if ($fh === false) {
            return true; // bez pliku nie da się kolejkować — lepiej zapytać, niż nie działać wcale
        }
        flock($fh, LOCK_EX);
        $now = microtime(true);
        $slot = self::nextSlot((float) stream_get_contents($fh), $now, $interval);
        $ok = $slot - $now <= self::MAX_QUEUE_SECONDS;
        if ($ok) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, sprintf('%.6F', $slot));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($ok && $slot > $now) {
            usleep((int) round(($slot - $now) * 1e6));
        }
        return $ok;
    }

    // --- Pamięć podręczna — ten sam wzorzec co Utils\RoadSurfaceDetector ---

    private static function cacheDir(): string
    {
        $dir = CORE_PATH . '/../storage/routing-cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Adres serwera jest częścią klucza: po zmianie serwera (2026-09-18: demo
     * samochodowe → rowerowy FOSSGIS) stare wyniki przestają pasować same,
     * zamiast przez 90 dni oddawać trasy liczone dla aut.
     */
    private static function cacheKey(string $kind, array $points, string $profile, string $extra = '', ?string $baseUrl = null): string
    {
        $parts = array_map(
            static fn(array $wp): string => round((float) $wp['lat'], 5) . ',' . round((float) $wp['lng'], 5),
            $points
        );
        return hash('sha256', self::baseUrl($baseUrl) . '|' . $kind . '|' . $profile . '|' . implode(';', $parts) . '|' . $extra);
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
        // 1/50 szans na przycięcie — nie przy każdym zapisie, wystarczająco
        // regularnie, żeby katalog nie rósł bez końca.
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
