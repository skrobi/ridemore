# Funkcje end-to-end (feature → pliki)

Dla każdej większej funkcji: które pliki ją realizują, przez wszystkie warstwy. Sprzężone
z pamięcią asystenta (`memory/project_ridemore_*`) — tam są daty wdrożeń i świadome „jeszcze
nie zrobione". Tu jest mapa kodu.

## Turnusy / wiele terminów (event_editions)
Model 1-event-wiele-terminów. Status zapisu jest **per turnus**, nie per event.
- Migr. `023`; model [`EventEdition`](../core/Models/EventEdition.php); wybór turnusu
  `Support::resolveEdition`; `EventRsvp` operuje na `editionId`.
- Widok: „Wybierz termin" w `event-page.php` (Alpine); linki `?termin=`/`edition_id`.
- Pamięć: `project_ridemore_turnusy`.

## „Pokręcę z kimś" (3. typ eventu)
Nieformalne wspólne jazdy. `eventTypeCode = 'pokrec_z_kims'`.
- Migr. `024`; `Event::validatePokrecZKims`; osobny ekran potwierdzenia; karta/etykieta w CSS.
- Pamięć: `project_ridemore_pokrec_z_kims`.

## „Wyścig" (4. typ eventu)
Formularz jednodniowy CELOWO identyczny jak `ustawka` (bez własnej walidacji
w `Event::save()`, bez osobnego ekranu potwierdzenia jak `pokrec_z_kims`) —
jedyna różnica to kod/etykieta/kolor. `eventTypeCode = 'wyscig'`, kolor
`--s-purple` (`#6D4AA6`, assets/css/style.css `:root`). Migr. `033` (sam
wpis w słowniku `event_type`, sort_order=4).
- **Whitelist typu** (miejsce najłatwiejsze do zapomnienia przy kolejnym
  typie): `Resources\EventFormInput::fromRequest()` i
  `Resources\EventFormResource::fromPost()` mają identyczną tablicę
  `in_array($post['type'] ?? '', [...], true)` — brak kodu na tej liście =
  cichy fallback na `'ustawka'` (submit zapisuje się, ale jako zły typ).
- **Wizard** (`views/web/partials/wizard/script.php`): `TYPE_META`/
  `STEP_MIN`/`visibleSteps()` (krok `trasa`, dzielony z `ustawka` —
  tu żyją warianty dystansu, patrz `EventRouteVariant`) —
  `type-picker.php` (4. karta) i `step-podsumowanie.php` (wiersz "Dystans").
- **Edycja** (`event-form.php`, duplikat kreatora — nie współdzieli kodu):
  osobny `type-toggle` + osobne warunki `type==='ustawka'` w sekcji
  "Plan wyjazdu"/warianty — trzeba było dopisać `|| type==='wyscig'` w OBU
  miejscach (kreator i edycja), inaczej działałoby tylko dodawanie, nie edycja.
- **Etykieta/kolor na stronie eventu** (`event-page.php`) liczona INACZEJ niż
  reszta serwisu: nie po `eventTypeCode` wprost, tylko `isLooseRide` (pokrec_z_kims)
  + `isMultiday` (liczba etapów > 1) jako heurystyka dla pozostałych dwóch —
  `wyscig` musiał dostać własny, jawny warunek PRZED tą heurystyką (inaczej
  jednodniowy wyścig wypadłby jako "Ustawka społecznościowa"). Reszta miejsc
  (`home-event-card.php`, `organizer-profile.php` `$FORMAT_META`,
  `events-list.php` `$eventTypeColor`) klucz po `eventTypeCode` wprost —
  tam wystarczył dopisany wpis/`match`-arm.
- Filtr na `/wydarzenia` (checkbox "Format") jest generowany z
  `Dictionary::items('event_type')` — nowy typ pojawia się tam automatycznie
  po samym dodaniu wiersza słownika, bez zmian w widoku.
- Strona główna: 4. karta w sekcji "Cztery sposoby, żeby pojechać razem"
  (`home.php` + `HomeController::$formatCounts`), link w stopce.

## „Kto jedzie" — skład turnusu na stronie wydarzenia (Etap 1 kierunku Peleton)
**PRZENIESIONY DO PRAWEJ KOLUMNY, pod panel zapisu (2026-08-12, decyzja usera:
„będzie miało to lepszy kontekst myślowy").** Wcześniej był PIERWSZĄ sekcją w `<main>`.
Racja jest po stronie usera: skład to nie opis wydarzenia, tylko argument w decyzji
„zapisać się czy nie" — a ta zapada przy przycisku, nie w treści.
- Wydzielony do [`partials/event-roster.php`](../views/web/partials/event-roster.php)
  (zwykły `require`, korzysta z domknięć z góry `event-page.php` — ten sam wzorzec co
  `interest-toggle.php`).
- `.book__who` (3 awatary + „N osób już jedzie") **usunięte z panelu zapisu** — tuż nad
  pełnym składem mówiło to samo, dwa razy pod rząd, uboższymi środkami.
- „Kto jedzie" **wypadło z kotwic** (`$anchors`): kotwice opisują sekcje ARTYKUŁU, a na
  desktopie skład jest widoczny cały czas. Sam identyfikator `#kto-jedzie` zostaje, bo
  linkują w niego maile i Puls.
- **Znalezione przy okazji: `position:sticky` na `.book` nigdy nie działało.**
  `.event-layout{align-items:start}` ścieśnia prawą kolumnę do wysokości treści, a element
  sticky klei się wyłącznie w obrębie rodzica — kolumna wysoka na 586 px przy treści na
  2400 px nie dawała ani piksela zapasu. Naprawione przez `align-self:stretch` na `aside`.
  Panel i skład są teraz jednym słupkiem `.book-rail` (sticky przeniesione tam z `.book`,
  inaczej skład chowałby się pod panelem) z `max-height` + przewijaniem wewnętrznym jako
  bezpiecznikiem: przy dużym składzie słupek wyższy od ekranu przestałby się kleić i dolna
  część panelu — z przyciskiem zapisu — stałaby się nieosiągalna.
- **Skutek na wąskim ekranie**: układ jest wtedy jednokolumnowy, a `<aside>` idzie po
  `<main>`, więc skład ląduje na samym dole strony (zmierzone: y≈4525 na stronie wysokiej
  na 5767 px). Panel zapisu był tam od zawsze i dlatego istnieje `.mbar`, ale skład
  wcześniej był u góry — jeśli to przeszkadza, jedyna zmiana to `order` na `aside` poniżej
  980 px, co wyniesie cały panel nad treść.
- Model: [`EventRsvp::rosterForEdition()`](../core/Models/EventRsvp.php) — JEDNO zapytanie
  zwraca `['confirmed' => [], 'interested' => []]` (statusy `potwierdzony`/`zainteresowany`).
  Kształt wiersza celowo identyczny z `confirmedParticipantsForEdition()`, więc istniejący
  blok awatarów w panelu zapisu (`.book__who`) zasilany jest z `$roster['confirmed']` bez
  zmian w widoku. `oczekuje_platnosci`/`lista_rezerwowa` NIE wchodzą do żadnej grupy.
- Kontroler: `EventController::show` — ta sama bramka statusu co dotąd (`published`/`full`),
  przekazuje `$roster` obok istniejącego `$confirmedParticipants`.
- Widok: `event-page.php` — sekcja + kotwica `Kto jedzie` (obie tylko gdy `$isBookable`;
  przy zakończonym/odwołanym czas przyszły byłby fałszem — skład odbytego wyjazdu pokaże
  kronika). Domknięcia `$rosterName`/`$rosterInitials`/`$rosterPlural`/`$rosterSentence`
  u góry pliku.
- **Prywatność**: awatary z inicjałami + liczba widoczne dla WSZYSTKICH (tak działał
  `.book__who` od zawsze), ale IMIONA tylko dla zalogowanych (`$rosterShowNames`), i tylko
  skrócone („Michał W."), nigdy pełne nazwisko. Gość dostaje wartość (ilu jedzie) bez
  listy nazwisk dla robotów.
- CSS: `.roster`, `.roster__names`, `.roster__grp`, `.roster__lbl` (style.css, przy `.lead`).
  Awatary to ISTNIEJĄCA `.avs` z panelu zapisu, tylko powiększona do 34px w tym kontekście.

## Peleton jako sygnał dopasowania (2026-08-12)
Ludzie, z którymi widz FAKTYCZNIE jechał, wpływają teraz na ranking podpowiedzi —
na stronie wydarzenia i na stronie głównej.
- Dwa fakty per kandydat, liczone podzapytaniami: **ilu ludzi z peletonu widza jest
  zapisanych** na ten turnus i **czy prowadzi go ktoś z peletonu** (`events.organizer_id`
  to user id). Dokładane w **DWÓCH miejscach** — `candidatePool()` (dopasowania względem
  wydarzenia) ORAZ w osobnym zapytaniu `profileMatchesForUser()` (strona główna,
  powiadomienia). Pominięcie drugiego = peleton działa na evencie, ale nie na głównej.
- Wagi: `PELOTON_WEIGHT = 2.5` (między nakładaniem tras 3.0 a geografią 2.0 — trasa mówi
  „czy wyjazd jest do mnie podobny", peleton „czy będę wśród swoich"),
  `PELOTON_SATURATION = 3` (różnica nikt→jeden jest ogromna, trzy→pięć żadna),
  `PELOTON_ORGANIZER_BONUS = 1.2`. Wspólny `pelotonScore()` dla obu ścieżek, żeby wagi
  się nie rozjechały.
- **Peleton potrafi sam wypchnąć wyjazd nad próg jakości** — zmierzone: „Wielka pętla"
  bez peletonu 2,13 (poniżej progu 3.0, niewidoczna), z dwiema znajomymi osobami 3,80.
  To jest cały sens zmiany.
- Imiona (`RiderConnection::namesOnEditions`) dokładane przez `attachPelotonNames()`
  **dopiero do finalnych kart**, nie w pętli oceniania — ranking potrzebuje liczby,
  imion potrzebuje tylko 3-5 kart, które user zobaczy.
- Karta: `MatchCardResource::pelotonLabel()` → „Jedzie Marek K. i Ola N. z Twojego
  peletonu." / „Prowadzi ktoś, z kim już jeździłeś." Renderowane w
  `match-suggestions.php` **NAD** uzasadnieniem profilowym. Gdy peleton jest, ale
  wszyscy ukryci (migr. 037) — komunikat ogólny zamiast milczenia.
- Gość: fragmenty SQL w ogóle nie wchodzą do zapytania, zachowanie bez zmian.
- **Pułapka przy edycji tych zapytań**: SQL siedzi w stringu PHP w podwójnych
  cudzysłowach — znak `"` w komentarzu `--` zamyka string i wywala parser (zdarzyło
  się 2× przy pisaniu tego).

## Discovery Grid — `/odkrycia` i `/odkrycia/spolecznosc` (Etap 8)
Warstwa odkrywania: heksagonalne „pola", które rowerzysta zdobywa jeżdżąc, mapa osobista
i wspólna mapa społeczności, znane trasy. Migr. `040`.

**Kluczowe ustalenie, od którego zależy cały kształt modułu: serwis NIE MA śladów GPS
użytkowników.** Każdy plik GPX w bazie należał do WYDARZENIA
(`event_stages`/`event_route_variants`) i był trasą ZAPLANOWANĄ przez organizatora; kolumny
`event_attendance.gps_verified` / `tracked_distance_km` / `source_item_id` (słownik
`attendance_source`) stoją puste od pierwszego dnia; Strava jest wyłączona, prosi tylko
o scope `read`, a `user_oauth_identities` w ogóle nie trzyma tokenów.

**CO LICZY SIĘ JAKO PRZEJAZD — reguła zaostrzona migracją `042` (decyzja usera).**
Potwierdzona obecność **plus ślad z tego, co faktycznie się wydarzyło** (`edition_tracks`):
własny ślad uczestnika, a gdy go nie ma — ślad z imprezy wgrany przez organizatora po
wyjeździe. **Trasa planowana nie liczy się w ogóle.**
- Powód: zapowiedź nie jest dowodem. Na trasie planowanej ten, kto skrócił, zawrócił albo
  pojechał zupełnie inaczej, odkrywał dokładnie tyle samo, co ten, kto przejechał wszystko.
  Pierwsza wersja liczyła właśnie z niej — to był opisany, znany kompromis.
- **Ślad własny ma pierwszeństwo** przed zbiorowym. Gdyby liczyły się oba, ktoś, kto
  uczciwie wgrał swój krótszy ślad, i tak dostałby całą trasę ze śladu organizatora —
  czyli wracałby problem, który ta zmiana rozwiązuje. Zweryfikowane na żywo: uczestnik,
  który wgrał ślad 32,9 km, dostał 70 pól, gdy reszta składu miała 373 ze śladu zbiorowego.
- Ślad rzeczywisty wisi na TURNUSIE, nie na wydarzeniu: przebieg różni się między
  terminami (objazd, skrócenie z powodu pogody), inaczej niż trasa planowana.
- **Skutek uboczny, świadomie zaakceptowany**: turnus bez wgranego śladu nie daje nikomu
  ani jednego pola, choćby wszyscy potwierdzili obecność. Po wdrożeniu migracji mapa jest
  pusta, dopóki nie pojawią się pierwsze ślady. Lepiej mieć mapę mniejszą i prawdziwą.
- Wgranie/usunięcie śladu przelicza odkrycia NATYCHMIAST (`EditionTrack::attach/remove` →
  `RiderActivity::resyncForEdition`) — przeliczanie wisi w modelu, nie w kontrolerze, bo
  ślad wgrany po tym, jak ludzie potwierdzili obecność, to normalna kolejność zdarzeń.
  Zawsze przez usunięcie i policzenie od nowa, nigdy przez dokładanie: ślad własny
  ZASTĘPUJE zbiorowy, więc wcześniejsze pola mogą być już nieprawdziwe.
- Dystans przejazdu bierze się z faktycznego śladu, nie z zapowiedzianej długości
  wydarzenia.
- Pozostałe ograniczenie: samotny przejazd (poza wydarzeniem) nadal nie odkrywa nic. Zniknie,
  gdy dojdzie źródło niezwiązane z turnusem — i **dlatego `rider_activities` istnieje mimo
  jednego źródła**: nowe źródło dopisze wiersze, nie przebuduje modułu.
- UI: **jedna kontrolka** [`partials/track-upload.php`](../views/web/partials/track-upload.php)
  (`renderTrackUpload()`), renderowana w DWÓCH miejscach — na stronie wydarzenia i **w
  kronice**. Kto wgrywa ślad zbiorowy, a kto własny, rozstrzyga SERWER
  (`TrackController`) na podstawie uprawnień — pole w formularzu byłoby zaproszeniem do
  wgrania „śladu z imprezy" przez przypadkową osobę. Organizator, który sam jechał, ma
  checkbox „tylko mój ślad".
  - **Poprawki po uwagach usera 2026-08-12** („w ogóle nie jest widoczne i niekoniecznie
    wiadomo, że można i trzeba to zrobić"): pierwsza wersja była dopiskiem w stopce bloku
    „Relacje z tego wyjazdu" — czyli pod warunkiem `$hasChronicle`, więc **wyjazd bez
    kroniki nie miał tej kontrolki w ogóle**, a to właśnie tam jest najbardziej potrzebna.
    Teraz: własna sekcja `#slad` z nagłówkiem i wyjaśnieniem, poza warunkiem kroniki.
  - W kronice stoi **nad** blokiem „Dorzuć swoje": ślad odblokowuje odkrycia całemu
    składowi, wpis w dzienniku jest miły, ale niczego nie uruchamia.
  - `backTo` decyduje, dokąd wraca akcja — kontrolka jest w dwóch miejscach, a wyrzucenie
    człowieka na inną stronę niż ta, na której kliknął, jest zawsze błędem. Whitelista
    jednej wartości, nie adres z żądania (to byłby open redirect). **Pułapka:** kotwica
    `#slad` musi iść ZA parametrami — doklejanie `&blad=` do gotowego adresu z fragmentem
    wpychało komunikat do środka fragmentu i błąd wyglądał jak cicha odmowa.
- **Zbiórka i „Kto organizuje" stoją OBOK SIEBIE** na stronie wydarzenia (uwaga usera z tej
  samej rundy) — osobno zajmowały ok. 790 px pionu na dwa krótkie bloki kontekstu. Ta sama
  klasa `.chr-actions` co dwie akcje uczestnika w kronice, żeby nie mnożyć siatek; kotwice
  `#zbiorka`/`#organizator` zostają na sekcjach, więc margines zerowany regułą
  `.chr-actions > .sec`.

- **Siatka**: [`Utils\DiscoveryGrid`](../core/Utils/DiscoveryGrid.php) — heksagony
  („pointy-top", współrzędne osiowe) w metrach **Web Mercator**, globalne. Świadomie NIE
  `RouteCells` (Etap 2): tamta ma `cos(52°)` wpisany na stałe, więc działa wyłącznie dla
  Polski, i jest kwadratowa (kwadraty stykają się rogami → dziury w odkrytym pasie po
  skosie). Odrzucona projekcja równopowierzchniowa: heksagon z niej narysowany na mapie
  Mercatora jest na 52°N rozciągnięty ~2,6× w pionie.
- **Rozmiar pola: `RES_CELL = 4` ≈ 500 m** (~120 pól na 60 km). Pierwsza wersja miała 1 km
  (dobrane pod „27 nowych pól" z §38) — user: „hexy są za duże", bo heksagon wielkości
  miasteczka zaokrągla ślad tak, że nie widać, którędy ktoś jechał. **Zmiana rozmiaru
  unieważnia WSZYSTKIE zapisane odkrycia** (poziom siedzi w identyfikatorze pola): trzeba
  wyczyścić tabele, puścić `backfill_discovery.php --rebuild`, przeliczyć `known_route_cells`
  i przesunąć progi zoomu w `/api/discovery/cells`.
- **Komórki liczone z PEŁNYCH plików GPX z dysku, nigdy z `event_stages.elevation_profile`.**
  Zmierzone na istniejących danych: profil ma maks. 50 próbek na etap (przy 160 km trasie
  ~3,2 km między nimi), a `RouteCells` interpoluje między nimi linią prostą — **~38%
  komórek w `event_stage_cells` to teren, którego nikt nie tknął, i brakuje ~59% realnej
  trasy**. Dla pytania „czy te wyjazdy są w tych samych okolicach" (MatchEngine) bez
  znaczenia; dla zdania „odkryłeś to pole" to zmyślanie.
- **„Pierwszy raz i tylko pierwszy raz" (§5) jest niezmiennikiem BAZY**, nie regułą w
  kodzie: klucz główny `discovery_cells(user_id, cell_id)` + `INSERT IGNORE`, a liczba
  wstawionych wierszy JEST liczbą nowych odkryć. To załatwia większość antyfarmingu (§25)
  strukturalnie — powtarzanie tej samej pętli nie ma jak nic dodać.
- **Zaczep w [`EventAttendance::declare()`](../core/Models/EventAttendance.php)**, tam gdzie
  już wisi przeliczanie peletonu, i z tego samego powodu. Działa w obie strony: wycofanie
  obecności kasuje przejazd i **wyłącznie te** odkrycia, których był pierwszym źródłem
  (`activity_id`). Opakowane w `try/catch` — Discovery jest warstwą dodatkową i nie wolno
  mu przewrócić potwierdzania obecności. Koszt: ~100 ms parsowania GPX na 160 km trasę.
  Wydarzenie bez GPX **nie zostawia wiersza** (nie pustego) — dzięki temu policzy się, gdy
  organizator dogra ślad po fakcie.
- **Punktacja w JEDNYM pliku** [`core/discovery.php`](../core/discovery.php) (§33), czytanym
  wyłącznie przez [`DiscoveryScoring`](../core/Models/DiscoveryScoring.php). Trzy kategorie
  (§13): DISCOVERY (za nowe pole), EXPLORATION (pierwszy w społeczności / pole rzadko
  odwiedzane — tylko JEDEN z dwóch bonusów na pole), TRAILS (progi 25/50/75/100%).
  Sprawdzian §12 do powtórzenia przy każdej zmianie liczb: 180 km trasa w całości daje
  ~1 800 pkt Discovery vs 700 pkt Trails — ścieżka odkrywcza zostaje silniejsza.

### „Nowy teren" zamiast „białych plam" + premia obniżona z 15 do 5 (2026-08-13)
User: *„pierwszy, który się zarejestruje i będzie jechał, dostanie punkty? z punktu widzenia
psychologicznego to słabe"*. Zarzut trafiony w połowie i wart zapisania w całości, bo przy
każdej zmianie tych liczb wróci.
- **Mechanika nie nagradza daty rejestracji, tylko MIEJSCE** — bonus dostaje ten, kto
  przejechał przez pole, którego nie ma nikt. Pole ma ok. 0,57 km², Polska 312 700 km²
  → **ok. 547 000 pól**; przejazd dotyka ~2 pól na kilometr, więc zamalowanie kraju to
  ~274 000 km bez powtórzeń. **Zasób się nie wyczerpie** i ktoś dołączający za dziesięć lat
  dalej znajdzie nowy teren.
- **Ale windfall pierwszej fali jest realny**: dziś każde pole jest nowe, więc pierwsi zbiorą
  wyniki, których nikt później nie powtórzy przy tym samym wysiłku.
- **Dlaczego 15 → 5**: przy 15 pkt najsilniejsza nagroda w systemie przypadała za rzecz
  nieprzypisywalną ani wysiłkowi, ani umiejętności (teoria autodeterminacji — nagroda zasila
  kompetencję tylko wtedy, gdy wynika z tego, CO zrobiłeś; teoria sprawiedliwości Adamsa —
  ten sam nakład i 2,5× niższy wynik to wzorcowa niesprawiedliwość porównawcza; efekt Mateusza
  — przewaga wczesnych kumuluje się i zamyka grupę). 5 pkt trzyma premię **poniżej** 10 pkt za
  własne odkrycie, więc najważniejszym przejazdem w systemie zostaje **pierwszy przejazd
  każdego** — jedyna rzecz, którą nowy dostaje na równi z weteranem.
- **Dlaczego nowa nazwa**: „białe plamy zamalowane" to język zajmowania terenu, gra o sumie
  zerowej (moje pole = takie, którego ty już nie zdobędziesz) — kłóciło się z nagłówkiem
  „RAZEM ODKRYLIŚMY" na tej samej stronie. „Nowy teren / pól dołożonych do wspólnej mapy"
  opisuje **to samo zdarzenie jako wkład**, nie jako roszczenie: dobro wspólne zamiast dobra
  pozycyjnego.
- **Stała `EXPLORATION` i klucz `'exploration'` ZOSTAJĄ** — siedzą w kluczu unikalnym
  `point_transactions` i w całej historii naliczeń. Nazwa dla człowieka mieszka wyłącznie
  w `PointLedger::LABELS` i wolno ją zmieniać bez ruszania bazy.
- **Historii to nie zmienia** — rejestr jest niezmienny, pierwsza fala zachowuje swoje 15 pkt.
- Opisy naliczeń mówią teraz „**z nich**" („656 nowych pól · 10 pkt za każde" / „656 **z nich**
  powiększyło wspólną mapę”), bo poprzednie podawały tę samą liczbę dwa razy pod dwiema
  nazwami i czytało się to jak podwójne naliczenie. `PointLedger::recentForUser()` sortuje
  w obrębie przejazdu **rosnąco po id**, czyli w kolejności przyczynowej: jazda → odkrycia →
  premia. Premia czyta się wtedy jako dopisek, a nie jako największa pozycja na górze listy.
- Odrzucone na teraz: premia **odnawialna** („nikt tu nie był od 12 miesięcy") — naprawia
  problem u źródła i działa tak samo w piątym roku, ale wymaga kolumny z datą ostatniej
  wizyty na `discovery_cell_totals` (dane są w `rider_activity_cells`). Do rozważenia, gdy
  mapa się zapełni.
- **Regiony = województwa z pokryciem heksowym (2026-08-25, migr. 070).** Dotąd
  region był pozycją słownika bez geometrii i dlatego procenty pokrycia były
  zakazane — mianownik nie miał pokrycia w danych. Teraz ma: `region_cells`
  przypisuje każdy heks dokładnie jednemu województwu (UNIQUE po cell_id,
  import `backfill_regions.php` z granicami administracyjnymi), a
  `Discovery::regionsForUser` liczy regiony po ODKRYTYCH HEKSACH, nie po
  regionach wydarzeń. Wydarzenia dalej dają punkty przez rejestr — one tylko
  już NIE definiują regionu. To otwiera drogę do procentów per region
  („Bieszczady…", a właściwie „podkarpackie x% odkryte") i celów regionalnych;
  decyzja o POKAZYWANIU takich procentów pozostaje osobnym wyborem produktowym,
  bo makiety z „% Polski" były odrzucone jako liczby bez pokrycia, a dziś mają
  je dopiero co odzyskane.
- **Regiony poza Polską rysuje się ręką (2026-09-01, `/admin/regiony-mapa`).**
  Województwa mają oficjalne granice i plik; Czechy i Słowacja mają mieć podział
  AUTORSKI i zgrubny, więc pliku nie ma i nie będzie. Narzędzie zbiera OBRYS
  ZEWNĘTRZNY — klikasz heksy wzdłuż granicy (poziom 2 siatki, ok. 60 km²),
  środek wypełnia się sam przy trzecim polu — i zapisuje go jako GeoJSON
  (`data/regiony.geojson`, [`RegionOutline`](../core/Models/RegionOutline.php)).
  **Świadomie NIE powstał drugi mechanizm geometrii**: pokrycie heksami liczy
  dalej `backfill_regions.php`, tym samym kodem co dla województw, jednym
  przebiegiem na obu plikach. Pierwsza propozycja szła w drugą stronę (osobna
  tabela `region_paint` z polami malowanymi na mapie i własną materializacją do
  `region_cells`) i została odrzucona przez usera właśnie dlatego, że dokładała
  równoległy mechanizm zamiast użyć istniejącego.
  **Narzędzie pokazuje WSZYSTKIE regiony, nie tylko własne** (uzupełnienie
  z tego samego dnia — „oznaczając np. lubelskie powinno mi się na mapie
  zaznaczyć, bo nie widać"): województwa z importu są na mapie kreską (podgląd,
  bez edycji — granica administracyjna jest dokładniejsza niż cokolwiek z ręki),
  obrysy rysowane ręką linią ciągłą, a wybrany region grubiej i z dociągnięciem
  kadru. **„Białe plamy"** to pola siatki, które nie należą do ŻADNEGO regionu —
  czerwone, z licznikiem w rogu mapy; to jest odpowiedź na „co mam nie pokryte".
  **Konflikt widać przed zapisem**: rysując po cudzym terenie, dostajesz „ZABIERASZ:
  dolnośląskie (6)". Rozstrzyga się on jednak dopiero przy imporcie i przez
  PIERWSZEŃSTWO (`priority` = czas zapisu, region zapisany później wygrywa
  sporne heksy), a nie przez przycinanie cudzego wielokąta — patrz
  [`RegionOutline`](../core/Models/RegionOutline.php). Przy każdym regionie na
  liście stoją dwie informacje: skąd ma geometrię (obrys / import / brak) i ile
  ma heksów w bazie. Rozjazd między nimi JEST komunikatem „puść backfill".
  **Tryb „Popraw pojedyncze pole" (2026-09-10, świadoma decyzja usera — dotąd
  województwa były tu tylko do podglądu).** Osobny od rysowania obrysu i
  celowo NIE reużywa jego mechanizmu: obrys zastępuje CAŁĄ geometrię regionu,
  więc mała łatka pod kodem istniejącego województwa skasowałaby jego
  oficjalną granicę zamiast poprawić jedno pole przy styku. Ten tryb pomija
  geometrię/plik całkowicie — klik zapisuje się WPROST do `region_cells`
  (`Models\RegionOutline::assignCell()`, `POST /admin/regiony-mapa/pole`),
  działa na KAŻDYM regionie łącznie z importem, i widać efekt bez
  `backfill_regions.php`. Siatka klikania jest DROBNA (poziom `discovery_cells`,
  ~500 m), nie poziom obrysu (~7,5 km) — to punktowa korekta jednego heksa,
  nie rysowanie granicy.
- **Znane trasy** ([`KnownRoute`](../core/Models/KnownRoute.php), panel `/admin/znane-trasy`)
  są DANYMI, nigdy kodem (§10). **Postępu nie ma w żadnej tabeli** — jest przecięciem
  `known_route_cells` z `discovery_cells` (jak Kronika i Puls, które też nie mają tabel).
  Zapamiętywane są tylko PUNKTY, przy przejeździe, który przekroczył próg.
  `awardBacklog()` (wewnątrz `createFromGpx`, żeby nie dało się o nim zapomnieć) nagradza
  tych, którzy przejechali trasę przed jej dodaniem — bez tego strona pokazywałaby
  „100%" obok zera punktów.
  Wszystko, co da się w trasie zmienić, zmienia JEDNA akcja
  `POST /admin/znane-trasy/{id}/edytuj` (nazwa, opis, region, zdjęcie z migr. 046, bonusy
  z migr. 043, opcjonalnie cały przebieg). **Nigdy nie dodawać trasy od nowa tylko po to,
  żeby coś w niej poprawić** — nowa trasa to nowe id, a progi zapisane
  w `point_transactions` jako `"trasa:próg"` wskazywałyby wtedy na byt, którego już nie ma,
  wszyscy uczestnicy straciliby postęp, a adres `/trasy/{slug}` przestałby działać.
  Patrz sekcja „Znane trasy: niewidoczne na mapie i nieedytowalne" niżej.
- **Wspólna mapa** to zmaterializowany agregat `discovery_cell_totals` (wzorzec
  `rider_connections`), odtwarzalny przez `Discovery::rebuildTotals()` — **zweryfikowane, że
  wynik przyrostowy i pełny są identyczne**. Rozstrzygnięcie remisu idzie po `activity_id`,
  NIE po `user_id`: `discovered_at` ma dokładność sekundy, więc przy backfillu wszystko ma
  ten sam znacznik i „kto był pierwszy" rozstrzygałby przypadkowy numer konta (to był realny
  rozjazd, złapany testem spójności).
- **MGŁA, NIE PODŚWIETLENIE** (odwrócenie koncepcji, decyzja usera 2026-08-12). Pierwsza
  wersja malowała to, co ODKRYTE, na czystej mapie. Teraz **świat startuje cały zamglony**,
  a jazda mgłę zdejmuje („fog of war"). Powód jest produktowy: przy podświetlaniu odkryć
  nowy użytkownik widzi czystą mapę i nie ma na niej nic do zrobienia; przy mgle od
  pierwszej sekundy widzi, ile świata przed nim stoi.
  - Realizacja: **JEDEN wielokąt obejmujący cały świat, w którym każde odkryte pole jest
    DZIURĄ** (`fill-rule: evenodd`, domyślne w Leaflecie). Pierścień to cały glob, nie kadr
    z marginesem — przy szybkim przesuwaniu warstwa jedzie z podkładem, a odpowiedź serwera
    ma opóźnienie, więc kadr z marginesem pokazywałby przez moment nagi, „odkryty" brzeg.
    Mgła kładzie się też SYNCHRONICZNIE przy starcie, przed pierwszą odpowiedzią API,
    inaczej świat byłby przez chwilę odsłonięty w całości — czyli pokazywałby dokładne
    przeciwieństwo prawdy.
  - **Zasłaniamy stan odkrycia, nie geografię**: mgła jest półprzezroczysta i JASNA (drogi,
    nazwy, jeziora zostają czytelne). Jasna, a nie ciemna, bo etykiety OSM są ciemne —
    przyciemnienie zjadłoby ich kontrast. Górna granica czytelności ok. 0.7 krycia;
    `FOG_UNDISCOVERED = 0.62`.
  - **Paleta: chłodna szarość, świadomie BEZ zieleni** (uwaga usera: kafle OSM w Polsce są
    mocno zielone, więc zielona warstwa zlewała się z podkładem). Kolor i tryb mieszania
    siedzą w CSS (`.leaflet-discoveryFog-pane`), nie w JS — stroi się je bez ruszania logiki.
    Zakomentowany `mix-blend-mode: saturation` to gotowa alternatywa: odbarwia podkład
    zamiast go rozjaśniać, zachowując pełny kontrast; domyślnie wyłączona, bo zwykłe krycie
    zachowuje się identycznie w każdej przeglądarce.
  - **Heatmapa wyrażona PRZEJRZYSTOŚCIĄ, nie kolorem**: `FOG_RAMP` schodzi od 0.40 do 0
    wraz z natężeniem — „im częściej tędy jeździmy, tym wyraźniej widać świat". Skala jest
    **względna wobec kadru** (`max` w odpowiedzi API), bo intensywność zmienia się o rzędy
    wielkości między poziomami agregacji (zmierzone: 4 przy pełnym przybliżeniu, 1201 przy
    oddaleniu); sztywne progi wysycałyby mapę przy oddaleniu i zostawiały bladą przy
    przybliżeniu. Przy `max = 1` mgła schodzi CAŁKOWICIE (odkryte znaczy odkryte — tak
    działa mapa jednej osoby przy pełnym przybliżeniu).
  - Warstwy się NIE nakładają: tam, gdzie leży mgła częściowa, w warstwie bazowej jest
    dziura, więc każde pole ma dokładnie jedno krycie, bez sumowania przezroczystości.
  - **Liczba przy legendzie tylko przy pełnym przybliżeniu** — przy oddaleniu intensywność
    jest sumą po wielu polach i „do 1201" brzmiałoby jak liczba osób.
  - **Pułapka przy edycji tekstów**: po odwróceniu SZARE znaczy „nieodkryte". Podpis na
    profilu mówił „zacieniony teren" o terenie ODKRYTYM i po zmianie był wprost nieprawdą.
- **Wtedy DWIE WARSTWY, przełączane przez użytkownika** (`L.control.layers`, migr. `041`)
  — dziś jest ich pięć, sterowanych drzewem ze słownika (`map_layer`, migr. `072`),
  patrz „Warstwy mapy odkryć" niżej i [`models.md`](models.md) → `MapLayer`:
  - **Odkrycia** (mgła, domyślnie włączona) — odpowiada „co odkryliśmy", czyli sens tej strony.
  - **Heatmapa przejazdów** (domyślnie wyłączona) — „którędy jeździmy najczęściej".
    Skala **przezroczysty → czerwony** (`HEAT_RAMP`: `--blaze` → `--surface-gravel` → `--s-red`),
    jedna rodzina barw, nie tęcza. Ciepła, bo to jedyny zakres, który nie ginie na
    zielonych kaflach OSM.
  - **Dlaczego to musiały być RÓŻNE DANE, a nie ten sam licznik w innym kolorze**:
    `riders_count` mówi ILU LUDZI pole odkryło — i tylko pierwszy przejazd każdego (§5),
    więc heatmapa na nim zbudowana dublowałaby mgłę. Stąd `passes_count` (migr. 041):
    ile RAZY ktokolwiek tędy przejechał, z powtórzeniami. Zmierzone na istniejących
    danych: w kadrze, gdzie **wszystkie 59 pól ma tych samych 4 odkrywców**, przejazdów
    jest 3, 4 albo 7 — 9 pól na styku dwóch tras jest realnie gorących, czego warstwa mgły
    nie pokazuje w ogóle.
  - Dla JEDNEJ osoby `passes` liczone jest z jej własnych przejazdów („tu jeżdżę
    najczęściej"), nie z licznika globalnego („tędy jeździ pół serwisu").
  - Panele: mgła `discoveryFog` (z-index 350), heatmapa `discoveryHeat` (360), ślady GPX
    w `overlayPane` (400) — heatmapa nad mgłą, żeby gorące miejsca było widać także tam,
    gdzie mgła jeszcze nie zeszła.
  - Legenda ma jeden wiersz na WŁĄCZONĄ warstwę, przełączany razem z nią
    (`overlayadd`/`overlayremove`).
  - `passes_count` jest odtwarzalny z `rider_activity_cells` — to jedyny powód istnienia
    tamtej tabeli. Bez niej `rebuildTotals()` wyzerowałby licznik i cicho zepsuł warstwę.
    Sprawdzane testem razem z `riders_count`.
- **Znaczenie intensywności zależy od zakresu** (i to jest celowe): dla społeczności to
  suma przejazdów na obszarze, dla JEDNEJ osoby — gęstość pokrycia (ile jej pól mieści się
  w zagregowanym heksagonie). Mapa jednej osoby nie ma „ilu ludzi tędy jechało", więc przy
  pełnym przybliżeniu wychodzi jednolita, a przy oddaleniu pokazuje, gdzie ta osoba jeździ
  gęsto. Pierwsza wersja zostawiała tam `1 AS riders` i po scaleniu kubełków agregacji
  produkowała „intensywność" równą liczbie połączonych kubełków — liczbę bez znaczenia.
- **Mapa na profilu rowerzysty** (`/rowerzysta/{slug}`): mgła kładzie się na ISTNIEJĄCĄ
  mapę śladów GPX (`#riderMap`), nie tworzy drugiej — ślady odpowiadają „którymi trasami",
  mgła „jak dużo świata jeszcze przed nią", i to są dwie odpowiedzi na jedno pytanie.
  Kolejność warstw wymuszona własnym panelem Leafletu `discoveryFog`
  (z-index 350, pod `overlayPane` 400); bez tego zależałaby od tego, co wczyta się pierwsze,
  a ślad GPX pod mgłą byłby po prostu niewidoczny. Liczby („Odkrytych pól", „Białe plamy") wchodzą
  do istniejącego `.trust-bar`, sekcja „Zaliczone szlaki" pokazuje **tylko trasy choć w
  części zaliczone** (na cudzym profilu lista szlaków z zerami mówiłaby o katalogu tras,
  nie o tej osobie).
  **BUDŻET LINII — 25 najnowszych** (`$lineBudget` w `rider-profile.php`, 2026-08-14).
  Rysowanie wszystkich śladów nie skaluje się i to jest strona PUBLICZNA, więc koszt płaci
  każdy odwiedzający. Zmierzone na prawdziwych plikach z `assets/uploads/gpx`
  (mediana 180 kB / 2107 punktów): **41 ms na ślad** przy budowaniu i **4,5 ms na ślad na
  każdy krok zoomu** — przy 600 śladach to 108 MB pobierania, ok. 25 s do pierwszego obrazu
  i ok. 2,7 s zwiechy na każdy ruch kółkiem (pomiary na localhoście, w sieci gorzej).
  Reszta śladów zostaje na liście obok i **doczytuje się pojedynczo po kliknięciu**
  (`lazyUrls` + klasa `.rp-ride.is-loading`), więc podświetlanie działa dla wszystkich.
  `fitBounds` woła się **dwa razy** (pierwszy i ostatni wczytany ślad), a nie raz na plik —
  każde dopasowanie kadru przelicza wszystkie narysowane linie, więc wcześniej koszt rósł
  z kwadratem. Kolejność z `EditionTrack::effectiveForUser()` jest malejąca po dacie, więc
  budżet zjadają najnowsze przejazdy. Lista ma `max-height` **także poniżej 900 px**
  (60vh) — wcześniej `none` dawało przy 600 pozycjach ścianę ok. 32 000 px pod mapą.
- **Jedna definicja widoczności profilu**: `Support::visibleRider()` (konto istnieje · nie
  ukryło się · ma potwierdzony przejazd) używana i przez `RiderController::show`, i przez
  `/api/discovery/cells?scope=rider`. Zweryfikowane: po `roster_visible = 0` **oba** zwracają
  404 równocześnie. Dwie kopie tego warunku rozjechałyby się przy pierwszej zmianie zasad
  i rozjazd oznaczałby wyciek mapy osoby, która się ukryła.
- **Wydajność (§32)**: nigdy nie ładujemy całej siatki — `/api/discovery/cells` bierze
  prostokąt widocznego obszaru, a poziom agregacji wynika z zoomu. Agregacja jest
  DWUETAPOWA (prawdziwego rodzica heksagonu nie da się policzyć w SQL, bo wymaga projekcji):
  SQL grupuje po dzieleniu całkowitym `cell_q`/`cell_r` (romby, nie heksagony — chodzi tylko
  o redukcję wierszy), PHP zamienia reprezentanta na PRAWDZIWE pole grubszego poziomu i scala.
  Poziom `RES_CELL` jest dokładny co do pola. API zwraca ŚRODKI, nie wielokąty — sześć par
  współrzędnych na pole rozdęłoby odpowiedź kilkunastokrotnie.
- **Prywatność (§27)**: mapa mówi ILE, nigdy KTO — w `Models\Discovery` nie ma i nie może
  powstać metoda zwracająca listę osób na polu. `first_user_id` służy WYŁĄCZNIE bonusowi i
  nigdy nie trafia na mapę. Przy dzisiejszym źródle ryzyko jest zerowe (geometria pochodzi
  z opublikowanych tras organizatorów) — **ta gwarancja znika w dniu, w którym rowerzyści
  zaczną wgrywać własne ślady**, i wtedy trzeba dodać wycinanie okolic startu/mety.
- **Świadomie BEZ procentów pokrycia** („Polska 3,8%", §6.1/§16/§23). Region w ridemore to
  pozycja słownika, nie geometria (5 wpisów na dev, 13 na prod, mieszają pasma górskie z
  województwami), a mianownik musiałby liczyć pola PRZEJEZDNE — inaczej „Ridemore odkryło
  0,03% Polski" jest prawdziwe i produktowo martwe. §39 zabrania liczb, które nie są
  wiarygodnie policzone. Model trzyma `cell_id` gotowy do agregacji, więc procenty da się
  włączyć później bez migracji.
- **Świadomie BEZ rankingu** (§14) — nigdzie nie ma listy „kto najlepszy".
- **UI = istniejące komponenty** (§28/§29): `.op-head`, `.trust-bar`/`.trust-item`,
  `.op-grid`/`.op-card`, `.event-map`, `.chr-facts`, `.tags`. Jedyne nowe reguły CSS to
  `.trail*` (pasek postępu znanej trasy) — serwis nie miał niczego pokazującego ułamek
  ukończenia. Wejście: pozycja **Odkrycia** w nawigacji (prowadzi do WSPÓLNEJ mapy, bo ta
  działa bez logowania) + „Moje odkrycia" w menu konta.
- **Integracje (Faza D)** — wszystkie jako zdanie w ISTNIEJĄCYM elemencie, zero nowych sekcji:
  Kronika → trzeci fakt w `.chr-facts` („odkryli N nowych pól"); Peleton → trzecia liczba w
  nawiasie przy osobie na profilu („282 wspólne pola", `Discovery::sharedCellCounts` jednym
  zapytaniem na cały peleton); Puls → **NIE nowy typ wpisu**, tylko wzbogacenie `przejazd`
  („Przy okazji odkryli N nowych pól"), bo odkrycie nie jest zdarzeniem obok przejazdu,
  tylko jego skutkiem; strona wydarzenia → jedna linijka pod potwierdzeniem obecności (§38).
- Backfill: [`backfill_discovery.php`](../backfill_discovery.php) (idempotentny,
  `--rebuild` odtwarza agregat). Kolejność chronologiczna ma znaczenie — bonus za pierwsze
  odkrycie należy się temu, kto był tam naprawdę pierwszy.

## Mapy tras (profil + kronika)
Reużywają `Support::gpxMapHead()` i `assets/js/gpx-map.js` — zero nowego kodu mapy.
`extraHead` wstrzykiwany warunkowo: **bez śladu GPX strona w ogóle nie ładuje
Leafletu**. Kolor śladu `#2C6B4F` (`--accent`), nie domyślna pomarańcz biblioteki.
- **Profil** (`#riderMap`): wiele śladów naraz, granice sumowane w `onLoaded`
  (ślady wczytują się asynchronicznie, więc `fitBounds` do ostatniego dawałby
  losowy kadr). Ślad per wyjazd bierze PIERWSZY etap z plikiem —
  `EventAttendance::ridesForUser()`, podzapytanie `gpx_url`.
- **Kronika** (`#chronicleMap`): **WSZYSTKIE ślady turnusu**, bez profilu wysokości
  (kronika jest o dniu, nie o analizie podjazdów). Do 2026-08-12 rysowała jeden —
  przy wielodniówce pokazywało to jeden dzień z trzech i wyglądało, jakby reszta
  trasy nie istniała.
  - Kolejność źródeł: **ślad rzeczywisty przed trasą planowaną** (kronika jest zapisem
    tego, co się wydarzyło), a wewnątrz śladów rzeczywistych **własny ślad widza bije
    ślad organizatora** (`EditionTrack::effectiveFor`, ta sama reguła co przy naliczaniu
    pól). Gość i osoba spoza składu widzą ślad zbiorowy. Bez żadnego śladu rzeczywistego
    pokazujemy trasę planowaną i mówimy o tym wprost w podpisie — inaczej kronika
    starego wyjazdu straciłaby mapę.
  - Zweryfikowane: gość → 3 ślady zbiorowe, uczestnik z własnym → 1 własny, uczestnik
    bez własnego → 3 zbiorowe, turnus bez śladów rzeczywistych → 2 etapy planowane
    z `tracksAreActual = false`.
  - Kolory z `DayColor::forDay()` — ten sam zestaw co plan wyjazdu na stronie
    wydarzenia, żeby „Dzień 2" znaczył wszędzie ten sam kolor. Granice sumowane
    w `onLoaded`, jak na profilu.
  - **POLA DISCOVERY NARYSOWANE NA MAPIE**, w podziale na odkryte TYM przejazdem
    (zieleń `--accent`) i te, które ktoś już wcześniej miał (szarość mgły `#8B95A3`).
    Bez tego panel mówił „359 z 373", ale nie dało się przypisać tych liczb do
    fragmentów trasy — a przez własny teren jeździ się stale (dojazd z domu,
    ulubiona pętla), więc powtórka jest normą, nie wyjątkiem.
    `Discovery::rideCellsForEdition(editionId, ?userId)`; „nowe" = `discovery_cells
    .activity_id` wskazuje na TEN przejazd. Dla gościa i osoby spoza składu liczone
    zbiorczo („ktokolwiek odkrył to wtedy"). Heksagony w osobnym panelu Leafletu
    `rideHex` (z-index 350), żeby linia trasy została czytelna na wierzchu;
    rysowane jako DWA wielokąty złożone, nie setka warstw.
    Sześciokąt rysuje `window.ridemoreHexRing` wystawione z `discovery-map.js` —
    matematyka siatki nadal wyłącznie w `Utils\DiscoveryGrid`.
    **Od 2026-09-01 obok niego stoi `window.ridemoreHexGrid`** (`lattice(map,
    sizeM, {minPx, max, skip})` · `cellAt(lat, lon, sizeM)` → `[q, r]` ·
    `center(q, r, sizeM)` · `ring`) — wspólne jądro siatki dla mgły i narzędzia
    „Regiony na mapie". `hexLattice` mgły jest od tej pory cienką nakładką na
    `latticeCells` ze swoimi progami (`GRID_MIN_PX`, `LATTICE_MAX`); narzędzie
    admina podaje własne, bo rysuje przy oddaleniu, przy którym mgła słusznie
    siatki nie pokazuje. Trzecia kopia wzorów Mercatora nie powstała.
    **Obrys pola dopiero od zoomu 12** (`syncHexOutline` na `zoomend`): ta mapa rysuje
    stały poziom `RES_CELL` w kadrze dopasowanym do całego przejazdu, więc w oddaleniu
    pole ma 1–4 px i biały włos 0,6 px (z mapy odkryć) zająłby 10–30% jego szerokości —
    wyblakłby korytarz zamiast rozdzielić pola. Zmierzone: trasa 100 km w kadrze 435 px
    daje pole 3,5 px. Próg 12 to ten sam zoom, od którego `api/routes.php` podaje
    pojedyncze pola zamiast plam.
    Zweryfikowane: dwie pokrywające się trasy dały temu samemu człowiekowi
    373 nowe pola na pierwszym wyjeździe i **168 nowych + 14 już posiadanych** na drugim.
  - **Po prawej stronie mapy kolumna informacji** (`.chr-route`): legenda pól i śladów
    (który kolor to który dzień, z dystansem) oraz **co ten przejazd wniósł w polach
    Discovery** — dla uczestnika jego własne liczby z `rider_activities`
    (`Discovery::rideForUserOnEdition`), dla każdego innego dorobek całego składu
    (`newCellsForEdition`). Punkty pokazane sumą; rozbicie na kategorie przyjdzie
    razem z osobną sekcją punktacji (dane już są — `rider_activities` trzyma każdą
    kategorię osobno).

## „Oznacz kogoś, kto tam był" — zaproszenie z kroniki
Pętla wzrostu. `POST /kronika/{slug}/oznacz` → `ChronicleController::invite`.
- **NIKOGO nie dopisuje do składu** — wysyła wyłącznie mail (`chronicle-invite`)
  z linkiem do kroniki. Gdyby uczestnik mógł oznaczyć kogoś jako obecnego, cała
  wartość „Byłem" (to, że liczby oznaczają coś prawdziwego) zniknęłaby w jedno
  kliknięcie.
- Zaprosić może **tylko ktoś, kto sam był na tym turnusie** — to czyni zaproszenie
  uczciwym i zamyka drogę do rozsyłania maili z przypadkowych kont.
- Limit 5 zaproszeń/turnus/dobę, licznik w sesji (osobna tabela pod rate-limit
  byłaby tu przesadą). Adres już obecny w składzie → cichy sukces, żeby formularz
  nie służył do sprawdzania „czy X ma konto".

## Puls na stronie głównej
`HomeController` → `Pulse::feed(3)` → sekcja `#h2-puls` NAD kalendarzem
(`home.php`). Strona główna mówiła dotąd wyłącznie w czasie przyszłym; to pierwsze
miejsce, gdzie widać, że coś się TU dzieje. Kafle `.op-card`, wpisy `przejazd`/
`kronika` prowadzą do kroniki, `sklad`/`wezwanie` do strony wydarzenia,
`skarb-*` na mapę odkryć, `slady-wgrane` na **stronę przejazdu przy jednym
śladzie, na profil rowerzysty przy wielu**.

## Obrazek śladu na karcie Pulsu (2026-09-12)
Prośba usera: „żeby w card pojawiało się zdjęcie śladu, jeśli jest jedno —
z linkiem do przejazdu; jeśli kilka — wyrenderować wszystkie, z linkiem do
profilu". Wpis `slady-wgrane` jako jedyny nie ma wyjazdu, więc nie ma ani
zdjęcia z kroniki, ani okładki organizatora — miejsce na obrazek zostawało
puste na karcie, a na `/puls` miniatura w ogóle nie powstawała.

**Nie powstał nowy mechanizm — rozszerzony został ten od „Trasy dnia".**
`TileController::trackGroupMap()` składa JEDEN PNG (kafle OSM + ślady,
`TileRenderer::compose()`) pod adresem `/assets/tiles/slad/{key}/{rozmiar}/{stamp}.png`,
zapisuje go na dysk i znika z obiegu — kolejne wejścia oddaje Apache regułą `!-f`.

- **Adres unieważnia się sam.** Zamiast daty (jak `rotd`) niesie STEMPEL
  `MAX(created_at)` grupy, więc kolejny ślad wgrany tej samej doby zmienia adres.
  Nic nie trzeba kasować ani wersjonować.
- **Klucz `sw-{userId}-{RRRRMMDD}` to GRUPA WPISU, nie warstwa mapy** — stąd
  własna walidacja zamiast `TileSource::isAllowed()`.
- **§27 trzyma się bez wyjątku.** Solo rysuje się WYŁĄCZNIE z `gpx_geometry_trimmed`
  (klucz `trimmed` z `Pulse::trackGroupHashes`), czyli z tego samego źródła, z którego
  publicznie rysuje się mapa społeczności; plik GPX ani surowa geometria solo nie są
  tu dotykane. Do tego bramka `Support::visibleRiderById` — ta sama, którą model
  stawia w zapytaniu feedu, bo adres jest w pełni przewidywalny.
- **Jeden ślad → styl `real`, wiele → `heat`**, ale **kolor w obu przypadkach
  pomarańczowy**. Pierwsza wersja rysowała pojedynczy ślad zielenią `real` i na
  podkładzie OSM w lesie ledwo było go widać — dokładnie to, co zapisano przy
  `STYLES['heat']` 2026-08-29. Nadpisanie idzie kluczem `color`, tym samym, którym
  znane trasy dostają własne kolory — bez ósmego stylu we wspólnej tablicy.
- **Kadr na najgęstszym skupisku** (wybór usera). Klastrowanie było już
  w `GpxGeometry::boundsFor()`, ale tylko dla zwykłej tabeli i z progiem 1000 km —
  skrojonym pod pełny ekran. Zmierzone na dev: grupa 37 przejazdów rozciąga się na
  ~570 km, czyli MIEŚCI SIĘ pod tamtym progiem, a na 272 px wychodziłaby siatką
  włosów na mapie kraju. Stąd `SLAD_CLUSTER_TRIGGER_KM = 120` / `BUCKET = 50`
  podawane parametrem.
- **Dwa rozmiary**: `karta` 600×272 (tło `.op-card__v`) i `kwadrat` 264×264
  (miniatura `.pulse-item__ph`), ok. 2× względem CSS.
- **Bez geometrii nie ma obrazka.** `mapReady` z modelu mówi, ile śladów grupy ma
  policzoną geometrię; przy zerze widok nie wstawia `<img>`, bo endpoint odda 404.
  W bazie dev pięć z sześciu grup nie ma geometrii (brakujące pliki GPX), więc ta
  ścieżka jest tam regułą, nie wyjątkiem.

**TEN SAM FEED RYSUJĄ DWA WIDOKI — i to jest tu jedyna pułapka.** Model dokłada
nowy typ wpisu, ktoś aktualizuje `pulse.php`, a `home.php` zostaje ze swoim
`default`. Zdarzyło się dwa razy z rzędu: skarby (naprawione 2026-09-11) i
`slady-wgrane` (2026-09-12 — user wgrał ślad, a karta wyszła jako
„«jego imię» · Szuka towarzystwa", bo domyślnym opisem było brzmienie
`wezwania`). Od 2026-09-12 domyślny opis jest neutralny („Zobacz w Pulsie"),
`wezwanie` ma własną gałąź, a `widoki_test.php` sprawdza listę typów
**wyprowadzoną z modelu** — dziewiąty typ wywróci test sam.

## Puls — wygląd wpisu (poprawki 2026-08-12 po uwagach usera)
- **Wpis to WIERSZ POZIOMY, nie karta pionowa.** Pierwsza próba reużywała
  `home-event-card.php` (.hcard) — wpis urósł do ~356 px, jeden przejazd zajmował
  ekran. Feed rządzi się inną logiką niż strona wyjazdu: ma pozwolić PRZEBIEC
  wzrokiem kilka zdarzeń. Treść w lewo (`.pulse-item__main`), miniatura kwadratowa
  w prawo (`.pulse-item__ph`, 132 px / 96 px na mobile). Zmierzone: **~500 px → 213 px
  na wpis, 3,2 wpisu na ekran 720 px**.
- Rytm feedu nadpisuje globalny rytm sekcji (`.pulse-feed .sec{margin-bottom:14px}`,
  `.pulse-item{padding:16px 18px}`) — `.sec` 35 px i `.box` 21/22 px robiły z wpisu slajd.
- **Fakty jedną linią** (`.pulse-item__facts`, mono): data · dni · dystans · trudność ·
  region · cena. Dane z `Event::cardsForEditions()` — ten sam kształt wiersza co
  `upcoming()`, mapowany przez `EventCardResource`, więc bez trzeciej odmiany karty.
- **Miniatura: najpierw ZDJĘCIE Z KRONIKI tego turnusu, dopiero potem okładka
  wydarzenia** (`Pulse::chroniclePhotos()`). Feed pokazuje, co ludzie przywieźli
  z trasy; okładka to materiał organizatora sprzed wyjazdu i przy wpisie
  „przejechali" byłaby zdjęciem nie na temat.
- `width`/`height` na `<img>` + `loading="lazy"` — bez przeskoku układu przy doczytaniu.

## Strona ZAKOŃCZONEGO wydarzenia — układ (2026-08-12)
Trzy nadmiarowe bloki po jednej uwadze usera („za dużo miejsca zajmują puste
cardy... poniżej masz Relacje, a to to samo co kronika"):
- **Kronika ma na stronie wydarzenia DOKŁADNIE JEDNO wejście** — w bloku
  „Relacje z tego wyjazdu" na dole (sekcja `#opinie`). Górna karta z linkiem do
  kroniki została usunięta jako duplikat. Stan obecności (`.post-ride__me`)
  zszedł tam razem z nią, jako stopka bloku — wszystko „po wyjeździe" w jednym
  miejscu.
- **Na górze zostaje WYŁĄCZNIE pytanie „Byłeś?" i tylko dopóki bez odpowiedzi.**
  To nie informacja do powtórzenia, tylko jedyny moment, w którym system może
  zdobyć fakt o obecności — zepchnięte na dół długiej strony nie zadziałałoby.
  Po odpowiedzi sekcja `#bylem` znika w całości.
- Kotwica `#bylem` pojawia się dokładnie razem z tą sekcją (link z maila po
  wydarzeniu w nią celuje).
- **Blok „Relacje" nie dubluje kroniki.** Gdy kronika istnieje, zostaje sam
  odsyłacz + galeria; wpisy czyta się w kronice. Bez kroniki (nikt nie
  potwierdził obecności) relacje pokazują się tu jak dotąd — inaczej zniknęłyby.
- **Pułapka HTML**: `<form>` w `<p>` jest nieprawidłowe — przeglądarka zamyka
  akapit przed formularzem i wyrzuca przycisk poza blok (przycisk „Zmień"
  znikał ze stopki). `.post-ride__me` musi być `<div>`.

## Kronika — układ (poprawki 2026-08-12 po uwagach usera)
Było **7 pełnoszerokościowych sekcji** na treść mieszczącą się w kilku zdaniach
(„czemu to takie duże... czemu nie może być obok siebie"). Jest **5**:
- **„N nowych znajomości" i „pierwszy raz w regionie" wróciły DO SKŁADU**
  (`.chr-facts` pod listą ludzi w `#pojechali`) — to zdania o tych samych osobach,
  więc osobna karta na jedno zdanie była marnotrawstwem ekranu.
- ~~„Dorzuć swoje" i „Ktoś jeszcze tam był?" stoją OBOK SIEBIE (`.chr-actions`)~~ —
  **cofnięte 2026-08-13**, patrz niżej. Sama siatka `.chr-actions` została i trzyma
  dziś „Zbiórkę" + „Kto organizuje" na stronie wydarzenia.

### Akcje przeniesione do rzeczy, których dotyczą (2026-08-13)
Sekcja z akcjami uczestnika na DOLE strony nie skaluje się z treścią —
user: *„a nie na dole. bo mogę mieć 50 wpisów"*. Przy pięćdziesięciu wpisach przycisk
„dodaj" jest poza zasięgiem wzroku i wymaga przewinięcia całej cudzej treści.
Sekcja `#dorzuc` zniknęła, a jej dwie karty stoją tam, gdzie się na nie patrzy:
- **„Ktoś jeszcze tam był?" → do składu** (`.chr-more` w `#pojechali`) — user:
  *„logicznie się łączą ze sobą"*. Zaproszenie jest pytaniem o skład: patrzysz, kogo
  brakuje na liście, i od razu masz gdzie wpisać adres. Ten sam zabieg co `.chr-facts`
  (kreska + odstęp zamiast własnej karty). **Kotwica `#oznacz` MUSI zostać** —
  `ChronicleController::invite` wraca na nią z komunikatem (`&zaproszono=1#oznacz`,
  `&blad=...#oznacz`).
- **„Dodaj wpis do kroniki" → do nagłówka dziennika** (`.sec-head .btn`, dosunięty
  do prawej). Widoczny tylko dla kogoś, kto wpisu jeszcze NIE ma:
  `UNIQUE(edition_id, author)` dopuszcza jeden na osobę, więc drugi przycisk
  prowadziłby do edycji pod nazwą „dodaj".
- **Edycja → ołówek przy WŁASNYM wpisie** (`.review-act` + `.iconbtn`,
  `Icon::render('edit')`). Własny wpis rozpoznajemy po `id` relacji
  (`$entry['id'] === $viewerRecap['id']`), nie po nazwisku autora — imiona się
  powtarzają. `renderActivityCard()` dostało siódmy parametr `$actionsHtml`,
  dopisany na końcu listy, więc pozostałe wywołania (opinie, komentarze) są nietknięte.
- **Sekcja dziennika renderuje się też PUSTA**, gdy widz był na wyjeździe — inaczej
  pierwszy uczestnik nie miałby skąd dodać pierwszego wpisu.
- Kolejność: `pojechali` (ze składem i zaproszeniem) → `trasa` → `dziennik`
  (z przyciskiem i ołówkami) → `ślad` → `dalej`.

## Znane trasy jako byt publiczny — `/trasy`, `/trasy/{slug}` (2026-08-13)
Do tej pory `known_routes` miało WYŁĄCZNIE panel admina: karty tras na `/odkrycia` i na
profilu rowerzysty pokazywały procent i nie dawało się w trasę wejść. Trasa jest tymczasem
jedynym bytem tego serwisu bez daty ważności — wydarzenie mija, Velo Czorsztyn zostaje.
- [`TrailController`](../core/Controllers/TrailController.php) — publiczny, **świadomie
  osobny** od `Admin\KnownRouteController` (tamten zarządza katalogiem i wymaga admina).
- Slug **już istniał** od migr. 040 — nie było trzeba migracji, tylko trasy w routerze.
  `/trasy` rejestrowane PRZED `/trasy/{slug}`: router dopasowuje liniowo.
- Strona trasy: przebieg na mapie, TWÓJ postęp najwyżej (jedyna liczba mówiąca o widzu),
  progi punktowe, „mają ją całą" (lista tylko widocznych, licznik wszystkich — ukrycie
  dotyczy tożsamości, nie faktu). JSON-LD typu **`Place`, nie `Event`** — trasa nie ma daty
  ani organizatora; `Event` byłby fałszywką wobec wyszukiwarek.
- Własna sitemapa `/sitemap-trails.xml` — treść bez daty ważności, więc wysoki priorytet
  i rzadka częstotliwość.
- **Przy okazji naprawione `Format::slugify()`**: `iconv('ASCII//TRANSLIT')` daje wynik
  zależny od locale i na Windowsie zamieniał „ś" na `'s`, po czym apostrof leciał do
  myślnika — stąd w bazie `kasia-wi-sniewska`. Polskie znaki podmieniane teraz JAWNIE,
  przed iconv-em. Istniejących slugów NIE ruszamy: adres, który raz gdzieś poszedł, ma dalej
  działać.

### Mapa strony trasy pokazuje TĘ trasę, nie katalog (2026-09-10)
Zgłoszenie usera: „wchodząc na `/trasy/{slug}` chciałbym widzieć tylko ją, nie potrzebuję
widzieć innych". Do tej daty warstwa „Znane trasy" ciągnęła na tej stronie klucz `kr`
(CAŁY katalog), a przebieg tej jednej trasy szedł OSOBNĄ, zawsze zapaloną warstwą
`kr-{id}` („bohater", 2026-09-04). Skutki były dwa: w terenie z kilkoma szlakami strona
o jednym z nich pokazywała plątaninę wszystkich, a checkbox „Znane trasy" wyglądał na
zepsuty — odznaczenie go nie gasiło linii, bo rysował ją ten drugi kafel.
- **Warstwa NIESIE teraz `kr-{id}`** (`TrailController::show` patchuje `trackKey`, tak jak
  patchuje `cells`) i jest JEDYNYM bytem rysującym trasę: jeden checkbox, jedna linia.
  Osobny `mapHeroTilesUrl` zniknął razem z tym. Patch siedzi w kontrolerze, nie w słowniku
  warstw — to wiedza o UKŁADZIE tej strony; na `/odkrycia` i na profilu ta sama warstwa
  dalej znaczy katalog.
- **Klik w mapę pyta o TĘ trasę** — `GET /api/discovery/trails/at?route={id}`
  (`KnownRoute::atPoint(..., onlyRouteId)`). Bez tego dymek mówiłby o szlakach, których
  na stronie nie widać. Adres z gotowym `?route=` składa kontroler, więc `discovery-map.js`
  dokleja `lat/lon/zoom` przez separator (`?` albo `&`), a nie sztywne `?`.
- **Sąsiedzi zeszli z mapy do sekcji „W okolicy tej trasy"** (`#w-okolicy`, pod „Mają ją
  całą") — `KnownRoute::nearby()`, sąsiedztwo liczone tą samą definicją co przy dobieraniu
  kolorów tras (`COLOR_NEIGHBOUR_RADIUS`, ok. 1 km). Zamiast paska postępu karta niesie
  PODPIS O RELACJI („krzyżuje się z tą trasą" / „biegnie w pobliżu"): procent na CUDZEJ
  trasie odpowiadałby na pytanie, którego na tej stronie nikt nie zadał. Brak sąsiadów =
  brak sekcji.
- **Zero nowej karty**: `.disc-trail` przeniesiona do wspólnego partiala
  [`partials/trail-card.php`](../views/web/partials/trail-card.php) i używana też przez
  `/trasy` oraz `/odkrycia` — trzecia kopia tego kafelka byłaby przypieczętowaniem
  rozjazdu, który już się zaczął (patrz `views-and-frontend.md`).

### „Co zobaczysz po drodze" — atrakcje na trasie (2026-08-23)
Zgłoszenie usera: „chciałbym zobaczyć, jakie skarby (w domyśle atrakcje) zobaczę na
trasie". Skarb jest w tym serwisie DWIEMA rzeczami naraz: punktem do zdobycia i miejscem,
które warto zobaczyć. Kafel statystyk mówił dotąd wyłącznie o pierwszym („12 skarbów,
+600 pkt"), a człowiek planujący wyjazd pyta o drugie.

- [`Treasure::listOnRoute(routeId, viewerId)`](../core/Models/Treasure.php) — LISTA, obok
  istniejącego `onRoute()`, który zostaje tanim agregatem („ile i za ile"). Osobna metoda,
  bo to dwa różne pytania.
- **Kolejność wzdłuż śladu** (`known_route_cells.sort_order`, migr. 048), nie po nazwie ani
  punktach: lista czyta się jak plan wyprawy. Trasy sprzed tamtej migracji (sort_order NULL)
  lądują na końcu — ta sama usterka, którą panel nazywa „do przeliczenia".
- **UJAWNIENIE PRZECHODZI PRZEZ TEN SAM `reveal()` CO MAPA.** Publiczna strona trasy byłaby
  najłatwiejszym obejściem całej zagadki ukrytych skarbów („wejdź, przeczytaj listę, jedź
  prosto pod punkty"), więc warunek nie może być tu osobny. Skarb ukryty w polu, którego
  widz nie odkrył, **nie wychodzi z modelu wcale** — ani nazwą, ani zdjęciem, ani
  współrzędnymi; wychodzi wyłącznie jako LICZBA (`hidden`).
- **Liczba ukrytych jest w interfejsie obowiązkowa**: kafel wyżej mówi „12 skarbów", a kart
  może być pięć. Bez zdania „N miejsc jest ukrytych — pokażą się, gdy przejedziesz przez
  ich okolicę" różnica wygląda na błąd, a nie na regułę modułu.
- **Zero nowego komponentu**: karty to `.disc-trails--all` + `.disc-trail`, czyli dokładnie
  te same kafle co katalog `/trasy` (miniatura, nazwa, metryczka, stopka). Miejsce na
  zdjęcie było w nich od początku.
- **Klik w kartę ustawia mapę na tym miejscu** — `map.ridemoreFocusTreasure(id, lat, lon)`,
  ten sam mechanizm co panel obok mapy na `/odkrycia`. Bez tego lista mówi „jest tam coś
  takiego" i zostawia z pytaniem „ale gdzie" (dokładnie to zgłoszenie zamknęło tamtą wersję
  panelu). Karta ma `role=button` i obsługę klawiatury; bez JS zostaje czytelna karta.
- **Zdjęcia**: pokazują się dla skarbów jawnych (`reveal_level = 2`) i dla znalazcy —
  `reveal()` zdejmuje zdjęcie razem z nazwą i opisem, bo jest najmocniejszym spoilerem tego
  modułu. Wariant `thumb` przez `Utils\Image::src`, jak wszędzie.
- **Opis trasy na całą szerokość** (druga część tego samego zgłoszenia): `.op-head__sub` ma
  `max-width: 62ch` — miara dobra dla METRYCZKI (jednej linii faktów), nie dla akapitu
  opisu, który przy tym limicie kończył się w połowie ekranu. Modyfikator
  `.op-head__sub--full`, nie zmiana wspólnej klasy: ten sam nagłówek nosi profil organizatora.
- **Zdjęcia samych tras**: trasa ma JEDNO zdjęcie (`known_routes.cover_photo_url`, migr. 046),
  pokazywane pod nagłówkiem strony i ustawiane w panelu admina. Galerii tras nie ma —
  gdyby była potrzebna, wzorcem jest `treasure_photos` (SKA/14).
- Testy: `php tests/run.php skarby` (pięć przypadków tej listy, w tym ten najważniejszy:
  gość nie dostaje o ukrytym skarbie ani nazwy, ani zdjęcia — sprawdzane po całym JSON-ie).

## Aktywność na profilu rowerzysty (2026-08-14)
[`Models\RiderFeed`](../core/Models/RiderFeed.php) — „co ta osoba wniosła", sekcja
`#aktywnosc` zaraz pod mapą. Cztery źródła: wpisy w kronice (`event_recaps`), komentarze
(`event_comments`, z rozróżnieniem FAQ/odpowiedź organizatora), zdjęcia (`event_photos`)
i wgrane ślady (`edition_tracks`).
- **To nie jest Puls zawężony do osoby.** Puls odpowiada „co się dzieje", jego jednostką
  jest wyjazd i grupuje wpisy per wydarzenie („3 wpisy w kronice"); tutaj pytanie brzmi
  „kim ta osoba jest", więc każdy wpis stoi osobno, bo to konkretna rzecz, którą ktoś zrobił.
- **Treść, nie zdarzenia** — ta sama granica, którą Puls trzyma od Etapu 5. Nie ma
  „zapisał się", „wypisał się", „wszedł na stronę".
- **Bez okna czasowego**, inaczej niż Puls (21 dni): wpis sprzed roku jest w Pulsie szumem,
  a na profilu dokładnie tym, po co się przyszło.
- Zdjęcia **zwinięte** do jednego wpisu na wydarzenie i dzień — bez tego jedna sesja
  wrzucania 20 zdjęć wypchnęłaby z feedu wszystko inne (jak `Pulse::signups`).
- Bez własnej tabeli, jak Puls i kronika — nie ma czego backfillować ani rozjeżdżać
  ze źródłem.
- **Stronicowanie krokowe po 10, NA SERWERZE** (`?aktywnosc=N`, kotwica `#aktywnosc`).
  Różnica względem listy przejazdów obok mapy, która stronicuje w przeglądarce: tam
  wszystkie wiersze muszą być w DOM-ie, bo klik steruje mapą; tutaj wiersze to zwykłe
  linki, więc przy 200 wpisach do przeglądarki jedzie **10, a nie 200**. Krokowe, nie
  kursorowe jak Puls: tam feed rośnie w czasie rzeczywistym i wpis dopisany między
  stronami przesuwałby offset, tu historia jednej osoby jest zamknięta, a numer strony
  daje pozycję („11–20 z 200") i powrót. `MAX_SCAN = 300` na źródło ogranicza głębokość —
  profil, który potrzebuje więcej, potrzebuje filtrów, nie głębszego stronicowania.
  Numer strony jest zaciskany, więc `?aktywnosc=99`, `-3` i `abc` dają 200, nie błąd.

## Wysokość listy „Przejazdy ze śladem" wiązana z mapą (2026-08-14)
Pytanie usera: co się stanie przy 20 przejazdach. Zmierzone: lista **nie** rozciągała
strony (sztywne `max-height:230px` + wewnętrzne przewijanie), ale pokazywała **4,3
pozycji z 20** w kolumnie szerokiej na 280 px, stojącej obok mapy wysokiej na 420 px —
czyli pod listą zostawało 190 px pustego miejsca, a treść i tak trzeba było przewijać.
Wysokość kolumny wiąże teraz z mapą jedna zmienna `--rp-map-h`. **Uwaga na pułapkę:**
samo `align-items:stretch` NIE wystarcza — siatka rozciąga wiersz do najwyższego dziecka,
więc lista bez limitu ciągnęła kolumnę 864 px poniżej mapy (zmierzone), czyli robiła
dokładnie tę wąską ścianę, której miało nie być. Limit musi być jawny (`max-height`
na `.rp-side`). Po zmianie: 20 przejazdów → **6,5 widocznych**, 100 → **7,4**, dół kolumny
równo z dołem mapy, przewijanie wewnętrzne. Poniżej 900 px limit znika — tam kolumna
stoi pod mapą i nie ma z czym równać wysokości.

**Odwrócenie kierunku (2026-08-25, uwaga usera: „puste miejsce, gdzie mogłaby być
mapa").** Od kafli (migr. 051) i od panelu przewijanego zamiast stronicowanego (2026-08-24)
to NIE lista ma dopasowywać się do mapy, tylko mapa do kolumny: `align-items:start`
wyleciało z `.rp-mapwrap`, wiersz rozciąga się do wyższego z dwojga (domyślny stretch),
`.rp-map__box` jest kolumną elastyczną (`display:flex;flex-direction:column`), a `.rp-map`
dopełnia wysokość (`flex:1 1 auto`) z podłogą `--rp-map-h` (420 px) na wypadek krótkiej
listy. Pułapka z 2026-08-14 nie wraca, bo dziś nic nie ciągnie kolumny w nieskończoność:
lista ma własne przewijanie, a kafle statystyk są stałe. Poniżej 900 px kolumny stackują
się i podłoga 420 px załatwia wysokość mapy sama.

## Jeden przełącznik kontekstu na /odkrycia (2026-08-14)
Strona miała **dwa** przełączniki tego samego i statystyki, które ich nie słuchały
(uwaga usera): przycisk „Moje odkrycia" w hero prowadził na stronę, na której się już
było, ciemna karta obok miała „Zobacz aktywność społeczności", a zakładki
`Mapa osobista / Mapa społeczności` stały dopiero nad mapą i przełączały **wyłącznie
mapę** — pasek statystyk pokazywał liczby osobiste także wtedy, gdy patrzyło się na
mapę społeczności.
- Oba przyciski hero usunięte. Zostaje jedna kontrolka (`.disc-tabs--main`), przeniesiona
  **nad pasek statystyk** — bo rządzi teraz całym ekranem, a nie samą mapą. Zakładka pod
  statystykami sugerowała, że ich nie dotyczy, i dokładnie tak działała.
- **Statystyki są kontekstowe**: zakładka osobista to 5 kafli (Twoje odkrycia · Punkty ·
  Znane trasy · Regiony · Nowy teren), społeczności — 4 (Odkryte pola · W ostatnim
  miesiącu · Odkrywcy · Znane trasy), stąd modyfikator `.disc-stats--4`.
- Nagłówek nad mapą mówi, czyją mapę się widzi (był zawsze „Twoja mapa odkryć").
- Karta „Razem odkryliśmy" **znika na zakładce społeczności**: pasek niesie wtedy tę samą
  liczbę pól, a ta sama liczba dwa razy na ekranie czyta się jak dwie metryki. Na
  osobistej jest kontrapunktem („tyle Ty, tyle my"), dla gościa — jedynym miejscem, gdzie
  ta liczba pada z odpowiednią wagą.
- „Co dały ostatnie wyjazdy" (dane na wskroś osobiste) tylko na zakładce osobistej;
  „Znane trasy" w obu, bo katalog szlaków jest wspólny.

## Warstwy mapy odkryć (2026-08-13, czwarta warstwa 2026-08-15)
Wtedy cztery NIEZALEŻNE przełączniki (`.map-layers`), nie zakładki. **Od migr. `072`
(2026-08-26) lista nie jest już wpisana wprost w PHP** — warstwy (dziś pięć: Odkrycia,
Heatmapa, Ślady, Znane trasy, Skarby z dwoma dziećmi) i ich stan domyślny/opis leżą
w słowniku `map_layer`, rozwiązywanym przez `Models\MapLayer::tree()` względem kontekstu
strony; opisy mechanizmów niżej (kolory, progi, panele) zostają aktualne, zmienia się
tylko to, ŻE konfiguracja jest teraz danymi, nie kodem. Pełny opis: [`database.md`](database.md)
§„Słownik `map_layer`" i [`models.md`](models.md) → `MapLayer`.
- **Odkrycia** — mgła i odkryte pola. Zgaszona zostawia gołą mapę.
- **Heatmapa** — natężenie z `passes_count`. **DOMYŚLNIE WYŁĄCZONA** (decyzja usera):
  w stanie podstawowym pole ma znaczyć „odkryte", nie „popularne". Po zgaszeniu skala ma
  JEDEN stopień — informacja binarna, bez gradientu, który przy wyłączonej warstwie nie ma
  prawa nic mówić.
- **Znane trasy** — **KAFLE, klucz `kr`** (2026-08-20). Pełna geometria z plików GPX,
  rysowana przez `TileRenderer`, w panelu kafli śladów (pod warstwą pól). Do tej daty
  szła wektorem z `/api/discovery/trails`, czyli ze ŚRODKÓW PÓL siatki — przy polu
  ok. 500 m linia była zygzakiem obok drogi, a ludzie przychodzą tu zobaczyć, którędy
  szlak faktycznie idzie. **Klik w szlak wrócił tego samego dnia, ale INACZEJ**:
  kafel jest obrazkiem, więc trafienie liczy SERWER (`GET /api/discovery/trails/at`
  → `KnownRoute::atPoint`). Powrót do wektorowej warstwy trafień cofnąłby dokładnie
  to, po co kafle weszły — geometria znowu jechałaby do przeglądarki przy każdym
  przesunięciu, i to ta ze środków pól, czyli przesunięta względem narysowanej linii
  (klik w widoczny szlak potrafiłby chybić). Teraz leci jedno małe żądanie NA
  KLIKNIĘCIE, a trafienie liczy się na polach trasy — czyli na tym, czym trasa dla
  tego modułu jest. **Tolerancja zależy od powiększenia** (ok. 12 px przeliczonych na
  metry przez `TileGrid::metersPerPixel`, nie mniej niż 120 m): przy oddaleniu jeden
  piksel to setki metrów i wymaganie precyzji byłoby wymaganiem niemożliwego.
  Pudło NIE otwiera dymka „nic tu nie ma" — to byłaby kara za kliknięcie w tło.
  **Dymek mówi to samo, co karta trasy w sekcji „Znane trasy" pod mapą** (uwaga usera:
  miał mniej): nazwa · dystans · region · przewyższenie, potem POSTĘP tej osoby
  (procent, `X / Y pól`, pasek `.trail__bar`) i zdanie „Zalicza się sama, gdy Twoje
  przejazdy pokryją kolejne fragmenty". Gość widzi w tym miejscu „Załóż konto, żeby
  śledzić postęp" — „0%" dla kogoś bez konta nie jest informacją o nim. Te same klasy
  CSS co karta, bo to ten sam byt opisany w dwóch miejscach.
  **Przycisk w dymku musiał dostać własną regułę koloru**: `leaflet.css` ma
  `.leaflet-container a{color:#0078A8}`, co jest bardziej szczegółowe niż samo `.btn`,
  więc link-przycisk wychodził z niebieskim tekstem. Skarby tego nie miały, bo tam akcja
  jest `<button>`, a nie `<a>`.
  **Przewyższenie to migr. 063** (`known_routes.elevation_gain_m`): `Gpx::parse()` liczyło
  je przy każdym wgraniu, ale nie było gdzie zapisać, więc wynik leciał do kosza.
  Kolumna ZOSTAJE mimo zniknięcia dymka (2026-08-20) — pokazuje ją nagłówek strony trasy,
  a policzona raz przy wgraniu nic nie kosztuje.
  Kolumna jest NULL-owalna, bo **0 m to poprawna wartość** (płaska pętla po Żuławach),
  a trasa sprzed migracji ma po prostu „nie wiem" — dymek pokazuje wtedy sam dystans
  zamiast kłamać zerem. Backfill dla istniejących tras robi `run_migrations.php` zaraz
  po migracji (`KnownRoute::backfillElevation()`, ten sam wzorzec co slugi organizatorów
  po migr. 004) — bez osobnego skryptu, o którym da się zapomnieć.
  Dwie linie na trasę: szeroka biała pod spodem, cienka OLIWKOWA `#5A8F6B` na wierzchu
  (od 2026-08-15; wcześniej fiolet, ten sam co skarby — nie dało się odczytać, czy
  fioletowa rzecz jest trasą, czy punktem do znalezienia) — sama ciemna kreska ginie
  na mapie z lasami i drogami. **DOMYŚLNIE WŁĄCZONA od 2026-08-19**
  (do tej daty odwrotnie) — z tego samego powodu co skarby: szlak zaprasza, a nie opisuje
  przeszłość. Wyłączenie zapisuje się w adresie jako `trasy=0`, bo stan domyślny nie ma
  zaśmiecać linku (tak jak `skarby=0`).
- **Skarby** (Etap 8D) — punkty do znalezienia w terenie, z `/api/treasures`.
  **DOMYŚLNIE WŁĄCZONA**, odwrotnie niż heatmapa: ta jest dodatkiem do odpowiedzi
  „gdzie byłem", a skarb jest ZAPROSZENIEM — schowany za przełącznikiem nie zaprasza
  nikogo. Przy oddaleniu znaczniki zwijają się w pęczki
  (`Treasure::clustersInBounds`), a pozycja skarbu ukrytego jest przycięta do środka
  pola przez `Treasure::inBounds` — ta sama bramka co wszędzie indziej.
  **PĘCZEK ZACZYNA SIĘ OD DWÓCH** (2026-08-20, zgłoszenie usera: „pomimo tego, że jest
  jeden w grupie, pokazuje 1 — wystarczy ikonka skarbu"). Kółko z jedynką nie niosło
  żadnej informacji ponad tę, którą niesie sama pinezka, a przy okazji kłamało
  o położeniu: pęczek stoi na ŚRODKU POLA, skarb tam, gdzie stoi. Pole z jednym
  skarbem wraca więc jako zwykły skarb — jedna odpowiedź niesie i `clusters`,
  i `treasures`.
- **Skarby zdobyte** — DRUGA WARSTWA (2026-08-20, prośba usera), tylko dla zalogowanego:
  gość nie ma ani jednego znaleziska, więc dostałby przełącznik, który nigdy niczego nie
  zmienia. Zdobyty skarb jest **złoty**, niezdobyty zostaje biały — zieleń, którą miał
  wcześniej, znaczy w tym serwisie „odkryty teren" i występuje na mapie wszędzie
  (pola, ślady, linie tras), więc stan skarbu nie może mówić tym samym kolorem.
  **ROZRÓŻNIENIE ROBI SERWER** (parametr `stan=moje|nowe` → `Treasure::mineCondition`),
  mimo że przełącznik stoi w przeglądarce: przy oddaleniu odpowiedź to pęczki, czyli same
  liczby, a z „2 skarby, masz 1" nie da się po zgaszeniu jednej warstwy zrobić pojedynczej
  pinezki — pęczek nie niesie ani nazwy, ani prawdziwej pozycji. Filtr przed grupowaniem
  daje poprawny wynik w każdym z czterech stanów przełączników (obie zgaszone = zero
  żądań). Testy: `php tests/run.php skarby`.
  **BŁĄD TEGO KONTRAKTU, NAPRAWIONY 2026-08-23** (zgłoszenie usera z profilu rowerzysty:
  „chciałem odchaczyć skarby znalezione przez tę osobę i nic się nie dzieje, request
  zawsze zwraca skarby"). Cztery stany przełączników są poprawne tylko tam, gdzie
  PRZEŁĄCZNIKI SĄ DWA. Profil rowerzysty ma JEDEN („Skarby — znalezione przez tę osobę"),
  więc jego zgaszenie dawało parę `treasures=false, treasuresFound=true`, czyli
  `stan=moje` — „pokaż wyłącznie MOJE" zamiast „nie pokazuj nic". A ponieważ dla
  oglądającego bez konta `Treasure::mineCondition` filtr pomija (nikt niezalogowany nie
  ma znalezisk), odpowiedź wracała KOMPLETNA i przełącznik wyglądał na martwy.
  Ten sam błąd miały `/odkrycia`, strona trasy i strona wyjazdu **dla gościa** — tam
  drugi przełącznik też się nie renderuje, a strony wpisywały wtedy `treasuresFound: true`
  na sztywno.
  **Zasada po poprawce**: warstwa „skarby zdobyte" istnieje jako WYBÓR tylko wtedy, gdy
  wołający poda ją wartością logiczną. Strona bez tego przełącznika przekazuje `null`
  (albo nic) i wtedy warstwa CHODZI ZA „Skarbami" — gasną i zapalają się razem, a stan
  „pokaż tylko moje" nie może powstać przypadkiem. Sprawdzenie jest po TYPIE, nie po
  obecności klucza, właśnie po to, żeby `null` znaczyło „tej warstwy tu nie ma".
  Zweryfikowane żywo: na profilu zgaszenie „Skarbów" daje ZERO żądań do API i zero
  pinezek, a na `/odkrycia` zalogowanego dalej działają cztery niezależne stany.
- **PEŁNY EKRAN — przycisk w PRAWYM DOLNYM rogu każdej mapy** (2026-08-23, zgłoszenie
  usera: „w mapach brakuje nam jeszcze ikonki w rogu ekranu (prawy dolny) full screen
  mapy, obecnie to strasznie małe mapy"). Kontrolka wpięta w `ridemoreCreateMap()`
  (`assets/js/gpx-map.js`), czyli w fabrykę, przez którą przechodzi KAŻDA mapa
  w serwisie — odkrycia, profil, trasa, wydarzenie, kronika, lista wyjazdów, picker
  w kreatorze i panel skarbów dostają ją bez pamiętania o niczym (ta sama zasada co
  przy wspólnej kontrolce warstw).
  **Rozciągamy WRAPPER, nie kontener Leafletu**: przełącznik warstw i legenda są jego
  RODZEŃSTWEM (`map-layers.php` kładzie je w rodzicu z `position: relative`), więc
  rozciągnięcie samego kontenera zabierałoby je z ekranu dokładnie wtedy, gdy jest
  najwięcej do oglądania. `ridemoreFullscreenBox()` bierze rodzica, jeśli ten zawiera
  `.map-layers` albo `.hex-legend`; w przeciwnym razie samą mapę.
  **Bez wtyczki** (leaflet.fullscreen): natywne Fullscreen API daje Escape, ukrycie
  paska przeglądarki i obsługę systemową za darmo. **Ścieżka zastępcza** (`.is-map-fs`,
  `position: fixed`) jest dla iOS Safari, które nie pozwala na pełny ekran zwykłym
  elementom, i dla WebView aplikacji mobilnej, który potrafi odmówić — bez niej przycisk
  istniałby na telefonie i nie robił nic. Wejście w nią następuje po odrzuceniu obietnicy
  z `requestFullscreen`, a wyjście Escape'em.
  Po każdej zmianie leci `map.invalidateSize()` z opóźnieniem — Leaflet mierzy kontener
  przy tworzeniu i sam nie zauważa, że urósł do całego ekranu (bez tego kafle zostają na
  starym obszarze, a reszta jest szara).
### „Ostatnia aktywność" przebudowana na oś czasu (2026-08-24)
Zgłoszenie usera: „sekcja »Co dały ostatnie wyjazdy« powiela informacje z »Ostatniej
aktywności« (…) klikanie na aktywność nic nie wnosi, a powinno (…) musi mieć paginację,
ikonki, brakuje daty (…) są tylko punkty, a nie ma odkryć hexów (…) ten sam moduł będzie
możliwy do zastosowania nawet na mapie społeczności, wtedy należy dodać przez kogo".

**DWIE POŁÓWKI TEJ SAMEJ RZECZY ZASTĄPIONE JEDNĄ CAŁOŚCIĄ.** Panel na mapie pokazywał
naliczenia z rejestru punktów (bez daty, bez pól, bez nazwy wyjazdu, bez sposobu, żeby
w nie kliknąć), a sekcja pod mapą — karty z datą i polami, ale bez związku z mapą.
Teraz jest [`partials/ride-feed.php`](../views/web/partials/ride-feed.php): wiersz niesie
ikonę (wyjazd czy solo), nazwę, **datę**, dystans, region, **odkryte pola** i punkty —
i prowadzi tam, gdzie ten przejazd był.

- **REGRESJA ZŁAPANA PRZEZ USERA I NAPRAWIONA TEGO SAMEGO DNIA**: pierwsza wersja tej
  listy brała WYŁĄCZNIE przejazdy, więc ze „Ostatniej aktywności" wypadły **znalezione
  skarby** („usunąłeś zdobycia skarbów z aktywności?!"). Poprzedni panel czytał cały
  rejestr naliczeń (`PointLedger::recentForUser`), więc pokazywał jedno i drugie — a przy
  przebudowie na listę przejazdów nikt tego nie sprawdził. Skarb bywa zdobyty **bez
  przejazdu** (zeskanowana wlepka, potwierdzona lokalizacja), więc nie da się go pokazać
  jako dopisku do przejazdu: jest własnym zdarzeniem na osi czasu, z ikoną w kolorze
  skarbów. **Znaleziska z jednego dnia grupują się w jeden wiersz** („21 skarbów, +1 050"),
  bo siedem osobnych wierszy po jednym przejeździe wypychało z listy same przejazdy
  (zmierzone na żywych danych). **Na mapie społeczności nazwa i dokładna pozycja wychodzą
  wyłącznie dla skarbów jawnych albo dla samego znalazcy** — inaczej publiczna oś czasu
  byłaby najprostszym obejściem zagadki: wystarczyłoby poczekać, aż ktoś znajdzie.
- **Dane**: [`Discovery::recentActivity(?userId, limit)`](../core/Models/Discovery.php) —
  jedno zapytanie dla obu map. `null` = społeczność (dokłada autora), id = mapa osobista.
  Do każdego przejazdu dochodzi **prostokąt na mapie** policzony z jego pól
  (`rider_activity_cells` → `DiscoveryGrid::cellCenter`) JEDNYM zapytaniem na całą listę,
  nie jednym na wiersz.
- **Klik w wiersz ustawia kadr** (`map.fitBounds`, `ridemoreRideFeed` w `discovery-map.js`),
  a wskazany wiersz zostaje podświetlony, żeby dało się wrócić do niego wzrokiem.
- **PANEL BIERZE CAŁĄ WOLNĄ WYSOKOŚĆ, LISTA SIĘ PRZEWIJA** (poprawka tego samego dnia,
  uwaga usera: „tracimy na super funkcjonalność przez to, że są tylko 3 aktywności i trzeba
  przełączać paginacją; jeśli będzie dziennie 200 aktywności, to co mi z takiego okna").
  Pierwsza wersja stronicowała po trzy — czyli dawała okno mniejsze, niż pozwalała mapa.
  Teraz wysokość dopasowuje FLEXBOX, nie JavaScript: kolumna `.disc-panels` sięga od góry
  do dołu mapy, panel skarbów bierze swoją naturalną wysokość (sufit 45%), a feed
  **całą resztę** (`flex:1 1 0`, podłoga 200 px). Zwinięcie panelu skarbów albo zgaszenie
  ich warstwy natychmiast oddaje miejsce liście — zmierzone: 275 px / 3 wiersze przy
  rozwiniętych skarbach, 482 px / 5 wierszy po zwinięciu, 619 px / 7 wierszy przy zgaszonej
  warstwie (okno 900 px). Wczytujemy 50 zdarzeń, reszta dojeżdża przewijaniem.
- **PANEL SKARBÓW IDZIE ZA SWOJĄ WARSTWĄ** (`data-layer-panel="treasures,treasuresFound"`,
  obsługa w `discovery-map.js`): panel opisujący warstwę zgaszoną na mapie mówi o czymś,
  czego nie widać, i zabiera miejsce liście, którą widać. Wiązanie atrybutem, nie po `id` —
  to zachowanie MAPY, więc zadziała dla każdego panelu dołożonego w przyszłości.
- **TRZY POMIARY, TRZY PUŁAPKI CSS w jednym module** (wszystkie złapane pomiarem, nie okiem):
  (1) `display:flex` bije `[hidden]{display:none}` z przeglądarki — bez `.disc-panel[hidden]`
  i `.disc-feed__row[hidden]` zgaszony panel dalej zajmował 234 px, a ukryte wiersze
  zostawiały puste miejsce; (2) reguła z podwójną klasą (0,2,0) bije mobilną `.disc-panel`
  (0,1,0) spod media query — `min-height:200px` rozpychało ZAMKNIĘTĄ szufladę z 46 px do
  200 px, więc wszystkie wysokości kolumny stoją w `@media(min-width:681px)`;
  (3) procentowy `max-height` na panelu liczył się względem kolumny o wysokości AUTO, czyli
  był ignorowany — otwarta szuflada rosła do 3257 px, więc sufit przeniósł się na kolumnę,
  która mierzy się względem mapy.
- **Panele w kolumnie** (`.disc-panels`): mapa społeczności ma teraz przejazdy I skarby,
  a dwie nakładki przyklejone do tego samego rogu leżałyby na sobie. Kolumna układa je
  pod sobą na desktopie i piętrzy jako paski szuflad na telefonie — jeden mechanizm
  zamiast dwóch zestawów pozycji.
- **Więcej miejsca dla mapy**: nagłówek „Twoja mapa odkryć" zniknął (powtarzał nazwę
  zakładki), zakładki główne urosły i są jedynym nagłówkiem nad mapą, a sama mapa ma teraz
  `min(76vh, 700px)` zamiast stałych 520 px (zmierzone: 654 px przy oknie 860 px wysokości).

Testy: `php tests/run.php widoki` (cztery przypadki: wiersz niesie datę/pola/punkty/zakres,
znaleziska są na osi czasu razem z przejazdami, ukryty skarb nie zdradza nazwy na mapie
społeczności, mapa osobista pokazuje wyłącznie własne).

- **PANEL PRZY MAPIE DA SIĘ ZWINĄĆ** (2026-08-24, prośba usera: „wprowadź na wszystkich
  mapach możliwość zwinięcia `.disc-panel`, dla usera na Ostatnie aktywności oraz
  w społeczności do Skarby"). Panel jest nakładką NA MAPIE i czasem zasłania dokładnie
  to, na co trzeba spojrzeć, a jego treść nie musi być przed oczami cały czas.
  Kontrolkę dokłada `discovery-map.js` (`ridemoreSetupPanels`), więc ma ją KAŻDA mapa
  w serwisie — także panel, który dopiero powstanie. Do tej daty kod szuflady siedział
  w `discovery.php` i dotyczył wyłącznie tamtego ekranu.
  **Dwie kontrolki, bo to dwa różne zachowania**: na telefonie panel jest SZUFLADĄ od
  dołu, domyślnie zamkniętą (`.is-open`), a na desktopie stoi otwarty w rogu i można go
  ZWINĄĆ (`.is-folded`) do samej pastylki z nazwą. Jedna klasa na oba przypadki
  znaczyłaby dwa różne stany domyślne pod tą samą nazwą.
  Wybór **zapamiętuje `localStorage`** per panel: zwinięcie, które wraca po każdym
  przeładowaniu, nie jest zwinięciem, tylko drganiem.
  Panel skarbów (zaczyna się od zakładek, nie ma `<h3>`) dostaje przy okazji **podpis,
  którego na desktopie dotąd nie miał** — przycisk zwijania jest tam własnym paskiem.
  **Reguły stanu zwinięcia stoją w `@media(min-width:681px)` i to nie jest ostrożność,
  tylko wynik pomiaru**: bez tego `.disc-panel.is-folded` (0,2,0) bije telefonową
  `.disc-panel` (0,1,0) spod media query i zdejmuje szufladzie `max-height` — zmierzone:
  rozpychała się do 915 px, czyli zasłaniała cały ekran.
- **KADR STARTOWY NALEŻY DO STRONY, NIE DO MAPY** (2026-08-19, uwaga usera: „centrujesz
  mapę w środku Polski, a nie w miejscu wystąpienia mapy"). `discovery-map.js` przyjmuje
  `options.bounds` = prostokąt policzony NA SERWERZE z tego, co dana mapa pokazuje:
  `Discovery::boundsFor(?userId)` (odkrycia własne albo wspólne), `KnownRoute::boundsFor(id)`
  (pola trasy), `GpxGeometry::boundsFor(hashes)` (ślady na profilu rowerzysty). Wcześniej
  każda mapa startowała w środku kraju przy oddaleniu 6 i dopiero po pierwszej odpowiedzi
  próbowała się dociągnąć (`fitToCells`) — co było błędne potrójnie: pierwsze żądanie szło
  dla całej Polski, obraz skakał, a dociągnięcie i tak umiało trafić wyłącznie w to, co
  wpadło do pierwszego (za szerokiego) kadru. Trzy rzeczy, bez których to nie działa,
  wszystkie zmierzone na żywo:
  - **`animate: false`** — bez tego Leaflet ANIMUJE dojście do kadru, czyli pokazuje
    dokładnie ten przelot ze środka kraju, którego mieliśmy się pozbyć; a że animacja
    wisi na `requestAnimationFrame`, w karcie bez kompozycji klatek mapa zostawała na
    widoku domyślnym **na stałe**, mimo poprawnie wywołanego `fitBounds`.
  - **kadr nakładany dopiero przy sensownym rozmiarze kontenera** (próg 40 px,
    powtarzane z `ResizeObserver`) — `fitBounds` na kontenerze wysokim na kilka pikseli
    zwraca oddalenie MAKSYMALNE: zmierzone żądania przy zoomie 18 dla kadru o zerowej
    wysokości.
  - **wstrzymanie pierwszego żądania** do czasu nałożenia kadru — inaczej i tak poszłoby
    pytanie o środek kraju, czyli o obszar, którego nikt nie ogląda.
  Efekt uboczny, którego szukaliśmy osobno: komplet żądań leci teraz RAZ, nie dwa razy
  (dawniej `fitBounds` po odpowiedzi wywoływał `moveend` → drugą rundę).
- **JEDNA KONTROLKA NA CAŁĄ APLIKACJĘ** — [`partials/map-layers.php`](../views/web/partials/map-layers.php)
  (2026-08-19, prośba usera: „nie chcę, żeby w aplikacji używano czegoś innego niż
  standardowej kontrolki mapy"). Wcześniej ten sam blok stał skopiowany na `/odkrycia`
  i na profilu rowerzysty, a **strona trasy `/trasy/{slug}` nie miała go wcale** — miała
  własną, gołą mapę z samym śladem z pliku: bez warstw, bez legendy, bez skarbów. Dziś
  wszystkie trzy renderują ten sam partial, a do strony należą wyłącznie podpisy warstw
  i stan domyślny. (Przebieg rysował się wtedy jeszcze Z PLIKU, `ridemoreAddGpxTrack` —
  od 2026-09-04 idzie kaflem `kr-{id}`, a od 2026-09-10 kafel ten JEST warstwą „Znane
  trasy" tej strony; patrz „Mapa strony trasy pokazuje TĘ trasę".) Kadr liczy
  `KnownRoute::boundsFor()` NA SERWERZE (`fitToCells: false`), bo mapa pyta o pola dla
  widocznego prostokąta zaraz po starcie: bez tego pierwsze żądanie leciałoby dla całego
  kraju, a obraz przeskakiwałby po dojściu pliku.
- **Kontrolka ZWIJANA (`<details>`), lewa kolumna pod powiększaniem** — poprawka
  2026-08-14 po dwóch iteracjach. Wersja 1: stały stos checkboxów w prawym GÓRNYM rogu,
  czyli pod „Ostatnią aktywnością" — dwie nakładki na sobie. Wersja 2 („każdy róg jedna
  rola", warstwy w prawym dolnym): rozwijały się w górę i spotykały z panelem aktywności
  w środku prawej kolumny — **zmierzone nachodzenie 236×121 px**.
  **Mapa dzieli się na KOLUMNY, nie na rogi**: lewa = sterowanie (zoom, warstwy, legenda),
  prawa = informacja (aktywność i co przyjdzie po niej). Dwie nakładki w jednej kolumnie
  będą się bić zawsze, niezależnie od tego, od której krawędzi rosną — nowa nakładka
  wybiera KOLUMNĘ wg tego, czy steruje, czy informuje.
- **Zwijanie odpowiada na „co jeśli dojdą nowe warstwy"** (pytanie usera): stały stos zjada
  kawałek mapy na stałe za każdą pozycję, także gdy nikt jej nie używa. Zwinięta kontrolka
  kosztuje jeden przycisk niezależnie od liczby warstw; rozwinięta ma `max-height:300px`
  i własne przewijanie. Sprawdzone symulacją na **9 warstwach**: panel zostaje 300 px
  wysokości, przewija 470 px treści, mieści się w mapie i zostawia 47 px nad legendą.
  `<details>`, nie własny JS — otwieranie, klawiatura i stan `open` za darmo z przeglądarki.
- Każda warstwa ma podpis pod nazwą („gdzie już byłeś", „jak często tędy jeżdżą") — bez
  niego „Odkrycia" i „Heatmapa" brzmią jak to samo dla kogoś, kto wchodzi pierwszy raz.
- Stan warstw ląduje w adresie (`replaceState`, nie push — przełącznik nie jest krokiem
  nawigacji), żeby dało się podesłać komuś konkretny widok. Przełączenie mgły i heatmapy
  przerysowuje z danych w pamięci, BEZ pytania serwera; tylko trasy mają własny endpoint.
- **Migracja 048 — `known_route_cells.sort_order`**: tabela miała tylko parę
  (route_id, cell_id) i żadnej informacji o przebiegu. Do liczenia postępu to wystarczało
  (postęp to przecięcie zbiorów), ale linia rysowana bez kolejności była zygzakiem po
  kolejności wstawiania. Wartość bierze się z `DiscoveryGrid::cellsForTrack()`, które i tak
  zwracało pola w kolejności śladu. Trasy sprzed migracji mają NULL i **nie rysują się** —
  lepiej nie narysować, niż narysować zygzak i nazwać go szlakiem. Naprawa: przycisk
  „Przelicz pola" w panelu (obiecany `backfill_known_route_order.php` nigdy nie powstał).

## Znane trasy: niewidoczne na mapie i nieedytowalne (2026-08-19, migr. 061–062)
Zgłoszenie po wdrożeniu na produkcję: **wgrana trasa nie pokazuje się na mapie**, to samo
na teście. Za jednym objawem stały dwie niezależne przyczyny, obie w miejscu, w którym
„nie widzę swojej trasy" jest jedynym możliwym wnioskiem użytkownika.

- **Przyczyna 1 — filtr kadru przez cudze odkrycia (błąd).**
  `KnownRoute::geometryInBounds()` zawężało trasy do widocznego prostokąta przez
  `JOIN discovery_cell_totals` — tabelę pól odkrytych PRZEZ KOGOKOLWIEK. JOIN był tam po
  współrzędne `cell_q/cell_r` (po spakowanym `cell_id` nie da się filtrować prostokątem),
  ale przy okazji działał jak **warunek istnienia**: trasa przez teren, po którym nikt
  jeszcze nie jechał, nie miała tam ani jednego wiersza i wypadała w całości. Zmierzone
  na bazie DEV przed naprawą: trasa z 9 polami, `is_active = 1`, komplet `sort_order` →
  **0 tras** w odpowiedzi dla kadru dokładnie ją obejmującego; druga trasa, po której ktoś
  jeździł — wracała normalnie. Odwrócenie zależności jest tu sednem: **widoczność szlaku
  nie ma prawa zależeć od tego, czy ktoś go już przejechał** — to jest warstwa
  odpowiadająca „co jeszcze możesz zaliczyć". Migr. 061 dokłada `cell_q/cell_r` na
  `known_route_cells` (ten sam wzorzec co migr. 040), 062 je wypełnia, a filtr idzie po
  własnych polach trasy.
- **Przyczyna 2 — warstwa domyślnie zgaszona.** Nawet po naprawie trasy nie zobaczyłby
  nikt, kto nie odhaczył „Znane trasy" w panelu warstw. Decyzja usera: **włączona
  domyślnie**, jak skarby (uzasadnienie przy warstwach wyżej).
- **Backfill jest migracją, nie osobnym skryptem** (inaczej niż migr. 047): to migracja
  naprawcza, a taka, po której trzeba jeszcze coś uruchomić, zostawia błąd otwarty.
  Osobny PLIK (062) dlatego, że `run_migrations.php` rejestruje całymi plikami i przy
  błędzie w środku nie rejestruje nic — ALTER razem z UPDATE-em groziłby tym, że po
  nieudanym przebiegu kolejny zobaczy „Duplicate column", uzna migrację za zastosowaną
  i po cichu pominie backfill.
  Rozpakowanie `cell_id` to czysta arytmetyka bitowa, więc SQL wystarcza — zweryfikowane
  wiersz po wierszu względem `DiscoveryGrid::decode()`, łącznie z ujemnymi współrzędnymi
  (półkula zachodnia i południowa: MySQL zwraca z operatorów bitowych BIGINT UNSIGNED,
  stąd `CAST(... AS SIGNED)` przed odjęciem znaku).

**Edycja trasy** (drugie zgłoszenie: „mam tylko wyłączenie i podmianę zdjęcia").
- `POST /admin/znane-trasy/{id}/edytuj` → `KnownRoute::update()` — nazwa, opis, region,
  zdjęcie, bonusy per trasa. Whitelist `KnownRoute::EDITABLE` **nie zawiera `slug`**
  (adres, który raz gdzieś poszedł, ma dalej działać), pól wyprowadzanych z GPX-a
  (`gpx_url`, `distance_km`, `cells_total` — ręczna korekta liczby pól rozjechałaby
  procent postępu z faktami) ani `is_active` (włącza i wyłącza osobny przycisk, jedna
  kolumna = jedna droga zapisu).
- **Podmiana przebiegu** (`KnownRoute::replaceGpx()`) zachowuje id i slug, więc **nikt nie
  traci progów** — inaczej niż jedyna dotąd metoda poprawienia śladu, czyli usunięcie
  trasy i dodanie jej od nowa. Punkty doprowadzane są do zgodności w OBIE strony:
  osoby liczone są PRZED i PO wymianie pól, a wynik to suma zbiorów — ktoś, kto miał pola
  starego przebiegu i nie ma ani jednego nowego, nie wyszedłby z zapytania „po"
  i zostałby z progiem bez pokrycia w faktach.
- **„Przelicz pola"** (`/{id}/przelicz`) to ta sama operacja na pliku, który trasa już ma —
  naprawa tras sprzed migr. 048. Panel mówi wprost, które trasy jej potrzebują
  (`KnownRoute::unorderedCounts()`); wcześniej admin nie miał skąd wiedzieć, czemu jego
  trasa się nie rysuje.
- **Jeden formularz na dodawanie i edycję** — [`partials/known-route-form.php`](../views/web/partials/known-route-form.php).
  Dwa osobne szablony rozjechały się już raz: dodawanie miało region i opis, a późniejsza
  obsługa nie miała czym ich zmienić. Osobna akcja `/{id}/zdjecie` (migr. 046) zniknęła —
  zdjęcie jest polem tego formularza. Bonusy są **tylko w edycji**: punkty za zaległości
  nalicza `awardBacklog()` WEWNĄTRZ `createFromGpx`, czyli zanim wartość z formularza
  zdążyłaby trafić do bazy.

**Panel jako LISTA, nie stos formularzy** (poprawka tego samego dnia, pytanie usera
„a jak będę miał 200 tras?"). Pierwsza wersja edycji siedziała w rozwijanym `<details>`
przy każdym wierszu, a lista wypisywała wszystkie trasy naraz — przy dwustu trasach to
dwieście formularzy w jednym dokumencie, każdy z pełną listą regionów w `<select>`,
i zero sposobów, żeby dojść do konkretnej trasy.
- Ekran przebudowany na wzorcu [`users-admin.php`](../views/web/pages/users-admin.php) —
  ten sam rodzaj zadania (katalog danych referencyjnych, po którym trzeba UMIEĆ SIĘ
  PORUSZAĆ), więc ten sam `.op-head` + `.trust-bar` + `.disc-tabs` + `.adm-table` +
  prev/next. `KnownRoute::search()` ma nawet ten sam kształt wyniku co
  `UserAdmin::search()`, żeby stronicowanie w widoku było tym samym kodem.
- **Szukanie po nazwie, slugu i regionie.** Slug, bo admin przychodzi tu z adresu, który
  mu ktoś podesłał; region, bo „pokaż te bieszczadzkie" jest naturalnym pytaniem przy
  dużym katalogu.
- **Filtr „Do przeliczenia" pokazuje USTERKĘ, nie kategorię**: tyle tras nie rysuje się
  na mapie, bo ich pola nie mają kolejności wzdłuż śladu. Przy dwustu trasach nie da się
  tego wypatrzeć okiem, a to jedyna awaria, którą ten ekran potrafi sam zdiagnozować
  i sam naprawić („Przelicz pola").
- **Sortowanie z zamkniętej listy** — wartość idzie z adresu, więc nigdy nie trafia do
  SQL-a wprost (test pilnuje, że nieznana wartość spada do porządku domyślnego).
- **Stan listy jedzie ze wszystkim**: w linkach do edycji (`?q=&filtr=&sort=&strona=`)
  i w polach `wroc_*` każdego formularza akcji. Bez tego poprawienie trasy znalezionej
  na trzeciej stronie wyników odsyłałoby na początek katalogu — czyli kazałoby szukać jej
  od nowa po każdej zmianie. `strona=1` nie trafia do adresu: to ten sam widok co goły
  adres, a dwa adresy na jeden widok psują historię przeglądarki.
- **Przy wierszu zostaje tylko „Włącz/Wyłącz"** — jedno kliknięcie i najczęstsza czynność.
  Wszystko inne (przelicz, usuń) mieszka na stronie trasy, bo wymaga przeczytania, co robi.
- Testy: [`tests/znane_trasy_test.php`](../tests/znane_trasy_test.php) — 10 przypadków,
  pierwszy jest testem tamtego błędu (trasa, której nikt nie przejechał, MUSI być widoczna).
  Dodawanie tras (warianty ilości informacji, slugi, progi parsera GPX):
  [`tests/dodawanie_tras_test.php`](../tests/dodawanie_tras_test.php) — 13 przypadków.

## Przejazd solo, prywatność domu i panel punktów (Etap 8A, dokończenie 2026-08-13)
- **Przejazd solo** (migr. 049) — ślad BEZ wydarzenia. Do tej pory żeby cokolwiek odkryć,
  trzeba było zapisać się na wyjazd, pojechać i potwierdzić obecność; codzienna runda po
  okolicy dla tego modułu nie istniała. `rider_activities` zostaje TĄ SAMĄ tabelą: solo to
  kolejne ZRÓDŁO (`source_code = 'solo'`), nie nowy byt. Od 2026-08-23 to samo wejście
  obsługuje import z Garmin Connect — patrz „Import przejazdów z Garmin Connect" niżej.
  Wejście:
  [`RiderActivity::recordSolo()`](../core/Models/RiderActivity.php), UI w „Moich przejazdach".
- **Idempotencja: UNIQUE `(user_id, gpx_hash)`**, nie sprawdzenie w kodzie. Hash z ZAWARTOŚCI
  pliku. Klucz na PARZE, nie na samym hashu — dwie osoby mogą uczciwie wgrać ten sam plik
  (jeden licznik na dwoje) i obie mają prawo do swoich pól. Powtórka zwraca `null`, a widok
  mówi „ten ślad już był policzony — nic się nie zdublowało"; to nie jest błąd.
- **PRYWATNOŚĆ (§27)** — pola w promieniu `privacy.home_trim_radius_m` (**50 m** od 2026-08-30,
  wcześniej 400 m — patrz nota niżej) od początku
  i końca śladu solo **nie są zapisywane**. Przycinamy PUNKTY przed liczeniem pól
  (`DiscoveryGrid::trimEnds`), nie pola po fakcie: usunięcie pola zostawiłoby je
  w `rider_activity_cells` i miejsce zamieszkania dałoby się odczytać z warstwy heatmapy.
  Dane, których nie ma, nie wyciekną żadnym przyszłym endpointem. Dystans i przewyższenie
  liczymy z PEŁNEGO śladu — kilometry to fakt o człowieku, nie o jego adresie.
- **PROMIEŃ ZMNIEJSZONY DO 50 m (decyzja usera 2026-08-30)** — po pierwszej prawdziwej
  jeździe nagranej apką: „początek i koniec trasy nie jest zaliczony (…) często punkt
  startowy to po prostu rozpoczęcie nagrywania, poza tym punkt końcowy tam, gdzie skarb,
  też nie zaliczony hex". Zarzut jest trafny i dotyczy samej konstrukcji przycięcia:
  działa ono **bezwarunkowo**, a nigdzie nie trzymamy adresu, więc kod nie odróżnia startu
  spod domu od startu z parkingu w lesie — a w apce „start" to w ogóle tylko miejsce,
  w którym ktoś dotknął „Nagraj". 400 m kosztowało po jednym-dwóch polach z każdego końca
  **oraz skarb stojący na mecie przejazdu**: punkty pod nim wypadały razem z końcówką śladu,
  a `treasures.default_radius_m` to 150 m. Świadomy koszt zmiany: 50 m to dziesiąta część
  pola, więc jako ochrona adresu ten promień jest już symboliczny — chroni próg, nie budynek.
  Docelowym rozwiązaniem jest **zadeklarowany punkt domowy** (przycinaj tylko wokół niego
  i tylko temu, kto tego chce); dopóki go nie ma, ta liczba jest kompromisem, nie gwarancją.
  Pilnują tego dwa testy w `tests/przejazdy_solo_test.php` — jeden bierze promień
  z konfiguracji zamiast ze stałej, drugi wywali się, gdyby promień domowy urósł powyżej
  promienia zaliczenia skarbu. **Zmiana działa tylko na NOWE przejazdy** — wcześniej
  policzone mają swoje pola zapisane i nie odzyskają tych z końcówek.
- **Wgrany GPX sam potwierdza obecność** (user: „nie muszę osobno deklarować"). Idzie przez
  `EventAttendance::declare()`, nie zapisem wprost — tamta metoda pilnuje peletonu
  i przeliczenia odkryć. Zgodność sprawdza `EditionTrack::coverageAgainstPlanned()` po TEJ
  SAMEJ siatce heksów, z której liczą się odkrycia; **mianownikiem jest wgrany ślad**, nie
  planowany („ile z tego, co przejechałeś, leży na trasie"), więc ktoś, kto uczciwie zawrócił
  z połowy, ma 100% zgodności. Próg **60%**, bo ślad zaczyna się pod domem i dojazd na
  zbiórkę nigdy nie leży na trasie. Poniżej progu plik jest PRZYJMOWANY, tylko obecność nie
  jest potwierdzana automatycznie — ktoś mógł jechać wariantem. Przy okazji poluzowana
  bramka uploadu: wystarczy potwierdzony ZAPIS, nie potwierdzona obecność (stary warunek
  przeczył całej tej zmianie).
- **`Gpx::parse()` czyta `<time>`** — `startedAt`, `elapsedSeconds`, `hasTimestamps`.
  Punktacja ich NIE UŻYWA i nie ma używać; są po to, żeby przejazd solo miał kiedy się odbyć
  (bez turnusu nie ma skąd wziąć daty) i żeby odróżnić zapis z licznika od trasy z planera.
  Świadomie BEZ „czasu w ruchu" — wymagałby progu prędkości, czyli kolejnej liczby do
  skalibrowania, której nic nie używa.
- **Panel `/admin/punkty`** ([`PointsController`](../core/Controllers/Admin/PointsController.php))
  — pokazuje konfigurację z przykładami liczonymi TYM SAMYM kodem, który nalicza naprawdę
  (liczby przepisane do widoku rozjechałyby się przy pierwszej zmianie), i pozwala ustawić
  bonusy per trasa (migr. 043) i per wydarzenie (migr. 044) — jedyne miejsce w module,
  gdzie admin musiał dotąd tknąć SQL. Plus podgląd ostatnich naliczeń z rejestru.
  **Od migr. 053 edytuje też stawki globalne** — patrz sekcja niżej.

### Stawki punktacji w panelu (migr. 053, 2026-08-14)
Pierwsza wersja panelu świadomie NIE dotykała konfiguracji globalnej: wartości miały
zostać w `core/discovery.php`, bo są regułami gry i mają przechodzić przez wdrożenie.
To było dobre, dopóki stawki ustalało się raz przy projektowaniu — przestało być, gdy
trzeba je kalibrować na żywym ruchu (prośba usera: „przenieśmy konfigurację punktową
za każde pole i inne rzeczy do tabeli").
- **Tabela NADPISUJE plik, nie zastępuje go.** `core/discovery.php` zostaje źródłem
  wartości domyślnych i uzasadnień (stoi w nim m.in. sprawdzian proporcji między
  Discovery a Trails, którego nie da się zapisać w kolumnie), a `scoring_settings`
  trzyma **wyłącznie klucze realnie zmienione**. Pusta tabela znaczy „jak przed
  migracją", a skasowanie wiersza to powrót do domyślnej wartości BEZ potrzeby jej
  znajomości. Scalanie robi [`ScoringSettings::applyTo()`](../core/Models/ScoringSettings.php),
  wołane z `DiscoveryScoring::config()`; po zapisie `forgetConfig()` zrzuca cache żądania.
- **Klucz jest ŚCIEŻKĄ** w konfiguracji (`discovery.points_per_new_cell`,
  `trails.threshold_count`) — płasko, bo parametrów jest kilkanaście i będą przybywać,
  a kolumna na każdy znaczyłaby migrację przy każdym nowym.
- **Biała lista `ScoringSettings::EDITABLE`, nie czarna.** Konfiguracja zawiera też
  rzeczy, które NIE są stawkami i których zmiana przez formularz byłaby awarią, a nie
  kalibracją: promień przycięcia śladu przy domu (prywatność) i limity ochronne zapytań.
  Lista mieszka w modelu, bo to reguła domenowa („co wolno stroić"), a nie „co pokazuje
  formularz"; etykiety i podpowiedzi jadą razem z nią do panelu, żeby admin widział,
  co ustawia, a nie ścieżkę w tablicy.
- Panel pokazuje „było / jest" — wartości domyślne bierze z `DiscoveryScoring::defaults()`
  (sam plik, z pominięciem nadpisań). Skarby korzystają z tego samego mechanizmu:
  punkty domyślne i promień zaliczenia są stawkami, nie stałymi w kodzie.
- **Testy spójności (8A/9), wynik**: odtworzenie `discovery_cell_totals` daje wiersz w wiersz
  IDENTYCZNY rezultat co budowanie przyrostowe (1197 pól); powtórne `PointLedger::award()`
  dla istniejącego źródła odbija się o klucz i nie zmienia ani liczby wpisów, ani sumy;
  agregacja mapy jest BEZSTRATNA — suma `riders`/`passes` jest ta sama na wszystkich pięciu
  poziomach oddalenia i równa sumie w bazie.

### Wartość znanej trasy — z długości, dzielona równo (2026-09-03)
Zgłoszenie usera, dwie iteracje tego samego dnia:

1. **Trasa 1000 km i trasa 50 km płaciły identycznie**, dopóki admin nie wpisał ręcznej
   wartości — nowa trasa z włączonym bonusem, ale bez nadpisania, brała płaską globalną
   drabinkę bez związku z długością. Naprawa: `trails.points_per_km` w
   `core/discovery.php` (domyślnie 3,89) — `DiscoveryScoring::trailValueFor()` mnoży ją
   przez `distance_km` trasy i TA wartość jest teraz sugestią domyślną, nie płaska stała.
2. **Model „progi cząstkowe osobno, ukończenie osobno, w proporcji"** (pierwsza iteracja
   naprawy #1) okazał się nie do ogarnięcia bez czytania kodu — nawet dla admina.
   Przebudowa: trasa ma **jedną wartość total**, którą `trails.threshold_count` progów
   (domyślnie 4, rozstawionych równo co `100/N` procent) dzieli między siebie **równo**
   (`DiscoveryScoring::splitEqually()` — reszta z zaokrąglenia na OSTATNIM progu, żeby
   wcześniejsze były identyczne co do punktu, nie „w przybliżeniu"). Panel ma jedno pole
   liczbowe na trasę zamiast dwóch.

**`known_routes.completion_bonus` jest od tej zmiany LEGACY** — kolumna z modelu #2
sprzed przebudowy. Nic już do niej nie pisze (panel ma tylko `bonus_points`, teraz
znaczące „wartość CAŁKOWITA trasy"), ale stare wiersze (obie kolumny wypełnione z modelu
sprzed zmiany) są dalej poprawnie odczytywane: `DiscoveryScoring::legacyRouteOverrideTotal()`
SUMUJE obie kolumny w jeden total, żeby wcześniej wpisana łączna wartość przeżyła zmianę
modelu. Ten sam merge stoi w formularzu edycji trasy (`known-route-form.php`) — otwarcie
starej trasy do edycji pokazuje sumę, nie połowę wartości.

**Panel `/admin/punkty` scalony, trzecia iteracja tego samego dnia**: sekcja „Znana
trasa — punktacja" trzyma teraz RAZEM ustawienia ogólne (stawka za km, liczba progów,
wartość domyślna — wcześniej rozrzucone po ogólnej siatce „Stawki punktowe") i trasy
z nadpisaną wartością. Sekcja „Ile to daje" (przykłady liczone generycznie) zniknęła —
po iteracji 2 jej jedyna treść o trasach i tak wskazywała na sekcję niżej.

**Lista pokazuje TYLKO wyjątki od automatu**, nie każdą trasę — przy większym katalogu
kart identycznych „Automatycznie" nie dałoby się przewinąć wzrokiem. Dodanie wyjątku jest
świadomym krokiem: przycisk „Ustaw wartości ręcznie" odsłania wyszukiwarkę (ta sama
`KnownRoute::search()`, co na `/admin/znane-trasy` — żadnej nowej implementacji szukania),
„Dodaj" przenosi trasę na listę z wartością startową RÓWNĄ dzisiejszej automatycznej
(nigdy nie zmienia po cichu, ile trasa jest warta) — pułapka po drodze: mini-formularz
„Dodaj" nie ma widocznego checkboksa `bonus_enabled`, więc musi nieść obecny stan bonusu
jako pole ukryte, inaczej dodanie wartości ręcznej wyłączałoby bonus przy okazji. Na
liście trasa dostaje edytowalne pole i osobny przycisk „Wróć do automatycznej" (osobny
mini-formularz, `value_mode=auto`), który zdejmuje ją z listy przy najbliższym odświeżeniu.

**Ta sama zasada dołożona do „Bonus za udział w wydarzeniu"** (czwarta prośba usera, ten
sam dzień): lista pokazuje TYLKO wydarzenia z już ustawionym bonusem, reszta kalendarza
(dziesiątki wydarzeń) w ogóle się nie renderuje. „Dodaj bonus punktów wydarzenia" odsłania
wyszukiwarkę PO TYTULE (`eventSearchResults()` — prosty `LIKE`, bo taka wyszukiwarka
wydarzeń nigdzie indziej w panelu jeszcze nie istniała, inaczej niż `KnownRoute::search()`
reużyte dla tras). Inaczej niż trasa, wydarzenie NIE MA wartości automatycznej do
zasugerowania — „Dodaj" startuje od 0, nie od podpowiedzi, i admin wpisuje realną liczbę
dopiero na liście. Usunięcie bonusu (i zniknięcie z listy) to już istniejący mechanizm
sprzed tej zmiany: puste pole + Zapisz = `point_bonus = NULL` — nie trzeba było dokładać
osobnego przycisku, jak przy trasach.

Przełącznik widoczności paneli wyszukiwania (trasy i wydarzenia) to jedna wspólna funkcja
JS (`wireSearchToggle`), nie dwie kopie tego samego nasłuchu.

Dwa hardkodowane `[25, 50, 75, 100]` w `views/web/pages/discovery.php` (mechanizm „prawie
ukończona trasa") zamienione na `DiscoveryScoring::trailThresholds()` — inaczej zmiana
`threshold_count` w panelu cicho rozjeżdżałaby podpowiedź „do progu X% — brakuje Y" z
progami, które faktycznie płacą.

## BUG: godzina zbiórki nie zapisywała się (2026-08-14)
Sekcja „Zbiórka" na stronie wydarzenia pokazywała samą datę, choć organizator wpisywał
godzinę w kreatorze. **Zmierzone: 0 z 52 turnusów w bazie miało `start_time`.**

Przyczyna była dokładnie odwrotna do intuicji — formularz i zapis miały przeciwne warunki:
- `step-kiedy.php` pokazuje pole „Godzina zbiórki" pod `x-if="type!=='pokrec_z_kims'"`,
  czyli WSZYSTKIM typom OPRÓCZ „Pokręcę z kimś";
- `Event::save()` przekazywał ją do `EventEdition::replaceForEvent()` pod warunkiem
  `$type === 'pokrec_z_kims' ? ... : null`, czyli WYŁĄCZNIE dla „Pokręcę z kimś".

Efekt: każdy typ, któremu pole pokazywano, tracił wpisaną wartość po drodze; jedyny typ,
dla którego zapis by zadziałał, pola w ogóle nie dostawał.

Godzina jedzie teraz **bezwarunkowo**. `endDate` i `dateIsFlexible` obok ZOSTAJĄ warunkowe
i to jest poprawne — okno dostępności ma sens tylko przy „Pokręcę z kimś", gdzie termin jest
do uzgodnienia. Godzina zbiórki nie ma z tym nic wspólnego: wyjazd o 7:00 to wyjazd o 7:00
niezależnie od typu; została tam wciągnięta razem z tamtymi przez pomyłkę.

**Historia się nie naprawi sama** — istniejące wydarzenia mają `start_time = NULL` i dostaną
godzinę dopiero przy kolejnej edycji. Backfillu nie ma czym zrobić: tej informacji nigdzie
nie zapisano.

## „Co mi ta trasa da" — szacunek pod trasą na stronie wydarzenia (2026-08-14)
User: *„może się okazać, że podobną trasę już odkrywałem i niewiele to wniesie do mojej
jazdy"*. Pod każdą trasą w sekcji „Mapa i profil trasy" (i pod zakładką „Cały wyjazd") stoi
jedno zdanie: ile NOWYCH pól da ta trasa **temu, kto patrzy**, i ile z tego wyjdzie punktów.

- [`RoutePreview`](../core/Models/RoutePreview.php) — `cellsForGpx()` (pola z cache'u) +
  `forUser()` (przecięcie z `discovery_cells` oglądającego). Punkty liczy **ta sama**
  `DiscoveryScoring::forDiscoveries()`, która nalicza naprawdę — szacunek nie ma prawa
  rozjechać się z tym, co człowiek dostanie po wyjeździe.
- **TO JEST SZACUNEK i tak jest nazwany w interfejsie.** Liczymy z trasy ZAPOWIADANEJ,
  a pola naliczają się wyłącznie ze śladu z odbytego wyjazdu (migr. 042): kto skróci trasę,
  dostanie mniej; kto dojedzie na zbiórkę własnym dojazdem, więcej. Zapowiedź jest jednak
  jedyną informacją, jaką mamy PRZED wyjazdem — a pytanie „czy warto tam jechać" pada właśnie
  wtedy. Zastrzeżenie stoi pod liczbami, mniejszą czcionką.
- **Przypadek „już to mam" ma własne zdanie**, bo to po niego user o tę funkcję poprosił:
  „Tę trasę masz już całą odkrytą. Nowych pól nie przybędzie — zostaje +N pkt za sam
  przejazd." Wyszarzone (`.rgain--known`), nie czerwone: brak odkryć to informacja, nie
  ostrzeżenie, a punkty za jazdę naliczają się zawsze.
- **Tylko dla zalogowanego** — bez konta nie ma czyich odkryć odejmować, a „100% nowego"
  pokazane gościowi byłoby obietnicą dla konta, którego jeszcze nie ma.
- Przy wielodniówce **każdy dzień ma własną odpowiedź** (jeden bywa w całości znany, drugi
  w całości nowy), a zakładka „Cały wyjazd" sumuje gotowe szacunki dni — świadomie NIE liczy
  od nowa na scalonych polach: przy nakładających się dniach suma bywa lekko zawyżona, ale
  drugi tor liczenia (i drugie miejsce do rozjechania się z pierwszym) kosztowałby więcej.
- **Migracja 050 — cache pól per PLIK GPX** (`gpx_route_cells`, klucz = hash zawartości).
  Bez niego każde wejście na stronę parsowałoby GPX-y od nowa; zmierzone 14–31 ms na trasę
  przy pierwszym liczeniu, **1 ms z cache'u**. Ten sam ślad podpięty w kilku miejscach
  (etap, wariant, znana trasa) liczy się raz dla wszystkich.

## Moje przejazdy — `/admin/moje-przejazdy` (2026-08-13)
Pierwszy ekran PANELU należący do rowerzysty, nie do organizatora. Publiczny profil opowiada
innym, gdzie ta osoba jeździła; ta tabela odpowiada JEJ na pytanie „co mam do uzupełnienia".
- [`EventAttendance::myRidesForUser()`](../core/Models/EventAttendance.php) — jedno zapytanie
  na całą tabelę. **Świadomie bez filtru `attended = 1`** (w odróżnieniu od `ridesForUser()`,
  która karmi publiczny profil): najcenniejsze wiersze to te, w których czegoś BRAKUJE.
  Filtr usunąłby dokładnie to, po co się na ten ekran wchodzi. Tylko zapisy `potwierdzony` —
  ta sama reguła co `forEditionAndUser()`.
- Ślady liczone **podzapytaniami**, nie JOIN-em po `edition_tracks`: śladów na turnus bywa
  kilka (wielodniówka ma jeden na dzień) i JOIN zwielokrotniłby wiersze wyjazdów.
- Komponent `.dash-table`/`.dash-row`/`.dash-cell` — ta sama siatka co lista uczestników
  i płatności, **nie** `.dash-list`/`.dash-item` z panelu organizatora: tu wiersz to trzy
  krótkie, porównywalne wartości, które przebiega się wzrokiem w pionie. Na telefonie siatka
  sama rozkłada się na etykiety (`data-label`), nagłówek znika.
- **Kolumny „Byłem" i „Ślad" są klikalne.** Tabela, która pokazuje brak i każe iść go
  uzupełniać gdzie indziej, jest listą wyrzutów, a nie narzędziem. „Byłem" to ten sam POST co
  na stronie wydarzenia (`/wydarzenia/{slug}/bylem`) z dodanym `powrot=moje-przejazdy`;
  upload śladu zostaje na stronie wyjazdu (potrzebuje pola na plik — formularz w każdym
  wierszu rozsadziłby tabelę), tutaj tylko stan i link do `#slad`.
- `powrot` to **whitelista jednej wartości**, nie adres z żądania — w `TrackController::backUrl()`
  i w `RsvpController::declareAttendance()`. Pole „wróć tutaj" przyjmujące URL wprost to gotowy
  open redirect.
- Odkrycia i punkty pokazują **myślnik**, nie zero, gdy nie ma przejazdu: zero znaczyłoby
  „przejechałeś i nic nie odkryłeś", a prawda jest taka, że przejazdu nie ma czym policzyć.
- Osobny wiersz ostrzeżenia, gdy **jest ślad, a obecność odznaczona na „nie dojechałem"** —
  plik wtedy leży i nie liczy się do niczego, a wszędzie indziej widać z tego tylko zero
  punktów bez powodu. To jedyny ekran, na którym obie kolumny stoją obok siebie.
- Wejścia: liczba **„Wspólnych wyjazdów"** w pasku zaufania na WŁASNYM profilu (+ dopisek
  „zarządzaj →", klasa `.trust-item-note`; na cudzym profilu linku nie ma, bo prowadziłby do
  własnych przejazdów oglądającego), pozycja w menu konta obok „Moich odkryć" oraz przycisk
  w panelu.

## Powiązania nowych funkcji (2026-08-12)
Funkcje istniały, ale nie było do nich wejść — user: „gubią się, nie są wyeksponowane".
- **Menu konta → „Mój profil rowerzysty"** oraz ten sam przycisk w panelu
  (`dashboard.php`). Do tej pory własny profil był osiągalny WYŁĄCZNIE przez
  kliknięcie cudzego nazwiska; swojego nie dało się znaleźć.
- **Profil → „Co się dzieje"** (Puls) — profil był ślepym zaułkiem.
- **Strona główna → blok Pulsu** nad kalendarzem; **wydarzenie zakończone → kronika**.
- **BUG naprawiony przy okazji: nowe konta nie dostawały `public_slug` w ogóle**
  (backfill objął tylko konta sprzed migr. 038). `createFromOAuth()` nadaje slug od
  razu, `setPasswordAndActivate()` przy aktywacji konta mailowego, `updateName()`
  jako siatka bezpieczeństwa. Bez tego nazwisko każdego NOWEGO użytkownika byłoby
  nieklikalne w całym serwisie.

## Puls — `/puls` (Etap 5)
**Jednostką wpisu jest WYJAZD albo GRUPA, nigdy kliknięcie.** Zero „Jan polubił",
zero liczników reakcji, zero ściany postów.
- **Bez własnej tabeli** — jak kronika. Osiem typów wpisów wyprowadzanych w locie
  ([`Models\Pulse`](../core/Models/Pulse.php)): `przejazd` (grupa ≥2 osób przejechała,
  z liczbą debiutantów w regionie i nowych znajomości w tym samym wpisie), `sklad`
  (grupa zbiera się na nadchodzący wyjazd + wolne miejsca), `kronika` (nowe wpisy
  w dzienniku), `wezwanie` (nowe „pokręcę z kimś"), `zapis` (dodany 2026-08-13 —
  **najmniejszy sygnał i jedyny mówiący o POJEDYNCZEJ decyzji**; zwijany do jednego
  wpisu na wyjazd i dobę i ograniczony do zapisów pojedynczych, bo wyjazd z dwoma
  zapisami opisuje już `sklad`; bez imion — wpis mówi ILE osób i na co, nigdy kto),
  dwa o skarbach: `skarb-nowe` (postawione punkty, jeden wpis na dobę) i
  `skarb-pierwszy` (pierwsze znalezienie danego skarbu — zdarza się RAZ w jego życiu),
  oraz `slady-wgrane` (2026-09-11 — jedyny wpis wymieniający człowieka z imienia
  i jedyny datowany wgraniem, nie przejazdem; pełne uzasadnienie w
  [`models.md`](models.md)).
  Okno `WINDOW_DAYS = 21`, stronicowanie kursorem (`feed(limit, ?before)`) porusza się
  WEWNĄTRZ tego okna. Sortowanie i scalanie w PHP, nie UNION-em — kilka czytelnych
  zapytań zamiast jednego wielopiętrowego, a przy tej skali koszt żaden.
  Wpisy o skarbach nie mają turnusu (`editionId => 0`) i są odsiewane, zanim polecą
  zapytania o karty wyjazdu.
- **Rytm tygodniowy, nie dzienny** — nagłówki „Dzisiaj / W tym tygodniu / Wcześniej".
  Pusty dzień wygląda jak awaria, spokojny tydzień wygląda naturalnie.
- **„Co teraz robimy" / „Twoi ludzie jadą"** — `RiderConnection::upcomingForPeloton()`,
  blok nad feedem, tylko dla zalogowanych. To jest silnik powrotów całego kierunku:
  nie „mamy nowe wydarzenia", tylko „Michał i Ania jadą w sobotę". Wyjazdy, na które
  widz sam jest zapisany, są wykluczone.
- **Jedyna reakcja: „Jadę następnym razem"** — i NIE jest lajkiem. Wywołuje istniejącą
  akcję `RsvpController::markInterested` (status `zainteresowany`), więc od razu wraca
  do systemu: widać ją w „Kto jedzie" jako „Rozważają udział" i w Pulsie innych osób.
  Zero nowej tabeli, zero licznika.
- Świadomie ODRZUCONE typy: „X i Y jechali razem po raz pierwszy" (imiona pary —
  zastąpione liczbą nowych znajomości wewnątrz wpisu o przejeździe) oraz „ktoś wrócił
  po przerwie" (najsłabszy sygnał, najdroższe zapytanie).
- Wejście: pozycja **Puls** w nawigacji (`header.php`) i w stopce.

## Kronika wyjazdu — `/kronika/{slug}?termin=ID` (Etap 4)
**Kronika NIE ma własnej tabeli.** Jest WIDOKIEM na dane, które serwis już ma:
wydarzenie + turnus (tytuł/data/region/dystans/GPX), `event_attendance` (kto był),
`event_recaps` (wpisy), `event_photos` (zdjęcia przy wpisach), `rider_connections`
(ile znajomości powstało). Dzięki temu **„rodzi się wypełniona" dzieje się samo** —
nie ma momentu tworzenia, nie ma crona, który mógłby nie zadziałać, i nie istnieje
stan „pusta kronika". Strona jest od chwili, gdy pierwsza osoba potwierdzi obecność.
- Migr. `039` — `event_recaps.edition_id` (kronika jest per TURNUS, bo obecność też).
  Klucz unikalny przeniesiony z `(event_id, author)` na `(edition_id, author)`: kto
  pojechał w lipcu i w sierpniu, dorzuca do OBU kronik. **Pułapka:** stary klucz
  obsługiwał też FK `fk_recap_event`, więc trzeba było najpierw dodać `idx_recap_event`,
  inaczej `DROP INDEX` leci błędem 1553. **Ten klucz unikalny zdjęty migr. `052`** —
  patrz „Kronika jako DZIENNIK, nie podsumowanie" niżej; dziś jest to zwykły
  indeks `(edition_id, author_user_id)`, jedna osoba może dodać kilka wpisów.
- Kontroler: [`ChronicleController`](../core/Controllers/ChronicleController.php);
  zasób: [`ChronicleResource`](../core/Resources/ChronicleResource.php).
- Widok `chronicle.php` — **zero nowego CSS**. Wpisy dziennika renderuje
  `renderActivityCard()` z `partials/activity-card.php`, ten sam komponent co opinie
  i komentarze. Kolejność: fakty → **POJECHALI (ludzie PRZED zdjęciami)** → dziennik
  chronologicznie → „N nowych znajomości" → co dalej.
- `RiderConnection::newPairsFromEdition()` — pary, dla których TEN turnus był pierwszym
  wspólnym przejazdem. **Liczba, nie lista** — kto z kim widać w składzie, wyciąganie
  par byłoby ujawnianiem relacji, o które nikt nie prosił.
- `EventAttendance::firstTimersInRegionForEdition()` — „dla N osób to był pierwszy raz
  w regionie X". Odkrywanie jako fakt zbiorowy (ILU), nie odznaka dla jednostki (KTO).
- Prywatność: ukryty uczestnik (migr. 037) liczy się do składu, ale bez imienia i linku.
- „Dorzuć swoje" widzi WYŁĄCZNIE ktoś, kto sam był na tym turnusie. `RecapController`
  przyjmuje `edition_id` (ukryte pole w `recap-form.php`) i po zapisie wraca **do
  kroniki**, nie na stronę wydarzenia.
- `/relacje` przestało być placeholderem — `PageController::recaps` listuje
  `EventAttendance::chronicleIndex()` na kaflach `.op-card`.

### Kronika jako DZIENNIK, nie podsumowanie (migr. 052, 2026-08-14)
Jedna osoba może dopisać do turnusu więcej niż jeden wpis (prośba usera: „powinienem móc
wpisać się kilkukrotnie — »jedziemy już prawie na miejscu«, »meldujemy się na zbiórce«
i zdjęcia").
- To zmienia to, CZYM jest kronika. Dotąd wpis był PODSUMOWANIEM pisanym po powrocie —
  stąd `UNIQUE (edition_id, author)` i przycisk „Edytuj swój wpis". Teraz kolejny wpis
  to kolejna godzina tego samego dnia, a nie poprawka poprzedniego.
- Migracja zdejmuje klucz unikalny i zostawia zwykły indeks `(edition_id, author_user_id)` —
  zapytania nie tracą nic, znika tylko zakaz.
- W modelu: `EventRecap::forEdition`, `findByEditionAndAuthor`, `findOwn`,
  `countForEditionAuthor`. Edycja dotyczy KONKRETNEGO wpisu (ołówek w nagłówku karty,
  `activity-card.php` z `$actionsHtml`), nie „mojego wpisu" w liczbie pojedynczej.

## Galeria zdjęć skarbu (SKA/14, 2026-08-22)
Zgłoszenie usera: „dla wszystkich skarbów dodałbym zdjęcie główne jak również
możliwość zbudowania galerii zdjęć. Zdjęcia wyświetlałyby się w zależności od typu
i ich zdobycia."

**Połowa tego już istniała i nie działała z jednego powodu.** `treasures.photo_url`
jest w schemacie od początku modułu, `Treasure::save()` je zapisuje, `inBounds()`
wybiera, `reveal()` zeruje dla Tropu i Ukrytego, a dymek na mapie renderuje — brakowało
**wyłącznie pola `<input type="file">` w panelu**, przez co 0 ze 102 skarbów miało
zdjęcie. Nowa jest tylko galeria (migr. 066, tabela `treasure_photos` odwzorowana
z `event_photos`); zdjęcie główne zostaje w swojej kolumnie.

**REGUŁA UJAWNIANIA — jedna, w jednym miejscu.** Galeria schodzi w tej samej linii
`Models\Treasure::reveal()` co `photo_url`, opis i rzadkość:

| Poziom | Kto widzi zdjęcia |
|---|---|
| 2 — Jawny | każdy, także niezalogowany |
| 1 — Trop | dopiero **znalazca** (sam skarb widać po odkryciu pola, zdjęć nie) |
| 0 — Ukryty | dopiero **znalazca** |

Zdjęcie jest najmocniejszym spoilerem w tym module — pokazuje dokładnie czego szukać,
więc jego wyciek znosi zagadkę skuteczniej niż wyciek nazwy. **Każda droga do zdjęć
omijająca `reveal()` jest błędem.** Dymek dostaje `photos_count` (null, nie 0, dla
ukrytych — zero znaczyłoby „sprawdziłem, nie ma"), a samą galerię dociąga
`/api/treasures/{id}` po kliknięciu: mapa oddaje do 300 punktów naraz.

**Kto wgrywa** (decyzja usera): admin bez limitu + **znalazcy**, po 3 zdjęcia na osobę
na skarb (`TreasurePhoto::PER_USER_LIMIT`). Guard to `Treasure::foundBy()` — ta sama
metoda rozstrzyga, kto widzi galerię skarbu ukrytego i kto może do niej dorzucić.
Bez kolejki moderacyjnej, bo zdjęcia z relacji z wyjazdów też są publiczne od razu;
dwa obiegi tej samej rzeczy w jednym serwisie byłyby gorsze niż brak kolejki.
Awatar w FAQ i zdjęcia w galerii dzielą tę zasadę: **anonimizacja i ujawnianie
rozstrzygają się w modelu, nigdy w widoku.**

**ADRESY ZDJĘĆ SKŁADA `api/routes.php`, NIE MODEL I NIE JS** (poprawka 2026-08-22 po
zgłoszeniu usera „nie pojawia się w dymku — czemu nie korzystasz z budowania url
i odpowiedniej wielkości?!"). Pierwsza wersja oddawała surowy wpis z bazy
(`/assets/uploads/...`), a aplikacja stoi w podkatalogu — **zmierzone: 404**. Dotyczyło
to także `photo_url`, czyli zdjęcia głównego: było zepsute od zawsze i nikt tego nie
widział, bo żaden skarb nie miał zdjęcia. Drugi błąd: do kafelka wysokiego na 110 px
szedł oryginał 1200 px (~230 kB zamiast ~19 kB).
Odpowiedź niesie teraz **dwa adresy na zdjęcie**: `thumb` (kafelek) i `full` (lightbox).
`Models\Treasure` nie zna base_path ani presetów obrazków i nie ma prawa ich znać;
przeglądarka tym bardziej nie wie, gdzie stoi aplikacja ani jakie warianty leżą na dysku.

**Cztery ekrany**: dymek na mapie (liczba → galeria → wspólny lightbox), ekran spod QR
(`/skarb/{code}` — galeria + formularz dodania dla znalazcy), pasek pamiątek w sekcji
„Kolekcja" na profilu rowerzysty, kolumna miniatur w `/admin/skarby`.
Podgląd wszędzie daje `partials/photo-lightbox.php` — zero nowego komponentu.
Testy: `php tests/run.php skarby` (7 testów, w tym „przy Tropie licznik NIE wychodzi,
mimo że pole jest odkryte" — to jest ten przypadek, w którym najłatwiej o błąd).

## Awatary — jedna zasada w całym serwisie (2026-08-22)
Zgłoszenie usera: „nie stosujemy jednorodnego wyświetlania awatarów — jeśli user ma
ikonkę wgraną jako awatar, to i tak w aplikacji ładuje skrót". Audyt potwierdził:
serwis miał **trzy równoległe sposoby** rysowania człowieka w kółku i tylko jeden z nich
umiał pokazać zdjęcie.

| Komponent | Gdzie | Stan przed |
|---|---|---|
| `.avatar` (organizator) | strona wydarzenia | zdjęcie + inicjały ✔ |
| `.disc-social__faces i` | `/odkrycia` | zdjęcie + inicjały ✔ (własny markup) |
| `renderRiderAvatar()` → `.avs` | skład, peleton, kronika, Puls, trasa (7 wywołań) | **tylko inicjały** |
| `renderActivityCard()` → `.r-avatar` | opinie, relacje, komentarze (5 wywołań) | **tylko inicjały** |
| `.r-avatar` ręcznie | opinie na profilu organizatora | **tylko inicjały** |
| `.inbox-avatar` | skrzynka wiadomości | **tylko pierwsza litera** |

**Przyczyna nie była w widokach, tylko w zapytaniach**: komponenty nie przyjmowały adresu
zdjęcia, bo modele go nie zwracały. `u.avatar_url` doszło do ośmiu zapytań:
`EventRsvp::rosterForEdition` (skład), `EventAttendance::attendedForEdition` (kronika),
`RiderConnection::forUser` / `pelotonOnEdition` / `upcomingForPeloton` (peleton na profilu,
na wydarzeniu i w Pulsie), `EventReview::forEvent` / `forOrganizer`,
`EventRecap::fetchWithPhotos`, `EventComment::forEvent`, `Message::inboxForUser`.
`KnownRoute::finishersFor` i `Discovery::recentDiscoverers` kolumnę już miały — brakowało
tylko przekazania jej do widoku.

**Zasada, jedna dla całego serwisu:** zdjęcie ma pierwszeństwo, inicjały są zapasem,
nigdy oba naraz — i inicjały **zostają w `alt`**, więc skasowany upload czy wyłączone
obrazki dają dwie litery zamiast pustego kółka.

**Wyjątek, celowy:** awatar w parze FAQ jest NULL-owany razem z nazwiskiem i slugiem
(`EventComment::forEvent`). Pytanie z FAQ wpisuje organizator, ale prezentuje się
anonimowo (migr. 034) — twarz rozpoznaje się szybciej niż podpis, więc zdjęcie
zdradzałoby dokładnie to, co ukrywają dwie linijki obok.

Szczegóły komponentu i CSS: [`views-and-frontend.md`](views-and-frontend.md).
Testy: `php tests/run.php widoki`.

## Profil rowerzysty — `/rowerzysta/{slug}` (Etap 3)
Pierwszy raz uczestnik istnieje jako byt publiczny (dotąd miał go tylko organizator).
- Migr. `038` — `users.public_slug` (UNIQUE, NULL dozwolony). Backfill istniejących kont:
  [`backfill_rider_slugs.php`](../backfill_rider_slugs.php) (jednorazowy, idempotentny;
  na prod PO `run_migrations.php`). Konto bez sluga po prostu nie jest linkowane.
- **TRZY warunki widoczności** (`RiderController::show`), każdy daje to samo 404, żeby
  nie zdradzać, czy konto istnieje: konto istnieje · `roster_visible = 1` (migr. 037) ·
  ma ≥ 1 potwierdzony przejazd. Ostatni warunek to decyzja produktowa — **pustych
  profili w tym produkcie nie ma**, profil pojawia się po pierwszym wspólnym wyjeździe.
- Zasób: [`RiderProfileResource`](../core/Resources/RiderProfileResource.php) — sygnatura
  (najczęstszy format + region, liczona z FAKTÓW, user nic nie wypełnia), regiony
  (mianownik LICZONY z `Dictionary::items('region')` — dev ma 5, prod 13), powroty
  w ten sam region, peleton, dystans z ludźmi, „jedzie na".
- **Żadna liczba nie dotyczy prędkości ani czasu.** Progres = zasięg (regiony, ludzie,
  powroty), nie wyczyn — początkujący zbiera go tak samo szybko jak ścigant.
  „Wraca w te same strony" mierzy przywiązanie do miejsca; nikt inny tego nie liczy.
- Dane: `EventAttendance::ridesForUser()` (świadomie NIE `Event::forParticipant()` —
  tamta niesie statusy prywatne `zainteresowany`/`oczekuje_platnosci`),
  `EventRsvp::publicNextRideForUser()` (tylko `potwierdzony` + `published/full`).
- Widok `rider-profile.php` — złożony z komponentów, które serwis już miał: `.av`/`.tags`,
  `.trust-chip` (regiony), `.op-grid`/`.op-card` (kafle wyjazdów), `.roster`/`.avs`
  (peleton), `.anchors` (spis sekcji), `.stat-tiles` (kafle liczb).
- **PRZEBUDOWA 2026-09-13 — „profil pokazuje, ustawienia ustawiają"** (cztery rundy
  uwag usera jednego dnia; test `tests/wlasny_profil_test.php`). Kolejność: nagłówek
  (imię, zdanie z faktów BEZ liczb, 3 liczby: peleton · wspólne wyjazdy · odkryty teren)
  → **Do dokończenia** (tylko właściciel) → **01 Ludzie i wyjazdy** (peleton + kafle
  wyjazdów z okładką, zdjęciami z relacji i linkiem do kroniki, `EventPhoto::forEditions`)
  → **02 Gablota** (WYŁĄCZNIE znane trasy przejechane w całości, karta `partials/trail-card.php`
  z emblematem w prawym górnym rogu — opcja `emblem`; trasy z emblematem pierwsze, emblemat
  za trasę wydarzenia dostaje taką samą kartę; bez osobnego tła) → **03 Skarby** (JEDNA sekcja:
  od najrzadszych, obwódka zdjęcia w kolorze rzadkości, zagadki zamaskowane, kategorie)
  → **04 Miejsca** (mapa + „Twoje regiony” z `partials/region-emblems.php` — ten sam komponent co na /odkrycia, dane `Discovery::regionProgress($userId)`) → **05 Dziennik** (`RiderFeed`). Usunięte: 6 kafli
  nad mapą, dwie kolumny, osobne „Gdzie jeździ", panel „Twój panel" z kartami ustawień.
  - **Właściciel**: w nagłówku „Twoje skróty" — „Wgraj przejazd" + 5 linków
    (przejazdy, ustawienia, preferencje, powiadomienia, prywatność; `?sekcja=`+kotwice),
    punkty z kłódką „tylko Ty", link „Ustawienia konta" w spisie rozdziałów. Żadnych
    formularzy ani kart ze stanem — to jest decyzja usera, nie przeoczenie.
  - **Do dokończenia** (`RiderController::ownerTodo` + `EventAttendance::profileTodoForUser`):
    najwyżej 5 zdań-linków, każde innego rodzaju — relacja (tylko wyjazdy zakończone
    ≤45 dni temu), bez śladu = niezaliczony, ślad niepełny = emblemat wyjazdu czeka,
    obecność bez odpowiedzi, tajemniczy skarb na mapie (`Treasure::openTrailsForUser`),
    trasa z emblematem najbliższa ukończenia. Te same podpowiedzi jako chipy na kaflach
    wyjazdów. Tylko wydarzenia `completed`.
  - **Zagadka zostaje zagadką** (poprawiony wyciek): Trop/Ukryty znaleziony przez
    właściciela OBCY widzi jako „Tajemnica rozwiązana" — bez nazwy, zdjęcia, rzadkości
    (`Treasure::showcaseForUser` i `RiderFeed::treasures` z `viewerId`). Dawne
    `foundPhotosForUser` na profilu pokazywało obcym zdjęcia ukrytych skarbów.
  - Publicznie nie ma: punktów, „z ilu" w kategoriach, procentu trasy (obcy widzi
    „W DRODZE"), liczby znalazców. Puste miejsce na legendarny skarb widzi tylko właściciel.
  - Właściciel ukrytego profilu (`roster_visible = 0`) widzi swój profil (zamiast 404),
    z informacją w „Do dokończenia"; obcy i gość dalej 404, `noindex`.
  - Telefon (≤640 px): wyjazdy, skarby i gablota to rzędy przewijane w bok.
  - **Skala** (pytanie usera: „300 skarbów? 1000 znanych tras?"): każda rosnąca lista idzie
    stronami przez `partials/step-pager.php` (zwykłe linki, zachowują resztę adresu):
    wyjazdy `?wyjazdy=` po 9 (zdjęcia relacji tylko dla bieżącej strony), gablota
    `?gablota=` po 8 (emblematy zawsze pierwsze), skarby `?skarby=` po 12 z filtrem
    `?rzadkosc=` i `?kategoria=` (kategorie w kolekcji są linkami-filtrami), dziennik
    `?aktywnosc=`. Filtr NIE zdradza zagadki: przy filtrze rzadkości zamaskowane wypadają,
    liczniki rzadkości ich nie liczą (`Treasure::showcaseWhere`). Peleton ma limit 12 z modelu.
- Kolejność czytania celowa: **„Jedzie na" NAD liczbami** — profil zaprasza do wspólnej
  jazdy, nie podsumowuje przeszłość. To jedyny element sygnałowy (`.blaze`) na ekranie.

### Przebudowa układu (2026-08-22)
Zgłoszenie usera: „nie ma polotu, a przy tylu funkcjach może wyglądać zdecydowanie
lepiej". Źródłem był UKŁAD, nie kolory — treść i kolejność czytania zostały te same.
Prototyp: [`szablony/profil-rowerzysty.html`](../szablony/profil-rowerzysty.html).
- **Hero `.rp-hero`** — trzy osobne prostokąty (`.op-head` + pudełko „Jedzie na" +
  czarny `.trust-bar`) scalone w JEDEN panel: tożsamość po lewej, karta następnego
  wyjazdu po prawej, pasek liczb jako stopka. Panel jest **jasny** (decyzja usera;
  pierwsza wersja miała ciemne tło) — ciężar niosą przesunięty cień 4 px, pasmo
  `--tint` pod liczbami i jedna żółta warstwica.
- **Zdanie-sygnatura** pod nazwiskiem — składane z liczb, które i tak są na stronie
  (zero nowych zapytań); człony, których nie ma, po prostu wypadają, więc świeże konto
  nie dostaje „0 km". Profil nie ma pola „o mnie" i dopóki go nie ma, to jedyne miejsce,
  w którym z listy metryk robi się człowiek.
- **Notki pod liczbami** — „3 z 5" nie mówiło nic, dopóki nie było wiadomo, KTÓRE trzy.
  Wszystkie z danych, które zasób już zwraca (`regions`, `discovery['rides']`).
- **`.anchors`** — profil urósł do siedmiu sekcji; pozycje renderują się tylko dla
  sekcji faktycznie obecnych na stronie, a przy ≤ 2 pozycjach spisu nie ma wcale.
- **Dwie kolumny `.rp-cols`** — podział z RODZAJU treści, nie estetyczny: kolumna główna
  to rzeczy, które się CZYTA (aktywność, szlaki, wyjazdy), szyna to rzeczy, które się
  SPRAWDZA (peleton, regiony, kolekcja). Bez treści w szynie układ wraca do jednej
  kolumny. Zmierzone: strona skróciła się z ~3,7 tys. px do ~3,0 tys. px.
- Drobiazgi: kafel wiodący w `.stat-tiles` (flaga `lead`, dodana do WSPÓLNEGO partiala),
  kolor kafelka ikony per typ wpisu aktywności, mini-słupek wspólnych pól w peletonie
  (skala względem maksimum W TEJ LIŚCIE, nie bezwzględna), kolekcja jako wiersze
  z paskiem zamiast chipów.
- Imiona linkują do profili w bloku „Z Twojego peletonu" (`event-page.php`) i w sekcji
  Peleton na samym profilu.

### Spójność profilu z modułem odkryć (2026-08-13)
Profil pokazywał inne liczby i innym komponentem niż `/odkrycia`, a jego mapa rysowała
co innego, niż mówiła. Cztery rozjazdy, wszystkie naprawione:
- **Mapa rysowała trasy ZAPOWIADANE** (`event_stages.gpx_url`) i podpisywała je „ślady
  z przejechanych wyjazdów", podczas gdy mgła schodzi WYŁĄCZNIE ze śladów rzeczywistych
  (`edition_tracks`, migr. 042). Linie i mgła brały się z dwóch różnych źródeł, więc na
  jednym obrazku pokazywały co innego. Teraz **dwie warstwy linii**: ciągła zielona =
  ślad z odbytego wyjazdu (dokładnie to, z czego naliczono pola), przerywana szara =
  trasa zapowiadana wyjazdu, którego nikt nie potwierdził śladem. Ta druga tłumaczy, czemu
  mapa jest pustsza niż lista wyjazdów — usunięcie jej ukryłoby powód.
- Ślady bierze [`EditionTrack::effectiveForUser()`](../core/Models/EditionTrack.php) —
  ta sama reguła pierwszeństwa co `effectiveFor()` (własny ślad bije ślad organizatora),
  tylko dla całej historii jednym zapytaniem. **Zweryfikowane, że oba zwracają identyczny
  zbiór** — także gdy własny ślad wypiera ślad z imprezy. Gdyby się rozjechały, mapa
  pokazywałaby inny przebieg niż ten, z którego są pola.
- **Statystyki**: profil dostał sekcję `#odkrycia` z komponentem `.disc-stats` — tym samym
  co `/odkrycia`, z tymi samymi podpisami i wartościami (pola · punkty · znane trasy ·
  białe plamy). Wcześniej punktów nie było w ogóle, a pola i białe plamy były doklejone
  na koniec `.trust-bar` (ciemny pasek zaufania), przez co ta sama liczba w dwóch miejscach
  czytała się jak dwie metryki. Piąty kafel — **„Przejazdy ze śladem X z Y"** — odpowiada
  na pytanie, które zadaje każdy, kto porówna tę stronę z listą wyjazdów niżej.
  Sekcja pojawia się dopiero, gdy jest co pokazać (pięć zer mówiłoby nieprawdę o osobie,
  nie o danych); właściciel profilu dostaje wtedy pod mapą zachętę do wgrania śladu.
- **„Regiony" znaczyło dwie różne rzeczy pod tym samym podpisem**: na profilu regiony
  WYJAZDÓW (był tam), na `/odkrycia` regiony PRZEJAZDÓW (jest ślad) — ten sam mianownik,
  różne liczniki, np. „3 z 5" i „1 z 5". Podpis na `/odkrycia` mówi teraz
  „z przejazdem ze śladem".
- Karty znanych tras na profilu to teraz `.disc-trail` (ze zdjęciem), a nie `.trail` —
  jeden szlak z tym samym procentem wyglądał w dwóch miejscach na dwie różne rzeczy.
  Licznik „X z Y" liczy się z PEŁNEGO katalogu tras (jak `/odkrycia`), a lista dalej
  pokazuje tylko trasy rozpoczęte.
- Kafle wyjazdów dostały stopkę „co dał ten wyjazd" (nowe pola + punkty) z
  `Discovery::statsForEditions()` — jedno zapytanie na całą historię, nie na kafel.
- `ridemoreAddGpxTrack()` przyjmuje teraz `opacity`, `dashArray` i `hideMarkers`
  (na mapie całej historii kilkanaście par pinezek zasłania to, co pokazują).

## Prywatność: „nie pokazuj mnie na listach uczestników i w peletonie"
Decyzja usera 2026-08-11, podjęta przed Etapem 3 (profil).
- Migr. `037` — `users.roster_visible` BOOLEAN DEFAULT TRUE. Nazwa **pozytywna**
  (czytelne `WHERE u.roster_visible = 1`); checkbox w UI jest jej ODWROTNOŚCIĄ
  (`hide_from_rosters`), zamiana w `AccountController::updatePreferences`.
- **Ukrycie dotyczy TOŻSAMOŚCI, nie FAKTU**: osoba znika z twarzy/imion, ale nadal
  liczy się do składu („2 osoby jadą"). Dlatego `EventRsvp::rosterForEdition()` zwraca
  osobno listy (`confirmed`/`interested` — tylko widoczni) i liczniki
  (`confirmedTotal`/`interestedTotal` — wszyscy). **Każde miejsce pokazujące liczbę
  uczestników musi używać `*Total`**, inaczej ukrycie jednej osoby pomniejszy skład.
- Organizator widzi ukrytych normalnie (`EventRsvp::forEvent()` niefiltrowane) — to on
  odpowiada za wyjazd.
- Filtrowane: `rosterForEdition`, `RiderConnection::forUser`, `pelotonOnEdition`.
  **NIE** filtrowane: `countForUser` (patrz komentarz w modelu — do rozstrzygnięcia
  przy profilu, żeby licznik i lista się nie rozjechały).
- Niezalogowany gość **nie widzi imion nikogo** (decyzja z tej samej rundy) — dostaje
  twarze z inicjałami i liczbę. Bez zmian wobec stanu sprzed Etapu 1.
- UI: nowy box „Prywatność" w `account.php`, komponent `.stg-sw` (ten sam co przełącznik
  powiadomień), wysyłany istniejącym formularzem preferencji.
- Przy okazji poprawiona odmiana w panelu zapisu („2 **osoby** już jedzie" zamiast
  „2 osób") — `$plural`/`$rosterPlural` przeniesione na górę `event-page.php`, bo dzielą
  je sekcja „Kto jedzie" i `.book__who`, renderowane w rozłącznych gałęziach statusu.

## Kafle map (migr. 051, 2026-08-14)

**Mapy przestały ładować pliki GPX do przeglądarki.** Ślady i pola odkryć przychodzą
jako gotowe obrazki PNG generowane na żądanie i zapisywane na dysk.

**Powód, policzalny.** Profil rowerzysty rysował KAŻDY ślad wektorowo, bez limitu.
Zmierzone na prawdziwych plikach z `assets/uploads/gpx` (mediana 180 kB / 2107 punktów),
przy 600 przejazdach: **ok. 108 MB** pobierania, **ok. 25 s** do pierwszego obrazu,
**ok. 2,7 s** zwiechy na każdy krok zoomu (41 ms na ślad przy budowie, 4,5 ms na ślad
na zoom). A to strona PUBLICZNA — koszt płacił każdy odwiedzający.
**Po zmianie: 0 plików GPX przy wejściu na profil** (zweryfikowane w przeglądarce);
klik w pozycję listy dociąga 1 plik, bo tylko podświetlenie wymaga wektora.

**Jak to działa — trzy warstwy, każda w jednym pliku:**
- [`Utils\TileGrid`](../core/Utils/TileGrid.php) — matematyka. Punkt zapisujemy jako
  parę liczb całkowitych: piksel świata na **zoomie 18**. Z tego kafel dowolnego zoomu
  wychodzi PRZESUNIĘCIEM BITOWYM (`px >> (18 - z + 8)`), bez jednego sinusa przy
  rysowaniu. 2^18·256 = 67 mln mieści się w 27 bitach; 0,37 m na piksel jest poniżej
  dokładności GPS. Zweryfikowane przeciw wzorowi slippy-map dla 5 punktów × 11 zoomów.
- [`Models\GpxGeometry`](../core/Models/GpxGeometry.php) — jedno parsowanie na plik,
  klucz to hash ZAWARTOŚCI (jak `gpx_route_cells`, migr. 050). Zmierzone: 179 864 B GPX
  → **16 856 B** bloba (10,7× mniej), odczyt 0,8 ms zamiast 6,4 ms parsowania.
  Indeks `gpx_tiles` na poziomie z14 z **uzupełnianiem przerw** — punkty GPS bywają
  rzadsze niż kafel (300 m), więc bez interpolacji ślad przeskakiwałby kafle i te nie
  narysowałyby się wcale. Zweryfikowane: 0 punktów bez swojego kafla.
- [`Utils\TileRenderer`](../core/Utils/TileRenderer.php) — GD z **nadpróbkowaniem ×2**,
  bo `imageantialias()` nie działa dla linii grubszych niż 1 px. Zmierzone: 16–30 ms na
  kafel, PNG 2–5 kB, pusty kafel 355 B.

**Jeden poziom indeksu obsługuje wszystkie zoomy** — i to jest różnica względem siatki
heksagonalnej, gdzie trzeba było dołożyć `parent_res0..3` (migr. 047). Kafle są
zagnieżdżone przez samo przesunięcie bitowe, więc pytanie o kafel zoomu z to zwykły
zakres `BETWEEN` na tych samych kolumnach.

**ODKRYTE POLE ODSŁANIA MAPĘ, NIE ZAMALOWUJE JEJ** (uwaga usera 2026-08-14:
„odkrycia powinny ujawniać trasę, czyli być przezroczyste"). Projekt grafika z 13.08
malował odkryty teren kolorem skali — przy typowych danych, gdzie prawie wszystko jest
odkryte RAZ, dawało to jednolitą zieloną plamę zasłaniającą drogi, nazwy i własny ślad.
Pierwszy stopień ma więc krycie **0**: „odkryte" = goła mapa. Kolor wraca dopiero tam,
gdzie niesie informację, której z mapy nie widać — że tędy jeździ się wielokrotnie
(krycia 0,18 / 0,28 / 0,38). Rastrowo to dziura wypalona w mgle (`imagealphablending`
wyłączone, kolor ZASTĘPUJE piksele), wektorowo — dziura w wielokącie mgły, czyli
mechanika z kroku 2, która wróciła.

**Obramowanie należy do MGŁY, nie do pola odkrytego.** Dzień wcześniej biały włos siedział
na polach odkrytych (miał rozdzielać sąsiadów tego samego stopnia); odkąd te pola są
przezroczyste, nie ma czego rozdzielać — obrys jest teraz granicą między odkrytym
a nieodkrytym i pokazuje kształt własnego zasięgu. Kolor: **ciemniejszy odcień mgły**
(`#63707F`), nie biel — biel działała na polu zamalowanym, a na jasnej szarości znikała
(zmierzone na kaflu: nieodróżnialna od antyaliasingu krawędzi). W wersji wektorowej
obrys niesie JEDEN wielokąt mgły, bo każde pole jest jego dziurą.

**SIATKA W MGLE — obrysy pól, których nikt jeszcze nie zdjął** (2026-08-30, prośba usera:
„na mgle delikatne obramowanie hexów, o ton ciemniejsze niż sama mgła — pozwoli userowi
zobaczyć, jakie hexy ma koło siebie i gdzie powinien skręcić"). Mgła była gładką plamą,
więc nie dało się w niej NICZEGO zaplanować; teraz w środku mgły widać podział na pola.
Cztery rzeczy, których ta zmiana wymagała:

- **Matematyka siatki musiała pójść do JS-a** (`hexLattice` w `discovery-map.js`). Serwer
  przysyła wyłącznie pola ODKRYTE — lista nieodkrytych byłaby listą wszystkiego, czego nie
  ma. `pointToAxial` / `axialToPoint` / `roundAxial` są więc przepisane 1:1
  z `Utils\DiscoveryGrid` i to **drugi (i ostatni) fragment tej matematyki powtórzony
  w JS**, obok samego rzutowania Mercatora. Ceną jest test „mgła: siatka pól jest
  wyrównana z siatką serwera", który pilnuje obu kopii: rozjazd nie wywala niczego
  z hukiem, tylko rysuje obrysy OBOK dziur wyciętych w mgle. Zweryfikowane na żywo:
  środek pola z API przepuszczony przez wzory z JS wraca z błędem max 1 m przy polu 7500 m.
- **Zakres `q` liczony osobno dla każdego wiersza.** Oś `q` zależy od OBU współrzędnych
  (`x = size·√3·(q + r/2)`), więc prostokąt w osiach opisuje na mapie równoległobok —
  ta sama pułapka, którą `DiscoveryGrid::boundsFromAxialExtremes` opisuje od drugiej strony.
- **Próg wielkości pola na ekranie** (`GRID_MIN_PX = 34`), MIERZONY rzutowaniem mapy,
  nie zgadywany z oddalenia (`sizeM` zmienia się skokowo z drabinką poziomów, a piksel na
  metr zależy też od szerokości geograficznej). Bez niego widok domyślny (cały kraj)
  rysował ~2000 pól po ~21 px — dokładnie ten „plaster miodu", przed którym ostrzega §24:
  gęstwina kresek bez treści, przeliczana przy każdym ruchu mapy. Powyżej progu w kadr
  wchodzi kilkaset pól. Zmierzone na ekranie 1199×520 px.
- **Pole odkryte NIE dostaje obrysu** — zostaje czystą mapą, zgodnie z zasadą z 2026-08-14.
  Odkryte pola z odpowiedzi trafiają do zbioru kluczy osiowych, który siatka pomija.

Kolor jest ten sam co granica odkrytego (`#63707F`), ale linia cieńsza i bledsza
(0,5 px / 0,3 zamiast 0,6 px / 0,85) — i to rozróżnienie **niesie treść**: mocna linia
to koniec Twojego zasięgu, delikatna to zwykły podział siatki. Gdyby wyglądały tak samo,
granica własnych odkryć — jedyna linia na tej mapie, która coś mówi — utonęłaby
w plastrze miodu. Dotyczy obu map wektorowych, więc w apce siatka pojawia się sama,
gdy kadr stanie na pozycji rowerzysty (zoom 15).

**Skala pól jest BEZWZGLĘDNA, nie względem kadru** (`TileSource::INTENSITY_STEPS`).
Wersja wektorowa liczyła stopnie względem maksimum w widocznym prostokącie — kafel nie
wie, w jakim kadrze się znajdzie, więc skala względna dawała SĄSIADUJĄCYM kaflom różne
odniesienia (widać to było jako szachownicę: wszystko czerwone). Intensywność to
**średnia liczba przejazdów na pole**, nie suma — przy oddaleniu jedno rysowane pole
jest sumą kilkunastu, więc suma malowałaby całą Polskę na czerwono.

**Kafle sięgają zoomu 18, ale zapisujemy do 14** (2026-08-20, zgłoszenie usera: „przy
maksymalnym zoomie linia jest za gruba i rozmyta, jakbyśmy przy jakiejś granicy nie
generowali nowych kafelków"). Dokładnie tak było: `maxNativeZoom` stało na 14, więc przy
z18 Leaflet rozciągał ten sam obraz **szesnastokrotnie** — przy śladzie GPS uchodziło to
na sucho, ale linia znanej trasy robiła się pasem. Teraz:
- `TileGrid::MAX_Z = 18` — generujemy do końca skali;
- `TileGrid::INDEX_Z` **odczepione od MAX_Z i zostaje na 14**, bo indeks „który ślad przez
  który kafel" jest ZAPISANY w `gpx_geometry.tiles`; podniesienie go unieważniłoby dane
  wszystkich śladów;
- **poziomy głębsze niż indeks nie lądują na dysku** — powstają na żądanie i lecą
  z `private, no-store`. To jest odpowiedź na stary argument „sam z15 to 804 000 kafli na
  Polskę": nie zapisujemy ich, a render jest tani, bo kafel z18 obejmuje ok. 150 m terenu.
  Przy okazji `TileCache::invalidateTrack` kasuje tylko do INDEX_Z — inaczej ślad
  dotykający 115 kafli indeksu wymagałby ok. 39 000 prób skasowania pliku.
- **Renderer trzeba było naprawić przy okazji**: nadpróbkowuje ×2, czyli rysuje
  w przestrzeni zoomu `z+1`, więc przy z18 liczył przesunięcie bitowe MINUS JEDEN
  i PHP 8 rzucał `ArithmeticError` — każdy kafel z18 wracał jako HTTP 500. Przesunięcie
  rozbite na parę nieujemnych (`shiftPair`), bez gałęzi w pętli po punktach.

**Cała wydajność siedzi w regule, która była w `.htaccess` wcześniej:**
`RewriteCond %{REQUEST_FILENAME} !-f`. Kafel leży dokładnie pod swoim adresem, więc
istniejący idzie z dysku bez budzenia PHP-a. Zmierzone: **54 ms** pierwsze żądanie,
**1,8 ms** drugie (nagłówki `ETag`/`Last-Modified` potwierdzają, że odpowiada Apache).

**Prywatność — na dysku ląduje wyłącznie to, co i tak jest publiczne.** Klucz
`u-{slug}` przechodzi przez `Support::visibleRider()` (wywołane, nie przepisane).
Osoba UKRYTA z list dostaje klucz `me`, którego **nie zapisujemy** — kafel powstaje na
żądanie i leci z `private, no-store`. Zmiana widoczności kasuje kafle `u-{slug}`
(`User::updateRosterVisibility` → `TileCache::purgeKey`), bo inaczej ktoś, kto się
ukrył, zostawiałby za sobą działający adres.

**Klucz `kr` — WSZYSTKIE aktywne znane trasy** (2026-08-20). Warstwa „Trasy" na
`/odkrycia`, profilu rowerzysty i stronie trasy rysuje się od tej daty WYŁĄCZNIE
kaflami. Wcześniej szła wektorem z `/api/discovery/trails`, a ten podawał geometrię
sklejoną ze ŚRODKÓW PÓL siatki odkryć — pole ma ok. 500 m, więc linia była zygzakiem
obok drogi zamiast przebiegiem szlaku. Kafel rysuje prawdziwy ślad z pliku GPX
(`TileRenderer` + `GpxGeometry`) i nie wysyła do przeglądarki ani jednego punktu
geometrii. **Cena, zapłacona świadomie: obrazek nie jest klikalny**, więc szlaki
straciły dymki (nazwa, dystans, przewyższenie, wejście na stronę trasy) — przywrócenie
ich wymaga osobnej warstwy trafień, a nie powrotu do wektora.

**Unieważnianie.** Wgranie i usunięcie śladu wisi na `EditionTrack::attach/remove` —
tam, gdzie już wisi przeliczanie odkryć, i z tego samego powodu (plik wgrywa się na
kilka sposobów). Kasujemy kafle, przez które ślad przechodzi, w kluczach: `all`,
`e-{turnus}` i `u-{slug}` każdego OBECNEGO. **Znane trasy dopiero od 2026-08-20 mają
to w ogóle** (`KnownRoute::invalidateTiles`, klucze `kr` i `kr-{id}`): dopóki rysowały
się wektorem, każde przesunięcie mapy dociągało świeże dane i unieważniać nie było
czego — od przejścia na kafle brak unieważnienia znaczyłby „zmiana trasy nie dociera
do nikogo". Wołane przy dodaniu, podmianie przebiegu (STARY i NOWY plik), włączeniu,
wyłączeniu i usunięciu trasy. Zmierzone: **23 ms**, ślad dotykający
115 kafli z14, kafle znikają na z10/z12/z14 i wracają przy pierwszym żądaniu (82 ms).
Epoka w `?v=` załatwia przeglądarki, bo na `*.png` leci `immutable` na rok.

**Panel `/admin/kafle`** pokazuje stan cache'u, kasuje kafle (warstwami albo w całości),
przycina do limitu i — od 2026-08-20 — **liczy geometrię wszystkich śladów**
(`GpxGeometry::backfillAll()`, ta sama funkcja co `php tiles.php backfill`). Ostatnie było
wcześniej dostępne WYŁĄCZNIE z konsoli, a ekran sam odsyłał do niej zdaniem „czego ten ekran
NIE robi" — na hostingu bez shella nie było do tego żadnej drogi. Panel pokazuje też, ile
śladów czeka na policzenie: to nie statystyka, tylko STAN GOTOWOŚCI, bo ślad bez geometrii
nie narysuje się na żadnym kaflu, dopóki ktoś nie wejdzie na mapę i nie zapłaci za
parsowanie w trakcie żądania o obrazek (przy kluczu `kr` byłoby to parsowanie wszystkich
znanych tras naraz). **Kafli panel nie generuje z góry** — pełna piramida z4–z14 dla Polski
to ok. 269 000 plików, a ogląda się z niej ułamek; backfill przygotowuje wszystko, czego
kafel potrzebuje, żeby powstać w kilkadziesiąt milisekund przy pierwszym żądaniu.

**Sprzątanie.** Limit `TileCache::MAX_TILES` = 120 000 chroni nie miejsce (to ok. 4 GB),
tylko **i-węzły**: hosting współdzielony daje ich 250–500 tys. na całe konto, a pełna
piramida z8–z14 dla Polski to 269 000 plików. Przycinanie po `last_used_at`, sprawdzane
raz na 500 zapisów. CLI: `php tiles.php stats|backfill|prune|purge`.

**Panel `/admin/kafle`** ([`TilesController`](../core/Controllers/Admin/TilesController.php),
2026-08-15) — to samo bez konsoli: co leży na dysku, `purge` (kasuje kafle warstwy,
geometria zostaje) i `prune` (przytnij do limitu). Powstał, bo reset dało się dotąd zrobić
WYŁĄCZNIE `php tiles.php purge`, a na hostingu bez shella zepsute kafle zostawały na dysku
do skutku — i to są pliki, które użytkownicy widzą zamiast mapy.

**Czego kafle NIE potrafią:** przestylować pojedynczego śladu (podświetlenie musi iść
warstwą wektorową na wierzchu) i odpowiedzieć na klik (to robi osobne trafienie po
stronie serwera — zmierzone 12 ms w najgorszym przypadku na geometrii przyciętej
do kafla).

### Obwódka znanych tras — „referencja" kontra „przejazd" (2026-08-27)

Zgłoszenie usera: „znane trasy i ślady mają dziś jeden styl i nakładają się na siebie
nie do odróżnienia (…) dla znanych tras możemy zmienić styl i wprowadzić obwódkę do
linii, tj. obramowanie, główny kolor wybrany przez algorytm, obramowanie". Kolor
per trasa (`KnownRoute::assignColor`, migr. 064) rozwiązał odróżnianie znanych tras
OD SIEBIE NAWZAJEM — ale znana trasa pokolorowana index 0 (`#2C6B4F`, zieleń brandowa)
wygląda IDENTYCZNIE jak zwykły zarejestrowany przejazd (`STYLES['real']`, ten sam
`#2C6B4F`), więc tam, gdzie ktoś jechał dokładnie znaną trasą, jedna plama zamiast
dwóch odróżnialnych warstw.

**Mechanizm — klasyczna „cased line" z kartografii drogowej.**
[`Utils\TileRenderer::tracks()`](../core/Utils/TileRenderer.php) rysuje geometrię grupy
DWA RAZY, gdy grupa niesie klucz `casing`: najpierw SZERZEJ, jednolitym kolorem
obwódki (spod spodu wystają tylko brzegi), potem NORMALNĄ grubością, głównym kolorem
grupy, na wierzchu. Bez klucza `casing` (`real`/`planned`) zachowanie jest bajtowo
identyczne jak przed zmianą — sprawdzone testem porównującym PNG bajt po bajcie.

**Obwódka jest STAŁA, nie idzie kolumną per trasa** — i to jest sedno rozwiązania,
nie szczegół implementacji. Kolor odróżnia trasę OD INNEJ TRASY; obwódka odróżnia
CAŁĄ KATEGORIĘ „to jest referencja" od „to jest zarejestrowany przejazd", więc musi
być ta sama niezależnie od tego, który z sześciu kolorów `KnownRoute::COLORS` akurat
przydzielił algorytm. Stąd `casing`/`casingWidth` siedzą w
[`Models\TileSource::STYLES['route']`](../core/Models/TileSource.php) (stała definicja
stylu), NIE w `routeGroups()` (per-trasa dane) — i obejmują od razu KAŻDĄ grupę tego
stylu, czyli też `ev-{id}` (trasa zapowiadana wydarzenia — ta sama „referencja" w innym
ubraniu, słowami usera: „co jest referencją a co kręceniem solo lub po ścieżce eventu").
Kolor obwódki: `#15201A`, czyli `--ink` z palety CSS serwisu — ciemny dość, żeby czytać
się na każdym z sześciu kolorów palety i na jasnym podkładzie mapy, a przy okazji spójny
z resztą interfejsu zamiast wprowadzać siódmy, nowy kolor tylko na tę okazję.

**Zmiana treści kafla, nie danych jednej trasy — wymaga PURGE, nie `invalidateTrack()`.**
`STYLES['route']` to kod renderera, współdzielony przez WSZYSTKIE znane trasy i trasy
wydarzeń naraz, więc nie ma pojedynczego śladu, którego unieważnienie załatwiłoby sprawę.
Po wdrożeniu: `TileCache::purgeLayer('slady')` (albo „Reset kafli" → warstwa Ślady na
`/admin/kafle`, albo `php tiles.php purge slady` z konsoli) — stare kafle bez obwódki
inaczej zostałyby na dysku (i w `Cache-Control: immutable` przeglądarek) na zawsze,
mimo zmienionego kodu. Kasowanie kafli jest bezpieczne (patrz nota w `TilesController`)
— kafel to obrazek policzony z geometrii, która zostaje nietknięta, i odbudowuje się
sam przy pierwszym żądaniu.

Testy: dopisane do `php tests/run.php znane_trasy` (4 nowe — obwódka faktycznie
dorysowuje piksele, grupa bez klucza `casing` renderuje się bajtowo identycznie jak
przed zmianą zarówno przy braku klucza, jak i przy `null`/pustym stringu, obwódka jest
zdefiniowana wyłącznie dla stylu `route`). Zweryfikowane żywo: po `purgeLayer('slady')`
prawdziwy kafel warstwy `kr` (znana trasa testowa, z jej realnym plikiem GPX) pobrany
przez HTTP z działającego Apache pokazuje OBA kolory (`#2C6B4F` — 784 px główny,
`#15201A` — 694 px obwódka, plus antyaliasing na styku), podczas gdy kafel warstwy `all`
(zwykły ślad wyjazdu) w TYCH SAMYCH współrzędnych z/x/y ma WYŁĄCZNIE `#2C6B4F`, bez
śladu obwódki — dokładnie ten kontrast, o który chodziło w zgłoszeniu.

### Kolory śladów — koniec zielonej plamy (2026-08-27, migr. 073)

Zgłoszenie usera, dzień po wprowadzeniu obwódki: „wszystkie te trasy są
wygenerowane w kolorze zielonym, niezależnie od tego czy to solo przejazdy czy
referencyjne, różnią się tylko obwódką (…) w pierwotnym założeniu, które
zostało gdzieś przypadkiem usunięte z aplikacji, było, że jakiekolwiek ślady
tras miały mieć różne kolory, tak aby w przypadku nakładających się śladów
rozróżnić je kolorami (…) moje ślady generują się w jednym kolorze i mam jedną
wielką zieloną plamę".

**Nic nie zostało usunięte — to założenie nigdy nie objęło śladów.** Migracja
064 dała kolory per obiekt WYŁĄCZNIE znanym trasom (`known_routes.color_index`),
i to rozwiązało odróżnianie szlaków od siebie nawzajem. Wszystkie przejazdy —
solo i z wyjazdów — szły dalej JEDNĄ grupą w jednym `#2C6B4F`, bo
`TileSource::tracks()` zwracało dla nich jeden worek hashy i jeden styl.
Zmierzone na koncie zgłaszającego: **89 przejazdów solo w jednej grupie**,
a na najbardziej zatłoczonym kaflu indeksu **39 śladów jeden na drugim** —
wszystkie tym samym zielonym.

**Trzy rzeczy, o które user poprosił wprost, i co z nich wyszło:**

1. **„Baza kolorów przeznaczonych na generowanie tras"** →
   [`Utils\TrackPalette`](../core/Utils/TrackPalette.php). Jedna paleta dla
   wszystkiego, co jest na mapie linią. `KnownRoute::COLORS` jest od tej pory
   jej aliasem, a algorytm wyboru (`pick()`) został wyciągnięty z
   `KnownRoute::pickColor` — bo obsługuje teraz i szlaki, i ślady, więc nie może
   być własnością jednego z nich.
2. **„Jeśli są koło siebie lub się nakładają, to rozróżnić je kolorem"** →
   `GpxGeometry::assignColor()`. Sąsiedztwo liczone po WSPÓLNYCH KAFLACH
   INDEKSU (`gpx_tiles`) — to dokładnie ta sama miara, której używa renderer:
   dwa ślady dzielące kafel to dwa ślady, które trafią na ten sam obrazek,
   czyli te, które naprawdę mogą się na sobie położyć.
3. **„Jeden kolor powinien być przeznaczony dla wybranego"** →
   `TrackPalette::SELECTED` (`#2B57C8`), ZAREZERWOWANY i celowo NIEOBECNY
   w palecie. **Przy okazji wyszedł błąd, który już tam siedział:** paleta miała
   pod indeksem 1 niebieski `#1F6FB2`, a podświetlenie kliknietego śladu rysuje
   `#2B57C8` — trasa z tym indeksem wyglądała więc jak zaznaczona, choć nikt jej
   nie kliknął. Niebieski wyleciał z palety, jego miejsce (indeks 1) zajęła
   oliwka `#5F7A20`. Podmiana JEST W MIEJSCU, więc przemalowują się wyłącznie
   trasy, które akurat miały niebieski.

**Kolor siedzi przy GEOMETRII, nie przy przejeździe** — `gpx_geometry.color_index`.
Ta tabela jest kluczowana hashem ZAWARTOŚCI pliku i jest dokładnie tym, co
renderer rysuje jako jedną linię. Ten sam plik bywa podpięty w kilku miejscach
naraz (przejazd solo, ślad turnusu, etap wydarzenia), więc kolumna
w `rider_activities` znaczyłaby „ten sam ślad jest zielony na jednej mapie
i czerwony na drugiej" — przy kolorze na geometrii ślad ma ten sam kolor
wszędzie, gdzie się pojawia.

**Przydział jest PRZYROSTOWY i nie rusza sąsiadów** — zasada przejęta wprost
z migracji 064, bo tam kosztowała już jedną analizę: kafle są plikami na dysku
unieważnianymi po ŚLADZIE (`TileCache::invalidateTrack`), więc przemalowanie
sąsiada wymagałoby skasowania kafli także na JEGO przebiegu, a sąsiada sąsiada
— lawinowo dalej. Nowy ślad dopasowuje się do tego, co już leży.

**Znane trasy zostają przy swoim `known_routes.color_index`** i to nie jest
niedoróbka: kolor szlaku jest własnością TRASY, nie pliku — pokazuje go dymek
na mapie, karta trasy i wykres profilu, więc musi przeżyć podmianę przebiegu
(nowy plik, nowy hash, ta sama trasa). Dwie niezależne przestrzenie kolorów
rozróżnia wizualnie obwódka stylu `route` (dzień wcześniej) — kolor mówi
„który to ślad", obwódka mówi „referencja czy przejazd".

**Trasy zapowiadane (`planned`) NIE dostają palety** — zostają jedną szarą,
przerywaną grupą. Ta szarość nie jest „kolorem tej trasy", tylko komunikatem:
zapowiedź, której nikt nie potwierdził śladem. Sześć wesołych kolorów mówiłoby
tam dokładnie to samo co przejazdy, które naprawdę się odbyły.

**Uruchomienie po wdrożeniu** (kolor przydziela się SAM przy liczeniu geometrii
nowego pliku, ale to, co już leży w bazie, trzeba pomalować raz):
`/admin/kafle` → **„Przydziel kolory śladom"**, albo `php tiles.php colors`
z konsoli. Obie drogi kasują potem kafle warstwy `slady` same — bez tego zmiana
siedziałaby w bazie, a mapa dalej pokazywałaby jedną plamę (`immutable`).
Panel pokazuje też licznik „śladów bez koloru" i całą paletę, żeby dało się
zobaczyć, czym serwis maluje, bez czytania kodu.

Testy: `php tests/run.php kolory_sladow` (12 przypadków — m.in. „dwa ślady
w tym samym miejscu dostają RÓŻNE kolory" jako test tej regresji, rezerwacja
koloru wyboru, idempotencja backfillu, „przydział nie przemalowuje sąsiadów"
oraz kształt grup oddawanych rendererowi). **Zweryfikowane żywo** na koncie
zgłaszającego: po backfillu (70 śladów, rozkład 12/12/12/12/11/11) kafle klucza
`me` pobrane przez HTTP z działającego Apache pokazują **wszystkie sześć
kolorów palety na każdym z trzech sprawdzonych zoomów** (z12, z13, z14) —
w tym na tym najgorszym kaflu z 39 nakładającymi się śladami, który przed
zmianą był w 100% `#2C6B4F`.

### Heatmapa społeczności zamiast palety (2026-08-28)

Dzień po kolorach śladów user zapytał o coś, co brzmiało jak żądanie
wygenerowania kafli z góry, a okazało się pytaniem o SENS palety w INNEJ
skali: „przyjmijmy przypadek 1000 userów dziennie po 100 gpx (…) co mi da,
że będę miał 500 śladów po tej samej drodze w ramach społeczności".

**Paleta z migr. 073 rozwiązuje inny problem niż skala.** Dobrze odróżnia
kilkadziesiąt RÓŻNYCH tras obok siebie — do tego powstała. Przy tysiącach
przejazdów dziennie większość z nich to NIE różne trasy, tylko ten sam
odcinek przejechany setki razy — a paleta ma tylko sześć kolorów
(`TrackPalette::COLORS`), więc przy takiej gęstości i tak zaczęłaby się
powtarzać losowo, dając confetti zamiast informacji.

**Klucz `all` (mapa społeczności) dostał inny styl: `STYLES['heat']`** —
jeden kolor, niska `alpha`, WSZYSTKIE ślady w jednej grupie zamiast osobnej
na każdy. To NIE jest powrót do błędu sprzed migracji 073 (tamten miał
pełną krycie — stąd „jedna zielona plama", od której nie dało się odróżnić
NICZEGO). Przy niskiej kryciu `imagealphablending` w `TileRenderer::canvas()`
samo sumuje przezroczystość tam, gdzie linie się nakładają: droga, którą
przejechało 50 osób, wychodzi wyraźnie ciemniejsza niż ta, którą przejechała
jedna — bez liczenia w bazie, ile razy dany fragment przejechano. To ten sam
efekt, co Strava Global Heatmap albo Trailforks Ride Heatmap, tylko policzony
za darmo przez kompozycję rastra zamiast dopasowywania geometrii do
kanonicznych segmentów (user pytał wprost o Trailforks — to jest świadomie
tańszy wariant tego samego pomysłu, bez nowych tabel i bez silnika
geoprzestrzennego).

**`me`/`u-{slug}`/`e-{id}`/`ev-{id}` NIE są tym ruszone.** Tam obiektów jest
mało (pojedyncze mapy, kronika turnusu), więc odróżnienie ich kolorem dalej
ma wartość — i tam paleta z migr. 073 zostaje dokładnie taka, jak była.

**Psychologia, nie tylko wydajność** (patrz zasady w [[feedback_gamification_psychology]]):
osobny, dobrze widoczny kolor na każdy ślad niechcący tworzy framing „mój
ślad musi się wyróżniać" — blisko zero-sumowego „byłem pierwszy". Wspólna
mapa, na której popularna droga robi się cieplejsza z KAŻDYM kolejnym
przejazdem, jest zaproszeniem, nie rywalizacją: przejazd osoby, która
dołącza jako pięćsetna, WIDOCZNIE dokłada się do czegoś, zamiast ginąć jako
nieodróżnialna kreska w tle.

**Ten sam dzień: `alpha` podbite z 0.14 na 0.25.** Zgłoszenie usera po
sprawdzeniu na żywo — na danych DEV (patrz niżej: tylko 7 śladów w bazie)
pojedynczy przejazd przy 0.14 był tak blady, że łatwo było go przeoczyć,
myśląc, że warstwa w ogóle nic nie rysuje. 0.25 to dalej wyraźnie mniej niż
`STYLES['real']` (1.0), więc popularna droga wciąż wyraźnie odcina się od
rzadkiej — kalibracja NIE jest zmierzona na produkcyjnej skali (user
o tym uprzedzony), tylko na tym, co dziś widać w bazie deweloperskiej.

**Solo NIE weszło do `all` — user pytał wprost, odpowiedź: nie, i to
świadomie.** *(ODWRÓCONE TEGO SAMEGO DNIA — patrz sekcja „Solo w heatmapie
społeczności" niżej. Akapit zostaje w całości: uzasadnia, DLACZEGO naiwne
dorzucenie `rider_activities.gpx_url` było odrzucone, i to uzasadnienie jest
dokładnie tym, co druga, przycięta para tabel niżej rozwiązuje.)* Baza DEV
ma tylko 7 różnych plików w `edition_tracks`, więc
user zasugerował dorzucenie przejazdów solo (`rider_activities`), żeby
heatmapa miała więcej danych do pokazania. **To by cofnęło naprawę błędu
z 2026-08-26** (patrz `TileSource::tracks()`, klucz `all`, i `§27` wyżej):
plik solo jest surowy i zaczyna/kończy się pod domem rowerzysty — dlatego
solo trafia WYŁĄCZNIE na klucz `me` (prywatny, niecache'owalny), nigdy na
klucz publiczny leżący na dysku pod adresem do zgadnięcia. Przycinanie tego
pliku PRZED zapisem było próbowane i cofnięte (psuło przejechane kilometry —
`RiderActivity::recordSolo`), więc dziś nie ma bezpiecznej, przyciętej kopii,
którą dałoby się tu podstawić bez przepisania czegoś większego. Rzadkie dane
w DEV to ograniczenie ŚRODOWISKA TESTOWEGO (przy 1000 userów/100 gpx dziennie,
o których user sam wspomniał, `edition_tracks` samo w sobie da gęstość), nie
powód do osłabiania prywatności w kodzie produkcyjnym. Jeśli heatmapa
kiedyś ma pokazywać też solo, potrzebny jest OSOBNY krok przycinający
geometrię specjalnie pod ten widok (nowa kolumna/tabela, nie ponowna próba
przycinania pliku źródłowego) — to nowa decyzja architektoniczna, nie
poprawka tego zadania.

Testy: `tests/kolory_sladow_test.php` rozszerzony o „`all` wychodzi JEDNĄ
grupą, niską kryciem, wspólnym kolorem" — pilnuje DOKŁADNIE tego, przed czym
ostrzegał komentarz nad starym testem („ktoś uprości `tracks()` z powrotem
do jednej grupy i mapa znowu robi się jednolita") — z tą różnicą, że tym
razem uproszczenie jest zamierzone i ma niską krycie, więc test sprawdza
`alpha`, nie tylko liczbę grup.

**Uruchomienie po wdrożeniu** — jak przy każdej zmianie KODU renderera:
`php tiles.php purge slady` (albo „Reset kafli" → warstwa Ślady w
`/admin/kafle`), inaczej stare kafle klucza `all` (namalowane jeszcze
paletą, pełną kryciem) zostają na dysku pod `Cache-Control: immutable`.

### Solo w heatmapie społeczności (migr. 076, 2026-08-28)

Ten sam dzień, kolejne zgłoszenie: „ja byłem przekonany że warstwa ślady
oraz heat map zawiera wszelkie ślady nie tylko te przypisane do eventów (…)
odkrycia na mapie społeczności pokazują odkryte pola ale chcąc zobaczyć
ślady to nic nie widać. To jest pewien rozjazd dla mózgu bo chciałbym na
odkrytym klocku zobaczyć ślad a nie widzę go".

**Diagnoza była trafna.** Pola odkryć (`Odkrycia`) liczą się ze WSZYSTKICH
przejazdów — event i solo (`RiderActivity`, oba źródła trafiają do
`discovery_cells`). „Ślady" liczyły WYŁĄCZNIE `edition_tracks`. Solo to
WIĘKSZOŚĆ tego, co ludzie realnie jeżdżą (to samo zdanie padło już raz przy
naprawie tej samej rozbieżności na mapie WŁASNEJ, 2026-08-26) — więc odkryte
pole prawie zawsze nie miało pod sobą linii na mapie SPOŁECZNOŚCI, mimo że
na mapie OSOBISTEJ (klucz `me`) ta sama linia widniała od dawna.

**To NIE JEST cofnięcie decyzji sprzed akapitu wyżej — to jej rozwiązanie.**
Powód, dla którego solo było wykluczone, zostaje w 100% aktualny: plik solo
jest surowy i zaczyna/kończy się pod domem, a `all` leży publicznie na
dysku pod adresem do zgadnięcia. Różnica: znaleziono mechanizm, który JUŻ
istniał w kodzie do DOKŁADNIE tego samego problemu przy polach odkryć —
`RiderActivity::recordSolo` przycina punkty w promieniu „domowym"
(`Utils\DiscoveryGrid::trimEnds`, `DiscoveryScoring::homeTrimRadiusM()`)
ZANIM policzy z nich pola, właśnie po to, żeby odkryte pole nie zdradzało
adresu. Te przycięte punkty istniały już w pamięci przy każdym wgraniu —
tylko nigdy nie były zapisywane, bo nikt jeszcze nie potrzebował ich
gdzie indziej.

**Migracja 076 daje im drugi dom: `gpx_geometry_trimmed`/`gpx_tiles_trimmed`**
— para tabel tego samego kształtu co `gpx_geometry`/`gpx_tiles`, kluczowana
TYM SAMYM `gpx_hash` (relacja 1:1 „przycięty wariant tego pliku"). Osobna
tabela, nie osobna kolumna: pomylenie dwóch KOLUMN tej samej tabeli byłoby
dużo łatwiejsze niż pomylenie dwóch osobno nazwanych metod
(`GpxGeometry::load()` kontra `loadTrimmed()`) — a to jest dokładnie ten
błąd, którego cała ta migracja miała nie dopuścić.

**`TileSource::tracks('all')` niesie od teraz DWIE grupy**, obie stylem
`heat`: `$eventGroup` (jak dotychczas, źródło `normal`) i `$soloGroup`
(`rider_activities` solo, źródło `'trimmed'`). `TileController::renderTracks()`
czyta klucz `source` per grupa i pyta o geometrię z WŁAŚCIWEJ pary tabel —
nadal jedno zapytanie na źródło dla całego kafla, źródeł jest dziś dwa,
nie tyle, ile grup. Zero kosztu dla nowych przejazdów: `RiderActivity::
recordSolo` woła `GpxGeometry::ensureTrimmedFromPoints()` od razu po
przycięciu punktów pod pola — ten sam zestaw, druga tabela, żadnego
dodatkowego parsowania. Solo wgrane WCZEŚNIEJ dostaje geometrię z backfillu
(`php tiles.php backfill` liczy teraz oba warianty jednym przebiegiem;
panel `/admin/kafle` ma osobny licznik „Solo bez przyciętej geometrii").

Testy: `tests/przejazdy_solo_test.php` („wspólna warstwa Ślady niesie solo —
PRZYCIĘTE, nigdy surowe") sprawdza SEDNO — geometria pod hashem solo w
źródle `trimmed` ma MNIEJ punktów niż plik pełny, nie tylko że hash „gdzieś
się pojawia". `tests/heatmapa_spolecznosci_test.php` pilnuje samej mechaniki
(`ensureTrimmed`/`ensureTrimmedFromPoints` idempotentne, `inTileTrimmed`
zwraca hash tylko dla kafli na trasie, `backfillAllTrimmed`/
`missingTrimmedCount` się zgadzają). `tests/kolory_sladow_test.php` — test
„`all` wychodzi JEDNĄ grupą" przestał być prawdziwy (może wyjść ich dwie),
zastąpiony testem „KAŻDA grupa `all` niesie styl `heat`", który sprawdza to,
co naprawdę miało znaczenie, niezależnie od liczby grup.

**Uruchomienie po wdrożeniu**: migracja `076` + `php tiles.php backfill`
(liczy przyciętą geometrię dla solo sprzed migracji) + `php tiles.php purge
slady` (stare kafle `all` nie wiedzą, że solo już powinno się na nich
pojawić — leżą na dysku pod `Cache-Control: immutable`).

**Dzień później: kolor zmieniony z zieleni brandowej na pomarańczowy**
(uwaga usera: zielona linia ginie na szarej mgle, a na standardowym OSM —
z lasami — ginie jeszcze bardziej). `STYLES['heat']['color']` to teraz
`#D2731A`, czyli `Utils\TrackPalette::COLORS[4]` — TA SAMA baza kolorów, co
„Ślady własne", żeby heatmapa nie wprowadzała siódmego odcienia do
aplikacji. Pomarańczowy konkretnie, nie dowolny inny z palety: ta sama
rodzina barw, którą warstwa „Heatmapa" (pola, `TileController::SCALE`) już
mówi o natężeniu (żółty → pomarańczowy → czerwony = częściej tu bywają) —
dwie heatmapy tej samej strony mówią teraz jednym językiem koloru. Wymaga
`php tiles.php purge slady` po wdrożeniu, jak każda zmiana koloru stylu.

### Klik we własny ślad (2026-08-27)

Prośba usera zaraz po kolorach: „dodajmy możliwość klikania na solo ślady tak
samo jak na znane trasy". Szlaki odpowiadały na kliknięcie od 2026-08-20
(`/api/discovery/trails/at`); ślady były do tej pory nieme — kafel to obrazek,
więc bez pytania do serwera nie ma w co kliknąć.

**Bliźniak endpointu tras, z jedną zasadniczą różnicą: WYMAGA ZALOGOWANIA
i odpowiada wyłącznie o WŁASNYCH przejazdach.** Tamten opisuje publiczny
katalog szlaków (to samo, co stoi otworem pod `/trasy`); ten opisuje czyjeś
przejazdy, a plik solo jest surowy i zaczyna się pod czyimś domem (§27).
`user_id` bierze się **z sesji, nigdy z żądania** — gdyby szedł parametrem, ten
endpoint byłby czytnikiem cudzych tras domowych po samym podstawieniu liczby.
Gość dostaje **404, nie 401**: odpowiedź nie ma zdradzać, że coś tu jest (ta
sama zasada co przy ukrytym profilu i kaflu spod niedostępnego klucza).
Endpoint jest podawany mapie **tylko tam, gdzie warstwa „Ślady" pokazuje ślady
PYTAJĄCEGO**: na zakładce osobistej `/odkrycia`, w apce (`discovery-app.php`)
i — od 2026-09-03 — na WŁASNYM profilu rowerzysty (`mapEndpoints.ridesAt`
w `RiderController::show`, `null` dla każdego innego widza). Na wspólnej mapie
i na cudzym profilu nie ma solo, w co można by kliknąć, a dymek o własnych
przejazdach nad cudzą warstwą `u-{slug}` opowiadałby o czymś innym, niż widać.

**TRAFIENIE LICZONE NA GEOMETRII, NIE NA POLACH** — jedyna świadoma różnica
względem `KnownRoute::atPoint()`, które pyta o pola siatki. Dwa powody:

1. **Pole ma ok. 500 m**, a wokół domu przejazdy leżą jeden na drugim
   (zmierzone na koncie zgłaszającego: **45 śladów w jednym kaflu z14**).
   Trafienie „po polach" zwróciłoby tam wszystkie naraz — czyli byłoby
   bezużyteczne dokładnie tam, gdzie ma pomóc. Kolory rozdzieliły te linie
   wizualnie dzień wcześniej; klik musi umieć rozdzielić je tak samo.
2. **Przejazd solo ma przycięte końce w polach** (§27), ale rysuje się
   z CAŁEGO pliku. Trafienie po polach nie odpowiadałoby więc na kliknięcie
   w widoczną linię przy domu — a to jest linia, którą widać.

Kandydatów zawęża indeks kafli (`gpx_tiles`) — kafel punktu **i jego sąsiedzi**,
bo klik tuż przy krawędzi może trafić w ślad zapisany pod innym `tx/ty`.
Potem liczy się odległość punkt–**ODCINEK**, nie punkt–punkt: punkty GPS bywają
rzadkie (zjazd co 10 s to ponad 200 m), więc mierzenie do najbliższego punktu
gubiłoby trafienia w środku długiego odcinka — czyli tam, gdzie linia jest
najprostsza i najłatwiej w nią kliknąć. To ten sam powód, dla którego
`GpxGeometry::tileSet()` uzupełnia przerwy przy indeksowaniu. Pętla wychodzi na
pierwszym trafieniu w tolerancję (nie potrzebujemy prawdziwego minimum, tylko
odpowiedzi „czy i jak blisko"). **Zmierzone na najgorszym realnym przypadku**
(45 śladów, 262 tys. punktów w jednym kaflu): **ok. 90 ms na kliknięcie** —
koszt jednego kliknięcia, nie przesuwania mapy. Pudło: 0,6 ms.

**Tolerancja skalowana powiększeniem**, jak przy szlakach (ok. 12 px
przeliczonych na metry), ale z niższym dnem — **60 m zamiast 120 m** — bo tu
trafiamy w LINIĘ, a nie w pole o boku pół kilometra: przy dużym przybliżeniu
da się i trzeba celować.

**Dymek mówi to samo, co wiersz w zakładce „Przejazdy solo"**: nazwa (z licznika,
gdy jest), data, dystans, przewyższenie, nowe pola, punkty — plus **kropka
w kolorze linii**, bo od migracji 073 każdy ślad ma własny kolor i przy dwóch
przejazdach w jednym dymku to jedyna rzecz, która mówi, który jest który.
Budowany element po elemencie (`textContent`), nie sklejany z HTML-a — nazwa
przychodzi z Garmina/Polara, czyli spoza serwisu.
**„Zobacz przejazd" prowadzi na listę przejazdów solo ZAWĘŻONĄ do jego daty**
(szukanie po dacie dodane 2026-08-26) — solo nie ma własnej strony, a to jest
miejsce, gdzie da się z nim cokolwiek zrobić: obejrzeć na mapie, powiązać
z wyjazdem, usunąć. Przejazd z turnusu prowadzi na stronę wydarzenia.

Osobny `AbortController` niż przy szlakach i osobne żądanie — przy obu warstwach
zapalonych jedno kliknięcie posyła dwa żądania, a wspólny kontroler kasowałby
jedno z nich w locie. Scalenie ich w jedno żądanie znaczyłoby pytanie o ślady
także wtedy, gdy warstwa „Ślady" jest zgaszona.

Testy: dopisane do `php tests/run.php przejazdy_solo` (5 nowych — klik w punkt
na śladzie trafia, klik 100 km obok nie trafia, **cudzego śladu nie da się
kliknąć** (najważniejszy — to ochrona adresu domowego), trafienie niesie komplet
danych z kolorem z palety, tolerancja rośnie z oddaleniem). Zweryfikowane żywo
prawdziwym żądaniem HTTP: trafienie zwraca komplet, pudło pustą listę,
niezalogowany 404, brak współrzędnych 400; `ridesHitEndpoint` jest w źródle
`/odkrycia` na zakładce osobistej i **nie ma go** na wspólnej; link z dymka
faktycznie otwiera listę z tym przejazdem.

**Domknięcie na profilu rowerzysty (2026-09-03).** Zgłoszenie usera: „klikam
aktywność na `/rowerzysta/{slug}`, trasa się oznacza, ale klik w mapę nie daje
dymka — na `/odkrycia` przy tym samym ruchu dostaję dymek z przyciskiem".
Profil miał już wszystko poza jedną opcją: od 2026-08-27 właściciel widzi tam
warstwę `me` (z solo), panel aktywności i podświetlanie śladu to ten sam moduł,
ale `ridesHitEndpoint` nigdy tam nie trafił — dopisany był tylko do `/odkrycia`
i do apki. Poprawka to jeden klucz w `mapEndpoints` (owner → adres, reszta →
`null`) i jedna linia w widoku; mechanika, dymek i zasięg odpowiedzi bez zmian.
Zweryfikowane żywo na `/rowerzysta/historical`: klik w wiersz „Przejazd solo
14 sie", potem klik w narysowaną linię → dymek „14 sie · 33 km · 762 m w górę,
68 nowych pól, +440 pkt" z przyciskiem „Zobacz przejazd"; na cudzym profilu
(`marek-kowalski`) w źródle stoi `ridesHitEndpoint: null` przy warstwie
`u-marek-kowalski`, a niezalogowany dostaje `null` tak samo.

### Kadr startowy odporny na odległy wyjazd (2026-08-27)

Zgłoszenie usera: „na /odkrycia centrowanie działa dość słabo, ponieważ centruje
poza zakresem moich osiągnięć lub społeczności. Sugerowałbym łapać bbox dla
kafli i na tej zasadzie wyznaczyć środek".

**Znalezione na żywo, nie zgadnięte**: rowerzysta z 89 przejazdami solo wokół
Mielca miał JEDEN wyjazd rowerowy na Teneryfę (4500 km dalej, prawdziwy plik
GPX z realnymi znacznikami czasu — nie błąd GPS ani śmieć w danych). Naiwny
`Discovery::boundsFor()` liczył MIN/MAX po WSZYSTKICH polach naraz: prostokąt
wyszedł Ocean Atlantycki–Polska (rozpiętość 39° długości geograficznej), a jego
ŚRODEK — Barcelona — nie leżał blisko żadnego z dwóch skupisk. Community miało
ten sam objaw jeszcze mocniej: prostokąt RPA–Filipiny.

**To NIE był błąd arytmetyki, i to jest ważne rozróżnienie.**
`DiscoveryGrid::boundsFromAxialExtremes()` liczy ŚCIŚLE, nie w przybliżeniu —
`x` (długość geograficzna) zależy WYŁĄCZNIE od `s = 2q + r`, a `y` (szerokość)
WYŁĄCZNIE od `r`, więc prostokąt skrajnych wartości jest matematycznie
dokładnym prostokątem obejmującym zbiór, nie nadzbiorem. Błędem było WYŁĄCZNIE
założenie „wszystkie odkrycia jednej osoby leżą w jednym zwartym obszarze".

**Sugestia usera („bbox z kafli") NIE ROZWIĄZAŁABY tego** — sprawdzone, nie
domysł: geometria śladu z Teneryfy (`gpx_geometry`, migr. 051) ma DOKŁADNIE ten
sam skrajny punkt co jego pola odkryć. Zamiana źródła danych z jednej tabeli na
drugą nie zmienia tego, że min/max po WSZYSTKICH rekordach jednej osoby zawsze
obejmie każdy jej wyjazd, bez względu na to, skąd te rekordy pochodzą. Problem
był w AGREGACJI, nie w ŹRÓDLE.

**Naprawa — dominujące skupisko, nie naiwny MIN/MAX.**
[`Discovery::boundsFor()`](../core/Models/Discovery.php) liczy naiwny prostokąt
jak dotychczas (jedno zapytanie, bez zmian). Dopiero gdy jego rzeczywista
rozpiętość przekracza `CLUSTER_TRIGGER_KM` (1000 km — szerzej niż jakikolwiek
kraj środkowoeuropejski, żeby normalny zasięg NIGDY nie płacił za drugie
zapytanie), DRUGIE zapytanie dzieli pola na kubełki `CLUSTER_BUCKET_KM` (150 km)
i bierze NAJWIĘKSZY — plus jego sąsiadów o jeden kubełek w każdą stronę, żeby nie
uciąć skupiska leżącego akurat w poprzek granicy kubełka. Mapa startuje tam,
gdzie faktycznie jest większość odkryć, a nie w geometrycznym środku między
dwoma odległymi miejscami. **Koszt drugiego zapytania płaci WYŁĄCZNIE ten, kto
już ma odległy wyjazd** — zero regresji dla typowego przypadku (jedna osoba albo
cała społeczność w jednym kraju kończy na pierwszym zapytaniu, jak zawsze).

Ta sama naprawa obejmuje OBIE mapy (`boundsFor(userId)` i `boundsFor(null)`),
bo to jedna metoda z jedną gałęzią różniącą wyłącznie źródło kolumn
(`discovery_cells` z rozpakowywaniem bitowym vs `discovery_cell_totals`
z gotowymi `cell_q`/`cell_r`) — wspólny prywatny `axialExtremes()` trzyma jeden
SELECT dla obu wywołań (naiwnego i zawężonego do kubełka), żeby nie istniał
w dwóch kopiach.

Testy: nowy `php tests/run.php kadr_mapy` (6 przypadków — brak danych = null,
zwarty klaster daje ciasny kadr, odległy wyjazd NIE wchodzi do kadru (test tej
regresji wprost), kadr jest WYRAŹNIE mniejszy niż naiwny MIN/MAX (liczbowo, nie
tylko „czy punkt jest w środku"), izolacja między osobami, community). Testy
używają SYNTETYCZNYCH współrzędnych — błąd zależy od tego, jak rozłożone są
czyjeś przejazdy, a dane DEV będą się zmieniać. **Pułapka złapana przy pisaniu
testów, warta zapamiętania**: pierwszy `t_user()` z puli testowej miał już 373
prawdziwe pola w `discovery_cells` z danych DEV — test dopisujący własny klaster
sprawdzał w rzeczywistości kadr MIESZANKI dwóch rzeczy, nie samego klastra
testowego. Naprawione osobnym helperem szukającym usera bez ANI JEDNEGO
istniejącego pola. Zweryfikowane żywo prawdziwym żądaniem HTTP na koncie
zgłaszającego: `mapBounds` w źródle `/odkrycia` to teraz `49.14–50.68°N,
20.21–22.71°E` (Podkarpacie, tam gdzie realnie jeżdżi) — dokładnie to, co liczy
`Discovery::boundsFor(9)` wywołane wprost, i dokładnie BEZ Teneryfy.

**Ciąg dalszy tego samego dnia — user zapytał wprost: „sprawdź to samo dla
profilu rowerzysty".** Kadr profilu (`RiderController::show`, `tileBounds`) NIE
idzie przez `Discovery::boundsFor()` — to zupełnie inny kod,
[`GpxGeometry::boundsFor()`](../core/Models/GpxGeometry.php), liczący z PIKSELI
Merkatora (`gpx_geometry.min/max_px/py`), nie z osi heksagonu. Sprawdzone, nie
założone: ta sama klasa błędu tam BYŁA — na koncie zgłaszającego (klucz `me`,
wszystkie 89 śladów solo naraz, bo profil bez publicznego adresu pokazuje
dokładnie ten sam zestaw co własna mapa) naiwny prostokąt wychodził
`28,10–54,54°N` — Teneryfa do Polski, ten sam objaw co na `/odkrycia`.

**Ta sama naprawa, przełożona na inny układ współrzędnych.** `boundsFor()` liczy
naiwny prostokąt jak dotychczas (jedno zapytanie), a dopiero gdy jego
rzeczywista rozpiętość (piksele × `TileGrid::metersPerPixel()` na 52°N —
szerokość charakterystyczna dla serwisu) przekroczy 1000 km, drugie zapytanie
dzieli ślady na kubełki ~150 km i bierze najliczniejszy. **Jednostką
klastrowania jest tu cały PLIK, nie punkt** (w odróżnieniu od `Discovery`, gdzie
jednostką jest pojedyncze pole odkryć) — bucketuje się po ŚRODKU bboxa każdego
śladu (`(min_px+max_px)/2`, `(min_py+max_py)/2`), bo to pliki nakładają się albo
nie na inne pliki, nie ich pojedyncze punkty.

**Pułapka złapana przy pierwszym uruchomieniu na prawdziwych danych, nie
w code review**: `pixelExtremes()` filtrował kubełek dwoma warunkami na OSI X
i OSI Y, obiema zbudowanymi z tej samej zmiennej `$bucketPx` pod TĄ SAMĄ nazwą
placeholdera (`:bucketPx` użyte dwa razy w jednym zapytaniu) —
`EMULATE_PREPARES=false` tego nie pozwala i każde wywołanie z aktywnym
klastrowaniem kończyło się `PDOException: Invalid parameter number`. Złapane,
bo test uruchomiono na REALNYM koncie z prawdziwym odległym wyjazdem (jedyny
sposób, żeby gałąź klastrowania w ogóle się wykonała) — testy syntetyczne same
by tego nie wymusiły, gdyby ich rozpiętość akurat nie przekroczyła progu.
Naprawione dwiema różnymi nazwami (`:bucketX`/`:bucketY`) na tę samą wartość.

Testy: dopisane do `php tests/run.php kadr_mapy` (4 nowe — pusta lista = null,
jeden ślad daje kadr obejmujący go, odległy wyjazd nie wchodzi do kadru,
rozpiętość wyraźnie mniejsza niż naiwny MIN/MAX). **Pułapka w SAMYCH testach**:
pierwsza wersja sprawdzała, czy punkt STARTOWY śladu (czyli dokładnie jego
własny skrajny róg bboxa) mieści się w policzonym z niego kadrze — przejście
przez piksele całkowitoliczbowe (`toPixel`/`toLatLon`) potrafi taki punkt
zepchnąć o ułamek promila poza jego własną krawędź. Naprawione sprawdzaniem
punktu ŚRODKOWEGO śladu, bezpiecznie wewnątrz, nie na granicy. Zweryfikowane
żywo: `GpxGeometry::boundsFor()` na prawdziwych 55 hashach klucza `me` konta
zgłaszającego (po realnym `ensure()` na wszystkich plikach solo, łącznie
z Teneryfą) daje `49,14–50,55°N` — 157×175 km wokół Mielca, BEZ Teneryfy;
publiczny kadr (`u-{slug}`, sam zawsze bezpieczny, bo solo nigdy tam nie
wchodzi — §27) pozostał bez zmian.

## Skarby — punkty w terenie (Etap 8D, migr. 054–060)

**Druga, przeciwstawna czynność wobec Discovery.** Pole odkryć ma ok. 500 m i zalicza się
samo ze śladu GPS — nagradza PRZEJECHANIE terenu. Skarb wymaga zatrzymania się, zejścia
z roweru i rozejrzenia; ma współrzędne z dokładnością do metrów. Stąd osobna tabela
i osobne źródło punktów (`TREASURE_FOUND` w [`PointLedger`](../core/Models/PointLedger.php)),
mimo że jedno i drugie kończy się liczbą przy nazwisku. Kolumna `cell_id` służy WYŁĄCZNIE
do rysowania ikonki na mapie i do zawężania kandydatów przy zaliczaniu ze śladu.

**DYMEK SKARBU: NA TELEFONIE AKCJA, NA KOMPUTERZE OPIS** (2026-08-20, zgłoszenie usera:
„skarby, które przeglądamy i klikamy na komputerze, nie powinny mieć przycisku »jestem
tutaj«, nie ma to najmniejszego sensu (…) rozbudowałbym o okienko z informacjami, które
już są zawarte w skarbie"). Zaliczenie wymaga STANIA przy skarbie, więc przy biurku
przycisk zawsze kończył się komunikatem „nie ma tu skarbu w zasięgu" — obiecywał operację,
która z definicji musiała się nie udać. Decyduje `pointer: coarse` (palec jako podstawowy
wskaźnik = telefon/tablet); laptop z ekranem dotykowym, ale i myszą, raportuje `fine`
i przycisku nie dostaje. **To wykrywanie sposobu obsługi, nie systemu.** Zamiast przycisku
komputer dostaje zdanie, gdzie ten przycisk jest — bez niego jego brak wyglądałby jak
usterka. Sam dymek pokazuje teraz wszystko, co skarb o sobie wie i co wolno pokazać:
kategorię, rzadkość, region, zdjęcie, opis, `+punkty`, **ile osób go znalazło** (liczba,
nigdy nazwiska — §27) i promień zaliczenia. **Ukryty skarb nie dostaje z tego nic**:
`Treasure::reveal()` zdejmuje opis, rzadkość, zdjęcie i liczbę znalazców razem z nazwą —
opis „stary młyn nad rzeką" zawęża poszukiwania dokładnie tak samo jak nazwa.
**Dymek dostaje też GOŚĆ** (do tej daty wymagał zalogowania): odkąd niesie głównie
informację o tym, co stoi w terenie, nie ma powodu chować go za logowaniem — ta sama
zasada co przy dymku znanej trasy. Zamiast przycisku gość widzi zaproszenie do założenia
konta. Skarb JUŻ ZNALEZIONY nadal nie ma dymka, tylko podpis „masz" — nie ma tam żadnej
akcji do wykonania.

**PANEL „SKARBY, KTÓRE CZEKAJĄ" MA DWIE ZAKŁADKI** (2026-08-20, zgłoszenie usera: „nie da
się na nie kliknąć, więc trudno je zlokalizować; fajnie by było, jakby był tab i w drugim
»W obszarze«"). Nakładka w rogu wspólnej mapy (`/odkrycia?mapa=spolecznosc`) odpowiada
teraz na dwa różne pytania:
- **„Czekają"** — o CZAS: co stoi nietknięte najdłużej (`Treasure::waitingList`, lista
  z serwera, niezależna od kadru). Wiersze są PRZYCISKAMI: kliknięcie przesuwa mapę na
  skarb i otwiera jego dymek. Pozycji NIE MA w HTML-u — strona dobiera ją osobnym
  żądaniem `/api/treasures/{id}`, czyli przez tę samą bramkę ujawnienia co warstwa mapy;
  404 („nie masz prawa go widzieć") wiersz pokazuje jako „ukryty — najpierw odkryj ten
  teren", tym samym komunikatem co dla nieistniejącego.
- **„W obszarze"** — o MIEJSCE: co leży w bieżącym kadrze, **po punktach malejąco**.
  Przelicza się z tej samej odpowiedzi, którą mapa i tak pobiera dla swoich pinezek
  (`options.onTreasures`), więc przesuwanie mapy nie kosztuje ani jednego dodatkowego
  żądania. Przy oddaleniu serwer oddaje pęczki (same liczby, bez nazw), więc lista mówi
  wprost „N skarbów w tym kadrze — przybliż, żeby zobaczyć listę".

**PANEL ADMINA: MAPA PO LEWEJ, FORMULARZ PO PRAWEJ** (2026-08-20, zgłoszenie usera: „nie mam
możliwości przesunięcia zakotwiczenia, brak możliwości usunięcia; sądziłem, że kliknę
w punkt na mapie i po prawej otworzy się formularz"). Wcześniej formularz siedział
w modalu `<dialog>`, który zasłaniał mapę — czyli dokładnie to, na co trzeba patrzeć,
poprawiając położenie punktu; klik w mapę zawsze znaczył „nowy skarb", a kliknięcie
w istniejącą pinezkę nie robiło nic. Teraz:
- **klik w pinezkę** wybiera skarb do edycji w panelu obok, a wybrana pinezka jest
  JEDYNĄ PRZECIĄGALNĄ na mapie (`dragend` → współrzędne w formularzu; zapis jak zwykle);
  przeciąganie tylko dla wybranego, żeby nie przesunąć nie tego punktu,
- **klik w pustą mapę** stawia nowy punkt (jak dawniej),
- **kasowanie** — tylko punktu, którego NIKT nie znalazł (`Treasure::deleteIfUnfound`);
  znaleziony zapłacił punktami z niezmiennego rejestru, więc schodzi ze sceny statusem
  „Wycofany", a przycisk mówi to wprost, zanim ktokolwiek kliknie,
- **lista pod mapą** dostała komplet narzędzi z panelu znanych tras (liczniki, zakładki
  filtrów, szukanie po nazwie/kodzie/regionie/kategorii, sortowanie, strony po 25) —
  odpowiedź na pytanie „co w przypadku 1000 punktów?",
- **mapa pobiera KADR, nie całość** (`/admin/skarby/punkty`): do tej daty widok wsypywał
  wszystkie skarby do HTML-a jako JSON i rysował je co do jednego.
Testy: `php tests/run.php skarby`.

**WARSTWA SKARBÓW MUSI BRONIĆ SIĘ TAK SAMO JAK WARSTWA PÓL** (2026-08-23, zgłoszenie
usera: „skarby się na produkcji od razu nie ładują, trzeba rozzoomować i zoomować").
Dwie bramki na ten sam warunek rozjechały się w jednym pliku: `refresh()` przy
kontenerze bez rozmiaru ponawiał próbę, a `refreshTreasures()` **rezygnował** — i badał
tylko szerokość, więc kontener o zerowej WYSOKOŚCI przechodził dalej i wysyłał kadr,
w którym `north === south`. Serwer słusznie odpowiadał pustką, warstwa czyściła się do
zera i tak zostawało do pierwszego ruchu mapą. Objaw był mylący, bo pola i kafle
ładowały się normalnie — mapa wyglądała na sprawną. Druga połowa usterki: przerywanie
żądania w locie było BEZWARUNKOWE, a `scheduleRefresh` woła warstwę co 250 ms, więc na
łączu wolniejszym niż to (telefon w terenie) każde wywołanie ubijało poprzednie i skarby
nie pojawiały się nigdy. Dziś żądanie o ten sam kadr nie jest ponawiane, a zakończone
zwalnia slot — inaczej `window.ridemoreRefreshTreasures()` (zaliczenie z lokalizacji)
przestałoby przerysowywać ikonkę. Pilnuje tego test `mapa: obie warstwy bronią się
przed kadrem bez rozmiaru tak samo`.

**DYMEK GASŁ SAM PO KLIKU W PINEZKĘ** (2026-09-04, zgłoszenie usera: dymek pojawiał
się, po kilku sekundach znikał bez żadnej akcji, a pod nim było widać tooltip). Klik
blisko krawędzi mapy uruchamia Leafletowe `autoPan` (domyślnie włączone) — mapa
dosuwa widok, żeby dymek zmieścił się w kadrze, a to jest zwykły `moveend`, ten sam,
który wyżej uruchamia `scheduleRefresh(true)`. Odświeżenie czyści WARSTWĘ i rysuje ją
od nowa, więc znacznik pod otwartym dymkiem znika, a Leaflet, usuwając znacznik z
mapy, sam zamyka jego dymek (`remove: this.closePopup` w rdzeniu biblioteki) —
znika bez śladu, dokładnie po czasie jednego dodatkowego przelotu do serwera (ułamek
sekundy lokalnie, realne „kilka sekund" w terenie na wolniejszym łączu). Lek jest
ten sam, co przy kliku z listy „Skarby w pobliżu": `focusPending` otwiera TEN SAM
skarb na nowym znaczniku zaraz po przerysowaniu (patrz `map.ridemoreFocusTreasure`
niżej) — różnica jest tylko taka, że teraz włącza się przy KAŻDYM otwarciu dymka
(`znacznik.on('popupopen', ...)`), nie tylko z listy. **Znany, nienaprawiony róg
przypadku**: na bardzo oddalonej mapie (cała Polska) `focusPending` szuka
PINEZKI o danym id, a po przesunięciu autoPanem serwer potrafi zwrócić ten sam
punkt jako część PĘCZKA — wtedy dymek nie wraca (tak samo ograniczony jest klik z
listy, gdyby nie wymuszał zoomu ≥15 w `ridemoreFocusTreasure`). W praktyce nie
występuje: pinezka jako pojedyncza ikonka (a nie pęczek) pojawia się dopiero przy
zbliżeniu, na którym autoPan rzadko przesuwa kadr aż tak daleko.

Druga część tego samego zgłoszenia: znacznik ma i tooltip (`podpis`), i dymek — klik
najpierw najeżdża (tooltip się pokazuje), dopiero potem otwiera dymek na TYM SAMYM
zaczepie (`direction:'top'` u obu), więc dolna krawędź dużego dymka nachodziła na
tooltip tuż pod nim. Dymek mówi to samo i więcej, więc tooltip pod nim jest czystym
szumem — `znacznik.closeTooltip()` chowa go na czas otwarcia dymka (na hover wraca
normalnie po zamknięciu). Naprawa siedzi w jednym miejscu (`drawTreasures` w
[`discovery-map.js`](../assets/js/discovery-map.js)), więc obowiązuje na każdej
mapie ze skarbami — odkrycia, trasa, wyjazd, profil rowerzysty.

**Trzy drogi znalezienia, które się NIE sumują** ([`Models\Treasure`](../core/Models/Treasure.php)):
- `QR` — [`/skarb/{code}`](../core/Controllers/TreasureScanController.php), adres z naklejki.
  **GET tylko pokazuje, zalicza dopiero POST** — podglądy linków w komunikatorach pobierają
  strony w tle i zabierałyby skarb bez wiedzy właściciela telefonu. Brak lokalizacji NIE
  blokuje zaliczenia: kto stoi przy naklejce, ma kod, a blokada wykluczyłaby wszystkich
  z wyłączonym GPS-em. Nieznany kod nie zdradza, czy taki skarb w ogóle istnieje.
- `GPS` — `POST /api/treasures/claim` z mapy. Odległość liczy serwer z WŁASNYCH
  współrzędnych skarbu; przysłane `lat/lon` to deklaracja pozycji telefonu, nie dowód.
  Jedno potwierdzenie zalicza wszystkie skarby w promieniu.
- `GPX` — `Treasure::claimAlongTrack`, wołane z [`RiderActivity`](../core/Models/RiderActivity.php)
  po wgraniu śladu: co minąłeś po drodze, dostajesz bez klikania.

Klucz `UNIQUE (treasure_id, user_id)` w `treasure_finds` sprawia, że **skarb znajduje się
raz na osobę** niezależnie od drogi — skan i ślad tego samego dnia to jedno znalezienie.
Liczba znalazców jest publiczna („znaleziony przez 47 riderów"), lista nazwisk nigdy (§27).

**Poziomy ujawnienia** (`reveal_level`, fundament 8E). Jedyna bramka pozycji to
`Treasure::inBounds` — skarb o poziomie 0/1 dostaje ŚRODEK POLA zamiast współrzędnych,
i to samo dotyczy dymka `/api/treasures/{id}` oraz feedu na stronie publicznej. Kod
z naklejki nie wychodzi w żadnej odpowiedzi API. Znalazca widzi swój skarb w pełni,
niezależnie od poziomu, a skarb ukryty **nadal da się zaliczyć** — o to w nim chodzi.

**Zgłoszenia społeczności** ([`TreasureProposalController`](../core/Controllers/TreasureProposalController.php)).
To nie jest okrojony panel admina: zgłaszający podaje MIEJSCE („tu coś jest, warto
podjechać"), a nie wartość i zasięg. Punkt startuje jako `PROPOSED`, **nie płaci żadną
z trzech dróg** i widać go na mapie z zerową wartością. Aktywuje go próg potwierdzeń
(`treasures.confirmations_needed`, domyślnie 3 — trzy, bo jedno może być od kolegi
zgłaszającego; nie dziesięć, bo przy starcie modułu nikt by ich nie zebrał; próg siedzi
w `scoring_settings`, żeby rósł razem z ruchem) albo decyzja admina, która omija głosowanie.
Głos wymaga bycia w promieniu — potwierdzenie z kanapy nie jest potwierdzeniem.
Limit `MAX_OPEN = 5` otwartych zgłoszeń na osobę pilnuje rytmu „zgłaszam to, przy czym
faktycznie bywam", nie złośliwców (tych i tak zatrzymuje próg).

**Panel** [`/admin/skarby`](../core/Controllers/Admin/TreasureController.php): mapa
z trasami referencyjnymi w tle, klik ustawia lokalizację, modal uzupełnia resztę; region
zgaduje `Treasure::guessRegion` z pozycji. Punkty domyślne biorą się z rzadkości
(50 / 120 / 300 / 800 — skok CELOWO nierówny, żeby po legendarny opłacało się nadłożyć
drogi, zamiast uczyć, że „legendarny to cztery pospolite"), ale rozstrzyga zawsze kolumna
`points` konkretnego skarbu: **rzadkość jest ETYKIETĄ, nie mnożnikiem**. `/admin/skarby/wydruk`
składa arkusz naklejek z kodami QR ([`Utils\Qr`](../core/Utils/Qr.php)), a `nowy-kod`
rotuje kod, gdy naklejka wycieknie. Skarbów się nie kasuje — jest `RETIRED`.

**Wchodzi w istniejące mechanizmy bez dopisywania czegokolwiek w trzech miejscach**:
punkty przez `PointLedger` (widoczne w historii profilu i w panelu admina), znalezienia
w aktywności rowerzysty ([`RiderFeed`](../core/Models/RiderFeed.php)), a Puls dostaje dwa
własne typy (`skarb-nowe`, `skarb-pierwszy`). Kolekcje: `Treasure::collectionProgress`
i `collectionsForUser` (pokazują tylko kategorie, w których coś już znaleziono).

**Rozróżnienie rzadkości i kategorii w interfejsie (2026-08-27)**, zgłoszenie usera:
„zwykły i legendarny skarb wyglądają dziś tak samo". Dwie osie, jeden słownik wizualny
w trzech miejscach naraz:

- **Kategoria → ikona pinezki.** Dotąd każdy widoczny, niezdobyty skarb dostawał ten sam
  ogólny diament (`ZNAKI.skarb` w [`discovery-map.js`](../assets/js/discovery-map.js)),
  mimo że `dictionary_items.icon` (klucze `tre-viewpoint`/`tre-pass`/`tre-hut`/`tre-water`/
  `tre-monument`/`tre-service`/`tre-curiosity`, te same co [`Utils\Icon`](../core/Utils/Icon.php))
  już istniał i był używany w Kolekcji i liście czekających. `Treasure::inBounds` /
  `listOnRoute` zaczęły dokładać `c.icon AS category_icon` do SELECT-a (i zerować je
  w `reveal()` na poziomie 0, razem z `category_label`/`category_code` — kategoria jest
  takim samym tropem jak nazwa). JS ma teraz kopię tych samych kształtów Lucide pod
  `KATEGORIE{}` — panel w przeglądarce nie ma jak sięgnąć do pliku PHP, więc dwie kopie
  ikon zamiast jednej. Znaleziony/zgłoszony/ukryty **zostają przy swoich generycznych
  znakach** (✓/❓/❔) — one mówią o STANIE, nie o tym, co to za miejsce, i mieszanie tych
  dwóch komunikatów było źródłem spłaszczenia.
- **Rzadkość → grubość obwódki pinezki + kolorowy chip.** Świadomie NIE kolor pinezki:
  kolor już niesie stan (złoty=zdobyty, bursztyn=zgłoszony, fiolet=domyślny), a rzadkość
  jest drugą, niezależną osią — dwie w jednym kolorze nie dałoby się odczytać naraz.
  `.tre-pin.is-rarity-{common,rare,epic,legendary}` w CSS ustawia tylko `border-width`
  (2/2/3/4 px) i, przy legendarnym, dorzuca złoty `outline` — osobna właściwość, nie
  nadpisuje `border` w całości, więc nakłada się na dowolny stan bez konfliktu. Kolor
  rzadkości mieszka w `.rarity-chip.is-rarity-*` (wspólna klasa CSS) — w dymku skarbu
  na mapie (zamiast płaskiego słowa w linii meta), we wpisie „skarb-pierwszy" w Pulsie
  (obok `regionLabel`/`categoryLabel`; wpis zbiorczy „skarb-nowe" NIE dostaje chipa —
  to partia WIELU różnych skarbów naraz, jedna rzadkość by kłamała) i w Kolekcji na
  profilu, gdzie `Treasure::collectionsForUser` liczy teraz DRUGIM zapytaniem rozbicie
  „ile z tych X to zwykłe/rzadkie/epickie/legendarne" per kategoria (`rarityFound`) —
  osobne od pierwszego, bo to pierwsze liczy WSZYSTKIE punkty (do zdobycia + zdobyte)
  po kategorii, a rozbicie rzadkości ma sens tylko dla ZNALEZIONYCH tej osoby.
  `Treasure::rarityLabel()` jest jedynym miejscem w PHP, które tłumaczy ENUM na polski
  (odpowiednik `RZADKOSC` w JS — dwie kopie, bo żyją po dwóch stronach granicy).

**Testy**: `php tests/run.php skarby` — 30 przypadków obejmujących trzy drogi zaliczania,
idempotencję rejestru punktów, poziomy ujawnienia, potwierdzenia, limity, statystyki i Puls.
`php tests/run.php dodawanie_skarbow` — 12 przypadków: tworzenie z minimalną ilością danych
(nazwa + współrzędne; reszta pól dostaje domyślne, w tym stawka zależna od rzadkości
COMMON/RARE/EPIC, patrz `Treasure::save`), zaliczenie i naliczanie punktów w rejestrze,
oraz wycofanie (`RETIRED`) jako jedyne „usunięcie" — blokuje wszystkie trzy drogi
zaliczenia i zdejmuje skarb z mapy, ale nie rusza już naliczonych punktów (niezmienny rejestr).

### Zgłaszanie skarbów z rozszerzenia Chrome (tryb "🗺️ Dodaj skarb", 2026-08-21)

Rozszerzenie Chrome [Importer eventów](../ridemore-event-importer/) ma tryb „🗺️ Dodaj skarb",
który pozwala zgłaszać skarby bez ręcznego szukania współrzędnych — skanuje stronę o miejscu
(np. Wikipedię) i automatycznie wypełnia formularz `/skarby/zglos`.

**Przepływ end-to-end:**
1. Użytkownik otwiera stronę o ciekawym miejscu (Wikipedia, blog turystyczny) w karcie Chrome.
2. Klika „🗺️ Dodaj skarb" w panelu bocznym rozszerzenia.
3. `dom-features.js` zbiera kontekst strony (tytuł, meta, elementy, linki, obrazy).
4. Wysyłka `POST /api/ai/engine-analyze` z `mode='treasure'` — silnik AI
   (`ai-engine/analyze.py`, `providers/treasure_prompt.py`) ekstrahuje:
   - `name` — nazwa (z tytułu/H1, max 160 znaków)
   - `lat/lon` — współrzędne (wymagane, szukane w infoboxie/Geohack/meta)
   - `description` — opis (z leadu, max 600 znaków)
   - `category` — kategoria ridemore (kod słownika z `Dictionary::items('treasure_category')`)
   - `hint` — wskazówka (1 zdanie, czego szukać na miejscu)
5. Panel renderuje kartę z danymi → przycisk „📌 Zapisz" otwiera `/skarby/zglos`
   i wypełnia formularz przez `treasure-fill.js` (vanilla JS, nie Alpine.js).
6. Użytkownik sprawdza dane i klika „Zgłoś to miejsce".

**Kategorie skarbów** są dynamiczne z bazy danych (`Dictionary::items('treasure_category')`,
Support::eventFormDictOptions()). AI dostaje listę kodów w promptcie i wybiera najlepszą.

**Walidacja po stronie Pythona** (`_validate_treasure()` w `analyze.py`):
- lat: -90..90, ≠ 0.0; lon: -180..180, ≠ 0.0
- name: niepusty, max 160; description: max 600
- category: kod musi istnieć w słowniku treasureCategories
- hint: max 300; confidence: low/medium/high

**Ograniczenia:**
- Tylko jedno miejsce na kliknięcie (nie zbiera wielu miejsc ze strony).
- Bez zdjęć (formularz nie wspiera uploadu).
- Współrzędne wymagane — strona bez geo-metadanych nie da skarbu.

## Moderacja dyskusji i FAQ (migr. 034)

**`/admin/dyskusje`** ([`DiscussionController`](../core/Controllers/Admin/DiscussionController.php))
— jedno miejsce na pytania i odpowiedzi ze WSZYSTKICH wydarzeń, którymi user zarządza.
Powstało, bo moderacja istniała wyłącznie na stronie pojedynczego wydarzenia: żeby znaleźć
niecenzuralny wpis, trzeba było obejść każde osobno (nierealne przy kilkudziesięciu, tym
bardziej przy tysiącach u admina).
- **Trasa CELOWO bez `$adminGet`** — organizator moderuje własne wydarzenia. Uprawnień nie
  nadaje trasa, tylko zapytanie: `EventComment::forModeration($scopeUserId, …)` filtruje po
  właścicielu, a admin wchodzi z `null` i widzi wszystko. Filtry: fraza, „bez odpowiedzi"
  (licznik z `unansweredCountFor`), jedno wydarzenie.
- **FAQ to nie osobna tabela**, tylko flaga `event_comments.is_faq` (migr. 034).
  `CommentController::storeFaq` → `EventComment::createFaqPair` zapisuje pytanie
  i odpowiedź jednym ruchem — organizator dopisuje to, o co ludzie i tak pytają, bez
  udawania, że ktoś zapytał. Pary idą też do structured data przez
  [`JsonLd::forFaq`](../core/Utils/JsonLd.php) (`FAQPage` na stronie wydarzenia).
- Kasowanie: `CommentController::delete`. Kolejność tras ma znaczenie — `/komentarz/faq`
  musi być zarejestrowane przed `/komentarz/{commentId}/usun`.

## Moderacja kont (migr. 058)

**`/admin/uzytkownicy`** ([`UsersController`](../core/Controllers/Admin/UsersController.php),
dane z [`UserAdmin`](../core/Models/UserAdmin.php)) — cztery czynności: przegląd („kto się
zarejestrował", z filtrem nowych), blokada z powodem, wysłanie linku resetu hasła
(`ActivationToken` + mail, admin NIE ustawia cudzego hasła), kasowanie fejków.

- **Blokada, a nie kasowanie — i to jest tu decyzja główna.** Klucze obce z `users`
  kaskadują do kilkudziesięciu tabel (`event_rsvps`, `event_recaps`, `event_comments`,
  `point_transactions`, `discovery_cells`, `rider_activities`…), więc skasowanie konta,
  które cokolwiek robiło, wyciera nie tylko jego dane, ale i historię CUDZYCH wyjazdów:
  ze składu znika uczestnik, z kroniki jego wpis, a z niezmiennego rejestru punktów —
  transakcje, które miały nigdy nie zniknąć. Cztery kolejne tabele (`events`,
  `conversations`, `messages`, `event_rsvp_payments`) mają RESTRICT, więc kasowanie
  organizatora i tak by się nie udało, tylko wywaliło błędem.
- **`UserAdmin::deleteIfEmpty`** kasuje więc wyłącznie konto PUSTE (fejk z rejestracji).
  Co liczy się jako „puste", mówi `contentSummary`.
- **Blokada działa też na sesjach, które już trwały**: `Auth::user()` sprawdza
  `User::isBlocked()` i wylogowuje: bez tego zablokowany chodziłby po serwisie do
  wygaśnięcia ciasteczka. Druga bramka jest przy logowaniu (`AuthController::login`).
- **Osobny model, nie metody w `Models\User`.** `User` obsługuje ZALOGOWANEGO człowieka
  i widzi wyłącznie własne konto — to jest jego zaletą. Tutaj czytamy cudze konta hurtem,
  razem z danymi, których sam użytkownik o sobie nie widzi; wsypanie tego do `User`
  znaczyłoby, że każdy kontroler frontu ma pod ręką narzędzia moderacji.
- **Testy**: `php tests/run.php uzytkownicy`.

## Peleton — relacja „jeździliśmy razem" (Etap 2)
Powstaje SAMA ze wspólnej **potwierdzonej obecności** na tym samym turnusie. Zero
„obserwuj"/„followers" — do peletonu wchodzi się wsiadając na rower.
- Migr. `036` — tabela `rider_connections`. **Zmaterializowany agregat, NIE źródło
  prawdy** (źródłem zostaje `event_attendance`); `rebuildAll()` odtwarza całość od zera
  — zweryfikowane, że wynik przyrostowy i pełny są identyczne.
- **Para kanoniczna** `user_a_id < user_b_id`, jeden wiersz na parę → relacja
  symetryczna z definicji (u obu ta sama liczba wyjazdów i ten sam dystans). To jest
  różnica wobec Stravy, gdzie wykrywanie po bliskości GPS bywa asymetryczne.
- Model: [`RiderConnection`](../core/Models/RiderConnection.php) — `recomputeForEdition`,
  `forUser`, `countForUser`, `pelotonOnEdition`, `rebuildAll`.
- **Przeliczanie wisi w `EventAttendance::declare()`**, nie w kontrolerach — niezmiennik
  „zmieniła się obecność → peleton aktualny" nie może zależeć od tego, czy ktoś pamiętał
  dopisać wywołanie przy kolejnym wejściu. Zakres przeliczenia to wszystkie osoby z
  WPISEM obecności na turnusie (także `attended=0`), inaczej cofnięcie obecności nigdy
  nie zmniejszyłoby licznika.
- `shared_km` z widoku `event_totals`; wydarzenie bez dystansu dorzuca 0 i widok wtedy
  km NIE pokazuje (żeby nie wyświetlić „0 km razem", co wygląda na błąd).
- Widok: blok **„Z Twojego peletonu"** w sekcji `#kto-jedzie` (`event-page.php`) —
  wyróżniony podzbiór składu, tylko dla zalogowanych. Świadomie BEZ `--blaze`: na tej
  stronie znak szlaku jest zarezerwowany dla wolnych miejsc.
- Pułapka SQL: przy `EMULATE_PREPARES=false` nie wolno użyć tego samego nazwanego
  placeholdera dwa razy — zapytania symetryczne mają `:uid1`/`:uid2`/`:uid3`.

## „Byłem" — potwierdzenie faktycznej obecności (fundament Peletonu)
Odróżnia „zapisał się" od „pojechał". Źródło prawdy dla Peletonu (Etap 2) i składu
kroniki (Etap 4) — `EventAttendance::attendedForEdition()`, NIE
`EventRsvp::confirmedParticipantsForEdition()`.
- Migr. `035` — **rozszerza ISTNIEJĄCĄ, nigdy nieużywaną tabelę `event_attendance`**
  (była w schemacie od początku pod weryfikację GPS; jedyne dotknięcie to odczyt w
  `DerivedPreference.php:160` z zawsze pustej tabeli). Nowe kolumny: `attended`,
  `declared_at`, `declared_by_user_id`, `confirmed_by_organizer`. Kolumny `gps_*`
  nietknięte, na później.
- **TRZY stany, nie dwa**: brak wiersza = nie odpowiedział · `attended=1` = był ·
  `attended=0` = nie dojechał. Braku deklaracji NIE wolno liczyć jako nieobecności.
- Model: [`EventAttendance`](../core/Models/EventAttendance.php) — `declare` (upsert,
  `GREATEST` na `confirmed_by_organizer`, żeby uczestnik nie zdjął potwierdzenia
  organizatora), `forEditionAndUser`, `mapByRsvpForEvent`, `attendedForEdition`.
- Kontroler: `RsvpController::declareAttendance` (uczestnik) / `setAttendance`
  (organizator, `requireEventEditPermission` + `editionBelongsToEvent` anty-IDOR).
- Trasy: `POST /wydarzenia/{slug}/bylem`, `POST /wydarzenia/{slug}/uczestnicy/{userId}/obecnosc`.
  **Wyłącznie POST** — mail linkuje na STRONĘ wydarzenia (`#bylem`), nie do akcji,
  bo prefetchery klientów pocztowych klikałyby GET za użytkownika.
- Widoki: sekcja `#bylem` w `event-page.php` (tylko `completed` + zalogowany z
  potwierdzonym zapisem) + kolumna „Obecność" w `event-participants.php` (tylko
  `completed`). Zero nowego CSS — `.box`, `.btn`, `.btn-secondary`, `.badge`, `.link-button`.
- Mail: **rozszerzony istniejący** `review-invite` (nie nowy) — nowy przycisk
  „Potwierdź, że byłeś" jako główny, opinia/relacja zeszły na drugi plan.
  `EventRsvp::confirmedParticipants()` zwraca teraz też `edition_id` (MIN+GROUP BY
  zamiast DISTINCT — nadal JEDEN mail na osobę), żeby link wskazywał właściwy turnus.

## Zapisy zewnętrzne (link / telefon / e-mail)
Gdy organizator prowadzi zapisy poza platformą. `registrationType='external'`.
- Kolumny `events.external_registration_{url,phone,email}` (migr. `030`).
- `Event.php` (właściwości + `save`) → `EventResource` (wyjście) → `EventFormInput`
  (wyprowadza `registrationType`, waliduje) → `EventFormResource` (`empty/fromPost/fromRawEvent`).
- Widoki: `event-form-wizard.php` + `event-form.php` (3 pola w boksie zewnętrznym),
  `event-page.php` — trzy powierzchnie prezentujące formy: boks „O wyjeździe" (`.ext-link`),
  kafelek „Zapisy" (`.fact-link`, klikalne link/tel/mailto), CTA zapisu (`.book__alt`).
- Akcja „wezmę udział" bez płatności: `RsvpController::joinExternal`.

## Dopasowania — Etap 2 (moduł matchingu)
Silnik podpowiadający podobne wyjazdy (geo/trasa/daty/rower), podpowiedzi na żywo w formularzu,
nocna konsolidacja + maile.
- [`MatchEngine`](../core/Models/MatchEngine.php) (`forEdition/forDraft/runNightlyConsolidation/
  criticalMassProgress`), [`RouteCells`](../core/Utils/RouteCells.php), tabele `event_stage_cells`/
  `event_match_notifications` (migr. `025`).
- API: `/api/matches/preview`. Widok: `match-suggestions.php` + widget na stronie głównej/evencie.
  Zasób: `MatchCardResource`. Cron: `runNightlyConsolidation`.
- Pamięć: `project_ridemore_etap2_matching`, `project_ridemore_match_widget_redesign`.

## Preferencje — Etap 3
Warstwa profilu (zadeklarowany + wynikający z historii), odrzucanie sugestii, dwa kanały powiadomień.
- [`UserPreference`](../core/Models/UserPreference.php) (zadeklarowane),
  [`DerivedPreference`](../core/Models/DerivedPreference.php) (wynikające, `recomputeAll` w cronie),
  [`PreferenceNotifier`](../core/Models/PreferenceNotifier.php) (regularny/aspiracyjny;
  **od Etapu 1c programu zachęt, 2026-09-11, wysyła przez `Models\Notifier`
  i nie ma już własnych cooldownów 7/30 dni** — odstęp bierze z budżetu bramki),
  [`RecommendationLog`](../core/Models/RecommendationLog.php)/[`RecommendationDismissal`](../core/Models/RecommendationDismissal.php).
  Tabele migr. `026–027`. API: `/api/matches/dismiss`. Ustawienia: „Moje konto" → preferencje.
- Pamięć: `project_ridemore_etap3_preferences`.

## Czat grupowy (per turnus)
Kanał dyskusji dla zapisanych na dany termin, obok istniejącego 1:1.
- [`EventGroupConversation`](../core/Models/EventGroupConversation.php) (członkostwo liczone
  na żywo z zapisów), tabele migr. `028`. Kontroler: `MessageController::groupShow/groupSend`
  + scalona skrzynka (`mergedInbox`). Wejście z `event-page.php` (sekcja Pytania) i z messengera
  (panel „Nowa rozmowa"). Maile do członków (tylko organizator inicjuje masowo).
- Header: ikona Wiadomości + czerwona kropka (nieprzeczytane). Widok: `messages.php`.
- Pamięć: `project_ridemore_group_chat`.

## Logowanie społecznościowe (Google + Strava)
OAuth przez `league/oauth2-client` (`GenericProvider`).
- [`SocialAuthController`](../core/Controllers/SocialAuthController.php),
  [`OAuthProvider`](../core/Utils/OAuthProvider.php) (fabryka + `isConfigured`),
  [`OAuthIdentity`](../core/Models/OAuthIdentity.php) (migr. `029`), `User::createFromOAuth`.
  Config: `core/config.php` `$oauth` (sekrety z env; `strava.enabled=false` — wyłączona).
  Trasy `/auth/{google,strava}[/callback]`, krok e-maila Stravy `/auth/strava/dokoncz`.
  UI: `social-login.php` (przycisk pokazywany tylko gdy `isConfigured`).
- Reguły bezpieczeństwa: Google zwraca **zweryfikowany** e-mail → auto-podłączenie OK;
  Strava **nie ma e-maila** → user go podaje (NIEzweryfikowany) → NIE wolno auto-podłączać
  do istniejącego konta. Strava chce „Authorization Callback Domain" = sama domena.
- Pamięć: `project_ridemore_social_login`.

## Płatne rezerwacje wewnętrzne
Rezerwacja z płatnością (zaliczka/całość), księga wpłat, zwroty, deadline anulowania.
- `RsvpController` (`reserve/confirmPayment/confirmRefund/...`), `Support::requirePayableInternalEvent/
  paymentSummary`, `EventPricing` (deadline), tabele `event_rsvp_payments` (migr. `018`).
  Statusy: `oczekuje_platnosci` → `potwierdzony`; `oczekuje_zwrotu`. Maile: `payment-*`, `refund-*`.

## Profil organizatora (przejęcie/weryfikacja/kompletność)
- `OrganizerController` (`show/verify/claim`), `Organizer::completeness/heroCover/ridingProfile/stats`,
  admin: `OrganizerAdminController`. Widok `organizer-profile.php` (stany operator/peer/gość/admin).
  Przycisk przejęcia: pomarańczowy `.op-head__act` w headerze profilu.

## Konto i ustawienia
- `Admin/AccountController` („Moje konto": nazwa/hasło/adres/preferencje + avatar),
  `Admin/BillingProfileController` („Profil rozliczeniowy"). Widoki `account.php`,
  `billing-profile.php`. Wzorowane na mockupach `moje-konto.html`/`profil-organizatora.html`.

## Importer eventów (rozszerzenie Chrome) + AI-Engine (Groq/Gemini/Ollama)
Osobny projekt `ridemore-event-importer/` (siostrzany katalog obok `ridemore/`,
NIE część tej aplikacji) — panel boczny Chrome, który zbiera dane o wydarzeniu
z dowolnej strony i wstrzykuje je do kreatora `/wydarzenia/nowe` (Alpine
`window.Alpine.$data`), nigdy nie publikuje sam. Jeden tryb: **🧠 AI-Engine**
(etykieta bez dostawcy w nazwie — patrz niżej, dostawca jest wymienny) —
`scripts/dom-features.js` w rozszerzeniu zbiera model elementów
żywej strony (tekst/pozycja/styl/rodzic, bez interpretacji), most
`POST /api/ai/engine-analyze` (dev-only, `api/routes.php`) odpala przez
[`AiEngineBridge`](../core/Utils/AiEngineBridge.php) proces Pythona
(`ai-engine/analyze.py`, poza tym repo w drzewie katalogów, patrz
`ai-engine/README.md`), który woła Groq i waliduje wynik (kody słownikowe,
indeksy linków/obrazów) — PHP jest tu wyłącznie mostem, bo JS rozszerzenia
nie potrafi odpalić Pythona. Dziennik ekstrakcji (bez pełnego DOM-u) w
[`AiImportLog`](../core/Models/AiImportLog.php) (`ai_import_logs`, migr. `032`).
Endpoint zwraca 404 poza `APP_ENV==='dev'` — to narzędzie lokalne dewelopera,
nigdy funkcja produkcyjna. Rozszerzenie korzysta też z istniejących
`/api/dictionaries` i `/api/organizers/match-domain`.

**"Typ wydarzenia" w panelu ciągnięty z `/api/dictionaries` (dopisane
2026-08-09)** — do tej pory `Support::eventFormDictOptions()` (więc i
`/api/dictionaries`) w ogóle NIE niósł słownika `event_type`; panel
(`sidepanel.html`) miał więc 3 radiobuttony wpisane na sztywno, tak jak
kilka innych miejsc w głównej aplikacji (patrz `EventFormInput`/
`EventFormResource` whitelisty) — dodanie 4. typu ('wyscig') wymagało
pamiętać o osobnej aktualizacji rozszerzenia. Domknięte: `eventTypes` doszło
do `Support::eventFormDictOptions()`; `sidepanel.js::syncEventTypeRadios()`
DOKŁADA do `#f_typeRow` każdy kod z API, którego tam jeszcze nie ma — baza (3
oryginalne typy) zostaje wpisana wprost w HTML jako odporność na brak sieci
(reszta słowników w panelu, w razie błędu fetcha, zostaje pusta — dla "typu
eventu", najbardziej podstawowego pola, uznano to za zbyt ryzykowne). Kolejny
nowy typ na ridemore.bike pojawi się w panelu SAM, bez zmian w rozszerzeniu.
`fill.js` ma osobną, świadomie NIE-dynamiczną whitelistę kodów (ostatnia
linia obrony przed wstrzyknięciem nieznanego typu do `TYPE_META`/`STEP_MIN`
kreatora, uruchamiana w kontekście strony ridemore.bike, bez łatwego dostępu
do świeżej listy) — TĘ trzeba dopisywać ręcznie przy każdym nowym typie.

**Tryb "Skanuj grupę FB" — posty „szukam towarzystwa" (dopisane 2026-08-20)**.
Drugi tryb importera, dla grup na Facebooku: `scripts/group-feed.js` zbiera
posty z feedu **wyłącznie to, co użytkownik widzi na załadowanej stronie**
(zalogowany na swoim koncie FB; bez API/scrapingu poza widok), panel wysyła
`{mode:'group', posts:[...]}` przez ten sam most `/api/ai/engine-analyze`
(dev-only) do `ai-engine/analyze.py`, które przełącza się na `analyze_group()`
dostawcy + `_validate_group()` (patrz `ai-engine/README.md`): model ocenia
KAŻDY post (czy ktoś szuka towarzystwa/chętnych na przejazd), uzupełnia
skrótowo napisane posty w pola wydarzenia (kody słownikowe walidowane, wpisy
ze zmyślonym `postIndex` odrzucane — ten sam wzorzec co indeksy linków w
trybie strony) i pisze `messageRide` — krótki opis jazdy „przerobiony przez
AI". Panel pokazuje karty postów; „📥 Użyj w formularzu" wypełnia formularz
danymi z posta (autor → pole Organizator — to on ma być organizatorem
wydarzenia; ta sama zasada „dokładamy, nie nadpisujemy" co w AI-Engine),
„💬 Czat" otwiera `facebook.com/messages/t/<profil>` i wkleja gotową
wiadomość do pola czatu (`scripts/chat-paste.js`, world MAIN — execCommand,
bo pole to contenteditable Reacta), „📋 Kopiuj" kopiuje TĘ SAMĄ wiadomość do
schowka (navigator.clipboard, awaryjnie textarea+execCommand — FB bywa w
ogóle niewklejalny). **Wiadomość to STAŁY szablon** (struktura uzgodniona
z psychologiem 2026-08-20): żyje w `sidepanel.js::buildAuthorMessage`
(przedstawienie ridemore.bike, prośba o zgodę, obietnica braku publikacji
bez zgody — niezmienne), AI dostarcza tylko wstawkę `messageRide`; to samo
wyjście idzie i do czatu, i do schowka. **Wiadomość NIGDY nie jest wysyłana
automatycznie** — wysyła ją moderator Enterem z własnego konta FB.
Gdy auto-skan nie znajdzie postów (FB zmienia DOM między kontami — bywa
ZERO `role="article"`), komunikat pokazuje diagnostykę strony, a „🖱️ Wskaż
posty" pozwala zaznaczyć posty ręcznie (multi-kliknięcia, ta sama idea co
🎯 przy polach). Ograniczenia świadomie w README importera: tylko widoczne
posty (przewiń i skanuj ponownie), heurystyczne selektory DOM-u FB (grupy
wyglądają różnie), wklejenie do czatu bywa zawodne (fallback: 📋 Kopiuj /
Ctrl+V). Endpoint i cała infrastruktura AI bez zmian — tryb grupowy to nowy
`mode` w istniejącym mostku, zero nowych tras API.

**Zdjęcie okładki z linku — serwer ściąga sam (dopisane 2026-08-09)** — bug
zgłoszony przez usera, potwierdzony żywo: gdy panel wykrywał zdjęcie jako
link (typowe dla AI-Engine — strona źródłowa ma zdjęcie pod URL-em, nie plik
do wyboru z dysku), rozszerzenie SAMO pobierało je w przeglądarce i
wstrzykiwało jako plik do `<input type=file>` kreatora — przy dużym zdjęciu
robiło to żądanie POST na tyle duże (setki KB–ponad MB), że firewall
hostingu (LiteSpeed/WAF na Namecheap) blokował je gołą stroną 403 ZANIM
dotarło do aplikacji. Pierwsza próba (skalowanie zdjęcia w przeglądarce
przed wysyłką, `sidepanel.js::resizeCoverPhotoIfNeeded()`, iteracyjnie do
250 KB) pomogła, ale problem wraca przy każdym kolejnym dużym zdjęciu z
linku. Właściwe rozwiązanie: gdy zdjęcie jest linkiem, przeglądarka NIE
wysyła już żadnych bajtów obrazu — tylko sam URL (kilkadziesiąt znaków) w
polu `cover_photo_source_url`. **Pole jest WIDOCZNE i x-model-owane**
(`step-oczym.php` w kreatorze ORAZ `event-form.php` w edycji, plus
`coverPhotoSourceUrl` we wszystkich trzech metodach `EventFormResource`) —
pierwsza wersja miała je UKRYTE i wypełniane tylko przez rozszerzenie przez
surowy DOM, co user słusznie zakwestionował: widoczne pole to równorzędna,
ręczna alternatywa dla „Dodaj zdjęcie" z dysku (można wkleić link samemu,
bez wtyczki), a przy okazji WIDAĆ, czy wtyczka faktycznie coś wstawiła —
przy wersji ukrytej nieudane wypełnienie było nieodróżnialne od braku
zdjęcia. `fill.js` ustawia je przez STAN Alpine (`data.coverPhotoSourceUrl`),
nie przez `.value` (pole jest x-model-owane, ręczny DOM zostałby nadpisany
przy przerysowaniu). Plik z dysku ma pierwszeństwo, gdy podano oba (pole
linku jest wtedy `:disabled`). Ściąganie dzieje się
PO STRONIE SERWERA: [`Utils\Upload::saveCoverPhotoFromUrl()`](../core/Utils/Upload.php),
wołane z `Resources\EventFormInput::fromRequest()` gdy nie ma ani realnego
uploadu, ani `existing_cover_photo_url`. **Ten formularz jest dostępny bez
logowania** (zgłoszenie w czyimś imieniu), więc to serwer publicznie
ściągający DOWOLNY podany URL — pełna ochrona przed SSRF: tylko http(s),
host (albo literalny IP) sprawdzany przez `FILTER_FLAG_NO_PRIV_RANGE|
FILTER_FLAG_NO_RES_RANGE` (odrzuca loopback/prywatne/link-local — w tym
adresy metadanych chmury typu 169.254.169.254 — dla IPv4 i IPv6),
przekierowania wyłączone (inaczej "bezpieczny" URL mógłby przekierować na
coś wewnętrznego), limit czasu/rozmiaru pobierania, zawartość zweryfikowana
jako prawdziwy obraz PO pobraniu (nie ufamy Content-Type ani rozszerzeniu w
URL-u). Rate-limit per IP (15/10 min) w `EventFormInput`, nie w `Upload.php`
— to jedyne miejsce ze skojarzeniem "user/gość" → IP. Świadomie NIE
chronione: DNS rebinding (wymagałoby ręcznego pinowania IP przez cURL,
nieproporcjonalne do realnego ryzyka tego serwisu). `fill.js` ustawia
`cover_photo_source_url` zamiast wstrzykiwać plik, gdy `payload.photoUrl`
jest znany — `resizeCoverPhotoIfNeeded()` w rozszerzeniu zostaje tylko dla
PRAWDZIWIE lokalnego pliku (`f_photoFile`, wybór z dysku), gdzie nie ma
linku, z którego serwer mógłby sam ściągnąć.

**Dostawca AI wymienny** (`AI_PROVIDER` w `ai-engine/.env`): `groq` (chmura,
limit tokenów/minutę na darmowym tierze) albo `ollama` (lokalnie, bez limitu,
bez klucza) — prompt/schemat wspólne w `ai-engine/providers/prompt.py`, nowy
dostawca = nowa klasa + gałąź w `analyze.py:main()`. Lokalnie ustawione na
`ollama` z modelem `qwen3:8b` (Q4_K_M, ~5.2GB) — dobrany pod tę maszynę (GTX
1650 4GB VRAM + 16GB RAM); `Qwen3-32B` w kwantyzacji zbliżonej do Q8_0
(~35GB wag) się TU NIE zmieści w RAM, próba pull-a przerwana świadomie.
[`OllamaProvider`](../ai-engine/providers/ollama_provider.py) wysyła
`'think': false` w `/api/chat` — Qwen3 (i inne modele "rozumujące": DeepSeek-R1,
gpt-oss) domyślnie generuje tor myślenia przed odpowiedzią, tu niepotrzebne
przy ekstrakcji z `temperature=0`; dodatkowo regex zdziera `<think>...</think>`
z treści jako siatka bezpieczeństwa, gdyby model/wersja Ollamy i tak coś wlała
do `message.content`. Zmierzone na `qwen3:8b`: ~3-4 tokeny/s generowania
(GPU/CPU hybrid 39%/61%) — dekodowanie odpowiedzi, NIE przetworzenie promptu
(~5s na 1300 wejściowych tokenów), jest wąskim gardłem; jedno skanowanie
strony trwa realnie **90s-3,5min** zależnie od złożoności eventu (więcej dni/
wariantów = więcej tokenów do wygenerowania = dłużej). Bezpiecznie w budżecie
`timeout_seconds` (300s), ale blisko górnej granicy przy bogatych
wielodniówkach — pierwsza dźwignia przy przekroczeniu: mniejszy model
(`qwen3:4b`) albo przełączenie na `AI_PROVIDER=groq`.

**Optymalizacja tokenów wyjściowych (sesja 2026-08-07)** — dekodowanie jest
wąskim gardłem (wyżej), więc krótsza odpowiedź = szybciej, bez zmiany modelu:
`SHORT_KEYS`/`STAGE_SHORT_KEYS`/`VARIANT_SHORT_KEYS` w `prompt.py` proszą
model o krótkie nazwy kluczy JSON (`t` zamiast `title`, `mpl` zamiast
`meetingPointLabel`...) — `analyze.py::_get()` czyta krótki klucz z
fallbackiem na pełny (na wypadek, gdyby model i tak wrócił do pełnej nazwy),
`_validate()` i tak zwraca do PHP/JS pełne kanoniczne nazwy jak dawniej —
kompresja jest CAŁKOWICIE niewidoczna poza `ai-engine/`. Zmierzone ~40%
szybciej (105s śr. vs 178s) na `qwen3:8b`, bez utraty jakości (2/2 czyste
testy). **WYPRÓBOWANE I WYCOFANE**: instrukcja "pomijaj klucz, jeśli nie masz
wartości" (miejsce `null`) dawała dodatkowe ~45%, ale REPRODUKOWALNIE (4/4)
wywoływała nowe halucynacje na `qwen3:8b` (np. `organizer` zgadywany z
domeny `sourceUrl`, mimo wyraźnego zakazu w prompcie) — kontrolny test z
oryginalnym promptem na tym samym payloadzie wypadł czysto. Wniosek zapisany
w komentarzu nad `SHORT_KEYS` w `prompt.py`: dla słabszego modelu decyzja
"czy dodać klucz" jest ryzykowniejsza niż "co wpisać w istniejący klucz" —
więcej stopni swobody, więcej okazji do zgadywania. Nie ponawiać bez testu
na docelowym modelu.

**"Knowledge base" v1 — kontekst historyczny per domena (sesja 2026-08-07)**.
Zamiast wektorowego podobieństwa (świadomie odłożonego, patrz akapit
"Dziennik ekstrakcji" niżej) — najprostsza rzecz, która realnie pomaga: gdy
PHP (`api/routes.php`, tuż przed `AiEngineBridge::analyze()`) dostanie
`sourceUrl`, woła
[`AiImportLog::domainSummary()`](../core/Models/AiImportLog.php) — ostatnia
analiza TEJ SAMEJ domeny z `confidence` != `'low'` (nie zawsze najnowsza; jeśli
ostatnia była słaba, sięga po wcześniejszą dobrą), okrojona do pól sensownych
per-organizator (organizer/region/bikeTypes/pace/difficulty/surface/
priceCurrency — NIE dat/tras/opisu, to per-event). Dorzucone do payloadu jako
`knowledgeHint`, `prompt.py::build_user_content()` wpuszcza to do treści
usera TYLKO gdy istnieje (zero kosztu tokenów, gdy brak historii), a system
prompt instruuje model, żeby traktował to jako WSKAZÓWKĘ, nie pewnik.
Model-agnostyczne z założenia — żyje w PHP/MySQL, nie w żadnym konkretnym
modelu, działa identycznie pod Groq i pod Ollamę, rośnie automatycznie z
każdym realnym skanem. Zweryfikowane żywo: z podpowiedzią `organizer: "Klub
Kolarski Tatry"` (fikcyjna, do testu) model poprawnie użył jej dla strony,
która SAMA nie wspominała organizatora, zamiast zgadywać z URL-a albo
zostawiać null — na obu dostawcach (Groq i Ollama).

**Silnik rozumie też strukturę eventu, nie tylko pola** — schemat obejmuje
`eventType`/`stages` (rozkład dzień-po-dniu wielodniówki, mapowane w
`fill.js` na PEŁNĄ tablicę `data.stages` kreatora, nie tylko `stages[0]`) i
`variants` (warianty dystansu, dokładane do istniejącej listy „Warianty
trasy” w panelu przed wypełnieniem). Pole `description` jest KOMPONOWANE z
zebranych faktów (nie tylko kopiowane ze strony), gdy źródło nie ma gotowego
opisu. Panel NIE ma edytora dnia-po-dniu — plan wielodniówki trafia wprost
do kreatora, user weryfikuje go w kroku „Plan”.

**GPX per wariant (dopisane 2026-08-07, na żądanie usera po przejrzeniu
`views/web/pages/event-form.php`)** — kreator od migracji 031 wspiera
WŁASNY plik GPX per wariant trasy (`Models\EventRouteVariant.gpxUrl`, upload
w formularzu -> auto-wykryte dystans/przewyższenie/nawierzchnia z pliku),
ale rozszerzenie Chrome tego nigdy nie wykorzystywało — GPX per wariant
trzeba było dograć ręcznie (to był świadomy, dokumentowany brak). Domknięte:
`VARIANT_SHORT_KEYS` (`ai-engine/providers/prompt.py`) ma teraz `gpxIdx`
(ta sama zasada co główne pole `gpxLinkIndex` — numer "index" z tablicy
"links", nigdy własny URL). Panel (`sidepanel.html/js`) rozwiązuje ten
indeks na URL, pokazuje mały odznaka-link "GPX ✓" (podgląd + możliwość
wyczyszczenia błędnego dopasowania) przy każdym wariancie, i przy "Wypełnij
formularz" pobiera KAŻDY plik osobno (`fetchAsBase64`) — `fill.js` wgrywa
każdy przez `/api/gpx/parse` (ta sama funkcja co główny event) i nadpisuje
dystans/przewyższenie/nawierzchnia wariantu wynikiem z pliku.
**Bezpieczeństwo przy wielu plikach GPX na stronie** (typowe dla wariantów —
każdy swój): `_valid_gpx_index()` w `analyze.py` NIE auto-koryguje błędnego
zgadnięcia modelu, gdy na stronie jest więcej niż jeden plik `.gpx` —
auto-korekta "na pierwszy znaleziony" jest bezpieczna tylko przy JEDNYM
kandydacie (typowy event bez wariantów); przy wielu wolimy `null` niż ryzyko
przypisania PLIKU JEDNEGO WARIANTU do INNEGO. Zweryfikowane żywo (prawdziwe
wywołanie Groq, syntetyczna strona z 2 wariantami/2 plikami GPX): każdy
wariant dostał swój właściwy, odrębny plik.

**Ręczne wskazanie GPX per wariant (dopisane tego samego dnia, na pytanie
usera "gdzie mogę dodać GPX")** — pierwsza wersja miała TYLKO auto-wykrycie
przez AI + przycisk czyszczenia błędnego dopasowania, bez sposobu dodania
GPX ręcznie, jeśli AI nie znalazło/pomyliło się — niezgodność z głównym
polem GPX (które ma tekst/🎯/upload). Domknięte: każdy wiersz wariantu ma
teraz przycisk **🎯 Plik GPX wariantu**, reużywający ISTNIEJĄCEGO mechanizmu
wskazywania elementów na stronie (`rmiPickOnPage()`, ten sam co pozostałe
przyciski 🎯 w panelu) — ale NIE przez `startPick()`/`applyPickResult()`
(te są zbudowane wokół jednego stałego `id` elementu; wiersze wariantów są
dynamiczne/powielane), tylko dedykowaną `pickVariantGpx(button, row)`, która
aktualizuje badge/hidden-input KONKRETNEGO wiersza przez `setVariantGpx()`.
Wiersz wariantu jest teraz dwuliniowy (nazwa/dystans/przewyższenie/usuń +
osobna linia GPX: 🎯wskaż / badge-wykryty / ✕usuń) — jedna linia na 7
kontrolek nie zmieściłaby się czytelnie w wąskim panelu bocznym.

**Rozjazd między opisem usera a rzeczywistym formularzem, zaznaczony
usera (nie tylko "poprawiony po cichu")**: user wspomniał "różny opis per
wariant" jako kolejny wymagany element — sprawdzone w `schema.sql`
(`event_route_variants`) i `EventRouteVariant.php`: TAKIEGO pola nie ma ani
w bazie, ani w formularzu (tylko name/dystans/przewyższenie/nawierzchnia/
GPX/cena/zaliczka/limit miejsc). Nie dodano (ani do bazy, ani do
rozszerzenia) — zaznaczone userowi wprost, żeby nie budować funkcji pod
nieistniejące pole. Jeśli to faktycznie potrzebne, wymaga OSOBNEJ decyzji
(nowa kolumna + UI w event-form.php), nie tylko zmiany w rozszerzeniu.

**GPX per DZIEŃ wielodniówki (dopisane tego samego dnia, na pytanie usera "a
co z wielodniówkami")** — `Models\EventStage` wspiera WŁASNY plik GPX per
dzień, dokładnie jak `EventRouteVariant` per wariant, ale AI-Engine tego nie
wykorzystywał (świadomy, dokumentowany brak — "GPX per dzień dogrywa
ręcznie"). Domknięte tym samym mechanizmem co warianty: `STAGE_SHORT_KEYS`
ma `gpxIdx`, `_as_stage()`/`_as_stage_list()` w `analyze.py` walidują przez
`_valid_gpx_index()` (ta sama ochrona przed wieloma plikami .gpx na
stronie). Stages NIE MAJĄ UI w panelu (świadomie, od redesignu wcześniej w
tej sesji) — `gpxLinkIndex` rozwiązywany na URL WPROST w `applyEngineResult()`
(nie ma widoku do recenzji, user weryfikuje w kroku "Plan" kreatora, który
JUŻ ma widżet uploadu GPX per dzień). `fill.js` wgrywa każdy dzień osobno
przez `/api/gpx/parse`, ten sam wzorzec co warianty.

**Bug w kolejności operacji znaleziony PRZY implementacji (nie przez
usera)**: stary, jednolity blok "GPX głównego eventu" w `fill.js`
bezwarunkowo nadpisywał `data.stages[0]` PO nowym blok dzień-po-dniu — przy
wielodniówce, gdzie AI wykryło TAKŻE top-level `gpxLinkIndex` (typowe,
model czasem kopiuje link dnia 1 jako "główny" plik eventu), stary blok
zamazywałby poprawnie ustawiony GPX dnia 1 wynikiem z tego samego albo
INNEGO pliku. Ten bug istniał od zawsze (od 2026-08-06), po prostu bez
realnego skutku, dopóki `stages[0].gpxToken` i tak zawsze było puste — teraz,
gdy ma realną wartość, trzeba było zagateować: `if (!multiDayStages &&
payload.gpxBase64)`.

**Pełny audyt formularza wobec wtyczki, zrobiony na żądanie usera** — pola z
`grep -oE ':?name="..."'` skonfrontowane z tym, co wtyczka dziś obsługuje.
**KOREKTA WAŻNA**: pierwszy audyt poszedł po `event-form.php`, który jest
formularzem EDYCJI (`$isEdit=true`), NIE tym, co wypełnia rozszerzenie —
prawdziwy KREATOR to `event-form-wizard.php` + `views/web/partials/wizard/
*.php` (`step-kogo/kiedy/gdzie/trasa/plan/oczym/dlakogo/pieniadze/
podsumowanie.php` + `script.php` z funkcją `eventWizard()`). Na szczęście
zestaw pól jest praktycznie identyczny (oba zasilają ten sam
`Event::save()`/`EventFormResource`), więc audyt się nie zmienił co do
treści, ale ŹRÓDŁO PRAWDY dla nazw właściwości Alpine to od teraz
`core/Resources/EventFormResource.php` (sprawdzone wprost, nie zgadywane):
`endDate`, `dateIsFlexible`, `additionalDates`, `limitParticipants`
(WYLICZANE: `min!==null || max!==null`, nie osobne pole), `minParticipants`,
`maxParticipants`, `priceUnit`, `paymentDeadlineDays`,
`cancellationDeadlineDays`, `cancellationPolicy`.

**Wszystko domknięte (2026-08-07, na wyraźne "wszystko" usera)**:
- **AI-Engine**: `SHORT_KEYS` w `prompt.py` ma teraz `edate`/`flex`/
  `moredates`/`minp`/`maxp`/`punit`/`paydl`/`canceldl`/`cancelpol`, nowy
  akapit `_extra_fields_para()` (w obu ścieżkach: Groq pełny prompt i
  core-call Ollamy) tłumaczy każde pole z konkretnym przykładem, `punit`
  dostał enum-walidację jak `region`/`surface` (lista `priceUnits` z
  dictionaries — dopisana też w `sidepanel.js::engineDictionariesPayload()`,
  bo wcześniej NIE była przekazywana do Pythona mimo że panel ją już miał).
  `analyze.py::_validate()` waliduje wszystkie nowe pola tymi samymi
  helperami co reszta (`_as_number`/`_as_bool`/`_as_str`/`_as_str_list`/
  `_clamp_enum`). **Zweryfikowane żywo, jednym realnym wywołaniem Groq** na
  syntetycznej stronie z cyklem terminów + limitami + politykami — WSZYSTKIE
  nowe pola wyszły poprawnie, w tym `additionalDates` bez zdublowania
  głównej daty i `cancellationPolicy` skopiowana wprost (nie parafrazowana).
- **Panel**: nowe pola w sekcji "Kiedy" (data zakończenia, checkbox
  "termin elastyczny", lista "Dodatkowe terminy" — nowy `dateRowTpl`,
  analogiczny do `variantRowTpl` ale prostszy: sam `<input type=date>` +
  usuń), w "Dla kogo" (min/maks uczestników), w "Pieniądze i zapisy"
  (select "Cena za", dni do dopłaty/odwołania, textarea polityki
  anulowania). Wszystkie nowe pojedyncze pola dopisane do `FIELD_IDS` —
  automatycznie objęte istniejącym zapisem/odczytem/czyszczeniem stanu
  (`saveState`/`loadState`/`clearAll` iterują tę listę), zero dodatkowego
  kodu potrzebnego dla TEJ części. Lista dat ma własny stan (jak warianty)
  — `readAdditionalDates()`/`addDateRow()`, dopisane osobno do
  `saveState`/`loadState`/`clearAll`.
- **`fill.js`**: `limitParticipants` WYLICZANE po stronie rozszerzenia tą
  samą regułą co `EventFormResource` (żeby stan Alpine od razu zgadzał się
  z tym, co backend by policzył) — nie osobne pole do przekazania.
  `endDate` uogólnione (wcześniej: tylko dla `pokrec_z_kims`) — teraz
  ustawiane zawsze, gdy panel/AI je dostarczy, z fallbackiem do starego
  zachowania gdy nie.
- **Nie zweryfikowane żywo w tej rundzie**: strona panelu (`sidepanel.html/
  js`) — narzędzie przeglądarki w tej sesji trzymało (cache'owało) starą
  wersję pliku `file://` niezależnie od nowych kart/nawigacji, więc
  zmiany HTML/JS panelu potwierdzone TYLKO przeglądem kodu + bilansem
  nawiasów, nie żywym DOM-em (w odróżnieniu od wcześniejszych fragmentów tej
  sesji, gdzie się to udało). Wzorzec jest identyczny do już
  zweryfikowanego (warianty/GPX), ale warto to potwierdzić realnym
  przeładowaniem rozszerzenia w Chrome przy najbliższej okazji.

**E-mail organizatora + dane zgłaszającego (dopisane tego samego dnia,
zgłoszone przez usera) — realna BLOKADA publikacji, nie kosmetyka.**
`script.php::validateBeforeSubmit()` odmawia zapisu (wraca do kroku "Kogo")
bez poprawnego `new_organizer_email`, gdy tworzony jest NOWY organizator
(niezależnie od zalogowania!), i bez `submitter_email`, gdy sesja
ridemore.bike NIE jest zalogowana. Domknięte:
- **AI-Engine**: nowe pole `organizerEmail` (`oemail`) — kontaktowy e-mail
  ORGANIZATORA/klubu (np. stopka, "Kontakt"), wyraźnie odróżnione w
  prompcie od `registrationEmail` (e-mail do zapisów NA TEN event, może być
  inną osobą/skrzynką). Zweryfikowane żywo (realne wywołanie Groq + przez
  prawdziwy endpoint PHP) na stronie z DWOMA różnymi mailami — poprawnie
  rozdzielone, żaden nie trafił w złe pole.
- **Panel**: nowe pole "E-mail organizatora" w sekcji "Kogo dotyczy"
  (AI-fillable, jak reszta pól). Osobno: nowy przycisk **"👤 Twoje dane"** —
  trwałe (NIE per-event, NIE czyszczone przez "Wyczyść", NIE szukane przez
  AI — to Twoja własna tożsamość jako operatora rozszerzenia, nie fakt ze
  strony) imię/e-mail zgłaszającego, zapisywane w osobnym kluczu
  `chrome.storage.local` (`rmiMe`), niezależnym od zwykłego stanu per-skan.
- **`fill.js`**: `newOrganizerEmail` ustawiane przy `organizerMode==='new'`
  (widoczne w kreatorze po ręcznym potwierdzeniu w kroku "Kogo", ale stan
  Alpine gotowy zanim user tam dotrze). `submitterName`/`submitterEmail`
  ustawiane BEZWARUNKOWO z "Twoich danych", gdy podane — nieszkodliwe przy
  zalogowanej sesji (pola wtedy niewidoczne w kreatorze), krytyczne przy
  niezalogowanej.

**Znaleziona przy tej samej rundzie halucynacja, NIE naprawiona, do
zbadania**: na stronie testowej bez ŻADNEJ wzmianki o liczbie uczestników
model (Groq) i tak wypełnił `minParticipants:10, maxParticipants:15` oraz
dopisał do `description` nieistniejące "Cena od zespołu. Grupa 10-15 osób."
— to nie pojedynczy przypadek dla `minp`/`maxp` (dla `punit`/`paydl`/
`canceldl`/`cancelpol`/`moredates` w wcześniejszym teście "Cykl wyjazdów"
było czysto). Wygląda na to, że te dwa konkretne pola mają słabszą
odporność na zgadywanie niż resztę nowych pól — warto osobno przetestować
i ewentualnie dociążyć instrukcję w `_extra_fields_para()`, zanim się na
nie zaufa bez przeglądu.

**KRYTYCZNY bug znaleziony i naprawiony (2026-08-07, żywy test na realnej
stronie)**: `sys.stdin.reconfigure(encoding='utf-8')` brakowało w
`analyze.py` — był tylko dla stdout/stderr (patrz nagłówek pliku). Na
Windows, gdy stdin jest przekierowane przez `proc_open` (a nie prawdziwa
konsola — czyli ZAWSZE w tej architekturze), Python domyślnie dekodował
przychodzący JSON stroną kodową systemu (`cp1250`), NIE UTF-8. Efekt: polskie
znaki w tekstach linków/elementów wchodziły uszkodzone, co po cichu psowało
dopasowania regex (np. `_prioritize_links()`'s keyword matching na "oświadczenie")
i wyglądało jak przypadkowy niedeterminizm modelu — trzy godziny
diagnozowania "czy to model, czy prompt, czy provider" zanim znaleziono
prawdziwą przyczynę. Naprawione jedną linią; jeśli kiedyś ktoś zobaczy
"niedeterminizm" w indeksach linków/kolejności mimo `temperature=0` —
sprawdź TO najpierw, nie winuj modelu.

**Trzeci dostawca: Gemini (Google AI Studio), dodany 2026-08-07** —
[`GeminiProvider`](../ai-engine/providers/gemini_provider.py), `AI_PROVIDER=gemini`
w `.env`. Powód: darmowy tier Groq (6000 TPM na tym koncie) okazał się za
wąski nawet po przycięciu payloadu (`analyze.py::_prioritize_links()` —
patrz niżej); Gemini ma 1M/65k tokenów wejścia/wyjścia. Na dziś (żywy test na
tej samej realnej stronie) to JEDYNY dostawca, który poprawnie odtworzył
wartość rozbitą w DOM-ie na dwa węzły tekstowe ("4000m d+" jako `"40"` +
`"00m d+"`, quirk konkretnej strony) — Groq i dwa modele Ollama (`qwen3:8b`,
`llama3.1:8b`) wszystkie to przekręcały. UWAGA: limity LICZBY zapytań na
darmowym tierze Gemini są bardzo nierówne między modelami na TYM SAMYM
koncie (sprawdzone: jeden model miał limit 20 zapytań, inny limit 0) —
domyślny `GEMINI_MODEL` to alias `gemini-flash-latest` (Google przekierowuje
na aktualnie obsługiwany model), nie konkretna wersja, właśnie żeby uniknąć
"ta wersja już nie istnieje" (przypadek `gemini-2.5-flash` → HTTP 404 mimo
że była na liście `GET /v1beta/models`).

**Przycinanie payloadu, provider-agnostyczne** (`analyze.py::_prioritize_links()`,
`MAX_LINKS=20`, `MAX_IMAGES=10`) — realna strona wydarzenia miała 52 linki
(nawigacja + FAQ + sponsorzy), co samo w sobie przekraczało limit Groq;
`elements` nie był problemem (był już mały). Priorytetyzuje linki z realną
wartością (rozszerzenie `.gpx`/`.pdf`, słowa: regulamin/zapis/rejestracja/
formularz/faq/trasa/gpx/komunikat/oświadczenie), resztę (nawigacja, social
media) ucina pierwszą.

**DRUGI krytyczny bug tej sesji (2026-08-07, zgłoszony przez usera, że GPX
się źle wybiera mimo jednoznacznego `.gpx` w linku)**: `_prioritize_links()`
przenumerowywało linki na nowo (0..N-1 wg pozycji w PRZYCIĘTEJ liście) —
rozjeżdżało się to z rozszerzeniem Chrome, które `linkAt()`/`imgAt()` w
`sidepanel.js` rozwiązuje wobec SWOJEJ, ORYGINALNEJ (nieprzycietej) tablicy
`pageContext.links` zebranej w przeglądarce. Model poprawnie wskazywał
pozycję w SWOJEJ (przyciętej) liście, ale rozszerzenie pod tym samym numerem
odczytywało zupełnie inny link z oryginalnej listy — wyglądało jak "AI
wybrało źle", a to była niezgodność dwóch RÓŻNYCH systemów numeracji.
Naprawione: linki NIE są przenumerowywane, każdy zachowuje swój oryginalny
`index` (nadany raz, sekwencyjnie, przez `dom-features.js` przy zbieraniu) —
`_valid_index()`/`_valid_gpx_index()` przepisane z "sprawdź pozycję w
tablicy" na "znajdź wpis o tej WARTOŚCI pola index", więc działają
poprawnie niezależnie od tego, jak Python poprzestawia/przytnie kolejność
po swojej stronie. Zweryfikowane żywo: `gpxLinkIndex: 42` w odpowiedzi
odpowiada dokładnie pozycji 42 w ORYGINALNEJ tablicy `dom-features.js` na
testowej stronie (52 linki, GPX daleko w środku listy — właśnie ten
przypadek, który wcześniej się rozjeżdżał).

## Import przejazdów z Garmin Connect (2026-08-23)

Zgłoszenie usera: „jak dodać wgrywanie solo tras z Garmin? (…) może są jakieś
pośrednie aplikacje". Rozpoznanie przed implementacją dało trzy fakty, które
ustawiły całą decyzję:

- **Garmin nie ma publicznego API dla kont osobistych.** Connect Developer
  Program (Activity API + webhooki) to program PARTNERSKI dla firm, z ręczną
  akceptacją wniosku i bez gwarancji terminu. Warto złożyć wniosek, nie warto
  na nim opierać onboardingu.
- **Strava odpada z powodów PRODUKTOWYCH, nie technicznych.** Umowa API od
  11.11.2024 zabrania pokazywania danych użytkownika komukolwiek poza nim samym
  (i trenowania na nich modeli). Mapa odkryć, peleton, kronika i rankingi to
  dokładnie „pokazywanie innym" — Strava API jest więc niezgodna z rdzeniem
  serwisu, a nie tylko „ograniczona". Eksport ZIP (własność użytkownika, nie
  API) pozostaje w porządku i tego dotyczy `Models\ArchiveImport`.
- **Apple Health / Health Connect odpadają technicznie**: Garmin nie zapisuje
  tam trasy GPS, tylko kroki/tętno/dystans. Bez punktów nie ma pól siatki.

Zostaje logowanie jak w aplikacji mobilnej — biblioteka `garminconnect`
(`ai-engine/garmin.py`), wołana przez [`GarminBridge`](../core/Utils/GarminBridge.php)
tym samym mostem `proc_open`, co silnik AI. Mechanika uruchomienia procesu
została przy okazji wyciągnięta do [`PythonBridge`](../core/Utils/PythonBridge.php),
żeby nie istniała w dwóch kopiach.

**TO JEST DODATEK, NIE FUNDAMENT.** Klient jest nieoficjalny: poprzednia
biblioteka (`garth`) przestała działać w marcu 2026 po zmianie logowania po
stronie Garmina, a `garminconnect` przetrwał tylko dlatego, że podszywa się pod
odcisk TLS aplikacji Androida. Wgrywanie pliku GPX zostaje pierwsze na ekranie
i całkowicie niezależne — gdyby Garmin jutro to wyłączył, nie tracimy niczego
poza wygodą wejścia.

**Przepływ** (sekcja „Przejechałeś coś poza wyjazdem?" na `/admin/moje-przejazdy`):
połącz konto → „Pobierz aktywności rowerowe" → lista NOWYCH przejazdów
z zaznaczonymi polami → „Pobierz zaznaczone" → każdy ślad idzie przez
`RiderActivity::recordSolo`, czyli TĘ SAMĄ drogę co plik wgrany ręcznie
(pola, punkty, skarby po drodze, przycięcie okolic domu §27 — wszystko dzieje
się tam, raz).

**Lista, a nie automat**: przycisk pobiera listę, a człowiek decyduje, co z niej
wchodzi. Każdy ślad to punkty i publiczne pola na mapie odkryć, więc „wciągnij
wszystko po cichu" byłoby decyzją podjętą za użytkownika.

**„Sprawdzaj, czy już takich nie ma" — dwie niezależne warstwy** (migr. 068):
rejestr `garmin_activities` odsiewa po identyfikatorze z Garmina, ZANIM
cokolwiek pobierzemy (więc na liście są wyłącznie nowe), a `UNIQUE (user_id,
gpx_hash)` w `rider_activities` łapie ten sam ślad wgrany wcześniej ręcznie —
przypadek, o którym rejestr nie ma prawa wiedzieć. Rejestr zapisuje TAKŻE
duplikaty i odrzucenia (aktywność bez śladu GPS), bo inaczej wracałyby jako
„nowe" po każdym odświeżeniu.

**Bezpieczeństwo**: hasło do Garmina nie jest zapisywane nigdzie — przychodzi
POST-em, jedzie stdin-em (nie argv, więc nie widać go na liście procesów) do
procesu Pythona i ginie razem z nim. W bazie zostaje wyłącznie token sesji,
szyfrowany AES-256-GCM. Logowanie ma własny limit prób (`RateLimiter`,
5/10 min per konto), bo to jest formularz logowania do CUDZEGO serwisu.

**Filtr aktywności**: bierzemy wyłącznie rower (rowerowy `typeKey`), z
wykluczeniem trenażera i jazdy wirtualnej — te nie mają śladu GPS, więc
lądowałyby jako „odrzucone" i za każdym razem zaśmiecały listę.

**OGRANICZENIE, KTÓREGO NIE DA SIĘ OBEJŚĆ W TEJ ARCHITEKTURZE: 2FA mailowe.**
Stan logowania dwuskładnikowego (sesja HTTP + CSRF Garmina) żyje w OBIEKCIE
klienta i nie da się go zserializować, a proces Pythona kończy się razem
z żądaniem HTTP. Kod można więc podać wyłącznie OD RAZU, w polu „Kod 2FA" —
co działa dla aplikacji uwierzytelniającej (kod generowany na żądanie) i nie
działa dla kodu wysyłanego mailem (dociera po tym, jak logowanie zostało już
odrzucone). Obsłużenie tego drugiego przypadku wymagałoby procesu-demona
żyjącego między żądaniami; świadomie NIE zbudowane. Konto z 2FA mailowym
dostaje wprost taki komunikat i ma wgrywanie pliku.

**Wygasły token** przy pobieraniu listy albo imporcie kasuje połączenie
(`GarminController::expiredOrReason`) — inaczej ekran dalej pokazywałby
„Połączono jako…" i przycisk, który nie ma prawa zadziałać, a formularz
logowania (jedyne wyjście z tej sytuacji) w ogóle by się nie pojawił.
Rejestr pobranych zostaje, więc ponowne połączenie nie liczy niczego drugi raz.

**BRAMKOWANE PER ŚRODOWISKO, nie na sztywno „tylko dev"** (włączenie na
produkcji 2026-09-04 opisane niżej — zweryfikowane żywym logowaniem, po
prawdziwej przeszkodzie po drodze). Klucz `garmin` istnieje w `core/config.php`
tam, gdzie hosting ma i Pythona, i `proc_open` — typowy współdzielony hosting
zwykle nie ma ani jednego, ani drugiego. Bez klucza w danym środowisku sekcja
w ogóle się nie pokazuje, a cztery trasy `/admin/moje-przejazdy/garmin/*`
oddają 404 — patrz `Utils\GarminBridge::available()`.

**TRZY POPRAWKI PO PIERWSZYM PRAWDZIWYM UŻYCIU (2026-08-24, zgłoszenia usera).**

1. *„Widzi 16 do pobrania, a na liście nic — w ogóle przeniosło mnie do zakładki
   Plik".* Jedna przyczyna, dwa objawy: `GarminController::back()` nie niósł
   `zrodlo=garmin` (i miał kotwicę `#garmin`, nieistniejącą po przebudowie na
   zakładki). Komunikat pokazywał się poprawnie, bo stoi NAD zakładkami i jest
   wspólny, ale lista rysuje się tylko w panelu Garmina. **Lekcja: przy
   przebudowie ekranu na zakładki trzeba przejść KAŻDY redirect, który na ten
   ekran wraca** — nie tylko ten, który się akurat testuje.

2. *„Sprawdzono 16 aktywności — nic nowego, a ja mam pod 300 w Garminie".*
   `LOOKBACK` wynosił 50 i liczył aktywności WSZYSTKICH dyscyplin; po odsianiu do
   roweru zostawało 16 — akurat tych zaimportowanych poprzednim razem. Reszta
   historii leżała tuż za oknem. Teraz okno to **300 surowych aktywności**
   przeglądanych stronami po 100 w JEDNYM procesie Pythona, a przycisk **„Szukaj
   starszych"** (`start`) sięga dalej i **DOKŁADA** do listy zamiast ją podmieniać.
   Komunikat podaje trzy liczby zamiast jednej mylącej: ile przejrzeliśmy → ile
   z tego rowerowych → ile nowych. Poprzednia wersja podpisywała liczbę rowerowych
   słowem „sprawdzono" i mówiła co innego, niż czytał człowiek.

3. *Piętnaście logowań na jedną partię.* `import` wołał most OSOBNO dla każdej
   aktywności, a każde wywołanie to nowy proces Pythona i nowe logowanie do
   Garmina — przy partii 15 przejazdów kilkadziesiąt sekund i prosta droga do
   blokady po ich stronie. Teraz cała partia idzie jednym wywołaniem
   (`activityIds`), błąd pojedynczej aktywności wraca w `errors` i zapisuje się
   jako odrzucenie, bez przerywania reszty.

Przy okazji: migracja 069 skopiowała szyfrogramy tokenów 1:1, a te niosły stary
kształt (`{"di_token": …}` zamiast koperty `{"session": …}`) — połączenie sprzed
migracji wyglądało więc na nieistniejące („Połączenie nie zadziałało"). Migracja
nie mogła ich przepakować, bo klucz szyfrujący jest w konfiguracji, nie w bazie;
stary kształt rozpoznaje teraz `GarminImport::sessionToken()`.

Testy: `php tests/run.php garmin` (12 przypadków — szyfrowanie tokenu, rejestr,
bramka „nie strzelaj do Garmina po to, co już mamy", koperta mostu).
Zweryfikowane żywo w przeglądarce: odrzucone dane logowania, wygasły token
(z powrotem formularza logowania) i render listy z zaznaczaniem.

**AUTO-DOBIJANIE PARTII (2026-09-06, zgłoszenie usera: „zaznaczone 79, pobrało
tylko 15").** `GarminImport::BATCH` / `DeviceImport::BATCH` (=15) to i tak
zostaje — to jest ochrona przed timeoutem na współdzielonym hostingu, nie
błąd. Zmiana jest wyłącznie w JS (`views/web/partials/ride-sources.php`, pasek
„żywego statusu"): po zdarzeniu `done` ze strumienia, jeśli serwer zgłosi
`pozostalo > 0`, skrypt sam wysyła ten sam formularz ponownie zamiast
przekierowywać — bezpieczne bez zmian po stronie serwera, bo `import()`
odsiewa już przepuszczone id po rejestrze `device_activities` przy KAŻDYM
wywołaniu. Liczniki (`dodane`, `pola`, `duplikaty`, `odrzucone`) sumują się
przez wszystkie partie, więc ekran po zakończeniu pokazuje sumę, nie tylko
ostatnią partię. Błąd w trakcie (np. wygasły token) przerywa dobijanie od
razu — brak zmiany w tym zachowaniu. Bez `fetch`/`ReadableStream` (stara
przeglądarka) formularz wraca do zwykłego PRG: jedna partia, komunikat
„Zostało N — kliknij jeszcze raz", bez zmian. Zweryfikowane testem JS poza
`php tests/run.php` (czysta logika klienta, symulowany strumień NDJSON) —
patrz historia sesji z tej daty, nie ma pliku w repo.

**Włączenie na produkcji (2026-09-04) — DZIAŁA, zweryfikowane żywym
logowaniem.** User: „mój serwer obsługuje Python, nie widzę problemu żeby to
się wydarzyło". Kod nigdy nie miał twardego sprawdzenia `APP_ENV` dla Garmina
(`GarminBridge`, `GarminController::guard()`, `DeviceApi::isUsable()` patrzą
wyłącznie na obecność klucza `garmin` w `APP_CONFIG`), więc samo włączenie
było WYŁĄCZNIE konfiguracyjne. Droga do działającego zestawu pakietów była
za to kręta.

**Przeszkoda po drodze: wersja Pythona na hostingu.** Produkcja stoi na
Namecheap (cPanel/CloudLinux). Python tam idzie wyłącznie przez „Setup Python
App" (CloudLinux Python Selector, venv POZA katalogiem projektu —
`/home/<user>/virtualenv/<approot>/<wersja>/`), a **maksymalna dostępna na
tym koncie wersja to Python 3.9.23**. Pinowany w `requirements.txt`
`garminconnect>=0.3.11` wymaga Pythona ≥3.12 i się tu nie instaluje.
`garminconnect` zgodny z samym 3.9 (bez `--ignore-requires-python`)
zatrzymuje się na wersji 0.2.8 — a ta zależy (`Requires: garth`) od
biblioteki `garth`, **oficjalnie porzuconej przez autora 2026-03-28**:
„Garmin changed their auth flow, breaking the mobile auth approach that
Garth depends on. I'm not in a position to dedicate the time to adapt to
these changes." Nawet ostatnia wersja garth (0.8.0, sama już niedziałająca
dla nowych logowań) wymaga Pythona 3.10+ — ślepy zaułek.

**Rozwiązanie: `garminconnect==0.3.2`, nie `>=0.3.11`.** Metadane PyPI dla
0.3.2 pokazały: `Requires-Python >=3.10` (obchodzalne flagą
`--ignore-requires-python`), zależność `curl_cffi>=0.6` (BEZ `garth` w
ogóle — 0.3.2 jest już na nowym „mobile SSO flow" opartym na `curl_cffi`,
dokładnie tym mechanizmie podszywania się pod TLS Androida, o który chodzi).
Dwie realne przeszkody po drodze, obie pokonane:
1. `garminconnect>=0.3.11` (nowsze wersje 0.3.x) wymaga `curl_cffi>=0.15.0`,
   który **nie ma wheela dla cp39** i nie da się zbudować ze źródeł na tym
   hostingu (`ResolutionImpossible`). `curl_cffi==0.13.0` MA wheel dla cp39 i
   spełnia luźny próg `>=0.6` z 0.3.2 — więc pinowanie właśnie tej pary
   (starszy `garminconnect` + odpowiednio stary `curl_cffi`) było kluczem.
2. Kod `garminconnect` 0.3.2 używa składni adnotacji typów `X | None`
   (PEP 604), którą Python 3.9 ocenia EAGERLY (od 3.10 leniwie) — `import
   garminconnect` wywalał się `TypeError` na pierwszej takiej linii. Łatka:
   ręcznie dopisane `from __future__ import annotations` jako pierwsza linia
   KAŻDEGO pliku `.py` w zainstalowanym pakiecie (`garminconnect` i
   `ua_generator`, jego zależność) — PEP 563 każe Pythonowi 3.9 traktować te
   adnotacje jako zwykły tekst, nie wyrażenie do obliczenia. Łatka na
   ZAINSTALOWANYM pakiecie, nie na kod tego repo — do nałożenia ponownie po
   każdym `pip install --upgrade` na tym venv.

Pełny, przetestowany zestaw wersji (bez którego nie da się tego odtworzyć od
zera) — `ai-engine/requirements-namecheap-py39.txt` (dokładny `pip freeze`
z venv, w którym logowanie przeszło) + runbook instalacji w
`ai-engine/README.md` → „Uruchomienie na produkcji". `requirements.txt` w
repo CELOWO NIE zmieniony — dev ma dalej swobodnie brać najnowszego
`garminconnect` na Pythonie 3.12, prod ma świadomie inny, przypięty zestaw.

**Zweryfikowane żywo na produkcji**: `import garminconnect` bez błędu po
łatce, i prawdziwe logowanie (`{"command":"login",...}` przez `garmin.py`)
zwróciło `{"ok": true, ...}` na realnym koncie usera. `ai_engine` (silnik AI
importera wydarzeń) zostaje CELOWO tylko w `dev` — o niego user nie prosił,
dotyczyło to wyłącznie Garmina. `php tests/run.php garmin` bez regresji po
przywróceniu konfiguracji.

**Zostawione jako opcja na przyszłość, NIE zrobione**: gdyby ten pin kiedyś
przestał działać (Garmin zablokuje starszy klient, albo `curl_cffi==0.13.0`
zniknie z PyPI) — most Garmina jako osobny mały serwis z nowszym Pythonem
(VPS/PaaS), PHP wołałby go przez HTTP zamiast lokalnego `proc_open`, to
realna zmiana architektury (nowy sekret autoryzacyjny, przepisanie
transportu w `GarminBridge`), nierozważana na poważnie, dopóki obecny pin
działa.

## Przejazdy solo: druga zakładka i powiązanie z wyjazdem (2026-08-23)

Zgłoszenie usera zaraz po imporcie z Garmina: „działa, ale co z tego, jak nawet
nie mogę zobaczyć ich, jak zaczytałem (…) a nóż widelec można połączyć jakiś
z eventem". Dwie sprawy, jedna przyczyna: przejazd bez wydarzenia nie miał
ŻADNEGO ekranu. Wpadał do statystyk mapy odkryć i znikał — nie dało się ani
sprawdzić, co weszło, ani nic z tym zrobić.

**Dwie zakładki na `/admin/moje-przejazdy`** (`?tab=solo`, `.disc-tabs` jak
w panelach admina). Osobna tabela, a nie wspólna, bo to dwa różne zestawienia:
wyjazd ma organizatora, obecność i punkty za wydarzenie, przejazd solo ma samą
jazdę — jedna tabela musiałaby połowę kolumn zostawiać pustą. Zakładka idzie
ADRESEM, nie JS-em, żeby dało się ją wysłać i odświeżyć.
Kolumny solo: nazwa (z Garmina, gdy jest — jedyne, co odróżnia od siebie wiersze
z samych dat), data, dystans, odkrycia, punkty, powiązanie.

**Powiązanie z wyjazdem** — [`RiderActivity::linkSoloToEdition()`](../core/Models/RiderActivity.php).
Co się naprawdę dzieje: ten sam plik GPX przestaje być przejazdem „znikąd",
a staje się WŁASNYM ŚLADEM uczestnika na turnusie — czyli dokładnie tym, co
powstaje przy wgraniu pliku na stronie wyjazdu. Dlatego droga jest ta sama
(`EditionTrack::attach()` + `EventAttendance::declare()`), a nie własny,
równoległy zapis. Plik zostaje na dysku nietknięty, przejmuje go `edition_tracks`.

**Dlaczego przejazd solo ZNIKA przy powiązaniu**: gdyby został, ten sam ślad
liczyłby się dwa razy (kilometry i punkty za jazdę; pola i tak odkrywają się raz,
ale suma dystansu byłaby nieprawdziwa). Kasujemy PRZED podpięciem śladu, żeby
odkrycia policzyły się od czystego stanu — inaczej nowy przejazd zobaczyłby
własne pola jako „już odkryte" i pokazał zero nowych. Kasowanie przejazdu
razem ze wszystkim, co z niego wynikło, jest wspólne z wycofaniem obecności
(`RiderActivity::deleteActivity()`, wyciągnięte z `removeForRsvp()`).

**Dwa kierunki, jedna trasa** (`POST /admin/moje-przejazdy/powiaz`): z listy solo
wybiera się wyjazd, a z kolumny „Ślad" w tabeli wyjazdów wybiera się przejazd
(„ślad jako wgraj powinien mieć wybór z listy solo" — po imporcie z Garmina ślad
z tego wyjazdu zwykle JUŻ jest w serwisie, więc kazanie go wgrywać drugi raz to
proszenie o to, co już mamy). Obie strony to ta sama para liczb, więc jeden
endpoint i jeden kod.

**Modal sortuje po DACIE** (prośba usera: „można nawet powiązać z datą"):
opcje ustawiają się wg odległości od daty przejazdu, a ten sam dzień dostaje
etykietę „ten sam dzień" — to jest odpowiedź, po którą człowiek tam przyszedł.
Listę renderuje PHP raz, JS ją tylko porządkuje. Natywny `<dialog>` jak lightbox
zdjęć: Escape, blokada tła i pułapka na fokus za darmo, a bez JS-a przyciski są
po prostu martwe i nic się nie psuje. Komponent CSS: `.pick-modal`/`.pick-opt`.

**Czego powiązanie NIE zrobi:**
- **Bez potwierdzonego zapisu na turnus** — odmowa (ten sam warunek co przy
  wgrywaniu pliku na stronie wyjazdu; ślad do cudzego wyjazdu nie ma sensu).
- **Po odpowiedzi „nie dojechałem"** — odmowa z wyjaśnieniem. Gdyby przeszło,
  przejazd solo by zniknął, a przejazd z turnusu nie powstałby (bez obecności
  nie ma z czego), więc człowiek straciłby odkrycia bez ostrzeżenia.
- **Cudzego przejazdu nie da się powiązać** — `findSoloForUser()` zawęża po
  właścicielu, sam identyfikator nie wystarczy.

**ŚWIADOMY SKUTEK UBOCZNY, POWIEDZIANY WPROST W MODALU:** przejazd solo ma
przycięte okolice domu (§27, `DiscoveryGrid::trimEnds`), a ślad z turnusu liczy
się z CAŁEGO pliku — bo wyjazd zaczyna się na zbiórce, nie pod czyimś blokiem.
Powiązanie zdejmuje więc przycięcie z tego jednego śladu (w teście: 99 pól → 100)
i pokazuje go przy wyjeździe. To jest dokładnie to samo, co dzieje się przy
ręcznym wgraniu własnego pliku na stronie wyjazdu — ale tutaj człowiek nie
wybiera pliku, tylko gotowy przejazd, więc musi to przeczytać, zanim kliknie.

Testy: `php tests/run.php przejazdy_solo` (8 przypadków — m.in. „te same
kilometry NIE liczą się dwa razy", „odkrycia nie przepadają", anty-IDOR i obie
odmowy). Zweryfikowane żywo w przeglądarce w obie strony: z listy solo i z
kolumny „Ślad", razem z automatycznym potwierdzeniem obecności.

### Zakładka solo: szukanie, stronicowanie, podgląd, kasowanie (2026-08-26)

Zgłoszenie usera po wcześniejszym szlifie tej zakładki: „jedyna akcja jaka może
zostać podjęta to przypisanie trasy z wyjazdem, powinienem mieć więcej
możliwości. Wnioskuję o dodanie usunięcia, podglądu, szukania, paginacji."

**Szukanie i stronicowanie** — `RiderActivity::searchSoloForUser()`, wzorzec
1:1 z `KnownRoute::search()` (ten sam kształt wyniku, ten sam sposób budowania
linków `?q=&strona=`). Szuka po nazwie z licznika (`device_activities.activity_name`)
ALBO po surowej dacie `YYYY-MM-DD` — przejazd solo nie ma ani tytułu, ani opisu,
ani miejsca zapisanego osobno od pliku GPX, więc nie ma czego więcej przeszukać.
`soloForUser()` (PEŁNA, nieprzefiltrowana lista) ZOSTAJE obok niej — modal
„Powiąż z wyjazdem" musi widzieć każdy przejazd solo, nie tylko bieżącą stronę
wyników wyszukiwania, inaczej człowiek nie znalazłby w nim przejazdu, który
akurat odfiltrowało szukanie.

**Kasowanie** — `RiderActivity::deleteSoloForUser()`, `SoloRideController::delete()`
(`POST /admin/moje-przejazdy/solo/{id}/usun`). Anty-IDOR przez `findSoloForUser`
(ten sam strażnik co przy powiązaniu). W ODRÓŻNIENIU od powiązania z wyjazdem —
gdzie plik PRZEJMUJE `edition_tracks` i musi zostać — tu nic go nie przejmuje,
więc plik kasuje się też z DYSKU. Rejestr licznika (`device_activities`) zostaje
nietknięty poza odczepieniem wskaźnika (`ON DELETE SET NULL`), żeby ta sama
aktywność Garmina nie wróciła jako „nowa" po skasowaniu. Stan listy (szukanie,
strona) wraca po kasowaniu ukrytymi polami `wroc_q`/`wroc_strona` — usunięcie
wiersza nie ma cofać na początek nieprzefiltrowanej listy.

**Podgląd** — jeden `<dialog>` NA WIERSZ (nie jeden współdzielony, populowany
JS-em jak lightbox zdjęć), żeby skorzystać z gotowego `renderStatTiles()`
(formatowanie i polska liczba mnoga po stronie serwera, bez duplikowania w JS-ie).
Mapa jest LEKKA — `ridemoreCreateMap` + `ridemoreAddGpxTrack`, BEZ warstw
odkryć/skarbów/legendy — świadomy, celowy wyjątek od zasady „jedna standardowa
kontrolka mapy" (patrz pamięć `feedback-one-standard-map`): to podgląd JEDNEGO
pliku w małym okienku, ta sama klasa problemu co per-wpisowa mapa w Kronice
(`chronicle.php`), nie strona-temat jak `/trasy/{slug}`. Mapa inicjuje się
LENIWIE, dopiero przy PIERWSZYM otwarciu danego dialogu (`dialog.dataset.mapInit`)
— przy dwudziestu wierszach na stronie nie ma powodu stawiać dwudziestu instancji
Leafletu, których nikt nie zobaczy.

**BŁĄD ZNALEZIONY PRZEZ USERA ZARAZ PO WDROŻENIU: „nie widzę w podglądzie śladu".**
Przyczyna nie leżała w mapie ani w pliku GPX (oba w porządku — `Utils\Gpx::parse`
i `GpxGeometry` policzyły ten sam plik bez problemu, kafle też) — `/admin/moje-przejazdy`
nigdy wcześniej nie potrzebowało PRAWDZIWEJ mapy (same wiersze tabeli i modale
wyboru), więc `MyRidesController::index()` nie ustawiał `extraHead` w ogóle.
Bez `Support::gpxMapHead()` w `<head>` ani Leaflet, ani `leaflet-gpx`, ani nawet
`assets/js/gpx-map.js` (a więc `ridemoreCreateMap`) NIE ISTNIAŁY w przeglądarce —
kontener mapy zostawał pustym, cichym boksem bez ani jednego błędu w konsoli,
bo JS grzecznie sprawdzał `typeof ridemoreCreateMap !== 'function'` i po prostu
się wyłączał. **Lekcja: dokładając PIERWSZĄ prawdziwą mapę do strony, która
wcześniej żadnej nie miała, sprawdź `extraHead` w kontrolerze — sam błąd
w JS-ie na to nie naprowadzi, bo brakujące funkcje zachowują się jak brak
danych, nie jak awaria.** Naprawione warunkowym `Support::gpxMapHead()`
(tylko gdy `$soloSearch['items']` jest niepuste, ten sam wzorzec co na
profilu rowerzysty i w Kronice).

Testy: dopisane do `php tests/run.php przejazdy_solo` (6 nowych — szukanie po
dacie, izolacja między osobami, kształt wyniku, kasowanie własnego przejazdu
z dysku, anty-IDOR na kasowaniu, nieistniejący przejazd nie wywraca się).
UWAGA na wzorzec w tych testach: kasowanie używa WYŁĄCZNIE kopii pliku
(`t_solo_gpx_kopia()`), nigdy współdzielonego `t_solo_gpx()` wprost —
`deleteSoloForUser()` kasuje plik z dysku NAPRAWDĘ, poza transakcją testu.
Zweryfikowane żywo prawdziwym żądaniem HTTP (sesja wstrzyknięta bezpośrednio,
bez logowania — konto testowe ma nieznane hasło): `<head>` niesie teraz
Leaflet + `leaflet-gpx`, dialog konkretnego przejazdu ma poprawny `data-gpx`.

### Trzy poprawki UX zgłoszone przez usera (2026-08-27)

Zgłoszenie usera: „funkcjonalnie jest ona bez żadnego sensu. Przyciski po
prawej są ogromne i rozpychają row. Upload plików, import z garmin, polar
i innych mało widoczne, brak widoczności z jakiego regionu są przejazdy."
Trzy niezależne poprawki, żadna nie zmienia struktury ekranu:

1. **Region przejazdu solo.** Przejazd solo dotyka tych samych pól siatki co
   wyjazd, więc `rider_activity_regions` (wypełniana w `recordTouchedCells()`,
   migr. 074) już niosła tę informację — ekran po prostu jej nie czytał.
   Nowa stała `RiderActivity::REGION_JOIN` (wzorzec 1:1 z `KnownRoute::find()`:
   `GROUP_CONCAT` po nazwach regionów, bo przejazd bywa na styku kilku) dołożona
   do `soloForUser()` i `searchSoloForUser()`. W widoku region jest dopiskiem
   pod tytułem wiersza (`dash-sub`), TĄ SAMĄ konwencją co `region_label`
   w zakładce Wyjazdy — nie osobną kolumną, bo bywa pusty (świeży przejazd bez
   policzonych jeszcze pól).

2. **Zduplikowany napis na przycisku uploadu.** `input[type=file]` w
   `.track-upload__file` nie był ukryty, więc przeglądarka rysowała WŁASNY
   przycisk „Wybierz plik" (+ „Nie wybrano pliku") tuż obok tego samego napisu
   z `<span>` etykiety — to jest źródło rozjechanego tekstu, który user opisał
   jako brak sensu strony. Naprawione standardową techniką „visually hidden"
   (`position:absolute` + `clip:rect(0,0,0,0)`, NIE `display:none` — ten
   wypadałby z tabulacji). `<label>` dalej opakowuje `<input>`, więc klik
   gdziekolwiek na etykiecie otwiera okno wyboru pliku bez JS-a. Dołożona
   ikona `upload` dla czytelności.

3. **Kolumna Akcje w zakładce solo.** `.dash-row` dzieli kolumny po równo
   (`grid-auto-columns:1fr`) — przy trzech przyciskach (Podgląd/Powiąż
   z wyjazdem/Usuń) w jednej wąskiej kolumnie każdy zawijał się na osobną
   linię, rozciągając wiersz w pionie i wizualnie przytłaczając resztę danych.
   `.dash-table--solo .dash-row` dostał JAWNY podział szerokości kolumn
   (`grid-template-columns`, tylko nad breakpointem telefonu — poniżej i tak
   przechodzi na stos etykiet). Nie dotyka `.dash-row` używanego przez
   `event-participants.php`/`payments.php`/zakładkę Wyjazdy — modyfikator jest
   osobną klasą.

   **Doprecyzowanie tego samego dnia** (zgłoszenie usera: „zamień buttony
   z tekstem na ikonki z tooltipem"): trzy przyciski tekstowe w Akcjach
   zamienione na `.iconbtn` (eye/link/close — TEN SAM komponent, którym panel
   organizatora oznacza podgląd/edycję w `dashboard.php`, więc tooltip to
   natywny `title`/`aria-label`, bez nowego komponentu). Kolumna Akcje po tej
   zmianie NIE potrzebuje już własnej szerokiej kolumny (ikony mają po 34px),
   więc `grid-template-columns` przeliczony na nowo — więcej miejsca oddane
   kolumnie Przejazd (tytuł + region + link do pliku, najgęstsza komórka).
   Dopisek „brak wyjazdów, z którymi można powiązać" (gdy nie ma z czym
   powiązać) dostał `order:9;flex-basis:100%` (`.dash-cell-actions__note`) —
   bez tego długi tekst wciskał się MIĘDZY dwie ikony w kolejności DOM-u
   i rozsuwał je na dwie linie; `order` tylko przenosi go wizualnie na koniec,
   kolejność tabulacji zostaje bez zmian.

Bez zmian API/schematu. Zapytania zweryfikowane bezpośrednio (`php -r` przez
`Core\Database`) na koncie z realną historią (`user_id=115`) — poprawny
`region_label` tam, gdzie są policzone pola, `NULL` tam, gdzie jeszcze nie ma.
Render zweryfikowany żywo (throwaway konto `@local.invalid`, skasowane po
teście razem z danymi testowymi): pojedynczy napis na przycisku uploadu,
region pod tytułem przejazdu, szersza kolumna Akcje.

## Strona przejazdu — `/przejazd/{id}` (2026-09-03)

Zgłoszenie usera: „jeśli mamy przejazd solo, zróbmy dla niego dedykowaną stronę,
aby móc zobaczyć tylko ten przejazd z wszystkimi danymi które mamy w gpx, może
ktoś będzie chciał ściągnąć (…) jakie skarby zaliczone (…) z jakim eventem
połączone (…) coś jak komoot ma". Kontrakt: `tasks/done/strona-przejazdu.md`.

**DOTYCZY KAŻDEGO PRZEJAZDU, NIE TYLKO SOLO** — decyzja podjęta po ustaleniu, że
„solo powiązane z wyjazdem" NIE ISTNIEJE jako stan: `linkSoloToEdition()` kasuje
przejazd solo i przerabia plik na ślad uczestnika, żeby kilometry nie liczyły się
dwa razy. Sekcje „wyjazd / kronika / zdjęcia" mają więc sens wyłącznie na stronie
śladu Z TURNUSU, a jedna strona dla obu rodzajów jest jedynym układem, w którym
w ogóle mogą się pojawić.

**PUBLICZNA, ALE CUDZY ŚLAD SOLO WYCHODZI PRZYCIĘTY.** Cała mechanika prywatności
opiera się na tym, co już było: `gpx_geometry_trimmed` (migr. 076) trzyma ślad po
odcięciu okolic domu (§27) i karmi wspólną heatmapę od 2026-08-28. Strona i
endpoint `/api/rides/{id}/track` sięgają po ten sam wariant, gdy patrzy ktoś inny
niż właściciel:

| kto patrzy | ślad na mapie | pobranie GPX |
|---|---|---|
| właściciel | pełna geometria | oryginalny plik (wysokości, czasy) |
| obcy, przejazd z wyjazdu | pełna (ślad turnusu jest publiczny z natury) | oryginalny plik |
| obcy, przejazd solo, właściciel publiczny | **przycięta** | plik **złożony z przyciętej geometrii** (same współrzędne) |
| obcy, właściciel ukryty z list | — | — (404) |

**PLIKU NA DYSKU NIE RUSZAMY** (nota przy `Utils\Gpx`): jest źródłem dystansu przy
powiązaniu przejazdu z turnusem. Plik dla obcego powstaje w locie z geometrii —
stąd `Gpx::fromPoints()`, przeniesiony z `Utils\Fit`, żeby writer GPX-a był jeden.

**BRAMKA JEST JEDNA I WSPÓLNA ZE STRONĄ PROFILU.** `Support::visibleRiderById()`
dociąga konto i pyta `visibleRider()` — nie powtarza jej trzech warunków; przejazd
osoby ukrytej z list daje **404, nie 403**. `Support::strangerTrackPath()` dokłada
do tego regułę „solo + publiczny właściciel = wolno pokazać wariant przycięty"
i słuchają jej OBA wejścia: strona i endpoint geometrii. Gdyby endpoint miał własną
wersję tej reguły, adres API byłby obejściem strony.

**CO JEST NA STRONIE** (kolejność jak na `/trasy/{slug}`, bo to ten sam rodzaj
strony): nagłówek z nazwą przejazdu (z licznika, gdy jest — to jedyne, co odróżnia
od siebie wiersze z samych dat), autor z linkiem do profilu · pasek `stat-tiles`
(dystans + średnia, przewyższenie, czas w ruchu, nowe pola z liczbą pól po drodze,
punkty, skarby) · mapa STANDARDOWA z warstwami odkryć i skarbów, ślad w KOLORZE
PRZEJAZDU (migr. 073) · profil wysokości z chipami podjazdów · skarby na polach
przejazdu · znane trasy, które ten jeden przejazd objął · wyjazd + kronika +
zdjęcia z niego · rozbicie punktów z `point_transactions` · inne własne przejazdy
tędy.

**CZEGO NIE MA I DLACZEGO:** tętna, kadencji, mocy i kalorii — `Gpx::parse` czyta
wyłącznie lat/lon/wysokość/czas, a rejestr licznika (`device_activities`) trzyma
nazwę, datę i dystans; nie ma ich skąd wziąć bez nowego parsowania rozszerzeń GPX.
Zdjęć DO przejazdu też nie ma (nie ma takiej tabeli i nie dokładamy jej) —
pokazujemy zdjęcia WYDARZENIA, gdy ślad z niego pochodzi. Cudzych przejazdów tą
samą drogą nie pokazujemy nigdy: „kto tędy jeździ" to pytanie, którego ten serwis
nie zadaje.

**PROFIL WYSOKOŚCI TYLKO DLA WŁAŚCICIELA** — nie z uprzejmości, tylko dlatego, że
liczy się z PLIKU, a przycięta geometria nie niesie wysokości. Zmierzone: strona
z parsowaniem 33-kilometrowego pliku oddaje się w ok. 100 ms (TTFB), więc nie
potrzebuje własnej kolumny na profil.

**DWA BŁĘDY ZNALEZIONE NA ŻYWO, NIE W KODZIE:**
1. Kadr mapy brany z `rider_activities.gpx_hash` był pusty dla przejazdów
   Z WYJAZDU — one mają tam NULL, bo ich ślad mieszka w `edition_tracks`. Hash
   liczy się teraz z pliku (`ensure()`/`ensureTrimmed()`, i tak cache'owane).
2. Wiersz potrafi wskazywać plik, którego już nie ma na dysku (w bazie dev: 39 z 91
   przejazdów solo). Strona obiecywała wtedy pobranie i pokazywała pustą mapę —
   teraz `is_file` przed jednym i drugim, a bez śladu sekcja „Przebieg" po prostu
   się nie pojawia.

**PODPIĘCIA:** dymek „Zobacz przejazd" na mapie prowadzi TERAZ TUTAJ (wcześniej:
solo → lista przejazdów zawężona po dacie, wyjazd → strona wydarzenia; jedno było
obejściem, drugie odpowiadało o imprezie, a nie o czyjejś jeździe), a wiersze
zakładki „Przejazdy solo" dostały link „strona przejazdu". Panel „Ostatnia
aktywność" na mapach ZOSTAJE bez linku — jego kliknięcie ustawia mapę i to jest
jego zadanie.

**POPRAWKA TEGO SAMEGO DNIA — ADRES WYJAZDU I WSPÓLNE KOMPONENTY.** Zgłoszenie
usera: „źle budujesz linki, nie korzystasz z base url (…) skorzystaj już
z gotowych partiali jeśli jest sposobność". Znalezione i naprawione:
- **`/wydarzenia/{slug}` dawało 404** — publiczna strona wyjazdu to `/events/{slug}`,
  a `/wydarzenia/{slug}` istnieje wyłącznie z przyrostkami akcji (`/edytuj`,
  `/uczestnicy`, `/zatwierdz`). Reszta adresów szła przez `View::url()` poprawnie.
- Sekcja „Z tego wyjazdu" opisywała wyjazd własnym akapitem — teraz renderuje
  **`partials/event-card.php`** (dane z `Event::cardsForEditions()`), więc okładka,
  daty, region, cena i stan zapisu przychodzą z tego samego komponentu co katalog
  i strona główna, razem z poprawnym adresem.
- Galeria zdjęć → `.photo-gallery` + `.ph-link` + **`partials/photo-lightbox.php`**
  (jak na stronie wyjazdu), zamiast kafelków zbudowanych na kartach tras.
- Karty skarbów i profil wysokości były SKOPIOWANE ze `trail.php` → wydzielone do
  **`partials/treasure-list.php`** i **`partials/elevation-profile.php`**, a obie
  strony ich teraz używają. Inicjalizacja wykresu (odczyt atrybutów `data-`
  i bramka na zerowy kontener) przeniesiona do
  **`ridemoreSetupElevationProfile(map)`** w `assets/js/gpx-map.js`.
- Lokalne domknięcie `$plural` (jedenasta kopia tej samej funkcji w widokach) →
  **`Utils\Format::plural()`**.

**AUDYT ADRESÓW PO ZGŁOSZENIU „dalej masz błędy w URL" (2026-09-03).** Sprawdzone
na wyrenderowanym HTML-u i na żywym ruchu sieciowym: KAŻDY adres wewnętrzny idzie
przez `View::url()` / `View::asset()` / `Image::src()` i niesie `base_path`
(`/ridemore`), a w bazie nie ma ani jednej ścieżki zapisanej z prefiksem albo
jako pełny URL. Wszystkie 404 na stronach dotyczyły PLIKÓW, nie adresów: w bazie
dev skasowane były **wszystkie 28 uploadów** (awatary, okładki, galerie, zdjęcia
skarbów). Prawdziwa usterka, którą to odsłoniło, jest jednak w kodzie i dotyczy
całej aplikacji: komponenty sprawdzały „czy adres jest w bazie", a nie „czy plik
istnieje", więc zamiast swoich stanów zapasowych (inicjały w awatarze, grafika
zastępcza na karcie wyjazdu) pokazywały ikonę zepsutego obrazka. Naprawione
`Utils\Image::exists()` w trzech wspólnych komponentach + filtrem galerii na
stronie przejazdu; `Image::src()` zostaje bez zmian, bo `<img src="">` w miejscu
bez alternatywy jest gorszy od zepsutej ikony.

**PEŁNE ADRESY W CAŁYM SERWISIE (decyzja usera, 2026-09-03).** „Chodziło mi o to,
żebyś linki budował o tak: `http://localhost/ridemore/assets/uploads/covers/…`".
`Utils\View::url()` — jedno miejsce, przez które przechodzi każdy wewnętrzny
adres (668 wywołań) — składa teraz `schemat://host` + `base_path` + ścieżka.
**Host bierze się z BIEŻĄCEGO ŻĄDANIA, nie z `app_url`**, i to jest sedno: ten
sam kod odpowiada pod `localhost`, pod IP maszyny (apka mobilna ładuje żywy
serwis z `server.url`; przy testach z telefonu to `http://192.168.x.x/ridemore`)
i pod domeną produkcyjną — adres z konfiguracji wysłałby telefon do niego samego.
`app_url` zostaje zapasem dla CLI (cron, migracje, testy) i jedynym źródłem dla
`absoluteUrl()`, czyli maili, pusha, JSON-LD, canonical i og:image, które muszą
wskazywać adres KANONICZNY i nie mogą ufać nagłówkowi `Host`. Adres już
bezwzględny wychodzi z `url()` nietknięty (awatar z Google). Przy okazji
poprawiony jedyny adres, który zostawał względny mimo tej zmiany: lista wyjazdów
zapamiętuje w `sessionStorage` URL z filtrami, a `ui.js` wstawia go jako `href`
w nagłówku — teraz zapisuje pełny. Zmierzone po zmianie: na dziesięciu
sprawdzonych stronach **zero** adresów względnych w `href`/`src`/`action`.

Testy: `php tests/run.php przejazdy_solo` (8 nowych — przycięta geometria kontra
pełna, kadr z przyciętej, ukryty rowerzysta nie wydaje śladu, jedno źródło reguły
„kto jest publiczny", plik dla obcego daje się sparsować, kształt skarbów/tras,
`Format::duration`, „inne przejazdy tędy" znajdują wspólny teren i pomijają
przypadkowe minięcie). Zweryfikowane żywo w trzech rolach: właściciel (mapa,
profil, wszystkie sekcje), obcy zalogowany i niezalogowany (ostrzeżenie
o przyciętych końcach, brak profilu wysokości, brak sekcji „inne przejazdy",
pobranie oddaje 810 punktów bez czasów).

## Liczniki: pliki FIT i zakładki źródeł (2026-08-23, migr. 069)

Decyzja usera po rozpoznaniu innych producentów: „ok zatem dodaj (…) zrób proszę
zakładkami wybór sposobu dodawania: Upload (GPX/FIT), Garmin, Polar, Wahoo, Coros,
Suunto".

### FIT — format, w którym nagrywa KAŻDY licznik
[`Utils\Fit`](../core/Utils/Fit.php) dekoduje FIT i **zamienia go na GPX na WEJŚCIU**.
To jest cała decyzja architektoniczna: FIT jest formatem wejścia, nie drugim torem
danych. Gdyby szedł własną drogą, dystans, przewyższenie, pola siatki i skarby
istniałyby w dwóch wersjach, które prędzej czy później dałyby dwa różne wyniki dla
tego samego przejazdu. Po konwersji cały serwis widzi to samo co zawsze —
`Utils\Gpx::parse()`, jeden format na dysku, jeden komplet liczb.

Wejście: `Upload::saveTrackTemp()` (osobne od `saveGpxTemp()`, bo tamto obsługuje też
GPX-y TRAS w kreatorze wydarzenia, gdzie plik z licznika nie ma czego szukać).
Biblioteka: `adriangibbons/php-fit-file-analysis` — oznaczona przez autora jako
`abandoned` i to jest świadomie przyjęte ryzyko: format `record` z pozycją nie zmienił
się od lat, paczka jest jednym plikiem bez zależności, a wymiana dotknęłaby wyłącznie
`Utils\Fit` (reszta serwisu widzi GPX). Pozycje przychodzą już w stopniach; odrzucamy
punkty (0,0) i spoza zakresu — licznik bez fixa zapisuje takie i ślad wyjechałby na
Zatokę Gwinejską, zamalowując po drodze pół Afryki.

**Co to odblokowuje naraz**: pliki prosto z licznika (katalog `Activities` po kablu —
Edge, ELEMNT, Bryton, iGPSPORT), eksporty z serwisów bez API oraz pliki z Wahoo,
COROS i Suunto, które FIT-a oddają jako jedyny format.

### Zakładki źródeł
[`partials/ride-sources.php`](../views/web/partials/ride-sources.php) —
jeden partial na całą kartę „Przejechałeś coś poza wyjazdem?".
**Plik jest pierwszy i to nie jest kolejność alfabetyczna**: działa zawsze, dla każdego
licznika i bez niczyjej zgody; integracje są wygodą, nie fundamentem. Zakładka idzie
adresem (`?zrodlo=`), bo powrót z autoryzacji OAuth musi umieć wskazać właściwą.
Kropka przy nazwie = konto podłączone, widać to bez wchodzenia w każdą z osobna.
Garmin ma własny podpanel (`partials/source-garmin.php`), bo jako jedyny nie ma OAuth
i stoi w nim PRAWDZIWY formularz logowania.

Komponent CSS `.src-tabs`/`.src-tab` jest **osobny od `.disc-tab`** i to jest różnica
znaczeniowa, nie kosmetyczna (prośba usera: „niech wyglądają jak zakładki, a nie jak
pastylki"): pastylka mówi „zawęź to, co widzisz" (filtr listy), zakładka mówi „przełącz
na inną treść". Stąd wspólna krawędź u dołu paska i podkreślenie pod aktywną.

### Dostawcy przez OAuth2
[`Utils\DeviceApi`](../core/Utils/DeviceApi.php) — metadane, OAuth i adaptery.
**W ODRÓŻNIENIU OD GARMINA te integracje działają na produkcji**: to zwykły OAuth2 po
HTTPS, bez mostu do Pythona. Klucze wyłącznie z env (`POLAR_CLIENT_ID` itd.), dostawca
bez kompletu jest po prostu wyłączony — przycisk się nie pokazuje, a trasa oddaje 404
(zweryfikowane: wszystkie cztery zwracają 404 przy zalogowanej sesji i braku kluczy).

**Adapter powstaje TYLKO dla API z publiczną dokumentacją**, w której da się sprawdzić
adresy:
- **Polar AccessLink** — rejestracja klienta samoobsługowa (`admin.polaraccesslink.com`),
  wymiana kodu przez HTTP Basic, jednorazowa rejestracja użytkownika (`POST /v3/users`,
  gdzie **409 znaczy „już jest" i jest poprawnym wynikiem**), lista `GET /v3/exercises`,
  ślad `GET /v3/exercises/{id}/gpx`. **Ograniczenie produktowe, powiedziane wprost
  w zakładce**: Polar odda wyłącznie przejazdy nagrane PO podłączeniu konta i tylko
  z ostatnich ok. 30 dni.
- **Wahoo Cloud API** — klucze po akceptacji wniosku, lista `GET /v1/workouts`, plik
  przez `GET /v1/workouts/{id}/workout_summary` → `file.url` (CDN, pobierany **bez**
  nagłówka Authorization, żeby nie rozjechać podpisu w adresie), format FIT.

**COROS i Suunto mają zakładki, ale nie mają adapterów** — i to jest decyzja, nie
zaniechanie: COROS udostępnia specyfikację dopiero po onboardingu partnerskim, Suunto
trzyma ją za rejestracją w APIzone. Zgadywanie endpointów dałoby kod, który wygląda na
gotowy i wywala się przy pierwszym prawdziwym koncie. Zakładka mówi wprost, czego
brakuje, i odsyła do wgrania pliku FIT.

**Własny klient OAuth zamiast `league/oauth2-client`**: dostawcy różnią się dokładnie
tam, gdzie `GenericProvider` ma jedną drogę (Polar wymaga Basic i zwraca `x_user_id`,
Wahoo posyła dane klienta w ciele). Logowanie SPOŁECZNOŚCIOWE dalej idzie przez
`Utils\OAuthProvider` i nie ma z tym nic wspólnego.

**`state` jest jedyną ochroną callbacku** (to zwykły GET, przeglądarka nie dołoży tam
tokenu CSRF): losowy, jednorazowy, sprawdzany razem z nazwą dostawcy — token Polara
zapisany jako Wahoo byłby cichą awarią.

### Wspólny rejestr zamiast tabel per dostawca
Migracja 069 uogólnia `garmin_connections`/`garmin_activities` do
`device_connections`/`device_activities` z kolumną `provider` (szczegóły →
[`database.md`](database.md)). Dzięki temu pytanie „czy tę aktywność już wciągnęliśmy"
ma JEDNĄ odpowiedź niezależnie od tego, z czyjego serwera przyszła, a jedna osoba może
mieć podpięte dwa liczniki naraz.

Testy: `php tests/run.php liczniki` (12 przypadków — dekoder FIT na PRAWDZIWYM pliku
z zegarka, odrzucenie nagrania bez GPS, bramka „bez kluczy nic nie działa", brak
zdublowanego katalogu w adresie powrotnym, rejestr rozdzielony po dostawcy, tekstowe
identyfikatory Polara) oraz `php tests/run.php garmin` (13, w tym dwa liczniki naraz).
Zweryfikowane żywo: prawdziwy plik FIT z zegarka wysłany formularzem HTTP z sesją
i CSRF wylądował jako przejazd solo (20,5 km, 219 m, 24 nowe pola, data z pliku).

**Czego NIE zweryfikowano na żywo**: prawdziwego połączenia z Polarem i Wahoo — to
wymaga kluczy API i konta u dostawcy. Adaptery są napisane z publicznej dokumentacji
i pierwsze prawdziwe połączenie trzeba przeklikać ręcznie.

## Automatyczny import z licznika — webhooki Polar i Wahoo (2026-09-14, migr. 088)

Zgłoszenie usera: „mając podpięty Garmin, czy jest możliwość, aby z automatu
pobierały się trasy z Garmina, Polara i innych?". Decyzje usera:
- **Tylko to, co oficjalne.** Polar AccessLink i Wahoo Cloud API SAME zawiadamiają
  o nowym treningu (webhook). Garmin takiego kanału bez programu partnerskiego nie
  daje, a odpytywanie go z crona nieoficjalną biblioteką (jedno IP serwera dla
  wszystkich kont, wygasający token bez hasła do ponownego logowania) zostało
  odrzucone. COROS/Suunto dalej czekają na onboarding partnerski.
- **Automat z przełącznikiem, DOMYŚLNIE WYŁĄCZONY** (nie „skrzynka do akceptacji"
  i nie „podłączenie = zgoda"). To zdejmuje zasadę „lista, a nie automat" z sekcji
  o Garminie wyłącznie dla osób, które SAME ją zdjęły przy połączonym koncie.
- **Powiadomienie push + mail** o każdym dodanym przejeździe.

**Przepływ.** Zakładka Polar/Wahoo na `/admin/moje-przejazdy` → „Dodawaj nowe
przejazdy automatycznie" (`DeviceController::autoImport`, komponent `.acc-notif`
jak zgody w koncie) → dostawca wysyła `POST /api/liczniki/{dostawca}/webhook` →
[`DeviceWebhookController`](../core/Controllers/DeviceWebhookController.php) sprawdza
podpis, **odpowiada 200 i dopiero potem** woła `DeviceImport::fromWebhook` → dla
każdej osoby z włączonym automatem przy tym koncie (`DeviceConnection::autoImportUsers`
po `external_user_id`) ta sama droga co „Pobierz zaznaczone" (`DeviceImport::import`:
rejestr, `recordSolo`, §27) → `DeviceImport::notifyImported` przez `Notifier`.

**Uwierzytelnienie** (`DeviceApi::verifyWebhook`): Polar — HMAC-SHA256 treści, hex,
w `Polar-Webhook-Signature`; Wahoo — `webhook_token` w treści. Sekrety z env:
`POLAR_WEBHOOK_SECRET`, `WAHOO_WEBHOOK_TOKEN`. Bez sekretu `DeviceApi::webhookReady`
jest fałszem: przełącznik się nie pokazuje, odbiornik oddaje 404.

**Trzy pułapki, które ten kod omija:**
1. **PING Polara przed podpisem.** Polar zakłada webhook dopiero po 200 na PING,
   a sekret do podpisu oddaje w odpowiedzi na to zakładanie — wymaganie podpisu
   na PING-u znaczyłoby „webhooka nie da się założyć nigdy". PING nic nie zmienia.
2. **Odpowiedź przed importem** (`fastcgi_finish_request` / `litespeed_finish_request`
   — Namecheap to LiteSpeed; na XAMPP-owym mod_php `Content-Length` + `flush`).
   Polar sam wyłącza webhook po 7 dniach nieudanych doręczeń, Wahoo ponawia i dubluje.
   Zmierzone na dev: 200 po 0,007 s, import startuje po zamknięciu odpowiedzi.
3. **Automat nie ma kogo zapytać „czy to rower".** Lista z zaznaczaniem pokazuje
   wszystko, ale automat dodaje WYŁĄCZNIE rower z trasą (`DeviceApi::isCycling`):
   Wahoo po `workout_type_id` z tabeli „Workout Types" (rodzina BIKING/OUTDOOR, bez
   17 = motocykl), Polar po `detailed_sport_info` (BIK/CYCL bez INDOOR/VIRTUAL…,
   `has_route` ≠ false). Wątpliwy trening NIE trafia do rejestru — dalej czeka na
   liście do ręcznego pobrania.

**Wahoo: zakres `offline_data` i `user.id`.** Bez tego zakresu Wahoo nie wysyła
webhooka dla danej osoby, a webhook podaje identyfikator Wahoo, nie nasz. Nowe
połączenia dostają oba (zakres w `META`, id z `GET /v1/user` w `registerUser`);
starsze przy włączaniu automatu dostają `id` dopisane, a bez zakresu komunikat
„odłącz i połącz ponownie" (`DeviceApi::missingWebhookScopes`).

**Założenie webhooka Polara** — raz, z produkcji, PO wdrożeniu trasy:
`php polar_webhook.php --create` wypisuje sekret (Polar pokazuje go tylko wtedy).
`--recreate` przy zgubionym sekrecie, `--activate` po samoczynnym wyłączeniu.
Wahoo: adres i token wpisuje się ręcznie w panelu aplikacji na developers.wahooligan.com.

**Powiadomienie**: typ `NotificationGate::PRZEJAZD_Z_LICZNIKA` (`device_ride`),
**transakcyjny** (odpowiedź na własny trening i własną decyzję — bez budżetu i ciszy),
zgody `push_rides`/`mail_rides`, klucz `dev:{dostawca}:{id treningu}` (ponowiony
webhook nie wyśle drugi raz), treści edytowalne w `/admin/powiadomienia`
(`NotificationTexts::POWIADOMIENIA['device_ride']`). Przełączniki w koncie widać tylko
przy włączonym automacie.

**Przy okazji**: dokumentacja AccessLink pokazuje dziś klucze z podkreślnikiem
(`start_time`), adapter czytał `start-time` — `DeviceApi::polarItem` czyta oba.

Testy: `php tests/run.php liczniki` (12 nowych — podpis, próbki treści z dokumentacji,
filtr roweru, adresowanie tylko do włączonych, deduplikacja powiadomień). Żywo na dev
(tymczasowy sekret, konto-wydmuszka, sprzątnięte): przełącznik w obie strony, zły
podpis → 401, dobry → 200 i próba pobrania treningu z Polara na koncie z automatem,
po wyłączeniu → 200 bez importu, przełącznik „Przejazdy z licznika" w koncie.
**Nie zweryfikowane**: prawdziwe doręczenie od Polara/Wahoo i realny import przez
webhook — wymaga produkcji (publiczny adres), kluczy i konta u dostawcy.

## Żywy status „Pobierz zaznaczone" (2026-08-26)

Zgłoszenie usera: „nie widać, czy coś się pobiera, ile już się udało, ile jest
w trakcie, ile błędów". Do tej pory „Pobierz zaznaczone" było jednym blokującym
POST-em — wynik (dodane/duplikaty/odrzucone) pokazywał się dopiero PO przekierowaniu,
a w trakcie nie było żadnej informacji poza zwykłym ładowaniem strony.

**WSPÓLNE dla Garmina i każdego dostawcy przez `DeviceController`** (Polar, Wahoo,
przyszli) — to jest ta „uniwersalność": kolejny dostawca dodany do `DeviceController`
dostaje pasek postępu bez żadnego kodu specyficznego dla niego, bo idzie tym samym
kontrolerem i tym samym modelem.

**Mechanizm**: [`Utils\ProgressStream`](../core/Utils/ProgressStream.php) — NDJSON
(jeden obiekt JSON na linię, `flush()` po każdej), nie SSE (to zwykły POST z ciałem,
a `EventSource` umie tylko GET). Włącza się WYŁĄCZNIE, gdy JS poprosi nagłówkiem
`X-Progress-Stream: 1` — bez tego (stara przeglądarka, brak JS) formularz zachowuje
się DOKŁADNIE jak wcześniej: zwykły POST i przekierowanie (PRG). `GarminController::import()`
i `DeviceController::import()` mają teraz `finish()`/`redirectUrl()` zamiast gołego
`back()` — w trybie strumieniowym `header('Location')` już nie zadziała (nagłówki
poszły razem z pierwszym `flush()`), więc gotowy adres leci jako OSTATNIE zdarzenie
(`{"type":"done","redirect":"..."}"`), a JS sam na niego przechodzi.

**Granularność progresu RÓŻNI SIĘ między dostawcami, bo architektura pobierania
jest inna, i to jest świadome, nie przeoczenie:**
- **Garmin** — cała partia leci JEDNYM wywołaniem mostu do Pythona (jedno logowanie,
  naprawa z 24.08 opisana wyżej — patrz „piętnaście logowań na partię"). Tego etapu
  NIE dało się rozbić na zdarzenia per aktywność bez cofnięcia tamtej naprawy, więc
  panel dostaje JEDNO zdarzenie `{"type":"batch","stage":"downloading","total":N}`
  na całą partię, a żywy licznik per aktywność startuje dopiero w pętli zapisu
  (`recordSolo` + liczenie pól siatki — realny, mierzalny czas).
- **Polar/Wahoo (i przyszli przez OAuth)** — każda aktywność to OSOBNE zapytanie HTTP
  w PHP, bez mostu do Pythona, więc progres jest granularny NAPRAWDĘ: zdarzenie
  `stage:"downloading"` przed pobraniem KAŻDEJ pozycji, `stage:"done"` po niej.

`Models\GarminImport::import()` i `Models\DeviceImport::import()` przyjmują teraz
opcjonalny 4. parametr `?callable $onProgress` (domyślnie `null` — reszta kodu,
w tym wszystkie testy sprzed tej zmiany, działa bez zmian). Wywoływany z tablicą
zdarzenia w miejscach, gdzie coś NAPRAWDĘ się kończy (nie w sztucznych krokach).

**JS** (koniec [`partials/ride-sources.php`](../views/web/partials/ride-sources.php),
WSPÓLNY dla obu formularzy przez `[data-import-progress]`): `fetch()` zamiast
zwykłego submit, czyta odpowiedź przez `ReadableStream`, dzieli po `\n`, aktualizuje
pasek i liczniki na żywo. Bez `fetch`/`ReadableStream` (stara przeglądarka) w ogóle
się nie podpina — formularz zostaje nietknięty. Błąd sieci w trakcie → `location.reload()`,
bo to, co zdążyło się zaimportować, już jest zapisane po stronie serwera (rejestr
per aktywność) i zgadywanie, gdzie się zatrzymało, jest gorsze niż odświeżenie.
Komponent CSS: `.import-progress` (`assets/css/style.css`).

Testy: dopisane do `php tests/run.php garmin` i `php tests/run.php liczniki` —
pusta partia (aktywność już znana) nie woła `onProgress` ani razu, więc panel nie
otwiera się na zero pozycji.

Zweryfikowane ŻYWO przez prawdziwe żądanie HTTP do działającego Apache/XAMPP
(sesja + CSRF wstrzyknięte bezpośrednio, bez logowania — konto testowe ma nieznane
hasło, patrz pamięć `reference_local_test_login`): `Content-Type: application/x-ndjson`,
`Transfer-Encoding: chunked`, zdarzenie `batch` faktycznie dociera ODDZIELNIE od
końcowego `done` (nie jednym kawałkiem na końcu), a błąd (zepsuty token) trafia
poprawnie do `expiredOrReason()` i kasuje połączenie tak samo jak w trybie bez JS.
**Czego NIE zweryfikowano żywo**: prawdziwego pobrania z Garmina z działającym
kontem (brak konta) — jak reszta importu z Garmina wyżej.

## Trwałość sesji (fix „po chwili wylogowuje" na prod)
- `core/bootstrap.php`: własny `storage/sessions`, długi `gc_maxlifetime`, lekki własny GC,
  cookie remember_me odczytywane przed `session_start`. `Core\Auth::login` (30 dni „Zapamiętaj mnie").

## „Trasa dnia" — wydarzenie ALBO znana trasa, złożony obrazek z prawdziwych kafli (2026-09-05)
Karta na stronie głównej (`home.php`, `#h2-trasa`), przebudowana po dwóch
zgłoszeniach usera w jednej sesji: „jeśli zdjęcie jest za duże to rozciąga całą
kontrolkę" (bug) i „kluczowe informacje są trochę małe i niewiele wnoszą,
warto ją przeprojektować... dołożyłbym też znane trasy, rozszerzają one zakres
Trasa dnia — nie musi być to tylko event".

**Bug — zdjęcie rozciągało kartę.** `.rotd-photo img{height:100%}` liczy się
względem wysokości RODZICA, a `.rotd-photo` jej nie miała (auto) — przy
zdjęciu o proporcjach innych niż atrybuty HTML `width="420" height="180"`
(te są tylko podpowiedzią, CSS je nadpisuje) przeglądarka liczyła wysokość
z WŁASNYCH proporcji obrazka rozciągniętego na 100% szerokości komórki, czyli
wysokość CAŁEGO WIERSZA SIATKI `.rotd-body--split` — stąd rozciągała się cała
karta. Naprawa: `.rotd-photo{height:clamp(110px,16vw,190px)}`, ten sam
`clamp()` co wykres obok, `object-fit:cover` wycina nadmiar.

**Dwa źródła zamiast jednego** (`HomeController::buildRouteOfDay()`):
1. `Event::firstUpcomingWithElevationProfile()` — najbliższe nadchodzące
   wydarzenie z profilem elewacji. MA PIERWSZEŃSTWO (decyzja usera: „wydarzenie
   ma pierwszeństwo" — niesie termin i licznik zapisów, więc „co się dzieje
   niedługo" wygrywa z katalogiem, który leży tam zawsze).
2. `KnownRoute::routeOfDay()` — gdy żadne wydarzenie się nie kwalifikuje.
   Kwalifikuje się trasa aktywna, z plikiem GPX i ZNANYM przewyższeniem.
   Wybór jest STABILNY W OBRĘBIE DNIA (dzień roku modulo liczba kandydatek),
   nie losowy przy każdym wejściu — „trasa DNIA" ma znaczyć to samo dla
   każdego, kto wejdzie tego samego dnia. Bez własnej tabeli, jak Kronika i Puls.

Obie ścieżki oddają WSPÓLNY kształt (`type: 'event'|'route'` rozstrzyga cel
linków — `/events/{slug}` vs `/trasy/{slug}` — i to, czy pokazać
datę/organizatora, których trasa katalogowa nie ma).

**Ślad z prawdziwej mapy** — domyślna wizualizacja zamiast (albo obok) wykresu
profilu wysokości. PIERWSZA WERSJA (ten sam dzień) rysowała abstrakcyjny SVG —
pola siatki odkryć połączone linią, rzutowane na płaski `viewBox` bez związku
z rzeczywistą geografią. User ją odrzucił po zobaczeniu na żywo: „myślałem że
bedzie to wyglądało jak tiles z mapy... taki wykres to nic nie ma wspolnego
z tym co ci załączyłem" (załączył zrzut prawdziwej mapy: kafle OSM, zielone
pola odkryć, gruba czerwona linia śladu). Zapytany o architekturę wybrał przez
`AskUserQuestion`: „Statyczny, składany obrazek z prawdziwych kafli" — bez
Leafletu/JS-a na stronie głównej — JEDYNA żywa, interaktywna mapa w serwisie
to `ridemoreDiscoveryMap` (`assets/js/discovery-map.js`), ta karta ma być
dekoracją, nie drugą jej implementacją.

**Jak działa** (`Controllers\TileController::routeOfDayMap`, trasa
`/assets/tiles/rotd/{key}/{data}.png`, `{key}` to `ev-{id}`/`kr-{id}` —
WYŁĄCZNIE te dwa, inne klucze `TileSource` — `me`/`u-{slug}` — są tu
zablokowane, bo to jedyne prywatne):
1. Bounding box trasy w lat/lon: `KnownRoute::boundsFor()` (już istniał) dla
   tras katalogowych, `GpxGeometry::boundsFor()` po `ensure()` dla wydarzeń.
2. Dobór zoomu i okna kadrowania: `TileController::rotdWindow()` próbuje
   kolejne zoomy od najgłębszego, aż trasa zmieści się w kadrze 1200×260
   z 12% oddechu — jak Leaflet `fitBounds()`, tylko policzone raz po stronie
   serwera przy pomocy istniejącej `Utils\TileGrid`.
3. Kafle mapy bazowej: NOWY `Models\OsmBasemap` — jedyne miejsce w serwisie,
   które samo ściąga cudzy kafel (OSM Tile Usage Policy: własny User-Agent,
   rotacja poddomen a/b/c, kafle trzymane na dysku pod `assets/tiles/osm/`
   trwale, nie tylko na czas żądania — znane trasy WRACAJĄ w rotacji
   `KnownRoute::routeOfDay()`, więc cache realnie oszczędza ruch).
4. Pola trasy: te same cell_id, które naliczyłby Discovery po odbytym
   wyjeździe — `KnownRoute::cellIds()` dla katalogu, `RoutePreview::
   cellsForGpx()` dla wydarzeń (TEN SAM cache po hashu pliku, którego już
   używa „co mi to da" na stronie wydarzenia — zero nowej infrastruktury).
   Rysowane ISTNIEJĄCYM `Utils\TileRenderer::hexes()` (ten sam kod, którego
   już używa żywa mapa odkryć), ale z `$fog = null`-owym wypełnieniem: kafel
   trasy pokazuje pola PRZY OKAZJI, nie ma być zamglony jak żywa mapa
   (`TileController::renderHexes()` już to rozróżniało dla `ev-`/`kr-`,
   tylko `TileSource::hexCells()` dotąd nie miało dla nich żadnych danych —
   ta ścieżka istniała jako martwy kod, aktywowany dopiero teraz).
   KOLOR: bursztyn `--blaze-dark` (#F0B41E), nie zieleń — zmierzone na żywym
   kaflu: pole ma tu ok. tyle pikseli, ile grubość śladu (paleta
   `Utils\TrackPalette::COLORS`, sześć kolorów, ŻADEN złoty), więc zielone
   wypełnienie ginęło pod śladem albo zlewało się z zielenią terenu OSM —
   bursztyn jest jedynym kolorem serwisu zarezerwowanym pod „zwróć uwagę"
   i świeci przy każdym śladzie i każdym terenie.
5. Ślad trasy: ISTNIEJĄCY `TileSource::tracks($key)` + `TileRenderer::tracks()`
   — dokładnie ten sam kod i kolor (per-trasa z palety), którego już używa
   żywa mapa odkryć dla `ev-{id}`/`kr-{id}`. Zero nowego kodu rysującego linię.
6. Sklejenie: NOWY `TileRenderer::compose()` — jedyne miejsce w serwisie,
   które łączy kafel mapy bazowej z własną nakładką w jeden obrazek (każda
   inna mapa stawia je jako osobne warstwy Leafletu w przeglądarce). Wkleja
   kafle na wspólne płótno, tnie do dokładnego okna, zapisuje PNG.
7. Cache na dysku: `assets/tiles/rotd/{key}/{data}.png` — adres niesie datę
   zamiast z/x/y (to jeden plik, nie piramida), więc odświeża się raz
   dziennie; ten sam zapis atomowy (tmp+rename) co `Models\TileCache`, plus
   sprzątanie plików POPRZEDNICH dni tego klucza (dochodzi się tam tylko,
   gdy dzisiejszego jeszcze nie ma). Apache oddaje istniejący plik regułą
   `!-f` jak każdy inny kafel — PHP dostaje tylko pierwsze wejście dnia.

Gdy pliku GPX nie ma na dysku (dev: częsty brak uploadów) albo etap go jeszcze
nie ma, `mapImageUrl` wychodzi `null` i widok spada na wykres profilu
wysokości (`svgLinePath`/`svgAreaPath`, jak dotąd).

**Reszta karty** (uwaga usera: liczby „trochę małe i niewiele wnoszą"):
- `.rotd-stats` zamiast dawnego `.rotd-meta` — ikona (`Utils\Icon::render('route')`
  dla dystansu, `'tre-viewpoint'` dla przewyższenia, ten sam znak co „Nowy
  teren" na /odkrycia) + pogrubiona liczba zamiast drobnego wersalikowego mono.
- Krótki opis (`HomeController::excerpt()`, ucięty na granicy słowa,
  max 160 znaków) — z `events.description` albo `known_routes.description`.
- Ikonka organizatora (TYLKO `type === 'event'` — katalog tras nie ma
  organizatora) — reużyte 1:1 klasy `.hcard-org`/`.hcard-av`/`.hcard-ver`
  z `partials/home-event-card.php`, nie druga implementacja tego samego
  (awatar albo inicjał, znaczek „zweryfikowany").

Testy: `php tests/run.php` (bez nowych `t_test` — feature jest w całości po
stronie kontrolera/renderera kafli, bez własnej tabeli do przetestowania
w izolacji). Obrazek zweryfikowany ŻYWO pod obiema ścieżkami: znana trasa
(„wislana trasa", id 36934, 233 km — na tej skali pola siatki wychodzą
poniżej piksela, więc widać samą linię, co jest oczekiwaną, nie błędną,
degradacją dla bardzo długich tras) i wydarzenie (id 4, 32,9 km, realny plik
GPX na dysku — tu pola są wyraźnie widoczne, złota obwódka wzdłuż całego
śladu). Sprawdzone przez bezpośrednie żądanie HTTP do `/assets/tiles/rotd/
{key}/{data}.png`, wizualną inspekcję zapisanego PNG-a i drugie żądanie
potwierdzające, że Apache oddaje już zapisany plik bez budzenia PHP-a
(`Content-Length` identyczny, brak nagłówka `X-Powered-By`). Dzisiejsza
prawdziwa „Trasa dnia" (wydarzenie bez pliku GPX na dysku w dev) potwierdza
spadek na wykres profilu wysokości — ten sam, znany wcześniej z tej sesji
brak plików GPX w środowisku dev, nie regresja.


## Strony regionów — `/regiony/{kraj}/{region}` (2026-09-14)

Kontrakt: `tasks/done/strony-regionow.md`. Powód: audyt SEO — serwis był
w Google tylko na hasło „ridemore bike”, a filtry regionu na `/wydarzenia`
mają canonical na samo `/wydarzenia`, więc żadna strona nie odpowiadała na
„wyjazdy rowerowe podkarpackie”.

- **Adresy:** `/regiony` (spis), `/regiony/{kraj}` (kraj z regionami; kraj
  bez regionów, np. Słowacja, jest tu od razu stroną regionu),
  `/regiony/{kraj}/{region}`. Kody wprost ze słownika `region`. Region pod
  złym krajem → 301, nieaktywny (dawne pasma „bieszczady”…) → 404.
- **Bez progu indeksowania** (decyzja usera) — każda aktywna strona w indeksie
  i w `/sitemap-regions.xml`, także pusta. Pusta SEKCJA się nie renderuje.
- **Szablon = strona znanej trasy**, zero nowych komponentów (patrz
  `views-and-frontend.md`). Kolejność: wyjazdy → mapa i trasy → skarby →
  zdjęcia z regionu → organizatorzy → „Kto tu jeździ” → odbyte → inne regiony.
- **„Zdjęcia z regionu” (2026-09-16)** — prośba usera: zdjęcia z relacji,
  opinii i skarbów, „ważne, aby wiadome było, co jest źródłem danego zdjęcia
  i czego dotyczy”. `RegionController::regionPhotos` łączy
  `EventPhoto::forRegion` (relacje i opinie uczestników z wyjazdów w
  `event_regions`; bez uploadów organizatora, szkiców i czekających na
  weryfikację) z `TreasurePhoto::latestFor` — 12 najnowszych. Pod KAŻDYM
  zdjęciem: rodzaj źródła, przedmiot z linkiem (wyjazd → `#opinie`, skarb →
  `/odkrycia?skarb=`), autor i data; ten sam podpis w lightboksie
  (`data-caption`). Decyzje usera: **zdjęcie skarbu tylko, gdy `reveal()`
  odsłonił go widzowi w całości** (jawny albo przez widza znaleziony — zdjęcie
  pokazuje, czego szukać; skarby przychodzą już po `reveal()`, bez drugiego
  warunku w SQL-u); **autor imieniem tylko przy publicznym profilu**
  (`roster_visible` + `public_slug`, jak „Kto tu jeździ”), inaczej „uczestnik
  wyjazdu” / „znalazca skarbu”. Konta zablokowane nie wychodzą wcale.
  Przy okazji: okładka regionu ma podpis „Zdjęcie: wyjazd/trasa „X”” z linkiem
  (wcześniej opisana nazwą regionu), a kafle karty organizatora mówią, czy to
  zdjęcia z profilu, czy okładki wyjazdów (`Organizer::coverPhotosWithSource`;
  wcześniej zawsze „zdjęcie z wyjazdu”).
- **„Kto tu jeździ”** (`RiderActivity::ridersInRegion`) — liczba wszystkich
  osób z przejazdami w regionie, awatary tylko publicznych profili; region
  przejazdu solo pochodzi z pól liczonych PO przycięciu okolic domu (§27).
- **Linki wchodzące:** okruszek regionu na wydarzeniu, tag regionu na trasie,
  „Region” na profilu organizatora, chipy SEO na dole `/wydarzenia`,
  „Przeglądaj po regionach” na stronie głównej, stopka.
- **Poza zakresem:** regiony turystyczne w obrębie województw (niezmiennik
  „jeden heks = jeden region”), strony typów roweru, ręczny opis regionu.
- Testy: `php tests/run.php wyglad_stron` (nazwa MUSI sortować się po
  `widoki_test` — strona dokleja jednorazowy `photo-lightbox.php`).

## Wielojęzyczność — `/en/…` (2026-09-16/17, gałąź `feature/wielojezycznosc`)

Kontrakt: [`tasks/active/wielojezycznosc.md`](../tasks/active/wielojezycznosc.md).
Zasada: **polski bez prefiksu i bez zmian**, inne języki pod `/{kod}/…`; przebudowy nie ma.

- **Prefiks liczy JEDNO miejsce** — [`Core\Lang`](../core/Core/Lang.php): `splitPath`
  (woła `Router::stripBasePath`), `localizePath` (woła `View::url`/`absoluteUrl`,
  idempotentne), `BEZ_PREFIKSU` (callbacki OAuth, `/auth/app`, callbacki/webhooki
  liczników, `/assets/`, pliki z rozszerzeniem — sitemapy, kafle). Język bieżący ustawia
  `bootstrap.php` (`initFromRequest`). Włączone języki: `APP_CONFIG['languages']`
  (dev `['pl','en']`, prod `['pl']`). Trzeci język = wpis w configu + `core/lang/{kod}.php`.
- **Brak przekierowań** poza wejściem na `/`: preferencja (`rm_lang` cookie, potem
  `users.lang`) przenosi na `/en`. Na podstronach tylko baner „This page is available
  in …" (`assets/js/ui.js`, dane `window.RM_LANG_ALT` z `partials/head.php`).
  Przełącznik: `partials/lang-switch.php` (belka, stopka, apka) + `POST /api/jezyk`.
- **Interfejs**: `__('polski tekst', ['pole' => …])` i `__n(n, one, few, many)`
  z [`core/i18n.php`](../core/i18n.php); słownik `core/lang/en.php` (klucz = polski
  tekst ze ściśniętymi białymi znakami). JS: `window.__` + `core/lang/en-js.php`
  (GENEROWANY). Narzędzie: `php i18n.php stats|missing|export|js|unused en` — skanuje
  `__()`, `__n()`, lokalne `$plural(…)` i `Format::plural(…)`, `__('…')` w JS, stałe
  z treściami powiadomień i nazwy słowników z bazy. **Po dodaniu tekstu: dopisz
  tłumaczenie, potem `php i18n.php js en`** — test `wielojezycznosc_test` pilnuje braków.
  - Pułapki: nie sklejaj zdań z liczebnikiem (`$n . ' ' . $plural(...) . ' reszta'`)
    — całe zdanie w `__n` z `{n}`; stała klasy / domyślny parametr nie może zawierać
    `__()` (tłumacz w miejscu użycia); `__()` w atrybucie Alpine działa (globalne `__`).
  - Strony statyczne: `views/web/pages/en/{how-it-works,for-organizers,terms,privacy}.php`
    — `View::render` bierze `pages/{lang}/…`, jeśli istnieje. **Zmieniając polski
    regulamin/politykę, zmień też tłumaczenie** (EN informacyjne, wiąże PL).
  - Panel STAFF (`$adminGet/$adminPost`) zawsze po polsku (`Lang::set('pl')`);
    panel użytkownika pod `/admin` — tłumaczony.
- **Treści z bazy** (tytuły/opisy eventów, etapy, warianty, bio organizatora, trasy,
  skarby): [`Models\ContentTranslation`](../core/Models/ContentTranslation.php)
  `fields/text/prefetch/prefetchRows` → tabela `content_translations` (migr. 089) →
  [`Utils\Translator`](../core/Utils/Translator.php) (driver `log`/`google`/`deepl`/`ai`). Język źródła zgaduje
  `Lang::guess`; tekst już w języku strony nie idzie do tłumacza. **Przy jednym
  włączonym języku nic nie jest tłumaczone** (prod bajt w bajt jak przed zmianą).
  Etykieta + „Pokaż oryginał" (`?oryginal=1`, `noindex`): `partials/translated-note.php`.
  Korekta ręczna: `GET/POST /tlumaczenie/{event|organizer|route|treasure}/{id}`
  (`TranslationController`) — wygrywa z automatem; stare wpisy maszynowe sprząta
  `cron.php` (noc, `sweep()`).
  - **Driver `ai`** (model językowy przez `ai-engine/translate.py`, dostawca z `AI_PROVIDER`):
    tekst bez tłumaczenia NIE idzie do modelu w trakcie renderowania, tylko do kolejki —
    wiersz `origin = 'pending'` z `source_text`; strona pokazuje oryginał (i `noindex`).
    `cron.php tlumaczenia` (co ~10 min) → `ContentTranslation::processQueue()` tłumaczy
    paczkami (≤25 tekstów / 12 tys. znaków), zeruje `source_text`. Budżet dzienny i przerwa
    po awarii jak przy pozostałych driverach (`Translator::translate`).
- **Maile i push w języku odbiorcy**: wysyłki owinięte w `Lang::with(lang, fn)`;
  język z `Lang::forEmail` (`users.lang`, istniejące konta bez wpisu = polski).
- **SEO**: hreflang pl/en/x-default(en) + `og:locale` w `head.php`, canonical na siebie,
  sitemapy z `xhtml:link` (`Utils\Sitemap::urlset`), `inLanguage` w JSON-LD eventu.
- **Świadomie nie tłumaczone**: nazwy województw/regionów (nazwy własne), nazwy skarbów
  w dymkach mapy i API, opisy w rejestrze punktów (`point_transactions` — zapisane dane),
  panel staff.
- Testy: `php tests/run.php wielojezycznosc`.

## Route Planner — `/planer` (Etap 1, 2026-09-17)

Planer tras rowerowych, oparty na hybrydowej architekturze wypracowanej przez dwa
kolejne panele architektoniczne (spike + „final architecture challenge",
`tasks/active/route-planner.md`): **routing engine odpowiada „którędy można",
logika Ridemore odpowiada „którędy warto"**. Świadomie PHP + JS, bez Pythona i bez
self-hosted infrastruktury routingu — publiczny OSRM (do 2026-09-18 `router.project-osrm.org`,
który — jak się okazało — liczył wyłącznie trasy SAMOCHODOWE; od tego dnia rowerowy
OSRM FOSSGIS, patrz „Warstwa routingu Ridemore” na końcu sekcji) jako silnik startowy, z jednym jawnym „szwem wymiany":
`Utils\RoutingProxy` zwraca WŁASNY, znormalizowany kształt danych, nigdy surowy JSON
dostawcy, więc zmiana silnika w przyszłości zostaje zmianą jednej klasy.

**Segmenty, nie cała trasa naraz.** `Controllers\PlannerController::calculate()`
liczy trasę PER PARA kolejnych waypointów (dokładnie jak stary planner
w `history/assets/js/planner/PlannerRouter.js`, tylko po stronie serwera) — dzięki
temu „który odcinek dołączyć do znanej trasy" jest jednoznacznym indeksem, bez
odwzorowywania z powrotem punktów scalonej geometrii na oryginalne waypointy.

**Skarb to zwykły waypoint** (`type='treasure'`, kosmetyka na liście/mapie) — bez
osobnego algorytmu „zjazd do skarbu i powrót". To wychodzi samo z kolejności
segmentów: OSRM(start→skarb) + OSRM(skarb→cel) już DAJE efekt zjazdu i powrotu,
zweryfikowane live (3 waypointy w jednym zapytaniu do OSRM).

**„Dołącz do znanej trasy" — jawna akcja, nie automatyczna magia** (Etap 1;
**ZASTĄPIONE 2026-09-18** przez „Źródła trasy" na końcu sekcji — `findSuggestion`,
`hybridSegment` i toast usunięte, akapit zostaje jako historia decyzji). Dwa różne
pytania, dwie różne struktury danych (świadomie, po debacie o pomieszaniu ich):
- *Czy w ogóle warto zapytać* — `KnownRoute::geometryInBounds()` (środki pól,
  tania warstwa wizualna mapy, `PlannerController::layers`) + `Utils\RouteSnap::
  findSuggestion()` na REALNEJ geometrii kilku kandydatów (nigdy wszystkich tras
  w kadrze) — próg 100 m + detour-ratio 1.8×, port matematyki z
  `history/assets/js/planner/PlannerSnap.js`.
- *Jak faktycznie dołączyć* — `PlannerController::hybridSegment()`: OSRM do wejścia
  na trasę → wycięty kawałek jej REALNEJ geometrii (`KnownRoute::linePoints()`,
  łańcuch `gpx_url → GpxGeometry::ensure/load → TileGrid::toLatLon`, NIE środki pól)
  → OSRM od zjazdu do celu. To jest właściwe dołączenie — geometria FAKTYCZNIE
  pokrywa się z zapisaną trasą, nie tylko „OSRM niezależnie znalazł jakąś drogę
  między tymi samymi punktami" (zweryfikowane live: odcinek 77 km bez dołączenia →
  103,6 km z dołączeniem do `wislana-trasa`, bo koryt jest dłuższy/malowniczy —
  dokładnie taka „preferencja, nie ograniczenie", jakiej oczekuje specyfikacja).

**Przewyższenie liczone leniwie.** `Utils\ElevationLookup` (publiczne, darmowe API
`opentopodata.org`, próbka do 100 punktów) wołane WYŁĄCZNIE przy zapisie trasy —
nie przy każdym przeliczeniu podczas przeciągania waypointów. `null` nie blokuje
zapisu (`planned_routes.ascent_m/descent_m` nullable).

**Storage minimalny z realnego powodu, nie z lenistwa** — `Models\PlannedRoute`
(migr. 090): jedna tabela, właściciel = jedyny widz, bez widoczności/publicznego
linku/wariantów (świadomie odłożone przez panel). IDOR pilnowany w SQL
(`WHERE user_id = :user_id`), zweryfikowane testem i live (obcy user dostaje `null`/404,
nie cudze dane).

**Frontend**: mały zestaw modułów bez bundlera. `assets/js/planner/route-model.js`
jest czystym, niezależnym od DOM-u i Leafleta modelem waypointów, segmentów oraz
kontekstu routingu (testowanym bezpośrednio w Node). `assets/js/planner.js` jest
orkiestratorem UI na `ridemoreCreateMap()` (ta sama fabryka bazowej mapy co reszta
serwisu — bez warstwy mgły odkryć, która planerowi nie służy). Waypointy są
przeciągalnymi markerami Leaflet, a przeliczanie jest debounce'owane (300 ms).
Eksport GPX idzie przez `Utils\Gpx::fromPoints()` (jedyny writer GPX-a w serwisie,
bez zmian). Podział rozpoczęto od modelu, bo to stabilna granica dla kolejnych
funkcji (np. historia operacji i warianty), bez odtwarzania rozdrobnienia starego
planera z `history/`.

**Zweryfikowane live** (login testowym userem, prawdziwe zapytania do OSRM i
opentopodata.org): rysowanie trasy, zapis (z realnym przewyższeniem z API), wczytanie
do edycji, eksport GPX przez HTTP, odrzucenie CSRF (403), przekierowanie gościa
(302 → `/logowanie`), podpowiedź dołączenia na prawdziwej trasie `wislana-trasa`
i faktyczne dołączenie (hybrydowy segment). Testy: `php tests/run.php planner`
(15 przypadków: geometria `Utils\RouteSnap`, walidacja bez sieci, IDOR na
`Models\PlannedRoute`).

**Poza zakresem Etapu 1 (świadomie)**: automatyczny scoring/generowanie wariantów
z popularności, tryb eksploracji nowych heksów, import GPX, publiczne
udostępnianie zaplanowanej trasy.

**„Zacznij od istniejącego śladu" (kolejny etap, 2026-09-17, na życzenie usera)**
— zamiast rysować trasę od zera, user wybiera GOTOWY przebieg jako bazę: własny
przejazd solo (`RiderActivity::searchSoloForUser`, ta sama metoda co panel
„Moje przejazdy"), aktywną znaną trasę (`KnownRoute::search`, ta sama metoda
co katalog `/trasy`) albo wgrywa własny plik GPX (`Utils\Gpx::parse`,
EFEMERYCZNIE — punkty wracają do przeglądarki, nic nie ląduje trwale na
dysku, w odróżnieniu od GPX-a wydarzenia/znanej trasy). Trzy panele
wyszukiwania, jeden endpoint geometrii (`/api/planer/zrodlo?typ=&ref=`) i
jeden endpoint uploadu (`/api/planer/wgraj-gpx`).

**Kluczowa decyzja projektowa — reużycie mechanizmu "dołącz znaną trasę"
zamiast nowej ścieżki kodu** (do 2026-09-18 — od „Źródeł trasy" baza jest
kontekstem `base` całej trasy, nie `attachments["0"]`). Wybrana baza NIE jest specjalnym przypadkiem:
staje się zwykłymi waypointami START/CEL + segmentem 0 oznaczonym jako
hybrydowy, dokładnie tak jak przy dołączaniu podpowiedzianej znanej trasy —
tylko że linia bazowa przychodzi WPROST OD KLIENTA (`attachments["0"] =
{points:[...], label}`), zamiast być doszukiwana po slugu
(`PlannerController::resolveAttachmentLine()` obsługuje teraz obie postaci).
Dzięki temu `hybridSegment()` (OSRM do wejścia → wycięty kawałek → OSRM od
zjazdu) obsługuje WSZYSTKIE trzy źródła (przejazd/trasa/GPX) bez własnej
logiki, a „kontynuacja trasy po bazie" (klik na mapie po jej końcu) działa
przez ISTNIEJĄCY mechanizm segmentów bez żadnej dodatkowej zmiany — nowy
punkt to po prostu kolejny waypoint bez attachmentu, czyli zwykły OSRM.

Zweryfikowane live end-to-end (znana trasa `wislana-trasa` jako baza →
233,1 km z odznaką „dociągnięto" → rozszerzenie klikiem → 289,2 km łącznie;
upload małego pliku GPX zwraca poprawne punkty). Świadomie ograniczone do
WŁASNYCH przejazdów solo (ta sama zasada prywatności co §27 — plik solo jest
surowy i zaczyna się pod domem) i tylko rozszerzania NA KOŃCU trasy (nie od
początku) — obie te granice to celowe, tanie decyzje zakresu, nie luki.

**Znane trasy + popularność — PRAWDZIWE KAFLE, nie własna wersja (poprawka
2026-09-18, zgłoszenie usera: „po co tworzysz coś extra, jeśli masz już gotowe
rozwiązanie").** Pierwsza wersja (2026-09-17) rysowała obie te warstwy
własnoręcznie: znane trasy linią ze ŚRODKÓW HEKSÓW
(`KnownRoute::geometryInBounds()` — metoda jawnie udokumentowana od
2026-08-20 jako „NIE ZASILA JUŻ MAPY", zachowana wyłącznie pod regułę „co jest
w kadrze" i testy), a popularność własnym rysowaniem heksów z
`/api/discovery/cells`. Obie były gorszą, równoległą wersją czegoś, co już
istniało: warstwa kaflowa **„Ślady"** (`TileSource::LAYER_TRACKS` = `slady`),
ta sama co na `/odkrycia/spolecznosc`, z REALNĄ geometrią i gradientem krycia
wypalonym w PNG po stronie serwera (`Utils\TileRenderer`), nie przybliżeniem
z hexów. Teraz planer dokłada ją wprost:
- `PlannerController::index()` buduje DWA szablony kafli przez
  `Models\TileCache::urlTemplate(TileSource::LAYER_TRACKS, $key)`: `kr`
  (wszystkie aktywne znane trasy) i `all` (społecznościowe ślady/heatmapa,
  ten sam klucz co mapa społeczności) — statyczne URL-e `{z}/{x}/{y}` z `?v=`
  z `tile_epochs`, przekazane raz do configu strony.
- `planner.js` dokłada je jako zwykłe `L.tileLayer` przez
  **`ridemoreAddTileLayer()`** — gotowa funkcja z `assets/js/gpx-map.js`
  (już wczytanego przez `Support::leafletMapHead()`), zero nowego `<script>`,
  zero własnej matematyki heksów/skali kolorów. Leaflet dociąga kafle SAM przy
  przesuwaniu mapy — `PlannerController::layers()` (`/api/planer/warstwy`)
  odchudzony do WYŁĄCZNIE skarbów (jedyne dane, które faktycznie potrzebują
  AJAX-a po bboxie; kafle nie).
- Przełącznik „Pokaż popularne ślady (heatmapa)" po prostu dodaje/zdejmuje
  warstwę kaflową `all` z mapy (`map.addLayer`/`removeLayer`) — bez żadnego
  zapytania przy przełączeniu, kafle są już wczytane albo Leaflet je dociąga
  leniwie przy pierwszym dodaniu.
- Kafle są WYŁĄCZNIE wizualne (PNG). Od „Źródeł trasy" planer bierze linie
  z TYCH SAMYCH zbiorów hashy, z których serwer rysuje kafle, ale z realnej
  geometrii (`GpxGeometry`), nigdy z obrazka.

Zweryfikowane live: oba klucze kafli (`slady/kr/...`, `slady/all/...`)
zwracają 200 i realną geometrię (widoczne prawdziwe znane trasy jako kolorowe
linie w okolicy Krakowa, nie zygzak).

**„Warstwy referencyjne" — trzecia warstwa (`me`) i reorganizacja sekcji**
(sekcję ZASTĄPIŁY tego samego dnia „Źródła trasy", niżej; kafle `kr`/`all`/`me`
i ich przełączniki zostały) **(2026-09-18, drugie zgłoszenie usera tego dnia: „mogę mieć wielokrotne
zaznaczenie — moje przejazdy, przejazdy społeczności, znane trasy mogą się
pojawiać [jednocześnie]", plus „dziś nie mam możliwości pokazania przejazdów
społeczności" jako osobnej, nazwanej kategorii).** Rozdzielone dwa pojęcia,
które wcześniej były posklejane: **co widać na mapie jako odniesienie**
(może być kilka warstw naraz, każda z osobnym przełącznikiem) vs **co
konkretnie staje się bazą trasy** (zawsze jedno, przez wyszukiwarkę). Sekcja
„Zacznij od istniejącego śladu" stała się **„Warstwy referencyjne"** i
połączyła w sobie: cztery niezależne przełączniki widoczności (Moje
przejazdy, Znane trasy, Przejazdy społeczności, Skarby — wszystkie mogą być
włączone jednocześnie) + pod-przełącznik „Podpowiadaj dołączenie" (dawne
`plannerSuggestKnown`, przeniesiony pod „Znane trasy", bo konceptualnie tam
należy) + niezmienioną wyszukiwarkę „Ustaw jako bazę trasy" (Moje
przejazdy/Znane trasy/Wgraj GPX, jak w Etapie 3). „Konfiguracja trasy"
skurczyła się do samego profilu roweru — jedynej rzeczy, która naprawdę jest
o trasie, nie o warstwach.

Trzecia warstwa kaflowa: `trackTiles.me` (`TileCache::urlTemplate(...,
'me')`) — TEN SAM klucz co osobista mapa `/odkrycia` (`me` = własne przejazdy
solo, rozwiązywane po stronie serwera z `Auth::user()->id`, NIGDY z żądania —
`TileSource::tracks()` już to robi, zero nowej logiki backendowej poza
dopisaniem jednego wpisu do `trackTiles`). Wszystkie trzy warstwy (`kr`,
`all`, `me`) dokładają się przez tę samą `bindLayerToggle()` w `planner.js` —
jedna funkcja, trzy niezależne przełączniki, kolejność dodania (kr → all →
me) decyduje o kolejności rysowania w tym samym pane'ie (moje przejazdy na
wierzchu, jako najbardziej osobiście istotne).

Zweryfikowane live: `slady/me/...` zwraca 200 dla zalogowanego usera;
włączenie wszystkich trzech warstw naraz daje 36 kafli w DOM-ie (12×3),
wyłączenie „Znane trasy" zdejmuje dokładnie 12 z nich — przełączniki działają
niezależnie i naprawdę wielokrotnie.

**Zabezpieczenie przed „nadkładaniem po referencji" (2026-09-17/18,
zgłoszenie usera z doświadczenia starego plannera)** (od „Źródeł trasy"
ta reguła żyje jako `snapRatio` 1,2× w `RouteSnap::preferredRuns` — dla
KAŻDEGO wklejanego kawałka; `isNearLineEnd`/`ATTACH_MAX_DETOUR_RATIO` usunięte) — `hybridSegment()`
(faktyczne liczenie dołączonego odcinka, nie tylko `findSuggestion()`, która
tylko PROPONUJE) nie miało ŻADNEGO zabezpieczenia przed nadmiernym objazdem.
Ryzyko: trasa referencyjna, która NIE jest pętlą (tam-i-z-powrotem), może
przebiegać blisko samej siebie w dwóch różnych miejscach wzdłuż linii —
`closestPointOnLine()` szuka najbliższego punktu po współrzędnych, nie po
sensownym przebiegu, więc `sliceBetween()` mógł wyciąć kawałek między dwoma
odległymi indeksami (np. cały odcinek tam-i-z-powrotem) zamiast rozpoznać
krótszą, bezpośrednią drogę. Naprawa: `Utils\RouteSnap::isNearLineEnd()`
(czysta, testowalna) rozróżnia świadome użycie CAŁEJ bazowej trasy (oba końce
segmentu siedzą na końcach linii — Etap 3, długi dystans jest CELEM) od
dołączenia W ŚRODKU (`findSuggestion()`) albo przeciągniętego waypointu — w
tym drugim przypadku `hybridSegment()` liczy realną trasę bezpośrednią przez
OSRM i porównuje: gdy wycięty kawałek jest ponad `ATTACH_MAX_DETOUR_RATIO`
(1,2×, celowo OSTRZEJSZY niż `SUGGEST_DETOUR_RATIO` 1,8× — to pytanie
o poprawność, nie o atrakcyjność propozycji) dłuższy niż droga bezpośrednia,
segment **po cichu wraca do zwykłego OSRM** (odpowiedź przestaje twierdzić
„dociągnięto" — `attachedRoute` w JSON-ie zależy teraz od nowej flagi
`attached`, nie tylko od obecności attachmentu). Zweryfikowane live na
syntetycznej trasie tam-i-z-powrotem: zły wybór dawał 1,59× realnej drogi
OSRM (949 m vs 1512 m) — mieściłoby się w starym, wspólnym progu 1,8×, więc
próg musiał być OSOBNY i OSTRZEJSZY dla tego przypadku. Kontrolny test (oba
końce = końce całej bazowej trasy) potwierdza, że Etap 3 dalej używa całej
referencji bez zmian.

**Przeciąganie trasy — historia dwóch pierwszych wersji (2026-09-18→19).**
Najpierw stały uchwyt na środku każdego odcinka (odrzucone przez usera:
„chcę złapać DOWOLNY fragment trasy"), potem chwytanie jednej scalonej
`routeLine` w dowolnym miejscu + `closestSegmentIndex()` i
`addWaypoint(…, segIndex + 1)`. Obie wersje testowano syntetycznymi
zdarzeniami (`dispatchEvent`), które NIE generują `click` — dlatego
przeszły testy, a w prawdziwej przeglądarce dawały błąd opisany niżej.
Notatka o „TypeError …baseVal" przy przeciąganiu markerów też była
artefaktem takich testów (zdarzenie wysłane na `document` → Leaflet robi
`addClass(document)`); przy prawdziwej myszy konsola jest czysta.

**Model trasy i kolejność punktów (2026-09-18, zgłoszenie usera:
„przeciągam odcinek A–B, a NOWY ląduje na końcu" + „istniejących punktów
nie da się przesuwać").** Frontend w `assets/js/planner/route-model.js`
i `assets/js/planner.js` oraz jedna reguła CSS — zero zmian po stronie
serwera. Dwie właściwe
przyczyny, zmierzone na żywo prawdziwą myszą:

1. Przeglądarka po `mouseup` dokłada `click` na wspólnym przodku miejsca
   wciśnięcia (linia) i puszczenia (mapa). Leaflet tłumi taki klik tylko
   po przeciągnięciu MAPY albo markera, a na czas chwytu linii
   `map.dragging` było wyłączone → `map.on('click')` → `addWaypoint()`
   z domyślnym „na koniec". Wynik: `START → A → NOWY → B → CEL → NOWY`
   (wstawienie na `segIndex + 1` działało, dochodził duplikat jako nowy
   CEL). To samo robił zwykły klik w pinezkę — marker bez słuchacza
   `click` Leaflet (`_findEventTargets`) oddaje klik mapie.
2. Widoczna pinezka nie była uchwytem markera: `.planner-map-pin` miała
   `pointer-events:none` + `translate(-50%,-100%)`, a divIcon
   `iconAnchor [14,28]` — pinezka rysowała się ~14 px w lewo i ~28 px
   w górę od przeciągalnego (niewidocznego) pola markera. „Złapanie
   punktu" przesuwało mapę, a klik w pinezkę dopisywał punkt na końcu.

**Model** (`RidemorePlannerRoute.create()` w `assets/js/planner/route-model.js`
— czysty, bez DOM-u i Leafleta; UI jest wyłącznie jego widokiem):
- `waypoints[i] = {id, lat, lng, type, label}` — indeks = kolejność
  przejazdu. `type` (start/via/end) wynika z pozycji, skarb zostaje
  `treasure`. `id` jest stałe — marker po nim znajduje swój bieżący indeks.
- Kolejność zmieniają WYŁĄCZNIE `insertWaypoint(index, point)` (zawsze
  jawny indeks; zły → `RangeError`, nigdy „domyślnie na koniec"),
  `insertBetween(fromId, toId, point)`, `removeWaypoint(index)`,
  `replaceWaypoints(list)`. `moveWaypoint(index, lat, lng)` zmienia tylko
  współrzędne istniejącego punktu.
- `segments[i]` to ZAWSZE para `i → i+1` — `afterChange()` wyrównuje je po
  każdej zmianie: nietknięte pary zachowują policzoną geometrię, nowe albo
  zmienione są `pending` (przerywana prosta) do odpowiedzi serwera.
- Sygnatura odcinka = para id + współrzędne + `contextSig()` (zaznaczone
  źródła, `autoJoin`, `base`). Wstawienie lub usunięcie punktu unieważnia
  tylko przeciętą parę; zmiana źródeł albo bazy — wszystkie odcinki (do
  „Źródeł trasy" były tu `attachments`/`dismissed` kluczowane parą id).
- `version` + `applyRouting(version, data)` — odpowiedź policzona dla
  starszej wersji modelu jest odrzucana (zapytania stoją w kolejce na
  blokadzie sesji po 8–20 s, więc stara odpowiedź potrafiła nadpisać nowszą
  kolejność). Zapis tylko przy `isComplete()` (brak odcinków `pending`) —
  wcześniej dało się zapisać geometrię sprzed ostatniej zmiany.

**Interakcja:**
- Jedna linia Leafleta NA ODCINEK (nie jedna scalona): `mousedown` na linii
  odcinka `i` od razu wyznacza parę `(i, i+1)` i `insertIndex = i + 1`
  (zapamiętane jako para id); na `mouseup` → `insertBetween()`. Mysz
  śledzona na `document`; ruch < 3 px to klik w linię, który niczego nie
  zmienia. `swallowGestureClick()` połyka `click` z tego gestu (capture na
  `document`, zdjęcie w `setTimeout(0)`).
- Marker = przesunięcie istniejącego punktu: `dragend` →
  `moveWaypoint(indexOf(id))`, nowy punkt nie powstaje. Pinezka JEST
  uchwytem (CSS bez `pointer-events:none`/translate; `iconAnchor [14,34]`
  stawia czubek kropli na współrzędnych). Słuchacz `click` na markerze
  zatrzymuje klik (marker ma `bubblingMouseEvents:false`).
- Klik w pustą mapę = jawnie `insertWaypoint(length)` (nowy CEL); skarb
  z dymka: „Po drodze" = `insertWaypoint(nearestSegment + 1)`, „Jedź tu
  (cel)" = na koniec. Przycisk „+ Dodaj punkt na początku trasy" usunięty
  razem ze „Źródłami trasy" (zgłoszenie: bez dodatkowych przycisków).
- `/api/planer/oblicz` dalej dostaje WSZYSTKIE punkty przy każdej zmianie
  (niezmienione odcinki wracają z cache `RoutingProxy`).

**Testy:** `php tests/run.php planner` — `tests/planner_kolejnosc_test.php`
uruchamia PRAWDZIWY `assets/js/planner/route-model.js` w Node
(`tests/planner_kolejnosc.js`, wymaga `node` w PATH; moduł wystawia
`RidemorePlannerRoute`) i sprawdza testy akceptacyjne 1–7 ze zgłoszenia na
tablicy waypointów i na `routingPayload()`, wyrównanie odcinków,
odrzucanie starych odpowiedzi, przypięcia po parach, limit 25.
**Zweryfikowane na żywo** prawdziwymi (zaufanymi) zdarzeniami myszy:
TEST 1–7 z kolejnością w zapytaniu do routingu i w odpowiedzi serwera,
klik w pinezkę / w linię bez zmian, blokada zapisu przy odcinku
`pending`, zapis + ponowne wczytanie `?id=`, baza + punkt przed START.

**„Źródła trasy" — prawa kolumna od nowa (2026-09-18; projekt stanów A–F
zatwierdzony przez usera z jedną zmianą: „jeśli zaznaczone … z automatu
łączy, a nie daje komunikat").** Zamiast „Warstw referencyjnych"
i wyszukiwarki „Ustaw jako bazę" jedna sekcja `#plannerSources`
(`views/web/pages/planner.php`), jedna zasada: **zaznaczone źródło = widać
je na mapie + planer woli jego odcinki; OSM tylko domyka brakujące
połączenia.** Szczegóły serwera: `md/controllers.md` (PlannerController),
`md/utils.md` (RouteSnap).

- **Status „Aktualna baza"** to JEDYNE miejsce, które mówi, z czego korzysta
  planer (`data-mode`): „Brak źródeł" (A), „Wszystkie zaznaczone źródła · N"
  (B/C), nazwa konkretnej trasy z kolorem i km (D/F), „Mój GPX · plik · km"
  (E). × wraca do trybu źródeł.
- **Lista** Moje przejazdy / Znane trasy / Przejazdy społeczności (domyślnie
  tylko Znane trasy) z licznikami z tych samych zbiorów, z których planer
  bierze linie (`myRidesCount`, `knownRoutesCount`). **Pierwszeństwo =
  kolejność listy** (niższe źródło przycinane do wolnych części); od dwóch
  źródeł widać o tym jedno zdanie. Skarby osobno („Punkty na mapie") — to
  punkty, nie baza: dymek „Po drodze" / „Jedź tu (cel)".
- **„Wybierz konkretną trasę"** (wyszukiwarka pod statusem: znane trasy
  i własne przejazdy naraz, pogrupowane) i **„Wgraj GPX"** ustawiają bazę,
  która wygrywa ze źródłami: nagłówek listy zmienia się na „Tylko pokaż na
  mapie — planer trzyma się bazy", a przełączanie checkboxów nie wysyła
  zapytania (kontekst bez zmiany sygnatury). Pusty planer dostaje START
  i CEL na końcach bazy; istniejące punkty zostają.
- **„Dołączaj dłuższe odcinki Ridemore"** (przełącznik, domyślnie włączony,
  aktywny tylko w trybie źródeł): włączony = kawałek linii do 1,8× (+300 m)
  drogi OSM wchodzi SAM, **bez komunikatu** (decyzja usera — dawny toast
  „dołączyć ten odcinek?" usunięty); wyłączony = najwyżej 1,2× (zwykłe
  przyciąganie). *(Od 2026-09-18 wieczorem „włączony” = warstwa routingu
  Ridemore z limitem wydłużenia całej trasy — patrz niżej; 1,8× na kawałek
  już nie działa.)* Na liście odcinków zostaje tylko podział „Ridemore X% · OSM
  Y%" (w trybie bazy „baza X%").
- **Wydajność:** dopasowanie w pikselach świata na siatce odcinków zamiast
  haversine O(n·m) — przeliczenie z 8–20 s do kilkudziesięciu ms.

**Zweryfikowane na żywo** (jednorazowy user `@local.invalid`, usunięty
z zapisaną trasą): stany A–F, wyszukiwarka, GPX, dymek skarbu (wstawienie
między punkty i na koniec), przeciąganie linii i pinezek prawdziwą myszą,
zapis + wczytanie, strona EN. `wislana-trasa` w trybie źródeł: z
przełącznikiem 224,0 km / 95% Ridemore, bez — 203,2 km / 2%; jako baza —
100%, jedno zapytanie. **Testy:** `php tests/run.php planner` —
`planner_test.php` (45: reguły `preferredRuns` na liniach syntetycznych —
pętla, tam-i-z-powrotem, szum kierunku, pierwszeństwo — tryb bazy offline,
`hitsInTiles`, IDOR) i `planner_kolejnosc_test.php` (18, doszły kontekst
i skarb „po drodze").

**Świadomie nie zrobione:** przeciąganie linii dotykiem (tylko mysz),
znane trasy bez pliku geometrii dalej są w wyszukiwarce (wybór kończy się
komunikatem błędu), skarb dopisany jako cel zachowuje typ `treasure` (brak
pinezki „C").

**Warstwa routingu Ridemore (2026-09-18, kontrakt
`tasks/active/warstwa-routingu-ridemore.md`).** Zgłoszenie usera: OSRM
wybiera boczne drogi, choć obok jest odcinek, którym ludzie naprawdę jeżdżą.
Zasada: **OSRM daje możliwość, Ridemore daje preferencję, scoring wybiera
kompromis** — OSRM zostaje silnikiem, bez własnego grafu.

- **Faza 0 — rowerowy OSRM.** Analiza wykazała, że serwer demo
  `router.project-osrm.org` ignoruje profil z adresu: `/cycling/` =
  `/driving/` = `/foot/` (Wawel → Tyniec 17,9 km przy 65 km/h; mediana
  prędkości 134 tras z cache planera 45,6 km/h), czyli planer rysował trasy
  dla aut. Teraz FOSSGIS `routing.openstreetmap.de/routed-bike` (10,2 km
  wzdłuż Wisły). Regulamin: 1 zapytanie/s → dławik w `Utils\RoutingProxy`,
  zwolnienie sesji w `/api/planer/oblicz`. Po zmianie trasa bazowa często
  SAMA jedzie po przejazdach usera (88–100% na dev).
- **Przełącznik „Dołączaj dłuższe odcinki Ridemore” WŁ.** = warstwa
  (`Utils\RidemoreRouting` + dane `Models\RidemoreCorridors`), liczona dla
  kolejnych odcinków ze stanem wybranego korytarza: elipsa START–CEL z płynnego
  budżetu wydłużenia (`min(8 km, max(4 km, 12% trasy bazowej))`) → linie
  zaznaczonych źródeł → wsparcie wzdłuż
  linii (ślady w 30 m: ile osób, ile przejazdów — max 3 na osobę) →
  RidemoreScore w LOKALNEJ skali (P90 heksów okolicy; społeczność od 2 osób;
  znana trasa ≥ 0,8, mój przejazd ≥ 0,6) → korytarze ≥ 1 km → wejście/wyjście
  → do 8 wariantów → JEDNA macierz OSRM (`table`) → koszt = długość − bonus
  (0,2 × score × długość, pełny od 3 km) + histereza za utrzymanie
  tego samego korytarza między waypointami → geometria zwycięzcy jednym
  zapytaniem (`routeLegs`). Popularność to bonus, nigdy kara; korytarz,
  którego rowerowy OSRM nie przejedzie od wejścia do wyjścia (> 1,3×), odpada.
  WYŁ. = samo przyciąganie, jak dotąd.
- **Stałe w jednym miejscu:** `RidemoreRouting::PARAMS`.
- **Pułapki znalezione na żywych danych:** znane trasy mają wierzchołki co
  kilkaset metrów (do ~900 m) — cięcie „dziur GPS > 300 m” dotyczy tylko
  nagrań; bardzo długi kawałek w elipsie (142 km Aqua Velo) rozrzedza sondy,
  więc próg przerwy rośnie z ich odstępem; szacunek po linii prostej myli się
  przy rzece bez mostu, więc z każdego korytarza idą dwie propozycje
  wejścia/wyjścia, a rozstrzyga macierz OSRM.
- **Zweryfikowane na żywo** (kontroler + prawdziwy rowerowy OSRM, dane dev):
  Wiślana trasa — OSRM 9,6 km z 4% po znanej trasie → warstwa 10,1 km
  (+0,4 km) z 92%; 18-km okno tej samej trasy: wariant +2,0 km dla 9,2 km
  korytarza przegrywa (bonus 1,5 km); Przełom Dunajca odrzucony, bo rowerowy
  OSRM potrzebuje 35 km, żeby przejechać 9,4 km korytarza; brak danych = 0
  dodatkowych zapytań; powtórka z pamięci odcinka ~7 ms. **UI w przeglądarce
  NIESPRAWDZONE** (logowanie — patrz kontrakt, Etap 1b).
- **Testy:** `tests/planner_routing_test.php` — przypadki 1–10 i 14–18
  z planu na danych syntetycznych z fałszywym OSRM (profile 11–13 to Etap 2b).
- **Etapy 2a–2c (2026-09-19).** Świeżość: przejazdy (nie znane trasy) mnożone
  przez 1,0 do 2 lat → 0,5 przy 6+ latach — ten sam korytarz trzech osób
  wygrywa, gdy jeżdżony w tym roku, a przegrywa, gdy ostatnio w 2018.
  Profile: przyciski Szosa/Gravel/MTB idą do kontekstu trasy (`profile`
  w `/api/planer/oblicz`; zmiana profilu przelicza trasę TYLKO przy
  włączonym przełączniku). Szosa odrzuca znane trasy z < 70% asfaltu, MTB
  dostaje premię za teren, progi przejezdności 1,3/1,5/1,8. Na dev tylko
  Aqua Velo ma % nawierzchni (56% asfaltu → dla szosy niezgodna); Wiślana
  trasa bez danych = neutralna dla każdego profilu. Powód wyboru: pod
  odcinkiem „+0,4 km — prowadzi sprawdzonymi odcinkami Ridemore" (dymek:
  korytarze z długością, dla społeczności „liczba osób: N" od 2 osób),
  `variant` zostaje przy odcinku w modelu JS. **Wygląd w przeglądarce
  niesprawdzony** (logowanie). Testy: 32 w `planner_routing_test.php`,
  profil + `variant` w `planner_kolejnosc_test.php`.
