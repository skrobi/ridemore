# Baza danych i migracje

MySQL (utf8mb4). Dostęp: `Core\Database::connection(): \PDO` (singleton;
`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`). Zapytania ręcznie,
prepared statements. **Ten sam nazwany placeholder nie może wystąpić dwa razy** w jednym
zapytaniu (skutek `EMULATE_PREPARES=false`) — użyj `:q1`, `:q2`.

- **Dev**: baza `ridemorebike2`, user `skrobi`, host `127.0.0.1`.
- **Prod**: baza `rideyvwv_ridemore_v2`, user `rideyvwv_skrobi`.

## Workflow migracji — WAŻNE

Pliki: `migration/migration_0XX_opis.sql` (kolejno numerowane) + odzwierciedlenie w
`migration/schema.sql` (pełny schemat świeżej instalacji). `seed*.sql` — dane startowe.

- **Lokalnie (dev)**: wgrywaj SQL **bezpośrednio** do `ridemorebike2` (np. `mysql`/skrypt PHP
  na `Core\Database::connection()`). Dopisz tę samą zmianę do `schema.sql`.
- **Produkcja**: [`run_migrations.php`](../run_migrations.php) — **wyłącznie prod**
  (`putenv('APP_ENV=prod')` w środku). Auto-wykrywa niezastosowane migracje przez tabelę
  `schema_migrations`. **Nigdy nie uruchamiaj go lokalnie.**
- Wdrażając nową funkcję z migracją: uruchom `run_migrations.php` na prod + wgraj zmienione pliki.

> **`schema.sql` NIE jest dziś kompletny** (stan 2026-08-19). Brakuje w nim ośmiu tabel
> z migracji 025–027, choć kod ich używa: `event_match_notifications`, `event_stage_cells`,
> `recommendation_log`, `recommendation_dismissals`, `user_preferences`,
> `user_preference_items`, `user_preference_signals`, `user_preference_stats`.
> Świeża instalacja postawiona z samego `schema.sql` wywróci się więc na module
> dopasowań/preferencji — do czasu uzupełnienia stawiaj ją `schema.sql` + tymi trzema
> migracjami. Źródłem prawdy dla tych tabel są pliki `migration_025..027`.

Dodając kolumnę do `events`: model `Event.php` buduje INSERT/UPDATE dynamicznie z kluczy
`$input` i SELECT-uje `e.*`, więc nowa kolumna „sama się" utrwali i odczyta — wystarczy
dodać właściwość + wpis w `save()` (patrz [`resources.md`](resources.md), reguła praktyczna).

## Tabele (wg obszaru)

**Słowniki**: `dictionaries`, `dictionary_items` (hierarchiczne — region ma poddrzewa;
migr. 013). Kody ⇆ id przez `Models\Dictionary`.

**Użytkownicy/konta**: `users`, `account_activation_tokens`, `user_oauth_identities`
(Google/Strava, migr. 029), `app_login_tokens` (most logowania do apki mobilnej,
migr. 067 — patrz niżej), `device_connections` (połączenia z licznikami:
Garmin, Polar, Wahoo…, migr. 068+069 — patrz niżej), `push_devices`
(urządzenia pod push apki mobilnej, migr. 077 — patrz niżej;
NIE mylić z `device_connections` wyżej — inne „urządzenia"), `user_billing_profiles`.
Samoobsługowe kasowanie konta w apce (Etap 9, migr. 079 — patrz niżej):
`users.deletion_requested_at`. Moderacja (migr. 058):
`users.blocked_at`/`blocked_reason`/`blocked_by` + indeksy `idx_users_blocked`,
`idx_users_created`. **Blokada zamiast kasowania** — klucze obce z `users` kaskadują
do kilkudziesięciu tabel (zapisy, relacje, komentarze, `point_transactions`,
`discovery_cells`, `rider_activities`), a cztery kolejne (`events`, `conversations`,
`messages`, `event_rsvp_payments`) mają RESTRICT, więc kasowanie konta, które
cokolwiek robiło, albo wyczyściłoby historię CUDZYCH wyjazdów, albo wywaliłoby błędem.

**Organizatorzy**: `organizer_profiles`, `organizer_collaborators` (współpracownicy → uprawnienia
edycji), `organizer_billing_profiles`.

**Wydarzenia**: `events` (centralna), `event_editions` (turnusy/terminy, migr. 023),
`event_bike_types`, `event_equipment`, `event_stages` (+ `event_stage_accommodations`,
`event_stage_meals`, `event_stage_cells` z migr. 025 do nakładania tras), `event_pricing`,
`event_price_items`, `event_recaps`, `event_photos`, `event_comments`.

**Zapisy/płatności**: `event_rsvps` (status per turnus), `event_rsvp_payments` (księga wpłat,
migr. 018), `event_attendance`, `event_reviews`.

**Wiadomości**: `conversations`, `messages` (1:1, migr. 020); `event_group_conversations`,
`event_group_messages`, `event_group_reads` (kanały grupowe per turnus, migr. 028).

**Dopasowania/preferencje (Etap 2/3)**: `event_match_notifications` (migr. 025),
`user_preference_items`, `user_preferences`, `user_preference_signals`, `user_preference_stats`,
`recommendation_log`, `recommendation_dismissals` (migr. 026–027).

**Silnik AI**: `ai_import_logs` (dziennik ekstrakcji importera Chrome + "knowledge base"
v1 per-domena — `domainSummary()`, patrz [`AiImportLog`](../core/Models/AiImportLog.php)
i `md/features.md`; migr. 032).

**Discovery Grid (Etap 8, migr. 040)**: `rider_activities` (PRZEJAZD jako byt abstrakcyjny,
`source_code` = `event_route`|`gpx`|`strava`; `rsvp_id` UNIQUE daje idempotencję jedynego
dzisiejszego źródła), `discovery_cells` (PK `(user_id, cell_id)` — „pierwszy raz i tylko
pierwszy raz" jest tu NIEZMIENNIKIEM BAZY, zapis przez `INSERT IGNORE`),
`discovery_cell_totals` (zmaterializowany agregat wspólnej mapy, odtwarzalny przez
`Discovery::rebuildTotals`; `cell_q`/`cell_r` zdenormalizowane pod zawężenie do kadru,
`parent_res0..3` z migr. 047 pod GRUPOWANIE — **kadr prostokątem, agregacja heksagonem**,
pomylenie tych dwóch rzeczy było błędem znikających pól),
`known_routes` + `known_route_cells` (znane trasy jako dane; **postępu użytkownika nie ma
w żadnej tabeli** — jest przecięciem z `discovery_cells`; `cover_photo_url` z migr. 046;
`elevation_gain_m` z migr. 063 — NULL znaczy „nieznane", nie „płasko";
`color_index` z migr. 064 — kolor linii na mapie jako WŁASNOŚĆ TRASY, a nie stała
w stylu; NULL znaczy „jeszcze nieprzydzielony" i renderuje się jak dawniej, brandową
zielenią;
`elevation_profile` z migr. 065 — ten sam JSON co `event_stages.elevation_profile`
(ok. 50 próbek `{d,e,lat,lon}` z `Gpx::sampleProfile`), pod wykres profilu na stronie
trasy; szczyty liczą się z niego NA ŻĄDANIE (`Gpx::detectPeaks`), więc nie mają
własnej kolumny;
`cell_q`/`cell_r` z migr. 061 — kadr mapy filtruje po WŁASNYCH polach trasy, bo filtr przez
`discovery_cell_totals` ukrywał trasy, których nikt jeszcze nie przejechał),
`rider_activity_cells` (migr. 041 — które pola dotknął KAŻDY przejazd, także powtórzony;
źródło prawdy dla `discovery_cell_totals.passes_count`),
`edition_tracks` (migr. 042 — ślad z ODBYTEGO wyjazdu; `user_id IS NULL` = ślad z imprezy
wgrany przez organizatora, ustawione = własny ślad uczestnika, który MA PIERWSZEŃSTWO),
`region_cells` (migr. 070 — województwa jako zbiory heksów; niezmiennik
„jeden heks = jeden region" na UNIQUE(cell_id); definicja „odkrytego regionu"
i workflow importu → patrz sekcja `region_cells` niżej).

**Discovery liczy pola WYŁĄCZNIE z `edition_tracks`** — nigdy z `event_stages.gpx_url` ani
`event_route_variants.gpx_url`. Tamte to trasa PLANOWANA (zapowiedź), a na niej kto skrócił
albo zawrócił odkrywałby tyle samo, co ten, kto przejechał wszystko. Trasa planowana dalej
robi wszystko inne (mapa na stronie wydarzenia, dopasowania, profil wysokości).

**Dwa liczniki na polu, dwie warstwy mapy** (nie mylić ich): `riders_count` = ilu ludzi
kiedykolwiek pole ODKRYŁO (rośnie tylko przy pierwszym przejeździe danej osoby, §5) —
zasila warstwę mgły. `passes_count` = ile RAZY ktokolwiek tędy przejechał, z powtórzeniami
— zasila warstwę heatmapy. Bez tego drugiego „gorące" pole znaczyłoby to samo co „odkryte
przez wielu" i heatmapa dublowałaby mgłę. **Punktacji to nie dotyczy — powtórzenia nadal
nie dają ani jednego punktu.**

**Ridemore Points (Etap 8A, migr. 043)**: `point_transactions` — **źródło prawdy dla wyniku
użytkownika**. Osobny wpis na każde naliczenie (`source`, `source_id`, `points`,
`activity_id`, `ride_date`, `description`), bo suma bez historii nie odpowiada na pytanie
„za co". **Idempotencja to klucz unikalny `(user_id, source, source_id)`, nie warunek
w kodzie** — dobór `source_id` decyduje, co wolno powtórzyć: id przejazdu dla `RIDE`/
`DISCOVERY`/`EXPLORATION` (nowy przejazd = nowe punkty), `"trasa:próg"` dla
`TRAIL_THRESHOLD` i `"trasa"` dla `TRAIL_COMPLETION` (raz w życiu), id turnusu dla `EVENT`.
`points` jest ZE ZNAKIEM — korekta ma być wpisem, nie usunięciem historii.
Kolumny `rider_activities.points_*` są od tej migracji najwyżej cache'em; nic nie ma prawa
ich zwiększać z pominięciem rejestru. Doszło też `rider_activities.elevation_gain_m`
(parser i tak je liczy) oraz bonus per trasa na `known_routes`
(`bonus_enabled`/`bonus_points`, NULL = policz automatycznie z długości trasy — patrz
`Models\DiscoveryScoring::trailValueFor()`). `completion_bonus` to LEGACY kolumna sprzed
2026-09-03 (dawny model: dwie osobne wartości zamiast jednej dzielonej równo) — nic już
do niej nie pisze, ale stare wiersze wciąż są poprawnie odczytywane, bo
`legacyRouteOverrideTotal()` sumuje ją z `bonus_points` przy odczycie.

**Stawki punktacji (migr. 053)**: `scoring_settings` — `setting_key` (ŚCIEŻKA
w konfiguracji, np. `discovery.points_per_new_cell`, `trails.threshold_count`),
`setting_value DECIMAL(12,4)`, `updated_by`. **Tabela nie zastępuje
[`core/discovery.php`](../core/discovery.php), tylko go nadpisuje**: trzyma wyłącznie
klucze realnie zmienione, więc pusta znaczy „jak przed migracją", a skasowanie wiersza
to powrót do wartości domyślnej bez potrzeby jej znajomości. Czyta ją wyłącznie
`Models\ScoringSettings` (biała lista `EDITABLE` decyduje, co wolno stroić z panelu).

**Kafle map (migr. 051)**: `gpx_geometry` (jedno parsowanie na plik — `gpx_hash` jako
PK, `points MEDIUMBLOB` z pikselami świata zoomu 18, `min/max_px|py` na kadr),
`gpx_tiles` (indeks „który plik dotyka którego kafla z14", z uzupełnianiem przerw
między rzadkimi punktami GPS), `tile_cache` (co leży na dysku: `layer`+`cache_key`+
`z/x/y`, `bytes`, `last_used_at` — po tym idzie przycinanie do `MAX_TILES`),
`tile_epochs` (numer epoki per warstwa i klucz → `?v=` w adresie kafla; unieważnienie
przeglądarek bez kasowania plików). **Wszystko ODTWARZALNE** — skasowanie kafli
niczego nie niszczy, powstaną przy następnym żądaniu (`php tiles.php` albo
`/admin/kafle`).
**`gpx_geometry.color_index` (migr. 073, 2026-08-27)** — kolor śladu na kaflu, indeks
w `Utils\TrackPalette::COLORS`. Ślady leżące blisko siebie dostają różne kolory, żeby
dało się je odróżnić tam, gdzie się nakładają (`GpxGeometry::assignColor`, sąsiedztwo
po wspólnych wierszach `gpx_tiles`). Indeks, nie HEX: paleta żyje w kodzie, więc zmiana
odcienia nie wymaga UPDATE'u. **NULL jest bezpieczny** — renderer spada wtedy na kolor
stylu, czyli rysuje jak przed migracją; uzupełnia go `backfillColors()` (przycisk
w `/admin/kafle` albo `php tiles.php colors`), który sam kasuje potem kafle warstwy
`slady`. Kolor znanej trasy to OSOBNA przestrzeń (`known_routes.color_index`, migr. 064)
— należy do TRASY, nie do pliku, więc przeżywa podmianę przebiegu.

**`gpx_geometry_trimmed` / `gpx_tiles_trimmed` (migr. 076, 2026-08-28)** — DRUGA
para tabel obok `gpx_geometry`/`gpx_tiles`, tego samego kształtu, kluczowana TYM
SAMYM `gpx_hash` (relacja 1:1 „przycięty wariant tego pliku", nie kolizja nazw).
Niesie WYŁĄCZNIE geometrię PO wycięciu okolic domu (`Utils\DiscoveryGrid::
trimEnds`, promień `DiscoveryScoring::homeTrimRadiusM()` — ten sam, którym
`RiderActivity::recordSolo` przycina punkty przed policzeniem pól odkryć).
Powód istnienia OSOBNEJ pary tabel, nie kolumny w `gpx_geometry`: klucz `all`
(mapa społeczności, `TileSource::tracks()`) leży PUBLICZNIE na dysku pod
adresem do zgadnięcia, a solo zaczyna/kończy się pod domem — surowa
geometria (w `gpx_geometry`) ma prawo trafić na klucze prywatne/publiczne-ale-
-uwierzytelnione (`me`, `u-{slug}` przez `Support::visibleRider`), nigdy na
`all`. Druga tabela to twarda gwarancja na poziomie SCHEMATU: żadna gałąź
kodu nie może przez pomyłkę oddać PEŁNEJ geometrii solo tam, gdzie ma iść
WYŁĄCZNIE przycięta — pomylenie dwóch KOLUMN tej samej tabeli byłoby dużo
łatwiejsze niż pomylenie dwóch osobno nazwanych metod (`GpxGeometry::load()`
vs `loadTrimmed()`). Bez koloru — heatmapa community maluje się jednym stylem
(`TileSource::STYLES['heat']`), nie paletą per ślad. Wypełniana leniwie
(`GpxGeometry::ensureTrimmed()`, pierwszy kafel klucza `all`, który dotyka
danego solo) albo od razu przy wgraniu (`ensureTrimmedFromPoints()`, zero
kosztu — punkty są już przycięte pod pola odkryć) i backfillem dla solo
sprzed migracji (`php tiles.php backfill` / `/admin/kafle`).

**Skarby (Etap 8D, migr. 054–060)**: `treasures` (punkt w terenie: `code` UNIQUE pod
naklejkę QR, `lat`/`lon` z dokładnością do metrów, `cell_id` tylko do rysowania ikonki
i zawężania kandydatów ze śladu, `points`, `claim_radius_m`, `origin`
OFFICIAL|ORGANIZER|PARTNER|COMMUNITY, `rarity` COMMON|RARE|EPIC|LEGENDARY, `status`
PROPOSED|ACTIVE|RETIRED, `reveal_level` + `hint`, `event_id`), `treasure_finds`
(`UNIQUE (treasure_id, user_id)` — **skarb znajduje się RAZ na osobę**, `method`
QR|GPS|GPX, `distance_m`, `points_awarded`), `treasure_confirmations`
(`UNIQUE (treasure_id, user_id)` — licznik w kolumnie nie powstrzymałby jednej osoby
przed dodaniem dziesięciu głosów). Tabele powstały jako `stickers`/`sticker_claims`
(migr. 054) i zostały przemianowane w migr. 055, zanim wszedł pierwszy wiersz
produkcyjny. `dictionary_items.icon` (migr. 055, poszerzone w 057) trzyma **KLUCZ
ikony z `Utils\Icon`, nie znak** — emoji wstawione literalnie zdążyło się uszkodzić
przy przejściu przez konsolę Windows w cp852 (naprawy: 057 dla ikon, 059 dla nazw
kategorii). `migration_060` to wygenerowany seed 100 skarbów, **idempotentny**
(kody deterministyczne + UNIQUE na `code`); `cell_id` musiał policzyć generator
w PHP, bo czystym SQL-em się go nie wyliczy.

**Systemowe**: `schema_migrations` (tworzone/używane przez `run_migrations.php`).

## `treasure_photos` (migr. 066, 2026-08-22)
Galeria zdjęć skarbu — odwzorowana z `event_photos` (ten sam problem, ten sam kształt).
**Zdjęcie główne zostaje w `treasures.photo_url`**, nie przeniesione tu jako `is_cover`:
kolumna jest wpięta w cztery działające miejsca i przepisywanie ich niczego nie kupuje.
Bez kolumny moderacji — zdjęcia z relacji też są publiczne od razu.
`uploaded_by` niesie limit na osobę (`TreasurePhoto::PER_USER_LIMIT`) i prawo do
skasowania własnego zdjęcia; `ON DELETE SET NULL`, bo skasowanie konta nie może zabrać
zdjęcia, które oglądają inni.
**Niezmiennik:** o widoczności NIE decyduje ta tabela, tylko `Models\Treasure::reveal()` —
to samo miejsce, które zeruje `photo_url` dla Tropu i Ukrytego. Każde nowe zapytanie
o zdjęcia musi albo przejść przez `reveal()`, albo (jak `foundPhotosForUser`) brać
wyłącznie znaleziska pytającego.

## `app_login_tokens` (migr. 067, 2026-08-23)
**Most logowania społecznościowego między systemową przeglądarką a aplikacją mobilną.**
Zgłoszenie usera: „logowanie nie działa poprawnie w aplikacji (googla) (…) autoryzacja
się nie powiodła i zostaję już na WWW". Powód nie leżał w kodzie logowania — ten
zadziałał poprawnie: Google odrzuca OAuth w WebView, więc Capacitor otwiera
`accounts.google.com` w systemowej przeglądarce, a ta ma WŁASNE ciasteczka. `oauth2state`
zapisany w sesji WebView nie istniał w sesji przeglądarki, więc bramka CSRF na callbacku
słusznie odmawiała. **Zalogowanie się w przeglądarce nie loguje aplikacji** — to dwa
osobne światy cookie i nic ich nie łączyło.

Przepływ: `/auth/{provider}?app=1` (otwierane pluginem Browser) → znacznik `oauth_app`
w sesji przeglądarki → po udanym OAuth `SocialAuthController::loginUser()` **NIE tworzy
tam sesji**, tylko wystawia token i przekierowuje na `bike.ridemore.app://auth?token=…` →
apka łapie deep link i woła `/auth/app?token=…`, gdzie sesja powstaje po właściwej stronie.

**Niezmienniki** (to jest mechanizm logowania — kto poda ważny token, wchodzi bez hasła):
w bazie leży **SHA-256**, nigdy sam token; ważność liczona w **minutach**
(`AppLoginToken::TTL_SECONDS` = 180, bo jedyne, co ma zdążyć, to przeskok między
aplikacjami na tym samym telefonie); **jednorazowy** — `consume()` czyta i kasuje wiersz
`FOR UPDATE` w jednej transakcji, bo deep link bywa aktywowany dwukrotnie. Wygasłe wiersze
sprząta `issue()` przy okazji, bez osobnego crona. Schemat deep linku (`APP_SCHEME`
w `SocialAuthController`) **musi zgadzać się** z `AndroidManifest.xml` i `Info.plist` —
rozjazd nie daje błędu, tylko cichy powrót do usterki. Testy: `php tests/run.php uzytkownicy`.

## `garmin_connections` / `garmin_activities` (migr. 068, 2026-08-23)
**Import przejazdów prosto z Garmin Connect.** Zgłoszenie usera: „jak dodać wgrywanie
solo tras z Garmin". Kontekst decyzji: Garmin nie ma publicznego API dla kont osobistych
(Connect Developer Program to program partnerski dla firm, ręczna akceptacja wniosku),
a Strava od 11.11.2024 **zabrania pokazywania danych użytkownika komukolwiek poza nim
samym** — czyli wyklucza mapę odkryć, peleton i kronikę, czyli rdzeń tego serwisu.
Zostaje logowanie jak w aplikacji mobilnej (biblioteka `garminconnect`, `ai-engine/garmin.py`).
Jest NIEOFICJALNE i może przestać działać po zmianie po stronie Garmina — dlatego cały
mechanizm jest DODATKIEM do wgrywania plików, nigdy jedyną drogą.

`garmin_connections` — jeden wiersz na użytkownika. **Hasła tu nie ma i nie będzie:**
w bazie leży wyłącznie token sesji zwrócony przez Garmina, zaszyfrowany AES-256-GCM
kluczem z konfiguracji (patrz `Models\DeviceConnection`; migr. 069 przeniosła go do
`devices.token_key`, bo ta sekcja istnieje w obu środowiskach). Tryb uwierzytelniony,
bo podmieniony szyfrogram nie może zdeszyfrować się po cichu na śmieci — ten token
idzie potem WPROST do procesu Pythona.

`garmin_activities` — rejestr „już to widzieliśmy", czyli odpowiedź na wymaganie
„sprawdzaj, czy takich już nie ma": po pobraniu listy z Garmina odsiewamy identyfikatory,
które tu są, więc na ekranie pokazują się WYŁĄCZNIE nowe przejazdy.
**Dlaczego osobna tabela, a nie kolumna w `rider_activities`:** wiersz musi powstać także
wtedy, gdy przejazd NIE powstał — duplikat (ten sam ślad wgrany wcześniej ręcznie, odbija
się o `UNIQUE (user_id, gpx_hash)`) albo odrzucenie (aktywność bez śladu GPS). Bez tego
takie aktywności wracałyby na listę „nowych" po każdym odświeżeniu, w kółko.

**Niezmienniki:** klucz unikalny na PARZE `(user_id, activity_id)` — dwie osoby mogą mieć
ten sam przejazd na swoich kontach Garmina i obie mają do niego prawo (ta sama zasada co
`idx_ra_user_gpx`). `rider_activity_id` ma `ON DELETE SET NULL`, nie `CASCADE`: skasowanie
przejazdu nie może skasować wpisu rejestru, bo aktywność wróciłaby jako „nowa" i dała się
policzyć drugi raz. `DeviceConnection::disconnect()` kasuje POŁĄCZENIE, ale zostawia rejestr —
inaczej „odłącz i podłącz ponownie" byłoby obejściem idempotencji.
Testy: `php tests/run.php garmin`.

## `device_connections` / `device_activities` (migr. 069, 2026-08-23)
**Liczniki, nie tylko Garmin.** Migracja 068 zbudowała `garmin_connections` i
`garmin_activities` pod JEDEN serwis. Przy drugim dostawcy nazwy zaczęłyby kłamać
(token Polara w tabeli „garmin"), a kolumny i tak są te same: kto, z czym połączony,
który token, co już ściągnęliśmy. Stąd uogólnienie zamiast drugiej pary tabel — jeden
rejestr „to już mamy" działa wtedy dla wszystkich źródeł naraz.

Dane ze starych tabel są KOPIOWANE, a tabele kasowane w tej samej migracji. Było to
bezpieczne dokładnie raz i tylko dlatego, że 068 miała jeden dzień i nigdy nie poszła
na produkcję; `run_migrations` wykonuje pliki po kolei, więc w każdym środowisku 068
istnieje, zanim dojdzie do 069.

**Co się zmienia poza nazwą:**
- `provider` — `garmin` | `polar` | `wahoo` | `coros` | `suunto`. Klucze unikalne idą
  na PARĘ `(user_id, provider)`: jedna osoba może mieć podpięte dwa liczniki naraz
  i to jest normalny przypadek, nie wyjątek.
- `activity_id` jako **VARCHAR, nie BIGINT** — Garmin numeruje aktywności liczbą, ale
  Polar oddaje identyfikator zahaszowany tekstem. Gdyby kolumna została liczbą,
  wszystkie aktywności Polara wpadałyby jako `0` i odbijały się o siebie nawzajem.
- `external_user_id` — Polar wymaga zarejestrowania użytkownika u siebie
  (`POST /v3/users`) i adresuje go potem WŁASNYM identyfikatorem.
- `token_cipher` trzyma CAŁY komplet OAuth (access + refresh + ważność) jako
  zaszyfrowany JSON. Trzy kolumny znaczyłyby trzy miejsca, w których można zapomnieć
  o szyfrowaniu — a te dane i tak zawsze czyta się i kasuje w komplecie.

**Niezmienniki:** haseł tu nie ma i nie będzie (Garmin zostawia token sesji, OAuth
token dostępu — oba AES-256-GCM kluczem `devices.token_key`, który istnieje w OBU
środowiskach, inaczej niż dawny `garmin.token_key`). `rider_activity_id` ma
`ON DELETE SET NULL`, nie `CASCADE`: skasowanie przejazdu nie może skasować wpisu
rejestru, bo aktywność wróciłaby jako „nowa" i dała się policzyć drugi raz.
`DeviceConnection::disconnect()` kasuje POŁĄCZENIE, ale zostawia rejestr.
Testy: `php tests/run.php liczniki` i `php tests/run.php garmin`.

## `device_connections.auto_import` + zgody `push_rides`/`mail_rides` (migr. 088, 2026-09-14)
**Automatyczny import z licznika przez webhooki Polar/Wahoo** (end-to-end →
[`features.md`](features.md)). `auto_import TINYINT DEFAULT 0` — decyzja usera:
przełącznik, domyślnie wyłączony, więc wdrożenie nie zaczyna nikomu wciągać przejazdów.
`connect()` go nie rusza (ponowne połączenie po zakresach zostawia wybór), `disconnect()`
kasuje wiersz, więc po ponownym podłączeniu znowu jest „wyłączone". Indeks
`(provider, external_user_id)`, bo webhook podaje identyfikator DOSTAWCY, nie nasz.
`user_preferences.push_rides`/`mail_rides` DEFAULT 1 — ta sama konwencja co migr.
081/082 (brak wiersza = „nie dotykałem ustawień"); powiadomienie i tak dochodzi
wyłącznie do kogoś, kto sam włączył automat. **Czeka na produkcję** razem z env:
`POLAR_WEBHOOK_SECRET` (z `php polar_webhook.php --create`) i `WAHOO_WEBHOOK_TOKEN`.
`schema.sql` nie ma tych tabel w ogóle (069, 081–084 też tam nie trafiły).

## `region_cells` (migr. 070, 2026-08-25)
**Region = województwo z pokryciem heksowym.** Do tej pory region był pozycją
słownika bez geometrii — przez co procent odkrycia nie miał mianownika
(„liczba bez pokrycia w danych jest gorsza niż jej brak"), a cel gry dało się
wskazać tylko trasą. Decyzje usera zamknięte w tej migracji:

1. Liśćmi słownika `region` stają się **16 województw** pod kontenerem
   „Polska"; autorskie pasma (Bieszczady, Tatry…) są dezaktywowane
   (`is_active=0`, referencje nie pękają), a referencje z `events`,
   `known_routes`, `treasures`, `organizer_profiles` przechodzą na województwa
   CASE-em po KODZIE (bieszczady/podkarpacie→podkarpackie, tatry→małopolskie,
   beskidy→śląskie, mazury→warmińsko-mazurskie; kody spoza mapy zostają i są
   raportowane przez backfill).
2. Tabela `region_cells (region_item_id, cell_id)` materializuje członkostwo
   (wzorzec `known_route_cells`). **Niezmiennik „jeden heks = jeden region"
   stoi na UNIQUE(cell_id), nie na PK** — klucz złożony `(region_item_id,
   cell_id)` dopuszczałby ten sam heks pod dwoma województwami. Poligony są
   rozłączne, więc wyłączność wynika też z geometrii; indeks jest gwarancją.
3. **„Odkryty region" = ≥1 odkryty heks w regionie**
   (`Discovery::regionsForUser`). Wcześniej liczyły się regiony wydarzeń
   z potwierdzoną obecnością — deklaracja organizatora zamiast terenu
   faktycznie przejechanego. Wydarzenia dalej dają punkty; już nie definiują
   regionu.
4. Dane wypełnia **`backfill_regions.php`** (idempotentny INSERT IGNORE,
   `--rebuild` czyści tabelę) z `data/wojewodztwa.geojson`
   (ppatrzyk/polska-geojson, wersja uproszczona). Trzy przebiegi: środek
   heksu w poligonie → test rogu na stykach → dziedziczenie od ≥3 przypisanych
   sąsiadów. Kafle za granicą (frędzle bbox: Bałtyk, Odra, Bug) zostają
   świadomie puste i są raportowane. Stan na dev: **1 492 715 heksów**
   (siatka ma ~0,22 km² na tej szerokości — patrz komentarz przy RES_CELL).

**Drugie źródło geometrii, TEN SAM mechanizm (2026-09-01).** Kraje, których
podziału nie da się zaimportować z granic administracyjnych (Czechy, Słowacja),
dostają obrysy rysowane ręką w `/admin/regiony-mapa` → `data/regiony.geojson`
(`Models\RegionOutline`). Backfill przyjmuje od tej daty KILKA PLIKÓW naraz
i domyślnie bierze oba; feature wskazuje pozycję słownika `properties.code`
(plik województw dalej rozwiązuje się po `properties.nazwa`). **Jeden przebieg
na wszystkich plikach, nie osobny na każdy** — przebiegi „styki" i
„dziedziczenie" muszą widzieć sąsiada zza granicy, inaczej pas wzdłuż granicy
PL/CZ zostawałby bez regionu po każdym imporcie z osobna. NOWEJ TABELI NIE MA
i to jest cała decyzja: `region_cells` zostaje jedynym źródłem prawdy dla
aplikacji, a narzędzie tylko dokłada plik do istniejącego wejścia.
Sporny heks (dwa obrysy albo obrys wchodzący na województwo) dostaje region
o WYŻSZYM `properties.priority` — backfill sortuje po nim malejąco przed
przebiegiem 1, a `priority` to czas zapisu obrysu, więc wygrywa ten zapisany
później („zawsze ma priorytet edytowany"). Województwa mają 0. Zmiana obrysu
wymaga `--rebuild` (INSERT IGNORE nie odbiera heksa, który już ma właściciela).

Zmiana `SIZES_M` unieważnia tę tabelę RAZEM z `discovery_cells`.
Testy: `php tests/run.php regiony`.

**Poprawka pojedynczego pola, DRUGI pisarz obok backfillu (2026-09-10,
świadoma decyzja usera).** Obrys (wyżej) działa na CAŁYCH regionach —
zapisanie małego wielokąta pod kodem istniejącego województwa ZASTĄPIŁOBY
jego oficjalną geometrię tą łatką, więc nie nadaje się do punktowej korekty
jednego źle przypisanego heksa przy granicy. `Models\RegionOutline::assignCell()`
omija więc geometrię/plik całkowicie i pisze wprost `INSERT ... ON DUPLICATE
KEY UPDATE` do `region_cells` (plus korekta `region_cell_counts` dla starego
i nowego właściciela, w jednej transakcji) — z `/admin/regiony-mapa`, tryb
„Popraw pojedyncze pole". Działa na KAŻDYM regionie, także z importu
(województwa były tu dotąd wyłącznie do podglądu). Zero pliku, zero
`backfill_regions.php` — efekt widać od razu.

## `region_cell_counts` (migr. 071, 2026-08-25)
**Materializowany mianownik pokrycia** — ile heksów ma każde województwo
(16 wierszy). Dwaj pisarze: `backfill_regions.php` (na końcu importu robi
`INSERT … ON DUPLICATE KEY UPDATE` z GROUP BY po pokryciu) i
`Models\RegionOutline::assignCell()` (przesuwa licznik o 1 przy pojedynczej
poprawce — patrz wyżej). Powód materializacji: liczenie w locie to skan
grupujący 1,5 mln wierszy na każde wejście na stronę (zmierzone na dev: ~1,5 s);
po materializacji `Discovery::regionProgress()` schodzi do ~100 ms. **Pusta
tabela nie blokuje aplikacji** — model spada na liczenie z `region_cells`
(środowiska sprzed pierwszego importu).

## Region jako zbiór: `known_route_regions` / `event_regions` / `rider_activity_regions` (migr. 074/075, 2026-08-27)
**Zastępuje pojedynczy `region_item_id` na `events`/`known_routes`** (kolumny
usunięte migr. 075) — prośba usera: przy uploadzie trasy region ma się
wyliczać sam, a event/trasa/przejazd solo mogą przebiegać przez kilka
regionów naraz, nie tylko jeden zadeklarowany ręcznie.

- **`known_routes`** — PEŁNA automatyzacja. Trasa zawsze ma GPX (wymagane przy
  uploadzie), więc `known_route_regions` to wyłącznie `known_route_cells ⋈
  region_cells` (`KnownRoute::syncRegions()`, wołane po `createFromGpx()` i
  `replaceGpx()`). Formularz stracił pole wyboru regionu — panel pokazuje go
  tylko do odczytu (`region_label`, agregat z tej tabeli).
- **`events`** — HYBRYDA, nie czysta automatyzacja: lokalnie tylko 7 z 49
  wydarzeń miało w ogóle plik GPX, więc samo wyprowadzenie z geometrii
  zostawiłoby większość wydarzeń bez regionu (utrata filtrów/dopasowań/
  powiadomień). `event_regions` to SUMA (1) ręcznej deklaracji z formularza
  (pole „region" zostaje, teraz pisze do tabeli łączącej) i (2) tego, co
  wynika z GPX etapów/wariantów (`Event::syncRegions()`, przez
  `RoutePreview::cellsForGpx()` — ta sama trasa co „co mi to da"). Liczone PO
  zapisie stages/variants, w tej samej transakcji co `Event::save()`.
- **`rider_activities`** (przejazdy solo) — `rider_activity_cells ⋈
  region_cells`, per przejazd; ta tabela już istniała (migr. 041) jako
  źródło prawdy heatmapy, więc dociągnięcie regionu jest prostym JOIN-em
  (`RiderActivity::recordTouchedCells()`).
- **`treasures.region_item_id` ZOSTAJE** jako pojedyncza kolumna — skarb to
  punkt, nie trasa, i leży w dokładnie jednym heksie/regionie. Zmienił się
  tylko sposób wyliczenia: `Treasure::guessRegion()` robi dziś bezpośredni
  lookup `region_cells` po własnym `cell_id` skarbu, zamiast (jak wcześniej)
  szukać najbliższej znanej trasy z przypisanym regionem.
- **`organizer_profiles.region_item_id` ZOSTAJE bez zmian** — deklarowany
  region organizatora jako OSOBY, niezwiązany z żadną trasą/wydarzeniem.

Odczyty regionu na liście/karcie (display) idą przez podzapytanie
`GROUP_CONCAT` (wiele regionów → jeden string do wyświetlenia); filtrowanie
i dopasowania (`MatchEngine`, `PreferenceNotifier`, `EventAttendance`) idą
przez `EXISTS`/`IN` na tabeli łączącej — nigdzie nie ma już równości na
skalarnej kolumnie. Backfill jednorazowy (przed migr. 075):
`backfill_multi_regions.php`.

## Słownik `map_layer` (migr. 072, 2026-08-26)
**Warstwy mapy jako dane, nie kod** (Etap 2, `tasks/done/warstwy-mapy.md`) —
ŻADNEJ nowej tabeli, wyłącznie siedem wierszy w `dictionary_items`: pięć
top-level (`cells`, `heat`, `slady`, `trails`, `treasures`) + dwa dzieci
`treasures` (`treasuresFound`, `treasuresNew`, przez `parent_id`). Cała
konfiguracja siedzi w `meta` (JSON): `kind` (`cells`/`tiles`/`markers` —
wskazuje RENDERER w `discovery-map.js`, nie konfiguruje go), `contexts.
{all,me,rider}.{on,hint,shown,source}` (stan domyślny i podpis PER KONTEKST
— Etap 1b: „Ślady" na profilu i na własnej mapie znaczą ślady TEJ osoby,
nie społeczności), `filterParam`/`filterValue` (składanie parametru `stan`
z zapalonych dzieci „Skarbów"), `requiresLogin` (gasi dzieci dla gościa).
Rozwiązuje to `Models\MapLayer` — pełny opis granicy dane/mechanizm tam.
**`rider.shown = FALSE`** na obu dzieciach „Skarbów": profil rowerzysty
pokazuje KOLEKCJĘ tej osoby (zawężenie robi `foundBy`/`rider=` na adresie
endpointu, nie parametr `stan`), więc rozbicie na odkryte/nieodkryte nie ma
tam czego filtrować — dokładnie tak samo, jak `rider-profile.php` nigdy nie
miał tego przełącznika przed tą migracją.
Idempotentna (`INSERT … WHERE NOT EXISTS`); `schema.sql` niesie wersję bez
strażników (świeża instalacja).
Testy: `php tests/run.php warstwy_mapy` — czytają PRAWDZIWY zasiany słownik
(nie budują drzewa sztucznie), więc pilnują RAZEM kodu i danych: literówka
w `code` migracji albo skasowany wiersz spadną tu, zanim spadną na
wszystkich mapach serwisu naraz.

**Etap 3 (ten sam plik migracji, dopisany 2026-08-26, PRZED commitem —
stąd edycja w miejscu, nie migr. 073).** `trails.contexts.{me,rider}.source`
zmienione z `known-routes` na `subject-done` (nowy token: „trasy, które ta
osoba ukończyła" — `kd-{slug}`/`kd-me` w `Models\TileSource`, tłumaczenie
w `Models\MapLayer::tileKeyFor`). Plik ma za tym SAMOKORYGUJĄCE `UPDATE`
(bezwarunkowe, więc idempotentne z definicji) — środowisko, na którym
migracja poszła wcześniejszą wersją pliku, dostaje poprawkę przy ponownym
uruchomieniu, bez osobnej migracji numerowanej.

**Naprawa błędu (ten sam plik, dopisana 2026-08-26, drugi samokorygujący
`UPDATE`).** Zgłoszenie usera: „ślady, które wgrałem, nie pojawiają się na
mapie". `slady.contexts.me.source` zmienione z `subject` na `subject-private` —
kontekst `me` (własna mapa) IGNORUJE teraz slug i zawsze rozwiązuje się do
klucza `me`, bo to jedyne miejsce, w którym `TileSource::tracks()` w ogóle
dorysowuje przejazdy SOLO (surowy plik, §27, więc publiczny `u-{slug}` nigdy
ich nie dostanie). `rider.source` zostaje przy `subject` — to wciąż widok
publiczny. Przy okazji naprawiony NIEZALEŻNY błąd w tej samej gałęzi kodu:
`TileSource::userIdFor('me')` wołało nieistniejącą `Auth::id()` (zastane
w commicie startowym) — każde żądanie klucza `me` od zalogowanego kończyło
się fatalnym błędem PHP.

## `notification_log` + zgody per typ (migr. 081, 2026-09-11)

**Bramka powiadomień — `Models\NotificationGate`.** Do tej migracji zgoda na
push była zero-jedynkowa (aktywny wiersz w `push_devices` = wszystko), co było
w porządku, dopóki wychodziły wyłącznie rzeczy transakcyjne. Program zachęt to
zmienia: jedno natrętne powiadomienie wyłączało przełącznik razem z
wiadomościami od organizatora wyjazdu.

- **`user_preferences.push_messages` / `push_nearby` / `push_progress`** —
  zgody per rodzaj, tam gdzie już mieszka `notify_matches`. **DEFAULT 1,
  a brak wiersza znaczy „włączone"** (`NotificationGate::zgoda()` nakłada
  domyślne): nadrzędny przełącznik push jest już świadomym opt-inem, więc
  pytanie o zgodę drugi raz byłoby pytaniem o zgodę na zgodę.
- **`notification_log`** robi trzy rzeczy naraz:
  **`UNIQUE (user_id, type, dedupe_key)`** to nie indeks pod szybkość, tylko
  GWARANCJA „tego samego nie wyślemy dwa razy" — pilnuje jej baza, nie czyjaś
  pamięć przy dopisywaniu kolejnego typu. `dedupe_key` identyfikuje RZECZ
  (`kr:42`, `msg:1987`), nie datę; typ, który ma prawo wracać cyklicznie,
  wpisuje do klucza okres (`tyg:2026-W37`). Jest `NOT NULL DEFAULT ''`, bo
  w MySQL NULL nie równa się NULL i UNIQUE przepuściłby dowolną liczbę wierszy
  z NULL-em. Indeks `(user_id, sent_at)` liczy budżet (2 zachęty/tydzień,
  1/dobę), a `opened_at` jest jedynym źródłem odpowiedzi na pytanie, czy
  ktokolwiek to otwiera.

**Zasada, którą ta tabela egzekwuje:** powiadamiamy o ZDARZENIU, nie o stanie.
Stan („zostały Ci 3 pola") jest prawdziwy w nieskończoność, więc wymaga
sztucznego cooldownu i i tak kiedyś zaspamuje; zdarzenie zdarza się raz.

## Kanał e-mail w bramce (migr. 082, 2026-09-11)

**Etap 1c programu zachęt.** Do tej migracji zachęta mogła dotrzeć wyłącznie
pushem, a `PushNotifier` zaczynał listę odbiorców od `FROM push_devices …
WHERE is_active = 1` — czyli program zachęt fizycznie nie widział nikogo bez
apki. E-mail ma każde konto, i to jest cała stawka tej zmiany.

- **`notification_log.channel`** (`'push'`/`'mail'`) **wchodzi do klucza
  unikalności**: `UNIQUE (user_id, type, dedupe_key, channel)`. Decyzja usera:
  kanały idą RÓWNOLEGLE, nie jako fallback. Bez kanału w kluczu pierwszy kanał
  „zużyłby" zdarzenie (`INSERT IGNORE` odrzuciłby drugi wiersz) i druga rura
  nigdy by nie ruszyła. `DEFAULT 'push'` mówi prawdę o wierszach sprzed
  migracji — wszystkie opisują push.
- **Indeks budżetu to teraz `(user_id, channel, sent_at)`** — druga decyzja
  usera: **limity liczone OSOBNO DLA KAŻDEGO KANAŁU** (2/tydzień, 1/dobę per
  kanał). Push przerywa dzień, mail czeka w skrzynce; wspólny licznik
  po cichu zmniejszyłby o połowę liczbę zachęt, które w ogóle wychodzą.
- **`user_preferences.mail_messages` / `mail_nearby` / `mail_progress`** —
  zgody mailowe NIEZALEŻNE od pushowych (decyzja usera: to nie jest ta sama
  decyzja). DEFAULT 1, jak pushowe; chroni przed natręctwem budżet plus wypis
  jednym kliknięciem w każdym mailu. **`notify_matches` zostaje nietknięta**
  (DEFAULT 0) — jest starsza i zebrana świadomie, a migracja nie odwołuje
  zgody, na którą ludzie już odpowiedzieli. Obsługuje ją typ
  `NotificationGate::DOPASOWANIE`, który istnieje TYLKO w kanale mailowym.

**Cisza nocna dotyczy wyłącznie pusha** (`wolnoZachecac()` sprawdza kanał).
Mail nikogo nie budzi, a przy okazji znika pułapka z migr. 081: nocny crontab
blokował CAŁY przebieg zachęt, teraz mailowa połowa wyjdzie zawsze.

**Wypis z maili nie ma swojej tabeli** i to jest decyzja: adres w stopce niesie
podpis HMAC (`NotificationGate::adresWypisu`, sekret
`config['notifications']['unsubscribe_key']`). Użycie `account_activation_tokens`
byłoby błędem — `ActivationToken::issueFor()` unieważnia wszystkie nieużyte
tokeny usera, więc wydanie linku wypisu kasowałoby komuś czekający link
aktywacyjny albo reset hasła; do tego tamte żyją godzinę, a link w mailu musi
działać po pół roku.

## `notification_settings` (migr. 083, 2026-09-11)

**Panel `/admin/powiadomienia`.** Zgłoszenie usera: „czy jest gdzieś miejsce do
zarządzania powiadomieniami — jakie, w jakim czasie, jak często". Nie było:
budżet, cisza nocna i okno świeżości siedziały w stałych `NotificationGate`,
a PORA wysyłki nawet nie w aplikacji, tylko w crontabie na serwerze.

**Ten sam wzorzec co `scoring_settings` (migr. 053), z tymi samymi trzema
własnościami:** pusta tabela = zachowanie sprzed migracji, skasowany wiersz =
powrót do domyślnej BEZ znajomości liczby, nowy parametr w kodzie działa bez
migracji. Kod (`NotificationSettings::PARAMETRY`) trzyma wartości domyślne,
zakresy i uzasadnienia; tabela wyłącznie to, co admin realnie zmienił —
**wartość równa domyślnej NIE jest zapisywana**, inaczej jeden zapis formularza
zamieniłby wszystkie parametry w jawne nadpisania i późniejsza zmiana wartości
domyślnej w kodzie nie dotarłaby już do nikogo.

Osobna tabela, a nie wiersze `notifications.*` w `scoring_settings`, bo tamta
nazywa się „ustawienia punktacji" i to jest część jej dokumentacji: punktacja
jest regułą gry, powiadomienia regułą komunikacji. Kształt kolumn jest
identyczny świadomie — gdyby kiedyś powstała jedna tabela ustawień aplikacji,
przeniesienie to `INSERT … SELECT`.

**Co da się ustawić:** budżety zachęt osobno per kanał (`budzet.push.*`,
`budzet.mail.*`), godziny ciszy nocnej (`cisza.*`, tylko push), okno wysyłki
zachęt (`okno.od`/`okno.do` — obejmuje OBA kanały, bo to reguła o porze
zaczepiania, nie o budzeniu), okno świeżości nowych skarbów
(`okno.swiezosc_dni`), wyłączniki kanałów (`kanal.*`) i rodzajów (`typ.*`).

**Wyłącznik rodzaju sprawdzany jest PRZED dziennikiem**, nie po — gdyby wpis
w `notification_log` powstawał mimo wyłączenia, deduplikacja uznałaby te
powiadomienia za już wysłane i po ponownym włączeniu nikt by ich nie dostał.

## `notification_texts` (migr. 084, 2026-09-11)

**Treści powiadomień do edycji w panelu**, razem z podglądem. Ten sam wzorzec
co migr. 053 i 083 (kod trzyma domyślne, tabela wyłącznie zmienione), ale
OSOBNA TABELA od `notification_settings`, bo tamta trzyma
`setting_value DECIMAL NOT NULL` — dopchnięcie tekstu wymagałoby kolumny zawsze
pustej w 17 istniejących wierszach i poluzowania `NOT NULL` tam, gdzie dziś
działa jako gwarancja.

**Dlaczego treść z bazy nie może nic zdradzić.** Powiadomienie o skarbie
szanuje poziom ujawnienia (`Treasure::reveal()`): kto nie odkrył pola, nie
poznaje nazwy skarbu. Gdyby istniał JEDEN edytowalny tekst z opcjonalnym
`{nazwa}`, wystarczyłby jeden wpis w formularzu, żeby wyciek stał się faktem —
cicho. Dlatego treść jest rozbita na **warianty per poziom ujawnienia**
(`…jawny` / `…ukryty`), a każdy wariant ma **własną białą listę znaczników**
(`NotificationTexts::TEKSTY`). W wariancie „ukryty" `nazwa` nie istnieje:
`set()` usuwa ją z zapisywanego tekstu (i melduje o tym panelowi), a `render()`
i tak by jej nie podstawił. **Dwa zamki na tych samych drzwiach** — pierwszy da
się ominąć, wchodząc do bazy wprost, i osobny test właśnie to robi.

**HTML w treści maila jest DOZWOLONY** (prośba usera o edytor z podglądem),
ale przechodzi przez białą listę: `oczyscHtml()` zostawia wyłącznie znaczniki
formatujące i **zrywa wszystkie atrybuty poza `href`**, a `href` musi być
http(s) albo znacznikiem bloku. Odcina to `onclick`, `style`, `<script>`,
`<iframe>` i `javascript:`. Sama `strip_tags` z białą listą NIE wystarcza —
zostawia atrybuty, więc `<a onclick=…>` przeszłoby bez szwanku; dlatego są dwa
kroki, nie jeden. Treść pusha jest zawsze odzierana z HTML-a w całości: ekran
blokady telefonu i tak go nie renderuje.

**DANA a BLOK — rozróżnienie, na którym stoi bezpieczeństwo tego modułu.**
Znaczniki (`{nadawca}`, `{nazwa}`, `{wyjazd}`) to DANE i są zawsze
escape'owane: cudza nazwa wyjazdu nie ma jak wnieść do maila własnego HTML-a.
Bloki (`{przycisk}`, `{mapa}`, `{cytat}`, `{powitanie}`, `{powod}`) to gotowy
HTML **składany przez kod** — admin decyduje tylko, gdzie stanie i czy w ogóle.
Dzięki temu przycisk zawsze wygląda jak przycisk, a mapa jest obrazkiem
z naszego serwera.

**Dwie pułapki złapane dopiero na żywym kliknięciu, obie ciche:**
1. **CRLF z `<textarea>`.** Przeglądarka zwraca końce linii jako CRLF, a treści
   domyślne w kodzie mają LF — więc porównanie „tekst równy domyślnemu" nigdy
   nie wychodziło prawdą i JEDEN zapis formularza robił nadpisania ze
   wszystkich treści naraz. `set()` normalizuje teraz końce linii przed
   porównaniem.
2. **Przełącznik, którego formularz nie renderuje.** Rodzaje bez nadawcy
   (`nearby_new`, `progress`) nie mają wiersza na ekranie, a niezaznaczony
   checkbox i checkbox nieistniejący wyglądają w POST identycznie — więc zapis
   je WYŁĄCZAŁ. Formularz wysyła teraz ukryty znacznik obecności pola;
   bez niego kontroler ustawienia nie dotyka.

## Nazwa formatu „ustawka" (migr. 085, 2026-09-11)

Zmiana **wyłącznie etykiety** w `dictionary_items`: „Ustawka społecznościowa" →
**„Zorganizowane wydarzenie"** (decyzja usera). **Kod pozycji (`ustawka`)
zostaje** i to jest tu najważniejsze: wisi na nim komplet adresów filtrów
(`?eventTypes[]=ustawka`, także w stopce i w linkach, które ktoś już zapisał),
gałęzie kreatora (`TYPE_META`, `STEP_MIN`, `visibleSteps()`), warianty trasy
i rozgraniczenie jednodniowe/wielodniowe.

Migracja jest **warunkowa na starej wartości** (idempotentna, i nie nadpisze
etykiety zmienionej później ręcznie). Sama baza to jednak połowa roboty:
napis był **zahardkodowany w siedmiu miejscach** widoków, w dwóch różnych
brzmieniach („Wspólna jazda" i „Ustawka społecznościowa") — wszystkie
poprawione razem z migracją, pilnuje tego `tests/zgloszenia_2026_09_11_test.php`.

Jeden wyjątek został celowo: „Wspólne jazdy z pasji" w
`organizer-profile-form.php` opisuje **rodzaj organizatora**, nie format
wydarzenia.

## Nawierzchnia znanej trasy (migr. 086, 2026-09-11)

Trzy kolumny na `known_routes`: `surface_asphalt_pct`, `surface_gravel_pct`,
`surface_trail_pct` — **te same co na `event_stages` i `event_route_variants`**,
ten sam detektor (`Utils\RoadSurfaceDetector`, Overpass API), ten sam pasek
w widoku (wspólny `partials/surface-breakdown.php`).

**Bez `surface_item_id`**, w odróżnieniu od tamtych dwóch. Tam kolumna jest
ręcznym awaryjnym wyborem ze słownika `surface_type` dla wydarzenia BEZ pliku
GPX — a znana trasa bez GPX-a nie istnieje (`KnownRoute::createFromGpx()` to
jedyna droga jej powstania).

**NULL = NIE WIADOMO, nie „zero asfaltu".** Overpass bywa niedostępny, ma
limity i nie pokrywa każdej drogi. Strona trasy przy NULL-u po prostu nie
pokazuje paska (`TrailController::surfaceBreakdown()` → `null`) — pasek
z samych zer byłby wpisaną do bazy nieprawdą.

**Kto odpytuje Overpass — i dlaczego nie model.** Detekcję odpala **warstwa
HTTP** (`KnownRouteController::surfaceOf()`, z `set_time_limit(300)` jak
`/api/gpx/parse`) albo **CLI** (`backfill_known_route_surface.php`); model
dostaje gotowe procenty parametrem. To ten sam podział, który od początku
obowiązuje przy wydarzeniu. Pierwsza wersja wołała detektor ze środka
`createFromGpx()` i **zawiesiła cały zestaw testów**: to jedyna droga dodania
trasy, także w testach, a Overpass ma budżet 70 s na trasę. Osobny test
pilnuje, żeby to nie wróciło.

**Backfill:** `php backfill_known_route_surface.php` (idempotentny — bierze
tylko trasy z NULL-em, więc przerwany w połowie podejmuje pracę tam, gdzie ją
zostawił). Liczyć **kilkanaście–kilkadziesiąt sekund na trasę**; przy limitach
Overpass część tras wraca bez wyniku i wchodzi do następnego przebiegu.

## Emblematy (migr. 087, 2026-09-11)

**Odznaka za przejechanie CAŁEJ trasy** — `emblems` (definicja) +
`known_routes.emblem_id` / `events.emblem_id` (przypisanie) + `emblem_awards`
(zdobyte egzemplarze). Warunek jest jeden dla obu źródeł: **100% pól siatki
odkryć** tej trasy.

**Dlaczego osobna tabela, a nie kolumny przy trasie.** User zapowiedział
„na przyszłość rzeczywiste ordery za przejechanie trasy". Fizyczny order to byt
z własnym życiem (nakład, status wysyłki, adres), więc komplet kolumn
zdublowany na `known_routes` I `events` trzeba by wtedy migrować w dwóch
miejscach naraz. Przy okazji jeden emblemat może obsłużyć serię tras.

**`emblem_awards` to jedyne miejsce w Discovery, gdzie ZAPISUJEMY zamiast
wyprowadzać** — i to jest cały powód jego istnienia. Pokrycie trasy dałoby się
policzyć przy każdym wyświetleniu profilu, ale `point_transactions` **ODBIERA**
`TRAIL_COMPLETION`, gdy pokrycie spadnie poniżej 100% (np. admin podmieni plik
GPX na dłuższy). Decyzja usera: **raz zdobyty emblemat zostaje na zawsze**.
Wyprowadzanie ze stanu znaczyłoby, że człowiek traci go przez cudzą edycję.
`Models\Emblem` **nie ma odpowiednika `revoke()`** i osobny test tego pilnuje.

**Unikat na `(user_id, emblem_id)`, nie na źródle** — emblemat jest rzeczą,
którą się MA. Ten sam wiszący na pięciu trasach serii nadaje się raz;
`source`/`source_id` mówią tylko, co go przyniosło (podpis „za trasę X").

**Klucze obce mówią dwie różne rzeczy i to jest zamierzone:**
`known_routes`/`events` → `ON DELETE SET NULL` (skasowanie emblematu nie rusza
trasy, trasa po prostu przestaje go dawać), `emblem_awards` → `ON DELETE
CASCADE` (osierocone egzemplarze byłyby pustymi kafelkami na profilu).
Dlatego **wycofanie z obiegu robi się przez `is_active = 0`, nie przez DELETE**.

**Przyznawanie jest ZBIOROWE, nie per użytkownik.** Jedno `INSERT ... SELECT
... GROUP BY user HAVING COUNT >= cells_total` na trasę nadaje emblemat
wszystkim, którzy ją domknęli; to samo zapytanie z `AND dc.user_id = :uid`
obsługuje pojedynczy upload. Cron i ścieżka po wgraniu pliku to więc **ten sam
kod**, a nie dwa mechanizmy, które kiedyś się rozjadą. Koszt rośnie z liczbą
tras z emblematem, nie z liczbą kont.

**Wydarzenie nie ma `known_route_cells`** — jego pola czyta się z
`gpx_route_cells` po hashu pliku (cache z migr. 050, to samo źródło co regiony
wydarzenia). **Etapy sumują się** (wielodniówkę zalicza się w całości),
**warianty są alternatywą** (wyścig z dystansami 50/100/160 — wystarczy jeden;
suma zrobiłaby emblemat niezdobywalnym). Szczegóły przy
`Emblem::eventRouteCandidates()`.

## `push_devices` (migr. 077, 2026-08-28)
**Etap 8 przebudowy apki mobilnej** — urządzenia zarejestrowane pod push
(`Models\PushDevice`, `Core\Push`). Nazwa CELOWO NIE `user_devices`, jak
sugerował szkic kontraktu: serwis ma już `Models\DeviceConnection`/
`Utils\DeviceApi` (liczniki rowerowe Garmin/Polar/Wahoo, config `devices` w
`core/config.php`) — ta sama nazwa kolidowałaby pojęciowo.

**Zgoda = obecność aktywnego wiersza** (`is_active`), nie osobna kolumna
gdzie indziej — rejestracja tokenu (`POST /api/devices/register`) JEST
zgodą, wyłączenie w koncie (`POST /api/devices/unregister`) ją cofa bez
kasowania wiersza (ponowne włączenie nie wymaga nowej rejestracji tokenu
z apki, patrz `PushDevice::activateForUser`). `token` **unikalny globalnie**,
nie per user — identyfikuje zainstalowanie apki, nie konto; rejestracja robi
UPSERT po tokenie (`ON DUPLICATE KEY UPDATE user_id=...`), więc na
współdzielonym telefonie token „idzie" za tym, kto się ostatnio zalogował.

`Core\Push::sendToUser()` — TEN SAM wzorzec co `Core\Mailer`: driver
`'log'`/`'fcm'` z `core/config.php['push']`, dev pisze do `storage/push.log`
zamiast wysyłać naprawdę. FCM HTTP v1 zaimplementowane w pełni (JWT RS256
własnym `openssl_sign`, bez nowej zależności Composera) — ale **bez pliku
konta serwisowego Firebase serwis nie wyśle niczego naprawdę**, to fakt
zewnętrzny, nie luka. APNs (iOS) świadomie tylko punkt rozszerzenia
(`sendApns()` rzuca czytelny wyjątek) — bez Maca w tej sesji iOS i tak się
nie buduje.

Trzy zaczepienia treści (kontrakt: nowy skarb w okolicy, ktoś dołączył do
wyjazdu, wiadomość na czacie):
- `Models\EventRsvp::join()` → organizator, tylko status `potwierdzony`,
  nigdy o zapisie na własny wyjazd.
- `Models\Message::send()` (1:1) i `Models\EventGroupConversation::postMessage()`
  (kanał grupowy per turnus) → odbiorca/członkowie oprócz nadawcy. **Treść
  wiadomości NIE wchodzi do push** — prywatność powiadomienia systemowego.
- `Models\PushNotifier::runTreasuresNearby()` (nowy plik, wzorzec
  `Models\PreferenceNotifier`, wołany z `cron.php`) — „okolica" to REGION
  skarbu dopasowany do regionów, w których user ma choć jedno odkryte pole
  (`Discovery::regionIdsForUser()`, nowa metoda). Okno „od wczoraj"
  (`created_at >= NOW() - INTERVAL 1 DAY`), bez cursora/tabeli stanu — ten
  sam duch co reszta `cron.php`. Treść szanuje `reveal_level` (ukryty skarb
  nie zdradza nazwy w powiadomieniu, tak jak nie zdradza jej na mapie).

**Przy okazji naprawione**: `EventRsvp::join()` wołało `beginTransaction()`
bezwarunkowo — wysadzało się, gdy ktoś woła je z WEWNĄTRZ już otwartej
transakcji (uruchamiacz testów, pierwszy taki wywołujący). Ten sam strażnik
`$wlasna = !$pdo->inTransaction()` co w `Models\AppLoginToken::consume()`.
Testy: `php tests/run.php push` (14 przypadków — rejestracja/zgoda,
wszystkie trzy zaczepienia, precyzja `PushNotifier` per poziom odsłony i
okno czasowe).

## `users.deletion_requested_at` (migr. 079, 2026-08-29)
**Samoobsługowe kasowanie konta w apce (Etap 9)** — Apple wymaga, żeby dało
się je zainicjować WPROST w apce, nie „napisz do nas" (dotychczasowy link
mailto w `/admin/moje-konto` obiecywał w treści automatyczną
„anonimizację", której żaden kod nie realizował — odkryte przy
weryfikacji, nie zgadywane).

Twardego, automatycznego kasowania kont z treścią świadomie NIE MA — ten sam
powód, co już w `users-admin.php`: klucze obce z `users` kaskadują do
kilkudziesięciu tabel, więc skasowanie zabrałoby też cudze wyjazdy, w
których to konto brało udział. `Models\User::requestDeletion()` (instancja,
nie `UserAdmin` — to działanie NA WŁASNYM koncie) więc od razu:
1. weryfikuje hasło (o ile konto je ma — logowanie wyłącznie społecznościowe
   nie ma czego weryfikować, `AccountController` wymaga wtedy zamiast tego
   jawnej zgody w formularzu),
2. blokuje konto TYM SAMYM mechanizmem co moderacja (migr. 058,
   `blocked_at`/`blocked_reason`/`blocked_by` = samo konto) — `Auth::user()`
   już wylogowuje zablokowane konta, za darmo,
3. ustawia `deletion_requested_at`, żeby admin odróżnił „chce odejść" od
   „naruszył regulamin" — nowy filtr `do_usuniecia` w `/admin/uzytkownicy`
   (`UserAdmin::search()`/`counters()`).

Pełne, trwałe usunięcie kończy admin ręcznie z tego samego panelu
(`UserAdmin::deleteIfEmpty()`, jeśli konto jest puste, albo ręczna decyzja,
jeśli ma treść). Testy: `php tests/run.php uzytkownicy` (5 nowych
przypadków). Treść `/prywatnosc` (§ 11, „Aplikacja mobilna") zaktualizowana
zgodnie z tym mechanizmem — **draft do przejrzenia przez usera**, nie
finalna opinia prawna.

## `content_translations` + `users.lang` (migr. 089, 2026-09-16)

- `users.lang VARCHAR(5) NULL` — język maili/pushy i wejścia na `/`; `NULL` = polski (wszystkie istniejące konta).
- `content_translations` — tłumaczenie treści z bazy **po hashu tekstu źródłowego** (`UNIQUE(source_hash, target_lang)`), nie po id rekordu: edycja oryginału = nowy hash = nowe tłumaczenie, bez triggerów. `origin`: `machine` / `human` (korekta, wygrywa) / `same` (tekst już w docelowym języku — żeby nie pytać tłumacza drugi raz) / `pending` (kolejka drivera `ai`; tylko wtedy `source_text` trzyma oryginał, cron go zeruje po tłumaczeniu). `context` (np. `event:12:title`) służy korekcie i sprzątaniu (`ContentTranslation::sweep`, noc).
- **Na produkcji migracja PRZED kodem** — kod czyta `users.lang`.

## `planned_routes` (Route Planner, Etap 1, migr. 090, 2026-09-17)

- Prywatny szkicownik trasy usera — celowo NIE `known_routes` (tamta jest publiczna,
  community/admin, bez właściciela). `waypoints_json` (surowe klikane punkty, pod
  ponowną edycję) i `geometry_json` (finalna wyliczona linia, pod wyświetlenie i
  eksport GPX) to dwa różne kształty danych, stąd dwie kolumny zamiast jednej.
- `engine`/`profile` (`VARCHAR`, nie enum — trasa nawet dziś nie ma słownika typu/
  trudności) niosą prowenencję wyliczenia: którym silnikiem routingu i dla jakiego
  profilu roweru policzono TĘ konkretną trasę. Tanie teraz (dwie kolumny z sensownym
  DEFAULT), kosztowne jako migracja dopisana po fakcie, gdyby kiedyś zmienił się
  silnik routingu (patrz `Utils\RoutingProxy`).
- `ascent_m`/`descent_m`/`duration_min` są **nullable z realnego powodu**: przewyższenie
  liczy się z publicznego, zewnętrznego API elevation (`Utils\ElevationLookup`), które
  może być chwilowo niedostępne — `NULL` znaczy „nie policzono", zapis trasy i tak
  przechodzi.
- Świadomie ODŁOŻONE (decyzja dwóch paneli architektonicznych, `tasks/active/route-planner.md`):
  widoczność/publiczny link, warianty trasy, historia wersji. MVP to jedna trasa =
  jeden wiersz, właściciel = jedyny widz.
- IDOR pilnowany w SQL (`WHERE id = :id AND user_id = :user_id`), nie w kodzie —
  patrz `Models\PlannedRoute::findForUser`/`update`.

## `planner_routing_configs` + snapshot trasy (migr. 092, 2026-09-21)

- Nazwana konfiguracja **rozszerza istniejący** `dictionary_items` ze słownika
  `bike_type` przez `bike_type_item_id`; nie powstaje drugi profil roweru.
- `character_code` to prosty preset `road|balanced|offroad`, a
  `preferences_json` przechowuje znormalizowane wagi nawierzchni i klas dróg.
  Globalnych presetów nie zapisujemy w DB, więc użytkownik nie może ich
  nadpisać.
- Unikalne `(user_id, name)` pilnuje nazw. `is_default` ma wartość `1` albo
  `NULL`, dzięki czemu indeks `(user_id, bike_type_item_id, is_default)`
  dopuszcza wiele konfiguracji i najwyżej jedną domyślną na profil.
- `planned_routes.routing_config_id` wskazuje nazwany zapis, ale ma `ON DELETE
  SET NULL`. Niezależny `routing_preferences_json` jest snapshotem użytym do
  obliczenia trasy, więc skasowanie lub późniejsza edycja konfiguracji nie
  zmienia historycznej trasy.

## Kamienie milowe migracji (skrót historii)

`001` auth · `002` region · `003` edycja eventu + współpracownicy/rozliczenia · `004(b)` profil
organizatora · `005` opinie/relacje · `006` profil rozliczeniowy usera · `007–009` płatne
rezerwacje/zwroty/deadline · `010` status „zainteresowany" · `011` moderacja zgłoszeń · `012`
rozszerzony profil organizatora · `013` hierarchia słowników · `014–016` zdjęcia/aktywność/posiadacz
konta · `017–018` potwierdzenie zaliczki + księga płatności · `019` % nawierzchni · `020`
wiadomości · `021–022` indeks/aktywność + e-mail kontaktowy organizatora · `023` turnusy
(event_editions) · `024` „pokręć z kimś" · `025` komórki trasy + powiadomienia dopasowań · `026–027`
preferencje + decay aspiracji · `028` czat grupowy · `029` tożsamości OAuth · `030` telefon/e-mail
zapisu zewnętrznego · `031` warianty trasy (event_route_variants) · `032`
dziennik importera AI (ai_import_logs) · `039` `event_recaps.edition_id`
(kronika per turnus; klucz unikalny przeniesiony na `(edition_id, author)`) ·
`038` `users.public_slug`
(publiczny profil rowerzysty; backfill `backfill_rider_slugs.php`) ·
`037` `users.roster_visible`
(wyłącznik „nie pokazuj mnie na listach/w peletonie") · `036` peleton
(`rider_connections`, zmaterializowany agregat par „jeździliśmy razem") ·
`065` profil wysokości znanej trasy
(`known_routes.elevation_profile` — zgłoszenie usera 2026-08-20: strona trasy nie
mówiła, „jaki jest profil", czyli gdzie są podjazdy. **Nie nowy mechanizm, tylko ta
sama kolumna co w `event_stages`/`event_route_variants`** — dzięki temu wykres,
kategorie podjazdów i synchronizacja „najedź na wykres → pinezka na mapie" działają
bez ani jednej nowej linii w JS. Kolumna, a nie parsowanie przy wejściu na stronę:
ten sam powód co przy 063 — 30 ms i odczyt pliku przy KAŻDYM wejściu, a strona trasy
jest w tym serwisie celem z wyszukiwarki. NULL = „nie policzono", strona po prostu
nie rysuje wykresu. Backfill robi `run_migrations.php` TĄ SAMĄ metodą co po 063
(`KnownRoute::backfillElevation` — i tak parsuje ten sam plik); metoda sprawdza
`SHOW COLUMNS`, czy kolumna profilu już istnieje, bo po 063 jeszcze jej nie ma) ·
`064` kolor znanej trasy
(`known_routes.color_index` — zgłoszenie usera 2026-08-20: warstwa „Znane trasy"
rysowała WSZYSTKIE szlaki jednym zielonym, więc trasy nakładające się na siebie były
nie do rozróżnienia. **Kolumna, a nie wyliczanie przy renderze kafla**: kafel jest
plikiem na dysku z `Cache-Control: immutable`, więc kolor zależny od tego, co widać na
danym kaflu, dałby tę samą trasę zieloną tu i niebieską obok. Kolor przydziela się RAZ,
przy wgraniu trasy (`KnownRoute::assignColor` — pierwszy kolor palety, którego nie mają
szlaki leżące w promieniu 2 pól, przy remisie najrzadszy w katalogu) i **nigdy nie
przemalowuje sąsiadów**: przemalowanie wymagałoby kasowania kafli także na ich
przebiegu, lawinowo dalej. Backfill robi `run_migrations.php` zaraz po migracji
(`KnownRoute::assignAllColors()`, ta sama konwencja co 063) i unieważnia kafle każdej
pokolorowanej trasy — inaczej zmiana zostałaby w bazie, a mapa dalej pokazywałaby
zieleń) ·
`063` przewyższenie znanej trasy
(`known_routes.elevation_gain_m` — pod dymek szlaku na mapie: klik ma powiedzieć
„co to za trasa, ile kilometrów, ile w górę". `Gpx::parse()` liczyło tę wartość przy
każdym wgraniu pliku, ale nie było gdzie jej zapisać. **Kolumna NULL-owalna, bo 0 m
to poprawna wartość** (płaska pętla) — trasa sprzed migracji ma „nie wiem", a nie
„płasko". Backfill wykonuje `run_migrations.php` zaraz po tej migracji
(`KnownRoute::backfillElevation()`, ten sam wzorzec co slugi organizatorów po migr. 004);
SQL-em się tego nie policzy, a osobny skrypt byłby krokiem wdrożenia do zapomnienia) ·
`061`+`062` współrzędne pól znanej trasy
(`known_route_cells.cell_q`/`cell_r` + indeks `(cell_r, cell_q)`, a w 062 backfill —
**naprawa niewidocznych tras**. Warstwa „Znane trasy" zawężała kadr przez JOIN
z `discovery_cell_totals`, czyli przez pola odkryte PRZEZ KOGOKOLWIEK: JOIN miał
dać wyłącznie współrzędne, a przy okazji działał jak warunek istnienia i wycinał
każdą świeżo dodaną trasę. Zmierzone przed naprawą: trasa z 9 polami, `is_active=1`,
komplet `sort_order` → 0 tras w odpowiedzi dla kadru dokładnie ją obejmującego.
**Backfill jest MIGRACJĄ, nie osobnym skryptem** (inaczej niż w 047): rozpakowanie
`cell_id` to czysta arytmetyka bitowa, a migracja naprawcza, po której trzeba jeszcze
coś uruchomić, zostawiałaby błąd otwarty. **Dwa pliki, bo runner rejestruje CAŁYMI
plikami**: gdyby ALTER i UPDATE siedziały razem, a UPDATE się wywalił, ponowne
uruchomienie zobaczyłoby „Duplicate column" z ALTER-a i zarejestrowałoby migrację
retroaktywnie — bez backfillu, po cichu. 062 jest idempotentny) ·
`060` startowy zestaw skarbów
(100 miejsc w całej Polsce; plik WYGENEROWANY, nie pisany ręcznie — `cell_id`
liczy `DiscoveryGrid::pointToCell`, czystym SQL-em się go nie wyliczy, a od niego
zależy zaliczanie skarbu ze śladu. Idempotentna: kody deterministyczne + UNIQUE) ·
`059` naprawa nazw kategorii skarbów
(te same uszkodzone bajty co w 057, kolumna obok — literały z polskimi znakami
przeszły przez konsolę Windows w cp852; uszkodzone były dokładnie trzy wiersze) ·
`058` blokada konta
(`users.blocked_at`/`blocked_reason`/`blocked_by` + indeksy — **narzędzie
moderacji zamiast kasowania**, bo klucze obce z `users` kaskadują do
kilkudziesięciu tabel i wytarłyby historię cudzych wyjazdów oraz niezmienny
rejestr punktów) ·
`057` ikony słownikowe
(`dictionary_items.icon` na VARCHAR(32) i KLUCZ z `Utils\Icon` zamiast emoji —
klucz jest ASCII, więc ten sam błąd kodowania nie ma jak się powtórzyć,
a kształt ikony zmienia się w kodzie, bez ruszania bazy) ·
`056` `treasure_finds.sticker_id` → `treasure_id`
(dokończenie zmiany nazewnictwa z 055; klucz unikalny `uniq_find_once`) ·
`055` wlepki stają się SKARBAMI
(`RENAME` `stickers`→`treasures`, `sticker_claims`→`treasure_finds` + `origin`,
`rarity`, `status`, `reveal_level`/`hint`, `event_id`, tabela
`treasure_confirmations`, `treasure_finds.method` QR/GPS/GPX, `dictionary_items.icon`.
**Zrobione, dopóki było za darmo** — w tabelach nie było ani jednego wiersza
produkcyjnego; po pilotażu ta sama zmiana znaczyłaby migrację danych i przepisanie
`source` w rejestrze punktów) ·
`054` skarby — pierwsze tabele
(pod nazwą `stickers`/`sticker_claims` + słownik `sticker_category`) ·
`053` stawki punktacji w tabeli
(`scoring_settings` — punktacja wychodzi z pliku PHP do panelu admina. Tabela
NADPISUJE `core/discovery.php`, nie zastępuje go: plik zostaje źródłem wartości
domyślnych i uzasadnień, tabela trzyma wyłącznie klucze realnie zmienione) ·
`052` wiele wpisów kroniki od jednej osoby
(zdjęty `UNIQUE (edition_id, author)`, został indeks — kronika przestała być
PODSUMOWANIEM pisanym po powrocie, a stała się DZIENNIKIEM pisanym w trakcie:
kolejny wpis to kolejna godzina tego samego dnia, nie poprawka poprzedniego) ·
`051` kafle map
(`gpx_geometry`, `gpx_tiles`, `tile_cache`, `tile_epochs` — mapy przestają ładować
pliki GPX do przeglądarki i dostają gotowe PNG. Wszystko odtwarzalne;
backfill geometrii: `php tiles.php backfill`) ·
`050` cache pol trasy z GPX
(`gpx_route_cells` + `gpx_route_cell_runs` — pola siatki odkryc dla DOWOLNEGO
pliku, kluczowane HASHEM ZAWARTOSCI, nie sciezka ani id etapu: ten sam slad bywa
podpiety w kilku miejscach i liczy sie raz dla wszystkich. Zywi „co mi ta trasa
da" na stronie wydarzenia — bez cache'u kazde wejscie parsowaloby GPX-y od nowa
(14–31 ms na trase, wielodniowka ma plik na dzien). Osobna tabela `_runs`, bo
plik bez ani jednego pola nie ma wierszy w pierwszej i byłby liczony w kolko.
ODTWARZALNE — skasowanie niczego nie psuje) ·
`049` przejazd solo
(`rider_activities.gpx_url`/`gpx_hash`/`started_at`/`moving_seconds` +
UNIQUE `(user_id, gpx_hash)` — slad BEZ wydarzenia. Ta sama tabela, bo solo to
kolejne ZRODLO przejazdu, nie nowy byt; idempotencja to klucz unikalny, nie
warunek w kodzie. `started_at`/`moving_seconds` ze znacznikow czasu w GPX —
PUNKTACJA ICH NIE UZYWA, sa po to, zeby przejazd solo mial kiedy sie odbyc.
PRYWATNOSC: pola w promieniu `privacy.home_trim_radius_m` (50 m od 2026-08-30) od poczatku
i konca sladu solo NIE SA ZAPISYWANE — przycinamy PUNKTY przed liczeniem pol,
zeby nic nie zostalo tez w `rider_activity_cells`.
PLIK spod `gpx_url` ZOSTAJE SUROWY i to jest decyzja (2026-08-26): jest ZRODLEM
DYSTANSU, bo `EditionTrack::distanceFromFile` czyta go ponownie przy powiazaniu
przejazdu z turnusem — przyciety oryginal po cichu zabieral kilometry dojazdu
z domu. Osłona jest po stronie WYJSCIA, nie danych: klucz `all` warstwy `slady`
nie bierze `rider_activities` (`Models\TileSource::tracks`), a adres pliku
wychodzi z feedu tylko wlascicielowi (`Support::trackUrlsForFeed($rides, $viewerId)`).
Chcac kiedys pokazac solo publicznie, trzeba OSOBNEGO pliku przycietego,
a nie nadpisywania oryginalu) ·
`080` własna nazwa przejazdu solo
(`rider_activities.name`, 2026-09-05 — WARSTWA nad nazwą z licznika, nie
zamiennik: priorytet w `RiderActivity`/`RideController::rideName()` to
własna nazwa → `device_activities.activity_name` → generyczny opis. Edycja
inline (ołóweczek) na `/przejazd/{id}`, tylko właściciel i tylko solo —
`RiderActivity::rename()`, ten sam strażnik `findSoloForUser` co przy
kasowaniu) ·
`048` kolejnosc pol wzdluz trasy
(`known_route_cells.sort_order` — bez niej linia znanej trasy na mapie byla
zygzakiem po kolejnosci wstawiania; trasy sprzed migracji maja NULL i sie nie
rysuja. Obiecany w migracji `backfill_known_route_order.php` NIGDY NIE POWSTAŁ —
od 2026-08-19 robi to przycisk „Przelicz pola" w panelu, czyli
`KnownRoute::replaceGpx()` na pliku, który trasa już ma) ·
`047` rodzice pola na mapie
(`discovery_cell_totals.parent_res0..3` — **naprawa znikających heksagonów**.
Mapa grupowała pola po PROSTOKĄCIE `FLOOR(cell_q / krok)`, bo tylko to da się
zapisać w SQL-u, a potem pytała `parentCell()` o heksagon reprezentanta. To dwa
różne parkietaże: zmierzone na danych dev — 199 z 274 grup przy res=3 zawierało
pola należące do RÓŻNYCH rodziców, cała grupa lądowała pod jednym z nich, reszta
nie rysowała się wcale; przy res=0 wychodziło 5 realnych rodziców i 4 grupy.
Rodzic liczy teraz `DiscoveryGrid::parentsFor()` przy zapisie i trzyma go kolumna.
**Po wdrożeniu OBOWIĄZKOWO `backfill_discovery.php --rebuild`** — bez tego pola
mają `parent_* = NULL` i wypadają z mapy przy każdym oddaleniu.
Przy okazji usunięte `DiscoveryGrid::axialStepTo()` — istniało wyłącznie dla tego
błędnego grupowania) ·
`046` zdjęcie znanej trasy
(`known_routes.cover_photo_url` — karty tras w projekcie grafika mają fotografię.
Miało własną akcję `/admin/znane-trasy/{id}/zdjecie`, bo panel nie umiał wtedy
edytować trasy w ogóle; od 2026-08-19 zdjęcie jest zwykłym polem formularza edycji
(`/{id}/edytuj`). Niezmiennik został ten sam: **nie wgrywa się trasy od nowa**, żeby
cokolwiek w niej zmienić — nowe id trasy = progi w rejestrze punktów wskazujące na
nieistniejący już byt) ·
`045` usunięcie sum punktów z `rider_activities`
(`points_discovery`/`points_exploration`/`points_trails` — od 043 były tylko
cache'em, po przepięciu odczytów nie miały ani jednego czytelnika; trzy kolumny,
których nic nie aktualizuje, a które wyglądają na aktualny stan punktów, to
dokładnie ten „wynik bez wiadomo skąd", przed którym ostrzega brief) ·
`044` Event Bonus
(`events.point_bonus`, NULL = brak — model ma UMOŻLIWIAĆ nagrodę za udział
w konkretnym wydarzeniu, nie dawać jej każdemu; wartość ustawia się dziś
wprost w bazie, ekran w panelu dojdzie z 8A/15) ·
`043` Ridemore Points
(`point_transactions` — rejestr naliczeń z idempotencją na kluczu unikalnym;
+ `rider_activities.elevation_gain_m`, + bonus per trasa na `known_routes`) ·
`042` ślady z odbytego wyjazdu
(`edition_tracks` — Discovery przestaje liczyć z trasy PLANOWANEJ; **po wdrożeniu
mapa jest pusta, dopóki ktoś nie wgra pierwszego śladu** — to poprawne) ·
`041` licznik przejazdów przez pole
(`rider_activity_cells` + `discovery_cell_totals.passes_count` — warstwa heatmapy;
po wdrożeniu OBOWIĄZKOWO `backfill_discovery.php --rebuild`, inaczej licznik
zostanie zerowy dla całej historii) ·
`040` Discovery Grid
(heksagonalne pola odkrywane jazdą — `rider_activities`, `discovery_cells`,
`discovery_cell_totals`, `known_routes`, `known_route_cells`; po wdrożeniu
uruchomić `backfill_discovery.php --rebuild`) ·
`035` „Byłem" — uruchomienie
istniejącej, nigdy nieużywanej tabeli `event_attendance` (kolumny deklaracji
obecności; patrz `md/features.md`) · `034` FAQ w dyskusji
(`event_comments.is_faq` — pytanie i odpowiedź wpisywane razem przez organizatora;
te pary idą też do structured data przez `JsonLd::forFaq`) · `033` czwarty typ eventu 'wyscig'
(sam wpis w słowniku `event_type` — bez własnej tabeli/logiki, patrz
`md/features.md`).
