# ai-engine — silnik AI (Python)

Realizuje "Groq AI Analysis" z dokumentacji projektowej ("AI Web Information
Understanding Engine"): dostaje model elementów strony (tekst/pozycja/styl/
rodzic — zebrany przez rozszerzenie Chrome `ridemore-event-importer`, patrz
`scripts/dom-features.js` tam), nie interpretuje niczego samodzielnie, tylko
przekazuje to Groq i waliduje wynik (kody słownikowe, indeksy linków/obrazów).

**Nie jest persystentnym serwerem** (w odróżnieniu od siostrzanego narzędzia
`bikevents/viewer.py`) — `core/Utils/AiEngineBridge.php` odpala `analyze.py`
jako jednorazowy proces na każde żądanie (`proc_open`), więc nic nie trzeba
ręcznie uruchamiać. Dostępny tylko lokalnie, `APP_ENV=dev` (patrz
`api/routes.php`, trasa `/api/ai/engine-analyze`) — na produkcji (shared
hosting) endpoint zwraca 404, niezależnie od tego, czy `exec`/`proc_open` są
tam w ogóle dostępne.

## Dwa tryby wejścia: strona wydarzenia i grupa FB

`analyze.py` rozróżnia tryby po polu `mode` w payloadzie (most PHP przepuszcza
go 1:1):

- **brak `mode` (domyślnie)** — ekstrakcja wydarzenia z JEDNEJ strony:
  `provider.extract()` → `_validate()` (dokładnie tak, jak opisane niżej).
- **`mode: 'group'`** — posty z feedu grupy na Facebooku (zbierane przez
  `ridemore-event-importer/scripts/group-feed.js`, WYŁĄCZNIE to, co moderator
  widzi na ekranie): `provider.analyze_group()` → `_validate_group()`. Model
  ocenia każdy post (czy ktoś SZUKA TOWARZYSTWA na przejazd rowerowy),
  uzupełnia pola wydarzenia (kody słownikowe walidowane jak w trybie strony)
  i pisze `messageRide` — KRÓTKI opis jazdy do wstawienia w stały list do
  autora. Pełnego listu NIE pisze model: struktura listu jest uzgodniona
  z psychologiem i sklejana przez rozszerzenie (`sidepanel.js::
  buildAuthorMessage`), AI dostarcza tylko to streszczenie. Prompt/schemat:
  `GROUP_SHORT_KEYS` + `build_group_system_prompt()`/`build_group_user_content()`
  w `providers/prompt.py`. Wpis pasuje tylko z prawdziwym `postIndex` (indeks
  posta z payloadu — ten sam wzorzec bezpieczeństwa co indeksy linków).
  `AIProvider::analyze_group()` ma domyślną implementację (= `extract()`),
  więc przyszły dostawca nie MUSI nic wiedzieć o trybie grupowym.

## Instalacja

```bash
cd ai-engine
py -3.12 -m venv venv
venv\Scripts\pip install -r requirements.txt
copy .env.example .env
```

W `core/config.php` (klucz `ai_engine` w sekcji `dev`) ustaw `python_bin` na
ścieżkę do `venv\Scripts\python.exe` z powyższego kroku — most PHP woła
dokładnie ten interpreter, żeby mieć zainstalowane `requests` z
`requirements.txt`, a nie systemowego Pythona.

## Wybór dostawcy AI: Groq / Gemini (chmura) albo Ollama (lokalnie)

`AI_PROVIDER` w `.env` przełącza, kto faktycznie interpretuje stronę —
prompt/schemat są identyczne dla wszystkich trzech (`providers/prompt.py` +
`providers/treasure_prompt.py` dla trybu treasure), więc jakość zależy
wyłącznie od wybranego dostawcy/modelu.

- **`AI_PROVIDER=groq`** — szybkie (~2s), ale darmowy/on_demand tier ma
  twardy limit tokenów NA MINUTĘ (patrz "Rozwiązywanie problemów" niżej).
  Klucz: `GROQ_API_KEY` z [console.groq.com](https://console.groq.com/keys).
- **`AI_PROVIDER=gemini`** — dodane 2026-08-07. Dużo wyższe limity TOKENÓW
  (1M wejścia/65k wyjścia na `gemini-flash-latest`) niż Groq, więc payload
  nie musi być tak ucinany. UWAGA: limity LICZBY zapytań na darmowym tierze
  są nierówne między modelami na tym samym koncie i bywają bardzo wąskie
  (sprawdzone żywo: jeden model miał limit 20 zapytań, inny limit 0) — patrz
  komentarz przy `DEFAULT_MODEL` w `providers/gemini_provider.py`. Klucz:
  `GEMINI_API_KEY` z [aistudio.google.com/apikey](https://aistudio.google.com/apikey).
  Na razie (2026-08-07) to jedyny dostawca, który poprawnie odtworzył
  wartość rozbitą w DOM-ie na dwa węzły tekstowe ("4000m d+" jako "40" +
  "00m d+") na testowej realnej stronie — patrz md/features.md.
- **`AI_PROVIDER=ollama`** — liczysz lokalnie, bez limitu tokenów i bez
  klucza API. Wymaga: [Ollama](https://ollama.com) zainstalowanej i
  uruchomionej (`ollama serve`, zwykle startuje jako usługa automatycznie) +
  **przynajmniej jednego pobranego modelu**. Nie wiesz, który masz/wybrać?
  ```bash
  ollama list                    # co już jest pobrane lokalnie
  ollama pull llama3.1           # albo dowolny inny — im większy, tym lepiej
  ```                             # rozumie polecenie "zwróć tylko JSON", ale i wolniej liczy
  Wpisz DOKŁADNĄ nazwę z `ollama list` (z tagiem, jeśli jest, np.
  `llama3.1:8b`) w `OLLAMA_MODEL` w `.env`. Zła nazwa → czytelny błąd
  „Ollama nie zna modelu ...", nie ciche niepowodzenie.

  **Dobór rozmiaru modelu pod sprzęt — licz w RAM, nie tylko VRAM.** Ollama
  potrafi dogenerować z dysku (mmap), gdy model nie mieści się w RAM/VRAM,
  ale wtedy liczy się nie w minutach, a w dziesiątkach minut na jedno
  zapytanie (losowe odczyty całego pliku wag na każdy token). Orientacyjnie
  (kwantyzacja Q4_K_M, jak w domyślnych tagach biblioteki Ollama):
  `qwen3:8b` ≈ 5GB, `qwen3:14b` ≈ 9GB, `qwen3:32b` ≈ 20GB. Na maszynie z
  16GB RAM i słabszym/małym VRAM (np. 4GB) zostań przy `8b`/`14b` —
  `32b`, a zwłaszcza wyższe kwantyzacje niż Q4 (Q8_0 to już ~35GB samych
  wag dla 32B), się tam praktycznie nie da użyć.

  **Modele "rozumujące" (Qwen3, DeepSeek-R1, gpt-oss)** domyślnie generują
  tor myślenia przed odpowiedzią — [`OllamaProvider`](providers/ollama_provider.py)
  wysyła `'think': false`, bo tu zadanie to deterministyczna ekstrakcja
  (`temperature=0`), nie rozumowanie; modele bez wsparcia dla tego pola je
  po prostu ignorują.

Przełączanie nie wymaga zmian w kodzie ani w rozszerzeniu Chrome — sama
zmiana `.env` + restart nie jest nawet potrzebny (`analyze.py` czyta `.env`
na nowo przy każdym wywołaniu, bo to jednorazowy proces, patrz architektura
w nagłówku pliku).

## Struktura

```
analyze.py                 — punkt wejścia silnika AI (stdin JSON -> stdout JSON), wołany przez AiEngineBridge
garmin.py                   — punkt wejścia Garmin Connect (ten sam kontrakt), wołany przez GarminBridge
providers/base.py           — AIProviderInterface (dokumentacja projektowa, sekcja 12)
providers/prompt.py         — prompt/schemat WSPÓLNE dla wszystkich dostawców (strona + tryb grupowy)
providers/treasure_prompt.py — prompt/schemat trybu "Dodaj skarb" (mode='treasure')
providers/groq_provider.py  — Groq (OpenAI-compatible REST)
providers/gemini_provider.py — Google AI Studio / Gemini API
providers/ollama_provider.py — lokalny model przez Ollama
requirements.txt            — requests (silnik AI) + garminconnect (import z Garmina)
.env.example                — szablon (GROQ_API_KEY/GEMINI_API_KEY/OLLAMA_*)
```

## Drugi skrypt: `garmin.py` (import przejazdów z Garmin Connect)

Ten sam most (`proc_open`, JSON na stdin, jeden JSON na stdout), inny skrypt.
Woła go `core/Utils/GarminBridge.php` z ekranu „Moje przejazdy"; konfiguracja
to klucz `garmin` w `core/config.php`. Od 2026-09-04 istnieje też w sekcji
`prod` — zweryfikowane żywym logowaniem — ale z INNYM, starszym zestawem
pakietów niż tu (patrz „Uruchomienie na produkcji" niżej), bo tamten hosting
ma max Python 3.9. `ai_engine` (silnik AI importera wydarzeń wyżej) zostaje
tylko `dev`.

Komendy (pole `command` w JSON-ie wejściowym):

| komenda    | wejście                          | wyjście                          |
|------------|----------------------------------|----------------------------------|
| `login`    | `email`, `password`, `mfaCode?`  | `tokens` (JSON sesji), `displayName` |
| `list`     | `tokens`, `limit`                | `activities[]` (tylko rower), odświeżone `tokens` |
| `download` | `tokens`, `activityId`           | `gpx` (treść pliku)              |

Błędy wracają jako `{"ok": false, "code": "...", "error": "..."}`, gdzie `code`
to `auth` (dane/token odrzucone), `mfa` (potrzebny kod dwuskładnikowy), `rate`
(Garmin przyblokował), `conn`, `setup` (brak biblioteki) albo `input`.

**Czego ten skrypt NIE potrafi: dwuetapowego 2FA.** Stan logowania
dwuskładnikowego (sesja HTTP + CSRF Garmina) żyje w obiekcie klienta i nie da
się go zserializować, a proces kończy się razem z żądaniem HTTP. Kod można więc
podać wyłącznie OD RAZU (`mfaCode`), co działa dla aplikacji uwierzytelniającej,
ale nie dla kodu wysyłanego mailem — ten dociera po tym, jak proces już zakończył
logowanie odmową. Taki przypadek wraca kodem `mfa`.

**To jest klient NIEOFICJALNY.** Garmin nie ma publicznego API dla kont
osobistych (Connect Developer Program to program partnerski dla firm).
Poprzednia biblioteka (`garth`) przestała działać w marcu 2026 po zmianie
logowania; `garminconnect` przetrwał, bo podszywa się pod odcisk TLS aplikacji
Androida. Dlatego import z Garmina jest DODATKIEM do wgrywania plików GPX,
nigdy jedyną drogą — patrz `md/features.md`.

### Uruchomienie na produkcji — DZIAŁA (Namecheap, zweryfikowane 2026-09-04)

**UWAGA: na hostingu z Pythonem <3.10 (jak Namecheap tu) `ai-engine/requirements.txt`
NIE ZADZIAŁA wprost** — pinuje `garminconnect>=0.3.11`, co wymaga Pythona
≥3.12. Użyj zamiast tego `ai-engine/requirements-namecheap-py39.txt` (dokładny,
przetestowany zestaw wersji) i przeczytaj ten plik do końca — instalacja
wymaga dwóch dodatkowych kroków, których sam `pip install -r ...` nie zrobi.
Jeśli hosting MA Pythona ≥3.12 — zwykłe `pip install -r requirements.txt`
wystarczy, pomiń poniższe kroki 4-5.

1. **Hosting cPanel (np. Namecheap) — użyj „Setup Python App"**, NIE surowego
   `python3 -m venv` po SSH: to standardowy mechanizm (CloudLinux Python
   Selector) do wyboru wersji Pythona na hostingu współdzielonym, gdzie nie
   ma się roota.
   - cPanel → **Setup Python App** → **Create Application**. Wersja Pythona:
     najwyższa dostępna (3.12+ jeśli jest — wtedy zwykły `requirements.txt`
     zadziała; jeśli max to 3.9, jak u tego usera, patrz kroki 4-5 niżej).
     **Application root**: katalog `ai-engine` (tam już leżą
     `garmin.py`/`requirements.txt` po wgraniu repo). **Application URL**:
     dowolna nieużywana ścieżka — ta część nas nie interesuje (patrz niżej).
     Pola „Application startup file"/„Entry point" są WYMAGANE przez
     formularz, ale nieużywane w naszym przypadku — wpisz cokolwiek
     syntaktycznie poprawnego; appka WSGI nigdy się nie uruchomi, bo nic jej
     nie wywołuje.
   - Po utworzeniu cPanel pokazuje komendę „Enter to the virtual environment"
     w stylu `source /home/<user>/virtualenv/ai-engine/3.9/bin/activate` —
     **katalog przed `bin/activate` + `bin/python` to szukana ścieżka**
     (u tego usera: `/home/rideyvwv/virtualenv/ai-engine/3.9/bin/python`).
   - **WAŻNE: to NIE jest wdrożenie `garmin.py` jako serwisu.** Ten mechanizm
     służy WYŁĄCZNIE do wyprodukowania interpretera Pythona z zainstalowanym
     `garminconnect` — `garmin.py` dalej jest odpalany jednorazowo przez
     `proc_open` z PHP (`PythonBridge`), dokładnie jak na dev. „Application
     URL" z kroku wyżej nie serwuje `garmin.py` i nie musi (i nie powinna)
     nigdy odpowiadać niczym sensownym.
   - **Uwaga bezpieczeństwa**: trasowanie Passengera pod „Application URL"
     dzieje się na poziomie Apache/mod_passenger, NIE przez zwykły document
     root — czyli poza `ai-engine/.htaccess` (deny-all, patrz ten plik),
     które chroni resztę katalogu przed pobraniem przez HTTP. Zostaw pola
     startowe wypełnione, ale ZERO realnego kodu WSGI pod wskazanym plikiem
     (byle istniał) i nie dodawaj `passenger_wsgi.py` — inaczej ten adres
     realnie odpowiada, z pominięciem tamtej blokady.
2. Wskaż `python_bin` z kroku wyżej w konfiguracji — najpewniej przez
   **`core/config.local.php`** (nie trafia do gita, wgrywany ręcznie SFTP —
   patrz komentarz w `core/config.php`):
   ```php
   <?php
   $configs['prod']['garmin']['python_bin'] = '/home/<user>/virtualenv/ai-engine/<wersja>/bin/python';
   ```
   (alternatywa: zmienna środowiskowa `AI_ENGINE_PYTHON_BIN` — działa, o ile
   PHP na tym hostingu faktycznie widzi ją w `getenv()`, co zależy od
   handlera PHP; `config.local.php` obchodzi tę niepewność).
3. Upewnij się, że `proc_open` nie jest wpisany w `disable_functions` w
   `php.ini` tego hostingu — częsty domyślny zapis nawet tam, gdzie Python
   jest dostępny. Bez tego `Utils\GarminBridge::available()` zwraca `false`
   i sekcja po prostu się nie pokazuje (bez komunikatu o przyczynie).

**Kroki 4-5 TYLKO, jeśli max Python na hostingu to <3.10** (jak Namecheap tu —
jeśli masz ≥3.12, zwykłe `pip install -r requirements.txt` wystarczy i
pomijasz to):

4. Zainstaluj DOKŁADNIE ten zestaw (nie zwykły `requirements.txt` —
   `garminconnect>=0.3.11` się tu nie zainstaluje):
   ```bash
   source <venv>/bin/activate
   pip install --ignore-requires-python -r requirements-namecheap-py39.txt
   ```
   `--ignore-requires-python` jest konieczne: `garminconnect==0.3.2` dalej
   deklaruje w metadanych `Requires-Python >=3.10`, mimo że realnie działa na
   3.9 (deklaracja to decyzja pakietowa autora, nie faktyczna bariera —
   sprawdzone empirycznie). Bez tej flagi pip odmówi instalacji.
5. Nałóż łatkę na zainstalowany pakiet — `garminconnect==0.3.2` używa w
   adnotacjach typów składni `X | None` (PEP 604), którą Python 3.9 ocenia
   EAGERLY (od 3.10 leniwie) i wywraca się na niej `TypeError` przy imporcie.
   `from __future__ import annotations` (PEP 563) każe traktować te
   adnotacje jako tekst, nie wyrażenie — jedna linia na początku KAŻDEGO
   pliku naprawia WSZYSTKIE takie miejsca w pliku naraz:
   ```bash
   find <venv>/lib/python3.9/site-packages/garminconnect -name '*.py' -exec sed -i '1i from __future__ import annotations' {} \;
   find <venv>/lib/python3.9/site-packages/ua_generator   -name '*.py' -exec sed -i '1i from __future__ import annotations' {} \;
   ```
   Sprawdź: `python -c "import garminconnect; print('OK')"` — powinno wypisać
   `OK` bez tracebacku.
   **Ta łatka znika przy każdym `pip install --upgrade` na tym venv** —
   trzeba ją wtedy nałożyć ponownie (te same dwie komendy).

`DEVICE_TOKEN_KEY` (szyfrowanie tokenu sesji) i migracja 069
(`device_connections`/`device_activities`) muszą już istnieć na produkcji —
jeśli liczniki Polar/Wahoo tam realnie działają, oba warunki są już
spełnione, bo Garmin korzysta z tych samych tabel i klucza.

Shared hosting z CloudLinux zwykle limituje CPU/pamięć per-proces (LVE) —
jeśli logowanie kończy się „most zwrócił nieoczekiwaną odpowiedź" zamiast
czytelnego błędu Garmina, sprawdź w cPanelu **Resource Usage**: to bywa
ubity limitem proces Pythona (`curl_cffi` jest cięższy niż samo `requests`),
nie błąd w kodzie.

**Zweryfikowane żywo 2026-09-04** na koncie produkcyjnym: import bez błędu po
łatce i prawdziwe logowanie (`{"command":"login",...}`) zwróciło
`{"ok": true, ...}`. `garminconnect` NADAL jest biblioteką NIEOFICJALNĄ
(patrz wyżej) — to, że działa dziś, nie gwarantuje, że będzie działać po
kolejnej zmianie po stronie Garmina; import z GPX/FIT zostaje pierwszą,
niezależną drogą.

Ręczny test bez PHP:

```bash
echo {"command":"login","email":"...","password":"..."} | venv\Scripts\python garmin.py
```

## Trzeci skrypt: `translate.py` (tłumaczenie treści, 2026-09-17)

Tłumaczy treści użytkowników (opisy wydarzeń, bio organizatorów, trasy, skarby)
dla wersji językowych serwisu. Woła go PHP (`Utils\Translator`, driver `ai`)
przez `Utils\PythonBridge` — **w tle, z `cron.php tlumaczenia`**, nie w trakcie
renderowania strony (model odpowiada sekundami). Dostawca i klucz: te same
`AI_PROVIDER` / `GEMINI_API_KEY` / `GROQ_API_KEY` co dla `analyze.py`.

- Wejście: `{"target": "en", "texts": ["...", ...]}` (1–60 tekstów).
- Wyjście: `{"ok": true, "data": {"items": [{"text": "...", "from": "pl"}, ...]}}`.
  Brak choćby jednej pozycji = błąd całej paczki (model pogubił numerację).
- Prompt i słownik pojęć (skarb → treasure, pole → hex…): `providers/translate_prompt.py`.
  Każdy dostawca daje `complete_json(system, user)` w swojej klasie.
- **Działa na Pythonie 3.9** (produkcja) — potrzebny tylko `requests`, który jest
  już w `requirements-namecheap-py39.txt`.
- Ręczny test:
  `echo '{"target":"en","texts":["Zbiórka o 9:00 w Cisnej."]}' | venv/Scripts/python.exe translate.py`

Na produkcji: `TRANSLATE_DRIVER=ai`, klucz dostawcy w env (albo `ai-engine/.env`),
cron `APP_ENV=prod php cron.php tlumaczenia` co ~10 minut. **Prywatność:** treści
użytkowników idą do dostawcy modelu — płatny plan bez uczenia na danych i wpis
w polityce prywatności.

## Tryby wejścia

Silnik obsługuje trzy tryby, różniące się promptem i walidacją:

- **`mode` brak/default** — tryb strony (event). Ekstrakcja pól wydarzenia:
  tytuł, opis, data, trasa, warianty, organizer, GPX, itd.
- **`mode='group'`** — tryb grupy FB. Ocena postów z feedu (czy ktoś szuka
  towarzystwa), uzupełnienie pól wydarzenia i redakcja `messageRide` (krótki
  opis jazdy do stałego szablonu listu). Prompt: `build_group_system_prompt()`.
- **`mode='treasure'`** — tryb "Dodaj skarb". Ekstrakcja danych skarbu z
  kontekstu strony (np. Wikipedii): nazwa, opis, lat/lon, kategoria, hint.
  Współrzędne SĄ WYMAGANE. Prompt: `build_treasure_system_prompt()`.
  Kategorie skarbów są dynamiczne (z bazy danych ridemore.bike, nie
  hardcodowane). Plik: `providers/treasure_prompt.py`.

## Rozwiązywanie problemów

**`HTTP 413: Request too large ... tokens per minute (TPM)`** — darmowy/
on_demand tier Groq limituje tokeny na MINUTĘ, nie tylko rozmiar pojedynczego
zapytania; bardzo rozbudowane strony (dużo elementów/JSON-LD) potrafią to
przekroczyć nawet po stronie obcinającej rozmiar w `scripts/dom-features.js`
rozszerzenia (`MAX_ELEMENTS`, `MAX_TEXT_LEN`, budżet JSON-LD). Domyślny model
(`llama-3.1-8b-instant`) ma na tym tierze wyraźnie wyższy limit niż warianty
70B — jeśli mimo to łapiesz 413: poczekaj minutę (limit się odnawia), obniż
`MAX_ELEMENTS` jeszcze bardziej, albo ustaw w `.env` inny/mniejszy `GROQ_MODEL`.
**UWAGA**: "poczekaj minutę" NIE pomoże, jeśli JEDNO zapytanie samo w sobie
przekracza limit (np. limit 6000, zapytanie potrzebuje 6900) — to nie
kwestia zresetowania okna, request jest fundamentalnie za duży. Właśnie taki
przypadek (żywa strona wydarzenia z 52 linkami w nawigacji/FAQ/sponsorach)
znaleziono 2026-08-07 i to jest powód, dla którego `analyze.py` teraz
PRZYCINA `links`/`images` PO SWOJEJ stronie (`_prioritize_links()`,
`MAX_LINKS=20`) niezależnie od tego, ile wysłało `dom-features.js` — linki
"ciekawe" (gpx/pdf/regulamin/zapisy/faq) mają priorytet, reszta (nawigacja,
social media) leci pod nóż pierwsza.

**Model potrafi zignorować schemat wyjścia na długim, gęstym wejściu**
(sprawdzone żywo na realnej stronie, nie syntetycznej) — zwłaszcza modele
lokalne przez Ollama (patrz niżej). Groq w testach z tej sesji radzi sobie
ZNACZNIE lepiej niż lokalne `qwen3:8b`/`llama3.1:8b` (te dwa, każdy inaczej,
olewały schemat na tej samej stronie) — jeśli zależy Ci na maksymalnej
wiarygodności na realnych, złożonych stronach, Groq (z przyciętym payloadem,
patrz wyżej) jest na razie sprawdzoną ścieżką, nie Ollama.

## Ręczny test (bez PHP)

```bash
echo {"sourceUrl":"https://example.com","elements":[],"links":[],"images":[],"dictionaries":{}} | venv\Scripts\python analyze.py
```

Powinno zwrócić `{"ok": false, "error": "..."}` (bez elementów nie ma czego
analizować) albo, z realnym payloadem z rozszerzenia, `{"ok": true, "data": {...}}`.
