<?php
// tests/skarby_test.php
// SKARBY (Etap 8D) — zaliczanie, poziomy ujawnienia, potwierdzenia społeczności.
//
// Zestaw pokrywa dokładnie te przypadki, które przy budowie modułu trzeba było
// sprawdzać ręcznie po każdej zmianie. Kolejność jest celowa: najpierw reguły
// pojedynczego zaliczenia, potem to, co widać na mapie, na końcu głosowanie.
//
// NAJWAŻNIEJSZE SĄ TU TESTY IDEMPOTENCJI. Punkty w tym serwisie idą do
// niezmiennego rejestru (Models\PointLedger) — źle naliczonego punktu nie da
// się cofnąć bez ręcznej interwencji w bazie, więc „to samo zdarzenie nie
// płaci dwa razy" jest własnością, którą trzeba pilnować automatem, a nie
// pamięcią.
use Models\Treasure;

// --- Zaliczanie: kod z naklejki --------------------------------------

t_test('QR: zaliczenie w promieniu daje punkty', function () {
    $t = t_treasure(['points' => 123]);
    $r = Treasure::claim($t['code'], t_user(0), 50.0002, 20.0);

    t_true($r['ok'], 'zaliczenie się udało');
    t_eq(123, $r['points'], 'przyznane punkty');
    t_eq('QR', Core\Database::connection()->query(
        'SELECT method FROM treasure_finds WHERE treasure_id = ' . (int) $t['id']
    )->fetchColumn(), 'zapisana metoda');
});

t_test('QR: to samo zaliczenie drugi raz nie płaci', function () {
    $t = t_treasure();
    Treasure::claim($t['code'], t_user(0), 50.0, 20.0);
    $r = Treasure::claim($t['code'], t_user(0), 50.0, 20.0);

    t_false($r['ok'], 'drugie zaliczenie odrzucone');
    t_eq('juz_zaliczona', $r['reason'], 'powód odrzucenia');
    t_eq(0, $r['points'], 'zero punktów za powtórkę');
    t_eq(1, Core\Database::connection()->query(
        'SELECT COUNT(*) FROM point_transactions WHERE source = "TREASURE_FOUND" AND source_id = ' . (int) $t['id']
    )->fetchColumn(), 'w rejestrze dokładnie jedna transakcja');
});

t_test('QR: poza promieniem nie zalicza', function () {
    $t = t_treasure(['claim_radius_m' => 150]);
    $r = Treasure::claim($t['code'], t_user(0), 50.05, 20.0); // ~5,5 km

    t_false($r['ok'], 'odrzucone');
    t_eq('za_daleko', $r['reason'], 'powód');
});

t_test('QR: brak lokalizacji NIE blokuje zaliczenia', function () {
    // Świadoma decyzja z Models\Treasure::claim — blokada wykluczyłaby
    // wszystkich z wyłączonym GPS-em, a to większa szkoda niż pożytek.
    $t = t_treasure();
    t_true(Treasure::claim($t['code'], t_user(0), null, null)['ok'], 'zaliczone bez współrzędnych');
});

t_test('QR: nieznany kod nie zdradza, czy skarb istnieje', function () {
    $r = Treasure::claim('NIEISTNIEJACY', t_user(0), 50.0, 20.0);
    t_eq('nieznana', $r['reason'], 'ten sam powód co dla wycofanego');
});

// --- Kolejka offline w apce (Etap 7 przebudowy apki, 2026-08-28) -----
//
// Apka wysyła zaległy skan W TLE po powrocie zasięgu (assets/js/native.js) —
// potrzebuje wyniku bez parsowania HTML-a. `TreasureScanController::claim`
// odpowiada JSON-em WYŁĄCZNIE, gdy żądanie niesie `Accept: application/json`;
// zwykły formularz („Odbierz" na stronie skarbu) tego nagłówka nigdy nie
// wysyła, więc ta gałąź nie rusza istniejącego zachowania — trzeci test
// niżej to sprawdza wprost.

function sk_json_claim(string $code, int $userId, ?float $lat = null, ?float $lon = null): array
{
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SESSION['csrf_token'] = 'sk-test-token';
    $_POST = ['csrf_token' => 'sk-test-token'];
    if ($lat !== null) { $_POST['lat'] = $lat; }
    if ($lon !== null) { $_POST['lon'] = $lon; }
    t_auth_as($userId);

    ob_start();
    Controllers\TreasureScanController::claim($code);
    $body = ob_get_clean();

    t_auth_as(null);
    unset($_SERVER['HTTP_ACCEPT']);
    return json_decode($body, true) ?? [];
}

t_test('Kolejka offline: skan bez zasięgu zalicza się po powrocie i odpowiada JSON-em', function () {
    $t = t_treasure(['name' => 'TEST kolejka', 'points' => 42]);
    $r = sk_json_claim($t['code'], t_user(0), 50.0, 20.0);

    t_true($r['ok'], 'zaliczenie się udało');
    t_eq(42, $r['points'], 'punkty w odpowiedzi — treść powiadomienia o zaliczeniu');
    t_eq('TEST kolejka', $r['name'], 'nazwa w odpowiedzi — też treść powiadomienia');
});

t_test('Kolejka offline: powtórna wysyłka zaległego skanu nie płaci drugi raz', function () {
    $t = t_treasure(['points' => 10]);
    sk_json_claim($t['code'], t_user(0), 50.0, 20.0);
    $r = sk_json_claim($t['code'], t_user(0), 50.0, 20.0);

    t_false($r['ok'], 'drugi raz odrzucone');
    t_eq('juz_zaliczona', $r['reason'], 'ten sam powód co przy zwykłym zaliczeniu — jeden rejestr, obie ścieżki');
});

t_test('Kolejka offline: JSON jest warunkowy na Accept — nie renderujemy pełnej strony w teście', function () {
    // Świadomie NIE wołamy tu claim() bez nagłówka Accept: ta gałąź idzie
    // przez self::render() → View::render('web','treasure-scan',...), która
    // dołącza partials/photo-lightbox.php — a ten ma bramkę „raz na proces"
    // (RIDEMORE_PHOTO_LIGHTBOX, celowo — jedna strona w prawdziwym żądaniu
    // = jeden proces PHP). W teście, gdzie WIELE „stron" renderuje się
    // w JEDNYM procesie, drugie takie wywołanie ukradłoby pierwsze
    // renderowanie testowi lightboksa w widoki_test.php. Sprawdzamy więc
    // ŹRÓDŁO — ten sam sposób co przy bramkach APP_IS_APP wyżej w innych
    // testach — zamiast uruchamiać pełny render z tego samego powodu.
    $src = (string) file_get_contents(CORE_PATH . '/../core/Controllers/TreasureScanController.php');

    t_true(
        (bool) preg_match('/str_contains\(\s*\$_SERVER\[.HTTP_ACCEPT.\]\s*\?\?\s*\x27\x27\s*,\s*.application\/json.\s*\)/', $src),
        'gałąź JSON jest warunkowa na nagłówku Accept — bez niego zostaje pełna strona jak dotąd'
    );
});

// --- Zaliczanie: lokalizacja i ślad ----------------------------------

t_test('GPS: jedno potwierdzenie zalicza wszystkie skarby w zasięgu', function () {
    t_treasure(['name' => 'TEST A', 'lat' => 50.0,    'points' => 10]);
    t_treasure(['name' => 'TEST B', 'lat' => 50.0006, 'points' => 20]);
    t_treasure(['name' => 'TEST C', 'lat' => 50.02,   'points' => 30]); // ~2,2 km

    $r = Treasure::claimNearby(t_user(0), 50.0002, 20.0);
    t_count(2, $r['claimed'], 'zaliczone dwa bliskie, trzeci poza zasięgiem');
    t_eq(30, array_sum(array_column($r['claimed'], 'points')), 'suma punktów');
});

t_test('GPS: powtórzone kliknięcie nic nie dokłada', function () {
    t_treasure();
    Treasure::claimNearby(t_user(0), 50.0002, 20.0);
    $r = Treasure::claimNearby(t_user(0), 50.0002, 20.0);

    t_count(0, $r['claimed'], 'nic nowego');
    t_eq(1, $r['already'], 'policzone jako już posiadane');
});

t_test('GPX: ślad w promieniu zalicza, poza promieniem nie', function () {
    $t = t_treasure(['claim_radius_m' => 150]);
    $cells = [(int) $t['cell_id']];

    // 0,00054 stopnia szerokości to ~60 m; 0,0036 to ~400 m (to samo pole).
    t_count(0, Treasure::claimAlongTrack(t_user(0), $cells, [['lat' => 49.9964, 'lon' => 20.0]]),
        'ślad 400 m obok nie zalicza mimo tego samego pola siatki');
    t_count(1, Treasure::claimAlongTrack(t_user(0), $cells, [['lat' => 49.99946, 'lon' => 20.0]]),
        'ślad 60 m obok zalicza');
    t_count(0, Treasure::claimAlongTrack(t_user(0), $cells, [['lat' => 49.99946, 'lon' => 20.0]]),
        'ten sam ślad drugi raz już nie');
});

t_test('GPX: bez pól siatki nie rusza tablicy punktów', function () {
    $t = t_treasure();
    t_count(0, Treasure::claimAlongTrack(t_user(0), [], [['lat' => 50.0, 'lon' => 20.0]]),
        'brak kandydatów = brak zaliczeń');
});

t_test('Metody nie sumują się: skan i ślad tego samego dnia to jedno znalezienie', function () {
    $t = t_treasure(['points' => 50]);
    t_true(Treasure::claim($t['code'], t_user(0), 50.0, 20.0)['ok'], 'najpierw skan');
    t_count(0, Treasure::claimAlongTrack(t_user(0), [(int) $t['cell_id']], [['lat' => 50.0, 'lon' => 20.0]]),
        'ślad z tej samej trasy już nie płaci');
    t_eq(1, Core\Database::connection()->query(
        'SELECT COUNT(*) FROM point_transactions WHERE source = "TREASURE_FOUND" AND source_id = ' . (int) $t['id']
    )->fetchColumn(), 'jedna transakcja mimo dwóch dróg');
});

// --- Poziomy ujawnienia (SKA/7) --------------------------------------

$granice = ['north' => 50.2, 'south' => 49.8, 'east' => 20.2, 'west' => 19.8];

t_test('Mapa: kod z naklejki NIE wychodzi w odpowiedzi', function () use ($granice) {
    t_treasure();
    foreach (Treasure::inBounds($granice, null) as $row) {
        if (array_key_exists('code', $row)) {
            t_fail('pole "code" wyciekło do klienta — każdy mógłby zaliczyć skarb z domu');
        }
    }
    t_true(true, 'brak pola "code"');
});

t_test('Ujawnienie 2: jawny skarb widać dokładnie i bez logowania', function () use ($granice) {
    $t = t_treasure(['reveal_level' => 2, 'name' => 'TEST jawny']);
    $found = null;
    foreach (Treasure::inBounds($granice, null) as $row) {
        if ((int) $row['id'] === (int) $t['id']) { $found = $row; }
    }
    t_not_null($found, 'skarb jest w odpowiedzi');
    t_eq('exact', $found['reveal'], 'poziom ujawnienia');
    t_eq('TEST jawny', $found['name'], 'nazwa widoczna');
    t_eq(50.0, round((float) $found['lat'], 5), 'dokładna szerokość');
});

t_test('Ujawnienie 0 i 1: niewidoczne w polu, którego oglądający nie odkrył', function () use ($granice) {
    t_treasure(['reveal_level' => 0, 'name' => 'TEST ukryty']);
    t_treasure(['reveal_level' => 1, 'name' => 'TEST trop', 'hint' => 'Przy dębie']);

    foreach (Treasure::inBounds($granice, null) as $row) {
        if (in_array($row['name'], ['TEST ukryty', 'TEST trop', 'Coś tu jest'], true)) {
            t_fail('ukryty skarb pokazał się anonimowi — ujawnienie ma zależeć od odkrytych pól');
        }
    }
    t_true(true, 'anonim nie widzi ukrytych');
});

t_test('Ujawnienie 0 i 1: w odkrytym polu widać, ale bez dokładnego miejsca', function () use ($granice) {
    $db = Core\Database::connection();
    $user = t_user(0);
    $ukryty = t_treasure(['reveal_level' => 0, 'name' => 'TEST ukryty']);
    $trop   = t_treasure(['reveal_level' => 1, 'name' => 'TEST trop', 'hint' => 'Przy dębie']);

    // Oglądający odkrywa pole, w którym stoją oba (ten sam punkt = to samo pole).
    $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
       ->execute(['u' => $user, 'c' => $ukryty['cell_id']]);

    $byId = [];
    foreach (Treasure::inBounds($granice, $user) as $row) { $byId[(int) $row['id']] = $row; }

    t_not_null($byId[(int) $ukryty['id']] ?? null, 'ukryty widoczny po odkryciu pola');
    t_eq('hidden', $byId[(int) $ukryty['id']]['reveal'], 'poziom ukrytego');
    t_eq('Coś tu jest', $byId[(int) $ukryty['id']]['name'], 'nazwa zastąpiona');
    t_null($byId[(int) $ukryty['id']]['category_label'], 'kategoria też jest tropem — nie wychodzi');

    t_eq('hint', $byId[(int) $trop['id']]['reveal'], 'poziom tropu');
    t_eq('Przy dębie', $byId[(int) $trop['id']]['hint'], 'wskazówka widoczna');

    // Współrzędne mają być środkiem pola, a nie prawdziwym miejscem.
    if (abs((float) $byId[(int) $ukryty['id']]['lat'] - 50.0) < 0.00001) {
        t_fail('ukryty skarb oddał dokładne współrzędne — cała zagadka do zdjęcia z podglądu API');
    }
    t_true(true, 'współrzędne przesunięte do środka pola');
});

t_test('Znalazca widzi swój skarb w pełni, niezależnie od poziomu', function () use ($granice) {
    $t = t_treasure(['reveal_level' => 0, 'name' => 'TEST mój ukryty']);
    Treasure::claim($t['code'], t_user(0), 50.0, 20.0);

    foreach (Treasure::inBounds($granice, t_user(0)) as $row) {
        if ((int) $row['id'] !== (int) $t['id']) { continue; }
        t_eq('exact', $row['reveal'], 'po znalezieniu pełne dane');
        t_eq('TEST mój ukryty', $row['name'], 'prawdziwa nazwa');
        return;
    }
    t_fail('własny skarb zniknął z mapy');
});

t_test('Ukryty skarb nadal da się zaliczyć — o to w nim chodzi', function () {
    $t = t_treasure(['reveal_level' => 0]);
    t_count(1, Treasure::claimNearby(t_user(0), 50.0002, 20.0)['claimed'], 'GPS działa na ukrytym');
});

// --- Alerty w tle „zbliżasz się" (Etap 5b apki, 2026-08-28) ----------
//
// Jedyne miejsce, które CELOWO odwraca zachowanie testu wyżej („niewidoczne
// w polu, którego oglądający nie odkrył"): apka liczy odległość lokalnie, na
// urządzeniu, więc musi dostać współrzędne skarbu ZANIM pole zostanie
// odkryte jazdą. Precyzja ma jednak zostać identyczna jak po odkryciu —
// środek pola i generyczna treść na poziomach 0/1, nigdy dokładny punkt.

t_test('Alerty: ukryty (poziom 0) wychodzi mimo nieodkrytego pola, ale bez dokładnej pozycji', function () use ($granice) {
    $t = t_treasure(['reveal_level' => 0, 'name' => 'TEST alert ukryty']);

    $found = null;
    foreach (Treasure::nearbyForAlerts($granice, t_user(0)) as $row) {
        if ((int) $row['id'] === (int) $t['id']) { $found = $row; }
    }
    t_not_null($found, 'ukryty skarb jest w odpowiedzi alertu, mimo że pole nieodkryte');
    t_eq('hidden', $found['reveal'], 'poziom ujawnienia jak po odkryciu');
    t_eq('Coś tu jest', $found['name'], 'nazwa zastąpiona, tak jak na mapie po odkryciu');
    if (abs((float) $found['lat'] - 50.0) < 0.00001) {
        t_fail('alert oddał dokładne współrzędne ukrytego skarbu — to więcej, niż pokazuje mapa po odkryciu');
    }
    t_true(true, 'współrzędne to środek pola, nie prawdziwe miejsce');
});

t_test('Alerty: trop (poziom 1) niesie wskazówkę, tak jak w odkrytym polu', function () use ($granice) {
    $t = t_treasure(['reveal_level' => 1, 'name' => 'TEST alert trop', 'hint' => 'Przy dębie']);

    $found = null;
    foreach (Treasure::nearbyForAlerts($granice, t_user(0)) as $row) {
        if ((int) $row['id'] === (int) $t['id']) { $found = $row; }
    }
    t_not_null($found, 'trop jest w odpowiedzi alertu');
    t_eq('hint', $found['reveal'], 'poziom ujawnienia');
    t_eq('Przy dębie', $found['hint'], 'wskazówka widoczna');
});

t_test('Alerty: już zdobyty przez pytającego nie wychodzi — nie ma czego alarmować', function () use ($granice) {
    $t = t_treasure(['reveal_level' => 0]);
    Treasure::claim($t['code'], t_user(0), 50.0, 20.0);

    foreach (Treasure::nearbyForAlerts($granice, t_user(0)) as $row) {
        if ((int) $row['id'] === (int) $t['id']) {
            t_fail('własny, już zdobyty skarb wyszedł w liście alertów');
        }
    }
    t_true(true, 'zdobyty skarb pominięty');
});

t_test('Alerty: jawny (poziom 2) ma dokładne współrzędne, tak jak na mapie', function () use ($granice) {
    $t = t_treasure(['reveal_level' => 2, 'name' => 'TEST alert jawny']);

    $found = null;
    foreach (Treasure::nearbyForAlerts($granice, t_user(0)) as $row) {
        if ((int) $row['id'] === (int) $t['id']) { $found = $row; }
    }
    t_not_null($found, 'jawny skarb jest w odpowiedzi');
    t_eq('exact', $found['reveal'], 'poziom ujawnienia');
    t_eq(50.0, round((float) $found['lat'], 5), 'dokładna szerokość');
});

// SKARB MIJANY PO DRODZE (prośba usera 2026-08-29: „powinno zaliczyć i podać
// informacje o nim — tak jakby przewodnik turystyczny"). Apka pokazuje notkę
// z `description`, więc opis wchodzi do listy pod alerty — a to jest dokładnie
// ten rodzaj pola, które NIE MOŻE wyjechać do telefonu przy skarbie ukrytym.

t_test('Alerty: opis jedzie do apki przy skarbie JAWNYM (notka przewodnika)', function () use ($granice) {
    $t = t_treasure([
        'reveal_level' => 2,
        'name'         => 'TEST wieża widokowa',
        'description'  => 'Drewniana wieża z widokiem na całą dolinę.',
    ]);

    $found = null;
    foreach (Treasure::nearbyForAlerts($granice, t_user(0)) as $row) {
        if ((int) $row['id'] === (int) $t['id']) { $found = $row; }
    }
    t_not_null($found, 'jawny skarb jest w odpowiedzi');
    t_eq('Drewniana wieża z widokiem na całą dolinę.', $found['description'],
        'opis jedzie z listą — dzięki temu notka działa też bez zasięgu');
});

t_test('Alerty: opis ukrytego i tropu NIE wyjeżdża do telefonu — byłby spoilerem', function () use ($granice) {
    // Opis zawęża poszukiwania tak samo jak zdjęcie czy nazwa, a payload bbox
    // przeczyta każdy, kto zajrzy w ruch sieciowy apki. Bramka jest w
    // `Treasure::reveal()` (ta sama linia co `photo_url`/`rarity`) — ten test
    // pilnuje, żeby dołożenie kolumny do zapytania jej nie ominęło.
    foreach ([0, 1] as $poziom) {
        $t = t_treasure([
            'reveal_level' => $poziom,
            'name'         => 'TEST ukryty opis',
            'description'  => 'TAJNE: stoi tuż za mostkiem, po lewej.',
            'hint'         => 'Przy wodzie',
        ]);

        $found = null;
        foreach (Treasure::nearbyForAlerts($granice, t_user(0)) as $row) {
            if ((int) $row['id'] === (int) $t['id']) { $found = $row; }
        }
        t_not_null($found, "skarb poziomu $poziom jest w odpowiedzi alertu");
        t_null($found['description'], "opis skarbu poziomu $poziom nie wychodzi do apki");
    }
});

t_test('Zaliczenie z pozycji niesie treść pod notkę przewodnika i wejście na pełny ekran', function () {
    // To są skarby WŁAŚNIE znalezione przez pytającego — pełny opis i kod nie
    // omijają tu żadnej bramki (ta chroni skarby jeszcze nieznalezione), a bez
    // nich apka nie ma z czego złożyć ani notki, ani adresu `/skarb/{code}`.
    $t = t_treasure([
        'reveal_level' => 0,
        'name'         => 'TEST mijany skarb',
        'description'  => 'Kapliczka z 1878 roku, na rozstaju dróg.',
        'claim_radius_m' => 150,
    ]);

    $wynik = Treasure::claimNearby(t_user(1), 50.0, 20.0);

    $mine = null;
    foreach ($wynik['claimed'] as $c) {
        if ((int) $c['treasure']['id'] === (int) $t['id']) { $mine = $c; }
    }
    t_not_null($mine, 'skarb w promieniu został zaliczony samą pozycją');
    t_eq('Kapliczka z 1878 roku, na rozstaju dróg.', $mine['treasure']['description'],
        'opis wraca w wyniku zaliczenia — z tego powstaje notka');
    t_eq($t['code'], $mine['treasure']['code'], 'kod wraca — z niego apka składa adres pełnego ekranu');
    t_true($mine['points'] > 0, 'i punkty, które notka pokazuje');
});

t_test('Zaliczenie z pozycji: poza promieniem nie zalicza nic', function () {
    t_treasure(['name' => 'TEST daleki skarb', 'claim_radius_m' => 100, 'lat' => 50.0, 'lon' => 20.0]);

    // ~1,1 km na północ — poza każdym sensownym promieniem zaliczenia.
    $wynik = Treasure::claimNearby(t_user(1), 50.01, 20.0);

    foreach ($wynik['claimed'] as $c) {
        if (($c['treasure']['name'] ?? '') === 'TEST daleki skarb') {
            t_fail('skarb spoza promienia zaliczony — odległość liczy serwer i to jest cała ochrona tej drogi');
        }
    }
    t_true(true, 'poza promieniem nic nie wpadło');
});

t_test('Apka: promień ZALICZENIA i promień ALERTU to dwie różne liczby', function () {
    // Zapas 150 m istnieje po to, żeby ostrzec ZANIM miniesz skarb. Gdyby
    // zaliczenie szło tym samym, szerszym promieniem, apka przyznawałaby
    // skarby, obok których się nie było — a serwer i tak by je odrzucił,
    // więc user dostawałby notkę bez pokrycia.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');

    t_true(
        (bool) preg_match('/d <= promienZaliczenia.{0,40}zaliczSkarb/s', $js),
        'zaliczamy w promieniu skarbu, nie w promieniu alertu'
    );
    t_true(
        (bool) preg_match('/d > promienZaliczenia \+ ALERT_BUFFER_M/', $js),
        'alert dalej działa na szerszym promieniu'
    );
    t_true(
        (bool) preg_match('/isOnline\(\).{0,120}zakolejkuj/s', $js),
        'brak zasięgu kieruje zaliczenie do kolejki, zamiast je gubić'
    );
    t_true(
        (bool) preg_match('/queueClaim/', $js) && str_contains(
            (string) file_get_contents(CORE_PATH . '/../assets/js/native.js'), 'native.queueClaim'
        ),
        'kolejka zaliczeń to TA SAMA kolejka co zaległe skany, nie druga implementacja'
    );
    t_true(
        (bool) preg_match("/url: c\.code \? '\/skarb\/' \+ c\.code/", $js),
        'notka prowadzi na pełny ekran skarbu — inaczej byłaby ślepym zaułkiem'
    );
});

// --- Potwierdzenia społeczności (SKA/3) ------------------------------

t_test('Zgłoszony punkt nie płaci żadną z trzech dróg', function () {
    $t = t_treasure(['status' => 'PROPOSED', 'points' => 77]);

    t_eq('nieaktywna', Treasure::claim($t['code'], t_user(0), 50.0, 20.0)['reason'], 'QR odrzucony');
    t_count(0, Treasure::claimNearby(t_user(0), 50.0002, 20.0)['claimed'], 'GPS odrzucony');
    t_count(0, Treasure::claimAlongTrack(t_user(0), [(int) $t['cell_id']], [['lat' => 50.0, 'lon' => 20.0]]),
        'GPX odrzucony');
});

t_test('Zgłoszony punkt widać na mapie z zerową wartością', function () use ($granice) {
    $t = t_treasure(['status' => 'PROPOSED', 'points' => 77, 'name' => 'TEST zgłoszony']);
    foreach (Treasure::inBounds($granice, null) as $row) {
        if ((int) $row['id'] !== (int) $t['id']) { continue; }
        t_eq('proposed', $row['reveal'], 'oznaczony jako zgłoszony');
        t_eq(0, $row['points'], 'obiecuje zero, dopóki nie jest aktywny');
        t_eq(0, $row['confirmations'], 'licznik głosów');
        return;
    }
    t_fail('zgłoszony punkt nie pojawił się na mapie — nikt nie mógłby go potwierdzić');
});

t_test('Próg potwierdzeń aktywuje punkt i dopiero wtedy zaczyna płacić', function () {
    $autor = t_user(0);
    $t = t_treasure(['status' => 'PROPOSED', 'points' => 40, 'created_by' => $autor]);
    $needed = Treasure::confirmationsNeeded();

    t_eq('wlasny', Treasure::confirm((int) $t['id'], $autor)['reason'], 'autor nie potwierdza sam siebie');

    for ($i = 1; $i <= $needed; $i++) {
        $r = Treasure::confirm((int) $t['id'], t_user($i));
        t_true($r['ok'], "głos {$i} przyjęty");
        t_eq($i, $r['count'], "licznik po głosie {$i}");
        t_same($i >= $needed, $r['activated'], "aktywacja po głosie {$i}");
    }

    t_eq('ACTIVE', Treasure::find((int) $t['id'])['status'], 'status po osiągnięciu progu');
    t_true(Treasure::claim(Treasure::find((int) $t['id'])['code'], t_user(0), 50.0, 20.0)['ok'],
        'aktywowany punkt płaci');
});

t_test('Ten sam głos drugi raz się nie liczy', function () {
    $t = t_treasure(['status' => 'PROPOSED', 'created_by' => t_user(0)]);
    Treasure::confirm((int) $t['id'], t_user(1));
    $r = Treasure::confirm((int) $t['id'], t_user(1));

    t_false($r['ok'], 'odrzucone');
    t_eq('juz_potwierdzone', $r['reason'], 'powód');
    t_eq(1, Treasure::confirmationCount((int) $t['id']), 'licznik bez zmian');
});

t_test('Potwierdzenie z mapy wymaga bycia na miejscu', function () {
    $t = t_treasure(['status' => 'PROPOSED', 'created_by' => t_user(0), 'claim_radius_m' => 150]);

    t_eq('za_daleko', Treasure::confirmNearby((int) $t['id'], t_user(1), 50.05, 20.0)['reason'],
        'z 5 km nie wolno');
    t_true(Treasure::confirmNearby((int) $t['id'], t_user(1), 50.0002, 20.0)['ok'],
        'z miejsca wolno');
});

t_test('Poczekalnia pokazuje tylko zgłoszone, od najbliższych progu', function () {
    $przed = count(Treasure::pending());
    $a = t_treasure(['status' => 'PROPOSED', 'name' => 'TEST bez głosów', 'created_by' => t_user(0)]);
    $b = t_treasure(['status' => 'PROPOSED', 'name' => 'TEST z głosem',  'created_by' => t_user(0)]);
    t_treasure(['status' => 'ACTIVE', 'name' => 'TEST aktywny']);
    Treasure::confirm((int) $b['id'], t_user(1));

    $pending = Treasure::pending();
    t_count($przed + 2, $pending, 'aktywny nie trafia do poczekalni');

    $nazwy = array_column($pending, 'name');
    $poz = array_search('TEST z głosem', $nazwy, true);
    $poz2 = array_search('TEST bez głosów', $nazwy, true);
    t_true($poz < $poz2, 'punkt z głosem stoi wyżej niż ten bez');
});

t_test('Decyzja admina omija głosowanie', function () {
    $t = t_treasure(['status' => 'PROPOSED', 'created_by' => t_user(0)]);
    Treasure::setStatus((int) $t['id'], 'ACTIVE');
    t_eq('ACTIVE', Treasure::find((int) $t['id'])['status'], 'aktywowany bez ani jednego głosu');

    Treasure::setStatus((int) $t['id'], 'RETIRED');
    t_eq('RETIRED', Treasure::find((int) $t['id'])['status'], 'odrzucony zostaje w bazie');
    t_eq('nieaktywna', Treasure::claim($t['code'], t_user(1), 50.0, 20.0)['reason'],
        'wycofany nie płaci');
});

t_test('Limit otwartych zgłoszeń liczy tylko czekające', function () {
    $autor = t_user(0);
    $przed = count(Treasure::proposedBy($autor));
    $a = t_treasure(['status' => 'PROPOSED', 'created_by' => $autor]);
    t_treasure(['status' => 'PROPOSED', 'created_by' => $autor]);
    t_count($przed + 2, Treasure::proposedBy($autor), 'oba w poczekalni');

    Treasure::setStatus((int) $a['id'], 'ACTIVE');
    t_count($przed + 1, Treasure::proposedBy($autor), 'potwierdzony zwalnia miejsce w limicie');
});

// --- Punktacja i statystyki ------------------------------------------

t_test('Rzadkość ustawia punkty wyjściowe, nie mnożnik', function () {
    $common = Treasure::defaultPointsFor('COMMON');
    $legend = Treasure::defaultPointsFor('LEGENDARY');
    t_true($legend > $common, 'legendarny wart więcej niż pospolity');
    t_eq(Treasure::defaultPoints(), Treasure::defaultPointsFor('NIEZNANA_RZADKOSC'),
        'nieznana rzadkość spada do wartości domyślnej');

    // Wpisana wartość ma być tym, co dostanie znalazca — bez cichego mnożenia.
    $t = t_treasure(['rarity' => 'LEGENDARY', 'points' => 250]);
    t_eq(250, Treasure::claim($t['code'], t_user(0), 50.0, 20.0)['points'],
        'punkty biorą się z pola skarbu, nie z rzadkości');
});

t_test('Stawka z bazy nadpisuje plik i daje się cofnąć', function () {
    // PRAWDZIWA wartość z pliku, nie to, co akurat zwraca defaultPointsFor()
    // na starcie — na dev leży realne, świadome nadpisanie admina (panel
    // punktacji), więc defaultPointsFor('EPIC') bez tego zwróciłoby JUŻ
    // nadpisaną wartość i test porównywałby nadpisanie z samym sobą, nie
    // z plikiem. DiscoveryScoring::defaults() to ta sama ścieżka „było" co
    // w panelu (Models\ScoringSettings::defaultFor()) — plik, bez nakładki z bazy.
    $zPliku = (int) Models\DiscoveryScoring::defaults()['treasures']['points']['EPIC'];

    Models\ScoringSettings::set('treasures.points.EPIC', $zPliku + 111, t_user(0));
    t_eq($zPliku + 111, Treasure::defaultPointsFor('EPIC'), 'nadpisane przez admina');

    Models\ScoringSettings::set('treasures.points.EPIC', null, t_user(0));
    t_eq($zPliku, Treasure::defaultPointsFor('EPIC'), 'po skasowaniu wraca wartość z pliku');
});

t_test('Statystyki społeczności liczą czekające, nie znalezione', function () {
    $przed = Treasure::statsCommunity();
    $a = t_treasure(['name' => 'TEST znaleziony']);
    t_treasure(['name' => 'TEST czekający']);
    Treasure::claim($a['code'], t_user(0), 50.0, 20.0);

    $po = Treasure::statsCommunity();
    t_eq($przed['total'] + 2, $po['total'], 'oba doliczone do sumy');
    t_eq($przed['taken'] + 1, $po['taken'], 'jeden zdjęty');
    t_eq($przed['waiting'] + 1, $po['waiting'], 'jeden nadal czeka');
});

t_test('Kolekcja pokazuje tylko kategorie, w których coś znaleziono', function () {
    $user = t_user(0);
    $przed = count(Treasure::collectionsForUser($user));
    t_treasure(['name' => 'TEST nieznaleziony']);
    t_count($przed, Treasure::collectionsForUser($user), 'sam skarb nie tworzy kolekcji');

    $t = t_treasure(['name' => 'TEST do kolekcji']);
    Treasure::claim($t['code'], $user, 50.0, 20.0);
    $kolekcje = Treasure::collectionsForUser($user);

    foreach ($kolekcje as $k) {
        if ($k['found'] < 1) {
            t_fail('w kolekcji znalazła się kategoria bez ani jednego znalezienia');
        }
    }
    t_true(count($kolekcje) >= 1, 'po znalezieniu kolekcja istnieje');
});

t_test('Znalezienie trafia do aktywności rowerzysty', function () {
    $user = t_user(0);
    $t = t_treasure(['name' => 'TEST w feedzie', 'points' => 33]);
    Treasure::claim($t['code'], $user, 50.0, 20.0);

    foreach (Models\RiderFeed::forUser($user, 0, 20)['items'] as $item) {
        if ($item['type'] === 'skarb' && str_contains($item['title'], 'TEST w feedzie')) {
            t_eq(33, $item['points'], 'punkty we wpisie');
            if (str_contains((string) $item['url'], '/skarb/')) {
                t_fail('wpis feedu prowadzi pod kod naklejki — sekret na publicznym profilu');
            }
            return;
        }
    }
    t_fail('znalezienie nie pojawiło się w aktywności');
});

t_test('Puls: partia skarbów z jednej doby to JEDEN wpis', function () {
    for ($i = 0; $i < 6; $i++) {
        t_treasure(['name' => 'TEST partia ' . $i, 'lat' => 50.0 + $i * 0.01]);
    }
    // SPRAWDZAMY WPIS Z DZISIAJ, nie globalną liczbę wpisów. Pierwsza wersja
    // liczyła wszystkie pozycje typu „skarb-nowe" w feedzie i przestała
    // przechodzić, gdy w bazie pojawił się startowy zestaw skarbów (migr. 060)
    // dodany innego dnia — a to jest poprawne zachowanie kodu, bo zwijanie
    // działa NA DOBĘ. Test mierzy teraz to, o czym mówi jego nazwa.
    $dzis = date('Y-m-d');
    $zDzis = array_values(array_filter(
        Models\Pulse::feed(50),
        static fn(array $w): bool => $w['type'] === 'skarb-nowe' && str_starts_with((string) $w['at'], $dzis)
    ));

    t_count(1, $zDzis, 'wszystkie dzisiejsze skarby w JEDNYM wpisie, nie sześciu');
    t_true($zDzis[0]['count'] >= 6, 'wpis niesie liczbę: ' . $zDzis[0]['count']);
    t_true(str_contains($zDzis[0]['title'], 'skarb'), 'tytuł mówi o skarbach: ' . $zDzis[0]['title']);
});

t_test('Puls: pierwsze znalezienie dostaje wpis, kolejne już nie', function () {
    $t = t_treasure(['name' => 'TEST pierwszy raz']);
    Treasure::claim($t['code'], t_user(0), 50.0, 20.0);
    Treasure::claim($t['code'], t_user(1), 50.0, 20.0);

    $wpisy = array_filter(
        Models\Pulse::feed(50),
        static fn(array $w): bool => $w['type'] === 'skarb-pierwszy' && str_contains($w['title'], 'TEST pierwszy raz')
    );
    t_count(1, $wpisy, 'dwa znalezienia, jeden wpis');
});

// --- Ikony kategorii (poprawka 2026-08-15) ---------------------------

t_test('Ikony kategorii to KLUCZE do Utils\Icon, nie znaki', function () {
    $rows = Core\Database::connection()->query('
        SELECT i.code, i.icon
          FROM dictionary_items i
          JOIN dictionaries d ON d.id = i.dictionary_id
         WHERE d.code = "treasure_category"
    ')->fetchAll();

    t_true(count($rows) > 0, 'słownik kategorii nie jest pusty');

    foreach ($rows as $row) {
        $icon = (string) $row['icon'];
        if ($icon === '') {
            continue;
        }
        // Klucz jest ASCII. Cokolwiek innego znaczy, że wróciło emoji albo
        // uszkodzone bajty z konsoli — dokładnie to, co naprawiała migracja 057.
        if (!preg_match('/^[a-z0-9-]+$/', $icon)) {
            t_fail("kategoria {$row['code']} ma w kolumnie icon znak, nie klucz: {$icon}");
        }
        if (Utils\Icon::maybe($icon) === '') {
            t_fail("kategoria {$row['code']} wskazuje na nieistniejącą ikonę: {$icon}");
        }
    }
    t_true(true, 'wszystkie kategorie mają poprawny klucz ikony');
});

t_test('Utils\Icon nie zawiera emoji ani uszkodzonych bajtów', function () {
    $r = new ReflectionClass(Utils\Icon::class);
    foreach ($r->getConstant('ICONS') as $name => $svg) {
        foreach (preg_split('//u', $svg, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            // Soft hyphen i znaki blokowe to sygnatura mojibake z cp852;
            // powyżej U+1F000 zaczynają się emoji.
            if ($cp === 0x00AD || ($cp >= 0x2591 && $cp <= 0x2594) || $cp > 0x1F000) {
                t_fail("ikona {$name} zawiera znak spoza ASCII: U+" . strtoupper(dechex($cp)));
            }
        }
    }
    t_true(true, 'zestaw ikon jest czysty');
});

t_test('Nazwy kategorii nie zawierają uszkodzonych bajtów', function () {
    // Trzeci raz ten sam błąd w tym module: emoji w ikonach (migr. 057), potem
    // polskie znaki w nazwach (migr. 059). Za każdym razem powód był ten sam —
    // literał w pliku SQL przeszedł przez konsolę Windows w cp852. Ten test
    // patrzy na BAJTY, nie na to, jak coś wygląda w terminalu; wtedy właśnie
    // uznałem uszkodzone nazwy za artefakt wyświetlania i przepuściłem je.
    $rows = Core\Database::connection()->query('
        SELECT i.code, i.name
          FROM dictionary_items i
          JOIN dictionaries d ON d.id = i.dictionary_id
         WHERE d.code = "treasure_category"
    ')->fetchAll();

    foreach ($rows as $row) {
        foreach (preg_split('//u', (string) $row['name'], -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            // Znaki rysowania ramek (U+2500–U+259F) i miękki łącznik nie
            // występują w żadnej polskiej nazwie — ich obecność znaczy, że
            // bajty UTF-8 zostały kiedyś odczytane jako strona kodowa DOS.
            if ($cp === 0x00AD || ($cp >= 0x2500 && $cp <= 0x259F)) {
                t_fail("kategoria {$row['code']} ma uszkodzoną nazwę: {$row['name']}");
            }
        }
    }
    t_true(true, 'wszystkie nazwy kategorii są czyste');
});

// --- Pęczki i warstwy: zdobyte / do znalezienia -----------------------
//
// Zgłoszenie usera 2026-08-20: „pomimo tego że jest jeden w grupie, pokazuje 1
// — wystarczy ikonka skarbu; grupowanie zaczyna się od 2" oraz „rozróżnijmy na
// warstwach skarby zdobyte od niezdobytych".
//
// SEDNO TYCH TESTÓW: filtr „moje/nowe" musi działać PRZED grupowaniem. Gdyby
// przeglądarka odsiewała gotową odpowiedź, pęczek „2 skarby, masz 1" po
// zgaszeniu jednej warstwy zostałby pęczkiem z jedynką — czyli dokładnie tym,
// co user kazał usunąć.

/** Kadr wokół pustkowia testowego, ciasny — żeby nie łapać skarbów z bazy DEV. */
function sk_bounds(float $lat = 54.80, float $lon = 17.90, float $pad = 0.02): array
{
    return ['north' => $lat + $pad, 'south' => $lat - $pad,
            'east'  => $lon + $pad, 'west'  => $lon - $pad];
}

t_test('pęczki: pole z JEDNYM skarbem wraca jako skarb, nie jako pęczek', function () {
    t_treasure(['name' => 'TEST samotny', 'lat' => 54.80, 'lon' => 17.90]);

    $d = Treasure::clustersInBounds(sk_bounds(), 1, null);

    t_count(0, $d['clusters'], 'żadnego pęczka z jedynką');
    t_count(1, $d['singles'], 'skarb wraca pojedynczo');
    t_eq('TEST samotny', $d['singles'][0]['name'], 'z nazwą, jak zwykła pinezka');
    // Pozycja PRAWDZIWA, nie środek pola — pęczek stoi na środku pola, skarb
    // ma stać tam, gdzie stoi.
    t_eq('54.8000000', $d['singles'][0]['lat'], 'prawdziwa szerokość, nie środek pola');
});

t_test('pęczki: dwa skarby w jednym polu dają pęczek dwójkę', function () {
    t_treasure(['name' => 'TEST para 1', 'lat' => 54.800, 'lon' => 17.900]);
    t_treasure(['name' => 'TEST para 2', 'lat' => 54.8006, 'lon' => 17.9006]);

    $d = Treasure::clustersInBounds(sk_bounds(), 1, null);

    t_count(1, $d['clusters'], 'jeden pęczek');
    t_eq(2, $d['clusters'][0]['count'], 'liczy oba skarby');
    t_count(0, $d['singles'], 'nic nie zostaje luzem');
});

t_test('warstwy: „nowe" pomija zdobyte, „moje" zostawia tylko zdobyte', function () {
    $user = t_user(0);
    $moj = t_treasure(['name' => 'TEST zdobyty', 'lat' => 54.800, 'lon' => 17.900]);
    t_treasure(['name' => 'TEST nietknięty', 'lat' => 54.8006, 'lon' => 17.9006]);
    Treasure::claim($moj['code'], $user, 54.800, 17.900);

    $nazwy = static function (?string $stan) use ($user): array {
        $rows = Treasure::inBounds(sk_bounds(), $user, 300, null, $stan);
        return array_column($rows, 'name');
    };

    t_count(2, $nazwy(null), 'bez filtra widać oba');
    t_same(['TEST nietknięty'], $nazwy('not'), 'warstwa „do znalezienia" bez zdobytego');
    t_same(['TEST zdobyty'], $nazwy('only'), 'warstwa „zdobyte" tylko ze zdobytym');
});

t_test('warstwy: filtr działa PRZED grupowaniem, więc pęczek dwójka staje się pinezką', function () {
    // To jest regresja, której nie da się naprawić po stronie przeglądarki:
    // z pęczka „2 skarby, masz 1" nie da się zrobić pojedynczej pinezki, bo
    // pęczek nie niesie ani nazwy, ani prawdziwej pozycji.
    $user = t_user(0);
    $moj = t_treasure(['name' => 'TEST mam', 'lat' => 54.800, 'lon' => 17.900]);
    t_treasure(['name' => 'TEST zostało', 'lat' => 54.8006, 'lon' => 17.9006]);
    Treasure::claim($moj['code'], $user, 54.800, 17.900);

    $oba  = Treasure::clustersInBounds(sk_bounds(), 1, $user);
    $nowe = Treasure::clustersInBounds(sk_bounds(), 1, $user, null, 'not');

    t_count(1, $oba['clusters'], 'z obiema warstwami to pęczek');
    t_eq(1, $oba['clusters'][0]['found'], 'pęczek wie, ile z niego mam');
    t_count(0, $nowe['clusters'], 'po zgaszeniu zdobytych pęczka już nie ma');
    t_count(1, $nowe['singles'], 'zostaje jeden skarb');
    t_eq('TEST zostało', $nowe['singles'][0]['name'], 'i to ten niezdobyty');
});

t_test('warstwy: gość nie ma czego filtrować i dostaje komplet', function () {
    // Dla niezalogowanego filtr nie ma sensu (nie ma ani jednego znaleziska),
    // więc jest pomijany — a nie zwraca pustej mapy.
    t_treasure(['name' => 'TEST dla gościa', 'lat' => 54.80, 'lon' => 17.90]);

    t_count(1, Treasure::inBounds(sk_bounds(), null, 300, null, 'not'), 'stan „nowe" nic nie zmienia');
    t_count(1, Treasure::inBounds(sk_bounds(), null, 300, null, 'only'), 'stan „moje" też nie');
});

// --- Warstwy w SKALI SPOŁECZNOŚCI (Etap 3, tasks/done/warstwy-mapy.md,
// 2026-08-26) — „odkryte"/„nieodkryte" na mapie SPOŁECZNOŚCI mają znaczyć
// „przez KOGOKOLWIEK", nie „przez pytającego". §27 nie stoi na przeszkodzie:
// to samo pytanie zadaje już publiczny licznik `finders`, żadna nazwa nikąd
// nie wycieka — testy więc pilnują ISTNIENIA, nie tożsamości.

t_test('warstwy: `mineScope=community` liczy CUDZE znalezisko jako „odkryte"', function () {
    [$znalazca, $ktoInny] = t_users(2);
    $t = t_treasure(['name' => 'TEST wspólny', 'lat' => 54.800, 'lon' => 17.900]);
    Treasure::claim($t['code'], $znalazca, 54.800, 17.900);

    // Pytający to KTOŚ INNY, kto tego skarbu NIE znalazł — w skali widza
    // (domyślnej) wyszedłby jako „nieodkryty", w skali społeczności jako
    // „odkryty", bo znalazł go ktokolwiek.
    $wzgledemWidza = Treasure::inBounds(sk_bounds(), $ktoInny, 300, null, 'only');
    $wzgledemSpolecznosci = Treasure::inBounds(sk_bounds(), $ktoInny, 300, null, 'only', 'community');

    t_count(0, $wzgledemWidza, 'skala widza: pytający tego nie ma, więc „moje" nic nie zwraca');
    t_count(1, $wzgledemSpolecznosci, 'skala społeczności: ktoś to ma, więc wpada do „odkryte"');
});

t_test('warstwy: `mineScope=community` działa BEZ konta pytającego', function () {
    $znalazca = t_user(0);
    $t = t_treasure(['name' => 'TEST widziane przez gościa', 'lat' => 54.801, 'lon' => 17.901]);
    Treasure::claim($t['code'], $znalazca, 54.801, 17.901);

    $rows = Treasure::inBounds(sk_bounds(), null, 300, null, 'only', 'community');
    t_count(1, $rows, 'gość bez konta widzi „odkryte przez kogokolwiek"');
});

t_test('warstwy: `mineScope=community`, wariant „nieodkryte" wymaga ZERA znalazców', function () {
    [$znalazca, $widz] = t_users(2);
    $zdobyty = t_treasure(['name' => 'TEST community zdobyty', 'lat' => 54.802, 'lon' => 17.902]);
    $wolny = t_treasure(['name' => 'TEST community wolny', 'lat' => 54.8026, 'lon' => 17.9026]);
    Treasure::claim($zdobyty['code'], $znalazca, 54.802, 17.902);

    $nazwy = array_column(
        Treasure::inBounds(sk_bounds(), $widz, 300, null, 'not', 'community'),
        'name'
    );
    t_same(['TEST community wolny'], $nazwy, 'zdobyty przez KOGOKOLWIEK znika z „nieodkrytych"');
});

t_test('warstwy: bez `mineScope` (domyślnie `viewer`) zachowanie sprzed Etapu 3 bez zmian', function () {
    [$znalazca, $widz] = t_users(2);
    $t = t_treasure(['name' => 'TEST domyślna skala', 'lat' => 54.803, 'lon' => 17.903]);
    Treasure::claim($t['code'], $znalazca, 54.803, 17.903);

    t_count(0, Treasure::inBounds(sk_bounds(), $widz, 300, null, 'only'),
        'domyślnie liczy się WZGLĘDEM WIDZA, nie społeczności — cudze znalezisko nie liczy się jako moje');
});

// --- Panel: szukanie, filtry, kasowanie (2026-08-20) -------------------
//
// Zgłoszenia usera: „brak możliwości usunięcia", „szukanie po liście jest dość
// kłopotliwe, co w przypadku 1000 punktów?". Lista dostała ten sam zestaw
// narzędzi co panel znanych tras, a kasowanie — regułę, która nie pozwala
// osierocić punktów w niezmiennym rejestrze.

t_test('panel: szukanie łapie nazwę, kod i region', function () {
    $t = t_treasure(['name' => 'ZZTEST Kapliczka Szukana', 'lat' => 54.80, 'lon' => 17.90]);

    $poNazwie = Treasure::search(['szukaj' => 'ZZTEST Kapliczka']);
    $poKodzie = Treasure::search(['szukaj' => substr((string) $t['code'], 0, 10)]);

    t_eq(1, $poNazwie['total'], 'nazwa znajduje dokładnie ten punkt');
    t_eq('ZZTEST Kapliczka Szukana', $poNazwie['items'][0]['name'], 'i to ten właściwy');
    t_eq(1, $poKodzie['total'], 'kod z naklejki też prowadzi do punktu');
});

t_test('panel: filtry rozdzielają aktywne, zgłoszone i wycofane', function () {
    t_treasure(['name' => 'ZZTEST aktywny', 'status' => 'ACTIVE']);
    t_treasure(['name' => 'ZZTEST zgloszony', 'status' => 'PROPOSED']);
    t_treasure(['name' => 'ZZTEST wycofany', 'status' => 'RETIRED']);

    $nazwy = static function (string $filtr): array {
        return array_column(Treasure::search(['szukaj' => 'ZZTEST', 'filtr' => $filtr])['items'], 'name');
    };

    t_same(['ZZTEST aktywny'], $nazwy('aktywne'), 'aktywne to tylko ACTIVE + widoczne');
    t_same(['ZZTEST zgloszony'], $nazwy('zgloszone'), 'zgłoszone czekają na decyzję');
    t_same(['ZZTEST wycofany'], $nazwy('wycofane'), 'wycofane schodzą do archiwum');
    t_count(3, $nazwy(''), 'bez filtra widać wszystkie trzy');
});

t_test('panel: „nieznalezione" pokazuje punkty, po które nikt nie pojechał', function () {
    $znaleziony = t_treasure(['name' => 'ZZTEST znaleziony', 'lat' => 50.0, 'lon' => 20.0]);
    t_treasure(['name' => 'ZZTEST nietknięty', 'lat' => 50.0, 'lon' => 20.0]);
    Treasure::claim($znaleziony['code'], t_user(0), 50.0, 20.0);

    $nazwy = array_column(
        Treasure::search(['szukaj' => 'ZZTEST', 'filtr' => 'nieznalezione'])['items'], 'name'
    );

    t_same(['ZZTEST nietknięty'], $nazwy, 'tylko ten, którego nikt nie ma');
});

t_test('panel: lista jest stronicowana, a nie wysypana w całości', function () {
    // Sedno pytania usera o tysiąc punktów: zapytanie ma oddawać STRONĘ, nie
    // wszystko, co jest w tabeli.
    $wynik = Treasure::search([]);

    t_true(count($wynik['items']) <= Treasure::PER_PAGE, 'strona nie przekracza PER_PAGE');
    t_true($wynik['total'] >= count($wynik['items']), 'licznik mówi o CAŁOŚCI, nie o stronie');
    t_eq(max(1, (int) ceil($wynik['total'] / Treasure::PER_PAGE)), $wynik['stron'], 'liczba stron się zgadza');
});

t_test('panel: kasowanie działa na punkcie, którego nikt nie znalazł', function () {
    $t = t_treasure(['name' => 'ZZTEST do skasowania']);

    $wynik = Treasure::deleteIfUnfound((int) $t['id']);

    t_true($wynik['ok'], 'skasowany');
    t_null(Treasure::find((int) $t['id']), 'nie ma go w bazie');
});

t_test('panel: znalezionego skarbu NIE da się skasować', function () {
    // To jest ta reguła, dla której kasowanie w ogóle ma warunek: znalezienie
    // zapłaciło punktami, a `point_transactions` jest rejestrem niezmiennym —
    // skasowany skarb zostawiłby w nim wiersze wskazujące na byt, którego nie
    // ma, i zabrałby ludziom punkty z historii.
    $t = t_treasure(['name' => 'ZZTEST znaleziony', 'lat' => 50.0, 'lon' => 20.0]);
    Treasure::claim($t['code'], t_user(0), 50.0, 20.0);

    $wynik = Treasure::deleteIfUnfound((int) $t['id']);

    t_false($wynik['ok'], 'odmowa');
    t_eq('znaleziony', $wynik['reason'], 'z powodem, który da się pokazać człowiekowi');
    t_eq(1, $wynik['finds'], 'i z liczbą znalezień');
    t_not_null(Treasure::find((int) $t['id']), 'skarb dalej jest');
    t_eq(1, Core\Database::connection()->query(
        'SELECT COUNT(*) FROM point_transactions WHERE source = "TREASURE_FOUND" AND source_id = ' . (int) $t['id']
    )->fetchColumn(), 'punkty w rejestrze nietknięte');
});

t_test('panel: mapa panelu dostaje PRAWDZIWĄ pozycję, także skarbu ukrytego', function () {
    // Panel odpowiada AUTOROWI („gdzie to naprawdę stoi"), a nie graczowi
    // („co wolno ci zobaczyć"), więc nie przycina pozycji do środka pola —
    // inaczej przeciągnięcie pinezki przesuwałoby skarb tam, gdzie go nie ma.
    $t = t_treasure(['name' => 'ZZTEST ukryty', 'lat' => 54.801234, 'lon' => 17.901234, 'reveal_level' => 0]);
    $granice = sk_bounds();

    $panel = Treasure::inBoundsAdmin($granice);
    // Ten sam kadr oczami GRACZA, który nie odkrył jeszcze tego pola: ukryty
    // skarb nie istnieje dla niego wcale (`reveal()` zwraca null). To jest
    // najostrzejsza postać kontrastu, o który tu chodzi.
    $gracz = Treasure::inBounds($granice, t_user(0));

    $zPanelu = null;
    foreach ($panel as $row) { if ((int) $row['id'] === (int) $t['id']) { $zPanelu = $row; } }

    t_not_null($zPanelu, 'punkt jest w kadrze panelu');
    t_eq('54.8012340', $zPanelu['lat'], 'pozycja co do metra');
    t_eq('ZZTEST ukryty', $zPanelu['name'], 'z nazwą, bo panel ją zna');
    // Ten sam punkt widziany przez gracza jest przycięty i bez nazwy.
    t_count(0, $gracz, 'gracz bez odkrytego pola nie dostaje go w ogóle');
});

t_test('panel mapy: lista „czekają" daje ID, ale NIE współrzędne', function () {
    // Zgłoszenie usera 2026-08-20: „nie da się na nie kliknąć, więc trudno je
    // zlokalizować". Klikalność wymaga identyfikatora — ale położenie musi
    // przejść przez bramkę ujawnienia, więc strona pyta o nie osobno
    // (`/api/treasures/{id}`). Gdyby lat/lon wychodziły tu razem z nazwą,
    // dokładne miejsce skarbu UKRYTEGO stałoby w źródle strony.
    $lista = Treasure::waitingList(3);

    t_true(count($lista) > 0, 'jest o czym mówić');
    foreach ($lista as $row) {
        t_true(($row['id'] ?? 0) > 0, 'wiersz niesie id — po nim strona pyta o pozycję');
        t_false(array_key_exists('lat', $row), 'i NIE niesie szerokości');
        t_false(array_key_exists('lon', $row), 'ani długości');
    }
});

t_test('panel mapy: znaleziony skarb znika z listy „czekają"', function () {
    // Bierzemy PIERWSZY z listy (najdłużej nietknięty), zaliczamy go i pytamy
    // o listę jeszcze raz — tak sprawdza się warunek, na którym ta lista stoi.
    $pierwszy = Treasure::waitingList(1)[0] ?? null;
    if ($pierwszy === null) {
        t_true(true, 'brak nieznalezionych skarbów w bazie DEV — nie ma czego sprawdzać');
        return;
    }

    $skarb = Treasure::find((int) $pierwszy['id']);
    Treasure::claim((string) $skarb['code'], t_user(0), (float) $skarb['lat'], (float) $skarb['lon']);

    $poZaliczeniu = array_column(Treasure::waitingList(3), 'id');

    t_false(in_array((int) $pierwszy['id'], array_map('intval', $poZaliczeniu), true),
        'lista jest o tych, po których nikt nie pojechał');
});

// --- Dymek na mapie: co skarb o sobie mówi (2026-08-20) ----------------
//
// Zgłoszenie usera: „skarby, które przeglądamy i klikamy na komputerze, nie
// powinny mieć przycisku »jestem tutaj« (…) rozbudowałbym o okienko
// z informacjami, które już są zawarte w skarbie". Sam przycisk to sprawa
// przeglądarki (`pointer: coarse`), ale DANE musi przysłać serwer — i tylko
// tym skarbom, którym wolno je pokazać.

t_test('dymek: skarb jawny niesie opis, rzadkość, promień i liczbę znalazców', function () {
    $t = t_treasure([
        'name' => 'ZZTEST z opisem', 'lat' => 54.80, 'lon' => 17.90,
        'description' => 'Stary młyn nad rzeką.', 'rarity' => 'EPIC',
        'claim_radius_m' => 220, 'reveal_level' => 2,
    ]);
    Treasure::claim($t['code'], t_user(1), 54.80, 17.90);

    $row = Treasure::inBounds(sk_bounds(), t_user(0))[0] ?? null;

    t_not_null($row, 'skarb jest w kadrze');
    t_eq('exact', $row['reveal'], 'jawny');
    t_eq('Stary młyn nad rzeką.', $row['description'], 'opis idzie do dymka');
    t_eq('EPIC', $row['rarity'], 'rzadkość też');
    t_eq(220, $row['claim_radius_m'], 'i promień zaliczenia');
    t_eq(1, $row['finders'], 'oraz LICZBA znalazców (nie nazwiska — §27)');
});

t_test('dymek: skarb ukryty nie zdradza opisu ani rzadkości', function () {
    // Poziom ujawnienia ma sens tylko wtedy, gdy trzyma WSZYSTKIE tropy: opis
    // „stary młyn nad rzeką" zawęża poszukiwania dokładnie tak samo jak nazwa,
    // którą `reveal()` podmienia na „Coś tu jest".
    $t = t_treasure([
        'name' => 'ZZTEST ukryty z opisem', 'lat' => 54.80, 'lon' => 17.90,
        'description' => 'Stary młyn nad rzeką.', 'rarity' => 'LEGENDARY', 'reveal_level' => 0,
    ]);
    // Widz MA odkryte pole tego skarbu — inaczej `reveal()` w ogóle by go nie
    // pokazał i test nie sprawdzałby tego, co miał.
    $pelny = Treasure::find((int) $t['id']);
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (:u, :c, NOW())')
        ->execute(['u' => t_user(0), 'c' => (int) $pelny['cell_id']]);

    $row = Treasure::inBounds(sk_bounds(), t_user(0))[0] ?? null;

    t_not_null($row, 'w odkrytym polu skarb ukryty jest widoczny');
    t_eq('hidden', $row['reveal'], 'ale jako ukryty');
    t_eq('Coś tu jest', $row['name'], 'bez nazwy');
    t_null($row['description'], 'bez opisu');
    t_null($row['rarity'], 'bez rzadkości');
    t_null($row['finders'], 'i bez liczby znalazców');
    t_null($row['photo_url'], 'zdjęcia też nie ma');
});

// --- GALERIA ZDJEC (SKA/14, migr. 066) --------------------------------
//
// Zgloszenie usera 2026-08-22: „dla wszystkich skarbow dodalbym zdjecie glowne
// jak rowniez mozliwosc zbudowania galerii zdjec. (...) Dla jawnych skarbow
// pojawialy by sie jako miniaturka (...). Dla Trop i ukryty dopiero po odkryciu."
//
// ZDJECIE JEST NAJMOCNIEJSZYM SPOILEREM W TYM MODULE: pokazuje dokladnie czego
// szukac, wiec jego wyciek znosi cala zagadke skuteczniej niz wyciek nazwy.
// Ponizsze testy pilnuja jednej rzeczy: galeria wychodzi DOKLADNIE tam, gdzie
// wychodzi `photo_url`, i nigdzie indziej.

t_test('Galeria: jawny skarb pokazuje zdjecia kazdemu, takze niezalogowanemu', function () use ($granice) {
    $t = t_treasure(['reveal_level' => 2, 'name' => 'TEST jawny ze zdjeciami']);
    Models\TreasurePhoto::add((int) $t['id'], ['/a.jpg', '/b.jpg'], null);

    foreach (Treasure::inBounds($granice, null) as $row) {
        if ((int) $row['id'] !== (int) $t['id']) { continue; }
        t_eq(2, $row['photos_count'], 'licznik zdjec wychodzi bez logowania');
        return;
    }
    t_fail('jawny skarb zniknal z mapy');
});

t_test('Galeria: przy Tropie licznik NIE wychodzi, mimo ze pole jest odkryte', function () use ($granice) {
    // Najwazniejszy przypadek calej funkcji. Skarb JEST widoczny (pole odkryte),
    // wiec latwo o blad „skoro widac pinezke, to pokazmy tez zdjecia" — a wtedy
    // trop przestaje byc tropem. `null`, nie `0`: zero znaczyloby „sprawdzilem,
    // nie ma zdjec", a my nie mowimy nawet tyle.
    $db = Core\Database::connection();
    $user = t_user(0);
    $trop = t_treasure(['reveal_level' => 1, 'name' => 'TEST trop ze zdjeciami', 'hint' => 'Przy dębie']);
    Models\TreasurePhoto::add((int) $trop['id'], ['/a.jpg', '/b.jpg', '/c.jpg'], null);

    $db->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
       ->execute(['u' => $user, 'c' => $trop['cell_id']]);

    foreach (Treasure::inBounds($granice, $user) as $row) {
        if ((int) $row['id'] !== (int) $trop['id']) { continue; }
        t_eq('hint', $row['reveal'], 'skarb jest widoczny jako trop');
        t_null($row['photo_url'], 'zdjecie glowne nie wychodzi');
        t_null($row['photos_count'], 'licznik galerii tez nie — i to jest sedno');
        return;
    }
    t_fail('trop zniknal z mapy mimo odkrytego pola');
});

t_test('Galeria: znalazca widzi zdjecia ukrytego skarbu', function () use ($granice) {
    // Druga polowa reguly: po znalezieniu wszystko sie odslania. Bez tego
    // ukrycie przestaje byc zagadka i staje sie po prostu brakiem funkcji.
    $t = t_treasure(['reveal_level' => 0, 'name' => 'TEST ukryty znaleziony']);
    Models\TreasurePhoto::add((int) $t['id'], ['/a.jpg'], null);
    Treasure::claim($t['code'], t_user(0), 50.0, 20.0);

    foreach (Treasure::inBounds($granice, t_user(0)) as $row) {
        if ((int) $row['id'] !== (int) $t['id']) { continue; }
        t_eq(1, $row['photos_count'], 'znalazca widzi licznik');
        return;
    }
    t_fail('wlasny ukryty skarb zniknal z mapy');
});

t_test('Galeria: foundBy jest bramka dodawania zdjec', function () {
    // Na tej metodzie stoi i to, KTO widzi galerie skarbu ukrytego, i to, kto
    // moze do niej dorzucic zdjecie (TreasureScanController::addPhoto).
    $t = t_treasure(['reveal_level' => 2]);
    t_false(Treasure::foundBy((int) $t['id'], t_user(1)), 'przed znalezieniem nie');
    Treasure::claim($t['code'], t_user(1), 50.0, 20.0);
    t_true(Treasure::foundBy((int) $t['id'], t_user(1)), 'po znalezieniu tak');
});

t_test('Galeria: kolejnosc trzyma sie po skasowaniu srodkowego zdjecia', function () {
    // `sort_order` liczymy od MAKSIMUM, nie od liczby wierszy — inaczej po
    // skasowaniu srodkowego nowe zdjecie wskakiwaloby przed istniejace.
    $t = t_treasure();
    $id = (int) $t['id'];
    Models\TreasurePhoto::add($id, ['/1.jpg', '/2.jpg', '/3.jpg'], null);
    $zdjecia = Models\TreasurePhoto::forTreasure($id);
    Models\TreasurePhoto::delete((int) $zdjecia[1]['id']);
    Models\TreasurePhoto::add($id, ['/4.jpg'], null);

    t_same(['/1.jpg', '/3.jpg', '/4.jpg'],
        array_column(Models\TreasurePhoto::forTreasure($id), 'url'),
        'nowe zdjecie ladu je na koncu, nie w srodku');
});

t_test('Galeria: limit na osobe liczy sie per skarb, nie globalnie', function () {
    // Limit ma powstrzymac jednego czlowieka przed zapelnieniem galerii soba,
    // a nie ograniczac jego udzial w calym module.
    $a = t_treasure();
    $b = t_treasure();
    $user = t_user(2);
    Models\TreasurePhoto::add((int) $a['id'], ['/x.jpg', '/y.jpg'], $user);
    Models\TreasurePhoto::add((int) $b['id'], ['/z.jpg'], $user);

    t_eq(2, Models\TreasurePhoto::countForUser((int) $a['id'], $user), 'dwa przy pierwszym');
    t_eq(1, Models\TreasurePhoto::countForUser((int) $b['id'], $user), 'jedno przy drugim');
});

t_test('Galeria: skasowanie skarbu zabiera jego zdjecia', function () {
    // ON DELETE CASCADE — bez niego wiersze zostawalyby jako sieroty wskazujace
    // na nieistniejacy skarb i wchodzily do licznikow.
    $t = t_treasure();
    $id = (int) $t['id'];
    Models\TreasurePhoto::add($id, ['/a.jpg'], null);
    Core\Database::connection()->prepare('DELETE FROM treasures WHERE id = :id')->execute(['id' => $id]);

    t_count(0, Models\TreasurePhoto::forTreasure($id), 'zdjecia poszly razem ze skarbem');
});

t_test('Galeria: adresy w JSON niosa base_path i wariant, nie surowy wpis z bazy', function () {
    // ZGLOSZENIE USERA 2026-08-22: „nie pojawia sie w dymku — czemu nie
    // korzystasz z budowania url i odpowiedniej wielkosci?!". Adres szedl
    // do przegladarki surowy z bazy („/assets/uploads/..."), a aplikacja stoi
    // w podkatalogu — ZMIERZONE: 404. Do tego oryginal 1200 px ladowal sie
    // do kafelka wysokiego na 110 px.
    //
    // Test nie rusza HTTP: sprawdza dokladnie te dwie transformacje, ktore
    // api/routes.php nakłada na wiersz przed json_encode. Gdyby ktos w
    // przyszlosci dolozyl trzecie miejsce zwracajace zdjecia skarbu, to tutaj
    // stoi opis tego, co musi zrobic.
    $surowy = '/assets/uploads/gallery/test.jpg';

    $thumb = Utils\Image::src($surowy, 'thumb');
    $full  = Utils\View::url($surowy);

    t_true(str_starts_with($thumb, Utils\View::url('/')), 'miniatura ma base_path aplikacji');
    t_true(str_starts_with($full, Utils\View::url('/')), 'pelny plik tez');
    // Plik testowy nie istnieje na dysku, wiec `Image::src` odda oryginal —
    // i to jest poprawne zachowanie (patrz nota nad Utils\Image::path).
    // Sprawdzamy WYLACZNIE to, czego ten helper nigdy nie moze pominac.
    t_false(str_starts_with($thumb, '/assets/'), 'miniatura nie jest surowym wpisem z bazy');
    t_false(str_starts_with($full, '/assets/'), 'pelny plik nie jest surowym wpisem z bazy');
});

// --- Lista skarbów na trasie (strona /trasy/{slug}, 2026-08-23) ------
//
// Prośba usera: „chciałbym zobaczyć, jakie skarby (w domyśle atrakcje) zobaczę
// na trasie". Strona trasy jest PUBLICZNA, więc najważniejsze w tych testach
// jest to, czego lista NIE POKAZUJE — bo tu byłoby najłatwiej obejść całą
// zagadkę ukrytych skarbów: wejdź na stronę, przeczytaj listę, jedź pod punkty.

/** Trasa z podanymi polami, w podanej kolejności wzdłuż śladu. */
function t_route_with_cells(array $cellIds): int
{
    $db = Core\Database::connection();
    $slug = 'zztest-trasa-' . bin2hex(random_bytes(4));
    $db->prepare('INSERT INTO known_routes (slug, name, distance_km, cells_total, is_active, created_at)
                  VALUES (:s, :n, 10, :c, 1, NOW())')
        ->execute(['s' => $slug, 'n' => 'ZZTEST ' . $slug, 'c' => count($cellIds)]);
    $routeId = (int) $db->lastInsertId();

    $ins = $db->prepare('INSERT INTO known_route_cells (route_id, cell_id, sort_order) VALUES (:r, :c, :o)');
    foreach (array_values($cellIds) as $i => $cellId) {
        $ins->execute(['r' => $routeId, 'c' => $cellId, 'o' => $i + 1]);
    }

    return $routeId;
}

t_test('trasa: lista pokazuje skarby leżące na jej polach, w kolejności wzdłuż śladu', function () {
    // Kolejność jest treścią, nie ozdobą: lista ma się czytać jak plan wyprawy.
    $a = t_treasure(['name' => 'ZZ Pierwszy', 'lat' => 49.20, 'lon' => 22.10]);
    $b = t_treasure(['name' => 'ZZ Drugi', 'lat' => 49.30, 'lon' => 22.30]);
    // Do trasy wpinamy je w kolejności ODWROTNEJ do identyfikatorów, żeby test
    // sprawdzał sort_order, a nie przypadkową zgodność z kolejnością wstawiania.
    $routeId = t_route_with_cells([(int) $b['cell_id'], (int) $a['cell_id']]);

    $lista = Treasure::listOnRoute($routeId, null);

    t_count(2, $lista['items'], 'oba skarby na liście');
    t_eq('ZZ Drugi', $lista['items'][0]['name'], 'pierwszy jest ten wcześniejszy na śladzie');
    t_eq('ZZ Pierwszy', $lista['items'][1]['name'], 'drugi jest ten dalszy');
    t_same(0, $lista['hidden'], 'nic nie jest ukryte');
});

t_test('trasa: skarb spoza trasy nie wchodzi na listę', function () {
    $naTrasie = t_treasure(['name' => 'ZZ Na trasie', 'lat' => 49.20, 'lon' => 22.10]);
    t_treasure(['name' => 'ZZ Gdzie indziej', 'lat' => 52.40, 'lon' => 16.90]);
    $routeId = t_route_with_cells([(int) $naTrasie['cell_id']]);

    $lista = Treasure::listOnRoute($routeId, null);

    t_count(1, $lista['items'], 'tylko ten z pola trasy');
    t_eq('ZZ Na trasie', $lista['items'][0]['name'], 'i to ten właściwy');
});

t_test('trasa: UKRYTY skarb nie wychodzi gościowi ani nazwą, ani zdjęciem', function () {
    // To jest najważniejszy test tej sekcji. Publiczna lista na stronie trasy
    // byłaby najłatwiejszym sposobem na obejście ukrycia — sprawdzamy, że
    // wychodzi z niej WYŁĄCZNIE liczba.
    $ukryty = t_treasure([
        'name'         => 'ZZ Tajemnica',
        'lat'          => 49.20, 'lon' => 22.10,
        'reveal_level' => 0,
        'photo_url'    => '/assets/uploads/gallery/test.jpg',
        'description'  => 'Opis, ktory zdradza wszystko',
    ]);
    $routeId = t_route_with_cells([(int) $ukryty['cell_id']]);

    $lista = Treasure::listOnRoute($routeId, null);

    t_count(0, $lista['items'], 'gość nie dostaje ani jednej pozycji');
    t_same(1, $lista['hidden'], 'dostaje samą liczbę, żeby lista zgadzała się z licznikiem');
    t_false(str_contains(json_encode($lista), 'Tajemnica'), 'nazwa nie wycieka w żadnym polu');
    t_false(str_contains(json_encode($lista), 'test.jpg'), 'zdjęcie nie wycieka');
});

t_test('trasa: kto odkrył pole, dostaje TROP — bez zdjęcia, opisu i prawdziwej nazwy', function () {
    $userId = t_user();
    $ukryty = t_treasure([
        'name'         => 'ZZ Tajemnica',
        'lat'          => 49.20, 'lon' => 22.10,
        'reveal_level' => 1,
        'hint'         => 'Za mostem w prawo',
        'photo_url'    => '/assets/uploads/gallery/test.jpg',
        'description'  => 'Opis, ktory zdradza wszystko',
    ]);
    $routeId = t_route_with_cells([(int) $ukryty['cell_id']]);

    // Odkryte pole = ten człowiek tamtędy przejechał, więc trop mu się należy.
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (:u, :c, NOW())')
        ->execute(['u' => $userId, 'c' => (int) $ukryty['cell_id']]);

    $lista = Treasure::listOnRoute($routeId, $userId);

    t_count(1, $lista['items'], 'pozycja się pojawia');
    $poz = $lista['items'][0];
    t_eq('hint', $poz['reveal'], 'ale wyłącznie jako trop');
    t_eq('Za mostem w prawo', $poz['hint'], 'ze wskazówką');
    t_true($poz['name'] !== 'ZZ Tajemnica', 'prawdziwa nazwa dalej ukryta');
    t_null($poz['photo_url'], 'zdjęcie dalej ukryte');
    t_null($poz['description'], 'opis dalej ukryty');
});

t_test('trasa: znalazca widzi swój skarb w pełni', function () {
    // Kto tam był, nie ma czego ukrywać — mapa i lista muszą pokazywać jego
    // kolekcję w całości, inaczej postęp jest niewidoczny.
    $userId = t_user();
    $ukryty = t_treasure([
        'name'         => 'ZZ Tajemnica',
        'lat'          => 49.20, 'lon' => 22.10,
        'reveal_level' => 0,
        'photo_url'    => '/assets/uploads/gallery/test.jpg',
    ]);
    $routeId = t_route_with_cells([(int) $ukryty['cell_id']]);
    Treasure::claim($ukryty['code'], $userId, 49.20, 22.10);

    $lista = Treasure::listOnRoute($routeId, $userId);

    t_count(1, $lista['items'], 'skarb jest na liście');
    t_eq('ZZ Tajemnica', $lista['items'][0]['name'], 'z prawdziwą nazwą');
    t_true(!empty($lista['items'][0]['mine']), 'oznaczony jako własny');
    t_same(0, $lista['hidden'], 'nic już przed nim nie ukrywamy');
});

// --- GPX: skarb ze śladu WGRANEGO PRZEZ ORGANIZATORA, nie przez znalazcę ---
//
// Pytanie usera: uczestnik, który sam nie wgrał śladu, ale organizator wgrał
// zbiorowy ślad z imprezy — czy uczestnikowi liczy się PRZEJAZD i SKARB leżący
// na trasie? Test bierze PRAWDZIWY plik GPX z katalogu uploadów (nie linię
// prostą wymyśloną w kodzie), stawia skarb dokładnie na jednym z jego punktów
// i przechodzi realną drogę zdarzeń: RSVP → potwierdzona obecność (jeszcze bez
// żadnego śladu) → EditionTrack::attach() jako ślad ZBIOROWY (user_id = null —
// dokładnie to, co TrackController robi dla kogoś z prawem edycji wydarzenia)
// → sprawdzenie, że RiderActivity i Treasure policzyły to uczestnikowi, mimo
// że to nie on nic wgrywał.

/**
 * Kopia PRAWDZIWEGO pliku z katalogu uploadów pod NOWĄ, jednorazową nazwą.
 * Kopia, nie oryginał: EditionTrack::remove() kasuje plik z dysku NAPRAWDĘ —
 * transakcja testu tego nie cofnie — więc oryginalny plik dev nie może być tym,
 * co ten test rusza. Największy plik z katalogu, żeby mieć pewność, że jest
 * w nim więcej niż kilka punktów.
 */
function t_skarb_kopia_realnego_gpx(): array
{
    $zrodla = glob(CORE_PATH . '/../assets/uploads/gpx/*.gpx') ?: [];
    if (!$zrodla) {
        t_fail('Brak jakiegokolwiek pliku GPX w assets/uploads/gpx — ten test potrzebuje prawdziwego śladu.');
    }
    usort($zrodla, static fn(string $a, string $b): int => filesize($b) <=> filesize($a));

    $nazwa = bin2hex(random_bytes(16)) . '.gpx';
    $cel = CORE_PATH . '/../assets/uploads/gpx/' . $nazwa;
    if (!copy($zrodla[0], $cel)) {
        t_fail('Nie udało się skopiować testowego pliku GPX.');
    }
    return ['path' => $cel, 'url' => '/assets/uploads/gpx/' . $nazwa];
}

t_test('Ślad organizatora bez śladu uczestnika: przejazd I skarb po drodze zaliczają się temu, kto nic nie wgrał', function () {
    $gpx = t_skarb_kopia_realnego_gpx();
    try {
        $trasa = \Utils\Gpx::parse($gpx['path']);
        t_true(count($trasa['points']) > 10, 'plik testowy ma sensowną liczbę punktów');

        // Skarb DOKŁADNIE na trasie — punkt w połowie realnego śladu, nie
        // wymyślona współrzędna, którą dopiero trzeba by trafić w GPX.
        $srodek = $trasa['points'][(int) floor(count($trasa['points']) / 2)];
        $t = t_treasure([
            'name'           => 'TEST skarb ze śladu organizatora',
            'lat'            => $srodek['lat'],
            'lon'            => $srodek['lon'],
            'claim_radius_m' => 150,
            'points'         => 88,
        ]);

        [$organizator, $uczestnik] = t_users(2);
        $db = Core\Database::connection();

        // Wydarzenie i turnus od zera — czyste, bez cudzych śladów i skarbów.
        $db->prepare('
            INSERT INTO events (organizer_id, event_type_item_id, status_item_id, title, slug, start_date)
            VALUES (:org, :typ, :status, :tytul, :slug, CURDATE())
        ')->execute([
            'org'    => $organizator,
            'typ'    => Models\Dictionary::id('event_type', 'ustawka'),
            'status' => Models\Dictionary::id('event_status', 'published'),
            'tytul'  => 'TEST ślad organizatora',
            'slug'   => 'test-slad-org-' . bin2hex(random_bytes(6)),
        ]);
        $eventId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO event_editions (event_id, start_date) VALUES (:e, CURDATE())')
            ->execute(['e' => $eventId]);
        $editionId = (int) $db->lastInsertId();

        $db->prepare('
            INSERT INTO event_rsvps (event_id, edition_id, user_id, status_item_id, joined_at)
            VALUES (:e, :ed, :u, :s, NOW())
        ')->execute([
            'e'  => $eventId,
            'ed' => $editionId,
            'u'  => $uczestnik,
            's'  => Models\Dictionary::id('rsvp_status', 'potwierdzony'),
        ]);
        $rsvpId = (int) $db->lastInsertId();

        // Uczestnik potwierdza „Byłem" ZANIM jakikolwiek ślad istnieje — to
        // najczęstsza realna kolejność (organizator dogrywa ślad po fakcie).
        Models\EventAttendance::declare($rsvpId, true, $uczestnik, false);
        t_count(0, $db->query(
            'SELECT id FROM rider_activities WHERE rsvp_id = ' . $rsvpId
        )->fetchAll(), 'bez śladu przejazd jeszcze się NIE liczy, mimo potwierdzonej obecności');

        // ORGANIZATOR wgrywa ślad ZBIOROWY (user_id = null) — to samo, co robi
        // TrackController::upload() dla kogoś z prawem edycji wydarzenia.
        Models\EditionTrack::attach($editionId, null, $gpx['url'], 'Ślad z imprezy', 42.0, $organizator);

        // PRZEJAZD: powinien się policzyć uczestnikowi, źródłem „ślad z imprezy".
        $przejazd = $db->query(
            'SELECT source_code FROM rider_activities WHERE rsvp_id = ' . $rsvpId
        )->fetch();
        t_not_null($przejazd, 'uczestnik dostał policzony przejazd ze śladu organizatora');
        t_eq(Models\RiderActivity::SOURCE_EVENT_TRACK, $przejazd['source_code'], 'źródło to ślad z imprezy, nie własny');

        // SKARB: zaliczony temu, kto nic nie wgrał, bo jechał tym samym śladem.
        t_true(Treasure::foundBy((int) $t['id'], $uczestnik),
            'skarb na trasie zaliczył się uczestnikowi mimo braku własnego wgrania');
        t_eq(1, $db->query(
            'SELECT COUNT(*) FROM point_transactions WHERE source = "TREASURE_FOUND" AND source_id = '
                . (int) $t['id'] . ' AND user_id = ' . $uczestnik
        )->fetchColumn(), 'punkty za skarb naliczone w rejestrze uczestnika');

        // DYMEK: dokładnie to, co zobaczy uczestnik na mapie — Treasure::inBounds
        // karmi /api/treasures i dymek (assets/js/discovery-map.js -> dymekSkarbu).
        // `mine=1` zmienia pinezkę na złotą (`is-found`), a `points` to właśnie
        // liczba za chipem „+" w dymku.
        $wPolu = null;
        foreach (Treasure::inBounds([
            'north' => $srodek['lat'] + 0.01, 'south' => $srodek['lat'] - 0.01,
            'east'  => $srodek['lon'] + 0.01, 'west'  => $srodek['lon'] - 0.01,
        ], $uczestnik) as $wiersz) {
            if ((int) $wiersz['id'] === (int) $t['id']) { $wPolu = $wiersz; }
        }
        t_not_null($wPolu, 'skarb wychodzi w odpowiedzi mapy dla uczestnika');
        t_true((bool) $wPolu['mine'], 'oznaczony jako zdobyty PRZEZ UCZESTNIKA — dymek pokaże złotą pinezkę');
        t_eq(88, $wPolu['points'], 'dymek pokaże chip „+88" — dokładnie tyle, ile płaci ten skarb');
        t_true((int) $wPolu['finders'] >= 1, 'licznik „ilu już znalazło" w dymku wzrósł o tę osobę');
    } finally {
        @unlink($gpx['path']);
    }
});
