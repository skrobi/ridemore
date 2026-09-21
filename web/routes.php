<?php
// web/routes.php
// Tylko rejestracja tras — logika przeniesiona do core/Controllers/* (patrz
// Controllers\Support dla współdzielonych helperów). Kolejność rejestracji
// MUSI zostać zachowana tam, gdzie jest to zaznaczone komentarzem: Router
// dopasowuje trasy liniowo, więc bardziej szczegółowy/statyczny wzorzec musi
// być zarejestrowany przed bardziej ogólnym (np. '/organizatorzy' przed
// '/organizatorzy/{slug}').
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Controllers\AuthController;
use Controllers\ChronicleController;
use Controllers\CommentController;
use Controllers\DiscoveryController;
use Controllers\EventController;
use Controllers\EventsListController;
use Controllers\HomeController;
use Controllers\MessageController;
use Controllers\OrganizerController;
use Controllers\PageController;
use Controllers\PlannerController;
use Controllers\PulseController;
use Controllers\RecapController;
use Controllers\RegionController;
use Controllers\RideController;
use Controllers\ReviewController;
use Controllers\RiderController;
use Controllers\SocialAuthController;
use Controllers\RsvpController;
use Controllers\SitemapController;
use Controllers\TileController;
use Controllers\TrackController;
use Controllers\TrailController;
use Controllers\TreasureScanController;
use Controllers\UnsubscribeController;
use Core\Router;

$router = new Router();

$router->get('/', [HomeController::class, 'index']);
$router->get('/jak-to-dziala', [PageController::class, 'howItWorks']);
$router->get('/regulamin', [PageController::class, 'terms']);
$router->get('/prywatnosc', [PageController::class, 'privacy']);
$router->get('/relacje', [PageController::class, 'recaps']);
$router->get('/dla-organizatorow', [PageController::class, 'forOrganizers']);

// Wypis z powiadomień mailowych (Etap 1c programu zachęt, 2026-09-11).
// BEZ LOGOWANIA — uprawnienie niesie podpis HMAC w adresie. GET tylko pyta,
// POST wypisuje: skanery poczty i podglądy linków pobierają adresy z maili
// w tle, więc wypis na GET zgasiłby ludziom powiadomienia bez ich udziału
// (ten sam powód, dla którego POST-only są `/skarb/{code}` i „Byłem").
// POST przyjmuje też dostawca poczty wprost — Gmail/Outlook wysyłają go same
// na podstawie nagłówka `List-Unsubscribe-Post` (RFC 8058).
$router->get('/powiadomienia/wypisz', [UnsubscribeController::class, 'form']);
$router->post('/powiadomienia/wypisz', [UnsubscribeController::class, 'save']);
$router->get('/events/{slug}', [EventController::class, 'show']);

// MUSI być zarejestrowane przed '/wydarzenia/nowe' i innymi '/wydarzenia/*' niżej.
$router->get('/wydarzenia', [EventsListController::class, 'index']);

// MUSI być zarejestrowane przed '/organizatorzy/{slug}' niżej.
$router->get('/organizatorzy', [OrganizerController::class, 'index']);
$router->get('/organizatorzy/{slug}', [OrganizerController::class, 'show']);
$router->post('/organizatorzy/{slug}/weryfikacja', [OrganizerController::class, 'verify']);
$router->post('/organizatorzy/{slug}/przejmij', [OrganizerController::class, 'claim']);
// Publiczny profil rowerzysty (Etap 3). Osobna gałąź od /organizatorzy —
// organizator to podmiot organizujący wyjazd, rowerzysta to uczestnik;
// jedna osoba bywa jednym i drugim, ale to dwie różne strony.
$router->get('/rowerzysta/{slug}', [RiderController::class, 'show']);
// STRONA JEDNEGO PRZEJAZDU (2026-09-03, tasks/done/strona-przejazdu.md).
// Publiczna, ale ślad CUDZEGO przejazdu solo wychodzi na niej przycięty
// o okolice domu (§27) — całą regułę liczy Controllers\Support, ta sama,
// której słucha endpoint `/api/rides/{id}/track`.
// Pobieranie MUSI być zarejestrowane przed wzorcem samej strony: router
// dopasowuje liniowo, a `{id}` nie połknęłoby wprawdzie drugiego segmentu,
// ale kolejność „szczegółowe przed ogólnym" jest tu zasadą, nie wyjątkiem.
$router->get('/przejazd/{id}/gpx', [RideController::class, 'gpx']);
$router->get('/przejazd/{id}', [RideController::class, 'show']);
// Kronika wyjazdu (Etap 4) — per TURNUS (?termin=ID, jak strona wydarzenia).
// Nie ma własnej tabeli: strona jest widokiem na wydarzenie + obecność +
// relacje + zdjęcia, więc „rodzi się wypełniona" bez żadnego crona.
$router->get('/kronika/{slug}', [ChronicleController::class, 'show']);
// „Oznacz kogoś, kto tam był" — wysyła WYŁĄCZNIE mail z linkiem do kroniki.
// Nikogo nie dopisuje do składu: obecność zaznacza sobie sam zaproszony.
$router->post('/kronika/{slug}/oznacz', [ChronicleController::class, 'invite']);
// Puls (Etap 5) — również bez własnej tabeli: wpisy wyprowadzane z obecności,
// zapisów, relacji i wydarzeń (patrz Models\Pulse).
$router->get('/puls', [PulseController::class, 'index']);
// Discovery (Etap 8) — mapa osobista (za logowaniem) i wspólna mapa
// społeczności (publiczna). Wspólna MUSI być zarejestrowana przed '/odkrycia',
// żeby dokładniejszy wzorzec wygrał niezależnie od przyszłych zmian w routerze.
$router->get('/odkrycia/spolecznosc', [DiscoveryController::class, 'community']);
$router->get('/odkrycia', [DiscoveryController::class, 'index']);

// Znane trasy jako byt publiczny (2026-08-13). Katalog PRZED wzorcem ze
// zmienną — router dopasowuje liniowo, więc '/trasy/{slug}' zarejestrowane
// wcześniej połknęłoby '/trasy' jako slug o pustej wartości.
// SKARBY (Etap 8D) — adres z kodu QR na naklejce. GET pokazuje ekran, POST
// dopiero zalicza: samo wejście pod link nie może zabrać skarbu, bo podglądy
// linków w komunikatorach pobierają strony w tle.
$router->get('/skarb/{code}', [TreasureScanController::class, 'show']);
$router->post('/skarb/{code}', [TreasureScanController::class, 'claim']);
// GALERIA SKARBU (SKA/14) — zdjecie dorzuca WYLACZNIE znalazca (guard
// w kontrolerze). Osobne trasy, nie kolejne pole w formularzu zaliczania:
// zdjecie dodaje sie zwykle PO zaliczeniu, przy powrocie na ten ekran.
$router->post('/skarb/{code}/zdjecie', [TreasureScanController::class, 'addPhoto']);
$router->post('/skarb/{code}/zdjecie/usun', [TreasureScanController::class, 'deletePhoto']);

// Zgloszenie punktu przez rowerzysta (SKA/4). Trafia do poczekalni, nie na
// mape jako gotowy skarb — o tym decyduja potwierdzenia (SKA/3).
$router->get('/skarby/zglos', [Controllers\TreasureProposalController::class, 'form']);
$router->post('/skarby/zglos', [Controllers\TreasureProposalController::class, 'save']);

$router->get('/trasy', [TrailController::class, 'index']);
$router->get('/trasy/{slug}', [TrailController::class, 'show']);

// ROUTE PLANNER, ETAP 1 — /planer/{id}/gpx PRZED /planer (bez konfliktu, ale
// zachowuje konwencję "szczegółowe przed ogólnym" z reszty pliku).
$router->get('/planer/{id}/gpx', [PlannerController::class, 'gpx']);
$router->get('/planer', [PlannerController::class, 'index']);

// STRONY REGIONÓW (2026-09-14, tasks/done/strony-regionow.md). Trzy
// poziomy: spis, kraj, region. Kraj bez regionów (Słowacja) jest stroną
// regionu już pod jednym segmentem — rozstrzyga to RegionController::country.
$router->get('/regiony', [RegionController::class, 'index']);
$router->get('/regiony/{kraj}', [RegionController::class, 'country']);
$router->get('/regiony/{kraj}/{region}', [RegionController::class, 'show']);

// KAFLE MAP (migr. 051). Trasa wygląda na pomyłkę — po co router dla czegoś
// pod /assets? — i to jest właśnie sedno: adres kafla jest JEDNOCZEŚNIE ścieżką
// pliku na dysku, więc reguła `RewriteCond %{REQUEST_FILENAME} !-f` z .htaccess
// oddaje istniejące kafle bez budzenia PHP-a, a do tej trasy trafiają WYŁĄCZNIE
// te, których jeszcze nie ma. Gdyby kafle leżały poza webrootem, każdy z nich
// wchodziłby tu na zawsze.
$router->get('/assets/tiles/{layer}/{key}/{z}/{x}/{y}.png', [TileController::class, 'show']);

// "TRASA DNIA" NA STRONIE GŁÓWNEJ (2026-09-05) — jeden złożony obrazek
// (kafle OSM + pola trasy + jej ślad), nie piramida kafli: adres niesie
// datę zamiast z/x/y, więc regeneruje się raz dziennie. Ta sama zasada co
// wyżej — Apache oddaje istniejący plik, PHP dostaje tylko pierwsze wejście
// danego dnia. Patrz TileController::routeOfDayMap.
$router->get('/assets/tiles/rotd/{key}/{date}.png', [TileController::class, 'routeOfDayMap']);

// OBRAZEK ŚLADU NA KARCIE PULSU (2026-09-12) — ten sam pomysł co "Trasa dnia":
// jeden złożony PNG pod adresem, który sam się unieważnia. Zamiast daty niesie
// STEMPEL ostatniego wgrania w grupie (osoba + doba), więc kolejny ślad tego
// samego dnia zmienia adres, a stary plik nikogo nie myli.
// Kolejność wobec trasy ogólnej wyżej nie ma tu znaczenia i to jest celowe:
// tamta wymaga PIĘCIU segmentów ({layer}/{key}/{z}/{x}/{y}), ta ma CZTERY —
// żadna ścieżka nie pasuje do obu. Patrz TileController::trackGroupMap.
$router->get('/assets/tiles/slad/{key}/{rozmiar}/{stamp}.png', [TileController::class, 'trackGroupMap']);

$router->get('/rejestracja', [AuthController::class, 'registerForm']);
$router->post('/rejestracja', [AuthController::class, 'register']);
$router->get('/rejestracja/dokoncz', [AuthController::class, 'completeRegistrationForm']);
$router->post('/rejestracja/dokoncz', [AuthController::class, 'completeRegistration']);
// Logowanie społecznościowe (Google/Strava) — provider przekazany wprost przez
// domknięcie, żeby dopuścić WYŁĄCZNIE te dwa (patrz SocialAuthController).
$router->get('/auth/google', fn() => SocialAuthController::redirect('google'));
$router->get('/auth/google/callback', fn() => SocialAuthController::callback('google'));
$router->get('/auth/strava', fn() => SocialAuthController::redirect('strava'));
$router->get('/auth/strava/callback', fn() => SocialAuthController::callback('strava'));
$router->get('/auth/strava/dokoncz', [SocialAuthController::class, 'stravaCompleteForm']);
$router->post('/auth/strava/dokoncz', [SocialAuthController::class, 'stravaComplete']);
// ODBIÓR LOGOWANIA W APLIKACJI MOBILNEJ (migr. 067). Wołane przez WebView apki
// po deep linku z przeglądarki — wymienia jednorazowy token na sesję po
// WŁAŚCIWEJ stronie. GET, bo wchodzi się tu nawigacją, nie formularzem; token
// jest jednorazowy i minutowy, więc podgląd linku niczego nie przejmuje.
$router->get('/auth/app', [SocialAuthController::class, 'appHandoff']);

$router->get('/odzyskaj-haslo', [AuthController::class, 'forgotPasswordForm']);
$router->post('/odzyskaj-haslo', [AuthController::class, 'forgotPassword']);
$router->get('/odzyskaj-haslo/nowe', [AuthController::class, 'resetPasswordForm']);
$router->post('/odzyskaj-haslo/nowe', [AuthController::class, 'resetPassword']);
$router->get('/logowanie', [AuthController::class, 'loginForm']);
$router->post('/logowanie', [AuthController::class, 'login']);
$router->post('/wyloguj', [AuthController::class, 'logout']);

$router->get('/wydarzenia/nowe', [EventController::class, 'createForm']);
$router->post('/wydarzenia/nowe', [EventController::class, 'create']);
$router->get('/wydarzenia/potwierdzenie/{slug}', [EventController::class, 'confirmation']);
$router->get('/wydarzenia/{slug}/edytuj', [EventController::class, 'editForm']);
$router->post('/wydarzenia/{slug}/edytuj', [EventController::class, 'update']);
$router->post('/wydarzenia/{slug}/usun', [EventController::class, 'delete']);
$router->post('/wydarzenia/{slug}/zatwierdz', [EventController::class, 'approve']);
$router->post('/wydarzenia/{slug}/odrzuc', [EventController::class, 'reject']);
$router->post('/wydarzenia/{slug}/link-przejecia', [EventController::class, 'issueClaimLink']);
$router->post('/wydarzenia/{slug}/odwolaj', [EventController::class, 'cancel']);

$router->get('/wydarzenia/{slug}/zapisz', [RsvpController::class, 'reserveForm']);
$router->post('/wydarzenia/{slug}/zapisz', [RsvpController::class, 'reserve']);
$router->post('/wydarzenia/{slug}/zapisz-zewnetrzne', [RsvpController::class, 'joinExternal']);
$router->post('/wydarzenia/{slug}/zainteresowany', [RsvpController::class, 'markInterested']);
$router->post('/wydarzenia/{slug}/potwierdz-oplate', [RsvpController::class, 'confirmPaymentSelf']);
$router->post('/wydarzenia/{slug}/anuluj-udzial', [RsvpController::class, 'cancelParticipation']);
// "Byłem" — potwierdzenie faktycznej obecności po zakończonym wyjeździe.
// Wyłącznie POST: link z maila po wydarzeniu prowadzi na STRONĘ wydarzenia
// (#bylem), nie tutaj — GET nie może zmieniać stanu, bo prefetchery klientów
// pocztowych potwierdzałyby obecność bez udziału człowieka.
$router->post('/wydarzenia/{slug}/bylem', [RsvpController::class, 'declareAttendance']);
// Ślad z ODBYTEGO wyjazdu (Etap 8, migr. 042) — jedyne źródło odkryć Discovery.
// Jedna trasa dla organizatora (ślad z imprezy) i uczestnika (ślad własny);
// kto jest kim, rozstrzyga serwer, nie formularz.
$router->post('/wydarzenia/{slug}/slad', [TrackController::class, 'upload']);
$router->post('/wydarzenia/{slug}/slad/{trackId}/usun', [TrackController::class, 'delete']);
$router->get('/wydarzenia/{slug}/uczestnicy', [RsvpController::class, 'participants']);
$router->get('/wydarzenia/{slug}/uczestnicy/{userId}/wplaty', [RsvpController::class, 'participantPayments']);
$router->post('/wydarzenia/{slug}/uczestnicy/{userId}/potwierdz-platnosc', [RsvpController::class, 'confirmPayment']);
$router->post('/wydarzenia/{slug}/uczestnicy/{userId}/potwierdz-zwrot', [RsvpController::class, 'confirmRefund']);
$router->post('/wydarzenia/{slug}/uczestnicy/{userId}/anuluj', [RsvpController::class, 'cancelParticipantByOrganizer']);
$router->post('/wydarzenia/{slug}/uczestnicy/{userId}/obecnosc', [RsvpController::class, 'setAttendance']);

$router->get('/wydarzenia/{slug}/opinia', [ReviewController::class, 'form']);
$router->post('/wydarzenia/{slug}/opinia', [ReviewController::class, 'submit']);
$router->get('/wydarzenia/{slug}/relacja', [RecapController::class, 'form']);
$router->post('/wydarzenia/{slug}/relacja', [RecapController::class, 'submit']);
$router->post('/wydarzenia/{slug}/komentarz', [CommentController::class, 'store']);
// Q&A i moderacja dyskusji (2026-08-09). Kolejność: statyczne '/faq' PRZED
// '{commentId}/usun', inaczej wzorzec z parametrem złapałby "faq" jako id.
$router->post('/wydarzenia/{slug}/komentarz/faq', [CommentController::class, 'storeFaq']);
$router->post('/wydarzenia/{slug}/komentarz/{commentId}/usun', [CommentController::class, 'delete']);

$router->get('/wiadomosci', [MessageController::class, 'inbox']);
$router->get('/wiadomosci/z/{userId}', [MessageController::class, 'withUserShow']);
$router->post('/wiadomosci/z/{userId}/wyslij', [MessageController::class, 'withUserSend']);
// Kanał grupowy per turnus — PRZED wieloznacznym /wiadomosci/{id} niżej.
$router->get('/wiadomosci/grupa/{editionId}', [MessageController::class, 'groupShow']);
$router->post('/wiadomosci/grupa/{editionId}/wyslij', [MessageController::class, 'groupSend']);
$router->get('/wiadomosci/{id}', [MessageController::class, 'show']);
$router->post('/wiadomosci/{id}/wyslij', [MessageController::class, 'send']);
$router->post('/wydarzenia/{slug}/uczestnicy/wyslij-wiadomosc', [MessageController::class, 'sendBulkToParticipants']);

// KOREKTA TŁUMACZENIA TREŚCI (wielojęzyczność, 2026-09-16) — w języku strony,
// z której przyszedł link. Uprawnienia jak przy edycji samej treści.
$router->get('/tlumaczenie/{typ}/{id}', [Controllers\TranslationController::class, 'form']);
$router->post('/tlumaczenie/{typ}/{id}', [Controllers\TranslationController::class, 'save']);

$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/sitemap-pages.xml', [SitemapController::class, 'pages']);
$router->get('/sitemap-events.xml', [SitemapController::class, 'events']);
$router->get('/sitemap-organizers.xml', [SitemapController::class, 'organizers']);
$router->get('/sitemap-trails.xml', [SitemapController::class, 'trails']);
$router->get('/sitemap-regions.xml', [SitemapController::class, 'regions']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
