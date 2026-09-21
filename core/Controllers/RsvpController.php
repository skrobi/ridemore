<?php
// core/Controllers/RsvpController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\Event;
use Models\EventAttendance;
use Models\EventRsvp;
use Models\Organizer;
use Models\User;
use Models\UserBillingProfile;
use Utils\Format;
use Utils\View;

class RsvpController
{
    public static function reserveForm(string $slug): void
    {
        [$event, $edition] = Support::requirePayableInternalEvent($slug, $_GET['termin'] ?? null);
        $user = Auth::user();
        $billingProfile = UserBillingProfile::findByUserId($user->id);

        View::render('web', 'event-reserve', [
            'title'          => __('Rezerwacja: {tytul} — ridemore.bike', ['tytul' => $event->title]),
            'event'          => $event,
            'edition'        => $edition,
            'billingProfile' => $billingProfile,
            'billingComplete' => UserBillingProfile::isComplete($user->id),
            'deadlineDays'   => $event->pricing->paymentDeadlineDaysBefore ?? 7,
            'breadcrumbs'    => [Support::homeCrumb(), ['label' => $event->title, 'url' => View::url('/events/' . $slug)], ['label' => __('Rezerwacja')]],
        ]);
    }

    public static function reserve(string $slug): void
    {
        [$event, $edition] = Support::requirePayableInternalEvent($slug, $_POST['edition_id'] ?? null);
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), ['label' => $event->title, 'url' => View::url('/events/' . $slug)], ['label' => __('Rezerwacja')]];
        $deadlineDays = $event->pricing->paymentDeadlineDaysBefore ?? 7;

        $fail = function (string $error) use ($event, $edition, $breadcrumbs, $deadlineDays, $user) {
            View::render('web', 'event-reserve', [
                'title'           => __('Rezerwacja: {tytul} — ridemore.bike', ['tytul' => $event->title]),
                'event'           => $event,
                'edition'         => $edition,
                'billingProfile'  => UserBillingProfile::findByUserId($user->id),
                'billingComplete' => UserBillingProfile::isComplete($user->id),
                'deadlineDays'    => $deadlineDays,
                'billingError'    => $error,
                'breadcrumbs'     => $breadcrumbs,
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $fail(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        if (!UserBillingProfile::isComplete($user->id)) {
            $legalName = trim($_POST['legal_name'] ?? '');
            $address   = trim($_POST['address'] ?? '');
            if ($legalName === '' || $address === '') {
                $fail(__('Podaj imię i nazwisko (lub nazwę firmy) oraz adres.'));
                return;
            }
            UserBillingProfile::save($user->id, [
                'legalName' => $legalName,
                'address'   => $address,
                'taxId'     => $_POST['tax_id'] ?? '',
            ]);
        }

        EventRsvp::reserve($edition->id, $user->id);

        // joined_at dla świeżo utworzonej rezerwacji to praktycznie "teraz" —
        // liczymy termin od bieżącej daty zamiast dociągać zapis, żeby nie robić
        // zbędnego round-tripu do bazy o coś, co i tak jest równe NOW().
        $deadlineDate = (new \DateTime())->modify("+{$deadlineDays} days");
        $summary = Support::paymentSummary($event, $deadlineDate);
        // Rezerwacja jest już zapisana — brak maila nie może jej cofnąć (sendTemplate() połyka błąd).
        \Core\Lang::with(\Core\Lang::forEmail((string) ($user->email)), static fn() => Mailer::sendTemplate('payment-reminder', $user->email, __('Wstępna rezerwacja: {tytul}', ['tytul' => $event->title]), [
            'recipientName'      => $user->name,
            'eventTitle'         => $event->title,
            'eventDateLabel'     => Format::dateP($edition->startDate),
            'amountLabel'        => $summary['amountLabel'],
            'depositAmountLabel' => $summary['depositAmountLabel'],
            'deadlineDateLabel'  => $summary['deadlineDateLabel'],
            'bankAccount'        => $summary['bankAccount'],
            'bankOwnerName'      => $summary['bankOwnerName'],
            'eventLink'          => View::absoluteUrl('/events/' . $slug) . '?termin=' . $edition->id,
        ]));

        $organizerUser = User::find($event->organizerId);
        if ($organizerUser) {
            \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizerUser))), static fn() => Mailer::sendTemplate('new-signup', Organizer::notificationEmail($organizerUser), __('Nowa rezerwacja: {tytul}', ['tytul' => $event->title]), [
                'recipientName'    => $organizerUser->displayName(),
                'participantName'  => $user->name ?: $user->email,
                'eventTitle'       => $event->title,
                'statusLabel'      => __('wstępna rezerwacja, oczekuje na wpłatę'),
                'participantsLink' => View::absoluteUrl('/wydarzenia/' . $slug . '/uczestnicy'),
            ]));
        }

        header('Location: ' . View::url('/events/' . $slug) . '?termin=' . $edition->id);
        exit;
    }

    public static function joinExternal(string $slug): void
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if (!$event->pricing || $event->registrationTypeCode !== 'external' || !in_array($event->statusCode, ['published', 'full'], true) || !$event->externalRegistrationUrl) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }

        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? null);
        $user = Auth::user();
        // 'zainteresowany' (patrz markInterested()) musi też przejść dalej —
        // bez tego user, który wcześniej kliknął "Zainteresowany", trafiał na
        // zewnętrzny formularz rejestracji (redirect działa), ale zapis w
        // naszej bazie zostawał na zawsze jako 'zainteresowany': organizator
        // nie dostawał maila, a "Moje wydarzenia" nie pokazywało oczekującej
        // rezerwacji — cichy błąd, bo z zewnątrz wyglądało, że wszystko zadziałało.
        if (in_array(EventRsvp::statusForUser($edition->id, $user->id), [null, 'anulowany', 'zainteresowany'], true)) {
            EventRsvp::reserve($edition->id, $user->id);

            $organizerUser = User::find($event->organizerId);
            if ($organizerUser) {
                \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizerUser))), static fn() => Mailer::sendTemplate('new-signup', Organizer::notificationEmail($organizerUser), __('Nowa rezerwacja: {tytul}', ['tytul' => $event->title]), [
                    'recipientName'    => $organizerUser->displayName(),
                    'participantName'  => $user->name ?: $user->email,
                    'eventTitle'       => $event->title,
                    'statusLabel'      => __('wstępna rezerwacja (zapisy zewnętrzne), oczekuje na wpłatę'),
                    'participantsLink' => View::absoluteUrl('/wydarzenia/' . $slug . '/uczestnicy'),
                ]));
            }
        }

        header('Location: ' . $event->externalRegistrationUrl);
        exit;
    }

    // Najlżejsze możliwe oznaczenie — "obserwuję to wydarzenie", dostępne dla
    // KAŻDEGO eventu (darmowego i płatnego, internal i external) — w
    // przeciwieństwie do reserve()/joinExternal() nie prowadzi przez żaden
    // formularz, nie wysyła maila, nie liczy się do puli (patrz EventRsvp::markInterested()).
    public static function markInterested(string $slug): void
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if (!in_array($event->statusCode, ['published', 'full'], true)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }

        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? null);
        $user = Auth::user();
        // Nie nadpisuj istniejącego prawdziwego zapisu — tylko brak zapisu albo
        // wcześniej anulowany (ten sam guard co joinExternal()).
        if (in_array(EventRsvp::statusForUser($edition->id, $user->id), [null, 'anulowany'], true)) {
            EventRsvp::markInterested($edition->id, $user->id);
        }

        header('Location: ' . View::url('/events/' . $slug) . '?termin=' . $edition->id);
        exit;
    }

    // "Byłem" — uczestnik sam potwierdza, że pojechał (albo że nie dojechał).
    // Wejście: przycisk na stronie ZAKOŃCZONEGO wydarzenia; ten sam ekran jest
    // celem linku z maila po wyjeździe (review-invite), dlatego mail prowadzi
    // do strony, a nie wprost do akcji — GET nie może zmieniać stanu, bo
    // skanery/prefetchery w klientach pocztowych klikają linki za użytkownika
    // i potwierdzałyby obecność same z siebie.
    public static function declareAttendance(string $slug): void
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }

        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? null);
        $user = Auth::user();

        // Pytamy tylko tych, którzy mieli potwierdzony zapis na TEN turnus —
        // forEditionAndUser() sam odsiewa resztę (zwraca null).
        $state = EventAttendance::forEditionAndUser($edition->id, $user->id);
        if ($state !== null) {
            EventAttendance::declare(
                $state['rsvpId'],
                ($_POST['attended'] ?? '') === '1',
                $user->id,
                false
            );
        }

        // Whitelista jednej wartości, nie dowolny adres z żądania — pole „wróć
        // tutaj" przyjmujące URL wprost to gotowy open redirect. Ta sama zasada
        // co w TrackController::backUrl(); przycisk „Byłem" stoi od 2026-08-13
        // także w tabeli „Moje przejazdy", a odesłanie stamtąd na stronę
        // wydarzenia gubiłoby kontekst przeglądanej listy.
        header('Location: ' . (($_POST['powrot'] ?? '') === 'moje-przejazdy'
            ? View::url('/admin/moje-przejazdy')
            : View::url('/events/' . $slug) . '?termin=' . $edition->id . '#bylem'));
        exit;
    }

    // Zatwierdzenie obecności przez organizatora z listy uczestników — mocniejszy
    // sygnał niż deklaracja własna (patrz EventAttendance::declare, GREATEST na
    // confirmed_by_organizer). Uprawnienia tą samą bramką co reszta akcji
    // organizatora na tej stronie.
    public static function setAttendance(string $slug, string $userId): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do potwierdzania obecności na tym wydarzeniu.'));
        $redirect = View::url('/wydarzenia/' . $slug . '/uczestnicy');
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . $redirect);
            exit;
        }

        $editionId = (int) ($_POST['edition_id'] ?? 0);
        // Anty-IDOR: turnus musi należeć do TEGO wydarzenia, inaczej organizator
        // mógłby podmienić edition_id na cudzy turnus (ten sam guard co przy wpłatach).
        if (!Support::editionBelongsToEvent($event, $editionId)) {
            header('Location: ' . $redirect);
            exit;
        }

        $state = EventAttendance::forEditionAndUser($editionId, (int) $userId);
        if ($state !== null) {
            EventAttendance::declare(
                $state['rsvpId'],
                ($_POST['attended'] ?? '') === '1',
                Auth::user()->id,
                true
            );
        }

        header('Location: ' . $redirect);
        exit;
    }

    public static function confirmPaymentSelf(string $slug): void
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        // Samo-potwierdzenie tylko dla rejestracji zewnętrznej — przy wewnętrznej
        // płatność trafia na konto organizatora, więc tylko on może ją zweryfikować.
        if (!$event->pricing || $event->registrationTypeCode !== 'external') {
            http_response_code(403);
            echo __('Tego zapisu nie można samodzielnie potwierdzić.');
            return;
        }
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }

        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? null);
        // Samo-potwierdzenie zewnętrznej rejestracji to zawsze deklaracja "zapłaciłem
        // całość" (nie ma tu formularza kwoty) — jedna wpłata na pełną cenę.
        $user = Auth::user();
        if (EventRsvp::confirmPayment($edition->id, $user->id, $event->pricing->amount, $user->id)) {
            \Core\Lang::with(\Core\Lang::forEmail((string) ($user->email)), static fn() => Mailer::sendTemplate('payment-confirmed', $user->email, __('Płatność potwierdzona: {tytul}', ['tytul' => $event->title]), [
                'recipientName'   => $user->name,
                'eventTitle'      => $event->title,
                'eventLink'       => View::absoluteUrl('/events/' . $slug) . '?termin=' . $edition->id,
                'discussionLink'  => View::absoluteUrl('/events/' . $slug) . '#dyskusja',
            ]));
        }

        header('Location: ' . View::url('/events/' . $slug) . '?termin=' . $edition->id);
        exit;
    }

    public static function cancelParticipation(string $slug): void
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }
        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? null);
        // Termin anulowania — egzekwowany też tu, nie tylko przez ukrycie przycisku
        // na event-page.php (bezpośredni POST musi być odrzucony tak samo). Dotyczy
        // WYŁĄCZNIE samoobsługi — organizator anulujący ręcznie (cancelParticipantByOrganizer())
        // tym się nie przejmuje. Liczony od startu WYBRANEGO turnusu, nie
        // pierwszego etapu szablonu trasy — dla innych niż główny termin to co
        // innego (patrz Models\EventEdition).
        if ($event->pricing && $event->pricing->isCancellationDeadlinePassed($edition->startDate)) {
            header('Location: ' . View::url('/events/' . $slug) . '?termin=' . $edition->id . '&blad=termin-anulowania-minal');
            exit;
        }

        $user = Auth::user();
        $newStatus = EventRsvp::requestCancellation($edition->id, $user->id);

        // Mail do organizatora tylko gdy było co zwracać (opłacone) — rezygnacja
        // z jeszcze nieopłaconej rezerwacji nic finansowo nie zmienia dla niego.
        if ($newStatus === 'oczekuje_zwrotu') {
            $organizerUser = User::find($event->organizerId);
            if ($organizerUser && $event->pricing) {
                \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizerUser))), static fn() => Mailer::sendTemplate('refund-requested', Organizer::notificationEmail($organizerUser), __('Rezygnacja z udziału: {tytul}', ['tytul' => $event->title]), [
                    'recipientName'    => $organizerUser->name,
                    'participantName'  => $user->name ?: $user->email,
                    'eventTitle'       => $event->title,
                    'amountLabel'      => Format::price($event->pricing->amount, Format::currencySymbol($event->pricing->currencyCode)),
                    'participantsLink' => View::absoluteUrl('/wydarzenia/' . $slug . '/uczestnicy'),
                ]));
            }
        }

        // Potwierdzenie dla samego uczestnika — dawniej dostawał je tylko wtedy,
        // gdy TO ORGANIZATOR anulował mu udział (patrz cancelParticipantByOrganizer());
        // przy samoobsłudze nie miał żadnego potwierdzenia własnej akcji. Ten sam
        // szablon, tylko druga strona.
        if ($newStatus !== null) {
            Support::notifyCancellation($event, $user, $newStatus);
        }

        header('Location: ' . View::url('/events/' . $slug) . '?termin=' . $edition->id);
        exit;
    }

    public static function participants(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do podglądu uczestników tego wydarzenia.'));

        View::render('web', 'event-participants', [
            'title'       => __('Uczestnicy: {tytul} — ridemore.bike', ['tytul' => $event->title]),
            'event'       => $event,
            'rows'        => EventRsvp::forEvent($event->id),
            'isPaidEvent' => $event->pricing !== null,
            // Obecność ("Byłem") — kolumna pokazuje się dopiero po zakończeniu
            // wyjazdu; wcześniej nie ma czego potwierdzać. Mapa rsvp_id => stan,
            // jedno zapytanie zamiast pytania per wiersz.
            'isCompleted'    => $event->statusCode === 'completed',
            'attendanceMap'  => EventAttendance::mapByRsvpForEvent($event->id),
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => $event->title, 'url' => View::url('/wydarzenia/' . $slug . '/edytuj')], ['label' => __('Uczestnicy')]],
        ]);
    }

    public static function participantPayments(string $slug, string $userId): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do podglądu wpłat dla tego wydarzenia.'));
        $editionId = (int) ($_GET['edition_id'] ?? 0);
        $participant = User::find((int) $userId);
        $rsvp = ($participant && Support::editionBelongsToEvent($event, $editionId))
            ? EventRsvp::forEditionAndUser($editionId, $participant->id)
            : null;
        if (!$event->pricing || !$participant || !$rsvp) {
            header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
            exit;
        }

        View::render('web', 'participant-payments', [
            'title'       => __('Historia wpłat: {kto} — ridemore.bike', ['kto' => $participant->displayName()]),
            'event'       => $event,
            'participant' => $participant,
            'rsvp'        => $rsvp,
            'payments'    => EventRsvp::paymentsForRsvp((int) $rsvp['rsvp_id']),
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => $event->title, 'url' => View::url('/wydarzenia/' . $slug . '/edytuj')], ['label' => __('Uczestnicy'), 'url' => View::url('/wydarzenia/' . $slug . '/uczestnicy')], ['label' => __('Historia wpłat')]],
        ]);
    }

    public static function confirmPayment(string $slug, string $userId): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do potwierdzania płatności dla tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
            exit;
        }
        if (!$event->pricing) {
            header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
            exit;
        }

        $participantId = (int) $userId;
        $editionId = (int) ($_POST['edition_id'] ?? 0);
        // Organizator wpisuje faktycznie otrzymaną kwotę — niekoniecznie zaliczkę
        // ani całość (może być dowolna suma, np. więcej niż zaliczka, albo kolejna
        // rata). EventRsvp::confirmPayment() sam decyduje, czy to rozlicza sprawę
        // w całości (patrz $result['isFullySettled']).
        $amount = (float) str_replace(',', '.', $_POST['kwota'] ?? '0');
        if ($amount > 0 && Support::editionBelongsToEvent($event, $editionId)) {
            $result = EventRsvp::confirmPayment($editionId, $participantId, $amount, Auth::user()->id);
            if ($result) {
                $participant = User::find($participantId);
                if ($participant) {
                    $currencySymbol = Format::currencySymbol($event->pricing->currencyCode);
                    if ($result['isFullySettled']) {
                        \Core\Lang::with(\Core\Lang::forEmail((string) ($participant->email)), static fn() => Mailer::sendTemplate('payment-confirmed', $participant->email, __('Płatność potwierdzona: {tytul}', ['tytul' => $event->title]), [
                            'recipientName'  => $participant->name,
                            'eventTitle'     => $event->title,
                            'eventLink'      => View::absoluteUrl('/events/' . $slug),
                            'discussionLink' => View::absoluteUrl('/events/' . $slug) . '#dyskusja',
                        ]));
                    } else {
                        \Core\Lang::with(\Core\Lang::forEmail((string) ($participant->email)), static fn() => Mailer::sendTemplate('partial-payment-confirmed', $participant->email, __('Wpłata potwierdzona: {tytul}', ['tytul' => $event->title]), [
                            'recipientName'   => $participant->name,
                            'eventTitle'      => $event->title,
                            'amountNowLabel'  => Format::price($result['amountPaidNow'], $currencySymbol),
                            'totalPaidLabel'  => Format::price($result['totalPaid'], $currencySymbol),
                            'remainingLabel'  => Format::price($result['remaining'], $currencySymbol),
                            'amountLabel'     => Format::price($result['price'], $currencySymbol),
                            'eventLink'       => View::absoluteUrl('/events/' . $slug),
                            'discussionLink'  => View::absoluteUrl('/events/' . $slug) . '#dyskusja',
                        ]));
                    }
                }
            }
        }

        header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
        exit;
    }

    public static function confirmRefund(string $slug, string $userId): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do potwierdzania zwrotów dla tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
            exit;
        }

        $participantId = (int) $userId;
        $editionId = (int) ($_POST['edition_id'] ?? 0);
        if (Support::editionBelongsToEvent($event, $editionId) && EventRsvp::confirmRefund($editionId, $participantId, Auth::user()->id)) {
            $participant = User::find($participantId);
            if ($participant && $event->pricing) {
                \Core\Lang::with(\Core\Lang::forEmail((string) ($participant->email)), static fn() => Mailer::sendTemplate('refund-processed', $participant->email, __('Zwrot zrealizowany: {tytul}', ['tytul' => $event->title]), [
                    'recipientName' => $participant->name,
                    'eventTitle'    => $event->title,
                    'amountLabel'   => Format::price($event->pricing->amount, Format::currencySymbol($event->pricing->currencyCode)),
                ]));
            }
        }

        header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
        exit;
    }

    // Anulowanie udziału NA WNIOSEK uczestnika zgłoszony poza platformą (telefon,
    // mail) — organizator robi to za niego z listy uczestników. Ta sama logika co
    // samoobsługowe cancelParticipation() (EventRsvp::requestCancellation()), tylko
    // wywołana przez organizatora dla wskazanego usera zamiast przez niego samego.
    public static function cancelParticipantByOrganizer(string $slug, string $userId): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do anulowania udziału dla tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
            exit;
        }

        $participantId = (int) $userId;
        $editionId = (int) ($_POST['edition_id'] ?? 0);
        $newStatus = Support::editionBelongsToEvent($event, $editionId) ? EventRsvp::requestCancellation($editionId, $participantId) : null;
        if ($newStatus !== null) {
            $participant = User::find($participantId);
            if ($participant) {
                Support::notifyCancellation($event, $participant, $newStatus);
            }
        }

        header('Location: ' . View::url('/wydarzenia/' . $slug . '/uczestnicy'));
        exit;
    }
}
