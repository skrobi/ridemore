<?php
// core/Controllers/ChronicleController.php
// KRONIKA WYJAZDU — /kronika/{slug}[?termin=ID] (Etap 4).
//
// Kronika NIE jest osobnym bytem w bazie i nie ma własnej tabeli. Jest
// WIDOKIEM na dane, które serwis i tak już ma: wydarzenie + turnus (tytuł,
// data, region, trasa, GPX), event_attendance (kto faktycznie był),
// event_recaps (wpisy dziennika), event_photos (zdjęcia przy wpisach),
// rider_connections (ile znajomości powstało tego dnia).
//
// Dzięki temu „kronika rodzi się wypełniona" dzieje się samo z siebie — nie
// ma momentu tworzenia, nie ma crona, który mógłby nie zadziałać, i nie ma
// stanu „pusta kronika". Strona istnieje dla każdego przejechanego turnusu
// od chwili, gdy pierwsza osoba potwierdzi obecność.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\Discovery;
use Models\EditionTrack;
use Models\Event;
use Models\EventAttendance;
use Models\EventPermission;
use Models\EventRecap;
use Models\RiderConnection;
use Models\User;
use Resources\ChronicleResource;
use Utils\View;

class ChronicleController
{
    public static function show(string $slug): void
    {
        $event = Event::findBySlug($slug);
        $edition = $event ? Support::resolveEdition($event, $_GET['termin'] ?? null) : null;

        // Kronika istnieje tylko dla wyjazdu, który SIĘ ODBYŁ i na którym
        // ktokolwiek potwierdził obecność. Bez tego nie mamy składu, a skład
        // jest jedyną rzeczą, której kronika nie może nie mieć.
        $attended = ($event && $edition && $event->statusCode === 'completed')
            ? EventAttendance::attendedForEdition($edition->id)
            : [];

        if (!$attended) {
            http_response_code(404);
            View::render('web', 'chronicle', [
                'title'       => __('Nie znaleziono kroniki — ridemore.bike'),
                'noindex'     => true,
                'chronicle'   => null,
                'breadcrumbs' => [Support::homeCrumb()],
            ]);
            return;
        }

        $viewer = Auth::user();
        $chronicle = ChronicleResource::build(
            $event,
            $edition,
            $attended,
            EventRecap::forEdition($edition->id),
            RiderConnection::newPairsFromEdition($edition->id),
            $viewer?->id
        );

        // Czy widz może dorzucić swoje — tylko ktoś, kto sam był na tym
        // turnusie. Kronika jest zapisem TEGO wyjazdu, nie forum dyskusyjnym,
        // więc nie dopisują do niej osoby, których tam nie było.
        $viewerAttended = $viewer !== null
            && in_array($viewer->id, array_map('intval', array_column($attended, 'user_id')), true);

        // Ślad z odbytego wyjazdu (Etap 8, migr. 042) — ta sama kontrolka co na
        // stronie wydarzenia. Kronika jest miejscem, w którym ludzie lądują PO
        // powrocie (link z maila, z Pulsu, z listy relacji), więc jeśli
        // gdziekolwiek ma się rzucać w oczy „wgraj ślad", to właśnie tu.
        $canManageEvent = $viewer !== null && EventPermission::canEdit($viewer, $event->organizerId);
        $canUploadTrack = $canManageEvent || $viewerAttended;

        // Pola Discovery tego przejazdu — na mapę, z podziałem na odkryte
        // WŁAŚNIE TERAZ i te, które ktoś już wcześniej miał. Dla uczestnika
        // liczone z jego własnego przejazdu, dla reszty zbiorczo.
        $rideCells = Discovery::rideCellsForEdition(
            $edition->id,
            $viewerAttended && $viewer ? $viewer->id : null
        );

        View::render('web', 'chronicle', [
            'title'       => __('Kronika: {tytul} — ridemore.bike', ['tytul' => $event->title]),
            'description' => $chronicle['metaDescription'],
            'chronicle'   => $chronicle,
            // Mapa tylko wtedy, gdy wyjazd ma ślad — bez GPX-a nie ładujemy
            // Leafletu ani leaflet-gpx.
            // discovery-map.js dochodzi tylko wtedy, gdy jest co narysować —
            // potrzebne wyłącznie dla helpera rysującego sześciokąt.
            'extraHead'   => $chronicle['tracks']
                ? Support::gpxMapHead() . ($rideCells['cells']
                    ? "\n" . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>'
                    : '')
                : '',
            'rideCells'   => $rideCells,
            // Co ten wyjazd wniósł w Discovery — dla widza, który był na nim
            // sam (własne pola i punkty), oraz dla każdego innego (ile pól
            // odkrył cały skład).
            'discoveryRide'  => $viewer ? Discovery::rideForUserOnEdition($viewer->id, $edition->id) : null,
            'discoveryCells' => Discovery::newCellsForEdition($edition->id),
            'viewerAttended' => $viewerAttended,
            'viewerRecap' => ($viewerAttended && $viewer)
                ? EventRecap::findByEditionAndAuthor($edition->id, $viewer->id)
                : null,
            'breadcrumbs' => [
                Support::homeCrumb(),
                ['label' => __('Relacje'), 'url' => View::url('/relacje')],
                ['label' => $event->title],
            ],
            'inviteSent'  => isset($_GET['zaproszono']),
            'inviteError' => $_GET['blad'] ?? null,
            'canUploadTrack' => $canUploadTrack,
            'canManageEvent' => $canManageEvent,
            'editionTracks'  => $canUploadTrack ? EditionTrack::forEdition($edition->id) : [],
            'viewerId'       => $viewer?->id,
        ]);
    }

    // „Byłeś tam? Oznacz kogoś" — zaproszenie do kroniki (pętla wzrostu).
    //
    // ŚWIADOMIE NIE dopisujemy nikogo do składu. Zaproszenie to WYŁĄCZNIE mail
    // z linkiem do kroniki; obecność zaznacza sobie sam zaproszony, po założeniu
    // konta. Gdyby uczestnik mógł oznaczyć kogoś jako obecnego, cała wartość
    // „Byłem" — czyli to, że liczby oznaczają coś prawdziwego — zniknęłaby
    // w jedno kliknięcie.
    //
    // Zaprasza tylko ktoś, kto SAM był na tym turnusie: to jedyne, co czyni
    // zaproszenie uczciwym („byłeś tam ze mną"), i jednocześnie zamyka drogę
    // do rozsyłania maili przez przypadkowe konta.
    public static function invite(string $slug): void
    {
        $user = Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;

        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? null);
        $back = View::url('/kronika/' . $slug) . '?termin=' . $edition->id;

        $fail = function (string $code) use ($back) {
            header('Location: ' . $back . '&blad=' . $code . '#oznacz');
            exit;
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $fail('sesja');
        }

        $attendedIds = array_map('intval', array_column(
            EventAttendance::attendedForEdition($edition->id), 'user_id'
        ));
        if (!in_array($user->id, $attendedIds, true)) {
            $fail('nieuprawniony');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fail('email');
        }
        if (mb_strtolower($email) === mb_strtolower($user->email)) {
            $fail('samemu');
        }

        // Osoba już w składzie nie potrzebuje zaproszenia — i nie chcemy dawać
        // sposobu na sprawdzanie „czy X ma konto", więc komunikat jest ten sam
        // co przy sukcesie.
        $invitee = User::findByEmail($email);
        if ($invitee && in_array($invitee->id, $attendedIds, true)) {
            // $back ma już '?termin=...', więc kolejny parametr doklejamy '&'.
            header('Location: ' . $back . '&zaproszono=1#oznacz');
            exit;
        }

        // Limit: 5 zaproszeń na osobę na turnus w ciągu doby. Bez tego formularz
        // jest gotowym narzędziem do rozsyłania maili na dowolne adresy.
        if (!self::withinInviteLimit($user->id, $edition->id)) {
            $fail('limit');
        }

        \Core\Lang::with(\Core\Lang::forEmail((string) ($email)), static fn() => Mailer::sendTemplate('chronicle-invite', $email, __('{kto} oznaczył Cię na wyjeździe „{tytul}”', ['kto' => $user->displayName(), 'tytul' => $event->title]), [
            'inviterName'   => $user->displayName(),
            'eventTitle'    => $event->title,
            'chronicleLink' => View::absoluteUrl('/kronika/' . $slug) . '?termin=' . $edition->id,
            'registerLink'  => View::absoluteUrl('/rejestracja'),
        ]));

        header('Location: ' . $back . '&zaproszono=1#oznacz');
        exit;
    }

    // Prosty licznik w sesji zamiast tabeli — zaproszenia to akcja rzadka
    // i incydentalna, a osobna tabela pod rate-limit byłaby tu grubo
    // przesadzona. Sesja wystarcza, bo limit ma powstrzymać nadużycie z tej
    // przeglądarki, nie zbudować audytu.
    private static function withinInviteLimit(int $userId, int $editionId): bool
    {
        $key = 'chronicle_invites';
        $bucket = $userId . ':' . $editionId;
        $now = time();

        $_SESSION[$key] = array_filter(
            $_SESSION[$key] ?? [],
            static fn(array $row): bool => $row['at'] > $now - 86400
        );
        $used = count(array_filter(
            $_SESSION[$key],
            static fn(array $row): bool => $row['bucket'] === $bucket
        ));
        if ($used >= 5) {
            return false;
        }

        $_SESSION[$key][] = ['bucket' => $bucket, 'at' => $now];
        return true;
    }
}
