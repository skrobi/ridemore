<?php

namespace Models;

use Core\Database;

/**
 * GALERIA ZDJEC SKARBU (SKA/14, migr. 066).
 *
 * Zgloszenie usera 2026-08-22: „dla wszystkich skarbow dodalbym zdjecie glowne
 * jak rowniez mozliwosc zbudowania galerii zdjec". Zdjecie GLOWNE zyje dalej
 * w `treasures.photo_url` — ta klasa obsluguje wylacznie galerie.
 *
 * ============================================================
 * TA KLASA NIE ROZSTRZYGA O WIDOCZNOSCI i nie wolno jej tego powierzyc.
 *
 * O tym, czy zdjecia w ogole wyjda do widoku, decyduje `Treasure::reveal()` —
 * to samo miejsce, ktore zeruje `photo_url`, opis i rzadkosc dla poziomow
 * TROP i UKRYTY. Zdjecie jest NAJMOCNIEJSZYM mozliwym spoilerem: pokazuje
 * dokladnie czego szukac, wiec kazda droga do galerii omijajaca `reveal()`
 * znosi cala zagadke. Metody ponizej sa celowo „glupie" — czytaja i zapisuja,
 * nie pytaja kto oglada.
 * ============================================================
 */
class TreasurePhoto
{
    /**
     * Ile zdjec JEDNA OSOBA moze dorzucic do JEDNEGO skarbu.
     *
     * Limit jest na pare (osoba, skarb), nie globalny: chodzi o to, zeby jeden
     * czlowiek nie zapelnil galerii sobą, a nie o to, zeby ograniczac ruch.
     * Admin limitu nie ma — kuratoruje.
     */
    public const PER_USER_LIMIT = 3;

    /** Zdjecia skarbu w kolejnosci, od pierwszego. */
    public static function forTreasure(int $treasureId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT p.id, p.url, p.sort_order, p.uploaded_by, p.created_at,
                   u.name AS author_name, u.public_slug AS author_slug
              FROM treasure_photos p
              LEFT JOIN users u ON u.id = p.uploaded_by
             WHERE p.treasure_id = :id
             ORDER BY p.sort_order ASC, p.id ASC
        ');
        $stmt->execute(['id' => $treasureId]);

        return array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'url'        => (string) $r['url'],
            'uploadedBy' => $r['uploaded_by'] !== null ? (int) $r['uploaded_by'] : null,
            'authorName' => $r['author_name'] ?: null,
            'authorSlug' => $r['author_slug'] ?: null,
        ], $stmt->fetchAll());
    }

    /**
     * Najnowsze zdjecia z galerii WSKAZANYCH skarbow — strona regionu
     * (sekcja „Zdjecia z regionu", 2026-09-16).
     *
     * Zgodnie z nota na gorze klasy: to wolajacy przekazuje wylacznie skarby,
     * ktore `Treasure::reveal()` odslonil widzowi w calosci (jawne albo przez
     * niego znalezione). Tu nie ma zadnej bramki per widz.
     *
     * Autor podpisany imieniem tylko przy widocznym profilu publicznym — ta sama
     * regula co „Kto tu jezdzi"; konta zablokowane nie wychodza wcale.
     *
     * @param  list<int> $treasureIds
     * @return list<array{id:int,treasureId:int,url:string,createdAt:string,authorName:?string,authorSlug:?string}>
     */
    public static function latestFor(array $treasureIds, int $limit = 12): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $treasureIds))));
        if (!$ids) {
            return [];
        }

        $rows = Database::connection()->query('
            SELECT p.id, p.treasure_id, p.url, p.created_at,
                   u.name AS author_name, u.public_slug AS author_slug,
                   (u.roster_visible = 1 AND u.public_slug IS NOT NULL) AS author_public
              FROM treasure_photos p
              JOIN users u ON u.id = p.uploaded_by AND u.blocked_at IS NULL
             WHERE p.treasure_id IN (' . implode(',', $ids) . ')
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . max(1, $limit)
        )->fetchAll();

        return array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'treasureId' => (int) $r['treasure_id'],
            'url'        => (string) $r['url'],
            'createdAt'  => (string) $r['created_at'],
            'authorName' => $r['author_public'] ? ((string) $r['author_name'] ?: null) : null,
            'authorSlug' => $r['author_public'] ? (string) $r['author_slug'] : null,
        ], $rows);
    }

    /**
     * Liczniki dla WIELU skarbow naraz — pod dymek na mapie („N zdjec").
     *
     * JEDNO zapytanie na cala widoczna mape, nie jedno na skarb: `inBounds()`
     * oddaje do 300 punktow, wiec petla z zapytaniem w srodku bylaby 300
     * round-tripami na kazde przesuniecie mapy. Galeria doczytuje sie dopiero
     * po kliknieciu, przez `/api/treasures/{id}`.
     *
     * @param  list<int> $treasureIds
     * @return array<int,int> id skarbu => liczba zdjec
     */
    public static function countsFor(array $treasureIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $treasureIds)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT treasure_id, COUNT(*) AS n FROM treasure_photos
              WHERE treasure_id IN ($placeholders)
              GROUP BY treasure_id"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['treasure_id']] = (int) $row['n'];
        }
        return $out;
    }

    /** Ile zdjec ta osoba juz dorzucila do tego skarbu (pod PER_USER_LIMIT). */
    public static function countForUser(int $treasureId, int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM treasure_photos WHERE treasure_id = :t AND uploaded_by = :u'
        );
        $stmt->execute(['t' => $treasureId, 'u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Dopisanie zdjec na KONIEC galerii.
     *
     * `sort_order` liczymy od aktualnego maksimum, a nie od liczby wierszy:
     * po skasowaniu srodkowego zdjecia liczba wierszy jest mniejsza niz
     * najwyzszy `sort_order` i nowe zdjecie wskoczyloby przed istniejace.
     *
     * @param  list<string> $urls
     * @return int ile faktycznie dopisano
     */
    public static function add(int $treasureId, array $urls, ?int $userId): int
    {
        $urls = array_values(array_filter($urls, static fn($u): bool => is_string($u) && $u !== ''));
        if (!$urls) {
            return 0;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM treasure_photos WHERE treasure_id = :t');
        $stmt->execute(['t' => $treasureId]);
        $next = (int) $stmt->fetchColumn() + 1;

        $ins = $pdo->prepare(
            'INSERT INTO treasure_photos (treasure_id, url, sort_order, uploaded_by)
             VALUES (:t, :u, :s, :by)'
        );
        foreach ($urls as $url) {
            $ins->execute(['t' => $treasureId, 'u' => $url, 's' => $next++, 'by' => $userId]);
        }
        return count($urls);
    }

    /** Jedno zdjecie z autorem — pod sprawdzenie uprawnienia przed skasowaniem. */
    public static function find(int $photoId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, treasure_id, url, uploaded_by FROM treasure_photos WHERE id = :id'
        );
        $stmt->execute(['id' => $photoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Skasowanie zdjecia. Wiersz znika z bazy; PLIK ZOSTAJE NA DYSKU.
     *
     * Tak samo jak przy zdjeciach z relacji: ten sam plik moze byc podlinkowany
     * gdzie indziej (kopia w innej galerii, adres wyslany mailem), a odzyskanie
     * skasowanego uploadu jest niemozliwe, podczas gdy osierocony plik kosztuje
     * kilkadziesiat kilobajtow. Sprzatanie dysku to osobna sprawa i osobna
     * decyzja.
     */
    public static function delete(int $photoId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM treasure_photos WHERE id = :id');
        $stmt->execute(['id' => $photoId]);
    }
}
