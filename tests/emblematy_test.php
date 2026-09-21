<?php
// tests/emblematy_test.php
// EMBLEMATY ZA UKOŃCZENIE TRASY (migr. 087, 2026-09-11).
//
// Trzy rzeczy, których ten zestaw pilnuje ponad zwykłym „działa":
//   1. EMBLEMATU NIE DA SIĘ STRACIĆ — to jest decyzja usera i jedyny powód,
//      dla którego `emblem_awards` w ogóle istnieje zamiast wyprowadzania
//      w locie. Test zabiera pokrycie i sprawdza, że emblemat zostaje.
//   2. 100% ZNACZY 100% — 99% nie daje nic. Bez tego progu emblemat byłby
//      kolejnym progiem punktowym, a nie nagrodą za całość.
//   3. WIELODNIÓWKA vs WARIANTY — etapy sumują się (wszystkie dni), warianty
//      są alternatywą (wystarczy jeden dystans). Odwrotna reguła zrobiłaby
//      emblemat wyścigu niezdobywalnym.
//
// Cały zestaw biegnie w transakcji runnera, więc zakłada własne trasy
// i pola — nie polega na danych DEV. Helpery z prefiksem `emb_`, bo `run.php`
// wgrywa wszystkie zestawy do jednego procesu.

use Core\Database;
use Models\Emblem;
use Models\KnownRoute;
use Utils\DiscoveryGrid;

/** Plik GPX z prostej linii — kasowany przez test po przebiegu. */
function emb_gpx(int $punktow = 40, float $lat = 53.11, float $lon = 16.41): string
{
    $trkpts = '';
    for ($i = 0; $i < $punktow; $i++) {
        $trkpts .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>100</ele></trkpt>', $lat, $lon + $i * 0.003);
    }
    $path = sys_get_temp_dir() . '/emb_' . bin2hex(random_bytes(8)) . '.gpx';
    file_put_contents(
        $path,
        '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<trk><trkseg>' . $trkpts . '</trkseg></trk></gpx>'
    );
    return $path;
}

/** Emblemat testowy — zwraca id. */
function emb_emblemat(string $nazwa = 'TEST emblemat'): int
{
    return Emblem::save(null, [
        'name'        => $nazwa . ' ' . bin2hex(random_bytes(3)),
        'description' => null,
        'is_active'   => 1,
    ]);
}

/**
 * Trasa z emblematem. Zwraca `[routeId, emblemId, cellIds]`.
 *
 * Pola bierzemy Z BAZY po utworzeniu, a nie liczymy drugi raz z pliku —
 * inaczej test sprawdzałby własną kopię reguły zamiast tej, która działa.
 */
function emb_trasa_z_emblematem(?int $emblemId = null): array
{
    $plik = emb_gpx();
    try {
        $emblemId ??= emb_emblemat();
        $trasa = KnownRoute::createFromGpx(
            'TEST trasa emblemat ' . bin2hex(random_bytes(4)),
            null,
            $plik,
            '/assets/uploads/gpx/emb_' . bin2hex(random_bytes(4)) . '.gpx',
            null,
            null,
            $emblemId
        );
    } finally {
        @unlink($plik);
    }

    $routeId = (int) $trasa['id'];
    $stmt = Database::connection()->prepare('SELECT cell_id FROM known_route_cells WHERE route_id = :id');
    $stmt->execute(['id' => $routeId]);

    return [$routeId, $emblemId, array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))];
}

/** Wpisuje userowi podane pola jako odkryte. */
function emb_odkryj(int $userId, array $cellIds): void
{
    $stmt = Database::connection()->prepare(
        'INSERT IGNORE INTO discovery_cells (user_id, cell_id, discovered_at) VALUES (:u, :c, NOW())'
    );
    foreach ($cellIds as $cellId) {
        $stmt->execute(['u' => $userId, 'c' => $cellId]);
    }
}

/** Czy ten user ma ten emblemat. */
function emb_ma(int $userId, int $emblemId): bool
{
    $stmt = Database::connection()->prepare(
        'SELECT COUNT(*) FROM emblem_awards WHERE user_id = :u AND emblem_id = :e'
    );
    $stmt->execute(['u' => $userId, 'e' => $emblemId]);
    return (int) $stmt->fetchColumn() > 0;
}

// ──────────────────────────────────────────────────────── WARUNEK ZDOBYCIA

t_test('100% pól trasy daje emblemat', function () {
    [, $emblemId, $cells] = emb_trasa_z_emblematem();
    $user = t_user(0);
    t_true(count($cells) > 0, 'trasa testowa ma pola');

    emb_odkryj($user, $cells);
    Emblem::sync($user);

    t_true(emb_ma($user, $emblemId), 'komplet pól nadaje emblemat');
});

t_test('99% nie daje nic — emblemat jest za CAŁOŚĆ, nie za próg', function () {
    [, $emblemId, $cells] = emb_trasa_z_emblematem();
    $user = t_user(1);

    // Wszystko OPRÓCZ jednego pola. To jest cała różnica między emblematem
    // a progiem punktowym: progi płacą za kawałki, emblemat wyłącznie za komplet.
    emb_odkryj($user, array_slice($cells, 0, count($cells) - 1));
    Emblem::sync($user);

    t_false(emb_ma($user, $emblemId), 'brak jednego pola = brak emblematu');

    // ...a dołożenie brakującego pola domyka sprawę.
    emb_odkryj($user, [$cells[count($cells) - 1]]);
    Emblem::sync($user);
    t_true(emb_ma($user, $emblemId), 'ostatnie pole nadaje emblemat');
});

t_test('emblemat dostaje się WSTECZ, za przejazdy sprzed przypisania', function () {
    // Kolejność odwrócona: najpierw ktoś ma pola, dopiero potem trasa dostaje
    // emblemat. To jest przypadek TYPOWY, nie brzegowy — katalog tras rośnie
    // wolniej niż przejazdy, więc świeżo opisana trasa niemal zawsze ma już
    // kogoś, kto przez nią przejechał.
    [$routeId, , $cells] = emb_trasa_z_emblematem();
    $user = t_user(2);
    emb_odkryj($user, $cells);

    $nowyEmblem = emb_emblemat('TEST wsteczny');
    KnownRoute::update($routeId, ['emblem_id' => $nowyEmblem]);
    Emblem::sync();

    t_true(emb_ma($user, $nowyEmblem), 'przypisanie emblematu nadaje go wstecz');
});

t_test('trasa bez emblematu nie nadaje niczego', function () {
    $plik = emb_gpx(30, 53.44, 16.77);
    try {
        $trasa = KnownRoute::createFromGpx(
            'TEST trasa bez emblematu ' . bin2hex(random_bytes(4)),
            null,
            $plik,
            '/assets/uploads/gpx/emb_be_' . bin2hex(random_bytes(4)) . '.gpx'
        );
    } finally {
        @unlink($plik);
    }
    $stmt = Database::connection()->prepare('SELECT cell_id FROM known_route_cells WHERE route_id = :id');
    $stmt->execute(['id' => (int) $trasa['id']]);
    $cells = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

    $user = t_user(0);
    $przed = (int) Database::connection()
        ->query('SELECT COUNT(*) FROM emblem_awards WHERE user_id = ' . $user)->fetchColumn();
    emb_odkryj($user, $cells);
    Emblem::sync($user);
    $po = (int) Database::connection()
        ->query('SELECT COUNT(*) FROM emblem_awards WHERE user_id = ' . $user)->fetchColumn();

    t_eq($przed, $po, 'ukończenie trasy bez emblematu nie dokłada nic');
});

t_test('emblemat nieaktywny nie jest nadawany, ale zdobyte zostają', function () {
    [, $emblemId, $cells] = emb_trasa_z_emblematem();
    $user = t_user(0);
    $drugi = t_user(1);

    emb_odkryj($user, $cells);
    Emblem::sync($user);
    t_true(emb_ma($user, $emblemId), 'pierwszy zdobył, póki emblemat był aktywny');

    // Wycofanie z obiegu = `is_active = 0`, nie DELETE (kasowanie zabiera też
    // zdobyte egzemplarze — patrz nota przy migr. 087).
    Emblem::save($emblemId, ['is_active' => 0]);
    emb_odkryj($drugi, $cells);
    Emblem::sync($drugi);

    t_false(emb_ma($drugi, $emblemId), 'nieaktywny emblemat nie jest już nadawany');
    t_true(emb_ma($user, $emblemId), 'zdobyty wcześniej zostaje mimo wycofania');
});

// ────────────────────────────────────────────────────── NIEODBIERALNOŚĆ

t_test('emblematu NIE DA SIĘ STRACIĆ, gdy pokrycie spadnie poniżej 100%', function () {
    [, $emblemId, $cells] = emb_trasa_z_emblematem();
    $user = t_user(0);

    emb_odkryj($user, $cells);
    Emblem::sync($user);
    t_true(emb_ma($user, $emblemId), 'najpierw zdobyty');

    // Dokładnie to, co robi podmiana pliku GPX trasy na dłuższy albo skasowanie
    // przejazdu: pokrycie przestaje być pełne. `point_transactions` odbiera
    // w takiej sytuacji `TRAIL_COMPLETION` — emblemat NIE MOŻE zniknąć razem
    // z nim (decyzja usera 2026-09-11).
    Database::connection()
        ->prepare('DELETE FROM discovery_cells WHERE user_id = :u AND cell_id = :c')
        ->execute(['u' => $user, 'c' => $cells[0]]);
    Emblem::sync($user);

    t_true(emb_ma($user, $emblemId), 'emblemat zostaje mimo utraty pokrycia');
});

t_test('powtórny sync nie dubluje ani nie przestawia daty zdobycia', function () {
    [, $emblemId, $cells] = emb_trasa_z_emblematem();
    $user = t_user(0);
    emb_odkryj($user, $cells);
    Emblem::sync($user);

    $stmt = Database::connection()->prepare(
        'SELECT COUNT(*) AS ile, MIN(awarded_at) AS kiedy FROM emblem_awards WHERE user_id = :u AND emblem_id = :e'
    );
    $stmt->execute(['u' => $user, 'e' => $emblemId]);
    $pierwszy = $stmt->fetch();

    // Trzy przebiegi z rzędu — cron chodzi codziennie, a admin może kliknąć
    // „Przelicz" ile razy zechce. Chwila zdobycia ma się nie przesuwać.
    Emblem::sync();
    Emblem::sync($user);
    Emblem::sync();

    $stmt->execute(['u' => $user, 'e' => $emblemId]);
    $drugi = $stmt->fetch();

    t_eq(1, (int) $drugi['ile'], 'dokładnie jeden wiersz, mimo czterech przebiegów');
    t_eq($pierwszy['kiedy'], $drugi['kiedy'], 'data zdobycia się nie przesuwa');
});

t_test('ten sam emblemat na dwóch trasach nie dubluje się u jednej osoby', function () {
    // Seria („Korona czegoś tam") — pierwsza domknięta trasa daje emblemat,
    // druga nie dokłada drugiego egzemplarza. Unikat stoi na (user, emblemat),
    // nie na źródle, właśnie po to.
    $emblemId = emb_emblemat('TEST seria');
    [, , $cellsA] = emb_trasa_z_emblematem($emblemId);
    $plikB = emb_gpx(30, 54.21, 18.02);
    try {
        $trasaB = KnownRoute::createFromGpx(
            'TEST seria B ' . bin2hex(random_bytes(4)),
            null,
            $plikB,
            '/assets/uploads/gpx/emb_sb_' . bin2hex(random_bytes(4)) . '.gpx',
            null,
            null,
            $emblemId
        );
    } finally {
        @unlink($plikB);
    }
    $stmt = Database::connection()->prepare('SELECT cell_id FROM known_route_cells WHERE route_id = :id');
    $stmt->execute(['id' => (int) $trasaB['id']]);
    $cellsB = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

    $user = t_user(1);
    emb_odkryj($user, $cellsA);
    emb_odkryj($user, $cellsB);
    Emblem::sync($user);

    $ile = (int) Database::connection()->query(
        'SELECT COUNT(*) FROM emblem_awards WHERE user_id = ' . $user . ' AND emblem_id = ' . $emblemId
    )->fetchColumn();
    t_eq(1, $ile, 'dwie trasy z tym samym emblematem = jeden egzemplarz');
});

// ─────────────────────────────────────────────────────────── ODCZYT

t_test('forUser podaje emblemat z podpisem „za co" i adresem trasy', function () {
    [$routeId, $emblemId, $cells] = emb_trasa_z_emblematem();
    $user = t_user(2);
    emb_odkryj($user, $cells);
    Emblem::sync($user);

    $moje = Emblem::forUser($user);
    $nasz = null;
    foreach ($moje as $e) {
        if (str_contains($e['name'], 'TEST emblemat')) { $nasz = $e; }
    }
    t_true($nasz !== null, 'emblemat jest na liście profilu');

    $trasa = KnownRoute::find($routeId);
    t_eq($trasa['name'], $nasz['sourceLabel'], 'podpis niesie nazwę trasy');
    t_eq('/trasy/' . $trasa['slug'], $nasz['sourceUrl'], 'podpis prowadzi na stronę trasy');
});

t_test('forRoute rozróżnia „masz go" od „możesz zdobyć"', function () {
    [$routeId, , $cells] = emb_trasa_z_emblematem();
    $wlasciciel = t_user(0);
    $obcy = t_user(1);
    emb_odkryj($wlasciciel, $cells);
    Emblem::sync($wlasciciel);

    t_true(Emblem::forRoute($routeId, $wlasciciel)['mine'], 'zdobywca widzi potwierdzenie');
    t_false(Emblem::forRoute($routeId, $obcy)['mine'], 'kto nie ma, widzi zapowiedź');
    // Gość nie ma konta, więc „mine" musi być fałszem, a nie błędem.
    t_false(Emblem::forRoute($routeId, null)['mine'], 'gość widzi zapowiedź, nie wybuch');
});

t_test('strona trasy bez emblematu nie dostaje nic do pokazania', function () {
    $plik = emb_gpx(30, 52.02, 15.33);
    try {
        $trasa = KnownRoute::createFromGpx(
            'TEST trasa naga ' . bin2hex(random_bytes(4)),
            null,
            $plik,
            '/assets/uploads/gpx/emb_n_' . bin2hex(random_bytes(4)) . '.gpx'
        );
    } finally {
        @unlink($plik);
    }
    t_null(Emblem::forRoute((int) $trasa['id'], t_user(0)), 'brak emblematu = null, nie pusta tablica');
});

// ───────────────────────────────────────────── WYDARZENIA: ETAPY vs WARIANTY

t_test('wydarzenie: etapy sumują się, warianty są alternatywą', function () {
    $m = new \ReflectionMethod(Emblem::class, 'eventRouteCandidates');
    $m->setAccessible(true);

    $db = Database::connection();
    $ev = $db->query('
        SELECT DISTINCT e.id FROM events e JOIN event_stages s ON s.event_id = e.id
         WHERE s.gpx_url IS NOT NULL LIMIT 1
    ')->fetch();
    if (!$ev) {
        t_fail('Baza DEV nie ma wydarzenia z plikiem GPX — ten test go potrzebuje.');
    }
    $eventId = (int) $ev['id'];

    // BEZ WARIANTÓW: etapy sklejają się w JEDNEGO kandydata. Wielodniówkę
    // zalicza się w całości, nie jednym dniem.
    $kandydaci = $m->invoke(null, $eventId);
    t_eq(1, count($kandydaci), 'wydarzenie bez wariantów ma jednego kandydata (suma etapów)');
    t_true($kandydaci[0]['total'] > 0, 'kandydat ma policzone pola');

    // Z WARIANTAMI: każdy wariant osobno. Uczestnik wyścigu wybiera JEDEN
    // dystans, więc suma wszystkich zrobiłaby emblemat niezdobywalnym.
    // PLIK MUSI ISTNIEĆ NA DYSKU, nie tylko w bazie. W danych DEV część
    // wierszy wskazuje na pliki, których już nie ma (ta sama luka, którą
    // zgłasza zestaw `diagnostyka_pustych_kafli`), a `candidateFor()` takie
    // pomija — słusznie, bo bez pliku nie da się policzyć pokrycia. Test
    // szukający „pierwszego z brzegu" trafiał w taki wiersz i wychodziło
    // z tego zero kandydatów przy działającym kodzie.
    $gpx = $db->prepare('SELECT gpx_url FROM event_stages WHERE event_id = :id AND gpx_url IS NOT NULL');
    $gpx->execute(['id' => $eventId]);
    $plik = null;
    foreach ($gpx->fetchAll(\PDO::FETCH_COLUMN) as $kandydat) {
        if (is_file(CORE_PATH . '/..' . $kandydat)) { $plik = (string) $kandydat; break; }
    }
    if ($plik === null) {
        t_fail('Żaden etap tego wydarzenia nie ma pliku GPX na dysku — ten test go potrzebuje.');
    }

    $ins = $db->prepare('INSERT INTO event_route_variants (event_id, name, gpx_url) VALUES (:e, :n, :g)');
    $ins->execute(['e' => $eventId, 'n' => 'TEST 50 km', 'g' => $plik]);
    $ins->execute(['e' => $eventId, 'n' => 'TEST 100 km', 'g' => $plik]);

    $zWariantami = $m->invoke(null, $eventId);
    t_eq(2, count($zWariantami), 'każdy wariant jest osobnym kandydatem');
});

t_test('wydarzenie: 100% trasy nadaje emblemat', function () {
    $db = Database::connection();
    $ev = $db->query('
        SELECT DISTINCT e.id FROM events e JOIN event_stages s ON s.event_id = e.id
         WHERE s.gpx_url IS NOT NULL LIMIT 1
    ')->fetch();
    if (!$ev) {
        t_fail('Baza DEV nie ma wydarzenia z plikiem GPX — ten test go potrzebuje.');
    }
    $eventId = (int) $ev['id'];

    $emblemId = emb_emblemat('TEST wydarzenie');
    $db->prepare('UPDATE events SET emblem_id = :em WHERE id = :id')
        ->execute(['em' => $emblemId, 'id' => $eventId]);

    // Pola trasy wydarzenia czytamy z cache'u GPX (migr. 050) — tego samego,
    // z którego korzysta model. `cellsForGpx` rozgrzewa go, gdyby plik nie był
    // jeszcze policzony.
    $gpx = $db->prepare('SELECT gpx_url FROM event_stages WHERE event_id = :id AND gpx_url IS NOT NULL');
    $gpx->execute(['id' => $eventId]);
    $cells = [];
    foreach ($gpx->fetchAll(\PDO::FETCH_COLUMN) as $gpxUrl) {
        foreach (\Models\RoutePreview::cellsForGpx(CORE_PATH . '/..' . $gpxUrl) as $cellId) {
            $cells[(int) $cellId] = true;
        }
    }
    t_true(count($cells) > 0, 'wydarzenie ma policzone pola trasy');

    $user = t_user(2);
    emb_odkryj($user, array_keys($cells));
    Emblem::sync($user);

    t_true(emb_ma($user, $emblemId), 'pokrycie całej trasy wydarzenia nadaje emblemat');
});
