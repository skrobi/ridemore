<?php
// core/Utils/RoadSurfaceDetector.php
namespace Utils;

// Port z old_web/RoadSurfaceDetector.php — analizuje trasę GPX przez Overpass
// API (OpenStreetMap) i liczy % długości per typ nawierzchni. W stosunku do
// oryginału: bez GeometryHelper (reużywa Gpx::simplify() — zwykłe równomierne
// przerzedzenie wystarcza tu tak samo jak "smart" wersja z krzywizną trasy),
// bez log_debug (błędy trafiają do error_log, cicho — brak wyniku detekcji
// NIE blokuje reszty formularza, patrz wywołanie w api/routes.php), punkty w
// formacie ['lat'=>,'lon'=>] (ten sam kształt co Gpx::parse(), zamiast GeoJSON
// [lon,lat] z oryginału).
//
// Świadomie WOLNE i zależne od zewnętrznego, darmowego API — chunking + sleep()
// między kawałkami to celowy compromis, żeby nie zbanować się na Overpass przy
// dłuższych trasach (patrz CHUNKING_STRATEGY). Wywołujący MUSI traktować null
// jako normalny, spodziewany wynik (offline Overpass, rate limit, trasa bez
// pokrycia OSM), nie błąd.
class RoadSurfaceDetector
{
    private const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';
    private const REQUEST_TIMEOUT = 30;
    // (dawne CHUNK_DELAY — pauza między kolejnymi kawałkami — usunięte
    // 2026-08-09 razem z sekwencyjną pętlą; uprzejmość wobec darmowego
    // Overpass zapewnia teraz limit równoległości MAX_PARALLEL.)

    // Zabezpieczenie przed PHP Fatal error "Allowed memory size exhausted" w
    // runBatch() (zaobserwowane realnie na /wydarzenia/{slug}/edytuj —
    // ten błąd NIE jest łapany przez try/catch(\Throwable) nigdzie wyżej w stosie,
    // bo wyczerpanie pamięci zabija proces zanim mechanizm wyjątków zdąży
    // zadziałać). Dwie niezależne bariery, każda sama w sobie wystarczająca:
    // 1) MAX_BBOX_DEGREES niżej — nie pozwala w ogóle WYSŁAĆ zapytania o
    //    absurdalnie duży obszar (typowy powód: pojedynczy błędny/oddalony punkt
    //    w GPX rozciąga bbox chunku na cały kontynent, Overpass próbuje zwrócić
    //    KAŻDĄ drogę z OSM w tym obszarze).
    // 2) MAX_RESPONSE_BYTES w runBatch() — na wypadek, gdyby mimo
    //    rozsądnego bbox odpowiedź i tak była nietypowo duża (gęsta zabudowa
    //    miejska w buforze) — json_decode() nigdy nie dostaje do przetworzenia
    //    surowego stringa większego niż ten limit.
    private const MAX_BBOX_DEGREES = 3.0; // ~330 km — z dużym zapasem ponad najdłuższy sensowny odcinek jednego chunku trasy rowerowej
    private const MAX_RESPONSE_BYTES = 25_000_000; // 25 MB surowego JSON-a z Overpass
    // Twardy budżet czasu CAŁEJ analizy (dopisany 2026-08-09). Bez niego długa
    // trasa potrafiła przekroczyć max_execution_time PHP i żądanie ginęło z
    // fatal errorem — zwracając HTML zamiast JSON-a, przez co front nie
    // dostawał NAWET dystansu i przewyższenia, które były już policzone.
    // Teraz po przekroczeniu budżetu przerywamy pobieranie kolejnych kawałków
    // i zwracamy wynik z tego, co zdążyliśmy zebrać (albo null) — czyli
    // dokładnie ten przypadek, który wywołujący i tak już obsługuje.
    private const MAX_ANALYSIS_SECONDS = 70;
    // Ile zapytań do Overpass naraz — patrz komentarz przy executeParallel().
    // DWA, nie trzy: przy trzech overpass-api.de zaczął odrzucać kawałki
    // (zmierzone: HTTP 504 i 429 na 2 z 3 zapytań), przez co wynik liczył się
    // z jednej trzeciej trasy — szybciej, ale CICHO mniej dokładnie, czyli
    // najgorszy możliwy kompromis. Publiczny Overpass udostępnia ~2 sloty na
    // adres IP i do tego się dostrajamy.
    private const MAX_PARALLEL = 2;
    // Odrzucony kawałek (504/429 — przeciążenie albo limit) ponawiamy RAZ, już
    // sekwencyjnie i po pauzie. Lepiej dołożyć kilkanaście sekund niż policzyć
    // nawierzchnię z fragmentu trasy i nic o tym nie powiedzieć.
    private const RETRY_PAUSE_SECONDS = 3;
    // 90 dni dla WYNIKU — tagi nawierzchni w OSM zmieniają się rzadko, a wynik
    // jest czysto pochodny (utrata pliku = najwyżej ponowne policzenie).
    private const CACHE_TTL_SECONDS = 90 * 24 * 3600;
    // ...ale PUSTKA (null) tylko na godzinę. "Nie udało się nic ustalić" ma DWIE
    // różne przyczyny, których nie umiemy rozróżnić: trasa naprawdę poza
    // pokryciem OSM (stan trwały) albo Overpass chwilowo przeciążony/limitujący
    // (stan przejściowy — realnie występuje, patrz HTTP 429 w logach). Gdyby
    // pustka leżała 90 dni, jedna awaria zewnętrznej usługi odbierałaby
    // nawierzchnię tej trasie na kwartał, bez żadnego sposobu odświeżenia.
    private const CACHE_TTL_EMPTY_SECONDS = 3600;

    // ZMIERZONE 2026-08-13 (zgloszenie usera: kreator nie rozpoznaje nawierzchni).
    // Przy trasie 160 km i chunk_size 500 Overpass zwracal ponad 26 MB na kawalek,
    // czyli WIECEJ niz MAX_RESPONSE_BYTES, a czesc zapytan konczyla sie 504.
    // Kazdy kawalek byl wtedy odrzucany i analiza konczyla sie na null - detektor
    // dzialal, tylko nigdy nie mial z czego policzyc wyniku.
    //
    // Powod jest geometryczny: chunk_size liczy PUNKTY, a nie obszar. 500 kolejnych
    // punktow rzadkiego sladu obejmuje wielokrotnie wieksza ramke niz 500 punktow
    // sladu gestego, wiec ten sam parametr znaczy co innego przy kazdym pliku.
    // Mniejsze kawalki na dluzszych trasach trzymaja ramke w rozsadnym rozmiarze;
    // liczba zapytan rosnie, ale MAX_PARALLEL i tak je ogranicza, a wynik jest.
    private const CHUNKING_STRATEGY = [
        ['max_km' => 25,  'max_points' => 2000, 'chunk_size' => 500, 'overlap' => 100],
        ['max_km' => 100, 'max_points' => 1500, 'chunk_size' => 300, 'overlap' => 60],
        ['max_km' => 300, 'max_points' => 1000, 'chunk_size' => 200, 'overlap' => 40],
        ['max_km' => PHP_INT_MAX, 'max_points' => 500, 'chunk_size' => 150, 'overlap' => 30],
    ];

    private const SURFACE_MAPPING = [
        'asphalt' => ['paved', 'asphalt', 'concrete', 'paving_stones', 'chipseal', 'sett', 'cobblestone', 'metal'],
        'gravel'  => ['gravel', 'fine_gravel', 'compacted', 'pebblestone', 'gravel_turf', 'rock'],
        'trail'   => ['unpaved', 'ground', 'dirt', 'grass', 'earth', 'sand', 'mud', 'wood', 'woodchips', 'snow', 'ice'],
    ];

    // $points: [['lat'=>float,'lon'=>float], ...] (jak z Gpx::parse()['points']).
    // Zwraca ['asphaltPct','gravelPct','trailPct'] (sumują się do 100) albo
    // null, gdy Overpass nie zwrócił nic użytecznego dla żadnego kawałka trasy.
    public static function analyze(array $points, float $distanceKm): ?array
    {
        if (empty($points)) {
            return null;
        }

        $strategy = self::chunkingStrategy($distanceKm);
        if (count($points) > $strategy['max_points']) {
            $points = Gpx::simplify($points, $strategy['max_points']);
        }

        $chunks = self::createChunks($points, $strategy['chunk_size'], $strategy['overlap']);

        // PAMIĘĆ PODRĘCZNA (2026-08-09) — klucz z geometrii PO uproszczeniu, nie
        // z bajtów pliku: ten sam ślad wyeksportowany dwa razy (inna nazwa,
        // inne metadane, ten sam przebieg) trafia w ten sam wpis. Realnie
        // zdarza się to często: poprawka w wydarzeniu i ponowne wgranie tego
        // samego GPX-a, albo ten sam ślad raz jako wariant, raz jako dzień etapu.
        $cacheKey = self::cacheKey($chunks);
        $cached = self::cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached['result']; // może być null — "sprawdzone, Overpass nic nie ma"
        }

        // RÓWNOLEGŁE zapytania zamiast sekwencyjnych (2026-08-09). Pomiar:
        // pojedyncze zapytanie do Overpass kosztuje ~25-30s NIEZALEŻNIE od
        // rozmiaru odpowiedzi (sprawdzone: 40-krotne zmniejszenie payloadu nie
        // skróciło czasu, zmiana instancji też nie). Czyli jedyne, co realnie
        // można odzyskać, to czekanie SUMOWANE po kawałkach: 3 x 25s + pauzy
        // ≈ 79s -> ~30s. Stąd curl_multi zamiast pętli z file_get_contents.
        // Sekwencyjne pauzy (CHUNK_DELAY) przestały być potrzebne — uprzejmość
        // wobec darmowego Overpass zapewnia teraz limit RÓWNOLEGŁOŚCI niżej.
        $queries = [];
        foreach ($chunks as $chunk) {
            if (!self::chunkBoundsAreSane($chunk)) {
                // Patrz komentarz przy MAX_BBOX_DEGREES — kawałek z absurdalnym
                // bbox pomijamy zamiast o niego pytać; reszta trasy leci dalej.
                error_log('RoadSurfaceDetector: pominięto kawałek trasy — bbox nierealistycznie duży (prawdopodobnie błędny punkt GPS w GPX)');
                continue;
            }
            $queries[] = self::buildOverpassQuery($chunk);
        }
        if (empty($queries)) {
            return null;
        }

        $allWays = [];
        $allNodes = [];
        foreach (self::executeParallel($queries) as $osmData) {
            foreach ($osmData['elements'] as $el) {
                if ($el['type'] === 'way') {
                    $allWays[$el['id']] = $el;
                } elseif ($el['type'] === 'node') {
                    $allNodes[$el['id']] = $el;
                }
            }
        }

        if (empty($allWays)) {
            // Zapisujemy TAKŻE pustkę — inaczej trasa spoza pokrycia OSM
            // odpytywałaby Overpass od nowa przy każdym wgraniu.
            self::cachePut($cacheKey, null);
            return null;
        }

        $result = self::calculateSurfacePercentages($allWays, $allNodes);
        self::cachePut($cacheKey, $result);
        return $result;
    }

    private static function chunkingStrategy(float $distanceKm): array
    {
        foreach (self::CHUNKING_STRATEGY as $strategy) {
            if ($distanceKm <= $strategy['max_km']) {
                return $strategy;
            }
        }
        return end(self::CHUNKING_STRATEGY);
    }

    private static function createChunks(array $points, int $chunkSize, int $overlap): array
    {
        $total = count($points);
        if ($total <= $chunkSize) {
            return [$points];
        }

        $chunks = [];
        $start = 0;
        while ($start < $total) {
            $end = min($start + $chunkSize, $total);
            $chunk = array_slice($points, $start, $end - $start);
            if (!empty($chunk)) {
                $chunks[] = $chunk;
            }
            $start += ($chunkSize - $overlap);
            if ($start >= $total - $overlap) {
                break;
            }
        }
        return $chunks;
    }

    // Patrz komentarz przy MAX_BBOX_DEGREES — odrzuca kawałek trasy, którego
    // rozpiętość lat/lon jest nierealistyczna dla jednego chunku GPX (do 500
    // punktów, patrz CHUNKING_STRATEGY), zanim zbudujemy i wyślemy zapytanie.
    private static function chunkBoundsAreSane(array $points): bool
    {
        $lats = array_column($points, 'lat');
        $lons = array_column($points, 'lon');
        if (!$lats || !$lons) {
            return false;
        }
        return (max($lats) - min($lats)) <= self::MAX_BBOX_DEGREES
            && (max($lons) - min($lons)) <= self::MAX_BBOX_DEGREES;
    }

    private static function buildOverpassQuery(array $points): string
    {
        $lats = array_column($points, 'lat');
        $lons = array_column($points, 'lon');

        $buffer = 0.01; // ~1km
        $minLat = min($lats) - $buffer;
        $maxLat = max($lats) + $buffer;
        $minLon = min($lons) - $buffer;
        $maxLon = max($lons) + $buffer;

        return "[out:json][timeout:25][bbox:{$minLat},{$minLon},{$maxLat},{$maxLon}];\n"
            . "(\n  way[\"highway\"];\n);\n"
            . "out body;\n>;\nout skel qt;";
    }

    // Wykonuje zapytania RÓWNOLEGLE, ale najwyżej MAX_PARALLEL naraz.
    // Limit jest tu z premedytacją: Overpass to darmowa, współdzielona usługa
    // i prosi o uprzejmość. Bez limitu trzy warianty trasy wgrywane naraz
    // dałyby 9 równoczesnych zapytań z jednego adresu — prosta droga do
    // odcięcia. Zwraca tablicę zdekodowanych odpowiedzi (bez tych, które
    // padły — awaria pojedynczego kawałka nie przerywa reszty, dokładnie jak
    // w wersji sekwencyjnej).
    private static function executeParallel(array $queries): array
    {
        $deadline = microtime(true) + self::MAX_ANALYSIS_SECONDS;
        $results = [];
        $failed = [];

        foreach (array_chunk($queries, self::MAX_PARALLEL) as $batch) {
            if (microtime(true) > $deadline) {
                error_log(sprintf('RoadSurfaceDetector: przerwano po %ds — pominięto część kawałków trasy', self::MAX_ANALYSIS_SECONDS));
                return $results;
            }
            [$ok, $bad] = self::runBatch($batch, (int) max(5, $deadline - microtime(true)));
            $results = array_merge($results, $ok);
            $failed = array_merge($failed, $bad);
        }

        // Ponowienie odrzuconych kawałków — pojedynczo i po pauzie. Overpass
        // odrzuca przy przeciążeniu/limicie, a te stany są chwilowe; bez tego
        // wynik liczyłby się z niepełnej trasy (zmierzone: przy równoległości 3
        // przepadały 2 z 3 kawałków, a użytkownik nie miał jak tego zauważyć).
        foreach ($failed as $q) {
            if (microtime(true) > $deadline) {
                error_log('RoadSurfaceDetector: brak czasu na ponowienie odrzuconych kawałków — wynik z niepełnej trasy');
                break;
            }
            sleep(self::RETRY_PAUSE_SECONDS);
            [$ok] = self::runBatch([$q], (int) max(5, $deadline - microtime(true)));
            $results = array_merge($results, $ok);
        }

        return $results;
    }

    // Zwraca [udane odpowiedzi, zapytania do ponowienia].
    private static function runBatch(array $queries, int $timeoutSeconds): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($queries as $i => $q) {
            $ch = curl_init(self::OVERPASS_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => 'data=' . urlencode($q),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => min(self::REQUEST_TIMEOUT, max(5, $timeoutSeconds)),
                // User-Agent/Accept nie są opcjonalne — bez nich Overpass
                // odpowiada 406 (zweryfikowane bezpośrednio, patrz historia pliku).
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: ridemore.bike RoadSurfaceDetector/1.0 (+https://ridemore.bike)',
                    'Accept: */*',
                ],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        $retry = [];
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($body === null || $body === false || $code < 200 || $code >= 300) {
                // 429 (limit) i 5xx (przeciążenie) są CHWILOWE — warto ponowić.
                // Inne kody to błąd zapytania i ponawianie nic nie zmieni.
                if ($code === 429 || $code >= 500 || $code === 0) {
                    $retry[] = $queries[$i];
                }
                error_log('RoadSurfaceDetector: kawałek trasy nieudany (HTTP ' . $code . ')'
                    . ($code === 429 || $code >= 500 || $code === 0 ? ' — do ponowienia' : ''));
                continue;
            }
            // Ta sama bariera pamięciowa co w wersji sekwencyjnej — json_decode
            // na dostatecznie dużym stringu potrafi wyczerpać limit pamięci PHP,
            // a takiego fatal errora nie łapie try/catch (patrz MAX_RESPONSE_BYTES).
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                error_log(sprintf('RoadSurfaceDetector: odpowiedź odrzucona — %d B > %d B', strlen($body), self::MAX_RESPONSE_BYTES));
                continue;
            }
            $data = json_decode($body, true);
            if (isset($data['elements']) && !empty($data['elements'])) {
                $out[] = $data;
            }
        }
        curl_multi_close($multi);
        return [$out, $retry];
    }

    // --- Pamięć podręczna wyniku -----------------------------------------
    // Plikowa, w storage/ — ten sam wzorzec co Utils\RateLimiter (bez nowych
    // zależności i bez tabeli w bazie; wynik jest czysto pochodny, więc jego
    // utrata to najwyżej ponowne policzenie).
    private static function cacheDir(): string
    {
        $dir = CORE_PATH . '/../storage/surface-cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    // Klucz z GEOMETRII (współrzędne po uproszczeniu, zaokrąglone do ~11 m),
    // nie z bajtów pliku — ten sam przebieg wyeksportowany ponownie da ten sam
    // klucz mimo innych metadanych/nazwy.
    private static function cacheKey(array $chunks): string
    {
        $parts = [];
        foreach ($chunks as $chunk) {
            foreach ($chunk as $p) {
                $parts[] = round($p['lat'], 4) . ',' . round($p['lon'], 4);
            }
        }
        return hash('sha256', implode(';', $parts));
    }

    private static function cacheGet(string $key): ?array
    {
        $file = self::cacheDir() . '/' . $key . '.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        // Rozróżniamy "brak wpisu" od "wpis mówiący: nic tu nie ma" — stąd
        // opakowanie w ['result' => ...], zamiast gołego null w pliku.
        if (!is_array($data) || !array_key_exists('result', $data)) {
            return null;
        }
        // Krótszy termin ważności dla pustki — patrz CACHE_TTL_EMPTY_SECONDS.
        $ttl = $data['result'] === null ? self::CACHE_TTL_EMPTY_SECONDS : self::CACHE_TTL_SECONDS;
        return (time() - filemtime($file)) > $ttl ? null : $data;
    }

    private static function cachePut(string $key, ?array $result): void
    {
        @file_put_contents(self::cacheDir() . '/' . $key . '.json', json_encode(['result' => $result]));
    }

    private static function calculateSurfacePercentages(array $ways, array $nodes): ?array
    {
        $lengths = ['asphalt' => 0.0, 'gravel' => 0.0, 'trail' => 0.0, 'unknown' => 0.0];

        foreach ($ways as $way) {
            if (empty($way['nodes'])) {
                continue;
            }
            $surface = strtolower(trim($way['tags']['surface'] ?? 'unknown'));
            $type = self::mapSurfaceType($surface);
            $lengths[$type] += self::wayLengthKm($way['nodes'], $nodes);
        }

        $total = array_sum($lengths);
        if ($total == 0.0) {
            return null;
        }

        $asphaltPct = round(($lengths['asphalt'] / $total) * 100);
        $gravelPct  = round(($lengths['gravel'] / $total) * 100);
        $trailPct   = round(($lengths['trail'] / $total) * 100);
        $unknownPct = round(($lengths['unknown'] / $total) * 100);

        // Nieoznaczone (brak tagu surface w OSM) rozdzielamy proporcjonalnie do
        // już znanych typów — inaczej duży "unknown" zaniżałby wszystkie 3
        // widoczne wartości bez potrzeby (użytkownikowi i tak nic nie mówi
        // "40% nieznane", a trasa i tak przebiega po jakiejś nawierzchni).
        if ($unknownPct > 0) {
            $knownTotal = $asphaltPct + $gravelPct + $trailPct;
            if ($knownTotal > 0) {
                $asphaltPct = round($asphaltPct + ($unknownPct * ($asphaltPct / $knownTotal)));
                $gravelPct  = round($gravelPct + ($unknownPct * ($gravelPct / $knownTotal)));
                $trailPct   = round($trailPct + ($unknownPct * ($trailPct / $knownTotal)));
            } else {
                $asphaltPct = $gravelPct = $trailPct = 33;
            }
        }

        $sum = $asphaltPct + $gravelPct + $trailPct;
        if ($sum != 100) {
            $asphaltPct += 100 - $sum; // różnica zaokrągleń — dokładamy do największej kategorii
        }

        return [
            'asphaltPct' => (int) $asphaltPct,
            'gravelPct'  => (int) $gravelPct,
            'trailPct'   => (int) $trailPct,
        ];
    }

    private static function mapSurfaceType(string $surface): string
    {
        foreach (self::SURFACE_MAPPING as $type => $tags) {
            if (in_array($surface, $tags, true)) {
                return $type;
            }
        }
        return 'unknown';
    }

    private static function wayLengthKm(array $nodeIds, array $nodes): float
    {
        $length = 0.0;
        for ($i = 1; $i < count($nodeIds); $i++) {
            $prev = $nodes[$nodeIds[$i - 1]] ?? null;
            $curr = $nodes[$nodeIds[$i]] ?? null;
            if ($prev === null || $curr === null) {
                continue;
            }
            $length += Gpx::haversineKm($prev['lat'], $prev['lon'], $curr['lat'], $curr['lon']);
        }
        return $length;
    }
}
