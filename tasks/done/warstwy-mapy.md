# Warstwy mapy w słowniku — kontrakt implementacyjny

Data przyjęcia: 2026-08-26. Kierunek zaakceptowany przez usera.

## Cel

Warstwy map przestają być listą wpisaną w kod każdej strony z osobna. **Słownik
trzyma STRUKTURĘ** (jakie warstwy istnieją, co jest czyim dzieckiem, jak się
nazywają, w jakiej kolejności stoją, czy są aktywne, jaki mają stan domyślny
w danym kontekście), a **kod trzyma MECHANIZM** (jak się daną rodzinę warstw
rysuje) i dokłada zdolności, których serwer dziś nie ma.

Efektem ma być jedna, drzewiasta kontrolka warstw na wszystkich mapach serwisu
oraz **jedno pojęcie „warstwa × kontekst"** zamiast trzech dzisiejszych notacji
zasięgu.

## Skąd to się wzięło

User, 2026-08-26: „czy możemy warstwy i ich konfigurację przenieść do
dictionary (…) żeby można było hierarchizować. Dla przykładu mamy skarby
odkryte i nieodkryte. Może warto zrobić Skarby a pod nimi Skarby odkryte,
nieodkryte (na panelu usera odkryte będą dla kontekstu usera, a dla kontekstu
społeczności dla społeczności). Trasy również: w kontekście społeczności dla
całości, a usera to tylko usera."

Doprecyzowanie tego samego dnia: brakujące zdolności serwera **mają wejść do
zakresu** — słownik wprowadza pozycję, kod dokłada jej obsługę.

## Stan zastany (zmierzony, nie z pamięci)

**Zasięg jest dziś wyrażany na TRZY różne sposoby**, po jednym na rodzinę warstw:

| warstwa | jak dziś powstaje zasięg |
|---|---|
| `cells`, `heat` | opcja `scope: 'all'\|'me'\|'rider'` (+ `slug`) → parametr zapytania do `/api/discovery/cells` |
| `slady`, `trails` | GOTOWY szablon URL kafla zbudowany w kontrolerze (`TileCache::urlTemplate` z kluczem `all` / `kr` / `u-{slug}`) |
| `treasures` | wyliczany w JS parametr `stan=moje\|nowe\|(brak)` z pary checkboxów — tabela prawdy w `assets/js/discovery-map.js` |

**Czytnik stanu kontrolki jest skopiowany trzy razy**: `on()` w `discovery.php`,
`wlaczona()` w `trail.php` i w `rider-profile.php` (czwarta odmiana
w `event-page.php`). Każda kopia robi to samo.

**Hierarchia już istnieje, ale wyłącznie jako gałęzie `if` w JS**: `treasures`
→ `treasuresFound`, razem z pułapką „true vs null" (strona bez osobnego
przełącznika MUSI podać `null`, bo `true` znaczy „zapalona i nie do ruszenia").

**Klucze warstw dziś w użyciu:** `cells`, `heat`, `slady`, `trails`,
`treasures`, `treasuresFound`.

**Poza standardem** (bez `ridemoreDiscoveryMap` albo bez kontrolki): kronika,
panel skarbów w adminie, zgłoszenie skarbu.

## Decyzje architektoniczne (zaakceptowane, nie wracamy do nich)

1. **Granica danych i mechanizmu.** Wiersz słownika mówi, CO to za warstwa
   i gdzie stoi w drzewie. Kod mówi, JAK się ją rysuje. `meta.kind` wskazuje
   renderer z rejestru w `discovery-map.js`. **Nowa warstwa istniejącego
   rodzaju = jeden wiersz w słowniku. Nowy rodzaj = kod.** Nie konfigurujemy
   mechanizmu rysowania — to byłoby wymyślanie języka programowania w JSON-ie.
2. **Trzy rodzaje na start:** `cells` (API pól + wielokąty), `tiles` (rastrowe
   kafle z szablonem URL), `markers` (piny z parametrem filtra).
3. **Kontekst jest jeden i nazwany:** `all` (społeczność), `me` (moja mapa),
   `rider` (cudzy profil). Strona deklaruje kontekst RAZ; każda warstwa
   rozwiązuje z niego swoje źródło.
4. **Dzieci warstwy `markers` są filtrem, nie osobnymi warstwami.** Rodzic
   niesie `meta.filterParam`, dziecko `meta.filterValue`; renderer składa
   z zapalonych dzieci wartość parametru. Wszystkie dzieci zapalone = brak
   filtra (komplet), żadne = nie pytamy serwera w ogóle.
5. **Pułapka „true vs null" znika z kontraktu stron.** Drzewo jest jedno dla
   całego serwisu, więc nie ma stanu „ta strona nie zna tej warstwy". To, czego
   dany kontekst nie pokazuje, wynika ze słownika, a nie z tego, co strona
   pamiętała przekazać.
6. **Szablony URL kafli nadal buduje SERWER.** Niosą epokę `?v=`, która
   unieważnia kafle — JS nie ma jak jej znać. Strona podaje je mapą
   `sources: {klucz => szablon}`, a nie osobnymi opcjami per warstwa.
7. **Warstwa idzie za KONTEKSTEM STRONY** (decyzja usera, 2026-08-26, po pytaniu
   o profil rowerzysty: „dokładnie tak. Zmienia się kontekst strony i za tym idą
   warstwy"). „Ślady" na profilu znaczą ślady TEJ OSOBY, na mapie społeczności —
   wszystkich, na własnej — moje. Ta sama zasada obejmie „Trasy" w Etapie 3.
   **Świadomie NIE robimy dwóch osobnych warstw** („ślady społeczności" obok
   „moich"). Jeśli okaże się to za ciasne, rozdzielimy je wtedy — user powiedział
   wprost, że na razie działamy tak.
8. **`is_active` w słowniku gasi warstwę w CAŁYM serwisie.** To jest moc, którą
   trzeba pilnować testem (patrz Etap 5) — dziś taki błąd dotknąłby jednej
   strony, po zmianie dotknie wszystkich map naraz.

## Etap 1 — jedno pojęcie zasięgu (tylko kod, bez bazy) — ZROBIONE 2026-08-26

Refaktor bez zmiany zachowania. Robimy go PRZED słownikiem, bo przeniesienie
do danych czegoś, co ma trzy notacje, zabetonowałoby te trzy notacje
w migracjach. Kod jest tani w refaktorze, schemat słownika nie.

- `discovery-map.js` przyjmuje `context` ('all'|'me'|'rider') zamiast `scope`
  i mapę `sources` zamiast osobnych `trailsTiles`/`sladyTiles`.
- Czytnik stanu kontrolki przenosi się do JS jako `ridemoreReadLayers(boxEl)` —
  jedna implementacja zamiast czterech kopii.
- Strony przestają ręcznie składać `layers: {...}`; podają kontekst i źródła.

**Kryteria akceptacji:**
- w `views/` nie ma ani jednej lokalnej funkcji czytającej `[data-layer]`,
- pięć map (odkrycia ×2 zakładki, profil, trasa, panel dnia wydarzenia) zachowuje
  się dokładnie jak przed zmianą, łącznie ze stanem domyślnym warstw,
- tabela prawdy skarbów bez zmian (test),
- `php tests/run.php` na zielono.

**ZROBIONE 2026-08-26.**

## Etap 1b — źródło warstwy „Ślady" wynika z kontekstu — ZROBIONE 2026-08-26

Domknięcie Etapu 1 po decyzji nr 7. Wciąż bez bazy — najpierw ma być JEDNA
zasada w kodzie, a dopiero potem jej zapis w słowniku.

Stan zastany do usunięcia: profil i zakładka „moja" na `/odkrycia` rysowały
ślady osoby jako OSOBNĄ warstwę zawsze zapaloną i BEZ przełącznika
(`tileTracks`/`tileTracksMe`), a stojący obok przełącznik „Ślady" sterował
warstwą SPOŁECZNOŚCI. Czyli przełącznik nie sterował tym, co człowiek widział
jako swoje ślady.

- `slady` rozwiązuje się z kontekstu: `all` → klucz `all`, `me` → `u-{slug}`/`me`,
  `rider` → `u-{slug}`.
- Osobne, zawsze zapalone warstwy kafli znikają — zastępuje je ta jedna,
  sterowana przełącznikiem (domyślnie zapalonym, więc obraz startowy bez zmian).
- Podpis warstwy idzie za kontekstem („przejechane trasy społeczności" /
  „twoje przejechane trasy" / „przejechane trasy tej osoby").

**Kryteria akceptacji:**
- na profilu i na zakładce „moja" warstwa „Ślady" pokazuje ślady TEJ OSOBY,
- przełącznik „Ślady" faktycznie je gasi (dotąd gasił cudzą warstwę),
- mapa społeczności bez zmian,
- żadna mapa nie ładuje dwóch warstw śladów naraz.

## Etap 2 — słownik `map_layer` + drzewiasta kontrolka — ZROBIONE 2026-08-26 (migr. 072)

- Migracja: słownik `map_layer` + pozycje:
  `cells`, `heat`, `slady`, `trails`, `treasures` → dzieci `treasuresFound`,
  `treasuresNew`; `trails` → dzieci wg Etapu 3.
- `Dictionary::tree()` rozszerzone o `meta` i `icon` (dziś ich NIE wybiera —
  sprawdzone).
- Nowy `Models\MapLayer` — czyta drzewo, rozwiązuje kontekst, oddaje gotową
  konfigurację jednocześnie dla kontrolki (PHP) i dla modułu mapy (JSON).
- `views/web/partials/map-layers.php` renderuje DRZEWO zamiast płaskiej listy;
  dziecko gaśnie razem z rodzicem.
- Rejestr rendererów `kind` w `discovery-map.js`.

**Kryteria akceptacji:**
- kontrolka wygląda i działa tak samo na wszystkich mapach,
- zgaszenie rodzica gasi dzieci na mapie i w żądaniach,
- pozycja `is_active = 0` znika ze wszystkich kontrolek,
- tabela prawdy skarbów wyprowadzona z dzieci daje ten sam wynik co dziś.

**ZROBIONE 2026-08-26.** Migr. 072 (żadnej nowej tabeli — 7 wierszy
`dictionary_items`, słownik `map_layer`). `Models\MapLayer` (`tree`,
`tileKeysFor`, `filtersFor`, `withQueryOverrides`, `flatten`, `tileKeyFor`,
`only`). `map-layers.php` renderuje drzewo, dziecko gaśnie CZYSTYM CSS
(`:has()`), bez JS. `discovery-map.js`: `ridemoreReadLayers` liczy dziecko
jako `checked ⊕ rodzic`; generyczny `ridemoreComposeFilter(parent, layers,
filters)` zastąpił dwuwartościowy ternary skarbów; `tileLayers` jedna mapa
zamiast `trailTiles`/`sladTiles` (kolejność rysowania STAŁA, nie z kluczy
obiektu). Odkryta w trakcie realna pułapka (nie tylko refaktor): przy
przenoszeniu okazało się, że `trail.php` i panel dnia wydarzenia mają
GENUINE inny kontekst dla `cells` (mgła to tam dodatek, nie temat strony —
inny domyślny stan, inny podpis) niż /odkrycia — rozwiązane opcją `only`
(które top-level warstwy strona w ogóle pokazuje) + jawnym patchem węzła
`cells` w kontrolerze, bo to jest wiedza o UKŁADZIE KONKRETNEJ STRONY, nie
o samej warstwie. Dołożone: `treasuresNew` jako realny przełącznik (dotąd
istniał tylko jako domyślny stan „nie-found") na wszystkich czterech
konsumentach (discovery ×2 zakładki, trail, panel dnia wydarzenia).
Testy: `tests/warstwy_mapy_test.php` (12) czytają PRAWDZIWY zasiany
słownik, nie budują drzewa sztucznie. `php tests/run.php`: 259/259.
**Panel skarbów w adminie i zgłoszenie skarbu ŚWIADOMIE nieruszone** — to
Etap 4, nie Etap 2.

## Etap 3 — zdolności serwera, których dziś nie ma — ZROBIONE 2026-08-26

Obie pozycje wchodzą do słownika w Etapie 2, ale bez tego etapu nie mają czego
pokazać.

1. **„Skarby odkryte" w kontekście SPOŁECZNOŚCI.** `Treasure::mineCondition`
   filtruje wyłącznie względem oglądającego (`treasure_finds.user_id = :uid_mine`).
   Dokładamy wariant „znaleziony przez kogokolwiek" dla kontekstu `all`.
   §27 nie stoi na przeszkodzie: liczba znalazców jest już publiczna
   („znaleziony przez 47 riderów"), lista nazwisk nadal NIGDY.
2. **„Trasy tylko moje".** Klucz kafla `kr` to wszystkie aktywne znane trasy.
   Dokładamy klucz osoby (trasy ukończone) w `Models\TileSource` + zapytanie
   w `Models\KnownRoute`, razem z unieważnianiem kafli.

**Kryteria akceptacji:**
- kontekst `all` pokazuje skarby znalezione przez kogokolwiek, `me`/`rider` —
  znaleziska tej osoby, i są to dwa RÓŻNE zbiory (test),
- klucz tras osoby przechodzi przez `Support::visibleRider` jak `u-{slug}`,
- zmiana trasy albo ukończenie jej unieważnia właściwe kafle.

**ZROBIONE 2026-08-26.**

1. **Skarby znalezione przez kogokolwiek** — `Treasure::mineCondition()` dostała
   `string $scope = 'viewer'`; `'community'` liczy istnienie znalazcy bez
   względu na tożsamość (`EXISTS (SELECT 1 FROM treasure_finds WHERE
   treasure_id = s.id)`, bez `:uid_mine` — bindowanie tego placeholdera stało
   się WARUNKOWE, `str_contains($mineSql, ':uid_mine')`, bo w tym wariancie
   go w SQL-u nie ma). `inBounds`/`clustersInBounds` dostały `$mineScope`.
   `api/routes.php`: `$mineScope = scope=all && foundBy===null ? 'community'
   : 'viewer'`; JS (`refreshTreasures`) dokłada `&scope=` do żądania RAZEM
   z `&stan=` (bez `stan` scope nic by nie zmieniał). 7 nowych testów w
   `tests/skarby_test.php` (m.in. gość widzi „odkryte przez kogokolwiek" bez
   konta — §27 nie stoi na przeszkodzie, to samo pytanie zadaje już publiczny
   licznik `finders`).
2. **Trasy tylko moje** — nowy klucz kafla `kd-{slug}` w `Models\TileSource`
   (+ prywatny `kd-me`, para do `me`/`u-{slug}` przy śladach, dla kogoś bez
   publicznego profilu). „Ukończona" to DOKŁADNIE `KnownRoute::
   progressForUser`'s `matched >= cells_total`, zapytaniem korelowanym per
   trasa (katalog jest mały). `KnownRoute::invalidateTiles()` (już istniejący
   hak na edycję/podmianę/kasowanie trasy) dostał dopisanie `kd-{slug}` dla
   każdej osoby, która ma choć jedno odkryte pole na tej trasie. **Świadoma,
   opisana luka**: nowa jazda, która DOPIERO doprowadza trasę do 100%, nie
   odświeża tu niczego — ten sam gap co przy `u-{slug}` (`TileCache::
   invalidateForEdition` też nie reaguje na nowe jazdy, tylko na zmianę
   treści śladu). Naprawa obu wymagałaby unieważniania przy KAŻDEJ jeździe;
   nieproporcjonalne do tego zadania.
   Słownik: `trails.contexts.me/rider.source` = `subject-done` (nowy token,
   `MapLayer::tileKeyFor` → `kd-{slug}`/`kd-me`), `all` zostaje
   `known-routes`. **TrailController i EventController PATCHUJĄ węzeł
   `trails` z powrotem na `kr`** — te dwie strony pokazują trasy jako
   KONTEKST wokół czegoś (samej trasy / dnia wydarzenia), nie postęp widza;
   ta sama zasada co patch węzła `cells` z Etapu 2. 7 nowych testów w
   `tests/znane_trasy_test.php` (nieukończona/częściowa/ukończona/cudza/
   bramka widoczności/unieważnianie) — metodologicznie sprawdzają
   PRZYNALEŻNOŚĆ HASHU tej konkretnej trasy, nie samą liczbę grup: prawdziwy
   rowerzysta z bazy DEV może już mieć INNĄ, naprawdę ukończoną trasę.
   Zweryfikowane na żywo na wszystkich czterech stronach: trasa i panel dnia
   nadal pokazują katalog (`kr`), profil i `/odkrycia` (zakładka „moja",
   niesprawdzalna bez logowania — ale profil to ta sama ścieżka kodu)
   przełączyły się na `kd-{slug}`.
`php tests/run.php`: 273/273.

## Etap 4 — trzy ekrany do standardu — ZROBIONE 2026-08-29 (wykonanie niżej)

Kronika (dziś rysuje pola własnym `ridemoreHexRing`), panel skarbów w adminie
(warstwy na sztywno, bez kontrolki), zgłoszenie skarbu (ma partial kontrolki,
ale piny składa ręcznie).

## Etap 5 — testy i dokumentacja — ZROBIONE 2026-08-29 (wykonanie niżej)

- Test pilnujący, że słownik ma KOMPLET kluczy, których oczekuje rejestr
  rendererów — bez niego literówka w `code` gasi warstwę na wszystkich mapach
  naraz i nikt się o tym nie dowie.
- Test tabeli prawdy skarbów (rodzic + dzieci → parametr filtra).
- `md/models.md`, `md/database.md`, `md/views-and-frontend.md`, `md/features.md`.

## Naprawa błędu znalezionego po drodze (2026-08-26, poza numeracją Etapów)

Zgłoszenie usera: „ślady, które wgrałem, nie pojawiają się na tiles w
warstwie ślady". Zweryfikowane na danych DEV (52 przejazdy solo z realnym
plikiem na dysku, 0 obecnych na kaflu własnej mapy) — dwa NIEZALEŻNE,
ZASTANE błędy w `TileSource::tracks()` (sprzed tej sesji, potwierdzone
`git show HEAD`):

1. Gałąź `me`/`u-{slug}` w ogóle nie sięgała po `rider_activities.gpx_url`
   — solo nie wchodziło tam NIGDY, mimo że to WIĘKSZOŚĆ tego, co ludzie
   realnie jeżdżą. Naprawione TYLKO dla klucza `me` (nie `u-{slug}`) — plik
   solo jest surowy (§27), więc na publicznym, cache'owalnym kluczu
   pojawić się nie może.
2. `userIdFor('me'/'kd-me')` wołało `Auth::id()` — metody, której
   `Core\Auth` nigdy nie miało. Każde żądanie tych kluczy od zalogowanego
   kończyło się fatalnym błędem PHP. Naprawa: `Auth::user()->id`.

Konsekwencja dla `MapLayer`: nowy token `subject-private` (`slady.contexts.
me.source`, migracja 072 — trzeci samokorygujący `UPDATE` w tym samym
pliku) — w odróżnieniu od `subject`, IGNORUJE slug i zawsze zwraca `me`,
żeby ktoś z publicznym profilem też zobaczył WŁASNE solo na swojej mapie.
`rider` zostaje przy `subject` (`u-{slug}`) — to wciąż jest widok publiczny.

Testy: `tests/przejazdy_solo_test.php` (solo faktycznie na kluczu `me`;
`me` nie wybucha z sesją i bez niej) + poprawione 2 testy w
`tests/warstwy_mapy_test.php`, które zakładały stary klucz. Zweryfikowane
też end-to-end przez `Controllers\TileController::show()` z symulowaną
sesją (1749 B, realna geometria, brak wyjątku).
`php tests/run.php`: 275/275.

## Poza zakresem

- Zmiana wyglądu kontrolki poza tym, czego wymaga drzewo (wcięcie dziecka).
- Nowe warstwy inne niż wymienione wyżej.
- Mapy-lokalizatory z jedną pinezką (zbiórka, pinezki na liście wyjazdów,
  pickery w kreatorze) — zostają gołe celowo, warstwy byłyby tam szumem.

---

## Etap 5 — ZROBIONE 2026-08-29

**Testy** — `tests/warstwy_mapy_test.php`, dwa nowe, domykające OBIE strony
styku słownik↔kod:

1. `słownik warstw: KAŻDY token `source` jest rozpoznawany przez tileKeyFor` —
   czyta tokeny wprost z bazy (aktywne wiersze `map_layer`, `meta.contexts.*.source`)
   i żąda, żeby każdy miał odpowiednik w `MapLayer::tileKeyFor()`. Ze
   strażnikiem `>= 6 tokenów`: bez niego pusty albo zepsuty słownik
   przechodziłby test bezszelestnie, bo pętla po zerze elementów zawsze jest
   zielona.
2. `słownik warstw: każdy zadeklarowany `source` daje realny klucz kafla
   w drzewie` — porównuje ZBIORY kluczy ze słownika i z `tileKeysFor(tree($ctx))`
   dla `all`/`me`/`rider`. Łapie to, czego pierwszy test nie złapie: literówkę
   w nazwie KONTEKSTU (token poprawny, warstwa i tak znika z mapy) oraz
   sytuację odwrotną — kod produkujący kafel dla warstwy, której nikt nie
   zadeklarował.

**Tabela prawdy skarbów** okazała się już pokryta — `filtersFor: opis dla JS
niesie param rodzica i wartości dzieci` oraz `filtersFor: rodzina bez dzieci
(rider) niesie pusty opis, nie brak wpisu`. Ten drugi pilnuje właśnie tego,
co jest tu groźne: przy braku wpisu `ridemoreComposeFilter` wpada w zapasową,
starą regułę dwuwartościową zamiast czytać słownik. Nie dublowałem.

**Dokumentacja** — `md/models.md` (obszerny wpis `MapLayer` z granicą
„słownik mówi CO, klasa JAK, rysowanie zostaje w JS"), `md/database.md`
(sekcja „Słownik `map_layer`"), `md/views-and-frontend.md` i `md/features.md`
opisują ten moduł od Etapów 2–3. Bez zmian w tym etapie.

`php tests/run.php`: 390/390.

---

## Etap 4 — ZROBIONE 2026-08-29 (KONTRAKT ZAMKNIĘTY)

Audyt trzech ekranów pokazał, że „przejście na standard" znaczy dla każdego
z nich co innego, bo każdy rysuje WŁASNĄ warstwę semantyczną, której wspólny
silnik nie umie. Decyzja usera: **„kontrolka tak, silnik nie"** — standardem
jest wspólna kontrolka warstw KONTEKSTOWYCH (mgła, heatmapa, ślady, trasy),
a rysowanie własne ekranu zostaje.

### Panel skarbów w adminie — ZROBIONE

`Admin\TreasureController::index()` buduje drzewo `tree('all', ['only' =>
['cells','heat','slady','trails']])` i szablony kafli; `treasures-admin.php`
renderuje wspólny partial i czyta z niego stan (`ridemoreReadLayers`) zamiast
wpisanego na sztywno obiektu, a zmiana checkboksa woła `map.ridemoreSetLayer`.
`.tr-work__map` dostało `position:relative` — kontrolka jest nakładką
`absolute` i bez tego uciekłaby na początek strony.
BEZ zapisu stanu w adresie: ten ekran ma już w `$_GET` szukanie, filtr,
sortowanie i stronę listy, a warstwy nie są tu widokiem do podesłania linkiem.

### Zgłoszenie skarbu (apka i web) — ZROBIONE

`TreasureProposalController::form()` podaje to samo drzewo obu wariantom.
Szablony robią `array_merge($mapLayers, [własny węzeł „Istniejące skarby"])`,
więc kontrolka ma pięć pozycji z dwóch źródeł, a jeden handler rozdziela
obsługę: własna warstwa → grupa Leafletu ekranu, reszta → `ridemoreSetLayer`.
Silnik dostaje ISTNIEJĄCĄ mapę (`map: mapa`) — gdyby tworzył swoją, pinezka
zgłoszenia i klikanie w mapę przestałyby działać.

### Kronika — ŚWIADOMIE BEZ ZMIAN

Jej mapa rysuje pola JEDNEGO przejazdu w podziale „odkryte tym przejazdem /
już miałem" (`$rideCells` + `ridemoreHexRing`). Silnik rysuje ZAGREGOWANĄ mgłę
z `/api/discovery/cells` — inne dane, inne znaczenie. Przeniesienie zabrałoby
ten podział, czyli jedyny powód, dla którego ta mapa istnieje. Gdyby kiedyś
miało to się zmienić, najpierw silnik musi dostać tryb „pola jednego
przejazdu" — to osobna robota w `discovery-map.js`, który obsługuje dziś
każdą mapę w serwisie.

### Testy

`tests/warstwy_mapy_test.php`: `panel skarbów: warstwy kontekstowe TAK, węzeł
„Skarby" NIE`, `panel skarbów: kontrolka i stan warstw idą z JEDNEGO źródła`,
`zgłoszenie skarbu: warstwy ze słownika + własna warstwa antyduplikatowa`.
Pilnują tego, co przy tej zmianie najłatwiej zepsuć: powrotu węzła „Skarby"
(dwa komplety pinezek) i utworzenia przez silnik drugiej mapy.

`php tests/run.php`: 393/393.
