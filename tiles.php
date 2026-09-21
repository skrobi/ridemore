<?php
// tiles.php
// NARZĘDZIE CLI DO KAFLI (migr. 051) — backfill geometrii, statystyki, sprzątanie.
//
// UŻYCIE:
//   php tiles.php stats              — co leży na dysku i ile tego jest
//   php tiles.php backfill           — policz geometrię dla plików bez niej (pełną i,
//                                       migr. 076, przyciętą solo pod heatmapę społeczności)
//   php tiles.php colors             — przydziel kolory śladom bez koloru (migr. 073)
//   php tiles.php repair             — dopisz brakujące wiersze gpx_tiles śladom, które
//                                       mają geometrię, ale niekompletny indeks kafli
//                                       (naprawa STARYCH szkód po wyścigu sprzed
//                                       transakcyjnego zapisu w ensure()/storeTrimmed(),
//                                       2026-09-07 — patrz komentarz tam)
//   php tiles.php prune              — przytnij cache do limitu (najdawniej używane)
//   php tiles.php purge [warstwa]    — skasuj WSZYSTKIE kafle (geometria zostaje)
//
// Wszystko jest IDEMPOTENTNE i można puszczać wielokrotnie. Backfill pomija
// pliki już policzone, więc drugie uruchomienie kosztuje jedno zapytanie na plik.
//
// Kafle są w pełni odtwarzalne — `purge` nigdy nie niszczy danych, tylko każe
// wygenerować obrazki od nowa przy następnym wejściu. To jest właściwa reakcja
// na zmianę koloru, grubości linii albo progów skali.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tylko z linii poleceń.\n");
}

require __DIR__ . '/core/bootstrap.php';

use Core\Database;
use Models\GpxGeometry;
use Models\TileCache;
use Models\TileSource;

$command = $argv[1] ?? 'stats';
$db = Database::connection();

// Lista plików GPX i sam backfill mieszkają w Models\GpxGeometry — od 2026-08-20
// to samo robi przycisk w panelu `/admin/kafle`, a dwie kopie tej samej pętli
// rozjechałyby się przy pierwszej nowej tabeli ze śladami.

if ($command === 'stats') {
    $s = TileCache::stats();
    printf("KAFLE NA DYSKU\n");
    printf("  plików:    %s / %s (limit)\n", number_format($s['tiles'], 0, ',', ' '), number_format($s['limit'], 0, ',', ' '));
    printf("  rozmiar:   %.1f MB\n", $s['bytes'] / 1048576);
    printf("  kluczy:    %d\n", $s['keys']);
    if ($s['tiles'] > 0) {
        printf("  średnio:   %d B na kafel\n", (int) ($s['bytes'] / $s['tiles']));
    }

    $geo = $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(point_count), 0) AS p,
                              COALESCE(SUM(LENGTH(points)), 0) AS b FROM gpx_geometry')->fetch();
    $tiles = (int) $db->query('SELECT COUNT(*) FROM gpx_tiles')->fetchColumn();
    printf("\nGEOMETRIA ŚLADÓW\n");
    printf("  plików:    %d\n", $geo['n']);
    printf("  punktów:   %s\n", number_format((int) $geo['p'], 0, ',', ' '));
    printf("  rozmiar:   %.1f MB\n", $geo['b'] / 1048576);
    printf("  wierszy indeksu kafli: %s\n", number_format($tiles, 0, ',', ' '));

    $brak = GpxGeometry::missingCount();
    printf("  BEZ policzonej geometrii: %d %s\n", $brak, $brak > 0 ? '(uruchom: php tiles.php backfill albo przycisk w /admin/kafle)' : '');

    // Solo w heatmapie społeczności (migr. 076, 2026-08-28) — geometria
    // PRZYCIĘTA (§27), osobna para tabel, osobny licznik: plik może mieć
    // pełną geometrię (bo wszedł np. jako ślad wyjazdu) i wciąż czekać na
    // przyciętą wersję pod klucz `all`, więc to NIE jest podzbiór licznika wyżej.
    $brakTrim = GpxGeometry::missingTrimmedCount();
    printf("  Solo BEZ przyciętej geometrii (heatmapa): %d %s\n", $brakTrim,
        $brakTrim > 0 ? '(uruchom: php tiles.php backfill)' : '');

    $epoki = $db->query('SELECT layer, cache_key, epoch FROM tile_epochs ORDER BY layer, cache_key')->fetchAll();
    if ($epoki) {
        printf("\nEPOKI (doklejane jako ?v= do adresów)\n");
        foreach ($epoki as $e) {
            printf("  %-6s %-30s v=%d\n", $e['layer'], $e['cache_key'], $e['epoch']);
        }
    }
    exit(0);
}

if ($command === 'backfill') {
    printf("Plików GPX w bazie: %d\n", count(GpxGeometry::allGpxUrls()));
    $t0 = microtime(true);
    $wynik = GpxGeometry::backfillAll(static function (string $m): void {
        echo str_starts_with($m, 'BRAK') ? "\n" . $m . "\n" : '.';
    });
    printf("\nPoliczone teraz: %d, już były: %d, brak pliku na dysku: %d (%.1f s)\n",
        $wynik['policzone'], $wynik['juz_byly'], $wynik['brak_pliku'], microtime(true) - $t0);

    // Druga geometria: solo PRZYCIĘTA pod heatmapę społeczności (migr. 076).
    // Osobne wywołanie, nie ta sama pętla — inne źródło hashy (WYŁĄCZNIE solo)
    // i inna para tabel, patrz GpxGeometry::backfillAllTrimmed.
    $t0 = microtime(true);
    $wynikTrim = GpxGeometry::backfillAllTrimmed(static function (string $m): void {
        echo str_starts_with($m, 'BRAK') ? "\n" . $m . "\n" : '.';
    });
    printf("\nSolo przycięte teraz: %d, już były: %d, brak pliku na dysku: %d (%.1f s)\n",
        $wynikTrim['policzone'], $wynikTrim['juz_byly'], $wynikTrim['brak_pliku'], microtime(true) - $t0);
    exit(0);
}

if ($command === 'colors') {
    // PRZYDZIAŁ KOLORÓW ŚLADOM (migr. 073). Osobne polecenie od `backfill`,
    // bo to dwie różne rzeczy: tamto LICZY geometrię plików, które jej nie
    // mają, a to maluje ślady, które geometrię już mają. Idempotentne —
    // rusza wyłącznie ślady bez koloru.
    $t0 = microtime(true);
    $wynik = GpxGeometry::backfillColors(static function (string $m): void {
        echo '.';
    });
    printf("\nPokolorowane: %d, miały już kolor: %d (%.1f s)\n",
        $wynik['colored'], $wynik['skipped'], microtime(true) - $t0);

    // KAFLE MUSZĄ PÓJŚĆ RAZEM Z KOLORAMI — leżą na dysku z nagłówkiem
    // `immutable`, więc bez tego zmiana siedziałaby w bazie, a mapa dalej
    // pokazywałaby jedną zieloną plamę.
    if ($wynik['colored'] > 0) {
        $ile = TileCache::purgeLayer(TileSource::LAYER_TRACKS);
        printf("Skasowane kafle warstwy `%s`: %d kluczy — odbudują się z ruchu.\n",
            TileSource::LAYER_TRACKS, $ile);
    }
    exit(0);
}

if ($command === 'repair') {
    // NAPRAWA DANYCH, NIE TYLKO KODU — patrz komentarz przy
    // GpxGeometry::repairTileIndex(). Idempotentne: ślad z kompletnym
    // indeksem kosztuje jedno zapytanie i zero zapisów.
    $t0 = microtime(true);
    $wynik = GpxGeometry::repairTileIndex(static function (string $m): void {
        echo $m . "\n";
    });
    printf("Pełna geometria — sprawdzone: %d, naprawione: %d, dopisanych wierszy gpx_tiles: %d\n",
        $wynik['sprawdzone'], $wynik['naprawione'], $wynik['dopisanych_wierszy']);

    $wynikTrim = GpxGeometry::repairTrimmedTileIndex(static function (string $m): void {
        echo $m . "\n";
    });
    printf("Solo przycięte — sprawdzone: %d, naprawione: %d, dopisanych wierszy gpx_tiles_trimmed: %d (%.1f s)\n",
        $wynikTrim['sprawdzone'], $wynikTrim['naprawione'], $wynikTrim['dopisanych_wierszy'], microtime(true) - $t0);

    // KAFLE MUSZĄ PÓJŚĆ RAZEM Z NAPRAWĄ — stare, dziurawe PNG-i leżą na
    // dysku z `immutable` i naprawiony indeks im nie pomoże, dopóki ktoś
    // ich nie podmieni. Ten sam powód i ten sam wzorzec co w `colors` niżej.
    if ($wynik['naprawione'] > 0 || $wynikTrim['naprawione'] > 0) {
        $ile = TileCache::purgeLayer(TileSource::LAYER_TRACKS);
        printf("Skasowane kafle warstwy `%s`: %d kluczy — odbudują się z ruchu.\n",
            TileSource::LAYER_TRACKS, $ile);
    }
    exit(0);
}

if ($command === 'prune') {
    $t0 = microtime(true);
    $n = TileCache::pruneIfNeeded();
    printf("Wyrzucone kafle: %d (%.1f s)\n", $n, microtime(true) - $t0);
    exit(0);
}

if ($command === 'purge') {
    $layers = isset($argv[2]) ? [$argv[2]] : [TileSource::LAYER_TRACKS, TileSource::LAYER_HEX];
    foreach ($layers as $layer) {
        $keys = $db->prepare('SELECT DISTINCT cache_key FROM tile_cache WHERE layer = :l');
        $keys->execute(['l' => $layer]);
        $klucze = $keys->fetchAll(\PDO::FETCH_COLUMN);
        // Katalogi mogą istnieć także dla kluczy spoza rejestru (np. po awarii
        // zapisu), więc oprócz kluczy z bazy zamiatamy to, co leży na dysku.
        foreach (glob(TileCache::root() . "/$layer/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $klucze[] = basename($dir);
        }
        foreach (array_unique($klucze) as $key) {
            TileCache::purgeKey($layer, $key);
            echo "skasowane: $layer/$key\n";
        }
    }
    echo "Gotowe. Kafle odbudują się przy pierwszym wejściu na mapę.\n";
    exit(0);
}

exit("Nieznane polecenie: $command\nDostępne: stats, backfill, colors, repair, prune, purge\n");
