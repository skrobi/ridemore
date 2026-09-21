# Mapa w apce centrowana na bieżącej lokalizacji — kontrakt implementacyjny

Zgłoszone przez usera 2026-08-29: „jeśli jestem na aplikacji mobilnej to
powinieneś centrować na obecnej lokalizacji a nie na zakresie z odkrytych kafli".

Dotyczy `views/web/pages/discovery-app.php` (pełnoekranowa mapa odkryć w apce).
Ekran zgłoszenia skarbu ma własny kontrakt — `zglos-skarb-w-terenie.md`.

## Stan zastany (zweryfikowany w kodzie 2026-08-29)

`DiscoveryController::index()` liczy `mapBounds` przez
`Discovery::boundsFor()` — prostokąt obejmujący FAKTYCZNIE odkryte pola
(własne na zakładce osobistej, wszystkie na społecznościowej). `discovery-app.php`
przekazuje go do silnika jako `bounds:`, a `discovery-map.js` nakłada ten kadr
w `applyStartBounds()`.

Kropka „tu jestem" na tym ekranie JUŻ ISTNIEJE (`RM.native.watchPosition`,
Etap 1) — ale **tylko rysuje**, nigdy nie rusza kadrem. Efekt: user otwiera
apkę w terenie i widzi prostokąt swoich dotychczasowych odkryć, a siebie
gdzieś poza kadrem albo jako punkt w rogu.

## PUŁAPKA — przeczytaj przed napisaniem `setView`

Naiwne „na pierwszej pozycji zrób `map.setView()`" NIE ZADZIAŁA niezawodnie.
W `discovery-map.js` są DWA mechanizmy, które ustawiają kadr po starcie:

1. `applyStartBounds()` (ok. L763) — nakłada kadr z serwera, ale **dopiero gdy
   kontener ma ≥40 px**, i ponawia próbę z `ResizeObserver` aż do skutku.
   Może więc odpalić PO pierwszym odczycie GPS i skasować wycentrowanie.
2. `fitBounds` na wczytanych polach (ok. L744, bramka `fitted`).

Dodatkowo `refresh()` świadomie NIE pyta serwera o pola, dopóki
`startFitDone` nie jest ustawione — czyli nie wolno po prostu wyciąć kadru
startowego, bo zablokuje to pobieranie danych mapy.

## Rozwiązanie

W trybie apki **kadr startowy z serwera zostaje wyłączony jako ŹRÓDŁO WIDOKU,
ale nie jako dane** — mapa dostaje `bounds: null`, a prostokąt z serwera
wędruje do zmiennej w `discovery-app.php` jako AWARYJNY kadr.

Przebieg:

1. Mapa startuje jak dziś przy braku odkryć — widok domyślny.
2. Pierwszy odczyt z `RM.native.watchPosition` (nasłuch już tam jest — nie
   dokładamy drugiego) robi `map.setView([lat, lon], 15)`. **Tylko pierwszy.**
   Kolejne aktualizacje przesuwają wyłącznie kropkę, jak dziś.
3. Jeżeli w ciągu **6 sekund** nie przyjdzie żadna pozycja (brak zgody,
   w budynku, wyłączony GPS) — nakładamy zapisany kadr z serwera,
   czyli zachowanie identyczne z dzisiejszym.
4. Jeżeli user w międzyczasie sam ruszył mapą (`dragstart` albo `zoomstart`
   wywołane jego ręką) — **nie robimy już nic**, ani GPS-em, ani awaryjnie.
   Wyrwanie kadru spod palca jest gorsze niż zły kadr.

### Warunki brzegowe, które muszą zostać spełnione

- `refresh()` musi zacząć pobierać pola po `setView` — sprawdzić, że przy
  `bounds: null` bramka `startBounds && !startFitDone` nie blokuje pierwszego
  zapytania (przy `startBounds === null` warunek jest fałszywy, więc powinno
  być OK, ale to trzeba potwierdzić uruchomieniem, nie lekturą).
- `fitBounds` na wczytanych polach (bramka `fitted`) NIE może przeskoczyć
  wycentrowania na użytkowniku — jeśli przeskakuje, przekazać
  `fitToCells: false` w trybie apki (opcja już istnieje w silniku, L743).
- Zakładka społecznościowa vs osobista: kadr na użytkowniku ma sens na OBU,
  bo to ten sam człowiek w tym samym miejscu. Nie różnicujemy.

## Zakres zmian

- `views/web/pages/discovery-app.php` — `bounds: null` + awaryjny kadr
  w zmiennej, wycentrowanie na pierwszym odczycie w ISTNIEJĄCYM
  `watchPosition`, licznik 6 s, bramka „user ruszył mapą".

**Bez zmian**: `discovery-map.js` (silnik mapy), `DiscoveryController`
(nadal liczy i podaje `mapBounds` — zmienia się tylko to, kto go nakłada),
`Discovery::boundsFor()`, `native.js`, web `discovery.php`, baza.

## Kryteria akceptacji

1. Apka z włączoną lokalizacją: po otwarciu `/odkrycia` mapa stoi na
   użytkowniku (zoom 15), kropka „tu jestem" widoczna w środku.
2. Apka z odmówioną lokalizacją: po ~6 s mapa nakłada kadr odkrytych pól —
   czyli dokładnie dzisiejsze zachowanie.
3. Konto bez ani jednego odkrytego pola i bez GPS: mapa zostaje na widoku
   domyślnym, bez skoków.
4. Ruszenie mapą palcem w pierwszych sekundach: żaden automat już jej nie
   przestawia.
5. Pola i skarby wczytują się (`refresh()` odpala) w każdym z tych trzech
   przypadków — to jest najłatwiejsza rzecz do zepsucia tą zmianą.
6. Web (`/odkrycia` bez UA apki) — HTML i zachowanie kadru bez zmian.
7. `php tests/run.php` zielone.

## Weryfikacja

`php tests/run.php` + `curl` w obu trybach UA. Zachowanie kadru przy realnym
GPS — **wymaga fizycznego telefonu**. Do sprawdzenia w przeglądarce da się
przypadek (2) i (3) oraz punkt 5 (pobieranie pól), podstawiając odrzucaną
obietnicę z `RM.native.position`.

---

## WYKONANIE — KONTRAKT ZAMKNIĘTY 2026-08-29

Zaimplementowane w `views/web/pages/discovery-app.php` dokładnie w zakresie
z kontraktu: `bounds: null` + `fitToCells: false` do silnika, prostokąt
z serwera schodzi do JS-a jako `kadrZSerwera`, wycentrowanie na pierwszej
pozycji, wypełniacz po 6 s, bramka „user ruszył palcem".
`discovery-map.js` nietknięty.

**Odchylenie od kontraktu, na plus:** pozycja jest pobierana DWIEMA drogami
równolegle — szybkim `RM.native.position({maximumAge:120000})` (bierze fix,
który system ma już w ręku) i ciągłym `watchPosition`. Kontrakt przewidywał
tylko to drugie; samo `watchPosition` na zimnym starcie potrafi milczeć
kilkanaście sekund, czyli dłużej niż 6-sekundowy wypełniacz.

**Poprawka wprowadzona po zgłoszeniu usera („mapa nie centruje na mojej
lokalizacji"), już zawarta w kodzie:** pierwsza wersja blokowała GPS wspólną
flagą `kadrUstawiony`, którą podnosił też kadr awaryjny — więc fix przychodzący
po 6 s nie miał już prawa nic zmienić i mapa NIGDY nie centrowała się na
człowieku. Dziś jedyną rzeczą blokującą GPS jest RUCH PALCEM (`userRuszylMapa`);
kadr awaryjny jest tylko wypełniaczem i wolno go nadpisać (`gpsUstawilKadr`).

### Kryteria akceptacji

1. **NIESPRAWDZONE — wymaga telefonu z GPS-em.** Kod jest, obie drogi pozycji
   wołają `kadrNaMnie(lat, lon)` → `map.setView([lat,lon], 15)`.
2. **NIESPRAWDZONE bezpośrednio** (wymaga odmowy uprawnienia na urządzeniu),
   ale ścieżka jest ta sama co dziś na koncie bez GPS: `setTimeout` 6 s →
   `fitBounds(kadrZSerwera)`.
3. Wynika wprost z kodu: bez `kadrZSerwera` i bez pozycji nie odpala się nic.
4. Bramka `dragstart`/`zoomstart` podniesiona PRZED własnym `setView`, więc
   ruch silnika nie policzy się jako ruch człowieka.
5. **SPEŁNIONE — POTWIERDZONE URUCHOMIENIEM**, tak jak kontrakt wymagał
   („to trzeba potwierdzić uruchomieniem, nie lekturą"). W przeglądarce
   utworzono mapę przez `ridemoreDiscoveryMap` z `bounds:null` i
   `fitToCells:false`, z podmienionym `fetch` liczącym żądania: silnik wysłał
   **1 zapytanie o pola** (`/api/discovery/cells?scope=all&zoom=6&north=…`)
   i **1 o skarby**. Bramka `startBounds && !startFitDone` przy
   `startBounds === null` nie blokuje niczego — zgodnie z przewidywaniem
   kontraktu, ale teraz zmierzonym.
6. Web bez zmian: `discovery.php` (wariant przeglądarkowy) dalej dostaje
   `bounds` z kontrolera — zmiana dotyczy wyłącznie `discovery-app.php`.
7. **SPEŁNIONE**: `php tests/run.php` → 384/384.

### Co zostaje do sprawdzenia na telefonie

Punkty 1, 2 i 4 — czyli wszystko, co dotyczy prawdziwego GPS-u i palca.
Wpisane do listy rzeczy do sprawdzenia przy pierwszym przejeździe testowym.
