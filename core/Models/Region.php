<?php
// core/Models/Region.php
// REGION JAKO STRONA PUBLICZNA — /regiony, /regiony/{kraj}, /regiony/{kraj}/{region}
// (2026-09-14, tasks/done/strony-regionow.md).
//
// NIE MA WŁASNEJ TABELI I TO JEST ZAŁOŻENIE: region to pozycja słownika
// `region` (kraj → regiony), geometria to `RegionOutline`, powiązania to
// istniejące tabele łączące. Ten model tylko odpowiada na pytania, których
// strona regionu potrzebuje, a których słownik sam nie zadaje: „jaki adres ma
// ten kod", „czy ten adres jest prawdziwy" i „ile czego jest w regionie".
//
// KRAJ BEZ DZIECI JEST SWOIM JEDYNYM REGIONEM (Słowacja rysowana w
// /admin/regiony-mapa bez rodzica) — ma jeden segment adresu, nie dwa.
// Tę samą regułę stosuje `Dictionary::groupedLeaves()` i `region-emblems.php`.
namespace Models;

use Core\Database;

class Region
{
    /** @var array<int,array>|null drzewo aktywnych krajów na czas żądania */
    private static ?array $countries = null;

    /**
     * Aktywne kraje z aktywnymi regionami, w kolejności słownika.
     *
     * @return list<array{id:int,code:string,name:string,isFlat:bool,
     *         regions:list<array{id:int,code:string,name:string}>}>
     *         `isFlat` = kraj bez aktywnych dzieci (sam jest regionem);
     *         jego `regions` jest wtedy puste.
     */
    public static function countries(): array
    {
        if (self::$countries !== null) {
            return self::$countries;
        }
        $out = [];
        foreach (Dictionary::tree('region') as $node) {
            if (!$node['isActive']) {
                continue;
            }
            $regions = [];
            foreach (self::activeLeaves($node['children']) as $leaf) {
                $regions[] = ['id' => $leaf['id'], 'code' => $leaf['code'], 'name' => $leaf['name']];
            }
            $out[] = [
                'id'      => $node['id'],
                'code'    => $node['code'],
                'name'    => $node['name'],
                'isFlat'  => $regions === [],
                'regions' => $regions,
            ];
        }
        // Kraj domowy pierwszy — ta sama kolejność co w `Discovery::regionProgress()`.
        usort($out, static fn(array $a, array $b): int => ($b['code'] === 'polska') <=> ($a['code'] === 'polska'));
        return self::$countries = $out;
    }

    /** Wyłącznie dla testów — drzewo jest trzymane na czas żądania. */
    public static function forgetCache(): void
    {
        self::$countries = null;
    }

    /**
     * Rozwiązanie adresu. Zwraca null, gdy kraju/regionu nie ma albo jest
     * nieaktywny (→ 404). Region istniejący pod INNYM krajem niż w adresie
     * wraca z `redirect` (→ 301 na właściwy adres).
     *
     * @return array{country:array,region:?array,redirect:?string}|null
     *         `region` = null dla strony kraju z regionami; dla kraju płaskiego
     *         `region` to sam kraj.
     */
    public static function resolve(string $countryCode, ?string $regionCode): ?array
    {
        foreach (self::countries() as $country) {
            if ($country['code'] !== $countryCode) {
                continue;
            }
            if ($regionCode === null) {
                return [
                    'country'  => $country,
                    'region'   => $country['isFlat'] ? self::flatRegion($country) : null,
                    'redirect' => null,
                ];
            }
            foreach ($country['regions'] as $region) {
                if ($region['code'] === $regionCode) {
                    return ['country' => $country, 'region' => $region, 'redirect' => null];
                }
            }
            break;
        }
        // Region pod złym krajem (albo kraj płaski z drugim segmentem).
        if ($regionCode !== null) {
            $path = self::pathForCode($regionCode);
            if ($path !== null) {
                return ['country' => [], 'region' => null, 'redirect' => $path];
            }
        }
        return null;
    }

    /** Adres strony kraju. */
    public static function countryPath(array $country): string
    {
        return '/regiony/' . $country['code'];
    }

    /**
     * Adres strony regionu dla KODU słownika (link z wydarzenia, trasy,
     * organizatora) albo null, gdy kodu nie ma wśród aktywnych regionów —
     * wtedy wołający zostaje przy dotychczasowym zachowaniu (bez linku).
     */
    public static function pathForCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        foreach (self::countries() as $country) {
            // Sam kraj (płaski albo kontener, np. wydarzenie otagowane „Polską").
            if ($country['code'] === $code) {
                return '/regiony/' . $country['code'];
            }
            foreach ($country['regions'] as $region) {
                if ($region['code'] === $code) {
                    return '/regiony/' . $country['code'] . '/' . $region['code'];
                }
            }
        }
        return null;
    }

    /**
     * To samo co `pathForCode()`, ale po NAZWIE — dla miejsc, które dostają
     * z zapytań sam podpis regionu (`region_label`, `regionName`), a nie kod.
     * Nazwy regionów są w słowniku unikalne (województwa + rysowane obrysy),
     * porównanie bez wielkości liter, bo nagłówki pokazują je z wielkiej.
     */
    public static function pathForName(?string $name): ?string
    {
        $name = mb_strtolower(trim((string) $name));
        if ($name === '') {
            return null;
        }
        foreach (self::countries() as $country) {
            if (mb_strtolower($country['name']) === $name) {
                return '/regiony/' . $country['code'];
            }
            foreach ($country['regions'] as $region) {
                if (mb_strtolower($region['name']) === $name) {
                    return '/regiony/' . $country['code'] . '/' . $region['code'];
                }
            }
        }
        return null;
    }

    /**
     * Liczniki treści per region (kod → liczby) jednym przebiegiem na rodzaj —
     * pod listy regionów na stronie kraju i spisie.
     *
     * @return array<string,array{upcoming:int,completed:int,trails:int,organizers:int}>
     */
    public static function contentCounts(): array
    {
        $db = Database::connection();
        $out = [];
        $add = static function (string $code, string $key, int $n) use (&$out): void {
            $out[$code] ??= ['upcoming' => 0, 'completed' => 0, 'trails' => 0, 'organizers' => 0];
            $out[$code][$key] = $n;
        };

        // Nadchodzące — ta sama definicja co filtry /wydarzenia.
        foreach (Event::upcomingCountsByRegion() as $row) {
            $add($row['code'], 'upcoming', (int) $row['event_count']);
        }
        foreach ($db->query("
            SELECT di.code, COUNT(DISTINCT e.id) AS n
              FROM events e
              JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'completed'
              JOIN event_regions er ON er.event_id = e.id
              JOIN dictionary_items di ON di.id = er.region_item_id
             GROUP BY di.id, di.code
        ") as $row) {
            $add($row['code'], 'completed', (int) $row['n']);
        }
        foreach ($db->query('
            SELECT di.code, COUNT(*) AS n
              FROM known_route_regions krr
              JOIN known_routes kr ON kr.id = krr.route_id AND kr.is_active = 1
              JOIN dictionary_items di ON di.id = krr.region_item_id
             GROUP BY di.id, di.code
        ') as $row) {
            $add($row['code'], 'trails', (int) $row['n']);
        }
        foreach ($db->query('
            SELECT di.code, COUNT(*) AS n
              FROM organizer_profiles op
              JOIN dictionary_items di ON di.id = op.region_item_id
             WHERE op.is_active = 1
             GROUP BY di.id, di.code
        ') as $row) {
            $add($row['code'], 'organizers', (int) $row['n']);
        }
        return $out;
    }

    /**
     * Kadr mapy regionu [south, west, north, east] z geometrii regionu
     * (`RegionOutline::allRegions()` — pliki GeoJSON, nie 1,5 mln heksów
     * z `region_cells`). null, gdy region nie ma obrysu.
     */
    public static function bounds(string $code): ?array
    {
        $rings = self::rings($code);
        if (!$rings) {
            return null;
        }
        $lats = [];
        $lons = [];
        foreach ($rings as $ring) {
            foreach ($ring as $point) {
                $lats[] = (float) $point[0];
                $lons[] = (float) $point[1];
            }
        }
        if (!$lats) {
            return null;
        }
        return ['south' => min($lats), 'west' => min($lons), 'north' => max($lats), 'east' => max($lons)];
    }

    /**
     * Granica regionu do narysowania na mapie (2026-09-14, prośba usera:
     * „granice regionu na mapie — gruba czerwona linia"): lista pierścieni
     * [[lat, lon], ...] z tej samej geometrii co kadr, zaokruglona do 5 miejsc
     * (~1 m) — pełna precyzja GeoJSON-a to sam balast w HTML-u strony.
     *
     * @return list<list<array{0:float,1:float}>>
     */
    public static function rings(string $code): array
    {
        static $cache = [];
        if (!array_key_exists($code, $cache)) {
            $region = RegionOutline::allRegions()[$code] ?? null;
            $cache[$code] = $region === null ? [] : array_map(
                static fn(array $ring): array => array_map(
                    static fn(array $p): array => [round((float) $p[0], 5), round((float) $p[1], 5)],
                    $ring
                ),
                $region['rings']
            );
        }
        return $cache[$code];
    }

    /** Nazwa do nagłówka — województwa są w słowniku małą literą. */
    public static function displayName(string $name): string
    {
        // Tylko do WYŚWIETLENIA — w innym języku przez słownik (nazwy krajów;
        // regiony bez wpisu zostają jak są, to nazwy własne).
        return __(mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1));
    }

    private static function flatRegion(array $country): array
    {
        return ['id' => $country['id'], 'code' => $country['code'], 'name' => $country['name']];
    }

    /** Aktywne liście poddrzewa (dowolna głębokość) — jak w `Dictionary::groupedLeaves()`. */
    private static function activeLeaves(array $nodes): array
    {
        $leaves = [];
        foreach ($nodes as $node) {
            if (!$node['isActive']) {
                continue;
            }
            $children = array_filter($node['children'], static fn(array $c): bool => $c['isActive']);
            $leaves = $children ? array_merge($leaves, self::activeLeaves($node['children'])) : array_merge($leaves, [$node]);
        }
        return $leaves;
    }
}
