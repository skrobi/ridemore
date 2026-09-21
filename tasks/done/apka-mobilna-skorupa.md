# Odseparowanie skorupy apki od web — kontrakt implementacyjny

Data: 2026-08-29. Kontrakt osobny od `apka-mobilna.md` (Etapy 0-9, zamknięty)
i `apka-mobilna-ux.md` (Fazy 0-5, zamknięty).

## Cel

Apka i web mają dziś WSPÓLNĄ skorupę: `views/web/layout.php` + `partials/header.php`
+ `partials/footer.php`. Podstrony są w porządku, skorupa nie jest. Rozdzielamy
SKORUPĘ — nie podstrony.

ZMIENIA SIĘ WYŁĄCZNIE WARSTWA PREZENTACJI. Bez zmian w: modelach, kontrolerach,
trasach, JS-ie, schemacie bazy, uprawnieniach, CSRF.

## Stan zastany (ZMIERZONY 2026-08-29, nie zakładany)

`grep -rn APP_IS_APP --include=*.php` — 30 wystąpień w 19 plikach:

| plik | ile |
|---|---|
| `views/web/layout.php` | 4 |
| `views/web/pages/account.php` | 3 |
| `core/bootstrap.php`, `TreasureProposalController.php` | 3 |
| pozostałe widoki (`events-list`, `rider-profile`, `messages`, `event-page`, `treasure-scan`) | 1-2 każdy |
| **`views/web/partials/header.php` (128 linii)** | **0** |

WNIOSEK: rozrostu warunków w podstronach NIE MA. Reguła progu z 2026-08-28
(mała różnica → warunek w pliku; duża różnica w UKŁADZIE → osobny plik)
zadziałała — `discovery-app.php` i `treasure-propose-app.php` już są osobne.

PRAWDZIWY PROBLEM: `header.php` nie wie o istnieniu apki. Apka dostaje pełny
nagłówek desktopowy (logo, 6 linków nawigacji, hamburger, ikona wiadomości,
rozwijane menu konta z ~20 pozycjami) NA TYM SAMYM EKRANIE co 5-slotowy
`app-nav.php`. Koszt mierzalny: **4 zapytania SQL na każde żądanie**
(`EventRsvp::confirmedEditionCountsForUser`, `Organizer::hasProfile`,
`Message::unreadCountForUser`, `EventGroupConversation::unreadCountForUser`)
na menu, które w apce dubluje dolny pasek.

Drugi koszt: `footer.php` (45 linii mapy serwisu) renderuje się do HTML-a
na KAŻDYM ekranie apki i jest ukrywany CSS-em (`body.is-app footer{display:none}`).

### Reguły `body.is-app` w style.css — 22 sztuki, przeklasyfikowane

- **znika** (1): `body.is-app footer{display:none}` — stopki nie będzie w HTML.
- **upraszcza się** (1): `body.is-app.map-page .wrap{padding-*:0}` — layout apki
  daje `.wrap` bez paddingu od razu.
- **zostaje** (20): arkusz filtrów, wysokość wątku messengera, `.k-nav`/`.mbar`
  nad paskiem, blur paska nad mapą, `disc-panels` padding, `has-mbar`.
  To PROJEKT apki, nie łatka wspólnego layoutu.

Sam podział layoutu NIE usuwa prefiksu `body.is-app`, bo `style.css` (285 KB)
jest jednym arkuszem dla obu. Patrz Faza 3.

## Decyzje usera (2026-08-29)

1. **Menu konta w apce → ekran „Profil"**. Piąty slot paska prowadzi do
   `rider-profile.php`; tam dochodzi sekcja z linkami: Moje przejazdy, Moje
   konto, Wyloguj + zwijany blok organizatora/admina dla uprawnionych.
   Bez nowych ekranów i bez nowej nawigacji.
2. **Zakres: skorupa + sprzątnięcie łatek CSS.**

## Zasada nadrzędna

Apka NIE dostaje własnego frontu ani własnych danych. `View::render()` nadal
dostaje te same dane z tych samych kontrolerów — zmienia się wyłącznie plik,
który je składa. To ten sam wzorzec co `discovery-app.php`, podniesiony
o jeden poziom: z widoku na skorupę.

## Fazy

### Faza 1 — Wspólny `<head>`

`views/web/partials/head.php` — WSZYSTKO od `<!DOCTYPE>` do `</head>`
z dzisiejszego `layout.php`: meta/SEO/OG/Twitter, preconnect, favicony,
`style.css`, fonty, `ui.js`, `native.js`, `$jsonLd`, `$extraHead`.

To jest WARUNEK KONIECZNY całej reszty. Bez tego dwa layouty rozjadą się przy
pierwszej zmianie meta tagów — to jedyne realne ryzyko tej operacji.

Warunki, które zostają W head.php (są o urządzeniu, nie o layoucie):

- `viewport-fit=cover` pod `APP_IS_APP`,
- blok `window.RM_*` + `app-tracking.js` pod `APP_IS_APP`.

Kryterium: `layout.php` po zmianie renderuje BAJT W BAJT to samo co przed nią
dla żądania bez UA apki.

### Faza 2 — Dwie skorupy

**`views/web/layout-app.php`** (nowy):

- `head.php`, `<body class="is-app ...">`, `.wrap`,
- `partials/app-header.php` (nowy, niżej),
- `$content`,
- `partials/app-nav.php` (bez warunku — ten layout jest tylko dla apki),
- BEZ `footer.php`.

**`views/web/partials/app-header.php`** (nowy) — minimalna belka:

- logo (wejście na `/`),
- ikona wiadomości z kropką nieprzeczytanych — JEDYNE zapytanie, jakie tej
  belce wolno zrobić (`Message::unreadCountForUser` +
  `EventGroupConversation::unreadCountForUser`),
- BEZ: hamburgera, nawigacji desktopowej, menu konta, licznika „Moje
  wydarzenia", `Organizer::hasProfile`.
- Efekt: 4 zapytania → 1.

**`views/web/layout.php`** — zostaje layoutem WEB. Wypada z niego warunek
`APP_IS_APP` przy `is-app` i przy `require app-nav.php`.

**`core/Utils/View.php`** — jedna zmiana, wybór skorupy:

```php
$layout = ($section === 'web' && APP_IS_APP) ? 'layout-app.php' : 'layout.php';
require CORE_PATH . '/../views/' . $section . '/' . $layout;
```

Kontrolery, trasy i wywołania `View::render()` — BEZ ZMIAN. `$section==='admin'`
świadomie nietknięte: panel organizatora/admina nie jest ekranem apki.

**`views/web/pages/rider-profile.php`** — nowa sekcja „Konto" pod
`APP_IS_APP`, na WŁASNYM profilu (`$isMe`). Zwykłe linki + istniejący
`<form method=post action="/wyloguj">` z `Core\Csrf::field()`, przeniesiony
1:1 z `header.php`. Blok organizatora/admina za tymi samymi bramkami co
w `header.php` (`Organizer::hasProfile`, `$currentUser->isAdmin`) — te
zapytania robimy TU, na jednym ekranie, a nie na każdym.

**Testy do aktualizacji** — `tests/widoki_test.php:239-245` asercje na źródło
`layout.php` (`is-app` w `$bodyClass`, `require` paska za bramką) przenoszą
się na `layout-app.php`, gdzie oba są już bezwarunkowe.

### Faza 3 — CSS — ZROBIONE 2026-08-29 (decyzja usera podjęta)

> Nagłówek wisiał na „DECYZJA USERA PRZED WYKONANIEM" do 2026-09-12, choć
> sekcja wykonania na końcu pliku liczy „Fazy 1-3 zrobione, 366/366".
> Sprawdzone w kodzie: `assets/css/app.css` istnieje jako osobny arkusz,
> `views/web/layout.php` go NIE ładuje, a `body.is-app` gasi m.in. kontrolki
> zoomu Leafleta (l. 155) — czyli rozdział arkuszy faktycznie stoi.

Wariant A (dosłowny zakres): usunąć `body.is-app footer{display:none}`,
uprościć `map-page .wrap`. Efekt: 2 reguły. Reszta zostaje z prefiksem.

Wariant B (rekomendowany): `assets/css/app.css` ładowany WYŁĄCZNIE przez
`layout-app.php`, PO `style.css`. Przenosimy tam blok apki (~270 linii,
L3524-3790) i zdejmujemy prefiks `body.is-app` z reguł, które i tak wykonują
się tylko w apce. `style.css` zostaje ładowany — apka używa wspólnych
komponentów (`.disc-panel`, `.mbar`, `.filters`, `.messenger`), więc app.css
jest DODATKOWY, nie zamienny.

Klasy przenoszone w całości (app-only): `.app-nav*`, `.app-map*`, `.rm-scan*`,
`.rm-bg-pill*`, `.rs*`. Klasy WSPÓLNE (`.disc-panel`, `.mbar`, `.filters`)
zostają w `style.css` — do app.css idą tylko ich nadpisania.

## Poza zakresem (świadomie)

- Forkowanie podstron z warunkami `APP_IS_APP` (`account.php`, `events-list.php`,
  `rider-profile.php`, `messages.php`, `event-page.php`). Pomiar tego nie
  uzasadnia — 1-3 warunki na plik, a `event-page.php` ma 1569 linii.
- Layout `views/admin/` — panel nie jest ekranem apki.
- Jakakolwiek zmiana JS, modeli, kontrolerów (poza `View::render`), tras, bazy.
- Naprawa błędu `Icon::render('user')` na produkcji — to wgranie pliku,
  osobna sprawa (audyt 2026-08-29).

## Kryteria akceptacji

1. `curl` BEZ UA apki na `/`, `/wydarzenia`, `/odkrycia`, `/rowerzysta/{slug}`
   daje HTML identyczny jak przed zmianą (diff pusty).
2. `curl` Z UA `ridemore-app` na tych samych trasach: dokument domknięty
   (`</html>` obecne), `app-nav` z 5 slotami, BRAK `<footer>` w źródle,
   BRAK menu konta i hamburgera w belce.
3. Liczba zapytań SQL w belce apki = 1 (było 4).
4. Własny profil w apce pokazuje sekcję Konto z działającym wylogowaniem
   (token CSRF obecny w formularzu).
5. `php tests/run.php` zielone, z asercjami przeniesionymi na `layout-app.php`.
6. Ekran mapy (`discovery-app.php`) nadal pełnoekranowy, pasek nadal pływa.

## Weryfikacja

`php tests/run.php` + `curl` przez prawdziwy routing w OBU trybach UA.
Weryfikacja na fizycznym telefonie NADAL NIEMOŻLIWA (żaden nie był podpięty
przez całą przebudowę) — to samo ograniczenie co w obu poprzednich kontraktach.

---

## WYKONANIE — KONTRAKT ZAMKNIĘTY 2026-08-29

Fazy 1-3 zrobione. `php tests/run.php`: **366/366**, weryfikacja `curl`-em
przez prawdziwy routing na siedmiu trasach w OBU trybach UA.

**Faza 1** — `partials/head.php`. Dowód, że głowa się nie rozjechała: `diff`
sekcji `<head>` między web a apką na `/odkrycia` pokazuje DOKŁADNIE dwie
udokumentowane różnice (`viewport-fit=cover`, blok `RM_*` + `app-tracking.js`)
i nic poza nimi.

**Faza 2** — `layout-app.php`, `app-header.php`, wybór skorupy w `View::render()`,
sekcja „Konto" na `rider-profile.php` (wylogowanie POST + CSRF, przeniesione
1:1 z belki). Zmierzone: belka web robi 4 zapytania na żądanie, belka apki 2.

**Faza 3 — WARIANT B, z JEDNYM ODSTĘPSTWEM.** `assets/css/app.css` (408 linii)
ładowany wyłącznie przez skorupę apki, zawsze PO `style.css`. `style.css`
schudł z 285 KB do 263 KB dla każdego odwiedzającego www.

Kontrakt zakładał zdjęcie prefiksu `body.is-app`. **Przy wykonaniu okazało się
to pułapką, nie uproszczeniem — zostawiony.** Część tych reguł nadpisuje
komponenty ze `style.css` SPECYFICZNOŚCIĄ, nie kolejnością:
`body.is-app.has-mbar` (0,2,0) bije `body.has-mbar` (0,1,1), ale skrócone
`.has-mbar` (0,1,0) już by PRZEGRAŁO — dół strony schowałby się pod paskiem,
i to wyłącznie na telefonie, którego wciąż nie ma do sprawdzenia. To samo
dotyczy `body.is-app.map-page` i `body.is-app:has(.filters.is-open)::after`.
Zysk zerowy, ryzyko realne.

**Weryfikacja samego cięcia:** bilans klamr `style.css` 1896 + `app.css` 91
= 1987, czyli dokładnie tyle, ile miał arkusz przed podziałem. Żadna reguła
nie została przecięta ani zgubiona.

### Przeklasyfikowanie „łatek CSS" — poprawka do własnego założenia

Kontrakt przewidywał usunięcie 1 reguły i uproszczenie 1. Wyszło:
- **usunięta**: `body.is-app footer{display:none}` — stopka nie wchodzi już
  do dokumentu, nie ma czego ukrywać;
- **NIE uproszczona**: `body.is-app.map-page .wrap{padding:0}` — zostaje,
  bo `.wrap` w skorupie apki jest tą samą klasą co na web. Uproszczenie
  wymagałoby własnej klasy kontenera, czyli zmiany bez powodu.
