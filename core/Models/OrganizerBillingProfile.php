<?php
// core/Models/OrganizerBillingProfile.php
namespace Models;

use Core\Database;

class OrganizerBillingProfile
{
    public int $organizerUserId;
    public ?string $legalName;
    public ?string $address;
    public ?string $taxId;
    public ?string $bankAccountHolder;
    public ?string $bankAccount;
    public ?string $completedAt;

    public static function findByUserId(int $userId): ?self
    {
        $stmt = Database::connection()->prepare('
            SELECT organizer_user_id, legal_name, address, tax_id, bank_account_holder, bank_account, completed_at
            FROM organizer_billing_profiles WHERE organizer_user_id = :id
        ');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ? self::fromRow($row) : null;
    }

    // Bramka publikacji płatnych wydarzeń z zapisami wewnętrznymi.
    public static function isComplete(int $userId): bool
    {
        $profile = self::findByUserId($userId);
        return $profile !== null && $profile->completedAt !== null;
    }

    // $data: legalName, address, taxId (opcjonalne), bankAccountHolder, bankAccount.
    // legalName to podmiot do faktury (firma/osoba), bankAccountHolder to
    // posiadacz konta do wypłat — CELOWO osobne pola, bo mogą wskazywać różne
    // podmioty (np. firma do faktury, prywatne konto właściciela do wypłat).
    // Kompletny profil (wszystkie wymagane pola wypełnione) ustawia completed_at = NOW().
    public static function save(int $userId, array $data): void
    {
        $legalName         = trim($data['legalName'] ?? '');
        $address           = trim($data['address'] ?? '');
        $taxId             = trim($data['taxId'] ?? '');
        $bankAccountHolder = trim($data['bankAccountHolder'] ?? '');
        $bankAccount       = trim($data['bankAccount'] ?? '');
        $isComplete        = $legalName !== '' && $address !== '' && $bankAccountHolder !== '' && $bankAccount !== '';

        $completedAtSql = $isComplete ? 'NOW()' : 'NULL';

        // ON DUPLICATE KEY UPDATE zamiast osobnego SELECT + INSERT/UPDATE
        // (UNIQUE na organizer_user_id, patrz schema.sql) — atomowe, bez okna
        // między sprawdzeniem istnienia a zapisem, w którym dwa równoległe
        // zapisy tego samego usera (np. dwie karty przeglądarki) mogłyby oba
        // trafić w gałąź INSERT i zderzyć się o naruszenie UNIQUE.
        Database::connection()->prepare("
            INSERT INTO organizer_billing_profiles
                (organizer_user_id, legal_name, address, tax_id, bank_account_holder, bank_account, completed_at)
            VALUES (:uid, :legal_name, :address, :tax_id, :bank_account_holder, :bank_account, $completedAtSql)
            ON DUPLICATE KEY UPDATE
                legal_name = VALUES(legal_name), address = VALUES(address), tax_id = VALUES(tax_id),
                bank_account_holder = VALUES(bank_account_holder), bank_account = VALUES(bank_account),
                completed_at = VALUES(completed_at)
        ")->execute([
            'uid'                 => $userId,
            'legal_name'          => $legalName,
            'address'             => $address,
            'tax_id'              => $taxId !== '' ? $taxId : null,
            'bank_account_holder' => $bankAccountHolder !== '' ? $bankAccountHolder : null,
            'bank_account'        => $bankAccount,
        ]);
    }

    private static function fromRow(array $row): self
    {
        $p = new self();
        $p->organizerUserId    = (int) $row['organizer_user_id'];
        $p->legalName          = $row['legal_name'];
        $p->address            = $row['address'];
        $p->taxId              = $row['tax_id'];
        $p->bankAccountHolder  = $row['bank_account_holder'];
        $p->bankAccount        = $row['bank_account'];
        $p->completedAt        = $row['completed_at'];
        return $p;
    }
}
