<?php
// core/Models/EventReview.php
namespace Models;

use Core\Database;

class EventReview
{
    // Średnia/liczba/rozkład opinii per organizator — liczone na żywo z
    // event_reviews.target_user_id, nie z zaseedowanego organizer_profiles.rating_avg
    // (ta kolumna nigdzie nie jest przeliczana, patrz Organizer::stats()).
    public static function statsFor(int $targetUserId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM event_reviews WHERE target_user_id = :id');
        $stmt->execute(['id' => $targetUserId]);
        $row = $stmt->fetch();
        $total = (int) $row['total'];

        $distStmt = $pdo->prepare('SELECT rating, COUNT(*) AS cnt FROM event_reviews WHERE target_user_id = :id GROUP BY rating');
        $distStmt->execute(['id' => $targetUserId]);
        $counts = array_column($distStmt->fetchAll(), 'cnt', 'rating');

        $distribution = [];
        for ($star = 5; $star >= 1; $star--) {
            $count = (int) ($counts[$star] ?? 0);
            $distribution[$star] = $total > 0 ? round($count / $total * 100) : 0;
        }

        return [
            'avg'          => $total > 0 ? round((float) $row['avg_rating'], 1) : null,
            'count'        => $total,
            'distribution' => $distribution,
        ];
    }

    public static function forOrganizer(int $targetUserId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare("
            SELECT r.rating, r.comment, r.created_at, u.name AS reviewer_name, u.email AS reviewer_email,
                   u.avatar_url AS reviewer_avatar_url,
                   e.title AS event_title, e.slug AS event_slug
            FROM event_reviews r
            JOIN users u ON u.id = r.reviewer_user_id
            JOIN events e ON e.id = r.event_id
            WHERE r.target_user_id = :id
            ORDER BY r.created_at DESC
            LIMIT $limit
        ");
        $stmt->execute(['id' => $targetUserId]);

        return array_map(fn($row) => [
            'rating'       => (int) $row['rating'],
            'comment'      => $row['comment'],
            'createdAt'    => $row['created_at'],
            'reviewerName' => $row['reviewer_name'] ?: $row['reviewer_email'],
            'reviewerAvatarUrl' => $row['reviewer_avatar_url'] ?? null,
            'eventTitle'   => $row['event_title'],
            'eventSlug'    => $row['event_slug'],
        ], $stmt->fetchAll());
    }

    // Opinie TEGO KONKRETNEGO eventu (nie zagregowane po wszystkich eventach
    // organizatora jak forOrganizer()) — pod sekcję na event-page.php.
    public static function forEvent(int $eventId): array
    {
        // LIMIT ochronny (jak Event::mapPins()) — bez paginacji UI, ale bez
        // ryzyka, że wieloletni, bardzo komentowany event zacznie ładować
        // nieograniczoną listę na każde wejście na stronę.
        $stmt = Database::connection()->prepare("
            SELECT r.id, r.rating, r.comment, r.created_at, u.name AS reviewer_name, u.email AS reviewer_email,
                   u.public_slug AS reviewer_slug, u.avatar_url AS reviewer_avatar_url
            FROM event_reviews r
            JOIN users u ON u.id = r.reviewer_user_id
            WHERE r.event_id = :event_id
            ORDER BY r.created_at DESC
            LIMIT 200
        ");
        $stmt->execute(['event_id' => $eventId]);

        return array_map(fn($row) => [
            'id'           => (int) $row['id'],
            'rating'       => (int) $row['rating'],
            'comment'      => $row['comment'],
            'createdAt'    => $row['created_at'],
            'reviewerName' => $row['reviewer_name'] ?: $row['reviewer_email'],
            // Publiczny profil autora opinii — podpis pod wpisem jest linkiem
            // (patrz partials/activity-card.php). Null = konto bez sluga.
            'reviewerSlug' => $row['reviewer_slug'] ?? null,
            // Wgrane zdjęcie autora (2026-08-22). Null = konto bez awatara,
            // wtedy kółko pokazuje inicjały jak dotąd.
            'reviewerAvatarUrl' => $row['reviewer_avatar_url'] ?? null,
        ], $stmt->fetchAll());
    }

    public static function hasReviewed(int $eventId, int $reviewerUserId, int $targetUserId): bool
    {
        $stmt = Database::connection()->prepare('
            SELECT 1 FROM event_reviews WHERE event_id = :event_id AND reviewer_user_id = :reviewer_id AND target_user_id = :target_id
        ');
        $stmt->execute(['event_id' => $eventId, 'reviewer_id' => $reviewerUserId, 'target_id' => $targetUserId]);
        return (bool) $stmt->fetchColumn();
    }

    // Zwraca nowe id albo null przy naruszeniu UNIQUE(event_id, reviewer, target)
    // — czyli druga opinia tej samej osoby na ten sam event. Trasa pokazuje wtedy
    // czytelny błąd zamiast 500.
    public static function create(int $eventId, int $reviewerUserId, int $targetUserId, int $rating, ?string $comment): ?int
    {
        $pdo = Database::connection();
        try {
            $pdo->prepare('
                INSERT INTO event_reviews (event_id, reviewer_user_id, target_user_id, rating, comment)
                VALUES (:event_id, :reviewer_id, :target_id, :rating, :comment)
            ')->execute([
                'event_id'    => $eventId,
                'reviewer_id' => $reviewerUserId,
                'target_id'   => $targetUserId,
                'rating'      => $rating,
                'comment'     => $comment,
            ]);
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return null;
            }
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }
}
