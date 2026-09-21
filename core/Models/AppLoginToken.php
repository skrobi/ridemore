<?php

namespace Models;

use Core\Database;

/**
 * JEDNORAZOWY TOKEN PRZENOSZACY LOGOWANIE Z PRZEGLADARKI DO APLIKACJI
 * (migr. 067, Etap 3 apki mobilnej).
 *
 * Istnieje z jednego powodu: Google blokuje OAuth w WebView, wiec logowanie
 * spolecznosciowe MUSI przebiec w systemowej przegladarce — a ta ma osobne
 * ciasteczka i zalogowanie sie w niej nie loguje aplikacji. Po udanym OAuth
 * serwer wystawia token, przekierowuje na deep link, a aplikacja wymienia go
 * na sesje juz u siebie.
 *
 * ============================================================
 * TO JEST MECHANIZM LOGOWANIA. Kazdy, kto poda wazny token, zostaje zalogowany
 * jako jego wlasciciel — bez hasla i bez drugiego skladnika. Stad trzy zasady,
 * ktorych nie wolno rozluznic:
 *   1. w bazie lezy HASH, nigdy sam token,
 *   2. token zyje MINUTY, nie godziny,
 *   3. dziala DOKLADNIE RAZ — `consume()` kasuje wiersz w tej samej transakcji,
 *      w ktorej go czyta.
 * ============================================================
 */
class AppLoginToken
{
    /**
     * Ile token jest wazny. Trzy minuty, bo jedyne, co ma zdazyc, to przeskok
     * z przegladarki do aplikacji na TYM SAMYM telefonie — to sekundy. Dluzszy
     * czas nie kupuje nic poza wiekszym oknem dla kogos, kto token przechwyci.
     */
    private const TTL_SECONDS = 180;

    /**
     * Wystawia token dla uzytkownika i oddaje go W POSTACI JAWNEJ — jedyny
     * moment, w ktorym istnieje poza adresem deep linku. Do bazy idzie hash.
     *
     * Przy okazji sprzatamy wygasle wiersze: tabela rosnie tylko o logowania
     * z aplikacji, wiec osobny cron byl by tu przerostem formy nad trescia.
     */
    public static function issue(int $userId): string
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM app_login_tokens WHERE expires_at < NOW()')->execute();

        // random_bytes, nie uniqid/mt_rand — to jest sekret uwierzytelniajacy.
        $token = bin2hex(random_bytes(32));

        $db->prepare('
            INSERT INTO app_login_tokens (token_hash, user_id, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ')->execute([hash('sha256', $token), $userId, self::TTL_SECONDS]);

        return $token;
    }

    /**
     * Wymienia token na id uzytkownika. Zwraca null, gdy token jest nieznany,
     * wygasly albo juz uzyty — wolajacy NIE MOZE rozrozniac tych przypadkow
     * w komunikacie, bo to podpowiedz dla zgadujacego.
     *
     * Odczyt i kasowanie w JEDNEJ transakcji z blokada wiersza: bez tego dwa
     * rownolegle zadania z tym samym tokenem (podworna aktywacja deep linku
     * zdarza sie na Androidzie) zalogowalyby sie oba, czyli token przestalby
     * byc jednorazowy dokladnie w sytuacji, w ktorej to najbardziej prawdopodobne.
     */
    public static function consume(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $db = Database::connection();

        // WŁASNA TRANSAKCJA TYLKO WTEDY, GDY NIKT JEJ JESZCZE NIE OTWORZYŁ.
        // PDO nie umie zagnieżdżać `beginTransaction()` — bezwarunkowe wywołanie
        // wysadza każdego, kto woła tę metodę wewnątrz swojej transakcji
        // (pierwszy taki wywołujący to uruchamiacz testów, który owija w nią
        // każdy przypadek). Gdy transakcja jest cudza, jednorazowość tokenu
        // domyka się razem z NIĄ, nie tutaj — i tak ma być.
        $wlasna = !$db->inTransaction();
        if ($wlasna) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare('
                SELECT id, user_id FROM app_login_tokens
                WHERE token_hash = ? AND expires_at >= NOW()
                FOR UPDATE
            ');
            $stmt->execute([hash('sha256', $token)]);
            $row = $stmt->fetch();

            if ($row === false) {
                if ($wlasna) { $db->rollBack(); }
                return null;
            }

            $db->prepare('DELETE FROM app_login_tokens WHERE id = ?')->execute([$row['id']]);
            if ($wlasna) { $db->commit(); }

            return (int) $row['user_id'];
        } catch (\Throwable $e) {
            if ($wlasna && $db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }
}
