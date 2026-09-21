<?php
// core/Controllers/RideController.php
// JEDEN PRZEJAZD JAKO STRONA — /przejazd/{id} (2026-09-03,
// tasks/done/strona-przejazdu.md).
//
// Do tej daty przejazd nie miał ŻADNEGO ekranu: był wierszem w tabeli
// /admin/moje-przejazdy, kreską na mapie i pozycją w feedzie. Dymek na mapie
// prowadził do listy zawężonej po dacie, bo nie było dokąd prowadzić.
//
// Kontroler jest ŚWIADOMIE osobny od SoloRideController: tamten WGRYWA i
// zmienia przejazdy solo (wszystko za `Auth::requireLogin()` pod /admin), ten
// tylko czyta i jest publiczny — dokładnie ta sama para co
// Admin\KnownRouteController i TrailController przy trasach.
//
// KTO CO WIDZI (§27, plik solo zaczyna się pod domem):
//   · właściciel                  — pełny ślad, oryginalny plik do pobrania,
//   · obcy, właściciel publiczny  — ślad PRZYCIĘTY o okolice domu, plik złożony
//                                   z tej samej przyciętej geometrii,
//   · obcy, właściciel ukryty     — 404 (nie 403: odpowiedź nie zdradza, że coś
//                                   tu jest — jak przy ukrytym profilu).
// Regułę liczy Support (`rideGpxPath` + `strangerTrackPath`), a nie ten plik,
// bo tę samą odpowiedź musi dać endpoint `/api/rides/{id}/track`.
namespace Controllers;

use Core\Auth;
use Models\Event;
use Models\EventPhoto;
use Models\GpxGeometry;
use Models\KnownRoute;
use Models\MapLayer;
use Models\PointLedger;
use Models\RiderActivity;
use Models\TileCache;
use Models\TileSource;
use Models\Treasure;
use Utils\Format;
use Utils\Gpx;
use Utils\Image;
use Utils\TileGrid;
use Utils\TrackPalette;
use Utils\View;

class RideController
{
    /** Strona przejazdu — /przejazd/{id}. */
    public static function show(string $id): void
    {
        $ride = RiderActivity::findForPage((int) $id);
        $viewer = Auth::user();
        $isOwner = $ride !== null && $viewer !== null && (int) $ride['user_id'] === $viewer->id;

        // Obcemu pokazujemy przejazd wyłącznie osoby, której profil jest
        // publiczny — ta sama bramka, którą przechodzi /rowerzysta/{slug},
        // żeby strona przejazdu nie była obejściem czyjegoś ukrycia z list.
        if ($ride === null || (!$isOwner && Support::visibleRiderById((int) $ride['user_id']) === null)) {
            self::notFound();
            return;
        }

        // ŚLAD: pełny albo przycięty — patrz nota nad klasą. `rideGpxPath`
        // oddaje ślad wyjazdu każdemu (turnus zaczyna się na zbiórce), a plik
        // solo tylko właścicielowi; dopiero gdy odmówi, pytamy o wariant dla
        // obcego. Adres dla przeglądarki jest w OBU przypadkach ten sam —
        // endpoint geometrii rozstrzyga to jeszcze raz u siebie.
        $fullPath = Support::rideGpxPath($ride, $viewer?->id);
        $trimmedPath = $fullPath === null ? Support::strangerTrackPath($ride) : null;
        $absolute = ($fullPath ?? $trimmedPath) !== null
            ? TileSource::absolutePath((string) ($fullPath ?? $trimmedPath))
            : null;

        // PLIKU MOŻE NIE BYĆ NA DYSKU, mimo że wiersz go wskazuje (skasowany
        // ślad wyjazdu — znalezione na żywo w danych dev). Bez tego sprawdzenia
        // strona obiecywałaby pobranie pliku, którego nie ma, i pokazywała
        // pustą mapę bez ani jednego komunikatu. Jeden `is_file` na wizytę.
        $hasTrack = $absolute !== null && is_file($absolute);

        $activityId = (int) $ride['id'];
        $solo = ($ride['source_code'] ?? '') === RiderActivity::SOURCE_SOLO;

        // KADR Z SERWERA, nie z wczytanego śladu (ta sama zasada co na profilu
        // rowerzysty): mapa ma znać granice, ZANIM zapyta o pola, inaczej
        // pierwsze żądanie leci dla środka Polski i obraz przeskakuje.
        // Obcy dostaje kadr z geometrii PRZYCIĘTEJ — inaczej sam prostokąt
        // wskazywałby dom, mimo że linii tam już nie ma.
        //
        // HASH LICZY SIĘ Z PLIKU, nie z kolumny `rider_activities.gpx_hash`:
        // przejazd Z WYJAZDU ma tam NULL (jego ślad mieszka w `edition_tracks`),
        // więc kadr brany z kolumny byłby pusty dokładnie dla tych przejazdów
        // — znalezione na żywo. `ensure()`/`ensureTrimmed()` są kluczowane
        // hashem pliku i cache'owane, a i tak zawoła je za chwilę endpoint
        // geometrii, więc to nie jest dodatkowa praca, tylko ta sama wcześniej.
        $bounds = null;
        if ($hasTrack) {
            if ($trimmedPath !== null) {
                $h = GpxGeometry::ensureTrimmed($absolute);
                $bounds = $h !== null ? GpxGeometry::boundsForTrimmed([$h]) : null;
            } else {
                $h = GpxGeometry::ensure($absolute);
                $bounds = $h !== null ? GpxGeometry::boundsFor([$h]) : null;
            }
        }

        // PROFIL WYSOKOŚCI TYLKO DLA WŁAŚCICIELA, i to jest wymuszone danymi,
        // nie decyzją: profil liczy się z PLIKU (`Gpx::parse`), a przycięta
        // geometria nie niesie wysokości — obcy nie ma z czego go zbudować.
        $elevation = null;
        $peaks = [];
        // DODATKOWE KANAŁY Z LICZNIKA (2026-09-11) — tętno, kadencja, moc,
        // temperatura. Ta sama bramka i to samo źródło co profil wysokości:
        // jedno parsowanie pliku daje oba, a obcy nie dostaje ani jednego, bo
        // przycięta geometria w ogóle nie niesie rozszerzeń.
        $metricChannels = [];
        if ($hasTrack && $fullPath !== null) {
            try {
                $parsed = Gpx::parse($absolute, Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
                $elevation = $parsed['elevationProfile'] ?: null;
                $peaks = $elevation ? Gpx::detectPeaks($elevation) : [];
                $metricChannels = $parsed['metricChannels'] ?? [];
            } catch (\Throwable $e) {
                // Plik nieczytelny albo skasowany — strona ma się otworzyć bez
                // wykresu, a nie wywalić. Reszta liczb siedzi w bazie.
                $elevation = null;
                $metricChannels = [];
            }
        }

        // WARSTWY MAPY jak na stronie trasy: to jest strona o JEDNYM śladzie,
        // więc warstwa „Ślady" (wszystkie przejazdy) nie ma tu czego szukać,
        // a „Znane trasy" zostaje katalogiem dookoła, nie postępem widza.
        $mapLayers = MapLayer::tree($viewer !== null ? 'me' : 'all', [
            'loggedIn' => $viewer !== null,
            'only'     => ['cells', 'heat', 'trails', 'treasures'],
        ]);
        foreach ($mapLayers as &$layer) {
            if ($layer['key'] === 'trails') {
                $layer['trackKey'] = 'kr';
                $layer['children'] = [];
            }
        }
        unset($layer);
        $mapSources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $mapSources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }

        $nazwa = self::rideName($ride);

        View::render('web', 'ride', [
            'title'       => $nazwa . ' — ' . (Format::dateP($ride['ride_date']) ?? __('przejazd')) . ' | ridemore.bike',
            'description' => self::metaDescription($ride, $nazwa),
            // Przejazd jest CZYJŚ i opisuje, gdzie ktoś był — wyszukiwarkom
            // nic do tego. Ta sama zasada, dla której nie ma go w sitemapie.
            'noindex'     => true,
            'ride'        => $ride,
            'rideName'    => $nazwa,
            'isSolo'      => $solo,
            'isOwner'     => $isOwner,
            'isLoggedIn'  => $viewer !== null,
            'rideColor'   => TrackPalette::colorOf($ride['color_index']),
            // Ślad idzie tym samym endpointem, co podświetlenie na każdej innej
            // mapie — jedna droga do geometrii, a nie druga tylko dla tej strony.
            'trackUrl'    => $hasTrack ? View::url('/api/rides/' . $activityId . '/track') : null,
            'isTrimmed'   => $trimmedPath !== null,
            'downloadUrl' => $hasTrack ? View::url('/przejazd/' . $activityId . '/gpx') : null,
            'elevation'   => $elevation,
            'peaks'       => $peaks,
            'metricChannels' => $metricChannels,
            'points'      => PointLedger::forActivity($activityId),
            'regions'     => RiderActivity::regionsFor($activityId),
            'treasures'   => Treasure::onActivity($activityId, $viewer?->id),
            'treasureList' => Treasure::listOnActivity($activityId, $viewer?->id),
            'routes'      => KnownRoute::onActivity($activityId),
            // ZDJĘCIA SĄ TU TYLKO TE, KTÓRE JUŻ ISTNIEJĄ. Przejazd nie ma
            // własnej galerii (nie ma takiej tabeli i nie dokładamy jej), więc
            // pokazujemy zdjęcia WYDARZENIA, z którego ten ślad pochodzi —
            // przy przejeździe solo po prostu nie ma czego pokazać, bo solo
            // z definicji nie jest z niczyjego wyjazdu.
            // ZDJĘCIA, KTÓRE NAPRAWDĘ SĄ NA DYSKU — `Image::exists()` (2026-09-03).
            // Sam wiersz w `event_photos` nie znaczy, że plik istnieje; bez tego
            // filtra galeria pokazywała rządek ikon zepsutych obrazków zamiast
            // po prostu się nie pojawić.
            'eventPhotos' => !empty($ride['event_id'])
                ? array_slice(array_values(array_filter(
                    EventPhoto::forEvent((int) $ride['event_id']),
                    static fn(string $url): bool => Image::exists($url)
                )), 0, 8)
                : [],
            // KARTA WYJAZDU TYM SAMYM KOMPONENTEM CO WSZĘDZIE INDZIEJ
            // (`partials/event-card.php` + `EventCardResource`): okładka, daty,
            // region, typ i stan zapisu widza. Własny akapit z tytułem
            // i datą byłby dwunastym miejscem, które opisuje wyjazd po swojemu.
            'eventCard'   => !empty($ride['edition_id'])
                ? (Event::cardsForEditions([(int) $ride['edition_id']])[(int) $ride['edition_id']] ?? null)
                : null,
            // „Inne przejazdy tędy" WYŁĄCZNIE dla właściciela: obcemu nie
            // pokazujemy, gdzie ta osoba jeszcze bywa (§27).
            'otherRides'  => $isOwner
                ? RiderActivity::otherRidesAlong($activityId, (int) $ride['user_id'])
                : [],
            'mapLayers'   => $mapLayers,
            'mapSources'  => $mapSources,
            'mapFilters'  => MapLayer::filtersFor($mapLayers),
            'mapBounds'   => $bounds,
            'mapScope'    => $viewer !== null ? 'me' : 'all',
            'mapEndpoints' => [
                'cells'     => View::url('/api/discovery/cells'),
                'trailsAt'  => View::url('/api/discovery/trails/at'),
                'treasures' => View::url('/api/treasures'),
                'claim'     => View::url('/api/treasures/claim'),
                'confirm'   => View::url('/api/treasures/confirm'),
            ],
            'extraHead'   => $hasTrack
                ? Support::gpxMapHead() . "\n"
                    . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>'
                : '',
            'breadcrumbs' => [
                Support::homeCrumb(),
                ['label' => $ride['user_name'] ?: __('Rowerzysta'),
                 'url'   => $ride['user_slug'] ? View::url('/rowerzysta/' . $ride['user_slug']) : null],
                ['label' => $nazwa],
            ],
        ]);
    }

    /**
     * POBRANIE ŚLADU — /przejazd/{id}/gpx.
     *
     * Właściciel (i każdy, kto ogląda ślad wyjazdu) dostaje ORYGINALNY plik:
     * z wysokościami, znacznikami czasu i wszystkim, co zapisał licznik.
     * Obcy przy przejeździe solo dostaje plik ZŁOŻONY z przyciętej geometrii —
     * same współrzędne, bez okolic domu. To nie jest ta sama rzecz i strona
     * mówi o tym wprost przy przycisku, żeby nikt nie odkrył tego dopiero
     * w nawigacji.
     *
     * Pliku na dysku nie ruszamy nigdy (patrz nota przy `Utils\Gpx`): jest
     * źródłem dystansu przy powiązaniu przejazdu z turnusem.
     */
    public static function gpx(string $id): void
    {
        $ride = RiderActivity::findForPage((int) $id);
        $viewer = Auth::user();
        $isOwner = $ride !== null && $viewer !== null && (int) $ride['user_id'] === $viewer->id;

        if ($ride === null || (!$isOwner && Support::visibleRiderById((int) $ride['user_id']) === null)) {
            self::notFound();
            return;
        }

        $fullPath = Support::rideGpxPath($ride, $viewer?->id);
        if ($fullPath !== null) {
            // Brak pliku na dysku = 404 TUTAJ, a nie przekierowanie na adres,
            // pod którym też go nie ma (ta sama sytuacja, którą `show()`
            // rozpoznaje przez `is_file`).
            if (!is_file(TileSource::absolutePath($fullPath))) {
                self::notFound();
                return;
            }
            // Plik leży pod publicznym adresem w `gpx/` — przekierowanie zamiast
            // przepychania bajtów przez PHP. Nazwę pod dyskiem człowieka nadaje
            // atrybut `download` w linku, bo hash w nazwie nic nikomu nie mówi.
            header('Location: ' . View::url($fullPath));
            return;
        }

        $trimmedPath = Support::strangerTrackPath($ride);
        if ($trimmedPath === null) {
            self::notFound();
            return;
        }

        $hash = GpxGeometry::ensureTrimmed(TileSource::absolutePath($trimmedPath));
        $geom = $hash !== null ? (GpxGeometry::loadTrimmed([$hash])[$hash] ?? null) : null;
        if ($geom === null || !$geom['pts']) {
            self::notFound();
            return;
        }

        $punkty = [];
        $pts = $geom['pts'];
        for ($i = 0, $len = count($pts); $i + 1 < $len; $i += 2) {
            [$lat, $lon] = TileGrid::toLatLon($pts[$i], $pts[$i + 1]);
            $punkty[] = ['lat' => $lat, 'lon' => $lon];
        }

        $nazwa = self::rideName($ride) . ' (' . (string) $ride['ride_date'] . ')';
        header('Content-Type: application/gpx+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="przejazd-' . (int) $ride['id'] . '.gpx"');
        echo Gpx::fromPoints($punkty, $nazwa);
    }

    /**
     * Nazwa przejazdu — ta sama reguła co w dymku na mapie
     * (`RiderActivity::describeForPopup`): własna nazwa (migr. 080), gdy
     * właściciel ją nadał, inaczej nazwa z licznika, gdy jest — bo przy
     * przejazdach solo to jedyne, co odróżnia od siebie wiersze z samych dat.
     *
     * PUBLICZNA: wołana też z `/api/rides/{id}/nazwa` (zmiana nazwy) — jedno
     * miejsce z regułą priorytetu, żeby ekran po zapisie i strona po
     * przeładowaniu zawsze pokazywały to samo.
     */
    public static function rideName(array $ride): string
    {
        if (!empty($ride['name'])) {
            return (string) $ride['name'];
        }
        if (!empty($ride['device_name'])) {
            return (string) $ride['device_name'];
        }
        if (($ride['source_code'] ?? '') === RiderActivity::SOURCE_SOLO) {
            return __('Przejazd solo');
        }
        return $ride['event_title'] ?: __('Przejazd z wyjazdu');
    }

    private static function metaDescription(array $ride, string $nazwa): string
    {
        $bits = [];
        if ((float) $ride['distance_km'] > 0) {
            $bits[] = Format::distance((float) $ride['distance_km']);
        }
        if ((int) $ride['elevation_gain_m'] > 0) {
            $bits[] = __('{n} m w górę', ['n' => (int) $ride['elevation_gain_m']]);
        }
        if ((int) $ride['cells_new'] > 0) {
            $bits[] = __('{n} nowych pól', ['n' => (int) $ride['cells_new']]);
        }

        return $nazwa . ' — ' . (Format::dateP($ride['ride_date']) ?? __('przejazd'))
            . ($bits ? ' · ' . implode(' · ', $bits) : '') . '.';
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('web', 'ride', [
            'title'   => __('Nie znaleziono przejazdu — ridemore.bike'),
            'noindex' => true,
            'ride'    => null,
            'breadcrumbs' => [Support::homeCrumb()],
        ]);
    }
}
