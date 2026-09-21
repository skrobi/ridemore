<?php
// core/Controllers/Admin/TilesController.php
// KAFLE MAP — podgląd i reset z przeglądarki (2026-08-15).
//
// Do tej pory reset dało się zrobić WYŁĄCZNIE przez `php tiles.php purge`,
// czyli tylko z shella. Na hostingu bez dostępu do konsoli oznaczało to, że
// zepsute kafle zostają na dysku do skutku — a są to pliki, które użytkownicy
// widzą zamiast mapy.
//
// KASOWANIE KAFLI NIE JEST OPERACJĄ NISZCZĄCĄ i to jest powód, dla którego ten
// ekran w ogóle może istnieć bez podwójnego potwierdzania. Kafel to obrazek
// wyliczony z geometrii, która zostaje nietknięta w `gpx_geometry`; skasowany
// odbuduje się przy pierwszym żądaniu. Najgorsze, co da się tu zrobić, to
// spowolnić kilka pierwszych wejść na mapę.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Csrf;
use Models\GpxGeometry;
use Models\TileCache;
use Models\TileSource;
use Utils\View;

class TilesController
{
    public static function index(): void
    {
        View::render('web', 'tiles-admin', [
            'title'   => 'Kafle map — ridemore.bike',
            'noindex' => true,
            'stats'   => TileCache::stats(),
            // Ile śladów czeka na policzenie geometrii. Liczone po plikach na
            // dysku, więc kosztuje jeden hash na plik — przy kilkuset śladach
            // to milisekundy, a bez tej liczby przycisk niżej byłby ślepy.
            'brakGeometrii' => GpxGeometry::missingCount(),
            'wszystkieSlady' => count(GpxGeometry::allGpxUrls()),
            // Ile śladów nie ma jeszcze przydzielonego koloru (migr. 073).
            // To też jest STAN GOTOWOŚCI, nie statystyka: ślad bez koloru
            // rysuje się kolorem stylu, czyli wpada z powrotem do wspólnej
            // zielonej plamy, którą ta migracja miała rozbić.
            'brakKolorow'   => GpxGeometry::missingColorCount(),
            // Solo bez PRZYCIĘTEJ geometrii pod heatmapę społeczności (migr.
            // 076) — osobny licznik od `brakGeometrii` wyżej, patrz jego nota.
            'brakGeometriiSolo' => GpxGeometry::missingTrimmedCount(),
            'info'    => match ($_GET['info'] ?? '') {
                'wyczyszczone' => 'Kafle skasowane. Odbudują się same przy pierwszym wejściu na mapę — pierwsze kilka żądań będzie wolniejszych.',
                'przyciete'    => 'Cache przycięty do limitu.',
                'nic'          => 'Nic nie było do skasowania.',
                'policzone'    => 'Geometria śladów policzona. Mapy mają teraz komplet — kafle dorobią się z ruchu.',
                'pokolorowane' => 'Kolory przydzielone. Ślady leżące blisko siebie mają teraz różne kolory, '
                                . 'a kafle warstwy „Ślady" zostały skasowane, żeby zmiana była widoczna.',
                'naprawione'   => 'Indeks kafli sprawdzony.',
                default        => null,
            },
            'wynik'   => isset($_GET['nowe']) ? [
                'policzone'  => (int) $_GET['nowe'],
                'juz_byly'   => (int) ($_GET['byly'] ?? 0),
                'brak_pliku' => (int) ($_GET['braki'] ?? 0),
            ] : null,
            // Wynik ostatniej naprawy indeksu (repairIndex()) — osobny blok,
            // bo niesie DWA przebiegi naraz (pełna geometria + solo przycięte).
            'wynikNaprawy' => isset($_GET['sprawdzone']) ? [
                'sprawdzone'        => (int) $_GET['sprawdzone'],
                'naprawione'        => (int) $_GET['naprawione'],
                'dopisane'          => (int) ($_GET['dopisane'] ?? 0),
                'sprawdzoneTrim'    => (int) ($_GET['sprawdzoneT'] ?? 0),
                'naprawioneTrim'    => (int) ($_GET['naprawioneT'] ?? 0),
                'dopisaneTrim'      => (int) ($_GET['dopisaneT'] ?? 0),
            ] : null,
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Kafle map']],
        ]);
    }

    /**
     * Reset kafli — całości albo jednej warstwy.
     *
     * Warstwę przepuszczamy przez białą listę, mimo że przychodzi z formularza
     * admina: nazwa warstwy trafia wprost do ścieżki katalogu, więc dowolny
     * ciąg z POST-a znaczyłby kasowanie czegokolwiek na dysku.
     */
    public static function purge(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $wybrana = (string) ($_POST['warstwa'] ?? '');
        $warstwy = in_array($wybrana, [TileSource::LAYER_TRACKS, TileSource::LAYER_HEX], true)
            ? [$wybrana]
            : [TileSource::LAYER_TRACKS, TileSource::LAYER_HEX];

        // Purge przy dużym cache'u potrafi trwać — bez tego PHP przerwałby
        // w połowie, zostawiając część kafli i rejestr rozjechany z dyskiem.
        @set_time_limit(300);

        $ile = 0;
        foreach ($warstwy as $warstwa) {
            $ile += TileCache::purgeLayer($warstwa);
        }

        self::back($ile > 0 ? 'wyczyszczone' : 'nic');
    }

    /**
     * Policzenie geometrii WSZYSTKICH śladów, jakie zna baza — „wygeneruj
     * wszystko na obecną chwilę".
     *
     * Do 2026-08-20 dało się to zrobić wyłącznie przez `php tiles.php backfill`,
     * czyli tylko z konsoli — ten ekran sam odsyłał do niej zdaniem „czego ten
     * ekran NIE robi". Na hostingu bez shella oznaczało to, że po wgraniu partii
     * tras pierwsze wejście na mapę parsowało wszystkie pliki GPX w trakcie
     * żądania o obrazek.
     *
     * Logika siedzi w `GpxGeometry::backfillAll()` — tej samej, którą wywołuje
     * CLI. Idempotentne: pliki już policzone kosztują jedno zapytanie. Od
     * migr. 076 odpala też `backfillAllTrimmed()` — geometrię PRZYCIĘTĄ solo
     * pod heatmapę społeczności, osobne źródło hashy, ta sama idempotencja.
     *
     * KAFLE SAME SIĘ TU NIE GENERUJĄ i to jest świadome: pełna piramida z4–z14
     * dla Polski to ok. 269 000 plików, a realnie ogląda się z niej ułamek.
     * Ta akcja przygotowuje WSZYSTKO, czego kafel potrzebuje, żeby powstać
     * w kilkanaście milisekund przy pierwszym żądaniu.
     */
    public static function backfill(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        // Parsowanie kilkuset GPX-ów potrafi potrwać — bez tego PHP przerwałby
        // w połowie i część śladów zostałaby bez geometrii.
        @set_time_limit(600);

        $wynik = GpxGeometry::backfillAll();
        // Solo PRZYCIĘTE pod heatmapę społeczności (migr. 076) — sam przebieg,
        // osobne wywołanie: inne źródło hashy (wyłącznie solo), inna para
        // tabel. Wynik nie wchodzi do parametrów URL niżej (te opisują TYLKO
        // pełną geometrię) — panel czyta jego stan z `missingTrimmedCount()`
        // przy następnym renderze, jak przy kolorach.
        GpxGeometry::backfillAllTrimmed();

        header('Location: ' . View::url('/admin/kafle') . '?info=policzone'
            . '&nowe=' . $wynik['policzone']
            . '&byly=' . $wynik['juz_byly']
            . '&braki=' . $wynik['brak_pliku']);
        exit;
    }

    /**
     * Przydział kolorów śladom (migr. 073) — „pomaluj to, co jeszcze szare".
     *
     * OSOBNO OD `backfill()`, bo to dwie różne rzeczy i mylenie ich kosztowałoby
     * czas przy pierwszym problemie: tamto LICZY geometrię plików, które jej nie
     * mają (parsowanie GPX, sekundy na plik), a to MALUJE ślady, które geometrię
     * już mają (dwa zapytania na ślad). Po wgraniu partii tras uruchamia się
     * jedno po drugim, ale to nie znaczy, że to jedna operacja.
     *
     * KAFLE LECĄ RAZEM Z KOLORAMI i to jest połowa wartości tego przycisku.
     * Kafle sprzed przydziału leżą na dysku z nagłówkiem `immutable`, więc bez
     * skasowania ich zmiana siedziałaby w bazie, a mapa dalej pokazywałaby
     * jedną zieloną plamę — czyli dokładnie to, na co user się skarżył.
     * Kasujemy CAŁĄ warstwę raz, a nie kafle per ślad: backfill dotyka naraz
     * wszystkiego, co jest na mapie, więc unieważnianie po jednym śladzie
     * przeszłoby po tych samych kaflach tyle razy, ile śladów je dotyka.
     */
    public static function colors(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        @set_time_limit(300);

        $wynik = GpxGeometry::backfillColors();
        if ($wynik['colored'] > 0) {
            TileCache::purgeLayer(TileSource::LAYER_TRACKS);
        }

        header('Location: ' . View::url('/admin/kafle')
            . '?info=pokolorowane&pomalowane=' . $wynik['colored']);
        exit;
    }

    /**
     * Naprawa STARYCH szkód po wyścigu zapisu sprzed transakcji w
     * `GpxGeometry::ensure()`/`storeTrimmed()` (2026-09-07, zgłoszenie usera:
     * na produkcji część śladu nowo zaimportowanego użytkownika „urywa się"
     * pod niektórymi zoomami). Dopisuje wyłącznie brakujące wiersze
     * `gpx_tiles`(`_trimmed`) z geometrii, którą baza już ma — bez czytania
     * pliku GPX, więc działa też dla przejazdów, których właściciela nie da
     * się poprosić o ponowne wgranie. Patrz `GpxGeometry::repairTileIndex`.
     */
    public static function repairIndex(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        @set_time_limit(300);

        $wynik = GpxGeometry::repairTileIndex();
        $wynikTrim = GpxGeometry::repairTrimmedTileIndex();

        // KAFLE MUSZĄ PÓJŚĆ RAZEM Z NAPRAWĄ — ten sam powód i wzorzec co
        // w colors() niżej: stare, dziurawe PNG-i leżą na dysku z
        // `immutable` i naprawiony indeks im nie pomoże bez podmiany.
        if ($wynik['naprawione'] > 0 || $wynikTrim['naprawione'] > 0) {
            TileCache::purgeLayer(TileSource::LAYER_TRACKS);
        }

        header('Location: ' . View::url('/admin/kafle') . '?info=naprawione'
            . '&sprawdzone=' . $wynik['sprawdzone']
            . '&naprawione=' . $wynik['naprawione']
            . '&dopisane=' . $wynik['dopisanych_wierszy']
            . '&sprawdzoneT=' . $wynikTrim['sprawdzone']
            . '&naprawioneT=' . $wynikTrim['naprawione']
            . '&dopisaneT=' . $wynikTrim['dopisanych_wierszy']);
        exit;
    }

    public static function prune(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        @set_time_limit(300);
        TileCache::pruneIfNeeded();
        self::back('przyciete');
    }

    private static function back(?string $info = null): void
    {
        header('Location: ' . View::url('/admin/kafle') . ($info ? '?info=' . $info : ''));
        exit;
    }
}
