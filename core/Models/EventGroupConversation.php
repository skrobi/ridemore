<?php
// core/Models/EventGroupConversation.php
// Kanał grupowy wydarzenia — prywatna dyskusja CAŁEJ grupy PER TURNUS
// (edition_id), odrębna od modułu 1:1 (Models\Message) i publicznego Q&A
// (Models\EventComment). Patrz migration_028. Członkostwo liczone NA ŻYWO
// (bez tabeli członków): organizator/współpracownik eventu LUB realny zapis na
// ten turnus — dostęp znika automatycznie po anulowaniu, spójnie z
// Message::canMessage. Bez czatu na żywo (odświeżenie wystarcza, jak 1:1).
namespace Models;

use Core\Database;

class EventGroupConversation
{
    // Statusy zapisu wpuszczające do prywatnej grupy — realne uczestnictwo, NIE
    // sama "obserwacja" ('zainteresowany') ani anulowany zapis. Wspólny fragment
    // WHERE dla wszystkich metod niżej, żeby definicja członkostwa była w jednym
    // miejscu (łatwo poluzować, np. dopuścić 'zainteresowany').
    private const MEMBER_STATUS_EXCLUDE = "('anulowany','zainteresowany')";

    // Fragment SQL "czy :uid jest członkiem kanału turnusu :edition_id" —
    // organizator/współpracownik eventu albo realny zapis na turnus. Wstrzykiwany
    // do canAccess()/unread/inbox, żeby reguła żyła w jednym miejscu. Wszystkie
    // placeholdery mają UNIKALNE nazwy (PDO nie pozwala reużyć nazwy w jednym
    // zapytaniu bez emulacji), stąd sufiksy.
    private static function membershipClause(string $eventIdCol, string $editionIdCol, string $sfx): string
    {
        return "(
            $eventIdCol IN (SELECT ev.id FROM events ev WHERE ev.organizer_id = :m_org_$sfx)
            OR EXISTS (
                SELECT 1 FROM organizer_collaborators oc_$sfx
                JOIN events ev2_$sfx ON ev2_$sfx.organizer_id = oc_$sfx.organizer_user_id
                WHERE ev2_$sfx.id = $eventIdCol AND oc_$sfx.user_id = :m_collab_$sfx
            )
            OR EXISTS (
                SELECT 1 FROM event_rsvps r_$sfx
                JOIN dictionary_items rdi_$sfx ON rdi_$sfx.id = r_$sfx.status_item_id
                WHERE r_$sfx.edition_id = $editionIdCol AND r_$sfx.user_id = :m_rsvp_$sfx
                  AND rdi_$sfx.code NOT IN " . self::MEMBER_STATUS_EXCLUDE . "
            )
        )";
    }

    private static function membershipParams(int $userId, string $sfx): array
    {
        return ["m_org_$sfx" => $userId, "m_collab_$sfx" => $userId, "m_rsvp_$sfx" => $userId];
    }

    // Admin ma dostęp niezależnie od relacji (moderacja) — przekazywany wprost,
    // żeby ta metoda nie musiała wczytywać całego User.
    public static function canAccess(int $editionId, int $userId, bool $isAdmin = false): bool
    {
        if ($isAdmin) {
            return true;
        }
        $sql = "
            SELECT 1 FROM event_editions ed
            JOIN events e ON e.id = ed.event_id
            WHERE ed.id = :edition_id AND " . self::membershipClause('e.id', 'ed.id', 'a') . "
            LIMIT 1
        ";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(array_merge(['edition_id' => $editionId], self::membershipParams($userId, 'a')));
        return (bool) $stmt->fetchColumn();
    }

    // Bez tworzenia — pod GET (podgląd wątku nie powinien tworzyć wiersza; kanał
    // powstaje dopiero przy wysłaniu, patrz findOrCreate()). null = jeszcze pusty.
    public static function findByEdition(int $editionId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM event_group_conversations WHERE edition_id = :ed');
        $stmt->execute(['ed' => $editionId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    // Id turnusów danego eventu — pod wysyłkę organizatora „do wszystkich" z
    // listy uczestników (event-wide), rozdystrybuowaną do kanału KAŻDEGO turnusu.
    public static function editionIdsForEvent(int $eventId): array
    {
        $stmt = Database::connection()->prepare('SELECT id FROM event_editions WHERE event_id = :ev ORDER BY start_date ASC');
        $stmt->execute(['ev' => $eventId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // Leniwe utworzenie kanału (dopiero przy pierwszej wiadomości) — edition_id
    // UNIQUE, więc wyścig kończy się złapaniem duplikatu i ponownym SELECT-em.
    public static function findOrCreate(int $editionId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM event_group_conversations WHERE edition_id = :ed');
        $stmt->execute(['ed' => $editionId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $eventStmt = $pdo->prepare('SELECT event_id FROM event_editions WHERE id = :ed');
        $eventStmt->execute(['ed' => $editionId]);
        $eventId = $eventStmt->fetchColumn();
        if ($eventId === false) {
            throw new \RuntimeException("Nie znaleziono turnusu id=$editionId");
        }

        try {
            $pdo->prepare('INSERT INTO event_group_conversations (edition_id, event_id) VALUES (:ed, :ev)')
                ->execute(['ed' => $editionId, 'ev' => (int) $eventId]);
            return (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            $stmt->execute(['ed' => $editionId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
            throw $e;
        }
    }

    public static function postMessage(int $groupConversationId, int $senderId, string $body, bool $isOrganizer): int
    {
        $pdo = Database::connection();
        $pdo->prepare('
            INSERT INTO event_group_messages (group_conversation_id, sender_id, body, is_organizer)
            VALUES (:conv, :sender, :body, :is_org)
        ')->execute(['conv' => $groupConversationId, 'sender' => $senderId, 'body' => $body, 'is_org' => $isOrganizer ? 1 : 0]);
        $messageId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE event_group_conversations SET last_message_at = NOW() WHERE id = :id')
            ->execute(['id' => $groupConversationId]);

        // PUSH „WIADOMOŚĆ NA CZACIE" (Etap 8 przebudowy apki, 2026-08-28) —
        // do WSZYSTKICH członków kanału oprócz nadawcy (members() już to
        // filtruje). TREŚĆ WIADOMOŚCI CELOWO NIE WCHODZI, ta sama zasada co
        // w Models\Message::send(). Poza try/catch tu nie trzeba osobno
        // łapać — Push::sendToUser() sam łyka własne błędy.
        $edition = $pdo->prepare('
            SELECT gc.edition_id, e.title FROM event_group_conversations gc
            JOIN events e ON e.id = gc.event_id WHERE gc.id = :conv
        ');
        $edition->execute(['conv' => $groupConversationId]);
        $row = $edition->fetch();
        if ($row) {
            $url = \Utils\View::url('/wiadomosci/grupa/' . (int) $row['edition_id']);
            // BRAMKA (2026-09-11, migr. 081) — TRANSAKCYJNY, bez budżetu
            // i bez ciszy nocnej. Klucz to id wiadomości, a wiersz w dzienniku
            // powstaje PER ODBIORCA (`UNIQUE (user_id, type, dedupe_key)`),
            // więc każdy członek kanału dostaje swoją jedną sztukę.
            foreach (self::members((int) $row['edition_id'], $senderId) as $member) {
                if (NotificationGate::claim(
                        (int) $member['user_id'],
                        NotificationGate::WIADOMOSC,
                        'grp:' . $messageId
                    ) === null) {
                    continue;
                }
                // Treść z panelu (migr. 084); `{wyjazd}` to tytuł wydarzenia.
                $znaczniki = ['wyjazd' => (string) $row['title']];
                \Core\Lang::with(\Models\User::langOf((int) ((int) $member['user_id'])), static fn() => \Core\Push::sendToUser(
                    (int) $member['user_id'],
                    NotificationTexts::render('message_group.push.title', $znaczniki, [], true),
                    NotificationTexts::render('message_group.push.body', $znaczniki, [], true),
                    ['url' => $url]
                ));
            }
        }

        return $messageId;
    }

    public static function thread(int $groupConversationId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT gm.id, gm.sender_id, gm.body, gm.is_organizer, gm.created_at,
                   u.name AS sender_name, u.email AS sender_email
            FROM event_group_messages gm
            JOIN users u ON u.id = gm.sender_id
            WHERE gm.group_conversation_id = :conv
            ORDER BY gm.created_at ASC
        ');
        $stmt->execute(['conv' => $groupConversationId]);
        return $stmt->fetchAll();
    }

    // Adresaci powiadomień: organizator + współpracownicy + realni uczestnicy
    // turnusu, z pominięciem nadawcy. Zwraca id/name/email do maila.
    public static function members(int $editionId, ?int $excludeUserId = null): array
    {
        $exclude = self::MEMBER_STATUS_EXCLUDE;
        $stmt = Database::connection()->prepare("
            SELECT DISTINCT u.id AS user_id, u.name, u.email FROM (
                SELECT e.organizer_id AS uid FROM event_editions ed JOIN events e ON e.id = ed.event_id WHERE ed.id = :ed_org
                UNION
                SELECT oc.user_id FROM event_editions ed2 JOIN events e2 ON e2.id = ed2.event_id
                    JOIN organizer_collaborators oc ON oc.organizer_user_id = e2.organizer_id WHERE ed2.id = :ed_collab
                UNION
                SELECT r.user_id FROM event_rsvps r JOIN dictionary_items rdi ON rdi.id = r.status_item_id
                    WHERE r.edition_id = :ed_rsvp AND rdi.code NOT IN $exclude
            ) m
            JOIN users u ON u.id = m.uid
            WHERE (:exclude_uid IS NULL OR u.id != :exclude_uid2)
        ");
        $stmt->execute([
            'ed_org' => $editionId, 'ed_collab' => $editionId, 'ed_rsvp' => $editionId,
            'exclude_uid' => $excludeUserId, 'exclude_uid2' => $excludeUserId,
        ]);
        return $stmt->fetchAll();
    }

    public static function markRead(int $groupConversationId, int $userId): void
    {
        Database::connection()->prepare('
            INSERT INTO event_group_reads (group_conversation_id, user_id, last_read_at)
            VALUES (:conv, :uid, NOW())
            ON DUPLICATE KEY UPDATE last_read_at = NOW()
        ')->execute(['conv' => $groupConversationId, 'uid' => $userId]);
    }

    // Nieprzeczytane ze WSZYSTKICH kanałów, do których user należy — po
    // last_read_at, nie własne. Membership sprawdzane inline (bez tabeli członków).
    public static function unreadCountForUser(int $userId): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM event_group_messages gm
            JOIN event_group_conversations gc ON gc.id = gm.group_conversation_id
            LEFT JOIN event_group_reads gr ON gr.group_conversation_id = gc.id AND gr.user_id = :uid_read
            WHERE gm.sender_id != :uid_sender
              AND (gr.last_read_at IS NULL OR gm.created_at > gr.last_read_at)
              AND " . self::membershipClause('gc.event_id', 'gc.edition_id', 'u') . "
        ";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(array_merge(
            ['uid_read' => $userId, 'uid_sender' => $userId],
            self::membershipParams($userId, 'u')
        ));
        return (int) $stmt->fetchColumn();
    }

    // Wiersze skrzynki (tylko kanały z jakąkolwiek wiadomością) — kształt
    // scalany w MessageController::inbox() z konwersacjami 1:1.
    public static function inboxRowsForUser(int $userId): array
    {
        $sql = "
            SELECT gc.id AS group_conversation_id, gc.edition_id, gc.event_id, gc.last_message_at,
                   e.title AS event_title, e.slug AS event_slug, ed.start_date AS edition_date,
                   (SELECT body FROM event_group_messages WHERE group_conversation_id = gc.id ORDER BY created_at DESC LIMIT 1) AS last_body,
                   (SELECT u2.name FROM event_group_messages gm2 JOIN users u2 ON u2.id = gm2.sender_id
                        WHERE gm2.group_conversation_id = gc.id ORDER BY gm2.created_at DESC LIMIT 1) AS last_sender_name,
                   (SELECT COUNT(*) FROM event_group_messages gm3
                        LEFT JOIN event_group_reads gr ON gr.group_conversation_id = gc.id AND gr.user_id = :uid_read
                        WHERE gm3.group_conversation_id = gc.id AND gm3.sender_id != :uid_sender
                          AND (gr.last_read_at IS NULL OR gm3.created_at > gr.last_read_at)) AS unread_count
            FROM event_group_conversations gc
            JOIN event_editions ed ON ed.id = gc.edition_id
            JOIN events e ON e.id = ed.event_id
            WHERE gc.last_message_at IS NOT NULL
              AND " . self::membershipClause('gc.event_id', 'gc.edition_id', 'i') . "
            ORDER BY gc.last_message_at DESC
        ";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(array_merge(
            ['uid_read' => $userId, 'uid_sender' => $userId],
            self::membershipParams($userId, 'i')
        ));
        return $stmt->fetchAll();
    }

    // WSZYSTKIE kanały, do których user może wejść (jest członkiem), także te
    // jeszcze PUSTE (bez wiadomości) — pod panel „Nowa rozmowa" w messengerze,
    // żeby dało się ZACZĄĆ dyskusję grupy, która się nie rozpoczęła. Inaczej niż
    // inboxRowsForUser(), które pokazuje tylko kanały z wiadomością.
    public static function startableChannelsForUser(int $userId, int $limit = 50): array
    {
        $sql = "
            SELECT ed.id AS edition_id, e.id AS event_id, e.title AS event_title,
                   e.slug AS event_slug, ed.start_date AS edition_date,
                   (gc.id IS NOT NULL) AS has_channel
            FROM event_editions ed
            JOIN events e ON e.id = ed.event_id
            LEFT JOIN event_group_conversations gc ON gc.edition_id = ed.id
            WHERE " . self::membershipClause('e.id', 'ed.id', 's') . "
            ORDER BY ed.start_date DESC
            LIMIT " . (int) $limit . "
        ";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(self::membershipParams($userId, 's'));
        return $stmt->fetchAll();
    }

    // Nagłówek wątku + link do eventu (tytuł, slug, data turnusu, event_id,
    // organizer_id — pod plakietkę/regułę maili w kontrolerze).
    public static function headerInfo(int $editionId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT e.id AS event_id, e.organizer_id, e.title AS event_title, e.slug AS event_slug, ed.start_date AS edition_date
            FROM event_editions ed JOIN events e ON e.id = ed.event_id WHERE ed.id = :ed
        ');
        $stmt->execute(['ed' => $editionId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
