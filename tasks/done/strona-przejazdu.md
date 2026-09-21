# Strona przejazdu — kontrakt implementacyjny

Data przyjęcia: 2026-09-03. Kierunek i cztery decyzje zaakceptowane przez usera
w tej samej rozmowie.

## Cel

Każdy przejazd dostaje **własną stronę-temat** pod adresem `/przejazd/{id}`:
mapa, komplet liczb, profil wysokości, plik GPX do pobrania i konteksty dookoła
(skarby, znane trasy, regiony, punkty, wydarzenie). Dziś przejazd nie ma
żadnego ekranu poza wierszem w tabeli — dymek na mapie prowadzi do
`/admin/moje-przejazdy?tab=solo&q={data}`, czyli do LISTY zawężonej po dacie,
bo nie ma dokąd prowadzić.

## Skąd to się wzięło

User, 2026-09-03: „Jeśli mamy przejazd solo zróbmy dla niego dedykowaną stronę,
aby móc zobaczyć tylko ten przejazd z wszystkimi danymi które mamy w gpx, może
ktoś będzie chciał ściągnąć. Była by tam też informacja jakie skarby zaliczone,
może jakieś zdjęcia jeśli wrzucone i powiązane z jakąś wycieczką, z jakim
eventem połączone jeśli połączone. Można też pokazać inne kroniki jeśli
powiązane z trasą, coś jak komoot ma."

## Stan zastany (sprawdzony w kodzie i w bazie, nie z pamięci)

- **`rider_activities`** niesie: `source_code` (`solo` · `own_track` ·
  `event_track`), `edition_id`, `rsvp_id`, `gpx_url`, `gpx_hash`, `ride_date`,
  `started_at`, `moving_seconds`, `distance_km`, `elevation_gain_m`,
  `cells_touched`, `cells_new`. W bazie dev: 91 solo, 9 z wyjazdu.
- **Przejazd solo powiązany z wyjazdem PRZESTAJE BYĆ SOLO** —
  `RiderActivity::linkSoloToEdition()` kasuje wiersz solo i przerabia plik na
  `edition_tracks`, żeby kilometry nie liczyły się dwa razy. „Solo + event"
  nie istnieje; event pokazuje więc strona przejazdu Z WYJAZDU.
- **Ślad dla obcych jest już policzony**: `gpx_geometry_trimmed` (migr. 076)
  trzyma geometrię PO odcięciu okolic domu (`DiscoveryGrid::trimEnds` tym samym
  promieniem, którym liczą się pola solo). Karmi dziś wspólną heatmapę.
- **`/api/rides/{id}/track`** oddaje spakowaną geometrię (`d6v`), a dostęp
  liczy `Support::rideGpxPath()`: ślad wyjazdu każdemu, plik solo **wyłącznie
  właścicielowi** (§27) — obcy dostaje dziś 404.
- **Pliku GPX nie wolno przycinać na dysku** (nota w `Utils\Gpx`, 2026-08-26):
  plik jest źródłem dystansu przy powiązaniu z turnusem.
- Gotowe do użycia: `partials/stat-tiles.php`, `partials/map-layers.php`,
  `ridemoreDiscoveryMap`, `ridemoreFocusTrack`, `Gpx::parse` →
  `elevationProfile` + `Gpx::detectPeaks` + `ridemoreLinkElevationProfile`,
  `PointLedger::forActivity()`, `rider_activity_regions`,
  `rider_activity_cells`, `Treasure::onRoute()`/`listOnRoute()` (dziś po
  `known_route_cells`), `TreasurePhoto`, `EventPhoto`, `Support::publicRider()`.
- **Czego nie ma nigdzie**: tętna, kadencji, mocy, kalorii. `Gpx::parse` czyta
  wyłącznie lat/lon/wysokość/czas, a rejestr Garmina (`device_activities`)
  trzyma nazwę, datę i dystans. Nie ma też zdjęć wgrywanych DO przejazdu.

## Decyzje (zaakceptowane, nie wracamy do nich)

1. **Strona dotyczy KAŻDEGO przejazdu**, nie tylko solo. Jeden wzorzec
   `/przejazd/{id}` dla wiersza `rider_activities` — inaczej sekcje
   „wydarzenie / zdjęcia / kronika" nie miałyby się gdzie pojawić.
2. **Strona jest PUBLICZNA, ale ślad obcego jest przycięty.** Właściciel widzi
   pełną geometrię i pobiera swój oryginalny plik; każdy inny widzi geometrię
   z `gpx_geometry_trimmed` i pobiera GPX złożony z tej samej, przyciętej
   linii. Plik na dysku zostaje nietknięty.
3. **Obcy wchodzi tylko na przejazd rowerzysty z publicznym profilem** —
   `Support::publicRider()`, ten sam strażnik co na `/rowerzysta/{slug}`.
   Brak dostępu = 404, nie 403 (jak przy ukrytym profilu i kaflu spod
   niedostępnego klucza).
4. **Nie tworzymy drugiej drogi do geometrii** (wprost od usera: „już masz
   endpoint track"). `/api/rides/{id}/track` przestaje odmawiać obcemu przy
   solo i zaczyna oddawać wariant PRZYCIĘTY — jeden endpoint, dwie odpowiedzi
   zależne od tożsamości z sesji.
5. **Nowy kontroler `RideController`**, nie `SoloRideController`: tamten jest
   od WGRYWANIA i akcji na przejazdach solo (`/admin/...`), a to jest publiczna
   strona-temat, bliźniak `TrailController` dla `/trasy/{slug}`.

## Zakres

### Etap 1 — szkielet strony
- Trasa `GET /przejazd/{id}` w `web/routes.php` (przed wzorcami ze zmienną,
  które mogłyby ją połknąć).
- `RideController::show()`: pobranie przejazdu, bramka widoczności (właściciel
  albo publiczny profil właściciela), 404 w obu przypadkach odmowy.
- `RiderActivity::findForPage(int $id): ?array` — wiersz + autor (nazwa, slug,
  avatar) + nazwa z licznika (`device_activities.activity_name`) + kolor śladu
  (`gpx_geometry.color_index`).
- Widok `views/web/pages/ride.php`: nagłówek (nazwa albo „Przejazd solo",
  data, autor, znaczek solo/wyjazd), kafle `stat-tiles` (dystans,
  przewyższenie, czas ruchu, średnia, nowe pola, punkty), mapa standardowa
  z warstwami, `breadcrumbs`.

### Etap 2 — ślad dla obcego i pobieranie
- `GpxGeometry::packedTrimmedForFile()` — bliźniak `packedForFile()` na
  `gpx_geometry_trimmed` (+ `ensureTrimmed()` jako ścieżka leniwa).
- `/api/rides/{id}/track`: właściciel i ślad wyjazdu bez zmian; solo dla obcego
  → wariant przycięty zamiast 404.
- `GET /przejazd/{id}/gpx` — pobranie. Właściciel: przekierowanie na oryginalny
  plik. Obcy przy solo: GPX zbudowany z przyciętej geometrii (lat/lon; bez
  wysokości i czasu, bo przycięta geometria ich nie trzyma — powiedziane
  wprost w opisie przycisku).

### Etap 3 — bloki treści
- **Skarby na tym przejeździe** — `Treasure::onActivity()`/`listOnActivity()`,
  bliźniaki `onRoute`/`listOnRoute` po `rider_activity_cells`; zdobyte
  odróżnione od tych, które się minęło. Zdjęcia skarbów (`TreasurePhoto`)
  wgrane przez właściciela wchodzą jako galeria — to jedyne istniejące dziś
  zdjęcia związane z przejazdem solo.
- **Znane trasy** — po których trasach prowadził przejazd
  (`known_route_cells ∩ rider_activity_cells`) + co ten przejazd ruszył lub
  ukończył (`point_transactions` z `activity_id`, źródła `TRAIL_*`).
- **Regiony** — `rider_activity_regions`.
- **Punkty** — `PointLedger::forActivity()`, wiersz na źródło.
- **Wydarzenie** (gdy `edition_id`) — karta wyjazdu, link do kroniki, zdjęcia
  z tego turnusu, relacja.
- **Moje inne przejazdy tędy** — WYŁĄCZNIE dla właściciela (obcemu nie
  pokazujemy, gdzie ta osoba jeszcze bywa).

### Etap 4 — profil wysokości
- `Gpx::parse` → `elevationProfile` + `Gpx::detectPeaks` (chipy podjazdów),
  `ridemoreRenderElevationChart` + `ridemoreLinkElevationProfile` jak na
  `/trasy/{slug}`. Dla obcego przy solo profil liczy się z tych samych
  przyciętych punktów co mapa albo nie pokazuje się wcale.

### Etap 5 — podpięcia i dokumentacja
- Dymek mapy „Zobacz przejazd" prowadzi na `/przejazd/{id}` zamiast na listę
  zawężoną po dacie (`api/routes.php`, `/api/discovery/rides/at`).
- Zakładka „Przejazdy solo" (`/admin/moje-przejazdy`) i panel aktywności na
  mapach linkują do strony przejazdu.
- `md/routing.md`, `md/controllers.md`, `md/features.md`, `md/models.md`
  zaktualizowane; testy w `php tests/run.php przejazdy_solo` (bramka
  widoczności, przycięta geometria dla obcego, anty-IDOR na pobieraniu).

## Poza zakresem

- Tętno, kadencja, moc, kalorie — nie ma ich w danych; parsowanie rozszerzeń
  GPX to osobne zadanie.
- Wgrywanie zdjęć DO przejazdu (nowa tabela) — pokazujemy tylko zdjęcia, które
  już istnieją (skarby, wydarzenie).
- Nawierzchnia z `RoadSurfaceDetector` — jedno zapytanie do Overpassa na
  przejazd, potrzebowałaby własnej pamięci wyników.
- Komentarze, polubienia, „kto tu jeszcze jeździł" — cudze przejazdy w tej
  okolicy zostają niewidoczne.
- Zmiana schematu bazy: żadna nowa tabela ani kolumna.

## Kryteria akceptacji

1. `/przejazd/{id}` otwiera się właścicielowi dla każdego jego przejazdu.
2. Obcy (także niezalogowany) widzi stronę przejazdu rowerzysty z publicznym
   profilem, a ślad solo ma na niej przycięte okolice domu.
3. Przejazd rowerzysty bez publicznego profilu daje obcemu 404.
4. Właściciel pobiera swój oryginalny plik; obcy przy solo pobiera plik
   przycięty. Cudzego oryginału nie da się pobrać ŻADNYM adresem.
5. Strona pokazuje: liczby, mapę ze śladem w kolorze przejazdu, profil
   wysokości, skarby, znane trasy, regiony, punkty i wydarzenie (gdy jest).
6. Dymek „Zobacz przejazd" na mapie prowadzi na tę stronę.
7. `php tests/run.php` przechodzi, a strona jest sprawdzona żywo w przeglądarce
   w trzech rolach: właściciel, obcy zalogowany, niezalogowany.

---

## WYKONANIE — KONTRAKT ZAMKNIĘTY 2026-09-03

> **Sekcja dopisana 2026-09-12 z opóźnieniem.** Praca poszła na produkcję
> 2026-09-03, ale ten plik nigdy nie dostał domknięcia i przez dziewięć dni
> wyglądał w `tasks/active/` na robotę w toku. Poniżej nie ma nic nowego —
> jest spis tego, co udało się odnaleźć W KODZIE 2026-09-12, kryterium po
> kryterium. Czego nie dało się potwierdzić kodem, jest oznaczone wprost.

| # | Kryterium | Czym potwierdzone |
|---|---|---|
| 1 | strona dla właściciela | `web/routes.php` → `/przejazd/{id}`, `RideController::show` |
| 2 | obcy widzi przycięte solo | `Controllers\Support::strangerTrackPath()` — jedna bramka dla strony i dla endpointu geometrii |
| 3 | brak publicznego profilu → 404 | ta sama metoda oddaje `null`, gdy `visibleRiderById()` nic nie zwróci |
| 4 | pobieranie: właściciel oryginał, obcy przycięty | `RideController::gpx` + trasa `/przejazd/{id}/gpx` |
| 5 | liczby, mapa, profil wysokości, skarby, trasy, regiony, punkty, wydarzenie | `RideController::show`: `Gpx::detectPeaks`, `PointLedger::forActivity`, `RiderActivity::regionsFor`, `Treasure::onActivity`/`listOnActivity`, warstwy `['cells','heat','trails','treasures']`; widok `views/web/pages/ride.php` |
| 6 | dymek „Zobacz przejazd" | `assets/js/discovery-map.js`; dymek przeszedł jeszcze naprawę 2026-09-11 (dwa `map.on('click')` kasowały się wzajemnie — patrz `tests/zgloszenia_2026_09_11_test.php`) |
| 7 | testy + żywe oględziny w trzech rolach | `php tests/run.php` zielone 2026-09-12 poza trzema znanymi, niezwiązanymi fail-ami na danych dev (`diagnostyka_pustych_kafli`, `przejazdy_solo` ×2). Testy nazwane wprost o tej stronie są w `tests/przejazdy_solo_test.php` (nazwa przejazdu na stronie, anty-IDOR na cudzej nazwie, §27 przy skarbie na mecie). **Oględzin w trzech rolach nie da się dziś odtworzyć** — hasła kont testowych są nieaktualne (patrz pamięć `reference_local_test_login`); przy zamykaniu kontraktu 2026-09-03 były zrobione. |

**Kadr mapy liczy się na serwerze**, nie z wczytanego śladu — `GpxGeometry::boundsFor`
/ `boundsForTrimmed` (ta druga dostała 2026-09-12 wspólne ciało z pierwszą, przy
obrazku śladu na karcie Pulsu; dla jednego śladu zachowanie bez zmian, pilnuje
tego test regresji w `tests/kadr_mapy_test.php`).

**Poza zakresem zostało to, co spisano wyżej** — z jednym wyjątkiem: tętno,
kadencja, moc i temperatura z `<extensions>` GPX doszły 2026-09-11 jako osobne
wykresy (zgłoszenie usera), więc punkt „nie ma ich w danych" jest już nieaktualny.
