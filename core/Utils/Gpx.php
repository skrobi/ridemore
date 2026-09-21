<?php
// core/Utils/Gpx.php
namespace Utils;

class Gpx
{
    private const MAX_POINTS = 50000;

    // GÓRNY PRÓG DYSTANSU. Domyślny 1000 km pilnuje UPLOADU: pojedynczy przejazd
    // ani etap wydarzenia nie ma prawa być dłuższy, więc taki plik to pomyłka
    // albo zlepek śladów. Znane trasy to zupełnie inny byt — Green Velo ma
    // 1892 km i jest jednym szlakiem — dlatego ścieżki znanych tras przekazują
    // LONG_ROUTE_MAX_DISTANCE_KM zamiast poluzowania progu wszystkim naraz.
    public const MAX_DISTANCE_KM            = 1000.0;
    public const LONG_ROUTE_MAX_DISTANCE_KM = 3000.0;

    private const DUPLICATE_THRESHOLD_DEG = 0.0000001;
    private const PROFILE_SAMPLE_COUNT = 50;

    // Ile niezerowych odczytów musi mieć kanał, żeby dostać własny wykres —
    // patrz uzasadnienie przy describeMetrics().
    private const MIN_METRIC_SAMPLES = 10;

    /**
     * DODATKOWE KANAŁY Z PLIKU LICZNIKA (2026-09-11, prośba usera: „jeśli mamy
     * dostępne jakieś inne parametry z GPX, to również powinny być
     * zwizualizowane — tętno, kadencja itp.").
     *
     * GPX nie ma tych pól w standardzie — siedzą w `<extensions>` i KAŻDY
     * producent nazywa je po swojemu (Garmin `gpxtpx:hr`, Cluetrust
     * `gpxdata:hr`, Strava `power`, starsze eksporty po prostu `heartrate`).
     * Dlatego kluczem jest tu LISTA ALIASÓW, a nie jedna nazwa znacznika:
     * porównujemy nazwę lokalną węzła (bez prefiksu) z tą listą, więc plik
     * z dowolnej z tych szkół czyta się tym samym kodem.
     *
     * ZAKRES TO FILTR NA ŚMIECI, nie na wyczyn. Licznik przy starcie potrafi
     * wypluć tętno 0 albo 255 (zerwany pas), moc 65535 (przepełnienie) —
     * a jeden taki punkt rozciąga skalę wykresu tak, że reszta jest płaską
     * kreską. Wartość spoza zakresu traktujemy jak jej brak.
     *
     * `label`/`unit`/`color` jadą razem z danymi do przeglądarki, żeby wykres
     * nie musiał mieć drugiego, własnego słownika tych samych kanałów.
     */
    private const METRIC_CHANNELS = [
        'hr' => [
            'aliases' => ['hr', 'heartrate', 'heart_rate', 'bpm'],
            'label'   => 'Tętno',
            'unit'    => 'bpm',
            'min'     => 20,
            'max'     => 260,
            'color'   => '#B3382C',
        ],
        'cad' => [
            'aliases' => ['cad', 'cadence', 'bikecadence'],
            'label'   => 'Kadencja',
            'unit'    => 'rpm',
            'min'     => 0,
            'max'     => 250,
            'color'   => '#2B57C8',
        ],
        'pwr' => [
            'aliases' => ['power', 'powerinwatts', 'watts'],
            'label'   => 'Moc',
            'unit'    => 'W',
            'min'     => 0,
            'max'     => 2500,
            'color'   => '#8B4FBF',
        ],
        'tmp' => [
            'aliases' => ['atemp', 'temp', 'temperature'],
            'label'   => 'Temperatura',
            'unit'    => '°C',
            'min'     => -60,
            'max'     => 70,
            'color'   => '#C98A12',
        ],
    ];

    // Próg istotności (prominencja) w metrach — lokalne maksimum liczy się jako
    // "szczyt" tylko jeśli teren opada o co najmniej tyle po OBU stronach zanim
    // znów wzniesie się wyżej niż ten punkt. Odcina szum próbkowania GPS i
    // drobne pofałdowania terenu, zostawia realne podjazdy.
    private const PEAK_PROMINENCE_M = 40.0;

    // Kategoryzacja podjazdów wg formuły Stravy — długość_m × średni_gradient%,
    // to samo podejście (uproszczone z oficjalnych wytycznych wyścigowych) co
    // w Garmin ClimbPro. Poniżej 3% średniego nachylenia podjazd w ogóle nie
    // jest kategoryzowany (za płaski, żeby się liczył).
    private const CLIMB_MIN_GRADIENT_PCT = 3.0;
    private const CLIMB_CATEGORY_THRESHOLDS = [
        'HC' => 80000,
        '1'  => 64000,
        '2'  => 32000,
        '3'  => 16000,
        '4'  => 8000,
    ];

    // Zwraca ['distanceKm' => float, 'elevationGainM' => int, 'pointCount' => int,
    //   'elevationProfile' => [['d' => float dystans_km, 'e' => int wysokość_m,
    //     'lat' => float, 'lon' => float], ...]].
    // Rzuca \RuntimeException przy nieprawidłowym/pustym/nierealistycznym pliku.
    // $maxDistanceKm — górny próg dystansu, patrz LONG_ROUTE_MAX_DISTANCE_KM.
    public static function parse(string $filepath, float $maxDistanceKm = self::MAX_DISTANCE_KM): array
    {
        libxml_use_internal_errors(true);
        // Świadomie BEZ flagi LIBXML_NOENT — ona włącza podstawianie encji, co
        // jest wektorem XXE. PHP 8 domyślnie nie ładuje zewnętrznych encji, więc
        // pominięcie tej flagi wystarcza jako zabezpieczenie.
        $xml = simplexml_load_file($filepath, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if (!$xml) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new \RuntimeException('Nieprawidłowy plik GPX: ' . ($errors[0]->message ?? 'błąd parsowania'));
        }

        $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
        $xml->registerXPathNamespace('gpx10', 'http://www.topografix.com/GPX/1/0');

        $trackPoints = $xml->xpath('//gpx:trkpt') ?: $xml->xpath('//gpx10:trkpt') ?: [];
        if (empty($trackPoints)) {
            throw new \RuntimeException(__('Brak punktów trasy w pliku GPX'));
        }

        $rawPoints = [];
        foreach ($trackPoints as $trkpt) {
            $lat = (float) $trkpt['lat'];
            $lon = (float) $trkpt['lon'];
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }
            $rawPoints[] = [
                'lat' => $lat,
                'lon' => $lon,
                'ele' => isset($trkpt->ele) ? (float) $trkpt->ele : 0.0,
                // Znacznik czasu punktu (Etap 8A/10). Trasa ZAPLANOWANA go nie
                // ma — to jest właśnie różnica między planem a zapisem przejazdu
                // i dlatego czas jest tu dobrym wyznacznikiem „ten plik pochodzi
                // z licznika, nie z planera". Null, gdy brak.
                'time' => isset($trkpt->time) ? (string) $trkpt->time : null,
                // Tętno/kadencja/moc/temperatura, gdy plik je niesie (2026-09-11).
                // Tablica pustych kluczy dla trasy z planera — i to jest
                // normalny przypadek, nie błąd.
                'metrics' => isset($trkpt->extensions)
                    ? self::readExtensions($trkpt->extensions)
                    : [],
            ];
        }

        $points = self::removeDuplicates($rawPoints);
        if (empty($points)) {
            throw new \RuntimeException(__('Brak poprawnych punktów trasy po walidacji'));
        }
        if (count($points) > self::MAX_POINTS) {
            $points = self::simplify($points, self::MAX_POINTS);
        }

        [$distanceKm, $elevationGainM, $cumulative] = self::calculateMetrics($points);

        if ($distanceKm < 0.1) {
            throw new \RuntimeException(__('Trasa zbyt krótka (< 100 m)'));
        }
        if ($distanceKm > $maxDistanceKm) {
            throw new \RuntimeException('Trasa zbyt długa (> ' . (int) $maxDistanceKm . ' km)');
        }

        // CZAS PRZEJAZDU — z pierwszego i ostatniego punktu ze znacznikiem.
        // Świadomie NIE liczymy tu „czasu w ruchu": wymagałby progu prędkości,
        // czyli kolejnej liczby do skalibrowania, a punktacja czasu nie używa
        // (patrz core/discovery.php: żadnego tempa, żadnej prędkości). To jest
        // fakt o pliku, nie metryka wyczynowa — potrzebny, żeby odróżnić zapis
        // przejazdu od trasy narysowanej w planerze i żeby przejazd solo miał
        // kiedy się odbyć.
        $times = array_values(array_filter(array_column($points, 'time')));
        $startedAt = $times ? ($times[0] ?? null) : null;
        $endedAt = $times ? (end($times) ?: null) : null;
        $elapsed = null;
        if ($startedAt !== null && $endedAt !== null) {
            $from = strtotime($startedAt);
            $to = strtotime($endedAt);
            // Ujemna albo absurdalna różnica = zepsute znaczniki; lepiej nie
            // podać czasu, niż podać nieprawdziwy.
            if ($from && $to && $to > $from && ($to - $from) < 7 * 24 * 3600) {
                $elapsed = $to - $from;
            }
        }

        return [
            'distanceKm'       => round($distanceKm, 1),
            'elevationGainM'   => (int) round($elevationGainM),
            'pointCount'       => count($points),
            // null dla trasy bez znaczników czasu (plan, eksport z planera).
            'startedAt'        => $startedAt !== null ? date('Y-m-d H:i:s', strtotime($startedAt)) : null,
            'elapsedSeconds'   => $elapsed,
            'hasTimestamps'    => $times !== [],
            'elevationProfile' => self::sampleProfile($cumulative),
            // KTÓRE dodatkowe kanały ten plik faktycznie niesie, z etykietą,
            // jednostką i kolorem. Pusta tablica = plik bez rozszerzeń (plan
            // z planera albo licznik bez czujników) i strona wtedy nie rysuje
            // żadnego dodatkowego wykresu. Próbki tych kanałów siedzą
            // w `elevationProfile` pod tymi samymi kluczami — TA SAMA tablica
            // i TE SAME indeksy co wysokość, żeby wykresy stały na jednej
            // skali kilometrów bez żadnego dopasowywania po stronie
            // przeglądarki (prośba usera: „osobne wykresy na jednej skali km").
            'metricChannels'   => self::describeMetrics($cumulative),
            // Wyłącznie do dalszego przetwarzania po stronie serwera (patrz
            // RoadSurfaceDetector) — NIE wysyłane do przeglądarki, zbyt duże.
            'points'           => $points,
        ];
    }

    /**
     * Wyciąga znane kanały z `<extensions>` jednego punktu trasy.
     *
     * PO NAZWIE LOKALNEJ, NIE PO PRZESTRZENI NAZW — i to jest tu sedno.
     * Rejestrowanie namespace'ów (`gpxtpx` v1, `gpxtpx` v2, `gpxdata`, `gpxpx`,
     * pusty…) znaczyłoby, że plik z szóstego producenta cicho gubi dane, choć
     * znacznik nazywa się tak samo. `localName` zdejmuje prefiks, więc
     * `<gpxtpx:hr>`, `<gpxdata:hr>` i gołe `<hr>` wpadają w tę samą gałąź.
     *
     * `getElementsByTagName('*')` schodzi na DOWOLNĄ głębokość — Garmin pakuje
     * te pola w zagnieżdżony `<gpxtpx:TrackPointExtension>`, a Strava kładzie
     * `<power>` bezpośrednio pod `<extensions>`. Płaskie przejście po
     * potomkach obsługuje oba układy bez gałęzi na każdy z nich.
     *
     * @return array<string,int> klucz kanału => wartość (tylko sensowne)
     */
    private static function readExtensions(\SimpleXMLElement $extensions): array
    {
        // PŁASKA MAPA ALIAS => KLUCZ, ZBUDOWANA RAZ NA PROCES. Ta metoda biegnie
        // raz na KAŻDY punkt trasy, a plik z licznika ma ich kilkadziesiąt
        // tysięcy; pętla po czterech kanałach z `in_array` w środku znaczyła
        // ćwierć miliona porównań tablicowych na plik (zmierzone: +0,18 s do
        // parsowania 16-tysięcznego śladu). Jedno wyszukanie w tablicy
        // asocjacyjnej robi to samo za stałą cenę.
        static $poAliasie = null;
        if ($poAliasie === null) {
            $poAliasie = [];
            foreach (self::METRIC_CHANNELS as $klucz => $kanal) {
                foreach ($kanal['aliases'] as $alias) {
                    $poAliasie[$alias] = $klucz;
                }
            }
        }

        $out = [];
        $dom = dom_import_simplexml($extensions);

        foreach ($dom->getElementsByTagName('*') as $node) {
            $klucz = $poAliasie[strtolower((string) $node->localName)] ?? null;
            // Nieznany znacznik (wrapper `TrackPointExtension`, rozszerzenia
            // producenta, których nie rysujemy) odpada TU, zanim dotkniemy
            // jego treści — `textContent` na wrapperze sklejałby tekst
            // wszystkich dzieci, czyli robotę na darmo.
            if ($klucz === null || isset($out[$klucz])) {
                // `isset` wyżej znaczy „pierwsza wartość wygrywa": gdy plik
                // niesie ten sam kanał dwa razy (podwójny zestaw rozszerzeń),
                // kolejna nie nadpisuje już odczytanej.
                continue;
            }
            $tekst = trim((string) $node->textContent);
            if ($tekst === '' || !is_numeric($tekst)) {
                continue;
            }
            $wartosc = (int) round((float) $tekst);
            $kanal = self::METRIC_CHANNELS[$klucz];
            if ($wartosc >= $kanal['min'] && $wartosc <= $kanal['max']) {
                $out[$klucz] = $wartosc;
            }
        }

        return $out;
    }

    /**
     * Opis kanałów, które ten konkretny plik naprawdę niesie — pod wykresy.
     *
     * NIE WYSTARCZY „znacznik istniał": licznik bez pasa zapisuje `<hr>0`
     * w każdym punkcie, a czujnik kadencji odpięty w połowie sezonu zostawia
     * same zera. Wykres z jednej poziomej kreski na zerze jest gorszy niż brak
     * wykresu, bo wygląda na zepsuty. Kanał wchodzi więc dopiero wtedy, gdy ma
     * MINIMUM_METRIC_SAMPLES niezerowych próbek — próg jest nisko, bo to filtr
     * na „czujnika nie było", a nie na jakość zapisu.
     *
     * @return array<int,array{key:string,label:string,unit:string,color:string,avg:int,max:int}>
     */
    private static function describeMetrics(array $cumulative): array
    {
        $out = [];

        foreach (self::METRIC_CHANNELS as $klucz => $kanal) {
            $wartosci = [];
            foreach ($cumulative as $p) {
                if (isset($p['metrics'][$klucz])) {
                    $wartosci[] = $p['metrics'][$klucz];
                }
            }
            // Temperatura jest jedynym kanałem, dla którego ZERO jest zwykłą
            // wartością (mróz), więc jej nie odsiewamy po wartości — tylko po
            // liczbie próbek.
            $niezerowe = $klucz === 'tmp'
                ? $wartosci
                : array_values(array_filter($wartosci, static fn(int $v): bool => $v > 0));
            if (count($niezerowe) < self::MIN_METRIC_SAMPLES) {
                continue;
            }

            $out[] = [
                'key'   => $klucz,
                'label' => __($kanal['label']),
                'unit'  => $kanal['unit'],
                'color' => $kanal['color'],
                // Średnia i maksimum liczone ze WSZYSTKICH punktów pliku, nie
                // z 50 próbek wykresu — próbkowanie jest po to, żeby wykres był
                // lekki, a nie żeby przekłamywać liczby pod nim.
                'avg'   => (int) round(array_sum($niezerowe) / count($niezerowe)),
                'max'   => max($niezerowe),
            ];
        }

        return $out;
    }

    // Przerzedza [{d_km, ele, lat, lon}, ...] (jeden wpis na oryginalny punkt)
    // do stałej liczby próbek pod lekki wykres SVG na froncie — bierzemy
    // punkty w równych odstępach indeksu (trasa ma z grubsza stałe tempo
    // próbkowania GPS), zawsze z pierwszym i ostatnim punktem. lat/lon jadą
    // razem z dystansem/wysokością, żeby front mógł zsynchronizować punkt
    // najechany na profilu z pinezką na mapie (patrz ridemoreLinkElevationProfile
    // w assets/js/gpx-map.js).
    private static function sampleProfile(array $cumulative): array
    {
        $n = count($cumulative);
        if ($n <= self::PROFILE_SAMPLE_COUNT) {
            return array_map(fn($p) => self::formatProfilePoint($p), $cumulative);
        }
        $step = ($n - 1) / (self::PROFILE_SAMPLE_COUNT - 1);
        $sampled = [];
        for ($i = 0; $i < self::PROFILE_SAMPLE_COUNT; $i++) {
            $idx = (int) round($i * $step);
            $sampled[] = self::formatProfilePoint($cumulative[$idx]);
        }
        return $sampled;
    }

    private static function formatProfilePoint(array $p): array
    {
        // Kanały dokładane PŁASKO (`hr`, `cad`, …), obok `e` — nie w podtablicy.
        // Wykres i tak czyta je po kluczu, a płaska próbka to o jeden poziom
        // zagnieżdżenia mniej w JSON-ie, który leci do przeglądarki 50 razy.
        // Brakujący odczyt po prostu nie ma klucza: `null` w każdej próbce
        // pliku bez czujników byłby czystym balastem.
        return [
            'd'   => round($p['d'], 2),
            'e'   => (int) round($p['ele']),
            'lat' => round($p['lat'], 6),
            'lon' => round($p['lon'], 6),
        ] + ($p['metrics'] ?? []);
    }

    // Lokalne maksima profilu z wystarczającą prominencją (patrz
    // PEAK_PROMINENCE_M), każde wzbogacone o opis prowadzącego do niego
    // podjazdu (długość/gradient/kategoria — patrz describeClimb()) —
    // w kolejności wzdłuż trasy. Działa też na starych profilach sprzed
    // dodania lat/lon (liczy tylko na 'd'/'e'), więc nie wymaga backfillu
    // żeby zadziałać.
    public static function detectPeaks(array $profile, float $prominenceM = self::PEAK_PROMINENCE_M): array
    {
        $n = count($profile);
        if ($n < 3) {
            return [];
        }

        $peaks = [];
        for ($i = 1; $i < $n - 1; $i++) {
            $e = $profile[$i]['e'];
            if (!($e > $profile[$i - 1]['e'] && $e > $profile[$i + 1]['e'])) {
                continue;
            }

            $leftBase = $e;
            for ($j = $i - 1; $j >= 0; $j--) {
                $leftBase = min($leftBase, $profile[$j]['e']);
                if ($profile[$j]['e'] >= $e) break;
            }
            $rightBase = $e;
            for ($j = $i + 1; $j < $n; $j++) {
                $rightBase = min($rightBase, $profile[$j]['e']);
                if ($profile[$j]['e'] >= $e) break;
            }

            if ($e - max($leftBase, $rightBase) >= $prominenceM) {
                $peaks[] = self::describeClimb($profile, $i);
            }
        }
        return $peaks;
    }

    // Opisuje podjazd prowadzący do szczytu pod $peakIdx: cofa się wzdłuż
    // profilu dopóki wysokość nieprzerwanie rośnie (w stronę szczytu), żeby
    // znaleźć "dolinę" gdzie podjazd faktycznie się zaczyna, potem liczy z
    // niej długość/średni gradient/kategorię wg formuły Stravy. Uproszczenie
    // względem realnych narzędzi (Garmin/Strava liczą z pełnej rozdzielczości
    // GPS, u nas tylko ~50 próbek na cały etap) — krótkie/ostre podjazdy mogą
    // się nie złapać, ale wystarcza do orientacyjnego podziału.
    private static function describeClimb(array $profile, int $peakIdx): array
    {
        $peak = $profile[$peakIdx];
        $startIdx = $peakIdx;
        while ($startIdx > 0 && $profile[$startIdx - 1]['e'] < $profile[$startIdx]['e']) {
            $startIdx--;
        }
        $start = $profile[$startIdx];

        $lengthKm = $peak['d'] - $start['d'];
        $gainM = $peak['e'] - $start['e'];
        $gradientPct = $lengthKm > 0 ? $gainM / ($lengthKm * 1000) * 100 : 0.0;

        $category = null;
        if ($lengthKm > 0 && $gradientPct >= self::CLIMB_MIN_GRADIENT_PCT) {
            $score = ($lengthKm * 1000) * $gradientPct;
            foreach (self::CLIMB_CATEGORY_THRESHOLDS as $cat => $threshold) {
                if ($score >= $threshold) {
                    $category = $cat;
                    break;
                }
            }
        }

        return [
            'd'             => $peak['d'],
            'e'             => $peak['e'],
            'climbStartD'   => $start['d'],
            'climbLengthKm' => round($lengthKm, 2),
            'gradientPct'   => round($gradientPct, 1),
            'category'      => $category,
        ];
    }

    // Ścieżka SVG <path d="..."> z profilu elewacji (ten sam kształt co
    // EventStage::$elevationProfile, [['d'=>km,'e'=>m,...], ...]) — pod
    // statyczny wykres "Trasa dnia" na stronie głównej (landing), renderowany
    // po stronie serwera z realnych danych, bez JS/biblioteki wykresów.
    // $padTopPct — margines nad najwyższym punktem (ułamek $viewH), żeby
    // szczyt trasy nie dotykał górnej krawędzi.
    public static function toSvgPath(array $profile, float $viewW, float $viewH, float $padTopPct = 0.12): string
    {
        $n = count($profile);
        if ($n < 2) {
            return '';
        }
        $maxD = max(array_column($profile, 'd')) ?: 1.0;
        $elevations = array_column($profile, 'e');
        $minE = min($elevations);
        $maxE = max($elevations);
        $rangeE = ($maxE - $minE) ?: 1.0;
        $usableH = $viewH * (1 - $padTopPct);

        $commands = [];
        foreach ($profile as $i => $p) {
            $x = round(($p['d'] / $maxD) * $viewW, 1);
            $y = round($viewH - (($p['e'] - $minE) / $rangeE) * $usableH, 1);
            $commands[] = ($i === 0 ? 'M ' : 'L ') . $x . ' ' . $y;
        }
        return implode(' ', $commands);
    }

    // Jak toSvgPath(), zamknięta u dołu do $viewH — pod wypełnienie pod linią
    // profilu (gradient/tło wykresu).
    public static function toSvgAreaPath(array $profile, float $viewW, float $viewH, float $padTopPct = 0.12): string
    {
        $line = self::toSvgPath($profile, $viewW, $viewH, $padTopPct);
        if ($line === '') {
            return '';
        }
        return $line . " L $viewW $viewH L 0 $viewH Z";
    }

    // Usuwa punkty praktycznie identyczne z poprzednim zachowanym (szum GPS)
    // — inaczej sztucznie zawyżają dystans.
    private static function removeDuplicates(array $points): array
    {
        if (empty($points)) {
            return [];
        }
        $cleaned = [$points[0]];
        foreach ($points as $i => $p) {
            if ($i === 0) continue;
            $last = end($cleaned);
            if (abs($p['lat'] - $last['lat']) < self::DUPLICATE_THRESHOLD_DEG
                && abs($p['lon'] - $last['lon']) < self::DUPLICATE_THRESHOLD_DEG) {
                continue;
            }
            $cleaned[] = $p;
        }
        return $cleaned;
    }

    // public — reużywana przez RoadSurfaceDetector do przycięcia liczby
    // punktów wysyłanych do Overpass API (ten sam prosty, równomierny krok
    // wystarcza tam tak samo jak tutaj).
    public static function simplify(array $points, int $maxPoints): array
    {
        $step = (int) ceil(count($points) / $maxPoints);
        $simplified = [];
        for ($i = 0; $i < count($points); $i += $step) {
            $simplified[] = $points[$i];
        }
        $simplified[] = $points[count($points) - 1];
        return $simplified;
    }

    /**
     * GPX 1.1 Z LISTY PUNKTÓW — jedyny writer w tym serwisie.
     *
     * Mieszkał w `Utils\Fit` (konwersja pliku z licznika), a od 2026-09-03 ma
     * drugiego czytelnika: pobieranie CUDZEGO przejazdu solo ze strony
     * `/przejazd/{id}` składa plik z przyciętej geometrii, bo oryginału obcemu
     * wydać nie wolno (§27). Dwa miejsca budujące ten sam XML rozjechałyby się
     * przy pierwszej poprawce, więc jest jedno.
     *
     * NIE MYLIĆ Z NIEISTNIEJĄCYM `saveTrimmed()` niżej: tamto NADPISYWAŁO plik
     * na dysku i dlatego zostało zdjęte. To buduje string i niczego nie zapisuje.
     *
     * Ręcznie, nie przez DOMDocument: dokument jest płaski i przewidywalny,
     * a przy kilku tysiącach punktów budowanie drzewa obiektów kosztuje
     * pamięć, której nie ma po co wydawać.
     *
     * @param list<array{lat:float,lon:float,ele?:?float,ts?:int}> $punkty
     */
    public static function fromPoints(array $punkty, ?string $trackName, string $creator = 'ridemore.bike'): string
    {
        $nazwa = htmlspecialchars($trackName ?: 'Przejazd', ENT_XML1);
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "
"
            . '<gpx version="1.1" creator="' . htmlspecialchars($creator, ENT_XML1)
            . '" xmlns="http://www.topografix.com/GPX/1/1">' . "
"
            . '  <trk><name>' . $nazwa . '</name><trkseg>' . "
";

        foreach ($punkty as $p) {
            // 7 miejsc po przecinku to ok. 1 cm — więcej niż potrafi każdy GPS,
            // a mniej niż domyślne formatowanie float, które potrafi wypisać
            // 46.202470000000003 i puchnąć plik bez żadnej treści.
            $out .= '    <trkpt lat="' . number_format((float) $p['lat'], 7, '.', '')
                . '" lon="' . number_format((float) $p['lon'], 7, '.', '') . '">';
            if (($p['ele'] ?? null) !== null) {
                $out .= '<ele>' . number_format((float) $p['ele'], 1, '.', '') . '</ele>';
            }
            if ((int) ($p['ts'] ?? 0) > 0) {
                // Czas jest tu TREŚCIĄ, nie ozdobą: z niego bierze się data
                // przejazdu solo (RiderActivity::recordSolo), bo ślad bez
                // wydarzenia nie ma skąd jej wziąć.
                $out .= '<time>' . gmdate('Y-m-d\TH:i:s\Z', (int) $p['ts']) . '</time>';
            }
            $out .= '</trkpt>' . "
";
        }

        return $out . '  </trkseg></trk>' . "
" . '</gpx>' . "
";
    }

    // NIE MA TU `saveTrimmed()` I NIE DOPISUJ GO Z POWROTEM (2026-08-26).
    // Przez jeden dzień istniała metoda nadpisująca plik GPX przyciętymi
    // punktami (§27, okolice domu), wołana z RiderActivity::recordSolo.
    // Zdjęta, bo plik jest ŹRÓDŁEM DYSTANSU: EditionTrack::distanceFromFile
    // czyta go ponownie przy powiązaniu przejazdu solo z turnusem, więc
    // przycięty oryginał po cichu zabierał kilometry dojazdu z domu.
    // Prywatność załatwiamy tam, gdzie plik WYCHODZI na zewnątrz
    // (TileSource::tracks — klucz `all` bez solo; Support::trackUrlsForFeed —
    // adres pliku solo tylko dla właściciela), a nie przez psucie danych.

    // Dystans (Haversine) + suma podejść (schemat trzyma tylko elevation_gain_m,
    // bez descent — spadki pomijamy, próg 0.5 m odcina szum barometru/GPS).
    // Zwraca też skumulowany [dystans, wysokość] w każdym punkcie — surowe dane
    // pod sampleProfile().
    private static function calculateMetrics(array $points): array
    {
        $distance = 0.0;
        $ascent   = 0.0;
        // `metrics` jedzie w tej samej tablicy co dystans i wysokość — dzięki
        // temu próbkowanie (`sampleProfile`) wybiera JEDEN raz indeksy, a każdy
        // kanał dostaje wartości dokładnie z tych samych punktów. To jest cała
        // mechanika „wykresów na jednej skali km": nie ma czego synchronizować,
        // bo nigdy się nie rozjechały.
        $cumulative = [['d' => 0.0, 'ele' => $points[0]['ele'], 'lat' => $points[0]['lat'],
                        'lon' => $points[0]['lon'], 'metrics' => $points[0]['metrics'] ?? []]];
        for ($i = 1; $i < count($points); $i++) {
            $prev = $points[$i - 1];
            $curr = $points[$i];
            $distance += self::haversineKm($prev['lat'], $prev['lon'], $curr['lat'], $curr['lon']);
            $diff = $curr['ele'] - $prev['ele'];
            if ($diff > 0.5) {
                $ascent += $diff;
            }
            $cumulative[] = ['d' => $distance, 'ele' => $curr['ele'], 'lat' => $curr['lat'],
                             'lon' => $curr['lon'], 'metrics' => $curr['metrics'] ?? []];
        }
        return [$distance, $ascent, $cumulative];
    }

    // Ten sam wzór co Event::upcoming() (sortowanie "blisko mnie") — tu w PHP,
    // bo liczymy z pliku przed zapisem, nie w zapytaniu SQL. Publiczna — reużywana
    // też przez Utils\RoadSurfaceDetector (wcześniej miał własną, identyczną kopię).
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }
}
