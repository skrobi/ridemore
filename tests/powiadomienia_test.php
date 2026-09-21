<?php

/**
 * BRAMKA POWIADOMIEŃ (Etap 0 programu zachęt, 2026-09-11; migr. 081).
 *
 * Testy sprawdzają REGUŁY, nie transport — `Core\Push` ma własny driver `log`
 * i nie jest tu wołany ani razu. Pytanie, na które odpowiada ten plik, brzmi:
 * „czy wolno", a nie „czy doszło".
 *
 * Uruchamiacz owija każdy test w transakcję, więc wiersze w `notification_log`
 * i `user_preferences` znikają po teście — dlatego można bez obaw zajmować
 * budżet i wyłączać zgody prawdziwym userom z fixture'ów.
 */

use Models\NotificationGate as Brama;

t_test('bramka: brak ustawień znaczy ZGODA — nie odwrotnie', function () {
    $user = t_user(0);
    // Świeże konto nie ma wiersza w `user_preferences`. Gdyby domyślną
    // odpowiedzią było „nie", push działałby wyłącznie dla ludzi, którzy
    // kiedyś zapisali formularz preferencji JAZDY — czyli praktycznie dla
    // nikogo, i to po cichu. Nadrzędny przełącznik push jest już opt-inem.
    \Core\Database::connection()->prepare('DELETE FROM user_preferences WHERE user_id = :u')
        ->execute(['u' => $user]);

    $zgody = Brama::zgoda($user);
    t_same(Brama::ZGODY_DOMYSLNE, $zgody, 'brak wiersza = wszystkie zgody włączone');
    t_not_null(
        Brama::claim($user, Brama::WIADOMOSC, 'msg:1'),
        'bez ustawień wiadomość przechodzi'
    );
});

t_test('bramka: wyłączona zgoda zatrzymuje SWÓJ typ i nie rusza pozostałych', function () {
    $user = t_user(0);
    Brama::ustawZgode($user, 'push_nearby', false);

    t_null(
        Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:1'),
        'zgaszona „okolica" blokuje skarb w okolicy'
    );
    // TO JEST CAŁY POWÓD ISTNIENIA TEGO ETAPU: do 2026-09-11 zgoda była jedna
    // na wszystko, więc irytująca zachęta zabierała też wiadomości od
    // organizatora wyjazdu.
    t_not_null(
        Brama::claim($user, Brama::WIADOMOSC, 'msg:2'),
        'wiadomości chodzą dalej, mimo zgaszonej „okolicy"'
    );
});

t_test('bramka: to samo nie wychodzi dwa razy', function () {
    $user = t_user(0);

    $pierwsze = Brama::claim($user, Brama::WIADOMOSC, 'msg:77');
    $drugie   = Brama::claim($user, Brama::WIADOMOSC, 'msg:77');

    t_not_null($pierwsze, 'pierwsze wywołanie zajmuje miejsce w dzienniku');
    t_null($drugie, 'drugie z tym samym kluczem nie przechodzi');

    // Klucz identyfikuje RZECZ, więc inna rzecz tego samego typu przechodzi.
    t_not_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:78'), 'inna wiadomość przechodzi');
});

t_test('bramka: zachęty mają budżet, transakcyjne go nie zajmują i nie podlegają', function () {
    $user = t_user(1);

    // Dobowy limit zachęt to 1 (BUDZET_DOBA), więc druga ma nie przejść —
    // ale tylko wtedy, gdy test biegnie POZA ciszą nocną. W nocy bramka
    // odrzuci już pierwszą i test nie miałby czego sprawdzać.
    if (Brama::ciszaNocna()) {
        t_true(true, 'test budżetu pominięty — trwa cisza nocna (sprawdzana osobno niżej)');
        return;
    }

    t_not_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:100'), 'pierwsza zachęta przechodzi');
    t_null(Brama::claim($user, Brama::NOWOSC_W_OKOLICY, 'kr:5'), 'druga zachęta tego samego dnia nie');

    // Transakcyjne są poza limitem — inaczej rozmowa na czacie wyczerpałaby
    // tygodniowy budżet w kwadrans, a zachęty nigdy by nie wyszły.
    t_not_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:900'), 'wiadomość mimo wyczerpanego budżetu zachęt');
    t_not_null(Brama::claim($user, Brama::ZAPIS_NA_WYJAZD, 'rsvp:1:2'), 'zapis na wyjazd też');
});

t_test('bramka: cisza nocna obejmuje 22:00–6:59 i ani minuty więcej', function () {
    // Godziny sprawdzane wprost, bo `date()` nie da się podstawić, a pomyłka
    // w jednym znaku nierówności albo budzi ludzi, albo zamyka wysyłkę na cały
    // dzień. Oba błędy są ciche.
    foreach ([22, 23, 0, 3, 6] as $godzina) {
        t_true(Brama::ciszaNocna($godzina), "godzina $godzina to cisza");
    }
    foreach ([7, 8, 12, 17, 21] as $godzina) {
        t_false(Brama::ciszaNocna($godzina), "godzina $godzina to NIE cisza");
    }
});

t_test('bramka: nieznany typ wybucha, zamiast wysyłać po cichu byle co', function () {
    $user = t_user(0);
    $rzucil = false;
    try {
        Brama::claim($user, 'wymyslony_typ', 'x');
    } catch (\InvalidArgumentException $e) {
        $rzucil = true;
    }
    // Literówka w nazwie typu musi zatrzymać się na testach, a nie zamienić
    // w powiadomienie bez zgody i bez budżetu — `ZGODA` jest jedyną listą
    // typów, jaką ta klasa uznaje.
    t_true($rzucil, 'nieznany typ powiadomienia rzuca wyjątek');
});

t_test('bramka: otwarcie zapisuje się raz i tylko właścicielowi wpisu', function () {
    $user = t_user(0);
    $obcy = t_user(1);
    $id = Brama::claim($user, Brama::WIADOMOSC, 'msg:555');
    t_not_null($id, 'wpis powstał');

    $db = \Core\Database::connection();
    $otwarte = static function (int $id) use ($db) {
        $s = $db->prepare('SELECT opened_at FROM notification_log WHERE id = :id');
        $s->execute(['id' => $id]);
        return $s->fetchColumn();
    };

    // Cudzy identyfikator nie ma prawa niczego oznaczyć — to samo podejście
    // co przy każdej innej bramce anty-IDOR w tym repo.
    Brama::oznaczOtwarte((int) $id, $obcy);
    t_null($otwarte((int) $id), 'obcy nie oznacza cudzego powiadomienia');

    Brama::oznaczOtwarte((int) $id, $user);
    t_not_null($otwarte((int) $id), 'właściciel oznacza');
});

t_test('push: puste `data` nie wychodzi w żądaniu — FCM nie przyjmuje listy jako mapy', function () {
    // ZNALEZIONE NA ŻYWYM FCM 2026-09-11, przy pierwszym uruchomieniu tego kodu
    // z prawdziwymi poświadczeniami: HTTP 400 „Cannot bind a list to map for
    // field 'data'". Przyczyna siedzi w PHP, nie w Google — i ta linijka jest
    // dowodem, że pułapka jest realna, a nie wymyślona:
    t_same('[]', json_encode(array_map(static fn($v): string => (string) $v, [])),
        'pusta tablica koduje się jako LISTA `[]`, a `data` w FCM musi być mapą `{}`');

    // Błąd był niewidoczny z dwóch powodów naraz: wszystkie cztery dzisiejsze
    // miejsca wysyłki podają `['url' => …]`, więc nigdy nie trafiały w pustą
    // gałąź — a `$data = []` jest WARTOŚCIĄ DOMYŚLNĄ `sendToUser()`. Do tego
    // `sendToUser()` łyka własne błędy, więc pierwsze wywołanie bez `data`
    // skończyłoby się cichym wpisem w logu i powiadomieniem, które nie doszło.
    $src = (string) file_get_contents(CORE_PATH . '/Core/Push.php');
    t_true(
        (bool) preg_match('/if \(\$data !== \[\]\) \{\s*(\/\/[^\n]*\n\s*)*\$payload\[\x27message\x27\]\[\x27data\x27\]/', $src),
        'pole `data` trafia do ładunku TYLKO gdy nie jest puste'
    );
    // Rzut na string dotyczy też liczb — `['n' => 123]` bez niego leci błędem,
    // a dokładnie tego kształtu potrzebuje pomiar otwarć (Etap 2).
    t_true(
        (bool) preg_match('/array_map\(static fn\(\$v\): string => \(string\) \$v, \$data\)/', $src),
        'wartości data-payloadu są rzutowane na stringi'
    );
});

/* ========================================================================
   ETAP 1c — KANAŁ E-MAIL (2026-09-11; migr. 082)
   ========================================================================
   Decyzje usera, których pilnują testy niżej: push i mail idą RÓWNOLEGLE
   (a nie jeden zamiast drugiego), ich limity liczą się OSOBNO, a zgoda jest
   osobna dla każdego kanału. Każda z tych trzech rzeczy zepsuta po cichu
   wygląda tak samo: powiadomienia po prostu przestają wychodzić.
   ======================================================================== */

t_test('kanały: to samo zdarzenie wychodzi pushem I mailem, ale każdym tylko raz', function () {
    $user = t_user(0);

    // Sedno migracji 082: gdyby kanał nie wchodził do klucza unikalności,
    // pierwszy kanał „zużyłby" zdarzenie i drugi nigdy by nie ruszył.
    $push = Brama::claim($user, Brama::WIADOMOSC, 'msg:4242', Brama::PUSH);
    $mail = Brama::claim($user, Brama::WIADOMOSC, 'msg:4242', Brama::MAIL);
    t_not_null($push, 'push tego zdarzenia przechodzi');
    t_not_null($mail, 'mail TEGO SAMEGO zdarzenia też przechodzi');

    t_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:4242', Brama::PUSH), 'ale push nie drugi raz');
    t_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:4242', Brama::MAIL), 'i mail nie drugi raz');
});

t_test('kanały: zgoda jest osobna — zgaszony mail nie gasi pusha', function () {
    $user = t_user(0);
    Brama::ustawZgode($user, 'mail_nearby', false);

    t_null(
        Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:31', Brama::MAIL),
        'zgaszona „okolica mailem" blokuje mail'
    );
    // Decyzja usera z 2026-09-11: to NIE jest ta sama zgoda. Można chcieć
    // wiedzieć o nowym skarbie z maila, a nie chcieć zaczepki w trakcie dnia.
    if (!Brama::ciszaNocna()) {
        t_not_null(
            Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:31', Brama::PUSH),
            'ta sama rzecz pushem przechodzi dalej'
        );
    }
});

t_test('kanały: budżet liczy się OSOBNO dla każdego kanału', function () {
    $user = t_user(2);
    if (Brama::ciszaNocna()) {
        // W ciszy pushowa połowa i tak jest zamknięta, więc porównanie
        // budżetów nie miałoby czego dowieść — mailowa jest sprawdzana niżej.
        t_true(true, 'test budżetu per kanał pominięty — trwa cisza nocna');
        return;
    }

    t_not_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:41', Brama::PUSH), 'pierwsza zachęta pushem');
    t_null(Brama::claim($user, Brama::NOWOSC_W_OKOLICY, 'kr:41', Brama::PUSH), 'druga pushem tego dnia — nie');

    // I TO JEST CAŁY SENS OSOBNYCH LIMITÓW: wyczerpany budżet pusha nie
    // zamyka skrzynki. Gdyby licznik był wspólny, włączenie drugiego kanału
    // po cichu zmniejszyłoby o połowę liczbę zachęt, które w ogóle wychodzą.
    t_not_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:41', Brama::MAIL), 'mail ma swój własny budżet');
    t_null(Brama::claim($user, Brama::NOWOSC_W_OKOLICY, 'kr:41', Brama::MAIL), 'ale też tylko jeden na dobę');
});

t_test('kanały: cisza nocna dotyczy pusha, nie maila', function () {
    $user = t_user(2);

    if (Brama::ciszaNocna()) {
        // Najmocniejszy wariant tego testu — akurat trwa cisza, więc różnicę
        // widać wprost, bez czytania kodu.
        t_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:51', Brama::PUSH), 'push milczy w nocy');
        t_not_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:51', Brama::MAIL), 'mail idzie — nikogo nie budzi');
        return;
    }

    // Poza ciszą oba kanały są otwarte, więc samo wywołanie niczego nie
    // rozstrzyga. Sprawdzamy więc warunek u źródła: to jedyna reguła w tej
    // klasie, której nie da się wywołać o dowolnej porze, a jej odwrócenie
    // (cisza obejmująca też mail) jest ciche — nocny cron po prostu przestaje
    // wysyłać cokolwiek, dokładnie tak jak przed tą zmianą.
    $src = (string) file_get_contents(CORE_PATH . '/Models/NotificationGate.php');
    t_true(
        (bool) preg_match('/\$kanal === self::PUSH && self::ciszaNocna\(\)/', $src),
        'cisza nocna jest zawężona do kanału push'
    );
});

t_test('kanały: nieznany kanał wybucha, zamiast wysyłać poza jakąkolwiek zgodą', function () {
    $user = t_user(0);
    $rzucil = false;
    try {
        Brama::claim($user, Brama::WIADOMOSC, 'msg:1', 'gołąb');
    } catch (\InvalidArgumentException $e) {
        $rzucil = true;
    }
    t_true($rzucil, 'nieznany kanał rzuca wyjątek');

    // `DOPASOWANIE` istnieje WYŁĄCZNIE w kanale mailowym, bo tylko tam ma
    // nadawcę (PreferenceNotifier). Gdyby kiedyś ktoś wysłał je pushem,
    // ma się o tym dowiedzieć tutaj, a nie podpiąć po cichu pod cudzą zgodę.
    $rzuciloPush = false;
    try {
        Brama::claim($user, Brama::DOPASOWANIE, 'ev:1', Brama::PUSH);
    } catch (\InvalidArgumentException $e) {
        $rzuciloPush = true;
    }
    t_true($rzuciloPush, 'dopasowanie pushem rzuca — nie ma takiego nadawcy');

    // Dopasowania stoją na STARSZEJ zgodzie (`notify_matches`), która jako
    // jedyna jest domyślnie WYŁĄCZONA — została zebrana świadomie w Etapie 3
    // i migracja 082 jej nie odwołuje. Bez tej linii claim odmawia i ma rację.
    Brama::ustawZgode($user, 'notify_matches', true);
    t_not_null(Brama::claim($user, Brama::DOPASOWANIE, 'ev:1', Brama::MAIL), 'ale mailem przechodzi');
});

t_test('wypis: podpis broni cudzego konta i cudzej flagi', function () {
    $user = t_user(0);
    $obcy = t_user(1);

    $adres = Brama::adresWypisu($user, 'mail_nearby');
    parse_str((string) parse_url($adres, PHP_URL_QUERY), $q);

    t_same((string) $user, (string) $q['u'], 'adres niesie identyfikator odbiorcy');
    t_not_null(Brama::rozwiazWypis($q['u'], $q['f'], $q['k']), 'własny, nietknięty link działa');

    // Podmiana identyfikatora to najprostszy atak na ten adres: gdyby podpis
    // obejmował samą flagę, dałoby się wyłączyć powiadomienia dowolnej osobie,
    // zmieniając w linku jedną cyfrę.
    t_null(Brama::rozwiazWypis((string) $obcy, $q['f'], $q['k']), 'cudzy identyfikator odpada');
    // Podpis obejmuje TAKŻE flagę — link z maila o skarbach nie może wyłączyć
    // wiadomości od organizatora.
    t_null(Brama::rozwiazWypis($q['u'], 'mail_messages', $q['k']), 'podmieniona flaga odpada');
    t_null(Brama::rozwiazWypis($q['u'], $q['f'], 'podrobiony'), 'zmyślony podpis odpada');
    t_null(Brama::rozwiazWypis($q['u'], 'wymyslona_flaga', $q['k']), 'nieznana flaga odpada');
});

t_test('wypis: gasi DOKŁADNIE jedną zgodę i nie rusza pozostałych', function () {
    $user = t_user(0);
    $adres = Brama::adresWypisu($user, 'mail_nearby');
    parse_str((string) parse_url($adres, PHP_URL_QUERY), $q);

    $cel = Brama::rozwiazWypis($q['u'], $q['f'], $q['k']);
    t_not_null($cel, 'link rozwiązuje się na cel');
    Brama::ustawZgode($cel['userId'], $cel['flaga'], false);

    $zgody = Brama::zgoda($user);
    t_true($zgody['mail_messages'], 'maile o wiadomościach zostają włączone');
    t_true($zgody['push_nearby'], 'pushowa „okolica" zostaje włączona');
    t_same(false, $zgody['mail_nearby'], 'zgaszona została tylko ta jedna flaga');
});

t_test('mail: stopka z wypisem pojawia się TYLKO gdy most ją dołożył', function () {
    // Maile transakcyjne (reset hasła, potwierdzenie wpłaty) idą wprost przez
    // Core\Mailer i nie mają się z czego wypisać — przycisk „wyłącz te
    // wiadomości" obiecywałby tam funkcję, której nie ma.
    $bezWypisu = \Utils\MailTemplate::render('new-message', [
        'recipientName' => 'Ktoś', 'senderName' => 'Ktoś inny',
        'body' => 'treść', 'link' => 'https://example.test/x',
    ]);
    t_true(!str_contains($bezWypisu, 'Wyłącz je jednym kliknięciem'), 'bez adresu wypisu — bez stopki');

    $zWypisem = \Utils\MailTemplate::render('new-message', [
        'recipientName' => 'Ktoś', 'senderName' => 'Ktoś inny',
        'body' => 'treść', 'link' => 'https://example.test/x',
        'unsubscribeUrl' => 'https://example.test/powiadomienia/wypisz?u=1&f=mail_messages&k=abc',
    ]);
    t_true(str_contains($zWypisem, 'Wyłącz je jednym kliknięciem'), 'z adresem wypisu — stopka jest');
    t_true(str_contains($zWypisem, 'f=mail_messages'), 'stopka linkuje do właściwej flagi');
});

t_test('mail: nagłówka nie da się wstrzyknąć znakiem końca linii', function () {
    // W SMTP pusta linia kończy blok nagłówków i zaczyna treść, więc CR/LF
    // w wartości nagłówka pozwoliłby dopisać własne nagłówki albo podmienić
    // całą wiadomość. Adres wypisu budujemy u siebie, ale przechodzi przez ten
    // sam kanał co wszystko inne i kiedyś przestanie być jedynym nagłówkiem.
    $metoda = new \ReflectionMethod(\Core\Mailer::class, 'formatHeaders');
    $metoda->setAccessible(true);
    $out = $metoda->invoke(null, [
        'List-Unsubscribe' => "<https://example.test/x>\r\nBcc: ofiara@example.test",
        'Zly Naglowek'     => 'wartosc',
    ]);

    t_true(substr_count($out, "\r\n") === 2, 'każdy nagłówek to dokładnie jedna linia');
    t_true(!preg_match('/^Bcc:/m', $out), 'CR/LF z wartości nie przemyca drugiego nagłówka');
    t_true(str_contains($out, 'ZlyNaglowek: wartosc'), 'nazwa czyszczona ze znaków spoza [A-Za-z0-9-]');
});

t_test('dopasowania: własny cooldown zniknął — liczy się budżet i klucz rzeczy', function () {
    // Do Etapu 1c ten plik pilnował się sam (7 i 30 dni), a bramka o tych
    // mailach nie wiedziała — więc jej limit „2 zachęty tygodniowo" był
    // deklaracją, nie faktem. Test broni tej zmiany przed cofnięciem:
    // powrót osobnego licznika dni to powrót dwóch prawd naraz.
    $src = (string) file_get_contents(CORE_PATH . '/Models/PreferenceNotifier.php');
    t_true(!str_contains($src, 'REGULAR_COOLDOWN_DAYS'), 'zwykły cooldown usunięty');
    t_true(!str_contains($src, 'ASPIRATIONAL_COOLDOWN_DAYS'), 'aspiracyjny cooldown usunięty');
    t_true(str_contains($src, 'Notifier::wyslij'), 'wysyłka idzie przez most');

    // Ten sam wyjazd nie wróci już nigdy — klucz identyfikuje RZECZ, nie datę.
    $user = t_user(1);
    Brama::ustawZgode($user, 'notify_matches', true); // patrz test wyżej: domyślnie wyłączona
    t_not_null(Brama::claim($user, Brama::DOPASOWANIE, 'ev:777', Brama::MAIL), 'pierwsza podpowiedź');
    t_null(Brama::claim($user, Brama::DOPASOWANIE, 'ev:777', Brama::MAIL), 'ten sam wyjazd drugi raz — nie');
});

/* ========================================================================
   PANEL POWIADOMIEŃ (2026-09-11; migr. 083)
   ========================================================================
   Zgłoszenie usera: „jakie, w jakim czasie, jak często" ma być zarządzalne.
   Testy niżej pilnują dwóch rzeczy naraz: że panel FAKTYCZNIE zmienia
   zachowanie (a nie tylko zapisuje liczbę do tabeli) i że nieruszany panel
   zachowuje się dokładnie jak system przed migracją 083.

   Cache ustawień jest statyczny, a uruchamiacz wycofuje transakcję po każdym
   teście — dlatego każdy test zaczyna od `forget()`. Bez tego druga połowa
   pliku czytałaby wartości, których w bazie już nie ma.
   ======================================================================== */

use Models\NotificationSettings as Ust;

t_test('panel: nieruszany zachowuje się dokładnie jak system przed migracją', function () {
    Ust::forget();
    // Domyślne w kodzie MUSZĄ zgadzać się ze stałymi, których używały testy
    // i kod przed panelem — inaczej sam fakt wdrożenia migracji 083 po cichu
    // zmieniłby częstotliwość powiadomień na produkcji.
    t_same(Brama::BUDZET_TYDZIEN, Ust::getInt('budzet.push.tydzien'), 'tygodniowy budżet pusha = stała klasy');
    t_same(Brama::BUDZET_DOBA, Ust::getInt('budzet.push.doba'), 'dobowy budżet pusha = stała klasy');
    t_same(Brama::CISZA_OD, Ust::getInt('cisza.od'), 'początek ciszy = stała klasy');
    t_same(Brama::CISZA_DO, Ust::getInt('cisza.do'), 'koniec ciszy = stała klasy');
    // Okno wysyłki domyślnie obejmuje całą dobę, czyli „decyduje sam crontab".
    foreach ([0, 6, 13, 21, 23] as $g) {
        t_true(Brama::poraNaZachety($g), "domyślnie godzina $g jest w oknie wysyłki");
    }
});

t_test('panel: zmiana budżetu realnie zmienia to, ile wychodzi', function () {
    Ust::forget();
    $user = t_user(2);
    if (Brama::ciszaNocna()) {
        t_true(true, 'pominięte — trwa cisza nocna (budżet sprawdzany poza nią)');
        return;
    }

    // Domyślnie 1/dobę, więc druga zachęta odpada. Podniesienie limitu
    // w panelu ma ją przepuścić — i to jest cały sens tego ekranu.
    Ust::set('budzet.push.doba', 3.0, null);
    Ust::set('budzet.push.tydzien', 3.0, null);

    t_not_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:901', Brama::PUSH), 'pierwsza');
    t_not_null(Brama::claim($user, Brama::NOWOSC_W_OKOLICY, 'kr:901', Brama::PUSH), 'druga — przeszła dzięki panelowi');
    t_not_null(Brama::claim($user, Brama::POSTEP, 'kr:902', Brama::PUSH), 'trzecia');
    t_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:903', Brama::PUSH), 'czwarta odpada — limit to teraz 3');
});

t_test('panel: budżet 0 znaczy „żadnych zachęt", ale transakcyjne idą dalej', function () {
    Ust::forget();
    $user = t_user(0);
    Ust::set('budzet.mail.doba', 0.0, null);
    Ust::set('budzet.mail.tydzien', 0.0, null);

    t_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:911', Brama::MAIL), 'zachęta mailem zatrzymana');
    // Gdyby zero wyciszało też transakcyjne, admin gaszący zachęty odciąłby
    // ludziom powiadomienia o wiadomościach — i dowiedziałby się o tym
    // najwcześniej ze skargi.
    t_not_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:911', Brama::MAIL), 'wiadomość dalej przechodzi');
});

t_test('panel: wyłącznik rodzaju gasi go i NIE zajmuje miejsca w dzienniku', function () {
    Ust::forget();
    $user = t_user(0);
    Ust::set('typ.treasure_nearby', 0.0, null);

    t_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:921', Brama::MAIL), 'wyłączony rodzaj nie przechodzi');
    t_not_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:921', Brama::MAIL), 'inne rodzaje bez zmian');

    // NAJWAŻNIEJSZA ASERCJA TEGO TESTU. Gdyby wpis do dziennika powstawał mimo
    // wyłączenia, deduplikacja uznałaby te powiadomienia za już wysłane —
    // i po ponownym włączeniu rodzaju NIKT by ich nie dostał. Cicho.
    Ust::set('typ.treasure_nearby', 1.0, null);
    t_not_null(
        Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:921', Brama::MAIL),
        'po ponownym włączeniu to samo powiadomienie wychodzi normalnie'
    );
});

t_test('panel: wyłącznik kanału gasi jeden kanał, drugi zostawia', function () {
    Ust::forget();
    $user = t_user(0);
    Ust::set('kanal.mail', 0.0, null);

    t_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:931', Brama::MAIL), 'kanał mailowy wyłączony');
    t_not_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:931', Brama::PUSH), 'push działa niezależnie');
});

t_test('panel: okno wysyłki zawęża porę zachęt, nie ruszając transakcyjnych', function () {
    Ust::forget();
    $user = t_user(1);
    $teraz = (int) date('G');

    // Okno ustawione OBOK bieżącej godziny — zachęty mają zamilknąć.
    $od = ($teraz + 2) % 24;
    $do = ($teraz + 4) % 24;
    Ust::set('okno.od', (float) $od, null);
    Ust::set('okno.do', (float) $do, null);

    t_false(Brama::poraNaZachety($teraz), 'bieżąca godzina jest poza oknem');
    t_null(Brama::claim($user, Brama::SKARB_W_OKOLICY, 'tre:941', Brama::MAIL), 'zachęta czeka na swoją porę');
    // Okno to reguła o ZACZEPIANIU, nie o wszystkich powiadomieniach — nikt
    // nie chce dostać wiadomości od organizatora o trzy godziny później.
    t_not_null(Brama::claim($user, Brama::WIADOMOSC, 'msg:941', Brama::MAIL), 'wiadomość idzie od razu');

    // Okno „przez północ" (np. 23→2) musi działać jako suma dwóch kawałków,
    // inaczej każda taka para znaczyłaby po cichu „nigdy".
    Ust::set('okno.od', 23.0, null);
    Ust::set('okno.do', 2.0, null);
    t_true(Brama::poraNaZachety(23), 'godzina 23 mieści się w oknie 23→2');
    t_true(Brama::poraNaZachety(1), 'godzina 1 też');
    t_false(Brama::poraNaZachety(12), 'południe już nie');
});

t_test('panel: cisza nocna bierze godziny z ustawień', function () {
    Ust::forget();
    Ust::set('cisza.od', 20.0, null);
    Ust::set('cisza.do', 9.0, null);

    t_true(Brama::ciszaNocna(20), 'nowy początek ciszy obowiązuje');
    t_true(Brama::ciszaNocna(8), 'nowy koniec ciszy obowiązuje');
    t_false(Brama::ciszaNocna(19), 'godzina przed ciszą jest wolna');
    t_false(Brama::ciszaNocna(9), 'godzina końca już jest wolna');

    // Odwrotne ustawienie („milcz w ciągu dnia") jest dziwne, ale sensowne —
    // i ma działać, zamiast po cichu znaczyć „cisza zawsze" albo „nigdy".
    Ust::set('cisza.od', 9.0, null);
    Ust::set('cisza.do', 17.0, null);
    t_true(Brama::ciszaNocna(12), 'cisza w ciągu dnia obowiązuje w południe');
    t_false(Brama::ciszaNocna(22), 'a noc jest wtedy wolna');
});

t_test('panel: pusta wartość wraca do domyślnej, a zakres jest twardy', function () {
    Ust::forget();
    Ust::set('cisza.od', 18.0, null);
    t_same(18, Ust::getInt('cisza.od'), 'nadpisanie działa');
    t_true(array_key_exists('cisza.od', Ust::all()), 'i widać je jako zmienione');

    // Powrót do domyślnej NIE WYMAGA znajomości liczby — to jest cała zaleta
    // wzorca z `ScoringSettings` i powód, dla którego panel pokazuje „wyczyść
    // pole, żeby wrócić".
    Ust::set('cisza.od', null, null);
    t_same(Brama::CISZA_OD, Ust::getInt('cisza.od'), 'po skasowaniu wraca wartość z kodu');
    t_false(array_key_exists('cisza.od', Ust::all()), 'i znika z listy zmienionych');

    // Zakres jest przycinany w modelu, nie w formularzu: ekran jest jednym
    // z wejść, a „godzina jest z doby" to reguła domenowa.
    Ust::set('cisza.od', 99.0, null);
    t_same(23, Ust::getInt('cisza.od'), 'godzina przycięta do 23');
    Ust::set('budzet.push.tydzien', -5.0, null);
    t_same(0, Ust::getInt('budzet.push.tydzien'), 'ujemny budżet przycięty do zera');
});

t_test('panel: nieznany klucz nie zapisuje się i nie daje się odczytać', function () {
    Ust::forget();
    Ust::set('wymyslony.parametr', 7.0, null);
    t_false(array_key_exists('wymyslony.parametr', Ust::all()), 'nieznany klucz nie trafia do tabeli');

    $rzucil = false;
    try {
        Ust::get('wymyslony.parametr');
    } catch (\InvalidArgumentException $e) {
        $rzucil = true;
    }
    // Biała lista, nie czarna — pierwsza literówka w panelu ma się zatrzymać
    // tutaj, a nie zamienić w wiersz, którego nikt już nigdy nie odczyta.
    t_true($rzucil, 'odczyt nieznanego parametru wybucha');
});

/* ========================================================================
   TREŚCI POWIADOMIEŃ (2026-09-11; migr. 084)
   ========================================================================
   Edytowalne treści są jedyną rzeczą w tym module, która pozwala człowiekowi
   z panelu wpłynąć na to, co pójdzie do cudzego telefonu i skrzynki. Testy
   pilnują więc przede wszystkim GRANICY: czego treść z bazy nie może zdradzić,
   nawet gdy ktoś bardzo chce.
   ======================================================================== */

use Models\NotificationTexts as Tekst;

t_test('treści: nieruszane brzmią dokładnie tak jak przed migracją 084', function () {
    Tekst::forget();
    // Gdyby domyślne rozjechały się z tym, co wysyłał kod, sama migracja
    // po cichu zmieniłaby brzmienie powiadomień na produkcji.
    t_same('Nowy skarb w Twojej okolicy', Tekst::get('treasure_nearby.push.title.jawny'), 'tytuł pusha o skarbie');
    t_same('Nowa wiadomość', Tekst::get('message.push.title'), 'tytuł pusha o wiadomości');
    t_same('Masz nową wiadomość na Ridemore.', Tekst::get('message.push.body'), 'treść pusha o wiadomości');
    t_same('Pojawił się wyjazd, który może Cię zainteresować', Tekst::get('match.mail.subject'), 'temat maila o dopasowaniu');
});

t_test('treści: znacznik {nazwa} NIE ISTNIEJE w wariancie dla skarbu ukrytego', function () {
    Tekst::forget();
    // TO JEST NAJWAŻNIEJSZY TEST TEGO PLIKU. Poziom ujawnienia skarbu jest
    // regułą prywatności gry (`Treasure::reveal()`): kto nie odkrył pola, nie
    // poznaje nazwy — ani z mapy, ani z powiadomienia. Edytowalna treść to
    // jedyne miejsce, w którym dałoby się tę regułę obejść z formularza.
    $usuniete = Tekst::set('treasure_nearby.push.body.ukryty', 'W okolicy pojawił się {nazwa}!', null);
    t_true(in_array('nazwa', $usuniete, true), 'zapis melduje, że odrzucił {nazwa}');
    t_true(!str_contains(Tekst::get('treasure_nearby.push.body.ukryty'), '{nazwa}'), 'i nie ma go w zapisanej treści');

    // Druga warstwa: nawet gdyby ktoś wpisał znacznik wprost do bazy (omijając
    // `set()`), renderowanie i tak go nie podstawi — biała lista jest per pole.
    \Core\Database::connection()->prepare(
        'INSERT INTO notification_texts (text_key, text_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE text_value = VALUES(text_value)'
    )->execute(['k' => 'treasure_nearby.push.body.ukryty', 'v' => 'Skarb: {nazwa} w {region}']);
    Tekst::forget();

    $wynik = Tekst::render('treasure_nearby.push.body.ukryty', [
        'nazwa'  => 'Tajna Kapliczka',
        'region' => 'małopolskie',
    ]);
    t_true(!str_contains($wynik, 'Tajna Kapliczka'), 'nazwa nieodkrytego skarbu NIE WYCHODZI w treści');
    t_true(!str_contains($wynik, '{nazwa}'), 'i nie zostaje po niej goły znacznik');
    t_true(str_contains($wynik, 'małopolskie'), 'region, który wolno pokazać, podstawia się normalnie');
});

t_test('treści: w wariancie dla skarbu jawnego nazwa podstawia się normalnie', function () {
    Tekst::forget();
    // Lustro testu wyżej — bo zabezpieczenie, które blokuje wszystko, jest
    // równie bezużyteczne co brak zabezpieczenia.
    $wynik = Tekst::render('treasure_nearby.push.body.jawny', ['nazwa' => 'Kapliczka', 'region' => 'śląskie']);
    t_same('Kapliczka', $wynik, 'poziom 2 pokazuje nazwę — tak samo jak mapa');
});

t_test('treści: HTML nie przechodzi przez zapis', function () {
    Tekst::forget();
    // Teksty idą jako ZWYKŁY TEKST i są escape'owane w szablonach, więc `<a>`
    // dotarłby do człowieka dosłownie. Odcinamy go przy zapisie, żeby nie
    // trzeba było tego tłumaczyć w każdym szablonie z osobna.
    Tekst::set('message.push.body', 'Masz wiadomość <a href="http://zly.example">kliknij</a>', null);
    $zapisane = Tekst::get('message.push.body');
    t_true(!str_contains($zapisane, '<a'), 'znacznik HTML wycięty');
    t_true(str_contains($zapisane, 'kliknij'), 'sam tekst zostaje');
});

t_test('treści: pusty tekst i tekst równy domyślnemu wracają do oryginału', function () {
    Tekst::forget();
    Tekst::set('message.push.title', 'Masz wiadomość!', null);
    t_true(array_key_exists('message.push.title', Tekst::all()), 'zmiana jest zapisana');

    Tekst::set('message.push.title', '', null);
    t_same('Nowa wiadomość', Tekst::get('message.push.title'), 'pusty tekst przywraca oryginał');
    t_false(array_key_exists('message.push.title', Tekst::all()), 'i kasuje wiersz');

    // Wpisanie ręcznie tego samego, co w kodzie, też nie jest nadpisaniem —
    // inaczej późniejsza zmiana brzmienia w kodzie nie dotarłaby do nikogo,
    // kto raz kliknął „Zapisz". Ten sam problem co przy liczbach w migr. 083.
    Tekst::set('message.push.title', 'Nowa wiadomość', null);
    t_false(array_key_exists('message.push.title', Tekst::all()), 'tekst identyczny z domyślnym nie jest nadpisaniem');
});

t_test('treści: puste znaczniki nie zostawiają śmieci w zdaniu', function () {
    Tekst::forget();
    // Skarb bez regionu to normalny przypadek (kolumna bywa pusta), a zdanie
    // „W okolicy, którą już odkrywasz ( ), pojawiło się…" wyglądałoby jak błąd
    // aplikacji — bo nim jest.
    $wynik = Tekst::render('treasure_nearby.mail.body.ukryty', ['region' => ''], Tekst::blokiPrzykladowe('treasure_nearby'));
    t_true(!str_contains($wynik, '()'), 'pusty nawias po regionie znika');
    t_true(!str_contains($wynik, 'odkrywasz  ,'), 'nie zostaje podwójna spacja w zdaniu');
    t_true(str_contains($wynik, 'pojawiło się coś nowego'), 'reszta zdania jest nietknięta');
});

t_test('treści: podgląd w panelu liczy się tą samą metodą co wysyłka', function () {
    Tekst::forget();
    // Podgląd, który idzie inną drogą niż wysyłka, prędzej czy później zacznie
    // pokazywać coś, czego nikt nie dostanie — a wtedy jest gorszy niż brak.
    t_same(
        Tekst::render('treasure_nearby.mail.body.jawny', Tekst::PRZYKLAD, Tekst::blokiPrzykladowe('treasure_nearby')),
        Tekst::podglad('treasure_nearby.mail.body.jawny'),
        'podgląd = render na danych i blokach przykładowych'
    );
    t_true(str_contains(Tekst::podglad('event_join.push.title'), 'dołączył'), 'podgląd zwraca gotowe zdanie');
});

t_test('treści: nieznany klucz wybucha przy odczycie i jest ignorowany przy zapisie', function () {
    Tekst::forget();
    t_same([], Tekst::set('wymyslony.tekst', 'cokolwiek', null), 'nieznany klucz nie zapisuje się');
    t_false(array_key_exists('wymyslony.tekst', Tekst::all()), 'i nie ma go w tabeli');

    $rzucil = false;
    try {
        Tekst::get('wymyslony.tekst');
    } catch (\InvalidArgumentException $e) {
        $rzucil = true;
    }
    t_true($rzucil, 'odczyt nieznanego tekstu rzuca');
});

/* ========================================================================
   HTML I BLOKI W TREŚCI MAILA (2026-09-11, migr. 084 — druga runda)
   ========================================================================
   User poprosił wprost o edycję HTML z podglądem. HTML w mailu wychodzącym
   w imieniu serwisu to realne ryzyko, więc testy niżej pilnują granicy:
   co wolno zostawić, co musi zniknąć i czym różni się DANA od BLOKU.
   ======================================================================== */

t_test('html: formatowanie zostaje, skrypty i zdarzenia znikają', function () {
    Tekst::forget();
    $wynik = Tekst::oczyscHtml(
        '<p>Tekst <b>gruby</b> i <i>pochyły</i></p>'
        . '<script>alert(1)</script>'
        . '<iframe src="http://zly.example"></iframe>'
        . '<p onclick="kradnij()">z uchwytem</p>'
    );

    t_true(str_contains($wynik, '<b>gruby</b>'), 'pogrubienie zostaje');
    t_true(str_contains($wynik, '<i>pochyły</i>'), 'kursywa zostaje');
    t_true(!str_contains($wynik, '<script'), 'skrypt wycięty');
    t_true(!str_contains($wynik, '<iframe'), 'ramka wycięta');
    // `strip_tags` z białą listą ZOSTAWIA atrybuty, więc sam nie wystarcza —
    // to jest dokładnie ta pułapka, dla której istnieje drugi krok.
    t_true(!str_contains($wynik, 'onclick'), 'uchwyt zdarzenia wycięty razem z atrybutami');
});

t_test('html: link przeżywa tylko z adresem http(s) albo blokiem', function () {
    Tekst::forget();
    $ok = Tekst::oczyscHtml('<a href="https://ridemore.bike/trasy">trasy</a>');
    t_true(str_contains($ok, 'href="https://ridemore.bike/trasy"'), 'zwykły link zostaje');

    // `javascript:` w mailu nic by nie zrobił, ale w podglądzie w panelu już
    // tak — a panel renderuje dokładnie to, co pójdzie do ludzi.
    $zly = Tekst::oczyscHtml('<a href="javascript:alert(1)">kliknij</a>');
    t_true(!str_contains($zly, 'javascript'), 'adres javascript: odcięty');
    t_true(str_contains($zly, 'kliknij'), 'sam tekst linku zostaje');

    $blok = Tekst::oczyscHtml('<a href="{przycisk}">x</a>');
    t_true(str_contains($blok, '{przycisk}'), 'znacznik jako adres jest dozwolony');
});

t_test('html: DANA jest escape\'owana, BLOK wstawiany jako HTML', function () {
    Tekst::forget();
    // To jest sedno rozróżnienia. Nazwa wyjazdu pochodzi od CZŁOWIEKA (kto
    // założył wydarzenie), więc nie ma prawa wnieść do maila własnego HTML-a.
    // Blok pochodzi od NAS i musi zostać HTML-em, bo inaczej przycisk byłby
    // tekstem `<a href=…>` w środku wiadomości.
    $wynik = Tekst::render(
        'message_group.mail.body',
        ['wyjazd' => '<b>Wyjazd</b>', 'nadawca' => '<script>x</script>Anna'],
        ['powitanie' => '<p>Cześć!</p>', 'cytat' => '<p>cytat</p>', 'przycisk' => '<a href="https://x.test">Otwórz</a>']
    );

    t_true(!str_contains($wynik, '<script>'), 'HTML z danych nie przechodzi');
    t_true(str_contains($wynik, '&lt;script&gt;'), 'jest widoczny jako tekst');
    t_true(str_contains($wynik, '<a href="https://x.test">Otwórz</a>'), 'blok zostaje HTML-em');
});

t_test('push: treść nigdy nie niesie HTML-a ani bloków', function () {
    Tekst::forget();
    // Ekran blokady telefonu nie renderuje HTML-a, a przycisk nie ma się gdzie
    // pokazać. Gdyby znacznik przeciekł, człowiek zobaczyłby `<b>` dosłownie.
    Tekst::set('message_group.push.title', 'Wiadomość: <b>{wyjazd}</b>', null);
    $wynik = Tekst::render('message_group.push.title', ['wyjazd' => 'Beskidy'], ['przycisk' => '<a>x</a>'], true);

    t_true(!str_contains($wynik, '<'), 'w pushu nie ma żadnego znacznika');
    t_true(str_contains($wynik, 'Beskidy'), 'sama wartość jest na miejscu');
});

t_test('treści: warianty skarbu mają różne listy znaczników', function () {
    Tekst::forget();
    // Lustro testu o wycieku nazwy, ale od strony definicji: to `znacznikiDla()`
    // decyduje, co wolno podstawić, i panel rysuje przyciski z tej samej listy.
    t_true(in_array('nazwa', Tekst::znacznikiDla('treasure_nearby.mail.body.jawny'), true),
        'wariant jawny zna {nazwa}');
    t_false(in_array('nazwa', Tekst::znacznikiDla('treasure_nearby.mail.body.ukryty'), true),
        'wariant ukryty NIE zna {nazwa}');
    t_true(in_array('region', Tekst::znacznikiDla('treasure_nearby.mail.body.ukryty'), true),
        'ale region jest dozwolony w obu');
});

t_test('treści: każde powiadomienie z definicji ma komplet treści dla swoich kanałów', function () {
    Tekst::forget();
    // Brakująca treść nie wywala wysyłki od razu — `render()` rzuca dopiero
    // przy próbie użycia, czyli w cronie, w nocy, do logu. Ten test zamienia
    // to na czerwony wynik tutaj.
    foreach (Tekst::POWIADOMIENIA as $kod => $def) {
        $warianty = array_keys($def['warianty'] ?? [null => null]);
        foreach ($def['kanaly'] as $kanal) {
            foreach ($kanal === 'push' ? ['title', 'body'] : ['subject', 'body'] as $pole) {
                foreach ($warianty as $wariant) {
                    $klucz = Tekst::klucz($kod, $kanal, $pole, $wariant);
                    t_not_null(Tekst::domyslny($klucz), "$klucz ma treść domyślną");
                }
            }
        }
    }
});

t_test('treści: CRLF z formularza nie robi z nietkniętego pola nadpisania', function () {
    Tekst::forget();
    // ZNALEZIONE NA ŻYWO, nie przez lekturę. Przeglądarka zwraca z `<textarea>`
    // końce linii jako CRLF, a treści domyślne w kodzie mają LF — więc
    // porównanie „tekst równy domyślnemu" nigdy nie wychodziło prawdą
    // i JEDEN zapis formularza robił nadpisania ze WSZYSTKICH treści, także
    // tych, których nikt nie dotknął. Skutek jest cichy i kosztowny: późniejsza
    // poprawka brzmienia w kodzie nie dotarłaby już do nikogo, kto raz kliknął
    // „Zapisz". Ten sam błąd co przy liczbach w migr. 083, tylko innym wejściem.
    $domyslna = (string) Tekst::domyslny('message.mail.body');
    t_true(str_contains($domyslna, "\n"), 'treść domyślna jest wielolinijkowa (inaczej test nic nie dowodzi)');

    Tekst::set('message.mail.body', str_replace("\n", "\r\n", $domyslna), null);
    t_false(array_key_exists('message.mail.body', Tekst::all()), 'ta sama treść w CRLF nie jest zmianą');

    // A prawdziwa zmiana dalej ma się zapisywać — zabezpieczenie, które
    // odrzuca wszystko, byłoby równie złe.
    Tekst::set('message.mail.body', "{powitanie}\r\n<p>Coś nowego</p>", null);
    t_true(array_key_exists('message.mail.body', Tekst::all()), 'realna zmiana zapisuje się normalnie');
    t_true(!str_contains(Tekst::get('message.mail.body'), "\r"), 'i ląduje w bazie z LF, nie CRLF');
});

t_test('panel: rodzaj, którego formularz nie pokazuje, NIE gaśnie przy zapisie', function () {
    Ust::forget();
    // ZNALEZIONE NA ŻYWO, po kliknięciu „Zapisz" w panelu. Rodzaje bez nadawcy
    // (`nearby_new`, `progress`) nie mają swojego wiersza na ekranie, bo ekran
    // pokazuje tylko to, co naprawdę wychodzi. Niezaznaczony checkbox i checkbox
    // NIEISTNIEJĄCY wyglądają w POST identycznie — oba to brak klucza — więc
    // pierwszy zapis formularza wyłączał te rodzaje po cichu. Nikt by tego nie
    // zauważył aż do dnia, w którym dostaną nadawcę i „z niewiadomych powodów"
    // nic nie będzie wychodzić.
    //
    // Test sprawdza REGUŁĘ w kontrolerze, bo to tam jest rozstrzygnięcie:
    // zapisujemy tylko te przełączniki, przy których przyszedł znacznik
    // obecności pola.
    $src = (string) file_get_contents(CORE_PATH . '/Controllers/Admin/NotificationsController.php');
    t_true(
        (bool) preg_match("/if \(!array_key_exists\('obecne_' \. \\\$pole, \\\$_POST\)\) \{\s*continue;/", $src),
        'zapis pomija przełączniki, których formularz nie renderował'
    );

    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/notifications-admin.php');
    t_same(2, substr_count($widok, 'name="obecne_'), 'każdy renderowany przełącznik niesie znacznik obecności');

    // I kontrola od strony zachowania: typ bez wiersza w tabeli ustawień jest
    // WŁĄCZONY, bo brak nadpisania znaczy „wartość domyślna", a ta jest 1.
    t_true(Brama::wlaczony(Brama::NOWOSC_W_OKOLICY, Brama::PUSH), 'rodzaj bez ustawienia jest włączony');
    t_true(Brama::wlaczony(Brama::POSTEP, Brama::MAIL), 'tak samo w kanale mailowym');
});

/* ========================================================================
   DOKĄD PROWADZĄ PRZYCISKI (2026-09-11, zgłoszenie usera)
   ========================================================================
   „przyciski w wiadomościach źle kierują bo wiadomość kieruje do mapy odkryć.
   tak samo do skarbu nie kieruje tylko do odkryć (…) Dopasowany wyjazd również
   kieruje do odkryć a nie do konkretnego dopasowanego eventu."

   Przyczyna była w PODGLĄDZIE, nie w wysyłce: `blokiPrzykladowe()` miało jeden
   wspólny przycisk dla wszystkich powiadomień, więc panel pokazywał „Zobacz na
   mapie → /odkrycia" nawet przy mailu o wiadomości. Podgląd, który pokazuje
   inny cel niż wysyłka, jest gorszy niż jego brak — stąd te testy.
   ======================================================================== */

t_test('linki: podgląd każdego powiadomienia prowadzi TAM, GDZIE prowadzi wysyłka', function () {
    Tekst::forget();
    $cele = [
        'message'            => '/wiadomosci/',
        'message_group'      => '/wiadomosci/grupa/',
        'event_join'         => '/uczestnicy',
        'match'              => '/events/',
        'match_aspirational' => '/events/',
        'treasure_nearby'    => '/odkrycia?skarb=',
    ];
    foreach ($cele as $kod => $fragment) {
        $przycisk = Tekst::blokiPrzykladowe($kod)['przycisk'];
        t_true(str_contains($przycisk, $fragment), "podgląd „$kod\" celuje w $fragment");
    }

    // I odwrotnie: żaden z nich (poza skarbem) nie ma prawa prowadzić na mapę.
    foreach (['message', 'message_group', 'event_join', 'match'] as $kod) {
        t_false(
            str_contains(Tekst::blokiPrzykladowe($kod)['przycisk'], '/odkrycia'),
            "„$kod\" nie prowadzi już do mapy odkryć"
        );
    }
});

t_test('linki: zapis na wyjazd prowadzi do listy uczestników, nie na pulpit', function () {
    // Organizator dostaje to powiadomienie po to, żeby zobaczyć, KTO doszedł.
    // Pulpit kazał mu szukać wyjazdu samemu — a przy kilku wyjazdach naraz to
    // jest dokładnie ta jedna informacja, której powiadomienie nie niosło.
    $src = (string) file_get_contents(CORE_PATH . '/Models/EventRsvp.php');
    t_true(str_contains($src, "'/wydarzenia/' . \$row['slug'] . '/uczestnicy'"), 'push celuje w listę uczestników');
    t_true(!str_contains($src, "\\Utils\\View::url('/admin')]"), 'stary link na pulpit zniknął');
    // Bez `slug` w zapytaniu powyższe byłoby ścieżką do `/wydarzenia//uczestnicy`.
    t_true(str_contains($src, 'SELECT e.organizer_id, e.title, e.slug FROM events e'), 'zapytanie dociąga slug');
});

t_test('linki: skarb JAWNY otwiera mapę na sobie, UKRYTY tylko gołą mapę', function () {
    // Mapa odkryć JEST miejscem skarbu — własnej strony nie ma. Ale może się
    // otworzyć wycentrowana (`DiscoveryController::kadrNaSkarbie`). Przy skarbie
    // ukrytym adres z identyfikatorem byłby wskazówką, gdzie szukać czegoś,
    // czego mapa celowo nie pokazuje — więc go nie ma.
    $src = (string) file_get_contents(CORE_PATH . '/Models/PushNotifier.php');
    t_true(
        str_contains($src, "'/odkrycia' . (\$ujawniony ? '?skarb=' . (int) \$treasure['id'] : '')"),
        'identyfikator skarbu trafia do adresu tylko przy wariancie jawnym'
    );

    $ctrl = (string) file_get_contents(CORE_PATH . '/Controllers/DiscoveryController.php');
    t_true(str_contains($ctrl, 'reveal_level >= 2'), 'kontroler oddaje kadr wyłącznie dla skarbu jawnego');
    t_true(str_contains($ctrl, "'south' =>"), 'kadr ma kształt zgodny z DiscoveryGrid::boundsFromAxialExtremes');
});

t_test('karta wyjazdu: niesie fakty, a puste pola po prostu znikają', function () {
    // „Powinno być więcej informacji o tym evencie" — karta odpowiada na to,
    // czego brakowało: kiedy, gdzie, jak daleko i czy ktoś już jedzie.
    $pelna = Tekst::blokKarta('Weekend gravelowy', '18 października', 'małopolskie', 124.5, 6);
    foreach (['Weekend gravelowy', '18 października', 'małopolskie', '124,5 km', '6 osób już jedzie'] as $fragment) {
        t_true(str_contains($pelna, $fragment), "karta niesie: $fragment");
    }

    // Wyjazd bez policzonego dystansu nie może pokazać „0 km", a bez zapisanych
    // osób — „0 osób już jedzie". Brak danej to brak wiersza, nie zero.
    $chuda = Tekst::blokKarta('Sam tytuł', null, null, null, 0);
    t_true(str_contains($chuda, 'Sam tytuł'), 'tytuł zostaje');
    t_true(!str_contains($chuda, '0 km') && !str_contains($chuda, '0 osób'), 'puste fakty nie wychodzą jako zera');

    // Polska odmiana liczebnika — 1/2–4/5+.
    t_true(str_contains(Tekst::blokKarta('x', null, null, null, 1), '1 osoba już jedzie'), 'jedna osoba');
    t_true(str_contains(Tekst::blokKarta('x', null, null, null, 3), '3 osoby już jadą'), 'trzy osoby');
    t_true(str_contains(Tekst::blokKarta('x', null, null, null, 12), '12 osób już jedzie'), 'dwanaście osób');
    t_true(str_contains(Tekst::blokKarta('x', null, null, null, 22), '22 osoby już jadą'), 'dwadzieścia dwie osoby');
});

t_test('karta wyjazdu: cudzy tytuł nie wnosi do maila własnego HTML-a', function () {
    // Tytuł wydarzenia pochodzi od człowieka, który je założył — a karta jest
    // blokiem, czyli jedynym miejscem, gdzie HTML NIE jest escape'owany przy
    // podstawianiu. Escape musi więc zdarzyć się tutaj, przy składaniu.
    $karta = Tekst::blokKarta('<script>alert(1)</script>Wyjazd', null, null, null, 0);
    t_true(!str_contains($karta, '<script>'), 'znacznik z tytułu nie przechodzi');
    t_true(str_contains($karta, '&lt;script&gt;'), 'jest widoczny jako tekst');
});

t_test('dopasowania: mail niesie kartę wyjazdu, nie sam powód', function () {
    Tekst::forget();
    foreach (['match', 'match_aspirational'] as $kod) {
        t_true(str_contains(Tekst::get($kod . '.mail.body'), '{karta}'), "„$kod\" ma kartę w treści domyślnej");
        t_true(in_array('karta', Tekst::blokiDla($kod), true), "„$kod\" pozwala wstawić {karta} w panelu");
    }
    // Silnik dopasowań musi oddać dane, z których karta powstaje — inaczej
    // byłaby pusta, a błąd wyszedłby dopiero w nocnym cronie.
    $src = (string) file_get_contents(CORE_PATH . '/Models/MatchEngine.php');
    foreach (["'startDate'      => \$candidate['startDate']", "'distanceKm'     => \$candidate['distanceKm']",
              "'confirmedCount' => \$candidate['confirmedCount']", "'regionItemIds'  => \$candidate['regionItemIds']"] as $pole) {
        t_true(str_contains($src, $pole), 'profileMatchesForUser oddaje ' . trim(explode('=>', $pole)[0]));
    }
});

// ─────────────────────────────────── HARMONOGRAM CRONA (2026-09-12)
//
// Zachęty nie wychodzą PUSHEM między 22:00 a 7:00, a `cron.php` chodził
// na produkcji w jednym wpisie o północy — czyli połowa pushowa dwóch zadań
// nie wyszła ani razu. Rozdzielenie na grupy `noc`/`dzien` jest naprawą tego,
// więc testy pilnują, żeby zadanie nie wróciło do złej grupy.

t_test('cron: zadania z pushem są WYŁĄCZNIE w grupie dziennej', function () {
    $src = (string) file_get_contents(CORE_PATH . '/../cron.php');

    // BLOKI, NIE POZYCJE OD POCZĄTKU PLIKU. Pierwsza wersja tego testu szukała
    // `strpos($src, 'DerivedPreference::recomputeAll(')` i spadała — bo trafiała
    // w nagłówek pliku, który wymienia te same nazwy w komentarzu tłumaczącym
    // podział. Test ma pytać o PRZYNALEŻNOŚĆ do bloku, więc kroimy źródło.
    $noc   = strpos($src, 'if ($noc) {');
    $dzien = strpos($src, 'if ($dzien) {');
    t_true($noc !== false && $dzien !== false && $dzien > $noc, 'plik ma obie grupy, nocną przed dzienną');

    $blokNocy   = substr($src, $noc, $dzien - $noc);
    $blokDnia   = substr($src, $dzien);

    // Oba zadania z połową PUSHOWĄ należą do grupy dziennej — i do żadnej innej.
    foreach (['PushNotifier::runTreasuresNearby', 'PushNotifier::runNewInRegion'] as $zadanie) {
        t_true(str_contains($blokDnia, $zadanie . '('), "$zadanie jest w grupie dziennej");
        t_false(str_contains($blokNocy, $zadanie . '('), "$zadanie NIE jest w grupie nocnej");
    }

    // A kanały mailowe zostają w nocnej — razem z przeliczeniem, z którego
    // korzystają w TYM SAMYM przebiegu.
    foreach (['DerivedPreference::recomputeAll', 'PreferenceNotifier::runRegular',
              'PreferenceNotifier::runAspirational', 'MatchEngine::runNightlyConsolidation'] as $zadanie) {
        t_true(str_contains($blokNocy, $zadanie . '('), "$zadanie jest w grupie nocnej");
        t_false(str_contains($blokDnia, $zadanie . '('), "$zadanie NIE wyjechał do grupy dziennej");
    }

    // Kolejność wewnątrz nocy: profil przeliczony PRZED wysyłką, która go czyta.
    t_true(
        strpos($blokNocy, 'DerivedPreference::recomputeAll(') < strpos($blokNocy, 'PreferenceNotifier::runRegular('),
        'profil wynikający liczy się przed kanałem zwykłym'
    );
});

t_test('cron: pierwsza linia wyjścia mówi, na jakim środowisku i bazie stoimy', function () {
    $src = (string) file_get_contents(CORE_PATH . '/../cron.php');

    // Zły APP_ENV w crontabie to najdroższa cicha usterka tego modułu: cron
    // łączy się z bazą 'dev', nic nie wychodzi i nikt tego nie widzi. Banner
    // czyni to widocznym w logu crona od razu (ten sam zwyczaj co
    // w run_migrations.php).
    t_true(str_contains($src, "'APP_ENV=' . APP_ENV"), 'banner podaje APP_ENV');
    t_true(str_contains($src, "APP_CONFIG['db']['name']"), 'banner podaje nazwę bazy');
    t_true(str_contains($src, "', grupa=' . \$grupa"), 'banner podaje uruchomioną grupę');
});

t_test('cron: nieudany przebieg kończy się kodem BŁĘDU, nie sukcesu', function () {
    // TO JEST NAJDROŻSZA CICHA USTERKA TEGO MODUŁU i była realna, nie
    // hipotetyczna. `set_exception_handler` kończy skrypt NORMALNIE, czyli
    // z kodem 0 — więc cron, który nie połączył się z bazą i nie wykonał ani
    // jednego zadania, meldował harmonogramowi SUKCES. A harmonogram na
    // hostingu powiadamia tylko przy kodzie niezerowym, więc brak
    // `APP_ENV=prod` w crontabie mógł trwać tygodniami bez jednego sygnału.
    // Zmierzone przed naprawą: `DB_NAME_DEV=<zła baza> php cron.php noc`
    // → kod wyjścia 0. Po naprawie → 1.
    $src = (string) file_get_contents(CORE_PATH . '/bootstrap.php');

    t_true(
        (bool) preg_match("/if \(PHP_SAPI === 'cli'\) \{[\s\S]{0,600}?exit\(1\);/", $src),
        'handler wyjątków kończy przebieg CLI kodem 1'
    );
    t_true(
        (bool) preg_match("/if \(PHP_SAPI === 'cli'\) \{[\s\S]{0,600}?fwrite\(STDERR/", $src),
        'i pisze przyczynę na STDERR, nie w JSON dla przeglądarki'
    );

    // Gałąź CLI musi stać PRZED `header()`/`http_response_code()` — inaczej
    // pod CLI leci ostrzeżenie „Cannot modify header information", które
    // zaśmieca log crona czymś, co nie jest przyczyną awarii.
    $pozCli = strpos($src, "if (PHP_SAPI === 'cli') {");
    $pozHdr = strpos($src, 'http_response_code(500)');
    t_true($pozCli !== false && $pozHdr !== false && $pozCli < $pozHdr, 'gałąź CLI wyprzedza nagłówki HTTP');

    // I kontrola od drugiej strony: ścieżka HTTP MUSI zostać nietknięta.
    t_true(str_contains($src, "header('Content-Type: application/json; charset=utf-8')"), 'odpowiedź HTTP dalej jest JSON-em');
    t_true(str_contains($src, "'error'   => 'Nieobsłużony wyjątek'"), 'i dalej niesie szczegóły w dev');
});

t_test('cron: dwa przebiegi tej samej grupy nie nachodzą na siebie', function () {
    $src = (string) file_get_contents(CORE_PATH . '/../cron.php');

    // Blokada PER GRUPA, nie globalna: wiszący przebieg nocny nie ma prawa
    // zabrać popołudniu jego pory.
    t_true(str_contains($src, "'/storage/cron-' . \$grupa . '.lock'"), 'plik blokady nazywa się od grupy');

    // LOCK_NB jest tu istotne — bez niego drugi przebieg CZEKAŁBY w kolejce
    // i wystartował o nieswojej porze, zamiast po prostu odpuścić.
    t_true(str_contains($src, 'LOCK_EX | LOCK_NB'), 'blokada nie czeka w kolejce');
    t_true(str_contains($src, 'exit(3)'), 'zajęta blokada ma własny kod wyjścia');

    // A niemożność ZAŁOŻENIA blokady nie może zatrzymać przebiegu: `flock`
    // bywa zawodny na sieciowych systemach plików, a blokada ma chronić przed
    // nachodzeniem, nie zostać nowym powodem, dla którego cron nie działa.
    t_true(
        (bool) preg_match('/\$uchwyt === false\) \{\s*fwrite\(STDERR/', $src),
        'brak uchwytu = ostrzeżenie, nie przerwanie'
    );
});

t_test('cron: nie da się go odpalić żądaniem HTTP', function () {
    $src = (string) file_get_contents(CORE_PATH . '/../cron.php');
    $ht  = (string) file_get_contents(CORE_PATH . '/../.htaccess');

    // DWA ZAMKI, bo każdy z nich osobno bywa zawodny: `.htaccess` może zostać
    // nadpisany przy wdrożeniu, a bramka w pliku zniknąć przy refaktorze.
    // Do 2026-09-12 nie było ŻADNEGO — `https://.../cron.php` był publicznym
    // spustem wysyłki maili i pushy.
    t_true(
        (bool) preg_match('/PHP_SAPI !== .cli./', $src),
        'cron.php ma własną bramkę CLI'
    );
    t_true(
        (bool) preg_match('/FilesMatch "\^\([^"]*\bcron\b[^"]*\)\\\.php\$"/', $ht),
        '.htaccess blokuje cron.php dostępem Apache'
    );
});
