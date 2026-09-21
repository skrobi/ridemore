<?php
// core/Models/OAuthIdentity.php
// Powiązanie konta z tożsamością u providera OAuth (Google/Strava). Patrz
// migration_029. Osobno od users, żeby jeden user mógł mieć oba providery.
namespace Models;

use Core\Database;

class OAuthIdentity
{
    // user_id podpięty do danej tożsamości u providera, albo null (nowy user).
    public static function findUserId(string $provider, string $providerUserId): ?int
    {
        $stmt = Database::connection()->prepare('
            SELECT user_id FROM user_oauth_identities
            WHERE provider = :p AND provider_user_id = :pid
        ');
        $stmt->execute(['p' => $provider, 'pid' => $providerUserId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    // Idempotentne — INSERT IGNORE po obu UNIQUE (ta sama tożsamość / ten sam
    // provider na koncie nie tworzy duplikatu przy ponownym logowaniu).
    public static function link(int $userId, string $provider, string $providerUserId): void
    {
        Database::connection()->prepare('
            INSERT IGNORE INTO user_oauth_identities (user_id, provider, provider_user_id)
            VALUES (:u, :p, :pid)
        ')->execute(['u' => $userId, 'p' => $provider, 'pid' => $providerUserId]);
    }
}
