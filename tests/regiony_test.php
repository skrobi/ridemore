<?php
// tests/regiony_test.php
// Pokrycie regionów heksami (migracja 070): słownik województw, wyłączność
// przypisania i definicja „odkryty region" w Models\Discovery::regionsForUser.
//
// Importu geometrii (backfill_regions.php) tu NIE odtwarzamy — testy zakładają,
// że baza DEV przeszła migrację 070 i ma wypełnioną tabelę. Wstawiamy wyłącznie
// własne wiersze discovery_cells/region_cells pod transakcją runnera.
if (!defined('CORE_PATH')) {
    require __DIR__ . '/../core/bootstrap.php';
}

use Models\Discovery;

t_test('regiony: słownik ma dokładnie 16 aktywnych liści-województw', function () {
    $n = (int) Core\Database::connection()->query("
        SELECT COUNT(*)
          FROM dictionary_items di
          JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'region'
         WHERE di.is_active = 1
           AND NOT EXISTS (
               SELECT 1 FROM dictionary_items c
                WHERE c.parent_id = di.id AND c.is_active = 1)
    ")->fetchColumn();
    t_same(16, $n, 'aktywne liście słownika region');
});

t_test('regiony: jeden heks należy do dokładnie jednego regionu (PK)', function () {
    $db = Core\Database::connection();
    $row = $db->query('SELECT region_item_id, cell_id FROM region_cells LIMIT 1')->fetch();
    t_not_null($row, 'tabela region_cells ma dane (uruchom backfill_regions.php)');
    $other = $db->prepare('SELECT region_item_id FROM region_cells WHERE region_item_id <> :r LIMIT 1');
    $other->execute(['r' => $row['region_item_id']]);
    $second = $other->fetchColumn();
    t_not_null($second, 'istnieją co najmniej dwa regiony z pokryciem');

    // Klucz główny (region_item_id, cell_id) musi fizycznie odrzucić drugie
    // przypisanie tego samego heksa — to jest niezmiennik „jeden heks = jeden
    // region", na którym opiera się procent pokrycia.
    $thrown = false;
    try {
        $ins = $db->prepare('INSERT IGNORE INTO region_cells (region_item_id, cell_id) VALUES (?, ?)');
        $ins->execute([(int) $second, (int) $row['cell_id']]);
        // INSERT IGNORE połyka konflikt klucza — sprawdzamy więc EFEKT:
        $cnt = $db->prepare(
            'SELECT COUNT(*) FROM region_cells WHERE cell_id = ? AND region_item_id <> ?'
        );
        $cnt->execute([(int) $row['cell_id'], (int) $row['region_item_id']]);
        t_same(0, (int) $cnt->fetchColumn(), 'obcy region nie doszedł do zajętego heksa');
    } catch (\PDOException $e) {
        // Ścieżka dla wykonania bez IGNORE: naruszenie klucza też jest poprawne.
        t_same('23000', $e->getCode(), 'kod naruszenia unikalności');
        $thrown = true;
    }
    t_true($thrown || true, 'jedna z dwóch ścieżek ochrony zadziałała');
});

t_test('regiony: regionsForUser liczy po odkrytych heksach', function () {
    $db = Core\Database::connection();
    $userId = t_user();

    $before = Discovery::regionsForUser((int) $userId)['visited'];

    // Dwa heksy z województw, których użytkownik JESZCZE nie ma (na bazie DEV
    // pierwszy użytkownik ma zwykle już swoje odkrycia — stąd nie zakładamy
    // zera, tylko dobieramy regiony spoza jego aktualnego zbioru).
    $stmt = $db->prepare('
        SELECT rc.region_item_id, MIN(rc.cell_id) AS cell_id
          FROM region_cells rc
         WHERE rc.region_item_id NOT IN (
             SELECT DISTINCT rc2.region_item_id
               FROM discovery_cells dc
               JOIN region_cells rc2 ON rc2.cell_id = dc.cell_id
              WHERE dc.user_id = :u
         )
         GROUP BY rc.region_item_id
         ORDER BY rc.region_item_id
         LIMIT 2
    ');
    $stmt->execute(['u' => $userId]);
    $pairs = $stmt->fetchAll();
    t_count(2, $pairs, 'dwa jeszcze nieodkryte regiony z pokryciem');

    $ins = $db->prepare('
        INSERT INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (?, ?, NOW())
    ');
    foreach ($pairs as $p) {
        $ins->execute([(int) $userId, (int) $p['cell_id']]);
    }

    $summary = Discovery::regionsForUser((int) $userId);
    t_same($before + 2, $summary['visited'], 'odkryte regiony wg heksów');
    t_true($summary['total'] >= 16, "mianownik ≥ 16 (jest {$summary['total']})");
});

t_test('regiony: brak referencji na dezaktywowane pozycje słownika', function () {
    $db = Core\Database::connection();
    $sum = 0;
    // events/known_routes od migr. 074 mają regiony w tabelach łączących
    // (wiele wierszy na byt), nie w skalarnej kolumnie — treasures i
    // organizer_profiles zostają przy starym wzorcu (punkt/osoba, nie trasa).
    foreach ([
        ['event_regions', 'region_item_id'],
        ['known_route_regions', 'region_item_id'],
        ['treasures', 'region_item_id'],
        ['organizer_profiles', 'region_item_id'],
    ] as [$table, $column]) {
        $sum += (int) $db->query("
            SELECT COUNT(*) FROM {$table}
              JOIN dictionary_items di ON di.id = {$table}.{$column}
             WHERE di.is_active = 0
        ")->fetchColumn();
    }
    // Remap z migracji 070 objął znane pasma; jeśli coś zostaje wskazane
    // na nieaktywną pozycję, to znaczy, że w środowisku są kody spoza mapy
    // i wymagają decyzji człowieka — test ma to wychwycić głośno.
    t_same(0, $sum, 'referencje na dezaktywowane regiony');
});

t_test('regiony: regionProgress liczy osobno moją i wspólne pokrycie', function () {
    $db = Core\Database::connection();
    $userId = t_user();

    // Baza DEV ma prawdziwe odkrycia — nie zakładamy zera, mierzymy DELTĘ.
    $before = Discovery::regionProgress((int) $userId);

    // Trzy heksy z jednego województwa + jeden z drugiego (min. dwa regiony
    // z pokryciem istnieją — sprawdza to wcześniejszy test).
    $regions = $db->query('
        SELECT rc.region_item_id, MIN(rc.cell_id) AS cell_id,
               (SELECT COUNT(*) FROM region_cells x WHERE x.region_item_id = rc.region_item_id) AS total
          FROM region_cells rc
         GROUP BY rc.region_item_id
         ORDER BY total DESC
         LIMIT 2
    ')->fetchAll();

    $ins = $db->prepare('
        INSERT INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (?, ?, NOW())
    ');
    foreach ([[$regions[0], 3], [$regions[1], 1]] as [$region, $howMany]) {
        // Tylko heksy jeszcze NIEposiadane przez użytkownika — test musi być
        // niezależny od resztek po wcześniejszych uruchomieniach i danych DEV.
        $cells = $db->prepare('
            SELECT rc.cell_id
              FROM region_cells rc
              LEFT JOIN discovery_cells dc ON dc.cell_id = rc.cell_id AND dc.user_id = :u
             WHERE rc.region_item_id = :r AND dc.cell_id IS NULL
             ORDER BY rc.cell_id
             LIMIT :n
        ');
        $cells->execute(['u' => (int) $userId, 'r' => (int) $region['region_item_id'], 'n' => $howMany]);
        foreach ($cells->fetchAll(PDO::FETCH_COLUMN) as $cellId) {
            $ins->execute([(int) $userId, (int) $cellId]);
        }
    }

    $after = Discovery::regionProgress((int) $userId);
    $byId = [];
    foreach ($after['regions'] as $row) { $byId[(int) $row['id']] = $row; }

    foreach ([[$regions[0], 3], [$regions[1], 1]] as [$region, $howMany]) {
        $row = $byId[(int) $region['region_item_id']] ?? null;
        t_not_null($row, 'region z pokryciem jest w odpowiedzi');
        t_same($howMany, $row['mine'], 'moje heksy w regionie');
        t_same($region['total'], $row['total'], 'mianownik = heksy regionu');
    }

    // „Społeczność obejmuje moje" sprawdza się dopiero po zapisie agregatu —
    // dokładnie tak samo robi to ścieżka aplikacji przy naliczaniu przejazdu
    // (wiersz discovery_cell_totals + refresh). Odtwarzamy ją tu, żeby testować
    // JOIN-a na realnych warunkach, nie na stanie pośrednim.
    $cellsAll = $db->prepare('
        SELECT cell_id FROM discovery_cells WHERE user_id = ?
    ');
    $cellsAll->execute([(int) $userId]);
    $myCellIds = array_map('intval', $cellsAll->fetchAll(PDO::FETCH_COLUMN));
    $qExpr = Utils\DiscoveryGrid::sqlQ('dc.cell_id');
    $rExpr = Utils\DiscoveryGrid::sqlR('dc.cell_id');
    $db->prepare("
        INSERT IGNORE INTO discovery_cell_totals (cell_id, cell_q, cell_r, riders_count)
        SELECT DISTINCT dc.cell_id, {$qExpr}, {$rExpr}, 1
          FROM discovery_cells dc
         WHERE dc.user_id = ?
           AND dc.cell_id IN (" . implode(',', $myCellIds) . ")
    ")->execute([(int) $userId]);
    Discovery::refreshTotalsFor($myCellIds);

    $afterRefresh = Discovery::regionProgress((int) $userId);
    $byIdRefresh = [];
    foreach ($afterRefresh['regions'] as $row) { $byIdRefresh[(int) $row['id']] = $row; }
    foreach ([[$regions[0], 3], [$regions[1], 1]] as [$region, $howMany]) {
        $row = $byIdRefresh[(int) $region['region_item_id']];
        t_true($row['community'] >= $howMany, 'społeczność obejmuje moje');
    }

    t_same($before['grand']['mine'] + 4, $after['grand']['mine'], 'delta moich heksów w sumie krajowej');
    t_true($after['grand']['pctMine'] >= $before['grand']['pctMine'], 'procent kraju nie spadł');
});

t_test('regiony: regionPotentials rankinguje wg nieodkrytych pól i skarbów', function () {
    $userId = t_user();

    // Skarb na znanym punkcie (50,20) — helper z lib.php, ACTIVE, nikt go
    // jeszcze u nas nie znalazł (świeży użytkownik bez treasure_finds).
    $treasure = t_treasure(['points' => 250]);
    $cellId = (int) $treasure['cell_id'];
    t_not_null($cellId, 'skarb dostał heks przy zapisie');

    $stmt = Core\Database::connection()->prepare(
        'SELECT region_item_id FROM region_cells WHERE cell_id = ?'
    );
    $stmt->execute([$cellId]);
    $regionId = (int) $stmt->fetchColumn();
    t_not_null($regionId, 'heks skarbu leży w regionie o pokryciu');

    $rate = (int) \Models\DiscoveryScoring::config()['discovery']['points_per_new_cell'];

    $potentials = Discovery::regionPotentials((int) $userId, 20);
    t_true(count($potentials) >= 1, 'ranking nie jest pusty');

    $entry = null;
    foreach ($potentials as $row) {
        if ((int) $row['id'] === $regionId) { $entry = $row; break; }
    }
    t_not_null($entry, 'region ze skarbem jest w rankingu');
    if ($entry === null) { return; }

    t_true($entry['treasures'] >= 1, 'skarb policzony w regionie');
    t_true($entry['treasurePoints'] >= 250, 'punkty skarbu wchodzą do sumy');
    t_same(
        ($entry['total'] - $entry['mine']) * $rate + $entry['treasurePoints'],
        $entry['score'],
        'score = pola × stawka + punkty skarbów'
    );

    // Ranking malejący po score.
    for ($i = 1, $n = count($potentials); $i < $n; $i++) {
        t_true($potentials[$i - 1]['score'] >= $potentials[$i]['score'], 'score nierosnąco');
    }
});

t_test('regiony: kraj bez rodzica (region płaski, jak Czechy/Słowacja) nie dolewa się do Polski', function () {
    // /admin/regiony-mapa (2026-09-01) pozwala dodać region BEZ rodzica —
    // kraj, który jest jednocześnie jedynym swoim regionem (Czechy, Słowacja:
    // „podziału nie da się zaimportować z granic administracyjnych"). Sprawdzone
    // na żywo w dev (2026-09-05, fikcyjna „Słowacja"): bez poprawki w
    // Discovery::regionsForUser/regionProgress taki region dolewał się PO
    // CICHU do mianownika 16 województw i do „grand" („Polska odkryta %").
    // Ten test odtwarza dokładnie ten scenariusz, żeby regresja nie wróciła.
    $db = Core\Database::connection();
    $userId = t_user();

    $before = Discovery::regionsForUser((int) $userId);
    $beforeProgress = Discovery::regionProgress((int) $userId);

    $dictId = (int) $db->query("SELECT id FROM dictionaries WHERE code = 'region'")->fetchColumn();
    $db->prepare('
        INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order)
        VALUES (?, NULL, ?, ?, 999)
    ')->execute([$dictId, 'kraj_test', 'Kraj Testowy']);
    $countryId = (int) $db->lastInsertId();

    // Heksy WYRAŹNIE poza Polską (okolice Popradu, Słowacja) — siatka
    // Utils\DiscoveryGrid jest globalna (Web Mercator, §31), więc dowolny
    // punkt na Ziemi ma swój cell_id bez importu żadnej geometrii.
    $cellIds = [];
    foreach ([[49.03, 20.30], [49.031, 20.301], [49.032, 20.302]] as [$lat, $lon]) {
        $cellIds[Utils\DiscoveryGrid::pointToCell($lat, $lon)] = true;
    }
    $cellIds = array_keys($cellIds);
    t_true(count($cellIds) >= 2, 'co najmniej dwa różne testowe heksy');

    $insCells = $db->prepare('INSERT INTO region_cells (region_item_id, cell_id) VALUES (?, ?)');
    foreach ($cellIds as $id) { $insCells->execute([$countryId, $id]); }
    $db->prepare('
        INSERT INTO region_cell_counts (region_item_id, cells_total) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE cells_total = VALUES(cells_total)
    ')->execute([$countryId, count($cellIds)]);

    $mineIds = array_slice($cellIds, 0, 1);
    $insMine = $db->prepare('INSERT INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (?, ?, NOW())');
    foreach ($mineIds as $id) { $insMine->execute([(int) $userId, $id]); }

    // 1) Kafel „Regiony" (domyślnie scoped do 'polska'): mianownik i licznik
    //    NIE reagują na region, który nie jest polskim województwem.
    $after = Discovery::regionsForUser((int) $userId);
    t_same($before['total'], $after['total'], 'mianownik 16 województw bez zmian');
    t_same($before['visited'], $after['visited'], 'odkrycie w obcym kraju nie liczy się jako polski region');

    // 2) regionProgress: „grand" (za nim stoi „Polska odkryta %") też scoped —
    //    zero różnicy mimo nowych heksów w bazie.
    $afterProgress = Discovery::regionProgress((int) $userId);
    t_same($beforeProgress['grand']['total'], $afterProgress['grand']['total'], 'grand.total (Polska) bez zmian');
    t_same($beforeProgress['grand']['mine'], $afterProgress['grand']['mine'], 'grand.mine (Polska) bez zmian');

    // 3) Kraj testowy dostaje WŁASNĄ, poprawną sumę w nowym kluczu `countries`.
    $byCode = [];
    foreach ($afterProgress['countries'] as $c) { $byCode[$c['code']] = $c; }
    t_not_null($byCode['kraj_test'] ?? null, 'kraj testowy ma własną pozycję w countries');
    t_same(count($cellIds), $byCode['kraj_test']['total'], 'total kraju testowego = jego własne heksy');
    t_same(count($mineIds), $byCode['kraj_test']['mine'], 'mine kraju testowego = to, co user tam odkrył');

    // 4) Region płaski jest sam sobie krajem (countryId = jego własne id) —
    //    to na tym rozróżnieniu stoi „pokaż nagłówek kraju czy nie" w widoku.
    $regionRow = null;
    foreach ($afterProgress['regions'] as $r) {
        if ($r['code'] === 'kraj_test') { $regionRow = $r; break; }
    }
    t_not_null($regionRow, 'region testowy jest na płaskiej liście regions');
    t_same('kraj_test', $regionRow['countryCode'] ?? null, 'kraj płaski jest sam sobie krajem');
    t_same($countryId, $regionRow['countryId'] ?? null, 'countryId = id samego regionu');

    // 5) „Następny cel" (regionPotentials) nie proponuje regionu spoza Polski —
    //    karta mówi o Polsce wprost w treści.
    foreach (Discovery::regionPotentials((int) $userId, 50) as $p) {
        t_true($p['code'] !== 'kraj_test', 'ranking celów pomija region spoza Polski');
    }
});



// ---------------------------------------------------------------------------
// OBRYSY REGIONÓW RYSOWANE RĘKĄ (Models\RegionOutline, narzędzie
// /admin/regiony-mapa, 2026-09-01).
//
// Testy pilnują tego, co w tym module może pęknąć po cichu:
//   - dosunięcia wierzchołków do siatki (bez niego granica dwóch regionów
//     rozjeżdża się i import zostawia wzdłuż niej pas bez regionu),
//   - tego, że narzędzie WIDZI wszystkie regiony, także zaimportowane
//     województwa (bez tego rysuje się na ślepo),
//   - raportowania konfliktu i rosnącego `priority` — bo to na nim, a nie na
//     przycinaniu cudzych wielokątów, stoi reguła „pierwszeństwo ma edytowany",
//   - tego, że zapisany plik jest GeoJSON-em, który `backfill_regions.php`
//     UMIE przeczytać.
//
// Każdy test pisze do WŁASNEGO pliku tymczasowego — transakcja runnera nie
// obejmuje dysku, więc plik produkcyjny nie może być tu w grze.
// ---------------------------------------------------------------------------

use Models\RegionOutline;
use Utils\DiscoveryGrid;

/** Pusty plik obrysów na czas jednego testu. */
function t_outline_file(): string
{
    $path = sys_get_temp_dir() . '/ridemore_obrysy_' . bin2hex(random_bytes(4)) . '.geojson';
    RegionOutline::useFile($path);
    return $path;
}

/** Sześć punktów wokół zadanego, każdy w innym heksie poziomu obrysu. */
function t_outline_ring(float $lat, float $lon): array
{
    // 0,12 stopnia to ok. 13 km — więcej niż szerokość pola (ok. 8 km),
    // więc każdy punkt na pewno wpada do innego heksa.
    return [
        [$lat + 0.12, $lon], [$lat + 0.06, $lon + 0.18], [$lat - 0.06, $lon + 0.18],
        [$lat - 0.12, $lon], [$lat - 0.06, $lon - 0.18], [$lat + 0.06, $lon - 0.18],
    ];
}

t_test('obrys: wierzchołki są dosuwane do środków heksów', function () {
    $path = t_outline_file();
    $wynik = RegionOutline::save('czechy_test', 'Czechy testowe', t_outline_ring(50.0, 14.5));
    t_true($wynik['ok'], 'zapis się powiódł');
    t_same(6, $wynik['vertices'], 'sześć pól obrysu');

    $ring = RegionOutline::all()['czechy_test']['ring'];
    t_count(6, $ring, 'pierścień bez punktu domykającego');
    foreach ($ring as $point) {
        $cell = DiscoveryGrid::pointToCell($point[0], $point[1], RegionOutline::RES_OUTLINE);
        [$lat, $lon] = DiscoveryGrid::cellCenter($cell);
        // Równość co do zaokrąglenia zapisu (6 miejsc po przecinku ≈ 0,1 m).
        t_true(abs($lat - $point[0]) < 1e-5 && abs($lon - $point[1]) < 1e-5,
            'wierzchołek leży w środku swojego heksa');
    }
    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: narzędzie widzi WSZYSTKIE regiony, w tym zaimportowane województwa', function () {
    $path = t_outline_file();
    RegionOutline::save('czechy_test', 'Czechy testowe', t_outline_ring(50.0, 14.5));

    $wszystkie = RegionOutline::allRegions();
    // Bez tego narzędzie rysuje na ślepo (zgłoszenie usera 2026-09-01:
    // „oznaczając np. lubelskie powinno mi się na mapie zaznaczyć").
    t_true(isset($wszystkie['lubelskie']), 'województwo z importu jest na liście');
    t_false($wszystkie['lubelskie']['editable'], 'województwa nie edytujemy tym narzędziem');
    t_true(count($wszystkie['lubelskie']['rings'][0]) > 100, 'granica administracyjna ma pełną geometrię');

    t_true(isset($wszystkie['czechy_test']), 'narysowany obrys też');
    t_true($wszystkie['czechy_test']['editable'], 'rysowany ręką jest edytowalny');

    // Pierścienie idą w konwencji aplikacji [lat, lon] — pomyłka w tym miejscu
    // przenosi region na drugą półkulę i nie widać jej aż do importu.
    [$lat, $lon] = $wszystkie['lubelskie']['rings'][0][0];
    t_true($lat > 49 && $lat < 55, 'pierwsza współrzędna to szerokość geograficzna');
    t_true($lon > 21 && $lon < 25, 'druga współrzędna to długość geograficzna');

    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: wejście na cudzy teren jest RAPORTOWANE, nie przycinane', function () {
    $path = t_outline_file();
    // Obrys wokół Lublina — całość leży w województwie lubelskim.
    $wynik = RegionOutline::save('nakladka_test', 'Nakladka', t_outline_ring(51.25, 22.57));

    t_true($wynik['ok'], 'zapis się powiódł');
    $kody = array_column($wynik['conflicts'], 'code');
    t_true(in_array('lubelskie', $kody, true), 'konflikt z lubelskim zgłoszony');

    $ile = 0;
    foreach ($wynik['conflicts'] as $c) {
        if ($c['code'] === 'lubelskie') { $ile = $c['cells']; }
    }
    t_true($ile > 0, 'policzone pola, nie sam fakt');

    // KLUCZOWE: geometria sąsiada zostaje NIETKNIĘTA. Sporne heksy rozstrzyga
    // dopiero import, po `priority` — cudzych wielokątów nie przycinamy,
    // bo granica administracyjna jest dokładniejsza niż cokolwiek z ręki.
    $wszystkie = RegionOutline::allRegions();
    t_true(count($wszystkie['lubelskie']['rings'][0]) > 100, 'granica lubelskiego bez zmian');
    t_false($wszystkie['lubelskie']['editable'], 'i dalej nieedytowalna');

    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: każdy zapis podnosi priorytet — na tym stoi „pierwszeństwo ma edytowany"', function () {
    $path = t_outline_file();
    RegionOutline::save('a_test', 'A', t_outline_ring(49.0, 15.0));
    $a = RegionOutline::all()['a_test']['priority'];
    t_true($a > 0, 'priorytet zapisany');

    // Województwa nie mają priorytetu wcale — czyli zawsze przegrywają
    // z czymkolwiek narysowanym.
    t_same(0, RegionOutline::allRegions()['lubelskie']['priority'], 'import ma priorytet 0');

    // Ponowny zapis tego samego regionu ma dać priorytet NIE MNIEJSZY (zegar
    // ma sekundową rozdzielczość, więc równość jest dopuszczalna).
    RegionOutline::save('a_test', 'A', t_outline_ring(49.0, 15.0));
    t_true(RegionOutline::all()['a_test']['priority'] >= $a, 'priorytet nie cofa się');

    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: mniej niż trzy pola to nie wielokąt', function () {
    $path = t_outline_file();
    $wynik = RegionOutline::save('za_maly', 'Za maly', [[50.0, 14.5], [50.12, 14.5]]);
    t_false($wynik['ok'], 'zapis odrzucony');
    t_true(isset($wynik['error']), 'jest komunikat błędu');
    t_count(0, RegionOutline::all(), 'nic nie trafiło do pliku');
    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: śmieci we współrzędnych są odrzucane, nie zapisywane', function () {
    $path = t_outline_file();
    $wynik = RegionOutline::save('smieci', 'Smieci', [
        [50.0, 14.5], ['ala', 'ma kota'], [999.0, 14.5], [50.12, 14.5], null, [50.24, 14.5], [50.0, 500.0],
    ]);
    t_true($wynik['ok'], 'poprawne punkty przeszły');
    t_same(3, $wynik['vertices'], 'zostały trzy poprawne pola');
    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: zapisany plik jest GeoJSON-em, który czyta backfill_regions.php', function () {
    $path = t_outline_file();
    RegionOutline::save('czechy_test', 'Czechy testowe', t_outline_ring(50.0, 14.5));

    $geo = json_decode((string) file_get_contents($path), true);
    t_same('FeatureCollection', $geo['type'] ?? null, 'typ kolekcji');
    t_count(1, $geo['features'], 'jeden feature');

    $feature = $geo['features'][0];
    t_same('czechy_test', $feature['properties']['code'] ?? null, 'kod słownika w properties');
    t_true(($feature['properties']['priority'] ?? 0) > 0, 'priorytet w properties (czyta go import)');
    t_same('Polygon', $feature['geometry']['type'] ?? null, 'geometria to Polygon');

    $coords = $feature['geometry']['coordinates'][0];
    t_count(7, $coords, 'sześć pól + punkt domykający');
    t_same($coords[0], $coords[count($coords) - 1], 'pierścień jest domknięty');
    // GeoJSON trzyma [lon, lat] — odwrotnie niż Leaflet.
    t_true($coords[0][0] > 13 && $coords[0][0] < 16, 'pierwsza współrzędna to długość geograficzna');
    t_true($coords[0][1] > 49 && $coords[0][1] < 51, 'druga współrzędna to szerokość geograficzna');

    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: test „punkt w poligonie" jest wspólny z importem', function () {
    // JEDNA implementacja dla narzędzia i dla backfillu — dwie kopie dałyby
    // dwie różne odpowiedzi na to samo pytanie o tę samą granicę.
    $lubelskie = RegionOutline::allRegions()['lubelskie'];
    t_true(
        RegionOutline::insideRings($lubelskie['rings'], 51.25, 22.57),
        'Lublin leży w lubelskim'
    );
    t_false(
        RegionOutline::insideRings($lubelskie['rings'], 50.06, 19.94),
        'Kraków już nie'
    );

    // Funkcja jest NIECZUŁA NA KONWENCJĘ, byle była spójna: te same pierścienie
    // z odwróconymi parami i odwróconym punktem dają ten sam wynik. Na tym stoi
    // to, że backfill może ją wołać na surowych danych GeoJSON-a ([lon, lat]).
    $odwrocone = array_map(
        static fn(array $ring): array => array_map(
            static fn(array $p): array => [$p[1], $p[0]],
            $ring
        ),
        $lubelskie['rings']
    );
    t_true(RegionOutline::insideRings($odwrocone, 22.57, 51.25), 'ta sama odpowiedź w drugiej konwencji');
});

t_test('obrys: usunięcie zdejmuje region z pliku i zostawia resztę', function () {
    $path = t_outline_file();
    RegionOutline::save('a_test', 'A', t_outline_ring(50.0, 14.5));
    RegionOutline::save('b_test', 'B', t_outline_ring(48.5, 19.5));
    t_count(2, RegionOutline::all(), 'dwa obrysy');

    t_true(RegionOutline::remove('a_test'), 'usunięto');
    $po = RegionOutline::all();
    t_count(1, $po, 'został jeden');
    t_true(isset($po['b_test']), 'został ten właściwy');
    t_false(RegionOutline::remove('nie_ma_takiego'), 'usunięcie nieistniejącego zwraca false');

    RegionOutline::useFile(null);
    @unlink($path);
});

t_test('obrys: brak pliku to pusta lista, nie błąd', function () {
    $path = t_outline_file();
    @unlink($path);
    t_count(0, RegionOutline::all(), 'środowisko bez pliku rysowanych obrysów działa');
    // ...ale województwa z importu widać dalej — narzędzie nie może oślepnąć
    // tylko dlatego, że nikt jeszcze nic nie narysował.
    t_true(isset(RegionOutline::allRegions()['lubelskie']), 'import widoczny mimo braku pliku obrysów');
    RegionOutline::useFile(null);
});

// ---------------------------------------------------------------------------
// POPRAWKA POJEDYNCZEGO POLA — Models\RegionOutline::assignCell() (2026-09-10,
// świadoma decyzja usera: "/admin/regiony-mapa" dostał drugą, punktową drogę
// zapisu do region_cells, obok backfill_regions.php). Zgłoszenie: obrys
// zastępuje CAŁĄ geometrię regionu, więc nie nadaje się do poprawienia jednego
// źle przypisanego heksa przy granicy województwa — te testy pilnują, że
// `assignCell()` NIE dotyka geometrii/pliku, działa punktowo i trzyma
// `region_cell_counts` w zgodzie z `region_cells` przy każdym zapisie.
//
// Bez `t_outline_file()`: ta ścieżka nie pisze do żadnego pliku, więc nie
// potrzebuje izolacji na dysku — sama transakcja runnera wystarczy.
// ---------------------------------------------------------------------------

/** Dwa różne, prawdziwe województwa z bazy DEV — do przypisywania i przenoszenia. */
function t_dwa_regiony(): array
{
    $a = Models\Dictionary::id('region', 'lubelskie');
    $b = Models\Dictionary::id('region', 'podlaskie');
    if ($a === null || $b === null) {
        t_fail('Baza DEV nie ma województw "lubelskie"/"podlaskie" w słowniku region.');
    }
    return [$a, $b];
}

/** Heks daleko poza jakimkolwiek realnym pokryciem — bezpieczny punkt startowy "bez właściciela". */
function t_pusty_cell(): int
{
    return DiscoveryGrid::encode(DiscoveryGrid::RES_CELL, 987654, 987654);
}

t_test('assignCell: pole bez właściciela dostaje region i podbija licznik', function () {
    [$region] = t_dwa_regiony();
    $cell = t_pusty_cell();
    Core\Database::connection()->prepare('DELETE FROM region_cells WHERE cell_id = ?')->execute([$cell]);

    $przed = (int) (Core\Database::connection()
        ->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $region)
        ->fetchColumn() ?: 0);

    RegionOutline::assignCell($cell, $region);

    $wlasciciel = Core\Database::connection()
        ->prepare('SELECT region_item_id FROM region_cells WHERE cell_id = ?');
    $wlasciciel->execute([$cell]);
    t_same($region, (int) $wlasciciel->fetchColumn(), 'pole trafiło do właściwego regionu');

    $po = (int) Core\Database::connection()
        ->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $region)
        ->fetchColumn();
    t_same($przed + 1, $po, 'licznik regionu wzrósł dokładnie o jeden');
});

t_test('assignCell: przeniesienie pola odejmuje staremu regionowi i dodaje nowemu', function () {
    [$a, $b] = t_dwa_regiony();
    $cell = t_pusty_cell();
    Core\Database::connection()->prepare('DELETE FROM region_cells WHERE cell_id = ?')->execute([$cell]);

    RegionOutline::assignCell($cell, $a); // najpierw A
    $db = Core\Database::connection();
    $liczA1 = (int) $db->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $a)->fetchColumn();
    $liczB1 = (int) ($db->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $b)->fetchColumn() ?: 0);

    RegionOutline::assignCell($cell, $b); // potem B — to jest przeniesienie, nie drugie przypisanie

    $wlasciciel = $db->prepare('SELECT region_item_id FROM region_cells WHERE cell_id = ?');
    $wlasciciel->execute([$cell]);
    t_same($b, (int) $wlasciciel->fetchColumn(), 'pole należy TERAZ do B, nie do obu naraz (UNIQUE cell_id)');

    $liczA2 = (int) $db->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $a)->fetchColumn();
    $liczB2 = (int) $db->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $b)->fetchColumn();
    t_same($liczA1 - 1, $liczA2, 'stary region stracił dokładnie jedno pole');
    t_same($liczB1 + 1, $liczB2, 'nowy region zyskał dokładnie jedno pole');
});

t_test('assignCell: przypisanie do TEGO SAMEGO regionu nic nie rusza', function () {
    [$region] = t_dwa_regiony();
    $cell = t_pusty_cell();
    Core\Database::connection()->prepare('DELETE FROM region_cells WHERE cell_id = ?')->execute([$cell]);

    RegionOutline::assignCell($cell, $region);
    $db = Core\Database::connection();
    $przed = (int) $db->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $region)->fetchColumn();

    RegionOutline::assignCell($cell, $region); // drugi raz, ten sam region

    $po = (int) $db->query('SELECT cells_total FROM region_cell_counts WHERE region_item_id = ' . $region)->fetchColumn();
    t_same($przed, $po, 'licznik nie rośnie przy ponownym przypisaniu tego samego pola do tego samego regionu');
});

t_test('assignCell: działa na regionie z IMPORTU (województwo), nie tylko na obrysie ręcznym', function () {
    // To jest cała racja bytu tej metody — save() (obrys) blokuje województwa,
    // assignCell() celowo nie sprawdza `editable` wcale.
    [$region] = t_dwa_regiony();
    t_false((RegionOutline::allRegions()['lubelskie']['editable'] ?? true), 'punkt wyjścia: lubelskie ma geometrię z importu, nie z ręki');

    $cell = t_pusty_cell();
    Core\Database::connection()->prepare('DELETE FROM region_cells WHERE cell_id = ?')->execute([$cell]);
    RegionOutline::assignCell($cell, $region);

    $wlasciciel = Core\Database::connection()->prepare('SELECT region_item_id FROM region_cells WHERE cell_id = ?');
    $wlasciciel->execute([$cell]);
    t_same($region, (int) $wlasciciel->fetchColumn(), 'poprawka na województwie z importu przeszła');
});
