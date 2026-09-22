<?php
// core/bootstrap.php

define('CORE_PATH', __DIR__);

spl_autoload_register(function ($class) {
    $path = CORE_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) require $path;
});

// Autoloader Composera (league/oauth2-client itd.) — aplikacja ma własny
// spl_autoload wyżej dla core/, ale zależności z vendor/ potrzebują tego.
// Współistnieją: Composer obsługuje tylko swoje namespace'y (League\...).
$composerAutoload = CORE_PATH . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

define('APP_ENV', getenv('APP_ENV') ?: 'dev');

$configs = require CORE_PATH . '/config.php';
if (!isset($configs[APP_ENV])) {
    throw new \RuntimeException("Nieznane środowisko: " . APP_ENV);
}
define('APP_CONFIG', $configs[APP_ENV]);

// TRYB APLIKACJI MOBILNEJ (Capacitor, patrz tasks/active/apka-mobilna.md).
//
// Apka to WebView ładujący TEN SAM serwis pod tym samym adresem — nie kopia
// frontu — więc sesja, CSRF i wszystkie bramki uprawnień działają bez zmian.
// Jedyne, po czym da się ją odróżnić od zwykłej przeglądarki, to własny
// dopisek w User-Agencie ustawiany w capacitor.config (`appendUserAgent`).
//
// Rozpoznanie stoi TU, przed session_start(), bo wpływa na parametry cookie
// sesji niżej: w apce nie ma „zamknięcia przeglądarki", jest ubicie procesu,
// po którym cookie sesyjne przepada i user ląduje na ekranie logowania przy
// każdym powrocie. Dlatego apka dostaje trwałe cookie z automatu, tak jakby
// zaznaczyła „Zapamiętaj mnie" (drugą połowę tej decyzji robi Core\Auth::login).
define('APP_IS_APP', str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'ridemore-app'));

// JĘZYK ŻĄDANIA (2026-09-16, tasks/active/wielojezycznosc.md) — z prefiksu
// adresu (`/en/…`), nigdy z nagłówków ani ciasteczka: ten sam adres ma zawsze
// znaczyć tę samą stronę. CLI (cron, testy) nie ma adresu, więc startuje
// po polsku i przełącza się per odbiorca przez Lang::with().
require_once CORE_PATH . '/i18n.php';
\Core\Lang::initFromRequest(PHP_SAPI === 'cli' ? '/' : ($_SERVER['REQUEST_URI'] ?? '/'));

if (session_status() === PHP_SESSION_NONE) {
    $rememberSeconds = 60 * 60 * 24 * 30; // 30 dni — spójne z Core\Auth::REMEMBER_SECONDS.

    // Prywatny katalog sesji zamiast współdzielonego domyślnego (/var/lib/php,
    // /tmp itp.). Na hostingu ten wspólny katalog sprząta SYSTEMOWY cron (albo
    // GC innej aplikacji) wg gc_maxlifetime z php.ini — domyślnie 24 min —
    // ignorując nasz ini_set niżej i kasując pliki sesji zalogowanych userów
    // po chwili bezczynności ("po chwili mnie wylogowuje" na produkcji, choć
    // lokalnie na XAMPP działa, bo tam tego crona nie ma). Własny katalog
    // czyścimy tylko my, naszym gc_maxlifetime. Chroniony przed dostępem po
    // HTTP osobnym storage/.htaccess (pliki sess_* to wrażliwe dane sesji).
    $sessionDir = CORE_PATH . '/../storage/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        ini_set('session.save_path', $sessionDir);
    }

    // gc_maxlifetime ustawiany długo na KAŻDYM żądaniu, nie tylko przy
    // remember_me. GC operuje na CAŁYM save_path naraz wg wartości z BIEŻĄCEGO
    // żądania — gdyby anonimowe wejście (bot, strona główna, sama strona
    // logowania) leciało z domyślnymi 24 min, wyczyściłoby też pliki sesji
    // zalogowanych. To steruje wyłącznie retencją pliku po stronie serwera;
    // trwałość per-user i tak rozstrzyga cookie (sesyjne bez "Zapamiętaj mnie",
    // 30 dni z zaznaczonym, patrz Core\Auth::login()).
    ini_set('session.gc_maxlifetime', (string) $rememberSeconds);
    // Część hostingów (Debian/Ubuntu) wyłącza własny GC PHP (gc_probability=0),
    // bo domyślny katalog sprząta systemowy cron — którego nasz prywatny
    // katalog NIE obejmuje. Bez własnego GC pliki rosłyby bez końca, więc
    // włączamy lekki (0,1% żądań), kasujący tylko wpisy starsze niż 30 dni.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    // Bezpieczeństwo cookie jest ustawiane ZAWSZE, także dla zwykłej sesji.
    // `remember_me` i APP_IS_APP sterują wyłącznie lifetime; wcześniej brak
    // znacznika zostawiał Secure/HttpOnly/SameSite przypadkowym wartościom z
    // php.ini hostingu.
    $persistentSession = isset($_COOKIE['remember_me']) || APP_IS_APP;
    session_set_cookie_params([
        'lifetime' => $persistentSession ? $rememberSeconds : 0,
        'path'     => '/',
        'secure'   => \Core\Auth::secureCookie(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Odróżnia etap awarii (baza danych vs. reszta aplikacji) zamiast pustej
// strony błędu PHP. Szczegóły tylko w dev — w prod ogólny komunikat.
set_exception_handler(function (\Throwable $e) {
    $stage = $e instanceof \PDOException ? 'database' : 'application';

    // ══════════════════════════════════════════════════════════════════════
    // CLI KOŃCZY SIĘ BŁĘDEM, NIE SUKCESEM (2026-09-12)
    // ══════════════════════════════════════════════════════════════════════
    // Ten handler był napisany wyłącznie pod żądanie HTTP i miał dwie wady,
    // które w przeglądarce nie znaczą nic, a w cronie znaczą wszystko:
    //
    // 1. `set_exception_handler` KOŃCZY SKRYPT NORMALNIE, czyli z kodem
    //    wyjścia 0. Cron, który nie połączył się z bazą i nie wykonał ani
    //    jednego zadania, meldował SUKCES — a harmonogram na hostingu wysyła
    //    powiadomienie tylko przy kodzie niezerowym. Dokładnie ten scenariusz
    //    opisuje ostrzeżenie w `cron.php` („mogły nigdy realnie nie zadziałać
    //    na produkcyjnych danych"): brak `APP_ENV=prod` w crontabie daje
    //    konfigurację 'dev', połączenie pada, a zgłoszenia nie ma żadnego.
    //    Zmierzone: `DB_NAME_DEV=baza_ktorej_nie_ma php cron.php noc`
    //    → kod wyjścia **0**.
    // 2. `header()` pod CLI nie ma czego ustawić i sypie ostrzeżeniem
    //    „Cannot modify header information", gdy skrypt cokolwiek wypisał —
    //    czyli zaśmieca log tym, co nie jest przyczyną awarii.
    //
    // Gałąź jest ZAWĘŻONA do `PHP_SAPI === 'cli'`, więc ścieżka HTTP zostaje
    // bajt w bajt taka, jaka była. Komunikat idzie na STDERR: w logu crona
    // odcina się od zwykłego wyjścia, a przy przekierowaniu `2>&1` ląduje
    // w tym samym pliku, tylko na końcu.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, sprintf(
            "BŁĄD [%s] %s w %s:%d — %s\n",
            $stage,
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        ));
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    if (APP_ENV === 'dev') {
        echo json_encode([
            'error'   => 'Nieobsłużony wyjątek',
            'stage'   => $stage,
            'type'    => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile() . ':' . $e->getLine(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        // Szczegóły ukryte przed userem, ale i tak muszą gdzieś trafić — inaczej
        // diagnoza awarii na produkcji to zgadywanka. Ląduje w standardowym
        // PHP error logu hostingu (cPanel: "Errors"/"Metrics" albo plik
        // error_log w katalogu domeny).
        error_log(sprintf('[%s] %s w %s:%d — %s', $stage, get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()));
        echo json_encode(['error' => 'Wystąpił błąd serwera']);
    }
});
