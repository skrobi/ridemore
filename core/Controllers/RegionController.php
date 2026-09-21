<?php
// core/Controllers/RegionController.php
// STRONY REGIONÓW — /regiony, /regiony/{kraj}, /regiony/{kraj}/{region}
// (2026-09-14, kontrakt tasks/done/strony-regionow.md).
//
// Po co: filtry regionu na /wydarzenia mają canonical na samo /wydarzenia,
// więc wyszukiwarka nie widziała żadnej strony „o regionie", a to na takie
// hasła („wyjazdy rowerowe podkarpackie") ludzie szukają. Strona regionu
// zbiera to, co serwis już o terenie wie — nie ma tu żadnych nowych danych.
//
// BLIŹNIAK `TrailController` i świadomie ZBUDOWANY JAK ON: ten sam układ
// strony, ta sama mapa z tym samym drzewem warstw, te same karty. Osobny od
// `Admin\RegionMapController`, który rysuje obrysy i wymaga admina — ta sama
// para co `Admin\KnownRouteController` / `TrailController`.
//
// BEZ PROGU INDEKSOWANIA (decyzja usera 2026-09-14): każda aktywna strona
// regionu jest indeksowana, także pusta. Pusta SEKCJA się za to nie renderuje.
namespace Controllers;

use Core\Auth;
use Models\Discovery;
use Models\Event;
use Models\EventPhoto;
use Models\KnownRoute;
use Models\MapLayer;
use Models\Organizer;
use Models\Region;
use Models\RiderActivity;
use Models\TileCache;
use Models\TileSource;
use Models\Treasure;
use Models\TreasurePhoto;
use Resources\EventCardResource;
use Utils\Format;
use Utils\JsonLd;
use Utils\View;

class RegionController
{
    /** Spis — /regiony. */
    public static function index(): void
    {
        $countries = Region::countries();
        View::render('web', 'regions', [
            'title'       => __('Regiony rowerowe — wyjazdy i trasy w Polsce i na Świecie | ridemore.bike'),
            'description' => __('Wyjazdy rowerowe, znane trasy i organizatorzy według regionów — w Polsce i na Świecie. Wybierz region i zobacz, gdzie i z kim pojechać.'),
            'countries'   => $countries,
            'counts'      => Region::contentCounts(),
            'heading'     => __('Regiony'),
            'sub'         => __('Wyjazdy, trasy i ludzie według miejsca, w którym jeździsz'),
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Regiony')]],
            'jsonLd'      => self::breadcrumbFreeList(array_map(
                static fn(array $c): array => ['name' => $c['name'], 'url' => View::absoluteUrl(Region::countryPath($c))],
                $countries
            )),
        ]);
    }

    /** Kraj — /regiony/{kraj}; kraj bez regionów jest od razu stroną regionu. */
    public static function country(string $countryCode): void
    {
        $resolved = Region::resolve($countryCode, null);
        if ($resolved === null) {
            self::notFound();
            return;
        }
        if ($resolved['region'] !== null) {
            self::renderRegion($resolved['country'], $resolved['region']);
            return;
        }
        $country = $resolved['country'];
        $name = Region::displayName($country['name']);
        View::render('web', 'regions', [
            'title'       => __('Wyjazdy rowerowe — {kraj}: regiony | ridemore.bike', ['kraj' => $name]),
            'description' => __n(count($country['regions']), 'Wyjazdy rowerowe, znane trasy i organizatorzy w regionach: {kraj}. {n} region — wybierz, gdzie chcesz pojechać.', 'Wyjazdy rowerowe, znane trasy i organizatorzy w regionach: {kraj}. {n} regiony — wybierz, gdzie chcesz pojechać.', 'Wyjazdy rowerowe, znane trasy i organizatorzy w regionach: {kraj}. {n} regionów — wybierz, gdzie chcesz pojechać.', ['kraj' => $name]),
            'countries'   => [$country],
            'counts'      => Region::contentCounts(),
            'heading'     => $name,
            'sub'         => __('Regiony, w których jeździmy — wybierz, gdzie chcesz pojechać'),
            'breadcrumbs' => [
                Support::homeCrumb(),
                ['label' => __('Regiony'), 'url' => View::url('/regiony')],
                ['label' => $name],
            ],
            'jsonLd'      => self::breadcrumbFreeList(array_map(
                static fn(array $r): array => ['name' => Region::displayName($r['name']), 'url' => View::absoluteUrl('/regiony/' . $country['code'] . '/' . $r['code'])],
                $country['regions']
            )),
        ]);
    }

    /** Region — /regiony/{kraj}/{region}. */
    public static function show(string $countryCode, string $regionCode): void
    {
        $resolved = Region::resolve($countryCode, $regionCode);
        if ($resolved === null) {
            self::notFound();
            return;
        }
        if ($resolved['redirect'] !== null) {
            header('Location: ' . View::url($resolved['redirect']), true, 301);
            exit;
        }
        self::renderRegion($resolved['country'], $resolved['region']);
    }

    private static function renderRegion(array $country, array $region): void
    {
        $viewer = Auth::user();
        $viewerId = $viewer?->id;
        $regionId = (int) $region['id'];
        $isFlat = $country['isFlat'];
        $name = Region::displayName($region['name']);
        $countryName = Region::displayName($country['name']);
        $filters = ['regions' => [$region['code']]];

        $upcoming = Event::upcoming($filters, 6, 0, 'date', $viewerId);
        $completed = Event::completed($filters, 6, 0, $viewerId);
        \Models\ContentTranslation::prefetchRows(array_merge($upcoming['items'], $completed['items']));
        $upcomingCards = array_map(fn($row) => EventCardResource::fromRow($row), $upcoming['items']);
        $completedCards = array_map(fn($row) => EventCardResource::fromRow($row), $completed['items']);

        // Karty tras z TYCH SAMYCH wierszy co katalog /trasy (patrz
        // `TrailController::index`) — zawężone do tras przechodzących przez region.
        $routeIds = array_flip(KnownRoute::idsInRegion($regionId));
        $routes = [];
        if ($routeIds) {
            $all = $viewer
                ? KnownRoute::progressForUser($viewer->id)
                : array_map(static function (array $r): array {
                    $r['matched'] = 0;
                    $r['pct'] = 0;
                    $r['isComplete'] = false;
                    return $r;
                }, KnownRoute::all());
            $routes = array_values(array_filter($all, static fn(array $r): bool => isset($routeIds[(int) $r['id']])));
        }
        $routesKm = array_sum(array_map(static fn(array $r): float => (float) ($r['distance_km'] ?? 0), $routes));

        $organizers = Organizer::search($filters, 'rating', 12, 0);
        $organizerCards = array_map(static function (array $o): array {
            $zdjecia = Organizer::coverPhotosWithSource($o['userId'], 3);
            $o['coverPhotos'] = $zdjecia['urls'];
            $o['coverPhotosSource'] = $zdjecia['source'];
            return $o;
        }, $organizers['items']);

        // Pokrycie regionu — wiersz z tego samego źródła co emblematy regionów
        // na /odkrycia i profilu, więc liczby nie mogą się rozjechać.
        $coverage = null;
        foreach (Discovery::regionProgress($viewerId)['regions'] ?? [] as $row) {
            if ((int) $row['id'] === $regionId) {
                $coverage = $row;
                break;
            }
        }

        // Okładka tylko z tego, co już jest w regionie: najbliższy wyjazd ze
        // zdjęciem, a w jego braku znana trasa ze zdjęciem. Bez zdjęcia strona
        // zostaje przy samym nagłówku — jak trasa bez okładki.
        //
        // ŹRÓDŁO IDZIE RAZEM ZE ZDJĘCIEM (2026-09-16, prośba usera: „ważne, aby
        // wiadome było, co jest źródłem zdjęcia i czego dotyczy"). Okładka nie
        // przedstawia „regionu", tylko konkretny wyjazd albo trasę — i tak ma
        // być podpisana.
        $cover = null;
        $coverSource = null;
        foreach ($upcomingCards as $card) {
            if (!empty($card['coverPhotoUrl'])) {
                $cover = $card['coverPhotoUrl'];
                $coverSource = ['label' => __('wyjazd „') . $card['title'] . '"', 'url' => View::url('/events/' . $card['slug'])];
                break;
            }
        }
        if ($cover === null) {
            foreach ($routes as $r) {
                if (!empty($r['cover_photo_url'])) {
                    $cover = $r['cover_photo_url'];
                    $coverSource = ['label' => __('trasa „') . $r['name'] . '"', 'url' => View::url('/trasy/' . $r['slug'])];
                    break;
                }
            }
        }

        $treasureListAll = Treasure::listInRegion($regionId, $viewerId, 1000);

        // Mapa — drzewo warstw jak na stronie trasy (`TrailController::show`),
        // z tym samym patchem mgły. Warstwa „Znane trasy" zostaje KATALOGIEM
        // (`kr`): strona jest o terenie, więc wszystkie szlaki w kadrze są jej
        // treścią, a nie tłem jednej trasy.
        $mapLayers = MapLayer::tree($viewer !== null ? 'me' : 'all', [
            'loggedIn' => $viewer !== null,
            'only'     => ['cells', 'heat', 'trails', 'treasures'],
        ]);
        foreach ($mapLayers as &$layer) {
            if ($layer['key'] === 'cells') {
                $layer['on'] = $viewer !== null;
                $layer['hint'] = $viewer !== null ? __('gdzie już byłeś') : __('gdzie jeżdżą inni');
            } elseif ($layer['key'] === 'trails') {
                $layer['on'] = true;
                // Dzieci „Ukończone"/„Nieukończone" — patrz ta sama nota w
                // TrailController::show; tu też nie o postępie widza jest strona.
                $layer['children'] = [];
            }
        }
        unset($layer);
        $mapSources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $mapSources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }
        $bounds = Region::bounds($region['code']);

        $path = $isFlat ? '/regiony/' . $country['code'] : '/regiony/' . $country['code'] . '/' . $region['code'];
        $plural = Format::plural(...);
        $descBits = [];
        if ($upcoming['total'] > 0) {
            $descBits[] = $upcoming['total'] . ' ' . $plural((int) $upcoming['total'], __('nadchodzący wyjazd'), __('nadchodzące wyjazdy'), __('nadchodzących wyjazdów'));
        }
        if ($routes) {
            $descBits[] = count($routes) . ' ' . $plural(count($routes), 'znana trasa', 'znane trasy', 'znanych tras');
        }
        if ($organizers['total'] > 0) {
            $descBits[] = $organizers['total'] . ' ' . $plural((int) $organizers['total'], 'organizator', 'organizatorzy', 'organizatorów');
        }
        $description = ($descBits ? ucfirst(implode(', ', $descBits)) . ' — ' : 'Wyjazdy rowerowe i trasy — ')
            . $name . ($isFlat ? '' : ', ' . $countryName)
            . '. Gravel, MTB i szosa: zobacz, gdzie i z kim pojechać, i dołącz do ludzi, którzy tu jeżdżą.';

        $jsonLdNode = [
            '@type' => $isFlat ? 'Country' : 'AdministrativeArea',
            'name'  => $name,
            'url'   => View::absoluteUrl($path),
        ];
        if (!$isFlat) {
            $jsonLdNode['containedInPlace'] = ['@type' => 'Country', 'name' => $countryName];
        }
        if ($bounds !== null) {
            $jsonLdNode['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => round(($bounds['south'] + $bounds['north']) / 2, 5),
                'longitude' => round(($bounds['west'] + $bounds['east']) / 2, 5),
            ];
        }

        $breadcrumbs = [Support::homeCrumb(), ['label' => __('Regiony'), 'url' => View::url('/regiony')]];
        if (!$isFlat) {
            $breadcrumbs[] = ['label' => $countryName, 'url' => View::url(Region::countryPath($country))];
        }
        $breadcrumbs[] = ['label' => $name];

        $siblings = $isFlat ? [] : array_values(array_filter(
            $country['regions'],
            static fn(array $r): bool => $r['code'] !== $region['code']
        ));

        View::render('web', 'region', [
            'title'          => __('Wyjazdy rowerowe i trasy — {region} | ridemore.bike', ['region' => $name]),
            'description'    => $description,
            'region'         => $region,
            'regionName'     => $name,
            'country'        => $country,
            'countryName'    => $countryName,
            'isFlat'         => $isFlat,
            'cover'          => $cover,
            'coverSource'    => $coverSource,
            'photos'         => self::regionPhotos($regionId, $treasureListAll['items']),
            'upcoming'       => $upcomingCards,
            'upcomingTotal'  => (int) $upcoming['total'],
            'completed'      => $completedCards,
            'completedTotal' => (int) $completed['total'],
            'routes'         => $routes,
            'routesKm'       => $routesKm,
            'organizers'     => $organizerCards,
            'organizersTotal' => (int) $organizers['total'],
            'treasures'      => Treasure::onRegion($regionId, $viewerId),
            // 9, nie 60 jak na trasie: trasa to plan „najpierw minę to", a region
            // ma ich dziesiątki — pełna lista zjadała ekran (24 karty na
            // podkarpackim, zmierzone). Reszta jest na mapie wyżej.
            'treasureList'   => Treasure::listInRegion($regionId, $viewerId, 9),
            'riders'         => RiderActivity::ridersInRegion($regionId),
            'coverage'       => $coverage,
            'siblings'       => $siblings,
            'isLoggedIn'     => $viewer !== null,
            'mapScope'       => $viewer !== null ? 'me' : 'all',
            'mapEndpoints'   => [
                'cells'     => View::url('/api/discovery/cells'),
                'trailsAt'  => View::url('/api/discovery/trails/at'),
                'treasures' => View::url('/api/treasures'),
                'claim'     => View::url('/api/treasures/claim'),
                'confirm'   => View::url('/api/treasures/confirm'),
            ],
            'mapLayers'      => $mapLayers,
            'mapSources'     => $mapSources,
            'mapFilters'     => MapLayer::filtersFor($mapLayers),
            'mapBounds'      => $bounds,
            'mapOutline'     => Region::rings($region['code']),
            'extraHead'      => $bounds !== null
                ? Support::leafletMapHead() . "\n"
                    . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>'
                : '',
            'jsonLd'         => JsonLd::forNode($jsonLdNode)
                . ($upcomingCards ? JsonLd::forEventList($upcomingCards, View::absoluteUrl($path)) : ''),
            'breadcrumbs'    => $breadcrumbs,
        ]);
    }

    /** Ile zdjęć pokazuje sekcja „Zdjęcia z regionu" — najnowsze. */
    private const PHOTOS_LIMIT = 12;

    /**
     * „ZDJĘCIA Z REGIONU" (2026-09-16) — to, co ludzie przywieźli z tego terenu:
     * zdjęcia z relacji i opinii uczestników wyjazdów oraz z galerii skarbów.
     *
     * Decyzje usera:
     *   1. Zdjęcia skarbu TYLKO gdy `Treasure::reveal()` odsłonił go widzowi
     *      w całości (skarb jawny albo przez widza znaleziony). Zdjęcie jest
     *      najmocniejszym spoilerem zagadki — dlatego skarby przychodzą tu
     *      już przepuszczone przez reveal(), a nie są wybierane drugi raz
     *      osobnym warunkiem w SQL-u.
     *   2. Autor z imieniem i linkiem tylko przy publicznym profilu, inaczej
     *      „uczestnik wyjazdu" / „znalazca skarbu" (modele to rozstrzygają).
     *
     * Każda pozycja ma rodzaj źródła, przedmiot (wyjazd/skarb) z linkiem,
     * autora i datę dodania — widok nie zgaduje żadnej z tych rzeczy.
     *
     * @param list<array<string,mixed>> $revealedTreasures pozycje z Treasure::listInRegion
     * @return list<array<string,mixed>>
     */
    private static function regionPhotos(int $regionId, array $revealedTreasures): array
    {
        $photos = [];
        foreach (EventPhoto::forRegion($regionId, self::PHOTOS_LIMIT) as $p) {
            $photos[] = [
                'url'        => $p['url'],
                'createdAt'  => $p['createdAt'],
                'source'     => $p['kind'] === 'recap' ? __('Relacja z wyjazdu') : __('Opinia o wyjeździe'),
                'subject'    => $p['eventTitle'],
                'subjectUrl' => View::url('/events/' . $p['eventSlug'] . '#opinie'),
                'authorName' => $p['authorName'],
                'authorUrl'  => $p['authorName'] !== null ? View::url('/rowerzysta/' . $p['authorSlug']) : null,
                'anonymous'  => 'uczestnik wyjazdu',
            ];
        }

        $treasures = [];
        foreach ($revealedTreasures as $t) {
            if (($t['reveal'] ?? '') === 'exact') {
                $treasures[(int) $t['id']] = $t;
            }
        }
        foreach (TreasurePhoto::latestFor(array_keys($treasures), self::PHOTOS_LIMIT) as $p) {
            $photos[] = [
                'url'        => $p['url'],
                'createdAt'  => $p['createdAt'],
                'source'     => __('Skarb'),
                'subject'    => (string) $treasures[$p['treasureId']]['name'],
                'subjectUrl' => View::url('/odkrycia?skarb=' . $p['treasureId']),
                'authorName' => $p['authorName'],
                'authorUrl'  => $p['authorName'] !== null ? View::url('/rowerzysta/' . $p['authorSlug']) : null,
                'anonymous'  => 'znalazca skarbu',
            ];
        }

        usort($photos, static fn(array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));

        return array_slice($photos, 0, self::PHOTOS_LIMIT);
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('web', 'regions', [
            'title'       => __('Nie znaleziono regionu — ridemore.bike'),
            'noindex'     => true,
            'notFound'    => true,
            'countries'   => Region::countries(),
            'counts'      => [],
            'heading'     => __('Nie znaleziono regionu'),
            'sub'         => __('Tego regionu nie ma w serwisie — wybierz jeden z listy.'),
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Regiony'), 'url' => View::url('/regiony')]],
        ]);
    }

    /** ItemList adresów (spis i strona kraju) — ten sam format co lista wydarzeń. */
    private static function breadcrumbFreeList(array $items): string
    {
        if (!$items) {
            return '';
        }
        return JsonLd::forNode([
            '@type'           => 'ItemList',
            'numberOfItems'   => count($items),
            'itemListElement' => array_map(
                static fn(array $it, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name'], 'url' => $it['url']],
                $items,
                array_keys($items)
            ),
        ]);
    }
}
