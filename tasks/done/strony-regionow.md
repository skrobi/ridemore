# Strony regionów — kontrakt implementacyjny

Data przygotowania: 2026-09-14. **Zaakceptowany tego samego dnia** z dwiema
odpowiedziami usera: „progu nie ma, nie wprowadzaj” i „kto tu jeździ dodaj,
fajny pomysł”.

## Cel

Każdy region ze słownika `region` dostaje **publiczną, indeksowaną stronę**
pod `/regiony/{kraj}/{region}` (np. `/regiony/polska/podkarpackie`). Strona
zbiera w jednym miejscu to, co serwis już wie o danym terenie: wyjazdy
nadchodzące i odbyte, znane trasy, skarby, organizatorów, postęp odkrywania
i ludzi, którzy tam jeżdżą, oraz mapę. Ma odpowiadać na wyszukiwania typu „wyjazdy rowerowe podkarpackie”,
na które dziś nie odpowiada żadna strona serwisu.

## Skąd to się wzięło

Audyt SEO 2026-09-14: serwis pojawia się w Google wyłącznie na hasło
„ridemore bike”. Filtry regionów na `/wydarzenia` mają canonical na samo
`/wydarzenia`, więc Google nie widzi żadnej strony „o regionie”. User
zaakceptował kierunek („przygotuj zatem strony regionów”), adres
`/regiony/polska/podkarpackie` oraz zasadę: **szablon ze stron istniejących,
podstawą strona znanej trasy (`/trasy/{slug}`) i elementy już obecne
w aplikacji.**

## Stan zastany (sprawdzony w kodzie i w bazie dev, nie z pamięci)

- **Region = pozycja słownika `region`** (`dictionary_items`, hierarchia
  `parent_id`). Od migr. 070 liśćmi w Polsce jest **16 województw** pod
  kontenerem „Polska”; dawne pasma (Bieszczady, Tatry, Beskidy, Mazury,
  Podkarpacie) mają `is_active=0`. Za granicą regiony rysuje się ręką
  w `/admin/regiony-mapa` — także jako **kraj bez dzieci** (Słowacja: kraj
  jest swoim jedynym regionem).
- **Kody są ASCII** (`malopolskie`, `dolnoslaskie`, `slaskie`) — nadają się
  wprost na segment adresu, bez nowego sluga.
- **Powiązania z regionem, które już istnieją:** `event_regions` (wiele
  regionów na wydarzenie), `known_route_regions`, `rider_activity_regions`,
  `treasures.region_item_id`, `organizer_profiles.region_item_id`,
  `region_cells` + `region_cell_counts` (geometria i mianownik pokrycia).
- **Dane dev na dziś** (większość regionów pusta — stąd zasada „pusta sekcja się nie renderuje”): podkarpackie 11 wydarzeń / 1 trasa / 1 organizator,
  małopolskie 4/2/0, śląskie 3/1/0, warmińsko-mazurskie 2/0/0, lubuskie
  i świętokrzyskie po 1 trasie, reszta województw pusta.
- **Gotowe do użycia:**
  - Szkielet strony trasy `views/web/pages/trail.php`: `.op-head` (tagi,
    `h1`, `.op-head__sub`, akcje), `.op-cover` z `.hero-bottom`, sekcje
    `.sec > .box > h2`.
  - `partials/stat-tiles.php` (`renderStatTiles`), `partials/breadcrumbs.php`
    (sam emituje BreadcrumbList).
  - Mapa: `.disc-map` + `discovery-map.js` + `partials/map-layers.php`
    z drzewem `MapLayer::tree($ctx, ['only' => ['cells','heat','trails','treasures']])`
    — dokładnie tak jak w `TrailController::show`.
  - `partials/event-card.php`, `partials/trail-card.php`,
    `partials/treasure-list.php` (poziomy ujawnienia przez `reveal()`),
    `partials/region-emblems.php`.
  - `Discovery::regionProgress(?userId)` — pokrycie per region z
    `countryCode/countryName` (społeczność i widz), ~100 ms.
  - `RegionOutline::allRegions()` — pierścienie WSZYSTKICH regionów
    (zaimportowane województwa + rysowane), czyli kadr mapy bez zapytania
    do 1,5 mln heksów.
  - Filtr `regions` w wyszukiwaniu wydarzeń (`Event`, lista `/wydarzenia`
    i odbyte) oraz w `Organizer::search`.
  - `JsonLd::forEventList` (ItemList samych adresów), `JsonLd::forNode`.
- **Czego nie ma:** publicznej listy tras w regionie (`KnownRoute::search`
  szuka po NAZWIE regionu — „śląskie” łapie „dolnośląskie”), listy skarbów
  w regionie (jest tylko `Treasure::collectionProgress` z tym samym
  `WHERE`), partiala karty organizatora (karta siedzi inline
  w `organizers-list.php`), opisu regionu gdziekolwiek.

## Decyzje

1. **Adres:** `/regiony/{kraj}/{region}`, kody wprost ze słownika.
   `/regiony/{kraj}` — strona kraju, `/regiony` — spis krajów i regionów.
   Kraj bez dzieci (Słowacja) ma TYLKO `/regiony/slowacja`, i to jest jego
   strona regionu. Region pod złym krajem → 301 na właściwy adres.
   Nieaktywny albo nieistniejący kod → 404 (dawne pasma nigdy nie miały
   stron, więc nie ma czego przekierowywać). *(zaakceptowane)*
2. **Szablon = strona znanej trasy.** Ten sam układ i te same klasy
   (`.op-head`, `.op-cover`, `renderStatTiles`, `.sec/.box`, `.disc-map`).
   **Zero nowego CSS poza tym, bez czego nie da się ułożyć sekcji** — każda
   sekcja poniżej wskazuje istniejący komponent. *(zaakceptowane)*
3. **Bez zmian w schemacie bazy.** Wszystko czyta się z istniejących tabel.
4. **Bez progu indeksowania** (decyzja usera): KAŻDA aktywna strona regionu,
   kraju i spis są indeksowane i są w sitemapie, także region bez treści.
5. **Nowy kontroler `RegionController`** (publiczny, bliźniak
   `TrailController`), osobny od `Admin\RegionMapController`, który rysuje
   obrysy. Ta sama para co `Admin\KnownRouteController` / `TrailController`.
6. **Nie tworzymy drugiej ścieżki do danych** — nowe metody modeli tylko
   tam, gdzie zapytania brakuje (lista tras w regionie, lista skarbów
   w regionie), i w kształcie tym samym co ich odpowiedniki dla trasy.

## Zakres

### Etap 1 — strona regionu

**Routing i kontroler**
- `GET /regiony`, `GET /regiony/{kraj}`, `GET /regiony/{kraj}/{region}`
  w `web/routes.php` (statyczne przed wzorcami ze zmienną).
- `RegionController::show(kraj, region)`: rozwiązanie kodu przez
  `Dictionary`, bramka aktywności i rodzica (404/301 wg Decyzji 1), zebranie
  danych.

**Sekcje strony (`views/web/pages/region.php`) — każda na istniejącym komponencie**

| # | Sekcja | Komponent / źródło |
|---|---|---|
| 1 | Nagłówek: tagi „Region” + kraj, `h1` (nazwa z wielkiej litery), podpis „województwo · Polska” | `.op-head` z `trail.php` (gałąź bez okładki) |
| 2 | Okładka, gdy jest z czego: zdjęcie najbliższego wyjazdu albo znanej trasy z regionu, która ma `cover_photo_url` | `.op-cover` + `.hero-bottom` z `trail.php`; bez zdjęcia — sam nagłówek z pkt 1 |
| 3 | Kafle: nadchodzące wyjazdy · znane trasy (liczba + suma km) · organizatorzy · skarby · odkryte (społeczność %, zalogowany: własny %) | `renderStatTiles`; zasada „gość nie dostaje zer” jak na trasie |
| 4 | Mapa regionu z warstwami (odkrycia, heatmapa, znane trasy, skarby), kadr = bbox pierścienia regionu | `.disc-map` + `map-layers.php` + `MapLayer::tree` (`only` jak na trasie); kadr z `RegionOutline::allRegions()` |
| 5 | Nadchodzące wyjazdy (do 6) + „Wszystkie wyjazdy w regionie” → `/wydarzenia?regions[]={kod}` | `event-card.php`, istniejący filtr `regions` |
| 6 | Znane trasy w regionie | `trail-card.php` + nowe `KnownRoute::inRegion(regionId)` po `known_route_regions` |
| 7 | Co zobaczysz w regionie (skarby) | `treasure-list.php` + nowe `Treasure::listInRegion(regionId, viewerId)` z tym samym `reveal()` co `listOnRoute` |
| 8 | Organizatorzy z regionu | `Organizer::search(['regions' => [kod]])`; karta **przeniesiona** z `organizers-list.php` do `partials/organizer-card.php` i używana w obu miejscach |
| 9 | Już się odbyło (ostatnie odbyte wyjazdy, link do kroniki, gdy jest) | `event-card.php`, filtr `regions` na widoku odbytych |
| 10 | Kto tu jeździ — awatary rowerzystów z PUBLICZNYM profilem, którzy mają przejazdy w regionie, najaktywniejsi ostatnio pierwsi, link do profilu | `partials/rider-avatar.php` (jak „Mają ją całą” na trasie) + nowe `RiderActivity::ridersInRegion(regionId)` po `rider_activity_regions`; bramka profilu tą samą regułą co `/rowerzysta/{slug}` |
| 11 | Inne regiony w tym kraju (linki) | `.chip-links` z dołu `/wydarzenia` |

Sekcja bez treści **nie renderuje się** (żadnych pustych nagłówków
„Brak tras”), z wyjątkiem pkt 5: pusty region dostaje jedno zdanie
z zaproszeniem „Dodaj wyjazd” (ten sam CTA co na `/dla-organizatorow`).

**Strona kraju i spis** (`views/web/pages/regions.php`, jeden widok dla obu)
- `/regiony/{kraj}`: nagłówek jak w pkt 1, kafle z sumą kraju
  (`regionProgress` → `countries`), lista regionów kraju z licznikami treści
  jako linki.
- `/regiony`: to samo dla wszystkich krajów, pogrupowane jak w
  `region-emblems.php` (kraj domowy pierwszy).

**SEO**
- Tytuł: „Wyjazdy rowerowe i trasy — {Region} | ridemore.bike”; kraj:
  „Wyjazdy rowerowe — {Kraj}: regiony | ridemore.bike”.
- Opis z liczb: „{N} nadchodzących wyjazdów, {M} znanych tras
  i {K} organizatorów w regionie {region}. Gravel, MTB i szosa…” (z poprawną
  odmianą `Format::plural`).
- JSON-LD: BreadcrumbList (sam z `breadcrumbs.php`), `ItemList` wyjazdów
  (`JsonLd::forEventList`), węzeł `AdministrativeArea` z nazwą i `geo`
  (środek bbox) przez `JsonLd::forNode`.
- `/sitemap-regions.xml` w indeksie sitemap — wszystkie aktywne regiony, kraje i spis.

**Linkowanie wewnętrzne** (bez tego Google nie znajdzie stron)
- Strona wydarzenia: element ścieżki nawigacji z nazwą regionu dostaje link
  (dziś jest bez linku) — przy wielu regionach pierwszy.
- Strona trasy: tag regionu w `.op-head` staje się linkiem.
- Profil organizatora: „Region” w faktach linkuje do strony regionu zamiast
  do filtra `/wydarzenia?regions[]=`.
- `/wydarzenia`: chipy regionów w bloku SEO na dole linkują do stron regionów.
- Stopka: „Regiony” w kolumnie „Wyjazdy”.

**Testy** — `tests/wyglad_stron_regionow_test.php`
- 200 dla aktywnego regionu, 301 przy złym kraju, 404 dla nieaktywnego
  (`bieszczady`) i nieistniejącego kodu; kraj bez dzieci pod jednym segmentem.
- Sitemapa regionów zawiera każdy aktywny region, żadnego nieaktywnego.
- `KnownRoute::inRegion`: „śląskie” nie zwraca tras z „dolnośląskiego”.
- `Treasure::listInRegion` maskuje tak samo jak `listOnRoute` (gość nie
  widzi nazw zagadek).
- „Kto tu jeździ” nie pokazuje rowerzysty z ukrytym profilem.
- JSON-LD strony regionu to poprawny JSON z `ItemList` i `AdministrativeArea`.
- Smoke test w przeglądarce: podkarpackie (pełne), województwo puste,
  `/regiony/polska`, `/regiony`, mobile.

**Dokumentacja** — `md/routing.md`, `md/controllers.md`, `md/models.md`
(nowe metody), `md/views-and-frontend.md` (nowe widoki i partial
organizatora), `md/features.md` (sekcja „Strony regionów”).

## Poza zakresem (świadomie)

- **Regiony turystyczne w obrębie województw** (Bieszczady, Tatry, Jura).
  Obecny niezmiennik „jeden heks = jeden region” nie pozwala im nachodzić
  na województwo — to osobna decyzja o modelu danych.
- **Strony typów roweru i połączeń region + typ** — dopiero gdy w danej
  kombinacji regularnie jest kilka wyjazdów.
- **Ręcznie pisany opis regionu i zdjęcie regionu** (wymagałyby miejsca
  w danych i edycji w panelu).
- Zmiana działania filtrów na `/wydarzenia` — zostają jak są.

## Kryteria akceptacji

1. `/regiony/polska/podkarpackie` pokazuje wszystkie sekcje z danymi, mapa
   kadruje województwo, kafle zgadzają się z listami pod nimi.
2. Pusty region renderuje się bez pustych sekcji.
3. Strona zbudowana wyłącznie z istniejących komponentów; karta organizatora
   jest jednym partialem używanym na liście organizatorów i na stronie regionu.
4. Każda strona regionu jest osiągalna linkiem z co najmniej: spisu
   `/regiony`, strony kraju, każdego wydarzenia i trasy w tym regionie.
5. Sitemapa zawiera wszystkie aktywne regiony.
6. Testy przechodzą; brak regresji w pozostałych zestawach.
