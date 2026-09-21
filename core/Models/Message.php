<?php
// core/Models/Message.php
namespace Models;

use Core\Database;

// Prosty moduł wiadomości 1:1 — bez grupowych wątków, bez czatu na żywo
// (odświeżenie strony wystarcza, tak jak w Dyskusji/Komentarzach pod eventem).
// Rozpoczęcie konwersacji jest bramkowane relacją "wspólne wydarzenie"
// (canMessage()) — nie jest to otwarte DM dla dowolnej pary userów, żeby nie
// dało się pisać do przypadkowych obcych bez żadnego mechanizmu moderacji.
class Message
{
    // Dwóch userów może do siebie napisać, jeśli istnieje choć jedno wydarzenie,
    // z którym OBAJ są "związani" — jako organizator/współpracownik (patrz
    // EventPermission::canEdit) ALBO jako uczestnik z zapisem innym niż
    // 'anulowany'. To jedna reguła obsługująca oba żądane kierunki naraz:
    // organizator<->uczestnik ORAZ uczestnicy tego samego eventu między sobą.
    public static function canMessage(int $userA, int $userB): bool
    {
        if ($userA === $userB) {
            return false;
        }

        $stmt = Database::connection()->prepare("
            SELECT 1 FROM events e
            WHERE (
                e.organizer_id = :org_a
                OR EXISTS (SELECT 1 FROM organizer_collaborators oc_a WHERE oc_a.organizer_user_id = e.organizer_id AND oc_a.user_id = :collab_a)
                OR EXISTS (
                    SELECT 1 FROM event_rsvps r_a
                    JOIN dictionary_items rdi_a ON rdi_a.id = r_a.status_item_id
                    WHERE r_a.event_id = e.id AND r_a.user_id = :rsvp_a AND rdi_a.code != 'anulowany'
                )
            )
            AND (
                e.organizer_id = :org_b
                OR EXISTS (SELECT 1 FROM organizer_collaborators oc_b WHERE oc_b.organizer_user_id = e.organizer_id AND oc_b.user_id = :collab_b)
                OR EXISTS (
                    SELECT 1 FROM event_rsvps r_b
                    JOIN dictionary_items rdi_b ON rdi_b.id = r_b.status_item_id
                    WHERE r_b.event_id = e.id AND r_b.user_id = :rsvp_b AND rdi_b.code != 'anulowany'
                )
            )
            LIMIT 1
        ");
        $stmt->execute([
            'org_a' => $userA, 'collab_a' => $userA, 'rsvp_a' => $userA,
            'org_b' => $userB, 'collab_b' => $userB, 'rsvp_b' => $userB,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    // Organizatorzy wydarzeń, na które user jest zapisany (realny zapis) — pod
    // panel „Nowa rozmowa" w messengerze („listę organizatorów, do których mogę
    // pisać"). Zawsze spełniają canMessage() (wspólne wydarzenie), więc lista
    // jest gotowym punktem startu wątku 1:1. Bez samego siebie.
    public static function organizerContactsForUser(int $userId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare("
            SELECT u.id AS user_id, u.name, u.email,
                   MAX(e.title) AS sample_event_title, COUNT(DISTINCT e.id) AS shared_events
            FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code != 'anulowany'
            JOIN events e ON e.id = r.event_id
            JOIN users u ON u.id = e.organizer_id
            WHERE r.user_id = :uid AND e.organizer_id != :uid2
            GROUP BY u.id, u.name, u.email
            ORDER BY shared_events DESC, u.name ASC
            LIMIT " . (int) $limit . "
        ");
        $stmt->execute(['uid' => $userId, 'uid2' => $userId]);
        return $stmt->fetchAll();
    }

    // user_low_id/user_high_id = min/max, żeby ta sama para nigdy nie dostała
    // dwóch wierszy niezależnie od tego, kto pisze pierwszy. Idempotentne.
    public static function findOrCreateConversation(int $userA, int $userB): int
    {
        $low  = min($userA, $userB);
        $high = max($userA, $userB);

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM conversations WHERE user_low_id = :low AND user_high_id = :high');
        $stmt->execute(['low' => $low, 'high' => $high]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $pdo->prepare('INSERT INTO conversations (user_low_id, user_high_id) VALUES (:low, :high)')
            ->execute(['low' => $low, 'high' => $high]);
        return (int) $pdo->lastInsertId();
    }

    // Bez tworzenia — pod guardy tras, gdzie brak konwersacji ma znaczyć "404",
    // nie "utwórz nową".
    public static function findConversation(int $userA, int $userB): ?int
    {
        $stmt = Database::connection()->prepare('
            SELECT id FROM conversations WHERE user_low_id = :low AND user_high_id = :high
        ');
        $stmt->execute(['low' => min($userA, $userB), 'high' => max($userA, $userB)]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    public static function isParticipant(int $conversationId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('
            SELECT 1 FROM conversations WHERE id = :id AND (user_low_id = :uid1 OR user_high_id = :uid2)
        ');
        $stmt->execute(['id' => $conversationId, 'uid1' => $userId, 'uid2' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    // Drugi uczestnik konwersacji (nie $userId) — pod nagłówek wątku i temat maila.
    public static function otherUserId(int $conversationId, int $userId): ?int
    {
        $stmt = Database::connection()->prepare('
            SELECT user_low_id, user_high_id FROM conversations WHERE id = :id
        ');
        $stmt->execute(['id' => $conversationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $low = (int) $row['user_low_id'];
        $high = (int) $row['user_high_id'];
        if ($userId !== $low && $userId !== $high) {
            return null;
        }
        return $userId === $low ? $high : $low;
    }

    public static function send(int $conversationId, int $senderId, string $body): int
    {
        $pdo = Database::connection();
        $pdo->prepare('
            INSERT INTO messages (conversation_id, sender_id, body) VALUES (:conversation_id, :sender_id, :body)
        ')->execute(['conversation_id' => $conversationId, 'sender_id' => $senderId, 'body' => $body]);
        $messageId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = :id')
            ->execute(['id' => $conversationId]);

        // PUSH „WIADOMOŚĆ NA CZACIE" (Etap 8 przebudowy apki, 2026-08-28) —
        // TREŚĆ WIADOMOŚCI CELOWO NIE WCHODZI do powiadomienia (prywatność
        // systemowego powiadomienia — nikt trzeci, kto zerknie na zablokowany
        // ekran telefonu, nie ma przeczytać cudzej prywatnej wiadomości).
        // `Push::sendToUser()` sam łyka własne błędy (wzorzec Core\Mailer),
        // ale zapis wiadomości już się udał niezależnie od tego wywołania.
        // BRAMKA (2026-09-11, migr. 081): zgoda per typ + idempotencja.
        // `message` jest TRANSAKCYJNY, więc nie podlega budżetowi zachęt ani
        // ciszy nocnej — wiadomość, na którą ktoś czeka, ma dojść wtedy, kiedy
        // przyszła. Klucz to id wiadomości: gdyby ta metoda wykonała się dwa
        // razy (ponowienie żądania), drugi przebieg nie wyśle powiadomienia.
        $recipientId = self::otherUserId($conversationId, $senderId);
        if ($recipientId !== null) {
            try {
                if (NotificationGate::claim($recipientId, NotificationGate::WIADOMOSC, 'msg:' . $messageId) !== null) {
                    // Treść z panelu (migr. 084) — bez znaczników, bo treść
                    // wiadomości ani nadawca nie mają wychodzić na ekran blokady.
                    \Core\Lang::with(\Models\User::langOf((int) ($recipientId)), static fn() => \Core\Push::sendToUser(
                        $recipientId,
                        NotificationTexts::render('message.push.title', [], [], true),
                        NotificationTexts::render('message.push.body', [], [], true),
                        ['url' => \Utils\View::url('/wiadomosci/' . $conversationId)]
                    ));
                }
            } catch (\Throwable $e) {
                // Patrz komentarz wyżej.
            }
        }

        return $messageId;
    }

    // Skrzynka odbiorcza — jedna konwersacja na wiersz, z podglądem ostatniej
    // wiadomości i licznikiem nieprzeczytanych (od DRUGIEJ strony, nie od siebie).
    public static function inboxForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT c.id AS conversation_id, c.last_message_at,
                   ou.id AS other_user_id, ou.name AS other_name, ou.email AS other_email,
                   ou.avatar_url AS other_avatar_url,
                   (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_body,
                   (SELECT sender_id FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_sender_id,
                   (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_id != :uid_unread AND m.read_at IS NULL) AS unread_count
            FROM conversations c
            JOIN users ou ON ou.id = IF(c.user_low_id = :uid_low, c.user_high_id, c.user_low_id)
            WHERE c.user_low_id = :uid_where1 OR c.user_high_id = :uid_where2
            ORDER BY c.last_message_at DESC
        ");
        $stmt->execute([
            'uid_unread' => $userId,
            'uid_low'    => $userId,
            'uid_where1' => $userId,
            'uid_where2' => $userId,
        ]);
        return $stmt->fetchAll();
    }

    public static function threadMessages(int $conversationId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT m.id, m.sender_id, m.body, m.created_at, u.name AS sender_name, u.email AS sender_email
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = :conversation_id
            ORDER BY m.created_at ASC
        ');
        $stmt->execute(['conversation_id' => $conversationId]);
        return $stmt->fetchAll();
    }

    public static function markRead(int $conversationId, int $userId): void
    {
        Database::connection()->prepare('
            UPDATE messages SET read_at = NOW()
            WHERE conversation_id = :conversation_id AND sender_id != :user_id AND read_at IS NULL
        ')->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    }

    // Pod badge w headerze (patrz $myEventsCount we header.php) — suma
    // nieprzeczytanych wiadomości od innych, ze WSZYSTKICH konwersacji usera.
    public static function unreadCountForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            WHERE (c.user_low_id = :uid_low OR c.user_high_id = :uid_high)
              AND m.sender_id != :uid_sender AND m.read_at IS NULL
        ');
        $stmt->execute(['uid_low' => $userId, 'uid_high' => $userId, 'uid_sender' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // Wysyłka masowa organizatora — osobna wiadomość 1:1 do KAŻDEGO uczestnika
    // eventu (nie grupowy czat, patrz migration_020). Pomija anulowane zapisy.
    // Permission (czy $organizerId faktycznie może edytować ten event) sprawdza
    // wywołujący (web/routes.php, tak jak przy każdej innej akcji organizatora).
    // Zwraca listę adresatów (id/name/email), żeby wywołujący mógł od razu
    // wysłać maile powiadomień bez drugiego zapytania o uczestników.
    public static function sendBulkToEventParticipants(int $eventId, int $organizerId, string $body): array
    {
        $stmt = Database::connection()->prepare("
            SELECT DISTINCT r.user_id, u.name, u.email
            FROM event_rsvps r
            JOIN users u ON u.id = r.user_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id
            WHERE r.event_id = :event_id AND rdi.code != 'anulowany' AND r.user_id != :organizer_id
        ");
        $stmt->execute(['event_id' => $eventId, 'organizer_id' => $organizerId]);
        $participants = $stmt->fetchAll();

        foreach ($participants as $participant) {
            $conversationId = self::findOrCreateConversation($organizerId, (int) $participant['user_id']);
            self::send($conversationId, $organizerId, $body);
        }

        return $participants;
    }
}
