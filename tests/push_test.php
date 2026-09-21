<?php
// tests/push_test.php
// PUSH DO APKI MOBILNEJ (Etap 8 przebudowy apki, tasks/active/apka-mobilna.md,
// 2026-08-28) — rejestracja urządzeń, driver 'log' (Core\Push, wzorzec
// Core\Mailer) i trzy zaczepienia treści: RSVP, dwa czaty, nowy skarb w okolicy.
//
// DRIVER JEST 'log' NA DEV (core/config.php, $env('PUSH_DRIVER','log')) —
// testy więc NIE wysyłają niczego naprawdę, tylko sprawdzają, że
// Core\Push::sendToUser() dopisuje do storage/push.log to, co powinno.
// Plik jest WSPÓLNY i rośnie przez cały przebieg testów (append-only) — stąd
// t_push_log_since(), nie zakładanie, że plik jest pusty na starcie.
use Models\Discovery;
use Models\EventGroupConversation;
use Models\EventRsvp;
use Models\Message;
use Models\PushDevice;
use Models\PushNotifier;
use Models\Treasure;
use Utils\DiscoveryGrid;

function t_push_log_path(): string
{
    return CORE_PATH . '/../storage/push.log';
}

function t_push_log_size(): int
{
    $p = t_push_log_path();
    return is_file($p) ? filesize($p) : 0;
}

function t_push_log_since(int $offset): string
{
    $p = t_push_log_path();
    if (!is_file($p)) { return ''; }
    $h = fopen($p, 'rb');
    fseek($h, $offset);
    $data = (string) stream_get_contents($h);
    fclose($h);
    return $data;
}

/**
 * Minimalne wydarzenie pod testy RSVP/czatu grupowego — żaden istniejący
 * test w tym repo jeszcze nie ćwiczył EventRsvp::join()/EventGroupConversation
 * z prawdziwymi wierszami, więc nie ma gotowego fixture'u do przejęcia.
 */
function t_event(array $overrides = []): int
{
    $db = Core\Database::connection();
    $stmt = $db->prepare('
        INSERT INTO events (organizer_id, event_type_item_id, status_item_id, title, slug, start_date)
        VALUES (:org, :type, :status, :title, :slug, CURDATE() + INTERVAL 7 DAY)
    ');
    $stmt->execute([
        'org'    => $overrides['organizer_id'] ?? t_user(0),
        'type'   => Models\Dictionary::id('event_type', 'ustawka'),
        'status' => Models\Dictionary::id('event_status', 'published'),
        'title'  => $overrides['title'] ?? 'TEST wyjazd push',
        'slug'   => 'test-push-' . bin2hex(random_bytes(6)),
    ]);
    return (int) $db->lastInsertId();
}

function t_edition(int $eventId): int
{
    $db = Core\Database::connection();
    $stmt = $db->prepare('SELECT start_date FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $startDate = $stmt->fetchColumn();

    $ins = $db->prepare('INSERT INTO event_editions (event_id, start_date) VALUES (?, ?)');
    $ins->execute([$eventId, $startDate]);
    return (int) $db->lastInsertId();
}

function t_conversation(int $userA, int $userB): int
{
    $db = Core\Database::connection();
    $low = min($userA, $userB);
    $high = max($userA, $userB);
    $stmt = $db->prepare('
        INSERT INTO conversations (user_low_id, user_high_id) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ');
    $stmt->execute([$low, $high]);
    return (int) $db->lastInsertId();
}

/** Dwie osobne, realne komórki z RÓŻNYMI regionami — dane z backfill_regions.php. */
function t_two_regions(): array
{
    $db = Core\Database::connection();
    $a = $db->query('SELECT region_item_id, cell_id FROM region_cells LIMIT 1')->fetch();
    t_not_null($a, 'region_cells ma dane (uruchom backfill_regions.php)');
    $stmt = $db->prepare('SELECT region_item_id, cell_id FROM region_cells WHERE region_item_id <> :r LIMIT 1');
    $stmt->execute(['r' => $a['region_item_id']]);
    $b = $stmt->fetch();
    t_not_null($b, 'istnieją co najmniej dwa regiony z pokryciem');
    return [$a, $b];
}

// --- PushDevice --------------------------------------------------------

t_test('PushDevice: rejestracja jest zgodą, wyłączenie ją cofa bez kasowania wiersza', function () {
    $u = t_user(0);
    $token = 'tok-' . bin2hex(random_bytes(8));

    t_false(PushDevice::hasActiveForUser($u), 'przed rejestracją brak zgody');

    PushDevice::register($u, 'android', $token);
    t_true(PushDevice::hasActiveForUser($u), 'rejestracja = zgoda');
    t_count(1, PushDevice::activeForUser($u)['android'], 'jedno aktywne urządzenie android');

    PushDevice::deactivateForUser($u);
    t_false(PushDevice::hasActiveForUser($u), 'wyłączenie cofa zgodę');

    PushDevice::activateForUser($u);
    t_true(PushDevice::hasActiveForUser($u), 'ponowne włączenie bez nowej rejestracji tokenu');
});

t_test('PushDevice: token unikalny globalnie — drugie konto na tym samym telefonie przejmuje go', function () {
    $token = 'tok-' . bin2hex(random_bytes(8));
    PushDevice::register(t_user(0), 'android', $token);
    PushDevice::register(t_user(1), 'android', $token);

    t_count(0, PushDevice::activeForUser(t_user(0))['android'], 'stare konto traci token');
    t_count(1, PushDevice::activeForUser(t_user(1))['android'], 'nowe konto go ma');
});

t_test('PushDevice: nieznana platforma i pusty token są po cichu ignorowane', function () {
    $u = t_user(0);
    PushDevice::register($u, 'windows-phone', 'cokolwiek');
    PushDevice::register($u, 'android', '');
    t_false(PushDevice::hasActiveForUser($u), 'żadna z dwóch prób nie zarejestrowała niczego');
});

// --- Core\Push (driver 'log' na dev) ------------------------------------

t_test('Push: sendToUser dopisuje do logu, gdy user ma aktywne urządzenie', function () {
    $u = t_user(0);
    PushDevice::register($u, 'android', 'tok-' . bin2hex(random_bytes(8)));

    $before = t_push_log_size();
    Core\Push::sendToUser($u, 'TEST tytuł', 'TEST treść');
    $added = t_push_log_since($before);

    t_true(str_contains($added, 'user_id=' . $u), 'wpis niesie id odbiorcy');
    t_true(str_contains($added, 'TEST tytuł'), 'wpis niesie tytuł');
});

t_test('Push: bez aktywnego urządzenia sendToUser nic nie dopisuje', function () {
    $u = t_user(2);
    PushDevice::deactivateForUser($u); // sprzątnij ewentualną zgodę z innego testu w tym przebiegu

    $before = t_push_log_size();
    Core\Push::sendToUser($u, 'TEST bez urządzenia', 'treść');
    t_same($before, t_push_log_size(), 'log nie urósł');
});

// --- Zaczepienie 1: „ktoś dołączył do Twojego wyjazdu" ------------------

t_test('Push: potwierdzony RSVP powiadamia organizatora, nie samego dołączającego', function () {
    $db = Core\Database::connection();
    $organizerId = t_user(0);
    $joinerId = t_user(1);
    PushDevice::register($organizerId, 'android', 'tok-' . bin2hex(random_bytes(8)));

    $eventId = t_event(['organizer_id' => $organizerId, 'title' => 'TEST wyjazd push']);
    $editionId = t_edition($eventId);

    $before = t_push_log_size();
    $status = EventRsvp::join($editionId, $joinerId);
    $added = t_push_log_since($before);

    t_eq('potwierdzony', $status, 'zapis potwierdzony (brak limitu miejsc)');
    t_true(str_contains($added, 'user_id=' . $organizerId), 'organizator dostał powiadomienie');
    t_true(str_contains($added, 'TEST wyjazd push'), 'treść niesie tytuł wydarzenia');
});

t_test('Push: organizator dołączający do WŁASNEGO wyjazdu nie dostaje powiadomienia o sobie', function () {
    $organizerId = t_user(0);
    PushDevice::register($organizerId, 'android', 'tok-' . bin2hex(random_bytes(8)));

    $eventId = t_event(['organizer_id' => $organizerId, 'title' => 'TEST własny wyjazd']);
    $editionId = t_edition($eventId);

    $before = t_push_log_size();
    EventRsvp::join($editionId, $organizerId);
    $added = t_push_log_since($before);

    t_false(str_contains($added, 'TEST własny wyjazd'), 'brak powiadomienia o zapisie na własny wyjazd');
});

// --- Zaczepienie 2: „wiadomość na czacie" (1:1) -------------------------

t_test('Push: prywatna wiadomość powiadamia DRUGĄ stronę, nie nadawcę', function () {
    $a = t_user(0);
    $b = t_user(1);
    PushDevice::register($b, 'android', 'tok-' . bin2hex(random_bytes(8)));
    $conversationId = t_conversation($a, $b);

    $before = t_push_log_size();
    Message::send($conversationId, $a, 'TEST treść prywatnej wiadomości');
    $added = t_push_log_since($before);

    t_true(str_contains($added, 'user_id=' . $b), 'odbiorca dostał powiadomienie');
    t_false(str_contains($added, 'TEST treść prywatnej wiadomości'), 'treść wiadomości NIE wchodzi do push (prywatność)');
});

// --- Zaczepienie 3: „wiadomość na czacie" (grupa wyjazdu) ---------------

t_test('Push: wiadomość w kanale grupowym powiadamia członków oprócz nadawcy', function () {
    $organizerId = t_user(0);
    $participantId = t_user(1);
    PushDevice::register($organizerId, 'android', 'tok-' . bin2hex(random_bytes(8)));

    $eventId = t_event(['organizer_id' => $organizerId, 'title' => 'TEST grupa push']);
    $editionId = t_edition($eventId);
    EventRsvp::join($editionId, $participantId); // realny uczestnik = członek kanału
    $convId = EventGroupConversation::findOrCreate($editionId);

    $before = t_push_log_size();
    EventGroupConversation::postMessage($convId, $participantId, 'TEST treść grupowa', false);
    $added = t_push_log_since($before);

    t_true(str_contains($added, 'user_id=' . $organizerId), 'organizator (członek, nie nadawca) dostał powiadomienie');
    t_false(str_contains($added, 'user_id=' . $participantId), 'nadawca nie powiadamia sam siebie');
});

// --- Zaczepienie 4: „nowy skarb w okolicy" (nightly, cron.php) ----------

t_test('PushNotifier: nowy jawny skarb powiadamia usera, który jeździł w tym regionie', function () {
    [$regionA] = t_two_regions();
    $u = t_user(0);
    PushDevice::register($u, 'android', 'tok-' . bin2hex(random_bytes(8)));
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $regionA['cell_id']);
    $t = t_treasure([
        'name' => 'TEST skarb okolica', 'region_item_id' => $regionA['region_item_id'],
        'lat' => $lat, 'lon' => $lon, 'reveal_level' => 2,
    ]);

    $before = t_push_log_size();
    $notified = PushNotifier::runTreasuresNearby();
    $added = t_push_log_since($before);

    $trafienie = null;
    foreach ($notified as $n) { if ($n['treasure_id'] === (int) $t['id'] && $n['user_id'] === $u) { $trafienie = $n; } }
    t_not_null($trafienie, 'wynik metody niesie parę treasure/user');
    t_true(str_contains($added, 'TEST skarb okolica'), 'jawny skarb (poziom 2) niesie prawdziwą nazwę');
});

t_test('PushNotifier: user, który NIE jeździł w tym regionie, nie dostaje powiadomienia', function () {
    [$regionA, $regionB] = t_two_regions();
    $u = t_user(0);
    PushDevice::register($u, 'android', 'tok-' . bin2hex(random_bytes(8)));
    // User odkrył pole w regionie B — skarb postawimy w regionie A.
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionB['cell_id']]);

    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $regionA['cell_id']);
    t_treasure(['name' => 'TEST skarb inny region', 'region_item_id' => $regionA['region_item_id'], 'lat' => $lat, 'lon' => $lon]);

    $notified = PushNotifier::runTreasuresNearby();
    foreach ($notified as $n) {
        if ($n['user_id'] === $u) { t_fail('user spoza regionu skarbu dostał powiadomienie'); }
    }
    t_true(true, 'brak trafienia dla niepasującego regionu');
});

t_test('PushNotifier: ukryty skarb (poziom 0/1) nie zdradza nazwy w treści push', function () {
    [$regionA] = t_two_regions();
    $u = t_user(0);
    PushDevice::register($u, 'android', 'tok-' . bin2hex(random_bytes(8)));
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $regionA['cell_id']);
    t_treasure(['name' => 'TEST tajna nazwa', 'region_item_id' => $regionA['region_item_id'], 'lat' => $lat, 'lon' => $lon, 'reveal_level' => 0]);

    $before = t_push_log_size();
    PushNotifier::runTreasuresNearby();
    $added = t_push_log_since($before);

    t_false(str_contains($added, 'TEST tajna nazwa'), 'nazwa ukrytego skarbu nie wyciekła do powiadomienia');
});

t_test('PushNotifier: skarb starszy niż doba nie powiadamia (okno crona)', function () {
    [$regionA] = t_two_regions();
    $u = t_user(0);
    PushDevice::register($u, 'android', 'tok-' . bin2hex(random_bytes(8)));
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $regionA['cell_id']);
    $t = t_treasure(['name' => 'TEST stary skarb', 'region_item_id' => $regionA['region_item_id'], 'lat' => $lat, 'lon' => $lon]);
    Core\Database::connection()
        ->prepare('UPDATE treasures SET created_at = NOW() - INTERVAL 2 DAY WHERE id = :id')
        ->execute(['id' => $t['id']]);

    $notified = PushNotifier::runTreasuresNearby();
    foreach ($notified as $n) {
        if ($n['treasure_id'] === (int) $t['id']) { t_fail('skarb sprzed 2 dni nadal powiadamia'); }
    }
    t_true(true, 'poza oknem — brak powiadomienia');
});

t_test('PushNotifier: skarb już znaleziony przez usera go nie alarmuje', function () {
    [$regionA] = t_two_regions();
    $u = t_user(0);
    PushDevice::register($u, 'android', 'tok-' . bin2hex(random_bytes(8)));
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $regionA['cell_id']);
    $t = t_treasure(['region_item_id' => $regionA['region_item_id'], 'lat' => $lat, 'lon' => $lon]);
    Treasure::claim($t['code'], $u, $lat, $lon);

    $notified = PushNotifier::runTreasuresNearby();
    foreach ($notified as $n) {
        if ($n['treasure_id'] === (int) $t['id'] && $n['user_id'] === $u) {
            t_fail('już znaleziony skarb nadal alarmuje znalazcę');
        }
    }
    t_true(true, 'znalazca pominięty');
});

/* ========================================================================
   ETAP 1c — ZASIĘG I DYSKRECJA KANAŁU MAILOWEGO (2026-09-11)
   ========================================================================
   Dwa testy pilnują dwóch rzeczy, dla których ten etap w ogóle powstał:
   że zachęta dociera do ludzi BEZ apki, i że mail — trwalszy i przeszukiwalny
   — nie zdradza więcej niż push.
   ======================================================================== */

function t_mail_log_path(): string
{
    return CORE_PATH . '/../storage/mail.log';
}

function t_mail_log_size(): int
{
    $p = t_mail_log_path();
    return is_file($p) ? filesize($p) : 0;
}

function t_mail_log_since(int $offset): string
{
    $p = t_mail_log_path();
    if (!is_file($p)) { return ''; }
    $h = fopen($p, 'rb');
    fseek($h, $offset);
    $data = (string) stream_get_contents($h);
    fclose($h);
    return $data;
}

t_test('PushNotifier: nowy skarb dociera MAILEM do kogoś, kto nie ma apki', function () {
    // TO JEST CAŁY POWÓD ISTNIENIA ETAPU 1c. Do 2026-09-11 zapytanie zaczynało
    // się od `FROM push_devices ... WHERE is_active = 1`, więc ta osoba —
    // z odkrytymi polami, ale bez telefonu z apką — nie miała fizycznie jak
    // dostać zachęty. Brak tego testu znaczy, że powrót starego JOIN-a
    // przechodzi niezauważony: pushowe testy wyżej i tak byłyby zielone.
    [$regionA] = t_two_regions();
    $u = t_user(0);
    // ŻADNEGO PushDevice::register() — i to jest teza tego testu.
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $regionA['cell_id']);
    $t = t_treasure([
        'name' => 'TEST skarb mailem', 'region_item_id' => $regionA['region_item_id'],
        'lat' => $lat, 'lon' => $lon, 'reveal_level' => 2,
    ]);

    $before = t_mail_log_size();
    $notified = PushNotifier::runTreasuresNearby();
    $added = t_mail_log_since($before);

    $trafienie = null;
    foreach ($notified as $n) {
        if ($n['treasure_id'] === (int) $t['id'] && $n['user_id'] === $u) { $trafienie = $n; }
    }
    t_not_null($trafienie, 'osoba bez apki jest w wyniku metody');
    t_true(in_array('mail', $trafienie['kanaly'] ?? [], true), 'i poszło do niej mailem');
    t_true(str_contains($added, 'TEST skarb mailem'), 'treść maila niesie nazwę jawnego skarbu');
    // Wypis musi być w każdym mailu z zachętą — bez niego zostaje przycisk
    // „to jest spam", który zabiera ze sobą także maile transakcyjne.
    t_true(str_contains($added, 'powiadomienia/wypisz'), 'mail niesie link wypisu');
    t_true(str_contains($added, 'List-Unsubscribe'), 'i nagłówek List-Unsubscribe');
});

t_test('PushNotifier: ukryty skarb nie zdradza nazwy TAKŻE w mailu', function () {
    // Mail jest pod tym względem gorszym kanałem niż push: zostaje w skrzynce
    // i da się go przeszukać. Zasada z Treasure::reveal() musi więc obowiązywać
    // w nim tak samo — a szablon to osobny plik, czyli osobna okazja do wycieku.
    [$regionA] = t_two_regions();
    $u = t_user(0);
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    [$lat, $lon] = DiscoveryGrid::cellCenter((int) $regionA['cell_id']);
    t_treasure([
        'name' => 'TEST tajna nazwa mailowa', 'region_item_id' => $regionA['region_item_id'],
        'lat' => $lat, 'lon' => $lon, 'reveal_level' => 0,
    ]);

    $before = t_mail_log_size();
    PushNotifier::runTreasuresNearby();
    $added = t_mail_log_since($before);

    t_false(str_contains($added, 'TEST tajna nazwa mailowa'), 'nazwa ukrytego skarbu nie wyciekła do maila');
});

/* ========================================================================
   ETAP 1b — „NOWOŚĆ W TWOJEJ OKOLICY" (2026-09-11)
   ======================================================================== */

t_test('1b: nowa trasa powiadamia tego, kto jeździ w jej regionie', function () {
    Models\NotificationTexts::forget();
    [$regionA] = t_two_regions();
    $u = t_user(0);
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    $db = Core\Database::connection();
    $db->prepare("INSERT INTO known_routes (slug, name, gpx_url, is_active, created_at)
                  VALUES ('test-1b-trasa', 'TEST trasa 1b', '/x.gpx', 1, NOW())")->execute();
    $routeId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO known_route_regions (route_id, region_item_id) VALUES (:r, :g)')
        ->execute(['r' => $routeId, 'g' => $regionA['region_item_id']]);

    $before = t_mail_log_size();
    $wyslane = Models\PushNotifier::runNewInRegion();
    $mail = t_mail_log_since($before);

    $trafienie = null;
    foreach ($wyslane as $w) {
        if ($w['kind'] === 'route' && $w['id'] === $routeId && $w['user_id'] === $u) { $trafienie = $w; }
    }
    t_not_null($trafienie, 'osoba z odkryciami w regionie dostała powiadomienie');
    t_true(str_contains($mail, 'TEST trasa 1b'), 'mail niesie nazwę trasy');
    // Cel linku to STRONA TRASY, nie mapa odkryć — ta sama uwaga usera, przez
    // którą powstały testy celów w Etapie 1c.
    // W logu maila sprawdzamy ETYKIETĘ przycisku, nie adres: driver `log` robi
    // `strip_tags`, więc `href` do niego nie dociera. Sam adres pilnuje test
    // podglądów w `powiadomienia_test.php` — tam widać go bez pośrednictwa logu.
    t_true(str_contains($mail, 'Zobacz trasę'), 'mail niesie przycisk trasy');
    t_true(!str_contains($mail, 'Zobacz na mapie'), 'i na pewno nie przycisk mapy odkryć');
});

t_test('1b: ta sama nowość nie wraca drugi raz', function () {
    Models\NotificationTexts::forget();
    [$regionA] = t_two_regions();
    $u = t_user(0);
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    $db = Core\Database::connection();
    $db->prepare("INSERT INTO known_routes (slug, name, gpx_url, is_active, created_at)
                  VALUES ('test-1b-powtorka', 'TEST powtórka', '/x.gpx', 1, NOW())")->execute();
    $routeId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO known_route_regions (route_id, region_item_id) VALUES (:r, :g)')
        ->execute(['r' => $routeId, 'g' => $regionA['region_item_id']]);

    $pierwszy = Models\PushNotifier::runNewInRegion();
    $drugi    = Models\PushNotifier::runNewInRegion();

    $ile = static function (array $lista) use ($routeId, $u): int {
        $n = 0;
        foreach ($lista as $w) {
            if ($w['kind'] === 'route' && $w['id'] === $routeId && $w['user_id'] === $u) { $n++; }
        }
        return $n;
    };
    t_true($ile($pierwszy) > 0, 'pierwszy przebieg wysyła');
    t_same(0, $ile($drugi), 'drugi przebieg tej samej trasy już nie — klucz `kr:{id}`');
});

t_test('1b: kto NIE jeździ w tym regionie, nic nie dostaje', function () {
    Models\NotificationTexts::forget();
    [$regionA, $regionB] = t_two_regions();
    $u = t_user(0);
    // Odkrycia w regionie B, trasa w regionie A.
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionB['cell_id']]);

    $db = Core\Database::connection();
    $db->prepare("INSERT INTO known_routes (slug, name, gpx_url, is_active, created_at)
                  VALUES ('test-1b-obcy', 'TEST obcy region', '/x.gpx', 1, NOW())")->execute();
    $routeId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO known_route_regions (route_id, region_item_id) VALUES (:r, :g)')
        ->execute(['r' => $routeId, 'g' => $regionA['region_item_id']]);

    foreach (Models\PushNotifier::runNewInRegion() as $w) {
        if ($w['kind'] === 'route' && $w['id'] === $routeId && $w['user_id'] === $u) {
            t_fail('powiadomienie poszło do kogoś spoza regionu');
        }
    }
    t_true(true, 'brak trafienia poza regionem');
});

t_test('1b: trasa starsza niż okno świeżości nie powiadamia', function () {
    Models\NotificationTexts::forget();
    [$regionA] = t_two_regions();
    $u = t_user(0);
    Core\Database::connection()
        ->prepare('INSERT IGNORE INTO discovery_cells (user_id, cell_id) VALUES (:u, :c)')
        ->execute(['u' => $u, 'c' => $regionA['cell_id']]);

    // Okno świeżości to ten sam parametr, którym rządzi panel (migr. 083) —
    // zadanie w tle ma łapać NOWOŚCI, a nie cały katalog przy pierwszym
    // uruchomieniu na produkcji.
    $db = Core\Database::connection();
    $db->prepare("INSERT INTO known_routes (slug, name, gpx_url, is_active, created_at)
                  VALUES ('test-1b-stara', 'TEST stara trasa', '/x.gpx', 1, NOW() - INTERVAL 40 DAY)")->execute();
    $routeId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO known_route_regions (route_id, region_item_id) VALUES (:r, :g)')
        ->execute(['r' => $routeId, 'g' => $regionA['region_item_id']]);

    foreach (Models\PushNotifier::runNewInRegion() as $w) {
        if ($w['kind'] === 'route' && $w['id'] === $routeId) {
            t_fail('stara trasa powiadomiła mimo okna świeżości');
        }
    }
    t_true(true, 'stara trasa milczy');
});

t_test('1b: treści obu nowości są w panelu i celują we właściwe miejsca', function () {
    Models\NotificationTexts::forget();
    foreach (['nearby_route' => '/trasy/', 'nearby_event' => '/events/'] as $kod => $cel) {
        t_not_null(Models\NotificationTexts::domyslny($kod . '.mail.body'), "„$kod\" ma treść maila");
        t_not_null(Models\NotificationTexts::domyslny($kod . '.push.title'), "„$kod\" ma tytuł pusha");
        t_true(str_contains(Models\NotificationTexts::blokiPrzykladowe($kod)['przycisk'], $cel), "podgląd „$kod\" celuje w $cel");
        // Oba stoją na typie `nearby_new`, czyli pod jednym wyłącznikiem
        // i jednym budżetem — bo dla odbiorcy to jest ta sama rzecz.
        t_same('nearby_new', Models\NotificationTexts::POWIADOMIENIA[$kod]['typ'], "„$kod\" należy do typu nearby_new");
    }
});

/* ========================================================================
   ETAP 2 — POMIAR OTWARĆ (2026-09-11)
   ======================================================================== */

t_test('2: identyfikator wpisu jedzie w ładunku pusha', function () {
    // Bez `nid` w ładunku apka nie ma czego odesłać, a `opened_at` zostaje
    // pusty na zawsze — czyli program zachęt stroi się na ślepo.
    $src = (string) file_get_contents(CORE_PATH . '/Models/Notifier.php');
    t_true(str_contains($src, "\$dane = (\$push['data'] ?? []) + ['nid' => (string) \$id];"),
        'most dokłada `nid` do ładunku');
    t_true(str_contains($src, 'Push::sendToUser($userId, $push[\'title\'], $push[\'body\'], $dane)'),
        'i wysyła właśnie ten ładunek, nie oryginalny');

    // Wartości `data` muszą być stringami (wymóg FCM) — rzutowanie jest tu,
    // a nie tylko w Core\Push, żeby nie zależeć od cudzej ostrożności.
    t_true(str_contains($src, "(string) \$id"), '`nid` jest stringiem');
});

t_test('2: otwarcie zapisuje się przez endpoint i tylko właścicielowi', function () {
    $user = t_user(0);
    $obcy = t_user(1);
    $id = Models\NotificationGate::claim($user, Models\NotificationGate::WIADOMOSC, 'msg:otw:1');
    t_not_null($id, 'wpis powstał');

    $db = Core\Database::connection();
    $otwarte = static function (int $id) use ($db) {
        $s = $db->prepare('SELECT opened_at FROM notification_log WHERE id = :id');
        $s->execute(['id' => $id]);
        return $s->fetchColumn();
    };

    // Endpoint woła dokładnie tę metodę — anty-IDOR siedzi w modelu, więc
    // cudzy `nid` nic nie zmienia, choćby ktoś podmienił go w żądaniu.
    Models\NotificationGate::oznaczOtwarte((int) $id, $obcy);
    t_null($otwarte((int) $id), 'cudze potwierdzenie nie działa');

    Models\NotificationGate::oznaczOtwarte((int) $id, $user);
    t_not_null($otwarte((int) $id), 'właściciel oznacza otwarcie');

    $routes = (string) file_get_contents(CORE_PATH . '/../api/routes.php');
    t_true(str_contains($routes, "/api/powiadomienia/otwarte"), 'endpoint istnieje');
    t_true(str_contains($routes, 'Models\NotificationGate::oznaczOtwarte($id, $user->id)'),
        'endpoint oznacza wpis w imieniu ZALOGOWANEGO, nie wg danych z żądania');
});

t_test('2: apka odsyła potwierdzenie po tapnięciu w push', function () {
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/native.js');
    t_true(str_contains($js, "pushNotificationActionPerformed"), 'listener tapnięcia istnieje');
    t_true(str_contains($js, '/api/powiadomienia/otwarte'), 'i woła endpoint pomiaru');
    // `data.url` jest DANYMI, nie gotowym adresem — ta sama ostrożność co przy
    // powiadomieniach lokalnych: przepuszczamy wyłącznie ścieżkę względną.
    t_true(str_contains($js, "sciezka.charAt(0) !== '/' || sciezka.charAt(1) === '/'"),
        'adres z ładunku jest walidowany jak dane, nie ufany jak adres');
});
