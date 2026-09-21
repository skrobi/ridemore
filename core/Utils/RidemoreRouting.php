<?php
// core/Utils/RidemoreRouting.php
namespace Utils;

// WARSTWA ROUTINGU RIDEMORE (2026-09-18, plan zaakceptowany przez usera).
// OSRM mówi, KTÓRĘDY MOŻNA; dane Ridemore mówią, KTÓRĘDY WARTO; ta klasa
// wybiera kompromis dla jednego odcinka planera (para kolejnych punktów):
//
//   trasa bazowa OSRM → korytarze Ridemore w elipsie START–CEL → wejście
//   i wyjście na każdym → warianty (1 albo 2 korytarze) → JEDNA macierz
//   odległości OSRM → koszt całych wariantów → zwycięzca → geometria
//
// Czysta logika: zero bazy i sieci. Linie z metadanymi daje
// Models\RidemoreCorridors, OSRM przychodzi jako dwie funkcje ($table,
// $legs) — w testach podstawione, w kontrolerze Utils\RoutingProxy.
//
// ZASADY (od usera, nie do negocjacji w kodzie):
//  • popularność daje BONUS, nigdy kary — droga bez danych Ridemore jest
//    neutralna, a trasa bazowa OSRM zawsze jest wariantem;
//  • twardy, płynny budżet wydłużenia względem trasy bazowej:
//    kilka kilometrów na lokalny zjazd i powrót, potem proporcjonalnie;
//  • OSM rozstrzyga, czy tędy da się jechać: korytarz, którego rowerowy OSRM
//    nie umie przejechać od wejścia do wyjścia (legalRatio), odpada;
//  • długi, spójny korytarz jest wart więcej niż krótkie kawałki
//    (fullContinuityM) — 100 m popularności nie uzasadnia objazdu.
final class RidemoreRouting
{
    /** Wszystkie parametry warstwy — JEDYNE miejsce do strojenia. */
    public const PARAMS = [
        // Sondy wsparcia: co ile metrów i w jakim promieniu ślad „jedzie tą samą drogą".
        'probeM'             => 100.0,
        'supportRadiusM'     => 30.0,
        // Korytarz: ciągły fragment linii z RidemoreScore ≥ minScore.
        'minCorridorM'       => 1000.0,
        'maxGapM'            => 250.0,   // odstęp między „dobrymi" sondami, który jeszcze nie tnie korytarza
        'maxVertexGapM'      => 300.0,   // dziura w NAGRANIU GPS dłuższa niż to przerywa linię (znanych tras nie dotyczy)
        'minScore'           => 0.3,
        // RidemoreScore (0..1).
        'minCommunityRiders' => 2,       // jedna osoba to jeszcze nie „sprawdzony odcinek"
        'passCapPerRider'    => 3,       // powtórzenia jednej osoby liczą się najwyżej 3 razy
        'riderRefMin'        => 3.0,     // dolna granica lokalnej skali osób
        'passRefMin'         => 5.0,     // dolna granica lokalnej skali przejazdów
        'weightRiders'       => 0.6,
        'weightPasses'       => 0.4,
        'knownFloor'         => 0.8,     // znana trasa: kuratorowana = sprawdzona
        'mineFloor'          => 0.6,     // mój przejazd: własny wybór drogi
        'mineStep'           => 0.1,     // + za każdy kolejny mój przejazd, do 1,0
        // Świeżość (Etap 2a): MNOŻNIK dla przejazdów (społeczność, moje), nie
        // składnik — stara popularna droga nie przegra przez to z jednym
        // świeżym przejazdem (jedna osoba i tak nie tworzy korytarza).
        // Ostatni przejazd do 2 lat temu → 1,0; liniowo do 0,5 przy 6+ latach;
        // brak daty → 1,0 (brak wiedzy to nie kara).
        'recentDays'         => 730,
        'staleDays'          => 2190,
        'staleFactor'        => 0.5,
        // Profil roweru (Etap 2b, od Etapu 3 z konfiguracji typu roweru w panelu —
        // Models\BikeType::plannerRules; tu tylko zasady, gdy typu nie podano).
        //  • minAsphaltPct — znana trasa z mniejszym % asfaltu to dla tego
        //    profilu droga NIEZGODNA: w jej pobliżu korytarza nie ma wcale
        //    (popularność nie nadpisuje profilu);
        //  • legalRatio — ile dłużej może jechać silnik od wejścia do wyjścia,
        //    zanim uznamy, że korytarzem się nie przejedzie;
        //  • trailBonus — dodatek do score przy znanej trasie w ≥ trailPct% terenowej;
        //  • countsRidesOf — przejazdy jakich typów rowerów są „dowodem" (pusta =
        //    wszystkie; przejazd bez typu liczy się zawsze).
        'defaultProfile'     => ['code' => '', 'legalRatio' => 1.3, 'minAsphaltPct' => null, 'trailBonus' => 0.0, 'countsRidesOf' => []],
        'trailPct'           => 50,
        // Koszt wariantu = długość − Σ bonus korytarzy.
        'bonusPerM'          => 0.2,     // metr korytarza o score 1,0 „kasuje" 0,2 m trasy
        'fullContinuityM'    => 3000.0,  // od tej długości korytarz liczy się w pełni
        'minGainM'           => 100.0,   // korytarz musi wygrać o tyle, żeby zmienić trasę
        // Budżet zasobu (epsilon-constraint): co najmniej 4 km pozwala na
        // lokalny zjazd i powrót, 12% skaluje go dla długiej trasy, 8 km
        // chroni przed niekontrolowanym objazdem. Brak skoków na progach.
        'detourRatio'        => 0.12,
        'minExtraM'          => 4000.0,
        'maxExtraM'          => 8000.0,
        // Histereza wyboru: przy kolejnym odcinku utrzymanie tego samego
        // korytarza dostaje premię przejścia. Nowa droga nadal musi wygrać
        // normalnym kosztem, ale planer nie porzuca trasy przez lokalny szum.
        'continuityBonusM'   => 3000.0,
        // Połączenia.
        'detourFactor'       => 1.25,    // szacunek drogi z linii prostej (przed macierzą OSRM)
        'directionMin'       => 0.6,     // korytarz musi zbliżać do celu o ≥ 60% swojej długości
        // (próg przejezdności wejście→wyjście: `legalRatio` w profilu wyżej)
        // Budżet obliczeń.
        'maxPieces'          => 10,
        'maxProbesPerPiece'  => 250,
        'entryEvery'         => 2,       // kandydaci na wejście/wyjście co tyle sond
        'maxCorridors'       => 5,
        'maxPairs'           => 3,
        'dedupeShare'        => 0.7,     // kawałek w 70% na już wybranym = ta sama droga
        'dedupeSample'       => 20,
    ];

    /**
     * Wybór wariantu dla jednego odcinka.
     *
     * @param array{lat:float,lng:float} $from
     * @param array{lat:float,lng:float} $to
     * @param array{coords:list<array{0:float,1:float}>,distanceM:float,durationS:float} $baseline trasa OSRM
     * @param list<array> $lines z Models\RidemoreCorridors::lines(..., withOwners: true)
     * @param array{riders:float,passes:float} $ref lokalna skala (RidemoreCorridors::localReference)
     * @param array{mine:bool,known:bool,community:bool} $sources
     * @param ?string $self klucz właściciela pytającego (`u{id}`) — dla „Moich przejazdów"
     * @param callable $table fn(list $points, list $sources, list $destinations): ?array (RoutingProxy::table)
     * @param callable $legs  fn(list $waypoints): ?array (RoutingProxy::routeLegs)
     * @param array{profile?:array,today?:string,preferredKeys?:list<string>} $opts zasady profilu roweru
     *        (Models\BikeType::plannerRules; brak = PARAMS['defaultProfile']) i „dziś"
     *        dla świeżości (Y-m-d; w testach stałe)
     * @return array{segment:?array,variant:array,continuityKeys?:list<string>,degraded?:bool} segment null = zostaje trasa
     *         bazowa; degraded = zabrakło odpowiedzi OSRM (wyniku nie wolno zapamiętać)
     */
    public static function route(
        array $from,
        array $to,
        array $baseline,
        array $lines,
        array $ref,
        array $sources,
        ?string $self,
        callable $table,
        callable $legs,
        float $speedMps,
        array $opts = []
    ): array {
        $p = self::PARAMS;
        $baseM = (float) $baseline['distanceM'];
        $preferredKeys = array_fill_keys(array_map('strval', $opts['preferredKeys'] ?? []), true);
        $variant = [
            'chosen' => 'osrm', 'baselineM' => (int) round($baseM), 'chosenM' => (int) round($baseM),
            'extraM' => 0, 'corridors' => [], 'considered' => 0,
        ];
        $area = self::area($from, $to, $baseM);
        if ($area === null || !$lines || count($baseline['coords']) < 2) {
            return ['segment' => null, 'variant' => $variant];
        }

        $ctx = self::context($lines, $area, $ref, $sources, $self, $opts);

        // Trasa bazowa też dostaje bonus, gdy sama biegnie sprawdzonymi
        // odcinkami — wtedy nie ma po co jej zmieniać (przypadek 1).
        $baseTrack = self::track(RouteSnap::toPixels($baseline['coords']), $ctx, PHP_INT_MAX);
        $baseBonus = 0.0;
        foreach (self::runs($baseTrack['scores'], $baseTrack['cumM'], 0.0, $baseTrack['stepM']) as [$a, $b]) {
            $baseBonus += self::bonusM($baseTrack['cumM'][$b] - $baseTrack['cumM'][$a], self::mean($baseTrack['scores'], $a, $b));
        }
        $baseCost = $baseM - $baseBonus;

        $candidates = self::candidates($area, $ctx);
        if (!$candidates) {
            return ['segment' => null, 'variant' => $variant];
        }
        $variants = self::variants($candidates, $area);
        $variant['considered'] = count($variants) + 1;

        // JEDNA macierz OSRM wycenia wszystkie połączenia wszystkich wariantów.
        $m = count($candidates);
        $points = [$from, $to];
        foreach ($candidates as $c) {
            $points[] = self::latLng($c['entryPx']);
        }
        foreach ($candidates as $c) {
            $points[] = self::latLng($c['exitPx']);
        }
        $src = [0];
        $dst = [];
        for ($u = 0; $u < $m; $u++) {
            $src[] = 2 + $m + $u;          // wyjście u
        }
        for ($u = 0; $u < $m; $u++) {
            $src[] = 2 + $u;               // wejście u (kontrola przejezdności)
            $dst[] = 2 + $u;               // wejście u
        }
        $dst[] = 1;                        // CEL
        for ($u = 0; $u < $m; $u++) {
            $dst[] = 2 + $m + $u;          // wyjście u
        }
        $matrix = $table($points, $src, $dst);
        if (!is_array($matrix) || !isset($matrix['distances'])) {
            return ['segment' => null, 'variant' => $variant, 'degraded' => true];
        }
        $D = $matrix['distances'];
        $T = [
            'Se' => static fn(int $u): ?float => $D[0][$u] ?? null,
            'xC' => static fn(int $u): ?float => $D[1 + $u][$m] ?? null,
            'xe' => static fn(int $a, int $b): ?float => $D[1 + $a][$b] ?? null,
            'ex' => static fn(int $u): ?float => $D[1 + $m + $u][$m + 1 + $u] ?? null,
        ];

        $best = null;
        foreach ($variants as $v) {
            $scored = self::evaluate($v['parts'], $candidates, $T, $area['maxLenM'], $ctx['profile']['legalRatio']);
            if ($scored === null) {
                continue;
            }
            $continues = false;
            foreach ($v['parts'] as $u) {
                if (isset($preferredKeys[$candidates[$u]['key']])) {
                    $continues = true;
                    break;
                }
            }
            $scored['choiceCost'] = $scored['cost'] - ($continues ? $p['continuityBonusM'] : 0.0);
            if ($best === null || $scored['choiceCost'] < $best['choiceCost'] - 0.5
                || (abs($scored['choiceCost'] - $best['choiceCost']) <= 0.5 && $scored['lenM'] < $best['lenM'])) {
                $best = $scored + ['parts' => $v['parts']];
            }
        }
        if ($best === null || $best['choiceCost'] > $baseCost - $p['minGainM']) {
            return ['segment' => null, 'variant' => $variant];
        }

        $segment = self::assemble($from, $to, $best['parts'], $candidates, $legs, $speedMps);
        if ($segment === null) {
            return ['segment' => null, 'variant' => $variant, 'degraded' => true];
        }
        if ($segment['distanceM'] > $area['maxLenM'] * 1.03) {
            // Macierz obiecała wariant w limicie, a geometria wyszła dłuższa
            // (inne przyciągnięcie punktów) — wtedy zostaje OSRM.
            return ['segment' => null, 'variant' => $variant];
        }

        $variant['chosen'] = 'ridemore';
        $variant['chosenM'] = (int) round($segment['distanceM']);
        $variant['extraM'] = (int) round($segment['distanceM'] - $baseM);
        foreach ($best['parts'] as $u) {
            $c = $candidates[$u];
            $variant['corridors'][] = [
                'source'  => $c['source'],
                'label'   => $c['label'],
                'lengthM' => (int) round($c['lenM']),
                'riders'  => $c['riders'],
                'passes'  => $c['passes'],
                'score'   => round($c['score'], 2),
            ];
        }
        $continuityKeys = [];
        foreach ($best['parts'] as $u) {
            $continuityKeys[$candidates[$u]['key']] = true;
        }
        return ['segment' => $segment, 'variant' => $variant, 'continuityKeys' => array_keys($continuityKeys)];
    }

    // ------------------------------------------------------------------
    // Obszar, skala i koszt
    // ------------------------------------------------------------------

    /** Najdłuższa dopuszczalna trasa dla trasy bazowej o długości $baseM. */
    public static function maxLengthM(float $baseM): float
    {
        $p = self::PARAMS;
        $extraM = max($p['minExtraM'], $baseM * $p['detourRatio']);
        return $baseM + min($p['maxExtraM'], $extraM);
    }

    /** Bonus korytarza w metrach: długość × score × waga, pełny od fullContinuityM. */
    public static function bonusM(float $lenM, float $score): float
    {
        $p = self::PARAMS;
        return $p['bonusPerM'] * $score * $lenM * min(1.0, $lenM / $p['fullContinuityM']);
    }

    /**
     * RidemoreScore jednej sondy (0..1). Społeczność: osoby i przejazdy
     * w LOKALNEJ skali (log, żeby 30 osób nie ważyło 10× tyle co 3), od dwóch
     * osób wzwyż. Znana trasa i mój przejazd podnoszą wynik do swojej podłogi.
     * Przejazdy (społeczność, moje) mnoży świeżość; znanej trasy nie — jest
     * kuratorowana, nie „ostatnio jeżdżona". Sonda przy znanej trasie
     * niezgodnej z profilem (`blocked`) ma 0 — popularność nie nadpisuje
     * profilu roweru.
     *
     * @param array{riders:int,passes:int,known:bool,mine:int,ageDays?:?int,blocked?:bool,trail?:int} $s
     * @param array{riders:float,passes:float} $ref
     * @param array{mine:bool,known:bool,community:bool} $sources
     * @param ?array{legalRatio:float,minAsphaltPct:?int,trailBonus:float} $profile brak = PARAMS['defaultProfile']
     */
    public static function score(array $s, array $ref, array $sources, ?array $profile = null): float
    {
        $p = self::PARAMS;
        $profile ??= $p['defaultProfile'];
        if (!empty($s['blocked'])) {
            return 0.0;
        }
        $fresh = self::recencyFactor($s['ageDays'] ?? null);
        $score = 0.0;
        if (!empty($sources['community']) && $s['riders'] >= $p['minCommunityRiders']) {
            $pop = min(1.0, log(1 + $s['riders']) / log(1 + max($p['riderRefMin'], (float) $ref['riders'])));
            $rep = min(1.0, log(1 + $s['passes']) / log(1 + max($p['passRefMin'], (float) $ref['passes'])));
            $score = ($p['weightRiders'] * $pop + $p['weightPasses'] * $rep) * $fresh;
        }
        if (!empty($sources['known']) && $s['known']) {
            $known = $p['knownFloor'];
            if (($s['trail'] ?? 0) >= $p['trailPct']) {
                $known += $profile['trailBonus'];
            }
            $score = max($score, min(1.0, $known));
        }
        if (!empty($sources['mine']) && $s['mine'] > 0) {
            $score = max($score, min(1.0, $p['mineFloor'] + $p['mineStep'] * ($s['mine'] - 1)) * $fresh);
        }
        return $score;
    }

    /** Mnożnik świeżości: ≤ recentDays → 1,0; ≥ staleDays → staleFactor; liniowo pomiędzy; brak daty → 1,0. */
    public static function recencyFactor(?int $ageDays): float
    {
        $p = self::PARAMS;
        if ($ageDays === null || $ageDays <= $p['recentDays']) {
            return 1.0;
        }
        if ($ageDays >= $p['staleDays']) {
            return $p['staleFactor'];
        }
        $t = ($ageDays - $p['recentDays']) / ($p['staleDays'] - $p['recentDays']);
        return 1.0 - $t * (1.0 - $p['staleFactor']);
    }

    /**
     * Elipsa START–CEL: każdy punkt wariantu mieszczącego się w limicie ma
     * sumę odległości do START i CELU nie większą niż limit długości — więc
     * korytarzy spoza elipsy nie ma sensu nawet oglądać. Plus kafle indeksu,
     * które ją dotykają (po nich Models\RidemoreCorridors szuka linii).
     *
     * @return array{S:array{0:int,1:int},C:array{0:int,1:int},mpp:float,dSC:float,maxLenM:float,maxSumPx:float,bboxPx:array{0:int,1:int,2:int,3:int},tiles:array<int,true>,bounds:array{south:float,west:float,north:float,east:float}}|null
     */
    public static function area(array $from, array $to, float $baseM): ?array
    {
        $S = TileGrid::toPixel((float) $from['lat'], (float) $from['lng']);
        $C = TileGrid::toPixel((float) $to['lat'], (float) $to['lng']);
        $mpp = TileGrid::metersPerPixel(TileGrid::STORE_Z, ((float) $from['lat'] + (float) $to['lat']) / 2);
        $dSC = hypot($C[0] - $S[0], $C[1] - $S[1]) * $mpp;
        if ($dSC < 50.0 || $baseM <= 0.0) {
            return null;
        }
        $maxLen = self::maxLengthM($baseM);
        $maxSumPx = $maxLen / $mpp;
        $half = $maxSumPx / 2;
        $mx = ($S[0] + $C[0]) / 2;
        $my = ($S[1] + $C[1]) / 2;
        $bbox = [(int) floor($mx - $half), (int) floor($my - $half), (int) ceil($mx + $half), (int) ceil($my + $half)];

        // Kafel dotyka elipsy, jeśli jego środek mieści się w niej z zapasem
        // na przekątną (przesunięcie punktu o d zmienia sumę najwyżej o 2d).
        $shift = TileGrid::shiftFor(TileGrid::INDEX_Z) + 8;
        $size = 1 << $shift;
        $tiles = [];
        for ($tx = $bbox[0] >> $shift; $tx <= $bbox[2] >> $shift; $tx++) {
            for ($ty = $bbox[1] >> $shift; $ty <= $bbox[3] >> $shift; $ty++) {
                $cx = ($tx << $shift) + $size / 2;
                $cy = ($ty << $shift) + $size / 2;
                if (hypot($cx - $S[0], $cy - $S[1]) + hypot($cx - $C[0], $cy - $C[1]) <= $maxSumPx + $size * M_SQRT2) {
                    $tiles[($tx << 32) | $ty] = true;
                }
            }
        }

        [$north, $west] = TileGrid::toLatLon($bbox[0], $bbox[1]);
        [$south, $east] = TileGrid::toLatLon($bbox[2], $bbox[3]);
        return [
            'S' => $S, 'C' => $C, 'mpp' => $mpp, 'dSC' => $dSC, 'maxLenM' => $maxLen, 'maxSumPx' => $maxSumPx,
            'bboxPx' => $bbox, 'tiles' => $tiles,
            'bounds' => ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east],
        ];
    }

    // ------------------------------------------------------------------
    // Wsparcie: ilu ludzi i ile razy jechało wzdłuż danej linii
    // ------------------------------------------------------------------

    /**
     * Linie przycięte do prostokąta elipsy (mniej pamięci i pracy) + wspólna
     * siatka odcinków pod liczenie wsparcia.
     */
    private static function context(array $lines, array $area, array $ref, array $sources, ?string $self, array $opts = []): array
    {
        $profile = is_array($opts['profile'] ?? null)
            ? $opts['profile'] + self::PARAMS['defaultProfile']
            : self::PARAMS['defaultProfile'];
        $today = strtotime((string) ($opts['today'] ?? date('Y-m-d')));
        $counts = $profile['countsRidesOf'];
        foreach ($lines as $j => $line) {
            // Przejazdy na rowerze, którego ten profil nie uznaje za dowód (np.
            // MTB dla szosy), nie są wsparciem. Właściciel zostaje, jeśli choć
            // jeden jego przejazd tym śladem jest zgodny albo bez typu.
            if ($counts && !empty($line['owners'])) {
                $lines[$j]['owners'] = $line['owners'] = array_filter(
                    $line['owners'],
                    static function (array $info) use ($counts): bool {
                        foreach ($info['types'] ?? [null] as $type) {
                            if ($type === null || in_array($type, $counts, true)) {
                                return true;
                            }
                        }
                        return false;
                    }
                );
            }
            // Najświeższy przejazd linii (pod mnożnik świeżości) i zgodność
            // znanej trasy z profilem — raz na linię, nie na każdą sondę.
            $last = null;
            foreach ($line['owners'] ?? [] as $info) {
                if (($info['last'] ?? null) !== null && ($last === null || $info['last'] > $last)) {
                    $last = $info['last'];
                }
            }
            $lines[$j]['ageDays'] = $last !== null ? max(0, (int) floor(($today - strtotime($last)) / 86400)) : null;
            $asphalt = $line['surface']['asphalt'] ?? null;
            $lines[$j]['fits'] = $profile['minAsphaltPct'] === null || $asphalt === null || $asphalt >= $profile['minAsphaltPct'];
        }

        $mpp = $area['mpp'];
        $radiusPx = self::PARAMS['supportRadiusM'] / $mpp;
        $bb = $area['bboxPx'];
        $pad = (int) ceil($radiusPx);
        $clip = [$bb[0] - $pad, $bb[1] - $pad, $bb[2] + $pad, $bb[3] + $pad];
        $maxGap2 = (self::PARAMS['maxVertexGapM'] / $mpp) ** 2;

        $clipped = [];
        foreach ($lines as $line) {
            if ($line['maxPx'] < $clip[0] || $line['minPx'] > $clip[2] || $line['maxPy'] < $clip[1] || $line['minPy'] > $clip[3]) {
                continue;
            }
            // Znana trasa to przebieg PLANOWANY — wierzchołki co kilkaset metrów na
            // prostej są w niej normalne (zmierzone: do ~900 m), więc cięcie po
            // dziurach dotyczy wyłącznie nagrań GPS (utrata sygnału, pauza).
            $gapLimit2 = !empty($line['known']) ? INF : $maxGap2;
            $f = $line['flat'];
            $cur = [];
            for ($i = 0, $n = count($f); $i + 1 < $n; $i += 2) {
                $x = $f[$i];
                $y = $f[$i + 1];
                $inside = $x >= $clip[0] && $x <= $clip[2] && $y >= $clip[1] && $y <= $clip[3];
                $jump = $cur && (($x - $cur[count($cur) - 2]) ** 2 + ($y - $cur[count($cur) - 1]) ** 2) > $gapLimit2;
                if (!$inside || $jump) {
                    if (count($cur) >= 4) {
                        $clipped[] = self::withFlat($line, $cur);
                    }
                    $cur = $inside ? [$x, $y] : [];
                    continue;
                }
                $cur[] = $x;
                $cur[] = $y;
            }
            if (count($cur) >= 4) {
                $clipped[] = self::withFlat($line, $cur);
            }
        }

        $cell = max(1, (int) ceil($radiusPx));
        $flats = array_column($clipped, 'flat');
        return [
            'lines' => $clipped, 'flats' => $flats, 'grid' => RouteSnap::gridIndex($flats, $cell, $clip),
            'cell' => $cell, 'radiusPx' => $radiusPx, 'mpp' => $mpp,
            'ref' => $ref, 'sources' => $sources, 'self' => $self, 'clip' => $clip, 'profile' => $profile,
        ];
    }

    private static function withFlat(array $line, array $flat): array
    {
        $xs = [];
        $ys = [];
        for ($i = 0, $n = count($flat); $i < $n; $i += 2) {
            $xs[] = $flat[$i];
            $ys[] = $flat[$i + 1];
        }
        $line['flat'] = $flat;
        $line['minPx'] = min($xs);
        $line['maxPx'] = max($xs);
        $line['minPy'] = min($ys);
        $line['maxPy'] = max($ys);
        return $line;
    }

    /**
     * Sondy co probeM wzdłuż polilinii (piksele) z wsparciem i wynikiem każdej.
     *
     * @param list<array{0:int,1:int}> $px
     */
    private static function track(array $px, array $ctx, int $maxProbes): array
    {
        $stepPx = self::PARAMS['probeM'] / $ctx['mpp'];
        [$dense] = RouteSnap::densify($px, $stepPx / 2);
        $cum = RouteSnap::cumulative($dense);
        $total = (float) end($cum);
        if ($maxProbes !== PHP_INT_MAX && $total / $stepPx > $maxProbes) {
            $stepPx = $total / $maxProbes;
        }
        $idx = self::probeIndices($cum, $stepPx);
        $probes = [];
        $cumM = [];
        foreach ($idx as $i) {
            $probes[] = $dense[$i];
            $cumM[] = $cum[$i] * $ctx['mpp'];
        }
        $support = self::support($probes, $ctx);
        $scores = [];
        foreach ($support as $s) {
            $scores[] = self::score($s, $ctx['ref'], $ctx['sources'], $ctx['profile']);
        }
        return [
            'dense' => $dense, 'idx' => $idx, 'probes' => $probes, 'cumM' => $cumM,
            'support' => $support, 'scores' => $scores, 'stepM' => $stepPx * $ctx['mpp'],
        ];
    }

    /**
     * Indeksy punktów co $stepPx wzdłuż łamanej (zawsze pierwszy i ostatni).
     *
     * @param list<float> $cum
     * @return list<int>
     */
    public static function probeIndices(array $cum, float $stepPx): array
    {
        $n = count($cum);
        if ($n === 0) {
            return [];
        }
        $idx = [0];
        $next = $stepPx;
        for ($i = 1; $i < $n; $i++) {
            if ($cum[$i] >= $next) {
                $idx[] = $i;
                $next = $cum[$i] + $stepPx;
            }
        }
        if ($idx[count($idx) - 1] !== $n - 1) {
            $idx[] = $n - 1;
        }
        return $idx;
    }

    /**
     * Dla każdej sondy: ile RÓŻNYCH osób (`riders`) i ile przejazdów
     * (`passes`, najwyżej passCapPerRider na osobę) ma ślad w promieniu
     * supportRadiusM, czy leży tam znana trasa i ile razy jechał tędy
     * pytający (`mine`). Ten sam przejazd w pełnej i przyciętej kopii liczy
     * się raz (po hashu). Do tego: wiek najświeższego przejazdu (`ageDays`),
     * % terenu znanej trasy (`trail`) i `blocked` — w pobliżu jest wyłącznie
     * znana trasa NIEZGODNA z profilem roweru.
     *
     * @param list<array{0:int,1:int}> $probes
     * @return list<array{riders:int,passes:int,known:bool,mine:int,ageDays:?int,blocked:bool,trail:int}>
     */
    private static function support(array $probes, array $ctx): array
    {
        $near = $ctx['flats'] ? RouteSnap::nearestAlong($probes, $ctx['flats'], $ctx['grid'], $ctx['cell'], $ctx['radiusPx']) : [];
        $perProbe = [];
        foreach ($near as $j => $matches) {
            $hash = $ctx['lines'][$j]['hash'];
            foreach ($matches as $k => $_) {
                $perProbe[$k][$hash] = $j;
            }
        }
        $cap = self::PARAMS['passCapPerRider'];
        $out = [];
        foreach ($probes as $k => $_) {
            $owners = [];
            $known = false;
            $unfit = false;
            $trail = 0;
            $age = null;
            foreach ($perProbe[$k] ?? [] as $j) {
                $line = $ctx['lines'][$j];
                if (!empty($line['known'])) {
                    if ($line['fits'] ?? true) {
                        $known = true;
                        $trail = max($trail, (int) ($line['surface']['trail'] ?? 0));
                    } else {
                        $unfit = true;
                    }
                    continue;
                }
                foreach ($line['owners'] ?? [] as $owner => $info) {
                    $owners[$owner] = ($owners[$owner] ?? 0) + (int) $info['n'];
                }
                if (($line['ageDays'] ?? null) !== null && ($age === null || $line['ageDays'] < $age)) {
                    $age = $line['ageDays'];
                }
            }
            $passes = 0;
            foreach ($owners as $n) {
                $passes += min($n, $cap);
            }
            $out[] = [
                'riders'  => count($owners),
                'passes'  => $passes,
                'known'   => $known,
                'mine'    => $ctx['self'] !== null ? ($owners[$ctx['self']] ?? 0) : 0,
                'ageDays' => $age,
                'blocked' => $unfit && !$known,
                'trail'   => $trail,
            ];
        }
        return $out;
    }

    /**
     * Ciągłe fragmenty z wynikiem ≥ minScore; „dziura" krótsza niż maxGapM
     * (skrzyżowanie, chwilowy brak śladu) ich nie tnie. Przy rzadszych sondach
     * (bardzo długi kawałek, patrz maxProbesPerPiece) próg rośnie z odstępem —
     * inaczej każda para sąsiednich sond byłaby „dziurą".
     *
     * @param list<float> $scores
     * @param list<float> $cumM
     * @return list<array{0:int,1:int}> [pierwsza, ostatnia sonda]
     */
    public static function runs(array $scores, array $cumM, float $minLenM, float $stepM = 0.0): array
    {
        $p = self::PARAMS;
        $maxGap = max($p['maxGapM'], 2.5 * $stepM);
        $out = [];
        $start = null;
        $last = null;
        foreach ($scores as $k => $s) {
            if ($s < $p['minScore']) {
                continue;
            }
            if ($start !== null && $cumM[$k] - $cumM[$last] > $maxGap) {
                $out[] = [$start, $last];
                $start = null;
            }
            $start ??= $k;
            $last = $k;
        }
        if ($start !== null) {
            $out[] = [$start, $last];
        }
        return array_values(array_filter(
            $out,
            static fn(array $r): bool => $r[1] > $r[0] && $cumM[$r[1]] - $cumM[$r[0]] >= $minLenM
        ));
    }

    // ------------------------------------------------------------------
    // Kandydaci: korytarze z wejściem i wyjściem
    // ------------------------------------------------------------------

    private static function candidates(array $area, array $ctx): array
    {
        $p = self::PARAMS;
        $out = [];
        foreach (self::selectPieces(self::pieces($ctx['lines'], $area), $ctx) as $piece) {
            $line = $ctx['lines'][$piece['line']];
            $track = self::track($piece['px'], $ctx, $p['maxProbesPerPiece']);
            foreach (self::runs($track['scores'], $track['cumM'], $p['minCorridorM'], $track['stepM']) as [$a, $b]) {
                foreach (self::entryExit($track, $a, $b, $area) as $best) {
                    [$i, $j] = [$best['entry'], $best['exit']];
                    [$lo, $hi] = [min($i, $j), max($i, $j)];
                    $slice = array_slice($track['dense'], $track['idx'][$lo], $track['idx'][$hi] - $track['idx'][$lo] + 1);
                    $out[] = $best + [
                        'key'     => (string) $line['hash'],
                        'source'  => $line['source'],
                        'label'   => $line['label'],
                        'entryPx' => $track['probes'][$i],
                        'exitPx'  => $track['probes'][$j],
                        'geomPx'  => $i <= $j ? $slice : array_reverse($slice),
                        'riders'  => self::median(array_column(array_slice($track['support'], $lo, $hi - $lo + 1), 'riders')),
                        'passes'  => self::median(array_column(array_slice($track['support'], $lo, $hi - $lo + 1), 'passes')),
                    ];
                }
            }
        }
        usort($out, static fn(array $a, array $b): int => $a['est'] <=> $b['est']);
        return array_slice($out, 0, $p['maxCorridors']);
    }

    /**
     * Fragmenty linii leżące w elipsie, dłuższe niż minCorridorM — materiał
     * na korytarze.
     *
     * @return list<array{line:int,px:list<array{0:int,1:int}>,lenPx:float}>
     */
    private static function pieces(array $lines, array $area): array
    {
        [$sx, $sy] = $area['S'];
        [$cx, $cy] = $area['C'];
        $max = $area['maxSumPx'];
        $minPx = self::PARAMS['minCorridorM'] / $area['mpp'];
        $out = [];
        foreach ($lines as $j => $line) {
            $f = $line['flat'];
            $run = [];
            $len = 0.0;
            for ($i = 0, $n = count($f); $i + 1 < $n; $i += 2) {
                $x = $f[$i];
                $y = $f[$i + 1];
                if (hypot($x - $sx, $y - $sy) + hypot($x - $cx, $y - $cy) <= $max) {
                    if ($run) {
                        $prev = $run[count($run) - 1];
                        $len += hypot($x - $prev[0], $y - $prev[1]);
                    }
                    $run[] = [$x, $y];
                    continue;
                }
                if ($len >= $minPx) {
                    $out[] = ['line' => $j, 'px' => $run, 'lenPx' => $len];
                }
                $run = [];
                $len = 0.0;
            }
            if ($len >= $minPx) {
                $out[] = ['line' => $j, 'px' => $run, 'lenPx' => $len];
            }
        }
        return $out;
    }

    /**
     * Jedna droga = jeden kandydat. Ślady tą samą drogą to WSPARCIE, nie
     * kolejni kandydaci: kawałek, który w dedupeShare leży na już wybranym,
     * odpada. Kolejność: źródło wg listy (moje → znane → społeczność), potem
     * dłuższe.
     */
    private static function selectPieces(array $pieces, array $ctx): array
    {
        $p = self::PARAMS;
        usort($pieces, static function (array $a, array $b) use ($ctx): int {
            return [$ctx['lines'][$a['line']]['rank'], -$a['lenPx']] <=> [$ctx['lines'][$b['line']]['rank'], -$b['lenPx']];
        });
        $selected = [];
        $flats = [];
        $grid = [];
        foreach ($pieces as $piece) {
            if (count($selected) >= $p['maxPieces']) {
                break;
            }
            if ($flats) {
                $n = count($piece['px']);
                $step = max(1, intdiv($n, $p['dedupeSample']));
                $sample = [];
                for ($i = 0; $i < $n; $i += $step) {
                    $sample[] = $piece['px'][$i];
                }
                $near = RouteSnap::nearestAlong($sample, $flats, $grid, $ctx['cell'], $ctx['radiusPx']);
                $hit = [];
                foreach ($near as $matches) {
                    foreach ($matches as $k => $_) {
                        $hit[$k] = true;
                    }
                }
                if (count($hit) >= $p['dedupeShare'] * count($sample)) {
                    continue;
                }
            }
            $selected[] = $piece;
            $flat = [];
            foreach ($piece['px'] as [$x, $y]) {
                $flat[] = $x;
                $flat[] = $y;
            }
            $flats[] = $flat;
            $grid = RouteSnap::gridIndex($flats, $ctx['cell'], $ctx['clip']);
        }
        return $selected;
    }

    /**
     * Wejście i wyjście na fragmencie korytarza [a, b] — do dwóch propozycji:
     *  • najmniejszy SZACOWANY koszt (dojazd + korytarz − bonus + zjazd);
     *  • korytarz od miejsca najbliższego STARTOWI do najbliższego CELOWI —
     *    szacunek liczy dojazdy po linii prostej, a rzeka bez mostu potrafi
     *    zrobić z „200 m do celu" 30 km drogi; macierz OSRM rozstrzyga potem,
     *    która propozycja jest naprawdę lepsza.
     * Zawsze: korytarz dłuższy niż minCorridorM i zbliżający do celu. Oba
     * kierunki jazdy po linii — ślad nagrany w jedną stronę jest drogą także
     * w drugą.
     *
     * @return list<array{entry:int,exit:int,lenM:float,score:float,bonusM:float,est:float}>
     */
    private static function entryExit(array $track, int $a, int $b, array $area): array
    {
        $p = self::PARAMS;
        [$sx, $sy] = $area['S'];
        [$cx, $cy] = $area['C'];
        $mpp = $area['mpp'];
        $ux = ($cx - $sx) / ($area['dSC'] / $mpp);
        $uy = ($cy - $sy) / ($area['dSC'] / $mpp);

        // Najwyżej ~125 kandydatów na fragment — pary to kwadrat tej liczby.
        $cand = range($a, $b, max(1, $p['entryEvery'], (int) ceil(($b - $a + 1) / 125)));
        if (end($cand) !== $b) {
            $cand[] = $b;
        }
        $pre = [];
        $sum = 0.0;
        for ($k = $a; $k <= $b; $k++) {
            $sum += $track['scores'][$k];
            $pre[$k] = $sum;
        }
        $info = [];
        foreach ($cand as $k) {
            [$x, $y] = $track['probes'][$k];
            $info[$k] = [
                hypot($x - $sx, $y - $sy) * $mpp,                    // do START
                hypot($cx - $x, $cy - $y) * $mpp,                    // do CELU
                (($x - $sx) * $ux + ($y - $sy) * $uy) * $mpp,        // postęp wzdłuż START→CEL
            ];
        }

        $best = null;
        $anchored = null;
        foreach ($cand as $i) {
            foreach ($cand as $j) {
                if ($i === $j) {
                    continue;
                }
                $len = abs($track['cumM'][$j] - $track['cumM'][$i]);
                if ($len < $p['minCorridorM'] || $info[$j][2] - $info[$i][2] < $p['directionMin'] * $len) {
                    continue;
                }
                // Dolna granica długości wariantu (linia prosta nie jest dłuższa
                // od drogi) — poza limitem nie ma czego szacować.
                if ($info[$i][0] + $len + $info[$j][1] > $area['maxLenM']) {
                    continue;
                }
                [$lo, $hi] = [min($i, $j), max($i, $j)];
                $mean = ($pre[$hi] - ($lo > $a ? $pre[$lo - 1] : 0.0)) / ($hi - $lo + 1);
                $bonus = self::bonusM($len, $mean);
                $est = $p['detourFactor'] * ($info[$i][0] + $info[$j][1]) + $len - $bonus;
                $option = ['entry' => $i, 'exit' => $j, 'lenM' => $len, 'score' => $mean, 'bonusM' => $bonus, 'est' => $est];
                if ($best === null || $est < $best['est']) {
                    $best = $option;
                }
                $reach = $info[$i][0] + $info[$j][1];
                if ($anchored === null || $reach < $anchored['reach'] - 1.0) {
                    $anchored = $option + ['reach' => $reach];
                }
            }
        }
        if ($best === null) {
            return [];
        }
        $out = [$best];
        if ($anchored !== null && [$anchored['entry'], $anchored['exit']] !== [$best['entry'], $best['exit']]) {
            unset($anchored['reach']);
            $out[] = $anchored;
        }
        return $out;
    }

    /**
     * Warianty: każdy korytarz osobno + najlepsze pary w kolejności jazdy
     * (wyjście pierwszego przed wejściem drugiego, patrząc wzdłuż START→CEL).
     * Bez eksplozji kombinacji: najwyżej maxCorridors + maxPairs.
     *
     * @return list<array{parts:list<int>,est:float}>
     */
    private static function variants(array $candidates, array $area): array
    {
        $p = self::PARAMS;
        [$sx, $sy] = $area['S'];
        [$cx, $cy] = $area['C'];
        $mpp = $area['mpp'];
        $ux = ($cx - $sx) / ($area['dSC'] / $mpp);
        $uy = ($cy - $sy) / ($area['dSC'] / $mpp);
        $t = static fn(array $pt): float => (($pt[0] - $sx) * $ux + ($pt[1] - $sy) * $uy) * $mpp;
        $d = static fn(array $a, array $b): float => hypot($a[0] - $b[0], $a[1] - $b[1]) * $mpp;

        $out = [];
        foreach ($candidates as $u => $c) {
            $out[] = ['parts' => [$u], 'est' => $c['est']];
        }
        $pairs = [];
        foreach ($candidates as $a => $A) {
            foreach ($candidates as $b => $B) {
                if ($a === $b || $t($A['exitPx']) >= $t($B['entryPx'])) {
                    continue;
                }
                $lower = $d($area['S'], $A['entryPx']) + $A['lenM'] + $d($A['exitPx'], $B['entryPx']) + $B['lenM'] + $d($B['exitPx'], $area['C']);
                if ($lower > $area['maxLenM']) {
                    continue;
                }
                $est = $p['detourFactor'] * ($d($area['S'], $A['entryPx']) + $d($A['exitPx'], $B['entryPx']) + $d($B['exitPx'], $area['C']))
                    + $A['lenM'] - $A['bonusM'] + $B['lenM'] - $B['bonusM'];
                $pairs[] = ['parts' => [$a, $b], 'est' => $est];
            }
        }
        usort($pairs, static fn(array $x, array $y): int => $x['est'] <=> $y['est']);
        return array_merge($out, array_slice($pairs, 0, $p['maxPairs']));
    }

    /**
     * Dokładna długość i koszt wariantu z macierzy OSRM, z twardymi regułami:
     * każde połączenie musi istnieć, OSRM musi umieć przejechać każdy korytarz
     * (legalRatio), a całość mieścić się w limicie długości.
     *
     * @param array<string,callable> $T
     * @return array{lenM:float,cost:float}|null
     */
    private static function evaluate(array $parts, array $candidates, array $T, float $maxLenM, float $legalRatio): ?array
    {
        $len = $T['Se']($parts[0]);
        if ($len === null) {
            return null;
        }
        $bonus = 0.0;
        foreach ($parts as $k => $u) {
            $c = $candidates[$u];
            $through = $T['ex']($u);
            if ($through === null || $through > $legalRatio * $c['lenM']) {
                return null; // OSM nie potwierdza, że tym korytarzem da się przejechać rowerem
            }
            $len += $c['lenM'];
            $bonus += $c['bonusM'];
            $next = isset($parts[$k + 1]) ? $T['xe']($u, $parts[$k + 1]) : $T['xC']($u);
            if ($next === null) {
                return null;
            }
            $len += $next;
        }
        if ($len > $maxLenM) {
            return null;
        }
        return ['lenM' => $len, 'cost' => $len - $bonus];
    }

    /**
     * Geometria zwycięzcy: JEDNO zapytanie OSRM przez START, wejścia, wyjścia
     * i CEL; odcinki wejście→wyjście zastępuje geometria korytarza.
     */
    private static function assemble(array $from, array $to, array $parts, array $candidates, callable $legs, float $speedMps): ?array
    {
        $waypoints = [$from];
        foreach ($parts as $u) {
            $waypoints[] = self::latLng($candidates[$u]['entryPx']);
            $waypoints[] = self::latLng($candidates[$u]['exitPx']);
        }
        $waypoints[] = $to;
        $route = $legs($waypoints);
        if (!is_array($route) || count($route['legs'] ?? []) !== count($waypoints) - 1) {
            return null;
        }

        $coords = [];
        $append = static function (array $points) use (&$coords): void {
            foreach ($points as $pt) {
                if ($coords && $coords[count($coords) - 1] === $pt) {
                    continue;
                }
                $coords[] = $pt;
            }
        };
        $pieces = [];
        $durationS = 0.0;
        $ridemoreM = 0.0;
        foreach ($parts as $k => $u) {
            $connector = $route['legs'][2 * $k];
            $append($connector['coords']);
            $durationS += $connector['durationS'];

            $c = $candidates[$u];
            $geom = array_map(static fn(array $px): array => self::latLng($px, true), $c['geomPx']);
            $start = count($coords);
            $append($geom);
            $pieces[] = ['source' => $c['source'], 'label' => $c['label'], 'from' => max(0, $start - 1), 'to' => count($coords) - 1];
            $durationS += $c['lenM'] / $speedMps;
            $ridemoreM += $c['lenM'];
        }
        $last = $route['legs'][count($route['legs']) - 1];
        $append($last['coords']);
        $durationS += $last['durationS'];

        return [
            'coords'    => $coords,
            'distanceM' => RouteSnap::lineLengthM($coords),
            'durationS' => $durationS,
            'ridemoreM' => (int) round($ridemoreM),
            'ridemore'  => $pieces,
        ];
    }

    // ------------------------------------------------------------------
    // Drobne
    // ------------------------------------------------------------------

    /** @return array{lat:float,lng:float}|array{0:float,1:float} */
    private static function latLng(array $px, bool $pair = false): array
    {
        [$lat, $lng] = TileGrid::toLatLon((int) $px[0], (int) $px[1]);
        $lat = round($lat, 6);
        $lng = round($lng, 6);
        return $pair ? [$lat, $lng] : ['lat' => $lat, 'lng' => $lng];
    }

    private static function mean(array $values, int $a, int $b): float
    {
        return $b >= $a ? array_sum(array_slice($values, $a, $b - $a + 1)) / ($b - $a + 1) : 0.0;
    }

    /** @param list<int> $values */
    private static function median(array $values): int
    {
        if (!$values) {
            return 0;
        }
        sort($values);
        return (int) $values[intdiv(count($values), 2)];
    }
}
