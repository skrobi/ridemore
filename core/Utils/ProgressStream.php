<?php
// core/Utils/ProgressStream.php
// ŻYWY STATUS DŁUGIEGO IMPORTU — wspólny dla Garmina i liczników OAuth
// (Polar, Wahoo i każdego kolejnego dostawcy przez DeviceController).
//
// PO CO TO ISTNIEJE (zgłoszenie usera, 2026-08-26): „Pobierz zaznaczone" to
// jeden blokujący POST, a przy kilkunastu przejazdach potrafi trwać dobrą
// chwilę — nie widać, czy coś się w ogóle dzieje, ile już się udało i ile
// jest błędów. Kontrolery sięgają po tę klasę TYLKO wtedy, gdy JS po drugiej
// stronie o to poprosił (nagłówek X-Progress-Stream) — bez JS-a formularz
// działa dokładnie jak wcześniej: zwykły POST i przekierowanie (PRG).
//
// FORMAT: NDJSON (jeden obiekt JSON na linię). NIE Server-Sent Events — to
// jest zwykły POST z ciałem (zaznaczone identyfikatory), a EventSource umie
// wyłącznie GET. Klient czyta odpowiedź przez fetch() + ReadableStream,
// dzieląc bufor po znaku nowej linii (patrz <script> w partials/ride-sources.php).
namespace Utils;

class ProgressStream
{
    /** Nagłówek, którym JS zgłasza „umiem czytać strumień NDJSON". */
    private const HEADER = 'HTTP_X_PROGRESS_STREAM';

    public static function requested(): bool
    {
        return ($_SERVER[self::HEADER] ?? '') === '1';
    }

    /**
     * Otwiera strumień: wyłącza buforowanie, żeby flush() faktycznie leciał
     * do przeglądarki od razu, a nie dopiero na końcu skryptu.
     */
    public static function start(): void
    {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Accel-Buffering: no'); // nginx i podobne proxy — nie buforuj do końca odpowiedzi
        header('Cache-Control: no-cache');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

    /** Jedno zdarzenie = jedna linia JSON-u + natychmiastowy flush do klienta. */
    public static function emit(array $event): void
    {
        echo json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    }
}
