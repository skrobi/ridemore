<?php
// tests/planner_test.php
// ROUTE PLANNER — matematyka RouteSnap (czysta, bez sieci/bazy), tryb bazy
// liczony bez sieci (punkty leżą na bazie, więc nie ma dojazdów OSM),
// własność (IDOR) na Models\PlannedRoute i walidacyjne ścieżki brzegowe,
// które NIE wychodzą w sieć. Tryb „Wszystkie zaznaczone źródła" potrzebuje
// żywego OSRM — sprawdzany na żywo (md/features.md, Route Planner).
use Controllers\PlannerController;
use Models\GpxGeometry;
use Models\KnownRoute;
use Models\PlannedRoute;
use Utils\RouteSnap;
use Utils\RoutingProxy;
use Utils\TileGrid;

// Syntetyczne linie w pikselach STORE_Z gdzieś w Polsce (~0,38 m/px).
const PT_X = 37280000;
const PT_Y = 22200000;

/** Pozioma łamana od (x0,y) do (x1,y) co $step px — płaska, jak w GpxGeometry. */
function pt_flat_h(int $x0, int $x1, int $y, int $step = 20): array
{
    $flat = [];
    for ($x = $x0; $x <= $x1; $x += $step) {
        $flat[] = $x;
        $flat[] = $y;
    }
    return $flat;
}

/** Dopasowanie trasy do linii i wybór odcinków — ścieżka jak w PlannerController::preferSegment. */
function pt_prefer(array $routePx, array $flats, array $ranks, ?float $joinRatio, float $thr = 150.0): array
{
    [$dense, $orig] = RouteSnap::densify($routePx, 60.0);
    $xs = array_column($dense, 0);
    $ys = array_column($dense, 1);
    $bbox = [(int) (min($xs) - $thr), (int) (min($ys) - $thr), (int) (max($xs) + $thr), (int) (max($ys) + $thr)];
    $cell = (int) ceil($thr);
    $near = RouteSnap::nearestAlong($dense, $flats, RouteSnap::gridIndex($flats, $cell, $bbox), $cell, $thr, 50.0);
    $runs = RouteSnap::preferredRuns(RouteSnap::cumulative($dense), $ranks, $flats, $near, [
        'minRunPx' => 500.0, 'snapRatio' => 1.2, 'joinRatio' => $joinRatio, 'joinSlackPx' => 500.0,
        'jumpSlackPx' => 300.0, 'gapPts' => 3,
    ]);
    return ['dense' => $dense, 'orig' => $orig, 'runs' => $runs];
}

// --- RouteSnap: czysta geometria --------------------------------------

t_test('haversineM: 1 stopień szerokości to ok. 111 km', function () {
    $d = RouteSnap::haversineM(50.0, 20.0, 51.0, 20.0);
    t_true($d > 110_000 && $d < 112_000, 'dystans w granicach ~111 km, jest ' . round($d));
});

t_test('haversineM: ten sam punkt daje zero', function () {
    t_same(0.0, RouteSnap::haversineM(50.0, 20.0, 50.0, 20.0), 'zero dla identycznych punktów');
});

t_test('lineLengthM: suma odcinków, nie odległość prosta między końcami', function () {
    // Zygzak — suma DWÓCH RÓŻNYCH boków (lon-delta i lat-delta NIE są tej
    // samej długości fizycznej — stopień długości geograficznej jest krótszy
    // o cos(szerokości)), więc licząc oczekiwaną sumę bierzemy każdy bok osobno,
    // a nie zakładamy kwadrat.
    $line = [[50.0, 20.0], [50.0, 20.001], [50.001, 20.001]];
    $bok1 = RouteSnap::haversineM(50.0, 20.0, 50.0, 20.001);
    $bok2 = RouteSnap::haversineM(50.0, 20.001, 50.001, 20.001);
    $len = RouteSnap::lineLengthM($line);
    t_true(abs($len - ($bok1 + $bok2)) < 1.0, 'długość to suma obu boków, jest ' . round($len) . ' vs ' . round($bok1 + $bok2));
    t_true($bok1 < $bok2, 'bok wzdłuż długości geogr. jest krótszy niż bok wzdłuż szerokości (cos(lat))');
});

t_test('densify: gęste punkty co krok, oryginalne oznaczone', function () {
    [$pts, $orig] = RouteSnap::densify([[0, 0], [1000, 0]], 100.0);
    t_count(11, $pts, 'dwa oryginalne + 9 wstawionych');
    t_same([0, 0], $pts[0], 'początek bez zmian');
    t_same([1000, 0], $pts[10], 'koniec bez zmian');
    t_same([true, false, false, false, false, false, false, false, false, false, true], $orig, 'maska oryginałów');
});

t_test('nearestAlong: trasa równoległa w progu jest dopasowana, dalsza — nie; luka GPS też się liczy', function () {
    $line = [PT_X, PT_Y, PT_X + 3000, PT_Y]; // JEDEN długi odcinek (luka GPS) — bez wierzchołków w środku
    $route = [];
    for ($x = PT_X + 500; $x <= PT_X + 2500; $x += 250) {
        $route[] = [$x, PT_Y + 100];
    }
    $grid = RouteSnap::gridIndex([$line], 150, [PT_X - 200, PT_Y - 200, PT_X + 3200, PT_Y + 400]);
    $near = RouteSnap::nearestAlong($route, [$line], $grid, 150, 150.0);
    t_count(count($route), $near[0] ?? [], 'wszystkie punkty 100 px obok odcinka — dopasowane');

    $far = array_map(static fn(array $p): array => [$p[0], PT_Y + 400], $route);
    $near = RouteSnap::nearestAlong($far, [$line], $grid, 150, 150.0);
    t_same([], $near, '400 px obok — poza progiem');
});

t_test('runs: krótka przerwa nie dzieli kawałka, dłuższa dzieli', function () {
    $near = [0 => 0, 1 => 1, 2 => 2, 5 => 5, 6 => 6, 20 => 20, 21 => 21];
    t_same([[0, 6, 0, 6], [20, 21, 20, 21]], RouteSnap::runs($near, 3), 'przerwa 2 pkt scalona, 13 pkt dzieli');
    t_same([[0, 2, 0, 2], [5, 6, 5, 6], [20, 21, 20, 21]], RouteSnap::runs($near, 1), 'przy progu 1 dzieli się wszystko');
});

t_test('przyciąganie: trasa biegnąca wzdłuż linii przejmuje jej geometrię', function () {
    $line = pt_flat_h(PT_X, PT_X + 4000, PT_Y + 40);
    $r = pt_prefer([[PT_X, PT_Y], [PT_X + 4000, PT_Y]], [$line], [0], null);
    t_count(1, $r['runs'], 'jeden kawałek wzdłuż linii');
    t_same(0, $r['runs'][0]['kFrom'], 'od początku trasy');
    t_same(count($r['dense']) - 1, $r['runs'][0]['kTo'], 'do końca trasy');
    t_false($r['runs'][0]['join'], 'to przyciąganie, nie dołączenie');
});

t_test('przyciąganie: ślad z pętlą (dużo dłuższy od zastępowanej drogi) odpada', function () {
    // Linia wzdłuż trasy, ale na środku robi pętlę 6000 px (ktoś kręcił kółka).
    $line = array_merge(
        pt_flat_h(PT_X, PT_X + 2000, PT_Y + 40),
        [PT_X + 2000, PT_Y + 3040, PT_X + 2020, PT_Y + 3040, PT_X + 2020, PT_Y + 40],
        pt_flat_h(PT_X + 2040, PT_X + 4000, PT_Y + 40)
    );
    $r = pt_prefer([[PT_X, PT_Y], [PT_X + 4000, PT_Y]], [$line], [0], null);
    foreach ($r['runs'] as $run) {
        t_true(!($run['kFrom'] === 0 && $run['kTo'] === count($r['dense']) - 1), 'żaden kawałek nie przechodzi przez pętlę w całości');
    }
    t_true(count($r['runs']) >= 1, 'proste części nadal są przyciągnięte');
});

t_test('ślad tam-i-z-powrotem tą samą drogą: jeden ciągły kawałek, bez przeskakiwania między nitkami', function () {
    // Tam 20 px nad trasą, z powrotem 20 px pod — obie nitki równie blisko.
    $tam = pt_flat_h(PT_X, PT_X + 4000, PT_Y - 20);
    $powrot = [];
    for ($x = PT_X + 4000; $x >= PT_X; $x -= 20) {
        $powrot[] = $x;
        $powrot[] = PT_Y + 20;
    }
    $line = array_merge($tam, $powrot);
    $r = pt_prefer([[PT_X, PT_Y], [PT_X + 4000, PT_Y]], [$line], [0], null);
    t_count(1, $r['runs'], 'jeden kawałek');
    t_true($r['runs'][0]['kTo'] - $r['runs'][0]['kFrom'] >= count($r['dense']) - 3, 'prawie cała trasa');
});

t_test('dołączanie: objazd po linii między dwoma wspólnymi kawałkami — tylko z przełącznikiem', function () {
    // Linia pokrywa się z trasą na [0..1500] i [3500..5000], a między nimi robi
    // łuk 800 px w bok (droga OSM 2000 px, łuk ~2600 px — 1,3×).
    $line = array_merge(
        pt_flat_h(PT_X, PT_X + 1500, PT_Y),
        [PT_X + 1800, PT_Y + 800, PT_X + 2500, PT_Y + 800, PT_X + 3200, PT_Y + 800],
        pt_flat_h(PT_X + 3500, PT_X + 5000, PT_Y)
    );
    $route = [[PT_X, PT_Y], [PT_X + 5000, PT_Y]];

    $bez = pt_prefer($route, [$line], [0], null);
    t_count(2, $bez['runs'], 'bez przełącznika: dwa osobne kawałki, środek po OSM');
    t_false($bez['runs'][0]['join'] || $bez['runs'][1]['join'], 'nic nie jest dołączone');

    $z = pt_prefer($route, [$line], [0], 1.8);
    t_count(1, $z['runs'], 'z przełącznikiem: jeden kawałek przez łuk');
    t_true($z['runs'][0]['join'], 'oznaczony jako dołączenie');

    $a = RouteSnap::assemble($z['dense'], $z['orig'], $z['runs'], [$line]);
    $ys = array_map(static fn(array $c): float => TileGrid::toPixel($c[0], $c[1])[1], $a['coords']);
    t_true(max($ys) >= PT_Y + 790, 'złożona trasa naprawdę idzie łukiem linii');

    $zaDlugi = pt_prefer($route, [$line], [0], 1.1);
    t_count(2, $zaDlugi['runs'], 'łuk 1,3× nie mieści się w limicie 1,1×');
});

t_test('dołączanie: krótkie kawałki na końcach z szumem kierunku (1 wierzchołek wstecz) nadal się łączą', function () {
    // Regresja z prawdziwej trasy (wislana-trasa, 2026-09-18): OSM dotyka linii
    // tylko 80 m przy starcie i 90 m przy końcu, a kawałek startowy ma indeks
    // linii 10 → 9 (szum). Ścisły test kierunku rozbijał łańcuch — 0% zamiast 98%.
    $flat = [];
    for ($i = 0; $i <= 100; $i++) {
        $flat[] = PT_X + $i * 50;
        $flat[] = PT_Y;
    }
    $cum = [];
    for ($k = 0; $k < 100; $k++) {
        $cum[] = $k * 50.0;
    }
    $near = [0 => [0 => 10, 1 => 10, 2 => 9, 3 => 9, 50 => 60, 51 => 61, 52 => 61, 53 => 62]];
    $o = ['minRunPx' => 500.0, 'snapRatio' => 1.2, 'joinRatio' => 1.8, 'joinSlackPx' => 100.0, 'jumpSlackPx' => 300.0, 'gapPts' => 3];
    $runs = RouteSnap::preferredRuns($cum, [0], [$flat], $near, $o);
    t_count(1, $runs, 'jeden łańcuch');
    t_true($runs[0]['join'], 'dołączony');
    t_same([0, 53, 10, 62], [$runs[0]['kFrom'], $runs[0]['kTo'], $runs[0]['lFrom'], $runs[0]['lTo']], 'od startu do końcowego kawałka, wzdłuż linii');
});

t_test('pierwszeństwo: źródło wyżej na liście wygrywa część wspólną, niższe przycina się do reszty', function () {
    $wysoko = pt_flat_h(PT_X + 1500, PT_X + 2500, PT_Y + 30);  // ranga 0, krótki środek
    $nisko = pt_flat_h(PT_X, PT_X + 4000, PT_Y - 30);           // ranga 1, cała trasa
    $r = pt_prefer([[PT_X, PT_Y], [PT_X + 4000, PT_Y]], [$wysoko, $nisko], [0, 1], null);
    $linie = array_column($r['runs'], 'line');
    t_same([1, 0, 1], $linie, 'niższe źródło przed i za środkiem, wyższe w środku');
    for ($i = 1; $i < count($r['runs']); $i++) {
        t_true($r['runs'][$i]['kFrom'] > $r['runs'][$i - 1]['kTo'], 'kawałki się nie nakładają');
    }
});

t_test('assemble: brzegi trasy zachowane, kawałki wskazują na wklejoną geometrię', function () {
    $line = pt_flat_h(PT_X + 1000, PT_X + 3000, PT_Y + 40);
    $route = [[PT_X, PT_Y], [PT_X + 4000, PT_Y]];
    $r = pt_prefer($route, [$line], [0], null);
    $a = RouteSnap::assemble($r['dense'], $r['orig'], $r['runs'], [$line]);
    $first = TileGrid::toPixel($a['coords'][0][0], $a['coords'][0][1]);
    $last = TileGrid::toPixel($a['coords'][count($a['coords']) - 1][0], $a['coords'][count($a['coords']) - 1][1]);
    t_true(abs($first[0] - PT_X) <= 1 && abs($first[1] - PT_Y) <= 1, 'zaczyna się w starcie trasy');
    t_true(abs($last[0] - (PT_X + 4000)) <= 1 && abs($last[1] - PT_Y) <= 1, 'kończy w celu trasy');
    t_count(1, $a['pieces'], 'jeden kawałek Ridemore');
    $p = $a['pieces'][0];
    $mid = TileGrid::toPixel($a['coords'][intdiv($p['from'] + $p['to'], 2)][0], $a['coords'][intdiv($p['from'] + $p['to'], 2)][1]);
    t_true(abs($mid[1] - (PT_Y + 40)) <= 2, 'środek kawałka leży na linii Ridemore, nie na drodze OSM');
});

t_test('orderedProjection: pętla (START = CEL) jedzie całą bazą, nie stoi w miejscu', function () {
    $loop = [PT_X, PT_Y, PT_X + 1000, PT_Y, PT_X + 1000, PT_Y + 1000, PT_X, PT_Y + 1000, PT_X, PT_Y];
    $p = RouteSnap::orderedProjection($loop, [[PT_X, PT_Y], [PT_X, PT_Y]], 100.0);
    t_same([0, 4], $p['indices'], 'START na pierwszym wierzchołku, CEL na ostatnim');
});

t_test('orderedProjection: tam-i-z-powrotem — punkt pośredni przypięty do PIERWSZEGO przejazdu', function () {
    // Tam (x rośnie) i z powrotem 20 px obok (x maleje).
    $outBack = array_merge(pt_flat_h(PT_X, PT_X + 2000, PT_Y, 500), [PT_X + 2000, PT_Y + 20, PT_X + 1500, PT_Y + 20, PT_X + 1000, PT_Y + 20, PT_X + 500, PT_Y + 20, PT_X, PT_Y + 20]);
    $p = RouteSnap::orderedProjection($outBack, [[PT_X, PT_Y], [PT_X + 1000, PT_Y + 15], [PT_X, PT_Y + 20]], 100.0);
    t_same(2, $p['indices'][1], 'punkt przy 1000 px przypięty do nitki „tam" (indeks 2), nie „z powrotem" (7)');
    t_same(9, $p['indices'][2], 'CEL na końcu powrotu');
});

t_test('orderedProjection: jazda pod prąd bazy odwraca bazę', function () {
    $line = pt_flat_h(PT_X, PT_X + 2000, PT_Y, 500); // 5 wierzchołków
    $p = RouteSnap::orderedProjection($line, [[PT_X + 2000, PT_Y], [PT_X, PT_Y]], 50.0);
    t_same([0, 4], $p['indices'], 'indeksy rosnące po odwróceniu');
    t_same(PT_X + 2000, $p['flat'][0], 'odwrócona baza zaczyna się tam, gdzie START');
});

// --- Tryb bazy w calculate() — bez sieci, bo punkty leżą NA bazie -------

t_test('calculate(): tryb bazy prowadzi po bazie, cały odcinek to „baza"', function () {
    $base = [];
    for ($i = 0; $i <= 50; $i++) {
        $base[] = [50.0 + $i * 0.0005, 20.0 + $i * 0.0003];
    }
    $result = PlannerController::calculate([
        'waypoints' => [['lat' => $base[0][0], 'lng' => $base[0][1]], ['lat' => $base[50][0], 'lng' => $base[50][1]]],
        'base'      => ['points' => $base, 'label' => 'TEST baza'],
        'sources'   => ['known' => true],
    ]);
    t_true($result['success'], 'policzone bez OSRM (brak dojazdów)');
    $seg = $result['segments'][0];
    t_count(1, $seg['ridemore'], 'jeden kawałek po bazie');
    t_eq('base', $seg['ridemore'][0]['source'], 'źródło: baza');
    t_eq('TEST baza', $seg['ridemore'][0]['label'], 'z nazwą bazy');
    $len = RouteSnap::lineLengthM($base);
    t_true(abs($seg['ridemoreM'] - $len) < 5, 'ridemoreM = długość bazy (' . $seg['ridemoreM'] . ' vs ' . round($len) . ')');
});

t_test('calculate(): baza z 1 punktem albo bez punktów jest ignorowana (nie wybucha)', function () {
    $result = PlannerController::calculate([
        'waypoints' => [['lat' => 50.0, 'lng' => 20.0]],
        'base'      => ['points' => [[50.0, 20.0]], 'label' => 'x'],
    ]);
    t_false($result['success'], 'nadal walidacja punktów trasy');
});

// --- Modele: źródła linii dla planera ----------------------------------

t_test('GpxGeometry::hitsInTiles: ślad znajduje się po swoim kaflu, obcy kafel go nie zwraca', function () {
    $row = Core\Database::connection()->query('SELECT gpx_hash, tx, ty FROM gpx_tiles LIMIT 1')->fetch();
    if (!$row) {
        t_true(true, 'brak śladów w bazie dev — nic do sprawdzenia');
        return;
    }
    $hits = GpxGeometry::hitsInTiles([(((int) $row['tx']) << 32) | (int) $row['ty'] => true]);
    t_true(isset($hits[$row['gpx_hash']]) && $hits[$row['gpx_hash']] >= 1, 'hash trafiony w swoim kaflu');
    t_same([], GpxGeometry::hitsInTiles([(1 << 32) | 1 => true]), 'kafel na drugim końcu świata — pusto');
});

t_test('KnownRoute::activeGeometryHashes: tylko aktywne trasy z plikiem, z nazwą', function () {
    $hashes = KnownRoute::activeGeometryHashes();
    $active = (int) Core\Database::connection()->query('SELECT COUNT(*) FROM known_routes WHERE is_active = 1 AND gpx_url IS NOT NULL')->fetchColumn();
    t_true(count($hashes) <= $active, 'nie więcej niż aktywnych tras z plikiem');
    foreach ($hashes as $hash => $name) {
        t_true(GpxGeometry::has($hash), 'geometria istnieje dla ' . $name);
        t_true($name !== '', 'ma nazwę');
    }
});

// --- RoutingProxy: walidacja brzegowa, BEZ sieci -----------------------

t_test('RoutingProxy::route odrzuca mniej niż 2 punkty bez wywołania sieci', function () {
    t_null(RoutingProxy::route([['lat' => 50.0, 'lng' => 20.0]]), 'jeden punkt to za mało');
});

t_test('RoutingProxy::route odrzuca ponad 25 punktów bez wywołania sieci', function () {
    $wp = [];
    for ($i = 0; $i < 26; $i++) {
        $wp[] = ['lat' => 50.0 + $i * 0.001, 'lng' => 20.0];
    }
    t_null(RoutingProxy::route($wp), '26 punktów przekracza limit');
});

t_test('RoutingProxy::nextSlot trzyma odstęp między zapytaniami (limit rowerowego OSRM)', function () {
    t_same(100.0, RoutingProxy::nextSlot(90.0, 100.0, 1.0), 'dawno po poprzednim — od razu');
    t_same(101.0, RoutingProxy::nextSlot(100.0, 100.2, 1.0), 'zaraz po poprzednim — sekundę po nim');
    t_same(103.0, RoutingProxy::nextSlot(102.0, 100.5, 1.0), 'kolejka zarezerwowana do przodu — za ostatnim terminem');
});

t_test('Planer liczy na ROWEROWYM serwerze OSRM (demo router.project-osrm.org ignoruje profil i liczy dla aut)', function () {
    $url = (string) (APP_CONFIG['planner']['osrm_base_url'] ?? '');
    t_true(!str_contains($url, 'router.project-osrm.org'), 'serwer demo liczy wyłącznie trasy samochodowe');
    t_true((int) (APP_CONFIG['planner']['osrm_min_interval_ms'] ?? 0) >= 1000 || getenv('OSRM_MIN_INTERVAL_MS') !== false,
        'publiczny rowerowy OSRM pozwala na jedno zapytanie na sekundę');
});

// --- PlannerController::calculate — ścieżka walidacyjna, bez sieci -----

t_test('calculate() zwraca czytelny błąd przy mniej niż 2 waypointach', function () {
    $result = PlannerController::calculate(['waypoints' => [['lat' => 50.0, 'lng' => 20.0]]]);
    t_false($result['success'], 'success=false');
    t_not_null($result['error'], 'jest komunikat błędu');
});

// --- Models\PlannedRoute: zapis, odczyt, własność (IDOR) ---------------

t_test('PlannedRoute::save + findForUser: round-trip zwraca to, co zapisano', function () {
    $owner = t_user(0);
    $input = [
        'name'           => 'TEST trasa plannera',
        'waypoints_json' => json_encode([['lat' => 50.0, 'lng' => 20.0, 'type' => 'start', 'label' => '']]),
        'geometry_json'  => json_encode(['points' => [[50.0, 20.0], [50.01, 20.01]]]),
        'distance_km'    => 12.34,
        'ascent_m'       => 100,
        'descent_m'      => 90,
        'duration_min'   => 45,
        'engine'         => 'osrm-public',
        'profile'        => 'cycling',
    ];
    $id = PlannedRoute::save($owner, $input);
    t_true($id > 0, 'insert zwraca id');

    $row = PlannedRoute::findForUser($id, $owner);
    t_not_null($row, 'właściciel znajduje swoją trasę');
    t_eq('TEST trasa plannera', $row['name'], 'nazwa');
    t_eq('12.34', $row['distance_km'], 'dystans');
    t_eq(100, $row['ascent_m'], 'wzniesienie');
});

t_test('PlannedRoute::findForUser: IDOR — obcy user nie widzi cudzej trasy', function () {
    $owner = t_user(0);
    $stranger = t_user(1);
    $id = PlannedRoute::save($owner, [
        'name' => 'TEST prywatna', 'waypoints_json' => '[]', 'geometry_json' => '{"points":[]}',
        'distance_km' => 1.0, 'ascent_m' => null, 'descent_m' => null, 'duration_min' => null,
    ]);
    t_null(PlannedRoute::findForUser($id, $stranger), 'obcy dostaje null, nie cudze dane');
});

t_test('PlannedRoute::update: obcy user nie może nadpisać cudzej trasy', function () {
    $owner = t_user(0);
    $stranger = t_user(1);
    $id = PlannedRoute::save($owner, [
        'name' => 'TEST oryginał', 'waypoints_json' => '[]', 'geometry_json' => '{"points":[]}',
        'distance_km' => 1.0, 'ascent_m' => null, 'descent_m' => null, 'duration_min' => null,
    ]);

    $ok = PlannedRoute::update($id, $stranger, [
        'name' => 'TEST przejęta', 'waypoints_json' => '[]', 'geometry_json' => '{"points":[]}',
        'distance_km' => 2.0, 'ascent_m' => null, 'descent_m' => null, 'duration_min' => null,
    ]);
    t_false($ok, 'update() zwraca false — zero wierszy zmienionych');

    $row = PlannedRoute::findForUser($id, $owner);
    t_eq('TEST oryginał', $row['name'], 'nazwa u prawowitego właściciela nietknięta');
});

t_test('PlannedRoute::update: właściciel może nadpisać swoją trasę', function () {
    $owner = t_user(0);
    $id = PlannedRoute::save($owner, [
        'name' => 'TEST v1', 'waypoints_json' => '[]', 'geometry_json' => '{"points":[]}',
        'distance_km' => 1.0, 'ascent_m' => null, 'descent_m' => null, 'duration_min' => null,
    ]);
    $ok = PlannedRoute::update($id, $owner, [
        'name' => 'TEST v2', 'waypoints_json' => '[]', 'geometry_json' => '{"points":[]}',
        'distance_km' => 3.0, 'ascent_m' => null, 'descent_m' => null, 'duration_min' => null,
    ]);
    t_true($ok, 'update() zwraca true dla właściciela');
    t_eq('TEST v2', PlannedRoute::findForUser($id, $owner)['name'], 'nazwa zaktualizowana');
});
