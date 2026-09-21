<?php
// core/Models/EventComment.php
namespace Models;

use Core\Database;

class EventComment
{
    // Jednopoziomowa dyskusja (schema.sql: "komentarze z jednopoziomowymi
    // odpowiedziami") — zwraca komentarze najwyższego poziomu, każdy z tablicą
    // 'replies' (już posortowaną chronologicznie). Widoczna tylko dla eventów
    // jeszcze niezakończonych (patrz event-page.php).
    public static function forEvent(int $eventId): array
    {
        // LIMIT ochronny (jak Event::mapPins()) — bez paginacji UI, ale bez
        // ryzyka nieograniczonego wzrostu na bardzo aktywnej dyskusji. Dość
        // wysoki (w odróżnieniu od zdjęć/opinii), bo cięcie w środku wątku
        // ucina też odpowiedzi do top-level komentarzy spoza limitu.
        $stmt = Database::connection()->prepare("
            SELECT c.id, c.parent_comment_id, c.body, c.is_organizer_reply, c.is_faq, c.created_at,
                   u.name AS author_name, u.email AS author_email, u.public_slug AS author_slug,
                   u.avatar_url AS author_avatar_url
            FROM event_comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.event_id = :event_id
            ORDER BY c.created_at ASC
            LIMIT 500
        ");
        $stmt->execute(['event_id' => $eventId]);
        $rows = $stmt->fetchAll();

        $byId = [];
        $topLevel = [];
        foreach ($rows as $row) {
            $isFaq = (bool) ($row['is_faq'] ?? false);
            $comment = [
                'id'               => (int) $row['id'],
                'body'             => $row['body'],
                'isOrganizerReply' => (bool) $row['is_organizer_reply'],
                'isFaq'            => $isFaq,
                'createdAt'        => $row['created_at'],
                // Pytanie z pary FAQ jest wpisane przez ORGANIZATORA, ale ma
                // się prezentować anonimowo (patrz migration_034) — podmieniamy
                // autora TU, w jednym miejscu, żeby żaden widok nie musiał o tym
                // pamiętać ani nie miał jak wypaplać prawdziwego nazwiska.
                'authorName'       => $isFaq ? __('Pytanie uczestnika') : ($row['author_name'] ?: $row['author_email']),
                // Slug świadomie NULL przy FAQ — skoro nazwisko organizatora jest
                // tam ukryte, link do jego profilu zdradzałby dokładnie to samo.
                'authorSlug'       => $isFaq ? null : ($row['author_slug'] ?? null),
                // Awatar podlega TEJ SAMEJ anonimizacji co nazwisko i slug
                // (2026-08-22). Zdjęcie organizatora nad „Pytaniem uczestnika"
                // zdradzałoby dokładnie to, co ukrywają dwie linijki wyżej —
                // twarz rozpoznaje się szybciej niż nazwisko.
                'authorAvatarUrl'  => $isFaq ? null : ($row['author_avatar_url'] ?? null),
                'replies'          => [],
            ];
            $byId[$comment['id']] = $comment;
            if ($row['parent_comment_id'] === null) {
                $topLevel[] = $row['id'];
            } else {
                // Zakładamy jednopoziomowość (odpowiedź na odpowiedź trzymamy pod
                // tym samym top-level rodzicem — UI i tak nie pozwala głębiej).
                $rootId = $byId[$row['parent_comment_id']]['id'] ?? $row['parent_comment_id'];
                if (isset($byId[$rootId])) {
                    $byId[$rootId]['replies'][] = $comment;
                }
            }
        }

        return array_values(array_map(fn($id) => $byId[$id], $topLevel));
    }

    public static function create(int $eventId, int $userId, ?int $parentCommentId, string $body, bool $isOrganizerReply, bool $isFaq = false): int
    {
        $pdo = Database::connection();
        $pdo->prepare('
            INSERT INTO event_comments (event_id, user_id, parent_comment_id, body, is_organizer_reply, is_faq)
            VALUES (:event_id, :user_id, :parent_id, :body, :is_organizer_reply, :is_faq)
        ')->execute([
            'event_id'          => $eventId,
            'user_id'           => $userId,
            'parent_id'         => $parentCommentId,
            'body'              => $body,
            'is_organizer_reply' => $isOrganizerReply ? 1 : 0,
            'is_faq'            => $isFaq ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    // Para Q&A wpisana jednym formularzem przez organizatora (patrz
    // migration_034): pytanie top-level oznaczone is_faq (prezentowane
    // anonimowo) + odpowiedź jako zwykła odpowiedź organizatora. W TRANSAKCJI,
    // bo pół pary (pytanie bez odpowiedzi) wyglądałoby jak niedokończone
    // pytanie od uczestnika i czekało na odpowiedź, której nikt już nie doda.
    public static function createFaqPair(int $eventId, int $organizerUserId, string $question, string $answer): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $questionId = self::create($eventId, $organizerUserId, null, $question, false, true);
            self::create($eventId, $organizerUserId, $questionId, $answer, true, false);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $questionId;
    }

    // Moderacja — usunięcie komentarza (i, dzięki ON DELETE CASCADE na
    // parent_comment_id, całego wątku odpowiedzi pod nim). Zawężone do
    // event_id, żeby spreparowany id z innego wydarzenia nie przeszedł, nawet
    // gdy wywołujący ma uprawnienia do TEGO wydarzenia. Zwraca false, gdy nic
    // nie skasowano (już usunięty albo cudzy) — wywołujący nie musi rozróżniać.
    public static function delete(int $commentId, int $eventId): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM event_comments WHERE id = :id AND event_id = :event_id');
        $stmt->execute(['id' => $commentId, 'event_id' => $eventId]);
        return $stmt->rowCount() > 0;
    }

    // Zbiorczy widok moderacji (2026-08-09, zgłoszenie usera: "a gdzie moduł
    // zarządzania dyskusją? nie jest widoczny z poziomu panelu") — WSZYSTKIE
    // wpisy z wydarzeń, którymi zarządza dany user, w jednym miejscu.
    // Bez tego jedyną drogą do moderacji było wejście na stronę KAŻDEGO
    // wydarzenia z osobna — nie do przejścia przy kilkudziesięciu, a tym
    // bardziej przy tysiącach po stronie admina.
    //
    // $organizerUserId === null => wszystko (widok admina), inaczej: wydarzenia
    // własne LUB te, gdzie user jest współpracownikiem — ten sam krąg co
    // EventPermission::canEdit(), żeby lista pokazywała dokładnie to, co
    // wolno moderować (inaczej user widziałby wpisy, których i tak nie skasuje).
    //
    // is_answered — czy pod pytaniem jest odpowiedź ORGANIZATORA; dla
    // organizatora to realna lista zadań ("na co jeszcze nie odpowiedziałem"),
    // nie tylko narzędzie cenzury.
    // $eventSlug — zawężenie do JEDNEGO wydarzenia (wejście z wiersza w panelu:
    // "pokaż mi dyskusję TEGO wyjazdu"). Filtrowanie po slugu, a nie po id,
    // żeby link z panelu był czytelny i dało się go komuś wysłać.
    public static function forModeration(?int $organizerUserId, string $q = '', bool $onlyUnanswered = false, int $limit = 200, string $eventSlug = ''): array
    {
        $params = [];
        $where = ["1=1"];

        if ($eventSlug !== '') {
            $where[] = 'e.slug = :slug';
            $params['slug'] = $eventSlug;
        }
        if ($organizerUserId !== null) {
            $where[] = "(e.organizer_id = :uid OR e.organizer_id IN (
                SELECT organizer_user_id FROM organizer_collaborators WHERE user_id = :uid2))";
            $params['uid'] = $organizerUserId;
            $params['uid2'] = $organizerUserId;
        }
        if (trim($q) !== '') {
            // Dwa osobne placeholdery — EMULATE_PREPARES=false nie pozwala
            // użyć tej samej nazwy dwa razy (patrz md/database.md).
            $where[] = "(c.body LIKE :q1 OR e.title LIKE :q2)";
            $params['q1'] = '%' . trim($q) . '%';
            $params['q2'] = '%' . trim($q) . '%';
        }
        if ($onlyUnanswered) {
            // Tylko pytania (top-level) BEZ odpowiedzi organizatora.
            // is_organizer_reply = 0 na SAMYM wpisie jest tu równie ważne co
            // brak odpowiedzi: wątek założony przez organizatora nie jest
            // pytaniem czekającym na jego własną odpowiedź. Bez tego warunku
            // filtr rozjeżdżał się z licznikiem w panelu wydarzeń i z flagą
            // needsAnswer niżej (złapane testem: panel pokazywał 0, lista 2).
            $where[] = "c.parent_comment_id IS NULL AND c.is_organizer_reply = 0
                AND NOT EXISTS (
                SELECT 1 FROM event_comments rr
                 WHERE rr.parent_comment_id = c.id AND rr.is_organizer_reply = 1)";
        }

        $sql = "
            SELECT c.id, c.body, c.created_at, c.is_organizer_reply, c.is_faq,
                   c.parent_comment_id,
                   e.slug AS event_slug, e.title AS event_title,
                   u.name AS author_name, u.email AS author_email,
                   NOT EXISTS (SELECT 1 FROM event_comments r2
                                WHERE r2.parent_comment_id = c.id AND r2.is_organizer_reply = 1) AS needs_answer
            FROM event_comments c
            JOIN events e ON e.id = c.event_id
            JOIN users u ON u.id = c.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.created_at DESC
            LIMIT " . (int) $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn($r) => [
            'id'          => (int) $r['id'],
            'body'        => $r['body'],
            'createdAt'   => $r['created_at'],
            'isReply'     => $r['parent_comment_id'] !== null,
            'isOrganizerReply' => (bool) $r['is_organizer_reply'],
            'isFaq'       => (bool) $r['is_faq'],
            // Ta sama anonimizacja co forEvent() — pytanie z FAQ nigdy nie
            // pokazuje nazwiska organizatora, nawet w panelu moderacji.
            'authorName'  => $r['is_faq'] ? __('Pytanie uczestnika') : ($r['author_name'] ?: $r['author_email']),
            'eventSlug'   => $r['event_slug'],
            'eventTitle'  => $r['event_title'],
            // Sensowne wyłącznie dla pytań top-level (odpowiedź nie potrzebuje odpowiedzi).
            'needsAnswer' => $r['parent_comment_id'] === null && (bool) $r['needs_answer'] && !$r['is_organizer_reply'],
        ], $stmt->fetchAll());
    }

    // Ile pytań czeka na odpowiedź organizatora — pod licznik w panelu.
    public static function unansweredCountFor(?int $organizerUserId): int
    {
        return count(self::forModeration($organizerUserId, '', true, 1000));
    }

    // Pary Q&A gotowe do structured data (schema.org) — wyłącznie wątki, gdzie
    // pytanie MA odpowiedź; pytanie bez odpowiedzi nie jest FAQ, tylko
    // otwartym wątkiem, i nie ma czego pokazać wyszukiwarce.
    public static function faqPairsForEvent(int $eventId): array
    {
        $pairs = [];
        foreach (self::forEvent($eventId) as $c) {
            if (empty($c['replies'])) {
                continue;
            }
            // Pierwsza odpowiedź ORGANIZATORA jest odpowiedzią kanoniczną.
            foreach ($c['replies'] as $r) {
                if (!empty($r['isOrganizerReply'])) {
                    $pairs[] = ['question' => $c['body'], 'answer' => $r['body']];
                    break;
                }
            }
        }
        return $pairs;
    }

    // Sprawdza, że dany komentarz faktycznie należy do tego eventu i jest
    // top-level (parent_comment_id NULL) — do walidacji parent_comment_id
    // przesłanego z formularza, żeby nie dało się odpowiedzieć na odpowiedź
    // ani podpiąć się pod komentarz z innego eventu. Zwraca też autora, żeby
    // route mógł go powiadomić o odpowiedzi bez dodatkowego zapytania.
    public static function findTopLevelOfEvent(int $commentId, int $eventId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT c.id, c.user_id, u.name AS author_name, u.email AS author_email
            FROM event_comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.id = :id AND c.event_id = :event_id AND c.parent_comment_id IS NULL
        ');
        $stmt->execute(['id' => $commentId, 'event_id' => $eventId]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id'          => (int) $row['id'],
            'userId'      => (int) $row['user_id'],
            'authorName'  => $row['author_name'],
            'authorEmail' => $row['author_email'],
        ];
    }
}
