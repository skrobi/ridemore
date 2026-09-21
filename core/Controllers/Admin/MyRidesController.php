<?php
// core/Controllers/Admin/MyRidesController.php
// „MOJE PRZEJAZDY" — /admin/moje-przejazdy.
//
// Tabelaryczny widok własnych wyjazdów dla ROWERZYSTY, nie dla organizatora.
// Do tej pory panel miał wyłącznie ekrany dla prowadzącego wydarzenia; uczestnik
// widział swoją historię tylko na publicznym profilu, czyli w formie opowieści
// dla innych, a nie zestawienia dla siebie.
//
// Ekran odpowiada na trzy pytania naraz i to jest cały powód, dla którego jest
// tabelą, a nie listą kart: „byłem?", „jest ślad?", „ile z tego wyszło" to trzy
// krótkie, porównywalne wartości — dokładnie ten rodzaj danych, przy którym
// kolumny pomagają (ta sama decyzja co przy event-participants.php i
// payments.php, patrz nota nad .dash-table w style.css).
//
// ŚWIADOMIE BEZ FILTRU `attended = 1`: najcenniejsze wiersze to te, w których
// czegoś BRAKUJE — nieodpowiedziana obecność i wyjazd bez śladu. To jest
// jedyne miejsce w serwisie, w którym widać je wszystkie naraz; na stronie
// wydarzenia trzeba by wchodzić w każdy wyjazd osobno, żeby to sprawdzić.
namespace Controllers\Admin;

use Controllers\DeviceController;
use Controllers\GarminController;
use Controllers\Support;
use Core\Auth;
use Models\EventAttendance;
use Models\DeviceConnection;
use Models\RiderActivity;
use Utils\DeviceApi;
use Utils\GarminBridge;
use Utils\View;

class MyRidesController
{
    public static function index(): void
    {
        $user = Auth::user();
        $rides = EventAttendance::myRidesForUser($user->id);

        // Trzy liczniki nad tabelą. Nie są filtrem (jak triage w panelu
        // organizatora) — mają tylko powiedzieć, ile roboty zostało, zanim
        // ktoś zacznie czytać wiersz po wierszu.
        $noAnswer = 0;
        $noTrack = 0;
        foreach ($rides as $r) {
            if ($r['attended'] === null) {
                $noAnswer++;
            }
            // Brak śladu liczymy TYLKO tam, gdzie obecność jest potwierdzona:
            // przy „nie dojechałem" nie ma czego wgrywać, a przy braku
            // odpowiedzi problemem jest odpowiedź, nie plik.
            if ((int) $r['attended'] === 1
                && (int) $r['own_tracks'] === 0 && (int) $r['event_tracks'] === 0) {
                $noTrack++;
            }
        }

        // GARMIN CONNECT (migr. 068) — druga droga do śladu bez wydarzenia,
        // więc mieszka w tej samej sekcji co wgrywanie pliku. `available()`
        // decyduje, czy sekcja w ogóle się pokazuje: poza dev nie ma mostu do
        // Pythona, a przycisk, który nie ma jak zadziałać, jest gorszy niż
        // jego brak.
        $garminAvailable = GarminBridge::available();

        // ŹRÓDŁA ŚLADU JAKO ZAKŁADKI (2026-08-23, prośba usera). Kolejność jest
        // decyzją, nie przypadkiem: PLIK stoi pierwszy, bo działa zawsze,
        // wszędzie i dla każdego licznika — reszta to wygoda dla tych, którzy
        // mają konto u konkretnego producenta. Zakładka idzie adresem
        // (`?zrodlo=`), nie JS-em, żeby powrót z autoryzacji OAuth mógł
        // wskazać właściwą.
        $zrodla = array_merge(['upload'], array_keys(DeviceApi::all()));
        $zrodlo = in_array($_GET['zrodlo'] ?? '', $zrodla, true) ? (string) $_GET['zrodlo'] : 'upload';

        $deviceConns = [];
        $devicePending = [];
        foreach (DeviceApi::all() as $key => $meta) {
            $deviceConns[$key] = DeviceConnection::find($user->id, $key);
            // Lista „co nowego" żyje w sesji per dostawca — Garmin ma własną
            // (starszy kontroler), reszta wspólną.
            $devicePending[$key] = $key === 'garmin'
                ? ($garminAvailable ? GarminController::pendingList() : [])
                : DeviceController::pendingList($key);
        }

        // PRZEJAZDY SOLO — druga zakładka (2026-08-23). Ślad bez wydarzenia
        // nie miał dotąd ŻADNEGO ekranu: wpadał do statystyk mapy odkryć i
        // znikał, więc po imporcie z Garmina nie dało się nawet sprawdzić, co
        // właściwie weszło. Tabela jest tu warunkiem tego, żeby dało się z tymi
        // przejazdami cokolwiek zrobić — łącznie z powiązaniem ich z wyjazdem.
        $soloRides = RiderActivity::soloForUser($user->id);
        $tab = ($_GET['tab'] ?? '') === 'solo' ? 'solo' : 'wyjazdy';

        // SZUKANIE I STRONICOWANIE (2026-08-26, zgłoszenie usera: „jedyna akcja
        // jaka może zostać podjęta to przypisanie trasy z wyjazdem, powinienem
        // mieć więcej możliwości"). OSOBNE zapytanie od `$soloRides` wyżej —
        // ten drugi zostaje PEŁNĄ listą, bo modal „Powiąż z wyjazdem" (zakładka
        // Wyjazdy) musi widzieć każdy przejazd solo, nie tylko bieżącą stronę
        // wyników. Liczone TYLKO na zakładce solo — na zakładce wyjazdów byłoby
        // to zapytanie, którego wynik nikt by nie zobaczył.
        $soloSearch = $tab === 'solo'
            ? RiderActivity::searchSoloForUser($user->id, [
                'szukaj' => $_GET['q'] ?? '',
                'strona' => (int) ($_GET['strona'] ?? 1),
            ])
            : null;

        View::render('web', 'my-rides', [
            'title'       => __('Moje przejazdy — ridemore.bike'),
            'noindex'     => true,
            'rides'       => $rides,
            'soloRides'   => $soloRides,
            'soloSearch'  => $soloSearch,
            // LEAFLET + leaflet-gpx TYLKO gdy jest co narysować (ta sama zasada
            // co na profilu rowerzysty i w kronice) — podgląd przejazdu solo
            // (2026-08-26) rysuje ślad wektorem, `ridemoreAddGpxTrack`, więc bez
            // tego <head> mapa w modalu zostawałaby pustym, martwym boksem: obie
            // funkcje (`ridemoreCreateMap`, `L.GPX`) po prostu by nie istniały.
            'extraHead'   => ($soloSearch && $soloSearch['items']) ? Support::gpxMapHead() : '',
            'tab'         => $tab,
            'noAnswer'    => $noAnswer,
            'noTrack'     => $noTrack,
            'garminOn'      => $garminAvailable,
            'zrodlo'        => $zrodlo,
            'deviceMeta'    => DeviceApi::all(),
            'deviceConns'   => $deviceConns,
            'devicePending' => $devicePending,
            'garminNext'    => $garminAvailable ? GarminController::pendingNext() : 0,
            'viewerSlug'  => $user->publicSlug,
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Moje przejazdy')]],
        ]);
    }
}
