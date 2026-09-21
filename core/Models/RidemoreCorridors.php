<?php
// core/Models/RidemoreCorridors.php
//
// DANE WARSTWY ROUTINGU RIDEMORE (2026-09-18) — wszystko, co warstwa czyta
// z bazy; sama logika (korytarze, RidemoreScore, warianty, koszt) żyje w
// Utils\RidemoreRouting i bazy nie dotyka.
//
// Żadnej nowej tabeli: linie to geometria, którą już rysują kafle
// (gpx_geometry / gpx_tiles, w tym PRZYCIĘTE kopie cudzych solo — §27),
// popularność to `rider_activities` (kto, ile razy, kiedy) i ślady
// odbytych wyjazdów (`edition_tracks` + uczestnicy), a lokalna skala to
// heksy `discovery_cell_totals` z okolicy trasy.
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;
use Utils\RoadAttributeCache;

class RidemoreCorridors
{
    // Pierwszeństwo przy nakładaniu się odcinków = kolejność na liście źródeł.
    public const SOURCE_RANK = ['mine' => 0, 'known' => 1, 'community' => 2];

    /**
     * Linie z zaznaczonych źródeł w podanych kaflach indeksu (INDEX_Z) — te
     * same zbiory, które rysują warstwy mapy: `me` (moje przejazdy, pełna
     * geometria — tylko właściciel, bo klucz rozwiązuje się z zalogowanego),
     * `kr` (znane trasy) i `all` (społeczność: wyjazdy + solo w PRZYCIĘTEJ
     * kopii, §27). Na źródło najwyżej $perSource linii, najczęściej
     * trafiających w kafle. Hash obecny w kilku źródłach zostaje przy tym
     * wyżej na liście — z wyjątkiem pary pełna/przycięta kopia tego samego
     * przejazdu, które mają różną geometrię.
     *
     * $withOwners dokłada do każdej linii, KTO nią jechał i ile razy
     * (`owners`) — potrzebne do RidemoreScore, zbędne do samego przyciągania.
     * Znana trasa niesie też `surface` (% asfaltu/szutru/trail, NULL =
     * nieznane) pod profil roweru.
     *
     * @param array{mine:bool,known:bool,community:bool} $sources
     * @param array<int,true> $tiles klucze (tx << 32) | ty
     * @return list<array{rank:int,source:string,label:?string,hash:string,known:bool,flat:list<int>,minPx:int,minPy:int,maxPx:int,maxPy:int,owners?:array<string,array{n:int,last:?string}>}>
     */
    public static function lines(array $sources, array $tiles, int $perSource, bool $withOwners = false): array
    {
        if (!$tiles) {
            return [];
        }

        $wanted = []; // źródło => lista [trimmed, hash, etykieta]
        if (!empty($sources['mine'])) {
            foreach (TileSource::tracks('me') as $group) {
                if (($group['style'] ?? '') !== 'real') {
                    continue; // trasy wyjazdów, na które się tylko zapisano — to nie przejazdy
                }
                foreach ($group['hashes'] as $hash) {
                    $wanted['mine'][] = [($group['source'] ?? null) === 'trimmed', $hash, null];
                }
            }
        }
        $surface = [];
        if (!empty($sources['known'])) {
            foreach (KnownRoute::activeGeometryInfo() as $hash => $info) {
                $wanted['known'][] = [false, $hash, $info['name']];
                $surface[$hash] = ['asphalt' => $info['asphalt'], 'gravel' => $info['gravel'], 'trail' => $info['trail']];
            }
        }
        if (!empty($sources['community'])) {
            foreach (TileSource::tracks('all') as $group) {
                foreach ($group['hashes'] as $hash) {
                    $wanted['community'][] = [($group['source'] ?? null) === 'trimmed', $hash, null];
                }
            }
        }
        if (!$wanted) {
            return [];
        }

        $hits = [false => GpxGeometry::hitsInTiles($tiles), true => []];
        if (!empty($wanted['community']) || !empty($wanted['mine'])) {
            $hits[true] = GpxGeometry::hitsInTiles($tiles, true);
        }

        $picked = []; // "t|hash" => [rank, source, label]
        foreach (self::SOURCE_RANK as $source => $rank) {
            $candidates = [];
            foreach ($wanted[$source] ?? [] as [$trimmed, $hash, $label]) {
                $key = ($trimmed ? 't|' : 'f|') . $hash;
                if (isset($picked[$key]) || !isset($hits[$trimmed][$hash])) {
                    continue;
                }
                $candidates[$key] = [$hits[$trimmed][$hash], $rank, $source, $label];
            }
            uasort($candidates, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
            foreach (array_slice($candidates, 0, $perSource, true) as $key => $c) {
                $picked[$key] = [$c[1], $c[2], $c[3]];
            }
        }
        if (!$picked) {
            return [];
        }

        $full = [];
        $trimmed = [];
        foreach (array_keys($picked) as $key) {
            if (str_starts_with($key, 't|')) {
                $trimmed[] = substr($key, 2);
            } else {
                $full[] = substr($key, 2);
            }
        }
        $geometry = ['f' => GpxGeometry::load($full), 't' => $trimmed ? GpxGeometry::loadTrimmed($trimmed) : []];
        $owners = $withOwners ? self::owners(array_map(static fn(string $k): string => substr($k, 2), array_keys($picked))) : [];
        $attributes = $withOwners
            ? RoadAttributeCache::many(array_map(static fn(string $k): string => substr($k, 2), array_keys($picked)))
            : [];

        $lines = [];
        foreach ($picked as $key => [$rank, $source, $label]) {
            $hash = substr($key, 2);
            $g = $geometry[$key[0]][$hash] ?? null;
            if ($g === null || count($g['pts']) < 4) {
                continue;
            }
            $line = [
                'rank' => $rank, 'source' => $source, 'label' => $label, 'hash' => $hash,
                'known' => $source === 'known', 'flat' => $g['pts'],
                'minPx' => $g['minPx'], 'minPy' => $g['minPy'], 'maxPx' => $g['maxPx'], 'maxPy' => $g['maxPy'],
            ];
            if ($withOwners) {
                $line['owners'] = $source === 'known' ? [] : ($owners[$hash] ?? []);
                // Przycięta kopia prywatnego śladu zachowuje hash oryginału,
                // ale nie jego pozycję 0..1. Do czasu jawnego mapowania końców
                // bezpieczniej zostawić ją neutralną niż przypisać zły wycinek.
                if ($key[0] !== 't' && isset($attributes[$hash])) {
                    $line['attributes'] = $attributes[$hash];
                    $line['attributeFrom'] = 0.0;
                    $line['attributeTo'] = 1.0;
                }
            }
            if ($source === 'known') {
                $line['surface'] = $surface[$hash] ?? ['asphalt' => null, 'gravel' => null, 'trail' => null];
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Kto jechał danym śladem i ile razy — klucz właściciela to `u{id}`.
     *
     *  • Przejazd z własnym plikiem (solo, import): wiersze `rider_activities`
     *    z tym hashem — ten sam plik wgrany dwa razy to dwa przejazdy.
     *  • Ślad odbytego wyjazdu (`edition_tracks`): własny ślad uczestnika
     *    należy do niego; ślad organizatora (user_id NULL) to trasa, którą
     *    przejechali WSZYSCY uczestnicy z „Byłem" (`event_track`) — wyjazd
     *    grupowy to mocny dowód, że tędy da się jechać. Bez uczestników ślad
     *    liczy się jako jeden jeździec `e{wyjazd}`.
     *
     * `last` = data ostatniego przejazdu (`ride_date`) — pod mnożnik świeżości.
     * `types` = kody typów rowerów tych przejazdów (migr. 091; null = nieznany)
     * — planer nie liczy przejazdów na rowerze niezgodnym z profilem.
     * NIE `discovery_cell_totals.last_seen_at`: to moment pierwszego odkrycia
     * pola zapisany przy imporcie, nie data jazdy.
     *
     * @param string[] $hashes
     * @return array<string,array<string,array{n:int,last:?string,types:list<?string>}>> hash => właściciel => {n, last, types}
     */
    public static function owners(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes, static fn($h): bool => is_string($h) && preg_match('/^[0-9a-f]{64}$/', $h) === 1)));
        if (!$hashes) {
            return [];
        }
        $db = Database::connection();
        $wanted = array_fill_keys($hashes, true);
        $out = [];
        $add = static function (string $hash, string $owner, int $n, ?string $last, ?string $types) use (&$out): void {
            $cur = $out[$hash][$owner] ?? ['n' => 0, 'last' => null, 'types' => []];
            $cur['n'] += $n;
            if ($last !== null && ($cur['last'] === null || $last > $cur['last'])) {
                $cur['last'] = $last;
            }
            // GROUP_CONCAT typów: pusty kod = przejazd bez typu roweru.
            foreach (explode(',', (string) $types) as $code) {
                $code = $code !== '' ? $code : null;
                if (!in_array($code, $cur['types'], true)) {
                    $cur['types'][] = $code;
                }
            }
            $out[$hash][$owner] = $cur;
        };
        $typeSql = "GROUP_CONCAT(DISTINCT COALESCE(bt.code, '')) AS types";

        $in = "'" . implode("','", $hashes) . "'";
        foreach ($db->query(
            "SELECT ra.gpx_hash, ra.user_id, COUNT(*) AS n, MAX(ra.ride_date) AS last, $typeSql
               FROM rider_activities ra LEFT JOIN dictionary_items bt ON bt.id = ra.bike_type_item_id
              WHERE ra.gpx_hash IN ($in) GROUP BY ra.gpx_hash, ra.user_id"
        ) as $r) {
            $add($r['gpx_hash'], 'u' . (int) $r['user_id'], (int) $r['n'], $r['last'], $r['types']);
        }

        // Ślady wyjazdów — hash liczy się z pliku (ta sama pamięć podręczna co
        // warstwa kafli `all`, więc zwykle bez czytania plików).
        $tracks = [];
        foreach ($db->query('SELECT edition_id, user_id, gpx_url FROM edition_tracks') as $r) {
            $hash = GpxGeometry::fileHash(TileSource::absolutePath((string) $r['gpx_url']));
            if ($hash !== null && isset($wanted[$hash])) {
                $tracks[] = [$hash, (int) $r['edition_id'], $r['user_id'] !== null ? (int) $r['user_id'] : null];
            }
        }
        if (!$tracks) {
            return $out;
        }

        $editions = array_values(array_unique(array_map(static fn(array $t): int => $t[1], $tracks)));
        $stmt = $db->query(
            'SELECT ra.edition_id, ra.user_id, COUNT(*) AS n, MAX(ra.ride_date) AS last, ' . $typeSql . '
               FROM rider_activities ra LEFT JOIN dictionary_items bt ON bt.id = ra.bike_type_item_id
              WHERE ra.source_code IN (\'event_track\', \'own_track\') AND ra.edition_id IN (' . implode(',', $editions) . ')
              GROUP BY ra.edition_id, ra.user_id, ra.source_code'
        );
        $riders = []; // wyjazd => [user_id => [n, last, types]]
        foreach ($stmt as $r) {
            $riders[(int) $r['edition_id']][(int) $r['user_id']] = [(int) $r['n'], $r['last'], $r['types']];
        }

        foreach ($tracks as [$hash, $editionId, $userId]) {
            if ($userId !== null) {
                $own = $riders[$editionId][$userId] ?? [1, null, null];
                $add($hash, 'u' . $userId, 1, $own[1], $own[2]);
                continue;
            }
            if (empty($riders[$editionId])) {
                $add($hash, 'e' . $editionId, 1, null, null);
                continue;
            }
            foreach ($riders[$editionId] as $uid => [$n, $last, $types]) {
                $add($hash, 'u' . $uid, $n, $last, $types);
            }
        }
        return $out;
    }

    /**
     * Lokalna skala popularności: 90. percentyl liczby osób i przejazdów
     * w polach (heksach ~500 m) okolicy, przez które ktokolwiek jechał.
     * „3 osoby w Bieszczadach" i „30 osób w Krakowie" mają przez to podobną
     * wagę — porównujemy okolicę z okolicą, nie z całym krajem. Heksy są tu
     * wyłącznie ODNIESIENIEM; popularność samego odcinka liczy się
     * z geometrii śladów (heks nie odróżnia dwóch równoległych dróg).
     *
     * @param array{south:float,west:float,north:float,east:float} $bounds
     * @return array{riders:float,passes:float}
     */
    public static function localReference(array $bounds): array
    {
        $range = DiscoveryGrid::axialRangeForBounds(
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'], DiscoveryGrid::RES_CELL
        );
        $stmt = Database::connection()->prepare(
            'SELECT riders_count, passes_count FROM discovery_cell_totals
              WHERE cell_r BETWEEN :r_min AND :r_max AND cell_q BETWEEN :q_min AND :q_max AND riders_count > 0'
        );
        $stmt->execute(['r_min' => $range['rMin'], 'r_max' => $range['rMax'], 'q_min' => $range['qMin'], 'q_max' => $range['qMax']]);
        $riders = [];
        $passes = [];
        while ($r = $stmt->fetch(\PDO::FETCH_NUM)) {
            $riders[] = (int) $r[0];
            $passes[] = (int) $r[1];
        }
        return ['riders' => self::percentile($riders, 0.9), 'passes' => self::percentile($passes, 0.9)];
    }

    /**
     * Odcisk danych, od których zależy wynik warstwy — zmienia się, gdy
     * przybędzie/ubędzie przejazdu, śladu wyjazdu albo znanej trasy. Klucz
     * pamięci podręcznej odcinka, żeby nowy przejazd nie czekał na TTL.
     */
    public static function fingerprint(): string
    {
        $row = Database::connection()->query(
            "SELECT (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(id), 0)) FROM rider_activities),
                    (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(id), 0), ':', COALESCE(SUM(is_active), 0)) FROM known_routes),
                    (SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(id), 0)) FROM edition_tracks)"
        )->fetch(\PDO::FETCH_NUM);
        return implode('|', array_map('strval', $row ?: [])) . '|attrs:' . RoadAttributeCache::fingerprint();
    }

    /** @param list<int> $values */
    private static function percentile(array $values, float $q): float
    {
        if (!$values) {
            return 0.0;
        }
        sort($values);
        return (float) $values[(int) floor($q * (count($values) - 1))];
    }
}
