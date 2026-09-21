<?php
// core/Models/UserAdmin.php
// LISTA UŻYTKOWNIKÓW DLA ADMINA — przegląd, moderacja, kasowanie fejków.
//
// Osobny model, a nie kolejne metody w Models\User, i to jest tu jedyna
// nieoczywista decyzja. `User` obsługuje ZALOGOWANEGO człowieka: znajdź po
// e-mailu, sprawdź hasło, pokaż profil. Te metody widzą wyłącznie własne konto
// i to jest ich zaletą. Tutaj wszystko robi się z drugiej strony — czytamy
// cudze konta hurtem, razem z danymi, których sam użytkownik o sobie nie widzi
// (kiedy się zarejestrował, ile ma treści, kto go zablokował). Wsypanie tego do
// `User` znaczyłoby, że każdy kontroler frontu ma pod ręką narzędzia moderacji.
namespace Models;

use Core\Database;

class UserAdmin
{
    public const PER_PAGE = 30;

    /**
     * TABELE, KTÓRE DECYDUJĄ, CZY KONTO WOLNO SKASOWAĆ.
     *
     * Nie jest to lista wszystkich 25 tabel z kluczem obcym — tylko tych,
     * w których wpis znaczy „ten człowiek coś w serwisie ZROBIŁ". Reszta
     * (preferencje, dziennik rekomendacji, odczyty czatu) powstaje sama i jej
     * skasowanie niczego nie niszczy.
     *
     * Kolejność ma znaczenie dla komunikatu — najpierw to, co najmocniej blokuje.
     */
    private const CONTENT_TABLES = [
        'events'             => ['kolumna' => 'organizer_id',      'etykieta' => 'wydarzenia'],
        'event_rsvps'        => ['kolumna' => 'user_id',           'etykieta' => 'zapisy na wyjazdy'],
        'event_recaps'       => ['kolumna' => 'author_user_id',    'etykieta' => 'wpisy w kronice'],
        'event_comments'     => ['kolumna' => 'user_id',           'etykieta' => 'komentarze'],
        'event_photos'       => ['kolumna' => 'uploaded_by',       'etykieta' => 'zdjęcia'],
        'messages'           => ['kolumna' => 'sender_id',         'etykieta' => 'wiadomości'],
        'rider_activities'   => ['kolumna' => 'user_id',           'etykieta' => 'przejazdy'],
        'point_transactions' => ['kolumna' => 'user_id',           'etykieta' => 'naliczenia punktów'],
        'treasure_finds'     => ['kolumna' => 'user_id',           'etykieta' => 'znalezione skarby'],
    ];

    /**
     * Lista kont z filtrem i stronicowaniem.
     *
     * DOMYŚLNIE OD NAJNOWSZYCH, bo pierwsze pytanie admina brzmi „czy ktoś nowy
     * się zarejestrował", a nie „kto jest w bazie". Alfabetycznie ta lista nie
     * odpowiada na żadne pytanie, które ktokolwiek zadaje.
     *
     * @param array{szukaj?:string,filtr?:string,strona?:int} $opcje
     */
    public static function search(array $opcje = []): array
    {
        $szukaj = trim((string) ($opcje['szukaj'] ?? ''));
        $filtr  = (string) ($opcje['filtr'] ?? '');
        $strona = max(1, (int) ($opcje['strona'] ?? 1));

        $where  = ['1 = 1'];
        $params = [];

        if ($szukaj !== '') {
            // Po nazwie ALBO po e-mailu — admin szukający kogoś ma w ręku raz
            // jedno, raz drugie, i nie powinien wybierać pola z listy.
            $where[] = '(u.email LIKE :q OR u.name LIKE :q2)';
            $params['q']  = '%' . $szukaj . '%';
            $params['q2'] = '%' . $szukaj . '%';
        }

        $where[] = match ($filtr) {
            'zablokowani'    => 'u.blocked_at IS NOT NULL',
            'niepotwierdzeni'=> 'u.email_verified_at IS NULL',
            'organizatorzy'  => 'EXISTS (SELECT 1 FROM organizer_profiles op WHERE op.user_id = u.id)',
            'administratorzy'=> 'u.is_admin = 1',
            // Etap 9 przebudowy apki (2026-08-29) — samoobsługowe prośby o
            // usunięcie konta (patrz Models\User::requestDeletion()). Osobny
            // filtr od „zablokowani": ten sam mechanizm blokady, ale admin
            // musi umieć znaleźć KOLEJKĘ do dokasowania, nie przekopywać się
            // przez wszystkie blokady moderacyjne.
            'do_usuniecia'   => 'u.deletion_requested_at IS NOT NULL',
            // „Nowi" to siedem dni, a nie „od ostatniego logowania admina":
            // ta druga wersja wymagałaby pamiętania stanu i myliłaby się przy
            // dwóch adminach patrzących na tę samą listę.
            'nowi'           => 'u.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            default          => '1 = 1',
        };

        $sqlWhere = implode(' AND ', $where);

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM users u WHERE ' . $sqlWhere);
        $count->execute($params);
        $ile = (int) $count->fetchColumn();

        $offset = ($strona - 1) * self::PER_PAGE;
        $stmt = Database::connection()->prepare('
            SELECT u.id, u.name, u.email, u.created_at, u.email_verified_at, u.is_admin,
                   u.blocked_at, u.blocked_reason, u.deletion_requested_at, u.public_slug, u.avatar_url,
                   u.password_hash IS NOT NULL AS ma_haslo,
                   b.name AS blokujacy,
                   (SELECT COUNT(*) FROM event_rsvps r WHERE r.user_id = u.id) AS zapisy,
                   (SELECT COUNT(*) FROM events e WHERE e.organizer_id = u.id) AS wydarzenia,
                   (SELECT COUNT(*) FROM event_comments c WHERE c.user_id = u.id) AS komentarze,
                   EXISTS (SELECT 1 FROM user_oauth_identities o WHERE o.user_id = u.id) AS przez_oauth
              FROM users u
              LEFT JOIN users b ON b.id = u.blocked_by
             WHERE ' . $sqlWhere . '
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset . '
        ');
        $stmt->execute($params);

        return [
            'items'   => $stmt->fetchAll(),
            'total'   => $ile,
            'strona'  => $strona,
            'stron'   => max(1, (int) ceil($ile / self::PER_PAGE)),
            'szukaj'  => $szukaj,
            'filtr'   => $filtr,
        ];
    }

    /** Liczby do pasków nad listą — jedno zapytanie, nie pięć. */
    public static function counters(): array
    {
        $row = Database::connection()->query('
            SELECT COUNT(*) AS wszyscy,
                   SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS nowi,
                   SUM(blocked_at IS NOT NULL) AS zablokowani,
                   SUM(email_verified_at IS NULL) AS niepotwierdzeni,
                   SUM(deletion_requested_at IS NOT NULL) AS do_usuniecia
              FROM users
        ')->fetch() ?: [];

        return [
            'wszyscy'         => (int) ($row['wszyscy'] ?? 0),
            'nowi'            => (int) ($row['nowi'] ?? 0),
            'zablokowani'     => (int) ($row['zablokowani'] ?? 0),
            'niepotwierdzeni' => (int) ($row['niepotwierdzeni'] ?? 0),
            'do_usuniecia'    => (int) ($row['do_usuniecia'] ?? 0),
        ];
    }

    /**
     * Co to konto po sobie zostawi, jeśli je skasować.
     *
     * Wołane PRZED kasowaniem i pokazywane adminowi — bo `ON DELETE CASCADE`
     * nie zapyta o zdanie. @return array{puste:bool, pozycje:array<string,int>}
     */
    public static function contentSummary(int $userId): array
    {
        $pozycje = [];
        foreach (self::CONTENT_TABLES as $tabela => $meta) {
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM ' . $tabela . ' WHERE ' . $meta['kolumna'] . ' = :id'
            );
            $stmt->execute(['id' => $userId]);
            $ile = (int) $stmt->fetchColumn();
            if ($ile > 0) {
                $pozycje[$meta['etykieta']] = $ile;
            }
        }

        return ['puste' => $pozycje === [], 'pozycje' => $pozycje];
    }

    public static function block(int $userId, string $reason, int $adminId): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            return false;
        }

        // ADMINA NIE DA SIĘ ZABLOKOWAĆ z tego ekranu. Nie chodzi o hierarchię,
        // tylko o to, że jeden admin w afekcie mógłby zamknąć drogę do panelu
        // wszystkim — łącznie z sobą. Odebranie praw robi się w bazie, świadomie.
        $stmt = Database::connection()->prepare('
            UPDATE users
               SET blocked_at = NOW(), blocked_reason = :powod, blocked_by = :admin
             WHERE id = :id AND is_admin = 0 AND blocked_at IS NULL
        ');
        $stmt->execute(['powod' => mb_substr($reason, 0, 255), 'admin' => $adminId, 'id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public static function unblock(int $userId): bool
    {
        $stmt = Database::connection()->prepare('
            UPDATE users SET blocked_at = NULL, blocked_reason = NULL, blocked_by = NULL
             WHERE id = :id AND blocked_at IS NOT NULL
        ');
        $stmt->execute(['id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Twarde kasowanie — WYŁĄCZNIE dla kont bez śladu działalności.
     *
     * Sprawdzenie „czy puste" jest tu, a nie w kontrolerze, bo to jedyne
     * zabezpieczenie przed kaskadą po 25 tabelach. W kontrolerze byłoby
     * warunkiem, który wolno pominąć; tutaj jest częścią operacji.
     *
     * @return array{ok:bool, powod:?string}
     */
    public static function deleteIfEmpty(int $userId, int $adminId): array
    {
        if ($userId === $adminId) {
            return ['ok' => false, 'powod' => 'wlasne_konto'];
        }

        $user = Database::connection()->prepare('SELECT is_admin FROM users WHERE id = :id');
        $user->execute(['id' => $userId]);
        $row = $user->fetch();

        if ($row === false) {
            return ['ok' => false, 'powod' => 'nie_ma'];
        }
        if ((int) $row['is_admin'] === 1) {
            return ['ok' => false, 'powod' => 'admin'];
        }
        if (!self::contentSummary($userId)['puste']) {
            return ['ok' => false, 'powod' => 'ma_tresc'];
        }

        $del = Database::connection()->prepare('DELETE FROM users WHERE id = :id AND is_admin = 0');
        $del->execute(['id' => $userId]);

        return ['ok' => $del->rowCount() > 0, 'powod' => null];
    }

    public static function find(int $userId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT id, name, email, created_at, email_verified_at, is_admin,
                   blocked_at, blocked_reason, blocked_by, deletion_requested_at, public_slug
              FROM users WHERE id = :id
        ');
        $stmt->execute(['id' => $userId]);

        return $stmt->fetch() ?: null;
    }
}
