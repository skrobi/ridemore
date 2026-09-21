<?php
// core/Controllers/TrackController.php
// Wgrywanie ŚLADU Z ODBYTEGO WYJAZDU (Etap 8, migr. 042) — jedyne źródło,
// z którego Discovery liczy odkryte pola.
//
// Jedna trasa POST obsługuje dwa przypadki, bo różnią się wyłącznie tym, kogo
// ślad dotyczy:
//   - ORGANIZATOR (uprawnienia do edycji wydarzenia) wgrywa ślad Z IMPREZY —
//     liczy się każdemu, kto potwierdził obecność na tym turnusie,
//   - UCZESTNIK z potwierdzoną obecnością wgrywa WŁASNY ślad — liczy się
//     tylko jemu i ma pierwszeństwo przed śladem zbiorowym.
// Kto jest kim, rozstrzyga serwer, nie formularz: pole z rodzajem śladu w
// żądaniu byłoby zaproszeniem do wgrania „śladu z imprezy" przez przypadkową
// osobę.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\EditionTrack;
use Models\Event;
use Models\EventAttendance;
use Models\EventPermission;
use Utils\Upload;
use Utils\View;

class TrackController
{
    public static function upload(string $slug): void
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) {
            return;
        }

        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? null);
        $powrot = $_POST['powrot'] ?? null;
        $fail = fn(string $kod) => self::backUrl($slug, $edition->id, $powrot, '&blad=' . $kod);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . self::backUrl($slug, $edition->id, $powrot));
            exit;
        }

        $user = Auth::user();
        $canManage = EventPermission::canEdit($user, $event->organizerId);

        // Kto może wgrać WŁASNY ślad: ktoś z POTWIERDZONYM ZAPISEM na ten
        // turnus (forEditionAndUser zwraca null dla całej reszty).
        //
        // ZMIENIONE 2026-08-13: wcześniej wymagana była już potwierdzona
        // OBECNOŚĆ (`attended === true`), co stało w sprzeczności z tym, czego
        // ta zmiana dotyczy — ślad ma sam potwierdzać obecność, a stary warunek
        // kazał ją najpierw zadeklarować ręcznie. Zapis dalej jest wymagany:
        // ślad do cudzego wyjazdu, na którym się nie było, nie ma sensu.
        // Odpowiedź „nie dojechałem" też nie blokuje wgrania — ktoś mógł
        // przejechać kawałek i chcieć mieć to na mapie; automat obecności po
        // prostu wtedy nie ruszy (patrz niżej).
        $attendance = EventAttendance::forEditionAndUser($edition->id, $user->id);
        $isAttendee = $attendance !== null;

        if (!$canManage && !$isAttendee) {
            header('Location: ' . $fail('brak-uprawnien'));
            exit;
        }

        if (empty($_FILES['gpx']['name'])) {
            header('Location: ' . $fail('brak-pliku'));
            exit;
        }

        // Poza try/catch: gałąź błędu przekierowuje osobno, a zmienna musi
        // istnieć w chwili składania adresu powrotnego.
        $autoAttendance = null;

        try {
            // Ten sam tor co GPX wydarzenia: katalog tymczasowy (tam dzieje się
            // walidacja rozszerzenia i rozmiaru), zaraz potem promocja.
            $token = Upload::saveGpxTemp($_FILES['gpx']);
            $gpxUrl = Upload::promoteGpxTemp($token);
            if ($gpxUrl === null) {
                header('Location: ' . $fail('zly-plik'));
                exit;
            }

            $distance = EditionTrack::distanceFromFile(CORE_PATH . '/..' . $gpxUrl);
            $label = trim((string) ($_POST['label'] ?? ''));

            // Organizator wgrywa ślad zbiorowy, uczestnik własny. Gdy
            // organizator sam jechał i chce wgrać SWÓJ ślad, robi to polem
            // 'wlasny' — inaczej każdy jego upload dotyczyłby wszystkich.
            $asOwn = !$canManage || ($_POST['zakres'] ?? '') === 'wlasny';

            EditionTrack::attach(
                $edition->id,
                $asOwn ? $user->id : null,
                $gpxUrl,
                $label !== '' ? mb_substr($label, 0, 150) : null,
                $distance,
                $user->id
            );

            // WŁASNY ŚLAD SAM POTWIERDZA OBECNOŚĆ (2026-08-13, prośba usera:
            // „jeśli wgrałem gpx, to powinno zaznaczyć, że byłem, nie muszę
            // osobno deklarować"). Wgranie pliku z wyjazdu JEST deklaracją
            // obecności — mocniejszą niż kliknięcie, bo popartą dowodem.
            //
            // Idzie przez EventAttendance::declare(), NIE zapisem wprost:
            // tamta metoda pilnuje peletonu i przeliczenia odkryć, a ominięcie
            // jej zostawiłoby te dwie rzeczy nieaktualne.
            //
            // WERYFIKACJA: gdy turnus ma trasę referencyjną, sprawdzamy pokrycie
            // wgranego śladu z zapowiedzią. Poniżej progu NIE odrzucamy pliku —
            // ktoś mógł jechać wariantem, skrótem albo zgubić sygnał — tylko nie
            // potwierdzamy obecności automatycznie i mówimy wprost dlaczego.
            // Bez trasy referencyjnej nie ma czego porównywać i ślad wystarcza.
            if ($asOwn) {
                $coverage = EditionTrack::coverageAgainstPlanned($edition->id, CORE_PATH . '/..' . $gpxUrl);
                $matchesRoute = $coverage === null
                    || $coverage['pct'] >= EditionTrack::AUTO_ATTENDANCE_MIN_PCT;

                $state = EventAttendance::forEditionAndUser($edition->id, $user->id);
                // Tylko gdy nikt jeszcze nie odpowiedział. Świadome „nie
                // dojechałem" jest odpowiedzią człowieka i plik jej nie kasuje —
                // ktoś mógł wgrać ślad dojazdu i zawrócić z połowy drogi.
                if ($state !== null && $state['attended'] === null && $matchesRoute) {
                    EventAttendance::declare($state['rsvpId'], true, $user->id, false);
                    $autoAttendance = 'potwierdzona';
                } elseif ($state !== null && $state['attended'] === null) {
                    $autoAttendance = 'niezgodna';
                }
            }
        } catch (\Throwable $e) {
            header('Location: ' . $fail('zly-plik'));
            exit;
        }

        header('Location: ' . self::backUrl(
            $slug,
            $edition->id,
            $powrot,
            '&info=' . ($autoAttendance === 'potwierdzona'
                ? 'slad-i-obecnosc'
                : ($autoAttendance === 'niezgodna' ? 'slad-niezgodny' : 'slad-dodany'))
        ));
        exit;
    }

    public static function delete(string $slug, string $trackId): void
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) {
            return;
        }

        $track = EditionTrack::find((int) $trackId);
        $user = Auth::user();
        $canManage = EventPermission::canEdit($user, $event->organizerId);

        // Anty-IDOR: ślad musi należeć do turnusu TEGO wydarzenia. Bez tego
        // znajomość samego id pozwalałaby kasować cudze ślady przez adres
        // wydarzenia, do którego ma się uprawnienia.
        $belongs = $track !== null && Support::editionBelongsToEvent($event, (int) $track['edition_id']);
        $isOwner = $track !== null && $track['user_id'] !== null && (int) $track['user_id'] === $user->id;

        if ($belongs && ($canManage || $isOwner)) {
            EditionTrack::remove((int) $track['id']);
        }

        header('Location: ' . self::backUrl(
            $slug,
            (int) ($track['edition_id'] ?? 0),
            $_POST['powrot'] ?? null
        ));
        exit;
    }

    /**
     * Dokąd wrócić po akcji. Kontrolka wgrywania stoi w DWÓCH miejscach
     * (strona wydarzenia i kronika), a wyrzucenie człowieka na inną stronę niż
     * ta, na której kliknął, jest zawsze błędem.
     *
     * Whitelista jednej wartości, nie dowolny adres z żądania — pole „wróć
     * tutaj" przyjmujące URL wprost to gotowy open redirect.
     */
    private static function backUrl(string $slug, int $editionId, ?string $powrot, string $extra = ''): string
    {
        // Tabela „Moje przejazdy" nie jest stroną wyjazdu — nie ma tam ani
        // parametru `termin`, ani kotwicy `#slad`, więc składa się osobno.
        if ($powrot === 'moje-przejazdy') {
            return View::url('/admin/moje-przejazdy')
                . ($extra !== '' ? '?' . ltrim($extra, '&') : '');
        }

        $base = $powrot === 'kronika'
            ? View::url('/kronika/' . $slug)
            : View::url('/events/' . $slug);

        // Kotwica ZAWSZE na końcu, za wszystkimi parametrami. Doklejanie
        // '&blad=...' do gotowego adresu z '#slad' wpychało komunikat do
        // środka fragmentu, gdzie przeglądarka go po prostu ignoruje — błąd
        // wyglądał wtedy jak cicha odmowa bez powodu.
        return $base . '?termin=' . $editionId . $extra . '#slad';
    }
}
