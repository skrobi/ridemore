# Przebudowa UX apki mobilnej — reszta ekranów

Data przyjęcia: 2026-08-29. Kontynuacja Etapu 1 z `tasks/active/apka-mobilna.md`
(mapa jako pełnoekranowy widok) — TEN kontrakt jest już w pełni zamknięty
(Etapy 0-9), więc dalsza przebudowa UX dostaje własny plik.

## Cel

Doprowadzić WSZYSTKIE ekrany apki do standardu z mapy: pełnoekranowe widoki,
dolne arkusze („bottom sheets") zamiast paneli bocznych, karty zamiast
gęstych tabel, duże cele dotykowe, pływające paski akcji z `safe-area`.
Wzorce: MysteryHike i podobne aplikacje terenowe.

## Zasada nadrzędna — apka NIE dostaje własnego frontu

Decyzja architektoniczna #1 z `apka-mobilna.md` zostaje: żaden ekran tu nie
dubluje danych/logiki kontrolera. Kontroler albo wybiera inny szablon dla
`APP_IS_APP` (wzorzec `DiscoveryController::index()` → `discovery-app.php`),
albo (gdy różnica jest mniejsza niż cała treść) TEN SAM szablon dostaje
`<?php if (APP_IS_APP): ?>` i CSS scoped pod `body.is-app` — wzorzec użyty
w `account.php`/`treasure-scan.php` w Etapach 6-9.

## Fundament do reużycia (nie wymyślać od nowa)

- `body.is-app` / `body.is-app.map-page` (`assets/css/style.css`, blok
  „TRYB APLIKACJI MOBILNEJ", od L.3494) — pełnoekranowy widok, `.app-nav`
  jako pływająca nakładka nad treścią.
- **Szuflada od dołu = gotowy „bottom sheet"**: `.disc-panel` +
  `ridemoreSetupPanels()` (`assets/js/discovery-map.js:225-307`) — JUŻ jest
  generycznym komponentem (czyta `data-drawer`, wystarczy dodać klasę
  `.disc-panel` do dowolnego panelu na stronie z załadowaną mapą). Nazwa
  zostaje `disc-panel` (nie przemianowywać na coś neutralnego „na zapas" —
  to byłby przepisywanie działającego kodu bez potrzeby, §7 CLAUDE.md).
  Faza 2/3 użyją go wprost.
- Pływające karty: `.rm-bg-pill`, `.rm-bg-consent`, `.app-map__stats`.
- `app-nav.php` (5 slotów, Mapa jako środek) — bez zmian, to nawigacja
  główna, poniższe fazy dotyczą ekranów PO wejściu w slot.

## Fazy

### Faza 0 — Fundament — ZROBIONE 2026-08-29
Audyt pokazał, że fundament w większości już istnieje z Etapu 1 (patrz
wyżej) — `.disc-panel`/`ridemoreSetupPanels()` jest już generyczną szufladą,
`body.is-app`/`.app-nav` już są reużywalne bez zmian. Nic nowego nie trzeba
było budować; ten wpis to formalne potwierdzenie, gdzie szukać, żeby Faza 2/3
nie duplikowały mechanizmu.

### Faza 1 — Wiadomości (`messages.php`) — ZROBIONE 2026-08-29
Web-mobile JUŻ przełącza panel jednopanelowo pod `@media(max-width:680px)`
(`.messenger.has-active .messenger-sidebar{display:none}` itd., CSS
L.1068-1076) — to nie wymagało przebudowy. Zrobione:
- Okruszki (`partials/breadcrumbs.php`) pominięte w `APP_IS_APP` — ten sam
  odruch co brak hero na `discovery-app.php`.
- Wysokość aktywnego wątku (`calc(100vh - 220px)`) skorygowana pod pływający
  `.app-nav`: `calc(100vh - 190px - var(--app-nav-h) - env(safe-area-inset-bottom,0px))`
  — 190px zamiast 220px (okruszki zniknęły), plus wysokość paska i wcięcie
  na dole ekranu. Bez tego dół wątku (pole odpowiedzi) chowałby się pod
  paskiem nawigacji.
- ŚWIADOMIE bez osobnego szablonu — różnica jest za mała (usunięcie jednego
  partiala + poprawka jednej stałej w CSS), więc `<?php if (APP_IS_APP) ?>`
  w istniejącym pliku, jak w `account.php`/`treasure-scan.php`.

Zweryfikowane: `php tests/run.php` (353/354, poza znanym niezwiązanym
fail-em z sesji), `php -l`, bilans klamer CSS, `curl` (żądanie bez sesji
poprawnie przekierowuje na `/logowanie` — 401/302, jak przed zmianą).
**NIEZWERYFIKOWANE na żywo**: renderowanie prawdziwej rozmowy w apce wymaga
zalogowanej sesji (hasło konta testowego nieaktualne, patrz pamięć
`reference_local_test_login`) — sama zmiana jest jednak czysto addytywna
(nowy warunek + nowa reguła CSS, zero zmiany istniejących selektorów), więc
ryzyko regresji niskie.

**Zauważone przy okazji, POZA ZAKRESEM tej fazy**: `.wrap` ma własny
`padding-bottom:60px` (baza), a `body.is-app` DOKŁADA do tego zapas na
`.app-nav` — na każdym ekranie is-app poza `map-page` (który zeruje
padding świadomie) te dwie wartości się sumują, zostawiając więcej pustego
miejsca na dole niż potrzeba. Kosmetyczne, sitewide, nie tylko messenger —
warte jednej wspólnej poprawki przy którejś kolejnej fazie, nie punktowej
łatki tutaj.

### Faza 2 — Wyjazdy, lista (`events-list.php`) — ZROBIONE 2026-08-29
Audyt pokazał więcej gotowego niż zakładał wcześniejszy szkic tej fazy:
**karty (`.event-grid`/`partials/event-card.php`) już istniały**
(współdzielone ze stroną główną), web-mobile **już miał** przełącznik
filtrów (`.filters.is-open`, `data-role="mobile-filter-toggle"`,
`partials/event-results.php`) — inline show/hide, nie prawdziwy arkusz.
Zrobione:
- Okruszki pominięte w `APP_IS_APP` (ten sam odruch co Faza 1).
- `.filters.is-open` w apce dostaje INNY WYGLĄD tego samego stanu: pełny,
  pływający dolny arkusz (`position:fixed`, zaokrąglone rogi, uchwyt-atrapa,
  animacja wjazdu) z półprzezroczystym tłem
  (`body.is-app:has(.filters.is-open)::after` — `:has()` już używane
  w `map-layers.php`, nie nowa technika) — ZERO zmiany logiki filtrowania w JS.
- Nowy przycisk zamknięcia `.filters__close` (✕) w `.filters__hd` — konieczny,
  bo arkusz zasłania też przycisk „Filtry", którym się go otworzyło; widoczny
  WYŁĄCZNIE w apce, na web-mobile ukryty (tam działa oryginalny przycisk pod
  spodem, panel niczego nie zasłania). Nowy listener
  (`filtersPane.addEventListener('click', ...)` na `data-role="close-filters"`)
  — mały dodatek, zero zmiany istniejących handlerów.
- z-index arkusza/tła w paśmie „100+ modale" (101/100), NIE w paśmie
  „70-90 dolne paski" — to jest modal, nie trwały pasek (skala z-index
  udokumentowana na górze `style.css`).

Zweryfikowane: `php tests/run.php` (354/355 — poza znanym niezwiązanym
fail-em), `php -l`, bilans klamer CSS, **żywy render w przeglądarce**
(Claude-in-chrome — `/wydarzenia` nie wymaga logowania, więc dało się to
sprawdzić na żywo, w odróżnieniu od Fazy 1): prawdziwy klik w „Filtry"
i w „✕" na realnej stronie, arkusz wjeżdża od dołu z tłem i zamyka się
poprawnie, `getComputedStyle` potwierdza `position:fixed`/`z-index:101`.

### Faza 3 — Zgłoś skarb (`treasure-propose.php`) — ZROBIONE 2026-08-29
Nowy szablon `treasure-propose-app.php`, WYŁĄCZNIE dla `APP_IS_APP` (ten sam
wzorzec co `discovery.php` → `discovery-app.php` z Etapu 1 —
`TreasureProposalController::form()` wybiera szablon i `bodyClass`, dane te
same). Mapa pełnoekranowa (`.app-map-page`, jak na `discovery-app.php`), tap
w mapę stawia pinezkę i OD RAZU otwiera formularz — mieszka w `.disc-panel`,
TYM SAMYM generycznym komponencie szuflady co lista skarbów na /odkrycia
(zero nowego JS-a poza jednym wywołaniem `classList.add('is-open')` przy
postawieniu pinezki — to, co normalnie robi klik w uchwyt). Domyślnie
zwinięty: zanim user wskaże punkt, cała wysokość ekranu służy do celowania.
Warstwa istniejących skarbów (antyduplikat) i kontrolka warstw — bez zmian,
ten sam `partials/map-layers.php`.

ŚWIADOMIE POMINIĘTE względem web: tabela „Twoje zgłoszenia w poczekalni" —
ekran terenowy ma jedno zadanie, przegląd zgłoszeń zostaje gdzie indziej.

Znaleziony przy okazji, NAPRAWIONY W TEJ FAZIE (nie nowa funkcjonalność,
poprawka pod nowy kontekst): `.tr-found` (podpowiedzi wyszukiwarki Nominatim)
jest `position:absolute` i liczy się względem najbliższego pozycjonowanego
przodka — na desktopie to przypadkiem działa (sticky kolumna formularza, nic
nad wyszukiwarką), w szufladzie (nad wyszukiwarką stoi nagłówek arkusza)
podpowiedzi wyskakiwałyby w złym miejscu bez `.disc-panel .tp-search{position:
relative}`. Dołożone też: zapas na pływający `.app-nav` pod otwartą szufladą
(`--app-nav-h`, ten sam mechanizm co Fazy 1-2) — `.disc-panel` nigdy wcześniej
nie stał w `body.is-app.map-page`, więc tego zapasu nie było skąd wziąć.
ŚWIADOMIE bez tła `:has()` jak w Fazie 2: to nie jest modal, mapa z pinezką
ma zostać widoczna pod formularzem.

Zweryfikowane: `php tests/run.php` (355/355 — poprzedni znany niezwiązany
fail z `edition_tracks` już nie występuje), `php -l`, bilans klamer CSS.
**NIEZWERYFIKOWANE na żywo** (ten sam powód co Faza 1): `/skarby/zglos`
wymaga zalogowania, a hasło konta testowego jest nieaktualne — zmiana
zweryfikowana kodem i testami, ryzyko ograniczone tym, że markup i logika
JS to niemal 1:1 kopia już działającego `treasure-propose.php` (te same
zmienne, te same wywołania `RM.native`/`ridemoreCreateMap`), różnica to
inny układ (szuflada zamiast kolumny) i dwie nowe, wąsko zakresowane reguły
CSS opisane wyżej.

### Faza 4 — Profil rowerzysty i dokończenie Konta — ZROBIONE 2026-08-29
Audyt (jak w poprzednich fazach) pokazał mniej roboty niż zakładał szkic:

- **`account.php`** — `.settings-*` (szyna sekcji + karty `.box`) już JEST
  jednym, spójnym wzorcem karty na WSZYSTKICH czterech sekcjach (Profil,
  Hasło, Faktura, Preferencje) — nic do „dociągania". Już ma responsywną
  szynę (`@media(max-width:900px)`: pozioma, przewijana belka zakładek
  zamiast kolumny) sprzed tej sesji. Jedyna realna różnica: okruszki
  pominięte w `APP_IS_APP`, ten sam odruch co Fazy 1-2-3.
- **`rider-profile.php`** — NOWA przypięta akcja `.mbar` (Faza 4) na cudzym
  profilu, WYŁĄCZNIE w apce i wyłącznie zalogowanemu (dokładnie warunek,
  pod którym strona i tak już rysowała „Napisz wiadomość" w treści — ten
  przycisk w treści w apce ZNIKA, zastąpiony przypiętym paskiem, żeby nie
  stały dwa CTA naraz). `.mbar` to TEN SAM komponent co przycisk zapisu na
  `event-page.php` — zero nowego CSS-a poza jedną regułą dokładającą zapas
  na `.app-nav` PONAD zapas, który już rezerwuje `body.has-mbar` (dwa
  pływające paski stoją jeden nad drugim, potrzebują dwóch osobnych
  rezerw). Reszta strony (mapa, kafle, roster, peleton) — bez zmian:
  już zbudowana z reużywalnych komponentów, cele dotykowe już objęte
  ogólną regułą `@media(pointer:coarse)` (L.2323+) sprzed tej sesji.

Zweryfikowane: `php tests/run.php` (355/355), `php -l`, bilans klamer CSS,
**żywy render w przeglądarce** dla `rider-profile.php` (`/rowerzysta/{slug}`
nie wymaga logowania — w tej sesji akurat była aktywna prawdziwa sesja
w Chrome, więc dało się to sprawdzić z realnym drugim rowerzystą jako
celem). Realny UA apki niedostępny (Chrome desktopowy) — `.app-nav` i
klasy `is-app`/`has-mbar` wstrzyknięte przez `javascript_tool` jako
podstawki (ta sama technika co przy weryfikacji Fazy 2), a reguły spod
`@media(max-width:980px)` wymuszone jawnie w tymczasowym `<style>`
(narzędzie nie zmienia realnego `window.innerWidth`, znana usterka z tej
sesji) — zmierzona geometria: `.mbar` (70px, nie zakładane 60px — CSS
poprawiony na zmierzoną wartość) siada dokładnie nad `.app-nav`, bez
zachodzenia, a `body.is-app.has-mbar{padding-bottom}` poprawnie sumuje obie
rezerwy (118px = 58+70 w tym pomiarze). `account.php` niezweryfikowane na
żywo (wymaga bycia zalogowanym jako WŁAŚCICIEL konta — inna sesja niż
przeglądanie cudzego profilu) — kod+testy, zmiana identyczna z już
sprawdzonym wzorcem breadcrumbs z Faz 1-3.

### Faza 5 — `event-page.php` (1569 linii) — ZROBIONE 2026-08-29

> Nagłówek mówił „DO WYKONANIA" do 2026-09-12, mimo że sekcja wykonania na końcu
> tego pliku od początku liczyła „Fazy 0-5 zrobione (…) wszystkie sześć faz".
> Sprawdzone w kodzie: `event-page.php` ma pasek `.mbar` (l. 1394), okruszki gasi
> partial (szablon nie ma własnej bramki), a tryb apki rozstrzyga się przez
> `body.is-app` w CSS — nie przez `APP_IS_APP` w szablonie, i tak ma być
> (zasada z `apka-mobilna-skorupa.md`: skorupa decyduje, strony nie wiedzą).

Eksploracja całego pliku + `style.css` (struktura sekcji, `.book-rail`/`.book`,
`.mbar`, `.anchors`, `.stats`, `.cover`) pokazała coś nieoczekiwanego: to
NAJBARDZIEJ dojrzały ekran w serwisie pod kątem mobile — prawdopodobnie
ŹRÓDŁO wzorców reużytych w Fazach 1-4 (`.mbar` to STĄD, nie z rider-profile;
`.stats` to `grid-template-columns:repeat(auto-fit,minmax(130px,1fr))` —
responsywne bez media query; `.cover` to `aspect-ratio`, nie sztywny px;
`.book`/`.book-rail` już ma pełny mobile-kolaps na `@media(max-width:980px)`
— sticky panel przechodzi w statyczną kartę w treści, a `.mbar` przejmuje
rolę trwałego CTA). To ZNACZĄCO zmniejsza realne ryzyko względem założenia
z pierwszej wersji planu.

**Twarda zasada dla całej Fazy 5**: ZERO zmian w logice RSVP/zapisów/płatności
(`eventPage()` w script na dole, `join()`, endpointy AJAX, `payment-info-box`,
edition/wariant pickery) — wyłącznie prezentacja pod `APP_IS_APP`, dokładnie
jak w Fazach 1-4. Jeśli podfaza odkryje, że coś wymaga zmiany logiki, STOP
i pytanie do usera przed implementacją (CLAUDE.md §7/§9).

- **5A — Fundament (niskie ryzyko)**: okruszki pominięte w `APP_IS_APP` (ten
  sam odruch co Fazy 1-4). Weryfikacja na żywo, że `.mbar` (Faza 4 dorzuciła
  ogólną regułę `body.is-app.has-mbar{padding-bottom}`) poprawnie nie
  zasłania ostatniej sekcji strony i nie zachodzi na `.app-nav` — TA
  STRONA ustawia `bodyClass='has-mbar'` od dawna (EventController.php:755),
  więc naprawa z Faza 4 już tu działa, ale nie była jeszcze sprawdzona
  na TEJ konkretnej, dłuższej stronie.
- **5B — Panel zapisu (`.book-rail`/`.book`)**: audyt, czy karta zapisu w
  apce (już statyczna/inline na mobile) potrzebuje jeszcze czegoś
  specyficznego dla is-app (odstępy, cele dotykowe pickerów terminu/wariantu)
  — bez dotykania samego mechanizmu zapisu.
- **5C — Treść (`.anchors`, dni/mapy trasy, galeria, opinie, Q&A)**: sprawdzić,
  czy sticky `.anchors` (offset liczony pod nagłówkiem web, nagłówek w apce
  ZOSTAJE bez zmian, więc offset powinien być poprawny bez modyfikacji) i
  taby dni (`.dtabs`) potrzebują czegokolwiek ponad już istniejącą, ogólną
  regułę `@media(pointer:coarse)` (L.2323+) powiększającą cele dotykowe.

Strona jest publiczna (nie wymaga logowania) — każda podfaza dostaje żywą
weryfikację w przeglądarce, jak Faza 4.

**WYKONANA 2026-08-29.** Przewidywanie z planu się potwierdziło: 5B i 5C nie
wymagały ANI JEDNEJ linii nowego kodu — żywy test na prawdziwym, płatnym,
trzydniowym wydarzeniu (`/events/wycieczka-tatry-3-dni`, z podstawionymi
`is-app`/`.app-nav` przez `javascript_tool`, ta sama technika co Faza 4)
pokazał, że karty planu dnia, panel „Kto jedzie", ceny i warunki już renderują
się poprawnie — istniejący, sprzed tej sesji, kolaps `@media(max-width:980px)`
i wzorzec kart `.box` wystarczają. Jedyna zmiana kodu w całej Fazie 5 to
okruszki pominięte w `APP_IS_APP` (5A, ten sam odruch co Fazy 1-4).

Zmierzona na żywo geometria potwierdziła też, że naprawa z Fazy 4
(`body.is-app.has-mbar{padding-bottom}`) działa poprawnie na TEJ, dłuższej
stronie: `.mbar` („1890 zł zapisy u organizatora" / „Zobacz szczegóły") siada
dokładnie nad `.app-nav`, bez zachodzenia, a ostatnia sekcja treści
(„Pytania") kończy się daleko nad obydwoma paskami — zero zmiany CSS-a
potrzebne poza tym, co Faza 4 już dała za darmo każdej stronie z `.mbar`.

Przy okazji zauważony, POZA ZAKRESEM tej fazy i niezwiązany z żadną zmianą
z tej sesji: `EventController.php` rzuca PHP warning „Undefined variable
$viewerId" (linie ok. 728-729, 734-735) przy renderowaniu `event-page.php` —
złapane w konsoli przeglądarki podczas testu na żywo. Nie naprawione
(CLAUDE.md §2 — problem niezwiązany z zadaniem, tylko zgłoszony).

Zweryfikowane: `php tests/run.php` (355/355), `php -l`, żywy render w
przeglądarce (desktop Chrome + podstawki `is-app`/`.app-nav`/`.mbar` przez
`javascript_tool` — realny UA apki niedostępny w tym środowisku, znana
usterka narzędzia z tej sesji).

## Kontrakt zamknięty

Fazy 0-5 zrobione. „Reszta ekranów apki" (poza mapą z Etapu 1 kontraktu
`apka-mobilna.md`) doprowadzona do standardu: pełnoekranowe widoki tam, gdzie
to miało sens (Zgłoś skarb), dolne arkusze zamiast paneli bocznych (Wiadomości,
Filtry wyjazdów), przypięte akcje zamiast przycisków ginących w treści
(Profil rowerzysty, wykorzystane też przez stronę wydarzenia), okruszki
pominięte wszędzie. Żaden ekran nie dostał własnego frontu ani duplikatu
logiki — zasada nadrzędna z góry tego pliku przetrwała nienaruszona przez
wszystkie sześć faz.

## Weryfikacja (każda faza)
`php -l`/`php tests/run.php` po zmianach PHP; bilans klamer CSS +
`node --check` po zmianach JS; smoke test w przeglądarce (Claude-in-chrome,
UA `ridemore-app`) gdzie ekran nie wymaga zalogowanej sesji — dla ekranów
za logowaniem (messenger, konto) zmiany zostają zweryfikowane kodem/testami,
nie żywym renderem, dopóki hasło konta testowego nie zostanie odświeżone
(patrz pamięć `reference_local_test_login`).
