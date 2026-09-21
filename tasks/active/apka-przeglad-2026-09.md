# Przegląd apki mobilnej po wrześniowych zmianach w web — plan prac

Zgłoszenie usera 2026-09-10: „w ostatnim czasie wprowadziliśmy wiele zmian
związanych z aplikacją webową. Nie dotykałem w ogóle aplikacji na Android.
Przeanalizuj stan obecny i zobacz co należy poprawić."

Wskazane wprost dwie rzeczy: (1) wczytanie GPX z wydarzenia / znanej trasy
i odpalenie nawigacji, gotowym rozwiązaniem; (2) centrowanie mapy na pozycji
GPS po dotknięciu, a gdy sygnału nie ma — pokazanie, że apka czeka.

Ten dokument to PLAN, nie kontrakt gotowy do wykonania w całości. Każdy etap
ma własne kryteria akceptacji, ale kolejność i zakres wymagają decyzji usera
(patrz „Decyzje do podjęcia" na końcu).

---

## Metoda — co zostało zweryfikowane, a co jest założeniem

Wszystko poniżej pochodzi z kodu tego repozytorium i ze źródeł Capacitora
w `app/node_modules/@capacitor/android/`, przeczytanych 2026-09-10. Nic nie
pochodzi z pamięci o tym, „jak zwykle działa WebView".

Sprawdzone:

- `git diff HEAD` od commita `9d032f7` (2026-09-04) — **żaden plik apki nie
  zmienił się od tamtej pory**: ani `app/`, ani `assets/js/native.js`,
  ani `app-tracking.js`, ani `sw-app.js`, ani `partials/app-nav.php`,
  ani `discovery-app.php`, ani `assets/css/app.css`.
- Zestaw opcji przekazywanych do `ridemoreDiscoveryMap()` w `discovery.php`
  (web) i `discovery-app.php` (apka) — porównany klucz po kluczu.
- `Bridge.launchIntent()` i `BridgeWebViewClient.shouldOverrideUrlLoading()`
  w źródłach Capacitora 8.5 — rozstrzygają, co apka robi z linkiem
  wychodzącym i z `target="_blank"`.
- Brak `DownloadListener` w CAŁYM `@capacitor/android` (grep po pakiecie).
- `php tests/run.php` → **463 przeszło, 3 nie przeszło**.

Nie sprawdzone (i nie da się tu):

- Cokolwiek na fizycznym telefonie. Ten stan trwa od początku modułu
  (2026-08-22) i jest dziś największym pojedynczym ryzykiem projektu.
- Stan migracji na produkcji.

---

## Stan zastany

### 1. Mapa apki NIE rozjechała się z web — to dobra wiadomość

Wrzesień dołożył do `discovery.php` 218 linii (hex-statystyki, emblematy
regionów, zdjęcie celu), ale to wszystko są elementy otoczki, których apka
świadomie nie ma. Silnik dostaje w obu widokach ten sam zestaw opcji poza
trzema, których brak jest zamierzony:

| opcja | web | apka | dlaczego |
|---|---|---|---|
| `emptyEl`, `legendEl`, `onTreasures` | tak | nie | to zaczepy panelu obok mapy, którego apka nie renderuje |

Wszystkie opcje FUNKCJONALNE — `sources`, `filters`, `trailsHitEndpoint`,
`ridesHitEndpoint`, `layers` — apka ma. Poprawki z 2026-09-10 (znikający
dymek skarbu, `rider=` przy kliknięciu w cudzy ślad) siedzą w
`discovery-map.js`, czyli we wspólnym silniku — apka dostaje je za darmo.

**Wniosek: nie ma tu długu do spłacenia.** Zasada „ten sam kontroler, inny
widok" (wzorzec `discovery-app.php`) wytrzymała próbę.

### 2. Realne luki — ponumerowane, każda potwierdzona w kodzie

**L1. „Pobierz GPX" w apce nie działa.** Cztery miejsca w serwisie mają link
`<a href="…gpx" download>`: `trail.php:120`, `trail.php:143`,
`event-page.php:646`, `ride.php:212`. W Capacitorze `Bridge.launchIntent()`
przepuszcza adres z tego samego hosta z powrotem do WebView (`return false`),
a WebView nie potrafi obsłużyć pobierania, bo `DownloadListener` nie jest
nigdzie ustawiony — ani przez Capacitora, ani przez nasze `MainActivity.java`
(które jest puste: `public class MainActivity extends BridgeActivity {}`).
Efekt zależy od typu MIME, jakim Apache poda plik: albo dotknięcie nie robi
NIC, albo apka zostaje z surowym XML-em w miejscu strony. Ani jedno, ani
drugie nie jest pobraniem pliku.

**L2. Nawigacja do punktu istnieje, ale w jednym miejscu i w złej formie.**
`event-page.php:802` ma przycisk „Nawiguj →" na
`https://www.google.com/maps?q=lat,lon`. Ten link Z APKI ZADZIAŁA (host
inny niż `server.url` → `launchIntent` odpala `ACTION_VIEW` → otwiera się
Mapy Google; `target="_blank"` nie przeszkadza, bo Capacitor nie włącza
`setSupportMultipleWindows`, więc link ładuje się w tym samym oknie i trafia
w `shouldOverrideUrlLoading`). Ale:
- `?q=` stawia PINEZKĘ, nie uruchamia nawigacji, i nie zna trybu rowerowego;
- nie ma go na `trail.php` (start znanej trasy), na `ride.php`, ani przy
  skarbie na mapie — a to są dokładnie te miejsca, w których człowiek stoi
  z telefonem i chce dojechać.

**L3. „Wyśrodkuj na mnie" jest schowane w pasku i milczy przy braku sygnału.**
Mechanizm istnieje: gdy jesteś na `/odkrycia`, środkowy slot paska zmienia się
w przycisk `data-rm-map-here` (`app-nav.php:81`), a obsługa siedzi w
`discovery-app.php:199`. Trzy problemy:
- to jedyna droga; na mapie NIE MA kontrolki „tu jestem" — user szuka jej
  tam, gdzie jest w każdej innej aplikacji z mapą, czyli przy mapie;
- `.catch(function () {})` — **cisza przy braku pozycji**. Timeout 8 s mija
  i nie dzieje się nic. Dokładnie to zgłasza user;
- nie ma tego na żadnej innej mapie w apce (`treasure-propose-app.php`,
  mapy na stronie wydarzenia, `trail.php`, `ride.php`).

**L4. Ekrany dodane w web po 2026-08-31 nigdy nie były oglądane w trybie apki.**
Od ostatniego audytu UX apki powstały albo zostały przebudowane:
`/przejazd/{id}` (`ride.php`), strona znanej trasy (`trail.php` — przebudowa
okładki 2026-09-10), strona główna („trasa dnia", 2026-09-05), zakładka
źródeł przejazdów z Garminem (`ride-sources.php`, `source-garmin.php`),
`my-rides.php`. Żaden z nich nie ma ani jednej gałęzi `APP_IS_APP` i żaden
nie był renderowany z UA `ridemore-app`. Ryzyko jest znane z poprzednich faz:
rezerwa miejsca pod pływającym `.app-nav`, poziome przewijanie, cele dotykowe.

**L5. Zimny start bez zasięgu nadal kończy się ekranem błędu WebView.**
To otwarty Etap 3 kontraktu `apka-offline.md` — świadomie zostawiony do czasu
sprawdzenia Etapów 1-2 na telefonie.

**L6. Nic z całego modułu nie zostało uruchomione na telefonie.** Etapy 0-9
kontraktu `apka-mobilna.md`, Fazy 0-5 kontraktu `apka-mobilna-ux.md`, ekran
wyniku jazdy, nagrywanie w tle, alerty o skarbach, aparat, push, kolejka
offline — wszystko jest „kod gotowy, urządzenie niesprawdzone". Ostatni APK
zbudowano 2026-08-30, czyli przed jedenastoma dniami zmian w serwisie.

**L7. Push nie wyśle się naprawdę** — brak pliku konta serwisowego Firebase.
To pozycja po stronie usera, nie kodu.

### 3. Poza zakresem apki, ale znalezione przy okazji

- `php tests/run.php` → 3 czerwone, wszystkie na danych dev, nie na kodzie:
  „DIAGNOSTYKA: każda AKTYWNA znana trasa (klucz `kr`) narysuje się",
  „wspólna warstwa Ślady niesie solo — PRZYCIĘTE", „§27: skarb NA MECIE
  przejazdu zalicza się mimo przycięcia". Wszystkie trzy to brak plików GPX na
  dysku maszyny deweloperskiej — **user potwierdził 2026-09-10, że skasował
  ślady sam**. Nie ma tu czego naprawiać w kodzie; testy są czerwone słusznie,
  bo pilnują niezmiennika „aktywna trasa MA plik".
- Zgłoszony wcześniej warning „Undefined variable `$viewerId`"
  w `EventController` **już nie występuje** — zmienna jest definiowana
  w linii 629, przed użyciem w 740.

---

## Oś planowania, którą warto mieć z tyłu głowy

Apka ładuje żywy serwis (`server.url`), więc prace dzielą się na dwie klasy
o zupełnie różnym koszcie wdrożenia:

- **[WEB]** — zmiana w PHP/JS/CSS serwisu. Wchodzi do zainstalowanej apki
  natychmiast po wgraniu na produkcję, bez nowego APK i bez sklepu.
- **[APK]** — zmiana w `app/` (konfiguracja, wtyczki, kod natywny). Wymaga
  `npx cap sync` + `gradlew assembleDebug`, wgrania na telefon, a docelowo
  przejścia przez sklep.

Dlatego kolejność niżej celowo wypycha [APK] jak najdalej: im więcej da się
zrobić po stronie web, tym mniej razy trzeba wypuszczać binarkę.

---

## Etap 1 — GPS na mapie: kontrolka „tu jestem" ze stanem oczekiwania  [WEB] — ZROBIONY 2026-09-10

**ZROBIONE 2026-09-10** (user: „ok to trzeba wprowadzić jako pierwsze").
Wykonanie i odchylenia od poniższego szkicu — na końcu etapu.

Odpowiada wprost na drugą prośbę usera.

### Co zrobić

1. **Kontrolka na mapie, nie tylko w pasku.** Nowa opcja silnika
   `locate: true` w `assets/js/discovery-map.js` — okrągły przycisk
   w rogu mapy (obok istniejącej kontrolki warstw), Leafletowy `L.Control`,
   ta sama sylwetka co w każdej mapie, jaką user zna z telefonu.
   Silnik, nie widok — bo zgodnie z zasadą „jedna standardowa mapa" ma to
   potem trafić na WSZYSTKIE mapy apki jednym przełącznikiem.

2. **Cztery stany zamiast dwóch.** Dziś jest „działa" i „cisza". Ma być:
   - *spoczynek* — przycisk normalny;
   - *szukam* — przycisk pulsuje, obok krótki komunikat „Szukam sygnału
     GPS…" (a nie spinner bez słowa; user ma wiedzieć, że apka czeka, a nie
     że się zawiesiła);
   - *mam* — mapa jedzie na pozycję, komunikat znika;
   - *nie mam* — „Nie widzę sygnału GPS. Spróbuj wyjść na otwartą
     przestrzeń." + możliwość ponowienia. Osobno rozpoznana ODMOWA ZGODY
     („Włącz dostęp do lokalizacji w ustawieniach") — `RM.native.position`
     już dziś odróżnia te dwa przypadki i układa treść komunikatu, tylko
     nikt jej nie pokazuje.

3. **Podwyższony timeout dla świadomego dotknięcia.** Dziś przycisk paska
   pyta z `timeout: 8000, highAccuracy: false` — dobre dla automatycznego
   kadru na wejściu, za krótkie dla „naciskam, bo CHCĘ swojej pozycji".
   Świadome dotknięcie ma czekać dłużej (rząd 30 s, jak domyślka mostu)
   i pokazywać, że czeka.

4. **Pasek i kontrolka to ta sama droga.** `data-rm-map-here` przestaje mieć
   własną kopię logiki — woła tę samą funkcję co przycisk na mapie.

5. **Nowa ikona `locate` w `Utils\Icon`** (celownik). W palecie jej nie ma;
   emoji odpada — reguła „`Utils\Icon`, nie emoji" obowiązuje od migr. 057.

6. **Rozszerzenie na pozostałe mapy apki** — `treasure-propose-app.php`
   (tam „gdzie jestem" jest jeszcze ważniejsze), mapy na stronie wydarzenia
   i znanej trasy w trybie apki.

### Pułapki

- **Nie dotykać `userRuszylMapa`.** Zasada „automat nie ma prawa wyrywać
  mapy z ręki, ale świadome dotknięcie ma prawo ją oddać" jest już wpisana
  w `discovery-app.php` i jest słuszna. Kontrolka to właśnie to świadome
  dotknięcie — kasuje flagę, automat nadal nie.
- **Jeden klient GPS, nie dwa.** `discovery-app.php` ma starannie zbudowaną
  logikę „gdy nagrywanie trzyma odbiornik, nie otwieraj drugiego watcha"
  (po zgłoszeniu „z pełnej baterii zrobiło się 0 po 40 minutach").
  Kontrolka MUSI korzystać z tej samej ścieżki, nie zakładać własnego
  `watchPosition`.
- Kontrolka w rogu nie może wejść pod pływający `.app-nav` ani pod
  `env(safe-area-inset-bottom)`.

### Kryteria akceptacji

1. Na mapie w apce jest widoczny przycisk lokalizacji; dotknięcie centruje.
2. Przy braku pozycji w ciągu ~2 s pojawia się stan „szukam" z tekstem.
3. Po nieudanym szukaniu jest komunikat i da się ponowić; odmowa zgody ma
   INNY komunikat niż brak sygnału.
4. Przycisk paska i przycisk mapy robią dokładnie to samo.
5. Podczas nagrywania przejazdu kontrolka nie otwiera drugiego strumienia GPS.
6. Web bez zmian — kontrolka wychodzi tylko pod `APP_IS_APP`.
7. `php tests/run.php` bez NOWYCH czerwonych.

### WYKONANIE 2026-09-10

Zmienione pliki: `assets/js/gpx-map.js` (nowa `ridemoreAddLocateControl`, wpięta
w `ridemoreCreateMap`), `assets/js/native.js` (gałąź natywna przepuszczona przez
`positionError`), `assets/css/style.css` (`.map-loc`), `assets/css/app.css`
(kontrolka zostaje w apce + podniesienie dolnego rzędu ponad `.app-nav`
i ponad uchwyt szuflady), `views/web/pages/discovery-app.php` (slot paska
deleguje zamiast mieć kopię), `tests/widoki_test.php` (5 nowych testów +
przepisany stary), `md/views-and-frontend.md`.

**Odchylenia od szkicu wyżej, świadome:**

1. **Kontrolka trafiła do `gpx-map.js`, nie do `discovery-map.js`** — bo to
   `ridemoreCreateMap` jest fabryką, przez którą przechodzi KAŻDA mapa
   w serwisie, i to tam siedzi już bliźniaczy przycisk pełnego ekranu.
   Skutek: kontrolkę mają wszystkie mapy od razu, łącznie z ekranem zgłoszenia
   skarbu i pickerem w kreatorze — punkt 6 szkicu („tylko pod `APP_IS_APP`")
   został więc świadomie odrzucony. Osobna kontrolka tylko dla apki byłaby
   trzecim sposobem robienia tego samego, a w przeglądarce na telefonie
   „tu jestem" jest potrzebne dokładnie tak samo.
2. **Bez nowej ikony w `Utils\Icon`** — ikona jest wpisana w JS obok kontrolki,
   tak jak ikona pełnego ekranu. Kontrolki Leafletu nie przechodzą przez PHP,
   więc wpis w palecie nie miałby kto przeczytać.
3. **Przy okazji naprawione, bo bez tego nowy komunikat kłamałby**: gałąź
   natywna `RM.native.position` NIGDY nie przechodziła przez `positionError`,
   więc apka pokazywała surowe angielskie komunikaty wtyczki (zgłoszenie
   z terenu 2026-08-29: „Could not obtain location in time. Try a higher
   timeout"). Doszło też rozpoznawanie po TREŚCI błędu — wtyczka natywna nie
   używa kodów W3C. Efekt uboczny do wiadomości: komunikat o braku zgody
   stracił końcówkę „żeby zaliczyć skarb" (był używany w ośmiu miejscach,
   z których siedem nie dotyczy skarbów).

**Kryteria:** 1–6 spełnione i sprawdzone na żywo w przeglądarce (stan „szukam"
z pulsującym przyciskiem, wycentrowanie po odczycie, czerwona pastylka
z właściwym komunikatem po odrzuceniu obietnicy pozycji, znikanie po 6 s).
7: `php tests/run.php` → **468/471**; trzy czerwone to znane braki plików GPX
na dysku dev (user potwierdził 2026-09-10, że sam je skasował).
**NIESPRAWDZONE:** wygląd przy prawdziwej szerokości telefonu i zachowanie przy
prawdziwym zimnym fiksie — jak reszta modułu, wymaga urządzenia.

---

## Etap 2 — GPX i nawigacja — ZROBIONY 2026-09-10

Odpowiada na pierwszą prośbę usera. Dzieli się na cztery kawałki o rosnącym
koszcie; **2a i 2b dają 80% wartości i nie wymagają żadnej nowej wtyczki**.

> **ROZSTRZYGNIĘCIE USERA 2026-09-10.** „Nawiguj" w sensie licznika Garmina —
> własny moduł nawigacji WEWNĄTRZ apki, z pokazywaniem odkrywanych pól, licznikiem
> punktów i skarbami, prowadzony z przygotowanego wcześniej pliku kursu wczytanego
> offline — ma osobny kontrakt: `tasks/nawigacja-w-apce-ODLOZONE.md` (odłożony 2026-09-11).
> Etap 2 poniżej zostaje w mocy jako to, czym jest naprawdę: **dowiezienie
> człowieka do punktu i wypuszczenie pliku na zewnątrz**, nie prowadzenie po trasie.
> Przycisk na stronie wydarzenia nazywa się od tej decyzji
> „Nawiguj do miejsca zbiórki" (zrobione 2026-09-10) — słowo „Nawiguj" samo
> należy teraz do modułu z tamtego kontraktu.

### 2a. „Nawiguj do miejsca zbiórki" wszędzie tam, gdzie jest punkt  [WEB]

**ZROBIONE 2026-09-10** — patrz WYKONANIE na końcu Etapu 2.

Najtańsze i najbardziej praktyczne. Adres w formie
`https://www.google.com/maps/dir/?api=1&destination=<lat>,<lon>&travelmode=bicycling`
— w odróżnieniu od dzisiejszego `?q=` to jest URUCHOMIENIE NAWIGACJI,
w trybie rowerowym. Capacitor odpala to intentem (zweryfikowane w źródłach),
a na telefonie bez Map Google system pokaże wybór z tego, co jest.

Gdzie dołożyć:
- `trail.php` — start znanej trasy (pierwszy punkt geometrii);
- `ride.php` — start przejazdu;
- dymek skarbu na mapie — „Dojedź tu" (uwaga: TYLKO dla skarbów
  o pełnej precyzji; dla nieodkrytych obowiązuje ta sama zasada co przy
  alertach — środek pola, nie punkt);
- `event-page.php` — **ZROBIONE 2026-09-10**: przycisk nazywa się teraz
  „Nawiguj do miejsca zbiórki" i prowadzi przez
  `maps/dir/?api=1&destination=…&travelmode=bicycling`, czyli URUCHAMIA
  prowadzenie w trybie rowerowym, zamiast dawnego `maps?q=` (samej pinezki).

Wariant do rozważenia zamiast twardego wpisania Google: schemat
`geo:<lat>,<lon>?q=<lat>,<lon>(<nazwa>)`, który Android oddaje DOWOLNEJ
zainstalowanej nawigacji (OsmAnd, Organic Maps, Waze, Mapy Google).
Bardziej uczciwy wobec usera, ale w przeglądarce nie zadziała — czyli
byłby to link istniejący tylko pod `APP_IS_APP`.

### 2b. Naprawa pobierania plików w apce  [APK]

**ZROBIONE 2026-09-10.**

Dziesięć linii w `app/android/app/src/main/java/bike/ridemore/app/MainActivity.java`:
`webView.setDownloadListener(...)` przekazujący adres do systemowego
`DownloadManager` (z ciasteczkiem sesji w nagłówku, bo pliki GPX wydarzeń
mogą wymagać zalogowania). Naprawia OD RAZU wszystkie cztery istniejące
linki „Pobierz GPX" i każdy przyszły plik do pobrania. Plik ląduje
w Pobranych, z powiadomieniem systemu, z którego user otwiera go swoją
nawigacją.

To jest „gotowe rozwiązanie, które będzie działać" w najczystszej postaci:
mechanizm systemu Android, nie nasz.

### 2c. „Wyślij trasę do nawigacji" — arkusz udostępniania  [APK]

**ZROBIONE 2026-09-10.**

Wygodniejsza droga niż 2b: zamiast wkładać plik do Pobranych, podać go
systemowi do wyboru aplikacji. OsmAnd, Locus Map, Komoot, Garmin Connect,
Ride with GPS — wszystkie przyjmują GPX tą drogą.

Wymaga dwóch oficjalnych wtyczek: `@capacitor/filesystem` (zapis pliku do
katalogu podręcznego) i `@capacitor/share` (arkusz z `files:`).
Nowa metoda mostu `RM.native.shareFile(url, nazwa)` w `assets/js/native.js`,
z zejściem do zwykłego pobrania (2b), gdy wtyczki nie ma — zgodnie z zasadą
mostu „nigdy cicha porażka".

W apce „Pobierz GPX ↓" zmienia wtedy podpis na „Wyślij do nawigacji".

### 2d. Prowadzenie po trasie wewnątrz apki — PRZENIESIONE

Ta pozycja była w pierwszej wersji planu propozycją „do decyzji". User
zdecydował 2026-09-10, i to w zakresie SZERSZYM niż tu proponowany: nie sam
tryb śledzenia śladu, ale moduł nawigacji na wzór licznika Garmina — z żywym
odkrywaniem pól, licznikiem punktów i skarbami, prowadzony z pliku kursu
przygotowanego wcześniej i wczytanego offline.

Opis, rachunki i decyzje do podjęcia przeniosły się do osobnego kontraktu:
`tasks/nawigacja-w-apce-ODLOZONE.md` (odłożony 2026-09-11). Tu zostaje sam wskaźnik, żeby
nie było dwóch miejsc mówiących o tym samym.

### WYKONANIE 2026-09-10 (2a + 2b + 2c)

User wybrał „tanie wyjście po drodze", więc cały Etap 2 poza 2d poszedł od razu.

**Zmienione pliki:** `views/web/partials/nav-button.php` (NOWY),
`views/web/pages/event-page.php`, `views/web/pages/trail.php`,
`core/Controllers/TrailController.php` (`startPoint`),
`assets/js/native.js` (`canShareFile`/`shareFile`),
`assets/js/app-share.js` (NOWY), `views/web/partials/head.php`,
`app/android/app/src/main/java/bike/ridemore/app/MainActivity.java`,
`app/package.json` (+`@capacitor/filesystem` 8.1.3, `@capacitor/share` 8.0.1),
`tests/widoki_test.php`, `md/views-and-frontend.md`, `md/controllers.md`.

**Decyzje podjęte w trakcie, warte zapamiętania:**

1. **Przycisk to PARTIAL, nie czwarta kopia `<a href>`.** Adres nawigacji ma
   trzy części, które łatwo pomylić i które zmieniają się razem (tryb podróży,
   `dir/` kontra `?q=`, zachowanie w apce). Że to nie jest przesada, dowodzi
   sam punkt wyjścia: jedyna istniejąca kopia miała `?q=`, czyli stawiała
   pinezkę zamiast uruchamiać prowadzenie — i nikt tego nie zauważył.
2. **Strona przejazdu ŚWIADOMIE nie dostała przycisku**, choć była w szkicu
   tego planu. Start cudzego śladu solo to okolica czyjegoś domu — dokładnie
   to, co §27 przycina. Przycisk „nawiguj na start" cofnąłby tę ochronę
   jednym dotknięciem.
3. **Naprawa pobierania jest dwustronna i to nie jest nadmiarowość.**
   `DownloadListener` w `MainActivity` to fundament (działa dla KAŻDEGO pliku,
   też takiego, którego nikt nie przewidział), a arkusz „wyślij do…" to droga
   główna (plik ląduje wprost w OsmAndzie). `app-share.js` zmienia napis na
   „Wyślij do nawigacji" TYLKO wtedy, gdy most potwierdzi obie wtyczki —
   bo apka na telefonie może być starsza niż ten plik JS (`server.url`), a
   przycisk nie ma prawa obiecywać arkusza, gdy umie tylko pobrać.
4. **Przechwycenie kliknięcia jest delegacją na `document`**, nie gałęzią
   `if (APP_IS_APP)` w trzech szablonach — obejmuje też linki, które dopiero
   powstaną.

**Zweryfikowane:** `php tests/run.php` → **476/479** (8 nowych testów; trzy
czerwone to skasowane przez usera pliki GPX). `npx cap sync android` widzi
14 wtyczek zamiast 12, `gradlew assembleDebug` → **BUILD SUCCESSFUL**, APK
34 MB — czyli nowa Java się kompiluje. Żywy render: strona wydarzenia
i strona trasy oddają poprawne współrzędne w adresie `dir/`.

**NIESPRAWDZONE (wymaga telefonu):** czy `DownloadManager` faktycznie zapisuje
plik i czy arkusz udostępniania podaje GPX do nawigacji tak, że ta go przyjmie.
To jest kryterium nr 3 poniżej i nie da się go domknąć bez urządzenia.

### Kryteria akceptacji Etapu 2

1. Na stronie wydarzenia, znanej trasy i przejazdu jest w apce działający
   przycisk nawigacji do punktu startu; dotknięcie otwiera nawigację
   systemową w trybie rowerowym.
2. „Pobierz GPX" w apce kończy się plikiem na telefonie (2b) albo arkuszem
   wyboru aplikacji (2c) — nigdy ciszą.
3. Ten sam GPX daje się zaimportować w co najmniej jednej realnej
   nawigacji (sprawdzenie na telefonie, nie z dokumentacji).
4. Web bez zmian.

---

## Etap 3 — Audyt ekranów dodanych po 2026-08-31  [WEB] — ZROBIONY 2026-09-11

**ZROBIONY 2026-09-11** (user: „skupimy się na działaniu samej aplikacji").

### Metoda — pierwszy raz wiernie, nie na podstawkach

Poprzednie fazy oglądały apkę przez podstawki wstrzykiwane JS-em, bo
`resize_window` w tym środowisku nie zmieniał prawdziwego `window.innerWidth`,
a UA apki był niedostępny. **Tym razem oba ograniczenia zniknęły:**

1. `resize_window` daje realne `innerWidth: 375`, `dpr: 2`, mobilny UA;
2. skorupę apki pobrano **z serwera**, `curl -A "… ridemore-app"`, i otwarto
   zapisany HTML pod adresem obsługiwanym przez Apache. Adresy zasobów są
   w tym serwisie BEZWZGLĘDNE (decyzja o `View::url`), więc arkusze i skrypty
   ładują się normalnie. Sprawdzone: `.app-header`, `.app-nav`, brak stopki,
   `body.is-app`, `app.css` — prawdziwa skorupa, nie atrapa.

**Ograniczenie tej metody, wykryte i obejście:** zapisana strona leży pod inną
ścieżką, więc część żądań względnych zwraca 404/500 (service worker, niektóre
API map). Pusty prostokąt w miejscu mapy na stronie przejazdu to ARTEFAKT
audytu, nie usterka — potwierdzone odczytem konsoli. Do rzeczy zależnych od
JS-a trzeba żywej strony, do skorupy i układu wystarcza zapisana.

Ekrany za logowaniem sprawdzone na koncie jednorazowym (utworzone, użyte,
skasowane w tej samej sesji) — technika z `reference_local_test_login`.

### Co znaleziono i naprawiono

**Z1. Okruszki w apce — zasada zapisana w czterech szablonach zamiast w jednym.**
`if (!APP_IS_APP)` wokół `require partials/breadcrumbs.php` stało w `messages`,
`events-list`, `account` i `event-page`, a okruszki dołącza ponad dwadzieścia
stron. W apce wychodziły więc na trasie, przejeździe, „moich przejazdach",
kronice, profilu rowerzysty i skanowaniu skarbu; na stronie przejazdu zawijały
się do DWÓCH linii u góry ekranu. Naprawa: bramka w SAMYM partialu, cztery
opakowania usunięte. JSON-LD `BreadcrumbList` wychodzi razem z nawigacją —
świadomie: to dane dla robota, a robot nigdy nie ma UA apki.

**Z2. `.op-head__act` — trzy przyciski, trzy różne prawe krawędzie.**
Zmierzone na `/trasy/{slug}` przy 375 px: 231, 220 i 225 px przy wspólnej lewej
krawędzi. Na desktopie to poprawny rząd dobrany do treści, na telefonie
`flex-wrap` robi z niego poszarpaną kolumnę. Naprawa w `app.css` (kolumna
+ pełna szerokość), więc web bez zmian. Dotyczy czternastu stron naraz.

**Z3. USTERKA, KTÓREJ NIKT NIE SZUKAŁ: rozjechany komentarz w `app.css` zjadał
regułę.** Akapit kończył się DRUGIM znacznikiem zamykającym komentarz —
pierwszy zamykał go cztery linie wyżej. CSS nie przerywa wtedy parsowania,
tylko połyka dalszy ciąg do najbliższego bloku, więc razem ze śmieciem znikała
reguła `body.is-app.map-page .app-offline`. Skutek: wskaźnik „brak zasięgu"
na pełnoekranowej mapie nie pływał nad mapą, tylko wchodził w jej przepływ.
Zmierzone: `document.styleSheets` dla `app.css` miał 93 reguły i kończył się
na `.app-offline__dot`; po naprawie 96 i brakujący selektor wrócił.
Usterka jest STARSZA niż zmiany z 2026-09-10 (jest w commicie `9d032f7`).
Nowy test skanuje `app.css` i `style.css` w poszukiwaniu tej klasy błędu.

### Co sprawdzono i NIE znaleziono usterki (żeby nie sprawdzać drugi raz)

- **Przewijanie poziome**: żaden z czterech ekranów go nie ma przy 375 px.
- **Zakładki źródeł przejazdów** (`.src-tabs`, Garmin/Polar/Wahoo/COROS/Suunto)
  wyglądają na ucięte, ale mają `overflow-x:auto` i realnie się przewijają
  (485 px treści w 289 px ramce). To nie jest usterka.
- **Zapas na dole**: `body.is-app` rezerwuje 58 px, treść nie chowa się pod
  pływającym paskiem.
- **Cele dotykowe**: poza kilkoma linkami tekstowymi w treści wszystko ma 44+ px.

### Poza zakresem, znalezione przy okazji (NIE naprawiane)

**Dziewięć z dziesięciu okładek w bazie dev wskazuje na nieistniejące pliki**
(`known_routes` 2 z 3, `events` 7 z 7). Na stronie trasy brak pliku degraduje
się łagodnie (szare tło), ale w kafelku „Trasa dnia" na stronie głównej zostaje
pusty prostokąt 325×179 z ikoną zepsutego obrazka. To gnicie danych dev, nie
kod — ta sama klasa co skasowane pliki GPX. Do decyzji usera: czy warto, żeby
brak pliku okładki degradował się tak samo w obu miejscach.

### Czego ten etap NIE obejmuje

Ekranów, które istnieją tylko w apce i były robione wcześniej (`discovery-app`,
`treasure-propose-app`, `ride-summary-app`) — te mają własne fazy w
`apka-mobilna-ux.md`. Oraz wszystkiego, co wymaga telefonu (Etap 5).


## Etap 4 — Domknięcie offline  [WEB + APK] — OTWARTY, ZABLOKOWANY za Etapem 5

Otwarty Etap 3 kontraktu `apka-offline.md`: zimny start bez zasięgu ma
pokazać mapę z tym, co jest w cache'u, zamiast ekranu błędu WebView.
Zablokowany świadomie do czasu sprawdzenia na telefonie, czy cache podkładu
(`sw-app.js`, limity 600 kafli / 900 kafelków wektorowych) w ogóle wystarcza
w terenie. **Nie ruszać przed Etapem 5.**

---

## Etap 5 — Telefon. Warunek konieczny wszystkiego powyżej — OTWARTY

To nie jest etap „na koniec" — to etap, bez którego reszta jest wróżeniem.
Cały moduł (dwa kontrakty, kilkanaście etapów, ~czterysta testów wokół)
nigdy nie został uruchomiony na fizycznym urządzeniu.

Kolejność:
1. `npx cap sync android` + `gradlew assembleDebug` (JDK **dokładnie 21**),
   instalacja na telefonie usera.
2. Przejście listy: logowanie (w tym społecznościowe przez deep link),
   mapa i kadr na GPS, nagrywanie przejazdu w tle i ekran wyniku, alert
   o skarbie, skaner QR, aparat w galerii skarbu, tryb offline,
   push (o ile będzie plik Firebase).
3. **Sprawdzenie migracji na produkcji.** Apka chodzi na
   `https://ridemore.bike`, więc funkcje apki zależą od schematu produkcji.
   Z notatek projektu jako niepewne: 067 (`app_login_tokens` — bez niej
   logowanie społecznościowe w apce nie ma jak zadziałać), 077
   (`push_devices`), 079 (`users.deletion_requested_at`), a także starszy
   backlog 028/029/035-038/061-063. Do zweryfikowania na żywo, nie z notatek.
4. Dopiero potem materiały do sklepów (zrzuty ekranu wymagają działającej
   apki na telefonie).

---

## Decyzje do podjęcia przed startem

1. **Kolejność.** Proponowana: Etap 5 (telefon) → Etap 1 (GPS) → Etap 2a
   → Etap 2b/2c → Etap 3 → Etap 4. Jeśli telefon nie jest teraz dostępny,
   Etapy 1, 2a i 3 są w całości [WEB] i da się je zrobić bez niego —
   z tym samym zastrzeżeniem co dotąd: „kod gotowy, niesprawdzone".
2. **Nawigacja do punktu: Google na sztywno czy `geo:` z wyborem?**
   (2a — rekomendacja: `geo:` w apce, link Google w przeglądarce).
3. **GPX: Pobrane (2b) czy arkusz udostępniania (2c)?** Rekomendacja: oba,
   2b jako fundament i zejście awaryjne, 2c jako droga główna.
4. ~~Kolejność wobec modułu nawigacji~~ — **ROZSTRZYGNIĘTE 2026-09-11: moduł
   nawigacji odłożony w całości** (user: „odpuśćmy robienie nawigacji od zera,
   skupimy się na działaniu samej aplikacji"). Kontrakt wyjechał z `active` do
   `tasks/nawigacja-w-apce-ODLOZONE.md`. Etap 2 tego planu (dojazd do punktu
   + wysyłka GPX-a) ZOSTAJE — jest zrobiony i jest osobną, działającą rzeczą,
   nie namiastką tamtego modułu.
   **Od tej decyzji ten dokument jest jedynym aktywnym planem dla apki**,
   a środek ciężkości przesuwa się na Etap 3 (ekrany) i Etap 5 (telefon).
5. **Czy `/odkrycia` w apce ma dostać hex-statystyki z wersji web**, czy
   pełny ekran mapy ma zostać goły (dzisiejsza decyzja z Etapu 1 przebudowy).
