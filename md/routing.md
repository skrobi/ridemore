# Trasy (routing)

Trzy pliki tras, wybierane w [`index.php`](../index.php) wg prefiksu URL. Router
dopasowuje **liniowo** — kolejność rejestracji ma znaczenie tam, gdzie wzorce się
nakładają (statyczny/szczegółowy MUSI być przed `{param}`/ogólnym). `{param}` w URL
staje się argumentem pozycyjnym handlera.

## web — [`web/routes.php`](../web/routes.php) (HTML)

| Metoda | URL | Handler |
|---|---|---|
| GET | `/` | `HomeController::index` |
| GET | `/jak-to-dziala` `/regulamin` `/prywatnosc` `/relacje` `/dla-organizatorow` | `PageController::*` |
| GET/POST | `/powiadomienia/wypisz` | `UnsubscribeController::form` / `save` — **wypis z powiadomień prosto z maila** (Etap 1c, 2026-09-11). BEZ LOGOWANIA: uprawnienie niesie podpis HMAC w adresie (`u`/`f`/`k`, patrz `NotificationGate::adresWypisu`), nie sesja — człowiek czytający maila najczęściej nie jest zalogowany, a jego alternatywą dla trudnego wypisu jest przycisk „to jest spam", który psuje reputację domeny także mailom transakcyjnym. **GET tylko pyta, POST wypisuje** — skanery poczty i podglądy linków w komunikatorach pobierają adresy z maili w tle (ten sam powód, dla którego POST-only są `/skarb/{code}` i „Byłem"). **POST świadomie bez CSRF**: wysyła go albo niezalogowany człowiek z formularza, albo wprost Gmail/Outlook na podstawie nagłówka `List-Unsubscribe-Post` (RFC 8058); rolę tokenu pełni podpis, ograniczony do JEDNEJ flagi JEDNEGO konta. |
| GET | `/events/{slug}` | `EventController::show` |
| GET | `/wydarzenia` | `EventsListController::index` — **przed** `/wydarzenia/*` |
| GET | `/organizatorzy` | `OrganizerController::index` — **przed** `/organizatorzy/{slug}` |
| GET | `/organizatorzy/{slug}` | `OrganizerController::show` |
| GET | `/rowerzysta/{slug}` | `RiderController::show` — publiczny profil rowerzysty (Etap 3) |
| GET | `/przejazd/{id}/gpx` | `RideController::gpx` — pobranie śladu. Właściciel (i każdy przy śladzie z wyjazdu) dostaje **przekierowanie na oryginalny plik**; obcy przy przejeździe solo — GPX **złożony z przyciętej geometrii** (§27, bez wysokości i czasów). Rejestrowane PRZED `/przejazd/{id}`. |
| GET | `/przejazd/{id}` | `RideController::show` — **strona jednego przejazdu** (2026-09-03): mapa, liczby, profil wysokości, skarby, znane trasy, punkty, wyjazd. Publiczna, ale cudzy ślad solo idzie PRZYCIĘTY, a przejazd rowerzysty bez publicznego profilu daje 404 (`Support::visibleRiderById`). |
| GET | `/kronika/{slug}` | `ChronicleController::show` — kronika wyjazdu per turnus (`?termin=`, Etap 4) |
| POST | `/kronika/{slug}/oznacz` | `ChronicleController::invite` — zaproszenie mailem, NIKOGO nie dopisuje do składu |
| GET | `/puls` | `PulseController::index` — Puls społeczności (Etap 5) |
| GET | `/odkrycia/spolecznosc` | `DiscoveryController::community` — wspólna mapa odkryć (Etap 8). **Publiczna**, rejestrowana PRZED `/odkrycia` |
| GET | `/odkrycia` | `DiscoveryController::index` — moja mapa odkryć (Etap 8), `Auth::requireLogin()`. **`?skarb={id}` (2026-09-11)** otwiera mapę wykadrowaną na tym skarbie — pod przycisk w powiadomieniu „nowy skarb w Twojej okolicy", które wcześniej lądowało na mapie całego kraju. **Kadr powstaje WYŁĄCZNIE dla skarbu jawnego** (`reveal_level >= 2`, patrz `kadrNaSkarbie()`); przy ukrytym parametr jest bezużyteczny i to jest zamierzone — inaczej wystarczyłoby zgadywać identyfikatory, żeby wyciągnąć położenie czegoś, czego mapa celowo nie pokazuje. |
| GET/POST | `/skarb/{code}` | `TreasureScanController::show` / `claim` — adres z kodu QR na naklejce (Etap 8D). **GET tylko pokazuje**, zalicza dopiero POST (podglądy linków w komunikatorach pobierają strony w tle). **POST z nagłówkiem `Accept: application/json`** (Etap 7 apki mobilnej, 2026-08-28) dostaje `{ok,reason,points,name}` zamiast pełnej strony — pod kolejkę zaległych skanów offline (`assets/js/native.js`); zwykły formularz „Odbierz" nigdy tego nagłówka nie wysyła, więc zachowanie dla www jest bez zmian. |
| GET/POST | `/skarby/zglos` | `TreasureProposalController::form` / `save` — zgłoszenie punktu przez rowerzystę; trafia do poczekalni (`status = PROPOSED`), nie na mapę |
| GET | `/trasy` | `TrailController::index` — katalog znanych tras, **przed** `/trasy/{slug}` |
| GET | `/trasy/{slug}` | `TrailController::show` — strona jednej znanej trasy |
| GET | `/planer/{id}/gpx` | `PlannerController::gpx` — eksport zapisanej trasy plannera, **przed** `/planer` |
| GET | `/planer` `?id=` | `PlannerController::index` — Route Planner, Etap 1 (2026-09-17). Wymaga logowania (`Auth::requireLogin()`), `?id=` wczytuje istniejącą trasę usera do edycji |
| GET | `/regiony` `/regiony/{kraj}` `/regiony/{kraj}/{region}` | `RegionController::index/country/show` — strony regionów (2026-09-14, `tasks/done/strony-regionow.md`). Kraj bez regionów (Słowacja) jest stroną regionu pod jednym segmentem; region pod złym krajem → 301, nieaktywny/nieistniejący kod → 404. |
| GET | `/assets/tiles/{layer}/{key}/{z}/{x}/{y}.png` | `TileController::show` — kafel mapy (migr. 051). Adres kafla jest JEDNOCZEŚNIE ścieżką pliku na dysku, więc tu trafiają wyłącznie kafle, których jeszcze nie ma — istniejące oddaje Apache (`RewriteCond %{REQUEST_FILENAME} !-f`) |
| GET | `/assets/tiles/rotd/{key}/{data}.png` | `TileController::routeOfDayMap` — "Trasa dnia" na stronie głównej (2026-09-05): jeden złożony obrazek (kafle OSM + pola trasy + jej ślad), nie piramida — adres niesie datę zamiast z/x/y, więc regeneruje się raz dziennie. `{key}` wyłącznie `ev-{id}`/`kr-{id}` |
| GET | `/assets/tiles/slad/{key}/{rozmiar}/{stamp}.png` | `TileController::trackGroupMap` — obrazek śladu na karcie Pulsu „wgrane ślady" (2026-09-12). Ten sam mechanizm co `rotd`, ale zamiast daty adres niesie STEMPEL ostatniego wgrania w grupie, więc kolejny ślad tej samej doby zmienia adres. `{key}` wyłącznie `sw-{userId}-{RRRRMMDD}` (grupa wpisu, NIE klucz `TileSource`), `{rozmiar}` to `karta`/`kwadrat`. Rysuje z geometrii PRZYCIĘTEJ dla solo (§27) i stawia bramkę `Support::visibleRiderById` |
| POST | `/wydarzenia/{slug}/bylem` | `RsvpController::declareAttendance` — „Byłem". **Tylko POST** (prefetchery maili) |
| POST | `/wydarzenia/{slug}/uczestnicy/{userId}/obecnosc` | `RsvpController::setAttendance` — obecność zatwierdzana przez organizatora |
| POST | `/wydarzenia/{slug}/slad` (+`/{trackId}/usun`) | `TrackController::upload` / `delete` — ślad z ODBYTEGO wyjazdu (Etap 8, migr. 042). Jedna trasa dla organizatora (ślad wspólny) i uczestnika (ślad własny); **kto jest kim, rozstrzyga serwer**, nie formularz |
| POST | `/organizatorzy/{slug}/weryfikacja` `/przejmij` | `OrganizerController::verify` / `claim` |
| GET/POST | `/rejestracja` `/rejestracja/dokoncz` | `AuthController::register*` / `completeRegistration*` |
| GET | `/auth/google` `/auth/google/callback` `/auth/strava` `/auth/strava/callback` | domknięcia → `SocialAuthController::redirect/callback(provider)` |
| GET/POST | `/auth/strava/dokoncz` | `SocialAuthController::stravaCompleteForm` / `stravaComplete` |
| GET/POST | `/odzyskaj-haslo` `/odzyskaj-haslo/nowe` | `AuthController::forgotPassword*` / `resetPassword*` |
| GET/POST | `/logowanie` | `AuthController::loginForm` / `login` |
| POST | `/wyloguj` | `AuthController::logout` |
| GET/POST | `/wydarzenia/nowe` | `EventController::createForm` / `create` |
| GET | `/wydarzenia/potwierdzenie/{slug}` | `EventController::confirmation` |
| GET/POST | `/wydarzenia/{slug}/edytuj` | `EventController::editForm` / `update` |
| POST | `/wydarzenia/{slug}/usun` `/zatwierdz` `/odrzuc` `/link-przejecia` `/odwolaj` | `EventController::delete/approve/reject/issueClaimLink/cancel` |
| GET/POST | `/wydarzenia/{slug}/zapisz` | `RsvpController::reserveForm` / `reserve` (płatne wewnętrzne) |
| POST | `/wydarzenia/{slug}/zapisz-zewnetrzne` | `RsvpController::joinExternal` |
| POST | `/wydarzenia/{slug}/zainteresowany` | `RsvpController::markInterested` |
| POST | `/wydarzenia/{slug}/potwierdz-oplate` `/anuluj-udzial` | `RsvpController::confirmPaymentSelf` / `cancelParticipation` |
| GET | `/wydarzenia/{slug}/uczestnicy` | `RsvpController::participants` |
| GET | `/wydarzenia/{slug}/uczestnicy/{userId}/wplaty` | `RsvpController::participantPayments` |
| POST | `/wydarzenia/{slug}/uczestnicy/{userId}/potwierdz-platnosc` `/potwierdz-zwrot` `/anuluj` | `RsvpController::confirmPayment/confirmRefund/cancelParticipantByOrganizer` |
| GET/POST | `/wydarzenia/{slug}/opinia` | `ReviewController::form` / `submit` |
| GET/POST | `/wydarzenia/{slug}/relacja` | `RecapController::form` / `submit` |
| POST | `/wydarzenia/{slug}/komentarz` | `CommentController::store` |
| POST | `/wydarzenia/{slug}/komentarz/faq` | `CommentController::storeFaq` — pytanie + odpowiedź jednym wpisem (organizator). **Przed** `{commentId}/usun` |
| POST | `/wydarzenia/{slug}/komentarz/{commentId}/usun` | `CommentController::delete` — moderacja dyskusji |
| GET | `/wiadomosci` | `MessageController::inbox` |
| GET/POST | `/wiadomosci/z/{userId}` `/z/{userId}/wyslij` | `MessageController::withUserShow` / `withUserSend` |
| GET/POST | `/wiadomosci/grupa/{editionId}` `/grupa/{editionId}/wyslij` | `MessageController::groupShow` / `groupSend` — **przed** `/wiadomosci/{id}` |
| GET/POST | `/wiadomosci/{id}` `/{id}/wyslij` | `MessageController::show` / `send` |
| POST | `/wydarzenia/{slug}/uczestnicy/wyslij-wiadomosc` | `MessageController::sendBulkToParticipants` |
| GET | `/sitemap.xml` `/sitemap-pages.xml` `/sitemap-events.xml` `/sitemap-organizers.xml` `/sitemap-trails.xml` `/sitemap-regions.xml` | `SitemapController::*` (`index/pages/events/organizers/trails/regions`) |

## api — [`api/routes.php`](../api/routes.php) (JSON)

Nagłówek `Content-Type: application/json`. **Logika jest wpisana bezpośrednio w
domknięcia** w tym pliku (nie w osobnych kontrolerach) — jedyne miejsce, gdzie tak jest.

| Metoda | URL | Co robi |
|---|---|---|
| GET | `/api/events/map` | Pinezki mapy (te same filtry co lista, bez limitu; opcjonalny prostokąt `north/south/east/west`). `Event::mapPins`. **Przed** `/api/events/{slug}`. |
| GET | `/api/discovery/trails/at` | Co jest pod wskazanym punktem (`lat`, `lon`, `zoom`) — odpowiedź na KLIK w szlak, bo kafel jest obrazkiem. `KnownRoute::atPoint`; tolerancja skalowana powiększeniem. Zwraca nazwę, dystans (`distanceLabel`), przewyższenie i `url` strony trasy; pudło = pusta lista. **`route={id}` (2026-09-10, opcjonalny)** zawęża odpowiedź do jednej trasy — używa go strona `/trasy/{slug}`, na której warstwa „Znane trasy" rysuje wyłącznie tę jedną; bez parametru pytanie idzie o cały katalog (tak pytają `/odkrycia` i profil). |
| GET | `/api/discovery/rides/at` | **Klik w ślad** (2026-08-27) — bliźniak trasy wyżej. **Bez `rider=`**: WŁASNY ślad, `RiderActivity::atPoint`, WYMAGA ZALOGOWANIA i odpowiada wyłącznie o przejazdach pytającego — `user_id` bierze się Z SESJI, nigdy z żądania, bo plik solo jest surowy i zaczyna się pod czyimś domem (§27). Gość dostaje **404, nie 401**. **Z `rider={slug}`** (2026-09-10, profil rowerzysty, ten sam wzorzec co `rider=` w `/api/treasures` — slug przez `Support::visibleRider`, nieznany/ukryty = 404): `RiderActivity::atPointForRider`, geometria **PRZYCIĘTA** (`gpx_tiles_trimmed`), WYŁĄCZNIE solo — działa identycznie dla właściciela i dla obcego, bo `u-{slug}` jest publiczny bez względu na to, kto patrzy. Trafienie liczone na GEOMETRII, nie na polach (patrz [`features.md`](features.md)). Zwraca nazwę, datę (`dateLabel`), dystans, przewyższenie, nowe pola, punkty, KOLOR linii i `url`; pudło = pusta lista. **`url` prowadzi od 2026-09-03 na `/przejazd/{id}`** (wcześniej: solo → lista przejazdów zawężona po dacie, wyjazd → strona wydarzenia). |
| POST | `/api/map/perf` | **Dziennik czasów mapy — TYLKO DEV** (2026-09-02). Monitor w przeglądarce (`ridemoreMapBusy` w `discovery-map.js`) zbiorczo przysyła czasy zadań mapy; endpoint dopisuje je do `storage/map-perf.log` (rotacja przy 2 MB do `map-perf-poprzedni.log`). **Dwie niezależne bramki:** adres wstawia `Support::mapPerfHead()` wyłącznie przy `APP_ENV=dev` (przeglądarka nie wie, dokąd wysyłać), a trasa sprawdza `APP_ENV` jeszcze raz (ręcznie sklecone żądanie nic nie zapisze) + CSRF z nagłówka `X-CSRF-TOKEN`. Zapisujemy wyłącznie etykiety i liczby z naszego kodu — żadnych danych rowerzysty. |
| GET | `/api/rides/{id}/track` | **Geometria śladu jednego przejazdu** (2026-09-02, zgłoszenie usera: klik w „Ostatnią aktywność" czekał na pobranie 10+ MB GPX-a). Oddaje samą linię z `gpx_geometry` spakowaną formatem `d6v` (`GpxGeometry::packedForFile`; dekoder `ridemoreUnpackTrack` w `discovery-map.js`) — bez tętna, mocy i kadencji, których rysowanie nie używa. Bramka „komu wolno" NIE jest tu pisana od nowa: liczy ją `Support::rideGpxPath()`, ta sama metoda, którą feed decyduje o wydaniu adresu (ślad wyjazdu przez `EditionTrack::effectiveFor`, **plik solo tylko właścicielowi**, §27). **Od 2026-09-03 obcy nie dostaje 404 przy przejeździe solo, tylko wariant PRZYCIĘTY** (`GpxGeometry::packedTrimmedForFile` z `gpx_geometry_trimmed`, migr. 076) — i to wyłącznie, gdy właściciel jest publicznym rowerzystą (`Support::strangerTrackPath`, ta sama bramka co strona `/przejazd/{id}`, żeby adresu API nie dało się użyć w jej obejściu). Brak dostępu dalej = **404, nie 403**. |
| GET | `/api/tracks/{id}/geometry` | To samo dla śladu wskazanego po `edition_tracks.id` — wpisy „Aktywności" na profilu rowerzysty (`data-track`). **Bez bramki, świadomie**: ślad turnusu opisuje imprezę ogłoszoną publicznie, rysuje go strona wydarzenia każdemu, a sam plik leży pod publicznym adresem w `gpx/` — endpoint oddaje MNIEJ niż on. Solo tędy nie wychodzi (nie ma wiersza w `edition_tracks`, dopóki właściciel sam go nie powiąże). |
| ~~GET~~ | ~~`/api/discovery/trails`~~ | **USUNIĘTE 2026-08-20.** Warstwa „Znane trasy" rysuje się kaflami (klucz `kr`, `Models\TileSource`) — pełna geometria GPX zamiast linii sklejonej ze środków pól siatki. `KnownRoute::geometryInBounds()` zostaje w modelu (reguła „które trasy wchodzą w kadr" + testy), ale nie ma już adresu HTTP. |
| GET | `/api/treasures` | Skarby w widocznym prostokącie (Etap 8D). Pozycja przechodzi przez `Treasure::inBounds`, więc skarb o `reveal_level` 0/1 dostaje środek pola zamiast dokładnych współrzędnych. Flaga `mine` mówi, czy PYTAJĄCY już go ma; **kto jeszcze go znalazł — nigdy** (§27). Kod z naklejki NIE wychodzi w odpowiedzi. Parametr `stan=moje\|nowe` (2026-08-20) zawęża do skarbów zdobytych albo niezdobytych — WZGLĘDEM KOGO, mówi drugi parametr, `scope` (Etap 3 warstw mapy, 2026-08-26): `scope=all` (i brak `rider=`) liczy „zdobyte" jako **znalezione przez KOGOKOLWIEK** (mapa społeczności), każda inna wartość — jak dotąd, względem PYTAJĄCEGO. §27 nie stoi na przeszkodzie: to samo pytanie zadaje już publiczny licznik `finders` w odpowiedzi, żadna tożsamość nie wycieka. Filtr MUSI być tutaj, a nie w przeglądarce, bo przy oddaleniu odpowiedź to pęczki (same liczby). Odpowiedź „pęczkowa" niesie i `clusters`, i `treasures`: pole z jednym skarbem nie jest pęczkiem. |
| GET | `/api/treasures/{id}` | Jeden skarb do dymka na mapie — ta sama bramka pozycji co wyżej (`Treasure::inBounds`), bo feed renderuje się na stronie publicznej. |
| POST | `/api/treasures/claim` | Zaliczenie z mapy (metoda `GPS`). Logowanie + CSRF. Odległość liczy serwer z WŁASNYCH współrzędnych skarbu — przysłane `lat/lon` to deklaracja pozycji telefonu, nie wynik sprawdzenia. |
| POST | `/api/treasures/confirm` | Głos potwierdzający zgłoszony punkt (SKA/3). Wymaga bycia w promieniu, tak samo jak zaliczenie — potwierdzenie „z kanapy" nie jest potwierdzeniem. Próg głosów aktywuje skarb (`status` → `ACTIVE`). |
| GET | `/api/discovery/nearby-treasures` | **Etap 5b apki mobilnej (2026-08-28)** — pod alert „zbliżasz się" liczony LOKALNIE w tle (`assets/js/app-tracking.js`), bo pozycja nie może wychodzić na serwer, gdy apka jest zwinięta. `Treasure::nearbyForAlerts` — **jedyne** miejsce, które celowo omija bramkę `knownCells`: zwraca skarby także z nieodkrytego pola, z tą samą precyzją, jaką `reveal()` przyznaje po odkryciu (środek pola na poziomach 0/1, nigdy dokładny punkt). Wymaga logowania (401 bez sesji); parametry `north/south/east/west` jak przy `/api/treasures`. **Od 2026-08-29 niesie też `description`** — pod notkę „przewodnika" przy mijanym skarbie, działającą bez zasięgu. Bezpieczne bez nowego warunku, bo `reveal()` zeruje opis tą samą linią co zdjęcie i rzadkość, więc wyjeżdża wyłącznie opis skarbu JAWNEGO; punktów świadomie nie ma (idą w parze z rzadkością, którą tamta bramka zdejmuje). |
| GET | `/api/matches/preview` | Podpowiedzi dopasowań na żywo w formularzu eventu (`MatchEngine::forDraft`), loguje `RecommendationLog::record`. GET, bez CSRF (odczyt, debounced). |
| GET | `/api/planer/warstwy` | **Route Planner** — WYŁĄCZNIE skarby w kadrze (`bounds` z query, `PlannerController::layers`) pod dymek „Po drodze" / „Jedź tu (cel)". Linie źródeł (znane trasy, przejazdy) idą kaflami „Ślady". Bez CSRF (odczyt). |
| POST | `/api/planer/oblicz` | Przeliczenie trasy PER SEGMENT (`PlannerController::calculate`). Body: `waypoints`, `sources`, `autoJoin`, istniejący `profile` (kod `bike_type`), opcjonalne `routing` `{configId,character,preferences}` oraz `base`. Preferencje są osobnym kosztem Ridemore Layer, nie parametrem ani zmianą OSRM; odpowiedź zawiera znormalizowany snapshot `routing` i w `variant.reason` dystans, detour, korektę drogi, zgodność profilu, bonus popularności i pokrycie metadanych. Po CSRF closure zwalnia sesję i podnosi limit czasu do 120 s. Logowanie + CSRF w body JSON-a. |
| GET | `/api/planer/preferencje` | Lista własnych nazwanych konfiguracji routingu. Każda wskazuje istniejący profil roweru. Logowanie. |
| POST | `/api/planer/preferencje/zapisz` | Tworzy/edytuje własną konfigurację. Body: `id?`, `name`, `bikeProfile`, `character`, `preferences`, CSRF. Nie pozwala zmienić profilu przy edycji ani nadpisać globalnego presetu. |
| POST | `/api/planer/preferencje/usun` | Usuwa wyłącznie własną konfigurację (`id` + CSRF); snapshoty zapisanych tras zostają. |
| POST | `/api/planer/preferencje/domyslna` | Ustawia własną konfigurację jako domyślną dla jednego istniejącego profilu roweru. |
| POST | `/api/planer/zapisz` | Zapis/nadpisanie `Models\PlannedRoute` (`PlannerController::save`) — leniwe przewyższenie z `Utils\ElevationLookup`. Logowanie + CSRF w body JSON-a. |
| GET | `/api/planer/zrodla` | **„Wybierz konkretną trasę"** — szukanie (`?typ=przejazd\|trasa&q=`, UI woła oba typy naraz i grupuje), `PlannerController::searchSources`; znane trasy niosą `color`. Tylko własne przejazdy (§27). **Przed** `/api/planer/{id}` (inaczej `{id}` przechwyciłby `zrodla` jako literalne id). Logowanie. |
| GET | `/api/planer/zrodlo` | Geometria wybranej konkretnej trasy (`?typ=&ref=`) → baza planowania, `PlannerController::sourceGeometry` (+ `color` znanej trasy). **Przed** `/api/planer/{id}`. Logowanie. |
| POST | `/api/planer/wgraj-gpx` | „Wgraj GPX" — plik jako baza planowania, efemeryczny: nic nie ląduje na dysku (`Utils\Gpx::parse` na wprost `$_FILES`). CSRF **z pola formularza** (`$_POST['csrf_token']`, nie body JSON-a — to multipart/FormData). Logowanie. |
| GET | `/api/planer/{id}` | Wczytanie zapisanej trasy do edycji (`PlannerController::load`) — ownership guard w SQL (`PlannedRoute::findForUser`). Logowanie. |
| POST | `/api/matches/dismiss` | Odrzucenie sugestii (Etap 3), wymaga logowania + CSRF. `RecommendationDismissal::record`. |
| GET | `/api/events/{slug}` | `EventResource::fromModel` jako JSON. |
| POST | `/api/events/{slug}/rsvp` | Szybki zapis (darmowe/zewnętrzne). Wymaga logowania + `X-CSRF-Token`. Płatne wewnętrzne odrzuca (409, muszą iść przez `/zapisz`). |
| GET | `/api/organizers/search` | Szukajka organizatorów (formularz „zgłoś w czyimś imieniu"). Bez logowania → e-mail maskowany. |
| GET | `/api/organizers/match-domain` | Dopasowanie organizatora po domenie `website_url` (`?domain=`) — pod zewnętrzne narzędzia (rozszerzenie Chrome). Bez logowania. |
| GET | `/api/dictionaries/{code}/search` | Podpowiedzi pozycji słownika (autouzupełnianie w formularzu). Bez logowania. |
| GET | `/api/dictionaries` | Pełny zestaw słowników formularza eventu jako JSON (`Support::eventFormDictOptions()`, to samo co kreator). Bez logowania. |
| POST | `/api/gpx/parse` | Parsuje wgrany GPX → dystans/przewyższenie + wykrycie nawierzchni (`Gpx::parse` + `RoadSurfaceDetector`). CSRF wymagany. |
| POST | `/api/ai/engine-analyze` | Most do lokalnego silnika AI (`Utils\AiEngineBridge` → `ai-engine/analyze.py`) pod rozszerzenie Chrome. **Tylko `APP_ENV=dev`** (na prod odrzucane). Świadomie bez `Auth`/`Csrf` — rozszerzenie nie ma sesji ani tokenu (tak samo jak `/api/dictionaries` i `/api/organizers/match-domain`). |
| GET | `/api/discovery/cells` | Pola Discovery w widocznym prostokącie mapy (Etap 8). Wymaga `north/south/east/west` (inaczej 400); `zoom` decyduje o poziomie agregacji. Trzy zakresy: `all` (heatmapa społeczności, domyślny), `me` (wymaga logowania, inaczej 403), `rider&slug=` (mapa z publicznego profilu — ta sama bramka co strona, `Support::visibleRider`, inaczej **404**). Zwraca ŚRODKI pól + `sizeM`, nie wielokąty. Każde pole niesie DWIE intensywności: `r` (ilu ludzi odkryło → warstwa mgły) i `p` (ile przejazdów z powtórzeniami → warstwa heatmapy), z osobnymi maksimami `max`/`maxP` liczonymi dla bieżącego kadru. |
| POST | `/api/devices/register` | **Etap 8 apki mobilnej (2026-08-28)** — rejestracja urządzenia pod push (`Models\PushDevice::register`). Sesja + CSRF. Ciało: `platform` (`android`/`ios`), `token`. Rejestracja JEST zgodą — patrz `md/database.md` (`push_devices`). Wołane z `assets/js/native.js` (`RM.native.registerPush()`) zaraz po tym, jak `@capacitor/push-notifications` odda token. |
| POST | `/api/devices/unregister` | Cofnięcie zgody (`PushDevice::deactivateForUser`) — wiersz zostaje, tylko gaśnie. Sesja + CSRF. Wołane z przełącznika w `/admin/moje-konto`. |
| POST | `/api/liczniki/{provider}/webhook` | **Automatyczny import z licznika (migr. 088, 2026-09-14)** — `DeviceWebhookController::receive`. Woła to SERWER Polara/Wahoo: bez sesji i CSRF, za to z podpisem (Polar: HMAC treści w `Polar-Webhook-Signature`) albo tokenem w treści (Wahoo). PING Polara dostaje 200 PRZED sprawdzeniem podpisu (sekret powstaje dopiero przy zakładaniu webhooka). Odpowiedź 200 idzie PRZED importem. Bez sekretu w konfiguracji 404. |
| POST | `/api/powiadomienia/otwarte` | **Etap 2 programu zachęt (2026-09-11)** — potwierdzenie otwarcia powiadomienia (`nid` z ładunku push → `NotificationGate::oznaczOtwarte`). Sesja + CSRF. Jedyne źródło `notification_log.opened_at`, czyli jedyna odpowiedź na pytanie, czy ktokolwiek te powiadomienia otwiera. Odpowiada `{ok:true}` także dla nieznanego `nid`: apka nie ma co zrobić z błędem pomiaru, a nawigacja do treści jest ważniejsza niż statystyka. |
| GET | `/api/discovery/nearby-gaps` | **Etap 1a programu zachęt (2026-09-11)** — brakujące pola tras w prostokącie (`KnownRoute::gapsNearbyForUser`), bliźniak `nearby-treasures`. Apka pobiera raz na prostokąt i liczy odległości u siebie, więc **pozycja nie wychodzi na serwer**. Oddaje WYŁĄCZNIE pola tras, które ten człowiek już zaczął — to zasada („nie prosimy o rzecz nierozsądną"), nie filtr wydajnościowy. |
| POST | `/api/powiadomienia/zgoda` | **Etap 0 programu zachęt (2026-09-11)** — zapis JEDNEJ zgody na rodzaj powiadomień (`Models\NotificationGate::ustawZgode`; `flaga` = `push_messages`/`push_nearby`/`push_progress` oraz — od Etapu 1c (migr. 082) — `mail_messages`/`mail_nearby`/`mail_progress`/`notify_matches`, `wartosc` = `0`/`1`). Sesja + CSRF; nieznana flaga → 400. Świadomie osobny od `/api/devices/*`: tamte włączają URZĄDZENIE, ten wybiera, CO na nie przychodzi — zlanie w jeden endpoint znaczyłoby, że zgaszenie rodzaju może wyrejestrować telefon. |

> Kody błędów: używamy **403** na wygasły CSRF (nie 419 — Apache/mod_php podmienia 419
> na 500). Walidacja pliku GPX rzuca `RuntimeException` → 422 z bezpieczną treścią.

## admin — [`admin/routes.php`](../admin/routes.php) (panel, wymaga logowania)

`Auth::requireLogin()` globalnie na górze. Część tras dodatkowo wymaga admina — owinięte
w lokalne helpery `$adminGet`/`$adminPost` (dorzucają `Auth::requireAdmin`). Trasy bez
tego wrappera (`/admin`, `/admin/platnosci`, `/admin/dyskusje`,
`/admin/profil-rozliczeniowy`, `/admin/moje-przejazdy*`, `/admin/moje-konto*`) są
dostępne dla każdego zalogowanego (organizatora/usera) — zakres danych zawęża wtedy
sam kontroler wg roli (dyskusje) albo wg id wołającego (moje przejazdy).

| Metoda | URL | Handler | Tylko admin? |
|---|---|---|---|
| GET | `/admin` `/admin/platnosci` | `DashboardController::index` / `payments` | nie |
| GET | `/admin/dyskusje` | `DiscussionController::index` — zbiorcza moderacja pytań i odpowiedzi ze WSZYSTKICH wydarzeń, którymi wołający zarządza (admin widzi wszystkie) | nie |
| GET/POST | `/admin/profil-rozliczeniowy` (+`/info`) | `BillingProfileController::form/save/saveInfo` | nie |
| GET | `/admin/moje-przejazdy` | `MyRidesController::index` — tabela własnych wyjazdów rowerzysty (obecność, ślad, odkrycia, punkty) | nie |
| POST | `/admin/moje-przejazdy/solo` | `SoloRideController::upload` — ślad BEZ wydarzenia (migr. 049); ta sama sekcja panelu, bo to ekran zarządzania własnymi przejazdami | nie |
| POST | `/admin/moje-przejazdy/powiaz` | `SoloRideController::link` — powiązanie przejazdu solo z turnusem (2026-08-23). Jedna trasa dla obu kierunków; pole `tab` decyduje, na którą zakładkę wrócić | nie |
| POST | `/admin/moje-przejazdy/solo/{id}/usun` | `SoloRideController::delete` — kasowanie WŁASNEGO przejazdu solo (2026-08-26), anty-IDOR + plik znika z dysku (`RiderActivity::deleteSoloForUser`) | nie |
| POST | `/admin/moje-przejazdy/licznik/{provider}/polacz` | `DeviceController::connect` — start autoryzacji OAuth (Polar, Wahoo). **Działa też na produkcji**; bez kluczy API 404 | nie |
| GET | `/admin/moje-przejazdy/licznik/{provider}/callback` | `DeviceController::callback` — powrót od dostawcy. GET, bo przekierowuje tu dostawca; chroni go jednorazowy `state` z sesji, nie CSRF | nie |
| POST | `/admin/moje-przejazdy/licznik/{provider}/odswiez` | `DeviceController::refresh` — lista nowych aktywności (do sesji) | nie |
| POST | `/admin/moje-przejazdy/licznik/{provider}/import` | `DeviceController::import` — pobiera zaznaczone i zapisuje jako przejazdy solo | nie |
| POST | `/admin/moje-przejazdy/licznik/{provider}/automat` | `DeviceController::autoImport` — przełącznik „Dodawaj nowe przejazdy automatycznie" (migr. 088, 2026-09-14), pole `wlacz=1/0`. Włączenie wymaga `DeviceApi::webhookReady`, zakresu `offline_data` (Wahoo) i identyfikatora u dostawcy (dopisuje go, gdy brak) | nie |
| POST | `/admin/moje-przejazdy/licznik/{provider}/odlacz` | `DeviceController::disconnect` — kasuje token; rejestr pobranych zostaje | nie |
| POST | `/admin/moje-przejazdy/garmin/polacz` | `GarminController::connect` — logowanie do Garmin Connect (migr. 068). **Działa tylko przy `APP_ENV=dev`** (most do Pythona), poza tym 404 | nie |
| POST | `/admin/moje-przejazdy/garmin/odlacz` | `GarminController::disconnect` — kasuje token; rejestr pobranych zostaje | nie |
| POST | `/admin/moje-przejazdy/garmin/odswiez` | `GarminController::refresh` — lista NOWYCH aktywności rowerowych (do sesji, nie do bazy) | nie |
| POST | `/admin/moje-przejazdy/garmin/import` | `GarminController::import` — pobiera zaznaczone ślady i zapisuje jako przejazdy solo | nie |
| GET/POST | `/admin/moje-konto` (+`/haslo` `/dane-adresowe` `/preferencje` `/usun`) | `AccountController::form/updateName/changePassword/updateBillingAddress/updatePreferences/requestDeletion` — `/usun` (Etap 9, 2026-08-29) to samoobsługowe kasowanie konta: blokuje od razu (`Models\User::requestDeletion`), wylogowuje, przekierowuje na `/logowanie?info=konto-do-usuniecia`. `GET ?sekcja=profil|haslo|faktura|preferencje` (2026-09-13, biała lista) otwiera wskazaną zakładkę — używa go panel „Twoje ustawienia" na własnym profilu, razem z kotwicami boksów `#jak-jezdze`, `#prywatnosc`, `#powiadomienia`, `#powiadomienia-mail` | nie |
| GET/POST | `/admin/taksonomia` (+`/dodaj` `/{id}/edytuj` `/{id}/przelacz`) | `TaxonomyController::index/create/update/toggle` | **tak** |
| GET/POST | `/admin/regiony-mapa` (+`/geometria` `/zapisz` `/pole` `/dodaj` `/usun-obrys`) | `RegionMapController::index/geometry/save/assignCell/createRegion/removeOutline` — RYSOWANIE OBRYSÓW REGIONÓW (2026-09-01). `save` przyjmuje `{region, ring:[[lat,lon],…]}` i zapisuje CAŁY wielokąt do `data/regiony.geojson` — pokrycia heksami NIE liczy, robi to `backfill_regions.php`. `pole` (2026-09-10) to INNA droga: `{region, lat, lon}`, przypisuje JEDEN heks wprost do `region_cells` z pominięciem geometrii/pliku — jedyny sposób na punktową korektę bez zastępowania geometrii całego regionu; działa też na województwach z importu | **tak** |
| GET | `/admin/znane-trasy` | `KnownRouteController::index` — LISTA tras Discovery: `?q=` (nazwa/slug/region), `?filtr=` (aktywne·wylaczone·do-przeliczenia·bez-punktow), `?sort=` (najnowsze·dystans·pola·region), `?strona=`. Dane z `KnownRoute::search()` | **tak** |
| GET | `/admin/znane-trasy/nowa` | `KnownRouteController::createForm` — formularz dodawania. **Statyczna, więc PRZED `/{id}/...`** | **tak** |
| POST | `/admin/znane-trasy/dodaj` | `KnownRouteController::create` — GPX → `KnownRoute::createFromGpx` | **tak** |
| GET/POST | `/admin/znane-trasy/{id}/edytuj` | `KnownRouteController::editForm/update` — WŁASNA PODSTRONA formularza (nie rozwijany blok przy wierszu: katalog ma dorosnąć do setek tras). Obejmuje TAKŻE zdjęcie i opcjonalną podmianę przebiegu (dawne `/{id}/zdjecie` usunięte 2026-08-19). Stan listy (`q/filtr/sort/strona`) jedzie w adresie i w polach `wroc_*`, żeby zapis wracał na tę samą stronę wyników | **tak** |
| POST | `/admin/znane-trasy/{id}/przelicz` `/{id}/przelacz` `/{id}/usun` | `recalculate/toggle/delete`. `przelicz` liczy pola od nowa z ZAPISANEGO pliku (naprawa tras sprzed migr. 048). Trasy nigdy nie wgrywa się od nowa, żeby ją zmienić — nowe id skasowałoby postęp wszystkich | **tak** |
| GET/POST | `/admin/emblematy` (+`/zapisz` `/przelicz` `/{id}/usun`) | `Admin\EmblemController::index/save/recalculate/delete` — **emblematy za przejechanie CAŁEJ trasy** (migr. 087, 2026-09-11). Lista i formularz na jednym ekranie. `przelicz` nadaje emblematy wstecz (`Emblem::sync()`), **statyczna więc PRZED `/{id}/usun`**. Przypisanie do trasy jest w formularzu trasy, do wydarzenia — w `/admin/punkty`. | **tak** |
| GET/POST | `/admin/skarby` (+`/zapisz` `/wydruk` `/punkty` `/{id}/nowy-kod` `/{id}/status` `/{id}/usun`) | `TreasureController::index/save/printSheet/points/rotateCode/setStatus/delete` — skarby na mapie (Etap 8D): klik w mapę stawia nowy punkt, klik w pinezkę otwiera go w panelu obok (i pozwala przeciągnąć), `/punkty` oddaje JSON dla KADRU mapy (pełna precyzja — wejście adminowe, nie publiczne `/api/treasures`), `/{id}/usun` kasuje punkt, którego **nikt nie znalazł** (znaleziony schodzi statusem `RETIRED`, żeby nie osierocić rejestru punktów) | **tak** |
| GET/POST | `/admin/uzytkownicy` (+`/{id}/blokuj` `/odblokuj` `/reset-hasla` `/usun`) | `UsersController::index/block/unblock/resetPassword/delete` — moderacja kont (migr. 058). Kasowanie tylko kont PUSTYCH (`UserAdmin::deleteIfEmpty`), reszta idzie przez blokadę | **tak** |
| GET/POST | `/admin/kafle` (+`/purge` `/prune` `/geometria` `/kolory` `/napraw-indeks`) | `TilesController::index/purge/prune/backfill/colors/repairIndex` — cache kafli map: co leży na dysku, reset warstwy, przycięcie do limitu, policzenie geometrii, **przydział kolorów śladom** (migr. 073 — sam kasuje potem kafle warstwy `slady`) oraz **naprawa niekompletnego indeksu `gpx_tiles`** (2026-09-07 — stare szkody po wyścigu zapisu sprzed transakcji w `GpxGeometry::ensure()`, bez czytania pliku GPX). To samo, co `php tiles.php`, tylko bez konsoli | **tak** |
| GET/POST | `/admin/punkty` (+`/stawki` `/trasa/{id}` `/wydarzenie/{id}`) | `PointsController::index/saveRates/saveRoute/saveEvent` — stawki globalne (migr. 053, `scoring_settings`), bonus per trasa (migr. 043) i per wydarzenie (migr. 044) + podgląd ostatnich naliczeń | **tak** |
| GET/POST | `/admin/powiadomienia` | `Admin\NotificationsController::index/save` — **zarządzanie powiadomieniami** (2026-09-11, migr. 083): jak często (budżety per kanał), w jakim czasie (cisza nocna, okno wysyłki, okno świeżości) i co wysyłamy (wyłączniki kanałów i rodzajów), plus dziennik wysyłek z `notification_log`. Do tej daty wszystkie te decyzje siedziały w stałych `NotificationGate`, a pora wysyłki nawet nie w aplikacji, tylko w crontabie. | **tak** |
| GET/POST | `/admin/organizatorzy` (+`/{slug}/edytuj` `/rozliczenia` `/aktywnosc`) | `OrganizerAdminController::index/editForm/update/saveBilling/toggleActive` | **tak** |

## Prefiks języka `/en/…`

Trasy są zapisane TYLKO po polsku; `Router::stripBasePath` zdejmuje prefiks języka (`Core\Lang::splitPath`), więc `/en/wydarzenia` trafia w `/wydarzenia`. Nowe trasy nic nie muszą robić — chyba że adres woła ktoś z zewnątrz (callback OAuth, webhook): wtedy dopisz wzorzec do `Lang::BEZ_PREFIKSU`. Nowe: `POST /api/jezyk` (zapamiętanie wyboru), `GET/POST /tlumaczenie/{typ}/{id}` (korekta tłumaczenia).

## Pułapki kolejności (nie przestawiać)

- `/wydarzenia` przed `/wydarzenia/nowe` i `/wydarzenia/{slug}/*`.
- `/organizatorzy` przed `/organizatorzy/{slug}`.
- `/wiadomosci/grupa/{editionId}` przed `/wiadomosci/{id}` (inaczej `{id}` złapie „grupa").
- `/trasy` przed `/trasy/{slug}` (inaczej katalog wpadłby do wzorca ze slugiem).
- `/przejazd/{id}/gpx` przed `/przejazd/{id}` — zasada „szczegółowe przed ogólnym".
- `/wydarzenia/{slug}/komentarz/faq` przed `/wydarzenia/{slug}/komentarz/{commentId}/usun`
  (inaczej `{commentId}` złapie „faq").
- `/api/events/map` przed `/api/events/{slug}` (inaczej `{slug}` = „map").
- `/odkrycia/spolecznosc` przed `/odkrycia` — dziś oba są statyczne, więc kolejność
  nic nie zmienia, ale gdyby doszło `/odkrycia/{cokolwiek}`, wzorzec z parametrem
  złapałby „spolecznosc".
