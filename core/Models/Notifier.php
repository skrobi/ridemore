<?php

namespace Models;

use Core\Mailer;
use Core\Push;

/**
 * MOST WYSYŁKI — jedno miejsce, które puszcza powiadomienie DWOMA KANAŁAMI
 * (Etap 1c programu zachęt, 2026-09-11; migr. 082).
 *
 * ============================================================================
 * PODZIAŁ RÓL, KTÓRY TU OBOWIĄZUJE
 * ============================================================================
 * `NotificationGate`  — DECYDUJE (zgoda, budżet, cisza, powtórka) i nie wysyła.
 * `Core\Push`, `Core\Mailer` — WYSYŁAJĄ i o niczym nie decydują.
 * `Notifier` (ten plik) — spina jedno z drugim i nie robi nic ponadto.
 *
 * Powód istnienia jest ten sam, dla którego w Etapie 0 powstała bramka: miejsc
 * wysyłki jest sześć, a od tej migracji każde z nich musiałoby powtórzyć
 * „claim push → wyślij push → claim mail → wyślij mail". Przy siódmym ktoś by
 * o którymś kroku zapomniał — i najpewniej o tym, którego brak widać dopiero
 * po skardze, czyli o zgodzie.
 *
 * ============================================================================
 * DWIE RZECZY, KTÓRYCH TEN MOST PILNUJE ZA WOŁAJĄCEGO
 * ============================================================================
 * 1. KANAŁY SĄ NIEZALEŻNE. Odmowa jednego nie blokuje drugiego — bo zgody
 *    i budżety są liczone osobno per kanał (decyzja usera). Wyłączony push
 *    nie może wyciszyć maila.
 * 2. KAŻDY MAIL Z MOSTU NIESIE WYPIS. Adres wypisu i nagłówek
 *    `List-Unsubscribe` dokłada tu, a nie w miejscu wysyłki, bo to most wie,
 *    która flaga zgody rządzi danym typem. Maile spoza mostu (reset hasła,
 *    potwierdzenie wpłaty) stopki wypisu nie dostają i dostać nie powinny —
 *    nie ma z czego się wypisywać, a przycisk sugerowałby, że jest.
 */
final class Notifier
{
    /**
     * Wyślij JEDNO powiadomienie tyloma kanałami, na ile jest zgoda.
     *
     * @param array $push ['title' => string, 'body' => string, 'data' => array]
     *                    — pusty wyłącza kanał push dla tego wywołania.
     * @param array $mail ['to' => string, 'subject' => string,
     *                     'template' => string, 'data' => array]
     *                    — pusty wyłącza kanał mailowy.
     * @return array ['push' => bool, 'mail' => bool] — co FAKTYCZNIE poszło.
     */
    public static function wyslij(
        int $userId,
        string $typ,
        string $dedupeKey,
        array $push = [],
        array $mail = []
    ): array {
        return [
            'push' => $push !== [] && self::push($userId, $typ, $dedupeKey, $push),
            'mail' => $mail !== [] && self::mail($userId, $typ, $dedupeKey, $mail),
        ];
    }

    private static function push(int $userId, string $typ, string $dedupeKey, array $push): bool
    {
        $id = NotificationGate::claim($userId, $typ, $dedupeKey, NotificationGate::PUSH);
        if ($id === null) {
            return false;
        }
        // POMIAR OTWARĆ (Etap 2, 2026-09-11) — identyfikator wpisu w dzienniku
        // jedzie w ładunku, a apka odsyła go po tapnięciu w powiadomienie
        // (`POST /api/powiadomienia/otwarte`). Bez tego `opened_at` zostawał
        // pusty na zawsze, a strojenie budżetu było zgadywaniem: wiedzieliśmy
        // tylko, ile wyszło, nigdy — czy ktokolwiek to otworzył.
        //
        // Wartości w `data` MUSZĄ być stringami (wymóg FCM) — `Core\Push` i tak
        // je rzutuje, ale podajemy od razu w docelowej postaci.
        $dane = ($push['data'] ?? []) + ['nid' => (string) $id];

        // `sendToUser` łyka własne błędy (patrz Core\Push) — powiadomienie jest
        // dodatkiem do akcji, która już się wydarzyła, a nie jej częścią.
        Push::sendToUser($userId, $push['title'], $push['body'], $dane);
        return true;
    }

    private static function mail(int $userId, string $typ, string $dedupeKey, array $mail): bool
    {
        $adres = trim((string) ($mail['to'] ?? ''));
        if ($adres === '') {
            return false;
        }
        if (NotificationGate::claim($userId, $typ, $dedupeKey, NotificationGate::MAIL) === null) {
            return false;
        }

        $flaga  = NotificationGate::flagaZgody($typ, NotificationGate::MAIL);
        $wypis  = NotificationGate::adresWypisu($userId, $flaga);
        $dane   = ($mail['data'] ?? []) + ['unsubscribeUrl' => $wypis];

        // RFC 8058: `List-Unsubscribe-Post` sprawia, że Gmail i Outlook
        // pokazują własny przycisk „Wypisz się" obok nadawcy i wysyłają POST-a
        // same, bez otwierania strony. Bez niego zostaje sam link w stopce,
        // a filtry antyspamowe traktują taką wysyłkę gorzej.
        Mailer::sendTemplate($mail['template'], $adres, $mail['subject'], $dane, [
            'List-Unsubscribe'      => '<' . $wypis . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
        return true;
    }
}
