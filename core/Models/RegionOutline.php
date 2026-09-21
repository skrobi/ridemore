<?php
// core/Models/RegionOutline.php
// GEOMETRIA REGIONÓW — model narzędzia /admin/regiony-mapa.
//
// PO CO. Region ma w tym serwisie geometrię (region_cells, migr. 070), ale
// jedyną drogą jej nadania był plik z granicami administracyjnymi
// (data/wojewodztwa.geojson → backfill_regions.php). Dla Polski to właściwe:
// województwa mają oficjalne granice. Dla kraju, którego podział ma być
// autorski i zgrubny (Czechy, Słowacja), takiego pliku nie ma — podział
// istnieje wyłącznie w głowie usera.
//
// DECYZJA (2026-09-01, po odrzuceniu pierwszej propozycji): narzędzie NIE
// wprowadza drugiego mechanizmu geometrii. Zbiera OBRYS ZEWNĘTRZNY i zapisuje
// go jako GeoJSON — czyli to, co system już czyta. Pokrycie heksami liczy
// dalej `backfill_regions.php`, tym samym kodem, w tym samym przebiegu co
// województwa. Poza plikiem `data/regiony.geojson` nie powstaje żadna nowa
// tabela, kolumna ani ścieżka danych.
//
// DWA ŹRÓDŁA, JEDNA LISTA (`allRegions()`). Narzędzie musi pokazywać WSZYSTKIE
// regiony, nie tylko własne — bez tego rysuje się na ślepo: nie widać, do czego
// się dokłada, gdzie jest biała plama i w co się wchodzi (zgłoszenie usera
// 2026-09-01: „oznaczając np. lubelskie powinno mi się na mapie zaznaczyć, bo
// nie widać"). Województwa są tu WIDOCZNE, ale NIEEDYTOWALNE: ich geometria
// pochodzi z granic administracyjnych i nadpisanie jej zgrubnym obrysem
// z ręki byłoby zamianą danych dokładnych na przybliżone.
//
// KONFLIKT ROZSTRZYGA PIERWSZEŃSTWO, NIE PRZYCINANIE POLIGONÓW. „Zawsze ma
// priorytet edytowany" (user, 2026-09-01) — dlatego każdy zapisany obrys
// dostaje `priority` (znacznik czasu zapisu), a backfill sortuje po nim
// malejąco przed przebiegiem 1. Region zapisany później zajmuje sporne heksy
// pierwszy, a `UNIQUE(cell_id)` odbiera je poprzedniemu właścicielowi — czyli
// „gdzieś dodaję, gdzieś zabieram" dzieje się na POKRYCIU, a nie przez
// przekrawanie cudzego wielokąta. Województwo, w które ktoś wejdzie obrysem,
// traci sporne heksy BEZ dotykania pliku granic.
//
// POPRAWKA POJEDYNCZEGO POLA — `assignCell()` (2026-09-10, świadoma decyzja
// usera). Obrys działa na CAŁYCH regionach: nowy wielokąt pod tym samym kodem
// ZASTĘPUJE geometrię, więc nie nadaje się do punktowej korekty pojedynczego
// heksa przy granicy województwa — mała łatka zastąpiłaby całą jego oficjalną
// granicę. `assignCell()` omija więc geometrię całkowicie i pisze wprost do
// `region_cells`/`region_cell_counts` — DRUGI, ŚWIADOMIE DOPUSZCZONY pisarz
// tych tabel obok `backfill_regions.php` (który dotąd był jedynym). Działa na
// KAŻDYM regionie, także z importu administracyjnego — to jest właśnie ta
// nowa zdolność: dotąd województwa były w tym narzędziu tylko do podglądu.
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;

class RegionOutline
{
    // Poziom siatki, po której klika się obrys i po której liczone są konflikty.
    // Wyłącznie wielkość „kroku rysowania" — na dysku lądują współrzędne, nie
    // identyfikatory pól, więc zmiana tej stałej nie unieważnia żadnych danych
    // (inaczej niż RES_CELL, zaszyty w discovery_cells).
    public const RES_OUTLINE = 2;

    // Obrys poniżej trzech heksów nie jest wielokątem, a powyżej kilku tysięcy
    // przestaje być „zgrubnym podziałem" i zaczyna być importem, który powinien
    // pójść plikiem.
    private const MIN_VERTICES = 3;
    private const MAX_VERTICES = 5000;

    // Ile pól sprawdzamy, licząc konflikt jednego obrysu. Bezpiecznik na obrys
    // wielkości pół Europy — raport ma być tani, bo liczy się przy każdym zapisie.
    private const MAX_CONFLICT_CELLS = 20000;

    /**
     * Nazwy z `data/wojewodztwa.geojson` → kody słownika (migr. 070).
     *
     * MIESZKA TU, A NIE W SKRYPCIE IMPORTU, odkąd czytają to dwie rzeczy:
     * backfill (przypisuje heksy) i to narzędzie (pokazuje województwa na
     * mapie). Dokładne stringi, nie transliteracja: jedna litera różnicy ma
     * oznaczać głośny błąd, nie ciche przypięcie złego województwa.
     */
    public const VOIVODESHIP_CODES = [
        'dolnośląskie'        => 'dolnoslaskie',
        'kujawsko-pomorskie'  => 'kujawsko-pomorskie',
        'lubelskie'           => 'lubelskie',
        'lubuskie'            => 'lubuskie',
        'łódzkie'             => 'lodzkie',
        'małopolskie'         => 'malopolskie',
        'mazowieckie'         => 'mazowieckie',
        'opolskie'            => 'opolskie',
        'podkarpackie'        => 'podkarpackie',
        'podlaskie'           => 'podlaskie',
        'pomorskie'           => 'pomorskie',
        'śląskie'             => 'slaskie',
        'świętokrzyskie'      => 'swietokrzyskie',
        'warmińsko-mazurskie' => 'warminsko-mazurskie',
        'wielkopolskie'       => 'wielkopolskie',
        'zachodniopomorskie'  => 'zachodniopomorskie',
    ];

    // Plik ze źródłem RYSOWANYCH obrysów. Podmienialny WYŁĄCZNIE po to, żeby
    // testy nie pisały po prawdziwych granicach — jedyna alternatywa (kopia
    // przed testem, przywrócenie po nim) gubi kopię, gdy test się wywali,
    // czyli dokładnie wtedy, gdy jest najbardziej potrzebna.
    private static ?string $file = null;

    public static function path(): string
    {
        return self::$file ?? CORE_PATH . '/../data/regiony.geojson';
    }

    /** Plik z granicami administracyjnymi — źródło TYLKO DO ODCZYTU. */
    public static function importedPath(): string
    {
        return CORE_PATH . '/../data/wojewodztwa.geojson';
    }

    /** Tylko dla testów. `null` przywraca plik produkcyjny. */
    public static function useFile(?string $path): void
    {
        self::$file = $path;
    }

    // ---------------------------------------------------------------
    // Odczyt
    // ---------------------------------------------------------------

    /**
     * Obrysy RYSOWANE RĘKĄ — to, co narzędzie może edytować.
     *
     * @return array<string,array{name:string,ring:array<int,array{0:float,1:float}>,priority:int}>
     *         kod słownika => nazwa + pierścień [[lat, lon], ...] BEZ punktu
     *         domykającego (dokłada go dopiero zapis do GeoJSON-a)
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::features(self::path()) as $feature) {
            $code = (string) ($feature['properties']['code'] ?? '');
            $rings = self::ringsOf($feature);
            if ($code === '' || !$rings) {
                continue;
            }
            $ring = $rings[0];
            // Punkt domykający jest wymogiem formatu, nie danymi — edytorowi
            // oddajemy listę klikniętych pól, nie listę z powtórzonym ostatnim.
            if (count($ring) > 1 && $ring[0] === $ring[count($ring) - 1]) {
                array_pop($ring);
            }
            $out[$code] = [
                'name'     => (string) ($feature['properties']['name'] ?? $code),
                'ring'     => $ring,
                'priority' => (int) ($feature['properties']['priority'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * WSZYSTKIE regiony z geometrią — rysowane ręką ORAZ zaimportowane
     * z granic administracyjnych. To jest to, co narzędzie kładzie na mapę.
     *
     * @return array<string,array{name:string,rings:array,editable:bool,priority:int}>
     *         `rings` to LISTA pierścieni [[lat, lon], ...] (MultiPolygon
     *         i dziury spłaszczone do jednej listy — dokładnie tak, jak
     *         traktuje je test „w środku" w backfillu)
     */
    public static function allRegions(): array
    {
        $out = [];

        // Najpierw import: pozycje edytowalne mają go przykryć na liście
        // (ten sam kod nie występuje w obu plikach, ale kolejność ustala też,
        // co jest rysowane pod spodem).
        foreach (self::features(self::importedPath()) as $feature) {
            $name = (string) ($feature['properties']['nazwa'] ?? '');
            $code = self::VOIVODESHIP_CODES[$name] ?? '';
            $rings = self::ringsOf($feature);
            if ($code === '' || !$rings) {
                continue;
            }
            $out[$code] = [
                'name'     => $name,
                'rings'    => $rings,
                'editable' => false,
                'priority' => 0,
            ];
        }

        foreach (self::all() as $code => $region) {
            $out[$code] = [
                'name'     => $region['name'],
                'rings'    => [$region['ring']],
                'editable' => true,
                'priority' => $region['priority'],
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------
    // Zapis
    // ---------------------------------------------------------------

    /**
     * Zapisuje obrys jednego regionu.
     *
     * @param array<int,array{0:float,1:float}> $ring [[lat, lon], ...]
     * @return array{ok:bool,error?:string,vertices?:int,priority?:int,
     *               conflicts?:array<int,array{code:string,cells:int}>}
     */
    public static function save(string $code, string $name, array $ring): array
    {
        // DOSUNIĘCIE DO SIATKI ROBI SERWER, nie przeglądarka. Wierzchołek,
        // który nie leży w środku heksa, psułby dwie rzeczy naraz: styk
        // z sąsiadem (granica rozjechałaby się o kilkaset metrów) i powrót do
        // edycji (heks pod wierzchołkiem przestałby być jednoznaczny).
        $cells = [];
        foreach ($ring as $point) {
            if (!is_array($point) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
                continue;
            }
            $lat = (float) $point[0];
            $lon = (float) $point[1];
            if ($lat < -85.0 || $lat > 85.0 || $lon < -180.0 || $lon > 180.0) {
                continue;
            }
            $cellId = DiscoveryGrid::pointToCell($lat, $lon, self::RES_OUTLINE);
            // Kolejność KLIKNIĘĆ jest kolejnością obrysu — dlatego duplikat
            // usuwamy, ale reszty nie sortujemy. Wielokąt sam z siebie nie ma
            // „właściwej" kolejności punktów; ma ją ręka, która go rysowała.
            if (!in_array($cellId, $cells, true)) {
                $cells[] = $cellId;
            }
        }

        if (count($cells) < self::MIN_VERTICES) {
            return ['ok' => false, 'error' => 'Obrys musi mieć co najmniej ' . self::MIN_VERTICES . ' pola.'];
        }
        if (count($cells) > self::MAX_VERTICES) {
            return ['ok' => false, 'error' => 'Obrys jest zbyt szczegółowy (limit ' . self::MAX_VERTICES . ' pól).'];
        }

        $nowy = array_map(static fn(int $cellId): array => DiscoveryGrid::cellCenter($cellId), $cells);

        // KONFLIKTY LICZONE PRZED ZAPISEM, na polach poziomu obrysu: user ma
        // usłyszeć „wchodzisz na lubelskie, 12 pól", a nie odkryć to dopiero
        // po imporcie, patrząc na cudzą mapę.
        $conflicts = self::conflictsFor($code, $nowy);

        $rysowane = self::all();
        $priority = time();
        $rysowane[$code] = ['name' => $name, 'ring' => $nowy, 'priority' => $priority];
        self::write($rysowane);

        return [
            'ok'        => true,
            'vertices'  => count($cells),
            'priority'  => $priority,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Przypisuje JEDNO pole siatki odkryć (poziom RES_CELL, ten sam co
     * `discovery_cells`) wprost do regionu — z pominięciem geometrii i
     * `backfill_regions.php`. Patrz uwaga na górze pliku.
     *
     * Dotyczy „poprzedniego właściciela" (jeśli był inny) i nowego naraz, żeby
     * `region_cell_counts` (mianownik pokazywany na tym samym ekranie) nie
     * czekał na najbliższy pełny import. Bez efektu, gdy pole już należy do
     * tego regionu — nie ma czego przestawiać.
     */
    public static function assignCell(int $cellId, int $regionItemId): void
    {
        $pdo = Database::connection();
        // WŁASNA TRANSAKCJA TYLKO WTEDY, GDY NIKT JEJ JESZCZE NIE OTWORZYŁ —
        // ten sam wzorzec co EventRsvp::join()/AppLoginToken::consume(): PDO
        // nie zagnieżdża beginTransaction(), a bezwarunkowe wywołanie wysadza
        // uruchamiacz testów, który każdy przypadek i tak owija we własną.
        $wlasna = !$pdo->inTransaction();
        if ($wlasna) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT region_item_id FROM region_cells WHERE cell_id = :c FOR UPDATE');
            $stmt->execute(['c' => $cellId]);
            $poprzedni = $stmt->fetchColumn();
            $poprzedni = $poprzedni !== false ? (int) $poprzedni : null;

            if ($poprzedni !== $regionItemId) {
                $pdo->prepare('
                    INSERT INTO region_cells (region_item_id, cell_id) VALUES (:r, :c)
                    ON DUPLICATE KEY UPDATE region_item_id = VALUES(region_item_id)
                ')->execute(['r' => $regionItemId, 'c' => $cellId]);

                if ($poprzedni !== null) {
                    $pdo->prepare('UPDATE region_cell_counts SET cells_total = cells_total - 1 WHERE region_item_id = :r')
                        ->execute(['r' => $poprzedni]);
                }
                $pdo->prepare('
                    INSERT INTO region_cell_counts (region_item_id, cells_total) VALUES (:r, 1)
                    ON DUPLICATE KEY UPDATE cells_total = cells_total + 1
                ')->execute(['r' => $regionItemId]);
            }

            if ($wlasna) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($wlasna && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Usuwa obrys regionu. Pokrycia (region_cells) nie rusza — patrz kontroler. */
    public static function remove(string $code): bool
    {
        $rysowane = self::all();
        if (!isset($rysowane[$code])) {
            return false;
        }
        unset($rysowane[$code]);
        self::write($rysowane);
        return true;
    }

    // ---------------------------------------------------------------
    // Geometria
    // ---------------------------------------------------------------

    /**
     * TEST „PUNKT W ZBIORZE PIERŚCIENI" (even-odd ray casting).
     *
     * Współrzędne są PARAMI (a, b) i muszą być podane w tej samej kolejności
     * co w pierścieniach — model trzyma [lat, lon], GeoJSON [lon, lat], a ta
     * funkcja obsługuje obie, bo nie wie i nie musi wiedzieć, która jest która.
     * Wołający: to narzędzie oraz `backfill_regions.php` (jedna implementacja
     * zamiast dwóch, które mogłyby się rozjechać przy tej samej granicy).
     *
     * Sumowanie przecięć po WSZYSTKICH pierścieniach daje standardową
     * semantykę dziur GeoJSON-a: punkt w nieparzystej liczbie pierścieni.
     *
     * @param array<int,array<int,array{0:float,1:float}>> $rings
     */
    public static function insideRings(array $rings, float $a, float $b): bool
    {
        $total = 0;
        foreach ($rings as $ring) {
            $n = count($ring);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$a1, $b1] = $ring[$j];
                [$a2, $b2] = $ring[$i];
                if (($b1 > $b) !== ($b2 > $b)
                    && $a < ($a2 - $a1) * ($b - $b1) / ($b2 - $b1) + $a1) {
                    $total++;
                }
            }
        }
        return $total % 2 === 1;
    }

    /**
     * Pola poziomu obrysu leżące wewnątrz pierścienia (razem z polami samego
     * obrysu — ich środki leżą na granicy i test „w środku" bywa dla nich
     * fałszywy, a należą do regionu bezspornie).
     *
     * @param array<int,array{0:float,1:float}> $ring [[lat, lon], ...]
     * @return array<int,int> cell_id
     */
    public static function cellsInside(array $ring): array
    {
        $lats = array_column($ring, 0);
        $lons = array_column($ring, 1);
        $range = DiscoveryGrid::axialRangeForBounds(
            min($lats),
            min($lons),
            max($lats),
            max($lons),
            self::RES_OUTLINE
        );

        $out = [];
        foreach ($ring as $point) {
            $out[DiscoveryGrid::pointToCell($point[0], $point[1], self::RES_OUTLINE)] = true;
        }

        for ($q = $range['qMin']; $q <= $range['qMax']; $q++) {
            for ($r = $range['rMin']; $r <= $range['rMax']; $r++) {
                if (count($out) > self::MAX_CONFLICT_CELLS) {
                    break 2;
                }
                $cellId = DiscoveryGrid::encode(self::RES_OUTLINE, $q, $r);
                if (isset($out[$cellId])) {
                    continue;
                }
                [$lat, $lon] = DiscoveryGrid::cellCenter($cellId);
                if (self::insideRings([$ring], $lat, $lon)) {
                    $out[$cellId] = true;
                }
            }
        }
        return array_map('intval', array_keys($out));
    }

    /**
     * Które regiony i ile pól traci obrys zapisywany pod kodem `$code`.
     *
     * @param array<int,array{0:float,1:float}> $ring
     * @return array<int,array{code:string,name:string,cells:int}>
     */
    public static function conflictsFor(string $code, array $ring): array
    {
        $moje = self::cellsInside($ring);
        if (!$moje) {
            return [];
        }

        $srodki = array_map(
            static fn(int $cellId): array => DiscoveryGrid::cellCenter($cellId),
            $moje
        );

        $out = [];
        foreach (self::allRegions() as $innyKod => $inny) {
            if ($innyKod === $code) {
                continue;
            }
            $bbox = self::bboxOf($inny['rings']);
            $n = 0;
            foreach ($srodki as [$lat, $lon]) {
                if ($lat < $bbox['s'] || $lat > $bbox['n'] || $lon < $bbox['w'] || $lon > $bbox['e']) {
                    continue;
                }
                if (self::insideRings($inny['rings'], $lat, $lon)) {
                    $n++;
                }
            }
            if ($n > 0) {
                $out[] = ['code' => $innyKod, 'name' => $inny['name'], 'cells' => $n];
            }
        }

        usort($out, static fn(array $a, array $b): int => $b['cells'] <=> $a['cells']);
        return $out;
    }

    /** @param array<int,array<int,array{0:float,1:float}>> $rings */
    private static function bboxOf(array $rings): array
    {
        $bbox = ['s' => INF, 'n' => -INF, 'w' => INF, 'e' => -INF];
        foreach ($rings as $ring) {
            foreach ($ring as [$lat, $lon]) {
                $bbox['s'] = min($bbox['s'], $lat);
                $bbox['n'] = max($bbox['n'], $lat);
                $bbox['w'] = min($bbox['w'], $lon);
                $bbox['e'] = max($bbox['e'], $lon);
            }
        }
        return $bbox;
    }

    // ---------------------------------------------------------------
    // Plik
    // ---------------------------------------------------------------

    /** @return array<int,array> features albo pusta lista (brak pliku to nie błąd) */
    private static function features(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $geo = json_decode((string) file_get_contents($file), true);
        return is_array($geo['features'] ?? null) ? $geo['features'] : [];
    }

    /**
     * Pierścienie feature'a jako [[lat, lon], ...] — Polygon i MultiPolygon
     * spłaszczone do jednej listy (dziury zostają osobnymi pierścieniami,
     * bo `insideRings` liczy je even-odd).
     *
     * @return array<int,array<int,array{0:float,1:float}>>
     */
    private static function ringsOf(array $feature): array
    {
        $geom = $feature['geometry'] ?? null;
        if (!is_array($geom) || !isset($geom['coordinates'])) {
            return [];
        }
        $polygons = ($geom['type'] ?? '') === 'MultiPolygon'
            ? $geom['coordinates']
            : [$geom['coordinates']];

        $out = [];
        foreach ($polygons as $polygon) {
            foreach ($polygon as $ring) {
                $punkty = [];
                foreach ($ring as $point) {
                    if (!is_array($point) || !isset($point[0], $point[1])) {
                        continue;
                    }
                    // GeoJSON trzyma [lon, lat]; aplikacja i Leaflet — [lat, lon].
                    $punkty[] = [(float) $point[1], (float) $point[0]];
                }
                if (count($punkty) >= 3) {
                    $out[] = $punkty;
                }
            }
        }
        return $out;
    }

    /**
     * Zapis całego pliku RYSOWANYCH obrysów.
     *
     * PRZEZ PLIK TYMCZASOWY I rename(): plik jest źródłem geometrii dla
     * backfillu, a zapis przerwany w połowie zostawiłby uszkodzony JSON —
     * czyli import, który przestaje działać, i obrysy, których nie da się
     * odzyskać inaczej niż rysując je drugi raz.
     *
     * @param array<string,array{name:string,ring:array,priority:int}> $regiony
     */
    private static function write(array $regiony): void
    {
        $features = [];
        foreach ($regiony as $code => $region) {
            $ring = $region['ring'];
            if (count($ring) < self::MIN_VERTICES) {
                continue;
            }
            $coords = array_map(
                static fn(array $p): array => [round($p[1], 6), round($p[0], 6)], // [lon, lat]
                $ring
            );
            $coords[] = $coords[0]; // GeoJSON wymaga domknięcia pierścienia

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    // `code` czyta backfill_regions.php i po nim (a nie po nazwie)
                    // odnajduje pozycję słownika.
                    'code' => $code,
                    'name' => $region['name'],
                    // PIERWSZEŃSTWO PRZY SPORNYCH HEKSACH — backfill sortuje po
                    // tym malejąco, więc obrys zapisany później zabiera sporne
                    // pola wcześniejszemu (i województwu, które ma 0).
                    'priority' => (int) ($region['priority'] ?? 0),
                ],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [$coords]],
            ];
        }

        $json = json_encode(
            ['type' => 'FeatureCollection', 'features' => $features],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        $file = self::path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $file . '.tmp';
        file_put_contents($tmp, $json);
        rename($tmp, $file);
    }
}
