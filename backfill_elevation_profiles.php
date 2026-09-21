<?php
// backfill_elevation_profiles.php
// JEDNORAZOWY skrypt — dogrywa lat/lon do już zapisanych profili wysokości
// (elevation_profile), które powstały PRZED dodaniem współrzędnych do
// Utils\Gpx::sampleProfile(). Bez tego zsynchronizowany punkt na
// mapie+profilu (ridemoreLinkElevationProfile w assets/js/gpx-map.js) po
// prostu się nie włącza dla starych wydarzeń — to jedyna funkcja, której to
// dotyczy (wysokość startu i szczyty liczą się z samego 'e'/'d', działają
// od razu bez backfillu).
//
// Bierze etapy z gpx_url, których elevation_profile albo nie ma, albo ma
// punkty bez 'lat' — i parsuje na nowo PLIK, który już leży na dysku pod tym
// URL-em (ten sam, z którego korzysta mapa). Nie dotyka distance_km/
// elevation_gain_m — tylko elevation_profile.
//
// UŻYCIE (SSH, zalecane):
//   php backfill_elevation_profiles.php
//
// UŻYCIE (bez SSH):
//   1. Wgraj ten plik do katalogu głównego strony.
//   2. Otwórz RAZ w przeglądarce: https://twoja-domena/backfill_elevation_profiles.php
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

$rows = $pdo->query("SELECT id, gpx_url, elevation_profile FROM event_stages WHERE gpx_url IS NOT NULL")->fetchAll();
$updateStmt = $pdo->prepare('UPDATE event_stages SET elevation_profile = :profile WHERE id = :id');

$updated = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $row) {
    $profile = $row['elevation_profile'] !== null ? json_decode($row['elevation_profile'], true) : null;
    if (!empty($profile) && isset($profile[0]['lat'])) {
        $skipped++;
        continue;
    }

    $path = __DIR__ . '/' . ltrim($row['gpx_url'], '/');
    if (!file_exists($path)) {
        $out("BŁĄD stage id={$row['id']}: brak pliku na dysku ({$row['gpx_url']})");
        $failed++;
        continue;
    }

    try {
        $parsed = Utils\Gpx::parse($path);
        $updateStmt->execute([
            'profile' => json_encode($parsed['elevationProfile']),
            'id'      => $row['id'],
        ]);
        $out("OK stage id={$row['id']}: " . count($parsed['elevationProfile']) . ' punktów');
        $updated++;
    } catch (\RuntimeException $e) {
        $out("BŁĄD stage id={$row['id']}: " . $e->getMessage());
        $failed++;
    }
}

$out('---');
$out("Zaktualizowano: {$updated}, pominięto (już miały lat/lon): {$skipped}, błędy: {$failed}");
$out('Gotowe. USUŃ TEN PLIK z serwera, jeśli uruchamiałeś/aś przez przeglądarkę.');

if (!$isCli) {
    $out('</pre>');
}
