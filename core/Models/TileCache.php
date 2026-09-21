<?php
// core/Models/TileCache.php
// KAFEL NA DYSKU — zapis, unieważnianie, sprzątanie (migr. 051).
//
// CAŁA WYDAJNOŚĆ TEGO ROZWIĄZANIA SIEDZI W JEDNEJ LINIJCE .htaccess
// ----------------------------------------------------------------
//     RewriteCond %{REQUEST_FILENAME} !-f
//     RewriteRule ^ index.php [L]
// Ta reguła istniała, zanim powstały kafle. Znaczy: „jeśli plik istnieje, oddaj
// go i nie budź PHP-a". Kafel zapisany pod swoim własnym adresem jest więc od
// drugiego żądania zwykłym plikiem statycznym — 0 ms PHP-a, 0 zapytań do bazy.
// Pierwsze żądanie kosztuje ok. 20–30 ms i wykonuje się RAZ.
//
// ZAPIS ATOMOWY, NIE BLOKADA
// --------------------------
// Dwa żądania na ten sam brakujący kafel wygenerują go równolegle — i dobrze.
// Blokada plikowa kosztowałaby więcej niż powtórzone rysowanie, a przy timeoucie
// PHP zostawiałaby zawieszone locki. Zamiast tego obie kopie lądują w plikach
// tymczasowych i rename() podmienia je atomowo: czytelnik nigdy nie zobaczy
// pliku w połowie zapisany. Wynik jest identyczny, więc kto wygra, nie ma
// znaczenia — ta sama zasada co przy idempotentnym naliczaniu punktów.
//
// EPOKA ZAMIAST CZYSZCZENIA PRZEGLĄDAREK
// --------------------------------------
// Kafle wychodzą z `Cache-Control: immutable` na rok (reguła na *.png
// w .htaccess), więc skasowanie pliku z serwera NIE dociera do nikogo, kto już
// go pobrał. Dlatego adres niesie ?v=<epoka>, a zmiana danych podbija epokę —
// ten sam wzorzec co ?v=<filemtime> w Utils\View::asset(), tylko dla zbioru.
namespace Models;

use Core\Database;
use Utils\TileGrid;
use Utils\View;

class TileCache
{
    /** Katalog pod webrootem — musi zgadzać się z adresem, inaczej reguła !-f nie zadziała. */
    private const DIR = 'assets/tiles';

    /**
     * Sufit liczby kafli na dysku.
     *
     * Nie chodzi o miejsce (200 000 kafli to ok. 4 GB), tylko o I-WĘZŁY: typowy
     * hosting współdzielony daje ich 250 000–500 000 NA CAŁE KONTO, wliczając
     * pliki aplikacji i pocztę. Piramida potrafi je wyczerpać po cichu, a objawia
     * się to niemożnością zapisania czegokolwiek — łącznie z sesją i uploadem.
     * Stąd twardy limit dużo poniżej progu i przycinanie najdawniej używanych.
     */
    private const MAX_TILES = 120000;

    /** Ile kafli wyrzucamy za jednym razem, gdy limit zostanie przekroczony. */
    private const PRUNE_BATCH = 5000;

    /**
     * Co ile zapisów sprawdzamy limit.
     *
     * Sprawdzanie przy każdym zapisie to dodatkowe COUNT(*) na tabeli, która
     * ma być duża. Raz na 500 zapisów wystarczy: między sprawdzeniami przybędzie
     * najwyżej 500 kafli, a limit ma i tak kilkadziesiąt tysięcy zapasu.
     */
    private const CHECK_EVERY = 500;

    public static function path(string $layer, string $key, int $z, int $x, int $y): string
    {
        return self::root() . "/$layer/$key/$z/$x/$y.png";
    }

    /** Katalog główny kafli — publiczny, bo pliki serwuje Apache. */
    public static function root(): string
    {
        return dirname(CORE_PATH) . '/' . self::DIR;
    }

    /**
     * Szablon adresu dla L.tileLayer, z epoką doklejoną jako ?v=.
     * Widok wstawia go do JS-a i nie musi wiedzieć nic o epokach.
     */
    public static function urlTemplate(string $layer, string $key): string
    {
        return View::url('/' . self::DIR . "/$layer/$key/{z}/{x}/{y}.png")
            . '?v=' . self::epoch($layer, $key);
    }

    public static function epoch(string $layer, string $key): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT epoch FROM tile_epochs WHERE layer = :l AND cache_key = :k'
        );
        $stmt->execute(['l' => $layer, 'k' => $key]);
        $epoch = $stmt->fetchColumn();
        return $epoch === false ? 1 : (int) $epoch;
    }

    /** Podbicie epoki — mówi przeglądarkom „to, co masz, jest nieaktualne". */
    public static function bump(string $layer, string $key): void
    {
        Database::connection()->prepare(
            'INSERT INTO tile_epochs (layer, cache_key, epoch) VALUES (:l, :k, 2)
             ON DUPLICATE KEY UPDATE epoch = epoch + 1'
        )->execute(['l' => $layer, 'k' => $key]);
    }

    /** Zapis kafla na dysk plus wpis do rejestru. */
    public static function store(string $layer, string $key, int $z, int $x, int $y, string $png): void
    {
        $path = self::path($layer, $key, $z, $x, $y);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // brak prawa zapisu nie może wywrócić żądania — kafel po prostu poleci z pamięci
        }

        $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $png) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return;
        }

        Database::connection()->prepare(
            'INSERT INTO tile_cache (layer, cache_key, z, x, y, bytes, last_used_at)
             VALUES (:l, :k, :z, :x, :y, :b, NOW())
             ON DUPLICATE KEY UPDATE bytes = VALUES(bytes), last_used_at = NOW()'
        )->execute(['l' => $layer, 'k' => $key, 'z' => $z, 'x' => $x, 'y' => $y, 'b' => strlen($png)]);

        if (random_int(1, self::CHECK_EVERY) === 1) {
            self::pruneIfNeeded();
        }
    }

    /**
     * Kasuje kafle, przez które przechodzi podany ślad, we WSKAZANYCH kluczach.
     *
     * Kafle bierzemy z indeksu poziomu TileGrid::INDEX_Z, a poziomy grubsze
     * wyprowadzamy przesunięciem bitowym — kafel (x, y) zoomu z ma rodzica
     * (x >> 1, y >> 1). Zmierzone: ślad 100 km dotyka ok. 274 kafli na z4–z14,
     * więc to kilkaset unlinków, nie przebudowa czegokolwiek.
     *
     * Nic nie GENERUJEMY z powrotem: brakujący kafel narysuje się sam przy
     * pierwszym żądaniu i to jest cała zaleta generowania na żądanie.
     *
     * @param string[] $keys
     */
    public static function invalidateTrack(string $hash, array $keys): void
    {
        $tiles = GpxGeometry::tilesFor($hash);
        if (!$tiles) {
            return;
        }

        $db = Database::connection();
        foreach ($keys as $key) {
            $wyrzucone = [];
            foreach ($tiles as [$tx, $ty]) {
                // OD INDEX_Z, NIE OD MAX_Z (2026-08-20). Poziomy głębsze niż
                // indeks powstają na żądanie i NIE LĄDUJĄ NA DYSKU (patrz
                // TileController), więc nie ma tam czego kasować — a próba
                // policzenia ich numerów dałaby ujemne przesunięcie bitowe.
                // Przy okazji oszczędza to lawinę: jeden kafel indeksu rozpada
                // się na 256 kafli zoomu 18, czyli ślad dotykający 115 kafli
                // wymagałby ok. 39 000 prób skasowania pliku.
                for ($z = TileGrid::INDEX_Z; $z >= TileGrid::MIN_Z; $z--) {
                    $shift = TileGrid::INDEX_Z - $z;
                    $x = $tx >> $shift;
                    $y = $ty >> $shift;
                    $wyrzucone["$z/$x/$y"] = [$z, $x, $y];
                }
            }
            foreach ([TileSource::LAYER_TRACKS, TileSource::LAYER_HEX] as $layer) {
                foreach ($wyrzucone as [$z, $x, $y]) {
                    @unlink(self::path($layer, $key, $z, $x, $y));
                }
                // Rejestr czyścimy JEDNYM zapytaniem z listą trójek, a nie
                // kilkuset osobnymi DELETE. I tylko te trójki, które faktycznie
                // skasowaliśmy — wyczyszczenie całego klucza zostawiłoby na
                // dysku pliki, o których rejestr już nie wie, czyli takie,
                // których przycinanie nigdy by nie ruszyło.
                foreach (array_chunk($wyrzucone, 300) as $chunk) {
                    $tuples = [];
                    foreach ($chunk as [$z, $x, $y]) {
                        $tuples[] = '(' . (int) $z . ',' . (int) $x . ',' . (int) $y . ')';
                    }
                    $db->prepare(
                        'DELETE FROM tile_cache WHERE layer = :l AND cache_key = :k
                          AND (z, x, y) IN (' . implode(',', $tuples) . ')'
                    )->execute(['l' => $layer, 'k' => $key]);
                }
                self::bump($layer, $key);
            }
        }
    }

    /**
     * Kompletne unieważnienie po wgraniu śladu do turnusu.
     *
     * Zbiera klucze map, na których ten ślad się pojawia — wspólna mapa,
     * kronika turnusu i mapa KAŻDEJ osoby, która na tym turnusie była — i
     * kasuje z nich kafle, przez które ślad przechodzi.
     *
     * Osoby liczą się z obecności, nie z zapisów: mapa profilu pokazuje ślady
     * z wyjazdów POTWIERDZONYCH (EditionTrack::effectiveForUser), więc komu
     * ten ślad nie wejdzie na mapę, temu nie trzeba nic kasować.
     */
    public static function invalidateForEdition(int $editionId, string $gpxUrl): void
    {
        $hash = GpxGeometry::ensure(TileSource::absolutePath($gpxUrl));
        if ($hash === null) {
            return;
        }

        $stmt = Database::connection()->prepare('
            SELECT DISTINCT u.public_slug
              FROM event_rsvps r
              JOIN event_attendance a ON a.rsvp_id = r.id AND a.attended = 1
              JOIN users u ON u.id = r.user_id
             WHERE r.edition_id = :ed AND u.public_slug IS NOT NULL
        ');
        $stmt->execute(['ed' => $editionId]);

        $keys = ['all', 'e-' . $editionId];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $slug) {
            $keys[] = 'u-' . $slug;
        }

        self::invalidateTrack($hash, $keys);
    }

    /**
     * Kasuje WSZYSTKO pod danym kluczem.
     *
     * Używane, gdy zmienia się nie treść, a prawo do oglądania: rowerzysta,
     * który ukrywa się z list, musi przestać mieć działające adresy kafli.
     */
    public static function purgeKey(string $layer, string $key): void
    {
        self::rmdirRecursive(self::root() . "/$layer/$key");
        Database::connection()
            ->prepare('DELETE FROM tile_cache WHERE layer = :l AND cache_key = :k')
            ->execute(['l' => $layer, 'k' => $key]);
        self::bump($layer, $key);
    }

    /** Odnotowanie użycia — pod przycinanie najdawniej oglądanych. */
    public static function touch(string $layer, string $key, int $z, int $x, int $y): void
    {
        Database::connection()->prepare(
            'UPDATE tile_cache SET last_used_at = NOW()
              WHERE layer = :l AND cache_key = :k AND z = :z AND x = :x AND y = :y'
        )->execute(['l' => $layer, 'k' => $key, 'z' => $z, 'x' => $x, 'y' => $y]);
    }

    /**
     * Przycięcie do limitu — najdawniej używane wychodzą pierwsze.
     *
     * @return int ile kafli wyrzucono
     */
    /**
     * Skasowanie WSZYSTKICH kafli warstwy — jedna logika dla CLI i dla panelu.
     *
     * Wyciągnięte z tiles.php (2026-08-15), gdy reset stał się dostępny także
     * z przeglądarki. Dwie kopie tego samego zamiatania rozjechałyby się przy
     * pierwszej zmianie układu katalogów, a to jest operacja, którą uruchamia
     * się rzadko i pod presją — czyli dokładnie wtedy, gdy nikt nie sprawdza,
     * czy obie wersje robią to samo.
     *
     * KASUJEMY TEŻ KATALOGI SPOZA REJESTRU. Po awarii zapisu na dysku potrafią
     * zostać kafle, o których `tile_cache` nic nie wie — sam DELETE z tabeli
     * zostawiłby je na zawsze, bo nic by ich już nie dotknęło.
     *
     * @return int ile kluczy wyczyszczono
     */
    public static function purgeLayer(string $layer): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT cache_key FROM tile_cache WHERE layer = :l'
        );
        $stmt->execute(['l' => $layer]);
        $klucze = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach (glob(self::root() . '/' . $layer . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $klucze[] = basename($dir);
        }

        $klucze = array_unique($klucze);
        foreach ($klucze as $key) {
            self::purgeKey($layer, $key);
        }

        return count($klucze);
    }

    public static function pruneIfNeeded(): int
    {
        $db = Database::connection();
        $count = (int) $db->query('SELECT COUNT(*) FROM tile_cache')->fetchColumn();
        if ($count <= self::MAX_TILES) {
            return 0;
        }

        $nadmiar = max(self::PRUNE_BATCH, $count - self::MAX_TILES);
        $rows = $db->query(
            'SELECT layer, cache_key, z, x, y FROM tile_cache
              ORDER BY last_used_at IS NULL DESC, last_used_at ASC
              LIMIT ' . (int) $nadmiar
        )->fetchAll();

        $del = $db->prepare(
            'DELETE FROM tile_cache WHERE layer = :l AND cache_key = :k AND z = :z AND x = :x AND y = :y'
        );
        foreach ($rows as $r) {
            @unlink(self::path($r['layer'], $r['cache_key'], (int) $r['z'], (int) $r['x'], (int) $r['y']));
            $del->execute([
                'l' => $r['layer'], 'k' => $r['cache_key'],
                'z' => (int) $r['z'], 'x' => (int) $r['x'], 'y' => (int) $r['y'],
            ]);
        }
        return count($rows);
    }

    /** Liczby pod panel admina i pod skrypty CLI. */
    public static function stats(): array
    {
        $row = Database::connection()->query(
            'SELECT COUNT(*) AS kafli, COALESCE(SUM(bytes), 0) AS bajtow,
                    COUNT(DISTINCT CONCAT(layer, cache_key)) AS kluczy
               FROM tile_cache'
        )->fetch();
        return [
            'tiles' => (int) $row['kafli'],
            'bytes' => (int) $row['bajtow'],
            'keys'  => (int) $row['kluczy'],
            'limit' => self::MAX_TILES,
        ];
    }

    private static function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
