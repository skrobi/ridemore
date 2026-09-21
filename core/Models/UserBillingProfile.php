<?php
// core/Models/UserBillingProfile.php
namespace Models;

use Core\Database;

// Dane adresowe/do faktury dla KUPUJĄCEGO (uczestnika płatnego wydarzenia) —
// osobna tabela/model od OrganizerBillingProfile (ten jest dla organizatora
// jako wystawcy faktur/odbiorcy wypłat). Bez bank_account — kupujący nie
// dostaje wypłat.
class UserBillingProfile
{
    public int $userId;
    public ?string $legalName;
    public ?string $address;
    public ?string $taxId;
    public ?string $completedAt;

    public static function findByUserId(int $userId): ?self
    {
        $stmt = Database::connection()->prepare('
            SELECT user_id, legal_name, address, tax_id, completed_at FROM user_billing_profiles WHERE user_id = :id
        ');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ? self::fromRow($row) : null;
    }

    // Bramka przed zakupem płatnego wydarzenia (zakup — poza zakresem na razie).
    public static function isComplete(int $userId): bool
    {
        $profile = self::findByUserId($userId);
        return $profile !== null && $profile->completedAt !== null;
    }

    // $data: legalName, address, taxId (opcjonalne). Kompletny profil
    // (wszystkie wymagane pola wypełnione) ustawia completed_at = NOW().
    public static function save(int $userId, array $data): void
    {
        $legalName  = trim($data['legalName'] ?? '');
        $address    = trim($data['address'] ?? '');
        $taxId      = trim($data['taxId'] ?? '');
        $isComplete = $legalName !== '' && $address !== '';

        $completedAtSql = $isComplete ? 'NOW()' : 'NULL';

        // ON DUPLICATE KEY UPDATE zamiast osobnego SELECT + INSERT/UPDATE
        // (UNIQUE na user_id, patrz schema.sql) — atomowe, bez okna między
        // sprawdzeniem istnienia a zapisem, w którym dwa równoległe zapisy
        // tego samego usera mogłyby oba trafić w gałąź INSERT i zderzyć się
        // o naruszenie UNIQUE.
        Database::connection()->prepare("
            INSERT INTO user_billing_profiles (user_id, legal_name, address, tax_id, completed_at)
            VALUES (:uid, :legal_name, :address, :tax_id, $completedAtSql)
            ON DUPLICATE KEY UPDATE
                legal_name = VALUES(legal_name), address = VALUES(address), tax_id = VALUES(tax_id),
                completed_at = VALUES(completed_at)
        ")->execute([
            'uid'        => $userId,
            'legal_name' => $legalName,
            'address'    => $address,
            'tax_id'     => $taxId !== '' ? $taxId : null,
        ]);
    }

    private static function fromRow(array $row): self
    {
        $p = new self();
        $p->userId      = (int) $row['user_id'];
        $p->legalName   = $row['legal_name'];
        $p->address     = $row['address'];
        $p->taxId       = $row['tax_id'];
        $p->completedAt = $row['completed_at'];
        return $p;
    }
}
