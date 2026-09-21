<?php
// backfill_rider_slugs.php
// JEDNORAZOWY skrypt — nadaje users.public_slug wszystkim kontom założonym
// PRZED migracją 038 (publiczny profil rowerzysty, /rowerzysta/{slug}).
//
// Bez tego stare konta nie mają adresu profilu, więc ich nazwiska nigdzie się
// nie linkują — strona działa, ale skład wyjazdu zostaje listą imion zamiast
// listą ludzi. Nowe konta dostają slug przy rejestracji, ten skrypt domyka
// tylko zastaną bazę.
//
// Slug budowany z nazwy konta, a gdy jej nie ma — z części e-maila przed @.
// Kolizje rozwiązuje Format::uniqueSlug (dopisuje sufiks), więc dwaj
// "Jan Kowalski" dostaną jan-kowalski i jan-kowalski-2.
//
// Idempotentny: konta, które slug już mają, są pomijane. Można puścić
// ponownie bez skutków ubocznych.
//
// UŻYCIE (SSH, zalecane):
//   php backfill_rider_slugs.php
//
// Na produkcji uruchamiać PO run_migrations.php (kolumna musi już istnieć).
//
// APP_ENV=prod WYMUSZONE TUTAJ (2026-09-10) — CLI nie przechodzi przez
// Apache, więc `SetEnv APP_ENV prod` w .htaccess tu nie działa i bez tej
// linii bootstrap.php spada na 'dev'. Ten sam wzorzec co w run_migrations.php.
putenv('APP_ENV=prod');

require __DIR__ . '/core/bootstrap.php';

$pdo = Core\Database::connection();

$rows = $pdo->query('
    SELECT id, name, email
    FROM users
    WHERE public_slug IS NULL
    ORDER BY id ASC
')->fetchAll();

if (!$rows) {
    echo "Nie ma czego uzupełniać — każde konto ma już public_slug.\n";
    exit;
}

echo 'Kont bez sluga: ', count($rows), "\n";

$done = 0;
$failed = 0;
foreach ($rows as $row) {
    // Nazwa konta bywa pusta (rejestracja e-mailem bez uzupełnienia profilu,
    // konta zakładane automatem przy zgłoszeniu w czyimś imieniu) — wtedy
    // bierzemy część adresu przed @, żeby slug w ogóle dało się zbudować.
    $base = trim((string) $row['name']);
    if ($base === '') {
        $base = (string) strstr((string) $row['email'], '@', true);
    }

    try {
        $slug = Models\User::generatePublicSlug($base);
        $pdo->prepare('UPDATE users SET public_slug = :slug WHERE id = :id')
            ->execute(['slug' => $slug, 'id' => $row['id']]);
        echo '  #', str_pad((string) $row['id'], 5), ' -> ', $slug, "\n";
        $done++;
    } catch (\Throwable $e) {
        // Jedno wadliwe konto nie może zatrzymać reszty — raportujemy i lecimy dalej.
        echo '  #', str_pad((string) $row['id'], 5), ' BŁĄD: ', $e->getMessage(), "\n";
        $failed++;
    }
}

echo "\nNadano slugów: ", $done;
echo $failed ? " | błędów: $failed" : '';
echo "\n";
