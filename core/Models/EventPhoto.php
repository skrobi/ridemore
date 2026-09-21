<?php
// core/Models/EventPhoto.php
namespace Models;

use Core\Database;

class EventPhoto
{
    // Cała galeria eventu — niezależnie od źródła (opinia/relacja/bezpośredni
    // upload organizatora), patrz komentarz przy event_photos w schema.sql.
    public static function forEvent(int $eventId): array
    {
        // LIMIT ochronny (jak Event::mapPins()) — bez paginacji UI, ale bez
        // ryzyka nieograniczonego wzrostu na bardzo aktywnym evencie.
        $stmt = Database::connection()->prepare('
            SELECT url FROM event_photos WHERE event_id = :event_id ORDER BY sort_order ASC, created_at ASC
            LIMIT 300
        ');
        $stmt->execute(['event_id' => $eventId]);
        return array_column($stmt->fetchAll(), 'url');
    }

    /**
     * Zdjęcia z RELACJI danych turnusów — kafle wyjazdów na profilu rowerzysty
     * (2026-09-13). Tylko relacje (kronika jest per turnus), bez zdjęć z opinii,
     * i najwyżej `$per` na turnus: kafel pokazuje przedsmak kroniki, nie galerię.
     * Zdjęcia relacji są publiczne od chwili dodania (patrz kronika), więc nie
     * ma tu żadnej bramki per widz.
     *
     * @return array<int,array{photos:list<string>,recaps:int}> edition_id => ...
     */
    public static function forEditions(array $editionIds, int $per = 3): array
    {
        $ids = array_values(array_unique(array_map('intval', $editionIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);
        $out = [];
        foreach ($ids as $id) { $out[$id] = ['photos' => [], 'recaps' => 0]; }

        $db = Database::connection();
        foreach ($db->query('SELECT edition_id, COUNT(*) AS n FROM event_recaps WHERE edition_id IN (' . $in . ') GROUP BY edition_id') as $r) {
            $out[(int) $r['edition_id']]['recaps'] = (int) $r['n'];
        }
        $rows = $db->query('
            SELECT r.edition_id, p.url
              FROM event_photos p
              JOIN event_recaps r ON r.id = p.recap_id
             WHERE r.edition_id IN (' . $in . ')
             ORDER BY p.created_at DESC, p.id DESC
        ')->fetchAll();
        foreach ($rows as $r) {
            $e = (int) $r['edition_id'];
            if (count($out[$e]['photos']) < $per) { $out[$e]['photos'][] = (string) $r['url']; }
        }
        return $out;
    }

    /**
     * Zdjęcia UCZESTNIKÓW z wyjazdów w regionie — relacje i opinie
     * (strona regionu, sekcja „Zdjęcia z regionu", 2026-09-16).
     *
     * Bez bezpośrednich uploadów organizatora (ani `review_id`, ani `recap_id`):
     * to materiał promocyjny wyjazdu, a sekcja pokazuje, co ludzie PRZYWIEŹLI
     * z tego terenu. Region wyjazdu z `event_regions`, czyli wyjazd z dwoma
     * regionami pokazuje swoje zdjęcia na obu stronach.
     *
     * Każdy wiersz niesie wszystko, czego potrzeba do podpisu „skąd i czego
     * dotyczy": rodzaj (relacja/opinia), wyjazd i autora. O tym, czy autora
     * wolno podpisać imieniem, decyduje ta sama reguła co „Kto tu jeździ"
     * (`RiderActivity::ridersInRegion`): widoczny profil publiczny.
     * Wyjazdy niepubliczne (szkic, czeka na weryfikację) i konta zablokowane
     * nie wychodzą wcale — strona regionu jest do indeksowania.
     *
     * @return list<array{id:int,url:string,createdAt:string,kind:string,eventSlug:string,
     *                    eventTitle:string,authorName:?string,authorSlug:?string}>
     */
    public static function forRegion(int $regionItemId, int $limit = 12): array
    {
        $stmt = Database::connection()->prepare('
            SELECT p.id, p.url, p.created_at, p.recap_id,
                   e.slug AS event_slug, e.title AS event_title,
                   u.name AS author_name, u.public_slug AS author_slug,
                   (u.roster_visible = 1 AND u.public_slug IS NOT NULL) AS author_public
              FROM event_photos p
              JOIN events e ON e.id = p.event_id
              JOIN dictionary_items st ON st.id = e.status_item_id
                   AND st.code NOT IN ("draft", "oczekuje_weryfikacji")
              JOIN event_regions er ON er.event_id = e.id AND er.region_item_id = :region
              JOIN users u ON u.id = p.uploaded_by AND u.blocked_at IS NULL
             WHERE p.recap_id IS NOT NULL OR p.review_id IS NOT NULL
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['region' => $regionItemId]);

        return array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'url'        => (string) $r['url'],
            'createdAt'  => (string) $r['created_at'],
            'kind'       => $r['recap_id'] !== null ? 'recap' : 'review',
            'eventSlug'  => (string) $r['event_slug'],
            'eventTitle' => (string) $r['event_title'],
            'authorName' => $r['author_public'] ? ((string) $r['author_name'] ?: null) : null,
            'authorSlug' => $r['author_public'] ? (string) $r['author_slug'] : null,
        ], $stmt->fetchAll());
    }

    // Miniatury pod konkretną opinią (event-page.php) — podzbiór galerii.
    public static function forReview(int $reviewId): array
    {
        $stmt = Database::connection()->prepare('SELECT url FROM event_photos WHERE review_id = :review_id ORDER BY sort_order ASC');
        $stmt->execute(['review_id' => $reviewId]);
        return array_column($stmt->fetchAll(), 'url');
    }

    // Wspólny zapis wywoływany i z formularza opinii, i z formularza relacji —
    // dokładnie jedno z $reviewId/$recapId powinno być ustawione (drugie null).
    /**
     * Zdjęcia podpięte do JEDNEGO wpisu relacji.
     *
     * Potrzebne formularzowi edycji: bez pokazania tego, co już jest, puste pole
     * pliku nie odpowiada na pytanie „czy nowe zdjęcia zastąpią stare, czy się
     * dołożą" — a bez tej odpowiedzi nikt nie ryzykuje drugiej próby.
     *
     * @return string[] adresy plików, w kolejności dodania
     */
    public static function forRecap(int $recapId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT url FROM event_photos WHERE recap_id = :id ORDER BY sort_order, id'
        );
        $stmt->execute(['id' => $recapId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function attach(int $eventId, int $uploadedBy, array $urls, ?int $reviewId, ?int $recapId): void
    {
        if (!$urls) {
            return;
        }
        $stmt = Database::connection()->prepare('
            INSERT INTO event_photos (event_id, review_id, recap_id, url, uploaded_by)
            VALUES (:event_id, :review_id, :recap_id, :url, :uploaded_by)
        ');
        foreach ($urls as $url) {
            $stmt->execute([
                'event_id'    => $eventId,
                'review_id'   => $reviewId,
                'recap_id'    => $recapId,
                'url'         => $url,
                'uploaded_by' => $uploadedBy,
            ]);
        }
    }
}
