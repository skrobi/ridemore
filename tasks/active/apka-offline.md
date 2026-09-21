# Apka offline — mapa zamiast blokady — kontrakt implementacyjny

Zgłoszone przez usera 2026-08-29: „Aplikacja nie może blokować mapy w trybie
offline. Będąc na szlaku bez zasięgu muszę widzieć, jak odkrywam kafle. (…)
Może być jakaś ikonka offline, a nie blokada".

## Stan zastany (zweryfikowany w kodzie, nie założony)

### Co JUŻ działa bez sieci — i to jest ważne

`assets/js/app-tracking.js` buforuje ślad LOKALNIE (`@capacitor/preferences`,
klucz `rm_bg_points`), zrzuca bufor co 20 punktów i przy nieudanej wysyłce
ZOSTAWIA go do następnego uruchomienia (`recoverLeftoverBuffer()`). Alerty
„blisko skarbu" liczą się całkowicie lokalnie, z listy pobranej wcześniej
(`nearby`, bbox ~15 km).

WNIOSEK: przejazd bez zasięgu NIE PRZEPADA — pola, punkty i skarby po drodze
zaliczają się po powrocie w zasięg. To jest problem WIDOKU, nie danych, i tak
trzeba go rozwiązywać. Nie budujemy drugiego obiegu danych.

### Co blokuje

`capacitor.config.ts`: `server.errorPath: 'index.html'`. Capacitor podmienia
CAŁĄ stronę na `app/www/index.html` przy każdym nieudanym wczytaniu. To jest
przełącznik zero-jedynkowy: nie ma trybu „część serwisu działa".

Dwa skutki uboczne, oba potwierdzone:
1. **Ekran offline nie ma dostępu do wtyczek Capacitora** (udokumentowane przy
   Etapie 7) — nie odczyta ani `Preferences`, ani pozycji, więc nie pokaże
   ani cache'u, ani mapy.
2. **Odpala się na BŁĘDZIE WCZYTANIA, nie tylko na braku sieci.** Zgłoszenie
   usera („kliknąłem na profil i mam »Brak połączenia«" przy działającym
   internecie) to najpewniej 404 z `/rowerzysta/` zamienione na ekran offline.
   Sam 404 naprawiony osobno (`app-nav.php`, slug), ale MECHANIZM zostaje:
   każdy przyszły błąd HTTP będzie kłamał o stanie sieci.

### Ograniczenie architektoniczne, o które to zahacza

Decyzja #1 z `apka-mobilna.md`: „APKA NIE MA WŁASNEGO FRONTU" — WebView ładuje
żywy serwis pod `server.url`, dzięki czemu cookie sesji jest first-party
i `Core\Auth`/CSRF/bramki działają bez zmian. Każdy offline'owy widok treści
serwisu jest odstępstwem od tej decyzji i wymaga jawnej zgody usera.

Druga zastana decyzja, którą to narusza: `layout.php` od czyszczenia starej
aplikacji One Page jest **świadomie BEZ service workera i manifest.json**
(komentarz w `partials/head.php` przy faviconach, odwołanie do `sw.js`
w katalogu głównym).

## Etapy

### Etap 1 — PRZESTAŃ KŁAMAĆ I PRZESTAŃ BLOKOWAĆ (bez cache'u) — ZROBIONY 2026-08-29

Najmniejsza zmiana, która realizuje zdanie „może być jakaś ikonka offline,
a nie blokada".

1. **Usunąć `errorPath` z `capacitor.config.ts`.** Bez niego nieudane wczytanie
   pokazuje systemowy ekran WebView — brzydki, ale wyłącznie dla strony,
   której naprawdę nie dało się pobrać, a nie dla całej apki.
2. **Wskaźnik offline w serwisie**, nie w apce: mały pasek/ikonka renderowana
   przez `layout-app.php`, sterowana `@capacitor/network` (wtyczka JEST
   w APK od Etapu 3, `native.js` już nasłuchuje `networkStatusChange` pod
   kolejkę skanów). Zero nowych zależności.
3. **Ekran `app/www/index.html` ZOSTAJE** jako ostatnia deska ratunku przy
   starcie bez sieci (WebView nie ma wtedy czego pokazać), ale przestaje być
   reakcją na każdy błąd.

Koszt: NOWY APK (zmiana `capacitor.config.ts` jest build-time).

### Etap 2 — MAPA DZIAŁA OFFLINE — ZROBIONY 2026-08-29 (decyzja usera: „service worker tylko w apce")

Żeby ekran mapy otworzył się bez sieci, jego zasoby muszą być w cache'u
przeglądarki. WebView ładuje prawdziwy origin (`https://ridemore.bike`), więc
**service worker jest technicznie możliwy** — i to jedyna droga, która nie
łamie decyzji #1 (cache należy do serwisu, nie do apki).

Do cache'owania: dokument `/odkrycia` w trybie apki, `style.css` + `app.css`,
`discovery-map.js` + `gpx-map.js`, Leaflet, oraz KAFLE MAPY (`/tiles.php`)
strategią „najpierw cache, potem sieć", z limitem rozmiaru.

**TO JEST COFNIĘCIE ŚWIADOMEJ DECYZJI** („bez service workera", po czyszczeniu
starej aplikacji One Page). Wymaga zgody usera, nie mojej. Ryzyko, które
tamta decyzja miała odsunąć, jest realne: źle unieważniony cache serwuje
starą wersję serwisu i objawia się jako „zmiany nie wchodzą" u WSZYSTKICH
użytkowników www, nie tylko w apce.

Wariant ostrożniejszy: service worker rejestrowany WYŁĄCZNIE w trybie apki
(`APP_IS_APP`), więc przeglądarka nigdy go nie dostaje.

### Etap 3 — WIDOCZNE ODKRYWANIE BEZ ZASIĘGU — OTWARTY

Dopiero po Etapie 2. Pola odkrywa dziś SERWER, z wgranego śladu
(`Utils\DiscoveryGrid`, `RouteCells`). Offline nie ma kto ich policzyć.

Do zrobienia: odpowiednik `DiscoveryGrid::pointToCell()` w JS, rysowanie
świeżo odkrytych pól z lokalnego bufora `rm_bg_points` NA WIERZCHU cache'owanej
warstwy mgły, oznaczone wizualnie jako „jeszcze niewysłane". Po odzyskaniu
sieci ślad idzie zwykłą drogą (`SoloRideController::upload`), serwer liczy
prawdę i lokalna nakładka znika.

NIEZMIENNIK: lokalne pola to PODGLĄD, nigdy źródło prawdy. Punktów nie przyznaje
przeglądarka. Inaczej robimy drugi obieg danych i pierwszą dziurę w liczeniu
punktów.

## Poza zakresem

- Offline dla reszty serwisu (wyjazdy, wiadomości, profile) — mapa jest jedynym
  ekranem używanym w terenie bez zasięgu.
- Pobieranie map „na zapas" na wybrany obszar — osobna, duża funkcja.
- Jakakolwiek zmiana w liczeniu punktów, skarbów i pól po stronie serwera.

## Kryteria akceptacji (Etap 1)

1. Błąd HTTP (404/500) pokazuje prawdziwą stronę błędu z paskiem nawigacji,
   NIE ekran „Brak połączenia".
2. Utrata sieci przy otwartej apce nie podmienia widoku — pojawia się wskaźnik.
3. Odzyskanie sieci chowa wskaźnik bez przeładowania strony.
4. Start apki całkowicie bez sieci nadal pokazuje `app/www/index.html`.
5. Web bez zmian — wskaźnik renderuje wyłącznie `layout-app.php`.

---

## WYKONANIE 2026-08-29 — Etapy 1 i 2 zrobione, Etap 3 otwarty

`php tests/run.php`: **370/370**. Decyzja usera: „service worker tylko w apce".

### Etap 1 — zrobione

- `errorPath` USUNIĘTY z `capacitor.config.ts`.
- `views/web/partials/app-offline.php` — wskaźnik stanu sieci w skorupie apki
  (`@capacitor/network` przez nowe `RM.native.isOnline()`/`onNetworkChange()`).
  Szary, nie czerwony: jazda bez zasięgu to normalny tryb pracy, nie awaria.
- Naprawiony przy okazji REALNY defekt, który dał zgłoszony objaw:
  `app-nav.php` sklejał `/rowerzysta/` + pusty `publicSlug` → 404 → ekran
  offline. `header.php` (web) miał ten warunek od zawsze.

### Etap 2 — zrobione

- `sw-app.js` W KORZENIU (zasięg service workera to katalog pobrania, a ma
  kontrolować `/odkrycia` i `/assets`). OSOBNY plik od `/sw.js` — tamten jest
  kill-switchem po One Page i musi nim zostać.
- Rejestracja wyłącznie pod `APP_IS_APP` w `head.php`. Rejestracja jest
  per-przeglądarka, więc www nigdy nie będzie kontrolowane — to CAŁY mechanizm
  ograniczenia ryzyka, dla którego projekt nie miał SW.
- Cache: nasze kafle (cache-first, limit 600), podkład OpenFreeMap/OSM
  (cache-first, limit 900), CSS/JS serwisu (cache-first — adresy niosą `?v=`,
  więc wdrożenie daje nowy klucz), Leaflet/MapLibre z CDN (network-first),
  dokument `/odkrycia` (network-first). Wszystko poza tą listą przechodzi BEZ
  `respondWith()`, czyli tak, jakby workera nie było.
- Cache dokumentu kasowany przy wejściu bez sesji (ekran mapy jest
  personalizowany i nie może przeżyć wylogowania na urządzeniu).

### Dwie pułapki znalezione pomiarem, nie z dokumentacji

1. **`tiles.php` NIE jest adresem kafli.** `.htaccess` blokuje go dla ruchu
   HTTP (`Require all denied`) — kafle leżą jako statyczne PNG pod
   `/assets/tiles/{warstwa}/{klucz}/{z}/{x}/{y}.png`. Pierwsza wersja workera
   celowała w `tiles.php` i nie cache'owałaby ANI JEDNEGO kafla.
2. **`sw-app.js` dostawał `Cache-Control: immutable` na rok** (zmierzone
   `curl -D-`), mimo reguły no-cache dopisanej na górze `.htaccess`. Blok
   assetów niżej ma negative lookahead wykluczający `sw.js|service-worker.js`
   i trzeba było dopisać do niego trzecią nazwę. Service worker zamrożony na
   rok to najgorszy możliwy stan — to on decyduje, co apka pobiera.

### Etap 3 — NIEZROBIONY

Widoczne odkrywanie pól bez zasięgu (odpowiednik `DiscoveryGrid::pointToCell()`
w JS, rysowanie świeżych pól z bufora `rm_bg_points` jako nakładki „jeszcze
niewysłane"). Wymaga najpierw sprawdzenia Etapów 1-2 na telefonie: jeśli
cache podkładu okaże się za mały albo za wolny, Etap 3 rysowałby pola na
szarym tle i nic by to nie dało.

### POPRAWKA TEGO SAMEGO DNIA — cache dokumentów WYCOFANY

Zgłoszenie usera po wgraniu: „klikanie na ikonę mapy kieruje nas do odkryć,
a nie do dedykowanej mapy na mobile".

**Przyczyna — mój błąd z Etapu 2.** Worker przechwytywał nawigację do
`/odkrycia` i wysyłał ją ponownie własnym `fetch()`. `APP_IS_APP` zależy
WYŁĄCZNIE od User-Agenta (`core/bootstrap.php:39`), a `User-Agent` jest
nagłówkiem ZABRONIONYM — worker nie może go ustawić, a doklejka `ridemore-app`
z Capacitora do żądań workera na Android WebView nie dochodzi. Serwer widział
zwykłą przeglądarkę i oddawał `discovery.php` zamiast `discovery-app.php`.
Odpowiedź szła do `rm-doc-v1`, więc usterka utrwalała się na stałe.

**Naprawa:** cały cache dokumentów usunięty (reguła 5 to teraz sama nota
wyjaśniająca, dlaczego tam nic nie ma). `WERSJA` podbita na `v2`, więc
`rm-doc-v1` kasuje się sam przy aktywacji — nie trzeba niczego czyścić ręcznie.

**ZASADA, KTÓRA Z TEGO ZOSTAJE:** dopóki tryb apki rozpoznaje się po
User-Agencie, ŻADEN dokument nie może przejść przez `fetch()` service workera.
Pilnuje tego test „service worker: ŻADEN dokument nie przechodzi przez fetch
workera", który sprawdza TEŻ, czy bootstrap nadal używa UA — gdy dojdzie drugi
sygnał trybu apki (nagłówek, cookie), trzeba tu świadomie wrócić.

**Cena:** ekran mapy NIE otworzy się bez sieci. Kafle, podkład, arkusze
i biblioteki nadal się cache'ują, więc mapa otwarta w zasięgu i używana dalej
bez zasięgu ma z czego rysować — ale zimny start offline pokaże systemowy
ekran błędu WebView. To jest teraz główna otwarta pozycja Etapu 3.

### Druga poprawka: skok układu na „Zgłoś skarb"

User: „widzę przez chwilę mapę, a później dopiero formularz (po złapaniu
lokalizacji)". Szufladę otwierało dopiero `ustaw()`, czyli pierwsza pozycja
z GPS — a ten łapie od ułamka sekundy do kilkunastu. Formularz wjeżdżał więc
bez żadnego działania użytkownika i przykrywał to, na co patrzył.

Teraz szuflada jest otwarta OD PIERWSZEJ KLATKI, z podpisem „Szukam Twojej
lokalizacji…". GPS zmienia wyłącznie treść podpisu i stawia pinezkę — układ
się nie rusza. Odmowa zgody albo brak sygnału wraca do „Stuknij w mapę, żeby
wskazać punkt", zamiast zostawiać podpis „Szukam…" na zawsze.

---

## KOREKTA 2026-08-29 (wieczór) — Etap 1 cofnięty w połowie

Zgłoszenie z terenu: „nie miałem zasięgu internetu, chciałem uruchomić
aplikację, nic się nie dało zrobić, czarny ekran".

**Co było nie tak w Etapie 1.** Usunięcie `errorPath` zabrało JEDYNY mechanizm,
który wyświetlał `app/www/index.html`. Punkt 3 tego etapu („ekran ZOSTAJE jako
ostatnia deska ratunku") i kryterium akceptacji nr 4 („start apki całkowicie
bez sieci nadal pokazuje app/www/index.html") **były nieprawdziwe w chwili
zapisania** — plik został w repo, ale nic go nie pokazywało. Bez sieci WebView
zostawał z nieudanym wczytaniem na ciemnym tle splasha.

**Czego to NIE unieważnia.** Zarzut wobec `errorPath` jest prawdziwy i został
potwierdzony w źródłach, nie z pamięci: `BridgeWebViewClient` ładuje ten plik
zarówno z `onReceivedError` (sieć), jak i z `onReceivedHttpError` (404/500),
oba pod `request.isForMainFrame()`.

**Rozwiązanie.** `errorPath` wraca, a zakaz kłamania o przyczynie przenosi się
z konfiguracji do samej strony — bo tam da się go spełnić bez utraty ekranu.
`app/www/index.html` nie twierdzi już nic z góry: przy starcie odpytuje serwer
(HEAD) i rozstrzyga między trzema stanami — brak zasięgu, awaria serwisu
(odpowiedź z błędem to TEŻ odpowiedź) i błąd samej strony, przy którym
jednorazowo wraca do aplikacji (blokada pętli w `sessionStorage`).

Kryteria #2, #3 i #5 Etapu 1 zostają bez zmian — wskaźnik w serwisie
i service worker działają, gdy apka JUŻ chodzi. Kryterium #1 zmienia się:
błąd HTTP nie pokazuje serwerowej strony błędu (Capacitor ją podmienia), ale
też nie mówi „brak połączenia" — mówi prawdę i wraca do aplikacji.

**KOSZT: NOWY APK.** `capacitor.config.ts` i `app/www/` są build-time —
zainstalowana apka tej poprawki nie zobaczy bez przebudowy.
