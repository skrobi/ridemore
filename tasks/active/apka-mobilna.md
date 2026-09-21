# Aplikacja mobilna (Capacitor) — kontrakt implementacyjny

Data przyjęcia: 2026-08-22. Kierunek zaakceptowany przez usera.

> **STAN NA 2026-09-12 — przeczytaj to przed planowaniem czegokolwiek stąd.**
>
> Kod wszystkich etapów jest napisany. Etapy 0, 1, 2 i 5 są zamknięte; etapy
> **3, 4, 5b, 6, 7 i 8 mają status „KOD GOTOWY, niesprawdzony na urządzeniu"**,
> a etap 9 (wydanie) — „CZĘŚCIOWO". **Nie jest to lista dziewięciu niezależnych
> niewiadomych: wszystkie sprowadzają się do jednej rzeczy — apka nigdy nie
> została uruchomiona na fizycznym telefonie.**
>
> Ta pozostała praca ma jedno miejsce zamieszkania i NIE jest nim ten plik:
> `apka-przeglad-2026-09.md`, **Etap 5**. Tamten dokument sam się ogłosił
> (2026-09-11, po odłożeniu modułu nawigacji) „jedynym aktywnym planem dla
> apki" i to tam jest kolejność: `cap sync` → `assembleDebug` (JDK **dokładnie
> 21**) → lista przejścia na telefonie → sprawdzenie migracji na produkcji →
> materiały do sklepów.
>
> Ten plik zostaje w `active/`, bo etapy 3–9 nie są zamknięte, ale służy dziś
> jako **opis JAK to zrobiono**, nie jako lista do wykonania. Push dodatkowo
> czeka na plik konta serwisowego Firebase od usera — to jedyna blokada
> nietechniczna w całym module.

## Cel

Wydać ridemore.bike jako aplikację na Androida i iOS, w której da się **odkrywać
i zgłaszać skarby w terenie** — z natywnym skanerem QR, GPS-em, aparatem
i powiadomieniami push. Serwis webowy zostaje jednym źródłem prawdy; apka jest
powłoką, która dokłada to, czego przeglądarka dać nie może.

## Decyzje architektoniczne (zaakceptowane, nie wracamy do nich)

1. **`server.url = https://ridemore.bike`** — WebView ładuje żywy serwis, a nie
   kopię frontu spakowaną do apki. Powód: cookie sesji jest wtedy first-party,
   więc `Core\Auth`, CSRF i wszystkie bramki uprawnień działają bez zmian —
   żadnego CORS-u, żadnej drugiej autoryzacji tokenowej, żadnego duplikatu
   widoków. Zmiana na serwerze = zmiana w apce, bez przechodzenia przez sklep.
   Świadomy koszt: bez sieci apka pokazuje własny ekran zastępczy, nie treść.
2. **Android i iOS równolegle** od Etapu 3 (user ma Maca i konto Apple Developer).
3. **Push w zakresie v1** (Etap 8).
4. **GPS w tle w zakresie** — ale jako funkcja „nagrywanie przejazdu" (Etap 5b),
   nie jako samo uprawnienie. Uzasadnienie w sekcji Etapu 5b.
5. **Serwis NIE staje się PWA** — layout nadal bez manifestu i service workera
   (patrz `sw.js`, `md/architecture.md`). Apka to Capacitor, nie PWA.

## Stan zastany (zweryfikowany w kodzie 2026-08-22, nie zakładany)

### Autoryzacja
`Core\Auth` — sesja PHP w cookie, `SameSite=Lax`, „Zapamiętaj mnie" = 30 dni
(`REMEMBER_SECONDS`), `session.gc_maxlifetime` ustawiane w `core/bootstrap.php`.
CSRF (`Core\Csrf::check`) na każdym POST, w tym `/api/treasures/claim`
i `/api/treasures/confirm`. Logowanie społecznościowe: `/auth/google`, Strava.

### Endpointy skarbów — kompletne, nic nowego nie trzeba

| Co | Endpoint | Plik |
|---|---|---|
| Skarby w kadrze (pęczki+pinezki, `stan=moje/nowe`, `rider=`) | `GET /api/treasures` | `api/routes.php:199` |
| Jeden skarb (dymek + galeria) | `GET /api/treasures/{id}` | `api/routes.php:294` |
| Zaliczenie z GPS | `POST /api/treasures/claim` | `api/routes.php:350` |
| Potwierdzenie zgłoszenia | `POST /api/treasures/confirm` | `api/routes.php:391` |
| Pola discovery w kadrze | `GET /api/discovery/cells` | `api/routes.php:431` |
| Ekran naklejki (GET pokazuje, POST zalicza) | `/skarb/{code}` | `TreasureScanController` |
| Zgłoszenie skarbu + zdjęcia | `/skarby/zglos`, `/skarb/{code}/zdjecie` | `TreasureProposalController`, `TreasureScanController` |

**Naklejka QR koduje adres `/skarb/{code}`** — skaner ma odczytać URL i przenieść
tam WebView. Zero nowego API pod QR.

### Responsywność (zmierzone przy 375x812 na lokalnym dev)
- `/`, `/wydarzenia`, `/odkrycia?mapa=spolecznosc`, `/logowanie` — brak
  przewijania w poziomie, layouty się składają. Baza jest dobra.
- **`.disc-panel` ma `display:none` poniżej 680 px** (`style.css:2066`) — na
  telefonie panel „Skarby, które czekają"/„W obszarze" znika w całości. To
  największa dziura funkcjonalna w apce.
- `.disc-map` ma 380 px wysokości na mobile (`style.css:2029`) — w apce mapa
  powinna wypełniać ekran.
- ~23 elementy klikalne poniżej 36 px na stronie głównej.

### `navigator.geolocation` — 7 miejsc do podmiany na most natywny
`assets/js/discovery-map.js`, `views/web/pages/discovery.php`,
`views/web/pages/treasure-scan.php`, `views/web/pages/treasure-propose.php`,
`views/web/pages/home.php`, `views/web/pages/events-list.php`,
`views/web/pages/treasures-admin.php`.

### Toolchain
Brak Node/npm, brak Android SDK. Jest Java i Git. Etap 0 to nadrobienie.

## Pułapki do obsłużenia (znalezione, nie hipotetyczne)

1. **Google OAuth jest blokowany w WebView** (`disallowed_useragent`). `/auth/google`
   (i dla spójności Strava) muszą iść przez systemową przeglądarkę
   (`@capacitor/browser` → Custom Tabs / ASWebAuthenticationSession) i wracać
   deep linkiem.
2. **`@capacitor/storage` nie istnieje pod tą nazwą** od Capacitora 3 — to
   `@capacitor/preferences`.
3. **Sesja musi przeżyć ubicie procesu** — w trybie apki wymuszamy `remember`,
   inaczej cookie sesyjne ginie i user wylatuje po każdym zamknięciu.
4. **`@capacitor/geolocation` nie robi tła** — do Etapu 5b potrzebny
   `@capacitor-community/background-geolocation`.
5. **Apple wymaga kasowania konta z poziomu apki**, jeśli da się je w niej
   założyć. Sprawdzić `/ustawienia`; dopisać, jeśli brak.
6. **Apple 4.2 „minimum functionality"** — samo opakowanie strony bywa odrzucane.
   Obroną są natywne funkcje z Etapów 4-8.

## Etapy

### Etap 0 — Toolchain — ZROBIONE 2026-08-23
Node 24.19, npm 11.19, Android Studio + SDK, **JDK 21** (Temurin
`C:\Program Files\Eclipse Adoptium\jdk-21.0.12.101-hotspot`).

**JDK 21, nie 17 — plan mówił źle.** Wtyczki Capacitora 8 deklarują toolchain
`languageVersion=21`, a Gradle dopasowuje WERSJĘ, nie „21 lub wyżej": build padał
na `capacitor-camera` mimo obecnych JDK 17 (Temurin) i JDK 25 (wbudowane
w Android Studio). Pozostałe JDK na maszynie (13, 17, 19) mogą zostać.

**SDK Platform 36 dobrał się sam** — Gradle pobiera brakującą platformę
i akceptuje licencję przy pierwszym buildzie. W SDK Managerze nic nie trzeba klikać.

`ANDROID_HOME`/`JAVA_HOME` nadal nieustawione w systemie i **nie są konieczne**:
przy buildach z CLI ustawiam je na czas jednego polecenia, a Android Studio ma
własne ustawienia. Trwałe ustawienie to wygoda, nie wymóg.

**Kryterium spełnione:** `gradlew assembleDebug` → BUILD SUCCESSFUL w 2 min 23 s,
`app/android/app/build/outputs/apk/debug/app-debug.apk` (32 MB).

### Etap 1 — Tryb aplikacji po stronie serwisu — ZROBIONE 2026-08-22
Pliki: `core/bootstrap.php` (`APP_IS_APP`), `core/Core/Auth.php` (wymuszony
remember), `views/web/layout.php` (klasa, viewport, most, pasek),
`views/web/partials/app-nav.php` (nowy), `assets/js/native.js` (nowy),
`assets/css/style.css` (blok `body.is-app` na końcu).
Testy: `php tests/run.php widoki` — 5 nowych przypadków (spojenia, nie wygląd).
Zweryfikowane: `curl` z dwoma User-Agentami (klasa, `viewport-fit`, pasek
tylko w apce) + pomiar w przeglądarce przy 375x812 (pasek 59 px na dole,
przycisk skanowania 56x56, zapas na dole 58 px, zero przewijania w poziomie).

- Rozpoznanie apki po własnym User-Agencie (`ridemore-app`) → stała `APP_IS_APP`
  w `core/bootstrap.php` → klasa `is-app` na `<body>` w `views/web/layout.php`.
- W trybie apki: `viewport-fit=cover` + `env(safe-area-inset-*)`, ukryta stopka
  i część nagłówka, wymuszony `remember` przy logowaniu.
- **Dolny pasek nawigacji jako JEDEN wspólny partial** (Mapa / Skarby /
  Wydarzenia / Profil) — nie kopia per strona.
- Nowy `assets/js/native.js`: most `window.RM.native` z fallbackiem do
  przeglądarki, żeby ten sam kod działał w apce i na www.

**Kryterium:** serwis ze zmienionym UA wygląda jak apka; zwykła przeglądarka
bez żadnej zmiany.

### Etap 2 — Poprawki mobilne z audytu — ZROBIONE 2026-08-22 (z wyjątkiem niżej)
Wybór usera: panel skarbów jako **szuflada od dołu**. Zrobione:
- `.disc-panel` = szuflada (uchwyt 46 px z licznikiem, otwarta 62% mapy);
  uchwyt dokładany z JS-a dla KAŻDEGO panelu (`discovery.php`),
- `.disc-map` na telefonie `min(62vh,520px)` zamiast 380 px,
- cele dotykowe do 40 px pod `@media(pointer:coarse)` i w `@media(max-width:680px)`.

Zmierzone przy 375x812: `/`, `/wydarzenia`, `/odkrycia`, `/organizatorzy`, `/puls`,
`/trasy/{slug}`, `/events/{slug}`, `/skarb/{code}` — zero przewijania w poziomie,
zero celów dotykowych poniżej 40 px. Desktop bez zmian (sprawdzone: panel 250 px
w rogu, uchwyt ukryty, zoom 30 px, mapa 520 px).

**NIEZWERYFIKOWANE — ekrany za logowaniem**: profil rowerzysty, kronika,
wiadomości/czat, kreator wydarzenia, `/skarby/zglos`, mapa osobista `/odkrycia`
(panel „Ostatnia aktywność" — ten sam kod szuflady, ale nieobejrzany na żywo).
Powód: hasło konta testowego jest nieaktualne, a zakładanie sesji w obejściu
logowania zostało zablokowane. Do sprawdzenia przy pierwszym uruchomieniu apki
albo po podaniu działającego logowania.

### Etap 3 — Szkielet Capacitora — SZKIELET GOTOWY 2026-08-22, build czeka
Zrobione: katalog `app/` (Capacitor 8.5, własny `package.json` i `.htaccess`
odcinający go od Apache), `capacitor.config.ts` z `server.url` i `appendUserAgent`,
platformy `android` i `ios`, 10 pluginów, ekran „brak połączenia" (`app/www/`),
uprawnienia w manifeście Androida i opisy w `Info.plist`, wpisy w `.gitignore`.
Zweryfikowane: `npx cap sync android` składa poprawny `capacitor.config.json`
(adres + UA), a `RIDEMORE_APP_URL` podmienia adres i włącza `cleartext`
w obie strony.

**APK ANDROIDA BUDUJE SIĘ (2026-08-23).** Polecenie, którym powstało — z katalogu
`app/android`, po ustawieniu `JAVA_HOME` na JDK 21 i `ANDROID_HOME` na SDK:
`.\gradlew.bat assembleDebug`.

**Zostało do kryterium Etapu 3:**
1. Wgranie APK na fizyczny telefon i sprawdzenie, czy WebView otwiera serwis,
   utrzymuje sesję po ubiciu procesu i pokazuje dolny pasek (czyli czy serwer
   naprawdę widzi `ridemore-app` w User-Agencie). **Nic z tego nie było
   uruchomione na urządzeniu** — żaden telefon nie był podpięty (`adb devices`
   puste).
2. iOS — projekt jest wygenerowany, ale build wymaga Maca (CocoaPods, Xcode).
3. **Logowanie Google w apce — patrz decyzja niżej.**

**Kryterium:** build na fizycznym Androidzie i iPhonie loguje się (hasłem
i Google) i utrzymuje sesję po restarcie apki.

#### DECYZJA DO PODJĘCIA: logowanie Google/Strava w apce
Google blokuje OAuth w WebView (`disallowed_useragent`), więc logowanie musi
otworzyć systemową przeglądarkę. Problem, który się za tym kryje: **Custom Tabs
i WebView mają OSOBNE ciasteczka**. Zalogowanie się w przeglądarce nie loguje
apki — sesja powstaje nie tam, gdzie trzeba.

Trzy wyjścia:
- **(A) Jednorazowy token powrotny.** Po OAuth serwer przekierowuje na deep link
  `bike.ridemore.app://auth?token=…`; apka otwiera `…/auth/app?token=…` już
  w WebView, serwer wymienia token na sesję. Wymaga: tabeli tokenów (albo kolumny
  z TTL), endpointu, obsługi deep linku w obu platformach. Token MUSI być
  jednorazowy i krótkożyjący — to jest pełnoprawny mechanizm logowania.
- **(B) Bez logowania społecznościowego w v1** — tylko e-mail i hasło.
  **Konsekwencja: kto założył konto przez Google, NIE WEJDZIE do apki** (nie ma
  hasła), a konta ze Stravy nie mają nawet e-maila. To wyklucza część istniejących
  użytkowników, nie tylko utrudnia im życie.
- **(C) Hasło jako furtka** — w apce tylko e-mail/hasło, ale przed wydaniem
  mailing do kont społecznościowych „ustaw hasło". Tanie, ale przerzuca robotę
  na użytkownika i nie zadziała dla Stravy (brak adresu e-mail).

Rekomendacja: **(A)**, bo to jedyne wyjście, które nie zamyka drzwi żadnej grupie
kont. Nie zaczynam bez akceptacji — to nowy mechanizm uwierzytelniania, a §6
CLAUDE.md mówi wprost, żeby nie dokładać takich rzeczy z własnej inicjatywy.

**DECYZJA USERA 2026-08-22: wariant (A), jednorazowy token powrotny.**
**ZROBIONE 2026-08-23** — po tym, jak user potwierdził usterkę na żywo
(„przenosi mnie do strony www autoryzacja się nie powiodła i zostaję już na WWW").

Powstało:
- `migration/migration_067_app_login_tokens.sql` — tabela tokenów.
  **`user_id` to BIGINT UNSIGNED**, nie INT: `users.id` jest bigintem i klucz
  obcy z INT nie powstaje (errno 150).
- `core/Models/AppLoginToken.php` — `issue()` / `consume()`. Hash SHA-256
  w bazie, TTL 180 s, jednorazowość przez `FOR UPDATE` + DELETE w transakcji.
  **`consume()` respektuje transakcję wywołującego** (PDO nie zagnieżdża
  `beginTransaction`, a uruchamiacz testów owija w nią każdy przypadek).
- `SocialAuthController`: `?app=1` → znacznik `oauth_app` w sesji przeglądarki;
  `loginUser()` w tym trybie **nie loguje w przeglądarce**, tylko wystawia token
  i przekierowuje na deep link; nowa akcja `appHandoff()` na `/auth/app`.
- `partials/social-login.php` — `data-rm-oauth` na obu przyciskach.
- `assets/js/native.js` — przejęcie kliknięcia (Browser.open z `app=1`)
  i nasłuch `appUrlOpen` → `Browser.close()` → nawigacja na `/auth/app`.
  Ścieżka bazowa liczona z adresu samego `native.js` (plik jest statyczny,
  więc nie może nieść `base_path` z PHP-a).
- `AndroidManifest.xml` (intent-filter) i `Info.plist` (CFBundleURLTypes).

Testy: 6 przypadków w `tests/uzytkownicy_test.php` — zachowaniowych, nie
skanujących plik: jednorazowość, wygaśnięcie, hash w bazie, śmieci, rozdzielność
kont. `php tests/run.php uzytkownicy`.

**Niezweryfikowane na urządzeniu:** cały przeskok przeglądarka → deep link → apka.
Wymaga wdrożenia migracji 067 i kodu PHP na produkcję ORAZ nowego APK
(manifest się zmienił, stary build nie zna schematu `bike.ridemore.app`).

### Etap 4 — Skaner QR — KOD GOTOWY 2026-08-22, niesprawdzony na urządzeniu
`RM.native.scan()` w `assets/js/native.js`: `startScan`/`stopScan` (nie
androidowy `scan()` — nie istnieje na iOS, byłyby dwie ścieżki i jedna
nietestowana), podgląd pod przezroczystą stroną (`rm-scan-on`), jedno wyjście
`koniec()` sprzątające po kodzie, anulowaniu i błędzie. Bramka `adresSkarbu()`:
ten sam origin + ścieżka `/skarb/{kod}`, inaczej null.
Zweryfikowane w przeglądarce: bramka przepuszcza własną naklejkę i odrzuca obcy
host, złą ścieżkę, śmieci, pusty ciąg i doklejone `../`.

**Kryterium (nadal otwarte):** skan naklejki z `/admin/skarby/wydruk` otwiera
ekran skarbu i zalicza go przyciskiem — wymaga fizycznego telefonu.

### Etap 5 — GPS bieżący — ZROBIONE 2026-08-22
`RM.native.position()` zastąpiło `navigator.geolocation` we WSZYSTKICH siedmiu
miejscach; pilnuje tego test skanujący repozytorium. Parametry dobrane per
miejsce (filtr listy 50 km nie potrzebuje wysokiej dokładności ani świeżej
pozycji, zaliczenie skarbu potrzebuje obu). Komunikaty błędów ujednolicone —
dawniej odmowa zgody i brak sygnału dawały ten sam tekst o zgodzie, a na liście
wydarzeń wychodził angielski komunikat przeglądarki.
Zweryfikowane w przeglądarce na podstawionej geolokalizacji: kształt
`{lat, lon, accuracy}`, przekazywanie opcji, mapowanie kodów błędu 1/2/3 oraz
dwa prawdziwe wywołania end-to-end (przycisk „Blisko mnie" na stronie głównej
i na liście wydarzeń).

**Kryterium (otwarte):** „jestem tutaj" zalicza skarb w terenie — wymaga telefonu.

### Etap 5b — GPS w tle: nagrywanie przejazdu ORAZ „jesteś blisko skarbu" — KOD GOTOWY 2026-08-28, niesprawdzony na urządzeniu
Decyzja usera 2026-08-22: **oba zastosowania**, jedno uprawnienie.
Doprecyzowanie 2026-08-28: **nagrywanie startuje automatycznie przy otwarciu
apki** (nie osobny przycisk „Start" — wzorem MysteryHike), za jawną zgodą
z banera przy pierwszym uruchomieniu; **alert działa też dla skarbów
UKRYTYCH** (poziom odsłony 0/1) — świadome odstępstwo od mechaniki „odkryj
pole jazdą, żeby zobaczyć", bo user uznał tę informację za ważną. Precyzja
alertu zostaje jednak identyczna jak po odkryciu (środek pola, generyczna
treść) — patrz `Treasure::nearbyForAlerts()`.

`@capacitor-community/background-geolocation` (nagrywanie, `addWatcher`/
`removeWatcher`) + `@capacitor/local-notifications` (alert). Apka BUFORUJE
ślad na urządzeniu (bez nowej tabeli!) i przy zatrzymaniu wysyła go GPX-em na
**istniejące** wejście `POST /admin/moje-przejazdy/solo`
(`SoloRideController::upload`) — za darmo dostaje: pola odkryte, **skarby
zaliczone po drodze bez klikania** (`Treasure::claimAlongTrack` — istniało i
działa), wpis w kronice, punkty. To domyka trzecią drogę znalezienia skarbu,
która dotąd wymagała ręcznego wgrania pliku, i jest jedynym uzasadnieniem
tła, jakie Apple przyjmie — opis w `Info.plist` mówi wprost o tej funkcji,
nie o samym uprawnieniu.

**Powiadomienie „jesteś blisko skarbu"** liczone jest CAŁKOWICIE LOKALNIE
(nie push z serwera — pozycja nie może wychodzić z telefonu w tle): apka
pobiera raz na sesję listę skarbów w okolicy (`GET
/api/discovery/nearby-treasures` → `Treasure::nearbyForAlerts`, jedyne
miejsce z celowym wyjątkiem od bramki `knownCells`) i liczy odległość
własnym haversine w `assets/js/app-tracking.js`. Throttling: jeden alert na
skarb na dobę, tylko przy ruchu — dokładnie próg zapisany tu wcześniej. Na
mapie (`discovery-app.php`, gdy akurat otwarta) zbliżający się skarb dostaje
też pulsujący znacznik, stylem Yanosika (`rm:treasure-near`/`-far`).

Widoczna pastylka „Nagrywam ślad" z przyciskiem stop (i cofnięciem zgody) —
wymóg App Store/Play dla śledzenia w tle. Auto-stop po 20 min bez ruchu.
Android: `useLegacyBridge: true` w `capacitor.config.ts` (bez tego most
JS↔WebView usypia po 5 min w tle — udokumentowana pułapka wtyczki, nie
hipoteza) + `ACCESS_BACKGROUND_LOCATION`/`FOREGROUND_SERVICE(_LOCATION)` w
manifeście. iOS: `NSLocationAlwaysAndWhenInUseUsageDescription` +
`UIBackgroundModes: [location]`.

Zweryfikowane: `php tests/run.php` (325/325, w tym 4 nowe testy
`Treasure::nearbyForAlerts` — precyzja per poziom odsłony i brak bramki
`knownCells`), `npx cap sync android` i `gradlew assembleDebug` (BUILD
SUCCESSFUL, nowe uprawnienia potwierdzone w scalonym manifeście).

**NIEZWERYFIKOWANE — wymaga fizycznego telefonu (brak sprzętu):** realne
dostarczanie pozycji w tle, dostawa powiadomień, zużycie baterii,
przetrwanie ubicia procesu przez system, znane ograniczenie wtyczki
(dławienie żądań HTTP z WebView po 5 min w tle — złagodzone ponowną wysyłką
przy `appStateChange`, ale niezmierzone). iOS w ogóle niebudowany (brak
Maca).

Reguły punktacji przechodzą przez ten sam filtr co reszta gamifikacji —
patrz pamięć „gamification psychology": nowy rowerzysta nie może dostać
wrażenia, że wszystko w okolicy jest już cudze.

### Etap 6 — Aparat (`@capacitor/camera`) — KOD GOTOWY 2026-08-28, niesprawdzony na urządzeniu
Zdjęcia do galerii skarbu robione z apki; na www zostaje `<input type=file>`.
Te same limity co dziś (`TreasurePhoto::PER_USER_LIMIT`) — zero zmian
serwerowych, bo zdjęcie z aparatu ląduje w TYM SAMYM `<input type=file
multiple>` co zawsze (przez `DataTransfer`), więc formularz zostaje zwykłym
multipart POST-em.

**Uwaga ze zweryfikowania kodu (2026-08-28): `/skarby/zglos` NIE MA i nigdy
nie miało pola na zdjęcie** — ani w widoku (`treasure-propose.php`), ani
w kontrolerze (`TreasureProposalController::save()`). Zapis tego dokumentu
„zdjęcia do /skarby/zglos" opisywał funkcję, która nie istnieje na www, więc
nie było czego podpiąć pod aparat bez wymyślania nowego pola od zera —
a to byłaby zmiana zakresu, nie dokończenie Etapu 6. Zaimplementowano więc
WYŁĄCZNIE galerię skarbu (`treasure-scan.php`, `TreasureScanController::addPhoto`),
która realnie istnieje. Jeśli zgłaszanie ma dostać zdjęcie, to osobne zadanie.

Nowa metoda mostu: `RM.native.takePhoto()` (`@capacitor/camera`, metoda
`takePhoto` — NIE przestarzałe `getPhoto`, ta wersja pluginu oznaczyła je
jako deprecated). Przycisk „Zrób zdjęcie" (`data-rm-camera-for="id-inputu"`,
delegacja w `native.js`) tylko w `APP_IS_APP` — w przeglądarce `<input
type=file>` na telefonie i tak proponuje aparat.

Zweryfikowane: `php tests/run.php` (326/326, nowy test sprawdza bramkę
APP_IS_APP i zgodność id przycisku z id inputu — statycznie, bo APP_IS_APP
jest stałą procesu i w testach CLI zawsze fałszywa). Uprawnienia (`CAMERA`
w Android, `NSCameraUsageDescription` w iOS) były już zadeklarowane przy
Etapie 4 z tekstem wprost anticypującym tę funkcję.

**Kryterium (otwarte, wymaga telefonu):** zdjęcie z aparatu ląduje w galerii
skarbu.

### Etap 7 — Offline (`@capacitor/preferences`) — KOD GOTOWY 2026-08-28, niesprawdzony na urządzeniu

**Ekran „brak sieci" — odkryta luka, nie nowa robota.** `app/www/index.html`
istniał od Etapu 3 (2026-08-22) i w treści od dawna obiecywał „zeskanowane
kody nie przepadają — zapiszą się i wyślą, gdy wróci zasięg" — ale
`capacitor.config.ts` nigdy nie miał `server.errorPath`, więc WebView bez
sieci nie pokazywał TEGO pliku wcale, tylko systemowy błąd/pustkę. Jeden
wpis (`errorPath: 'index.html'`) to naprawia.

**Kolejka zeskanowanych kodów** — `assets/js/native.js`. Skan offline NIE
nawiguje (i tak nic by nie pobrał) — `native.queueScan()` dokłada
`{code, lat, lon, queuedAt}` do `@capacitor/preferences` (pozycja to
najlepszy wysiłek, 3 s timeout — offline GPS bywa wolny bez A-GPS z sieci,
`Treasure::claim()` i tak akceptuje brak lat/lon). `przetworzKolejke()`
odpala się przy `Network.addListener('networkStatusChange')` i przy starcie
skryptu (kolejka mogła doczekać zasięgu z zamkniętą apką); wysyła na
**ISTNIEJĄCY** `POST /skarb/{code}` — dokładnie to, co robi przycisk
„Odbierz" — z nowym nagłówkiem `Accept: application/json`, żeby
`TreasureScanController` (nowa metoda `respond()`) oddał `{ok,reason,points,name}`
zamiast pełnej strony (parsowanie HTML-a w JS-ie byłoby kruche). Metoda
zaliczenia zostaje `'QR'`, nie `'GPS'` — świadomie, żeby nie zafałszować
rejestru, po której ścieżce naprawdę przyszło zdarzenie. Udany zaległy skan
kończy się `native.notify()` (most z Etapu 5b) — bez ponownego skanowania,
zgodnie z kryterium.

**„Cache ostatniego stanu kolekcji" — ŚWIADOMIE NIEZROBIONE, konflikt
z decyzją architektoniczną #1 tego dokumentu.** Apka nie ma własnego frontu
(„Alternatywa... oznaczałaby drugi system uprawnień... i duplikat każdego
widoku") — ekran offline (`app/www/index.html`) nie ma dostępu do wtyczek
Capacitora (potwierdzone w dokumentacji `server.errorPath`), więc nie może
odczytać niczego zapisanego przez `@capacitor/preferences` z prawdziwych
stron. Pokazanie tam cache'owanych liczb wymagałoby albo złamania decyzji #1
(kopia widoku w apce), albo nieoczywistego mostu natywnego do statycznego
pliku — obu rozwiązań nie ma sensu zgadywać bez rozmowy. Zostaje jak jest:
ekran mówi „brak połączenia", nie pokazuje starych liczb.

Zweryfikowane: `php tests/run.php` (329/329, w tym 3 nowe testy —
`TreasureScanController::claim` z `Accept: application/json` zalicza,
odrzuca powtórkę tym samym powodem co zawsze, a bez tego nagłówka gałąź JSON
się nie uruchamia — sprawdzone statycznie, żeby nie ukraść pierwszego
renderowania partiala lightboksa innemu testowi w tym samym procesie),
`npx cap sync android` + `gradlew assembleDebug` BUILD SUCCESSFUL,
ręczny `curl` z `Accept: application/json` przez prawdziwy routing.

**Kryterium (otwarte, wymaga telefonu w trybie samolotowym):** skan w trybie
samolotowym zalicza się po włączeniu sieci, bez powtórnego skanowania.

### Etap 8 — Push (`@capacitor/push-notifications`, FCM + APNs) — KOD GOTOWY 2026-08-28, niesprawdzony na urządzeniu

**Nazwa migracji ZMIENIONA względem szkicu**: `push_devices`, nie
`user_devices` — koliduje pojęciowo z `Models\DeviceConnection` (liczniki
rowerowe Garmin/Polar/Wahoo), które w tym serwisie już są „urządzeniami".
Migracja 077. Zgoda = obecność aktywnego wiersza (`is_active`), nie osobna
kolumna — szczegóły w `md/database.md`.

`POST /api/devices/register` + `/unregister` (sesja + CSRF), `Core\Push`
(TEN SAM wzorzec co `Core\Mailer` — driver `'log'`/`'fcm'`, dev pisze do
`storage/push.log`), nadawca `Models\PushNotifier::runTreasuresNearby()`
podpięty pod istniejący `cron.php`, przełącznik zgody w `/admin/moje-konto`.

**Twardy fakt zewnętrzny, nie luka**: w repo NIE MA `google-services.json`
ani klucza APNs — bez nich `driver` zostaje na `'log'` i nic nie wysyła się
naprawdę, dopóki user nie założy projektu Firebase i nie poda pliku konta
serwisowego (`FCM_PROJECT_ID`/`FCM_SERVICE_ACCOUNT_PATH` w env). Android
buduje się bez tego pliku (`google-services` plugin aplikuje się warunkowo,
patrz `app/android/app/build.gradle` — zastane z Etapu 0, nie dopisane
teraz). APNs (iOS) świadomie tylko punkt rozszerzenia (`Core\Push::sendApns()`
rzuca czytelny wyjątek) — bez Maca w tej sesji iOS i tak się nie buduje.

Treści (trzy zaczepienia): **nowy skarb w okolicy**
(`PushNotifier::runTreasuresNearby()`, region skarbu dopasowany do regionów,
w których user ma odkryte pole — świadomie uproszczona definicja „okolicy",
nie promień geograficzny; szanuje `reveal_level`, ukryty skarb nie zdradza
nazwy); **ktoś dołączył do Twojego wyjazdu** (`EventRsvp::join()`, tylko
status potwierdzony, nigdy o zapisie organizatora na własny wyjazd);
**wiadomość na czacie** — doprecyzowanie z sesji: OBA czaty serwisu,
prywatny 1:1 (`Message::send()`) i grupowy kanał wyjazdu
(`EventGroupConversation::postMessage()`); treść wiadomości nigdy nie
wchodzi do push (prywatność powiadomienia systemowego).

**Przy okazji naprawione**: `EventRsvp::join()` wołało `beginTransaction()`
bezwarunkowo — jedyny sposób, żeby w ogóle dało się je przetestować (ten sam
strażnik co `Models\AppLoginToken::consume()`), a przy okazji realny
niezmiennik: metoda była dotąd niewywoływalna z wnętrza cudzej transakcji.

Zweryfikowane: `php tests/run.php` (342/342 poza jednym, wcześniej
istniejącym, niezwiązanym z Etapem 8 — patrz notatka przy Etapie 7 sesji;
14 nowych testów w `tests/push_test.php`), `npx cap sync android` +
`gradlew assembleDebug` BUILD SUCCESSFUL (bez `google-services.json`,
zgodnie z projektem), ręczny `curl` przez prawdziwy routing
(`/api/devices/register`/`unregister` poprawnie odrzucają brak sesji),
`php cron.php` uruchomiony na żywo na bazie dev — nowa linia działa bez
błędu.

**Kryterium (otwarte, wymaga danych od usera i telefonu):** push dochodzi na
fizyczne urządzenie; wyłączenie zgody go zatrzymuje. Do tego: plik konta
serwisowego Firebase + `google-services.json` w `app/android/app/`.

### Etap 9 — Wydanie — CZĘŚCIOWO ZROBIONE 2026-08-29

Cztery różne rzeczy pod jednym numerem, o różnym charakterze:

1. **Opisy uprawnień (w tym tła) — JUŻ KOMPLETNE**, sprawdzone przy
   weryfikacji tego etapu: `NSCameraUsageDescription`,
   `NSLocationWhenInUseUsageDescription`,
   `NSLocationAlwaysAndWhenInUseUsageDescription`, `NSPhotoLibrary*` —
   wszystkie w `Info.plist` z konkretnym tekstem opisującym funkcję, nie
   samo uprawnienie (dopisywane na bieżąco przy Etapach 4/5b/6). Push nie
   wymaga własnego opisu w Info.plist (systemowy dialog bez tekstu z apki).
2. **Kasowanie konta w apce — ZROBIONE.** Decyzja usera 2026-08-29 (węższy
   zakres z dwóch zaproponowanych): samoobsługowy przycisk w
   `/admin/moje-konto` NATYCHMIAST blokuje konto (`Models\User::requestDeletion()`,
   migr. 079, `deletion_requested_at`) i zgłasza je do kolejki ręcznego
   dokasowania w panelu admina (nowy filtr „Do usunięcia"). Nie buduje
   automatycznej anonimizacji treści w ~9 tabelach — świadomie, bo m.in.
   `point_transactions` jest udokumentowanym NIEZMIENNYM rejestrem, a hard
   delete kaskadowałby do cudzych wyjazdów (ta sama zasada, co już stoi
   w `users-admin.php`). Odkryte przy weryfikacji: dotychczasowy link mailto
   „Napisz o usunięcie konta" obiecywał w treści automatyczną
   „anonimizację", której ŻADEN kod nigdy nie realizował.
3. **Polityka prywatności — ZAKTUALIZOWANA, DRAFT DO PRZEJRZENIA.** Nowy
   § 11 „Aplikacja mobilna" w `/prywatnosc` (lokalizacja w tle TYLKO podczas
   aktywnego nagrywania, aparat, token push, kasowanie konta) + rozszerzony
   § 3 (zakres danych). To tekst prawny — napisany rzetelnie, ale user
   powinien go przejrzeć, nie traktować jako gotową opinię prawną.
4. **Materiały do sklepów i wysyłka — POZA ZASIĘGIEM tej sesji.** Zrzuty
   ekranu/grafiki wymagają działającej apki na telefonie (żaden nie jest
   podpięty przez całą tę przebudowę); wysyłka do App Store/Google Play
   wymaga kont deweloperskich usera. To realna praca usera, nie coś, co dało
   się „zrobić kodem".

Zweryfikowane: `php tests/run.php` (353/354 — poza znanym niezwiązanym fail-em,
patrz notatka przy Etapie 7 sesji; 11 nowych testów), `curl` przez prawdziwy routing (`/admin/moje-konto/usun`
poprawnie przekierowuje niezalogowanego na `/logowanie`, `/prywatnosc`
renderuje się bez błędu).

**Niezweryfikowane, bo niemożliwe w tej sesji:** cały przepływ na
fizycznym urządzeniu (jak każdy poprzedni etap), realne wysłanie do
sklepów.

## Poza zakresem
- Zamiana serwisu w PWA (decyzja 5).
- Przepisywanie widoków na natywne — apka renderuje istniejący HTML.
- Zmiany w modelu uprawnień, CSRF i bramkach ujawnienia skarbów (§6 CLAUDE.md).

---

## PODPIS WYDANIA I NUMERACJA (2026-08-29)

**Magazyn kluczy: `app/android/ridemore-release.jks`, hasło w
`app/android/keystore.properties`.** Oba pliki są w `.gitignore` i mają w nim
własne wpisy — magazyn łapie `*.jks`, plik z hasłem musiał dostać wpis osobno,
bo ma inne rozszerzenie, a niesie to samo ryzyko.

**TA PARA PLIKÓW JEST TOŻSAMOŚCIĄ APLIKACJI.** Android pozwala zaktualizować
zainstalowaną apkę wyłącznie plikiem podpisanym TYM SAMYM kluczem. Zgubienie
ich znaczy: nikt z zainstalowaną apką nie dostanie już aktualizacji, a jedynym
wyjściem jest odinstalowanie i instalacja pod nową tożsamością (z utratą
danych lokalnych: zgody na nagrywanie, bufora śladu, kolejki skanów).
Kopia zapasowa POZA tym komputerem jest obowiązkowa, nie zalecana.

`app/build.gradle` czyta te dane warunkowo (`if (plikPodpisu.exists())`), więc
świeży klon repozytorium bez magazynu dalej się buduje — tyle że wydanie
wychodzi bez podpisu, zamiast wywalać build komunikatem o brakującym pliku.

**Numeracja:** `versionCode` (liczba dla systemu, rośnie o 1 przy każdym
wydaniu — po niej Android poznaje nowszą wersję) i `versionName` (to, co widzi
człowiek w ustawieniach telefonu). Stan: `versionCode 2`, `versionName 0.2.0`.
Szablon Capacitora zostawiał `1` / `1.0` i nikt tego nie ruszał do tej pory.

**Uwaga przy pierwszej instalacji wydania:** APK podpisany tym kluczem NIE
zainstaluje się na telefonie, który ma wcześniejszą wersję deweloperską
(inny podpis). Trzeba najpierw odinstalować starą apkę.
