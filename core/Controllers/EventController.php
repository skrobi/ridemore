<?php
// core/Controllers/EventController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Database;
use Core\Mailer;
use Models\ActivationToken;
use Models\Discovery;
use Models\EditionTrack;
use Models\Event;
use Models\EventAttendance;
use Models\EventComment;
use Models\EventGroupConversation;
use Models\EventPermission;
use Models\EventPhoto;
use Models\EventRecap;
use Models\EventReview;
use Models\EventRouteVariant;
use Models\EventRsvp;
use Models\MapLayer;
use Models\MatchEngine;
use Models\Organizer;
use Models\OrganizerBillingProfile;
use Models\RecommendationLog;
use Models\RiderConnection;
use Models\TileCache;
use Models\TileSource;
use Models\User;
use Resources\EventFormInput;
use Resources\EventFormResource;
use Resources\EventResource;
use Resources\MatchCardResource;
use Utils\Format;
use Utils\JsonLd;
use Utils\View;

class EventController
{
    // Świadomie BEZ Auth::check() — dostępne też dla niezalogowanych gości, żeby
    // można było zgłosić wydarzenie w czyimś imieniu bez zakładania konta (patrz
    // sekcja "W czyim imieniu" w event-form.php). Zalogowany user zgłaszający dla
    // kogoś innego niż on sam trafia w tę samą ścieżkę weryfikacji co gość —
    // jedyny wyjątek to admin i sam docelowy organizator (patrz create()).
    // Kreator krokowy (patrz szablony/dodaj-kreator.html) — WYŁĄCZNIE dla
    // dodawania nowego wydarzenia. Edycja (editForm()/update() niżej) zostaje
    // przy dzisiejszym jednostronicowym event-form.php, bez zmian. Renderuje
    // się w tym samym, zwykłym layout.php co reszta serwisu (pełna nawigacja
    // i stopka) — kreator to nowy UKŁAD KROKÓW wewnątrz strony, nie osobna
    // "goła" strona.
    public static function createForm(): void
    {
        $user = Auth::check() ? Auth::user() : null;
        $breadcrumbs = $user
            ? [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Dodaj wydarzenie')]]
            : [Support::homeCrumb(), ['label' => __('Zgłoś wydarzenie')]];
        View::render('web', 'event-form-wizard', [
            'title'           => __('Dodaj wydarzenie — ridemore.bike'),
            'extraHead'       => Support::leafletMapHead(),
            'initialState'    => EventFormResource::empty(),
            // Dotyczy tylko trybu "moje" — dla wyszukanego/nowego organizatora prawdziwe
            // rozliczenie i tak weryfikuje Event::save() po id docelowego organizatora,
            // to tu tylko podpowiedź w UI.
            'billingComplete' => $user ? OrganizerBillingProfile::isComplete($user->id) : false,
            'dictOptions'     => Support::eventFormDictOptions(),
            'isEdit'          => false,
            'formAction'      => View::url('/wydarzenia/nowe'),
            'breadcrumbs'     => $breadcrumbs,
        ]);
    }

    public static function create(): void
    {
        $user = Auth::check() ? Auth::user() : null;
        $breadcrumbs = $user
            ? [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Dodaj wydarzenie')]]
            : [Support::homeCrumb(), ['label' => __('Zgłoś wydarzenie')]];

        $fail = function (string $error) use ($user, $breadcrumbs) {
            View::render('web', 'event-form-wizard', [
                'title'           => __('Dodaj wydarzenie — ridemore.bike'),
                'extraHead'       => Support::leafletMapHead(),
                // fromPost(), NIE empty() — inaczej każdy błąd walidacji czyści
                // wszystko, co użytkownik wpisał (patrz zgłoszenie użytkownika).
                'initialState'    => EventFormResource::fromPost($_POST),
                'billingComplete' => $user ? OrganizerBillingProfile::isComplete($user->id) : false,
                'dictOptions'     => Support::eventFormDictOptions(),
                'isEdit'          => false,
                'formAction'      => View::url('/wydarzenia/nowe'),
                'error'           => $error,
                'breadcrumbs'     => $breadcrumbs,
            ]);
        };

        // Pułapka na boty (pole ukryte CSS-em w event-form.php) — po cichu ignoruj,
        // bez podpowiadania botowi co poszło nie tak.
        if (!empty($_POST['website'])) {
            return;
        }

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $fail(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        $type = $_POST['type'] ?? 'ustawka';

        $submitterName  = null;
        $submitterEmail = null;

        // pokrec_z_kims nie ma sekcji "Kogo dotyczy" (patrz event-form.php,
        // §3.2 specyfikacji) — nie ma tu osoby trzeciej do wyszukania/założenia,
        // zgłaszający JEST właścicielem swojego ogłoszenia. Zalogowany: staje
        // się organizer_id wprost. Gość: potrzebujemy jednak jakiegoś users.id
        // (events.organizer_id jest NOT NULL) — więc zakładamy dla niego, po
        // jego własnym e-mailu, dokładnie takie samo "oczekujące" konto jak przy
        // 'new' organizatorze niżej (do ewentualnego dokończenia rejestracji),
        // zamiast wymyślać osobny mechanizm.
        if ($type === 'pokrec_z_kims') {
            if ($user) {
                $organizerId = $user->id;
            } else {
                $submitterName  = trim($_POST['submitter_name'] ?? '') ?: null;
                $submitterEmail = trim($_POST['submitter_email'] ?? '');
                if ($submitterEmail === '' || !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) {
                    $fail(__('Podaj poprawny e-mail — potrzebny do powiadomienia o publikacji.'));
                    return;
                }
                $existingUser = User::findByEmail($submitterEmail);
                if ($existingUser) {
                    $organizerId = $existingUser->id;
                } else {
                    $newUser = User::createPending($submitterEmail);
                    if ($submitterName) {
                        $newUser->updateName($submitterName);
                    }
                    $organizerId = $newUser->id;
                }
            }
            self::finishCreate($organizerId, $submitterName, $submitterEmail, $user, $fail, $type);
            return;
        }

        // Kim jest docelowy organizator — "ja" wymaga zalogowania (broni się też
        // przed spreparowanym POST-em od gościa: bez sesji nie ma czyjego "ja" być).
        $organizerMode = $_POST['organizer_mode'] ?? 'existing';
        if (!in_array($organizerMode, ['self', 'existing', 'new'], true) || (!$user && $organizerMode === 'self')) {
            $organizerMode = 'existing';
        }

        if ($organizerMode === 'self') {
            $organizerId = $user->id;
        } else {
            // Kontakt do zgłaszającego — dla niezalogowanego gościa pytamy wprost;
            // dla zalogowanego bierzemy z sesji (nie pytamy drugi raz), ale i tak
            // zapisujemy, żeby po zatwierdzeniu/odrzuceniu było komu wysłać wynik
            // (patrz Event::approveSubmission()/reject()).
            if (!$user) {
                $submitterName  = trim($_POST['submitter_name'] ?? '') ?: null;
                $submitterEmail = trim($_POST['submitter_email'] ?? '');
                if ($submitterEmail === '' || !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) {
                    $fail(__('Podaj poprawny e-mail kontaktowy — potrzebny do weryfikacji zgłoszenia.'));
                    return;
                }
            } else {
                $submitterName  = $user->displayName();
                $submitterEmail = $user->email;
            }

            if ($organizerMode === 'existing') {
                $organizerId = (int) ($_POST['organizer_id'] ?? 0);
                $checkStmt = Database::connection()->prepare('SELECT 1 FROM organizer_profiles WHERE user_id = :id');
                $checkStmt->execute(['id' => $organizerId]);
                if ($organizerId <= 0 || !$checkStmt->fetchColumn()) {
                    $fail(__('Wybierz organizatora z listy podpowiedzi.'));
                    return;
                }
            } else { // 'new'
                $newName  = trim($_POST['new_organizer_name'] ?? '');
                $newEmail = trim($_POST['new_organizer_email'] ?? '');
                if ($newName === '' || $newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $fail(__('Podaj nazwę i poprawny e-mail nowego organizatora.'));
                    return;
                }
                $existingUser = User::findByEmail($newEmail);
                if ($existingUser) {
                    // Ten e-mail już ma konto na ridemore.bike — dopisujemy do NIEGO,
                    // zamiast tworzyć duplikat/widmowego użytkownika.
                    $organizerId = $existingUser->id;
                } else {
                    $newUser = User::createPending($newEmail);
                    $newUser->updateName($newName);
                    $organizerId = $newUser->id;
                }
            }
        }

        self::finishCreate($organizerId, $submitterName, $submitterEmail, $user, $fail, $type);
    }

    // Wspólny ogon POST /wydarzenia/nowe dla wszystkich trzech typów —
    // wyodrębniony, bo pokrec_z_kims (patrz wyżej) resolveuje organizera
    // zupełnie inaczej niż pozostałe dwa typy, ale od momentu ustalenia
    // $organizerId reszta (zapis, powiadomienia, przekierowanie) jest
    // identyczna poza celem przekierowania (Zadanie 7: pokrec_z_kims trafia
    // na ekran potwierdzenia z mostem do Facebooka, nie prosto na stronę eventu).
    private static function finishCreate(int $organizerId, ?string $submitterName, ?string $submitterEmail, ?User $user, callable $fail, string $type): void
    {
        Organizer::ensureProfile($organizerId);

        try {
            $input = EventFormInput::fromRequest($_POST, $_FILES, $organizerId, null);
            if ($input['title'] === '') {
                $fail(__('Podaj tytuł wydarzenia.'));
                return;
            }

            // Sam organizator albo admin publikują/szkicują normalnie (jak dotąd).
            // Każdy inny zgłaszający — nawet zalogowany — trafia do kolejki
            // weryfikacji, niezależnie co wybrał w formularzu.
            $isSelfOrAdmin = ($user && $user->id === $organizerId) || ($user && $user->isAdmin);
            if (!$isSelfOrAdmin) {
                $input['status'] = 'oczekuje_weryfikacji';
            }
            $input['submitterName']  = $submitterName;
            $input['submitterEmail'] = $submitterEmail;

            $slug = Event::save($input);

            if (!$isSelfOrAdmin) {
                Event::notifySubmissionForReview($slug, $organizerId, $submitterName, $submitterEmail, $user);
            } elseif ($user && $user->id !== $organizerId) {
                // Admin dodał wydarzenie dla kogoś innego — publikuje się od razu
                // (stąd $isSelfOrAdmin===true, bez kolejki), ale organizator i tak
                // powinien się dowiedzieć, że coś przybyło pod jego nazwiskiem.
                Event::notifyOrganizerAssignedByAdmin($slug, $organizerId, $user);
            }
        } catch (\InvalidArgumentException $e) {
            // Wyjątki walidacyjne rzucane celowo przez Event::save() (np. brak
            // daty wydarzenia, albo walidacja pokrec_z_kims) — treść jest
            // bezpieczna i pomocna do pokazania.
            $fail('Nie udało się zapisać wydarzenia: ' . $e->getMessage());
            return;
        } catch (\Throwable $e) {
            // Wszystko inne (np. błąd SQL) mogłoby ujawnić szczegóły zapytań/
            // ścieżek serwera — user dostaje ogólny komunikat, szczegóły lecą
            // do error logu (patrz set_exception_handler w bootstrap.php).
            error_log(sprintf('Błąd zapisu wydarzenia: %s w %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $fail(__('Nie udało się zapisać wydarzenia. Spróbuj ponownie za chwilę.'));
            return;
        }

        $redirectUrl = $type === 'pokrec_z_kims'
            ? View::url('/wydarzenia/potwierdzenie/' . $slug)
            : View::url('/events/' . $slug);
        header('Location: ' . $redirectUrl);
        exit;
    }

    public static function editForm(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do edycji tego wydarzenia.'));

        $raw = Event::findRawById($event->id);
        View::render('web', 'event-form', [
            'title'           => __('Edytuj: {tytul} — ridemore.bike', ['tytul' => $event->title]),
            'extraHead'       => Support::leafletMapHead(),
            'initialState'    => EventFormResource::fromRawEvent($raw),
            'billingComplete' => OrganizerBillingProfile::isComplete($event->organizerId),
            'dictOptions'     => Support::eventFormDictOptions(),
            'isEdit'          => true,
            'formAction'      => View::url('/wydarzenia/' . $slug . '/edytuj'),
            'breadcrumbs'     => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Edytuj: ' . $event->title]],
        ]);
    }

    public static function update(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do edycji tego wydarzenia.'));

        $fail = function (string $error) use ($event, $slug) {
            // fromPost(), NIE fromRawEvent() — ten drugi cofa się do stanu z bazy
            // sprzed edycji i tak samo gubi wszystko, co użytkownik właśnie wpisał.
            View::render('web', 'event-form', [
                'title'           => __('Edytuj: {tytul} — ridemore.bike', ['tytul' => $event->title]),
                'extraHead'       => Support::leafletMapHead(),
                'initialState'    => EventFormResource::fromPost($_POST),
                'billingComplete' => OrganizerBillingProfile::isComplete($event->organizerId),
                'dictOptions'     => Support::eventFormDictOptions(),
                'isEdit'          => true,
                'formAction'      => View::url('/wydarzenia/' . $slug . '/edytuj'),
                'error'           => $error,
                'breadcrumbs'     => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Edytuj: ' . $event->title]],
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $fail(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        try {
            $input = EventFormInput::fromRequest($_POST, $_FILES, $event->organizerId, $event->id);
            if ($input['title'] === '') {
                $fail(__('Podaj tytuł wydarzenia.'));
                return;
            }
            $newSlug = Event::save($input);
        } catch (\InvalidArgumentException $e) {
            // Wyjątki walidacyjne rzucane celowo przez Event::save() (np. brak
            // daty wydarzenia) — treść jest bezpieczna i pomocna do pokazania.
            $fail('Nie udało się zapisać wydarzenia: ' . $e->getMessage());
            return;
        } catch (\Throwable $e) {
            // Wszystko inne (np. błąd SQL) mogłoby ujawnić szczegóły zapytań/
            // ścieżek serwera — user dostaje ogólny komunikat, szczegóły lecą
            // do error logu (patrz set_exception_handler w bootstrap.php).
            error_log(sprintf('Błąd zapisu wydarzenia: %s w %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $fail(__('Nie udało się zapisać wydarzenia. Spróbuj ponownie za chwilę.'));
            return;
        }

        header('Location: ' . View::url('/events/' . $newSlug));
        exit;
    }

    public static function delete(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do usunięcia tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/admin'));
            exit;
        }

        // Trwałe usunięcie: zawsze dla szkiców, ALBO dla dowolnego statusu, gdy
        // event nie ma ani JEDNEGO zapisu (dopisane 2026-08-09 — zgłoszenie
        // usera: opublikował testowe wydarzenie i nie miał jak go skasować,
        // "odwołaj" tylko zmienia status, nie usuwa). Realny event z choćby
        // jednym zapisem/płatnością zostaje chroniony — dla niego jest tylko
        // "odwołaj" (zachowuje historię), patrz EventRsvp::existsAnyForEvent().
        if ($event->statusCode === 'draft' || !EventRsvp::existsAnyForEvent($event->id)) {
            Event::delete($event->id);
        }

        header('Location: ' . View::url('/admin'));
        exit;
    }

    // Zatwierdź/odrzuć zgłoszenie "w czyimś imieniu" (patrz create()) — ta sama
    // bramka co edycja (EventPermission::canEdit): admin ALBO docelowy
    // organizator, jeśli już ma aktywne konto. Nic nowego do wymyślania w warstwie
    // uprawnień, "móc zatwierdzić" i "móc edytować" to dokładnie ten sam krąg osób.
    public static function approve(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do zatwierdzenia tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/admin'));
            exit;
        }

        if ($event->statusCode === 'oczekuje_weryfikacji') {
            Event::approveSubmission($event->id);

            if ($event->submitterEmail) {
                // Wydarzenie jest już zatwierdzone — brak maila tego nie cofa (sendTemplate() połyka błąd).
                \Core\Lang::with(\Core\Lang::forEmail((string) ($event->submitterEmail)), static fn() => Mailer::sendTemplate('submission-approved', $event->submitterEmail, __('Zatwierdzono wydarzenie: „{tytul}”', ['tytul' => $event->title]), [
                    'recipientName' => $event->submitterName,
                    'eventTitle'    => $event->title,
                    'eventLink'     => View::absoluteUrl('/events/' . $event->slug),
                ]));
            }
        }

        header('Location: ' . View::url('/admin'));
        exit;
    }

    public static function reject(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do odrzucenia tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/admin'));
            exit;
        }

        // Nigdy nie było publicznie widoczne — trwałe usunięcie jest bezpieczne,
        // ten sam wzorzec co kasowanie szkiców w delete().
        if ($event->statusCode === 'oczekuje_weryfikacji') {
            if ($event->submitterEmail) {
                // Nie blokujemy usunięcia przez nieudaną wysyłkę (sendTemplate() połyka błąd).
                \Core\Lang::with(\Core\Lang::forEmail((string) ($event->submitterEmail)), static fn() => Mailer::sendTemplate('submission-rejected', $event->submitterEmail, __('Zgłoszenie odrzucone: „{tytul}”', ['tytul' => $event->title]), [
                    'recipientName' => $event->submitterName,
                    'eventTitle'    => $event->title,
                ]));
            }
            Event::delete($event->id);
        }

        header('Location: ' . View::url('/admin'));
        exit;
    }

    // Link "przejmij profil" dla organizatora założonego przez kogoś innego
    // (patrz User::createPending() w create()) — tylko admin: skoro
    // konto nie ma jeszcze hasła, sam docelowy organizator z definicji nie może
    // być tym, kto klika ten przycisk. Token bez limitu czasu (ttlMinutes=null) —
    // w odróżnieniu od zwykłej aktywacji przy rejestracji, ten link admin wysyła
    // ręcznie i może to zająć tygodnie, zanim ktoś go kliknie.
    public static function issueClaimLink(string $slug): void
    {
        Auth::requireAdmin(__('Tylko administrator może wygenerować link do przejęcia profilu.'));
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/admin'));
            exit;
        }

        $token = ActivationToken::issueFor($event->organizerId, null);
        $link  = View::absoluteUrl('/rejestracja/dokoncz?token=' . urlencode($token));

        header('Location: ' . View::url('/admin?claim_link=' . urlencode($link)));
        exit;
    }

    public static function cancel(string $slug): void
    {
        $event = Support::requireEventEditPermission($slug, __('Nie masz uprawnień do odwołania tego wydarzenia.'));
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/admin'));
            exit;
        }

        if (in_array($event->statusCode, ['draft', 'published', 'full'], true)) {
            Event::cancel($event->id);
        }

        header('Location: ' . View::url('/admin'));
        exit;
    }

    // Ekran po zapisaniu luźnego wyjazdu (Zadanie 7 specyfikacji Etapu 1) —
    // jedyny mechanizm zasięgu, dopóki nie ma modułu dopasowań (Etap 2): bez
    // tego zgłaszający widziałby tylko surową stronę eventu bez żadnego
    // "co dalej" i nie wracał. Dostępna tylko dla tego typu — inne dwa mają
    // swój zwykły przepływ (redirect prosto na /events/{slug}).
    public static function confirmation(string $slug): void
    {
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if ($event->eventTypeCode !== 'pokrec_z_kims') {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }

        View::render('web', 'event-confirmation', [
            'title'     => __('Zgłoszenie przyjęte — ridemore.bike'),
            'noindex'   => true,
            'eventData' => EventResource::fromModel($event),
        ]);
    }

    public static function show(string $slug): void
    {
        $event = Event::findBySlug($slug);
        if (!$event) {
            http_response_code(404);
            View::render('web', 'event-page', ['eventData' => null, 'title' => __('Nie znaleziono'), 'noindex' => true]);
            return;
        }
        $eventData = EventResource::fromModel($event);
        $hasGpx = false;
        foreach ($eventData['stages'] as $stage) {
            if (!empty($stage['gpxUrl'])) { $hasGpx = true; break; }
        }
        // Warianty trasy: GPX żyje w event_route_variants, nie w event_stages
        // (event-page.php podmienia trasę na wybrany wariant dopiero w widoku) —
        // bez tego leaflet-gpx (L.GPX) nigdy się nie ładuje dla eventów z wariantami.
        if (!$hasGpx) {
            foreach ($eventData['variants'] as $v) {
                if (!empty($v['gpxUrl'])) { $hasGpx = true; break; }
            }
        }

        // Turnusy (patrz Models\EventEdition) — ?termin=ID pozwala odwiedzającemu
        // przełączyć wybrany termin (patrz "Wybierz termin" na event-page.php);
        // bez tego parametru albo z nieprawidłową wartością pada na domyślny
        // (najbliższy nadchodzący). myStatus/paymentInfo/próg anulowania niżej
        // dotyczą WYŁĄCZNIE tego jednego, wybranego turnusu — ten sam user może
        // mieć inny status na innym terminie tego samego wydarzenia.
        $selectedEdition = Support::resolveEdition($event, $_GET['termin'] ?? null);
        $eventData['selectedEditionId'] = $selectedEdition->id;

        // Warianty trasy (pętle) — ?wariant=ID wybiera pętlę (analogicznie do
        // ?termin dla turnusu); bez/zły -> pierwszy. Wybrany wariant niesie własną
        // trasę, cenę i limit. "Zostało miejsc" liczone per (wybrany turnus,
        // wariant) — limit jest per wariant (patrz Models\EventRouteVariant).
        $selectedVariantId = null;
        if (!empty($eventData['variants'])) {
            $requestedVariant = ctype_digit((string) ($_GET['wariant'] ?? '')) ? (int) $_GET['wariant'] : null;
            $selectedVariantId = $eventData['variants'][0]['id'];
            foreach ($eventData['variants'] as $v) {
                if ($v['id'] === $requestedVariant) { $selectedVariantId = $v['id']; break; }
            }
            foreach ($eventData['variants'] as &$v) {
                $confirmed = EventRouteVariant::confirmedCount($selectedEdition->id, $v['id']);
                $v['confirmedCount'] = $confirmed;
                $v['spotsLeft'] = $v['maxParticipants'] !== null ? max(0, $v['maxParticipants'] - $confirmed) : null;
            }
            unset($v);
        }
        $eventData['selectedVariantId'] = $selectedVariantId;

        $myStatus = Auth::check() ? EventRsvp::statusForUser($selectedEdition->id, Auth::user()->id) : null;

        // Szczegóły płatności pod przyciskiem CTA (patrz event-page.php) — te same
        // dane co w mailu payment-reminder, żeby user nie musiał grzebać w skrzynce
        // przy każdorazowym powrocie na stronę eventu. Termin liczony na żywo z
        // joined_at + payment_deadline_days_before (bez trzymania osobnej kolumny).
        // Tylko internal — przy external płatność/rozliczenie dzieje się poza
        // platformą, nie mamy tu żadnych danych do pokazania. 'oczekuje_doplaty'
        // (zaliczka już wpłynęła, brakuje dopłaty) traktowane tak samo jak
        // 'oczekuje_platnosci' wszędzie indziej w apce — patrz EventRsvp::
        // requestCancellation()/confirmPayment(), event-participants.php.
        $paymentInfo = null;
        if (in_array($myStatus, ['oczekuje_platnosci', 'oczekuje_doplaty'], true) && $event->registrationTypeCode === 'internal' && $event->pricing) {
            $joinedAt = EventRsvp::joinedAtForUser($selectedEdition->id, Auth::user()->id);
            $deadlineDays = $event->pricing->paymentDeadlineDaysBefore ?? 7;
            // Gdy jest zaliczka, TO ona zabezpiecza rezerwację do terminu — reszta
            // ceny płatna zgodnie z zasadami organizatora (patrz "Cena i co
            // obejmuje" na tej samej stronie, sekcja z paymentDeadlineDays/cancellationPolicy),
            // nie mamy osobnej, policzonej daty na dopłatę pozostałej kwoty.
            $deadlineDate = $joinedAt ? (new \DateTime($joinedAt))->modify("+{$deadlineDays} days") : null;
            $paymentInfo = Support::paymentSummary($event, $deadlineDate);
        }

        // Egzekwowanie terminu anulowania (patrz EventPricing::isCancellationDeadlinePassed())
        // — decyduje, czy przycisk "Anuluj udział" w ogóle się pokaże (patrz
        // cancel-participation-form.php). Liczony od startu WYBRANEGO turnusu
        // (nie pierwszego etapu szablonu trasy — dla innego niż główny termin to
        // co innego). Organizator anulujący ręcznie za kogoś (POST
        // /uczestnicy/{userId}/anuluj) tym NIE jest ograniczony.
        $cancellationDeadlinePassed = $event->pricing
            ? $event->pricing->isCancellationDeadlinePassed($selectedEdition->startDate)
            : false;

        // "Relacje i opinie" — tylko dla zakończonych eventów, dociągane tu (nie w
        // widoku) bo potrzebują numerycznego event->id, którego EventResource nie
        // eksponuje (tylko slug).
        $reviews = [];
        $recaps  = [];
        $photos  = [];
        $canReview = false;
        $canRecap  = false;
        $myRecap   = null;
        if ($eventData['statusCode'] === 'completed') {
            $reviews = EventReview::forEvent($event->id);
            $recaps  = EventRecap::forEvent($event->id);
            $photos  = EventPhoto::forEvent($event->id);
            if (Auth::check()) {
                $user = Auth::user();
                $isParticipant = EventRsvp::isConfirmedParticipant($event->id, $user->id);
                $canReview = $isParticipant && !EventReview::hasReviewed($event->id, $user->id, $event->organizerId);
                $canRecap  = $isParticipant || EventPermission::canEdit($user, $event->organizerId);
                $myRecap   = EventRecap::findByEventAndAuthor($event->id, $user->id);
            }
        }

        // Dyskusja — tylko dla eventów, które JESZCZE się nie odbyły (po zakończeniu
        // jest zakładka "Relacje i opinie" zamiast tego). Otwarta dla każdego
        // zalogowanego (nie tylko zapisanych — pytania często padają przed zapisem).
        $comments = [];
        if ($eventData['statusCode'] !== 'completed') {
            $comments = EventComment::forEvent($event->id);
        }
        // Moderacja dyskusji + dodawanie par Q&A (2026-08-09) — ten sam krąg
        // osób co edycja wydarzenia (organizator/współpracownik/admin).
        $canModerateDiscussion = Auth::check() && EventPermission::canEdit(Auth::user(), $event->organizerId);
        // Pytania i odpowiedzi jako structured data — patrz Utils\JsonLd::forFaq()
        // (tam też uczciwa uwaga o tym, czego NIE należy się spodziewać po
        // wynikach rozszerzonych Google). Liczone z tych samych komentarzy,
        // które i tak są już wczytane.
        $faqPairs = [];
        foreach ($comments as $c) {
            foreach ($c['replies'] as $r) {
                if (!empty($r['isOrganizerReply'])) {
                    $faqPairs[] = ['question' => $c['body'], 'answer' => $r['body']];
                    break;
                }
            }
        }

        // Meta description z realnych danych trasy — dokładnie w duchu "82 km,
        // 60% szuter, pobierz GPX" zamiast ogólnikowego opisu powielonego na
        // każdej stronie eventu.
        // SEO (2026-09-14): KIEDY i GDZIE na początku — to dwie rzeczy, po
        // które ktoś szuka wyjazdu, a Google ucina opis po ~155 znakach.
        // Pełny adres zbiórki zjadał dotąd cały ten limit.
        // Do tytułu tylko pewna miejscowość albo region; do opisu może też
        // krótki, ręcznie wpisany adres zbiórki.
        $place = JsonLd::addressParts($eventData['meetingPointAddress'] ?? null)['locality'] ?? ($eventData['regionLabel'] ?? null);
        $rawMeeting = trim((string) ($eventData['meetingPointAddress'] ?? ''));
        $startLabel = JsonLd::addressParts($rawMeeting)['locality']
            ?? ($rawMeeting !== '' && mb_strlen($rawMeeting) <= 45 ? $rawMeeting : $place);
        $whenLabel = null;
        if ($selectedEdition->startDate && !$selectedEdition->dateIsFlexible) {
            $whenLabel = Format::dateP($selectedEdition->startDate)
                . ($selectedEdition->startTime ? ', ' . __('godz.') . ' ' . substr($selectedEdition->startTime, 0, 5) : '');
        }
        $descParts = [];
        if ($whenLabel) {
            $descParts[] = $whenLabel;
        }
        if ($startLabel) {
            $descParts[] = __('start: {miejsce}', ['miejsce' => $startLabel]);
        }
        if ($eventData['totals']['distanceKm'] > 0) {
            $descParts[] = round($eventData['totals']['distanceKm']) . ' km';
        }
        if (!empty($eventData['difficultyLabel'])) {
            $descParts[] = __('poziom: {poziom}', ['poziom' => mb_strtolower($eventData['difficultyLabel'])]);
        }
        $metaDescription = $descParts ? implode(' · ', $descParts) . '.' : '';
        if (!empty($eventData['description'])) {
            $metaDescription .= ($metaDescription ? ' ' : '') . JsonLd::excerpt($eventData['description'], max(60, 155 - mb_strlen($metaDescription)));
        }
        $seoTitle = $eventData['title']
            . ($place || $whenLabel ? ' — ' . implode(', ', array_filter([$place, $whenLabel ? Format::dateP($selectedEdition->startDate) : null])) : '')
            . ' | ridemore.bike';
        if ($metaDescription === '') {
            $metaDescription = __('Wyjazd rowerowy „{tytul}" organizowany przez {organizator} na ridemore.bike.', ['tytul' => $eventData['title'], 'organizator' => $eventData['organizer']['name']]);
        }

        // Etap 2 (dopasowania), krok 4: widget na stronie wydarzenia. Tylko dla
        // opublikowanych, aktywnych turnusów — dopasowanie zakończonego/anulowanego
        // wydarzenia nie ma odbiorcy, który mógłby z niego skorzystać (docs/etap2:
        // "Nie proponujemy wyjazdów już domkniętych").
        // KTO OGLĄDA — poza warunkiem statusu, bo używają tego DWIE niezależne
        // rzeczy: dopasowania (tylko dla opublikowanych, niżej) ORAZ warstwy
        // mapy (dla KAŻDEGO wydarzenia, ok. 100 linii dalej).
        //
        // Do 2026-08-29 przypisanie stało wewnątrz `if` poniżej. Dla wydarzenia
        // zakończonego, anulowanego albo szkicu zmienna po prostu nie istniała,
        // więc `$viewerId !== null` przy warstwach mapy było FAŁSZEM niezależnie
        // od tego, kto patrzy — zalogowany dostawał mapę w trybie wylogowanego:
        // własna warstwa odkryć wyłączona i podpisana „gdzie jeżdżą inni"
        // zamiast „gdzie już byłeś". Ekran nie pokazywał żadnego błędu; jedynym
        // objawem był PHP-owy warning w logu.
        $viewerId = Auth::check() ? Auth::user()->id : null;

        $matchResult = ['matches' => [], 'wideningLevel' => 0];
        $criticalMassMessage = null;
        $criticalMassProgress = null;
        if ($eventData['statusCode'] === 'published') {
            $matchResult = MatchEngine::forEdition($selectedEdition->id, 3, $viewerId);
            $criticalMassMessage = MatchEngine::criticalMassMessage($event->id, $selectedEdition->id);
            // Surowe liczby pod pasek postępu w panelu zapisu (patrz .prog__bar
            // w event-page.php) — ta sama SQL co criticalMassMessage() wyżej,
            // patrz Models\MatchEngine::criticalMassProgress().
            $criticalMassProgress = MatchEngine::criticalMassProgress($event->id, $selectedEdition->id);

            // Etap 3 §8 — patrz komentarz przy /api/matches/preview (api/routes.php):
            // dziennik zapisany tu, nie w MatchEngine.
            foreach ($matchResult['matches'] as $i => $m) {
                RecommendationLog::record($viewerId, $m['eventId'], 'event_page', $m['score'], $i + 1, $m['isExploration'] ?? false, $m['isAspirational'] ?? false, $m['derivedShare'] ?? 0.0);
            }
        }

        // Skład turnusu — zasila DWA miejsca naraz: awatary "N osób już jedzie"
        // w panelu zapisu (.book__who, jak dotąd) ORAZ nową sekcję "Kto jedzie"
        // w treści strony. Jedno zapytanie na obie grupy, patrz
        // Models\EventRsvp::rosterForEdition() (zastępuje tu wcześniejsze
        // confirmedParticipantsForEdition() — 'confirmed' ma ten sam kształt
        // wiersza, więc panel zapisu działa bez zmian w widoku).
        //
        // Per WYBRANY turnus, nie całe wydarzenie. Tylko gdy w ogóle sensowne
        // pokazywać zapisy (opublikowane/pełne) — dla szkicu/odwołanego/
        // w-weryfikacji lista byłaby myląca albo pusta z definicji.
        $roster = in_array($eventData['statusCode'], ['published', 'full'], true)
            ? EventRsvp::rosterForEdition($selectedEdition->id)
            // Kształt MUSI być pełny (z kluczami *Total), bo widok czyta je
            // bezwarunkowo — panel zapisu renderuje się także dla statusów,
            // dla których składu nie pokazujemy (szkic/odwołany/zakończony).
            : ['confirmed' => [], 'confirmedTotal' => 0, 'interested' => [], 'interestedTotal' => 0];
        // Lista = tylko osoby widoczne; licznik = wszyscy zapisani (patrz
        // EventRsvp::rosterForEdition — ukrycie dotyczy tożsamości, nie faktu).
        $confirmedParticipants = $roster['confirmed'];
        $confirmedTotal = $roster['confirmedTotal'];

        // "Byłem" — pytanie o faktyczną obecność, tylko na ZAKOŃCZONYM wydarzeniu
        // i tylko dla zalogowanego z potwierdzonym zapisem na ten turnus
        // (forEditionAndUser() zwraca null dla wszystkich pozostałych).
        // To jest cel linku z maila po wyjeździe — patrz
        // Event::sendReviewInvitesIfNeeded() i szablon review-invite.
        $attendance = ($eventData['statusCode'] === 'completed' && Auth::check())
            ? EventAttendance::forEditionAndUser($selectedEdition->id, Auth::user()->id)
            : null;

        // Co ten wyjazd dał w Discovery (Etap 8, §38) — wyłącznie dla kogoś,
        // kto już potwierdził obecność, bo tylko wtedy przejazd w ogóle
        // istnieje. Podpięte pod ten sam warunek co pytanie "Byłeś?", żeby nie
        // dokładać zapytania na każdą stronę wydarzenia.
        $discoveryRide = ($attendance !== null && $attendance['attended'] === true)
            ? Discovery::rideForRsvp($attendance['rsvpId'])
            : null;

        // Ślad z ODBYTEGO wyjazdu (migr. 042) — jedyne źródło odkryć.
        // Wgrać go może organizator (ślad wspólny) albo uczestnik z
        // POTWIERDZONĄ obecnością (ślad własny). Zapis bez potwierdzonej
        // obecności nie wystarcza: ślad ma dokumentować przejazd, który się
        // odbył, a nie zamiar udziału.
        $canManageEvent = Auth::check() && EventPermission::canEdit(Auth::user(), $event->organizerId);
        $isAttendeeHere = $attendance !== null && $attendance['attended'] === true;
        $canUploadTrack = $eventData['statusCode'] === 'completed' && ($canManageEvent || $isAttendeeHere);
        $editionTracks = $canUploadTrack ? EditionTrack::forEdition($selectedEdition->id) : [];

        // Kronika (Etap 4) istnieje dokładnie wtedy, gdy ktokolwiek potwierdził
        // obecność na tym turnusie — bo skład jest jedyną rzeczą, której kronika
        // nie może nie mieć. Ten sam warunek co ChronicleController::show.
        $hasChronicle = $eventData['statusCode'] === 'completed'
            && EventAttendance::attendedForEdition($selectedEdition->id) !== [];

        // PELETON — kto z osób, z którymi widz FAKTYCZNIE już jechał, jedzie
        // także na ten turnus. Wyróżniony podzbiór składu: znajomy w składzie
        // waży przy decyzji o zapisie nieporównanie więcej niż nieznajomy.
        // Tylko dla zalogowanych (gość nie ma peletonu) i tylko tam, gdzie w
        // ogóle pokazujemy skład (ten sam warunek statusu co $roster wyżej).
        $pelotonHere = (Auth::check() && in_array($eventData['statusCode'], ['published', 'full'], true))
            ? RiderConnection::pelotonOnEdition($selectedEdition->id, Auth::user()->id)
            : [];

        // Link "Dyskusja grupy" — tylko dla członków kanału tego turnusu
        // (organizator/współpracownik albo realny zapis, patrz
        // EventGroupConversation::canAccess). Liczone raz w kontrolerze, nie
        // zapytaniem w widoku.
        $canGroupChat = Auth::check()
            && EventGroupConversation::canAccess($selectedEdition->id, Auth::user()->id, Auth::user()->isAdmin);

        // Okruszki — Start / Wyjazdy / {region, samym tekstem — nie ma dziś
        // dedykowanej strony regionu w tej apce} / {tytuł}. Ta sama etykieta
        // "Start" co w przebudowanych dziś stronie głównej i /wydarzenia.
        $breadcrumbs = [
            ['label' => __('Start'), 'url' => View::url('/')],
            ['label' => __('Wyjazdy'), 'url' => View::url('/wydarzenia')],
        ];
        if (!empty($eventData['regionLabel'])) {
            // Link do strony regionu (2026-09-14) — dotąd okruszek był samym
            // tekstem. Przy kilku regionach prowadzi do pierwszego.
            $regionPath = \Models\Region::pathForCode($eventData['regionCode'] ?? null);
            $breadcrumbs[] = ['label' => $eventData['regionLabel']] + ($regionPath ? ['url' => View::url($regionPath)] : []);
        }
        $breadcrumbs[] = ['label' => $eventData['title']];

        // DRZEWO WARSTW (Etap 2, tasks/done/warstwy-mapy.md) — ta sama
        // reguła co na stronie trasy: panel dnia NIE JEST mapą odkryć, więc
        // `only` zdejmuje „Ślady", a mgła dostaje inny domyślny stan i inne
        // brzmienie niż na /odkrycia (dodatek do trasy, nie temat strony) —
        // patch na kluczu `cells` zamiast osobnego wiersza w słowniku dla tej
        // samej warstwy. Od Etapu 3 „Trasy" też trzeba patchować z powrotem
        // na katalog (`kr`) — słownik od tej daty daje kontekstowi `me`
        // WYŁĄCZNIE trasy ukończone przez widza, ale panel dnia ma pokazywać
        // szlaki PO DRODZE, nie czyjś postęp.
        $mapLayers = MapLayer::tree($viewerId !== null ? 'me' : 'all', [
            'loggedIn' => $viewerId !== null,
            'only'     => ['cells', 'heat', 'trails', 'treasures'],
        ]);
        foreach ($mapLayers as &$mapLayer) {
            if ($mapLayer['key'] === 'cells') {
                $mapLayer['on'] = $viewerId !== null;
                $mapLayer['hint'] = $viewerId !== null ? __('gdzie już byłeś') : __('gdzie jeżdżą inni');
            } elseif ($mapLayer['key'] === 'trails') {
                $mapLayer['trackKey'] = 'kr';
                // Dzieci „Ukończone"/„Nieukończone" (migr. 078) — patrz ta
                // sama nota w TrailController::show. Panel dnia pokazuje
                // szlaki dookoła, nie postęp widza na innych trasach.
                $mapLayer['children'] = [];
            }
        }
        unset($mapLayer);
        $mapSources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $mapSources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }

        View::render('web', 'event-page', [
            'eventData'  => $eventData,
            // .mbar (pasek mobilny z ceną/CTA) tylko gdy w ogóle jest sens
            // zapisu — ten sam warunek co blok CTA na stronie (status
            // published/full), patrz style.css `body.has-mbar`.
            'bodyClass'  => in_array($eventData['statusCode'], ['published', 'full'], true) ? 'has-mbar' : '',
            'breadcrumbs' => $breadcrumbs,
            'matchCards' => MatchCardResource::fromMatches($matchResult['matches'], fn($slug) => View::url('/events/' . $slug)),
            'matchWideningLabel' => MatchEngine::wideningLabel($matchResult['wideningLevel']),
            'criticalMassMessage' => $criticalMassMessage,
            'criticalMassProgress' => $criticalMassProgress,
            'confirmedParticipants' => $confirmedParticipants,
            'confirmedTotal' => $confirmedTotal,
            'roster'     => $roster,
            'attendance' => $attendance,
            'discoveryRide' => $discoveryRide,
            'canUploadTrack' => $canUploadTrack,
            'canManageEvent' => $canManageEvent,
            'isAttendeeHere' => $isAttendeeHere,
            'editionTracks'  => $editionTracks,
            // TA SAMA zmienna co przy warstwach mapy wyżej — nie powtórzone
            // wyrażenie. Dwie kopie tego samego pytania to dwa miejsca, które
            // mogą się rozjechać; jedna z nich właśnie się rozjechała.
            'viewerId'       => $viewerId,
            'hasChronicle' => $hasChronicle,
            'pelotonHere' => $pelotonHere,
            'canGroupChat' => $canGroupChat,
            'title'      => $seoTitle,
            // Szkic i oczekujący na weryfikację — poza indeksem. Odwołany
            // zostaje: Google zaleca oznaczyć go EventCancelled, a nie usuwać.
            'noindex'    => in_array($eventData['statusCode'], ['draft', 'oczekuje_weryfikacji'], true),
            'description' => $metaDescription,
            'ogImage'    => $eventData['coverPhotoUrl'] ? View::absoluteUrl($eventData['coverPhotoUrl']) : null,
            'ogType'     => 'article',
            // Dwa osobne bloki structured data: Event (czym strona JEST) +
            // FAQPage (pytania i odpowiedzi na niej) — patrz JsonLd::forFaq().
            'jsonLd'     => JsonLd::forEvent($eventData, View::currentCanonicalUrl())
                . JsonLd::forFaq($faqPairs, View::currentCanonicalUrl()),
            'reviews'    => $reviews,
            'recaps'     => $recaps,
            'galleryPhotos' => $photos,
            'canReview'  => $canReview,
            'canRecap'   => $canRecap,
            'myRecap'    => $myRecap,
            'comments'   => $comments,
            'canModerateDiscussion' => $canModerateDiscussion,
            'myStatus'   => $myStatus,
            'paymentInfo' => $paymentInfo,
            'cancellationDeadlinePassed' => $cancellationDeadlinePassed,
            // MAPA PRZEBIEGU TO STANDARDOWA KONTROLKA (2026-08-20, zgłoszenie
            // usera: „mapa wydarzenia nie jest spójna z pozostałymi mapami
            // w aplikacji"). Do tej daty był tu goły Leaflet ze śladem — bez
            // pól odkryć, bez znanych tras, bez skarbów i bez przełącznika
            // warstw, czyli jedyna mapa w serwisie, która nie zachowywała się
            // jak reszta. Adresy API składa KONTROLER, nigdy widok — tak samo
            // jak na stronie trasy i na profilu rowerzysty.
            'mapEndpoints' => [
                'cells'     => View::url('/api/discovery/cells'),
                'trailsAt'  => View::url('/api/discovery/trails/at'),
                'treasures' => View::url('/api/treasures'),
                'claim'     => View::url('/api/treasures/claim'),
                'confirm'   => View::url('/api/treasures/confirm'),
            ],
            'mapLayers'  => $mapLayers,
            'mapSources' => $mapSources,
            'mapFilters' => MapLayer::filtersFor($mapLayers),
            // Leaflet też przy samym punkcie zbiórki (bez śladu GPX) — patrz karta
            // "Start" na event-page.php, drugi slajd z mapką lokalizacji zbiórki.
            'extraHead'  => $hasGpx
                ? Support::gpxMapHead() . "\n"
                    . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>'
                : ($eventData['meetingPointLat'] !== null ? Support::leafletMapHead() : ''),
        ]);
    }
}
