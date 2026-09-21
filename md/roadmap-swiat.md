# Świat Ridemore — roadmapa Etapu 8 (przyjęta 2026-08-14)

Kolejność nie jest listą życzeń, tylko **kolejnością zależności**: każdy etap
korzysta z mechaniki poprzedniego i żaden nie wymaga przebudowy tego, co przed nim.

| etap | co | stan |
|---|---|---|
| **8A** | Punkty — rejestr `point_transactions`, jedno wejście (`PointLedger`) | **gotowe** |
| **8B** | Hex / Discovery — siatka, mgła, mapa | **gotowe** |
| **8C** | Znane trasy — progi, bonusy per trasa | **gotowe** |
| **8D** | **Skarby** — punkty w terenie, QR, kolekcje | **gotowe** — patrz [`features.md`](features.md) |
| 8E | Fog of war — poziomy ujawnienia, „coś tu jest" | **działa dla skarbów**: `reveal_level` przycina pozycję w `Treasure::inBounds` |
| 8F | Misje — zadania na sezon / region | — |
| 8G | Kolekcje — „1 / 10 Bieszczadzkich Skarbów" | **częściowo**: `Treasure::collectionProgress`/`collectionsForUser` (profil, ekran po skanie) |
| 8H | Świat społeczności — skarby organizatorów, partnerów, userów | `origin` już jest |

## Co z 8D było zrobione, ZANIM zaczął się 8D

Świadomie, bo później kosztowałoby migrację danych zamiast jednej migracji schematu:

- **`TREASURE_FOUND` jako źródło punktów** w `PointLedger`. Silnik punktów nie
  będzie przebudowywany — skarb nalicza się tą samą drogą co przejazd i trasa,
  więc od pierwszego dnia widać go w „Ostatniej aktywności", w historii profilu
  i w panelu admina bez dopisywania czegokolwiek w tych trzech miejscach.
- **Schemat pod pełny model** (migr. 054 + 055): `origin` (OFFICIAL / ORGANIZER /
  PARTNER / COMMUNITY), `rarity` (COMMON / RARE / EPIC / LEGENDARY), `status`
  (PROPOSED / ACTIVE / RETIRED), `reveal_level` + `hint`, `event_id`,
  `treasure_confirmations`, `treasure_finds.method` (QR / GPS / GPX).
- **Stawki w panelu admina** — punkty i promień domyślny jako `scoring_settings`.

## Decyzje, które już zapadły i nie wracamy do nich

**Rzadkość jest ETYKIETĄ, nie wzorem.** `rarity` nie wylicza `points`. Skarb
legendarny w płaskim terenie może być wart mniej niż epicki na przełęczy —
gdyby rzadkość wyznaczała punkty, wycena przestałaby zależeć od miejsca,
a o to w tym całym module chodzi.

**Skarb społecznościowy nie trafia na mapę od razu.** Startuje jako `PROPOSED`
i wchodzi na `ACTIVE` po progu potwierdzeń (próg w `scoring_settings`) albo ręką
admina. Potwierdzenia mają WŁASNĄ TABELĘ z `UNIQUE(treasure_id, user_id)` —
licznik w kolumnie nie powstrzymałby jednej osoby przed dodaniem dziesięciu.

**Trzy drogi znalezienia mają różną wagę dowodową** i dlatego `method` istnieje
od początku: QR to dowód, że tam byłeś i patrzyłeś; GPS — że byłeś w pobliżu;
GPX — że przejechałeś obok. Różnicowanie stawek między nimi będzie możliwe bez
migracji na tabeli, która zdąży urosnąć.

**Skasowanie skarbu jest zakazane, jest `RETIRED`.** Znalezienia zostają
w historii tych, którzy zdążyli, a rejestr punktów jest niezmienny.

**Skarb znajduje się RAZ na osobę** (`UNIQUE(treasure_id, user_id)`), ale liczba
znalazców jest publiczna („znaleziony przez 47 riderów") — to social proof,
nie lista nazwisk.

## Pilotaż, nie 10 000 naklejek

Technologia gotowa od początku, skarby fizyczne stopniowo: 10–20 sztuk tam, gdzie
realnie są użytkownicy (Mielec, Bieszczady, Czorsztyn, Śląsk). Dopiero gdy widać,
że ludzie tego szukają — skalujemy. `origin` i `event_id` są po to, żeby skalowanie
nie musiało przechodzić przez nas: organizator stawia 5 skarbów na trasie i jego
wydarzenie żyje po zakończeniu imprezy.
