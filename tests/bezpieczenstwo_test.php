<?php
// Regresje zabezpieczeń brzegowych. To celowo testy źródła: właściwe metody
// kończą żądanie przez exit/header albo startują sesję przy bootstrapie, więc
// ich bezpośrednie wołanie w jednym procesie runnera nie jest izolowalne.

t_test('sekrety: bieżąca konfiguracja nie ma wartości zapasowych', function () {
    $config = (string) file_get_contents(CORE_PATH . '/config.php');

    foreach ([
        'SMTP_PASSWORD',
        'GOOGLE_CLIENT_SECRET',
        'POLAR_CLIENT_SECRET',
        'DEVICE_TOKEN_KEY',
        'GARMIN_TOKEN_KEY',
        'NOTIFICATIONS_UNSUBSCRIBE_KEY',
        'DB_PASS_DEV',
        'DB_PASS_PROD',
    ] as $name) {
        t_true(
            (bool) preg_match('/\$env\(\'' . preg_quote($name, '/') . '\',\s*\'\'\)/', $config),
            $name . ' ma pusty fallback'
        );
    }

    $ignore = (string) file_get_contents(CORE_PATH . '/../.gitignore');
    $htaccess = (string) file_get_contents(CORE_PATH . '/../.htaccess');
    t_true(str_contains($ignore, '/core/config.local.php'), 'lokalna konfiguracja jest ignorowana');
    t_true(str_contains($ignore, '/error_log'), 'log runtime jest ignorowany');
    t_true(is_file(CORE_PATH . '/config.local.example.php'), 'repo zawiera bezpieczny wzór konfiguracji');
    t_true(str_contains($htaccess, '^error_log$'), 'odtworzony log runtime jest blokowany przez HTTP');
    t_true(str_contains($htaccess, '^\\.env'), 'pliki środowiskowe są blokowane przez HTTP');
});

t_test('SMTP: certyfikat i nazwa hosta są weryfikowane lokalnie', function () {
    $mailer = (string) file_get_contents(CORE_PATH . '/Core/Mailer.php');

    t_true(str_contains($mailer, 'stream_socket_client('), 'połączenie przyjmuje własny kontekst TLS');
    t_false(str_contains($mailer, 'stream_context_set_default(['), 'mailer nie osłabia globalnego kontekstu');
    t_true((bool) preg_match("/'verify_peer'\s*=>\s*true/", $mailer), 'weryfikacja łańcucha certyfikatu');
    t_true((bool) preg_match("/'verify_peer_name'\s*=>\s*true/", $mailer), 'weryfikacja nazwy certyfikatu');
    t_true((bool) preg_match("/'allow_self_signed'\s*=>\s*false/", $mailer), 'brak self-signed w produkcyjnym SMTP');
});

t_test('logowanie: limiter chroni jednocześnie IP i konto', function () {
    $auth = (string) file_get_contents(CORE_PATH . '/Controllers/AuthController.php');
    $start = strpos($auth, 'public static function login(): void');
    $end = strpos($auth, 'public static function logout(): void');
    $login = ($start !== false && $end !== false) ? substr($auth, $start, $end - $start) : '';

    t_true(str_contains($login, "RateLimiter::tooMany('login:ip:'"), 'limit per IP');
    t_true(str_contains($login, "RateLimiter::tooMany('login:email:'"), 'limit per znormalizowany e-mail');
    t_true(strpos($login, "RateLimiter::tooMany('login:ip:'") < strpos($login, 'User::findByEmail'), 'limit działa przed odczytem konta');
});

t_test('sesja: każda cookie ma jawne flagi, a nie tylko remember-me', function () {
    $bootstrap = (string) file_get_contents(CORE_PATH . '/bootstrap.php');
    $auth = (string) file_get_contents(CORE_PATH . '/Core/Auth.php');

    t_true(str_contains($bootstrap, "ini_set('session.use_strict_mode', '1')"), 'strict mode sesji');
    t_true(str_contains($bootstrap, '$persistentSession = isset($_COOKIE[\'remember_me\']) || APP_IS_APP;'), 'remember steruje tylko trwałością');
    t_true(str_contains($bootstrap, "'lifetime' => \$persistentSession ? \$rememberSeconds : 0"), 'zwykła sesja dostaje lifetime 0');
    foreach (["'secure'", "'httponly'", "'samesite'"] as $flag) {
        t_true(str_contains($bootstrap, $flag), 'jawna flaga ' . $flag);
    }
    t_true(str_contains($auth, 'public static function secureCookie(): bool'), 'jedno źródło decyzji Secure');
    t_true(str_contains($auth, "APP_CONFIG['app_url']"), 'HTTPS za proxy wynika z zaufanej konfiguracji');
});

t_test('usuwanie śladu: CSRF jest sprawdzony przed operacją destrukcyjną', function () {
    $track = (string) file_get_contents(CORE_PATH . '/Controllers/TrackController.php');
    $start = strpos($track, 'public static function delete(');
    $end = strpos($track, 'private static function backUrl(');
    $delete = ($start !== false && $end !== false) ? substr($track, $start, $end - $start) : '';

    $csrf = strpos($delete, 'Csrf::check(');
    $remove = strpos($delete, 'EditionTrack::remove(');
    t_true($csrf !== false, 'guard CSRF istnieje');
    t_true($remove !== false && $csrf < $remove, 'guard poprzedza usunięcie i resynchronizację punktów');
});

t_test('klucze HMAC i szyfrowania zawodzą bezpiecznie, gdy konfiguracji brak', function () {
    $notifications = (string) file_get_contents(CORE_PATH . '/Models/NotificationGate.php');
    $devices = (string) file_get_contents(CORE_PATH . '/Models/DeviceConnection.php');

    t_true((bool) preg_match('/unsubscribe_key.{0,220}if\s*\(\$sekret\s*===\s*\'\'\).{0,180}RuntimeException/s', $notifications), 'pusty HMAC nie podpisuje linku');
    t_true((bool) preg_match('/devices.token_key.{0,260}if\s*\(\$secret\s*===\s*\'\'\).{0,180}RuntimeException/s', $devices), 'pusty klucz nie szyfruje tokenu');
});
