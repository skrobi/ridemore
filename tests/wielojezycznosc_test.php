<?php
// tests/wielojezycznosc_test.php
// WIELOJĘZYCZNOŚĆ (tasks/active/wielojezycznosc.md) — kontrakt, na którym stoi
// reszta: polski bez prefiksu i bez zmian, /en/… jako prefiks liczony w JEDNYM
// miejscu (Core\Lang), wyjątki, które muszą zostać bez prefiksu (callbacki
// OAuth, webhooki, assety), słownik z polskim tekstem jako kluczem i tłumaczenie
// treści z bazy, które na driverze `log` jest przewidywalne.
//
// Każdy test przywraca język na polski — Lang::with() robi to sam nawet przy
// wyjątku, a testy poza with() ustawiają go z powrotem ręcznie.

use Core\Lang;
use Utils\Format;

t_test('prefiks: polski bez prefiksu, angielski z /en, ścieżka po odcięciu ta sama', function () {
    t_true(in_array('en', Lang::supported(), true), 'na dev włączony jest angielski');
    t_same(['pl', '/wydarzenia'], Lang::splitPath('/wydarzenia'), 'bez prefiksu = polski');
    t_same(['en', '/wydarzenia'], Lang::splitPath('/en/wydarzenia'), '/en/… = angielski');
    t_same(['en', '/'], Lang::splitPath('/en'), 'sam /en = strona główna');
    t_same(['pl', '/pl/wydarzenia'], Lang::splitPath('/pl/wydarzenia'), 'polski NIE ma prefiksu /pl');
    t_same(['pl', '/de/x'], Lang::splitPath('/de/x'), 'niewłączony język nie jest prefiksem');
    t_same(['pl', '/english'], Lang::splitPath('/english'), 'słowo zaczynające się od „en" to nie prefiks');
});

t_test('localizePath: idempotentne, zachowuje zapytanie i kotwicę, nie rusza polskiego', function () {
    t_eq('/wydarzenia?x=1', Lang::localizePath('/wydarzenia?x=1', 'pl'), 'polski bez zmian');
    t_eq('/en/wydarzenia?x=1#mapa', Lang::localizePath('/wydarzenia?x=1#mapa', 'en'), 'zapytanie i kotwica zostają');
    t_eq('/en/wydarzenia', Lang::localizePath('/en/wydarzenia', 'en'), 'drugi raz nie dokleja prefiksu');
    t_eq('/en', Lang::localizePath('/', 'en'), 'strona główna bez końcowego ukośnika');
});

t_test('wyjątki bez prefiksu: callbacki OAuth, webhooki, assety, pliki', function () {
    foreach ([
        '/auth/google/callback',
        '/auth/strava/callback',
        '/api/liczniki/polar/webhook',
        '/assets/js/ui.js',
        '/sitemap.xml',
    ] as $sciezka) {
        t_eq($sciezka, Lang::localizePath($sciezka, 'en'), "$sciezka zostaje bez prefiksu");
    }
});

t_test('Lang::with przywraca poprzedni język także po wyjątku', function () {
    Lang::set('pl');
    t_eq('en', Lang::with('en', static fn() => Lang::current()), 'w środku angielski');
    t_eq('pl', Lang::current(), 'po wyjściu polski');
    try {
        Lang::with('en', static function () { throw new RuntimeException('x'); });
    } catch (RuntimeException $e) {
    }
    t_eq('pl', Lang::current(), 'po wyjątku też polski');
});

t_test('__(): polski zwraca klucz bez zmian, angielski ze słownika, placeholdery wypełnione', function () {
    Lang::set('pl');
    t_eq('Szukaj', __('Szukaj'), 'polski = klucz');
    t_eq('Dzień 3', __('Dzień {n}', ['n' => 3]), 'placeholder po polsku');
    Lang::with('en', static function () {
        t_eq('Search', __('Szukaj'), 'angielski ze słownika');
        t_eq('Day 3', __('Dzień {n}', ['n' => 3]), 'placeholder po angielsku');
        t_eq('Search', __("  Szukaj\n "), 'białe znaki klucza nie mają znaczenia');
        t_eq('Tekst spoza słownika zostaje', __('Tekst spoza słownika zostaje'), 'brak wpisu = polski, nie pusto');
    });
});

t_test('__n(): polska reguła po polsku, angielska liczba mnoga po angielsku', function () {
    Lang::set('pl');
    t_eq('1 osoba zapisana', __n(1, '{n} osoba zapisana', '{n} osoby zapisane', '{n} osób zapisanych'), 'pl 1');
    t_eq('3 osoby zapisane', __n(3, '{n} osoba zapisana', '{n} osoby zapisane', '{n} osób zapisanych'), 'pl 3');
    t_eq('12 osób zapisanych', __n(12, '{n} osoba zapisana', '{n} osoby zapisane', '{n} osób zapisanych'), 'pl 12');
    Lang::with('en', static function () {
        t_eq('1 person signed up', __n(1, '{n} osoba zapisana', '{n} osoby zapisane', '{n} osób zapisanych'), 'en 1');
        t_eq('22 people signed up', __n(22, '{n} osoba zapisana', '{n} osoby zapisane', '{n} osób zapisanych'), 'en 22');
        t_eq('hexes', Format::plural(5, 'pole', 'pola', 'pól'), 'Format::plural też tłumaczy');
    });
});

t_test('Format: separatory liczb i miesiące w języku strony', function () {
    Lang::set('pl');
    $pl = Format::price(1890);
    Lang::with('en', static function () use ($pl) {
        t_eq('1,890 zł', Format::price(1890), 'angielski separator tysięcy');
        t_eq('12.5 km', Format::distance(12.5), 'angielska kropka dziesiętna');
        t_true($pl !== Format::price(1890), 'polski format różni się od angielskiego');
    });
    t_eq('12,5 km', Format::distance(12.5), 'polski przecinek dziesiętny');
});

t_test('Lang::guess rozpoznaje oczywisty polski i angielski, krótkie zostawia', function () {
    t_eq('pl', Lang::guess('Zbiórka na parkingu przy stacji, jedziemy razem w góry.'), 'polski');
    t_eq('en', Lang::guess('Meeting point is at the station and we ride to the hills.'), 'angielski');
    t_null(Lang::guess('Gravel 100'), 'za krótkie — bez zgadywania');
});

t_test('słownik: każdy tekst z kodu ma tłumaczenie, placeholdery się zgadzają', function () {
    $slownik = Lang::dictionary('en');
    $zly = [];
    foreach ($slownik as $pl => $en) {
        if (!is_string($pl) || !is_string($en)) {
            continue;
        }
        preg_match_all('/\{[a-z_]+\}|%[ds%]/u', $pl, $a);
        preg_match_all('/\{[a-z_]+\}|%[ds%]/u', $en, $b);
        sort($a[0]);
        sort($b[0]);
        if ($a[0] !== $b[0] || trim($en) === '') {
            $zly[] = $pl;
        }
    }
    t_same([], array_slice($zly, 0, 5), 'placeholdery zgodne i brak pustych tłumaczeń');

    $wynik = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CORE_PATH . '/../i18n.php') . ' missing en', $wynik);
    t_same([], array_slice($wynik, 0, 5), 'php i18n.php missing en — nic nie brakuje');
});

t_test('treść z bazy: polski wraca bez zmian, angielski przez tłumacza (driver log)', function () {
    if ((APP_CONFIG['translate']['driver'] ?? 'log') !== 'log') {
        t_true(true, 'pominięte — test zakłada driver log');
        return;
    }
    $pole = ['title' => 'Nocna pętla po lesie z ogniskiem na mecie i powrotem do miasta'];
    Lang::set('pl');
    t_same($pole, Models\ContentTranslation::fields($pole, 'test:1'), 'po polsku bez tłumaczenia');
    Lang::with('en', static function () use ($pole) {
        $en = Models\ContentTranslation::fields($pole, 'test:1');
        t_eq('[EN] ' . $pole['title'], $en['title'], 'driver log dokleja [EN]');
        $meta = Models\ContentTranslation::meta('test:1');
        t_true($meta['translated'] ?? false, 'meta: przetłumaczono');
        t_eq('pl', $meta['from'] ?? null, 'meta: z polskiego');
        $en2 = Models\ContentTranslation::fields(['title' => 'Evening ride to the lake and back with the club'], 'test:2');
        t_eq('Evening ride to the lake and back with the club', $en2['title'], 'angielski oryginał nie jest tłumaczony');
    });
});

t_test('kolejka tłumaczeń: wiersz pending to oryginał na stronie, cron go tłumaczy', function () {
    if ((APP_CONFIG['translate']['driver'] ?? 'log') !== 'log') {
        t_true(true, 'pominięte — test zakłada driver log');
        return;
    }
    $pdo = Core\Database::connection();
    $tekst = 'Kolejka: spokojna pętla po lesie z przerwą na kawę w schronisku ' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO content_translations (source_hash, target_lang, source_lang, source_text, origin, context)
                   VALUES (?, 'en', 'pl', ?, 'pending', 'test:kolejka')")->execute([sha1($tekst), $tekst]);

    Lang::with('en', static function () use ($tekst) {
        $r = Models\ContentTranslation::fields(['t' => $tekst], 'test:kolejka');
        t_eq($tekst, $r['t'], 'w kolejce — strona pokazuje oryginał');
        t_true(Models\ContentTranslation::meta('test:kolejka')['failed'] ?? false, 'meta: brak tłumaczenia (noindex)');
    });
    t_true(Models\ContentTranslation::pendingCount() >= 1, 'licznik kolejki widzi wiersz');

    $wynik = Models\ContentTranslation::processQueue(500);
    t_false($wynik['failed'], 'przebieg bez błędu');
    $row = $pdo->query("SELECT origin, translated_text, source_text FROM content_translations WHERE source_hash = '" . sha1($tekst) . "' AND target_lang = 'en'")->fetch();
    t_eq('machine', $row['origin'], 'po przebiegu: machine');
    t_eq('[EN] ' . $tekst, $row['translated_text'], 'tłumaczenie zapisane');
    t_null($row['source_text'], 'oryginał usunięty z kolejki');
});
