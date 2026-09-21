# Widoki i front-end

PHP jako szablony (bez silnika), jeden arkusz CSS, Alpine.js z CDN. Brak buildu.

## Renderowanie

`Utils\View::render($section, $page, $data)` → wykonuje `views/{section}/pages/{page}.php`
z `extract($data)`, buforuje wynik do `$content`, potem `require views/{section}/layout.php`.
`$section` = `web` albo `admin` (obie sekcje dziś dzielą ten sam `views/web/layout.php`
przez ścieżki; strony admina leżą w `views/web/pages/`).

## Layout — [`views/web/layout.php`](../views/web/layout.php)

Zmienne opcjonalne nadpisywane przez `$data`: `$title`, `$description`, `$canonical`,
`$ogImage/$ogType`, `$jsonLd`, `$noindex`, `$extraHead` (np. `Support::gpxMapHead()` dla mapy),
`$bodyClass` (dziś tylko `event-page` — pasek `.mbar` na mobile). Ładuje: Google Fonts
(Archivo/Instrument Sans/IBM Plex Mono/Big Shoulders), `assets/css/style.css`,
`assets/js/ui.js` (defer), `assets/js/native.js` (defer), Alpine.js (CDN, defer).
gtag tylko na prod. Wstawia `partials/header.php` → `$content` → `partials/footer.php`.

**Tryb aplikacji mobilnej** (Capacitor, 2026-08-22 — kontrakt
[`tasks/active/apka-mobilna.md`](../tasks/active/apka-mobilna.md)). Apka to WebView
ładujący TEN SAM serwis pod tym samym adresem, więc layout musi umieć wyglądać na dwa
sposoby. Rozpoznanie robi `APP_IS_APP` w [`core/bootstrap.php`](../core/bootstrap.php)
(dopisek `ridemore-app` w User-Agencie), a layout przekłada je na trzy rzeczy:
klasę `is-app` na `<body>`, `viewport-fit=cover` w `<meta viewport>` (tylko wtedy
przeglądarka wystawia `env(safe-area-inset-*)`) i `partials/app-nav.php` po stopce.
Style siedzą na końcu `style.css` w bloku `body.is-app` — stopka schowana, nagłówek
ZOSTAJE (mieszka w nim konto i wiadomości), a `.k-nav`/`.mbar` przesunięte o wysokość
paska, bo wszystkie trzy są `fixed; bottom:0`.

**Ekrany pełnoekranowe w apce** (Etap 1 przebudowy, 2026-08-28) — czwarty
opcjonalny bodyClass, `map-page`, obok `is-app`. Kontroler dokłada go do
`$data['bodyClass']` tylko w trybie apki (patrz `discovery-app.php` wyżej).
`body.is-app.map-page` zeruje zapas na dole i zamienia `.wrap` w kolumnę
flex — nagłówek dostaje naturalną wysokość, treść (`.app-map-page`) bierze
CAŁĄ resztę, a `.app-nav` (już `fixed`) pływa nad nią z półprzezroczystym,
rozmytym tłem. Ten sam wzorzec nadaje się każdemu kolejnemu ekranowi apki,
który ma wypełniać ekran zamiast przewijać się jak strona web.

## Strony — `views/web/pages/*.php`

Wydarzenia: `event-page.php` (strona eventu — największy widok; w `APP_IS_APP`,
Faza 5 przebudowy UX apki, 2026-08-29: tylko okruszki pominięte — reszta strony
(karty planu dnia, `.book`/`.mbar`, kolaps `@media(max-width:980px)`) była już
mobilnie dojrzała sprzed tej sesji, prawdopodobnie ŹRÓDŁO wzorca `.mbar` reużytego
w Fazie 4 — patrz `tasks/done/apka-mobilna-ux.md`), `event-form-wizard.php`
(kreator dodawania), `event-form.php` (edycja), `event-confirmation.php`, `event-reserve.php`,
`event-participants.php`, `participant-payments.php`, `events-list.php`
(w `APP_IS_APP`, Faza 2 przebudowy UX apki 2026-08-29: bez okruszków,
istniejący przełącznik filtrów `.filters.is-open` dostaje wygląd pełnego
dolnego arkusza zamiast inline show/hide — patrz `tasks/done/apka-mobilna-ux.md`).
Organizatorzy: `organizer-profile.php`, `organizers-list.php`, `organizers-admin.php`,
`organizer-admin-edit.php`. Konto/panel: `dashboard.php`, `account.php` (w `APP_IS_APP`,
Faza 4 przebudowy UX apki, 2026-08-29: bez okruszków — `.settings-*` był już jednym
wzorcem karty na wszystkich sekcjach, nic więcej nie trzeba było dociągać), `billing-profile.php`,
`payments.php`, `taxonomy-admin.php`, `known-routes-admin.php` (lista) +
`known-route-edit.php` (formularz trasy: dodawanie i edycja), `my-rides.php`
(„Moje przejazdy" — DWIE ZAKŁADKI od 2026-08-23: wyjazdy i przejazdy solo,
`.disc-tabs` + `?tab=solo`; modal wyboru `.pick-modal`/`.pick-opt` na natywnym
`<dialog>` służy powiązaniu przejazdu solo z wyjazdem z obu stron tabeli,
a JS ustawia opcje wg odległości od daty przejazdu), `discussions.php` (moderacja dyskusji), `points-admin.php`,
`tiles-admin.php`, `treasures-admin.php` (**warsztat skarbów**: mapa po lewej, formularz
po prawej, `.tr-work`; klik w pinezkę wybiera punkt i czyni go przeciągalnym, klik w pustą
mapę stawia nowy, lista pod spodem ma szukanie/filtry/strony — szczegóły
w [`features.md`](features.md)), `users-admin.php`. Discovery (Etap 8):
`discovery.php` — **JEDEN widok** dla mapy osobistej i wspólnej (jeden przełącznik
kontekstu, patrz [`features.md`](features.md); osobne `discovery-mine.php`/
`discovery-community.php` już nie istnieją). `discovery-app.php` (Etap 1
przebudowy apki mobilnej, 2026-08-28) — pełnoekranowy odpowiednik WYŁĄCZNIE dla
`APP_IS_APP`: `Controllers\DiscoveryController::index()` renderuje TEN szablon
zamiast `discovery.php` z tymi samymi danymi (inny widok, nie inne zapytania).
Bez hero/kafli/karty celu/panelu przejazdów/przełącznika moja-wspólnota —
sama mapa (silnik i warstwy TE SAME co `discovery.php`, przez
`ridemoreDiscoveryMap`/`partials/map-layers.php`) plus mały chip statystyk
(`.app-map__stats`) i żywa kropka pozycji (`RM.native.watchPosition`, nowość
w `assets/js/native.js` obok jednorazowego `position()`). Znane trasy: `trails.php` (katalog),
`trail.php` (jedna trasa — od 2026-09-10 zdjęcie idzie przez **`.op-cover`**,
TEN SAM komponent co `organizer-profile.php`, zamiast dawnego osobnego
`.box`+`<img>`; nazwa trasy nakłada się na zdjęcie, patrz „Nazwa na zdjęciu
.hero" niżej; opis trasy (`.op-head__sub--full`) przeniesiony pod sekcję
„Przebieg" — nie stoi już w nagłówku). Jeden przejazd: **`ride.php`** (`/przejazd/{id}`,
2026-09-03) — zbudowana WYŁĄCZNIE z gotowych komponentów strony trasy
(`.op-head`, `stat-tiles`, `.disc-map` + `partials/map-layers.php`,
`.profbox`/`.day-profile-big`/`.climbs`, karty `.disc-trail`, `.facts-list`).
Sekcja „Z tego wyjazdu" nie opisuje wyjazdu po swojemu — renderuje
`partials/event-card.php` w `.event-grid` (dane z `Event::cardsForEditions()` →
`EventCardResource`), więc adres `/events/{slug}`, okładka, daty i stan zapisu
przychodzą z komponentu; galeria to `.photo-gallery` + kafelki `.ph-link`
obsługiwane przez `partials/photo-lightbox.php`.
Ślad rysuje `ridemoreFocusTrack(map, url, {color})` — ten sam helper, którym
podświetla się przejazd na każdej innej mapie, tylko w KOLORZE PRZEJAZDU
(`opts.color` dodane tego dnia): tu ślad jest tematem strony, a nie jednym
z wielu wybranym z listy, więc niebieski „wyboru" byłby kłamstwem. Widok sam
z siebie NIE ROZSTRZYGA prywatności — dostaje gotowe `trackUrl`/`isTrimmed`
z `RideController` i pokazuje ostrzeżenie o przyciętych końcach, gdy ogląda
cudzy przejazd solo. Skarby: `treasure-scan.php` (ekran spod kodu QR),
`treasure-propose.php` (zgłoszenie — układ horyzontalny: mapa lewa, formularz prawy,
wyszukiwarka w formularzu, warstwa istniejących skarbów na mapie) — w `APP_IS_APP`
`TreasureProposalController::form()` renderuje zamiast niego `treasure-propose-app.php`
(Faza 3 przebudowy UX apki, 2026-08-29: mapa pełnoekranowa jak `discovery-app.php`,
tap stawia pinezkę i otwiera formularz jako `.disc-panel`; tabela zgłoszeń w poczekalni
świadomie pominięta — patrz `tasks/done/apka-mobilna-ux.md`), `treasure-print.php` (arkusz naklejek).
Społeczność: `pulse.php`, `chronicle.php`, `rider-profile.php` (w `APP_IS_APP`, Faza 4
przebudowy UX apki, 2026-08-29: na cudzym profilu przycisk „Napisz wiadomość" w treści
zastępuje przypięty dolny pasek `.mbar` — ten sam komponent co przycisk zapisu na
`event-page.php` — patrz `tasks/done/apka-mobilna-ux.md`).
Auth: `login.php`, `register.php`, `register-complete.php`,
`forgot-password.php`, `reset-password.php`, `strava-complete.php`. Messenger: `messages.php`
(w `APP_IS_APP`, Faza 1 przebudowy UX apki 2026-08-29: bez okruszków,
wysokość aktywnego wątku doliczą `--app-nav-h` — przełącznik jednopanelowy
sam w sobie już istniał w web-mobile, patrz `tasks/done/apka-mobilna-ux.md`).
Treści/statyczne: `home.php`, `how-it-works.php`, `for-organizers.php`, `recaps.php`,
`terms.php`, `privacy.php`, `recap-form.php`, `review-form.php`.

## Partiale — `views/web/partials/*.php`

**`breadcrumbs.php` — w apce nie renderuje NIC (2026-09-11).** Bramka
`if (APP_IS_APP) { return; }` stoi w samym partialu, nie w szablonach. Zasada
istniała od Faz 1/2/4/5 przebudowy UX, ale była zapisana w CZTERECH stronach
(`messages`, `events-list`, `account`, `event-page`) przez `if (!APP_IS_APP)`
wokół `require` — a okruszki dołącza ponad dwadzieścia szablonów. Audyt przy
prawdziwym UA apki i 375 px pokazał je na stronie trasy, przejazdu, „moich
przejazdów", kroniki i profilu; na stronie przejazdu zawijały się do dwóch
linii i zjadały górę ekranu. Powód jest mocniejszy niż miejsce: okruszki to
nawigacja po drzewie serwisu, a apka nawiguje dolnym paskiem i gestem wstecz.
**JSON-LD `BreadcrumbList` wychodzi razem z nawigacją** — to dane dla robota,
a żaden robot nie przedstawia się jako `ridemore-app`. Pilnuje tego test
„apka: okruszki gasi JEDNO miejsce, nie każdy szablon z osobna".

**`nav-button.php` — „Nawiguj do…" (2026-09-10).** Oczekuje `$navLat`/`$navLon`
(brak = nie renderuje nic — trasa sprzed backfillu profili albo wydarzenie bez
pinezki zbiórki), `$navLabel`, `$navClass`. Adres to
`maps/dir/?api=1&destination=…&travelmode=bicycling` — **`dir/` URUCHAMIA
prowadzenie**, dawne `maps?q=` na stronie wydarzenia stawiało tylko pinezkę.
W apce działa bez żadnej wtyczki: obcy host wychodzi z WebView intentem
(`Bridge.launchIntent`), a `target="_blank"` tego nie psuje, bo Capacitor nie
włącza `setSupportMultipleWindows`. **Napis MUSI mówić, dokąd wiezie**
(„do miejsca zbiórki", „na start trasy") — samo „Nawiguj" należy od tej daty do
modułu prowadzenia po śladzie (`tasks/nawigacja-w-apce-ODLOZONE.md`).
Używają: `event-page.php` (zbiórka), `trail.php` (start trasy z pierwszej próbki
profilu, `TrailController::startPoint`). **Strona przejazdu świadomie NIE** —
start cudzego śladu solo to okolica czyjegoś domu, którą §27 właśnie przycina.

**Skorupa apki — dwie liczby, nie dwadzieścia (2026-08-29, zgłoszenie usera
z telefonu: „header (…) są różne i nie są spójne", „przycisk warstw oraz
punktacji nie jest na tym samym poziomie").** Dwa rozjazdy, obie naprawy przez
zmienną zamiast punktowej łatki:
- `--app-wrap-pad` (24 px, na mapie `0px`) — `.wrap` na `map-page` traci wcięcie,
  żeby mapa była pełnoekranowa, ale `<header>` siedzi w tym samym `.wrap`.
  Belka rekompensuje to ujemnym marginesem o wartość zmiennej i dokłada sobie
  24 px w środku, więc na KAŻDYM ekranie apki wygląda identycznie: kreska pełną
  szerokością, treść 24 px od brzegu. Zmierzone: `[0, 393]` i logo na 24 px
  na obu ekranach.
- `--app-map-pad` (10 px) — wspólny odstęp nakładek od krawędzi mapy dla
  `.map-layers` i `.app-map__stats`. Wcześniej pierwsza brała `top:84px`
  z arkusza wspólnego (odstęp pod pasek narzędzi, którego w apce nie ma),
  a druga `12px + env(safe-area-inset-top)` — a wycięcie ekranu bierze na siebie
  nagłówek stojący nad mapą, więc było liczone drugi raz. Zmierzone: obie
  dokładnie 10 px od górnej krawędzi mapy.

**`app-nav.php`** (2026-08-22, środek zmieniony 2026-08-28) — dolny pasek
nawigacji, renderowany WYŁĄCZNIE gdy `APP_IS_APP`. Pięć slotów: Wyjazdy /
Zgłoś / **Mapa (wyniesiony okrąg pośrodku, `.app-nav__hero`)** / Skanowanie
(zwykły przycisk) / Profil. Od Etapu 1 przebudowy apki to Mapa dostaje
wyniesione kółko 56 px pośrodku (link do `/odkrycia`, który w trybie apki
renderuje `discovery-app.php` — patrz wyżej) — wcześniej miało je skanowanie,
ale to właśnie mapa jest teraz środkiem apki. Skanowanie zostaje jedyną
CZYNNOŚCIĄ paska (nie miejscem), więc jako jedyne jest `<button>`, nie `<a>` —
obsługę podpina `data-rm-scan` w `assets/js/native.js`.
Skoro pasek istnieje tylko w apce, jego przyciski mogą zakładać obecność mostu
natywnego i nie potrzebują wariantu przeglądarkowego.
**JEDEN ZESTAW ZNAKÓW I JEDNA TYPOGRAFIA (2026-08-29, zgłoszenie usera „ikony
w footer są różne i podpisy też")** — pasek mieszał wcześniej trzy systemy:
ikony konturowe (`calendar`, `user`), ikonę WYPEŁNIONĄ (`pin`) oraz emoji 🗺️
i znak typograficzny ⌗, czyli dwa kształty rysowane fontem systemu, więc na
każdym telefonie inne. Dziś wszystkie pięć to SVG z Lucide (ISC) w `Utils\Icon`:
24×24, `fill="none"`, `stroke-width 2` — `calendar`, `pin-add` (pinezka
Z PLUSEM, bo ten slot ZGŁASZA punkt, a nie pokazuje istniejący; wypełniony
`pin` zostaje tam, gdzie znaczy MIEJSCE), `map`, `scan`, `user`.
Slot mapy jest teraz zwykłym `.app-nav__i` **z podpisem**, a wyniesiony okrąg
zszedł do wewnętrznego `.app-nav__ring` — wcześniej jako jedyny nie miał
podpisu widocznego dla oka (miał tylko `aria-label`).
Przy okazji naprawiony REALNY błąd typografii: `.app-nav__i--btn` miało
`font:inherit`, co ustawiało krój i rozmiar na wartość odziedziczoną z `.app-nav`
i — stojąc w arkuszu po `.app-nav__i` przy tej samej wadze selektora — wygrywało
z nią. „Skanuj" jechało krojem tekstowym w rozmiarze bazowym obok podpisów
`--f-m` po 10 px. Zweryfikowane zrzutem paska, nie samym czytaniem CSS.

**`event-roster.php`** (2026-08-12) — „Kto jedzie", skład turnusu. Zwykły `require` (nie
funkcja): korzysta z domknięć zdefiniowanych u góry `event-page.php` — `$rosterName`,
`$rosterInitials`, `$rosterPlural`, `$rosterSentence`, `$rosterLink`, `$plural`,
`$rosterShowNames` — oraz z `$roster`/`$pelotonHere`. Wołany z `<aside>`, wewnątrz
`.book-rail`, POD panelem zapisu.

**`track-upload.php` — `renderTrackUpload($o)`** (Etap 8, 2026-08-12). Wgrywanie ŚLADU
Z ODBYTEGO WYJAZDU — jedyne źródło odkryć Discovery. Renderowana w DWÓCH miejscach:
sekcja `#slad` na `event-page.php` (poza warunkiem `$hasChronicle`!) i na `chronicle.php`
(nad blokiem „Dorzuć swoje"). Przyjmuje `eventSlug/editionId/tracks/canManage/isAttendee/
viewerId/backTo`; sama dobiera treść dla organizatora (ślad wspólny) i uczestnika (własny),
pokazuje listę śladów z usuwaniem, komunikaty `blad`/`info` z adresu (PRG) oraz ostrzeżenie
„Twoje odkrycia liczą się z Twojego śladu", gdy uczestnik ma własny.
Powstała po zgłoszeniu usera, że kontrolka „w ogóle nie jest widoczne" — pierwsza wersja
była dopiskiem w stopce bloku „Relacje", czyli **nie istniała dla wyjazdu bez kroniki**.

**`ride-sources.php`** (2026-08-23) — ZAKŁADKI ŹRÓDŁA ŚLADU na „Moich przejazdach": Plik (GPX/FIT), Garmin, Polar, Wahoo, COROS, Suunto. Jeden partial na całą kartę, bo ekran ma zostać tabelą przejazdów, a nie sześcioma formularzami integracji. Plik jest pierwszy świadomie (działa zawsze i bez niczyjego API). Zakładka idzie adresem `?zrodlo=`, żeby powrót z OAuth mógł wskazać właściwą; kropka przy nazwie = konto podłączone. Garmin ma podpanel `source-garmin.php` (jako jedyny ma formularz logowania hasłem). Komponent `.src-tabs`/`.src-tab` jest OSOBNY od `.disc-tab` i to różnica znaczeniowa: pastylka zawęża listę, zakładka przełącza treść (prośba usera: „niech wyglądają jak zakładki, a nie jak pastylki").

**`ride-feed.php`** (2026-08-24) — OSTATNIE PRZEJAZDY jako panel przy mapie, jeden moduł
dla mapy osobistej i społecznościowej (`$feedShowRider` dokłada autora). Zastąpił dwie
połówki tej samej rzeczy: listę naliczeń punktowych w panelu (bez daty, pól i kliknięcia)
oraz sekcję kart „Co dały ostatnie wyjazdy" pod mapą (bez związku z mapą). Wiersz jest
PRZYCISKIEM — kliknięcie ustawia kadr na tym przejeździe (`ridemoreRideFeed`
w `discovery-map.js`). Stronicowanie robi przeglądarka, po 3 pozycje: panel stoi NA MAPIE,
więc przeładowanie strony gasiłoby kadr i warstwy. Szczegóły → [`features.md`](features.md).

**`region.php` / `regions.php`** (2026-09-14) — strona regionu i spis/kraj/404. Szkielet = `trail.php` (`.op-head`, `.op-cover` z okładką z najbliższego wyjazdu albo trasy regionu, `renderStatTiles`, `.disc-map` + `map-layers.php`), karty `event-card`/`trail-card`/`organizer-card`/`treasure-list`, „Kto tu jeździ” = `.roster/.avs` + `renderRiderAvatar` jak „Mają ją całą”, inne regiony = `.list-seo .chip-links`; spis używa `.regions-grid .region-link` ze strony głównej. Pusta sekcja się nie renderuje (poza wyjazdami — zaproszenie „Dodaj wyjazd”). **`organizer-card.php`** (2026-09-14) — karta `.org-card` przeniesiona 1:1 z `organizers-list.php`, oczekuje `$o` z `coverPhotos`. Linki do stron regionów: okruszek regionu na wydarzeniu, tag regionu na trasie, „Region” na profilu organizatora, chipy SEO na `/wydarzenia`, „Przeglądaj po regionach” na stronie głównej, stopka. **`region-link.php`** (2026-09-14, zgłoszenie usera „nie da się kliknąć w region”) — `renderRegionLinks(labels, class, separator)` ZWRACA HTML: rozbija podpis „a, b” i linkuje każdy aktywny region osobno (`Region::pathForName`), resztę zostawia tekstem. Użyte: tagi i fakt „Region” na wydarzeniu, chipy specjalizacji organizatora, kronika (tag + zdanie o debiutantach), Puls (plan peletonu, fakty skarbu i wyjazdu, debiutanci), „Trasa dnia”, profil rowerzysty („Jedzie na”, wyjazdy), strona przejazdu, skan skarbu, moje przejazdy, konto („Co wiemy z Twoich zapisów”). `region-emblems.php` — emblemat i nagłówek kraju są linkami; dymek skarbu na mapie dostaje `region_url` z `/api/treasures`. **Świadomie BEZ linku:** nazwa regionu wewnątrz kart, które same są linkiem (karta wydarzenia, trasy, organizatora, kroniki, wpis feedu profilu, wiersz feedu odkryć, przyciski panelu skarbów) — link w linku to niepoprawny HTML; tekst do skopiowania na Facebooka (potwierdzenie zgłoszenia); panel admina.

**`trail-card.php`** (2026-09-10) — KARTA ZNANEJ TRASY (`.disc-trail`), jedna dla całej
aplikacji: `renderTrailCard($route, $loggedIn, $opts)`. Ten sam kafelek stał wcześniej
skopiowany w `trails.php` (katalog `/trasy`) i `discovery.php` (sekcja „Znane trasy"),
a sekcja „W okolicy tej trasy" na `/trasy/{slug}` byłaby trzecią kopią; kopie zdążyły
się rozjechać (stopka dla gościa tylko w jednej, akcent „prawie" tylko w drugiej).
Różnice ekranów zostały OPCJAMI, nie osobnymi plikami: `near` (dopisek „do progu X% —
brakuje Y", `DiscoveryScoring::trailThresholds()`), `guestFoot` („N pól do zaliczenia"
dla niezalogowanego), `note` (własny podpis zamiast stopki postępu — strona trasy pisze
tam „krzyżuje się z tą trasą" / „biegnie w pobliżu"). Wiersz wejściowy = kształt
`KnownRoute::progressForUser()`/`nearby()`.

**`map-layers.php`** (2026-08-19, DRZEWO od Etapu 2 — 2026-08-26) — KONTROLKA WARSTW
MAPY, jedna dla całej aplikacji. Oczekuje `$mlId` (id `<details>`, JS czyta z niego
stan checkboxów) i `$mlLayers` — DRZEWO węzłów `[['key','label','hint','on','children'
=> [...]], ...]`, dziś zawsze wynik `Models\MapLayer::tree()` (patrz `models.md`),
ewentualnie przez `MapLayer::withQueryOverrides()`. Renderuje dowolną głębokość
rekurencyjnie (`renderMapLayerNodes`), choć tylko „Skarby" mają dziś dzieci. **Dziecko
gaśnie razem z rodzicem CZYSTYM CSS, bez JS**: `.map-layers__children` stoi ZARAZ PO
`<label>` swojego rodzica, więc `label:has(> input:not(:checked)) + .map-layers__children
{display:none}` chowa cały blok na odznaczenie. `data-children-of` na tym bloku to
jedyne, czego potrzebuje JS (`ridemoreReadLayers`), żeby stan dziecka liczyć poprawnie
NAWET gdyby jakaś przeglądarka `:has()` nie wspierała — to wyłącznie UI, stan wysyłany
do serwera zostaje poprawny tak czy inaczej.
Powstała na prośbę usera („nie chcę, żeby w aplikacji używano czegoś innego niż
standardowej kontrolki mapy") — ten sam blok stał skopiowany w `discovery.php`
i `rider-profile.php`, a `trail.php` nie miał go wcale. Kopie zdążyły się już różnić
stanem domyślnym warstw; od Etapu 2 stan domyślny, podpisy i struktura idą ze słownika
`map_layer` (migr. 072), a stronie zostaje wyłącznie PRZEKAZANIE `$mlLayers` z kontrolera
(**do strony nadal należy TYLKO to, co dictionary nie może wiedzieć**: czy strona
w ogóle jest mapą odkryć — `only` w `MapLayer::tree()` — i ewentualny patch węzła `cells`
dla stron, gdzie mgła jest dodatkiem, nie tematem, jak strona trasy i panel dnia
wydarzenia — mgła zapala się tam **tylko dla zalogowanego**, bo welon na 55% krycia
gościowi z wyszukiwarki przykryłby to, po co przyszedł). Wymaga rodzica z
`position: relative` (`.disc-map`, `.rp-map__box`).
**Pełny ekran (2026-08-23) NIE należy do tej kontrolki** — przycisk w prawym dolnym rogu
dokłada `ridemoreCreateMap()` w `assets/js/gpx-map.js`, więc ma go każda mapa w serwisie,
także te bez przełącznika warstw. Rozciągany jest WRAPPER (ten sam, którego wymaga ten
partial), żeby warstwy i legenda nie zostały poza ekranem. Szczegóły → [`features.md`](features.md).

**„Tu jestem" (2026-09-10) — tak samo z fabryki, nie z kontrolki warstw.**
`ridemoreCreateMap()` dokłada obok pełnego ekranu drugi przycisk (`ridemoreAddLocateControl`,
prawy dolny róg): pytanie o pozycję przez most (`RM.native.position`, więc w apce natywna
wtyczka, w przeglądarce silnik przeglądarki) i `map.setView` na wyniku. **Trzy stany są
tu funkcją, nie ozdobą**: „Szukam sygnału GPS…" zapala się PRZED zapytaniem (zimny fix
trwa do 30 s, więc bez tego przycisk przez pół minuty wygląda na zepsuty), sukces gasi
komunikat, a błąd pokazuje treść z mostu — z rozróżnieniem odmowy zgody od braku sygnału.
Uchwyt `map.ridemoreLocate()` istnieje po to, żeby strona nie pisała drugiej kopii: woła go
środkowy slot dolnego paska apki (`[data-rm-map-here]` na `/odkrycia`), który wcześniej miał
własną, milczącą przy błędzie wersję. Przed własnym `setView` kontrolka wysyła zdarzenie
`ridemore:locate` — `discovery-app.php` zdejmuje na nim blokadę „user ruszył mapą palcem".
W apce zoom i pełny ekran są schowane (`app.css`), a ten przycisk **zostaje** i podnosi się
ponad pływający `.app-nav` (oraz ponad uchwyt szuflady, gdy ekran ma `.disc-panels`).

**Arkusz apki (`assets/css/app.css`) — dwie rzeczy z 2026-09-11.**
(1) `body.is-app .op-head__act` układa pasek akcji pod nagłówkiem w kolumnę
z przyciskami pełnej szerokości. Komponent jest wspólny dla czternastu stron;
na desktopie rząd dobrany do treści jest poprawny, ale na telefonie `flex-wrap`
zostawiał trzy różne prawe krawędzie jedna pod drugą (zmierzone na `/trasy/{slug}`:
231, 220 i 225 px). (2) **Komentarze w tym pliku muszą się domykać** — stał tu
akapit zakończony DRUGIM znacznikiem zamykającym, przez co CSS połknął idącą
za nim regułę `body.is-app.map-page .app-offline` (wskaźnik „brak zasięgu"
przestał pływać nad mapą i wchodził w przepływ strony). Arkusz się ładował,
nic nie krzyczało — usterka była niewidoczna przez samo czytanie. Od tej daty
pilnuje tego test „arkusze: żaden komentarz CSS nie zostaje otwarty ani
zamknięty dwa razy", który skanuje `app.css` i `style.css`.

**KAŻDA WARSTWA, KTÓREJ STRONA NIE POKAZUJE W KONTROLCE, MUSI IŚĆ DO MAPY JAKO `null`,
nie jako `true`** (poprawka 2026-08-23). Dotyczy dziś `treasuresFound` („Skarby zdobyte"),
którego nie ma profil rowerzysty ani żadna ze stron oglądana przez gościa. `true`
znaczyło „warstwa zapalona i nie do ruszenia", przez co zgaszenie „Skarbów" wychodziło
na serwer jako `stan=moje` zamiast „nie pytaj" — i przełącznik nie robił nic.
`null` znaczy „tej warstwy tu nie ma", a wtedy `discovery-map.js` gasi ją razem
z warstwą nadrzędną. Szczegóły i objawy → [`features.md`](features.md), sekcja
„Warstwy mapy odkryć".
**Strona wydarzenia dołączyła 2026-08-20** (zgłoszenie usera: „mapa wydarzenia nie jest
spójna z pozostałymi mapami w aplikacji"). Do tej daty moduł „Mapa i profil trasy" miał
goły Leaflet ze śladem — bez pól odkryć, znanych tras, skarbów i bez przełącznika warstw.
Teraz każdy panel dnia (i „Cały wyjazd") dostaje WŁASNĄ kontrolkę o id `mapLayers-{klucz}`
i legendę `legend-{klucz}` — bo każdy panel to osobna mapa Leafletu. Kadr należy do ŚLADU:
`fitBounds` po wczytaniu GPX-a jest tam jedynym właścicielem kadru, więc moduł dostaje
`fitToCells: false` i **żadnego `bounds`** (przy mapie podanej z zewnątrz — `options.map` —
moduł i tak nie rusza kadru).
**`.mapbox` urosło do `clamp(380px,42vw,520px)`** (zgłoszenie usera: „trochę większa mapa,
bo nie mieszczą się wszystkie warstwy"). Zmierzone przy poprzednich 330 px: rozwinięta
kontrolka kończyła się 32 px pod krawędzią kadru przy czterech warstwach i ok. 80 px przy
pięciu (zalogowany ma jeszcze „Skarby zdobyte"), a `.mapbox` ma `overflow:hidden`, więc
po prostu ucinało. 520 px to dokładnie tyle, ile ma `.disc-map` — czyli geometria, w której
ta kontrolka mieści się z zapasem nad legendą (zmierzone po zmianie: 111 px luzu przy
pięciu warstwach, brak kolizji z legendą). Mapka punktu zbiórki zostaje przy 200 px —
pilnuje tego `.chr-actions .meet .mapbox`.
**Warstwa „Skarby zdobyte"** (2026-08-20) pojawia się TYLKO dla zalogowanego i tylko tam,
gdzie mapa jest o widzu (`/odkrycia`, `/trasy/{slug}`, strona wydarzenia) — na profilu rowerzysty skarby są
z definicji znaleziskami tej osoby, więc drugi przełącznik nie miałby czego dzielić.
Strona, która jej nie renderuje, musi podać `treasuresFound: true` w `layers` — `on()` na
nieistniejącym polu wywaliłoby cały skrypt mapy.

**`treasure-list.php`** (2026-09-03) — KARTY SKARBÓW leżących na trasie albo na
przejeździe. Oczekuje `$tlList` (wynik `Treasure::listOnRoute()` /
`listOnActivity()`: `items` + `hidden`) i opcjonalnego `$tlHiddenNote`.
Wydzielony ze `trail.php`, gdy strona przejazdu potrzebowała tego samego bloku —
ta sama zasada co przy `stat-tiles.php`. Ujawnienie robi MODEL (`reveal()`),
partial tylko rysuje; obsługę kliknięcia (`.js-atrakcja` →
`ridemoreFocusTreasure`) podpina strona, bo tylko ona wie, którą mapę przesuwać.
Zdanie o ukrytych miejscach jedzie razem z kartami — bez niego lista wygląda na
niepełną wobec kafla „Skarby po drodze".

**`elevation-profile.php`** (2026-09-03) — PROFIL WYSOKOŚCI + chipy podjazdów.
Oczekuje `$epProfile` / `$epPeaks` / `$epColor`; pusty profil nie renderuje nic,
więc strona nie musi tego sprawdzać. Zawiera wrapper `.day-panel active`, po
którym `ridemoreRenderElevationChart` znajduje chipy — najłatwiejszy do
zgubienia szczegół przy kopiowaniu tego bloku (a stał skopiowany w `trail.php`
i `ride.php`). Podpięcie do mapy to jedna linijka: **`ridemoreSetupElevationProfile(map)`**
z `assets/js/gpx-map.js` — helper zna bramkę na zerowy kontener (`ResizeObserver`,
wykres liczy się w REALNYCH pikselach i tylko raz), która wcześniej też stała
skopiowana w obu widokach.

**`metric-charts.php`** (2026-09-11) — TĘTNO / KADENCJA / MOC / TEMPERATURA
z pliku licznika, jako OSOBNE wykresy pod profilem wysokości (prośba usera:
„jak na Stravie — osobne wykresy na jednej skali km"). Oczekuje `$mcProfile`
(**te same próbki co `$epProfile`**) i `$mcChannels` (`Gpx::parse()`
→ `metricChannels`: klucz, etykieta, jednostka, kolor, średnia, maksimum).
Pusty zestaw kanałów nie renderuje nic — plik z planera jest normalnym
przypadkiem. Podpięcie: **`ridemoreSetupMetricCharts()`**.

*Skąd bierze się „jedna skala km":* nie z przeliczania, tylko z danych.
`Gpx::sampleProfile()` dokłada odczyty kanałów **do tej samej tablicy próbek,
pod tymi samymi indeksami** co wysokość, a `ridemoreRenderMetricChart` używa
**identycznych marginesów SVG** co `ridemoreRenderElevationChart` (`left: 44`) —
więc ten sam kilometr wypada w tym samym pikselu w każdym wykresie. Kursor jest
wspólny: `ridemoreProfileCursors` w `gpx-map.js` (najechanie na którykolwiek
wykres przesuwa pozostałe **i pinezkę na mapie**). Osobny wykres na kanał, nie
jeden z czterema liniami — bpm, rpm, W i °C nie mają wspólnej skali Y.
Kanał bez odczytów (czujnika nie było — same zera) i odczyt spoza zakresu
(tętno 900 z zerwanego pasa) **nie dostają wykresu**: jeden śmieć rozciąga
skalę tak, że reszta jest płaską kreską.

**EMBLEMATY — `.emblem-hex` / `.emblems` / `.emblem-promise`** (migr. 087,
2026-09-11). Nie ma tu partiala, bo bloki są dwa i różne: **karta na profilu**
(`rider-profile.php`, sekcja `#emblematy` nad „Zaliczonymi szlakami") i
**zapowiedź na stronie trasy** (`trail.php`, sekcja `#emblemat` nad skarbami).
Wspólny jest KSZTAŁT: `.emblem-hex` używa **tego samego nieregularnego
`clip-path`**, którym `/odkrycia` przycina zdjęcie celu (`.disc-goal__photo`) —
emblemat i cel odkryć to ten sam gatunek rzeczy, więc nie mogą mieć dwóch
różnych znaków firmowych. Bez grafiki kafelek dostaje wyblakłą ikonę `hex`, jak
pusty hex celu. Siatka `.emblems` to `.region-emblems` (emblematy regionów),
tylko ciaśniejsza; złoto `.emblem-promise--mine` to **to samo złoto** co
znaleziona pinezka i karta `.disc-trail--mine` (trzeci odcień byłby czwartym
znaczeniem tego koloru).

*Dlaczego kafelek, a nie pasek postępu jak „Kolekcja" skarbów w szynie obok:*
tam sens niesie licznik „3 z 8" (widać, ile brakuje), tutaj nie ma czego liczyć —
emblemat się MA albo nie ma, a katalog możliwych emblematów nie jest publiczną
listą do odhaczania.

*Pułapka złapana na żywo:* `url('...')` w łańcuchu w apostrofach wymaga
escape'owania, które w jednolinijkowym ternarnym w środku atrybutu HTML jest
nieczytelne i **raz wywaliło parser**. Oba widoki liczą więc `style` w bloku PHP
nad znacznikiem, a nie w atrybucie.

**`emblems-admin.php`** (2026-09-11) — panel `/admin/emblematy`: lista + formularz
na jednym ekranie, edycja w wierszu przez `<details>` (`.adm-akcje`). Kolumna
„Przypięty do" mówi wprost „do niczego — nikt go nie zdobędzie", gdy emblemat
nie wisi na żadnej trasie ani wydarzeniu: to najczęstszy stan tuż po założeniu
i bez tego zdania wygląda jak usterka.

**PANEL SKARBÓW — ODWRÓCONE PROPORCJE, `.tr-*`** (2026-09-12, zgłoszenie
usera: „ta część również jest nie do użytku"). `treasures-admin.php`
+ `.tr-work`/`.tr-grid`/`.tr-reveal` w `style.css`.

*Zmierzone przed zmianą (1440×900):* panel **360 px szerokości, 620 px
widocznej wysokości — a formularz w środku 1299 px**. To 2,2 ekranu
przewijania WEWNĄTRZ ramki (`max-height` + `overflow-y` na
`.tr-work__side`), 696 px do przewinięcia, i „Zapisz skarb" nigdy niewidoczny.
Podział był odwrotny do pracy: mapa 1040 px, a klika się na niej dwa razy
(postaw pinezkę, przeciągnij); formularz 360 px na dwanaście pól.

*Co zrobione:* `.tr-work` to teraz `520px | 1fr` — mapa oddała połowę
szerokości, panel ma 772 px. **`max-height` i `overflow-y` zdjęte z
`.tr-work__side`** (to one robiły z panelu okienko z własnym paskiem); panel
jest wysoki na tyle, na ile trzeba, i przykleja się do góry. Pola leżą
w `.tr-grid` (dwie kolumny, `--full` na całą szerokość); klasa `.tr-row`
zniknęła razem z wąskim panelem. Kolejność w HTML-u BEZ ZMIAN — mapa dalej
jest pierwszym dzieckiem, zmieniły się wyłącznie szerokości ścieżek siatki.

*Trzy rzeczy poza układem:* **`.tr-reveal` — poziom ujawnienia jako trzy karty
radio zamiast `<select>`** (to jedyna rzecz odróżniająca skarb od pinezki,
a stała jako jedno z sześciu identycznych rozwijanych pól); **pole „Trop"
pokazuje się tylko wtedy, gdy coś robi** (przy „Jawnym" nie ma odbiorcy —
wartość zostaje w polu, chowa się kontener); **`.tr-more` — status,
pochodzenie i widoczność złożone w `<details>`**, bo mają dobre domyślne i przy
stawianiu partii naklejek nie zmienia się ich ani razu. Zamknięty `<details>`
nadal wysyła swoje pola — sprawdzone round-tripem zapisu.

*Kontrakt z JS-em nie pękł:* `pola.reveal` to teraz obiekt z getterem
i setterem `.value`, więc `nowy()` i `wybierz()` zostały nietknięte. **Nazwa
pola i wartości bez zmian** (`reveal_level`, 2/1/0) — `TreasureController::save`
nie wie o tej zmianie i nie musi wiedzieć.

*Pułapka złapana na żywo:* obiekt startowy `dane[startId]` (wejście z listy
przez `?skarb={id}`) MUSI nieść to samo, co odpowiedź `/admin/skarby/punkty` —
brakowało w nim `code`, więc `wybierz(startId)` nadpisywał poprawnie
wyrenderowany przez serwer kod naklejki pustym stringiem. Niewidoczne, dopóki
panel kodu nie pokazywał. Osobny test pilnuje zgodności obu kształtów.

**EKRAN EDYCJI ZNANEJ TRASY — WARSZTAT, `.kr-*`** (2026-09-12, zgłoszenie
usera: „na dzień dzisiejszy nie da się tego używać"). `known-route-edit.php`
+ `partials/known-route-form.php` + przestrzeń nazw `.kr-*` w `style.css`.

*Co było nie tak i dlaczego to JEDNA wada, nie osiem:* pola dziedziczyły
`.form-row` (`display:flex`) + `.search-input` (`flex:1`), więc **każde**
zjadało cały wiersz — pole na 30-znakową nazwę trasy mierzyło **1210 px**,
dokładnie tyle samo co pole na czterocyfrową liczbę punktów. Reszta (brak
hierarchii, brak rytmu, „Usuń trasę" wyglądający jak „Przelicz pola") wynikała
z tego samego: skoro wszystko ma jedną szerokość, nic nie ma wagi.

*Dlaczego własna przestrzeń nazw, a nie poprawka `.form-row`:* tamten komponent
działa poprawnie tam, gdzie jest używany (kreator wydarzenia). Potrzebna była
inna reguła, nie naprawa cudzej. `.kr-field` jest domyślnie WĄSKIE (max 440 px);
szerokie (`--wide`) jest wyjątkiem, który trzeba nazwać.

*Układ:* `.kr-work` to grid `420px | 1fr` — po lewej `.kr-side` (sticky)
z podglądem trasy, po prawej pola. Podgląd składa się z **istniejących
komponentów**: `ridemoreDiscoveryMap` z kluczem kafli `kr-{id}` (pamięć
`feedback-one-standard-map`), `partials/elevation-profile.php`
i `partials/surface-breakdown.php`. Trasa bez GPX (świeżo dodawana) nie dostaje
pustej ramki — `.kr-work--solo` zwęża formularz do 760 px zamiast rozciągać go
na 1360.

*Trzy rzeczy poza układem:* fakty wchodzą na `.trust-bar` (ten sam komponent,
którym lista podsumowuje katalog — dotąd były cienką linijką bez przewyższenia
i bez informacji, czy ktokolwiek tą trasą jeździ); ostrzeżenie „nie rysuje się
na mapie" niesie SWÓJ przycisk „Przelicz pola" (`.kr-alert`; wcześniej dzieliło
je 600 px i osobna karta); `.kr-danger` oddziela akcje nieodwracalne i podpisuje
je SKUTKIEM, a `.btn--danger` jest jedynym groźnie wyglądającym przyciskiem na
ekranie.

*Zapis nie wyrzuca z ekranu:* dwa przyciski submit — „Zapisz" (zostaje, żeby
dało się zobaczyć skutek na podglądzie obok) i „Zapisz i wróć do listy"
(`name="zapisz_i_wroc"`). Rozstrzyga `name` KLIKNIĘTEGO przycisku, bo
przeglądarka wysyła tylko ten jeden — zero JS-a i zero pola ukrytego. Patrz
`KnownRouteController::wrocGdzieTrzeba()`.

**Strona publiczna `/trasy/{slug}` została nietknięta** (decyzja usera) — ta
przebudowa dotyczy wyłącznie panelu.

**`surface-breakdown.php`** (2026-09-11) — PASEK NAWIERZCHNI + legenda km/%.
Oczekuje `$sbBreakdown` (`asphaltPct`/`gravelPct`/`trailPct` + odpowiadające
`…Km`). Wydzielony z domkniętej funkcji `$renderSurfaceBreakdown`
w `event-page.php`, gdy nawierzchnia doszła do znanych tras (migr. 086) — tamta
była dla nich nieosiągalna. Wołający ma obowiązek **nie dołączać go, gdy
podziału nie wykryto**: brak danych to nie „0% asfaltu". Strona wydarzenia
trzyma domknięcie jako cienkie opakowanie, bo woła je w środku znacznika echa
i potrzebuje stringa.

**`trail-actions.php`** (2026-09-11) — TRZY PRZYCISKI STRONY TRASY („Nawiguj na
start", „Wyjazdy w tych stronach", „Pobierz GPX") jako jedna grupa. Powstał ze
zgłoszenia usera: GPX przeniesiono na okładkę 2026-09-10, a pozostałe dwa
zostały nad nią — jedna grupa akcji rozdzielona na dwa piętra strony. Ma **dwa
miejsca zamieszkania**: z okładką idzie w `.hero-acts` na zdjęciu, bez niej
zostaje w `.op-head__act` (ta sama reguła, którą rządzą się nazwa trasy
i metryczka). Stąd partial, a nie dwie kopie. „Wyjazdy w tych stronach" wysyła
`?regions=` — `EventsListController` czyta `$arr('regions')`, a wcześniejsze
`?region=` przelatywało bez śladu i przycisk prowadził na pełną listę.

**Nazwa na zdjęciu `.hero` — `.hero-bottom`/`.hero-ttl`/`.hero-acts`** (2026-09-10,
prośba usera: uspójnić wygląd strony trasy ze stroną wydarzenia i przenieść
nazwę na zdjęcie, z obwódką czytelną na dowolnym zdjęciu). Dwa zdjęcia-hero w
serwisie: `.cover` (`event-page.php`, `aspect-ratio:16/6.2`) i `.op-cover`
(`organizer-profile.php` + od tej daty `trail.php`, `aspect-ratio:16/5.2`;
oba mają jawne `width:100%` — bez tego `aspect-ratio` na wąskim ekranie liczy
szerokość WSTECZ z zaciśniętej przez `min-height` wysokości i rozciąga box
poza rodzica, złapane żywym testem mobile na `.op-cover` przy tej okazji).
Oba mają gradient-scrim (`.cover__grad`/`.op-cover__g`) i WSPÓLNY pionowy
kontener **`.hero-bottom`** (`position:absolute;left/right/bottom:16px;
display:flex;flex-direction:column`) — dzieci układają się jedno pod drugim
bez liczenia ręcznie wysokości sąsiada. `<h1>` w środku dostaje klasę
**`.hero-ttl`**: biały, `text-shadow` DWUWARSTWOWY jako DODATEK do gradientu
(sam gradient, dostrojony pod mały pasek organizatora, nie wystarcza pod
dużym nagłówkiem na jasnym zdjęciu). Na `event-page.php` `.hero-bottom`
niesie `<h1>` NAD `.cover__org` (pasek organizatora — treść i pozycja
względem obrazka bez zmian, tylko już nie pozycjonuje się sam). Na
`trail.php` (bez paska organizatora) niesie `<h1>` i RZĄD AKCJI
(**`.hero-acts`**, `align-self:flex-end` + `flex-wrap`). Do 2026-09-11 był to
sam przycisk GPX (`.hero-gpx`); od tej daty stoi tam cała trójka z
`partials/trail-actions.php` — user zgłosił, że „Nawiguj" i „Wyjazdy w tych
stronach" zostały nad zdjęciem, mimo że to jedna grupa akcji. Gdy trasa nie ma
zdjęcia, `.op-cover` w ogóle się nie renderuje — `<h1>` i cała grupa WRACAJĄ do
`.op-head__c`/`.op-head__act` (dwa miejsca renderowania tego samego elementu,
rozłączne warunkiem `cover_photo_url`, nie duplikat). Odznaka „Znana trasa" dostała przy okazji kolor `--blaze-dark`
przez `.tag`+`.blaze`+`--bz` — TEN SAM mechanizm, którym `event-page.php`
koloruje swój pierwszoplanowy tag formatu.

**Pułapka specyficzności `.hero-ttl`** (złapana żywym testem mobile tego
samego dnia): samo `.hero-ttl{font-size:...}` (0,1,0) PRZEGRYWAŁO z
`.head h1{font-size:clamp(30px,4.4vw,54px);max-width:20ch}` (0,1,1) — h1 na
wydarzeniu jest dalej potomkiem `.head` (przeniosło się tylko wewnątrz
`.cover`, `.cover` samo zostało w `.head`), więc na wąskim ekranie renderował
się WIĘKSZY font niż zaprojektowany i tytuł wychodził poza `.cover` u góry
(`overflow:hidden` ucinał pierwszą linię). Selektor musi być
`.cover .hero-ttl, .op-cover .hero-ttl` (0,2,0), nie sama klasa — przy
dokładaniu kolejnego modyfikatora do elementu, który zostaje potomkiem
swojego starego, bardziej specyficznego selektora, sprawdź to na mobile,
nie tylko na desktopie (na desktopie różnica była niewidoczna gołym okiem).

`.head__sub`/`.op-head__sub` (data/zbiórka na wydarzeniu, dystans/
przewyższenie/pola na trasie) dołącza pod `.hero-ttl`, w tym samym
`.hero-bottom` — najpierw na `event-page.php`, GODZINĘ później user dopytał
„czemu nie w znanej trasie, ta sama robota" i doszło `trail.php` (ten sam
dzień, dwa osobne zgłoszenia — nie zakładaj z góry, że modyfikator zrobiony
dla jednej z bliźniaczych stron ma zostać tylko tam, dopóki user wyraźnie
nie powie, że to specyficzne dla jednej). Modyfikator **`.hero-sub`** zmienia
tylko kolor (biały zamiast `ink-soft`), reszta reguły macierzystej zostaje.
Najważniejsza liczba w `<b>` (data na evencie, dystans na trasie) dostaje
znacznik **`--blaze`** za tekstem — TEN SAM mechanizm co wyróżnione słowo w
`<em>` na hero strony głównej (`.hero h1 em::after`), nie nowy wzór.
Selektory **kwalifikowane** `.hero-bottom .hero-sub`(` b`(`::after`)) (0,2,0),
NIE same klasy — ta sama pułapka co przy `.hero-ttl` wyżej: zarówno
`.head__sub` jak i `.op-head__sub` (0,1,0) są w pliku DALEJ niż była goła
`.hero-sub`, więc przy remisie specyficzności wygrywałyby kolejnością źródła.

Dwie linie tekstu (tytuł + metryczka) w `.hero-bottom` potrzebują więcej
miejsca niż krótszy, jednoliniowy przypadek, pod który liczone było
`min-height:220px`/`180px` — stąd
`@media(max-width:560px){.cover.cover{min-height:280px}.op-cover.op-cover{min-height:220px}}`
tuż obok reguł `.hero-*`. **Znowu PODWÓJNA klasa, nie pojedyncza**: `.cover`
ma swoją bazową regułę WCZEŚNIEJ w pliku niż ten blok (więc akurat by
zadziałało), ale `.op-cover` ma swoją bazową regułę SETKI LINII DALEJ
(sekcja profilu organizatora) — przy remisie specyficzności ten media query
przegrywałby kolejnością źródła i NIC by nie zmieniał (złapane żywym testem:
różnica zapasu była tylko 5 px, nie realne ucięcie, ale przy dłuższej nazwie
trasy by ucinało). **Reguła robocza na przyszłość**: dodając modyfikator do
`.cover`/`.op-cover`/`.head h1`/`.op-head h1`/`.head__sub`/`.op-head__sub` —
te pary NIE SIEDZĄ obok siebie w pliku (`.op-*` żyje w sekcji profilu
organizatora, setki linii dalej) — zawsze kwalifikuj selektor (podwójna
klasa albo klasa rodzica), nigdy nie licz na kolejność źródła.

**Pułapka PHP, nie CSS** (złapana tym samym żywym testem): dosłowny znacznik
zamykający PHP (`?`+`>`) WEWNĄTRZ komentarza `//` przedwcześnie kończy blok
PHP — parser nie rozumie „jesteśmy w komentarzu", więc reszta linii i
wszystko do najbliższego `<?php` leci na wyjście jako gołe HTML (tu:
fragmenty treści komentarza jako widoczny tekst na stronie, plus
`$metaLine = ob_get_clean();` nigdy się nie wykonało → `Undefined variable`
niżej). `php -l` TEGO NIE ŁAPIE (wynik jest składniowo poprawnym PHP, zmienia
się tylko co się wykonuje) — więc opisuj taki znacznik słownie w
komentarzach, nigdy nie wklejaj go dosłownie, nawet w cudzysłowie/backtickach.

**`stat-tiles.php` — `renderStatTiles($tiles, $opts)`** (2026-08-20) — KAFLE STATYSTYK,
jeden komponent liczb dla całego serwisu. Powstał na zgłoszenie usera („na każdej stronie
wygląda inaczej, a można z tego zrobić jeden partial"): te same trzy rzeczy — podpis,
liczba, dopisek — miały wcześniej trzy postacie (`.disc-stats` na `/odkrycia` i stronie
trasy, `.rp-stats` na profilu rowerzysty, ręcznie składane wiersze w kronice), a kopie
zdążyły się rozjechać nie tylko wyglądem: część miała podpisy pod liczbą, część nie,
kronika nie miała ich wcale. **Referencją wyglądu jest profil rowerzysty** (wskazany
przez usera).
`$tiles` to lista `['lbl','val','small'?,'sub'?,'hex'?,'subHex'?,'done'?,'lead'?]`;
**pozycje `null` są pomijane**, więc warunek „pokaż ten kafel, gdy jest o czym mówić"
pisze się w miejscu, w którym stoją dane. `lead` (2026-08-22) robi z kafla WIODĄCY —
pełna szerokość rzędu, większa liczba, tło `--accent-soft`; do jednej liczby na pasek,
tej, po którą się na ten ekran przyszło (drugi `lead` znosi sens pierwszego). `$opts`: `cols` (domyślnie tyle, ile kafli), `compact`
(wariant do kolumny bocznej — mniejsza liczba, ciaśniej), `class`.
**Liczba kolumn idzie stylem inline `--st-cols`**, a nie kolejnym modyfikatorem klasy
(były już `--4` i `--6`, przy czym `--6` znaczyło 3) — dlatego zapytania medialne
nadpisują `grid-template-columns`, a NIE tę zmienną: inline wygrywa z arkuszem.
Używają go: `discovery.php` (obie zakładki), `rider-profile.php` (compact, w kolumnie
z mapą, z `lead` na „Odkryte pola"), `trail.php` (5 kafli nad mapą), `chronicle.php`
(compact, „Co to dało w Discovery"). Testy: `php tests/run.php widoki`.

**Klucz `graphic` + `threshold-badge.php` — `renderThresholdBadge()`**
(2026-09-11). Kafel może dostać własny znacznik (surowe SVG) OBOK liczby —
komponent daje mu tylko `.stat-tile__vrow` (wiersz liczba + odznaka)
i `.stat-tile__badge`; treść buduje strona. Dziś jeden konsument: kafel
„Punkty" na `trail.php`, gdzie odznaka zastąpiła napis „zdobyte za progi
20/40/60/80/100%" (user: „niezrozumiałe", przysłał render — pięciokąt, każde
ramię to jeden próg). `renderThresholdBadge($thresholds, $pct)` zwraca
`['svg','done','steps']`: N-kąt złożony z klinów, tyle klinów, ile progów
(**licznik progów to ustawienie globalne w /admin/punkty**, `threshold_count`
— dziś 5, wcześniej 4, więc piątka NIE jest wpisana na sztywno), pusty
środek, białe przerwy, obwódka rysowana na końcu (czyli na wierzchu przerw),
gradient `--blaze`→`--blaze-dark` na zdobytych, a próg „w drodze" rośnie od
środka proporcjonalnie do postępu między poprzednim a tym progiem. Liczby
(ile procent, ile punktów) siedzą w `<title>` każdego klina — dymek zamiast
zaśmiecania podpisu. Podpis kafla mówi teraz „2 z 5 progów", nie listę
procentów. **Pierwsza iteracja została odrzucona** (40 px, kliny w `--tint`
na białym kaflu, bez pustego środka — „zobacz co wygenerowałeś, a jaka była
moja propozycja"): jeśli odznaka ma coś znaczyć, musi mieć rozmiar (52 px
w kaflu) i kontrast (`--hair`, nie `--tint`).

**Wariant „grywalizacja" (2026-09-05) — `stat-tiles-hex.php` /
`renderHexStatTiles()`.** Zbudowany jako podgląd obok produkcji, obejrzany
i zatwierdzony przez usera, wdrożony na `discovery.php`. CELOWO osobny plik od
`stat-tiles.php`, nie jego zamiennik: `renderStatTiles()`/`.stat-tiles`
zostają nietknięte i dalej obsługują `rider-profile.php`, `trail.php`
i `chronicle.php`, bo tego wyglądu nikt tam jeszcze nie widział ani nie
zatwierdził — zmiana obejmuje WYŁĄCZNIE /odkrycia. Stylistyka: hex jako
element przewodni kafla + osobna ikona per kategoria w heksagonalnej
plakietce (inspiracja: render usera). Kolory plakietek to ISTNIEJĄCE tokeny
(`--accent`, `--s-green/--s-purple/--s-blue`, `--blaze-dark`) przez
`data-tone` — zero nowych barw. Poziomy/XP/odznaki z rendera świadomie
pominięte (user: „tego jeszcze nie ma").

**„Twoje regiony" — emblematy grupowane po kraju (2026-09-05).** Kafel
„Regiony" na zakładce osobistej dostaje `href="#regiony"` (tylko gdy jest co
najmniej jeden rozpoczęty region — patrz `stat-tiles-hex.php`, klucz `href`,
kafel renderuje się wtedy jako `<a>`) i prowadzi do sekcji niżej na
stronie: po jednym „emblemacie" na każdy region, w którym user ma ≥1 odkryty
heks (`Discovery::regionsForUser` — ta sama definicja co licznik w kaflu, żeby
się nie rozjechały). Emblemat to hex wypełniany OD DOŁU procentem `pctMine` z
`Discovery::regionProgress($viewerId)` — CSS custom property `--pct` na
`.region-emblem__hex`, dziecko `.region-emblem__fill` wycięte tym samym
`clip-path` co rodzic. Region przy 100% dostaje pełne wypełnienie i ikonę
`check` zamiast liczby (ta sama logika co `.is-done` gdzie indziej). Inspiracja
to plakietki „odznak" z rendera usera, ale bez wymyślania systemu poziomów —
liczba w środku to prawdziwy procent pokrycia regionu, nie punkt XP.

**GRUPOWANIE PO KRAJU** (user testował na żywo: „stwórz region poza Polską,
zobacz czy pokaże się w statystykach" — pokazywał się, ale WMIESZANY
w województwa; naprawa w `Discovery::regionProgress()`, patrz `models.md`).
`$startedByCountry` (discovery.php) grupuje `$regionsWorld['regions']` po
`countryCode`, w kolejności `$regionsWorld['countries']` (kraj domowy zawsze
pierwszy). Kraj z REALNĄ podgrupą (Polska: 16 województw) dostaje nagłówek
nad swoją siatką emblematów — reużyte `.disc-world__box`/`__lbl`/`__num`/
`__sub`, ten sam kształt informacji co pasek „Razem odkrywamy Polskę" niżej,
zero nowych klas na sam nagłówek. Kraj PŁASKI (dziś: kraj bez rodzica i bez
dzieci, sam sobie regionem — Czechy, Słowacja) dostaje TYLKO swój pojedynczy
emblemat, BEZ osobnego nagłówka — inaczej ta sama nazwa i procent stałyby na
ekranie dwa razy (`showHeader` w PHP: `false`, gdy grupa ma dokładnie jeden
region i jego `id === countryId`).

`Discovery::regionProgress()` woła się TERAZ z id widza (było `null`) — dodaje
to pola `mine`/`pctMine`/`countryId`/`countryCode`/`countryName` per region
oraz klucz `countries`. `grand` („Polska odkryta %") jest SCOPED do Polski
(zero różnicy, dopóki w bazie jest tylko ona — różnica ujawnia się dopiero,
gdy ktoś naprawdę doda drugi kraj w `/admin/regiony-mapa`). Trwały test
regresji: `tests/regiony_test.php`.

**Zdjęcie celu w „mgle" (2026-09-05, dwie iteracje tego samego dnia).** Karta
„Następny cel" (`.disc-goal`, dziedziczy po `.disc-social`) dzieli się na
treść (`.disc-goal__body`) i miniaturę (`.disc-goal__photo`) ZAWSZE,
niezależnie od tego, czy `$goal` ma pole `photo` (trasa/„zacznij trasę":
`cover_photo_url`; skarb: `photo_url` z `Treasure::waitingList()` — zdjęcie
nie zdradza pozycji jak lat/lon, więc idzie bez wyjątku od bramki ujawnienia).

*Iteracja 1* zrobiła plakietkę 20% szerokości karty — user: „w tak małym hex
chcesz umieścić zdjęcie?! porozmawiaj z designerem" (odpowiedź: przeszukane
`anthropic-skills:ui-ux-pro-max` pod kątem trendów image-reveal; finalny
kierunek czerpie z WŁASNEGO motywu serwisu — fog of war Discovery Grid —
bardziej niż z ogólnych trendów).

*Iteracja 2, finalna*: miniatura rośnie do ~40% szerokości i PRZELEWA SIĘ za
górną/prawą/dolną krawędź karty (`margin` ujemny = `padding` `.disc-social`,
przycięty jej `overflow:hidden`) zamiast stać na środku z marginesem —
zweryfikowane, że `photoRect.right === cardRect.right` i `photoRect.height
=== cardRect.height`. Kształt to NIEREGULARNY siedmiokąt (`clip-path`,
7 ręcznie dobranych wierzchołków — user: „może być hex nieregularny"),
celowo różny od porządnego sześciokąta `.hexn`/emblematów regionów: ten jeden
akcent ma wyglądać jak fragment wyrwany z mapy. `::after` kładzie na nim
radialny gradient (`circle at 65% 38%`, przesunięty w stronę zdjęcia, nie na
środek) od przezroczystego do koloru karty — dosłowna „mgła" tej samej
metafory co Discovery Grid: odkryty fragment WYŁANIA SIĘ z szarości, a przy
okazji rozmywa szew ze zdjęciem i chroni tekst przed sąsiadowaniem z ostrą
krawędzią fotografii. **BEZ zdjęcia dostaje `.disc-goal__photo--empty`** — ta
sama mgła plus wyblakły `Icon::render('hex')` na środku (ten sam znak, którym
serwis wszędzie oznacza „pole") — region i „dołącz do wyjazdu" (bez
naturalnego zdjęcia) kończą właśnie tak, czytelnie jako „jeszcze nic tu nie
odkryto", nie jak zepsuty obrazek.

`.disc-goal__body` ma WŁASNY `display:flex;flex-direction:column`: odkąd
podpis/tytuł/pasek/przycisk przestały być bezpośrednimi dziećmi `.disc-social`
(flex-column), przestały dostawać pionowe ułożenie za darmo — iteracja 1 to
pominęła (regresja zgłoszona przez usera osobno: podpis i tytuł wylądowały
w jednej linii, bo `<span>`/`<strong>` wróciły do domyślnego `inline`).
`margin-top:auto` na przycisku działa mimo zagnieżdżenia — `.disc-social .btn`
to selektor potomka.

**Ten sam wygląd na stronie głównej — `.disc-social--photo`** (2026-09-05,
user: „na stronie głównej mamy podobną kontrolkę »Razem odkryliśmy«, zastosuj
takie samo tło jak w Następny cel"). `home.php`'s „Razem odkryliśmy" (osobna
karta od tej na `/odkrycia` — ta sama liczba, ten sam komponent `.disc-social`,
nie druga jego wersja, patrz nota wyżej w pliku) dostała TĘ SAMĄ miniaturę
w mgle. Selektor CSS jest wspólny: `.disc-goal, .disc-social--photo{...}` —
`__body`/`__photo` zostają w przestrzeni nazw `disc-goal` mimo że karta nie
jest celem (jeden układ wizualny na każdą ciemną kartę `.disc-social`, która
chce miniaturę, nie osobny komponent per strona), ale `.disc-goal
.disc-social__num{font-size:30px}` NIE dotyczy `.disc-social--photo` — ta
karta zostaje przy większej liczbie (38px), bo to nie kafel „celu". Brak
jednego konkretnego miejsca do pokazania (suma społeczności, nie czyjś cel),
więc miniatura zawsze w wariancie `--empty` — sama mgła + wyblakły hex, bez
wymyślania zdjęcia, które niczego konkretnego by nie przedstawiało.
`/odkrycia`'s WŁASNA karta „Razem odkryliśmy" (z awatarami `.disc-social__faces`
w rogu) NIE dostała tej zmiany — user wskazał konkretnie stronę główną, a ta
karta ma już swój akcent (awatary), który konkurowałby z miniaturą.

**Dymek skarbu — liczby jako ikony** (2026-08-22, zgłoszenie usera: „+50 pkt · nikt
jeszcze go nie znalazł · zalicza się w promieniu 150 m — nie lepiej to ikonkami zrobić?
Za dużo treści a nic nie wnoszą. Jak już to jakiś tooltip do ikonek"). `.tre-pop__facts`
(jedno zdanie, trzy liczby rozpisane na dziewięć słów, w dymku szerokim na 240 px)
zastąpione paskiem `.tre-pop__stats` z chipami `.tre-pop__stat`: ikona + wartość.
**Słowa nie znikły — zeszły do tooltipa.** Chip jest `<button>`, nie `<span>`, bo na
telefonie `title` nie istnieje, a to jest ekran przede wszystkim terenowy: hover daje
podpowiedź przy biurku, kliknięcie wypisuje pełne zdanie w linijce `.tre-pop__msg` pod
spodem (drugie kliknięcie chowa). Ta sama linijka przyjmuje komunikaty z zaliczania —
wcześniej nadpisywały one pasek liczb i te już nie wracały.
Ikony dochodzą do istniejącego zestawu `ZNAKI` w `discovery-map.js` (Lucide, ISC),
nie zakładają drugiego zbioru. Jedyny kolorowy chip to `is-first` — skarb, którego nikt
jeszcze nie znalazł; „nikt tam nie był" jest w tym module najmocniejszym zaproszeniem.

**`photo-lightbox.php`** (2026-08-22) — PODGLĄD ZDJĘCIA, jeden dla całego serwisu.
Powstał na zgłoszenie usera („galeria zdjęć otwiera z osobna zdjęcie i to małe,
a powinno zadziałać lightbox"). Serwis miał dwa zachowania kliknięcia w zdjęcie:
kronika otwierała modal `<dialog>` z oryginałem, a strona wyjazdu (3 galerie) i profil
organizatora robiły `<a target="_blank">` **do wariantu `thumb`** — czyli nowa karta
z obrazkiem 320 px. Oba objawy z jednego błędu: `href` prowadził do miniatury.
Kod jest **przeniesiony** z `chronicle.php`, nie napisany od nowa. Dołącz raz na stronę
(`require` jest zabezpieczony stałą przed drugim `<dialog>` o tym samym id) i oznacz
kafelki jednym z dwóch sposobów:
`<button class="ph-thumb" data-full="…">` (siatka kronikowa, tło CSS) albo
`<a class="ph-link" href="PEŁNY">` (galerie wyjazdu i organizatora — prawdziwy link,
więc bez JS otwiera oryginał w nowej karcie zamiast nie robić nic). **Nowe galerie pisz
jako `.ph-link`.** `<dialog>` daje za darmo Escape, blokadę tła i pułapkę na fokus;
Ctrl/Cmd/środkowy przycisk zostają przeglądarce. Testy: `php tests/run.php widoki`
(m.in. skan szablonów pilnujący, że żaden `href` galerii nie wróci do `thumb`).
**Podpis (2026-09-16):** opcjonalny `data-caption` na wyzwalaczu pokazuje się w
podglądzie jako nakładka na dole zdjęcia (`.ph-modal__cap`) i staje się `alt`;
bez atrybutu nic się nie zmienia. Używa go galeria „Zdjęcia z regionu”
(`region.php`, komponent `.rph-grid`/`.rph` — kafel 4:3 z podpisem źródła,
przedmiotu i autora POD zdjęciem).

**`known-route-form.php`** (2026-08-19) — formularz znanej trasy, JEDEN dla dodawania
i dla edycji. Zwykły `require`; oczekuje `$krRoute` (wiersz `known_routes` albo `null`
= dodawanie), `$regions`, `$krWroc` (stan listy → pola ukryte `wroc_*`) i `$krStare`
(pola z odrzuconego POST-a). Sam składa `action`, `enctype`, CSRF i etykiety. Bonusy tylko
w edycji (powód w [`features.md`](features.md)).
**Wartość pola czyta domknięcie `$krVal(klucz)`: najpierw `$krStare`, dopiero potem
wiersz z bazy** (2026-08-23, zgłoszenie usera). Klucze `$krStare` są celowo takie same
jak kolumny `known_routes`, więc jest tu JEDNA ścieżka odczytu, a nie osobna dla
dodawania i osobna dla edycji. Plik GPX nie wraca inputem plikowym (przeglądarka go nie
wypełni) tylko **ukrytym `gpx_token`** wskazującym plik czekający w `gpx/tmp`; przy
trzymanym pliku z inputu schodzi `required`, inaczej przeglądarka blokowałaby wysyłkę
poprawnego już formularza. Testy: `widoki_test.php` („formularz trasy: …").
Renderowany przez `known-route-edit.php` — **własną podstronę**, nie rozwijany blok przy
wierszu listy: pierwsza wersja miała po formularzu na trasę w jednym dokumencie, co przy
dwustu trasach jest ekranem nie do przejścia. `known-routes-admin.php` jest od tej zmiany
czystą LISTĄ (`.trust-bar` + `.disc-tabs` + wyszukiwarka z sortowaniem + `.adm-table` +
prev/next — komponenty z `users-admin.php`).

**`rider-avatar.php` — `renderRiderAvatar()` / `renderRiderName()`** (2026-08-12).
JEDNO miejsce decydujące o tym, czy człowiek na ekranie jest klikalny. Powstało po
zgłoszeniu usera, że awatary i imiona nigdzie nie prowadziły do profilu, choć profil
istnieje. **Zasada: każde wystąpienie człowieka prowadzi do `/rowerzysta/{slug}`.**
Bez sluga (konto sprzed migr. 038, osoba ukryta) render daje zwykły `<span>`/tekst —
lepsze niż link do 404. Podpięte w: skład i „Rozważają udział" oraz peleton na
`event-page.php`, opinie/relacje/komentarze (przez `activity-card.php`), skład
i dziennik w `chronicle.php`, peleton na `rider-profile.php`, „Ludzie z Twojego
peletonu jadą" w `pulse.php`.
**Awatar = ZDJĘCIE, inicjały to zapas** (2026-08-22, zgłoszenie usera: „jeśli user ma
ikonkę wgraną jako avatar, to i tak w aplikacji ładuje skrót"). `renderRiderAvatar()`
przyjmuje czwarty parametr `$avatarUrl` (dopisany na końcu — wywołania bez niego działają
jak dotąd), `renderActivityCard()` ósmy `$authorAvatarUrl`. **Od 2026-09-03 komponent pyta `Image::exists()`, a nie tylko „czy adres jest w bazie"** (zgłoszenie usera: „wskazuje to, że nie ma poprawnego dostępu (…) w całej aplikacji jest podobnie" — w bazie dev skasowane były WSZYSTKIE 28 plików uploadów). Bez tego obietnica z noty niżej nie działała dla skasowanego pliku: `alt` nie zastępuje obrazka, tylko towarzyszy ikonie błędu. Ta sama poprawka w `event-card.php` i `home-event-card.php` (grafika zastępcza zamiast pustego prostokąta).
**Inicjały zostają w `alt`**,
więc wyłączone obrazki albo padnięty CDN dają dwie litery, a nie ikonę zepsutego
obrazka. Adres przechodzi przez `Image::src($url, 'av')`, który sam przepuszcza adresy
zewnętrzne (awatar z Google) i sam składa wariant dla własnych uploadów.
Wymagało to dołożenia `u.avatar_url` do ośmiu zapytań — pełna lista i uzasadnienie
w [`features.md`](features.md#awatary--jedna-zasada-w-całym-serwisie-2026-08-22).
**Awatar w FAQ jest NULL-owany razem z nazwiskiem** (`EventComment::forEvent`): twarz
zdradza organizatora szybciej niż podpis.
**Dwa układy pod jedną klasą `.roster`, nazwane wprost** (2026-08-22, pytanie usera
„czy zawsze awatar po lewej i obok imię?"): domyślny `.roster` to **pasek twarzy +
zdanie o grupie** (skład wyjazdu, kronika, trasa, Puls) — kilkanaście nachodzących
awatarów i jedno zdanie, więc `flex-wrap:wrap` jest tam poprawny. `.roster--person` to
**lista osób**, jeden wiersz = jeden człowiek (dziś tylko peleton na profilu rowerzysty) —
tam zawijanie jest błędem i jest wyłączone (`nowrap` + `min-width:0` na tekście, bez
którego element flex nie kurczy się poniżej swojej treści).
CSS: `.avs span,.avs a` (ten sam wygląd dla klikalnego i nie), `a.r-avatar`,
`.review-name a,.roster__names a` (kolor dziedziczony — lista nie ma być morzem linków),
`.avs img`/`.r-avatar img`/`.inbox-avatar--photo img` (kółko ma już rozmiar i obwódkę —
obrazek je tylko wypełnia: `100%` + `object-fit:cover` + `display:block`; to ostatnie
usuwa szparę pod linią bazową, przez którą awatar ze zdjęciem stałby niżej niż awatar
z inicjałami w tym samym rzędzie). Tło kółka gasimy pod nieprzezroczystym zdjęciem —
przez `:has(img)` tam, gdzie elementów jest kilka, i przez modyfikator klasy w skrzynce,
gdzie wiersz renderuje się setki razy.
`renderActivityCard()` przyjmuje `$authorSlug`, a od 2026-08-13 także `$actionsHtml`
(akcje przy KONKRETNYM wpisie, dosunięte do prawej krawędzi nagłówka karty — dziś
ołówek „edytuj" na własnym wpisie w kronice). Oba dopisywane **na końcu** listy
parametrów, więc stare wywołania działają bez zmian.

`header.php` (nawigacja + dropdown konta + ikona Wiadomości z czerwoną kropką).
**Odchudzone 2026-08-12** pod nowy moduł: „Jak to działa" usunięte z nagłówka
(zostaje w stopce, sekcja Serwis — treść czytana raz, a zajmowała stałe miejsce
na każdej stronie), „Zaloguj się" + „Załóż konto" scalone w JEDNO wejście
(rejestracja jest pierwszym zdaniem pod formularzem logowania, `login.php`).
Zwolnione ~200 px; na 1280 px zostaje **507 px wolnego dla gościa / 350 px dla
zalogowanego** między logo a nawigacją.
`footer.php`, `breadcrumbs.php`, `form-error.php`, `event-card.php`, `home-event-card.php`,
`event-results.php` (siatka wyników), `activity-card.php`, `match-suggestions.php` (widget
dopasowań, Alpine), `interest-toggle.php`, `social-login.php` (przyciski Google/Strava pod
formularzem auth), `cancel-participation-form.php` (gałęzie CTA płatne — internal i external),
`organizer-profile-form.php`, `organizer-billing-form.php`.

**CTA "Anuluj udział" dla darmowych wydarzeń wewnętrznych (dodane 2026-08-09)** — gałąź
`else` na końcu bloku CTA w `event-page.php` (Alpine `eventPage()`, przyciski
"Dołącz do wyjazdu"/"Zapisano") do tej pory (realny bug) nie miała ŻADNEGO sposobu na
rezygnację po dołączeniu — jedyna gałąź bez tego, w odróżnieniu od płatnych gałęzi,
które `require cancel-participation-form.php`. Naprawione: przycisk **"Rozmyśliłem
się"** (`.btn--claim`, pomarańczowy — patrz CSS niżej) w `<div x-show="joined">` obok
"Zapisano", POSTujący do tego samego `/wydarzenia/{slug}/anuluj-udzial` co partial.
Celowo NIE reużywa `cancel-participation-form.php` wprost (inne brzmienie tekstu/
potwierdzenia, inny kolor) — osobny inline `<form>` w tym samym miejscu. Dotyczy
darmowych `ustawka`/`wycieczka_wielodniowa` ORAZ `pokrec_z_kims` (ta sama gałąź).

### Kreator dodawania — `views/web/partials/wizard/*.php`
`event-form-wizard.php` (strona) to cienki orkiestrator: rama (jeden `x-data="eventWizard()"`,
pasek postępu, `<form>` + ukryte pola, nawigacja) + `require` kolejnych partiali. **Jeden
komponent Alpine** — partiale to `require` w tym samym scope (widzą `$dictOptions` itd.) i tej
samej instancji Alpine (granice `require` są dla Alpine niewidoczne). Pliki: `type-picker.php`
(KROK 0), `step-{kogo,kiedy,gdzie,trasa,plan,oczym,dlakogo,pieniadze,podsumowanie}.php` (9 kroków),
`gpx-surface.php` (WSPÓLNY blok „nawierzchnia" dla `trasa` i `plan` — parametry `$sRef`/`$sIdx`/
`$sLegend` ustawiane przed `require`), `variants.php` (warianty trasy jednodniowej,
`Models\EventRouteVariant`), `nav.php`, `map-modal.php`, `script.php` (komponent Alpine
+ 2 IIFE; ma interpolacje PHP, więc jest partialem PHP, nie plikiem `.js`). Kroki widoczne per typ
liczy `visibleSteps()` w `script.php`; konfiguracja typów (`TYPE_META`/`STEP_MIN`) też tam + picker
w `type-picker.php`. **Edycja (`event-form.php`) NIE korzysta z tych partiali** — osobny,
jednostronicowy formularz (świadomie, patrz [`../md/README.md`] duplikacja create/edit).

`notifications-admin.php` (2026-09-11) — panel powiadomień. **Rodzaj i jego
treść stoją w jednym wierszu** (`.ntf-item`): przełącznik, opis, przycisk
„Treść" rozwijający edytor z polami i podglądem. Pierwsza wersja miała
wyłączniki na górze, a wszystkie treści w osobnej sekcji na dole — user odrzucił
to jako niezarządzalne, bo trzeba było skakać po stronie, żeby zobaczyć, czego
dotyczy edytowany tekst. Podgląd renderuje serwer (ta sama metoda co wysyłka),
a JS odświeża go przy pisaniu; znaczniki i bloki wstawia się klikiem w miejsce
kursora. Poza tym nie ma w nim ANI JEDNEGO własnego komponentu: pola liczbowe to `.rate`/`.rate__in`/`.rate__hint`
z `points-admin` (razem z `.is-set`, czyli znacznikiem „zmienione"), tabele to
`.dash-table`, a przełączniki 0/1 to `.acc-notif__row` z ekranu konta. To ten
sam problem co przy stawkach punktowych — liczba z jednostką, podpowiedzią
i wartością domyślną, do której wraca się wyczyszczeniem pola — więc drugi
komponent byłby kopią. Widok nie zna nazw parametrów ani domyślnych: wszystko
przychodzi z `NotificationSettings::PARAMETRY` przez kontroler.

## Maile — `views/emails/`

`layout.php` + `pages/*.php` (~24 szablony). Renderowane przez `Utils\MailTemplate::render`,
wysyłane `Core\Mailer::sendTemplate('nazwa-pliku', to, subject, data)`. Przykłady:
`activation`, `password-reset`, `new-signup`, `payment-reminder/confirmed`, `refund-*`,
`event-cancelled`, `review-invite`, `match-suggestion`, `new-message`, `discussion-*`,
`custom` (2026-09-11 — **uniwersalny szablon dla wszystkich maili z bramki**:
przyjmuje gotowy `$bodyHtml` złożony przez `NotificationTexts` i nie ma własnej
treści ani logiki; zastąpił `new-message`, `match-suggestion` i `treasure-nearby`
w roli nadawcy),
`treasure-nearby` (Etap 1c — mailowa połowa zachęty „nowy skarb w okolicy";
**szanuje poziom ujawnienia skarbu tak samo jak push i mapa**, bo mail zostaje
w skrzynce i da się go przeszukać). Od migr. 084 ten szablon dostaje GOTOWE
pierwsze zdanie (`$lead` z `NotificationTexts`) i **nie wie o poziomie
ujawnienia** — wariant wybiera kod, który zna skarb. Gdyby warunek stał
w widoku, ta sama reguła istniałaby w dwóch miejscach, a wystarczy, że
rozjadą się raz.

`layout.php` dokłada **stopkę z wypisem**, gdy w danych jest `$unsubscribeUrl` —
podaje go wyłącznie `Models\Notifier`, więc maile transakcyjne jej nie mają
(pod potwierdzeniem wpłaty nie ma z czego się wypisać). Strona docelowa tego
linku to `views/web/pages/unsubscribe.php`: trzy stany na jednym ekranie (zły
podpis / pytanie / potwierdzenie), bo to adres, który człowiek otwiera raz.

## CSS — [`assets/css/style.css`](../assets/css/style.css) (~2620 linii, jeden plik)

**Design tokeny** w `:root` (paleta „Szlak", 2026-07-31). Nazwy zmiennych ZOSTAJĄ mimo
zmian kolorów — przefarbowanie = zmiana wartości, nie reguł:
- Neutralne: `--bg #F4F5F1`, `--paper`, `--ink`, `--ink-soft/mute`, `--hair`/`--hair-strong`.
- Akcent (zieleń): `--accent #2C6B4F`, `--accent-dark #1E4B38`, `--accent-soft`, `--accent-text`.
- Sygnał: `--blaze #FFC93F`/`--blaze-dark`, `--star`, `--tint`.
- Formaty (kolory typów eventu): `--s-red/--s-blue/--s-green`.
- Nawierzchnie: `--surface-asphalt/gravel/trail`.
- Layout/typografia: `--radius 10px`, `--max 1360px`, `--section-gap`, fonty `--f-d/--f-b/--f-m`.
- `[x-cloak]{display:none}` (dla Alpine), `.icon` bazowa.

**WARSTWY (`z-index`) — jedna skala, na górze arkusza** (2026-08-20, zgłoszenie usera:
„mapa zawsze na wierzchu, dropdown menu chowa się pod `.anchors`"). Obie usterki miały
to samo źródło: liczby nadawane lokalnie, każda pod inny problem.

| poziom | co |
|---|---|
| `0` | **mapy** — `.leaflet-container` i całe jego wnętrze |
| `1–3` | nakładki wewnątrz mapy (`.map-layers`, `.disc-panel`, `.hex-legend--onmap`, `.search-area-btn`) — `.disc-panel` poniżej 680 px jest **szufladą od dołu**, patrz niżej |
| `5` | `.quick-nav` — pasek przyklejony wewnątrz treści |
| `20–40` | rozwijane w treści (`.organizer-search-results`, `.tr-found`, `.multisel__p`, `.rowmenu__p`) |
| `50` | paski przyklejone POD nagłówkiem (`.anchors`, `.k-bar`) |
| `60` | nagłówek wraz z menu konta (panel ma `10` już WEWNĄTRZ kontekstu nagłówka) |
| `70–90` | dolne paski mobilne (`.k-nav` 70, `.app-nav` 80, `.mbar` 90) |
| `100+` | modale i to, co w nich |

**`.leaflet-container{isolation:isolate;z-index:0}` jest tu najważniejszą linijką.**
leaflet.css nadaje swoim panelom 200–700, a kontrolkom 800–1000 — liczby większe niż
cokolwiek w tym serwisie. Dopóki kontener nie tworzył WŁASNEGO kontekstu układania, te
panele konkurowały z nagłówkiem na poziomie dokumentu i wygrywały (zmierzone na
`/odkrycia`: pinezka skarbu rysowała się nad przyklejonym nagłówkiem).
`isolation`, a nie `position:relative` — `.disc-map__canvas` jest absolutna i nie wolno
jej tego zabrać. Dzięki izolacji nakładki na mapie mają dziś `3` zamiast `500–1200`:
wystarczy, że biją kontener, a nie cały serwis. **Nowa nakładka na mapie = poziom 1–3,
nigdy 500.**

**Mapa sekcji (numery linii przybliżone — zweryfikuj przed edycją).** Zakresy do
~1460 pochodzą z 2026-08-06 i są od tego czasu poprzesuwane; wszystko, co doszło
później, siedzi PONIŻEJ i ma własne wiersze w drugiej części tabeli. Najpewniejsza
metoda i tak pozostaje `grep` po nazwie klasy.

| ~Linia | Sekcja |
|---|---|
| 1–44 | `:root` tokeny + reset |
| 74–130 | Header/nav + logo, ikona Wiadomości, sygnatura marki |
| 172–207 | Modyfikatory przycisków; przejęcie profilu organizatora (`.op-head__act`), w tym `.btn--claim` (pomarańczowy, miękkie wypełnienie) — reużyty 2026-08-09 dla "Rozmyśliłem się" na stronie eventu |
| 208–333 | Zakładki sekcji stron ustawień (moje-konto/profil-organizatora) |
| 334–450 | Sekcje profilu organizatora; lista/wyszukiwarka organizatorów |
| 452–555 | Logowanie społecznościowe; plakietki widoczności; pigułki/kafle/przełączniki ustawień; miernik kompletności |
| 555–780 | Karta „Start" (slider mapa/tekst); przełącznik widoku; karta „Pokręcę z kimś"; podział nawierzchni; ekran potwierdzenia |
| 779–866 | Messenger: layout, panel „Nowa rozmowa", kanał grupowy, wątek grupowy |
| 867–920 | Widget dopasowań (Etap 2/3) |
| 921–1057 | Strona główna: rytm sekcji, karta „Najbliższe wyjazdy" |
| 997–1057 | Lista wyników: toolbar, tagi filtrów, separator miesiąca, SEO blok |
| 1058–1280 | Strona wydarzenia: rytm, jedna kolumna (event zakończony), link zewnętrzny, wejście do dyskusji grupy, kafelek „Zapisy" (link/tel/e-mail), alternatywne formy zapisu pod CTA |
| 1281–1460 | Kreator eventu: kroki, pasek postępu, kafelki wyboru, pola akcji (mapa/plik), dni/turnusy/cennik, modal mapy, nawigacja |
| ~1426–1460 | Warstwa odkryć: jedna skala od szarości po czerwień (`.leaflet-discoveryFog-pane`) |
| ~1454–1560 | Discovery `/odkrycia` wg projektu grafika (nagłówek i akcje w jednym rzędzie, `.disc-*`) |
| ~1565–1590 | **Faza C na `/odkrycia` (2026-08-25)** — hierarchia gracza: hero prawa kolumna = `.disc-social.disc-goal` (karta „Następny cel", drabinka trasa→skarb→region w widoku `discovery.php`; pasek `.disc-goal__bar` biały, bo `--tint`/`--accent` giną na ciemnym), kafle osobiste: lead `Punkty` z ROZBICIEM źródeł z rejestru (`PointLedger::label`) + 5 kafli w 5 kolumnach (pola, nowy teren, regiony heksowo, skarby, trasy — po uwadze usera „uciąłeś dużą część statystyki"; bez dziury w siatce), wspólne: lead „Polska odkryta x%" z PRAWDZIWEGO mianownika (`region_cell_counts`, migr. 071). Trasy (osobista): sortowanie „w toku → ukończone → nietknięte" + akcent „prawie" `.disc-trail__foot .near` („do progu 75% — brakuje 7"). Pasek `.disc-world*` przed CTA = obecność społeczności na osobistej zakładce bez drugiej mapy. Kotwice `#mapa`, `#znane-trasy`. Legenda mapy NIE uproszczona świadomie — jest własnością `discovery-map.js` i zmienia się z warstwami |
| **Spójność mapy osobistej z profilem (2026-08-25, uwaga usera)** | `/odkrycia` (zakładka „me") dostaje z profilu rowerzysty dwa elementy 1:1: **kafle własnych śladów** — od 2026-08-26 (Etap 1b) NIE osobną, zawsze zapaloną warstwą, tylko jako warstwa „Ślady", której źródło wynika z kontekstu (`sources.slady` = **zawsze `me`** na własnej mapie — naprawa błędu 2026-08-26, patrz `models.md`/`MapLayer::tileKeyFor` token `subject-private`, bo to jedyny klucz, na którym rysują się przejazdy solo — `u-{slug}` na profilu tej samej osoby oglądanym przez KOGOKOLWIEK, `all` na mapie społeczności); dzięki temu przełącznik „Ślady" gasi to, co człowiek widzi jako swoje ślady, a nie cudzą warstwę — i **podświetlanie z feedu** — `ridemoreRideFeed(map, root, {tracks})`, gdzie `tracks` to mapa `activity_id → adres GEOMETRII` składana w `Support::trackUrlsForFeed` (od 2026-09-02 endpoint, nie plik — patrz wiersz niżej) (edition ślady po `edition_id` + **solo przez `rider_activities.gpx_url`**; wiersze feedu niosą `data-ride`). **Plik solo wychodzi TYLKO właścicielowi** (§27, 2026-08-26): `trackUrlsForFeed($rides, ?int $viewerId)` porównuje `user_id` wiersza z widzem, bo surowy ślad solo zaczyna się pod czyimś domem (przycinamy pola przed zapisem, nie plik — patrz `RiderActivity::recordSolo`). Na mapie społeczności i na cudzym profilu wiersz solo zostaje klikalny, ale dociąga sam kadr z pól (`bounds`), bez pliku. Ślad wyjazdu tej bramki nie ma — zbiórka jest ogłoszona publicznie. Klik rysuje ślad wektorowo na niebiesko WYBORU (`#2B57C8`) przez wspólny helper `ridemoreFocusTrack`, kadr wyznacza prostokąt z odpowiedzi endpointu, drugi klik zdejmuje wybór. Head me-tab = `gpxMapHead()` (leaflet-gpx), społecznościowej bez zmian. `Discovery::recentActivity` dokłada `edition_id` i `gpx_url`, a parametr `?int $viewerId` maskuje skarby na cudzej osi czasu jak na mapie społeczności (nazwa/pozycja tylko dla jawnych albo właściciela) |
| **Profil rowerzysty: kafle na górę, feed z /odkrycia (2026-08-25, uwaga usera: „aktywności mają być te same… mniej kodu i kombinowania")** | `rider-profile.php`: stat-tiles (pełna szerokość, lead = pola, cols=5) przeniesione NAD sekcję mapy; mapa używa wspólnej struktury `.disc-map` + panel `ride-feed` (zawinięty w `.disc-panels`, `$feedShowRider=false`); WŁASNA lista `.rp-rides` i cały jej JS (paginacja krokowa, `przemaluj`/`dociagnijKadr`/`lazyUrls`) USUNIĘTE — rozwiązało to rozjazd, bo stara lista czytała tylko ślady wyjazdów i **wycinała przejazdy solo**. Wpisy „Aktywności" pod mapą (`rf-item--mapa`, `data-track` = edition_tracks.id) sterują mapą przez `ridemoreFocusTrack` + `trackUrlsById`; `data-treasure` bez zmian. `RiderController` ładuje discovery-map.js przy KAŻDEJ mapie (feed żyje w tym pliku), nie tylko przy odkrytych polach. CSS `.rp-mapwrap/.rp-map/.rp-side/.rp-rides*/.rp-map__box` usunięte |
| **Mapa ładuje się równolegle, nie po kolei (2026-09-02, zgłoszenie usera: "warstwy lecą po kolei, przy dwóch obszarach czekałem ponad 10 sekund")** | Trzy zmiany, wszystkie zmierzone monitorem: (1) `scheduleRefresh(true)` na `moveend`/`zoomend` pyta o pola i skarby **NATYCHMIAST**, a 250 ms odstępu obowiązuje tylko przy serii ruchów i przy ponowieniach z bramki zerowego kadru — dotąd każdy gest dawał kaflom ćwierć sekundy fory i stąd brało się "najpierw ślady, potem heksy"; (2) `updateWhenIdle: true` w `ridemoreAddTileLayer` — kafel powstaje w PHP na żądanie, więc nie zamawiamy tych, które porzucimy w połowie przeciągnięcia (zmierzone: 30 zamówionych, 18 doczekanych = 40% renderów do kosza, blokujących sześć połączeń przeglądarki); (3) pomiary sprawdzają TOŻSAMOŚĆ żądania (`pending === mojePola`), więc przerwane nie zamyka cyklu należącego do nowszego. **Zmierzony cykl na /odkrycia (mapa osobista):** wejście na stronę 680 ms, przesunięcie kadru 526 ms, mapa społeczności 153 ms — przy czym pola odkryć są na ekranie po ok. 50–90 ms, a kafle śladów dochodzą później i już nikogo nie blokują. |
| **Widać, co się ładuje: monitor mapy (2026-09-02, zgłoszenie usera: „muszę długo czekać, aż heksy się wyrenderują — jest bezwładność, nie wiem, co się dzieje")** | `ridemoreMapBusy(map)` w `discovery-map.js` + `.map-busy` w `style.css`. Panel na ŚRODKU mapy wymienia trwające zadania z licznikiem czasu (`ślady przejazdów 12/40`, `pola odkryć`, `skarby w kadrze`, `rysowanie pól`, `ślad przejazdu`) i po `BUSY_PROG_MS` (1,5 s) dokłada zdanie o tym, że kafle tego obszaru **powstają przy pierwszym wejściu**. **To nie modal**: `pointer-events:none`, mapa zostaje używalna. Warstwy kafli podpinają się SAME przez `layeradd` (`loading`/`tileloadstart`/`tileload`/`load`), więc nowa warstwa ze słownika jest mierzona bez zmiany kodu. Każde zakończone zadanie ląduje w `window.ridemoreMapLog` (+`console.debug`), a pojedyncze żądania powyżej progu dokłada `PerformanceObserver` — stąd wiadomo, KTÓRY kafel zjadł sekundy. W dev wpisy jadą zbiorczo do `POST /api/map/perf` → `storage/map-perf.log`. **Zmierzone tą drogą:** API pól ~80 ms, rysowanie pól ~1 ms, a kafle śladów 0,6–3,2 s **narastająco** — bo `session_start()` w `bootstrap.php` serializuje żądania jednej sesji (5 kafli równolegle: 0,97 s bez ciasteczka sesji, 3,1 s z nim). |
| **Podświetlenie śladu nie wczytuje pliku GPX (2026-09-02, zgłoszenie usera: „klikam na aktywność 200 km i czekam, aż wczyta się cały GPX, a potrzebujemy tylko ją oznaczyć")** | `ridemoreFocusTrack(map, url)` NIE używa już `leaflet-gpx`: `url` wskazuje endpoint geometrii (`/api/rides/{id}/track` dla wiersza feedu, `/api/tracks/{id}/geometry` dla wpisów `data-track` na profilu), a ten oddaje samą linię z `gpx_geometry` (migr. 051) spakowaną formatem `d6v` — delta 1e-6 stopnia, zygzak, varint, base64. Koder: `Models\GpxGeometry::packedForFile`; dekoder: `ridemoreUnpackTrack` w `discovery-map.js` — **opis formatu stoi w obu miejscach i zmiana kodowania wymaga zmiany w obu**. Zmierzone na żywym pliku: 8,26 MB GPX → 47,9 kB odpowiedzi (172×), ok. 85 ms po stronie serwera. Helper zwraca warstwę OD RAZU (pustą) i dopełnia ją po odpowiedzi, więc wołający nie zmieniają sposobu użycia; ślad bez geometrii sam zdejmuje swoją warstwę. Bramka „komu wolno" bez zmian — liczy ją `Support::rideGpxPath()` (wyciągnięte z `trackUrlsForFeed`), tej samej metody pyta endpoint |
| ~1553–1700 | **Profil rowerzysty — hero, spis sekcji, dwie kolumny** (2026-08-22, na zgłoszenie usera „nie ma polotu"). `.rp-hero*` (jeden JASNY panel zamiast trzech prostokątów: `.op-head` + pudełko „Jedzie na" + czarny `.trust-bar`; warstwice inline SVG, `.rp-next*` = karta następnego wyjazdu, `.rp-nums`/`.rp-num*` = dawny `.trust-bar` na paśmie `--tint`, liczba kolumn z markupu przez `--rp-cols`), `.anchors__c` (podpis w pasku kotwic), `.rp-cols`/`.rp-rail` (kolumna główna = rzeczy, które się CZYTA; szyna = rzeczy, które się SPRAWDZA), `.stat-tile--lead`, `.roster__bar`, `.coll*`, `.rf-item__ico--map/--pic/--talk`. Zero nowych kolorów i fontów — wszystko z palety „Szlak" |
| ~1700–1780 | Aktywność na profilu rowerzysty (`Models\RiderFeed`) |
| ~1658–1720 | Dopasowania na liście wyjazdów (`match-grid.php`); heksagon przy liczbach pól |
| ~1723–1800 | Przełączniki warstw mapy (zwijana kontrolka) + wariant na telefon |
| ~2127–2300 | Kreator dodawania wydarzenia (przebudowa wg `szablony/dodaj-kreator.html`) |
| ~2307–2400 | Profil organizatora (przebudowa wg `szablony/organizator-v3.html`) |
| ~2402–2450 | Kronika: zdjęcia we wpisie, podgląd w `<dialog>`, formularz wpisu |
| ~2452–2466 | Stawki punktowe w panelu (migr. 053) |
| ~2468–2620 | Skarby (Etap 8D): panel, ekran spod QR, znacznik i pęczek na mapie, wyszukiwarka miejsc |
| ~3398–3447 | Formularz zgłoszenia skarbu (`.tp-*`): układ horyzontalny, pinezki warstwy, ujawnienie na mapie |
| przy `.chr-facts` | **Discovery (Etap 8)** — `.trail*` (pasek postępu znanej trasy: `__head/__name/__pct/__pct--done/__bar/__meta`), `.track-list*`/`.track-upload*` (wgrywanie śladu z odbytego wyjazdu — własna sekcja, nie stopka), `.hex-legend*` (legenda mgły) i `.leaflet-discoveryFog-pane` (kolor/tryb mieszania mgły — TU się ją stroi, nie w JS; zakomentowany `mix-blend-mode:saturation` to gotowa alternatywa dla rozjaśniania). To JEDYNE nowe klasy całego modułu; reszta Discovery złożona jest z `.op-head`, `.trust-bar`, `.op-grid`/`.op-card`, `.event-map`, `.chr-facts`, `.tags`. Powód wyjątku: serwis nie miał niczego pokazującego UŁAMEK ukończenia ani skali zamglenia |

| koniec pliku | **Tryb aplikacji** `body.is-app` (2026-08-22) — zapas na dolny pasek, ukryta stopka, przesunięte `.k-nav`/`.mbar`, `.app-nav*` |

### Telefon — trzy rzeczy zmienione 2026-08-22 (Etap 2 apki mobilnej)

- **`.disc-panel` jest SZUFLADĄ OD DOŁU poniżej 680 px**, nie `display:none` jak dotąd.
  Do tej daty na telefonie znikały obie listy skarbów („Czekają"/„W obszarze") razem
  z „Ostatnią aktywnością" — na ekranie, który jest przede wszystkim terenowy.
  Zwinięta pokazuje sam uchwyt (`.disc-panel__handle`, 46 px, z liczbą skarbów
  w kadrze), otwarta bierze 62% wysokości mapy. **Uchwyt dokłada JS** w `discovery.php`
  dla KAŻDEGO `.disc-panel` — mapa osobista i wspólna mają różne panele, a to jedno
  zachowanie. Zwijanie robi `max-height`, nie `display:none` na dzieciach: `display:revert`
  przy otwieraniu nie przywraca `display:flex` z reguły autorskiej. **Bez `transition`** —
  Chrome interpoluje `46px`→`62%` jako `calc(0% + 46px)` i szuflada nie otwiera się wcale
  (zmierzone).
- **`.disc-map` na telefonie ma `min(62vh,520px)`** zamiast 380 px — po tym ekranie
  się przesuwa i przybliża, a mniejszy kadr to więcej gestów na ten sam teren.
- **Cele dotykowe pod `@media(pointer:coarse)`** (sposób obsługi, nie szerokość ekranu —
  ta sama zasada co przy przycisku „jestem tutaj" w dymku skarbu): `.btn--sm`, `.chip`,
  `.view-btn`, sortowanie i przyciski zoomu Leafletu do 40 px; w `@media(max-width:680px)`
  także `.map-layers summary`/`input` i `.disc-panel__tabs button`. Zoom Leafletu wymaga
  selektora `.leaflet-container.leaflet-touch .leaflet-bar a` — `leaflet.css` wchodzi
  przez `$extraHead`, czyli PO tym arkuszu, i przy równej wadze wygrywa.

Edytując CSS: **grepuj nazwę klasy** przed dodaniem nowej reguły (kolizje), po zmianie
sprawdzaj bilans klamer/komentarzy. Wiele reguł ma komentarz „dlaczego" — czytaj go.

## JavaScript — `assets/js/`

- `ui.js` — drobne interakcje globalne (ładowane w każdym layoucie, `defer`).
- `native.js` — **most do funkcji natywnych**, `window.RM.native` (2026-08-22).
  Ładowany ZAWSZE, także w przeglądarce: to on udaje plugin, gdy go nie ma, więc
  strony wołają jedno API i nie sprawdzają same `window.Capacitor`. Dziś: `available`,
  `position()` (obietnica `{lat, lon, accuracy}` — plugin Geolocation albo
  `navigator.geolocation`; opcje `timeout`/`highAccuracy`/`maximumAge`),
  `positionError()` (jeden komunikat dla obu źródeł — **od 2026-09-10 to
  wreszcie prawda: gałąź natywna dotąd go OMIJAŁA i pokazywała surowe angielskie
  komunikaty wtyczki; doszło rozpoznawanie po treści błędu, bo wtyczka nie używa
  kodów W3C**), `canShareFile()`/`shareFile(url, nazwa)` (plik do innej aplikacji
  przez Filesystem + Share — patrz `app-share.js` niżej), `scan()` (skaner QR),
  `adresSkarbu()` (bramka adresu z kodu) i obsługa `[data-rm-scan]`. Zasada: brak
  pluginu = zejście do odpowiednika przeglądarkowego albo uczciwy komunikat, nigdy cisza.
  **`navigator.geolocation` nie wolno wołać nigdzie indziej** — pilnuje tego test
  `most: żadna strona nie woła geolokalizacji z pominięciem mostu`. Siedem miejsc,
  które robiły to wcześniej, przeszło na most 2026-08-22 (Etap 5): `discovery-map.js`
  (dymek skarbu), `discovery.php`, `treasure-scan.php`, `treasure-propose.php`,
  `home.php`, `events-list.php`, `treasures-admin.php`. Przy okazji dobrane
  parametry per miejsce: filtrowanie listy promieniem 50 km nie potrzebuje
  `highAccuracy` ani świeżej pozycji, a zaliczenie skarbu potrzebuje obu.
  **WYGASZENIE STRONY NA CZAS KADRU MA `!important` (naprawa 2026-08-29,
  zgłoszenie usera „klikam skanuj, a strona zostaje w tle")** — `html.rm-scan-on
  .wrap{display:none}` to dwie klasy i element, a `body.is-app.map-page .wrap
  {display:flex}` to trzy klasy, więc na KAŻDYM ekranie z mapą (`/odkrycia`,
  `/skarby/zglos`) reguła gasząca przegrywała wagą i skaner startował z całym
  interfejsem na wierzchu zamiast z obrazem z aparatu. Odtworzone pomiarem
  (`getComputedStyle(.wrap).display === 'flex'` przy włączonej klasie), nie
  z lektury. Podbicie specyficzności przez dopisanie klas naprawiłoby ten jeden
  przypadek i pękło przy następnym `bodyClass` — to jest stan typu wyłącznik
  awaryjny i tak jest zapisany.
  **Skaner** (`scan()`) używa `startScan`/`stopScan`, nie androidowego `scan()` —
  ten drugi nie istnieje na iOS, więc byłyby dwie ścieżki i tylko jedna testowana.
  Podgląd kamery rysuje się POD WebView, więc klasa `rm-scan-on` na `<html>` robi
  stronę przezroczystą (style na końcu `style.css`), a jedna funkcja `koniec()`
  sprząta po każdym zakończeniu — przy osobnych wyjściach dla kodu, anulowania
  i błędu któreś zostawiłoby włączony aparat i niewidoczną stronę.
  **Etap 5b (2026-08-28)** dokłada: `watchPosition()`/`clearWatch()` — ŚLEDZENIE
  CIĄGŁE plikiem `@capacitor/geolocation` (kropka „tu jestem" na
  `discovery-app.php`, TYLKO na pierwszym planie — ten plugin nie gwarantuje
  pozycji w tle); `startTracking()`/`stopTracking()` — ŚWIADOMIE INNY plugin,
  `@capacitor-community/background-geolocation`, jedyny w apce z prawdziwym
  wsparciem tła (usługa pierwszoplanowa na Androidzie, tryb tła na iOS); `notify()`
  — powiadomienie lokalne (`@capacitor/local-notifications`, z fallbackiem do Web
  Notifications API w przeglądarce, JEŚLI zgoda już jest); `requestBackgroundPermission()`
  — zgoda na powiadomienia (geolokalizacja pyta się sama przy pierwszym `startTracking()`).
  W przeglądarce `startTracking()` **odrzuca z komunikatem** — w odróżnieniu od
  reszty mostu, tu naprawdę nie ma odpowiednika.
  **Etap 6 (2026-08-28)** dokłada `takePhoto()` (`@capacitor/camera`, metoda
  `takePhoto` — NIE przestarzałe `getPhoto`) i delegację kliknięcia
  `[data-rm-camera-for="id-inputu"]`: zdjęcie z aparatu ląduje w ISTNIEJĄCYM
  `<input type=file multiple>` o tym id przez `DataTransfer` (dokładane, nie
  nadpisujące — kilka zdjęć albo aparat + wybór z galerii mają się zsumować),
  więc formularz zostaje zwykłym multipart POST-em i serwer nic o tym nie wie.
  Dziś jedyny cel: `#tsGalPhotos` na `treasure-scan.php` (galeria skarbu) —
  `/skarby/zglos` nie ma pola na zdjęcie, więc nie ma czego tam podpiąć
  (patrz uwaga w `tasks/active/apka-mobilna.md`, Etap 6).
  **Etap 8 (2026-08-28)** dokłada `registerPush()` (`@capacitor/push-notifications`):
  `requestPermissions()` → `register()` → nasłuch `registration` (token) →
  `POST /api/devices/register` (patrz `routing.md`). Wołane WYŁĄCZNIE z
  przełącznika w `/admin/moje-konto` (`views/web/pages/account.php`), NIGDY
  automatycznie przy starcie apki — pytanie o uprawnienie systemowe musi iść
  za jawną decyzją usera, ten sam odruch co zgoda na nagrywanie w tle
  (Etap 5b). Wyłączenie w koncie NIE woła mostu w ogóle — to zwykły `fetch`
  na `/api/devices/unregister`, bo cofnięcie zgody jest czysto serwerową
  zmianą stanu (`is_active=0`).
  **Etap 7 (2026-08-28)** dokłada kolejkę zeskanowanych kodów bez zasięgu —
  samodzielny blok tuż nad handlerem `[data-rm-scan]`: `native.queueScan(kod)`
  (dołącza do `@capacitor/preferences`, z pozycją najlepszego wysiłku —
  3 s timeout, offline GPS bywa wolny) i `przetworzKolejke()` (wysyła zaległe
  na `POST /skarb/{code}` z `Accept: application/json` — patrz `routing.md`
  — przy `networkStatusChange` na `Network` i przy starcie skryptu). Handler
  skanera pyta `Network.getStatus()` PRZED nawigacją: online zachowuje się
  jak dotąd (`window.location.href`), offline woła `queueScan` zamiast
  nawigacji, która i tak nic by nie pobrała. Udany zaległy skan kończy się
  `native.notify()` (ten sam most co Etap 5b) — bez ponownego skanowania.
  `errorPath: 'index.html'` w `capacitor.config.ts` (Etap 7) w końcu WŁĄCZA
  ekran „brak połączenia" (`app/www/index.html`), który istniał od Etapu 3,
  ale nigdy nie był podpięty — biała strona / systemowy błąd zamiast niego
  to była luka odkryta przy weryfikacji tego etapu, nie przy Etapie 3.
- `app-share.js` (2026-09-10) — **wysyłanie plików z apki do innych aplikacji**,
  dziś GPX trasy do nawigacji. Ładowany wyłącznie w `APP_IS_APP`.
  Powód istnienia: `<a href="…gpx" download>` **w apce nie działał od zawsze** —
  adres z własnego hosta wraca do WebView (`Bridge.launchIntent` → `false`),
  a WebView bez `DownloadListener` nie ma czym pobrać pliku; Capacitor takiego
  listenera nie ustawia nigdzie w `@capacitor/android`. Naprawa jest dwustronna:
  natywnie `MainActivity` ustawia `DownloadListener` → systemowy `DownloadManager`
  (z przepisanym cookie sesji, bo to osobny proces i inaczej pobrałby stronę
  logowania jako `.gpx`), a w JS ten plik **podmienia napis na „Wyślij do
  nawigacji" i przechwytuje klik** — ale TYLKO gdy most potwierdzi
  `canShareFile()`. Bez wtyczek zostaje „Pobierz GPX" i droga natywna: przycisk
  nie obiecuje arkusza, gdy umie tylko pobrać. Delegacja na `document` obejmuje
  wszystkie trzy strony z GPX-em i te, które dopiero powstaną — zamiast gałęzi
  `if (APP_IS_APP)` w każdym szablonie z osobna.
- `app-tracking.js` (Etap 5b, 2026-08-28) — **orkiestracja** nagrywania w tle
  i alertów o skarbach, ładowana WYŁĄCZNIE w `APP_IS_APP` (z `layout.php`,
  globalnie, nie z jednej podstrony — tło może się włączyć z każdego ekranu).
  Nie rysuje niczego: gdy skarb wejdzie/wyjdzie z promienia alertu, wysyła
  `window` zdarzenia `rm:treasure-near`/`rm:treasure-far`, które łapie (jeśli
  akurat otwarty) skrypt mapy w `discovery-app.php` i rysuje pulsujący
  znacznik (`.rm-near-marker`, `L.divIcon`). Cykl: baner zgody (`.rm-bg-consent`,
  raz — odmowa nie pyta ponownie) → `startTracking()` → bufor punktów w pamięci
  + okresowy zrzut do `@capacitor/preferences` (przetrwanie ubicia procesu) →
  pastylka `.rm-bg-pill` z przyciskiem stop → na koniec złożenie GPX i wysyłka
  na **ISTNIEJĄCY** `POST /admin/moje-przejazdy/solo`
  (`SoloRideController::upload`) — zero nowej tabeli, zero nowego kodu
  przyjmującego ślad; `Treasure::claimAlongTrack` zalicza skarby po drodze
  jak przy ręcznym GPX. Auto-stop po 20 min bez ruchu. Cache skarbów pod alerty
  z `GET /api/discovery/nearby-treasures` (patrz `routing.md`), odległość liczona
  WŁASNYM haversine (nie Leafletem — ten plik działa też tam, gdzie żadnej mapy
  nie ma). Throttling: jeden alert na skarb na dobę, tylko przy ruchu.
  **NAPRAWA 2026-08-29 (zgłoszenie z terenu: „przejechałem 5 km, nie odsłoniło
  ani jednego hexa")** — dwie rzeczy, obie w `toGpx`/`uploadTrack`:
  (1) składany dokument NIE MIAŁ `xmlns="http://www.topografix.com/GPX/1/1"`,
  a `Utils\Gpx::parse` szuka punktów xpath-em `//gpx:trkpt` z tym prefiksem —
  więc KAŻDE nagranie z apki leciało do kosza jako „Brak punktów trasy";
  (2) wysyłka uznawała za sukces cokolwiek, co wróciło (`r.ok` jest prawdą
  także dla strony z `?blad=`, bo `fetch` śledzi przekierowanie), więc bufor
  z nagraniem kasował się razem z odrzuconym plikiem — awaria była CICHA.
  Dziś wysyłka prosi o `Accept: application/json` (gałąź w `SoloRideController`,
  patrz `controllers.md`) i rozróżnia trzy wyniki: `ok` (kasuj bufor),
  `odrzucony` (kasuj, ale powiedz o tym `native.notify()` — ponowienie nic nie
  da), `siec` (zostaw do następnego uruchomienia apki). Przy okazji naprawia to
  ekran wyniku jazdy: to `fetch` konsumował sesję `ride_summary` przed userem.
  Testy: `tests/przejazdy_solo_test.php` (sekcja „ŚLAD NAGRANY W APCE") biorą
  znacznik `<gpx ...>` WPROST z tego pliku i puszczają nagranie przez
  `RiderActivity::recordSolo`.
  **CYKL ŻYCIA PRZEJAZDU (decyzja usera 2026-08-29: „jeden przejazd do
  Zatrzymaj")** — apka chodzi na `server.url`, więc KAŻDE przejście między
  ekranami przeładowuje stronę i uruchamia ten plik od nowa. Dotąd znaczyło to:
  wyślij bufor jako OSOBNY przejazd, wystartuj nowy watcher, a starego zostaw
  w usłudze natywnej (jego id żyło tylko w pamięci strony) — jeden przejazd
  rozpadał się na kawałki, a KAŻDY kawałek tracił po 400 m z obu końców
  (`home_trim_radius_m`), czyli znikały też hexy ze środka trasy. Dziś stan
  sesji (`rm_bg_session`: `watcherId`, `startedAt`, `lastAt`) leży
  w Preferences, a start pliku rozstrzyga trzy przypadki: świeża sesja →
  zdejmij osierocony watcher i nagrywaj DALEJ tym samym buforem; sesja starsza
  niż `STALE_MS` (30 min bez punktu) → zamknij przejazd i wyślij; brak sesji →
  wyślij ewentualny bufor po awarii. **Zgoda ODBLOKOWUJE nagrywanie, ale go nie
  zaczyna** — przejazd zaczyna dotknięcie „Nagrywaj" (chip przy licznikach na
  `discovery-app.php`, `[data-rm-bg-toggle]`, stany `off`/`on`/`blad`; to także
  jedyna droga powrotu po „Nie teraz" w banerze). Zrzut bufora co 5 punktów
  (~75 m) i na `pagehide`, bo przeładowanie potrafi wejść w środek jazdy.
  Zweryfikowane symulacją przeładowań (stuby mostu i `fetch`), nie samym
  czytaniem kodu: nawigacja nie generuje żadnej wysyłki i kontynuuje bufor,
  a nieświeża sesja wysyła dokładnie jeden ślad.
  **PRZEŁĄCZNIKI WARSTW BYŁY MARTWE W APCE (2026-08-30, zgłoszenie:
  „zaznaczanie checkboxa w kontrolce Warstwy w ogóle nie działa")**. Kontrolka
  jest wspólna od 2026-08-19 (`partials/map-layers.php`), ale do partiala
  przeniesiono WYGLĄD, a nie ZACHOWANIE: podpięcie `change → ridemoreSetLayer`
  zostało po stronie każdej strony osobno (`discovery.php`, `trail.php`,
  `rider-profile.php`, `event-page.php` — cztery kopie tego samego listenera).
  Piąty ekran, `discovery-app.php`, dostał kontrolkę i **nie dostał podpięcia**:
  czytał stan checkboxów RAZ, przy tworzeniu mapy (`ridemoreReadLayers(box)`),
  i potem nie słuchał już niczego — checkbox się zaznaczał i nie robił nic.
  Podpięcie mieszka od teraz w `discovery-map.js` jako `ridemoreBindLayers(map,
  box)`; bierze stan z czytnika (więc dziecko dostaje `checked ∧ rodzic`, a nie
  surowe `cb.checked`) i przy przełączeniu rodzica przepisuje też stan skuteczny
  dzieci. Zapis stanu w adresie zostaje w `discovery.php` — to jedyny ekran,
  którego adres da się komuś podesłać. Pilnuje tego test „mapy: KAŻDY ekran
  z kontrolką warstw ma podpięte przełączniki", który przechodzi po wszystkich
  widokach z partialem. **Cztery stare kopie listenera zostają** — działają
  i ich przepisywanie jest poza zakresem tej poprawki.
  **KADR STARTOWY I PASEK — DWIE POPRAWKI Z 2026-08-30** (zgłoszenie: „klikam
  Mapę na dolnej belce i czekam długo; jak już jestem na mapie i kliknę raz
  jeszcze, przeładowuje stronę i dla zalogowanego ustawia na środku Polski").
  - **Kadr awaryjny nakłada się OD RAZU, nie po 6 s.** Środek Polski to był
    widok domyślny Leafletu, w którym mapa siedziała przez pierwsze sekundy
    KAŻDEGO wejścia — `bounds:null` idzie do silnika celowo (żeby
    `applyStartBounds()` nie przebił wycentrowania na GPS), a prostokąt odkryć
    czekał na `setTimeout`. Prostokąt jest policzony, zanim strona wyjdzie,
    więc nie ma po co go trzymać. **GPS dalej wygrywa**: `kadrNaMnie()` blokuje
    wyłącznie przeciągnięcie palcem, więc pierwszy odczyt nadal przestawia mapę
    na człowieka — tylko teraz z jego okolicy, a nie z całego kraju.
  - **Slot „Mapa" na `/odkrycia` jest PRZYCISKIEM, nie linkiem do samego
    siebie** (`app-nav.php`, `data-rm-map-here`). Apka chodzi na `server.url`,
    więc link do adresu, na którym stoisz, to pełna podróż do serwera i budowa
    mapy od zera. Skoro slot jest wtedy podświetlony jako aktywny, dotknięcie
    nie znaczy „zabierz mnie na mapę", tylko „pokaż mi MNIE" — przycisk pyta
    o pozycję i przestawia kadr. To zarazem **jedyna droga powrotu** po tym, jak
    ktoś odjechał mapą palcem: `userRuszylMapa` blokuje automat na zawsze
    i celowo tak zostaje (automat nie wyrywa mapy z ręki), ale świadome
    dotknięcie tę blokadę zdejmuje.
  **STAN NAGRYWANIA — JEDNO ŹRÓDŁO PRAWDY (przebudowa 2026-08-29, zgłoszenie:
  „powiadomienie mówi, że nagrywa, wchodzę w mapę — mam »Nagraj«, klikam
  i przenosi mnie do uploadu GPX")**. Stan żył w TRZECH miejscach naraz:
  usługa natywna (powiadomienie), `rm_bg_session` w Preferences i pamięć JS
  strony. Pamięć JS ginie przy KAŻDEJ nawigacji, usługa nie ginie nigdy,
  a Preferences czyta się asynchronicznie — więc UI malował się z pamięci,
  zanim ktokolwiek sprawdził prawdę. Wtyczka tła **nie ma czym odpowiedzieć**
  „czy nagrywam" (jej definicje typów znają wyłącznie `addWatcher`,
  `removeWatcher`, `openSettings`), więc jedynym źródłem prawdy przeżywającym
  stronę jest znacznik w Preferences. Zasady, które z tego zostają:
  - **nic nie malujemy, dopóki nie wiemy** — chip ma `hidden` w markupie
    i pozostaje niewidoczny do rozstrzygnięcia; brak odpowiedzi jest uczciwszy
    niż zła odpowiedź;
  - **stan ma cztery wartości, nie dwie**: `off` / `startuje` / `on` / `blad`.
    „Próbuję wystartować" to osobny stan — to w nim tkwiło zgłoszone
    kliknięcie, bo stara flaga `sessionActive` była już podniesiona, choć
    watchera jeszcze nie było;
  - **świeży znacznik = usługa pracuje** → „Nagrywam" malowane NATYCHMIAST,
    przed czymkolwiek asynchronicznym;
  - **zatrzymanie działa z każdej strony**: gdy pamięć nie zna id watchera,
    `zdejmijWatcher()` bierze je z Preferences — inaczej „Zatrzymaj" na świeżo
    otwartym ekranie zostawiało pracujący GPS, a kolejne dotknięcie dokładało
    DRUGIEGO watchera;
  - **najpierw zapis faktu, potem rysowanie** (`zapiszSesje` → `renderStan` →
    `try { showPill() }`): wyjątek w rysowaniu leciał wcześniej do `.catch`
    startu, czyli kasował sesję i pokazywał „Brak zgody", podczas gdy watcher
    działał;
  - **PRZYCISK NIGDY NIE NAWIGUJE** (decyzja usera 2026-08-29: „ma informować,
    czy się nagrywa, i pozwalać zatrzymać lub rozpocząć — nic poza tym; nie
    chcę, żeby odwoływał się do jakichś uploadów"). Świadomy stop i auto-stop
    z bezruchu robią dziś dokładnie to samo, więc flaga `manual` zniknęła,
    a razem z nią `goToSummary()` i globalna `RM_SUMMARY_URL` (zmienna bez
    odbiorcy to obietnica zachowania, którego nie ma). Wynik przejazdu
    przychodzi zdarzeniem `rm:ride-saved` → **powiadomieniem** z liczbą
    odkrytych pól i ścieżką do podsumowania: można je zignorować albo w nie
    dotknąć. `uploadTrack` odróżnia przy tym `pusto` (nagranie bez ani jednego
    odcinka) od `ok`, więc puste zatrzymanie nie zgłasza żadnego przejazdu.
  Zweryfikowane symulacją modułu odtwarzającą zgłoszony scenariusz (start →
  nawigacja na nową stronę → dotknięcie chipa) oraz przypadek „system nie
  oddaje dostępu".
  **STAN PRZEJŚCIOWY NIE MOŻE BYĆ PUŁAPKĄ (poprawka 2026-08-30, zgłoszenie:
  „klikam nagrywanie, dostaję powiadomienie, że rozpoczyna, ale status się nie
  zmienia — cały czas mam rozpoczynanie")**. Wersja z 2026-08-29 wychodziła
  ze stanu `startuje` **wyłącznie** wtedy, gdy rozstrzygnęła się obietnica
  z `addWatcher()`, a dotknięcie w tym stanie było ignorowane. Gdy więc most
  Capacitora nie odpowiedział (albo cały łańcuch zerwała odmowa zgody na
  powiadomienia, w której `startSession` wisiało w `.then`), chip zostawał na
  „Włączam…" do końca życia procesu, a usługa natywna nagrywała dalej z własnym
  powiadomieniem — czyli dokładnie ten rozjazd, który poprzednia przebudowa
  miała zlikwidować, tylko z innym stanem. Cztery zasady, które to zamykają:
  - **dowód bije obietnicę** — pierwsza pozycja z callbacka kończy `startuje`
    tak samo dobrze jak id watchera z mostu; obie drogi schodzą się
    w `potwierdzStart()` (idempotentnym, bo przy kontynuacji stan jest już `on`,
    a i tak trzeba zapisać ŚWIEŻE id watchera do znacznika sesji);
  - **stan przejściowy ma termin** (`START_TIMEOUT_MS`, 20 s) — po nim, jeśli
    nie ma ani id, ani jednej pozycji, chip mówi „Brak zgody" zamiast udawać,
    że wciąż startuje;
  - **dotknięcie ma odpowiedź w każdym stanie** — w `startuje` anuluje próbę
    i wraca na „Nagraj";
  - **zgoda na powiadomienia nie jest warunkiem nagrywania** — pytamy o nią
    i jedziemy dalej niezależnie od odpowiedzi (potrzebują jej alerty
    o skarbach, nie zbieranie pozycji).
  **KONTYNUACJA PO NAWIGACJI PODPINA NOWY WATCHER (ta sama poprawka)**.
  `startSession()` blokowało się warunkiem `if (trwa()) return;`, czyli na
  NAMALOWANYM stanie — a `wznowSesje()` maluje „Nagrywam" *przed* wywołaniem
  `wlaczNagrywanie(true)`. Kontynuacja trafiała więc w ten warunek i wracała
  już po zdjęciu osieroconego watchera: pierwsze przejście na inny ekran
  w trakcie jazdy **cicho kończyło zbieranie pozycji**, a chip do końca
  pokazywał „Nagrywam". Warunek stoi dziś na `watcherId` (pamięć TEJ strony,
  pusta po przeładowaniu) — dalej chroni przed drugim watcherem na jednym
  ekranie, ale nie blokuje wznowienia.

  **JEDEN KLIENT LOKALIZACJI, NIE DWA (2026-08-29, zgłoszenie: „po 40 min
  z pełnej baterii zrobiło się 0")** — `discovery-app.php` otwierał WŁASNY
  `watchPosition({highAccuracy:true, maximumAge:0})` bezwarunkowo i nigdy go
  nie zamykał: ani przy zejściu ekranu w tło, ani gdy nagrywanie w tle i tak
  trzymało odbiornik. Android SCALA żądania lokalizacji i obsługuje
  najbardziej wymagające, więc ten jeden nasłuch podnosił GNSS do pełnej
  częstości niezależnie od tego, jak oszczędnie ustawione było nagrywanie —
  a przy otwartej mapie w czasie jazdy pracowały dwa strumienie naraz.
  Dziś: `app-tracking.js` rozgłasza `rm:position` (pozycje z nagrywania są
  DARMOWE, odbiornik i tak pracuje) oraz `rm:tracking` (stan), a mapa otwiera
  własny nasłuch TYLKO gdy nikt inny nie trzyma GPS-u i zamyka go przy
  `visibilitychange` → hidden. Pierwszy `clearWatch` w historii tego ekranu.
  **Punkt odniesienia — OwnTracks** (sprawdzone w ich dokumentacji): jeden
  klient i przełączane tryby — „significant change" na co dzień (balanced
  power, fix co ~15 min, cell/WiFi) i „move" na czas jazdy (GPS co 10 s),
  o którym sami piszą, że kosztuje jak nawigacja i ma być włączany tylko na
  przejazd. Nasze nagrywanie JEST odpowiednikiem ich „move", więc poniżej
  kosztu nawigacji nie zejdziemy — ale nie ma powodu płacić go dwa razy.

  **PRÓBKOWANIE I ENERGIA (2026-08-29, pytanie usera: „nie potrzebuję pobierać
  lokalizacji, jeśli stoję w miejscu")** — trzy warstwy filtrowania, każda o co
  innego: (1) `distanceFilter: 25` w `native.js` (podniesione z 15 po zgłoszeniu
  o baterii) — filtr WTYCZKI, liczony od poprzedniego odczytu, czyli sam
  z siebie dopasowany do prędkości: przy 20 km/h punkt co ~4,5 s, przy 40 km/h
  co ~2,3 s. Przy 15 m wychodziło co 2,7 s, czyli czterokrotnie częściej niż
  tryb „move" OwnTracks — bez zysku dla kształtu śladu, który i tak liczy się
  na polach ~500 m; (2) `MAX_ACC_M = 40` —
  odczyt gorszy niż 40 m to nie pozycja, tylko zgadywanie, i wypada w całości,
  także z alertów o skarbach; (3) próg przesunięcia liczony OD OSTATNIEGO
  ZAPISANEGO punktu i **zależny od prędkości**: `MOVE_MIN_M = 10` w jeździe,
  `MOVE_MIN_STOJAC_M = 40` poniżej 1 m/s. Powód trzeciej warstwy: filtr wtyczki
  szumu nie odsiewa — pozycja skacząca ±16 m wokół miejsca dzieli od poprzedniej
  32 m, czyli przechodzi. Zmierzone symulacją modułu: 40 odczytów szumu na
  postoju dawało 40 punktów w śladzie, teraz daje **0**, a 30 punktów jazdy co
  20 m przechodzi w komplecie. UWAGA na proporcje: to oszczędza PLIK, ZAPISY
  i WYSYŁKĘ, nie prąd — baterię zjada włączony odbiornik GPS, a tym steruje
  `distanceFilter` wtyczki i auto-stop po 20 min bezruchu.

  **SKARB MIJANY PO DRODZE (2026-08-29)** — `checkAlerts` rozróżnia teraz DWA
  promienie: szerszy (`claim_radius_m + ALERT_BUFFER_M`) dalej ostrzega
  „zbliżasz się", węższy — prawdziwy promień skarbu — **zalicza** przez
  `POST /api/treasures/claim` (endpoint istniał od SKA/6, apka go nigdy nie
  wołała). Online: notka „Zaliczono: {nazwa} · +{punkty} · {kategoria} —
  {opis}", tapnięcie otwiera `/skarb/{code}` (`extra.url` w powiadomieniu,
  nasłuch `localNotificationActionPerformed` w `native.js`, nawigacja SKŁADANA
  z origin + base_path, nigdy z treści powiadomienia). Offline: notka od razu
  z danych, które telefon już ma, a pozycja idzie do **tej samej kolejki co
  zaległe skany** (`native.queueClaim`, `wyslijZalegly` rozgałęzia się po
  obecności `code`) i zalicza się sama po powrocie zasięgu. Zaliczony skarb
  NIE dostaje już alertu „zbliżasz się" — to byłoby drugie powiadomienie o tym
  samym, i do tego nieprawdziwe. Prywatność: pozycja wychodzi na serwer
  WYŁĄCZNIE w chwili zaliczenia (jeden punkt, przy skarbie o współrzędnych,
  które serwer i tak zna) — zasada „w tle nie strumieniujemy pozycji" zostaje.
  Zweryfikowane symulacją przejazdu obok skarbu w obu trybach sieci.
- `gpx-map.js` — helpery mapy Leaflet: `ridemoreCreateMap`, `ridemoreAddGpxTrack` (ładowane tylko na stronach z mapą przez `Support::leafletMapHead/gpxMapHead`).
- **Podkład mapowy** (2026-08-25) — też w `gpx-map.js`: domyślnie **OpenFreeMap „Positron"**
  (`RIDEMORE_BASE_DEFAULT = 'ofm'`, styl z `https://tiles.openfreemap.org/styles/positron`),
  minimalistyczny, żeby nakładki Ridemore były czytelniejsze. OpenFreeMap NIE MA kafli
  rastrowych (tylko vector pbf + styl MapLibre), więc podkład idzie przez oficjalny binding
  `@maplibre/maplibre-gl-leaflet` + MapLibre GL — skrypty dokłada `Support::leafletHead()`
  i ich KOLEJNOŚĆ ma znaczenie (binding czyta globalne `L` i `maplibregl` przy starcie).
  Binding rysuje GL-a w `tilePane` (z-index 200), czyli POD pane'ami Ridemore:
  hex 350 < tracks 360 < overlayPane 400. Atrybucję podaje kod (`attributionControl.customAttribution`),
  bo styl Positron nie niesie jej w źródłach (zweryfikowane na JSON-ie stylu); OSM zostaje
  alternatywą przez `ridemoreSetBaseLayer(map, 'osm')` — UI przełącznika podkładu celowo
  nie ma, kontrolka `map-layers.php` dotyczy nakładek aplikacyjnych. Brak WebGL = spadek
  na raster OSM. `L.map(el, {maxZoom: 18})` jest teraz jawne — dotąd limit nadawała warstwa
  kafli OSM, a warstwa wektorowa tego nie robi.
  **Wydajność (2026-08-25, „strasznie wolno się ładuje")**: oba ciężkie skrypty GL (~1 MB)
  idą z `defer` — synchroniczne blokowały render całej strony do ich pobrania. Defer wykonuje
  się po parsowaniu HTML, a kilka stron tworzy mapy w inline `<script>`, więc taka mapa
  dostaje od razu raster OSM i ląduje w kolejce `RIDEMORE_GL_CZEKA`; gdy binding dojdzie,
  `ridemoreCzekajNaGl` podmienia jej podkład na wektor (próby liczone do ~30 s — przy padłym
  CDN zostaje OSM). `Support::leafletHead()` dokłada też `preconnect` do
  `tiles.openfreemap.org` — host kafli, fontów (glyphs) i sprite'ów jest wspólny.
  **Podłoga zoomu** (`RIDEMORE_MAP_LIMITS`): `minZoom 5` — widok kontynentu wyglądał
  jak niedziałająca mapa (wektor prawie pusty, a kafle Ridemore mają minZoom 4);
  wszystkie strony i tak operują w zoomach 6–14. Świadomie BEZ maxBounds —
  ograniczony wyłącznie zoom, nie geografia.
- `ridemoreAddTileLayer(map, url, {kind})` w `gpx-map.js` — **KAFLE RASTROWE**
  (migr. 051, 2026-08-14). Od tej zmiany to jest domyślny sposób pokazywania śladów
  i pól na mapach; `ridemoreAddGpxTrack` zostaje wyłącznie dla POJEDYNCZEJ, wybranej
  trasy, którą trzeba przestylować.
  **Powód, zmierzony:** profil z 600 przejazdami pobierał 600 plików GPX — ok. 108 MB,
  ok. 25 s do pierwszego obrazu, ok. 2,7 s zwiechy na krok zoomu (41 ms na ślad przy
  budowie, 4,5 ms na ślad na zoom). Kafel kosztuje tyle samo przy 6 i przy 6000 śladów.
  **Zmierzone po zmianie:** profil pobiera **0 plików GPX** przy wejściu; klik w pozycję
  listy dociąga **1** plik.
  `maxNativeZoom: 14` — wyżej serwer nie generuje (sam z15 to 804 000 kafli na Polskę),
  Leaflet powiększa ostatni obraz. Panele: `ridemoreTilesHex` (350) < `ridemoreTilesTracks`
  (360) < `overlayPane` (400) na podświetlony ślad — kolejność nie może zależeć od tego,
  co wczyta się pierwsze.
  **Kadr bierze się z serwera** (`GpxGeometry::boundsFor`), nie z wczytanych plików:
  przedtem, żeby wiedzieć GDZIE ustawić mapę, trzeba było pobrać całą historię.
  Adres warstwy z `TileCache::urlTemplate()` niesie `?v=<epoka>` — patrz md/features.md.

- `region-map-admin.php` (`/admin/regiony-mapa`) — **rysowanie obrysów regionów**
  (2026-09-01). Jedyna mapa w serwisie, która świadomie NIE używa
  `ridemoreDiscoveryMap`: nie ma tu ani jednej jego warstwy (mgła, heatmapa,
  skarby, trasy), więc zbudowanie tego na tamtym silniku znaczyłoby wyłączanie
  po kolei wszystkiego, co on umie. Wspólne zostaje to, co naprawdę wspólne —
  `ridemoreCreateMap` (podkład, pełny ekran, limity zoomu) i `ridemoreHexGrid`
  (te same wzory siatki co mgła). Kliknięcie dokłada heks do obrysu, kliknięcie
  w heks już będący w obrysie zdejmuje go (jedno narzędzie zamiast pędzla
  i gumki). Siatka idzie na KANWĘ (`L.canvas`), nie do SVG: przy oddaleniu 7
  w kadr wchodzi ok. 8 200 pól, co w domyślnym rendererze jest tyle samo
  elementów DOM. Kadr startowy to oddalenie 8 ustawiane `setView(…, {animate:
  false})` — `setZoom()` uruchamia animację, która na mapie w trakcie układania
  potrafi nie dojść do skutku i zostawić kadr na domyślnej szóstce (zmierzone).
  Geometrię WSZYSTKICH regionów strona pobiera osobnym żądaniem
  (`/admin/regiony-mapa/geometria`) i trzyma w pamięci: rysuje z niej podkład
  (import kreską, obrysy linią ciągłą, wybrany grubiej + `fitBounds`), liczy
  BIAŁE PLAMY (pole siatki, którego środek nie wpada w żaden pierścień) oraz
  konflikt na żywo („zabierasz: dolnośląskie (6)"). Test „punkt w poligonie"
  jest tu przepisany z PHP (`RegionOutline::insideRings`) — jedyny sposób, żeby
  czerwone pole na ekranie znaczyło DOKŁADNIE to samo, co brak przypisania
  przy imporcie; odsiew po prostokącie otaczającym robi całą robotę
  wydajnościową (bez niego każde pole testowałoby się z 8 000 odcinków granic).

- `discovery-map.js` — **mgła odkryć** (Etap 8):
  `ridemoreDiscoveryMap(el, {context, sources, layers, filters, endpoint, map, query, emptyEl, legendEl, labels, zoom, color})`.
  Trzy zastosowania, jeden kod: `context:'all'` (wspólna mapa), `'me'` (moja),
  `'rider'` + `query:'&slug=…'` (mapa na publicznym profilu).

  **KONTRAKT WARSTW (Etap 1, 2026-08-26 — `tasks/done/warstwy-mapy.md`).** Trzy rzeczy,
  które wcześniej każda strona składała sama:
  - `context` zastąpiło `scope`. To JEDNO pojęcie zasięgu dla wszystkich rodzin warstw,
    a nie parametr samych pól — wcześniej zasięg wyrażały trzy różne notacje naraz
    (`scope` dla pól, gotowy szablon URL kafla dla śladów i tras, wyliczany `stan=` dla
    skarbów). W adresie API zostaje `scope=`: to kontrakt serwera.
  - `sources: {trails, slady}` zastąpiło osobne opcje `trailsTiles`/`sladyTiles` —
    jedna mapa kluczowana NAZWĄ WARSTWY. Szablony URL nadal buduje serwer, bo niosą
    epokę `?v=` unieważniającą kafle. Brak klucza = warstwa nie powstaje (tak strona
    trasy nie ma „Śladów"). W środku moduł też trzyma je jedną mapą `tileLayers`
    (Etap 2), nie osobną zmienną na warstwę — dołożenie trzeciej warstwy kaflowej
    w słowniku nie wymaga już zmiany tutaj. Kolejność RYSOWANIA jest jednak STAŁA
    (`['trails', 'slady']`), nie z kolejności kluczy `sources`: strony podają tę mapę
    w różnej kolejności właściwości, a od tego nie może zależeć, co jest na wierzchu.
  - `layers` czyta wspólny `ridemoreReadLayers(boxEl)` zamiast lokalnej kopii w widoku
    (były cztery, pod trzema nazwami). Oddaje **wyłącznie przełączniki, które kontrolka
    faktycznie ma** — warstwa bez przełącznika nie może wyjść stąd jako `false`, bo
    `false` znaczy „człowiek ją zgasił". Na tym stoi też warstwa zależna: brak pola
    `treasuresFound` (gość, profil) = brak klucza = obie warstwy skarbów gasną razem,
    czyli dokładnie to, co strony robiły dotąd, przekazując ręcznie `null`. Od Etapu 2
    ta sama funkcja liczy stan DZIECKA jako `checked ⊕ rodzic` przez `[data-children-of]`
    (patrz partial niżej) — **dziecko gaśnie razem z rodzicem**, niezależnie od WŁASNEGO
    checkboksa.

  **DRZEWO I SKŁADANIE FILTRA (Etap 2, 2026-08-26).** `filters` — opis
  `{rodzic: {param, children: {dziecko: wartość}}}`, budowany w kontrolerze przez
  `Models\MapLayer::filtersFor()` — czyta go `ridemoreComposeFilter(parentKey, layers,
  filters)`: oba dzieci zapalone = brak filtra (komplet), jedno = jego wartość, żadne =
  nie pytamy serwera wcale; rodzina BEZ dzieci w ogóle (np. „Skarby" na profilu
  rowerzysty) = rodzic zapalony znaczy brak filtra. GENERYCZNIE — dołożenie drugiej
  rodziny filtrowanych markerów w słowniku nie dotyka JS, tylko wiersza w danych
  i wpisu w `filters` z kontrolera. Ma zapasową (starą, dwuwartościową) regułę na
  wypadek strony, która nie zbudowała opisu.

  **`scope` JEDZIE RAZEM Z `stan` DO `/api/treasures` (Etap 3, 2026-08-26).**
  `refreshTreasures()` dokłada `&scope=` (ta sama zmienna, która steruje `/api/
  discovery/cells`) do żądania skarbów, ale TYLKO gdy `stan` jest niepuste — bez
  filtra `scope` nic by nie zmieniał. Serwer (`api/routes.php`) czyta go, żeby
  wiedzieć, WZGLĘDEM KOGO liczyć „odkryte"/„nieodkryte": `scope=all` (mapa
  społeczności) = względem KOGOKOLWIEK, każda inna wartość = jak dotąd, względem
  pytającego (`Models\Treasure::mineCondition`, param `$mineScope`).

  Obu nawyków Etapu 1 pilnują strażniki w `tests/widoki_test.php` — wracały same
  z siebie; `Models\MapLayer` (Etap 2) i skład filtra pilnuje `tests/warstwy_mapy_test.php`.
  Buduje na `ridemoreCreateMap`, ale **`map:` podaje gotową instancję** — profil kładzie
  mgłę na istniejącą mapę śladów GPX zamiast stawiać drugą; wtedy nie rusza też kadru
  (`fitBounds` należy do śladów).
  Pobiera pola dla widocznego prostokąta (debounce 250 ms, `AbortController`).
  Powtarza z PHP wyłącznie wzory Web Mercatora — cała matematyka siatki zostaje
  w `Utils\DiscoveryGrid`.
  **Rysuje ODWROTNIE niż mogłoby się wydawać**: jeden wielokąt na cały świat (`WORLD_RING`)
  z odkrytymi polami jako DZIURAMI (`fill-rule: evenodd`), plus po jednej warstwie
  częściowej mgły na stopień skali. Skala (`FOG_UNDISCOVERED`/`FOG_RAMP`/`fogRampFor`) jest
  względna wobec `max` z API; przy `max = 1` mgła schodzi całkowicie.
  Kolor i tryb mieszania w CSS (`.leaflet-discoveryFog-pane`), nie tutaj.
  **JEDNA SKALA** (projekt grafika, 2026-08-13) zamiast mgły + przełączanej heatmapy:
  `UNDISCOVERED` (szarość) + `SCALE` (zielony → żółty → pomarańcz → czerwony),
  `scaleFor()`/`levelOf()`/`renderLegend()`. Natężenie to `p` (liczba przejazdów), nie `r`
  (liczba odkrywców) — na wspólnej mapie znaczy „ilu ludzi tędy jeździ", na własnej „jak
  często jeżdżę tam ja". Etykiety w `SCALE_LABELS` per zakres; w mockupie stały obok siebie
  „Odkryte" i „Odkryte rzadko", co nie składa się w jedną drabinkę, więc rozdzielone.
  Wszystkie stopnie są PÓŁPRZEZROCZYSTE, łącznie z czerwienią — zamalowujemy odkryty teren
  zamiast go odsłaniać, więc krycie rośnie łagodnie (0,50 → 0,65), żeby drogi i nazwy
  zostały czytelne.
  **Panel Leafletu**: `discoveryHex` (z-index 350), ślady GPX w `overlayPane` (400) — bez
  tego ślad GPX mógłby wylądować pod polami.
  **Obrys pola** (2026-08-14): biały włos `0.6 px` przy kryciu `0.35` na wielokątach pól
  ODKRYTYCH; mgła zostaje bez obrysu (to jeden wielokąt na cały świat, więc obrys dałby
  ramkę wokół mapy i drugą linię po krawędziach dziur). Biel, nie ciemniejszy odcień
  wypełnienia — biel czyta się jak fuga między kaflami, ciemna kreska jak siatka. Że to nie
  robi plastra miodu, gwarantuje **drabinka poziomów z `api/routes.php`**: co dwa poziomy
  zoomu pole rośnie czterokrotnie, więc na ekranie ma stale 34,5–69 px (policzone dla 52°N;
  w progach zoom 4/6/8/10/12 dokładnie 34,5 px) — obrys to najwyżej **1,7% szerokości pola**
  i ten udział NIE rośnie przy oddalaniu.
  **Mapa kroniki robi to samo inaczej** (`chronicle.php`): rysuje stały poziom `RES_CELL`
  w kadrze dopasowanym do CAŁEGO przejazdu, więc przy trasie 100 km pole ma 3,5 px i obrys
  zająłby 17% jego szerokości — wyblakłby korytarz zamiast rozdzielić pola. Dlatego obrys
  włącza się tam dopiero od **zoomu 12** (`syncHexOutline` na `zoomend`; ten sam próg, od
  którego API podaje pojedyncze pola).
  **Bramka na zerowy kadr w `refresh()` + `ResizeObserver`** — dopóki kontener nie ma
  rozmiaru, `getBounds()` zwraca `east === west` i serwer słusznie odpowiada zerem pól,
  więc mapa zostaje w całości zamglona przy poprawnie rysujących się kafelkach. Dzieje się
  to realnie w karcie otwartej W TLE (przeglądarka odkłada pierwsze przeliczenie układu, a
  `requestAnimationFrame` w ogóle nie odpala) — dlatego nie zgadujemy opóźnienia, tylko
  **nie pytamy o pusty prostokąt i próbujemy ponownie**.
  **Dwie pułapki, obie zmierzone na żywo:** pierwsze pobranie musi iść po `invalidateSize()`
  (inaczej Leaflet pamięta kontener o zerowej szerokości i `getBounds()` daje `east === west`,
  czyli zawsze pustą mapę przy poprawnie rysujących się kafelkach); i musi to być `setTimeout`,
  a **nie `requestAnimationFrame`** — rAF nie odpala się w karcie otwartej w tle.
  **Ten sam plik obsługuje dziś także pozostałe warstwy mapy**: `drawTreasures`/
  `drawClusters`/`refreshTreasures` i `dymekSkarbu` (skarby z `/api/treasures`, przy
  oddaleniu zwijane w pęczki) oraz — od 2026-08-20 — warstwę ZNANYCH TRAS, która jest
  już tylko jedną linijką: `ridemoreAddTileLayer(map, sources.trails)`.
  **KAŻDA TRASA MA WŁASNY KOLOR** (migr. 064, zgłoszenie usera 2026-08-20: „kilka śladów
  się na siebie nakłada i nie wiadomo, który jest który, bo wszystkie są zielone").
  Kolor idzie z bazy (`known_routes.color_index` → `KnownRoute::colorOf`), więc ta sama
  trasa jest tak samo kolorowa na mapie odkryć, na profilu rowerzysty i na własnej
  stronie — także w wektorze-bohaterze na `/trasy/{slug}`, który wcześniej był na sztywno
  zielony i po zmianie kafli pokazywałby jeden szlak w dwóch kolorach. Powiązanie
  „kreska ↔ nazwa" niesie **kropka `.trail-dot`**: w dymku po kliknięciu w szlak
  (`dymekTras`, kolor z `/api/discovery/trails/at`) i na kartach tras (`.disc-trail__name`
  na `/odkrycia`, `/trasy` i profilu) — bez niej kolor mówi tylko „to dwie różne trasy".
  **STRONA TRASY `/trasy/{slug}` — jeden pasek zamiast dwóch kart** (2026-08-20,
  zgłoszenie usera: „brakuje statystyk: jaka długość, jaki profil, jakie skarby, ile
  punktów; dwie karty nad i pod mapą niewiele wnoszą"). Nad mapą stoi pięć kafli
  `stat-tiles.php` — **ten sam komponent co na `/odkrycia`, profilu i w kronice**:
  długość, przewyższenie, postęp, punkty, skarby po drodze. Pod mapą profil wysokości
  w `.profbox`/`.day-profile-big` + „chipy" podjazdów, czyli komplet ze strony
  wydarzenia; **wrapper `.day-panel active` nie jest ozdobnikiem** —
  `ridemoreRenderElevationChart` szuka po nim chipów (`closest('.day-panel')` →
  `.peak-chip`), więc bez niego klik w podjazd nic by nie robił. Wykres idzie
  **kolorem trasy** (migr. 064) i rysuje się dopiero, gdy kontener ma szerokość
  (`ResizeObserver`): buduje się w realnych pikselach i tylko RAZ (`dataset.linked`),
  więc policzony przy szerokości 0 zostałby na zawsze w awaryjnych 400 px — zmierzone
  na żywo przy pierwszym otwarciu karty. W nagłówku doszedł przycisk **Pobierz GPX**
  (`download="{slug}.gpx"`, bo nazwa pliku na dysku to hash). Wektorowe
  `drawTrails`/`refreshTrails` USUNIĘTE razem z endpointem `/api/discovery/trails`:
  rysowały szlak ze środków pól siatki, czyli zygzakiem. **Klikalność wróciła bez
  wektora**: `map.on('click')` → `options.trailsHitEndpoint` → `dymekTras()` — jedno
  małe żądanie na kliknięcie zamiast geometrii przy każdym przesunięciu mapy.
  **`options.bounds`** (2026-08-19) = {south, west, north, east} — kadr startowy liczony
  na serwerze; nakładany `fitBounds`-em BEZ animacji, dopiero gdy kontener ma sensowny
  rozmiar, i wstrzymuje pierwsze żądanie do tego czasu (powody i pomiary →
  [`features.md`](features.md)). Bez niego mapa startuje w środku Polski.
  **`options.onTreasures(dane)` i `map.ridemoreFocusTreasure(id, lat, lon)`** (2026-08-20)
  obsługują LISTĘ SKARBÓW OBOK MAPY: callback dostaje to, co moduł przed chwilą narysował
  (`{clustered, treasures, clusters}`) — bez drugiego żądania o ten sam kadr — a `focus`
  przesuwa kadr i otwiera dymek wskazanego skarbu. **Pozycję podaje wołający**, bo jedyne
  legalne źródła to odpowiedzi API, które przeszły przez bramkę ujawnienia; moduł nie szuka
  skarbu po id i nie ma jak tej bramki obejść. Dymek otwiera się PO przerysowaniu warstwy
  i przez ok. 2,5 s jest ponawiany przy każdym kolejnym — `setView` potrafi wywołać dwa
  odświeżenia (osobno `zoomend`, osobno `moveend`), a każde czyści warstwę razem z dymkiem
  (zmierzone: jednorazowe otwarcie ginęło w ułamku sekundy).
  Przełączanie idzie przez `map.ridemoreSetLayer(nazwa, wł.)`, a stan warstw ląduje
  w adresie — i **kierunek zapisu idzie za stanem domyślnym**: `heat=1` włącza (domyślnie
  zgaszona), `trasy=0` i `skarby=0` wyłączają (domyślnie zapalone; trasy od 2026-08-19).
  Domyślny stan nie ma zaśmiecać linku. Globalne `window.ridemoreRefreshTreasures`
  pozwala odświeżyć warstwę po zaliczeniu skarbu bez przeładowania strony.

## Alpine.js — wzorce i pułapki

Używane w: `event-form-wizard.php`, `event-form.php`, `event-page.php`, `match-suggestions.php`.
- Komponent: `x-data="nazwa(initial, ...)"`; kroki kreatora to `x-show` (zostają w DOM).
- **Ustaw-potem-odczytaj stan reaktywny wymaga osobnych wywołań** (nie w jednym ticku).
- Preferuj `requestSubmit()` zamiast klikania referencji przy programowym submicie (pref. użytkownika).
- Pola sterowane kafelkami (tempo/trudność) muszą mieć **ukryty `<input :value=...>`** — sam
  stan Alpine się nie zapisze (był realny bug: kafle bez hidden inputu = brak zapisu).
- `x-cloak` chowa element do zamontowania Alpine (reguła w CSS jest, patrz wyżej).
- **Natywny `required`/`:required` w kreatorze (`event-form-wizard.php`) jest niebezpieczny**
  (był realny bug, zgłoszenie usera 2026-08-09): kroki kreatora zostają w DOM cały czas,
  chowane tylko przez `x-show` (display:none) na `<section class="k-step">` — pole `required`
  na kroku INNYM niż aktualny jest więc obecne w formularzu, ale niewidoczne. Klik "Opublikuj"
  odpala natywną walidację HTML5 przeglądarki na CAŁYM formularzu; przeglądarka blokuje submit,
  ale NIE POTRAFI pokazać dymka walidacji na niewidocznym polu — użytkownik dostaje ciszę,
  zero komunikatu, nie wie czego brakuje. Naprawione: żadne pole w krokach kreatora nie ma
  `required`/`:required` — cała walidacja idzie przez `validateBeforeSubmit()` w `script.php`
  (`jumpTo(step, msg)`: przenosi na właściwy krok + ustawia `submitError`, które pokazuje
  baner `<p class="form-error" x-show="submitError">` w `event-form-wizard.php`). Dodając nowe
  wymagane pole w kreatorze: dopisz sprawdzenie w `validateBeforeSubmit()`, NIE `required` na
  polu. (`event-form.php`, edycja pojedynczej strony bez kroków, tym NIE jest dotknięty —
  sekcje chowane tam przez `x-if`, więc wymagane pole nieaktualnego typu w ogóle nie istnieje
  w DOM, nie tylko jest niewidoczne.)
- **`initialError` (komunikat z serwera po nieudanym zapisie, patrz `EventController::create()`
  `$fail()`) musi trafić do `submitError`** w `eventWizard()` (`script.php`) — sam parametr bez
  przypisania `submitError: initialError || ''` ustawia tylko `currentStep`/`wizardStarted`
  (skacze na "podsumowanie"), ale baner zostaje pusty; był to ten sam objaw ciszy co wyżej,
  tylko dla walidacji, której nie złapał jeszcze `validateBeforeSubmit()` po stronie klienta.

## Mockupy — `szablony/*.html`

Statyczne prototypy będące źródłem designu (nie są renderowane przez apkę): `index-light.html`,
`wydarzenia.html`, `wydarzenie.html`, `dodaj-kreator.html`, `moje-konto.html`,
`profil-organizatora.html`, `organizator-v3.html`, `ridemore-*-prototype.html`. Przy odtwarzaniu
wyglądu strony porównuj z odpowiednim mockupem.

### Prawa kolumna strony wydarzenia — `.book-rail` (poprawka 2026-08-14)
`max-height` + `overflow-y:auto` + `scrollbar-gutter:stable` na słupku dawały **drugi pasek
przewijania przy cenie, terminach i „Kto jedzie"**. Miały być bezpiecznikiem na duży skład,
ale zmierzone na zwykłym wydarzeniu przy oknie 1280×720: treść 807 px, dostępna wysokość
594 px — bezpiecznik odpalał się w sytuacji NORMALNEJ, a `scrollbar-gutter:stable` rezerwował
tor paska na stałe (to jest cała jego funkcja).

**Zasada: sticky tylko wtedy, gdy element MIEŚCI SIĘ w oknie.** Przyklejony słupek wyższy od
viewportu ma dolną część trwale poza zasięgiem, bo przestaje jechać z treścią — a ratowanie
tego przewijaniem wewnętrznym kupuje drugi pasek na zawsze. Stąd
`@media (min-height:960px) and (min-width:981px)`: na niższym ekranie prawa kolumna jedzie
z resztą strony (jeden pasek), na wyższym się klei i wtedy i tak się mieści.
`.event-layout > aside{align-self:stretch}` zostaje — bez niego sticky nie ma po czym jechać.

### Dopasowania na `/wydarzenia` — `match-grid.php` (2026-08-14)
Widget dopasowań stał NAD całą wyszukiwarką, czyli **przed** pytaniem „czego szukasz" —
a to jest odpowiedź, nie wstęp (zgłoszenie usera). Przeniesiony na początek obszaru wyników
i przepisany na **siatkę równorzędnych kart** zamiast kontrolki ze strony głównej.

- **Osobny partial, nie reużycie `match-suggestions.php`.** Tamten robi „hero + boczna lista":
  najmocniejsze dopasowanie dostaje dużą kartę. Na stronie głównej to ma sens — dopasowania
  są tam jedyną treścią w tym miejscu. Na liście wyjazdów ta hierarchia biłaby się z siatką
  wyników pod spodem o to, co jest ważniejsze, a użytkownik przyszedł przeglądać, nie dostać
  jedną rekomendację. Stąd `auto-fit + minmax` — przy jednym dopasowaniu nie zostają dziury
  po sztywnych trzech kolumnach.
- **POZA `<main>`**, w `.results-column`, tuż nad nim — tak samo jak przełącznik karty/mapa
  i z tego samego powodu: filtrowanie robi `resultsMain.innerHTML = html`, czyli podmienia
  **całą** zawartość `<main>`, nie sam `#results-region`. Pierwsze podejście wstawiło sekcję
  do `<main>` i znikała po kliknięciu w „Blisko mnie" (zgłoszone przez usera). Dopasowania
  i tak nie zależą od filtrów — liczy je `MatchEngine` z profilu, nie z checkboxów; gdyby
  jechały z każdym odświeżeniem, mieliłyby silnik i zapisywały `RecommendationLog` raz za
  razem, zaśmiecając statystyki trafności.
- **Przełącznik „Pokaż dopasowania"** w panelu filtrów (grupa „Widok") — pokazuje się tylko
  wtedy, gdy są dopasowania. Świadomie **nie jedzie do serwera** jak reszta panelu i nie ląduje
  w adresie: to preferencja widoku tej osoby, a nie filtr wyników. Link podesłany komuś niósłby
  MOJĄ preferencję, a jego dopasowania i tak są inne. Wybór trzymany w `localStorage`.
- Limit dopasowań w kontrolerze podniesiony z 3 na **6** — rząd siatki mieści 4–5 kart przy
  `minmax(230px)`. Ile faktycznie wyjdzie, decyduje próg `MIN_MATCH_SCORE = 1.5`
  w `MatchEngine`, nie ten limit.
- Karta pokazuje **jeden powód**, w kolejności siły: peleton → geografia → uzasadnienie
  z profilu. Trzy powody na karcie w siatce to ściana tekstu, nie argument. Bez powodu karta
  po prostu go nie ma — „dopasowanie" bez uzasadnienia jest twierdzeniem bez pokrycia.
- Bez Alpine (odrzucanie „nie moje tempo", rozwijanie powodów) — te akcje należą do widgetu
  o JEDNYM dopasowaniu; tu każdy dodatkowy przycisk konkurowałby z listą wyników.

### Heksagon przy liczbach pól — `Icon::render('hex')` + `.hexn` (2026-08-14)
Decyzja usera: pola odkryć dostają **jeden znak w całym serwisie**. Do tej pory były wszędzie
samym słowem („373 pola", „nowe pola"), więc wzrokiem nie dawało się ich odróżnić od
kilometrów, punktów i miejsc w składzie.

- Ikona to heksagon **spiczasty u góry** — dokładnie ten kształt, który człowiek widzi na
  mapie (`DiscoveryGrid`, „pointy-top"). Znak ma być tą samą rzeczą co pole na mapie, a nie
  ogólną sześciokątną ozdobą.
- **Kontur, nie wypełnienie** (`fill:none; stroke:currentColor`). Na ciemnym pasku/karcie
  wychodzi biały, na jasnej karcie ciemny — zweryfikowane: `rgb(255,255,255)` na
  `.disc-social__num`, `rgb(21,32,26)` na `.disc-stat__val`. Wypełniony w jednym z tych
  dwóch miejsc byłby niewidoczny.
- `.hexn` skaluje ikonę do `1em`, więc ta sama klasa działa w kaflu z wielką liczbą (38 px)
  i w zdaniu w środku akapitu (27 px i mniej). Kolejność zawsze: **heksagon → liczba →
  reszta zdania**.
- Podpięte w: `/odkrycia` (Razem odkryliśmy, Twoje odkrycia, Nowy teren, karty tras, kafle
  przejazdów), strona główna (Razem odkryliśmy), profil rowerzysty (Odkryte pola, Nowy teren,
  karty tras), Moje przejazdy (kolumna Odkrycia), `/trasy` i strona trasy (postęp), Puls
  („odkryli N"), kronika (fakty o składzie i podsumowanie przejazdu), strona wydarzenia
  (szacunek „co mi to da" i podsumowanie po przejeździe).

### Zasada: `Utils\Icon`, nie emoji (migr. 057) + „liczba jako bohater"
Reguła szersza niż heksagon wyżej: **każda ikona w serwisie idzie przez
[`Utils\Icon::render()`](../core/Utils/Icon.php)** (zestaw Lucide, ISC, wklejony
raz do jednego pliku), nigdy przez surowe emoji. Powód udokumentowany w
nagłówku `Icon.php` — emoji renderuje się różnie na każdym systemie i nie da
się go pokolorować razem z tekstem (i przechodziło przez konsolę Windows jako
„krzaczki" w cp852, patrz migr. 057). Klasa bazowa `.icon{width:1em;height:1em}`
(`style.css` L.50) skaluje się do `font-size` rodzica — nowy kontekst NIE
potrzebuje własnej reguły rozmiaru, wystarczy owinąć w element z ustawionym
`font-size` (wzorzec: `.app-nav__ico{font-size:19px}`, `.app-map__stat .icon`).

Audyt UX apki (2026-08-28, §13) znalazł i naprawił dwie regresje wobec tej
reguły sprzed tej sesji (📷 w `treasure-scan.php`, 📍 w `discovery-app.php`)
oraz przepisał **pasek nawigacji apki** (`app-nav.php`) — jedyne miejsce,
które nigdy nie przeszło migracji 057, bo powstało później (Etap 1, już po
057): sloty „Wyjazdy"/„Zgłoś"/„Profil" dostały `calendar`/`pin`/nowy klucz
`user` (pojedyncza sylwetka, `users` to dwie — profil to „Ty", nie skład).
**Świadomie NIE ruszone**: 🗺️ na wyniesionym kole „Mapa" (jedyny wizualnie
odróżniony slot paska — potraktowany jak wyjątek, nie przeoczenie) i `⌗` na
„Skanuj" (to nie emoji, tylko znak techniczny, poza zakresem tej reguły).

**„Liczba jako bohater"** — drugi, osobny wzorzec wizualny (nie mylić z
regułą ikon wyżej): duża liczba (`font-family:'Big Shoulders Display'`,
`clamp(56px,18vw,110px)`, kolor `--accent`) jako pierwsza rzecz na ekranie,
reszta treści pod spodem. Trzy niezależne wdrożenia tego samego kroju:
widget dopasowania (`match-grid.php`, 2026-08-14), ekran skarbu (`.ts`,
`treasure-scan.php`, SKA/5) i ekran wyniku jazdy w apce (`.rs`,
`ride-summary-app.php`, §11 audytu UX 2026-08-28 — WYŁĄCZNIE `APP_IS_APP`,
`SoloRideController::summary()`, dane przez sesję jednorazową z `upload()`).
`.rs` to CELOWO osobny prefiks od `.ts` — ta sama sylwetka CSS, ale inny
właściciel danych (`RiderActivity::record`, nie `Treasure::award`); kopiowanie
klasy między nimi byłoby myleniem właściciela, nie oszczędnością.
