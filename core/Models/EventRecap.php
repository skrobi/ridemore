<?php
// core/Models/EventRecap.php
namespace Models;

use Core\Database;

class EventRecap
{
    public static function findByEventAndAuthor(int $eventId, int $authorUserId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT id, body, youtube_url FROM event_recaps
            WHERE event_id = :event_id AND author_user_id = :author_id
        ');
        $stmt->execute(['event_id' => $eventId, 'author_id' => $authorUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Wpis tej osoby w kronice KONKRETNEGO turnusu (migr. 039). Od Etapu 4 to
    // jest właściwe wejście — findByEventAndAuthor() powyżej patrzy na całe
    // wydarzenie, więc przy cyklicznym zwróciłby relację z innego terminu.
    /**
     * NAJNOWSZY wpis tej osoby w tym turnusie.
     *
     * Od migr. 052 jedna osoba może mieć ich wiele (kronika jest dziennikiem
     * pisanym w trakcie, nie jednym podsumowaniem), więc „mój wpis" przestało
     * być jednoznaczne. Ta metoda odpowiada wyłącznie na pytanie „czy ta osoba
     * już cokolwiek dopisała" — do EDYCJI służy findOwn(), które wymaga id.
     */
    public static function findByEditionAndAuthor(int $editionId, int $authorUserId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT id, body, youtube_url FROM event_recaps
            WHERE edition_id = :edition_id AND author_user_id = :author_id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute(['edition_id' => $editionId, 'author_id' => $authorUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * KONKRETNY wpis, ale tylko własny.
     *
     * Autorstwo sprawdzamy W ZAPYTANIU, nie po pobraniu: to jedyne miejsce,
     * w którym id wpisu przychodzi z adresu, więc warunek musi być częścią
     * odczytu, a nie osobnym „if" do zapomnienia przy następnej zmianie.
     */
    public static function findOwn(int $recapId, int $authorUserId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT id, body, youtube_url, edition_id FROM event_recaps
            WHERE id = :id AND author_user_id = :author_id
        ');
        $stmt->execute(['id' => $recapId, 'author_id' => $authorUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Ile wpisów ta osoba ma już w tym turnusie — pod nagłówek formularza. */
    public static function countForEditionAuthor(int $editionId, int $authorUserId): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) FROM event_recaps
            WHERE edition_id = :edition_id AND author_user_id = :author_id
        ');
        $stmt->execute(['edition_id' => $editionId, 'author_id' => $authorUserId]);
        return (int) $stmt->fetchColumn();
    }

    // Zwraca null przy naruszeniu UNIQUE(edition_id, author_user_id) — trasa woła
    // najpierw findByEditionAndAuthor() i idzie do update(), więc to tylko
    // zabezpieczenie przed wyścigiem (dwa równoległe zapisy tej samej osoby).
    public static function create(int $eventId, int $authorUserId, ?string $body, ?string $youtubeUrl, ?int $editionId = null): ?int
    {
        $pdo = Database::connection();
        try {
            $pdo->prepare('
                INSERT INTO event_recaps (event_id, edition_id, author_user_id, body, youtube_url)
                VALUES (:event_id, :edition_id, :author_id, :body, :yt)
            ')->execute([
                'event_id'   => $eventId,
                'edition_id' => $editionId,
                'author_id'  => $authorUserId,
                'body'       => $body,
                'yt'         => $youtubeUrl,
            ]);
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return null;
            }
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $recapId, ?string $body, ?string $youtubeUrl): void
    {
        Database::connection()->prepare('
            UPDATE event_recaps SET body = :body, youtube_url = :yt WHERE id = :id
        ')->execute(['body' => $body, 'yt' => $youtubeUrl, 'id' => $recapId]);
    }

    // Wpisy relacji dla eventu wraz z dołączonymi zdjęciami (event_photos.recap_id)
    // i nazwą autora — pod sekcję "Relacje i opinie" na event-page.php.
    public static function forEvent(int $eventId): array
    {
        return self::fetchWithPhotos('r.event_id = :id', ['id' => $eventId], 'DESC');
    }

    // Dziennik kroniki JEDNEGO turnusu (Etap 4). Ten sam kształt wpisu co
    // forEvent(), ale rosnąco — kronika czyta się jak zapis dnia, od rana do
    // wieczora, a nie jak feed od najnowszego. Autor dostaje też public_slug,
    // żeby podpisy pod wpisami prowadziły do profili.
    public static function forEdition(int $editionId): array
    {
        return self::fetchWithPhotos('r.edition_id = :id', ['id' => $editionId], 'ASC');
    }

    // Wspólne ciało forEvent()/forEdition() — różnią się wyłącznie warunkiem
    // WHERE i kierunkiem sortowania, więc zapytanie o zdjęcia (jedno IN (...)
    // zamiast round-tripu per relacja) i składanie wyniku żyją w jednym miejscu.
    private static function fetchWithPhotos(string $where, array $params, string $direction): array
    {
        // LIMIT ochronny (jak Event::mapPins()) — bez paginacji UI, ale bez
        // ryzyka nieograniczonego wzrostu na bardzo aktywnym evencie.
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT r.id, r.body, r.youtube_url, r.created_at, r.author_user_id,
                   u.name AS author_name, u.email AS author_email, u.public_slug AS author_slug,
                   u.avatar_url AS author_avatar_url
            FROM event_recaps r
            JOIN users u ON u.id = r.author_user_id
            WHERE $where
            ORDER BY r.created_at $direction
            LIMIT 100
        ");
        $stmt->execute($params);
        $recaps = $stmt->fetchAll();
        if (!$recaps) {
            return [];
        }

        // Zdjęcia WSZYSTKICH relacji naraz (IN (...)) zamiast osobnego zapytania
        // per relacja w array_map() niżej — dla eventu z wieloma relacjami to
        // była tyle samo dodatkowych round-tripów do bazy.
        $recapIds = array_column($recaps, 'id');
        $placeholders = implode(',', array_fill(0, count($recapIds), '?'));
        $photoStmt = $pdo->prepare("
            SELECT recap_id, url FROM event_photos
            WHERE recap_id IN ($placeholders)
            ORDER BY recap_id ASC, sort_order ASC
        ");
        $photoStmt->execute($recapIds);
        $photosByRecap = [];
        foreach ($photoStmt->fetchAll() as $row) {
            $photosByRecap[$row['recap_id']][] = $row['url'];
        }

        return array_map(function ($row) use ($photosByRecap) {
            return [
                'id'          => (int) $row['id'],
                // Id autora, nie tylko jego nazwa: kronika rozstrzyga po nim,
                // przy których wpisach pokazać ołówek. Od migr. 052 jedna osoba
                // może mieć w turnusie kilka wpisów, więc porównanie „to mój
                // jedyny wpis" przestało wystarczać.
                'authorId'    => (int) $row['author_user_id'],
                'body'        => $row['body'],
                'youtubeId'   => self::extractYoutubeId($row['youtube_url']),
                'createdAt'   => $row['created_at'],
                'authorName'  => $row['author_name'] ?: $row['author_email'],
                'authorSlug'  => $row['author_slug'] ?? null,
                'authorAvatarUrl' => $row['author_avatar_url'] ?? null,
                'photos'      => $photosByRecap[$row['id']] ?? [],
            ];
        }, $recaps);
    }

    // Najnowsza relacja z niepustą treścią, z opublikowanego wydarzenia — pod
    // cytat w sekcji "Dowód" na stronie głównej (landing). Null gdy baza nie
    // ma jeszcze żadnej relacji (sekcja cytatu się wtedy nie renderuje, patrz
    // HomeController) — statystyki liczbowe obok niej zostają niezależnie.
    public static function mostRecentPublic(): ?array
    {
        $stmt = Database::connection()->query("
            SELECT r.body, e.title AS event_title, e.slug AS event_slug
            FROM event_recaps r
            JOIN events e ON e.id = r.event_id
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code IN ('published', 'completed')
            WHERE r.body IS NOT NULL AND r.body <> ''
            ORDER BY r.created_at DESC
            LIMIT 1
        ");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Sprawdza tylko kształt URL-a (youtube.com/watch?v=... albo youtu.be/...) —
    // nie wołamy YouTube API, więc nie wiemy czy film faktycznie istnieje. Zwraca
    // ID wideo pod bezpieczne osadzenie w <iframe> albo null gdy URL pusty/zły.
    public static function extractYoutubeId(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        if (preg_match('#^https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtube\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{6,})#', trim($url), $m)) {
            return $m[1];
        }
        return null;
    }
}
