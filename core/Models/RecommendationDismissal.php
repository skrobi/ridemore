<?php
// core/Models/RecommendationDismissal.php
// Etap 3 (preferencje) §6 — stan odrzucenia trzystopniowego. Stopień 1
// (jeden klik, bez powodu) wstawia wiersz z reason=NULL — wydarzenie znika z
// rekomendacji dla tego usera, BEZ kary na żadną oś. Stopień 2 (opcjonalny,
// drugie kliknięcie) dogrywa reason do TEGO SAMEGO wiersza. dismissed_at
// świadomie NIE jest dotykane przy dogrywaniu powodu — liczenie "3 razy w
// 90 dni" (Models\DerivedPreference) musi liczyć od momentu odrzucenia, nie
// od momentu ewentualnego doprecyzowania powodu chwilę później.
namespace Models;

use Core\Database;

class RecommendationDismissal
{
    private const REASONS = ['zly_termin', 'za_daleko', 'nie_moje_tempo', 'po_prostu_nie'];

    public static function record(int $userId, int $eventId, ?string $reason): void
    {
        if ($reason !== null && !in_array($reason, self::REASONS, true)) {
            $reason = null;
        }
        Database::connection()->prepare('
            INSERT INTO recommendation_dismissals (user_id, event_id, reason)
            VALUES (:user_id, :event_id, :reason)
            ON DUPLICATE KEY UPDATE reason = COALESCE(VALUES(reason), reason)
        ')->execute(['user_id' => $userId, 'event_id' => $eventId, 'reason' => $reason]);
    }

    public static function isDismissed(int $userId, int $eventId): bool
    {
        $stmt = Database::connection()->prepare('
            SELECT 1 FROM recommendation_dismissals WHERE user_id = :user_id AND event_id = :event_id
        ');
        $stmt->execute(['user_id' => $userId, 'event_id' => $eventId]);
        return (bool) $stmt->fetchColumn();
    }
}
