# Warstwa routingu Ridemore — preferowanie sprawdzonych odcinków

> Kontrakt implementacyjny. Powstał z analizy i planu przedstawionego userowi
> 2026-09-18 („Ridemore Routing Layer"); user zaakceptował go poleceniem
> „realizuj po kolei fazy, zaimplementuj rozwiązania". Status każdego etapu
> stoi w jego nagłówku (`tasks/README.md`).

## Cel

OSRM wyznacza poprawną trasę, ale bywa, że wybiera boczne lub przypadkowe
drogi, choć obok jest odcinek, którym użytkownicy Ridemore naprawdę jeżdżą.
Planer ma wykorzystywać wiedzę Ridemore o przejazdach, żeby **preferować
sprawdzone odcinki, gdy są tylko nieznacznie dłuższe** — i wracać do OSRM, gdy
wymagałyby dużego objazdu.

**OSRM daje możliwość. Ridemore daje preferencję. Scoring wybiera kompromis.**

## Decyzje zamknięte (user, 2026-09-18)

1. **OSRM zostaje silnikiem** — bez własnego grafu OSM, bez własnego silnika,
   bez GraphHoppera. Nad nim lekka warstwa, która z OSRM korzysta; OSRM nie wie
   nic o RidemoreScore.
2. **Popularność to BONUS, nigdy kara.** Brak danych Ridemore = sygnał
   neutralny. OSM zawsze jest wariantem zapasowym.
3. **Twardy budżet wydłużenia** względem trasy bazowej OSRM: płynna
   funkcja bez progów, z miejscem na lokalny zjazd i powrót. Zmienione
   decyzją usera 2026-09-21; dawne progi 12–25% zostały odrzucone.
4. **OSM rozstrzyga, czy tędy da się jechać** — popularność nie może wybrać
   drogi niedostępnej, nieprzejezdnej ani absurdalnej.
5. **Długi, spójny korytarz > krótkie kawałki** (100 m popularności nie
   uzasadnia 5 km objazdu).
6. **Skarby to punkty (POI), nie korytarze** — bez osobnego systemu w MVP.
7. **Bez suwaka „siła popularności"** — algorytm działa sam; architektura ma
   pozwolić później pokazać powód wyboru wariantu.
8. **Faza 0 — rowerowy OSRM** (rekomendacja z planu, przyjęta razem z nim):
   serwer demo `router.project-osrm.org` liczył trasy SAMOCHODOWE (ignoruje
   profil w adresie); zastępuje go rowerowy OSRM FOSSGIS — nadal OSRM, zmiana
   adresu w configu.
9. **„Przejazdy społeczności" domyślnie zaznaczone** (rekomendacja z planu,
   wdrożona) — bez tego popularność działałaby dopiero po ręcznym zaznaczeniu.
   *Do potwierdzenia przez usera przy przeglądzie UI.*

## Stan zastany (zmierzony 2026-09-18, nie z pamięci)

- **Planer liczył trasy dla aut.** `/cycling/`, `/driving/`, `/foot/` na serwerze
  demo dawały identyczny wynik (Wawel → Tyniec 17,9 km przy 65 km/h; mediana
  prędkości 134 tras z cache planera: 45,6 km/h). Rowerowy OSRM: 10,2 km wzdłuż
  Wisły. Regulamin FOSSGIS: najwyżej 1 zapytanie/s, bez intensywnego użycia.
- **Dane:** `rider_activities` (kto, kiedy — `ride_date`, który plik),
  `gpx_geometry`/`gpx_tiles` (+ przycięte kopie solo, §27), `edition_tracks`
  z uczestnikami, `discovery_cell_totals` (heksy ~500 m: `riders_count`,
  `passes_count`). `last_seen_at` w heksach to moment ostatniego *pierwszego
  odkrycia* zapisany przy imporcie — NIE data ostatniego przejazdu. Brak typu
  roweru przy przejeździe i nawierzchni na śladach.
- **Dane dev:** 102 przejazdy solo, 100 jednego użytkownika — popularności
  „wśród wielu osób" nie da się tu zweryfikować; logika testowana na danych
  syntetycznych.
- **Koszt:** liczenie wsparcia dla WSZYSTKICH śladów najgęstszego obszaru dev
  (9 kafli z14, 55 plików, 16,5 tys. sond) = 10,4 s. Tylko dla kandydatów:
  ułamek sekundy.

## Architektura

```
planner.js → /api/planer/oblicz → PlannerController::sourceSegments()
   przełącznik „Dołączaj dłuższe odcinki Ridemore" WYŁ. → samo przyciąganie (jak dotąd)
   WŁ. → kolejne odcinki (pary punktów) ze stanem wybranego korytarza
      przekazywanym między nimi i z pamięcią wyniku na dobę:
      RoutingProxy::route          trasa bazowa (rowerowy OSRM)
      RidemoreRouting::area        elipsa START–CEL z limitu wydłużenia + kafle z14
      RidemoreCorridors::lines     linie zaznaczonych źródeł w elipsie + kto nimi jechał
      RidemoreCorridors::localReference   lokalna skala (P90 heksów okolicy)
      RidemoreRouting::route       wsparcie → korytarze → wejście/wyjście → warianty
         RoutingProxy::table       JEDNA macierz odległości dla wszystkich wariantów
         RoutingProxy::routeLegs   geometria zwycięzcy (dojazdy OSRM + korytarz)
```

Wszystkie parametry: `Utils\RidemoreRouting::PARAMS` (jedno miejsce).

## Etapy

### Faza 0 — rowerowy OSRM, dławik, zwolnienie sesji — ZROBIONY 2026-09-18

- `core/config.php`: `planner.osrm_base_url` = `https://routing.openstreetmap.de/routed-bike`,
  `planner.osrm_min_interval_ms` = 1000.
- `Utils\RoutingProxy`: rezerwacja terminu wyjścia do sieci (plik `.throttle`,
  wspólny dla procesów PHP, kolejka najwyżej 8 s → `null`); adres serwera
  w kluczu cache (stare trasy samochodowe przestały pasować same).
- `/api/planer/oblicz`: `Core\Session::release()` + `set_time_limit(120)`.
- Zweryfikowane: Wawel → Tyniec 10,2 km (było 17,9); 3 nowe zapytania = 3,1 s.

### Etap 1 — MVP warstwy Ridemore — ZROBIONY 2026-09-18

- `Models\RidemoreCorridors` (dane): `lines()` (przeniesione `sourceLines()`
  z kontrolera + `owners`), `owners()` (solo po hashu; ślad organizatora =
  wszyscy uczestnicy z „Byłem"), `localReference()`, `fingerprint()`.
- `Utils\RidemoreRouting` (czysta logika): RidemoreScore, elipsa, wsparcie
  wzdłuż linii (30 m, limit 3 przejazdów na osobę), korytarze (≥ 1 km,
  score ≥ 0,3), wejście/wyjście (najtańsze wg szacunku + „najbliżej START –
  najbliżej CELU"), warianty (≤ 5 korytarzy + ≤ 3 pary), koszt całej trasy,
  limity, kontrola przejezdności, geometria zwycięzcy.
- `Utils\RoutingProxy::table()` i `routeLegs()`.
- `PlannerController`: warstwa przy włączonym przełączniku (budżet 25 s na
  przeliczenie, pamięć wyniku odcinka `storage/planner-cache`, 24 h, klucz
  z odciskiem danych); odpowiedź niesie `variant` (pod przyszły komunikat).
  Dawne lokalne dołączanie ≤ 1,8× usunięte z użycia.
- UI: nowy tekst podpowiedzi przełącznika (stary obiecywał „do +80%"),
  „Przejazdy społeczności" domyślnie zaznaczone, tłumaczenie EN.
- Testy: `tests/planner_routing_test.php` (24), cały planer 71/71.
- Na żywo (kontroler + prawdziwy OSRM): Wiślana trasa — OSRM 9,6 km z 4% po
  znanej trasie → warstwa 10,1 km (+0,4 km) z 92%; tam, gdzie OSRM już jedzie
  trasą albo rzeka bez mostu robi objazd, zostaje OSRM.

### Etap 1b — weryfikacja UI w przeglądarce — OTWARTY, ZABLOKOWANY za logowaniem

Klasyfikator trybu automatycznego odmówił zalogowania przeglądarki przez
dopisanie sesji (2026-09-18), a hasła w formularz agent nie wpisuje. Potrzebne:
user loguje się w panelu przeglądarki (albo dodaje regułę uprawnień) —
wtedy: stany A–F z domyślnie zaznaczoną społecznością, przypadek Wiślanej
trasy z przełącznikiem WŁ./WYŁ., nowa podpowiedź, strona EN, konsola czysta,
a po Etapach 2b–2c także: przełączanie profilu przelicza trasę (tylko przy
WŁ.), linia „+0,4 km — prowadzi sprawdzonymi odcinkami Ridemore” pod
odcinkiem i jej dymek.

### Etap 2a — świeżość (mnożnik z `ride_date`) — ZROBIONY 2026-09-19

Mnożnik 0,5–1,0 (ostatni przejazd ≤ 2 lata → 1,0; liniowo do 0,5 przy 6+
latach). Dane już są: `owners[...]['last']`. Mnożnik, nie składnik — stara
popularna droga nie może przegrać z jednym świeżym przejazdem.

*Wykonanie:* `RidemoreRouting::recencyFactor()` + `PARAMS` `recentDays`/
`staleDays`/`staleFactor`; wiek liczony raz na linię (najświeższy `last`
właścicieli), w sondzie najświeższy ze śladów obok. Znane trasy bez mnożnika.
Testy: czynnik, ten sam korytarz świeży vs sprzed 8 lat, znana trasa.

### Etap 2b — profile Szosa / Gravel / MTB na istniejących danych — ZROBIONY 2026-09-19

- `planner.js` wysyła profil w kontekście (dziś zmienia tylko prędkość do
  wyświetlenia); profil w sygnaturze odcinka.
- Znane trasy z % nawierzchni: szosa odrzuca korytarz z asfaltem < 70%;
  MTB dostaje premię za `trail`.
- Próg przejezdności (`legalRatio`) per profil: szosa 1,3; gravel 1,5; MTB 1,8.
- Przejazdy bez nawierzchni: neutralne (świadomie — patrz Etap 2e).

*Wykonanie:* `KnownRoute::activeGeometryInfo()` (% nawierzchni obok hasha),
`RidemoreCorridors::lines()` niesie `surface`, `PARAMS['profiles']`,
`PlannerController::sanitizeProfile()`, profil w kluczu pamięci odcinka;
`planner.js` — `data-profile` na przyciskach, `profile` w kontekście
(w sygnaturze odcinka tylko przy włączonym przełączniku). Sonda przy znanej
trasie niezgodnej z profilem ma score 0 („popularność nie nadpisuje
profilu”). Testy 11, 11b, 12, 13 + przejezdność 1,6× (szosa nie, MTB tak).

### Etap 2c — powód wyboru w UI — ZROBIONY 2026-09-19 (wygląd niesprawdzony, patrz 1b)

Pod odcinkiem, gdy wygrał korytarz: „+1,2 km — prowadzi sprawdzonymi
odcinkami Ridemore" (dane już są w `variant`). Bez nowych przycisków.
Tylko liczby zbiorcze (osoby/przejazdy), nigdy kto.

*Wykonanie:* `applyRouting` zachowuje `variant` przy odcinku; `whyText()`
w `planner.js` — linia pod liczbami odcinka (`.planner-seg__why`), dymek
z korytarzami („Szlak · 9.4 km”, dla społeczności „liczba osób: N” od 2 osób
— bez form mnogich, bo JS nie ma `__n`). Teksty w słowniku EN.

### Etap 2d — preagregacja wsparcia — OTWARTY, ZABLOKOWANY za pomiarem na danych produkcyjnych

Siatka ~30 m per kafel z14 w cache plikowym, unieważniana epoką kafli
`slady` — dopiero, gdy czasy na produkcji to uzasadnią (dziś: pamięć wyniku
odcinka + limity kandydatów wystarczają).

### Etap 2e — typ roweru przy przejeździe — ZASTĄPIONY Etapem 3 (decyzja usera 2026-09-19)

### Etap 3 — typy rowerów: przejazdy + konfiguracja planera per typ — OTWARTY

Decyzje usera (2026-09-19): „dodaj typ roweru do jeżdżenia z możliwością
konfigurowania pod planer, jeśli zmienię silnik"; konfiguracja **w panelu
admina**; typ przejazdu ze **wszystkich czterech źródeł** (Garmin, Polar/Wahoo,
`<type>` w GPX, ręczny wybór).

- **Typy rowerów = istniejący słownik `bike_type`** (Szosowy, Gravel, MTB,
  E-bike — ten sam co w wydarzeniach i preferencjach). Bez nowej tabeli.
- **Konfiguracja w `dictionary_items.meta`** (wzorzec `map_layer`):
  `planner` — `speedKmh`, `minAsphaltPct`, `legalRatio`, `trailBonus`,
  `countsRidesOf` (przejazdy jakich typów są „dowodem" dla tego profilu;
  nieznany typ zawsze neutralny), `engines.{silnik}.profile|baseUrl`;
  `aliases` — nazwy z importów (Garmin `road_biking`, Polar `ROAD_BIKING`,
  GPX `<type>`…). Edycja: `/admin/taksonomia?dict=bike_type`, sekcja „Planer".
- **Silnik:** `config.php` `planner.engine` (dziś tylko `osrm`); profil
  i ewentualny adres serwera per typ roweru z panelu. Nowy silnik = gałąź
  w `Utils\RoutingProxy` + wypełnienie profili w panelu.
- **Migracja 091:** `rider_activities.bike_type_item_id` (NULL = nieznany)
  + domyślna konfiguracja czterech typów (tylko tam, gdzie `meta` puste).
- **Źródła typu:** `RiderActivity::recordSolo()` przyjmuje typ; Garmin
  (`typeKey`), Polar/Wahoo (`type`), archiwum i ręczne wgranie → `<type>`
  z GPX przez aliasy; ręczny wybór przy wgrywaniu i w edycji przejazdu.
- **Planer:** przyciski profilu z aktywnych typów słownika; profil zawsze
  w sygnaturze odcinka (typ może mieć inny profil silnika); przejazdy
  niezgodnego typu nie liczą się jako wsparcie korytarza; zapis trasy niesie
  typ roweru i silnik.
- **Poza zakresem:** uzupełnianie typu starych przejazdów z zewnętrznych API
  (tylko ręcznie); implementacja drugiego silnika.

### Etap 2f — kalibracja stałych na produkcji — OTWARTY, ZABLOKOWANY za danymi z produkcji

Zapytanie SQL tylko do odczytu (wolumeny, rozkład osób na pole, udział
jednego jeźdźca) do uruchomienia przez usera; potem strojenie `bonusPerM`,
limitów i progów w `RidemoreRouting::PARAMS`.

### Etap 2g — ciągłość korytarza i płynny budżet objazdu — ZROBIONY 2026-09-21

- Usunięte skokowe progi 12–25%. Limit jest ograniczeniem zasobu
  (epsilon-constraint): `min(8 km, max(4 km, 12% trasy bazowej))`. Daje to
  kilka kilometrów na lokalny zjazd i powrót także na krótkiej trasie,
  ale nie pozwala popularności kupić dowolnie długiego objazdu.
- `sourceSegments()` przekazuje hash wybranego korytarza do następnej pary
  waypointów. Ten sam korytarz dostaje premię przejścia (histerezę), więc
  lokalny szum kosztu ani sztuczna granica waypointu nie zrywają trasy.
  OSRM nadal musi potwierdzić przejezdność i twardy budżet.
- To lekki odpowiednik modelu stanów z map matchingu HMM: obserwacją jest
  koszt aktualnego wariantu, a przejściem — pozostanie na tym samym korytarzu.
  Nie budujemy pełnego grafu ani Viterbiego, bo kandydaci i warianty już są
  jawne, a planner potrzebuje deterministycznej decyzji online.
- Podstawa naukowa: Newson i Krumm, *Hidden Markov Map Matching Through Noise
  and Sparseness* (2009); Brakatsoulas i in., *On Map-Matching Vehicle
  Tracking Data* (2005); Beasley i Christofides, resource-constrained
  shortest path (1989); Broach i in., cyclist route-choice utility (2012).
- Test regresyjny rozróżnia ten sam boczny korytarz bez stanu (przegrywa)
  i po wcześniejszym wyborze (zostaje wybrany i przekazuje stan dalej).

### V3 — poza zakresem tego kontraktu

GraphHopper / własny routing / wagi krawędzi — decyzja dopiero po zebraniu
danych z Etapów 2d i 2f.

## Kryteria akceptacji

1. Popularny korytarz tylko nieznacznie dłuższy od trasy OSRM jest wybierany;
   duży objazd wraca do OSRM. — *Etap 1: testy 2, 3, 4; na żywo Wiślana trasa.*
2. Wydłużenie nigdy nie przekracza płynnego budżetu: co najmniej 4 km
   miejsca na zjazd/powrót, dalej 12% trasy, najwyżej +8 km.
   — *test budżetu + przypadki 3, 10.*
3. Korytarz, którego rowerowy OSRM nie przejedzie (wejście→wyjście > 1,3×),
   odpada. — *test 16; na żywo Przełom Dunajca odrzucony (35 km zamiast 9,4).*
4. Brak danych Ridemore = zero dodatkowych zapytań do OSRM. — *test 8.*
5. Jedna osoba nie tworzy „sprawdzonego odcinka"; powtórzenia jednej osoby
   liczą się najwyżej 3 razy. — *test 17.*
6. Skala popularności lokalna, nie krajowa. — *test 18.*
7. Skarby bez osobnego systemu. — *test 14.*
8. Bez suwaka i bez komunikatów; `variant` w odpowiedzi pozwala później
   pokazać powód. — *Etap 1; komunikat w Etapie 2c.*
9. Profile Szosa/Gravel/MTB wpływają na wybór. — *Etap 2b: testy 11, 11b, 12, 13, przejezdność per profil.*
10. `php tests/run.php planner` zielone.
11. Ten sam korytarz jest utrzymywany między kolejnymi waypointami, o ile
    nadal mieści się w budżecie i jest przejezdny. — *test ciągłości.*

## Testy — przypadki z planu

| # | Przypadek | Test | Stan |
|---|---|---|---|
| 1 | najkrótsza = popularna | Przypadek 1 | ✓ |
| 2 | popularna +5% | Przypadek 2 | ✓ |
| 3 | popularna +20% | Przypadek 3 | ✓ |
| 4 | popularna +50% | Przypadek 4 | ✓ |
| 5 | dwa korytarze | Przypadek 5 | ✓ |
| 6 | krótki bardzo popularny | Przypadek 6 | ✓ |
| 7 | długi słabszy vs krótki mocny | Przypadek 7 | ✓ |
| 8 | brak danych | Przypadek 8 | ✓ |
| 9 | start blisko korytarza | Przypadek 9 | ✓ |
| 10 | start daleko | Przypadek 10 | ✓ |
| 11 | szosa | Przypadek 11, 11b | ✓ |
| 12 | gravel | Przypadek 12 | ✓ |
| 13 | MTB | Przypadek 13 | ✓ |
| 14 | skarb poza korytarzem | Przypadek 14 | ✓ |
| 15 | korytarz w złą stronę | Przypadek 15 | ✓ |
| 16 | nieprzejezdny wg OSM | Przypadek 16 | ✓ |
| 17 | jedna osoba vs wiele | Przypadek 17 (+ score) | ✓ |
| 18 | skala lokalna | Przypadek 18 | ✓ |

## Znane ograniczenia

- **Limit rowerowego OSRM (1 zapytanie/s)** — pierwsze przeliczenie odcinka
  z korytarzem trwa 2–3 s; przy wielu użytkownikach naraz publiczny serwer się
  nie skaluje (docelowo własny OSRM albo płatne API — decyzja w V3).
- **Współrzędne tras trafiają do logów FOSSGIS** — tak jak wcześniej do
  serwera demo OSRM.
- **Luki OSM** — ścieżka, której rowerowy graf OSM nie ma (np. fragment
  Przełomu Dunajca), zostaje odrzucona nawet jako znana trasa. Tak chce
  reguła 4; tryb bazy („Wybierz konkretną trasę") dalej jedzie po niej.
- **Na dev większość przejazdów nie ma pliku GPX** (12 ze 100 u głównego
  jeźdźca), więc warstwa „Moje przejazdy" widzi tylko te.
- **Po przejściu na rowerowy OSRM** trasa bazowa często sama jedzie po
  przejazdach usera (88–100% na dev) — warstwa ma wtedy mniej do poprawiania.
