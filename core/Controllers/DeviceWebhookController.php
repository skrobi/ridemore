<?php
// core/Controllers/DeviceWebhookController.php
// ODBIORNIK WEBHOOKÓW LICZNIKÓW (migr. 088, 2026-09-14) — Polar, Wahoo.
//
// Zgłoszenie usera: „czy jest możliwość, aby z automatu pobierały się trasy?".
// Decyzja: wyłącznie oficjalne kanały, czyli dostawcy, którzy SAMI zawiadamiają
// o nowym treningu. Adres: POST /api/liczniki/{dostawca}/webhook.
//
// BEZ SESJI I BEZ CSRF — I TO NIE JEST LUKA. Żądanie przychodzi z serwera
// dostawcy, nie z przeglądarki, więc nie ma ani ciasteczka, ani tokenu. Rolę
// tokenu pełni podpis (Polar: HMAC treści) albo sekret w treści (Wahoo),
// sprawdzany w Utils\DeviceApi::verifyWebhook, ZANIM cokolwiek się wydarzy.
// Z niepodpisanego żądania nie da się ani wskazać konta, ani niczego pobrać:
// ślad i tak ściągamy tokenem właściciela, a identyfikator treningu, którego
// ten token nie widzi, kończy się odmową u dostawcy.
//
// NAJPIERW ODPOWIEDŹ, POTEM PRACA. Ściągnięcie pliku i policzenie pól to kilka
// sekund, a dostawca czeka na 200 z krótkim limitem czasu — Polar po 7 dniach
// nieudanych doręczeń sam wyłącza webhook, Wahoo ponawia i dubluje. Po 200
// błąd importu i tak nie ma dokąd wrócić: trening, którego nie udało się
// dodać, zostaje na liście „Pobierz aktywności" do ręcznego pobrania.
namespace Controllers;

use Core\Session;
use Models\DeviceImport;
use Utils\DeviceApi;

class DeviceWebhookController
{
    public static function receive(string $provider): void
    {
        // Sesja wystartowała w bootstrapie jak przy każdym żądaniu; odbiornik
        // jej nie potrzebuje, a trzymanie pliku sesji przez cały import
        // nie ma tu żadnego sensu.
        Session::release();

        if (!DeviceApi::isKnown($provider) || !DeviceApi::isUsable($provider)) {
            self::answer(404, ['error' => __('Nieznany odbiornik.')]);
            return;
        }

        $raw = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            self::answer(400, ['error' => __('Treść nie jest JSON-em.')]);
            return;
        }

        // PING POLARA PRZED SPRAWDZENIEM PODPISU. Polar wysyła go przy
        // ZAKŁADANIU webhooka i zakłada go tylko po 200 — a sekret do podpisu
        // oddaje dopiero w odpowiedzi na to zakładanie. Wymaganie podpisu tutaj
        // znaczyłoby, że webhooka nie da się założyć nigdy. PING niczego nie
        // zmienia i nic nie ujawnia, więc odpowiedź „jestem" jest bezpieczna.
        if ($provider === 'polar' && ($payload['event'] ?? '') === 'PING') {
            self::answer(200, ['ok' => true]);
            return;
        }

        if (!DeviceApi::webhookReady($provider)) {
            self::answer(404, ['error' => __('Automatyczny import nie jest skonfigurowany.')]);
            return;
        }
        if (!DeviceApi::verifyWebhook($provider, $raw, $_SERVER['HTTP_POLAR_WEBHOOK_SIGNATURE'] ?? null, $payload)) {
            error_log('Webhook ' . $provider . ': odrzucony podpis/token.');
            self::answer(401, ['error' => __('Nieprawidłowy podpis.')]);
            return;
        }

        $event = DeviceApi::webhookEvent($provider, $payload);
        self::answer(200, ['ok' => true]);
        self::finishResponse();

        if ($event !== null) {
            DeviceImport::fromWebhook($provider, $event);
        }
    }

    private static function answer(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body);
    }

    /**
     * Oddaje odpowiedź dostawcy i pozwala skryptowi pracować dalej.
     *
     * Dwie funkcje, bo dwa typy serwera: `fastcgi_finish_request` (PHP-FPM)
     * i `litespeed_finish_request` (LiteSpeed — m.in. Namecheap, na którym
     * stoi produkcja). Gdy nie ma żadnej (Apache z mod_php, czyli XAMPP),
     * import wykona się przed zamknięciem połączenia — wolniej dla dostawcy,
     * ale z tym samym wynikiem.
     */
    private static function finishResponse(): void
    {
        ignore_user_abort(true);
        @set_time_limit(180);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            // Content-Length da się podać tylko z bufora — bez niego klient
            // i tak dostanie całą odpowiedź, tylko nie wie, że już koniec.
            if (ob_get_level() > 0) {
                header('Content-Length: ' . (int) ob_get_length());
                header('Connection: close');
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            flush();
        }
    }
}
