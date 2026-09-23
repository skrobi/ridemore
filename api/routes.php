<?php
// api/routes.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Controllers\PlannerController;
use Controllers\Support;
use Core\Auth;
use Core\Csrf;
use Core\Database;
use Core\Mailer;
use Core\Router;
use Models\AiImportLog;
use Models\Dictionary;
use Models\Discovery;
use Models\Event;
use Models\EventRsvp;
use Models\KnownRoute;
use Models\MatchEngine;
use Models\Organizer;
use Models\RecommendationDismissal;
use Models\RecommendationLog;
use Models\User;
use Resources\EventResource;
use Utils\AiEngineBridge;
use Utils\DiscoveryGrid;
use Utils\Format;
use Utils\Gpx;
use Utils\RateLimiter;
use Utils\RoadSurfaceDetector;
use Utils\Upload;
use Utils\View;

header('Content-Type: application/json; charset=utf-8');

// ODCZYTY NIE BLOKUJĄ SIĘ WZAJEMNIE (2026-09-02, zgłoszenie usera: „przy dwóch
// obszarach czekałem ponad 10 sekund, mimo że wiele rzeczy już się wyrenderowało").
//
// Jeden kadr mapy to kilkadziesiąt żądań naraz: kafle każdej warstwy plus pola,
// plus skarby. Wszystkie idą z tej samej przeglądarki, czyli z tą samą sesją —
// a PHP trzyma plik sesji na wyłączność do końca żądania, więc DOMYŚLNIE stoją
// w kolejce jedno za drugim. Właśnie stąd brało się „najpierw ślady, potem
// dopiero heksy": nie z kolejności rysowania, tylko z kolejki po plik sesji.
//
// GET w tym pliku jest ODCZYTEM — żaden nie zmienia sesji. `Auth::user()` woła
// się TU, przed zwolnieniem, i to jest istotne: rozwiązanie użytkownika może
// wylogować konto zablokowane (patrz Core\Auth), a to jest zapis do sesji,
// który musi jeszcze zdążyć trafić na dysk.
//
// Pomiar i pełne uzasadnienie: Core\Session.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    Auth::user();
    Core\Session::release();
}

$router = new Router();

// Pinezki pod widok mapy na stronie głównej — CELOWO poza web/routes.php '/'
// (i bez limitu jak karty): mapa musi widzieć wszystkie pasujące wydarzenia
// naraz, nie tylko bieżącą stronę paginacji. Te same filtry co karty (chipy,
// checkboxy, wyszukiwarka, mine/view) + opcjonalny prostokąt widocznego
// obszaru mapy (north/south/east/west) pod przycisk "Szukaj w tym obszarze".
// MUSI być zarejestrowane przed /api/events/{slug} niżej — Router dopasowuje
// w kolejności rejestracji, inaczej {slug} złapałoby "map" jako wartość.
$router->get('/api/events/map', function () {
    $arr = fn(string $key) => array_values(array_filter((array) ($_GET[$key] ?? []), 'is_string'));
    $filters = [
        'eventTypes'    => $arr('eventTypes'),
        'difficulties'  => $arr('difficulties'),
        'paces'         => $arr('paces'),
        'bikeTypes'     => $arr('bikeTypes'),
        'regions'       => $arr('regions'),
        'paid'          => in_array($_GET['paid'] ?? '', ['free', 'paid'], true) ? $_GET['paid'] : null,
        'when'          => in_array($_GET['when'] ?? '', ['weekend', 'month'], true) ? $_GET['when'] : null,
        'maxDistanceKm' => is_numeric($_GET['distance'] ?? null) ? (float) $_GET['distance'] : null,
        'q'             => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
    ];
    $statusView = ($_GET['view'] ?? '') === 'completed' ? 'completed' : 'upcoming';
    $mine = ($_GET['mine'] ?? '') === '1' && Auth::check();
    $viewerId = Auth::check() ? Auth::user()->id : null;

    $bounds = null;
    foreach (['north', 'south', 'east', 'west'] as $key) {
        if (!is_numeric($_GET[$key] ?? null)) { $bounds = null; break; }
        $bounds[$key] = (float) $_GET[$key];
    }

    $rows = Event::mapPins($filters, $statusView, $mine, $viewerId, $bounds);

    echo json_encode(array_map(fn($row) => [
        'lat'           => (float) $row['meeting_point_lat'],
        'lng'           => (float) $row['meeting_point_lng'],
        'title'         => $row['title'],
        'dateLabel'     => Format::dateShort($row['start_date']),
        'distanceLabel' => Format::distance((float) $row['distance_km']),
        'url'           => View::url('/events/' . $row['slug']),
        'gpxUrls'       => !empty($row['gpx_urls']) ? array_map(fn($u) => View::url($u), explode('|', $row['gpx_urls'])) : [],
    ], $rows), JSON_UNESCAPED_UNICODE);
});

// Etap 8 (Discovery) — pola widoczne w bieżącym kadrze mapy. GET bez CSRF, jak
// /api/events/map wyżej: czysty odczyt, wywoływany przy każdym przesunięciu.
//
// NIGDY nie ładujemy całej siatki (§32) — żądanie zawsze niesie prostokąt
// widocznego obszaru, a poziom agregacji wynika z oddalenia. Odpowiedź to
// ŚRODKI pól, nie wielokąty: sześć par współrzędnych na pole rozdęłoby ją
// kilkunastokrotnie, a przeglądarka i tak musi umieć narysować sześciokąt
// wokół punktu.
//
// scope=me wymaga zalogowania i pokazuje WYŁĄCZNIE własne pola. Wspólna mapa
// (scope=all) zwraca liczby rowerzystów, nigdy tożsamości — patrz komentarz
// o prywatności w Models\Discovery.
// CO JEST POD TYM PUNKTEM — odpowiedź na kliknięcie w szlak na mapie.
//
// Zastąpiło GET /api/discovery/trails (usunięte 2026-08-20 razem z wektorową
// warstwą tras). Tamten endpoint wysyłał GEOMETRIĘ przy każdym przesunięciu
// mapy, i to sklejoną ze środków pól siatki — pole ma ok. 500 m, więc linia
// była zygzakiem obok drogi. Szlaki rysują się teraz kaflami (klucz `kr`),
// ale kafel to obrazek: nie ma w co kliknąć.
//
// Ten endpoint odwraca kierunek: zamiast wysyłać geometrię „na zapas", żeby
// przeglądarka umiała trafić, przyjmuje PUNKT i odpowiada, czyje pola są
// w jego pobliżu. Jedno małe żądanie na kliknięcie zamiast jednego dużego na
// każde drgnięcie mapy — i trafienie liczone tam, gdzie mieszka definicja
// trasy (jej pola), a nie w przybliżeniu przysłanym wcześniej.
//
// GET bez CSRF, jak reszta odczytów mapy. Dane są publiczne — to ten sam
// katalog, który stoi otworem pod /trasy.
$router->get('/api/discovery/trails/at', function () {
    if (!is_numeric($_GET['lat'] ?? null) || !is_numeric($_GET['lon'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => __('Brak współrzędnych')]);
        return;
    }

    // Powiększenie decyduje o tolerancji trafienia (patrz KnownRoute::atPoint).
    // Zaciskamy je do zakresu mapy, żeby `zoom=999` nie wyprodukował dzielenia
    // dającego tolerancję zerową ani ujemną.
    $zoom = max(1, min(20, (int) ($_GET['zoom'] ?? 12)));

    // ZAWĘŻENIE DO JEDNEJ TRASY (2026-09-10) — pod stronę `/trasy/{slug}`,
    // gdzie warstwa „Znane trasy" rysuje WYŁĄCZNIE tę jedną trasę. Bez tego
    // parametru klik w mapę odpowiadałby o szlakach, których na tamtej stronie
    // nie widać. Nieobecny albo zerowy = pytanie o cały katalog, czyli
    // zachowanie /odkrycia i profilu, bez zmian.
    $onlyRoute = (int) ($_GET['route'] ?? 0);

    // Postęp liczony dla PYTAJĄCEGO — dymek ma nieść to samo, co karta trasy
    // w sekcji „Znane trasy" pod mapą. Gość dostaje same fakty o szlaku.
    $trails = KnownRoute::atPoint(
        (float) $_GET['lat'],
        (float) $_GET['lon'],
        $zoom,
        Auth::check() ? Auth::user()->id : null,
        3,
        $onlyRoute > 0 ? $onlyRoute : null
    );

    echo json_encode([
        'trails' => array_map(static function (array $trail): array {
            // Adres i sformatowany dystans skleja SERWER — `base_path` zna
            // tylko on, a „159,8 km" to konwencja tego serwisu, nie coś, co
            // przeglądarka ma odgadywać z liczby (tak samo w /api/events/map).
            $trail['url'] = View::url('/trasy/' . $trail['slug']);
            $trail['distanceLabel'] = $trail['distanceKm'] > 0
                ? Format::distance($trail['distanceKm'])
                : null;
            return $trail;
        }, $trails),
    ], JSON_UNESCAPED_UNICODE);
});

// CO JEST POD TYM PUNKTEM — odpowiedź na kliknięcie we WŁASNY ŚLAD
// (2026-08-27, prośba usera: „dodajmy możliwość klikania na solo ślady tak samo
// jak na znane trasy"). Bliźniak endpointu wyżej, z jedną zasadniczą różnicą:
//
// BEZ `rider=`: WYMAGA ZALOGOWANIA I ODPOWIADA WYŁĄCZNIE O WŁASNYCH
// PRZEJAZDACH. Tamten (szlaki) opisuje publiczny katalog (to samo, co stoi
// otworem pod /trasy); ten opisuje CZYJEŚ przejazdy, a plik solo jest surowy
// i zaczyna się pod czyimś domem (§27). Warstwa `me`, w którą się klika bez
// `rider=`, jest prywatna — odpowiedź ma dokładnie ten sam zasięg. `user_id`
// bierzemy Z SESJI, nigdy z żądania: gdyby szedł parametrem, ten endpoint
// byłby czytnikiem cudzych tras domowych po samym podstawieniu liczby.
//
// Z `rider={slug}` (2026-09-10, zgłoszenie usera: „na swoim profilu każdy
// przejazd jest klikalny, na czyimś powinno być tak samo") — PUBLICZNY
// PROFIL, `/rowerzysta/{slug}`. TA SAMA bramka i TEN SAM wzorzec co przy
// `rider=` w `/api/treasures` wyżej: slug przechodzi przez
// `Support::visibleRider`, nieznany/ukryty rowerzysta dostaje 404 — adres nie
// ma zdradzać, czy ktoś tu w ogóle jest. Odpowiada `RiderActivity::
// atPointForRider()`, nie `atPoint()`: geometria PRZYCIĘTA (§27), nie pełna,
// i WYŁĄCZNIE solo (ślady z wyjazdów mają tu zasięg sprzed tej naprawy —
// nikt o nie nie prosił, patrz komentarz przy `atPointForRider`).
$router->get('/api/discovery/rides/at', function () {
    if (!is_numeric($_GET['lat'] ?? null) || !is_numeric($_GET['lon'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => __('Brak współrzędnych')]);
        return;
    }

    // Zacisk zoomu jak wyżej — `zoom=999` dałoby tolerancję zerową.
    $zoom = max(1, min(20, (int) ($_GET['zoom'] ?? 12)));

    if (is_string($_GET['rider'] ?? null) && $_GET['rider'] !== '') {
        $rider = Support::visibleRider($_GET['rider']);
        if ($rider === null) {
            http_response_code(404);
            return;
        }
        $rides = \Models\RiderActivity::atPointForRider(
            (float) $_GET['lat'],
            (float) $_GET['lon'],
            $zoom,
            $rider->id
        );
    } else {
        if (!Auth::check()) {
            // 404, nie 401 — tak samo jak przy ukrytym profilu i przy kaflu
            // spod niedostępnego klucza: odpowiedź nie ma zdradzać, że coś tu jest.
            http_response_code(404);
            return;
        }
        $rides = \Models\RiderActivity::atPoint(
            (float) $_GET['lat'],
            (float) $_GET['lon'],
            $zoom,
            Auth::user()->id
        );
    }

    echo json_encode([
        'rides' => array_map(static function (array $ride): array {
            // Adres, sformatowany dystans i data — sklejane po stronie SERWERA,
            // dokładnie jak w `/api/discovery/trails/at`: `base_path` zna tylko
            // on, a „26,7 km" i „4 wrz" to konwencje tego serwisu, nie coś, co
            // przeglądarka ma odgadywać z liczby i z ISO.
            //
            // DOKĄD PROWADZI „Zobacz przejazd": NA STRONĘ TEGO PRZEJAZDU
            // (2026-09-03). Do tej daty przejazd solo nie miał własnej strony,
            // więc link szedł na listę przejazdów solo zawężoną do jego daty —
            // obejście, nie odpowiedź: człowiek klikał w konkretną linię
            // i dostawał wyszukiwarkę. Przejazd z turnusu prowadził z kolei na
            // stronę wydarzenia, czyli na coś, co opisuje imprezę, a nie JEGO
            // jazdę. Teraz oba prowadzą tam, gdzie stoi kliknięta linia razem
            // ze swoimi liczbami — a stamtąd jest link do wyjazdu i do kroniki.
            $ride['url'] = View::url('/przejazd/' . (int) $ride['id']);
            $ride['distanceLabel'] = $ride['distanceKm'] > 0
                ? Format::distance($ride['distanceKm'])
                : null;
            $ride['dateLabel'] = $ride['date'] !== null ? Format::dateShort($ride['date']) : null;
            return $ride;
        }, $rides),
    ], JSON_UNESCAPED_UNICODE);
});

// DZIENNIK CZASÓW MAPY — NARZĘDZIE DIAGNOSTYCZNE, TYLKO DEV (2026-09-02).
//
// Zgłoszenie usera: „jest bezwładność, nie wiem, co się dzieje — dodajmy logi,
// żeby było widać, z czym problem". Monitor w przeglądarce
// (`ridemoreMapBusy` w assets/js/discovery-map.js) mierzy każde zadanie mapy
// i zbiorczo przysyła tu wyniki; ten endpoint dopisuje je do
// `storage/map-perf.log` — tam, gdzie leży już poczta i push w dev.
//
// DLACZEGO NIE NA PRODUKCJI: adres wstawia `Support::mapPerfHead()` wyłącznie
// przy APP_ENV=dev, a ten warunek stoi TU JESZCZE RAZ. Pierwszy decyduje, czy
// przeglądarka w ogóle wie, dokąd wysyłać; drugi pilnuje, żeby ręcznie
// skonstruowane żądanie nie zapisywało niczego na serwerze, który tego nie
// chce. To nie jest ta sama reguła w dwóch miejscach, tylko dwa różne pytania.
//
// Zapisujemy WYŁĄCZNIE liczby i etykiety, których źródłem jest nasz własny kod
// (nazwa zadania, czas, adres żądania) — bez treści odpowiedzi i bez czegokolwiek,
// co niesie dane rowerzysty.
$router->post('/api/map/perf', function () {
    if (APP_ENV !== 'dev') {
        http_response_code(404);
        return;
    }
    if (!Csrf::check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        http_response_code(419);
        echo json_encode(['error' => __('Sesja wygasła')]);
        return;
    }

    $dane = json_decode((string) file_get_contents('php://input'), true);
    $wpisy = is_array($dane['wpisy'] ?? null) ? $dane['wpisy'] : [];
    if (!$wpisy) {
        echo json_encode(['ok' => true, 'zapisane' => 0]);
        return;
    }

    $dir = CORE_PATH . '/../storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $plik = $dir . '/map-perf.log';

    // ROTACJA PRZY 2 MB, bo ten plik rośnie od samego PATRZENIA na mapę
    // w dev — jedna sesja przeglądania to setki wpisów. Jedna kopia wstecz
    // wystarcza: to jest dziennik do czytania „co się przed chwilą działo",
    // nie archiwum.
    if (is_file($plik) && filesize($plik) > 2 * 1024 * 1024) {
        @rename($plik, $dir . '/map-perf-poprzedni.log');
    }

    $strona = substr((string) ($dane['strona'] ?? '-'), 0, 200);
    $linie = '';
    foreach (array_slice($wpisy, 0, 200) as $w) {
        if (!is_array($w)) {
            continue;
        }
        $linie .= sprintf(
            "[%s] %-22s %7s ms  %-28s %s
",
            date('Y-m-d H:i:s'),
            substr((string) ($w['co'] ?? '?'), 0, 22),
            isset($w['ms']) ? number_format((float) $w['ms'], 0, ',', ' ') : '-',
            $strona,
            substr((string) ($w['szczegoly'] ?? ''), 0, 160)
        );
    }
    file_put_contents($plik, $linie, FILE_APPEND);

    echo json_encode(['ok' => true, 'zapisane' => count($wpisy)]);
});

// GEOMETRIA JEDNEGO ŚLADU — podświetlenie z panelu „Ostatnia aktywność"
// (2026-09-02, zgłoszenie usera: „klikam na aktywność 200 km i czekam, aż
// wczyta się cały GPX; a przecież potrzebujemy tylko ją oznaczyć").
//
// DLACZEGO TO W OGÓLE POWSTAŁO. Do tej daty klik podawał Leafletowi (leaflet-gpx)
// adres SUROWEGO PLIKU: przeglądarka ściągała 10+ MB XML-a z tętnem, mocą,
// kadencją i wysokościami i parsowała każdy `<trkpt>`, żeby wyjąć z niego dwie
// liczby. Te same punkty leżą policzone w `gpx_geometry` od migr. 051 — ten
// endpoint po prostu je stamtąd oddaje, spakowane (format `d6v`, opis przy
// `GpxGeometry::packedForFile`). Plik zostaje tam, gdzie jest potrzebny
// w całości: przy wgraniu i przy liczeniu pól.
//
// KTO MOŻE ZOBACZYĆ CO — nie rozstrzyga się tutaj. Ślad wyjazdu wychodzi przez
// `EditionTrack::effectiveFor`, a plik solo TYLKO właścicielowi (§27) — całość
// liczy `Support::rideGpxPath()`, ta sama metoda, którą feed decyduje, czy
// wiersz w ogóle dostaje adres. Endpoint zna id przejazdu i tożsamość z sesji,
// nic poza tym; `user_id` nigdy nie idzie parametrem.
//
// Brak dostępu to 404, nie 403 — jak przy ukrytym profilu i przy kaflu spod
// niedostępnego klucza: odpowiedź nie ma zdradzać, że coś tu jest.
$router->get('/api/rides/{id}/track', function (string $id) {
    $ride = \Models\RiderActivity::findForTrack((int) $id);
    if ($ride === null) {
        http_response_code(404);
        return;
    }

    $gpxUrl = Support::rideGpxPath($ride, Auth::check() ? Auth::user()->id : null);

    // OBCY DOSTAJE ŚLAD PRZYCIĘTY, NIE ODMOWĘ (2026-09-03, strona przejazdu).
    // Do tej daty solo kończyło się tu 404 dla każdego poza właścicielem, bo
    // jedyne, co ten endpoint umiał oddać, to pełna geometria — a ta zaczyna
    // się pod domem (§27). Odkąd `/przejazd/{id}` jest stroną publiczną,
    // odpowiedzią jest ten sam ślad BEZ okolic domu (`gpx_geometry_trimmed`,
    // migr. 076 — dokładnie to, co wspólna heatmapa pokazuje od 2026-08-28),
    // i wyłącznie wtedy, gdy właściciel jest publicznym rowerzystą.
    // Bramkę liczy `Support::strangerTrackPath()` — ta sama, której używa
    // strona, żeby nie dało się jej obejść adresem API.
    $trimmed = false;
    if ($gpxUrl === null) {
        $gpxUrl = Support::strangerTrackPath($ride);
        $trimmed = true;
    }
    if ($gpxUrl === null) {
        http_response_code(404);
        return;
    }

    $absolute = \Models\TileSource::absolutePath($gpxUrl);
    $packed = $trimmed
        ? \Models\GpxGeometry::packedTrimmedForFile($absolute)
        : \Models\GpxGeometry::packedForFile($absolute);
    if ($packed === null) {
        // Pliku nie ma na dysku albo jest nieczytelny. Pusta geometria (`n=0`)
        // idzie normalną odpowiedzią — to jest fakt o śladzie, nie błąd.
        http_response_code(404);
        return;
    }

    echo json_encode($packed);
});

// ZMIANA WŁASNEJ NAZWY PRZEJAZDU SOLO (migr. 080, 2026-09-05) — ołóweczek
// przy tytule na /przejazd/{id}. Tylko WŁASNY i tylko SOLO — anty-IDOR i
// bramka "solo" siedzą w RiderActivity::rename() (ten sam strażnik co przy
// kasowaniu, findSoloForUser), nie tutaj, żeby nie duplikować reguły.
//
// Pusta nazwa CZYŚCI własną nazwę (wraca się do nazwy z licznika / opisu
// generycznego) — to jest normalne wyjście z edycji, nie błąd walidacji.
$router->post('/api/rides/{id}/nazwa', function (string $id) {
    header('Content-Type: application/json; charset=utf-8');
    $user = Auth::user();

    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się, żeby zmienić nazwę.')]);
        return;
    }
    if (!Core\Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }

    $nazwa = trim((string) ($_POST['nazwa'] ?? ''));
    if (mb_strlen($nazwa) > \Models\RiderActivity::NAME_MAX_LENGTH) {
        http_response_code(400);
        echo json_encode(['error' => 'Za długa nazwa (maks. ' . \Models\RiderActivity::NAME_MAX_LENGTH . ' znaków).']);
        return;
    }

    $ok = \Models\RiderActivity::rename((int) $id, $user->id, $nazwa);
    if (!$ok) {
        // Nie zdradzamy różnicy między "nie ma", "nie Twoje" i "nie solo" —
        // ta sama zasada co przy 404 wyżej, tu 403, bo user jest zalogowany
        // i CSRF się zgadza (rozpoznajemy więc, że próbuje czegoś, nie że
        // stracił sesję).
        http_response_code(403);
        echo json_encode(['error' => __('Nie można zmienić nazwy tego przejazdu.')]);
        return;
    }

    $ride = \Models\RiderActivity::findForPage((int) $id);
    echo json_encode([
        'name' => $ride !== null ? (string) $ride['name'] : $nazwa,
        // Nazwa, na którą WRACA pole po wyczyszczeniu — bez tego JS musiałby
        // zgadywać (nazwa z licznika? generyczny opis? który?) zamiast po
        // prostu przeładować to, co faktycznie wyliczył serwer.
        'displayName' => $ride !== null
            ? \Controllers\RideController::rideName($ride)
            : $nazwa,
    ]);
});

// To samo, ale dla ŚLADU WYJAZDU wskazanego po `edition_tracks.id` — wpisy
// „Aktywności" pod mapą na profilu rowerzysty sterują mapą właśnie tym id
// (`data-track`, `$trackUrlsById` w RiderController).
//
// BEZ BRAMKI, i to jest świadome: ślad turnusu opisuje imprezę ogłoszoną
// publicznie, rysuje go strona wydarzenia każdemu, kto na nią wejdzie, a sam
// plik leży pod publicznym adresem w `gpx/`. Ten endpoint nie odsłania więc
// niczego, czego nie widać było wcześniej — oddaje tylko mniej (samą linię).
// Ślad solo tędy NIE WYCHODZI: solo nie ma wiersza w `edition_tracks`, dopóki
// właściciel sam nie powiąże go z wyjazdem (`RiderActivity::linkSoloToEdition`).
$router->get('/api/tracks/{id}/geometry', function (string $id) {
    $track = \Models\EditionTrack::find((int) $id);
    if ($track === null || empty($track['gpx_url'])) {
        http_response_code(404);
        return;
    }

    $packed = \Models\GpxGeometry::packedForFile(\Models\TileSource::absolutePath((string) $track['gpx_url']));
    if ($packed === null) {
        http_response_code(404);
        return;
    }

    echo json_encode($packed);
});

// `KnownRoute::geometryInBounds()` ZOSTAJE w modelu — nie ma już czytelnika po
// stronie HTTP, ale opisuje regułę „które trasy wchodzą w kadr" i pilnują jej
// testy. Skasowanie jej przy okazji zmiany sposobu rysowania byłoby wyrzuceniem
// sprawdzonej wiedzy.

// SKARBY W KADRZE (Etap 8D, SKA/8) — warstwa 💎 na mapie odkryć.
//
// Zwraca DOKŁADNE współrzędne, nie środek pola: skarb ma być do znalezienia
// w terenie, a zaokrąglenie do heksa znaczyłoby „gdzieś w tym pół kilometra".
// Pole (`cell_id`) jedzie osobno, pod przyszłe ukrywanie skarbów w polu (SKA/7).
//
// Flaga `mine` mówi, czy PYTAJĄCY już go ma — mapa musi pokazywać postęp,
// inaczej gra nie ma widocznego celu. NIE zdradzamy, kto jeszcze go znalazł:
// to byłoby wskazanie, kto gdzie bywa (§27).
/**
 * ADRESY ZDJEC SKLADAMY TUTAJ, NA GRANICY JSON-A (2026-08-22).
 *
 * Zgloszenie usera: „nie pojawia sie w dymku — czemu nie korzystasz z budowania
 * url i odpowiedniej wielkosci?!". Racja i to byl blad w dwoch miejscach naraz:
 *
 *   1. BASE_PATH. W bazie leza adresy wzgledne aplikacji („/assets/uploads/..."),
 *      a aplikacja stoi w podkatalogu („/ridemore"). Surowy adres w odpowiedzi
 *      dawal w przegladarce 404 — ZMIERZONE. Dotyczylo to takze `photo_url`,
 *      czyli zdjecia glownego: bylo zepsute od zawsze i nikt tego nie widzial,
 *      bo zaden skarb nie mial zdjecia (patrz migr. 066).
 *   2. ROZMIAR. Kafelek w dymku ma 110 px wysokosci, a szedl do niego oryginal
 *      (1200x900, ~230 kB). Wariant `thumb` to 320 px i ~19 kB.
 *
 * DLACZEGO TU, A NIE W MODELU: `Models\Treasure` nie zna ani base_path, ani
 * presetow obrazkow i nie ma prawa ich znac — to jest wiedza warstwy widoku.
 * DLACZEGO NIE W JS: przegladarka tym bardziej nie ma skad wiedziec, gdzie stoi
 * aplikacja ani jakie warianty istnieja na dysku.
 *
 * `thumb` I `full` OSOBNO, bo to dwa rozne zastosowania: kafelek pokazuje mala
 * wersje, a lightbox otwiera oryginal. Wysylanie jednego adresu zmuszaloby JS
 * do sklejania nazwy wariantu, czyli do zgadywania regul `Utils\Image`.
 */
$adresyZdjec = static function (array $t): array {
    // `reveal()` mogl je wyzerowac (Trop/Ukryty przed znalezieniem) — wtedy
    // nie ma czego skladac i null musi zostac nullem.
    if (!empty($t['photo_url'])) {
        // Surowy adres bierzemy PRZED nadpisaniem — `photo_url` po tej linii
        // jest juz wariantem `thumb` i nie da sie z niego odtworzyc oryginalu
        // bez znajomosci regul nazewnictwa `Utils\Image`.
        $surowy = (string) $t['photo_url'];
        $t['photo_url']  = Utils\Image::src($surowy, 'thumb');
        $t['photo_full'] = Utils\View::url($surowy);
    }
    if (!empty($t['photos'])) {
        $t['photos'] = array_map(static fn(string $u): array => [
            'thumb' => Utils\Image::src($u, 'thumb'),
            'full'  => Utils\View::url($u),
        ], $t['photos']);
    }
    // Adres strony regionu pod link w dymku skarbu (2026-09-14, „wszędzie da
    // się kliknąć w region"). Po nazwie, bo zapytania skarbów niosą podpis.
    if (!empty($t['region_label'])) {
        $regionPath = Models\Region::pathForName((string) $t['region_label']);
        $t['region_url'] = $regionPath !== null ? Utils\View::url($regionPath) : null;
    }
    return $t;
};

$router->get('/api/treasures', function () use ($adresyZdjec) {
    $bounds = [];
    foreach (['north', 'south', 'east', 'west'] as $key) {
        if (!is_numeric($_GET[$key] ?? null)) {
            http_response_code(400);
            echo json_encode(['error' => __('Brak zakresu mapy')]);
            return;
        }
        $bounds[$key] = (float) $_GET[$key];
    }

    // MAPA PROFILU: `rider={slug}` zawęża do skarbów znalezionych przez TĘ osobę.
    //
    // Slug przechodzi przez Support::visibleRider — tę samą bramkę co warstwa
    // pól (scope=rider) i sama strona profilu. Bez tego dałoby się wyciągnąć
    // kolekcję kogoś, kto wypisał się z list, samym zapytaniem do API.
    //
    // Poziom ujawnienia liczy się dalej względem PYTAJĄCEGO, nie właściciela
    // profilu — patrz Models\Treasure::inBounds.
    $foundBy = null;
    if (is_string($_GET['rider'] ?? null) && $_GET['rider'] !== '') {
        $rider = Support::visibleRider($_GET['rider']);
        if ($rider === null) {
            http_response_code(404);
            echo json_encode(['error' => __('Nie znaleziono rowerzysty')]);
            return;
        }
        $foundBy = $rider->id;
    }

    // PĘCZKI PRZY ODDALENIU, pojedyncze pinezki przy zbliżeniu.
    //
    // Próg 11 nie jest przypadkowy: przy tym powiększeniu pole siatki (~500 m)
    // ma na ekranie kilkanaście pikseli, czyli mniej niż sama pinezka — poniżej
    // tego punktu pojedyncze skarby fizycznie nie mieszczą się obok siebie
    // i ich rysowanie jest wyłącznie kosztem.
    //
    // Poziom agregacji rośnie razem z oddaleniem, tak samo jak przy heksach:
    // im dalej, tym większe pole zbiera pęczek.
    $zoom = is_numeric($_GET['zoom'] ?? null) ? (int) $_GET['zoom'] : 12;

    // KTÓRE SKARBY — zdobyte, niezdobyte czy jedne i drugie (2026-08-20,
    // zgłoszenie usera o osobnych warstwach). Rozróżnienie robi SERWER, mimo
    // że przełącznik stoi w przeglądarce: przy oddaleniu odpowiedź to pęczki,
    // czyli same liczby, a z „2 skarby, masz 1" nie da się po zgaszeniu jednej
    // warstwy zrobić pojedynczej pinezki. Filtr przed grupowaniem daje poprawny
    // wynik w każdym stanie przełączników — łącznie z regułą „pęczek zaczyna
    // się od dwóch". Wartość spoza słownika = brak filtra, jak przy każdym
    // innym parametrze z adresu.
    $mine = match ($_GET['stan'] ?? '') {
        'moje'  => 'only',
        'nowe'  => 'not',
        default => null,
    };
    // CZYJE ZNALEZISKA LICZY `stan` (Etap 3, tasks/done/warstwy-mapy.md,
    // 2026-08-26) — na mapie SPOŁECZNOŚCI (`scope=all`) „odkryte"/„nieodkryte"
    // mają znaczyć „przez KOGOKOLWIEK", nie „przez pytającego"; wszędzie
    // indziej zachowanie jest jak dotąd. `$foundBy !== null` (mapa profilu,
    // `rider=`) wygrywa zawsze — to już jest zawężenie do JEDNEJ osoby, więc
    // dokładanie do tego jeszcze filtra społeczności nie ma sensu (dziś się
    // nie zdarza: `MapLayer` nie daje profilowi dzieci „Skarbów", patrz
    // migracja 072, ale bramka kosztuje jedno porównanie).
    $mineScope = ($_GET['scope'] ?? '') === 'all' && $foundBy === null ? 'community' : 'viewer';

    if ($zoom < 11) {
        $res = match (true) {
            $zoom <= 6  => 0,
            $zoom <= 8  => 1,
            $zoom <= 9  => 2,
            default     => 3,
        };

        // Pęczki I pojedyncze skarby w jednej odpowiedzi: pole z jednym
        // skarbem nie jest pęczkiem (patrz Models\Treasure::clustersInBounds),
        // więc wraca gotowe do narysowania zwykłą pinezką — z prawdziwą
        // pozycją zamiast środka pola.
        $dane = Models\Treasure::clustersInBounds(
            $bounds, $res, Core\Auth::user()?->id, $foundBy, $mine, $mineScope
        );

        echo json_encode([
            'clustered' => true,
            'clusters'  => $dane['clusters'],
            'treasures' => array_map($adresyZdjec, $dane['singles']),
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'clustered' => false,
        'treasures' => array_map(
            $adresyZdjec,
            Models\Treasure::inBounds($bounds, Core\Auth::user()?->id, 300, $foundBy, $mine, $mineScope)
        ),
    ], JSON_UNESCAPED_UNICODE);
});

// JEDEN SKARB PO ID — pod klikniecie we wpis aktywnosci na profilu.
//
// Osobny endpoint, a nie wspolrzedne wprost we wpisie feedu, i to jest tu
// decydujace: feed renderuje sie na PUBLICZNEJ stronie, wiec wstawienie w niego
// lat/lon zdradzaloby dokladne polozenie skarbow UKRYTYCH (reveal_level 0/1),
// ktore warstwa mapy celowo przycina do srodka pola. Tedy pozycja idzie przez
// dokladnie te sama bramke co reszta — Models\Treasure::inBounds.
$router->get('/api/treasures/{id}', function (string $id) use ($adresyZdjec) {
    header('Content-Type: application/json; charset=utf-8');

    $treasure = Models\Treasure::find((int) $id);
    if ($treasure === null) {
        http_response_code(404);
        echo json_encode(['error' => __('Nie ma takiego skarbu')]);
        return;
    }

    // Ramka wokol prawdziwego miejsca jest tylko po to, zeby trafic w ten wiersz
    // przez inBounds — zwracany punkt i tak jest przyciety wedlug ujawnienia.
    $rows = Models\Treasure::inBounds([
        'north' => (float) $treasure['lat'] + 0.001,
        'south' => (float) $treasure['lat'] - 0.001,
        'east'  => (float) $treasure['lon'] + 0.001,
        'west'  => (float) $treasure['lon'] - 0.001,
    ], Core\Auth::user()?->id, 50);

    foreach ($rows as $row) {
        if ((int) $row['id'] === (int) $id) {
            // GALERIA (SKA/14) — doczytywana DOPIERO TUTAJ, przy pojedynczym
            // skarbie, nie w `inBounds`: mapa oddaje do 300 punktow i galerie
            // wszystkich bylyby setkami adresow, z ktorych nikt nie oglada
            // ani jednego, dopoki czegos nie kliknie.
            //
            // BRAMKA JEST JEDNA I JUZ ZADZIALALA. `reveal()` ustawil
            // `photos_count` na null wszystkiemu, czego pytajacy nie ma prawa
            // widziec (Trop i Ukryty przed znalezieniem) — wiec sprawdzamy
            // WYNIK tamtej decyzji zamiast powtarzac ja tu drugi raz. Gdyby
            // ten warunek rozjechal sie z `reveal()`, galeria wyciekalaby
            // zagadke tylnymi drzwiami.
            $row['photos'] = ((int) ($row['photos_count'] ?? 0) > 0)
                ? array_column(Models\TreasurePhoto::forTreasure((int) $id), 'url')
                : [];
            echo json_encode(['treasure' => $adresyZdjec($row)], JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    // Skarb istnieje, ale pytajacy nie ma prawa go widziec (ukryty w polu,
    // ktorego nie odkryl). Ten sam 404 co dla nieistniejacego — brak odpowiedzi
    // nie moze zdradzac, ze cos tam jednak jest.
    http_response_code(404);
    echo json_encode(['error' => __('Nie ma takiego skarbu')]);
});

// ZALICZENIE Z LOKALIZACJI (SKA/6) — droga bez wlepki.
//
// POST, nie GET, i to nie jest formalność: to zapis, który przyznaje punkty.
// SKARBY POD ALERT „ZBLIŻASZ SIĘ" W APCE (Etap 5b przebudowy apki, 2026-08-28)
// — apka pobiera tę listę RAZ przy starcie nagrywania w tle i dalej liczy
// odległość LOKALNIE, na urządzeniu (pozycja nie może wychodzić na serwer,
// gdy apka jest w tle). Dlatego endpoint oddaje więcej niż `/api/treasures`
// (patrz `Models\Treasure::nearbyForAlerts` — jedyne miejsce z celowym
// wyjątkiem od bramki `knownCells`), ale TYLKO zalogowanemu i TYLKO wąski
// zestaw pól — to nie jest zamiennik dymka na mapie.
// BRAKUJĄCE POLA TRAS W OKOLICY (Etap 1a programu zachęt, 2026-09-11) —
// bliźniak `nearby-treasures`, z którego apka korzysta od Etapu 5b. Ta sama
// droga: apka pobiera raz na prostokąt, a potem liczy odległości u siebie,
// bez strumieniowania pozycji na serwer.
//
// Oddajemy WYŁĄCZNIE pola tras, które ten człowiek już zaczął — patrz
// `KnownRoute::gapsNearbyForUser()`, gdzie stoi, dlaczego to jest zasada,
// a nie filtr wydajnościowy.
$router->get('/api/discovery/nearby-gaps', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Core\Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się.')]);
        return;
    }
    $bounds = [];
    foreach (['north', 'south', 'east', 'west'] as $key) {
        if (!is_numeric($_GET[$key] ?? null)) {
            http_response_code(400);
            echo json_encode(['error' => __('Brak zakresu mapy')]);
            return;
        }
        $bounds[$key] = (float) $_GET[$key];
    }
    echo json_encode([
        'gaps' => Models\KnownRoute::gapsNearbyForUser($user->id, $bounds),
    ], JSON_UNESCAPED_UNICODE);
});

$router->get('/api/discovery/nearby-treasures', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Core\Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się.')]);
        return;
    }
    $bounds = [];
    foreach (['north', 'south', 'east', 'west'] as $key) {
        if (!is_numeric($_GET[$key] ?? null)) {
            http_response_code(400);
            echo json_encode(['error' => __('Brak zakresu mapy')]);
            return;
        }
        $bounds[$key] = (float) $_GET[$key];
    }
    echo json_encode([
        'treasures' => Models\Treasure::nearbyForAlerts($bounds, $user->id),
    ], JSON_UNESCAPED_UNICODE);
});

// GET-em przeglądarka albo prefetch mogłyby zaliczyć skarb bez wiedzy
// użytkownika, a odwrócić się tego nie da (rejestr punktów jest niezmienny).
//
// CSRF sprawdzamy jak przy każdym innym zapisie. Odległość weryfikuje serwer
// na podstawie WŁASNYCH współrzędnych skarbu — przysłane lat/lon są deklaracją
// pozycji telefonu, nie wynikiem sprawdzenia.
$router->post('/api/treasures/claim', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Core\Auth::user();

    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się, żeby zbierać skarby.')]);
        return;
    }
    if (!Core\Csrf::check($_POST['csrf_token'] ?? null)) {
        // 403, nie 419: to drugie jest wymyslem Laravela, a PHP nie przepuszcza
        // go przez http_response_code i konczy sie 500 — czyli klient dostaje
        // „awaria serwera" zamiast „odswiez strone" (zmierzone).
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    if (!is_numeric($_POST['lat'] ?? null) || !is_numeric($_POST['lon'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => __('Brak lokalizacji.')]);
        return;
    }

    $result = Models\Treasure::claimNearby($user->id, (float) $_POST['lat'], (float) $_POST['lon']);

    // ODPOWIEDŹ NIESIE TREŚĆ, NIE TYLKO WYNIK (2026-08-29, prośba usera:
    // „jak mam widok i tam wszystko opisane co to jest, fajnie wiedzieć —
    // tak jakby przewodnik turystyczny"). Te skarby SĄ JUŻ ZNALEZIONE przez
    // pytającego — nagroda właśnie została przyznana — więc pełen opis i kod
    // nie omijają tu żadnej bramki ujawnienia; ta pilnuje skarbów, których
    // ktoś jeszcze NIE znalazł. `code` służy wyłącznie do złożenia adresu
    // pełnego ekranu skarbu (`/skarb/{code}`) i z tego samego powodu NIE
    // wychodzi listą pod alerty — tam skarby są jeszcze nieznalezione.
    echo json_encode([
        'claimed' => array_map(static fn(array $c): array => [
            'name'        => $c['treasure']['name'],
            'points'      => $c['points'],
            'code'        => $c['treasure']['code'],
            'description' => $c['treasure']['description'],
            'category'    => $c['treasure']['category_label'] ?? null,
        ], $result['claimed']),
        'already' => $result['already'],
        'points'  => array_sum(array_column($result['claimed'], 'points')),
    ], JSON_UNESCAPED_UNICODE);
});

// POTWIERDZENIE ZGLOSZONEGO PUNKTU (SKA/3).
//
// WYMAGAMY LOKALIZACJI W PROMIENIU, tak samo jak przy zaliczaniu. Potwierdzenie
// z kanapy nie jest potwierdzeniem — cala wartosc tego glosu bierze sie stad,
// ze ktos tam FIZYCZNIE dojechal i zobaczyl, ze punkt istnieje. Bez tego
// warunku trzy klikniecia znajomych aktywowalyby dowolna bzdure.
$router->post('/api/treasures/confirm', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Core\Auth::user();

    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się, żeby potwierdzać punkty.')]);
        return;
    }
    if (!Core\Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    if (!is_numeric($_POST['id'] ?? null) || !is_numeric($_POST['lat'] ?? null) || !is_numeric($_POST['lon'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => __('Brak punktu albo lokalizacji.')]);
        return;
    }

    $result = Models\Treasure::confirmNearby(
        (int) $_POST['id'], $user->id, (float) $_POST['lat'], (float) $_POST['lon']
    );

    echo json_encode([
        'ok'        => $result['ok'],
        'count'     => $result['count'],
        'needed'    => $result['needed'],
        'activated' => $result['activated'],
        'error'     => match ($result['reason']) {
            'za_daleko'         => __('Jesteś za daleko — potwierdzić można tylko na miejscu.'),
            'wlasny'            => __('Swojego zgłoszenia nie potwierdzasz.'),
            'juz_potwierdzone'  => __('Ten punkt już potwierdziłeś.'),
            'nie_do_glosowania' => __('Ten punkt nie czeka na potwierdzenia.'),
            'nieznany'          => __('Nie ma takiego punktu.'),
            default             => null,
        },
    ], JSON_UNESCAPED_UNICODE);
});

$router->get('/api/discovery/cells', function () {
    $bounds = [];
    foreach (['north', 'south', 'east', 'west'] as $key) {
        if (!is_numeric($_GET[$key] ?? null)) {
            http_response_code(400);
            echo json_encode(['error' => __('Brak zakresu mapy')]);
            return;
        }
        $bounds[$key] = (float) $_GET[$key];
    }

    // Poziom agregacji dobierany z oddalenia mapy. Przy pełnym przybliżeniu
    // pokazujemy pojedyncze pola, przy oddaleniu — plamy terenu.
    //
    // Progi są sprzężone z rozmiarem pola (Utils\DiscoveryGrid::SIZES_M): pole
    // ma ok. 500 m, więc dopiero od zoomu 12 mieści się w kadrze rozsądna ich
    // liczba (ok. 1200 px ekranu to wtedy ok. 28 km, czyli ok. 55 pól w
    // poprzek). Zejście o jeden poziom niżej czterokrotnie zwiększa liczbę
    // wielokątów — przy zmianie rozmiaru pola TE PROGI TEŻ trzeba przesunąć.
    $zoom = (int) ($_GET['zoom'] ?? 12);
    $res = match (true) {
        $zoom >= 12 => 4,
        $zoom >= 10 => 3,
        $zoom >= 8  => 2,
        $zoom >= 6  => 1,
        default     => 0,
    };

    // Trzy zakresy: 'all' (wspólna heatmapa), 'me' (moja mapa, za logowaniem),
    // 'rider' (mapa konkretnej osoby na jej publicznym profilu).
    $scope = $_GET['scope'] ?? 'all';
    $onlyUserId = null;

    if ($scope === 'me') {
        if (!Auth::check()) {
            http_response_code(403);
            echo json_encode(['error' => __('Wymagane logowanie')]);
            return;
        }
        $onlyUserId = Auth::user()->id;
    } elseif ($scope === 'rider') {
        // DOKŁADNIE ta sama reguła widoczności co strona profilu — jedna
        // definicja w Support::visibleRider(). Bez tego dałoby się wyciągnąć
        // mapę osoby, która wyłączyła się z list (migr. 037), samym zapytaniem
        // do API. 404, nie 403: nie zdradzamy, czy konto istnieje.
        $rider = Support::visibleRider(is_string($_GET['slug'] ?? null) ? $_GET['slug'] : null);
        if ($rider === null) {
            http_response_code(404);
            echo json_encode(['error' => __('Nie znaleziono rowerzysty')]);
            return;
        }
        $onlyUserId = $rider->id;
    }

    $cells = Discovery::communityCells($bounds, $res, $onlyUserId);

    // Obie skale są WZGLĘDNE — najsilniejsze pole w bieżącym kadrze wyznacza
    // górę skali, osobno dla każdej warstwy. Muszą takie być, bo intensywność
    // zmienia się o rzędy wielkości między poziomami agregacji (przy pełnym
    // oddaleniu jedno pole sumuje setki przejazdów, przy przybliżeniu
    // pojedyncze). Sztywne progi wysycałyby całą mapę na jednym kolorze przy
    // oddaleniu i zostawiały ją jednolicie bladą przy przybliżeniu.
    $maxRiders = 0;
    $maxPasses = 0;
    foreach ($cells as $cell) {
        $maxRiders = max($maxRiders, $cell['riders']);
        $maxPasses = max($maxPasses, $cell['passes']);
    }

    echo json_encode([
        'res'   => $res,
        // Promień opisany pola w metrach Mercatora — przeglądarka rysuje z
        // niego sześciokąt wokół środka (patrz assets/js/discovery-map.js).
        'sizeM' => DiscoveryGrid::sizeM($res),
        // Dwie warstwy, dwie niezależne skale: 'max' = ilu ludzi (mgła),
        // 'maxP' = ile przejazdów z powtórzeniami (heatmapa).
        'max'   => $maxRiders,
        'maxP'  => $maxPasses,
        'cells' => array_map(function (array $cell) {
            [$lat, $lon] = DiscoveryGrid::cellCenter($cell['cellId']);
            // Klucze jednoliterowe i zaokrąglenie do 5 miejsc (ok. 1 m) —
            // przy kilku tysiącach pól nazwy pól JSON-a ważą więcej niż dane.
            return [
                'a' => round($lat, 5),
                'o' => round($lon, 5),
                'r' => $cell['riders'],
                'p' => $cell['passes'],
            ];
        }, $cells),
    ], JSON_UNESCAPED_UNICODE);
});

// Etap 2 (dopasowania), krok 3: podpowiedzi na żywo podczas wypełniania
// formularza wydarzenia, PRZED zapisem — Models\MatchEngine::forDraft()
// ocenia dane wpisane właśnie w polach (region/daty/typy roweru/punkt
// zbiórki) na tle już opublikowanych wydarzeń. GET + bez CSRF, tak jak
// /api/events/map wyżej — to odczyt bez efektów ubocznych, wywoływany co
// chwilę (debounced) przy każdej zmianie pola, nie pojedynczy submit.
$router->get('/api/matches/preview', function () {
    $bikeTypes = array_values(array_filter((array) ($_GET['bikeTypes'] ?? []), 'is_string'));
    $startDate = (string) ($_GET['startDate'] ?? '');
    if ($startDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        echo json_encode(['matches' => [], 'wideningLabel' => null]);
        return;
    }

    $criteria = [
        'startDate'       => $startDate,
        'endDate'         => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['endDate'] ?? '')) ? $_GET['endDate'] : null,
        'regionItemId'    => Dictionary::id('region', $_GET['region'] ?? null),
        'meetingPointLat' => is_numeric($_GET['lat'] ?? null) ? (float) $_GET['lat'] : null,
        'meetingPointLng' => is_numeric($_GET['lng'] ?? null) ? (float) $_GET['lng'] : null,
        'paceGroupItemId' => Dictionary::id('pace_group', $_GET['pace'] ?? null),
        'bikeTypeCodes'   => $bikeTypes,
        'excludeEventId'  => ctype_digit((string) ($_GET['excludeEventId'] ?? '')) ? (int) $_GET['excludeEventId'] : null,
    ];

    $viewerId = Auth::check() ? Auth::user()->id : null;
    $criteria['userId'] = $viewerId;

    $result = MatchEngine::forDraft($criteria, 3);

    // Etap 3 §8 — dziennik rekomendacji, zapisany tu (kontroler), nie w
    // MatchEngine (patrz Models\RecommendationLog). isExploration/isAspirational
    // domyślnie false do czasu wpięcia profilu (krok 6 Etapu 3) — wtedy
    // MatchEngine zacznie oznaczać te pozycje wprost w wyniku.
    foreach ($result['matches'] as $i => $m) {
        RecommendationLog::record($viewerId, $m['eventId'], 'form', $m['score'], $i + 1, $m['isExploration'] ?? false, $m['isAspirational'] ?? false, $m['derivedShare'] ?? 0.0);
    }

    echo json_encode([
        'matches' => array_map(fn($m) => [
            'eventId'        => $m['eventId'],
            'title'          => $m['title'],
            'url'            => View::url('/events/' . $m['slug']),
            'classification' => $m['classification'],
            'reason'         => $m['reason'],
            // Samodzielna fraza (np. "97 km od Twojego startu"), bez
            // przedrostka "Do uzgodnienia: oś" — patrz ten sam zabieg w
            // Resources\MatchCardResource::fromMatches().
            'alignLabel'     => $m['alignDetail'],
            'profileJustification' => $m['profileJustification'],
        ], $result['matches']),
        'wideningLabel' => MatchEngine::wideningLabel($result['wideningLevel']),
    ], JSON_UNESCAPED_UNICODE);
});

// Etap 3 (preferencje), krok 7: odrzucenie sugestii, trzystopniowe
// (docs/etap3 §6). Jedno wywołanie obsługuje oba pierwsze stopnie —
// $_POST['reason'] puste = stopień 1 (tylko to wydarzenie, bez kary na oś),
// wypełnione (drugie, opcjonalne kliknięcie z tego samego widgetu) =
// stopień 2 (słaba kara na oś, patrz Models\DerivedPreference). Wymaga
// logowania — odrzucenie jest per-user, bez konta nie ma gdzie go zapisać
// (patrz FK NOT NULL na recommendation_dismissals.user_id).
$router->post('/api/matches/dismiss', function () {
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['error' => __('Wymagane logowanie')]);
        return;
    }
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła, odśwież stronę')]);
        return;
    }
    $eventId = (int) ($_POST['eventId'] ?? 0);
    if ($eventId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => __('Brak eventId')]);
        return;
    }
    $reason = ($_POST['reason'] ?? '') !== '' ? (string) $_POST['reason'] : null;

    $userId = Auth::user()->id;
    RecommendationDismissal::record($userId, $eventId, $reason);
    RecommendationLog::recordOutcome($userId, $eventId, 'dismissed', $reason);
    echo json_encode(['ok' => true]);
});

$router->get('/api/events/{slug}', function (string $slug) {
    $event = Event::findBySlugOrFailJson($slug);
    if (!$event) return;
    echo json_encode(EventResource::fromModel($event));
});

$router->post('/api/events/{slug}/rsvp', function (string $slug) {
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['error' => __('Wymagane logowanie')]);
        return;
    }
    if (!Csrf::check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        // 419 (konwencja Laravela na "sesja wygasła") nie jest oficjalnym kodem
        // HTTP — Apache/mod_php go nie rozpoznaje i podmienia na 500, patrz
        // ta sama poprawka w /api/gpx/parse niżej. 403 jest bezpieczne i realne.
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła, odśwież stronę')]);
        return;
    }
    $event = Event::findBySlugOrFailJson($slug);
    if (!$event) return;
    if (!in_array($event->statusCode, ['published', 'full'], true)) {
        http_response_code(409);
        echo json_encode(['error' => __('To wydarzenie nie przyjmuje już zapisów.')]);
        return;
    }
    // Płatne eventy wewnętrzne muszą przejść przez /wydarzenia/{slug}/zapisz
    // (profil rozliczeniowy + status 'oczekuje_platnosci', patrz
    // Support::requirePayableInternalEvent()) — EventRsvp::join() niżej
    // ustawia od razu 'potwierdzony', co dla płatnego eventu ominęłoby płatność.
    if ($event->pricing && $event->registrationTypeCode === 'internal') {
        http_response_code(409);
        echo json_encode(['error' => __('To wydarzenie wymaga rezerwacji z płatnością — użyj formularza na stronie wydarzenia.')]);
        return;
    }

    // Który turnus — patrz event-page.php (eventPage() Alpine, "Wybierz
    // termin"); $_GET bo fetch() dokleja edition_id jako query string nawet
    // przy POST. Brak/zły edition_id -> domyślny (najbliższy nadchodzący).
    $edition = Support::resolveEdition($event, $_GET['edition_id'] ?? null);

    $user = Auth::user();
    $status = EventRsvp::join($edition->id, $user->id);

    if ($user->id !== $event->organizerId) {
        $organizerUser = User::find($event->organizerId);
        if ($organizerUser) {
            \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizerUser))), static fn() => Mailer::sendTemplate('new-signup', Organizer::notificationEmail($organizerUser), __('Nowy zapis: {tytul}', ['tytul' => $event->title]), [
                'recipientName'     => $organizerUser->displayName(),
                'participantName'   => $user->name ?: $user->email,
                'eventTitle'        => $event->title,
                'statusLabel'       => $status === 'lista_rezerwowa' ? __('trafił(a) na listę rezerwową') : __('potwierdzony udział'),
                'participantsLink'  => View::absoluteUrl('/wydarzenia/' . $slug . '/uczestnicy'),
            ]));
        }
    }

    echo json_encode(['status' => $status]);
});

// Szukajka istniejących organizatorów pod formularz "zgłoś wydarzenie w
// czyimś imieniu" (patrz /wydarzenia/nowe) — CELOWO bez Auth::check(),
// formularz jest dostępny też dla niezalogowanych gości.
$router->get('/api/organizers/search', function () {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode([]);
        return;
    }
    // Dwa osobne placeholdery — z ATTR_EMULATE_PREPARES=false (prawdziwe
    // prepared statements) MySQL nie pozwala użyć tego samego nazwanego
    // parametru dwa razy w jednym zapytaniu ("Invalid parameter number").
    $stmt = Database::connection()->prepare("
        SELECT u.id, u.name, u.email
        FROM organizer_profiles op
        JOIN users u ON u.id = op.user_id
        WHERE u.name LIKE :q1 OR u.email LIKE :q2
        ORDER BY u.name
        LIMIT 10
    ");
    $stmt->execute(['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%']);

    // Trasa jest celowo bez logowania (patrz komentarz wyżej) — pełny e-mail
    // pozwalałby na hurtowe zbieranie adresów przez powtarzane zapytania
    // krótkimi frazami. Maskujemy, zostawiając tylko tyle, ile trzeba do
    // odróżnienia dwóch osób o tej samej nazwie.
    $maskEmail = function (string $email): string {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, 2);
        return $visible . str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
    };

    echo json_encode(array_map(function ($row) use ($maskEmail) {
        $masked = $maskEmail($row['email']);
        return [
            'id'    => (int) $row['id'],
            'label' => $row['name'] ? $row['name'] . ' (' . $masked . ')' : $masked,
        ];
    }, $stmt->fetchAll()), JSON_UNESCAPED_UNICODE);
});

// Podpowiedzi do pól typu "wpisz albo wybierz z listy, jeśli nie ma to
// dodamy" — na start pod kategorię pozycji cennika w formularzu eventu
// (patrz Dictionary::resolveOrCreate(), wywoływane dopiero przy zapisie
// eventu, nie tutaj — ta trasa tylko podpowiada, niczego nie tworzy).
// CELOWO bez Auth::check() — formularz eventu jest dostępny też dla
// niezalogowanych gości (patrz /wydarzenia/nowe).
$router->get('/api/dictionaries/{code}/search', function (string $code) {
    echo json_encode(Dictionary::searchItems($code, (string) ($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
});

// Pełny zestaw słowników formularza eventu w JEDNYM wywołaniu — te same dane
// (i ten sam kod, Support::eventFormDictOptions()) co przy renderowaniu
// kreatora /wydarzenia/nowe, tylko jako JSON zamiast <select>/<option> w
// HTML-u. Pod zewnętrzne narzędzia (np. rozszerzenie Chrome "Importer
// eventów"), które muszą dopasować tekst do prawdziwych kodów bez zgadywania
// czy parsowania DOM-u kreatora. CELOWO bez Auth::check() — to te same dane,
// które i tak trafiają w HTML-u na publiczną stronę /wydarzenia/nowe.
$router->get('/api/dictionaries', function () {
    echo json_encode(Support::eventFormDictOptions(), JSON_UNESCAPED_UNICODE);
});

// Dopasowanie istniejącego organizatora po domenie strony WWW — pod
// "Importer eventów": rozszerzenie zeskanowało stronę spoza ridemore.bike i
// zamiast zawsze proponować "nowego organizatora" (patrz EventFormResource,
// organizer_mode=new), sprawdza, czy ta domena jest już czyimś website_url.
// Zwraca 0..N dopasowań (imiennicy/współpracujące kluby mogą mieć tę samą
// domenę) — wybór, którego użyć, zostaje po stronie użytkownika rozszerzenia.
// CELOWO bez Auth::check() z tego samego powodu co /api/organizers/search
// wyżej — formularz zgłoszenia jest dostępny też dla gości.
$router->get('/api/organizers/match-domain', function () {
    $domain = trim((string) ($_GET['domain'] ?? ''));
    if ($domain === '') {
        echo json_encode([]);
        return;
    }
    echo json_encode(Organizer::findByWebsiteDomain($domain), JSON_UNESCAPED_UNICODE);
});

// Wywoływane z formularza dodawania/edycji eventu przy wyborze pliku GPX —
// od razu zwraca policzony dystans/przewyższenie, żeby pola w formularzu
// wypełniły się bez czekania na cały submit. CELOWO bez Auth::check() —
// formularz jest dostępny też dla niezalogowanych gości (patrz /wydarzenia/nowe).
$router->post('/api/gpx/parse', function () {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        // 419 nie jest oficjalnym kodem HTTP — Apache/mod_php go nie rozpoznaje
        // i realnie zwraca 500 zamiast tego (potwierdzone bezpośrednim testem).
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła, odśwież stronę')]);
        return;
    }
    if (empty($_FILES['gpx'])) {
        http_response_code(400);
        echo json_encode(['error' => __('Brak pliku')]);
        return;
    }

    // ZWOLNIENIE BLOKADY SESJI — krytyczne przy WIELU WARIANTACH TRASY
    // (zgłoszenie usera 2026-08-09: przy 3 wariantach nawierzchnia wykrywała
    // się tylko dla jednego). PHP trzyma plik sesji zablokowany na czas całego
    // żądania, więc równoległe wgrania z tej samej przeglądarki USTAWIAŁY SIĘ
    // W KOLEJKĘ: drugie czekało na pierwsze, trzecie na drugie. A ponieważ
    // licznik max_execution_time tyka też podczas czekania, żądania 2 i 3
    // wywalały się na "Maximum execution time exceeded" ZANIM zdążyły cokolwiek
    // policzyć (odtworzone: 1 z 3 się udawał, 2 kończyły się fatal errorem
    // zwracającym HTML zamiast JSON-a). CSRF jest już sprawdzony wyżej, a dalej
    // nic z sesji nie korzysta — możemy ją bezpiecznie zamknąć i pozwolić
    // żądaniom biec naprawdę równolegle.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    // Detekcja nawierzchni chodzi do zewnętrznego Overpass z celowymi pauzami
    // między kawałkami trasy — domyślne 120 s bywa za mało dla długiej trasy
    // nawet BEZ kolejkowania. Limit czasu samej analizy pilnuje
    // RoadSurfaceDetector (patrz MAX_ANALYSIS_SECONDS), więc to tylko zapas,
    // żeby PHP nie ubił żądania przed nią.
    @set_time_limit(300);

    try {
        $token = Upload::saveGpxTemp($_FILES['gpx']);
        $parsed = Gpx::parse(CORE_PATH . '/../assets/uploads/gpx/tmp/' . $token . '.gpx');

        // Podział nawierzchni (asfalt/gravel/ścieżka) przez Overpass API — WOLNE
        // (kilkanaście-kilkadziesiąt sekund, celowe opóźnienia między kawałkami
        // trasy, patrz RoadSurfaceDetector) i zależne od zewnętrznego serwisu.
        // null jest normalnym wynikiem (offline/rate limit/brak pokrycia OSM) —
        // front wtedy zostaje przy ręcznym wyborze ze słownika (patrz "Mix").
        $surface = RoadSurfaceDetector::analyze($parsed['points'], $parsed['distanceKm']);

        echo json_encode([
            'token'          => $token,
            'distanceKm'     => $parsed['distanceKm'],
            'elevationGainM' => $parsed['elevationGainM'],
            'surface'        => $surface,
        ]);
    } catch (\RuntimeException $e) {
        // Rzucane celowo przez Gpx::parse()/Upload::saveGpxTemp() z bezpieczną,
        // pomocną treścią ("Nieprawidłowy plik GPX", "Trasa zbyt krótka" itd.).
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        // Coś nieoczekiwanego (np. błąd HTTP do Overpass w RoadSurfaceDetector) —
        // nie pokazujemy szczegółów, tylko logujemy (patrz bootstrap.php).
        error_log(sprintf('Błąd /api/gpx/parse: %s w %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
        http_response_code(422);
        echo json_encode(['error' => __('Nie udało się przetworzyć pliku GPX. Spróbuj ponownie.')]);
    }
});

// Most do lokalnego silnika AI (ai-engine/analyze.py, Python) pod rozszerzenie
// Chrome "ridemore-event-importer" (przycisk "🧠 AI-Engine (Groq)") — patrz
// core/Utils/AiEngineBridge.php i md/features.md. Wołane cross-origin z
// rozszerzenia (chrome-extension://...); Chrome zwalnia strony z
// host_permissions=<all_urls> z CORS (ten sam mechanizm, którym rozszerzenie
// od dawna woła /api/dictionaries i /api/organizers/match-domain), więc bez
// nagłówków CORS. CELOWO bez Auth::check()/Csrf::check() z tego samego
// powodu co te dwie trasy (brak sesji/CSRF w kontekście rozszerzenia) — realną
// ochronę daje gate poniżej + limit + to, że most odpala proces na dysku
// developera, nie ma efektów ubocznych w bazie poza dziennikiem.
$router->post('/api/ai/engine-analyze', function () {
    // TYLKO dev — narzędzie lokalne dla organizatora-dewelopera (jak
    // siostrzane bikevents), nigdy funkcja produkcyjna ridemore.bike.
    // Niezależne od tego, czy shared hosting w ogóle pozwala na proc_open.
    if (APP_ENV !== 'dev') {
        http_response_code(404);
        return;
    }
    if (RateLimiter::tooMany('ai-engine:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 300)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => __('Za dużo żądań do silnika AI — odczekaj chwilę.')]);
        return;
    }

    $raw = file_get_contents('php://input');
    $maxBytes = APP_CONFIG['ai_engine']['max_payload_bytes'] ?? 2_000_000;
    if (strlen($raw) > $maxBytes) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'Strona jest za duża do analizy (limit ' . $maxBytes . ' B).']);
        return;
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['sourceUrl'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => __('Brak danych strony (sourceUrl).')]);
        return;
    }

    // "Knowledge base" v1 (patrz AiImportLog::domainSummary, md/features.md) —
    // jeśli tę domenę już wcześniej dobrze przeanalizowaliśmy, dorzucamy to
    // do payloadu jako wskazówkę dla modelu (byle model, Groq czy Ollama —
    // wiedza żyje tu, w DB, nie w konkretnym silniku). Tylko odczyt, zero
    // interpretacji — most (AiEngineBridge) i tak zostaje "głupim" mostem.
    $domain = parse_url($payload['sourceUrl'], PHP_URL_HOST);
    if ($domain) {
        $domain = preg_replace('/^www\./', '', $domain);
        $hint = AiImportLog::domainSummary($domain);
        if ($hint) {
            $payload['knowledgeHint'] = $hint;
        }
    }

    try {
        $result = AiEngineBridge::analyze($payload);
    } catch (\RuntimeException $e) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        return;
    }

    AiImportLog::record($payload['sourceUrl'], $payload, $result);
    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
});

// URZĄDZENIA POD PUSH (Etap 8 przebudowy apki, 2026-08-28) — patrz
// Models\PushDevice. Sesja + CSRF jak przy każdym innym zapisie z apki.
// Wołane z assets/js/native.js: `register` zaraz po tym, jak
// @capacitor/push-notifications odda token; `unregister` z przełącznika
// w koncie (views/web/pages/account.php).
$router->post('/api/devices/register', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się.')]);
        return;
    }
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    $platform = (string) ($_POST['platform'] ?? '');
    $token = (string) ($_POST['token'] ?? '');
    if (!in_array($platform, ['android', 'ios'], true) || $token === '') {
        http_response_code(400);
        echo json_encode(['error' => __('Brak platformy albo tokenu.')]);
        return;
    }
    Models\PushDevice::register($user->id, $platform, $token);
    echo json_encode(['ok' => true]);
});

$router->post('/api/devices/unregister', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się.')]);
        return;
    }
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    Models\PushDevice::deactivateForUser($user->id);
    echo json_encode(['ok' => true]);
});

// ZGODA NA POJEDYNCZY RODZAJ POWIADOMIEŃ (Etap 0 programu zachęt, 2026-09-11).
//
// Osobno od `/api/devices/*`, bo to inna decyzja: tamte włączają i wyłączają
// URZĄDZENIE (zgoda systemowa + token), ta wybiera, CO na nie przychodzi.
// Zlanie obu w jeden endpoint znaczyłoby, że zgaszenie jednego rodzaju może
// przy okazji wyrejestrować telefon.
//
// Nazwa flagi jest weryfikowana w modelu (`NotificationGate::ustawZgode`
// rzuca na nieznanej), więc nie ma tu drugiej listy do rozjechania.
$router->post('/api/powiadomienia/zgoda', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się.')]);
        return;
    }
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    try {
        Models\NotificationGate::ustawZgode(
            $user->id,
            (string) ($_POST['flaga'] ?? ''),
            ($_POST['wartosc'] ?? '0') === '1'
        );
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => __('Nieznane ustawienie.')]);
        return;
    }
    echo json_encode(['ok' => true, 'zgody' => Models\NotificationGate::zgoda($user->id)]);
});

// POTWIERDZENIE OTWARCIA POWIADOMIENIA (Etap 2 programu zachęt, 2026-09-11).
//
// Apka woła to po tapnięciu w powiadomienie, przekazując `nid` z ładunku push.
// `oznaczOtwarte()` jest IDEMPOTENTNE i sprawdza właściciela wpisu, więc
// powtórzone wywołanie (albo cudzy identyfikator) niczego nie psuje — to ta
// sama bramka anty-IDOR co wszędzie indziej w tym repo.
//
// Odpowiedź jest zawsze `{ok:true}`, także gdy wpis nie istniał: apka nie ma
// co zrobić z błędem pomiaru, a nawigacja do treści powiadomienia jest
// ważniejsza niż statystyka. Cisza jest tu właściwą reakcją.
$router->post('/api/powiadomienia/otwarte', function () {
    header('Content-Type: application/json; charset=utf-8');
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => __('Zaloguj się.')]);
        return;
    }
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    $id = (int) ($_POST['nid'] ?? 0);
    if ($id > 0) {
        Models\NotificationGate::oznaczOtwarte($id, $user->id);
    }
    echo json_encode(['ok' => true]);
});

// WEBHOOKI LICZNIKÓW (migr. 088, 2026-09-14) — automatyczny import przejazdów.
// Woła to SERWER dostawcy (Polar, Wahoo), nie przeglądarka: bez sesji i CSRF,
// za to z podpisem/sekretem sprawdzanym w kontrolerze. Szczegóły i powód, dla
// którego odpowiedź idzie przed importem: Controllers\DeviceWebhookController.
$router->post('/api/liczniki/{provider}/webhook', [\Controllers\DeviceWebhookController::class, 'receive']);

// WYBÓR JĘZYKA Z PRZEŁĄCZNIKA (2026-09-16, tasks/active/wielojezycznosc.md).
// Sam przełącznik jest ZWYKŁYM LINKIEM do tej samej strony w drugim języku —
// działa bez JS i jest widoczny dla robotów. Ten endpoint tylko ZAPAMIĘTUJE
// wybór (ciasteczko + `users.lang` u zalogowanego), żeby maile, pushe i wejście
// na stronę główną szły w nowym języku. POST z CSRF, bo zmienia konto.
$router->post('/api/jezyk', function () {
    $lang = (string) ($_POST['lang'] ?? '');
    if (!\Core\Lang::isSupported($lang)) {
        http_response_code(422);
        echo json_encode(['error' => __('Nieobsługiwany język.')]);
        return;
    }
    \Core\Lang::rememberChoice($lang);
    $user = Auth::user();
    if ($user !== null) {
        if (!Csrf::check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null))) {
            http_response_code(403);
            echo json_encode(['error' => __('Sesja wygasła, odśwież stronę')]);
            return;
        }
        User::setLang($user->id, $lang);
    }
    echo json_encode(['ok' => true, 'lang' => $lang]);
});

// ============================================================================
// ROUTE PLANNER, ETAP 1 (2026-09-17) — closure robi auth/CSRF/dekodowanie
// JSON-a (wzorem /api/treasures/claim i /api/regiony-mapa/zapisz), cała
// logika w Controllers\PlannerController. CSRF w BODY JSON-a (nie
// $_POST['csrf_token']), bo fetch() wysyła surowy JSON, nie form-urlencoded.
// ============================================================================
$router->get('/api/planer/warstwy', function () {
    $bounds = [
        'south' => (float) ($_GET['south'] ?? 0),
        'west'  => (float) ($_GET['west'] ?? 0),
        'north' => (float) ($_GET['north'] ?? 0),
        'east'  => (float) ($_GET['east'] ?? 0),
    ];
    echo json_encode(PlannerController::layers($bounds, Auth::user()?->id), JSON_UNESCAPED_UNICODE);
});

$router->post('/api/planer/oblicz', function () {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => __('Zaloguj się, żeby zaplanować trasę.')]);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];
    if (!Csrf::check($body['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    // Liczenie czeka na rowerowy OSRM (limit: jedno zapytanie na sekundę),
    // więc nowy odcinek trwa sekundy. Sesja jest już tylko czytana — bez
    // zwolnienia blokady kafle mapy i kolejne przeliczenia z tej samej
    // przeglądarki stałyby w kolejce za tym żądaniem.
    Core\Session::release();
    @set_time_limit(120);
    echo json_encode(PlannerController::calculate($body), JSON_UNESCAPED_UNICODE);
});

// KREATOR „gdzie warto pojechać” (Etap A, tasks/active/planer-uproszczona-
// architektura.md) — ta sama obudowa co /oblicz: logowanie, CSRF, zwolnienie
// sesji na czas czekania na rowerowy OSRM i API wysokości.
$router->post('/api/planer/generuj', function () {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => __('Zaloguj się, żeby zaplanować trasę.')]);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];
    if (!Csrf::check($body['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    Core\Session::release();
    @set_time_limit(120);
    echo json_encode(PlannerController::generate($body), JSON_UNESCAPED_UNICODE);
});

$router->post('/api/planer/zapisz', function () {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => __('Zaloguj się, żeby zapisać trasę.')]);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];
    if (!Csrf::check($body['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    echo json_encode(PlannerController::save($user->id, $body), JSON_UNESCAPED_UNICODE);
});

// „Zacznij od istniejącego śladu" (kolejny etap po MVP, 2026-09-17) — te
// dwie trasy MUSZĄ być zarejestrowane PRZED `/api/planer/{id}` niżej
// (wzorzec „szczegółowe przed ogólnym" z web/routes.php), inaczej
// `{id}` przechwyciłby `zrodla`/`zrodlo` jako literalną wartość id.
$router->get('/api/planer/zrodla', function () {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => __('Zaloguj się.')]);
        return;
    }
    $type = (string) ($_GET['typ'] ?? '');
    $q = (string) ($_GET['q'] ?? '');
    echo json_encode(PlannerController::searchSources($user->id, $type, $q), JSON_UNESCAPED_UNICODE);
});

$router->get('/api/planer/zrodlo', function () {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => __('Zaloguj się.')]);
        return;
    }
    $type = (string) ($_GET['typ'] ?? '');
    $ref = (string) ($_GET['ref'] ?? '');
    echo json_encode(PlannerController::sourceGeometry($type, $ref, $user->id), JSON_UNESCAPED_UNICODE);
});

// Multipart (wgranie pliku) — CSRF z POLA FORMULARZA, nie z body JSON-a jak
// reszta plannera, bo fetch() z FormData nie wysyła surowego JSON-a.
$router->post('/api/planer/wgraj-gpx', function () {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => __('Zaloguj się.')]);
        return;
    }
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => __('Sesja wygasła — odśwież stronę.')]);
        return;
    }
    echo json_encode(PlannerController::importGpx($_FILES['gpx'] ?? []), JSON_UNESCAPED_UNICODE);
});

$router->get('/api/planer/{id}', function (string $id) {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => __('Zaloguj się, żeby wczytać trasę.')]);
        return;
    }
    echo json_encode(PlannerController::load((int) $id, $user->id), JSON_UNESCAPED_UNICODE);
});

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
