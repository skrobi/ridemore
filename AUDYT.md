# Audyt oprogramowania — ridemore.bike

- **Data audytu:** 2026-08-19
- **Zakres:** cała aplikacja (PHP mini-MVC, frontend, baza, wdrożenie)
- **Metoda:** dokumentacja `md/` (mapa kodu, decyzje, pomiary) + weryfikacja kodu
  (`core/config.php`, `bootstrap.php`, `Auth`, `Csrf`, `Router`, `Mailer`, `Upload`,
  widoki) + uruchomienie testów (58/58 PASS) + przegląd repozytorium git
- **Środowisko weryfikacji:** PHP 8.1.12 (XAMPP), baza dev `ridemorebike2`

---

## 1. Werdykt (streszczenie)

Aplikacja jest dojrzała jak na własny mini-framework: **bardzo dobra dokumentacja,
przemyślane niezmienniki bazodanowe (idempotencja na kluczach unikalnych, nie w kodzie),
konsekwentne zabezpieczenia (CSRF, PRG, POST-only dla akcji, whitelisty powrotów),
testy przechodzące 58/58** (zweryfikowane w trakcie audytu) oraz mierzona i opisana
wydajność.

Równocześnie audyt znalazł **jedno znalezisko krytyczne i kilka poważnych**:

| # | Znalezisko | Powaga |
|---|---|---|
| 1 | **Sekrety produkcyjne w `core/config.php` — plik zstagedowany w gicie** (hasło DB prod, hasło SMTP, secret OAuth Google) | **KRYTYCZNA** |
| 2 | **Repozytorium git ma 0 commitów** — cały kod czeka zstagedowany na pierwszy commit; brak historii, punktu przywracania i śladu zmian | **KRYTYCZNA** (proces) |
| 3 | PHP 8.1 — koniec wsparcia bezpieczeństwa (31.12.2025) | WYSOKA |
| 4 | `Mailer` wyłącza weryfikację certyfikatu TLS (`verify_peer=false`) | WYSOKA |
| 5 | Brak rate-limitu na logowaniu (jest na rejestracji/reset/claim/cover-url) | WYSOKA |
| 6 | `schema.sql` niekompletny (brak 8 tabel z migracji 025–027) — świeża instalacja się wywraca | ŚREDNIA |
| 7 | Brak nagłówków bezpieczeństwa (X-Frame-Options, CSP, HSTS, Referrer-Policy) | ŚREDNIA |
| 8 | Brak CI — testy nie biegają automatycznie; brak testów kluczowych ścieżek (auth, zapisy/płatności, wiadomości, event CRUD) | ŚREDNIA |

---

## 2. Architektura i jakość kodu — mocne strony

- **Czysta, udokumentowana warstwowość**: routes → kontrolery (statyczne metody) →
  modele (PDO) → resources (kształt danych) → widoki (`View::render` + layout).
  Trzy zbiory tras (web/api/admin) z jawnymi pułapkami kolejności rejestracji.
- **Dokumentacja w `md/` jest wyjątkowa** — mapa kodu, uzasadnienia decyzji
  („dlaczego", nie „co"), zmierzone liczby (wydajność, błędy), opisane pułapki
  (np. `EMULATE_PREPARES=false` → zakaz powtarzania nazwanego placeholdera).
- **Idempotencja i niezmienniki w bazie, nie w kodzie**: `UNIQUE(user_id, cell_id)`
  na `discovery_cells`, `UNIQUE(treasure_id, user_id)` na `treasure_finds`,
  `UNIQUE(user_id, source, source_id)` na `point_transactions` — powtórzenie operacji
  jest bezkosztowe z definicji.
- **Bezpieczny upload**: whitelista MIME (JPEG/PNG/WebP), skalowanie GD, losowe nazwy,
  ochrona SSRF przy pobieraniu okładki z URL (publiczne IP, zakaz przekierowań,
  weryfikacja treści po pobraniu) — świadomie udokumentowane (DNS rebinding zaakceptowany).
- **Konsekwentne wzorce bezpieczeństwa**: `Csrf::field()`/`Csrf::check()` (hash_equals),
  `session_regenerate_id` przy logowaniu, cookie `httponly` + `SameSite=Lax` + `secure`
  na HTTPS, blokada konta działa na żywych sesjach (`Auth::user()`), hasła przez
  `password_hash`/`password_verify`, akcje ze skutkami ubocznymi tylko POST,
  whitelisty powrotów zamiast adresów z żądania (anty open-redirect).
- **Ucieczka wyjścia w widokach** — próbka kontrolna potwierdza `htmlspecialchars()`
  na danych użytkownika (mailach, formularzach, listach).
- **Wydajność mierzona i broniona**: kafle map (54 ms → 1,8 ms; Apache oddaje
  istniejące z dysku), cache pól GPX po hashu zawartości, budżet linii na profilu,
  agregacja dwuetapowa mapy, limit `MAX_TILES` pod i-węzły hostingu.
- **Testy**: własny mini-runner, transakcje wycofywane po każdym teście, 58 testów
  w 5 zestawach — wszystko przechodzi (zweryfikowane: `OK: 58 przeszło, 0 nie przeszło`).
- **Migracje**: 62 numerowane pliki, runner tylko-prod z rejestrem `schema_migrations`,
  backfill jako osobne pliki (świadoma ochrona przed częściowym zastosowaniem).

---

## 3. Bezpieczeństwo — znaleziska

### 3.1 KRYTYCZNE: sekrety produkcyjne w pliku, który jest w indeksie gita

`core/config.php` (linie 22, 35, 52, 88) zawiera wprost:

- hasło **produkcyjnej bazy danych** (`DB_PASS_PROD`),
- hasło **SMTP** (`SMTP_PASSWORD`),
- **client secret OAuth Google** (`GOOGLE_CLIENT_SECRET`),
- hasło dev DB.

Komentarz w pliku nazywa to stanem przejściowym („docelowo fallbacki skasować"),
ale faktem jest, że **plik został `git add`-nięty** (`git status` → `A core/config.php`).
Pierwszy commit — a repozytorium nie ma jeszcze żadnego — utrwali te sekrety w historii
na zawsze.

**Rekomendacja (priorytet 0):**
1. Rotacja wszystkich trzech sekretów produkcyjnych (DB prod, SMTP, Google OAuth) —
   traktować je jako skompromitowane.
2. Wszystkie sekrety wyłącznie przez zmienne środowiskowe serwera (cPanel → env);
   z `config.php` usunąć fallbacki.
3. Przed pierwszym commitem: `git rm --cached` nie wystarczy (treść była w indeksie),
   sprawdzić `git log --all` po commicie; najlepiej commitować dopiero po rotacji.
4. Dodać do `.gitignore` przykładową wersję `config.local.php` albo skrypt generujący
   config z env.

### 3.2 KRYTYCZNE (proces): repozytorium bez ani jednego commita

`git log` → „branch 'master' does not have any commits yet". Cały kod (w tym
`core/config.php`) jest zstagedowany. Skutki: brak możliwości rollbacku, blame,
przeglądu zmian, porównania z wersją produkcyjną (na prod jest kod, którego
repo nie opisuje). **Rekomendacja: niezwłocznie ustanowić baseline (commit), po
wcześniejszej rotacji sekretów (3.1).**

### 3.3 WYSOKA: PHP 8.1 po końcu wsparcia

Środowisko dev to PHP 8.1.12 (XAMPP). Oficjalne wsparcie bezpieczeństwa PHP 8.1
zakończyło się 31.12.2025. Brak łat = niezałatane CVE w rdzeniu przy każdym nowym
publikowanym podatności. **Rekomendacja: sprawdzić wsparcie hostingu (cPanel zwykle
oferuje 8.2/8.3), zaplanować migrację + testy; na dev odświeżyć XAMPP.**

### 3.4 WYSOKA: Mailer bez weryfikacji TLS

`Mailer::sendSmtp()` ustawia globalnie `verify_peer=false`, `verify_peer_name=false`,
`allow_self_signed=true` (komentarz: „część hostingów ma certyfikaty, których PHP
domyślnie nie zaakceptuje"). Przy haśle SMTP zdradzonym w repo (3.1) połączenie
`hasło + brak weryfikacji` to prosta droga do przechwycenia. **Rekomendacja:**
włączyć weryfikację i pinować certyfikat/fingerprint serwera SMTP albo przynajmniej
wyłączyć `allow_self_signed`; przetestować na hostingu docelowym.

### 3.5 WYSOKA: brak rate-limitu na logowaniu

`RateLimiter` obejmuje rejestrację (IP 20/h, e-mail 3/10 min), reset hasła, przejęcie
profilu organizatora i pobieranie okładki z URL — ale **nie** `/logowanie`
(`AuthController::login`). Formularz logowania jest jedynym miejscem podatnym na
brute-force haseł. **Rekomendacja: limit per IP (np. 10 prób / 10 min) + per e-mail
(5 / 15 min) + drobne opóźnienie przy błędzie.**

### 3.6 ŚREDNIA: brak nagłówków bezpieczeństwa

W `.htaccess` nie ma `X-Frame-Options`/`CSP frame-ancestors` (clickjacking formularzy
POST), `Referrer-Policy`, `X-Content-Type-Options`. HSTS także brak (na prod jest
SSL — warto dopisać przy pełnym HTTPS). **Rekomendacja: dodać nagłówki w `.htaccess`
prod.**

### 3.7 ŚREDNIA: `run_migrations.php` wymusza `APP_ENV=prod` w środku

`putenv('APP_ENV=prod')` wewnątrz skryptu oznacza, że omyłkowe uruchomienie lokalne
celuje w produkcyjną bazę. `.htaccess` blokuje dostęp HTTP, ale nie CLI.
**Rekomendacja:** dodać strażnika (np. wymóg potwierdzenia zmienną `CONFIRM=1` albo
whitelistę hostów) — koszt minimalny, wypadek kosztowny.

### 3.8 Pozytywnie zweryfikowane (bez zastrzeżeń)

- CSRF na wszystkich POST-ach (403 przy wygasłym tokenie, hash_equals);
- regeneracja ID sesji przy logowaniu; własny katalog sesji (ochrona przed cronem
  hostingu); cookie `httponly`/`SameSite=Lax`/`secure`;
- blokada konta egzekwowana na żywych sesjach + druga bramka przy logowaniu;
- kasowanie tylko pustych kont (RESTRICT/FK świadomie udokumentowane);
- whitelisty „powrót po akcji" — brak open redirect;
- SSRF-guard przy `saveCoverPhotoFromUrl` (public IP, brak przekierowań, weryfikacja
  treści) — zaakceptowany wyjątek DNS rebinding udokumentowany;
- `password_hash(PASSWORD_DEFAULT)`/`password_verify`;
- `storage/` chronione `.htaccess` (deny all), skrypty admin chronione przed HTTP;
- obsługa wyjątków nie wycieka detali na prod (log do error_log);
- `robots.txt` wyłącza `/admin/` i `/api/`.

---

## 4. Baza danych i migracje

**Mocne:** 62 migracje, kolejność numerowana, runner prod-only z rejestrem
`schema_migrations`; niezmienniki w schemacie (UNIQUE), indeksy pod zapytania;
slowniki zamiast enumów; `EMULATE_PREPARES=false` (prawdziwe prepared statements);
wzorzec „ALTER i backfill w osobnych plikach" (ochrona przed retroaktywną rejestracją).

**Znaleziska:**

| # | Znalezisko | Powaga |
|---|---|---|
| 1 | **`schema.sql` niekompletny** — brak 8 tabel z migracji 025–027 (`event_match_notifications`, `event_stage_cells`, `recommendation_log`, `recommendation_dismissals`, `user_preferences`, `user_preference_items`, `user_preference_signals`, `user_preference_stats`). Zweryfikowane grepem po `CREATE TABLE`. Świeża instalacja z samego `schema.sql` wywróci się na module dopasowań. | ŚREDNIA (udokumentowane w `md/database.md`, ale otwarte) |
| 2 | Rozjazd dev/prod w workflow migracji (lokalnie SQL ręcznie, prod runner) — ryzyko rozjazdu schematów; wymaga dyscypliny i jest poleganiem na pamięci. | ŚREDNIA (proces) |
| 3 | Brak w dokumentacji procedury **backupu/restore** i odtwarzania środowiska od zera (seed-y istnieją, ale nie ma opisanego kroku „postaw nowy dev"). | NISKA |
| 4 | `scoring_settings` nadpisuje `core/discovery.php` — działa poprawnie (biała lista `EDITABLE`), ale wymaga pamiętania, że plik jest źródłem domyślnych. Udokumentowane. | NISKA |

---

## 5. Testy

- **Stan: 5 zestawów, 58 testów, wszystkie przechodzą** (1272 ms) — zweryfikowane
  w trakcie audytu. Pokrycie: skarby (30), użytkownicy/moderacja (12), znane trasy
  (10), dopasowania, preferencje.
- Mechanika solidna: każdy test w transakcji, zawsze rollback; kod wyjścia
  (0/1/2); odmowa startu poza `APP_ENV=dev`; brak rejestracji nowych zestawów.
- **Luki:**
  - brak testów najważniejszych ścieżek biznesowych: rejestracja/logowanie/reset,
    zapisy i płatności (RSVP), wiadomości (1:1 i grupowe), cykl życia eventu
    (create/approve/cancel), Discovery cells API, Puls, kronika;
  - brak testów HTTP/end-to-end (jest tylko poziom modeli);
  - brak CI — nikt nie gwarantuje, że testy biegają po każdej zmianie.

**Rekomendacja:** GitHub Actions (lub odpowiednik) z MySQL + `APP_ENV=dev`,
uruchamiający `php tests/run.php` na każde push/PR; rozszerzyć pokrycie o wymienione
ścieżki.

---

## 6. Operacje i wdrożenia

- Wdrożenie ręczne na hosting współdzielony (cPanel); `cron.php` pod Task Scheduler/
  cron (naliczanie, przeterminowanie płatności, dopasowania nocne, porządki).
- Kafle map: CLI (`tiles.php`) + panel `/admin/kafle` — dobra redundancja pod hosting
  bez shella.
- **Brak:** CI/CD, środowiska staging (są tylko dev/prod), monitoringu/alarmów,
  dokumentacji procedury wdrożeniowej (poza opisem w `md/`).
- `storageDir()` w `Mailer` robi `mkdir(0777)` — bezpieczne przy typowym umask,
  ale warto jawne `0755` (zależnie od konfiguracji hostingu).
- Zależności Composer: tylko `league/oauth2-client` 2.9.0 i `endroid/qr-code` 5.1.0 —
  minimalny footprint, dobrze. Brak `composer audit` w procesie (wskazane dodać
  do CI przy okazji).

---

## 7. Wydajność

Stan dobry, decyzje poparte pomiarami (opisane w `md/features.md`):

- kafle rastrowe zamiast wektorów GPX (108 MB → 0 przy wejściu na profil);
- cache geometrii GPX (`gpx_geometry`, hash zawartości) — 0,8 ms vs 6,4 ms;
- cache pól trasy per plik (`gpx_route_cells`) — 1 ms z cache vs 14–31 ms;
- kadr mapy liczony po stronie serwera (`boundsFor`), agregacja dwuetapowa;
- budżet linii 25 na profilu + doczytywanie po kliknięciu;
- limit kafli 120 000 (ochrona i-węzłów, nie dysku).

**Uwagi:**
- `RoadSurfaceDetector` zależy od zewnętrznego Overpass API — ma bariery pamięciowe
  i awaryjny tryb ręczny, ale pozostaje punktem wrażliwym (wolny, rate-limitowany).
- Leaflet/Alpine/Google Fonts z CDN bez SRI — awaria CDN = awaria map; do rozważenia
  SRI albo self-host (jeśli kiedyś ma znaczyć).

---

## 8. Porządek w repo (znaleziska niskiej wagi)

- `readme.md` w katalogu głównym to stub (`.`) — dokumentacja jest w `md/`,
  ale rootowe readme wprowadza w błąd; warto zlikwidować albo uczynić wskaźnikiem.
- `AGENT.md` to kopia `CLAUDE.md` w uszkodzonym kodowaniu (cp852) — zbędny duplikat.
- Zstagedowane pliki niekodowe: `.claude/scheduled_tasks.lock`, `tasks - skrót .lnk`.
- Świadomie nieużywane/martwe, ale obecne w kodzie: `sw.js` (service worker nie
  używany celowo), `Models\ArchiveImport` (brak wejścia HTTP), kolumny
  `event_attendance.gps_*` (nieużywane od startu). Warto opisać w `md/` jako
  „świadomie zostawione" — reszta już jest opisana.
- `ai-engine/` (Python, tylko dev) i `ridemore-event-importer/` (Chrome) to osobne
  byty w tym samym repo — sensowne, ale wymaga świadomości przy zarządzaniu.

---

## 9. Rekomendacje — priorytety

**P0 (natychmiast):**
1. Rotacja sekretów (DB prod, SMTP, Google OAuth) + przeniesienie do env serwera +
   usunięcie fallbacków z `core/config.php`.
2. Ustanowić baseline w gicie (pierwszy commit) — dopiero po punkcie 1.
3. Włączyć weryfikację TLS w `Mailer` (lub pin certyfikatu) i przetestować na prod.
4. Dodać rate-limit na logowaniu.

**P1 (krótki termin):**
5. Uzupełnić `schema.sql` o 8 brakujących tabel (lub formalnie zadekretować
   „schema.sql + migracje 025–027" jako jedyne źródło świeżej instalacji).
6. Dodać CI uruchamiające testy (58) po każdej zmianie + `composer audit`.
7. Rozszerzyć testy o: auth, zapisy/płatności, wiadomości, cykl życia eventu,
   `/api/discovery/cells`.
8. Dodać nagłówki bezpieczeństwa (X-Frame-Options, X-Content-Type-Options,
   Referrer-Policy; CSP rozważyć).
9. Zaplanować migrację PHP 8.1 → 8.2/8.3 (dev i hosting).

**P2 (średni termin):**
10. Strażnik dla `run_migrations.php` przed omyłkowym uruchomieniem lokalnym.
11. Zmniejszyć duplikację kreator/edycja eventu (świadoma, ale dwukrotnie już
    źródło bugów — np. `wyscig`).
12. Przenieść logikę API z domknięć `api/routes.php` do kontrolerów (testowalność).
13. Udokumentować backup/restore, procedurę wdrożenia i odtworzenia dev.
14. Posprzątać root (stub `readme.md`, `AGENT.md`, `.lnk`, lock) i rozważyć opis
    martwego kodu w `md/`.

---

## 10. Podsumowanie

Aplikacja jest **bardzo dobrze napisana i udokumentowana jak na projekt bez
frameworka** — architektura spójna, decyzje opisane wraz z uzasadnieniem, dane
chronione niezmiennikami w bazie, testy zielone. Największe ryzyka nie leżą w kodzie
produkcyjnym, tylko w **higienie wdrożenia**: sekrety w repo (i w indeksie gita),
brak historii wersjonowania, EOL PHP i brak CI. Znaleziska P0 dotyczą wyłącznie
tych obszarów i są do załatwienia w ciągu jednej sesji; kod właściwy (poza
rate-limitem logowania i TLS w mailerze) nie wymaga pilnych zmian.

---

## 11. Plan wzmocnienia bezpieczeństwa — konkretne rozwiązania

Poniżej rozwiązania wg priorytetów. Snippety są napisane w stylu projektu
(polskie komentarze, istniejące wzorce) — do wdrożenia po decyzji, nie
zastępują implementacji.

### 11.1 P0 — sekrety poza repo i rotacja

**Cel:** `core/config.php` bez żadnego sekretu; sekrety tylko z env albo z pliku
poza gitem.

1. **Rotacja** wszystkich trzech sekretów produkcyjnych (hasło DB prod, hasło SMTP,
   Google OAuth client secret) w panelach dostawców — traktować jako
   skompromitowane (były w indeksie gita).
2. **Config czyta wyłącznie env**, fallbacki usunięte:
   ```php
   // core/config.php — sekretów nie ma; każdy klucz musi przyjść z env
   $env = fn(string $key): string => getenv($key) ?: '';
   ```
3. **Plik lokalny poza gitem** dla środowisk bez wygodnego env (dev, cPanel):
   nowy `core/config.local.php` dodany do `.gitignore`, `require`-owany na końcu
   `config.php` tylko gdy istnieje, nadpisujący `$configs['dev']`/`['prod']`:
   ```php
   // core/config.local.php (NIE trafia do gita; na prod wgrywany ręcznie SFTP)
   $configs['dev']['db']['pass']  = '...';
   $configs['prod']['db']['pass'] = '...';
   $configs['mail']['password']   = '...';
   $configs['oauth']['google']['clientSecret'] = '...';
   ```
4. **Ochrona przed ponownym wyciekiem:** skan sekretów przed commitem — gitleaks
   albo prosty hook `pre-commit` grepujący wzorce (`GOCSPX-`, `BEGIN PRIVATE`,
   itd.). Najtańszy wariant: wpis w `.git/hooks/pre-commit` + dokumentacja w CLAUDE.md.

### 11.2 P0 — uploady poza gitem (nowe znalezisko z audytu)

`git ls-files assets/uploads` zwraca **84 pliki wgrane przez użytkowników**
(awatary, okładki — potencjalnie wizerunki ludzi). To dane osobowe w indeksie gita
i niepotrzebna objętość repo.

```gitignore
# .gitignore — dodać
/assets/uploads/
```
+ `git rm -r --cached assets/uploads` przed pierwszym commitem (pliki zostają na
dysku, znikają tylko z indeksu).

Pozytywnie: `assets/uploads/.htaccess` już wyłącza wykonywanie PHP i blokuje
`*.php*` — warstwa obronna jest, zostaje.

### 11.3 P0 — rate-limit na logowaniu

Wzorzec już istnieje (`Utils\RateLimiter`), brakuje tylko wywołania w
`AuthController::login()`:

```php
// AuthController::login() — przed jakimkolwiek sprawdzeniem hasła
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (RateLimiter::tooMany('login:ip:' . $ip, 10, 600)
    || RateLimiter::tooMany('login:email:' . strtolower($email), 5, 900)) {
    $fail('Zbyt wiele prób logowania. Spróbuj za chwilę.'); // komunikat ogólny
}
```
plus stałe opóźnienie przy błędnym haśle (`usleep(300000)` — 300 ms), żeby
równoległe zgadywanie nie miało sensu.

### 11.4 P0 — TLS w Mailerze

```php
// core/Core/Mailer.php — sendSmtp()
stream_context_set_default([
    'ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
        // 'cafile' => '...',   // jeśli hosting nie zna systemowych CA
    ],
]);
```
Jeśli docelowy hosting ma certyfikat, którego PHP nie akceptuje — **pinning
fingerprintu** zamiast wyłączenia weryfikacji:

```php
'peer_fingerprint' => 'sha256:<odcisk certyfikatu SMTP>',
```
Fingerprint zdejmuje się raz (`openssl s_client -connect ridemore.bike:465`).
Zawsze lepsze niż `verify_peer=false`.

### 11.5 P1 — nagłówki bezpieczeństwa

Do głównego `.htaccess` (działają też na dev; HSTS tylko prod):

```apache
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "DENY"
Header set Referrer-Policy "strict-origin-when-cross-origin"
Header set Permissions-Policy "camera=(), microphone=(), geolocation=(self)"

# Tylko prod (pełne HTTPS):
Header set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

**CSP — ostrożnie:** aplikacja ma inline skrypty Alpine i CDN (Leaflet, Alpine,
Google Fonts). Najpierw `Content-Security-Policy-Report-Only` na prod, po
obserwacji raportów włączyć na serio. Punkt wyjścia (weryfikacja domen CDN
w `layout.php`/`Support` przed wdrożeniem):

```apache
Header set Content-Security-Policy-Report-Only "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: https://*.tile.openstreetmap.org; connect-src 'self' https://overpass-api.de; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
```

### 11.6 P1 — guard dla `run_migrations.php`

```php
// run_migrations.php — na górze, po putenv('APP_ENV=prod')
if (PHP_SAPI !== 'cli') { exit('Tylko CLI' . PHP_EOL); }
if (getenv('MIGRATE_CONFIRM') !== 'PROD') {
    exit('Ustaw MIGRATE_CONFIRM=PROD, aby uruchomić migracje produkcyjne.' . PHP_EOL);
}
```
Omyłkowe `php run_migrations.php` lokalnie przestaje cokolwiek robić; koszt:
jeden export przed wdrożeniem.

### 11.7 P1 — sesje (strict mode, secure zawsze na prod)

W `core/bootstrap.php` przy konfiguracji sesji:

```php
ini_set('session.use_strict_mode', '1');   // odrzuca nieznane session id (fixacja)
ini_set('session.use_only_cookies', '1');  // brak session id w URL
// secure cookie: na prod ZAWSZE, nie tylko gdy $_SERVER['HTTPS'] akurat stoi
$sessionCookie = ['path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                  'secure' => APP_ENV === 'prod'];
session_set_cookie_params($sessionCookie);
```
(w bootstrap jest dziś `secure` zależne od `$_SERVER['HTTPS']` — na prod dodać
pewność przez `APP_ENV`; to samo w `Auth::login()`.)

### 11.8 P1 — weryfikacja Origin dla POST-ów (obrona wgłębna)

Uzupełnienie CSRF bez ruszania wszystkich formularzy — sprawdzenie nagłówka
`Origin` w `Csrf::check()` (konserwatywnie: blokujemy tylko gdy nagłówek jest
obecny i obcy; stare przeglądarki bez Origin nie są odcinane):

```php
// core/Core/Csrf.php
public static function check(?string $token): bool
{
    if (!is_string($token) || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        $own  = parse_url(APP_CONFIG['app_url'] ?? '', PHP_URL_HOST);
        if ($host !== null && $host !== $own) {
            return false;
        }
    }
    return true;
}
```

### 11.9 P1 — Composer audit + plan backupu

- **Zależności:** `composer audit` lokalnie i w CI (znane CVE w vendor/);
  `composer.lock` już jest — dobry znak. Do rozważenia `composer update` co
  kwartał + testy.
- **Backup (brak w dokumentacji):** codzienny `mysqldump` prod do miejsca poza
  kontem (cPanel: Cron Jobs + FTP/skrypt) + kopia `assets/uploads`; opisać
  procedurę przywracania w `md/`. Bez tego jedyną kopią bazy jest kopia hostingu.

### 11.10 P2 — monitoring i higiena

1. **Dziennik zdarzeń bezpieczeństwa** — `storage/security.log`, wzorzec jak
   `Mailer::logError` (dopisek do pliku): nieudane logowania (IP + e-mail),
   akcje admina (blokada/odblokowanie, zmiana stawek, rotacja kodu skarbu),
   zmiana hasła. Alert mailem przy skoku (np. >20 nieudanych logowań z jednego
   IP na dobę — można sprawdzać w `cron.php`).
2. **2FA dla konta admina** — opcjonalne, ale przy jednym koncie admina
   najtańsza duża wygrana (TOTP, biblioteka `spomky-labs/otphp` albo własna
   implementacja RFC 6238 ~100 linii; bez frameworka ta druga jest prostsza).
3. **CSP na serio** (po obserwacji report-only), rozważenie **SRI** dla
   skryptów CDN (albo self-host Alpine/Leaflet — przy jednym pliku CSS i bez
   buildu to kilka minut).
4. **Rate-limit na `/api/treasures/claim` i `/skarb/{code}`** (POST) per user
   — przeciwdziała skryptom zbierającym skarby z mapy.
5. **`storage/` i sesje:** `Mailer::storageDir()` `mkdir(0777)` → `0755`;
   rozważyć `session.gc_maxlifetime` krótszy niż 30 dni dla gości (dziś jedna
   wartość dla wszystkich).
6. **Sprzątanie:** usunąć stub `readme.md`, `AGENT.md` (uszkodzona kopia
   CLAUDE.md), `.claude/scheduled_tasks.lock` i `tasks - skrót .lnk` z indeksu.

### 11.11 Świadome, przemyślane „nie teraz" (żeby nie zapomnieć, że istnieją)

- **IP-allowlist dla /admin** — na hostingu współdzielonym często brak stałego IP;
  do rozważenia, gdyby panel miał zostać narzędziem wieloosobowym.
- **Podpis podpisów plików GPX wg zawartości XML** przed zapisem (dziś walidacja
  formatu przy użyciu) — niski priorytet, pliki nie są wykonywane.
- **Szyfrowanie w spoczynku** (np. wiersze `point_transactions`/dane rozliczeń
  szyfrowane aplikacyjnie) — decyzja produktowa, kosztowna; dziś ochrona spoczywa
  na dostępie do bazy i backupów (stąd 11.9).

---

## 12. Podsumowanie planu

| Priorytet | Zakres | Szacowany nakład |
|---|---|---|
| **P0** (dziś) | rotacja sekretów + config bez fallbacków · uploady poza gitem · rate-limit logowania · TLS w mailerze | ~2–4 h |
| **P1** (tydzień) | nagłówki bezpieczeństwa · guard migracji · sesje strict/secure · Origin-check · `composer audit` · backup + procedura | ~1–2 dni |
| **P2** (miesiąc) | security.log + alerty · 2FA admina · CSP na serio/SRI · rate-limity skarbów · sprzątanie repo | ~2–3 dni |

Największy stosunek „zysk/kilkanaście minut" ma **punkt 11.3** (rate-limit
logowania — ~10 linii wg istniejącego wzorca) i **11.4** (TLS w mailerze —
~5 linii), a największy „zysk/decyzja" to **11.1** (rotacja sekretów), bo
neutralizuje jedyne znalezisko, które dziś realnie zagraża produkcji.