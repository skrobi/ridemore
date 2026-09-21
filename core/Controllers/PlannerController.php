<?php
// core/Controllers/PlannerController.php
//
// ROUTE PLANNER, ETAP 1 — MVP (2026-09-17, tasks/active/route-planner.md).
// Zgodnie z decyzją dwóch paneli architektonicznych: PHP + JS, BEZ Pythona,
// BEZ własnej infrastruktury routingu. `Utils\RoutingProxy`/`Utils\ElevationLookup`
// to jedyne miejsca znające kształt odpowiedzi zewnętrznych API — ten
// kontroler operuje wyłącznie na ich WŁASNYM, znormalizowanym kształcie.
//
// Segmenty, nie cała trasa naraz: dokładnie jak stary planner z `history/`
// liczył trasę PER ODCINEK (para kolejnych waypointów), nie jako jeden
// monolit.
//
// „ŹRÓDŁA TRASY" (2026-09-18, przebudowa po propozycji UX zaakceptowanej przez
// usera). Dwa tryby, jedna zasada: DANE RIDEMORE → PREFEROWANE ODCINKI, OSM →
// BRAKUJĄCE POŁĄCZENIA. Planowanie nigdy się nie przerywa, gdy źródła się nie
// łączą — luki zawsze wypełnia zwykły routing.
//  • Zaznaczone źródła (Moje przejazdy / Znane trasy / Przejazdy społeczności):
//    szkieletem jest trasa OSM odcinka; tam, gdzie biegnie wzdłuż linii
//    Ridemore, jedzie po jej geometrii (RouteSnap::preferredRuns — reguły
//    przyciągania i pierwszeństwa są opisane tam).
//  • Baza (konkretna trasa albo GPX): każdy odcinek jedzie po bazie między
//    rzutami swoich punktów, OSM tylko na dojazdy i zjazdy.
// Publiczny OSRM nie przyjmuje wag dróg, więc „preferowanie" to wklejanie
// geometrii Ridemore w trasę OSM — nie wybór innej drogi przez silnik.
//
// WARSTWA ROUTINGU RIDEMORE (2026-09-18) — przełącznik „Dołączaj dłuższe
// odcinki Ridemore" włącza Utils\RidemoreRouting: popularne korytarze
// w okolicy odcinka, połączenia do nich z OSRM, kilka wariantów i koszt
// CAŁEJ trasy z twardym limitem wydłużenia. Zastąpiła dawne lokalne
// dołączanie (≤1,8× na kawałek). Wyłączony przełącznik = samo przyciąganie.
//
// Skarb jako punkt pośredni NIE ma osobnej ścieżki kodu — to zwykły waypoint
// z type='treasure' (kosmetyka na liście/mapie). "Zjazd do skarbu i powrót"
// wychodzi sam z kolejności segmentów, bez żadnego dodatkowego algorytmu.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\BikeType;
use Models\GpxGeometry;
use Models\KnownRoute;
use Models\PlannedRoute;
use Models\PlannerRoutingConfig;
use Models\RiderActivity;
use Models\RidemoreCorridors;
use Models\TileCache;
use Models\TileSource;
use Models\Treasure;
use Utils\ElevationLookup;
use Utils\Gpx;
use Utils\RidemoreRouting;
use Utils\RoutingPreferences;
use Utils\RouteSnap;
use Utils\RoutingProxy;
use Utils\TileGrid;
use Utils\View;

class PlannerController
{
    private const MAX_WAYPOINTS = 25;

    // Dopasowanie trasy OSM do linii Ridemore (tryb „Wszystkie zaznaczone źródła").
    // 60 m: szum GPS i szerokość drogi mieszczą się z zapasem, a ścieżka
    // rowerowa wzdłuż jezdni też — dalsza równoległa droga już nie.
    private const MATCH_THRESHOLD_M = 60.0;
    private const DENSIFY_M = 25.0;       // co ile metrów mierzyć, czy trasa biegnie wzdłuż linii
    private const GAP_M = 75.0;           // krótsze odejście od linii nie przerywa kawałka (skrzyżowania)
    private const MIN_RUN_M = 250.0;      // krótszy wspólny kawałek to przecięcie, nie wspólny odcinek
    // Przyciąganie: geometria Ridemore najwyżej o 20% dłuższa od zastępowanej
    // drogi OSM. (Dawne dołączanie ≤1,8× na kawałek zastąpiła warstwa
    // Ridemore — koszt całej trasy z limitem wydłużenia.)
    private const SNAP_RATIO = 1.2;
    // Ślad tam-i-z-powrotem: dwa przebiegi bliżej niż 20 m od siebie to „ten
    // sam" kandydat — wygrywa ciągłość indeksu (RouteSnap::nearestAlong).
    private const CONTINUITY_M = 20.0;
    // Skok po linii dłuższy niż droga po trasie (+120 m luzu) = pętla/zawrotka
    // w śladzie — kawałek się tam dzieli, zamiast odpadać w całości.
    private const JUMP_SLACK_M = 120.0;
    // Ilu kandydatów na źródło czytać z bazy — najczęściej trafiających w korytarz trasy.
    private const MAX_LINES_PER_SOURCE = 25;

    // Warstwa Ridemore: linie na źródło do liczenia popularności (więcej niż do
    // samego przyciągania — każdy ślad to głos „tędy się jeździ"), budżet czasu
    // na jedno przeliczenie (reszta odcinków dostaje wtedy trasę bazową)
    // i pamięć wyniku odcinka.
    private const LAYER_LINES_PER_SOURCE = 40;
    private const LAYER_BUDGET_S = 25.0;
    private const LAYER_CACHE_TTL_S = 24 * 3600;
    private const LAYER_CACHE_MAX_FILES = 2000;

    // Tryb bazy: punkt „przy bazie" bliżej niż 25 m nie dostaje osobnego dojazdu
    // OSM; rzut na PIERWSZY przejazd bazy, gdy kolejny jest bliżej o mniej niż 50 m.
    private const APPROACH_MIN_M = 25.0;
    private const BASE_TOLERANCE_M = 50.0;
    private const MAX_BASE_POINTS = 100000;

    // Brak realnego czasu OSRM dla wklejonej geometrii Ridemore (to nie jest
    // zapytanie routingowe) — liczymy go z tej samej domyślnej prędkości co
    // reszta MVP (25 km/h, jak profil "szosa").
    private const ASSUMED_SPEED_MPS = 25_000 / 3600;

    /** Strona plannera — /planer (wymaga logowania: prywatny szkicownik usera). */
    public static function index(): void
    {
        $user = Auth::requireLogin();

        $loadId = isset($_GET['id']) ? (int) $_GET['id'] : null;
        $existing = $loadId ? PlannedRoute::findForUser($loadId, $user->id) : null;

        // Liczniki pod nazwami źródeł („14 przejazdów", „38 tras w katalogu")
        // liczone z TYCH SAMYCH zbiorów, z których planer bierze linie — źródło,
        // które pokazuje 0, naprawdę nic nie wniesie do trasy.
        $myRides = 0;
        foreach (TileSource::tracks('me') as $group) {
            if (($group['style'] ?? '') === 'real') {
                $myRides += count($group['hashes']);
            }
        }
        $knownRoutes = count(KnownRoute::activeGeometryHashes());

        View::render('web', 'planner', [
            'title'       => __('Planer tras — ridemore.bike'),
            'description' => __('Wyznacz trasę rowerową, wykorzystując znane trasy i skarby Ridemore.'),
            // ridemoreCreateMap (z gpx-map.js, dołączonego przez leafletMapHead())
            // wystarcza — ta sama fabryka bazowej mapy co reszta serwisu (te same
            // kafle, fullscreen, „Tu jestem"), bez warstwy mgły odkryć, która
            // planerowi nie służy.
            'extraHead'   => Support::leafletMapHead(),
            'existingRouteId' => $existing ? (int) $existing['id'] : null,
            'csrfToken'   => Csrf::token(),
            'myRidesCount'    => $myRides,
            'knownRoutesCount' => $knownRoutes,
            // Przyciski profilu = aktywne typy rowerów ze słownika (panel „Planer"
            // ustawia ich prędkość, zasady warstwy i profil silnika).
            'bikeTypes'   => BikeType::all(),
            // PRAWDZIWE kafle, TE SAME co /odkrycia/spolecznosc (2026-09-18,
            // zgłoszenie usera: „po co tworzysz coś extra, jeśli masz już
            // gotowe rozwiązanie" — poprzednia wersja rysowała znane trasy
            // linią ze ŚRODKÓW HEKSÓW (KnownRoute::geometryInBounds,
            // udokumentowane jako „NIE ZASILA JUŻ MAPY" od 2026-08-20) i
            // popularność własnymi heksami z /api/discovery/cells zamiast
            // reużyć gotową warstwę „Ślady" z prawdziwą geometrią i gradientem
            // krycia. `TileCache::urlTemplate()` buduje SZABLON z {z}/{x}/{y}
            // i `?v=` z `tile_epochs` — Leaflet dociąga kafle sam przy
            // przesuwaniu mapy, bez żadnego dodatkowego zapytania AJAX.
            // `me` — ten sam klucz co osobista mapa /odkrycia, właściciel
            // dostaje PEŁNĄ (nieprzyciętą) geometrię, bo to jego własne przejazdy.
            'trackTiles'  => [
                'kr'  => TileCache::urlTemplate(TileSource::LAYER_TRACKS, 'kr'),
                'all' => TileCache::urlTemplate(TileSource::LAYER_TRACKS, 'all'),
                'me'  => TileCache::urlTemplate(TileSource::LAYER_TRACKS, 'me'),
            ],
        ]);
    }

    /** GET /przejazd-planer/{id}/gpx — eksport zapisanej trasy. */
    public static function gpx(string $id): void
    {
        $user = Auth::requireLogin();
        $route = PlannedRoute::findForUser((int) $id, $user->id);
        if ($route === null) {
            http_response_code(404);
            echo __('Nie znaleziono trasy.');
            return;
        }

        $geometry = json_decode((string) $route['geometry_json'], true);
        $points = [];
        foreach (($geometry['points'] ?? []) as $c) {
            if (isset($c[0], $c[1])) {
                $points[] = ['lat' => (float) $c[0], 'lon' => (float) $c[1]];
            }
        }

        header('Content-Type: application/gpx+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="trasa-' . (int) $route['id'] . '.gpx"');
        echo Gpx::fromPoints($points, (string) $route['name']);
    }

    // ------------------------------------------------------------------
    // AJAX (wołane z api/routes.php — closure tam robi auth/CSRF/dekodowanie
    // JSON-a i przekazuje gotową tablicę tutaj; ten kontroler nic nie wie
    // o $_POST/nagłówkach, tylko o kształcie danych).
    // ------------------------------------------------------------------

    /**
     * Przeliczenie trasy — jeden segment na parę kolejnych waypointów.
     *
     * Wejście: `waypoints` (kolejność = kolejność przejazdu), `sources`
     * ({mine, known, community} — zaznaczone źródła), `autoJoin` (przełącznik
     * „Dołączaj dłuższe odcinki Ridemore"), `base` ({points, label} — konkretna
     * trasa albo GPX; gdy jest, wygrywa ze źródłami).
     *
     * Każdy segment w odpowiedzi niesie `ridemore` — kawałki swojej geometrii
     * (indeksy w `coords`) pochodzące z danych Ridemore, z nazwą źródła — i ich
     * długość `ridemoreM`; reszta to OSM. Z tego UI rysuje obwódkę odcinków
     * Ridemore i udział „Ridemore X% · OSM Y%".
     */
    public static function calculate(int|array $userId, array $body = []): array
    {
        // Kompatybilność czystych testów kontrolera sprzed konfiguracji usera;
        // prawdziwy endpoint zawsze przekazuje jawne id zalogowanej osoby.
        if (is_array($userId)) {
            $body = $userId;
            $userId = Auth::user()?->id ?? 0;
        }
        $waypoints = self::sanitizeWaypointsForRouting($body['waypoints'] ?? []);
        if (count($waypoints) < 2) {
            return ['success' => false, 'error' => __('Potrzebne są przynajmniej dwa punkty.')];
        }

        $base = self::sanitizeBase($body['base'] ?? null);
        $bike = self::bikeFor($body['profile'] ?? null, $userId, $body['routing'] ?? null);
        $segments = $base !== null
            ? self::baseSegments($waypoints, $base, $bike)
            : self::sourceSegments(
                $waypoints, self::sanitizeSources($body['sources'] ?? null), !empty($body['autoJoin']), $bike
            );
        if ($segments === null) {
            return ['success' => false, 'error' => __('Nie udało się wyznaczyć trasy — spróbuj ponownie za chwilę.')];
        }

        return [
            'success'     => true,
            'segments'    => $segments,
            'distanceKm'  => round(array_sum(array_column($segments, 'distanceM')) / 1000, 1),
            'durationMin' => (int) round(array_sum(array_column($segments, 'durationS')) / 60),
            'routing'     => [
                'character' => $bike['routing']['character'],
                'configId' => $bike['routingConfigId'],
                'fingerprint' => RoutingPreferences::fingerprint($bike['routing']),
            ],
        ];
    }

    /** Zapis (nowej albo istniejącej, gdy podano `routeId` należący do usera) trasy. */
    public static function save(int $userId, array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $waypoints = self::sanitizeWaypointsForStorage($body['waypoints'] ?? []);
        $coords = self::sanitizeCoords($body['coords'] ?? null);

        if ($name === '' || count($waypoints) < 2 || $coords === null) {
            return ['success' => false, 'error' => __('Brakuje nazwy trasy albo policzonej geometrii.')];
        }

        $bike = self::bikeFor($body['profile'] ?? null, $userId, $body['routing'] ?? null);
        $elevation = ElevationLookup::forRoute($coords);

        $input = [
            'name'           => mb_substr($name, 0, 200),
            'waypoints_json' => json_encode($waypoints, JSON_UNESCAPED_UNICODE),
            'geometry_json'  => json_encode(['points' => $coords], JSON_UNESCAPED_UNICODE),
            'distance_km'    => round(RouteSnap::lineLengthM($coords) / 1000, 2),
            'ascent_m'       => $elevation['ascentM'] ?? null,
            'descent_m'      => $elevation['descentM'] ?? null,
            'duration_min'   => isset($body['durationMin']) && is_numeric($body['durationMin']) ? (int) $body['durationMin'] : null,
            'engine'         => RoutingProxy::engine(),
            'profile'        => $bike['code'] !== '' ? $bike['code'] : 'cycling',
            'routing_config_id' => $bike['routingConfigId'],
            'routing_preferences_json' => json_encode(
                $bike['routing'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            ),
        ];

        $routeId = isset($body['routeId']) && is_numeric($body['routeId']) ? (int) $body['routeId'] : null;
        if ($routeId !== null && PlannedRoute::update($routeId, $userId, $input)) {
            return ['success' => true, 'routeId' => $routeId];
        }

        $newId = PlannedRoute::save($userId, $input);
        return ['success' => true, 'routeId' => $newId];
    }

    /** Wczytanie zapisanej trasy do edycji w plannerze. */
    public static function load(int $id, int $userId): array
    {
        $route = PlannedRoute::findForUser($id, $userId);
        if ($route === null) {
            return ['success' => false, 'error' => __('Nie znaleziono trasy.')];
        }

        return [
            'success' => true,
            'route'   => [
                'id'          => (int) $route['id'],
                'name'        => $route['name'],
                'waypoints'   => json_decode((string) $route['waypoints_json'], true) ?: [],
                'distanceKm'  => (float) $route['distance_km'],
                'ascentM'     => $route['ascent_m'] !== null ? (int) $route['ascent_m'] : null,
                'descentM'    => $route['descent_m'] !== null ? (int) $route['descent_m'] : null,
                'durationMin' => $route['duration_min'] !== null ? (int) $route['duration_min'] : null,
                'profile'     => (string) $route['profile'],
                'routing'     => self::storedRouting($route),
            ],
        ];
    }

    /**
     * Skarby widoczne na mapie plannera (punkty pod klik „Po drodze" / „Jedź tu")
     * — jedyne, co ma sens dociągać przez AJAX po bboxie przy przesuwaniu mapy.
     * Linie źródeł (znane trasy, przejazdy) idą kaflami, bez tego endpointu.
     */
    public static function layers(array $bounds, ?int $viewerId): array
    {
        return [
            'success'   => true,
            'treasures' => array_map(static fn(array $t): array => [
                'id'   => (int) $t['id'],
                'name' => $t['name'],
                'lat'  => (float) $t['lat'],
                'lon'  => (float) $t['lon'],
            ], Treasure::inBounds($bounds, $viewerId, 200)),
        ];
    }

    /**
     * „Wybierz konkretną trasę" — wyszukiwarka. Szuka WYŁĄCZNIE wśród źródeł,
     * które Ridemore już ma: własne przejazdy solo usera (`RiderActivity::
     * searchSoloForUser` — ta sama metoda, co panel „Moje przejazdy") i aktywne
     * znane trasy (`KnownRoute::search` — ta sama metoda, co katalog /trasy).
     * Nigdy cudze przejazdy (§27).
     */
    public static function searchSources(int $userId, string $type, string $q): array
    {
        if ($type === 'przejazd') {
            $result = RiderActivity::searchSoloForUser($userId, ['szukaj' => $q]);
            return ['success' => true, 'items' => array_map(static function (array $r): array {
                return [
                    'ref'   => (string) $r['id'],
                    'label' => (string) ($r['name'] ?: $r['garmin_name'] ?: $r['ride_date']),
                    'meta'  => $r['ride_date'] . ' · ' . number_format((float) $r['distance_km'], 1) . ' km',
                ];
            }, $result['items'])];
        }
        if ($type === 'trasa') {
            $result = KnownRoute::search(['szukaj' => $q, 'filtr' => 'aktywne']);
            return ['success' => true, 'items' => array_map(static function (array $r): array {
                return [
                    'ref'   => (string) $r['slug'],
                    'label' => (string) $r['name'],
                    'meta'  => number_format((float) $r['distance_km'], 1) . ' km',
                    'color' => KnownRoute::colorOf($r['color_index'] ?? null),
                ];
            }, $result['items'])];
        }
        return ['success' => false, 'error' => __('Nieznany typ źródła.')];
    }

    /** Pełna geometria wybranej konkretnej trasy — baza planowania (`base` w `calculate()`). */
    public static function sourceGeometry(string $type, string $ref, int $userId): array
    {
        $color = null;
        if ($type === 'przejazd') {
            $points = self::pointsFromOwnRide((int) $ref, $userId);
            $label = __('Przejazd');
        } elseif ($type === 'trasa') {
            $route = KnownRoute::findBySlug($ref);
            $points = $route !== null ? KnownRoute::linePoints($route) : [];
            $label = $route['name'] ?? __('Znana trasa');
            $color = $route !== null ? KnownRoute::colorOf($route['color_index'] ?? null) : null;
        } else {
            return ['success' => false, 'error' => __('Nieznany typ źródła.')];
        }

        if (count($points) < 2) {
            return ['success' => false, 'error' => __('Ten ślad nie ma geometrii do wczytania.')];
        }
        return ['success' => true, 'points' => $points, 'label' => $label, 'color' => $color];
    }

    /**
     * Wgranie WŁASNEGO pliku GPX jako bazy trasy — świadomie EFEMERYCZNE:
     * plik jest tylko parsowany (`Utils\Gpx::parse`, jedyny parser w
     * serwisie) i punkty wracają do przeglądarki, nic nie ląduje trwale na
     * dysku (w odróżnieniu od GPX-a wydarzenia/znanej trasy, które
     * `Utils\Upload` promuje do publicznego katalogu). PHP sam sprząta plik
     * tymczasowy po żądaniu.
     *
     * @param array{tmp_name?:string,name?:string} $file jeden wpis z $_FILES
     */
    public static function importGpx(array $file): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => __('Brak pliku.')];
        }
        try {
            $parsed = Gpx::parse($file['tmp_name']);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $points = array_map(static fn(array $p): array => [$p['lat'], $p['lon']], $parsed['points']);
        return ['success' => true, 'points' => $points, 'label' => (string) ($file['name'] ?? __('Wgrany plik GPX'))];
    }

    // ------------------------------------------------------------------
    // Wewnętrzne — tryb „Wszystkie zaznaczone źródła"
    // ------------------------------------------------------------------

    /** @return list<array>|null null, gdy OSRM nie wyznaczył któregoś odcinka */
    private static function sourceSegments(array $waypoints, array $sources, bool $autoJoin, array $bike): ?array
    {
        $natural = [];
        for ($i = 0, $n = count($waypoints) - 1; $i < $n; $i++) {
            $route = RoutingProxy::route([$waypoints[$i], $waypoints[$i + 1]], $bike['profile'], $bike['baseUrl']);
            if ($route === null) {
                return null;
            }
            $natural[] = $route;
        }

        if (!in_array(true, $sources, true)) {
            return array_map(static fn(array $route): array => self::preferSegment($route, []), $natural);
        }
        if (!$autoJoin) {
            // Samo przyciąganie: trasa OSM jedzie po liniach, wzdłuż których i tak biegnie.
            $lines = RidemoreCorridors::lines($sources, self::corridorTiles($natural), self::MAX_LINES_PER_SOURCE);
            return array_map(static fn(array $route): array => self::preferSegment($route, $lines), $natural);
        }

        // Warstwa Ridemore — odcinek po odcinku, póki starcza budżetu czasu.
        // Wybrane korytarze są stanem przejścia dla kolejnego odcinka:
        // planer nie porzuca tej samej drogi tylko dlatego, że waypoint dzieli
        // ją na dwie osobno liczone pary punktów.
        $deadline = microtime(true) + self::LAYER_BUDGET_S;
        $fingerprint = RidemoreCorridors::fingerprint();
        $segments = [];
        $preferredKeys = [];
        foreach ($natural as $i => $route) {
            $segment = microtime(true) < $deadline
                ? self::ridemoreSegment($waypoints[$i], $waypoints[$i + 1], $route, $sources, $fingerprint, $bike, $preferredKeys)
                : self::preferSegment($route, []);
            if (array_key_exists('_continuity', $segment)) {
                $preferredKeys = $segment['_continuity'];
                unset($segment['_continuity']);
            }
            $segments[] = $segment;
        }
        return $segments;
    }

    /**
     * Jeden odcinek przez warstwę Ridemore (Utils\RidemoreRouting). Wynik
     * zapamiętany na dobę pod kluczem z punktów, źródeł, odcisku danych
     * i parametrów warstwy — przeliczenie całej trasy po przesunięciu jednego
     * punktu nie liczy od nowa odcinków, których zmiana nie dotyczy.
     */
    private static function ridemoreSegment(
        array $from,
        array $to,
        array $baseline,
        array $sources,
        string $fingerprint,
        array $bike,
        array $preferredKeys = []
    ): array
    {
        $self = ($bike['userId'] ?? 0) > 0 ? 'u' . $bike['userId'] : null;
        $cacheKey = hash('sha256', serialize([
            round($from['lat'], 5), round($from['lng'], 5), round($to['lat'], 5), round($to['lng'], 5),
            $sources, $sources['mine'] ? $self : null, $fingerprint, RidemoreRouting::PARAMS, $bike, $preferredKeys,
        ]));
        $cached = self::layerCacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $area = RidemoreRouting::area($from, $to, (float) $baseline['distanceM']);
        $lines = $area !== null ? RidemoreCorridors::lines($sources, $area['tiles'], self::LAYER_LINES_PER_SOURCE, true) : [];
        $ref = $area !== null && $sources['community'] ? RidemoreCorridors::localReference($area['bounds']) : ['riders' => 0.0, 'passes' => 0.0];

        $result = RidemoreRouting::route(
            $from, $to, $baseline, $lines, $ref, $sources, $self,
            static fn(array $points, array $src, array $dst): ?array => RoutingProxy::table($points, $src, $dst, $bike['profile'], $bike['baseUrl']),
            static fn(array $waypoints): ?array => RoutingProxy::routeLegs($waypoints, $bike['profile'], $bike['baseUrl']),
            self::ASSUMED_SPEED_MPS,
            [
                'profile' => $bike['rules'],
                'routingPreferences' => $bike['routing'],
                'preferredKeys' => $preferredKeys,
            ]
        );
        // Wygrała trasa bazowa: dalej jedzie po liniach, wzdłuż których i tak biegnie.
        $segment = $result['segment'] ?? self::preferSegment($baseline, $lines);
        $segment['variant'] = $result['variant'];
        // Gdy baza już biegnie korytarzem, route() słusznie jej nie zmienia;
        // stan zachowujemy, aby kolejny waypoint nie zrywał ciągłości.
        $segment['_continuity'] = $result['continuityKeys'] ?? $preferredKeys;

        if (empty($result['degraded'])) {
            self::layerCachePut($cacheKey, $segment);
        }
        return $segment;
    }

    /** Kafle indeksu, przez które idą trasy OSM, plus sąsiednie — korytarz przyciągania. */
    private static function corridorTiles(array $natural): array
    {
        $shift = TileGrid::shiftFor(TileGrid::INDEX_Z) + 8;
        $corridor = [];
        foreach ($natural as $route) {
            [$pts] = RouteSnap::densify(RouteSnap::toPixels($route['coords']), (1 << $shift) / 2);
            foreach ($pts as [$x, $y]) {
                $tx = $x >> $shift;
                $ty = $y >> $shift;
                for ($dx = -1; $dx <= 1; $dx++) {
                    for ($dy = -1; $dy <= 1; $dy++) {
                        $corridor[(($tx + $dx) << 32) | ($ty + $dy)] = true;
                    }
                }
            }
        }
        return $corridor;
    }

    // --- Pamięć wyniku odcinka warstwy — ten sam wzorzec co Utils\RoutingProxy ---

    private static function layerCacheDir(): string
    {
        $dir = CORE_PATH . '/../storage/planner-cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function layerCacheGet(string $key): ?array
    {
        $file = self::layerCacheDir() . '/' . $key . '.json';
        if (!is_file($file) || (time() - filemtime($file)) > self::LAYER_CACHE_TTL_S) {
            return null;
        }
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) && isset($data['coords']) ? $data : null;
    }

    private static function layerCachePut(string $key, array $segment): void
    {
        @file_put_contents(self::layerCacheDir() . '/' . $key . '.json', json_encode($segment));
        if (random_int(1, 50) === 1) {
            $files = glob(self::layerCacheDir() . '/*.json') ?: [];
            if (count($files) > self::LAYER_CACHE_MAX_FILES) {
                usort($files, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
                foreach (array_slice($files, 0, count($files) - self::LAYER_CACHE_MAX_FILES) as $old) {
                    @unlink($old);
                }
            }
        }
    }

    /** Jeden odcinek: trasa OSM z wklejonymi odcinkami Ridemore (RouteSnap::preferredRuns, samo przyciąganie). */
    private static function preferSegment(array $route, array $lines): array
    {
        $plain = [
            'coords'    => $route['coords'],
            'distanceM' => $route['distanceM'],
            'durationS' => $route['durationS'],
            'ridemoreM' => 0,
            'ridemore'  => [],
        ];
        if (!$lines || count($route['coords']) < 2) {
            return $plain;
        }

        $mid = $route['coords'][intdiv(count($route['coords']), 2)];
        $mpp = TileGrid::metersPerPixel(TileGrid::STORE_Z, (float) $mid[0]);
        $threshold = self::MATCH_THRESHOLD_M / $mpp;
        [$dense, $orig] = RouteSnap::densify(RouteSnap::toPixels($route['coords']), self::DENSIFY_M / $mpp);
        $xs = array_column($dense, 0);
        $ys = array_column($dense, 1);
        $bbox = [
            (int) floor(min($xs) - $threshold), (int) floor(min($ys) - $threshold),
            (int) ceil(max($xs) + $threshold), (int) ceil(max($ys) + $threshold),
        ];

        $local = [];
        foreach ($lines as $line) {
            if ($line['maxPx'] >= $bbox[0] && $line['minPx'] <= $bbox[2] && $line['maxPy'] >= $bbox[1] && $line['minPy'] <= $bbox[3]) {
                $local[] = $line;
            }
        }
        if (!$local) {
            return $plain;
        }

        $flats = array_column($local, 'flat');
        $cell = (int) ceil($threshold);
        $near = RouteSnap::nearestAlong(
            $dense, $flats, RouteSnap::gridIndex($flats, $cell, $bbox), $cell, $threshold, self::CONTINUITY_M / $mpp
        );
        $runs = RouteSnap::preferredRuns(RouteSnap::cumulative($dense), array_column($local, 'rank'), $flats, $near, [
            'minRunPx'    => self::MIN_RUN_M / $mpp,
            'snapRatio'   => self::SNAP_RATIO,
            'joinRatio'   => null,
            'joinSlackPx' => 0.0,
            'jumpSlackPx' => self::JUMP_SLACK_M / $mpp,
            'gapPts'      => (int) ceil(self::GAP_M / self::DENSIFY_M),
        ]);
        if (!$runs) {
            return $plain;
        }

        $assembled = RouteSnap::assemble($dense, $orig, $runs, $flats);
        $coords = $assembled['coords'];
        $distanceM = RouteSnap::lineLengthM($coords);
        $ridemoreM = 0.0;
        $pieces = [];
        foreach ($assembled['pieces'] as $p) {
            $ridemoreM += RouteSnap::lineLengthM(array_slice($coords, $p['from'], $p['to'] - $p['from'] + 1));
            $pieces[] = [
                'source' => $local[$p['line']]['source'],
                'label'  => $local[$p['line']]['label'],
                'from'   => $p['from'],
                'to'     => $p['to'],
            ];
        }
        $osmShare = $route['distanceM'] > 0 ? max(0.0, $distanceM - $ridemoreM) / $route['distanceM'] : 0.0;

        return [
            'coords'    => $coords,
            'distanceM' => $distanceM,
            'durationS' => $route['durationS'] * $osmShare + $ridemoreM / self::ASSUMED_SPEED_MPS,
            'ridemoreM' => (int) round($ridemoreM),
            'ridemore'  => $pieces,
        ];
    }

    // ------------------------------------------------------------------
    // Wewnętrzne — tryb bazy (konkretna trasa albo GPX)
    // ------------------------------------------------------------------

    /** @return list<array>|null */
    private static function baseSegments(array $waypoints, array $base, array $bike): ?array
    {
        $mpp = TileGrid::metersPerPixel(TileGrid::STORE_Z, (float) $waypoints[0]['lat']);
        $projection = RouteSnap::orderedProjection(
            RouteSnap::toFlat($base['points']),
            RouteSnap::toPixels(array_map(static fn(array $w): array => [$w['lat'], $w['lng']], $waypoints)),
            self::BASE_TOLERANCE_M / $mpp
        );

        $segments = [];
        for ($i = 0, $n = count($waypoints) - 1; $i < $n; $i++) {
            $segment = self::baseSegment(
                $waypoints[$i], $waypoints[$i + 1], $projection['flat'],
                $projection['indices'][$i], $projection['indices'][$i + 1], $base['label'], $bike
            );
            if ($segment === null) {
                return null;
            }
            $segments[] = $segment;
        }
        return $segments;
    }

    /**
     * Odcinek po bazie: OSM do rzutu punktu $from na bazę, baza do rzutu $to,
     * OSM do $to. Punkt z dala od bazy (np. skarb „po drodze") daje przez to
     * zjazd z bazy i powrót — bez osobnego algorytmu.
     */
    private static function baseSegment(array $from, array $to, array $flat, int $vFrom, int $vTo, string $label, array $bike): ?array
    {
        if ($vTo <= $vFrom) {
            // Oba punkty przypięte do tego samego miejsca bazy — baza nic tu nie wnosi.
            $route = RoutingProxy::route([$from, $to], $bike['profile'], $bike['baseUrl']);
            return $route === null ? null : [
                'coords' => $route['coords'], 'distanceM' => $route['distanceM'], 'durationS' => $route['durationS'],
                'ridemoreM' => 0, 'ridemore' => [],
            ];
        }

        $slice = [];
        for ($v = $vFrom; $v <= $vTo; $v++) {
            [$lat, $lon] = TileGrid::toLatLon($flat[2 * $v], $flat[2 * $v + 1]);
            $slice[] = [round($lat, 6), round($lon, 6)];
        }
        $entry = $slice[0];
        $exit = $slice[count($slice) - 1];
        $durationS = 0.0;

        $coords = [[$from['lat'], $from['lng']]];
        if (RouteSnap::haversineM($from['lat'], $from['lng'], $entry[0], $entry[1]) > self::APPROACH_MIN_M) {
            $in = RoutingProxy::route([$from, ['lat' => $entry[0], 'lng' => $entry[1]]], $bike['profile'], $bike['baseUrl']);
            if ($in !== null) {
                $coords = $in['coords'];
                $durationS += $in['durationS'];
            }
        }
        $pieceFrom = count($coords) - 1;
        $coords = array_merge($coords, $slice);
        $pieceTo = count($coords) - 1;
        $sliceM = RouteSnap::lineLengthM($slice);
        $durationS += $sliceM / self::ASSUMED_SPEED_MPS;

        $out = null;
        if (RouteSnap::haversineM($exit[0], $exit[1], $to['lat'], $to['lng']) > self::APPROACH_MIN_M) {
            $out = RoutingProxy::route([['lat' => $exit[0], 'lng' => $exit[1]], $to], $bike['profile'], $bike['baseUrl']);
        }
        if ($out !== null) {
            $coords = array_merge($coords, array_slice($out['coords'], 1));
            $durationS += $out['durationS'];
        } else {
            $coords[] = [$to['lat'], $to['lng']];
        }

        return [
            'coords'    => $coords,
            'distanceM' => RouteSnap::lineLengthM($coords),
            'durationS' => $durationS,
            'ridemoreM' => (int) round($sliceM),
            'ridemore'  => [['source' => 'base', 'label' => $label, 'from' => $pieceFrom, 'to' => $pieceTo]],
        ];
    }

    /**
     * Realna geometria WŁASNEGO przejazdu jako [lat,lon] — ten sam łańcuch
     * co `KnownRoute::linePoints()` (gpx_url → GpxGeometry → TileGrid), tylko
     * z bramką własności zamiast publicznego dostępu: planer pokazuje tu
     * WYŁĄCZNIE przejazdy pytającego (ta sama zasada co §27 — plik solo jest
     * surowy i zaczyna się pod domem), nigdy cudze, nawet przycięte.
     *
     * @return list<array{0:float,1:float}>
     */
    private static function pointsFromOwnRide(int $activityId, int $userId): array
    {
        $ride = RiderActivity::findForTrack($activityId);
        if ($ride === null || (int) $ride['user_id'] !== $userId) {
            return [];
        }
        $gpxPath = Support::rideGpxPath($ride, $userId);
        if ($gpxPath === null) {
            return [];
        }
        $hash = GpxGeometry::ensure(TileSource::absolutePath($gpxPath));
        if ($hash === null) {
            return [];
        }
        $geom = GpxGeometry::load([$hash])[$hash] ?? null;
        if ($geom === null || empty($geom['pts'])) {
            return [];
        }

        $points = [];
        $pts = $geom['pts'];
        for ($i = 0; $i < count($pts); $i += 2) {
            $points[] = TileGrid::toLatLon((int) $pts[$i], (int) $pts[$i + 1]);
        }
        return $points;
    }

    /**
     * Typ roweru z przycisków planera (kod ze słownika `bike_type`; nieznany
     * albo brak = pierwszy aktywny typ) → zasady warstwy Ridemore i profil
     * silnika routingu z konfiguracji typu (panel „Planer").
     *
     * @return array{code:string,rules:array,profile:string,baseUrl:?string,routing:array,routingConfigId:?int,userId:int}
     */
    private static function bikeFor(mixed $raw, int $userId, mixed $routingRaw = null): array
    {
        $type = (is_string($raw) ? BikeType::byCode($raw) : null) ?? (BikeType::all()[0] ?? null);
        $engine = BikeType::engineProfile($type, RoutingProxy::engine());
        $routingRaw = is_array($routingRaw) ? $routingRaw : [];
        $config = null;
        if (isset($routingRaw['configId']) && is_numeric($routingRaw['configId'])) {
            $candidate = PlannerRoutingConfig::findForUser((int) $routingRaw['configId'], $userId);
            if ($candidate !== null && $candidate['bikeProfile'] === ($type['code'] ?? '')) {
                $config = $candidate;
            }
        }
        if ($config === null && !array_key_exists('character', $routingRaw)
            && !array_key_exists('preferences', $routingRaw) && $type !== null) {
            $config = PlannerRoutingConfig::defaultForUser($userId, (int) $type['id']);
        }
        // Klient może przesłać jeszcze niezapisane zmiany wybranej, należącej
        // do niego konfiguracji — mają działać od razu przed kliknięciem Zapisz.
        $character = $routingRaw['character'] ?? ($config['character'] ?? 'balanced');
        $overrides = $routingRaw['preferences'] ?? ($config['preferences'] ?? []);
        return [
            'code'    => (string) ($type['code'] ?? ''),
            'rules'   => BikeType::plannerRules($type),
            'profile' => $engine['profile'],
            'baseUrl' => $engine['baseUrl'],
            'routing' => BikeType::routingPreferences($type, $character, $overrides),
            'routingConfigId' => $config['id'] ?? null,
            'userId' => $userId,
        ];
    }

    /** Snapshot preferencji zapisanej trasy; stare rekordy dostają preset profilu. */
    private static function storedRouting(array $route): array
    {
        $snapshot = json_decode((string) ($route['routing_preferences_json'] ?? ''), true);
        if (is_array($snapshot)) {
            return [
                // Snapshot opisuje historyczną trasę, nie bieżący stan
                // nazwanej konfiguracji. Nie podpinamy go ponownie pod jej id,
                // bo „Zapisz zmiany” nadpisałoby nowszy profil starymi danymi.
                'configId' => null,
                'character' => RoutingPreferences::character($snapshot['character'] ?? null),
                'preferences' => $snapshot,
            ];
        }
        $type = BikeType::byCode((string) ($route['profile'] ?? ''));
        $preferences = BikeType::routingPreferences($type);
        return ['configId' => null, 'character' => 'balanced', 'preferences' => $preferences];
    }

    /** @return array{mine:bool,known:bool,community:bool} */
    private static function sanitizeSources(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        return [
            'mine'      => !empty($raw['mine']),
            'known'     => !empty($raw['known']),
            'community' => !empty($raw['community']),
        ];
    }

    /**
     * Baza z przeglądarki — punkty przychodzą wprost od klienta (znana trasa
     * albo własny przejazd pobrane wcześniej przez `sourceGeometry()`, albo
     * wgrany GPX). Serwer nie musi sprawdzać własności: punkty idą wyłącznie
     * do matematyki rzutowania, nie do żadnego zapisu.
     *
     * @return array{points:list<array{0:float,1:float}>,label:string}|null
     */
    private static function sanitizeBase(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_array($raw['points'] ?? null) || count($raw['points']) > self::MAX_BASE_POINTS) {
            return null;
        }
        $points = self::sanitizeCoords($raw['points']);
        if ($points === null) {
            return null;
        }
        $label = is_string($raw['label'] ?? null) ? mb_substr(strip_tags($raw['label']), 0, 120) : '';
        return ['points' => $points, 'label' => $label];
    }

    /** @return list<array{lat:float,lng:float}> */
    private static function sanitizeWaypointsForRouting(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, self::MAX_WAYPOINTS) as $wp) {
            if (!is_array($wp) || !isset($wp['lat'], $wp['lng']) || !is_numeric($wp['lat']) || !is_numeric($wp['lng'])) {
                continue;
            }
            $out[] = ['lat' => (float) $wp['lat'], 'lng' => (float) $wp['lng']];
        }
        return $out;
    }

    /** Jak wyżej, ale zachowuje typ/etykietę punktu (do ponownej edycji) — z białą listą typów. */
    private static function sanitizeWaypointsForStorage(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $allowedTypes = ['start', 'via', 'end', 'treasure'];
        $out = [];
        foreach (array_slice($raw, 0, self::MAX_WAYPOINTS) as $wp) {
            if (!is_array($wp) || !isset($wp['lat'], $wp['lng']) || !is_numeric($wp['lat']) || !is_numeric($wp['lng'])) {
                continue;
            }
            $type = is_string($wp['type'] ?? null) && in_array($wp['type'], $allowedTypes, true) ? $wp['type'] : 'via';
            $label = is_string($wp['label'] ?? null) ? mb_substr(strip_tags($wp['label']), 0, 120) : '';
            $out[] = ['lat' => (float) $wp['lat'], 'lng' => (float) $wp['lng'], 'type' => $type, 'label' => $label];
        }
        return $out;
    }

    /** @return list<array{0:float,1:float}>|null */
    private static function sanitizeCoords(mixed $raw): ?array
    {
        if (!is_array($raw) || count($raw) < 2) {
            return null;
        }
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c) || !isset($c[0], $c[1]) || !is_numeric($c[0]) || !is_numeric($c[1])) {
                return null;
            }
            $out[] = [(float) $c[0], (float) $c[1]];
        }
        return $out;
    }
}
