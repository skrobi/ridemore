<?php
// core/Controllers/HomeController.php
// Strona główna — od 2026-07-31 landing zamiast filtrowalnej listy (patrz
// Controllers\EventsListController dla tamtej, przeniesionej pod '/wydarzenia').
namespace Controllers;

use Core\Auth;
use Models\Discovery;
use Models\Event;
use Models\EventRecap;
use Models\EventStage;
use Models\KnownRoute;
use Models\MatchEngine;
use Models\Organizer;
use Models\Pulse;
use Models\RecommendationLog;
use Models\RoutePreview;
use Models\TileSource;
use Resources\EventCardResource;
use Resources\MatchCardResource;
use Utils\DiscoveryGrid;
use Utils\Gpx;
use Utils\View;

class HomeController
{
    // Ile regionów pokazać w kafelkach sekcji "Regiony" — reszta zostaje
    // dostępna przez /wydarzenia (link "Wszystkie regiony").
    private const REGIONS_SHOWN = 7;

    // Wymiary viewBox wykresu profilu w sekcji "Trasa dnia" — stałe, bo SVG
    // ze ścieżką (Utils\Gpx::toSvgPath()) i tak skaluje się do 100% szerokości
    // kontenera przez CSS (.rotd-chart), viewBox ustala tylko proporcje.
    private const ROTD_VIEW_W = 1200.0;
    private const ROTD_VIEW_H = 260.0;

    // Miniatura profilu na kartach "Najbliższe wyjazdy" — mały viewBox,
    // proporcje zgodne z .card__v (400×158, patrz assets/css/style.css).
    private const CARD_THUMB_VIEW_W = 400.0;
    private const CARD_THUMB_VIEW_H = 158.0;

    public static function index(): void
    {
        // Jak w EventsListController — oportunistyczne wyzwolenie zamiast
        // potwierdzonego harmonogramu cron, idempotentne, tania operacja na
        // stronie z realnym ruchem.
        Event::processCompletions();
        Event::expireStalePendingPayments();

        $viewerId = Auth::check() ? Auth::user()->id : null;

        // "Odkrywanie bierne" (Etap 3) — bez zmian logiki względem dawnego
        // HomeController, tylko przeniesione tu z EventsListController (patrz
        // tamten plik — obie strony pokazują ten sam widget, patrz partial).
        $matchCards = [];
        if ($viewerId !== null) {
            $matches = MatchEngine::profileMatchesForUser($viewerId, '2000-01-01 00:00:00', 3);
            foreach ($matches as $i => $m) {
                RecommendationLog::record($viewerId, $m['eventId'], 'list', $m['score'], $i + 1, $m['isExploration'], $m['isAspirational'], $m['derivedShare']);
            }
            $matchCards = MatchCardResource::fromMatches($matches, fn($slug) => View::url('/events/' . $slug));
        }
        // Bez spersonalizowanych dopasowań (gość anonimowy albo brak profilu/
        // deklaracji) widget ma zawsze wyglądać tak samo — jedna karta hero +
        // reszta w sidebarze (user: "zawsze jak mam zgłoszenie") — więc zamiast
        // pustki pokazujemy realne, otwarte ogłoszenia "Pokręcę z kimś" z całego
        // serwisu, bez personalizacji (patrz MatchCardResource::fromLooseRideFallback()).
        // isFallback — widget przechodzi wtedy w tryb BEZ personalizacji
        // (patrz looseRideFallbackCards: zero geografii, zero profilu, po
        // prostu otwarte ogłoszenia z całego kraju). Nagłówek musi to
        // odzwierciedlać, inaczej obiecuje dopasowanie, którego w tym trybie
        // nie ma (poprawka 2026-08-09 — user zobaczył propozycję 500 km dalej
        // pod nagłówkiem "Może Cię zainteresować").
        $isFallback = empty($matchCards);
        if ($isFallback) {
            $matchCards = self::looseRideFallbackCards($viewerId);
        }

        $upcomingResult = Event::upcoming([], 4, 0, 'date', $viewerId);
        $featuredEvents = array_map(function ($row) {
            $ev = EventCardResource::fromRow($row);
            $ev['routeThumbnailPath'] = self::routeThumbnailFor($ev['eventId']);
            return $ev;
        }, $upcomingResult['items']);
        $totalUpcoming  = $upcomingResult['total'];

        $formatCounts = [
            'ustawka'                => Event::upcoming(['eventTypes' => ['ustawka']], 1)['total'],
            'wycieczka_wielodniowa'  => Event::upcoming(['eventTypes' => ['wycieczka_wielodniowa']], 1)['total'],
            'pokrec_z_kims'          => Event::upcoming(['eventTypes' => ['pokrec_z_kims']], 1)['total'],
            'wyscig'                 => Event::upcoming(['eventTypes' => ['wyscig']], 1)['total'],
        ];

        $regionCounts = array_slice(Event::upcomingCountsByRegion(), 0, self::REGIONS_SHOWN);

        $routeOfDay = self::buildRouteOfDay();

        $recap = EventRecap::mostRecentPublic();
        $stats = [
            'upcomingCount'  => $totalUpcoming,
            'organizerCount' => Organizer::totalCount(),
        ];

        View::render('web', 'home', [
            // Puls na stronie głównej (Etap 5) — trzy najświeższe wpisy nad
            // kalendarzem. Strona główna mówiła dotąd wyłącznie w czasie
            // przyszłym; to pierwsze miejsce, gdzie widać, że coś się TU dzieje.
            'pulseItems'     => Pulse::feed(3),
            'matchCards'     => $matchCards,
            'matchHeading'   => $isFallback
                ? __('Ktoś szuka towarzystwa')
                : __('Może Cię zainteresować'),
            'matchIsFallback' => $isFallback,
            'featuredEvents' => $featuredEvents,
            'formatCounts'   => $formatCounts,
            'regionCounts'   => $regionCounts,
            'routeOfDay'     => $routeOfDay,
            // Odkrycia na stronie głównej (2026-08-13) — ta sama liczba i ten
            // sam komponent co na /odkrycia. Powód jest produktowy: strona
            // główna mówiła wyłącznie o KALENDARZU, więc ktoś, kto tu ląduje,
            // nie miał skąd wiedzieć, że ten serwis jest o czymś więcej niż
            // zapisy na wyjazdy. „Razem odkryliśmy N pól" to jedyne zdanie,
            // które mówi to bez tłumaczenia.
            'community'      => Discovery::communityStats(30),
            'recap'          => $recap,
            'stats'          => $stats,
            // WebSite + Organization (SEO, 2026-09-14): z tego Google bierze
            // nazwę serwisu i logo pokazywane nad adresem w wynikach.
            'jsonLd'         => \Utils\JsonLd::forNode([
                '@type'     => 'WebSite',
                '@id'       => View::absoluteUrl('/') . '#website',
                'name'      => 'ridemore.bike',
                'alternateName' => ['RideMore', 'ridemore bike'],
                'url'       => View::absoluteUrl('/'),
                'inLanguage' => 'pl-PL',
                'publisher' => ['@id' => View::absoluteUrl('/') . '#organization'],
            ]) . \Utils\JsonLd::forNode([
                '@type' => 'Organization',
                '@id'   => View::absoluteUrl('/') . '#organization',
                'name'  => 'ridemore.bike',
                'url'   => View::absoluteUrl('/'),
                'logo'  => View::absoluteUrl('/assets/logo/android-chrome-512x512.png'),
            ]),
            'title'          => __('Wspólne wyjazdy rowerowe i odkrywanie tras w Polsce i na Świecie | ridemore.bike'),
            'description'    => __('Znajdź ludzi do wspólnej jazdy, odkrywaj nowe trasy w Polsce i na Świecie i zbieraj przejechane miejsca na mapie. Jazdy gravelowe i MTB, wycieczki wielodniowe i rajdy. Bezpłatnie, 0% prowizji.'),
        ]);
    }

    // Ile otwartych ogłoszeń "Pokręcę z kimś" pobrać jako pulę pod fallback
    // widgetu dopasowań, zanim wybierzemy z niej 3 z największą grupą.
    private const LOOSE_RIDE_FALLBACK_POOL = 10;

    // Fallback widgetu dopasowań, patrz komentarz w index(). Sortujemy w PHP
    // po confirmedCount malejąco (Event::upcoming() nie ma takiej opcji sortu)
    // — ta sama zasada co w prawdziwym dopasowaniu: największa grupa pierwsza.
    private static function looseRideFallbackCards(?int $viewerId): array
    {
        $rows = Event::upcoming(['eventTypes' => ['pokrec_z_kims']], self::LOOSE_RIDE_FALLBACK_POOL, 0, 'date', $viewerId)['items'];
        \Models\ContentTranslation::prefetchRows($rows);
        $cards = array_map(fn($row) => EventCardResource::fromRow($row), $rows);
        usort($cards, fn($a, $b) => $b['confirmedCount'] <=> $a['confirmedCount']);
        $top = array_slice($cards, 0, 3);
        return MatchCardResource::fromLooseRideFallback($top, fn($slug) => View::url('/events/' . $slug));
    }

    // Realna mini-ścieżka profilu (pierwszy etap eventu) pod tło karty
    // "Najbliższe wyjazdy" — zamiast fabrykowanej dekoracji jak w makiecie.
    // Null gdy event nie ma jeszcze przetworzonego GPX-a — karta wtedy
    // pokazuje zwykłą okładkę/placeholder (patrz home-event-card.php).
    private static function routeThumbnailFor(?int $eventId): ?array
    {
        if ($eventId === null) {
            return null;
        }
        $stages = EventStage::findByEventId($eventId);
        $firstStage = $stages[0] ?? null;
        if ($firstStage === null || empty($firstStage->elevationProfile)) {
            return null;
        }
        return [
            'area' => Gpx::toSvgAreaPath($firstStage->elevationProfile, self::CARD_THUMB_VIEW_W, self::CARD_THUMB_VIEW_H, 0.35),
            'line' => Gpx::toSvgPath($firstStage->elevationProfile, self::CARD_THUMB_VIEW_W, self::CARD_THUMB_VIEW_H, 0.35),
        ];
    }

    // Ile znaków opisu pod kartą "Trasa dnia" (2026-09-05) — dość, żeby
    // powiedzieć coś konkretnego, mało, żeby nie zdominować karty; ta sama
    // rola co $recapQuote w home.php, tylko osobna kopia: tamten cytat i ten
    // opis mają różne źródła (relacja vs. wydarzenie/trasa) i różne miejsce
    // w kodzie (widok vs. kontroler) — sklejanie ich jednym helperem
    // kosztowałoby więcej niż jest warte przy jednej linijce logiki.
    private const ROTD_DESCRIPTION_MAX = 160;

    // Wybiera "Trasę dnia": najpierw najbliższe nadchodzące wydarzenie
    // z realnym profilem elewacji (Event::firstUpcomingWithElevationProfile()),
    // a gdy żadne się nie kwalifikuje — znaną trasę (KnownRoute::routeOfDay(),
    // 2026-09-05, prośba usera: "dołożyłbym też znane trasy, rozszerzają one
    // zakres Trasa dnia — nie musi być to tylko event"). WYDARZENIE MA
    // PIERWSZEŃSTWO: to ono niesie termin i licznik zapisów, więc "co się
    // dzieje niedługo" ma priorytet nad katalogiem tras, które leżą tam
    // zawsze. Obie ścieżki oddają WSPÓLNY kształt — widok nie musi wiedzieć,
    // skąd dana "Trasa dnia" pochodzi, poza jednym polem `type` (pod ikonkę
    // organizatora, której trasa katalogowa nie ma).
    private static function buildRouteOfDay(): ?array
    {
        return self::buildRouteOfDayFromEvent() ?? self::buildRouteOfDayFromKnownRoute();
    }

    private static function buildRouteOfDayFromEvent(): ?array
    {
        $row = Event::firstUpcomingWithElevationProfile();
        if ($row === null) {
            return null;
        }

        $profile = $row['elevation_profile'];

        return [
            'type'         => 'event',
            'slug'         => $row['slug'],
            'title'        => $row['title'],
            'description'  => self::excerpt($row['description'] ?? null),
            'startDate'    => $row['start_date'],
            // Okładka i długość wyjazdu (2026-08-13): karta dzieli się na dwie
            // części — profil wysokości po lewej, zdjęcie po prawej. Zdjęcie ma
            // sens tylko przy wyjeździe JEDNODNIOWYM: przy wielodniówce profil
            // pierwszego etapu i tak opisuje ułamek trasy, więc zestawienie go
            // ze zdjęciem obiecywałoby całość.
            'coverPhotoUrl' => $row['cover_photo_url'] ?? null,
            'durationDays'  => (int) ($row['duration_days'] ?? 1),
            'regionName'   => $row['region_name'],
            'bikeTypeNames' => $row['bike_type_names'],
            'distanceKm'   => round((float) $row['distance_km'], 1),
            'elevationGainM' => (int) $row['elevation_gain_m'],
            'organizerName'       => $row['organizer_name'],
            'organizerAvatarUrl'  => $row['organizer_avatar_url'],
            // Zgłoszenie usera 2026-09-10: awatar organizatora na tej karcie
            // nie prowadził NIGDZIE — brakowało tu sluga, bo dotąd karta
            // pokazywała tylko imię jako tekst, bez linku. Slug bierze się
            // z TEGO SAMEGO LEFT JOIN-a na organizer_profiles, którego
            // zapytanie już miało dla organizer_verified.
            'organizerSlug'       => $row['organizer_slug'] ?? null,
            'organizerVerified'   => (bool) $row['organizer_verified'],
            // ŚLAD Z KAFLI PRAWDZIWEJ MAPY (2026-09-05, poprawione po
            // odrzuceniu abstrakcyjnego wykresu przez usera: "myślałem że
            // bedzie to wyglądało jak tiles z mapy") — złożony obrazek
            // (Controllers\TileController::routeOfDayMap) z prawdziwych kafli
            // OSM plus pól tej trasy plus jej śladu. `hexTrailAxialForGpx()`
            // tu tylko SPRAWDZA, czy w ogóle jest co narysować (te same pola
            // siatki odkryć co Models\RoutePreview::cellsForGpx() liczy dla
            // "co mi to da" na stronie wydarzenia) — samo rysowanie dzieje się
            // leniwie, przy pierwszym wejściu na adres obrazka. Pusty wynik,
            // gdy etap nie ma jeszcze pliku GPX — widok spada wtedy na wykres
            // profilu wysokości niżej.
            'mapImageUrl'  => self::hexTrailAxialForGpx($row['gpx_url'] ?? null)
                ? self::rotdMapUrl('ev-' . (int) $row['event_id'])
                : null,
            'svgAreaPath'  => Gpx::toSvgAreaPath($profile, self::ROTD_VIEW_W, self::ROTD_VIEW_H),
            'svgLinePath'  => Gpx::toSvgPath($profile, self::ROTD_VIEW_W, self::ROTD_VIEW_H),
            'viewW'        => self::ROTD_VIEW_W,
            'viewH'        => self::ROTD_VIEW_H,
        ];
    }

    // Bliźniak buildRouteOfDayFromEvent(), źródło KnownRoute::routeOfDay().
    // Pola bez odpowiednika w katalogu tras (termin, organizator, rodzaj
    // roweru) dostają neutralne wartości zamiast znikać z tablicy — widok
    // odróżnia je jednym polem `type`, nie sprawdza istnienia każdego klucza.
    private static function buildRouteOfDayFromKnownRoute(): ?array
    {
        $row = KnownRoute::routeOfDay();
        if ($row === null) {
            return null;
        }

        $axial = KnownRoute::axialCellsInOrder((int) $row['id']);

        return [
            'type'         => 'route',
            'slug'         => $row['slug'],
            'title'        => $row['name'],
            'description'  => self::excerpt($row['description'] ?? null),
            'startDate'    => null,
            'coverPhotoUrl' => $row['cover_photo_url'] ?? null,
            'durationDays'  => 1,
            'regionName'   => $row['region_label'],
            'bikeTypeNames' => null,
            'distanceKm'   => round((float) $row['distance_km'], 1),
            'elevationGainM' => (int) $row['elevation_gain_m'],
            'organizerName'      => null,
            'organizerAvatarUrl' => null,
            'organizerSlug'      => null,
            'organizerVerified'  => false,
            'mapImageUrl'  => $axial ? self::rotdMapUrl('kr-' . (int) $row['id']) : null,
            'svgAreaPath'  => '',
            'svgLinePath'  => '',
            'viewW'        => self::ROTD_VIEW_W,
            'viewH'        => self::ROTD_VIEW_H,
        ];
    }

    // Pola siatki odkryć dla PLIKU GPX etapu — puste, gdy etap nie ma jeszcze
    // pliku (rzadkie: firstUpcomingWithElevationProfile() wymaga profilu, ale
    // profil i plik to dwie różne kolumny) albo plik zniknął z dysku.
    //
    // @return list<array{q:int,r:int}>
    private static function hexTrailAxialForGpx(?string $gpxUrl): array
    {
        if ($gpxUrl === null || $gpxUrl === '') {
            return [];
        }
        $path = TileSource::absolutePath($gpxUrl);
        if (!is_file($path)) {
            return [];
        }

        $axial = [];
        foreach (RoutePreview::cellsForGpx($path) as $cellId) {
            [, $q, $r] = DiscoveryGrid::decode($cellId);
            $axial[] = ['q' => $q, 'r' => $r];
        }
        return $axial;
    }

    // Adres złożonego obrazka mapy dla klucz Controllers\TileSource (`ev-{id}`/
    // `kr-{id}`) — data w adresie jest CELOWO tylko cache-busterem, nie
    // parametrem sprawdzanym przy generowaniu: byle dzisiejszy plik jeszcze
    // nie istniał, TileController::routeOfDayMap i tak go narysuje na nowo.
    private static function rotdMapUrl(string $key): string
    {
        return View::url("/assets/tiles/rotd/$key/" . date('Ymd') . '.png');
    }

    // Skrót opisu pod kartą "Trasa dnia" — cięcie na granicy słowa, ta sama
    // idea co $recapQuote w home.php (osobna kopia, patrz stała wyżej).
    private static function excerpt(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= self::ROTD_DESCRIPTION_MAX) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::ROTD_DESCRIPTION_MAX);
        $lastSpace = strrpos($cut, ' ');
        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return $cut . '…';
    }
}
