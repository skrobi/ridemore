<?php
// core/Controllers/Admin/DashboardController.php
namespace Controllers\Admin;

use Controllers\Support;
use Core\Auth;
use Models\Event;
use Models\EventComment;
use Models\EventRsvp;
use Utils\View;

class DashboardController
{
    public static function index(): void
    {
        $user = Auth::user();
        View::render('web', 'dashboard', [
            'title'       => __('Panel — ridemore.bike'),
            'user'        => $user,
            'events'      => Event::forDashboard($user->isAdmin ? null : $user->id),
            // Licznik przy linku "Dyskusje" — ile pytań czeka na odpowiedź
            // (patrz Controllers\Admin\DiscussionController).
            'unansweredCount' => EventComment::unansweredCountFor($user->isAdmin ? null : $user->id),
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Panel')]],
        ]);
    }

    // Zbiorcza kolejka wpłat/zwrotów wymagających działania — ze WSZYSTKICH
    // wydarzeń organizatora naraz (patrz EventRsvp::pendingForOrganizer(), tam
    // też uwzględnieni współpracownicy z organizer_collaborators). Same akcje
    // (potwierdź płatność/zwrot) POSTują do już istniejących tras RsvpController
    // — ta strona jest tylko widokiem, nie duplikuje logiki mutacji.
    public static function payments(): void
    {
        $user = Auth::user();
        View::render('web', 'payments', [
            'title'       => __('Płatności — ridemore.bike'),
            'rows'        => EventRsvp::pendingForOrganizer($user->isAdmin ? null : $user->id),
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Płatności')]],
        ]);
    }
}
