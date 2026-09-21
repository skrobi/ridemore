<?php
// core/Utils/DiscoveryGrid.php
// Etap 8 (Discovery Grid) — podział świata na heksagonalne "pola", które
// rowerzysta odkrywa jadąc.
//
// DLACZEGO NOWA KLASA, A NIE Utils\RouteCells
// -------------------------------------------
// RouteCells (Etap 2) robi coś powierzchownie podobnego — dzieli trasę na
// komórki — ale nie nadaje się tutaj z dwóch niezależnych powodów:
//
//   1. Jest siatką WYŁĄCZNIE dla Polski. Przelicza długość geograficzną
//      przez cos(52°) wpisany na stałe, żeby siatka pokrywała się między
//      Bieszczadami a Mazurami. Poza Polską ta sama komórka ma inny rozmiar
//      w terenie, a przy równiku rozjeżdża się o 60%. §31 wymaga modelu
//      gotowego na Europę i świat, więc ta uproszczona projekcja odpada.
//   2. Komórki są kwadratowe, a §3 preferuje heksagony. To nie jest wyłącznie
//      kwestia wyglądu: kwadraty stykają się rogami, więc trasa przecinająca
//      siatkę po skosie zostawia w odkrytym pasie regularne dziury. Heksagon
//      ma sześciu sąsiadów krawędziowych i zera narożnikowych — pas odkryty
//      wzdłuż trasy jest spójny bez sztuczek.
//
// RouteCells zostaje nietknięty przy dopasowaniach — tam odpowiada na pytanie
// "czy te dwa wyjazdy są w tych samych okolicach" i robi to dobrze.
//
// WYBÓR PRZESTRZENI: METRY WEB MERCATOR
// -------------------------------------
// Siatka żyje w metrach Web Mercator (EPSG:3857), tej samej przestrzeni, w
// której rysuje kafle Leaflet. Rozważane i ODRZUCONE alternatywy:
//
//   - Projekcja równopowierzchniowa (Lambert). Kusi, bo pole miałoby wszędzie
//     tę samą powierzchnię w terenie. Ale heksagon zdefiniowany w tej
//     projekcji, narysowany na mapie Mercatora, jest na 52°N rozciągnięty w
//     pionie ~2,6× (Mercator rozciąga o 1/cos φ, równopowierzchniowa ściska
//     o cos φ — błędy się mnożą, nie znoszą). §24 i §28 wymagają, żeby
//     heksagony wyglądały jak część tego serwisu, a nie jak usterka.
//   - Siatka jak w RouteCells, tylko z inną szerokością referencyjną. To samo
//     ograniczenie do jednego kraju, tylko przesunięte.
//
// Cena Mercatora: pole na 60°N pokrywa mniej terenu niż pole na 40°N (skala
// maleje jak cos φ). Świadomie akceptowana — dokładnie tak zachowuje się każdy
// kafel każdej mapy internetowej, a gdyby kiedyś trzeba było zrównać wartość
// punktową odkrycia między szerokościami, robi się to WSPÓŁCZYNNIKIEM W
// PUNKTACJI (konfigurowalnej, patrz core/discovery.php), a nie przebudową
// zapisanych danych.
//
// ROZDZIELCZOŚĆ
// -------------
// RES_CELL = 4 daje pole o szerokości ok. 500 m w terenie na szerokości Polski
// (≈0,22 km² powierzchni). Typowa 60-kilometrowa trasa dotyka ok. 120 pól.
//
// Pierwsza wersja miała pole 1-kilometrowe — dobrane pod zdania z dokumentu
// (§38 „odkryłeś 27 nowych pól", §17 „jeszcze 8 pól do pełnego odkrycia
// obszaru"), czyli pod dziesiątki pól na przejazd, nie setki. Na mapie okazało
// się jednak po prostu za grube: heksagon wielkości miasteczka zaokrągla ślad
// trasy tak mocno, że przestaje być widać, KTÓRĘDY ktoś jechał, a o to na tej
// mapie chodzi najbardziej. 500 m jest kompromisem — ślad zostaje czytelny,
// a liczby wciąż mieszczą się w głowie. Zejście niżej (250 m) zacznie
// produkować „247 nowych pól" przy weekendowej pętli.
//
// Grubsze poziomy służą wyłącznie agregacji mapy przy oddaleniu (§32) — nic
// się na nich nie zapisuje.
//
// HEKSAGONY NIE ZAGNIEŻDŻAJĄ SIĘ IDEALNIE
// ---------------------------------------
// Cztery pola nie tworzą jednego większego pola, jak działoby się to z
// kwadratami. Rodzic liczony jest więc przez PONOWNE PRZYPISANIE ŚRODKA pola
// do grubszej siatki (parentCell) — przybliżenie, dokładnie to samo, którego
// używa H3. Dla mapy przy oddaleniu, gdzie i tak agregujemy tysiące pól w
// jedną plamę, jest w zupełności wystarczające; nigdzie nie opieramy na nim
// liczb pokazywanych użytkownikowi.
namespace Utils;

class DiscoveryGrid
{
    // Promień kuli ziemskiej przyjęty przez Web Mercator (EPSG:3857).
    private const EARTH_RADIUS_M = 6378137.0;

    // Mercator nie istnieje na biegunach — standardowe obcięcie, to samo,
    // którego używa Leaflet i każdy dostawca kafli.
    private const MAX_LAT_DEG = 85.05112878;

    // Promień opisany heksagonu w metrach Mercatora, per poziom. Każdy kolejny
    // poziom jest 4× drobniejszy. Poziom 4 to POLE — jedyny, na którym cokolwiek
    // się zapisuje; 0–3 istnieją wyłącznie po to, żeby mapa przy oddaleniu
    // pokazywała plamy zamiast miliona wielokątów.
    //
    // ZMIANA ROZMIARU POLA WYMAGA PRZELICZENIA WSZYSTKIEGO OD ZERA. Poziom jest
    // zaszyty w identyfikatorze pola, więc odkrycia zapisane przy poprzednim
    // rozmiarze nigdy nie zrównają się z nowymi: trzeba wyczyścić
    // discovery_cells / discovery_cell_totals i puścić backfill_discovery.php
    // --rebuild (na produkcji dodatkowo przeliczyć known_route_cells, bo trasy
    // też są podzielone tą siatką).
    private const SIZES_M = [
        0 => 120000.0,  // ok. 128 km w terenie na 52°N — pół kraju
        1 => 30000.0,   // ok. 32 km — województwo
        2 => 7500.0,    // ok. 8 km — okolica
        3 => 1875.0,    // ok. 2 km
        4 => 468.75,    // ok. 500 m  ← POLE
    ];

    // Poziom, na którym liczy się odkrycie. Wszystko, co trafia do
    // discovery_cells, ma ten poziom zaszyty w identyfikatorze.
    public const RES_CELL = 4;

    // Identyfikator pola pakowany jest w jeden BIGINT ze znakiem: 3 bity
    // poziomu + dwie współrzędne osiowe po 30 bitów. Powód: to klucz główny
    // i kolumna złączenia w każdym zapytaniu Discovery — liczba indeksuje się
    // i porównuje bez porównywania łańcuchów, a 8 bajtów zamiast kilkunastu
    // ma znaczenie przy tabeli, która docelowo urośnie najbardziej w bazie.
    // 30 bitów wystarcza z ogromnym zapasem: na poziomie 4 cały świat mieści
    // się w zakresie ok. ±12 400 (limit to ±536 870 911).
    private const COORD_BITS = 30;
    private const COORD_MASK = 0x3FFFFFFF;
    private const COORD_SIGN = 0x20000000;

    public static function sizeM(int $res): float
    {
        return self::SIZES_M[$res] ?? self::SIZES_M[self::RES_CELL];
    }

    /** Poziomy dostępne dla mapy, od najgrubszego. */
    public static function resolutions(): array
    {
        return array_keys(self::SIZES_M);
    }

    // ---------------------------------------------------------------
    // Punkt -> pole
    // ---------------------------------------------------------------

    /** Zwraca spakowany identyfikator pola zawierającego dany punkt. */
    public static function pointToCell(float $lat, float $lon, int $res = self::RES_CELL): int
    {
        [$x, $y] = self::project($lat, $lon);
        [$q, $r] = self::pointToAxial($x, $y, self::sizeM($res));
        return self::encode($res, $q, $r);
    }

    /** Środek pola jako [lat, lon] — pod agregację i pod pinezki na mapie. */
    public static function cellCenter(int $cellId): array
    {
        [$res, $q, $r] = self::decode($cellId);
        [$x, $y] = self::axialToPoint($q, $r, self::sizeM($res));
        return self::unproject($x, $y);
    }

    /**
     * Sześć wierzchołków pola jako [[lat, lon], ...] — gotowe do podania
     * Leafletowi jako L.polygon. Zamknięcie wielokąta zostawiamy bibliotece.
     */
    public static function cellPolygon(int $cellId): array
    {
        [$res, $q, $r] = self::decode($cellId);
        $size = self::sizeM($res);
        [$cx, $cy] = self::axialToPoint($q, $r, $size);

        $corners = [];
        for ($i = 0; $i < 6; $i++) {
            // Heksagon "pointy-top": pierwszy wierzchołek 30° od osi X.
            $angle = deg2rad(60 * $i - 30);
            $corners[] = self::unproject($cx + $size * cos($angle), $cy + $size * sin($angle));
        }
        return $corners;
    }

    /**
     * Pole na grubszym poziomie, zawierające środek danego pola. Heksagony nie
     * zagnieżdżają się dokładnie (patrz komentarz nad klasą) — to przybliżenie
     * wyłącznie pod agregację widoku mapy.
     */
    public static function parentCell(int $cellId, int $res): int
    {
        [$lat, $lon] = self::cellCenter($cellId);
        return self::pointToCell($lat, $lon, $res);
    }

    /**
     * Rodzice pola na WSZYSTKICH poziomach agregacji mapy, do zapisania
     * w kolumnach `discovery_cell_totals.parent_res*` (migr. 047).
     *
     * DLACZEGO W KOLUMNIE, A NIE LICZONE W ZAPYTANIU: mapa grupowała wcześniej
     * pola po prostokącie we współrzędnych osiowych (`FLOOR(cell_q / krok)`),
     * bo tylko to da się wyrazić w SQL-u. Heksagony poziomu wyższego NIE
     * układają się jednak w prostokąty siatki poziomu niższego — na danych
     * deweloperskich 199 z 274 prostokątnych grup przy res=3 zawierało pola
     * należące do różnych heksagonów nadrzędnych. Cała grupa lądowała wtedy pod
     * jednym z nich, a pozostałe nie były rysowane w ogóle („znikające
     * hexagony" przy skalowaniu).
     *
     * Przynależność rozstrzyga więc TA klasa, raz, przy zapisie — i zostaje
     * jedynym właścicielem geometrii siatki. Zapytanie mapy grupuje po gotowej
     * kolumnie, czyli po realnym heksagonie.
     *
     * @return array<int,int> res => identyfikator pola nadrzędnego (bez RES_CELL,
     *                        gdzie pole jest swoim własnym rodzicem)
     */
    public static function parentsFor(int $cellId): array
    {
        // Środek liczony RAZ dla wszystkich poziomów — cellCenter to
        // najdroższa część tej operacji, a wynik jest ten sam.
        [$lat, $lon] = self::cellCenter($cellId);

        $out = [];
        for ($res = self::RES_CELL - 1; $res >= 0; $res--) {
            $out[$res] = self::pointToCell($lat, $lon, $res);
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Ślad -> pola
    // ---------------------------------------------------------------

    /**
     * Pola dotknięte przez ślad. $points w kształcie zwracanym przez
     * Utils\Gpx::parse()['points'] — [['lat' => float, 'lon' => float], ...].
     * Zwraca unikalne identyfikatory pól.
     *
     * Zagęszczanie odcinków jest SIATKĄ BEZPIECZEŃSTWA, nie sposobem na
     * uzupełnianie brakującej geometrii: przy prawdziwym pliku GPX kolejne
     * punkty dzieli kilkanaście–kilkadziesiąt metrów i interpolacja praktycznie
     * nie wchodzi w grę. Wchodzi dopiero przy śladzie rzadkim, i wtedy jej
     * wynik jest tylko tak dobry, jak założenie, że między punktami trasa
     * biegła prosto — dlatego Discovery czyta PEŁNE pliki GPX z dysku, a nie
     * 50-punktowy events_stages.elevation_profile (zmierzone: komórki liczone
     * z profilu to w ~38% teren, którego nikt nie tknął).
     */
    public static function cellsForTrack(array $points, int $res = self::RES_CELL): array
    {
        $size = self::sizeM($res);
        // Najkrótsza przekątna heksagonu to sqrt(3)*size; krok równy połowie
        // tej wartości gwarantuje, że prosty odcinek nie przeskoczy pola.
        $maxStepM = sqrt(3) * $size / 2;

        $cells = [];
        $prev = null;
        foreach ($points as $p) {
            if (!isset($p['lat'], $p['lon'])) {
                continue;
            }
            $curr = self::project((float) $p['lat'], (float) $p['lon']);

            if ($prev !== null) {
                $dx = $curr[0] - $prev[0];
                $dy = $curr[1] - $prev[1];
                $dist = sqrt($dx * $dx + $dy * $dy);
                $steps = (int) ceil($dist / $maxStepM);
                for ($s = 1; $s < $steps; $s++) {
                    $t = $s / $steps;
                    [$q, $r] = self::pointToAxial($prev[0] + $dx * $t, $prev[1] + $dy * $t, $size);
                    $id = self::encode($res, $q, $r);
                    $cells[$id] = true;
                }
            }

            [$q, $r] = self::pointToAxial($curr[0], $curr[1], $size);
            $cells[self::encode($res, $q, $r)] = true;
            $prev = $curr;
        }

        return array_keys($cells);
    }

    /**
     * Prostokąt lat/lon obejmujący ZBIÓR pól, opisany jego skrajnymi
     * wartościami osiowymi. Odwrotność `axialRangeForBounds()`.
     *
     * PO CO: mapa musi znać swój kadr, ZANIM zapyta o dane. Bez tego startuje
     * w środku kraju, pobiera pola dla całej Polski i dopiero po odpowiedzi
     * dociąga się do tego, co dostała — czyli pokazuje zły obszar, wykonuje
     * zbędne żądanie i skacze w oczach użytkownika.
     *
     * DLACZEGO DWIE OSIE LICZĄ SIĘ INACZEJ (patrz `axialToPoint`):
     *   y = size · 3/2 · r          → szerokość zależy WYŁĄCZNIE od `r`,
     *   x = size · √3 · (q + r/2)   → długość zależy od `q` ORAZ `r`.
     * Dlatego skrajne długości bierzemy z wyrażenia `2q + r` (podanego jako
     * `$sMin`/`$sMax`), a nie ze skrajnych `q`: zbiór rozciągnięty po skosie
     * ma skrajne `q` i skrajne `r` w RÓŻNYCH polach, więc łączenie ich parami
     * dałoby prostokąt grubo większy od faktycznego.
     *
     * Zapas jednego promienia pola z każdej strony — kadr ma obejmować całe
     * skrajne pola, a nie ich środki.
     *
     * @param int $rMin,$rMax skrajne `r` w zbiorze
     * @param int $sMin,$sMax skrajne `2q + r` w zbiorze
     * @return array{south:float,west:float,north:float,east:float}
     */
    public static function boundsFromAxialExtremes(
        int $rMin,
        int $rMax,
        int $sMin,
        int $sMax,
        int $res = self::RES_CELL
    ): array {
        $size = self::sizeM($res);

        [$south, $west] = self::unproject(
            $size * sqrt(3) * ($sMin / 2) - $size,
            $size * 3 / 2 * $rMin - $size
        );
        [$north, $east] = self::unproject(
            $size * sqrt(3) * ($sMax / 2) + $size,
            $size * 3 / 2 * $rMax + $size
        );

        return ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east];
    }

    /**
     * Wyrażenia SQL rozpakowujące `q` i `r` ze spakowanego `cell_id`.
     *
     * Mieszkają TUTAJ, przy `encode()`/`decode()`, bo to jedno miejsce, które
     * zna format identyfikatora. Potrzebne wszędzie tam, gdzie tabela trzyma
     * samo `cell_id` bez zdenormalizowanych kolumn (`discovery_cells`) —
     * a wyciąganie milionów wierszy do PHP-a tylko po to, żeby policzyć
     * minimum i maksimum, nie wchodzi w grę.
     *
     * CAST na SIGNED jest KONIECZNY: operatory bitowe MySQL-a zwracają
     * BIGINT UNSIGNED i odjęcie znaku skończyłoby się błędem zakresu.
     * Ta sama arytmetyka co w migracji 062.
     */
    public static function sqlQ(string $column): string
    {
        return self::sqlSignExtend('(' . $column . ' >> ' . self::COORD_BITS . ')');
    }

    public static function sqlR(string $column): string
    {
        return self::sqlSignExtend($column);
    }

    private static function sqlSignExtend(string $expr): string
    {
        $masked = '(' . $expr . ' & ' . self::COORD_MASK . ')';
        return '(CAST(' . $masked . ' AS SIGNED) - IF(' . $masked . ' >= ' . self::COORD_SIGN . ', '
            . (self::COORD_MASK + 1) . ', 0))';
    }

    /**
     * Zakres współrzędnych osiowych pokrywający prostokąt mapy — pod zapytanie
     * o widoczny obszar (§32: nigdy nie ładujemy całej siatki).
     *
     * `r` zależy wyłącznie od osi Y Mercatora, więc jego zakres jest dokładny.
     * `q` zależy od X ORAZ Y, więc bierzemy skrajne wartości z czterech
     * narożników i poszerzamy o 1 — wynik jest NADZBIOREM prostokąta. Kilka
     * pól spoza kadru na brzegach jest nieszkodliwe (i tak sąsiadują z
     * widocznymi), a gwarancja, że nic nie wypadnie, jest tu ważniejsza.
     *
     * @return array{qMin:int,qMax:int,rMin:int,rMax:int}
     */
    public static function axialRangeForBounds(
        float $south,
        float $west,
        float $north,
        float $east,
        int $res = self::RES_CELL
    ): array {
        $size = self::sizeM($res);
        $qs = [];
        $rs = [];
        foreach ([[$south, $west], [$south, $east], [$north, $west], [$north, $east]] as [$lat, $lon]) {
            [$x, $y] = self::project($lat, $lon);
            [$q, $r] = self::pointToAxial($x, $y, $size);
            $qs[] = $q;
            $rs[] = $r;
        }
        return [
            'qMin' => min($qs) - 1,
            'qMax' => max($qs) + 1,
            'rMin' => min($rs) - 1,
            'rMax' => max($rs) + 1,
        ];
    }

    // USUNIĘTE 2026-08-13: axialStepTo() — „ile pól poziomu RES_CELL przypada na
    // jedno pole poziomu $res w każdej osi". Służyło WYŁĄCZNIE do grupowania
    // mapy po prostokącie (GROUP BY FLOOR(cell_q / krok)), które okazało się
    // źródłem znikających heksagonów: prostokąt siatki drobnej nie pokrywa się
    // z heksagonem siatki grubej, więc jedna grupa zawierała pola należące do
    // kilku różnych rodziców. Od migr. 047 rodzic jest wyliczany przez
    // parentsFor() przy zapisie i trzymany w kolumnie, a ta metoda nie ma już
    // ani jednego wywołania. Nie przywracać jej „na wszelki wypadek" — liczba,
    // która wygląda na przelicznik między poziomami siatki, kusi do dokładnie
    // tego błędu drugi raz.

    /**
     * Odcina od śladu punkty leżące w promieniu $radiusM od JEGO POCZĄTKU
     * i KOŃCA — czyli, przy przejeździe solo, od domu (§27).
     *
     * Świadomie operuje na PUNKTACH, nie na gotowych polach: usunięcie pola po
     * fakcie zostawiałoby je w `rider_activity_cells` (warstwa heatmapy), więc
     * miejsce zamieszkania dalej dałoby się odczytać — tylko z innej tabeli.
     * Punkt, którego nie ma, nie wyprodukuje żadnego wiersza w żadnej z nich.
     *
     * Pętla wracająca pod ten sam dom traci oba końce jednym warunkiem, bo
     * sprawdzamy odległość od OBU krańców niezależnie.
     *
     * @param array $points [['lat' => float, 'lon' => float], ...]
     */
    public static function trimEnds(array $points, int $radiusM): array
    {
        if ($radiusM <= 0 || count($points) < 3) {
            return $points;
        }

        $first = $points[0];
        $last = $points[count($points) - 1];

        $kept = [];
        foreach ($points as $p) {
            if (self::distanceM($p['lat'], $p['lon'], $first['lat'], $first['lon']) <= $radiusM) {
                continue;
            }
            if (self::distanceM($p['lat'], $p['lon'], $last['lat'], $last['lon']) <= $radiusM) {
                continue;
            }
            $kept[] = $p;
        }

        // Gdyby przycięcie zjadło CAŁY ślad (bardzo krótka pętla wokół domu),
        // oddajemy pustą tablicę — lepiej nie policzyć takiego przejazdu, niż
        // policzyć go razem z miejscem zamieszkania.
        return $kept;
    }

    /** Odległość w metrach (haversine) — na potrzeby trimEnds(). */
    private static function distanceM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // ---------------------------------------------------------------
    // Pakowanie identyfikatora
    // ---------------------------------------------------------------

    public static function encode(int $res, int $q, int $r): int
    {
        return ($res << (2 * self::COORD_BITS))
            | (($q & self::COORD_MASK) << self::COORD_BITS)
            | ($r & self::COORD_MASK);
    }

    /** @return array{0:int,1:int,2:int} [res, q, r] */
    public static function decode(int $cellId): array
    {
        $res = ($cellId >> (2 * self::COORD_BITS)) & 0x7;
        $q = self::signExtend(($cellId >> self::COORD_BITS) & self::COORD_MASK);
        $r = self::signExtend($cellId & self::COORD_MASK);
        return [$res, $q, $r];
    }

    public static function resolutionOf(int $cellId): int
    {
        return ($cellId >> (2 * self::COORD_BITS)) & 0x7;
    }

    // ---------------------------------------------------------------
    // Wnętrzności
    // ---------------------------------------------------------------

    /** Stopnie -> metry Web Mercator. */
    private static function project(float $lat, float $lon): array
    {
        $lat = max(-self::MAX_LAT_DEG, min(self::MAX_LAT_DEG, $lat));
        return [
            deg2rad($lon) * self::EARTH_RADIUS_M,
            self::EARTH_RADIUS_M * log(tan(M_PI / 4 + deg2rad($lat) / 2)),
        ];
    }

    /** Metry Web Mercator -> [lat, lon]. */
    private static function unproject(float $x, float $y): array
    {
        return [
            rad2deg(2 * atan(exp($y / self::EARTH_RADIUS_M)) - M_PI / 2),
            rad2deg($x / self::EARTH_RADIUS_M),
        ];
    }

    /** Punkt w metrach Mercatora -> zaokrąglone współrzędne osiowe heksagonu. */
    private static function pointToAxial(float $x, float $y, float $size): array
    {
        $q = (sqrt(3) / 3 * $x - 1 / 3 * $y) / $size;
        $r = (2 / 3 * $y) / $size;
        return self::roundAxial($q, $r);
    }

    private static function axialToPoint(int $q, int $r, float $size): array
    {
        return [
            $size * sqrt(3) * ($q + $r / 2),
            $size * 3 / 2 * $r,
        ];
    }

    /**
     * Zaokrąglenie ułamkowych współrzędnych osiowych do najbliższego heksagonu.
     * Przez współrzędne sześcienne (x + y + z = 0), bo naiwne round() na parze
     * osiowej wskazuje czasem sąsiada zamiast właściwego pola — składowa o
     * największym błędzie jest odtwarzana z dwóch pozostałych.
     */
    private static function roundAxial(float $q, float $r): array
    {
        $x = $q;
        $z = $r;
        $y = -$x - $z;

        $rx = round($x);
        $ry = round($y);
        $rz = round($z);

        $dx = abs($rx - $x);
        $dy = abs($ry - $y);
        $dz = abs($rz - $z);

        if ($dx > $dy && $dx > $dz) {
            $rx = -$ry - $rz;
        } elseif ($dy > $dz) {
            $ry = -$rx - $rz;
        } else {
            $rz = -$rx - $ry;
        }

        return [(int) $rx, (int) $rz];
    }

    /** Rozszerzenie znaku z 30 bitów na pełny int PHP. */
    private static function signExtend(int $v): int
    {
        return ($v & self::COORD_SIGN) ? ($v | ~self::COORD_MASK) : $v;
    }
}
