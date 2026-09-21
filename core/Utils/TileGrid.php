<?php
// core/Utils/TileGrid.php
// SIATKA KAFLI — jedyne miejsce z odwzorowaniem Web Mercator po stronie kafli.
//
// Odpowiednik Utils\DiscoveryGrid, tylko dla drugiej siatki, której serwis
// używa. Podział pracy jest ten sam: cała matematyka mieszka tutaj, renderer
// i modele dostają gotowe liczby całkowite.
//
// DLACZEGO DWIE SIATKI, A NIE JEDNA
// ---------------------------------
// Siatka heksagonalna (DiscoveryGrid) odpowiada na pytanie „gdzie ktoś był" —
// jest jednostką GRY i musi mieć pola o równej powierzchni w terenie, bo za nie
// płacimy punkty. Siatka kafli odpowiada na pytanie „co narysować w tym
// prostokącie ekranu" — jest jednostką OBRAZU i musi się zgadzać co do piksela
// z tym, czego oczekuje Leaflet. To są dwa różne wymagania i próba pogodzenia
// ich jedną siatką kończy się tym, że żadne nie jest spełnione.
//
// WSZYSTKO W PIKSELACH ŚWIATA NA POZIOMIE 18
// -------------------------------------------
// Punkt zapisujemy raz, jako parę liczb całkowitych: ile pikseli od lewej i od
// góry mapy świata narysowanej w zoomie 18. Z tego:
//   * piksel na zoomie z            = px >> (18 - z)
//   * kafel na zoomie z             = px >> (18 - z + 8)   (kafel ma 256 px)
//   * rodzic kafla                  = x >> 1
// Same przesunięcia bitowe — ani jednego sinusa w czasie rysowania. Trygonometria
// zostaje wyłącznie tutaj i wyłącznie przy ZAPISIE geometrii.
//
// Dlaczego akurat 18: 2^18 * 256 = 67 108 864, czyli 27 bitów, więc mieści się
// w INT ze znakiem z zapasem, a jeden piksel to na 52°N ok. 0,37 m — poniżej
// dokładności GPS-u w rowerze. Zejście niżej (np. 14, gdzie i tak kończymy
// generowanie) oszczędziłoby tyle samo bajtów, ale zabrałoby dokładność
// trafieniom w ślad, które liczą się z tych samych liczb.
namespace Utils;

class TileGrid
{
    /** Poziom, w którego pikselach zapisujemy geometrię. */
    public const STORE_Z = 18;

    /** Bok kafla w pikselach — stała Leafletu i całego świata kafli. */
    public const TILE = 256;

    /**
     * Najwyższy poziom, dla którego GENERUJEMY kafle.
     *
     * BYŁO 14 (do 2026-08-20), a wyżej Leaflet powiększał ostatni dostępny
     * obraz. Opisane wtedy jako „wizualnie akceptowalne" i przy śladzie GPS
     * takie było — ale nie przy ZNANEJ TRASIE, która od tej daty rysuje się
     * wyłącznie kaflami. Zgłoszenie usera: „przy maksymalnym zoomie linia jest
     * za gruba i rozmyta, jakbyśmy przy jakiejś granicy nie generowali nowych
     * kafelków". Dokładnie tak było: przy zoomie 18 oglądało się kafel z14
     * rozciągnięty ŚZESNASTOKROTNIE, więc linia o grubości 4 px robiła się
     * pasem na 64 px.
     *
     * Argument o wielkości piramidy (sam z15 to 804 000 kafli na Polskę) nie
     * zniknął — odpowiada na niego reguła NIE ZAPISUJEMY GŁĘBSZYCH NIŻ INDEX_Z
     * (patrz TileController): powstają na żądanie i lecą bez zapisu na dysk.
     * Przy takim przybliżeniu kafel obejmuje ok. 150 m terenu, więc geometrii
     * do narysowania jest w nim tyle co nic i render jest tani.
     */
    public const MAX_Z = 18;

    /** Najniższy poziom, dla którego generujemy — niżej cała Polska to plamka. */
    public const MIN_Z = 4;

    /**
     * Poziom, na którym trzymamy indeks „który ślad przez który kafel".
     *
     * Indeks ma być najdrobniejszym poziomem, jaki ZAPISUJEMY: grubsze
     * wyprowadza się z niego zakresem, a drobniejsze mieszczą się w jednym
     * jego kaflu (patrz `indexRange`, gałąź `z >= INDEX_Z`).
     *
     * ODCZEPIONY OD MAX_Z 2026-08-20. Wcześniej `= self::MAX_Z`, ale podniesienie
     * MAX_Z przesunęłoby też indeks — a ten jest ZAPISANY w `gpx_geometry.tiles`
     * dla każdego śladu w bazie. Zmiana tej stałej unieważnia tamte dane
     * i wymaga przeliczenia wszystkich śladów, więc zostaje tam, gdzie była.
     */
    public const INDEX_Z = 14;

    /** Piksel świata (poziom STORE_Z) dla współrzędnych geograficznych. */
    public static function toPixel(float $lat, float $lon): array
    {
        $n = self::TILE << self::STORE_Z;
        // Zacisk na ±85,05112878° — poza tym pasem Mercator ucieka w
        // nieskończoność, a punkt z zepsutego GPX-a wysłałby geometrię
        // w kosmos i rozdął prostokąt otaczający na cały świat.
        $lat = max(-85.05112878, min(85.05112878, $lat));
        $sin = sin(deg2rad($lat));
        return [
            (int) round(($lon + 180.0) / 360.0 * $n),
            (int) round((0.5 - log((1 + $sin) / (1 - $sin)) / (4 * M_PI)) * $n),
        ];
    }

    /** Odwrotność toPixel — potrzebna trafieniom i rysowaniu heksagonów. */
    public static function toLatLon(int $px, int $py): array
    {
        $n = self::TILE << self::STORE_Z;
        return [
            rad2deg(2 * atan(exp((0.5 - $py / $n) * 2 * M_PI)) - M_PI / 2),
            $px / $n * 360.0 - 180.0,
        ];
    }

    /**
     * Przesunięcie pikseli STORE_Z -> piksele podanego zoomu.
     * Nieujemne, więc zawsze `>> shift`: STORE_Z jest RÓWNY MAX_Z (18), czyli
     * najgłębszy rysowany kafel ma przesunięcie zero i nie ma zoomu, przy
     * którym trzeba by przesuwać w drugą stronę.
     */
    public static function shiftFor(int $z): int
    {
        return self::STORE_Z - $z;
    }

    /** Kafel, w którym leży piksel STORE_Z, na podanym zoomie. */
    public static function tileOfPixel(int $px, int $py, int $z): array
    {
        $shift = self::shiftFor($z) + 8; // 8 = log2(TILE)
        return [$px >> $shift, $py >> $shift];
    }

    /** Kafel dla współrzędnych geograficznych — skrót na toPixel + tileOfPixel. */
    public static function tileOf(float $lat, float $lon, int $z): array
    {
        [$px, $py] = self::toPixel($lat, $lon);
        return self::tileOfPixel($px, $py, $z);
    }

    /**
     * Zakres kafli poziomu INDEX_Z, które leżą wewnątrz kafla (z, x, y).
     *
     * To jest cała sztuczka, dzięki której jeden indeks obsługuje wszystkie
     * zoomy: kafel zoomu z rozpada się na 4^(INDEX_Z - z) kafli poziomu
     * indeksu, a ich numery to zwykły przedział — więc zapytanie to BETWEEN
     * na indeksowanych kolumnach, bez żadnej kolumny „rodzic".
     *
     * @return array{0:int,1:int,2:int,3:int} [txMin, txMax, tyMin, tyMax]
     */
    public static function indexRange(int $z, int $x, int $y): array
    {
        if ($z >= self::INDEX_Z) {
            // Zoom drobniejszy niż indeks: kafel mieści się w JEDNYM kaflu
            // indeksu (albo jest z nim tożsamy).
            $shift = $z - self::INDEX_Z;
            return [$x >> $shift, $x >> $shift, $y >> $shift, $y >> $shift];
        }
        $shift = self::INDEX_Z - $z;
        return [$x << $shift, (($x + 1) << $shift) - 1, $y << $shift, (($y + 1) << $shift) - 1];
    }

    /**
     * Prostokąt kafla w pikselach STORE_Z, powiększony o margines w pikselach
     * DOCELOWEGO zoomu.
     *
     * Margines jest konieczny, nie kosmetyczny: odcinek, którego oba końce leżą
     * poza kaflem, nadal może przez ten kafel przechodzić, a linia o grubości
     * 3 px wystaje półtora piksela poza swój tor. Bez zapasu kafle miałyby
     * przerwy na stykach — najbardziej widoczny objaw źle napisanego renderera.
     *
     * @return array{0:int,1:int,2:int,3:int} [pxMin, pyMin, pxMax, pyMax]
     */
    public static function pixelBounds(int $z, int $x, int $y, int $marginPx = 0): array
    {
        $shift = self::shiftFor($z);
        $size = self::TILE << $shift;
        $m = $marginPx << $shift;
        return [$x * $size - $m, $y * $size - $m, ($x + 1) * $size + $m, ($y + 1) * $size + $m];
    }

    /** Granice geograficzne kafla — dla warstw pytających bazę po lat/lon. */
    public static function latLonBounds(int $z, int $x, int $y, int $marginPx = 0): array
    {
        [$pxMin, $pyMin, $pxMax, $pyMax] = self::pixelBounds($z, $x, $y, $marginPx);
        [$north, $west] = self::toLatLon($pxMin, $pyMin);
        [$south, $east] = self::toLatLon($pxMax, $pyMax);
        return ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east];
    }

    /** Ile metrów w terenie przypada na piksel danego zoomu na danej szerokości. */
    public static function metersPerPixel(int $z, float $lat): float
    {
        return 156543.03392 * cos(deg2rad($lat)) / (2 ** $z);
    }

    /** Czy (z, x, y) to w ogóle istniejący kafel — zabezpieczenie wejścia z URL-a. */
    public static function isValid(int $z, int $x, int $y): bool
    {
        if ($z < self::MIN_Z || $z > self::MAX_Z) {
            return false;
        }
        $n = 1 << $z;
        return $x >= 0 && $x < $n && $y >= 0 && $y < $n;
    }
}
