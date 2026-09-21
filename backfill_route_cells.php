<?php
// backfill_route_cells.php
// JEDNORAZOWY skrypt — Etap 2 (dopasowania), krok 1: dolicza event_stage_cells
// dla etapów zapisanych PRZED dodaniem tej tabeli (migration_025_route_cells.sql).
// Nowe zapisy dostają komórki od razu przy Models\EventStage::replaceForEvent(),
// ten skrypt tylko domyka historyczne dane.
//
// Bierze etapy z elevation_profile IS NOT NULL, które NIE mają jeszcze
// żadnego wiersza w event_stage_cells, i liczy komórki z już zapisanego
// profilu (z interpolacją między ~1,66km próbkami — patrz Utils\RouteCells).
// Etapy bez lat/lon w profilu (sprzed backfill_elevation_profiles.php)
// zostają pominięte — uruchom najpierw backfill_elevation_profiles.php.
//
// UŻYCIE (SSH, zalecane):
//   php backfill_route_cells.php
//
// UŻYCIE (bez SSH):
//   1. Wgraj ten plik do katalogu głównego strony.
//   2. Otwórz RAZ w przeglądarce: https://twoja-domena/backfill_route_cells.php
//   3. USUŃ plik z serwera zaraz po tym — bez autoryzacji, każdy kto zna URL
//      mógłby go odpalić ponownie (nieszkodliwie, ale to niepotrzebne obciążenie).

putenv('APP_ENV=prod');
require __DIR__ . '/core/bootstrap.php';

$pdo = Core\Database::connection();
$isCli = php_sapi_name() === 'cli';
$out = function (string $line) use ($isCli) {
    echo $line . ($isCli ? "\n" : "<br>\n");
};

if (!$isCli) {
    $out('<pre style="font-family:monospace;white-space:pre-wrap;">');
}

$out('APP_ENV=' . APP_ENV . ', baza=' . APP_CONFIG['db']['name']);
$out('---');

$rows = $pdo->query("
    SELECT es.id, es.elevation_profile
    FROM event_stages es
    WHERE es.elevation_profile IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM event_stage_cells esc WHERE esc.event_stage_id = es.id)
")->fetchAll();

$cellStmt = $pdo->prepare('INSERT IGNORE INTO event_stage_cells (event_stage_id, cell_x, cell_y) VALUES (:stage_id, :x, :y)');

$updated = 0;
$skippedNoCoords = 0;

foreach ($rows as $row) {
    $profile = json_decode($row['elevation_profile'], true);
    $cells = Utils\RouteCells::fromElevationProfile($profile);
    if (empty($cells)) {
        $out("POMINIĘTO stage id={$row['id']}: profil bez lat/lon (uruchom najpierw backfill_elevation_profiles.php)");
        $skippedNoCoords++;
        continue;
    }

    foreach ($cells as [$x, $y]) {
        $cellStmt->execute(['stage_id' => $row['id'], 'x' => $x, 'y' => $y]);
    }
    $out("OK stage id={$row['id']}: " . count($cells) . ' komórek');
    $updated++;
}

$out('---');
$out("Zaktualizowano: {$updated}, pominięto (brak lat/lon): {$skippedNoCoords}");
$out('Gotowe. USUŃ TEN PLIK z serwera, jeśli uruchamiałeś/aś przez przeglądarkę.');

if (!$isCli) {
    $out('</pre>');
}
