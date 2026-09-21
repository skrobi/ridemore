<?php
// tests/przejazdy_solo_test.php
// PRZEJAZDY SOLO: przegląd i POWIĄZANIE Z WYJAZDEM (2026-08-23).
//
// Powiązanie dotyka trzech rzeczy naraz — przejazdu, śladu turnusu i obecności —
// więc testy pilnują przede wszystkim tego, co przy takiej operacji psuje się po
// cichu: podwójnego policzenia tych samych kilometrów i zgubienia odkryć.

use Core\Database;
use Models\Dictionary;
use Models\EditionTrack;
use Models\EventAttendance;
use Models\RiderActivity;

/** Najdłuższy ślad z katalogu uploadów — musi wystarczyć na pola po przycięciu okolic „domu". */
function t_solo_gpx(): string
{
    static $path = null;
    if ($path === null) {
        $files = glob(CORE_PATH . '/../assets/uploads/gpx/*.gpx') ?: [];
        if (!$files) {
            t_fail('Brak jakiegokolwiek pliku GPX w assets/uploads/gpx — te testy potrzebują prawdziwego śladu.');
        }
        usort($files, static fn(string $a, string $b): int => filesize($b) <=> filesize($a));
        $path = $files[0];
    }
    return $path;
}

/** Przejazd solo tej osoby, zrobiony z prawdziwego pliku (przez tę samą drogę co upload). */
function t_solo_ride(int $userId): array
{
    $gpxUrl = '/assets/uploads/gpx/' . basename(t_solo_gpx());
    $wynik = RiderActivity::recordSolo($userId, t_solo_gpx(), $gpxUrl);
    if ($wynik === null) {
        t_fail('recordSolo nie zapisał przejazdu — plik testowy nie daje ani jednego pola siatki.');
    }
    $lista = RiderActivity::soloForUser($userId);

    return $lista[0];
}

/** Potwierdzony zapis tej osoby na dowolny opublikowany turnus. */
function t_solo_rsvp(int $userId): array
{
    $db = Database::connection();
    $event = $db->query("
        SELECT e.id, ed.id AS edition_id FROM events e
        JOIN event_editions ed ON ed.event_id = e.id
        JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
        LIMIT 1
    ")->fetch();
    if (!$event) {
        t_fail('Baza DEV nie ma opublikowanego wydarzenia — te testy potrzebują turnusu.');
    }

    $db->prepare('INSERT INTO event_rsvps (event_id, edition_id, user_id, status_item_id, joined_at)
                  VALUES (:e, :ed, :u, :s, NOW())')
        ->execute([
            'e'  => $event['id'],
            'ed' => $event['edition_id'],
            'u'  => $userId,
            's'  => Dictionary::id('rsvp_status', 'potwierdzony'),
        ]);

    return ['editionId' => (int) $event['edition_id'], 'rsvpId' => (int) $db->lastInsertId()];
}

t_test('lista solo pokazuje przejazd bez wydarzenia i tylko właściciela', function () {
    [$a, $b] = t_users(2);
    $ride = t_solo_ride($a);

    t_true((int) $ride['id'] > 0, 'przejazd solo jest na liście właściciela');
    t_true((float) $ride['distance_km'] > 0, 'ma dystans');
    t_count(0, array_filter(
        RiderActivity::soloForUser($b),
        static fn(array $r): bool => (int) $r['id'] === (int) $ride['id']
    ), 'druga osoba nie widzi cudzego przejazdu');
});

t_test('właściciel może nadać własną nazwę przejazdowi solo — widać ją na liście i na stronie przejazdu', function () {
    $userId = t_user();
    $ride = t_solo_ride($userId);

    t_true(RiderActivity::rename((int) $ride['id'], $userId, '  Rajd po Beskidach  '), 'zapis się udał');

    $znowu = RiderActivity::soloForUser($userId);
    t_same('Rajd po Beskidach', $znowu[0]['name'], 'lista solo widzi PRZYCIĘTĄ nazwę (bez spacji na brzegach)');

    $strona = RiderActivity::findForPage((int) $ride['id']);
    t_same('Rajd po Beskidach', $strona['name'], 'strona przejazdu (findForPage) widzi tę samą nazwę');
});

t_test('cudzej nazwy przejazdu solo nie da się zmienić (anty-IDOR)', function () {
    [$a, $b] = t_users(2);
    $ride = t_solo_ride($a);

    t_false(RiderActivity::rename((int) $ride['id'], $b, 'Podmieniona nazwa'), 'obca osoba nie zmieni cudzej nazwy');

    $strona = RiderActivity::findForPage((int) $ride['id']);
    t_null($strona['name'], 'nazwa zostaje nietknięta (null, jak od startu)');
});

t_test('pusta nazwa CZYŚCI własną nazwę, nie zapisuje pustego stringu', function () {
    // Zamierzone wyjście z edycji ("wróć do nazwy z licznika/opisu
    // generycznego"), nie błąd walidacji — stąd NULL w kolumnie, nie ''.
    $userId = t_user();
    $ride = t_solo_ride($userId);
    RiderActivity::rename((int) $ride['id'], $userId, 'Tymczasowa nazwa');

    t_true(RiderActivity::rename((int) $ride['id'], $userId, '   '), 'wyczyszczenie samymi spacjami też się udaje');

    $strona = RiderActivity::findForPage((int) $ride['id']);
    t_null($strona['name'], 'kolumna wraca do NULL, nie do pustego stringu');
});

t_test('za długa nazwa przycina się do limitu kolumny, zamiast wywrócić zapis', function () {
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $dluga = str_repeat('a', RiderActivity::NAME_MAX_LENGTH + 50);

    t_true(RiderActivity::rename((int) $ride['id'], $userId, $dluga), 'zapis się udał mimo długości');

    $strona = RiderActivity::findForPage((int) $ride['id']);
    t_same(RiderActivity::NAME_MAX_LENGTH, mb_strlen((string) $strona['name']), 'nazwa przycięta dokładnie do limitu');
});

t_test('recordSolo zwraca dystans i przewyższenie — ekran wyniku jazdy w apce ich potrzebuje', function () {
    // SoloRideController::upload() (branch APP_IS_APP) czyta $result['distanceKm']/
    // ['elevationGainM'] wprost z tego zwrotu (§11 audytu UX 2026-08-28) — bez tych
    // kluczy ekran wyniku nie miałby skąd wziąć dystansu przejechanej jazdy.
    $userId = t_user();
    $gpxUrl = '/assets/uploads/gpx/' . basename(t_solo_gpx());
    $wynik = RiderActivity::recordSolo($userId, t_solo_gpx(), $gpxUrl);

    t_true($wynik !== null, 'przejazd się zapisał');
    t_true(isset($wynik['distanceKm']) && $wynik['distanceKm'] > 0, 'ma dystans w zwrocie');
    t_true(isset($wynik['elevationGainM']) && $wynik['elevationGainM'] >= 0, 'ma przewyższenie w zwrocie (może być 0)');
});

t_test('powiązanie robi ze śladu solo WŁASNY ślad turnusu', function () {
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $rsvp = t_solo_rsvp($userId);

    t_same('ok', RiderActivity::linkSoloToEdition((int) $ride['id'], $userId, $rsvp['editionId']), 'powiązanie się udało');

    $tracks = EditionTrack::forEditionAndUser($rsvp['editionId'], $userId);
    t_count(1, $tracks, 'turnus ma teraz jeden własny ślad tej osoby');
    t_eq($ride['gpx_url'], $tracks[0]['gpx_url'], 'to jest DOKŁADNIE ten sam plik, nie kopia');
});

t_test('powiązanie potwierdza obecność, bo ślad jest dowodem', function () {
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $rsvp = t_solo_rsvp($userId);

    t_null(EventAttendance::forEditionAndUser($rsvp['editionId'], $userId)['attended'], 'przed: nikt nie odpowiedział');

    RiderActivity::linkSoloToEdition((int) $ride['id'], $userId, $rsvp['editionId']);

    t_same(true, EventAttendance::forEditionAndUser($rsvp['editionId'], $userId)['attended'], 'po: obecność potwierdzona');
});

t_test('te same kilometry NIE liczą się dwa razy', function () {
    // To jest powód, dla którego przejazd solo znika przy powiązaniu. Bez tego
    // ten sam plik dawałby dwa wiersze: solo i przejazd z turnusu.
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $rsvp = t_solo_rsvp($userId);

    $ile = static function (int $userId): array {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(distance_km), 0) AS km
               FROM rider_activities WHERE user_id = :u'
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetch();
    };

    $przed = $ile($userId);
    RiderActivity::linkSoloToEdition((int) $ride['id'], $userId, $rsvp['editionId']);
    $po = $ile($userId);

    t_eq($przed['n'], $po['n'], 'liczba przejazdów bez zmian (jeden zamienił się w drugi)');
    t_eq(round((float) $przed['km'], 1), round((float) $po['km'], 1), 'suma kilometrów bez zmian');
    t_count(0, RiderActivity::soloForUser($userId), 'przejazd zniknął z listy solo');
});

t_test('odkrycia nie przepadają — pola wracają na nowym przejeździe', function () {
    // Najłatwiejsza rzecz do zepsucia: skasowanie przejazdu solo zabiera jego
    // odkrycia, a nowy przejazd musi je policzyć od nowa jako swoje.
    //
    // NIE równość, tylko „co najmniej tyle samo", i to jest fakt o produkcie,
    // nie luz w teście: przejazd solo ma PRZYCIĘTE okolice domu (§27,
    // DiscoveryGrid::trimEnds), a ślad z turnusu liczy się z całego pliku —
    // bo wyjazd zaczyna się na zbiórce, nie pod czyimś blokiem. Powiązanie
    // zdejmuje więc przycięcie z tego jednego śladu i pól bywa o kilka więcej.
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $rsvp = t_solo_rsvp($userId);
    $polaPrzed = (int) $ride['cells_new'];

    RiderActivity::linkSoloToEdition((int) $ride['id'], $userId, $rsvp['editionId']);

    $stmt = Database::connection()->prepare(
        'SELECT cells_new FROM rider_activities WHERE rsvp_id = :r'
    );
    $stmt->execute(['r' => $rsvp['rsvpId']]);
    $polaPo = $stmt->fetchColumn();

    t_true($polaPo !== false, 'przejazd z turnusu powstał');
    t_true((int) $polaPo >= $polaPrzed, 'co najmniej tyle samo nowych pól co przed powiązaniem'
        . ' (przed: ' . $polaPrzed . ', po: ' . (int) $polaPo . ')');
});

t_test('cudzego przejazdu nie da się powiązać ze swoim wyjazdem', function () {
    [$a, $b] = t_users(2);
    $ride = t_solo_ride($a);
    $rsvp = t_solo_rsvp($b);

    t_same('brak-przejazdu', RiderActivity::linkSoloToEdition((int) $ride['id'], $b, $rsvp['editionId']),
        'anty-IDOR: sam identyfikator nie wystarczy');
    t_count(1, RiderActivity::soloForUser($a), 'przejazd właściciela nietknięty');
});

t_test('bez potwierdzonego zapisu na turnus powiązanie odmawia', function () {
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $obcyTurnus = (int) Database::connection()->query('SELECT id FROM event_editions ORDER BY id DESC LIMIT 1')->fetchColumn();

    t_same('brak-zapisu', RiderActivity::linkSoloToEdition((int) $ride['id'], $userId, $obcyTurnus), 'odmowa');
    t_count(1, RiderActivity::soloForUser($userId), 'przejazd solo został na miejscu');
});

t_test('po odpowiedzi „nie dojechałem" powiązanie odmawia i NIC nie kasuje', function () {
    // Gdyby przeszło, przejazd solo zniknąłby, a przejazd z turnusu nie powstał
    // (bez obecności nie ma z czego) — człowiek straciłby odkrycia bez ostrzeżenia.
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $rsvp = t_solo_rsvp($userId);
    EventAttendance::declare($rsvp['rsvpId'], false, $userId, false);

    t_same('nie-bylem', RiderActivity::linkSoloToEdition((int) $ride['id'], $userId, $rsvp['editionId']), 'odmowa');
    t_count(1, RiderActivity::soloForUser($userId), 'przejazd solo nietknięty');
    t_count(0, EditionTrack::forEditionAndUser($rsvp['editionId'], $userId), 'żaden ślad nie został podpięty');
});

// -------------------------------------------------------------------
// FARMING TĄ SAMĄ PĘTLĄ (pytanie usera 2026-08-23: „mogę zrobić sobie trasę
// w obrębie 30 hex i jeździć codziennie, odkryję 400 razy ten sam skarb
// i będę królem listy!"). Testy odpowiadają na to LICZBAMI, nie zapewnieniem.
//
// Ta sama TRASA, ale inny PLIK — bo klucz UNIQUE (user_id, gpx_hash) blokuje
// wyłącznie wgranie tego samego pliku drugi raz, a codzienna runda daje co
// dzień nowy ślad. Symulujemy to dopisując do kopii pliku komentarz XML:
// przebieg identyczny, hash inny.
// -------------------------------------------------------------------

/** Kopia śladu o INNEJ zawartości (inny hash), ale tym samym przebiegu. */
function t_solo_gpx_kopia(): string
{
    $tresc = (string) file_get_contents(t_solo_gpx());
    $kopia = str_replace('</gpx>', '<!-- ' . bin2hex(random_bytes(8)) . ' --></gpx>', $tresc);
    $url = Utils\Upload::saveGpxContents($kopia);
    if ($url === null) {
        t_fail('Nie udało się zapisać kopii śladu do testu powtórki.');
    }
    return $url;
}

t_test('druga runda tą samą pętlą NIE daje ani jednego pola i ani punktu za odkrycie', function () {
    $userId = t_user();
    $pierwszy = RiderActivity::recordSolo($userId, t_solo_gpx(), '/assets/uploads/gpx/' . basename(t_solo_gpx()));
    t_true($pierwszy !== null && $pierwszy['cellsNew'] > 0, 'pierwszy przejazd odkrywa pola');

    $kopiaUrl = t_solo_gpx_kopia();
    $drugi = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);

    t_true($drugi !== null, 'drugi przejazd zapisuje się (jazda naprawdę się odbyła)');
    t_same(0, $drugi['cellsNew'], 'ZERO nowych pól przy powtórce');
    t_same(0, $drugi['pointsDiscovery'], 'ZERO punktów za odkrycia');
    t_same(0, $drugi['pointsExploration'], 'ZERO punktów za nowy teren');
});

t_test('ten sam skarb liczy się RAZ NA ZAWSZE, nie raz na przejazd', function () {
    // UNIQUE (treasure_id, user_id) w treasure_finds — nie „raz dziennie",
    // tylko raz w ogóle. To jest odpowiedź na „odkryję 400 razy ten sam skarb".
    $userId = t_user();
    $pierwszy = RiderActivity::recordSolo($userId, t_solo_gpx(), '/assets/uploads/gpx/' . basename(t_solo_gpx()));
    $ileZaPierwszym = count($pierwszy['treasures'] ?? []);

    $kopiaUrl = t_solo_gpx_kopia();
    $drugi = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);

    t_same(0, count($drugi['treasures'] ?? []), 'druga runda nie znajduje NICZEGO ponownie'
        . ' (za pierwszym razem: ' . $ileZaPierwszym . ')');
});

t_test('za samą jazdę punkty NALEŻĄ SIĘ za każdym razem — i to jest decyzja, nie luka', function () {
    // Jedyna kategoria, którą wolno zdobyć powtórnie (core/discovery.php:
    // „bo jazda naprawdę się odbyła, choćby po znanej pętli"). Test pilnuje,
    // żeby zmiana tej zasady nigdy nie stała się przypadkiem.
    $userId = t_user();
    $pierwszy = RiderActivity::recordSolo($userId, t_solo_gpx(), '/assets/uploads/gpx/' . basename(t_solo_gpx()));
    $kopiaUrl = t_solo_gpx_kopia();
    $drugi = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);

    t_eq($pierwszy['pointsRide'], $drugi['pointsRide'], 'tyle samo punktów za jazdę co za pierwszym razem');
    t_true($drugi['pointsRide'] > 0, 'i jest to liczba dodatnia');

    // Dzienny sufit na punkty za jazdę ISTNIEJE w kodzie, ale jest wyłączony
    // w konfiguracji (`ride.daily_cap = null`). Ten test mówi wprost, w którym
    // stanie jest system — żeby włączenie sufitu było decyzją, a nie odkryciem.
    t_null(Models\DiscoveryScoring::dailyRideCap(), 'dzienny limit punktów za jazdę jest WYŁĄCZONY');
});

// ---------------------------------------------------------------------------
// PRYWATNOŚĆ ŚLADU SOLO (§27) — trzy testy pilnujące JEDNEJ decyzji z 2026-08-26:
// plik na dysku zostaje surowy (bo jest źródłem kilometrów), a osłoną jest to,
// że NIE WYCHODZI PUBLICZNIE SUROWY. Wcześniej było odwrotnie — plik był
// przycinany przy zapisie — i kosztowało to człowieka dojazd z domu przy
// powiązaniu przejazdu z turnusem. Te testy trzymają obie połowy naraz: bez
// nich powrót do przycinania pliku wygląda jak niewinne porządki.
//
// NIUANS OD 2026-08-28 (migr. 076): „nie wychodzi publicznie" nie znaczy już
// „nie wychodzi wcale". Solo dostało DRUGĄ, PRZYCIĘTĄ geometrię specjalnie
// pod heatmapę społeczności (patrz test „wspólna warstwa Ślady niesie solo —
// PRZYCIĘTE, nigdy surowe" niżej) — SUROWY plik dalej nigdy nie ląduje na
// kluczu `all`, ale bezpieczna, przycięta KOPIA już tak. Te trzy testy niżej
// dalej pilnują SUROWEGO PLIKU NA DYSKU i endpointu `atPoint()` — tamte dwie
// drogi nadal muszą zostać zamknięte tak samo szczelnie jak wcześniej.
// ---------------------------------------------------------------------------

t_test('plik solo zostaje na dysku SUROWY — bo to z niego liczą się kilometry', function () {
    // Gdyby recordSolo znów nadpisywał plik przyciętą wersją, ten test spadnie
    // JAKO PIERWSZY — zanim ktokolwiek zauważy, że powiązania z turnusem po
    // cichu gubią po kilkaset metrów.
    $sciezka = t_solo_gpx();
    $przed = count(Utils\Gpx::parse($sciezka)['points']);

    t_solo_ride(t_user());

    $po = count(Utils\Gpx::parse($sciezka)['points']);
    t_eq($przed, $po, 'liczba punktów w pliku bez zmian po zapisaniu przejazdu');
});

t_test('wspólna warstwa „Ślady" niesie solo — PRZYCIĘTE, nigdy surowe (naprawa 2026-08-28)', function () {
    // DO 2026-08-27 ten test pilnował odwrotnej reguły: solo NIE trafiało do
    // `all` w ogóle. Zgłoszenie usera, które to odwróciło: był przekonany, że
    // heatmapa społeczności liczy WSZYSTKIE przejazdy, bo pola odkryć liczą
    // wszystkie — a „Ślady" tylko `edition_tracks`. Odkryte pole nie miało
    // pod sobą śladu na mapie. Naprawa (migr. 076): solo WCHODZI do `all`,
    // ale WYŁĄCZNIE przez DRUGĄ, PRZYCIĘTĄ geometrię (`gpx_geometry_trimmed`),
    // z tym samym promieniem „okolic domu" co przy polach odkryć — surowy
    // plik nadal nigdy nie ląduje na kluczu, który leży publicznie na dysku.
    $ride = t_solo_ride(t_user());
    $hashSolo = Models\GpxGeometry::ensure(t_solo_gpx()); // PEŁNA geometria — punkt odniesienia

    $mojaGrupa = null;
    foreach (Models\TileSource::tracks('all') as $grupa) {
        if (in_array($hashSolo, $grupa['hashes'], true)) {
            $mojaGrupa = $grupa;
        }
    }
    t_not_null($mojaGrupa, 'hash pliku solo TERAZ trafia do warstwy `all`');
    t_same('trimmed', $mojaGrupa['source'] ?? null,
        'ale WYŁĄCZNIE ze źródła `trimmed` — nigdy `normal` (tam leży PEŁNA geometria)');

    // SEDNO PRYWATNOŚCI: to, co faktycznie idzie na kafel dla tego hasha
    // (`GpxGeometry::loadTrimmed`, bo `source` jest `trimmed`) ma WYCIĘTE
    // okolice domu — mniej punktów niż pełny plik, nie tyle samo.
    $pelna = Models\GpxGeometry::load([$hashSolo])[$hashSolo] ?? null;
    $przycieta = Models\GpxGeometry::loadTrimmed([$hashSolo])[$hashSolo] ?? null;
    t_not_null($pelna, 'pełna geometria istnieje (punkt odniesienia)');
    t_not_null($przycieta, 'przycięta geometria istnieje — TO ONA idzie na kafel `all`');
    t_true(count($przycieta['pts']) < count($pelna['pts']),
        'przycięta geometria ma MNIEJ punktów niż pełna — okolice domu naprawdę wycięte'
        . ' (pełna: ' . (count($pelna['pts']) / 2) . ' pkt, przycięta: ' . (count($przycieta['pts']) / 2) . ' pkt)');

    // Kontrola pozytywna: warstwa MA rysować ślady wyjazdów, osobną grupą,
    // źródłem `normal`. Bez tego test przechodziłby także wtedy, gdyby `all`
    // zaczęło mieszać oba źródła w jedną, nieodróżnialną grupę.
    $sladWyjazdu = Database::connection()
        ->query('SELECT gpx_url FROM edition_tracks WHERE gpx_url IS NOT NULL LIMIT 1')
        ->fetchColumn();
    if ($sladWyjazdu !== false) {
        $hashWyjazdu = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $sladWyjazdu);
        $grupaWyjazdu = null;
        foreach (Models\TileSource::tracks('all') as $grupa) {
            if (in_array($hashWyjazdu, $grupa['hashes'], true)) {
                $grupaWyjazdu = $grupa;
            }
        }
        t_not_null($grupaWyjazdu, 'ślad WYJAZDU nadal jest w warstwie `all`');
        t_same('normal', $grupaWyjazdu['source'] ?? 'normal', 'i czyta z PEŁNEJ geometrii — wyjazd nie zaczyna się pod domem');
    }
});

// t_auth_as() przeniesione do tests/lib.php (2026-08-28) — ten sam wzorzec
// był potrzebny drugiemu testowi kontrolera (skarby: kolejka offline).

t_test('WŁASNA warstwa „Ślady" (klucz `me`) NIESIE solo — naprawa 2026-08-26', function () {
    // Zgłoszenie usera: „ślady, które wgrałem, nie pojawiają się na mapie".
    // Przyczyna była podwójna: (1) gałąź `me`/`u-{slug}` w TileSource::tracks()
    // w ogóle nie sięgała po `rider_activities.gpx_url` — solo nie wchodziło
    // tam NIGDY, niezależnie od tej sesji; (2) `userIdFor('me')` wołało
    // `Auth::id()`, metodę, która nie istnieje — każde żądanie klucza `me`
    // od kogokolwiek zalogowanego kończyło się fatalnym błędem PHP zamiast
    // pustą albo niepustą mapą. Oba znalezione i naprawione w tym samym
    // przebiegu; ten test pilnuje obu naraz, bo naprawienie jednego bez
    // drugiego dalej dawałoby pustą albo wywróconą mapę.
    $userId = t_user();
    t_solo_ride($userId);
    $hashSolo = Models\GpxGeometry::ensure(t_solo_gpx());
    t_true(is_string($hashSolo) && $hashSolo !== '', 'plik solo ma policzoną geometrię (inaczej test niczego nie sprawdza)');

    t_auth_as($userId);
    try {
        $wKafle = [];
        foreach (Models\TileSource::tracks('me') as $grupa) {
            foreach ($grupa['hashes'] as $h) {
                $wKafle[$h] = true;
            }
        }
    } finally {
        t_auth_as(null);
    }

    t_true(isset($wKafle[$hashSolo]), 'własny klucz `me` NIESIE świeżo dodane solo');
});

t_test('klucz `me` nie wybucha (regresja `Auth::id()`) i jest pusty bez sesji', function () {
    // `TileSource::userIdFor('me')` wołało nieistniejącą `Auth::id()` —
    // sprawdzone wprost, żeby literówka w przyszłej refaktoryzacji Auth
    // nie wróciła cicho jako fatalny błąd zamiast czerwonego testu.
    t_auth_as(null);
    t_count(0, Models\TileSource::tracks('me'), 'bez sesji: pusto, nie wyjątek');

    $userId = t_user();
    t_auth_as($userId);
    try {
        $grupy = Models\TileSource::tracks('me');
    } finally {
        t_auth_as(null);
    }
    t_true(is_array($grupy), 'z sesją: zwraca tablicę, nie fatalny błąd');
});

t_test('adres śladu solo z feedu: właściciel zawsze, obcy TYLKO gdy właściciel publiczny (2026-09-10)', function () {
    // DO 2026-09-10 obcy nie dostawał tu adresu W OGÓLE — mimo że sam
    // endpoint (`/api/rides/{id}/track`) od 2026-09-03 umie oddać mu ślad
    // PRZYCIĘTY (patrz strona przejazdu, `Support::strangerTrackPath`).
    // Ta metoda była jedynym miejscem, które o to nie zapytało: panel
    // „Ostatnia aktywność" na cudzym publicznym profilu nie dawał kliknąć
    // solo, mimo że sam link by zadziałał poprawnie. Zgłoszenie usera:
    // „na swoim profilu każdy przejazd jest klikalny, na czyimś powinno być
    // tak samo" — konta świeże (`t_swiezy_user`), nie `t_user()`, bo test
    // musi SAM kontrolować `roster_visible`, a nie zgadywać stan bazy dev.
    $wlasciciel = t_swiezy_user();
    Models\User::ensurePublicSlug($wlasciciel);
    $obcy = t_swiezy_user();
    $wiersze = [[
        'id'         => 987654,
        'user_id'    => $wlasciciel,
        'edition_id' => null,
        'gpx_url'    => '/assets/uploads/gpx/' . basename(t_solo_gpx()),
    ]];

    t_count(1, Controllers\Support::trackUrlsForFeed($wiersze, $wlasciciel), 'właściciel dostaje adres swojego śladu');
    t_count(1, Controllers\Support::trackUrlsForFeed($wiersze, $obcy),
        'PUBLICZNY właściciel: obcy TEŻ dostaje adres — prowadzi do wersji PRZYCIĘTEJ, nie do niczego');
    t_count(1, Controllers\Support::trackUrlsForFeed($wiersze, null),
        'PUBLICZNY właściciel: niezalogowany TEŻ dostaje adres (ta sama zasada co strona przejazdu)');

    Database::connection()->prepare('UPDATE users SET roster_visible = 0 WHERE id = :id')
        ->execute(['id' => $wlasciciel]);
    t_count(0, Controllers\Support::trackUrlsForFeed($wiersze, $obcy),
        'UKRYTY właściciel: obcy nie dostaje NIC — ukrycie z list (§27) działa też tutaj');
    t_count(1, Controllers\Support::trackUrlsForFeed($wiersze, $wlasciciel),
        'ukrycie nie dotyczy WŁAŚCICIELA patrzącego na WŁASNY wiersz');
});

/**
 * Rozpakowanie formatu `d6v` — LUSTRO tego, co robi przeglądarka
 * (`ridemoreUnpackTrack` w assets/js/discovery-map.js). Stoi tu celowo napisane
 * od zera zamiast wołania kodu produkcyjnego: gdyby dekoder brał się z tego
 * samego miejsca co koder, test przechodziłby także wtedy, gdy oba są zgodnie
 * zepsute, a to właśnie zgodność DWÓCH NIEZALEŻNYCH implementacji jest tu
 * pilnowana.
 *
 * @return list<array{0:float,1:float}>
 */
function t_unpack_d6v(string $b64): array
{
    $s = base64_decode($b64, true);
    if ($s === false) {
        t_fail('Odpowiedź nie jest poprawnym base64.');
    }
    $i = 0;
    $n = strlen($s);
    $lat = 0;
    $lon = 0;
    $out = [];
    $licz = static function () use (&$i, $s, $n): int {
        $r = 0;
        $shift = 0;
        do {
            $b = ord($s[$i++]);
            $r += ($b & 0x7F) << $shift;
            $shift += 7;
        } while (($b & 0x80) && $i < $n);

        return ($r & 1) ? -(($r + 1) >> 1) : ($r >> 1);
    };
    while ($i < $n) {
        $lat += $licz();
        if ($i >= $n) {
            break;
        }
        $lon += $licz();
        $out[] = [$lat / 1e6, $lon / 1e6];
    }

    return $out;
}

t_test('podświetlenie śladu bierze geometrię z bazy, nie plik GPX', function () {
    // Zgłoszenie usera (2026-09-02): klik w wiersz „Ostatniej aktywności"
    // czekał na pobranie i sparsowanie 10+ MB GPX-a z tętnem, mocą i kadencją,
    // choć do narysowania linii potrzeba dwóch liczb na punkt.
    $plik = t_solo_gpx();
    $packed = Models\GpxGeometry::packedForFile($plik);

    t_not_null($packed, 'plik z uploadów daje geometrię');
    t_eq(Models\GpxGeometry::PACK_ENC, $packed['enc'], 'odpowiedź nazywa swój format');
    t_true($packed['n'] > 0, 'ślad ma punkty');

    $punkty = t_unpack_d6v($packed['pts']);
    t_eq($packed['n'], count($punkty), 'rozpakowanych punktów tyle, ile zapowiada odpowiedź');

    // ZGODNOŚĆ Z ZAPISANĄ GEOMETRIĄ — po to, żeby „mniejsze" nie znaczyło
    // przypadkiem „inne". Tolerancja 1e-6 stopnia (ok. 0,11 m) to dokładnie
    // krok, na który tnie transport; piksel STORE_Z jest od niej grubszy.
    $hash = hash_file('sha256', $plik);
    $geom = Models\GpxGeometry::load([$hash])[$hash] ?? null;
    t_not_null($geom, 'ten sam plik ma wiersz w gpx_geometry');
    t_eq(count($geom['pts']) / 2, $packed['n'], 'nie zgubiliśmy ani nie doliczyliśmy punktu');

    $ost = count($punkty) - 1;
    foreach ([[0, 0], [$ost, $ost * 2]] as [$idx, $off]) {
        [$lat, $lon] = Utils\TileGrid::toLatLon($geom['pts'][$off], $geom['pts'][$off + 1]);
        t_true(abs($lat - $punkty[$idx][0]) <= 1e-6, "punkt $idx: szerokość zgodna z geometrią w bazie");
        t_true(abs($lon - $punkty[$idx][1]) <= 1e-6, "punkt $idx: długość zgodna z geometrią w bazie");
    }

    // KADR z odpowiedzi musi obejmować ślad — rysowanie nie ma już skąd go
    // wziąć samo, bo nie czeka na wczytany plik.
    $b = $packed['bounds'];
    t_true($b['north'] >= $b['south'] && $b['east'] >= $b['west'], 'prostokąt kadru nie jest odwrócony');
    $poza = 0;
    foreach ($punkty as [$lat, $lon]) {
        if ($lat < $b['south'] - 1e-5 || $lat > $b['north'] + 1e-5
            || $lon < $b['west'] - 1e-5 || $lon > $b['east'] + 1e-5) {
            $poza++;
        }
    }
    t_eq(0, $poza, 'każdy punkt mieści się w kadrze z odpowiedzi');

    // I to, po co cała zmiana powstała.
    t_true(
        strlen($packed['pts']) < filesize($plik) / 5,
        'odpowiedź jest wielokrotnie mniejsza niż plik GPX (' . strlen($packed['pts'])
            . ' B vs ' . filesize($plik) . ' B)'
    );
});

t_test('feed podaje adres endpointu geometrii, nie adres pliku', function () {
    // Ta sama bramka co wyżej, ale sprawdzona od strony tego, co ląduje
    // w HTML-u: plik solo nie ma już wychodzić nawet do właściciela.
    $wlasciciel = t_user(0);
    $wiersze = [[
        'id'         => 987654,
        'user_id'    => $wlasciciel,
        'edition_id' => null,
        'gpx_url'    => '/assets/uploads/gpx/' . basename(t_solo_gpx()),
    ]];

    $adresy = Controllers\Support::trackUrlsForFeed($wiersze, $wlasciciel);
    t_count(1, $adresy, 'właściciel dostaje adres swojego śladu');
    t_true(str_contains($adresy[987654], '/api/rides/987654/track'), 'adres prowadzi do endpointu geometrii');
    t_false(str_contains($adresy[987654], '.gpx'), 'w adresie nie ma już pliku GPX');

    // Bramka endpointu to TA SAMA metoda, którą liczy feed — sprawdzana wprost,
    // bo od 2026-09-02 to ona, a nie sam feed, decyduje o wydaniu śladu.
    t_not_null(Controllers\Support::rideGpxPath($wiersze[0], $wlasciciel), 'właściciel: endpoint znajdzie plik');
    t_null(Controllers\Support::rideGpxPath($wiersze[0], t_user(1)), 'obcy: endpoint nie znajdzie pliku solo');
    t_null(Controllers\Support::rideGpxPath($wiersze[0], null), 'niezalogowany: endpoint nie znajdzie pliku solo');
});

// ---------------------------------------------------------------------------
// SZUKANIE, STRONICOWANIE I KASOWANIE (2026-08-26) — zgłoszenie usera: „jedyna
// akcja jaka może zostać podjęta to przypisanie trasy z wyjazdem, powinienem
// mieć więcej możliwości". Testy kasowania używają WŁASNEJ KOPII pliku
// (t_solo_gpx_kopia), NIGDY t_solo_gpx() wprost — deleteSoloForUser() kasuje
// plik z DYSKU naprawdę, poza transakcją testu, więc odpalenie go na
// współdzielonym pliku źródłowym zniszczyłoby go dla wszystkich pozostałych
// testów (i dla realnych danych w bazie DEV, jeśli to akurat ten sam plik).
// ---------------------------------------------------------------------------

t_test('szukanie solo: druga osoba nie widzi cudzego przejazdu', function () {
    [$a, $b] = t_users(2);
    $ride = t_solo_ride($a);

    $wynikB = RiderActivity::searchSoloForUser($b);
    t_count(0, array_filter(
        $wynikB['items'],
        static fn(array $r): bool => (int) $r['id'] === (int) $ride['id']
    ), 'cudzy przejazd solo nie wchodzi do wyniku');
});

t_test('szukanie solo: fraza dopasowana do daty znajduje przejazd, inna fraza nie', function () {
    $userId = t_user();
    $ride = t_solo_ride($userId);
    $data = (string) $ride['ride_date'];

    $trafienie = RiderActivity::searchSoloForUser($userId, ['szukaj' => $data]);
    t_true(in_array((int) $ride['id'], array_map(
        static fn(array $r): int => (int) $r['id'], $trafienie['items']
    ), true), 'dopasowanie po dacie (RRRR-MM-DD) znajduje przejazd');

    $brak = RiderActivity::searchSoloForUser($userId, ['szukaj' => 'zupelnie-inna-fraza-xyz']);
    t_count(0, array_filter(
        $brak['items'],
        static fn(array $r): bool => (int) $r['id'] === (int) $ride['id']
    ), 'fraza spoza danych nie trafia w ten przejazd');
});

t_test('szukanie solo: kształt wyniku ma total/strona/stron/szukaj', function () {
    $userId = t_user();
    t_solo_ride($userId);

    $wynik = RiderActivity::searchSoloForUser($userId, ['strona' => 1]);
    t_true($wynik['total'] >= 1, 'total liczy realne wiersze (jest: ' . $wynik['total'] . ')');
    t_same(1, $wynik['strona'], 'strona domyślna to 1');
    t_true($wynik['stron'] >= 1, 'liczba stron co najmniej 1');
    t_same('', $wynik['szukaj'], 'pusta fraza wraca jako pusty string');
});

t_test('kasowanie własnego przejazdu solo usuwa go z listy I z dysku', function () {
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    t_true($wynik !== null, 'przejazd testowy (kopia pliku) się zapisał');
    $sciezka = CORE_PATH . '/..' . $kopiaUrl;
    t_true(is_file($sciezka), 'plik istnieje przed kasowaniem');

    $activityId = (int) $wynik['activityId'];
    t_true(RiderActivity::deleteSoloForUser($activityId, $userId), 'kasowanie się udało');

    t_count(0, array_filter(
        RiderActivity::soloForUser($userId),
        static fn(array $r): bool => (int) $r['id'] === $activityId
    ), 'zniknął z listy solo');
    t_false(is_file($sciezka), 'plik zniknął z dysku — nic go już nie potrzebuje '
        . '(w odróżnieniu od linkSoloToEdition, gdzie plik PRZEJMUJE edition_tracks)');
});

t_test('cudzego przejazdu solo nie da się usunąć (anty-IDOR)', function () {
    [$a, $b] = t_users(2);
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($a, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    $activityId = (int) $wynik['activityId'];
    $sciezka = CORE_PATH . '/..' . $kopiaUrl;

    t_false(RiderActivity::deleteSoloForUser($activityId, $b), 'obca osoba nie usunie cudzego przejazdu');
    t_count(1, array_filter(
        RiderActivity::soloForUser($a),
        static fn(array $r): bool => (int) $r['id'] === $activityId
    ), 'przejazd właściciela nietknięty');
    t_true(is_file($sciezka), 'plik nietknięty');

    // Właściciel sprząta po sobie — ta kopia istniała wyłącznie na potrzeby
    // tego testu i nikomu innemu już się nie przyda.
    RiderActivity::deleteSoloForUser($activityId, $a);
});

t_test('nieistniejący przejazd solo: kasowanie zwraca false, nie wysypuje się', function () {
    $userId = t_user();
    t_false(RiderActivity::deleteSoloForUser(999999999, $userId), 'brak przejazdu = false, nie wyjątek');
});

// ---------------------------------------------------------------------------
// KLIK W ŚLAD NA MAPIE (2026-08-27) — prośba usera: „dodajmy możliwość klikania
// na solo ślady tak samo jak na znane trasy". `RiderActivity::atPoint()`.
//
// Testy pilnują przede wszystkim PRYWATNOŚCI: ta metoda odpowiada na pytanie
// „czyj ślad tu leży", a ślad solo jest surowy i zaczyna się pod czyimś domem
// (§27). Wyciek tutaj byłby wyciekiem adresów, nie kilometrów.
// ---------------------------------------------------------------------------

/** Punkt LEŻĄCY na śladzie — wzięty wprost z zapisanej geometrii. */
function t_solo_punkt_na_sladzie(string $gpxUrl): array
{
    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $gpxUrl);
    $geom = Models\GpxGeometry::load([$hash])[$hash] ?? null;
    if ($geom === null) {
        t_fail('Ślad testowy nie ma policzonej geometrii — nie ma w co klikać.');
    }
    // Punkt ze ŚRODKA śladu, nie z końca: końce przejazdu solo bywają
    // przycięte w polach (§27), a chcemy trafiać w to, co widać jako linia.
    $i = ((int) (count($geom['pts']) / 4)) * 2;

    return Utils\TileGrid::toLatLon($geom['pts'][$i], $geom['pts'][$i + 1]);
}

t_test('klik w punkt NA śladzie znajduje ten przejazd', function () {
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    t_true($wynik !== null, 'przejazd testowy się zapisał');

    [$lat, $lon] = t_solo_punkt_na_sladzie($kopiaUrl);
    $trafienia = RiderActivity::atPoint($lat, $lon, 14, $userId);

    $ids = array_map(static fn(array $r): int => $r['id'], $trafienia);
    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $userId);

    t_true(in_array((int) $wynik['activityId'], $ids, true),
        'kliknięty przejazd jest wśród trafień (trafień: ' . count($trafienia) . ')');
});

t_test('klik DALEKO od śladu nie trafia w nic', function () {
    // Pudło ma zostawiać mapę w spokoju — dymek „nic tu nie ma" byłby karą
    // za kliknięcie w tło.
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);

    [$lat, $lon] = t_solo_punkt_na_sladzie($kopiaUrl);
    // Stopień szerokości to ok. 111 km — żaden ślad testowy tam nie sięga.
    $trafienia = RiderActivity::atPoint($lat + 1.0, $lon + 1.0, 14, $userId);

    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $userId);

    t_count(0, $trafienia, 'sto kilometrów obok = zero trafień');
});

t_test('CUDZEGO śladu nie da się kliknąć — to jest ochrona adresu domowego', function () {
    // NAJWAŻNIEJSZY TEST TEGO ZESTAWU. Plik solo jest surowy (§27), więc
    // odpowiedź „tutaj leży czyjś przejazd" dla obcej osoby byłaby czytnikiem
    // cudzych tras spod domu — wystarczyłoby klikać po mapie.
    [$a, $b] = t_users(2);
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($a, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);

    [$lat, $lon] = t_solo_punkt_na_sladzie($kopiaUrl);
    $wlasciciel = RiderActivity::atPoint($lat, $lon, 14, $a);
    $obcy = RiderActivity::atPoint($lat, $lon, 14, $b);

    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $a);

    t_true(count($wlasciciel) > 0, 'właściciel trafia we własny ślad');
    t_count(0, array_filter(
        $obcy,
        static fn(array $r): bool => (int) $r['id'] === (int) $wynik['activityId']
    ), 'obca osoba NIE dostaje cudzego przejazdu');
});

t_test('trafienie niesie to, co pokazuje wiersz listy — z kolorem linii włącznie', function () {
    // Dymek ma mówić to samo, co zakładka „Przejazdy solo": dwa ekrany
    // opisujące ten sam byt dwoma zestawami liczb czytają się jak dwa byty.
    // Kolor jest tu osobno ważny — od migr. 073 każdy ślad ma własny i to
    // jedyna rzecz, która w dymku mówi, KTÓRY ślad kliknięto.
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);

    [$lat, $lon] = t_solo_punkt_na_sladzie($kopiaUrl);
    $trafienia = RiderActivity::atPoint($lat, $lon, 14, $userId);
    $moje = null;
    foreach ($trafienia as $t) {
        if ((int) $t['id'] === (int) $wynik['activityId']) { $moje = $t; }
    }

    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $userId);

    t_not_null($moje, 'trafienie znalezione');
    foreach (['id', 'name', 'date', 'color', 'distanceKm', 'cellsNew', 'points', 'isSolo'] as $klucz) {
        t_true(array_key_exists($klucz, $moje), 'trafienie niesie klucz `' . $klucz . '`');
    }
    t_true(in_array($moje['color'], Utils\TrackPalette::COLORS, true),
        'kolor pochodzi z palety śladów, a nie jest wymyślony: ' . $moje['color']);
    t_same(true, $moje['isSolo'], 'przejazd solo jest oznaczony jako solo');
    t_true((float) $moje['distanceKm'] > 0, 'dystans jest realny');
});

t_test('tolerancja rośnie z oddaleniem — inaczej przy oddaleniu nie dałoby się trafić', function () {
    // Ta sama zasada co przy znanych trasach (`KnownRoute::atPoint`): przy
    // oddaleniu jeden piksel to setki metrów, więc wymaganie precyzji byłoby
    // wymaganiem niemożliwego. Punkt świadomie OBOK śladu: przy dużym
    // przybliżeniu ma być pudłem, przy oddaleniu — trafieniem.
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);

    [$lat, $lon] = t_solo_punkt_na_sladzie($kopiaUrl);
    $obok = $lat + 0.004;   // ok. 440 m na północ

    $bliski = RiderActivity::atPoint($obok, $lon, 18, $userId);
    $daleki = RiderActivity::atPoint($obok, $lon, 9, $userId);

    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $userId);

    t_count(0, $bliski, 'przy maksymalnym przybliżeniu 440 m obok to pudło');
    t_true(count($daleki) > 0, 'przy oddaleniu ten sam punkt trafia w ślad');
});

// ---------------------------------------------------------------------------
// KLIK W SOLO NA PUBLICZNYM PROFILU — RiderActivity::atPointForRider()
// (2026-09-10, zgłoszenie usera: „na swoim profilu każdy przejazd jest
// klikalny, na czyimś powinno być tak samo" — trzecia i ostatnia z trzech
// napraw tego dnia, po kolorze per ślad na `u-{slug}` i klikalności
// w „Ostatniej aktywności"). Bliźniak zestawu wyżej dla `atPoint()`, z tą
// samą troską o §27 — tu w wersji PRZYCIĘTEJ, więc dodatkowy dowód: punkt
// w promieniu domowym MUSI zostać pudłem, mimo że ten sam punkt pełny
// `atPoint()` (właściciel) by znalazł.
// ---------------------------------------------------------------------------

/** Punkt z SAMEGO POCZĄTKU śladu (geometria PEŁNA) — w promieniu domowym. */
function t_solo_punkt_startowy(string $gpxUrl): array
{
    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $gpxUrl);
    $geom = Models\GpxGeometry::load([$hash])[$hash] ?? null;
    if ($geom === null) {
        t_fail('Ślad testowy nie ma policzonej geometrii — nie ma w co klikać.');
    }
    return Utils\TileGrid::toLatLon($geom['pts'][0], $geom['pts'][1]);
}

t_test('atPointForRider(): obcy trafia w solo PUBLICZNEGO rowerzysty na środku trasy', function () {
    $wlasciciel = t_swiezy_user();
    Models\User::ensurePublicSlug($wlasciciel);

    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($wlasciciel, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    t_true($wynik !== null, 'testowy przejazd solo się zapisał');

    [$lat, $lon] = t_solo_punkt_na_sladzie($kopiaUrl);

    try {
        $trafienia = RiderActivity::atPointForRider($lat, $lon, 14, $wlasciciel);
        $ids = array_map(static fn(array $r): int => $r['id'], $trafienia);
        t_true(in_array((int) $wynik['activityId'], $ids, true),
            'klik obcego w ŚRODEK trasy publicznego rowerzysty trafia w ten przejazd (trafień: ' . count($trafienia) . ')');
    } finally {
        RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $wlasciciel);
    }
});

t_test('atPointForRider(): §27 — okolice domu NIE są trafialne, mimo że pełny atPoint() by je znalazł', function () {
    // NAJWAŻNIEJSZY TEST TEGO ZESTAWU — ten sam powód co „CUDZEGO śladu nie
    // da się kliknąć" przy atPoint() wyżej, tylko na klikaniu z PUBLICZNEGO
    // profilu, gdzie ryzyko jest większe: adres jest do zgadnięcia (sam
    // slug), więc TU dostęp musi być bezpieczny dla KAŻDEGO, nie tylko dla
    // kogoś, kto akurat zna czyjeś id.
    $wlasciciel = t_swiezy_user();
    Models\User::ensurePublicSlug($wlasciciel);

    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($wlasciciel, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    t_true($wynik !== null, 'testowy przejazd solo się zapisał');

    [$lat, $lon] = t_solo_punkt_startowy($kopiaUrl);

    try {
        // DOWÓD, że punkt naprawdę leży w promieniu domowym: pełny atPoint()
        // (właściciel, prywatna mapa /odkrycia) GO ZNAJDUJE.
        $pelny = RiderActivity::atPoint($lat, $lon, 16, $wlasciciel);
        t_true(
            in_array((int) $wynik['activityId'], array_map(static fn(array $r): int => $r['id'], $pelny), true),
            'test zakłada punkt w promieniu domowym — pełny atPoint() (właściciel) musi go znaleźć, inaczej test niczego nie dowodzi'
        );

        // PRZYCIĘTY hit-test (publiczny profil) TEGO SAMEGO punktu NIE ZNAJDUJE.
        $obcy = RiderActivity::atPointForRider($lat, $lon, 16, $wlasciciel);
        t_count(0, array_filter(
            $obcy,
            static fn(array $r): bool => (int) $r['id'] === (int) $wynik['activityId']
        ), 'na publicznym profilu okolice domu NIE są klikalne — §27, dokładnie jak na kaflu i w feedzie');
    } finally {
        RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $wlasciciel);
    }
});

// ---------------------------------------------------------------------------
// EKRAN WYNIKU JAZDY W APCE (§11 audytu UX 2026-08-28). SoloRideController::
// upload() kończy się w exit; po header() — nie do odpalenia wprost w procesie
// testów (ubiłoby cały przebieg), więc bramka APP_IS_APP i przekazanie danych
// przez sesję są sprawdzane statycznie na źródle, wzorem testu paska w
// widoki_test.php („apka: pasek renderuje się WYŁĄCZNIE w trybie aplikacji").
// Sam redirect+render weryfikowany żywym curl-em (patrz notatka w pamięci
// projektu), nie tutaj.
// ---------------------------------------------------------------------------

t_test('apka: sukces/duplikat solo przejazdu ląduje w sesji i wraca ekranem wyniku, nie query stringiem', function () {
    $src = (string) file_get_contents(CORE_PATH . '/Controllers/SoloRideController.php');

    t_true(
        (bool) preg_match('/if\s*\(\s*APP_IS_APP\s*\)\s*\{.{0,400}\$_SESSION\[.ride_summary.\]/s', $src),
        'gałąź APP_IS_APP zapisuje wynik do sesji'
    );
    t_true(
        str_contains($src, "View::url('/admin/moje-przejazdy/podsumowanie')"),
        'i przekierowuje na dedykowany ekran wyniku, nie na listę z query stringiem'
    );
    t_true(
        (bool) preg_match('/function\s+summary\s*\(.*?unset\(\$_SESSION\[.ride_summary.\]\)/s', $src),
        'summary() konsumuje sesję jednorazowo — odświeżenie nie pokaże starych liczb drugi raz'
    );
});

t_test('apka: przycisk nagrywania NIGDY nie nawiguje — informuje i przełącza, nic poza tym', function () {
    // Decyzja usera 2026-08-29: „powinien informować o tym, czy się nagrywa,
    // czy nie, lub możliwość zatrzymania lub rozpoczęcia i nic poza tym.
    // Nie chcę, aby odwoływał się do jakichś uploadów".
    //
    // Wcześniej świadomy stop przerzucał na ekran wyniku, a gdy ten nie miał
    // czego pokazać — dalej, na listę wgrywania GPX. Ten test pilnuje, żeby
    // żadna droga z tego pliku nie kończyła się przejęciem ekranu.
    $src = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');

    t_false(str_contains($src, 'window.location.href'), 'plik nagrywania nie przenosi nigdzie ekranu');
    t_false(str_contains($src, 'goToSummary'), 'nie ma już skoku na ekran wyniku');
    t_false(str_contains($src, 'RM_SUMMARY_URL'), 'i nie trzyma adresu, pod który miałby skakać');

    // Wynik przejazdu nie znika — przychodzi POWIADOMIENIEM, które można
    // zignorować albo dotknąć. Wybór należy do człowieka, nie do przycisku.
    t_true(
        (bool) preg_match("/addEventListener\('rm:ride-saved'.{0,900}native\.notify\(/s", $src),
        'zapisany przejazd zgłasza się powiadomieniem, nie przejęciem ekranu'
    );
    t_true(
        (bool) preg_match("/url: '\/admin\/moje-przejazdy\/podsumowanie'/", $src),
        'a powiadomienie prowadzi do podsumowania dopiero po dotknięciu'
    );

    // Zatrzymanie ma jedną postać — auto-stop i świadomy stop robią to samo,
    // więc dawna flaga `manual` nie ma już czego rozstrzygać.
    t_false((bool) preg_match('/function stopSession\(manual\)/', $src), 'stopSession bez flagi trybu');
});

// ---------------------------------------------------------------------------
// ŚLAD NAGRANY W APCE (zgłoszenie usera 2026-08-29: „przejechałem 5 km, nie
// odsłoniło ani jednego hexa"). Powód: GPX składany w `app-tracking.js` nie
// deklarował przestrzeni nazw, więc `Gpx::parse` nie widział w nim ANI JEDNEGO
// punktu i odrzucał plik — a apka czytała przekierowanie na stronę z `?blad=`
// jako sukces i kasowała bufor z nagraniem.
//
// Test buduje plik z NAGŁÓWKA WZIĘTEGO WPROST Z `app-tracking.js`, żeby pilnował
// tego, co apka naprawdę wysyła, a nie kopii z testu.
// ---------------------------------------------------------------------------

/** Dokument GPX dokładnie w kształcie, w jakim składa go apka. */
function t_apka_gpx(array $punkty): string
{
    $src = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');
    if (!preg_match('/<gpx version[^>]*>/', $src, $m)) {
        t_fail('W app-tracking.js nie ma znacznika <gpx ...> — zmienił się kształt toGpx()?');
    }

    $trkpts = '';
    foreach ($punkty as $i => $p) {
        $czas = gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-08-29 08:00:00 UTC') + $i * 20);
        $trkpts .= '<trkpt lat="' . $p['lat'] . '" lon="' . $p['lon'] . '"><time>' . $czas . '</time></trkpt>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . $m[0]
        . '<trk><name>Nagranie w tle</name><trkseg>' . $trkpts . '</trkseg></trk></gpx>';
}

/** Prosty ślad ~5 km na północ — tyle, ile przejechał user w zgłoszeniu. */
function t_apka_punkty(): array
{
    $punkty = [];
    for ($i = 0; $i < 46; $i++) {
        // 0.001 stopnia szerokości ≈ 111 m, czyli ~5 km na 45 odcinkach.
        $punkty[] = ['lat' => 53.4200 + $i * 0.001, 'lon' => 16.5300];
    }
    return $punkty;
}

t_test('apka: GPX złożony w apce ma przestrzeń nazw i Gpx::parse widzi w nim punkty', function () {
    $plik = tempnam(sys_get_temp_dir(), 'rm_apka_') . '.gpx';
    file_put_contents($plik, t_apka_gpx(t_apka_punkty()));

    try {
        $parsed = Utils\Gpx::parse($plik);
    } finally {
        @unlink($plik);
    }

    t_eq(46, $parsed['pointCount'], 'parser widzi wszystkie punkty nagrania');
    t_true($parsed['distanceKm'] > 4.5 && $parsed['distanceKm'] < 5.5, 'i liczy z nich ~5 km');
    t_not_null($parsed['startedAt'], 'znaczniki czasu z nagrania dochodzą do parsera');
});

t_test('apka: nagranie 5 km odsłania pola — cały tor zapisu, nie sam parser', function () {
    $plik = tempnam(sys_get_temp_dir(), 'rm_apka_') . '.gpx';
    file_put_contents($plik, t_apka_gpx(t_apka_punkty()));

    try {
        $wynik = RiderActivity::recordSolo(t_user(0), $plik, '/assets/uploads/gpx/nagranie-test.gpx');
    } finally {
        @unlink($plik);
    }

    t_not_null($wynik, 'przejazd zapisany (null = zero pól po przycięciu okolic domu albo duplikat)');
    t_true($wynik['cellsTouched'] > 0, 'ślad dotyka pól siatki — o to chodzi w całej funkcji');
    t_true($wynik['distanceKm'] > 4.5, 'i zalicza przejechane kilometry');
});

t_test('apka: odrzucony ślad NIE wygląda jak policzony — wysyłka rozróżnia trzy wyniki', function () {
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');
    $php = (string) file_get_contents(CORE_PATH . '/Controllers/SoloRideController.php');

    t_true(
        (bool) preg_match("/headers:\s*\{\s*'Accept':\s*'application\/json'/", $js),
        'apka prosi o JSON — po przekierowaniu PRG nie da się odróżnić błędu od sukcesu'
    );
    t_true(
        (bool) preg_match("/if\s*\(\s*wynik\s*===\s*'siec'\s*\)\s*\{\s*return;/", $js),
        'błąd sieci zostawia bufor — nagranie ma przeżyć brak zasięgu'
    );
    t_true(
        (bool) preg_match("/wynik\s*===\s*'odrzucony'.{0,60}zglosOdrzucenie\(\)/s", $js),
        'odrzucony ślad mówi o tym człowiekowi, zamiast zniknąć po cichu'
    );

    t_true(
        (bool) preg_match('/private static function fail\(string \$kod\).{0,200}wantsJson\(\).{0,120}ok.{0,20}false/s', $php),
        'serwer oddaje apce {ok:false} zamiast strony z ?blad='
    );
    // Kolejność, nie sąsiedztwo: między jednym a drugim stoi cały ładunek
    // ekranu wyniku, więc regex z limitem odległości pilnowałby długości bloku,
    // a nie tego, o co chodzi.
    $sesja = strpos($php, "\$_SESSION['ride_summary'] =");
    $json  = strpos($php, 'if (self::wantsJson()) {');
    t_true(
        $sesja !== false && $json !== false && $sesja < $json,
        'JSON idzie PO ustawieniu sesji — inaczej fetch skonsumowałby ekran wyniku przed userem'
    );
});

t_test('apka: przejazd trwa do „Zatrzymaj" — nawigacja i zamknięcie apki go nie tną', function () {
    // Decyzja usera 2026-08-29. Apka chodzi na `server.url`, więc każde
    // przejście między ekranami przeładowuje stronę i uruchamia ten plik
    // od nowa. Dotąd znaczyło to: wyślij bufor jako OSOBNY przejazd i zacznij
    // nowy watcher (starego nie było już czym zdjąć). Jeden przejazd rozpadał
    // się na kawałki, a każdy kawałek tracił po 400 m z obu końców.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');

    t_true(
        (bool) preg_match('/K_SESSION.{0,200}watcherId/s', $js),
        'stan trwającego przejazdu (z id watchera) leży w Preferences, nie tylko w pamięci strony'
    );
    t_true(
        (bool) preg_match('/sesja\.watcherId.{0,120}stopTracking\(sesja\.watcherId\)/s', $js),
        'osierocony watcher jest zdejmowany przy starcie — inaczej każda nawigacja dokłada kolejny'
    );
    t_true(
        (bool) preg_match('/STALE_MS.{0,200}recoverLeftoverBuffer/s', $js),
        'ślad bez nowych punktów dłużej niż próg zostaje zamknięty i wysłany'
    );
    t_true(
        (bool) preg_match('/buffer = pkt.{0,200}wlaczNagrywanie\(true\)/s', $js),
        'świeży ślad jest KONTYNUOWANY (bufor wczytany), a nie wysłany w kawałku'
    );
    t_true(
        (bool) preg_match('/if \(!kontynuacja\) \{\s*buffer = \[\];/s', $js),
        'kontynuacja nie zeruje bufora — to jest cała różnica między jednym śladem a kilkoma'
    );
    // Flaga `sessionActive` zastąpiona stanem `STAN` (2026-08-29) — warunek
    // znaczy dokładnie to samo: nie ruszaj bufora, jeśli przejazd trwa.
    t_true(
        (bool) preg_match("/isActive && STAN === 'off'/", $js),
        'powrót na pierwszy plan nie wysyła bufora trwającego przejazdu'
    );
    // Pułapka na przyszłość: `.then(startSession)` wpycha wynik poprzedniej
    // obietnicy w pierwszy parametr, czyli w `kontynuacja`. Szukamy w SAMYM
    // KODZIE — komentarz obok tej funkcji cytuje ten zapis, żeby nikt go nie
    // wprowadził z powrotem, i inaczej sam by ten test wywracał.
    $kod = (string) preg_replace('#/\*.*?\*/|//[^
]*#s', '', $js);
    t_false(
        str_contains($kod, '.then(startSession)'),
        'startSession nigdy nie jest podpinane wprost do .then() — dostałoby przypadkowy argument'
    );
});

t_test('apka: postój nie nabija punktów, jazda zachowuje kształt śladu', function () {
    // Pytanie usera 2026-08-29: „nie potrzebuję pobierać lokalizacji, jeśli stoję
    // w miejscu i walić w miejscu 1000 punktów". `distanceFilter` wtyczki (15 m)
    // tego nie załatwia: liczy od POPRZEDNIEGO ODCZYTU, więc szum skaczący
    // ±16 m wokół jednego miejsca przechodzi przez niego za każdym razem.
    // Zmierzone symulacją: 40 odczytów szumu = 40 punktów w śladzie przed
    // zmianą, 0 po. Trzydzieści punktów jazdy co 20 m przechodzi w komplecie.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');

    t_true(
        (bool) preg_match('/p\.accuracy > MAX_ACC_M.{0,20}return;/s', $js),
        'odczyt o kiepskiej dokładności jest odrzucany w całości — to nie jest pozycja'
    );
    t_true(
        (bool) preg_match('/function progRuchu\(p\).{0,400}STOI_MAX_MS.{0,80}MOVE_MIN_STOJAC_M : MOVE_MIN_M/s', $js),
        'próg przesunięcia zależy od PRĘDKOŚCI: inny w jeździe, inny na postoju'
    );
    // Dystans MUSI być liczony od ostatniego ZAPISANEGO punktu. Od ostatniego
    // widzianego dryf po 9 m sumowałby się w nieskończoność i nigdy nie
    // przekroczył progu — ślad pełzłby przez pół osiedla, stojąc.
    t_true(
        (bool) preg_match('/var ostatni = buffer\.length \? buffer\[buffer\.length - 1\] : null;\s*(\/\/[^
]*
\s*)*if \(ostatni && haversineM\(ostatni, p\) < progRuchu\(p\)\)/s', $js),
        'próg mierzony od ostatniego zapisanego punktu, nie od ostatniego widzianego'
    );
    t_true(
        (bool) preg_match('/MOVE_MIN_STOJAC_M\s*=\s*40/', $js) && (bool) preg_match('/MOVE_MIN_M\s*=\s*10/', $js),
        'próg postoju wyraźnie wyższy niż próg jazdy'
    );
});

t_test('apka: JEDEN klient lokalizacji naraz — mapa nie zamawia drugiego GPS-u', function () {
    // Zgłoszenie usera 2026-08-29: „włączyłem apkę i po 40 min z pełnej baterii
    // zrobiło się 0". Ekran mapy otwierał WŁASNY `watchPosition` z
    // `highAccuracy:true` i `maximumAge:0` — bezwarunkowo i bez zamykania.
    // Android scala żądania lokalizacji i obsługuje NAJBARDZIEJ WYMAGAJĄCE,
    // więc ten jeden nasłuch podnosił odbiornik do pełnej częstości niezależnie
    // od tego, jak oszczędnie ustawione było nagrywanie w tle.
    $mapa = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery-app.php');
    $js   = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');

    // Nagrywanie rozgłasza pozycje — mapa ma je dostawać za darmo.
    t_true(
        (bool) preg_match("/dispatchEvent\(new CustomEvent\('rm:position'/", $js),
        'nagrywanie rozgłasza pozycje, zamiast kazać mapie pytać GPS drugi raz'
    );
    t_true(
        (bool) preg_match("/dispatchEvent\(new CustomEvent\('rm:tracking'/", $js),
        'i rozgłasza swój stan, żeby mapa wiedziała, czy może na nie liczyć'
    );

    // Mapa: własny nasłuch TYLKO gdy nikt inny nie trzyma odbiornika.
    t_true(
        (bool) preg_match('/if \(watchId !== null \|\| nagrywaniemTrzyma \|\| document\.hidden\) \{ return; \}/', $mapa),
        'mapa nie otwiera nasłuchu, gdy nagrywanie już trzyma GPS albo ekranu nie widać'
    );
    t_true(
        (bool) preg_match("/addEventListener\('visibilitychange'.{0,160}stopWlasnegoNasluchu\(\)/s", $mapa),
        'zejście ekranu z oczu zamyka nasłuch — inaczej GPS pracuje, nie rysując nic'
    );
    t_true(
        (bool) preg_match('/RM\.native\.clearWatch\(watchId\)/', $mapa),
        'nasłuch da się w ogóle zamknąć (wcześniej nie było ani jednego clearWatch)'
    );
});

t_test('apka: stan nagrywania ma JEDNO źródło prawdy i nie kłamie po nawigacji', function () {
    // Zgłoszenie usera 2026-08-29: „dostałem powiadomienie, że Ridemore nagrywa
    // przejazd, wchodzę w mapę — mam »Nagraj«, klikam i przenosi mnie do
    // uploadu GPX". Jedna przyczyna, trzy objawy: stan żył w usłudze natywnej,
    // w Preferences i w pamięci JS strony, a pamięć JS ginie przy KAŻDEJ
    // nawigacji. Wtyczka tła nie ma czym odpowiedzieć „czy nagrywam" (tylko
    // addWatcher/removeWatcher/openSettings), więc jedynym źródłem prawdy,
    // które przeżywa stronę, jest znacznik w Preferences.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');

    // 1. NIC NIE MALUJEMY, ZANIM NIE WIEMY. `renderStan('off')` nie może stać
    //    przed odczytem znacznika — to był ten „Nagraj" w środku przejazdu.
    t_false(
        (bool) preg_match("/renderStan\('off'\);\s*wznowSesje\(\)/", $js),
        'stan nie jest malowany przed odczytem Preferences'
    );
    // 2. Świeży znacznik = usługa pracuje → „Nagrywam" NATYCHMIAST.
    t_true(
        (bool) preg_match("/renderStan\('on'\);\s*showPill\(\);\s*return prefGet\(K_BUFFER\)/s", $js),
        'świeży znacznik sesji maluje „Nagrywam" przed czymkolwiek asynchronicznym'
    );
    // 3. Stan przejściowy istnieje, ale NIE JEST PUŁAPKĄ (poprawka 2026-08-30,
    //    zgłoszenie: „dostaję powiadomienie, że rozpoczyna, ale status się nie
    //    zmienia — cały czas mam rozpoczynanie"). Do tej pory dotknięcie
    //    w „startuje" było ignorowane, więc gdy start nie doszedł do skutku,
    //    przycisk milkł na dobre. Dziś ANULUJE próbę i wraca na „Nagraj".
    t_true(str_contains($js, "STAN = 'off';   // 'off' | 'startuje' | 'on' | 'blad'"), 'stan jest czterowartościowy');
    t_true(
        (bool) preg_match("/if \(STAN === 'startuje'\) \{.{0,700}renderStan\('off'\);\s*return;\s*\}/s", $js),
        'dotknięcie w trakcie startu ANULUJE próbę — stan przejściowy ma wyjście'
    );
    t_true(
        (bool) preg_match("/if \(STAN === 'on'\) \{ stopSession\(\)/s", $js),
        'dotknięcie przy włączonym nagrywaniu zatrzymuje'
    );
    // 3b. STAN PRZEJŚCIOWY MA TERMIN. Bez niego jedynym wyjściem w górę była
    //     obietnica mostu Capacitora — a gdy ta nie wróciła, chip zostawał na
    //     „Włączam…", podczas gdy usługa natywna nagrywała z powiadomieniem.
    t_true(
        (bool) preg_match("/setTimeout\(function \(\) \{.{0,600}STAN !== 'startuje'.{0,600}renderStan\('blad'\);\s*\}, START_TIMEOUT_MS\)/s", $js),
        'stan „startuje" ma termin, po którym sam się rozstrzyga'
    );
    // 3c. DOWÓD BIJE OBIETNICĘ: pierwsza pozycja z callbacka kończy „Włączam…"
    //     niezależnie od tego, czy most kiedykolwiek odpowiedział.
    t_true(
        (bool) preg_match("/function onPosition\(p\) \{.{0,800}if \(STAN === 'startuje'\) \{\s*potwierdzStart\(\);/s", $js),
        'przychodząca pozycja sama potwierdza start — UI nie wisi na obietnicy mostu'
    );
    // 3d. ZGODA NA POWIADOMIENIA NIE BLOKUJE NAGRYWANIA — jej odrzucenie
    //     zrywało wcześniej cały łańcuch i start nie następował w ciszy.
    t_true(
        (bool) preg_match("/zgoda\.catch\(function \(\) \{\}\)\.then\(function \(\) \{\s*startSession/s", $js),
        'odmowa zgody na powiadomienia nie przerywa startu nagrywania'
    );
    // 3e. KONTYNUACJA PO NAWIGACJI MUSI PODPIĄĆ NOWY WATCHER. Warunek na
    //     namalowanym stanie (`trwa()`) blokował ją całkowicie: `wznowSesje`
    //     zdejmowało osierocony watcher i nie stawiało nowego, więc pierwsze
    //     przejście na inny ekran cicho kończyło zbieranie pozycji.
    t_true(
        (bool) preg_match('/function startSession\(kontynuacja\) \{.{0,900}if \(watcherId\) \{ return; \}/s', $js),
        'ponowny start blokuje ISTNIEJĄCY watcher, a nie namalowany stan'
    );
    // 4. Zatrzymanie działa ze strony, która nie zna id watchera.
    t_true(
        (bool) preg_match('/function zdejmijWatcher\(\).{0,700}prefGet\(K_SESSION\).{0,300}stopTracking\(sesja\.watcherId\)/s', $js),
        'stop bierze id watchera z Preferences, gdy pamięć strony go nie ma'
    );
    // 5. Ekran wyniku tylko wtedy, gdy naprawdę powstał przejazd.
    t_true(
        (bool) preg_match("/points\.length < 2\) \{ return Promise\.resolve\('pusto'\)/", $js),
        'puste nagranie ma własny wynik, odróżnialny od sukcesu'
    );
    // 6. Zapis faktu PRZED rysowaniem — wyjątek w UI nie może skasować sesji.
    //    Od 2026-08-30 obie drogi wyjścia w górę (id watchera i pierwsza
    //    pozycja) schodzą się w `potwierdzStart()`, więc kolejności pilnujemy
    //    tam, w jednym miejscu.
    t_true(
        (bool) preg_match('/function potwierdzStart\(\) \{.{0,900}zapiszSesje\(\);\s*renderStan\(.on.\);.{0,300}try \{ showPill\(\); \} catch/s', $js),
        'najpierw zapisany fakt, potem rysowanie — i rysowanie nie wywraca startu'
    );
    t_true(
        (bool) preg_match('/watcherId = id;\s*potwierdzStart\(\);/s', $js),
        'id watchera z mostu prowadzi do tego samego potwierdzenia co pozycja'
    );
});

// ---------------------------------------------------------------------------
// CO SIĘ DZIEJE, GDY JADĘ I STAJĘ (2026-08-30, pytania usera: „zapisze to jako
// solo czy jakoś inaczej?", „czy pierwszy ruch w hexie odsłania go, czy musi
// być ich więcej?"). Testy jadą na helperach z sekcji wyżej — `t_apka_gpx()`
// bierze kształt pliku WPROST z `app-tracking.js`, więc pilnują tego, co apka
// naprawdę wysyła.
// ---------------------------------------------------------------------------

/** Zapisuje nagranie apki do pliku tymczasowego i puszcza je drogą uploadu. */
function t_apka_nagranie(int $userId, array $punkty): ?array
{
    $plik = tempnam(sys_get_temp_dir(), 'rm_apka_') . '.gpx';
    file_put_contents($plik, t_apka_gpx($punkty));
    try {
        return RiderActivity::recordSolo($userId, $plik, '/assets/uploads/gpx/' . basename($plik));
    } finally {
        @unlink($plik);
    }
}

/** Odległość w metrach między dwoma punktami {lat,lon} — haversine. */
function t_metry(array $a, array $b): float
{
    $r = 6371000.0;
    $dLat = deg2rad($b['lat'] - $a['lat']);
    $dLon = deg2rad($b['lon'] - $a['lon']);
    $h = sin($dLat / 2) ** 2
       + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($h), sqrt(1 - $h));
}

/** Linia prosta na wschód: `$ile` punktów co `$krokM` metrów. */
function t_apka_linia(float $lat, float $lon, int $ile, float $krokM): array
{
    $stopienM = 111320.0 * cos(deg2rad($lat));
    $punkty = [];
    for ($i = 0; $i < $ile; $i++) {
        $punkty[] = ['lat' => $lat, 'lon' => round($lon + ($i * $krokM) / $stopienM, 7)];
    }
    return $punkty;
}

t_test('apka: nagranie bez wczytanej trasy ląduje jako SOLO, bez wydarzenia', function () {
    // Wprost odpowiedź na pytanie „zapisze jako solo czy jakoś inaczej":
    // źródło to `solo`, wydarzenia nie ma, bo apka nie ma czym go wskazać.
    [$u] = t_users(1);
    $wynik = t_apka_nagranie($u, t_apka_linia(52.2300, 21.0100, 240, 25.0)); // ~6 km

    t_not_null($wynik, 'nagranie z apki daje przejazd');
    $lista = RiderActivity::soloForUser($u);
    t_eq(1, count($lista), 'przejazd wychodzi na liście „Przejazdy solo"');
    t_true(empty($lista[0]['event_id']), 'nagranie bez trasy NIE jest podpinane pod żadne wydarzenie');
    t_true((int) $wynik['cellsNew'] > 0, 'i odsłania pola siatki');
});

t_test('apka: JEDEN punkt w polu je odsłania — nie trzeba w nim postać', function () {
    // Odpowiedź na „czy pierwszy ruch w hexie odsłania go, czy musi być ich
    // więcej": pole liczy się od pierwszego odczytu, który w nie trafi. Nie ma
    // progu czasu ani liczby punktów.
    $jeden = \Utils\DiscoveryGrid::cellsForTrack([['lat' => 52.2300, 'lon' => 21.0100]]);
    t_eq(1, count($jeden), 'pojedynczy punkt odsłania dokładnie jedno pole');

    // I więcej: pola MIĘDZY dwoma odczytami też się liczą — odcinek jest
    // dogęszczany, więc pole przejechane bez ani jednego odczytu w środku
    // (przy distanceFilter 25 m to codzienność) nie przepada.
    $odcinek = \Utils\DiscoveryGrid::cellsForTrack([
        ['lat' => 52.2300, 'lon' => 21.0100],
        ['lat' => 52.2300, 'lon' => 21.0400],
    ]);
    t_true(count($odcinek) > 2, 'pola pomiędzy dwoma odczytami też się odsłaniają');
});

t_test('§27: przycięcie końców bierze promień Z KONFIGURACJI, nie ze stałej w kodzie', function () {
    // Promień zmieniał się już (400 m → 50 m, 2026-08-30), więc test pyta
    // o ZASADĘ, a nie o liczbę: punkty bliżej niż `home_trim_radius_m` od
    // pierwszego i ostatniego punktu śladu mają wypaść PRZED liczeniem pól.
    $promien = \Models\DiscoveryScoring::homeTrimRadiusM();
    t_true($promien > 0, 'ochrona okolic startu/mety jest w ogóle włączona');

    $pelne = t_apka_linia(52.2300, 21.0100, 200, 25.0); // 5 km, punkt co 25 m
    $ciete = \Utils\DiscoveryGrid::trimEnds($pelne, $promien);

    t_true(count($ciete) > 0, 'ślad dłuższy niż dwa promienie coś zostawia');
    t_true(count($ciete) < count($pelne), 'i faktycznie coś ucina');

    $pierwszy = $pelne[0];
    $ostatni  = $pelne[count($pelne) - 1];
    $wPromieniu = 0;
    foreach ($ciete as $p) {
        if (t_metry($p, $pierwszy) <= $promien || t_metry($p, $ostatni) <= $promien) {
            $wPromieniu++;
        }
    }
    t_eq(0, $wPromieniu, 'żaden zachowany punkt nie leży w promieniu domowym od końców śladu');
});

t_test('§27: skarb NA MECIE przejazdu zalicza się mimo przycięcia', function () {
    // Zgłoszenie usera 2026-08-30: „punkt końcowy tam, gdzie skarb, też nie
    // zaliczony hex". Przy 400 m było to nieuniknione — końcówka śladu wypadała
    // razem z punktami, po których liczy się `Treasure::claimAlongTrack`,
    // a promień zaliczenia skarbu to zwykle 150 m.
    //
    // Test wiąże DWIE liczby z DWÓCH konfiguracji: dopóki promień domowy jest
    // mniejszy od promienia zaliczenia skarbu, dojazd pod skarb działa.
    // Podniesienie `home_trim_radius_m` powyżej `claim_radius_m` wywali ten
    // test — i o to chodzi.
    $promien = \Models\DiscoveryScoring::homeTrimRadiusM();
    $claim   = (int) (\Models\DiscoveryScoring::config()['treasures']['default_radius_m'] ?? 150);

    t_true($promien < $claim, "promień domowy ({$promien} m) mniejszy niż promień zaliczenia skarbu ({$claim} m)");

    $pelne = t_apka_linia(52.2300, 21.0100, 200, 25.0);
    $ciete = \Utils\DiscoveryGrid::trimEnds($pelne, $promien);
    $meta  = $pelne[count($pelne) - 1];
    $najblizszy = min(array_map(static fn(array $p): float => t_metry($p, $meta), $ciete));

    t_true(
        $najblizszy < $claim,
        'po przycięciu zostaje punkt w zasięgu zaliczenia skarbu stojącego na mecie'
    );

    // To samo dla pola siatki: hex, w którym kończysz jazdę, ma się odsłonić.
    $polaCiete = \Utils\DiscoveryGrid::cellsForTrack($ciete);
    $poleMety  = \Utils\DiscoveryGrid::cellsForTrack([$meta]);
    t_true(
        in_array($poleMety[0], $polaCiete, true),
        'pole, w którym kończy się przejazd, jest wśród odkrytych'
    );
});

t_test('apka: nagranie krótsze niż 100 m jest ODRZUCONE, a nie po cichu policzone', function () {
    // `Gpx::parse` rzuca „Trasa zbyt krótka (< 100 m)", kontroler zamienia to
    // na `{ok:false}`, a apka na powiadomienie „Nagranie nie zostało
    // policzone" (`zglosOdrzucenie`) — czyli TU user dostaje odpowiedź.
    [$u] = t_users(1);
    $blad = null;
    try {
        t_apka_nagranie($u, t_apka_linia(52.2300, 21.0100, 3, 25.0)); // ~50 m
    } catch (\RuntimeException $e) {
        $blad = $e->getMessage();
    }
    t_true($blad !== null && str_contains((string) $blad, 'zbyt krótka'), 'ślad poniżej 100 m nie przechodzi parsera');
});

// ---------------------------------------------------------------------------
// STRONA PRZEJAZDU — /przejazd/{id} (2026-09-03,
// tasks/done/strona-przejazdu.md).
//
// Strona jest PUBLICZNA, więc testy pilnują dokładnie tej granicy, na której
// publiczność spotyka się z §27: co dostaje obcy. Reszta strony to złożenie
// zapytań, które mają już swoje testy w innych miejscach.
// ---------------------------------------------------------------------------

t_test('strona przejazdu: obcy dostaje geometrię PRZYCIĘTĄ, właściciel pełną', function () {
    // To jest cała mechanika prywatności tej strony: ten sam plik, dwie różne
    // odpowiedzi. Gdyby `packedTrimmedForFile` sięgnęła po pełną geometrię,
    // publiczna strona pokazywałaby początek pod czyimś domem — a nikt by tego
    // nie zauważył, bo obie odpowiedzi wyglądają tak samo (ta sama linia).
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    t_true($wynik !== null, 'przejazd testowy się zapisał');

    $sciezka = CORE_PATH . '/..' . $kopiaUrl;
    $pelna = Models\GpxGeometry::packedForFile($sciezka);
    $cieta = Models\GpxGeometry::packedTrimmedForFile($sciezka);

    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $userId);

    t_not_null($pelna, 'pełna geometria jest');
    t_not_null($cieta, 'przycięta geometria też — recordSolo liczy ją przy zapisie');
    t_true($cieta['n'] > 0, 'przycięty ślad nie jest pusty (zostaje środek trasy)');
    t_true($cieta['n'] < $pelna['n'],
        'przycięty ma MNIEJ punktów niż pełny (' . $cieta['n'] . ' < ' . $pelna['n'] . ')');
    t_true($cieta['pts'] !== $pelna['pts'], 'to nie jest ta sama linia oddana dwa razy');
});

t_test('strona przejazdu: kadr obcego liczy się z przyciętej geometrii, nie z pełnej', function () {
    // Sam PROSTOKĄT też jest informacją: gdyby liczył się z pełnego śladu,
    // wskazywałby dom, mimo że linii już tam nie ma.
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    $hash = Models\GpxGeometry::ensure(CORE_PATH . '/..' . $kopiaUrl);

    $pelny = Models\GpxGeometry::boundsFor([$hash]);
    $ciety = Models\GpxGeometry::boundsForTrimmed([$hash]);

    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $userId);

    t_not_null($ciety, 'przycięty kadr istnieje');
    t_true(
        $ciety['north'] <= $pelny['north'] && $ciety['south'] >= $pelny['south']
        && $ciety['east'] <= $pelny['east'] && $ciety['west'] >= $pelny['west'],
        'przycięty prostokąt mieści się w pełnym — nie może być od niego większy'
    );
});

t_test('strona przejazdu: ukryty rowerzysta nie pokazuje przejazdu nikomu obcemu', function () {
    // NAJWAŻNIEJSZY TEST TEJ STRONY. `roster_visible = 0` znaczy „nie ma mnie
    // na listach" i musi znaczyć to samo tutaj — inaczej adres /przejazd/{id}
    // byłby obejściem ukrycia profilu.
    [$a] = t_users(1);
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($a, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    $ride = RiderActivity::findForPage((int) $wynik['activityId']);

    $stmt = Database::connection()->prepare('UPDATE users SET roster_visible = 0 WHERE id = :id');
    $stmt->execute(['id' => $a]);

    $sciezkaObcego = Controllers\Support::strangerTrackPath($ride);
    $publiczny = Controllers\Support::visibleRiderById($a);

    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $a);

    t_null($publiczny, 'ukryty rowerzysta nie jest publiczny');
    t_null($sciezkaObcego, 'obcy nie dostaje adresu śladu ukrytej osoby');
});

t_test('strona przejazdu: reguła „kto jest publiczny" ma JEDNO źródło', function () {
    // `visibleRiderById` nie może być drugą definicją tej reguły — dociąga
    // konto i pyta `visibleRider()`. Test pilnuje, żeby ktoś nie „uprościł" jej
    // kiedyś do własnego zapytania, które rozjedzie się przy zmianie zasad.
    [$a] = t_users(1);
    $user = Models\User::find($a);
    t_not_null($user, 'użytkownik testowy istnieje');

    t_same(
        Controllers\Support::visibleRider($user->publicSlug)?->id,
        Controllers\Support::visibleRiderById($a)?->id,
        'obie drogi dają tę samą odpowiedź'
    );
});

// --- Profil publiczny NIE wymaga żadnej historii (zgłoszenie 2026-09-10) ---
//
// Do 2026-09-10 Support::visibleRider() miała trzeci warunek: co najmniej
// jeden potwierdzony przejazd, turnusowy albo solo. Zgłoszenie usera:
// „rowerzysta się rejestruje, nigdzie nie był, nigdzie jeszcze nie jechał,
// wchodzi na profil i co… dupa". Reguła zawężona do DWÓCH warunków — konto
// istnieje i jest widoczne na listach (`roster_visible`) — bez względu na
// to, czy kiedykolwiek cokolwiek przejechał.

/**
 * Świeże konto bez żadnej historii — lokalny odpowiednik `t_pusty_user()`
 * z uzytkownicy_test.php. Nie wołamy tamtej wprost: `php tests/run.php
 * przejazdy_solo` ładuje tylko pliki pasujące do wzorca, więc funkcja z
 * innego pliku testowego by tu nie istniała.
 */
function t_swiezy_user(): int
{
    $db = Database::connection();
    $db->prepare('INSERT INTO users (name, email, password_hash, email_verified_at, is_admin)
                  VALUES (:n, :e, :p, NOW(), 0)')
       ->execute([
           'n' => 'TEST konto',
           'e' => 'test-' . bin2hex(random_bytes(6)) . '@example.invalid',
           'p' => password_hash('x', PASSWORD_DEFAULT),
       ]);
    return (int) $db->lastInsertId();
}

t_test('Support::visibleRider: świeże konto bez żadnej historii JEST publiczne, jeśli widoczne na listach', function () {
    $id = t_swiezy_user();
    $slug = Models\User::ensurePublicSlug($id);

    $publiczny = Controllers\Support::visibleRider($slug);
    t_not_null($publiczny, 'zero wyjazdów i zero przejazdów solo — profil mimo to jest publiczny');
    t_eq($id, $publiczny->id, 'to dokładnie to konto');
});

t_test('Support::visibleRider: to samo świeże konto z roster_visible=0 zostaje niepubliczne', function () {
    $id = t_swiezy_user();
    $slug = Models\User::ensurePublicSlug($id);
    Database::connection()->prepare('UPDATE users SET roster_visible = 0 WHERE id = :id')->execute(['id' => $id]);

    t_null(Controllers\Support::visibleRider($slug), 'jedyna pozostała bramka nadal działa');
});

t_test('TileSource::tracks(u-{slug}): solo BEZ żadnego wyjazdu z RSVP wchodzi na publiczny profil, PRZYCIĘTE, PER ŚLAD KOLOROWANE (2026-09-10)', function () {
    // DOKŁADNIE TEN PROFIL zgłosił user na produkcji: rowerzysta jeżdżący
    // WYŁĄCZNIE solo (import z Garmina), zero zapisów na jakikolwiek wyjazd —
    // więc `EditionTrack::effectiveForUser()` (jedyne źródło grupy "real" na
    // `u-{slug}` SPRZED tej naprawy) wychodziło puste, a cała odpowiedź
    // `tracks()` dla tego profilu była `[]`. Hexy (`Discovery::
    // communityCells`, zupełnie inna ścieżka) tej luki nigdy nie miały —
    // stąd zgłoszenie „widzę odkryte hexy, ale jego śladów nie mam na mapie".
    //
    // DWA przejazdy solo, nie jeden — pierwsza wersja tej naprawy dawała
    // WSZYSTKIM solo jednego profilu WSPÓLNY kolor, user to odrzucił
    // („na swoim profilu każdy przejazd jest kolorowany… powinno być tak
    // samo"). `t_solo_gpx_kopia()` daje TĘ SAMĄ trasę pod INNYM hashem —
    // czyli dwa ślady w tym samym miejscu, które (ten sam dowód co
    // `kolory_sladow_test.php`: „dwa ślady w tym samym miejscu MUSZĄ wyjść
    // z dwoma różnymi kolorami") MUSZĄ dostać RÓŻNE kolory, jeśli
    // przydzielane są PER ŚLAD, a nie WSPÓLNIE.
    $id = t_swiezy_user();
    $slug = Models\User::ensurePublicSlug($id);

    // OBIE przez t_solo_gpx_kopia() — WŁASNE, jednorazowe kopie, NIGDY
    // surowa współdzielona ścieżka t_solo_gpx(): ten test kasuje przejazdy
    // (i ich pliki, RiderActivity::deleteSoloForUser) w finally niżej, a
    // usunięcie WSPÓLNEJ fixture z assets/uploads/gpx/ wysypałoby KAŻDY inny
    // test w tym pliku, który jej dalej potrzebuje.
    $urlA = t_solo_gpx_kopia();
    $sciezkaA = CORE_PATH . '/..' . $urlA;
    $urlB = t_solo_gpx_kopia();
    $sciezkaB = CORE_PATH . '/..' . $urlB;

    $a = RiderActivity::recordSolo($id, $sciezkaA, $urlA);
    $b = RiderActivity::recordSolo($id, $sciezkaB, $urlB);
    t_true($a !== null && $b !== null, 'oba testowe przejazdy solo się zapisały');

    try {
        $grupy = Models\TileSource::tracks('u-' . $slug);

        $trimmed = array_values(array_filter(
            $grupy,
            static fn(array $g): bool => ($g['source'] ?? 'normal') === 'trimmed'
        ));
        t_true($trimmed !== [], 'publiczny profil dostaje grupy śladów solo (source: trimmed) — bez naprawy ich tu w ogóle nie ma');

        $hashA = (string) Models\GpxGeometry::ensureTrimmed($sciezkaA);
        $hashB = (string) Models\GpxGeometry::ensureTrimmed($sciezkaB);

        $znalezioneHashe = array_merge(...array_map(static fn(array $g): array => $g['hashes'], $trimmed));
        t_true(in_array($hashA, $znalezioneHashe, true), 'pierwszy testowy przejazd solo jest w odpowiedzi');
        t_true(in_array($hashB, $znalezioneHashe, true), 'drugi testowy przejazd solo jest w odpowiedzi');

        // PO JEDNEJ GRUPIE NA ŚLAD, NIE JEDNEJ WSPÓLNEJ — to jest sedno
        // poprawki po odrzuceniu pierwszej wersji: gdyby oba hashe siedziały
        // w JEDNEJ grupie, nie dałoby się im przydzielić różnych kolorów
        // (grupa niesie JEDEN klucz `color`, nie po jednym na hash).
        $grupaA = current(array_filter($trimmed, static fn(array $g) => in_array($hashA, $g['hashes'], true)));
        $grupaB = current(array_filter($trimmed, static fn(array $g) => in_array($hashB, $g['hashes'], true)));
        t_true($grupaA !== false && $grupaB !== false, 'oba hashe faktycznie znalazły swoją grupę');
        t_true($grupaA !== $grupaB, 'to DWIE różne grupy, nie jedna wspólna');
        t_count(1, $grupaA['hashes'], 'grupa pierwszego śladu niesie WYŁĄCZNIE jego hash');
        t_count(1, $grupaB['hashes'], 'grupa drugiego śladu niesie WYŁĄCZNIE jego hash');

        // KOLOR: obecny w OBU grupach i RÓŻNY między nimi — ten sam mechanizm
        // co ślady z wyjazdów (GpxGeometry::assignColor po wspólnych kaflach).
        t_true(isset($grupaA['color'], $grupaB['color']), 'obie grupy niosą przydzielony kolor, nie spadają na kolor stylu');
        t_true($grupaA['color'] !== $grupaB['color'], 'dwa solo w tym samym miejscu dostały RÓŻNE kolory — nie jeden wspólny');

        // TEN SAM KOLOR CO NA `me` — dowód, że to NAPRAWDĘ `gpx_geometry.
        // color_index` (własność PLIKU), a nie osobna, niezależna wartość
        // wymyślona tylko dla tej warstwy.
        $kolorPelnyA = Models\GpxGeometry::colorsFor([$hashA])[$hashA] ?? null;
        t_not_null($kolorPelnyA, 'ensure() PEŁNE też przydzieliło kolor temu hashowi (efekt uboczny hashesFor() w tracks())');
        t_eq(Utils\TrackPalette::colorOf($kolorPelnyA), $grupaA['color'], 'kolor na publicznym profilu to DOKŁADNIE ten z gpx_geometry.color_index');

        // PRYWATNOŚĆ: to MUSI być geometria PRZYCIĘTA, nie surowa — ten sam
        // dowód (mniej punktów) co test „strona przejazdu: obcy dostaje
        // geometrię PRZYCIĘTĄ" wyżej w tym pliku. `u-{slug}` leży na dysku
        // pod adresem do zgadnięcia, więc surowy ślad (zaczynający się pod
        // domem, §27) nie ma tu prawa się znaleźć.
        $pelna = Models\GpxGeometry::packedForFile($sciezkaA);
        $cieta = Models\GpxGeometry::packedTrimmedForFile($sciezkaA);
        t_true($cieta['n'] < $pelna['n'], 'geometria wystawiona publicznie ma MNIEJ punktów niż pełna (okolice domu wycięte)');
    } finally {
        RiderActivity::deleteSoloForUser((int) $a['activityId'], $id);
        RiderActivity::deleteSoloForUser((int) $b['activityId'], $id);
    }
});

t_test('pobranie dla obcego: plik złożony z przyciętych punktów daje się sparsować', function () {
    // Obcy dostaje GPX ZBUDOWANY z geometrii, nie kopię pliku — więc musi to
    // być poprawny GPX, a nie „prawie". Sprawdzamy tą samą drogą, którą serwis
    // czyta wgrywane pliki.
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    $hash = Models\GpxGeometry::ensureTrimmed(CORE_PATH . '/..' . $kopiaUrl);
    $geom = Models\GpxGeometry::loadTrimmed([$hash])[$hash] ?? null;
    t_not_null($geom, 'przycięta geometria jest');

    $punkty = [];
    for ($i = 0, $len = count($geom['pts']); $i + 1 < $len; $i += 2) {
        [$lat, $lon] = Utils\TileGrid::toLatLon($geom['pts'][$i], $geom['pts'][$i + 1]);
        $punkty[] = ['lat' => $lat, 'lon' => $lon];
    }

    $plik = sys_get_temp_dir() . '/ridemore-test-' . uniqid() . '.gpx';
    file_put_contents($plik, Utils\Gpx::fromPoints($punkty, 'Przejazd testowy'));
    $sparsowany = Utils\Gpx::parse($plik, Utils\Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
    @unlink($plik);
    RiderActivity::deleteSoloForUser((int) $wynik['activityId'], $userId);

    // NIE „tyle samo punktów, ile zapisaliśmy": zapisana geometria leży
    // w PIKSELACH siatki (STORE_Z), więc dwa sąsiednie punkty GPS potrafią
    // wypaść w tym samym pikselu i wrócić jako ta sama współrzędna —
    // a `Gpx::parse` usuwa powtórzenia (`removeDuplicates`). Zmierzone:
    // 15 348 zapisanych → 15 286 po sparsowaniu, czyli 0,4% różnicy.
    t_true(
        $sparsowany['pointCount'] > count($punkty) * 0.95,
        'parser widzi praktycznie wszystkie punkty ('
            . $sparsowany['pointCount'] . ' z ' . count($punkty) . ')'
    );
    t_false($sparsowany['hasTimestamps'], 'bez znaczników czasu — przycięta geometria ich nie ma');
    t_true($sparsowany['distanceKm'] > 0, 'plik niesie prawdziwy przebieg, nie zero kilometrów');
});

t_test('strona przejazdu: skarby i znane trasy liczą się z PÓL tego przejazdu', function () {
    // Bliźniaki `Treasure::onRoute` / `KnownRoute::onActivity` po
    // `rider_activity_cells`. Test pilnuje kształtu i tego, że zapytania
    // w ogóle działają na drugiej tabeli — sama zawartość zależy od danych dev.
    $userId = t_user();
    $kopiaUrl = t_solo_gpx_kopia();
    $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $kopiaUrl, $kopiaUrl);
    $id = (int) $wynik['activityId'];

    $skarby = Models\Treasure::onActivity($id, $userId);
    $lista  = Models\Treasure::listOnActivity($id, $userId);
    $trasy  = Models\KnownRoute::onActivity($id);
    $regiony = RiderActivity::regionsFor($id);

    RiderActivity::deleteSoloForUser($id, $userId);

    t_true(isset($skarby['total'], $skarby['points'], $skarby['pointsLeft']), 'licznik skarbów ma swój kształt');
    t_true(isset($lista['items'], $lista['hidden']), 'lista skarbów ma swój kształt');
    t_true(is_array($trasy), 'znane trasy zwracają listę');
    t_true(is_array($regiony), 'regiony zwracają listę');
});

t_test('czas przejazdu: sekundy na „2 h 14 min", a brak danych to NIE zero', function () {
    t_same('48 min', Utils\Format::duration(2880), 'poniżej godziny bez godzin');
    t_same('2 h 14 min', Utils\Format::duration(8040), 'godziny i minuty');
    t_null(Utils\Format::duration(null), 'brak danych to null, nie „0 min"');
    t_null(Utils\Format::duration(0), 'zero sekund też nie jest odpowiedzią');
});

t_test('strona przejazdu: „inne przejazdy tędy" znajdują wspólny teren, a przypadkowe minięcie nie', function () {
    // Sekcja liczy się z PÓL przejazdu, nie z indeksu kafli — bo pola ma każdy
    // przejazd, a kafle są liczone leniwie (zmierzone przy wdrożeniu: 77 hashy
    // w `gpx_tiles` na 90 śladów). Test pilnuje obu stron progu: ta sama droga
    // ma się znaleźć, a przejazd o sto kilometrów dalej — nie.
    [$u] = t_users(1);

    $a = t_apka_nagranie($u, t_apka_linia(52.2300, 21.0100, 300, 50.0)); // ~15 km
    $b = t_apka_nagranie($u, t_apka_linia(52.2300, 21.0100, 260, 50.0)); // ta sama droga, krócej
    $daleko = t_apka_nagranie($u, t_apka_linia(51.1000, 17.0300, 300, 50.0)); // inne miasto
    t_true($a !== null && $b !== null && $daleko !== null, 'trzy przejazdy testowe się zapisały');

    $inne = RiderActivity::otherRidesAlong((int) $a['activityId'], $u);
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $inne);

    foreach ([$a, $b, $daleko] as $przejazd) {
        RiderActivity::deleteSoloForUser((int) $przejazd['activityId'], $u);
    }

    t_true(in_array((int) $b['activityId'], $ids, true), 'przejazd tą samą drogą jest na liście');
    t_false(in_array((int) $daleko['activityId'], $ids, true), 'przejazd w innym mieście NIE jest');
    t_false(in_array((int) $a['activityId'], $ids, true), 'przejazd nie poleca sam siebie');
});
