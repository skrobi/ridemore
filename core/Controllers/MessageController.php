<?php
// core/Controllers/MessageController.php
// Wiadomości — DWA rodzaje kanałów w jednej skrzynce:
//   1) 1:1 (Models\Message) — bramkowane relacją "wspólne wydarzenie".
//   2) grupowe PER TURNUS (Models\EventGroupConversation) — prywatna dyskusja
//      całej grupy zapisanych na turnus + organizator (patrz migration_028).
// Skrzynka scala oba (mergedInbox()); wątek renderuje się z flagą isGroup.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\Event;
use Models\EventGroupConversation;
use Models\EventPermission;
use Models\Message;
use Models\NotificationGate;
use Models\NotificationTexts;
use Utils\MailTemplate;
use Models\Notifier;
use Models\User;
use Utils\Format;
use Utils\View;

class MessageController
{
    // Ujednolicone wiersze skrzynki: konwersacje 1:1 + kanały grupowe, posortowane
    // malejąco po czasie ostatniej wiadomości. Widok (messages.php) renderuje
    // znormalizowany kształt, nie zna dwóch osobnych źródeł.
    private static function mergedInbox(int $userId): array
    {
        $rows = [];

        foreach (Message::inboxForUser($userId) as $c) {
            $title = $c['other_name'] ?: $c['other_email'];
            $rows[] = [
                'key'      => 'c' . $c['conversation_id'],
                'is_group' => false,
                'href'     => View::url('/wiadomosci/' . $c['conversation_id']),
                // Litera zostaje ZAPASEM, nie znika (2026-08-22): wiersz bez
                // wgranego zdjęcia ma wyglądać dokładnie tak jak dotąd.
                'avatar'   => mb_strtoupper(mb_substr($title, 0, 1)),
                'avatarUrl'=> $c['other_avatar_url'] ?? null,
                'title'    => $title,
                'subtitle' => null,
                'time'     => $c['last_message_at'],
                'preview'  => ((int) $c['last_sender_id'] === $userId ? 'Ty: ' : '') . (string) $c['last_body'],
                'unread'   => (int) $c['unread_count'],
            ];
        }

        foreach (EventGroupConversation::inboxRowsForUser($userId) as $g) {
            $rows[] = [
                'key'      => 'g' . $g['edition_id'],
                'is_group' => true,
                'href'     => View::url('/wiadomosci/grupa/' . $g['edition_id']),
                'avatar'   => null, // widok rysuje ikonę grupy
                'avatarUrl'=> null,
                'title'    => $g['event_title'],
                'subtitle' => 'Grupa · ' . Format::dateShort($g['edition_date']),
                'time'     => $g['last_message_at'],
                'preview'  => ($g['last_sender_name'] ? $g['last_sender_name'] . ': ' : '') . (string) $g['last_body'],
                'unread'   => (int) $g['unread_count'],
            ];
        }

        usort($rows, fn($a, $b) => strtotime((string) $b['time']) <=> strtotime((string) $a['time']));
        return $rows;
    }

    public static function inbox(): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        View::render('web', 'messages', [
            'title'         => __('Wiadomości — ridemore.bike'),
            'conversations' => self::mergedInbox($user->id),
            'currentUserId' => $user->id,
            'startChannels'  => EventGroupConversation::startableChannelsForUser($user->id),
            'startOrganizers' => Message::organizerContactsForUser($user->id),
            'activeKey'     => null,
            'activeThread'  => null,
            'breadcrumbs'   => [Support::homeCrumb(), ['label' => __('Wiadomości')]],
        ]);
    }

    public static function withUserShow(string $userId): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $targetId = (int) $userId;

        $target = User::find($targetId);
        if (!$target || !Message::canMessage($user->id, $targetId)) {
            http_response_code(403);
            echo __('Nie możesz napisać do tego użytkownika — nie łączy Was żadne wspólne wydarzenie.');
            return;
        }

        // Bez tworzenia konwersacji tutaj — to GET, nie powinno mieć efektów
        // ubocznych w bazie (prefetch przeglądarki, boty, skanery linków mogłyby
        // tworzyć puste wątki). Jeśli konwersacja już istnieje, po prostu do niej
        // wchodzimy; jeśli nie, pokazujemy pusty wątek gotowy do napisania —
        // realnie powstaje dopiero przy wysyłce, patrz withUserSend() niżej.
        $conversationId = Message::findConversation($user->id, $targetId);
        if ($conversationId !== null) {
            header('Location: ' . View::url('/wiadomosci/' . $conversationId));
            exit;
        }

        View::render('web', 'messages', [
            'title'         => __('Wiadomości: {kto} — ridemore.bike', ['kto' => $target->name ?: $target->email]),
            'conversations' => self::mergedInbox($user->id),
            'currentUserId' => $user->id,
            'startChannels'  => EventGroupConversation::startableChannelsForUser($user->id),
            'startOrganizers' => Message::organizerContactsForUser($user->id),
            'activeKey'     => null,
            'activeThread'  => [
                'isGroup'   => false,
                'title'     => $target->name ?: $target->email,
                'messages'  => [],
            ],
            'sendAction'    => '/wiadomosci/z/' . $targetId . '/wyslij',
            'breadcrumbs'   => [Support::homeCrumb(), ['label' => __('Wiadomości'), 'url' => View::url('/wiadomosci')], ['label' => $target->name ?: $target->email]],
        ]);
    }

    public static function withUserSend(string $userId): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $targetId = (int) $userId;
        $target = User::find($targetId);

        if (!Csrf::check($_POST['csrf_token'] ?? null) || !$target || !Message::canMessage($user->id, $targetId)) {
            header('Location: ' . View::url('/wiadomosci'));
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        if ($body === '') {
            header('Location: ' . View::url('/wiadomosci/z/' . $targetId));
            exit;
        }

        // Tu, i dopiero tu, konwersacja realnie powstaje (POST, nie GET).
        $conversationId = Message::findOrCreateConversation($user->id, $targetId);
        // Mail idzie przez most (Etap 1c, 2026-09-11), a nie wprost przez
        // Mailer: dzięki temu przełącznik „Wiadomości — mailem" w koncie
        // cokolwiek znaczy, a ponowione żądanie nie wyśle drugiego maila o tej
        // samej wiadomości. Push tej wiadomości wysyła Models\Message::send()
        // — ten sam klucz, drugi kanał, więc jedno drugiego nie zasłania.
        $messageId = Message::send($conversationId, $user->id, $body);
        \Core\Lang::with(\Models\User::langOf((int) ($targetId)), static fn() => Notifier::wyslij($targetId, NotificationGate::WIADOMOSC, 'msg:' . $messageId, [], [
            'to'       => $target->email,
            'subject'  => NotificationTexts::render('message.mail.subject', ['nadawca' => $user->name ?: $user->email], [], true),
            'template' => 'custom',
            'data'     => [
                'bodyHtml' => NotificationTexts::render(
                    'message.mail.body',
                    ['nadawca' => $user->name ?: $user->email],
                    [
                        'powitanie' => MailTemplate::greeting($target->displayName()),
                        'cytat'     => NotificationTexts::blokCytat($body),
                        'przycisk'  => MailTemplate::button(View::absoluteUrl('/wiadomosci/' . $conversationId), __('Odpowiedz →')),
                    ]
                ),
            ],
        ]));

        header('Location: ' . View::url('/wiadomosci/' . $conversationId));
        exit;
    }

    public static function show(string $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $conversationId = (int) $id;

        if (!Message::isParticipant($conversationId, $user->id)) {
            http_response_code(403);
            echo __('Nie masz dostępu do tej konwersacji.');
            return;
        }

        $otherUserId = Message::otherUserId($conversationId, $user->id);
        $otherUser = $otherUserId !== null ? User::find($otherUserId) : null;
        if (!$otherUser) {
            http_response_code(404);
            echo __('Nie znaleziono konwersacji.');
            return;
        }

        Message::markRead($conversationId, $user->id);

        View::render('web', 'messages', [
            'title'         => __('Wiadomości: {kto} — ridemore.bike', ['kto' => $otherUser->name ?: $otherUser->email]),
            'conversations' => self::mergedInbox($user->id),
            'currentUserId' => $user->id,
            'startChannels'  => EventGroupConversation::startableChannelsForUser($user->id),
            'startOrganizers' => Message::organizerContactsForUser($user->id),
            'activeKey'     => 'c' . $conversationId,
            'activeThread'  => [
                'isGroup'  => false,
                'title'    => $otherUser->name ?: $otherUser->email,
                'messages' => Message::threadMessages($conversationId),
            ],
            'sendAction'    => '/wiadomosci/' . $conversationId . '/wyslij',
            'breadcrumbs'   => [Support::homeCrumb(), ['label' => __('Wiadomości'), 'url' => View::url('/wiadomosci')], ['label' => $otherUser->name ?: $otherUser->email]],
        ]);
    }

    public static function send(string $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $conversationId = (int) $id;

        if (!Csrf::check($_POST['csrf_token'] ?? null) || !Message::isParticipant($conversationId, $user->id)) {
            header('Location: ' . View::url('/wiadomosci'));
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            $messageId = Message::send($conversationId, $user->id, $body);

            $otherUserId = Message::otherUserId($conversationId, $user->id);
            $otherUser = $otherUserId !== null ? User::find($otherUserId) : null;
            if ($otherUser) {
                // Patrz komentarz przy pierwszej wysyłce wyżej — mail przez
                // most, żeby podlegał zgodzie i nie wyszedł dwa razy.
                \Core\Lang::with(\Models\User::langOf((int) ($otherUser->id)), static fn() => Notifier::wyslij($otherUser->id, NotificationGate::WIADOMOSC, 'msg:' . $messageId, [], [
                    'to'       => $otherUser->email,
                    'subject'  => NotificationTexts::render('message.mail.subject', ['nadawca' => $user->name ?: $user->email], [], true),
                    'template' => 'custom',
                    'data'     => [
                        'bodyHtml' => NotificationTexts::render(
                            'message.mail.body',
                            ['nadawca' => $user->name ?: $user->email],
                            [
                                'powitanie' => MailTemplate::greeting($otherUser->displayName()),
                                'cytat'     => NotificationTexts::blokCytat($body),
                                'przycisk'  => MailTemplate::button(View::absoluteUrl('/wiadomosci/' . $conversationId), __('Odpowiedz →')),
                            ]
                        ),
                    ],
                ]));
            }
        }

        header('Location: ' . View::url('/wiadomosci/' . $conversationId));
        exit;
    }

    // ===== Kanał grupowy wydarzenia (per turnus) =====

    public static function groupShow(string $editionId): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $editionId = (int) $editionId;

        if (!EventGroupConversation::canAccess($editionId, $user->id, $user->isAdmin)) {
            http_response_code(403);
            echo __('Nie masz dostępu do dyskusji tej grupy — nie jesteś zapisany/a na ten termin.');
            return;
        }

        $info = EventGroupConversation::headerInfo($editionId);
        if (!$info) {
            http_response_code(404);
            echo __('Nie znaleziono grupy.');
            return;
        }

        // Bez tworzenia na GET — kanał powstaje przy pierwszej wysyłce. Pusty =
        // pusty wątek gotowy do napisania.
        $conversationId = EventGroupConversation::findByEdition($editionId);
        $messages = [];
        if ($conversationId !== null) {
            $messages = EventGroupConversation::thread($conversationId);
            EventGroupConversation::markRead($conversationId, $user->id);
        }

        View::render('web', 'messages', [
            'title'         => __('Grupa: {tytul} — ridemore.bike', ['tytul' => $info['event_title']]),
            'conversations' => self::mergedInbox($user->id),
            'currentUserId' => $user->id,
            'startChannels'  => EventGroupConversation::startableChannelsForUser($user->id),
            'startOrganizers' => Message::organizerContactsForUser($user->id),
            'activeKey'     => 'g' . $editionId,
            'activeThread'  => [
                'isGroup'   => true,
                'title'     => $info['event_title'],
                'subtitle'  => 'Dyskusja grupy · ' . Format::dateP($info['edition_date']),
                'eventLink' => View::url('/events/' . $info['event_slug']) . '?termin=' . $editionId,
                'messages'  => $messages,
            ],
            'sendAction'    => '/wiadomosci/grupa/' . $editionId . '/wyslij',
            'breadcrumbs'   => [Support::homeCrumb(), ['label' => __('Wiadomości'), 'url' => View::url('/wiadomosci')], ['label' => 'Grupa: ' . $info['event_title']]],
        ]);
    }

    public static function groupSend(string $editionId): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $editionId = (int) $editionId;

        if (!Csrf::check($_POST['csrf_token'] ?? null) || !EventGroupConversation::canAccess($editionId, $user->id, $user->isAdmin)) {
            header('Location: ' . View::url('/wiadomosci'));
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        $info = EventGroupConversation::headerInfo($editionId);
        if ($body !== '' && $info) {
            $isOrganizer = EventPermission::canEdit($user, (int) $info['organizer_id']);
            $conversationId = EventGroupConversation::findOrCreate($editionId);
            $grpMessageId = EventGroupConversation::postMessage($conversationId, $user->id, $body, $isOrganizer);
            self::notifyGroupMembers($editionId, $user, $body, $isOrganizer, (string) $info['event_title'], $grpMessageId);
        }

        header('Location: ' . View::url('/wiadomosci/grupa/' . $editionId));
        exit;
    }

    // Powiadomienia mailowe kanału grupowego — świadomie TYLKO dla wiadomości
    // organizatora (broadcast/ogłoszenie), żeby zwykły czat uczestników nie
    // zasypywał skrzynek. Wiadomości uczestników zostają w aplikacji + badge.
    //
    // Od Etapu 1c (2026-09-11) mail idzie przez most, więc respektuje zgodę
    // „Wiadomości — mailem" i klucz `grp:{id}` — ten sam, którym posługuje się
    // pushowa połowa w EventGroupConversation::postMessage(). Dwa kanały, jeden
    // klucz, po jednej sztuce każdego.
    private static function notifyGroupMembers(int $editionId, User $sender, string $body, bool $isOrganizer, string $eventTitle, int $messageId): void
    {
        if (!$isOrganizer) {
            return;
        }
        $link = View::absoluteUrl('/wiadomosci/grupa/' . $editionId);
        foreach (EventGroupConversation::members($editionId, $sender->id) as $member) {
            \Core\Lang::with(\Models\User::langOf((int) ((int) $member['user_id'])), static fn() => Notifier::wyslij((int) $member['user_id'], NotificationGate::WIADOMOSC, 'grp:' . $messageId, [], [
                'to'       => $member['email'],
                'subject'  => NotificationTexts::render('message_group.mail.subject', [
                    'wyjazd'  => $eventTitle,
                    'nadawca' => $sender->name ?: $sender->email,
                ], [], true),
                'template' => 'custom',
                'data'     => [
                    'bodyHtml' => NotificationTexts::render(
                        'message_group.mail.body',
                        [
                            'wyjazd'  => $eventTitle,
                            'nadawca' => ($sender->name ?: $sender->email) . ' (organizator)',
                        ],
                        [
                            'powitanie' => MailTemplate::greeting($member['name'] ?: $member['email']),
                            'cytat'     => NotificationTexts::blokCytat($body),
                            'przycisk'  => MailTemplate::button($link, __('Otwórz kanał grupowy →')),
                        ]
                    ),
                ],
            ]));
        }
    }

    // „Napisz do wszystkich uczestników" z listy uczestników — publikuje do
    // KANAŁU GRUPOWEGO (jedno miejsce, wszyscy widzą to samo) zamiast dawnego
    // fan-outu do N wątków 1:1. Lista uczestników jest event-wide, więc
    // rozdystrybuowujemy do kanału KAŻDEGO turnusu (organizator ogłasza wszystkim
    // grupom). Permission sprawdza wywołujący warunek niżej.
    public static function sendBulkToParticipants(string $slug): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;

        if (!Csrf::check($_POST['csrf_token'] ?? null) || !EventPermission::canEdit($user, $event->organizerId)) {
            header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            $isOrganizer = EventPermission::canEdit($user, $event->organizerId); // zawsze true tutaj, ale utrwalamy poprawnie
            foreach (EventGroupConversation::editionIdsForEvent($event->id) as $editionId) {
                $conversationId = EventGroupConversation::findOrCreate($editionId);
                $grpMessageId = EventGroupConversation::postMessage($conversationId, $user->id, $body, $isOrganizer);
                self::notifyGroupMembers($editionId, $user, $body, $isOrganizer, $event->title, $grpMessageId);
            }
        }

        header('Location: ' . View::url('/wydarzenia/' . $event->slug . '/uczestnicy'));
        exit;
    }
}
