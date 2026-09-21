<?php
// backfill_known_route_surface.php
// Uzupełnia `known_routes.surface_*_pct` (migr. 086) dla tras wgranych PRZED tą
// migracją. Nowe trasy liczą nawierzchnię same, przy wgraniu pliku
// (`KnownRoute::createFromGpx` / `replaceGpx`) — ten skrypt jest wyłącznie
// dla zastanych.
//
// WOLNY Z NATURY, i to nie jest do naprawienia. Detekcja chodzi do Overpass API
// kawałek po kawałku, z CELOWYMI pauzami między zapytaniami (patrz
// Utils\RoadSurfaceDetector) — publiczny serwis OSM ma limity i wypada je
// szanować. Rachunek: kilkanaście–kilkadziesiąt sekund na trasę, więc
// katalog kilkudziesięciu tras to kilkanaście minut, a nie sekundy.
// Dlatego skrypt jest CLI, a nie akcją w panelu.
//
// IDEMPOTENTNY: bierze wyłącznie trasy z `surface_asphalt_pct IS NULL`, więc
// ponowne uruchomienie dolicza tylko to, co zostało. Przerwany w połowie
// (Ctrl+C, timeout, padnięty Overpass) zapisuje trasa po trasie, więc następny
// przebieg podejmuje pracę tam, gdzie ją zostawił — nic nie liczy dwa razy.
//
// Trasa, dla której Overpass nie odpowiedział, ZOSTAJE z NULL-ami i wejdzie do
// następnego przebiegu. To celowe: NULL znaczy „nie wiadomo" i strona trasy
// wtedy po prostu nie pokazuje paska. Zapisanie zer na wszelki wypadek byłoby
// wpisaniem do bazy nieprawdy („trasa bez asfaltu, bez gravelu i bez ścieżek").
//
// UŻYCIE:
//   php backfill_known_route_surface.php            — wszystkie bez nawierzchni
//   php backfill_known_route_surface.php --limit=5  — tylko N (do próby)
//   php backfill_known_route_surface.php --force    — także te już policzone
//
// Na produkcji uruchamiać PO run_migrations.php (kolumny muszą istnieć).
//
// APP_ENV=prod WYMUSZONE TUTAJ (2026-09-10) — CLI nie przechodzi przez Apache,
// więc `SetEnv APP_ENV prod` w .htaccess tu nie działa i bez tej linii
// bootstrap spadłby na 'dev', czyli łączyłby się z bazą deweloperską. Ten sam
// wzorzec co w pozostałych backfillach i w run_migrations.php.
putenv('APP_ENV=prod');

require __DIR__ . '/core/bootstrap.php';

use Core\Database;
use Utils\Gpx;
use Utils\RoadSurfaceDetector;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tylko z linii poleceń.\n");
}

$force = in_array('--force', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$db = Database::connection();

$sql = 'SELECT id, slug, name, gpx_url, distance_km
          FROM known_routes
         WHERE gpx_url IS NOT NULL AND gpx_url <> ""'
    . ($force ? '' : ' AND surface_asphalt_pct IS NULL')
    . ' ORDER BY id'
    . ($limit > 0 ? ' LIMIT ' . $limit : '');

$trasy = $db->query($sql)->fetchAll();
if (!$trasy) {
    echo "Nie ma czego liczyć — każda trasa ma już nawierzchnię.\n";
    exit(0);
}

echo 'Tras do policzenia: ' . count($trasy) . "\n";
echo "Overpass jest wolny z założenia — licz kilkanaście–kilkadziesiąt sekund na trasę.\n\n";

$zapis = $db->prepare('
    UPDATE known_routes
       SET surface_asphalt_pct = :asfalt,
           surface_gravel_pct  = :gravel,
           surface_trail_pct   = :sciezka
     WHERE id = :id
');

$ok = 0;
$pominiete = 0;

foreach ($trasy as $i => $trasa) {
    $numer = ($i + 1) . '/' . count($trasy);
    printf('%-8s %-40s ', $numer, mb_strimwidth((string) $trasa['name'], 0, 38, '…'));

    // Ścieżka do pliku tak samo jak w panelu (`KnownRouteController::gpxPath`):
    // w bazie jest adres publiczny, na dysku ten sam plik pod katalogiem aplikacji.
    $sciezkaPliku = __DIR__ . '/' . ltrim((string) $trasa['gpx_url'], '/');
    if (!is_file($sciezkaPliku)) {
        echo "BRAK PLIKU ({$trasa['gpx_url']})\n";
        $pominiete++;
        continue;
    }

    try {
        // Ten sam próg długości co przy wgraniu znanej trasy — szlak, nie przejazd.
        $parsed = Gpx::parse($sciezkaPliku, Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
        $surface = RoadSurfaceDetector::analyze($parsed['points'], (float) $parsed['distanceKm']);
    } catch (\Throwable $e) {
        echo 'BŁĄD: ' . $e->getMessage() . "\n";
        $pominiete++;
        continue;
    }

    if (!is_array($surface) || !isset($surface['asphaltPct'])) {
        echo "bez wyniku (Overpass milczy) — zostaje na następny przebieg\n";
        $pominiete++;
        continue;
    }

    $zapis->execute([
        'asfalt'  => (int) $surface['asphaltPct'],
        'gravel'  => (int) $surface['gravelPct'],
        'sciezka' => (int) $surface['trailPct'],
        'id'      => (int) $trasa['id'],
    ]);
    $ok++;

    printf("asfalt %d%% · gravel %d%% · ścieżka %d%%\n",
        $surface['asphaltPct'], $surface['gravelPct'], $surface['trailPct']);
}

echo "\nPoliczone: $ok · pominięte: $pominiete\n";
if ($pominiete > 0) {
    echo "Pominięte zostają z NULL-ami — uruchom skrypt ponownie, żeby spróbować dla nich jeszcze raz.\n";
}
