<?php
// core/Controllers/Admin/DiscussionController.php
// Zbiorcza moderacja dyskusji (2026-08-09) — jedno miejsce na wszystkie
// pytania i odpowiedzi z wydarzeń, którymi user zarządza. Powstało, bo
// moderacja istniała WYŁĄCZNIE na stronie pojedynczego wydarzenia: żeby
// znaleźć niecenzuralny wpis, trzeba było obejść każde wydarzenie osobno
// (nierealne przy kilkudziesięciu, tym bardziej przy tysiącach u admina).
//
// CELOWO nie tylko dla admina (brak $adminGet w admin/routes.php) —
// organizator moderuje własne wydarzenia i to dla niego jest to przede
// wszystkim lista zadań "na co jeszcze nie odpowiedziałem", a dopiero potem
// narzędzie cenzury.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Auth;
use Models\EventComment;
use Utils\View;

class DiscussionController
{
    public static function index(): void
    {
        $user = Auth::user();
        // Admin widzi wszystko (null), organizator tylko swoje — ten sam
        // wzorzec co Event::forDashboard()/EventRsvp::pendingForOrganizer().
        $scopeUserId = $user->isAdmin ? null : $user->id;

        $q = trim((string) ($_GET['q'] ?? ''));
        $onlyUnanswered = ($_GET['bez_odpowiedzi'] ?? '') === '1';
        // Zawężenie do jednego wydarzenia — wejście z wiersza panelu. Sam slug
        // NIE nadaje uprawnień: EventComment::forModeration() i tak filtruje po
        // $scopeUserId, więc podstawienie cudzego slugu da pustą listę.
        $eventSlug = trim((string) ($_GET['event'] ?? ''));

        $comments = EventComment::forModeration($scopeUserId, $q, $onlyUnanswered, 200, $eventSlug);

        View::render('web', 'discussions', [
            'title'          => __('Dyskusje — ridemore.bike'),
            'user'           => $user,
            'comments'       => $comments,
            'q'              => $q,
            'onlyUnanswered' => $onlyUnanswered,
            'eventSlug'      => $eventSlug,
            // Tytuł filtrowanego wydarzenia bierzemy z pierwszego wiersza —
            // bez dodatkowego zapytania. Pusty, gdy wydarzenie nie ma jeszcze
            // żadnej dyskusji (wtedy widok pokazuje sam slug).
            'eventTitle'     => $comments[0]['eventTitle'] ?? '',
            'unansweredCount' => EventComment::unansweredCountFor($scopeUserId),
            'breadcrumbs'    => [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Dyskusje')]],
        ]);
    }
}
