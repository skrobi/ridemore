<?php
// run_migrations.php
// Skrypt aktualizujący bazę o kolejne migracje z katalogu migration/.
// W odróżnieniu od poprzedniej wersji NIE ma tu ręcznie utrzymywanej listy
// plików — katalog migration/ jest przeszukiwany automatycznie (migration_*.sql,
// posortowane po nazwie), a to, co już zostało zastosowane, jest zapisywane w
// tabeli `schema_migrations` (tworzonej automatycznie przy pierwszym
// uruchomieniu). Dzięki temu dodanie nowej migracji to po prostu wrzucenie
// kolejnego pliku migration_XXX_*.sql do katalogu — nie trzeba nigdzie
// dopisywać jego nazwy.
//
// Pierwsze uruchomienie na bazie, która nie miała jeszcze tabeli
// schema_migrations (czyli każda baza aktualizowana starą wersją tego
// skryptu) wciąż bezpiecznie pomija już zastosowane migracje po komunikacie
// błędu ("Duplicate column"/"already exists"/"Duplicate entry") — i przy
// okazji rejestruje je w schema_migrations, więc każde kolejne uruchomienie
// jest już szybkim odpytaniem tabeli, bez parsowania błędów SQL.
//
// UŻYCIE (SSH, zalecane):
//   php run_migrations.php
//
// UŻYCIE (bez SSH, np. cPanel bez terminala):
//   1. Wgraj ten plik do katalogu głównego strony na produkcji.
//   2. Otwórz RAZ w przeglądarce: https://twoja-domena/run_migrations.php
//   3. Zapisz sobie wypisany wynik.
//   4. NATYCHMIAST USUŃ plik z serwera — nie ma żadnej autoryzacji, każdy
//      kto zna URL mógłby go odpalić ponownie.
//
// ZALECENIE: przed uruchomieniem zrób kopię bazy (mysqldump) — to zmiana
// struktury produkcyjnej bazy danych, w większości nieodwracalna wprost.

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

// Rejestr zastosowanych migracji — tworzony automatycznie, nie jest osobnym
// plikiem migration_*.sql (musi istnieć zanim cokolwiek z rejestru odczytamy).
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        name        VARCHAR(190) PRIMARY KEY,
        applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$isApplied = function (string $name) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = :name');
    $stmt->execute(['name' => $name]);
    return (bool) $stmt->fetchColumn();
};
$markApplied = function (string $name) use ($pdo): void {
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (name) VALUES (:name)')
        ->execute(['name' => $name]);
};

// Krok specjalny (nie jest plikiem .sql) — backfill organizer_profiles.slug,
// patrz migration_004_organizer_profile.sql (dodaje kolumnę, ale nie
// wypełnia jej dla istniejących wierszy). Wpięty w kolejkę zaraz po tamtej
// migracji, śledzony w schema_migrations pod własną, syntetyczną nazwą, więc
// też wykona się dokładnie raz.
$runBackfillSlugs = function () use ($pdo, $out) {
    $out('--- backfill: organizer_profiles.slug ---');
    try {
        $rows = $pdo->query("
            SELECT op.user_id, u.name, u.email
            FROM organizer_profiles op
            JOIN users u ON u.id = op.user_id
            WHERE op.slug IS NULL
        ")->fetchAll();
        foreach ($rows as $row) {
            $slug = Models\Organizer::generateUniqueSlug($row['name'] ?: $row['email']);
            $pdo->prepare('UPDATE organizer_profiles SET slug = :slug WHERE user_id = :uid')
                ->execute(['slug' => $slug, 'uid' => $row['user_id']]);
            $out("  user_id={$row['user_id']} -> slug={$slug}");
        }
        $out(count($rows) . ' wiersz(y) zaktualizowanych.');
    } catch (\PDOException $e) {
        // Kolumna slug pewnie jeszcze nie istnieje (migration_004 nie
        // przeszła) — nic tu do zrobienia, sam plik .sql doda kolumnę.
        $out('POMINIĘTO backfill (jeszcze brak kolumny slug?): ' . $e->getMessage());
    }
};

// Krok specjalny nr 2 — przewyższenie znanych tras (migracja 063 dodaje
// kolumnę, ale wartości nie da się wyliczyć SQL-em: trzeba sparsować plik GPX
// każdej trasy). Ta sama zasada co przy slugach wyżej: wpięte w kolejkę zaraz
// po swojej migracji i śledzone pod własną nazwą, więc wykona się raz.
//
// Logika siedzi w modelu (KnownRoute::backfillElevation), a nie tutaj — ten
// plik ma orkiestrować migracje, a nie znać się na parsowaniu GPX-ów.
$runBackfillElevation = function () use ($out) {
    $out('--- backfill: known_routes.elevation_gain_m ---');
    try {
        $wynik = Models\KnownRoute::backfillElevation($out);
        $out('Uzupełniono: ' . $wynik['updated'] . ', pominięto: ' . $wynik['skipped'] . '.');
    } catch (\Throwable $e) {
        // Kolumna pewnie jeszcze nie istnieje (migracja 063 nie przeszła) —
        // sam plik .sql ją doda, a backfill wykona się przy kolejnym przebiegu.
        $out('POMINIĘTO backfill przewyższeń: ' . $e->getMessage());
    }
};

// Krok specjalny nr 3 — kolory znanych tras (migracja 064 dodaje kolumnę, ale
// wartości nie da się wyliczyć SQL-em: dobór patrzy na to, które trasy leżą
// obok siebie, a to liczy się na współrzędnych osiowych pól). Ta sama zasada
// co wyżej: wpięte zaraz po swojej migracji, śledzone pod własną nazwą.
$runBackfillColors = function () use ($out) {
    $out('--- backfill: known_routes.color_index ---');
    try {
        $wynik = Models\KnownRoute::assignAllColors($out);
        $out('Pokolorowano: ' . $wynik['colored'] . ', pominięto (miały kolor): ' . $wynik['skipped'] . '.');
    } catch (\Throwable $e) {
        // Kolumna pewnie jeszcze nie istnieje (migracja 064 nie przeszła) —
        // sam plik .sql ją doda, a backfill wykona się przy kolejnym przebiegu.
        $out('POMINIĘTO backfill kolorów tras: ' . $e->getMessage());
    }
};

// Auto-discovery: wszystkie migration_*.sql w katalogu migration/, posortowane
// po nazwie pliku — a to, dzięki zero-padded numeracji (001, 002, ..., 013,
// 014, ...), odpowiada kolejności chronologicznej. "004b" celowo sortuje się
// tuż za "004" ('_' < 'b' w porównaniu znak po znaku), więc nie trzeba nic
// specjalnie wymuszać.
$files = glob(__DIR__ . '/migration/migration_*.sql');
sort($files, SORT_STRING);

foreach ($files as $path) {
    $name = basename($path);

    if ($isApplied($name)) {
        $out("POMINIĘTO (już w rejestrze): {$name}");
    } else {
        $sql = file_get_contents($path);
        try {
            $pdo->exec($sql);
            $markApplied($name);
            $out("OK: {$name}");
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (preg_match('/(Duplicate column|already exists|Duplicate entry|Duplicate key)/i', $msg)) {
                // Zastosowana wcześniej, zanim istniała tabela schema_migrations
                // (albo ręcznie) — rejestrujemy retroaktywnie, żeby kolejne
                // uruchomienia już tego nie sprawdzały przez błąd SQL.
                $markApplied($name);
                $out("POMINIĘTO (już zastosowane, zarejestrowano retroaktywnie): {$name} — {$msg}");
            } else {
                $out("BŁĄD w {$name}: {$msg}");
                $out('Zatrzymuję — napraw ręcznie i uruchom ponownie (już zastosowane kroki zostaną pominięte).');
                break;
            }
        }
    }

    // Backfill slugów zaraz po migration_004 — dokładnie raz, niezależnie od
    // tego, czy migration_004 właśnie wykonano, czy była już w rejestrze.
    if ($name === 'migration_004_organizer_profile.sql' && !$isApplied('__backfill_organizer_slugs__')) {
        $runBackfillSlugs();
        $markApplied('__backfill_organizer_slugs__');
    }

    // To samo dla przewyższeń znanych tras (migr. 063) — patrz nota wyżej.
    if ($name === 'migration_063_route_elevation.sql' && !$isApplied('__backfill_route_elevation__')) {
        $runBackfillElevation();
        $markApplied('__backfill_route_elevation__');
    }

    // I dla kolorów tras (migr. 064).
    if ($name === 'migration_064_route_color.sql' && !$isApplied('__backfill_route_colors__')) {
        $runBackfillColors();
        $markApplied('__backfill_route_colors__');
    }

    // Profile wysokości tras (migr. 065) — TA SAMA metoda co po 063, bo i tak
    // parsuje ten sam plik GPX. Po 063 kolumny profilu jeszcze nie ma i metoda
    // sama to sprawdza, więc wtedy dopisuje samo przewyższenie, a tutaj resztę.
    if ($name === 'migration_065_route_elevation_profile.sql' && !$isApplied('__backfill_route_profile__')) {
        $runBackfillElevation();
        $markApplied('__backfill_route_profile__');
    }
}

$out('---');
$out('Gotowe. Nowe migracje: wystarczy wrzucić kolejny plik migration_XXX_*.sql do katalogu migration/ i uruchomić ten skrypt ponownie.');
$out('USUŃ TEN PLIK z serwera, jeśli uruchamiałeś/aś przez przeglądarkę.');

if (!$isCli) {
    $out('</pre>');
}
