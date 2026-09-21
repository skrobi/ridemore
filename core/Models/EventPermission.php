<?php
// core/Models/EventPermission.php
namespace Models;

use Core\Database;

class EventPermission
{
    // Właściciel (organizer_id na evencie), jego współpracownik
    // (organizer_collaborators — dostęp do WSZYSTKICH jego wydarzeń,
    // nie ad-hoc per event), albo admin aplikacji.
    public static function canEdit(User $user, int $organizerId): bool
    {
        if ($user->isAdmin) {
            return true;
        }
        if ($user->id === $organizerId) {
            return true;
        }

        $stmt = Database::connection()->prepare('
            SELECT 1 FROM organizer_collaborators
            WHERE organizer_user_id = :organizer_id AND user_id = :user_id
        ');
        $stmt->execute(['organizer_id' => $organizerId, 'user_id' => $user->id]);
        return (bool) $stmt->fetchColumn();
    }
}
