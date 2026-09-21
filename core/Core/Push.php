<?php
// core/Core/Push.php
namespace Core;

/**
 * PUSH DO APKI MOBILNEJ (Etap 8 przebudowy apki, 2026-08-28) — TEN SAM
 * WZORZEC co `Core\Mailer`: driver z configu (`'log'` domyślnie, dev pisze
 * do pliku zamiast wysyłać naprawdę; `'fcm'` do prawdziwego FCM HTTP v1),
 * błędy połykane i logowane — akcja biznesowa (RSVP, wiadomość, skarb) już
 * się wykonała, brak push jej nie cofa.
 *
 * BEZ NOWEJ ZALEŻNOŚCI COMPOSERA: JWT do OAuth2 konta serwisowego Google
 * podpisujemy sam (RS256 przez `openssl_sign` — to samo narzędzie, którego
 * PHP używa wszędzie indziej w tym repo do podpisów), bo cały mechanizm to
 * dwa proste żądania HTTP (token → wysyłka), nie warty biblioteki.
 *
 * FAKT, NIE LUKA: bez pliku konta serwisowego Firebase (`APP_CONFIG['push']
 * ['fcm']['service_account_path']`) i realnego `google-services.json` w
 * apce ten kod nie wyśle NICZEGO naprawdę — dopóki user nie założy projektu
 * Firebase, `driver` zostaje na `'log'` i wszystko ląduje w
 * `storage/push.log`, dokładnie jak niewysłana poczta w dev.
 */
class Push
{
    /**
     * Wysyłka do WSZYSTKICH aktywnych urządzeń usera. `data` to ładunek pod
     * kliknięcie powiadomienia (np. `['url' => '/wiadomosci']`) — TREŚĆ
     * WIADOMOŚCI CZATU NIGDY TU NIE WCHODZI (prywatność powiadomienia
     * systemowego, ten sam odruch co „nie zdradzamy kto znalazł skarb").
     */
    public static function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $devices = \Models\PushDevice::activeForUser($userId);
        if (!$devices['android'] && !$devices['ios']) {
            return;
        }

        $driver = APP_CONFIG['push']['driver'] ?? 'log';
        try {
            match ($driver) {
                'log' => self::sendToLog($userId, $devices, $title, $body, $data),
                'fcm' => self::sendFcmAndApns(APP_CONFIG['push'], $devices, $title, $body, $data),
                default => throw new \RuntimeException("Nieznany driver push: $driver"),
            };
        } catch (\Throwable $e) {
            self::logError($userId, $title, $e);
        }
    }

    private static function sendFcmAndApns(array $cfg, array $devices, string $title, string $body, array $data): void
    {
        foreach ($devices['android'] as $token) {
            self::sendFcm($cfg['fcm'] ?? [], $token, $title, $body, $data);
        }
        foreach ($devices['ios'] as $token) {
            self::sendApns($cfg['apns'] ?? [], $token, $title, $body, $data);
        }
    }

    /**
     * APNs (iOS) — ŚWIADOMIE CZYSTY PUNKT ROZSZERZENIA, nie pełny klient
     * HTTP/2. Bez Maca w tej sesji apka iOS i tak się nie zbuduje — pisanie
     * bajtowo dokładnego klienta bez żadnego sposobu, żeby go tu sprawdzić,
     * byłoby pracą na zgadywanie. Błąd trafia do TEGO SAMEGO logu co reszta.
     */
    private static function sendApns(array $cfg, string $token, string $title, string $body, array $data): void
    {
        throw new \RuntimeException('APNs jeszcze nieskonfigurowane (token=' . substr($token, 0, 8) . '…) — patrz Core\\Push::sendApns().');
    }

    // --- FCM HTTP v1 -------------------------------------------------

    private static function sendFcm(array $cfg, string $token, string $title, string $body, array $data): void
    {
        $projectId = $cfg['project_id'] ?? '';
        $keyPath = $cfg['service_account_path'] ?? '';
        if ($projectId === '' || $keyPath === '') {
            throw new \RuntimeException('FCM nieskonfigurowane — brak project_id albo service_account_path.');
        }

        $accessToken = self::fcmAccessToken($keyPath);

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
            ],
        ];

        // PUSTE `data` NIE MOŻE WYJŚĆ W ŻĄDANIU — i to nie jest przezorność,
        // tylko naprawa błędu zmierzonego na żywym FCM 2026-09-11 (pierwszy
        // raz, gdy ten kod w ogóle dostał prawdziwe poświadczenia):
        //
        //   HTTP 400 — "Cannot bind a list to map for field 'data'"
        //
        // Przyczyna jest w PHP, nie w FCM: `json_encode([])` daje `[]`, czyli
        // LISTĘ, a `data` w FCM musi być mapą (`{}`). Pusta tablica po
        // `array_map` nadal jest pusta, więc sam rzut na stringi tego nie
        // ratował. Pole jest opcjonalne, więc najprościej go wtedy nie wysyłać.
        //
        // DLACZEGO TO PRZELEŻAŁO OD SIERPNIA NIEZAUWAŻONE: wszystkie cztery
        // dzisiejsze miejsca wysyłki podają `['url' => …]`, więc nigdy nie
        // trafiały w pustą gałąź — a `$data = []` jest WARTOŚCIĄ DOMYŚLNĄ
        // `sendToUser()`, czyli zaproszeniem do wdepnięcia. Do tego
        // `sendToUser()` łyka własne błędy (wzorzec `Core\Mailer`), więc
        // pierwsze wywołanie bez `data` skończyłoby się cichym wpisem w logu
        // i powiadomieniem, które nigdy nie doszło.
        if ($data !== []) {
            // Wartości data-payloadu MUSZĄ być stringami (wymóg FCM) —
            // wysyłający nie ma obowiązku o tym pamiętać przy każdym wywołaniu.
            // Dotyczy to też liczb: `['n' => 123]` bez tego rzutu leci błędem.
            $payload['message']['data'] = array_map(static fn($v): string => (string) $v, $data);
        }

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('FCM: błąd połączenia — ' . $curlError);
        }
        if ($status >= 300) {
            throw new \RuntimeException("FCM: HTTP $status — " . $response);
        }
    }

    /**
     * Token OAuth2 dla konta serwisowego (JWT bearer flow, RFC 7523) —
     * cache'owany W RAMACH TEGO PROCESU (statyczna zmienna): cron wysyła
     * push do wielu userów w jednej pętli i nie ma powodu autoryzować się
     * od nowa przy każdym odbiorcy. Bez trwałego cache między żądaniami —
     * to nie jest usługa long-running, więc dodatkowa złożoność nie ma się
     * na czym zwrócić.
     */
    private static function fcmAccessToken(string $keyPath): string
    {
        static $cached = null; // ['token' => string, 'expires' => int]

        if ($cached !== null && $cached['expires'] > time() + 30) {
            return $cached['token'];
        }

        if (!is_file($keyPath)) {
            throw new \RuntimeException("Nie znaleziono pliku konta serwisowego Firebase: $keyPath");
        }
        $account = json_decode((string) file_get_contents($keyPath), true);
        if (!is_array($account) || empty($account['private_key']) || empty($account['client_email'])) {
            throw new \RuntimeException('Niepoprawny plik konta serwisowego Firebase.');
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('Nie udało się podpisać JWT kontem serwisowym Firebase.');
        }
        $jwt = $signingInput . '.' . self::base64UrlEncode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = $response !== false ? json_decode($response, true) : null;
        if ($status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            throw new \RuntimeException('Nie udało się uzyskać tokenu OAuth2 dla FCM: ' . ($response ?: 'brak odpowiedzi'));
        }

        $cached = [
            'token' => $decoded['access_token'],
            'expires' => $now + (int) ($decoded['expires_in'] ?? 3600),
        ];
        return $cached['token'];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // --- log / błędy ---------------------------------------------------

    private static function storageDir(): string
    {
        $dir = CORE_PATH . '/../storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private static function sendToLog(int $userId, array $devices, string $title, string $body, array $data): void
    {
        $entry = sprintf(
            "[%s] user_id=%d urządzenia=android:%d,ios:%d\nTytuł: %s\nTreść: %s\nDane: %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $userId,
            count($devices['android']),
            count($devices['ios']),
            $title,
            $body,
            json_encode($data, JSON_UNESCAPED_UNICODE),
            str_repeat('-', 60)
        );
        file_put_contents(self::storageDir() . '/push.log', $entry, FILE_APPEND);
    }

    private static function logError(int $userId, string $title, \Throwable $e): void
    {
        $entry = sprintf(
            "[%s] BŁĄD wysyłki push do user_id=%d (%s): %s\n",
            date('Y-m-d H:i:s'),
            $userId,
            $title,
            $e->getMessage()
        );
        file_put_contents(self::storageDir() . '/push-error.log', $entry, FILE_APPEND);
    }
}
