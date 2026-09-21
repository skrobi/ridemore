<?php
// admin/routes.php
// Tylko rejestracja tras — logika przeniesiona do core/Controllers/Admin/*
// (patrz Controllers\Support dla współdzielonych helperów breadcrumbs).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Controllers\Admin\AccountController;
use Controllers\Admin\BillingProfileController;
use Controllers\Admin\DashboardController;
use Controllers\Admin\DiscussionController;
use Controllers\Admin\EmblemController;
use Controllers\Admin\KnownRouteController;
use Controllers\Admin\MyRidesController;
use Controllers\Admin\NotificationsController;
use Controllers\Admin\ImporterController;
use Controllers\Admin\OrganizerAdminController;
use Controllers\Admin\PointsController;
use Controllers\Admin\RegionMapController;
use Controllers\Admin\TilesController;
use Controllers\Admin\TreasureController;
use Controllers\Admin\UsersController;
use Controllers\DeviceController;
use Controllers\GarminController;
use Controllers\SoloRideController;
use Controllers\Admin\TaxonomyController;
use Core\Auth;
use Core\Router;

Auth::requireLogin();

$router = new Router();

// Owijają $router->get/post dla tras, które oprócz zalogowania (patrz globalny
// Auth::requireLogin() wyżej) wymagają jeszcze roli admina — usuwa powtórzone
// Auth::requireAdmin('Brak uprawnień.') z osobnych kontrolerów niżej.
//
// PANEL STAFF JEST WYŁĄCZNIE PO POLSKU (2026-09-16, tasks/active/wielojezycznosc.md).
// `/en/admin/skarby` działa, ale renderuje się jak `/admin/skarby` — obsługa
// serwisu to jeden zespół w jednym języku, a tłumaczenie ~600 tekstów
// narzędzi wewnętrznych nie daje nic żadnemu użytkownikowi. Panel
// UŻYTKOWNIKA (moje konto, moje przejazdy, płatności) stoi niżej na zwykłym
// $router->get/post i JEST tłumaczony — granicą jest ten wrapper, nie `/admin`.
$adminGet = function (string $path, callable $handler) use ($router): void {
    $router->get($path, function (...$args) use ($handler) {
        Auth::requireAdmin('Brak uprawnień.');
        \Core\Lang::set(\Core\Lang::DOMYSLNY);
        return $handler(...$args);
    });
};
$adminPost = function (string $path, callable $handler) use ($router): void {
    $router->post($path, function (...$args) use ($handler) {
        Auth::requireAdmin('Brak uprawnień.');
        \Core\Lang::set(\Core\Lang::DOMYSLNY);
        return $handler(...$args);
    });
};

$router->get('/admin', [DashboardController::class, 'index']);
$router->get('/admin/platnosci', [DashboardController::class, 'payments']);
// Moderacja dyskusji — CELOWO bez $adminGet: organizator moderuje własne
// wydarzenia (zakres danych zawęża sam kontroler wg roli).
$router->get('/admin/dyskusje', [DiscussionController::class, 'index']);

$router->get('/admin/profil-rozliczeniowy', [BillingProfileController::class, 'form']);
$router->post('/admin/profil-rozliczeniowy', [BillingProfileController::class, 'save']);
$router->post('/admin/profil-rozliczeniowy/info', [BillingProfileController::class, 'saveInfo']);

// Moje przejazdy — ekran ROWERZYSTY, nie organizatora, więc bez $adminGet
// (globalny Auth::requireLogin() wyżej w zupełności wystarcza). Zapytanie
// zawęża się samo do zapisów wołającego, więc nie ma tu cudzych danych.
$router->get('/admin/moje-przejazdy', [MyRidesController::class, 'index']);
// Przejazd SOLO — ślad bez wydarzenia (migr. 049). Ta sama sekcja panelu, bo to
// jest ekran, na którym rowerzysta zarządza swoimi przejazdami.
$router->post('/admin/moje-przejazdy/solo', [SoloRideController::class, 'upload']);
// Ekran wyniku jazdy — WYŁĄCZNIE w apce (§11 audytu UX 2026-08-28), upload()
// przekierowuje tu zamiast na listę, gdy APP_IS_APP. Statyczny człon ścieżki,
// więc kolejność wobec `{id}` niżej bez znaczenia (md/routing.md).
$router->get('/admin/moje-przejazdy/podsumowanie', [SoloRideController::class, 'summary']);
// Powiazanie przejazdu solo z turnusem — jedna trasa dla obu kierunkow
// (z listy solo wybierz wyjazd / z wiersza wyjazdu wybierz przejazd).
$router->post('/admin/moje-przejazdy/powiaz', [SoloRideController::class, 'link']);
// Kasowanie WLASNEGO przejazdu solo (2026-08-26) — anty-IDOR w kontrolerze.
$router->post('/admin/moje-przejazdy/solo/{id}/usun', [SoloRideController::class, 'delete']);
// GARMIN CONNECT (migr. 068) — ta sama sekcja ekranu co przejazd solo, bo to
// jest DRUGA DROGA do dokładnie tego samego: śladu bez wydarzenia. Wszystko
// POST-em (zmienia stan konta), wszystko wraca na /admin/moje-przejazdy.
// Trasy istnieją zawsze; kontroler oddaje 404 w środowisku, gdzie most do
// Pythona nie jest skonfigurowany (patrz Utils\GarminBridge::available() i
// klucz `garmin` w core/config.php — od 2026-09-04 obecny też na 'prod').
$router->post('/admin/moje-przejazdy/garmin/polacz', [GarminController::class, 'connect']);
$router->post('/admin/moje-przejazdy/garmin/odlacz', [GarminController::class, 'disconnect']);
$router->post('/admin/moje-przejazdy/garmin/odswiez', [GarminController::class, 'refresh']);
$router->post('/admin/moje-przejazdy/garmin/import', [GarminController::class, 'import']);

// LICZNIKI PRZEZ OAuth (migr. 069) — Polar, Wahoo (COROS/Suunto czekają na
// onboarding partnerski). W ODRÓŻNIENIU OD GARMINA te trasy działają także na
// produkcji: to zwykły OAuth po HTTPS, bez mostu do Pythona.
// `callback` jest GET-em, bo to dostawca na niego przekierowuje — chroni go
// jednorazowy `state` z sesji, nie token CSRF (którego przeglądarka by tam nie
// dołożyła). Reszta POST-em.
$router->post('/admin/moje-przejazdy/licznik/{provider}/polacz', [DeviceController::class, 'connect']);
$router->get('/admin/moje-przejazdy/licznik/{provider}/callback', [DeviceController::class, 'callback']);
$router->post('/admin/moje-przejazdy/licznik/{provider}/odswiez', [DeviceController::class, 'refresh']);
$router->post('/admin/moje-przejazdy/licznik/{provider}/import', [DeviceController::class, 'import']);
// Automatyczny import przez webhooki (migr. 088) — przełącznik per konto.
$router->post('/admin/moje-przejazdy/licznik/{provider}/automat', [DeviceController::class, 'autoImport']);
$router->post('/admin/moje-przejazdy/licznik/{provider}/odlacz', [DeviceController::class, 'disconnect']);

$router->get('/admin/moje-konto', [AccountController::class, 'form']);
$router->post('/admin/moje-konto', [AccountController::class, 'updateName']);
$router->post('/admin/moje-konto/haslo', [AccountController::class, 'changePassword']);
$router->post('/admin/moje-konto/dane-adresowe', [AccountController::class, 'updateBillingAddress']);
$router->post('/admin/moje-konto/preferencje', [AccountController::class, 'updatePreferences']);
// Kasowanie konta w apce (Etap 9 przebudowy apki, 2026-08-29) — patrz
// AccountController::requestDeletion() i Models\User::requestDeletion().
$router->post('/admin/moje-konto/usun', [AccountController::class, 'requestDeletion']);

$adminGet('/admin/taksonomia', [TaxonomyController::class, 'index']);
$adminPost('/admin/taksonomia/dodaj', [TaxonomyController::class, 'create']);
$adminPost('/admin/taksonomia/{id}/edytuj', [TaxonomyController::class, 'update']);
$adminPost('/admin/taksonomia/{id}/przelacz', [TaxonomyController::class, 'toggle']);

// Znane trasy (Etap 8) — dane referencyjne serwisu, jak taksonomia, dlatego
// tutaj, a nie w samoobsługowej części panelu.
$adminGet('/admin/znane-trasy', [KnownRouteController::class, 'index']);
// Formularze na WŁASNYCH podstronach, nie w rozwijanych blokach przy wierszach:
// katalog ma dorosnąć do setek tras, a tyle formularzy w jednym dokumencie to
// ekran, po którym nie da się poruszać. Statyczna `/nowa` PRZED `/{id}/...`
// (router dopasowuje liniowo).
$adminGet('/admin/znane-trasy/nowa', [KnownRouteController::class, 'createForm']);
$adminPost('/admin/znane-trasy/dodaj', [KnownRouteController::class, 'create']);
$adminGet('/admin/znane-trasy/{id}/edytuj', [KnownRouteController::class, 'editForm']);
// Edycja obejmuje TAKŻE zdjęcie i (opcjonalnie) podmianę przebiegu — dawna
// osobna akcja `/{id}/zdjecie` zniknęła 2026-08-19, bo była drugą drogą
// zapisu tej samej kolumny.
$adminPost('/admin/znane-trasy/{id}/edytuj', [KnownRouteController::class, 'update']);
$adminPost('/admin/znane-trasy/{id}/przelicz', [KnownRouteController::class, 'recalculate']);
$adminPost('/admin/znane-trasy/{id}/przelacz', [KnownRouteController::class, 'toggle']);
$adminPost('/admin/znane-trasy/{id}/usun', [KnownRouteController::class, 'delete']);

// Emblematy (migr. 087) — dane referencyjne jak taksonomia i znane trasy,
// dlatego w tej samej części panelu. Lista i formularz na JEDNYM ekranie
// (emblematów będą dziesiątki, nie setki — patrz nota w widoku).
$adminGet('/admin/emblematy', [EmblemController::class, 'index']);
$adminPost('/admin/emblematy/zapisz', [EmblemController::class, 'save']);
// Statyczna `/przelicz` PRZED `/{id}/usun` — router dopasowuje liniowo.
$adminPost('/admin/emblematy/przelicz', [EmblemController::class, 'recalculate']);
$adminPost('/admin/emblematy/{id}/usun', [EmblemController::class, 'delete']);

// Punkty i bonusy (Etap 8A/15). Konfiguracja GLOBALNA zostaje w
// core/discovery.php — ten ekran ją tylko POKAZUJE (z przykładami liczonymi
// tym samym kodem, który nalicza naprawdę) i pozwala ustawić to, co jest
// danymi: bonusy per trasa i per wydarzenie.
// SKARBY (Etap 8D) - zarzadzanie punktami w terenie.
$adminGet('/admin/skarby', [TreasureController::class, 'index']);
$adminPost('/admin/skarby/zapisz', [TreasureController::class, 'save']);
$adminGet('/admin/skarby/wydruk', [TreasureController::class, 'printSheet']);
$adminPost('/admin/skarby/{id}/nowy-kod', [TreasureController::class, 'rotateCode']);
// Decyzja moderacyjna nad zgloszonym punktem (SKA/3) — omija glosowanie.
$adminPost('/admin/skarby/{id}/status', [TreasureController::class, 'setStatus']);
// SKARBY W KADRZE MAPY panelu (2026-08-20). Osobne wejscie zamiast wsypywania
// calej tabeli do HTML-a: panel ma dorosnac do tysiaca punktow, a rysuje sie
// tylko to, na co admin patrzy. Pelna precyzja (bez przycinania ujawnieniem),
// wiec wylacznie dla admina — stad $adminGet, nie api/routes.php.
$adminGet('/admin/skarby/punkty', [TreasureController::class, 'points']);
// KASOWANIE — tylko punktu, ktorego nikt nie znalazl (Models\Treasure::
// deleteIfUnfound). Znaleziony schodzi ze sceny statusem RETIRED, zeby nie
// osierocic wierszy w niezmiennym rejestrze punktow.
$adminPost('/admin/skarby/{id}/usun', [TreasureController::class, 'delete']);
// Kasowanie POJEDYNCZEGO zdjecia z galerii skarbu (SKA/14). {id} to id
// ZDJECIA, nie skarbu — akcja stoi przy miniaturze w formularzu.
$adminPost('/admin/skarby/zdjecie/{id}/usun', [TreasureController::class, 'deletePhoto']);
// UZYTKOWNICY (migr. 058) — przeglad, blokada, reset hasla, kasowanie fejkow.
$adminGet('/admin/uzytkownicy', [UsersController::class, 'index']);
$adminPost('/admin/uzytkownicy/{id}/blokuj', [UsersController::class, 'block']);
$adminPost('/admin/uzytkownicy/{id}/odblokuj', [UsersController::class, 'unblock']);
$adminPost('/admin/uzytkownicy/{id}/reset-hasla', [UsersController::class, 'resetPassword']);
$adminPost('/admin/uzytkownicy/{id}/usun', [UsersController::class, 'delete']);

// KAFLE MAP — podglad cache'u i reset bez dostepu do konsoli (2026-08-15).
$adminGet('/admin/kafle', [TilesController::class, 'index']);
$adminPost('/admin/kafle/purge', [TilesController::class, 'purge']);
$adminPost('/admin/kafle/prune', [TilesController::class, 'prune']);
// „Policz geometrię wszystkich śladów" — to samo, co `php tiles.php backfill`,
// tylko z przeglądarki (hosting bez shella). Patrz TilesController::backfill.
$adminPost('/admin/kafle/geometria', [TilesController::class, 'backfill']);
// Przydzial kolorow sladom (migr. 073) — osobno od geometrii: tamto LICZY
// pliki, to MALUJE to, co juz policzone.
$adminPost('/admin/kafle/kolory', [TilesController::class, 'colors']);
// Naprawa STARYCH szkod po wyscigu zapisu sprzed transakcji w ensure()/
// storeTrimmed() (2026-09-07) — dopisuje brakujace wiersze gpx_tiles bez
// czytania pliku GPX. Patrz TilesController::repairIndex.
$adminPost('/admin/kafle/napraw-indeks', [TilesController::class, 'repairIndex']);

// REGIONY NA MAPIE (2026-09-01) — dodawanie regionow do slownika i rysowanie
// ich obrysow dla krajow, ktorych podzialu nie da sie zaimportowac z granic
// administracyjnych. Obrys zapisuje sie do data/regiony.geojson; pokrycie
// heksami liczy dalej backfill_regions.php. Koncowka JSON-owa siedzi pod tym
// samym adresem, zeby dziedziczyc bramke admina z $adminGet/$adminPost.
$adminGet('/admin/regiony-mapa', [RegionMapController::class, 'index']);
$adminGet('/admin/regiony-mapa/geometria', [RegionMapController::class, 'geometry']);
$adminPost('/admin/regiony-mapa/zapisz', [RegionMapController::class, 'save']);
// Poprawka pojedynczego heksa, z pominięciem geometrii/importu (2026-09-10) —
// patrz RegionMapController::assignCell().
$adminPost('/admin/regiony-mapa/pole', [RegionMapController::class, 'assignCell']);
$adminPost('/admin/regiony-mapa/dodaj', [RegionMapController::class, 'createRegion']);
$adminPost('/admin/regiony-mapa/usun-obrys', [RegionMapController::class, 'removeOutline']);

// PANEL POWIADOMIEŃ (2026-09-11, migr. 083) — budżet, pory, wyłączniki per
// rodzaj i kanał, plus dziennik wysyłek. Do tej daty wszystkie te decyzje
// siedziały w stałych `NotificationGate`, a pora wysyłki nawet nie w aplikacji,
// tylko w crontabie na serwerze.
$adminGet('/admin/powiadomienia', [NotificationsController::class, 'index']);
$adminPost('/admin/powiadomienia', [NotificationsController::class, 'save']);

// Importer wydarzeń — zarządzanie z panelu zamiast CLI (patrz
// tasks/active/importer-wydarzen.md, Etap 4). Import idzie przez ten sam most
// AI co rozszerzenie (dev-only); kandydaci trafiają do moderacji /admin.
$adminGet('/admin/importer', [ImporterController::class, 'index']);
$adminPost('/admin/importer/import', [ImporterController::class, 'import']);
$adminPost('/admin/importer/zbierz', [ImporterController::class, 'harvest']);
$adminPost('/admin/importer/zrodla/dodaj', [ImporterController::class, 'addSource']);
$adminPost('/admin/importer/zrodla/usun', [ImporterController::class, 'removeSource']);

$adminGet('/admin/punkty', [PointsController::class, 'index']);
// Stawki globalne (migr. 053) — punktacja przestala byc wylacznie plikiem PHP.
$adminPost('/admin/punkty/stawki', [PointsController::class, 'saveRates']);
$adminPost('/admin/punkty/trasa/{id}', [PointsController::class, 'saveRoute']);
$adminPost('/admin/punkty/wydarzenie/{id}', [PointsController::class, 'saveEvent']);

$adminGet('/admin/organizatorzy', [OrganizerAdminController::class, 'index']);
$adminGet('/admin/organizatorzy/{slug}/edytuj', [OrganizerAdminController::class, 'editForm']);
$adminPost('/admin/organizatorzy/{slug}/edytuj', [OrganizerAdminController::class, 'update']);
$adminPost('/admin/organizatorzy/{slug}/rozliczenia', [OrganizerAdminController::class, 'saveBilling']);
$adminPost('/admin/organizatorzy/{slug}/aktywnosc', [OrganizerAdminController::class, 'toggleActive']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
