<?php
// import_events.php
// Worker CLI importera wydarzeń rowerowych (patrz tasks/active/importer-wydarzen.md).
// Dla każdego podanego adresu: pobiera stronę (Utils\EventSourceFetcher),
// ekstrahuje TYM SAMYM silnikiem co rozszerzenie Chrome (Utils\AiEngineBridge ->
// ai-engine/analyze.py), zapisuje kandydata w kolejce weryfikacji
// (Models\EventImport). NIC nie publikuje — człowiek zatwierdza w /admin.
//
// Użycie:
//   php import_events.php <url> [<url> ...]           bezpośrednie adresy wydarzeń
//   php import_events.php --file=lista-url.txt        jeden adres na linię, # = komentarz
//   php import_events.php --list=<adres-kalendarza>   POZIOM 2: wyłuskaj adresy
//                                                     wydarzeń ze strony-listy
//                                                     (kalendarz/„nadchodzące")
//   php import_events.php --sources                   POZIOM 2b: przejdź po
//                                                     WSZYSTKICH kalendarzach z
//                                                     allowlisty (config
//                                                     event_import.sources) —
//                                                     pod harmonogram (cron/
//                                                     Task Scheduler)
//   php import_events.php --dry --list=<adres>        tylko pokaż, co zebrano/pobrano
//                                                     (bez Gemini, bez zapisu do bazy)
//
// --dry łączy się z każdym trybem: dla --list wypisuje znalezione adresy, dla
// adresów bezpośrednich pobiera stronę i pokazuje podsumowanie payloadu (tytuł,
// liczba linków/elementów) — zero kosztu modelu i zero zapisu. Do sprawdzenia
// „skąd lista" i czy strona dobrze się parsuje, zanim puścimy ekstrakcję.
//
// APP_ENV: jak cron.php — nie wymuszamy 'prod' na sztywno (silnik AI działa
// realnie na dev, tak jak /api/ai/engine-analyze i całe AI-Engine). Środowisko
// ustawia APP_ENV samo, jeśli trzeba. Pierwsza linia wyjścia mówi, gdzie
// jesteśmy i do jakiej bazy piszemy (ten sam zwyczaj co cron/run_migrations).
//
// TYLKO Z WIERSZA POLECEŃ — jak cron.php: przez HTTP zwraca 404 (plik leży
// w katalogu serwowanym).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/core/bootstrap.php';

use Models\EventImport;
use Models\EventImportSource;
use Utils\EventSourceFetcher;

// --- Zebranie adresów z argumentów / pliku / list ---------------------------
$urls = [];
$listUrls = [];
$dry = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry' || $arg === '--dry-run') {
        $dry = true;
    } elseif ($arg === '--sources') {
        // Zarządzalna lista źródeł (data/event_sources.json, panel /admin/importer)
        // — wspólna dla CLI i web; seed z config event_import.sources.
        foreach (EventImportSource::urls() as $src) {
            $listUrls[] = $src;
        }
        if (!$listUrls) {
            fwrite(STDERR, "Brak skonfigurowanych źródeł (data/event_sources.json / config event_import.sources).\n");
        }
    } elseif (str_starts_with($arg, '--list=')) {
        $listUrls[] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--file=')) {
        $path = substr($arg, 7);
        if (!is_file($path)) {
            fwrite(STDERR, "Nie znaleziono pliku: $path\n");
            exit(2);
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $urls[] = $line;
            }
        }
    } elseif (str_starts_with($arg, 'http://') || str_starts_with($arg, 'https://')) {
        $urls[] = $arg;
    } else {
        fwrite(STDERR, "Pomijam argument (nie URL ani --file=/--list=): $arg\n");
    }
}

if (!$urls && !$listUrls) {
    fwrite(STDERR, "Użycie: php import_events.php <url> ...  |  --file=lista.txt  |  --list=<adres-kalendarza>  |  --sources  [--dry]\n");
    exit(2);
}

echo 'APP_ENV=' . APP_ENV . ', baza=' . APP_CONFIG['db']['name'] . ($dry ? ' [DRY-RUN: bez modelu i bez zapisu]' : '') . "\n\n";

// --- POZIOM 2: wyłuskanie adresów wydarzeń ze stron-list --------------------
foreach ($listUrls as $listUrl) {
    echo "◆ lista: $listUrl\n";
    try {
        $listPayload = EventSourceFetcher::fetch($listUrl);
        $found = EventSourceFetcher::extractEventLinks($listPayload['links'], $listUrl);
        echo '   znaleziono ' . count($found) . " adresów wydarzeń\n";
        if (!$found && EventSourceFetcher::looksJsRendered($listPayload)) {
            echo "   ⚠ strona prawdopodobnie renderowana JavaScriptem (SPA) — serwerowy harvester nie widzi jej treści.\n";
            echo "     Użyj rozszerzenia Chrome na tym źródle (widzi wyrenderowaną stronę) albo podaj bezpośrednie adresy wydarzeń.\n";
        }
        foreach ($found as $f) {
            echo '     • ' . $f['url'] . ($f['text'] !== '' ? '  („' . mb_substr($f['text'], 0, 60) . '")' : '') . "\n";
            $urls[] = $f['url'];
        }
    } catch (\Throwable $e) {
        echo '   ERR ' . $e->getMessage() . "\n";
    }
}
// Odduplikuj (ta sama pozycja mogła przyjść z listy i z argumentu).
$urls = array_values(array_unique($urls));

if (!$urls) {
    echo "\nBrak adresów wydarzeń do przetworzenia.\n";
    exit(0);
}

// --- DRY-RUN: tylko podgląd, bez modelu i bez zapisu ------------------------
if ($dry) {
    echo "\nDo przetworzenia (" . count($urls) . "), podgląd pobrania:\n";
    foreach ($urls as $url) {
        try {
            $p = EventSourceFetcher::fetch($url);
            echo "   OK  $url\n      tytuł: " . ($p['pageTitle'] ?? '(brak)')
               . ' | linków: ' . count($p['links']) . ' | elementów: ' . count($p['elements'])
               . ' | JSON-LD: ' . count($p['jsonLd']) . "\n";
        } catch (\Throwable $e) {
            echo "   ERR $url — " . $e->getMessage() . "\n";
        }
    }
    echo "\nDRY-RUN zakończony — nic nie zapisano. Uruchom bez --dry, aby utworzyć kandydatów.\n";
    exit(0);
}

echo 'Adresów wydarzeń do przetworzenia: ' . count($urls) . "\n\n";

$created = 0;
$duplicates = 0;
$skipped = 0;
$errors = 0;

foreach ($urls as $url) {
    echo "→ $url\n";
    // Cały import jednego adresu (fetch -> silnik AI -> dziennik -> kolejka)
    // żyje w Models\EventImport::importUrl — ten sam rdzeń, co panel web.
    $res = EventImport::importUrl($url);
    switch ($res['status']) {
        case 'created':
            $created++;
            echo "   OK  utworzono kandydata: {$res['slug']} (oczekuje_weryfikacji)\n";
            break;
        case 'possible_duplicate':
            $duplicates++;
            echo "   OK  kandydat {$res['slug']} — MOŻLIWY DUPLIKAT wobec {$res['duplicateOf']} (do rozstrzygnięcia w /admin)\n";
            break;
        case 'js_rendered':
        case 'unsupported_source':
            $skipped++;
            echo "   -   pominięto: {$res['reason']}\n";
            break;
        case 'error':
            $errors++;
            echo '   ERR ' . $res['reason'] . "\n";
            error_log(sprintf('import_events: %s dla %s', $res['reason'], $url));
            break;
        default:
            $skipped++;
            echo "   -   pominięto: {$res['reason']}" . ($res['slug'] ? " (istnieje: {$res['slug']})" : '') . "\n";
    }
}

echo "\nPodsumowanie: utworzono $created, możliwych duplikatów $duplicates, pominięto $skipped, błędów $errors.\n";
echo "Zatwierdź/odrzuć kandydatów w panelu /admin.\n";
exit($errors > 0 ? 1 : 0);
