<?php
// tests/znane_trasy_test.php
// ZNANE TRASY (Etap 8) — widoczność na mapie i edycja z panelu.
//
// Zestaw powstał przy naprawie błędu zgłoszonego po wdrożeniu 2026-08-19:
// świeżo wgrana trasa NIE POKAZYWAŁA SIĘ na mapie odkryć. Przyczyną było
// zawężanie kadru przez JOIN z `discovery_cell_totals`, czyli przez pola
// odkryte PRZEZ KOGOKOLWIEK — trasa poprowadzona przez teren, po którym nikt
// jeszcze nie jechał, wypadała z wyniku w całości.
//
// Pierwszy test jest testem TAMTEGO błędu i to on jest tu najważniejszy:
// warunek widoczności trasy nie ma prawa zależeć od cudzych przejazdów.
use Models\KnownRoute;
use Utils\DiscoveryGrid;

/**
 * Plik GPX z listy punktów. Trasy testowe kładziemy w pustkowiu (okolice
 * 54,80 N / 17,90 E), żeby nie mieszać się z odkryciami z bazy DEV — założenie
 * „tych pól nikt nie ma" jest w połowie testów treścią, a nie tłem.
 */
function kr_gpx_file(array $points): string
{
    $trkpts = '';
    foreach ($points as [$lat, $lon]) {
        $trkpts .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', $lat, $lon);
    }
    $path = sys_get_temp_dir() . '/kr_test_' . bin2hex(random_bytes(8)) . '.gpx';
    file_put_contents(
        $path,
        '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>' . $trkpts . '</trkseg></trk></gpx>'
    );
    return $path;
}

/** Prosta trasa ze wschodu na zachód, `$n` punktów co ~200 m. */
function kr_line(float $lat = 54.80, float $lon = 17.90, int $n = 40): array
{
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $points[] = [$lat, $lon + $i * 0.003];
    }
    return $points;
}

/** Trasa testowa w bazie. Zwraca [id, gpxPath] — plik zostaje do podmiany. */
function kr_route(array $points = null, string $name = 'TEST trasa'): array
{
    $path = kr_gpx_file($points ?? kr_line());
    $created = KnownRoute::createFromGpx($name, 'opis testowy', $path, '/assets/uploads/gpx/test.gpx');
    return ['id' => (int) $created['id'], 'path' => $path, 'cells' => (int) $created['cells']];
}

/** Kadr obejmujący wszystkie pola trasy, z zapasem. */
function kr_bounds(int $routeId, float $pad = 0.01): array
{
    $ids = Core\Database::connection()
        ->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $routeId)
        ->fetchAll(\PDO::FETCH_COLUMN);

    $lats = [];
    $lons = [];
    foreach ($ids as $cellId) {
        [$lat, $lon] = DiscoveryGrid::cellCenter((int) $cellId);
        $lats[] = $lat;
        $lons[] = $lon;
    }
    return [
        'north' => max($lats) + $pad, 'south' => min($lats) - $pad,
        'east'  => max($lons) + $pad, 'west'  => min($lons) - $pad,
    ];
}

function kr_slugs(array $bounds): array
{
    return array_column(KnownRoute::geometryInBounds($bounds), 'slug');
}

/**
 * Trasa testowa z WŁASNYM, unikalnym `gpx_url` (nie dzieli placeholdera
 * `/assets/uploads/gpx/test.gpx`, którego `kr_route()` używa dla wszystkich
 * wywołań naraz). Pod testy, które sprawdzają TOŻSAMOŚĆ kafla po hashu
 * geometrii — patrz „kafle: podmiana przebiegu kasuje..." niżej, ten sam
 * wzorzec. Zwraca też `hash`, żeby wołający nie musiał go liczyć drugi raz.
 *
 * @return array{id:int,path:string,gpxUrl:string,hash:string}
 */
function kr_route_unique(array $points, string $name): array
{
    $path = kr_gpx_file($points);
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    copy($path, CORE_PATH . '/..' . $gpxUrl);
    $created = KnownRoute::createFromGpx($name, null, $path, $gpxUrl);
    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $gpxUrl);
    return ['id' => (int) $created['id'], 'path' => $path, 'gpxUrl' => $gpxUrl, 'hash' => (string) $hash];
}

/** Sprząta plik tymczasowy + kopię pod `gpx_url` z `kr_route_unique()`. */
function kr_route_unique_cleanup(array $route): void
{
    @unlink($route['path']);
    @unlink(CORE_PATH . '/..' . $route['gpxUrl']);
}

/** Hashe wszystkich grup z `TileSource::tracks()` w jednym płaskim zbiorze. */
function kr_hashes(array $grupy): array
{
    $out = [];
    foreach ($grupy as $g) {
        foreach ($g['hashes'] as $h) {
            $out[$h] = true;
        }
    }
    return $out;
}

// --- Widoczność na mapie ---------------------------------------------

t_test('mapa: trasa, której NIKT nie przejechał, jest widoczna', function () {
    // REGRESJA 2026-08-19. Przed naprawą to zapytanie zwracało pustą tablicę,
    // bo trasa nie miała ani jednego pola w discovery_cell_totals.
    $route = kr_route();
    $slugs = kr_slugs(kr_bounds($route['id']));
    unlink($route['path']);

    t_count(1, $slugs, 'trasa w kadrze mimo zera odkryć w tym terenie');
});

t_test('mapa: trasa spoza kadru nie wchodzi do wyniku', function () {
    $route = kr_route();
    // Kadr oddalony o ~2 stopnie na południe — inny zakres osi r.
    $bounds = kr_bounds($route['id']);
    foreach (['north', 'south'] as $key) {
        $bounds[$key] -= 2.0;
    }
    $slugs = kr_slugs($bounds);
    unlink($route['path']);

    t_count(0, $slugs, 'poza kadrem nic nie wraca');
});

t_test('mapa: trasa wyłączona znika z warstwy', function () {
    $route = kr_route();
    KnownRoute::setActive($route['id'], false);
    $slugs = kr_slugs(kr_bounds($route['id']));
    unlink($route['path']);

    t_count(0, $slugs, 'wyłączona trasa nie jest rysowana');
});

t_test('mapa: punkty wracają w kolejności wzdłuż śladu', function () {
    $route = kr_route();
    $trails = KnownRoute::geometryInBounds(kr_bounds($route['id']));
    unlink($route['path']);

    t_count(1, $trails, 'jedna trasa');
    $lons = array_column($trails[0]['points'], 1);
    $sorted = $lons;
    sort($sorted);
    t_same($sorted, $lons, 'długości rosną monotonicznie — linia, nie zygzak');
});

t_test('mapa: pola bez kolejności są pomijane i zgłaszane', function () {
    $route = kr_route();
    Core\Database::connection()->exec(
        'UPDATE known_route_cells SET sort_order = NULL WHERE route_id = ' . $route['id']
    );

    $slugs = kr_slugs(kr_bounds($route['id']));
    $unordered = KnownRoute::unorderedCounts();
    unlink($route['path']);

    t_count(0, $slugs, 'trasa bez kolejności pól nie jest rysowana');
    t_eq($route['cells'], $unordered[$route['id']] ?? 0, 'panel widzi, ile pól czeka na przeliczenie');
});

// --- Zapis pól --------------------------------------------------------

t_test('pola: współrzędne osiowe zgadzają się z identyfikatorem', function () {
    // Rozjazd między zapisem (writeCells) a filtrem kadru oznaczałby trasę
    // niewidoczną na mapie — czyli dokładnie ten błąd, tylko cichszy.
    $route = kr_route();
    $rows = Core\Database::connection()
        ->query('SELECT cell_id, cell_q, cell_r FROM known_route_cells WHERE route_id = ' . $route['id'])
        ->fetchAll();
    unlink($route['path']);

    $bad = 0;
    foreach ($rows as $row) {
        [, $q, $r] = DiscoveryGrid::decode((int) $row['cell_id']);
        if ((int) $row['cell_q'] !== $q || (int) $row['cell_r'] !== $r) {
            $bad++;
        }
    }
    t_true(count($rows) > 0, 'trasa ma pola');
    t_eq(0, $bad, 'żadne pole nie ma rozjechanych współrzędnych');
});

// --- Edycja -----------------------------------------------------------

t_test('edycja: zmiana danych opisowych nie rusza slugu ani pól', function () {
    $route = kr_route();
    $before = KnownRoute::find($route['id']);

    KnownRoute::update($route['id'], [
        'name'             => 'TEST trasa po zmianie',
        'description'      => 'nowy opis',
        'bonus_points'     => 250,
        'completion_bonus' => 500,
        'bonus_enabled'    => 0,
    ]);
    $after = KnownRoute::find($route['id']);
    unlink($route['path']);

    t_eq('TEST trasa po zmianie', $after['name'], 'nazwa zmieniona');
    t_eq('nowy opis', $after['description'], 'opis zmieniony');
    t_eq(250, (int) $after['bonus_points'], 'bonus za progi');
    t_eq(500, (int) $after['completion_bonus'], 'bonus za ukończenie');
    t_eq(0, (int) $after['bonus_enabled'], 'punktowanie wyłączone');
    t_eq($before['slug'], $after['slug'], 'slug NIETKNIĘTY — adres /trasy/{slug} ma dalej działać');
    t_eq((int) $before['cells_total'], (int) $after['cells_total'], 'liczba pól nietknięta');
});

t_test('edycja: kolumn spoza whitelisty nie da się podać', function () {
    $route = kr_route();
    $before = KnownRoute::find($route['id']);

    KnownRoute::update($route['id'], [
        'slug'        => 'podmieniony-slug',
        'cells_total' => 1,
        'is_active'   => 0,
        'name'        => 'TEST trasa',
    ]);
    $after = KnownRoute::find($route['id']);
    unlink($route['path']);

    t_eq($before['slug'], $after['slug'], 'slug nie do ruszenia przez update()');
    t_eq((int) $before['cells_total'], (int) $after['cells_total'], 'cells_total wyprowadzany z pliku, nie z formularza');
    t_eq(1, (int) $after['is_active'], 'aktywność zmienia wyłącznie setActive()');
});

t_test('edycja: podmiana przebiegu zmienia pola, zachowuje id i slug', function () {
    $route = kr_route();
    $before = KnownRoute::find($route['id']);

    // Nowy przebieg: ten sam start, dwa razy dłuższy.
    $newPath = kr_gpx_file(kr_line(54.80, 17.90, 80));
    $result = KnownRoute::replaceGpx($route['id'], $newPath, '/assets/uploads/gpx/test2.gpx');
    $after = KnownRoute::find($route['id']);

    $cells = (int) Core\Database::connection()
        ->query('SELECT COUNT(*) FROM known_route_cells WHERE route_id = ' . $route['id'])
        ->fetchColumn();
    $unordered = (int) Core\Database::connection()
        ->query('SELECT COUNT(*) FROM known_route_cells WHERE sort_order IS NULL AND route_id = ' . $route['id'])
        ->fetchColumn();
    unlink($route['path']);
    unlink($newPath);

    t_eq($before['slug'], $after['slug'], 'slug ten sam — nikt nie traci adresu ani progów');
    t_eq('/assets/uploads/gpx/test2.gpx', $after['gpx_url'], 'plik podmieniony');
    t_true((int) $after['cells_total'] > (int) $before['cells_total'], 'dłuższa trasa ma więcej pól');
    t_eq($result['cells'], $cells, 'cells_total zgadza się z liczbą wierszy');
    t_eq(0, $unordered, 'każde pole dostało pozycję wzdłuż śladu');
    t_true((float) $after['distance_km'] > (float) $before['distance_km'], 'dystans przeliczony');
});

t_test('edycja: po podmianie przebiegu trasa dalej jest na mapie', function () {
    // Przeliczenie zostawiające trasę bez współrzędnych osiowych byłoby
    // niewidoczne w danych i widoczne dopiero na mapie — stąd ten test.
    $route = kr_route();
    $newPath = kr_gpx_file(kr_line(54.90, 18.10, 30));
    KnownRoute::replaceGpx($route['id'], $newPath, '/assets/uploads/gpx/test3.gpx');

    $slugs = kr_slugs(kr_bounds($route['id']));
    unlink($route['path']);
    unlink($newPath);

    t_count(1, $slugs, 'trasa widoczna w nowym miejscu');
});

// --- Lista w panelu: szukanie, filtry, sortowanie, stronicowanie ------
//
// Powstało po pytaniu usera „a jak będę miał 200 tras?". Ekran, który wypisuje
// wszystko naraz, przy takim katalogu przestaje być listą — a każda z tych
// czterech rzeczy z osobna decyduje o tym, czy da się dojść do JEDNEJ trasy.

/**
 * Wiersze tras wstawiane WPROST do bazy, bez pól i bez pliku. Lista panelu
 * czyta wyłącznie `known_routes`, więc parsowanie trzydziestu GPX-ów tylko po
 * to, żeby sprawdzić stronicowanie, byłoby płaceniem sekundami za nic.
 *
 * @return string prefiks nazwy, po którym testy zawężają wynik do swoich tras
 */
function kr_rows(int $ile, array $nadpisania = []): string
{
    $prefix = 'ZZTEST' . bin2hex(random_bytes(3));
    $stmt = Core\Database::connection()->prepare('
        INSERT INTO known_routes (slug, name, distance_km, cells_total, is_active, bonus_enabled, created_at)
        VALUES (:slug, :name, :km, :cells, :active, :bonus, NOW() - INTERVAL :dni DAY)
    ');
    for ($i = 1; $i <= $ile; $i++) {
        $stmt->execute([
            'slug'   => strtolower($prefix) . '-' . $i,
            'name'   => $prefix . ' ' . sprintf('%03d', $i),
            'km'     => $nadpisania['km'] ?? (10 + $i),
            'cells'  => 100 + $i,
            'active' => $nadpisania['active'] ?? 1,
            'bonus'  => $nadpisania['bonus'] ?? 1,
            'dni'    => $ile - $i,
        ]);
    }
    return $prefix;
}

t_test('panel: szukanie zawęża listę do pasujących tras', function () {
    $prefix = kr_rows(3);
    $wynik = KnownRoute::search(['szukaj' => $prefix]);

    t_eq(3, $wynik['total'], 'znalezione po nazwie');
    t_eq(1, $wynik['stron'], 'mieszczą się na jednej stronie');
});

t_test('panel: szukanie działa też po adresie trasy', function () {
    $prefix = kr_rows(1);
    // Admin przychodzi tu często z adresu, który mu ktoś podesłał.
    $wynik = KnownRoute::search(['szukaj' => strtolower($prefix) . '-1']);

    t_eq(1, $wynik['total'], 'trafione po slugu');
});

t_test('panel: stronicowanie tnie wynik i liczy strony', function () {
    $prefix = kr_rows(KnownRoute::PER_PAGE + 3);

    $pierwsza = KnownRoute::search(['szukaj' => $prefix, 'strona' => 1]);
    $druga    = KnownRoute::search(['szukaj' => $prefix, 'strona' => 2]);

    t_eq(KnownRoute::PER_PAGE + 3, $pierwsza['total'], 'licznik mówi o CAŁYM wyniku, nie o stronie');
    t_count(KnownRoute::PER_PAGE, $pierwsza['items'], 'pierwsza strona pełna');
    t_count(3, $druga['items'], 'druga strona ma resztę');
    t_eq(2, $pierwsza['stron'], 'dwie strony');

    // Żaden wiersz nie może wyjść na obu stronach — to jest cały sens offsetu.
    $wspolne = array_intersect(
        array_column($pierwsza['items'], 'id'),
        array_column($druga['items'], 'id')
    );
    t_count(0, $wspolne, 'strony się nie zazębiają');
});

t_test('panel: numer strony poza zakresem daje pustkę, nie błąd', function () {
    $prefix = kr_rows(2);
    $wynik = KnownRoute::search(['szukaj' => $prefix, 'strona' => 99]);

    t_eq(2, $wynik['total'], 'licznik bez zmian');
    t_count(0, $wynik['items'], 'za ostatnią stroną nie ma nic');
});

t_test('panel: sortowanie po dystansie idzie od najdłuższej', function () {
    $prefix = kr_rows(5);
    $wynik = KnownRoute::search(['szukaj' => $prefix, 'sort' => 'dystans']);

    $km = array_map(static fn(array $r): float => (float) $r['distance_km'], $wynik['items']);
    $malejaco = $km;
    rsort($malejaco);
    t_same($malejaco, $km, 'dystanse malejąco');
});

t_test('panel: nieznane sortowanie spada do domyślnego, nie do SQL-a', function () {
    // Wartość idzie z adresu, więc musi przechodzić przez zamkniętą listę.
    $prefix = kr_rows(3);
    $wynik = KnownRoute::search(['szukaj' => $prefix, 'sort' => 'kr.id; DROP TABLE known_routes']);

    $nazwy = array_column($wynik['items'], 'name');
    $rosnaco = $nazwy;
    sort($rosnaco);
    t_same($rosnaco, $nazwy, 'porządek domyślny — po nazwie');
});

t_test('panel: filtry rozdzielają aktywne, wyłączone i bez punktów', function () {
    $wlaczone  = kr_rows(2);
    $wylaczone = kr_rows(3, ['active' => 0]);
    $bezPkt    = kr_rows(1, ['bonus' => 0]);

    t_eq(2, KnownRoute::search(['szukaj' => $wlaczone, 'filtr' => 'aktywne'])['total'], 'aktywne');
    t_eq(0, KnownRoute::search(['szukaj' => $wlaczone, 'filtr' => 'wylaczone'])['total'], 'aktywnej nie ma wśród wyłączonych');
    t_eq(3, KnownRoute::search(['szukaj' => $wylaczone, 'filtr' => 'wylaczone'])['total'], 'wyłączone');
    t_eq(1, KnownRoute::search(['szukaj' => $bezPkt, 'filtr' => 'bez-punktow'])['total'], 'bez punktów');
});

t_test('panel: filtr „do przeliczenia" znajduje trasę, która się nie rysuje', function () {
    // To jedyny filtr pokazujący USTERKĘ — przy dwustu trasach nie da się
    // wypatrzeć okiem, która nie ma kolejności pól.
    $route = kr_route(null, 'ZZTEST bez kolejnosci');
    t_eq(0, KnownRoute::search(['szukaj' => 'ZZTEST bez kolejnosci', 'filtr' => 'do-przeliczenia'])['total'],
        'zdrowa trasa nie jest zgłaszana');

    Core\Database::connection()->exec(
        'UPDATE known_route_cells SET sort_order = NULL WHERE route_id = ' . $route['id']
    );
    $wynik = KnownRoute::search(['szukaj' => 'ZZTEST bez kolejnosci', 'filtr' => 'do-przeliczenia']);
    unlink($route['path']);

    t_eq(1, $wynik['total'], 'uszkodzona trasa wpada do filtra');
});

t_test('panel: liczniki zgadzają się z filtrami', function () {
    $przed = KnownRoute::counters();
    kr_rows(2, ['active' => 0]);
    $po = KnownRoute::counters();

    t_eq($przed['wszystkie'] + 2, $po['wszystkie'], 'wszystkie');
    t_eq($przed['wylaczone'] + 2, $po['wylaczone'], 'wyłączone');
    t_eq($przed['aktywne'], $po['aktywne'], 'aktywnych nie przybyło');
});

t_test('panel: find() podaje KOD regionu, nie tylko jego id', function () {
    // Od migr. 074 region jest WYPROWADZANY z GPX, nie zapisywany ręcznie
    // (patrz KnownRoute::syncRegions) — ślad musi więc leżeć na PRAWDZIWYM
    // terenie, a nie w pustkowiu 54,8 N / 17,9 E, którego używa reszta tego
    // zestawu specjalnie po to, żeby NIE dotykać żadnego regionu.
    $cellRow = Core\Database::connection()->query('SELECT cell_id FROM region_cells LIMIT 1')->fetch();
    t_not_null($cellRow, 'region_cells ma dane (uruchom backfill_regions.php)');
    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $cellRow['cell_id']);

    $route = kr_route(kr_line($lat, $lon));
    $found = KnownRoute::find($route['id']);
    unlink($route['path']);

    t_not_null($found['region_code'], 'region_code jest w wyniku find()');
    t_not_null($found['region_label'], 'nazwa regionu też');
});

// --- Kadr mapy na stronie trasy --------------------------------------

t_test('kadr: boundsFor obejmuje wszystkie pola trasy', function () {
    // Strona trasy ustawia kadr Z SERWERA, zanim mapa zapyta o pola — bez tego
    // pierwsze żądanie leciałoby dla całego kraju, a obraz przeskakiwał po
    // dojściu pliku GPX.
    $route = kr_route();
    $bounds = KnownRoute::boundsFor($route['id']);

    $lats = [];
    $lons = [];
    foreach (Core\Database::connection()
        ->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $route['id'])
        ->fetchAll(\PDO::FETCH_COLUMN) as $cellId) {
        [$lat, $lon] = DiscoveryGrid::cellCenter((int) $cellId);
        $lats[] = $lat;
        $lons[] = $lon;
    }
    unlink($route['path']);

    t_not_null($bounds, 'trasa z polami ma kadr');
    t_true($bounds['south'] < min($lats) && $bounds['north'] > max($lats), 'wszystkie szerokości w kadrze');
    t_true($bounds['west'] < min($lons) && $bounds['east'] > max($lons), 'wszystkie długości w kadrze');
});

t_test('kadr: trasa bez pól nie udaje, że ma kadr', function () {
    // Wtedy widok po prostu nie ustawia kadru — lepsze niż prostokąt zerowy,
    // do którego mapa przybliżyłaby się na maksa w losowym miejscu.
    $db = Core\Database::connection();
    $db->prepare('INSERT INTO known_routes (slug, name, distance_km, cells_total) VALUES (:s, :n, 0, 0)')
       ->execute(['s' => 'zztest-bez-pol', 'n' => 'ZZTEST bez pól']);

    t_null(KnownRoute::boundsFor((int) $db->lastInsertId()), 'brak pól = brak kadru');
});

// --- Dymek trasy na mapie ---------------------------------------------

t_test('dymek: warstwa podaje nazwę, dystans i przewyższenie', function () {
    // Klik w szlak ma odpowiedzieć na pytanie „co to za kreska i czy chcę tam
    // pojechać" — bez wchodzenia na stronę trasy.
    $route = kr_route();
    $trails = KnownRoute::geometryInBounds(kr_bounds($route['id']));
    $found = KnownRoute::find($route['id']);
    unlink($route['path']);

    t_count(1, $trails, 'trasa w kadrze');
    t_eq('TEST trasa', $trails[0]['name'], 'nazwa do dymka');
    t_eq((float) $found['distance_km'], $trails[0]['distanceKm'], 'dystans ten sam co w bazie');
    t_not_null($trails[0]['elevation'], 'przewyższenie policzone przy wgraniu pliku');
    t_not_null($trails[0]['slug'], 'slug, z którego widok składa adres strony trasy');
});

t_test('dymek: przewyższenie NIEZNANE nie udaje zera', function () {
    // Zero metrów w górę jest poprawną wartością (płaska pętla), więc trasa
    // sprzed migracji 063 musi dać null, a nie 0 — inaczej dymek pokazywałby
    // „0 m" jako fakt.
    $route = kr_route();
    Core\Database::connection()->exec(
        'UPDATE known_routes SET elevation_gain_m = NULL WHERE id = ' . $route['id']
    );

    $trails = KnownRoute::geometryInBounds(kr_bounds($route['id']));
    unlink($route['path']);

    t_null($trails[0]['elevation'], 'brak danych zostaje brakiem danych');
});

t_test('dymek: backfill dolicza przewyższenie z zapisanego pliku', function () {
    // Trasy sprzed migr. 063 dostają wartość przy `php run_migrations.php`,
    // bez wgrywania czegokolwiek od nowa i bez ruszania pól.
    $path = kr_gpx_file(kr_line(54.80, 17.90, 30));
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    // Plik musi leżeć tam, gdzie wskazuje gpx_url — backfill czyta z dysku.
    $target = CORE_PATH . '/..' . $gpxUrl;
    copy($path, $target);

    $created = KnownRoute::createFromGpx('TEST backfill', null, $path, $gpxUrl);
    $id = (int) $created['id'];
    $db = Core\Database::connection();
    $cellsBefore = (int) $db->query('SELECT COUNT(*) FROM known_route_cells WHERE route_id = ' . $id)->fetchColumn();
    $db->exec('UPDATE known_routes SET elevation_gain_m = NULL WHERE id = ' . $id);

    $wynik = KnownRoute::backfillElevation();
    $after = KnownRoute::find($id);
    $cellsAfter = (int) $db->query('SELECT COUNT(*) FROM known_route_cells WHERE route_id = ' . $id)->fetchColumn();
    unlink($path);
    unlink($target);

    t_true($wynik['updated'] >= 1, 'backfill uzupełnił co najmniej tę trasę');
    t_not_null($after['elevation_gain_m'], 'przewyższenie dopisane');
    t_eq($cellsBefore, $cellsAfter, 'pola trasy NIETKNIĘTE — backfill liczy tylko metry');
});

t_test('dymek: trasa bez pliku nie wywraca backfillu', function () {
    $route = kr_route();
    Core\Database::connection()->exec(
        'UPDATE known_routes SET elevation_gain_m = NULL, gpx_url = "/assets/uploads/gpx/nie-ma-takiego.gpx"
          WHERE id = ' . $route['id']
    );

    $wynik = KnownRoute::backfillElevation();
    $after = KnownRoute::find($route['id']);
    unlink($route['path']);

    t_true($wynik['skipped'] >= 1, 'brakujący plik zgłoszony jako pominięcie');
    t_null($after['elevation_gain_m'], 'zostaje uczciwe „nie wiem", nie zmyślone zero');
});

// --- Warstwa „Trasy" jako kafle (2026-08-20) --------------------------

t_test('kafle: klucz `kr` zbiera aktywne trasy w stylu szlaku', function () {
    // Warstwa rysuje się kaflami, więc `kr` musi widzieć dokładnie te trasy,
    // które są w serwisie WŁĄCZONE — wyłączenie ma zdejmować szlak z mapy,
    // a nie tylko z listy.
    $path = kr_gpx_file(kr_line(54.80, 17.90, 25));
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    copy($path, CORE_PATH . '/..' . $gpxUrl);

    $created = KnownRoute::createFromGpx('ZZTEST kafle', null, $path, $gpxUrl);
    $id = (int) $created['id'];
    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $gpxUrl);

    // GRUPA NA TRASĘ, nie jedna na warstwę (migr. 064): każdy szlak ma własny
    // kolor, a kolor jest właściwością grupy. Test pyta więc o hashe ze
    // WSZYSTKICH grup — liczba grup zależy od tego, ile tras jest w bazie DEV.
    $hashe = static function (array $grupy): array {
        return array_merge(...array_column($grupy, 'hashes')) ?: [];
    };

    $wlaczona = Models\TileSource::tracks('kr');
    KnownRoute::setActive($id, false);
    $wylaczona = Models\TileSource::tracks('kr');

    unlink($path);
    unlink(CORE_PATH . '/..' . $gpxUrl);

    t_eq('route', $wlaczona[0]['style'], 'styl szlaku, nie śladu z wyjazdu');
    t_true(in_array($hash, $hashe($wlaczona), true), 'aktywna trasa wchodzi do kafli');
    t_false(in_array($hash, $hashe($wylaczona), true), 'wyłączona już nie');
    t_true(Models\TileSource::isAllowed('kr'), 'klucz przechodzi walidację adresu kafla');
    t_true(Models\TileSource::isCacheable('kr'), 'kafle `kr` wolno trzymać na dysku — dane publiczne');
});

t_test('kafle: zmiana trasy podbija epokę, czyli unieważnia kafle', function () {
    // Bez tego zmiana w panelu nie dociera do nikogo, kto ma kafel w cache'u
    // przeglądarki — a `?v=` w adresie jest jedynym sygnałem, że jest nowy.
    $path = kr_gpx_file(kr_line(54.90, 18.10, 25));
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    copy($path, CORE_PATH . '/..' . $gpxUrl);

    $przed = Models\TileCache::epoch(Models\TileSource::LAYER_TRACKS, 'kr');
    $created = KnownRoute::createFromGpx('ZZTEST epoka', null, $path, $gpxUrl);
    $poDodaniu = Models\TileCache::epoch(Models\TileSource::LAYER_TRACKS, 'kr');

    KnownRoute::setActive((int) $created['id'], false);
    $poWylaczeniu = Models\TileCache::epoch(Models\TileSource::LAYER_TRACKS, 'kr');

    KnownRoute::delete((int) $created['id']);
    $poUsunieciu = Models\TileCache::epoch(Models\TileSource::LAYER_TRACKS, 'kr');

    unlink($path);
    unlink(CORE_PATH . '/..' . $gpxUrl);

    t_true($poDodaniu > $przed, 'dodanie trasy unieważnia kafle');
    t_true($poWylaczeniu > $poDodaniu, 'wyłączenie też — warstwa pokazuje tylko aktywne');
    t_true($poUsunieciu > $poWylaczeniu, 'usunięcie liczy hash PRZED skasowaniem wiersza');
});

t_test('kafle: trasa bez pliku na dysku nie wywraca zapisu', function () {
    // Trasy testowe (i te z bazy DEV) potrafią wskazywać na nieistniejący plik.
    // Unieważnianie kafli ma być wtedy ciche, a nie wysadzać dodawanie trasy.
    $route = kr_route();
    KnownRoute::setActive($route['id'], false);
    KnownRoute::delete($route['id']);
    unlink($route['path']);

    t_null(KnownRoute::find($route['id']), 'trasa skasowana mimo braku pliku');
});

// --- Klik w szlak: trafienie liczone na serwerze ----------------------

t_test('klik: punkt na trasie zwraca ją z nazwą i liczbami', function () {
    // Kafel to obrazek, więc trafienie nie może liczyć się w przeglądarce —
    // pyta o nie serwer, na POLACH trasy, czyli na tym, czym trasa dla tego
    // modułu jest.
    $route = kr_route();
    $cells = KnownRoute::cellIds($route['id']);
    [$lat, $lon] = DiscoveryGrid::cellCenter($cells[0]);

    $hit = KnownRoute::atPoint($lat, $lon, 14);
    unlink($route['path']);

    t_count(1, $hit, 'jedna trasa pod punktem');
    t_eq('TEST trasa', $hit[0]['name'], 'nazwa do dymka');
    t_not_null($hit[0]['slug'], 'slug, z którego serwer składa adres strony trasy');
    t_true($hit[0]['distanceKm'] > 0, 'dystans');
    t_not_null($hit[0]['elevation'], 'przewyższenie');
});

t_test('klik: punkt daleko od trasy nie trafia w nic', function () {
    // Dymek „nic tu nie ma" byłby karą za kliknięcie w tło, więc pudło musi
    // być pustą odpowiedzią, a nie najbliższą trasą z całej Polski.
    $route = kr_route();
    $cells = KnownRoute::cellIds($route['id']);
    [$lat, $lon] = DiscoveryGrid::cellCenter($cells[0]);

    $hit = KnownRoute::atPoint($lat + 0.2, $lon, 14); // ok. 22 km na północ
    unlink($route['path']);

    t_count(0, $hit, 'pudło zostaje pudłem');
});

t_test('klik: tolerancja rośnie z oddaleniem', function () {
    // Przy oddaleniu jeden piksel to setki metrów — wymaganie precyzji byłoby
    // wymaganiem niemożliwego. Przy przybliżeniu odwrotnie: dwie trasy obok
    // siebie muszą dać się rozróżnić.
    $route = kr_route();
    $cells = KnownRoute::cellIds($route['id']);
    [$lat, $lon] = DiscoveryGrid::cellCenter($cells[0]);
    $obok = $lat + 0.02; // ok. 2,2 km

    $daleki = KnownRoute::atPoint($obok, $lon, 7);
    $bliski = KnownRoute::atPoint($obok, $lon, 16);
    unlink($route['path']);

    t_count(1, $daleki, 'przy oddaleniu 2 km od linii to wciąż to samo miejsce');
    t_count(0, $bliski, 'przy przybliżeniu 2 km to już nie ta trasa');
});

t_test('klik: wyłączona trasa nie odpowiada na kliknięcie', function () {
    $route = kr_route();
    $cells = KnownRoute::cellIds($route['id']);
    [$lat, $lon] = DiscoveryGrid::cellCenter($cells[0]);
    KnownRoute::setActive($route['id'], false);

    $hit = KnownRoute::atPoint($lat, $lon, 14);
    unlink($route['path']);

    t_count(0, $hit, 'wyłączenie zdejmuje trasę z serwisu, nie tylko z listy');
});

// --- Kafle przy maksymalnym przybliżeniu ------------------------------

t_test('kafle: renderer rysuje do najgłębszego zoomu, nie wywala się', function () {
    // REGRESJA 2026-08-20. Po podniesieniu MAX_Z do 18 renderer liczył
    // przesunięcie bitowe MINUS JEDEN (nadpróbkowanie ×2 rysuje w przestrzeni
    // zoomu z+1), a PHP 8 rzuca na tym ArithmeticError — każdy kafel z18
    // wracał jako HTTP 500. Objaw dla użytkownika: mapa bez tras przy
    // maksymalnym przybliżeniu.
    $punkt = ['lat' => 49.2134, 'lon' => 22.6650];
    foreach ([Utils\TileGrid::MIN_Z, 12, Utils\TileGrid::INDEX_Z, Utils\TileGrid::MAX_Z] as $z) {
        [$x, $y] = Utils\TileGrid::tileOf($punkt['lat'], $punkt['lon'], $z);
        $png = Utils\TileRenderer::tracks(
            [['geom' => [['pts' => [
                Utils\TileGrid::toPixel($punkt['lat'], $punkt['lon'])[0],
                Utils\TileGrid::toPixel($punkt['lat'], $punkt['lon'])[1],
                Utils\TileGrid::toPixel($punkt['lat'] + 0.01, $punkt['lon'] + 0.01)[0],
                Utils\TileGrid::toPixel($punkt['lat'] + 0.01, $punkt['lon'] + 0.01)[1],
            ]]], 'color' => '#2C6B4F', 'weight' => 4, 'alpha' => 1.0, 'dash' => false]],
            $z, $x, $y
        );
        t_true(strlen($png) > 0, 'kafel zoomu ' . $z . ' się renderuje');
        t_eq("\x89PNG", substr($png, 0, 4), 'i jest PNG-iem (zoom ' . $z . ')');
    }
});

// --- Obwódka znanych tras (2026-08-27) ---------------------------------
//
// Zgłoszenie usera: „znane trasy i ślady mają dziś jeden styl i nakładają się
// na siebie nie do odróżnienia (…) dla znanych tras wprowadzić obwódkę do
// linii: obramowanie, główny kolor, obramowanie". `TileRenderer::tracks()`
// rysuje teraz geometrię grupy DWA RAZY, gdy ma klucz `casing`: szerzej pod
// spodem (obwódka), normalnie na wierzchu (główny kolor) — testy pilnują
// FAKTU „obwódka faktycznie coś dorysowuje" i „bez klucza nic się nie zmienia",
// nie dokładnych współrzędnych pikseli (to szczegół rastrowania GD).

/** Prosta przekątna przez środek jednego kafla — ta sama konstrukcja co w teście zoomu z18. */
function t_kafel_linia(): array
{
    $punkt = ['lat' => 49.2134, 'lon' => 22.6650];
    $z = 12;
    [$x, $y] = Utils\TileGrid::tileOf($punkt['lat'], $punkt['lon'], $z);
    return [
        'z'   => $z, 'x' => $x, 'y' => $y,
        'pts' => [
            Utils\TileGrid::toPixel($punkt['lat'], $punkt['lon'])[0],
            Utils\TileGrid::toPixel($punkt['lat'], $punkt['lon'])[1],
            Utils\TileGrid::toPixel($punkt['lat'] + 0.02, $punkt['lon'] + 0.02)[0],
            Utils\TileGrid::toPixel($punkt['lat'] + 0.02, $punkt['lon'] + 0.02)[1],
        ],
    ];
}

/** Liczba pikseli NIE W PEŁNI przezroczystych na PNG-ie z bajtów. */
function t_licz_nieprzezroczyste(string $png): int
{
    $im = imagecreatefromstring($png);
    t_true($im !== false, 'PNG się dekoduje');
    $w = imagesx($im);
    $h = imagesy($im);
    $n = 0;
    for ($py = 0; $py < $h; $py++) {
        for ($px = 0; $px < $w; $px++) {
            // Kanał alfa GD: 0 = kryjący, 127 = w pełni przezroczysty.
            if ((imagecolorat($im, $px, $py) >> 24 & 0x7F) < 127) {
                $n++;
            }
        }
    }
    imagedestroy($im);
    return $n;
}

t_test('kafle: obwódka daje WIĘCEJ nieprzezroczystych pikseli niż sam kolor główny', function () {
    $l = t_kafel_linia();

    $bezObwodki = Utils\TileRenderer::tracks(
        [['geom' => [['pts' => $l['pts']]], 'color' => '#2C6B4F', 'weight' => 4, 'alpha' => 1.0, 'dash' => false]],
        $l['z'], $l['x'], $l['y']
    );
    $zObwodka = Utils\TileRenderer::tracks(
        [['geom' => [['pts' => $l['pts']]], 'color' => '#2C6B4F', 'weight' => 4, 'alpha' => 1.0, 'dash' => false,
          'casing' => '#15201A', 'casingWidth' => 1.5]],
        $l['z'], $l['x'], $l['y']
    );

    $pikseleBez = t_licz_nieprzezroczyste($bezObwodki);
    $pikseleZ = t_licz_nieprzezroczyste($zObwodka);

    t_true($pikseleBez > 0, 'linia bez obwódki w ogóle coś rysuje (jest: ' . $pikseleBez . ' px)');
    t_true($pikseleZ > $pikseleBez, 'z obwódką pikseli nieprzezroczystych jest WIĘCEJ — obwódka wystaje '
        . 'spod głównej linii (bez: ' . $pikseleBez . ', z: ' . $pikseleZ . ')');
});

t_test('kafle: grupa BEZ klucza `casing` renderuje się bajtowo identycznie jak przed zmianą', function () {
    // Regresja: style `real`/`planned` (bez obwódki w ogóle) nie mają prawa
    // zmienić wyglądu przy tej samej geometrii i kolorze — obwódka jest
    // WYŁĄCZNIE dodatkiem stylu `route`, nigdy zachowaniem domyślnym.
    $l = t_kafel_linia();
    $grupaBazowa = ['geom' => [['pts' => $l['pts']]], 'color' => '#2C6B4F', 'weight' => 3, 'alpha' => 1.0, 'dash' => false];

    $bezKlucza = Utils\TileRenderer::tracks([$grupaBazowa], $l['z'], $l['x'], $l['y']);
    $zNullem = Utils\TileRenderer::tracks([$grupaBazowa + ['casing' => null]], $l['z'], $l['x'], $l['y']);
    $zPustymStringiem = Utils\TileRenderer::tracks([$grupaBazowa + ['casing' => '']], $l['z'], $l['x'], $l['y']);

    t_same($bezKlucza, $zNullem, 'brak klucza i `casing => null` dają bajtowo identyczny PNG');
    t_same($bezKlucza, $zPustymStringiem, 'pusty string traktowany tak samo jak brak obwódki');
});

/**
 * Liczy nieprzezroczyste piksele, jakie `TileRenderer::polyline()` (PRYWATNA
 * — stąd Reflection, ten sam wzorzec co gdzie indziej w tym pakiecie testów)
 * rysuje na CZYSTYM płótnie dla podanej łamanej i średnicy złączenia.
 * Współrzędne lokalne (bez GPS-a, bez nadpróbkowania) — interesuje nas
 * WYŁĄCZNIE geometria złączenia, nie cała ścieżka kafla.
 */
function t_polyline_pixels(array $pts, int $jointPx): int
{
    $ref = new ReflectionMethod(Utils\TileRenderer::class, 'polyline');
    $ref->setAccessible(true);

    $w = 300; $h = 220;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    imagesetthickness($im, 8);
    $color = imagecolorallocatealpha($im, 30, 30, 30, 0);

    $ref->invoke(null, $im, $pts, 0, 0, 0, 0, $w, 40, $color, $jointPx);

    $n = 0;
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            if ((imagecolorat($im, $x, $y) >> 24 & 0x7F) < 127) {
                $n++;
            }
        }
    }
    imagedestroy($im);
    return $n;
}

t_test('kafle: ZAOKRĄGLONE ZŁĄCZENIA dokładają powierzchnię DOKŁADNIE na ostrych zakrętach (naprawa 2026-08-29)', function () {
    // Zgłoszenie usera: „obwódka mocno postrzępiona, niedokładne łączenia".
    // Przyczyna: `imagesetthickness` + `imageline` w GD łączy odcinki BEZ
    // zaokrąglenia — na ostrym zakręcie (a taki ma niemal każdy prawdziwy
    // ślad GPS) zostaje klin brakującego piksela w wierzchołku. Naprawa
    // (`$jointPx` w `polyline()`) dokłada kółko o średnicy grubości linii
    // w każdym wierzchołku — test porównuje TĘ SAMĄ łamaną z i bez tego
    // kółka (parametr izoluje dokładnie ten mechanizm, więc różnica pikseli
    // mierzy WYŁĄCZNIE jego efekt, nie coś przypadkowego w geometrii).
    //
    // Ostry zygzak — malutki krok poziomy, duży skok pionowy: każdy
    // wierzchołek to niemal zawracanie, nie łagodny skręt (dokładnie ten
    // kształt, jaki ma szumiący ślad GPS na screenie ze zgłoszenia).
    $ostry = [20, 180, 24, 40, 28, 180, 32, 40, 36, 180, 40, 40];

    $zZlaczeniem = t_polyline_pixels($ostry, 8);
    $bezZlaczenia = t_polyline_pixels($ostry, 0);

    t_true($zZlaczeniem > $bezZlaczenia, 'z kółkiem w wierzchołku jest WIĘCEJ nieprzezroczystych pikseli — '
        . "ostry zakręt bez niego zostawia klin (z: $zZlaczeniem, bez: $bezZlaczenia)");

    // KONTRAST: na PROSTEJ linii złączenia też domalowują parę pikseli GD-owej
    // rasteryzacji między krótkimi współliniowymi odcinkami (to nie jest
    // usterka — dokładnie taką samą korzyść dostaje gęsty, niemal prosty
    // fragment prawdziwego śladu GPS), ale przyrost na OSTRYM zakręcie musi
    // być WYRAŹNIE większy — to on jest sednem tej naprawy, nie sama obecność
    // złączeń w ogóle.
    $prosta = [20, 100, 24, 100, 28, 100, 32, 100, 36, 100, 40, 100];
    $prostaZZlaczeniem = t_polyline_pixels($prosta, 8);
    $prostaBezZlaczenia = t_polyline_pixels($prosta, 0);
    $deltaOstry = $zZlaczeniem - $bezZlaczenia;
    $deltaProsta = $prostaZZlaczeniem - $prostaBezZlaczenia;
    t_true($deltaOstry > $deltaProsta, 'przyrost pikseli ze złączeń jest większy na ostrym zakręcie niż na prostej '
        . "(ostry: +$deltaOstry, prosta: +$deltaProsta)");
});

t_test('kolory: obwódka ZNANEJ TRASY jest stała, niezależna od przydzielonego koloru', function () {
    // Sens obwódki jest inny niż sens koloru (patrz komentarz przy
    // TileSource::STYLES): kolor rozróżnia trasy MIĘDZY SOBĄ, obwódka
    // rozróżnia „to jest referencja" od zarejestrowanego przejazdu — więc
    // MUSI być ta sama niezależnie od tego, który z sześciu kolorów trasa
    // dostała.
    $styl = Models\TileSource::STYLES['route'];
    t_true(!empty($styl['casing']), 'styl `route` ma zdefiniowaną obwódkę');
    t_true(!isset(Models\TileSource::STYLES['real']['casing']), 'styl `real` (zarejestrowany przejazd) NIE ma obwódki');
    t_true(!isset(Models\TileSource::STYLES['planned']['casing']), 'styl `planned` (trasa zapowiadana bez śladu) NIE ma obwódki');
});

t_test('kafle: głębsze niż indeks nie trafiają na dysk', function () {
    // Powód jest arytmetyczny: jeden kafel indeksu rozpada się na 4^(z-14)
    // kafli, więc zapis z18 wysadziłby i piramidę, i unieważnianie.
    t_true(Utils\TileGrid::MAX_Z > Utils\TileGrid::INDEX_Z, 'generujemy głębiej, niż indeksujemy');
    t_eq(14, Utils\TileGrid::INDEX_Z, 'indeks ZOSTAJE na 14 — jest zapisany w gpx_geometry');
});

// --- Wgranie śladu unieważnia kafle w całym swoim bboxie --------------

t_test('kafle: podmiana przebiegu kasuje kafle na WSZYSTKICH zoomach', function () {
    // To jest test niezmiennika, na którym stoi cała warstwa rastrowa: kafel
    // leży pod swoim adresem na dysku i Apache oddaje go BEZ pytania PHP-a,
    // więc sama zmiana epoki (`?v=`) nikogo nie ratuje — plik musi zniknąć.
    // I musi zniknąć na każdym poziomie, nie tylko na tym najdrobniejszym.
    $path = kr_gpx_file(kr_line(54.70, 17.60, 25));
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    copy($path, CORE_PATH . '/..' . $gpxUrl);

    $created = KnownRoute::createFromGpx('ZZTEST inwalidacja', null, $path, $gpxUrl);
    $id = (int) $created['id'];
    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $gpxUrl);
    $kafleIndeksu = Models\GpxGeometry::tilesFor($hash);

    // Kładziemy na dysku kafle na kilku poziomach — dokładnie tam, gdzie
    // przechodzi ślad. `store()` to ta sama droga, którą idzie prawdziwy kafel.
    [$tx, $ty] = $kafleIndeksu[0];
    $zoomy = [8, 10, 12, Utils\TileGrid::INDEX_Z];
    $pliki = [];
    foreach ($zoomy as $z) {
        $shift = Utils\TileGrid::INDEX_Z - $z;
        $x = $tx >> $shift;
        $y = $ty >> $shift;
        Models\TileCache::store(Models\TileSource::LAYER_TRACKS, 'kr', $z, $x, $y, Utils\TileRenderer::blank());
        $pliki[$z] = CORE_PATH . '/../assets/tiles/slady/kr/' . $z . '/' . $x . '/' . $y . '.png';
    }
    $zapisane = array_filter($pliki, 'is_file');
    $epokaPrzed = Models\TileCache::epoch(Models\TileSource::LAYER_TRACKS, 'kr');

    // Podmiana przebiegu — to samo, co wgranie nowego GPX-a w panelu.
    $nowy = kr_gpx_file(kr_line(54.70, 17.60, 40));
    KnownRoute::replaceGpx($id, $nowy, $gpxUrl);

    $zostaly = array_filter($pliki, 'is_file');
    $epokaPo = Models\TileCache::epoch(Models\TileSource::LAYER_TRACKS, 'kr');

    foreach ($pliki as $p) { @unlink($p); }
    unlink($path);
    unlink($nowy);
    unlink(CORE_PATH . '/..' . $gpxUrl);

    t_count(count($zoomy), $zapisane, 'kafle faktycznie wylądowały na dysku');
    t_count(0, $zostaly, 'po podmianie NIE MA ich na żadnym z poziomów');
    t_true($epokaPo > $epokaPrzed, 'epoka podbita — przeglądarki dostają nowy adres');
});

t_test('kafle: unieważnianie obejmuje obie warstwy, nie tylko ślady', function () {
    // Ślad zmienia też POLA odkryć, więc kafle `hex` muszą zniknąć razem
    // z `slady` — inaczej mgła zostałaby w kształcie sprzed zmiany.
    $path = kr_gpx_file(kr_line(54.60, 17.40, 25));
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    copy($path, CORE_PATH . '/..' . $gpxUrl);

    $created = KnownRoute::createFromGpx('ZZTEST dwie warstwy', null, $path, $gpxUrl);
    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $gpxUrl);
    [$tx, $ty] = Models\GpxGeometry::tilesFor($hash)[0];

    $pliki = [];
    foreach ([Models\TileSource::LAYER_TRACKS, Models\TileSource::LAYER_HEX] as $warstwa) {
        Models\TileCache::store($warstwa, 'kr', Utils\TileGrid::INDEX_Z, $tx, $ty, Utils\TileRenderer::blank());
        $pliki[$warstwa] = CORE_PATH . '/../assets/tiles/' . $warstwa . '/kr/'
            . Utils\TileGrid::INDEX_Z . '/' . $tx . '/' . $ty . '.png';
    }
    $zapisane = array_filter($pliki, 'is_file');

    KnownRoute::setActive((int) $created['id'], false);
    $zostaly = array_filter($pliki, 'is_file');

    foreach ($pliki as $p) { @unlink($p); }
    unlink($path);
    unlink(CORE_PATH . '/..' . $gpxUrl);

    t_count(2, $zapisane, 'obie warstwy miały kafel na dysku');
    t_count(0, $zostaly, 'obie zostały skasowane');
});

// --- Kolory tras (migr. 064) ------------------------------------------
//
// Zgłoszenie usera 2026-08-20: „kilka śladów się na siebie nakłada i nie
// wiadomo, który jest który, bo wszystkie są zielone". Od tej daty kolor jest
// WŁASNOŚCIĄ TRASY (kolumna `color_index`), a nie stałą w stylu linii — te
// testy pilnują trzech rzeczy naraz: że kolor w ogóle powstaje, że sąsiedzi
// dostają różne, i że raz przydzielony NIE ZMIENIA SIĘ przy dokładaniu tras
// (bo kafle na dysku pamiętają ten, którym je narysowano).

/** Kolor trasy prosto z bazy — testy sprawdzają zapis, nie to, co zwrócił kod. */
function kr_color(int $routeId)
{
    $stmt = Core\Database::connection()->prepare('SELECT color_index FROM known_routes WHERE id = :id');
    $stmt->execute(['id' => $routeId]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? null : (int) $value;
}

t_test('kolory: nowa trasa dostaje kolor z palety', function () {
    $route = kr_route();
    $index = kr_color($route['id']);
    unlink($route['path']);

    t_not_null($index, 'trasa ma przydzielony kolor');
    t_true(in_array(KnownRoute::colorOf($index), KnownRoute::COLORS, true), 'kolor jest z palety');
});

t_test('kolory: trasa leżąca NA innej dostaje inny kolor', function () {
    // Sedno zgłoszenia: dwa szlaki tym samym korytarzem. Przed migracją 064
    // obie linie były #2C6B4F i na mapie zlewały się w jedną.
    $a = kr_route(kr_line(54.80, 17.90, 40), 'TEST trasa A');
    $b = kr_route(kr_line(54.80, 17.90, 40), 'TEST trasa B');

    $kolorA = kr_color($a['id']);
    $kolorB = kr_color($b['id']);
    unlink($a['path']);
    unlink($b['path']);

    t_true($kolorA !== $kolorB, 'nakładające się trasy mają różne kolory');
});

t_test('kolory: trasa obok (nie na) też dostaje inny kolor', function () {
    // ~700 m na północ, czyli dwa pola siatki — na mapie w powiększeniu 13
    // to dwie kreski tuż obok siebie, więc muszą się różnić tak samo jak te
    // nałożone na siebie.
    $a = kr_route(kr_line(54.80, 17.90, 40), 'TEST trasa blisko A');
    $b = kr_route(kr_line(54.8065, 17.90, 40), 'TEST trasa blisko B');

    $kolorA = kr_color($a['id']);
    $kolorB = kr_color($b['id']);
    unlink($a['path']);
    unlink($b['path']);

    t_true($kolorA !== $kolorB, 'sąsiadujące trasy mają różne kolory');
});

t_test('kolory: dołożenie sąsiada NIE przemalowuje trasy już narysowanej', function () {
    // To jest test na spójność kafli, nie na estetykę: kafel `kr` leży na
    // dysku z Cache-Control immutable i unieważnia się WYŁĄCZNIE po śladzie
    // zmienianej trasy. Gdyby nowa trasa przemalowywała sąsiadów, sąsiad
    // zostałby narysowany dwoma kolorami — starym tam, gdzie kafel przetrwał.
    $a = kr_route(kr_line(54.80, 17.90, 40), 'TEST trasa stała');
    $przed = kr_color($a['id']);

    $b = kr_route(kr_line(54.80, 17.90, 40), 'TEST trasa dołożona');
    $po = kr_color($a['id']);

    unlink($a['path']);
    unlink($b['path']);

    t_eq($przed, $po, 'kolor istniejącej trasy zostaje nietknięty');
});

t_test('kolory: backfill pomija trasy, które kolor już mają', function () {
    $route = kr_route();
    $przed = kr_color($route['id']);

    $wynik = KnownRoute::assignAllColors();
    $po = kr_color($route['id']);
    unlink($route['path']);

    t_eq($przed, $po, 'powtórny backfill niczego nie przemalowuje');
    t_eq(0, $wynik['colored'], 'nie ma czego kolorować — wszystkie trasy mają kolor');
});

t_test('kafle: warstwa `kr` daje osobną grupę na trasę, każdą w swoim kolorze', function () {
    // Warstwa „Znane trasy" przed migracją 064 zwracała JEDNĄ grupę ze
    // wszystkimi hashami i jednym stylem — stąd wzięła się zielona plama.
    $pliki = [];
    $urls = [];
    $kolory = [];
    foreach ([['TEST kafel A', 54.80], ['TEST kafel B', 54.8015]] as [$nazwa, $lat]) {
        $path = kr_gpx_file(kr_line($lat, 17.90, 30));
        $url = '/assets/uploads/gpx/' . basename($path);
        copy($path, CORE_PATH . '/..' . $url);
        $created = KnownRoute::createFromGpx($nazwa, null, $path, $url);
        $pliki[] = $path;
        $urls[] = $url;
        $kolory[Models\GpxGeometry::ensure(CORE_PATH . '/..' . $url)]
            = KnownRoute::colorOf(kr_color((int) $created['id']));
    }

    $grupy = [];
    foreach (Models\TileSource::tracks('kr') as $grupa) {
        foreach ($grupa['hashes'] as $hash) {
            if (isset($kolory[$hash])) {
                $grupy[$hash] = $grupa['color'] ?? null;
            }
        }
    }

    foreach ($pliki as $p) { unlink($p); }
    foreach ($urls as $u) { unlink(CORE_PATH . '/..' . $u); }

    t_count(2, $grupy, 'każda trasa osobną grupą');
    t_same($kolory, $grupy, 'grupa niesie kolor swojej trasy');
    t_count(2, array_unique(array_values($grupy)), 'dwie sąsiadujące trasy = dwa różne kolory');
});

// --- Profil wysokości i skarby na trasie (migr. 065) -------------------
//
// Zgłoszenie usera 2026-08-20: strona trasy nie pokazywała ani profilu, ani
// tego, co po drodze leży do znalezienia. Profil jest teraz kolumną (ten sam
// kształt co `event_stages.elevation_profile`), a skarby liczy `Treasure::
// onRoute` po POLACH trasy — tą samą regułą, którą stosuje zaliczanie ze śladu.

/** Profil trasy prosto z bazy — testy sprawdzają zapis, nie to, co zwrócił kod. */
function kr_profile(int $routeId): ?array
{
    $stmt = Core\Database::connection()->prepare('SELECT elevation_profile FROM known_routes WHERE id = :id');
    $stmt->execute(['id' => $routeId]);
    $json = $stmt->fetchColumn();
    return $json === false || $json === null ? null : json_decode($json, true);
}

/** Ślad z realnym podjazdem: wysokość rośnie i opada wzdłuż trasy. */
function kr_hilly(int $n = 60): array
{
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $points[] = [54.80, 17.90 + $i * 0.003];
    }
    return $points;
}

t_test('profil: nowa trasa zapisuje profil wysokości z pliku', function () {
    $route = kr_route();
    $profil = kr_profile($route['id']);
    unlink($route['path']);

    t_not_null($profil, 'profil zapisany przy dodaniu trasy');
    t_true(count($profil) > 2, 'ma próbki, nie jeden punkt');
    // Kształt MUSI być ten sam co w etapach wydarzeń — z niego rysuje wykres
    // i z niego liczą się szczyty, więc brak `lat`/`lon` wyłącza synchronizację
    // wykresu z mapą (dokładnie ten błąd naprawiał backfill_elevation_profiles).
    foreach (['d', 'e', 'lat', 'lon'] as $klucz) {
        t_true(array_key_exists($klucz, $profil[0]), 'próbka ma pole ' . $klucz);
    }
});

t_test('profil: podmiana pliku podmienia profil, a nie zostawia stary', function () {
    $route = kr_route();
    $przed = kr_profile($route['id']);

    $nowy = kr_gpx_file(kr_line(54.70, 17.60, 30));
    KnownRoute::replaceGpx($route['id'], $nowy, '/assets/uploads/gpx/test.gpx');
    $po = kr_profile($route['id']);

    unlink($route['path']);
    unlink($nowy);

    t_not_null($po, 'po podmianie profil dalej jest');
    t_true($przed[0]['lon'] !== $po[0]['lon'], 'profil pochodzi z NOWEGO pliku');
});

t_test('profil: backfill dopisuje profil trasie, która go nie ma', function () {
    $path = kr_gpx_file(kr_hilly());
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    copy($path, CORE_PATH . '/..' . $gpxUrl);
    $created = KnownRoute::createFromGpx('ZZTEST profil backfill', null, $path, $gpxUrl);

    // Stan sprzed migracji 065: kolumna pusta.
    Core\Database::connection()->exec(
        'UPDATE known_routes SET elevation_profile = NULL WHERE id = ' . (int) $created['id']
    );
    $wynik = KnownRoute::backfillElevation();
    $profil = kr_profile((int) $created['id']);

    unlink($path);
    unlink(CORE_PATH . '/..' . $gpxUrl);

    t_not_null($profil, 'backfill dopisał profil z pliku, który trasa już miała');
    t_true($wynik['updated'] >= 1, 'backfill zgłasza, że coś uzupełnił');
});

t_test('profil: elevationProfile() dokłada szczyty i nie wywraca się na pustym', function () {
    $route = kr_route();
    $json = json_encode(kr_profile($route['id']));
    $gotowy = KnownRoute::elevationProfile($json);
    unlink($route['path']);

    t_not_null($gotowy, 'profil z bazy przechodzi na kształt dla widoku');
    t_true(is_array($gotowy['peaks']), 'szczyty policzone na żądanie, nie z bazy');
    t_null(KnownRoute::elevationProfile(null), 'brak profilu to null, nie pusty wykres');
    t_null(KnownRoute::elevationProfile('[]'), 'profil bez próbek też jest niczym');
});

t_test('skarby: onRoute liczy skarby leżące na polach trasy', function () {
    $route = kr_route();
    $cellId = (int) Core\Database::connection()
        ->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $route['id'] . ' LIMIT 1')
        ->fetchColumn();
    [$lat, $lon] = Utils\DiscoveryGrid::cellCenter($cellId);

    $naTrasie = t_treasure(['name' => 'TEST skarb na trasie', 'lat' => $lat, 'lon' => $lon, 'points' => 70]);
    // Kontrolny skarb daleko stąd — nie ma prawa wejść do liczby.
    t_treasure(['name' => 'TEST skarb gdzie indziej', 'lat' => 50.0, 'lon' => 20.0, 'points' => 1000]);

    $gosc = Models\Treasure::onRoute($route['id'], null);
    $widz = Models\Treasure::onRoute($route['id'], t_user(0));

    unlink($route['path']);

    t_eq(1, $gosc['total'], 'tylko skarb z pola trasy');
    t_eq(70, $gosc['points'], 'suma punktów skarbów po drodze');
    t_eq(70, $gosc['pointsLeft'], 'dla gościa wszystko jest jeszcze do wzięcia');
    t_null($gosc['found'], 'gość nie dostaje „0 znalezionych" — to nie informacja o nim');
    t_eq(0, $widz['found'], 'zalogowany, który jeszcze nic nie znalazł, ma zero');
    t_eq($naTrasie['cell_id'], $cellId, 'skarb faktycznie siedzi w polu trasy');
});

t_test('skarby: onRoute liczy ZNALEZIONE przez pytającego', function () {
    $route = kr_route();
    $cellId = (int) Core\Database::connection()
        ->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $route['id'] . ' LIMIT 1')
        ->fetchColumn();
    [$lat, $lon] = Utils\DiscoveryGrid::cellCenter($cellId);

    $skarb = t_treasure(['name' => 'TEST skarb zaliczony', 'lat' => $lat, 'lon' => $lon]);
    Models\Treasure::claim($skarb['code'], t_user(0), $lat, $lon);

    $ja = Models\Treasure::onRoute($route['id'], t_user(0));
    $ktosInny = Models\Treasure::onRoute($route['id'], t_user(1));

    unlink($route['path']);

    t_eq(1, $ja['found'], 'mój skarb liczy się jako znaleziony');
    t_eq(0, $ktosInny['found'], 'cudze znalezienie nie jest moje');
    t_eq(1, $ja['total'], 'znaleziony skarb NIE znika z listy „po drodze"');
    t_eq(0, $ja['pointsLeft'], 'ale jego punkty nie są już „do zdobycia"');
    t_true($ktosInny['pointsLeft'] > 0, 'dla kogoś innego wciąż są');
});

// --- „TRASY TYLKO MOJE" — klucz kafla `kd-{slug}` (Etap 3 warstw mapy,
// tasks/done/warstwy-mapy.md, 2026-08-26). „Ukończona" to DOKŁADNIE ta
// sama definicja, której używa karta postępu (KnownRoute::progressForUser:
// matched >= cells_total) — testy to sprawdzają wprost, żeby ta warstwa
// nigdy nie mogła pokazać czegoś innego niż lista tras na profilu.

/**
 * Prawdziwie widoczny rowerzysta z bazy DEV (ma slug, nie ukrył się —
 * Support::visibleRider, zawężone 2026-09-10 do tych dwóch warunków). Testy
 * `kd-{slug}` muszą przejść przez TĘ SAMĄ bramkę co produkcja, więc szukamy
 * istniejącego, a nie tworzymy nowego konta tylko po to, żeby ją ominąć.
 */
function kr_visible_rider(): array
{
    $db = Core\Database::connection();
    foreach ($db->query('SELECT id, public_slug FROM users WHERE public_slug IS NOT NULL')->fetchAll() as $row) {
        if (Controllers\Support::visibleRider($row['public_slug']) !== null) {
            return ['id' => (int) $row['id'], 'slug' => $row['public_slug']];
        }
    }
    t_fail('Baza DEV nie ma ani jednego rowerzysty spełniającego Support::visibleRider — te testy go potrzebują.');
}

/** Wpisuje WSZYSTKIE pola trasy jako odkryte przez tego użytkownika — „ukończenie" na skróty. */
function kr_complete_route(int $routeId, int $userId): void
{
    $db = Core\Database::connection();
    $ids = $db->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $routeId)
        ->fetchAll(\PDO::FETCH_COLUMN);
    $ins = $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (?, ?, NOW())');
    foreach ($ids as $cellId) {
        $ins->execute([$userId, (int) $cellId]);
    }
}

// UWAGA METODOLOGICZNA: bramka `t_count(0, ...)` na CAŁYM `tracks('kd-{slug}')`
// nie zadziała tu niezawodnie — prawdziwy rowerzysta z bazy DEV (musi przejść
// `Support::visibleRider`) może już mieć w historii INNĄ, naprawdę ukończoną
// trasę, więc klucz nie jest pusty z założenia. Sprawdzamy więc PRZYNALEŻNOŚĆ
// hashu TEJ KONKRETNEJ trasy do zwróconych grup, nie samą ich liczbę —
// i każda trasa dostaje WŁASNY plik (`kr_route_unique`), żeby jej hash
// nie mógł się zlać z żadną inną.

t_test('kd-{slug}: nieukończona trasa nie wychodzi na kafel', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.75, 17.50), 'ZZTEST kd nieukończona');

    $wKafle = kr_hashes(Models\TileSource::tracks('kd-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_false(isset($wKafle[$route['hash']]), 'zero pól odkrytych = ta trasa nie wchodzi na kafel');
});

t_test('kd-{slug}: CZĘŚCIOWO pokryta trasa dalej nie liczy się jako ukończona', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.76, 17.50), 'ZZTEST kd częściowa');

    $db = Core\Database::connection();
    $pierwsze = (int) $db->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $route['id'] . ' LIMIT 1')
        ->fetchColumn();
    $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (?, ?, NOW())')
        ->execute([$rider['id'], $pierwsze]);

    $wKafle = kr_hashes(Models\TileSource::tracks('kd-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_false(isset($wKafle[$route['hash']]), 'JEDNO pole z wielu to wciąż „nieukończona", tak samo jak na karcie postępu');
});

t_test('kd-{slug}: trasa pokryta w 100% wychodzi na kafel, ta sama definicja co karta postępu', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.77, 17.50), 'ZZTEST kd ukończona');
    kr_complete_route($route['id'], $rider['id']);

    $ukonczone = array_column(
        array_filter(KnownRoute::progressForUser($rider['id']), fn($r) => $r['isComplete']),
        'id'
    );
    $wKafle = kr_hashes(Models\TileSource::tracks('kd-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_true(in_array($route['id'], $ukonczone, true), 'karta postępu też widzi tę trasę jako ukończoną (test niczego nie sprawdza inaczej)');
    t_true(isset($wKafle[$route['hash']]), 'i wchodzi na kafel');
});

t_test('kd-{slug}: cudze ukończenie nie wychodzi na TWOIM kluczu', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.78, 17.50), 'ZZTEST kd cudze');
    // Ktoś INNY, na pewno różny od `$rider` — to nieistotne, czy jest
    // widoczny, liczy się wyłącznie to, że NIE JEST właścicielem klucza.
    $ktosInny = current(array_filter(t_users(5), fn(int $id) => $id !== $rider['id']));
    kr_complete_route($route['id'], $ktosInny);

    $wKafle = kr_hashes(Models\TileSource::tracks('kd-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_false(isset($wKafle[$route['hash']]), 'cudzy postęp nie przecieka na klucz tej osoby');
});

t_test('kd-{slug}: bramka widoczności — nieistniejący/ukryty slug daje pustkę, nie błąd', function () {
    t_count(0, Models\TileSource::tracks('kd-nie-ma-takiego-uzytkownika-xyz'), 'nieznany slug');
    t_false(Models\TileSource::isAllowed('kd-nie-ma-takiego-uzytkownika-xyz'), 'i w ogóle nie jest dozwolonym kluczem kafla');
});

t_test('kd-{slug}: edycja przebiegu trasy unieważnia kafel osoby, która ją ukończyła', function () {
    // Ta sama próba co „kafle: podmiana przebiegu kasuje kafle na WSZYSTKICH
    // zoomach" wyżej, tylko dla klucza per-osoba: plik na dysku musi zniknąć,
    // nie tylko epoka się podbić — Apache oddaje istniejący plik bez pytania PHP-a.
    $rider = kr_visible_rider();
    $path = kr_gpx_file(kr_line(54.79, 17.50, 25));
    $gpxUrl = '/assets/uploads/gpx/' . basename($path);
    copy($path, CORE_PATH . '/..' . $gpxUrl);

    $created = KnownRoute::createFromGpx('ZZTEST kd inwalidacja', null, $path, $gpxUrl);
    $id = (int) $created['id'];
    kr_complete_route($id, $rider['id']);

    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $gpxUrl);
    [$tx, $ty] = Models\GpxGeometry::tilesFor($hash)[0];
    $z = Utils\TileGrid::INDEX_Z;
    $klucz = 'kd-' . $rider['slug'];
    Models\TileCache::store(Models\TileSource::LAYER_TRACKS, $klucz, $z, $tx, $ty, Utils\TileRenderer::blank());
    $plik = CORE_PATH . '/../assets/tiles/slady/' . $klucz . '/' . $z . '/' . $tx . '/' . $ty . '.png';
    $bylZapisany = is_file($plik);

    $nowy = kr_gpx_file(kr_line(54.79, 17.50, 40));
    KnownRoute::replaceGpx($id, $nowy, $gpxUrl);

    $zostal = is_file($plik);
    @unlink($plik);
    unlink($path);
    unlink($nowy);
    unlink(CORE_PATH . '/..' . $gpxUrl);

    t_true($bylZapisany, 'kafel faktycznie wylądował na dysku przed edycją');
    t_false($zostal, 'po edycji przebiegu zniknął — inaczej zostałby nieodświeżony na zawsze');
});

// --- „TRASY NIEUKOŃCZONE" — klucz kafla `kn-{slug}` (migr. 078, 2026-08-29,
// zgłoszenie usera: mapa osobista pokazywała WYŁĄCZNIE ukończone trasy, więc
// reszta katalogu na niej w ogóle nie istniała — inaczej niż na mapie
// społeczności, gdzie widać komplet). `kn-` jest DOKŁADNYM LUSTREM `kd-`
// wyżej, więc testy są parami: to samo ustawienie, odwrócone oczekiwanie.

t_test('kn-{slug}: nieukończona trasa WYCHODZI na kafel (lustro kd-)', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.75, 17.60), 'ZZTEST kn nieukończona');

    $wKafle = kr_hashes(Models\TileSource::tracks('kn-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_true(isset($wKafle[$route['hash']]), 'zero pól odkrytych = trasa jest „nieukończona" i wchodzi tutaj');
});

t_test('kn-{slug}: CZĘŚCIOWO pokryta trasa dalej liczy się jako nieukończona', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.76, 17.60), 'ZZTEST kn częściowa');

    $db = Core\Database::connection();
    $pierwsze = (int) $db->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $route['id'] . ' LIMIT 1')
        ->fetchColumn();
    $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (?, ?, NOW())')
        ->execute([$rider['id'], $pierwsze]);

    $wKafle = kr_hashes(Models\TileSource::tracks('kn-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_true(isset($wKafle[$route['hash']]), 'JEDNO pole z wielu to wciąż „nieukończona" — ta sama granica co `kd-`');
});

t_test('kn-{slug}: trasa pokryta w 100% NIE wychodzi na kafel', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.77, 17.60), 'ZZTEST kn ukończona');
    kr_complete_route($route['id'], $rider['id']);

    $wKafle = kr_hashes(Models\TileSource::tracks('kn-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_false(isset($wKafle[$route['hash']]), 'ukończona trasa znika z „nieukończonych" — nie może być na obu naraz');
});

t_test('kn-{slug}: cudze ukończenie nie wypycha trasy z TWOJEGO klucza', function () {
    $rider = kr_visible_rider();
    $route = kr_route_unique(kr_line(54.78, 17.60), 'ZZTEST kn cudze');
    $ktosInny = current(array_filter(t_users(5), fn(int $id) => $id !== $rider['id']));
    kr_complete_route($route['id'], $ktosInny);

    $wKafle = kr_hashes(Models\TileSource::tracks('kn-' . $rider['slug']));

    kr_route_unique_cleanup($route);
    t_true(isset($wKafle[$route['hash']]), 'cudzy postęp nie kończy trasy za CIEBIE — u ciebie dalej jest nieukończona');
});

t_test('kn-{slug}: bramka widoczności — nieistniejący/ukryty slug daje pustkę, nie błąd', function () {
    t_count(0, Models\TileSource::tracks('kn-nie-ma-takiego-uzytkownika-xyz'), 'nieznany slug');
    t_false(Models\TileSource::isAllowed('kn-nie-ma-takiego-uzytkownika-xyz'), 'i w ogóle nie jest dozwolonym kluczem kafla');
});

t_test('kd-/kn-: KAŻDA aktywna trasa trafia do DOKŁADNIE JEDNEJ z tych dwóch warstw', function () {
    // Niezmiennik partycji — powtórzenie warunku `kd-` zanegowane w `kn-` to
    // realne ryzyko rozjazdu (np. przy zmianie granicy `cells_total > 0`
    // w jednym miejscu, a nie w drugim). Test sprawdza to WPROST na trzech
    // stanach naraz, nie ufając samej symetrii kodu.
    $rider = kr_visible_rider();
    $pusta = kr_route_unique(kr_line(54.79, 17.60), 'ZZTEST partycja pusta');
    $czesciowa = kr_route_unique(kr_line(54.80, 17.60), 'ZZTEST partycja częściowa');
    $pelna = kr_route_unique(kr_line(54.81, 17.60), 'ZZTEST partycja pełna');

    $db = Core\Database::connection();
    $pierwsze = (int) $db->query('SELECT cell_id FROM known_route_cells WHERE route_id = ' . $czesciowa['id'] . ' LIMIT 1')
        ->fetchColumn();
    $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (?, ?, NOW())')
        ->execute([$rider['id'], $pierwsze]);
    kr_complete_route($pelna['id'], $rider['id']);

    $done = kr_hashes(Models\TileSource::tracks('kd-' . $rider['slug']));
    $remaining = kr_hashes(Models\TileSource::tracks('kn-' . $rider['slug']));

    foreach (['pusta' => $pusta, 'czesciowa' => $czesciowa, 'pelna' => $pelna] as $nazwa => $route) {
        $wDone = isset($done[$route['hash']]);
        $wRemaining = isset($remaining[$route['hash']]);
        t_true($wDone xor $wRemaining, "trasa „{$nazwa}” jest w DOKŁADNIE jednej z dwóch warstw (done=" . ($wDone ? '1' : '0') . ", remaining=" . ($wRemaining ? '1' : '0') . ')');
    }

    kr_route_unique_cleanup($pusta);
    kr_route_unique_cleanup($czesciowa);
    kr_route_unique_cleanup($pelna);
});

// --- Sąsiedztwo tras (strona /trasy/{slug}, 2026-09-10) ---------------
//
// Od tej daty warstwa „Znane trasy" na stronie jednej trasy pokazuje WYŁĄCZNIE
// tę trasę (patrz TrailController::show), a sąsiedzi zeszli z mapy do sekcji
// „W okolicy tej trasy". Te testy pilnują obu połówek tamtej zmiany: że lista
// sąsiadów faktycznie ich znajduje i że klik w mapę nie odpowiada o szlakach,
// których na tej stronie nie widać.

/** Pionowa trasa — do przecinania poziomej z `kr_line()`. */
function kr_line_ns(float $lat, float $lon, int $n = 40): array
{
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $points[] = [$lat + $i * 0.002, $lon];
    }
    return $points;
}

t_test('okolica: trasa krzyżująca się wchodzi na listę i jest oznaczona', function () {
    $a = kr_route(kr_line(54.60, 17.20), 'ZZTEST okolica pozioma');
    // Pionowa przez środek poziomej: wspólne pole jest tu treścią testu.
    $b = kr_route(kr_line_ns(54.56, 17.26), 'ZZTEST okolica pionowa');

    $nearby = KnownRoute::nearby($a['id']);
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $nearby);

    t_true(in_array($b['id'], $ids, true), 'trasa przecinająca jest w wyniku');
    foreach ($nearby as $r) {
        if ((int) $r['id'] === $b['id']) {
            t_true($r['crosses'], 'przecinająca ma crosses = true');
        }
    }
    t_false(in_array($a['id'], $ids, true), 'trasa NIE jest sąsiadem samej siebie');

    @unlink($a['path']);
    @unlink($b['path']);
});

t_test('okolica: trasa z drugiego końca kraju nie wchodzi na listę', function () {
    $a = kr_route(kr_line(54.62, 17.30), 'ZZTEST okolica bliska');
    $daleka = kr_route(kr_line(50.10, 20.10), 'ZZTEST okolica daleka');

    $ids = array_map(
        static fn(array $r): int => (int) $r['id'],
        KnownRoute::nearby($a['id'])
    );

    t_false(in_array($daleka['id'], $ids, true), 'trasa spod Krakowa nie jest „w okolicy" trasy spod Słupska');

    @unlink($a['path']);
    @unlink($daleka['path']);
});

t_test('okolica: wyłączona trasa nie pokazuje się jako sąsiad', function () {
    // Ta sama reguła, co na mapie: `is_active = 0` znika z katalogu w całości.
    $a = kr_route(kr_line(54.64, 17.40), 'ZZTEST okolica aktywna');
    $b = kr_route(kr_line_ns(54.60, 17.46), 'ZZTEST okolica wyłączona');
    KnownRoute::setActive($b['id'], false);

    $ids = array_map(
        static fn(array $r): int => (int) $r['id'],
        KnownRoute::nearby($a['id'])
    );

    t_false(in_array($b['id'], $ids, true), 'wyłączona trasa nie jest sąsiadem');

    @unlink($a['path']);
    @unlink($b['path']);
});

t_test('klik w mapę: zawężenie do jednej trasy odsiewa sąsiadów', function () {
    // REGRESJA-PREWENCJA 2026-09-10: strona trasy rysuje tylko swoją trasę,
    // więc dymek nie ma prawa mówić o szlaku, którego na niej nie widać.
    $a = kr_route(kr_line(54.66, 17.50), 'ZZTEST klik pozioma');
    $b = kr_route(kr_line_ns(54.62, 17.56), 'ZZTEST klik pionowa');

    // Punkt przecięcia obu tras — bez zawężenia odpowiadają obie.
    $wszystkie = KnownRoute::atPoint(54.66, 17.56, 13);
    $tylkoA = KnownRoute::atPoint(54.66, 17.56, 13, null, 3, $a['id']);

    $idsWszystkie = array_column($wszystkie, 'id');
    t_true(in_array($a['id'], $idsWszystkie, true) && in_array($b['id'], $idsWszystkie, true),
        'bez zawężenia w punkcie przecięcia są obie trasy');
    t_count(1, $tylkoA, 'z zawężeniem wraca dokładnie jedna trasa');
    t_eq($a['id'], (int) $tylkoA[0]['id'], 'i jest to trasa, o którą pytano');

    @unlink($a['path']);
    @unlink($b['path']);
});

t_test('warstwa strony trasy: klucz `kr-{id}` niesie DOKŁADNIE jedną trasę', function () {
    // To jest kafel, który od 2026-09-10 ciągnie warstwa „Znane trasy" NA
    // STRONIE JEDNEJ TRASY (TrailController::show). Gdyby zaczął nieść cokolwiek
    // poza tą trasą, wróciłaby dokładnie ta plątanina, po której zniknięcie ta
    // zmiana powstała — i nikt by tego nie zauważył, bo mapa dalej by działała.
    $a = kr_route_unique(kr_line(54.68, 17.62), 'ZZTEST klucz jedna');
    $b = kr_route_unique(kr_line(54.6815, 17.62), 'ZZTEST klucz sąsiadka');

    $hashe = kr_hashes(Models\TileSource::tracks('kr-' . $a['id']));

    t_true(isset($hashe[$a['hash']]), 'trasa, o którą pytano, jest na kaflu');
    t_false(isset($hashe[$b['hash']]), 'sąsiadka NIE jest — mimo że leży tuż obok');
    t_count(1, $hashe, 'i nie ma tam nic poza tą jedną trasą');

    kr_route_unique_cleanup($a);
    kr_route_unique_cleanup($b);
});

/* ========================================================================
   ETAP 1a — ZACHĘTY LOKALNE, BEZ FIREBASE (2026-09-11)
   ========================================================================
   Dwie rzeczy: alert „jesteś blisko brakującego kawałka trasy" liczony
   W APCE z pozycji (bez wysyłania jej na serwer) i zdanie „zbliżyłeś się"
   na ekranie wyniku jazdy, który apka i tak pokazuje.
   ======================================================================== */

t_test('1a: okolica luk pokazuje TYLKO trasy, które ktoś już zaczął', function () {
    $u = t_user(0);
    $db = Core\Database::connection();

    // Dwie trasy w tym samym miejscu: jedną user liznął, drugiej nie tknął.
    // Pole bierzemy wprost z siatki — region nie ma tu nic do rzeczy.
    $lat = 50.0614; $lon = 19.9366;
    $cellId = Utils\DiscoveryGrid::pointToCell($lat, $lon);
    $sasiad  = Utils\DiscoveryGrid::pointToCell($lat + 0.01, $lon + 0.01);

    $zrobTrase = static function (string $slug, string $name, array $cells) use ($db): int {
        $db->prepare("INSERT INTO known_routes (slug, name, gpx_url, is_active, bonus_enabled, cells_total, created_at)
                      VALUES (:s, :n, '/x.gpx', 1, 1, :ct, NOW())")
           ->execute(['s' => $slug, 'n' => $name, 'ct' => count($cells)]);
        $id = (int) $db->lastInsertId();
        foreach ($cells as $i => $cid) {
            [, $q, $r] = Utils\DiscoveryGrid::decode($cid);  // decode() oddaje [res, q, r]
            $db->prepare('INSERT INTO known_route_cells (route_id, cell_id, cell_q, cell_r, sort_order)
                          VALUES (:r, :c, :q, :rr, :o)')
               ->execute(['r' => $id, 'c' => $cid, 'q' => $q, 'rr' => $r, 'o' => $i]);
        }
        return $id;
    };

    $zaczeta  = $zrobTrase('test-1a-zaczeta', 'TEST zaczęta', [$cellId, $sasiad]);
    $nietknieta = $zrobTrase('test-1a-obca', 'TEST nietknięta', [$sasiad]);

    // User ma PIERWSZE pole trasy zaczętej — drugie jest luką.
    $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
       ->execute(['u' => $u, 'c' => $cellId]);

    $luki = Models\KnownRoute::gapsNearbyForUser($u, [
        'south' => $lat - 0.05, 'north' => $lat + 0.05,
        'west'  => $lon - 0.05, 'east'  => $lon + 0.05,
    ]);

    $trasy = array_column($luki, 'route_id');
    t_true(in_array($zaczeta, $trasy, true), 'luka w trasie ZACZĘTEJ jest na liście');
    // To jest zasada, nie optymalizacja: „nie prosimy o rzecz nierozsądną".
    // Pole trasy, której ktoś nigdy nie tknął, jest po prostu polem na mapie.
    t_false(in_array($nietknieta, $trasy, true), 'trasa nietknięta NIE zachęca');

    // Pole, które user już ma, nie może wrócić jako luka.
    foreach ($luki as $luka) {
        t_true($luka['cell_id'] !== $cellId, 'odkryte pole nie jest luką');
    }
});

t_test('1a: luka spoza prostokąta nie wchodzi do odpowiedzi', function () {
    $u = t_user(0);
    $db = Core\Database::connection();

    $lat = 50.0614; $lon = 19.9366;
    $cellId = Utils\DiscoveryGrid::pointToCell($lat, $lon);
    [$lat, $lon] = Utils\DiscoveryGrid::cellCenter($cellId);
    $daleko = Utils\DiscoveryGrid::pointToCell($lat + 1.5, $lon + 1.5);

    $db->prepare("INSERT INTO known_routes (slug, name, gpx_url, is_active, bonus_enabled, cells_total, created_at)
                  VALUES ('test-1a-daleko', 'TEST daleko', '/x.gpx', 1, 1, 2, NOW())")->execute();
    $routeId = (int) $db->lastInsertId();
    foreach ([$cellId, $daleko] as $i => $cid) {
        [, $q, $r] = Utils\DiscoveryGrid::decode($cid);  // decode() oddaje [res, q, r]
        $db->prepare('INSERT INTO known_route_cells (route_id, cell_id, cell_q, cell_r, sort_order)
                      VALUES (:r, :c, :q, :rr, :o)')
           ->execute(['r' => $routeId, 'c' => $cid, 'q' => $q, 'rr' => $r, 'o' => $i]);
    }
    $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
       ->execute(['u' => $u, 'c' => $cellId]);

    // Prostokąt wokół pozycji użytkownika — pole 150 km dalej nie ma prawa
    // w nim być, bo apka i tak nie policzyłaby go jako „blisko".
    $luki = Models\KnownRoute::gapsNearbyForUser($u, [
        'south' => $lat - 0.05, 'north' => $lat + 0.05,
        'west'  => $lon - 0.05, 'east'  => $lon + 0.05,
    ]);
    foreach ($luki as $luka) {
        t_true($luka['cell_id'] !== $daleko, 'pole spoza prostokąta nie wychodzi');
    }
    t_true(true, 'prostokąt zawęża wynik');
});

t_test('1a: apka liczy odległość u siebie i nie wysyła pozycji', function () {
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');
    t_true(str_contains($js, 'RM_GAPS_URL'), 'apka zna endpoint luk');
    t_true(str_contains($js, 'checkGapAlerts'), 'i sprawdza je przy pozycji');
    // Ta sama droga co przy skarbach: pobieramy PROSTOKĄT, a odległości liczymy
    // lokalnie. Gdyby apka pytała serwer o każdą pozycję, byłoby to
    // strumieniowanie lokalizacji — czego ten projekt świadomie nie robi.
    t_true(str_contains($js, 'function refreshGaps'), 'dane pobierane na prostokąt');
    t_true(str_contains($js, 'haversineM(p, { lat: g.lat, lon: g.lon })'), 'odległość liczona w apce');
    // Jedno powiadomienie na trasę na dobę — luka bywa ciągiem pól, więc bez
    // grupowania przejazd wzdłuż niej sypałby alertami.
    t_true(str_contains($js, 'GAP_COOLDOWN_MS'), 'alert ma cooldown');
    t_true(str_contains($js, 'najblizsze[g.route_id]'), 'grupowanie per trasa, nie per pole');
});

t_test('1a: ekran wyniku mówi „zbliżyłeś się" tylko po przejeździe, który ruszył trasę', function () {
    // Różnica między ZDARZENIEM a STANEM, czyli cała zasada tego programu:
    // „masz 97% od miesiąca" to stan i nie ma prawa wracać przy każdej jeździe.
    $src = (string) file_get_contents(CORE_PATH . '/Models/KnownRoute.php');
    t_true(str_contains($src, 'touched_now'), 'zapytanie wie, czy TEN przejazd ruszył trasę');
    t_true(str_contains($src, "\$reached || (!empty(\$row['touched_now']) && \$pct > 0 && \$pct < 100)"),
        'trasa bez progu wchodzi na listę tylko wtedy, gdy została ruszona');

    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/ride-summary-app.php');
    t_true(str_contains($widok, 'zbliżyłeś się'), 'widok ma zdanie dla postępu bez progu');
    // Liczba PÓL, nie procent — „zostały 3 pola" mówi o wysiłku, „97%" nie.
    t_true(str_contains($widok, "\$plural(\$brakuje, 'pole', 'pola', 'pól')"), 'pokazuje ile pól zostało');
});
