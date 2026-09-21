<?php
// core/Controllers/EventsListController.php
// Pełna, filtrowalna lista wydarzeń (AJAX, mapa, sidebar filtrów) — do
// 2026-07-31 żyła pod '/', przeniesiona pod '/wydarzenia' gdy '/' stało się
// stroną typu landing (patrz Controllers\HomeController).
namespace Controllers;

use Core\Auth;
use Models\Dictionary;
use Models\Event;
use Models\MatchEngine;
use Models\RecommendationLog;
use Resources\EventCardResource;
use Resources\MatchCardResource;
use Utils\JsonLd;
use Utils\View;

class EventsListController
{
    public static function index(): void
    {
        // Brak potwierdzonego harmonogramu cron w tej apce — oportunistyczne
        // wyzwolenie przy realnym ruchu na liście wydarzeń, oba idempotentne
        // (patrz Event::processCompletions()/expireStalePendingPayments()).
        Event::processCompletions();
        Event::expireStalePendingPayments();

        $arr   = fn(string $key) => array_values(array_filter((array) ($_GET[$key] ?? []), 'is_string'));
        $coord = function ($value, float $min, float $max): ?float {
            if (!is_numeric($value)) return null;
            $v = (float) $value;
            return ($v >= $min && $v <= $max) ? $v : null;
        };

        $lat = $coord($_GET['lat'] ?? null, -90, 90);
        $lng = $coord($_GET['lng'] ?? null, -180, 180);
        if ($lat === null || $lng === null) { $lat = null; $lng = null; }

        // Data w formacie YYYY-MM-DD (input[type=date]) — walidacja przez
        // DateTime::createFromFormat, nie regex, żeby odrzucić np. "2026-02-31".
        $isoDate = function ($value): ?string {
            if (!is_string($value) || $value === '') return null;
            $d = \DateTime::createFromFormat('Y-m-d', $value);
            return ($d && $d->format('Y-m-d') === $value) ? $value : null;
        };

        $filters = [
            'eventTypes'      => $arr('eventTypes'),
            'difficulties'    => $arr('difficulties'),
            'paces'           => $arr('paces'),
            'bikeTypes'       => $arr('bikeTypes'),
            'regions'         => $arr('regions'),
            'durationBuckets' => array_values(array_intersect($arr('durationBuckets'), ['1', '2-3', '4plus'])),
            'paid'            => in_array($_GET['paid'] ?? '', ['free', 'paid'], true) ? $_GET['paid'] : null,
            'when'            => in_array($_GET['when'] ?? '', ['weekend', 'month'], true) ? $_GET['when'] : null,
            'maxDistanceKm'   => is_numeric($_GET['distance'] ?? null) ? (float) $_GET['distance'] : null,
            'dateFrom'        => $isoDate($_GET['dateFrom'] ?? null),
            'dateTo'          => $isoDate($_GET['dateTo'] ?? null),
            'maxPriceAmount'  => is_numeric($_GET['maxPrice'] ?? null) ? (float) $_GET['maxPrice'] : null,
            'verifiedOnly'    => ($_GET['verifiedOnly'] ?? '') === '1',
            'hasSpotsOnly'    => ($_GET['hasSpotsOnly'] ?? '') === '1',
            // radiusKm ma sens wyłącznie razem ze znanymi współrzędnymi (lat/lng
            // niżej) — "Blisko mnie"/geolokalizacja, nie zapisane miasto (nie
            // mamy takiego pola), patrz panel filtrów w events-list.php.
            'radiusKm'        => (is_numeric($_GET['radius'] ?? null) && $lat !== null) ? (float) $_GET['radius'] : null,
            'q'               => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'userLat'         => $lat,
            'userLng'         => $lng,
        ];
        $sort  = in_array($_GET['sort'] ?? '', ['spots', 'near', 'route_asc', 'route_desc', 'price_asc', 'newest'], true) ? $_GET['sort'] : 'date';
        $limit = max(1, min(240, (int) ($_GET['limit'] ?? 24) ?: 24));

        // Dwa niezależne wymiary listy — status (nadchodzące/zakończone, chip
        // "Zakończone") i "moje" (link w headerze, wymaga logowania) — dają się
        // dowolnie łączyć, np. "moje zakończone" (patrz Event::forParticipant()).
        $statusView = ($_GET['view'] ?? '') === 'completed' ? 'completed' : 'upcoming';
        $mine = ($_GET['mine'] ?? '') === '1' && Auth::check();
        $viewerId = Auth::check() ? Auth::user()->id : null;

        if ($mine) {
            $result = Event::forParticipant($viewerId, $filters, $limit, 0, $statusView === 'completed' ? 'completed' : 'upcoming');
        } elseif ($statusView === 'completed') {
            $result = Event::completed($filters, $limit, 0, $viewerId);
        } else {
            $result = Event::upcoming($filters, $limit, 0, $sort, $viewerId);
        }
        \Models\ContentTranslation::prefetchRows($result['items']);
        $events = array_map(fn($row) => EventCardResource::fromRow($row), $result['items']);
        $total  = $result['total'];
        $view   = $statusView; // alias — event-results.php oczekuje $view/$mine w tym scope'ie

        // Same nazwy/kody słowników (bez liczb — te są w $filterCounts niżej,
        // policzone tylko dla pełnego renderu) — event-results.php potrzebuje
        // ich już w gałęzi ajax=1, żeby podpisać tagi aktywnych filtrów
        // prawdziwymi nazwami, nie samymi kodami.
        $filterOptions = [
            'eventTypes'   => Dictionary::items('event_type'),
            'difficulties' => Dictionary::items('difficulty_level'),
            'paces'        => Dictionary::items('pace_group'),
            'bikeTypes'    => Dictionary::items('bike_type'),
            'regions'      => Dictionary::groupedLeaves('region'),
        ];

        // event-results.php czyta $events/$total/$limit/$sort/$view/$mine/$filters/
        // $filterOptions bezpośrednio z tego scope'u (te same zmienne, których
        // używa View::render niżej po extract()).
        if (($_GET['ajax'] ?? '') === '1') {
            require CORE_PATH . '/../views/web/partials/event-results.php';
            return;
        }

        // Etap 3 (preferencje) §1 pkt 3 — "odkrywanie bierne": widget na samej
        // górze strony głównej, niezależny od filtrów listy poniżej. Tylko dla
        // zalogowanych z profilem (deklarowanym lub wynikającym) — bez profilu
        // MatchEngine::profileMatchesForUser() i tak zwraca pustkę, patrz
        // Models\MatchEngine::loadProfile(). '2000-01-01' zamiast okna 24h
        // użytego w zadaniu nocnym (Models\PreferenceNotifier) — to widok
        // strony na żądanie, nie powiadomienie o czymś nowym, więc pokazujemy
        // wszystko pasujące, nie tylko świeże publikacje.
        $matchCards = [];
        if ($viewerId !== null) {
            // 6, nie 3: od 2026-08-14 dopasowania są SIATKĄ, a nie widgetem
            // z jednym bohaterem — rząd mieści 4–5 kart przy minmax(230px),
            // więc limit 3 zostawiał wolne miejsce nawet wtedy, gdy silnik miał
            // czym je wypełnić. Ile faktycznie wyjdzie, decyduje i tak próg
            // MIN_MATCH_SCORE w MatchEngine, nie ta liczba.
            $matches = MatchEngine::profileMatchesForUser($viewerId, '2000-01-01 00:00:00', 6);
            foreach ($matches as $i => $m) {
                RecommendationLog::record($viewerId, $m['eventId'], 'list', $m['score'], $i + 1, $m['isExploration'], $m['isAspirational'], $m['derivedShare']);
            }
            $matchCards = MatchCardResource::fromMatches($matches, fn($slug) => View::url('/events/' . $slug));
        }

        // Liczby przy opcjach filtrów — globalne, nie fasetowe (patrz komentarz
        // przy Event::upcomingCountsByDictionaryColumn()). Tylko tu (pełne
        // renderowanie strony), nie w gałęzi ajax=1 wyżej — te liczby nie
        // zmieniają się przy zmianie filtrów, więc nie ma co ich przeliczać
        // przy każdym odświeżeniu wyników.
        $regionCounts = [];
        foreach (Event::upcomingCountsByRegion() as $r) {
            $regionCounts[$r['code']] = (int) $r['event_count'];
        }
        $filterCounts = [
            'eventTypes'      => Event::upcomingCountsByEventType(),
            'difficulties'    => Event::upcomingCountsByDifficulty(),
            'paces'           => Event::upcomingCountsByPace(),
            'bikeTypes'       => Event::upcomingCountsByBikeType(),
            'regions'         => $regionCounts,
            'durationBuckets' => Event::upcomingCountsByDurationBucket(),
            'verifiedOnly'    => Event::upcomingVerifiedOrganizerCount(),
            'hasSpotsOnly'    => Event::upcomingHasSpotsCount(),
        ];

        // ItemList JSON-LD — tylko dla ogólnej, publicznej listy (nie "Moje
        // wydarzenia", to widok osobisty bez wartości dla wyszukiwarki) i tylko
        // gdy jest co pokazać.
        $jsonLd = (!$mine && $events)
            ? JsonLd::forEventList($events, View::currentCanonicalUrl())
            : '';

        View::render('web', 'events-list', [
            'events'        => $events,
            'total'         => $total,
            'matchCards'    => $matchCards,
            'limit'         => $limit,
            'sort'          => $sort,
            'view'          => $statusView,
            'mine'          => $mine,
            'filters'       => $filters,
            'breadcrumbs'   => [['label' => __('Start'), 'url' => View::url('/')], ['label' => __('Wyjazdy')]],
            'jsonLd'        => $jsonLd,
            // Najpierw słowa, które ktoś wpisuje w Google, marka na końcu
            // (SEO, 2026-09-14) — dotąd tytuł zaczynał się od nazwy serwisu,
            // której nikt spoza społeczności jeszcze nie szuka.
            'title'         => __('Wyjazdy rowerowe: gravel, MTB, szosa i wycieczki rowerowe | ridemore.bike'),
            'description'   => 'Wspólne wyjazdy i wycieczki rowerowe w Polsce i na Świecie: gravel, MTB i szosa, rajdy i wyprawy wielodniowe. '
                . $total . ' ' . \Utils\Format::plural((int) $total, 'nadchodzący wyjazd', 'nadchodzące wyjazdy', 'nadchodzących wyjazdów') . ' — dołącz do ludzi, którzy jadą.',
            'extraHead'     => Support::gpxMapHead(),
            'filterCounts'  => $filterCounts,
            'filterOptions' => $filterOptions,
        ]);
    }
}
