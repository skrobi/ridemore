<?php
// tests/heatmapa_spolecznosci_test.php
// HEATMAPA SPOŁECZNOŚCI: SOLO PRZEZ GEOMETRIĘ PRZYCIĘTĄ (migr. 076, 2026-08-28).
//
// Zgłoszenie usera: był przekonany, że warstwa „Ślady"/heatmapa na mapie
// społeczności liczy WSZYSTKIE przejazdy — pola odkryć („Odkrycia") liczą
// wszystkie, a „Ślady" tylko `edition_tracks`, więc odkryte pole nie miało
// pod sobą śladu. Naprawa NIE mogła być prostym dorzuceniem
// `rider_activities.gpx_url` do `TileSource::tracks('all')` (tak jak było
// przez jeden dzień w 2026-08-26 i zostało cofnięte) — plik solo jest surowy
// i zaczyna/kończy się pod domem, a klucz `all` leży publicznie na dysku pod
// adresem do zgadnięcia.
//
// TE TESTY PILNUJĄ MECHANIZMU „PRZYCIĘTA GEOMETRIA", nie samego faktu, że
// solo jest w `all` (to pilnuje `tests/przejazdy_solo_test.php`). Sedno: jeśli
// ktoś kiedyś „uprości" `GpxGeometry::ensureTrimmed()`/`ensureTrimmedFromPoints()`
// tak, że zaczną zapisywać PEŁNE punkty zamiast przyciętych, żaden test
// wysokopoziomowy tego nie złapie — kafel dalej się narysuje, dalej będzie
// PNG-iem, tylko znów będzie mapą czyichś adresów.

use Models\GpxGeometry;
use Models\RiderActivity;
use Utils\DiscoveryGrid;

/** Plik GPX prostej linii na pustkowiu — start/koniec dają się jednoznacznie przyciąć. */
function hs_track(float $lat = 55.10, float $lon = 18.40, int $n = 80): array
{
    $xml = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>';
    for ($i = 0; $i < $n; $i++) {
        // ~111 m na krok (0,001° szerokości) — 80 punktów daje ~8,9 km,
        // z zapasem ponad promień domowy (dev: 400 m) na obu końcach.
        $xml .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', $lat + $i * 0.001, $lon);
    }
    $xml .= '</trkseg></trk></gpx>';

    $url = Utils\Upload::saveGpxContents($xml);
    if ($url === null) {
        t_fail('Nie udało się zapisać testowego pliku GPX.');
    }
    return ['path' => CORE_PATH . '/..' . $url, 'url' => $url];
}

/** Sprząta plik i OBIE geometrie (przyciętą i pełną, jeśli powstała) po hashu. */
function hs_cleanup(string $hash, string $path): void
{
    @unlink($path);
    $db = Core\Database::connection();
    foreach (['gpx_tiles_trimmed', 'gpx_geometry_trimmed', 'gpx_tiles', 'gpx_geometry'] as $t) {
        $db->prepare("DELETE FROM $t WHERE gpx_hash = :h")->execute(['h' => $hash]);
    }
}

t_test('ensureTrimmed: geometria przycięta ma MNIEJ punktów niż pełny plik', function () {
    $t = hs_track();
    $hash = hash_file('sha256', $t['path']);

    $wynik = GpxGeometry::ensureTrimmed($t['path']);
    $pelnaPunktow = count(Utils\Gpx::parse($t['path'])['points']);
    $przycieta = GpxGeometry::loadTrimmed([$hash])[$hash] ?? null;

    hs_cleanup($hash, $t['path']);

    t_same($hash, $wynik, 'zwraca hash pliku');
    t_not_null($przycieta, 'przycięta geometria jest zapisana');
    t_true(count($przycieta['pts']) / 2 < $pelnaPunktow,
        'mniej punktów niż w pełnym pliku (pełny: ' . $pelnaPunktow
        . ', przycięty: ' . (count($przycieta['pts']) / 2) . ')');
});

t_test('ensureTrimmed jest IDEMPOTENTNY — drugie wywołanie nie liczy niczego ponownie', function () {
    $t = hs_track();
    $hash = hash_file('sha256', $t['path']);

    GpxGeometry::ensureTrimmed($t['path']);
    $przed = GpxGeometry::loadTrimmed([$hash])[$hash];
    GpxGeometry::ensureTrimmed($t['path']);
    $po = GpxGeometry::loadTrimmed([$hash])[$hash];

    hs_cleanup($hash, $t['path']);

    t_same($przed['pts'], $po['pts'], 'ten sam ślad przed i po drugim wywołaniu');
});

t_test('ensureTrimmedFromPoints: zero parsowania pliku — bierze gotowe, już przycięte punkty', function () {
    // To jest DOKŁADNIE ścieżka wołana z RiderActivity::recordSolo — punkty
    // przychodzą JUŻ po DiscoveryGrid::trimEnds, ta metoda ma je tylko zapisać.
    $t = hs_track();
    $hash = hash_file('sha256', $t['path']);
    $pelne = Utils\Gpx::parse($t['path'])['points'];
    $recznie = DiscoveryGrid::trimEnds($pelne, \Models\DiscoveryScoring::homeTrimRadiusM());

    GpxGeometry::ensureTrimmedFromPoints($hash, $recznie);
    $zapisane = GpxGeometry::loadTrimmed([$hash])[$hash] ?? null;

    hs_cleanup($hash, $t['path']);

    t_not_null($zapisane, 'geometria zapisana z gotowych punktów');
    t_same(count($recznie), count($zapisane['pts']) / 2,
        'liczba punktów zgadza się z tym, co przyszło z DiscoveryGrid::trimEnds');
});

t_test('inTileTrimmed: kafel na trasie widzi hash, kafel z dala od trasy — nie', function () {
    $t = hs_track();
    $hash = hash_file('sha256', $t['path']);
    GpxGeometry::ensureTrimmed($t['path']);

    // ŚRODEK trasy, NIE punkt startowy — start leży w promieniu domowym
    // i właśnie dlatego znika z geometrii przyciętej (patrz test wyżej).
    // Kafel na starcie sprawdzałby więc dokładnie to, czego przycięcie
    // ma NIE pokazywać, a to jest inny test (prywatność), nie ten.
    $z = \Utils\TileGrid::INDEX_Z;
    [$tx, $ty] = \Utils\TileGrid::tileOf(55.10 + 0.040, 18.40, $z);

    $naTrasie = GpxGeometry::inTileTrimmed([$hash], $z, $tx, $ty);
    $zDala = GpxGeometry::inTileTrimmed([$hash], $z, $tx + 500, $ty + 500);

    hs_cleanup($hash, $t['path']);

    t_true(in_array($hash, $naTrasie, true), 'kafel leżący na trasie widzi hash');
    t_count(0, $zDala, 'kafel setki pól dalej — pusto');
});

t_test('backfillAllTrimmed + missingTrimmedCount: policzy solo, które go jeszcze nie ma', function () {
    $userId = t_user();
    $t = hs_track(55.20, 18.50);
    $hash = hash_file('sha256', $t['path']);

    Core\Database::connection()->prepare(
        'INSERT INTO rider_activities (user_id, source_code, ride_date, distance_km, gpx_url, gpx_hash)
         VALUES (:u, :s, "2026-01-01", 8.9, :url, :h)'
    )->execute(['u' => $userId, 's' => RiderActivity::SOURCE_SOLO, 'url' => $t['url'], 'h' => $hash]);

    // Świadomie NIE wołamy ensureTrimmed — symulujemy solo wgrane PRZED migr. 076.
    $brakPrzed = GpxGeometry::missingTrimmedCount();
    t_true($brakPrzed >= 1, 'przynajmniej ten jeden solo czeka na przycięcie');

    $wynik = GpxGeometry::backfillAllTrimmed();
    $brakPo = GpxGeometry::missingTrimmedCount();
    $maTeraz = GpxGeometry::hasTrimmed($hash);

    hs_cleanup($hash, $t['path']);
    // rider_activities sprząta transakcja testu (rollback w runnerze).

    t_true($wynik['policzone'] >= 1, 'backfill policzył przynajmniej ten jeden solo');
    t_true($brakPo < $brakPrzed, 'licznik brakujących spadł');
    t_true($maTeraz, 'ten konkretny solo ma teraz przyciętą geometrię');
});
