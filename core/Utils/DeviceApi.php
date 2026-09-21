<?php
// core/Utils/DeviceApi.php
// LICZNIKI PRZEZ OAuth2 — Polar, Wahoo (+ miejsce na COROS i Suunto).
//
// DLACZEGO TO NIE JEST DRUGI MOST DO PYTHONA. Garmin nie ma API dla kont
// osobistych, więc tam trzeba było logować się jak aplikacja mobilna (patrz
// Utils\GarminBridge) — działa tylko tam, gdzie jest Python/proc_open (od
// 2026-09-04 to też produkcja, patrz core/config.php — inny, starszy zestaw
// pakietów niż na dev). Pozostali producenci mają zwykły OAuth2 po HTTPS —
// czyli PHP, czyli działa WSZĘDZIE bez żadnych dodatkowych warunków
// środowiskowych. To jest najważniejsza różnica między tym plikiem a tamtym.
//
// DLACZEGO WŁASNY KLIENT OAuth, SKORO MAMY league/oauth2-client. Bo dostawcy
// różnią się dokładnie w tych miejscach, w których `GenericProvider` ma jedną
// drogę: Polar wymaga HTTP Basic przy wymianie kodu i zwraca własne
// `x_user_id`, Wahoo posyła dane klienta w ciele żądania. Trzydzieści linii
// cURL-a jest tu uczciwsze niż naginanie cudzej abstrakcji — a logowanie
// SPOŁECZNOŚCIOWE (Google/Strava) dalej idzie przez `Utils\OAuthProvider`
// i nie ma z tym plikiem nic wspólnego.
//
// KTÓRZY DOSTAWCY SĄ TU NAPRAWDĘ. Adapter powstaje tylko dla API z PUBLICZNĄ
// dokumentacją, w której da się sprawdzić adresy:
//   * Polar AccessLink — dokumentacja jawna, rejestracja klienta samoobsługowa,
//   * Wahoo Cloud API  — dokumentacja jawna, klucze po akceptacji wniosku.
// COROS i Suunto mają zakładki w interfejsie, ale nie mają adapterów: COROS
// udostępnia specyfikację dopiero po onboardingu partnerskim, a Suunto trzyma
// ją za rejestracją w APIzone. Zgadywanie endpointów skończyłoby się kodem,
// który wygląda na gotowy i wywala się przy pierwszym prawdziwym koncie —
// więc zakładka mówi wprost, czego brakuje.
//
// WEBHOOKI — AUTOMATYCZNY IMPORT (migr. 088, 2026-09-14). Decyzja usera: „tylko
// to, co oficjalne". Polar i Wahoo SAMI zawiadamiają nas o nowym treningu, więc
// nie ma tu żadnego odpytywania z crona — i nie ma go też dla Garmina, który
// takiego kanału bez programu partnerskiego nie daje. Uwierzytelnienie jest
// różne, ale oba warianty mieszkają tutaj (`verifyWebhook`), bo to jest wiedza
// o dostawcy, nie o HTTP:
//   * Polar — HMAC-SHA256 całej treści żądania, hex, w `Polar-Webhook-Signature`;
//     sekret dostajemy JEDEN RAZ przy zakładaniu webhooka (`polar_webhook.php`),
//   * Wahoo — `webhook_token` w treści, wpisany przez nas w panelu aplikacji.
// Webhook niesie wyłącznie identyfikatory (Polar) albo podsumowanie (Wahoo);
// ślad i tak ściągamy tą samą drogą co przy imporcie z listy.
namespace Utils;

class DeviceApi
{
    /**
     * Wszystko, co odróżnia dostawców od siebie, w jednym miejscu.
     *
     * `ready` = mamy adapter i znamy adresy. `note` = zdanie dla człowieka
     * w zakładce, także wtedy (a właściwie zwłaszcza wtedy), gdy czegoś nie ma.
     */
    private const META = [
        'garmin' => [
            'label'     => 'Garmin',
            'ready'     => true,
            'oauth'     => false, // logowanie hasłem przez most Pythona, patrz GarminBridge
            'note'      => 'Logowanie e-mailem i hasłem, przez nieoficjalną bibliotekę. Wymaga Pythona '
                         . 'po stronie serwera — nie w każdym środowisku jest skonfigurowany.',
        ],
        'polar' => [
            'label'     => 'Polar',
            'ready'     => true,
            'oauth'     => true,
            'authorize' => 'https://flow.polar.com/oauth2/authorization',
            'token'     => 'https://polarremote.com/v2/oauth2/token',
            'tokenAuth' => 'basic',   // Polar: dane klienta w nagłówku Authorization
            'scope'     => 'accesslink.read_all',
            'api'       => 'https://www.polaraccesslink.com',
            'format'    => 'gpx',
            'webhook'   => true,      // AccessLink: webhook EXERCISE (jeden na klienta API)
            'note'      => 'Polar odda WYŁĄCZNIE przejazdy nagrane po podłączeniu konta '
                         . '(i tylko z ostatnich ok. 30 dni) — tak działa AccessLink. '
                         . 'Starszą historię wgraj plikami.',
        ],
        'wahoo' => [
            'label'     => 'Wahoo',
            'ready'     => true,
            'oauth'     => true,
            'authorize' => 'https://api.wahooligan.com/oauth/authorize',
            'token'     => 'https://api.wahooligan.com/oauth/token',
            'tokenAuth' => 'body',
            // `offline_data` — bez tego zakresu Wahoo NIE wysyła webhooka
            // `workout_summary` dla danego człowieka (migr. 088). Konta
            // połączone wcześniej go nie mają i muszą połączyć się ponownie,
            // żeby włączyć automat (patrz DeviceController::autoImport).
            'scope'     => 'user_read workouts_read offline_data',
            'api'       => 'https://api.wahooligan.com',
            'format'    => 'fit',
            'webhook'   => true,      // workout_summary, adres i token w panelu aplikacji Wahoo
            'note'      => 'Wahoo oddaje pliki FIT — konwertujemy je u nas na wejściu.',
        ],
        'coros' => [
            'label' => 'COROS',
            'ready' => false,
            'oauth' => true,
            'note'  => 'COROS udostępnia specyfikację API dopiero po onboardingu partnerskim '
                     . '(formularz w ich centrum pomocy). Do czasu, aż przejdziemy ten proces '
                     . 'i poznamy prawdziwe adresy, nie ma tu czego podłączyć — pliki FIT '
                     . 'z zegarka wgrasz zakładką „Plik".',
        ],
        'suunto' => [
            'label' => 'Suunto',
            'ready' => false,
            'oauth' => true,
            'note'  => 'Suunto trzyma dokumentację API za rejestracją w APIzone i wymaga podpisania '
                     . 'umowy partnerskiej. Zanim to zrobimy, nie znamy adresów, pod które mielibyśmy '
                     . 'pytać — pliki FIT z zegarka wgrasz zakładką „Plik".',
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return self::META;
    }

    public static function isKnown(string $provider): bool
    {
        return isset(self::META[$provider]);
    }

    public static function meta(string $provider): array
    {
        if (!isset(self::META[$provider])) {
            throw new \RuntimeException('Nieznany dostawca: ' . $provider);
        }

        return self::META[$provider];
    }

    public static function label(string $provider): string
    {
        return (string) (self::META[$provider]['label'] ?? $provider);
    }

    /** Czy dostawca ma adapter I klucze — dopiero wtedy przycisk ma prawo działać. */
    public static function isUsable(string $provider): bool
    {
        $meta = self::META[$provider] ?? null;
        if (!$meta || empty($meta['ready'])) {
            return false;
        }
        if (empty($meta['oauth'])) {
            // Garmin: nie OAuth, o jego dostępności decyduje most do Pythona.
            return GarminBridge::available();
        }

        return self::credentials($provider) !== null;
    }

    /** @return array{id:string,secret:string}|null */
    public static function credentials(string $provider): ?array
    {
        $c = APP_CONFIG['devices'][$provider] ?? null;
        if (!is_array($c) || empty($c['clientId']) || empty($c['clientSecret'])) {
            return null;
        }

        return ['id' => (string) $c['clientId'], 'secret' => (string) $c['clientSecret']];
    }

    /**
     * Adres powrotny musi być IDENTYCZNY przy autoryzacji i przy wymianie kodu
     * (dostawcy porównują go znak po znaku) — i taki sam, jak wpisany w panelu
     * dostawcy przy zakładaniu klienta API.
     *
     * Składany z `app_url`, a NIE przez `View::url()`: `app_url` na dev to już
     * `http://localhost/ridemore`, a `View::url()` dokleiłoby `/ridemore`
     * DRUGI RAZ (zmierzone: .../ridemore/ridemore/admin/...).
     */
    public static function redirectUri(string $provider): string
    {
        return rtrim((string) APP_CONFIG['app_url'], '/')
            . '/admin/moje-przejazdy/licznik/' . $provider . '/callback';
    }

    public static function authorizeUrl(string $provider, string $state): string
    {
        $meta = self::meta($provider);
        $cred = self::credentials($provider);
        if ($cred === null) {
            throw new \RuntimeException('Brak kluczy API dla: ' . $meta['label']);
        }

        return $meta['authorize'] . '?' . http_build_query([
            'client_id'     => $cred['id'],
            'response_type' => 'code',
            'redirect_uri'  => self::redirectUri($provider),
            'scope'         => $meta['scope'],
            'state'         => $state,
        ]);
    }

    /**
     * Kod autoryzacyjny -> komplet tokenów.
     *
     * @return array<string,mixed> pola dostawcy + `obtained_at` (do liczenia ważności)
     */
    public static function exchangeCode(string $provider, string $code): array
    {
        $meta = self::meta($provider);
        $cred = self::credentials($provider);
        if ($cred === null) {
            throw new \RuntimeException('Brak kluczy API dla: ' . $meta['label']);
        }

        $body = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirectUri($provider),
        ];
        $headers = ['Accept: application/json'];
        if (($meta['tokenAuth'] ?? 'body') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode($cred['id'] . ':' . $cred['secret']);
        } else {
            $body['client_id'] = $cred['id'];
            $body['client_secret'] = $cred['secret'];
        }

        $answer = self::http('POST', $meta['token'], $headers, http_build_query($body));
        $token = json_decode($answer['body'], true);
        if (!is_array($token) || empty($token['access_token'])) {
            throw new \RuntimeException('Dostawca nie oddał tokenu (HTTP ' . $answer['status'] . ').');
        }
        $token['obtained_at'] = time();

        return $token;
    }

    /**
     * Odświeżenie tokenu, gdy wygasł (albo wygaśnie za chwilę).
     *
     * @return array<string,mixed>|null nowy token albo null, gdy stary wystarcza
     */
    public static function refreshIfNeeded(string $provider, array $token): ?array
    {
        $expires = (int) ($token['expires_in'] ?? 0);
        $obtained = (int) ($token['obtained_at'] ?? 0);
        // Margines 120 s: token, który wygaśnie w trakcie pobierania listy,
        // jest z naszego punktu widzenia już wygasły.
        if ($expires <= 0 || $obtained <= 0 || time() < $obtained + $expires - 120) {
            return null;
        }
        if (empty($token['refresh_token'])) {
            throw new \RuntimeException(__('Token wygasł, a dostawca nie dał tokenu odświeżającego.'));
        }

        $meta = self::meta($provider);
        $cred = self::credentials($provider);
        if ($cred === null) {
            throw new \RuntimeException('Brak kluczy API dla: ' . $meta['label']);
        }

        $body = ['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token']];
        $headers = ['Accept: application/json'];
        if (($meta['tokenAuth'] ?? 'body') === 'basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode($cred['id'] . ':' . $cred['secret']);
        } else {
            $body['client_id'] = $cred['id'];
            $body['client_secret'] = $cred['secret'];
        }

        $answer = self::http('POST', $meta['token'], $headers, http_build_query($body));
        $nowy = json_decode($answer['body'], true);
        if (!is_array($nowy) || empty($nowy['access_token'])) {
            throw new \RuntimeException('Nie udało się odświeżyć tokenu (HTTP ' . $answer['status'] . ').');
        }
        $nowy['obtained_at'] = time();
        // Niektórzy dostawcy nie odsyłają refresh_tokenu przy odświeżeniu —
        // stary zostaje ważny i musi przeżyć nadpisanie.
        $nowy['refresh_token'] = $nowy['refresh_token'] ?? $token['refresh_token'];

        return $nowy;
    }

    /**
     * Rejestracja użytkownika u dostawcy i jego identyfikator po tamtej stronie.
     *
     * AccessLink wymaga tego kroku raz, zanim cokolwiek odda, i zwraca własny
     * identyfikator, którym adresuje się potem każde zapytanie. `409` znaczy
     * „ten człowiek już jest zarejestrowany" i jest poprawnym wynikiem, nie błędem.
     *
     * Wahoo rejestracji nie wymaga, ale od automatycznego importu (migr. 088)
     * potrzebujemy JEGO identyfikatora: webhook podaje `user.id` Wahoo, nie nasz,
     * i bez zapamiętanego id nie ma jak trafić do właściwego konta.
     *
     * @return ?string identyfikator nadany przez dostawcę
     */
    public static function registerUser(string $provider, array $token): ?string
    {
        if ($provider === 'wahoo') {
            return self::wahooUserId($token);
        }
        if ($provider !== 'polar') {
            return null;
        }

        $meta = self::meta('polar');
        $userId = (string) ($token['x_user_id'] ?? '');
        $answer = self::http(
            'POST',
            $meta['api'] . '/v3/users',
            [
                'Authorization: Bearer ' . $token['access_token'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            json_encode(['member-id' => 'ridemore-' . ($userId !== '' ? $userId : bin2hex(random_bytes(6)))])
        );

        if ($answer['status'] === 409 || $answer['status'] === 200 || $answer['status'] === 201) {
            $dane = json_decode($answer['body'], true);
            return (string) ($dane['polar-user-id'] ?? $userId) ?: ($userId ?: null);
        }

        throw new \RuntimeException('Polar odrzucił rejestrację użytkownika (HTTP ' . $answer['status'] . ').');
    }

    /**
     * Lista aktywności do wyboru.
     *
     * ŚWIADOMIE BEZ FILTRA „TYLKO ROWER" u dostawców, u których nie mamy pewnej
     * mapy rodzajów treningu (Garmin ją mamy, patrz ai-engine/garmin.py). Lista
     * jest tu FORMULARZEM WYBORU z zaznaczaniem, więc pokazanie biegu z podpisem
     * „bieganie" jest uczciwsze niż odsianie po zgadywanym kluczu i milczenie
     * o tym, że coś zniknęło.
     *
     * @return list<array{id:string,name:string,startedAt:string,distanceKm:float,type:string}>
     */
    public static function listActivities(string $provider, array $token, ?string $externalUserId, int $limit = 50): array
    {
        return match ($provider) {
            'polar' => self::polarList($token, $externalUserId, $limit),
            'wahoo' => self::wahooList($token, $limit),
            default => throw new \RuntimeException('Ten dostawca nie ma jeszcze adaptera: ' . self::label($provider)),
        };
    }

    /**
     * Ślad jednej aktywności.
     *
     * @return array{format:string,bytes:string}
     */
    public static function downloadTrack(string $provider, array $token, string $activityId): array
    {
        return match ($provider) {
            'polar' => self::polarTrack($token, $activityId),
            'wahoo' => self::wahooTrack($token, $activityId),
            default => throw new \RuntimeException('Ten dostawca nie ma jeszcze adaptera: ' . self::label($provider)),
        };
    }

    // ---------------------------------------------------------------
    // Webhooki — automatyczny import (migr. 088)
    // ---------------------------------------------------------------

    /** Sekret webhooka z konfiguracji albo null, gdy go nie ustawiono. */
    public static function webhookSecret(string $provider): ?string
    {
        $sekret = (string) (APP_CONFIG['devices'][$provider]['webhookSecret'] ?? '');

        return $sekret !== '' ? $sekret : null;
    }

    /**
     * Czy automat ma prawo się pokazać: dostawca działa, ma webhook i znamy
     * jego sekret. Bez sekretu każde powiadomienie zostałoby odrzucone, więc
     * przełącznik obiecywałby coś, co nigdy się nie wydarzy.
     */
    public static function webhookReady(string $provider): bool
    {
        return !empty(self::META[$provider]['webhook'])
            && self::isUsable($provider)
            && self::webhookSecret($provider) !== null;
    }

    /** Adres odbiornika — ten sam, który trzeba podać dostawcy. Z `app_url`, jak redirectUri(). */
    public static function webhookUrl(string $provider): string
    {
        return rtrim((string) APP_CONFIG['app_url'], '/') . '/api/liczniki/' . $provider . '/webhook';
    }

    /**
     * Czy żądanie naprawdę przyszło od dostawcy. `hash_equals`, żeby czas
     * porównania nie zdradzał, ile znaków podpisu już się zgadza.
     *
     * @param ?string $signature nagłówek podpisu (Polar); Wahoo niesie token w treści
     * @param ?string $sekret    WYŁĄCZNIE dla testów — `APP_CONFIG` jest stałą,
     *                           więc bez tego parametru reguły podpisu nie dałoby
     *                           się sprawdzić w środowisku bez skonfigurowanego sekretu
     */
    public static function verifyWebhook(string $provider, string $rawBody, ?string $signature, array $payload, ?string $sekret = null): bool
    {
        $sekret ??= self::webhookSecret($provider);
        if ($sekret === null) {
            return false;
        }

        return match ($provider) {
            'polar' => $signature !== null
                && hash_equals(hash_hmac('sha256', $rawBody, $sekret), strtolower(trim($signature))),
            'wahoo' => is_scalar($payload['webhook_token'] ?? null)
                && hash_equals($sekret, (string) $payload['webhook_token']),
            default => false,
        };
    }

    /**
     * Treść webhooka -> „kto i co". Null dla wszystkiego, co nie jest nowym
     * treningiem (PING Polara przy zakładaniu webhooka, inne rodzaje zdarzeń).
     *
     * @return array{externalUserId:string,activityId:string,meta:?array}|null
     *         `meta` tylko wtedy, gdy dostawca podał je w samym powiadomieniu (Wahoo)
     */
    public static function webhookEvent(string $provider, array $payload): ?array
    {
        if ($provider === 'polar') {
            if (($payload['event'] ?? '') !== 'EXERCISE' || empty($payload['user_id']) || empty($payload['entity_id'])) {
                return null;
            }

            return [
                'externalUserId' => (string) $payload['user_id'],
                'activityId'     => (string) $payload['entity_id'],
                'meta'           => null,
            ];
        }

        if ($provider === 'wahoo') {
            if (($payload['event_type'] ?? '') !== 'workout_summary') {
                return null;
            }
            $summary = is_array($payload['workout_summary'] ?? null) ? $payload['workout_summary'] : [];
            $workout = is_array($summary['workout'] ?? null) ? $summary['workout'] : [];
            if (empty($payload['user']['id']) || empty($workout['id'])) {
                return null;
            }
            $id = (string) $workout['id'];

            // Identyfikator TRENINGU (workout), nie podsumowania — ten sam,
            // który zwraca lista i którego używa wahooTrack(), więc rejestr
            // pobrań rozpozna przejazd niezależnie od tego, którędy wszedł.
            return [
                'externalUserId' => (string) $payload['user']['id'],
                'activityId'     => $id,
                'meta'           => [
                    'id'         => $id,
                    'name'       => (string) ($workout['name'] ?? 'Trening'),
                    'startedAt'  => substr((string) ($workout['starts'] ?? ''), 0, 19),
                    'distanceKm' => round(((float) ($summary['distance_accum'] ?? 0)) / 1000, 2),
                    'type'       => (string) ($workout['workout_type_id'] ?? ''),
                ],
            ];
        }

        return null;
    }

    /**
     * Metadane jednego treningu, gdy webhook ich nie niósł (Polar podaje same
     * identyfikatory). Null, gdy dostawca nie ma takiego zapytania.
     */
    public static function activityDetails(string $provider, array $token, string $activityId): ?array
    {
        return match ($provider) {
            'polar' => self::polarExercise($token, $activityId),
            default => null,
        };
    }

    /**
     * Czy to na pewno rower z trasą. Lista z ręcznym zaznaczaniem nie filtruje
     * (człowiek widzi rodzaj i odznacza bieg), ale AUTOMAT NIE MA KOGO ZAPYTAĆ:
     * bieg zaliczony po cichu jako przejazd dałby pola i punkty za coś, czego
     * serwis nie liczy. Dlatego tu jest odwrotnie niż na liście — wątpliwość
     * znaczy „nie". Taki trening nie trafia do rejestru, więc dalej czeka na
     * liście „Pobierz aktywności" i człowiek może go dodać sam.
     */
    public static function isCycling(string $provider, array $item): bool
    {
        $typ = strtoupper(trim((string) ($item['type'] ?? '')));
        if ($typ === '') {
            return false;
        }

        if ($provider === 'wahoo') {
            // Tabela „Workout Types" z dokumentacji Wahoo Cloud API: rodzina
            // BIKING, lokalizacja OUTDOOR. Poza listą świadomie: 12/49/61/68
            // (trenażer, zajęcia, wirtualna jazda — bez trasy) i 17
            // (BIKING_MOTOCYCLING, czyli motocykl w rodzinie „rower").
            return ctype_digit($typ) && in_array((int) $typ, [0, 11, 13, 14, 15, 16, 64, 70], true);
        }

        if ($provider === 'polar') {
            if (($item['hasRoute'] ?? null) === false) {
                return false;
            }
            // AccessLink nie publikuje pełnej listy dyscyplin w jednym miejscu,
            // więc zamiast wyliczanki: rower = BIKE/CYCLING w nazwie, bez
            // wariantów, które z definicji nie mają trasy.
            if (!str_contains($typ, 'BIK') && !str_contains($typ, 'CYCL')) {
                return false;
            }
            foreach (['INDOOR', 'SPINNING', 'VIRTUAL', 'TRAINER', 'MOTO'] as $bezTrasy) {
                if (str_contains($typ, $bezTrasy)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Czego brakuje w UDZIELONYCH zakresach, żeby dostawca wysyłał webhooki
     * dla tego człowieka. Pusta lista = nic albo nie da się tego stwierdzić
     * (dostawca nie odesłał `scope` w tokenie — wtedy nie blokujemy na zgadywaniu).
     *
     * @return list<string>
     */
    public static function missingWebhookScopes(string $provider, array $token): array
    {
        if ($provider !== 'wahoo' || !isset($token['scope'])) {
            return [];
        }
        $udzielone = preg_split('/[\s,]+/', (string) $token['scope']) ?: [];

        return in_array('offline_data', $udzielone, true) ? [] : ['offline_data'];
    }

    // ---------------------------------------------------------------
    // Polar AccessLink
    // ---------------------------------------------------------------

    private static function polarList(array $token, ?string $externalUserId, int $limit): array
    {
        $meta = self::meta('polar');
        $answer = self::http('GET', $meta['api'] . '/v3/exercises', [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/json',
        ]);
        if ($answer['status'] === 204) {
            return []; // „nic nowego" — Polar odpowiada pustą treścią, nie pustą listą
        }
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Polar odmówił listy (HTTP ' . $answer['status'] . ').');
        }

        $dane = json_decode($answer['body'], true);
        $items = [];
        foreach (is_array($dane) ? $dane : [] as $ex) {
            if (!is_array($ex) || empty($ex['id'])) {
                continue;
            }
            $items[] = self::polarItem($ex);
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Jeden trening Polara w kształcie pozycji listy — wspólne dla listy
     * i dla webhooka, żeby oba wejścia widziały te same pola.
     *
     * NAZWY KLUCZY W DWÓCH PISOWNIACH. Dokumentacja AccessLink (stan na
     * 2026-09-14) pokazuje `start_time`/`detailed_sport_info`/`has_route`,
     * a adapter był pisany pod `start-time` z łącznikiem. Czytamy oba
     * warianty, zamiast zgadywać, który z nich zwraca dziś produkcyjne API.
     */
    private static function polarItem(array $ex): array
    {
        $pole = static fn(string $snake) => $ex[$snake] ?? $ex[str_replace('_', '-', $snake)] ?? null;

        return [
            'id'         => (string) $ex['id'],
            'name'       => (string) ($ex['sport'] ?? 'Trening'),
            'startedAt'  => substr((string) ($pole('start_time') ?? ''), 0, 19),
            // Polar podaje dystans w METRACH, jako liczbę zmiennoprzecinkową.
            'distanceKm' => round(((float) ($ex['distance'] ?? 0)) / 1000, 2),
            // Szczegółowa dyscyplina (ROAD_BIKING) mówi więcej niż `sport`
            // (CYCLING/OTHER) — tylko po niej automat odróżni rower od biegu.
            'type'       => (string) ($pole('detailed_sport_info') ?? $ex['sport'] ?? ''),
            'hasRoute'   => $pole('has_route'),
        ];
    }

    private static function polarExercise(array $token, string $activityId): ?array
    {
        $meta = self::meta('polar');
        $answer = self::http('GET', $meta['api'] . '/v3/exercises/' . rawurlencode($activityId), [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/json',
        ]);
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Polar nie oddał treningu (HTTP ' . $answer['status'] . ').');
        }
        $ex = json_decode($answer['body'], true);

        return is_array($ex) && !empty($ex['id']) ? self::polarItem($ex) : null;
    }

    /**
     * Klient API Polara — zapytania do zarządzania webhookiem idą HTTP Basic
     * danymi KLIENTA, nie tokenem człowieka (webhook jest jeden na aplikację).
     */
    private static function polarClientHeaders(): array
    {
        $cred = self::credentials('polar');
        if ($cred === null) {
            throw new \RuntimeException('Brak kluczy API dla: Polar');
        }

        return [
            'Authorization: Basic ' . base64_encode($cred['id'] . ':' . $cred['secret']),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /** @return list<array<string,mixed>> webhooki założone dla naszego klienta (0 albo 1) */
    public static function polarWebhooks(): array
    {
        $answer = self::http('GET', self::meta('polar')['api'] . '/v3/webhooks', self::polarClientHeaders());
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Polar odmówił listy webhooków (HTTP ' . $answer['status'] . ').');
        }
        $dane = json_decode($answer['body'], true);
        $lista = $dane['data'] ?? $dane;
        if (is_array($lista) && isset($lista['id'])) {
            $lista = [$lista]; // pojedynczy obiekt zamiast listy
        }

        return is_array($lista) ? array_values(array_filter($lista, 'is_array')) : [];
    }

    /**
     * Zakłada webhook. Polar najpierw wysyła PING pod `url` i zakłada webhook
     * TYLKO wtedy, gdy odbiornik odpowie 200 — więc to działa wyłącznie z
     * serwera widocznego z internetu (nie z localhosta).
     *
     * @return array{id:string,secret:string} sekret Polar oddaje TYLKO TERAZ
     */
    public static function createPolarWebhook(string $url): array
    {
        $answer = self::http(
            'POST',
            self::meta('polar')['api'] . '/v3/webhooks',
            self::polarClientHeaders(),
            json_encode(['events' => ['EXERCISE'], 'url' => $url])
        );
        $dane = json_decode($answer['body'], true);
        $dane = is_array($dane) ? ($dane['data'] ?? $dane) : [];
        if ($answer['status'] >= 400 || empty($dane['signature_secret_key'])) {
            throw new \RuntimeException('Polar nie założył webhooka (HTTP ' . $answer['status'] . '): ' . mb_substr($answer['body'], 0, 300));
        }

        return ['id' => (string) ($dane['id'] ?? ''), 'secret' => (string) $dane['signature_secret_key']];
    }

    public static function deletePolarWebhook(string $id): void
    {
        $answer = self::http('DELETE', self::meta('polar')['api'] . '/v3/webhooks/' . rawurlencode($id), self::polarClientHeaders());
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Polar nie skasował webhooka (HTTP ' . $answer['status'] . ').');
        }
    }

    /** Polar wyłącza webhook sam po 7 dniach nieudanych doręczeń — to go włącza z powrotem. */
    public static function activatePolarWebhook(string $id): void
    {
        $answer = self::http('POST', self::meta('polar')['api'] . '/v3/webhooks/' . rawurlencode($id) . '/activate', self::polarClientHeaders());
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Polar nie włączył webhooka (HTTP ' . $answer['status'] . ').');
        }
    }

    private static function polarTrack(array $token, string $activityId): array
    {
        $meta = self::meta('polar');
        $answer = self::http('GET', $meta['api'] . '/v3/exercises/' . rawurlencode($activityId) . '/gpx', [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/gpx+xml',
        ]);
        if ($answer['status'] >= 400 || $answer['body'] === '') {
            throw new \RuntimeException('Polar nie oddał śladu (HTTP ' . $answer['status'] . ').');
        }

        return ['format' => 'gpx', 'bytes' => $answer['body']];
    }

    // ---------------------------------------------------------------
    // Wahoo Cloud API
    // ---------------------------------------------------------------

    private static function wahooList(array $token, int $limit): array
    {
        $meta = self::meta('wahoo');
        $answer = self::http(
            'GET',
            $meta['api'] . '/v1/workouts?' . http_build_query(['per_page' => min(50, $limit)]),
            ['Authorization: Bearer ' . $token['access_token'], 'Accept: application/json']
        );
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Wahoo odmówił listy (HTTP ' . $answer['status'] . ').');
        }

        $dane = json_decode($answer['body'], true);
        $items = [];
        foreach (($dane['workouts'] ?? []) as $w) {
            if (!is_array($w) || empty($w['id'])) {
                continue;
            }
            $items[] = [
                'id'         => (string) $w['id'],
                'name'       => (string) ($w['name'] ?? 'Trening'),
                'startedAt'  => substr((string) ($w['starts'] ?? ''), 0, 19),
                'distanceKm' => round(((float) ($w['workout_summary']['distance_accum'] ?? 0)) / 1000, 2),
                'type'       => (string) ($w['workout_type_id'] ?? ''),
            ];
        }

        return $items;
    }

    private static function wahooUserId(array $token): ?string
    {
        $answer = self::http('GET', self::meta('wahoo')['api'] . '/v1/user', [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/json',
        ]);
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Wahoo nie oddał danych konta (HTTP ' . $answer['status'] . ').');
        }
        $dane = json_decode($answer['body'], true);

        return !empty($dane['id']) ? (string) $dane['id'] : null;
    }

    private static function wahooTrack(array $token, string $activityId): array
    {
        $meta = self::meta('wahoo');
        $naglowki = ['Authorization: Bearer ' . $token['access_token'], 'Accept: application/json'];
        $answer = self::http(
            'GET',
            $meta['api'] . '/v1/workouts/' . rawurlencode($activityId) . '/workout_summary',
            $naglowki
        );
        if ($answer['status'] >= 400) {
            throw new \RuntimeException('Wahoo nie oddał podsumowania (HTTP ' . $answer['status'] . ').');
        }

        $dane = json_decode($answer['body'], true);
        $url = (string) ($dane['file']['url'] ?? '');
        if ($url === '') {
            // Trening bez pliku (ręcznie dodany, trenażer bez zapisu) — to nie
            // jest awaria, tylko aktywność, z której nic nie zrobimy.
            throw new \RuntimeException(__('Ten trening nie ma pliku FIT.'));
        }

        // Plik leży na CDN-ie z własnym podpisem w adresie — leci BEZ nagłówka
        // Authorization (dorzucony potrafi rozjechać podpis żądania).
        $plik = self::http('GET', $url, []);
        if ($plik['status'] >= 400 || $plik['body'] === '') {
            throw new \RuntimeException('Nie udało się pobrać pliku z Wahoo (HTTP ' . $plik['status'] . ').');
        }

        return ['format' => 'fit', 'bytes' => $plik['body']];
    }

    // ---------------------------------------------------------------

    /**
     * Jedno wywołanie HTTP. cURL, nie file_get_contents: potrzebujemy KODU
     * ODPOWIEDZI (204 i 409 są tu poprawnymi wynikami, nie błędami), własnych
     * nagłówków i twardego limitu czasu — żądanie do cudzego API nie może
     * zawiesić naszej strony.
     *
     * @return array{status:int,body:string}
     */
    private static function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $blad = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            throw new \RuntimeException('Nie udało się połączyć z dostawcą: ' . $blad);
        }

        return ['status' => $status, 'body' => (string) $out];
    }
}
