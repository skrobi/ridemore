<?php
// core/Controllers/GarminController.php
// GARMIN CONNECT NA EKRANIE „MOJE PRZEJAZDY" (migr. 068).
//
// Cztery czynności, wszystkie POST-em i wszystkie wracające na ten sam ekran:
// połącz konto, odśwież listę nowych aktywności, zaimportuj zaznaczone, odłącz.
//
// KONTROLER JEST CIENKI, tak jak SoloRideController: logowanie i pobieranie
// siedzi w Models\GarminImport / Models\DeviceConnection, a te wołają jeden most
// do Pythona (Utils\GarminBridge). Tutaj zostaje to, co należy do warstwy
// HTTP: CSRF, limit prób logowania, zamiana kodu błędu na zdanie po polsku
// i przekierowanie (PRG — po POST-cie zawsze przekierowanie, żeby odświeżenie
// strony nie powtarzało operacji).
//
// WYJĄTEK: `import()` w trybie strumieniowym (Utils\ProgressStream, 2026-08-26,
// żywy status „Pobierz zaznaczone") kończy się OSTATNIM zdarzeniem NDJSON
// niosącym adres, a nie nagłówkiem Location — PRG i tak zostaje zachowane,
// tylko przekierowanie robi JS po stronie klienta zamiast przeglądarki.
//
// HASŁO NIE JEST NIGDZIE ZAPISYWANE. Przychodzi POST-em, jedzie stdin-em do
// procesu Pythona i ginie razem z nim — nie ląduje ani w sesji, ani w bazie,
// ani w adresie (dlatego wszystkie komunikaty to KODY, nie treści).
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\DeviceConnection;
use Models\GarminBridgeError;
use Models\GarminImport;
use Utils\GarminBridge;
use Utils\ProgressStream;
use Utils\RateLimiter;
use Utils\View;

class GarminController
{
    /** Ile minut lista pobrana z Garmina jest ważna w sesji. */
    private const LIST_TTL_MINUTES = 30;

    public static function connect(): void
    {
        [$user] = self::guard();

        $email = trim((string) ($_POST['garmin_email'] ?? ''));
        $password = (string) ($_POST['garmin_haslo'] ?? '');
        $code = trim((string) ($_POST['garmin_kod'] ?? ''));

        if ($email === '' || $password === '') {
            self::back('gblad=brak-danych');
        }

        // Logowanie do CUDZEGO serwisu naszymi rękami: bez limitu ktoś mógłby
        // użyć tego ekranu do zgadywania haseł do Garmina (i ściągnąć na nasz
        // adres IP blokadę po ich stronie). Limit jest per konto ridemore.
        if (RateLimiter::tooMany('garmin-login:' . $user->id, 5, 600)) {
            self::back('gblad=limit');
        }

        try {
            $answer = GarminBridge::call('login', [
                'email'    => $email,
                'password' => $password,
                'mfaCode'  => $code,
            ]);
        } catch (\RuntimeException $e) {
            error_log('Garmin login: ' . $e->getMessage());
            self::back('gblad=most');
        }

        if (($answer['ok'] ?? false) !== true) {
            error_log('Garmin login: ' . ($answer['error'] ?? __('nieznany błąd')));
            self::back('gblad=' . self::reasonSlug((string) ($answer['code'] ?? 'error')));
        }

        // Wspólna tabela dostawców (migr. 069). Garmin nie ma OAuth, więc jego
        // token sesji jedzie pod kluczem `session` — patrz GarminImport::sessionToken.
        DeviceConnection::connect(
            $user->id,
            'garmin',
            ['session' => (string) ($answer['data']['tokens'] ?? '')],
            $email,
            (string) ($answer['data']['displayName'] ?? '') ?: null
        );
        unset($_SESSION['garmin_lista']);

        self::back('ginfo=polaczono');
    }

    public static function disconnect(): void
    {
        [$user] = self::guard();

        DeviceConnection::disconnect($user->id, 'garmin');
        unset($_SESSION['garmin_lista']);

        self::back('ginfo=odlaczono');
    }

    /** Pobiera z Garmina listę aktywności rowerowych, których jeszcze nie mamy. */
    public static function refresh(): void
    {
        [$user] = self::guard();

        // OD KTÓREGO MIEJSCA HISTORII (przycisk „Szukaj starszych"). Zwykłe
        // kliknięcie „Pobierz aktywności" zaczyna od zera, bo najczęściej szuka
        // się tego, co przybyło od ostatniego razu.
        $start = max(0, (int) ($_POST['start'] ?? 0));

        try {
            $wynik = GarminImport::candidates($user->id, $start);
        } catch (GarminBridgeError $e) {
            self::back('gblad=' . self::expiredOrReason($user->id, $e->reason));
        } catch (\RuntimeException $e) {
            error_log('Garmin lista: ' . $e->getMessage());
            self::back('gblad=most');
        }

        // Lista trzymana w SESJI, nie w bazie: to jest wynik jednego zapytania
        // do cudzego serwisu, a nie fakt o użytkowniku. W bazie ląduje dopiero
        // to, co faktycznie zaimportował (device_activities).
        // „SZUKAJ STARSZYCH" DOKŁADA, NIE ZASTĘPUJE. Człowiek klika ten przycisk
        // właśnie dlatego, że to, co widzi, mu nie wystarcza — podmiana listy
        // zabrałaby mu z ekranu (i z zaznaczenia) wszystko, co już znalazł.
        // Zwykłe „Pobierz aktywności" (start = 0) zaczyna od czysta, bo to jest
        // pytanie „co przybyło od ostatniego razu".
        $items = $wynik['items'];
        if ($start > 0) {
            $stare = self::pendingList();
            $znane = array_flip(array_map(static fn(array $i): string => (string) $i['id'], $stare));
            foreach ($items as $item) {
                if (!isset($znane[(string) $item['id']])) {
                    $stare[] = $item;
                }
            }
            $items = $stare;
        }

        // `next` wędruje razem z listą: przycisk „Szukaj starszych" musi wiedzieć,
        // dokąd doszliśmy, a adres nie jest miejscem na stan przeglądania.
        $_SESSION['garmin_lista'] = [
            'at'    => time(),
            'items' => $items,
            'next'  => (int) $wynik['next'],
        ];

        self::back(http_build_query([
            'ginfo'      => 'lista',
            'nowe'       => count($wynik['items']),
            'rowerowych' => $wynik['seen'],
            'sprawdzono' => $wynik['scanned'],
            'od'         => $wynik['start'],
        ]));
    }

    public static function import(): void
    {
        [$user] = self::guard();

        $wybrane = $_POST['aktywnosc'] ?? [];
        if (!is_array($wybrane) || !$wybrane) {
            self::back('gblad=nic-nie-zaznaczono');
        }

        // Metadane (nazwa, data, dystans) bierzemy z listy w sesji, nie z formularza
        // — z formularza przychodzi WYŁĄCZNIE identyfikator, czyli jedyna rzecz,
        // którą i tak weryfikuje Garmin przy pobieraniu śladu.
        $meta = [];
        foreach (($_SESSION['garmin_lista']['items'] ?? []) as $item) {
            $meta[(string) $item['id']] = $item;
        }

        // ŻYWY STATUS (zgłoszenie usera, 2026-08-26): włącza się WYŁĄCZNIE, gdy
        // JS po drugiej stronie o to poprosił — bez niego formularz zachowuje
        // się dokładnie jak dawniej (patrz Utils\ProgressStream).
        $streaming = ProgressStream::requested();
        if ($streaming) {
            ProgressStream::start();
        }
        $onProgress = $streaming ? static function (array $event): void {
            ProgressStream::emit($event);
        } : null;

        try {
            $wynik = GarminImport::import($user->id, array_map('strval', $wybrane), $meta, $onProgress);
        } catch (GarminBridgeError $e) {
            self::finish($streaming, 'gblad=' . self::expiredOrReason($user->id, $e->reason));
        } catch (\RuntimeException $e) {
            error_log('Garmin import: ' . $e->getMessage());
            self::finish($streaming, 'gblad=most');
        }

        // Zaimportowane znikają z listy w sesji — ekran ma pokazywać to, co
        // ZOSTAŁO, a nie to, co przed chwilą weszło (od tego są liczniki).
        $zrobione = array_flip(array_map('strval', $wybrane));
        $_SESSION['garmin_lista']['items'] = array_values(array_filter(
            $_SESSION['garmin_lista']['items'] ?? [],
            static fn(array $item): bool => !isset($zrobione[(string) $item['id']])
        ));

        self::finish($streaming, http_build_query([
            'ginfo'     => 'import',
            'dodane'    => $wynik['dodane'],
            'pola'      => $wynik['pola'],
            'duplikaty' => $wynik['duplikaty'],
            'odrzucone' => $wynik['odrzucone'],
            'pozostalo' => $wynik['pozostalo'],
        ]));
    }

    /**
     * Lista z sesji dla widoku — razem z wygaszaniem po LIST_TTL_MINUTES.
     * Stara lista to zła lista: w Garminie mogło w międzyczasie przybyć
     * przejazdów, a stąd nie widać których.
     *
     * @return list<array{id:string,name:string,startedAt:string,distanceKm:float}>
     */
    public static function pendingList(): array
    {
        $lista = $_SESSION['garmin_lista'] ?? null;
        if (!is_array($lista) || (time() - (int) ($lista['at'] ?? 0)) > self::LIST_TTL_MINUTES * 60) {
            unset($_SESSION['garmin_lista']);
            return [];
        }

        return $lista['items'] ?? [];
    }

    /** Od którego miejsca historii szukać starszych — 0 = nie ma dalej. */
    public static function pendingNext(): int
    {
        return (int) ($_SESSION['garmin_lista']['next'] ?? 0);
    }

    /** @return array{0:object} */
    private static function guard(): array
    {
        Auth::requireLogin();

        if (!GarminBridge::available()) {
            // Środowisko bez Pythona (produkcja) — formularz i tak się nie
            // pokazuje, więc to jest zabezpieczenie na wypadek strzału wprost.
            http_response_code(404);
            exit;
        }
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back('gblad=sesja');
        }

        return [Auth::user()];
    }

    /**
     * To samo co reasonSlug(), ale dla czynności robionych ZAPISANYM tokenem.
     *
     * „auth" znaczy tu co innego niż przy logowaniu: nie „złe hasło", tylko
     * „token, który mieliśmy, przestał być ważny". Zapisane połączenie od razu
     * kasujemy — inaczej ekran dalej pokazywałby „Połączono jako…" i przycisk,
     * który nie ma prawa zadziałać, a formularz logowania (jedyne wyjście
     * z tej sytuacji) w ogóle by się nie pojawił.
     */
    private static function expiredOrReason(int $userId, string $code): string
    {
        if ($code !== 'auth') {
            return self::reasonSlug($code);
        }

        DeviceConnection::disconnect($userId, 'garmin');
        unset($_SESSION['garmin_lista']);

        return 'wygaslo';
    }

    /** Kod przyczyny z mostu -> krótki slug w adresie (widok ma własne teksty). */
    private static function reasonSlug(string $code): string
    {
        return match ($code) {
            'mfa'   => 'kod-2fa',
            'auth'  => 'logowanie',
            'rate'  => 'limit-garmin',
            'conn'  => 'polaczenie',
            'setup' => 'srodowisko',
            default => 'most',
        };
    }

    /**
     * Powrót na ekran „Moich przejazdów" — ZAWSZE na zakładkę Garmina.
     *
     * BŁĄD ZGŁOSZONY PRZEZ USERA (2026-08-24): „widzi 16 do pobrania, a na
     * liście nic nie mam, w ogóle przeniosło mnie do zakładki pliku". Przyczyna
     * była tutaj: adres nie niósł `zrodlo`, więc karta wracała do zakładki
     * domyślnej (Plik). Komunikat „16 nowych" pokazywał się poprawnie, bo stoi
     * NAD zakładkami i jest wspólny — ale sama lista rysuje się tylko w panelu
     * Garmina, którego w tym momencie nie było na ekranie. Jeden brakujący
     * parametr, dwa objawy wyglądające na dwie różne usterki.
     *
     * Kotwica `#dodaj`, nie `#garmin`: id karty zmieniło się przy przebudowie
     * na zakładki (partials/ride-sources.php), a kotwica, która nigdzie nie
     * prowadzi, zostawia człowieka na górze strony.
     */
    private static function back(string $query): never
    {
        header('Location: ' . self::redirectUrl($query));
        exit;
    }

    /**
     * Koniec żądania — dwie drogi na to samo miejsce. Bez trybu strumieniowego
     * (albo gdy błąd wysypał się PRZED ProgressStream::start()) to zwykłe
     * przekierowanie, jak zawsze. W trybie strumieniowym `header('Location')`
     * już nie zadziała — nagłówki poszły razem z pierwszym flush() — więc JS
     * dostaje gotowy adres w OSTATNIM zdarzeniu i sam na niego przechodzi.
     */
    private static function finish(bool $streaming, string $query): never
    {
        if ($streaming) {
            ProgressStream::emit(['type' => 'done', 'redirect' => self::redirectUrl($query)]);
            exit;
        }

        self::back($query);
    }

    private static function redirectUrl(string $query): string
    {
        return View::url('/admin/moje-przejazdy') . '?zrodlo=garmin&' . $query . '#dodaj';
    }
}
