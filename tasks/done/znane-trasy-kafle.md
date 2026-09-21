# Zadanie: warstwa „Znane trasy" wyłącznie kaflami — usunięcie ładowania wektorowego

## Kontekst

Warstwa „Trasy" na mapach (`/odkrycia`, profil rowerzysty, strona trasy) jest dziś rysowana **wektorowo** z `/api/discovery/trails`, które zwraca geometrię ze **środków pól** siatki (`KnownRoute::geometryInBounds`), a nie z pliku GPX. Efekt: linia trasy to zygzak, a ludzie chcą widzieć pełny przebieg. Rozwiązanie, które już istnieje w projekcie i ma być jedynym: **kafle rastrowe** — renderer `TileRenderer` + `GpxGeometry` rysują prawdziwą geometrię GPX (klucze kafli `all`, `u-{slug}`, `e-{id}`, `ev-{id}`, `kr-{id}`, `me`; brakuje tylko klucza „wszystkie znane trasy").

## Cel

Na trzech mapach warstwa tras ma być rysowana **wyłącznie kaflami** (pełna geometria GPX, bez zygzaków). Wektorowy endpoint tras znika. Warstwa pól (heksy/mgła) **zostaje wektorowa** — to nie jest zakresem tego zadania.

## Zakres zmian

### 1. Nowy klucz kafli `kr` (wszystkie aktywne znane trasy)
`core/Models/TileSource.php`:
- `isAllowed()`: dodać `kr` do wzorca `^(all|me|u-[a-z0-9-]{1,60}|e-\d{1,12}|ev-\d{1,12}|kr-\d{1,12}|kr)$`.
- `tracks('kr')`: `SELECT gpx_url FROM known_routes WHERE is_active = 1 AND gpx_url IS NOT NULL`, przez `hashesFor()`, styl `'route'` (istnieje w `STYLES`: `#2C6B4F`, weight 4).
- `isCacheable('kr')`: true (dane publiczne, jak `kr-{id}`).
- Zaktualizować komentarz nagłówkowy klasy (lista kluczy).
- `TileCache`, panel `/admin/kafle`, `tiles.php` działają generycznie po kluczach — **bez zmian**.

### 2. Unieważnianie kafli przy zmianach tras (dziś luka — brak jakiegokolwiek)
`core/Models/KnownRoute.php` — przy każdej operacji zmieniającej trasę z `gpx_url` (utworzenie, edycja przebiegu, usunięcie, włączenie/wyłączenie `is_active`) wywołać:

```php
TileCache::invalidateTrack($hash, ['kr']);
```

gdzie `$hash = GpxGeometry::ensure(TileSource::absolutePath($gpxUrl))` (przy usuwaniu — policzyć z `gpx_url` przed skasowaniem wiersza). `invalidateTrack` kasuje tylko kafle, przez które przechodzi trasa, i podbija epokę `?v=`; kafle odbudują się na żądanie. Znaleźć dokładne metody (np. `createFromGpx`, update, `delete`, toggle — zweryfikować nazwy w kodzie).

### 3. JS — usunąć wektor, dodać kafle
`assets/js/discovery-map.js`:
- Usunąć: `trailLayer`, `drawTrails`, `dymekTrasy`, `trailPending`, `refreshTrails`, obsługę opcji `trailsEndpoint` (i wołania `refreshTrails()` w init/scheduleRefresh).
- Dodać opcję `trailsTiles` (szablon adresu z `TileCache::urlTemplate`) → warstwa przez `ridemoreAddTileLayer` (istnieje w `assets/js/gpx-map.js`, `maxNativeZoom: 14`).
- Zachować: przełącznik warstw (`layers.trails`, `map.ridemoreSetLayer('trails', …)`), stan początkowy z `options.layers.trails`.
- Zaktualizować komentarz nagłówkowy pliku („cztery zastosowania, jeden kod" itd.).

### 4. Widoki i kontrolery (zamiana `trailsEndpoint` → szablon kafli `kr`)
- `core/Controllers/DiscoveryController.php` (index): przekazać szablon kafli (`TileCache::urlTemplate(TileSource::LAYER_TRACKS, 'kr')`).
- `views/web/pages/discovery.php` (~linia 482): `trailsTiles` zamiast `trailsEndpoint`.
- `core/Controllers/RiderController.php` (~linia 92): `trails` → `tileTrails` (obok istniejących `tileTracks`/`tileHex`, linie 102–104).
- `views/web/pages/rider-profile.php` (~linia 435): j.w.
- `core/Controllers/TrailController.php` (~linia 99): j.w. (klucz `kr` — na stronie trasy własny przebieg i tak rysuje się osobno z pliku GPX przez leaflet-gpx; dublowanie z kaflami jest OK).
- `views/web/pages/trail.php` (~linia 195): j.w.
- `api/routes.php`: usunąć `GET /api/discovery/trails` (linie 98–125). `KnownRoute::geometryInBounds()` **zostaje** (wołają go testy `tests/znane_trasy_test.php` bezpośrednio — po usunięciu route nic nie pęka).

### 5. Testy
`tests/znane_trasy_test.php` — dopisać (prefix `kr_*` już istnieje):
- `TileSource::tracks('kr')` zwraca hashe aktywnych tras i styl `route`, nieaktywne nie wchodzą;
- `TileCache::epoch('slady', 'kr')` rośnie po zmianie trasy (bump przez invalidateTrack).

Uruchomić `php tests/run.php` — wszystkie zestawy muszą przejść (obecnie 95/95).

### 6. Dokumentacja
- `md/features.md` — sekcje o mapie odkryć / znanych trasach: opisy wektorowego endpointu i środków pól zastąpić opisem kafli `kr` (pełna geometria GPX).
- `md/architecture.md` — dopisać klucz `kr` tam, gdzie opisane są kafle.

## Świadoma konsekwencja (NIE implementować w tym zadaniu)

Klikalne dymki tras (nazwa, dystans, przewyższenie, link „Zobacz trasę") znikną — kafle to obrazki bez interakcji. Jeśli mają zostać, to osobne zadanie.

## Czego NIE robić

- Nie ruszać warstwy pól (heksy/mgła) ani skarbów.
- Nie usuwać `KnownRoute::geometryInBounds()` (używane przez testy i wzorcowane przez inne metody, np. komentarz przy linii 869).
- Nie wprowadzać upraszczania geometrii per zoom (osobne zadanie).

## Kryteria akceptacji

1. `/odkrycia`, profil rowerzysty, strona trasy: w Network **brak** żądań `/api/discovery/trails`; trasy rysują się jako pełne linie GPX (kafle `assets/tiles/slady/kr/…`).
2. Przełącznik „Trasy" działa jak dotąd (domyślnie włączony).
3. Dodanie/edycja/wyłączenie/usunięcie trasy w panelu odświeża jej kafle (nowa epoka `?v=`).
4. `php tests/run.php` — 100% PASS; `php -l` na zmienionych plikach.
5. Dokumentacja `md/` zaktualizowana.

---

## WYKONANIE — KONTRAKT ZAMKNIĘTY 2026-08-20

> **Sekcja dopisana 2026-09-12 z opóźnieniem** — plik leżał bez domknięcia
> i nie dało się z niego odczytać, czy praca została zrobiona. Odnalezione
> w kodzie, kryterium po kryterium:

1. **Wektor usunięty.** `GET /api/discovery/trails` nie istnieje —
   `api/routes.php` ma na jego miejscu komentarz „usunięte 2026-08-20 razem
   z wektorową warstwą tras". W `assets/js/discovery-map.js` nie ma już
   `trailsEndpoint`. **Co ZOSTAŁO i nie jest tym samym:**
   `/api/discovery/trails/at` — trafianie kliknięciem w trasę pod kursorem
   (dymek), rzecz osobna od rysowania; wołają ją `discovery.php`,
   `discovery-app.php`, `EventController` i `RideController`.
2. **Klucz kafla `kr`** stoi w `Models\TileSource` (`$key === 'kr'`).
   Od migr. 064 każda znana trasa jest w nim osobną grupą z własnym kolorem.
3. **Unieważnianie** — `KnownRoute::invalidateTiles()`, wpięte w trzy miejsca
   (edycja, podmiana pliku, kasowanie/wyłączenie).
4. Testy: `tests/znane_trasy_test.php` + `tests/diagnostyka_pustych_kafli_test.php`
   (ten drugi pilnuje, że każda AKTYWNA trasa faktycznie się narysuje, a nie
   tylko ma plik na dysku).
5. Dokumentacja: [`md/features.md`](../../md/features.md) i
   [`md/models.md`](../../md/models.md), sekcje o kaflach i o `TileSource`.

**Domyślne włączenie warstwy** i późniejsze poprawki rysowania (postrzępione
obwódki na ciasnych pętlach, 2026-08-29) poszły już poza tym kontraktem —
patrz pamięć `project_ridemore_znane_trasy_fix`.