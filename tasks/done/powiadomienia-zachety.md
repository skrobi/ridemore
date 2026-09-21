# Powiadomienia, które angażują — kontrakt implementacyjny

Zgłoszenie usera 2026-09-11: „aplikacja w żaden sposób nie angażuje użytkownika,
żeby sprawdzał, co nowego pojawiło się w jego okolicy, czy ma jakieś nowe
wyzwania, nie przypomina o tym, że mam kilka punktów, a można mieć więcej.
Wtedy duża część ruchu przeniesie się do aplikacji."

---

## Zasady, na których stoi ten program

Wyszły z rozmowy i z materiału, nie z gustu. Każdy nowy typ powiadomienia ma
je spełniać, zanim powstanie.

**1. Powiadamiamy o ZDARZENIU, nie o stanie.** To jest najważniejsza zasada
i powstała z zastrzeżenia usera do mojej pierwszej propozycji. „Zostały Ci
3 pola z 42" opisuje STAN — będzie prawdziwy jutro, za miesiąc i za rok, więc
każdy system oparty na stanie wymaga doklejonego cooldownu i i tak kiedyś
zaspamuje. „Zbliżyłeś się dzisiejszym przejazdem" i „pojawiła się nowa trasa"
to zdarzenia: zdarzają się raz, więc deduplikacja wychodzi z natury wyzwalacza,
a nie z licznika dni.

**2. Nie prosimy o rzecz nierozsądną.** Drugie zastrzeżenie usera do tamtej
propozycji: trzy brakujące pola mogą leżeć 60 km od domu albo być rozsiane po
całej trasie. Wtedy „dokończ" znaczy „przejedź trasę drugi raz", człowiek to
wie, i przestaje ufać kolejnym powiadomieniom. Zachęta ma być przyczepiona do
okazji (jesteś blisko / właśnie się zbliżyłeś), nie do czyjegoś braku.

**3. Budżet jest częścią projektu, nie ustawieniem.** Przy >6 powiadomieniach
tygodniowo ludzie są 3,4× bardziej skłonni odinstalować apkę w ciągu 30 dni niż
przy 1–2; 46% wyłącza je przy 2–5 tygodniowo, jeśli nie widzą w nich sensu.
Bierzemy dolny koniec: **2 zachęty/tydzień, 1/dobę, cisza 22:00–6:59.**

**4. Zgoda per rodzaj, bo inaczej nowa funkcja psuje starą.** Bez tego jedno
natrętne powiadomienie wyłącza przełącznik razem z wiadomościami od organizatora.

**5. Kompetencja i przynależność, nie status.** Wprost z zasady, którą user
ustalił przy punktach (2026-08-13): nagroda ma karmić poczucie kompetencji,
a nie pozycję wobec innych; nowicjusz ma móc dostać to samo co weteran.
**W programie NIE MA:** serii („streak" — mechanizm oparty na unikaniu straty,
buduje obowiązek, a w dniu zerwania wypycha ludzi), rankingów, „wyprzedził Cię
X", przypomnień o niskim stanie punktów. Prośbę usera o to ostatnie odwracamy:
nie „masz mało punktów", tylko „obok Ciebie leży konkretna rzecz warta tyle".

---

## Etap 0 — bramka  [WEB] — **ZROBIONY 2026-09-11**

Fundament. Bez niego każdy kolejny etap psuje to, co już działa.

**Co powstało:**
- `migration_081_notification_gate.sql` — `notification_log` + trzy kolumny
  zgód w `user_preferences`.
- `Models\NotificationGate` — `claim()` rozstrzyga zgodę, budżet, ciszę nocną
  i powtórkę JEDNĄ operacją (`INSERT IGNORE`), bo decyzja i zapis muszą być
  atomowe: cron i żądanie HTTP potrafią trafić w tę samą sekundę.
- Wpięcie w **cztery istniejące** miejsca wysyłki: `Message::send`,
  `EventGroupConversation::postMessage`, `EventRsvp::join`,
  `PushNotifier::runTreasuresNearby`.
- `POST /api/powiadomienia/zgoda` + przełączniki w `account.php` (tylko w apce,
  tylko przy włączonym pushu).
- `tests/powiadomienia_test.php` — 7 testów.

**Decyzje warte zapamiętania:**
1. **Brak wiersza w `user_preferences` znaczy ZGODA**, nie odmowa. Nadrzędny
   przełącznik push jest już świadomym opt-inem (człowiek sam go włącza i sam
   przyznaje uprawnienie systemowe); pytanie drugi raz byłoby pytaniem o zgodę
   na zgodę. Gdyby było odwrotnie, push działałby wyłącznie dla ludzi, którzy
   kiedyś zapisali formularz preferencji JAZDY — czyli po cichu dla nikogo.
2. **Transakcyjne poza budżetem i poza ciszą.** Inaczej rozmowa na czacie
   wyczerpałaby tygodniowy limit w kwadrans, a zachęty nigdy by nie wyszły.
3. **W koncie widać tylko te rodzaje, które NAPRAWDĘ dziś wychodzą.**
   `push_progress` ma kolumnę i obsługę w bramce, ale nie ma jeszcze nadawcy,
   więc nie ma też przełącznika. W tym projekcie kilka razy okazało się, że
   tekst UI obiecywał funkcję, której kod nie realizował.
4. **Konsekwencja dla crona, opisana w `cron.php`:** cisza nocna zablokuje
   `runTreasuresNearby` w całości, jeśli crontab chodzi w nocy. To działanie
   zgodne z zamiarem, nie usterka — zachęty potrzebują własnego, popołudniowego
   przebiegu. **Do zrobienia po stronie serwera.**

**BŁĄD ZNALEZIONY PRZY PODŁĄCZANIU FCM (2026-09-11) — naprawiony.**
`Core\Push::sendFcm` wysyłał `data` zawsze, także puste. `json_encode([])`
daje w PHP LISTĘ `[]`, a FCM wymaga tam mapy `{}` — odpowiedź brzmiała
HTTP 400 „Cannot bind a list to map for field 'data'". Błąd przeleżał od
sierpnia niezauważony, bo wszystkie cztery miejsca wysyłki podają
`['url' => …]`, a pusta tablica jest WARTOŚCIĄ DOMYŚLNĄ `sendToUser()` —
czyli zaproszeniem do wdepnięcia przy pierwszym nowym typie powiadomienia.
Do tego `sendToUser()` łyka własne błędy, więc skończyłoby się to cichym
wpisem w logu. Naprawa: `data` idzie do ładunku tylko gdy niepuste. Test
w `powiadomienia_test.php` dowodzi samej pułapki (`json_encode` pustej
tablicy), nie tylko obecności warunku.

**Zweryfikowane:** `php tests/run.php` → 486/489 (trzy czerwone to znane braki
plików GPX na dysku dev). Żywo, na koncie jednorazowym z UA apki: ekran konta
renderuje dwa przełączniki, endpoint zapisuje i oddaje nowy stan, nieznana
flaga → 400, brak CSRF → 403, a bramka faktycznie odmawia skarbu przy
zgaszonej „okolicy", przepuszcza wiadomość i odrzuca jej duplikat.

---

## Etap 1a — zachęty LOKALNE (bez Firebase)  [WEB + APK] — **ZROBIONY 2026-09-11**

Wybrane tak, żeby **nie zależały od Firebase** — `@capacitor/local-notifications`
jest w apce od Etapu 6 i już obsługuje alerty o skarbach.

**1. „Jesteś blisko brakującego kawałka trasy"** — gotowe.
- `KnownRoute::gapsNearbyForUser()` + `GET /api/discovery/nearby-gaps` —
  bliźniak `nearby-treasures`: apka pobiera raz na prostokąt i liczy odległości
  u siebie, więc **pozycja nie wychodzi na serwer**.
- `app-tracking.js`: osobny cache, próg **600 m**, jedno powiadomienie **na
  trasę na dobę**, grupowanie po trasie (luka bywa ciągiem pól — bez tego
  przejazd wzdłuż niej sypałby alertami). Identyfikatory powiadomień
  przesunięte o 900000, żeby nie nadpisywały alertów o skarbach.
- **Odpowiedź na pytanie zostawione otwarte:** „blisko" liczymy od brakujących
  pól trasy, ale WYŁĄCZNIE z tras, które ktoś **już zaczął**. To nie jest filtr
  wydajnościowy, tylko reguła „nie prosimy o rzecz nierozsądną": pole trasy,
  której ktoś nigdy nie tknął, jest po prostu polem na mapie.

**2. „Zbliżyłeś się"** — gotowe, bez powiadomienia.
- `syncProgress()` wie teraz, czy **ten** przejazd ruszył trasę (`touched_now`),
  więc trasa trafia na ekran wyniku także wtedy, gdy postęp nie przebił progu.
  Wcześniej taki przejazd nie zostawiał po sobie ani słowa.
- Ekran mówi **ile PÓL zostało**, nie procent: „zostały 3 pola" mówi coś
  o wysiłku, „97%" nie mówi nic.
- Rozróżnienie zdarzenia od stanu jest tu twarde: bez `touched_now` ekran
  powtarzałby „masz 97%" po każdej jeździe, czyli dokładnie to, czego ten
  program nie robi.

## Etap 1b — „nowość w Twojej okolicy"  [WEB] — **ZROBIONY 2026-09-11**

`PushNotifier::runNewInRegion()` w `cron.php`: nowa znana trasa albo nowo
opublikowany wyjazd w regionie, w którym masz odkryte pola. Klucz deduplikacji
to `kr:{id}` / `ev:{id}`, więc ta sama nowość nie wróci nigdy — także gdy okno
świeżości złapie ją ponownie.

**Dwa powiadomienia, jeden typ.** `nearby_route` i `nearby_event` mają osobne
treści (trasa nie ma terminu ani składu), ale stoją na typie `nearby_new`, czyli
pod jednym wyłącznikiem i jednym budżetem — bo dla odbiorcy to jest ta sama
rzecz: „coś nowego u mnie w okolicy". Ten sam układ co `message` /
`message_group`.

**Odpowiedź na otwarte pytanie: REGION, nie promień GPS.** Region liczy się
z danych, które user sam wytworzył jeżdżąc (`discovery_cells`); promień
wymagałby przechowywania ostatniej pozycji — nowej kategorii danych osobowych
i zmiany w polityce prywatności — żeby zachęta wysyłana dwa razy w tygodniu
była o kilkanaście kilometrów dokładniejsza. Zła wymiana.

**NIE WYMAGA FCM**, wbrew pierwotnej nocie w nagłówku: od Etapu 1c kanał mailowy
dociera do każdego konta, a push jest dodatkiem dla tych, którzy mają apkę.

## Etap 1c — kanał e-mail  [WEB] — **ZROBIONY 2026-09-11**

Nie nowy typ powiadomienia, tylko **drugi transport dla typów, które już są**.
Etap 0 rozdzielił decyzję od transportu (`Core\Push` jest głupi, bramka decyduje)
— ten etap korzysta z tego rozdziału i dokłada drugą rurę obok pierwszej.

### Po co

`PushNotifier::runTreasuresNearby()` zaczyna zapytanie od `FROM push_devices pd
… WHERE pd.is_active = 1`. To znaczy, że **zachęta fizycznie nie ma dziś jak
dotrzeć do kogokolwiek bez apki** — cały program zachęt widzi ułamek bazy,
i to ten ułamek, który już jest zaangażowany. Mail odwraca proporcję: e-mail
ma każde konto (`contactFor` strzeże pustego, a rejestracja przez Stravę
dopytuje o adres, bo Strava go nie oddaje).

### Decyzje usera (2026-09-11) — kontrakt, nie sugestia

1. **Oba kanały równolegle**, nie fallback. Ta sama zachęta może pójść i pushem,
   i mailem. Konsekwencja: kanał wchodzi do klucza unikalności, bo inaczej
   pierwszy kanał „zużyłby" zdarzenie i drugi nigdy by nie wyszedł.
2. **Osobne limity na kanał**: 2/tydzień i 1/dobę **per kanał**, liczone
   niezależnie. Push i mail nie zabierają sobie miejsca.
3. **Jeden mechanizm, nie dwa.** `PreferenceNotifier` (maile o dopasowaniach)
   przechodzi pod bramkę i traci własne cooldowny 7/30 dni. Do dziś limit
   2/tydzień był deklaracją, nie faktem — bramka nie widziała maili
   o dopasowaniach, więc ten sam człowiek mógł dostać trzy zaczepki
   w tygodniu, a licznik pokazywał dwie.

### Zakres

**1. `migration_082_notification_channel.sql`**
- `notification_log` + `channel VARCHAR(10) NOT NULL DEFAULT 'push'`.
- `UNIQUE (user_id, type, dedupe_key)` → `UNIQUE (user_id, type, dedupe_key,
  channel)`. To jest sedno decyzji nr 1 i jedyny powód tej migracji.
- Indeks budżetu `(user_id, sent_at)` → `(user_id, channel, sent_at)`,
  bo licznik pyta teraz o kanał (decyzja nr 2).
- `DEFAULT 'push'` nie jest wygodą: istniejące wiersze z Etapu 0 opisują
  wyłącznie push, więc domyślna wartość mówi o nich prawdę.
- **Kolejność na produkcji: 081 przed 082.** Migracja 081 nigdzie poza dev
  jeszcze nie poszła.

**2. `Models\NotificationGate` — kanał jako parametr, nie drugi byt**
- `claim($userId, $type, $dedupeKey, $channel = self::PUSH): ?int`.
  Domyślny `push` zostawia cztery istniejące wywołania bez zmian.
- `KANALY = ['push', 'mail']`; nieznany kanał wybucha tak samo jak nieznany typ.
- Budżet liczony `WHERE channel = :channel`.
- **Cisza nocna obejmuje TYLKO push.** Mail nie budzi telefonu — skrzynka jest
  z natury asynchroniczna, a człowiek i tak zobaczy go rano. Przy okazji znika
  pułapka opisana w Etapie 0 (nocny crontab blokował cały przebieg): mailowa
  połowa wyjdzie nawet z nocnego crona.

**3. `Models\Notifier` — most, który wysyła obydwoma kanałami**
Nowa, mała klasa z jedną metodą: „wyślij TO, TEMU, kanałami, na które jest
zgoda". Woła `claim()` osobno per kanał i wysyła tylko tam, gdzie dostała id.

Dlaczego nowa klasa, a nie sześć razy ten sam kawałek w miejscach wysyłki:
miejsc jest dziś sześć (`Message::send`, `EventGroupConversation::postMessage`,
`EventRsvp::join`, `PushNotifier::runTreasuresNearby`,
`PreferenceNotifier::runRegular`, `::runAspirational`), a przy dwóch kanałach
każde z nich musiałoby powtórzyć „claim push → wyślij push → claim mail →
wyślij mail". To jest dokładnie ten sam argument, dla którego w Etapie 0
powstała bramka. Bramka dalej **decyduje** i nic nie wysyła; most **wysyła**
i nic nie rozstrzyga.

**4. Nadawcy**
- `PushNotifier::runTreasuresNearby()` — zapytanie przestaje startować od
  `push_devices` (inaczej mail nie dotrze do nikogo bez apki, czyli do celu
  tego etapu). Odbiorcą jest ten, kto ma odkryte pola w regionie skarbu; kanał
  rozstrzyga się **później**, per człowiek. Nazwa klasy zostaje, mimo że
  przestaje być ścisła — zmiana nazwy to refaktor poza zakresem (CLAUDE.md §2),
  wystarczy nota w pliku i w `md/models.md`.
- `PreferenceNotifier` — `Mailer::sendTemplate` zastąpione mostem, prywatne
  `withinCooldown()` znika razem z `REGULAR_COOLDOWN_DAYS` /
  `ASPIRATIONAL_COOLDOWN_DAYS` (decyzja nr 3). `dedupe_key` = `ev:{eventId}`,
  czyli ten sam wyjazd nie wróci — dziś wracał, bo cooldown liczył dni,
  nie rzeczy. `RecommendationLog::record` zostaje bez zmian: to osobny rejestr,
  który zasila naukę dopasowań, a nie licznik powiadomień.

**5. Wypis z maili — warunek wejścia, nie ozdoba**
Zachęta mailowa bez jednoklikowego wypisu to naruszenie art. 10 uśude i prosta
droga do filtra spamu, który zabierze ze sobą także maile transakcyjne.
- **Token deterministyczny (HMAC), nie `ActivationToken`.** Powód jest twardy:
  `ActivationToken::issueFor()` robi `UPDATE … SET used_at = NOW() WHERE
  user_id = :uid AND used_at IS NULL` — wydanie tokenu wypisu **unieważniłoby
  czekający link aktywacyjny albo reset hasła**. Do tego token wypisu musi
  działać za pół roku, a tamten ma TTL 60 minut. HMAC z `user_id` + sekretu
  (`config['notifications']['unsubscribe_key']`, wzorzec `devices.token_key`
  z env i dev-fallbackiem) nie dotyka bazy i nie wygasa.
- `GET /powiadomienia/wypisz` **pokazuje stronę z przyciskiem**, `POST` dopiero
  wypisuje. To nie jest nadmiar: w tym projekcie już dwa razy okazało się, że
  prefetchery poczty i podglądy linków w komunikatorach klikają za człowieka
  (`/skarb/{code}`, „Byłem" — oba są POST-only właśnie dlatego).
- Ten sam adres obsługuje `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
  (RFC 8058) — Gmail i Outlook wysyłają wtedy POST-a same. **Ta trasa jest
  świadomie zwolniona z CSRF**, bo POST przychodzi od dostawcy poczty, który
  nie ma sesji ani tokenu; rolę tokenu pełni HMAC w adresie, ograniczony
  do jednej flagi zgody. Zwolnienie ma być jawne i opisane w kodzie, a nie
  wynikać z przeoczenia.
- `Core\Mailer::send()` dostaje opcjonalny parametr nagłówków; `sendSmtp`
  dokleja je do bloku nagłówków, driver `log` wypisuje (żeby na dev było widać,
  co poszło).
- Stopka w `views/emails/layout.php` pokazuje link **tylko dla zachęt**
  (opcjonalne `$unsubscribeUrl`). Maile transakcyjne — reset hasła, potwierdzenie
  płatności — zostają bez niego; „wypisz się" pod potwierdzeniem wpłaty jest
  mylące i nie ma czego wypisywać.

**6. Konto (`account.php`)**
- Przełączniki rodzajów przestają zależeć od `if (!empty($pushEnabled))` —
  dziś człowiek bez apki nie ma gdzie wyłączyć czegoś, co zaraz zacznie
  dostawać. Nagłówek sekcji przestaje mówić „push".
- Jeden przełącznik kanału mailowego („Przysyłaj mi to też mailem"), nie
  macierz 3 typy × 2 kanały. Skrzynkę wycisza się w całości, nie po kawałku.
- `notify_matches` zostaje jako dzisiejsza zgoda na maile o dopasowaniach.

**7. Testy** — rozszerzenie `tests/powiadomienia_test.php`:
budżety per kanał nie mieszają się; to samo zdarzenie wychodzi raz pushem i raz
mailem, ale nie dwa razy tym samym kanałem; cisza nocna nie dotyka maila;
nieznany kanał wybucha; HMAC wypisu odrzuca podmieniony `user_id`;
GET nie wypisuje, POST wypisuje.

**8. Dokumentacja** — `md/database.md` (migr. 082), `md/models.md` (bramka +
most + zmiana w `PreferenceNotifier`), `md/routing.md` (trasa wypisu),
`md/architecture.md` (nagłówki w `Mailer`), `md/features.md`.

### Rozstrzygnięte przed kodem

**Zgoda jest OSOBNA PER KANAŁ** (decyzja usera 2026-09-11, wbrew mojej
propozycji jednego nadrzędnego wyłącznika maila). Trzy nowe kolumny
`mail_messages` / `mail_nearby` / `mail_progress` obok pushowych. Uzasadnienie,
które się broni: push przerywa dzień, mail czeka w skrzynce — można chcieć
wiedzieć o nowym skarbie z maila i nie chcieć zaczepki w trakcie jazdy.
Ekran konta pokazuje po DWA przełączniki na kanał, nie sześć: `*_progress`
nie ma nadawcy, więc nie ma i przełącznika (ta sama zasada co w Etapie 0).

**Domyślnie WŁĄCZONE, tak jak pushowe — to jest założenie, nie ustalenie.**
Przy domyślnym `0` kanał mailowy nie istniałby dla nikogo, kto sam go nie
włączy, czyli cel etapu (zasięg) nie zostałby osiągnięty. Chroni więc nie
domyślna odmowa, tylko budżet, wypis jednym kliknięciem i `List-Unsubscribe`.
**`notify_matches` zostaje przy `0`** — jest starsza i zebrana świadomie.
Odwrócenie domyślnej wartości to jedna linia w migracji 082, gdyby user wolał
inaczej.

### Co powstało i co zweryfikowano

- `migration_082_notification_channel.sql` — **uruchomiona na dev**, sprawdzona
  `SHOW INDEX` (kanał jest czwartą kolumną `uniq_notification`, indeks budżetu
  to `(user_id, channel, sent_at)`).
- `NotificationGate` — kanał jako parametr, budżet per kanał, cisza nocna
  zawężona do pusha, `DOPASOWANIE` tylko w kanale mailowym, wypis na HMAC.
- `Models\Notifier` — most; `Core\Mailer` przyjmuje nagłówki (czyszczone z CR/LF).
- Nadawcy: `PushNotifier` (zapytanie już NIE startuje od `push_devices`),
  `PreferenceNotifier` (bez własnych cooldownów), trzy miejsca maili
  o wiadomościach w `MessageController`.
- `UnsubscribeController` + `GET/POST /powiadomienia/wypisz` + widok
  `unsubscribe.php`; stopka w `views/emails/layout.php`; szablon
  `treasure-nearby.php`; sekcja „Powiadomienia mailem" w koncie.
- Style `.acc-notif` **przeniesione z `app.css` do `style.css`** — na www
  app.css się nie ładuje, więc lista byłaby nieostylowana.
- Testy: `powiadomienia_test.php` +10, `push_test.php` +2 (w tym ten, który
  broni sedna etapu: „skarb dociera MAILEM do kogoś bez apki").
  **`php tests/run.php` → 498/501**; trzy czerwone to znane braki plików GPX
  na dysku dev, sprzed tej zmiany.
- Żywo w przeglądarce: poprawny link wypisu gasi DOKŁADNIE jedną flagę
  (sprawdzone w bazie), podrobiony podpis pokazuje „ten link już nie działa",
  **dwa wejścia GET nie zmieniły niczego**, a przełącznik mailowy na www
  zapisuje się AJAX-em (na koncie jednorazowym, skasowanym po teście).

**ZNALEZIONE PRZY OKAZJI, NAPRAWIONE:** `tests/run.php` wysyłał PRAWDZIWE
maile. Skrzynka SMTP w `core/config.php` jest ta sama na dev i na prod, więc
każdy przebieg testów pukał do serwera pocztowego z adresami z fixture'ów —
`storage/mail-error.log` ma takie próby od 2026-08-05, czyli na długo przed
tym etapem. Odbijały się wyłącznie dlatego, że fixture'y mają domeny
`@example.com`. Uruchamiacz ustawia teraz `MAIL_DRIVER=log` **przed**
bootstrapem (`APP_CONFIG` jest stałą, później już za późno), a `config.php`
czyta driver z env — ta sama zasada, co przy pushu.

### Czego ten etap NIE robi

Nie rusza treści maili transakcyjnych, nie zmienia `MatchEngine`, nie dotyka
`RecommendationLog`, nie dokłada typów powiadomień (1a i 1b zostają jak były)
i nie wypełnia `opened_at` dla maila — piksel śledzący to osobna decyzja
o prywatności, a nie szczegół implementacyjny.

## Etap 1d — panel zarządzania + edycja treści  [WEB] — **ZROBIONY 2026-09-11**

Zgłoszenie usera zaraz po kanale e-mail: „czy jest gdzieś miejsce do zarządzania
powiadomieniami — jakie, w jakim czasie, jak często". Nie było. Budżet, cisza
nocna i okno świeżości siedziały w stałych `NotificationGate`, a PORA wysyłki
nawet nie w aplikacji — w crontabie na serwerze, przez co nocny wpis po cichu
wycinał pushową połowę zachęt.

**Co powstało:**
- `migration_083_notification_settings.sql` + `Models\NotificationSettings` —
  wzorzec z `ScoringSettings` (migr. 053): kod trzyma domyślne, zakresy
  i uzasadnienia, tabela wyłącznie to, co admin realnie zmienił.
- `/admin/powiadomienia` (`Admin\NotificationsController` + widok
  `notifications-admin.php`, link w nagłówku i na pulpicie).
- 17 parametrów: budżety per kanał, cisza nocna, okno wysyłki, okno świeżości,
  wyłączniki dwóch kanałów i sześciu rodzajów.
- Druga połowa ekranu to DZIENNIK: ile poszło i ile otwarto (7 i 30 dni) plus
  ostatnie 25 wysyłek. `notification_log` zbierał to od Etapu 0 i do dziś nikt
  tego nie pokazywał — bez tej odpowiedzi strojenie budżetu byłoby zgadywaniem.
- Testy: `powiadomienia_test.php` +9 (razem 27 w tym pliku).

**Trzy decyzje, które warto pamiętać:**
1. **Wartość równa domyślnej NIE jest zapisywana.** Wyszło to dopiero na żywym
   teście: pierwszy zapis formularza zrobił nadpisania ze WSZYSTKICH 17
   parametrów, bo pole liczbowe zawsze coś zawiera. Psuło to całą własność
   wzorca — panel pokazywał „zmienione" przy każdym polu, a przyszła zmiana
   domyślnej w kodzie nie dotarłaby już do nikogo, kto raz kliknął „Zapisz".
2. **Wyłącznik rodzaju działa PRZED dziennikiem.** Gdyby wpis w
   `notification_log` powstawał mimo wyłączenia, deduplikacja uznałaby te
   powiadomienia za wysłane i po ponownym włączeniu nikt by ich nie dostał.
   Osobny test pilnuje właśnie tego.
3. **Okno wysyłki obejmuje oba kanały, cisza nocna tylko push.** Cisza
   odpowiada na „czy wolno teraz budzić", okno na „czy to jest pora, w której
   w ogóle zaczepiamy" — i tej drugiej odpowiedzi mail nie ma powodu mieć innej.

**TREŚCI — DOŁOŻONE TEGO SAMEGO DNIA** (migr. 084), na prośbę usera „dodaj
edycję treści wiadomości, żebym mógł zobaczyć". Zrobione dokładnie tak, jak
zapowiadałem, że musiałoby być zrobione bezpiecznie:
- `Models\NotificationTexts` — 16 tekstów (tytuły i treści pushy, tematy maili,
  pierwsze zdanie maila o skarbie), każdy z **własną białą listą znaczników**.
- **Treść o skarbie ma dwa warianty: `…jawny` i `…ukryty`**, a wybiera je KOD
  na podstawie poziomu ujawnienia. Wariant „ukryty" NIE MA `{nazwa}` na liście,
  więc nazwa nieodkrytego skarbu nie ma jak w nim wylądować.
- **Dwa zamki, nie jeden:** `set()` wycina niedozwolony znacznik przy zapisie
  (i melduje panelowi, co wyciął), a `render()` i tak by go nie podstawił.
  Osobny test wchodzi do bazy WPROST, omijając `set()`, i sprawdza, że nazwa
  dalej nie wychodzi.
- **Podgląd liczony tą samą metodą co wysyłka** (`render()` na danych
  z `PRZYKLAD`) — podgląd idący inną drogą prędzej czy później pokazywałby coś,
  czego nikt nie dostanie, a wtedy jest gorszy niż jego brak.
- HTML wycinany przy zapisie: teksty są podstawiane jako zwykły tekst
  i escape'owane w szablonach, więc `<a href>` byłby próbą, która nie ma prawa
  się udać — lepiej odciąć ją raz niż tłumaczyć w każdym szablonie.
- Szablon `treasure-nearby.php` dostaje GOTOWE pierwsze zdanie i przestał
  wiedzieć o poziomie ujawnienia — warunek został w jednym miejscu.

**PRZEBUDOWA PO UWADZE USERA (ta sama sesja).** Pierwsza wersja edytora miała
wszystkie treści w osobnej sekcji na dole ekranu i czysty tekst zamiast HTML-a.
User odrzucił to wprost: „ta forma pod spodem jest nieakceptowalna, nie da się
tym zarządzać". Miał rację — żeby zobaczyć, czego dotyczy edytowane pole,
trzeba było skakać po stronie. Co się zmieniło:

- **Treść mieszka przy rodzaju powiadomienia.** Jeden wiersz = przełącznik,
  opis, przycisk „Treść" z ikonką, a pod nim rozwijany edytor. Sekcja „Rodzaje
  powiadomień" jest teraz jedynym miejscem, w którym się o tym decyduje.
- **Mail ma temat I treść, treść jest HTML-em** z podglądem odświeżanym przy
  pisaniu. Pierwszy podgląd renderuje serwer tą samą metodą co wysyłka, więc
  to, co widać po wejściu na stronę, jest prawdą; JS tylko go odświeża.
- **BLOKI jako parametry** — `{przycisk}`, `{mapa}`, `{cytat}`, `{powitanie}`,
  `{powod}`. To gotowy HTML składany przez KOD; admin decyduje, gdzie stanie
  i czy w ogóle. Znaczniki wstawia się klikiem w miejsce kursora, więc nikt nie
  przepisuje ich ręcznie (i nie myli się w nazwie).
- **Rozróżnienie DANA vs BLOK jest fundamentem bezpieczeństwa**: dane zawsze
  przez `htmlspecialchars` (cudza nazwa wyjazdu nie wniesie własnego HTML-a),
  bloki bez escape'owania (pochodzą od nas).
- `views/emails/pages/custom.php` — jeden szablon dla wszystkich maili
  z bramki, bez własnej treści i logiki. Zastąpił `new-message`,
  `match-suggestion` i `treasure-nearby` w roli nadawcy.

**DWA BŁĘDY ZŁAPANE DOPIERO PRZEZ KLIKNIĘCIE, obydwa ciche:**
1. **CRLF z `<textarea>`** — przeglądarka zwraca końce linii jako CRLF, treści
   domyślne w kodzie mają LF, więc „tekst równy domyślnemu" nigdy nie wychodziło
   prawdą i JEDEN zapis robił nadpisania ze wszystkich 16 treści naraz.
2. **Przełącznik, którego formularz nie renderuje** — rodzaje bez nadawcy
   (`nearby_new`, `progress`) nie mają wiersza na ekranie, a niezaznaczony
   checkbox i checkbox NIEISTNIEJĄCY wyglądają w POST identycznie. Pierwszy
   zapis je wyłączał; dowiedzielibyśmy się o tym dopiero w dniu, w którym
   dostaną nadawcę. Naprawione ukrytym znacznikiem obecności pola.

Przy okazji: **uruchamiacz testów czyści teraz cache modeli po każdym teście**.
Transakcja cofa wiersze, ale statyczny `$cache` zostaje — kolejny test widział
cudzy budżet i padał bez związku z tym, co sam sprawdza.

**CELE LINKÓW — poprawione po zgłoszeniu usera (2026-09-11).** „przyciski
w wiadomościach źle kierują (…) do skarbu nie kieruje tylko do odkryć (…)
Dopasowany wyjazd również kieruje do odkryć". Przyczyna była w PODGLĄDZIE, nie
w wysyłce: `blokiPrzykladowe()` miało jeden wspólny przycisk dla wszystkich
powiadomień, więc panel pokazywał cel, którego wysyłka nigdy nie używała.
Podgląd, który pokazuje co innego niż wysyłka, jest gorszy niż jego brak.

- **Podglądy są teraz per powiadomienie I per wariant.** Sprawdzone żywcem:
  zero różnic między podglądem serwerowym a odświeżonym przez JS.
- **Zapis na wyjazd** prowadzi do `/wydarzenia/{slug}/uczestnicy`, nie na
  pulpit — organizator dostaje to powiadomienie po to, żeby zobaczyć, KTO
  doszedł (zapytanie dociąga `slug`, bez tego powstawał adres z pustym polem).
- **Skarb jawny** otwiera `/odkrycia?skarb={id}` — mapę wykadrowaną na nim
  (`DiscoveryController::kadrNaSkarbie`, reużywa istniejącego `mapBounds`).
  **Skarb ukryty dostaje gołą mapę**: identyfikator w adresie byłby wskazówką,
  gdzie szukać czegoś, czego mapa celowo nie pokazuje. Ta sama zasada, która
  rządzi treścią wariantów, teraz także w adresie.
- **Dopasowanie niesie KARTĘ WYJAZDU** (`{karta}`): termin, region, dystans
  i ilu już jedzie. Sam powód kazał klikać, żeby poznać rzeczy, które
  przesądzają, czy w ogóle warto kliknąć. Dane pochodzą z pól, które
  `MatchEngine` i tak liczy do rankingu — zero dodatkowych zapytań.

**Czego dalej NIE DA SIĘ edytować:** treści maila o dopasowaniu (powstaje
automatycznie w `MatchEngine` — to zdanie o KONKRETNYM wyjeździe, nie szablon;
edytowalne jest to, co wokół niego, przez blok `{powod}`) i wyglądu samego
layoutu maila. Push o nowej wiadomości świadomie nie ma znaczników: treść
wiadomości ani nazwa nadawcy nie wychodzą na ekran blokady telefonu.

**Ograniczenie, którego kod nie sprawdzi za admina:** okno wysyłki ma sens
tylko wtedy, gdy `cron.php` odpala się częściej niż raz dziennie. Przy jednym
nocnym przebiegu okno 17–20 znaczy „nigdy". Podpowiedź przy polu mówi o tym
wprost, ale to jest rzecz do ustawienia po stronie serwera.

## Etap 2 — pomiar — **ZROBIONY 2026-09-11**

Pętla domknięta: `Notifier` wkłada `nid` (identyfikator wpisu w dzienniku) do
ładunku push → apka po tapnięciu odsyła go na `POST /api/powiadomienia/otwarte`
→ `NotificationGate::oznaczOtwarte()` wypełnia `opened_at` → panel
`/admin/powiadomienia` pokazuje otwarcia per typ i kanał (7 i 30 dni).

**Trzy decyzje warte zapamiętania:**
- **Endpoint oznacza wpis w imieniu ZALOGOWANEGO**, nie wg danych z żądania —
  cudzy `nid` nic nie zmienia (`oznaczOtwarte` sprawdza właściciela i jest
  idempotentne).
- **Odpowiedź to zawsze `{ok:true}`**, także dla nieznanego `nid`: apka nie ma
  co zrobić z błędem pomiaru, a nawigacja do treści jest ważniejsza niż
  statystyka.
- **Listener rejestruje się przy starcie apki**, nie w `registerPush()` —
  tapnięcie dotyczy powiadomień wysłanych dawno temu, także takich, które
  przyszły, zanim ten ekran się otworzył.

**Maile świadomie NIE mają licznika otwarć** — wymagałby piksela śledzącego,
czyli osobnej decyzji o prywatności. Panel pokazuje przy nich `—`, zamiast
udawać zero.

---

## Czego potrzeba od usera (poza kodem)

1. **Firebase** — projekt założony (plan Spark, FCM bez limitu i bez opłat).
   - **STRONA APKI — ZROBIONA 2026-09-11.** `google-services.json` leży
     w `app/android/app/`, nazwa pakietu w pliku zgadza się z `applicationId`
     (`bike.ridemore.app`). Zweryfikowane NIE przez obecność pliku, tylko przez
     wynik: zadanie `processDebugGoogleServices` jest w grafie gradle'a (czyli
     wtyczka faktycznie się zaaplikowała), a w `resources.arsc` zbudowanego APK
     są `google_app_id`, `gcm_defaultSenderId`, `project_id` i `google_api_key`.
     **Plik jest poza gitem** — reguła w `.gitignore` celowo BEZ wiodącego
     ukośnika, bo przy pierwszym wgraniu wylądował o katalog za wysoko
     i reguła przypięta do jednej ścieżki by go nie zasłoniła.
   - **STRONA SERWERA — ZWERYFIKOWANA NA DEV 2026-09-11.** Klucz konta
     serwisowego leży w `C:/xampp/secrets/` (poza DocumentRootem — sprawdzone
     żądaniem HTTP: 404, nie zawartość), wpięty przez `core/config.local.php`.
     **Google oddaje token OAuth** (1024 znaki, ~290 ms) — to rozstrzyga, że
     klucz, podpis JWT RS256 i zakres `firebase.messaging` są poprawne, bez
     żadnego urządzenia w grze. Wysyłka do FCM przechodzi walidację ładunku
     i odbija się DOPIERO o celowo zmyślony token urządzenia
     („The registration token is not a valid FCM registration token") —
     czyli działa wszystko poza ostatnim ogniwem, którym jest telefon.
     **DEV ZOSTAJE NA DRIVERZE `log`**: przy `fcm` pięć istniejących testów
     push przestaje przechodzić, bo sprawdzają zapis do `storage/push.log`.
     Testy pilnują więc, żeby maszyna deweloperska nie wysyłała nikomu
     prawdziwych powiadomień.
   - **NA PRODUKCJI — ZOSTAJE.** Plik konta serwisowego (Ustawienia projektu
     → Konta usługi → wygeneruj klucz prywatny) poza katalogiem serwowanym
     przez Apache, plus `driver`/`project_id`/`service_account_path`
     w `core/config.local.php` (pewniejsze na cPanelu niż zmienne
     środowiskowe — ten plik jest już poza gitem i po to istnieje).
     **Klucz konta serwisowego NIGDY nie trafia do repozytorium: to prywatny
     klucz RS256, którym `Core\Push` podpisuje JWT — kto go ma, wysyła push
     jako nasza aplikacja.**
2. **Crontab** — osobny, popołudniowy wpis dla zadań wysyłających zachęty
   (patrz nota w `cron.php`) i `APP_ENV=prod` ustawione w samym wpisie.
3. **Telefon** — cała reszta apki wciąż nie była uruchomiona na urządzeniu
   (`tasks/active/apka-przeglad-2026-09.md`, Etap 5).
