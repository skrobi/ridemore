<?php
// backfill_multi_regions.php
// Wypełnia known_route_regions / event_regions / rider_activity_regions
// (migr. 074) — regiony wyprowadzone z geometrii, nie z ręcznego wyboru.
// Uzupełnienie do migration_075_drop_region_item_id.sql: kolumna
// events.region_item_id / known_routes.region_item_id znika w 075, więc TEN
// skrypt musi przejść PRZED nią, inaczej dane o regionie po prostu przepadną.
//
// Źródła:
//   known_route_regions    — known_route_cells ⋈ region_cells, per trasa.
//   rider_activity_regions — rider_activity_cells ⋈ region_cells, per przejazd.
//   event_regions          — DWA źródła naraz (decyzja usera, bo większość
//     wydarzeń dziś nie ma GPX): (a) ręcznie zadeklarowany region eventu
//     (stare events.region_item_id, jeszcze czytelne w tym skrypcie, bo
//     odpalany PRZED migracją 075) i (b) regiony wyliczone z GPX etapów/
//     wariantów przez Models\RoutePreview::cellsForGpx (ta sama trasa co
//     "co mi to da"). Suma obu, bez utraty żadnego z nich.
//
// Idempotentny: INSERT IGNORE, bez kasowania wcześniej policzonych wierszy
// (start od zera i tak jest bezpieczny — to świeża, pusta tabela z migr. 074).
//
// UŻYCIE:
//   php backfill_multi_regions.php
//
// APP_ENV=prod WYMUSZONE TUTAJ (2026-09-10) — CLI nie przechodzi przez
// Apache, więc `SetEnv APP_ENV prod` w .htaccess tu nie działa i bez tej
// linii bootstrap.php spada na 'dev'. Ten sam wzorzec co w run_migrations.php.
putenv('APP_ENV=prod');

require __DIR__ . '/core/bootstrap.php';

use Core\Database;
use Models\RoutePreview;

$pdo = Database::connection();

function flushPairs(\PDO $pdo, string $table, string $col, array &$rows): void
{
    if (!$rows) { return; }
    $values = rtrim(str_repeat('(?,?),', count($rows)), ',');
    $stmt = $pdo->prepare("INSERT IGNORE INTO $table ($col, region_item_id) VALUES $values");
    $params = [];
    foreach ($rows as [$id, $regionId]) { $params[] = $id; $params[] = $regionId; }
    $stmt->execute($params);
    $rows = [];
}

// ---------------------------------------------------------------
// known_route_regions
// ---------------------------------------------------------------
echo "known_route_regions...\n";
$n = $pdo->exec('
    INSERT IGNORE INTO known_route_regions (route_id, region_item_id)
    SELECT DISTINCT krc.route_id, rc.region_item_id
      FROM known_route_cells krc
      JOIN region_cells rc ON rc.cell_id = krc.cell_id
');
echo "  wstawiono $n wierszy\n";

// ---------------------------------------------------------------
// rider_activity_regions
// ---------------------------------------------------------------
echo "rider_activity_regions...\n";
$n = $pdo->exec('
    INSERT IGNORE INTO rider_activity_regions (activity_id, region_item_id)
    SELECT DISTINCT rac.activity_id, rc.region_item_id
      FROM rider_activity_cells rac
      JOIN region_cells rc ON rc.cell_id = rac.cell_id
');
echo "  wstawiono $n wierszy\n";

// ---------------------------------------------------------------
// event_regions — deklaracja ręczna (stare region_item_id, jeszcze w bazie)
// ---------------------------------------------------------------
echo "event_regions (deklaracje ręczne)...\n";
$n = $pdo->exec('
    INSERT IGNORE INTO event_regions (event_id, region_item_id)
    SELECT id, region_item_id FROM events WHERE region_item_id IS NOT NULL
');
echo "  wstawiono $n wierszy\n";

// ---------------------------------------------------------------
// event_regions — dociągnięte z GPX etapów/wariantów
// ---------------------------------------------------------------
echo "event_regions (z GPX etapów/wariantów)...\n";
$gpxByEvent = [];
foreach ($pdo->query('SELECT event_id, gpx_url FROM event_stages WHERE gpx_url IS NOT NULL') as $row) {
    $gpxByEvent[(int) $row['event_id']][] = $row['gpx_url'];
}
foreach ($pdo->query('SELECT event_id, gpx_url FROM event_route_variants WHERE gpx_url IS NOT NULL') as $row) {
    $gpxByEvent[(int) $row['event_id']][] = $row['gpx_url'];
}

$rows = [];
$countEvents = 0;
foreach ($gpxByEvent as $eventId => $gpxUrls) {
    $cells = [];
    foreach (array_unique($gpxUrls) as $gpxUrl) {
        $abs = CORE_PATH . '/..' . $gpxUrl;
        foreach (RoutePreview::cellsForGpx($abs) as $cellId) {
            $cells[$cellId] = true;
        }
    }
    if (!$cells) { continue; }
    $countEvents++;
    $placeholders = implode(',', array_fill(0, count($cells), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT region_item_id FROM region_cells WHERE cell_id IN ($placeholders)");
    $stmt->execute(array_keys($cells));
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $regionId) {
        $rows[] = [$eventId, (int) $regionId];
        if (count($rows) >= 500) { flushPairs($pdo, 'event_regions', 'event_id', $rows); }
    }
}
flushPairs($pdo, 'event_regions', 'event_id', $rows);
echo "  eventów z GPX: $countEvents\n";

$total = (int) $pdo->query('SELECT COUNT(*) FROM known_route_regions')->fetchColumn();
echo "\nknown_route_regions: $total wierszy\n";
$total = (int) $pdo->query('SELECT COUNT(*) FROM event_regions')->fetchColumn();
echo "event_regions: $total wierszy\n";
$total = (int) $pdo->query('SELECT COUNT(*) FROM rider_activity_regions')->fetchColumn();
echo "rider_activity_regions: $total wierszy\n";
