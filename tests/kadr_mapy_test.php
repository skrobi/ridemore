<?php
// tests/kadr_mapy_test.php
// KADR STARTOWY MAPY — `Models\Discovery::boundsFor()` (/odkrycia) i
// `Models\GpxGeometry::boundsFor()` (profil rowerzysty), 2026-08-27.
//
// Zgłoszenie usera: „na /odkrycia centrowanie działa dość słabo, ponieważ
// centruje poza zakresem moich osiągnięć lub społeczności". Znalezione na
// żywo: rowerzysta z 89 przejazdami wokół Mielca miał JEDEN wyjazd na
// Teneryfę (4500 km dalej) — naiwny MIN/MAX po wszystkich polach dał
// prostokąt Ocean Atlantycki–Polska, a jego ŚRODEK (Barcelona) nie leżał
// blisko ani jednego, ani drugiego skupiska.
//
// TESTY UŻYWAJĄ SYNTETYCZNYCH WSPÓŁRZĘDNYCH, nie prawdziwych danych z bazy
// DEV — bo ten błąd zależy od TEGO, jak rozłożone są czyjeś przejazdy, a baza
// DEV będzie się z czasem zmieniać. `discovery_cells` jest per-user (transakcja
// testu wycofa wstawione wiersze), ale `discovery_cell_totals` jest WSPÓLNA
// dla całego serwisu — testy community dopisują do niej jeden odległy punkt
// i sprawdzają WYŁĄCZNIE, że on nie wciąga kadru, nigdy dokładnych granic
// (te zależą od reszty bazy i nie są treścią tego testu).

use Models\Discovery;
use Utils\DiscoveryGrid;

/** Cell_id pod wskazanym punktem, na poziomie bazowym (RES_CELL). */
function km_cell(float $lat, float $lon): int
{
    return DiscoveryGrid::pointToCell($lat, $lon);
}

/** Kilka sąsiednich pól wokół punktu — jeden przejazd rzadko dotyka jednego pola. */
function km_cluster(float $lat, float $lon, int $n = 5): array
{
    $cells = [];
    for ($i = 0; $i < $n; $i++) {
        // Krok mniejszy niż RES_CELL (~469 m), ale wystarczający, żeby kolejne
        // punkty trafiły w RÓŻNE pola, nie w to samo.
        $cells[] = km_cell($lat + $i * 0.004, $lon + $i * 0.004);
    }
    return array_values(array_unique($cells));
}

/**
 * Prawdziwy user_id (FK w `discovery_cells` wymaga istniejącego wiersza
 * `users`), ale bez ANI JEDNEGO wcześniejszego pola — inaczej test dopisuje
 * swój klaster do tego, co ten user ma już naprawdę w bazie DEV (złapane na
 * żywo: pierwszy `t_user()` miał już 373 prawdziwe pola), i sprawdza kadr
 * mieszanki dwóch rzeczy zamiast samego klastra testowego.
 */
function km_czysty_user(): int
{
    return km_czysci_userzy(1)[0];
}

/** @return int[] */
function km_czysci_userzy(int $ile): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = array_map('intval', Core\Database::connection()->query(
            'SELECT id FROM users WHERE id NOT IN (SELECT DISTINCT user_id FROM discovery_cells) ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN));
    }
    if (count($cache) < $ile) {
        t_fail('Baza DEV ma mniej niż ' . $ile . ' userów bez discovery_cells — te testy potrzebują tylu czystych kont.');
    }
    return array_slice($cache, 0, $ile);
}

function km_insert_user(int $userId, array $cellIds): void
{
    $stmt = Core\Database::connection()->prepare(
        'INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (:u, :c, NOW())'
    );
    foreach ($cellIds as $c) {
        $stmt->execute(['u' => $userId, 'c' => $c]);
    }
}

/**
 * Wiersze `discovery_cell_totals` dla podanych pól — WSPÓLNA tabela, więc
 * `INSERT IGNORE`: gdyby akurat któreś z syntetycznych pól już tam było
 * (z prawdziwych danych DEV), zostawiamy je nietknięte, zamiast nadpisywać.
 */
function km_insert_totals(array $cellIds): void
{
    $stmt = Core\Database::connection()->prepare(
        'INSERT IGNORE INTO discovery_cell_totals (cell_id, cell_q, cell_r, riders_count, passes_count)
         VALUES (:c, :q, :r, 1, 1)'
    );
    foreach ($cellIds as $c) {
        [, $q, $r] = DiscoveryGrid::decode($c);
        $stmt->execute(['c' => $c, 'q' => $q, 'r' => $r]);
    }
}

/** Czy punkt leży w prostokącie zwróconym przez boundsFor(). */
function km_wewnatrz(array $bounds, float $lat, float $lon): bool
{
    return $lat >= $bounds['south'] && $lat <= $bounds['north']
        && $lon >= $bounds['west'] && $lon <= $bounds['east'];
}

// --- Bez odległego wyjazdu: zachowanie jak dotychczas ----------------

t_test('kadr: bez danych zwraca null, nie pustą tablicę', function () {
    // Nieistniejący user_id zamiast t_user() — nie chcemy zależeć od tego,
    // czy akurat KTÓRYŚ z prawdziwych userów bazy DEV ma zero pól.
    t_null(Discovery::boundsFor(999999999), 'brak pól = brak kadru');
});

t_test('kadr: jeden zwarty klaster daje ciasny prostokąt obejmujący go cały', function () {
    $userId = km_czysty_user();
    $cells = km_cluster(52.10, 19.20);
    km_insert_user($userId, $cells);

    $bounds = Discovery::boundsFor($userId);

    t_not_null($bounds, 'kadr policzony');
    foreach ($cells as $c) {
        [$lat, $lon] = DiscoveryGrid::cellCenter($c);
        t_true(km_wewnatrz($bounds, $lat, $lon), 'każde pole klastra mieści się w kadrze');
    }
    // Klaster ma ok. 5 pól rozstawionych co ~500 m po przekątnej — kilka
    // kilometrów, nie kontynent.
    t_true(($bounds['north'] - $bounds['south']) < 1.0, 'kadr jednego klastra jest ciasny (< ~110 km), jest: '
        . round(($bounds['north'] - $bounds['south']) * 111) . ' km');
});

// --- Odległy wyjazd: DOKŁADNIE ta regresja, którą zgłosił user ---------

t_test('kadr: odległy wyjazd NIE przeciąga środka w pustkę (regresja Teneryfy)', function () {
    $userId = km_czysty_user();
    $glowny = km_cluster(52.10, 19.20, 8);       // „Polska" — dziewięć pól, zwarcie
    $daleko = km_cell(10.0, -75.0);              // kilka tysięcy km dalej — „wyjazd"
    km_insert_user($userId, array_merge($glowny, [$daleko]));

    $bounds = Discovery::boundsFor($userId);
    [$latDaleko, $lonDaleko] = DiscoveryGrid::cellCenter($daleko);

    t_not_null($bounds, 'kadr policzony');
    t_false(km_wewnatrz($bounds, $latDaleko, $lonDaleko), 'odległy wyjazd NIE wchodzi do kadru');

    foreach ($glowny as $c) {
        [$lat, $lon] = DiscoveryGrid::cellCenter($c);
        t_true(km_wewnatrz($bounds, $lat, $lon), 'główny klaster w całości w kadrze');
    }
});

t_test('kadr: prostokąt ze skupiskiem jest WYRAŹNIE mniejszy niż naiwny MIN/MAX', function () {
    // Ten sam scenariusz, ale sprawdzony liczbą, nie tylko „czy punkt jest w
    // środku" — to jest test na to, że łatka faktycznie ZWĘZIŁA kadr, a nie
    // przypadkiem trafiła w warunek brzegowy.
    $userId = km_czysty_user();
    $glowny = km_cluster(52.10, 19.20, 8);
    $daleko = km_cell(10.0, -75.0);
    km_insert_user($userId, array_merge($glowny, [$daleko]));

    $bounds = Discovery::boundsFor($userId);
    $rozpietoscKm = ($bounds['north'] - $bounds['south']) * 111;

    // Naiwny prostokąt Polska-Kolumbia miałby dziesiątki tysięcy km — jeśli
    // łatka nie zadziałała, ten test spadnie z rozpiętością rzędu 5000+ km.
    t_true($rozpietoscKm < 500, 'kadr po odfiltrowaniu wyjazdu jest rzędu dziesiątek/set km, '
        . 'nie tysięcy (jest: ' . round($rozpietoscKm) . ' km)');
});

t_test('kadr: druga osoba nie wpływa na kadr pierwszej (izolacja per user)', function () {
    [$a, $b] = km_czysci_userzy(2);
    km_insert_user($a, km_cluster(52.10, 19.20));
    km_insert_user($b, km_cluster(10.0, -75.0));   // zupełnie inny kontynent

    $boundsA = Discovery::boundsFor($a);
    [$latB, $lonB] = DiscoveryGrid::cellCenter(km_cell(10.0, -75.0));

    t_not_null($boundsA, 'kadr osoby A policzony');
    t_false(km_wewnatrz($boundsA, $latB, $lonB), 'kadr osoby A nie sięga po pola osoby B');
});

// --- Mapa społeczności: ta sama ochrona, wspólna tabela -----------------

t_test('kadr społeczności: odległy punkt w discovery_cell_totals nie wciąga kadru', function () {
    // `discovery_cell_totals` jest WSPÓLNA — nie zerujemy jej ani nie zakładamy
    // jej zawartości, tylko DOPISUJEMY jeden bardzo odległy punkt i sprawdzamy,
    // że on sam nie determinuje kadru całej społeczności.
    $daleko = km_cell(-40.0, 170.0);   // Nowa Zelandia — z dala od czegokolwiek w DEV
    km_insert_totals([$daleko]);

    $bounds = Discovery::boundsFor(null);
    [$lat, $lon] = DiscoveryGrid::cellCenter($daleko);

    if ($bounds === null) {
        // Baza DEV bez żadnych innych odkryć — dopisany punkt jest wtedy
        // JEDYNYM polem i musi wyjść w kadrze (nie ma czego odfiltrować).
        t_fail('discovery_cell_totals nie ma ani jednego innego pola — sprawdź dane DEV.');
    }
    t_false(km_wewnatrz($bounds, $lat, $lon),
        'jeden odległy punkt (Nowa Zelandia) nie wciąga kadru społeczności w pustkę');
});

// ---------------------------------------------------------------------------
// TA SAMA REGRESJA, DRUGI SYSTEM WSPÓŁRZĘDNYCH — `GpxGeometry::boundsFor()`,
// kadr profilu rowerzysty (`RiderController::show`, patrz `tileBounds`).
// User poprosił wprost: „sprawdź to samo dla profilu rowerzysty". Ta metoda
// liczy z PIKSELI Merkatora (`gpx_geometry.min/max_px/py`), nie z osi
// heksagonu — więc to jest osobny kod z osobnym błędem do sprawdzenia, mimo
// że łata ma ten sam kształt.
// ---------------------------------------------------------------------------

use Models\GpxGeometry;

/** Prawdziwy (minimalny) plik GPX pod wskazanym punktem — zapisany jako upload. */
function km_gpx_track(float $lat, float $lon): array
{
    // `xmlns` JEST WYMAGANY — bez niego Utils\Gpx::parse() nie znajduje ani
    // jednego punktu i geometria zapisuje się jako PUSTA (ta sama pułapka co
    // w tests/kolory_sladow_test.php).
    $xml = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>';
    for ($i = 0; $i < 5; $i++) {
        $xml .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', $lat + $i * 0.001, $lon + $i * 0.001);
    }
    $xml .= '</trkseg></trk></gpx>';

    $url = Utils\Upload::saveGpxContents($xml);
    if ($url === null) {
        t_fail('Nie udało się zapisać testowego pliku GPX.');
    }
    $path = CORE_PATH . '/..' . $url;
    $hash = GpxGeometry::ensure($path);
    if ($hash === null) {
        t_fail('Geometria testowego śladu się nie policzyła.');
    }

    // Punkt ŚRODKOWY śladu, nie skrajny — skrajny leży DOKŁADNIE na krawędzi
    // bboxa (to jest jego min/max z definicji), a przejście przez piksele
    // int (toPixel/toLatLon) potrafi go po zaokrągleniu zepchnąć o ułamek
    // promila poza własną krawędź. Test sprawdzający „czy punkt jest w
    // kadrze" ma pytać o punkt bezpiecznie WEWNĄTRZ, nie o precyzję zaokrągleń.
    return ['path' => $path, 'hash' => $hash, 'midLat' => $lat + 0.002, 'midLon' => $lon + 0.002];
}

/** Sprząta plik testowy — geometria i indeks kafli wracają same wraz z wycofaniem transakcji testu. */
function km_gpx_cleanup(array $track): void
{
    @unlink($track['path']);
}

t_test('kadr profilu: pusta lista hashy daje null, nie wyjątek', function () {
    t_null(GpxGeometry::boundsFor([]), 'brak śladów = brak kadru');
});

t_test('kadr profilu: jeden ślad daje kadr obejmujący dokładnie jego', function () {
    $t = km_gpx_track(52.20, 19.30);
    $bounds = GpxGeometry::boundsFor([$t['hash']]);
    km_gpx_cleanup($t);

    t_not_null($bounds, 'kadr policzony');
    t_true(km_wewnatrz($bounds, $t['midLat'], $t['midLon']), 'środek śladu mieści się w kadrze');
});

t_test('kadr profilu: odległy wyjazd NIE przeciąga środka w pustkę', function () {
    // DOKŁADNIE ten scenariusz, który zgłosił user na koncie z 89 przejazdami:
    // większość śladów w jednym miejscu, jeden daleko.
    $a = km_gpx_track(52.10, 19.20);
    $b = km_gpx_track(52.11, 19.21);    // ten sam korytarz co $a
    $daleko = km_gpx_track(10.0, -75.0); // kilka tysięcy km dalej

    $bounds = GpxGeometry::boundsFor([$a['hash'], $b['hash'], $daleko['hash']]);

    km_gpx_cleanup($a);
    km_gpx_cleanup($b);
    km_gpx_cleanup($daleko);

    t_not_null($bounds, 'kadr policzony');
    t_true(km_wewnatrz($bounds, $a['midLat'], $a['midLon']), 'główny klaster (a) w kadrze');
    t_true(km_wewnatrz($bounds, $b['midLat'], $b['midLon']), 'główny klaster (b) w kadrze');
    t_false(km_wewnatrz($bounds, $daleko['midLat'], $daleko['midLon']), 'odległy wyjazd NIE wchodzi do kadru');
});

t_test('kadr profilu: prostokąt ze skupiskiem jest WYRAŹNIE mniejszy niż naiwny MIN/MAX', function () {
    $a = km_gpx_track(52.10, 19.20);
    $b = km_gpx_track(52.11, 19.21);
    $daleko = km_gpx_track(10.0, -75.0);

    $bounds = GpxGeometry::boundsFor([$a['hash'], $b['hash'], $daleko['hash']]);

    km_gpx_cleanup($a);
    km_gpx_cleanup($b);
    km_gpx_cleanup($daleko);

    $rozpietoscKm = ($bounds['north'] - $bounds['south']) * 111;
    // Naiwny prostokąt Polska-Kolumbia miałby tysiące km — jeśli łatka nie
    // zadziałała, ten test spadnie z rozpiętością rzędu 5000+ km.
    t_true($rozpietoscKm < 500, 'kadr po odfiltrowaniu wyjazdu jest rzędu dziesiątek km, '
        . 'nie tysięcy (jest: ' . round($rozpietoscKm) . ' km)');
});

// ─────────────────────────────── KADR PRZYCIĘTEJ GEOMETRII (2026-09-12)
//
// `boundsForTrimmed()` do tej daty było osobnym, naiwnym MIN/MAX — świadomie,
// bo miało JEDNEGO wołającego (`/przejazd/{id}`) i JEDEN ślad na wywołanie.
// Obrazek śladu na karcie Pulsu podaje mu całą dobę jednej osoby, czyli po
// imporcie z Garmina kilkaset przejazdów z różnych okolic — dokładnie ten
// przypadek, dla którego klastrowanie w ogóle powstało.

/**
 * Ślad z PRZYCIĘTĄ geometrią — bliźniak `km_gpx_track()`, tyle że liczy drugą
 * tabelę (`gpx_geometry_trimmed`): tę, z której rysuje się cudze solo i obrazek
 * śladu na karcie Pulsu.
 *
 * WIĘCEJ PUNKTÓW niż w `km_gpx_track()` i to nie jest ozdobnik:
 * `ensureTrimmed()` obcina końce w promieniu „okolic domu", więc ślad krótszy
 * niż dwa takie promienie zapisałby się jako PUSTY, a test mierzyłby brak
 * danych zamiast kadru.
 */
function km_gpx_track_trimmed(float $lat, float $lon): array
{
    $xml = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>';
    for ($i = 0; $i < 40; $i++) {
        $xml .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', $lat + $i * 0.002, $lon + $i * 0.002);
    }
    $xml .= '</trkseg></trk></gpx>';

    $url = Utils\Upload::saveGpxContents($xml);
    if ($url === null) {
        t_fail('Nie udało się zapisać testowego pliku GPX.');
    }
    $path = CORE_PATH . '/..' . $url;
    $hash = GpxGeometry::ensureTrimmed($path);
    if ($hash === null) {
        t_fail('Przycięta geometria testowego śladu się nie policzyła.');
    }

    // Punkt ŚRODKOWY (jak w km_gpx_track) — skrajny leży na krawędzi bboxa,
    // a po przycięciu końców w ogóle go już nie ma.
    return ['path' => $path, 'hash' => $hash, 'midLat' => $lat + 0.04, 'midLon' => $lon + 0.04];
}

t_test('kadr przycięty: pojedynczy ślad odpowiada tak samo jak przed rozdzieleniem tabel', function () {
    // SIATKA BEZPIECZEŃSTWA DLA STAREGO WOŁAJĄCEGO (`/przejazd/{id}`):
    // wspólne ciało z `boundsFor()` ma dla jednego śladu zachowywać się
    // identycznie, bo pojedynczy przejazd nie ma jak przekroczyć progu
    // klastrowania i drugie zapytanie nie rusza.
    $t = km_gpx_track_trimmed(52.20, 19.30);
    $bounds = GpxGeometry::boundsForTrimmed([$t['hash']]);
    km_gpx_cleanup($t);

    t_not_null($bounds, 'kadr policzony');
    t_true(km_wewnatrz($bounds, $t['midLat'], $t['midLon']), 'środek śladu mieści się w kadrze');
});

t_test('kadr przycięty: klastrowanie działa TAKŻE na drugiej tabeli', function () {
    $a = km_gpx_track_trimmed(52.10, 19.20);
    $b = km_gpx_track_trimmed(52.15, 19.25);     // ten sam korytarz co $a
    $daleko = km_gpx_track_trimmed(10.0, -75.0); // kilka tysięcy km dalej

    $bounds = GpxGeometry::boundsForTrimmed([$a['hash'], $b['hash'], $daleko['hash']]);

    km_gpx_cleanup($a);
    km_gpx_cleanup($b);
    km_gpx_cleanup($daleko);

    t_not_null($bounds, 'kadr policzony');
    t_true(km_wewnatrz($bounds, $a['midLat'], $a['midLon']), 'główny klaster (a) w kadrze');
    t_true(km_wewnatrz($bounds, $b['midLat'], $b['midLon']), 'główny klaster (b) w kadrze');
    t_false(km_wewnatrz($bounds, $daleko['midLat'], $daleko['midLon']), 'odległy ślad NIE wchodzi do kadru');
});

t_test('kadr: ciaśniejszy próg zawęża kadr tam, gdzie domyślny go nie rusza', function () {
    // „Za szeroko" znaczy co innego na mapie profilu (pełny ekran) niż na
    // obrazku wysokim na 272 px. Dwa skupiska ~300 km od siebie: domyślny
    // próg (1000 km) ich NIE rozdziela i ma prawo tego nie robić, próg karty
    // Pulsu (TileController::SLAD_CLUSTER_*) rozdziela.
    $a = km_gpx_track_trimmed(52.10, 19.20);
    $b = km_gpx_track_trimmed(52.15, 19.25);
    $daleko = km_gpx_track_trimmed(54.80, 19.30); // ~300 km na północ

    $hashes   = [$a['hash'], $b['hash'], $daleko['hash']];
    $domyslny = GpxGeometry::boundsForTrimmed($hashes);
    $ciasny   = GpxGeometry::boundsForTrimmed($hashes, 120, 50);

    km_gpx_cleanup($a);
    km_gpx_cleanup($b);
    km_gpx_cleanup($daleko);

    t_true(
        km_wewnatrz($domyslny, $daleko['midLat'], $daleko['midLon']),
        'przy DOMYŚLNYM progu odległe skupisko zostaje w kadrze (300 km < 1000 km)'
    );
    t_false(
        km_wewnatrz($ciasny, $daleko['midLat'], $daleko['midLon']),
        'przy progu karty Pulsu wypada poza kadr'
    );
    t_true(
        km_wewnatrz($ciasny, $a['midLat'], $a['midLon']),
        'a główne skupisko w kadrze zostaje'
    );
});
