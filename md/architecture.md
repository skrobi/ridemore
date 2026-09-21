# Architektura i konwencje

Własny mini-MVC w PHP 8.1, bez frameworka. Dwie zależności runtime (Composer):
`league/oauth2-client` (logowanie społecznościowe) i `endroid/qr-code` (naklejki
skarbów — używane wyłącznie przez `Utils\Qr`). Alpine.js + Leaflet z CDN. Serwer: XAMPP/Apache
lokalnie, hosting współdzielony (cPanel) na prod.

## Cykl żądania

1. **Apache** przepisuje wszystko na `index.php` (mod_rewrite; katalogi `admin/`,
   `api/`, `web/` zawierają TYLKO `routes.php`, nie są prawdziwymi ścieżkami URL).
2. [`index.php`](../index.php) → `require core/bootstrap.php`, potem wg prefiksu URL
   (`Router::stripBasePath`) ładuje:
   - `/api/...` → [`api/routes.php`](../api/routes.php) (odpowiedzi JSON)
   - `/admin/...` → [`admin/routes.php`](../admin/routes.php) (globalny `Auth::requireLogin()` na górze)
   - reszta → [`web/routes.php`](../web/routes.php) (strony HTML)
3. **bootstrap** ([`core/bootstrap.php`](../core/bootstrap.php)): definiuje `CORE_PATH`,
   rejestruje autoloader dla `core/`, ładuje `vendor/autoload.php` (Composer, jeśli jest),
   ustawia `APP_ENV` (`dev`|`prod`) i `APP_CONFIG`, konfiguruje sesję, rejestruje
   `set_exception_handler`.
4. Plik tras tworzy `new Router()`, rejestruje trasy i woła `$router->dispatch(...)`.
5. `Router::dispatch` dopasowuje **liniowo** (pierwsza pasująca wygrywa), wyciąga
   parametry `{slug}` i woła handler: `[Kontroler::class, 'metoda']` **albo** domknięcie.
6. Kontroler → modele → zasoby → `View::render()` → widok w layoucie.

## Usługi rdzenia — `core/Core/*`

| Klasa | Rola | Kluczowe metody |
|---|---|---|
| [`Core\Router`](../core/Core/Router.php) | Dopasowanie tras, `stripBasePath` (zdejmuje `/ridemore` na dev, `` na prod). Liniowe, `{param}` → regex. Na 404 zwraca JSON (w dev z listą tras). | `get/post/dispatch`, `stripBasePath` |
| [`Core\Auth`](../core/Core/Auth.php) | Sesja logowania, cache usera per-request. `login()` regeneruje id + obsługuje „Zapamiętaj mnie" (30 dni). **Konto zablokowane (migr. 058) jest wylogowywane w `user()`** — blokada działa też na sesjach, które trwały w chwili jej nałożenia; drugą bramkę ma `AuthController::login`. | `login/logout/check/user/requireLogin/requireAdmin` |
| [`Core\Database`](../core/Core/Database.php) | Singleton PDO. `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`. | `connection(): \PDO` |
| [`Core\Csrf`](../core/Core/Csrf.php) | Token CSRF w sesji. `field()` = gotowy `<input hidden>`. | `token/check/field` |
| [`Core\Session`](../core/Core/Session.php) | **Zwolnienie blokady sesji na ODCZYTACH** (2026-09-02). PHP trzyma plik sesji na wyłączność przez całe żądanie, więc kilkadziesiąt kafli jednego kadru stało w kolejce jedno za drugim (zmierzone: 5 kafli równolegle 0,97 s bez ciasteczka sesji, 3,10 s z nim). Woła to `TileController::show()` (po sprawdzeniu uprawnień) i `api/routes.php` dla **GET-ów** (po `Auth::user()`, bo wylogowanie konta zablokowanego to zapis do sesji). Po zwolnieniu `$_SESSION` nadal się CZYTA — nie wolno tylko polegać na ZAPISIE, dlatego żaden POST tego nie robi. | `release()` |
| [`Core\Mailer`](../core/Core/Mailer.php) | Wysyłka maili SMTP (`Utils\MailTemplate` renderuje treść). Od Etapu 1c (2026-09-11) przyjmuje **dodatkowe nagłówki** — dokłada je `Models\Notifier` (`List-Unsubscribe`, RFC 8058); wartości są czyszczone z CR/LF, bo w SMTP pusta linia kończy blok nagłówków i zaczyna treść. Driver `log` też je wypisuje, żeby dało się je sprawdzić na dev. **Skrzynka jest prawdziwa TAKŻE na dev** — dlatego `tests/run.php` wymusza `MAIL_DRIVER=log` przed bootstrapem. | `sendTemplate(page, to, subject, data, headers = [])` |
| [`Core\Lang`](../core/Core/Lang.php) | **Język żądania i prefiks `/en/…`** (2026-09-16). Jedyne miejsce, które zna prefiks: `Router::stripBasePath` go zdejmuje, `View::url/absoluteUrl` dokleja. Polski bez prefiksu. Wysyłki do innych osób w `with()`. Szczegóły: `md/features.md` → Wielojęzyczność. | `current/set/with/splitPath/localizePath/alternates/switchUrl/t/plural/guess` |
| [`Core\Push`](../core/Core/Push.php) | Push do apki mobilnej (Etap 8, 2026-08-28) — TEN SAM wzorzec co `Mailer`: driver `'log'`/`'fcm'` (`core/config.php['push']`), dev pisze do `storage/push.log`. FCM HTTP v1 w pełni (JWT RS256 własnym `openssl_sign`); APNs (iOS) świadomie tylko punkt rozszerzenia. Patrz `md/database.md` (`push_devices`). | `sendToUser(userId, title, body, data)` |

> Uwaga na namespace: usługi rdzenia są w `Core\` (`core/Core/*`), ale helpery
> renderujące/formatujące są w `Utils\` (`core/Utils/*`) — w tym `Utils\View`.
> `Database` = `Core\Database::connection()`, NIE `Core\Database::pdo()`.

## Config i środowiska — [`core/config.php`](../core/config.php)

- Zwraca tablicę `['dev' => [...], 'prod' => [...]]`; `bootstrap` wybiera wg `APP_ENV`
  (env, domyślnie `dev`) i utrwala w stałej `APP_CONFIG`.
- Klucze środowiska: `base_path` (`/ridemore` dev, `` prod), `app_url`, `db`, `mail`, `oauth`.
- Sekrety: wzorzec `$env('KLUCZ', fallback)` — czyta z env, fallback w pliku. Docelowo
  hasła/sekrety mają iść z env serwera, fallbacki skasować.
- **`APP_IS_APP`** (2026-08-22) — trzecia stała obok `APP_ENV`/`APP_CONFIG`: czy żądanie
  przyszło z aplikacji mobilnej (Capacitor), rozpoznane po dopisku `ridemore-app`
  w User-Agencie. Apka ładuje TEN SAM serwis pod tym samym adresem, więc sesja, CSRF
  i bramki uprawnień działają bez zmian — stała steruje wyłącznie layoutem
  (`is-app`, dolny pasek) i trwałością cookie sesji. **Definiowana PRZED
  `session_start()`**, bo w apce nie ma „zamknięcia przeglądarki", tylko ubicie procesu:
  cookie sesyjne by przepadło, więc apka dostaje trwałe z automatu, jakby zaznaczyła
  „Zapamiętaj mnie" (druga połowa tej decyzji jest w `Core\Auth::login`).
  Kontrakt: [`tasks/active/apka-mobilna.md`](../tasks/active/apka-mobilna.md).
- **Dev DB**: `ridemorebike2` / user `skrobi` (host `127.0.0.1`).
- **Prod DB**: `rideyvwv_ridemore_v2` / `rideyvwv_skrobi`.

## Konwencje kodu (trzymaj się ich)

- **Kontrolery i modele = klasy ze statycznymi metodami.** Brak DI, brak instancji
  kontrolerów. Trasa wskazuje `[Foo::class, 'bar']` i `bar()` jest `public static`.
- **Modele encji** (`User`, `Event`, `Organizer`, `EventEdition`, `EventPricing`,
  `UserBillingProfile`, `OrganizerBillingProfile`) mają publiczne właściwości i prywatny
  `fromRow(array): self`; findery są statyczne (`find`, `findBySlug`, ...). Reszta modeli
  to czyste zbiory statycznych operacji na tabelach (bez instancji).
- **PDO wszędzie ręcznie**: `Core\Database::connection()->prepare(...)->execute([...])`.
  Prepared statements z prawdziwymi (nie emulowanymi) placeholderami — **nie da się użyć
  tego samego nazwanego parametru dwa razy** w jednym zapytaniu (patrz `/api/organizers/search`).
- **Każdy wewnętrzny URL przez `Utils\View::url('/sciezka')`** — od 2026-09-03 zwraca PEŁNY adres (`http://host` + `base_path` + ścieżka), z hostem i schematem BIEŻĄCEGO żądania; `app_url` z configu jest zapasem dla CLI. Adres kanoniczny (maile, push, canonical, og:image, sitemapa) dalej składa `View::absoluteUrl()` — patrz [`utils.md`](utils.md).
  Assety przez `View::asset()` (dokleja `?v=mtime`). Pełne adresy (maile) przez `View::absoluteUrl()`.
- **Każdy `<form method=post>` musi zawierać `Csrf::field()`**; POST-handler woła `Csrf::check()`.
- **Renderowanie**: `View::render('web'|'admin', 'nazwa-strony', $data)`. `$data` jest
  `extract()`owane w widoku; widok buforuje HTML do `$content`, layout go wkłada.
- **Słowniki** zamiast enumów w kodzie: typy roweru, trudność, tempo, region, waluta itd.
  żyją w tabelach `dictionaries`/`dictionary_items` i chodzą przez `Models\Dictionary`.
  Formularze przez `Support::eventFormDictOptions()`.
- **Język**: kod, komentarze i UI po polsku. Kod ma gęste komentarze „dlaczego" —
  czytaj je, tłumaczą nieoczywiste decyzje.

## Dev vs Prod — o czym pamiętać

- `run_migrations.php` jest **wyłącznie produkcyjny** (`putenv('APP_ENV=prod')` w środku).
  **Nigdy nie odpalaj go lokalnie.** Lokalnie migracje wgrywasz SQL-em wprost do
  `ridemorebike2` (patrz [`database.md`](database.md)).
- Handler błędów: w `dev` zwraca pełny JSON z wyjątkiem (`type/message/file`, `stage` =
  `database`|`application`|`routing`); w `prod` maskuje i loguje do `error_log` hostingu.
- Sesje: własny katalog `storage/sessions` + długi `gc_maxlifetime`, żeby cron hostingu
  nie kasował plików sesji zalogowanych (bug „po chwili wylogowuje" na prod).
- Analytics (gtag) i inne rzeczy tylko-prod bramkowane `APP_ENV === 'prod'` w layoucie.
- Leaflet/Alpine z CDN — brak buildu, brak `npm`. Zmiany CSS/JS to edycja plików w `assets/`.

## Zadania okresowe — [`cron.php`](../cron.php)

**DWA WPISY W HARMONOGRAMIE, NIE JEDEN** (2026-09-12). Skrypt bierze grupę zadań
jako pierwszy argument:

```
APP_ENV=prod php /pełna/ścieżka/cron.php noc      # utrzymanie, przeliczenia, maile
APP_ENV=prod php /pełna/ścieżka/cron.php dzien    # zachęty (push + mail)
APP_ENV=prod php /pełna/ścieżka/cron.php tlumaczenia  # co ~10 min, kolejka tłumaczeń AI (tylko przy >1 języku)
php cron.php                                      # WSZYSTKO (dev, ręcznie)
```

- **`noc`** (9 zadań): `Event::processCompletions`, `Event::expireStalePendingPayments`,
  `Upload::cleanupOrphanedGpxTemp`, `MatchEngine::runNightlyConsolidation`,
  `DerivedPreference::recomputeAll`, `RecommendationLog::purgeOld`,
  `PreferenceNotifier::runAspirational`/`runRegular`, `Emblem::sync`.
- **`dzien`** (2 zadania): `PushNotifier::runTreasuresNearby`, `runNewInRegion`.

**Po co rozdział.** Od migr. 081 zachęty nie wychodzą PUSHEM między 22:00 a 7:00
(`NotificationGate::CISZA_OD`/`CISZA_DO`), więc jeden nocny wpis znaczył „zero
pushy o skarbach i nowościach" — cicho, bo mailowa połowa wychodziła normalnie
(migr. 082 zawęziła ciszę do pusha). Maile zostały w nocy świadomie: cisza ich
nie dotyczy, a `PreferenceNotifier` MUSI biec po `DerivedPreference::recomputeAll()`
w tym samym przebiegu, bo czyta profil z niego.

**`APP_ENV` musi przyjść ze środowiska** — ten plik celowo go nie wymusza (działa
na dev i na prod), a `.htaccess`/`SetEnv` nie obowiązuje CLI. Pierwsza linia
wyjścia podaje `APP_ENV`, nazwę bazy i grupę, żeby zły wpis w crontabie było
widać w logu od razu (ten sam zwyczaj co w `run_migrations.php`).

**Tylko CLI.** `cron.php` ma bramkę `PHP_SAPI !== 'cli'` i jest na liście
`FilesMatch` w `.htaccess`. Do 2026-09-12 nie miał żadnego z tych dwóch
zabezpieczeń, czyli `https://.../cron.php` był publicznym spustem wysyłki
maili i pushy — chronionym wyłącznie tym, że żądanie HTTP nie ma `APP_ENV`
i spadało na konfigurację nieistniejącą na produkcji.

**Nieudany przebieg kończy się kodem 1** (2026-09-12). To była najdroższa cicha
usterka tego modułu: `set_exception_handler` w bootstrapie kończy skrypt
NORMALNIE, więc cron bez połączenia z bazą meldował **sukces** — a harmonogram
na hostingu powiadamia tylko przy kodzie niezerowym. Zmierzone przed naprawą:
`DB_NAME_DEV=<zła baza> php cron.php noc` → kod **0**. Handler ma teraz gałąź
`PHP_SAPI === 'cli'`, która pisze przyczynę na STDERR i kończy kodem 1; stoi
PRZED `header()`, bo pod CLI ten drugi sypie ostrzeżeniem o nagłówkach.
Ścieżka HTTP jest nietknięta. Zyskują wszystkie skrypty CLI, nie tylko cron.

**Jeden przebieg grupy naraz** — `flock` na `storage/cron-{grupa}.lock`
(wzorzec z `Utils\RateLimiter`). `LOCK_NB`, więc drugi przebieg mówi „już trwa"
i kończy kodem **3**, zamiast czekać w kolejce i wystartować o nieswojej porze.
Blokada jest per grupa, bo wiszący przebieg nocny nie ma prawa zabrać
popołudniu jego pory. Niemożność ZAŁOŻENIA blokady nie przerywa pracy, tylko ją
odnotowuje: `flock` bywa zawodny na sieciowych systemach plików, a blokada ma
chronić przed nachodzeniem, nie zostać nowym powodem awarii.

Kody wyjścia: **0** sukces · **1** awaria · **2** zły argument grupy ·
**3** przebieg tej grupy już trwa.

`processCompletions` jest też wołane oportunistycznie przy wejściu na stronę główną.

## Testy

`tests/` — własny mini-runner, bez PHPUnit (projekt nie ma frameworka, patrz
`core/bootstrap.php`).

```
php tests/run.php            # wszystkie zestawy
php tests/run.php skarby     # tylko pliki pasujące do wzorca
```

- `tests/lib.php` — asercje (`t_eq`, `t_true`, `t_count`, `t_null`…) i fabryki
  danych (`t_user`, `t_category`, `t_treasure`).
- `tests/run.php` — uruchamiacz. **Każdy test biegnie we własnej transakcji,
  która zawsze jest wycofywana**, więc baza DEV po przebiegu wygląda tak samo jak
  przed nim. Kod wyjścia: 0 = wszystko przeszło, 1 = są błędy, 2 = zła
  konfiguracja. Odmawia startu poza `APP_ENV=dev`.
- `tests/*_test.php` — zestawy. Nowy plik nie wymaga rejestracji nigdzie, wystarczy
  nazwa kończąca się na `_test.php` i wywołania `t_test('opis', function () { … })`.
- `tests/dashboard.php` — dashboard testów: **web UI** (tylko APP_ENV=dev,
  poza tym 403; wyjątek w `tests/.htaccess` — reszta katalogu zablokowana)
  z przyciskami „Uruchom wszystkie” / per zestaw, logami wykonawczymi
  (rozwijane `<details>`) i historią 50 przebiegów (`tests/results/history.json`,
  gitignored). Zestawy odpala osobnymi procesami `php tests/run.php <nazwa>`,
  więc błąd jednego nie przerywa reszty. CLI: `php tests/dashboard.php [--open]`
  — to samo z linii poleceń.

**Ograniczenie:** kod pod testem nie może sam otwierać transakcji ani wykonywać
DDL — MySQL zatwierdziłby wtedy transakcję zewnętrzną i wycofanie przestałoby
działać.

Zestawy: `skarby_test.php` (zaliczanie QR/GPS/GPX, idempotencja rejestru
punktów, poziomy ujawnienia, potwierdzenia społeczności, statystyki, Puls),
`dodawanie_skarbow_test.php` (tworzenie skarbów z minimalną ilością danych,
stawki trzech rzadkości COMMON/RARE/EPIC, zaliczenie i naliczanie punktów,
wycofanie jako „usunięcie" skarbu bez ruszania niezmiennego rejestru),
`uzytkownicy_test.php` (blokada, odblokowanie, kasowanie tylko pustych kont),
`dopasowania_test.php` i `preferencje_test.php` (przeniesione 2026-08-15
z płaskich skryptów `test_*.php` w katalogu głównym — te już nie istnieją),
`dodawanie_tras_test.php` (warianty dodawania znanych tras z różną ilością
informacji: od samej nazwy+GPX po komplet z opisem/regionem/zdjęciem, slugi,
progi parsera GPX), `znane_trasy_test.php` (widoczność na mapie, edycja,
panel).

## Pliki/katalogi na obrzeżach

- `backfill_*.php`, `rescale_uploads.php` — jednorazowe skrypty dev.
  (Skrypty `test_match_engine.php` i `test_preferences.php` zostały przeniesione
  do `tests/` i skasowane — 2026-08-15.)
- [`tiles.php`](../tiles.php) — narzędzie CLI kafli map (migr. 051):
  `php tiles.php stats|backfill|prune|purge`. **Tylko z linii poleceń** (`PHP_SAPI`
  sprawdzane w pliku, dodatkowo blokada w `.htaccess`). Wszystko idempotentne,
  kafle są w pełni odtwarzalne. Ten sam reset z przeglądarki: `/admin/kafle` — tam też
  przycisk **„Policz geometrię wszystkich śladów"** (odpowiednik `tiles.php backfill`,
  dodany 2026-08-20, bo na hostingu bez shella nie było do tego żadnej drogi).
- `ai-engine/` — dwa skrypty Pythona wołane z PHP wspólnym mostem `Utils\PythonBridge`:
  `analyze.py` (silnik AI importera — Groq/Gemini/Ollama, przez `Utils\AiEngineBridge`,
  **tylko dev**, klucz `ai_engine` nie istnieje na `prod`) i `garmin.py` (import
  przejazdów z Garmin Connect, przez `Utils\GarminBridge`, **od 2026-09-04 też na
  `prod`**, zweryfikowane żywym logowaniem — inny, starszy pin pakietów niż na dev,
  bo tamten hosting ma max Python 3.9, patrz `requirements-namecheap-py39.txt`).
  `ridemore-event-importer/` — rozszerzenie Chrome, które używa tego pierwszego.
  Wszystko opisane w [`features.md`](features.md).
- `nbproject/` — NetBeans. `sw.js` — pozostały service worker, **świadomie nieużywany**
  (layout celowo nie ładuje SW/manifestu). `storage/` — sesje, rate-limit, logi maili,
  cache nawierzchni (`surface-cache`), chronione `storage/.htaccess`.
  `szablony/` — statyczne mockupy HTML (źródło designu).
- `assets/tiles/` — wygenerowane kafle map (cache na dysku, kasowalny). Adres:
  `assets/tiles/{warstwa}/{klucz}/{z}/{x}/{y}.png`, warstwy `slady`/`hex`, klucze:
  `all`, `me`, `u-{slug}`, `e-{id}` (turnus), `ev-{id}` (trasy zapowiadane
  wydarzenia), `kr-{id}` (jedna znana trasa) i `kr` (**wszystkie aktywne znane
  trasy** — od 2026-08-20 jedyny sposób rysowania warstwy „Trasy" na mapach).
- `vendor/` — Composer (`league/oauth2-client`, `endroid/qr-code`).

### Co NIE wychodzi przez HTTP — i dlaczego to trzeba deklarować

**Cała aplikacja leży w katalogu serwowanym, a główny `.htaccess` przepuszcza
ISTNIEJĄCE pliki** (`RewriteCond %{REQUEST_FILENAME} !-f` — bez tego nie dałoby
się serwować kafli z dysku). Skutek: **każdy plik w repozytorium jest domyślnie
publiczny**, dopóki nie dostanie blokady. Nie ma tu „bezpiecznego domyślnie".

Blokady per katalog, wzorzec `Require all denied` + `Deny from all` (obie składnie
Apache, bo hosting bywa 2.2 albo 2.4): `storage/`, `tests/` (z wyjątkiem
`dashboard.php`), `ai-engine/`, `app/`, `assets/uploads/` (tam blokada WYKONYWANIA
PHP, nie odczytu) oraz — **od 2026-09-12** — `core/`, `migration/`, `md/`, `tasks/`.
W głównym `.htaccess`: `FilesMatch` na skrypty CLI (`run_migrations`,
`backfill_elevation_profiles`, `tiles`, `cron`) i na manifesty zależności.

**Czego brakowało do 2026-09-12** (zmierzone `curl`-em, nie założone):
`/migration/schema.sql` oddawał **89 703 B** pełnego schematu bazy, każda
migracja osobno, `/md/database.md` — **65 837 B** dokumentacji wewnętrznej,
`/tasks/README.md` — stan prac. Pliki PHP w `core/` oddawały 200 i ZERO bajtów,
bo PHP je WYKONYWAŁ — czyli zabezpieczeniem był tam wyłącznie fakt, że
interpreter działa. Gdyby obsługa PHP dla tego katalogu kiedyś przestała
działać, Apache oddałby `core/config.php` jako tekst, z hasłem SMTP, sekretem
HMAC wypisu i danymi bazy w wartościach zapasowych.

Pilnuje tego `tests/widoki_test.php` (sekcja „CO NIE MA WYCHODZIĆ PRZEZ HTTP") —
sprawdza istnienie blokad i OBIE składnie, bo blokada napisana tylko w jednej
jest na drugim Apache cicho nieobecna.
- `app/` — **aplikacja mobilna (Capacitor 8)**, od 2026-08-22. Osobny świat: własny
  `package.json` i `node_modules`, zero związku z PHP-em poza tym, że WebView ładuje
  ten serwis. Odcięty od HTTP własnym `.htaccess` (leży pod `htdocs`, więc bez tego
  Apache serwowałby `node_modules`). Szczegóły niżej.

## Aplikacja mobilna — `app/` (Capacitor)

**Apka nie ma własnego frontu.** `server.url` w [`app/capacitor.config.ts`](../app/capacitor.config.ts)
wskazuje `https://ridemore.bike`, więc WebView wyświetla żywy serwis, cookie sesji jest
first-party i `Core\Auth`/CSRF działają bez jednej zmiany. Serwis rozpoznaje apkę po
dopisku `ridemore-app` w User-Agencie (`appendUserAgent`) → stała `APP_IS_APP` (wyżej).
**Ten ciąg jest sprzężony z bootstrapem** — zmiana w jednym miejscu wyłącza tryb apki.

W `app/www/index.html` leży JEDYNA strona wożona w apce: ekran „brak połączenia".
Nie jest to okrojony serwis i nie ma nim być — bez niego brak zasięgu daje biały ekran.

Uprawnienia mieszkają w plikach natywnych, nie w konfiguracji Capacitora:
`app/android/app/src/main/AndroidManifest.xml` i `app/ios/App/App/Info.plist`
(opisy po polsku — Apple czyta je jako odpowiedź na „po co"). Zasada: uprawnienie
wchodzi razem z etapem, który je wykorzystuje. Lokalizacji w tle **jeszcze nie ma**.

Polecenia (z katalogu `app/`, wymaga Node):

| Cel | Polecenie |
|---|---|
| Po zmianie konfiguracji/pluginów | `npx cap sync` |
| Otworzyć projekt w Android Studio | `npx cap open android` |
| Zbudować i wgrać na podpięty telefon | `npx cap run android` |
| Wejść apką na LOKALNY serwer | `set RIDEMORE_APP_URL=http://<IP-maszyny>/ridemore` przed `npx cap sync` |

Adres testowy idzie **zmienną środowiskową, nie edycją pliku** — wpisany w konfigurację
prędzej czy później pojechałby z buildem do sklepu. Zmienna włącza też `cleartext`
(lokalny XAMPP jest po http).

**Wymagania builda: Node LTS i JDK 21 — dokładnie 21.** Wtyczki Capacitora 8 deklarują
toolchain `languageVersion=21`, a Gradle dopasowuje WERSJĘ, nie „21 lub wyżej": ani
JDK 17, ani JDK 25 wbudowane w Android Studio tego nie spełniają (zmierzone —
build padał na `capacitor-camera` z „Cannot find a Java installation ... matching
{languageVersion=21}"). Na tej maszynie: `C:\Program Files\Eclipse Adoptium\jdk-21…`.

Android SDK: `compileSdk`/`targetSdk` **36**, minSdk 24 (`app/android/variables.gradle`).
Brakującej platformy NIE trzeba dobierać ręcznie — Gradle sam ją pobiera i akceptuje
licencję przy pierwszym buildzie (tak wszedł android-36 obok zainstalowanego 37).
Pierwszy build: ok. 2,5 min, wynik w `app/android/app/build/outputs/apk/debug/`.

Kontrakt i stan prac: [`tasks/active/apka-mobilna.md`](../tasks/active/apka-mobilna.md).
