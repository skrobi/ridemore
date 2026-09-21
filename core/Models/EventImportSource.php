<?php
// core/Models/EventImportSource.php
// Zarządzalna z panelu (/admin/importer) lista ŹRÓDEŁ importera — stron-LIST
// (kalendarzy) z treścią renderowaną po stronie serwera, po których chodzi
// worker `import_events.php --sources` i przycisk „Uruchom źródła" w panelu.
//
// Trzymane w PLIKU (data/event_sources.json), nie w tabeli — ten sam wzorzec co
// obrysy regionów (data/regiony.geojson, Models\RegionOutline): mała, rzadko
// zmieniana lista, którą admin edytuje narzędziem, bez potrzeby nowej tabeli
// (zgodnie z „zero nowych tabel" z kontraktu). Plik jest ŹRÓDŁEM PRAWDY po
// pierwszym zapisie; dopóki nie istnieje, lista jest zasiewana z
// config['event_import']['sources'], żeby świeże środowisko miało od czego
// zacząć. Dzięki temu usunięcie źródła zasianego z configu jest trwałe.
namespace Models;

class EventImportSource
{
    private static function file(): string
    {
        return dirname(__DIR__, 2) . '/data/event_sources.json';
    }

    /** Lista źródeł: [['url' => ..., 'added_at' => ...], ...]. */
    public static function all(): array
    {
        $path = self::file();
        if (!is_file($path)) {
            // Pierwszy odczyt: zasiej z configu i utrwal, żeby dalej plik był
            // jedynym źródłem prawdy (edycje z panelu nie kolidują z configiem).
            $seed = [];
            foreach ((array) (APP_CONFIG['event_import']['sources'] ?? []) as $url) {
                if (is_string($url) && self::valid($url)) {
                    $seed[] = ['url' => $url, 'added_at' => null];
                }
            }
            self::write($seed);
            return $seed;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        // Odporność na starszy/ręcznie edytowany format (lista stringów).
        $out = [];
        foreach ($data as $row) {
            if (is_string($row) && self::valid($row)) {
                $out[] = ['url' => $row, 'added_at' => null];
            } elseif (is_array($row) && isset($row['url']) && self::valid((string) $row['url'])) {
                $out[] = ['url' => (string) $row['url'], 'added_at' => $row['added_at'] ?? null];
            }
        }
        return $out;
    }

    /** Same adresy — dla workera (`--sources`). */
    public static function urls(): array
    {
        return array_column(self::all(), 'url');
    }

    /** Dodaje źródło. Zwraca true, gdy dodano; false, gdy zły adres lub duplikat. */
    public static function add(string $url): bool
    {
        $url = trim($url);
        if (!self::valid($url)) {
            return false;
        }
        $rows = self::all();
        foreach ($rows as $r) {
            if (rtrim($r['url'], '/') === rtrim($url, '/')) {
                return false; // już jest
            }
        }
        $rows[] = ['url' => $url, 'added_at' => date('c')];
        self::write($rows);
        return true;
    }

    /** Usuwa źródło po adresie. Zwraca true, gdy coś usunięto. */
    public static function remove(string $url): bool
    {
        $url = trim($url);
        $rows = self::all();
        $kept = array_values(array_filter(
            $rows,
            static fn(array $r): bool => rtrim($r['url'], '/') !== rtrim($url, '/')
        ));
        if (count($kept) === count($rows)) {
            return false;
        }
        self::write($kept);
        return true;
    }

    private static function valid(string $url): bool
    {
        return $url !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }

    private static function write(array $rows): void
    {
        $path = self::file();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $path,
            json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
