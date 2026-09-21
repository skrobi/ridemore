<?php
// tests/zgloszenia_2026_09_11_test.php
// DWANAŚCIE ZGŁOSZEŃ USERA Z 2026-09-11 — po jednym teście na naprawę, żeby
// żadna z nich nie cofnęła się po cichu.
//
// Zestaw jest zbiorczy, a nie rozsypany po tematycznych plikach, z jednego
// powodu: te naprawy łączy DATA i ZGŁOSZENIE, nie warstwa. Szukając za pół roku
// „co zrobiliśmy z tym, że dymek gubił znaną trasę", łatwiej trafić tutaj niż
// zgadywać, czy to było w `znane_trasy_test.php`, czy w `widoki_test.php`.
//
// Konwencja jak w sąsiednich zestawach: helpery z prefiksem `z0911_`, bo
// `run.php` wgrywa wszystkie pliki do jednego procesu.

use Models\KnownRoute;
use Utils\Format;
use Utils\Gpx;

/** Ścieżka do wspólnego katalogu widoków — skraca asercje na źródłach. */
function z0911_plik(string $wzgledna): string
{
    return (string) file_get_contents(CORE_PATH . '/../' . $wzgledna);
}

/**
 * Plik GPX z rozszerzeniami Garmina — `$kanaly` to mapa nazwa => wartość
 * dopisywana do KAŻDEGO punktu (np. `['hr' => 150, 'cad' => 85]`).
 *
 * Prefiks `ns3:` NIE JEST przypadkowy: dokładnie tak nazywa przestrzeń nazw
 * eksport z Garmin Connect, a poprzednia (odrzucona) wersja parsera szukała
 * znaczników po przestrzeni nazw i takie pliki by minęła.
 */
function z0911_gpx_z_kanalami(array $kanaly, int $punktow = 40): string
{
    $trkpts = '';
    for ($i = 0; $i < $punktow; $i++) {
        $ext = '';
        foreach ($kanaly as $nazwa => $wartosc) {
            $ext .= sprintf('<ns3:%s>%s</ns3:%s>', $nazwa, $wartosc, $nazwa);
        }
        $trkpts .= sprintf(
            '<trkpt lat="%.6f" lon="%.6f"><ele>100</ele>'
            . ($ext !== '' ? '<extensions><ns3:TrackPointExtension>' . $ext . '</ns3:TrackPointExtension></extensions>' : '')
            . '</trkpt>',
            54.80,
            17.90 + $i * 0.003
        );
    }
    $path = sys_get_temp_dir() . '/z0911_' . bin2hex(random_bytes(8)) . '.gpx';
    file_put_contents(
        $path,
        '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"'
        . ' xmlns:ns3="http://www.garmin.com/xmlschemas/TrackPointExtension/v1">'
        . '<trk><trkseg>' . $trkpts . '</trkseg></trk></gpx>'
    );
    return $path;
}

// ───────────────────────────────────────────────────────────── 1. DYMEK MAPY

t_test('1: klik w mapę składa JEDEN dymek, nie dwa gaszące się nawzajem', function () {
    $js = z0911_plik('assets/js/discovery-map.js');

    // ISTOTA BŁĘDU: dwa `map.on('click')`, każdy kończący się `.openOn(map)`.
    // Leaflet zamyka poprzedni dymek przy otwarciu nowego, więc ta odpowiedź,
    // która przyszła druga, kasowała pierwszą — i znana trasa znikała pod
    // przejazdem solo. Liczymy więc OTWARCIA dymków w tym pliku.
    $otwarcia = substr_count($js, '.openOn(map)');
    t_eq(2, $otwarcia, 'w całym pliku są dokładnie dwa .openOn(map) — jeden na ślady/trasy, jeden na skarby');

    // Obie sekcje muszą lądować w TYM SAMYM kontenerze.
    t_true(str_contains($js, 'dymekTras(trasy, box)'), 'szlaki dopisują się do wspólnego pudełka');
    t_true(str_contains($js, 'dymekPrzejazdow(przejazdy, box)'), 'przejazdy dopisują się do tego samego pudełka');

    // Funkcje sekcji nie mogą już tworzyć własnego `.map-pop` — to była
    // dokładnie ta druga, konkurencyjna zawartość dymka.
    t_false(
        str_contains($js, "function dymekTras(trasy) {") || str_contains($js, "function dymekPrzejazdow(przejazdy) {"),
        'żadna z funkcji sekcji nie buduje już osobnego dymka'
    );
});

// ─────────────────────────────────────────────── 2. NAZWA PRZEJAZDU SOLO

t_test('2: aktywność na profilu bierze NADANĄ nazwę przejazdu, nie generyczną', function () {
    $model = z0911_plik('core/Models/RiderFeed.php');

    // Zapytanie musi w ogóle pobierać nazwę — poprzednia wersja jej nie brała
    // i wpisywała tytuł na sztywno, więc zmiana nazwy nigdzie nie docierała.
    t_true(str_contains($model, 'a.name'), 'zapytanie pobiera własną nazwę przejazdu');
    t_true(str_contains($model, 'd.activity_name AS device_name'), 'zapytanie pobiera nazwę z licznika');
    t_false(str_contains($model, "'title'      => 'Przejazd solo',"), 'tytuł nie jest już wpisany na sztywno');

    // Reguła priorytetu ma zostać JEDNA — ta sama, której używa strona
    // przejazdu i dymek na mapie.
    t_true(
        str_contains($model, 'RideController::rideName('),
        'tytuł liczy wspólna reguła, a nie druga kopia priorytetów'
    );

    // I sama reguła: własna nazwa > nazwa z licznika > opis generyczny.
    t_eq('Moja pętla', \Controllers\RideController::rideName(
        ['name' => 'Moja pętla', 'device_name' => 'Morning Ride', 'source_code' => 'solo']
    ), 'własna nazwa wygrywa z nazwą licznika');
    t_eq('Morning Ride', \Controllers\RideController::rideName(
        ['name' => null, 'device_name' => 'Morning Ride', 'source_code' => 'solo']
    ), 'bez własnej nazwy zostaje ta z licznika');
    t_eq('Przejazd solo', \Controllers\RideController::rideName(
        ['name' => null, 'device_name' => null, 'source_code' => 'solo']
    ), 'bez obu zostaje opis generyczny');
});

// ─────────────────────────────────────────────────────── 3. ROK W DACIE

t_test('3: krótka data pokazuje rok, gdy nie jest bieżący', function () {
    $rokTeraz = (int) date('Y');

    // Data z TEGO roku zostaje krótka — najczęstszy przypadek (wyjazd za dwa
    // tygodnie) nie ma zyskać szumu.
    t_eq($rokTeraz . '-05-25', date('Y-m-d', strtotime($rokTeraz . '-05-25')), 'kontrola daty pomocniczej');
    t_false(
        str_contains((string) Format::dateShort($rokTeraz . '-05-25'), (string) $rokTeraz),
        'data z bieżącego roku nie dokleja roku'
    );

    // Archiwum i przyszłość — rok MUSI być, bo inaczej „25 maj" jest zagadką.
    t_eq('20 paź 2024', Format::dateShort('2024-10-20'), 'data z przeszłości niesie rok');
    t_eq('5 mar ' . ($rokTeraz + 1), Format::dateShort(($rokTeraz + 1) . '-03-05'), 'data z przyszłości niesie rok');

    // Zakres rządzi się tą samą regułą — inaczej „9–10 sie" gubiłoby rok tam,
    // gdzie pojedyncza data by go pokazała.
    t_eq('9–10 sie 2024', Format::dateRangeShort('2024-08-09', '2024-08-10'), 'zakres w jednym miesiącu niesie rok');
    t_false(
        str_contains(Format::dateRangeShort($rokTeraz . '-08-09', $rokTeraz . '-08-10'), (string) $rokTeraz),
        'zakres w bieżącym roku zostaje krótki'
    );

    // null nadal jest null-em, nie pustym stringiem — widoki na tym polegają.
    t_null(Format::dateShort(null), 'brak daty zwraca null');
});

// ──────────────────────────────────────── 4. DODATKOWE KANAŁY Z GPX

t_test('4: parser czyta tętno i kadencję z <extensions>, niezależnie od prefiksu', function () {
    $plik = z0911_gpx_z_kanalami(['hr' => 150, 'cad' => 85, 'atemp' => 21]);
    try {
        $parsed = Gpx::parse($plik);
        $klucze = array_column($parsed['metricChannels'], 'key');

        t_true(in_array('hr', $klucze, true), 'tętno rozpoznane mimo prefiksu ns3:');
        t_true(in_array('cad', $klucze, true), 'kadencja rozpoznana');
        t_true(in_array('tmp', $klucze, true), 'temperatura rozpoznana');
        t_false(in_array('pwr', $klucze, true), 'kanał, którego w pliku nie ma, nie pojawia się z niczego');

        // Próbki wykresu muszą nieść odczyty POD TYMI SAMYMI indeksami co
        // wysokość — na tym stoi cała „jedna skala km".
        $probka = $parsed['elevationProfile'][0];
        t_eq(150, $probka['hr'] ?? null, 'próbka profilu niesie tętno obok wysokości');
        t_eq(85, $probka['cad'] ?? null, 'próbka profilu niesie kadencję');
        t_true(isset($probka['d'], $probka['e']), 'próbka dalej niesie dystans i wysokość');

        $tetno = null;
        foreach ($parsed['metricChannels'] as $k) {
            if ($k['key'] === 'hr') { $tetno = $k; }
        }
        t_eq(150, $tetno['avg'], 'średnia liczona z odczytów, nie zgadywana');
        t_eq('bpm', $tetno['unit'], 'jednostka jedzie razem z kanałem');
    } finally {
        @unlink($plik);
    }
});

t_test('4: plik bez rozszerzeń nie produkuje żadnego wykresu', function () {
    $plik = z0911_gpx_z_kanalami([]);
    try {
        $parsed = Gpx::parse($plik);
        t_true($parsed['metricChannels'] === [], 'trasa z planera nie ma dodatkowych kanałów');
        // I nie może zyskać pustych kluczy w próbkach — to byłby czysty balast
        // w JSON-ie lecącym do przeglądarki 50 razy.
        t_false(array_key_exists('hr', $parsed['elevationProfile'][0]), 'próbka nie ma klucza kanału, którego nie ma');
    } finally {
        @unlink($plik);
    }
});

t_test('4: odczyt spoza zakresu i zbyt rzadki kanał nie dostają wykresu', function () {
    // Tętno 900 to śmieć z zerwanego pasa, nie wynik. Jeden taki punkt
    // rozciągnąłby skalę tak, że reszta byłaby płaską kreską.
    $plik = z0911_gpx_z_kanalami(['hr' => 900]);
    try {
        $parsed = Gpx::parse($plik);
        t_true($parsed['metricChannels'] === [], 'odczyt spoza zakresu traktowany jak jego brak');
    } finally {
        @unlink($plik);
    }

    // Licznik bez pasa zapisuje same zera — wykres jednej poziomej kreski na
    // zerze wygląda na zepsuty, więc kanał ma nie wejść.
    $plik = z0911_gpx_z_kanalami(['hr' => 0]);
    try {
        $parsed = Gpx::parse($plik);
        t_true($parsed['metricChannels'] === [], 'same zera to „czujnika nie było", a nie dane');
    } finally {
        @unlink($plik);
    }
});

t_test('4: wykresy dostają wspólną skalę i wspólny kursor', function () {
    $js = z0911_plik('assets/js/gpx-map.js');

    t_true(str_contains($js, 'function ridemoreRenderMetricChart('), 'jest osobny rysownik kanału');
    t_true(str_contains($js, 'ridemoreProfileCursorRegister'), 'wykresy zapisują się do wspólnego kursora');
    t_true(str_contains($js, 'ridemoreProfileCursorBroadcast'), 'najechanie rozgłasza pozycję pozostałym');

    // Marginesy muszą być IDENTYCZNE z wykresem wysokości — inaczej ten sam
    // kilometr wypadałby w innym pikselu i „wspólna skala" byłaby przypadkiem.
    t_eq(
        2,
        substr_count($js, "left: 44 }"),
        'oba rysowniki używają tego samego lewego marginesu (44 px)'
    );

    $partial = z0911_plik('views/web/partials/metric-charts.php');
    t_true(str_contains($partial, 'data-metric-profile'), 'partial podaje te same próbki co profil wysokości');
});

// ──────────────────────────────────────── 5. ZŁOTA RAMKA ZDOBYTEGO SKARBU

t_test('5: zdobyty skarb dostaje złotą ramkę w tych samych kolorach co pinezka', function () {
    $partial = z0911_plik('views/web/partials/treasure-list.php');
    $css = z0911_plik('assets/css/style.css');

    t_true(str_contains($partial, "disc-trail--mine"), 'karta zdobytego skarbu dostaje modyfikator');
    t_true(str_contains($css, '.disc-trail--mine{'), 'modyfikator ma regułę w arkuszu');

    // TE SAME dwa kolory co `.tre-pin.is-found` — karta i pinezka opisują ten
    // sam byt, więc „zdobyte" nie może znaczyć dwóch różnych złotych.
    $regula = substr($css, strpos($css, '.disc-trail--mine{'), 400);
    t_true(str_contains($regula, '#A97911'), 'obwódka w kolorze obwódki znalezionej pinezki');
    t_true(str_contains($regula, '#E7B03A'), 'wypełnienie w kolorze wypełnienia znalezionej pinezki');

    // Napis „MASZ" zostaje: sam kolor nie jest dostępny dla każdego.
    t_true(str_contains($partial, 'is-done'), 'podpis stanu zostaje obok koloru');
});

// ─────────────────────────── 6 i 10. PRZYCISKI NA STRONIE ZNANEJ TRASY

t_test('6: trzy przyciski trasy stoją jedną grupą, w jednym partialu', function () {
    $partial = z0911_plik('views/web/partials/trail-actions.php');
    $strona = z0911_plik('views/web/pages/trail.php');

    t_true(str_contains($partial, 'nav-button.php'), 'grupa zawiera przycisk nawigacji');
    t_true(str_contains($partial, 'Wyjazdy w tych stronach'), 'grupa zawiera wyjazdy w okolicy');
    t_true(str_contains($partial, 'Pobierz GPX'), 'grupa zawiera pobranie GPX');

    // Dwa miejsca zamieszkania (na okładce i — bez niej — w nagłówku), ale
    // JEDNO źródło. Trzecia kopia rozjechałaby się przy pierwszej zmianie.
    t_eq(2, substr_count($strona, "partials/trail-actions.php"), 'strona dołącza grupę dokładnie w dwóch miejscach');
    t_false(str_contains($strona, 'Wyjazdy w tych stronach'), 'strona nie ma już własnej kopii przycisków');
});

t_test('10: „Wyjazdy w tych stronach" filtruje po parametrze, który lista czyta', function () {
    $partial = z0911_plik('views/web/partials/trail-actions.php');
    $lista = z0911_plik('core/Controllers/EventsListController.php');

    // Kontroler czyta `regions`; poprzednia wersja przycisku wysyłała `region`,
    // więc parametr przelatywał bez śladu i link prowadził na pełną listę.
    t_true(str_contains($lista, "\$arr('regions')"), 'lista wydarzeń czyta parametr `regions`');
    // Patrzymy na SAM ADRES, nie na cały plik: komentarz nad przyciskiem
    // cytuje starą, błędną nazwę parametru i słusznie ma tam zostać.
    $adresy = implode("
", array_filter(
        explode("
", $partial),
        static fn(string $l): bool => str_contains($l, '/wydarzenia')
    ));
    t_true(str_contains($adresy, '?regions='), 'przycisk wysyła `regions`');
    t_false(str_contains($adresy, '?region='), 'przycisk nie wysyła już `region`');
});

// ────────────────────────────────── 7. PANEL SKARBÓW BEZ PASKÓW PRZEWIJANIA

t_test('7: formularz skarbu nie mieszka już w wąskim, przewijanym panelu', function () {
    // TEN TEST ZMIENIŁ SENS 2026-09-12, i to jest cała historia tej poprawki.
    //
    // 11 września zwinąłem `.tr-row` do jednej kolumny, żeby zabić paski
    // przewijania W POZIOMIE w panelu szerokim na 360 px. Zadziałało — i przy
    // okazji dołożyło ~300 px wysokości panelowi, który miał `max-height:620px`
    // i własny `overflow-y`. Efekt: 1299 px formularza w okienku 620 px, czyli
    // 2,2 ekranu przewijania W PIONIE, z „Zapisz skarb" poza zasięgiem. User
    // wrócił nazajutrz ze zdaniem „ta część również jest nie do użytku".
    //
    // Prawdziwą przyczyną nie była liczba kolumn, tylko SZEROKOŚĆ PANELU.
    // Dlatego `.tr-row` już nie istnieje: pola leżą w `.tr-grid` w panelu,
    // który dostał 772 px zamiast 360 (mapa oddała mu połowę swojej szerokości).
    $css = z0911_plik('assets/css/style.css');

    t_true(str_contains($css, '.tr-work{display:grid;grid-template-columns:520px'), 'mapa oddała szerokość formularzowi');
    t_false(str_contains($css, 'grid-template-columns:minmax(0,1fr) 360px'), 'panel nie ma już 360 px');

    // BEZ WEWNĘTRZNEGO PRZEWIJANIA — to jest niezmiennik, nie kosmetyka.
    // Wystarczy, że ktoś przywróci `max-height` na `.tr-work__side`, żeby cała
    // ta naprawa cofnęła się bez śladu w interfejsie.
    $panel = substr($css, strpos($css, '.tr-work__side{'), 220);
    t_false(str_contains($panel, 'overflow-y'), 'panel nie ma własnego paska przewijania');
    t_false(str_contains($panel, 'max-height'), 'panel nie jest ograniczony wysokością');
});

// ──────────────────────────────────────────── 8 i 9. PULS

t_test('8: skarb w Pulsie prowadzi do skarbu, nie na mapę całego kraju', function () {
    $model = z0911_plik('core/Models/Pulse.php');
    $widok = z0911_plik('views/web/pages/pulse.php');

    t_true(str_contains($model, "'treasureId'"), 'wpis niesie identyfikator skarbu');
    t_true(str_contains($widok, "'&skarb=' . (int) \$item['treasureId']"), 'adres kadruje mapę na skarbie');

    // BRAMKA UJAWNIENIA: dla skarbu ukrytego adres z parametrem byłby
    // obietnicą bez pokrycia — kontroler i tak odda `null`.
    t_true(str_contains($model, "reveal_level"), 'model pyta o poziom ujawnienia');
    t_true(
        str_contains(z0911_plik('core/Controllers/DiscoveryController.php'), 'reveal_level >= 2'),
        'kadrowanie po stronie kontrolera dalej wpuszcza tylko skarby jawne'
    );
});

t_test('9: wgrane ślady mają w Pulsie własny wpis z linkiem do rowerzysty', function () {
    $model = z0911_plik('core/Models/Pulse.php');
    $widok = z0911_plik('views/web/pages/pulse.php');

    t_true(str_contains($model, 'private static function trackUploads('), 'jest ósme źródło wpisów');
    t_true(str_contains($model, 'self::trackUploads($before)'), 'źródło jest wpięte do feedu');

    // ZWIJANIE PER OSOBA I DOBA — import z Garmina wciąga całą historię naraz;
    // bez tego pierwsze podłączenie licznika wyczyściłoby cały Puls.
    t_true(str_contains($model, 'DATE(a.created_at)'), 'wpisy zwijają się do jednego na dobę');

    // BRAMKA WIDOCZNOŚCI — to jedyny wpis w Pulsie wskazujący na człowieka,
    // więc kto ukrył się z listy rowerzystów, nie może się tu pojawić.
    t_true(str_contains($model, 'u.roster_visible = 1'), 'ukryty rowerzysta nie trafia do Pulsu');
    t_true(str_contains($model, "u.public_slug IS NOT NULL"), 'wpis bez adresu profilu w ogóle nie powstaje');

    t_true(str_contains($widok, "'/rowerzysta/' . \$item['riderSlug']"), 'wpis linkuje do profilu');

    // Klucz pól NIE MOŻE nazywać się `newCells` — `feed()` nadpisuje tę nazwę
    // polami turnusu (zerem dla wpisu bez turnusu), więc wartość by nie dojechała.
    t_true(str_contains($model, "'cellsNew'"), 'liczba odkrytych pól jedzie pod własnym kluczem');
});

// ─────────────────────────────────── 11. NAWIERZCHNIA ZNANEJ TRASY

t_test('11: nowa trasa zapisuje nawierzchnię podaną przez wołającego', function () {
    $plik = z0911_gpx_z_kanalami([]);
    $routeId = null;
    try {
        $trasa = KnownRoute::createFromGpx(
            'TEST nawierzchnia ' . bin2hex(random_bytes(4)),
            null,
            $plik,
            '/assets/uploads/gpx/z0911.gpx',
            null,
            [62, 25, 13]
        );
        $routeId = (int) $trasa['id'];
        $row = KnownRoute::find($routeId);

        t_eq(62, (int) $row['surface_asphalt_pct'], 'asfalt zapisany');
        t_eq(25, (int) $row['surface_gravel_pct'], 'gravel zapisany');
        t_eq(13, (int) $row['surface_trail_pct'], 'ścieżka zapisana');
    } finally {
        @unlink($plik);
    }
});

t_test('11: trasa bez detekcji ma NULL-e, nie zera', function () {
    $plik = z0911_gpx_z_kanalami([]);
    try {
        $trasa = KnownRoute::createFromGpx(
            'TEST bez nawierzchni ' . bin2hex(random_bytes(4)),
            null,
            $plik,
            '/assets/uploads/gpx/z0911b.gpx'
        );
        $row = KnownRoute::find((int) $trasa['id']);

        // NULL znaczy „nie wiadomo". Zero znaczyłoby „zero asfaltu" — czyli
        // wpisaną do bazy nieprawdę, którą strona pokazałaby jako pasek.
        t_null($row['surface_asphalt_pct'], 'brak detekcji zostawia NULL, nie zero');
    } finally {
        @unlink($plik);
    }
});

t_test('11: Overpass odpytuje warstwa HTTP i CLI, nigdy model przy zapisie', function () {
    $model = z0911_plik('core/Models/KnownRoute.php');
    $kontroler = z0911_plik('core/Controllers/Admin/KnownRouteController.php');

    // Gdyby detekcja wróciła do środka `createFromGpx()`, każdy test tworzący
    // trasę znów czekałby do 70 s na zewnętrzny serwis — zestaw przestawał się
    // kończyć. Ten test jest zabezpieczeniem właśnie przed tym powrotem.
    t_false(
        str_contains($model, 'self::detectSurface($parsed'),
        'model nie woła detektora w trakcie zapisu trasy'
    );
    t_true(str_contains($kontroler, 'KnownRoute::detectSurface('), 'detekcję odpala kontroler');
    t_true(str_contains($kontroler, 'set_time_limit(300)'), 'kontroler podnosi limit czasu jak /api/gpx/parse');
    t_true(
        is_file(CORE_PATH . '/../backfill_known_route_surface.php'),
        'jest skrypt dla tras sprzed migracji'
    );
});

t_test('11: pasek nawierzchni to jeden partial, nie kopia na stronę', function () {
    $partial = z0911_plik('views/web/partials/surface-breakdown.php');
    $trasa = z0911_plik('views/web/pages/trail.php');
    $event = z0911_plik('views/web/pages/event-page.php');

    t_true(str_contains($partial, 'surface-bar'), 'partial rysuje pasek');
    t_true(str_contains($trasa, "partials/surface-breakdown.php"), 'strona trasy używa partiala');
    t_true(str_contains($event, "partials/surface-breakdown.php"), 'strona wydarzenia używa partiala');

    // Żadna strona nie może mieć już własnej kopii segmentów.
    t_false(str_contains($trasa, 'surface-seg surface-asphalt'), 'strona trasy nie składa paska sama');
    t_false(str_contains($event, 'surface-seg surface-asphalt'), 'strona wydarzenia nie składa paska sama');
});

// ──────────────────────── 12. „ZORGANIZOWANE WYDARZENIE" W CAŁEJ APLIKACJI

t_test('12: format „ustawka" nazywa się wszędzie tak samo', function () {
    // Słownik jest źródłem prawdy dla filtrów i kreatora.
    $nazwa = Core\Database::connection()->query("
        SELECT di.name FROM dictionary_items di
          JOIN dictionaries d ON d.id = di.dictionary_id
         WHERE d.code = 'event_type' AND di.code = 'ustawka'
    ")->fetchColumn();
    t_eq('Zorganizowane wydarzenie', $nazwa, 'słownik niesie nową nazwę');

    // KOD pozycji zostaje bez zmian — wiszą na nim adresy filtrów
    // (`?eventTypes[]=ustawka`), gałęzie kreatora i warianty trasy.
    $stopka = z0911_plik('views/web/partials/footer.php');
    t_true(str_contains($stopka, 'eventTypes[]=ustawka'), 'adresy filtrów dalej używają kodu `ustawka`');

    // Żadne miejsce w kodzie nie może już mówić starą nazwą. Formularz
    // organizatora jest wyjątkiem: „Wspólne jazdy z pasji" opisuje RODZAJ
    // ORGANIZATORA, nie format wydarzenia, i celowo zostaje.
    foreach ([
        'views/web/pages/home.php',
        'views/web/pages/organizer-profile.php',
        'views/web/partials/home-event-card.php',
        'views/web/partials/wizard/script.php',
        'views/web/partials/wizard/type-picker.php',
        'views/web/pages/event-page.php',
        'views/web/pages/events-list.php',
        'core/Resources/RiderProfileResource.php',
    ] as $plik) {
        $tresc = z0911_plik($plik);
        t_false(
            str_contains($tresc, 'Wspólna jazda') || str_contains($tresc, 'Wspólne jazdy'),
            $plik . ' nie używa już starej nazwy'
        );
    }
});
