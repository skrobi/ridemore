# Wielojęzyczność — „oryginał + tłumaczenia obok"

Branch: `feature/wielojezycznosc` (do weryfikacji przez usera, nie na produkcję
bez przeglądu). Decyzja architektoniczna z analizy 2026-09-16 (debata ekspertów,
wariant F3): **nie przebudowujemy tabel, routera, kontrolerów ani API.**

## Cel

Pełny przełącznik PL/EN: interfejs, treści z bazy (także gdy event napisano po
angielsku, a ogląda Polak), maile i pushe w języku odbiorcy, SEO dla obu wersji.
Polska wersja ma zostać **bajt w bajt** taka, jak przed zmianą.

## Założenia (decyzje, do których nie wracamy bez powodu)

1. **Język w adresie.** EN pod `/en/…`, PL bez prefiksu (adresy bez zmian).
   Prefiks obsługują dwa istniejące „jedyne miejsca": `Router::stripBasePath()`
   i `View::url()`. O języku strony decyduje ADRES. Preferencja (ciasteczko
   `rm_lang` + `users.lang`) działa wyłącznie przy wejściu na `/`, w mailach
   i pushach; przy niezgodności baner, nie przekierowanie.
2. **Teksty interfejsu:** `__('polski tekst', ['pole' => …])` + `lang/en.php`
   (zwykła tablica PHP, kluczem jest polski tekst; brak tłumaczenia → polski).
   Bez gettext, bez `ext-intl`, bez nowych zależności. Długie strony statyczne
   jako całe pliki `views/web/pages/en/*.php`.
3. **Treści z bazy:** oryginał zostaje w obecnych kolumnach. Tłumaczenia leniwie
   (pierwsze wyświetlenie w danym języku) do JEDNEJ tabeli `content_translations`
   kluczowanej `sha1(tekst) + język`; `origin` machine|human|same; człowiek
   wygrywa. Wpięte w warstwę zasobów/kontrolerów odczytu, nie w zapis.
4. **Tłumacz maszynowy za driverem** (wzorzec `Mailer`/`Push`): `log` (dev,
   testy — nic nie wychodzi na zewnątrz), `google`, `deepl`.
5. **Panel staff** (`$adminGet/$adminPost` w `admin/routes.php`) zostaje po
   polsku. Panel użytkownika pod `/admin` — tłumaczony.
6. **Flaga** `languages` w `core/config.php`: dev `['pl','en']`, prod `['pl']`
   do czasu startu (wtedy `/en/…` na prod to zwykłe 404).

## Etapy

### Etap 1 — Fundament — ZROBIONE (do weryfikacji na gałęzi `feature/wielojezycznosc`)
`Core\Lang`, `__()`/`__n()`, prefiks w routerze i `View::url()`, `head.php`
(`<html lang>`, hreflang, og:locale, słownik JS), `users.lang`, przełącznik,
wejście na `/` wg preferencji, nadpisanie widoku per język.

### Etap 2 — Interfejs ścieżki publicznej — ZROBIONE (do weryfikacji na gałęzi `feature/wielojezycznosc`)
Belka, stopka, apka, strona główna, wydarzenia, organizatorzy, trasy, regiony,
profil, przejazd, odkrycia, puls, kronika, logowanie/rejestracja, `Format`,
JS, strony statyczne.

### Etap 3 — Treści (tłumaczenie leniwe) — ZROBIONE (do weryfikacji na gałęzi `feature/wielojezycznosc`)
Migracja `content_translations`, `Utils\Translator`, `Models\ContentTranslation`,
wpięcie (eventy, etapy, cenniki, organizatorzy, trasy, skarby, emblematy),
etykieta „Przetłumaczono automatycznie · Pokaż oryginał", `noindex` przy braku
tłumaczenia, sprzątanie w cronie.

### Etap 4 — Korekta tłumaczeń — ZROBIONE (do weryfikacji na gałęzi `feature/wielojezycznosc`)
Ekran „Popraw tłumaczenie" dla uprawnionych (organizator eventu, właściciel
profilu, admin).

### Etap 5 — Panel użytkownika i formularze — ZROBIONE (do weryfikacji na gałęzi `feature/wielojezycznosc`)

### Etap 6 — Maile i push w języku odbiorcy — ZROBIONE (do weryfikacji na gałęzi `feature/wielojezycznosc`)

### Etap 7 — SEO — ZROBIONE (do weryfikacji na gałęzi `feature/wielojezycznosc`)
Alternates w sitemapach, `inLanguage` w JSON-LD, hreflang, canonical.

## Kryteria akceptacji

- [x] Polskie strony renderują się jak na `master` (54 adresy, flaga `['pl']`, 2026-09-17). Różnice wyłącznie techniczne: `__()` w skryptach/atrybutach Alpine, `confirm(&quot;…&quot;)` zamiast `confirm('…')`, `"inLanguage": "pl"` w JSON-LD eventu, skrypt słownika JS w `<head>`.
- [x] `/en/…` pokazuje interfejs po angielsku (2742 teksty). Poza zakresem świadomie: panel staff, nazwy województw/regionów, nazwy skarbów w dymkach mapy/API, opisy w rejestrze punktów.
- [x] Event w innym języku pokazuje tłumaczenie z etykietą i „Pokaż oryginał" (sprawdzone na driverze `log`; prawdziwy dostawca niezweryfikowany — brak klucza API).
- [x] Organizator może poprawić tłumaczenie; poprawka wygrywa z automatem.
- [x] Maile i pushe idą w języku odbiorcy (`users.lang`), linki z właściwym prefiksem (driver `log`).
- [x] hreflang pl/en/x-default wzajemne, canonical na siebie, alternates w sitemapach.
- [x] Callbacki OAuth, callbacki/webhooki liczników, kafle i sitemapy nigdy nie dostają prefiksu.
- [x] Trzeci język = wpis w configu + plik słownika (+ opcjonalnie `pages/{kod}/` dla stron statycznych), bez migracji.
- [x] Testy: `tests/wielojezycznosc_test.php` (10 ok); pełny zestaw 641 ok, 5 błędów — te same co na `master` (dane dev + PushNotifier).
