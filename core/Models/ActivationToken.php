<?php
// core/Models/ActivationToken.php
namespace Models;

use Core\Database;

class ActivationToken
{
    private const TTL_MINUTES = 60;

    // Zwraca surowy token (do wysłania w linku) — w bazie trzymamy tylko jego hash.
    // $ttlMinutes = null → token bez limitu czasu (np. link "przejmij profil
    // organizatora" wysyłany ręcznie przez admina, który może czekać na kliknięcie
    // tygodniami — w odróżnieniu od linku aktywacyjnego przy rejestracji, klikanego "od razu").
    public static function issueFor(int $userId, ?int $ttlMinutes = self::TTL_MINUTES): string
    {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);

        $pdo = Database::connection();
        // Unieważnij poprzednie, niewykorzystane tokeny tego użytkownika
        // (np. gdy ktoś klika "Załóż konto" drugi raz na ten sam e-mail).
        $pdo->prepare('UPDATE account_activation_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
            ->execute(['uid' => $userId]);

        $expiresAtSql = $ttlMinutes !== null ? 'DATE_ADD(NOW(), INTERVAL ' . (int) $ttlMinutes . ' MINUTE)' : 'NULL';
        $stmt = $pdo->prepare("
            INSERT INTO account_activation_tokens (user_id, token_hash, expires_at)
            VALUES (:uid, :hash, {$expiresAtSql})
        ");
        $stmt->execute(['uid' => $userId, 'hash' => $hash]);

        return $raw;
    }

    // user_id jeśli token istnieje, jest nieużyty i (nie wygasł lub jest bezterminowy) — inaczej null.
    public static function resolveUserId(string $rawToken): ?int
    {
        $hash = hash('sha256', $rawToken);
        $stmt = Database::connection()->prepare('
            SELECT user_id FROM account_activation_tokens
            WHERE token_hash = :hash AND used_at IS NULL AND (expires_at IS NULL OR expires_at >= NOW())
        ');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();
        return $row ? (int) $row['user_id'] : null;
    }

    public static function markUsed(string $rawToken): void
    {
        $hash = hash('sha256', $rawToken);
        Database::connection()
            ->prepare('UPDATE account_activation_tokens SET used_at = NOW() WHERE token_hash = :hash')
            ->execute(['hash' => $hash]);
    }
}
