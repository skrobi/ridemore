<?php
// core/Models/GpxGeometry.php
// GEOMETRIA PLIKU GPX W POSTACI, KTÓREJ POTRZEBUJE RENDERER KAFLI (migr. 051).
//
// Jedno parsowanie na plik, na zawsze. Klucz to hash ZAWARTOŚCI — ta sama
// decyzja i te same powody co w Models\RoutePreview (migr. 050): ten sam ślad
// bywa podpięty jako etap, wariant i znana trasa, a plik jest niezmienny, bo
// podmiana zapisuje się pod nową nazwą.
//
// CO TU JEST, A CZEGO NIE MA
// --------------------------
// Jest: punkty w pikselach świata (poziom TileGrid::STORE_Z), prostokąt
// otaczający i lista kafli, przez które ślad przechodzi.
// Nie ma: wysokości, czasów, dystansu. To wszystko żyje w Utils\Gpx i w
// kolumnach edition_tracks — tutaj wchodzi wyłącznie to, co jest potrzebne, żeby
// NARYSOWAĆ i ŻEBY TRAFIĆ. Gdyby wchodziło więcej, ta tabela byłaby drugą kopią
// prawdy o trasie i pierwsza rozbieżność byłaby kwestią czasu.
//
// DLACZEGO BLOB, A NIE TABELA PUNKTÓW
// -----------------------------------
// 600 śladów to 1,26 mln punktów. Jako wiersze byłoby to 1,26 mln odczytów przy
// każdym kaflu; jako blob — jedno I/O i jeden unpack(). Zmierzone na prawdziwym
// pliku: 179 864 B GPX -> 16 856 B bloba (11x mniej).
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;
use Utils\Gpx;
use Utils\TileGrid;
use Utils\TrackPalette;

class GpxGeometry
{
    /**
     * Zapewnia geometrię dla pliku i zwraca jego hash.
     *
     * Idempotentne przez klucz główny tabeli, nie przez sprawdzenie w kodzie:
     * dwa równoległe żądania na ten sam nowy plik obie próby zapiszą, druga
     * odbije się o INSERT IGNORE. Ta sama zasada co w całym Discovery.
     *
     * @return string|null hash pliku; null, gdy pliku nie ma lub jest nieczytelny
     */
    public static function ensure(string $absolutePath): ?string
    {
        $hash = self::fileHash($absolutePath);
        if ($hash === null) {
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT 1 FROM gpx_geometry WHERE gpx_hash = :h');
        $stmt->execute(['h' => $hash]);
        if ($stmt->fetchColumn() !== false) {
            return $hash;
        }

        try {
            // PRÓG DŁUGIEJ TRASY, bo to jest RYSOWANIE pliku, który już leży na
            // dysku i przeszedł walidację przy wgraniu — nie drugi upload.
            // Przy domyślnym progu 1000 km szlak w rodzaju Green Velo (1892 km)
            // wpadłby w catch niżej i zapisał się jako PUSTA geometria, czyli
            // zniknąłby z mapy na stałe (wiersz jest cache'em po hashu pliku).
            $points = Gpx::parse($absolutePath, Gpx::LONG_ROUTE_MAX_DISTANCE_KM)['points'];
        } catch (\Throwable $e) {
            // Plik nieczytelny zapisujemy jako PUSTĄ geometrię, a nie pomijamy:
            // bez wiersza próbowalibyśmy go parsować przy każdym kaflu, na
            // którym mógłby się znaleźć. Pusty wiersz znaczy „policzone, nie ma
            // czego rysować" — dokładnie jak gpx_route_cell_runs w migr. 050.
            $db->prepare(
                'INSERT IGNORE INTO gpx_geometry
                    (gpx_hash, point_count, min_px, min_py, max_px, max_py, points)
                 VALUES (:h, 0, 0, 0, 0, 0, NULL)'
            )->execute(['h' => $hash]);
            return $hash;
        }

        // Rzutowanie robimy RAZ, do płaskiej tablicy pikseli — wszystko dalej
        // (pakowanie, prostokąt, indeks kafli) liczy się już z niej.
        $px = [];
        foreach ($points as $p) {
            $px[] = TileGrid::toPixel((float) $p['lat'], (float) $p['lon']);
        }

        $packed = '';
        $minPx = PHP_INT_MAX; $minPy = PHP_INT_MAX; $maxPx = 0; $maxPy = 0;
        foreach ($px as [$x, $y]) {
            // 'V' zamiast 'l': jawny little-endian zamiast kolejności bajtów
            // maszyny. Piksele świata są nieujemne, więc bez znaku, a cache
            // zapisany na jednej architekturze da się odczytać na innej.
            $packed .= pack('VV', $x, $y);
            if ($x < $minPx) { $minPx = $x; }
            if ($y < $minPy) { $minPy = $y; }
            if ($x > $maxPx) { $maxPx = $x; }
            if ($y > $maxPy) { $maxPy = $y; }
        }

        $tiles = self::tileSet($px);

        // ZAPIS ATOMOWY WZGLĘDEM CZYTELNIKÓW (2026-09-07, zgłoszenie usera: znany
        // szlak „urywa się" na części kafli, zależnie od zoomu). Bez transakcji
        // wiersz `gpx_geometry` commituje się PRZED tym, jak `gpx_tiles` zdąży
        // wstawić WSZYSTKIE porcje (długi szlak to kilka INSERT-ów po 500
        // wierszy) — a `ensure()` wołane RÓWNOLEGLE dla innego kafla TEGO SAMEGO
        // nowego pliku (kafle jednego kadru idą dziś naprawdę równolegle, patrz
        // `Core\Session::release()` w TileController — to ta sama naprawa, która
        // ten wyścig uczyniła częstym) widzi w tej szczelinie SAM wiersz
        // `gpx_geometry`, bierze „krótką ścieżkę" (return na samej górze metody)
        // i pyta `inTile()` o kafel, którego `gpx_tiles` jeszcze nie ma kompletu.
        // Renderer dostaje pusty zestaw grup i rysuje `TileRenderer::blank()` —
        // a TO ląduje na dysku z `Cache-Control: immutable`, czyli dziura
        // w linii NA ZAWSZE, dokładnie na kaflu, który trafił w wyścig. Transakcja
        // sprawia, że żaden czytelnik nie zobaczy `gpx_geometry` przed COMMITEM,
        // czyli przed kompletem `gpx_tiles`.
        // WŁASNA TRANSAKCJA TYLKO WTEDY, GDY NIKT JEJ JESZCZE NIE OTWORZYŁ — PDO
        // nie zagnieżdża `beginTransaction()`, a `tests/run.php` owija każdy
        // przypadek testowy we własną (ten sam wzorzec co `AppLoginToken::consume`).
        $wlasna = !$db->inTransaction();
        if ($wlasna) {
            $db->beginTransaction();
        }
        try {
            $db->prepare(
                'INSERT IGNORE INTO gpx_geometry
                    (gpx_hash, point_count, min_px, min_py, max_px, max_py, points)
                 VALUES (:h, :n, :m1, :m2, :m3, :m4, :pts)'
            )->execute([
                'h'   => $hash,
                'n'   => count($points),
                'm1'  => $minPx,
                'm2'  => $minPy,
                'm3'  => $maxPx,
                'm4'  => $maxPy,
                'pts' => $packed,
            ]);

            foreach (array_chunk(array_values($tiles), 500) as $chunk) {
                $values = [];
                foreach ($chunk as [$tx, $ty]) {
                    $values[] = '(' . $db->quote($hash) . ',' . (int) $tx . ',' . (int) $ty . ')';
                }
                $db->exec('INSERT IGNORE INTO gpx_tiles (gpx_hash, tx, ty) VALUES ' . implode(',', $values));
            }

            if ($wlasna) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($wlasna && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        // KOLOR PO KAFLACH, NIE PRZED — przydział pyta właśnie o `gpx_tiles`
        // („co leży w tych samych kaflach"), więc przed tą pętlą ślad nie
        // miałby jeszcze żadnych sąsiadów i każdy dostawałby indeks 0.
        self::assignColor($hash);

        return $hash;
    }

    // ---------------------------------------------------------------
    // Naprawa STARYCH szkód po wyścigu sprzed transakcji (2026-09-07)
    // ---------------------------------------------------------------
    //
    // Transakcja w ensure()/storeTrimmed() wyżej zapobiega TYLKO NOWYM
    // przypadkom. Ślad, który padł w wyścig ZANIM ta poprawka trafiła do
    // kodu, ma już wiersz w `gpx_geometry`(`_trimmed`) — więc `ensure()`
    // bierze dla niego „krótką ścieżkę" NA ZAWSZE i nigdy więcej nie
    // spróbuje dopisać brakujących kafli-indeksu. Zgłoszenie usera
    // (produkcja): nowy użytkownik zaimportował naraz wiele przejazdów,
    // a jeden z jego śladów „urywa się" — widać go na zoomie 8, nie widać
    // kawałka na zoomie 9. Nie da się poprosić kogoś o ponowne wgranie
    // przejazdu, którego już nie ma jak odtworzyć z tej strony (plik leży
    // u niego na liczniku, nie na naszym dysku pod inną nazwą) — więc
    // naprawa MUSI działać z tego, co już jest zapisane w bazie.

    /**
     * Uzupełnia brakujące wiersze `gpx_tiles` dla śladów, które MAJĄ już
     * policzoną geometrię, ale ich indeks kafli jest niekompletny.
     *
     * NIE CZYTA PLIKU GPX I NICZEGO NIE PARSUJE — liczy `tileSet()`
     * z punktów już zapisanych w `gpx_geometry.points` (dokładnie to samo
     * źródło, z którego renderer bierze linię do narysowania) i dopisuje
     * WYŁĄCZNIE te (tx, ty), których jeszcze nie ma (`INSERT IGNORE`, więc
     * bezpiecznie powtarzalne — uruchomienie na już naprawionym śladzie nic
     * nie zmienia). To jest jedyny sposób naprawić ślad, którego oryginalny
     * plik jest poza naszą kontrolą (przejazd solo osoby trzeciej, licznik
     * dawno zsynchronizowany) — geometria w bazie jest KOMPLETNA i POPRAWNA
     * (parsowanie się powiodło), zepsuty jest wyłącznie indeks kafli.
     *
     * Po naprawie trzeba jeszcze skasować kafle PNG dotkniętego śladu —
     * stare, dziurawe kopie leżą na dysku z `Cache-Control: immutable`
     * i naprawiony indeks im nie pomoże, dopóki ktoś ich nie podmieni
     * (wołający: `TilesController::repairIndex`/`php tiles.php repair`
     * kasują całą warstwę `slady`, tak jak `colors()` robi to po
     * przydzieleniu kolorów — z tego samego powodu).
     *
     * @return array{sprawdzone:int, naprawione:int, dopisanych_wierszy:int}
     */
    public static function repairTileIndex(?callable $log = null): array
    {
        return self::repairTileIndexFor('gpx_geometry', 'gpx_tiles', $log);
    }

    /** Bliźniak wyżej dla `gpx_geometry_trimmed`/`gpx_tiles_trimmed` (solo w heatmapie). */
    public static function repairTrimmedTileIndex(?callable $log = null): array
    {
        return self::repairTileIndexFor('gpx_geometry_trimmed', 'gpx_tiles_trimmed', $log);
    }

    /** @return array{sprawdzone:int, naprawione:int, dopisanych_wierszy:int} */
    private static function repairTileIndexFor(string $geomTable, string $tilesTable, ?callable $log): array
    {
        $db = Database::connection();
        $sprawdzone = 0;
        $naprawione = 0;
        $dopisanychWierszy = 0;

        // WSZYSTKO NARAZ, nie po jednym wierszu: te same rozmiary co przy
        // `backfillColors()` (graf sąsiedztwa całego zbioru jednym
        // zapytaniem) — dziesiątki/setki śladów na admina, który klika ten
        // przycisk raz po zgłoszeniu, nie tysiące razy dziennie.
        $rows = $db->query("SELECT gpx_hash, points FROM $geomTable WHERE points IS NOT NULL")->fetchAll();

        foreach ($rows as $row) {
            $sprawdzone++;
            $hash = $row['gpx_hash'];

            $flat = array_values(unpack('V*', $row['points']));
            $px = [];
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $px[] = [$flat[$i], $flat[$i + 1]];
            }
            $oczekiwane = self::tileSet($px);

            $obecne = $db->prepare("SELECT tx, ty FROM $tilesTable WHERE gpx_hash = :h");
            $obecne->execute(['h' => $hash]);
            foreach ($obecne->fetchAll() as $r) {
                // TEN SAM klucz co w tileSet() — inaczej „już mamy ten kafel"
                // nigdy by nie trafiło, i naprawa dopisywałaby duplikaty
                // (nieszkodliwie, dzięki INSERT IGNORE, ale bez sensu).
                unset($oczekiwane[((int) $r['tx'] << 32) | (int) $r['ty']]);
            }

            if (!$oczekiwane) {
                continue; // indeks już kompletny — nic do dopisania
            }

            $naprawione++;
            $dopisanychWierszy += count($oczekiwane);
            foreach (array_chunk(array_values($oczekiwane), 500) as $chunk) {
                $values = [];
                foreach ($chunk as [$tx, $ty]) {
                    $values[] = '(' . $db->quote($hash) . ',' . (int) $tx . ',' . (int) $ty . ')';
                }
                $db->exec("INSERT IGNORE INTO $tilesTable (gpx_hash, tx, ty) VALUES " . implode(',', $values));
            }

            if ($log) {
                $log(sprintf('  naprawiono %s… (+%d kafli)', substr($hash, 0, 12), count($oczekiwane)));
            }
        }

        return ['sprawdzone' => $sprawdzone, 'naprawione' => $naprawione, 'dopisanych_wierszy' => $dopisanychWierszy];
    }

    // ---------------------------------------------------------------
    // Kolor śladu (migr. 073)
    // ---------------------------------------------------------------

    /**
     * Przydział koloru JEDNEMU śladowi — po policzeniu jego geometrii.
     *
     * ZGŁOSZENIE USERA (2026-08-27): „wszystkie te trasy są wygenerowane
     * w kolorze zielonym (…) w pierwotnym założeniu jakiekolwiek ślady tras
     * miały mieć różne kolory, tak aby w przypadku nakładających się śladów
     * rozróżnić je kolorami (…) mam jedną wielką zieloną plamę".
     *
     * ZASADA jest ta sama co przy znanych trasach (migr. 064) i celowo idzie
     * tym samym kodem (`Utils\TrackPalette::pick`): bierzemy kolor, którego
     * nie ma żaden ślad leżący w pobliżu, a przy remisie ten najrzadszy
     * w całym zbiorze.
     *
     * SĄSIEDZTWO LICZYMY PO WSPÓLNYCH KAFLACH INDEKSU (`gpx_tiles`), a nie po
     * geometrii z plików. Trzy powody, wszystkie praktyczne: (1) ten indeks
     * ślad i tak ma policzony, (2) leży pod gotowym indeksem `(tx, ty)`, więc
     * pytanie jest jednym złączeniem, (3) to DOKŁADNIE ta sama miara, której
     * używa renderer — dwa ślady dzielące kafel to dwa ślady, które trafią na
     * ten sam obrazek, czyli te, które naprawdę mogą się na sobie położyć.
     * Liczenie odległości z punktów GPX znaczyłoby parsowanie plików tylko po
     * to, żeby dobrać kolor.
     *
     * KOLORÓW SĄSIADÓW NIE RUSZAMY — to jest sedno rozwiązania, przejęte
     * wprost z `KnownRoute::assignColor`. Kafle są plikami na dysku,
     * unieważnianymi po ŚLADZIE (`TileCache::invalidateTrack`), więc
     * przemalowanie sąsiada wymagałoby skasowania kafli także na JEGO
     * przebiegu, a przemalowanie sąsiada sąsiada — lawinowo dalej. Przydział
     * przyrostowy daje ten sam efekt tam, gdzie jest potrzebny (nowy ślad
     * różni się od tego, co już leży obok), bez ruszania niczego, co jest już
     * narysowane.
     */
    public static function assignColor(string $hash): void
    {
        self::setColor($hash, TrackPalette::pick(self::neighbourColors($hash), self::colorUsage()));
    }

    /**
     * Kolory śladów dzielących z tym choć jeden kafel indeksu.
     *
     * @return int[] indeksy kolorów, z powtórzeniami (powtórzenie JEST treścią
     *               — patrz `TrackPalette::pick`)
     */
    private static function neighbourColors(string $hash): array
    {
        $stmt = Database::connection()->prepare('
            SELECT g.color_index
              FROM gpx_tiles a
              JOIN gpx_tiles b ON b.tx = a.tx AND b.ty = a.ty AND b.gpx_hash <> a.gpx_hash
              JOIN gpx_geometry g ON g.gpx_hash = b.gpx_hash AND g.color_index IS NOT NULL
             WHERE a.gpx_hash = :h
             GROUP BY b.gpx_hash, g.color_index
        ');
        $stmt->execute(['h' => $hash]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Ile śladów ma który kolor — do rozkładania palety po całym zbiorze. */
    private static function colorUsage(): array
    {
        $rows = Database::connection()->query(
            'SELECT color_index, COUNT(*) AS ile FROM gpx_geometry
              WHERE color_index IS NOT NULL GROUP BY color_index'
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['color_index']] = (int) $row['ile'];
        }
        return $out;
    }

    private static function setColor(string $hash, int $colorIndex): void
    {
        Database::connection()
            ->prepare('UPDATE gpx_geometry SET color_index = :c WHERE gpx_hash = :h')
            ->execute(['c' => $colorIndex, 'h' => $hash]);
    }

    /**
     * Kolory podanych śladów — jedno zapytanie dla całego kafla.
     *
     * @param string[] $hashes
     * @return array<string,int> hash => indeks koloru (bez wpisu dla śladów
     *         bez przydziału; wołający spada wtedy na kolor stylu)
     */
    public static function colorsFor(array $hashes): array
    {
        $hashes = self::sanitize($hashes);
        if (!$hashes) {
            return [];
        }
        $in = "'" . implode("','", $hashes) . "'";
        $rows = Database::connection()->query(
            "SELECT gpx_hash, color_index FROM gpx_geometry
              WHERE gpx_hash IN ($in) AND color_index IS NOT NULL"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['gpx_hash']] = (int) $r['color_index'];
        }
        return $out;
    }

    /**
     * Pokolorowanie WSZYSTKIEGO, co koloru jeszcze nie ma — backfill migr. 073.
     *
     * Idzie od śladów o NAJWIĘKSZEJ liczbie sąsiadów. Zachłanne kolorowanie
     * grafu w tej kolejności wyczerpuje paletę najrzadziej: gdyby zacząć od
     * śladów samotnych, te z zatłoczonego korytarza dostawałyby resztki.
     * (Ta sama kolejność i ten sam powód co w `KnownRoute::assignAllColors`.)
     *
     * IDEMPOTENTNY — rusza wyłącznie wiersze bez koloru, więc powtórne
     * uruchomienie niczego nie przemalowuje, a to znaczy, że nie unieważnia
     * kafli, których nikt nie kazał unieważniać.
     *
     * KAFLI TU NIE KASUJEMY po jednym śladzie — inaczej niż przy znanych
     * trasach. Backfill dotyka NARAZ wszystkiego, co jest na mapie, więc
     * unieważnianie per ślad znaczyłoby przejście po tych samych kaflach
     * tyle razy, ile śladów je dotyka. Wołający kasuje CAŁĄ warstwę raz
     * (`TileCache::purgeLayer`) — patrz `TilesController::colors`.
     *
     * @return array{colored:int, skipped:int}
     */
    public static function backfillColors(?callable $log = null): array
    {
        $db = Database::connection();

        // Graf sąsiedztwa CAŁEGO zbioru jednym zapytaniem — pytanie o sąsiadów
        // osobno dla każdego śladu dawałoby N przejść po tej samej tabeli.
        $sasiedzi = [];
        foreach ($db->query('
            SELECT DISTINCT a.gpx_hash AS a, b.gpx_hash AS b
              FROM gpx_tiles a
              JOIN gpx_tiles b ON b.tx = a.tx AND b.ty = a.ty AND b.gpx_hash <> a.gpx_hash
        ')->fetchAll() as $row) {
            $sasiedzi[$row['a']][] = $row['b'];
        }

        $kolory = [];
        $doPokolorowania = [];
        foreach ($db->query('SELECT gpx_hash, color_index FROM gpx_geometry')->fetchAll() as $row) {
            if ($row['color_index'] !== null) {
                $kolory[$row['gpx_hash']] = (int) $row['color_index'];
                continue;
            }
            $doPokolorowania[] = $row['gpx_hash'];
        }

        // Malejąco po liczbie sąsiadów; przy remisie po hashu, żeby dwa
        // przebiegi na tej samej bazie dały te same kolory.
        usort($doPokolorowania, static function (string $a, string $b) use ($sasiedzi): int {
            $ra = count($sasiedzi[$a] ?? []);
            $rb = count($sasiedzi[$b] ?? []);
            return $ra === $rb ? strcmp($a, $b) : $rb <=> $ra;
        });

        $uzycie = array_count_values($kolory);
        $pokolorowane = 0;
        foreach ($doPokolorowania as $hash) {
            $zajete = [];
            foreach ($sasiedzi[$hash] ?? [] as $sasiad) {
                if (isset($kolory[$sasiad])) {
                    $zajete[] = $kolory[$sasiad];
                }
            }
            $kolor = TrackPalette::pick($zajete, $uzycie);
            self::setColor($hash, $kolor);
            $kolory[$hash] = $kolor;
            $uzycie[$kolor] = ($uzycie[$kolor] ?? 0) + 1;
            $pokolorowane++;

            if ($log) {
                $log(sprintf(
                    '  %s… -> %s (sąsiadów: %d)',
                    substr($hash, 0, 12),
                    TrackPalette::COLORS[$kolor],
                    count($sasiedzi[$hash] ?? [])
                ));
            }
        }

        return ['colored' => $pokolorowane, 'skipped' => count($kolory) - $pokolorowane];
    }

    /** Ile śladów czeka jeszcze na kolor — liczba do panelu kafli. */
    public static function missingColorCount(): int
    {
        return (int) Database::connection()
            ->query('SELECT COUNT(*) FROM gpx_geometry WHERE color_index IS NULL AND points IS NOT NULL')
            ->fetchColumn();
    }

    /**
     * Zbiór kafli poziomu indeksu, przez które przechodzi ślad — z punktami
     * POMIĘDZY punktami włącznie.
     *
     * Uzupełnianie przerw nie jest ozdobnikiem: punkty GPS bywają rzadkie
     * (zjazd z górki co 10 s to ponad 200 m), a kafel z14 ma ok. 300 m
     * w poprzek. Bez tego ślad „przeskakiwałby" kafle, te wypadłyby z indeksu
     * i renderer nigdy by ich nie narysował — dziura w linii dokładnie tam,
     * gdzie jechało się najszybciej.
     *
     * Interpolacja jest liniowa w pikselach, bo w Mercatorze odcinek prosty na
     * ekranie jest prosty również we współrzędnych pikselowych.
     *
     * @param array<int,array{0:int,1:int}> $px
     * @return array<int,array{0:int,1:int}>
     */
    private static function tileSet(array $px): array
    {
        $shift = TileGrid::shiftFor(TileGrid::INDEX_Z) + 8;
        $tiles = [];
        $prev = null;
        foreach ($px as [$x, $y]) {
            $tx = $x >> $shift; $ty = $y >> $shift;
            $tiles[($tx << 32) | $ty] = [$tx, $ty];
            if ($prev !== null) {
                $steps = max(abs($tx - ($prev[0] >> $shift)), abs($ty - ($prev[1] >> $shift)));
                // Krok po kroku tylko wtedy, gdy odcinek naprawdę przeskakuje
                // kafle. Limit 64 chroni przed plikiem z jednym punktem
                // w Polsce i drugim w Australii (zdarza się przy sklejaniu
                // śladów) — taki odcinek i tak nie niesie informacji, a bez
                // limitu wygenerowałby kilkanaście tysięcy wierszy indeksu.
                if ($steps > 1 && $steps <= 64) {
                    for ($i = 1; $i < $steps; $i++) {
                        $ix = ((int) round($prev[0] + ($x - $prev[0]) * $i / $steps)) >> $shift;
                        $iy = ((int) round($prev[1] + ($y - $prev[1]) * $i / $steps)) >> $shift;
                        $tiles[($ix << 32) | $iy] = [$ix, $iy];
                    }
                }
            }
            $prev = [$x, $y];
        }
        return $tiles;
    }

    /**
     * Zawęża listę hashy do tych, które w ogóle dotykają danego kafla.
     *
     * To jest ten odsiew, dzięki któremu kafel kosztuje tyle samo przy 6 i przy
     * 6000 śladów. Zapytanie idzie po indeksie (tx, ty) zakresem wyliczonym
     * z TileGrid::indexRange — jeden poziom indeksu obsługuje wszystkie zoomy,
     * bo kafle są zagnieżdżone przez przesunięcie bitowe.
     *
     * @param string[] $hashes
     * @return string[]
     */
    public static function inTile(array $hashes, int $z, int $x, int $y): array
    {
        $hashes = self::sanitize($hashes);
        if (!$hashes) {
            return [];
        }
        [$txMin, $txMax, $tyMin, $tyMax] = TileGrid::indexRange($z, $x, $y);

        $db = Database::connection();
        $in = "'" . implode("','", $hashes) . "'";
        $stmt = $db->prepare(
            "SELECT DISTINCT gpx_hash FROM gpx_tiles
              WHERE gpx_hash IN ($in)
                AND tx BETWEEN :tx1 AND :tx2
                AND ty BETWEEN :ty1 AND :ty2"
        );
        $stmt->execute(['tx1' => $txMin, 'tx2' => $txMax, 'ty1' => $tyMin, 'ty2' => $tyMax]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Ślady przechodzące przez podane kafle indeksu (INDEX_Z) — ile z tych
     * kafli trafia każdy hash. Planer tras (Źródła trasy, 2026-09-18) szuka tak
     * kandydatów w KORYTARZU trasy, a nie w całym prostokącie: przekątna trasa
     * przez pół województwa ma prostokąt pełen śladów, które jej nie dotykają.
     * Jedno zapytanie po (tx, ty) zakresem, dokładny odsiew po zbiorze kafli
     * w PHP — ten sam indeks, którym karmi się renderer kafli.
     *
     * @param array<int,true> $tiles klucze (tx << 32) | ty
     * @param bool $trimmed czytać przyciętą parę tabel (solo w warstwie społeczności)
     * @return array<string,int> hash => liczba trafionych kafli
     */
    public static function hitsInTiles(array $tiles, bool $trimmed = false): array
    {
        if (!$tiles) {
            return [];
        }
        $txs = [];
        $tys = [];
        foreach (array_keys($tiles) as $key) {
            $txs[] = $key >> 32;
            $tys[] = $key & 0xFFFFFFFF;
        }
        $table = $trimmed ? 'gpx_tiles_trimmed' : 'gpx_tiles';
        $stmt = Database::connection()->prepare(
            "SELECT gpx_hash, tx, ty FROM $table
              WHERE tx BETWEEN :tx1 AND :tx2 AND ty BETWEEN :ty1 AND :ty2"
        );
        $stmt->execute(['tx1' => min($txs), 'tx2' => max($txs), 'ty1' => min($tys), 'ty2' => max($tys)]);

        $hits = [];
        while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            if (isset($tiles[((int) $row[1] << 32) | (int) $row[2]])) {
                $hits[$row[0]] = ($hits[$row[0]] ?? 0) + 1;
            }
        }
        return $hits;
    }

    /**
     * Rozpakowana geometria dla podanych hashy.
     *
     * @param string[] $hashes
     * @return array<string,array{pts:array<int,int>,minPx:int,minPy:int,maxPx:int,maxPy:int}>
     *         pts to płaska tablica [px, py, px, py, ...] w pikselach STORE_Z
     */
    public static function load(array $hashes): array
    {
        $hashes = self::sanitize($hashes);
        if (!$hashes) {
            return [];
        }
        $in = "'" . implode("','", $hashes) . "'";
        $rows = Database::connection()
            ->query("SELECT gpx_hash, min_px, min_py, max_px, max_py, points
                       FROM gpx_geometry WHERE gpx_hash IN ($in)")
            ->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            if ($r['points'] === null || $r['points'] === '') {
                continue;
            }
            // unpack zwraca tablicę indeksowaną od 1 — array_values() daje
            // płaską [px, py, px, py, ...], po której renderer idzie krokiem 2.
            $out[$r['gpx_hash']] = [
                'pts'   => array_values(unpack('V*', $r['points'])),
                'minPx' => (int) $r['min_px'],
                'minPy' => (int) $r['min_py'],
                'maxPx' => (int) $r['max_px'],
                'maxPy' => (int) $r['max_py'],
            ];
        }
        return $out;
    }

    /**
     * Bucket i próg klastrowania kadru — TA SAMA naprawa i ta sama wartość co
     * `Models\Discovery::CLUSTER_BUCKET_KM`/`CLUSTER_TRIGGER_KM` (2026-08-27,
     * zgłoszenie usera: „sprawdź to samo dla profilu rowerzysty"). Osobna
     * stała, nie import z Discovery — inny system współrzędnych (piksele
     * Merkatora, nie osie heksagonu), więc liczy się inaczej, ale ODPOWIADA
     * na to samo pytanie: „czy naiwny prostokąt jest skażony odległym
     * wyjazdem". Patrz `boundsFor()` niżej po pełne uzasadnienie.
     */
    private const CLUSTER_BUCKET_KM = 150;

    private const CLUSTER_TRIGGER_KM = 1000;

    /**
     * Wspólny prostokąt otaczający podane ślady, w stopniach.
     *
     * Po co: mapa musi ustawić kadr na to, co pokazuje, a przy kaflach nie ma
     * już momentu „wczytał się GPX, znam jego granice" — kafle przychodzą jako
     * obrazki i nic o sobie nie mówią. Granice liczymy więc na serwerze
     * z kolumn min/max, jednym zapytaniem, i podajemy widokowi gotowe. To jest
     * zarazem tańsze niż stary sposób: przedtem trzeba było POBRAĆ wszystkie
     * pliki, żeby dowiedzieć się, gdzie ustawić mapę.
     *
     * JEDEN ODLEGŁY WYJAZD POTRAFI PRZECIĄGNĄĆ ŚRODEK W PUSTKĘ — DOKŁADNIE TA
     * SAMA REGRESJA, KTÓRĄ NAPRAWIONO W `Discovery::boundsFor()` (zgłoszenie
     * usera, ten sam dzień: „sprawdź to samo dla profilu rowerzysty"). Naiwny
     * MIN/MAX po WSZYSTKICH śladach naraz zakłada jeden zwarty obszar; ktoś,
     * kto ma choć jeden wyjazd na drugi kontynent obok reszty historii, dostaje
     * prostokąt oceaniczny, a jego geometryczny środek nie leży blisko żadnego
     * realnego skupiska. Naprawa jest tym samym pomysłem, przełożonym na
     * PIKSELE (ta klasa nie zna osi heksagonu — patrz `pixelExtremes()`):
     * naiwny prostokąt liczy się jak dotychczas, a dopiero gdy jego rzeczywista
     * rozpiętość przekroczy `CLUSTER_TRIGGER_KM`, drugie zapytanie dzieli ślady
     * na kubełki `CLUSTER_BUCKET_KM` (po ŚRODKU bboxa KAŻDEGO śladu, bo tu
     * jednostką klastrowania jest cały PLIK, nie pojedynczy punkt) i bierze
     * NAJWIĘKSZY — plus sąsiadów o jeden kubełek, żeby nie uciąć skupiska na
     * granicy. Koszt drugiego zapytania płaci wyłącznie ten, kto ma odległy
     * wyjazd; typowy zwarty zasięg (prawie każdy profil) kończy na pierwszym
     * zapytaniu, jak dotychczas.
     *
     * @param string[] $hashes
     * @return array{south:float,west:float,north:float,east:float}|null
     */
    public static function boundsFor(array $hashes, ?int $triggerKm = null, ?int $bucketKm = null): ?array
    {
        return self::boundsIn($hashes, 'gpx_geometry', $triggerKm, $bucketKm);
    }

    /**
     * Wspólne ciało `boundsFor()` i `boundsForTrimmed()` — ta sama reguła
     * kadrowania, dwie tabele geometrii.
     *
     * Rozdzielone na parametr 2026-09-12, gdy kadru na PRZYCIĘTEJ geometrii
     * zaczęła potrzebować karta Pulsu „wgrane ślady" (jedna osoba, jedna doba,
     * po imporcie z Garmina bywa ich kilkaset). Do tej daty klastrowanie stało
     * wyłącznie w gałęzi zwykłej, bo `boundsForTrimmed()` miało jednego
     * wołającego i JEDEN ślad na wywołanie — patrz nota przy tamtej metodzie.
     * Dla tamtego wołającego nic się nie zmienia: pojedynczy ślad nigdy nie
     * rozciąga się na `CLUSTER_TRIGGER_KM`, więc drugie zapytanie nie rusza.
     *
     * `$table` jest WYŁĄCZNIE literałem z tej klasy (nazwa tabeli nie da się
     * sparametryzować przez PDO), więc biała lista stoi tu, a nie w wołającym.
     *
     * PRÓG I KUBEŁEK SĄ PARAMETREM, bo „za szeroko" znaczy co innego na pełnym
     * ekranie niż na obrazku wysokim na 132 px. Stałe klasy (1000/150 km) są
     * skrojone pod mapę profilu, gdzie odsiać trzeba wyjazd na inny kontynent,
     * a cała Polska w kadrze jest w porządku. Karta Pulsu ma kilkanaście razy
     * mniej pikseli i podaje własne, ciaśniejsze wartości — patrz
     * `TileController::SLAD_CLUSTER_*`.
     *
     * @param string[] $hashes
     * @return array{south:float,west:float,north:float,east:float}|null
     */
    private static function boundsIn(
        array $hashes,
        string $table,
        ?int $triggerKm = null,
        ?int $bucketKm = null
    ): ?array {
        if ($table !== 'gpx_geometry' && $table !== 'gpx_geometry_trimmed') {
            throw new \InvalidArgumentException("Nieznana tabela geometrii: $table");
        }
        $triggerKm ??= self::CLUSTER_TRIGGER_KM;
        $bucketKm  ??= self::CLUSTER_BUCKET_KM;

        $hashes = self::sanitize($hashes);
        if (!$hashes) {
            return null;
        }

        $box = self::pixelExtremes($hashes, null, null, null, $table);
        if ($box === null) {
            return null;
        }

        // Metry w terenie na piksel STORE_Z, liczone na 52°N — szerokość
        // charakterystyczna dla serwisu (ta sama, którą DiscoveryGrid podaje
        // jako przykład rozmiaru pola). Wystarczy jako PRZYBLIŻENIE do
        // decyzji „czy to jest jeden kraj, czy dwa kontynenty" — nie musi być
        // dokładne co do metra na KAŻDEJ szerokości geograficznej.
        $metersPerPx = TileGrid::metersPerPixel(TileGrid::STORE_Z, 52.0);
        $spanX = ($box['max_px'] - $box['min_px']) * $metersPerPx;
        $spanY = ($box['max_py'] - $box['min_py']) * $metersPerPx;

        if (max($spanX, $spanY) > $triggerKm * 1000) {
            $bucketPx = max(1, (int) round($bucketKm * 1000 / $metersPerPx));

            $in = "'" . implode("','", $hashes) . "'";
            $bucketRow = Database::connection()->query("
                SELECT FLOOR(((min_px + max_px) / 2) / $bucketPx) AS xb,
                       FLOOR(((min_py + max_py) / 2) / $bucketPx) AS yb,
                       COUNT(*) AS n
                  FROM $table
                 WHERE gpx_hash IN ($in) AND points IS NOT NULL
                 GROUP BY xb, yb
                 ORDER BY n DESC
                 LIMIT 1
            ")->fetch();

            if ($bucketRow !== false) {
                $clustered = self::pixelExtremes(
                    $hashes,
                    $bucketPx,
                    (int) $bucketRow['xb'],
                    (int) $bucketRow['yb'],
                    $table
                );
                // Pusty tylko w teorii (kubełek wzięty z GROUP BY tych samych
                // wierszy) — naiwny prostokąt zostaje jako siatka bezpieczeństwa.
                if ($clustered !== null) {
                    $box = $clustered;
                }
            }
        }

        // Piksel rośnie w prawo i W DÓŁ, a szerokość geograficzna rośnie w górę
        // — dlatego minimum piksela to PÓŁNOC, a maksimum POŁUDNIE.
        [$north, $west] = TileGrid::toLatLon($box['min_px'], $box['min_py']);
        [$south, $east] = TileGrid::toLatLon($box['max_px'], $box['max_py']);
        return ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east];
    }

    /**
     * Skrajne piksele (min/max `px`/`py`) dla podanych śladów — wspólne dla
     * naiwnego prostokąta i dla wersji zawężonej do jednego kubełka
     * w `boundsFor()`, żeby zapytanie MIN/MAX istniało w kodzie raz.
     *
     * Kubełek (opcjonalny) filtruje po ŚRODKU bboxa KAŻDEGO śladu — jednostką
     * klastrowania jest tu cały plik, nie pojedynczy punkt geometrii, bo to
     * pliki (ślady) są tym, co się nakłada albo nie na inne pliki.
     *
     * @param string[] $hashes JUŻ przepuszczone przez sanitize() — metoda
     *        prywatna, wołający ma obowiązek to zapewnić.
     * @return array{min_px:int,min_py:int,max_px:int,max_py:int}|null
     */
    private static function pixelExtremes(
        array $hashes,
        ?int $bucketPx = null,
        ?int $xb = null,
        ?int $yb = null,
        string $table = 'gpx_geometry'
    ): ?array {
        $in = "'" . implode("','", $hashes) . "'";
        $where = "gpx_hash IN ($in) AND points IS NOT NULL";
        $params = [];
        if ($bucketPx !== null) {
            // DWA RÓŻNE NAZWANE PLACEHOLDERY na tę samą wartość ($bucketX/$bucketY),
            // nie jeden powtórzony ($bucketPx dwa razy) — EMULATE_PREPARES=false
            // nie pozwala użyć tej samej nazwy dwukrotnie w JEDNYM zapytaniu.
            $where .= " AND FLOOR(((min_px + max_px) / 2) / :bucketX) BETWEEN :xbMin AND :xbMax
                         AND FLOOR(((min_py + max_py) / 2) / :bucketY) BETWEEN :ybMin AND :ybMax";
            $params = [
                'bucketX' => $bucketPx, 'bucketY' => $bucketPx,
                'xbMin' => $xb - 1, 'xbMax' => $xb + 1,
                'ybMin' => $yb - 1, 'ybMax' => $yb + 1,
            ];
        }

        $stmt = Database::connection()->prepare(
            "SELECT MIN(min_px) AS min_px, MIN(min_py) AS min_py,
                    MAX(max_px) AS max_px, MAX(max_py) AS max_py
               FROM $table WHERE $where"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false || $row['min_px'] === null) {
            return null;
        }

        return [
            'min_px' => (int) $row['min_px'], 'min_py' => (int) $row['min_py'],
            'max_px' => (int) $row['max_px'], 'max_py' => (int) $row['max_py'],
        ];
    }

    /**
     * Kafle poziomu indeksu, przez które przechodzi dany ślad.
     * Używane przy unieważnianiu: dodanie śladu kasuje dokładnie te kafle
     * (i ich rodziców, przez przesunięcie).
     *
     * @return array<int,array{0:int,1:int}>
     */
    public static function tilesFor(string $hash): array
    {
        $stmt = Database::connection()->prepare('SELECT tx, ty FROM gpx_tiles WHERE gpx_hash = :h');
        $stmt->execute(['h' => $hash]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [(int) $r['tx'], (int) $r['ty']];
        }
        return $out;
    }

    /**
     * Adresy WSZYSTKICH plików GPX, jakie zna baza — z każdej tabeli, która je
     * trzyma. Jedno miejsce z tą listą, bo „skąd biorą się ślady" to pytanie,
     * na które dwie odpowiedzi zawsze się w końcu rozjadą.
     *
     * @return string[]
     */
    public static function allGpxUrls(): array
    {
        $db = Database::connection();
        $urls = [];
        foreach ([
            'SELECT gpx_url FROM edition_tracks',
            'SELECT gpx_url FROM event_stages WHERE gpx_url IS NOT NULL',
            'SELECT gpx_url FROM event_route_variants WHERE gpx_url IS NOT NULL',
            'SELECT gpx_url FROM known_routes WHERE gpx_url IS NOT NULL',
            'SELECT gpx_url FROM rider_activities WHERE gpx_url IS NOT NULL',
        ] as $sql) {
            foreach ($db->query($sql)->fetchAll(\PDO::FETCH_COLUMN) as $url) {
                if ($url !== null && $url !== '') {
                    $urls[$url] = true;
                }
            }
        }
        return array_keys($urls);
    }

    /**
     * Policzenie geometrii dla wszystkich śladów, które jej jeszcze nie mają.
     *
     * PO CO, SKORO GEOMETRIA LICZY SIĘ SAMA. Liczy się LENIWIE, przy pierwszym
     * kaflu, na którym ślad mógłby się znaleźć (`TileSource::hashesFor` woła
     * `ensure`). Przy jednym śladzie to niewidoczne, ale klucz `kr` obejmuje
     * WSZYSTKIE aktywne znane trasy — więc pierwsze wejście na mapę po dodaniu
     * dwustu szlaków parsowałoby dwieście plików GPX w trakcie żądania o obrazek.
     * Ta metoda przenosi ten koszt tam, gdzie jest nieszkodliwy: do panelu.
     *
     * IDEMPOTENTNA — pliki już policzone kosztują jedno zapytanie. Uszkodzony
     * albo brakujący plik nie przerywa przebiegu: reszta śladów ma się policzyć
     * niezależnie od tego, że jeden jest zepsuty.
     *
     * @param callable|null $log wywoływane z komunikatem po każdym pliku
     * @return array{policzone:int, juz_byly:int, brak_pliku:int}
     */
    public static function backfillAll(?callable $log = null): array
    {
        $policzone = 0;
        $juzByly = 0;
        $brakPliku = 0;

        foreach (self::allGpxUrls() as $url) {
            $path = TileSource::absolutePath($url);
            if (!is_file($path)) {
                $brakPliku++;
                if ($log) { $log('BRAK PLIKU: ' . $url); }
                continue;
            }
            if (self::has(hash_file('sha256', $path))) {
                $juzByly++;
                continue;
            }
            if (self::ensure($path) !== null) {
                $policzone++;
                if ($log) { $log('policzone: ' . $url); }
            }
        }

        return ['policzone' => $policzone, 'juz_byly' => $juzByly, 'brak_pliku' => $brakPliku];
    }

    /**
     * Ile śladów czeka na policzenie geometrii — liczba do panelu kafli.
     *
     * Liczona po PLIKACH, nie po wierszach: ten sam plik bywa podpięty
     * w kilku miejscach (etap, wariant, znana trasa) i liczy się raz.
     */
    public static function missingCount(): int
    {
        $brak = 0;
        foreach (self::allGpxUrls() as $url) {
            $path = TileSource::absolutePath($url);
            if (is_file($path) && !self::has(hash_file('sha256', $path))) {
                $brak++;
            }
        }
        return $brak;
    }

    /** Czy plik ma już policzoną geometrię (bez parsowania). */
    public static function has(string $hash): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM gpx_geometry WHERE gpx_hash = :h');
        $stmt->execute(['h' => $hash]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Hashe wstawiane do zapytania są sklejane w IN, więc muszą być
     * bezwarunkowo bezpieczne. Hash z hash_file() zawsze pasuje do tego wzorca;
     * cokolwiek innego wypada tu, zamiast trafić do SQL-a.
     *
     * @param string[] $hashes
     * @return string[]
     */
    private static function sanitize(array $hashes): array
    {
        return array_values(array_unique(array_filter(
            $hashes,
            static fn($h): bool => is_string($h) && preg_match('/^[0-9a-f]{64}$/', $h) === 1
        )));
    }

    // ---------------------------------------------------------------
    // Geometria PRZYCIĘTA solo — heatmapa społeczności (migr. 076, 2026-08-28)
    // ---------------------------------------------------------------
    //
    // Zgłoszenie usera: był przekonany, że „Ślady"/heatmapa na mapie
    // społeczności liczy WSZYSTKIE przejazdy, nie tylko `edition_tracks` —
    // odkryte pole (ze WSZYSTKICH przejazdów) nie miało pod sobą śladu na
    // mapie (tylko z wyjazdów). Solo NIE MOŻE wejść do `gpx_geometry` wprost
    // — zaczyna/kończy się pod domem (§27), a `all` leży publicznie na
    // dysku pod adresem do zgadnięcia. Druga para tabel (`gpx_geometry_trimmed`
    // / `gpx_tiles_trimmed`, migr. 076) niesie WYŁĄCZNIE geometrię PO
    // przycięciu okolic domu — tym samym promieniem, którym `RiderActivity::
    // recordSolo` przycina punkty przed policzeniem pól odkryć
    // (`DiscoveryGrid::trimEnds`, `DiscoveryScoring::homeTrimRadiusM()`).
    // Klucz to ten sam `gpx_hash` co w `gpx_geometry` — relacja 1:1 „przycięty
    // wariant tego pliku", nie kolizja nazw.

    /**
     * Zapewnia PRZYCIĘTĄ geometrię solo z punktów, które ktoś JUŻ przyciął —
     * `RiderActivity::recordSolo` i tak woła `DiscoveryGrid::trimEnds` przy
     * wgraniu, żeby policzyć pola odkryć. Zero powtórnego parsowania pliku:
     * ten sam koszt-zero, o którym mówi nota przy kolorze śladów („kolor
     * przydziela się SAM przy liczeniu geometrii, bez osobnego kroku").
     *
     * @param array<int,array{lat:float,lon:float}> $trimmedPoints
     */
    public static function ensureTrimmedFromPoints(string $hash, array $trimmedPoints): void
    {
        if (self::hasTrimmed($hash)) {
            return;
        }
        self::storeTrimmed($hash, $trimmedPoints);
    }

    /**
     * Zapewnia PRZYCIĘTĄ geometrię solo Z PLIKU — ścieżka leniwa/backfillowa:
     * plik wgrany PRZED migr. 076, albo pierwszy kafel klucza `all`, który go
     * dotyka, zanim backfill zdążył przejść. W przeciwieństwie do
     * `ensureTrimmedFromPoints()` PARSUJE plik od nowa (jeden `Utils\Gpx::
     * parse()`) — kosztuje tyle, co pierwsze policzenie pełnej geometrii
     * w `ensure()`, i płaci to PIERWSZY, kto akurat trafi.
     *
     * @return string|null hash pliku; null, gdy pliku nie ma lub jest nieczytelny
     */
    public static function ensureTrimmed(string $absolutePath): ?string
    {
        $hash = self::fileHash($absolutePath);
        if ($hash === null) {
            return null;
        }
        if (self::hasTrimmed($hash)) {
            return $hash;
        }

        try {
            $points = Gpx::parse($absolutePath, Gpx::LONG_ROUTE_MAX_DISTANCE_KM)['points'];
        } catch (\Throwable $e) {
            // Plik nieczytelny -> PUSTY wiersz, ten sam powód co w ensure():
            // bez niego każdy kolejny kafel próbowałby go sparsować od nowa.
            self::storeTrimmed($hash, []);
            return $hash;
        }

        // TEN SAM promień co przy polach odkryć solo (RiderActivity::
        // recordSolo) — inny promień tutaj znaczyłby dwa różne obwody
        // „okolic domu" dla TEGO SAMEGO przejazdu, zależnie od tego, którędy
        // geometria akurat przyszła.
        self::storeTrimmed($hash, DiscoveryGrid::trimEnds($points, DiscoveryScoring::homeTrimRadiusM()));
        return $hash;
    }

    /** Czy PRZYCIĘTA geometria pliku już jest policzona (bez parsowania). */
    public static function hasTrimmed(string $hash): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM gpx_geometry_trimmed WHERE gpx_hash = :h');
        $stmt->execute(['h' => $hash]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zapis PRZYCIĘTEJ geometrii — wspólne dla obu ścieżek wyżej. Ta sama
     * logika pakowania co `ensure()`, świadomie powtórzona zamiast wyciągnięta
     * do wspólnego helpera z nią: `ensure()` przy okazji liczy kolor
     * (`assignColor`) i pilnuje progu długiej trasy inaczej niż to, co ma
     * sens tutaj (punkty są JUŻ przycięte, nie surowe z pliku) — sklejenie
     * dwóch ścieżek jednym warunkowym helperem kosztowałoby więcej niż jest
     * warte przy dwudziestu linijkach.
     *
     * @param array<int,array{lat:float,lon:float}> $points
     */
    private static function storeTrimmed(string $hash, array $points): void
    {
        $db = Database::connection();

        $px = [];
        foreach ($points as $p) {
            if (!isset($p['lat'], $p['lon'])) {
                continue;
            }
            $px[] = TileGrid::toPixel((float) $p['lat'], (float) $p['lon']);
        }

        if (!$px) {
            // Cały ślad leżał w promieniu domowym (bardzo krótki solo) —
            // PUSTY wiersz z tego samego powodu co w ensure(): bez niego
            // każdy kafel dotykający tego hasha próbowałby przycinać od nowa.
            $db->prepare(
                'INSERT IGNORE INTO gpx_geometry_trimmed
                    (gpx_hash, point_count, min_px, min_py, max_px, max_py, points)
                 VALUES (:h, 0, 0, 0, 0, 0, NULL)'
            )->execute(['h' => $hash]);
            return;
        }

        $packed = '';
        $minPx = PHP_INT_MAX; $minPy = PHP_INT_MAX; $maxPx = 0; $maxPy = 0;
        foreach ($px as [$x, $y]) {
            $packed .= pack('VV', $x, $y);
            if ($x < $minPx) { $minPx = $x; }
            if ($y < $minPy) { $minPy = $y; }
            if ($x > $maxPx) { $maxPx = $x; }
            if ($y > $maxPy) { $maxPy = $y; }
        }

        // SAMA LUKA I TA SAMA ŁATKA co w `ensure()` wyżej — bliźniacze tabele,
        // bliźniaczy wyścig (`gpx_geometry_trimmed` commitowałby się przed
        // kompletem `gpx_tiles_trimmed`, a to jest indeks czytany przez
        // `inTileTrimmed()` dla klucza `all`, czyli heatmapy społeczności).
        $wlasna = !$db->inTransaction();
        if ($wlasna) {
            $db->beginTransaction();
        }
        try {
            $db->prepare(
                'INSERT IGNORE INTO gpx_geometry_trimmed
                    (gpx_hash, point_count, min_px, min_py, max_px, max_py, points)
                 VALUES (:h, :n, :m1, :m2, :m3, :m4, :pts)'
            )->execute([
                'h'   => $hash,
                'n'   => count($px),
                'm1'  => $minPx,
                'm2'  => $minPy,
                'm3'  => $maxPx,
                'm4'  => $maxPy,
                'pts' => $packed,
            ]);

            foreach (array_chunk(array_values(self::tileSet($px)), 500) as $chunk) {
                $values = [];
                foreach ($chunk as [$tx, $ty]) {
                    $values[] = '(' . $db->quote($hash) . ',' . (int) $tx . ',' . (int) $ty . ')';
                }
                $db->exec('INSERT IGNORE INTO gpx_tiles_trimmed (gpx_hash, tx, ty) VALUES ' . implode(',', $values));
            }

            if ($wlasna) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($wlasna && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zawęża listę hashy PRZYCIĘTEJ geometrii do tych, które dotykają kafla —
     * bliźniak `inTile()`, ta sama logika, druga tabela.
     *
     * @param string[] $hashes
     * @return string[]
     */
    public static function inTileTrimmed(array $hashes, int $z, int $x, int $y): array
    {
        $hashes = self::sanitize($hashes);
        if (!$hashes) {
            return [];
        }
        [$txMin, $txMax, $tyMin, $tyMax] = TileGrid::indexRange($z, $x, $y);

        $in = "'" . implode("','", $hashes) . "'";
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT gpx_hash FROM gpx_tiles_trimmed
              WHERE gpx_hash IN ($in)
                AND tx BETWEEN :tx1 AND :tx2
                AND ty BETWEEN :ty1 AND :ty2"
        );
        $stmt->execute(['tx1' => $txMin, 'tx2' => $txMax, 'ty1' => $tyMin, 'ty2' => $tyMax]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Rozpakowana PRZYCIĘTA geometria — bliźniak `load()`, druga tabela.
     *
     * @param string[] $hashes
     * @return array<string,array{pts:array<int,int>,minPx:int,minPy:int,maxPx:int,maxPy:int}>
     */
    public static function loadTrimmed(array $hashes): array
    {
        $hashes = self::sanitize($hashes);
        if (!$hashes) {
            return [];
        }
        $in = "'" . implode("','", $hashes) . "'";
        $rows = Database::connection()
            ->query("SELECT gpx_hash, min_px, min_py, max_px, max_py, points
                       FROM gpx_geometry_trimmed WHERE gpx_hash IN ($in)")
            ->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            if ($r['points'] === null || $r['points'] === '') {
                continue;
            }
            $out[$r['gpx_hash']] = [
                'pts'   => array_values(unpack('V*', $r['points'])),
                'minPx' => (int) $r['min_px'],
                'minPy' => (int) $r['min_py'],
                'maxPx' => (int) $r['max_px'],
                'maxPy' => (int) $r['max_py'],
            ];
        }
        return $out;
    }

    /**
     * Kadr PRZYCIĘTEJ geometrii (2026-09-03) — prostokąt startowy mapy dla
     * kogoś, kto ogląda cudzy przejazd solo na `/przejazd/{id}`.
     *
     * Do 2026-09-12 stało tu osobne, naiwne MIN/MAX z notą „ŚWIADOMIE BEZ
     * KLASTROWANIA": jedyny wołający podawał JEDEN ślad, więc skrajne piksele
     * były odpowiedzią, a nie problemem. Od karty Pulsu „wgrane ślady" ta sama
     * metoda dostaje CAŁĄ dobę jednej osoby — po imporcie z Garmina kilkaset
     * przejazdów z różnych okolic — czyli dokładnie ten przypadek, dla którego
     * klastrowanie powstało. Stąd wspólne ciało z `boundsFor()`; dla starego
     * wołającego nic się nie zmienia, bo pojedynczy ślad nie ma jak przekroczyć
     * `CLUSTER_TRIGGER_KM` i drugie zapytanie w ogóle nie rusza.
     *
     * @param string[] $hashes
     * @return array{south:float,west:float,north:float,east:float}|null
     */
    public static function boundsForTrimmed(array $hashes, ?int $triggerKm = null, ?int $bucketKm = null): ?array
    {
        return self::boundsIn($hashes, 'gpx_geometry_trimmed', $triggerKm, $bucketKm);
    }

    /**
     * Backfill PRZYCIĘTEJ geometrii dla solo wgranego PRZED migr. 076 —
     * bliźniak `backfillAll()`, ale źródło hashy to WYŁĄCZNIE
     * `rider_activities` solo, nie wszystkie pięć tabel z `allGpxUrls()`:
     * przycinanie ma sens tylko tam, gdzie plik zaczyna się pod domem.
     *
     * @return array{policzone:int, juz_byly:int, brak_pliku:int}
     */
    public static function backfillAllTrimmed(?callable $log = null): array
    {
        $policzone = 0;
        $juzByly = 0;
        $brakPliku = 0;

        foreach (self::soloGpxUrls() as $url) {
            $path = TileSource::absolutePath($url);
            if (!is_file($path)) {
                $brakPliku++;
                if ($log) { $log('BRAK PLIKU: ' . $url); }
                continue;
            }
            $hash = hash_file('sha256', $path);
            if ($hash !== false && self::hasTrimmed($hash)) {
                $juzByly++;
                continue;
            }
            if (self::ensureTrimmed($path) !== null) {
                $policzone++;
                if ($log) { $log('przycięte: ' . $url); }
            }
        }

        return ['policzone' => $policzone, 'juz_byly' => $juzByly, 'brak_pliku' => $brakPliku];
    }

    /** Ile solo czeka jeszcze na przyciętą geometrię — liczba do panelu/CLI. */
    public static function missingTrimmedCount(): int
    {
        $brak = 0;
        foreach (self::soloGpxUrls() as $url) {
            $path = TileSource::absolutePath($url);
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($hash !== false && !self::hasTrimmed($hash)) {
                $brak++;
            }
        }
        return $brak;
    }


    /**
     * GEOMETRIA JEDNEGO ŚLADU DLA PRZEGLĄDARKI — spakowana (2026-09-02).
     *
     * Powstało po zgłoszeniu usera: klik w wiersz „Ostatniej aktywności"
     * zatrzymywał się na kilkanaście sekund, bo podświetlenie śladu ciągnęło
     * SUROWY PLIK GPX (leaflet-gpx) — przy przejeździe z licznika to ponad
     * 10 MB XML-a z tętnem, mocą, kadencją i wysokościami, z czego do
     * narysowania linii używamy wyłącznie lat/lon. Te same punkty leżą tu
     * policzone od migr. 051, więc rysowanie bierze je STĄD, a plik zostaje
     * tam, gdzie jest potrzebny w całości (parsowanie przy wgraniu, kafle).
     *
     * PEŁNA GEOMETRIA I PEŁNA PRECYZJA (decyzja usera) — nie upraszczamy
     * linii i nie schodzimy z rozdzielczością do zoomu kadru. Piksel STORE_Z
     * to ok. 0,4 m w Polsce, a transport tnie na 1e-6 stopnia (ok. 0,11 m),
     * czyli poniżej tego, co w ogóle jest zapisane.
     *
     * FORMAT `d6v` — ten sam pomysł co encoded polyline, tylko bez zmyślania
     * własnego alfabetu: współrzędne w milionowych częściach stopnia, RÓŻNICE
     * między kolejnymi punktami (sąsiednie punkty śladu dzielą kilka metrów,
     * więc różnica mieści się w jednym–dwóch bajtach), zygzak (liczby ujemne
     * bez pełnego słowa) i varint po 7 bitów, całość w base64. Zmierzone na
     * żywym pliku: 179 864 B GPX -> ok. 12 000 B odpowiedzi. Rozpakowanie po
     * stronie przeglądarki to kilkanaście linii (`ridemoreUnpackTrack`
     * w assets/js/discovery-map.js) — TU I TAM opisuje ten sam format, więc
     * zmiana kodowania wymaga zmiany w obu miejscach.
     *
     * Zwraca null tylko wtedy, gdy pliku nie ma albo jest nieczytelny.
     * Ślad policzony jako pusty oddaje `n = 0` — „nie ma czego rysować" to
     * odpowiedź, a nie błąd (patrz `ensure()`).
     *
     * @return array{n:int,enc:string,pts:string,bounds:?array{south:float,west:float,north:float,east:float}}|null
     */
    public static function packedForFile(string $absolutePath): ?array
    {
        $hash = self::ensure($absolutePath);
        if ($hash === null) {
            return null;
        }

        return self::pack(self::load([$hash])[$hash] ?? null);
    }

    /**
     * TO SAMO, ALE Z GEOMETRII PRZYCIĘTEJ (2026-09-03) — odpowiedź dla kogoś,
     * kto NIE JEST właścicielem przejazdu solo (strona `/przejazd/{id}`).
     *
     * Bliźniak `packedForFile()` dokładnie tak, jak `loadTrimmed()` jest
     * bliźniakiem `load()`: ta sama funkcja pakująca, inna tabela. Nie ma tu
     * drugiego formatu ani drugiej drogi do geometrii — jest ten sam `d6v`,
     * policzony z punktów po odcięciu okolic domu (§27, migr. 076).
     *
     * @return array{n:int,enc:string,pts:string,bounds:?array{south:float,west:float,north:float,east:float}}|null
     */
    public static function packedTrimmedForFile(string $absolutePath): ?array
    {
        $hash = self::ensureTrimmed($absolutePath);
        if ($hash === null) {
            return null;
        }

        return self::pack(self::loadTrimmed([$hash])[$hash] ?? null);
    }

    /**
     * Spakowanie JEDNEJ wczytanej geometrii do odpowiedzi API — wspólne dla
     * wariantu pełnego i przyciętego. Wydzielone przy dodawaniu tego drugiego
     * (2026-09-03): kopia tej pętli w dwóch metodach znaczyłaby dwa miejsca do
     * poprawienia przy każdej zmianie kodowania, a `ridemoreUnpackTrack` w JS
     * i tak jest już trzecim.
     *
     * @param array{pts:array<int,int>,minPx:int,minPy:int,maxPx:int,maxPy:int}|null $g
     * @return array{n:int,enc:string,pts:string,bounds:?array{south:float,west:float,north:float,east:float}}
     */
    private static function pack(?array $g): array
    {
        if ($g === null || !$g['pts']) {
            return ['n' => 0, 'enc' => self::PACK_ENC, 'pts' => '', 'bounds' => null];
        }

        $pts = $g['pts'];
        $bin = '';
        $n = 0;
        $lastLat = 0;
        $lastLon = 0;
        for ($i = 0, $len = count($pts); $i + 1 < $len; $i += 2) {
            [$lat, $lon] = TileGrid::toLatLon($pts[$i], $pts[$i + 1]);
            $e6Lat = (int) round($lat * 1e6);
            $e6Lon = (int) round($lon * 1e6);
            $bin .= self::varint(self::zigzag($e6Lat - $lastLat))
                 .  self::varint(self::zigzag($e6Lon - $lastLon));
            $lastLat = $e6Lat;
            $lastLon = $e6Lon;
            $n++;
        }

        // Prostokąt liczymy ze SKRAJNYCH PIKSELI, nie z przelecianych punktów:
        // to ta sama wartość, którą zna renderer kafli, i nie zależy od tego,
        // czy pętla po drodze czegoś nie zaokrągliła. Uwaga na oś Y — piksel
        // rośnie na POŁUDNIE, więc min_py to północ.
        [$north, $west] = TileGrid::toLatLon($g['minPx'], $g['minPy']);
        [$south, $east] = TileGrid::toLatLon($g['maxPx'], $g['maxPy']);

        return [
            'n'      => $n,
            'enc'    => self::PACK_ENC,
            'pts'    => base64_encode($bin),
            'bounds' => ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east],
        ];
    }

    /**
     * SUMA KONTROLNA PLIKU — LICZONA RAZ NA WERSJĘ PLIKU (2026-09-02).
     *
     * Zgłoszenie usera: „przy dwóch obszarach czekałem ponad 10 sekund".
     * Zmierzone: KAŻDY kafel warstwy „Ślady" kosztował ok. 0,6 s — niezależnie
     * od tego, czy cokolwiek na nim było (pusty kafel nad Bałtykiem tyle samo,
     * co gęsty nad Krakowem). To nie było rysowanie: `TileSource::tracks('all')`
     * woła `ensure()` DLA KAŻDEGO PLIKU ŚLADU, a `ensure()` liczyło
     * `hash_file()` — 55 plików po kilka MB to ok. 0,55 s czystego czytania
     * dysku, powtarzane przy każdym kaflu, w kółko, dla tej samej odpowiedzi.
     *
     * Pamięć podręczna jest kluczowana ŚCIEŻKĄ, ale WAŻNA przez `mtime` i
     * rozmiar: podmieniony plik ma inny stempel, więc dostaje nowy hash bez
     * niczyjej interwencji. Wpis to jedna linijka w `storage/gpx-hash/` —
     * nie tabela, bo to jest cache systemu plików o systemie plików, a nie
     * wiedza o dziedzinie (ta mieszka w `gpx_geometry`, kluczowana hashem).
     *
     * Cache USZKODZONY ALBO NIEZAPISYWALNY nie jest błędem: metoda wtedy po
     * prostu liczy hash tak jak wcześniej. Jedyne, co można stracić, to czas.
     */
    public static function fileHash(string $absolutePath): ?string
    {
        static $pamiec = [];   // w obrębie jednego żądania: ten sam plik pyta wiele razy

        if (!is_file($absolutePath)) {
            return null;
        }

        // STEMPEL WCHODZI DO KLUCZA PAMIĘCI, nie tylko do pliku cache'u —
        // inaczej długo żyjący proces (backfill z CLI, który sam przepisuje
        // pliki) trzymałby hash sprzed własnej zmiany.
        $stempel = (string) filemtime($absolutePath) . ':' . (string) filesize($absolutePath);
        $pamiecKlucz = $absolutePath . '|' . $stempel;
        if (isset($pamiec[$pamiecKlucz])) {
            return $pamiec[$pamiecKlucz];
        }

        $plik = self::hashCacheDir() . '/' . sha1($absolutePath) . '.txt';

        $zapisane = @file_get_contents($plik);
        if (is_string($zapisane)) {
            $czesci = explode('|', trim($zapisane));
            if (count($czesci) === 2 && $czesci[0] === $stempel && preg_match('/^[0-9a-f]{64}$/', $czesci[1])) {
                return $pamiec[$pamiecKlucz] = $czesci[1];
            }
        }

        $hash = hash_file('sha256', $absolutePath);
        if ($hash === false) {
            return null;
        }
        @file_put_contents($plik, $stempel . '|' . $hash, LOCK_EX);

        return $pamiec[$pamiecKlucz] = $hash;
    }

    private static function hashCacheDir(): string
    {
        $dir = CORE_PATH . '/../storage/gpx-hash';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** Nazwa formatu w odpowiedzi — przeglądarka ma czym sprawdzić, co dostała. */
    public const PACK_ENC = 'd6v';

    /** Zygzak: -1 -> 1, 1 -> 2, -2 -> 3 — żeby varint nie płacił za znak. */
    private static function zigzag(int $v): int
    {
        return ($v << 1) ^ ($v >> 63);
    }

    /** Varint LE po 7 bitów, ósmy bit = „jest dalszy ciąg". */
    private static function varint(int $v): string
    {
        $out = '';
        while ($v >= 0x80) {
            $out .= chr(($v & 0x7F) | 0x80);
            $v >>= 7;
        }
        return $out . chr($v);
    }

    /** @return string[] adresy plików GPX solo — jedyne źródło geometrii przyciętej. */
    private static function soloGpxUrls(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT gpx_url FROM rider_activities WHERE source_code = :s AND gpx_url IS NOT NULL'
        );
        $stmt->execute(['s' => RiderActivity::SOURCE_SOLO]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
