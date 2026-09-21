# Linkowanie awatarów: rowerzysta vs organizator — kontrakt implementacyjny

Zgłoszenie usera (2026-09-10): sprawdzić, czy każdy awatar w serwisie prowadzi
do profilu rowerzysty, z jednym wyjątkiem — kontekst organizatora wydarzenia.

## Reguła (tak jak ją rozumiem — do potwierdzenia przed startem)

1. Domyślnie: KAŻDY awatar/imię pokazujące konkretną osobę →
   `/rowerzysta/{public_slug}`.
2. Wyjątek: gdy ta sama osoba pokazuje się **w roli organizatora KONKRETNEGO
   wydarzenia** (blok „kto organizuje ten wyjazd" na stronie eventu, karta
   eventu, ikonka przy „Trasie dnia") → `/organizatorzy/{slug}`
   (`organizer_profiles.slug`), bo w tym miejscu to jest jej rola, nie
   tożsamość rowerzysty. Ta sama osoba może przecież zapisać się na cudzy
   event jako zwykły uczestnik.
3. Wszędzie indziej (skład wyjazdu jako uczestnik, kronika, komentarz/FAQ pod
   WŁASNYM wydarzeniem, Puls) ta osoba nadal jest rowerzystą →
   `/rowerzysta/{slug}`, nawet jeśli przy konkretnym wpisie ma znaczek
   „Organizator" — to etykieta roli tego wpisu, nie zmiana tożsamości linku.
4. Puls: wpis o DODANIU wydarzenia → link do organizatora; wpis o przejeździe
   solo / zdobytym skarbie → link do rowerzysty.

## Stan zastany (zweryfikowany w kodzie 2026-09-10)

Przejrzane: `views/web/partials/rider-avatar.php`,
`views/web/partials/activity-card.php` i każde miejsce w `views/web`
renderujące awatar (19 plików, `grep -ri avatar`).

### A. Już działa poprawnie — bez zmian

- `renderRiderAvatar()` / `renderRiderName()`
  ([rider-avatar.php](../../views/web/partials/rider-avatar.php)) i
  `renderActivityCard()`
  ([activity-card.php](../../views/web/partials/activity-card.php)) ZAWSZE
  linkują do `/rowerzysta/{slug}`. Wszędzie, gdzie są dziś użyte — skład
  wyjazdu (`event-roster.php`), kronika (`chronicle.php`), Puls „Twój
  peleton" (`pulse.php`), `ride.php`, `trail.php`, `rider-profile.php`,
  komentarze/opinie/FAQ na stronie eventu (`event-page.php`, w tym odpowiedzi
  ze znaczkiem „Organizator", `comment-org-tag`) — kontekst jest poprawnie
  rowerzystowski wg punktu 3 reguły. **Założenie do potwierdzenia:** odpowiedź
  organizatora na FAQ/komentarz pod własnym eventem zostaje linkiem do jego
  profilu ROWERZYSTY (ze znaczkiem-etykietą obok) — nie zmieniam tego na
  organizatora, bo rozumiem to jako punkt 3, nie punkt 2 reguły.
- `event-card.php`, `home-event-card.php`, `organizers-list.php` — awatar
  organizatora siedzi WEWNĄTRZ jednej dużej karty-linku
  (`<a class="event-card">` / `<a class="hcard">` / `<a class="org-card">`).
  Zagnieżdżony `<a>` w `<a>` to nieprawidłowy HTML, więc te awatary CELOWO nie
  są osobnym linkiem — kliknięcie w kartę i tak prowadzi we właściwe miejsce
  (wydarzenie → tam już jest poprawny link do organizatora; katalog
  organizatorów → już prowadzi do profilu). Bez zmian.
- `messages.php` (awatary rozmówców w skrzynce — link to już wiersz
  konwersacji, nie profil), `account.php` (edycja WŁASNEGO zdjęcia),
  `organizer-profile.php` / `organizer-profile-form.php` (organizator widzi/
  edytuje WŁASNY profil) — to nie jest przypadek „ktoś się pojawia i trzeba go
  podlinkować". Poza zakresem.

### B. Znalezione braki — do naprawienia

1. **`views/web/pages/event-page.php`** (dwa miejsca, ok. L370 i L809) — blok
   „kto organizuje ten wyjazd": awatar organizatora to goły `<img>`, BEZ
   linku. Imię obok jest już poprawnie linkowane do `/organizatorzy/{slug}`
   (L376, L816) — awatar ma dostać dokładnie ten sam href.

2. **`views/web/pages/home.php`** (Trasa dnia, blok `.rotd-org`, ok.
   L366-374) — gdy `routeOfDay['type'] === 'event'`, pokazuje awatar+imię
   organizatora, ale ANI JEDNO ANI DRUGIE nie jest linkiem. `organizer_slug`
   nie jest dziś w ogóle pobierany przez
   `Event::firstUpcomingWithElevationProfile()` (core/Models/Event.php:1038)
   — LEFT JOIN na `organizer_profiles` (alias `org_prof`) już tam jest, więc
   to jedna linijka SELECT-a (`org_prof.slug AS organizer_slug`) plus
   przekazanie dalej w `HomeController::buildRouteOfDayFromEvent()`.

3. **`views/web/pages/discovery.php`** (`.disc-social__faces`, ok.
   L381-390) — małe kółka „kto ostatnio odkrywał" w sekcji „Razem
   odkryliśmy": w ogóle nie są linkami, mimo że
   `Discovery::recentDiscoverers()` (core/Models/Discovery.php:49) JUŻ
   zwraca `public_slug`. Czysto widokowa poprawka — dane są gotowe.

### C. Puls (`/puls`) — reguła jest dziś bezobiektowa

Obecne typy wpisów w feedzie (`przejazd`, `sklad`, `kronika`, `wezwanie`,
`zapis`, `skarb-nowe`, `skarb-pierwszy`) są CELOWO bezimienne — komentarze w
kodzie wprost mówią „bez imienia, wpis mówi ile osób, nigdy kto"
(pulse.php:294, 305). Nie ma dziś wpisu typu „nowe wydarzenie dodane" z
awatarem organizatora, ani wpisu z imiennym awatarem rowerzysty przy
przejeździe/skarbie w głównym feedzie. Jedyne prawdziwe, imienne awatary na
`/puls` to sekcja „Twój peleton" na górze strony (uczestnicy wyjazdów), i te
już poprawnie linkują do profilu rowerzysty (patrz sekcja A).

**Pytanie:** reguła z punktu 4 nie ma dziś czego naprawić — czy to zgłoszenie
na przyszłość (gdy Puls dostanie kiedyś imienne wpisy/avatary), czy chodziło
o coś, co już dziś istnieje i przeoczyłem? Jeśli to drugie, proszę wskazać
konkretny wpis/ekran — nie znalazłem takiego miejsca w kodzie.

## Zakres zmian

- `views/web/pages/event-page.php` — owinięcie 2 awatarów organizatora w
  `<a href="/organizatorzy/{slug}">`.
- `core/Models/Event.php` — `firstUpcomingWithElevationProfile()`: dodać
  `org_prof.slug AS organizer_slug` do SELECT-a.
- `core/Controllers/HomeController.php` — `buildRouteOfDayFromEvent()`:
  przekazać `organizerSlug` w zwracanej tablicy.
- `views/web/pages/home.php` — `.rotd-org`: owinąć awatar+imię linkiem do
  `/organizatorzy/{organizerSlug}` (gdy slug jest; bez linku, gdy nie ma —
  tak jak dziś przy braku sluga rowerzysty w `rider-avatar.php`).
- `views/web/pages/discovery.php` — `.disc-social__faces`: owinąć każde `<i>`
  w `<a href="/rowerzysta/{public_slug}">`, pomijając osoby bez sluga.

**Bez zmian:** `rider-avatar.php`, `activity-card.php`, `event-roster.php`,
`chronicle.php`, `pulse.php`, `ride.php`, `trail.php`, `rider-profile.php`,
`event-card.php`, `home-event-card.php`, `organizers-list.php`,
`messages.php`, `account.php`, `organizer-profile.php`,
`organizer-profile-form.php`, baza danych (żadna migracja).

## Kryteria akceptacji

1. Na stronie eventu klik w awatar organizatora prowadzi na
   `/organizatorzy/{slug}` — tak samo jak klik w imię obok.
2. Na stronie głównej, gdy „Trasa dnia" to wydarzenie z organizatorem: awatar
   i imię są linkiem do `/organizatorzy/{slug}`; gdy to trasa katalogowa
   (`type !== 'event'`) — sekcja organizatora się nie pokazuje, bez zmian.
3. Na `/odkrycia`, w „Razem odkryliśmy": każdy awatar z „kto ostatnio
   odkrywał" jest linkiem do `/rowerzysta/{slug}`, chyba że dana osoba nie ma
   sluga (wtedy jak dziś: bez linku, wygląda tak samo).
4. Nic z sekcji A się nie rusza — w szczególności awatar w kartach eventów
   (`.hcard` / `.event-card`) NIE dostaje własnego `<a>` (uniknąć
   zagnieżdżonych linków — nieprawidłowy HTML).
5. `php tests/run.php` zielone.

## Weryfikacja

`php -l` na zmienionych plikach, `php tests/run.php`, żywy smoke test w
przeglądarce: event ze zdjęciem organizatora, „Trasa dnia" = event z
organizatorem, `/odkrycia` z co najmniej jednym odkrywcą mającym awatar.

---

## WYKONANIE — KONTRAKT ZAMKNIĘTY 2026-09-10

Założenia z sekcji A (znaczek „Organizator" w komentarzu/FAQ zostaje linkiem
do rowerzysty; reguła Pulsu jest dziś bezobiektowa) potwierdzone przez usera
— zaimplementowano dokładnie zakres z sekcji B, bez zmian poza nim.

- **event-page.php** — oba awatary organizatora (`.cover__org`, `.orgbox`)
  owinięte w `<a href="/organizatorzy/{slug}">`, ten sam adres co istniejący
  link na imieniu. `.orgbox` dostał `text-decoration:none` (tak jak sąsiedni
  link na imieniu w tym bloku); `.cover__org` celowo bez tego stylu — sąsiedni
  link na imieniu w tym bloku też go nie ma.
- **Event.php** (`firstUpcomingWithElevationProfile`) — doszła jedna kolumna
  `org_prof.slug AS organizer_slug` do istniejącego LEFT JOIN-a.
- **HomeController.php** (`buildRouteOfDayFromEvent`/`buildRouteOfDayFromKnownRoute`)
  — przekazuje `organizerSlug` (i `null` w gałęzi trasy katalogowej).
- **home.php** (`.rotd-org`) — tag renderowany dynamicznie jako `<a>` (gdy
  jest `organizerSlug`) albo `<span>` (gdy nie ma, jak dziś) — ten sam wzorzec
  co brak sluga w `rider-avatar.php`.
- **discovery.php** (`.disc-social__faces`) — każde `<i>` owinięte w
  `<a href="/rowerzysta/{public_slug}">`, gdy dana osoba ma slug; dane już
  istniały w `Discovery::recentDiscoverers()`, zmiana czysto widokowa.

**Zweryfikowane żywo w przeglądarce (DOM, nie tylko lektura kodu):**
- `/` → `.rotd-org` to realny `<a href="…/organizatorzy/tatry-bike-tours">`
  obejmujący i avatar, i imię.
- `/events/3-dniowa-petla-bieszczadzka` → oba avatary organizatora
  (`.cover__org a`, `.orgbox a`) prowadzą na ten sam adres organizatora.
- `/odkrycia` → wszystkie 4 kółka w „Razem odkryliśmy" to `<a>` do
  `/rowerzysta/{slug}` właściwej osoby; `getComputedStyle` potwierdza, że
  krążki zachowują wygląd sprzed zmiany (32px, `border-radius:50%`,
  zachodzenie `-8px` — `<a>` jako dziecko `display:flex` bloczkuje się tak
  samo jak wcześniej `<i>`).

**Testy:** `php tests/run.php` → 456 przeszło, 3 nie przeszło — wszystkie
trzy PRZEDTEM istniejące i niezwiązane (dane dev): §27 skarb na mecie,
warstwa „Ślady" niesie solo, oraz `diagnostyka_pustych_kafli_test.php`
(brakujący plik GPX dla trasy testowej na dysku). Żaden test nie dotyczy
zmienionych plików — w repo nie było testu na markup tych trzech miejsc.

**Poza zakresem, zgłoszone, nie naprawione:** brakujący plik
`/assets/uploads/gpx/TEST-scale.gpx` dla trasy „Szlak Testowy” — to dane
środowiska dev, nie kod, i nie ma związku z tym zadaniem.
