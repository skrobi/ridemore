<?php
// core/Controllers/TileController.php
// WYDANIE KAFLA — wołany WYŁĄCZNIE wtedy, gdy pliku jeszcze nie ma.
//
// Drugie i każde kolejne żądanie tego samego kafla nie dociera tutaj: Apache
// widzi istniejący plik i oddaje go sam (`RewriteCond %{REQUEST_FILENAME} !-f`
// w .htaccess). To jest cała architektura tego rozwiązania — PHP obsługuje
// pierwsze wejście na dany fragment mapy, potem znika z obiegu.
//
// STĄD DECYZJA O ADRESIE: /assets/tiles/{warstwa}/{klucz}/{z}/{x}/{y}.png musi
// być JEDNOCZEŚNIE adresem HTTP i ścieżką pliku na dysku. Gdyby kafle leżały
// gdzie indziej niż wskazuje URL (np. w cache/ poza webrootem), regułą !-f nie
// dałoby się ich serwować i każdy kafel budziłby PHP-a na zawsze.
namespace Controllers;

use Core\Session;
use Models\Event;
use Models\GpxGeometry;
use Models\KnownRoute;
use Models\OsmBasemap;
use Models\Pulse;
use Models\RoutePreview;
use Models\TileCache;
use Models\TileSource;
use Utils\TileGrid;
use Utils\TileRenderer;

class TileController
{
    /**
     * Skala pól odkryć — te same kolory i krycia co SCALE w discovery-map.js.
     *
     * Powtórzenie jest tu świadome i ograniczone do CZTERECH PAR LICZB: jedna
     * strona rysuje w JS, druga w GD, a wspólny plik konfiguracyjny dla obu
     * kosztowałby więcej (generowanie JS-a z PHP albo pobieranie palety osobnym
     * żądaniem) niż jest wart. Zmiana tutaj wymaga zmiany tam ORAZ podbicia
     * epoki warstwy `hex` — inaczej na dysku zostaną kafle w starych kolorach.
     *
     * PIERWSZY STOPIEŃ MA KRYCIE 0 (2026-08-14, uwaga usera). „Odkryte" znaczy
     * odsłonięte — pole, przez które ktoś przejechał raz, pokazuje gołą mapę
     * z drogą i własnym śladem, a nie zieloną plamę. To jest zdecydowana
     * większość każdej mapy, więc w praktyce odkrycie = przezroczystość.
     *
     * Kolor wraca dopiero tam, gdzie NIESIE INFORMACJĘ, której z gołej mapy nie
     * widać: że tędy jeździ się wielokrotnie. Dlatego krycia są niskie
     * i rosnące (0,18 → 0,38), a nie takie jak przy zamalowywaniu — mają
     * przyciemnić teren, nie zasłonić go.
     */
    private const SCALE = [
        ['color' => '#5BA969', 'alpha' => 0.00],  // odkryte  — czysta mapa
        ['color' => '#EFC248', 'alpha' => 0.18],  // kilka razy
        ['color' => '#E28446', 'alpha' => 0.28],  // często
        ['color' => '#DA5244', 'alpha' => 0.38],  // bardzo często
    ];

    /**
     * Mgła nad terenem nieodkrytym plus jej OBRAMOWANIE (uwaga usera 2026-08-14).
     *
     * `outline` to ciemniejszy odcień tej samej chłodnej szarości, nie osobny
     * kolor: rąbek ma czytać się jako krawędź mgły, a nie jako narysowana na
     * mapie siatka. Biel, którą obrys miał dzień wcześniej, sprawdzała się na
     * polu ZAMALOWANYM — na jasnej mgle znikała.
     */
    private const FOG = ['color' => '#8B95A3', 'alpha' => 0.55, 'outline' => '#63707F'];

    /**
     * Rozmiar złożonego obrazka "Trasa dnia" (routeOfDayMap) — te same
     * proporcje co dotychczasowy viewBox wykresu profilu (HomeController::
     * ROTD_VIEW_W/H), żeby obie wizualizacje zajmowały wizualnie tyle samo
     * miejsca niezależnie od tego, która akurat się wyrenderuje. Osobna stała
     * (nie import z HomeController) — tu to piksele prawdziwego obrazu, tam
     * jednostki viewBoxa SVG; wspólna wartość liczbowa jest zbiegiem
     * okoliczności, nie zależnością.
     */
    private const RMAP_W = 1200;
    private const RMAP_H = 260;

    /** Oddech wokół trasy w oknie kadru — trasa nie ma dotykać krawędzi. */
    private const RMAP_PAD_FRAC = 0.12;

    /**
     * Pola tej trasy na złożonym obrazku — WIDOCZNE wypełnienie, nie dziura
     * w mgle jak na żywej mapie odkryć (patrz komentarz przy `$fog` w
     * renderHexes(): "kafel wydarzenia czy trasy pokazuje pola PRZY OKAZJI",
     * więc mgła by tu tylko zasłaniała mapę bez powodu).
     *
     * KOLOR: `--blaze-dark` z CSS (#F0B41E), nie zieleń — zmierzone na
     * żywym kaflu (2026-09-05): pole odkryć ma tu ok. tyle samo pikseli, ile
     * grubość śladu (ślad rysuje się w jednym z sześciu kolorów palety, patrz
     * Utils\TrackPalette::COLORS — ŻADEN z nich nie jest złoty), więc zielone
     * wypełnienie ginęło POD śladem albo zlewało się z zielenią terenu OSM.
     * Bursztyn jest jedynym kolorem serwisu zarezerwowanym pod "zwróć uwagę"
     * (`.eyebrow .blaze` na tej samej karcie, dawny wyróżnik mety w
     * odrzuconym wykresie) i nie występuje ani w palecie śladów, ani w
     * typowym kolorycie mapy — świeci przy KAŻDYM śladzie i KAŻDYM terenie.
     * Krycie 0,55 (nie 0,35 jak przy pierwszej próbie) z tego samego powodu:
     * przy cienkiej obwódce pola i śladzie grubszym niż samo pole subtelny
     * odcień ginął całkowicie, mocniejszy prześwituje jako obwódka wokół
     * śladu, nawet gdy wypełnienie jest pod nim.
     */
    private const RMAP_FIELD_COLOR = '#F0B41E';
    private const RMAP_FIELD_ALPHA = 0.55;

    /**
     * OBRAZEK ŚLADU NA KARCIE PULSU (2026-09-12) — dwa rozmiary, bo dwa
     * miejsca mają inny kształt: karta na stronie głównej to pas
     * (`.op-card__v`, 132 px wysokości przy 290–480 px szerokości, zmierzone
     * w przeglądarce), a miniatura na /puls to kwadrat 132 px (96 px na
     * telefonie). Wartości są ok. 2× względem CSS — obrazek jest rastrem
     * i na gęstszym ekranie 1:1 wyglądałby miękko.
     *
     * Nazwy po polsku, jak reszta segmentów adresu w tym serwisie, i wchodzą
     * do ścieżki na dysku — stąd biała lista tablicą, nie sklejanie z wejścia.
     */
    private const SLAD_SIZES = [
        'karta'   => [600, 272],
        'kwadrat' => [264, 264],
    ];

    /**
     * Progi kadrowania obrazka śladu — ciaśniejsze niż stałe `GpxGeometry`
     * (1000 km / 150 km), bo „za szeroko" znaczy co innego na 272 px niż na
     * pełnym ekranie. Uzasadnienie przy `sladBounds()`.
     */
    private const SLAD_CLUSTER_TRIGGER_KM = 120;
    private const SLAD_CLUSTER_BUCKET_KM  = 50;

    public static function show(string $layer, string $key, string $z, string $x, string $y): void
    {
        $z = (int) $z;
        $x = (int) $x;
        $y = (int) $y;

        if (!in_array($layer, [TileSource::LAYER_TRACKS, TileSource::LAYER_HEX], true)
            || !TileGrid::isValid($z, $x, $y)
            || !TileSource::isAllowed($key)) {
            // 404, nie 403 — tak samo jak przy ukrytym profilu, żeby odpowiedź
            // nie zdradzała, czy klucz istnieje, czy tylko jest niedostępny.
            http_response_code(404);
            exit;
        }

        // BLOKADA SESJI ZWALNIANA TU, a nie później: wszystko, co z niej
        // czytamy (`TileSource::isAllowed` wyżej — czyli KTO pyta), jest już
        // przeczytane, a wszystko poniżej to czysty odczyt bazy i rysowanie.
        // Bez tego kafle jednego kadru stoją w kolejce jeden za drugim, bo PHP
        // trzyma plik sesji na wyłączność — patrz Core\Session z pomiarem.
        Session::release();

        $png = $layer === TileSource::LAYER_HEX
            ? self::renderHexes($key, $z, $x, $y)
            : self::renderTracks($key, $z, $x, $y);

        // DWA WARUNKI ZAPISU, każdy o czym innym:
        //   klucz  — czy te dane w ogóle wolno położyć w katalogu publicznym
        //            (prywatność, patrz TileSource::isCacheable),
        //   zoom   — czy opłaca się je trzymać. Poziomy głębsze niż indeks
        //            (2026-08-20, po podniesieniu MAX_Z do 18) powstają na
        //            żądanie i znikają: jest ich 4^(z-14) na kafel indeksu, więc
        //            zapisywanie ich wysadziłoby i piramidę, i unieważnianie —
        //            a render takiego kafla jest tani, bo obejmuje ok. 150 m
        //            terenu i prawie nie ma w nim geometrii.
        $prywatny = !TileSource::isCacheable($key);
        $cacheable = !$prywatny && $z <= TileGrid::INDEX_Z;
        if ($cacheable) {
            TileCache::store($layer, $key, $z, $x, $y, $png);
        }

        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        // Nagłówek musi paść TUTAJ, mimo że na *.png jest reguła w .htaccess:
        // ta reguła dotyczy plików serwowanych przez Apache, a tę odpowiedź
        // generuje PHP i bez tego poleciałaby bez cache'u w ogóle.
        // TRZY ODPOWIEDZI NA JEDNO PYTANIE „czy to wolno trzymać", bo pytania
        // są naprawdę trzy (rozdzielone 2026-09-02, wcześniej dwa ostatnie
        // przypadki dostawały tę samą, najostrzejszą odpowiedź):
        //   * kafel zapisany na dysku — cache publiczny na zawsze, bo adres
        //     niesie epokę i zmiana treści zmienia adres,
        //   * kafel spod prywatnego klucza — `no-store`, bez wyjątków: mapa
        //     osoby ukrytej z list nie ma prawa zostać ani na dysku serwera,
        //     ani w cache'u pośredniczącym (patrz Models\TileSource),
        //   * kafel GŁĘBOKIEGO ZOOMU pod jawnym kluczem — nie zapisujemy go na
        //     dysku (byłoby ich 4^(z-14) na kafel indeksu), ale PRZEGLĄDARCE
        //     wolno go trzymać: to ten sam obraz pod tym samym adresem z tą
        //     samą epoką. Do tej daty leciało tu `no-store`, więc przybliżenie,
        //     odsunięcie i powrót w to samo miejsce renderowały wszystko od
        //     nowa — czyli najczęstszy sposób oglądania mapy był najdroższy.
        header($cacheable
            ? 'Cache-Control: public, max-age=31536000, immutable'
            : ($prywatny
                ? 'Cache-Control: private, no-store'
                : 'Cache-Control: private, max-age=86400, immutable'));
        echo $png;
    }

    /**
     * "Trasa dnia" na stronie głównej — jeden złożony obrazek zamiast żywej
     * mapy: prawdziwe kafle OSM plus pola tej trasy/wydarzenia plus jej ślad,
     * sklejone w płaski PNG (decyzja usera 2026-09-05: "Statyczny, składany
     * obrazek z prawdziwych kafli" — żeby strona główna nie ciągnęła
     * Leafletu/JS-a dla jednej dekoracyjnej karty). Adres niesie datę
     * (`{key}/{data}.png`), więc obrazek odświeża się raz dziennie — ta sama
     * sztuczka co epoka przy zwykłych kaflach (TileCache), tylko wbudowana
     * w sam adres, bo to JEDEN plik, nie piramida.
     *
     * `$key` to WYŁĄCZNIE `ev-{id}`/`kr-{id}` — inne klucze TileSource (`me`,
     * `u-{slug}`, ...) są tu CELOWO zablokowane. Ten obrazek ląduje na
     * publicznym dysku pod adresem widocznym w źródle strony głównej, a
     * jedyne klucze, które i tak są publiczne bez względu na to, kto pyta,
     * to zapowiadane trasy wydarzeń i katalog znanych tras — patrz nota
     * o prywatności na górze Models\TileSource.
     */
    public static function routeOfDayMap(string $key, string $date): void
    {
        if (!preg_match('/^\d{8}$/', $date)
            || !TileSource::isAllowed($key)
            || !(str_starts_with($key, 'ev-') || str_starts_with($key, 'kr-'))) {
            http_response_code(404);
            exit;
        }

        Session::release();

        $resolved = self::resolveRotdInputs($key);
        if ($resolved === null) {
            http_response_code(404);
            exit;
        }
        [$bounds, $cellIds] = $resolved;

        $png = self::renderRotdMap($key, $bounds, $cellIds);
        self::storeRotdMap($key, $date, $png);

        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $png;
    }

    /**
     * OBRAZEK ŚLADU NA KARCIE PULSU — `/assets/tiles/slad/{key}/{rozmiar}/{stamp}.png`
     * (2026-09-12, prośba usera: „żeby w card pojawiało się zdjęcie śladu").
     *
     * Ten sam mechanizm co „Trasa dnia" wyżej: jeden złożony PNG zamiast
     * piramidy kafli, zapisany pod adresem, którym karta posłuży się następnym
     * razem — więc drugie i każde kolejne wejście oddaje Apache, a PHP widzi
     * wyłącznie pierwsze.
     *
     * KLUCZ TO GRUPA WPISU, NIE WARSTWA MAPY: `sw-{userId}-{RRRRMMDD}` opisuje
     * dokładnie to, czym jest wpis „wgrane ślady" — jedna osoba, jedna doba.
     * Dlatego NIE przechodzi przez `TileSource::isAllowed()` (tam mieszkają
     * klucze warstw, które ktoś może sobie włączyć na mapie) i ma własną,
     * równie ciasną walidację: klucz staje się nazwą katalogu na dysku.
     *
     * CO Z §27: solo wolno obcemu pokazać wyłącznie po wycięciu okolic domu.
     * Ta metoda nie dotyka ani pliku GPX, ani surowej geometrii solo — rysuje
     * z `gpx_geometry_trimmed` (klucz `trimmed` z `Pulse::trackGroupHashes`),
     * czyli z tego samego źródła, z którego od migr. 076 publicznie rysuje się
     * mapa społeczności. „Trasa dnia" blokuje klucze `me`/`u-{slug}` właśnie
     * dlatego, że TAMTE niosą geometrię NIEPRZYCIĘTĄ; tu takiej nie ma jak
     * wziąć.
     *
     * STEMPEL W ADRESIE (`MAX(created_at)` grupy) unieważnia obrazek sam:
     * kolejny ślad wgrany tej samej doby zmienia stempel, więc karta prosi
     * o inny plik. Nic nie trzeba kasować ani wersjonować.
     */
    public static function trackGroupMap(string $key, string $rozmiar, string $stamp): void
    {
        if (!preg_match('/^sw-(\d{1,12})-(\d{8})$/', $key, $m)
            || !isset(self::SLAD_SIZES[$rozmiar])
            || !preg_match('/^\d{14}$/', $stamp)) {
            http_response_code(404);
            exit;
        }

        Session::release();

        // TA SAMA BRAMKA, KTÓRĄ MODEL POSTAWIŁ W ZAPYTANIU FEEDU (roster_visible
        // + publiczny slug, przez Support::visibleRider). Adres jest w pełni
        // przewidywalny — id konta i data — więc uprawnienie musi stać w kodzie,
        // a nie w tym, że nikt go nie zgadnie. Kto ukryje się z listy
        // rowerzystów, traci też ten obrazek, łącznie z wersją już zapisaną na
        // dysku: kolejne wejście na nią i tak nie dostanie nowej, a stara
        // zniknie razem z wpisem z feedu.
        $userId = (int) $m[1];
        if (Support::visibleRiderById($userId) === null) {
            http_response_code(404);
            exit;
        }

        $day    = substr($m[2], 0, 4) . '-' . substr($m[2], 4, 2) . '-' . substr($m[2], 6, 2);
        $hashes = Pulse::trackGroupHashes($userId, $day);
        $bounds = self::sladBounds($hashes);
        $groups = self::sladGroups($hashes);

        if ($bounds === null || $groups === []) {
            http_response_code(404);
            exit;
        }

        [$w, $h] = self::SLAD_SIZES[$rozmiar];
        $png = self::renderSladMap($groups, $bounds, $w, $h);
        self::storeSladMap($key, $rozmiar, $stamp, $png);

        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $png;
    }

    /**
     * Kadr obrazka śladu — WŁASNE progi klastrowania, nie te z `GpxGeometry`.
     *
     * Stałe tamtej klasy (1000 km progu / 150 km kubełka) pilnują mapy profilu
     * na pełnym ekranie, gdzie odsiać trzeba wyjazd na inny kontynent, a cała
     * Polska w kadrze jest w porządku. Tu kadr ma 272 px wysokości: zmierzona
     * na danych dev grupa 37 przejazdów rozciąga się na ~570 km, czyli mieści
     * się pod tamtym progiem, a na karcie wyszłaby siatką włosów na mapie
     * kraju. Patrz `SLAD_CLUSTER_*`.
     *
     * Gdy grupa niesie oba źródła naraz (solo + ślad z wyjazdu tej samej doby),
     * kadry sumują się. Rzadkie i nieszkodliwe: szerszy prostokąt to po prostu
     * mniejszy zoom, a nie błąd.
     *
     * @param array{trimmed:string[], normal:string[]} $hashes
     */
    private static function sladBounds(array $hashes): ?array
    {
        $boxes = array_values(array_filter([
            $hashes['trimmed'] === [] ? null : GpxGeometry::boundsForTrimmed(
                $hashes['trimmed'], self::SLAD_CLUSTER_TRIGGER_KM, self::SLAD_CLUSTER_BUCKET_KM
            ),
            $hashes['normal'] === [] ? null : GpxGeometry::boundsFor(
                $hashes['normal'], self::SLAD_CLUSTER_TRIGGER_KM, self::SLAD_CLUSTER_BUCKET_KM
            ),
        ]));

        if ($boxes === []) {
            return null;
        }

        return [
            'south' => min(array_column($boxes, 'south')),
            'west'  => min(array_column($boxes, 'west')),
            'north' => max(array_column($boxes, 'north')),
            'east'  => max(array_column($boxes, 'east')),
        ];
    }

    /**
     * Grupy do narysowania — kształt jak z `TileSource::tracks()`, bo trafiają
     * do tego samego `renderTrackGroups()`.
     *
     * JEDEN ŚLAD DOSTAJE STYL `real`, WIELE — `heat`. To nie jest kosmetyka:
     * pojedynczy ślad jest bohaterem obrazka, więc pełna linia. Kilkadziesiąt
     * nakładających się śladów przy pełnym kryciu robi czarną plamę —
     * dokładnie ten sam wniosek, dla którego mapa społeczności przeszła na
     * heatmapę (2026-08-28): niskie krycie, a GD samo sumuje je tam, gdzie
     * ślady leżą na sobie, więc ulubiona droga wypala się ciemniej.
     *
     * KOLOR JEST POMARAŃCZOWY W OBU PRZYPADKACH, choć styl `real` ma własną
     * zieleń. Powód jest zmierzony, nie estetyczny: pierwsza wersja tego
     * obrazka (2026-09-12) rysowała pojedynczy ślad zielenią `real` i na
     * podkładzie OSM w lesie ledwo było go widać — czyli dokładnie to, co
     * zapisano przy `STYLES['heat']` 2026-08-29 („zielona linia ginie na
     * standardowym OSM, jeszcze bardziej w lasach"). Skoro podkład jest ten
     * sam, wniosek też musi być ten sam. Nadpisanie idzie kluczem `color`,
     * czyli mechanizmem, którym już dziś każda znana trasa dostaje swój
     * własny kolor — bez dokładania ósmego stylu do wspólnej tablicy.
     *
     * @param array{trimmed:string[], normal:string[]} $hashes
     */
    private static function sladGroups(array $hashes): array
    {
        $jeden = (count($hashes['trimmed']) + count($hashes['normal'])) === 1;
        $styl  = $jeden ? 'real' : 'heat';
        $kolor = TileSource::STYLES['heat']['color'];

        $groups = [];
        if ($hashes['trimmed'] !== []) {
            $groups[] = ['hashes' => $hashes['trimmed'], 'style' => $styl, 'color' => $kolor, 'source' => 'trimmed'];
        }
        if ($hashes['normal'] !== []) {
            $groups[] = ['hashes' => $hashes['normal'], 'style' => $styl, 'color' => $kolor];
        }

        return $groups;
    }

    /** Skład obrazka śladu: kafle bazowe OSM plus ślady. Bez pól — na karcie byłyby szumem. */
    private static function renderSladMap(array $groups, array $bounds, int $w, int $h): string
    {
        [$z, $txMin, $tyMin, $txMax, $tyMax, $cropX, $cropY] = self::rotdWindow($bounds, $w, $h);

        $basemap = [];
        $tracks  = [];
        for ($tx = $txMin; $tx <= $txMax; $tx++) {
            for ($ty = $tyMin; $ty <= $tyMax; $ty++) {
                $k = "$tx,$ty";
                $basemap[$k] = OsmBasemap::tile($z, $tx, $ty);
                $tracks[$k]  = self::renderTrackGroups($groups, $z, $tx, $ty);
            }
        }

        return TileRenderer::compose(
            $basemap, [$tracks],
            $txMin, $tyMin, $txMax, $tyMax,
            $cropX, $cropY, $w, $h
        );
    }

    /**
     * Zapis obrazka śladu — bliźniak `storeRotdMap()`, z jedną różnicą: katalog
     * schodzi o poziom głębiej (`slad/{key}/{rozmiar}`), bo ta sama grupa ma
     * dwa kadry. Sprzątanie zostaje per katalog, więc dalej kasuje wyłącznie
     * POPRZEDNIE stemple tego samego klucza i rozmiaru.
     */
    private static function storeSladMap(string $key, string $rozmiar, string $stamp, string $png): void
    {
        $dir = TileCache::root() . "/slad/$key/$rozmiar";
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.png') ?: [] as $stale) {
            @unlink($stale);
        }
        $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $png) === false) {
            return;
        }
        if (!@rename($tmp, "$dir/$stamp.png")) {
            @unlink($tmp);
        }
    }

    /** @return ?array{0:array{south:float,west:float,north:float,east:float},1:list<int>} */
    private static function resolveRotdInputs(string $key): ?array
    {
        if (str_starts_with($key, 'kr-')) {
            $routeId = (int) substr($key, 3);
            $bounds = KnownRoute::boundsFor($routeId);
            return $bounds === null ? null : [$bounds, KnownRoute::cellIds($routeId)];
        }

        $eventId = (int) substr($key, 3);
        $gpxUrl = Event::firstStageGpxUrl($eventId);
        if ($gpxUrl === null) {
            return null;
        }
        $path = TileSource::absolutePath($gpxUrl);
        if (!is_file($path)) {
            return null;
        }
        $hash = GpxGeometry::ensure($path);
        $bounds = $hash !== null ? GpxGeometry::boundsFor([$hash]) : null;
        return $bounds === null ? null : [$bounds, RoutePreview::cellsForGpx($path)];
    }

    /** Rysuje i skleja "Trasę dnia" — kafle bazowe OSM plus pola trasy plus jej ślad. */
    private static function renderRotdMap(string $key, array $bounds, array $cellIds): string
    {
        [$z, $txMin, $tyMin, $txMax, $tyMax, $cropX, $cropY] = self::rotdWindow($bounds);

        $cells = array_map(static fn (int $id): array => ['cellId' => $id, 'level' => 0], $cellIds);
        $scale = [['color' => self::RMAP_FIELD_COLOR, 'alpha' => self::RMAP_FIELD_ALPHA]];
        // Krycie 0 dla WYPEŁNIENIA — nie mgła, patrz renderHexes(): tu pole ma
        // być widoczne, nie odsłaniać mapę spod niczego. `alpha` mgły na zero
        // wyłącza wyłącznie zamalowanie CAŁEGO kafla (patrz TileRenderer::
        // hexes), obrys zostaje (liczony z `alpha + 0.30`).
        $fog = ['color' => self::RMAP_FIELD_COLOR, 'outline' => self::RMAP_FIELD_COLOR, 'alpha' => 0.0];

        $basemap = [];
        $hexes = [];
        $tracks = [];
        for ($tx = $txMin; $tx <= $txMax; $tx++) {
            for ($ty = $tyMin; $ty <= $tyMax; $ty++) {
                $k = "$tx,$ty";
                $basemap[$k] = OsmBasemap::tile($z, $tx, $ty);
                $hexes[$k] = $cells ? TileRenderer::hexes($cells, $scale, $fog, $z, $tx, $ty) : null;
                $tracks[$k] = self::renderTracks($key, $z, $tx, $ty);
            }
        }

        return TileRenderer::compose(
            $basemap, [$hexes, $tracks],
            $txMin, $tyMin, $txMax, $tyMax,
            $cropX, $cropY, self::RMAP_W, self::RMAP_H
        );
    }

    /**
     * Dobiera zoom (jak najbardziej przybliżony, żeby trasa nadal mieściła
     * się w kadrze RMAP_W×RMAP_H z zapasem RMAP_PAD_FRAC) i okno kaflowania
     * wyśrodkowane na trasie — ten sam pomysł co Leaflet `fitBounds()`, tylko
     * liczony raz, po stronie serwera.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:int} [z, txMin, tyMin, txMax, tyMax, cropX, cropY]
     */
    private static function rotdWindow(array $bounds, ?int $outW = null, ?int $outH = null): array
    {
        // Rozmiar wyjściowy jest PARAMETREM od 2026-09-12 (obrazek śladu na
        // karcie Pulsu ma inny kształt niż pas „Trasy dnia"). Domyślne wartości
        // to dawne stałe, więc dla „Trasy dnia" nic się nie zmienia.
        $outW ??= self::RMAP_W;
        $outH ??= self::RMAP_H;

        $availW = $outW * (1 - 2 * self::RMAP_PAD_FRAC);
        $availH = $outH * (1 - 2 * self::RMAP_PAD_FRAC);

        [$pxW, $pyN] = TileGrid::toPixel($bounds['north'], $bounds['west']);
        [$pxE, $pyS] = TileGrid::toPixel($bounds['south'], $bounds['east']);

        $z = TileGrid::MIN_Z;
        for ($try = TileGrid::MAX_Z; $try >= TileGrid::MIN_Z; $try--) {
            $shift = TileGrid::shiftFor($try);
            $w = ($pxE >> $shift) - ($pxW >> $shift);
            $h = ($pyS >> $shift) - ($pyN >> $shift);
            $z = $try;
            if ($w <= $availW && $h <= $availH) {
                break;
            }
        }

        $shift = TileGrid::shiftFor($z);
        $centerX = intdiv(($pxW >> $shift) + ($pxE >> $shift), 2);
        $centerY = intdiv(($pyN >> $shift) + ($pyS >> $shift), 2);

        $worldSize = TileGrid::TILE << $z;
        $cropLeft = max(0, min($centerX - intdiv($outW, 2), $worldSize - $outW));
        $cropTop  = max(0, min($centerY - intdiv($outH, 2), $worldSize - $outH));

        $txMin = intdiv($cropLeft, TileGrid::TILE);
        $txMax = intdiv($cropLeft + $outW - 1, TileGrid::TILE);
        $tyMin = intdiv($cropTop, TileGrid::TILE);
        $tyMax = intdiv($cropTop + $outH - 1, TileGrid::TILE);

        return [
            $z, $txMin, $tyMin, $txMax, $tyMax,
            $cropLeft - $txMin * TileGrid::TILE,
            $cropTop - $tyMin * TileGrid::TILE,
        ];
    }

    /**
     * Zapis na dysk pod adresem, którym karta się posłuży następnym razem —
     * ten sam wzorzec zapisu atomowego co Models\TileCache::store(), ale bez
     * wpisu w `tile_cache` (ta tabela jest kluczowana z/x/y, a to jeden plik,
     * nie piramida). Sprząta pliki POPRZEDNICH dni tego klucza — dochodzimy
     * tu tylko wtedy, gdy dzisiejszego pliku jeszcze nie ma (inaczej
     * odpowiedziałby Apache regułą `!-f`), więc wszystko, co tu leży, jest
     * z wcześniejszych dni.
     */
    private static function storeRotdMap(string $key, string $date, string $png): void
    {
        $dir = TileCache::root() . "/rotd/$key";
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.png') ?: [] as $stale) {
            @unlink($stale);
        }
        $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $png) === false) {
            return;
        }
        if (!@rename($tmp, "$dir/$date.png")) {
            @unlink($tmp);
        }
    }

    private static function renderTracks(string $key, int $z, int $x, int $y): string
    {
        return self::renderTrackGroups(TileSource::tracks($key), $z, $x, $y);
    }

    /**
     * Rysowanie śladów z GOTOWEJ listy grup — rozdzielone od `renderTracks()`
     * 2026-09-12, gdy obrazek śladu na karcie Pulsu potrzebował tego samego
     * rysowania dla grupy, której NIE opisuje żaden klucz `TileSource`.
     *
     * Klucza nie dało się tam po prostu dorobić: `TileSource` opisuje WARSTWY
     * MAPY (co komu wolno włączyć na /odkrycia), a grupa „wgrane ślady jednej
     * osoby jednej doby" warstwą nie jest i nie ma być wybierana z listy.
     * Dlatego dzieli się rysowanie, a nie rozszerza słownik kluczy.
     *
     * @param array<int,array{hashes:string[],style:string,color?:string,source?:string}> $source
     */
    private static function renderTrackGroups(array $source, int $z, int $x, int $y): string
    {
        // ODSIEW I ODCZYT GEOMETRII RAZ DLA CAŁEGO KAFLA, nie raz na grupę.
        // Odkąd każda znana trasa jest osobną grupą (własny kolor, migr. 064),
        // grup bywa tyle, ile tras w katalogu — a pytanie „które ślady dotykają
        // tego kafla" jest dla wszystkich to samo. Per grupa byłoby to 200
        // zapytań na kafel pustkowia zamiast jednego.
        //
        // ZBIERANE PO ŹRÓDLE, NIE W JEDEN WOREK (migr. 076, 2026-08-28) —
        // solo w heatmapie społeczności niesie klucz `source: 'trimmed'`
        // (patrz TileSource::tracks, klucz `all`) i czyta z DRUGIEJ pary
        // tabel (`gpx_geometry_trimmed`/`gpx_tiles_trimmed`), gdzie okolice
        // domu są już wycięte. Nadal JEDNO zapytanie na źródło dla całego
        // kafla — źródeł są dziś dwa, nie tyle, ile grup.
        $hashesBySource = [];
        foreach ($source as $group) {
            $src = $group['source'] ?? 'normal';
            foreach ($group['hashes'] as $hash) {
                $hashesBySource[$src][$hash] = true;
            }
        }
        $geomBySource = [];
        foreach ($hashesBySource as $src => $hashes) {
            $hashes = array_keys($hashes);
            $inTile = $src === 'trimmed'
                ? GpxGeometry::inTileTrimmed($hashes, $z, $x, $y)
                : GpxGeometry::inTile($hashes, $z, $x, $y);
            $geomBySource[$src] = $inTile === []
                ? []
                : ($src === 'trimmed' ? GpxGeometry::loadTrimmed($inTile) : GpxGeometry::load($inTile));
        }

        $groups = [];
        foreach ($source as $group) {
            $geomZrodla = $geomBySource[$group['source'] ?? 'normal'] ?? [];
            $geomGrupy = [];
            foreach ($group['hashes'] as $hash) {
                if (isset($geomZrodla[$hash])) {
                    $geomGrupy[$hash] = $geomZrodla[$hash];
                }
            }
            if (!$geomGrupy) {
                continue;
            }
            $style = TileSource::STYLES[$group['style']];
            $groups[] = [
                'geom'   => $geomGrupy,
                // Kolor grupy ma pierwszeństwo przed kolorem stylu — tak znana
                // trasa dostaje swój własny, a reszta warstw zostaje przy swoim.
                'color'  => $group['color'] ?? $style['color'],
                'weight' => $style['weight'],
                'alpha'  => $style['alpha'],
                'dash'   => $style['dash'],
                // Obwódka NALEŻY DO STYLU, nigdy do grupy — patrz nota przy
                // TileSource::STYLES['route']. `?? null` dla stylów bez tego
                // klucza (`real`, `planned`).
                'casing'      => $style['casing'] ?? null,
                'casingWidth' => $style['casingWidth'] ?? null,
            ];
        }

        // Pusty kafel też zapisujemy — i to jest ważniejsze, niż wygląda.
        // Mapa ma o rząd wielkości więcej pustych kafli niż zajętych, a bez
        // zapisu KAŻDE spojrzenie na pustą okolicę budziłoby PHP-a i odpytywało
        // bazę. Przezroczysty PNG waży 355 B.
        return $groups ? TileRenderer::tracks($groups, $z, $x, $y) : TileRenderer::blank();
    }

    private static function renderHexes(string $key, int $z, int $x, int $y): string
    {
        $data = TileSource::hexCells($key, $z, $x, $y, count(self::SCALE));

        // MGŁA TYLKO NA MAPACH, KTÓRE O NIEJ MÓWIĄ. Warstwa pól na profilu
        // i na /odkrycia niesie komunikat „ile świata jeszcze przed Tobą",
        // więc nieodkryty teren musi być zamglony. Kafel wydarzenia czy trasy
        // pokazuje pola przy okazji i mgła zasłaniałaby tam mapę bez powodu.
        $fog = in_array($key, ['all', 'me'], true) || str_starts_with($key, 'u-')
            ? self::FOG
            : null;

        return TileRenderer::hexes($data['cells'], self::SCALE, $fog, $z, $x, $y);
    }
}
