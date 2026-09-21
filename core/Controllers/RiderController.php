<?php
// core/Controllers/RiderController.php
// Publiczny profil rowerzysty — /rowerzysta/{slug} (Etap 3).
namespace Controllers;

use Core\Auth;
use Models\Discovery;
use Models\User;
use Models\GpxGeometry;
use Models\KnownRoute;
use Models\MapLayer;
use Models\RiderFeed;
use Models\TileCache;
use Models\TileSource;
use Resources\RiderProfileResource;
use Utils\View;

class RiderController
{
    // Rozmiary stron list na profilu — patrz „SKALA LIST NA PROFILU" w show().
    private const PER_RIDES = 9;
    private const PER_TREASURES = 12;
    public const PER_GABLOTA = 8;

    public static function show(string $slug): void
    {
        // TRZY warunki widoczności (konto istnieje · nie ukryło się ·
        // ma potwierdzony przejazd) mieszkają w Support::visibleRider() —
        // od Etapu 8 tej samej reguły używa API mapy odkryć, a dwie kopie
        // rozjechałyby się przy pierwszej zmianie zasad prywatności.
        //
        // Wszystkie trzy dają to samo 404: nie zdradzamy, czy konto istnieje,
        // czy tylko się ukryło.
        $user = Support::visibleRider($slug);
        // WŁASNY PROFIL OMIJA BRAMKĘ UKRYCIA (2026-09-13, test usera „jako
        // rowerzysta nie znalazłem swoich ustawień"). Kto zaznaczył „Nie
        // pokazuj mnie na listach", dostawał na WŁASNYM profilu — i pod
        // „Profil" w dolnym pasku apki — ekran „Nie znaleziono rowerzysty",
        // czyli tracił jedyną drogę z profilu do ustawień, łącznie z tym,
        // które go ukryło. Właściciel jest rozpoznawany Z SESJI, więc obcy
        // dalej dostaje to samo 404; `profileHidden` każe widokowi powiedzieć
        // to wprost, a strona idzie z `noindex`. API mapy (`rider=`) bramki
        // nie omija — mapa ukrytego profilu może być uboższa, i tak ma być.
        $profileHidden = false;
        if (!$user) {
            $sessionUser = Auth::user();
            if ($sessionUser !== null && $slug !== '' && $sessionUser->publicSlug === $slug) {
                $user = $sessionUser;
                $profileHidden = true;
            }
        }
        if (!$user) {
            http_response_code(404);
            View::render('web', 'rider-profile', [
                'title'   => __('Nie znaleziono rowerzysty — ridemore.bike'),
                'noindex' => true,
                'profile' => null,
                'breadcrumbs' => [Support::homeCrumb()],
            ]);
            return;
        }

        $profile = RiderProfileResource::fromUser($user);
        $viewer = Auth::user();
        // WŁAŚCICIEL PATRZĄCY NA WŁASNY PROFIL (2026-08-27, zgłoszenie usera:
        // „mam inne ślady niż na mapie własnej w odkryciach, powinienem mieć
        // to samo"). Steruje WYŁĄCZNIE tym, który klucz kafli „Ślady" dostaje
        // ta osoba — patrz `$tileKey` niżej i `Models\MapLayer::tileKeyFor`.
        // Reszta strony (Discovery, feed, trasy, skarby) już liczyła to samo
        // rozróżnienie osobno tam, gdzie było potrzebne (np. `$feedTrackUrls`
        // kilka linii niżej) — to jest po prostu jedno miejsce więcej.
        $isOwner = $viewer !== null && $viewer->id === $user->id;

        // Discovery (Etap 8) — odkryty teren tej osoby i jej postęp na znanych
        // trasach. Mapa hex rysuje się na TEJ SAMEJ mapie co ślady wyjazdów
        // (jedna mapa, dwie warstwy), więc gdy śladów nie ma, nie ma też gdzie
        // narysować pól — i wtedy nie ładujemy nic dodatkowego.
        $discovery = Discovery::summaryForUser($user->id);

        // OSTATNIA AKTYWNOŚĆ NA MAPIE — TEN SAM feed co na /odkrycia
        // (2026-08-25, uwaga usera: „już ci mówiłem, że aktywności mają być
        // te same”; dotąd lista obok mapy czytała wyłącznie ślady wyjazdów
        // i wycinała przejazdy solo, które u tej osoby są większością).
        // $viewerId steruje maską skarbów: właściciel widzi swoje znaleziska
        // w pełni, obcy — tak samo jak na mapie społeczności (nazwa i pozycja
        // tylko dla jawnych albo dla znalazcy).
        $feedRides = Discovery::recentActivity($user->id, 50, $viewer?->id);
        // $viewer?->id steruje TU jeszcze jednym: plik przejazdu solo wychodzi
        // wyłącznie na własnym profilu (§27). Na cudzym wiersz feedu zostaje
        // klikalny, ale dociąga sam kadr z pól, a nie surowy ślad spod domu.
        $feedTrackUrls = Support::trackUrlsForFeed($feedRides, $viewer?->id);
        // Id ŚLADU → adres jego GEOMETRII, dla wpisów „Aktywności” z data-track
        // (edition_tracks.id). Od 2026-09-02 to endpoint `/api/tracks/{id}/geometry`,
        // a nie adres pliku: rysowanie potrzebuje samej linii, a plik z licznika
        // potrafi mieć 10+ MB tętna i mocy — patrz nota przy tym endpoincie
        // w api/routes.php.
        $trackUrlsById = [];
        foreach (\Models\EditionTrack::effectiveForUser($user->id) as $t) {
            if (empty($t['gpx_url'])) {
                continue;
            }
            $trackUrlsById[(int) $t['id']] = View::url('/api/tracks/' . (int) $t['id'] . '/geometry');
        }

        // Trasy: lista tylko ROZPOCZĘTYCH (na cudzym profilu szlaki z zerami
        // mówiłyby o katalogu tras, nie o tej osobie), ale licznik „X z Y"
        // z PEŁNEGO katalogu — bo dokładnie tak liczy go /odkrycia i dwie
        // różne odpowiedzi na to samo pytanie były częścią niespójności.
        $allTrails = KnownRoute::progressForUser($user->id);
        $trails = array_values(array_filter($allTrails, static fn(array $r) => (int) $r['matched'] > 0));
        $trailsDone = count(array_filter($allTrails, static fn(array $r) => !empty($r['isComplete'])));


        // KAFLE (migr. 051). Widok dostaje dwie gotowe rzeczy i nic więcej nie
        // musi wiedzieć: adresy warstw (z epoką w ?v=, więc same się unieważnią)
        // oraz KADR POCZĄTKOWY policzony z prostokątów w bazie.
        //
        // Ten kadr to drugi, mniej oczywisty zysk z kafli. Przedtem mapa uczyła
        // się swoich granic dopiero z WCZYTANYCH plików GPX — czyli żeby wiedzieć,
        // gdzie ustawić widok, trzeba było najpierw pobrać całą historię. Teraz
        // to jedno zapytanie o MIN/MAX kolumn.
        $tileKey = $isOwner
            ? 'me'
            : ($user->publicSlug !== null && $user->publicSlug !== '' ? 'u-' . $user->publicSlug : 'me');
        $tileHashes = [];
        foreach (TileSource::tracks($tileKey) as $group) {
            $tileHashes = array_merge($tileHashes, $group['hashes']);
        }

        // DRZEWO WARSTW (Etap 2, tasks/done/warstwy-mapy.md). Kontekst
        // 'rider' + `slug` TEJ osoby (nie widza) — „Ślady" rozwiązuje się do
        // jej śladów (Etap 1b), a „Skarby" nie mają tu dzieci odkryte/
        // nieodkryte (zawężenie do jej kolekcji robi `mapEndpoints.treasures`
        // przez `slug`/`rider=`, nie parametr `stan` — patrz komentarz niżej
        // przy `mapEndpoints`), więc `MapLayer` je pomija (`shown: false`
        // w migracji 072 dla kontekstu `rider`).
        $mapLayers = MapLayer::tree('rider', [
            'loggedIn' => $viewer !== null,
            'slug'     => $user->publicSlug,
            // Patrz `$isOwner` wyżej — jedyny efekt tutaj: „Ślady" tej osoby
            // rozwiązuje się do prywatnego `me` (z przejazdami solo) zamiast
            // publicznego `u-{slug}`, WYŁĄCZNIE gdy pytający to ona sama.
            'isOwner'  => $isOwner,
        ]);
        $mapSources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $mapSources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }

        // SKALA LIST NA PROFILU (2026-09-13, pytanie usera: „a co, jeśli zdobędę
        // 300 skarbów albo zahaczę o 1000 znanych tras?"). Każda rosnąca lista
        // idzie stronami (`partials/step-pager.php`, parametr w adresie), a skarby
        // dostają filtr rzadkości i kategorii. Liczone po stronie serwera: do
        // przeglądarki jedzie jedna strona, nie cała historia.
        $wyjazdyStron = max(1, (int) ceil(count($profile['rides']) / self::PER_RIDES));
        $wyjazdyStrona = min($wyjazdyStron, max(1, (int) ($_GET['wyjazdy'] ?? 1)));
        $wyjazdyNaStronie = array_slice($profile['rides'], ($wyjazdyStrona - 1) * self::PER_RIDES, self::PER_RIDES);

        $skarbyRzadkosc = in_array($_GET['rzadkosc'] ?? '', ['LEGENDARY', 'EPIC', 'RARE', 'COMMON'], true)
            ? (string) $_GET['rzadkosc'] : null;
        $skarbyKategoria = (int) ($_GET['kategoria'] ?? 0) > 0 ? (int) $_GET['kategoria'] : null;
        $skarbyIle = \Models\Treasure::countShowcaseForUser((int) $profile['id'], $viewer?->id, $skarbyRzadkosc, $skarbyKategoria);
        $skarbyStron = max(1, (int) ceil($skarbyIle / self::PER_TREASURES));
        $skarbyStrona = min($skarbyStron, max(1, (int) ($_GET['skarby'] ?? 1)));
        $skarbyLiczniki = \Models\Treasure::showcaseRarityCounts((int) $profile['id'], $viewer?->id);

        View::render('web', 'rider-profile', [
            'title'       => __('{imie} — rowerzysta na ridemore.bike', ['imie' => $profile['name']]),
            'description' => self::metaDescription($profile),
            'profile'     => $profile,
            'discovery'   => $discovery,
            'treasureStats' => \Models\Treasure::statsForUser((int) $profile['id']),
            'collections' => \Models\Treasure::collectionsForUser((int) $profile['id']),
            // GABLOTA (2026-09-13) — znaleziska od najrzadszych, ze zdjęciem.
            // Zastępuje dawne „pamiątki" (`foundPhotosForUser`), które pokazywały
            // OBCEMU zdjęcia Tropów i Ukrytych, bo pytały o znalazcę, a nie
            // o oglądającego. `showcaseForUser` maskuje zagadkę per widz.
            'showcase'     => \Models\Treasure::showcaseForUser((int) $profile['id'], $viewer?->id, self::PER_TREASURES,
                ($skarbyStrona - 1) * self::PER_TREASURES, $skarbyRzadkosc, $skarbyKategoria),
            'showcasePaged' => ['page' => $skarbyStrona, 'pages' => $skarbyStron, 'total' => $skarbyIle,
                'per' => self::PER_TREASURES, 'rarity' => $skarbyRzadkosc, 'category' => $skarbyKategoria,
                'counts' => $skarbyLiczniki],
            'rarityTotals' => \Models\Treasure::rarityTotals(),
            // REGIONY JAKO EMBLEMATY — ten sam komponent i te same liczby co
            // „Twoje regiony" na /odkrycia (prośba usera 2026-09-13). Publiczne
            // na równi z mapą mgły tej osoby: to zasięg, nie ślad.
            'regionsWorld' => Discovery::regionProgress($user->id),
            // Zdjęcia i liczba wpisów z KRONIK turnusów na kaflach wyjazdów.
            // Zdjęcia z relacji TYLKO dla wyjazdów bieżącej strony — przy 300
            // wyjazdach nie ciągniemy galerii, której nikt nie zobaczy.
            'rideExtras'   => \Models\EventPhoto::forEditions(array_column($wyjazdyNaStronie, 'edition_id')),
            'ridesPaged'   => ['items' => $wyjazdyNaStronie, 'page' => $wyjazdyStrona, 'pages' => $wyjazdyStron,
                'total' => count($profile['rides']), 'per' => self::PER_RIDES],
            'gablotaPage'  => max(1, (int) ($_GET['gablota'] ?? 1)),
            // EMBLEMATY (migr. 087, 2026-09-11) — odznaki za przejechanie CAŁEJ
            // trasy albo całej trasy wydarzenia. Publiczne bez żadnej bramki
            // i to jest zamierzone: emblemat jest osiągnięciem, a osiągnięcie
            // pokazuje się tak samo obcemu, jak właścicielowi profilu. Nie
            // niesie ani jednej informacji o tym, KTÓRĘDY ktoś jeździ — a to
            // jedyna rzecz, którą ten profil chroni (§27).
            'emblems'     => \Models\Emblem::forUser((int) $profile['id']),
            'trails'      => $trails,
            'trailsDone'  => $trailsDone,
            'trailsTotal' => count($allTrails),
            'riderSlug'   => $user->publicSlug,
            // TA SAMA MAPA CO NA /odkrycia, zawężona do jednej osoby.
            // Endpointy przekazujemy z kontrolera, bo widok nie ma prawa
            // sklejać adresów API — i tak jest wszędzie indziej w tym serwisie.
            'mapEndpoints' => [
                'cells'     => View::url('/api/discovery/cells'),
                // Klik w szlak: kafel jest obrazkiem, więc trafienie liczy serwer.
                'trailsAt'  => View::url('/api/discovery/trails/at'),
                // KLIK W ŚLAD — TERAZ ZAWSZE, NIE TYLKO NA WŁASNYM PROFILU
                // (2026-09-03: „klikam aktywność, trasa się oznacza, ale klik
                // w mapę nie daje dymka tak jak na /odkrycia"; rozszerzone
                // 2026-09-10, zgłoszenie usera: „na swoim profilu każdy
                // przejazd jest klikalny, na czyimś powinno być tak samo" —
                // ten sam dzień, który dał kolor per ślad i klikalność
                // w „Ostatniej aktywności" dla solo na `u-{slug}`).
                // Adres jest ten sam bez względu na widza — o zasięgu decyduje
                // TERAZ endpoint (parametr `rider`, patrz api/routes.php i
                // `RiderActivity::atPointForRider`), nie kontroler: bez
                // `rider` (na /odkrycia) odpowiada WYŁĄCZNIE o przejazdach
                // pytającego z sesji (§27, geometria pełna); z `rider={slug}`
                // (tu, zawsze — slug dokleja `discovery-map.js` z opcji
                // `slug`, tej samej co przy `treasuresEndpoint`) odpowiada
                // o solo WŁAŚCICIELA PROFILU, geometrią PRZYCIĘTĄ, każdemu
                // pytającemu — właścicielowi i obcemu jednakowo.
                'ridesAt'   => View::url('/api/discovery/rides/at'),
                'treasures' => View::url('/api/treasures'),
                'claim'     => View::url('/api/treasures/claim'),
                'confirm'   => View::url('/api/treasures/confirm'),
                'one'       => View::url('/api/treasures'),
            ],
            // Zaliczanie skarbów TYLKO na własnym profilu (decyzja usera
            // 2026-08-15). Na cudzym mapa jest do oglądania: pokazuje czyjąś
            // historię, a nie jest miejscem, z którego zbiera się swoją.
            'mapActions'  => $viewer !== null && $viewer->id === $user->id,
            'mapLayers'   => $mapLayers,
            'mapSources'  => $mapSources,
            'mapFilters'  => MapLayer::filtersFor($mapLayers),
            'tileHex'     => $discovery['cells'] > 0
                ? TileCache::urlTemplate(TileSource::LAYER_HEX, $tileKey)
                : null,
            'tileBounds'  => GpxGeometry::boundsFor($tileHashes),
            // PANEL AKTYWNOŚCI NA MAPIE — partial ride-feed + mapa URL-i śladów
            // (Support::trackUrlsForFeed). feedTrackUrls kluczuje po id PRZEJAZDU
            // (rider_activities.id, wiersze feedu niosą go jako data-ride);
            // trackUrlsById po id ŚLADU (edition_tracks.id) dla wpisów
            // „Aktywności” pod mapą, które sterują mapą przez data-track.
            'feedRides'         => $feedRides,
            'feedShowRider'     => false,
            'feedTrackUrls'     => $feedTrackUrls,
            'trackUrlsById'     => $trackUrlsById,
            // AKTYWNOŚĆ tej osoby — co napisała i wgrała. Osobny byt od Pulsu:
            // tamten mówi „co się dzieje" i grupuje per wyjazd, ten mówi „kim
            // ta osoba jest" i pokazuje każdą rzecz osobno. Patrz Models\RiderFeed.
            // Numer strony z adresu, zeby dalo sie podeslac konkretna strone
            // aktywnosci i zeby wstecz w przegladarce dzialalo tak, jak user
            // sie spodziewa.
            'feed'        => RiderFeed::forUser($user->id, max(0, (int) ($_GET['aktywnosc'] ?? 0) - 1), viewerId: $viewer?->id),
            'feedTotal'   => RiderFeed::countForUser($user->id),
            // Leaflet + leaflet-gpx i discovery-map.js ŁADUJĄ SIĘ ZAWSZE
            // (zgłoszenie usera 2026-09-10: świeże konto bez ani jednej linii
            // ma dostać PUSTĄ mapę — całą Polskę pod mgłą — a nie pustą
            // sekcję bez mapy w ogóle). Do 2026-09-10 biblioteki dochodziły
            // tylko przy `$hasMap` (choć jeden ślad/zapowiedź); teraz mapa
            // profilu zachowuje się tak samo jak /odkrycia dla nowego konta —
            // ten sam silnik już to bezpiecznie obsługuje (pusta odpowiedź
            // API = nic do narysowania, nie błąd).
            'extraHead'   => Support::gpxMapHead() . "\n" . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>',
            // Własny profil ma inny nagłówek akcji (bez „Napisz do siebie").
            'isOwnProfile' => $viewer !== null && $viewer->id === $user->id,
            'profileHidden' => $profileHidden,
            'noindex'     => $profileHidden,
            'ownerTodo'   => $isOwner ? self::ownerTodo($user, $allTrails) : null,
            'viewerId'    => $viewer?->id,
            // PRZYPIĘTA AKCJA „NAPISZ WIADOMOŚĆ" W APCE (Faza 4 przebudowy UX
            // apki, 2026-08-29) — TEN SAM `.mbar` co przycisk zapisu na stronie
            // wydarzenia, ten sam wzorzec `bodyClass` (EventController.php:755).
            // Tylko na cudzym profilu i tylko zalogowanemu — dokładnie warunek,
            // pod którym rider-profile.php i tak już rysuje ten przycisk w treści.
            'bodyClass'   => (APP_IS_APP && $viewer !== null && $viewer->id !== $user->id) ? 'has-mbar' : null,
            'breadcrumbs' => [
                Support::homeCrumb(),
                ['label' => __('Rowerzyści')],
                ['label' => $profile['name']],
            ],
        ]);
    }

    /**
     * PODPOWIEDZI „DO DOKOŃCZENIA" NA WŁASNYM PROFILU (2026-09-13, trzecia
     * i czwarta runda uwag usera). Zastępują dawny panel z kartami ustawień:
     * user rozstrzygnął, że profil ma POKAZYWAĆ i PRZEKIEROWYWAĆ, a ustawiać
     * się ma na ekranach ustawień. Każda pozycja to jedno zdanie i jeden link
     * do miejsca, w którym rzecz się naprawdę robi — żadnego formularza tutaj.
     *
     * Kolejność = to, co najłatwiej stracić: najpierw relacja (wspomnienie
     * blednie z każdym dniem), potem obecność i ślad (bez nich wyjazd się nie
     * liczy), potem emblemat i trasa „prawie", na końcu trop na mapie.
     *
     * Liczone TYLKO dla właściciela — obcy nie płaci za te zapytania.
     *
     * @return array{byEdition: array<int,list<array{kind:string,label:string,url:string}>>, top: list<array{kind:string,text:string,url:string}>}
     */
    private static function ownerTodo(User $user, array $allTrails): array
    {
        $byEdition = [];
        $bez = ['recap' => [], 'attend' => [], 'track' => [], 'emblem' => []];
        // RELACJA MA TERMIN WAŻNOŚCI PODPOWIEDZI: 45 dni od końca wyjazdu.
        // Wspomnienie jest świeże przez kilka tygodni — przypominanie o relacji
        // z wyjazdu sprzed pół roku nie zachęca, tylko buduje listę zaległości.
        // Formularz relacji działa dalej bez limitu; znika wyłącznie podpowiedź.
        $relacjaOd = (new \DateTimeImmutable('today'))->modify('-45 days')->format('Y-m-d');

        foreach (\Models\EventAttendance::profileTodoForUser($user->id) as $r) {
            if (($r['status_code'] ?? '') !== 'completed') {
                continue;
            }
            $ed = (int) $r['edition_id'];
            $eventUrl = '/events/' . $r['slug'] . '?termin=' . $ed;
            if ($r['attended'] === null) {
                $byEdition[$ed][] = ['kind' => 'attend', 'label' => __('Potwierdź obecność'), 'url' => $eventUrl];
                $bez['attend'][] = $r;
                continue;
            }
            if ((int) $r['attended'] !== 1) {
                continue;
            }
            if ((int) $r['own_recaps'] === 0 && (string) $r['end_date'] >= $relacjaOd) {
                $byEdition[$ed][] = ['kind' => 'recap', 'label' => __('Napisz relację'),
                    'url' => '/wydarzenia/' . $r['slug'] . '/relacja?termin=' . $ed];
                $bez['recap'][] = $r;
            }
            if ((int) $r['has_track'] === 0) {
                $byEdition[$ed][] = ['kind' => 'track', 'label' => __('Bez śladu — niezaliczony'), 'url' => '/admin/moje-przejazdy'];
                $bez['track'][] = $r;
            } elseif ((int) $r['emblem_pending'] === 1) {
                $byEdition[$ed][] = ['kind' => 'emblem', 'label' => __('Ślad niepełny — emblemat czeka'), 'url' => $eventUrl];
                $bez['emblem'][] = $r;
            }
        }

        $top = [];
        $tytul = static fn(array $r): string => (string) $r['title'];
        if ($bez['recap']) {
            $r = $bez['recap'][0];
            $reszta = count($bez['recap']) - 1;
            $top[] = ['kind' => 'recap',
                'text' => __('Wyjazd „') . $tytul($r) . __('” już za Tobą — napisz relację, póki wszystko pamiętasz') . ($reszta > 0 ? ' ' . __n($reszta, '(i {n} inny świeży wyjazd)', '(i {n} inne świeże wyjazdy)', '(i {n} innych świeżych wyjazdów)') : ''),
                'url' => '/wydarzenia/' . $r['slug'] . '/relacja?termin=' . (int) $r['edition_id']];
        }
        if ($bez['track']) {
            $n = count($bez['track']);
            $top[] = ['kind' => 'track',
                'text' => __n($n, '{n} wyjazd bez śladu — nie zaliczył pól ani punktów', '{n} wyjazdy bez śladu — nie zaliczyły pól ani punktów', '{n} wyjazdów bez śladu — nie zaliczyły pól ani punktów'),
                'url' => '/admin/moje-przejazdy'];
        }
        if ($bez['emblem']) {
            $r = $bez['emblem'][0];
            $top[] = ['kind' => 'emblem',
                'text' => __('Ślad z „') . $tytul($r) . __('” nie obejmuje całej trasy — emblemat wyjazdu jeszcze czeka'),
                'url' => '/events/' . $r['slug'] . '?termin=' . (int) $r['edition_id']];
        }
        if ($bez['attend']) {
            $n = count($bez['attend']);
            $top[] = ['kind' => 'attend',
                'text' => __n($n, '{n} wyjazd czeka na potwierdzenie obecności — bez tego się nie liczy', '{n} wyjazdy czekają na potwierdzenie obecności — bez tego się nie liczy', '{n} wyjazdów czeka na potwierdzenie obecności — bez tego się nie liczy'),
                'url' => '/admin/moje-przejazdy'];
        }
        $tropy = \Models\Treasure::openTrailsForUser($user->id);
        if ($tropy > 0) {
            $top[] = ['kind' => 'mystery',
                'text' => __n($tropy, '{n} tajemniczy skarb czeka na Twojej mapie — znasz już okolicę', '{n} tajemnicze skarby czekają na Twojej mapie — znasz już okolicę', '{n} tajemniczych skarbów czeka na Twojej mapie — znasz już okolicę'),
                'url' => '/odkrycia'];
        }

        // TRASA „PRAWIE" — najbliższa ukończenia z rozpoczętych, tylko gdy
        // ukończenie coś daje (emblemat). Bez emblematu to zwykły postęp,
        // który i tak widać w gablocie.
        $prawie = null;
        foreach ($allTrails as $t) {
            if ((int) $t['matched'] > 0 && empty($t['isComplete']) && !empty($t['emblem_id'])
                && ($prawie === null || (int) $t['pct'] > (int) $prawie['pct'])) {
                $prawie = $t;
            }
        }
        if ($prawie !== null) {
            $brak = max(0, (int) $prawie['cells_total'] - (int) $prawie['matched']);
            $top[] = ['kind' => 'trail',
                'text' => __n($brak, '{nazwa}: do emblematu brakuje {n} pola trasy', '{nazwa}: do emblematu brakuje {n} pól trasy', '{nazwa}: do emblematu brakuje {n} pól trasy', ['nazwa' => (string) $prawie['name']]),
                'url' => '/trasy/' . $prawie['slug']];
        }
        return ['byEdition' => $byEdition, 'top' => $top];
    }

    private static function metaDescription(array $profile): string
    {
        $bits = [];
        $bits[] = $profile['ridesCount'] === 1 ? __('1 wspólny wyjazd') : __('{n} wspólnych wyjazdów', ['n' => $profile['ridesCount']]);
        if ($profile['regionsVisited'] > 0) {
            $bits[] = __('{a} z {b} regionów', ['a' => $profile['regionsVisited'], 'b' => $profile['regionsTotal']]);
        }
        return __('{imie} na ridemore.bike: {fakty}.', ['imie' => $profile['name'], 'fakty' => implode(' · ', $bits)]);
    }
}
