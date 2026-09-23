# Ridemore Planner – Simplified Architecture

> **PROPOZYCJA — czeka na akceptację usera (2026-09-23).** Nic z tego nie jest
> jeszcze zaimplementowane; w kodzie nie zmieniono ani linii. Dokument powstał
> z analizy kodu (nie z pamięci ani z samej dokumentacji). Bazy danych nie dało
> się odpytać z tego środowiska (brak MySQL), więc liczności danych pochodzą
> z kontraktu `warstwa-routingu-ridemore.md` (pomiar na dev 2026-09-18), a
> struktura — z migracji i modeli.
>
> Po akceptacji ten plik staje się kontraktem (status etapów w nagłówkach,
> `tasks/README.md`), a `warstwa-routingu-ridemore.md` dostaje notę, które
> jego etapy są zamrożone.
>
> **Aktualizacja 2026-09-23:** dopisana sekcja 14 — uwagi drugiego zespołu
> (przegląd ekspercki) zestawione z kierunkiem z zadania i stanem kodu.

**Zdanie, które rozstrzyga spory:** Ridemore nie rysuje trasy — pokazuje,
którędy **warto** pojechać. OSRM mówi „którędy można”, dane Ridemore mówią
„którędy warto”, a scoring wybiera kompromis.

**Wniosek z analizy w jednym akapicie:** silnik tej idei już jest i działa
(`Utils\RidemoreRouting` + `Models\RidemoreCorridors`: OSRM baseline →
korytarze z przejazdów i znanych tras → warianty → jedna macierz OSRM → koszt
całej trasy z bonusem Ridemore i twardym limitem objazdu). Brakuje trzech
rzeczy: **(1) wejścia „odpowiedz na 4 pytania”** zamiast klikania punktów,
**(2) pętli „~60 km stąd”** — dziś planer umie tylko A→B po klikniętych
punktach, **(3) podsumowania wyniku** (przewyższenie, skarby po drodze,
udział sprawdzonych odcinków). Najtańsza droga to dołożyć te trzy rzeczy NA
istniejącą warstwę i schować obecny panel pod „Zaawansowane” — nie przepisywać
warstwy.

---

## 1. Aktualna architektura

```
/planer (views/web/pages/planner.php, assets/js/planner.js + planner/route-model.js)
  klik na mapie = waypoint; prawa kolumna: Źródła trasy, baza (trasa/GPX),
  przełącznik „Dołączaj dłuższe odcinki Ridemore”, profil roweru
        │  POST /api/planer/oblicz  {waypoints, sources, autoJoin, base, profile}
        ▼
PlannerController::calculate()                     core/Controllers/PlannerController.php:198
  ├─ base (konkretna trasa / GPX) → baseSegments() — jazda po bazie, OSRM na dojazdy
  └─ sourceSegments()                              :384
       ├─ RoutingProxy::route() per para punktów   (rowerowy OSRM FOSSGIS, 1 zapytanie/s, cache 90 dni)
       ├─ brak źródeł           → czysty OSRM
       ├─ autoJoin WYŁ.         → preferSegment(): wklejanie geometrii Ridemore wzdłuż trasy OSRM (RouteSnap)
       └─ autoJoin WŁ.          → ridemoreSegment() :431  (cache wyniku 24 h, budżet 25 s)
             RidemoreRouting::area()          elipsa START–CEL z budżetu objazdu
             RidemoreCorridors::lines()       linie źródeł w kaflach elipsy (+ kto jechał, kiedy, jakim rowerem)
             RidemoreCorridors::localReference()  P90 heksów okolicy = lokalna skala popularności
             RidemoreRouting::route()         :114  wsparcie → korytarze → warianty → RoutingProxy::table()
                                                     → koszt całej trasy → RoutingProxy::routeLegs()
  zwraca segmenty: coords, distanceM, durationS, ridemore[] (kawałki z danych Ridemore), variant (powód)
Zapis: PlannedRoute (migr. 090, IDOR w SQL), przewyższenie z ElevationLookup dopiero przy zapisie.
Mapa: kafle „Ślady” kr / all / me (TileCache), skarby przez /api/planer/warstwy (Treasure::inBounds + reveal()).
```

Rozmiar: `PlannerController` 833 linii, `RidemoreRouting` 1000, `RouteSnap` 552,
`RidemoreCorridors` 287, `RoutingProxy` 397, `planner.js` 926, `route-model.js` 301.
Testy: `tests/planner_test.php`, `planner_routing_test.php` (32 przypadki warstwy
na fałszywym OSRM), `planner_kolejnosc_test.php` (model JS w Node) — `php tests/run.php planner`
(wymaga MySQL; tu nieuruchamialne).

## 2. Co zostaje (bez zmian)

| Element | Plik | Dlaczego zostaje |
|---|---|---|
| OSRM jako jedyny silnik, szew wymiany | `Utils\RoutingProxy` (`route`, `table`, `routeLegs`, dławik 1/s, cache) | Dokładnie „OSRM odpowiada, jak można przejechać”. |
| Rowerowy OSRM FOSSGIS | `core/config.php` `planner.osrm_base_url` | Serwer demo liczył trasy samochodowe — ta decyzja była słuszna. |
| Warstwa decyzyjna | `Utils\RidemoreRouting` (czysta logika, `PARAMS` w jednym miejscu) | To JEST Ridemore Routing Layer z §9 zadania: baseline + ograniczone warianty + ocena całej trasy. |
| Dane warstwy | `Models\RidemoreCorridors` (`lines`, `owners`, `localReference`, `fingerprint`) | Geometria z realnych śladów, nie z heksów — zgodnie z §8. |
| Hierarchia reguł | `score()`, `legalRatio`, `minAsphaltPct`, budżet `maxLengthM()` | Pokrywa hierarchię z §6: dostęp (OSRM) → profil → sensowność → limit objazdu → charakter → popularność. Popularność = bonus, brak danych = neutralnie, 1 osoba ≠ „sprawdzone”. |
| Model trasy JS | `assets/js/planner/route-model.js` | Czysty, testowany; wynik generatora to po prostu lista waypointów + segmenty. |
| Zapis / GPX / IDOR | `PlannedRoute`, `PlannerController::save/load/gpx` | Bez zmian. |
| Kafle referencyjne | `trackTiles` kr/all/me | Mapa jako wizualizacja wyniku. |
| Typy rowerów ze słownika | `Models\BikeType::plannerRules()`, migr. 091 | Odpowiedź na „Czym?” już istnieje. |

## 3. Co usuwamy / upraszczamy

**Nic nie kasujemy w pierwszym kroku** — zgodnie z §14 obecne sterowanie
przechodzi do „Zaawansowane”. Upraszczamy ścieżkę podstawową i zamrażamy
rozbudowę:

| Co | Decyzja | Uzasadnienie |
|---|---|---|
| Prawa kolumna: 3 źródła z pierwszeństwem, status bazy, wyszukiwarka, GPX, przełącznik autoJoin, osobna „Konfiguracja trasy” | **Przenieść pod `<details>` „Zaawansowane”.** Ścieżka podstawowa: wszystkie źródła WŁ., warstwa WŁ., bez pytań. | To panel techniczny; zwykły user nie powinien wiedzieć, co to „źródło”. |
| Dwa tryby liczenia przy źródłach (autoJoin WYŁ. = samo wklejanie `preferSegment`, WŁ. = warstwa) | W podstawowej ścieżce zawsze warstwa. `preferSegment` zostaje tylko jako pomiar udziału „Ridemore X%” i tryb zaawansowany. | Jeden mechanizm decyzji, nie dwa. |
| Etap 3 kontraktu: konfiguracja silnika per typ roweru (`engines.{silnik}.profile|baseUrl` w panelu) | **Zamrozić** (zostaje, nie rozwijać). | FOSSGIS ma jeden profil rowerowy — Szosa/Gravel/MTB wysyłają dziś do OSRM to samo `cycling`. Drugi silnik to V3, poza zakresem. |
| V3 — GraphHopper / własne wagi krawędzi | **Skreślić z horyzontu MVP.** | §15: bez własnego grafu i silnika. |
| Etap 2d — preagregacja wsparcia | **Odblokować dopiero z importem danych BOT** (sekcja 6.3). | Bez dużych danych niepotrzebne; z nimi — konieczne. |
| Etap 2g — histereza korytarza między waypointami | Zostaje, bez rozbudowy. | Generator tworzy mało waypointów; przyda się w trybie ręcznym. |
| Pozostałe pomysły (wielodniowe, noclegi, pogoda, roadbook, Street View/Mapillary, AI itinerary, POI) | **Nie wchodzą** (sekcja 13). | Odtwarzają Komoota, nie wzmacniają przewagi danych. |
| Styl „Widokowo” z §4 zadania | **Nie jako osobny styl** — patrz 7.3. | Ridemore nie ma żadnych danych o widokach; styl obiecywałby coś, czego nie umiemy policzyć. |
| Styl „Terenowo” | **Realizowany przez „Czym?” (Gravel/MTB), nie osobny przycisk.** | Jedyne dane o nawierzchni: `%` asfaltu/szutru/trail na znanych trasach (migr. 086) i typ roweru przejazdu (migr. 091, zwykle NULL). OSRM FOSSGIS nie rozróżnia nawierzchni. |

## 4. Co już jest zaimplementowane

Sprawdzone w kodzie, nie w dokumentacji:

| Obszar z §16A | Stan | Gdzie |
|---|---|---|
| Planer | Jest: waypointy, przeciąganie, segmenty, zapis, GPX, EN | `PlannerController`, `planner.js`, `route-model.js` |
| OSRM proxy | Jest: `route`, `table` (macierz), `routeLegs`, dławik FOSSGIS, cache plikowy | `Utils\RoutingProxy` |
| Routing A→B przez waypointy | Jest | `sourceSegments()` |
| **Pętla „N km z punktu”** | **Brak** | — |
| Start z regionu | Brak w planerze; regiony są (`region_cells`, `/regiony/...`) | `Models\Region`, `RegionOutline` |
| Profile rowerowe | Są: słownik `bike_type` + `meta.planner` (speedKmh, minAsphaltPct, legalRatio, trailBonus, countsRidesOf) | `Models\BikeType`, migr. 091 |
| Popularność | Jest: z geometrii śladów (osoby, przejazdy ≤3/os., świeżość), lokalna skala P90 heksów | `RidemoreRouting::score/support`, `RidemoreCorridors::owners/localReference` |
| Heatmapa | Jest: kafle `slady/all` + `discovery_cell_totals.passes_count` | `TileSource`, `TileRenderer`, `Discovery::communityCells` |
| Znane trasy | Są: geometria z GPX, % nawierzchni, przewyższenie, profil, kolor, skarby wzdłuż | `KnownRoute::activeGeometryInfo`, `Treasure::listOnRoute` |
| Przejazdy usera | Są: solo (upload, Garmin, Polar/Wahoo, archiwum Strava/Garmin), z wyjazdów | `RiderActivity`, `ArchiveImport`, `DeviceImport` |
| Przejazdy społeczności | Są: `edition_tracks` + solo wszystkich w kopii PRZYCIĘTEJ (§27) | `TileSource::tracks('all')`, `gpx_geometry_trimmed` |
| Discovery | Jest: `discovery_cells (user_id, cell_id)`, `discovery_cell_totals`, `DiscoveryGrid::cellsForTrack` | `Models\Discovery`, `Utils\DiscoveryGrid` |
| Skarby | Są: punkty z `reveal()` (ukryte nie wychodzą z modelu), jako waypoint w planerze | `Models\Treasure::inBounds`, `PlannerController::layers` |
| Skarb jako **propozycja zjazdu** („odbij 2,4 km”) | **Brak** | — |
| Bonus za **nowy teren usera** w planerze | **Brak** (istnieje w punktacji, nie w routingu) | — |
| Podsumowanie wyniku: dystans, czas, udział Ridemore | Jest | `planner.js` statystyki + `ridemoreM` |
| Przewyższenie w podsumowaniu | Tylko po zapisie (`ElevationLookup::forRoute`) | `PlannerController::save` |
| Powód wyboru („+0,4 km — sprawdzone odcinki”) | Jest (`variant`, `whyText()`); wygląd niesprawdzony w przeglądarce | `planner.js` |

### Co z wcześniejszej Ridemore Routing Layer już istnieje (§16B)

Z kontraktu `warstwa-routingu-ridemore.md` zrobione: Faza 0 (rowerowy OSRM),
Etap 1 (MVP warstwy, 18 przypadków z planu w testach), 2a (świeżość), 2b
(profile), 2c (powód w UI), 2g (płynny budżet + ciągłość), Etap 3 w kodzie
(migr. 091, typ roweru przejazdu, konfiguracja w panelu). Otwarte: 1b
(weryfikacja UI w przeglądarce), 2d, 2f (kalibracja na produkcji).

Model kosztu, który zadanie opisuje w §10, **już tak działa**:

```
koszt(wariant) = długość_OSRM(wariant)                 ← routing_cost
               + [odrzucenie, gdy profil niezgodny     ← profile_cost (twardo, nie liczbowo)
                  albo wejście→wyjście > legalRatio]
               + [odrzucenie, gdy > maxLengthM()]       ← detour_cost (twardy budżet)
               − Σ 0,2 · score · len · min(1, len/3 km) ← ridemore_bonus
               − continuityBonus (ten sam korytarz)
wybór: min koszt, o ile wygrywa z baseline o ≥ 100 m
```

## 5. Minimalny Ridemore Routing Layer

Nic nowego w architekturze — **jedna nowa funkcja w warstwie (pętla) i dwa
nowe składniki bonusu**. Schemat docelowy:

```
Kreator (4 pytania) ──► POST /api/planer/generuj
                              │
            PlannerController::generate()        (nowe, cienkie: walidacja + mapowanie stylu na opcje)
                              │
         ┌────────────────────┴─────────────────────┐
         │ A→B                                      │ Pętla (brak celu)
         │ istniejące sourceSegments() dla [S, C]   │ RidemoreRouting::loop()  (NOWE)
         │ z opcjami stylu                          │   kotwice = istniejące candidates()
         │                                          │   OSRM routeLegs dla ≤ 4 wariantów
         └────────────────────┬─────────────────────┘
                              ▼
         ocena całej trasy = istniejący koszt + NOWE: bonus nowego terenu (Odkrywczo)
                              ▼
         podsumowanie: dystans, czas, udział Ridemore (jest), przewyższenie (ElevationLookup, 1 zapytanie),
                       skarby po drodze + propozycje zjazdu (NOWE: Treasure::inBounds + reveal + RoutingProxy::table)
                              ▼
         odpowiedź = TEN SAM kształt co /api/planer/oblicz (waypoints + segments) + summary + alternatives[≤2]
                              ▼
         planner.js wczytuje wynik do route-model.js → mapa + karta wyniku; dalej zwykła edycja
```

Kluczowa decyzja: **wynik generatora to zwykłe waypointy i segmenty**, więc
po wygenerowaniu user ląduje w istniejącym planerze (przeciąganie, zapis, GPX)
bez żadnego nowego trybu edycji.

## 6. Dane wykorzystywane przez warstwę (§16F)

### 6.1 Dostępne i używane dziś

| Dane | Tabela / źródło | Użycie w warstwie |
|---|---|---|
| Geometria śladów (pełna / przycięta o dom) | `gpx_geometry`, `gpx_tiles`, `gpx_geometry_trimmed`, `gpx_tiles_trimmed` | Kształt korytarzy (nie heksy). |
| Przejazdy: kto, kiedy, jakim rowerem | `rider_activities` (`user_id`, `gpx_hash`, `ride_date`, `bike_type_item_id` — zwykle NULL) | Osoby / przejazdy / świeżość / zgodność z profilem. |
| Ślady odbytych wyjazdów + uczestnicy z „Byłem” | `edition_tracks`, `rider_activities` (`event_track`/`own_track`) | Wyjazd grupowy = wielu jeźdźców na jednym śladzie. |
| Znane trasy | `known_routes` (`gpx_url`, `is_active`, `surface_*_pct` — NULL = nie wiadomo) | Korytarz o podłodze score 0,8; nawierzchnia vs profil. |
| Skala lokalna | `discovery_cell_totals` (`riders_count`, `passes_count`) | P90 okolicy — wyłącznie ODNIESIENIE, nie geometria. |
| Typy rowerów | `dictionary_items.meta.planner` (słownik `bike_type`) | Reguły profilu. |

### 6.2 Dostępne, jeszcze nieużywane przez planer (nowe użycia, zero nowych tabel)

| Dane | Tabela / metoda | Nowe użycie |
|---|---|---|
| Odkryte pola usera | `discovery_cells (user_id, cell_id)` + `DiscoveryGrid::cellsForTrack()` | „Odkrywczo”: bonus za pola, których user jeszcze nie ma. |
| Skarby z regułą ujawnienia | `treasures` + `Treasure::inBounds()` / `reveal()` | Skarby po drodze i propozycje zjazdu. **Ukryty skarb nie wychodzi ani nazwą, ani współrzędnymi** — tylko liczba. |
| Regiony | `region_cells`, `Region` | „Skąd?” = region (start w środku/w miejscu z największą liczbą znanych tras). |
| Przewyższenie trasy | `ElevationLookup::forRoute()` (opentopodata, publiczne) | Wołane raz przy generowaniu, nie przy każdym przeciągnięciu. |

### 6.3 Czego NIE ma (nie zakładać)

- nawierzchni na przejazdach (tylko na znanych trasach),
- klasy drogi / nawierzchni z OSRM (FOSSGIS nie zwraca, a proxy prosi o `annotations=false`),
- jakichkolwiek danych „widokowych” (punkty widokowe, ocena krajobrazu),
- zdjęć z lokalizacją wzdłuż śladów (poza `treasure_photos` przy skarbach),
- typu roweru dla starych przejazdów (NULL = neutralnie),
- więcej niż jednego profilu rowerowego w OSRM,
- danych produkcyjnych do kalibracji (Etap 2f wciąż czeka na zapytanie usera).

### 6.4 Duże źródło przejazdów (~2 GB) pod ukrytym użytkownikiem BOT (§18 zadania)

Wartościowe — to dokładnie „własne dane”, na których stoi przewaga. Ale
**naiwny import pod jednym userem BOT nie da planerowi nic**, a zepsuje kilka
innych modułów. Znalezione w kodzie:

1. **Jeden BOT = jedna osoba.** `RidemoreRouting::support()` liczy jeźdźców po
   kluczu właściciela `u{user_id}`; `score()` wymaga `minCommunityRiders = 2`,
   a powtórzenia jednej osoby są cięte do `passCapPerRider = 3`. 10 000 przejazdów
   pod BOT-em = „1 osoba, 3 przejazdy” = **zero popularności**. Potrzebny osobny
   klucz właściciela per oryginalny autor (np. zanonimizowany identyfikator
   z paczki) — to jest **zmiana schematu** (`rider_activities` albo osobna
   tabela) i wymaga decyzji.
2. **`RiderActivity::record()` ma skutki uboczne**: punkty (`PointLedger`),
   `discovery_cells`, emblematy, zaliczanie skarbów wzdłuż śladu, postęp znanych
   tras, liczniki „odkryte przez N osób”, listy jeźdźców regionu, Puls. BOT nie
   może przez to przechodzić (ani przez `recordSolo`/`ArchiveImport`, które go
   wołają). Import BOT musi zapisywać wyłącznie geometrię (`gpx_geometry_trimmed`
   + `gpx_tiles_trimmed`) i minimalny wiersz „kto/kiedy/rower”.
3. **Skala.** Dziś każde przeliczenie z włączoną społecznością woła
   `TileSource::tracks('all')`, które dla KAŻDEGO pliku solo w bazie robi
   `is_file` + `filemtime` + odczyt cache hasha + zapytanie `hasTrimmed`
   (`GpxGeometry::ensureTrimmed`), a `RidemoreCorridors::owners()` skanuje całe
   `edition_tracks` z hashowaniem plików. Przy dziesiątkach tysięcy plików to
   sekundy na żądanie. Do tego `LAYER_LINES_PER_SOURCE = 40` — w gęstym obszarze
   popularność liczyłaby się z przypadkowej próbki 40 śladów. **Z danymi BOT
   Etap 2d (preagregacja wsparcia per kafel) przestaje być opcją.**
4. **Filtr aktywności** — paczka zawiera „różnego rodzaju przejazdy”: bieg,
   spacer, samochód (prędkość), GPS-owe śmieci muszą odpaść przed importem.
5. **Prywatność i pochodzenie danych** — końce śladów przycinane o dom
   (`DiscoveryGrid::trimEnds`, jak §27); pełna geometria BOT nigdy nie trafia na
   kafle. Trzeba potwierdzić, że mamy prawo użyć tych danych (skąd pochodzą,
   czy autorzy się zgodzili) — to decyzja usera, nie techniczna.
6. **Czy BOT ma być widoczny na heatmapie `all` i w `discovery_cell_totals`?**
   Rekomendacja: w heatmapie tak (to realny ruch rowerowy), w licznikach
   „odkryło N osób” — nie (to liczniki ludzi z Ridemore).

**Rekomendacja:** import BOT jako osobny, późniejszy etap (E w planie migracji),
po decyzjach z punktów 1, 5, 6. Planer z Etapów A–D działa bez niego.

## 7. Algorytm podejmowania decyzji

### 7.1 A→B (jest cel)

Bez zmian w algorytmie: istniejące `sourceSegments()` dla dwóch punktów
z opcjami stylu (7.3). Warianty: baseline OSRM + do 5 korytarzy + do 3 par
(już tak jest — to odpowiada „1 baseline, A, B, A+B” z §9 zadania).

### 7.2 Pętla (celu nie ma, jest dystans)

OSRM nie generuje pętli (`/trip` to komiwojażer, nie „60 km dookoła”), więc to
jedyny naprawdę nowy kawałek logiki. Minimalna wersja, reużywająca warstwę:

```
wejście: S, L (km), profil, styl
1. r = L / (2π · 1,25)                       promień okręgu, 1,25 = typowa krętość dróg
2. obszar = koło (S, 0,6·L/2); linie = RidemoreCorridors::lines() dla kafli koła
3. kotwice = istniejące candidates() (korytarze ≥ 1 km, score ≥ minScore, zgodne z profilem)
             posortowane wg bonusM(), max 1 na sektor 90°
4. warianty (≤ 4):
     baseline:  S → P(θ) → P(θ+120°) → S        punkty na okręgu, θ = kierunek z najwięcej danych
     A:         S → wejście(A) → wyjście(A) → S
     B:         S → wejście(B) → wyjście(B) → S
     A+B:       S → A → B → S                   (gdy sektory sąsiednie)
5. RoutingProxy::routeLegs() dla każdego wariantu (≤ 4 zapytania ≈ 4 s przy limicie FOSSGIS)
6. koszt = |len − L| · 1,0                      odchylenie od zadanego dystansu
         + max(0, len − 1,15·L) · 5             twarde: ±15% poza tolerancją mocno karane
         + overlap(tam i z powrotem) · len · 0,5  kara za jazdę tą samą drogą w obie strony
         − Σ bonusM(korytarz)                   istniejąca funkcja
         − bonus stylu (7.3)
7. zwycięzca + do 2 alternatyw (inne kotwice) → wynik
```

Brak danych Ridemore w okolicy = sam baseline (pętla z punktów na okręgu) —
użytkownik i tak dostaje sensowną trasę, tylko bez komentarza „dlaczego”.

### 7.3 Styl jazdy → parametry (użytkownik nie widzi żadnej liczby)

| Styl (przycisk) | Co zmienia | Dane |
|---|---|---|
| **Szybko** | Warstwa WYŁ. → czysty OSRM (tylko udział „Ridemore X%” jako informacja). | OSRM |
| **Sprawdzone** *(domyślny; odpowiednik „Widokowo” z §4 w wersji, którą umiemy policzyć)* | Obecna warstwa bez zmian: znane trasy + społeczność + moje. | przejazdy, znane trasy |
| **Odkrywczo** | Warstwa WŁ. + bonus za nowe pola: `0,3 · score_bonus_per_m · długość_przez_pola_nieodkryte_przez_usera`; propozycje skarbów wyżej na liście; „Moje przejazdy” jako źródło WYŁ. (nie prowadzić po znanym). | `discovery_cells` usera, `DiscoveryGrid::cellsForTrack`, skarby |

„Terenowo” = wybór Gravel/MTB w „Czym?” (istniejące `plannerRules`:
`minAsphaltPct`, `trailBonus`, `countsRidesOf`, `legalRatio`). Hierarchia
z §6 zadania zostaje nienaruszona: styl dokłada wyłącznie BONUS, nigdy nie
zdejmuje ograniczeń profilu ani budżetu objazdu.

### 7.4 Skarby — sugestia, nie wymuszenie

Po wyborze trasy (nie w trakcie wyboru):

```
skarby = Treasure::inBounds(bbox trasy + 3 km, viewerId)   → reveal() decyduje, co widać
dla każdego jawnego: najbliższy punkt trasy (RouteSnap) ≤ 3 km
jedna macierz RoutingProxy::table([punkt_trasy_i, skarb_i]) → realny koszt zjazdu i powrotu
wynik: „Skarb X — odbij 2,4 km (tam i z powrotem)” + przycisk „Dodaj po drodze”
        (istniejący insertWaypoint(nearestSegment + 1), jak dziś w dymku)
ukryte: tylko liczba („2 miejsca ukryte w okolicy”)
```

Skarb nie wchodzi do trasy sam — wchodzi po kliknięciu.

## 8. UX użytkownika

```
/planer  →  modal nad mapą (zamyka się, mapa zostaje pod spodem)

  Skąd?            [📍 Moja lokalizacja] [szukaj miejsca / regionu] [klik na mapie]
  Dokąd / ile?     (•) Pętla  [30] [50] [70] [100] km
                   ( ) Do celu [szukaj / klik na mapie]
  Czym?            [Szosa] [Gravel] [MTB]  (+ E-bike, ze słownika bike_type)
  Jak?             [Szybko] [Sprawdzone] [Odkrywczo]

                   [ GENERUJ TRASĘ ]          „Wolę narysować sam” → obecny planer

─────────────────────────────────────────────────────────────────────────
MAPA + TRASA
Karta wyniku:  62,4 km · ↑ 540 m · ~3 h 10 min · Gravel · Sprawdzone
               71% trasy po sprawdzonych odcinkach Ridemore (Wiślana Trasa 18 km, …)
               Skarby: 2 po drodze · „Kapliczka — odbij 2,4 km” [Dodaj]
               [Inny wariant] [Zapisz] [GPX] [Edytuj punkty]
Zaawansowane ▸ (obecna prawa kolumna: źródła, baza/GPX, przełącznik, profil)
```

Ścieżka podstawowa: 4 odpowiedzi → wynik. Obecny planer = „Edytuj punkty”
albo „Wolę narysować sam”.

## 9. API / backend

| Zmiana | Plik | Uwagi |
|---|---|---|
| **Nowe** `POST /api/planer/generuj` | `api/routes.php` | Ta sama obudowa co `/oblicz`: `Auth::user()` (401), `Csrf::check` (403), `Session::release()`, `set_time_limit(120)`. |
| **Nowe** `PlannerController::generate(array $body, int $userId)` | `PlannerController` | Walidacja (`sanitizeCoords`, `bikeFor`, styl z białej listy, `L` 10–200 km), mapowanie stylu na `sources`/`autoJoin`/opcje; A→B → istniejące `sourceSegments()`; pętla → `RidemoreRouting::loop()`. Odpowiedź: `waypoints`, `segments` (kształt jak `/oblicz`), `summary`, `alternatives`. |
| **Nowe** `RidemoreRouting::loop()` | `Utils\RidemoreRouting` | Czysta logika jak `route()`: OSRM wstrzyknięty jako funkcja, parametry w `PARAMS['loop']`. Reużywa `context()`, `candidates()`, `bonusM()`. |
| **Nowe** `RidemoreRouting::discoveryBonusM()` | j.w. | Czysta: dostaje zbiór pól usera i listę pól trasy. |
| **Nowe** `RidemoreCorridors::userCells(int $userId, array $cellIds)` | `Models\RidemoreCorridors` | Jedno zapytanie `discovery_cells WHERE user_id = :u AND cell_id IN (...)` — pola usera z sesji, nigdy z żądania. |
| **Nowe** `PlannerController::treasureSuggestions()` | `PlannerController` | `Treasure::inBounds` (+ `reveal()`), `RouteSnap` na najbliższy punkt, jedno `RoutingProxy::table`. |
| Przewyższenie w podsumowaniu | `ElevationLookup::forRoute` | Raz na wygenerowaną trasę; `null` nie blokuje wyniku. |
| Bez zmian | `/oblicz`, `/zapisz`, `/zrodla`, `/zrodlo`, `/wgraj-gpx`, `/{id}` | — |
| Schemat bazy | **bez zmian** w Etapach A–D | Zmiana tylko w Etapie E (BOT), po decyzji. |

Budżet zapytań FOSSGIS (1/s): A→B jak dziś (2–4 zapytania, potem cache);
pętla ≤ 4 `routeLegs` + 1 `table` na skarby ≈ 5–6 s przy zimnym cache.
Przy wielu użytkownikach naraz publiczny FOSSGIS się nie skaluje — znane
ograniczenie z poprzedniego kontraktu, poza zakresem.

## 10. Frontend

| Zmiana | Plik |
|---|---|
| Modal kreatora (4 pytania) nad mapą, otwierany domyślnie przy pustym planerze i przyciskiem „Zaplanuj trasę”; zastępuje `#plannerOnboarding` („Kliknij na mapie…”) | `views/web/pages/planner.php`, `assets/js/planner.js` (nowy mały moduł `assets/js/planner/wizard.js` — stan formularza → payload, bez DOM-u w logice, testowalny w Node jak `route-model.js`) |
| Wczytanie wyniku: `route.replaceWaypoints()` + `applyRouting()` z segmentami z `/generuj` | `planner.js` (istniejące metody modelu) |
| Karta wyniku (dystans, ↑, czas, charakter, % sprawdzonych, skarby, „Inny wariant”) — rozbudowa obecnego `#plannerStatsCard`, nie nowy komponent | `planner.php`, `planner.js`, `style.css` (sekcja planera) |
| Obecna prawa kolumna (`#plannerSources`, `#plannerCfg`) → jeden `<details>` „Zaawansowane” | `planner.php` |
| Teksty EN | `core/lang/en.php` |
| „Skąd?” — geolokalizacja przeglądarki + wyszukiwarka regionów (istniejące dane regionów); wyszukiwanie adresów (geokoder) **świadomie odłożone** — to nowa zależność zewnętrzna | `planner.js` |

## 11. Testy

Wszystkie w istniejącej grupie `php tests/run.php planner`, logika na
fałszywym OSRM (wzorzec `planner_routing_test.php`):

| # | Przypadek | Oczekiwanie |
|---|---|---|
| G1 | Pętla bez danych Ridemore | Zwraca baseline, długość w ±15% L, 0 dodatkowych zapytań poza baseline. |
| G2 | Pętla z jednym popularnym korytarzem w zasięgu | Wybrany wariant przez korytarz, długość w ±15% L. |
| G3 | Korytarz, który wydłużyłby pętlę > 1,15·L | Odrzucony, zostaje baseline/inny wariant. |
| G4 | Pętla „tam i z powrotem” tą samą drogą vs prawdziwa pętla | Wygrywa prawdziwa pętla (kara overlap). |
| G5 | Znana trasa z 40% asfaltu, profil Szosa | Nie jest kotwicą (istniejąca reguła `minAsphaltPct`). |
| G6 | Styl Szybko | Wynik = czysty OSRM, `variant.chosen = 'osrm'`. |
| G7 | Odkrywczo: dwa warianty tej samej długości, jeden przez pola nieodkryte | Wygrywa nieodkryty; różnica długości > budżetu → nie wygrywa. |
| G8 | Odkrywczo nie łamie profilu | Nieodkryty teren niezgodny z profilem nie wygrywa. |
| G9 | Skarb ukryty (`reveal()` = null) w zasięgu | W JSON-ie odpowiedzi brak nazwy i współrzędnych; tylko liczba (sprawdzane po całym JSON-ie, jak w `skarby_test`). |
| G10 | Skarb jawny 2 km od trasy | Propozycja z realnym kosztem z macierzy; trasa NIE zmieniona. |
| G11 | `/generuj` bez CSRF / bez logowania | 403 / 401. |
| G12 | Pola usera brane z sesji | Parametr `userId` w żądaniu ignorowany (IDOR). |
| G13 | Kreator (Node): stan → payload | Pętla bez celu, A→B z celem, walidacja dystansu, domyślny styl. |
| G14 | Ostrzeżenie o nawierzchni | Tylko dla odcinków po znanych trasach ze znanym %; poza nimi brak zdania. |
| G15 | Skarb: czas zjazdu i zdjęcie | Czas z macierzy OSRM; ukryty Skarb nie wychodzi ze zdjęciem (rozszerzenie G9). |
| — | Istniejące 32 + 18 + 45 | Bez regresji. |
| E* | (Etap E) BOT: 50 przejazdów różnych autorów tym samym śladem | Korytarz popularny; ten sam test pod jednym kluczem → niepopularny. Import BOT nie tworzy punktów, odkryć ani wpisów w Pulsie. |

Weryfikacja na żywo (jak dotąd): prawdziwy rowerowy OSRM, dane dev, UI
w przeglądarce — wymaga zalogowania (blokada z Etapu 1b nadal obowiązuje).

## 12. Plan migracji obecnego rozwiązania

Każdy etap działa samodzielnie i niczego nie psuje; kolejność = najpierw
wartość dla usera przy najmniejszej zmianie.

| Etap | Zakres | Backend | Schemat |
|---|---|---|---|
| **A — Kreator A→B + karta wyniku** | Modal 4 pytań; tylko „Do celu”; styl → `sources`/`autoJoin`; karta wyniku z przewyższeniem, 1–2 ostrzeżeniami z danych, które mamy (14.2), i „tędy jeździło N osób”; drag & drop GPX. Prawa kolumna → „Zaawansowane”. | Tylko `/generuj` jako cienka nakładka na `sourceSegments()` + `ElevationLookup`. | — |
| **B — Pętla** | „Pętla N km”; `RidemoreRouting::loop()`; „Inny wariant”. | `loop()`, `PARAMS['loop']`. | — |
| **C — Skarby po drodze** | Propozycje zjazdu z realnym kosztem (km + czas z macierzy), zdjęcie Skarbu przez `reveal()`; „Dodaj po drodze”. | `treasureSuggestions()`. | — |
| **D — Odkrywczo** | Bonus nowych pól usera. | `discoveryBonusM()`, `userCells()`. | — |
| **E — Dane BOT** *(po decyzjach z 6.4)* | Import bez skutków ubocznych, klucz autora, filtr aktywności, preagregacja wsparcia (dawne 2d), odwrócenie `tracks('all')` na zapytanie po kaflach. | Import CLI, `RidemoreCorridors`. | **Tak** — do decyzji. |
| **F — Kalibracja** (dawne 2f) | Strojenie `PARAMS` na produkcji / danych BOT. | Tylko stałe. | — |

Kontrakt `warstwa-routingu-ridemore.md`: 1b przechodzi do weryfikacji
Etapu A; 2d → Etap E; 2f → Etap F; Etap 3 (silniki per typ) i V3 — zamrożone.

## 13. Świadomie odłożone

- wielodniowe wyprawy, podział na dni, noclegi, Warmshowers, iOverlander,
- pogoda, zachód słońca, roadbook,
- Street View / Mapillary, AI itinerary, AI asystent, automatyczny plan podróży,
- rozbudowany system POI (gastronomia, serwisy) — jedynym „POI” są Skarby,
- styl „Widokowo” jako osobny algorytm — do czasu, gdy Ridemore będzie miał
  własne dane o widokach (np. zdjęcia z lokalizacją),
- geokoder adresów w „Skąd?” (nowa zależność zewnętrzna),
- drugi silnik / własny OSRM / GraphHopper / wagi krawędzi (dawne V3),
- konfiguracja silnika per typ roweru w panelu — zostaje, nie rozwijamy,
- publiczne udostępnianie zaplanowanej trasy, warianty zapisane w bazie,
- suwaki (siła Ridemore, maks. objazd) — jeśli kiedyś, to tylko w „Zaawansowane”.

## 14. Uwagi drugiego zespołu (przegląd ekspercki, 2026-09-23)

Drugi zespół (planowanie tras/bikepacking, UX/psychologia, kontrpanel)
przygotował pełny katalog funkcji zainspirowany projektami Bike Trip
Planner, Cycling Companion, Pathfinders, TrailTuner, rando-planner
i MapillaryJS. Jego wniosek końcowy: **planer powinien ewoluować w „Trip
Planner + Route Intelligence”**, z modułem wypraw wielodniowych jako Etap 1
(P0), a własną przewagą Ridemore mają być popularność, Skarby, odkrywanie
i społeczność.

Poniżej każdą grupę uwag zestawiam z kierunkiem z zadania (§11–12: „nie
budujemy teraz trip plannera”, „nie budujemy drugiego Komoota”) i ze stanem
kodu. Zewnętrznych repozytoriów nie sprawdzałem — oceniam wyłącznie, co
z tego Ridemore ma albo może policzyć na własnych danych.

### 14.1 Gdzie oba zespoły się zgadzają — wchodzi do tego planu

| Uwaga drugiego zespołu | Stan w Ridemore | Gdzie w planie |
|---|---|---|
| „Proponować, a nie zmuszać do konfiguracji”; preset + Advanced; choice overload | Zgodne z §3, §13–14 zadania | Kreator 4 pytań + „Zaawansowane” (sekcje 8, 10) |
| 2–3 warianty trasy, nie więcej | Warstwa już ogranicza warianty | Wynik + ≤ 2 alternatywy („Inny wariant”) |
| Maksymalny objazd jako mechanizm wewnętrzny | Jest: `RidemoreRouting::maxLengthM()` | Bez zmian, bez suwaka |
| RidemoreScore / popularność jako rdzeń | Jest | Bez zmian; dane BOT — Etap E |
| Profil roweru | Jest (`bike_type`, migr. 091) | „Czym?” w kreatorze |
| Import GPX, eksport GPX, punkty pośrednie, multi-stop (do 25), łączenie istniejących tras, „dołącz do znanej trasy” | **Wszystko już jest** (`/api/planer/wgraj-gpx`, `/planer/{id}/gpx`, `route-model.js`, tryb bazy, warstwa) | Zostaje, przechodzi do „Zaawansowane” / „Edytuj punkty” |
| Drag & drop GPX | Brak (jest tylko przycisk „Wgraj GPX”) | **Dopisane do Etapu A** — mała zmiana w samym frontendzie, ten sam endpoint |
| Skarby blisko trasy, odległość, „warto zjechać 1,2 km”, automatyczne wyliczenie objazdu | Brak w planerze; dane i `reveal()` są | Etap C (sekcja 7.4) |
| **Dodatkowy czas zjazdu do Skarbu** | `RoutingProxy::table()` już prosi o `annotations=distance,duration` | **Dopisane do Etapu C**: „odbij 2,4 km, ~9 min” |
| **Zdjęcie Skarbu** w propozycji | Są `treasures.photo_url` i `treasure_photos`; zdjęcie zdejmuje `reveal()` | **Dopisane do Etapu C**, zawsze przez `reveal()` (spoiler = najsilniejszy wyciek, patrz `features.md`) |
| „Chcę wiedzieć, czy dam radę” (kompetencja) | Dystans i czas są; przewyższenie tylko po zapisie | Karta wyniku z przewyższeniem (Etap A) |
| „Chcę sam zdecydować” (autonomia) | Edycja punktów istnieje | „Edytuj punkty” po wygenerowaniu |
| „Chcę jechać z innymi / czuć społeczność” (relacyjność) | `whyText()`: „liczba osób: N” | Karta wyniku: „tędy jeździło N osób” (tylko liczby zbiorcze, nigdy kto) |

### 14.2 Route Intelligence — przyjęte częściowo, tylko na danych, które mamy

Drugi zespół słusznie wskazuje, że wartość pojawia się, gdy planer
**analizuje** gotową trasę. W MVP wchodzi tylko to, co da się policzyć
uczciwie z obecnych danych, jako 1–3 zdania w karcie wyniku (nie osobny
panel):

| Ostrzeżenie | Z czego | Decyzja |
|---|---|---|
| Duże przewyższenie względem dystansu | `ElevationLookup::forRoute()` (suma ↑/↓) | **Etap A** — jedno zdanie, próg w `PARAMS` |
| Dużo asfaltu dla MTB / terenu dla szosy | % nawierzchni **tylko na znanych trasach** (migr. 086) | **Etap A** — tylko dla odcinków po znanych trasach; poza nimi milczymy (nie wiemy) |
| Stromy podjazd (maks. nachylenie) | `ElevationLookup` próbkuje ≤ 100 punktów — przy 60 km co ~600 m, za rzadko na nachylenie | **Odłożone** — wymaga gęstszego profilu wysokości (więcej zapytań do publicznego API) |
| Długi odcinek po głównej drodze | Proxy prosi OSRM o `annotations=false`; FOSSGIS nie zwraca klasy drogi | **Odłożone** — brak danych |
| Brak wody / sklepu / noclegu | Ridemore nie ma takich danych | **Odłożone** (14.3) |
| Pogoda, wiatr, zachód słońca, zasięg e-bike | Brak danych | **Odłożone** |

### 14.3 Gdzie zespoły się różnią — odłożone zgodnie z kierunkiem z zadania

**Główny konflikt:** drugi zespół daje moduł **Trip** (wyprawa, podział na
dni, automatyczny podział wg km + D+, ręczne granice dni, statystyki i GPX
per dzień) jako **Etap 1, P0**. Zadanie w §11 wprost go wyklucza. Ten plan
trzyma się zadania — decyzja należy do usera (punkt 4 niżej).

Architektura tego planu **nie blokuje** modułu Trip w przyszłości. Wynik
generatora to zwykła trasa (`waypoints` + `segments`, zapis w
`planned_routes`), więc podział na dni będzie dodatkową warstwą nad gotowym
przebiegiem i nie zmieni Ridemore Routing Layer. Wtedy trzeba będzie
dołożyć gęstszy profil wysokości (to samo co przy stromych podjazdach, 14.2).

| Grupa z katalogu drugiego zespołu | Dlaczego nie teraz | Warunek powrotu |
|---|---|---|
| Wyprawy wielodniowe, pacing, zmęczenie, GPX/FIT per dzień, data startu, zapis etapów | §11 zadania; odtwarza Bike Trip Planner / TrailTuner | Decyzja usera; po Etapach A–D |
| Postoje i POI: woda, sklep, jedzenie, WC, serwis, camping, schronisko, hotel, punkt widokowy, zabytek, zamek | Ridemore nie ma tych danych. Jedyna droga to Overpass (serwis już używany do nawierzchni, ale z limitami i budżetem ~70 s na trasę) — **nowe źródło danych**, czego zadanie zabrania w §10; odtwarza Cycling Companion | Osobny moduł „Postoje” jako sugestie, po decyzji o Overpass |
| Noclegi: automatyczny nocleg na końcu dnia, bivouac, Warmshowers, iOverlander | §11; zależy od modułu Trip i POI. Warmshowers — ograniczenia praw do danych (drugi zespół zgłasza to samo) | Po module Trip; Warmshowers/iOverlander tylko przez legalne mechanizmy |
| Street View, Mapillary, zdjęcia 360°, wirtualny przejazd | §11 (wprost); Street View to koszt i warunki Google, Mapillary ma luki w pokryciu | Przyszły moduł „Podgląd” |
| „Zdjęcia Ridemore” wzdłuż trasy | `event_photos` **nie mają współrzędnych**; lokalizację mają tylko zdjęcia Skarbów | Dziś tylko przez Skarby (14.1); reszta wymaga lokalizacji zdjęć |
| Roadbook, eksport PDF/FIT, automatyczny opis dnia | §11 | Po module Trip |
| Preferencje nawierzchni i dróg lokalnych/głównych jako **ustawienia** („ROZWIJAĆ”) | Publiczny OSRM FOSSGIS nie przyjmuje wag i ma jeden profil rowerowy — ustawienie niczego by nie zmieniło, czyli interfejs by kłamał. Dziś działa tylko przez korytarze Ridemore i typ roweru | Decyzja o własnym OSRM z profilami (dawne V3) |
| Udostępnianie linkiem, publiczna wyprawa, wspólne planowanie, zaproszenia | `planned_routes` celowo prywatne (decyzja paneli, migr. 090) | Osobne zadanie z oceną prywatności (§27) |
| AI itinerary, AI asystent, AI opis wyprawy | §11 (wprost) | Nie w horyzoncie |
| Ocena atrakcyjności Skarbów, Skarby dopasowane do profilu | Brak danych o atrakcyjności (są potwierdzenia i liczba znalazców) | Po Etapie C, jeśli propozycje Skarbów się przyjmą |

### 14.4 Wpływ na plan migracji

- **Etap A**: + drag & drop GPX, + 1–2 ostrzeżenia z 14.2 w karcie wyniku,
  + „tędy jeździło N osób”.
- **Etap C**: + dodatkowy czas zjazdu, + zdjęcie Skarbu przez `reveal()`.
- **Testy**: G14 — ostrzeżenie o nawierzchni tylko dla odcinków ze znanym
  % nawierzchni (poza nimi brak zdania); G15 — propozycja Skarbu z czasem
  z macierzy; ukryty Skarb nie wychodzi ze zdjęciem (rozszerzenie G9).
- Poza tym bez zmian: Etapy B, D, E, F i lista odłożonych (sekcja 13)
  zostają.

## Decyzje potrzebne od usera przed implementacją

1. Akceptacja stylów **Szybko / Sprawdzone / Odkrywczo** (zamiast Szybko /
   Terenowo / Widokowo / Odkrywczo) — „terenowo” przez typ roweru,
   „widokowo” odłożone z braku danych.
2. Kolejność etapów A → D (czy pętla B ma iść przed kreatorem A).
3. Dane BOT (6.4): pochodzenie i prawo do użycia; czy paczka ma identyfikator
   autora; czy BOT ma być na heatmapie; zgoda na zmianę schematu w Etapie E.
4. **Konflikt z drugim zespołem (14.3):** czy zostajemy przy „nie budujemy
   teraz trip plannera” (ten plan), czy moduł Trip ma wejść jako Etap 1 —
   wtedy ten plan trzeba przebudować przed implementacją.
5. Czy Overpass może stać się źródłem POI (woda/sklep/camping) w przyszłym
   module „Postoje” — to nowe źródło danych, którego zadanie w §10 zabrania.
