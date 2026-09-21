<?php
// core/Controllers/PageController.php
namespace Controllers;

use Models\EventAttendance;
use Utils\View;

class PageController
{
    public static function howItWorks(): void
    {
        View::render('web', 'how-it-works', [
            'title'       => __('Jak to działa — ridemore.bike'),
            'description' => __('Jak działa ridemore.bike: wyjazdy rowerowe, zapisy i płatności bez prowizji, mapa odkryć, znane trasy, skarby i kroniki wyjazdów.'),
        ]);
    }

    // Cztery minimalne strony pod linki w stopce (patrz footer.php) — treść
    // do uzupełnienia w kolejnej sesji, dodane teraz żeby linki nie prowadziły
    // donikąd (patrz ustalenie przy przebudowie strony głównej, 2026-07-31).
    public static function terms(): void
    {
        View::render('web', 'terms', ['title' => __('Regulamin — ridemore.bike'), 'noindex' => true]);
    }

    public static function privacy(): void
    {
        View::render('web', 'privacy', ['title' => __('Prywatność — ridemore.bike'), 'noindex' => true]);
    }

    // Indeks kronik (Etap 4) — do tej pory ta strona była placeholderem
    // („Strona w przygotowaniu"). Nie ma tu żadnej nowej tabeli: kronika
    // istnieje dla każdego turnusu z potwierdzoną obecnością, więc lista
    // takich turnusów JEST listą kronik.
    public static function recaps(): void
    {
        View::render('web', 'recaps', [
            'title'       => __('Kroniki wyjazdów — ridemore.bike'),
            'description' => __('Relacje z przejechanych wyjazdów rowerowych — kto był, którędy i jak było.'),
            'chronicles'  => EventAttendance::chronicleIndex(),
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Kroniki')]],
        ]);
    }

    public static function forOrganizers(): void
    {
        View::render('web', 'for-organizers', [
            'title'       => __('Dla organizatorów — ridemore.bike'),
            'description' => __('Wystaw wyjazd rowerowy na ridemore.bike bez prowizji — zapisy i płatności zostają po Twojej stronie.'),
        ]);
    }
}
