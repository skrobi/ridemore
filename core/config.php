<?php
// core/config.php
// Konfiguracja per środowisko. Wybór: zmienna środowiskowa APP_ENV (dev|prod),
// domyślnie 'dev'. Na produkcji ustaw APP_ENV=prod w konfiguracji serwera.
//
// Sekrety NIE mają wartości zapasowych w repozytorium. Przychodzą z env albo
// z ignorowanego przez Git `config.local.php` (wzór: config.local.example.php).
// Pusta wartość wyłącza opcjonalną integrację albo powoduje czytelny błąd przy
// próbie użycia funkcji wymagającej sekretu — nigdy cichy powrót do wspólnego,
// publicznie znanego klucza.
$env = fn(string $key, string $fallback): string => (getenv($key) ?: $fallback) ?: $fallback;

// Jedna skrzynka SMTP używana i na dev, i na prod — trzymana tu raz,
// żeby dane logowania nie żyły w dwóch miejscach.
//
// `driver` z env, bo TA SKRZYNKA JEST PRAWDZIWA TAKŻE NA DEV — wysyłka
// z maszyny deweloperskiej realnie puka do serwera pocztowego. Uruchamiacz
// testów ustawia `MAIL_DRIVER=log` przed startem (patrz tests/run.php)
// i to jest jedyny powód istnienia tego przełącznika: bez niego przebieg
// testów próbuje wysłać maile na adresy z fixture'ów. Ta sama zasada,
// co przy pushu, gdzie dev zostaje na driverze `log`.
$smtp = [
    'driver'     => 'smtp',
    'host'       => $env('SMTP_HOST', 'ridemore.bike'),
    'port'       => (int) $env('SMTP_PORT', '465'),
    'encryption' => $env('SMTP_ENCRYPTION', 'ssl'),
    'username'   => $env('SMTP_USERNAME', 'david@ridemore.bike'),
    'password'   => $env('SMTP_PASSWORD', ''),
    'from_email' => $env('MAIL_FROM_EMAIL', 'david@ridemore.bike'),
    'from_name'  => $env('MAIL_FROM_NAME', 'rideMore.Bike'),
];
$mailDev  = array_replace($smtp, ['driver' => $env('MAIL_DRIVER', 'log')]);
$mailProd = array_replace($smtp, ['driver' => $env('MAIL_DRIVER', 'smtp')]);

// Logowanie społecznościowe (league/oauth2-client). Redirect URI wyliczany z
// app_url per środowisko (patrz Utils\OAuthProvider). clientId jest publiczny —
// może zostać jako fallback; SEKRETY czytamy z env (pusty fallback), NIGDY nie
// commitujemy ich w pliku. Provider bez ustawionego clientSecret jest po prostu
// wyłączony (przycisk się nie pokazuje, patrz partials/social-login.php).
$oauth = [
    'google' => [
        'clientId'     => $env('GOOGLE_CLIENT_ID', '133884512796-nkjne7pje2ctl5l5jmhnbooil7ae8fs0.apps.googleusercontent.com'),
        'clientSecret' => $env('GOOGLE_CLIENT_SECRET', ''),
    ],
    'strava' => [
        'clientId'     => $env('STRAVA_CLIENT_ID', ''),
        'clientSecret' => $env('STRAVA_CLIENT_SECRET', ''),
    ],
];

// LICZNIKI PRZEZ OAuth (migr. 069) — Polar, Wahoo, docelowo COROS i Suunto.
//
// WSPÓLNE DLA OBU ŚRODOWISK, inaczej niż `ai_engine`/`garmin`: to jest zwykły
// OAuth2 po HTTPS, więc działa wszędzie tam, gdzie działa PHP — także na
// współdzielonym hostingu, na którym most do Pythona (Garmin) nie ma szans.
//
// SEKRETY WYŁĄCZNIE Z ENV, bez fallbacków — dostawca bez kompletu kluczy jest
// po prostu wyłączony (zakładka mówi, czego brakuje, przycisk się nie pokazuje).
// Ta sama zasada co przy logowaniu społecznościowym.
//
// Gdzie założyć klienta:
//   Polar  — https://admin.polaraccesslink.com (samoobsługa, konto Polar Flow)
//   Wahoo  — https://developers.wahooligan.com/applications (wniosek do akceptacji)
// Adres powrotny MUSI się zgadzać co do znaku z Utils\DeviceApi::redirectUri():
//   {app_url}/admin/moje-przejazdy/licznik/{dostawca}/callback
// Odbiornik webhooków (automatyczny import, migr. 088) — Utils\DeviceApi::webhookUrl():
//   {app_url}/api/liczniki/{dostawca}/webhook
$devices = [
    // KLUCZ DO SZYFROWANIA TOKENÓW WSZYSTKICH LICZNIKÓW (AES-256-GCM, patrz
    // Models\DeviceConnection). Musi być stabilny i pochodzić spoza repo.
    // Rotacja wymaga ponownego połączenia liczników zapisanych starym kluczem.
    'token_key' => $env('DEVICE_TOKEN_KEY', ''),
    'polar' => [
        'clientId'     => $env('POLAR_CLIENT_ID', '9037b50f-367d-426d-9343-19da5a4671d6'),
        'clientSecret' => $env('POLAR_CLIENT_SECRET', ''),
        // AUTOMATYCZNY IMPORT (migr. 088). `signature_secret_key`, który Polar
        // oddaje JEDEN RAZ przy zakładaniu webhooka (`php polar_webhook.php`).
        // Bez niego przełącznik „dodawaj automatycznie" się nie pokazuje,
        // a odbiornik webhooka oddaje 404. Bez wartości domyślnej, jak sekrety.
        'webhookSecret' => $env('POLAR_WEBHOOK_SECRET', ''),
    ],
    'wahoo' => [
        'clientId'     => $env('WAHOO_CLIENT_ID', ''),
        'clientSecret' => $env('WAHOO_CLIENT_SECRET', ''),
        // `webhook_token` wpisany SAMODZIELNIE w panelu aplikacji Wahoo
        // (developers.wahooligan.com) razem z adresem odbiornika:
        //   {app_url}/api/liczniki/wahoo/webhook
        'webhookSecret' => $env('WAHOO_WEBHOOK_TOKEN', ''),
    ],
    // COROS i Suunto czekają na onboarding partnerski — patrz Utils\DeviceApi.
    'coros' => [
        'clientId'     => $env('COROS_CLIENT_ID', ''),
        'clientSecret' => $env('COROS_CLIENT_SECRET', ''),
    ],
    'suunto' => [
        'clientId'     => $env('SUUNTO_CLIENT_ID', ''),
        'clientSecret' => $env('SUUNTO_CLIENT_SECRET', ''),
    ],
];

// PUSH DO APKI MOBILNEJ (Etap 8, `Core\Push`, 2026-08-28) — TA SAMA zasada
// co `$smtp`/`driver` wyżej: 'log' (domyślny) pisze do storage/push.log
// zamiast wysyłać naprawdę, 'fcm' wysyła realnie przez FCM HTTP v1.
//
// BEZ WARTOŚCI DOMYŚLNYCH, jak `wahoo`/`coros`/`suunto` wyżej — dopóki nikt
// nie założy projektu Firebase i nie poda pliku konta serwisowego,
// `project_id`/`service_account_path` zostają puste i 'fcm' rzuca czytelny
// błąd (złapany i zalogowany przez Core\Push::sendToUser, nic nie wybucha
// wywołującemu). Plik konta serwisowego NIGDY nie trafia do gita — leży
// poza repo (np. `storage/firebase-service-account.json`), ścieżkę
// wskazuje env.
$push = [
    'driver' => $env('PUSH_DRIVER', 'log'),
    'fcm' => [
        'project_id'            => $env('FCM_PROJECT_ID', ''),
        'service_account_path'  => $env('FCM_SERVICE_ACCOUNT_PATH', ''),
    ],
    // APNs (iOS) — patrz Core\Push::sendApns(); świadomie niezaimplementowane
    // w tej sesji (brak Maca do zbudowania i sprawdzenia apki iOS).
    'apns' => [],
];

// Powiadomienia mailowe (Etap 1c programu zachęt, 2026-09-11).
// `unsubscribe_key` podpisuje link „wypisz się" w stopce maila (HMAC, patrz
// Models\NotificationGate::adresWypisu) — ten sam wzorzec co `devices.token_key`.
// ZMIANA TEGO KLUCZA UNIEWAŻNIA WSZYSTKIE LINKI WYPISU, które już poszły
// w świat: ktoś, kto wróci do maila sprzed miesiąca, zobaczy „link nieprawidłowy"
// zamiast móc się wypisać. Na produkcji ma przyjść z env albo z config.local.php
// i już nigdy się nie zmienić.
// Tłumaczenie treści z bazy (wielojęzyczność, tasks/active/wielojezycznosc.md).
// Driver `log` nie wychodzi do sieci — tłumaczenie to oryginał z dopiskiem
// `[EN] `, więc na dev widać, co przeszło przez tłumacza. Na produkcji
// `google`/`deepl` z kluczem z env albo `ai` (model językowy, kolejka w cronie
// `tlumaczenia`, klucz dostawcy jak dla silnika AI). UWAGA na dane: treści użytkowników
// idą do dostawcy — tylko plan, który nie uczy modeli na danych klienta,
// i wpis o procesorze w polityce prywatności.
$translate = [
    'driver'        => $env('TRANSLATE_DRIVER', 'log'),
    'google_key'    => $env('GOOGLE_TRANSLATE_KEY', ''),
    'deepl_key'     => $env('DEEPL_API_KEY', ''),
    'deepl_url'     => $env('DEEPL_API_URL', 'https://api-free.deepl.com/v2/translate'),
    'daily_chars'   => (int) $env('TRANSLATE_DAILY_CHARS', '200000'),
    'timeout'       => (int) $env('TRANSLATE_TIMEOUT', '4'),
    'pause_seconds' => 600,
    // Driver `ai`: ai-engine/translate.py przez PythonBridge; `python_bin`
    // dokłada niżej każde środowisko (inna ścieżka venv na Windows i Linuksie).
    // Dostawca i klucz: AI_PROVIDER + GEMINI_API_KEY/GROQ_API_KEY (env albo
    // ai-engine/.env). Paczka tekstów liczy się sekundami — stąd kolejka w cronie.
    'ai' => [
        'script'          => __DIR__ . '/../ai-engine/translate.py',
        'timeout_seconds' => (int) $env('TRANSLATE_AI_TIMEOUT', '180'),
    ],
];

$notifications = [
    'unsubscribe_key' => $env('NOTIFICATIONS_UNSUBSCRIBE_KEY', ''),
];

// ROUTE PLANNER, ETAP 1 (2026-09-17) — oba adresy to DARMOWE, PUBLICZNE
// serwery bez klucza (zweryfikowane live w spike'u), wspólne dla dev/prod.
// Trzymane jako wartość konfiguracyjna, nie stała w Utils\RoutingProxy/
// Utils\ElevationLookup, żeby ewentualna zmiana dostawcy (self-hosted OSRM,
// BRouter, openrouteservice) była wpisem w env, nie zmianą kodu.
//
// OSRM ROWEROWY (2026-09-18). Do tego dnia stał tu serwer demo
// router.project-osrm.org, który IGNORUJE profil w adresie: /cycling/,
// /driving/ i /foot/ dawały tę samą trasę SAMOCHODOWĄ (Wawel → Tyniec 17,9 km
// przy 65 km/h; rowerowo 10,2 km wzdłuż Wisły). FOSSGIS to ten sam silnik
// OSRM z profilem rowerowym, ale regulamin mówi: najwyżej jedno zapytanie na
// sekundę, bez intensywnego użycia — stąd `osrm_min_interval_ms` (dławik
// w RoutingProxy, wspólny dla wszystkich procesów PHP).
//
// SILNIK (`engine`, 2026-09-19): nazwa silnika routingu — klucz, pod którym
// typy rowerów (słownik `bike_type`, panel „Planer") trzymają swój profil
// i ewentualny własny adres serwera. Dziś zaimplementowany tylko `osrm`
// (Utils\RoutingProxy); inna wartość = planer bez tras, dopóki nie dopisze
// się gałęzi silnika.
$planner = [
    'engine'               => $env('PLANNER_ENGINE', 'osrm'),
    'osrm_base_url'        => $env('OSRM_BASE_URL', 'https://routing.openstreetmap.de/routed-bike'),
    'osrm_min_interval_ms' => (int) $env('OSRM_MIN_INTERVAL_MS', '1000'),
    'elevation_base_url'   => $env('ELEVATION_BASE_URL', 'https://api.opentopodata.org/v1/srtm30m'),
];

$configs = [
    'dev' => [
        // Aplikacja stoi w podkatalogu: http://localhost/ridemore/...
        'base_path' => '/ridemore',
        'app_url'   => 'http://localhost/ridemore',
        // WERSJE JĘZYKOWE (tasks/active/wielojezycznosc.md). Pierwszy zawsze
        // polski (bez prefiksu w adresie); każdy kolejny dostaje `/{kod}/…`.
        // Trzeci język = dopisanie kodu tu + plik core/lang/{kod}.php.
        'languages' => ['pl', 'en'],
        'db' => [
            'host' => $env('DB_HOST_DEV', '127.0.0.1'),
            'name' => $env('DB_NAME_DEV', 'ridemorebike2'),
            'user' => $env('DB_USER_DEV', 'skrobi'),
            'pass' => $env('DB_PASS_DEV', ''),
        ],
        'mail' => $mailDev,
        'oauth' => $oauth,
        'devices' => $devices,
        'push' => $push,
        'notifications' => $notifications,
        'planner' => $planner,
        'translate' => array_replace_recursive($translate, ['ai' => [
            'python_bin' => $env('AI_ENGINE_PYTHON_BIN', __DIR__ . '/../ai-engine/venv/Scripts/python.exe'),
        ]]),
        // Most do lokalnego silnika Pythona (ai-engine/), patrz
        // core/Utils/AiEngineBridge.php i md/features.md. CELOWO tylko w
        // 'dev': /api/ai/engine-analyze i tak zwraca 404 poza APP_ENV==='dev'
        // (patrz api/routes.php), ale klucz w ogóle nie istniejąc na 'prod'
        // czyni to jawnym, nie tylko wymuszonym w kodzie kontrolera.
        'ai_engine' => [
            // BUG naprawiony: bez __DIR__ ta ścieżka była string "/../ai-engine/..."
            // wprost — PHP/Windows czyta wiodący "/" jako root BIEŻĄCEGO dysku, więc
            // proc_open szukał C:\ai-engine\... zamiast C:\xampp\htdocs\ridemore\ai-engine\...
            'python_bin'      => $env('AI_ENGINE_PYTHON_BIN', __DIR__ . '/../ai-engine/venv/Scripts/python.exe'),
            'script'          => __DIR__ . '/../ai-engine/analyze.py',
            // 900s: model lokalny (AI_PROVIDER=ollama) potrafi liczyć minutami na
            // słabszym sprzęcie, zwłaszcza duże modele (30B+) — musi zdążyć
            // wygenerować odpowiedź, zanim proc_open przerwie proces. Od sesji
            // 2026-08-07 OllamaProvider robi TRZY zapytania po kolei (core/
            // stages_variants/description, patrz ollama_provider.py), każde
            // z własnym limitem do 300s — więc CAŁY proces (ten jeden
            // proc_open) potrzebuje budżetu na wszystkie trzy razem, nie na
            // jedno. Dla Groq to i tak zwykle kilka sekund. Podbij dalej
            // przez AI_ENGINE_TIMEOUT_SECONDS w env, jeśli i tego zabraknie.
            'timeout_seconds' => (int) $env('AI_ENGINE_TIMEOUT_SECONDS', '900'),
            'max_payload_bytes' => 2_000_000,
        ],
        // Importer wydarzeń (Models\EventImport, import_events.php). Istnieje TYLKO
        // na 'dev' z tego samego powodu co `ai_engine` — ekstrakcja idzie przez
        // ten sam most PHP->Python (Gemini/Groq/Ollama), którego typowy współdzielony
        // hosting nie ma. `sources` to allowlista stron-LIST (kalendarzy) z treścią
        // renderowaną PO STRONIE SERWERA (harvester serwerowy je odczyta — patrz
        // Utils\EventSourceFetcher::looksJsRendered dla źródeł SPA, których NIE
        // wpisujemy tu, tylko obsługujemy rozszerzeniem Chrome). Uruchamiane przez
        // `php import_events.php --sources` (ręcznie albo z Windows Task Scheduler
        // /crona na maszynie, gdzie działa silnik AI).
        'event_import' => [
            'bot_email' => $env('EVENT_IMPORT_BOT_EMAIL', 'importer@ridemore.bike'),
            'sources'   => [
                // Zweryfikowane 2026-09-17: server-side HTML + JSON-LD, harvester
                // wyłuskuje strony /wydarzenie/... poprawnie.
                'https://kalendarzrowerowy.pl/',
            ],
        ],
        // Import przejazdów z Garmin Connect (ai-engine/garmin.py, ten sam most
        // PHP->Python co silnik AI — patrz Utils\GarminBridge). Do 2026-09-04
        // istniało to wyłącznie tu, z tego samego powodu co `ai_engine`:
        // typowy współdzielony hosting nie ma Pythona ani (zwykle) proc_open.
        // TEN hosting ma oba, więc klucz `garmin` istnieje teraz TAKŻE w 'prod'
        // niżej (w odróżnieniu od `ai_engine`, o który user nie prosił — ten
        // zostaje tylko tu). Patrz komentarz przy bloku 'prod' — na prod jest
        // to zestaw INNYCH, starszych wersji pakietów niż tu, bo max Python
        // dostępny na tamtym hostingu to 3.9 (zweryfikowane żywo 2026-09-04).
        'garmin' => [
            'python_bin'      => $env('AI_ENGINE_PYTHON_BIN', __DIR__ . '/../ai-engine/venv/Scripts/python.exe'),
            'script'          => __DIR__ . '/../ai-engine/garmin.py',
            // Dużo krócej niż silnik AI: to są zwykłe zapytania HTTP do Garmina,
            // a nie generowanie odpowiedzi przez model. Jeśli logowanie nie
            // wyrobi się w 2 minuty, to znaczy, że coś jest nie tak, i lepiej
            // powiedzieć to człowiekowi, niż trzymać go przy pustym ekranie.
            'timeout_seconds' => (int) $env('GARMIN_TIMEOUT_SECONDS', '120'),
            // KLUCZ DO SZYFROWANIA TOKENÓW SESJI GARMINA (AES-256-GCM, patrz
            // Models\GarminAccount). Hasło do Garmina NIE JEST nigdzie zapisywane
            // — w bazie ląduje wyłącznie token, i to zaszyfrowany. Na dev
            // klucz musi przyjść z env/config.local.php także na dev — konta
            // podpinane lokalnie są prawdziwe i ich token nie może być szyfrowany
            // publicznie znaną wartością z repozytorium.
            'token_key'       => $env('GARMIN_TOKEN_KEY', ''),
        ],
    ],
    'prod' => [
        // Własna domena, katalog główny: https://ridemore.bike/...
        'base_path' => '',
        'app_url'   => 'https://ridemore.bike',
        // FLAGA WERSJI JĘZYKOWYCH — na produkcji tylko polski, dopóki wersja
        // angielska nie przejdzie przeglądu. Z samym 'pl' prefiks `/en/…` nie
        // istnieje (404), hreflang się nie wypisuje, a polski HTML jest taki
        // jak przed wielojęzycznością. Start = dopisanie 'en'.
        'languages' => ['pl'],
        'db' => [
            'host' => $env('DB_HOST_PROD', 'localhost'),
            'name' => $env('DB_NAME_PROD', 'rideyvwv_ridemore_v2'),
            'user' => $env('DB_USER_PROD', 'rideyvwv_skrobi'),
            'pass' => $env('DB_PASS_PROD', ''),
        ],
        'mail' => $mailProd,
        'oauth' => $oauth,
        'devices' => $devices,
        'push' => $push,
        'notifications' => $notifications,
        'planner' => $planner,
        'translate' => array_replace_recursive($translate, ['ai' => [
            'python_bin' => $env('AI_ENGINE_PYTHON_BIN', __DIR__ . '/../ai-engine/venv/bin/python3'),
        ]]),
        // Import przejazdów z Garmin Connect (ai-engine/garmin.py) — WŁĄCZONE
        // NA PRODUKCJI, zweryfikowane żywym logowaniem 2026-09-04. `ai_engine`
        // (silnik AI importera wydarzeń) zostaje CELOWO tylko w 'dev' — o
        // niego user nie prosił, dotyczy to wyłącznie Garmina.
        //
        // UWAGA: to środowisko (Namecheap/cPanel, „Setup Python App") ma max
        // Python 3.9 — a `garminconnect>=0.3.11` (pin w requirements.txt,
        // działający na dev pod 3.12) wymaga Pythona ≥3.12 i się tu NIE
        // zainstaluje. Realnie działający zestaw na 3.9 to inne, starsze
        // wersje — `garminconnect==0.3.2` (ostatnia linia BEZ zależności od
        // porzuconej biblioteki `garth`) + `curl_cffi==0.13.0` (ostatnia z
        // gotowym wheel-em dla cp39; wersje `>=0.15.0`, których żąda
        // `garminconnect>=0.3.11`, nie mają wheela na 3.9 i nie dają się tu
        // zbudować) — PLUS ręczna łatka `from __future__ import annotations`
        // na zainstalowanym pakiecie (kod `garminconnect` 0.3.2 używa składni
        // `X | None` w adnotacjach, którą Python 3.9 ocenia eagerly i się na
        // niej wywala bez tej łatki). Pełny, przetestowany przepis instalacji
        // (dokładne komendy pip, gdzie nałożyć łatkę, pełny `pip freeze`) w
        // `ai-engine/README.md` → „Uruchomienie na produkcji" i
        // `ai-engine/requirements-namecheap-py39.txt`. Ten plik (requirements.txt)
        // NIE odzwierciedla tego zestawu celowo — dev ma swobodnie brać
        // najnowszego `garminconnect` na Pythonie 3.12, prod ma inny,
        // świadomie przypięty zestaw.
        //
        // `python_bin` domyślnie mierzy w standardowy layout Linuksowego
        // venv (`python3 -m venv ai-engine/venv`). Jeśli realna ścieżka na
        // serwerze jest inna (np. venv założony narzędziem cPanela "Setup
        // Python App", jak u tego usera), NADPISZ przez AI_ENGINE_PYTHON_BIN
        // w env albo `core/config.local.php` (pewniejsze na cPanelu, patrz
        // tamten plik) — ta sama zmienna co używa 'dev' wyżej; nie ma z czym
        // kolidować, bo klucz `ai_engine` na 'prod' nie istnieje.
        'garmin' => [
            'python_bin'      => $env('AI_ENGINE_PYTHON_BIN', __DIR__ . '/../ai-engine/venv/bin/python3'),
            'script'          => __DIR__ . '/../ai-engine/garmin.py',
            'timeout_seconds' => (int) $env('GARMIN_TIMEOUT_SECONDS', '120'),
            // Realne szyfrowanie tokenu idzie przez `devices.token_key`
            // (Models\DeviceConnection::key() sprawdza go NAJPIERW, patrz tam)
            // — ten klucz to tylko fallback zachowany dla symetrii z blokiem
            // 'dev' wyżej, celowo BEZ wartości domyślnej (nie kopiuj sekretu
            // dev na prod).
            'token_key'       => $env('GARMIN_TOKEN_KEY', ''),
        ],
    ],
];

// SEKRETY LOKALNE POZA GITEM (AUDYT.md §11.1). `core/config.local.php` NIGDY
// nie trafia do repo (.gitignore) — na dev to zwykły lokalny plik, na prod
// wgrywany ręcznie SFTP. Gdy istnieje, dopisuje/nadpisuje klucze w $configs
// PO złożeniu tablicy wyżej, więc wygrywa z fallbackami z env() powyżej —
// dokładnie to, po co jest: hasła i sekrety API nie mają wpadać do gita
// razem z resztą tego pliku.
$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    require $localConfigFile;
}

return $configs;
