<?php
// polar_webhook.php
// ZAKŁADANIE WEBHOOKA POLAR ACCESSLINK — automatyczny import (migr. 088, 2026-09-14).
//
// Webhook Polara jest JEDEN NA KLIENTA API (nie na użytkownika) i zakłada się
// go raz, zapytaniem z danymi klienta. W odpowiedzi Polar oddaje
// `signature_secret_key` — JEDYNY RAZ, później nie da się go odczytać. Ten
// sekret trzeba wpisać jako POLAR_WEBHOOK_SECRET w env produkcji; dopiero wtedy
// przełącznik „Dodawaj nowe przejazdy automatycznie" pokaże się w zakładce Polar.
//
// DLACZEGO CLI, A NIE PRZYCISK W PANELU: to jest jednorazowa czynność
// wdrożeniowa z sekretem na wyjściu, a nie coś, co ktokolwiek robi na co dzień.
// Sekret wypisany w przeglądarce zostałby w historii i w logach serwera.
//
// WARUNEK: przy zakładaniu Polar wysyła PING pod adres odbiornika i zakłada
// webhook TYLKO po odpowiedzi 200. Kod z trasą `/api/liczniki/polar/webhook`
// musi być już wdrożony na produkcji, zanim uruchomisz --create.
//
// UŻYCIE:
//   php polar_webhook.php             — stan: czy webhook istnieje i dokąd wskazuje
//   php polar_webhook.php --create    — załóż webhook i wypisz sekret
//   php polar_webhook.php --recreate  — skasuj istniejący i załóż od nowa (zgubiony sekret)
//   php polar_webhook.php --activate  — włącz webhook, który Polar wyłączył sam
//                                       po 7 dniach nieudanych doręczeń
//
// APP_ENV=prod WYMUSZONE TUTAJ — ten sam wzorzec co w backfillach: CLI nie
// przechodzi przez Apache, więc bez tej linii bootstrap spadłby na 'dev',
// a adres odbiornika wskazywałby na localhost, do którego Polar nie dotrze.
//
// Bramka CLI stoi PRZED bootstrapem i przed putenv: plik leży w katalogu
// serwowanym, a z przeglądarki nie ma prawa ani wypisać sekretu, ani
// przełączyć konfiguracji na produkcyjną.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tylko z linii poleceń.\n");
}

putenv('APP_ENV=prod');

require __DIR__ . '/core/bootstrap.php';

use Utils\DeviceApi;

$args = array_slice($argv, 1);
$url = DeviceApi::webhookUrl('polar');

try {
    $istniejace = DeviceApi::polarWebhooks();

    echo "Odbiornik: $url\n";
    if ($istniejace === []) {
        echo "Webhook: brak.\n";
    }
    foreach ($istniejace as $w) {
        echo 'Webhook: id=' . ($w['id'] ?? '?') . ', url=' . ($w['url'] ?? '?')
            . ', zdarzenia=' . implode(',', (array) ($w['events'] ?? [])) . "\n";
    }

    if (in_array('--activate', $args, true)) {
        foreach ($istniejace as $w) {
            DeviceApi::activatePolarWebhook((string) $w['id']);
            echo 'Włączono webhook ' . $w['id'] . ".\n";
        }
        exit(0);
    }

    $recreate = in_array('--recreate', $args, true);
    if (!$recreate && !in_array('--create', $args, true)) {
        exit(0);
    }

    if ($istniejace !== [] && !$recreate) {
        // Polar i tak odmówi drugiego — mówimy wprost, co zrobić, zamiast
        // pokazywać surowy błąd z ich API.
        fwrite(STDERR, "Webhook już istnieje. Jeśli zgubiłeś sekret, użyj --recreate.\n");
        exit(1);
    }
    foreach ($istniejace as $w) {
        DeviceApi::deletePolarWebhook((string) $w['id']);
        echo 'Skasowano webhook ' . $w['id'] . ".\n";
    }

    $nowy = DeviceApi::createPolarWebhook($url);
    echo "\nZałożono webhook {$nowy['id']}.\n";
    echo "Ustaw w env produkcji (Polar nie pokaże go już nigdy więcej):\n\n";
    echo "  POLAR_WEBHOOK_SECRET={$nowy['secret']}\n\n";
} catch (\RuntimeException $e) {
    fwrite(STDERR, 'Błąd: ' . $e->getMessage() . "\n");
    exit(1);
}
