<?php
// tests/importer_test.php
// Importer wydarzeń (tasks/active/importer-wydarzen.md) — testy DETERMINISTYCZNEGO
// rdzenia PHP: parser HTML->payload, deduplikacja, normalizacja tytułu i wybór
// organizatora (realny, nie bot).
//
// CZEGO TU NIE MA I DLACZEGO: pełne EventImport::queueCandidate() woła
// Event::save(), które SAMO otwiera transakcję — a runner (tests/lib.php)
// owija każdy test we własną transakcję i wycofuje ją. Zagnieżdżony
// beginTransaction w MySQL wywala test (to samo ograniczenie opisane w lib.php:
// „kod pod testem nie może sam otwierać transakcji"). End-to-end zapis kandydata
// weryfikujemy osobnym skryptem dev ze sprzątaniem, nie tutaj.

use Models\EventImport;
use Models\User;
use Utils\EventSourceFetcher;

// --- Parser: HTML -> payload dla analyze.py ---------------------------------

$FIXTURE_HTML = <<<'HTML'
<!doctype html><html><head>
<title>  Gravelowa   Setka 2026  </title>
<meta name="description" content="Wyścig gravelowy, 100 km, Podkarpacie.">
<script type="application/ld+json">{"@type":"Event","name":"Gravelowa Setka 2026","startDate":"2026-06-14"}</script>
</head><body>
<h1>Gravelowa Setka 2026</h1>
<p>Zapraszamy na wyścig gravelowy. Dystans 100 km.</p>
<ul><li>Start: Rzeszów, rynek</li><li>Wpisowe: 80 zł</li></ul>
<a href="/zapisy">Zapisz się</a>
<a href="trasa/setka.gpx">Pobierz trasę GPX</a>
<a href="https://inny.example/regulamin">Regulamin</a>
<a href="mailto:biuro@example.org">napisz</a>
<a href="#gora">do góry</a>
<img src="foto/hero.jpg" alt="peleton">
</body></html>
HTML;

t_test('buildPayload: tytuł, meta, JSON-LD wyciągnięte', function () use ($FIXTURE_HTML) {
    $p = EventSourceFetcher::buildPayload($FIXTURE_HTML, 'https://klub.example/wydarzenia/setka');
    t_eq('Gravelowa Setka 2026', $p['pageTitle'], 'pageTitle znormalizowany (bez nadmiarowych spacji)');
    t_eq('Wyścig gravelowy, 100 km, Podkarpacie.', $p['meta']['description'] ?? '', 'meta description');
    t_count(1, $p['jsonLd'], 'jeden blok JSON-LD');
    t_eq('Event', $p['jsonLd'][0]['@type'] ?? '', 'typ JSON-LD odczytany');
});

t_test('buildPayload: linki — mailto/kotwica odrzucone, względne zabsolutyzowane', function () use ($FIXTURE_HTML) {
    $p = EventSourceFetcher::buildPayload($FIXTURE_HTML, 'https://klub.example/wydarzenia/setka');
    $hrefs = array_column($p['links'], 'href');
    // mailto: i #gora nie są nawigacyjne -> nie ma ich na liście.
    foreach ($hrefs as $h) {
        t_true(str_starts_with($h, 'http'), 'każdy link jest http(s): ' . $h);
    }
    t_true(in_array('https://klub.example/zapisy', $hrefs, true), 'link względny /zapisy zabsolutyzowany do origin');
    t_true(in_array('https://klub.example/wydarzenia/setka.gpx', $hrefs, false)
        || in_array('https://klub.example/wydarzenia/trasa/setka.gpx', $hrefs, true), 'link .gpx względny do katalogu zabsolutyzowany');
    t_true(in_array('https://inny.example/regulamin', $hrefs, true), 'link absolutny zachowany');
    // Indeksy sekwencyjne 0..N-1 (kontrakt z analyze.py: dopasowanie po "index").
    foreach ($p['links'] as $i => $l) {
        t_same($i, $l['index'], 'index linku sekwencyjny');
    }
});

t_test('buildPayload: obrazy i elementy tekstowe', function () use ($FIXTURE_HTML) {
    $p = EventSourceFetcher::buildPayload($FIXTURE_HTML, 'https://klub.example/wydarzenia/setka');
    t_true(count($p['images']) >= 1, 'co najmniej jeden obraz');
    t_eq('https://klub.example/wydarzenia/foto/hero.jpg', $p['images'][0]['src'], 'src obrazu zabsolutyzowany do katalogu');
    $texts = array_column($p['elements'], 'text');
    t_true(in_array('Gravelowa Setka 2026', $texts, true), 'nagłówek H1 w elementach');
    t_true(in_array('bikeTypes', array_keys($p['dictionaries'] ?? []), true), 'payload niesie słowniki dla analyze.py');
});

// --- Harvester: strona-lista -> adresy wydarzeń (poziom 2) ------------------

t_test('extractEventLinks: wybiera wydarzenia, odsiewa nawigację/social/pliki', function () {
    $base = 'https://klub.example/kalendarz';
    $links = [
        ['index' => 0, 'text' => 'Strona główna', 'href' => 'https://klub.example/'],
        ['index' => 1, 'text' => 'Wyścig Gravel Setka', 'href' => 'https://klub.example/wydarzenia/setka-2026'],
        ['index' => 2, 'text' => 'Maraton MTB', 'href' => 'https://klub.example/e/maraton-mtb-2026'],
        ['index' => 3, 'text' => 'Regulamin', 'href' => 'https://klub.example/regulamin'],
        ['index' => 4, 'text' => 'Facebook', 'href' => 'https://facebook.com/klub'],
        ['index' => 5, 'text' => 'Zapisy', 'href' => 'https://eventbrite.pl/e/cos-123'],
        ['index' => 6, 'text' => 'Kontakt', 'href' => 'https://klub.example/kontakt'],
        ['index' => 7, 'text' => 'Kalendarz', 'href' => 'https://klub.example/kalendarz'],
        ['index' => 8, 'text' => 'Galeria', 'href' => 'https://klub.example/galeria/foto.jpg'],
        ['index' => 9, 'text' => 'to samo', 'href' => 'https://klub.example/wydarzenia/setka-2026/'],
    ];
    $found = Utils\EventSourceFetcher::extractEventLinks($links, $base);
    $urls = array_column($found, 'url');

    t_count(3, $found, 'trzy wydarzenia (setka, maraton, eventbrite) — reszta odsiana');
    t_true(in_array('https://klub.example/wydarzenia/setka-2026', $urls, true), 'wydarzenie same-host z sygnałem w ścieżce');
    t_true(in_array('https://klub.example/e/maraton-mtb-2026', $urls, true), 'wydarzenie same-host /e/');
    t_true(in_array('https://eventbrite.pl/e/cos-123', $urls, true), 'zaufana platforma spoza hosta listy');
    foreach (['regulamin', 'facebook', 'kontakt', 'foto.jpg', '/kalendarz'] as $bad) {
        foreach ($urls as $u) {
            t_true(!str_contains($u, $bad), "odsiane: $bad (jest $u)");
        }
    }
});

t_test('extractEventLinks: „dodaj wydarzenie" odrzucone mimo słowa „wydarzenie"', function () {
    // Regresja z żywego testu (kalendarzrowerowy.pl): link „Dodaj wydarzenie"
    // łapał się na sygnał „wydarzeni" — ma być odsiany przez NON_EVENT_HINTS.
    $base = 'https://kal.example/';
    $links = [
        ['index' => 0, 'text' => 'Dodaj wydarzenie', 'href' => 'https://kal.example/dodaj-wydarzenie/'],
        ['index' => 1, 'text' => 'Reza Gravel', 'href' => 'https://kal.example/wydarzenie/reza-gravel-2026/'],
    ];
    $urls = array_column(Utils\EventSourceFetcher::extractEventLinks($links, $base), 'url');
    t_count(1, $urls, 'tylko realne wydarzenie');
    t_true(!in_array('https://kal.example/dodaj-wydarzenie/', $urls, true), '„dodaj-wydarzenie" odsiane');
});

t_test('extractEventLinks: same-host bez sygnału i sama lista -> pominięte, dedup po ukośniku', function () {
    $base = 'https://klub.example/kalendarz';
    $links = [
        ['index' => 0, 'text' => 'O klubie', 'href' => 'https://klub.example/dowolna-podstrona'],
        ['index' => 1, 'text' => 'Setka', 'href' => 'https://klub.example/wydarzenia/setka'],
        ['index' => 2, 'text' => 'Setka (znów)', 'href' => 'https://klub.example/wydarzenia/setka/'],
    ];
    $found = Utils\EventSourceFetcher::extractEventLinks($links, $base);
    t_count(1, $found, 'tylko jedno — podstrona bez sygnału odrzucona, duplikat z ukośnikiem zwinięty');
});

t_test('isUnsupportedHost: FB/IG odrzucane, zwykły kalendarz nie', function () {
    t_true(Utils\EventSourceFetcher::isUnsupportedHost('https://www.facebook.com/events/123'), 'facebook.com odrzucony');
    t_true(Utils\EventSourceFetcher::isUnsupportedHost('https://instagram.com/p/abc'), 'instagram odrzucony');
    t_false(Utils\EventSourceFetcher::isUnsupportedHost('https://kalendarzrowerowy.pl/'), 'zwykły kalendarz OK');
});

t_test('importUrl: adres FB odmawia od razu (bez pobierania) statusem unsupported_source', function () {
    $res = Models\EventImport::importUrl('https://www.facebook.com/events/1365602015733572');
    t_eq('unsupported_source', $res['status'], 'FB nie idzie do modelu ani do bazy');
    t_null($res['slug'], 'nic nie utworzono');
});

t_test('looksJsRendered: skorupa SPA wykryta, realna strona nie', function () {
    // Skorupa (jak brevety.pl): parę elementów, zero JSON-LD.
    $shell = ['elements' => [['tag' => 'li', 'text' => 'PL'], ['tag' => 'li', 'text' => 'EN']], 'jsonLd' => []];
    t_true(Utils\EventSourceFetcher::looksJsRendered($shell), 'skorupa SPA wykryta');
    // Realna strona: dużo elementów + JSON-LD.
    $real = ['elements' => array_fill(0, 30, ['tag' => 'p', 'text' => 'x']), 'jsonLd' => [['@type' => 'Event']]];
    t_false(Utils\EventSourceFetcher::looksJsRendered($real), 'realna strona nie jest SPA');
});

// --- Normalizacja tytułu ----------------------------------------------------

t_test('normalizeTitle: małe litery, interpunkcja zwinięta', function () {
    t_eq('gravelowa setka 2026', EventImport::normalizeTitle('  Gravelowa   Setka!!!  2026  '), 'normalizacja');
    t_same(
        EventImport::normalizeTitle('Gravel Ride – Edycja #1'),
        EventImport::normalizeTitle('gravel ride edycja 1'),
        'równoważne po normalizacji mimo interpunkcji/wielkości liter'
    );
});

// --- Dedup: brak fałszywych trafień na nonsensie ----------------------------

t_test('findEventBySourceUrl: nieznany adres -> null', function () {
    t_null(EventImport::findEventBySourceUrl('https://nie-ma-takiego.example/' . uniqid()), 'brak wydarzenia z tego adresu');
});

t_test('findPossibleDuplicate: unikalny tytuł w odległej dacie -> null', function () {
    $title = 'Zupełnie Unikalne Wydarzenie ' . uniqid();
    t_null(EventImport::findPossibleDuplicate($title, '2099-12-31'), 'nic nie koliduje');
});

// --- Wybór organizatora: ZAWSZE realny, nigdy bot (gdy są dane) --------------

t_test('resolveOrganizer: e-mail + nazwa -> realne pending konto z tą nazwą', function () {
    $email = 'importer-test-' . uniqid() . '@example.com';
    $id = EventImport::resolveOrganizer('Klub Testowy', $email, '2026-06-14');
    t_true($id > 0, 'zwrócono id organizatora');
    $u = User::find($id);
    t_not_null($u, 'użytkownik istnieje');
    t_eq('Klub Testowy', $u->name, 'nazwa organizatora ustawiona (nie bot)');
    t_eq($email, $u->email, 'e-mail organizatora zapisany (pod przejęcie)');
});

t_test('resolveOrganizer: sama nazwa (brak e-maila) -> pending konto z syntetycznym adresem', function () {
    $name = 'Solo Organizator ' . uniqid();
    $id = EventImport::resolveOrganizer($name, null, '2026-06-14');
    $u = User::find($id);
    t_not_null($u, 'użytkownik istnieje');
    t_eq($name, $u->name, 'nazwa realnego organizatora, nie bota');
    t_true(str_ends_with($u->email, '@import.ridemore.local'), 'syntetyczny, niewysyłalny adres do czasu poprawki admina');
});

t_test('resolveOrganizer: brak nazwy i e-maila -> fallback na konto bota', function () {
    $id = EventImport::resolveOrganizer(null, null, '2026-06-14');
    $u = User::find($id);
    t_not_null($u, 'konto bota istnieje');
    t_eq('Importer ridemore', $u->name, 'bot ma czytelną nazwę (admin przypisze realnego organizatora)');
});
