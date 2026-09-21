# Automatyczny importer wydarzeń rowerowych

> Kontrakt implementacyjny. Powstał z debaty pięciu ekspertów (2026-09-17).
> Zasada nadrzędna: **aplikacja jest stabilna — zmiany minimalne, reużywamy
> istniejących mechanizmów**. Zero nowych tabel, zero zmian schematu bazy.

## Cel

Automatycznie zbierać z internetu informacje o wydarzeniach rowerowych
i wprowadzać je do ridemore.bike jako **kandydatów oczekujących na weryfikację**,
przy maksymalnej automatyzacji ekstrakcji i **obowiązkowym przeglądzie przez
człowieka przed publikacją**.

## Decyzje zamknięte (użytkownik, 2026-09-17)

1. **Zawsze human-in-the-loop.** Nigdy nie ma autopublikacji. Każdy kandydat,
   niezależnie od pewności ekstrakcji, wchodzi w stanie `oczekuje_weryfikacji`
   i czeka na `approve`/`reject` w istniejącej moderacji (`/admin`).
2. **Kolejka = istniejący stan `oczekuje_weryfikacji`.** Bez nowej tabeli.
3. **Organizatorem NIGDY nie jest bot.** Import zawsze uzupełnia nazwę i e-mail
   realnego organizatora, tak żeby mógł potem przejąć wydarzenie:
   - `events.organizer_id` = **pending konto realnego organizatora** (wzorzec
     `User::createPending` + `updateName`, dokładnie jak tryb `organizer_mode='new'`
     w `EventController::create`), więc wyświetlany organizator to realny
     organizator, a przejęcie działa przez istniejący `EventController::issueClaimLink`
     (→ `/rejestracja/dokoncz`).
   - Konto-bot „Importer ridemore" jest organizatorem **wyłącznie w skrajnym
     przypadku**, gdy źródło nie podaje ANI nazwy, ANI e-maila organizatora —
     wtedy admin przypisuje realnego organizatora przy zatwierdzaniu.
   - Ślad pochodzenia (źródło, URL, pewność) idzie do `events.custom_attributes`
     (JSON — istniejąca „furtka", `schema.sql:236`).

## Zakres (co robimy)

- Serwerowy worker CLI, który dla listy adresów: pobiera stronę → ekstrahuje
  ten sam sposób co rozszerzenie (`AiEngineBridge` → `ai-engine/analyze.py`) →
  deduplikuje → tworzy kandydata w kolejce weryfikacji.
- Obsługę czwartego typu (`wyscig`) w ekstraktorze (dziś gubiony).
- Ślad pochodzenia w `custom_attributes` + jego pokazanie w moderacji.
- Deduplikację: intra-źródłowa (po URL źródła) i inter-źródłowa (po tytule+dacie
  wobec istniejących `events`).

## Poza zakresem (świadomie)

- Autopublikacja / feature flag zaufanych domen — **skreślone decyzją usera**.
- Serwerowy crawl Facebooka/Instagrama — TOS + antybot; te źródła zostają przy
  rozszerzeniu Chrome (zalogowany admin).
- Pełny harvester (RSS/sitemap/API Eventbrite/Strava Clubs) — Etap 2, po
  potwierdzeniu, że rdzeń działa.
- Pobieranie i parsowanie GPX oraz zdjęć okładkowych w workerze — admin dograva
  ręcznie po zatwierdzeniu (worker ustawia tylko link zewnętrzny do źródła).

## Mapowanie na schemat (patrz `md/database.md`, `schema.sql`)

Cztery typy w słowniku `event_type`: `ustawka`, `wycieczka_wielodniowa`,
`pokrec_z_kims`, `wyscig` (`schema.sql:1095`). Kandydat zapisuje się przez
istniejące `Models\Event::save()` (nigdy surowy INSERT), po zbudowaniu wejścia
w kształcie `Resources\EventFormInput`. Rejestracja zawsze **zewnętrzna**
(`registration_type='external'`, link = znaleziony link zapisów albo URL źródła).
Pola podatne na halucynację (min/max uczestników) **nie są mapowane** domyślnie.

## Architektura / przepływ

```
URL-e (argv/plik)  →  Utils\EventSourceFetcher::fetch()  →  payload {elements,links,images,jsonLd,meta,dictionaries}
                   →  AiEngineBridge::analyze() (analyze.py, Groq/Gemini/Ollama)  [+ knowledgeHint z AiImportLog]
                   →  AiImportLog::record()  (dziennik, istnieje)
                   →  Models\EventImport::queueCandidate()  (dedup + organizator + Event::save)
                   →  events.status = oczekuje_weryfikacji, organizer_id = realny (pending), custom_attributes = prowenancja
                   →  /admin (istniejąca moderacja: approve/reject)  →  publikacja  →  issueClaimLink → organizator przejmuje
```

## Minimalne zmiany w aplikacji

| Plik | Zmiana | Inwazyjność |
|---|---|---|
| `ai-engine/analyze.py` | `_validate` eventType: dodać `wyscig` | Python, poza prod |
| `ai-engine/providers/prompt.py` | klasyfikacja `wyscig` w `_event_type_para` + `_EXTRA_NOTES` | Python, poza prod |
| `core/Models/Event.php` | `save()` zapisuje `custom_attributes` **tylko** gdy klucz obecny w `$input` (edycja formularzem nietknięta) | 1 warunek + 1 pole |
| `core/Utils/EventSourceFetcher.php` | NOWY: HTML → payload dla analyze.py | nowy plik, izolowany |
| `core/Models/EventImport.php` | NOWY: dedup + resolveOrganizer + queueCandidate | nowy plik, izolowany |
| `import_events.php` | NOWY: worker CLI (wzorzec `cron.php`) | nowy plik, CLI-only |
| `views/web/pages/dashboard.php` (+ `Event::forDashboard`) | pokazać znacznik „z importu" + link do źródła przy kandydacie | Etap 1b, opcjonalne |
| `tests/importer_test.php` | NOWY: testy dedup/mapowania/organizatora | nowy plik |

## Etapy

- **Etap 0 — czwarty typ + ślad pochodzenia.** `wyscig` w ekstraktorze i prompcie;
  `Event::save()` warunkowo zapisuje `custom_attributes`. Bez nowego przepływu.
- **Etap 1 — worker półautomatyczny (rdzeń).** `EventSourceFetcher`, `EventImport`,
  `import_events.php`. Admin podaje URL-e → kandydaci w kolejce weryfikacji.
  Dedup intra (po URL) + inter (tytuł+data). Testy.
- **Etap 1b — widoczność w moderacji (ZROBIONE 2026-09-17).** Plakietka „z importu"
  + link do źródła + „⚠ możliwy duplikat" w `/admin` (`Event::forDashboard` niesie
  `custom_attributes`, `dashboard.php` je renderuje, CSS `.dash-item__import`).
- **Etap 2a — harvester z jednej strony-listy (ZROBIONE 2026-09-17).**
  `EventSourceFetcher::harvest()`/`extractEventLinks()` + worker `--list=<kalendarz>`
  wyłuskują adresy wydarzeń z podanej strony-listy; `--dry` pokazuje wynik bez
  modelu i bez zapisu. Rozwiązuje „skąd wziąć listę adresów" bez pełnego crawla.
- **Etap 2b — allowlista źródeł + harmonogram (ZROBIONE 2026-09-17).**
  `config event_import.sources` (zaufane kalendarze server-side) + worker
  `--sources` przechodzi po wszystkich. Harmonogram = Task Scheduler/cron woła
  `php import_events.php --sources` na maszynie z silnikiem AI (NIE prod-cron —
  most AI jest dev-only). Dedup fuzzy już jest (`findPossibleDuplicate`).
  Do zrobienia dalej: adaptery RSS/sitemap/API (Eventbrite/Strava Clubs).
- **Etap 4 — panel web `/admin/importer` (ZROBIONE 2026-09-17).** Pełne
  zarządzanie importerem z serwisu zamiast CLI: dodanie pojedynczego adresu,
  „Zbierz linki" z kalendarza (z zaznaczaniem i oznaczeniem już zaimportowanych),
  zarządzalna lista źródeł (`Models\EventImportSource` → `data/event_sources.json`),
  „Zbierz ze wszystkich źródeł", kandydaci do weryfikacji + dziennik analiz.
  `Admin\ImporterController` woła wspólny `EventImport::importUrl` (ten sam rdzeń
  co worker). Link w nawigacji panelu. Ekstrakcja dev-only (most AI) — panel mówi
  o tym wprost, gdy silnik nieaktywny.
- **Etap 3 — rozszerzenie Chrome „🔗 Zbierz linki" (ZROBIONE 2026-09-17).**
  `scripts/link-harvest.js` skanuje WYRENDEROWANY DOM (działa na SPA/JS —
  brevety.pl, FB, kalendarze na JS), wyłuskuje linki wydarzeń (heurystyka
  lustrzana do `extractEventLinks`); sidepanel listuje je, użytkownik otwiera
  każdy i odpala istniejący 🧠 AI-Engine. Manifest 0.4.0. Wymaga ręcznego
  załadowania rozszerzenia w Chrome do pełnego testu UI; logika heurystyki
  zweryfikowana na URL-ach brevety.pl (bierze `/brevet/{id}`).

## Kryteria akceptacji

1. `php import_events.php <url>` na dev tworzy wydarzenie w stanie
   `oczekuje_weryfikacji`, którego organizatorem jest realny organizator ze
   źródła (nie bot), a `custom_attributes` niesie `source_url`.
2. Ponowne uruchomienie na tym samym URL-u **nie tworzy** drugiego wydarzenia.
3. URL do wydarzenia o tytule+dacie zbieżnych z istniejącym tworzy kandydata
   **oznaczonego** jako możliwy duplikat (nie publikuje, nie pomija po cichu).
4. Zatwierdzenie kandydata w `/admin` publikuje je; `issueClaimLink` wysyła
   organizatorowi link do przejęcia.
5. `wyscig` ze strony wyścigu jest poprawnie klasyfikowany i zapisany.
6. Edycja zwykłego wydarzenia formularzem **nie kasuje** `custom_attributes`.
7. `php tests/run.php importer` — zielone.
8. `/api/ai/engine-analyze` pozostaje dev-only; worker nie wystawia nowego
   endpointu HTTP.

## Znane ograniczenia

- **Źródła renderowane JavaScriptem (SPA) — serwerowy import ich nie odczyta.**
  Przykład zweryfikowany na żywo: `brevety.pl` (cała aplikacja + strony
  `/brevet/{id}` to SPA; serwer oddaje samą skorupę, treść i linki wydarzeń
  pojawiają się dopiero po JS; dane lecą binarnym `/data/brevets.blob`).
  `EventSourceFetcher::looksJsRendered()` wykrywa taką skorupę i worker mówi
  wprost: użyj rozszerzenia Chrome (widzi wyrenderowaną stronę) albo podaj
  bezpośrednie adresy — zamiast po cichu zwracać „0". To rozgraniczenie
  „źródła łatwe (serwer) vs trudne (rozszerzenie)" z założeń projektu.
- **Facebook/Instagram/X — twardo odrzucane w imporcie serwerowym**
  (`EventSourceFetcher::isUnsupportedHost`, 2026-09-17). Zgłoszenie usera: dodanie
  adresu `facebook.com/events/...` „zepsuło importer" — FB oddaje serwerowi samą
  skorupę (tytuł, 0 linków/elementów/JSON-LD, treść za logowaniem), więc próba
  pobrania wisiała/myliła. Teraz `importUrl` i harvest odmawiają PRZED pobraniem,
  status `unsupported_source`, kierując na rozszerzenie Chrome. Dodatkowo strażnik
  rozmiaru payloadu przed wysyłką do modelu (limit `ai_engine.max_payload_bytes`).

- Ekstrakcja LLM wymaga skonfigurowanego `ai_engine` (Groq/Gemini/Ollama) +
  `proc_open` — realnie środowisko dev (jak całe AI-Engine). Leg LLM/fetch nie
  jest weryfikowany testem jednostkowym (brak sieci/kluczy w CI) — testy
  pokrywają deterministyczny rdzeń PHP (dedup, mapowanie, organizator, parser HTML).
