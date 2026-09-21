<?php
// backfill_image_sizes.php
// JEDNORAZOWY skrypt — przeskalowuje w miejscu zdjęcia zapisane NA DYSKU
// zanim istniało skalowanie w Utils\Upload (albo wgrane z pominięciem
// aplikacji, np. seed/dev dane) — patrz Utils\Upload::resizeExistingFile(),
// dodane specjalnie pod ten backfill, żeby użyć DOKŁADNIE tego samego GD
// algorytmu co świeże uploady (resizeIfOversized()), nie kopii logiki.
//
// Każdy NOWY upload (avatar/okładka eventu/hero organizatora/galeria opinii
// i relacji) już dziś przechodzi przez Utils\Upload i jest skalowany od razu
// — ten skrypt dotyczy WYŁĄCZNIE plików, które już leżały na dysku zanim to
// wdrożono, albo trafiły tam z pominięciem uploadu (import/seed).
//
// Obejmuje cztery miejsca przechowujące URL-e obrazków:
//   - users.avatar_url                          (max 400px)
//   - organizer_profiles.hero_photo_urls (JSON) (max 1200px)
//   - events.cover_photo_url                    (max 1600px)
//   - event_photos.url                          (max 1200px)
//
// UŻYCIE (SSH, zalecane):
//   php backfill_image_sizes.php
//
// UŻYCIE (bez SSH):
//   1. Wgraj ten plik do katalogu głównego strony.
//   2. Otwórz RAZ w przeglądarce: https://twoja-domena/backfill_image_sizes.php
//   3. USUŃ plik z serwera zaraz po tym — bez autoryzacji, każdy kto zna URL
//      mógłby go odpalić ponownie (nieszkodliwie, ale to niepotrzebne obciążenie).

putenv('APP_ENV=prod');
require __DIR__ . '/core/bootstrap.php';

use Core\Database;
use Utils\Upload;

$pdo = Database::connection();
$isCli = php_sapi_name() === 'cli';
$out = function (string $line) use ($isCli) {
    echo $line . ($isCli ? "\n" : "<br>\n");
};

if (!$isCli) {
    $out('<pre style="font-family:monospace;white-space:pre-wrap;">');
}

$out('APP_ENV=' . APP_ENV . ', baza=' . APP_CONFIG['db']['name']);
$out('---');

$updated = 0;
$skipped = 0;
$failed  = 0;

// Wspólna obsługa jednego URL-a (jak zapisanego w bazie, np.
// "/assets/uploads/avatars/xxx.jpg") — zwraca true jeśli faktycznie
// przeskalowano, false gdy plik już mieścił się w limicie.
$processUrl = function (?string $url, int $maxDimension, string $label) use (&$updated, &$skipped, &$failed, $out): void {
    if (!$url) {
        return;
    }
    $path = __DIR__ . '/' . ltrim($url, '/');
    if (!is_file($path)) {
        $out("BŁĄD {$label}: brak pliku na dysku ({$url})");
        $failed++;
        return;
    }
    try {
        if (Upload::resizeExistingFile($path, $maxDimension)) {
            $out("OK {$label}: przeskalowano ({$url})");
            $updated++;
        } else {
            $skipped++;
        }
    } catch (\Throwable $e) {
        $out("BŁĄD {$label}: " . $e->getMessage() . " ({$url})");
        $failed++;
    }
};

$out('== users.avatar_url ==');
$rows = $pdo->query("SELECT id, avatar_url FROM users WHERE avatar_url IS NOT NULL")->fetchAll();
foreach ($rows as $row) {
    $processUrl($row['avatar_url'], Upload::avatarMaxDimension(), 'user id=' . $row['id']);
}

$out('== organizer_profiles.hero_photo_urls ==');
$rows = $pdo->query("SELECT user_id, hero_photo_urls FROM organizer_profiles WHERE hero_photo_urls IS NOT NULL")->fetchAll();
foreach ($rows as $row) {
    $urls = json_decode($row['hero_photo_urls'], true) ?: [];
    foreach ($urls as $i => $url) {
        $processUrl($url, Upload::galleryMaxDimension(), 'organizer_profiles user_id=' . $row['user_id'] . " [{$i}]");
    }
}

$out('== events.cover_photo_url ==');
$rows = $pdo->query("SELECT id, cover_photo_url FROM events WHERE cover_photo_url IS NOT NULL")->fetchAll();
foreach ($rows as $row) {
    $processUrl($row['cover_photo_url'], Upload::coverMaxDimension(), 'event id=' . $row['id']);
}

$out('== event_photos.url ==');
$rows = $pdo->query("SELECT id, url FROM event_photos WHERE url IS NOT NULL")->fetchAll();
foreach ($rows as $row) {
    $processUrl($row['url'], Upload::galleryMaxDimension(), 'event_photos id=' . $row['id']);
}

$out('---');
$out("Przeskalowano: {$updated}, pominięto (już mieściły się w limicie): {$skipped}, błędy: {$failed}");
$out('Gotowe. USUŃ TEN PLIK z serwera, jeśli uruchamiałeś/aś przez przeglądarkę.');

if (!$isCli) {
    $out('</pre>');
}
