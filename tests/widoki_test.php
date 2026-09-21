<?php
// tests/widoki_test.php
// WSPÓLNE KOMPONENTY WIDOKU — dziś kafle statystyk (`partials/stat-tiles.php`).
//
// Widoków w tym serwisie nie testujemy jako całości (to strony, nie funkcje),
// ale PARTIAL jest funkcją: dostaje dane, oddaje HTML. Sprawdzamy dokładnie te
// dwie rzeczy, które przy składaniu HTML-a w pętli psują się po cichu:
// ucieczkę znaków (nazwy tras i podpisy bywają pisane przez ludzi) i pomijanie
// pustych pozycji — bo to na nim opierają się wszystkie cztery strony, wpisując
// warunek „pokaż ten kafel, gdy jest o czym mówić" wprost w tablicy.
require_once CORE_PATH . '/../views/web/partials/stat-tiles.php';

/** HTML kafli jako string — partial pisze na wyjście, nie zwraca. */
function w_tiles(array $tiles, array $opts = []): string
{
    ob_start();
    renderStatTiles($tiles, $opts);
    return (string) ob_get_clean();
}

t_test('kafle: pozycje null są pomijane, a nie rysowane pusto', function () {
    $html = w_tiles([
        ['lbl' => 'Pierwszy', 'val' => 1],
        null,
        ['lbl' => 'Drugi', 'val' => 2],
    ]);

    t_eq(2, substr_count($html, 'class="stat-tile"'), 'dwa kafle, nie trzy');
    t_eq(2, preg_match_all('/stat-tile__lbl/', $html), 'dwa podpisy');
});

t_test('kafle: pusta lista nie zostawia pustej karty', function () {
    t_same('', trim(w_tiles([])), 'nic do pokazania = żadnego HTML-a');
    t_same('', trim(w_tiles([null, null])), 'same puste pozycje też nic nie dają');
});

t_test('kafle: treść od użytkownika jest ucieczkowana', function () {
    // Podpisy kafli składają dziś strony, ale liczby bywają formatowane
    // z danych (nazwa trasy w podpisie to kwestia czasu), więc komponent nie
    // ma prawa ufać temu, co dostał.
    $html = w_tiles([[
        'lbl' => '<b>x</b>',
        'val' => '<script>alert(1)</script>',
        'sub' => 'a & b "c"',
    ]]);

    t_true(!str_contains($html, '<script>'), 'znacznik nie wychodzi jako znacznik');
    t_true(str_contains($html, '&lt;script&gt;'), 'wychodzi jako tekst');
    t_true(str_contains($html, 'a &amp; b'), 'ampersand w podpisie też');
});

t_test('kafle: liczba kolumn to liczba kafli, a w wariancie bocznym dwie', function () {
    // Wartość idzie stylem inline, więc musi być policzona w PHP — inline
    // wygrywa z arkuszem i `--st-cols` z klasy nigdy by go nie poprawiło.
    t_true(str_contains(w_tiles([
        ['lbl' => 'a', 'val' => 1], ['lbl' => 'b', 'val' => 2], ['lbl' => 'c', 'val' => 3],
    ]), '--st-cols:3'), 'trzy kafle = trzy kolumny');

    t_true(str_contains(w_tiles([
        ['lbl' => 'a', 'val' => 1], ['lbl' => 'b', 'val' => 2], ['lbl' => 'c', 'val' => 3],
    ], ['compact' => true]), '--st-cols:2'), 'wariant boczny zawsze dwie');

    t_true(str_contains(w_tiles([['lbl' => 'a', 'val' => 1]], ['cols' => 5]), '--st-cols:5'),
        'strona może narzucić swoją liczbę');
});

t_test('kafle: ikona pola i dopisek „z N" wchodzą tam, gdzie trzeba', function () {
    $html = w_tiles([['lbl' => 'Pola', 'val' => '1 029', 'hex' => true, 'small' => 'z 2 000']]);

    t_true(str_contains($html, 'hexn'), 'liczba z ikoną pola siedzi w .hexn');
    t_true(str_contains($html, '<small>z 2 000</small>'), 'mianownik jako <small>');
    t_false(str_contains(w_tiles([['lbl' => 'Punkty', 'val' => 5]]), 'hexn'),
        'bez flagi nie ma ikony');
});

t_test('kafle: kafel wiodacy dostaje wlasna klase, reszta zostaje zwykla', function () {
    // `lead` doszedl 2026-08-22 dla profilu rowerzysty. Test pilnuje dwoch
    // rzeczy naraz: ze flaga w ogole dziala ORAZ ze jej brak niczego nie
    // zmienia — bo ten sam partial sklada dzis cztery strony i regresja
    // w kafelku bez flagi byla by regresja na wszystkich.
    $html = w_tiles([
        ['lbl' => 'Pola', 'val' => 441, 'lead' => true],
        ['lbl' => 'Punkty', 'val' => 5872],
    ]);

    t_eq(1, substr_count($html, 'stat-tile--lead'), 'dokladnie jeden kafel wiodacy');
    t_eq(1, substr_count($html, 'class="stat-tile"'), 'drugi kafel bez dodatkowej klasy');
});

t_test('kafle: bez flagi `lead` markup jest bajt w bajt jak przed jej dodaniem', function () {
    // Regresja, ktorej najlatwiej nie zauwazyc: dynamiczna klasa moze zostawic
    // spacje albo pusty przyrostek i rozjechac selektory na stronie trasy,
    // kroniki i /odkrycia, ktore o `lead` nic nie wiedza.
    $html = w_tiles([['lbl' => 'Punkty', 'val' => 5]]);

    t_true(str_contains($html, '<div class="stat-tile">'), 'atrybut bez ogona');
    t_false(str_contains($html, 'stat-tile--lead'), 'i bez klasy wiodacej');
});

// ============================================================
// AWATARY — `partials/rider-avatar.php` (2026-08-22).
//
// Zgloszenie usera: „jesli user ma ikonke wgrana jako avatar, to i tak
// w aplikacji laduje skrot". Komponent renderowal WYLACZNIE inicjaly, bo
// w ogole nie przyjmowal adresu zdjecia — i tak samo zachowywalo sie
// wszystkie siedem miejsc, ktore go wolaja.
//
// Testujemy trzy rzeczy, ktore przy takiej zmianie psuja sie po cichu:
// pierwszenstwo zdjecia nad inicjalami, ZACHOWANIE inicjalow jako zapasu
// (w `alt`, na wypadek niewczytanego pliku) i to, ze brak awatara daje
// dokladnie ten sam HTML co przed zmiana.
// ============================================================
require_once CORE_PATH . '/../views/web/partials/rider-avatar.php';

/** HTML awatara jako string — partial pisze na wyjscie, nie zwraca. */
function w_av(string $initials, ?string $slug, string $title = '', ?string $avatarUrl = null): string
{
    ob_start();
    renderRiderAvatar($initials, $slug, $title, $avatarUrl);
    return (string) ob_get_clean();
}

/**
 * PRAWDZIWY plik awatara na czas testu — od 2026-09-03 komponent pyta
 * `Image::exists()`, wiec sciezka „na niby" renderuje sie jako inicjaly
 * i nie da sie nia sprawdzic galezi ze zdjeciem. Zwraca adres aplikacyjny,
 * sprzata `w_av_bez_pliku()`.
 */
function w_av_plik(): string
{
    $dir = dirname(CORE_PATH) . '/assets/uploads/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $nazwa = 'test-' . bin2hex(random_bytes(6)) . '.png';
    // Najmniejszy poprawny PNG (1x1) — `Image::src` odda dla niego oryginal,
    // bo jest mniejszy niz preset, wiec nie powstaje zaden wariant do sprzatania.
    file_put_contents($dir . '/' . $nazwa, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
    return '/assets/uploads/avatars/' . $nazwa;
}

function w_av_bez_pliku(string $url): void
{
    @unlink(dirname(CORE_PATH) . $url);
}

t_test('awatar: wgrane zdjecie wygrywa z inicjalami', function () {
    $url = w_av_plik();
    $html = w_av('MK', 'marek-kowalski', 'Marek K.', $url);
    w_av_bez_pliku($url);

    t_true(str_contains($html, '<img '), 'renderuje sie obrazek');
    t_true(str_contains($html, basename($url, '.png')), 'i to ten wskazany');
    t_true(str_contains($html, 'alt="MK"'), 'inicjaly zostaja w alt (wylaczone obrazki, padniety CDN)');
    // Inicjaly NIE moga zostac jako tekst obok obrazka — w kolku 28 px
    // wystawalyby zza zdjecia.
    t_false((bool) preg_match('/>\s*MK\s*</', $html), 'inicjaly nie stoja obok obrazka');
});

t_test('awatar: SKASOWANY plik to inicjaly, a nie ikona zepsutego obrazka', function () {
    // To jest cala roznica miedzy „zdjecie zamiast inicjalow" a „zdjecie ALBO
    // inicjaly". Do 2026-09-03 komponent patrzyl WYLACZNIE na to, czy adres
    // jest w bazie — a plik moze go nie miec (zgloszenie usera: „wskazuje to,
    // ze nie ma poprawnego dostepu (…) w calej aplikacji jest podobnie";
    // zmierzone: WSZYSTKIE 28 plikow uploadow w bazie dev bylo skasowanych).
    // `alt` tego nie ratuje: przegladarka pokazuje wtedy ikone bledu razem
    // z tekstem, a nie czyste dwie litery w kolku.
    $html = w_av('OL', null, 'Ola N.', '/assets/uploads/avatars/nie-ma-mnie.png');

    t_false(str_contains($html, '<img '), 'zadnego obrazka do zepsucia');
    t_true((bool) preg_match('/>\s*OL\s*</', $html), 'w kolku stoja inicjaly');
});

t_test('awatar: adres ZEWNETRZNY zostaje obrazkiem (nie mamy jak go sprawdzic)', function () {
    // Awatar z Google przy logowaniu spolecznosciowym. `Image::exists()`
    // uznaje go za istniejacy z rozmyslem: jedyna alternatywa to zadanie
    // sieciowe na kazdy awatar w liscie skladu.
    $html = w_av('AN', null, 'Anna N.', 'https://lh3.googleusercontent.com/a/xyz=s96-c');

    t_true(str_contains($html, '<img '), 'renderuje sie obrazek');
    t_true(str_contains($html, 'googleusercontent'), 'i to ten zewnetrzny adres');
});

t_test('awatar: bez zdjecia HTML jest taki sam jak przed zmiana', function () {
    // Regresja dla siedmiu miejsc wolajacych ten komponent: konto bez awatara
    // (wiekszosc bazy) ma wygladac dokladnie tak jak dotad.
    //
    // ADRES SKLADAMY `View::url()`, nie wpisujemy go tu z palca (2026-09-03):
    // od tej daty helper zwraca PELNY adres liczony z biezacego zadania
    // (w CLI — z `app_url`), wiec literal „/ridemore/..." przypinalby test do
    // jednego srodowiska. Pilnowany jest KSZTALT markupu, nie baza adresu —
    // ta ma swoj wlasny test nizej.
    t_same('<a href="' . Utils\View::url('/rowerzysta/ola-nowak') . '" title="Ola N.">OL</a>',
        w_av('OL', 'ola-nowak', 'Ola N.'),
        'klikalny wariant bez zmian');
    t_same('<span title="Ktos">XX</span>', w_av('XX', null, 'Ktos'),
        'nieklikalny wariant bez zmian');
    // Pusty string traktujemy jak brak — kolumna w bazie bywa '' zamiast NULL.
    t_false(str_contains(w_av('XX', null, 'Ktos', ''), '<img'), 'pusty adres to brak awatara');
});

t_test('awatar: zdjecie nie psuje linku do profilu', function () {
    // Awatar ze zdjeciem musi dalej prowadzic na profil — to byla cala racja
    // bytu tego komponentu (2026-08-12), zanim doszly do niego zdjecia.
    $url = w_av_plik();
    $html = w_av('MK', 'marek-kowalski', 'Marek K.', $url);
    w_av_bez_pliku($url);

    t_true(str_contains($html, 'href="' . Utils\View::url('/rowerzysta/marek-kowalski') . '"'), 'link zostaje');
    t_true(str_contains($html, 'title="Marek K."'), 'tytul zostaje');
});

t_test('adresy: View::url() sklada PELNY adres, a zewnetrznego nie rusza', function () {
    // Prosba usera (2026-09-03): „chodzilo mi o to, zebys linki budowal o tak:
    // http://localhost/ridemore/assets/uploads/covers/...". Host bierze sie
    // z BIEZACEGO ZADANIA (w tescie, czyli w CLI — z `app_url`), bo ten sam
    // kod odpowiada pod localhostem, pod IP maszyny (apka na telefonie,
    // `server.url` w app/capacitor.config.ts) i pod produkcyjna domena.
    $url = Utils\View::url('/trasy/szlak-testowy');

    t_true((bool) preg_match('~^https?://~', $url), 'adres ma schemat i host: ' . $url);
    t_true(str_contains($url, '/trasy/szlak-testowy'), 'i sciezke aplikacji');

    $base = rtrim(APP_CONFIG['base_path'] ?? '', '/');
    if ($base !== '') {
        t_true(str_contains($url, $base . '/trasy/'), 'z base_path dokladnie RAZ');
        t_false(str_contains($url, $base . $base), 'base_path nie zdublowany');
    }

    // Wartosci z bazy bywaja juz bezwzgledne (awatar z Google przy logowaniu
    // spolecznosciowym) — takiego adresu nie wolno tknac.
    $zewnetrzny = 'https://lh3.googleusercontent.com/a/xyz=s96-c';
    t_same($zewnetrzny, Utils\View::url($zewnetrzny), 'adres zewnetrzny wychodzi bez zmian');
    t_same('//cdn.example.com/x.js', Utils\View::url('//cdn.example.com/x.js'),
        'adres protokolowo-wzgledny tez');

    // `absoluteUrl()` ZOSTAJE przy adresie kanonicznym z konfiguracji — na tym
    // stoja maile, push, canonical i og:image (nagłowek Host jest do podrobienia).
    t_true(str_starts_with(Utils\View::absoluteUrl('/trasy/x'), rtrim(APP_CONFIG['app_url'], '/')),
        'absoluteUrl dalej idzie z app_url');
});

// ============================================================
// LIGHTBOX — `partials/photo-lightbox.php` (2026-08-22).
//
// Zgloszenie usera: „galeria zdjec otwiera z osobna zdjecie i to male,
// a powinno zadzialac lightbox". Objaw byl podwojny, a przyczyna jedna:
// link prowadzil do wariantu `thumb` (320 px) zamiast do oryginalu.
//
// Partial nie jest funkcja (to <dialog> + skrypt dolaczany raz na strone),
// wiec testujemy KONTRAKT, na ktorym opieraja sie trzy strony: jednorazowosc
// dolaczenia i obecnosc obu wyzwalaczy w skrypcie. Zachowanie w przegladarce
// sprawdzone recznie na /events, /kronika i /organizatorzy.
// ============================================================
t_test('lightbox: partial dokleja sie tylko RAZ na strone', function () {
    // Strona wyjazdu ma trzy galerie i moze go zazadac z kilku miejsc.
    // Drugi <dialog> o tym samym id psulby getElementById i modal
    // przestalby sie otwierac przy DRUGIEJ galerii.
    ob_start();
    require CORE_PATH . '/../views/web/partials/photo-lightbox.php';
    $pierwszy = (string) ob_get_clean();

    ob_start();
    require CORE_PATH . '/../views/web/partials/photo-lightbox.php';
    $drugi = (string) ob_get_clean();

    t_true(str_contains($pierwszy, 'id="phModal"'), 'pierwsze dolaczenie renderuje modal');
    t_same('', trim($drugi), 'drugie nie renderuje nic');
});

t_test('lightbox: obsluguje oba rodzaje kafelka', function () {
    // `.ph-thumb` to siatka kronikowa (przycisk z data-full), `.ph-link` to
    // galerie wyjazdu i organizatora (prawdziwy link do pelnego pliku, ktory
    // dziala takze bez JS). Utrata ktoregokolwiek = jedna ze stron cicho
    // wraca do otwierania zdjecia w nowej karcie.
    $js = (string) file_get_contents(CORE_PATH . '/../views/web/partials/photo-lightbox.php');

    t_true(str_contains($js, ".closest('.ph-thumb')"), 'kafel kronikowy');
    t_true(str_contains($js, ".closest('a.ph-link')"), 'link galerii');
    // Ctrl/Cmd/srodkowy przycisk zostaja przegladarce — przechwycenie ich
    // odebraloby userowi „otworz w nowej karcie", ktore dzialalo.
    t_true(str_contains($js, 'e.metaKey || e.ctrlKey'), 'modyfikatory nieprzechwytywane');
});

t_test('lightbox: zadna galeria nie linkuje juz do miniatury', function () {
    // Sedno zgloszenia: `href` MUSI wskazywac oryginal. Skanujemy szablony,
    // bo to jedyny sposob, zeby nowa galeria nie powtorzyla tego bledu po cichu.
    $strony = glob(CORE_PATH . '/../views/web/pages/*.php') ?: [];
    $zle = [];
    foreach ($strony as $plik) {
        foreach (file($plik) ?: [] as $nr => $linia) {
            // Link, ktorego href sklada wariant `thumb` — dokladnie ten wzorzec
            // stal na stronie wyjazdu (3x) i na profilu organizatora.
            if (preg_match('/<a[^>]*href="[^"]*Image::src\([^)]*[\'"]thumb[\'"]/', $linia)) {
                $zle[] = basename($plik) . ':' . ($nr + 1);
            }
        }
    }
    t_same([], $zle, 'href galerii prowadzi do oryginalu, nie do wariantu thumb');
});

// ---------------------------------------------------------------------------
// TRYB APLIKACJI MOBILNEJ (Capacitor, Etap 1 — tasks/active/apka-mobilna.md).
//
// Ta funkcja jest rozłożona na CZTERY pliki (bootstrap wykrywa, layout składa,
// partial rysuje, arkusz stylów odsuwa treść) i to jest jedyny powód, dla
// którego ma własne testy: każdy z tych plików da się zmienić osobno, a rozpad
// widać dopiero na telefonie, do którego nie ma tu dostępu. Sprawdzamy więc
// SPOJENIA, nie wygląd.

/** HTML dolnego paska jako string — partial pisze na wyjście, nie zwraca. */
function w_app_nav(): string
{
    ob_start();
    require CORE_PATH . '/../views/web/partials/app-nav.php';
    return (string) ob_get_clean();
}

t_test('apka: pasek renderuje się WYŁĄCZNIE w trybie aplikacji', function () {
    // Od rozdzielenia skorup (2026-08-29, tasks/done/apka-mobilna-skorupa.md)
    // bramką nie jest już `if (APP_IS_APP)` w jednym layoucie, tylko WYBÓR
    // PLIKU w View::render(). Sprawdzamy więc oba końce tego spojenia — inaczej
    // dolny pasek trafiłby na zwykłą stronę, z przyciskiem skanowania, który
    // w przeglądarce nie ma czym zeskanować.
    $web = (string) file_get_contents(CORE_PATH . '/../views/web/layout.php');
    $app = (string) file_get_contents(CORE_PATH . '/../views/web/layout-app.php');
    $view = (string) file_get_contents(CORE_PATH . '/../core/Utils/View.php');

    t_false(str_contains($web, 'app-nav.php'), 'skorupa web nie dołącza paska w ogóle');
    t_false(str_contains($web, "' is-app'"), 'skorupa web nie nadaje klasy trybu apki');
    t_true(str_contains($app, 'app-nav.php'), 'skorupa apki dołącza pasek');
    t_true(str_contains($app, "trim(\$bodyClass . ' is-app')"), 'klasa is-app dokładana, nie nadpisująca $bodyClass');

    t_true(
        (bool) preg_match("/\\\$section\s*===\s*'web'\s*&&\s*APP_IS_APP.*layout-app\.php/s", $view),
        'View::render wybiera skorupę apki tylko dla sekcji web'
    );
});

t_test('skorupy: jedna głowa dokumentu, dwa ciała', function () {
    // NAJWAŻNIEJSZY TEST TEJ PRZEBUDOWY. Dwie kopie <head> rozjechałyby się
    // CICHO: web wyglądałby dalej poprawnie, a apka traciłaby meta tagi albo
    // skrypt, czego nikt nie ogląda w źródle.
    $web  = (string) file_get_contents(CORE_PATH . '/../views/web/layout.php');
    $app  = (string) file_get_contents(CORE_PATH . '/../views/web/layout-app.php');
    $head = (string) file_get_contents(CORE_PATH . '/../views/web/partials/head.php');

    foreach (['web' => $web, 'apki' => $app] as $nazwa => $src) {
        t_true(str_contains($src, "partials/head.php"), "skorupa $nazwa dołącza wspólną głowę");
        t_false(str_contains($src, '<!DOCTYPE'), "skorupa $nazwa nie ma własnego doctype");
        t_false(str_contains($src, 'og:title'), "skorupa $nazwa nie ma własnych meta tagów");
    }
    t_true(str_contains($head, 'og:title') && str_contains($head, '</head>'), 'głowa niesie meta i domyka <head>');
    // Most natywny ładuje się ZAWSZE — na web udaje funkcje telefonu, więc
    // strony wołają jedno API niezależnie od tego, gdzie się wykonują.
    t_true(str_contains($head, 'native.js'), 'most natywny w głowie wspólnej, nie tylko w apce');
    t_true(str_contains($head, 'app-tracking.js'), 'nagrywanie w tle wpięte w głowę (działa na każdym ekranie apki)');
});

t_test('apka: stan nagrywania jest WIDOCZNY i da się go włączyć z ekranu mapy', function () {
    // Zgłoszenie usera 2026-08-29: „nie widziałem wcześniej nigdzie zatrzymania
    // ani ikonki, że jestem w trybie trakingu". Pastylka istniała, ale wyłącznie
    // W TRAKCIE sesji — po odmowie w banerze (ten nie wraca) albo po odmowie
    // uprawnienia systemowego nie było ŻADNEGO stanu ani drogi powrotu.
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery-app.php');
    $js    = (string) file_get_contents(CORE_PATH . '/../assets/js/app-tracking.js');
    $css   = (string) file_get_contents(CORE_PATH . '/../assets/css/app.css');

    // Cały chip, od klasy kontenera do jego domknięcia (w środku są tylko
    // spany i przycisk, żadnego zagnieżdżonego <div>) — pytamy o ZAWIERANIE,
    // nie o odległość w znakach, bo tę zmienia byle komentarz.
    preg_match('/app-map__stats.*?<\/div>/s', $widok, $chip);
    t_true(
        isset($chip[0]) && str_contains($chip[0], 'data-rm-bg-toggle'),
        'przełącznik stoi w chipie statystyk — user prosił „koło punktów"'
    );
    t_true(
        (bool) preg_match('/data-rm-bg-toggle[^>]*\shidden/s', $widok),
        'w markupie jest hidden — bez potwierdzenia mostu nie obiecujemy funkcji, której nie ma'
    );
    t_true(
        str_contains($css, '.app-map__rec[hidden]{display:none;}'),
        'i CSS to `hidden` respektuje — display:flex z .app-map__stat bije regułę przeglądarki'
    );

    // KSZTAŁT ZMIENIONY 2026-08-30: dotknięcie ma dziś odpowiedź w KAŻDYM
    // stanie. Doszła gałąź „startuje" (anuluj próbę) — wcześniej było tam
    // puste `return`, czyli przycisk, który przestawał odpowiadać, gdy start
    // nie doszedł do skutku. Limit odległości podniesiony, bo między
    // selektorem a gałęzią „on" stoi teraz ta gałąź wraz z uzasadnieniem.
    t_true(
        (bool) preg_match("/data-rm-bg-toggle.{0,1600}STAN === 'on'.{0,40}stopSession\(\)/s", $js),
        'dotknięcie przy włączonym nagrywaniu ZATRZYMUJE'
    );
    t_true(
        (bool) preg_match("/data-rm-bg-toggle.{0,1200}STAN === 'startuje'.{0,700}renderStan\('off'\)/s", $js),
        'dotknięcie w trakcie startu anuluje próbę — nie ma stanu bez odpowiedzi'
    );
    t_true(
        (bool) preg_match("/el\.hidden = false/", $js),
        'skrypt odsłania przycisk dopiero u siebie, czyli w apce'
    );
    // NAJWAŻNIEJSZE: odmowa uprawnienia miała dotąd pustą gałąź `catch`.
    t_true(
        // Limit odległości podniesiony 2026-08-29: w gałęzi błędu doszło
        // czyszczenie znacznika sesji i akapit wyjaśniający, po co ono jest.
        (bool) preg_match("/catch\(function \(\) \{.{0,1200}renderStan\('blad'\)/s", $js),
        'odmowa zgody systemowej ma swój stan na przycisku, a nie ciszę'
    );
});

t_test('arkusze: style apki nie jadą do przeglądarki', function () {
    // Faza 3 kontraktu skorupy (2026-08-29). ~370 linii, które nie mają prawa
    // wykonać się na www, jechały wcześniej do KAŻDEGO odwiedzającego serwis.
    $wspolny = (string) file_get_contents(CORE_PATH . '/../assets/css/style.css');
    $apka    = (string) file_get_contents(CORE_PATH . '/../assets/css/app.css');
    $head    = (string) file_get_contents(CORE_PATH . '/../views/web/partials/head.php');

    // Ciągi szukane w REGUŁACH, nie w komentarzach — `style.css` kończy się
    // notą wymieniającą z nazwy to, co zostało przeniesione, więc szukamy
    // wzorców z nawiasem klamrowym albo przecinkiem selektora.
    foreach (['body.is-app{', '.app-nav{', '.app-header{', '.rm-scan{', '.rs{'] as $regula) {
        t_false(str_contains($wspolny, $regula), "reguła $regula zeszła z arkusza wspólnego");
        t_true(str_contains($apka, $regula), "reguła $regula jest w arkuszu apki");
    }

    // KOLEJNOŚĆ TO WYMÓG, NIE KOSMETYKA: app.css nadpisuje komponenty wspólne
    // (.disc-panel, .mbar, .filters), więc przed style.css nadpisywałby nic.
    $poz1 = strpos($head, 'css/style.css');
    $poz2 = strpos($head, 'css/app.css');
    t_true($poz1 !== false && $poz2 !== false && $poz1 < $poz2, 'app.css ładowany PO style.css');
    t_true(
        (bool) preg_match('/APP_IS_APP.{0,900}css\/app\.css/s', $head),
        'app.css za bramką APP_IS_APP — web go nie pobiera'
    );
});

t_test('apka: belka górna bez nawigacji, menu konta i licznika wydarzeń', function () {
    // Powód rozdzielenia skorup: apka miała pełny nagłówek desktopowy NA TYM
    // SAMYM ekranie co własny pięciosłotowy pasek dolny — i płaciła za to
    // czterema zapytaniami do bazy na każde żądanie.
    $src = (string) file_get_contents(CORE_PATH . '/../views/web/partials/app-header.php');

    // KOMENTARZE ODCINAMY PRZED SPRAWDZANIEM. Nagłówek tego partiala WYMIENIA
    // z nazwy wszystko, czego w nim nie ma („hamburger", „menu konta",
    // „Organizer::hasProfile"), bo bez tej listy nikt by nie wiedział, że
    // pominięcia są celowe. Test szukający samych ciągów trafiałby więc
    // w wyjaśnienie zamiast w kod — i wywracał się tym trafniej, im lepiej
    // plik jest opisany. `token_get_all` zostawia sam kod i HTML.
    $kod = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $kod .= is_array($t) ? $t[1] : $t;
    }

    t_false(str_contains($kod, 'account-menu'), 'menu konta zeszło na ekran Profil');
    t_false(str_contains($kod, 'hamburger-btn'), 'brak hamburgera — nawigacją jest dolny pasek');
    t_false(str_contains($kod, 'mobile-nav-toggle'), 'brak rozwijanej nawigacji mobilnej');
    t_false(str_contains($kod, 'confirmedEditionCountsForUser'), 'licznik „Moje wydarzenia" nie kosztuje zapytania');
    t_false(str_contains($kod, 'Organizer::hasProfile'), 'rola organizatora nie kosztuje zapytania');
    t_true(str_contains($kod, 'msg-icon'), 'ikona wiadomości zostaje — to jedyny sygnał o nowych');

    // Menu konta musi mieć nowy dom, inaczej apka traci wylogowanie.
    $profil = (string) file_get_contents(CORE_PATH . '/../views/web/pages/rider-profile.php');
    t_true(
        (bool) preg_match('/\$isOwnProfile\s*&&\s*APP_IS_APP/', $profil),
        'sekcja Konto tylko na własnym profilu i tylko w apce'
    );
    t_true(
        // Adres jest budowany View::url() wewnątrz atrybutu, więc `/wyloguj`
        // NIE stoi tuż przed domykającym cudzysłowem — dopasowujemy sam adres
        // i wymagamy tokenu w tym samym formularzu.
        (bool) preg_match('~method="post"[^>]*/wyloguj.{0,160}Csrf::field~s', $profil),
        'wylogowanie zostaje POST-em z tokenem, nie linkiem'
    );
});

t_test('apka: pasek ma pięć slotów, Mapa jest wyniesionym środkiem, skanowanie przyciskiem', function () {
    $html = w_app_nav();

    // Wzorzec domyka klasę cudzysłowem albo spacją — samo `app-nav__i` łapie
    // też `app-nav__ico` wewnątrz każdego slotu i liczy podwójnie.
    //
    // PIĘĆ, NIE CZTERY (zmiana 2026-08-29): Mapa dostała klasę `app-nav__i`
    // wspólnie z resztą, żeby jej PODPIS brał tę samą typografię co pozostałe
    // — wcześniej jako jedyna nie miała podpisu widocznego dla oka. Wyniesiony
    // okrąg nie zniknął, zszedł tylko na wewnętrzne `.app-nav__ring`.
    t_eq(5, preg_match_all('/class="app-nav__i[ "]/', $html), 'pięć slotów w jednym systemie');
    t_true(str_contains($html, 'app-nav__hero'), 'Mapa dalej jest wyniesionym środkiem paska');
    t_true(str_contains($html, 'app-nav__ring'), 'okrąg siedzi w osobnym elemencie, żeby podpis mógł stać pod nim');
    // SKANOWANIE NIE MOŻE BYĆ LINKIEM: to czynność wykonywana na miejscu przez
    // most natywny, nie adres, pod który da się przejść. Jako <a href> byłoby
    // też otwierane w nowej karcie i indeksowane.
    t_true(str_contains($html, 'class="app-nav__i app-nav__i--btn" data-rm-scan'), 'skanowanie to <button> z data-rm-scan');
    t_false(str_contains($html, 'href="#"'), 'żaden slot nie jest pustym linkiem');
});

t_test('apka: gość dostaje logowanie zamiast cudzego profilu', function () {
    // Testy lecą bez sesji, więc to jest gałąź gościa. Bez tego rozgałęzienia
    // ostatni slot wskazywałby /rowerzysta/ z pustym slugiem — czyli 404
    // w miejscu, w które user puka najczęściej.
    $html = w_app_nav();

    t_true(str_contains($html, '/logowanie'), 'ostatni slot prowadzi do logowania');
    t_true(str_contains($html, '>Zaloguj<'), 'i mówi o tym wprost');
    t_false(str_contains($html, '/rowerzysta/"'), 'nie ma linku do profilu bez sluga');
});

t_test('apka: trwała sesja jest wpięta w OBU miejscach', function () {
    // Decyzja „w apce zawsze zapamiętuj" wymaga dwóch niezależnych rzeczy:
    // ustawienia cookie przy logowaniu (Auth::login) i utrzymania jego
    // parametrów na każdym kolejnym żądaniu (bootstrap, przed session_start).
    // Sama pierwsza połowa = wylogowanie po restarcie apki, sama druga = brak
    // znacznika remember_me. Rozpad żadnej z nich nie daje błędu, tylko cichy
    // powrót na ekran logowania — stąd ten test.
    $bootstrap = (string) file_get_contents(CORE_PATH . '/bootstrap.php');
    $auth      = (string) file_get_contents(CORE_PATH . '/Core/Auth.php');

    t_true(
        (bool) preg_match('/if\s*\(\s*isset\(\$_COOKIE\[.remember_me.\]\)\s*\|\|\s*APP_IS_APP\s*\)/', $bootstrap),
        'bootstrap traktuje apkę jak zaznaczone „Zapamiętaj mnie"'
    );
    t_true(
        (bool) preg_match('/\$remember\s*=\s*\$remember\s*\|\|\s*\(defined\(.APP_IS_APP.\)\s*&&\s*APP_IS_APP\)/', $auth),
        'Auth::login wymusza remember w apce'
    );
    // Stała MUSI powstać przed session_start(), bo steruje parametrami cookie
    // sesji — po starcie sesji jest już za późno.
    // Szukamy WYWOŁANIA na początku linii, nie wzmianki — o `session_start()`
    // mówi też komentarz nad samą definicją stałej, kilkanaście linii wcześniej.
    preg_match('/^\s*session_start\(\);/m', $bootstrap, $m, PREG_OFFSET_CAPTURE);
    t_true(
        $m && strpos($bootstrap, "define('APP_IS_APP'") < $m[0][1],
        'APP_IS_APP definiowane przed session_start()'
    );
});

t_test('apka: przycisk „Zrób zdjęcie" stoi za bramką APP_IS_APP i celuje we właściwy input', function () {
    // APP_IS_APP jest stałą ustaloną raz na proces (z User-Agenta żądania) —
    // w testach CLI zawsze fałszywa, więc gałęzi „w apce" nie da się wyrenderować
    // wprost. Sprawdzamy więc ŹRÓDŁO, tym samym sposobem co test wyżej dla
    // paska nawigacji: czy bramka i cel istnieją, a nie jak wygląda wynik.
    $src = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasure-scan.php');

    t_true(
        (bool) preg_match('/if\s*\(\s*APP_IS_APP\s*\).{0,200}data-rm-camera-for/s', $src),
        'przycisk stoi za bramką APP_IS_APP — na www zostaje sam <input type=file>'
    );
    t_true(
        str_contains($src, 'id="tsGalPhotos"') && str_contains($src, 'data-rm-camera-for="tsGalPhotos"'),
        'przycisk celuje w id istniejącego inputu — rozjazd id-ów zostawiłby martwy przycisk'
    );
});

t_test('most natywny: brak pluginu schodzi do przeglądarki, nie do ciszy', function () {
    // Cały sens native.js: strony wołają jedno API i nie sprawdzają same
    // window.Capacitor. Pierwsza strona, która to obejdzie, zacznie działać
    // inaczej w apce niż na www — a tego nie widać z żadnej strony osobno.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/native.js');

    t_true(str_contains($js, 'navigator.geolocation.getCurrentPosition'), 'position() ma zejście do przeglądarki');
    t_true(str_contains($js, 'positionError'), 'jeden komunikat błędu dla obu źródeł');
    t_true(str_contains($js, "closest('[data-rm-scan]')"), 'przycisk skanowania podpięty delegacją');
});

t_test('szuflada: panel skarbów na telefonie zwija się, a nie znika', function () {
    // Regresja, która kosztowała najwięcej na tym ekranie: `.disc-panel` miało
    // `display:none` poniżej 680 px, czyli na telefonie znikały OBIE listy
    // skarbów razem z „Ostatnią aktywnością" — na widoku, który jest przede
    // wszystkim terenowy. Test pilnuje, żeby ta reguła nie wróciła przy
    // najbliższym porządkowaniu media queries.
    $css = (string) file_get_contents(CORE_PATH . '/../assets/css/style.css');

    t_false(
        (bool) preg_match('/\.disc-panel\s*\{[^}]*display\s*:\s*none/', $css),
        'panel nie jest chowany na sztywno'
    );
    t_true(str_contains($css, '.disc-panel.is-open'), 'jest stan otwarty szuflady');
    // BEZ `transition` NA max-height — Chrome interpoluje 46px→62% jako
    // `calc(0% + 46px)` i szuflada nie otwiera się wcale (zmierzone).
    t_false(
        (bool) preg_match('/\.disc-panel\{[^}]*transition\s*:[^}]*max-height/', $css),
        'brak animacji max-height, która blokuje otwarcie'
    );
});

t_test('panel przy mapie: obsługa dokładana KAŻDEMU panelowi, nie tylko skarbom', function () {
    // Mapa osobista ma „Ostatnią aktywność", wspólna — skarby; nigdy oba naraz.
    // Gdyby kontrolki wisiały na `#discTreasurePanel`, mapa osobista dostałaby
    // panel bez sposobu otwarcia, czyli pasek, którego nie da się rozwinąć.
    //
    // OD 2026-08-24 KOD SIEDZI W `discovery-map.js`, nie w `discovery.php`
    // (prośba usera o zwijanie „na wszystkich mapach"): panel jest nakładką NA
    // MAPIE, więc należy do mapy, a nie do jednej strony. Test sprawdza więc
    // wspólny plik — i przy okazji pilnuje, żeby ta obsługa NIE wróciła do
    // pojedynczego widoku.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/discovery-map.js');
    $page = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery.php');

    t_true(str_contains($js, "querySelectorAll('.disc-panel')"), 'kontrolki dokładane po klasie, nie po id');
    t_true(str_contains($js, 'disc-panel__handle'), 'klasa uchwytu (telefon) zgadza się ze stylami');
    t_true(str_contains($js, 'disc-panel__fold'), 'klasa zwijaka (desktop) zgadza się ze stylami');
    // `dataset.drawerCount` w JS-ie to `data-drawer-count` w HTML-u — strona
    // szuka licznika tym drugim zapisem, więc test musi znać oba i pilnować,
    // że są parą (literalnego `data-drawer-count` w pliku JS nie ma i nie będzie).
    t_true(str_contains($js, 'dataset.drawerCount'), 'licznik powstaje w uchwycie');
    t_true(str_contains($page, '[data-drawer-count]'), 'strona wie, gdzie go szukać');
    t_false(str_contains($page, 'disc-panel__handle'), 'strona nie dokłada uchwytu drugi raz');
});

t_test('most: żadna strona nie woła geolokalizacji z pominięciem mostu', function () {
    // Cały sens `RM.native.position()`: jedno wywołanie, dwa źródła pozycji
    // (natywny GPS w apce, przeglądarka poza nią) i JEDEN komunikat błędu.
    // Pierwsze miejsce, które sięgnie po `navigator.geolocation` wprost, zacznie
    // się zachowywać inaczej w aplikacji niż na stronie — a tego nie widać
    // z żadnego ekranu osobno, bo oba „działają".
    //
    // Skanujemy pliki zamiast ufać pamięci: przy siedmiu miejscach podmienionych
    // naraz łatwo dopisać ósme i nie zauważyć.
    $pliki = array_merge(
        glob(CORE_PATH . '/../views/web/pages/*.php') ?: [],
        glob(CORE_PATH . '/../views/web/partials/*.php') ?: [],
        glob(CORE_PATH . '/../assets/js/*.js') ?: []
    );

    $zle = [];
    foreach ($pliki as $plik) {
        if (basename($plik) === 'native.js') { continue; } // to JEST most
        foreach (file($plik) ?: [] as $nr => $linia) {
            // Samo słowo w komentarzu jest w porządku (kilka miejsc tłumaczy,
            // skąd bierze się pozycja) — chodzi o WYWOŁANIE.
            if (str_contains($linia, 'navigator.geolocation.getCurrentPosition')
                || str_contains($linia, 'navigator.geolocation.watchPosition')) {
                $zle[] = basename($plik) . ':' . ($nr + 1);
            }
        }
    }

    t_same([], $zle, 'pozycja wyłącznie przez RM.native.position()');
});

t_test('skaner: kod z naklejki przechodzi przez bramkę adresu', function () {
    // Kod QR przynosi OBCY TEKST. Bez sprawdzenia adresu zeskanowanie dowolnej
    // naklejki z miasta przeniosłoby WebView aplikacji — z żywą sesją Ridemore —
    // na cudzą stronę. Bramka jest jedna (`adresSkarbu`) i musi sprawdzać dwie
    // rzeczy naraz: ten sam origin i ścieżkę /skarb/{kod}.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/native.js');

    t_true(str_contains($js, 'url.origin !== window.location.origin'), 'obcy host odrzucony');
    t_true(str_contains($js, '/\/skarb\/[A-Za-z0-9_-]+\/?$/'), 'ścieżka musi być adresem skarbu');
    // Nawigacja MUSI iść przez bramkę, nie wprost z odczytanego kodu.
    t_false((bool) preg_match('/location\.href\s*=\s*kod\b/', $js), 'surowy kod nigdy nie trafia do location.href');
    // Skanowanie kończy się JEDNĄ drogą — przy trzech osobnych wyjściach
    // (kod / anulowanie / błąd) któraś zostawi włączony aparat i przezroczystą
    // stronę, czyli aplikację nie do użycia.
    t_eq(1, substr_count($js, 'function koniec('), 'jedno wyjście ze skanera');
    t_true(str_contains($js, "classList.remove('rm-scan-on')"), 'i ono przywraca widoczność strony');

    // Skanowanie chowa CAŁE UI, bo pod stroną jest żywy kadr. Pastylka
    // nagrywania i baner zgody wiszą na <body>, nie w `.wrap`, więc muszą być
    // wymienione z nazwy — inaczej „Zatrzymaj" da się dotknąć przez
    // przezroczystą nakładkę skanera i nawigacja wychodzi w środku kadru.
    $css = (string) file_get_contents(CORE_PATH . '/../assets/css/app.css');
    t_true(
        (bool) preg_match('/rm-scan-on \.rm-bg-pill/', $css),
        'pastylka nagrywania znika na czas skanowania (sesja nagrywania trwa dalej)'
    );

    // WYGASZENIE STRONY MUSI WYGRYWAĆ Z KAŻDĄ REGUŁĄ EKRANU (naprawa 2026-08-29,
    // zgłoszenie usera: „klikam skanuj, a strona zostaje w tle").
    // `html.rm-scan-on .wrap` to dwie klasy; `body.is-app.map-page .wrap
    // {display:flex}` to trzy — więc na ekranach z mapą skaner startował
    // z całym interfejsem na wierzchu. Podbicie specyficzności naprawiłoby
    // tamten jeden przypadek i pękło przy następnym `bodyClass`, więc stan
    // „trwa kadr" jest wyłącznikiem awaryjnym i ma `!important`.
    t_true(
        (bool) preg_match('/html\.rm-scan-on \.wrap,[^{]*\{display:none !important;\}/s', $css),
        'na czas skanowania strona gaśnie NIEZALEŻNIE od reguł ekranu'
    );
    t_true(
        (bool) preg_match('/html\.rm-scan-on body\{background:transparent !important;\}/', $css),
        'i tło też — inaczej zamiast kadru z aparatu widać kolor strony'
    );
});

t_test('mapa: obie warstwy bronią się przed kadrem bez rozmiaru tak samo', function () {
    // Zgłoszenie usera 2026-08-23: „skarby się na produkcji od razu nie ładują,
    // trzeba rozzoomować i zoomować". Przyczyną był ROZJAZD DWÓCH BRAMEK na ten
    // sam warunek w jednym pliku: warstwa pól przy kontenerze bez rozmiaru
    // ponawiała próbę, a warstwa skarbów po prostu rezygnowała — i sprawdzała
    // tylko szerokość, więc kontener o zerowej WYSOKOŚCI przechodził dalej
    // i wysyłał kadr, w którym north === south. Serwer słusznie odpowiadał
    // pustką, warstwa czyściła się do zera i tak zostawało.
    //
    // Objaw był mylący, bo pola odkryć ładowały się normalnie — mapa wyglądała
    // na sprawną, brakowało wyłącznie skarbów. Test pilnuje, żeby obie bramki
    // sprawdzały OBA wymiary i obie ponawiały.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/discovery-map.js');

    $bramki = preg_match_all('/map\.getSize\(\)\.x\s*<\s*1\s*\|\|\s*map\.getSize\(\)\.y\s*<\s*1/', $js);
    t_eq(2, $bramki, 'obie warstwy sprawdzają szerokość I wysokość');

    // Sama bramka nie wystarczy — bez ponowienia rezygnacja jest ostateczna
    // i nic już nie pyta o skarby aż do ruchu mapą.
    t_false(
        (bool) preg_match('/map\.getSize\(\)\.x\s*<\s*1\s*\)\s*\{\s*return;/', $js),
        'żadna bramka nie kończy się samym return'
    );

    // Przerywanie żądania w locie MUSI być warunkowe: `scheduleRefresh` woła
    // warstwę co 250 ms, a na łączu wolniejszym niż to każde wywołanie ubijało
    // poprzednie, zanim zdążyło wrócić — czyli skarby nie ładowały się nigdy.
    t_true(str_contains($js, 'treasurePendingUrl === adresZadania'), 'to samo pytanie w locie nie jest ponawiane');
    // ...ale slot musi się zwalniać, inaczej drugie żądanie o ten sam kadr
    // (zaliczenie skarbu z lokalizacji) nie poszłoby już nigdy.
    t_true(str_contains($js, 'treasurePending === mojeZadanie'), 'zakończone żądanie zwalnia slot');
});

t_test('podkład mapy: raster OpenStreetMap domyślnie, OpenFreeMap dalej dostępny', function () {
    // Zmiana podkładu (2026-08-25): OpenFreeMap jest WEKTOROWY, więc Leaflet
    // potrzebuje MapLibre GL + oficjalnego bindingu (@maplibre/maplibre-gl-leaflet,
    // ścieżka „Using Leaflet" z openfreemap.org). Test pilnuje trzech rzeczy
    // naraz, bo każda z nich psuje się po cichu: domyślnego podkładu, fallbacku
    // przy braku WebGL i atrybucji, której styl Positron sam nie dostarcza.
    require_once CORE_PATH . '/Controllers/Support.php';
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/gpx-map.js');
    $support = \Controllers\Support::leafletHead();

    // PRZEŁĄCZONE 2026-08-29 na prośbę usera: „przełączmy w całej aplikacji
    // webowej warstwę na OpenStreetMap, bo kafle mapy bazowej nie chcą się
    // ładować". Wektor wymaga WebGL, stylu, glifów i sprite'ów, zanim narysuje
    // pierwszy piksel, a przy niedostępnym hoście MapLibre zostawia SZARĄ mapę
    // zamiast zgłosić błąd. Raster rysuje się kafel po kaflu i widać go od razu.
    t_true(str_contains($js, "RIDEMORE_BASE_DEFAULT = 'osm'"), 'domyślnym podkładem jest raster OpenStreetMap');
    t_true(str_contains($js, "'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'"), 'endpoint rastra OSM na miejscu');

    // OpenFreeMap NIE zostaje usunięty — zmieniło się WYŁĄCZNIE to, co dostaje
    // ktoś, kto niczego nie wybrał. Cała obsługa wektora zostaje sprawna.
    t_true(str_contains($js, 'https://tiles.openfreemap.org/styles/positron'), 'styl Positron nadal w zestawie');
    t_true(str_contains($js, 'function ridemoreSetBaseLayer('), 'przełącznik podkładu gotowy dla przyszłego UI');

    // Brak WebGL (stare WebView, wyłączona akceleracja) nie może zostawić
    // szarej mapy — spadamy na raster OSM zamiast ciszy.
    t_true(str_contains($js, 'function ridemoreWebGlAvailable('), 'wykrywanie WebGL przed budową podkładu GL');
    t_true(str_contains($js, 'def = RIDEMORE_BASE_LAYERS.osm'), 'brak WebGL = raster OSM, nie pusta mapa');

    // Atrybucja: styl Positron nie niesie jej w źródłach (zweryfikowane na
    // JSON-ie), więc musi iść przez customAttribution — inaczej kontrolka
    // Leafleta pokazałaby pustkę.
    t_true(str_contains($js, 'customAttribution'), 'atrybucja OFM podana przez binding');

    // Limit zoomu: dotąd pochodził z warstwy kafli OSM (TileLayer nadaje mapie
    // swój maxZoom); warstwa wektorowa nie jest GridLayer-em, więc fabryka
    // musi go podać jawnie.
    t_true((bool) preg_match('/L\.map\(el,\s*Object\.assign\(\{\s*maxZoom:\s*18\s*\}/', $js), 'maxZoom 18 podany jawnie fabryce mapy');

    // Podłoga zoomu (2026-08-25): bez niej mapa startowała w widoku kontynentu,
    // gdzie podkład wektorowy jest prawie pusty, a kafle Ridemore i tak mają
    // minZoom 4 — wyglądało to jak niedziałające ładowanie. ŚWIADOMIE bez
    // maxBounds: ograniczamy tylko zoom, nie geografię (serwis może się
    // rozrastać na kolejne kraje).
    t_true(str_contains($js, 'minZoom: 5'), 'podłoga zoomu (widok kontynentu nieosiągalny)');
    t_false(str_contains($js, 'maxBounds'), 'żadnych ram geograficznych — tylko zoom');

    // Skrypty GL dokładane tam, gdzie Leaflet — KOLEJNOŚĆ ma znaczenie,
    // bo binding czyta globalne L i maplibregl już przy starcie skryptu.
    foreach (['maplibre-gl.css', 'maplibre-gl.js', '@maplibre/maplibre-gl-leaflet'] as $igla) {
        t_true(str_contains($support, $igla), "leafletHead dokłada {$igla}");
    }
    t_true(
        strrpos($support, 'maplibre-gl@5') !== false
        && strrpos($support, 'maplibre-gl@5') < strrpos($support, '@maplibre/maplibre-gl-leaflet'),
        'binding ładuje się PO maplibre-gl'
    );

    // Wydajność (2026-08-25, „strasznie wolno się ładuje"): ciężkie skrypty GL
    // (~1 MB) idą z DEFER — synchroniczne blokowały render całej strony do ich
    // pobrania. Mapy stworzone wcześniej niż defer się wykona łapie kolejka
    // i dostaje wektor po dojściu skryptów (raster OSM na czas przejściowy).
    t_true(str_contains($support, '<script defer src="https://cdn.jsdelivr.net/npm/maplibre-gl@5'), 'maplibre-gl z defer (nie blokuje renderu)');
    t_true(str_contains($support, '<script defer src="https://cdn.jsdelivr.net/npm/@maplibre/maplibre-gl-leaflet'), 'binding z defer');
    t_true(str_contains($support, 'rel="preconnect" href="https://tiles.openfreemap.org"'), 'preconnect do hosta kafli/fontów OFM');
    t_true(str_contains($js, 'RIDEMORE_GL_CZEKA'), 'kolejka map czekających na skrypty GL');
    t_true(str_contains($js, 'function ridemoreCzekajNaGl('), 'automatyczna podmiana podkładu po dojściu GL');
});

// --- FORMULARZ ZNANEJ TRASY: co się dzieje po odrzuconym zapisie -------
//
// Do 2026-08-23 błąd walicacji wracał przekierowaniem i czyścił CAŁY formularz
// — nazwę, opis, region i wskazany plik GPX. Teraz kontroler renderuje partial
// z `$krStare` (pola prosto z odrzuconego POST-a) i to one mają pierwszeństwo
// przed wierszem z bazy. Te testy pilnują obu kierunków tej reguły.

/** HTML formularza znanej trasy; partial pisze na wyjście, nie zwraca. */
function w_kr_form(?array $krRoute, ?array $krStare): string
{
    $krWroc = [];
    ob_start();
    require CORE_PATH . '/../views/web/partials/known-route-form.php';
    return (string) ob_get_clean();
}

/** Wiersz known_routes w kształcie, w jakim czyta go formularz. */
function w_kr_row(array $nadpisz = []): array
{
    return array_merge([
        'id'               => 7,
        'name'             => 'Z bazy',
        'description'      => 'Opis z bazy',
        // Od migr. 074 region jest wyprowadzony (known_route_regions), nie
        // polem formularza — 'region_label' to jedyne, co partial czyta.
        'region_label'     => 'małopolskie',
        'bonus_points'     => 100,
        'completion_bonus' => 200,
        'bonus_enabled'    => 1,
        'cover_photo_url'  => null,
        // Doszły 2026-09-12 razem z przebudową ekranu: formularz pokazuje
        // teraz, JAKI plik trasa ma teraz (nazwa, liczba pól, dystans) i daje
        // go pobrać. Fixture ma być wierszem w kształcie, w jakim czyta go
        // partial — niepełny przestałby nim być.
        'slug'             => 'z-bazy',
        'gpx_url'          => '/assets/uploads/gpx/z-bazy.gpx',
        'cells_total'      => 120,
        'distance_km'      => 42.5,
    ], $nadpisz);
}

t_test('formularz trasy: DODAWANIE po błędzie oddaje to, co wpisano', function () {
    // Najboleśniejszy przypadek: przy dodawaniu $krRoute jest nullem, więc bez
    // $krStare nie ma dosłownie NIC do pokazania i cały formularz jest pusty.
    // Region w ogóle się nie renderuje przy dodawaniu — nowa trasa nie ma
    // jeszcze przebiegu, z którego dałoby się go wyliczyć.
    $html = w_kr_form(null, [
        'name'        => 'Green Velo',
        'description' => 'Wschodni Szlak Rowerowy',
        'gpx_token'   => null,
    ]);

    t_true(str_contains($html, 'value="Green Velo"'), 'nazwa wróciła');
    t_true(str_contains($html, 'Wschodni Szlak Rowerowy</textarea>'), 'opis wrócił');
    // Klasa bloku regionu to od 2026-09-12 `kr-readonly` (cała przestrzeń
    // nazw formularza ujednolicona na `kr-*`). Sprawdzamy po ETYKIECIE pola,
    // bo to ona jest tu faktem, a nie nazwa klasy CSS.
    t_true(!str_contains($html, '<label>Region</label>'), 'brak bloku regionu przy dodawaniu');
});

t_test('formularz trasy: EDYCJA — wpisane wygrywa z tym, co w bazie', function () {
    $html = w_kr_form(w_kr_row(), [
        'name'             => 'Poprawiona nazwa',
        'description'      => 'Poprawiony opis',
        'bonus_points'     => 999,
        'completion_bonus' => null,
        'bonus_enabled'    => 0,
        'gpx_token'        => null,
    ]);

    t_true(str_contains($html, 'value="Poprawiona nazwa"'), 'nazwa z formularza, nie z bazy');
    t_true(!str_contains($html, 'value="Z bazy"'), 'stara nazwa nie wraca');
    t_true(str_contains($html, 'Poprawiony opis</textarea>'), 'opis z formularza');
    // Region jest tylko-do-odczytu z bazy (wyliczony z GPX) — nie ma go
    // w $krStare, bo formularz go nie wysyła; zawsze pokazuje $krRoute.
    t_true(str_contains($html, 'małopolskie'), 'region z bazy (jedyne źródło, tylko podgląd)');
    // Po atrybutach, nie po ich KOLEJNOŚCI (2026-09-12): przebudowa formularza
    // wsunęła `placeholder="auto"` między `name` a `value`, co wywalało
    // dosłowne dopasowanie, choć wartość była poprawna.
    t_true(
        (bool) preg_match('/name="bonus_points"[^>]*value="999"/', $html),
        'wartość trasy z formularza, nie scalona z bazy'
    );
    // Pole completion_bonus zniknęło z formularza (2026-09-03, model „jedna
    // wartość trasy dzielona między progi równo") — nie ma go już czym
    // pokazać jako puste ani jako cokolwiek innego.
    t_true(!str_contains($html, 'name="completion_bonus"'), 'pola completion_bonus już nie ma w formularzu');
    t_true((bool) preg_match('/name="bonus_enabled" value="1"\s*>/', $html), 'odznaczone zostaje odznaczone');
});

t_test('formularz trasy: zwykłe wejście dalej czyta z bazy', function () {
    // Druga strona tej samej reguły — bez $krStare nic się nie zmienia.
    // Baza (w_kr_row()) ma bonus_points=100 i completion_bonus=200 z LEGACY
    // modelu sprzed 2026-09-03 (dwie osobne kolumny) — formularz pokazuje ich
    // SUMĘ jako jedną wartość trasy (DiscoveryScoring::legacyRouteOverrideTotal),
    // żeby admin nie stracił połowy wcześniej wpisanej wartości samym
    // otwarciem edycji.
    $html = w_kr_form(w_kr_row(), null);

    t_true(str_contains($html, 'value="Z bazy"'), 'nazwa z bazy');
    t_true(str_contains($html, 'Opis z bazy</textarea>'), 'opis z bazy');
    t_true(str_contains($html, 'małopolskie'), 'region z bazy (tylko podgląd)');
    t_true(
        (bool) preg_match('/name="bonus_points"[^>]*value="300"/', $html),
        'wartość trasy to suma starych kolumn (100+200)'
    );
    t_true((bool) preg_match('/name="bonus_enabled" value="1"\s*checked/', $html), 'zaznaczenie z bazy');
});

t_test('formularz trasy: pola mają szerokość na miarę treści, nie całego ekranu', function () {
    // SEDNO PRZEBUDOWY Z 2026-09-12 („na dzień dzisiejszy nie da się tego
    // używać"). Poprzedni formularz dawał każdemu polu klasę `.search-input`
    // wewnątrz `.form-row` (flex + flex:1), przez co pole na 30-znakową nazwę
    // trasy mierzyło 1210 px — dokładnie tyle samo co pole na czterocyfrową
    // liczbę punktów. Ten test pilnuje, żeby tamte klasy nie wróciły.
    $html = w_kr_form(w_kr_row(), null);

    t_true(!str_contains($html, 'class="search-input"'), 'pola nie rozciągają się na całą szerokość');
    t_true(!str_contains($html, 'class="form-row"'), 'formularz nie wraca do układu jednej kolumny');
    t_true(str_contains($html, 'kr-field--name'), 'nazwa ma własną, wąską klasę');
    t_true(str_contains($html, 'kr-field--points'), 'wartość punktowa ma własną, wąską klasę');

    // Grupy zamiast jednego ciągu ośmiu wierszy.
    foreach (['Podstawy', 'Przebieg', 'Wyróżnienie'] as $grupa) {
        t_true(str_contains($html, '>' . $grupa . '</h3>'), 'grupa „' . $grupa . '" jest w formularzu');
    }
});

t_test('formularz trasy: EDYCJA ma dwa zapisy, DODAWANIE jeden', function () {
    // „Zapisz" zostaje na ekranie (żeby dało się zobaczyć skutek na podglądzie
    // obok), „Zapisz i wróć do listy" kończy pracę nad trasą. Rozstrzyga `name`
    // klikniętego przycisku — patrz KnownRouteController::wrocGdzieTrzeba().
    $edycja = w_kr_form(w_kr_row(), null);
    t_true(str_contains($edycja, 'name="zapisz_i_wroc"'), 'edycja ma wyjście na listę');
    t_true(str_contains($edycja, '>Zapisz</button>'), 'edycja ma zapis bez opuszczania ekranu');

    // Przy DODAWANIU nie ma dokąd wracać poza listę, więc drugi przycisk
    // byłby wyborem bez różnicy.
    $nowa = w_kr_form(null, null);
    t_true(!str_contains($nowa, 'name="zapisz_i_wroc"'), 'dodawanie ma jeden przycisk');
    t_true(str_contains($nowa, '>Dodaj trasę</button>'), 'i mówi on, co robi');
});

t_test('formularz trasy: przy edycji widać, JAKI plik GPX trasa ma teraz', function () {
    // Dotąd na ekranie nie było tego nigdzie — zostawał sam input „Podmień
    // przebieg (opcjonalnie)", czyli pytanie bez kontekstu. Skutki podmiany
    // (przeliczenie postępu WSZYSTKICH uczestników) stoją teraz przy tym polu,
    // a nie jako akapit w środku listy.
    $html = w_kr_form(w_kr_row(), null);

    t_true(str_contains($html, 'kr-file__n'), 'nazwa obecnego pliku jest na ekranie');
    t_true(str_contains($html, 'postęp wszystkich uczestników'), 'skutek podmiany stoi przy polu');

    // Przy dodawaniu nie ma jeszcze pliku, więc nie ma czego pokazywać.
    t_true(!str_contains(w_kr_form(null, null), 'kr-file__n'), 'dodawanie nie udaje, że ma plik');
});

t_test('formularz trasy: trzymany plik GPX wraca tokenem, nie polem plikowym', function () {
    // Przeglądarka nigdy nie wypełni <input type="file"> — plik przeżywa błąd
    // w gpx/tmp, a formularz niesie tylko jego token.
    $token = str_repeat('a', 32);
    $html = w_kr_form(null, ['name' => 'X', 'gpx_token' => $token]);

    t_true(str_contains($html, 'name="gpx_token" value="' . $token . '"'), 'token w ukrytym polu');
    // `required` musi zejść, inaczej przeglądarka zablokuje wysyłkę formularza,
    // w którym wszystko jest już w porządku.
    t_true(!str_contains($html, 'accept=".gpx" required'), 'pole pliku przestaje być wymagane');
});

t_test('formularz trasy: bez trzymanego pliku dodawanie WYMAGA pliku', function () {
    $html = w_kr_form(null, ['name' => 'X', 'gpx_token' => null]);

    t_true(!str_contains($html, 'name="gpx_token"'), 'nie ma czego nieść');
    t_true(str_contains($html, 'accept=".gpx" required'), 'plik dalej wymagany');
});

t_test('formularz trasy: to, co wpisano, jest ucieczkowane', function () {
    // Wartości z $krStare przychodzą prosto z POST-a — czyli są najbardziej
    // wrogim wejściem, jakie ten partial widzi.
    $html = w_kr_form(null, [
        'name'        => '" onfocus="alert(1)',
        'description' => '<script>alert(1)</script>',
        'gpx_token'   => null,
    ]);

    t_true(!str_contains($html, 'onfocus="alert(1)"'), 'atrybut się nie wyrywa z wartości');
    t_true(!str_contains($html, '<script>'), 'znacznik nie wychodzi jako znacznik');
    t_true(str_contains($html, '&lt;script&gt;'), 'wychodzi jako tekst');
});

// --- OSTATNIE PRZEJAZDY: jeden moduł zamiast dwóch połówek (2026-08-24) -----

/**
 * Przejazd wstawiony WPROST do bazy, bez pliku GPX.
 *
 * Testowana jest tu KSZTAŁT LISTY dla panelu, a nie liczenie pól — parsowanie
 * kilku tysięcy punktów tylko po to, żeby sprawdzić, czy w wierszu jest data,
 * byłoby płaceniem sekundami za nic. (Właściwa droga zapisu ma własny zestaw:
 * `php tests/run.php przejazdy_solo`.) Helper jest LOKALNY, bo `run.php`
 * z argumentem ładuje wyłącznie pasujące pliki — test, który woła funkcję
 * z cudzego zestawu, przechodzi w pełnym przebiegu i wywala się w wybiórczym.
 */
function w_ride(int $userId, string $source = 'solo'): int
{
    $db = Core\Database::connection();
    $db->prepare('INSERT INTO rider_activities
                    (user_id, source_code, ride_date, distance_km, elevation_gain_m,
                     cells_touched, cells_new)
                  VALUES (:u, :s, CURDATE(), 42.5, 300, 3, 3)')
        ->execute(['u' => $userId, 's' => $source]);
    $activityId = (int) $db->lastInsertId();

    $ins = $db->prepare('INSERT INTO rider_activity_cells (activity_id, cell_id) VALUES (:a, :c)');
    foreach ([[49.20, 22.10], [49.25, 22.15], [49.30, 22.20]] as [$lat, $lon]) {
        $ins->execute(['a' => $activityId, 'c' => Utils\DiscoveryGrid::pointToCell($lat, $lon)]);
    }

    return $activityId;
}


t_test('przejazdy: lista dla mapy niesie WSZYSTKO, czego brakowało panelowi', function () {
    // Zgłoszenie usera: panel „Ostatnia aktywność" pokazywał same punkty —
    // bez daty, bez odkrytych pól i bez sposobu, żeby zobaczyć, gdzie to było.
    // Te trzy rzeczy muszą być w danych, inaczej widok nie ma ich skąd wziąć.
    $userId = t_user();
    w_ride($userId);

    $lista = Models\Discovery::recentActivity($userId, 5);
    t_true(count($lista) > 0, 'przejazd jest na liście');

    $wiersz = $lista[0];
    t_true(array_key_exists('ride_date', $wiersz), 'jest data');
    t_true(array_key_exists('cells_new', $wiersz), 'są odkryte pola');
    t_true(array_key_exists('points_total', $wiersz), 'są punkty');
    t_true(array_key_exists('source_code', $wiersz), 'wiadomo, czy to wyjazd, czy solo');
    t_not_null($wiersz['bounds'], 'jest prostokąt na mapie — inaczej kliknięcie nie ma dokąd prowadzić');
    t_true($wiersz['bounds']['north'] >= $wiersz['bounds']['south'], 'prostokąt jest poprawny');
});

t_test('aktywność: ZNALEZIONE SKARBY są na osi czasu razem z przejazdami', function () {
    // BŁĄD ZGŁOSZONY PRZEZ USERA 2026-08-24: „usunąłeś zdobycia skarbów
    // z aktywności?!". Poprzedni panel czytał cały rejestr naliczeń, więc
    // pokazywał i przejazdy, i znaleziska; pierwsza wersja tej listy brała
    // same przejazdy. Skarb bywa zdobyty BEZ przejazdu (zeskanowana wlepka),
    // więc musi być własnym zdarzeniem, a nie dopiskiem do przejazdu.
    $userId = t_user();
    $skarb = t_treasure(['name' => 'ZZ Skarb testowy', 'points' => 77]);
    Models\Treasure::claim($skarb['code'], $userId, (float) $skarb['lat'], (float) $skarb['lon']);

    $lista = Models\Discovery::recentActivity($userId, 10);
    $skarby = array_values(array_filter($lista, static fn(array $w): bool => $w['kind'] === 'treasure'));

    t_count(1, $skarby, 'znalezisko jest na liście');
    t_eq('ZZ Skarb testowy', $skarby[0]['title'], 'z nazwą — znalazca widzi swój skarb w pełni');
    t_eq(77, $skarby[0]['points_total'], 'z punktami z rejestru');
    t_not_null($skarby[0]['bounds'], 'i z miejscem na mapie');
});

t_test('aktywność: ukryty skarb NIE zdradza nazwy na mapie społeczności', function () {
    // Publiczna oś czasu byłaby najprostszym sposobem na obejście zagadki:
    // wystarczyłoby poczekać, aż ktokolwiek go znajdzie.
    [$a, $b] = t_users(2);
    $ukryty = t_treasure(['name' => 'ZZ Tajemnica', 'reveal_level' => 0, 'points' => 30]);
    Models\Treasure::claim($ukryty['code'], $a, (float) $ukryty['lat'], (float) $ukryty['lon']);

    $wspolne = Models\Discovery::recentActivity(null, 30);
    $nasze = array_values(array_filter(
        $wspolne,
        static fn(array $w): bool => $w['kind'] === 'treasure' && (int) $w['id'] === (int) $ukryty['id']
    ));

    t_count(1, $nasze, 'zdarzenie jest widoczne (fakt, nie tożsamość skarbu)');
    t_true($nasze[0]['title'] !== 'ZZ Tajemnica', 'ale nazwa nie wychodzi');
    t_false(str_contains(json_encode($wspolne), 'ZZ Tajemnica'), 'i nie wycieka żadnym innym polem');
});

t_test('przejazdy: mapa społeczności dokłada AUTORA, osobista go nie potrzebuje', function () {
    // Ten sam moduł na obu mapach — różnica jest jedna i musi być w danych.
    [$a, $b] = t_users(2);
    w_ride($a);

    $wspolne = Models\Discovery::recentActivity(null, 10);
    t_true(count($wspolne) > 0, 'wspólna lista nie jest pusta');
    t_true(!empty($wspolne[0]['user_name']), 'wiadomo, kto jechał');

    $moje = Models\Discovery::recentActivity($a, 10);
    foreach ($moje as $w) {
        t_eq($a, $w['user_id'], 'na mapie osobistej wyłącznie własne przejazdy');
    }
});

// ---------------------------------------------------------------------------
// KONTRAKT WARSTW MAPY (Etap 1, tasks/done/warstwy-mapy.md)
//
// To nie są testy funkcji, tylko STRAŻNICY NIEZMIENNIKA — i dlatego czytają
// pliki widoków. Oba pilnowane tu nawyki wracały już w tym kodzie same z siebie:
// czytnik stanu kontrolki dorobił się czterech kopii pod trzema nazwami, a każda
// nowa warstwa kaflowa dostawała własną opcję modułu. Testu na to nie da się
// napisać przez wywołanie, bo obie rzeczy dzieją się w szablonie.
// ---------------------------------------------------------------------------

/** Wszystkie pliki widoków, w których w ogóle stoi mapa. */
function w_pliki_map(): array
{
    $out = [];
    foreach (glob(CORE_PATH . '/../views/web/pages/*.php') ?: [] as $plik) {
        $tresc = (string) file_get_contents($plik);
        if (str_contains($tresc, 'ridemoreDiscoveryMap(') || str_contains($tresc, 'ridemoreCreateMap(')) {
            $out[basename($plik)] = $tresc;
        }
    }
    t_true(count($out) > 0, 'znaleziono jakiekolwiek widoki z mapą (inaczej test niczego nie sprawdza)');
    return $out;
}

t_test('warstwy: stan kontrolki czyta JEDNA funkcja, nie kopia w każdym widoku', function () {
    // Wzorzec łapie dokładnie to, co robiły cztery kopie: sklejanie selektora
    // `[data-layer="..."]` z nazwą warstwy wewnątrz widoku. Wspólny czytnik
    // (`ridemoreReadLayers` w assets/js/discovery-map.js) selektora nie skleja —
    // bierze WSZYSTKIE przełączniki, które kontrolka ma.
    foreach (w_pliki_map() as $nazwa => $tresc) {
        $ma = preg_match('/\[data-layer="\'\s*\+|\[data-layer="\'\s*\.\s*\$|querySelector\(\s*\'\[data-layer="\'\s*\+/', $tresc);
        t_false((bool) $ma, $nazwa . ' nie skleja własnego selektora warstwy');
    }
});

t_test('warstwy: źródła kafli idą jedną mapą `sources`, a nie opcją na warstwę', function () {
    // `trailsTiles`/`sladyTiles` były osobnymi opcjami modułu. Dołożenie
    // trzeciej warstwy kaflowej znaczyło wtedy dopisanie trzeciej opcji
    // w discovery-map.js ORAZ pamiętanie o niej w każdym widoku z osobna.
    foreach (w_pliki_map() as $nazwa => $tresc) {
        t_false(str_contains($tresc, 'trailsTiles:'), $nazwa . ' nie używa zniesionej opcji trailsTiles');
        t_false(str_contains($tresc, 'sladyTiles:'), $nazwa . ' nie używa zniesionej opcji sladyTiles');
    }

    $modul = (string) file_get_contents(CORE_PATH . '/../assets/js/discovery-map.js');
    t_true(str_contains($modul, 'window.ridemoreReadLayers'), 'moduł wystawia wspólny czytnik kontrolki');
    t_true(str_contains($modul, 'options.sources'), 'moduł czyta źródła kafli z mapy `sources`');
    t_true(str_contains($modul, 'options.context'), 'moduł przyjmuje kontekst mapy');
});

// ============================================================================
// APKA 2026-08-29 — trzy zmiany prezentacyjne z kontraktów:
//   tasks/done/mapa-apka-bez-zoomu.md
//   tasks/done/mapa-apka-kadr-na-mnie.md
//   tasks/done/zglos-skarb-w-terenie.md
// Wszystkie sprawdzane STATYCZNIE (regex na źródle), tym samym sposobem co
// bramki APP_IS_APP wyżej: gałęzi „w apce" nie da się wyrenderować w CLI,
// bo APP_IS_APP jest tam zawsze fałszywe.
// ============================================================================

t_test('apka: mapa nie pokazuje kontrolek zoomu ani pełnego ekranu', function () {
    // Reguła mieszka w app.css od Fazy 3 (2026-08-29) — arkusz ładowany
    // WYŁĄCZNIE przez skorupę apki, więc przeglądarka go nie pobiera.
    $css = (string) file_get_contents(CORE_PATH . '/../assets/css/app.css');
    $wspolny = (string) file_get_contents(CORE_PATH . '/../assets/css/style.css');

    t_true(
        (bool) preg_match('/body\.is-app\s+\.leaflet-control-zoom\s*,\s*body\.is-app\s+\.map-fs__ctrl\s*\{[^}]*display\s*:\s*none/s', $css),
        'obie kontrolki schowane jedną regułą pod body.is-app'
    );

    // NEGATYWNY, i to jest tu najważniejszy test: powiększenie celów dotykowych
    // ma bramkę @media(pointer:coarse), czyli SPOSÓB OBSŁUGI, nie tryb apki —
    // obowiązuje też w przeglądarce mobilnej i dotyczy przełącznika warstw,
    // który zostaje. Pierwsza wersja kontraktu kazała je usunąć jako „martwe".
    t_true(
        str_contains($wspolny, '.leaflet-container.leaflet-touch .leaflet-bar a{width:40px'),
        'powiększenie celów dotykowych ZOSTAJE w arkuszu WSPÓLNYM — służy web-mobile'
    );

    // Fabryka map jest wspólna dla web i apki — gdyby ktoś „poprawił" to
    // parametrem, zoom zniknąłby też w przeglądarce.
    $fabryka = (string) file_get_contents(CORE_PATH . '/../assets/js/gpx-map.js');
    t_true(
        !str_contains($fabryka, 'zoomControl: false') && !str_contains($fabryka, 'zoomControl:false'),
        'ridemoreCreateMap() NIE wyłącza zoomu — to ma być wyłącznie warstwa CSS'
    );
});

t_test('apka: mapa odkryć centruje się na człowieku, nie na prostokącie odkryć', function () {
    $src = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery-app.php');

    // Kadr z serwera NIE MOŻE iść do silnika: applyStartBounds() czeka, aż
    // kontener urośnie do 40 px, i ponawia próbę ResizeObserverem, więc
    // potrafi odpalić PO pierwszym odczycie GPS i skasować wycentrowanie.
    t_true(str_contains($src, 'bounds: null'), 'kadr z serwera nie idzie do silnika mapy');
    t_true(str_contains($src, 'fitToCells: false'), 'dociąganie do wczytanych pól wyłączone');

    // ...ale MUSI zostać dostępny w JS jako kadr awaryjny, inaczej odmowa GPS
    // zostawiłaby usera na mapie całego kraju zamiast na jego odkryciach.
    t_true(str_contains($src, 'kadrZSerwera'), 'prostokąt odkryć zostaje jako kadr awaryjny');
    t_true(str_contains($src, 'userRuszylMapa'), 'ruch palcem blokuje automaty kadru');
    t_true(
        (bool) preg_match('/kadrUstawiony\s*=\s*true;\s*\n\s*map\.setView/s', $src),
        'flaga podnoszona PRZED setView — inaczej własny ruch policzyłby się jako ruch usera'
    );
});

t_test('zgłoszenie skarbu w apce: zdjęcie dochodzi do photo_url', function () {
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasure-propose-app.php');

    // Bez enctype plik nie doszedłby do $_FILES, a reszta pól działałaby dalej
    // — czyli usterka byłaby CICHA. Stąd osobna asercja, nie domysł.
    t_true(str_contains($widok, 'enctype="multipart/form-data"'), 'formularz niesie pliki');
    t_true(
        str_contains($widok, 'id="tpPhoto" name="photo"')
        && str_contains($widok, 'data-rm-camera-for="tpPhoto"'),
        'przycisk aparatu celuje w id istniejącego inputu'
    );
    // Wyszukiwarka miejsc jest przeciwieństwem ekranu terenowego — ma zostać
    // wyłącznie na wersji webowej.
    t_true(!str_contains($widok, 'id="zgSzukaj"'), 'wyszukiwarka miejsc usunięta z ekranu terenowego');
    t_true(str_contains($widok, 'pobierzPozycje(true)'), 'GPS startuje sam przy wejściu na ekran');

    $kontroler = (string) file_get_contents(CORE_PATH . '/../core/Controllers/TreasureProposalController.php');
    t_true(
        (bool) preg_match('/Upload::saveCoverPhoto\(\$_FILES\[.photo.\]\)/', $kontroler),
        'ten sam helper co okładka skarbu w panelu admina, nie własny upload'
    );
    t_true(
        // APOSTROFY JAKO `.`, delimiter `~` — wzorzec w cudzysłowie podwójnym
        // wciągał `$photoUrl` jako zmienną PHP (pustą), przez co wyrażenie
        // traciło domykający ukośnik i preg_match zwracał false z warningiem.
        (bool) preg_match('~.photo_url.\s*=>\s*\$photoUrl~', $kontroler),
        'photo_url ląduje w tablicy Treasure::save()'
    );

    // Web bez zmian — wyszukiwarka i brak zdjęcia to jego świadomy stan.
    $web = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasure-propose.php');
    t_true(str_contains($web, 'tp-search'), 'wersja webowa zachowuje wyszukiwarkę miejsc');
});

t_test('zgłoszenie skarbu: limit mówi „nie" PRZED wypełnieniem, nie po wysłaniu', function () {
    // Zasada „formularze nie gubią danych". `save()` odrzuca nadmiarowe
    // zgłoszenie przekierowaniem, które gubi CAŁY formularz — w apce razem
    // ze zdjęciem zrobionym przy obiekcie, nie do odtworzenia spod domu.
    $kontroler = (string) file_get_contents(CORE_PATH . '/../core/Controllers/TreasureProposalController.php');
    t_true(
        (bool) preg_match("/'limitOsiagniety'\s*=>\s*count\(\\\$moje\)\s*>=\s*self::MAX_OPEN/", $kontroler),
        'widok dostaje gotową odpowiedź na pytanie o limit'
    );
    // TA SAMA stała po obu stronach — osobna liczba w widoku rozjechałaby się
    // przy pierwszej zmianie limitu i ekran obiecywałby coś, czego save() nie
    // przyjmie (albo odwrotnie).
    t_true(
        substr_count($kontroler, 'self::MAX_OPEN') >= 2,
        'limit liczony z jednej stałej w obu miejscach'
    );
    // Jedno zapytanie, nie dwa — lista „w poczekalni" i bramka limitu to ta
    // sama odpowiedź z bazy.
    t_true(
        substr_count($kontroler, 'Treasure::proposedBy(Auth::user()->id)') === 1,
        'proposedBy wołane raz, wynik dzielony między widok i bramkę'
    );

    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasure-propose-app.php');
    t_true(str_contains($widok, 'if ($limitOsiagniety):'), 'widok chowa formularz przy limicie');
    // Bez tej bramki JS wywaliłby się na nieistniejących polach formularza
    // i zabrał ze sobą mapę, która przy limicie ma zostać sprawna.
    t_true(str_contains($widok, 'if (!poleLat) { return; }'), 'JS kończy przed obsługą nieistniejącego formularza');
});

t_test('most aparatu: pole jednoplikowe dostaje zdjęcie ZAMIAST, nie OPRÓCZ', function () {
    // Usterka znaleziona przy zgłoszeniu skarbu (2026-08-29): delegacja
    // dokładała do input.files bezwarunkowo. Galeria skarbu ma `multiple`,
    // więc było to poprawne i niewidoczne; pole na okładkę nie ma, a lista
    // dwuelementowa na takim polu to zachowanie zależne od przeglądarki.
    $src = (string) file_get_contents(CORE_PATH . '/../assets/js/native.js');
    t_true(
        (bool) preg_match('/if\s*\(\s*input\.multiple\s*\)\s*\{[^}]*dt\.items\.add\(input\.files\[i\]\)/s', $src),
        'dokładanie istniejących plików tylko przy input[multiple]'
    );
});

t_test('apka: pusty slug profilu nie prowadzi w 404 (a więc w fałszywy „brak połączenia")', function () {
    // Zgłoszenie usera 2026-08-29: „kliknąłem na profil i mam komunikat
    // »Brak połączenia«" przy działającym internecie. `User::publicSlug` jest
    // NULLOWALNE (nadawane przy potwierdzeniu rejestracji i przy zmianie
    // nazwy), więc konto, które tamtędy nie przeszło, sklejało adres do
    // `/rowerzysta/` — 404, a WebView zamienia błąd wczytania na ekran
    // offline z `errorPath`.
    $src = (string) file_get_contents(CORE_PATH . '/../views/web/partials/app-nav.php');

    t_false(
        (bool) preg_match("/\\$profileUrl\s*=\s*\\$currentUser\s*\?\s*'\/rowerzysta\/'/", $src),
        'brak sklejania adresu bez sprawdzenia sluga'
    );
    t_true(
        (bool) preg_match('/publicSlug\s*\?\s*.\/rowerzysta\/.\s*\.\s*\$currentUser->publicSlug\s*:\s*.\/admin\/moje-konto./s', $src),
        'pusty slug prowadzi do ustawień konta, nie w nieistniejący adres'
    );
    // Slot musi ZOSTAĆ, nie zniknąć jak pozycja w menu web — od przeniesienia
    // menu konta na ekran profilu to jedyne wejście do ustawień i wylogowania
    // w apce. Liczbę slotów pilnuje osobny test na wyrenderowanym HTML-u;
    // tutaj wystarczy, że adres NIGDY nie jest pusty.
    t_true(str_contains($src, "\$profileUrl = '/logowanie';"), 'adres profilu ma wartość domyślną, nie pustą');
});

t_test('offline: wskaźnik zamiast blokady, tylko w apce', function () {
    // tasks/active/apka-offline.md, Etap 1. Zgłoszenie usera z terenu:
    // „aplikacja nie może blokować mapy w trybie offline (…) może być jakaś
    // ikonka offline, a nie blokada".
    $cfg = (string) file_get_contents(CORE_PATH . '/../app/capacitor.config.ts');
    $app = (string) file_get_contents(CORE_PATH . '/../views/web/layout-app.php');
    $web = (string) file_get_contents(CORE_PATH . '/../views/web/layout.php');

    // ZMIENIONE 2026-08-29 PO ZGŁOSZENIU Z TERENU („brak zasięgu, czarny
    // ekran, nic się nie da zrobić"). Etap 1 usunął `errorPath`, żeby błąd
    // HTTP nie kłamał o sieci — ale przy okazji zabrał JEDYNY mechanizm
    // pokazujący `app/www/index.html`, czyli własne kryterium akceptacji nr 4.
    // `errorPath` wraca, a zakaz kłamania o przyczynie przenosi się tam, gdzie
    // da się go spełnić bez utraty ekranu: do samej strony, która najpierw
    // pyta serwer (patrz test „apka: start bez sieci ma co pokazać").
    t_true(
        (bool) preg_match('/^\s*errorPath\s*:/m', $cfg),
        'apka ma co pokazać przy starcie bez sieci'
    );
    t_false(
        str_contains(
            (string) file_get_contents(CORE_PATH . '/../app/www/index.html'),
            '<h1>Brak połączenia</h1>'
        ),
        'ale nie twierdzi „brak połączenia" z góry — przyczynę ustala dopiero po zapytaniu serwera'
    );
    t_true(str_contains($app, 'app-offline.php'), 'skorupa apki niesie wskaźnik');
    t_false(str_contains($web, 'app-offline.php'), 'web nie dostaje wskaźnika');
});

t_test('service worker: tylko apka, tylko biała lista, cudzych cache nie rusza', function () {
    $sw   = (string) file_get_contents(CORE_PATH . '/../sw-app.js');
    $head = (string) file_get_contents(CORE_PATH . '/../views/web/partials/head.php');
    $ht   = (string) file_get_contents(CORE_PATH . '/../.htaccess');

    // DECYZJA USERA: „service worker tylko w apce". Rejestracja jest
    // per-przeglądarka, więc bramka APP_IS_APP wokół niej JEST całym
    // mechanizmem ograniczenia ryzyka dla www.
    t_true(
        (bool) preg_match('/APP_IS_APP.{0,3000}serviceWorker.{0,400}sw-app\.js/s', $head),
        'rejestracja stoi za bramką APP_IS_APP'
    );

    // Kill-switch po starej aplikacji One Page MUSI zostać nietknięty —
    // to jedyny sposób, żeby przeglądarki z pamięcią po niej się wypisały.
    $stary = (string) file_get_contents(CORE_PATH . '/../sw.js');
    t_false(str_contains($stary, "addEventListener('fetch'"), '/sw.js dalej niczego nie przechwytuje');
    t_true(str_contains($stary, 'skipWaiting'), '/sw.js dalej jest kill-switchem');

    // NIE WOLNO kasować cudzych cache: `caches.keys()` widzi cały origin.
    t_true(
        (bool) preg_match('/nasze\.some\(\(p\)\s*=>\s*k\.startsWith\(p\)\)/', $sw),
        'kasujemy wyłącznie własne cache, po prefiksie'
    );
    // POST-y (logowanie, zapisy, wysyłka śladu) nie mogą przechodzić przez worker.
    t_true(
        (bool) preg_match("/req\.method\s*!==\s*'GET'.{0,40}return/s", $sw),
        'wszystko poza GET przepuszczane bez tknięcia'
    );
    // Adres kafli sprawdzony w kodzie: statyczne PNG, nie tiles.php
    // (ten jest zablokowany w .htaccess dla ruchu HTTP).
    t_true(str_contains($sw, "'/assets/tiles/'"), 'kafle łapane pod prawdziwym adresem');
    t_false(str_contains($sw, "endsWith('/tiles.php')"), 'nie celujemy w zablokowany tiles.php');

    // Worker zamrożony na rok byłby najgorszym możliwym stanem — to on
    // decyduje, co apka pobiera.
    t_true(
        str_contains($ht, 'sw-app\.js$).+\.(css|js'),
        'sw-app.js wykluczony z długiego cache dla assetów'
    );
});

t_test('apka: dolny pasek ma jeden zestaw ikon i jedną typografię podpisów', function () {
    // Zgłoszenie usera 2026-08-29: „ikony w aplikacji w footer są różne
    // i podpisy też". Pasek mieszał ikony konturowe, jedną wypełnioną, emoji
    // i znak typograficzny — te dwa ostatnie rysuje font systemu, więc nie
    // dawało się ich ani dopasować grubością, ani pokolorować.
    $nav  = (string) file_get_contents(CORE_PATH . '/../views/web/partials/app-nav.php');
    $css  = (string) file_get_contents(CORE_PATH . '/../assets/css/app.css');
    $ikon = (string) file_get_contents(CORE_PATH . '/Utils/Icon.php');

    // ŻADNYCH ZNAKÓW RYSOWANYCH FONTEM w miejscu ikony.
    t_false(str_contains($nav, '🗺'), 'brak emoji w pasku');
    t_false(str_contains($nav, '⌗'), 'brak znaku typograficznego w miejscu ikony');

    // PIĘĆ SLOTÓW, PIĘĆ IKON, PIĘĆ PODPISÓW.
    //
    // Liczymy NAZWY ikon, nie wystąpienia w źródle (zmiana 2026-08-30): slot
    // „Mapa" ma od tej pory dwa warianty w tym samym pliku — link, gdy jesteś
    // gdzie indziej, i przycisk „wyśrodkuj na mnie", gdy jesteś już na mapie —
    // więc jego ikona pada w źródle dwa razy, choć slot dalej jest jeden.
    preg_match_all("/Icon::render\('([a-z-]+)'\)/", $nav, $wszystkie);
    t_eq(5, count(array_unique($wszystkie[1])), 'każdy z pięciu slotów ma ikonę z jednego rejestru');
    t_eq(2, substr_count($nav, "Icon::render('map')"), 'slot Mapy ma dokładnie dwa warianty: link i przycisk');
    // Podpis profilu jest zmienną (Profil/Zaloguj), reszta stała.
    foreach (['Wyjazdy', 'Zgłoś', 'Mapa', 'Skanuj', '<?= $profileLabel ?>'] as $podpis) {
        // Od wielojęzyczności (2026-09-16) stały podpis idzie przez __() — liczy się,
        // że jest widoczny w <span>, nie w jakiej formie stoi w źródle.
        t_true(str_contains($nav, '<span>' . $podpis . '</span>') || str_contains($nav, "<span><?= __('" . $podpis . "') ?></span>"), 'slot ma widoczny podpis: ' . $podpis);
    }

    // JEDEN SYSTEM RYSUNKU: wszystkie pięć ikon paska konturowe, tej samej grubości.
    preg_match_all("/Icon::render\('([a-z-]+)'\)/", $nav, $uzyte);
    foreach ($uzyte[1] as $nazwa) {
        t_true(
            (bool) preg_match("/'" . preg_quote($nazwa, '/') . "' => '<svg[^>]*fill=\"none\"[^>]*stroke-width=\"2\"/", $ikon),
            "ikona „$nazwa\" jest konturowa i ma grubość 2 — jak reszta paska"
        );
    }

    // Typografia podpisów należy do `.app-nav__i`. Guzik skanowania nie może
    // jej nadpisywać skrótem `font:` (patrz komentarz przy regule w app.css).
    t_true(
        (bool) preg_match('/\.app-nav__i--btn\{([^}]*)\}/', $css, $btn),
        'reguła guzika istnieje'
    );
    t_false(str_contains($btn[1], 'font:'), 'guzik skanowania nie nadpisuje kroju i rozmiaru podpisu');
});

t_test('apka: belka i nakładki mapy nie mają jak się rozjechać', function () {
    // Zgłoszenie usera z telefonu 2026-08-29, dwa zrzuty obok siebie:
    // „header w jednym i drugim screenie (…) są różne i nie są spójne" oraz
    // „przycisk warstw oraz punktacji nie jest na tym samym poziomie (…)
    // powinny być znacznie wyżej przy samej górnej części mapy".
    $css = (string) file_get_contents(CORE_PATH . '/../assets/css/app.css');

    // BELKA. `.wrap` na mapie traci wcięcie (mapa ma być pełnoekranowa),
    // a `<header>` siedzi w tym samym `.wrap` — więc bez rekompensaty logo
    // dotykało krawędzi ekranu, a kreska pod belką raz szła od brzegu do
    // brzegu, raz kończyła się 24 px wcześniej.
    t_true(str_contains($css, 'body.is-app{--app-wrap-pad:24px;}'), 'wcięcie `.wrap` w apce nazwane zmienną');
    t_true(str_contains($css, 'body.is-app.map-page{--app-wrap-pad:0px;}'), 'na mapie wcięcia nie ma i CSS o tym wie');
    t_true(
        (bool) preg_match('/\.app-header\{[^}]*padding:[^;]*24px[^;]*;[^}]*margin-left:calc\(-1 \* var\(--app-wrap-pad/s', $css),
        'belka sama dokłada 24 px w środku i wychodzi ujemnym marginesem o tyle, ile ma wcięcia .wrap'
    );

    // NAKŁADKI MAPY. Jedna wartość dla obu — nie dwie liczby do pilnowania.
    t_true(str_contains($css, 'body.is-app.map-page{--app-map-pad:10px;}'), 'jeden odstęp nakładek od krawędzi mapy');
    t_true(
        (bool) preg_match('/\.map-layers\{top:var\(--app-map-pad\);left:var\(--app-map-pad\)/', $css),
        'przełącznik warstw liczy się od tej zmiennej'
    );
    t_true(
        (bool) preg_match('/\.app-map__stats\{position:absolute;top:var\(--app-map-pad\);right:var\(--app-map-pad\)/', $css),
        'chip statystyk liczy się od TEJ SAMEJ zmiennej — stąd wspólny poziom'
    );
    // Wcięcie na wycięcie ekranu bierze na siebie NAGŁÓWEK stojący nad mapą.
    // Doliczanie go drugi raz w chipie spychało go o wysokość paska stanu.
    t_false(
        (bool) preg_match('/\.app-map__stats\{[^}]*env\(safe-area-inset-top/', $css),
        'chip nie dolicza wycięcia ekranu drugi raz'
    );
});

t_test('apka: ekran startowy i ikony zrobione z logo', function () {
    // Prośba usera 2026-08-29: „masz plik logo_hex, możesz użyć podczas
    // odpalania aplikacji oraz jako ikonkę". Wszystko generuje
    // `app/gen-ikony.php` z `assets/logo/logo_hex.png`.
    $app = CORE_PATH . '/../app';
    $res = $app . '/android/app/src/main/res';

    // KOMPLET ROZMIARÓW. Brak jednego pliku nie wywala builda — Android po
    // cichu weźmie wariant z innego dpi i ikona będzie rozmyta na części
    // telefonów, czego nie widać na jednym urządzeniu testowym.
    $gestosci = ['mdpi' => 48, 'hdpi' => 72, 'xhdpi' => 96, 'xxhdpi' => 144, 'xxxhdpi' => 192];
    foreach ($gestosci as $dpi => $px) {
        foreach (['ic_launcher', 'ic_launcher_round'] as $nazwa) {
            $plik = "$res/mipmap-$dpi/$nazwa.png";
            t_true(is_file($plik), "$nazwa dla $dpi istnieje");
            $wymiar = getimagesize($plik);
            t_eq($px, $wymiar[0], "$nazwa $dpi ma właściwą szerokość");
        }
        // Warstwa adaptacyjna to 108 dp, czyli 2,25× ikony klasycznej.
        $fg = getimagesize("$res/mipmap-$dpi/ic_launcher_foreground.png");
        t_eq((int) round($px * 2.25), $fg[0], "warstwa adaptacyjna $dpi ma 108 dp");
    }

    // IKONA iOS NIE MOŻE MIEĆ KANAŁU ALFA — App Store odrzuca takie zgłoszenie,
    // a dowiedzieć się o tym przy wysyłce buildu to najgorszy moment.
    $ios = $app . '/ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png';
    $info = getimagesize($ios);
    t_eq(1024, $info[0], 'ikona iOS ma 1024 px');
    // Bajt 25 nagłówka PNG to typ koloru: 6 i 4 niosą kanał alfa, 2 (truecolor)
    // i 3 (paleta) nie. Czytamy plik, a nie ufamy temu, czym miał być.
    $typKoloru = ord(file_get_contents($ios, false, null, 25, 1));
    t_false(in_array($typKoloru, [4, 6], true), 'ikona iOS jest bez kanału alfa — App Store odrzuca ikony z przezroczystością');

    // TŁO EKRANU STARTOWEGO: kolor z konfiguracji MUSI być tym samym, co tło
    // wygenerowanych obrazków — inaczej start apki błyska dwoma kolorami.
    $config = (string) file_get_contents($app . '/capacitor.config.ts');
    t_true((bool) preg_match("/backgroundColor:\s*'(#[0-9A-Fa-f]{6})'/", $config, $m), 'konfiguracja podaje kolor tła');
    $splash = imagecreatefrompng("$res/drawable-port-xxhdpi/splash.png");
    $rog = imagecolorsforindex($splash, imagecolorat($splash, 5, 5));
    t_eq(
        strtoupper($m[1]),
        sprintf('#%02X%02X%02X', $rog['red'], $rog['green'], $rog['blue']),
        'tło obrazka ekranu startowego zgadza się z kolorem z konfiguracji'
    );
});

t_test('apka: start bez sieci ma co pokazać, a ekran awaryjny nie zgaduje przyczyny', function () {
    // Zgłoszenie z terenu 2026-08-29: „nie miałem zasięgu, chciałem uruchomić
    // aplikację, nic się nie dało zrobić, czarny ekran". `errorPath` usunięty
    // tego samego dnia rano był JEDYNYM mechanizmem pokazującym
    // `app/www/index.html` — kryterium akceptacji nr 4 kontraktu
    // apka-offline.md (Etap 1) nie było spełnione od chwili zapisania.
    $config = (string) file_get_contents(CORE_PATH . '/../app/capacitor.config.ts');
    $ekran  = (string) file_get_contents(CORE_PATH . '/../app/www/index.html');

    t_true(
        (bool) preg_match("/errorPath:\s*'index\.html'/", $config),
        'apka wozi ze sobą ekran na wypadek startu bez sieci — inaczej zostaje czarny ekran'
    );

    // Powód, dla którego errorPath zniknął, JEST prawdziwy: Capacitor pokazuje
    // ten plik także przy 404/500 (BridgeWebViewClient.onReceivedHttpError).
    // Dlatego rozstrzyga go teraz sama strona, a nie konfiguracja.
    t_true(
        str_contains($ekran, 'sprawdzSerwis'),
        'ekran pyta serwer, zanim cokolwiek powie o przyczynie'
    );
    t_true(
        str_contains($ekran, "return r.ok ? 'ok' : 'blad'"),
        'i odróżnia awarię serwisu od braku zasięgu — 500 to też odpowiedź'
    );
    t_true(
        str_contains($ekran, 'sessionStorage') && str_contains($ekran, 'juzWracalismy'),
        'automatyczny powrót do aplikacji jest jednorazowy — inaczej błąd na stronie głównej zapętla apkę'
    );
    // Strona tłumacząca brak sieci nie może niczego z tej sieci potrzebować.
    t_false(
        (bool) preg_match('~<(link|script)[^>]+(href|src)=.?https?://~i', $ekran),
        'ekran awaryjny nie ładuje niczego z zewnątrz'
    );
});

t_test('service worker: ŻADEN dokument nie przechodzi przez fetch workera', function () {
    // USTERKA Z 2026-08-29, naprawiona tego samego dnia. Pierwsza wersja
    // workera przechwytywała nawigację do `/odkrycia`, żeby ekran mapy
    // otwierał się bez sieci. Objaw zgłoszony przez usera: „klikanie na ikonę
    // mapy kieruje nas do odkryć, a nie do dedykowanej mapy na mobile".
    //
    // MECHANIZM: `APP_IS_APP` zależy WYŁĄCZNIE od User-Agenta (bootstrap.php),
    // a `User-Agent` jest nagłówkiem ZABRONIONYM — service worker nie może go
    // ustawić na własnym `fetch()`, i doklejka `ridemore-app` z Capacitora do
    // tych żądań nie dochodzi. Serwer widział zwykłą przeglądarkę i oddawał
    // `discovery.php` zamiast `discovery-app.php`, po czym odpowiedź szła
    // do cache'u i usterka utrwalała się na stałe.
    $sw = (string) file_get_contents(CORE_PATH . '/../sw-app.js');
    $bootstrap = (string) file_get_contents(CORE_PATH . '/../core/bootstrap.php');

    t_false(str_contains($sw, "req.mode === 'navigate'"), 'worker nie rozpoznaje nawigacji, bo jej nie obsługuje');
    t_false(str_contains($sw, 'C_DOC'), 'nie ma cache na dokumenty');

    // Ten test ma sens TYLKO dopóki tryb apki rozpoznaje się po User-Agencie.
    // Gdyby doszedł drugi sygnał (nagłówek, cookie), przechwytywanie dokumentów
    // stanie się możliwe — i wtedy trzeba tu świadomie wrócić, a nie po cichu
    // skasować asercje wyżej.
    t_true(
        str_contains($bootstrap, "str_contains(\$_SERVER['HTTP_USER_AGENT'] ?? '', 'ridemore-app')"),
        'tryb apki nadal rozpoznawany wyłącznie po User-Agencie'
    );
});

t_test('apka: mapa nie płaci za panel, którego nie ma', function () {
    // Zgłoszenie usera 2026-08-30: „klikam Mapę na dolnej belce i czekam
    // długo". `discovery-app.php` to pełnoekranowa mapa i dwa liczniki, a
    // kontroler liczył pod nią komplet danych strony desktopowej. Zmierzone
    // na bazie DEV: dane nieużywane przez apkę ~1160 ms na żądanie, z czego
    // `regionsForUser` ~980 ms; dane używane ~20 ms.
    $php  = (string) file_get_contents(CORE_PATH . '/Controllers/DiscoveryController.php');
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery-app.php');

    t_true(
        (bool) preg_match('/\$pelnaStrona = !APP_IS_APP;/', $php),
        'bramka stoi na jednej fladze, nie na rozsypanych warunkach'
    );
    // Każde z tych wywołań MUSI być za bramką — to są te milisekundy.
    foreach ([
        'Discovery::regionsForUser',
        'Discovery::regionPotentials',
        'Discovery::communityStats',
        'Discovery::recentDiscoverers',
        'Discovery::recentActivity',
        'KnownRoute::progressForUser',
        'PointLedger::pointsSince',
        'Support::trackUrlsForFeed',
    ] as $wywolanie) {
        t_true(
            (bool) preg_match('/\$pelnaStrona[^;]{0,200}' . preg_quote($wywolanie, '/') . '/s', $php)
            || (bool) preg_match('/' . preg_quote($wywolanie, '/') . '[^;]{0,200}\$pelnaStrona/s', $php),
            "$wywolanie liczy się tylko dla pełnej strony"
        );
    }
    // A to, czego apka UŻYWA (chip nad mapą), zostaje bezwarunkowe — inaczej
    // liczniki pokazałyby zera.
    foreach (['Discovery::summaryForUser', 'Discovery::boundsFor'] as $potrzebne) {
        t_false(
            (bool) preg_match('/\$pelnaStrona[^;]{0,120}' . preg_quote($potrzebne, '/') . '/s', $php),
            "$potrzebne liczy się ZAWSZE — apka to rysuje"
        );
    }
    // I sprawdzenie od drugiej strony: widok apki naprawdę nie sięga po nic
    // z tego, co zostało wyłączone.
    foreach (['$rides', '$routes', '$community', '$discoverers', '$feedTrackUrls', '$regionPotentials'] as $zmienna) {
        t_false(str_contains($widok, $zmienna), "widok apki nie używa $zmienna");
    }
});

t_test('monitor mapy: panel dla człowieka wszędzie, wysyłka na serwer tylko w dev', function () {
    // Zgłoszenie usera (2026-09-02): „jest bezwładność, nie wiem, co się dzieje
    // — dodajmy modal z ładowaniem i logi, żeby było widać, z czym problem".
    //
    // Test pilnuje GRANICY, bo to ona jest tu decyzją, a nie sam panel: panel
    // widzi każdy (to część produktu), ale ODSYŁANIE POMIARÓW na serwer jest
    // narzędziem diagnostycznym i ma nie istnieć poza dev.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/discovery-map.js');
    $css = (string) file_get_contents(CORE_PATH . '/../assets/css/style.css');

    t_true(str_contains($js, 'window.ridemoreMapBusy'), 'monitor mieszka w discovery-map.js');
    t_true(str_contains($css, '.map-busy{'), 'panel ma swój styl');
    // NIE MODAL — mapa musi zostać używalna przez te kilka sekund.
    t_true(
        (bool) preg_match('/\.map-busy\{[^}]*pointer-events:none/', $css),
        'panel nie przechwytuje kliknięć — to nakładka, nie okno modalne'
    );
    // Wysyłka wisi na zmiennej, której poza dev nikt nie ustawi.
    t_true(
        (bool) preg_match('/if \(!doWyslania\.length \|\| !window\.RIDEMORE_MAP_PERF\)/', $js),
        'bez adresu z serwera JS nie wysyła ani jednego pomiaru'
    );

    $head = Controllers\Support::leafletHead();
    if (APP_ENV === 'dev') {
        t_true(str_contains($head, 'RIDEMORE_MAP_PERF'), 'dev: strona podaje adres dziennika');
        t_true(str_contains($head, 'RIDEMORE_MAP_PERF_TOKEN'), 'dev: razem z tokenem CSRF, bo endpoint zapisuje');
    } else {
        t_false(str_contains($head, 'RIDEMORE_MAP_PERF'), 'poza dev: adresu dziennika nie ma w ogóle');
    }

    // Endpoint pilnuje tego samego po SWOJEJ stronie — dwa różne pytania,
    // nie jedna reguła w dwóch miejscach (patrz nota przy trasie).
    $routes = (string) file_get_contents(CORE_PATH . '/../api/routes.php');
    t_true(
        (bool) preg_match(
            "/post\('\/api\/map\/perf'.{0,400}APP_ENV !== 'dev'.{0,300}Csrf::check/s",
            $routes
        ),
        'endpoint dziennika: najpierw dev, potem CSRF, dopiero potem zapis'
    );
});

t_test('apka: mapa nie siedzi w środku Polski, czekając na GPS', function () {
    // Zgłoszenie usera 2026-08-30: „dla zalogowanego usera ustawia na środku
    // Polski". Środek Polski to widok domyślny Leafletu — mapa siedziała w nim
    // przez pierwsze 6 s każdego wejścia, bo kadr z serwera czekał na timeout.
    // Prostokąt odkryć jest policzony, zanim strona wyjdzie, więc nakładamy go
    // OD RAZU, a pierwszy odczyt GPS i tak go nadpisuje.
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery-app.php');

    t_false(
        (bool) preg_match('/\}, 6000\);/', $widok),
        'kadr awaryjny nie czeka już na sześciosekundowy timeout'
    );
    t_true(
        (bool) preg_match('/if \(userRuszylMapa \|\| !kadrZSerwera \|\| !window\.L\) \{ return; \}.{0,400}fitBounds/s', $widok),
        'kadr z serwera nakłada się natychmiast, o ile user sam nie ruszył mapą'
    );
    // GPS MUSI DALEJ WYGRYWAĆ — inaczej zamienilibyśmy jeden zły kadr na drugi.
    t_true(
        (bool) preg_match('/function kadrNaMnie\(lat, lon\) \{\s*if \(gpsUstawilKadr \|\| userRuszylMapa\) \{ return; \}/s', $widok),
        'pozycja użytkownika nadal nadpisuje kadr awaryjny'
    );
});

t_test('apka: „Mapa" na pasku nie przeładowuje mapy, na której już jesteś', function () {
    // Zgłoszenie usera 2026-08-30: „jak już jestem na mapie i kliknę raz
    // jeszcze, to przeładowuje stronę". Apka chodzi na `server.url`, więc link
    // do adresu, na którym stoisz, to pełna podróż do serwera i budowa mapy
    // od zera. Slot jest wtedy przyciskiem „wyśrodkuj na mnie".
    $nav   = (string) file_get_contents(CORE_PATH . '/../views/web/partials/app-nav.php');
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery-app.php');

    $warunek  = strpos($nav, "if (\$is('/odkrycia')):");
    $przycisk = strpos($nav, 'data-rm-map-here');
    $inaczej  = strpos($nav, '<?php else: ?>');
    $link     = strpos($nav, "<a href=\"<?= Utils\View::url('/odkrycia') ?>\"");
    t_true(
        $warunek !== false && $przycisk !== false && $warunek < $przycisk && $przycisk < $inaczej,
        'na /odkrycia slot Mapy jest przyciskiem, nie linkiem do samego siebie'
    );
    t_true(
        $link !== false && $inaczej < $link,
        'poza mapą slot dalej jest zwykłym linkiem'
    );
    // OD 2026-09-10 PRZYCISK NIE PYTA O POZYCJĘ SAM — woła wspólną kontrolkę
    // z fabryki map (`map.ridemoreLocate`, assets/js/gpx-map.js). Wcześniej
    // stała tu druga kopia tego samego pytania, z krótszym timeoutem i pustym
    // catch-em; to ona była przyczyną zgłoszenia „nie widać, że czeka na GPS".
    t_true(
        (bool) preg_match('/data-rm-map-here.{0,400}map\.ridemoreLocate\(\);/s', $widok),
        'przycisk deleguje do wspólnej kontrolki, zamiast pytać o pozycję po swojemu'
    );
    // ŚWIADOME DOTKNIĘCIE ODDAJE MAPĘ AUTOMATOWI z powrotem — inaczej po
    // jednym przeciągnięciu palcem nie byłoby drogi powrotu do siebie.
    // Dziś dzieje się to na zdarzeniu, które kontrolka wysyła PRZED setView.
    t_true(
        (bool) preg_match("/map\.on\('ridemore:locate'.{0,300}userRuszylMapa = false/s", $widok),
        'dotknięcie kasuje blokadę „user ruszył mapą"'
    );
});

t_test('mapy: KAŻDY ekran z kontrolką warstw ma podpięte przełączniki', function () {
    // Zgłoszenie usera 2026-08-30: „zaznaczanie checkboxa w kontrolce Warstwy
    // w ogóle nie działa". Kontrolka jest wspólna od 2026-08-19
    // (`partials/map-layers.php`), ale jej PODPIĘCIE do mapy zostało po
    // stronie każdej strony z osobna — więc `discovery-app.php` dostał
    // kontrolkę i nie dostał podpięcia: czytał stan checkboxów RAZ, przy
    // tworzeniu mapy, i potem nie słuchał już niczego.
    //
    // Ten test jest po to, żeby szósty ekran z mapą nie powtórzył tego samego.
    // Wspólny binder (`ridemoreBindLayers` w discovery-map.js) liczy się tak
    // samo jak własny listener — chodzi o to, żeby przełącznik CZEGOŚ dotykał.
    $katalog = CORE_PATH . '/../views/web/pages';
    $ekrany  = [];
    foreach (glob($katalog . '/*.php') ?: [] as $plik) {
        $src = (string) file_get_contents($plik);
        if (!str_contains($src, 'map-layers.php')) { continue; }
        $ekrany[basename($plik)] = $src;
    }
    t_true(count($ekrany) >= 5, 'znaleziono ekrany z kontrolką warstw (jest ich ' . count($ekrany) . ')');

    foreach ($ekrany as $nazwa => $src) {
        t_true(
            str_contains($src, 'ridemoreBindLayers')
            || (bool) preg_match("/addEventListener\('change'.{0,400}data-layer/s", $src),
            "$nazwa podpina przełączniki warstw do mapy, a nie tylko je rysuje"
        );
    }
});

t_test('mapy: wspólny binder czyta stan czytnikiem i gasi dzieci razem z rodzicem', function () {
    // Stan SKUTECZNY dziecka to `checked ∧ rodzic` — silnik ma dostać to samo,
    // co `ridemoreReadLayers` oddało przy starcie mapy. Kopie per strona
    // przekazywały surowe `cb.checked`, co dla dziecka bywało nieprawdą.
    // Zweryfikowane też na żywo w przeglądarce (fałszywa mapa + prawdziwy
    // markup partiala): rodzic OFF daje `treasures=false` + `treasuresFound=false`.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/discovery-map.js');

    t_true(
        (bool) preg_match('/window\.ridemoreBindLayers = function \(map, box\)/', $js),
        'binder istnieje jako jedno wspólne wejście'
    );
    t_true(
        (bool) preg_match('/ridemoreBindLayers[\s\S]{0,1200}var stan = ridemoreReadLayers\(box\);[\s\S]{0,200}ridemoreSetLayer\(cb\.dataset\.layer, stan\[cb\.dataset\.layer\]\)/', $js),
        'binder bierze stan z czytnika, nie z surowego cb.checked'
    );
    t_true(
        (bool) preg_match('/ridemoreBindLayers[\s\S]{0,1800}data-children-of="\' \+ cb\.dataset\.layer/', $js),
        'przełączenie rodzica przepisuje też stan skuteczny dzieci'
    );
});

t_test('mgła: siatka pól jest wyrównana z siatką serwera', function () {
    // Prośba usera 2026-08-30: „na mgle delikatne obramowanie hexów, o ton
    // ciemniejsze niż sama mgła — pozwoli userowi zobaczyć, jakie hexy ma koło
    // siebie i gdzie powinien skręcić". Serwer przysyła WYŁĄCZNIE pola odkryte,
    // więc obrysy nieodkrytych JS musi policzyć sam — a to znaczy, że wzory
    // siatki żyją od tej pory w DWÓCH miejscach.
    //
    // Ten test jest ceną za to powtórzenie: pilnuje, żeby wzory po obu stronach
    // pozostały te same. Rozjazd nie wywala niczego z hukiem — po prostu
    // rysuje siatkę OBOK dziur wyciętych w mgle, czyli produkuje mapę, która
    // wygląda na zepsutą. Zweryfikowane też na żywo w przeglądarce: środek pola
    // z API przepuszczony przez wzory z JS wraca z błędem max 1 m przy polu
    // 7500 m (czyli tyle, ile gubi zaokrąglenie w JSON-ie).
    $js  = (string) file_get_contents(CORE_PATH . '/../assets/js/discovery-map.js');
    $php = (string) file_get_contents(CORE_PATH . '/Utils/DiscoveryGrid.php');

    // pointToAxial: q = (√3/3·x − y/3) / size, r = (2/3·y) / size
    t_true(
        (bool) preg_match('/Math\.sqrt\(3\) \/ 3 \* x - y \/ 3\) \/ size/', $js),
        'JS liczy q tym samym wzorem co PHP'
    );
    t_true(
        (bool) preg_match('/sqrt\(3\) \/ 3 \* \$x - 1 \/ 3 \* \$y\) \/ \$size/', $php),
        'wzór na q po stronie PHP nie zmienił się pod tym testem'
    );
    // axialToPoint: x = size·√3·(q + r/2), y = size·3/2·r
    t_true(
        (bool) preg_match('/size \* Math\.sqrt\(3\) \* \(q \+ r \/ 2\), size \* 3 \/ 2 \* r/', $js),
        'JS liczy środek pola tym samym wzorem co PHP'
    );
    t_true(
        (bool) preg_match('/\$size \* sqrt\(3\) \* \(\$q \+ \$r \/ 2\)/', $php),
        'wzór na środek pola po stronie PHP nie zmienił się pod tym testem'
    );
    // roundAxial przez współrzędne sześcienne — naiwne round() wskazuje sąsiada.
    t_true(
        (bool) preg_match('/function roundAxial\(q, r\)[\s\S]{0,400}dx > dy && dx > dz/', $js),
        'JS zaokrągla przez współrzędne sześcienne, jak PHP'
    );
});

t_test('mgła: siatka pojawia się dopiero, gdy pole coś znaczy, i omija odkryte', function () {
    // DWA warunki, oba zmierzone w przeglądarce na ekranie 1199×520:
    //   - bez progu wielkości w widoku domyślnym (cały kraj) siatka rysowała
    //     ~2000 pól po ~21 px, czyli „plaster miodu" z §24: gęstwina kresek,
    //     która nie mówi nic i kosztuje przy KAŻDYM ruchu mapy;
    //   - obrysy nad polem ODKRYTYM przeczyłyby zasadzie „odkrycie odsłania
    //     mapę" (2026-08-14) — pole zdjęte z mgły ma być czystą mapą.
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/discovery-map.js');

    t_true(
        (bool) preg_match('/var GRID_MIN_PX = \d+;/', $js),
        'próg wielkości pola na ekranie jest nazwaną stałą, nie liczbą w kodzie'
    );
    // Od 2026-09-01 próg jest PARAMETREM (`minPx`) wspólnego jądra siatki
    // `latticeCells` — narzędzie „Regiony na mapie" rysuje tę samą siatkę przy
    // oddaleniu, przy którym mgła słusznie jej nie pokazuje. Mgle nic się nie
    // zmieniło: to `hexLattice` podaje jej GRID_MIN_PX, i to jest tu sprawdzane.
    t_true(
        (bool) preg_match('/Math\.sqrt\(3\) \* pSrodek\.distanceTo\(pRog\) < minPx\) \{ return \[\]; \}/', $js),
        'wielkość pola jest MIERZONA rzutowaniem mapy, nie zgadywana z oddalenia'
    );
    t_true(
        (bool) preg_match('/latticeCells\(map, sizeM, \{ minPx: GRID_MIN_PX, max: LATTICE_MAX, skip: odkryte \}\)/', $js),
        'mgła dalej dostaje swój własny próg i limit, nie cudzy'
    );
    t_true(
        (bool) preg_match('/if \(odkryte && odkryte\[q \+ \x27:\x27 \+ r\]\) \{ continue; \}/', $js),
        'pole odkryte nie dostaje obrysu — zostaje czystą mapą'
    );
    t_true(
        (bool) preg_match('/odkryte\[axialKey\(cell\.a, cell\.o, data\.sizeM\)\] = true;/', $js),
        'zbiór odkrytych powstaje z pól przysłanych przez serwer'
    );
    // Siatka jest BLEDSZA I CIEŃSZA od granicy odkrytego — to rozróżnienie
    // niesie treść (patrz komentarz przy GRID w discovery-map.js).
    t_true(
        (bool) preg_match("/var GRID = \{ color: '#63707F', weight: 0\.5, opacity: 0\.3 \};/", $js),
        'siatka jest delikatniejsza niż obrys granicy odkryć (0.6 / 0.85)'
    );
});

// ---------------------------------------------------------------------------
// „TU JESTEM" NA MAPIE (2026-09-10) — zgłoszenie usera: „centrowanie po
// kliknięciu na mapę w miejscu lokalizacji GPS. Jeśli nie ma, to pokazać, że
// czekasz na sygnał". Testy są STATYCZNE (regex na źródle), tym samym wzorcem
// co reszta testów widoków w tym pliku: kontrolka żyje w Leaflecie i w mostku
// natywnym, więc jedynym sposobem uruchomienia jej w PHP byłoby zbudowanie
// atrapy obu — czyli sprawdzanie atrapy, nie kodu.
// ---------------------------------------------------------------------------

t_test('mapa: kontrolka „tu jestem" jest w FABRYCE, więc ma ją każda mapa', function () {
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/gpx-map.js');

    t_true(
        (bool) preg_match('/function ridemoreCreateMap\(.*?ridemoreAddLocateControl\(map\);.*?return map;/s', $js),
        'ridemoreCreateMap wpina kontrolkę lokalizacji — tak samo jak pełny ekran'
    );
    t_true(
        (bool) preg_match('/function ridemoreAddLocateControl\(map\)/', $js),
        'kontrolka jest osobną funkcją, nie wpleciona w fabrykę'
    );
    // Uchwyt na mapie to jedyna droga, którą strona (dolny pasek apki) woła tę
    // samą logikę zamiast pisać drugą kopię — patrz test niżej.
    t_true(
        (bool) preg_match('/map\.ridemoreLocate = lokalizuj;/', $js),
        'kontrolka wystawia uchwyt map.ridemoreLocate, wzorcem map.ridemoreSetLayer'
    );
    t_true(
        (bool) preg_match("/options: \{ position: 'bottomright' \}/", $js),
        'przycisk stoi w prawym dolnym rogu — pod kciukiem'
    );
});

t_test('mapa: brak sygnału GPS MÓWI, zamiast milczeć', function () {
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/gpx-map.js');

    // Trzy stany muszą być rozróżnialne: szukam / mam / nie mam. „Szukam"
    // pojawia się PRZED zapytaniem o pozycję, bo zimny fix trwa do 30 s —
    // pokazanie go po odpowiedzi nie pokazałoby niczego.
    t_true(
        (bool) preg_match("/powiedz\((?:__\()?'Szukam sygnału GPS…'\)?, 'is-busy'\);\s*\n\s*(\/\/[^\n]*\n\s*)*RM\.native\.position/", $js),
        'stan „szukam" zapala się PRZED pytaniem o pozycję, nie po nim'
    );
    t_true(
        (bool) preg_match('/\.catch\(function \(err\) \{[^}]*powiedzNaChwile\(\(err && err\.message\)/s', $js),
        'błąd pokazuje treść z mostu — nie ma pustego catch'
    );
    // Pytamy o CIAŁO kontrolki, nie o cały plik: pusty catch bywa gdzie indziej
    // poprawny (np. przy sprzątaniu nasłuchu, gdzie nie ma komu nic powiedzieć).
    preg_match('/function ridemoreAddLocateControl\(map\) \{.*?\n\}/s', $js, $cialo);
    t_true(isset($cialo[0]), 'ciało kontrolki daje się wyciąć — inaczej test niżej nic nie sprawdza');
    t_false(
        isset($cialo[0]) && (bool) preg_match('/\.catch\(function \(\) \{\}\)/', $cialo[0]),
        'w kontrolce lokalizacji nie ma połkniętego błędu'
    );
    // Zapytanie ze świadomego dotknięcia czeka tyle, ile trwa zimny fix —
    // ośmiosekundowy timeout (tyle miała stara kopia w discovery-app.php)
    // oddawał błąd, zanim GPS zdążył cokolwiek złapać.
    t_true(
        (bool) preg_match('/RM\.native\.position\(\{ highAccuracy: true, timeout: 30000, maximumAge: 10000 \}\)/', $js),
        'świadome dotknięcie czeka 30 s, a nie 8'
    );
});

t_test('apka: przycisk „Mapa" w pasku nie ma własnej kopii logiki lokalizacji', function () {
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/discovery-app.php');

    t_true(
        (bool) preg_match('/data-rm-map-here.*?map\.ridemoreLocate\(\);/s', $widok),
        'slot paska woła wspólną drogę z fabryki'
    );
    // To jest cała treść zgłoszenia: poprzednia kopia kończyła się pustym
    // catch-em, więc przy braku sygnału nie działo się nic. Sprawdzamy SAM
    // HANDLER, bo pusty catch przy sprzątaniu nasłuchu GPS niżej w tym pliku
    // jest poprawny — tam naprawdę nie ma komu nic powiedzieć.
    preg_match('/data-rm-map-here.{0,400}?\}\);/s', $widok, $handler);
    t_true(isset($handler[0]), 'handler daje się wyciąć — inaczej test niżej nic nie sprawdza');
    t_false(
        isset($handler[0]) && str_contains($handler[0], '.catch(function () {})'),
        'zniknął pusty catch, który połykał brak sygnału'
    );
    t_true(
        (bool) preg_match("/map\.on\('ridemore:locate'/", $widok),
        'strona zdejmuje blokadę automatycznego kadru dopiero na świadome dotknięcie'
    );
});

t_test('mapa: błąd lokalizacji jest po polsku TAKŻE z wtyczki natywnej', function () {
    $js = (string) file_get_contents(CORE_PATH . '/../assets/js/native.js');

    // Zgłoszenie z terenu (2026-08-29): „Could not obtain location in time.
    // Try a higher timeout" — surowy komunikat wtyczki pokazany człowiekowi.
    // Gałąź natywna omijała `positionError`, choć nota nad nim obiecywała
    // „jeden komunikat dla obu źródeł błędu".
    t_true(
        (bool) preg_match('/geo\.getCurrentPosition\(options\)\.then\(.*?\}, function \(err\) \{.*?throw new Error\(native\.positionError\(err\)\);/s', $js),
        'gałąź natywna przepuszcza błąd przez positionError'
    );
    t_true(
        (bool) preg_match('/if \(\/denied\|permission\|not allowed\/i\.test\(tresc\)\)/', $js),
        'odmowa zgody rozpoznana także po treści (wtyczka nie używa kodów W3C)'
    );
    t_false(
        (bool) preg_match('/return \(err && err\.message\) \|\| \x27Nie udało się ustalić pozycji\.\x27;/', $js),
        'nierozpoznany błąd nie wypuszcza już angielskiego tekstu wtyczki na ekran'
    );
});

t_test('apka: kontrolka lokalizacji ZOSTAJE i nie chowa się pod dolnym paskiem', function () {
    $css = (string) file_get_contents(CORE_PATH . '/../assets/css/app.css');

    // Apka chowa zoom i pełny ekran. Lokalizacja jest odwrotnym przypadkiem —
    // to jedyna droga powrotu do siebie po odjechaniu mapy palcem.
    t_false(
        (bool) preg_match('/body\.is-app[^{]*\.map-loc__ctrl[^{]*\{[^}]*display:\s*none/', $css),
        'kontrolka lokalizacji NIE jest na liście chowanych w apce'
    );
    t_true(
        (bool) preg_match('/body\.is-app\.map-page \.leaflet-bottom\{\s*bottom:calc\(var\(--app-nav-h\)/', $css),
        'dolny rząd kontrolek podnosi się ponad pływający pasek nawigacji'
    );
    t_true(
        (bool) preg_match('/body\.is-app\.map-page:has\(\.disc-panels\) \.leaflet-bottom/', $css),
        'ekran z szufladą dostaje dodatkowy odstęp na jej uchwyt'
    );
});

// ---------------------------------------------------------------------------
// GPX I NAWIGACJA DO PUNKTU (2026-09-10) — dwie różne rzeczy, celowo osobne od
// modułu nawigacji z tasks/nawigacja-w-apce-ODLOZONE.md: tu chodzi o dowiezienie
// człowieka POD punkt i o wypuszczenie pliku na zewnątrz, nie o prowadzenie
// po trasie.
// ---------------------------------------------------------------------------

t_test('panel skarbów: ujawnienie to karty radio, nie <select>', function () {
    // Poziom ujawnienia jest jedyną rzeczą, która odróżnia skarb od zwykłej
    // pinezki — a stał jako jedno z sześciu identycznych rozwijanych pól
    // w kolumnie. Karty pokazują różnicę bez czytania podpowiedzi pod spodem.
    //
    // NAZWA POLA I WARTOŚCI BEZ ZMIAN (`reveal_level`, 2/1/0): serwer dostaje
    // dokładnie to, co dostawał, więc `TreasureController::save` nie wie o tej
    // zmianie i nie musi wiedzieć.
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasures-admin.php');

    t_false(str_contains($widok, '<select class="search-input" id="trReveal"'), 'ujawnienie nie jest już rozwijanym polem');
    t_true(str_contains($widok, 'type="radio" name="reveal_level"'), 'ujawnienie wysyła radio pod tą samą nazwą');
    t_true(str_contains($widok, 'tr-reveal__o'), 'każdy poziom jest własną kartą');

    // JS POSŁUGUJE SIĘ NIM TAK SAMO (`pola.reveal.value`) — getter/setter
    // zamiast przepisywania obu miejsc wywołania (`nowy()` i `wybierz()`).
    t_true(str_contains($widok, 'get value()'), 'pola.reveal dalej ma .value do odczytu');
    t_true(str_contains($widok, 'set value(v)'), 'pola.reveal dalej ma .value do zapisu');
});

t_test('panel skarbów: obiekt startowy niesie to samo, co odpowiedź mapy', function () {
    // Wejście z listy (`?skarb={id}`) buduje `dane[startId]` w HTML-u, a klik
    // w pinezkę bierze ten sam wiersz z `/admin/skarby/punkty`. Gdy te dwa
    // kształty się rozjadą, `wybierz()` nadpisuje poprawnie wyrenderowane pole
    // pustką — i dokładnie tak zniknął kod naklejki z nagłówka panelu
    // (złapane żywym testem 2026-09-12).
    $widok = (string) file_get_contents(CORE_PATH . '/../views/web/pages/treasures-admin.php');
    $kontroler = (string) file_get_contents(CORE_PATH . '/Controllers/Admin/TreasureController.php');

    foreach (['code', 'claims', 'rarity', 'region'] as $klucz) {
        t_true(str_contains($kontroler, "'" . $klucz . "'"), 'endpoint punktów oddaje `' . $klucz . '`');
        t_true(str_contains($widok, "'" . $klucz . "' =>"), 'obiekt startowy niesie `' . $klucz . '`');
    }
});

t_test('nawigacja do punktu: jeden partial, nie kopia na stronę', function () {
    $partial = (string) file_get_contents(CORE_PATH . '/../views/web/partials/nav-button.php');
    $event   = (string) file_get_contents(CORE_PATH . '/../views/web/pages/event-page.php');
    // Strona trasy sięga po przycisk PRZEZ `trail-actions.php` (2026-09-11):
    // od kiedy „Nawiguj", „Wyjazdy w tych stronach" i „Pobierz GPX" stoją jedną
    // grupą, a ta grupa ma dwa miejsca zamieszkania (na okładce i — gdy trasa
    // jej nie ma — w nagłówku), wspólny partial jest o poziom głębiej.
    // Test pilnuje tego samego co wcześniej: JEDNEGO źródła adresu nawigacji,
    // więc sprawdza obie warstwy razem.
    $trail   = (string) file_get_contents(CORE_PATH . '/../views/web/pages/trail.php')
             . (string) file_get_contents(CORE_PATH . '/../views/web/partials/trail-actions.php');

    // `dir/` URUCHAMIA prowadzenie; dawne `?q=` stawiało samą pinezkę i nikt
    // tego nie zauważył przez rok. Tryb rowerowy, bo to rowerowy serwis.
    t_true(
        str_contains($partial, 'maps/dir/?api=1&destination=') && str_contains($partial, 'travelmode=bicycling'),
        'partial uruchamia prowadzenie w trybie rowerowym, nie stawia pinezki'
    );
    t_true(str_contains($event, "partials/nav-button.php"), 'strona wydarzenia używa partiala');
    // Sama NAZWA pliku, nie ścieżka `partials/…` — `trail-actions.php` leży
    // w tym samym katalogu i dołącza sąsiada przez `__DIR__ . '/nav-button.php'`.
    // Testujemy „używa wspólnego partiala", a nie to, jak głęboko wołający siedzi.
    t_true(str_contains($trail, "nav-button.php"), 'strona trasy używa partiala');
    // Gdyby ktoś dopisał czwartą kopię linku obok partiala, to by ją złapało.
    t_false(
        str_contains($event, 'google.com/maps') || str_contains($trail, 'google.com/maps'),
        'żadna strona nie składa adresu nawigacji samodzielnie'
    );
});

t_test('nawigacja do punktu: bez współrzędnych nie ma przycisku', function () {
    // Trasy sprzed backfillu profili nie mają lat/lon w próbkach, a wydarzenie
    // nie musi mieć wskazanej pinezki zbiórki. Przycisk prowadzący donikąd
    // byłby gorszy niż jego brak.
    $navLat = null;
    $navLon = null;
    ob_start();
    require CORE_PATH . '/../views/web/partials/nav-button.php';
    $pusty = trim((string) ob_get_clean());
    t_same('', $pusty, 'brak współrzędnych = pusty wynik');

    $navLat = 49.4713;
    $navLon = 22.3335;
    $navLabel = 'Nawiguj na start trasy';
    ob_start();
    require CORE_PATH . '/../views/web/partials/nav-button.php';
    $html = (string) ob_get_clean();
    t_true(str_contains($html, 'destination=49.4713%2C22.3335'), 'współrzędne trafiają do adresu');
    t_true(str_contains($html, 'Nawiguj na start trasy'), 'napis mówi, DOKĄD prowadzi');
});

t_test('apka: „Pobierz GPX" nie kończy się już ciszą', function () {
    $java = (string) file_get_contents(CORE_PATH . '/../app/android/app/src/main/java/bike/ridemore/app/MainActivity.java');
    $js   = (string) file_get_contents(CORE_PATH . '/../assets/js/app-share.js');
    $most = (string) file_get_contents(CORE_PATH . '/../assets/js/native.js');
    $head = (string) file_get_contents(CORE_PATH . '/../views/web/partials/head.php');

    // FUNDAMENT: bez tego WebView nie ma czym pobrać pliku — Capacitor nie
    // ustawia DownloadListenera nigdzie w swoim pakiecie.
    t_true(str_contains($java, 'setDownloadListener'), 'WebView apki ma wreszcie obsługę pobierania');
    t_true(
        str_contains($java, 'CookieManager.getInstance().getCookie(url)')
            && str_contains($java, 'addRequestHeader("Cookie"'),
        'DownloadManager dostaje cookie sesji — inaczej pobrałby stronę logowania jako .gpx'
    );

    // DROGA GŁÓWNA: arkusz „wyślij do…" prosto do nawigacji.
    t_true(str_contains($most, 'canShareFile:') && str_contains($most, 'shareFile:'), 'most umie wysłać plik');
    t_true(
        (bool) preg_match('/if \(!RM\.native\.canShareFile\(\)\) \{ return; \}\s*\n\s*przemianuj\(\);/', $js),
        'napis zmienia się TYLKO wtedy, gdy apka naprawdę umie wysłać plik'
    );
    t_true(
        (bool) preg_match('/if \(\/cancel\/i\.test\(\(err && err\.message\) \|\| \x27\x27\)\) \{ return; \}/', $js),
        'zamknięcie arkusza palcem nie jest zgłaszane jako błąd'
    );

    // Skrypt jest apkowy — w przeglądarce nie ma czego przechwytywać.
    t_true(
        (bool) preg_match('/APP_IS_APP.*app-share\.js/s', $head),
        'app-share.js ładuje się wyłącznie w apce'
    );
});

// ---------------------------------------------------------------------------
// ARKUSZE: KOMENTARZE MUSZĄ SIĘ DOMYKAĆ (2026-09-11)
//
// Ten test powstał po znalezieniu USTERKI, nie z ostrożności. W `app.css` stał
// akapit komentarza zakończony DRUGIM znacznikiem zamykającym — pierwszy
// zamykał komentarz cztery linie wyżej, więc dla przeglądarki był to zwykły
// tekst w arkuszu. CSS nie przerywa wtedy parsowania: POŁYKA to, co idzie
// dalej, aż odzyska się na kolejnym bloku. Razem ze śmieciem zniknęła reguła
// `body.is-app.map-page .app-offline` (wskaźnik „brak zasięgu" na
// pełnoekranowej mapie przestał pływać i wchodził w przepływ strony).
//
// Zmierzone przed naprawą: `document.styleSheets` dla app.css miał 93 reguły
// i kończył się na `.app-offline__dot`; po naprawie 96 i brakujący selektor
// wrócił. Usterka była NIEWIDOCZNA — arkusz się ładował, nic nie krzyczało.
// Dlatego pilnuje jej test, a nie czyjaś uważność przy przeglądaniu diffa.
// ---------------------------------------------------------------------------

t_test('arkusze: żaden komentarz CSS nie zostaje otwarty ani zamknięty dwa razy', function () {
    foreach (['app.css', 'style.css'] as $plik) {
        $css = (string) file_get_contents(CORE_PATH . '/../assets/css/' . $plik);
        $dlugosc = strlen($css);
        $wKomentarzu = false;
        $poczatek = 0;
        $i = 0;

        while ($i < $dlugosc - 1) {
            $para = $css[$i] . $css[$i + 1];
            if (!$wKomentarzu && $para === '/*') {
                $wKomentarzu = true;
                $poczatek = $i;
                $i += 2;
                continue;
            }
            if ($wKomentarzu && $para === '*/') {
                $wKomentarzu = false;
                $i += 2;
                continue;
            }
            // Znacznik zamykający POZA komentarzem to dokładnie ten błąd.
            if (!$wKomentarzu && $para === '*/') {
                $linia = substr_count(substr($css, 0, $i), "\n") + 1;
                t_true(false, $plik . ': osierocony znacznik zamykający komentarz w linii ' . $linia);
                return;
            }
            $i++;
        }

        if ($wKomentarzu) {
            $linia = substr_count(substr($css, 0, $poczatek), "\n") + 1;
            t_true(false, $plik . ': komentarz otwarty w linii ' . $linia . ' nigdy się nie zamyka');
            return;
        }
        t_true(true, $plik . ': komentarze się domykają');
    }
});

t_test('apka: okruszki gasi JEDNO miejsce, nie każdy szablon z osobna', function () {
    $partial = (string) file_get_contents(CORE_PATH . '/../views/web/partials/breadcrumbs.php');

    t_true(
        (bool) preg_match('/if \(APP_IS_APP\) \{ return; \}/', $partial),
        'bramka trybu apki stoi w samym partialu okruszków'
    );

    // Reguła jest dla WSZYSTKICH stron — cztery szablony miały ją u siebie,
    // a dwadzieścia innych o niej nie wiedziało (audyt 2026-09-11: okruszki
    // wychodziły w apce na stronie trasy, przejazdu i „moich przejazdów",
    // na tej ostatniej zawijając się do dwóch linii u góry ekranu).
    $strony = glob(CORE_PATH . '/../views/web/pages/*.php') ?: [];
    $zOpakowaniem = [];
    foreach ($strony as $sciezka) {
        $tresc = (string) file_get_contents($sciezka);
        if (!str_contains($tresc, 'breadcrumbs.php')) { continue; }
        if (preg_match('/if \(!APP_IS_APP\).{0,120}breadcrumbs\.php/s', $tresc)) {
            $zOpakowaniem[] = basename($sciezka);
        }
    }
    t_same(
        [],
        $zOpakowaniem,
        'żaden szablon nie powtarza bramki, którą ma już partial: ' . implode(', ', $zOpakowaniem)
    );
});

// ─────────────────────────────────────────── PULS W DWÓCH MIEJSCACH
//
// Ten sam feed (`Models\Pulse::feed`) rysują DWA widoki: pełny `/puls` i skrót
// na stronie głównej. Za każdym razem, gdy do modelu dochodził nowy typ wpisu,
// aktualizowany był tylko `/puls`, a strona główna spadała na swój `default` —
// i dwa razy z rzędu (skarby 2026-09-11, wgrane ślady 2026-09-12) tym defaultem
// był opis „Szuka towarzystwa", czyli brzmienie CUDZEGO typu wpisu.

t_test('puls: KAŻDY typ wpisu jest obsłużony w OBU widokach', function () {
    $model = (string) file_get_contents(CORE_PATH . '/../core/Models/Pulse.php');
    $home  = (string) file_get_contents(CORE_PATH . '/../views/web/pages/home.php');
    $puls  = (string) file_get_contents(CORE_PATH . '/../views/web/pages/pulse.php');

    // Lista typów pochodzi z MODELU, nie z tablicy wpisanej w test — dziewiąty
    // typ ma ten test wywrócić SAM, bez dopisywania go tutaj ręcznie. Dokładnie
    // tego zabrakło: `slady-wgrane` doszedł do feedu i do `/puls`, a strona
    // główna nie wiedziała o nim nic.
    preg_match_all('/\'type\'\s*=>\s*\'([a-z-]+)\'/', $model, $m);
    $typy = array_values(array_unique($m[1]));
    t_true(count($typy) >= 8, 'model produkuje co najmniej osiem typów, znaleziono ' . count($typy));

    $bezEtykiety = [];
    $bezOpisu    = [];
    $bezNaglowka = [];
    foreach ($typy as $typ) {
        $klucz = '/\'' . preg_quote($typ, '/') . '\'\s*=>\s*\[/';
        // Nagłówek karty na stronie głównej — klucz w `$pulseLabels`.
        if (!preg_match($klucz, $home)) { $bezEtykiety[] = $typ; }
        // Nagłówek wpisu na /puls — gałąź w `$eyebrowFor` (match, ten sam kształt).
        if (!preg_match($klucz, $puls)) { $bezNaglowka[] = $typ; }
        // Opis karty na stronie głównej — WŁASNA gałąź, nie `default`.
        if (!str_contains($home, "\$pi['type'] === '" . $typ . "'")) { $bezOpisu[] = $typ; }
    }
    t_same([], $bezEtykiety, 'typy bez nagłówka na stronie głównej: ' . implode(', ', $bezEtykiety));
    t_same([], $bezNaglowka, 'typy bez nagłówka na /puls: ' . implode(', ', $bezNaglowka));
    t_same([], $bezOpisu, 'typy bez własnego opisu na stronie głównej: ' . implode(', ', $bezOpisu));
});

t_test('puls: wgrany ślad prowadzi na PROFIL i nie mówi, że ktoś szuka towarzystwa', function () {
    $home = (string) file_get_contents(CORE_PATH . '/../views/web/pages/home.php');

    // Zgłoszenie 2026-09-12: user wgrał trasę, a karta Pulsu na stronie głównej
    // wyszła jako „<jego imię> · Szuka towarzystwa". Tytułem tego wpisu JEST
    // imię człowieka, więc default przypisywał konkretnej osobie intencję,
    // której nigdy nie wyraziła — i prowadził na mapę odkryć zamiast na profil.
    t_true(str_contains($home, "'slady-wgrane'   => ['Nowe ślady na mapie'") || str_contains($home, "'slady-wgrane'   => [__('Nowe ślady na mapie')"), 'wpis ma własny nagłówek, jak na /puls');
    t_true(str_contains($home, "'/rowerzysta/' . \$pi['riderSlug']"), 'karta prowadzi na profil rowerzysty');

    // Brzmienie „wezwania" MA BYĆ przypisane wyłącznie `wezwaniu`. Póki siedzi
    // w `default`, każdy nowy typ dostanie je automatycznie — to jest sam
    // mechanizm tej usterki, nie jej objaw.
    t_eq(1, substr_count($home, '$pulseMeta = \'Szuka towarzystwa\';') + substr_count($home, '$pulseMeta = __(\'Szuka towarzystwa\');'), 'brzmienie stoi w JEDNYM miejscu');
    t_true(
        (bool) preg_match('/=== \'wezwanie\'\) \{\s*\$pulseMeta = (?:__\()?\'Szuka towarzystwa\'\)?;/', $home),
        'i należy wyłącznie do typu `wezwanie`'
    );
});

t_test('puls: wpis o śladach pokazuje SAM ŚLAD, w obu widokach i w dwóch kadrach', function () {
    $home   = (string) file_get_contents(CORE_PATH . '/../views/web/pages/home.php');
    $puls   = (string) file_get_contents(CORE_PATH . '/../views/web/pages/pulse.php');
    $routes = (string) file_get_contents(CORE_PATH . '/../web/routes.php');

    // Prośba usera 2026-09-12: „żeby w card pojawiało się zdjęcie śladu".
    // Wpis o wgranych śladach nie ma wyjazdu, więc nie ma ani zdjęcia
    // z kroniki, ani okładki organizatora — do tej daty karta była pusta,
    // a miniatura na /puls w ogóle nie powstawała.
    t_true(str_contains($routes, '/assets/tiles/slad/{key}/{rozmiar}/{stamp}.png'), 'trasa obrazka jest zarejestrowana');
    t_true(str_contains($home, "/assets/tiles/slad/' . \$pi['mapKey'] . '/karta/"), 'karta bierze wariant pasa');
    t_true(str_contains($puls, "/assets/tiles/slad/' . \$item['mapKey'] . '/kwadrat/"), '/puls bierze wariant kwadratu');

    // OBA WIDOKI PYTAJĄ O `mapReady`. To nie ostrożność, tylko warunek
    // konieczny: ślad bez policzonej geometrii nie ma z czego się narysować,
    // endpoint odpowiada na niego 404, a <img> pokazałby ikonę zepsutego
    // obrazka. Sprawdzone na żywo — grupa z dev bez geometrii oddaje 404.
    t_true(str_contains($home, "\$pi['mapReady'] ?? 0) > 0"), 'karta pyta o mapReady');
    t_true(str_contains($puls, "\$item['mapReady'] ?? 0) > 0"), '/puls pyta o mapReady');

    // STEMPEL W ADRESIE unieważnia obrazek sam — bez niego kolejny ślad
    // wgrany tej samej doby zostałby pod starym, nieaktualnym obrazkiem.
    t_true(str_contains($home, "\$pi['mapStamp']"), 'adres karty niesie stempel grupy');
    t_true(str_contains($puls, "\$item['mapStamp']"), 'adres miniatury niesie stempel grupy');
});

t_test('puls: jeden ślad prowadzi DO PRZEJAZDU, wiele — na profil', function () {
    $home  = (string) file_get_contents(CORE_PATH . '/../views/web/pages/home.php');
    $puls  = (string) file_get_contents(CORE_PATH . '/../views/web/pages/pulse.php');
    $model = (string) file_get_contents(CORE_PATH . '/../core/Models/Pulse.php');
    $ctrl  = (string) file_get_contents(CORE_PATH . '/../core/Controllers/TileController.php');

    // Wpis zwija się do jednej doby, więc przy wielu śladach nie istnieje
    // jeden „ten" przejazd — obiecywanie konkretnego byłoby zmyśleniem.
    // Model pilnuje tego u ŹRÓDŁA: `rideId` powstaje tylko dla grupy
    // jednoelementowej, więc widok nie ma z czego zbudować złego adresu.
    t_true(str_contains($model, "\$ile === 1 ? (int) \$row['ride_id'] : null"), 'model oddaje id TYLKO dla jednego śladu');
    t_true(str_contains($home, "'/przejazd/' . (int) \$pi['rideId']"), 'karta jednego śladu prowadzi do przejazdu');
    t_true(str_contains($puls, "'/przejazd/' . (int) \$item['rideId']"), 'miniatura jednego śladu prowadzi do przejazdu');
    t_true(str_contains($home, "'/rowerzysta/' . \$pi['riderSlug']"), 'karta wielu śladów prowadzi na profil');

    // §27 W OBRAZKU. Solo obcej osoby wolno pokazać WYŁĄCZNIE po wycięciu
    // okolic domu, więc endpoint nie ma prawa sięgnąć po surową geometrię
    // solo ani po plik. Dwie rzeczy to gwarantują: podział źródeł w modelu
    // i bramka widoczności przed rysowaniem.
    t_true(str_contains($model, "\$zrodlo = (int) \$row['edycja'] === 0 ? 'trimmed' : 'normal'"), 'solo idzie źródłem przyciętym');
    t_true(str_contains($ctrl, 'Support::visibleRiderById($userId) === null'), 'endpoint stawia bramkę widoczności');
    t_true(str_contains($ctrl, 'boundsForTrimmed'), 'kadr solo liczy się z przyciętej tabeli');
});

// ──────────────────────── CO NIE MA WYCHODZIĆ PRZEZ HTTP (2026-09-12)
//
// Aplikacja leży w katalogu serwowanym i główny `.htaccess` przepuszcza
// ISTNIEJĄCE pliki (`RewriteCond %{REQUEST_FILENAME} !-f`) — więc każdy plik
// w repozytorium jest domyślnie publiczny, dopóki ktoś go nie zablokuje.
// `tests/`, `storage/`, `ai-engine/` i `assets/uploads/` miały swoje blokady
// od dawna; `core/`, `migration/`, `md/` i `tasks/` nie miały żadnej.
// Zmierzone przed naprawą: `/migration/schema.sql` oddawał 89 703 B pełnego
// schematu bazy, `/md/database.md` — 65 837 B dokumentacji wewnętrznej.

t_test('http: katalogi niepubliczne mają blokadę Apache w OBU składniach', function () {
    // Oba warianty są konieczne, nie „dla porządku": mod_authz_core to Apache
    // 2.4, mod_access_compat obsługuje 2.2. Hosting bywa jednym albo drugim,
    // a blokada napisana tylko w jednej składni na drugim jest CICHO nieobecna.
    foreach (['core', 'migration', 'md', 'tasks', 'tests', 'storage'] as $katalog) {
        $plik = CORE_PATH . '/../' . $katalog . '/.htaccess';
        t_true(is_file($plik), "$katalog/ ma własny .htaccess");

        $tresc = (string) file_get_contents($plik);
        t_true(str_contains($tresc, 'Require all denied'), "$katalog/: blokada w składni Apache 2.4");
        t_true(str_contains($tresc, 'Deny from all'), "$katalog/: blokada w składni Apache 2.2");
    }
});

t_test('http: skrypty CLI i manifesty zależności są zablokowane w głównym .htaccess', function () {
    $ht = (string) file_get_contents(CORE_PATH . '/../.htaccess');

    // Pliki w KATALOGU GŁÓWNYM nie mają własnego .htaccess (ten jeden jest
    // wspólny dla całej aplikacji), więc muszą być wymienione po nazwie.
    //
    // Wzorce wyciągamy z dyrektyw i szukamy w nich nazw — zamiast dopasowywać
    // całą dyrektywę wyrażeniem. Pierwsza wersja tego testu robiła to drugie
    // i spadła na jednym zgubionym ukośniku w samym wzorcu testu, nie w kodzie.
    preg_match_all('/FilesMatch "([^"]+)"/', $ht, $m);
    $wzorce = implode(' ', $m[1]);
    t_true($wzorce !== '', 'główny .htaccess ma w ogóle dyrektywy FilesMatch');

    foreach (['run_migrations', 'backfill_elevation_profiles', 'tiles', 'cron'] as $skrypt) {
        t_true(str_contains($wzorce, $skrypt), "$skrypt.php jest na liście blokowanych");
    }

    t_true(str_contains($wzorce, 'composer'), 'manifesty Composera zablokowane');

    // A `sw.js` MUSI zostać dostępny — service worker apki ładuje się z adresu
    // i blokada zabiłaby tryb offline. Osobny blok wyżej w pliku go wypuszcza.
    t_true(str_contains($ht, 'RewriteRule ^service-worker\.js$ sw.js'), 'service worker dalej ma swój adres');
});
