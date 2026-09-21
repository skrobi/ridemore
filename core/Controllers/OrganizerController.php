<?php
// core/Controllers/OrganizerController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\ActivationToken;
use Models\Dictionary;
use Models\Event;
use Models\EventReview;
use Models\Organizer;
use Resources\EventCardResource;
use Utils\JsonLd;
use Utils\RateLimiter;
use Utils\View;

class OrganizerController
{
    // MUSI być zarejestrowane przed show() niżej — konwencja tego pliku (patrz
    // /api/events/map w api/routes.php), choć tu akurat bez ryzyka kolizji:
    // {slug} wymaga segmentu ścieżki, którego "/organizatorzy" nie ma.
    public static function index(): void
    {
        $arr = fn(string $key) => array_values(array_filter((array) ($_GET[$key] ?? []), 'is_string'));

        $filters = [
            'q'            => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'regions'      => $arr('regions'),
            // Typ organizatora i weryfikacja to dwie NIEZALEŻNE cechy (patrz komentarz
            // przy Organizer::search()) — osobne parametry, nie jedno pole 'type'
            // udające oba naraz.
            'type'         => in_array($_GET['type'] ?? '', ['peer', 'professional_operator'], true) ? $_GET['type'] : null,
            'verifiedOnly' => ($_GET['verified'] ?? '') === '1',
            'bikeTypes'    => $arr('bikeTypes'),
            'minRating'    => is_numeric($_GET['minRating'] ?? null) ? (float) $_GET['minRating'] : null,
        ];
        $sort = ($_GET['sort'] ?? '') === 'events' ? 'events' : 'rating';

        $result = Organizer::search($filters, $sort, 60, 0);
        // Okładka karty — realne zdjęcia z ostatnich eventów, nie dekoracyjne SVG
        // (patrz plan). N+1, akceptowalne przy skali tej appki, ten sam duch co
        // reszta strony organizatora (Organizer::recentCoverPhotos()).
        $organizers = array_map(function ($o) {
            $zdjecia = Organizer::coverPhotosWithSource($o['userId'], 3);
            $o['coverPhotos'] = $zdjecia['urls'];
            $o['coverPhotosSource'] = $zdjecia['source'];
            return $o;
        }, $result['items']);

        View::render('web', 'organizers-list', [
            'organizers'    => $organizers,
            'total'         => $result['total'],
            'filters'       => $filters,
            'sort'          => $sort,
            'title'         => __('Organizatorzy wyjazdów rowerowych — kluby, grupy i biura | ridemore.bike'),
            'description'   => __('Kluby, grupy rowerowe i biura podróży organizujące wyjazdy w Polsce i na Świecie — sprawdź oceny, specjalizacje i nadchodzące wyprawy.'),
            'filterOptions' => [
                'regions'      => Dictionary::groupedLeaves('region'),
                'organizerTypes' => Dictionary::items('organizer_type'),
                'bikeTypes'    => Dictionary::items('bike_type'),
            ],
            'breadcrumbs'   => [Support::homeCrumb(), ['label' => __('Organizatorzy')]],
        ]);
    }

    public static function show(string $slug): void
    {
        $organizer = Organizer::findBySlug($slug);
        // Dezaktywowany przez admina (patrz Organizer::setActive()) profil znika z
        // publicznego widoku — traktujemy jak "nie znaleziono", chyba że patrzy
        // admin (musi mieć jak sprawdzić/przywrócić profil, patrz /admin/organizatorzy).
        $isAdminViewer = Auth::check() && Auth::user()->isAdmin;
        if (!$organizer || (!$organizer->isActive && !$isAdminViewer)) {
            http_response_code(404);
            View::render('web', 'organizer-profile', ['organizer' => null, 'title' => __('Nie znaleziono'), 'noindex' => true]);
            return;
        }

        // Opis organizatora w języku strony (wielojęzyczność, 2026-09-16) —
        // podmieniany na modelu PRZED widokiem i JSON-LD, żeby oba mówiły to samo.
        // Formularz edycji czyta profil osobno, więc oryginał w nim zostaje.
        $organizer->bio = \Models\ContentTranslation::text($organizer->bio, 'organizer:' . $organizer->userId);
        $bioTranslation = \Models\ContentTranslation::meta('organizer:' . $organizer->userId);

        $upcoming = Event::upcoming(['organizerId' => $organizer->userId], 12, 0, 'date');
        // "Zobacz pozostałe N" w sekcji Historia rozwija tę samą stronę pod tym
        // samym adresem (bez osobnej trasy/paginacji) zamiast domyślnego cappa na 5.
        $historyLimit = ($_GET['historia'] ?? '') === 'wszystko' ? 200 : 5;
        $history = Event::completed(['organizerId' => $organizer->userId], $historyLimit);
        \Models\ContentTranslation::prefetchRows(array_merge($upcoming['items'], $history['items']));
        $specializationChips = Organizer::specializationChips($organizer->userId);

        $orgDescParts = [$organizer->organizerType === 'professional_operator' ? __('Operator turystyczny') : __('Organizator społecznościowy')];
        if ($organizer->regionName) $orgDescParts[] = $organizer->regionName;
        if ($specializationChips) $orgDescParts[] = implode(', ', array_slice($specializationChips, 0, 3));
        if (!empty($organizer->bio)) $orgDescParts[] = mb_substr(trim($organizer->bio), 0, 100);
        $orgDescription = $organizer->name . ' na ridemore.bike — ' . implode('. ', $orgDescParts) . '.';
        $reviewStats = EventReview::statsFor($organizer->userId);

        // Widok właściciela (patrz organizator-v3.html, pasek "Widok właściciela")
        // — RÓŻNY od $isAdminViewer: to sam organizator patrzący na swój
        // publiczny profil, nie administrator. Lista braków to podpowiedź co
        // uzupełnić, widoczna WYŁĄCZNIE jemu — nigdy na publicznej stronie,
        // żeby nie wyglądało to jak zarzut/ostrzeżenie dla odwiedzających.
        $viewer = Auth::check() ? Auth::user() : null;
        $isOwnerViewer = $viewer !== null && $viewer->id === $organizer->userId;
        // Jedno źródło prawdy dla "czego brakuje" — patrz Organizer::completeness(),
        // reużywane też w karcie "Kompletność profilu" na /admin/profil-rozliczeniowy.
        $missingFields = $isOwnerViewer
            ? array_column(array_filter(Organizer::completeness($organizer)['items'], fn($i) => !$i['done']), 'label')
            : [];

        View::render('web', 'organizer-profile', [
            'organizer'         => $organizer,
            'stats'             => Organizer::stats($organizer->userId),
            'specialization'    => $specializationChips,
            'coverPhotos'       => Organizer::recentCoverPhotos($organizer->userId),
            'totalCoverPhotos'  => Organizer::totalCoverPhotoCount($organizer->userId),
            'heroCover'         => Organizer::heroCover($organizer->userId),
            'ridingProfile'     => Organizer::ridingProfile($organizer->userId),
            'upcomingEvents'    => array_map(fn($row) => EventCardResource::fromRow($row), $upcoming['items']),
            'historyEvents'     => array_map(fn($row) => EventCardResource::fromRow($row), $history['items']),
            'historyTotal'      => $history['total'],
            'historyExpanded'   => $historyLimit > 5,
            'reviewStats'       => $reviewStats,
            'reviews'           => EventReview::forOrganizer($organizer->userId),
            'isAdminViewer'     => $isAdminViewer,
            'isOwnerViewer'     => $isOwnerViewer,
            'bioTranslation'    => $bioTranslation,
            'missingFields'     => $missingFields,
            'claimed'           => ($_GET['przejeto'] ?? '') === '1',
            'title'             => __('{nazwa} — organizator — ridemore.bike', ['nazwa' => $organizer->name]),
            'description'       => $orgDescription,
            'ogImage'           => $organizer->avatarUrl ? View::absoluteUrl($organizer->avatarUrl) : null,
            'ogType'            => 'profile',
            'jsonLd'            => JsonLd::forOrganizer($organizer, $reviewStats, View::currentCanonicalUrl()),
            'breadcrumbs'       => [Support::homeCrumb(), ['label' => __('Organizatorzy'), 'url' => View::url('/organizatorzy')], ['label' => $organizer->name]],
        ]);
    }

    // Admin-only, minimalny przełącznik weryfikacji (patrz plan: brak osobnego
    // workflow wniosków) — steruje odznaką "Zweryfikowany" na publicznym profilu.
    public static function verify(string $slug): void
    {
        Auth::requireAdmin();
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo __('Sesja wygasła, spróbuj ponownie.');
            return;
        }
        $organizer = Organizer::findBySlug($slug);
        if (!$organizer) {
            http_response_code(404);
            echo __('Nie znaleziono organizatora.');
            return;
        }

        Organizer::setVerified($organizer->userId, !$organizer->isVerified);

        header('Location: ' . View::url('/organizatorzy/' . $slug));
        exit;
    }

    // Samoobsługowe przejęcie nieprzejętego profilu (konto bez hasła, założone
    // automatycznie przy zgłoszeniu eventu "w czyimś imieniu") — CELOWO bez
    // Auth::requireLogin(), dostępne dla każdego anonimowego odwiedzającego stronę
    // organizatora, dokładnie jak POST /rejestracja. W odróżnieniu od istniejącego,
    // ręcznego mechanizmu admina (EventController::issueClaimLink(), patrz
    // dashboard.php) link NIE jest tu nigdzie pokazywany klikającemu — leci mailem
    // wyłącznie na adres już przypisany do konta, więc przejąć profil może
    // faktycznie tylko ten, kto ma dostęp do tamtej skrzynki.
    public static function claim(string $slug): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/organizatorzy/' . $slug));
            exit;
        }
        $organizer = Organizer::findBySlug($slug);
        if (!$organizer) {
            http_response_code(404);
            echo __('Nie znaleziono organizatora.');
            return;
        }

        // Rate limit: brak logowania i brak innej ochrony przed masowym spamowaniem
        // cudzej skrzynki linkiem przejęcia profilu (patrz komentarz nad metodą).
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (RateLimiter::tooMany('claim-organizer:ip:' . $ip, 20, 3600)
            || RateLimiter::tooMany('claim-organizer:slug:' . $slug, 3, 600)) {
            header('Location: ' . View::url('/organizatorzy/' . $slug . '?przejeto=1'));
            exit;
        }

        if ($organizer->isUnclaimed) {
            // Bezterminowy (null) jak w wersji admina — "ważny do pierwszego użycia",
            // patrz komentarz w EventController::issueClaimLink().
            $token = ActivationToken::issueFor($organizer->userId, null);
            $link  = View::absoluteUrl('/rejestracja/dokoncz?token=' . urlencode($token));
            \Core\Lang::with(\Core\Lang::forEmail((string) ($organizer->email)), static fn() => Mailer::sendTemplate('claim-organizer-profile', $organizer->email,
                __('Przejmij swój profil organizatora na ridemore.bike'),
                ['link' => $link, 'organizerName' => $organizer->name]));
        }

        header('Location: ' . View::url('/organizatorzy/' . $slug . '?przejeto=1'));
        exit;
    }
}
