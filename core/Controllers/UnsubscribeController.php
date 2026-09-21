<?php
// core/Controllers/UnsubscribeController.php
// WYPIS Z POWIADOMIEŃ MAILOWYCH (Etap 1c programu zachęt, 2026-09-11).
//
// ============================================================================
// DLACZEGO TO JEST OSOBNA, PUBLICZNA STRONA, A NIE USTAWIENIE W KONCIE
// ============================================================================
// Ustawienia w koncie już istnieją i zostają. Ten adres jest dla sytuacji,
// w której człowiek NIE CHCE się logować — otwiera maila na telefonie, ma dość
// i chce, żeby to się skończyło w jednym kliknięciu. Jeśli wypis wymaga
// przypomnienia hasła, to jego realną alternatywą jest przycisk „to jest spam",
// który kosztuje nas reputację domeny i zabiera ze sobą TAKŻE maile
// transakcyjne — resety haseł i potwierdzenia wpłat wszystkich pozostałych.
//
// ============================================================================
// DWIE DECYZJE, KTÓRE WYGLĄDAJĄ NA DROBIAZGI, A NIE SĄ
// ============================================================================
// 1. GET NICZEGO NIE ZMIENIA — pokazuje stronę z przyciskiem, wypisuje dopiero
//    POST. To nie jest formalizm: podglądy linków w komunikatorach i skanery
//    poczty pobierają adresy z maili w tle, więc wypis na GET wyłączałby ludziom
//    powiadomienia bez ich udziału. W tym projekcie ten sam problem rozstrzygnął
//    już kształt `/skarb/{code}` i „Byłem" (oba POST-only, z tego samego powodu).
//
// 2. BRAK CSRF NA TYM POST-cie JEST ŚWIADOMY, nie przeoczony. Żądanie
//    przychodzi albo z formularza na stronie poniżej (osoba niezalogowana,
//    więc bez sesji i bez tokenu), albo wprost od dostawcy poczty: Gmail
//    i Outlook widzą nagłówek `List-Unsubscribe-Post` (RFC 8058), pokazują
//    własny przycisk „Wypisz się" obok nadawcy i wysyłają POST-a same.
//    Rolę tokenu pełni podpis HMAC w adresie — dowód, że link pochodzi
//    z naszego maila, ograniczony do JEDNEJ flagi jednego użytkownika.
//    Najgorsze, co osiąga napastnik z cudzym linkiem, to wyłączenie komuś
//    powiadomień, które ten ktoś i tak może włączyć z powrotem w koncie.
namespace Controllers;

use Models\NotificationGate;
use Utils\View;

class UnsubscribeController
{
    /** Ludzkie nazwy flag — muszą mówić, co dokładnie zgaśnie. */
    private const OPISY = [
        'push_messages'  => 'powiadomienia w aplikacji o wiadomościach i zapisach',
        'push_nearby'    => 'powiadomienia w aplikacji o nowościach w Twojej okolicy',
        'push_progress'  => 'powiadomienia w aplikacji o postępach na trasach',
        'mail_messages'  => 'maile o nowych wiadomościach i zapisach na wyjazdy',
        'mail_nearby'    => 'maile o nowościach w Twojej okolicy',
        'mail_progress'  => 'maile o postępach na trasach',
        'notify_matches' => 'maile o wyjazdach, które mogą Cię zainteresować',
        'push_rides'     => 'powiadomienia w aplikacji o przejazdach dodanych automatycznie z licznika',
        'mail_rides'     => 'maile o przejazdach dodanych automatycznie z licznika',
    ];

    public static function form(): void
    {
        $cel = self::rozwiaz();
        View::render('web', 'unsubscribe', [
            'title'   => __('Wypisz się z powiadomień — ridemore.bike'),
            'noindex' => true,
            'cel'     => $cel,
            'opis'    => $cel ? __(self::OPISY[$cel['flaga']] ?? 'te powiadomienia') : null,
            'akcja'   => $_SERVER['REQUEST_URI'] ?? View::url('/powiadomienia/wypisz'),
            'zrobione' => false,
        ]);
    }

    public static function save(): void
    {
        $cel = self::rozwiaz();
        if ($cel !== null) {
            NotificationGate::ustawZgode($cel['userId'], $cel['flaga'], false);
        }

        View::render('web', 'unsubscribe', [
            'title'    => __('Wypisano z powiadomień — ridemore.bike'),
            'noindex'  => true,
            'cel'      => $cel,
            'opis'     => $cel ? __(self::OPISY[$cel['flaga']] ?? 'te powiadomienia') : null,
            'akcja'    => $_SERVER['REQUEST_URI'] ?? View::url('/powiadomienia/wypisz'),
            'zrobione' => true,
        ]);
    }

    /**
     * Parametry z adresu → `['userId' => int, 'flaga' => string]` albo null.
     * Czytane z GET nawet przy POST-cie, bo dostawca poczty (RFC 8058) wysyła
     * POST-a na ten sam adres z parametrami w query stringu, a treścią żądania
     * jest wtedy `List-Unsubscribe=One-Click`, nie nasz formularz.
     */
    private static function rozwiaz(): ?array
    {
        return NotificationGate::rozwiazWypis(
            $_GET['u'] ?? null,
            $_GET['f'] ?? null,
            $_GET['k'] ?? null
        );
    }
}
