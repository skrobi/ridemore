# Modele — `core/Models/*`

Warstwa dostępu do danych + logika domenowa. Prawie wszystko `public static` (operacje
na tabelach). Kilka „encji" ma publiczne właściwości + prywatny `fromRow(array): self`.
Zapytania: `Core\Database::connection()->prepare(...)`. Wykaz tabel → [`database.md`](database.md).

## Encje (właściwości + statyczne findery)

### [`Event`](../core/Models/Event.php) — centralny model (tabela `events`)
Właściwości m.in.: `id, slug, title, description, eventTypeCode, statusCode, organizerId,
min/maxParticipants, meetingPoint{Address,Lat,Lng}, coverPhotoUrl, difficultyLabel,
paceGroupLabel, regionLabel, bikeTypeLabels[], equipment[], stages[], pricing (EventPricing),
editions[] (EventEdition), organizer (Organizer), registrationTypeCode, externalRegistration{Url,Phone,Email},
submitter{Name,Email}`.
- **`save(array $input): string`** — jedyny punkt zapisu (INSERT/UPDATE budowane
  dynamicznie z kluczy `$input`; nowy klucz = auto-persist). Zwraca slug. **Nie ma `create()`.**
- Findery: `findBySlug`, `findBySlugOrFail` (404 HTML), `findBySlugOrFailJson` (404 JSON), `findRawById`.
- Listy/filtry: `upcoming`, `completed`, `forParticipant`, `mapPins`, `forDashboard`, `allIndexableForSitemap`,
  `cardsForEditions` (karty wyjazdów dla listy turnusów — Puls i „Twoi ludzie jadą"), `maxDistanceKm` (górny kraniec suwaka filtrów);
  `buildFilterClauses` (prywatna) — wspólne WHERE dla filtrów.
- Liczniki strony głównej/listy: `upcomingCountsBy{Region,EventType,Difficulty,Pace,BikeType,DurationBucket}`,
  `upcomingVerifiedOrganizerCount`, `upcomingHasSpotsCount`, `firstUpcomingWithElevationProfile`,
  `firstStageGpxUrl(eventId)` (2026-09-05, ta sama kolumna/warunek co wyżej, ale po ID — pod
  `TileController::routeOfDayMap()`, który dostaje z adresu wyłącznie klucz `ev-{id}`).
- Cykl życia (cron/moderacja): `delete`, `approveSubmission`, `cancel`, `processCompletions`,
  `expireStalePendingPayments`; prywatne `moveRsvpsToRefundOnCancel`, `sendCancellationNotices`, `sendReviewInvitesIfNeeded`.
- Maile/slug: `notifySubmissionForReview`, `notifyOrganizerAssignedByAdmin`, `generateUniqueSlug` (prywatna),
  `validatePokrecZKims` (prywatna).

### [`User`](../core/Models/User.php) (tabela `users`)
`find`, `findByEmail`, `createPending` (rejestracja mailowa), `createFromOAuth(email, name,
avatarUrl, emailVerified)`, `setPasswordAndActivate`, `updateName/Phone/Avatar`, `changePassword`,
`resetPassword`, `isActive`, `verifyPassword`, `displayName`, właściwość `isAdmin`.
Profil publiczny (Etap 3, migr. 037–038): `findBySlug`, `generatePublicSlug`,
`ensurePublicSlug(userId)`, `updateRosterVisibility(userId, visible)` (kasuje też kafle
`u-{slug}` — patrz [`features.md`](features.md)) + właściwości `publicSlug`, `rosterVisible`.
Moderacja (migr. 058): `isBlocked()` + właściwości `blockedAt`, `blockedReason`.
**Model widzi wyłącznie własne konto** — przegląd cudzych i akcje moderacyjne są
w osobnym [`UserAdmin`](../core/Models/UserAdmin.php).

### [`Organizer`](../core/Models/Organizer.php) (tabela `organizer_profiles`)
`findByUserId` (auto-tworzy przez `ensureProfile`), `findBySlug`, `updateBio/HeroPhotos/Type/
ProfileDetails/ContactInfo/Safety`, `setVerified/setActive`, `stats`, `completeness`,
`specializationChips`, `ridingProfile`, `heroCover`, `recentCoverPhotos` (+ `coverPhotosWithSource` — to samo ze źródłem `profile`/`events`, pod opis kafli karty), `totalCoverPhotoCount`,
`hasProfile`, `search`, `allForAdmin`,
`notificationEmail`, `generateUniqueSlug`, `allSlugsForSitemap`, `totalCount`, `findByWebsiteDomain`
(dopasowanie po hoście `website_url`, pod `/api/organizers/match-domain` — rozszerzenie Chrome).

### [`EventEdition`](../core/Models/EventEdition.php) — turnusy/terminy (tabela `event_editions`)
`forEvent`, `find`, `defaultForEvent`, `effectiveEndDate(durationDays)`, `replaceForEvent(...)`.
Wprowadzone w migr. 023 — model 1-event-wiele-terminów. Patrz [`features.md`](features.md) (turnusy).

### [`EventPricing`](../core/Models/EventPricing.php) (tabela `event_pricing`)
`findByEventId`, `replaceForEvent`, `cancellationDeadlineDate(startDate)`, `isCancellationDeadlinePassed`.

### [`UserBillingProfile`](../core/Models/UserBillingProfile.php) / [`OrganizerBillingProfile`](../core/Models/OrganizerBillingProfile.php)
`findByUserId`, `isComplete`, `save`. Organizer ma osobne `bankAccount`/`bankAccountHolder` (posiadacz konta ≠ podmiot z faktury).

## Zapisy, płatności, uczestnicy

### [`EventRsvp`](../core/Models/EventRsvp.php) (tabele `event_rsvps`, `event_rsvp_payments`)
Statusy per **turnus** (edition), nie per event. `join(editionId, userId)`, `reserve`, `markInterested`,
`statusForUser`, `joinedAtForUser`, `forEditionAndUser`, `confirmedParticipants(ForEdition)`,
`participantsWithStatuses`, `isConfirmedParticipant`, `existsAnyForEvent` (bramka trwałego
usunięcia eventu), `confirmedEditionCountsForUser` (**zastąpiło `confirmedEventCountForUser`**),
`publicNextRideForUser` („jedzie na" na profilu publicznym),
`behavioralPatternForUser`, `paymentsForRsvp`, `confirmPayment`, `requestCancellation`,
`confirmRefund`, `pendingForOrganizer`, `forEvent`.
`rosterForEdition(editionId)` — skład turnusu pod sekcję „Kto jedzie" (patrz [`features.md`](features.md)):
jedno zapytanie, zwraca `['confirmed' => [], 'interested' => []]`.
Statusy zapisu: `potwierdzony`, `lista_rezerwowa`, `zainteresowany`, `oczekuje_platnosci`,
`oczekuje_zwrotu`, `anulowany` (patrz użycia w `Support`/`RsvpController`).

### [`EventPermission`](../core/Models/EventPermission.php)
`canEdit(User, organizerId)` — organizator, współpracownik (`organizer_collaborators`) albo admin.

## Treści przy evencie

- [`EventStage`](../core/Models/EventStage.php) — etapy/dni trasy (`event_stages` + nawierzchnia/nocleg/posiłki): `findByEventId`, `replaceForEvent`.
- [`EventPhoto`](../core/Models/EventPhoto.php) — galeria (`event_photos`): `forEvent`, `forReview`, `forRecap`, `attach`, **`forEditions(editionIds, per)`** (2026-09-13: zdjęcia z relacji + liczba wpisów kroniki per turnus, kafle wyjazdów na profilu). **`forRegion(regionItemId, limit)`** (2026-09-16) — zdjęcia z relacji i opinii z wyjazdów w regionie, z rodzajem, wyjazdem i autorem (imię tylko przy publicznym profilu; bez szkiców, bez kont zablokowanych). Pokrewne: `TreasurePhoto::latestFor(treasureIds, limit)` — najnowsze zdjęcia z galerii WSKAZANYCH skarbów (wołający podaje wyłącznie skarby odsłonięte przez `Treasure::reveal()`).
- [`EventComment`](../core/Models/EventComment.php) — pytania/odpowiedzi (`event_comments`): `forEvent`, `create`, `findTopLevelOfEvent`, `createFaqPair` (pytanie + odpowiedź jednym ruchem, flaga `is_faq` z migr. 034), `faqPairsForEvent` (pod `JsonLd::forFaq`), `delete`, `forModeration(scopeUserId, q, onlyUnanswered, limit, eventSlug)` i `unansweredCountFor` (panel `/admin/dyskusje`; `scopeUserId = null` = admin widzi wszystko).
- [`EventReview`](../core/Models/EventReview.php) — opinie (`event_reviews`): `statsFor`, `forOrganizer`, `forEvent`, `hasReviewed`, `create`.
- [`EventRecap`](../core/Models/EventRecap.php) — relacje (`event_recaps`): `findByEventAndAuthor`, `findByEditionAndAuthor`, `findOwn`, `countForEditionAuthor`, `create`, `update`, `forEvent`, `forEdition`, `mostRecentPublic`, `extractYoutubeId`. **Od migr. 052 jedna osoba może mieć wiele wpisów w tym samym turnusie** (zdjęty `UNIQUE`, został indeks `(edition_id, author_user_id)`) — kronika przestała być podsumowaniem, a stała się dziennikiem pisanym w trakcie.
- [`EventRouteVariant`](../core/Models/EventRouteVariant.php) — warianty trasy jednodniowego eventu (`event_route_variants`, migr. 031; pętle 300/200/100 km, każda z własnym GPX, ceną i limitem): `findByEventId`, `replaceForEvent`, `confirmedCount`. Kształt trasy 1:1 jak w `EventStage`; wybór uczestnika siedzi w `event_rsvps.variant_id`.

## Messenger

- [`Message`](../core/Models/Message.php) — 1:1 (`conversations`, `messages`): `canMessage`, `findOrCreateConversation`, `findConversation`, `isParticipant`, `otherUserId`, `send`, `inboxForUser`, `threadMessages`, `markRead`, `unreadCountForUser`, `sendBulkToEventParticipants`, `organizerContactsForUser`.
- [`EventGroupConversation`](../core/Models/EventGroupConversation.php) — kanały grupowe per turnus (`event_group_conversations/messages/reads`): `canAccess`, `findByEdition`, `findOrCreate`, `postMessage`, `thread`, `members`, `markRead`, `unreadCountForUser`, `inboxRowsForUser`, `startableChannelsForUser`, `headerInfo`, `editionIdsForEvent`. Członkostwo liczone na żywo z zapisów (prywatne `membershipClause/Params`).

## Dopasowania i preferencje (Etap 2/3) — patrz [`features.md`](features.md)

- [`MatchEngine`](../core/Models/MatchEngine.php) — silnik dopasowań (duży). Publiczne wejścia:
  `forEdition`, `forDraft` (podpowiedzi w formularzu), `criticalMassProgress/Message`,
  `wideningLabel`, `runNightlyConsolidation` (cron, wysyła maile), `profileMatchesForUser`.
  Reszta to prywatne kroki oceny (geo/trasa/daty/rower/profil).
- [`UserPreference`](../core/Models/UserPreference.php) — preferencje zadeklarowane (`user_preference*`): `forUser`, `save`, `hasAnyDeclared`.
- [`DerivedPreference`](../core/Models/DerivedPreference.php) — profil **wynikający** z historii (odtwarzany w całości w cronie): `recomputeAll` + prywatne kroki.
- [`PreferenceNotifier`](../core/Models/PreferenceNotifier.php) — powiadomienia o dopasowaniach (dwa strumienie: regularny/aspiracyjny): `runRegular`, `runAspirational`, `convertRealizedAspirations`, `sweepIgnoredAspirations`. **Od Etapu 1c (2026-09-11) bez własnych cooldownów 7/30 dni** — wysyła przez `Models\Notifier`, więc odstęp wynika z budżetu bramki, a powtórce tego samego wyjazdu zapobiega klucz `ev:{id}`. Powód zmiany: bramka nie widziała tych maili, więc jej limit „2 zachęty tygodniowo" był deklaracją, nie faktem. `recommendation_log` zostaje nietknięty — to rejestr uczenia dopasowań, nie licznik wysyłek.
- [`RecommendationLog`](../core/Models/RecommendationLog.php) — dziennik pokazanych/rozstrzygniętych rekomendacji: `record`, `recordOutcome`, `purgeOld`.
- [`RecommendationDismissal`](../core/Models/RecommendationDismissal.php) — odrzucenia: `record`, `isDismissed`.

## Peleton (Etap 2) — patrz [`features.md`](features.md)

- [`EventAttendance`](../core/Models/EventAttendance.php) — (`profileTodoForUser(userId)`, 2026-09-13: flagi „do dokończenia" po zakończonych turnusach — obecność, ślad, własna relacja, emblemat wyjazdu czekający mimo śladu) „Byłem" (`event_attendance`,
  migr. 035): `declare` (upsert + przelicza peleton), `forEditionAndUser`,
  `mapByRsvpForEvent`, `attendedForEdition` (**źródło prawdy „kto pojechał"**),
  `answeredCountForEvent`, `chronicleIndex` (indeks kronik na `/relacje` — kronika
  istnieje dokładnie wtedy, gdy istnieje skład, więc ta lista JEST listą kronik),
  `firstTimersInRegionForEdition` (debiutanci w regionie — Kronika i Puls).
  Trzy stany: brak wiersza ≠ `attended=0`.
  **Dwie listy wyjazdów jednej osoby, nie jedna**: `ridesForUser()` = publiczna historia
  na profilu (tylko `attended = 1`, + trasa ZAPOWIADANA jako `gpx_url`);
  `myRidesForUser()` = ekran zarządczy „Moje przejazdy" (wszystkie potwierdzone zapisy,
  także bez odpowiedzi i bez śladu, + stan śladów i przejazdu). Filtr `attended = 1`
  w tej drugiej usunąłby dokładnie te wiersze, po które się na nią wchodzi.
- [`RiderConnection`](../core/Models/RiderConnection.php) — relacja „jeździliśmy razem"
  (`rider_connections`, migr. 036): `recomputeForEdition`, `forUser`, `countForUser`,
  `pelotonOnEdition`, `upcomingForPeloton` („Twoi ludzie jadą"), `newPairsFromEdition`,
  `namesOnEditions` (imiona peletonu na kartach dopasowań), `rebuildAll`.
  Para kanoniczna `user_a_id < user_b_id`.
- [`Pulse`](../core/Models/Pulse.php) — feed społeczności (Etap 5). **BEZ tabeli** —
  wpisy wyprowadzane w locie z obecności/zapisów/relacji/wydarzeń/skarbów.
  Publiczne wejścia: `feed(limit, ?before)` oraz — od 2026-09-12 —
  `trackGroupHashes(userId, dzień)` dla obrazka śladu (patrz niżej). **Osiem typów**: `przejazd`,
  `sklad`, `kronika`, `wezwanie`, `zapis` (pojedynczy zapis, zwijany do jednego wpisu
  na wyjazd i dobę), `skarb-nowe` (postawione punkty, jeden wpis na dobę),
  `skarb-pierwszy` (pierwsze znalezienie danego skarbu — zdarza się RAZ w jego życiu),
  **`slady-wgrane`** (2026-09-11, zgłoszenie usera: „w Pulsie nie widać, że użytkownicy
  wgrali swoje ślady"). Discovery **nie dodaje własnego typu** — wzbogaca wpis
  `przejazd` polem `newCells` (jedno zapytanie zbiorcze). Wpisy bez turnusu wchodzą
  z `editionId => 0` i są odsiewane przed zapytaniami o karty wyjazdu.

  **`slady-wgrane` (`trackUploads`) łamie dwie zasady tego modułu — świadomie.**
  (1) To **jedyny wpis wymieniający człowieka z imienia**; reszta Pulsu trzyma
  „bez imienia — kto to zrobił, jest sprawą jego profilu". Decyzja usera: wpis ma
  prowadzić DO PROFILU, więc musi powiedzieć, czyjego. Bramka `roster_visible = 1`
  **i** wymóg `public_slug` stoją w samym zapytaniu — kto ukrył się z listy
  rowerzystów, nie pojawia się tu ani z imieniem, ani wcale.
  (2) Datą wpisu jest **`created_at` (kiedy plik trafił do serwisu), nie `ride_date`** —
  wgranie archiwum sprzed dwóch lat jest zdarzeniem DZISIEJSZYM i tak ma się sortować.
  Zwijany per **osoba i doba**, i tu jest to krytyczne: import z Garmina wciąga całą
  historię naraz (kilkaset przejazdów w kwadrans), więc bez zwinięcia pierwsze
  podłączenie licznika wyczyściłoby cały Puls. Liczba odkrytych pól jedzie pod kluczem
  **`cellsNew`, NIE `newCells`** — tę drugą nazwę `feed()` nadpisuje każdemu wpisowi
  polami turnusu (zerem dla wpisu bez turnusu), więc wartość by nie dojechała.

  **`skarb-*` niosą `treasureId`** (2026-09-11) pod adres `/odkrycia?mapa=spolecznosc&skarb={id}` —
  do tej daty oba typy linkowały na mapę całego kraju, mimo że tytułem wpisu jest
  NAZWA konkretnego skarbu (zgłoszenie usera). Id oddajemy **tylko dla skarbu jawnego**
  (`reveal_level >= 2`) i tylko przy wpisie o JEDNYM skarbie: `kadrNaSkarbie()`
  w kontrolerze i tak odda współrzędne wyłącznie takiemu, a zbiorcze „8 nowych skarbów"
  nie ma czego wskazać.

  **OBRAZEK ŚLADU NA KARCIE** (2026-09-12, prośba usera). Wpis `slady-wgrane` nie ma
  wyjazdu, więc nie ma ani zdjęcia z kroniki, ani okładki organizatora — miejsce na
  obrazek zostawało puste. Teraz wpis niesie `mapKey` (`sw-{userId}-{RRRRMMDD}` —
  identyfikator GRUPY, czyli osoby i doby), `mapStamp` (`MAX(created_at)` grupy) oraz
  `mapReady`. Adres składa się z tych trzech i **unieważnia się sam**: kolejny ślad tej
  samej doby zmienia stempel, więc zmienia adres — ta sama sztuczka co data w adresie
  „Trasy dnia". **`mapReady` to liczba śladów grupy z POLICZONĄ geometrią i jest
  warunkiem koniecznym**, nie ostrożnością: przy zerze widok nie wstawia `<img>` w ogóle,
  bo endpoint odpowiedziałby 404, a przeglądarka pokazałaby ikonę zepsutego obrazka
  (w bazie dev pięć z sześciu grup nie ma geometrii — pliki GPX nie istnieją na dysku).
  **`rideId` tylko dla grupy JEDNOELEMENTOWEJ** — przy wielu śladach nie istnieje jeden
  „ten" przejazd, więc karta prowadzi na profil, a nie na wylosowany wpis.
  `trackGroupHashes(userId, dzień)` oddaje hashe grupy **rozbite na źródła**: solo idzie
  kluczem `trimmed` (§27 — obcemu wolno je pokazać wyłącznie po wycięciu okolic domu),
  ślad z wyjazdu kluczem `normal`. Metoda stoi TU, obok `trackUploads()`, bo definicja
  grupy musi w kodzie istnieć raz — inaczej karta mówiłaby o 37 śladach, a obrazek
  rysowałby 35. Rysowanie → `Controllers\TileController::trackGroupMap`.
- [`Emblem`](../core/Models/Emblem.php) — **emblematy za przejechanie CAŁEJ trasy**
  (migr. 087, 2026-09-11). Definicje (`all/find/save/delete`), przyznawanie
  (`sync(?userId)`) i odczyt (`forUser`, `forRoute`). Warunek jeden dla znanej
  trasy i dla wydarzenia: **100% pól siatki odkryć**.
  **`sync()` NIE MA ODPOWIEDNIKA `revoke()`** — emblematu nie da się stracić
  (decyzja usera), i to jest jedyny powód, dla którego `emblem_awards` w ogóle
  istnieje zamiast wyprowadzania w locie: `point_transactions` odbiera
  `TRAIL_COMPLETION`, gdy pokrycie spadnie, a emblemat jest pamiątką, nie stanem
  konta. **Punktów nie daje** — jest nagrodą RÓWNOLEGŁĄ do progów, nie kolejnym
  progiem, więc nie tworzy drugiego źródła prawdy o tym samym zdarzeniu.
  Przyznaje ZBIOROWO: jedno `INSERT ... SELECT` na trasę obsługuje i cron
  (bez `$userId`), i pojedynczy upload (z `$userId`). Wołane z
  `RiderActivity::record*` tuż po `KnownRoute::syncProgress`, z `cron.php`
  i z panelu (przycisk „Przelicz zdobycia", zapis trasy, zapis wydarzenia).
  `eventRouteCandidates()` niesie jedyne nieoczywiste rozstrzygnięcie: **etapy
  sumują się w jednego kandydata, warianty są osobnymi kandydatami** —
  wielodniówkę zalicza się w całości, a z wyścigu wystarczy jeden dystans.
- [`RiderFeed`](../core/Models/RiderFeed.php) — (`forUser(..., viewerId:)` od 2026-09-13 maskuje wpisy o Tropach/Ukrytych dla widza, który ich nie znalazł) aktywność JEDNEJ osoby pod mapą na jej
  profilu publicznym: `forUser(userId, offset)`, `countForUser`. **To nie jest Puls
  zawężony do jednej osoby**: Puls odpowiada „co się dzieje" i jego jednostką jest
  wyjazd albo grupa, tutaj jednostką jest coś, co ta osoba napisała albo wgrała.
  **Tytuł wpisu `solo` to NADANA nazwa przejazdu** (2026-09-11): do tej daty `solos()`
  nie pobierało nawet `a.name` i wpisywało „Przejazd solo" na sztywno, więc zmiana
  nazwy nigdzie nie docierała. Regułę priorytetu (własna > z licznika > generyczna)
  trzyma JEDNA metoda — `Controllers\RideController::rideName()`, publiczna właśnie po
  to, żeby dymek na mapie, strona przejazdu i ta lista nie dały trzech różnych
  odpowiedzi na to samo pytanie.

## Discovery Grid (Etap 8) — patrz [`features.md`](features.md)

- [`EditionTrack`](../core/Models/EditionTrack.php) — ślad z ODBYTEGO wyjazdu
  (`edition_tracks`, migr. 042), **jedyne źródło, z którego Discovery liczy pola**:
  `forEdition`, `forEditionAndUser`, `find`, **`effectiveFor(editionId, userId)`** (własny ślad,
  a gdy go nie ma — ślad z imprezy; to tu mieszka reguła pierwszeństwa),
  `effectiveForUser(userId)` (ta sama reguła dla WSZYSTKICH wyjazdów jednej osoby —
  linie na profilu mają pokazywać dokładnie to, z czego naliczono pola), `hasEventTrack`,
  `coverageAgainstPlanned(editionId, gpxPath)` (ile ze śladów turnusu pokrywa trasę
  zapowiadaną — `{pct, matched, total}`), `attach` / `remove` (oba przeliczają odkrycia
  natychmiast oraz unieważniają kafle), `distanceFromFile`.
- [`RiderActivity`](../core/Models/RiderActivity.php) — PRZEJAZD, jedyne miejsce
  zapisujące do `discovery_cells`. Od Etapu 8A **punkty idą do `PointLedger`**
  (RIDE/DISCOVERY/EXPLORATION, `source_id` = numer przejazdu), a kolumny `points_*`
  są tylko cache'em do czasu 8A/7. **Punktów za jazdę świadomie NIE MA w cache** —
  nie istnieje kolumna `points_ride`, a doklejenie ich do `points_discovery`
  zawyżyłoby „Discovery" w kronice. Prywatne `cappedRidePoints()` stosuje dzienny
  limit z konfiguracji (domyślnie wyłączony), liczony po DACIE PRZEJAZDU — dziesięć
  śladów wgranych jednego wieczoru, ale z dziesięciu różnych dni, to dziesięć
  uczciwych przejazdów.
  **Uwaga przy `resyncForEdition()`**: kasuje przejazdy i tworzy je na nowo, więc
  `source_id` się zmienia i klucz unikalny go nie powstrzyma — to jest przeliczenie,
  nie duplikat, i tak ma działać. Idempotencja z §6 dotyczy `syncForRsvp()`, które
  przy istniejącym przejeździe wychodzi od razu. `syncForRsvp(rsvpId, attended)` (wejście z
  `EventAttendance::declare`, idempotentne w obie strony), `resyncForEdition(editionId,
  ?userId)` (wejście z `EditionTrack`), **`recordSolo(userId, absolutePath, gpxUrl)`**
  (przejazd BEZ wydarzenia, migr. 049 — ta sama tabela, bo solo to kolejne ŹRÓDŁO
  przejazdu; idempotencja na `UNIQUE (user_id, gpx_hash)`, przycięcie okolic domu przez
  `DiscoveryGrid::trimEnds` PRZED liczeniem pól), `record(...)` (punkt wpięcia przyszłych
  źródeł, np. Stravy), `removeForRsvp`.
  **Przejazdy solo mają własny ekran (2026-08-23)**: `soloForUser(userId)` — lista do
  drugiej zakładki „Moich przejazdów" (z nazwą z Garmina, LEFT JOIN `garmin_activities`),
  `findSoloForUser(id, userId)` — odczyt zawężony do właściciela (anty-IDOR),
  **`linkSoloToEdition(activityId, userId, editionId)`** — powiązanie z turnusem: ten sam
  plik staje się WŁASNYM ŚLADEM uczestnika (`EditionTrack::attach` + `EventAttendance::declare`,
  ta sama droga co wgranie pliku na stronie wyjazdu), a przejazd solo ZNIKA, bo te same
  kilometry nie mogą policzyć się dwa razy. Kasowanie idzie przez prywatne
  `deleteActivity()`, wspólne z `removeForRsvp` — kolejność operacji (pola → punkty →
  `Discovery::refreshTotalsFor` → `KnownRoute::syncProgress`) była raz przemyślana i nie
  ma prawa istnieć w dwóch kopiach. Kody odmowy: `brak-przejazdu` (cudzy/nieistniejący),
  `brak-zapisu` (brak potwierdzonego zapisu na turnus), `nie-bylem` (świadome „nie
  dojechałem" — powiązanie zabrałoby odkrycia i nie dało nic w zamian), `brak-pliku`.
  **Klik w ślad na mapie (2026-08-27)**: `atPoint(lat, lon, zoom, userId, limit)` — odpowiedź
  na kliknięcie w warstwę „Ślady", bliźniak `KnownRoute::atPoint()`. **WYŁĄCZNIE WŁASNE
  PRZEJAZDY**: `user_id` przychodzi z sesji, nigdy z żądania — plik solo jest surowy
  i zaczyna się pod czyimś domem (§27), a warstwa z solo istnieje tylko pod prywatnym
  kluczem `me`. **TRAFIENIE LICZONE NA GEOMETRII, NIE NA POLACH** i to jedyna świadoma
  różnica względem znanych tras: pole ma ok. 500 m, a wokół domu przejazdy leżą jeden na
  drugim (zmierzone: 45 śladów w jednym kaflu z14), więc trafienie „po polach" zwracałoby
  je wszystkie naraz — bezużytecznie dokładnie tam, gdzie ma pomóc; do tego przejazd solo
  ma PRZYCIĘTE końce w polach, a rysuje się z całego pliku, więc pola nie odpowiadałyby na
  klik w widoczną linię. Kandydatów zawęża indeks `gpx_tiles` (kafel punktu + sąsiedzi, bo
  klik przy krawędzi trafia w ślad zapisany pod innym `tx/ty`), potem prywatne
  `distanceToTrack()` liczy odległość punkt–ODCINEK (nie punkt–punkt: przy rzadkich
  punktach GPS trafienie w środek długiego odcinka by przepadło) z wyjściem na pierwszym
  trafieniu w tolerancję. Zmierzone na najgorszym realnym przypadku: ok. 90 ms na
  kliknięcie. Opis do dymka składa prywatne `describeForPopup()` — te same liczby co wiersz
  listy „Przejazdy solo", plus KOLOR linii z `gpx_geometry.color_index`.
  **`atPointForRider(lat, lon, zoom, targetUserId, limit)` (2026-09-10)** — bliźniak
  `atPoint()` dla PUBLICZNEGO profilu (`/rowerzysta/{slug}`), trzecia z trzech napraw tego
  dnia po kolorze per ślad na `u-{slug}` i klikalności w feedzie (patrz `TileSource`
  i `Controllers\Support` wyżej — wszystkie trzy to to samo zgłoszenie usera: „na swoim
  profilu każdy przejazd jest kolorowany i klikalny, na czyimś powinno być tak samo").
  DWIE różnice względem `atPoint()`: (1) `$targetUserId` to WŁAŚCICIEL profilu, nie
  pytający — bramkę „czy wolno o niego pytać" liczy WOŁAJĄCY (endpoint
  `/api/discovery/rides/at?rider={slug}`, `Support::visibleRider`), nie ta metoda; (2)
  trafienie liczy się na geometrii PRZYCIĘTEJ (`gpx_tiles_trimmed`/`GpxGeometry::
  loadTrimmed()`), więc okolice domu NIE SĄ trafialne kliknięciem obcego — dokładnie tak,
  jak nie są narysowane. WYŁĄCZNIE solo (`source_code = SOURCE_SOLO`) — ślady z wyjazdów
  mają tu zasięg sprzed tej naprawy (owner-only), świadomie niezmienione, bo nikt o to nie
  prosił. Guard: `tests/przejazdy_solo_test.php`.
  **Szukanie, stronicowanie i kasowanie (2026-08-26)**: `searchSoloForUser(userId, opcje)`
  — TA SAMA lista co `soloForUser()`, ale ze `szukaj`/`strona` i kształtem wyniku 1:1
  z `KnownRoute::search()` (`items`/`total`/`strona`/`stron`/`szukaj`, `SOLO_PER_PAGE = 20`);
  `soloForUser()` zostaje NIETKNIĘTA obok niej, bo modal „Powiąż z wyjazdem" musi widzieć
  KAŻDY przejazd solo, nie tylko bieżącą stronę wyników. `deleteSoloForUser(activityId, userId)`
  — anty-IDOR przez `findSoloForUser`, kasuje przez `deleteActivity()` i DODATKOWO usuwa
  plik z dysku (`@unlink`) — w odróżnieniu od `linkSoloToEdition()`, gdzie plik PRZEJMUJE
  `edition_tracks` i musi zostać. Rejestr licznika (`device_activities`) zostaje: `ON DELETE
  SET NULL` na `rider_activity_id` (migr. 069) tylko odczepia wskaźnik, więc ta sama
  aktywność Garmina/Polara nie wraca jako „nowa" po skasowaniu.
  **Pod stronę przejazdu `/przejazd/{id}` (2026-09-03)**: `findForPage(activityId)` —
  wiersz razem z autorem, nazwą z licznika, kolorem linii i wydarzeniem (te same
  złączenia co dymek `describeForPopup`, tylko szerzej); **nie jest bramką uprawnień**,
  o to pyta się `Controllers\Support`. `regionsFor(activityId)` — województwa
  z `rider_activity_regions`. `otherRidesAlong(activityId, userId)` — inne przejazdy
  TEJ SAMEJ osoby po tym terenie; wspólny teren liczony na **polach siatki**
  (`rider_activity_cells`, próg `MIN_SHARED_CELLS = 5`), a nie na `gpx_tiles`, bo
  indeks kafli jest liczony leniwie — zmierzone przy wdrożeniu: 77 hashy w `gpx_tiles`
  na 90 śladów przejazdów, więc sekcja bywałaby pusta bez widocznego powodu.
  Komórki liczone z **pełnych plików GPX śladu rzeczywistego** — nigdy z trasy planowanej
  (`event_stages.gpx_url`) i nigdy z `event_stages.elevation_profile`.
- [`Discovery`](../core/Models/Discovery.php) — ODCZYTY. **Punkty czyta z `PointLedger`, **`recentActivity(?userId, limit)`** (2026-08-24) — oś czasu pod panel na mapie: przejazdy ORAZ znalezione skarby (te grupowane po dniach; na mapie społeczności ukryty skarb nie zdradza nazwy ani dokładnej pozycji): `null` = społeczność (z autorem), id = mapa osobista. Do każdego wiersza dokłada PROSTOKĄT na mapie policzony z pól przejazdu, jednym zapytaniem na całą listę (`rider_activity_cells` → `DiscoveryGrid::cellCenter`), żeby kliknięcie miało dokąd prowadzić.
  nie z przejazdów** (Etap 8A/7 — kolumny `points_*` usunięte migr. 045).
  `summaryForUser` zwraca `pointsTotal` + `pointsBySource` (całe rozbicie, żeby widok
  nie wymagał zmiany przy każdym nowym źródle punktów), `rideForRsvp`/`rideForUserOnEdition`
  dokładają `points` i `pointsBySource` przez prywatne `withPoints()`,
  `recentRidesForUser` dosumowuje `points_total` podzapytaniem. Dalej:
  `cellsForUser`, `recentRidesForUser`, `rideForRsvp`, `communityCells(bounds, res, ?userId)`
  (mapa osobista i wspólna tym samym kodem), `communityStats`, `newCellsForEdition(s)`
  (Kronika/Puls), `rideCellsForEdition` (pola JEDNEGO przejazdu — mapa kroniki),
  `statsForEditions` (podsumowania dla listy turnusów), `regionsForUser`
  (`{visited, total}` — od migr. 070 „odkryty region" = masz w nim heks;
  wcześniej liczyły się regiony wydarzeń z potwierdzoną obecnością),
  **`regionProgress(?userId)`** (migr. 070/071 — pokrycie per województwo:
  mianownik z materializowanej `region_cell_counts`, społeczność i osoba
  liczone od strony MAŁYCH zbiorów; kierunek złączeń ma znaczenie przy
  1,5 mln heksów, zmierzone 1,9 s → 0,1 s) i **`regionPotentials(userId,
  limit)`** — ranking „gdzie jest co wziąć": nieodkryte pola × stawka
  + punkty skarbów do wzięcia + trasy; score to HEURYSTYKA RANKINGU, nie
  obietnica punktów (premii EXPLORATION nie wolno obiecywać), widok cytuje
  składniki,
  **SCOPED PO KRAJU (2026-09-05)** — `/admin/regiony-mapa` od 2026-09-01
  pozwala dorysować region BEZ rodzica (kraj, który jest jednocześnie jedynym
  swoim regionem — Czechy, Słowacja), więc liście słownika `region` przestały
  być JEDNYM zbiorem. Test na żywo w dev (fikcyjna „Słowacja" z kilkoma
  heksami): bez poprawki `regionsForUser` liczyło „X z 17" zamiast „X z 16",
  a `regionProgress`'s `grand` (za tą liczbą stoi „Polska odkryta %" na
  /odkrycia) dolewał zagraniczne heksy do mianownika Polski. Poprawka:
  `regionsForUser(userId, countryCode='polska')` i
  `regionProgress(?userId, homeCountryCode='polska')` liczą `visited/total`
  i `grand` WYŁĄCZNIE z liści, których najwyższy przodek ma ten kod (domyślna
  wartość = zero różnicy, dopóki w bazie jest tylko Polska). `regionProgress`
  dokłada `countryId/countryCode/countryName` do każdego wiersza `regions`
  oraz NOWY klucz `countries` (jedna suma na kraj, kraj domowy zawsze
  pierwszy) — do grupowania widoku bez drugiego zapytania. `regionPotentials`
  filtruje `countryCode === 'polska'`, bo karta „Następny cel" mówi o Polsce
  wprost w treści. Widok: `discovery.php` → „Twoje regiony"
  (`views-and-frontend.md`).
  `recentDiscoverers` (**tylko osoby widoczne na listach** —
  ozdoba nie może łamać czyjejś decyzji o ukryciu się),
  `sharedCellCounts` (Peleton), `refreshTotalsFor`, `rebuildTotals`,
  **`boundsFor(?userId)`** (prostokąt odkrytych pól — kadr STARTOWY mapy; `null` =
  wspólna. Jedno zapytanie agregujące po skrajnych współrzędnych osiowych, bez
  wyciągania pól do PHP-a; `discovery_cells` nie ma kolumn `q`/`r`, więc rozpakowuje je
  `DiscoveryGrid::sqlQ/sqlR`).
  **ODPORNOŚĆ NA ODLEGŁY WYJAZD (2026-08-27)** — zgłoszenie usera: „centruje poza
  zakresem moich osiągnięć lub społeczności". Znalezione na żywo: rowerzysta z 89
  przejazdami wokół Mielca miał JEDEN wyjazd na Teneryfę (4500 km dalej) — naiwny
  MIN/MAX po WSZYSTKICH polach dał prostokąt Ocean Atlantycki–Polska, a jego środek
  (Barcelona) nie leżał blisko żadnego z dwóch skupisk. **To NIE był błąd
  arytmetyki** (`boundsFromAxialExtremes` liczy ściśle, nie w przybliżeniu —
  `s = 2q + r` zależy WYŁĄCZNIE od `s`, `y` WYŁĄCZNIE od `r`), tylko naiwności
  założenia „jeden zwarty obszar". Prosta łatka „bbox z geometrii kafli zamiast
  z pól odkryć" NIE POMOGŁABY: ślad z Teneryfy ma DOKŁADNIE ten sam skrajny punkt
  co jego pola — to nie jest problem ŹRÓDŁA danych, tylko problem AGREGACJI.
  Naprawa: gdy naiwny prostokąt przekracza `CLUSTER_TRIGGER_KM` (1000 km),
  DRUGIE zapytanie dzieli pola na kubełki `CLUSTER_BUCKET_KM` (150 km) i bierze
  NAJWIĘKSZY (plus sąsiadów o 1 kubełek, żeby nie uciąć skupiska na granicy) —
  mapa startuje tam, gdzie faktycznie jest większość odkryć. Drugie zapytanie
  płaci WYŁĄCZNIE ten, kto już ma odległy wyjazd; typowy zwarty zasięg kończy się
  na pierwszym zapytaniu jak dotychczas (zero regresji kosztu dla typowego
  przypadku). Wspólny helper `axialExtremes()` — ten sam SELECT dla naiwnego
  i dla zawężonego do kubełka wywołania.
  **Żadna metoda nie zwraca listy osób na polu** — mapa mówi ILE, nigdy KTO (§27).
- [`KnownRoute`](../core/Models/KnownRoute.php) — znane trasy jako DANE. **`ridersProgress()` (2026-09-12)** oddaje `['started','completed']` dla jednej trasy jednym zapytaniem (ta sama agregacja, dwa progi) — pod pasek faktów na ekranie edycji: „czy ktokolwiek tą trasą jeździ" to pierwsze pytanie przed wyłączeniem albo usunięciem, a dotąd nie dało się na nie odpowiedzieć bez wejścia do bazy. `cells_total` bierze z wiersza trasy, nie z `COUNT(known_route_cells)` — inaczej trasa w trakcie przeliczania pokazywałaby „ukończyło" większe niż „zaczęło". **`gapsNearbyForUser()` (Etap 1a, 2026-09-11)** oddaje brakujące pola tras w prostokącie — tylko z tras, które ten człowiek już zaczął — pod alerty w apce. `syncProgress()` wie teraz, czy TEN przejazd ruszył trasę (`touched_now`), więc ekran wyniku umie powiedzieć „zbliżyłeś się\" zamiast milczeć, gdy postęp nie przebił progu.
  **Pod listę panelu**: `search(opcje)` — szukanie po nazwie/slugu/nazwie regionu,
  filtr, sortowanie z ZAMKNIĘTEJ listy (wartość idzie z adresu) i stronicowanie po
  `PER_PAGE`; kształt wyniku identyczny jak `UserAdmin::search`, bo widok stronicuje
  się tym samym kawałkiem kodu. `counters()` — kafle nad listą, w tym „do przeliczenia",
  czyli ile tras NIE RYSUJE SIĘ na mapie. Reszta:
  `onActivity(activityId)` (2026-09-03) — **które znane trasy objął JEDEN przejazd**
  (przecięcie `known_route_cells` z `rider_activity_cells`, procent liczony WZGLĘDEM
  TRASY) pod sekcję „Znane trasy na tym przejeździe"; nie mylić z postępem osoby,
  który zbiera się ze wszystkich przejazdów i to on daje punkty.
  `all`, `find`, `findBySlug` (strona `/trasy/{slug}`), `progressForUser` (postęp
  **wyprowadzany**, bez tabeli), `progressForUserOnRoute` (ta sama arytmetyka dla
  jednego szlaku), `geometryInBounds(bounds, maxRoutes)` (które trasy wchodzą
  w kadr, w kolejności `sort_order`, migr. 048; **kadr filtruje po
  `known_route_cells.cell_q/cell_r`, migr. 061**. Od 2026-08-20 NIE ZASILA JUŻ MAPY —
  warstwa „Trasy" rysuje się kaflami (klucz `kr`), bo geometria ze środków pól była
  zygzakiem. Metoda zostaje jako opis reguły „co jest w kadrze" i pod testy — filtr przez `discovery_cell_totals`
  ukrywał każdą trasę, której nikt jeszcze nie przejechał), **`linePoints(route)` (Route Planner,
  Etap 1, 2026-09-17)** — REALNA geometria jednej trasy jako `[lat,lon]` z pliku GPX
  (`gpx_url` → `TileSource::absolutePath` → `GpxGeometry::ensure/load` → `TileGrid::toLatLon`),
  świadomie NIE środki pól jak `geometryInBounds()` — dziś pod bazę planowania „Wybierz konkretną
  trasę" (`PlannerController::sourceGeometry`), **`activeGeometryHashes()`** / **`activeGeometryInfo()`** (ta druga + % nawierzchni, 2026-09-19) (2026-09-18 —
  hash geometrii => nazwa dla WSZYSTKICH aktywnych tras z plikiem: ten sam zbiór co warstwa kafli
  `kr`, źródło „Znane trasy" w planerze), `finishersFor` (kto ukończył),
  **`syncProgress(userId, ?activityId, ?rideDate)`** (Etap 8A — zastąpiła
  `awardProgress`), `createFromGpx` (bez parametru regionu od migr. 074 —
  prywatne `syncRegions(routeId)` liczy go PO `writeCells()` z
  `known_route_cells ⋈ region_cells` i zapisuje do `known_route_regions`;
  `region_item_id` zniknęło z tabeli w migr. 075, `region_label`/`region_code`
  w wynikach `find`/`search`/`all` to teraz `GROUP_CONCAT` z tej tabeli, nie
  JOIN na skalarną kolumnę) (+ prywatne `awardBacklog` dla tych, którzy
  przejechali trasę przed jej dodaniem), `update(id, fields)` (dane opisowe, zdjęcie
  i bonusy — whitelist `EDITABLE`, **bez `slug`, `is_active` i pól z GPX-a**),
  `replaceGpx(id, path, url)` (podmiana przebiegu: id i slug zostają, pola/dystans
  przeliczone, punkty dotkniętych osób doprowadzone do zgodności w OBIE strony;
  prywatne `invalidateTiles()` kasuje kafle `kr`/`kr-{id}` PLUS — od Etapu 3 warstw
  mapy, 2026-08-26 — `kd-{slug}` każdej osoby, która ma choć jedno odkryte pole na
  tej trasie: geometria/`cells_total` mogły się zmienić, więc jej status „ukończona"
  też mógł. Świadomie NIE reaguje na NOWĄ JAZDĘ, która dopiero doprowadza trasę do
  100% — ten sam gap co przy `TileCache::invalidateForEdition`, patrz `TileSource`),
  `atPoint(lat, lon, zoom, ?viewerId, limit, ?onlyRouteId)` (co jest pod kliknięciem w mapę
  — trafienie po polach trasy, z tolerancją skalowaną powiększeniem; kafel jest obrazkiem,
  więc klik liczy serwer. **`onlyRouteId` (2026-09-10)** zawęża odpowiedź do jednej trasy
  — pod stronę `/trasy/{slug}`, gdzie warstwa „Znane trasy" rysuje wyłącznie tę jedną;
  filtr siedzi w ZAPYTANIU, bo odsianie po fakcie oddawałoby pustkę tam, gdzie trasa
  wypadła z `LIMIT`-u zajętego przez sąsiadów),
  **`nearby(routeId, ?viewerId, limit = 6)` (2026-09-10)** — INNE TRASY W OKOLICY TEJ
  JEDNEJ, pod sekcję „W okolicy tej trasy" na `/trasy/{slug}`. Sąsiedztwo liczone tą samą
  definicją co przy dobieraniu kolorów (`COLOR_NEIGHBOUR_RADIUS`, indeks `idx_krc_bbox`),
  `shared_cells` (te SAME pola = krzyżują się) sortuje przed `near_cells` i daje widokowi
  gotowe `crosses`. Kształt wiersza taki sam jak `progressForUser()`, bo kartę rysuje
  ten sam partial `views/web/partials/trail-card.php`,
  `unorderedCounts()` (ile pól per trasa czeka na `sort_order` — ostrzeżenie w panelu),
  **`colorOf(index)` / `assignColor(id)` / `assignAllColors(?log)` + paleta `COLORS`**
  (kolor szlaku na mapie, migr. 064 — zgłoszenie usera „wszystkie są zielone i nie
  wiadomo, który jest który"). Kolor jest WŁASNOŚCIĄ TRASY, jednakową na każdej mapie
  serwisu i w każdym miejscu, gdzie trasa ma nazwę; `colorOf(null)` daje zieleń brandową,
  więc trasa bez przydziału wygląda jak przed migracją. `assignColor` bierze pierwszy
  kolor palety, którego nie mają szlaki leżące w promieniu `COLOR_NEIGHBOUR_RADIUS`
  (2 pola ≈ 1 km, liczone po `known_route_cells.cell_q/cell_r`), przy remisie najrzadszy
  w katalogu — i **nie rusza kolorów sąsiadów**: kafle unieważniają się po ŚLADZIE
  zmienianej trasy, więc przemalowanie sąsiada zostawiłoby go na mapie w dwóch kolorach.
  `assignAllColors` (backfill z `run_migrations.php`) idzie od tras o największej liczbie
  sąsiadów, pomija te, które kolor już mają, i unieważnia kafle każdej pokolorowanej,
  `backfillElevation(?log)` (dopisanie przewyższeń trasom sprzed migr. 063 ORAZ profili
  sprzed migr. 065 — jedno parsowanie pliku daje obie wartości, więc to jedna metoda,
  wołana z `run_migrations.php` dwa razy: po 063 i po 065. **Sprawdza `SHOW COLUMNS`,
  czy kolumna profilu istnieje** — po 063 jeszcze jej nie ma, a wywalony backfill
  przewyższeń zostawiłby trasy bez żadnej z tych wartości),
  `elevationProfile(?json)` (profil z bazy → kształt dla widoku: próbki + **szczyty
  liczone na żądanie** `Gpx::detectPeaks`, tak samo jak w `StageResource`; null dla
  trasy bez profilu, więc strona po prostu nie rysuje wykresu), `boundsFor(id)` (prostokąt trasy ze środków pól — kadr mapy na `/trasy/{slug}`
  ustawiany Z SERWERA; `GpxGeometry::boundsFor` się nie nadaje, bo zna tylko ślady
  zindeksowane w `gpx_geometry`), `setActive`, `delete`, `cellIds`.
  Prywatne: `writeCells` (JEDYNE miejsce wstawiające pola trasy — `sort_order`
  i `cell_q/cell_r` naraz) i `ridersTouchingRoute` (kto ma pola tej trasy i przez
  który przejazd; wspólne dla `awardBacklog` i `replaceGpx`).
  Usunięte 2026-08-19: `setCoverPhoto` — zdjęcie idzie przez `update()` jak reszta pól.
  - `syncProgress` **doprowadza rejestr do zgodności z faktycznym pokryciem**:
    dopisuje progi osiągnięte i **zdejmuje te, które przestały być pokryte**
    (usunięty ślad zabiera pola, a bonus za trasę, której się już nie pokrywa,
    byłby wynikiem bez pokrycia w faktach). Punkty przy przejeździe kasuje
    kaskada, ale progi trasy nie wiszą na jednym przejeździe — ten sam próg mógł
    zostać osiągnięty innym wyjazdem, więc muszą być zdejmowane jawnie.
  - **Nieidempotentność zniknęła**: poprzednia wersja porównywała procent sprzed
    i po przejeździe, żeby nie zapłacić drugi raz. Od Etapu 8A pilnuje tego klucz
    unikalny w `point_transactions`, więc wystarczy policzyć stan OBECNY i przyznać
    wszystko, co się należy — powtórzenie jest bezkosztowe z definicji.
  - Ukończenie to **osobne źródło** (`TRAIL_COMPLETION`), nie kolejny próg — brief
    je rozdziela, a osobna nazwa pozwala pokazać „Ukończona trasa" zamiast
    „Znana trasa 100%".
- [`PointLedger`](../core/Models/PointLedger.php) — **jedyne wejście do punktów**
  (Etap 8A, `point_transactions`, migr. 043). `award(userId, source, sourceId, points,
  ?activityId, ?rideDate, ?description): bool` (INSERT IGNORE — **idempotencja jest
  kluczem unikalnym, nie warunkiem w kodzie**; zwraca, czy wpis faktycznie powstał),
  `totalForUser`, `breakdownForUser` (za co konkretnie), `pointsOnDate`/`pointsSince`
  (dzienny limit za jazdę i podsumowania okresowe), `recentForUser` (historia,
  sortowana po `ride_date` — przejazd sprzed miesiąca policzony wczoraj należy do
  tamtego miesiąca), `forActivity`/`sumForActivity`, `revoke` (zdejmowanie progów
  trasy, które przestały być pokryte — punkty przy przejeździe kasuje CASCADE),
  `label` (podpisy źródeł w jednym miejscu, żeby trzy widoki nie trzymały trzech
  tłumaczeń). **Zero punktów nie tworzy wpisu** — „+0 Przejazd" w historii wygląda
  jak usterka. Wartości nie zna: ile co jest warte, decyduje `DiscoveryScoring`.
- [`DiscoveryScoring`](../core/Models/DiscoveryScoring.php) — jedyne miejsce decydujące
  „ile punktów". Czyta [`core/discovery.php`](../core/discovery.php) **przez
  `ScoringSettings::applyTo()`** (migr. 053): plik daje wartości domyślne i uzasadnienia,
  tabela nadpisuje to, co admin zmienił w panelu. `config()` (scalona konfiguracja,
  zapamiętana per żądanie), `forgetConfig()` (po zapisie stawki), `defaults()` (sam plik —
  panel pokazuje „było / jest"), **`forRide(distanceKm, elevationM)`** (Etap 8A — dystans
  + przewyższenie; zaokrąglenie na SAMYM KOŃCU, żeby ułamki z przewyższenia nie przepadały
  przy każdym przejeździe), `dailyRideCap()` (domyślnie `null` = wyłączony),
  `forDiscoveries`, `trailAwards(pct, totalPoints)` (progi znanej trasy — DZIELĄ
  `totalPoints` MIĘDZY SIEBIE RÓWNO, decyzja usera 2026-09-03; `totalPoints=null` = wartość
  domyślna), `splitEqually` (matematyka podziału, reszta z zaokrąglenia na ostatniej
  części), `trailThresholds` (progi %, rozstawione równo co `100/threshold_count`),
  `trailValueFor(distanceKm, totalOverride)` (JEDYNE miejsce decydujące, ile jest warta
  KONKRETNA trasa: nadpisanie > sugestia z długości [`points_per_km`] > wartość domyślna —
  bez tego 1000 km i 50 km płaciłyby tyle samo), `legacyRouteOverrideTotal` (scala stare,
  osobne kolumny `known_routes.bonus_points`/`completion_bonus` sprzed tej zmiany w jeden
  total), `homeTrimRadiusM` (prywatność przejazdu solo), `maxNewCellsPerActivity`.
- [`ScoringSettings`](../core/Models/ScoringSettings.php) — nadpisania punktacji z bazy
  (`scoring_settings`, migr. 053). **Warstwa NA pliku, nie zamiast niego**: pusta tabela
  znaczy „jak przed migracją", a skasowanie wiersza to powrót do domyślnej wartości bez
  potrzeby jej znajomości. Klucz jest ŚCIEŻKĄ w konfiguracji (`discovery.points_per_new_cell`,
  `trails.threshold_count`). `all`, `applyTo(config)`, `set`, `defaultFor`, `lastChange`.
  Stała `EDITABLE` to **biała lista** tego, co wolno stroić z panelu (promień prywatności
  domu i limity ochronne zapytań świadomie poza nią) razem z etykietami dla formularza.
  Kalibracja RIDE: 100 km płaskie = 500 pkt, górskie 100 km / 2 000 m = 700.
  **Proporcja, którą trzeba sprawdzić przy każdej zmianie stawek**: znana pętla 60 km
  daje 380 pkt, ta sama trasa w nowym terenie ok. 1 580 — odkrywanie jest ~4× cenniejsze
  od jazdy po swoim, przy zachowaniu zasady, że codzienna runda nadal coś daje.

## Skarby (Etap 8D) — patrz [`features.md`](features.md)

- [`Treasure`](../core/Models/Treasure.php) — **profil rowerzysty (2026-09-13): `showcaseForUser(userId, viewerId, limit, offset, rarity, categoryId)` + `countShowcaseForUser` + `showcaseRarityCounts` — znaleziska od najrzadszych ze zdjęciem, stronicowane i filtrowane (filtr wyklucza zamaskowane dla obcego), MASKOWANE dla widza, który sam nie znalazł Tropu/Ukrytego (`masked`, bez nazwy/zdjęcia/rzadkości); `rarityTotals()`; `openTrailsForUser(userId)` — nieznalezione Tropy/Ukryte w polach, które user odkrył.** Punkt w terenie do znalezienia **`listOnRoute(routeId, viewerId)`** (2026-08-23) — lista skarbów NA TRASIE pod sekcję „Co zobaczysz po drodze", w kolejności wzdłuż śladu (`known_route_cells.sort_order`); ujawnienie przez ten sam `reveal()` co mapa, a skarby, których widz nie może zobaczyć, wychodzą wyłącznie jako liczba `hidden` (obok istniejącego `onRoute()`, który zostaje agregatem).
  **`onActivity(activityId, viewerId)` / `listOnActivity(...)` (2026-09-03)** — te same
  dwa pytania, ale o skarby na polach JEDNEGO PRZEJAZDU (`rider_activity_cells` zamiast
  `known_route_cells`) pod stronę `/przejazd/{id}`. Nie są kopiami: obie pary chodzą
  wspólnym ciałem (`onCells`/`listOnCells`), któremu podaje się tabelę pól — jedyna
  różnica między nimi. Kolejność wzdłuż śladu ma tylko katalog tras (`sort_order`),
  przejazd idzie po id. Dla solo pola są już PRZYCIĘTE (§27), więc skarby spod domu
  nie trafiają na listę nawet właścicielowi.
  (`treasures`, `treasure_finds`, `treasure_confirmations`; migr. 054–060).
  **Druga, przeciwstawna czynność wobec Discovery**: pole odkryć ma ok. 500 m i zalicza
  się samo ze śladu (nagradza PRZEJECHANIE terenu), skarb wymaga zatrzymania się —
  stąd osobna tabela, współrzędne z dokładnością do metrów i osobne źródło punktów.
  Kolumna `cell_id` służy wyłącznie do rysowania ikonki i do zawężania kandydatów
  przy zaliczaniu ze śladu.
  - **Trzy drogi zaliczenia, jedna reguła**: `claim(code, userId, lat, lon, 'QR')`
    (ekran spod naklejki), `claimNearby` (`GPS`, z mapy), `claimAlongTrack(userId,
    cellIds, points)` (`GPX`, wołane z `RiderActivity` po wgraniu śladu). Znalezienie
    jest jedno na osobę i skarb (`UNIQUE (treasure_id, user_id)`), więc metody się
    NIE sumują; punkty idą zwykłą drogą przez `PointLedger` (`TREASURE_FOUND`).
  - **`onRoute(routeId, ?viewerId)`** (migr. 065, strona znanej trasy): ile skarbów
    leży NA POLACH trasy, ile niosą punktów (`points` — wszystkie, `pointsLeft` — tylko te, których pytający
    jeszcze nie ma) i ile z nich znalazł. „Na trasie" =
    ten sam warunek `cell_id`, którym posługuje się `claimAlongTrack`, więc liczba
    odpowiada na pytanie „ile z nich podniosę, jeśli tę trasę przejadę". `found` to
    **null dla gościa**, nie zero — „0 znalezionych" nie jest informacją o kimś, kto
    nie ma konta (ta sama zasada co przy postępie trasy).
  - **Odczyty na mapę**: `inBounds` (od 2026-08-20 niesie też `description`, `rarity`,
    `claim_radius_m`, `region_label` i `finders` — pod rozbudowany dymek; `reveal()`
    zdejmuje je punktom ukrytym razem z nazwą i zdjęciem) (jedyna bramka pozycji — skarb o `reveal_level`
    0/1 dostaje środek pola zamiast współrzędnych, a kod z naklejki nie wychodzi
    w odpowiedzi nigdy; parametr `$mine` = `'only'`/`'not'` zawęża do skarbów, które
    pytający ma albo których nie ma — **własny placeholder `:uid_mine`**, bo `:uid`
    jest już zajęte, a EMULATE_PREPARES jest wyłączone. **`$mineScope = 'viewer'|
    'community'`** (Etap 3 warstw mapy, 2026-08-26) — `'community'` liczy `$mine`
    względem ISTNIENIA znalazcy, nie tożsamości pytającego (`EXISTS (…) WHERE
    treasure_id = s.id`, bez `:uid_mine` w ogóle — dlatego jego bindowanie w
    `inBounds()` jest WARUNKOWE, `str_contains($sql, ':uid_mine')`, a nie
    `$mineSql !== ''`). Mapa społeczności ma wtedy „odkryte"/„nieodkryte" znaczyć
    „przez KOGOKOLWIEK" — §27 nie stoi na przeszkodzie, to samo pytanie zadaje już
    publiczny `finders`), `clustersInBounds` (skupiska
    przy oddaleniu — zwraca `['clusters' => ..., 'singles' => ...]`, bo **pęczek zaczyna
    się od dwóch**: pole z jednym skarbem wraca jako zwykły skarb, z prawdziwą pozycją
    zamiast środka pola), `findByCode`,
    `find`, `all`, `findersCount`.
  - **Panel (2026-08-20)**: `search(opcje)` + `counters()` + `PER_PAGE` — lista ze
    szukaniem (nazwa, KOD z naklejki, region, kategoria), filtrami i stronami; osobno od
    `all()`, bo panel ma dorosnąć do tysiąca punktów, a `all()` ładuje całą tabelę.
    `inBoundsAdmin(bounds)` — skarby w KADRZE mapy panelu z PEŁNĄ pozycją: `inBounds()`
    odpowiada graczowi („co wolno ci zobaczyć") i przycina ukryte do środka pola, panel
    odpowiada autorowi („gdzie to naprawdę stoi") i musi pokazać prawdziwy punkt, bo
    inaczej przeciągnięcie pinezki przesuwałoby skarb tam, gdzie go nie ma.
    **`deleteIfUnfound(id)`** — kasuje WYŁĄCZNIE punkt, którego nikt nie znalazł (ta sama
    zasada co `UserAdmin::deleteIfEmpty`): znalezienie zapłaciło punktami, a
    `point_transactions` jest rejestrem niezmiennym, więc skasowanie znalezionego skarbu
    zabrałoby ludziom punkty z historii. Znaleziony schodzi ze sceny statusem `RETIRED`.
  - **Zgłoszenia społeczności**: `save` (admin i zgłaszający tym samym wejściem),
    `pending`, `proposedBy`, `confirm`/`confirmNearby` (głos wymaga bycia w promieniu),
    `confirmationCount`, `confirmationsNeeded`, `setStatus` (decyzja admina omija
    głosowanie), `rotateCode`, `newCode`, `guessRegion`.
  - **Podsumowania**: `statsForUser`, `statsCommunity`, `collectionProgress`,
    `collectionsForUser`, `waitingList`, `mostFound`.
    **`waitingList()` oddaje `id`, ale NIGDY lat/lon** (2026-08-20): panel obok mapy jest
    klikalny, a pozycję strona dobiera osobno przez `/api/treasures/{id}` — czyli przez tę
    samą bramkę ujawnienia co warstwa mapy. Współrzędne w tej odpowiedzi stałyby w źródle
    strony i zdradzały dokładne miejsce skarbów UKRYTYCH.
  - Wartości domyślne: `defaultPoints`, `defaultPointsFor(rarity)`, `defaultRadius` —
    wszystkie przez `DiscoveryScoring`/`ScoringSettings`, nie wpisane w model.

## Kafle map (migr. 051) — patrz [`features.md`](features.md)

- [`GpxGeometry`](../core/Models/GpxGeometry.php) — geometria pliku GPX w postaci,
  której potrzebuje renderer (`gpx_geometry`, `gpx_tiles`). Jedno parsowanie na plik,
  klucz to **hash ZAWARTOŚCI** (ta sama decyzja co w `RoutePreview`): `ensure`, `load`,
  `inTile`, `tilesFor`, `boundsFor` (kadr mapy liczony po stronie serwera, bez pobierania
  historii plików), `has`.
  **`boundsFor`/`boundsForTrimmed` mają JEDNO ciało (`boundsIn`, 2026-09-12)** — ta sama
  reguła kadrowania, parametrem jest TABELA. Do tej daty klastrowanie („odetnij odległe
  skupisko") stało wyłącznie w gałęzi zwykłej, bo `boundsForTrimmed()` miało jednego
  wołającego i JEDEN ślad na wywołanie; obrazek śladu na karcie Pulsu podaje mu całą
  dobę jednej osoby, czyli dokładnie ten przypadek, dla którego klastrowanie powstało.
  **Próg i kubełek też są parametrem** — stałe klasy (1000/150 km) są skrojone pod mapę
  profilu na pełnym ekranie, a karta Pulsu ma 272 px wysokości i podaje własne, ciaśniejsze
  (`TileController::SLAD_CLUSTER_*`). Dla starego wołającego nic się nie zmienia:
  pojedynczy ślad nie ma jak przekroczyć progu.
  **`fileHash()` (2026-09-02)** — suma kontrolna pliku liczona RAZ NA WERSJĘ PLIKU
  (pamięć w `storage/gpx-hash/`, ważność po `mtime`+rozmiarze; klucz pamięci procesu
  też zawiera stempel). Używają jej `ensure()`/`ensureTrimmed()`. Powód: `TileSource::tracks()`
  woła `ensure()` dla KAŻDEGO pliku śladu, więc każdy kafel liczył `hash_file()` na kilkudziesięciu
  plikach po kilka MB — **0,6 s na kafel, tyle samo dla pustego kafla nad Bałtykiem**. Po zmianie
  kafel `slady/all` na z17: **0,075 s**.
  **Zapis `ensure()`/`storeTrimmed()` jest TRANSAKCYJNY (2026-09-07)** — zgłoszenie
  usera: znany szlak „urywa się" na części kafli, zależnie od zoomu. Przyczyna: nowy
  ślad zapisywał się DWOMA osobnymi, autocommitującymi się krokami — wiersz
  `gpx_geometry`, potem (dla długiego szlaku kilkoma zapytaniami) komplet `gpx_tiles`.
  Naprawa zwolnienia blokady sesji wyżej sprawiła, że kafle jednego kadru NAPRAWDĘ
  jadą równolegle, więc równoległe żądanie o INNY kafel TEGO SAMEGO nowego śladu
  potrafiło trafić w szczelinę między tymi krokami: widziało już `gpx_geometry` (więc
  `ensure()` brało „krótką ścieżkę"), ale `gpx_tiles` nie miało jeszcze kompletu.
  `inTile()` nie znajdował śladu na TYM kaflu, renderer rysował go jako pusty, a to
  lądowało na dysku z `immutable` — dziura w linii NA ZAWSZE, tylko na kaflach, które
  trafiły w wyścig (stąd „pod którymś zoomem", nie wszędzie). Oba kroki idą dziś
  w jednej transakcji (`$db->inTransaction()` jako guard — testy same są w jednej,
  patrz `tests/run.php`), więc żaden czytelnik nie zobaczy `gpx_geometry` przed
  kompletem `gpx_tiles`. Ten sam wzorzec w `storeTrimmed()` dla bliźniaczej pary
  `gpx_geometry_trimmed`/`gpx_tiles_trimmed`. Guard: `tests/wydajnosc_mapy_test.php`.
  **`repairTileIndex()`/`repairTrimmedTileIndex()` (2026-09-07)** — naprawa STARYCH
  szkód po tym samym wyścigu: transakcja wyżej zapobiega tylko NOWYM przypadkom,
  a ślad, który padł w wyścig ZANIM ona powstała, ma już wiersz w `gpx_geometry`
  i `ensure()` bierze dla niego „krótką ścieżkę" na zawsze. Zgłoszenie z produkcji:
  nowy użytkownik zaimportował naraz wiele przejazdów, jeden ślad dalej „urywał się"
  mimo wdrożonej transakcji. NIE CZYTA PLIKU GPX — liczy `tileSet()` z punktów już
  zapisanych w `gpx_geometry.points` i dopisuje wyłącznie brakujące wiersze
  `gpx_tiles` (`INSERT IGNORE`, idempotentne), więc naprawia też przejazdy solo,
  których właściciela nie da się poprosić o ponowne wgranie. Wystawione jako
  `php tiles.php repair` i przycisk w `/admin/kafle` (`TilesController::repairIndex`,
  kasuje potem kafle warstwy `slady`, tak jak `colors()`). Guard:
  `tests/wydajnosc_mapy_test.php` (usuwa plik PRZED naprawą, żeby dowieść, że nie
  jest potrzebny). Osobno: `tests/diagnostyka_pustych_kafli_test.php` sprawdza
  INNĄ możliwą przyczynę „przezroczystego kafla" — martwy `gpx_url` (plik w ogóle
  zniknął z dysku) albo trasę POZA grupami `TileSource::tracks()` (np. `is_active=0`)
  — których to `repairTileIndex()` świadomie NIE naprawia, bo nie ma czego liczyć.
  **`packedForFile()` (2026-09-02)** — ta sama geometria, ale DLA PRZEGLĄDARKI: linia
  spakowana formatem `d6v` (delta 1e-6 stopnia, zygzak, varint, base64) plus kadr ze
  skrajnych pikseli. Powstało, bo podświetlenie śladu na mapie ciągnęło surowy plik GPX
  (8,26 MB → 47,9 kB odpowiedzi). Dekoder mieszka w `assets/js/discovery-map.js`
  (`ridemoreUnpackTrack`) — opis formatu stoi w obu miejscach i zmiana kodowania wymaga
  zmiany w obu.
  **`packedTrimmedForFile()` + `boundsForTrimmed()` (2026-09-03)** — bliźniaki obu
  metod wyżej, ale na `gpx_geometry_trimmed` (migr. 076): odpowiedź dla kogoś, kto
  ogląda CUDZY przejazd solo na `/przejazd/{id}`. Ten sam format `d6v` (pakowanie
  wydzielone do prywatnego `pack()`, żeby nie stały dwie kopie tej samej pętli),
  ale z punktów PO odcięciu okolic domu (§27). `boundsForTrimmed` świadomie BEZ
  klastrowania z `boundsFor` — tam kadr liczy się dla całej historii jednej osoby,
  tu dla jednego śladu, więc skrajne piksele SĄ odpowiedzią.
  **`boundsFor()` ODPORNY NA ODLEGŁY WYJAZD (2026-08-27)** — ta sama naprawa i ta sama
  para stałych (`CLUSTER_BUCKET_KM`=150, `CLUSTER_TRIGGER_KM`=1000) co
  `Discovery::boundsFor()`, przełożona na PIKSELE Merkatora zamiast osi heksagonu
  (patrz `pixelExtremes()`): naiwny prostokąt liczy się jak dotychczas, a dopiero gdy
  jego rozpiętość przekroczy próg, drugie zapytanie bucketuje ślady po ŚRODKU bboxa
  KAŻDEGO pliku (jednostką jest tu cały ślad, nie punkt) i bierze najliczniejszy kubełek.
  Używane przez `RiderController` (kadr profilu rowerzysty) — zgłoszenie usera zaraz po
  naprawie tej samej rzeczy na `/odkrycia`: „sprawdź to samo dla profilu rowerzysty".
  Od 2026-08-20 także **`allGpxUrls()`** (adresy WSZYSTKICH
  plików GPX, jakie zna baza — jedno miejsce z tą listą, bo dwie odpowiedzi na pytanie
  „skąd biorą się ślady" w końcu się rozjadą), **`backfillAll(?log)`** i
  **`missingCount()`**: przycisk „Policz geometrię wszystkich śladów" w `/admin/kafle`
  i `php tiles.php backfill` to ta sama funkcja z dwoma wejściami. Geometria liczy się
  też LENIWIE, przy pierwszym kaflu — backfill przenosi ten koszt z żądania o obrazek
  (przy kluczu `kr` byłoby to parsowanie wszystkich znanych tras naraz) do panelu.
  **KOLOR ŚLADU (migr. 073, 2026-08-27)**: `assignColor(hash)` (wołane SAMO z `ensure()`,
  po zapisaniu kafli — przed nimi ślad nie miałby jeszcze sąsiadów), `colorsFor(hashes)`
  (jedno zapytanie na kafel), `backfillColors(?log)`, `missingColorCount()`.
  Zgłoszenie usera: „wszystkie te trasy są wygenerowane w kolorze zielonym (…) mam jedną
  wielką zieloną plamę" — do tej daty kolor per obiekt miały WYŁĄCZNIE znane trasy.
  **Kolor siedzi przy GEOMETRII, nie przy przejeździe**, bo `gpx_geometry` jest kluczowana
  hashem ZAWARTOŚCI i jest dokładnie tym, co renderer rysuje jako jedną linię: ten sam plik
  bywa podpięty w kilku miejscach naraz (solo, ślad turnusu, etap), więc kolumna
  w `rider_activities` znaczyłaby „ten sam ślad jest zielony na jednej mapie i czerwony
  na drugiej". **Sąsiedztwo liczymy po WSPÓLNYCH KAFLACH INDEKSU** (`gpx_tiles`, gotowy
  indeks `(tx, ty)`) — to ta sama miara, której używa renderer: dwa ślady dzielące kafel
  to dwa ślady, które trafią na ten sam obrazek. **Kolorów sąsiadów NIE RUSZAMY**
  (przejęte z `KnownRoute::assignColor`): kafle są plikami unieważnianymi po ŚLADZIE, więc
  przemalowanie sąsiada kasowałoby kafle na jego przebiegu, a sąsiada sąsiada — lawinowo
  dalej. Sam wybór koloru robi `Utils\TrackPalette::pick()`. Znane trasy zostają przy
  WŁASNYM `known_routes.color_index` (kolor trasy przeżywa podmianę przebiegu, bo pokazuje
  go dymek, karta i wykres profilu) — to dwie niezależne przestrzenie kolorów, rozróżniane
  wizualnie obwódką stylu `route`.
  **GEOMETRIA PRZYCIĘTA SOLO (migr. 076, 2026-08-28)**: `ensureTrimmed(path)`
  (parsuje + `Utils\DiscoveryGrid::trimEnds()`, ścieżka leniwa/backfillowa),
  `ensureTrimmedFromPoints(hash, points)` (zero parsowania — `RiderActivity::
  recordSolo` ma punkty JUŻ przycięte pod pola odkryć), `hasTrimmed`,
  `inTileTrimmed`, `loadTrimmed`, `backfillAllTrimmed(?log)`,
  `missingTrimmedCount()`. Bliźniaki metod wyżej, ale na DRUGIEJ parze tabel
  (`gpx_geometry_trimmed`/`gpx_tiles_trimmed`, ten sam `gpx_hash` co
  w `gpx_geometry` — relacja 1:1, nie kolizja). Powód: klucz `all` (mapa
  społeczności) leży publicznie na dysku pod adresem do zgadnięcia, a plik
  solo zaczyna/kończy się pod domem (§27) — `TileSource::tracks('all')`
  BIERZE stąd geometrię solo (patrz niżej), NIGDY z `gpx_geometry` wprost.
  Bez koloru — heatmapa community maluje się jednym stylem, nie paletą.
  Zgłoszenie usera: był przekonany, że „Ślady" liczy WSZYSTKIE przejazdy
  (tak jak liczą je pola odkryć), a liczyła tylko `edition_tracks`.
  **`hitsInTiles(tiles, trimmed)`** (2026-09-18, Route Planner „Źródła trasy") — ślady przechodzące
  przez podany ZBIÓR kafli indeksu (INDEX_Z) z liczbą trafień na hash: jedno zapytanie po (tx, ty)
  zakresem + dokładny odsiew po zbiorze w PHP; `trimmed` czyta `gpx_tiles_trimmed` (solo w warstwie
  społeczności). Planer szuka tak kandydatów w KORYTARZU trasy, nie w całym prostokącie.
- [`OsmBasemap`](../core/Models/OsmBasemap.php) — jedyne miejsce w serwisie,
  które samo (z serwera) ściąga cudzy kafel mapy: `tile(z, x, y)`, kafle OSM
  na dysk pod `assets/tiles/osm/` (trwale, nie na czas żądania — OSM Tile
  Usage Policy: własny User-Agent, rotacja poddomen a/b/c). Pod `TileController::
  routeOfDayMap()` ("Trasa dnia" na stronie głównej, 2026-09-05) — szczegóły
  → [`features.md`](features.md).
- [`TileCache`](../core/Models/TileCache.php) — kafel na dysku: `path`, `root`,
  `urlTemplate` (niesie `?v=<epoka>`), `epoch`/`bump`, `store`, `touch`,
  `invalidateTrack`/`invalidateForEdition`/`purgeKey`/`purgeLayer`, `pruneIfNeeded`
  (limit `MAX_TILES` chroni i-węzły hostingu, nie miejsce), `stats`.
- [`TileSource`](../core/Models/TileSource.php) — co narysować na kaflu o danym kluczu:
  `isAllowed`, `isCacheable` (klucze `me`/`kd-me`/`kn-me` NIE są zapisywane na dysk),
  `userIdFor`, `tracks`, `hexCells`, `trackLabels`, `absolutePath`. Klucze warstwy
  `slady`: `all`, `u-{slug}`, `me`, `e-{id}`, `ev-{id}`, `kr`, `kr-{id}`, `kd-{slug}`,
  `kd-me`, `kn-{slug}`, `kn-me`.
  **`u-{slug}` NIESIE TEŻ SOLO, PRZYCIĘTE, PER-ŚLAD KOLOROWANE (2026-09-10)** —
  zgłoszenie z produkcji, DWA etapy tego samego dnia. (1) Rowerzysta jeżdżący
  WYŁĄCZNIE solo (import z Garmina, zero zapisów na wyjazd) miał na publicznym
  profilu odkryte hexy, ale ANI JEDNEJ linii śladu — `tracks('u-{slug}')` budowała
  grupę „real" WYŁĄCZNIE z `EditionTrack::effectiveForUser()`, więc dla takiego
  konta wychodziła pusta, mimo że `me` (własny widok) solo pokazuje od 2026-08-26.
  (2) User odrzucił pierwszą wersję naprawy („na swoim profilu każdy przejazd jest
  kolorowany i klikalny, na czyimś powinno być tak samo") — szła jedną WSPÓLNĄ
  grupą, jeden kolor na wszystkie solo tego profilu. Ostateczna naprawa: solo idzie
  przez TĘ SAMĄ `trackGroups()` co ślady z wyjazdów (parametr `$source = 'trimmed'`,
  2026-09-10) — PO JEDNEJ GRUPIE NA ŚLAD, każda w swoim kolorze. Kolor bierze się
  z PEŁNEJ `gpx_geometry.color_index` (dopisanej przez dodatkowe wywołanie
  `hashesFor()` — PEŁNY `ensure()` WYŁĄCZNIE po metadane koloru, RYSOWANIE i tak
  idzie z `gpx_geometry_trimmed`/`gpx_tiles_trimmed`, ten sam mechanizm co solo
  w kluczu `all`, patrz `trimmedHashesFor()` niżej) — **NIE pełna geometria DO
  RYSOWANIA**: `u-{slug}` leży na dysku pod adresem do zgadnięcia (sam slug), więc
  surowy plik zaczynający się pod domem (§27) nie ma tu prawa trafić, w odróżnieniu
  od `me`, które nigdy nie ląduje na dysku i pyta wyłącznie o WŁASNE dane
  zalogowanego właściciela. Efekt uboczny jest pożądany: TEN SAM plik solo dostaje
  TEN SAM kolor na `me` i na `u-{slug}`, bo kolor jest własnością PLIKU, nie warstwy.
  **Klikalność w panelu „Ostatnia aktywność"** to OSOBNA naprawa, w
  `Controllers\Support::trackUrlsForFeed()`, nie tutaj — patrz jej własny komentarz.
  Guard: `tests/przejazdy_solo_test.php`.
  **`kd-{slug}` / `kd-me` — TRASY UKOŃCZONE PRZEZ TĘ OSOBĘ** (Etap 3 warstw mapy,
  2026-08-26). `kd-me` to prywatna wersja (widz bez publicznego profilu) — ta sama
  para co `me`/`u-{slug}` przy śladach, sprawdzana RÓWNOŚCIĄ przed ogólnym prefiksem
  `kd-`, żeby literalne „me" nie trafiło jako (niepoprawny) slug. „Ukończona" to
  DOKŁADNIE definicja z `KnownRoute::progressForUser` (`matched >= cells_total`),
  liczona zapytaniem korelowanym per trasa — katalog jest mały, więc nie ma po co
  reimplementować tego w SQL-u zbiorczo.
  **`kn-{slug}` / `kn-me` — TRASY, KTÓRYCH TA OSOBA JESZCZE NIE UKOŃCZYŁA** (migr. 078,
  2026-08-29, zgłoszenie usera: mapa osobista pokazywała WYŁĄCZNIE `kd-`, więc reszta
  katalogu na niej w ogóle nie istniała, inaczej niż na mapie społeczności). DOKŁADNE
  LUSTRO `kd-` — zapytanie jest tym samym warunkiem `matched >= cells_total`,
  zanegowanym (`NOT (...)`), więc każda aktywna trasa trafia do DOKŁADNIE JEDNEJ z tych
  dwóch warstw, nigdy do obu i nigdy do żadnej (`cells_total = 0` łapie się tu, bo trasa
  nieprzeliczona nigdy nie jest „ukończona"). Guard tej własności:
  `tests/znane_trasy_test.php` („kd-/kn-: KAŻDA aktywna trasa…”). `KnownRoute::
  invalidateTiles` kasuje `kd-{slug}` i `kn-{slug}` RAZEM — zmiana, która dopisuje kogoś
  do ukończonych, mogła go zdjąć z nieukończonych.
  **Klucz `me` (warstwa „Ślady") jest JEDYNYM miejscem, gdzie rysują się przejazdy
  SOLO** (naprawa błędu, 2026-08-26 — zgłoszenie usera: „ślady, które wgrałem, nie
  pojawiają się na mapie"). Gałąź `me`/`u-{slug}` do tej daty w ogóle nie sięgała po
  `rider_activities.gpx_url` — solo nie wchodziło tam NIGDY, żadną z tych dat, mimo
  że to WIĘKSZOŚĆ tego, co ludzie realnie jeżdżą. `u-{slug}` (publiczny, cache'owalny
  na dysku) solo NIE DOSTAJE i nie może — plik jest surowy, zaczyna się pod domem
  (§27), a ten klucz jest funkcją SAMEGO SLUGU, nie widza, więc treść musi być
  bezpieczna dla każdego, kto go poprosi. Przy okazji naprawiony DRUGI, niezależny
  błąd w tej samej gałęzi: `userIdFor('me')` wołało `Auth::id()` — metodę, której
  `Core\Auth` NIGDY nie miało (zastane w commicie startowym, nie wprowadzone tą
  sesją) — więc KAŻDE żądanie klucza `me`/`kd-me` od kogokolwiek zalogowanego
  kończyło się fatalnym błędem PHP. Poprawka: `Auth::user()->id`. Oba pilnuje
  `tests/przejazdy_solo_test.php`.
  **Klucz `all` (warstwa „Ślady") niesie WYŁĄCZNIE ślady wyjazdów (`edition_tracks`),
  bez przejazdów solo** — decyzja z 2026-08-26. Przez jeden dzień solo tu było, osłonięte
  przycinaniem pliku przy zapisie; przycinanie zostało cofnięte (psuło kilometry — patrz
  `RiderActivity::recordSolo`), więc plik solo jest znowu surowy i zaczyna się pod czyimś
  domem. Kafel leży w katalogu publicznym pod adresem do zgadnięcia, więc byłby mapą
  adresów (§27). Wyjazd zostaje, bo zaczyna się na ogłoszonej publicznie zbiórce.
  **KAŻDY ŚLAD WYCHODZI STĄD OSOBNĄ GRUPĄ, W SWOIM KOLORZE** (migr. 073, 2026-08-27,
  prywatne `trackGroups`) — dotyczy kluczy `me`/`u-{slug}`, `e-{id}` i `ev-{id}`.
  Do tej daty każda z tych gałęzi zwracała JEDEN worek hashy i jeden styl, więc 89
  przejazdów wokół jednego miasta rysowało się jednym `#2C6B4F` (zgłoszenie usera: „mam
  jedną wielką zieloną plamę"). Kolor bierze się z `gpx_geometry.color_index`
  (`GpxGeometry::assignColor`), a ślad bez przydziału po prostu nie dostaje klucza `color`
  i spada na kolor stylu — czyli rysuje się jak przed migracją. Koszt rozbicia worka na N
  grup jest bliski zera: `TileController::renderTracks` odsiewa geometrię RAZ dla całego
  kafla, jednym zapytaniem po wszystkich hashach naraz (przyzwyczaiły go do tego znane
  trasy), więc dochodzi N obrotów pętli po tablicy jednoelementowej, a nie N zapytań.
  **Klucz `all` jest WYJĄTKIEM od 2026-08-28 — HEATMAPA, nie paleta kolorów.**
  Uwaga usera: „co mi da, że będę miał 500 śladów po tej samej drodze w ramach
  społeczności" — przy skali rzędu tysięcy przejazdów dziennie osobny kolor na ślad
  (dobry dla kilkudziesięciu RÓŻNYCH tras) zamienia się w confetti, gdy większość
  śladów powtarza te same drogi. `all` wraca do JEDNEJ grupy z workiem hashy, ale
  stylem `STYLES['heat']` (jeden kolor, `alpha` 0.25) — NIE stylem `real` sprzed
  migracji 073: przy niskiej kryciu GD samo sumuje przezroczystość tam, gdzie ślady
  się nakładają (`imagealphablending` włączone w `TileRenderer::canvas()`), więc
  popularna droga wychodzi ciemniejsza bez liczenia w bazie, ile razy dany fragment
  przejechano — a rzadka trasa zostaje ledwo widoczna, co jest treścią komunikatu,
  nie usterką (Strava Heatmap robi to samo, tylko na rastrze zamiast na kaflach
  wektorowych). `me`/`u-{slug}`/`e-{id}`/`ev-{id}` NIE są tym ruszone — tam obiektów
  jest mało i odróżnienie ich kolorem dalej ma wartość. Guard: `tests/kolory_sladow_test.php`.
  **`all` niesie DWIE grupy stylu `heat` od 2026-08-28 (migr. 076), nie jedną** —
  `$eventGroup` (`edition_tracks`, źródło `normal` = `GpxGeometry::load`/`inTile`)
  i `$soloGroup` (`rider_activities` solo, źródło `'source' => 'trimmed'` =
  `loadTrimmed`/`inTileTrimmed`, przez `trimmedHashesFor()` — bliźniak
  `hashesFor()`, ale wołający `GpxGeometry::ensureTrimmed()` zamiast `ensure()`).
  `TileController::renderTracks()` czyta klucz `source` per grupa i zbiera
  hashe PO ŹRÓDLE (nie w jeden worek) — nadal JEDNO zapytanie na źródło dla
  całego kafla, źródeł jest dziś dwa. Guard: `tests/przejazdy_solo_test.php`
  i `tests/heatmapa_spolecznosci_test.php` (mechanika `ensureTrimmed*`).
  **TRASY ZAPOWIADANE (`planned`) ZOSTAJĄ JEDNĄ SZARĄ GRUPĄ** i to jest decyzja: szarość
  nie jest tam „kolorem tej trasy", tylko komunikatem „zapowiedź, której nikt nie
  potwierdził śladem". Kolor odróżnia ślad OD ŚLADU, szarość odróżnia BRAK od dokonania.
  **Znane trasy (`kr`, `kr-{id}`) wychodzą stąd PO JEDNEJ GRUPIE NA TRASĘ** (migr. 064,
  prywatne `routeGroups`), bo każda ma własny kolor z `known_routes.color_index`
  (`KnownRoute::colorOf`). Grupa może nieść klucz `color`, który nadpisuje kolor stylu —
  `STYLES['route']['color']` jest od tej pory wartością ZAPASOWĄ, dla tras bez przydziału
  i dla tras wydarzeń. Kolejność grup idzie po `id` rosnąco: tam, gdzie dwa szlaki biegną
  tą samą drogą, widać ten narysowany później, więc kolejność musi być ustalona.
  **`STYLES['route']` ma OBWÓDKĘ (`casing`/`casingWidth`, 2026-08-27)** — zgłoszenie
  usera: „znane trasy i ślady mają dziś jeden styl i nakładają się na siebie nie do
  odróżnienia". Obwódka jest STAŁA dla WSZYSTKICH grup stylu `route` (`ev-{id}` też,
  bo trasa zapowiadana wydarzenia to ta sama „referencja" co znana trasa) — celowo
  NIE idzie kolumną per trasa jak `color`: kolor rozróżnia trasę OD INNEJ TRASY,
  obwódka rozróżnia CAŁĄ KATEGORIĘ „referencja" od zarejestrowanego przejazdu
  (style `real`/`planned`, bez obwódki). Rysowanie w `Utils\TileRenderer::tracks()`.
  **Zmiana treści kafla wymaga `TileCache::purgeLayer('slady')`** (albo „Reset kafli"
  → warstwa Ślady w `/admin/kafle`) — to jest zmiana KODU renderera, nie danych jednej
  trasy, więc nie ma pojedynczego śladu do `invalidateTrack()`; stare kafle bez
  obwódki inaczej zostałyby na dysku (i w `Cache-Control: immutable` przeglądarek —
  epoka bumpuje się sama w `purgeLayer`/`purgeKey`).
  - **`all()` zwraca `kr.*`, czyli od migr. 065 także JSON profilu** (~3 kB na trasę).
    Przy katalogu rzędu 200 tras to ok. 0,6 MB na liście — świadomie zostawione, bo
    listę dla zalogowanego i tak buduje `progressForUser()`, który wybiera kolumny
    jawnie i profilu nie bierze.
- [`RoutePreview`](../core/Models/RoutePreview.php) — „co mi ta trasa da": ile NOWYCH
  pól i punktów wniosłaby dana trasa konkretnej osobie (`gpx_route_cells`,
  `gpx_route_cell_runs`, migr. 050; klucz to hash zawartości pliku). `cellsForGpx`,
  `forUser`. **To SZACUNEK i tak ma być nazwany w interfejsie** — liczy z trasy
  PLANOWANEJ, a odkrycia naliczają się wyłącznie ze śladu z odbytego wyjazdu.

## Route Planner (Etap 1) — patrz [`features.md`](features.md)

- [`PlannedRoute`](../core/Models/PlannedRoute.php) — prywatny szkicownik trasy usera
  (migr. 090, 2026-09-17). Celowo NIE `known_routes` (tamta jest publiczna, community/admin) —
  `waypoints_json` (surowe klikane punkty, do ponownej edycji) i `geometry_json`
  (finalna wyliczona linia, do wyświetlenia/GPX) to DWA różne kształty danych, dwie
  kolumny. `engine`/`profile` niosą prowenencję wyliczenia (którym silnikiem/dla
  jakiego roweru) pod przyszłą zmianę silnika routingu, bez migracji. `save`,
  `update(id, userId, fields)` (IDOR guard w WHERE, nie w kodzie — `rowCount()===0`
  dla obcego), `findForUser(id, userId)` (null zamiast cudzych danych), `forUser`.
  Od migr. 092 zapisuje również opcjonalny `routing_config_id` oraz niezależny
  snapshot `routing_preferences_json`, aby późniejsza edycja nazwanego profilu
  nie zmieniła już policzonej trasy.
- [`PlannerRoutingConfig`](../core/Models/PlannerRoutingConfig.php) — CRUD nazwanych
  preferencji użytkownika pod istniejącym `BikeType`: `forUser`,
  `findForUser`, `defaultForUser`, `save`, `delete`, `setDefault`. Każdy odczyt
  i zapis zawiera ownership guard; edycja nie może przenieść konfiguracji do
  innego typu roweru ani nadpisać globalnego presetu.
- [`RidemoreCorridors`](../core/Models/RidemoreCorridors.php) — dane **warstwy routingu Ridemore** (2026-09-18; logika w `UtilsRidemoreRouting`). Bez nowej tabeli:
  `lines(sources, tiles, perSource, withOwners)` — linie zaznaczonych źródeł w kaflach z14 (znane trasy niosą starsze `surface` oraz — gdy backfill je przygotował — lokalne przedziały i histogramy `attributes.surface`/`attributes.roadClass` z `RoadAttributeCache`; przycięta prywatna kopia pozostaje neutralna, bo nie zachowuje pozycji 0..1 oryginału) (przeniesione z dawnego `PlannerController::sourceLines`: zbiory `TileSource::tracks('me'|'all')` i `KnownRoute::activeGeometryHashes()` — to, co rysują warstwy; społeczność w PRZYCIĘTEJ kopii, §27), opcjonalnie z `owners`;
  `owners(hashes)` — kto jechał śladem i ile razy (`u{id}` => {n, last}): solo po `rider_activities.gpx_hash`, ślad organizatora wyjazdu = wszyscy uczestnicy z „Byłem" (`event_track`), własny ślad = jego autor; `last` = `ride_date` (NIE `discovery_cell_totals.last_seen_at` — to czas pierwszego odkrycia pola zapisany przy imporcie);
  `localReference(bounds)` — lokalna skala: P90 `riders_count`/`passes_count` heksów okolicy (heksy tylko jako odniesienie — popularność odcinka liczy się z geometrii śladów, bo heks nie odróżnia dwóch równoległych dróg);
  `fingerprint()` — odcisk danych (przejazdy, znane trasy, ślady wyjazdów) do klucza pamięci wyniku odcinka.

## Słowniki i pozostałe

- [`Dictionary`](../core/Models/Dictionary.php) — słowniki (`dictionaries`/`dictionary_items`): `items`, `groupedLeaves`, `tree`, `id(code, itemCode)`, `findItem`, `searchItems`, `resolveOrCreate`, `regionCompatibility`, admin `createItem/updateItem/setActive/dictionaries`. Konwertuje kody ⇆ id słownikowe. `tree()` od Etapu 2 warstw mapy (2026-08-26) niesie też `meta` (zdekodowany JSON, `[]` gdy brak) i `icon` — wcześniej ich nie wybierało, choć kolumny istniały od dawna.
- [`Region`](../core/Models/Region.php) — REGION JAKO STRONA PUBLICZNA (2026-09-14). Bez tabeli: czyta słownik `region`. `countries()` (aktywne kraje z aktywnymi liśćmi, kraj domowy pierwszy, `isFlat` = kraj bez dzieci jest swoim jedynym regionem; cache na żądanie, `forgetCache()` dla testów), `resolve(kraj, ?region)` (null = 404, `redirect` = region pod złym krajem), `pathForCode(code)` (link z wydarzenia/trasy/organizatora/listy; kod kraju → strona kraju; nieaktywny → null i wołający zostaje przy starym zachowaniu), `countryPath`, `contentCounts()` (per kod: nadchodzące — `Event::upcomingCountsByRegion`, odbyte, trasy, organizatorzy), `rings(code)` (pierścienie granicy z `RegionOutline::allRegions()`, zaokrąglone do 5 miejsc, pod linię na mapie), `bounds(code)` (bbox z `rings`), `displayName` (województwa są w słowniku małą literą). Nowe odczyty dla stron regionów w innych modelach: `KnownRoute::idsInRegion`, `Treasure::onRegion/listInRegion` (te same ciała co trasa, tabela pól `region_cells`), `RiderActivity::ridersInRegion` (lista tylko publiczne profile, liczba wszystkich), `Event->regionCode` (kod pierwszego regionu z `findBySlug`).
- [`RegionOutline`](../core/Models/RegionOutline.php) — GEOMETRIA REGIONÓW (2026-09-01, narzędzie `/admin/regiony-mapa`). Jedyny właściciel pliku `data/regiony.geojson` i JEDNO WEJŚCIE do całej geometrii regionów: `all()` (obrysy rysowane ręką: kod słownika => nazwa + pierścień `[[lat,lon],…]` bez punktu domykającego + `priority`), **`allRegions()`** (WSZYSTKIE regiony — rysowane ORAZ zaimportowane z `data/wojewodztwa.geojson`, każdy z `editable`), `save(code, name, ring)`, `remove(code)`, `cellsInside(ring)`, `conflictsFor(code, ring)`, **`insideRings(rings, a, b)`**, `path()`/`importedPath()`, `useFile()` (podmiana pliku — WYŁĄCZNIE dla testów). Niesie też `VOIVODESHIP_CODES` (nazwa z GeoJSON-a → kod słownika), **przeniesione tu z `backfill_regions.php`**, bo czytają je teraz dwie rzeczy i dwie kopie rozjechałyby się CICHO. **NIE MA WŁASNEJ TABELI I TO JEST SEDNO**: geometria regionu ma jedno źródło (GeoJSON → `backfill_regions.php` → `region_cells`), a narzędzie tylko dopisuje drugi plik do tego samego wejścia.
  - `save()` DOSUWA każdy wierzchołek do środka heksa poziomu `RES_OUTLINE` (2, ok. 60 km²) — bez tego granica dwóch regionów rozjeżdżałaby się o kilkaset metrów i import zostawiałby wzdłuż niej pas bez regionu. Kolejność punktów = kolejność KLIKNIĘĆ i nie jest sortowana: wielokąt nie ma „właściwej" kolejności wierzchołków, ma ją ręka, która go rysowała. Zapis idzie przez plik tymczasowy i `rename()`.
  - **KONFLIKT ROZSTRZYGA PIERWSZEŃSTWO, NIE PRZYCINANIE POLIGONÓW** („zawsze ma priorytet edytowany" — user, 2026-09-01). Każdy zapis stempluje feature `priority` (czas zapisu); `backfill_regions.php` sortuje po nim malejąco przed przebiegiem 1, więc obrys zapisany później zajmuje sporne heksy pierwszy, a `UNIQUE(cell_id)` odbiera je poprzedniemu właścicielowi. Województwa mają `priority` 0 i tracą sporne pola BEZ dotykania pliku granic — zweryfikowane na dev: po narysowaniu obrysu wchodzącego na dolnośląskie spadło ono z 89 817 na 88 486 heksów. `conflictsFor()` liczy to ZAWCZASU (pola poziomu obrysu × pozostałe regiony) i dlatego user widzi „zabierasz: dolnośląskie (6)" przed zapisem, a nie po imporcie.
  - `insideRings()` to JEDYNA implementacja testu „punkt w poligonie" w projekcie — woła ją i to narzędzie, i backfill (który dokłada tylko odsiew po prostokącie otaczającym). Jest NIECZUŁA NA KONWENCJĘ współrzędnych: pary w pierścieniach i para w argumencie muszą być w tej samej kolejności, więc model podaje `[lat, lon]`, a backfill surowe `[lon, lat]` z GeoJSON-a.
- [`MapLayer`](../core/Models/MapLayer.php) — WARSTWY MAPY W SŁOWNIKU (Etap 2, `tasks/done/warstwy-mapy.md`, migr. 072, słownik `map_layer`). Rozwiązuje drzewo `Dictionary::tree('map_layer')` względem KONTEKSTU strony (`'all'|'me'|'rider'` — to samo pojęcie co `context` w `ridemoreDiscoveryMap`, Etap 1) na gotowe węzły dla partiala kontrolki i modułu mapy: `tree($context, ['loggedIn', 'slug', 'only'])`, `tileKeysFor()` (mapa `warstwa=>klucz TileSource`, tylko `kind:'tiles'`), `filtersFor()` (opis `{param, children}` dla `ridemoreComposeFilter` w JS), `withQueryOverrides($tree, $_GET)` (persystencja stanu w adresie — modele w tym serwisie same nie czytają `$_GET`, więc przyjmuje tablicę), `flatten()` (drzewo → płaska `klucz=>on`), `tileKeyFor($source, $slug)` (token `community`/`subject`/`subject-private`/`known-routes` → klucz `TileSource`; `subject` bez slugu spada na `me`, `subject-private` IGNORUJE slug i jest zawsze `me` — naprawa błędu 2026-08-26: to JEDYNY klucz, na którym `TileSource::tracks()` dorysowuje solo, więc kontekst `me` warstwy „Ślady" musi tam trafiać nawet dla kogoś z publicznym profilem, podczas gdy `rider` — wciąż widok publiczny — zostaje przy `subject`/`u-{slug}`). GRANICA: wiersz słownika mówi CO i GDZIE (`meta.kind`, `meta.contexts.{ctx}.{on,hint,shown,source}`, `meta.filterParam`/`filterValue`, `meta.requiresLogin`), ta klasa mówi JAK TO ROZWIĄZAĆ dla żądania — SAMO RYSOWANIE zostaje w JS (trzy `kind`: `cells`/`tiles`/`markers`, trzy różne mechanizmy, nie do skonfigurowania w JSON-ie). `only` (lista top-level kluczy) zawęża drzewo dla stron, które mapą odkryć NIE są (strona trasy, panel dnia wydarzenia — tam „Ślady" nigdy nie miały sensu); to wiedza o UKŁADZIE STRONY, więc siedzi w wołaniu kontrolera, nie w słowniku. **Strona trasy i panel dnia wydarzenia PATCHUJĄ węzły `cells` i (od Etapu 3) `trails`** po `tree()` — `cells` dostaje inny domyślny stan i podpis (mgła jest tam dodatkiem, nie tematem strony), `trails` wraca na `trackKey = 'kr'` (katalog/kontekst wokół trasy albo dnia, nie postęp widza) — zamiast dostawać osobny wiersz w słowniku dla tej samej warstwy: to jest wiedza o UKŁADZIE KONKRETNEJ STRONY, tak samo jak `only`. Woła go: `DiscoveryController`, `RiderController`, `TrailController`, `EventController::show`. **Panel skarbów w adminie i zgłoszenie skarbu przeszły na `MapLayer` 2026-08-29** (Etap 4, decyzja usera „kontrolka tak, silnik nie"): oba biorą drzewo z `tree('all', ['only' => ['cells','heat','slady','trails']])` — **bez węzła „Skarby"**, bo skarby rysują te ekrany SAME (przeciągalne pinezki admina, warstwa antyduplikatowa zgłoszenia), a warstwa z silnika postawiłaby obok nich drugi komplet znaczników tych samych punktów. Zgłoszenie DOPISUJE do drzewa własny węzeł `treasures` w szablonie (`array_merge`) i rozdziela obsługę w jednym handlerze: własna warstwa → grupa Leafletu ekranu, reszta → `map.ridemoreSetLayer`. Oba ekrany podają silnikowi ISTNIEJĄCĄ mapę (`map: mapa`), więc nie powstaje druga i klikanie w mapę (stawianie punktu) działa jak dotąd. **Kronika ZOSTAJE przy własnym rysowaniu** — jej mapa pokazuje pola JEDNEGO przejazdu w podziale „nowe / już miałem" (`$rideCells`, `ridemoreHexRing`), czego silnik nie umie: on rysuje zagregowaną mgłę z `/api/discovery/cells`. Przeniesienie zabrałoby ten podział, czyli sens tamtej mapy.
  **Etap 3 (2026-08-26), token `subject-done`** — `tileKeyFor('subject-done', $slug)`
  → `kd-{slug}`/`kd-me` (para do `subject` → `u-{slug}`/`me`). W słowniku `trails`
  dostał ten token dla kontekstów `me`/`rider` (**„Trasy również w kontekście
  społeczności dla całości, a usera to tylko usera"** — user, 2026-08-26): własna mapa
  i profil pokazywały WYŁĄCZNIE trasy ukończone przez tę osobę, `all` zostaje pełnym
  katalogiem (`known-routes`).
  **Migr. 078 (2026-08-29) rozbija to dalej** — zgłoszenie usera: mapa osobista w ogóle
  nie pokazywała katalogu poza ukończonymi trasami, w odróżnieniu od mapy społeczności.
  Węzeł `trails` w kontekstach `me`/`rider` TRACI WŁASNE `source` (przestaje sam być
  warstwą kafli — `meta.contexts.me`/`rider` nie niosą już klucza `source`, tylko
  `on`/`hint`) i dostaje DWOJE DZIECI, każde ze swoim `source`: `trailsDone` →
  `subject-done` (bez zmiany znaczenia) i `trailsRemaining` → NOWY token
  `subject-remaining` → `tileKeyFor` daje `kn-{slug}`/`kn-me` (para do `subject-done`,
  ten sam wzorzec zapasowego klucza bez slugu). Rodzic zostaje ZBIORCZYM przełącznikiem
  obojga dzieci (ten sam mechanizm CSS/JS „dziecko gaśnie razem z rodzicem", co przy
  „Skarbach"). Kontekst `all` NIE MA tych dzieci wcale (brakuje im klucza `'all'` w
  `contexts`, więc `buildNode` ich tam nie pokazuje — ten sam mechanizm, który chowa
  `treasuresFound`/`treasuresNew` na profilu rowerzysty) — mapa społeczności zostaje
  jedną warstwą `trails: 'kr'`, bez zmian.
  **Strona trasy i panel dnia wydarzenia PATCHUJĄ TERAZ TAKŻE `children`** — patch, który
  do tej daty tylko nadpisywał `trackKey` z powrotem na `'kr'`, musi od migr. 078 też
  wyzerować `$layer['children'] = []`: inaczej te strony (kontekst `me`, jeśli widz jest
  zalogowany) dostałyby dwa dodatkowe przełączniki o postępie widza na INNYCH trasach,
  mimo że obie strony patrzą na JEDNĄ konkretną trasę/dzień, nie na czyjś katalog
  ukończeń. `TrailController::show` i `EventController::show` (blok dnia wydarzenia).
- [`OAuthIdentity`](../core/Models/OAuthIdentity.php) — tożsamości OAuth (`user_oauth_identities`): `findUserId(provider, providerUserId)`, `link(userId, provider, providerUserId)`.
- [`ActivationToken`](../core/Models/ActivationToken.php) — tokeny aktywacji/resetu (`account_activation_tokens`): `issueFor`, `resolveUserId`, `markUsed`.
- [`UserAdmin`](../core/Models/UserAdmin.php) — lista i moderacja kont dla admina (migr. 058): `search`, `counters`, `contentSummary`, `find`, `block`, `unblock`, `deleteIfEmpty`. **Osobny model, nie metody w `User`** — czyta cudze konta hurtem, razem z danymi, których sam użytkownik o sobie nie widzi; wsypanie tego do `User` dałoby każdemu kontrolerowi frontu narzędzia moderacji pod ręką. Kasujemy tylko konto PUSTE: klucze obce kaskadują do kilkudziesięciu tabel, więc usunięcie aktywnego konta wycierałoby historię CUDZYCH wyjazdów i niezmienny rejestr punktów — stąd blokada zamiast kasowania.
- [`AiImportLog`](../core/Models/AiImportLog.php) — dziennik ekstrakcji importera AI (`ai_import_logs`, migr. 032): `record`, `domainSummary` („knowledge base" v1 per domena). Świadomie BEZ pełnego payloadu wejścia — tylko fingerprint (liczba elementów, domena).
- [`EventImport`](../core/Models/EventImport.php) — serwerowy importer wydarzeń (worker CLI [`import_events.php`](../import_events.php) + panel [`Admin\ImporterController`](controllers.md), kontrakt `tasks/active/importer-wydarzen.md`): `importUrl(url)` to WSPÓLNY rdzeń CLI+web (fetch → silnik AI → dziennik → kolejka; nigdy nie rzuca, zwraca status created|possible_duplicate|skipped|js_rendered|error). `queueCandidate(extracted, payload)` bierze wynik ekstraktora AI (`analyze.py` przez `AiEngineBridge`) i zapisuje KANDYDATA przez `Event::save()` w stanie `oczekuje_weryfikacji` (istniejąca moderacja, człowiek zatwierdza — nigdy autopublikacja). `resolveOrganizer` = REALNY organizator jako pending konto (`User::createPending`+`updateName`, claimable przez `issueClaimLink`), bot tylko gdy brak nazwy i e-maila. Dedup: `findEventBySourceUrl` (intra, po `custom_attributes.source_url`), `findPossibleDuplicate` (inter, tytuł+data ±1 dzień, `similar_text` ≥88% — OZNACZA, nie pomija). Prowenancja idzie do `events.custom_attributes` (jedyna zmiana w `Event::save`: zapisuje tę kolumnę tylko gdy klucz `customAttributes` obecny w `$input`, więc edycja formularzem jej nie kasuje). Testy: `php tests/run.php importer`.
- [`EventImportSource`](../core/Models/EventImportSource.php) — zarządzalna lista ŹRÓDEŁ importera (kalendarze) w pliku `data/event_sources.json` (wzorzec `RegionOutline`/plik, nie tabela): `all`/`urls`/`add`/`remove`. Plik jest źródłem prawdy po pierwszym zapisie; seedowany z `config event_import.sources`. Wspólny dla panelu `/admin/importer` i workera `import_events.php --sources`.
- [`ArchiveImport`](../core/Models/ArchiveImport.php) — import archiwum ze Stravy/Garmina z pliku ZIP: `fromZip(userId, zipPath)`. Plik zamiast API, bo warunki cudzego API potrafią się zmienić, a eksport należy do użytkownika. **Model istnieje, ale nie ma jeszcze wejścia HTTP** — żadna trasa ani kontroler go nie woła. Uwaga: bierze z archiwum wyłącznie `.gpx`/`.gpx.gz`, a eksport z Garmina zawiera pliki `.fit` w zagnieżdżonych ZIP-ach — dla Garmina to wejście dziś nic nie wciągnie.
- [`DeviceConnection`](../core/Models/DeviceConnection.php) — połączenie konta z LICZNIKIEM (migr. 069, zastąpiło `GarminAccount`): `find`/`allFor`/`connect`/`token`/`refreshToken`/`disconnect`, wszystko per para (użytkownik, dostawca). Automat (migr. 088): `setAutoImport`, `hasAutoImport`, `setExternalUserId`, `autoImportUsers(provider, externalUserId)` — webhook trafia WYŁĄCZNIE do osób z włączonym przełącznikiem; `connect()` przełącznika nie rusza, `disconnect()` kasuje go razem z wierszem. Hasła nie ma w bazie w żadnej postaci; token (komplet OAuth albo sesja Garmina) leży jako zaszyfrowany JSON, AES-256-GCM kluczem `devices.token_key`. `disconnect()` CELOWO zostawia rejestr `device_activities`.
- [`DeviceImport`](../core/Models/DeviceImport.php) — `candidates(userId, provider)` i `import(userId, provider, ids, meta)` dla dostawców OAuth (Polar, Wahoo): pobiera plik, konwertuje FIT→GPX (`Utils\Fit`) i zapisuje przez `RiderActivity::recordSolo` — tę samą drogą co plik wgrany ręcznie. Trzyma też WSPÓLNY rejestr pobrań (`knownIds`/`remember`), z którego korzysta również `GarminImport`. Token odświeżany i ZAPISYWANY przed każdą operacją (u części dostawców refresh jest jednorazowy). `import()` zwraca też `przejazdy` (faktycznie dodane). Automat (migr. 088): `fromWebhook(provider, event)` — dla każdej osoby z włączonym automatem: rejestr → metadane (`DeviceApi::activityDetails`, gdy webhook ich nie niósł) → `DeviceApi::isCycling` (wątpliwe NIE trafia do rejestru) → `import()` → `notifyImported()` przez `Notifier` (typ `device_ride`, klucz `dev:{dostawca}:{id}`).
- [`GarminImport`](../core/Models/GarminImport.php) — Garmin przez most do Pythona (migr. 068): `candidates(userId)` i `import(userId, ids, meta)`. Od migr. 069 korzysta ze WSPÓLNEGO połączenia (`DeviceConnection`, dostawca `garmin`, token sesji pod kluczem `session`) i wspólnego rejestru (`DeviceImport::knownIds`/`remember`). Dwie warstwy ochrony przed podwójnym liczeniem: rejestr `device_activities` (po identyfikatorze z Garmina, ZANIM cokolwiek pobierzemy) i `UNIQUE (user_id, gpx_hash)` w `rider_activities` (ten sam ślad wgrany wcześniej ręcznie). `BATCH = 15` na jedno kliknięcie.
- [`NotificationGate`](../core/Models/NotificationGate.php) — **bramka powiadomień** (Etap 0 programu zachęt, 2026-09-11; migr. 081, rozszerzona o kanały w migr. 082). Jedyne miejsce, które odpowiada na pytanie „czy wolno wysłać TO, TEMU, RAZ": `claim($userId, $type, $dedupeKey, $kanal = PUSH): ?int` sprawdza zgodę per rodzaj I KANAŁ, budżet (liczony OSOBNO dla każdego kanału) i ciszę nocną (tylko dla pusha — mail nikogo nie budzi), a zajęcie miejsca w dzienniku robi **tym samym `INSERT IGNORE`**, którym rozstrzyga powtórkę — decyzja i zapis są jedną operacją, bo cron i żądanie HTTP potrafią trafić w tę samą sekundę. Wołający wysyła TYLKO gdy dostał id. `Core\Push` zostaje głupi (umie wysłać, nie decyduje). Typy: `WIADOMOSC`, `ZAPIS_NA_WYJAZD`, `PRZEJAZD_Z_LICZNIKA` (migr. 088, zgody `push_rides`/`mail_rides`) (**transakcyjne** — bez budżetu i bez ciszy nocnej, bo nikt nie wyłącza powiadomienia, na które czeka), `SKARB_W_OKOLICY`, `NOWOSC_W_OKOLICY`, `POSTEP` (zachęty — 2/tydzień, 1/dobę, cisza 22:00–6:59) oraz `DOPASOWANIE` (Etap 1c — istnieje WYŁĄCZNIE w kanale `mail`, bo tylko tam ma nadawcę; stoi na starszej zgodzie `notify_matches`, jedynej domyślnie wyłączonej). Kanały `PUSH`/`MAIL`, nieznany rzuca tak samo jak nieznany typ. Tu mieszka też **wypis z maili**: `adresWypisu(userId, flaga)` i `rozwiazWypis(u, f, k)` — podpis HMAC obejmujący user_id ORAZ flagę, bez wiersza w bazie i bez wygasania (w `md/database.md`, migr. 082, jest napisane, czemu NIE `ActivationToken`). `ciszaNocna(?int $godzina)` przyjmuje godzinę WYŁĄCZNIE po to, żeby dało się ją sprawdzić testem. Nieznany typ rzuca `InvalidArgumentException` — literówka ma zatrzymać się na testach, a nie zamienić w powiadomienie bez zgody i bez budżetu.
- [`PushNotifier`](../core/Models/PushNotifier.php) — **nadawcy zachęt z crona** (nazwa historyczna: od Etapu 1c wysyła obydwoma kanałami). `runTreasuresNearby()` — nowy skarb w regionie Twoich odkryć; `runNewInRegion()` (Etap 1b, 2026-09-11) — **nowa znana trasa albo nowo opublikowany wyjazd** w tym samym regionie. Oba zaczynają od odbiorcy (`discovery_cells` → `region_cells`), NIE od `push_devices` — kanał rozstrzyga bramka per człowiek. „Okolica\" to REGION ADMINISTRACYJNY, nie promień GPS: promień wymagałby przechowywania ostatniej pozycji, czyli nowej kategorii danych osobowych, dla kilkunastu kilometrów dokładności przy zachęcie wysyłanej dwa razy w tygodniu.
- [`NotificationSettings`](../core/Models/NotificationSettings.php) — **ustawienia powiadomień z bazy** (migr. 083, 2026-09-11; panel `/admin/powiadomienia`). Wzorzec przepisany z `ScoringSettings`: `PARAMETRY` w kodzie trzyma wartości domyślne, zakresy i uzasadnienia, tabela wyłącznie klucze realnie zmienione; `get`/`getInt`/`wlaczone` czytają obowiązującą, `set($klucz, null, $userId)` kasuje nadpisanie i przywraca domyślną. Biała lista kluczy (nieznany rzuca przy odczycie, a przy zapisie jest ignorowany) i **przycinanie do zakresu w modelu, nie w formularzu** — „godzina jest z doby" to reguła domenowa, a ekran jest tylko jednym z wejść. Brak tabeli (środowisko przed migracją) nie zatrzymuje powiadomień: `all()` łyka błąd i wraca do domyślnych. **Treści powiadomień świadomie NIE są konfigurowalne** — zależą od poziomu ujawnienia skarbu, więc szablon z bazy pozwoliłby obejść regułę, która pilnuje, żeby nazwa nieodkrytego skarbu nie wyciekła.
- [`NotificationTexts`](../core/Models/NotificationTexts.php) — **treści powiadomień** (migr. 084, 2026-09-11). `POWIADOMIENIA` grupuje je PER RODZAJ (tak jak panel), z kanałami, znacznikami, blokami i wariantami; `render($klucz, $dane, $bloki, $plain)` podstawia **dane przez `htmlspecialchars`, a bloki jako gotowy HTML** — to rozróżnienie jest tu najważniejsze. `podglad()` idzie tą samą drogą na `PRZYKLAD` i `blokiPrzykladowe()`, więc panel nie może pokazać czegoś, czego nikt nie dostanie. `set()` odrzuca znaczniki spoza listy pola (**oddaje ich nazwy**, żeby ekran powiedział, co zniknęło), normalizuje CRLF przed porównaniem z domyślną i przepuszcza HTML przez `oczyscHtml()` (biała lista tagów, atrybuty zrywane poza `href`). **Warianty `jawny`/`ukryty`** dla skarbu wybiera KOD wg poziomu ujawnienia, a wariant „ukryty\" nie ma `{nazwa}` na liście — patrz `md/database.md`, migr. 084. **`blokiPrzykladowe($kod, $wariant)` daje podgląd PER POWIADOMIENIE i per wariant** — jeden wspólny zestaw sprawiał, że panel pokazywał „Zobacz na mapie → /odkrycia\" przy mailu o wiadomości i o dopasowaniu (zgłoszenie usera 2026-09-11). `blokKarta()` składa ramkę z faktami o wyjeździe (termin, region, dystans, ilu jedzie) z polską odmianą liczebnika; puste pola znikają, zamiast pokazywać zera.
- [`Notifier`](../core/Models/Notifier.php) — **most wysyłki** (Etap 1c, 2026-09-11). Podział ról: `NotificationGate` DECYDUJE i nic nie wysyła, `Core\Push`/`Core\Mailer` WYSYŁAJĄ i o niczym nie decydują, a ten most spina jedno z drugim: `wyslij($userId, $typ, $dedupeKey, $push, $mail): array` woła `claim()` osobno per kanał i puszcza tylko tam, gdzie dostał id (zwraca `['push'=>bool,'mail'=>bool]`). Pusta tablica `$push`/`$mail` wyłącza ten kanał dla danego wywołania. **Każdy mail z mostu dostaje adres wypisu i nagłówki `List-Unsubscribe` (RFC 8058)** — dokłada je most, bo to on wie, która flaga zgody rządzi typem; maile spoza mostu (reset hasła, potwierdzenie wpłaty) stopki wypisu nie mają i mieć nie powinny. Istnieje z tego samego powodu co bramka: sześć miejsc wysyłki musiałoby inaczej powtarzać „claim push → wyślij push → claim mail → wyślij mail".
- [`GarminBridgeError`](../core/Models/GarminBridgeError.php) — błąd z mostu z KODEM przyczyny (`auth`/`mfa`/`rate`/`conn`/`setup`), żeby kontroler mógł powiedzieć „popraw hasło" zamiast „coś poszło nie tak".
