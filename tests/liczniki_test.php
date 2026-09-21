<?php
// tests/liczniki_test.php
// PLIKI FIT I LICZNIKI PRZEZ OAuth (migr. 069, 2026-08-23).
//
// CZEGO TU NIE MA: prawdziwych zapytań do Polara i Wahoo. Wymagałyby cudzych
// kluczy API w kodzie testu i strzelania do cudzych serwerów przy każdym
// przebiegu — to nie jest test, to zależność od pogody. Testujemy WSZYSTKO,
// co jest po naszej stronie: dekoder FIT na PRAWDZIWYM pliku, składanie adresu
// autoryzacji, bramkę „bez kluczy nic nie działa" i wspólny rejestr pobrań.

use Models\DeviceConnection;
use Models\DeviceImport;
use Utils\DeviceApi;
use Utils\Fit;

/** Prawdziwy plik FIT z zegarka — dołączony do biblioteki dekodera. */
function t_fit_file(string $name = 'road-cycling.fit'): string
{
    $path = CORE_PATH . '/../vendor/adriangibbons/php-fit-file-analysis/demo/fit_files/' . $name;
    if (!is_file($path)) {
        t_fail('Brak pliku testowego FIT (' . $name . ') — czy vendor/ jest zainstalowany?');
    }
    return $path;
}

// --- FIT -------------------------------------------------------------

t_test('FIT: prawdziwy plik z licznika zamienia się w GPX ze śladem', function () {
    // Cały sens konwersji: FIT jest formatem WEJŚCIA, a dalej serwis widzi
    // dokładnie to samo co zawsze. Sprawdzamy więc nie sam XML, tylko to, czy
    // przechodzi przez NASZ parser i daje sensowne liczby.
    $gpx = Fit::toGpx(t_fit_file(), 'Test');
    $tmp = sys_get_temp_dir() . '/ridemore_fit_test_' . bin2hex(random_bytes(4)) . '.gpx';
    file_put_contents($tmp, $gpx);

    try {
        $parsed = Utils\Gpx::parse($tmp);
        t_true(count($parsed['points']) > 1000, 'ślad ma tysiące punktów (jest: ' . count($parsed['points']) . ')');
        t_true((float) $parsed['distanceKm'] > 5, 'dystans wyszedł sensowny: ' . $parsed['distanceKm'] . ' km');
        t_not_null($parsed['startedAt'], 'znacznik czasu przeżył konwersję (data przejazdu solo bierze się z niego)');
        t_true(count(Utils\DiscoveryGrid::cellsForTrack($parsed['points'])) > 10, 'ślad daje pola siatki');
    } finally {
        @unlink($tmp);
    }
});

t_test('FIT: nagranie bez GPS jest ODRZUCONE z własnym komunikatem', function () {
    // Trenażer i basen nagrywają się normalnie, tylko bez pozycji. To nie jest
    // uszkodzony plik i komunikat musi to rozróżniać — inaczej człowiek szuka
    // usterki tam, gdzie jej nie ma.
    try {
        Fit::toGpx(t_fit_file('swim.fit'));
        t_fail('oczekiwano odmowy dla nagrania bez śladu');
    } catch (TAssertionFailed $e) {
        throw $e;
    } catch (\RuntimeException $e) {
        t_true(str_contains($e->getMessage(), 'bez GPS'), 'komunikat mówi o braku GPS, nie o błędzie pliku');
    }
});

t_test('FIT: śmieci zamiast pliku nie wywracają nas cicho', function () {
    try {
        Fit::toGpxFromString('to nie jest plik FIT, tylko tekst');
        t_fail('oczekiwano wyjątku');
    } catch (TAssertionFailed $e) {
        throw $e;
    } catch (\RuntimeException $e) {
        t_true($e->getMessage() !== '', 'jest czytelny komunikat');
    }
});

t_test('FIT: rozpoznajemy go po rozszerzeniu w JEDNYM miejscu', function () {
    t_true(Fit::looksLikeFit('przejazd.fit'), 'małe litery');
    t_true(Fit::looksLikeFit('PRZEJAZD.FIT'), 'wielkie litery');
    t_false(Fit::looksLikeFit('przejazd.gpx'), 'GPX to nie FIT');
    t_false(Fit::looksLikeFit('fit'), 'sama nazwa bez rozszerzenia');
});

// --- Dostawcy --------------------------------------------------------

t_test('liczniki: zakładki mają wszystkich sześciu dostawców', function () {
    // Interfejs obiecuje sześć zakładek (plik + pięć serwisów); ten test pilnuje,
    // żeby lista w kodzie i w głowie usera się nie rozjechały.
    $all = DeviceApi::all();
    foreach (['garmin', 'polar', 'wahoo', 'coros', 'suunto'] as $key) {
        t_true(isset($all[$key]), 'dostawca na liście: ' . $key);
        t_true(!empty($all[$key]['label']), $key . ' ma nazwę dla człowieka');
        t_true(!empty($all[$key]['note']), $key . ' ma zdanie wyjaśniające (także gdy NIE działa)');
    }
});

t_test('liczniki: dostawca bez adaptera NIE jest używalny, choć ma zakładkę', function () {
    // COROS i Suunto czekają na onboarding partnerski. Zakładka istnieje (żeby
    // powiedzieć, czego brakuje), ale przycisk nie ma prawa się pokazać —
    // i trasa musi to samo rozstrzygać po stronie serwera.
    t_false(DeviceApi::isUsable('coros'), 'COROS bez adaptera');
    t_false(DeviceApi::isUsable('suunto'), 'Suunto bez adaptera');
});

t_test('liczniki: bez kluczy API dostawca jest wyłączony', function () {
    // Ta sama zasada co przy logowaniu społecznościowym: brak sekretu = brak
    // przycisku. Test ma sens w OBIE strony, więc sprawdza to, co akurat jest
    // w konfiguracji tego środowiska.
    foreach (['polar', 'wahoo'] as $key) {
        $maKlucze = DeviceApi::credentials($key) !== null;
        t_same($maKlucze, DeviceApi::isUsable($key), $key . ': używalny dokładnie wtedy, gdy ma klucze');
        if (!$maKlucze) {
            try {
                DeviceApi::authorizeUrl($key, 'x');
                t_fail('bez kluczy nie wolno zbudować adresu autoryzacji (' . $key . ')');
            } catch (TAssertionFailed $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                t_true(true, $key . ': odmowa zamiast pustego client_id w adresie');
            }
        }
    }
});

t_test('liczniki: adres powrotny nie dubluje katalogu aplikacji', function () {
    // Błąd złapany przy budowie: `app_url` ma już `/ridemore`, a View::url()
    // dokładał go DRUGI RAZ. Dostawcy porównują redirect_uri znak po znaku,
    // więc taka literówka znaczy „autoryzacja nie działa i nie wiadomo czemu".
    $uri = DeviceApi::redirectUri('polar');
    t_true(str_starts_with($uri, (string) APP_CONFIG['app_url']), 'zaczyna się od app_url');
    t_true(str_ends_with($uri, '/admin/moje-przejazdy/licznik/polar/callback'), 'kończy się trasą callbacku');
    t_same(1, substr_count($uri, '/admin/moje-przejazdy'), 'ścieżka występuje dokładnie raz');
});

// --- Wspólny rejestr -------------------------------------------------

t_test('rejestr pobrań jest WSPÓLNY, ale rozdzielony po dostawcy', function () {
    // Ta sama liczba może być identyfikatorem u dwóch dostawców naraz —
    // klucz unikalny stoi na trójce (user, dostawca, aktywność), więc jedno
    // nie zasłania drugiego.
    $userId = t_user();
    DeviceImport::remember($userId, 'polar', 'ABC123', 'imported', null, ['name' => 'Runda']);
    DeviceImport::remember($userId, 'wahoo', 'ABC123', 'imported', null, null);

    t_count(1, DeviceImport::knownIds($userId, 'polar'), 'Polar zna swój wpis');
    t_count(1, DeviceImport::knownIds($userId, 'wahoo'), 'Wahoo zna swój');
    t_count(0, DeviceImport::knownIds($userId, 'garmin'), 'Garmin nie widzi cudzych');
});

t_test('rejestr przyjmuje TEKSTOWE identyfikatory (Polar hashuje swoje)', function () {
    // Kolumna była BIGINT-em pod Garmina; Polar oddaje ID jak „aBc9-Xy".
    // Gdyby została liczbą, wpis wpadłby jako 0 i wszystkie aktywności Polara
    // odbijałyby się o siebie nawzajem.
    $userId = t_user();
    DeviceImport::remember($userId, 'polar', 'aBc9-Xy_z', 'imported', null, null);

    $known = DeviceImport::knownIds($userId, 'polar');
    t_true(isset($known['aBc9-Xy_z']), 'identyfikator zapisał się bez zmian');
});

t_test('import bez połączenia odmawia, zanim cokolwiek poleci do sieci', function () {
    $userId = t_user();
    try {
        DeviceImport::import($userId, 'polar', ['x1']);
        t_fail('oczekiwano wyjątku przy braku połączenia');
    } catch (TAssertionFailed $e) {
        throw $e;
    } catch (\RuntimeException $e) {
        t_true(str_contains($e->getMessage(), 'Brak połączenia'), 'czytelny komunikat');
    }
});

t_test('żywy status (2026-08-26): znana już aktywność nie woła onProgress ani razu', function () {
    // Ten sam wzorzec co test Garmina w tests/garmin_test.php — gdy `$partia`
    // po odsianiu po rejestrze wychodzi pusta, pętla w ogóle nie startuje,
    // więc callback progresu nie ma czego zgłaszać. Token BEZ expires_in, żeby
    // DeviceApi::refreshIfNeeded() nie próbował nic odświeżać przez sieć.
    $userId = t_user();
    DeviceConnection::connect($userId, 'polar', ['access_token' => 't'], 'a@example.com');
    DeviceImport::remember($userId, 'polar', 'p-999', 'imported', null, null);

    $zdarzenia = [];
    DeviceImport::import($userId, 'polar', ['p-999'], [], function (array $e) use (&$zdarzenia) {
        $zdarzenia[] = $e;
    });

    t_count(0, $zdarzenia, 'onProgress nie wystrzelił ani razu');
});

t_test('token OAuth wraca z bazy w całości i tylko właścicielowi', function () {
    [$a, $b] = t_users(2);
    $token = ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'obtained_at' => time()];
    DeviceConnection::connect($a, 'polar', $token, 'polar@example.com', 'Jan', 'polar-999');

    $wrocil = DeviceConnection::token($a, 'polar');
    t_same('AT', $wrocil['access_token'], 'access token');
    t_same('RT', $wrocil['refresh_token'], 'refresh token przeżył szyfrowanie');
    t_eq('polar-999', DeviceConnection::find($a, 'polar')['externalUserId'], 'id nadane przez dostawcę');
    t_null(DeviceConnection::token($b, 'polar'), 'druga osoba nie dostaje cudzego tokenu');
});

// --- Automatyczny import przez webhooki (migr. 088, 2026-09-14) -------
//
// Prawdziwego doręczenia od Polara/Wahoo tu nie ma (to cudze serwery i cudze
// konta) — sprawdzamy wszystko, co rozstrzyga się po naszej stronie: podpis,
// odczytanie „kto i co", filtr „tylko rower", to, że webhook trafia wyłącznie
// do osób z włączonym automatem, i że powiadomienie idzie przez bramkę raz.

t_test('webhook Polar: prawdziwy podpis HMAC przechodzi, zmieniona treść i brak nagłówka nie', function () {
    $sekret = 'sekret-testowy';
    $tresc = '{"event":"EXERCISE","user_id":475,"entity_id":"aQlC83"}';
    $podpis = hash_hmac('sha256', $tresc, $sekret);
    $payload = json_decode($tresc, true);

    t_true(DeviceApi::verifyWebhook('polar', $tresc, $podpis, $payload, $sekret), 'podpis z tej treści');
    t_true(DeviceApi::verifyWebhook('polar', $tresc, strtoupper($podpis), $payload, $sekret), 'wielkość liter hexa nie ma znaczenia');
    t_false(DeviceApi::verifyWebhook('polar', str_replace('475', '476', $tresc), $podpis, $payload, $sekret),
        'podmieniony user_id w treści = odrzucone (inaczej dałoby się wskazać cudze konto)');
    t_false(DeviceApi::verifyWebhook('polar', $tresc, null, $payload, $sekret), 'brak nagłówka = odrzucone');
    t_false(DeviceApi::verifyWebhook('polar', $tresc, hash_hmac('sha256', $tresc, 'inny'), $payload, $sekret), 'podpis innym sekretem');
});

t_test('webhook Wahoo: przechodzi wyłącznie token z panelu aplikacji', function () {
    $sekret = 'token-z-panelu';
    t_true(DeviceApi::verifyWebhook('wahoo', '', null, ['webhook_token' => $sekret], $sekret), 'zgodny token');
    t_false(DeviceApi::verifyWebhook('wahoo', '', null, ['webhook_token' => 'zgadywany'], $sekret), 'inny token');
    t_false(DeviceApi::verifyWebhook('wahoo', '', null, [], $sekret), 'brak tokenu');
    t_false(DeviceApi::verifyWebhook('wahoo', '', null, ['webhook_token' => ['tablica']], $sekret), 'token nie-tekstowy');
});

t_test('webhook: bez sekretu w konfiguracji nic nie przechodzi i automat się nie pokazuje', function () {
    foreach (['polar', 'wahoo'] as $key) {
        if (DeviceApi::webhookSecret($key) !== null) {
            continue; // środowisko ma sekret — ten przypadek sprawdza się tylko bez niego
        }
        t_false(DeviceApi::verifyWebhook($key, '{}', 'cokolwiek', ['webhook_token' => ''], null), $key . ': odmowa bez sekretu');
        t_false(DeviceApi::webhookReady($key), $key . ': przełącznik automatu się nie pokazuje');
    }
    t_false(DeviceApi::webhookReady('garmin'), 'Garmin nie ma oficjalnego webhooka — automatu nie ma');
    t_true(str_ends_with(DeviceApi::webhookUrl('polar'), '/api/liczniki/polar/webhook'), 'adres odbiornika');
    t_same(1, substr_count(DeviceApi::webhookUrl('polar'), '/api/liczniki'), 'ścieżka występuje dokładnie raz');
});

t_test('webhook Polar: EXERCISE daje „kto i co", PING i inne zdarzenia nie', function () {
    $e = DeviceApi::webhookEvent('polar', [
        'event' => 'EXERCISE', 'user_id' => 475, 'entity_id' => 'aQlC83',
        'timestamp' => '2018-05-15T14:22:24Z', 'url' => 'https://www.polaraccesslink.com/v3/exercises/aQlC83',
    ]);
    t_same('475', $e['externalUserId'], 'identyfikator Polara jako tekst');
    t_same('aQlC83', $e['activityId'], 'identyfikator treningu');
    t_null($e['meta'], 'Polar nie podaje metadanych w powiadomieniu');

    t_null(DeviceApi::webhookEvent('polar', ['event' => 'PING', 'timestamp' => '2019-01-11T08:25:10.02Z']), 'PING');
    t_null(DeviceApi::webhookEvent('polar', ['event' => 'SLEEP', 'user_id' => 1, 'entity_id' => 'x']), 'sen to nie przejazd');
    t_null(DeviceApi::webhookEvent('polar', ['event' => 'EXERCISE', 'user_id' => 1]), 'bez identyfikatora treningu');
});

t_test('webhook Wahoo: próbka z dokumentacji daje id TRENINGU, nie podsumowania', function () {
    // Treść 1:1 z przykładu w dokumentacji Wahoo Cloud API. `workout` siedzi
    // WEWNĄTRZ `workout_summary`, a jego id (56519) różni się od id
    // podsumowania (8297) — rejestr pobrań zna id treningu z listy, więc
    // pomylenie ich znaczyłoby ten sam przejazd dwa razy.
    $e = DeviceApi::webhookEvent('wahoo', [
        'event_type' => 'workout_summary', 'webhook_token' => 't', 'user' => ['id' => 60462],
        'workout_summary' => [
            'id' => 8297, 'distance_accum' => '24909.71',
            'file' => ['url' => 'https://server.com/4_Mile_Segment_.fit'],
            'workout' => ['id' => 56519, 'starts' => '2015-08-12T09:00:00.000Z', 'name' => 'Friday Fun', 'workout_type_id' => 40],
        ],
    ]);
    t_same('60462', $e['externalUserId'], 'id użytkownika Wahoo');
    t_same('56519', $e['activityId'], 'id treningu, nie podsumowania');
    t_same('Friday Fun', $e['meta']['name'], 'nazwa');
    t_same('2015-08-12T09:00:00', $e['meta']['startedAt'], 'start przycięty jak na liście');
    t_eq(24.91, $e['meta']['distanceKm'], 'dystans w km');
    t_same('40', $e['meta']['type'], 'rodzaj treningu');

    t_null(DeviceApi::webhookEvent('wahoo', ['event_type' => 'user_update', 'user' => ['id' => 1]]), 'inne zdarzenie');
});

t_test('automat: dodaje tylko rower z trasą — bieg, trenażer i motocykl zostają do ręcznego pobrania', function () {
    foreach (['0' => 'BIKING', '15' => 'ROAD', '13' => 'MTB', '11' => 'CX', '64' => 'EBIKE'] as $id => $opis) {
        t_true(DeviceApi::isCycling('wahoo', ['type' => (string) $id]), 'Wahoo ' . $opis);
    }
    $nieRower = ['1' => 'bieg', '12' => 'trenażer', '61' => 'trenażer smart', '68' => 'jazda wirtualna',
                 '17' => 'motocykl', '40' => 'kitesurfing', '' => 'brak rodzaju'];
    foreach ($nieRower as $id => $opis) {
        t_false(DeviceApi::isCycling('wahoo', ['type' => (string) $id]), 'Wahoo ' . $opis);
    }

    t_true(DeviceApi::isCycling('polar', ['type' => 'ROAD_BIKING', 'hasRoute' => true]), 'Polar szosa');
    t_true(DeviceApi::isCycling('polar', ['type' => 'MOUNTAIN_BIKING']), 'Polar MTB bez pola has_route');
    t_true(DeviceApi::isCycling('polar', ['type' => 'CYCLING']), 'Polar ogólne kolarstwo');
    t_false(DeviceApi::isCycling('polar', ['type' => 'INDOOR_CYCLING']), 'Polar rower stacjonarny');
    t_false(DeviceApi::isCycling('polar', ['type' => 'RUNNING']), 'Polar bieg');
    t_false(DeviceApi::isCycling('polar', ['type' => 'ROAD_BIKING', 'hasRoute' => false]), 'Polar rower bez trasy');
    t_false(DeviceApi::isCycling('polar', ['type' => 'OTHER']), 'Polar „inne"');
    t_false(DeviceApi::isCycling('garmin', ['type' => 'cycling']), 'dostawca bez automatu');
});

t_test('automat: przełącznik domyślnie wyłączony, a odświeżenie połączenia go nie gasi', function () {
    $userId = t_user();
    DeviceConnection::connect($userId, 'polar', ['access_token' => 't'], null, null, 'p-auto-1');
    t_false(DeviceConnection::find($userId, 'polar')['autoImport'], 'nowe połączenie = automat wyłączony');

    DeviceConnection::setAutoImport($userId, 'polar', true);
    DeviceConnection::connect($userId, 'polar', ['access_token' => 't2'], null, null, 'p-auto-1');
    t_true(DeviceConnection::find($userId, 'polar')['autoImport'], 'ponowne połączenie (np. po zakresach) zostawia wybór');
    t_true(DeviceConnection::hasAutoImport($userId), 'konto ma automat');

    DeviceConnection::disconnect($userId, 'polar');
    DeviceConnection::connect($userId, 'polar', ['access_token' => 't3'], null, null, 'p-auto-1');
    t_false(DeviceConnection::find($userId, 'polar')['autoImport'], 'odłączenie kasuje zgodę — po nim zaczynamy od „wyłączone"');
});

t_test('automat: webhook trafia WYŁĄCZNIE do osób z włączonym przełącznikiem przy tym koncie', function () {
    [$a, $b, $c] = t_users(3);
    DeviceConnection::connect($a, 'polar', ['access_token' => 'a'], null, null, 'p-wspolne');
    DeviceConnection::connect($b, 'polar', ['access_token' => 'b'], null, null, 'p-wspolne');
    DeviceConnection::connect($c, 'polar', ['access_token' => 'c'], null, null, 'p-inne');
    DeviceConnection::connect($a, 'wahoo', ['access_token' => 'w'], null, null, 'p-wspolne');
    DeviceConnection::setAutoImport($a, 'polar', true);
    DeviceConnection::setAutoImport($c, 'polar', true);
    DeviceConnection::setAutoImport($a, 'wahoo', true);

    t_same([$a], DeviceConnection::autoImportUsers('polar', 'p-wspolne'), 'b ma to samo konto, ale nie włączył automatu');
    t_same([$c], DeviceConnection::autoImportUsers('polar', 'p-inne'), 'inne konto Polar — inna osoba');
    t_same([], DeviceConnection::autoImportUsers('polar', 'nieznane'), 'nieznany identyfikator nikogo nie trafia');
});

t_test('automat: znany trening i nie-rower nie idą do sieci, rejestru ani powiadomień', function () {
    $userId = t_user();
    // Token BEZ expires_in — refreshIfNeeded() nie ma czego odświeżać, więc
    // test nie może przypadkiem wyjść do sieci.
    DeviceConnection::connect($userId, 'wahoo', ['access_token' => 't'], null, null, 'w-900');
    DeviceConnection::setAutoImport($userId, 'wahoo', true);
    DeviceImport::remember($userId, 'wahoo', 'w-znany', 'imported', null, null);

    $stmt = \Core\Database::connection()->prepare(
        'SELECT COUNT(*) FROM notification_log WHERE user_id = :u AND type = :t'
    );
    $ile = static function () use ($stmt, $userId): int {
        $stmt->execute(['u' => $userId, 't' => Models\NotificationGate::PRZEJAZD_Z_LICZNIKA]);
        return (int) $stmt->fetchColumn();
    };
    $przed = $ile();

    // Ponowione powiadomienie o treningu, który już jest — rejestr ucina je
    // przed jakimkolwiek zapytaniem do Wahoo.
    t_same(0, DeviceImport::fromWebhook('wahoo', [
        'externalUserId' => 'w-900', 'activityId' => 'w-znany',
        'meta' => ['id' => 'w-znany', 'name' => 'Runda', 'startedAt' => '', 'distanceKm' => 30.0, 'type' => '15'],
    ]), 'znany trening nie dodaje się drugi raz');

    // Bieg: automat go pomija i NIE zapisuje w rejestrze — ma dalej czekać na
    // liście „Pobierz aktywności", gdzie człowiek sam zdecyduje.
    t_same(0, DeviceImport::fromWebhook('wahoo', [
        'externalUserId' => 'w-900', 'activityId' => 'w-bieg',
        'meta' => ['id' => 'w-bieg', 'name' => 'Bieg', 'startedAt' => '', 'distanceKm' => 10.0, 'type' => '1'],
    ]), 'bieg nie jest dodany');
    t_false(isset(DeviceImport::knownIds($userId, 'wahoo')['w-bieg']), 'bieg nie trafia do rejestru — zostaje na liście');
    t_same($przed, $ile(), 'żadnego powiadomienia');
});

t_test('automat: powiadomienie o dodanym przejeździe idzie przez bramkę, oboma kanałami, raz na trening', function () {
    $userId = t_user();
    \Core\Database::connection()->prepare('DELETE FROM user_preferences WHERE user_id = :u')->execute(['u' => $userId]);
    $przejazd = ['sourceId' => 'aQlC83', 'activityId' => 123, 'distanceKm' => 42.5, 'cellsNew' => 17];

    // Transakcyjne: budżet zachęt i cisza nocna go nie zatrzymują, więc wynik
    // nie zależy od godziny, o której chodzą testy.
    t_true(Models\NotificationGate::transakcyjny(Models\NotificationGate::PRZEJAZD_Z_LICZNIKA), 'typ transakcyjny');
    t_same(['push' => true, 'mail' => true], DeviceImport::notifyImported($userId, 'polar', $przejazd), 'pierwsze: oba kanały');
    t_same(['push' => false, 'mail' => false], DeviceImport::notifyImported($userId, 'polar', $przejazd),
        'ponowiony webhook o tym samym treningu nie wysyła drugi raz');
    t_same(['push' => true, 'mail' => true], DeviceImport::notifyImported($userId, 'wahoo', $przejazd),
        'ten sam identyfikator u INNEGO dostawcy to inny trening');

    Models\NotificationGate::ustawZgode($userId, 'mail_rides', false);
    $inny = ['sourceId' => 'kolejny'] + $przejazd;
    t_same(['push' => true, 'mail' => false], DeviceImport::notifyImported($userId, 'polar', $inny), 'wyłączony mail nie gasi pusha');
});

t_test('automat: treść powiadomienia — polska, bez surowych znaczników', function () {
    Models\NotificationTexts::forget();
    $dane = ['licznik' => 'Polar', 'dystans' => '42,5 km', 'pola' => '17 nowych pól'];
    $tytul = Models\NotificationTexts::render('device_ride.push.title', $dane, [], true);
    $body = Models\NotificationTexts::render('device_ride.push.body', $dane, [], true);
    $mail = Models\NotificationTexts::render('device_ride.mail.body', $dane, ['przycisk' => '<a href="x">Zobacz</a>']);

    t_same('Przejazd z licznika Polar jest już na mapie', $tytul, 'tytuł pusha');
    t_same('42,5 km · 17 nowych pól', $body, 'treść pusha');
    t_false(str_contains($tytul . $body . $mail, '{'), 'żaden znacznik nie został niepodstawiony');
    t_true(str_contains($mail, 'Zobacz'), 'przycisk trafił do maila');
});

t_test('automat: odbiornik ma trasę, a skrypt zakładający webhook nie działa z HTTP', function () {
    $api = (string) file_get_contents(CORE_PATH . '/../api/routes.php');
    $ht  = (string) file_get_contents(CORE_PATH . '/../.htaccess');
    $cli = (string) file_get_contents(CORE_PATH . '/../polar_webhook.php');

    t_true(str_contains($api, "'/api/liczniki/{provider}/webhook'"), 'trasa odbiornika');
    t_true((bool) preg_match('/PHP_SAPI !== .cli./', $cli), 'polar_webhook.php ma bramkę CLI');
    t_true(strpos($cli, 'PHP_SAPI') < strpos($cli, "putenv('APP_ENV=prod')"),
        'bramka CLI stoi przed przełączeniem na konfigurację produkcyjną');
    t_true((bool) preg_match('/FilesMatch "\^\([^"]*\bpolar_webhook\b[^"]*\)\\\.php\$"/', $ht), '.htaccess blokuje skrypt');
});
