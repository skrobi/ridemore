<?php
// tests/wydajnosc_mapy_test.php
// SZYBKOŚĆ MAPY — NIEZMIENNIKI, KTÓRE ŁATWO ZEPSUĆ PO CICHU (2026-09-02).
//
// Zgłoszenie usera: „przy tylko dwóch obszarach czekałem ponad 10 sekund, mimo
// że wiele rzeczy już przerenderowanych; warstwy lecą po kolei".
//
// Zmierzone przyczyny były trzy i wszystkie były NIEWIDOCZNE — mapa działała
// poprawnie, tylko wolno:
//   1. `TileSource::tracks()` wołało `GpxGeometry::ensure()` dla KAŻDEGO pliku
//      śladu, a to liczyło `hash_file()` — 55 plików po kilka MB przy KAŻDYM
//      kaflu (ok. 0,55 s, tyle samo dla pustego kafla nad Bałtykiem),
//   2. blokada pliku sesji ustawiała wszystkie kafle jednego kadru w kolejkę
//      (5 kafli równolegle: 0,97 s bez ciasteczka sesji, 3,10 s z nim),
//
// TRZECIA, ODRZUCONA POPRAWKA — dla potomności, bo kusi: „ta sama lista śladów
// liczy się w każdym z kilkudziesięciu procesów obsługujących jeden kadr, więc
// zapiszmy ją na dysku". Napisane i WYCOFANE tego samego dnia: sześć testów
// z innych zestawów natychmiast pokazało, czym to jest naprawdę — zapytanie
// „które ślady są na mapie" ma odpowiadać STANEM, a nie stanem sprzed sekundy
// (dane zmieniają się także bez podbicia epoki kafli). Zysk: ok. 25 ms na kafel.
// Cena: mapa, która czasem pokazuje wczorajszy świat. Nieopłacalne.
//
// Każdy z tych błędów wróciłby przy pierwszym „uproszczeniu" cache'ów, a żaden
// nie wywoła FAIL-a w testach funkcjonalnych: kafel dalej będzie poprawnym
// PNG-iem. Dlatego te testy pilnują MECHANIZMU, nie wyniku.
//
// DOPISANE 2026-09-07 — CZWARTA PRZYCZYNA, tym razem nie wolności, a
// POPRAWNOŚCI, i odkryta przez zgłoszenie usera dopiero PO naprawie #2 wyżej:
// „znany szlak urywa się pod którymś zoomem, wygląda jak błąd zapisu pod
// dużym obciążeniem". `GpxGeometry::ensure()` zapisywało nowy ślad DWOMA
// niezależnymi, autocommitującymi się krokami: najpierw wiersz
// `gpx_geometry`, potem (dla długiego szlaku kilkoma osobnymi zapytaniami)
// komplet `gpx_tiles`. Naprawa #2 wyżej (zwolnienie blokady sesji) sprawiła,
// że kafle jednego kadru NAPRAWDĘ jadą dziś równolegle — więc RÓWNOLEGŁE
// żądanie na INNY kafel TEGO SAMEGO nowego śladu potrafiło trafić dokładnie
// w szczelinę między tymi dwoma krokami: widziało już `gpx_geometry` (więc
// `ensure()` brało „krótką ścieżkę" i nie czekało na nic), ale `gpx_tiles`
// nie miało jeszcze wszystkich wierszy. `inTile()` nie znajdował śladu na
// TYM kaflu, renderer rysował go jako pusty, a to lądowało na dysku
// z `Cache-Control: immutable` — dziura w linii NA ZAWSZE, tylko na TYCH
// kaflach, które akurat trafiły w wyścig (stąd „pod którymś zoomem", nie
// wszędzie: każdy zoom to inny zestaw żądań, więc inna szansa trafienia
// w okno wyścigu). Naprawa: oba kroki w JEDNEJ transakcji — żaden czytelnik
// nie zobaczy `gpx_geometry` przed kompletem `gpx_tiles`. Ten sam wzorzec
// (i ten sam powód) dla bliźniaczej pary `gpx_geometry_trimmed`/
// `gpx_tiles_trimmed` w `storeTrimmed()`.
//
// DOPISANE TEGO SAMEGO DNIA — transakcja zapobiega TYLKO NOWYM przypadkom.
// Zgłoszenie z produkcji: nowy użytkownik zaimportował naraz wiele
// przejazdów, a jeden ślad DALEJ „urywa się" (padł w wyścig ZANIM ta
// poprawka trafiła do kodu — ma już wiersz `gpx_geometry`, więc `ensure()`
// bierze dla niego „krótką ścieżkę" na zawsze i nigdy więcej nie doda
// brakujących kafli). Nie da się poprosić kogoś o ponowne wgranie przejazdu
// solo, którego plik jest poza naszą kontrolą — stąd `GpxGeometry::
// repairTileIndex()`/`repairTrimmedTileIndex()`: dopisują WYŁĄCZNIE brakujące
// wiersze `gpx_tiles`(`_trimmed`), licząc je z geometrii JUŻ ZAPISANEJ
// w bazie, bez czytania pliku GPX. Wystawione jako `php tiles.php repair`
// i przycisk „Sprawdź i napraw indeks kafli" w `/admin/kafle` (`TilesController
// ::repairIndex`) — ten sam powód, dla którego panel `/admin/kafle` w ogóle
// istnieje: hosting bez konsoli.

use Models\GpxGeometry;
use Models\TileSource;

t_test('hash pliku liczy się raz na wersję pliku, a nie raz na kafel', function () {
    // Własny plik, nie `t_solo_gpx()` z sąsiedniego zestawu: ten test musi
    // działać także wtedy, gdy ktoś odpala sam ten plik (`php tests/run.php wydajnosc`).
    $pliki = glob(CORE_PATH . '/../assets/uploads/gpx/*.gpx') ?: [];
    if (!$pliki) {
        t_fail('Brak jakiegokolwiek pliku GPX w assets/uploads/gpx.');
    }
    $zrodlo = $pliki[0];
    $kopia = CORE_PATH . '/../assets/uploads/gpx/tmp/wydajnosc-' . bin2hex(random_bytes(6)) . '.gpx';
    @mkdir(dirname($kopia), 0777, true);
    copy($zrodlo, $kopia);

    try {
        $pierwszy = GpxGeometry::fileHash($kopia);
        t_eq(hash_file('sha256', $kopia), $pierwszy, 'wynik jest tym samym hashem co hash_file');

        // PODMIENIONY PLIK MUSI DAĆ NOWY HASH. To jest cała cena tego cache'u:
        // gdyby ważność szła po samej ścieżce, podmiana śladu zostawiłaby mapę
        // rysującą starą trasę — na zawsze, bo geometria jest kluczowana hashem.
        file_put_contents($kopia, "<!-- zmiana -->\n", FILE_APPEND);
        touch($kopia, time() + 2);
        clearstatcache(true, $kopia);

        // Świeży proces = pusta pamięć w obrębie żądania; tu wymuszamy to samo,
        // pytając o ŚCIEŻKĘ, która zmieniła stempel (mtime + rozmiar).
        $drugi = GpxGeometry::fileHash($kopia);
        t_eq(hash_file('sha256', $kopia), $drugi, 'po podmianie pliku hash jest przeliczony');
        t_true($pierwszy !== $drugi, 'to naprawdę inny hash niż przed podmianą');
    } finally {
        @unlink($kopia);
    }
});

t_test('odczyty mapy zwalniają blokadę sesji — inaczej kafle stoją w kolejce', function () {
    // Kod, nie zachowanie: blokady sesji nie da się zaobserwować w jednym
    // procesie CLI. Pilnujemy więc tego, co da się zepsuć przez przypadek —
    // usunięcia wywołania przy „porządkach".
    $tile = (string) file_get_contents(CORE_PATH . '/Controllers/TileController.php');
    $api = (string) file_get_contents(CORE_PATH . '/../api/routes.php');

    // W kaflu: PO sprawdzeniu uprawnień (czyta sesję), PRZED rysowaniem.
    t_true(
        (bool) preg_match('/TileSource::isAllowed.*Session::release\(\).*renderHexes/s', $tile),
        'kafel zwalnia sesję po sprawdzeniu uprawnień, a przed renderowaniem'
    );
    // W API: tylko dla GET-ów i dopiero po rozwiązaniu użytkownika (zablokowane
    // konto wylogowuje się zapisem do sesji — ten zapis musi zdążyć).
    t_true(
        (bool) preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) === 'GET'\)\s*\{\s*Auth::user\(\);\s*Core\\\\Session::release\(\);/s", $api),
        'API zwalnia sesję na GET-ach, po Auth::user()'
    );
    t_false(
        (bool) preg_match('/router->post\([^)]*\)[^;]{0,4000}Session::release/s', $api),
        'żaden POST nie zwalnia sesji — zapisy muszą trafić na dysk'
    );
});

t_test('kafel prywatnego klucza nadal nie wolno cache\'ować, głęboki zoom jawnego — wolno', function () {
    // Rozdzielenie tych dwóch przypadków (2026-09-02) było poprawką wydajności,
    // ale dotyka PRYWATNOŚCI, więc ma swój test: mapa osoby ukrytej z list nie
    // może wylądować w żadnym cache'u, także po tej zmianie.
    $tile = (string) file_get_contents(CORE_PATH . '/Controllers/TileController.php');

    t_true(
        (bool) preg_match('/\$prywatny = !TileSource::isCacheable\(\$key\);/', $tile),
        'prywatność klucza jest osobnym pytaniem niż „czy zapisać na dysku"'
    );
    t_true(
        (bool) preg_match('/\$prywatny\s*\?\s*.Cache-Control: private, no-store./s', $tile),
        'klucz prywatny dostaje no-store'
    );
    t_true(
        (bool) preg_match('/Cache-Control: private, max-age=\d+, immutable/', $tile),
        'głęboki zoom jawnego klucza wolno trzymać w PRZEGLĄDARCE (adres niesie epokę)'
    );
    t_false(TileSource::isCacheable('me'), 'przypomnienie, czym jest klucz prywatny');
});

t_test('ensure()/storeTrimmed(): wiersz gpx_geometry i komplet gpx_tiles idą jedną transakcją', function () {
    // Kod, nie zachowanie: ten wyścig wymaga DWÓCH procesów PHP naraz, więc
    // znowu — jak przy blokadzie sesji wyżej — nie da się go zobaczyć
    // w jednym procesie CLI. Pilnujemy, żeby ktoś przy „porządkach" nie
    // zdjął transakcji jako „niepotrzebnego opakowania" wokół dwóch prostych
    // INSERT-ów: pojedynczo każdy z nich dalej działa poprawnie, więc żaden
    // test FUNKCJONALNY by tego nie złapał (dokładnie jak w komentarzu na
    // górze pliku).
    $geom = (string) file_get_contents(CORE_PATH . '/Models/GpxGeometry.php');

    // ensure(): INSERT gpx_geometry ... pętla z INSERT gpx_tiles ... commit,
    // wszystko między jednym beginTransaction() a jednym commit().
    t_true(
        (bool) preg_match(
            '/beginTransaction\(\).*INSERT IGNORE INTO gpx_geometry\b.*'
          . 'INSERT IGNORE INTO gpx_tiles \(gpx_hash, tx, ty\).*commit\(\)/s',
            $geom
        ),
        'ensure(): geometria i komplet kafli-indeksu commitują się RAZEM'
    );

    // storeTrimmed(): bliźniacza para tabel, ta sama zasada.
    t_true(
        (bool) preg_match(
            '/beginTransaction\(\).*INSERT IGNORE INTO gpx_geometry_trimmed\b.*'
          . 'INSERT IGNORE INTO gpx_tiles_trimmed \(gpx_hash, tx, ty\).*commit\(\)/s',
            $geom
        ),
        'storeTrimmed(): geometria PRZYCIĘTA i komplet kafli-indeksu commitują się RAZEM'
    );

    // WŁASNA TRANSAKCJA MUSI UMIEĆ ŻYĆ WEWNĄTRZ CUDZEJ — testy same działają
    // w jednej (patrz tests/run.php), więc gdyby `ensure()` wołało
    // `beginTransaction()` bezwarunkowo (bez sprawdzenia `inTransaction()`),
    // PDO rzuciłoby wyjątkiem na już otwartej transakcji i wysypałoby to
    // (a za nim każdy inny test dotykający nowego pliku GPX). Sprawdzamy to
    // NAPRAWDĘ, nie tylko w kodzie źródłowym: prawdziwe wywołanie na świeżym,
    // nigdy wcześniej niepoliczonym pliku, zagnieżdżone w transakcji tego testu.
    $xml = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>';
    for ($i = 0; $i < 30; $i++) {
        $xml .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', 54.70 + $i * 0.002, 17.70 + $i * 0.002);
    }
    $xml .= '</trkseg></trk></gpx>';
    $url = Utils\Upload::saveGpxContents($xml);
    if ($url === null) {
        t_fail('Nie udało się zapisać testowego pliku GPX.');
    }
    $path = CORE_PATH . '/..' . $url;

    try {
        $hash = GpxGeometry::ensure($path);
        t_true($hash !== null, 'ensure() zagnieżdżone w transakcji testu nie wywala się');

        $tiles = GpxGeometry::tilesFor((string) $hash);
        t_true($tiles !== [], 'nowy ślad dostał komplet gpx_tiles od razu, w tym samym wywołaniu');

        [$tx, $ty] = $tiles[0];
        t_same(
            [(string) $hash],
            GpxGeometry::inTile([(string) $hash], \Utils\TileGrid::INDEX_Z, $tx, $ty),
            'kafel indeksu, który ślad naprawdę dotyka, widzi go od razu po ensure()'
        );
    } finally {
        @unlink($path);
    }
});

t_test('repairTileIndex(): dopisuje TYLKO brakujące kafle, BEZ pliku GPX na dysku', function () {
    // Symulacja STAREJ szkody: świeży ślad, którego indeks „ktoś" (wyścig
    // sprzed naprawy) zdążył zapisać tylko częściowo. Kasujemy plik PRZED
    // naprawą — to jest sedno testu: właściciela przejazdu solo nie da się
    // poprosić o ponowne wgranie, więc naprawa MUSI działać z samej bazy.
    $xml = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>';
    for ($i = 0; $i < 60; $i++) {
        $xml .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', 53.10 + $i * 0.004, 15.10 + $i * 0.004);
    }
    $xml .= '</trkseg></trk></gpx>';
    $url = Utils\Upload::saveGpxContents($xml);
    if ($url === null) {
        t_fail('Nie udało się zapisać testowego pliku GPX.');
    }
    $path = CORE_PATH . '/..' . $url;

    $hash = (string) GpxGeometry::ensure($path);
    t_true($hash !== '', 'geometria testowego śladu policzyła się');

    $pelnyIndeks = GpxGeometry::tilesFor($hash);
    t_true(count($pelnyIndeks) >= 2, 'test zakłada ślad dotykający co najmniej dwóch kafli indeksu — zwiększ liczbę punktów, jeśli to nie prawda');

    // USZKADZAMY: kasujemy JEDEN wiersz gpx_tiles wprost przez SQL — dokładnie
    // to, czego NIE zdążył dopisać stary, nietransakcyjny kod przy wyścigu.
    [$usunTx, $usunTy] = $pelnyIndeks[0];
    $db = Core\Database::connection();
    $db->prepare('DELETE FROM gpx_tiles WHERE gpx_hash = :h AND tx = :x AND ty = :y')
        ->execute(['h' => $hash, 'x' => $usunTx, 'y' => $usunTy]);
    t_eq(count($pelnyIndeks) - 1, count(GpxGeometry::tilesFor($hash)), 'uszkodzenie faktycznie usunęło jeden wiersz');

    // PLIK ZNIKA Z DYSKU — jeśli naprawa go potrzebuje, test to złapie.
    @unlink($path);
    t_false(is_file($path), 'plik testowy naprawdę zniknął, naprawa nie może z niego skorzystać');

    $wynik = GpxGeometry::repairTileIndex();
    t_true($wynik['sprawdzone'] >= 1, 'przebieg dotknął przynajmniej ten jeden ślad');

    $poNaprawie = GpxGeometry::tilesFor($hash);
    t_eq(count($pelnyIndeks), count($poNaprawie), 'po naprawie indeks ma z powrotem KOMPLET kafli');
    t_true(
        in_array([$usunTx, $usunTy], $poNaprawie, false),
        'skasowany wcześniej kafel ($usunTx,$usunTy) wrócił do indeksu'
    );

    // IDEMPOTENCJA: drugie uruchomienie na już naprawionym śladzie nic
    // więcej dla NIEGO nie dopisuje (inne ślady w bazie deweloperskiej mogą
    // nadal potrzebować naprawy, więc sprawdzamy TEN hash osobno, nie
    // globalne liczniki przebiegu).
    GpxGeometry::repairTileIndex();
    t_eq(count($pelnyIndeks), count(GpxGeometry::tilesFor($hash)), 'drugie uruchomienie niczego nie psuje ani nie dubluje');
});
