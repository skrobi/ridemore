# Ridemore – Importer eventów (rozszerzenie Chrome)

Zbiera dane o wydarzeniu rowerowym z **dowolnej strony** i wypełnia nimi
kreator dodawania eventu na `ridemore.bike/wydarzenia/nowe` — bez ręcznego
przepisywania. Panel boczny (side panel) zostaje otwarty, gdy przeglądasz
kolejne strony.

## Instalacja (rozszerzenie niepublikowane — "wgraj rozpakowane")

1. `chrome://extensions` → włącz **Tryb dewelopera** (przełącznik w prawym
   górnym rogu).
2. **Wczytaj rozpakowane** → wskaż folder `ridemore-event-importer` (ten, w
   którym jest `manifest.json`).
3. Kliknij ikonę rozszerzenia na pasku — otworzy się panel boczny. Jeśli nic
   się nie stanie, przypnij ikonę (puzzle 🧩 obok paska adresu → pinezka).

## 🧠 AI-Engine — jak to działa

Jedyny tryb automatycznego wypełniania w tej wersji. W odróżnieniu od
wcześniejszych prób (proste regexy, bezpośrednie wywołanie Claude z panelu)
ten silnik żyje w osobnym projekcie Pythona (`../ridemore/ai-engine/`, patrz
tamtejszy `README.md`) i implementuje pełny pipeline z dokumentacji
projektowej "AI Web Information Understanding Engine": zamiast parsować
gotowe pola (cena/data/tytuł), zbiera **model elementów strony** (tekst,
pozycja, rozmiar, styl, rodzic — bez interpretacji) i dopiero AI (Groq albo
lokalna Ollama, patrz niżej) po drugiej stronie decyduje, co jest czym.

Przepływ jednego kliknięcia:

1. `scripts/dom-features.js` (world: ISOLATED) skanuje AKTYWNĄ kartę — bez
   ponownego pobierania strony (przeglądarka już ją wyrenderowała), więc nie
   ma tu żadnego Playwrighta ani headless Chromium po stronie serwera.
2. Panel wysyła ten model + listę kodów słownikowych (`/api/dictionaries`)
   POST-em do lokalnego mostu na ridemore.bike: `/api/ai/engine-analyze`.
3. **PHP jest tu wyłącznie mostem** — samo JS-owe rozszerzenie nie potrafi
   odpalić procesu Pythona. `core/Utils/AiEngineBridge.php` uruchamia
   `ai-engine/analyze.py` (jeden proces na jedno żądanie, `proc_open`), ten
   woła Groq i waliduje wynik (kody spoza słownika → null, złe indeksy →
   null), więc do panelu wraca już bezpieczny, gotowy JSON.
4. Panel dokłada wynik do pustych pól (jak zawsze — nic nie nadpisuje tego,
   co już masz) i pokazuje, co dołożył.

**Endpoint działa TYLKO lokalnie, `APP_ENV=dev`** (most na produkcyjnym
ridemore.bike odpowiada 404) — to narzędzie dla organizatora-dewelopera z
uruchomionym lokalnie XAMPP + skonfigurowanym `ai-engine/` (patrz
`ridemore/ai-engine/README.md`: venv, `pip install -r requirements.txt`,
klucz Groq albo lokalna Ollama), nie funkcja produkcyjna ridemore.bike. Adres
mostu (domyślnie `http://localhost/ridemore/api/ai/engine-analyze`) zmienisz
w ⚙️ AI-Engine.

Silnik po stronie Pythona (`AI_PROVIDER` w `ai-engine/.env`) można przełączyć
między **Groq** (chmura, szybkie, ale darmowy tier ma limit tokenów/minutę)
a **Ollama** (lokalnie, bez limitu, bez klucza — wymaga `ollama serve` +
pobranego modelu). Przełączenie to sama zmiana `.env`, bez dotykania
rozszerzenia. Silnik oprócz pojedynczych pól wykrywa też **strukturę**
wydarzenia: rozkład dzień-po-dniu wielodniówki (dokładany do edytora „Plan —
dni” w panelu, do poprawienia przed wypełnieniem) i **warianty trasy**
(dokładane do listy „Warianty trasy” w panelu do przejrzenia przed
wypełnieniem — z własną ceną/zaliczką/limitem/nawierzchnią i plikiem GPX).
Pole opisu bywa **skomponowane** z zebranych faktów (trasa,
cena, miejsce zbiórki), nie tylko skopiowane ze strony, gdy źródło nie ma
gotowego opisu.

## Jak używać

1. Wejdź na stronę z wydarzeniem (Facebook, Eventbrite, Strava, klub
   rowerowy, cokolwiek) — upewnij się, że lokalny ridemore.bike (XAMPP) i
   `ai-engine/` (skonfigurowany dostawca — `GROQ_API_KEY` albo działająca
   Ollama, patrz `AI_PROVIDER` w `ai-engine/.env`) są gotowe.
2. W panelu kliknij **🧠 AI-Engine** — pola uzupełnią się tym, co
   silnik rozpozna. Jeśli domena strony pasuje do `website_url` istniejącego
   organizatora na ridemore.bike, panel to pokaże pod polem "Organizator"
   z przyciskiem **Użyj**.
3. **Region / nawierzchnia / tempo / trudność / rowery / waluta** to
   prawdziwe listy pobrane z ridemore.bike (`GET /api/dictionaries`) — Groq
   dostaje te same kody i ma zwrócić jeden z nich albo `null` (dodatkowo
   walidowane w `analyze.py`, więc kod spoza listy i tak nigdy nie trafi do
   panelu). Możesz też wybrać ręcznie z listy albo kliknąć **🎯** przy polu
   i wskazać tekst na stronie.
4. **Możesz przechodzić po podstronach tej samej strony (np. "Kontakt", "O
   nas") i klikać AI-Engine ponownie na każdej** — kolejny skan **tylko
   dokłada puste pola**, nigdy nie nadpisuje tego, co już zebrałeś/aś albo
   poprawiłeś/aś ręcznie.
5. Czego automat nie złapie — dodaj przez **🎯** przy polu: kliknij, potem
   wskaż dowolny element na stronie (tekst, link, zdjęcie), Esc anuluje.
6. Uzupełnij/popraw resztę ręcznie (cena, warianty trasy...).
7. **🚴 Wypełnij formularz na ridemore.bike** — otworzy/przełączy kartę na
   `/wydarzenia/nowe` i wstrzyknie dane do kreatora. Kreator wyląduje na
   kroku **"Podsumowanie"**.
8. **Sprawdź wszystko i dopiero wtedy kliknij Zapisz/Opublikuj sam(a)** —
   rozszerzenie niczego nie wysyła automatycznie.

## 👥 Skanuj grupę FB — posty „szukam towarzystwa” (dopisane 2026-08-20)

Drugi tryb obok AI-Engine, dla grup na Facebooku (np. rowerowych grup
lokalnych), w których ludzie piszą „szukam kogoś na przejazd". Wejście: wejdź
na grupę (facebook.com/groups/...) — musisz być **zalogowany/a na swoim
koncie FB** — i kliknij **👥 Skanuj grupę FB** w panelu. To NIE jest
scraping: `scripts/group-feed.js` zbiera **wyłącznie posty widoczne na
załadowanej stronie** (autor, treść, link) — dokładnie to, co Ty byś
przeczytał/a ręcznie. Przewiń feed, żeby zebrać więcej, i skanuj ponownie.

Jeśli auto-skan nie znajdzie postów (FB zmienia DOM między kontami/regionami
— bywa nawet ZERO `role="article"` przy normalnie wyglądającym feedzie),
komunikat błędu pokaże **diagnostykę** (ile artykułów/linków profili/bloków
tekstu ma strona), a panel proponuje **🖱️ Wskaż posty**: klikasz na stronie
grupy posty, które mają trafić na ridemore.bike (badge u góry liczy kliknięcia),
✓ Zakończ na stronie kończy zbieranie — i dalej wszystko biegnie identycznie
jak po auto-skanie. To ta sama idea co 🎯 przy polach formularza, tylko
multi-kliknięcia i ekstrakcja całego posta (autor + treść + link) zamiast
surowego elementu.

Przepływ jednego kliknięcia:

1. `scripts/group-feed.js` (world: ISOLATED) zbiera posty z feedu — bez
   pobierania strony, tylko to, co przeglądarka już wyrenderowała.
2. Panel wysyła `{mode: 'group', posts: [...]}` POST-em do tego samego mostu
   co AI-Engine (`/api/ai/engine-analyze`, dev-only) — silnik
   (`ai-engine/analyze.py`, tryb `mode='group'`) ocenia KAŻDY post, czy ktoś
   **szuka towarzystwa/chętnych na przejazd rowerowy**, uzupełnia skrótowo
   napisane posty w pola wydarzenia (tytuł, opis, region, data, godzina,
   miejsce zbiórki, dystans, tempo, trudność, rowery — kody słownikowe
   walidowane po stronie Pythona, zmyślone indeksy postów odrzucane) i pisze
   **`messageRide`** — krótki (1-2 zdania) opis jazdy „przerobiony przez AI",
   do wstawienia w stały list.
3. Panel pokazuje **listę kart postów** — autor + fragment treści + link do
   posta; posty uznane przez AI za szukanie towarzystwa mają zieloną ramkę,
   podsumowanie wykrytych pól i przyciski:
   - **📥 Użyj w formularzu** — wypełnia formularz poniżej danymi z posta
     (autor trafia w pole **Organizator** — to on ma być organizatorem
     wydarzenia na ridemore.bike; e-mail organizatora dopisujesz ręcznie,
     kreator go wymaga dla nowego organizatora). Ta sama zasada „dokładamy,
     nie nadpisujemy” co w AI-Engine: nic, co już masz w formularzu, nie
     zostanie nadpisane.
   - **💬 Czat** — otwiera nową kartę `facebook.com/messages/t/<profil>`
     z autorem posta i **wkleja gotową wiadomość do pola czatu**
     (`scripts/chat-paste.js`, world: MAIN — execCommand z tego świata odpala
     zdarzenia, które React FB odbiera). **Rozszerzenie NIGDY nie wysyła
     wiadomości samo** — wysyłasz ją Ty, Enterem, z własnego konta FB.
     Wklejenie bywa zawodne (FB zmienia DOM) — panel pokaże komunikat, a Ty
     wkleisz ręcznie (Ctrl+V).
   - **📋 Kopiuj** — skopiowanie TEJ SAMEJ gotowej wiadomości do schowka
     (navigator.clipboard, awaryjnie textarea+execCommand); FB bywa w ogóle
     niewklejalny (contenteditable Reacta), więc schowek to pewny plan B.
4. **Wiadomość do autora to STAŁY szablon** (struktura uzgodniona
   z psychologiem, 2026-08-20) — żyje w `sidepanel.js::buildAuthorMessage`
   i jest sklejany w rozszerzeniu, NIE przez AI: model dostarcza tylko
   „opis jazdy przepisany przez AI" (`messageRide`), reszta listu
   (przedstawienie ridemore.bike, prośba o zgodę, obietnica braku publikacji
   bez zgody) jest niezmienna. Ta sama wiadomość idzie i do 💬 Czat, i do
   📋 Kopiuj — obie ścieżki dają identyczny tekst.
5. **Publikację wydarzenia robisz jak zawsze ręcznie** przez
   **🚴 Wypełnij formularz na ridemore.bike** → sprawdź → Zapisz.

Ograniczenia (świadomie):

- **Tylko to, co widzisz**: posty poza ekranem nie są zbierane — przewiń i
  skanuj ponownie (kolejny skan zastępuje poprzednie wyniki, nie dubluje).
- **Struktura DOM-u FB bywa zmienna** — selektory (`[role="article"]`,
  `div[dir="auto"]`, linki profilu) to heurystyki; auto-skan próbuje DWIECH
  strategii: `role="article"`, a gdy zero trafień — linki profili autorów +
  najbliższy przodek z tekstem. Jeśli obie zawiodą, komunikat pokaże
  diagnostykę, a **🖱️ Wskaż posty** zawsze zadziała (klikasz posty ręcznie).
  Treść posta to „najdłuższy blok tekstu" w karcie — przy długich komentarzach
  bywa, że AI dostanie komentarz zamiast treści (ocena i tak zostaje przy
  Tobie).
- **Autor → Organizator**: nazwa autora z posta trafia do pola organizatora;
  e-mail organizatora (wymagany przez kreator dla nowego organizatora)
  dopisujesz ręcznie.
- **Czat**: otwieramy czat FB w nowej karcie — wymaga zalogowanej sesji FB w
  tej przeglądarce; wklejenie może się nie udać, wtedy wklejasz ręcznie.

## Tryb „🗺️ Dodaj skarb" (mapa → skarb)

Skanujesz stronę o ciekawym miejscu (Wikipedia, blog turystyczny, strona
lokalna), a rozszerzenie wyekstrahuje współrzędne, nazwę, opis i kategorię
skarbu i zaproponuje zgłoszenie na ridemore.bike.

Przepływ jednego kliknięcia:

1. Otwierasz stronę o miejscu (np. Wikipedię) w aktywnej karcie.
2. Klikasz **🗺️ Dodaj skarb** w panelu bocznym.
3. `scripts/dom-features.js` zbiera kontekst strony (tytuł, meta, elementy,
   linki, obrazy) — ten sam skrypt co AI-Engine.
4. Panel wysyła `{mode: 'treasure', ...context}` POST-em do mostu
   `/api/ai/engine-analyze` — silnik (`ai-engine/analyze.py`,
   tryb `mode='treasure'`) ekstrahuje dane skarbu:
   - `name` — nazwa (z tytułu/H1, max 160 znaków)
   - `lat/lon` — współrzędne (wymagane, accuracy: high/medium/low)
   - `description` — opis (z leadu, max 600 znaków)
   - `category` — kategoria ridemore (kod słownika: sacral/nature/landscape/
     history/culture/sport/other — z动态nie z bazy danych)
   - `hint` — wskazówka (1 zdanie, czego szukać na miejscu)
5. Panel renderuje **kartę propozycji skarbu** z danymi i przyciskami:
   - **📌 Zapisz** — otwiera `/skarby/zglos` na ridemore.bike i wypełnia
     formularz (nazwa, opis, współrzędne, kategoria) przez `treasure-fill.js`.
     Formularz jest otwarty na kroku "Miejsce" — sprawdzasz dane i klikasz
     „Zgłoś to miejsce".
   - **🔄 Skanuj ponownie** — ponownie skanuje tę samą stronę.

Ograniczenia:

- **Współrzędne wymagane** — strona bez geo-metadanych (infobox, Geohack,
  meta geo.position) nie da skarbu. AI szuka współrzędnych w wielu źródłach,
  ale jeśli ich nie ma — odrzuca propozycję.
- **Bez zdjęć** — formularz zgłoszenia skarbu nie wspiera uploadu zdjęć
  (ograniczenie formularza ridemore.bike). Zdjęcia dodajesz ręcznie po
  opublikowaniu.
- **Kategorie z bazy** — lista kategorii pochodzi z `GET /api/dictionaries`
  (tak samo jak słowniki eventowe). AI wybiera najlepszą z nich; Ty możesz
  zmienić w formularzu.
- **Jeden skarb na kliknięcie** — każdy klik „🗺️ Dodaj skarb" generuje jedną
  propozycję. Nie zbiera wielu miejsc ze strony naraz (w odróżnieniu od trybu
  grupowego, który zbiera wiele postów).

## Jak to działa (skrót techniczny)

- Kreator dodawania eventu to jeden komponent Alpine.js — rozszerzenie nie
  klika po polach, tylko wstrzykuje dane bezpośrednio do jego stanu
  (`window.Alpine.$data(...)`), więc działa niezależnie od tego, na którym
  kroku aktualnie jesteś.
- **GPX** leci przez prawdziwy endpoint `/api/gpx/parse` — te same
  dystans/przewyższenie/nawierzchnia co przy ręcznym wgrywaniu pliku.
- **Zdjęcie okładki** jest wpinane do prawdziwego `<input type=file>` przez
  `DataTransfer` (jedyny legalny sposób programowego ustawienia pliku w
  przeglądarce).
- **Region / rodzaj roweru / tempo / trudność / nawierzchnia / waluta** —
  panel pobiera prawdziwe listy z `GET /api/dictionaries` na ridemore.bike
  (jeden request, ten sam zestaw co widzi kreator — `Support::eventFormDictOptions()`
  po stronie backendu) i cache'uje je 24h w `chrome.storage.local`.
- **Organizator** — panel woła `GET /api/organizers/match-domain?domain=...`
  z domeną skanowanej strony; jeśli ktoś na ridemore.bike ma tę domenę jako
  `website_url` swojego profilu, dostajesz propozycję "Użyj" zamiast zawsze
  zakładać nowego organizatora.
- **Kolejne skanowanie tej samej strony (albo jej podstron) tylko dokłada
  puste pola** — nic, co już masz w panelu, nie zostanie nadpisane.

## Ograniczenia (świadomie zostawione na potem)

- **Dopasowanie organizatora po domenie** działa tylko, gdy profil na
  ridemore.bike ma wypełnione pole "Strona WWW". Bez trafienia — nazwa
  trafia jako propozycja nowego organizatora, wybór/potwierdzenie robisz Ty
  w kroku "Kogo".
- **Dni wielodniówki** — pole „Tytuł dnia” nie dociera do serwera (kreator nie
  ma na nie inputu; stan Alpine je niesie, ale `EventFormInput` go nie czyta)
  — tytuł zostaje tylko w edytorze panelu, reszta dnia (nocleg/posiłki/
  notatki/GPX) trafia normalnie.
- **AI-Engine wymaga lokalnej infrastruktury** (XAMPP z `APP_ENV=dev` +
  skonfigurowany `ai-engine/` — kluczem Groq albo lokalną Ollamą) — nie
  działa "z pudełka" bez tego. To celowe: most jest lokalny/dev-only, nie
  funkcja produkcyjna ridemore.bike.
- Panel nie odświeża się automatycznie przy zmianie karty — dane zostają, aż
  klikniesz "Wyczyść" albo sam(a) je nadpiszesz kolejnym skanem/wskazaniem.

## Uprawnienia

`<all_urls>` — bo strony źródłowe (i pliki GPX/zdjęcia, które bywają na
osobnej domenie/CDN) mogą być dowolne. Rozszerzenie rozmawia wyłącznie z: (a)
stroną, którą akurat skanujesz/na której klikasz 🎯, (b) lokalnym mostem
ridemore.bike (`/api/ai/engine-analyze`, tylko `APP_ENV=dev`), (c)
ridemore.bike — `/api/dictionaries`, `/api/organizers/match-domain` (odczyt
słowników/dopasowanie organizatora) i `/wydarzenia/nowe` + `/api/gpx/parse`
w kroku wypełniania, (d) w trybie grupowym — tylko Facebook (odczyt postów z
feedu, który już widzisz; otwarcie `facebook.com/messages/t/<profil>` +
wklejenie wiadomości do pola czatu — bez wysyłania). Nic nie trafia
bezpośrednio do żadnego zewnętrznego API AI z poziomu przeglądarki — klucz
Groq (jeśli używany) żyje wyłącznie po stronie Pythona; przy
`AI_PROVIDER=ollama` żadny klucz nie jest potrzebny, a ruch AI nie wychodzi
poza `localhost`.

## Struktura projektu

```
manifest.json          — konfiguracja MV3
background.js          — włącza otwieranie panelu kliknięciem ikony
sidepanel.html/.css/.js — UI i logika (AI-Engine, Skanuj grupę FB, Dodaj skarb, wskazywanie, wypełnianie)
scripts/dom-features.js — model elementów żywej strony pod AI-Engine (world: ISOLATED)
scripts/group-feed.js   — posty z feedu grupy FB pod tryb "Skanuj grupę FB" (world: ISOLATED)
scripts/chat-paste.js   — wklejenie wiadomości do czatu FB (world: MAIN)
scripts/treasure-fill.js — wypełnienie formularza /skarby/zglos pod tryb "Dodaj skarb" (world: MAIN)
scripts/fill.js         — wstrzyknięcie do kreatora ridemore.bike (world: MAIN)
icons/                  — ikony paska (placeholder, można podmienić)
```

Silnik Pythona (`ai-engine/`) żyje w osobnym katalogu obok `ridemore/` (nie
w tym repo) — patrz jego własny `README.md`.
