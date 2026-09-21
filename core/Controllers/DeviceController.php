<?php
// core/Controllers/DeviceController.php
// LICZNIKI PRZEZ OAuth NA EKRANIE „MOJE PRZEJAZDY" (migr. 069) — Polar, Wahoo.
//
// Pięć czynności: połącz (przekierowanie do dostawcy), powrót z autoryzacji,
// odśwież listę, zaimportuj zaznaczone, odłącz. Garmin ma własny kontroler,
// bo jako jedyny nie ma OAuth i loguje się hasłem przez most do Pythona —
// wspólny kontroler musiałby obsłużyć dwa zupełnie różne przepływy logowania
// i skończyłby się gałęzią `if ($provider === 'garmin')` w każdej metodzie.
//
// KONTROLER JEST CIENKI: pobieranie i zapis siedzą w Models\DeviceImport,
// rozmowa z API w Utils\DeviceApi. Tutaj zostaje warstwa HTTP — CSRF, `state`
// OAuth, tłumaczenie błędów na polskie zdania i przekierowania (PRG).
//
// WYJĄTEK: `import()` w trybie strumieniowym (Utils\ProgressStream, 2026-08-26,
// żywy status „Pobierz zaznaczone", WSPÓLNY z GarminController) kończy się
// OSTATNIM zdarzeniem NDJSON niosącym adres, a nie nagłówkiem Location — PRG
// zostaje zachowane, tylko przekierowanie robi JS po stronie klienta.
//
// TOKENÓW NIE MA W ADRESIE ANI W LOGU. Kod autoryzacyjny przychodzi w adresie
// (tak działa OAuth) i ginie razem z przekierowaniem; token ląduje wyłącznie
// zaszyfrowany w bazie (Models\DeviceConnection).
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\DeviceConnection;
use Models\DeviceImport;
use Utils\DeviceApi;
use Utils\ProgressStream;
use Utils\RateLimiter;
use Utils\View;

class DeviceController
{
    /** Ile minut lista pobrana od dostawcy jest ważna w sesji. */
    private const LIST_TTL_MINUTES = 30;

    /** Rozpoczyna autoryzację: POST u nas (CSRF) -> przekierowanie do dostawcy. */
    public static function connect(string $provider): void
    {
        $user = self::guard($provider);

        // `state` to jedyna ochrona przed podrzuceniem cudzego kodu na powrocie
        // (CSRF na callbacku, który z definicji jest zwykłym GET-em). Losowy,
        // jednorazowy, sprawdzany i kasowany w callback().
        $state = bin2hex(random_bytes(16));
        $_SESSION['device_oauth'] = ['provider' => $provider, 'state' => $state, 'at' => time()];

        try {
            $url = DeviceApi::authorizeUrl($provider, $state);
        } catch (\RuntimeException $e) {
            error_log('OAuth ' . $provider . ': ' . $e->getMessage());
            self::back($provider, 'gblad=brak-kluczy');
        }

        header('Location: ' . $url);
        exit;
    }

    /** Powrót od dostawcy — tu powstaje połączenie. */
    public static function callback(string $provider): void
    {
        Auth::requireLogin();
        if (!DeviceApi::isKnown($provider)) {
            http_response_code(404);
            exit;
        }
        $user = Auth::user();

        $oczekiwany = $_SESSION['device_oauth'] ?? null;
        unset($_SESSION['device_oauth']);
        // Zgodność `state` I dostawcy: sam losowy ciąg nie mówi, do KOGO
        // wracamy, a token Polara zapisany jako Wahoo byłby cichą awarią.
        if (!is_array($oczekiwany)
            || ($oczekiwany['provider'] ?? '') !== $provider
            || !hash_equals((string) ($oczekiwany['state'] ?? ''), (string) ($_GET['state'] ?? ''))) {
            self::back($provider, 'gblad=sesja');
        }

        // Człowiek mógł kliknąć „odmawiam" u dostawcy — to nie jest błąd,
        // tylko decyzja, i ma dostać zdanie, a nie komunikat o awarii.
        if (!empty($_GET['error']) || empty($_GET['code'])) {
            self::back($provider, 'gblad=odmowa');
        }

        try {
            $token = DeviceApi::exchangeCode($provider, (string) $_GET['code']);
            // Polar wymaga jednorazowej rejestracji użytkownika u siebie, zanim
            // cokolwiek odda; pozostali zwracają tu null i nic się nie dzieje.
            $externalId = DeviceApi::registerUser($provider, $token);
        } catch (\RuntimeException $e) {
            error_log('OAuth ' . $provider . ': ' . $e->getMessage());
            self::back($provider, 'gblad=polaczenie');
        }

        DeviceConnection::connect(
            $user->id,
            $provider,
            $token,
            null,
            null,
            $externalId
        );
        unset($_SESSION['device_lista'][$provider]);

        self::back($provider, 'ginfo=polaczono');
    }

    public static function refresh(string $provider): void
    {
        $user = self::guard($provider);

        try {
            $wynik = DeviceImport::candidates($user->id, $provider);
        } catch (\RuntimeException $e) {
            error_log('Lista ' . $provider . ': ' . $e->getMessage());
            self::back($provider, 'gblad=polaczenie');
        }

        // Lista w SESJI, nie w bazie: to wynik jednego zapytania do cudzego
        // serwisu, a nie fakt o użytkowniku. W bazie ląduje dopiero to, co
        // faktycznie zaimportował (device_activities).
        $_SESSION['device_lista'][$provider] = ['at' => time(), 'items' => $wynik['items']];

        self::back($provider, 'ginfo=lista&nowe=' . count($wynik['items']) . '&sprawdzono=' . $wynik['seen']);
    }

    public static function import(string $provider): void
    {
        $user = self::guard($provider);

        $wybrane = $_POST['aktywnosc'] ?? [];
        if (!is_array($wybrane) || !$wybrane) {
            self::back($provider, 'gblad=nic-nie-zaznaczono');
        }

        // Metadane bierzemy z listy w sesji, nie z formularza — z formularza
        // przychodzi WYŁĄCZNIE identyfikator, czyli jedyna rzecz, którą i tak
        // weryfikuje dostawca przy pobieraniu pliku.
        $meta = [];
        foreach (self::pendingList($provider) as $item) {
            $meta[(string) $item['id']] = $item;
        }

        // ŻYWY STATUS, WSPÓLNY Z GARMINEM (zgłoszenie usera, 2026-08-26) —
        // ten sam mechanizm co GarminController::import(), patrz komentarz tam
        // i Utils\ProgressStream. To jest ta „uniwersalność": każdy kolejny
        // dostawca dodany tędy (Polar, Wahoo, przyszli) dostaje żywy postęp za
        // darmo, bo idzie tym samym kontrolerem i tym samym modelem.
        $streaming = ProgressStream::requested();
        if ($streaming) {
            ProgressStream::start();
        }
        $onProgress = $streaming ? static function (array $event): void {
            ProgressStream::emit($event);
        } : null;

        try {
            $wynik = DeviceImport::import($user->id, $provider, array_map('strval', $wybrane), $meta, $onProgress);
        } catch (\RuntimeException $e) {
            error_log('Import ' . $provider . ': ' . $e->getMessage());
            self::finish($provider, $streaming, 'gblad=polaczenie');
        }

        $zrobione = array_flip(array_map('strval', $wybrane));
        $_SESSION['device_lista'][$provider]['items'] = array_values(array_filter(
            self::pendingList($provider),
            static fn(array $item): bool => !isset($zrobione[(string) $item['id']])
        ));

        self::finish($provider, $streaming, http_build_query([
            'ginfo'     => 'import',
            'dodane'    => $wynik['dodane'],
            'pola'      => $wynik['pola'],
            'duplikaty' => $wynik['duplikaty'],
            'odrzucone' => $wynik['odrzucone'],
            'pozostalo' => $wynik['pozostalo'],
        ]));
    }

    /**
     * Przełącznik „dodawaj nowe przejazdy automatycznie" (migr. 088).
     *
     * Decyzja usera: automat istnieje, ale jest DOMYŚLNIE WYŁĄCZONY i włącza go
     * sam człowiek — każdy ślad to punkty i publiczne pola na mapie odkryć.
     * Włączenie sprawdza to, czego potem webhook nie ma już jak sprawdzić:
     * że dostawca umie powiedzieć, czyje to konto, i że dostał na to zgodę.
     */
    public static function autoImport(string $provider): void
    {
        $user = self::guard($provider);
        $conn = DeviceConnection::find($user->id, $provider);
        if ($conn === null) {
            self::back($provider, 'gblad=polaczenie');
        }

        $wlacz = ($_POST['wlacz'] ?? '0') === '1';
        if (!$wlacz) {
            DeviceConnection::setAutoImport($user->id, $provider, false);
            self::back($provider, 'ginfo=automat-wylaczony');
        }

        if (!DeviceApi::webhookReady($provider)) {
            self::back($provider, 'gblad=automat-niedostepny');
        }

        $token = DeviceConnection::token($user->id, $provider);
        // Wahoo połączone przed migr. 088 nie ma zakresu `offline_data`, bez
        // którego webhook dla tej osoby po prostu nie przychodzi. Włączenie
        // przełącznika bez tego byłoby obietnicą, która nigdy się nie spełni.
        if ($token === null || DeviceApi::missingWebhookScopes($provider, $token) !== []) {
            self::back($provider, 'gblad=automat-ponownie');
        }

        // Identyfikator u dostawcy jest JEDYNYM, po czym webhook trafi do
        // właściwego konta. Konta Wahoo sprzed migracji go nie mają — dopisujemy.
        if (empty($conn['externalUserId'])) {
            try {
                $externalId = DeviceApi::registerUser($provider, $token);
            } catch (\RuntimeException $e) {
                error_log('Automat ' . $provider . ': ' . $e->getMessage());
                self::back($provider, 'gblad=polaczenie');
            }
            if (empty($externalId)) {
                self::back($provider, 'gblad=automat-ponownie');
            }
            DeviceConnection::setExternalUserId($user->id, $provider, $externalId);
        }

        DeviceConnection::setAutoImport($user->id, $provider, true);
        self::back($provider, 'ginfo=automat-wlaczony');
    }

    public static function disconnect(string $provider): void
    {
        $user = self::guard($provider);

        DeviceConnection::disconnect($user->id, $provider);
        unset($_SESSION['device_lista'][$provider]);

        self::back($provider, 'ginfo=odlaczono');
    }

    /**
     * Lista z sesji dla widoku, z wygaszaniem po LIST_TTL_MINUTES.
     * Stara lista to zła lista: u dostawcy mogło w międzyczasie przybyć
     * przejazdów, a stąd nie widać których.
     *
     * @return list<array<string,mixed>>
     */
    public static function pendingList(string $provider): array
    {
        $lista = $_SESSION['device_lista'][$provider] ?? null;
        if (!is_array($lista) || (time() - (int) ($lista['at'] ?? 0)) > self::LIST_TTL_MINUTES * 60) {
            unset($_SESSION['device_lista'][$provider]);
            return [];
        }

        return $lista['items'] ?? [];
    }

    private static function guard(string $provider): object
    {
        Auth::requireLogin();

        if (!DeviceApi::isKnown($provider) || !DeviceApi::isUsable($provider)) {
            // Dostawca bez adaptera albo bez kluczy — zakładka i tak nie
            // pokazuje przycisku, więc to jest zabezpieczenie na strzał wprost.
            http_response_code(404);
            exit;
        }
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back($provider, 'gblad=sesja');
        }
        // Każde kliknięcie to zapytanie do CUDZEGO API — limit chroni i nas
        // (przed zawieszeniem żądaniami), i konto u dostawcy przed blokadą.
        if (RateLimiter::tooMany('device:' . $provider . ':' . Auth::user()->id, 30, 600)) {
            self::back($provider, 'gblad=limit');
        }

        return Auth::user();
    }

    private static function back(string $provider, string $query): never
    {
        header('Location: ' . self::redirectUrl($provider, $query));
        exit;
    }

    /**
     * Koniec żądania — patrz GarminController::finish(), ten sam powód: w
     * trybie strumieniowym nagłówki poszły razem z pierwszym flush(), więc
     * przekierowanie leci jako OSTATNIE zdarzenie NDJSON, a nie Location.
     */
    private static function finish(string $provider, bool $streaming, string $query): never
    {
        if ($streaming) {
            ProgressStream::emit(['type' => 'done', 'redirect' => self::redirectUrl($provider, $query)]);
            exit;
        }

        self::back($provider, $query);
    }

    private static function redirectUrl(string $provider, string $query): string
    {
        return View::url('/admin/moje-przejazdy')
            . '?zrodlo=' . urlencode($provider) . '&' . $query . '#dodaj';
    }
}
