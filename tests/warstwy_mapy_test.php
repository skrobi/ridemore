<?php
// tests/warstwy_mapy_test.php
// WARSTWY MAPY W SŁOWNIKU (Etap 2, tasks/done/warstwy-mapy.md).
//
// Dane czyta EXACT tak samo jak produkcja: ze słownika `map_layer` zasianego
// migracją 072 (nie ma tu żadnego sztucznego drzewa budowanego na potrzeby
// testu) — więc te testy pilnują RAZEM dwóch rzeczy naraz: że kod
// `Models\MapLayer` działa poprawnie I że słownik ma w sobie to, czego kod
// oczekuje. Literówka w `code` migracji albo skasowany wiersz spadną tu,
// zanim spadną na wszystkich mapach serwisu naraz (§ „is_active w słowniku
// gasi warstwę w CAŁYM serwisie" — decyzja architektoniczna 8 w tasks/).
use Models\MapLayer;

t_test('drzewo: pięć rodzin na starcie, każda z `kind`', function () {
    $tree = MapLayer::tree('all');
    $klucze = array_column($tree, 'key');
    t_same(['cells', 'heat', 'slady', 'trails', 'treasures'], $klucze, 'kolejność i komplet kluczy top-level');
    foreach ($tree as $node) {
        t_true($node['kind'] !== null, $node['key'] . ' ma `kind` (mówi renderowi, jak się rysuje)');
    }
});

t_test('drzewo: „Ślady" idzie za KONTEKSTEM, nie stoi w miejscu (Etap 1b)', function () {
    // `me` ZAWSZE `me` (naprawa błędu 2026-08-26: solo w ogóle nie wchodziło
    // na kafel — jedyne bezpieczne miejsce dla surowego pliku solo, §27, to
    // klucz PRYWATNY, więc kontekst `me` ignoruje slug), `rider` publicznie
    // `u-{slug}` — to WCIĄŻ jest widok kogoś innego, nawet gdy patrzy właściciel.
    $spodziewane = ['all' => 'all', 'me' => 'me', 'rider' => 'u-ktos'];
    foreach ($spodziewane as $ctx => $oczekiwanyKlucz) {
        $tree = MapLayer::tree($ctx, ['slug' => 'ktos']);
        $slady = array_values(array_filter($tree, fn(array $n) => $n['key'] === 'slady'))[0] ?? null;
        t_true($slady !== null, 'slady istnieje w kontekście ' . $ctx);
        t_eq($oczekiwanyKlucz, $slady['trackKey'], 'trackKey dla ' . $ctx . ' wskazuje właściwy zbiór');
    }
});

t_test('drzewo: `subject-private` IGNORUJE slug — własna mapa jest zawsze prywatna', function () {
    // W odróżnieniu od `subject` (rider), gdzie brak slugu jest ZAPASEM —
    // tu `me` jest jedyną poprawną odpowiedzią NIEZALEŻNIE od slugu, bo to
    // jedyny klucz, na którym `TileSource::tracks()` w ogóle dorysowuje solo.
    $zeSlugiem = MapLayer::tree('me', ['slug' => 'ktos']);
    $bezSlugu = MapLayer::tree('me', ['slug' => null]);
    foreach (['ze slugiem' => $zeSlugiem, 'bez slugu' => $bezSlugu] as $etykieta => $tree) {
        $slady = array_values(array_filter($tree, fn(array $n) => $n['key'] === 'slady'))[0];
        t_eq('me', $slady['trackKey'], 'własna mapa (' . $etykieta . ') = klucz `me`');
    }
});

t_test('drzewo: dzieci „Skarbów" gasną dla gościa (`requiresLogin`)', function () {
    $gosc = MapLayer::tree('all', ['loggedIn' => false]);
    $zalogowany = MapLayer::tree('all', ['loggedIn' => true]);
    $treasuresGosc = array_values(array_filter($gosc, fn(array $n) => $n['key'] === 'treasures'))[0];
    $treasuresZal = array_values(array_filter($zalogowany, fn(array $n) => $n['key'] === 'treasures'))[0];
    t_count(0, $treasuresGosc['children'], 'gość nie widzi rozbicia na odkryte/nieodkryte');
    t_count(2, $treasuresZal['children'], 'zalogowany widzi oba: odkryte i nieodkryte');
    t_same(['treasuresFound', 'treasuresNew'], array_column($treasuresZal['children'], 'key'), 'w tej kolejności');
});

t_test('drzewo: `rider` bez `isOwner` zostaje publiczny — obcy widz nigdy nie dostaje solo', function () {
    // Ochrona §27: bez wprost podanego `isOwner`, kontekst `rider` MUSI
    // zostać na publicznym `u-{slug}`, żeby gość (i każdy inny zalogowany)
    // nie zobaczył surowych plików solo tej osoby.
    $tree = MapLayer::tree('rider', ['loggedIn' => true, 'slug' => 'ktos']);
    $slady = array_values(array_filter($tree, fn(array $n) => $n['key'] === 'slady'))[0];
    t_eq('u-ktos', $slady['trackKey'], 'domyślnie (bez isOwner) rider = publiczny klucz u-{slug}');
});

t_test('drzewo: `rider` + `isOwner` daje WŁAŚCICIELOWI prywatny klucz `me`', function () {
    // Zgłoszenie usera 2026-08-27: „mam inne ślady niż na mapie własnej
    // w odkryciach, powinienem mieć to samo". Właściciel patrzący na WŁASNY
    // profil ma dostać dokładnie to, co widzi na /odkrycia — komplet, solo
    // włącznie — a nie publiczną, okrojoną wersję.
    $tree = MapLayer::tree('rider', ['loggedIn' => true, 'slug' => 'ktos', 'isOwner' => true]);
    $slady = array_values(array_filter($tree, fn(array $n) => $n['key'] === 'slady'))[0];
    t_eq('me', $slady['trackKey'], 'właściciel na własnym profilu = prywatny klucz me');
});

t_test('tileKeyFor: `isOwner` dotyczy WYŁĄCZNIE tokenu `subject`, nie zmienia innych', function () {
    // `subject-done` (znane trasy ukończone) nie ma tu żadnego problemu
    // prywatności — ukończenie szlaku nie jest surowym plikiem spod domu —
    // więc `isOwner` nie ma prawa nic w nim zmienić.
    t_eq('kd-ktos', MapLayer::tileKeyFor('subject-done', 'ktos', true), 'subject-done ignoruje isOwner');
    t_eq('all', MapLayer::tileKeyFor('community', 'ktos', true), 'community ignoruje isOwner');
});

t_test('drzewo: kontekst `rider` nie rozbija skarbów na dzieci NAWET dla zalogowanego', function () {
    // Profil pokazuje KOLEKCJĘ tej osoby (zawężenie robi adres endpointu przez
    // `slug`/`rider=`, nie parametr `stan`) — `shown:false` w migracji 072,
    // niezależnie od tego, kto ogląda.
    $tree = MapLayer::tree('rider', ['loggedIn' => true, 'slug' => 'ktos']);
    $treasures = array_values(array_filter($tree, fn(array $n) => $n['key'] === 'treasures'))[0];
    t_count(0, $treasures['children'], 'rider: „Skarby" bez dzieci, żaden login tego nie zmienia');
});

t_test('drzewo: `only` zawęża TYLKO top-level, dzieci zostają nietknięte', function () {
    // Ta sama reguła, której używają TrailController i EventController, żeby
    // zdjąć „Ślady" ze stron, które nie są mapą odkryć.
    $tree = MapLayer::tree('all', ['loggedIn' => true, 'only' => ['cells', 'heat', 'trails', 'treasures']]);
    t_same(['cells', 'heat', 'trails', 'treasures'], array_column($tree, 'key'), 'bez `slady`');
    $treasures = array_values(array_filter($tree, fn(array $n) => $n['key'] === 'treasures'))[0];
    t_count(2, $treasures['children'], '`only` nie dotyka dzieci — Etap 3 wciąż ma na czym pracować');
});

t_test('tileKeysFor: po jednym kluczu na warstwę `kind:tiles`, nic więcej', function () {
    // Etap 3 + migr. 078 (2026-08-29): „Trasy" w kontekście `me` to DWIE
    // warstwy — ukończone (`kd-{slug}`) i nieukończone (`kn-{slug}`), nie
    // cały katalog pod jednym kluczem `trails` — inaczej niż w kontekście
    // `all` (osobny test niżej). Rodzic `trails` sam nie niesie już źródła
    // w `me`/`rider` (dzieci je przejęły), więc w wyniku nie ma klucza
    // `trails`. „Ślady" w `me` to zawsze `me` (naprawa błędu 2026-08-26) —
    // NIEZALEŻNIE od slugu, bo to jedyny klucz z solo.
    $tree = MapLayer::tree('me', ['slug' => 'jan']);
    $keys = MapLayer::tileKeysFor($tree);
    t_same(
        ['slady' => 'me', 'trailsDone' => 'kd-jan', 'trailsRemaining' => 'kn-jan'],
        $keys,
        'dokładnie te trzy warstwy mają klucz kafla'
    );
});

t_test('tileKeysFor: „Trasy" w kontekście `all` to CAŁY katalog, nie niczyje ukończenia', function () {
    $tree = MapLayer::tree('all');
    t_same(['slady' => 'all', 'trails' => 'kr'], MapLayer::tileKeysFor($tree),
        'społeczność widzi katalog — user 2026-08-26: „w kontekście społeczności dla całości"');
});

t_test('tileKeyFor: `subject-done` bez slugu spada na prywatny klucz `kd-me`', function () {
    // Ten sam wzorzec co `subject` -> `me` przy śladach: ktoś zalogowany bez
    // publicznego profilu wciąż musi widzieć WŁASNE ukończenia.
    t_eq('kd-me', MapLayer::tileKeyFor('subject-done', null), 'brak slugu = prywatny klucz kd-me');
    t_eq('kd-jan', MapLayer::tileKeyFor('subject-done', 'jan'), 'ze slugiem = publiczny klucz kd-{slug}');
});

t_test('filtersFor: opis dla JS niesie param rodzica i wartości dzieci', function () {
    $tree = MapLayer::tree('all', ['loggedIn' => true]);
    $filters = MapLayer::filtersFor($tree);
    t_same(['stan', ['treasuresFound' => 'moje', 'treasuresNew' => 'nowe']],
        [$filters['treasures']['param'], $filters['treasures']['children']],
        'dokładnie ta para, którą czyta ridemoreComposeFilter w JS');
});

t_test('filtersFor: rodzina bez dzieci (rider) niesie pusty opis, nie brak wpisu', function () {
    // `ridemoreComposeFilter` w JS czyta PUSTĄ listę dzieci jako „rodzic
    // zapalony = brak filtra" — gdyby wpisu w ogóle nie było, JS wpadłby
    // w zapasową (starą) regułę zamiast tej.
    $tree = MapLayer::tree('rider', ['loggedIn' => true, 'slug' => 'ktos']);
    $filters = MapLayer::filtersFor($tree);
    t_true(isset($filters['treasures']), 'wpis dla `treasures` istnieje');
    t_count(0, $filters['treasures']['children'], 'ale bez dzieci');
});

t_test('withQueryOverrides: `?klucz=0` gasi, każda inna wartość zostawia domyślny', function () {
    $tree = MapLayer::tree('all', ['loggedIn' => true]);
    $po = MapLayer::withQueryOverrides($tree, ['trails' => '0', 'heat' => '1', 'treasuresFound' => '0']);
    $by = fn(array $t, string $k) => array_values(array_filter($t, fn($n) => $n['key'] === $k))[0];
    t_false($by($po, 'trails')['on'], '?trails=0 gasi');
    t_true($by($po, 'heat')['on'], '?heat=1 zapala (domyślnie zgaszona)');
    t_true($by($po, 'treasures')['on'], 'treasures bez override — zostaje domyślne');
    $treasures = $by($po, 'treasures');
    $treasuresFound = array_values(array_filter($treasures['children'], fn($n) => $n['key'] === 'treasuresFound'))[0];
    t_false($treasuresFound['on'], 'override dotarł też do dziecka');
});

t_test('flatten: płaska mapa klucz => on, z dziećmi włącznie', function () {
    $tree = MapLayer::tree('all', ['loggedIn' => true]);
    $flat = MapLayer::flatten($tree);
    t_true(array_key_exists('treasuresFound', $flat), 'dziecko trafia do płaskiej mapy tak samo jak rodzic');
    t_eq(true, $flat['cells'], 'wartość to `on`, nie sam fakt istnienia klucza');
});

t_test('tileKeyFor: nieznany token nie wybucha, oddaje null', function () {
    t_true(MapLayer::tileKeyFor('cos-czego-nie-ma', 'jan') === null, 'nieznany token = brak klucza, nie wyjątek');
});
t_test('strona wydarzenia: warstwy mapy znają widza NIEZALEŻNIE od statusu wydarzenia', function () {
    // USTERKA NAPRAWIONA 2026-08-29. `$viewerId` przypisywało się WYŁĄCZNIE
    // wewnątrz `if ($eventData['statusCode'] === 'published')`, a warstwy mapy
    // czytają je ~100 linii dalej, bezwarunkowo. Dla wydarzenia zakończonego,
    // anulowanego albo szkicu zmienna nie istniała, więc `$viewerId !== null`
    // było fałszem NIEZALEŻNIE od tego, kto patrzy: zalogowany dostawał mapę
    // w trybie wylogowanego (własna warstwa odkryć wyłączona, podpis „gdzie
    // jeżdżą inni" zamiast „gdzie już byłeś"). Bez błędu na ekranie — jedynym
    // objawem był warning w logu.
    //
    // Test na ŹRÓDLE, nie na wyrenderowanej stronie: show() kończy się
    // renderowaniem widoku, a odtworzenie tu wydarzenia w każdym z czterech
    // statusów kosztowałoby więcej niż sprawdzana reguła. Pilnujemy tego, co
    // faktycznie się zepsuło — KOLEJNOŚCI przypisania względem bramki statusu.
    $src = (string) file_get_contents(CORE_PATH . '/../core/Controllers/EventController.php');

    $przypisanie = strpos($src, '$viewerId = Auth::check()');
    $bramka      = strpos($src, "if (\$eventData['statusCode'] === 'published')");
    $uzycie      = strpos($src, "MapLayer::tree(\$viewerId");

    t_true($przypisanie !== false, '$viewerId jest w ogóle przypisywane');
    t_true($bramka !== false, 'bramka statusu istnieje');
    t_true($uzycie !== false, 'warstwy mapy czytają $viewerId');
    t_true($przypisanie < $bramka, '$viewerId ustawiane PRZED bramką statusu, nie w środku');
    t_true($bramka < $uzycie, 'warstwy mapy czytają je po bramce — czyli poza nią');

    // Jedno źródło prawdy: powtórzone `Auth::check() ? ... : null` to drugie
    // miejsce, które może się rozjechać — i właśnie się rozjechało.
    t_eq(
        1,
        substr_count($src, '$viewerId = Auth::check()'),
        '$viewerId liczone raz na żądanie, nie w kilku miejscach'
    );
});

// --- Etap 5 kontraktu: KOMPLET KLUCZY MIĘDZY SŁOWNIKIEM A KODEM ------------
//
// „Literówka w `code` gasi warstwę na wszystkich mapach naraz i nikt się o tym
// nie dowie" — to jest dokładnie ten rodzaj awarii, którego nie widać: warstwa
// po prostu przestaje się rysować, bez błędu w logu i bez pustego miejsca
// w interfejsie. Te dwa testy zamykają obie strony styku: token ze słownika
// musi być rozpoznawany przez kod, a wpisanie go w słowniku musi realnie
// wyprodukować klucz kafla w zbudowanym drzewie.

/** Tokeny `source` ze słownika, per kontekst: ['me' => ['subject-private', ...], ...] */
function t_warstwy_zrodla(): array
{
    $rows = Core\Database::connection()->query('
        SELECT i.code, i.meta FROM dictionary_items i
          JOIN dictionaries d ON d.id = i.dictionary_id AND d.code = "map_layer"
         WHERE i.is_active = 1
    ')->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $meta = json_decode((string) $r['meta'], true) ?: [];
        foreach (($meta['contexts'] ?? []) as $kontekst => $cfg) {
            if (isset($cfg['source'])) {
                $out[$kontekst][$r['code']] = (string) $cfg['source'];
            }
        }
    }
    return $out;
}

t_test('słownik warstw: KAŻDY token `source` jest rozpoznawany przez tileKeyFor', function () {
    $zrodla = t_warstwy_zrodla();

    // Bez tego strażnika pusty (albo zepsuty) słownik przechodziłby ten test
    // bezszelestnie — pętla po zerze elementów zawsze jest zielona.
    $ile = array_sum(array_map('count', $zrodla));
    t_true($ile >= 6, "słownik niesie tokeny źródeł (jest $ile)");

    foreach ($zrodla as $kontekst => $warstwy) {
        foreach ($warstwy as $kod => $token) {
            t_not_null(
                MapLayer::tileKeyFor($token, 'jan'),
                "token „$token\" (warstwa $kod, kontekst $kontekst) ma odpowiednik w tileKeyFor()"
            );
        }
    }
});

t_test('słownik warstw: każdy zadeklarowany `source` daje realny klucz kafla w drzewie', function () {
    // Druga strona tego samego styku. Poprzedni test sprawdza SŁOWNIK -> KOD,
    // ten SŁOWNIK -> DRZEWO: literówka w nazwie kontekstu („mee" zamiast „me")
    // przeszłaby tamten test, bo sam token byłby poprawny, a warstwa i tak
    // zniknęłaby z mapy. Porównujemy ZBIORY kluczy, więc test złapie też
    // sytuację odwrotną — kod produkujący kafel dla warstwy, której nikt nie
    // zadeklarował w słowniku.
    $zrodla = t_warstwy_zrodla();

    foreach (['all', 'me', 'rider'] as $kontekst) {
        $tree = MapLayer::tree($kontekst, ['loggedIn' => true, 'slug' => 'jan']);
        $zDrzewa = array_keys(MapLayer::tileKeysFor($tree));
        $zeSlownika = array_keys($zrodla[$kontekst] ?? []);
        sort($zDrzewa);
        sort($zeSlownika);
        t_same($zeSlownika, $zDrzewa, "kontekst „$kontekst\": słownik i drzewo mówią o tych samych warstwach");
    }
});

// --- Etap 4: PANEL SKARBÓW W ADMINIE DOSTAJE WSPÓLNĄ KONTROLKĘ -------------
//
// Decyzja usera 2026-08-29: „kontrolka tak, silnik nie" — ekran rysuje własne
// pinezki dalej sam, ale warstwy KONTEKSTOWE (mgła, heatmapa, ślady, trasy)
// bierze z tego samego słownika i tej samej kontrolki co reszta serwisu.

t_test('panel skarbów: warstwy kontekstowe TAK, węzeł „Skarby" NIE', function () {
    // `only` bez `treasures` jest tu istotą rzeczy, nie kosmetyką: skarby na
    // tej mapie rysuje sam panel, przeciąganymi pinezkami. Warstwa z silnika
    // postawiłaby obok nich drugi komplet znaczników tych samych punktów.
    $tree = MapLayer::tree('all', [
        'loggedIn' => true,
        'only'     => ['cells', 'heat', 'slady', 'trails'],
    ]);
    $klucze = array_map(static fn(array $n): string => $n['key'], $tree);

    t_same(['cells', 'heat', 'slady', 'trails'], $klucze, 'dokładnie cztery warstwy kontekstowe');
    t_false(in_array('treasures', $klucze, true), 'bez węzła Skarby — pinezki panelu zostają jedyne');

    // Warstwy kafelkowe muszą mieć skąd wziąć obrazki, inaczej przełącznik
    // zapala coś, co nie ma czego narysować.
    $kafle = MapLayer::tileKeysFor($tree);
    t_same(['slady' => 'all', 'trails' => 'kr'], $kafle, 'ślady i trasy mają klucze kafli');
});

t_test('panel skarbów: kontrolka i stan warstw idą z JEDNEGO źródła', function () {
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasures-admin.php');
    $ctrl  = (string) file_get_contents(CORE_PATH . '/Controllers/Admin/TreasureController.php');
    $css   = (string) file_get_contents(CORE_PATH . '/../assets/css/style.css');

    t_true(str_contains($widok, "partials/map-layers.php"), 'ekran używa wspólnego partiala, nie własnej kontrolki');
    t_true(str_contains($ctrl, "'only'     => ['cells', 'heat', 'slady', 'trails']"), 'kontroler zawęża drzewo bez skarbów');
    t_true(str_contains($ctrl, "'mapSources' => \$mapSources"), 'i podaje szablony kafli');

    // TO JEST TA ZMIANA: stan warstw pochodzi z kontrolki, a nie z obiektu
    // wpisanego w szablon. Sztywna lista zostaje wyłącznie jako zapas.
    t_true(
        (bool) preg_match('/layers: \(boxWarstw && typeof ridemoreReadLayers/', $widok),
        'stan warstw czytany z kontrolki'
    );
    t_true(
        str_contains($widok, 'map.ridemoreSetLayer(cb.dataset.layer, cb.checked)'),
        'zmiana checkboksu przełącza warstwę tą samą metodą co na /odkrycia'
    );
    // Kontrolka jest nakładką `absolute` — bez tego uciekłaby na początek strony.
    t_true(
        (bool) preg_match('/\.tr-work__map\{[^}]*position:relative/', $css),
        'kontener mapy panelu ma position:relative'
    );
});

t_test('zgłoszenie skarbu: warstwy ze słownika + własna warstwa antyduplikatowa', function () {
    // Ten ekran ma DWA rodzaje warstw w jednej kontrolce i to jest cała
    // trudność Etapu 4: mgła/ślady/trasy rysuje wspólny silnik, a „Istniejące
    // skarby" — sam ekran, własną grupą Leafletu. Jeden przełącznik, dwa
    // adresaty.
    $ctrl = (string) file_get_contents(CORE_PATH . '/Controllers/TreasureProposalController.php');
    t_true(str_contains($ctrl, "'only'     => ['cells', 'heat', 'slady', 'trails']"), 'kontroler zawęża drzewo bez skarbów');
    t_true(str_contains($ctrl, "'mapLayers'     => \$mapLayers"), 'i podaje je obu wariantom szablonu');

    foreach (['treasure-propose-app.php', 'treasure-propose.php'] as $nazwa) {
        $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/' . $nazwa);

        t_true(
            (bool) preg_match('/\$mlLayers = array_merge\(\$mapLayers \?\? \[\]/', $widok),
            "$nazwa: kontrolka łączy drzewo ze słownika z własną warstwą ekranu"
        );
        t_true(
            str_contains($widok, 'mapa.ridemoreSetLayer(cb.dataset.layer, cb.checked)'),
            "$nazwa: warstwy ze słownika przełącza silnik"
        );
        t_true(
            str_contains($widok, 'mapa.addLayer(treasureLayer)'),
            "$nazwa: własna warstwa antyduplikatowa dalej obsługiwana przez ekran"
        );
        // `map: mapa` jest tu kluczowe — bez tego silnik zrobiłby DRUGĄ mapę
        // w tym samym kontenerze i pinezka zgłoszenia przestałaby działać.
        t_true(
            (bool) preg_match('/ridemoreDiscoveryMap\(document\.getElementById\(.zgloszenieMapa.\), \{\s*map: mapa,/s', $widok),
            "$nazwa: silnik dostaje ISTNIEJĄCĄ mapę, nie tworzy swojej"
        );
    }
});
