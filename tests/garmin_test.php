<?php
// tests/garmin_test.php
// IMPORT PRZEJAZDÓW Z GARMIN CONNECT (migr. 068).
//
// CZEGO TU NIE MA I DLACZEGO: nie ma testu prawdziwego logowania ani
// prawdziwego pobrania śladu. Wymagałoby to cudzych danych logowania w kodzie
// testu i strzelania do serwerów Garmina przy każdym przebiegu — to nie jest
// test, to zależność od pogody. Testujemy WSZYSTKO, co jest po naszej stronie:
// szyfrowanie tokenów, rejestr „już to mamy", bramkę na braku połączenia oraz
// sam most PHP->Python (że proces się uruchamia, dostaje JSON i oddaje kopertę
// {"ok":false,...} zamiast wysypać się na stderr).

use Core\Database;
use Models\DeviceConnection;
use Models\DeviceImport;
use Models\GarminImport;
use Utils\GarminBridge;

t_test('token Garmina szyfruje się i odszyfrowuje z powrotem', function () {
    $plain = '{"di_token":"abc","di_refresh_token":"def","di_client_id":"ghi"}';
    $cipher = DeviceConnection::encrypt($plain);

    t_true($cipher !== $plain, 'szyfrogram różni się od tekstu jawnego');
    t_same($plain, DeviceConnection::decrypt($cipher), 'odszyfrowanie oddaje oryginał');
});

t_test('ten sam token szyfrowany dwa razy daje dwa różne szyfrogramy', function () {
    // Losowy IV przy każdym zapisie — inaczej po samej bazie dałoby się poznać,
    // że dwie osoby mają ten sam token, i porównywać zmiany w czasie.
    $plain = '{"di_token":"abc"}';
    t_true(DeviceConnection::encrypt($plain) !== DeviceConnection::encrypt($plain), 'dwa różne szyfrogramy');
});

t_test('podmieniony szyfrogram nie deszyfruje się po cichu', function () {
    // AES-GCM wykrywa manipulację — token idzie potem WPROST do procesu
    // Pythona, więc „odszyfrowały się śmieci" byłoby najgorszym wynikiem.
    $cipher = DeviceConnection::encrypt('{"di_token":"abc"}');
    $raw = base64_decode($cipher, true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';

    t_null(DeviceConnection::decrypt(base64_encode($raw)), 'zepsuty szyfrogram zwraca null');
    t_null(DeviceConnection::decrypt('to nie jest base64 ###'), 'śmieci zamiast szyfrogramu zwracają null');
});

t_test('połączenie konta zapisuje się i daje się odczytać', function () {
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', ['session' => 't1'], 'rowerzysta@example.com', 'Jan Rowerzysta');

    $acct = DeviceConnection::find($userId, 'garmin');
    t_not_null($acct, 'połączenie istnieje');
    t_eq('rowerzysta@example.com', $acct['label'], 'adres e-mail');
    t_eq('Jan Rowerzysta', $acct['displayName'], 'nazwa z Garmina');
    t_same('t1', DeviceConnection::token($userId, 'garmin')['session'], 'token wraca w postaci jawnej');

    // Ponowne logowanie NADPISUJE, nie duplikuje — klucz główny to (user, dostawca).
    DeviceConnection::connect($userId, 'garmin', ['session' => 't2'], 'inny@example.com');
    t_same('t2', DeviceConnection::token($userId, 'garmin')['session'], 'token po ponownym logowaniu');
    t_eq('inny@example.com', DeviceConnection::find($userId, 'garmin')['label'], 'adres po ponownym logowaniu');
});

t_test('dwa liczniki naraz to normalny przypadek, nie konflikt', function () {
    // Klucz główny na PARZE (user_id, provider) — ktoś może mieć Garmina na
    // rowerze i Polara na nadgarstku, i oba mają prawo tu być.
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', ['session' => 'g']);
    DeviceConnection::connect($userId, 'polar', ['access_token' => 'p']);

    t_count(2, DeviceConnection::allFor($userId), 'dwa połączenia obok siebie');
    t_same('g', DeviceConnection::token($userId, 'garmin')['session'], 'token Garmina osobno');
    t_same('p', DeviceConnection::token($userId, 'polar')['access_token'], 'token Polara osobno');

    DeviceConnection::disconnect($userId, 'polar');
    t_not_null(DeviceConnection::find($userId, 'garmin'), 'odłączenie jednego nie rusza drugiego');
});

t_test('hasło nie trafia do bazy w żadnej postaci', function () {
    // Test-strażnik: kolumna na hasło nie istnieje i nie ma powstać, a token
    // w bazie nie może być zapisany jawnie.
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', ['session' => 'sekret'], 'rowerzysta@example.com');

    $row = Database::connection()->query(
        'SELECT * FROM device_connections WHERE user_id = ' . (int) $userId
    )->fetch(PDO::FETCH_ASSOC);

    t_false(array_key_exists('password', $row), 'brak kolumny password');
    t_false(str_contains(json_encode($row), 'sekret'), 'token nie leży w bazie jawnie');
});

t_test('odłączenie kasuje połączenie, ale ZOSTAWIA rejestr pobranych', function () {
    // Inaczej „odłącz i podłącz ponownie" byłoby obejściem idempotencji:
    // wszystkie przejazdy wróciłyby jako nowe do policzenia drugi raz.
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', ['session' => 't'], 'a@example.com');
    t_garmin_seen($userId, 111, 'imported');

    DeviceConnection::disconnect($userId, 'garmin');

    t_null(DeviceConnection::find($userId, 'garmin'), 'połączenia już nie ma');
    t_null(DeviceConnection::token($userId, 'garmin'), 'tokenu już nie ma');
    t_count(1, DeviceImport::knownIds($userId, 'garmin'), 'rejestr pobranych został');
});

t_test('rejestr zna WSZYSTKIE stany, nie tylko udane pobrania', function () {
    // Duplikat i odrzucenie też muszą blokować powrót na listę — inaczej
    // aktywność bez śladu GPS wracałaby jako „nowa" po każdym odświeżeniu.
    $userId = t_user();
    t_garmin_seen($userId, 201, 'imported');
    t_garmin_seen($userId, 202, 'duplicate');
    t_garmin_seen($userId, 203, 'rejected');

    $known = DeviceImport::knownIds($userId, 'garmin');
    t_count(3, $known, 'trzy znane identyfikatory');
    t_true(isset($known['202']), 'duplikat jest znany');
    t_true(isset($known['203']), 'odrzucony jest znany');
});

t_test('rejestr jest osobny dla każdego konta', function () {
    // Klucz unikalny stoi na TRÓJCE (user_id, provider, activity_id) — dwie osoby mogą
    // mieć u siebie ten sam przejazd i obie mają do niego prawo.
    [$a, $b] = t_users(2);
    t_garmin_seen($a, 555, 'imported');

    t_count(1, DeviceImport::knownIds($a, 'garmin'), 'pierwsza osoba ma wpis');
    t_count(0, DeviceImport::knownIds($b, 'garmin'), 'druga osoba nie widzi cudzego wpisu');
});

t_test('import znanej już aktywności NIE strzela do Garmina', function () {
    // Gdyby bramka nie działała, ten test poszedłby po sieć i albo trwał
    // sekundy, albo wywalił się na tokenie „t" — jedno i drugie jest sygnałem.
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', ['session' => 't'], 'a@example.com');
    t_garmin_seen($userId, 777, 'imported');

    $wynik = GarminImport::import($userId, ['777']);

    t_same(0, $wynik['dodane'], 'nic nie dodano');
    t_same(0, $wynik['odrzucone'], 'nic nie odrzucono');
    t_same(0, $wynik['pozostalo'], 'nic nie zostało');
});

t_test('żywy status (2026-08-26): pusta partia nie woła onProgress ani razu', function () {
    // Ten sam przypadek co wyżej ("import znanej już aktywności"), ale patrzy
    // na drugi koniec zmiany: skoro `$partia` wychodzi pusta PRZED wywołaniem
    // mostu, callback progresu nie ma czego zgłaszać — zero zdarzeń, nie
    // zdarzenie z total=0. Panel w JS nie ma prawa otworzyć się na nic.
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', ['session' => 't'], 'a@example.com');
    t_garmin_seen($userId, 888, 'imported');

    $zdarzenia = [];
    GarminImport::import($userId, ['888'], [], function (array $e) use (&$zdarzenia) {
        $zdarzenia[] = $e;
    });

    t_count(0, $zdarzenia, 'onProgress nie wystrzelił ani razu');
});

t_test('import bez połączenia odmawia, zamiast próbować cokolwiek pobrać', function () {
    $userId = t_user();
    try {
        GarminImport::import($userId, ['1234']);
        t_fail('oczekiwano wyjątku przy braku połączenia');
    } catch (TAssertionFailed $e) {
        throw $e;
    } catch (\RuntimeException $e) {
        t_true(str_contains($e->getMessage(), 'Brak połączenia'), 'czytelny komunikat');
    }
});

t_test('most do Pythona odpowiada kopertą JSON, a nie wysypaniem się', function () {
    if (!GarminBridge::available()) {
        // Środowisko bez Pythona/skryptu — nie ma czego testować i nie jest to błąd.
        t_true(true, 'most niedostępny w tym środowisku, test pominięty');
        return;
    }

    // Nieprawidłowy identyfikator zatrzymuje się w skrypcie, ZANIM cokolwiek
    // poleci do Garmina — więc test jest szybki i nie wymaga sieci.
    $answer = GarminBridge::call('download', ['tokens' => '{"di_token":"x"}', 'activityId' => 'abc']);

    t_same(false, $answer['ok'], 'most zwrócił ok=false');
    t_true(!empty($answer['error']), 'jest komunikat błędu');
});

t_test('most odrzuca nieznaną komendę', function () {
    if (!GarminBridge::available()) {
        t_true(true, 'most niedostępny w tym środowisku, test pominięty');
        return;
    }

    $answer = GarminBridge::call('skasuj-wszystko', []);

    t_same(false, $answer['ok'], 'nieznana komenda odrzucona');
    t_eq('input', $answer['code'], 'kod przyczyny wskazuje na wejście');
});

/** Wpis do rejestru pobranych — odpowiednik GarminImport::remember() dla testu. */
function t_garmin_seen(int $userId, int $activityId, string $status): void
{
    $stmt = Database::connection()->prepare(
        'INSERT INTO device_activities (user_id, provider, activity_id, status) VALUES (:u, "garmin", :a, :s)'
    );
    $stmt->execute(['u' => $userId, 'a' => $activityId, 's' => $status]);
}

t_test('token w STARYM kształcie (sprzed migr. 069) dalej otwiera połączenie', function () {
    // BŁĄD ZGŁOSZONY PRZEZ USERA 2026-08-24: „Połączenie nie zadziałało"
    // natychmiast po migracji, mimo podłączonego konta. Migracja skopiowała
    // szyfrogramy jeden do jednego, a te niosły SUROWY token Garmina
    // (`{"di_token": …}`) zamiast koperty `{"session": …}` — nowy odczyt
    // widział wtedy „brak połączenia" i kazał odłączać i łączyć od nowa.
    //
    // Pusta lista identyfikatorów: interesuje nas WYŁĄCZNIE to, czy import
    // dochodzi do pracy, czy odbija się o brak tokenu. Bez wywołania sieci.
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', [
        'di_token'         => 'stary-format',
        'di_refresh_token' => 'r',
        'di_client_id'     => 'c',
    ], 'stary@example.com');

    $wynik = GarminImport::import($userId, []);
    t_same(0, $wynik['dodane'], 'import ruszył i nie miał nic do zrobienia');
});

t_test('token bez rozpoznawalnego kształtu dalej znaczy „brak połączenia"', function () {
    // Zgodność wsteczna nie może zamienić się w „bierz cokolwiek": token,
    // którego nie rozumiemy, ma się zachować jak jego brak, a nie polecieć
    // do Garmina i wrócić niezrozumiałym błędem.
    $userId = t_user();
    DeviceConnection::connect($userId, 'garmin', ['cos' => 'zupelnie innego']);

    try {
        GarminImport::import($userId, []);
        t_fail('oczekiwano wyjątku przy nierozpoznanym tokenie');
    } catch (TAssertionFailed $e) {
        throw $e;
    } catch (\RuntimeException $e) {
        t_true(str_contains($e->getMessage(), 'Brak połączenia'), 'czytelny komunikat');
    }
});

t_test('import bierze CAŁĄ partię jednym wywołaniem mostu, nie po jednym', function () {
    // Regresja na to, co poprawialiśmy 2026-08-24: każde pobranie startowało
    // osobny proces Pythona i osobne logowanie do Garmina, więc partia
    // piętnastu przejazdów znaczyła piętnaście logowań pod rząd — wolno
    // i wprost pod blokadę po ich stronie. Sprawdzamy KSZTAŁT wywołania,
    // bo samego pobierania nie da się przetestować bez konta.
    $src = file_get_contents(CORE_PATH . '/Models/GarminImport.php');

    t_true(str_contains($src, "'activityIds' => \$partia"), 'most dostaje całą partię naraz');
    t_same(1, substr_count($src, "GarminBridge::call('download'"), 'dokładnie JEDNO wywołanie pobierania');
    t_true(!str_contains($src, "foreach (\$partia as \$id) {\n            try {\n                \$answer = GarminBridge::call"),
        'w pętli po aktywnościach nie ma już wywołania mostu');
});

t_test('okno historii sięga dalej niż jedna strona wyników', function () {
    // „Sprawdzono 16 aktywności, a ja mam pod 300 w Garminie" — LOOKBACK liczy
    // aktywności WSZYSTKICH dyscyplin, więc przy 50 rowerowych zostawało kilkanaście
    // i wszystkie były już zaimportowane.
    t_true(Models\GarminImport::LOOKBACK >= 300, 'okno obejmuje co najmniej 300 aktywności'
        . ' (jest: ' . Models\GarminImport::LOOKBACK . ')');
});
