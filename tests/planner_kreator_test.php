<?php
// tests/planner_kreator_test.php
// KREATOR PLANERA „gdzie warto pojechać” (Etap A, tasks/active/planer-
// uproszczona-architektura.md): walidacja PlannerController::generate() na
// ścieżkach, które NIE wychodzą w sieć, mapa stylów jazdy na źródła i warstwę
// oraz karta wyniku PlannerController::summary() — czysta funkcja, sprawdzana
// na odcinkach syntetycznych. Samo generowanie (OSRM + API wysokości) jest
// sprawdzane na żywo — opis w md/features.md (Route Planner, kreator).
use Controllers\PlannerController;
use Core\Lang;

/** Odcinek jak z sourceSegments(): prosta na wschód od 50°N 20°E, $km długości. */
function pk_segment(float $km, array $ridemore = [], array $corridors = []): array
{
    $n = 101;
    $coords = [];
    $lngPerM = 1 / (111320.0 * cos(deg2rad(50.0)));
    for ($i = 0; $i < $n; $i++) {
        $coords[] = [50.0, 20.0 + $km * 1000 * $i / ($n - 1) * $lngPerM];
    }
    $ridemoreM = 0.0;
    foreach ($ridemore as $piece) {
        $ridemoreM += \Utils\RouteSnap::lineLengthM(array_slice($coords, $piece['from'], $piece['to'] - $piece['from'] + 1));
    }
    return [
        'coords'    => $coords,
        'distanceM' => $km * 1000,
        'durationS' => $km * 1000 / 6,
        'ridemoreM' => (int) round($ridemoreM),
        'ridemore'  => $ridemore,
        'variant'   => $corridors ? ['chosen' => 'ridemore', 'corridors' => $corridors] : ['chosen' => 'osrm', 'corridors' => []],
    ];
}

/** Kawałek znanej trasy na indeksach [from, to] (co 1% długości odcinka). */
function pk_known(string $name, int $from, int $to, ?int $asphalt = null): array
{
    return ['source' => 'known', 'label' => $name, 'from' => $from, 'to' => $to,
        'surface' => ['asphalt' => $asphalt, 'gravel' => null, 'trail' => null]];
}

const PK_ROAD = ['minAsphaltPct' => 70];
const PK_GRAVEL = ['minAsphaltPct' => null];

// --- generate(): walidacja bez sieci ----------------------------------------

t_test('generate(): brak startu albo celu — czytelny błąd, bez liczenia trasy', function () {
    $noStart = PlannerController::generate(['end' => ['lat' => 50.1, 'lng' => 20.1]]);
    t_false($noStart['success'], 'bez startu: success=false');
    t_same('Wskaż, skąd chcesz jechać.', $noStart['error'], 'bez startu: komunikat');

    $noEnd = PlannerController::generate(['start' => ['lat' => 50.0, 'lng' => 20.0]]);
    t_false($noEnd['success'], 'bez celu: success=false');
    t_same('Wskaż, dokąd chcesz jechać.', $noEnd['error'], 'bez celu: komunikat');

    $junk = PlannerController::generate(['start' => ['lat' => 'x', 'lng' => 20.0], 'end' => 'Kraków']);
    t_false($junk['success'], 'śmieci zamiast współrzędnych: success=false');
});

t_test('generate(): start i cel w tym samym miejscu — błąd, zanim cokolwiek pójdzie do OSRM', function () {
    $r = PlannerController::generate(['start' => ['lat' => 50.0, 'lng' => 20.0], 'end' => ['lat' => 50.0005, 'lng' => 20.0005]]);
    t_false($r['success'], 'success=false');
    t_same('Start i cel leżą za blisko siebie.', $r['error'], 'komunikat');
});

t_test('Style jazdy: Szybko = czysty OSRM, Sprawdzone = znane trasy + społeczność z warstwą, bez „Moich przejazdów”', function () {
    $fast = PlannerController::STYLES['fast'];
    t_same(['mine' => false, 'known' => false, 'community' => false], $fast['sources'], 'Szybko: żadnych źródeł');
    t_false($fast['autoJoin'], 'Szybko: warstwa WYŁ.');

    $proven = PlannerController::STYLES['proven'];
    t_same(['mine' => false, 'known' => true, 'community' => true], $proven['sources'], 'Sprawdzone: znane trasy + społeczność');
    t_true($proven['autoJoin'], 'Sprawdzone: warstwa Ridemore WŁ.');
    t_same('proven', PlannerController::DEFAULT_STYLE, 'domyślny styl');
    t_false(isset(PlannerController::STYLES['discover']), '„Odkrywczo” dopiero w Etapie D — nie obiecujemy stylu, którego nie liczymy');
});

// --- summary(): karta wyniku ------------------------------------------------

t_test('summary(): udział odcinków Ridemore i znane trasy po drodze', function () {
    $s = PlannerController::summary([pk_segment(20.0, [pk_known('Wiślana Trasa', 0, 50)])], ['ascentM' => 120, 'descentM' => 110], PK_GRAVEL);
    t_same(20.0, $s['distanceKm'], 'dystans');
    t_same(50, $s['ridemorePct'], 'połowa trasy po znanej trasie');
    t_same(120, $s['ascentM'], 'przewyższenie z API wysokości');
    t_same(110, $s['descentM'], 'zjazdy');
    t_same([['name' => 'Wiślana Trasa', 'km' => 10.0]], $s['knownRoutes'], 'znana trasa z długością');
    t_same('50% trasy po sprawdzonych odcinkach Ridemore.', $s['highlights'][0], 'pierwsze zdanie: udział');
    t_same('Po drodze: Wiślana Trasa (10 km).', $s['highlights'][1], 'drugie zdanie: znane trasy');
    t_same([], $s['warnings'], 'bez ostrzeżeń');
});

t_test('summary(): kawałek znanej trasy krótszy niż 500 m nie trafia do „Po drodze”', function () {
    $s = PlannerController::summary([pk_segment(20.0, [pk_known('Mostek', 10, 11)])], null, PK_GRAVEL);
    t_same([], $s['knownRoutes'], 'brak znanych tras w karcie');
    t_count(1, $s['highlights'], 'tylko zdanie o udziale');
});

t_test('summary(): styl Szybko mówi wprost, że to najkrótsza droga, nie „sprawdzona”', function () {
    $s = PlannerController::summary([pk_segment(12.0)], null, PK_ROAD, 'fast');
    t_same('Najkrótsza droga rowerowa — bez preferowania odcinków Ridemore.', $s['highlights'][0], 'zdanie stylu');
    t_same(0, $s['ridemorePct'], 'udział 0');
});

t_test('summary(): Sprawdzone bez danych Ridemore w okolicy — uczciwe zdanie zamiast „0%”', function () {
    $s = PlannerController::summary([pk_segment(12.0)], null, PK_ROAD, 'proven');
    t_same('W okolicy nie ma jeszcze sprawdzonych odcinków Ridemore — trasa jedzie zwykłymi drogami rowerowymi.', $s['highlights'][0], 'zdanie');
    t_null($s['ascentM'], 'API wysokości nie odpowiedziało → null, nie 0');
});

t_test('summary(): liczba osób z korytarza społeczności — od 2 osób, z odmianą, nigdy kto', function () {
    $community = static fn(int $riders): array => ['source' => 'community', 'label' => null, 'lengthM' => 5000, 'riders' => $riders, 'passes' => $riders * 2, 'score' => 0.7];

    $many = PlannerController::summary([pk_segment(15.0, [], [$community(5)])], null, PK_GRAVEL);
    t_same(5, $many['riders'], '5 osób');
    t_true(in_array('Tym korytarzem jechało co najmniej 5 osób z Ridemore.', $many['highlights'], true), 'forma „osób”');

    $few = PlannerController::summary([pk_segment(15.0, [], [$community(3)])], null, PK_GRAVEL);
    t_true(in_array('Tym korytarzem jechały co najmniej 3 osoby z Ridemore.', $few['highlights'], true), 'forma „osoby”');

    $one = PlannerController::summary([pk_segment(15.0, [], [$community(1)])], null, PK_GRAVEL);
    t_null($one['riders'], 'jedna osoba to nie „sprawdzony odcinek” — liczby nie pokazujemy');
    foreach ($one['highlights'] as $line) {
        t_false(str_contains($line, 'korytarzem'), 'bez zdania o osobach');
    }

    $known = PlannerController::summary([pk_segment(15.0, [], [['source' => 'known', 'label' => 'Szlak', 'lengthM' => 5000, 'riders' => 0, 'passes' => 0, 'score' => 0.8]])], null, PK_GRAVEL);
    t_null($known['riders'], 'korytarz znanej trasy nie ma „osób”');
});

t_test('summary(): ostrzeżenie o wspinaczce od 15 m na km (i tylko na trasie ≥ 5 km)', function () {
    $steep = PlannerController::summary([pk_segment(40.0)], ['ascentM' => 900, 'descentM' => 900], PK_GRAVEL);
    t_same(['Dużo wspinaczki: 900 m w górę na 40 km.'], $steep['warnings'], '22 m/km → ostrzeżenie');

    $flat = PlannerController::summary([pk_segment(40.0)], ['ascentM' => 300, 'descentM' => 300], PK_GRAVEL);
    t_same([], $flat['warnings'], '7,5 m/km → bez ostrzeżenia');

    $short = PlannerController::summary([pk_segment(3.0)], ['ascentM' => 120, 'descentM' => 0], PK_GRAVEL);
    t_same([], $short['warnings'], 'krótka trasa z jednym podjazdem → bez ostrzeżenia');

    $unknown = PlannerController::summary([pk_segment(40.0)], null, PK_GRAVEL);
    t_same([], $unknown['warnings'], 'brak danych wysokości → milczymy');
});

t_test('summary(): nawierzchnia — ostrzeżenie tylko dla znanej trasy z asfaltem poniżej progu typu roweru', function () {
    $road = PlannerController::summary([pk_segment(20.0, [pk_known('Szuter Doliny', 0, 60, 40)])], null, PK_ROAD);
    t_same(['12 km po trasie „Szuter Doliny”, która ma tylko 40% asfaltu.'], $road['warnings'], 'szosa: 40% < 70% → ostrzeżenie');

    $gravel = PlannerController::summary([pk_segment(20.0, [pk_known('Szuter Doliny', 0, 60, 40)])], null, PK_GRAVEL);
    t_same([], $gravel['warnings'], 'gravel: brak progu asfaltu → bez ostrzeżenia');

    $asphalt = PlannerController::summary([pk_segment(20.0, [pk_known('Asfaltowa', 0, 60, 95)])], null, PK_ROAD);
    t_same([], $asphalt['warnings'], 'szosa na 95% asfaltu → bez ostrzeżenia');

    $unknown = PlannerController::summary([pk_segment(20.0, [pk_known('Nieznana', 0, 60)])], null, PK_ROAD);
    t_same([], $unknown['warnings'], 'nawierzchnia nieznana (NULL) → milczymy, brak danych ≠ „OK” ani „źle”');
});

t_test('summary(): kilka odcinków sumuje się w jedną kartę', function () {
    $s = PlannerController::summary([
        pk_segment(10.0, [pk_known('Szlak', 0, 100)]),
        pk_segment(10.0),
    ], null, PK_GRAVEL);
    t_same(20.0, $s['distanceKm'], 'dystans z obu odcinków');
    t_same(50, $s['ridemorePct'], 'udział liczony od całej trasy');
});

t_test('summary(): po angielsku zdania wychodzą ze słownika', function () {
    $s = Lang::with('en', static fn(): array => PlannerController::summary(
        [pk_segment(40.0, [pk_known('Szuter', 0, 60, 40)])], ['ascentM' => 900, 'descentM' => 900], PK_ROAD
    ));
    t_same('60% of the route on proven Ridemore sections.', $s['highlights'][0], 'udział po angielsku');
    t_same('Lots of climbing: 900 m up over 40 km.', $s['warnings'][0], 'wspinaczka po angielsku');
});
