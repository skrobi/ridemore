<?php
// core/Controllers/TrailController.php
// ZNANE TRASY jako byt publiczny — /trasy i /trasy/{slug}.
//
// Do 2026-08-13 `known_routes` miało wyłącznie panel admina: karty tras na
// /odkrycia i na profilu rowerzysty pokazywały procent ukończenia i nie dawało
// się w trasę wejść. Trasa jest tymczasem jedynym bytem tego serwisu, który
// istnieje niezależnie od kalendarza — wydarzenie mija, Velo Czorsztyn zostaje.
// To czyni ją naturalną stroną docelową dla wyszukiwania („Velo Czorsztyn na
// rowerze") i naturalnym miejscem, do którego linkuje cała reszta modułu.
//
// Kontroler jest ŚWIADOMIE osobny od Controllers\Admin\KnownRouteController:
// tamten zarządza katalogiem (wgrywanie GPX, włączanie, kasowanie) i wymaga
// admina, ten tylko czyta i jest publiczny. Wspólny kontroler musiałby
// rozstrzygać uprawnienia przy każdej akcji.
namespace Controllers;

use Core\Auth;
use Models\KnownRoute;
use Models\MapLayer;
use Models\TileCache;
use Models\TileSource;
use Utils\JsonLd;
use Utils\View;

class TrailController
{
    /** Katalog tras — /trasy. */
    public static function index(): void
    {
        $viewer = Auth::user();

        // Zalogowany widzi swój postęp przy każdej trasie; gość samą listę.
        // progressForUser() liczy to jednym zapytaniem dla wszystkich tras,
        // więc nie ma powodu robić dwóch ścieżek kodu.
        $routes = $viewer
            ? KnownRoute::progressForUser($viewer->id)
            : array_map(static function (array $r): array {
                $r['matched'] = 0;
                $r['pct'] = 0;
                $r['isComplete'] = false;
                return $r;
            }, KnownRoute::all());

        View::render('web', 'trails', [
            'title'       => __('Znane trasy rowerowe — ridemore.bike'),
            'description' => __('Szlaki, które zaliczasz samym jeżdżeniem: Velo, Green Velo, trasy zawodów i lokalne klasyki. Postęp liczy się sam z Twoich przejazdów.'),
            'routes'      => $routes,
            'isLoggedIn'  => $viewer !== null,
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Znane trasy')]],
        ]);
    }

    /** Jedna trasa — /trasy/{slug}. */
    public static function show(string $slug): void
    {
        $route = KnownRoute::findBySlug($slug);
        if (!$route) {
            http_response_code(404);
            View::render('web', 'trail', [
                'title'   => __('Nie znaleziono trasy — ridemore.bike'),
                'noindex' => true,
                'route'   => null,
                'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Znane trasy'), 'url' => View::url('/trasy')]],
            ]);
            return;
        }

        // Nazwa i opis trasy w języku strony (wielojęzyczność, 2026-09-16).
        $trTrasa = \Models\ContentTranslation::fields(['name' => $route['name'], 'description' => $route['description'] ?? null], 'route:' . (int) $route['id']);
        $route['name'] = $trTrasa['name'];
        $route['description'] = $trTrasa['description'];
        $route['translation'] = \Models\ContentTranslation::meta('route:' . (int) $route['id']);

        $viewer = Auth::user();
        $progress = $viewer
            ? KnownRoute::progressForUserOnRoute($viewer->id, (int) $route['id'])
            : null;

        // PUNKTY ZA TRASĘ — dwie liczby z jednej drabinki (zgłoszenie usera
        // 2026-08-20: „ile punktów do zdobycia, a jeśli zdobyto, to ile
        // procent"). `trailAwards(100, ...)` daje wszystkie progi, ta sama
        // funkcja przy AKTUALNYM procencie daje te już opłacone — czyli
        // dokładnie to, co siedzi w `point_transactions`, bo `syncProgress()`
        // liczy ledger tą samą metodą. Osobne zapytanie do rejestru byłoby
        // drugim źródłem prawdy o tej samej rzeczy.
        //
        // BONUS WYŁĄCZONY = PUSTA DRABINKA. Trasa z `bonus_enabled = 0` nie
        // wchodzi do gry w `syncProgress()`, więc obiecywanie na jej stronie
        // punktów było obietnicą bez pokrycia (usterka sprzed tej zmiany).
        // Wartość trasy: nadpisanie z panelu, a w jego braku sugestia z
        // długości — nie płaska wartość domyślna (patrz uzasadnienie przy
        // DiscoveryScoring::trailValueFor()). Ta sama reguła, co w
        // KnownRoute::syncProgress(), więc strona trasy i naliczanie
        // pokazują dokładnie to samo.
        $override = \Models\DiscoveryScoring::legacyRouteOverrideTotal(
            $route['bonus_points'] !== null ? (int) $route['bonus_points'] : null,
            $route['completion_bonus'] !== null ? (int) $route['completion_bonus'] : null
        );
        $trailValue = \Models\DiscoveryScoring::trailValueFor((float) ($route['distance_km'] ?? 0), $override);
        $awards = static fn(float $pct): array => \Models\DiscoveryScoring::trailAwards($pct, $trailValue['total']);
        $thresholds = (int) $route['bonus_enabled'] === 1 ? $awards(100) : [];
        $earned = $thresholds && $progress !== null
            ? array_sum($awards((float) $progress['pct']))
            : null;

        // DRZEWO WARSTW (Etap 2, tasks/done/warstwy-mapy.md). Strona trasy
        // NIE JEST mapą odkryć — jest o JEDNEJ konkretnej trasie, więc
        // `only` zdejmuje „Ślady" (nigdy tu nie miały sensu: to nie strona
        // o niczyich przejechanych trasach) i pomija słownikowe `slug`
        // (kontekst tras nie ma osoby, więc `tileKeysFor` po prostu nie
        // dostanie węzła `slady`, dla którego mógłby go użyć).
        $mapLayers = MapLayer::tree($viewer !== null ? 'me' : 'all', [
            'loggedIn' => $viewer !== null,
            'only'     => ['cells', 'heat', 'trails', 'treasures'],
        ]);
        // MGŁA JEST TU DODATKIEM, NIE TEMATEM STRONY (patrz nota niżej przy
        // `mapScope`) — inny domyślny stan i inne brzmienie niż na /odkrycia,
        // gdzie mgła JEST stroną. To wiedza o UKŁADZIE tej konkretnej strony,
        // nie o warstwie „Odkrycia" w ogóle, więc patchujemy tu, a nie w
        // słowniku (który tej samej warstwy używa też na /odkrycia).
        //
        // „ZNANE TRASY" TO TU JEDNA TRASA — TA, O KTÓREJ JEST STRONA
        // (2026-09-10, prośba usera: „wchodząc na /trasy/{slug} chciałbym
        // widzieć tylko ją, nie potrzebuję widzieć innych"). Do tej daty
        // warstwa ciągnęła klucz `kr`, czyli CAŁY katalog, a przebieg tej
        // jednej trasy szedł osobną, zawsze zapaloną warstwą `kr-{id}`
        // („bohater"). Skutki były dwa: w terenie z kilkoma szlakami strona
        // o jednym z nich pokazywała plątaninę wszystkich, a checkbox
        // „Znane trasy" wyglądał na zepsuty — odznaczenie go nie gasiło
        // linii, bo rysował ją drugi, niezależny kafel.
        //
        // Teraz warstwa NIESIE klucz `kr-{id}` i jest jedynym rysującym tę
        // trasę bytem: jeden checkbox, jedna linia, przewidywalnie. Sąsiednie
        // szlaki (te, które się z tą krzyżują albo biegną obok) zeszły z mapy
        // do sekcji „W okolicy tej trasy" pod stroną — patrz `KnownRoute::
        // nearby()` niżej. To wiedza o UKŁADZIE tej strony, nie o warstwie
        // „Znane trasy" w ogóle, więc patchujemy tu, a nie w słowniku
        // (na /odkrycia i na profilu ta sama warstwa dalej znaczy katalog).
        //
        // Przy okazji odpada patch „TRASY ZOSTAJE KATALOGIEM" z Etapu 3:
        // słownikowe `source` (`subject-done` w kontekście `me`) i tak nie ma
        // tu nic do rozstrzygania, skoro klucz kafla podajemy wprost.
        foreach ($mapLayers as &$layer) {
            if ($layer['key'] === 'cells') {
                $layer['on'] = $viewer !== null;
                $layer['hint'] = $viewer !== null ? __('gdzie już byłeś') : __('gdzie jeżdżą inni');
            } elseif ($layer['key'] === 'trails') {
                $layer['trackKey'] = 'kr-' . $route['id'];
                $layer['on'] = true;
                $layer['hint'] = __('tylko ta trasa');
                // Dzieci „Ukończone"/„Nieukończone" (migr. 078) budują się
                // z kontekstu 'me' RAZEM z resztą drzewa, zanim ten patch
                // zdąży zabrać głos — bez wyczyszczenia tu strona jednej
                // trasy dostałaby dwa dodatkowe przełączniki o postępie
                // widza na INNYCH trasach, których tu nikt nie pytał.
                $layer['children'] = [];
            }
        }
        unset($layer);
        $mapSources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $mapSources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }

        View::render('web', 'trail', [
            'title'       => __('{nazwa} — trasa rowerowa | ridemore.bike', ['nazwa' => $route['name']]),
            'description' => self::metaDescription($route),
            'route'       => $route,
            'progress'    => $progress,
            'finishers'   => KnownRoute::finishersFor((int) $route['id']),
            'thresholds'  => $thresholds,
            'pointsEarned' => $earned,
            // Skarby leżące na polach trasy — ta sama reguła, którą zaliczanie
            // ze śladu stosuje naprawdę (Treasure::claimAlongTrack).
            'treasures'   => \Models\Treasure::onRoute((int) $route['id'], $viewer?->id),
            // LISTA tych skarbów, nie tylko licznik (prośba usera 2026-08-23:
            // „chciałbym zobaczyć, jakie skarby — w domyśle atrakcje — zobaczę
            // na trasie"). Poziomy ujawnienia obcina model, ten sam `reveal()`
            // co na mapie — publiczna strona trasy nie może być obejściem
            // zagadki („wejdź, przeczytaj listę, jedź prosto pod punkty").
            'treasureList' => \Models\Treasure::listOnRoute((int) $route['id'], $viewer?->id),
            // Profil wysokości (migr. 065) + szczyty liczone z niego na żądanie.
            // Ten sam kształt danych, który dostaje etap wydarzenia, więc widok
            // rysuje go tym samym `ridemoreRenderElevationChart`.
            'elevation'   => KnownRoute::elevationProfile($route['elevation_profile'] ?? null),
            // START TRASY jako punkt, do którego da się dojechać nawigacją
            // (2026-09-10). `known_routes` nie ma kolumny ze współrzędnymi —
            // ma je natomiast profil wysokości (`Utils\Gpx::sampleProfile`
            // zapisuje lat/lon przy każdej próbce), więc pierwsza próbka JEST
            // startem i nie kosztuje ani jednego dodatkowego zapytania.
            // `null` dla tras sprzed backfillu profili — partial przycisku
            // sam wtedy nic nie wyrenderuje.
            'startPoint'  => self::startPoint($route['elevation_profile'] ?? null),
            // NAWIERZCHNIA (migr. 086) — ten sam kształt tablicy, co
            // `EventResource`/`StageResource` oddają pod kluczem
            // `surfaceBreakdown`, bo rysuje to ten sam partial
            // (`partials/surface-breakdown.php`). null = nie wykryto; widok
            // wtedy nic nie rysuje, zamiast pokazywać pasek z samych zer.
            'surface'     => self::surfaceBreakdown($route),
            // EMBLEMAT ZA CAŁOŚĆ (migr. 087) — zapowiedź nad sekcją skarbów.
            // `mine` liczy się dla PYTAJĄCEGO, więc kto go już ma, widzi
            // potwierdzenie zamiast zachęty; gość dostaje samą zapowiedź.
            'emblem'      => \Models\Emblem::forRoute((int) $route['id'], $viewer?->id),
            'isLoggedIn'  => $viewer !== null,
            // MAPA: STANDARDOWA KONTROLKA Z WARSTWAMI (2026-08-19, prośba
            // usera). Do tej daty strona trasy miała własną, gołą mapę —
            // sam ślad z pliku, bez warstw, bez legendy, bez skarbów. Była
            // jedynym ekranem z mapą, który nie wyglądał i nie zachowywał się
            // jak reszta serwisu; przebieg trasy dalej rysuje się z pliku
            // (dokładniejszy niż środki pól), tylko już NA mapie odkryć.
            //
            // Zakres pól zależy od widza: zalogowany widzi SWOJE odkrycia
            // (czyli od razu, ile z tej trasy ma zaliczone), gość — wspólną
            // mapę społeczności, dokładnie jak na publicznym /odkrycia.
            'mapScope'    => $viewer !== null ? 'me' : 'all',
            // Adresy API składa kontroler, nigdy widok — tak samo jak na
            // profilu rowerzysty.
            'mapEndpoints' => [
                'cells'     => View::url('/api/discovery/cells'),
                // Klik w szlak: kafel jest obrazkiem, więc trafienie liczy serwer.
                // `route` ZAWĘŻA ODPOWIEDŹ DO TEJ JEDNEJ TRASY — bez tego
                // kliknięcie w mapę otwierało dymek o szlakach, których na tej
                // stronie już nie widać (warstwa niesie tylko `kr-{id}`), czyli
                // odpowiadało o czymś, czego nikt tu nie narysował.
                'trailsAt'  => View::url('/api/discovery/trails/at') . '?route=' . (int) $route['id'],
                'treasures' => View::url('/api/treasures'),
                'claim'     => View::url('/api/treasures/claim'),
                'confirm'   => View::url('/api/treasures/confirm'),
            ],
            'mapLayers'   => $mapLayers,
            'mapSources'  => $mapSources,
            'mapFilters'  => MapLayer::filtersFor($mapLayers),
            // Kadr liczony NA SERWERZE z pól trasy — inaczej mapa pytałaby
            // o pola dla całej Polski, zanim doczyta plik, i przeskakiwała.
            'mapBounds'   => KnownRoute::boundsFor((int) $route['id']),
            // W OKOLICY TEJ TRASY — to, co zeszło z mapy razem z zawężeniem
            // warstwy „Znane trasy" do tej jednej (2026-09-10, patrz nota przy
            // `$mapLayers` wyżej). Lista niesie więcej niż tło pod mapą: nazwę,
            // dystans i to, czy trasa się z tą krzyżuje, czy tylko biegnie obok.
            'nearby'      => KnownRoute::nearby((int) $route['id'], $viewer?->id),
            // Leaflet + discovery-map.js (warstwy, kafle) — bez leaflet-gpx:
            // przebieg trasy rysuje się WYŁĄCZNIE kaflami, jak wszędzie indziej.
            // Ładowane tylko wtedy, gdy trasa ma co narysować.
            'extraHead'   => $route['gpx_url']
                ? Support::leafletMapHead() . "\n"
                    . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>'
                : '',
            'jsonLd'      => JsonLd::forNode(self::structuredData($route)),
            'breadcrumbs' => [
                Support::homeCrumb(),
                ['label' => __('Znane trasy'), 'url' => View::url('/trasy')],
                ['label' => $route['name']],
            ],
        ]);
    }

    /**
     * Pierwszy punkt trasy jako ['lat' => ..., 'lon' => ...] albo null.
     *
     * Źródłem jest `known_routes.elevation_profile` (migr. 065) — ta sama
     * kolumna, z której `Utils\RouteCells::fromElevationProfile` odtwarza
     * przebieg trasy w polach siatki. Wiersze sprzed backfillu profili nie
     * mają w próbkach lat/lon (patrz nota w RouteCells) i dostają `null`,
     * zamiast zgadywanego punktu.
     */
    /**
     * Podział nawierzchni trasy w kształcie, którego oczekuje wspólny partial
     * (procenty + kilometry), albo `null`, gdy detekcja się nie powiodła.
     *
     * KILOMETRY LICZYMY TU, NIE TRZYMAMY W BAZIE — to prosty iloczyn długości
     * i procentu, a druga kolumna na wyliczalną wartość mogłaby rozjechać się
     * z pierwszą po podmianie pliku. Dokładnie tak samo robi to strona
     * wydarzenia dla wariantu trasy (event-page.php, `$vBreakdown`).
     */
    private static function surfaceBreakdown(array $route): ?array
    {
        if ($route['surface_asphalt_pct'] === null) {
            return null;
        }
        $km = (float) $route['distance_km'];
        $asfalt  = (int) $route['surface_asphalt_pct'];
        $gravel  = (int) $route['surface_gravel_pct'];
        $sciezka = (int) $route['surface_trail_pct'];

        return [
            'asphaltPct' => $asfalt,
            'gravelPct'  => $gravel,
            'trailPct'   => $sciezka,
            'asphaltKm'  => round($km * $asfalt / 100, 1),
            'gravelKm'   => round($km * $gravel / 100, 1),
            'trailKm'    => round($km * $sciezka / 100, 1),
        ];
    }

    private static function startPoint(?string $profileJson): ?array
    {
        if ($profileJson === null || $profileJson === '') {
            return null;
        }
        $profile = json_decode($profileJson, true);
        if (!is_array($profile) || !isset($profile[0]['lat'], $profile[0]['lon'])) {
            return null;
        }
        return ['lat' => (float) $profile[0]['lat'], 'lon' => (float) $profile[0]['lon']];
    }

    private static function metaDescription(array $route): string
    {
        $bits = [];
        if ((float) $route['distance_km'] > 0) {
            $bits[] = \Utils\Format::distance((float) $route['distance_km']);
        }
        if (!empty($route['region_label'])) {
            $bits[] = $route['region_label'];
        }
        $head = $route['name'] . ($bits ? ' — ' . implode(' · ', $bits) : '');
        $tail = trim((string) ($route['description'] ?? ''));
        return $tail !== '' ? $head . '. ' . mb_substr($tail, 0, 150) : $head . '.';
    }

    /**
     * Dane strukturalne. Typ `Place`, nie `Event` — trasa nie ma daty ani
     * organizatora; to miejsce, w którym można być kiedykolwiek. Wyszukiwarki
     * traktują je zupełnie inaczej i podpięcie tu `Event` byłoby fałszywką.
     */
    private static function structuredData(array $route): array
    {
        // Bez '@context' — dokłada go JsonLd::forNode() na poziomie dokumentu.
        $data = [
            '@type'    => 'Place',
            'name'     => $route['name'],
            'url'      => View::absoluteUrl('/trasy/' . $route['slug']),
        ];
        if (!empty($route['description'])) {
            $data['description'] = $route['description'];
        }
        if (!empty($route['cover_photo_url'])) {
            $data['image'] = View::absoluteUrl($route['cover_photo_url']);
        }
        if (!empty($route['region_label'])) {
            $data['containedInPlace'] = ['@type' => 'Place', 'name' => $route['region_label']];
        }
        return $data;
    }
}
