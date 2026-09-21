<?php
// tests/run.php
// URUCHAMIACZ TESTÓW.
//
//   php tests/run.php            — wszystkie zestawy
//   php tests/run.php skarby     — tylko pliki pasujące do wzorca
//
// BLOKADA NA BAZIE PRODUKCYJNEJ jest pierwszą rzeczą w tym pliku i nie da się
// jej ominąć przełącznikiem. Testy zapisują do bazy — wycofują to transakcją,
// ale wystarczy jeden przypadek, w którym kod pod testem zrobi commit, żeby
// zostało to na stałe. Na produkcji nie ma powodu tego ryzykować.
// POCZTA NA DRIVER `log` — USTAWIONE PRZED BOOTSTRAPEM, bo `APP_CONFIG` jest
// stałą i po załadowaniu konfiguracji nie da się już tego zmienić.
//
// Skrzynka SMTP w `core/config.php` jest PRAWDZIWA także na dev, a testy
// przechodzą przez kod, który wysyła maile (powiadomienia o wiadomościach,
// zachęty o skarbach). Bez tej linii każdy przebieg testów puka do serwera
// pocztowego z adresami z fixture'ów — `storage/mail-error.log` ma takie próby
// od 2026-08-05. Odbijają się o nieistniejące domeny `@example.com`, ale to
// przypadek, a nie zabezpieczenie: pierwszy fixture z prawdziwym adresem
// oznaczałby maila wysłanego do żywego człowieka z maszyny deweloperskiej.
putenv('MAIL_DRIVER=log');

require __DIR__ . '/lib.php';

if (APP_ENV !== 'dev') {
    fwrite(STDERR, "Testy uruchamiamy WYŁĄCZNIE na APP_ENV=dev. Teraz: " . APP_ENV . "\n");
    exit(2);
}

$pattern = $argv[1] ?? '';
$files = glob(__DIR__ . '/*_test.php') ?: [];
if ($pattern !== '') {
    $files = array_values(array_filter(
        $files,
        static fn(string $f): bool => str_contains(basename($f), $pattern)
    ));
}

if (!$files) {
    fwrite(STDERR, "Nie znaleziono żadnego pliku *_test.php" . ($pattern !== '' ? " dla wzorca „{$pattern}\"" : '') . "\n");
    exit(2);
}

foreach ($files as $file) {
    $GLOBALS['t_current_file'] = basename($file, '_test.php');
    require $file;
}

$db = Core\Database::connection();
$passed = 0;
$failed = 0;
$lastFile = null;
$start = microtime(true);

echo 'baza: ' . APP_CONFIG['db']['name'] . ', zestawów: ' . count($files)
   . ', testów: ' . count($GLOBALS['t_tests']) . "\n\n";

foreach ($GLOBALS['t_tests'] as $test) {
    if ($test['file'] !== $lastFile) {
        $lastFile = $test['file'];
        echo "[{$lastFile}]\n";
    }

    // KAŻDY TEST WE WŁASNEJ TRANSAKCJI. Wycofanie jest w finally, więc dzieje
    // się także przy wyjątku — inaczej pierwszy błąd zostawiałby otwartą
    // transakcję i wszystkie kolejne testy widziałyby jego zapisy.
    $db->beginTransaction();
    try {
        ($test['fn'])();
        echo "  PASS  {$test['name']}\n";
        $passed++;
    } catch (TAssertionFailed $e) {
        echo "  FAIL  {$test['name']}\n        {$e->getMessage()}\n";
        $failed++;
    } catch (\Throwable $e) {
        echo "  ERR   {$test['name']}\n        " . get_class($e) . ': ' . $e->getMessage()
           . "\n        " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $failed++;
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // CACHE MODELI PRZEŻYWA ROLLBACK — pułapka, na którą wpada się długo
        // po napisaniu testu. Transakcja cofa WIERSZE, ale statyczny $cache
        // w modelach ustawień zostaje z wartościami, których w bazie już nie
        // ma: kolejny test widzi wtedy cudzy budżet albo cudze okno wysyłki
        // i pada bez związku z tym, co sam sprawdza.
        //
        // Czyszczone TUTAJ, a nie w testach, bo inaczej każdy nowy test
        // musiałby o tym pamiętać — a zapomnienie objawia się dopiero
        // w SĄSIEDNIM teście, czyli tam, gdzie nikt nie będzie szukał.
        foreach ([Models\NotificationSettings::class, Models\NotificationTexts::class] as $model) {
            if (method_exists($model, 'forget')) {
                $model::forget();
            }
        }
    }
}

$ms = (int) round((microtime(true) - $start) * 1000);
echo "\n" . ($failed === 0 ? 'OK' : 'BŁĘDY') . ": {$passed} przeszło, {$failed} nie przeszło ({$ms} ms)\n";

// Kod wyjścia, żeby dało się to wpiąć w cokolwiek automatycznego.
exit($failed === 0 ? 0 : 1);
