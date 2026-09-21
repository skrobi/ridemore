<?php
// backfill_discovery.php
// Nalicza Discovery (Etap 8) dla obecności potwierdzonych PRZED wdrożeniem
// modułu. Bez tego mapa startuje pusta, mimo że ludzie realnie przejechali te
// trasy — a pusta mapa społeczności na starcie zabija cały sens §6.2.
//
// Od wdrożenia odkrycia liczą się same, w EventAttendance::declare().
//
// Idempotentny: przejazd jest zapisywany raz na zapis (rider_activities.rsvp_id
// jest UNIQUE), więc ponowne uruchomienie niczego nie zdubluje.
//
// UWAGA: od migracji 042 liczą się WYŁĄCZNIE ślady z ODBYTEGO wyjazdu
// (edition_tracks) — własny ślad uczestnika albo ślad z imprezy wgrany przez
// organizatora. Trasa PLANOWANA wydarzenia nie wystarcza, bo jest zapowiedzią,
// nie dowodem przejechania. Dopóki nikt nie wgra ani jednego śladu, ten skrypt
// nie ma czego policzyć i mapa odkryć jest pusta — to jest poprawne.
// Wgranie śladu przelicza odkrycia od razu (EditionTrack::attach), więc
// ponowne uruchomienie tego skryptu potrzebne jest tylko po zmianach
// wykonanych poza aplikacją.
//
// UŻYCIE:
//   php backfill_discovery.php            — nalicza brakujące
//   php backfill_discovery.php --rebuild  — dodatkowo odtwarza agregat
//                                           społeczności z discovery_cells
//
// Na produkcji uruchamiać PO run_migrations.php (tabele muszą już istnieć).
//
// APP_ENV=prod WYMUSZONE TUTAJ (2026-09-10) — CLI nie przechodzi przez
// Apache, więc `SetEnv APP_ENV prod` w .htaccess tu nie działa i bez tej
// linii bootstrap.php spada na 'dev'. Ten sam wzorzec co w run_migrations.php.
//
// PO MIGRACJI 047 --rebuild JEST OBOWIĄZKOWY, nie opcjonalny: kolumny
// `discovery_cell_totals.parent_res0..3` (heksagon nadrzędny na każdym poziomie
// oddalenia mapy) dochodzą puste, a zapytanie mapy pomija pola bez wyliczonego
// rodzica — zamiast rysować je w złym miejscu. Bez przeliczenia mapa jest pusta
// na wszystkich poziomach poza pełnym przybliżeniem.

putenv('APP_ENV=prod');

require __DIR__ . '/core/bootstrap.php';

use Models\Discovery;
use Models\RiderActivity;

$pdo = Core\Database::connection();
$rebuild = in_array('--rebuild', $argv, true);

// Wszystkie potwierdzone obecności, dla których nie ma jeszcze przejazdu.
// Kolejność chronologiczna ma znaczenie: bonus za pierwsze odkrycie w
// społeczności (§9) należy się temu, kto był tam NAPRAWDĘ pierwszy, a nie
// temu, kogo skrypt akurat przetworzył wcześniej.
$rows = $pdo->query('
    SELECT r.id AS rsvp_id, ed.start_date, e.title
      FROM event_attendance a
      JOIN event_rsvps r ON r.id = a.rsvp_id
      JOIN event_editions ed ON ed.id = r.edition_id
      JOIN events e ON e.id = r.event_id
      LEFT JOIN rider_activities ra ON ra.rsvp_id = r.id
     WHERE a.attended = 1
       AND ra.id IS NULL
     ORDER BY ed.start_date ASC, r.id ASC
')->fetchAll();

echo 'Obecności do policzenia: ', count($rows), "\n";

$done = 0;
$skipped = 0;
$failed = 0;
foreach ($rows as $row) {
    try {
        RiderActivity::syncForRsvp((int) $row['rsvp_id'], true);

        $stmt = $pdo->prepare('SELECT cells_new, cells_touched FROM rider_activities WHERE rsvp_id = :rsvp_id');
        $stmt->execute(['rsvp_id' => $row['rsvp_id']]);
        $activity = $stmt->fetch();

        if (!$activity) {
            // Turnus bez śladu z odbytego wyjazdu — nie ma czym potwierdzić
            // przejazdu. Świadomie nie zapisujemy pustego przejazdu, żeby dało
            // się go policzyć, gdy ślad się pojawi.
            echo '  - ', mb_substr($row['title'], 0, 40), ' — brak śladu z wyjazdu, pominięte', "\n";
            $skipped++;
            continue;
        }

        echo '  + ', str_pad(mb_substr($row['title'], 0, 40), 42),
             str_pad($activity['cells_touched'] . ' pól', 12),
             $activity['cells_new'], " nowych\n";
        $done++;
    } catch (\Throwable $e) {
        echo '  ! rsvp ', $row['rsvp_id'], ' BŁĄD: ', $e->getMessage(), "\n";
        $failed++;
    }
}

if ($rebuild) {
    echo "\nOdtwarzanie agregatu społeczności...\n";
    echo '  discovery_cell_totals: ', Discovery::rebuildTotals(), " pól\n";
}

$stats = Discovery::communityStats();
echo "\nPrzejazdów policzonych: ", $done;
echo $skipped ? " | bez GPX: $skipped" : '';
echo $failed ? " | błędów: $failed" : '';
echo "\nRidemore odkryło łącznie: ", $stats['cells'], ' pól · ', $stats['riders'], " rowerzystów\n";
