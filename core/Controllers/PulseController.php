<?php
// core/Controllers/PulseController.php
// PULS — /puls (Etap 5). „Co się dzieje w moim rowerowym świecie".
namespace Controllers;

use Core\Auth;
use Models\Pulse;
use Models\RiderConnection;
use Utils\View;

class PulseController
{
    public static function index(): void
    {
        $viewer = Auth::user();

        // KURSOR STRONICOWANIA — znacznik czasu ostatniego widocznego wpisu,
        // nie numer strony. Wpisy powstają w locie z pięciu źródeł, więc numer
        // strony i tak nie miałby stabilnego znaczenia: między jednym a drugim
        // wejściem dochodzą nowe zdarzenia i „strona 2" pokazywałaby co innego.
        //
        // Format sprawdzany, zanim trafi do zapytania — parametr z adresu jedzie
        // wprost do warunku SQL (przez placeholder, ale i tak nie ma powodu
        // przepuszczać czegokolwiek poza datą).
        $before = $_GET['przed'] ?? null;
        if (!is_string($before) || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $before)) {
            $before = null;
        }

        $perPage = 30;
        $items = Pulse::feed($perPage, $before);

        View::render('web', 'pulse', [
            'title'       => __('Puls — co się dzieje na trasach | ridemore.bike'),
            'description' => 'Kto właśnie przejechał trasę, na jakie wyjazdy zbierają się grupy '
                           . __('i kto szuka towarzystwa — bieżąca aktywność społeczności ridemore.bike.'),
            'items'       => $items,
            // Kursor następnej strony = czas NAJSTARSZEGO wpisu na tej. Pełna
            // strona nie dowodzi, że coś jeszcze jest, ale niepełna dowodzi, że
            // nie ma — i tylko tego potrzebujemy, żeby nie pokazać przycisku
            // prowadzącego do pustki.
            'nextCursor'  => count($items) === $perPage ? (string) end($items)['at'] : null,
            'isPaged'     => $before !== null,
            // „Co teraz robimy?" — blok nad resztą feedu, tylko dla zalogowanych,
            // bo gość nie ma peletonu. To jest najmocniejszy powód powrotu w całym
            // produkcie: nie „mamy nowe wydarzenia", tylko „Twoi ludzie jadą".
            'pelotonPlans' => $viewer ? RiderConnection::upcomingForPeloton($viewer->id) : [],
            'isLoggedIn'  => $viewer !== null,
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Puls')]],
        ]);
    }
}
