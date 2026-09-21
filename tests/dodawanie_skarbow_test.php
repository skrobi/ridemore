<?php
// tests/dodawanie_skarbow_test.php
// DODAWANIE SKARBÓW — tworzenie z minimalną ilością danych, trzy warianty
// rzadkości, zaliczenie, naliczanie punktów i wycofanie („usunięcie").
//
// To jest uzupełnienie skarby_test.php (który pilnuje zachowań w TERENIE:
// zaliczanie, ujawnienia, głosowanie): ten zestaw pilnuje PANELU — co się
// dzieje, gdy admin stawia skarb i daje tylko to, co jest naprawdę
// konieczne (nazwa + współrzędne). Reszta pól ma mieć sensowne domyślne.
//
// TRZY WARIANTOW = TRZY RZADKOŚCI. Skarb bez wpisanej stawki jest wart tyle,
// ile wyjściowo jego rzadkość (SKA/15) — COMMON, RARE i EPIC mają w
// konfiguracji różne stawki i każda ma dojść do bazy bez ręcznego wpisywania
// liczby. To nie jest wygoda, tylko zabezpieczenie: (int)'' dawało kiedyś
// zero, czyli skarb wart tyle, co nic, i to bez żadnego komunikatu.
//
// WYCOFANIE JEST JEDYNYM „USUNIĘCIEM" SKARBU. Twardego DELETE nie ma i nie
// powinno być: treasure_finds wisi na ON DELETE CASCADE, więc skasowanie
// wiersza skarbu wymazałoby historię znalezisk, a wpisy w point_transactions
// (niezmienny rejestr punktów) zostałyby osierocone. Wycofanie (status
// RETIRED) robi to, co ma robić usunięcie — przestaje płacić i znika z mapy —
// a już naliczone punkty zostają, bo są faktem o przeszłości, nie o
// teraźniejszości skarbu.
use Models\Treasure;
use Models\PointLedger;
use Core\Database;

// --- Tworzenie z minimalną ilością danych ----------------------------

t_test('Minimalne dane: nazwa + współrzędne wystarczą, reszta ma domyślne', function () {
    $t = Treasure::find(Treasure::save(null, ['name' => 'TEST minimalny', 'lat' => 50.123, 'lon' => 20.456], null));

    t_eq('TEST minimalny', $t['name'], 'nazwa');
    t_true((bool) preg_match('/^[0-9a-f]{16}$/', (string) $t['code']), 'kod to 16 znaków hex');
    t_eq('COMMON', $t['rarity'], 'domyślna rzadkość');
    t_eq('OFFICIAL', $t['origin'], 'domyślne pochodzenie');
    t_eq('ACTIVE', $t['status'], 'domyślny status');
    t_eq(2, $t['reveal_level'], 'domyślny poziom ujawnienia');
    t_eq(1, $t['is_active'], 'widoczny od razu');
    t_eq(Treasure::defaultPoints(), $t['points'], 'punkty z konfiguracji');
    t_eq(Treasure::defaultRadius(), $t['claim_radius_m'], 'promień z konfiguracji');
    t_eq(Utils\DiscoveryGrid::pointToCell(50.123, 20.456), $t['cell_id'], 'pole siatki policzone przy zapisie');
});

t_test('Minimalne dane: opis, wskazówka i kategoria mogą zostać puste', function () {
    $t = Treasure::find(Treasure::save(null, ['name' => 'TEST goły', 'lat' => 50.0, 'lon' => 20.0], null));

    t_null($t['description'], 'opis');
    t_null($t['hint'], 'wskazówka');
    t_null($t['category_item_id'], 'kategoria');
    t_null($t['created_by'], 'autor może być nieznany');
});

t_test('Dwa skarby z minimalnymi danymi dostają różne kody', function () {
    $a = Treasure::find(Treasure::save(null, ['name' => 'TEST kod A', 'lat' => 50.0, 'lon' => 20.0], null));
    $b = Treasure::find(Treasure::save(null, ['name' => 'TEST kod B', 'lat' => 50.001, 'lon' => 20.001], null));

    t_not_null($a['code'], 'kod pierwszego');
    t_not_null($b['code'], 'kod drugiego');
    t_true($a['code'] !== $b['code'], 'kody się nie powtarzają');
});

// --- Trzy warianty rzadkości (SKA/15) ---------------------------------

t_test('Wariant 1/3 — COMMON: minimalne dane dostają stawkę zwykłego', function () {
    $t = Treasure::find(Treasure::save(null, [
        'name' => 'TEST zwykły', 'lat' => 50.0, 'lon' => 20.0, 'rarity' => 'COMMON',
    ], null));

    t_eq(Treasure::defaultPointsFor('COMMON'), $t['points'], 'stawka zwykłego, bez wpisywania liczby');
});

t_test('Wariant 2/3 — RARE: minimalne dane dostają stawkę rzadkiego', function () {
    $t = Treasure::find(Treasure::save(null, [
        'name' => 'TEST rzadki', 'lat' => 50.0, 'lon' => 20.0, 'rarity' => 'RARE',
    ], null));

    t_eq(Treasure::defaultPointsFor('RARE'), $t['points'], 'stawka rzadkiego');
    t_eq('RARE', $t['rarity'], 'rzadkość zapisana');
});

t_test('Wariant 3/3 — EPIC: minimalne dane dostają stawkę epickiego', function () {
    $t = Treasure::find(Treasure::save(null, [
        'name' => 'TEST epicki', 'lat' => 50.0, 'lon' => 20.0, 'rarity' => 'EPIC',
    ], null));

    t_eq(Treasure::defaultPointsFor('EPIC'), $t['points'], 'stawka epickiego');
});

t_test('Wpisana stawka nadpisuje rzadkość — bez cichego mnożenia', function () {
    $t = Treasure::find(Treasure::save(null, [
        'name' => 'TEST własna cena', 'lat' => 50.0, 'lon' => 20.0,
        'rarity' => 'EPIC', 'points' => 777,
    ], null));

    t_eq(777, $t['points'], 'w bazie wpisana wartość');
    t_eq(777, Treasure::claim($t['code'], t_user(0), 50.0, 20.0)['points'],
        'znalazca dostaje dokładnie wpisaną stawkę');
});

// --- Zaliczenie i naliczanie punktów ---------------------------------

t_test('Zaliczenie zapisuje znalezienie i jedną transakcję punktów', function () {
    $user = t_user(0);
    $t = Treasure::find(Treasure::save(null, ['name' => 'TEST zaliczenie', 'lat' => 50.0, 'lon' => 20.0], null));
    $przed = PointLedger::totalForUser($user);

    $r = Treasure::claim($t['code'], $user, 50.0002, 20.0);
    t_true($r['ok'], 'zaliczenie się udało');
    t_eq($t['points'], $r['points'], 'tyle punktów za stawkę skarbu');

    $find = Database::connection()->prepare(
        'SELECT method, points_awarded FROM treasure_finds WHERE treasure_id = :t AND user_id = :u'
    );
    $find->execute(['t' => $t['id'], 'u' => $user]);
    $f = $find->fetch();
    t_true($f !== false, 'wiersz znalezienia');
    t_eq('QR', $f['method'], 'metoda domyślna');
    t_eq($t['points'], $f['points_awarded'], 'punkty zamrożone przy znalezieniu');

    $tx = Database::connection()->prepare(
        'SELECT source, source_id, points, description, ride_date
           FROM point_transactions
          WHERE user_id = :u AND source = "TREASURE_FOUND" AND source_id = :t'
    );
    $tx->execute(['u' => $user, 't' => $t['id']]);
    $row = $tx->fetch();
    t_true($row !== false, 'wpis w rejestrze punktów');
    t_eq('TREASURE_FOUND', $row['source'], 'źródło');
    t_eq($t['points'], $row['points'], 'wartość');
    t_eq('Skarb: TEST zaliczenie', $row['description'], 'opis zamrożony przy naliczaniu');
    t_eq(date('Y-m-d'), $row['ride_date'], 'data naliczenia to data zaliczenia');

    t_eq($przed + (int) $t['points'], PointLedger::totalForUser($user), 'wynik rośnie dokładnie o stawkę');
});

t_test('Punkty za skarb widać w rozbiciu wyników jako źródło TREASURE_FOUND', function () {
    $user = t_user(0);
    $t = Treasure::find(Treasure::save(null, ['name' => 'TEST rozbicie', 'lat' => 50.0, 'lon' => 20.0], null));
    $przed = PointLedger::breakdownForUser($user)['TREASURE_FOUND'] ?? 0;

    Treasure::claim($t['code'], $user, 50.0, 20.0);

    $po = PointLedger::breakdownForUser($user)['TREASURE_FOUND'] ?? 0;
    t_eq($przed + (int) $t['points'], $po, 'rozbicie rośnie o stawkę skarbu');
});

// --- Usunięcie skarbu (wycofanie) i usunięcie punktu ------------------

t_test('Wycofanie skarbu blokuje wszystkie trzy drogi zaliczenia', function () {
    $user = t_user(0);
    $t = Treasure::find(Treasure::save(null, ['name' => 'TEST wycofany', 'lat' => 50.0, 'lon' => 20.0], null));
    Treasure::setStatus((int) $t['id'], 'RETIRED');

    t_eq('nieaktywna', Treasure::claim($t['code'], $user, 50.0, 20.0)['reason'], 'QR odrzucony');
    t_count(0, Treasure::claimNearby($user, 50.0002, 20.0)['claimed'], 'GPS nie widzi wycofanego');
    t_count(0, Treasure::claimAlongTrack($user, [(int) $t['cell_id']], [['lat' => 50.0, 'lon' => 20.0]]),
        'GPX nie widzi wycofanego');
});

t_test('Wycofany skarb znika z mapy, ale zostaje w bazie', function () {
    $granice = ['north' => 50.2, 'south' => 49.8, 'east' => 20.2, 'west' => 19.8];
    $t = Treasure::find(Treasure::save(null, ['name' => 'TEST z mapy', 'lat' => 50.0, 'lon' => 20.0], null));
    Treasure::setStatus((int) $t['id'], 'RETIRED');

    foreach (Treasure::inBounds($granice, null) as $row) {
        if ((int) $row['id'] === (int) $t['id']) {
            t_fail('wycofany skarb wciąż jest na mapie');
        }
    }
    t_true(true, 'nie ma go w odpowiedzi mapy');

    t_not_null(Treasure::find((int) $t['id']), 'wiersz zostaje w bazie');
    t_eq('RETIRED', Treasure::find((int) $t['id'])['status'], 'status wycofany');
});

t_test('Wycofanie NIE kasuje już naliczonych punktów — rejestr jest niezmienny', function () {
    $user = t_user(0);
    $t = Treasure::find(Treasure::save(null, ['name' => 'TEST niezmienność', 'lat' => 50.0, 'lon' => 20.0], null));
    Treasure::claim($t['code'], $user, 50.0, 20.0);
    $przed = PointLedger::totalForUser($user);

    Treasure::setStatus((int) $t['id'], 'RETIRED');

    t_eq($przed, PointLedger::totalForUser($user), 'wynik bez zmian po wycofaniu');
    t_eq(1, Database::connection()->query(
        'SELECT COUNT(*) FROM point_transactions WHERE source = "TREASURE_FOUND" AND source_id = ' . (int) $t['id']
    )->fetchColumn(), 'wpis w rejestrze nietknięty');
    t_eq(1, Database::connection()->query(
        'SELECT COUNT(*) FROM treasure_finds WHERE treasure_id = ' . (int) $t['id']
    )->fetchColumn(), 'znalezienie też zostaje — historia to historia');
});

// --- ZGŁOSZENIE Z TERENU: ZDJĘCIE (kontrakt zglos-skarb-w-terenie.md, kryt. 6) ---
//
// `TreasureProposalController::save()` kończy się `header()` + `exit`, więc
// nie da się go odpalić w procesie testów (ubiłoby cały przebieg) — tak samo
// jak przy `SoloRideController`. Rozdzielamy więc pytanie na dwa: co robi
// MODEL ze zdjęciem (odpalane naprawdę) i czy KONTROLER mu je w ogóle podaje
// (sprawdzane statycznie na źródle).

t_test('Zgłoszenie ze zdjęciem: photo_url dochodzi do bazy', function () {
    $t = Treasure::find(Treasure::save(null, [
        'name'      => 'TEST zgłoszenie ze zdjęciem',
        'lat'       => 50.1,
        'lon'       => 20.1,
        'photo_url' => '/assets/uploads/covers/zglosz.jpg',
        'status'    => 'PROPOSED',
    ], null));

    t_eq('/assets/uploads/covers/zglosz.jpg', $t['photo_url'], 'zdjęcie zapisane przy skarbie');
    t_eq('PROPOSED', $t['status'], 'zgłoszenie czeka na potwierdzenie, nie płaci od razu');
});

t_test('Zgłoszenie BEZ zdjęcia przechodzi — pole jest opcjonalne', function () {
    $t = Treasure::find(Treasure::save(null, [
        'name'   => 'TEST zgłoszenie bez zdjęcia',
        'lat'    => 50.2,
        'lon'    => 20.2,
        'status' => 'PROPOSED',
    ], null));

    t_null($t['photo_url'], 'brak zdjęcia = NULL, nie pusty ciąg');
});

t_test('Kontroler zgłoszenia PODAJE zdjęcie modelowi i bierze je z uploadu', function () {
    $src = (string) file_get_contents(CORE_PATH . '/Controllers/TreasureProposalController.php');

    // Plik idzie przez WSPÓLNY helper okładek, nie przez własną obsługę
    // move_uploaded_file — tam są już limity, formaty i skalowanie.
    t_true(
        (bool) preg_match('/\$photoUrl = Upload::saveCoverPhoto\(\$_FILES\[.photo.\]\)/', $src),
        'zdjęcie leci przez Upload::saveCoverPhoto, nie własną ścieżką'
    );
    // Bez tej linijki cały tor (pole w formularzu, aparat, upload) kończyłby
    // się w powietrzu — to jest ta jedna rzecz, której brakowało.
    t_true(
        (bool) preg_match('/photo_url.{0,12}=>\s*\$photoUrl/', $src),
        'i trafia do tablicy przekazywanej Treasure::save()'
    );
    // Opcjonalność: bez pliku zmienna zostaje nullem i zgłoszenie przechodzi.
    t_true(
        (bool) preg_match('/\$photoUrl = null;\s*if \(!empty\(\$_FILES\[.photo.\]\[.name.\]\)\)/s', $src),
        'brak pliku nie blokuje zgłoszenia'
    );
});

t_test('Ekran zgłoszenia w apce: aparat, jedno zdjęcie i uprzedzenie o ukryciu', function () {
    $apka = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasure-propose-app.php');
    $web  = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasure-propose.php');

    t_true(str_contains($apka, 'data-rm-camera-for="tpPhoto"'), 'przycisk aparatu wpięty w istniejący most');
    t_true(
        (bool) preg_match('/<input type="file" id="tpPhoto" name="photo"(?![^>]*multiple)/', $apka),
        'pole na JEDNO zdjęcie (okładka) — bez multiple, stąd poprawka w native.js'
    );
    // `Treasure::reveal()` zeruje photo_url dla Tropu i Ukrytego. Bez tego
    // zdania user uzna, że zdjęcie przepadło.
    t_true(
        (bool) preg_match('/Przy Tropie i Ukrytym zdjęcie zobaczy/', $apka),
        'formularz uprzedza, kiedy zdjęcia nie będzie widać'
    );
    // Wyszukiwarka miejsc służy do zgłaszania z fotela — w apce nie ma jej
    // być, na webie ma zostać.
    t_false(stripos($apka, 'nominatim') !== false, 'apka nie ma wyszukiwarki miejsc');
    t_true(stripos($web, 'nominatim') !== false, 'web ją zachowuje');
});
