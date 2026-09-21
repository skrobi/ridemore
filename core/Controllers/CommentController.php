<?php
// core/Controllers/CommentController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\Event;
use Models\EventComment;
use Models\EventPermission;
use Models\Organizer;
use Models\User;
use Utils\View;

class CommentController
{
    public static function store(string $slug): void
    {
        $user = Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        // Dyskusja jest tylko dla eventów, które się jeszcze nie odbyły — po
        // zakończeniu ten sam formularz nie ma już sensu (jest "Relacje i opinie").
        if ($event->statusCode === 'completed' || !Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        if ($body === '') {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }

        $parentId = null;
        $parentComment = null;
        if (!empty($_POST['parent_comment_id']) && is_numeric($_POST['parent_comment_id'])) {
            $parentComment = EventComment::findTopLevelOfEvent((int) $_POST['parent_comment_id'], $event->id);
            if ($parentComment !== null) {
                $parentId = $parentComment['id'];
            }
        }

        $isOrganizerReply = EventPermission::canEdit($user, $event->organizerId);
        EventComment::create($event->id, $user->id, $parentId, $body, $isOrganizerReply);

        // Powiadomienie mailem dla autora pytania, że dostał odpowiedź — pomijamy
        // gdy ktoś odpowiada sam sobie. Brak driverów push/SMS w tej aplikacji,
        // więc mail to jedyny kanał (ten sam wzorzec co zaproszenia do opinii).
        if ($parentComment !== null && $parentComment['userId'] !== $user->id) {
            \Core\Lang::with(\Core\Lang::forEmail((string) ($parentComment['authorEmail'])), static fn() => Mailer::sendTemplate('discussion-reply', $parentComment['authorEmail'], __('Nowa odpowiedź w dyskusji: „{tytul}"', ['tytul' => $event->title]), [
                'recipientName' => $parentComment['authorName'],
                'replierName'   => $user->name ?: $user->email,
                'eventTitle'    => $event->title,
                'body'          => $body,
                'link'          => View::absoluteUrl('/events/' . $slug) . '#dyskusja',
            ]));
        } elseif ($parentComment === null && $user->id !== $event->organizerId) {
            // Nowe (nie-odpowiedź) pytanie — organizator inaczej dowiadywał się
            // tylko przypadkiem, zaglądając na stronę wydarzenia.
            $organizerUser = User::find($event->organizerId);
            if ($organizerUser) {
                \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizerUser))), static fn() => Mailer::sendTemplate('discussion-question', Organizer::notificationEmail($organizerUser), __('Nowe pytanie pod „{tytul}”', ['tytul' => $event->title]), [
                    'recipientName' => $organizerUser->displayName(),
                    'askerName'     => $user->name ?: $user->email,
                    'eventTitle'    => $event->title,
                    'body'          => $body,
                    'link'          => View::absoluteUrl('/events/' . $slug) . '#dyskusja',
                ]));
            }
        }

        header('Location: ' . View::url('/events/' . $slug));
        exit;
    }

    // Moderacja dyskusji (2026-08-09, zgłoszenie usera: "nie mamy możliwości
    // moderowania tej dyskusji, jeśli pojawią się niecenzuralne treści to mamy
    // kłopot"). Uprawnienie: EventPermission::canEdit — organizator,
    // współpracownik albo admin, czyli ten sam krąg co edycja wydarzenia.
    // Kasowanie pytania top-level usuwa też odpowiedzi pod nim (ON DELETE
    // CASCADE na parent_comment_id, patrz schema.sql).
    public static function delete(string $slug, string $commentId): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do moderowania dyskusji tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug) . '#pytania');
            exit;
        }

        EventComment::delete((int) $commentId, $event->id);

        // Powrót tam, skąd przyszło kasowanie. CELOWO token ('panel'), a NIE
        // adres z formularza — przyjmowanie URL-a z POST-a byłoby otwartym
        // przekierowaniem (ktoś podstawia obcą domenę i używa naszej strony
        // jako trampoliny). Whitelist jednej wartości zamyka temat.
        $back = ($_POST['back'] ?? '') === 'panel'
            ? View::url('/admin/dyskusje')
            : View::url('/events/' . $slug) . '#pytania';
        header('Location: ' . $back);
        exit;
    }

    // Dodanie pary Q&A przez organizatora — pytanie pokazuje się anonimowo,
    // odpowiedź jako odpowiedź organizatora (patrz EventComment::createFaqPair()
    // i migration_034). Służy do spisania pytań, które padają w kółko poza
    // platformą (telefon, Messenger), żeby kolejni uczestnicy znaleźli je same.
    public static function storeFaq(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do dodawania pytań i odpowiedzi w tym wydarzeniu.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug) . '#pytania');
            exit;
        }

        $question = trim($_POST['faq_question'] ?? '');
        $answer   = trim($_POST['faq_answer'] ?? '');
        if ($question !== '' && $answer !== '') {
            EventComment::createFaqPair($event->id, Auth::user()->id, $question, $answer);
        }

        header('Location: ' . View::url('/events/' . $slug) . '#pytania');
        exit;
    }
}
