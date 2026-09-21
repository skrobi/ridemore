# Zgłoszenie skarbu w terenie (apka) — kontrakt implementacyjny

Zgłoszone przez usera 2026-08-29: „zgłaszanie skarbu z poziomu aplikacji
powinno podebrać lokalizację z bieżącej lokalizacji i do tego formularz
+ zdjęcie z telefonu (…) w kilku krokach, jak najmniej obciążające usera".

## ZANIM COKOLWIEK ZACZNIESZ — sprawdź to pierwsze

User napisał, że „obecnie pociąga się strona z aplikacji internetowej".
**Kod temu przeczy.** `TreasureProposalController::form()` (linia 57) już dziś
wybiera `treasure-propose-app.php` na podstawie `APP_IS_APP` — pełnoekranową
mapę z formularzem w dolnej szufladzie (Faza 3, `apka-mobilna-ux.md`, 2026-08-29).

Trzy możliwe wyjaśnienia, do rozstrzygnięcia PRZED implementacją:

1. `views/web/pages/treasure-propose-app.php` nie został wgrany na produkcję
   (wtedy `View::render` rzuciłby „Widok nie istnieje" — user zobaczyłby błąd,
   nie stronę web, więc to mało prawdopodobne).
2. User oglądał ekran przed naprawą `Icon::render('user')` (audyt 2026-08-29) —
   każda strona w apce urywała się w pasku nawigacji, więc ekran mógł wyglądać
   na zepsuty/niepełny.
3. User był niezalogowany — `/skarby/zglos` wymaga sesji (302 na `/logowanie`),
   a strona logowania to zwykły widok web.

Jeżeli okaże się (2) albo (3), część tego kontraktu jest już zrobiona i zakres
schodzi do samych luk z sekcji niżej. NIE PISZ NOWEGO EKRANU OD ZERA.

## Stan zastany (zweryfikowany w kodzie 2026-08-29)

Co JEST w `treasure-propose-app.php`:

- mapa pełnoekranowa (`.app-map`), warstwa istniejących skarbów (antyduplikat),
- tap w mapę stawia przeciągalną pinezkę i OD RAZU otwiera szufladę z formularzem,
- przycisk **„Moja lokalizacja"** wołający `RM.native.position()`,
- wyszukiwarka miejsc (Nominatim),
- pola: Nazwa (wymagana), Co to jest, Ujawnienie na mapie, Opis.

Czego NIE MA — to są realne luki, pokrywające się z prośbą usera:

| luka | dowód |
|---|---|
| **Lokalizacja NIE jest pobierana automatycznie** | mapa startuje na `[52.0, 19.4]` (środek Polski); GPS to ręczny przycisk `#zgGps` |
| **Zdjęcia nie ma NIGDZIE w obiegu zgłoszenia** | brak `input[type=file]` w obu szablonach; `TreasureProposalController::save()` nie przekazuje `photo_url` do `Treasure::save()` |
| **Formularz ma 5 pól** | Szukaj + Nazwa + Kategoria + Ujawnienie + Opis — za dużo „z paki" |

## Czego NIE trzeba dokładać (reuse — sprawdzone)

- **Kolumna na zdjęcie ISTNIEJE**: `treasures.photo_url` jest w schemacie od
  początku modułu i `Treasure::save()` ją zapisuje (patrz migration_066, nagłówek).
  ZERO nowej tabeli, ZERO migracji.
- **Most do aparatu ISTNIEJE**: `RM.native.takePhoto()` (Etap 6) + delegacja
  `data-rm-camera-for="<id inputu>"`, która wkłada zdjęcie do zwykłego
  `<input type=file>` przez `DataTransfer`. Wzorzec do skopiowania 1:1 —
  `views/web/pages/treasure-scan.php:101`.
- **Upload plików ISTNIEJE**: `Utils\Upload`, ten sam co galeria skarbu.
- **Szuflada ISTNIEJE**: `.disc-panel` + `ridemoreSetupPanel()`.

## Docelowy przebieg (propozycja — do akceptacji usera)

Założenie: user STOI PRZY OBIEKCIE, w rękawiczkach, telefon w jednej ręce.

1. **Wejście na ekran → od razu GPS.** Bez klikania: `RM.native.position()`
   leci na starcie, stawia pinezkę na bieżącej pozycji, centruje mapę (zoom 17)
   i otwiera szufladę. Podpis: „Twoja lokalizacja · popraw, jeśli stoisz obok".
   Pinezka zostaje przeciągalna, tap w mapę nadal działa — automat to SKRÓT,
   nie odebranie kontroli.
2. **Zdjęcie** — pierwszy element w szufladzie, duży przycisk „Zrób zdjęcie"
   (`data-rm-camera-for`), miniatura po zrobieniu. Opcjonalne.
3. **Nazwa** — jedyne pole wymagane, jak dziś.
4. **Co to jest** — kategoria.
5. **Zgłoś to miejsce.**

Chowamy pod „Więcej szczegółów" (`<details>`, domyślnie zwinięte):
**Ujawnienie na mapie** (domyślnie 2 = Jawny, tak jak dziś) i **Opis**.

**Wyszukiwarka miejsc znika z trybu apki.** Służy do zgłaszania z fotela —
przeczy całej idei tego ekranu. Zostaje w `treasure-propose.php` (web).

Efekt: z „5 pól + znajdź się na mapie" robi się „zdjęcie, nazwa, kategoria".

### Zachowanie awaryjne — obowiązkowe

GPS potrafi nie odpowiedzieć (brak zgody, w budynku, wyłączona lokalizacja).
Wtedy ekran MUSI wyglądać dokładnie jak dziś: mapa Polski, podpis „Stuknij
w mapę, żeby wskazać punkt", szuflada zwinięta. Żadnego blokującego spinnera
ani modala z błędem — `RM.native.position()` ma `.catch()`, który już
istnieje w tym pliku.

## Zakres zmian

- `views/web/pages/treasure-propose-app.php` — automat GPS na starcie,
  przestawiona kolejność pól, `<details>` na szczegóły, `input[type=file]`
  + przycisk aparatu, usunięcie wyszukiwarki.
- `core/Controllers/TreasureProposalController::save()` — przyjęcie pliku,
  `Utils\Upload`, dołożenie `'photo_url'` do tablicy `Treasure::save()`.
  Walidacja typu/rozmiaru jak w galerii skarbu.
- `assets/css/style.css` — miniatura zdjęcia w szufladzie, jeśli nie da się
  użyć istniejącej klasy.

**Bez zmian**: schemat bazy, migracje, `Models\Treasure`, trasy,
`discovery-map.js`, szablon web `treasure-propose.php`.

### Odstępstwo od zakresu — `native.js`, uzasadnione (2026-08-29)

Kontrakt zakładał `native.js` bez zmian. Przy implementacji wyszła realna
usterka we wspólnym kodzie: delegacja `[data-rm-camera-for]` **dokładała**
zdjęcie do `input.files` bezwarunkowo. Dotąd jedynym odbiorcą była galeria
skarbu z `multiple`, więc było to poprawne. Pole zgłoszenia przyjmuje JEDNO
zdjęcie (okładka), a ustawienie dwuelementowej listy na polu bez `multiple`
każda przeglądarka rozstrzyga po swojemu.

Naprawa: dokładamy tylko gdy `input.multiple`, w przeciwnym razie zastępujemy.
Dwie linie, nie może zepsuć galerii (ta ma `multiple`), a bez niej funkcja
nie działałaby poprawnie przy drugim naciśnięciu „Zrób zdjęcie".

## Uwaga poza zakresem (do decyzji, nie robić po cichu)

Zgłoszenie tworzy skarb ze `status = 'PROPOSED'` i `reveal_level` wybranym
przez zgłaszającego. `Treasure::reveal()` **zeruje `photo_url` dla Tropu (1)
i Ukrytego (0)** — inaczej zdjęcie zdradza zagadkę. Czyli: przy zgłoszeniu
ukrytego skarbu zdjęcie zapisze się do bazy, ale nikt go nie zobaczy, dopóki
punkt nie zostanie odkryty. To POPRAWNE zachowanie (ta sama bramka co
wszędzie), ale trzeba o tym uprzedzić w interfejsie jednym zdaniem, żeby user
nie sądził, że zdjęcie przepadło.

## Kryteria akceptacji

1. Wejście na `/skarby/zglos` w apce z włączoną lokalizacją: pinezka stoi na
   bieżącej pozycji, szuflada otwarta, bez ani jednego kliknięcia.
2. Odmowa/brak GPS: ekran zachowuje się jak dziś, bez błędu blokującego.
3. Zdjęcie z aparatu ląduje w `<input type=file>` i po wysłaniu jest widoczne
   jako `treasures.photo_url` nowego wiersza `PROPOSED`.
4. Zgłoszenie BEZ zdjęcia nadal przechodzi (pole opcjonalne).
5. Web (`treasure-propose.php`) niezmieniony — `curl` bez UA apki daje
   identyczny HTML.
6. `php tests/run.php` zielone; nowy test na przekazanie `photo_url` w `save()`.

## Weryfikacja

`php tests/run.php` + `curl` w obu trybach UA. Aparat i GPS — **NIE DA SIĘ
sprawdzić bez fizycznego telefonu**, to samo ograniczenie co w całym module.
Po naprawie `Icon::render('user')` na prodzie i instalacji APK ten ekran
powinien być JEDNYM Z PIERWSZYCH sprawdzonych na urządzeniu.

---

## WYKONANIE — KONTRAKT ZAMKNIĘTY 2026-08-29

### Rozstrzygnięcie sekcji „ZANIM COKOLWIEK ZACZNIESZ"

Hipoteza 1 (brak pliku na produkcji) ODPADA — `treasure-propose-app.php`
istnieje i `TreasureProposalController::form()` wybiera go po `APP_IS_APP`.
Zostają (2) i (3), czyli user oglądał ekran przed naprawą ikon albo bez sesji.
Zgodnie z instrukcją z nagłówka kontraktu NIE powstał żaden nowy ekran —
zakres zszedł do trzech realnych luk z tabeli i wszystkie trzy są zamknięte.

### Stan implementacji (zweryfikowany w kodzie, plik po pliku)

- **GPS automatycznie na wejściu** — `RM.native.position(...)` odpala się przy
  starcie ekranu, bez klikania; ręczny przycisk „Moja lokalizacja" zostaje.
- **Zdjęcie w obiegu** — `<input type="file" id="tpPhoto" name="photo">`
  (BEZ `multiple`, bo to okładka) + `data-rm-camera-for="tpPhoto"`, czyli
  istniejąca delegacja aparatu z `native.js`. Kontroler przyjmuje plik przez
  `Upload::saveCoverPhoto()` i podaje go jako `photo_url` do `Treasure::save()`.
- **Formularz skrócony** — szczegóły (Ujawnienie, Opis) schowane w `<details>`,
  wyszukiwarka miejsc usunięta Z APKI i zachowana na webie.
- **Uprzedzenie o ukryciu** — zdanie „Przy Tropie i Ukrytym zdjęcie zobaczy
  dopiero ten, kto…" jest w formularzu, zgodnie z sekcją „Uwaga poza zakresem".
- **Odstępstwo `native.js`** (dokładanie vs zastępowanie pliku przy braku
  `multiple`) — opisane wyżej w tym kontrakcie, wykonane.

### Kryteria akceptacji

1, 2, 3 (część „z aparatu") — **NIESPRAWDZONE, wymagają telefonu.** Kod jest
kompletny na całej długości toru; nie da się tu zrobić więcej bez urządzenia.
4. **SPEŁNIONE, przetestowane**: zgłoszenie bez zdjęcia zapisuje `photo_url`
   jako NULL i przechodzi.
5. **SPEŁNIONE**: wyszukiwarka miejsc jest w `treasure-propose.php` i nie ma
   jej w wariancie apki — pilnuje tego test.
6. **SPEŁNIONE — DOPISANE 2026-08-29**, bo tego jednego brakowało do zamknięcia
   kontraktu. `tests/dodawanie_skarbow_test.php`, cztery testy: zdjęcie dochodzi
   do bazy, brak zdjęcia nie blokuje zgłoszenia, kontroler faktycznie podaje
   `photo_url` modelowi i bierze je ze wspólnego helpera uploadu, a ekran apki
   ma aparat, pojedyncze pole i zdanie o ukryciu. Kontroler kończy się
   `header()`+`exit`, więc jego część sprawdzana jest statycznie na źródle —
   ten sam wzorzec co przy `SoloRideController`.
   `php tests/run.php` → 388/388.
