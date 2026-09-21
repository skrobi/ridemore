# Mapa w apce bez przycisków zoomu — kontrakt implementacyjny

Zgłoszone przez usera 2026-08-29: „po stronie aplikacji mobilnej nie ma
potrzeby przycisków zoomowania mapy".

## Stan zastany (zweryfikowany 2026-08-29)

`ridemoreCreateMap()` (`assets/js/gpx-map.js:137`) to JEDYNA fabryka map
w serwisie — przechodzi przez nią KAŻDA mapa (odkrycia, profil, trasa,
wydarzenie, kronika, lista wyjazdów, picker w kreatorze, panel skarbów,
zgłoszenie skarbu). Tworzy mapę z domyślną kontrolką zoomu Leafletu
(`.leaflet-control-zoom`) i dokłada kontrolkę pełnego ekranu.

W apce te przyciski są zbędne: uszczypnięcie dwoma palcami robi to samo,
a na pełnoekranowej mapie zajmują róg, w którym i tak nic innego nie stoi.

## Rozwiązanie

**Jedna reguła CSS, nie zmiana fabryki map.**

```css
body.is-app .leaflet-control-zoom,
body.is-app .map-fs__ctrl{display:none;}
```

Uzasadnienie wyboru:

- **Nie ruszamy `ridemoreCreateMap()`.** To wspólna fabryka dla web i apki;
  parametr `zoomControl:false` przepuszczony przez nią wymagałby przekazywania
  flagi z każdego wywołania albo czytania `APP_IS_APP` w JS-ie, który dziś
  o trybie apki nic nie wie. Zasada „jedna standardowa mapa" zostaje nienaruszona.
- **Działa na KAŻDEJ mapie w apce automatycznie**, łącznie z tymi, które
  jeszcze nie powstały — tak samo jak reszta bloku `body.is-app`.
- To czysta warstwa prezentacji, zgodnie z zasadą przyjętą przy kontrakcie
  `apka-mobilna-skorupa.md`.

Miejsce: blok „TRYB APLIKACJI MOBILNEJ" w `assets/css/style.css` (ok. L3524).
Jeżeli w międzyczasie powstanie `assets/css/app.css` (Faza 3 kontraktu
skorupy) — reguła idzie tam, bez prefiksu `body.is-app`.

## CZEGO NIE USUWAĆ (poprawka do pierwszej wersji tego kontraktu)

Pierwsza wersja kazała usunąć „martwą" regułę powiększającą przyciski pod
palec. **To był błąd — sprawdzone w kodzie.** Reguła to
`.leaflet-container.leaflet-touch .leaflet-bar a{width:40px;height:40px}`
w `@media(pointer:coarse)` (L2323-2327). Bramką jest SPOSÓB OBSŁUGI, nie tryb
apki, więc obowiązuje też w przeglądarce mobilnej, i dotyczy KAŻDEJ kontrolki
w `.leaflet-bar` — m.in. przełącznika warstw, który zostaje. Reguła ZOSTAJE
nietknięta.

## Przycisk pełnego ekranu — ZATWIERDZONE przez usera 2026-08-29

`ridemoreAddFullscreenControl()` (`gpx-map.js:175`) dokłada przycisk pełnego
ekranu do każdej mapy; jego kontrolka ma klasę `.map-fs__ctrl`. W apce znika
razem z zoomem — user: „przycisk pełnego ekranu dla aplikacji mobilnej
również można usunąć". Ukrywamy na WSZYSTKICH mapach w apce, nie tylko
pełnoekranowych: apka jest już pełnym ekranem, a mapa w treści ma szufladę
albo własny ekran, do którego się przechodzi.

Chowamy tylko KONTROLKĘ. Sam mechanizm (`.map-fs`, `is-map-fs`, obsługa
Escape) zostaje — jest współdzielony z web i nic go nie woła bez przycisku.

## Zakres

- `assets/css/style.css` — jedna reguła dodana, jedna martwa usunięta.

**Bez zmian**: `gpx-map.js`, `discovery-map.js`, szablony, kontrolery, testy.

## Kryteria akceptacji

1. Mapa w apce (`curl` z UA `ridemore-app` + oględziny) nie pokazuje `+`/`−`.
2. Uszczypnięcie i podwójne stuknięcie nadal zmieniają oddalenie.
3. Web bez zmian — przyciski zoomu na miejscu na wszystkich mapach.
4. `php tests/run.php` zielone.

## Weryfikacja

`php tests/run.php` + oględziny w przeglądarce z podstawioną klasą `is-app`
(technika z Faz 2/4 `apka-mobilna-ux.md`). Gest uszczypnięcia — **wymaga
fizycznego telefonu**, jak reszta modułu.

---

## WYKONANIE — KONTRAKT ZAMKNIĘTY 2026-08-29

Reguła siedzi w `assets/css/app.css` (L136-137), w bloku z pełnym uzasadnieniem.
Z prefiksem `body.is-app`, a nie bez niego jak sugerował kontrakt — plik i tak
jedzie wyłącznie w apce (`layout-app.php`), ale wszystkie sąsiednie reguły mają
ten prefiks i wyłamanie się z tego kosztowałoby więcej niż daje.

**Oba selektory sprawdzone w kodzie, nie założone**: `.map-fs__ctrl` powstaje
w `gpx-map.js:275` (`L.DomUtil.create('div', 'leaflet-bar leaflet-control
map-fs__ctrl')`), `.leaflet-control-zoom` jest własną klasą Leafletu.

### Kryteria akceptacji

1. **SPEŁNIONE — zmierzone na żywej mapie**, nie z lektury CSS. Na
   `/odkrycia` w przeglądarce, po wstrzyknięciu `app.css` i klasy `is-app`
   (technika z audytów UX): `.leaflet-control-zoom` → `display:none`,
   `.map-fs__ctrl` → `display:none`, a `.map-layers` → `display:block`,
   czyli przełącznik warstw ZOSTAJE, tak jak wymaga sekcja „czego nie usuwać".
   Przed włączeniem trybu apki wszystkie trzy kontrolki są obecne.
2. **NIESPRAWDZONE — wymaga fizycznego telefonu.** Nic w zmianie nie dotyka
   obsługi gestów (żadnej zmiany w `ridemoreCreateMap`), więc ryzyko jest
   teoretyczne, ale gest to gest.
3. **SPEŁNIONE z zapasem.** Reguła nie leży w arkuszu wspólnym, tylko
   w `app.css`, którego przeglądarka w ogóle nie pobiera — web nie ma jak się
   zmienić, nawet gdyby prefiks `body.is-app` zniknął.
4. **SPEŁNIONE**: `php tests/run.php` → 384/384.

### Zakres — odchylenie od kontraktu

„Jedna martwa reguła usunięta" z sekcji Zakres NIE została wykonana i tak ma
zostać: sekcja „CZEGO NIE USUWAĆ" (poprawka do pierwszej wersji tego kontraktu)
unieważnia tamten punkt. `.leaflet-bar a{width:40px}` w `@media(pointer:coarse)`
jest nietknięte.
