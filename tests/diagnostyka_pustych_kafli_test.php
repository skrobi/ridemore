<?php
// tests/diagnostyka_pustych_kafli_test.php
// DIAGNOSTYKA: dlaczego kafel warstwy „Ślady" wychodzi PRZEZROCZYSTY, mimo że
// powinien coś narysować (2026-09-08).
//
// PO CO TEN PLIK ISTNIEJE. Wyścig zapisu w `GpxGeometry::ensure()`/
// `storeTrimmed()` (dziura w `gpx_tiles` powstająca przy równoległych
// żądaniach o nowy ślad) jest naprawiony i ma swój test w
// `tests/wydajnosc_mapy_test.php`. Ale „przezroczysty kafel tam, gdzie
// powinna być linia" ma WIĘCEJ NIŻ JEDNĄ możliwą przyczynę, a użytkownik
// widzi tylko OBJAW (biały/przezroczysty kwadrat), nie to, KTÓRY krok
// odpadł. Ten test przechodzi przez TĘ SAMĄ ścieżkę kodu co
// `TileController::renderTracks()` — `TileSource::tracks()`, potem
// `GpxGeometry::inTile()`/`load()` (albo ich odpowiedniki `*Trimmed`) — dla
// KAŻDEGO śladu, jaki serwis dziś zna, i mówi PO IMIENIU, na którym kroku
// dana grupa by odpadła:
//
//   BRAK PLIKU        — `gpx_url` wskazuje na plik, którego nie ma na dysku
//                        (`ensure()`/`ensureTrimmed()` oddały null).
//   PUSTA GEOMETRIA   — plik jest, ale `Utils\Gpx::parse()` nie znalazł w nim
//                        punktów (zły XML, brak `xmlns`, same punkty (0,0)) —
//                        `ensure()` ZAPISUJE to jako `points IS NULL`
//                        ŚWIADOMIE (żeby nie próbować parsować przy każdym
//                        kaflu), ale to znaczy „nigdy się nie narysuje",
//                        dopóki ktoś nie podmieni pliku.
//   BRAK KAFLI-INDEKSU — geometria JEST, ale `gpx_tiles`/`gpx_tiles_trimmed`
//                        nie ma dla niej ani jednego wiersza — to jest
//                        DOKŁADNIE ten wyścig, który miał naprawić fix wyżej;
//                        jeśli ten test to złapie MIMO naprawy, ślad padł
//                        w wyścig ZANIM poprawka trafiła do kodu (stary,
//                        „zamrożony" wiersz) i potrzebuje przeliczenia, nie
//                        tylko kasowania kafli PNG.
//   POZA GRUPĄ         — geometria i kafle-indeks są w porządku, ale
//                        `TileSource::tracks()` W OGÓLE nie zwraca tego
//                        hasha dla danego klucza (trasa wyłączona,
//                        `gpx_url` wyzerowany, złe query) — kasowanie kafli
//                        PNG nic tu nie da, bo `TileController` nigdy nie
//                        dostanie tego hasha do narysowania.
//
// URUCHOMIENIE: php tests/run.php diagnostyka_pustych_kafli

use Core\Database;
use Models\GpxGeometry;
use Models\TileSource;
use Utils\TileGrid;

/**
 * Sprawdza JEDEN hash dokładnie tak, jak zrobiłby to
 * `TileController::renderTracks()` — i mówi, na którym kroku by odpadł.
 *
 * @return string[] lista problemów (pusta = ślad narysuje się poprawnie)
 */
function dpk_sprawdz_hash(string $hash, string $zrodlo): array
{
    $db = Database::connection();
    $problemy = [];

    $tabelaGeom  = $zrodlo === 'trimmed' ? 'gpx_geometry_trimmed' : 'gpx_geometry';
    $tabelaTiles = $zrodlo === 'trimmed' ? 'gpx_tiles_trimmed' : 'gpx_tiles';

    $stmt = $db->prepare("SELECT points FROM $tabelaGeom WHERE gpx_hash = :h");
    $stmt->execute(['h' => $hash]);
    $wiersz = $stmt->fetch();

    if ($wiersz === false) {
        // Nie powinno się zdarzyć — hash pochodzi z ensure(), które ZAWSZE
        // wstawia wiersz (pusty albo pełny). Jeśli to widzimy, ktoś skasował
        // wiersz ręcznie (np. z /admin/kafle albo bezpośrednio z bazy) i NIC
        // go jeszcze nie odtworzyło.
        $problemy[] = "brak wiersza w $tabelaGeom — ktoś go skasował, a nic jeszcze nie policzyło od nowa";
        return $problemy;
    }

    if ($wiersz['points'] === null || $wiersz['points'] === '') {
        $problemy[] = "PUSTA GEOMETRIA w $tabelaGeom (points IS NULL) — plik nie sparsował się przy ensure(), sprawdź Utils\\Gpx::parse() na tym pliku ręcznie";
        return $problemy; // bez punktów nie ma czego szukać w gpx_tiles
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM $tabelaTiles WHERE gpx_hash = :h");
    $stmt->execute(['h' => $hash]);
    $ileKafli = (int) $stmt->fetchColumn();

    if ($ileKafli === 0) {
        $problemy[] = "BRAK KAFLI-INDEKSU w $tabelaTiles — geometria jest, ale gpx_tiles jest pusty dla tego hasha (ślad wyścigu SPRZED naprawy ensure()/storeTrimmed(); trzeba usunąć wiersz z $tabelaGeom i $tabelaTiles i policzyć od nowa, samo czyszczenie kafli PNG NIC nie da)";
    }

    return $problemy;
}

/**
 * Czy `TileSource::tracks($key)` w ogóle ODDAJE ten hash w którejś grupie.
 * Jeśli nie — problem jest o krok WCZEŚNIEJ niż geometria, i żadna naprawa
 * `GpxGeometry` tego nie ruszy.
 */
function dpk_hash_w_grupach(string $key, string $hash): bool
{
    foreach (TileSource::tracks($key) as $grupa) {
        if (in_array($hash, $grupa['hashes'], true)) {
            return true;
        }
    }
    return false;
}

t_test('DIAGNOSTYKA: każda AKTYWNA znana trasa (klucz `kr`) narysuje się, nie tylko ma plik', function () {
    $routes = Database::connection()->query(
        'SELECT id, slug, name, gpx_url FROM known_routes WHERE is_active = 1 AND gpx_url IS NOT NULL ORDER BY id'
    )->fetchAll();

    if (!$routes) {
        echo "    (brak aktywnych znanych tras w tej bazie — nic do sprawdzenia)\n";
        return;
    }

    $zle = [];
    foreach ($routes as $r) {
        $path = TileSource::absolutePath($r['gpx_url']);
        if (!is_file($path)) {
            $zle[] = "#{$r['id']} „{$r['name']}" . "\" ({$r['slug']}): BRAK PLIKU na dysku ({$r['gpx_url']})";
            continue;
        }

        $hash = GpxGeometry::ensure($path);
        if ($hash === null) {
            $zle[] = "#{$r['id']} „{$r['name']}\" ({$r['slug']}): ensure() oddał null mimo is_file() — plik nieczytelny?";
            continue;
        }

        $problemy = dpk_sprawdz_hash($hash, 'normal');
        if (!dpk_hash_w_grupach('kr', $hash)) {
            $problemy[] = "POZA GRUPĄ — TileSource::tracks('kr') nie zwraca tego hasha wcale (mimo is_active=1, gpx_url ustawiony — sprawdź routeGroups()/hashesFor())";
        }

        if ($problemy) {
            $zle[] = "#{$r['id']} „{$r['name']}\" ({$r['slug']}, hash " . substr($hash, 0, 12) . "…):\n        - " . implode("\n        - ", $problemy);
        }
    }

    if ($zle) {
        t_fail("znalezione trasy, które NIE narysują się na kaflu (" . count($zle) . "/" . count($routes) . "):\n    "
            . implode("\n    ", $zle));
    }
});

t_test('DIAGNOSTYKA: mapa społeczności (klucz `all`) — ślady wyjazdów i solo PRZYCIĘTE', function () {
    $problemyLacznie = [];

    foreach (TileSource::tracks('all') as $grupa) {
        $zrodlo = $grupa['source'] ?? 'normal';
        foreach ($grupa['hashes'] as $hash) {
            $problemy = dpk_sprawdz_hash($hash, $zrodlo);
            if ($problemy) {
                $problemyLacznie[] = substr($hash, 0, 12) . "… (źródło: $zrodlo):\n        - " . implode("\n        - ", $problemy);
            }
        }
    }

    if ($problemyLacznie) {
        t_fail("ślady mapy społeczności z problemem (" . count($problemyLacznie) . "):\n    "
            . implode("\n    ", $problemyLacznie));
    }
});

t_test('DIAGNOSTYKA: konkretny kafel indeksu — podaj hash i tx/ty ręcznie, jeśli wiesz który', function () {
    // Ręczne dopisanie: jeśli wiesz DOKŁADNIE, który kafel jest przezroczysty
    // (adres z karty sieci w przeglądarce, np.
    // /assets/tiles/slady/kr/14/8912/5432.png), wpisz tu z/x/y i hash trasy
    // (znajdziesz go w gpx_geometry po gpx_url z known_routes) — test policzy
    // GpxGeometry::inTile() DOKŁADNIE tak samo jak TileController i powie,
    // czy przy TYM z/x/y w ogóle powinna się narysować.
    $z = null; // np. 14
    $x = null; // np. 8912
    $y = null; // np. 5432
    $hash = null; // np. 'a1b2c3...' (64 znaki hex)

    if ($z === null || $x === null || $y === null || $hash === null) {
        echo "    (pomiń — wypełnij \$z/\$x/\$y/\$hash na górze tego testu, żeby sprawdzić konkretny kafel)\n";
        return;
    }

    $trafienie = GpxGeometry::inTile([$hash], $z, $x, $y);
    echo "    inTile([$hash], z=$z, x=$x, y=$y) => " . ($trafienie ? 'ZNALEZIONO' : 'NIC') . "\n";
    [$txMin, $txMax, $tyMin, $tyMax] = TileGrid::indexRange($z, $x, $y);
    echo "    zakres kafli indeksu (z14) dla tego żądania: x $txMin..$txMax, y $tyMin..$tyMax\n";

    $stmt = Database::connection()->prepare(
        'SELECT tx, ty FROM gpx_tiles WHERE gpx_hash = :h AND tx BETWEEN :x1 AND :x2 AND ty BETWEEN :y1 AND :y2'
    );
    $stmt->execute(['h' => $hash, 'x1' => $txMin, 'x2' => $txMax, 'y1' => $tyMin, 'y2' => $tyMax]);
    $wKrangu = $stmt->fetchAll();
    echo '    wiersze gpx_tiles w tym zakresie: ' . count($wKrangu) . "\n";

    t_true($trafienie !== [], 'ten kafel POWINIEN narysować podany hash (jeśli FAIL — patrz liczby wyżej: 0 wierszy w zakresie = brak w indeksie, >0 ale inTile() puste = błąd w kodzie inTile()/indexRange(), nie w danych)');
});
