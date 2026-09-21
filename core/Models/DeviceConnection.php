<?php
// core/Models/DeviceConnection.php
// POŁĄCZENIE KONTA Z LICZNIKIEM (migr. 069) — jeden wiersz na parę
// (użytkownik, dostawca). Powstało z `GarminAccount` (migr. 068), gdy doszli
// kolejni dostawcy: kolumny były te same, różnił się wyłącznie serwis.
//
// CO TU JEST, A CZEGO NIE MA. Nie ma hasła — ani w bazie, ani w sesji, ani
// w logu. Przy Garminie hasło idzie stdin-em do procesu Pythona i ginie razem
// z nim; przy OAuth (Polar, Wahoo, …) hasła nie widzimy w ogóle, bo człowiek
// wpisuje je u dostawcy. Zostaje wyłącznie TOKEN, zaszyfrowany AES-256-GCM.
//
// TOKEN TO JEDEN SEKRET, NIE TRZY. Access token, refresh token i czas ważności
// jadą razem jako zaszyfrowany JSON w `token_cipher`. Trzy kolumny znaczyłyby
// trzy miejsca, w których można zapomnieć o szyfrowaniu — a te dane i tak
// zawsze czyta się i kasuje w komplecie.
//
// DLACZEGO GCM: tryb uwierzytelniony wykrywa manipulację szyfrogramem —
// podmieniony token nie zdeszyfruje się po cichu na śmieci, tylko zwróci false
// i zostanie potraktowany jak brak połączenia. To ma znaczenie, bo ten token
// leci potem prosto do cudzego API (albo do procesu Pythona).
namespace Models;

use Core\Database;

class DeviceConnection
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * @return array{userId:int,provider:string,label:?string,externalUserId:?string,
     *               displayName:?string,connectedAt:string,lastSyncAt:?string,autoImport:bool}|null
     */
    public static function find(int $userId, string $provider): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, provider, account_label, external_user_id, display_name,
                    connected_at, last_sync_at, auto_import
               FROM device_connections WHERE user_id = :u AND provider = :p LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'p' => $provider]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'userId'         => (int) $row['user_id'],
            'provider'       => (string) $row['provider'],
            'label'          => $row['account_label'] !== null ? (string) $row['account_label'] : null,
            'externalUserId' => $row['external_user_id'] !== null ? (string) $row['external_user_id'] : null,
            'displayName'    => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'connectedAt'    => (string) $row['connected_at'],
            'lastSyncAt'     => $row['last_sync_at'] !== null ? (string) $row['last_sync_at'] : null,
            'autoImport'     => (bool) $row['auto_import'],
        ];
    }

    /**
     * Przełącznik „dodawaj nowe przejazdy automatycznie" (migr. 088).
     * Działa wyłącznie na ISTNIEJĄCYM połączeniu — bez niego nie ma czego włączać.
     */
    public static function setAutoImport(int $userId, string $provider, bool $on): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE device_connections SET auto_import = :a WHERE user_id = :u AND provider = :p'
        );
        $stmt->execute(['a' => $on ? 1 : 0, 'u' => $userId, 'p' => $provider]);
    }

    /**
     * Czy ta osoba ma włączony automat przy JAKIMKOLWIEK liczniku — ekran konta
     * pokazuje przełączniki powiadomień o dodanych przejazdach tylko wtedy,
     * bo bez automatu te powiadomienia nigdy nie wychodzą.
     */
    public static function hasAutoImport(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM device_connections WHERE user_id = :u AND auto_import = 1 LIMIT 1'
        );
        $stmt->execute(['u' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Identyfikator u dostawcy dopisany później (Wahoo połączone przed migr. 088). */
    public static function setExternalUserId(int $userId, string $provider, string $externalUserId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE device_connections SET external_user_id = :x WHERE user_id = :u AND provider = :p'
        );
        $stmt->execute(['x' => mb_substr($externalUserId, 0, 64), 'u' => $userId, 'p' => $provider]);
    }

    /**
     * Do kogo należy powiadomienie od dostawcy — wyłącznie osoby z WŁĄCZONYM
     * automatem. Lista, nie jedna osoba: nic nie broni podpiąć tego samego
     * konta Polar do dwóch kont u nas, a klucz unikalny stoi na (user, dostawca).
     *
     * @return list<int>
     */
    public static function autoImportUsers(string $provider, string $externalUserId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id FROM device_connections
              WHERE provider = :p AND external_user_id = :x AND auto_import = 1'
        );
        $stmt->execute(['p' => $provider, 'x' => $externalUserId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Wszystkie połączenia tej osoby — pod nagłówki zakładek („połączony"). */
    public static function allFor(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT provider FROM device_connections WHERE user_id = :u'
        );
        $stmt->execute(['u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $provider) {
            $out[(string) $provider] = true;
        }

        return $out;
    }

    /**
     * Zapisuje (albo nadpisuje) połączenie. Ponowne zalogowanie odświeża token.
     *
     * @param array<string,mixed> $token komplet OAuth albo token sesji Garmina
     */
    public static function connect(
        int $userId,
        string $provider,
        array $token,
        ?string $label = null,
        ?string $displayName = null,
        ?string $externalUserId = null
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO device_connections
                 (user_id, provider, account_label, external_user_id, token_cipher, display_name, connected_at)
             VALUES (:u, :p, :l, :x, :t, :d, NOW())
             ON DUPLICATE KEY UPDATE account_label = VALUES(account_label),
                                     external_user_id = VALUES(external_user_id),
                                     token_cipher = VALUES(token_cipher),
                                     display_name = VALUES(display_name),
                                     connected_at = VALUES(connected_at)'
        );
        $stmt->execute([
            'u' => $userId,
            'p' => $provider,
            'l' => $label !== null && $label !== '' ? mb_substr($label, 0, 190) : null,
            'x' => $externalUserId,
            't' => self::encrypt(json_encode($token, JSON_UNESCAPED_UNICODE)),
            'd' => $displayName !== null && $displayName !== '' ? mb_substr($displayName, 0, 190) : null,
        ]);
    }

    /**
     * Token gotowy do użycia albo null, gdy połączenia nie ma (albo szyfrogram
     * nie daje się odczytać — patrz nota o GCM wyżej).
     *
     * @return array<string,mixed>|null
     */
    public static function token(int $userId, string $provider): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT token_cipher FROM device_connections WHERE user_id = :u AND provider = :p LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'p' => $provider]);
        $cipher = $stmt->fetchColumn();
        if ($cipher === false) {
            return null;
        }

        $plain = self::decrypt((string) $cipher);
        if ($plain === null) {
            return null;
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Nowa wersja tokenu po odświeżeniu (OAuth) albo po zwykłym zapytaniu
     * (Garmin odświeża sesję sam) — żeby połączenie nie wygasało samo z siebie.
     *
     * @param array<string,mixed> $token
     */
    public static function refreshToken(int $userId, string $provider, array $token): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE device_connections SET token_cipher = :t, last_sync_at = NOW()
              WHERE user_id = :u AND provider = :p'
        );
        $stmt->execute([
            'u' => $userId,
            'p' => $provider,
            't' => self::encrypt(json_encode($token, JSON_UNESCAPED_UNICODE)),
        ]);
    }

    public static function disconnect(int $userId, string $provider): void
    {
        // Rejestr pobranych aktywności (device_activities) zostaje CELOWO:
        // ktoś, kto odłączy i podłączy konto ponownie, nie ma dostać z powrotem
        // wszystkich swoich przejazdów jako „nowych" do policzenia drugi raz.
        $stmt = Database::connection()->prepare(
            'DELETE FROM device_connections WHERE user_id = :u AND provider = :p'
        );
        $stmt->execute(['u' => $userId, 'p' => $provider]);
    }

    private static function key(): string
    {
        // Klucz jest WSPÓLNY dla wszystkich dostawców i mieszka w `devices`,
        // bo ta sekcja istnieje w OBU środowiskach niezależnie od tego, który
        // dostawca akurat tam działa (Polar/Wahoo nie mają nic wspólnego z
        // Pythonem, a bez tego klucza token Polara nie miałby czym się
        // zaszyfrować). `garmin.token_key` (od 2026-09-04 też na 'prod', patrz
        // core/config.php) zostaje niżej jako fallback: domyślna wartość jest
        // zgodna z dawnym kluczem sprzed migracji 069, więc tokeny zapisane
        // przed nią dalej się odszyfrowują.
        $secret = (string) (APP_CONFIG['devices']['token_key'] ?? APP_CONFIG['garmin']['token_key'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('Brak klucza szyfrującego tokeny liczników (devices.token_key).');
        }

        return hash('sha256', $secret, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Nie udało się zaszyfrować tokenu.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        return $plain === false ? null : $plain;
    }
}
