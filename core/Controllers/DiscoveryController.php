<?php
// core/Controllers/DiscoveryController.php
// RIDEMORE DISCOVERY — /odkrycia.
//
// JEDNA STRONA Z ZAKŁADKAMI (projekt grafika, 2026-08-13). Wcześniej były dwie
// osobne: /odkrycia (moja) i /odkrycia/spolecznosc (wspólna). Projekt stawia je
// obok siebie jako przełącznik nad tą samą mapą, bo to jedno pytanie zadane
// z dwóch stron — „co odkryłem" i „co odkryliśmy" — a nie dwa różne ekrany.
// Statystyki nad mapą też są wtedy jedne, a nie rozbite na dwa nagłówki.
//
// Strona zostaje PUBLICZNA. Niezalogowany widzi wyłącznie zakładkę
// społeczności: białe plamy na wspólnej mapie są argumentem za założeniem
// konta silniejszym niż jakikolwiek tekst, więc trzymanie tego za logowaniem
// byłoby zamykaniem drzwi, które mają zapraszać.
namespace Controllers;

use Core\Auth;
use Models\Discovery;
use Models\EditionTrack;
use Models\KnownRoute;
use Models\MapLayer;
use Models\PointLedger;
use Models\TileCache;
use Models\TileSource;
use Utils\View;

class DiscoveryController
{
    // Okno kafla „RAZEM ODKRYLIŚMY … w tym miesiącu" z projektu.
    private const COMMUNITY_WINDOW_DAYS = 30;

    public static function index(): void
    {
        $viewer = Auth::user();

        // Zakładka z adresu, żeby dało się podlinkować konkretny widok
        // (?mapa=spolecznosc). Gość zawsze ląduje na wspólnej — swojej nie ma.
        $tab = ($_GET['mapa'] ?? '') === 'spolecznosc' || $viewer === null ? 'all' : 'me';

        // APKA LICZY TYLKO TO, CO RYSUJE (2026-08-30, zgłoszenie usera:
        // „klikam Mapę na dolnej belce i czekam długo").
        //
        // Do tej pory stało tu, że „dane liczymy raz, jak dotąd; zmienia się
        // tylko to, co je renderuje" — i to było prawdą, tylko kosztowną:
        // `discovery-app.php` to pełnoekranowa mapa i DWA liczniki w chipie,
        // a kontroler liczył pod nią komplet kafli strony desktopowej.
        // Zmierzone na bazie DEV (konto z prawdziwą historią): dane, których
        // apka NIE UŻYWA, to ~1160 ms na żądanie, z czego samo
        // `Discovery::regionsForUser` ~980 ms. To, czego używa — ~20 ms.
        //
        // Bramka jest jedna (`$pelnaStrona`) i stoi TYLKO na danych. Widok,
        // trasa, uprawnienia i zakładki zostają wspólne — apka dostaje ten sam
        // `$tab` i te same warstwy, po prostu nie płaci za panel, którego nie ma.
        $pelnaStrona = !APP_IS_APP;

        // Jedno zapytanie na feed — korzystają z niego i panel, i mapa URL-i
        // śladów (spójność z profilem, patrz nota niżej). viewerId przechodzi
        // do maski skarbów: na własnej osi czasu wychodzą w pełni.
        $rides = $pelnaStrona
            ? Discovery::recentActivity($tab === 'me' && $viewer ? $viewer->id : null, 50, $viewer?->id)
            : [];

        // DRZEWO WARSTW (Etap 2, tasks/done/warstwy-mapy.md) — zakładka
        // JEST kontekstem tej strony: „moja" dostaje `slug` widza, więc
        // warstwa „Ślady" rozwiąże się do JEGO śladów (Etap 1b), a
        // społeczność dostaje `slug = null`, czyli klucz `all`.
        // `withQueryOverrides` utrzymuje stan warstw w adresie (`?trails=0`
        // itd.) — jedyna strona, która to dotąd robiła.
        $mapLayersDefault = MapLayer::tree($tab === 'me' ? 'me' : 'all', [
            'loggedIn' => $viewer !== null,
            'slug'     => $tab === 'me' && $viewer ? $viewer->publicSlug : null,
        ]);
        $mapLayers = MapLayer::withQueryOverrides($mapLayersDefault, $_GET);
        // Szablony URL kafli nadal buduje SERWER (niosą epokę `?v=`, której
        // przeglądarka nie zna) — `tileKeysFor` mówi tylko, DLA KTÓRYCH
        // warstw i pod jakim kluczem `TileSource` je zbudować.
        $sources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $sources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }

        // W APCE MOBILNEJ ta sama trasa/dane dostają INNY szablon — pełnoekranową
        // mapę zamiast strony z kaflami statystyk (Etap 1 przebudowy apki,
        // tasks/active/apka-mobilna.md). Dane liczymy raz, jak dotąd; zmienia się
        // tylko to, co je renderuje. `bodyClass` steruje CSS-em w `body.is-app.map-page`
        // (mapa wypełnia ekran, dolny pasek pływa nad nią).
        View::render('web', APP_IS_APP ? 'discovery-app' : 'discovery', [
            'title'       => $viewer && $tab === 'me'
                ? __('Moje odkrycia | ridemore.bike')
                : __('Ridemore Discovery — co odkryliśmy razem | ridemore.bike'),
            'description' => 'Każdy Twój przejazd odkrywa nowe miejsca na mapie. '
                           . __('Zbieraj punkty, zaliczaj trasy i odkrywaj razem z innymi.'),
            'tab'         => $tab,
            'isLoggedIn'  => $viewer !== null,
            'summary'     => $viewer ? Discovery::summaryForUser($viewer->id) : null,
            'treasures'   => $viewer ? \Models\Treasure::statsForUser($viewer->id) : null,
            'treasuresAll' => \Models\Treasure::statsCommunity(),
            'treasuresWaiting' => $pelnaStrona ? \Models\Treasure::waitingList() : [],
            'treasureTop' => $pelnaStrona ? \Models\Treasure::mostFound() : [],
            'regions'     => $viewer && $pelnaStrona ? Discovery::regionsForUser($viewer->id) : null,
            // POKRYCIE REGIONÓW (migr. 070/071) — mianownik, którego brak był
            // powodem zakazu procentów. Społeczność zawsze (procent Polski na
            // zakładce wspólnej i pasek „Razem" na osobistej), potencjały tylko
            // zalogowanemu (ranking celów regionalnych).
            //
            // ID WIDZA DOKŁADANE OD 2026-09-05 („Twoje regiony" — emblematy per
            // region pod kotwicą `#regiony` na discovery.php): pola
            // `mine`/`pctMine` w każdym regionie liczą się TYLKO gdy
            // $userId != null — reszta wyniku (community/total/grand) jest
            // identyczna niezależnie od niego. Ta sama bramka `$pelnaStrona`
            // co reszta danych osobowych: apka mobilna dalej nie płaci za
            // zapytanie, którego jej szablon nie rysuje.
            'regionsWorld'     => Discovery::regionProgress($pelnaStrona && $viewer ? $viewer->id : null),
            'regionPotentials' => $viewer && $pelnaStrona ? Discovery::regionPotentials($viewer->id, 3) : [],
            // „Ostatnia aktywność" NIE JEST JUŻ listą naliczeń punktowych
            // (2026-08-24) — panel pokazuje PRZEJAZDY (`rides` niżej), bo to
            // one mają datę, odkryte pola i miejsce na mapie. Zostaje sam
            // tygodniowy licznik, bo ten idzie do kafla statystyk.
            'pointsWeek'  => $viewer && $pelnaStrona ? PointLedger::pointsSince($viewer->id, 7) : 0,
            'routes'      => $pelnaStrona ? ($viewer ? KnownRoute::progressForUser($viewer->id) : KnownRoute::all()) : [],
            // OSTATNIA AKTYWNOŚĆ — przejazdy I znalezione skarby (2026-08-24).
            // PIĘĆDZIESIĄT, nie kilkanaście: lista w panelu przewija się zamiast
            // stronicować, więc ogranicza ją wysokość mapy, a nie sztywna liczba.
            // Sufit zostaje, bo na mapie społeczności zdarzeń przybywa bez końca,
            // a panel ma być oknem na ostatnie, nie całą historią serwisu.
            // Na zakładce osobistej TWOJE, na społecznościowej WSZYSTKICH (wtedy
            // moduł dokłada autora). Zastąpiła dwie połówki tej samej rzeczy:
            // listę naliczeń punktowych w panelu i sekcję kart pod mapą.
            // viewerId = właściciel: skarby na własnej osi czasu wychodzą w pełni.
            'rides'       => $rides,
            'community'   => $pelnaStrona ? Discovery::communityStats(self::COMMUNITY_WINDOW_DAYS) : [],
            'discoverers' => $pelnaStrona ? Discovery::recentDiscoverers(4) : [],
            // KADR STARTOWY MAPY (2026-08-19, uwaga usera). Do tej daty mapa
            // startowała w środku Polski i dopiero po pierwszej odpowiedzi
            // dociągała się do pól, które akurat wpadły w ten (za szeroki)
            // kadr. Teraz dostaje prostokąt policzony z FAKTYCZNYCH odkryć:
            // własnych na zakładce osobistej, wszystkich na społecznościowej.
            // null (nikt jeszcze nic nie odkrył) = mapa zostaje przy widoku
            // domyślnym, czyli całym kraju — i to jest wtedy prawidłowa odpowiedź.
            // KADR NA KONKRETNYM SKARBIE (?skarb={id}, 2026-09-11) — pod
            // przycisk w powiadomieniu „nowy skarb w Twojej okolicy". Bez tego
            // link z maila lądował na mapie całego kraju i człowiek musiał sam
            // szukać, o co chodziło (zgłoszenie usera).
            //
            // WSPÓŁRZĘDNE ODDAJEMY WYŁĄCZNIE DLA SKARBU JAWNEGO (poziom 2) —
            // patrz `kadrNaSkarbie()`. Dla ukrytego adres z parametrem jest
            // bezużyteczny i to jest zamierzone: inaczej wystarczyłoby zgadywać
            // identyfikatory, żeby wyciągnąć położenie czegoś, czego mapa
            // celowo nie pokazuje.
            'mapBounds'   => self::kadrNaSkarbie()
                ?? Discovery::boundsFor($tab === 'me' && $viewer ? $viewer->id : null),
            // SPÓJNOŚC Z PROFILEM ROWERZYSTY (2026-08-25, uwaga usera:
            // „osobista mapa nie koreluje wyglądem ani funkcjonalnością co
            // profil"). Dwa elementy przeniesione 1:1 z RiderController:
            //   - KAFLE WŁASNYCH ŚLADÓW (migr. 051) — ta sama warstwa rastrowa
            //     co na profilu, pod TYM SAMYM kluczem (u-{slug}, awaryjnie
            //     `me`), więc obraz linii jest identyczny w obu miejscach;
            //   - URL-e śladów per przejazd — klik w wiersz „Ostatniej
            //     aktywności" rysuje wybrany ślad WEKTOROWO na wierzchu
            //     (niebieski WYBÓR, patrz SEMANTYKA KOLORÓW MAPY) i dociąga
            //     kadr, dokładnie jak lista przejazdów na profilu.
            //     Klucz to id przejazdu (rider_activities.id) z feedu.
            //     Przejazd solo ma tu swój plik TYLKO na własnej osi czasu —
            //     na mapie społeczności cudze solo dostaje sam fitBounds
            //     (§27; bramka siedzi w Support::trackUrlsForFeed, dlatego
            //     leci tam `$viewer?->id`).
            'feedTrackUrls' => $pelnaStrona ? Support::trackUrlsForFeed($rides, $viewer?->id) : [],
            'mapLayers'   => $mapLayers,
            // Stan SPRZED override'u z `$_GET` — persystencja warstw w adresie
            // (discovery.php) go potrzebuje, żeby zapisywać tylko odchylenie
            // od domyślnego, nie każdy klucz przy każdym kliknięciu.
            'mapLayersDefault' => MapLayer::flatten($mapLayersDefault),
            'mapSources'  => $sources,
            'mapFilters'  => MapLayer::filtersFor($mapLayers),
            // Osobista mapa rysuje WYBRANY ślad wektorowo (leaflet-gpx), więc
            // dostaje kompletny head jak profil; społecznościowa wektora nie
            // rysuje i nie dokłada biblioteki.
            'extraHead'   => ($tab === 'me' ? Support::gpxMapHead() : Support::leafletMapHead()) . "\n"
                           . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>',
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Ridemore Discovery')]],
            'bodyClass'   => APP_IS_APP ? 'map-page' : null,
        ]);
    }

    /**
     * Stary adres wspólnej mapy. Zostaje jako przekierowanie, bo linkują w
     * niego nawigacja, Puls, kronika i maile — a adres, który przestał
     * odpowiadać, jest gorszy niż niepotrzebna trasa.
     */
    public static function community(): void
    {
        header('Location: ' . View::url('/odkrycia') . '?mapa=spolecznosc', true, 301);
        exit;
    }

    /**
     * Prostokąt kadru wokół skarbu z `?skarb={id}` albo `null`.
     *
     * BRAMKA POZIOMU UJAWNIENIA JEST TU, A NIE W WIDOKU: oddajemy współrzędne
     * tylko dla skarbu, którego położenie i tak widać na mapie (poziom 2).
     * Dla ukrytego zwracamy `null`, więc mapa zachowa się jak zwykle — ta sama
     * zasada, która rządzi treścią powiadomień o skarbach.
     *
     * Margines ~0,02° to mniej więcej dwa kilometry: widać okolicę, a nie
     * pojedyncze pole, więc kadr nie zdradza więcej niż sama pinezka.
     */
    private static function kadrNaSkarbie(): ?array
    {
        $id = (int) ($_GET['skarb'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $stmt = \Core\Database::connection()->prepare("
            SELECT lat, lon FROM treasures
             WHERE id = :id AND is_active = 1 AND status = 'ACTIVE' AND reveal_level >= 2
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['lat'] === null || $row['lon'] === null) {
            return null;
        }
        $lat = (float) $row['lat'];
        $lon = (float) $row['lon'];
        $m = 0.02;

        // Kształt MUSI się zgadzać z `DiscoveryGrid::boundsFromAxialExtremes()`,
        // bo widok podaje to dalej do tej samej gałęzi JS-a co zwykły kadr.
        return ['south' => $lat - $m, 'west' => $lon - $m, 'north' => $lat + $m, 'east' => $lon + $m];
    }
}
