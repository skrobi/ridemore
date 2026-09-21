<?php
// core/Controllers/RecapController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\Event;
use Models\EventPermission;
use Models\EventPhoto;
use Models\EventRecap;
use Models\EventRsvp;
use Models\Organizer;
use Models\User;
use Utils\Upload;
use Utils\View;

class RecapController
{
    public static function form(string $slug): void
    {
        $user = Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        if ($event->statusCode !== 'completed') {
            echo __('Relację można dodać dopiero po zakończeniu wydarzenia.');
            return;
        }
        if (!EventRsvp::isConfirmedParticipant($event->id, $user->id) && !EventPermission::canEdit($user, $event->organizerId)) {
            http_response_code(403);
            echo __('Tylko uczestnicy tego wydarzenia i organizator mogą dodać relację.');
            return;
        }

        // Wpis należy do TURNUSU (migr. 039) — jedna osoba, która pojechała
        // na dwa terminy tego samego wydarzenia, dorzuca do dwóch kronik.
        $edition = Support::resolveEdition($event, $_GET['termin'] ?? null);

        // DOMYSLNIE NOWY WPIS (migr. 052). Formularz otwarty bez `?wpis=ID`
        // ZAWSZE dopisuje kolejny wpis, bo kronika jest dziennikiem pisanym
        // w trakcie wyjazdu: „jestesmy na zbiorce", „prawie na miejscu",
        // zdjecia z postoju. Edycja konkretnego wpisu wymaga jego id, ktore
        // podaje olowek przy wpisie w kronice — inaczej nie da sie powiedziec,
        // ktory z kilku wpisow tej osoby ma zostac nadpisany.
        $editId = (int) ($_GET['wpis'] ?? 0);
        $existing = $editId > 0 ? EventRecap::findOwn($editId, $user->id) : null;

        View::render('web', 'recap-form', [
            'title'       => __('Relacja: {tytul} — ridemore.bike', ['tytul' => $event->title]),
            'event'       => $event,
            'editionId'   => $edition->id,
            'existing'    => $existing,
            'entriesSoFar'=> EventRecap::countForEditionAuthor($edition->id, $user->id),
            // Zdjęcia już wgrane do tego wpisu — patrz komentarz w recap-form.php.
            'existingPhotos' => $existing ? EventPhoto::forRecap((int) $existing['id']) : [],
            'photoLimit'  => Upload::GALLERY_MAX,
            'breadcrumbs' => [Support::homeCrumb(), ['label' => $event->title, 'url' => View::url('/events/' . $slug)], ['label' => __('Relacja')]],
        ]);
    }

    public static function submit(string $slug): void
    {
        $user = Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) return;
        $canPost = EventRsvp::isConfirmedParticipant($event->id, $user->id) || EventPermission::canEdit($user, $event->organizerId);
        if ($event->statusCode !== 'completed' || !$canPost) {
            http_response_code(403);
            echo __('Nie możesz dodać relacji dla tego wydarzenia.');
            return;
        }

        // Turnus z ukrytego pola formularza (patrz recap-form.php), z fallbackiem
        // na ?termin= i domyślny — ten sam wzorzec co reszta akcji per-turnus.
        $edition = Support::resolveEdition($event, $_POST['edition_id'] ?? ($_GET['termin'] ?? null));
        // Nadpisujemy WYLACZNIE wtedy, gdy formularz przyszedl z id wpisu —
        // czyli z olowka przy konkretnym wpisie. Bez id dopisujemy nowy,
        // nawet jesli ta osoba ma juz w tym turnusie inne wpisy (migr. 052).
        $editId = (int) ($_POST['recap_id'] ?? 0);
        $existing = $editId > 0 ? EventRecap::findOwn($editId, $user->id) : null;

        $fail = function (string $error) use ($event, $slug, $existing, $edition) {
            View::render('web', 'recap-form', [
                'title'       => __('Relacja: {tytul} — ridemore.bike', ['tytul' => $event->title]),
                'event'       => $event,
                'editionId'   => $edition->id,
                'existing'    => $existing,
                'error'       => $error,
                'breadcrumbs' => [Support::homeCrumb(), ['label' => $event->title, 'url' => View::url('/events/' . $slug)], ['label' => __('Relacja')]],
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $fail(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        $body = trim($_POST['body'] ?? '') ?: null;
        $youtubeUrlRaw = trim($_POST['youtube_url'] ?? '') ?: null;
        $youtubeId = EventRecap::extractYoutubeId($youtubeUrlRaw);
        if ($youtubeUrlRaw !== null && $youtubeId === null) {
            $fail(__('To nie wygląda na prawidłowy link do YouTube.'));
            return;
        }

        $hasNewPhotos = !empty(array_filter($_FILES['photos']['name'] ?? [], fn($n) => $n !== ''));
        if ($body === null && $youtubeUrlRaw === null && !$hasNewPhotos && !$existing) {
            $fail(__('Dodaj chociaż tekst, link do filmu albo zdjęcie.'));
            return;
        }

        // MAIL TYLKO PRZY PIERWSZYM WPISIE TEJ OSOBY W TYM TURNUSIE, a nie przy
        // każdym nowym (migr. 052). Odkąd kronika przyjmuje wiele wpisów,
        // „nowy wpis" zdarza się kilka razy dziennie — organizator dostawałby
        // maila za każde „jesteśmy na zbiórce".
        $isFirstFromAuthor = EventRecap::countForEditionAuthor($edition->id, $user->id) === 0;
        if ($existing) {
            EventRecap::update($existing['id'], $body, $youtubeUrlRaw);
            $recapId = $existing['id'];
        } else {
            $recapId = EventRecap::create($event->id, $user->id, $body, $youtubeUrlRaw, $edition->id);
            if ($recapId === null) {
                $fail(__('Coś poszło nie tak, spróbuj ponownie.'));
                return;
            }
        }

        // ZDJĘCIA: zapisujemy tyle, ile się da, i mówimy o reszcie.
        //
        // Poprzednia wersja miała tu jedno try/catch wokół OBU wywołań i pusty
        // blok catch. Skutek zgłoszony 2026-08-14: wpis z czterema zdjęciami
        // zapisał się bez żadnego. Mechanika awarii: saveImage() rzucał na
        // trzecim pliku, co przerywało pętlę w saveGalleryPhotos, wyjątek
        // wypadał tutaj i kasował ZMIENNĄ $urls z dwoma plikami, które już
        // wylądowały na dysku — więc EventPhoto::attach() nie wykonywał się
        // wcale. Pliki zostawały osierocone (zmierzone: 2 na dysku, 0 wierszy
        // w event_photos), a użytkownik nie dostawał żadnego komunikatu.
        //
        // Teraz odporność jest w pętli (Utils\Upload), a tutaj zostaje tylko
        // zapis tego, co się udało, i przekazanie powodów dalej.
        $photoErrors = [];
        $urls = Upload::saveGalleryPhotos($_FILES['photos'] ?? [], Upload::GALLERY_MAX, 'gallery', $photoErrors);
        if ($urls) {
            try {
                EventPhoto::attach($event->id, $user->id, $urls, null, $recapId);
            } catch (\Throwable $e) {
                // Relacja jest już zapisana — błąd zapisu zdjęć nie może jej cofnąć.
                $photoErrors[] = __('Nie udało się podpiąć zdjęć do wpisu.');
            }
        }

        // Tylko przy pierwszym dodaniu — edycja własnej relacji nie powinna spamować
        // organizatora ponownym mailem za każdą poprawkę literówki.
        if ($isFirstFromAuthor && $user->id !== $event->organizerId) {
            $organizerUser = User::find($event->organizerId);
            if ($organizerUser) {
                \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizerUser))), static fn() => Mailer::sendTemplate('recap-posted', Organizer::notificationEmail($organizerUser), __('Nowa relacja: „{tytul}”', ['tytul' => $event->title]), [
                    'recipientName' => $organizerUser->displayName(),
                    'authorName'    => $user->name ?: $user->email,
                    'eventTitle'    => $event->title,
                    'link'          => View::absoluteUrl('/events/' . $slug) . '#opinie',
                ]));
            }
        }

        // Po dodaniu wpisu wracamy do KRONIKI, nie na stronę wydarzenia — to
        // tam wpis właśnie wylądował i tam autor zobaczy efekt swojego działania.
        //
        // Odrzucone zdjęcia jadą w adresie, bo wpis JEST już zapisany i nie ma
        // do czego wrócić formularzem. Milczenie było gorsze od komunikatu:
        // autor widział swój wpis bez zdjęć i nie miał jak się dowiedzieć, czy
        // to jego wina, czy usterka.
        $adres = View::url('/kronika/' . $slug) . '?termin=' . $edition->id;
        if ($photoErrors) {
            $adres .= '&zdjecia=' . rawurlencode(implode(' · ', array_slice($photoErrors, 0, 4)));
        }
        header('Location: ' . $adres . '#dziennik');
        exit;
    }
}
