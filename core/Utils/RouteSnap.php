<?php
// core/Utils/RouteSnap.php
namespace Utils;

// ROUTE PLANNER — matematyka „gdzie trasa biegnie wzdłuż linii Ridemore".
//
// Czysta geometria, zero zapytań do bazy/sieci — łatwe do przetestowania
// samodzielnie (patrz tests/planner_test.php). Po stronie kontrolera
// (PlannerController::calculate) zostaje tylko zdobycie danych: trasa OSM
// odcinka, linie z zaznaczonych źródeł, baza.
//
// PIKSELE, NIE STOPNIE (2026-09-18, „Źródła trasy"). Dopasowanie liczy się
// w pikselach świata TileGrid::STORE_Z (liczby całkowite, ~0,4 m/px w Polsce)
// — w tej samej przestrzeni, w której GpxGeometry trzyma ślady, więc linie
// z bazy nie wymagają konwersji, a najbliższy odcinek linii szuka się
// w siatce komórek, zamiast porównywać każdy punkt z każdym. Poprzednia wersja
// (`findSuggestion`, haversine O(n·m) na kandydata) robiła z jednego
// przeliczenia kilkanaście sekund już przy dwóch znanych trasach.
//
// Linie są w formacie GpxGeometry: płaska tablica [x, y, x, y, …]. Ślady nie
// są upraszczane (średnio co kilka metrów), ale zdarzają się luki GPS po
// ~100 m — dlatego siatka trzyma ODCINKI linii, nie same wierzchołki.
class RouteSnap
{
    private const EARTH_RADIUS_M = 6371000.0;

    // (linia, odcinek) spakowane w jedną liczbę w komórce siatki — linie mają
    // do kilkudziesięciu tysięcy punktów, więc 2^24 na odcinek wystarcza.
    private const PACK = 16777216;

    public static function haversineM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @param list<array{0:float,1:float}> $line */
    public static function lineLengthM(array $line): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($line); $i++) {
            $total += self::haversineM($line[$i - 1][0], $line[$i - 1][1], $line[$i][0], $line[$i][1]);
        }
        return $total;
    }

    /**
     * @param  list<array{0:float,1:float}> $latLngs
     * @return list<array{0:int,1:int}> piksele STORE_Z
     */
    public static function toPixels(array $latLngs): array
    {
        $out = [];
        foreach ($latLngs as $p) {
            $out[] = TileGrid::toPixel((float) $p[0], (float) $p[1]);
        }
        return $out;
    }

    /** @param list<array{0:float,1:float}> $latLngs @return list<int> płaskie [x, y, x, y, …] */
    public static function toFlat(array $latLngs): array
    {
        $flat = [];
        foreach ($latLngs as $p) {
            [$x, $y] = TileGrid::toPixel((float) $p[0], (float) $p[1]);
            $flat[] = $x;
            $flat[] = $y;
        }
        return $flat;
    }

    /**
     * Zagęszczenie łamanej: między kolejnymi punktami co najwyżej $stepPx.
     * Trasa OSRM ma na prostych punkty co kilkaset metrów, a „jak długo trasa
     * biegnie wzdłuż linii" trzeba mierzyć gęściej. Zwraca też maskę punktów
     * oryginalnych — do wyniku idą tylko one, wstawione służą wyłącznie pomiarowi.
     *
     * @param  list<array{0:int,1:int}> $px
     * @return array{0:list<array{0:int,1:int}>,1:list<bool>}
     */
    public static function densify(array $px, float $stepPx): array
    {
        $pts = [];
        $orig = [];
        $n = count($px);
        for ($i = 0; $i < $n; $i++) {
            if ($i > 0 && $stepPx > 0) {
                [$ax, $ay] = $px[$i - 1];
                [$bx, $by] = $px[$i];
                $len = hypot($bx - $ax, $by - $ay);
                for ($s = 1; $s * $stepPx < $len; $s++) {
                    $t = $s * $stepPx / $len;
                    $pts[] = [(int) round($ax + ($bx - $ax) * $t), (int) round($ay + ($by - $ay) * $t)];
                    $orig[] = false;
                }
            }
            $pts[] = $px[$i];
            $orig[] = true;
        }
        return [$pts, $orig];
    }

    /** @param list<array{0:int,1:int}> $px @return list<float> długość od początku do punktu k, w pikselach */
    public static function cumulative(array $px): array
    {
        $cum = [0.0];
        for ($i = 1, $n = count($px); $i < $n; $i++) {
            $cum[] = $cum[$i - 1] + hypot($px[$i][0] - $px[$i - 1][0], $px[$i][1] - $px[$i - 1][1]);
        }
        return $cum;
    }

    /** @param list<int> $flat @return list<float> długość linii od początku do wierzchołka v, w pikselach */
    public static function prefixPx(array $flat): array
    {
        $pre = [0.0];
        for ($v = 1, $n = intdiv(count($flat), 2); $v < $n; $v++) {
            $pre[] = $pre[$v - 1] + hypot($flat[2 * $v] - $flat[2 * $v - 2], $flat[2 * $v + 1] - $flat[2 * $v - 1]);
        }
        return $pre;
    }

    /**
     * Wspólna siatka ODCINKÓW wielu linii: komórka -> spakowane (linia, odcinek).
     * Indeksowane są tylko odcinki w prostokącie $bbox (korytarz trasy +
     * próg) — reszta długiego śladu nie ma tu nic do roboty, a indeksy
     * wierzchołków zostają oryginalne, żeby wycinek linii był ciągły. Długi
     * odcinek (luka GPS) trafia do każdej komórki, przez którą przechodzi.
     *
     * @param list<list<int>>          $flats linie w formacie GpxGeometry
     * @param array{0:int,1:int,2:int,3:int} $bbox  [minX, minY, maxX, maxY]
     * @return array<int,list<int>>
     */
    public static function gridIndex(array $flats, int $cellPx, array $bbox): array
    {
        [$minX, $minY, $maxX, $maxY] = $bbox;
        $grid = [];
        foreach ($flats as $j => $flat) {
            $n = intdiv(count($flat), 2);
            for ($v = 0; $v + 1 < $n; $v++) {
                $ax = $flat[2 * $v];
                $ay = $flat[2 * $v + 1];
                $bx = $flat[2 * $v + 2];
                $by = $flat[2 * $v + 3];
                if (max($ax, $bx) < $minX || min($ax, $bx) > $maxX || max($ay, $by) < $minY || min($ay, $by) > $maxY) {
                    continue;
                }
                $packed = $j * self::PACK + $v;
                $steps = max(1, (int) ceil(hypot($bx - $ax, $by - $ay) / ($cellPx / 2)));
                $seen = [];
                for ($s = 0; $s <= $steps; $s++) {
                    $key = (intdiv((int) ($ax + ($bx - $ax) * $s / $steps), $cellPx) << 32)
                        | intdiv((int) ($ay + ($by - $ay) * $s / $steps), $cellPx);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $grid[$key][] = $packed;
                    }
                }
            }
        }
        return $grid;
    }

    /**
     * Dla każdego punktu trasy i każdej linii w pobliżu: najbliższy wierzchołek
     * tej linii (koniec najbliższego jej odcinka), o ile odcinek leży
     * w promieniu $maxPx. Komórka siatki musi mieć co najmniej $maxPx, żeby
     * sąsiedztwo 3×3 było kompletne.
     *
     * CIĄGŁOŚĆ ($continuityPx): ślad tam-i-z-powrotem tą samą drogą ma przy
     * punkcie trasy DWA niemal równie bliskie przebiegi. Czysty „najbliższy"
     * przeskakiwałby między nimi co punkt i żaden kawałek nie byłby ciągły —
     * dlatego spośród kandydatów najwyżej $continuityPx dalszych od najbliższego
     * wygrywa ten o indeksie najbliższym poprzedniemu dopasowaniu tej linii.
     *
     * @param  list<array{0:int,1:int}> $routePx
     * @return array<int,array<int,int>> [linia => [k => wierzchołek]], k rosnąco
     */
    public static function nearestAlong(array $routePx, array $flats, array $grid, int $cellPx, float $maxPx, float $continuityPx = 0.0): array
    {
        $max2 = $maxPx * $maxPx;
        $out = [];
        $prev = [];
        foreach ($routePx as $k => [$x, $y]) {
            $cx = intdiv($x, $cellPx);
            $cy = intdiv($y, $cellPx);
            $cands = [];
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dy = -1; $dy <= 1; $dy++) {
                    $cell = $grid[(($cx + $dx) << 32) | ($cy + $dy)] ?? null;
                    if ($cell === null) {
                        continue;
                    }
                    foreach ($cell as $packed) {
                        $j = intdiv($packed, self::PACK);
                        $v = $packed % self::PACK;
                        $f = $flats[$j];
                        $ax = $f[2 * $v];
                        $ay = $f[2 * $v + 1];
                        $ex = $f[2 * $v + 2] - $ax;
                        $ey = $f[2 * $v + 3] - $ay;
                        $l2 = $ex * $ex + $ey * $ey;
                        $t = $l2 > 0 ? max(0.0, min(1.0, (($x - $ax) * $ex + ($y - $ay) * $ey) / $l2)) : 0.0;
                        $qx = $ax + $t * $ex - $x;
                        $qy = $ay + $t * $ey - $y;
                        $d2 = $qx * $qx + $qy * $qy;
                        if ($d2 <= $max2) {
                            $cands[$j][] = [$t < 0.5 ? $v : $v + 1, sqrt($d2)];
                        }
                    }
                }
            }
            foreach ($cands as $j => $list) {
                $best = min(array_column($list, 1));
                $pick = null;
                foreach ($list as [$v, $d]) {
                    if ($d > $best + $continuityPx) {
                        continue;
                    }
                    if ($pick === null) {
                        $pick = [$v, $d];
                        continue;
                    }
                    $better = isset($prev[$j])
                        ? abs($v - $prev[$j]) < abs($pick[0] - $prev[$j])
                        : $d < $pick[1];
                    if ($better) {
                        $pick = [$v, $d];
                    }
                }
                $out[$j][$k] = $pick[0];
                $prev[$j] = $pick[0];
            }
        }
        return $out;
    }

    /**
     * Ciągłe kawałki trasy biegnące wzdłuż JEDNEJ linii — punkty z dopasowaniem,
     * z przerwami do $gapPts punktów (skrzyżowanie, chwilowe odejście GPS).
     *
     * @param  array<int,int> $near [k => wierzchołek], k rosnąco
     * @return list<array{0:int,1:int,2:int,3:int}> [kFrom, kTo, lFrom, lTo]
     */
    public static function runs(array $near, int $gapPts): array
    {
        $out = [];
        $start = null;
        $prev = null;
        foreach ($near as $k => $v) {
            if ($start !== null && $k - $prev - 1 > $gapPts) {
                $out[] = [$start, $prev, $near[$start], $near[$prev]];
                $start = null;
            }
            if ($start === null) {
                $start = $k;
            }
            $prev = $k;
        }
        if ($start !== null) {
            $out[] = [$start, $prev, $near[$start], $near[$prev]];
        }
        return $out;
    }

    /**
     * Które kawałki trasy OSM zastąpić liniami Ridemore — trzy reguły, bez punktacji:
     *
     * 1. PRZYCIĄGANIE (zawsze, gdy źródło jest zaznaczone): kawałek trasy biegnący
     *    wzdłuż linii (w progu) zastępuje jej geometria, jeśli ta jest najwyżej
     *    `snapRatio` razy dłuższa (pętla w śladzie GPS odpada).
     * 2. DOŁĄCZANIE (`joinRatio` !== null — przełącznik „Dołączaj dłuższe odcinki
     *    Ridemore"): dwa kolejne kawałki TEJ SAMEJ linii łączy jej przebieg między
     *    nimi — trasa zjeżdża z drogi OSM i na nią wraca — jeśli ten przebieg jest
     *    najwyżej `joinRatio` razy dłuższy od zastępowanej drogi (+ `joinSlackPx`
     *    na krótkie luki). Bez pytania i bez komunikatu (decyzja usera 2026-09-18).
     * 3. PIERWSZEŃSTWO: niższa ranga (wyżej na liście źródeł) wygrywa miejsca
     *    wspólne. Przyciągnięty kawałek niższej rangi przycina się do wolnych
     *    miejsc; dołączony objazd, który koliduje, odpada w całości.
     *
     * @param list<float>                $cum   cumulative() zagęszczonej trasy
     * @param list<int>                  $ranks ranga każdej linii
     * @param list<list<int>>            $flats linie
     * @param array<int,array<int,int>>  $near  nearestAlong()
     * @param array{minRunPx:float,snapRatio:float,joinRatio:?float,joinSlackPx:float,jumpSlackPx:float,gapPts:int} $o
     *        jumpSlackPx — luz przy dzieleniu kawałka na skoku indeksu linii (pętla/zawrotka w śladzie)
     * @return list<array{kFrom:int,kTo:int,line:int,lFrom:int,lTo:int,join:bool}> rosnąco po kFrom
     */
    public static function preferredRuns(array $cum, array $ranks, array $flats, array $near, array $o): array
    {
        $cands = [];
        $prefix = [];
        foreach ($near as $j => $nj) {
            $pre = $prefix[$j] = self::prefixPx($flats[$j]);
            $lineLen = static fn(int $a, int $b): float => abs($pre[$b] - $pre[$a]);

            // Kawałek dzieli się tam, gdzie kolejne dopasowane punkty trasy
            // wskazują wierzchołki odległe PO LINII o wiele bardziej niż po
            // trasie — ślad robi w tym miejscu pętlę albo zawraca. Bez tego
            // pętla w środku odrzucała cały kawałek razem z prostymi obok.
            $raw = [];
            foreach (self::runs($nj, $o['gapPts']) as [$kf, $kt]) {
                $start = $kf;
                $prevK = $kf;
                foreach ($nj as $k => $v) {
                    if ($k <= $kf) {
                        continue;
                    }
                    if ($k > $kt) {
                        break;
                    }
                    if ($lineLen($nj[$prevK], $v) > $o['snapRatio'] * ($cum[$k] - $cum[$prevK]) + $o['jumpSlackPx']) {
                        $raw[] = [$start, $prevK, $nj[$start], $nj[$prevK]];
                        $start = $k;
                    }
                    $prevK = $k;
                }
                $raw[] = [$start, $prevK, $nj[$start], $nj[$prevK]];
            }

            foreach ($raw as [$kf, $kt, $lf, $lt]) {
                $rl = $cum[$kt] - $cum[$kf];
                if ($rl >= $o['minRunPx'] && $lineLen($lf, $lt) <= $o['snapRatio'] * $rl) {
                    $cands[] = ['kFrom' => $kf, 'kTo' => $kt, 'line' => $j, 'lFrom' => $lf, 'lTo' => $lt,
                                'join' => false, 'rank' => $ranks[$j], 'len' => $rl];
                }
            }
            if ($o['joinRatio'] === null) {
                continue;
            }
            // Kierunek łańcucha po linii z TOLERANCJĄ: kawałek wspólny bywa krótki
            // (trasa OSM dotyka linii na 80 m przy starcie), a jego własny
            // kierunek to wtedy szum GPS o wierzchołek w tę czy w tamtą — liczy
            // się, że kolejne kawałki leżą dalej wzdłuż linii, z cofnięciem
            // najwyżej o luz skoku.
            $ahead = static fn(int $from, int $to): bool => $pre[$to] >= $pre[$from] - $o['jumpSlackPx'];
            $behind = static fn(int $from, int $to): bool => $pre[$to] <= $pre[$from] + $o['jumpSlackPx'];
            for ($a = 0, $n = count($raw); $a < $n - 1; $a++) {
                [$kf, $kt, $lf, $lt] = $raw[$a];
                for ($b = $a + 1; $b < $n; $b++) {
                    [$nkf, $nkt, $nlf, $nlt] = $raw[$b];
                    $forward = $ahead($lf, $lt) && $ahead($lt, $nlf) && $ahead($nlf, $nlt) && $pre[$nlt] > $pre[$lf];
                    $backward = $behind($lf, $lt) && $behind($lt, $nlf) && $behind($nlf, $nlt) && $pre[$nlt] < $pre[$lf];
                    if (!$forward && !$backward) {
                        break;
                    }
                    $gapRoute = $cum[$nkf] - $cum[$kt];
                    if ($lineLen($lt, $nlf) > $o['joinRatio'] * $gapRoute + $o['joinSlackPx']) {
                        break;
                    }
                    $kt = $nkt;
                    $lt = $nlt;
                    $rl = $cum[$kt] - $cum[$kf];
                    // Całość też w limicie — przyciągnięte kawałki łańcucha mogą
                    // same mieć pętlę w śladzie, której próg luki nie widzi.
                    if ($lineLen($lf, $lt) > $o['joinRatio'] * $rl + $o['joinSlackPx']) {
                        break;
                    }
                    if ($rl >= $o['minRunPx']) {
                        $cands[] = ['kFrom' => $kf, 'kTo' => $kt, 'line' => $j, 'lFrom' => $lf, 'lTo' => $lt,
                                    'join' => true, 'rank' => $ranks[$j], 'len' => $rl];
                    }
                }
            }
        }

        usort($cands, static fn(array $x, array $y): int => [$x['rank'], -$x['len']] <=> [$y['rank'], -$y['len']]);

        $taken = [];
        $accepted = [];
        foreach ($cands as $c) {
            $free = self::freeParts($c['kFrom'], $c['kTo'], $taken);
            if (count($free) === 1 && $free[0] === [$c['kFrom'], $c['kTo']]) {
                $taken[] = [$c['kFrom'], $c['kTo']];
                $accepted[] = $c;
                continue;
            }
            if ($c['join']) {
                continue;
            }
            $nj = $near[$c['line']];
            foreach ($free as [$a, $b]) {
                while ($a < $b && !isset($nj[$a])) {
                    $a++;
                }
                while ($b > $a && !isset($nj[$b])) {
                    $b--;
                }
                $rl = $cum[$b] - $cum[$a];
                $pre = $prefix[$c['line']];
                if ($a >= $b || $rl < $o['minRunPx'] || abs($pre[$nj[$b]] - $pre[$nj[$a]]) > $o['snapRatio'] * $rl) {
                    continue;
                }
                $taken[] = [$a, $b];
                $accepted[] = ['lFrom' => $nj[$a], 'lTo' => $nj[$b], 'kFrom' => $a, 'kTo' => $b] + $c;
            }
        }

        usort($accepted, static fn(array $x, array $y): int => $x['kFrom'] <=> $y['kFrom']);
        return array_map(static fn(array $c): array => [
            'kFrom' => $c['kFrom'], 'kTo' => $c['kTo'], 'line' => $c['line'],
            'lFrom' => $c['lFrom'], 'lTo' => $c['lTo'], 'join' => $c['join'],
        ], $accepted);
    }

    /**
     * Przedział [$a,$b] minus zajęte przedziały (włącznie z końcami) — wolne części.
     *
     * @param  list<array{0:int,1:int}> $taken
     * @return list<array{0:int,1:int}>
     */
    private static function freeParts(int $a, int $b, array $taken): array
    {
        $parts = [[$a, $b]];
        foreach ($taken as [$ta, $tb]) {
            $next = [];
            foreach ($parts as [$pa, $pb]) {
                if ($tb < $pa || $ta > $pb) {
                    $next[] = [$pa, $pb];
                    continue;
                }
                if ($ta > $pa) {
                    $next[] = [$pa, $ta - 1];
                }
                if ($tb < $pb) {
                    $next[] = [$tb + 1, $pb];
                }
            }
            $parts = $next;
        }
        return $parts;
    }

    /**
     * Składa wynik odcinka: trasa OSM z wklejonymi wycinkami linii Ridemore.
     * Z trasy OSM idą tylko punkty oryginalne (plus brzegi wklejek) — zagęszczenie
     * służyło pomiarowi, nie ma puchnąć w odpowiedzi.
     *
     * @param  list<array{0:int,1:int}> $routePx zagęszczona trasa
     * @param  list<bool>               $orig    maska z densify()
     * @param  list<array{kFrom:int,kTo:int,line:int,lFrom:int,lTo:int}> $runs preferredRuns()
     * @param  list<list<int>>          $flats
     * @return array{coords:list<array{0:float,1:float}>,pieces:list<array{line:int,from:int,to:int}>}
     */
    public static function assemble(array $routePx, array $orig, array $runs, array $flats): array
    {
        $coords = [];
        $push = static function (int $x, int $y) use (&$coords): void {
            [$lat, $lon] = TileGrid::toLatLon($x, $y);
            $point = [round($lat, 6), round($lon, 6)];
            if (!$coords || $coords[count($coords) - 1] !== $point) {
                $coords[] = $point;
            }
        };

        $pieces = [];
        $k = 0;
        $n = count($routePx);
        foreach ($runs as $r) {
            for ($i = $k; $i <= $r['kFrom']; $i++) {
                if ($i === $k || $i === $r['kFrom'] || $orig[$i]) {
                    $push($routePx[$i][0], $routePx[$i][1]);
                }
            }
            $from = count($coords);
            $flat = $flats[$r['line']];
            $step = $r['lTo'] >= $r['lFrom'] ? 1 : -1;
            for ($v = $r['lFrom']; ; $v += $step) {
                $push($flat[2 * $v], $flat[2 * $v + 1]);
                if ($v === $r['lTo']) {
                    break;
                }
            }
            $pieces[] = ['line' => $r['line'], 'from' => max(0, $from - 1), 'to' => count($coords) - 1];
            $k = $r['kTo'];
        }
        for ($i = $k; $i < $n; $i++) {
            if ($i === $k || $i === $n - 1 || $orig[$i]) {
                $push($routePx[$i][0], $routePx[$i][1]);
            }
        }
        return ['coords' => $coords, 'pieces' => $pieces];
    }

    /**
     * Rzut kolejnych punktów trasy na BAZĘ (konkretna trasa albo GPX) z zachowaniem
     * kolejności — trasa jedzie po bazie W JEJ KIERUNKU, a punkt przypina się do
     * PIERWSZEGO przejazdu bazy obok siebie. Bez tego pętla (START = CEL) i ślad
     * tam-i-z-powrotem rzutowały się dowolnie: najbliższy wierzchołek potrafił leżeć
     * na nitce powrotnej i trasa przeskakiwała pół bazy.
     *
     * Kierunek: CEL rzutowany na NAJPÓŹNIEJSZY bliski wierzchołek, START na
     * najwcześniejszy; gdy wychodzi odwrotnie — user jedzie bazę pod prąd, więc
     * baza zostaje odwrócona. Punkty pośrednie szukane tylko w oknie
     * [poprzedni, CEL]; „bliski" = najwyżej $tolPx dalej niż najbliższy w oknie.
     *
     * @param  list<int>                $flat baza w formacie GpxGeometry
     * @param  list<array{0:int,1:int}> $ptsPx punkty trasy w kolejności (co najmniej 2)
     * @return array{flat:list<int>,indices:list<int>}
     */
    public static function orderedProjection(array $flat, array $ptsPx, float $tolPx): array
    {
        $nv = intdiv(count($flat), 2);
        $pick = static function (array $f, array $p, int $lo, int $hi, bool $latest) use ($tolPx): int {
            $d = [];
            $best = INF;
            for ($v = $lo; $v <= $hi; $v++) {
                $d[$v] = hypot($f[2 * $v] - $p[0], $f[2 * $v + 1] - $p[1]);
                $best = min($best, $d[$v]);
            }
            if ($latest) {
                for ($v = $hi; $v >= $lo; $v--) {
                    if ($d[$v] <= $best + $tolPx) {
                        return $v;
                    }
                }
            }
            for ($v = $lo; $v <= $hi; $v++) {
                if ($d[$v] <= $best + $tolPx) {
                    return $v;
                }
            }
            return $lo;
        };

        $lastPt = $ptsPx[count($ptsPx) - 1];
        $first = $pick($flat, $ptsPx[0], 0, $nv - 1, false);
        $last = $pick($flat, $lastPt, 0, $nv - 1, true);
        if ($last < $first) {
            $reversed = [];
            for ($v = $nv - 1; $v >= 0; $v--) {
                $reversed[] = $flat[2 * $v];
                $reversed[] = $flat[2 * $v + 1];
            }
            $flat = $reversed;
            $first = $pick($flat, $ptsPx[0], 0, $nv - 1, false);
            $last = max($first, $pick($flat, $lastPt, 0, $nv - 1, true));
        }

        $indices = [$first];
        for ($k = 1, $n = count($ptsPx); $k < $n - 1; $k++) {
            $indices[] = $pick($flat, $ptsPx[$k], $indices[$k - 1], max($indices[$k - 1], $last), false);
        }
        $indices[] = max($last, $indices[count($indices) - 1]);
        return ['flat' => $flat, 'indices' => $indices];
    }
}
