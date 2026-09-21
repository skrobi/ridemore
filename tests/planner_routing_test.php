<?php
// tests/planner_routing_test.php
// WARSTWA ROUTINGU RIDEMORE (Utils\RidemoreRouting) — scenariusze z planu
// zaakceptowanego 2026-09-18, na danych SYNTETYCZNYCH i z PODSTAWIONYM OSRM.
// Dane dev to praktycznie jeden jeździec, więc „popularność wśród wielu osób"
// da się sprawdzić tylko tak; na żywo (md/features.md) sprawdzana jest
// mechanika z prawdziwym rowerowym OSRM.
//
// Układ: START w (0, 0), CEL w (20 000, 0) — metry na wschód i północ od
// punktu 50°N 20°E. Trasa bazowa „OSRM" to prosta 20 km. Fałszywy OSRM liczy
// odległość w linii prostej (świat bez przeszkód), chyba że test mówi inaczej.
use Utils\RidemoreRouting;
use Utils\RoutingPreferences;
use Utils\RoadAttributeDetector;
use Utils\RouteSnap;
use Utils\TileGrid;
use Controllers\PlannerController;

const RR_M_PER_DEG_LAT = 111320.0;

/** Punkt (x m na wschód, y m na północ) → lat/lng. */
function rr_pt(float $x, float $y): array
{
    return [
        'lat' => 50.0 + $y / RR_M_PER_DEG_LAT,
        'lng' => 20.0 + $x / (RR_M_PER_DEG_LAT * cos(deg2rad(50.0))),
    ];
}

/**
 * Linia źródła jak z Models\RidemoreCorridors::lines(withOwners: true):
 * łamana przez podane punkty (metry), zagęszczona co 20 m jak ślad GPS.
 *
 * @param list<array{0:float,1:float}> $xy
 * @param array<string,int> $owners właściciel => liczba przejazdów
 */
function rr_line(array $xy, string $source, array $owners = [], ?string $label = null, array $extra = []): array
{
    $flat = [];
    for ($i = 0; $i < count($xy); $i++) {
        [$x0, $y0] = $xy[$i];
        if ($i === 0) {
            $p = rr_pt($x0, $y0);
            [$px, $py] = TileGrid::toPixel($p['lat'], $p['lng']);
            array_push($flat, $px, $py);
            continue;
        }
        [$xa, $ya] = $xy[$i - 1];
        $len = hypot($x0 - $xa, $y0 - $ya);
        $steps = max(1, (int) ceil($len / 20));
        for ($s = 1; $s <= $steps; $s++) {
            $p = rr_pt($xa + ($x0 - $xa) * $s / $steps, $ya + ($y0 - $ya) * $s / $steps);
            [$px, $py] = TileGrid::toPixel($p['lat'], $p['lng']);
            array_push($flat, $px, $py);
        }
    }
    $xs = [];
    $ys = [];
    for ($i = 0; $i < count($flat); $i += 2) {
        $xs[] = $flat[$i];
        $ys[] = $flat[$i + 1];
    }
    $rank = ['mine' => 0, 'known' => 1, 'community' => 2][$source];
    return [
        'rank' => $rank, 'source' => $source, 'label' => $label, 'hash' => hash('sha256', json_encode([$xy, $source, $owners, $label])),
        'known' => $source === 'known', 'flat' => $flat,
        'minPx' => min($xs), 'minPy' => min($ys), 'maxPx' => max($xs), 'maxPy' => max($ys),
        'owners' => array_map(static fn(int $n): array => ['n' => $n, 'last' => $extra['last'] ?? '2026-09-01'], $owners),
    ] + ($source === 'known' ? ['surface' => $extra['surface'] ?? ['asphalt' => null, 'gravel' => null, 'trail' => null]] : [])
      + (isset($extra['attributes']) && is_array($extra['attributes']) ? ['attributes' => $extra['attributes']] : []);
}

/** Trasa bazowa „OSRM": łamana przez punkty (metry). */
function rr_baseline(array $xy): array
{
    $coords = array_map(static fn(array $p): array => array_values(rr_pt($p[0], $p[1])), $xy);
    $len = RouteSnap::lineLengthM($coords);
    return ['coords' => $coords, 'distanceM' => $len, 'durationS' => $len / 5];
}

/**
 * Wywołanie warstwy z fałszywym OSRM. $block(a, b) → mnożnik odległości dla
 * pary punktów (np. „tędy rowerem się nie przejedzie").
 *
 * @return array{result:array,calls:array{table:int,legs:int}}
 */
function rr_route(array $fromXY, array $toXY, array $lines, array $opts = []): array
{
    $calls = ['table' => 0, 'legs' => 0];
    $block = $opts['block'] ?? null;
    $dist = static function (array $a, array $b) use ($block): float {
        $d = RouteSnap::haversineM($a['lat'], $a['lng'], $b['lat'], $b['lng']);
        return $block ? $d * $block($a, $b) : $d;
    };
    $table = static function (array $points, array $src, array $dst) use (&$calls, $dist): ?array {
        $calls['table']++;
        $D = [];
        foreach ($src as $r => $i) {
            foreach ($dst as $c => $j) {
                $D[$r][$c] = $dist($points[$i], $points[$j]);
            }
        }
        return ['distances' => $D, 'durations' => $D];
    };
    if (isset($opts['table'])) {
        $table = $opts['table'];
    }
    $legs = static function (array $wps) use (&$calls, $dist): ?array {
        $calls['legs']++;
        $out = [];
        for ($i = 0; $i + 1 < count($wps); $i++) {
            $d = $dist($wps[$i], $wps[$i + 1]);
            $out[] = ['coords' => [[$wps[$i]['lat'], $wps[$i]['lng']], [$wps[$i + 1]['lat'], $wps[$i + 1]['lng']]], 'distanceM' => $d, 'durationS' => $d / 5];
        }
        return ['legs' => $out];
    };
    $sources = $opts['sources'] ?? [
        'mine' => (bool) array_filter($lines, static fn($l) => $l['source'] === 'mine'),
        'known' => (bool) array_filter($lines, static fn($l) => $l['source'] === 'known'),
        'community' => (bool) array_filter($lines, static fn($l) => $l['source'] === 'community'),
    ];
    $baseline = rr_baseline($opts['baseline'] ?? [$fromXY, $toXY]);
    $profileName = is_string($opts['profile'] ?? null) ? $opts['profile'] : 'gravel';
    $profiles = [
        'road' => ['code' => 'szosowy', 'legalRatio' => 1.3, 'minAsphaltPct' => 70, 'trailBonus' => 0.0, 'countsRidesOf' => []],
        'gravel' => ['code' => 'gravel', 'legalRatio' => 1.5, 'minAsphaltPct' => null, 'trailBonus' => 0.0, 'countsRidesOf' => []],
        'mtb' => ['code' => 'mtb', 'legalRatio' => 1.8, 'minAsphaltPct' => null, 'trailBonus' => 0.0, 'countsRidesOf' => []],
    ];
    $profile = is_array($opts['profile'] ?? null) ? $opts['profile'] : $profiles[$profileName];
    $routingPreferences = $opts['routingPreferences'] ?? (array_key_exists('profile', $opts)
        ? RoutingPreferences::resolve($profile['code'], $opts['character'] ?? 'balanced', $opts['preferences'] ?? [])
        : [
            'character' => 'balanced',
            'surface' => array_fill_keys(RoutingPreferences::SURFACES, 0),
            'roadClass' => array_fill_keys(RoutingPreferences::ROAD_CLASSES, 0),
            'popularityStrength' => 1.0,
        ]);
    $result = RidemoreRouting::route(
        rr_pt(...$fromXY), rr_pt(...$toXY), $baseline, $lines,
        $opts['ref'] ?? ['riders' => 0.0, 'passes' => 0.0], $sources, $opts['self'] ?? null,
        $table, $legs, 25000 / 3600,
        [
            'profile' => $profile,
            'routingPreferences' => $routingPreferences,
            'today' => $opts['today'] ?? '2026-09-19',
            'preferredKeys' => $opts['preferredKeys'] ?? [],
        ]
    );
    return ['result' => $result, 'calls' => $calls];
}

// --- Parametry: limit wydłużenia, bonus, RidemoreScore -------------------

t_test('Płynny budżet objazdu: minimum +4 km, potem 12%, najwyżej +8 km', function () {
    t_true(abs(RidemoreRouting::maxLengthM(8000) - 12000) < 0.01, 'krótka 8 km → 12 km');
    t_true(abs(RidemoreRouting::maxLengthM(20000) - 24000) < 0.01, 'średnia 20 km → 24 km');
    t_true(abs(RidemoreRouting::maxLengthM(60000) - 67200) < 0.01, 'długa 60 km → 67,2 km');
    t_true(abs(RidemoreRouting::maxLengthM(100000) - 108000) < 0.01, 'bardzo długa: nie więcej niż +8 km');
});

t_test('Bonus rośnie z długością korytarza, krótkie kawałki ważą ułamek (ciągłość)', function () {
    t_true(abs(RidemoreRouting::bonusM(3000, 1.0) - 600) < 0.01, '3 km o score 1,0 → 600 m');
    t_true(abs(RidemoreRouting::bonusM(300, 1.0) - 6) < 0.01, '300 m → tylko 6 m (10% wagi)');
    t_true(RidemoreRouting::bonusM(18000, 0.4) > 5 * RidemoreRouting::bonusM(2000, 1.0), 'długi słabszy (1440 m) > 5× krótki mocny (267 m)');
});

t_test('Przypadek 18: skala lokalna — 3 osoby w pustej okolicy ważą tyle, co 30 w gęstej', function () {
    $sources = ['mine' => false, 'known' => false, 'community' => true];
    $quiet = RidemoreRouting::score(['riders' => 3, 'passes' => 5, 'known' => false, 'mine' => 0], ['riders' => 3, 'passes' => 5], $sources);
    $busy = RidemoreRouting::score(['riders' => 30, 'passes' => 50, 'known' => false, 'mine' => 0], ['riders' => 30, 'passes' => 50], $sources);
    t_true(abs($quiet - $busy) < 1e-9, 'ten sam wynik: ' . $quiet . ' vs ' . $busy);
    $threeInCity = RidemoreRouting::score(['riders' => 3, 'passes' => 5, 'known' => false, 'mine' => 0], ['riders' => 30, 'passes' => 50], $sources);
    t_true($threeInCity < $quiet, '3 osoby w mieście, gdzie typowo jeździ 30, to mało');
});

t_test('Przypadek 17 (score): jedna osoba to nie „sprawdzony odcinek", wiele osób po razie — tak', function () {
    $sources = ['mine' => false, 'known' => false, 'community' => true];
    $ref = ['riders' => 5, 'passes' => 10];
    t_same(0.0, RidemoreRouting::score(['riders' => 1, 'passes' => 3, 'known' => false, 'mine' => 0], $ref, $sources), 'jedna osoba, nawet 40 razy (limit 3)');
    t_true(RidemoreRouting::score(['riders' => 12, 'passes' => 12, 'known' => false, 'mine' => 0], $ref, $sources) >= 0.9, '12 osób po razie');
});

t_test('Znana trasa i mój przejazd podnoszą score do swojej podłogi — tylko gdy źródło zaznaczone', function () {
    $s = ['riders' => 0, 'passes' => 0, 'known' => true, 'mine' => 2];
    $ref = ['riders' => 0, 'passes' => 0];
    t_same(0.8, RidemoreRouting::score($s, $ref, ['mine' => false, 'known' => true, 'community' => false]), 'znana trasa: 0,8');
    t_true(abs(RidemoreRouting::score($s, $ref, ['mine' => true, 'known' => false, 'community' => false]) - 0.7) < 1e-9, 'dwa moje przejazdy: 0,7');
    t_same(0.0, RidemoreRouting::score($s, $ref, ['mine' => false, 'known' => false, 'community' => true]), 'odznaczone źródła nic nie dają');
});

t_test('Korytarze: krótka dziura we wsparciu nie tnie, długa tnie; rzadsze sondy = większa tolerancja', function () {
    $cum = [0, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 1100, 1200];
    $scores = [0.8, 0.8, 0.8, 0.0, 0.8, 0.8, 0.0, 0.0, 0.0, 0.8, 0.8, 0.8, 0.8];
    t_same([[0, 5], [9, 12]], RidemoreRouting::runs($scores, $cum, 0.0), 'jedna słaba sonda (200 m) łączy, trzy (400 m) tną');
    t_same([[0, 12]], RidemoreRouting::runs($scores, $cum, 0.0, 200.0), 'sondy co 200 m: dziura 400 m to jeszcze nie przerwa');
    t_same([], RidemoreRouting::runs($scores, $cum, 1000.0), 'żaden kawałek nie ma 1 km');
});

// --- Scenariusze z planu (trasa bazowa: prosta 20 km) ---------------------

t_test('Przypadek 1: najkrótsza trasa jest też popularna — zostaje trasa bazowa', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[2000, 0], [17000, 0]], 'known', [], 'Szlak')]);
    t_null($r['result']['segment'], 'trasa bazowa już jedzie korytarzem');
    t_same('osrm', $r['result']['variant']['chosen'], 'wybór');
});

t_test('Ciągłość: poprzednio wybrany korytarz nie znika przez lokalny objazd', function () {
    $line = rr_line([[2500, 3000], [17500, 3000]], 'known', [], 'Szlak');
    $withoutState = rr_route([0, 0], [20000, 0], [$line]);
    t_null($withoutState['result']['segment'], 'sam lokalny objazd nie wygrywa z bazą');

    $withState = rr_route([0, 0], [20000, 0], [$line], ['preferredKeys' => [$line['hash']]]);
    t_same('ridemore', $withState['result']['variant']['chosen'], 'utrzymuje ten sam korytarz');
    t_true($withState['result']['variant']['extraM'] > 2000, 'akceptuje kilka kilometrów dodatkowej drogi');
    t_same([$line['hash']], $withState['result']['continuityKeys'], 'przekazuje stan do kolejnego odcinka');
});

t_test('Przypadek 2: popularna trasa +5% — wygrywa korytarz', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[2500, 1658], [17500, 1658]], 'known', [], 'Szlak')]);
    $v = $r['result']['variant'];
    t_same('ridemore', $v['chosen'], 'wybór');
    t_true($v['extraM'] > 800 && $v['extraM'] < 1200, 'wydłużenie ok. 1 km, jest ' . $v['extraM']);
    t_same('known', $v['corridors'][0]['source'], 'źródło korytarza');
    t_true($r['result']['segment']['ridemoreM'] > 14000, 'ponad 14 km po korytarzu');
    t_same(1, $r['calls']['table'], 'jedna macierz OSRM');
    t_same(1, $r['calls']['legs'], 'jedno zapytanie o geometrię');
});

t_test('Przypadek 3: popularna trasa ponad 4 km dłuższa — poza budżetem, zostaje trasa bazowa', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[1000, 2828], [19000, 2828]], 'known')]);
    t_same('osrm', $r['result']['variant']['chosen'], 'wybór');
});

t_test('Przypadek 4: popularna trasa daleko poza budżetem — poza elipsą, żadnego zapytania do OSRM', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[1000, 8000], [19000, 8000]], 'known')]);
    t_same('osrm', $r['result']['variant']['chosen'], 'wybór');
    t_same(0, $r['calls']['table'] + $r['calls']['legs'], 'bez zapytań');
});

t_test('Przypadek 5: dwa konkurencyjne korytarze — wygrywa ten o niższym koszcie całej trasy', function () {
    $r = rr_route([0, 0], [20000, 0], [
        rr_line([[5000, 2040], [15000, 2040]], 'known', [], 'Długi'),     // 20,8 km, 10 km korytarza
        rr_line([[7500, -1744], [12500, -1744]], 'known', [], 'Krótki'),  // 20,4 km, 5 km korytarza
    ]);
    $v = $r['result']['variant'];
    t_same('ridemore', $v['chosen'], 'wybór');
    t_same('Długi', $v['corridors'][0]['label'], 'koszt 19,2 km < 19,6 km');
});

t_test('Przypadek 6: bardzo popularny, ale krótki odcinek — nie uzasadnia objazdu', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[9850, 1500], [10150, 1500]], 'known')]);
    t_same('osrm', $r['result']['variant']['chosen'], 'wybór');
    t_same(0, $r['result']['variant']['considered'], '300 m to nie korytarz');
});

t_test('Przypadek 7: długi, mniej popularny korytarz wygrywa z krótkim bardzo popularnym', function () {
    $long = [[1000, 1249], [19000, 1249]];
    $r = rr_route([0, 0], [20000, 0], [
        rr_line($long, 'community', ['u1' => 1]),
        rr_line($long, 'community', ['u2' => 1]),
        rr_line($long, 'community', ['u3' => 1]),
        rr_line([[9000, -2343], [11000, -2343]], 'known', [], 'Krótki'),
    ], ['ref' => ['riders' => 20, 'passes' => 40]]);
    $v = $r['result']['variant'];
    t_same('ridemore', $v['chosen'], 'wybór');
    t_same('community', $v['corridors'][0]['source'], 'długi korytarz społeczności');
    t_same(3, $v['corridors'][0]['riders'], 'trzy osoby');
    t_true($v['corridors'][0]['lengthM'] > 10000, 'długi odcinek, jest ' . $v['corridors'][0]['lengthM']);
});

t_test('Przypadek 8: brak danych Ridemore — trasa bazowa, zero dodatkowych zapytań', function () {
    $r = rr_route([0, 0], [20000, 0], []);
    t_null($r['result']['segment'], 'segment');
    t_same(0, $r['calls']['table'] + $r['calls']['legs'], 'bez zapytań');
});

t_test('Przypadek 9: start 40 m od korytarza — jedzie korytarzem prawie od razu', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[0, 40], [10000, 1200], [20000, 40]], 'known')]);
    $v = $r['result']['variant'];
    t_same('ridemore', $v['chosen'], 'wybór');
    t_true($v['extraM'] < 600, 'wydłużenie niewielkie, jest ' . $v['extraM']);
    t_true($r['result']['segment']['ridemoreM'] > 18000, 'prawie cała trasa po korytarzu');
});

t_test('Przypadek 10: start daleko od korytarza (4 km w bok) — trasa bazowa', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[0, 4000], [20000, 4000]], 'known')]);
    t_same('osrm', $r['result']['variant']['chosen'], 'wybór');
});

t_test('Przypadek 14: skarb poza korytarzem — oba odcinki jadą korytarzem i zjeżdżają do skarbu', function () {
    $lines = [rr_line([[0, 800], [20000, 800]], 'known', [], 'Równoległa')];
    $toTreasure = rr_route([0, 0], [10000, 2000], $lines);
    $fromTreasure = rr_route([10000, 2000], [20000, 0], $lines);
    t_same('ridemore', $toTreasure['result']['variant']['chosen'], 'START → skarb po korytarzu');
    t_same('ridemore', $fromTreasure['result']['variant']['chosen'], 'skarb → CEL po korytarzu');
    t_true($toTreasure['result']['segment']['ridemoreM'] > 6000, 'dojazd do skarbu głównie korytarzem');
});

t_test('Przypadek 15: korytarz w złą stronę (w poprzek trasy) — odrzucony', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[10000, -3000], [10000, 3000]], 'known')]);
    t_same('osrm', $r['result']['variant']['chosen'], 'wybór');
    t_same(0, $r['result']['variant']['considered'], 'nie zbliża do celu');
});

t_test('Przypadek 16: korytarz, którym OSM nie pozwala przejechać — odrzucony', function () {
    // Fałszywy OSRM: między dwoma punktami korytarza (y≈1658) droga jest 2× dłuższa.
    $block = static fn(array $a, array $b): float => (abs($a['lat'] - rr_pt(0, 1658)['lat']) < 0.001
        && abs($b['lat'] - rr_pt(0, 1658)['lat']) < 0.001) ? 2.0 : 1.0;
    $r = rr_route([0, 0], [20000, 0], [rr_line([[2500, 1658], [17500, 1658]], 'known')], ['block' => $block]);
    t_same('osrm', $r['result']['variant']['chosen'], 'wybór');
    t_true($r['result']['variant']['considered'] > 0, 'kandydat był, odpadł na przejezdności');
});

t_test('Przypadek 17: jedna osoba 40 razy — brak korytarza; 12 osób po razie — korytarz', function () {
    $xy = [[2500, 1658], [17500, 1658]];
    $one = rr_route([0, 0], [20000, 0], [rr_line($xy, 'community', ['u1' => 40])], ['ref' => ['riders' => 5, 'passes' => 10]]);
    t_same('osrm', $one['result']['variant']['chosen'], 'jedna osoba');
    $owners = [];
    for ($i = 1; $i <= 12; $i++) {
        $owners['u' . $i] = 1;
    }
    $many = rr_route([0, 0], [20000, 0], [rr_line($xy, 'community', $owners)], ['ref' => ['riders' => 5, 'passes' => 10]]);
    t_same('ridemore', $many['result']['variant']['chosen'], '12 osób');
    t_same(12, $many['result']['variant']['corridors'][0]['riders'], 'liczba osób w informacji o wariancie');
});

t_test('Moje przejazdy: własna droga +5% wygrywa tylko wtedy, gdy źródło jest zaznaczone', function () {
    $line = rr_line([[2500, 1658], [17500, 1658]], 'mine', ['u7' => 2]);
    $on = rr_route([0, 0], [20000, 0], [$line], ['self' => 'u7']);
    t_same('ridemore', $on['result']['variant']['chosen'], 'moje przejazdy zaznaczone');
    $off = rr_route([0, 0], [20000, 0], [$line], ['self' => 'u7', 'sources' => ['mine' => false, 'known' => false, 'community' => true]]);
    t_same('osrm', $off['result']['variant']['chosen'], 'tylko społeczność: mój pojedynczy przejazd to nie „sprawdzony odcinek"');
});

t_test('Dwa korytarze po kolei (wariant D): START → A → B → CEL', function () {
    $r = rr_route([0, 0], [20000, 0], [
        rr_line([[1000, 700], [8500, 700]], 'known', [], 'A'),
        rr_line([[11500, -700], [19000, -700]], 'known', [], 'B'),
    ]);
    $v = $r['result']['variant'];
    t_same('ridemore', $v['chosen'], 'wybór');
    t_same(['A', 'B'], array_column($v['corridors'], 'label'), 'oba korytarze, w kolejności jazdy');
});

t_test('Brak odpowiedzi OSRM (macierz) — trasa bazowa i znacznik, żeby wyniku nie zapamiętać', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[2500, 1658], [17500, 1658]], 'known')], [
        'table' => static fn(): ?array => null,
    ]);
    t_null($r['result']['segment'], 'segment');
    t_true(!empty($r['result']['degraded']), 'degraded');
});

t_test('Geometria zwycięzcy: dojazd OSRM + korytarz + zjazd, kawałek Ridemore wskazuje korytarz', function () {
    $r = rr_route([0, 0], [20000, 0], [rr_line([[2500, 1658], [17500, 1658]], 'known', [], 'Szlak')]);
    $seg = $r['result']['segment'];
    $first = $seg['coords'][0];
    $last = $seg['coords'][count($seg['coords']) - 1];
    t_true(RouteSnap::haversineM($first[0], $first[1], ...array_values(rr_pt(0, 0))) < 1, 'zaczyna się w START');
    t_true(RouteSnap::haversineM($last[0], $last[1], ...array_values(rr_pt(20000, 0))) < 1, 'kończy się w CELU');
    t_count(1, $seg['ridemore'], 'jeden kawałek Ridemore');
    $piece = $seg['ridemore'][0];
    t_same('Szlak', $piece['label'], 'etykieta');
    $mid = $seg['coords'][intdiv($piece['from'] + $piece['to'], 2)];
    t_true(abs(($mid[0] - 50.0) * RR_M_PER_DEG_LAT - 1658) < 40, 'środek kawałka leży na korytarzu');
});

// --- Etap 2a: świeżość przejazdów ----------------------------------------

t_test('Świeżość: do 2 lat pełna waga, potem liniowo do 0,5 przy 6 latach; brak daty to nie kara', function () {
    t_same(1.0, RidemoreRouting::recencyFactor(null), 'brak daty');
    t_same(1.0, RidemoreRouting::recencyFactor(730), '2 lata');
    t_true(abs(RidemoreRouting::recencyFactor(1460) - 0.75) < 1e-9, '4 lata → 0,75');
    t_same(0.5, RidemoreRouting::recencyFactor(4000), 'ponad 6 lat → 0,5');
});

t_test('Świeżość: ten sam korytarz społeczności wygrywa, gdy jeżdżony niedawno, a nie sprzed 8 lat', function () {
    $xy = [[2500, 1658], [17500, 1658]];
    $ref = ['ref' => ['riders' => 5, 'passes' => 10]];
    $fresh = rr_route([0, 0], [20000, 0], [
        rr_line($xy, 'community', ['u1' => 1]), rr_line($xy, 'community', ['u2' => 1]), rr_line($xy, 'community', ['u3' => 1]),
    ], $ref);
    t_same('ridemore', $fresh['result']['variant']['chosen'], 'świeży: 3 osoby w tym roku');
    $stale = rr_route([0, 0], [20000, 0], [
        rr_line($xy, 'community', ['u1' => 1], null, ['last' => '2018-05-01']),
        rr_line($xy, 'community', ['u2' => 1], null, ['last' => '2018-05-01']),
        rr_line($xy, 'community', ['u3' => 1], null, ['last' => '2018-05-01']),
    ], $ref);
    t_same('osrm', $stale['result']['variant']['chosen'], 'te same 3 osoby, ostatnio w 2018 — za mało, żeby nadłożyć 1 km');
});

t_test('Świeżość nie dotyczy znanych tras (kuratorowane, nie „ostatnio jeżdżone")', function () {
    $sources = ['mine' => false, 'known' => true, 'community' => false];
    t_same(0.8, RidemoreRouting::score(['riders' => 0, 'passes' => 0, 'known' => true, 'mine' => 0, 'ageDays' => 5000], ['riders' => 0, 'passes' => 0], $sources), 'znana trasa 0,8 mimo daty');
});

// --- Etap 2b: profile Szosa / Gravel / MTB (przypadki 11–13) ---------------

/** Dwa korytarze znanych tras: asfaltowy 5 km (+2%) i terenowy 10 km (+4%). */
function rr_two_surfaces(): array
{
    return [
        rr_line([[7500, 1744], [12500, 1744]], 'known', [], 'Asfalt', ['surface' => ['asphalt' => 100, 'gravel' => 0, 'trail' => 0]]),
        rr_line([[5000, -2040], [15000, -2040]], 'known', [], 'Teren', ['surface' => ['asphalt' => 0, 'gravel' => 20, 'trail' => 80]]),
    ];
}

t_test('Przypadek 11: szosa — znana trasa bez asfaltu odpada, wygrywa asfaltowa', function () {
    $r = rr_route([0, 0], [20000, 0], rr_two_surfaces(), ['profile' => 'road']);
    t_same('ridemore', $r['result']['variant']['chosen'], 'wybór');
    t_same('Asfalt', $r['result']['variant']['corridors'][0]['label'], 'korytarz asfaltowy');
});

t_test('Przypadek 11b: szosa — jedyny korytarz jest szutrowy → zostaje OSRM', function () {
    $r = rr_route([0, 0], [20000, 0], [
        rr_line([[2500, 1658], [17500, 1658]], 'known', [], 'Szuter', ['surface' => ['asphalt' => 20, 'gravel' => 80, 'trail' => 0]]),
    ], ['profile' => 'road']);
    t_same('osrm', $r['result']['variant']['chosen'], 'popularność nie nadpisuje profilu');
});

t_test('Przypadek 12: gravel — szuter i teren dopuszczone, wygrywa dłuższy korytarz terenowy', function () {
    $r = rr_route([0, 0], [20000, 0], rr_two_surfaces(), ['profile' => 'gravel']);
    t_same('Teren', $r['result']['variant']['corridors'][0]['label'], 'koszt 19,2 km < 19,6 km');
});

t_test('Przypadek 13: MTB — teren wygrywa przez preferencję drogi, nie przez zmianę popularności', function () {
    $r = rr_route([0, 0], [20000, 0], rr_two_surfaces(), ['profile' => 'mtb']);
    $c = $r['result']['variant']['corridors'][0];
    t_same('Teren', $c['label'], 'wybór');
    t_true(abs($c['score'] - 0.8) < 1e-9, 'RidemoreScore pozostaje niezależne: 0,8');
    t_true($c['roadPreferenceM'] < 0, 'teren obniża koszt profilu MTB');
});

t_test('Profil a przejezdność: OSRM potrzebuje 1,6× korytarza — szosa odrzuca, MTB przyjmuje', function () {
    $block = static fn(array $a, array $b): float => (abs($a['lat'] - rr_pt(0, 1658)['lat']) < 0.001
        && abs($b['lat'] - rr_pt(0, 1658)['lat']) < 0.001) ? 1.6 : 1.0;
    $lines = [rr_line([[2500, 1658], [17500, 1658]], 'known')];
    $road = rr_route([0, 0], [20000, 0], $lines, ['block' => $block, 'profile' => 'road']);
    t_same('osrm', $road['result']['variant']['chosen'], 'szosa: próg 1,3');
    $mtb = rr_route([0, 0], [20000, 0], $lines, ['block' => $block, 'profile' => 'mtb']);
    t_same('ridemore', $mtb['result']['variant']['chosen'], 'MTB: próg 1,8');
});

// --- Preferencje nawierzchni i klasy drogi (zaakceptowany etap 4) ----------

function rr_preference_corridors(): array
{
    return [
        rr_line([[7500, 1200], [12500, 1200]], 'known', [], 'Krótki asfalt', [
            'surface' => ['asphalt' => 100, 'gravel' => 0, 'trail' => 0],
            'attributes' => ['surface' => ['asphalt' => 100], 'roadClass' => ['residential' => 100]],
        ]),
        rr_line([[5000, -1800], [15000, -1800]], 'known', [], 'Dłuższy gravel', [
            'surface' => ['asphalt' => 0, 'gravel' => 100, 'trail' => 0],
            'attributes' => ['surface' => ['gravel' => 100], 'roadClass' => ['track' => 100]],
        ]),
    ];
}

t_test('Preferencje 1: Gravel standard — dłuższy gravel może wygrać z krótkim asfaltem', function () {
    $r = rr_route([0, 0], [20000, 0], rr_preference_corridors(), ['profile' => 'gravel']);
    t_same('ridemore', $r['result']['variant']['chosen'], 'wybrano wariant Ridemore');
    t_same('Dłuższy gravel', $r['result']['variant']['corridors'][0]['label'], 'preferowana nawierzchnia');
    t_true($r['result']['variant']['reason']['roadPreferenceM'] < 0, 'preferencja obniżyła koszt');
});

t_test('Preferencje 2: Gravel terenowy — track i gravel wygrywają z asfaltem', function () {
    $r = rr_route([0, 0], [20000, 0], rr_preference_corridors(), ['profile' => 'gravel', 'character' => 'offroad']);
    t_same('Dłuższy gravel', $r['result']['variant']['corridors'][0]['label'], 'terenowy preset wybiera dukt');
});

t_test('Preferencje 3: MTB — track/ground wygrywa z lokalnym asfaltem', function () {
    $r = rr_route([0, 0], [20000, 0], rr_preference_corridors(), ['profile' => 'mtb']);
    t_same('Dłuższy gravel', $r['result']['variant']['corridors'][0]['label'], 'MTB wybiera wariant terenowy');
});

t_test('Preferencje 4: MTB bez alternatywy terenowej może zostać na asfalcie OSRM', function () {
    $r = rr_route([0, 0], [20000, 0], [], ['profile' => 'mtb']);
    t_same('osrm', $r['result']['variant']['chosen'], 'asfalt bazowy nie jest zakazany');
});

t_test('Preferencje 5–7: popularność nie miesza się z profilem — terenowy wariant zachowuje własny score', function () {
    $asphalt = rr_line([[7500, 1000], [12500, 1000]], 'community', ['u1' => 3, 'u2' => 3, 'u3' => 3, 'u4' => 3], 'Popularny asfalt', [
        'attributes' => ['surface' => ['asphalt' => 100], 'roadClass' => ['residential' => 100]],
    ]);
    $track = rr_line([[5000, -1500], [15000, -1500]], 'community', ['u5' => 1, 'u6' => 1], 'Mniej popularny track', [
        'attributes' => ['surface' => ['ground' => 100], 'roadClass' => ['track' => 100]],
    ]);
    $ref = ['riders' => 8, 'passes' => 16];
    $standard = rr_route([0, 0], [20000, 0], [$asphalt, $track], ['profile' => 'gravel', 'ref' => $ref]);
    $offroad = rr_route([0, 0], [20000, 0], [$asphalt, $track], ['profile' => 'gravel', 'character' => 'offroad', 'ref' => $ref]);
    $mtb = rr_route([0, 0], [20000, 0], [$asphalt, $track], ['profile' => 'mtb', 'ref' => $ref]);
    t_not_null($standard['result']['variant']['chosen'], 'Gravel standard zwraca wyjaśniony wybór');
    t_same('Mniej popularny track', $offroad['result']['variant']['corridors'][0]['label'], 'Gravel terenowy: preferencja może pokonać popularniejszy asfalt');
    t_same('Mniej popularny track', $mtb['result']['variant']['corridors'][0]['label'], 'MTB: track może pokonać popularniejszy asfalt');
    t_true($mtb['result']['variant']['corridors'][0]['score'] < 1.0, 'score tracku nie został sztucznie podniesiony przez profil');
});

t_test('Preferencje 12: brak surface/highway jest neutralny, nie karany', function () {
    $prefs = RoutingPreferences::resolve('mtb', 'offroad');
    $cost = RoutingPreferences::costAdjustment($prefs, [], 10000);
    t_same(0.0, $cost['adjustmentM'], 'brak metadanych = zero korekty');
    t_same(0.0, $cost['coverage'], 'jawne zerowe pokrycie');

    $other = RoutingPreferences::resolve('e-bike', 'balanced');
    $otherCost = RoutingPreferences::costAdjustment($other, [
        'surface' => ['gravel' => 100], 'roadClass' => ['track' => 100],
    ], 10000);
    t_same(0.0, $otherCost['adjustmentM'], 'inne istniejące profile zachowują neutralny preset zamiast udawać Gravel');
    t_same(-2, RoutingPreferences::resolve('gravel')['roadClass']['motorway'], 'droga główna ma mocną miękką karę');

    $partial = RoutingPreferences::costAdjustment($prefs, [
        'surface' => ['ground' => 1], 'roadClass' => ['track' => 1], 'coverage' => 0.01,
    ], 10000);
    t_true(abs($partial['adjustmentM'] + 16.0) < 0.01, '1% pokrycia daje tylko 1% bonusu, nie bonus całej trasy');
    t_true(abs($partial['coverage'] - 0.01) < 1e-9, 'zachowane rzeczywiste pokrycie źródła');
    t_same(0.0, RoutingPreferences::compatibilityPenalty(70, [
        'surface' => ['unknown' => 100], 'coverage' => 1.0,
    ], 10000), 'unknown nie udaje 0% asfaltu');
    $mixedPenalty = RoutingPreferences::compatibilityPenalty(70, [
        'surface' => ['gravel' => 1, 'unknown' => 99], 'coverage' => 1.0,
    ], 10000);
    t_true(abs($mixedPenalty - 45.0) < 0.01, '1% znanej nieasfaltowej nawierzchni daje tylko 1% kary');
});

t_test('Atrybuty wycinka: lokalny asfalt nie dziedziczy gravelu z reszty długiego śladu', function () {
    $attributes = [
        'segments' => [
            ['from' => 0.0, 'to' => 0.2, 'surface' => 'asphalt', 'roadClass' => 'residential'],
            ['from' => 0.2, 'to' => 1.0, 'surface' => 'gravel', 'roadClass' => 'track'],
        ],
    ];
    $start = RoutingPreferences::sliceAttributes($attributes, 0.0, 0.1);
    $end = RoutingPreferences::sliceAttributes($attributes, 0.5, 0.8);
    t_true(($start['surface']['asphalt'] ?? 0) > 99, 'początek jest lokalnie asfaltem');
    t_true(($end['surface']['gravel'] ?? 0) > 99, 'dalszy wycinek jest lokalnie gravelem');
    t_same(1.0, $start['coverage'], 'pełne pokrycie wycinka');
});

t_test('Bazowy OSRM dostaje koszt drogi tam, gdzie pokrywa wzbogacony korytarz', function () {
    $baseline = rr_line([[0, 0], [20000, 0]], 'known', [], 'Asfalt bazowy', [
        'attributes' => [
            'segments' => [[
                'from' => 0.0, 'to' => 1.0, 'surface' => 'asphalt', 'roadClass' => 'residential',
            ]],
        ],
    ]);
    $r = rr_route([0, 0], [20000, 0], [$baseline], ['profile' => 'mtb']);
    t_same('osrm', $r['result']['variant']['chosen'], 'ta sama geometria nie jest bez sensu zastępowana');
    t_true($r['result']['variant']['reason']['roadPreferenceM'] > 0, 'asfalt bazowy nie jest już zawsze neutralny');
    t_true($r['result']['variant']['reason']['attributeCoverage'] > 0.9, 'atrybuty pokrywają bazową geometrię');
});

t_test('Atrybuty OSM: przestrzenne dopasowanie zachowuje dokładne tagi, nie wymyśla brakujących', function () {
    $points = [[50.0, 20.0], [50.0, 20.01]];
    $ways = [[
        'type' => 'way',
        'tags' => ['highway' => 'track', 'surface' => 'compacted'],
        'geometry' => [['lat' => 50.0, 'lon' => 20.0], ['lat' => 50.0, 'lon' => 20.01]],
    ]];
    $attributes = RoadAttributeDetector::match($points, $ways);
    t_not_null($attributes, 'dopasowano drogę');
    t_true(($attributes['surface']['compacted'] ?? 0) > 99, 'dokładna nawierzchnia');
    t_true(($attributes['roadClass']['track'] ?? 0) > 99, 'dokładna klasa highway');
    t_same(1, count($attributes['segments']), 'cache zachowuje lokalny odcinek, nie tylko histogram całości');

    $unknown = RoadAttributeDetector::match($points, [[
        'type' => 'way', 'tags' => [],
        'geometry' => [['lat' => 50.0, 'lon' => 20.0], ['lat' => 50.0, 'lon' => 20.01]],
    ]]);
    t_true(($unknown['surface']['unknown'] ?? 0) > 99, 'brak tagu pozostaje unknown');
    t_true(($unknown['roadClass']['unknown'] ?? 0) > 99, 'brak highway pozostaje unknown');
});

t_test('Atrybuty OSM: kierunek odrzuca przecinającą drogę i wybiera równoległą', function () {
    $points = [[50.0, 20.0], [50.0, 20.01]];
    $attributes = RoadAttributeDetector::match($points, [
        [
            'id' => 1, 'tags' => ['highway' => 'track', 'surface' => 'ground'],
            'geometry' => [['lat' => 49.999, 'lon' => 20.005], ['lat' => 50.001, 'lon' => 20.005]],
        ],
        [
            'id' => 2, 'tags' => ['highway' => 'residential', 'surface' => 'asphalt'],
            'geometry' => [['lat' => 50.00009, 'lon' => 20.0], ['lat' => 50.00009, 'lon' => 20.01]],
        ],
    ]);
    t_true(($attributes['surface']['asphalt'] ?? 0) > 99, 'wybrano równoległy asfalt, nie skrzyżowanie');
    t_true(($attributes['roadClass']['residential'] ?? 0) > 99, 'klasa pochodzi z równoległej drogi');
});

t_test('Długi ślad jest dzielony na bezpieczne bbox-y zamiast odrzucany w całości', function () {
    $method = new ReflectionMethod(RoadAttributeDetector::class, 'analysisChunks');
    $method->setAccessible(true);
    $chunks = $method->invoke(null, [[50.0, 20.0], [50.0, 20.6], [50.0, 21.2], [50.0, 21.8]]);
    t_true(count($chunks) >= 3, 'trasa o rozpiętości >1° ma kilka kawałków');
    foreach ($chunks as $chunk) {
        $lons = array_column($chunk, 1);
        t_true(max($lons) - min($lons) <= 0.75 + 1e-9, 'każdy bbox mieści się w limicie');
    }
});

t_test('Wczytana trasa zachowuje snapshot, ale nie id edytowalnej konfiguracji', function () {
    $method = new ReflectionMethod(PlannerController::class, 'storedRouting');
    $method->setAccessible(true);
    $snapshot = RoutingPreferences::resolve('gravel', 'offroad');
    $routing = $method->invoke(null, [
        'routing_config_id' => 7,
        'routing_preferences_json' => json_encode($snapshot, JSON_PRESERVE_ZERO_FRACTION),
        'profile' => 'gravel',
    ]);
    t_null($routing['configId'], 'historyczny zapis nie wskazuje żywej konfiguracji');
    t_same('offroad', $routing['character'], 'charakter snapshotu zachowany');
    t_same($snapshot, $routing['preferences'], 'preferencje snapshotu zachowane 1:1');
});
