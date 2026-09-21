<?php
// tests/kolory_sladow_test.php
// KOLORY ŚLADÓW NA KAFLU (migr. 073, 2026-08-27).
//
// Zgłoszenie usera: „wszystkie te trasy są wygenerowane w kolorze zielonym,
// niezależnie od tego czy to solo przejazdy czy referencyjne (…) w pierwotnym
// założeniu, które zostało gdzieś przypadkiem usunięte z aplikacji, było, że
// jakiekolwiek ślady tras miały mieć różne kolory (…) mam jedną wielką zieloną
// plamę".
//
// TE TESTY ISTNIEJĄ, ŻEBY TO ZAŁOŻENIE NIE ZNIKNĘŁO PO RAZ DRUGI. Kolory per
// ślad są łatwe do zgubienia po cichu: wystarczy, że ktoś „uprości"
// `TileSource::tracks()` z powrotem do jednej grupy z workiem hashy i wszystko
// dalej działa — mapa po prostu znowu robi się jednolita. Żaden istniejący test
// by tego nie złapał, bo kafel dalej się renderuje i dalej jest PNG-iem.
//
// Dlatego pierwszy test jest testem TAMTEJ regresji: dwa ślady w tym samym
// miejscu MUSZĄ wyjść z dwoma różnymi kolorami.
//
// WYJĄTEK OD 2026-08-28: klucz `all` (mapa społeczności) świadomie WRÓCIŁ do
// jednej grupy — patrz sekcja „Grupy na kaflu" niżej i `STYLES['heat']` w
// `TileSource`. To NIE jest nawrót błędu sprzed migracji 073: różni go niska
// krycie (`alpha` 0.25 zamiast 1.0), która sprawia, że nakładające się ślady
// SAME zaciemniają popularną drogę zamiast dawać jednolitą, w pełni kryjącą
// plamę. Guard na TĘ różnicę jest w sekcji „Heatmapa społeczności" niżej —
// dopilnuj, żeby ktoś, kto kiedyś znów „uprości" `all`, zrobił to z powrotem
// do `heat`, a nie do `real`.

use Models\GpxGeometry;
use Models\TileSource;
use Utils\TrackPalette;

/**
 * Plik GPX z prostą linią, zapisany w katalogu uploadów pod losową nazwą.
 * Kładziemy je w pustkowiu (54,8 N / 17,9 E) z tego samego powodu co testy
 * znanych tras: żeby nie mieszać się z prawdziwymi śladami z bazy DEV.
 *
 * @return array{path:string,url:string,hash:string}
 */
function ks_track(float $lat = 54.80, float $lon = 17.90, int $n = 40): array
{
    // `xmlns` JEST WYMAGANY — `Utils\Gpx::parse()` szuka punktów po przestrzeni
    // nazw GPX i bez niej oddaje „Brak punktów trasy w pliku GPX", a wtedy
    // `GpxGeometry::ensure()` zapisuje PUSTĄ geometrię (i słusznie nie
    // przydziela jej koloru — nie ma czego malować). Ten sam nagłówek co
    // w `kr_gpx_file()` w tests/znane_trasy_test.php.
    $xml = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>';
    for ($i = 0; $i < $n; $i++) {
        $xml .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>',
            $lat + $i * 0.0015, $lon + $i * 0.0015);
    }
    $xml .= '</trkseg></trk></gpx>';

    $url = Utils\Upload::saveGpxContents($xml);
    if ($url === null) {
        t_fail('Nie udało się zapisać testowego pliku GPX.');
    }
    $path = CORE_PATH . '/..' . $url;

    return ['path' => $path, 'url' => $url, 'hash' => (string) GpxGeometry::ensure($path)];
}

/** Sprząta pliki testowe razem z ich geometrią — inaczej zostają w indeksie kafli. */
function ks_cleanup(array ...$tracks): void
{
    $db = Core\Database::connection();
    foreach ($tracks as $t) {
        @unlink($t['path']);
        $db->prepare('DELETE FROM gpx_tiles WHERE gpx_hash = :h')->execute(['h' => $t['hash']]);
        $db->prepare('DELETE FROM gpx_geometry WHERE gpx_hash = :h')->execute(['h' => $t['hash']]);
    }
}

// --- Paleta ----------------------------------------------------------

t_test('paleta: kolor WYBORU nie jest w palecie śladów', function () {
    // Prośba usera wprost: „jeden kolor powinien być przeznaczony dla
    // wybranego". Gdyby `SELECTED` trafił do palety, zwykły przejazd
    // wyglądałby jak kliknięty — i to jest dokładnie ten błąd, który
    // istniał przed migracją 073 (indeks 1 był niebieski `#1F6FB2`,
    // podświetlenie rysuje `#2B57C8`).
    t_false(in_array(TrackPalette::SELECTED, TrackPalette::COLORS, true),
        'zarezerwowany kolor wyboru NIE występuje w palecie');
});

t_test('paleta: żaden kolor nie powtarza się i wszystkie są poprawnym HEX-em', function () {
    $kolory = TrackPalette::COLORS;
    t_count(count($kolory), array_unique($kolory), 'brak duplikatów w palecie');
    t_true(count($kolory) >= 4, 'paleta ma co najmniej cztery kolory (jest: ' . count($kolory) . ')');
    foreach ($kolory as $i => $hex) {
        t_true(preg_match('/^#[0-9A-F]{6}$/', $hex) === 1, 'indeks ' . $i . ' to poprawny HEX: ' . $hex);
    }
});

t_test('paleta: brak przydziału NIE psuje mapy, tylko jej nie poprawia', function () {
    // NULL to stan każdego śladu sprzed backfillu. Musi dawać kolor bazowy —
    // czyli dokładnie to, co serwis rysował przed migracją 073.
    t_same(TrackPalette::COLORS[0], TrackPalette::colorOf(null), 'null = kolor bazowy');
    t_same(TrackPalette::COLORS[0], TrackPalette::colorOf(''), 'pusty string = kolor bazowy');
    // Indeks spoza zakresu zawija się, zamiast wywalać się na brakującym kluczu
    // — paleta może się kiedyś skrócić, a w bazie zostaną stare numery.
    t_same(TrackPalette::COLORS[1], TrackPalette::colorOf(count(TrackPalette::COLORS) + 1),
        'indeks spoza palety zawija się, nie wywraca');
});

t_test('paleta: znane trasy i ślady malują się z TEJ SAMEJ bazy kolorów', function () {
    // User poprosił o JEDNĄ „bazę kolorów przeznaczonych na generowanie tras".
    // Gdyby `KnownRoute` miało własną kopię, dwie palety rozjechałyby się przy
    // pierwszej zmianie — i znowu dałoby się dostać ślad w kolorze wyboru.
    t_same(TrackPalette::COLORS, Models\KnownRoute::COLORS, 'KnownRoute::COLORS to ta sama paleta');
    t_same(TrackPalette::colorOf(3), Models\KnownRoute::colorOf(3), 'i ta sama zamiana indeksu na HEX');
});

// --- Przydział koloru ------------------------------------------------

t_test('dwa ślady W TYM SAMYM MIEJSCU dostają RÓŻNE kolory', function () {
    // TO JEST TEST TEJ REGRESJI. Przed migracją 073 oba wyszłyby zielone,
    // bo kolor brał się ze stylu, a nie ze śladu.
    $a = ks_track(54.80, 17.90);
    $b = ks_track(54.8005, 17.9005);   // ten sam korytarz, kilkadziesiąt metrów obok

    $kolory = GpxGeometry::colorsFor([$a['hash'], $b['hash']]);
    ks_cleanup($a, $b);

    t_count(2, $kolory, 'oba ślady mają przydzielony kolor');
    t_true($kolory[$a['hash']] !== $kolory[$b['hash']],
        'nakładające się ślady mają RÓŻNE kolory (a: ' . $kolory[$a['hash']]
        . ', b: ' . $kolory[$b['hash']] . ')');
});

t_test('kolor przydziela się SAM przy liczeniu geometrii, bez osobnego kroku', function () {
    // Gdyby przydział wisiał wyłącznie na backfillu, każdy nowo wgrany ślad
    // wpadałby do wspólnej plamy do czasu, aż ktoś kliknie przycisk w panelu.
    $t = ks_track(54.90, 18.10);
    $kolory = GpxGeometry::colorsFor([$t['hash']]);
    ks_cleanup($t);

    t_count(1, $kolory, 'świeżo policzona geometria ma już kolor');
    t_true($kolory[$t['hash']] >= 0 && $kolory[$t['hash']] < count(TrackPalette::COLORS),
        'i jest to poprawny indeks palety');
});

t_test('ślad DALEKO od innych może wziąć kolor bazowy — sąsiedztwo, nie licznik', function () {
    // Przydział ma unikać KOLIZJI, a nie rozdawać kolejne numery każdemu.
    // Ślad w pustce nie ma z czym kolidować, więc wolno mu dostać cokolwiek —
    // sprawdzamy, że w ogóle dostaje i że to poprawny indeks.
    $t = ks_track(53.10, 15.20);
    $kolory = GpxGeometry::colorsFor([$t['hash']]);
    ks_cleanup($t);

    t_count(1, $kolory, 'samotny ślad też dostaje kolor');
});

t_test('przydział NIE przemalowuje sąsiadów — kafle nie lecą lawinowo', function () {
    // Sedno rozwiązania, przejęte z KnownRoute::assignColor: kafle są plikami
    // na dysku unieważnianymi po ŚLADZIE, więc przemalowanie sąsiada
    // wymagałoby skasowania kafli także na JEGO przebiegu, a sąsiada sąsiada
    // — lawinowo dalej. Nowy ślad ma się dopasować do tego, co już leży,
    // a nie kazać wszystkim się przesunąć.
    $a = ks_track(54.70, 17.70);
    $przed = GpxGeometry::colorsFor([$a['hash']])[$a['hash']];

    $b = ks_track(54.7005, 17.7005);
    $po = GpxGeometry::colorsFor([$a['hash']])[$a['hash']];

    ks_cleanup($a, $b);

    t_same($przed, $po, 'kolor śladu, który już był narysowany, nie zmienia się');
});

t_test('backfill jest IDEMPOTENTNY — drugi przebieg nic nie maluje', function () {
    // Powtórne uruchomienie nie ma prawa unieważnić kafli, których nikt nie
    // kazał unieważniać (ta sama zasada co przy backfillu znanych tras).
    $t = ks_track(52.30, 19.40);
    $wynik = GpxGeometry::backfillColors();
    ks_cleanup($t);

    t_same(0, $wynik['colored'], 'nie było czego kolorować — wszystko ma już kolor');
});

// --- Grupy na kaflu --------------------------------------------------

t_test('kafel: każdy ślad idzie OSOBNĄ grupą ze swoim kolorem', function () {
    // Regresja na kształt tego, co `TileSource` oddaje rendererowi. Do migracji
    // 073 gałęzie `all`/`me`/`e-` zwracały JEDNĄ grupę z workiem hashy —
    // i to była bezpośrednia przyczyna zielonej plamy.
    $userId = t_user();
    $a = ks_track(54.60, 17.50);
    $b = ks_track(54.6005, 17.5005);

    $db = Core\Database::connection();
    $stmt = $db->prepare(
        'INSERT INTO rider_activities (user_id, source_code, ride_date, distance_km, gpx_url, gpx_hash)
         VALUES (:u, :s, "2026-01-01", 10, :url, :h)'
    );
    foreach ([$a, $b] as $t) {
        $stmt->execute(['u' => $userId, 's' => \Models\RiderActivity::SOURCE_SOLO,
                        'url' => $t['url'], 'h' => $t['hash']]);
    }

    $_SESSION['user_id'] = $userId;
    $ref = new ReflectionClass(Core\Auth::class);
    foreach (['resolved' => false, 'cachedUser' => null] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }

    try {
        $moje = [];
        foreach (TileSource::tracks('me') as $grupa) {
            foreach ($grupa['hashes'] as $hash) {
                if ($hash === $a['hash'] || $hash === $b['hash']) {
                    $moje[$hash] = $grupa['color'] ?? null;
                    t_count(1, $grupa['hashes'], 'ślad jedzie SAM w swojej grupie, nie w worku');
                }
            }
        }
    } finally {
        unset($_SESSION['user_id']);
        foreach (['resolved' => false, 'cachedUser' => null] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
        ks_cleanup($a, $b);
    }

    t_count(2, $moje, 'oba ślady trafiły na własną mapę');
    t_not_null($moje[$a['hash']], 'grupa niesie kolor, a nie zdaje się na styl');
    t_count(2, array_unique(array_values($moje)), 'i są to DWA RÓŻNE kolory');
});

t_test('kafel: `all` (społeczność) niesie WYŁĄCZNIE grupy stylu `heat`, bez koloru z palety', function () {
    // TO JEST TEST NOWEJ REGRESJI (2026-08-28) — lustrzane odbicie testu `me`
    // wyżej. Ktoś, kto kiedyś "poprawi" `all` z powrotem na osobną grupę na
    // ślad, cofnie decyzję o heatmapie społeczności bez świadomości, że to
    // robi — a to jest DOKŁADNIE ten sam rodzaj cichej regresji, przed którą
    // ostrzega nagłówek tego pliku.
    //
    // NIE „dokładnie jedna grupa" (jak przed migr. 076) — od solo w heatmapie
    // `all` może zwrócić DWIE grupy (wyjazdy + solo, źródło `trimmed`), jeśli
    // baza DEV ma choć jeden przejazd solo. Sedno regresji nie jest w LICZBIE
    // grup, tylko w tym, że ŻADNA z nich nie ma pełnego krycia ani koloru
    // z palety — to sprawdzamy wprost, niezależnie od tego, ile grup wyszło.
    $a = ks_track(54.62, 17.52);

    $db = Core\Database::connection();
    $eventId = $db->query('SELECT id FROM events LIMIT 1')->fetchColumn();
    $editionId = $db->query('SELECT id FROM event_editions LIMIT 1')->fetchColumn();
    t_true($eventId !== false && $editionId !== false, 'fixture ma przynajmniej jedno wydarzenie/edycję');

    $db->prepare('INSERT INTO edition_tracks (edition_id, gpx_url) VALUES (:e, :u)')
        ->execute(['e' => $editionId, 'u' => $a['url']]);

    try {
        $grupy = TileSource::tracks('all');
    } finally {
        $db->prepare('DELETE FROM edition_tracks WHERE gpx_url = :u')->execute(['u' => $a['url']]);
        ks_cleanup($a);
    }

    t_true(count($grupy) >= 1, '`all` oddaje przynajmniej jedną grupę (świeżo dodany ślad wyjazdu)');
    $mojaGrupa = null;
    foreach ($grupy as $g) {
        t_same('heat', $g['style'], 'KAŻDA grupa `all` niesie styl `heat`, nigdy `real`');
        t_false(isset($g['color']), 'grupa NIE nadpisuje koloru — heatmapa maluje się stylem, nie paletą');
        if (in_array($a['hash'], $g['hashes'], true)) {
            $mojaGrupa = $g;
        }
    }
    t_not_null($mojaGrupa, 'świeżo dodany ślad wyjazdu trafia do którejś z grup `all`');
    t_same('normal', $mojaGrupa['source'] ?? 'normal', 'ślad WYJAZDU czyta z normalnej geometrii, nie z przyciętej');
});

t_test('styl `heat`: niska krycie, żeby nakładanie się śladów SAMO rysowało natężenie', function () {
    // Bez tego `all` różniłoby się od stylu sprzed migracji 073 tylko nazwą —
    // sedno heatmapy jest w tym, że pojedynczy przejazd jest ledwo widoczny,
    // a wiele nakładających się PRZYCIEMNIA drogę.
    $heat = TileSource::STYLES['heat'];
    t_true($heat['alpha'] > 0.0 && $heat['alpha'] < 0.3,
        'krycie jest niskie, ale nie zerowe: ' . $heat['alpha']);
    t_true($heat['alpha'] < TileSource::STYLES['real']['alpha'],
        'wyraźnie mniejsze krycie niż pełny styl `real` używany na mapach osobistych');
    t_false(!empty($heat['casing'] ?? null), 'heatmapa nie ma obwódki — to nie jest "referencja"');
});

t_test('kafel: trasy ZAPOWIADANE zostają jedną szarą grupą', function () {
    // ŚWIADOMA decyzja, nie przeoczenie: szarość stylu `planned` nie jest
    // „kolorem tej trasy", tylko komunikatem „to zapowiedź, której nikt nie
    // potwierdził śladem". Rozbicie ich na paletę zabrałoby tej warstwie całe
    // znaczenie — wyglądałyby jak przejazdy, które się odbyły.
    $styl = TileSource::STYLES['planned'];
    t_true(!empty($styl['dash']), 'zapowiedź jest przerywana');
    t_true($styl['alpha'] < 1.0, 'i wyblakła — mówi o BRAKU, nie o dokonaniu');
    t_false(in_array($styl['color'], TrackPalette::COLORS, true),
        'jej szarość NIE jest kolorem z palety śladów');
});

t_test('renderer dostaje HEX-a z palety, nie surowy indeks z bazy (kontekst `me`)', function () {
    // Bezpiecznik na kontrakt między modelem a rendererem: `TileRenderer`
    // rozkłada kolor na składowe RGB, więc liczba zamiast `#RRGGBB` dałaby
    // czarną linię zamiast błędu. Sprawdzane na `me`, bo od 2026-08-28 to
    // WŁAŚNIE tu (nie na `all`) grupy niosą kolor z palety — patrz niżej.
    $userId = t_user();
    $a = ks_track(54.50, 17.30);
    $b = ks_track(54.5005, 17.3005);

    $db = Core\Database::connection();
    $stmt = $db->prepare(
        'INSERT INTO rider_activities (user_id, source_code, ride_date, distance_km, gpx_url, gpx_hash)
         VALUES (:u, :s, "2026-01-01", 10, :url, :h)'
    );
    foreach ([$a, $b] as $t) {
        $stmt->execute(['u' => $userId, 's' => \Models\RiderActivity::SOURCE_SOLO,
                        'url' => $t['url'], 'h' => $t['hash']]);
    }

    $_SESSION['user_id'] = $userId;
    $ref = new ReflectionClass(Core\Auth::class);
    foreach (['resolved' => false, 'cachedUser' => null] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }

    $znalezione = [];
    try {
        foreach (TileSource::tracks('me') as $grupa) {
            if (isset($grupa['color'])) {
                $znalezione[] = $grupa['color'];
            }
        }
    } finally {
        unset($_SESSION['user_id']);
        foreach (['resolved' => false, 'cachedUser' => null] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
        ks_cleanup($a, $b);
    }

    t_true(count($znalezione) >= 2, 'oba świeże ślady wniosły kolor grupy');
    foreach ($znalezione as $hex) {
        t_true(in_array($hex, TrackPalette::COLORS, true), 'kolor grupy pochodzi z palety: ' . $hex);
    }
});
