# Zasoby (Resources) — `core/Resources/*`

Warstwa pośrednia: mapuje modele/surowy POST na **tablice asocjacyjne** gotowe do
oddania widokowi, wysłania jako JSON albo przekazania do `Event::save()`. Trzyma
prezentację/kształt danych z dala od modeli i kontrolerów. Wszystkie `public static`.

## Wyjście (model → tablica dla widoku/JSON)

- [`EventResource`](../core/Resources/EventResource.php) — `fromModel(Event): array`. Pełny kształt eventu na stronę wydarzenia i `/api/events/{slug}`: fakty, turnusy, cennik, sprzęt, etapy, formy zapisu (`registrationType`, `externalUrl/Phone/Email`), agregacja nawierzchni (`aggregateSurface`, prywatna). **Tu dodajesz pole, żeby pokazać je na stronie eventu.**
- [`EventCardResource`](../core/Resources/EventCardResource.php) — `fromRow(array): array`. Karta wydarzenia (lista `/wydarzenia`, strona główna) z surowego wiersza SQL. Lekka — bez pełnego modelu.
- [`OrganizerResource`](../core/Resources/OrganizerResource.php) — `fromModel(Organizer): array`. Kształt profilu organizatora.
- [`StageResource`](../core/Resources/StageResource.php) — `fromModel(EventStage): array` + `surfaceBreakdown` (prywatna). Etap/dzień trasy z podziałem nawierzchni.
- [`PricingResource`](../core/Resources/PricingResource.php) — `fromModel(EventPricing): array`. Cennik do wyświetlenia.
- [`MatchCardResource`](../core/Resources/MatchCardResource.php) — karty dopasowań: `fromMatches(matches, urlBuilder)`, `fromLooseRideFallback(...)`, `groupSizeLabel`, prywatne `eyebrowFor`, `loadDetails`.

## Wejście (POST/model → dane do zapisu lub do formularza)

- [`EventFormInput`](../core/Resources/EventFormInput.php) — **`fromRequest(post, files, organizerId, existingId): array`**. Waliduje i normalizuje cały formularz eventu do tablicy `$input` dla `Event::save()`. Tu m.in. wyprowadzany jest `registrationType='external'` gdy podano URL/telefon/e-mail, walidacja e-maila, obsługa uploadu GPX/okładki. **Wejście do zapisu eventu.**
- [`EventFormResource`](../core/Resources/EventFormResource.php) — stan **początkowy formularza** (Alpine). `empty()` (nowy), `fromPost(post)` (po błędzie walidacji — zachowuje wpisane), `fromRawEvent(raw)` (edycja — prefill z bazy), prywatna `emptyStage()`. **Dodając pole formularza, dopisz je we wszystkich trzech.**
- [`OrganizerProfileFormInput`](../core/Resources/OrganizerProfileFormInput.php) — `apply(Organizer, targetUser, post, files): ?string`. Zapis edycji profilu organizatora (zwraca komunikat błędu lub null).

## Kronika i profil rowerzysty

- [`ChronicleResource`](../core/Resources/ChronicleResource.php) — `build(event, edition,
  attended, entries, newPairs, ?viewerId)`. **`$viewerId` rozstrzyga, CZYJ ślad trafia na
  mapę kroniki**: własny ślad widza bije ślad organizatora (`EditionTrack::effectiveFor`).
  Zwraca `tracks` (WSZYSTKIE ślady turnusu, nie pierwszy z brzegu — wielodniówka ma jeden
  na dzień) + `tracksAreActual` (czy to zapis przejazdu, czy tylko trasa planowana; widok
  mówi o nich inaczej). Poza tym: skład z ukrytymi liczonymi bez imion, `newCells`
  (Discovery), `firstTimers`, `newPairs`.
- [`RiderProfileResource`](../core/Resources/RiderProfileResource.php) — sygnatura, regiony,
  peleton (z `shared_cells` z Discovery), „jedzie na". **Dwie rozłączne listy linii na mapę**:
  `tracks` (ślady z odbytych wyjazdów — `EditionTrack::effectiveForUser`, to samo źródło
  co pola odkryć) i `plannedTracks` (trasy zapowiadane wyjazdów BEZ śladu, deduplikowane po
  URL-u pliku, bo cykliczna ustawka ma ten sam GPX w każdym terminie). Mylenie tych dwóch
  było źródłem niespójności profilu z mapą odkryć — patrz [`features.md`](features.md).

## Reguła praktyczna

Dodajesz pole do eventu widoczne na stronie i edytowalne w formularzu → dotykasz zwykle:
`events` (kolumna, migracja) → `Event.php` (właściwość + wpis w `save()`) →
`EventResource` (wyjście na stronę) → `EventFormInput` (walidacja wejścia) →
`EventFormResource` (`empty/fromPost/fromRawEvent`) → widoki formularza + `event-page.php`.
Przykład end-to-end (telefon/e-mail zapisu zewnętrznego) → [`features.md`](features.md).
