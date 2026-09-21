<?php
// tests/dodawanie_tras_test.php
// DODAWANIE ZNANYCH TRAS (Etap 8) — warianty z RÓŻNĄ ILOŚCIĄ INFORMACJI.
//
// Jedyna droga dodania trasy to KnownRoute::createFromGpx(): wymagane są nazwa
// i plik GPX, wszystko inne (opis, region, zdjęcie) jest opcjonalne. Ten zestaw
// sprawdza, że każdy wariant — od samego minimum po komplet — zapisuje DOKŁADNIE
// to, co dostał, i ani pola, ani sługa nie powstają „z kapelusza".
//
// Walidacja formularza (pusta nazwa, brak pliku) siedzi w kontrolerze
// (KnownRouteController::create) i tu jej nie testujemy — to jest poziom
// modelu: co model przyjmie i co z tym zrobi.
//
// Konwencja jak w znane_trasy_test.php: ślady kładziemy w pustkowiu (54,8 N /
// 17,9 E), a helpery mają prefiks dr_, żeby nie zderzyć się z kr_* z innego
// zestawu (run.php wgrywa wszystkie zestawy do jednego procesu).
use Models\KnownRoute;
use Utils\Gpx;

/** Plik GPX z listy punktów — do usunięcia przez test po przebiegu. */
function dr_gpx_file(array $points): string
{
    $trkpts = '';
    foreach ($points as [$lat, $lon]) {
        $trkpts .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', $lat, $lon);
    }
    $path = sys_get_temp_dir() . '/dr_test_' . bin2hex(random_bytes(8)) . '.gpx';
    file_put_contents(
        $path,
        '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>' . $trkpts . '</trkseg></trk></gpx>'
    );
    return $path;
}

/** Prosta trasa ze wschodu na zachód, `$n` punktów co ~200 m. */
function dr_line(int $n = 40, float $lat = 54.80, float $lon = 17.90): array
{
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $points[] = [$lat, $lon + $i * 0.003];
    }
    return $points;
}

/**
 * Punkt z realnym pokryciem w `region_cells` (migr. 070/074) — od tej migracji
 * region trasy jest WYPROWADZANY z GPX, nie przekazywany, więc test regionu
 * musi ułożyć ślad na prawdziwym terenie, a nie w pustkowiu 54,8 N / 17,9 E
 * używanym przez resztę tego zestawu (tam celowo nie ma pokrycia).
 *
 * @return array{lat:float, lon:float, regionItemId:int}
 */
function dr_region_point(): array
{
    $row = Core\Database::connection()->query('
        SELECT cell_id, region_item_id FROM region_cells LIMIT 1
    ')->fetch();
    if (!$row) {
        t_fail('region_cells jest puste — uruchom backfill_regions.php przed tym testem');
    }
    [$lat, $lon] = Utils\DiscoveryGrid::cellCenter((int) $row['cell_id']);
    return ['lat' => $lat, 'lon' => $lon, 'regionItemId' => (int) $row['region_item_id']];
}

/** Wiersz trasy z bazy po id — wygodny skrót do asercji. */
function dr_find(int $id): array
{
    $row = KnownRoute::find($id);
    if ($row === null) {
        t_fail('trasa o id ' . $id . ' nie istnieje w bazie');
    }
    return $row;
}

// --- Warianty z różną ilością informacji -----------------------------

t_test('minimalnie: sama nazwa i plik GPX', function () {
    $path = dr_gpx_file(dr_line(40));
    $created = KnownRoute::createFromGpx('TEST minimum', null, $path, '/assets/uploads/gpx/min.gpx');
    $row = dr_find($created['id']);
    unlink($path);

    t_true($created['cells'] > 0, 'trasa ma pola');
    t_true((float) $created['distanceKm'] > 0, 'dystans policzony z pliku');
    t_eq('TEST minimum', $row['name'], 'nazwa zapisana');
    t_null($row['description'], 'brak opisu = NULL');
    // Region NIE jest już parametrem tego testu (patrz osobne testy regionu
    // niżej, na prawdziwym terenie z dr_region_point()) — punkt "pustkowia"
    // bywa mimo wszystko przybrzeżny w realnych danych backfillu, więc żadne
    // konkretne oczekiwanie tutaj nie byłoby stabilne.
    t_null($row['cover_photo_url'], 'brak zdjęcia = NULL');
    t_eq('/assets/uploads/gpx/min.gpx', $row['gpx_url'], 'url pliku zapisany');
    t_eq(1, (int) $row['is_active'], 'nowa trasa jest domyślnie aktywna');
    t_eq(1, (int) $row['bonus_enabled'], 'punktowanie domyślnie włączone');
    t_eq($created['cells'], (int) $row['cells_total'], 'cells_total z wyniku = kolumna');
    t_eq($created['cells'], (int) Core\Database::connection()
        ->query('SELECT COUNT(*) FROM known_route_cells WHERE route_id = ' . $created['id'])
        ->fetchColumn(), 'cells_total = liczba wierszy pól');
});

t_test('komplet: nazwa, opis, region wyliczony z GPX i zdjęcie', function () {
    // Ślad na PRAWDZIWYM terenie (dr_region_point), nie w pustkowiu — inaczej
    // nie byłoby czego wyliczyć w known_route_regions.
    $p = dr_region_point();
    $path = dr_gpx_file(dr_line(40, $p['lat'], $p['lon']));
    $created = KnownRoute::createFromGpx(
        'TEST komplet',
        'Opis szlaku — dłuższy tekst.',
        $path,
        '/assets/uploads/gpx/pelny.gpx',
        '/assets/uploads/covers/pelny.jpg'
    );
    $row = dr_find($created['id']);
    unlink($path);

    t_eq('TEST komplet', $row['name'], 'nazwa');
    t_eq('Opis szlaku — dłuższy tekst.', $row['description'], 'opis');
    t_not_null($row['region_label'], 'region wyliczony automatycznie z GPX');
    $stmt = Core\Database::connection()->prepare('SELECT region_item_id FROM known_route_regions WHERE route_id = :id');
    $stmt->execute(['id' => $created['id']]);
    $regionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    t_true(in_array($p['regionItemId'], $regionIds, true), 'region punktu jest wśród regionów trasy');
    t_eq('/assets/uploads/covers/pelny.jpg', $row['cover_photo_url'], 'zdjęcie');
    t_eq('/assets/uploads/gpx/pelny.gpx', $row['gpx_url'], 'plik');
});

t_test('nazwa + opis, bez regionu i zdjęcia', function () {
    $path = dr_gpx_file(dr_line(20));
    $created = KnownRoute::createFromGpx('TEST opis', 'tylko opis', $path, '/assets/uploads/gpx/o.gpx');
    $row = dr_find($created['id']);
    unlink($path);

    t_eq('tylko opis', $row['description'], 'opis zapisany');
    t_null($row['cover_photo_url'], 'zdjęcie nietknięte');
});

t_test('sam plik na realnym terenie wystarczy, żeby region się wyliczył', function () {
    // Region nie jest już parametrem — sam przebieg na prawdziwym terenie
    // ma wystarczyć (patrz dr_region_point).
    $p = dr_region_point();
    $path = dr_gpx_file(dr_line(20, $p['lat'], $p['lon']));
    $created = KnownRoute::createFromGpx('TEST region', null, $path, '/assets/uploads/gpx/r.gpx');
    $row = dr_find($created['id']);
    unlink($path);

    t_not_null($row['region_label'], 'region wyliczony bez żadnego ręcznego pola');
    t_null($row['description'], 'opis NULL');
    t_null($row['cover_photo_url'], 'zdjęcie NULL');
});

t_test('nazwa + zdjęcie, bez opisu i regionu', function () {
    $path = dr_gpx_file(dr_line(20));
    $created = KnownRoute::createFromGpx(
        'TEST zdjecie',
        null,
        $path,
        '/assets/uploads/gpx/z.gpx',
        '/assets/uploads/covers/z.jpg'
    );
    $row = dr_find($created['id']);
    unlink($path);

    t_eq('/assets/uploads/covers/z.jpg', $row['cover_photo_url'], 'zdjęcie zapisane');
    t_null($row['description'], 'opis NULL');
});

t_test('model nie normalizuje opisu — pusty string zostaje pustym stringiem', function () {
    // Kontroler zamienia '' na null (description() w KnownRouteController);
    // model ma zapisać DOKŁADNIE to, co dostał. Test pilnuje tej granicy —
    // gdyby normalizacja przeszła kiedyś do modelu, kontroler nie musiałby
    // już niczego zamieniać i ten test trzeba by zmienić świadomie.
    $path = dr_gpx_file(dr_line(10));
    $created = KnownRoute::createFromGpx('TEST pusty opis', '', $path, '/assets/uploads/gpx/po.gpx');
    $row = dr_find($created['id']);
    unlink($path);

    t_same('', $row['description'], 'pusty string nie zamieniony na NULL');
});

// --- Nazwa i slug ----------------------------------------------------

t_test('nazwa z polskimi znakami daje czysty slug', function () {
    $path = dr_gpx_file(dr_line(20));
    $created = KnownRoute::createFromGpx('Dębowy Łęg — TEST', null, $path, '/assets/uploads/gpx/s.gpx');
    $row = dr_find($created['id']);
    unlink($path);

    t_true((bool) preg_match('/^debowy-leg-test(-\d+)?$/', $row['slug']),
        'slug bez polskich znaków: ' . $row['slug']);
    t_true($row['slug'] === strtolower($row['slug']), 'slug małymi literami');
});

t_test('ta sama nazwa drugi raz dostaje inny slug', function () {
    // Adres /trasy/{slug} raz poszedł w świat — druga trasa o tej samej
    // nazwie nie może go przepisać. Format::uniqueSlug dosala -2, -3…
    $p1 = dr_gpx_file(dr_line(20));
    $p2 = dr_gpx_file(dr_line(20, 54.82, 18.00));
    $pierwsza = KnownRoute::createFromGpx('TEST duplikat', null, $p1, '/assets/uploads/gpx/d1.gpx');
    $druga    = KnownRoute::createFromGpx('TEST duplikat', null, $p2, '/assets/uploads/gpx/d2.gpx');
    $slug1 = dr_find($pierwsza['id'])['slug'];
    $slug2 = dr_find($druga['id'])['slug'];
    unlink($p1);
    unlink($p2);

    t_true($slug1 !== $slug2, 'slugi różne');
    t_true((bool) preg_match('/^test-duplikat(-\d+)?$/', $slug1), 'pierwszy: ' . $slug1);
    t_true((bool) preg_match('/^test-duplikat-\d+$/', $slug2), 'drugi dosolony: ' . $slug2);
});

t_test('nazwa bez liter nie daje pustego sluga', function () {
    // Slugify zostawia pustkę → fallback 'trasa' (z suffixem, jeśli zajęty).
    $path = dr_gpx_file(dr_line(10));
    $created = KnownRoute::createFromGpx('!!!', null, $path, '/assets/uploads/gpx/f.gpx');
    $row = dr_find($created['id']);
    unlink($path);

    t_true((bool) preg_match('/^trasa(-\d+)?$/', $row['slug']), 'fallback zamiast pustki: ' . $row['slug']);
});

t_test('nazwa o dokładnie 200 znakach mieści się w całości', function () {
    // Kolumna name to VARCHAR(200) — granica, którą kontroler pilnuje
    // mb_substr(0, 200). Model przyjmuje nazwę bez ograniczeń, więc testujemy
    // dokładnie granicę kolumny.
    $name = str_repeat('a', 200);
    $path = dr_gpx_file(dr_line(10));
    $created = KnownRoute::createFromGpx($name, null, $path, '/assets/uploads/gpx/200.gpx');
    $row = dr_find($created['id']);
    unlink($path);

    t_eq(200, mb_strlen($row['name']), '200 znaków zapisanych w całości');
    t_eq($name, $row['name'], 'żadnej zmiany treści');
});

// --- Plik GPX ---------------------------------------------------------

t_test('pusty GPX (bez punktów) jest odrzucany przez parser', function () {
    $path = sys_get_temp_dir() . '/dr_test_' . bin2hex(random_bytes(8)) . '.gpx';
    file_put_contents(
        $path,
        '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg></trkseg></trk></gpx>'
    );

    $wyjatek = null;
    try {
        KnownRoute::createFromGpx('TEST pusty gpx', null, $path, '/assets/uploads/gpx/empty.gpx');
    } catch (\RuntimeException $e) {
        $wyjatek = $e;
    }
    unlink($path);

    t_not_null($wyjatek, 'createFromGpx rzuca wyjątek');
    t_true(str_contains($wyjatek->getMessage(), 'Brak punktów'), 'komunikat o braku punktów');
});

t_test('ślad krótszy niż 100 m jest odrzucany przez parser', function () {
    // Gpx::parse ma próg istotności: trasa krótsza niż 100 m nie jest trasą
    // (pojedynczy punkt, dwa punkty obok siebie). createFromGpx nie dostaje
    // takiego pliku nigdy — parser rzuca, zanim cokolwiek trafi do bazy.
    // Dwa punkty w odległości ~6 m (0,0001° długości na 54,8 N) — dużo
    // poniżej progu 100 m.
    $path = dr_gpx_file([[54.80, 17.90], [54.80, 17.9001]]);

    $wyjatek = null;
    try {
        KnownRoute::createFromGpx('TEST za krotka', null, $path, '/assets/uploads/gpx/short.gpx');
    } catch (\RuntimeException $e) {
        $wyjatek = $e;
    }
    unlink($path);

    t_not_null($wyjatek, 'createFromGpx rzuca wyjątek');
    t_true(str_contains($wyjatek->getMessage(), 'zbyt krótka'), 'komunikat o progu 100 m');
});

/** Południkowa linia `$n` punktów co `$stepDeg` szerokości (1° ≈ 111 km). */
function dr_meridian(int $n, float $stepDeg = 0.01, float $lat = 20.0, float $lon = 17.90): array
{
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $points[] = [$lat + $i * $stepDeg, $lon];
    }
    return $points;
}

t_test('szlak dłuższy niż 1000 km wchodzi jako znana trasa', function () {
    // GREEN VELO MA 1892 KM. Do 2026-08-23 parser miał jeden, wspólny próg
    // 1000 km i taki plik wracał z formularza jako „Nie udało się odczytać
    // pliku GPX" — mimo że plik był w porządku. Znana trasa to szlak, nie
    // przejazd: dostaje Gpx::LONG_ROUTE_MAX_DISTANCE_KM.
    $path = dr_gpx_file(dr_meridian(1080)); // ~1200 km

    $created = KnownRoute::createFromGpx('TEST dlugi szlak', null, $path, '/assets/uploads/gpx/long.gpx');
    unlink($path);

    t_true((float) $created['distanceKm'] > 1000, 'dystans ponad 1000 km: ' . $created['distanceKm']);
    t_true($created['cells'] > 0, 'szlak dostał pola');
    t_eq('TEST dlugi szlak', dr_find($created['id'])['name'], 'trasa jest w bazie');
});

t_test('ten sam plik odrzuca DOMYŚLNY próg parsera', function () {
    // Druga połowa tej samej reguły: luźniejszy próg dostały WYŁĄCZNIE znane
    // trasy. Przejazd solo i etap wydarzenia dalej stoją na 1000 km, bo
    // pojedynczy przejazd tej długości to zlepek śladów, nie przejazd.
    $path = dr_gpx_file(dr_meridian(1080));

    $wyjatek = null;
    try {
        Gpx::parse($path);
    } catch (\RuntimeException $e) {
        $wyjatek = $e;
    }
    unlink($path);

    t_not_null($wyjatek, 'domyślny próg rzuca wyjątek');
    t_true(str_contains($wyjatek->getMessage(), 'zbyt długa'), 'komunikat o progu dystansu');
});

t_test('powyżej progu długiej trasy nadal odrzucane', function () {
    // Podniesienie progu to nie zdjęcie progu — ślad wokół połowy globu dalej
    // jest błędem pliku, nie szlakiem rowerowym.
    $path = dr_gpx_file(dr_meridian(1400, 0.02)); // ~3100 km

    $wyjatek = null;
    try {
        Gpx::parse($path, Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
    } catch (\RuntimeException $e) {
        $wyjatek = $e;
    }
    unlink($path);

    t_not_null($wyjatek, 'próg długiej trasy też ma górną granicę');
    t_true(str_contains($wyjatek->getMessage(), 'zbyt długa'), 'komunikat o progu dystansu');
});

t_test('token trzymanego GPX-a nie jest ścieżką do pliku', function () {
    // `gpx_token` przychodzi z formularza, więc jest danymi obcymi jak każde
    // inne. Upload::gpxTempPath() przepuszcza WYŁĄCZNIE 32 znaki hex — to ta
    // sama bramka co w promoteGpxTemp(), tylko że tu wynikiem jest ścieżka
    // na dysku, więc luka znaczyłaby czytanie cudzych plików.
    t_same(null, Utils\Upload::gpxTempPath('../../../etc/passwd'), 'wyjście z katalogu odrzucone');
    t_same(null, Utils\Upload::gpxTempPath('..'), 'dwie kropki odrzucone');
    t_same(null, Utils\Upload::gpxTempPath(''), 'pusty token odrzucony');
    t_same(null, Utils\Upload::gpxTempPath(null), 'brak tokenu odrzucony');
    t_same(null, Utils\Upload::gpxTempPath(str_repeat('A', 32)), 'wielkie litery odrzucone');
    // Poprawny format, ale pliku nie ma — też null, nie ścieżka do nieistniejącego.
    t_same(null, Utils\Upload::gpxTempPath(str_repeat('a', 32)), 'poprawny token bez pliku');
});

t_test('dłuższy ślad daje więcej pól i większy dystans', function () {
    // Ta sama linia, dwa razy więcej punktów — więcej pól i więcej kilometrów.
    // Liczby bezwzględne zależą od siatki, relacja nie może się zmienić.
    $p1 = dr_gpx_file(dr_line(20));
    $p2 = dr_gpx_file(dr_line(80));
    $krotka  = KnownRoute::createFromGpx('TEST krotka', null, $p1, '/assets/uploads/gpx/k1.gpx');
    $dluga   = KnownRoute::createFromGpx('TEST dluga', null, $p2, '/assets/uploads/gpx/k2.gpx');
    unlink($p1);
    unlink($p2);

    t_true($dluga['cells'] > $krotka['cells'], 'więcej pól: ' . $krotka['cells'] . ' < ' . $dluga['cells']);
    t_true((float) $dluga['distanceKm'] > (float) $krotka['distanceKm'], 'większy dystans');
    t_true((float) $krotka['distanceKm'] > 0, 'krótka też ma dystans');
});