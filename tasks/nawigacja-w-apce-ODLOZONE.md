> **ODŁOŻONE 2026-09-11 — decyzja usera: „odpuśćmy robienie nawigacji od zera,
> skupimy się na działaniu samej aplikacji".**
>
> Dokument wychodzi z `tasks/active/`, żeby nie udawał pracy w toku, ale zostaje
> w repozytorium w całości — nie dlatego, że kiedyś wrócimy, tylko dlatego, że
> zawiera dwie rzeczy przydatne niezależnie od nawigacji:
>   1. **zmierzony stan apki** (§1: co już jest i gdzie), w tym fakt, że siatka
>      pól odkryć liczy się tą samą matematyką w PHP i w JS;
>   2. **opis twardej przeszkody offline** (§2: tryb apki zależy wyłącznie od
>      User-Agenta, więc service worker nie może cache'ować dokumentów) — to
>      dotyczy CAŁEJ apki, nie tylko nawigacji, i wróci przy
>      `tasks/active/apka-offline.md`.
>
> Etap 2 planu `tasks/active/apka-przeglad-2026-09.md` (dojazd do punktu
> + wysyłka GPX-a do cudzej nawigacji) został zrobiony 2026-09-10 i ZOSTAJE —
> to osobna, działająca rzecz, nie namiastka tego modułu.

---

# Nawigacja w apce — moduł „licznik rowerowy" — KIERUNEK ZATWIERDZONY

Zgłoszenie usera 2026-09-10:

> „chciałbym aby zrobić w aplikacji nawigowanie tak jak to robi garmin. Miało by
> to działać w ten sposób że zapisuję się na wydarzenie później mogę kliknąć
> w aplikacji uruchom nawigację i wewnątrz aplikacji ridemore.bike android włącza
> mi się moduł nawigacji (podobnie jak to robią komputery nawigacyjne na rower
> Garmina). Dodatkową funkcjonalnością jednak byłoby to że pokazuje jak odkrywam
> kafle, zlicza punkty na bieżąco oraz wskazuje skarby. Musiałby się szykować
> specjalny ślad GPX z extension który ładowany byłby offline i on odpowiadałby
> za nawigację."

**Sześć decyzji architektonicznych z §10 zostało podjętych przez usera
2026-09-10** — dokument przestał być propozycją, a stał się kierunkiem. Nadal
NIE jest kontraktem gotowym do wykonania etap po etapie: Etap 0 (telefon) jest
warunkiem wstępnym reszty, a dopiero po nim ma sens spisywanie kryteriów
akceptacji dla Etapów 1–5. Reszta dokumentu to fakty, na których te decyzje
zapadły — warto ją przeczytać przed pierwszą linią kodu, zwłaszcza §2.

---

## 1. Co już jest — zmierzone w kodzie, nie założone

Ta lista jest tu najważniejsza, bo przesądza, ile z tego modułu to NOWA robota,
a ile złożenie części, które już działają.

| Potrzebne | Stan | Gdzie |
|---|---|---|
| Ciągła pozycja w tle, z usługą pierwszoplanową | **JEST** | `RM.native.startTracking` (`native.js`), `@capacitor-community/background-geolocation` |
| Buforowanie śladu bez sieci i wysyłka po powrocie | **JEST** | `assets/js/app-tracking.js` |
| Zamknięcie jazdy: punkty, pola, skarby po drodze, ekran wyniku | **JEST** | `POST /admin/moje-przejazdy/solo` → `RiderActivity::recordSolo` → `ride-summary-app.php` |
| Alert „zbliżasz się do skarbu", liczony lokalnie | **JEST** | `app-tracking.js` (własny haversine), `Treasure::nearbyForAlerts()` |
| Pełnoekranowa mapa apki + szuflady + pływający pasek | **JEST** | `discovery-app.php`, `.disc-panel`, `app.css` |
| **Zamiana pozycji na pole odkryć — W JAVASCRIPCIE** | **JEST** | `axialKey(lat, lon, sizeM)` w `discovery-map.js` |
| Cache kafli mapy i podkładu na telefonie | **JEST** | `sw-app.js` (limity 600 / 900) |
| Spakowana geometria śladu zamiast pliku GPX | **JEST** | `Models\GpxGeometry` (format d6v), `/api/gpx/{...}` |
| Ekran „tu jestem" i stan czekania na GPS | **JEST od 2026-09-10** | `ridemoreAddLocateControl` (`gpx-map.js`) |

**Najważniejsze z tej tabeli**: siatka pól jest w PHP i w JS **tą samą
matematyką** — `DiscoveryGrid::pointToAxial` (rozmiar pola 468,75 m, poziom 4)
i `pointToAxial` w `discovery-map.js`. JS potrafi już dziś powiedzieć, w którym
polu stoi rower, bez pytania serwera. Live'owe „odkrywam kafle" nie wymaga więc
ani jednej nowej linii matematyki — tylko listy pól JUŻ odkrytych, wiezionej
w pliku kursu.

Czego NIE ma (i co trzeba napisać — nasze, nie cudze):
- **silnika prowadzenia po śladzie** na telefonie: rzut pozycji na trasę,
  następny manewr, dystans do niego, zapowiedzi na progach, alarm zjechania
  i powrót na trasę (§4 — budulec z GitHuba: `turf.js`, MIT);
- **generatora kursu** z manewrami po polsku (§4 — Valhalla `trace_route`
  wołana RAZ, na serwerze, przy budowie pliku);
- ekranu nawigacji;
- pobierania kafli „na zapas" wzdłuż trasy;
- głosu (`@capacitor-community/text-to-speech`) i utrzymywania włączonego ekranu.

---

## 2. JEDYNA TWARDA PRZESZKODA — i to nie jest ta, której się spodziewasz

Nie chodzi o GPS ani o mapy. Chodzi o to, **że ekran nawigacji musi się otworzyć
bez zasięgu**, a dziś w apce nie może otworzyć się ŻADNA strona serwisu.

Powód jest udokumentowany i potwierdzony pomiarem (kontrakt `apka-offline.md`,
poprawka z 2026-08-29): tryb apki rozpoznaje się **wyłącznie po User-Agencie**
(`core/bootstrap.php:39`), a service worker nie może ustawić tego nagłówka —
`User-Agent` jest nagłówkiem zabronionym. Gdy worker przechwycił nawigację do
`/odkrycia` i wysłał ją własnym `fetch`, serwer zobaczył zwykłą przeglądarkę
i oddał widok webowy. Dlatego dziś obowiązuje zasada „żaden dokument nie
przechodzi przez `fetch` workera", pilnowana testem — i dlatego zimny start bez
zasięgu kończy się ekranem błędu WebView.

Ten sam test niesie w komentarzu przewidzianą furtkę: *„gdy dojdzie drugi sygnał
trybu apki (nagłówek, cookie), trzeba tu świadomie wrócić"*. To jest ta chwila.

**Propozycja: cookie jako drugi sygnał trybu apki.** Serwer przy pierwszym
żądaniu z UA `ridemore-app` ustawia trwałe, first-party cookie (`rm_app=1`,
`SameSite=Lax`), a `APP_IS_APP` to od tej pory `UA ∨ cookie`. Cookie jedzie
z każdym żądaniem workera, więc worker może wreszcie cache'ować dokumenty —
i tylko wtedy ekran nawigacji da się otworzyć bez zasięgu.

Konsekwencje, które trzeba przyjąć świadomie:
- w przeglądarce cookie nigdy nie powstanie (nikt tam nie ma tego UA), więc www
  nie zmienia się w niczym;
- test „żaden dokument nie przechodzi przez fetch workera" trzeba przepisać na
  „przechodzą WYŁĄCZNIE strony z listy" — bo to lista, a nie zakaz, jest tu
  realnym zabezpieczeniem;
- cache'owana strona to zawsze ryzyko „zmiana nie wchodzi"; dlatego listę
  ograniczamy do ekranu nawigacji i jego zasobów, a nie do serwisu.

**Wariant B, gdybyś nie chciał ruszać rozpoznawania trybu apki:** ekran
nawigacji jako JEDYNA strona wożona w `app/www/` obok ekranu offline. Łamie
decyzję architektoniczną nr 1 („apka nie ma własnego frontu") i oznacza własną
kopię Leafletu, stylów i logiki — czyli dokładnie ten dług, dla którego
`server.url` zostało wybrane. Nie polecam, ale to jest wyjście awaryjne.

---

## 3. Plik kursu — GPX z rozszerzeniami czy własny format?

Prosisz o „GPX z extension". To działa, ale warto zobaczyć koszt.

**Co musi wieźć kurs** (żeby cała obiecana funkcjonalność zadziałała offline):

1. przebieg trasy — punkty, po których prowadzimy;
2. wskazówki (zakręty) — policzone z góry, patrz §4;
3. **pola odkryć wzdłuż korytarza wraz z informacją, które masz już odkryte** —
   bez tego „pokazuje jak odkrywam kafle" nie ma z czym porównać;
4. skarby przy trasie (z zachowaniem reguł ukrycia — §5);
5. stawki punktowe obowiązujące w chwili wygenerowania (§6);
6. metadane: nazwa wydarzenia/trasy, dystans, przewyższenie, wersja kursu.

Pozycje 3–5 to w GPX-ie `<extensions>` z własną przestrzenią nazw, czyli
w praktyce nasz własny format zapakowany w XML — z którego żadna inna
nawigacja i tak nie skorzysta, a telefon musi go sparsować XML-em przy każdym
otwarciu.

**Rekomendacja: dwa pliki o dwóch różnych zadaniach.**

- **`kurs` (JSON)** — to, czym żywi się nasz moduł. Geometria w istniejącym
  spakowanym formacie `GpxGeometry` (d6v — już używanym w serwisie), reszta jako
  zwykłe pola. Mały, czytany bez parsera XML, wersjonowany.
- **`GPX` (czysty, bez rozszerzeń)** — to, co wysyłasz do OsmAnda, Garmina czy
  Komoota. Już istnieje; brakuje tylko sposobu wyjęcia go z apki (osobny etap
  w `apka-przeglad-2026-09.md`).

Jeden format do dwóch rzeczy naraz byłby gorszy w obu.

---

## 4. Wskazówki — WŁASNA nawigacja, nic oddanego cudzej aplikacji

### Korekta pierwszej wersji tego rozdziału (2026-09-10)

Stało tu: „Garmin ma router i mapę z nazwami ulic, my nie — więc nie da się
powiedzieć «za 200 m w prawo w ulicę Leśną», a po pełną nawigację odsyłamy
do OsmAnda". **Przesłanka była prawdziwa, wniosek fałszywy** — i user słusznie
to wychwycił („czuję, że zdelegowałeś to").

Błąd polegał na założeniu, że router musi pracować W CZASIE JAZDY. Nie musi.
Garmin też go wtedy nie ma: licznik gra z **pliku kursu policzonego wcześniej**
(CoursePoints). Router jest nam potrzebny **raz, przy generowaniu kursu, na
naszym serwerze** — a potem telefon odtwarza gotowe wskazówki offline, sam,
naszym kodem.

**Odpowiedź na pytanie „co będzie odpowiadało za nawigację": nasz własny
silnik w apce.** W czasie jazdy telefon nie odpytuje NIKOGO — ani Google,
ani OsmAnda, ani naszego serwera.

### Stos — wszystko open source, każdy element z konkretnym zadaniem

| Warstwa | Czym | Kiedy działa | Licencja |
|---|---|---|---|
| Dopasowanie śladu do dróg + wskazówki PO POLSKU | [Valhalla](https://github.com/valhalla/valhalla), akcja `trace_route` | **raz, przy budowie kursu, na serwerze** | MIT |
| Geometria w czasie jazdy (rzut na trasę, dystans, azymut) | [turf.js](https://github.com/Turfjs/turf) — `nearest-point-on-line`, `distance`, `bearing` | na telefonie, offline | MIT |
| Mapa i kafle | to, co już mamy (Leaflet/MapLibre + `sw-app.js`) | offline z cache'u | — |
| Głos | [`@capacitor-community/text-to-speech`](https://github.com/capacitor-community/text-to-speech) 8.0.2 | offline (silnik TTS systemu) | MIT |
| Ekran nie gaśnie | `@capacitor-community/keep-awake` | — | MIT |

### Dlaczego akurat Valhalla i co dokładnie z niej bierzemy

`trace_route` przyjmuje listę punktów z GPX-a i oddaje ślad **przyklejony do
dróg** razem z narracją. Sprawdzone w dokumentacji API, nie z pamięci:

- parametr `language` przyjmuje BCP 47 i **`pl-PL` jest na liście** — dostajemy
  wskazówki po polsku, gotowe, bez pisania własnej gramatyki instrukcji;
- manewr niesie `instruction` („Skręć w prawo w ulicę Leśną"), `street_names`,
  `length`, `type`, `bearing_before`/`bearing_after` oraz
  **`begin_shape_index`/`end_shape_index`** — czyli kotwicę w geometrii, dzięki
  której każdy manewr przypina się do konkretnego miejsca NASZEJ linii;
- costing `bicycle` z `bicycle_type`, `use_hills`, `avoid_bad_surfaces` — to
  jest silnik rowerowy, nie samochodowy z doklejonym trybem.

**Gdzie ją uruchomić — i tu jest realne ograniczenie.** Produkcja stoi na
współdzielonym hostingu Namecheap (patrz historia importu z Garmina), więc
**własnej Valhalli tam nie postawimy**: budowa kafli routingu to godziny CPU
i setki gigabajtów. Dwie drogi:

1. **Publiczna instancja FOSSGIS** (`valhalla1.openstreetmap.de`) na czas
   budowy kursu. Limit to 1 żądanie/sek i zasada fair use, a my pytamy **raz na
   trasę/edycję**, nie raz na przejazd i nie raz na użytkownika — to są
   dziesiątki żądań, nie tysiące. Warunek regulaminu, o którym nie wolno
   zapomnieć: aplikacja publiczna ma się przedstawiać nagłówkiem
   `X-Client-Id` i zgłosić się na ich GitHubie.
2. **Własna Valhalla na osobnym VPS-ie**, gdy skala urośnie albo gdy nie
   będziemy chcieli zależeć od cudzego serwera. Ekstrakt Polski, nie planeta.

Decyzja o tym, którą drogą pójść, nie blokuje niczego innego: kurs jest plikiem,
więc źródło manewrów da się podmienić bez ruszania telefonu.

### Kiedy dopasowanie zawiedzie — i co wtedy

Trasa rowerowa potrafi biec tam, gdzie OSM nie ma drogi (singletrack, leśna
dukta, przejazd przez pole). Wtedy `trace_route` albo odmówi, albo przyklei
ślad do najbliższej drogi kilkadziesiąt metrów obok — a wskazówka „skręć
w ulicę X", gdy jedziesz przez las, jest gorsza niż jej brak.

Dlatego generator **sprawdza wynik, zamiast mu wierzyć**: porównuje długość
dopasowanego śladu z oryginałem i odległość punktów od dopasowania. Odcinki,
które nie przeszły progu, dostają wskazówki **policzone z samej geometrii**
(uproszczenie Douglas–Peucker + zmiana azymutu na wierzchołkach → „ostry zakręt
w prawo", bez nazwy). Kurs niesie więc manewry dwóch rodzajów, a ekran mówi to
wprost: nazwa ulicy albo sam kierunek. Nigdy zmyślona nazwa.

### Co robi silnik na telefonie (to jest cała nawigacja)

Pętla co pozycję z GPS-u, w całości nasza:

1. **rzut na trasę** — `nearestPointOnLine` na OKNIE punktów wokół bieżącego
   indeksu, nie na całej linii (setki kilometrów śladu razy kilka odczytów na
   sekundę to policzalny koszt baterii; biblioteka ma zresztą udokumentowane
   problemy z dokładnością na bardzo długich liniach);
2. **postęp** — który manewr jest następny (z `begin_shape_index`) i ile do
   niego zostało;
3. **zapowiedzi** na progach dystansu (np. 300 m / 100 m / teraz) — ekran
   i głos;
4. **zjechanie z trasy** — odległość od rzutu ponad próg przez kilka odczytów
   z rzędu (jeden odczyt to szum GPS-u, nie zjazd), potem kierunek i dystans
   powrotu do najbliższego punktu;
5. **to, czego Garmin nie ma** — świeżo odkryte pole, skarb w pobliżu, licznik
   punktów (§5, §6).

**Zasada, której nie wolno złamać** (w tym projekcie łamano ją już kilka razy
i za każdym razem wychodziło to dopiero w terenie): ekran mówi tyle, ile kurs
naprawdę wie. Odcinek bez nazwy ulicy pokazuje strzałkę i dystans, a nie
wymyśloną nazwę.

---

## 5. Skarby i pola na żywo

- **Pola**: `axialKey(lat, lon, 468.75)` w JS daje klucz pola; kurs wiezie zbiór
  kluczy JUŻ odkrytych. Nowe pole = klucz spoza zbioru. Rysowanie: ta sama mgła
  co na mapie odkryć, tylko zasilana lokalnie. Serwer i tak przeliczy wszystko
  przy wysyłce śladu — ekran w trakcie jazdy jest podglądem, nie księgowością.
- **Skarby**: wiezione w kursie z tą samą precyzją, jaką dziś dostają alerty
  (`Treasure::nearbyForAlerts` — dla skarbów jeszcze nieodkrytych środek pola,
  nie dokładny punkt; to świadomy wyjątek zatwierdzony 2026-08-28 i tu go NIE
  zmieniamy). Zaliczenie w terenie działa jak dziś: przycisk „Jestem tutaj"
  albo automat po wgraniu śladu.

---

## 6. Punkty „na bieżąco" — muszą być podpisane jako szacunek

Punkty przyznaje serwer przy wysyłce śladu (`RiderActivity::recordSolo`,
`Treasure::claimAlongTrack`, progi znanych tras). Apka bez sieci nie ma jak
tego rozstrzygnąć — nie zna cudzych zaliczeń ani stanu progów.

Rozwiązanie: kurs wiezie **stawki** (za km, za nowe pole, za skarb, progi
trasy), apka mnoży je lokalnie i pokazuje wynik **podpisany jako szacunek**,
a po zakończeniu jazdy prawdę pokazuje istniejący ekran wyniku
(`ride-summary-app.php`), który liczy się z serwerem. Rozjazd między szacunkiem
a wynikiem jest wtedy zrozumiały, bo ekran wyniku pokazuje rozbicie.

---

## 7. Kafle offline — rachunek, nie życzenie

Kafel przy 52°N ma bok ok. 40075·cos(52°)/2^z km. Dla trasy 100 km i korytarza
±1 km:

| zoom | bok kafla | kafli wzdłuż | z korytarzem | rozmiar (~15 kB/kafel) |
|---|---|---|---|---|
| 13 | ~3,0 km | ~33 | ~100 | ~1,5 MB |
| 14 | ~1,5 km | ~67 | ~200 | ~3 MB |
| 15 | ~0,75 km | ~133 | ~400 | ~6 MB |
| 16 | ~0,38 km | ~266 | ~800 | ~12 MB |

Czyli z13–z15 dla stukilometrowej trasy to rząd **10 MB** — akceptowalne.
Trzy rzeczy do rozstrzygnięcia przy implementacji:

1. **Osobny cache, poza LRU.** Dzisiejszy `C_TILES` ma limit 600 kafli i wycina
   najstarsze — kurs pobrany wieczorem wyparowałby przed wyjazdem. Kurs
   potrzebuje własnego, kasowanego dopiero razem z kursem.
2. **Pobieranie jest jawną akcją usera** („Przygotuj trasę offline"), z paskiem
   postępu i ostrzeżeniem o rozmiarze — nigdy samo z siebie po komórce.
3. Nasze własne kafle (mgła, szlaki) mają klucze zależne od widza i unieważniają
   się epoką w adresie — trzeba zdecydować, czy jadą do paczki, czy korytarz
   wiezie tylko podkład. **Rekomendacja: tylko podkład**; pola rysujemy z kursu,
   więc mgła offline nie jest do niczego potrzebna.

---

## 8. Etapy

Znaczniki jak w `apka-przeglad-2026-09.md`: **[WEB]** wchodzi do zainstalowanej
apki od razu, **[APK]** wymaga przebudowy i wgrania binarki.

### Etap 0 — telefon  [APK]
Warunek wstępny całej reszty. Nic z apki nigdy nie chodziło na urządzeniu.
Nawigacja to najbardziej „terenowa" funkcja w serwisie — pisanie jej na ślepo
byłoby powtórzeniem tego samego błędu, tylko drożej.

### Etap 1 — generator kursu  [WEB]
`Models\Course::build($zrodlo)` — jedno wejście dla obu źródeł (`edition_tracks`
wydarzenia i `known_routes`; **bez bramki zapisu**, decyzja 5). Wynik: JSON
z §3, zapisany jako plik obok GPX-a i unieważniany epoką, tak jak kafle tras.

Manewry składa `Utils\Cue` z DWÓCH źródeł, w tej kolejności:
1. `Utils\MapMatch` — żądanie `trace_route` do Valhalli z `language=pl-PL`
   i costingiem `bicycle`, plus **kontrola jakości dopasowania** (§4);
2. własne liczenie z geometrii (Douglas–Peucker + zmiana azymutu) dla
   odcinków, które kontroli nie przeszły, i jako pełne zejście awaryjne, gdy
   serwer routingu milczy — **kurs MUSI dać się zbudować bez sieci zewnętrznej**,
   inaczej cudza awaria zatrzymuje nasz serwis.

Testy: manewry na zadanej geometrii (bez sieci — atrapa odpowiedzi), próg
odrzucenia złego dopasowania, korytarz pól, przycięcie skarbów, budowa kursu
przy niedostępnym routerze.

### Etap 2 — ekran nawigacji, ONLINE  [WEB]
`/nawigacja/{kurs}` — nowa strona **tylko w apce** (wzorzec `discovery-app.php`:
osobny plik widoku, ten sam kontroler). Pełny ekran: mapa ze śladem, strzałka
i dystans do zakrętu, pasek statystyk (km, pola, szacunek punktów, skarby),
duży przycisk „Zakończ". Nagrywanie startuje i kończy się istniejącym
`app-tracking.js`, wynik pokazuje istniejący ekran podsumowania.
Wejście: przycisk „Uruchom nawigację" na stronie wydarzenia (dla zapisanych)
i na stronie znanej trasy.

### Etap 3 — offline  [WEB + APK]
Cookie jako drugi sygnał trybu apki (§2), lista dokumentów w `sw-app.js`,
pobieranie kursu do `@capacitor/preferences`, paczka kafli korytarza (§7).
**To jest etap, w którym ta funkcja staje się prawdziwa** — nawigacja działająca
tylko w zasięgu nie jest nawigacją rowerową.

### Etap 4 — ekran nie gaśnie  [APK]
`@capacitor-community/keep-awake` na czas nawigacji. Licznik rowerowy, który
gaśnie po 30 sekundach, nie jest licznikiem. Plus jawna informacja o zużyciu
baterii — GPS w trybie „move" kosztuje jak nawigacja i nie da się zejść niżej
(zmierzone przy Etapie 1 przebudowy apki).

### Etap 5 — dopracowanie terenowe  [WEB]
Alarm zjechania z trasy z wibracją, automatyczne przewijanie mapy w kierunku
jazdy, tryb dzienny/nocny, przełącznik „mapa / liczby".

### Etap 6 — głos  [APK]
`@capacitor-community/text-to-speech` 8.0.2 (Capacitor 8, aktywnie utrzymywany)
czyta gotowe polskie zdania z kursu — silnik TTS jest w telefonie, więc działa
bez sieci. Progi zapowiedzi te same, co dla ekranu.

**Nazwy ulic NIE są już osobnym etapem ani osobną decyzją** — przychodzą
z kursu (§4), bo dopasowanie do dróg dzieje się przy jego budowie, a nie
w czasie jazdy. Pierwsza wersja tego dokumentu odsyłała tu po nie do OsmAnda;
to była delegacja, którą user odrzucił 2026-09-10.

---

## 9. Poza zakresem (świadomie)

- **Planowanie trasy w apce** (wyznacz mi drogę z A do B) — to router pytany
  na żywo, czyli inny projekt niż prowadzenie po gotowym kursie. Nawigacja
  z tego kontraktu prowadzi po ŚLADZIE, który ktoś wcześniej ułożył.
- **Przeliczenie trasy po zjechaniu** („rerouting") — z tego samego powodu:
  wymagałoby routera dostępnego w terenie, offline. Zamiast tego prowadzimy
  z powrotem do najbliższego punktu kursu (§4).
- **Własna instancja Valhalli na produkcji** — Namecheap tego nie uciągnie;
  to decyzja o infrastrukturze, nie o tym module (§4).
- iOS — kod pisze się równolegle, ale bez Maca nic się nie zbuduje.

Do 2026-09-10 stała tu jeszcze „nawigacja dla niezapisanych na wydarzenie" —
decyzja 5 zdjęła to ograniczenie.

---

## 10. Decyzje — PODJĘTE 2026-09-10

1. **Format kursu: własny JSON + osobny czysty GPX.** Zgodnie z rekomendacją §3.
2. **Tryb apki: cookie jako drugi sygnał** (`APP_IS_APP = UA ∨ cookie`),
   czyli furtka z §2 zostaje otwarta świadomie. Test „żaden dokument nie
   przechodzi przez fetch workera" trzeba wtedy przepisać z ZAKAZU na LISTĘ
   dozwolonych stron — to lista, a nie zakaz, będzie realnym zabezpieczeniem.
3. **Wskazówki: prowadzenie po śladzie** (strzałka + dystans + alarm zjechania).
   **POPRAWIONE tego samego dnia, po zastrzeżeniu usera** („czuję, że
   zdelegowałeś to; chciałem, żebyś zbudował wewnętrzną własną nawigację
   bazując na rozwiązaniach z GitHuba"): nazwy ulic i głos NIE są odłożone
   i nic nie jest oddawane cudzej aplikacji. Manewry po polsku, z nazwami,
   liczy Valhalla **przy budowie kursu na serwerze**, a w terenie odtwarza je
   nasz własny silnik, offline (§4). Router w czasie jazdy nie jest potrzebny —
   Garmin też go wtedy nie ma.
4. **Kafle offline: sam podkład**, pola rysujemy z kursu.
5. **Kto może odpalić: KAŻDY, kto otworzy trasę lub wydarzenie** — user wybrał
   szerzej niż rekomendacja i niż własny pierwotny opis („zapisuję się na
   wydarzenie"). Skutek dla implementacji: **nawigacja nie ma bramki zapisu**,
   więc nie wolno wiązać kursu z RSVP ani chować przycisku przed niezapisanymi.
   Kurs generuje się dla wydarzenia i dla znanej trasy tak samo.
6. **Tanie wyjście robimy po drodze** — Etap 2 planu `apka-przeglad-2026-09.md`
   (pobieranie + arkusz „wyślij do nawigacji") ZROBIONY 2026-09-10, przed
   pierwszą linią tego modułu.

Do rozstrzygnięcia zostaje tylko to, co wymaga telefonu (Etap 0).

## 11. Pierwotne pytania (kontekst decyzji wyżej)

1. **Format kursu**: własny JSON (rekomendacja) czy GPX z rozszerzeniami?
2. **Tryb apki**: dokładamy cookie jako drugi sygnał (rekomendacja), czy wozimy
   ekran nawigacji w `app/www/`?
3. **Zakres wskazówek**: prowadzenie po śladzie ze strzałką i dystansem
   (rekomendacja), czy od razu celujemy w głos?
4. **Kafle offline**: sam podkład (rekomendacja) czy też nasze warstwy?
5. **Kto może odpalić nawigację**: tylko zapisany na wydarzenie (Twój opis),
   czy każdy, kto otworzy trasę — łącznie ze znanymi trasami?
6. **Kolejność wobec `apka-przeglad-2026-09.md`**: ten moduł jest duży. Etap 2
   tamtego planu (GPX do zewnętrznej nawigacji) daje działającą nawigację
   „od jutra" kosztem kilkunastu linii — czy robimy go po drodze, czy idziemy
   od razu we własny moduł?
