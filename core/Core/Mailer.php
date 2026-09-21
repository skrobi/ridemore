<?php
// core/Core/Mailer.php
namespace Core;

class Mailer
{
    /**
     * @param array<string,string> $headers Dodatkowe nagłówki, np.
     *        `List-Unsubscribe` dokładany przez `Models\Notifier` (Etap 1c).
     *        Driver 'log' też je wypisuje — inaczej na dev nie dałoby się
     *        sprawdzić, czy poszły, a to jedyny nagłówek w tym projekcie,
     *        od którego zależy, jak pocztę potraktuje filtr antyspamowy.
     */
    public static function send(string $to, string $subject, string $html, array $headers = []): void
    {
        $driver = APP_CONFIG['mail']['driver'] ?? 'log';

        try {
            match ($driver) {
                'log'  => self::sendToLog($to, $subject, $html, $headers),
                'smtp' => self::sendSmtp(APP_CONFIG['mail'], $to, $subject, $html, $headers),
                default => throw new \RuntimeException("Nieznany driver poczty: $driver"),
            };
        } catch (\Throwable $e) {
            self::logError($to, $subject, $e);
            throw $e;
        }
    }

    // Wygodne opakowanie na wzorzec powtórzony w web/routes.php, api/routes.php
    // i Models\Event kilkanaście razy: renderuj szablon, wyślij, po cichu połknij
    // błąd (akcja biznesowa — zapis/rezerwacja/zatwierdzenie itd. — jest już
    // wykonana i dokonana; brak maila jej nie cofa, a błąd jest już zalogowany
    // przez send() do storage/mail-error.log).
    public static function sendTemplate(string $template, string $to, string $subject, array $data, array $headers = []): void
    {
        try {
            self::send($to, $subject, \Utils\MailTemplate::render($template, $data), $headers);
        } catch (\Throwable $e) {
            // Patrz komentarz metody — celowo brak dalszej obsługi.
        }
    }

    private static function storageDir(): string
    {
        $dir = CORE_PATH . '/../storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    // Prawdziwy powód błędu SMTP (host/port/login/odpowiedź serwera) ląduje tutaj
    // zawsze, niezależnie od środowiska — inaczej ginie bez śladu za ogólnym
    // komunikatem pokazywanym użytkownikowi.
    private static function logError(string $to, string $subject, \Throwable $e): void
    {
        $entry = sprintf(
            "[%s] BŁĄD wysyłki do %s (%s): %s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $e->getMessage()
        );
        file_put_contents(self::storageDir() . '/mail-error.log', $entry, FILE_APPEND);
    }

    private static function sendToLog(string $to, string $subject, string $html, array $headers = []): void
    {
        $entry = sprintf(
            "[%s] Do: %s\nTemat: %s\n%s\n%s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            self::formatHeaders($headers),
            strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)),
            str_repeat('-', 60)
        );
        file_put_contents(self::storageDir() . '/mail.log', $entry, FILE_APPEND);
    }

    // Minimalny klient SMTP (bez zewnętrznych zależności) — EHLO/AUTH LOGIN/MAIL/RCPT/DATA
    // przez SSL (port 465, "implicit TLS"). fsockopen zamiast stream_socket_client i luźniejsze
    // parsowanie odpowiedzi — sprawdzona konfiguracja pod typowe SMTP hostingów współdzielonych.
    /**
     * Nagłówki jako tekst. Wartości są czyszczone z CR/LF — bez tego dowolna
     * wartość z zewnątrz (choćby adres wypisu z podmienionym parametrem)
     * mogłaby wstrzyknąć własny nagłówek albo całą treść maila, bo w SMTP
     * pusta linia kończy blok nagłówków i zaczyna wiadomość.
     */
    private static function formatHeaders(array $headers): string
    {
        $out = '';
        foreach ($headers as $nazwa => $wartosc) {
            $nazwa   = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $nazwa);
            $wartosc = str_replace(["\r", "\n"], '', (string) $wartosc);
            if ($nazwa !== '' && $wartosc !== '') {
                $out .= $nazwa . ': ' . $wartosc . "\r\n";
            }
        }
        return $out;
    }

    private static function sendSmtp(array $cfg, string $to, string $subject, string $html, array $headers = []): void
    {
        // Domyślny kontekst z wyłączoną ścisłą weryfikacją certyfikatu — część
        // hostingów ma certyfikaty, których PHP domyślnie nie zaakceptuje,
        // co inaczej kończy się cichym niepowodzeniem połączenia.
        stream_context_set_default([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $transport = ($cfg['encryption'] ?? 'ssl') === 'ssl' ? 'ssl://' : '';
        $socket = @fsockopen($transport . $cfg['host'], (int) $cfg['port'], $errno, $errstr, 30);
        if (!$socket) {
            throw new \RuntimeException("Nie udało się połączyć z serwerem SMTP: $errstr ($errno)");
        }
        stream_set_timeout($socket, 30);

        $read = function () use ($socket): string {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                // Odpowiedź wieloliniowa ciągnie się dalej dopóki 4. znak to '-'.
                if (substr($line, 3, 1) !== '-') break;
            }
            return $response;
        };
        $write = function (string $cmd) use ($socket): void {
            fputs($socket, $cmd . "\r\n");
        };
        $expect = function (string $pattern) use ($read): string {
            $response = $read();
            if (!preg_match($pattern, $response)) {
                throw new \RuntimeException("SMTP: oczekiwano $pattern, otrzymano: $response");
            }
            return $response;
        };

        try {
            $expect('/^220/');
            $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? $cfg['host']));
            $expect('/^250/');
            $write('AUTH LOGIN');
            $expect('/^334/');
            $write(base64_encode($cfg['username']));
            $expect('/^334/');
            $write(base64_encode($cfg['password']));
            // Większość serwerów odpowiada 235, ale niektóre — 250.
            $expect('/^(235|250)/');

            $fromEmail = $cfg['from_email'];
            $write("MAIL FROM:<$fromEmail>");
            $expect('/^250/');
            $write("RCPT TO:<$to>");
            $expect('/^250/');
            $write('DATA');
            $expect('/^354/');

            $fromName = $cfg['from_name'] ?? $fromEmail;
            $headers =
                'Date: ' . date('r') . "\r\n" .
                "From: {$fromName} <{$fromEmail}>\r\n" .
                "To: <{$to}>\r\n" .
                'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" .
                "MIME-Version: 1.0\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit\r\n" .
                self::formatHeaders($headers);

            // Dot-stuffing wg RFC 5321 — linia zaczynająca się od kropki musi być podwojona.
            $body = preg_replace('/^\./m', '..', $html);

            $write($headers . "\r\n" . $body . "\r\n.");
            $expect('/^250/');

            $write('QUIT');
            $read();
        } finally {
            fclose($socket);
        }
    }
}
