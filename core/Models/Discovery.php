<?php
// core/Models/Discovery.php
// Etap 8 — ODCZYTY Discovery: mapa osobista, wspólna mapa społeczności,
// podsumowania. Zapisem zajmuje się wyłącznie Models\RiderActivity.
//
// PRYWATNOŚĆ (§27) — reguła całego pliku: wspólna mapa mówi ILE, nigdy KTO.
// Nie ma tu i nie może powstać metoda zwracająca listę osób, które odkryły
// dane pole. Pojedyncze pole przy czyimś domu zdradzałoby, kto tam mieszka, a
// serwis ma już ustaloną zasadę z "Kto jedzie" i Kroniki: ukrywamy TOŻSAMOŚĆ,
// nie FAKT.
//
// Przy dzisiejszym źródle danych ('event_route') ryzyko jest zresztą zerowe —
// geometria pochodzi z opublikowanych tras organizatorów, więc mapa nie
// zawiera ani jednego metra, którego nie było wcześniej publicznie. Ta
// gwarancja znika w dniu, w którym rowerzyści zaczną wgrywać własne ślady, i
// wtedy trzeba będzie dodać wycinanie okolic startu/mety.
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;

class Discovery
{
    // Ile pól maksymalnie zwracamy do jednego widoku mapy. Zabezpieczenie
    // przed pytaniem o pół Europy przy pełnym oddaleniu; przy sensownym
    // poziomie agregacji nigdy nie jest osiągane.
    private const MAX_CELLS_PER_VIEW = 6000;

    // Ile pól osobistych wczytujemy na mapę profilu. Osobista mapa nie ma
    // zapytania prostokątnego (jedna osoba to najwyżej kilka tysięcy pól, a
    // pobranie ich naraz jest szybsze niż indeks pod bbox) — ale limit musi
    // istnieć, żeby nikt po latach nie wywalił sobie strony własną historią.
    private const MAX_PERSONAL_CELLS = 20000;

    // Pola jednego przejazdu wstawiane wprost w HTML kroniki. Najdłuższy realny
    // dzień etapowy to kilkaset pól; limit jest bezpiecznikiem przed wielodniówką
    // z wgranym śladem całego tygodnia, nie spodziewaną wartością.
    private const MAX_RIDE_CELLS = 3000;

    // ---------------------------------------------------------------
    // Moje Discovery (§6.1)
    // ---------------------------------------------------------------

    /**
     * Twarze do kafla „RAZEM ODKRYLIŚMY" — ostatni, którzy coś odkryli.
     * Wyłącznie osoby widoczne na listach (migr. 037): kafel jest ozdobą,
     * a ozdoba nie może łamać czyjejś decyzji o ukryciu się.
     */
    public static function recentDiscoverers(int $limit = 5): array
    {
        $stmt = Database::connection()->prepare('
            SELECT u.id, u.name, u.email, u.avatar_url, u.public_slug,
                   MAX(dc.discovered_at) AS last_at
              FROM discovery_cells dc
              JOIN users u ON u.id = dc.user_id AND u.roster_visible = 1
             GROUP BY u.id, u.name, u.email, u.avatar_url, u.public_slug
             ORDER BY last_at DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Ile regionów ta osoba zna, na ile istniejących — kafel „REGIONY" z
     * projektu. OD MIGRACJI 070 region = województwo, a „odkryty region"
     * znaczy: masz w nim co najmniej jeden odkryty heks (`region_cells`).
     * Wcześniej liczyły się regiony WYDARZEŃ z potwierdzoną obecnością —
     * czyli deklaracja organizatora, nie teren faktycznie przejechany;
     * skoro pokrycie heksowe pozwala rozstrzygnąć to naprawdę, definicja
     * przeszła na heksową (decyzja usera 2026-08-25). Wydarzenia dalej dają
     * punkty przez rejestr — one tylko już NIE definiują regionu.
     *
     * SCOPED DO JEDNEGO KRAJU (2026-09-05, `$countryCode`, domyślnie 'polska').
     * Do migracji 070 region ZAWSZE znaczył województwo, więc licznik i
     * mianownik nigdy nie musiały pytać „którego kraju" — ale odkąd
     * `/admin/regiony-mapa` pozwala dorysować region BEZ rodzica (kraj, który
     * jest jednocześnie jedynym swoim regionem — Czechy, Słowacja), liście
     * słownika przestały być jednym zbiorem. Test na żywo (2026-09-05,
     * fikcyjna „Słowacja" z 7 heksami w dev): bez tego filtra kafel pokazywał
     * „1 z 17" zamiast „1 z 16" — zagraniczny region dolewał się po cichu do
     * mianownika WOJEWÓDZTW. Domyślna wartość zachowuje dokładne zachowanie
     * sprzed tej poprawki, dopóki w bazie jest tylko Polska.
     *
     * @return array{visited:int, total:int}
     */
    public static function regionsForUser(int $userId, string $countryCode = 'polska'): array
    {
        $db = Database::connection();

        // Kraj liścia = kod RODZICA, a dla liścia BEZ rodzica (kraj-region
        // płaski, patrz nota wyżej) — jego WŁASNY kod.
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT rc.region_item_id)
              FROM discovery_cells dc
              JOIN region_cells rc ON rc.cell_id = dc.cell_id
              JOIN dictionary_items di ON di.id = rc.region_item_id
              LEFT JOIN dictionary_items parent ON parent.id = di.parent_id
             WHERE dc.user_id = :user_id
               AND COALESCE(parent.code, di.code) = :country
        ');
        $stmt->execute(['user_id' => $userId, 'country' => $countryCode]);

        // Mianownik: aktywne liście słownika regionów TEGO KRAJU (kontener
        // „Polska" nie jest regionem, do którego da się pojechać), które MAJĄ
        // pokrycie heksowe.
        $leavesWithCoverage = $db->prepare("
            SELECT COUNT(DISTINCT di.id)
              FROM dictionary_items di
              JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'region'
              JOIN region_cells rc ON rc.region_item_id = di.id
              LEFT JOIN dictionary_items parent ON parent.id = di.parent_id
             WHERE di.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM dictionary_items c WHERE c.parent_id = di.id AND c.is_active = 1)
               AND COALESCE(parent.code, di.code) = :country
        ");
        $leavesWithCoverage->execute(['country' => $countryCode]);
        $total = (int) $leavesWithCoverage->fetchColumn();

        if ($total === 0) {
            // Import pokrycia jeszcze nie ruszył (backfill_regions.php) —
            // bez tego kafel pokazywałby „0 z 0". Wracamy wtedy do zwykłej
            // liczby aktywnych liści TEGO KRAJU, żeby kafel miał sensowny
            // mianownik.
            $fallback = $db->prepare("
                SELECT COUNT(*)
                  FROM dictionary_items di
                  JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'region'
                  LEFT JOIN dictionary_items parent ON parent.id = di.parent_id
                 WHERE di.is_active = 1
                   AND NOT EXISTS (SELECT 1 FROM dictionary_items c WHERE c.parent_id = di.id AND c.is_active = 1)
                   AND COALESCE(parent.code, di.code) = :country
            ");
            $fallback->execute(['country' => $countryCode]);
            $total = (int) $fallback->fetchColumn();
        }

        return ['visited' => (int) $stmt->fetchColumn(), 'total' => $total];
    }

    /**
     * Identyfikatory regionów, w których ten user ma choć jedno odkryte pole —
     * inaczej niż `regionsForUser()` wyżej (ten oddaje LICZBĘ pod kafel), to
     * ZBIÓR do dopasowania „user jeździ w tej okolicy" (Etap 8 przebudowy
     * apki, `Models\PushNotifier::runTreasuresNearby()` — powiadomienie
     * o nowym skarbie w regionie, w którym user realnie bywał).
     *
     * @return int[]
     */
    public static function regionIdsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT DISTINCT rc.region_item_id
              FROM discovery_cells dc
              JOIN region_cells rc ON rc.cell_id = dc.cell_id
             WHERE dc.user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Pokrycie per województwo: ile heksów ma region, ile odkryła SPOŁECZNOŚĆ,
     * ile osoba — plus gotowe procenty. Paliwo pod kafel „Regiony", sekcję
     * „Wspólnie odkrywamy" i cele regionalne (migr. 070 dała mianownik, którego
     * brak był powodem zakazu procentów pokrycia).
     *
     * DWA zapytania zamiast jednego z dwoma LEFT JOIN-ami celowo: dołączenie
     * discovery_cells i discovery_cell_totals NARAZ mnożyłoby wiersze (osoba
     * i społeczność pasują niezależnie) i wymuszałoby COUNT(DISTINCT) po
     * 1,5 mln wierszy. Rozdzielone, każde po jednym JOIN-ie 1:1/1:n bez
     * mnożenia, liczą się przewidywalnie.
     *
     * GRUPOWANIE PO KRAJU (2026-09-05). Region poza Polską (Czechy, Słowacja —
     * `/admin/regiony-mapa`, kraj BEZ rodzica pełniący jednocześnie funkcję
     * jedynego swojego regionu) zweryfikowany na żywo w dev: bez tego jego
     * heksy dolewały się po cichu do `grand` — „Polska odkryta 0,3%" liczyłoby
     * ułamek ZE ŚWIATA, nie z Polski, i rosłoby przy każdym kolejnym kraju.
     * `grand` zostaje więc SCOPED do `$homeCountryCode` (domyślnie 'polska',
     * dokładnie to, co znaczył zawsze, dopóki innych krajów nie było) — zero
     * różnicy w wyniku, dopóki w bazie jest tylko Polska. `countries` to NOWY
     * klucz: jedna pozycja na każdy kraj z pokryciem, z własnym total/mine/pct
     * — do grupowania „Polska X% [jej regiony…], Słowacja Y% [jej regiony…]"
     * bez drugiego zapytania. Każdy element `regions` dostaje `countryId`/
     * `countryCode`/`countryName`, żeby widok mógł pogrupować bez ponownego
     * czytania słownika.
     *
     * @param int|null $userId null = tylko liczby społeczności (gość)
     * @return array{
     *   regions:list<array{id:int,code:string,name:string,countryId:int,
     *         countryCode:string,countryName:string,total:int,
     *         community:int,pctCommunity:float,mine:int,pctMine:float}>,
     *   countries:list<array{id:int,code:string,name:string,total:int,
     *         community:int,pctCommunity:float,mine:int,pctMine:float}>,
     *   grand:array{total:int,community:int,mine:int,pctCommunity:float,pctMine:float}}
     */
    public static function regionProgress(?int $userId, string $homeCountryCode = 'polska'): array
    {
        $db = Database::connection();

        // KIERUNEK ZŁĄCZEŃ MA ZNACZENIE przy 1,5 mln heksów pokrycia: duża
        // tabela jest zawsze CELEM indeksowego lookupu, nigdy stroną skanowaną.
        //  - mianownik: MATERIALIZOWANY (region_cell_counts, migr. 071) —
        //    zmienia się wyłącznie przy imporcie geometrii, a skan grupujący
        //    po całym pokryciu kosztował ~1,5 s na każde wejście na stronę;
        //    pusta tabela (brak importu) = fallback na liczenie z region_cells;
        //  - społeczność: prowadzi discovery_cell_totals (tylko odkryte pola),
        //    każdy robi jeden unikalny lookup po cell_id;
        //  - moje: prowadzi discovery_cells użytkownika (PK prefix user_id).
        $totals = [];
        foreach ($db->query('
            SELECT c.region_item_id AS id, c.cells_total AS total
              FROM region_cell_counts c
             ORDER BY c.region_item_id
        ') as $row) {
            $totals[(int) $row['id']] = (int) $row['total'];
        }
        if (!$totals) {
            foreach ($db->query('
                SELECT region_item_id AS id, COUNT(*) AS total
                  FROM region_cells
                 GROUP BY region_item_id
            ') as $row) {
                $totals[(int) $row['id']] = (int) $row['total'];
            }
        }

        $community = [];
        foreach ($db->query('
            SELECT rc.region_item_id AS id, COUNT(*) AS community
              FROM discovery_cell_totals ct
              JOIN region_cells rc ON rc.cell_id = ct.cell_id
             GROUP BY rc.region_item_id
        ') as $row) {
            $community[(int) $row['id']] = (int) $row['community'];
        }

        $mine = [];
        if ($userId !== null) {
            $stmt = $db->prepare('
                SELECT rc.region_item_id AS id, COUNT(*) AS mine
                  FROM discovery_cells dc
                  JOIN region_cells rc ON rc.cell_id = dc.cell_id
                 WHERE dc.user_id = :user
                 GROUP BY rc.region_item_id
            ');
            $stmt->execute(['user' => $userId]);
            foreach ($stmt->fetchAll() as $row) {
                $mine[(int) $row['id']] = (int) $row['mine'];
            }
        }

        // WSZYSTKIE aktywne pozycje słownika `region` — liście I kontenery-kraje
        // JEDNYM zapytaniem (zastępuje dawne osobne „tylko liście": kraj liścia
        // trzeba znać, więc i tak trzeba było przeczytać całe drzewo).
        $allItems = [];
        foreach ($db->query("
            SELECT di.id, di.parent_id, di.code, di.name
              FROM dictionary_items di
              JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'region'
             WHERE di.is_active = 1
        ") as $row) {
            $allItems[(int) $row['id']] = [
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'code'      => $row['code'],
                'name'      => $row['name'],
            ];
        }
        $hasActiveChild = [];
        foreach ($allItems as $item) {
            if ($item['parent_id'] !== null) { $hasActiveChild[$item['parent_id']] = true; }
        }

        // Nazwy + KRAJ per liść. Kraj to najwyższy przodek — dziś drzewo ma
        // najwyżej dwa poziomy (kraj → jego regiony), ale idziemy w górę
        // pętlą, nie zakładamy tej głębokości na sztywno. Liść BEZ rodzica
        // (kraj-region płaski typu „Słowacja") jest krajem SAM DLA SIEBIE.
        $names = [];
        $countryOf = [];
        foreach ($allItems as $id => $item) {
            if (!empty($hasActiveChild[$id])) { continue; } // kontener, nie liść
            $names[$id] = ['code' => $item['code'], 'name' => $item['name']];

            $countryId = $id;
            $countryItem = $item;
            while ($countryItem['parent_id'] !== null && isset($allItems[$countryItem['parent_id']])) {
                $countryId = $countryItem['parent_id'];
                $countryItem = $allItems[$countryId];
            }
            $countryOf[$id] = ['id' => $countryId, 'code' => $countryItem['code'], 'name' => $countryItem['name']];
        }

        $pct = static fn(int $part, int $whole): float =>
            $whole > 0 ? round($part / $whole * 100, 1) : 0.0;

        $regions = [];
        $countries = []; // code => suma bieżąca
        $home = ['total' => 0, 'community' => 0, 'mine' => 0];
        foreach ($totals as $id => $total) {
            if (!isset($names[$id])) { continue; } // pokrycie bez aktywnego liścia — pomiń, nie psuj ekranu
            // UWAGA NA NAZWY: skalar MUSI się inaczej nazywać niż tablica
            // $community powyżej — przysłonięcie jej w pierwszej iteracji
            // zerowało społeczność dla wszystkich kolejnych regionów.
            $communityCount = (int) ($community[$id] ?? 0);
            $myCount = (int) ($mine[$id] ?? 0);
            $country = $countryOf[$id];

            if ($country['code'] === $homeCountryCode) {
                $home['total'] += $total;
                $home['community'] += $communityCount;
                $home['mine'] += $myCount;
            }

            if (!isset($countries[$country['code']])) {
                $countries[$country['code']] = [
                    'id' => $country['id'], 'code' => $country['code'], 'name' => $country['name'],
                    'total' => 0, 'community' => 0, 'mine' => 0,
                ];
            }
            $countries[$country['code']]['total']     += $total;
            $countries[$country['code']]['community'] += $communityCount;
            $countries[$country['code']]['mine']      += $myCount;

            $regions[] = [
                'id'            => $id,
                'code'          => $names[$id]['code'],
                'name'          => $names[$id]['name'],
                'countryId'     => $country['id'],
                'countryCode'   => $country['code'],
                'countryName'   => $country['name'],
                'total'         => $total,
                'community'     => $communityCount,
                'pctCommunity'  => $pct($communityCount, $total),
                'mine'          => $myCount,
                'pctMine'       => $pct($myCount, $total),
            ];
        }

        usort($regions, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

        foreach ($countries as &$c) {
            $c['pctCommunity'] = $pct($c['community'], $c['total']);
            $c['pctMine'] = $pct($c['mine'], $c['total']);
        }
        unset($c);
        $countries = array_values($countries);
        // Kraj domowy zawsze pierwszy (to on ma być "Polska" na górze listy,
        // nie przypadkową kolejnością sortowania), reszta wg wielkości pokrycia.
        usort($countries, static fn(array $a, array $b): int => match (true) {
            $a['code'] === $homeCountryCode => -1,
            $b['code'] === $homeCountryCode => 1,
            default => $b['total'] <=> $a['total'],
        });

        return [
            'regions'   => $regions,
            'countries' => $countries,
            'grand'     => [
                'total'        => $home['total'],
                'community'    => $home['community'],
                'mine'         => $home['mine'],
                'pctCommunity' => $pct($home['community'], $home['total']),
                'pctMine'      => $pct($home['mine'], $home['total']),
            ],
        ];
    }

    /**
     * POTENCJAŁ REGIONÓW — ranking „gdzie jest co wziąć" dla jednej osoby:
     * nieodkryte heksy × stawka za pole + punkty skarbów, których jeszcze nie
     * masz, + liczba znanych tras przecinających region.
     *
     * TO JEST HEURYSTYKA DO RANKINGU CELÓW, NIE OBIECAWNICA PUNKTÓW. Wynik
     * (`score`) porządkuje regiony między sobą i tyle: premii za nowy teren
     * (EXPLORATION) nie wolno obiecywać, bo jest warunkowa, a progi tras mają
     * własną drabinkę liczona przez DiscoveryScoring::trailAwards na stronie
     * trasy. Pola widoku mogą cytować SKŁADNIKI („X heksów", „Y skarbów"),
     * nigdy samej sumy jako gwarancji.
     *
     * @param int $userId osoba, dla której liczymy „jeszcze nieodkryte"
     * @param int $limit ile regionów zwrócić (top wg score)
     * @return list<array{id:int,code:string,name:string,total:int,mine:int,
     *         cellsLeft:int,treasures:int,treasurePoints:int,routes:int,score:int}>
     */
    public static function regionPotentials(int $userId, int $limit = 3): array
    {
        $limit = max(1, $limit);
        $progress = self::regionProgress($userId);
        if (!$progress['regions']) {
            return [];
        }

        $db = Database::connection();

        // Skarby do wzięcia per region: aktywne, w oknie dat, NIEznalezione
        // przez tę osobę. Ta sama definicja „do wzięcia" co Treasure::onRoute —
        // skarb na heksie regionu, nie w promieniu od granicy. Prowadzi tabela
        // SKARBÓW (kilkanaście tysięcy), nie 1,5 mln heksów pokrycia.
        $stmt = $db->prepare('
            SELECT rc.region_item_id AS id,
                   COUNT(t.id)       AS treasures,
                   COALESCE(SUM(t.points), 0) AS points
              FROM treasures t
              JOIN region_cells rc ON rc.cell_id = t.cell_id
              LEFT JOIN treasure_finds f ON f.treasure_id = t.id AND f.user_id = :user
             WHERE t.is_active = 1 AND t.status = "ACTIVE"
               AND (t.active_from IS NULL OR t.active_from <= CURDATE())
               AND (t.active_to   IS NULL OR t.active_to   >= CURDATE())
               AND f.treasure_id IS NULL
             GROUP BY rc.region_item_id
        ');
        $stmt->execute(['user' => $userId]);
        $treasures = [];
        foreach ($stmt->fetchAll() as $row) {
            $treasures[(int) $row['id']] = [
                'count' => (int) $row['treasures'],
                'points' => (int) $row['points'],
            ];
        }

        // Znane trasy dotykające regionu (aktywne, rysujące się na mapie).
        // Prowadzi known_route_cells — pól tras jest rzędy wielkości mniej
        // niż całego pokrycia kraju.
        $routes = [];
        foreach ($db->query('
            SELECT rc.region_item_id AS id,
                   COUNT(DISTINCT kr.id) AS routes
              FROM known_route_cells krc
              JOIN region_cells rc ON rc.cell_id = krc.cell_id
              JOIN known_routes kr ON kr.id = krc.route_id AND kr.is_active = 1
             GROUP BY rc.region_item_id
        ') as $row) {
            $routes[(int) $row['id']] = (int) $row['routes'];
        }

        // Stawka za nowe pole — TA SAMA konfiguracja, którą nalicza silnik
        // punktów; żadna osobna „stawka marketingowa" obok niej.
        $rate = (int) DiscoveryScoring::config()['discovery']['points_per_new_cell'];

        $out = [];
        foreach ($progress['regions'] as $region) {
            // SCOPED DO KRAJU DOMOWEGO (2026-09-05) — „Region z potencjałem"
            // zasila kartę „Następny cel" na /odkrycia, która mówi o Polsce
            // wprost w treści („teren do odkrycia" obok mapy Polski); region
            // zagraniczny (patrz regionProgress()) nie ma tu czego szukać,
            // dopóki karta nie nauczy się mówić, w którym jest kraju.
            if ($region['countryCode'] !== 'polska') { continue; }
            $cellsLeft = $region['total'] - $region['mine'];
            $t = $treasures[$region['id']] ?? ['count' => 0, 'points' => 0];
            if ($cellsLeft === 0 && $t['count'] === 0 && !isset($routes[$region['id']])) {
                continue; // nic tu dla tej osoby — nie zaśmiecaj rankingu
            }
            $out[] = [
                'id'             => $region['id'],
                'code'           => $region['code'],
                'name'           => $region['name'],
                'total'          => $region['total'],
                'mine'           => $region['mine'],
                'cellsLeft'      => $cellsLeft,
                'treasures'      => $t['count'],
                'treasurePoints' => $t['points'],
                'routes'         => $routes[$region['id']] ?? 0,
                'score'          => $cellsLeft * $rate + $t['points'],
            ];
        }

        usort($out, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /** Liczby na nagłówek strony odkryć i profil rowerzysty. */
    public static function summaryForUser(int $userId): array
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT COUNT(*) FROM discovery_cells WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $cells = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM rider_activities WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $rides = (int) $stmt->fetchColumn();

        // Punkty z REJESTRU, nie z sum na przejazdach (Etap 8A/7). Kolumny
        // points_* przestały istnieć — były denormalizacją, która nie umiała
        // odpowiedzieć „za co", a przy okazji nie miała gdzie pomieścić
        // punktów za jazdę ani za wydarzenie.
        $breakdown = PointLedger::breakdownForUser($userId);

        // Ile pól tej osoby to białe plamy, które sama zamalowała jako
        // pierwsza w całym ridemore — najmocniejsza liczba w tym module,
        // bo mówi o odkrywaniu, a nie o kilometrach.
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM discovery_cell_totals
             WHERE first_user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);
        $firstInCommunity = (int) $stmt->fetchColumn();

        return [
            'cells'            => $cells,
            'rides'            => $rides,
            // Rozbicie per źródło trafia dalej w całości — widok pokazuje
            // wszystko, co rejestr zna, i nie trzeba go ruszać przy każdym
            // nowym źródle punktów (misje, kolekcje).
            'pointsBySource'   => $breakdown,
            'pointsTotal'      => array_sum($breakdown),
            'firstInCommunity' => $firstInCommunity,
        ];
    }

    /** @return int[] identyfikatory pól tej osoby, pod jej własną mapę */
    public static function cellsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cell_id FROM discovery_cells WHERE user_id = :user_id LIMIT ' . self::MAX_PERSONAL_CELLS
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Bucket klastrowania kadru — patrz `boundsFor()`. Dość gruby, żeby cała
     * Polska mieściła się w garści sąsiadujących kubełków, i dość drobny,
     * żeby wyjazd kilka tysięcy km dalej wylądował w zupełnie innym.
     */
    private const CLUSTER_BUCKET_KM = 150;

    /**
     * Próg, od którego naiwny prostokąt (MIN/MAX po WSZYSTKICH polach) uznajemy
     * za skażony odległym wyjazdem — patrz `boundsFor()`. Szerzej niż
     * jakikolwiek pojedynczy kraj środkowoeuropejski, żeby normalny, duży
     * zasięg (cała społeczność w Polsce) NIGDY nie uruchamiał drugiego
     * zapytania.
     */
    private const CLUSTER_TRIGGER_KM = 1000;

    /**
     * Prostokąt obejmujący odkryte pola — kadr startowy mapy.
     *
     * `$userId` = null: wspólna mapa (wszystko, co odkrył ktokolwiek).
     *
     * PO CO: mapa musi znać swój kadr, ZANIM zapyta o pola. Do 2026-08-19
     * startowała w środku Polski przy oddaleniu 6, pobierała pola dla całego
     * kraju i dopiero po odpowiedzi dociągała się do tego, co dostała
     * (`fitToCells`) — czyli pokazywała zły obszar, robiła zbędne żądanie
     * i skakała w oczach. Kadr policzony tutaj jest ODPOWIEDZIĄ NA PYTANIE
     * „gdzie ten człowiek jeździ", a nie zgadywaniem ze środka kraju.
     *
     * JEDNO ZAPYTANIE AGREGUJĄCE W TYPOWYM PRZYPADKU, bez wyciągania pól do
     * PHP-a: skrajne współrzędne osiowe wystarczą, a `DiscoveryGrid::
     * boundsFromAxialExtremes()` zamienia je na lat/lon. `discovery_cell_totals`
     * ma `cell_q`/`cell_r` gotowe (migr. 040); `discovery_cells` trzyma sam
     * `cell_id`, więc `q`/`r` rozpakowuje SQL-em ta sama arytmetyka, którą zna
     * `DiscoveryGrid`.
     *
     * JEDEN ODLEGŁY WYJAZD POTRAFI PRZECIĄGNĄĆ ŚRODEK W PUSTKĘ (zgłoszenie
     * usera 2026-08-27: „centruje poza zakresem moich osiągnięć lub
     * społeczności"). Znalezione na żywo: rowerzysta z 89 przejazdami wokół
     * Mielca miał JEDEN wyjazd na Teneryfę (4500 km dalej) — MIN/MAX po
     * wszystkich polach dało prostokąt Ocean Atlantycki–Polska, a jego ŚRODEK
     * (Barcelona) nie leżał BLISKO ani jednego, ani drugiego skupiska. To NIE
     * jest błąd arytmetyki (`boundsFromAxialExtremes` liczy dokładnie —
     * `s = 2q + r` zależy WYŁĄCZNIE od `s`, więc prostokąt jest ścisły, nie
     * przybliżony), tylko naiwności założenia „jeden zwarty obszar". Prosta
     * łatka „bbox z kafli zamiast z pól" NIE POMOGŁABY: geometria śladu z
     * Teneryfy ma DOKŁADNIE ten sam skrajny punkt co jego pola.
     *
     * DLATEGO: gdy naiwny prostokąt jest szerszy niż `CLUSTER_TRIGGER_KM`,
     * DRUGIE zapytanie dzieli pola na `CLUSTER_BUCKET_KM`-owe kubełki i bierze
     * NAJWIĘKSZY (plus sąsiadów o 1 kubełek, żeby nie uciąć skupiska leżącego
     * w poprzek granicy) — mapa startuje tam, gdzie faktycznie jest większość
     * odkryć, a nie w geometrycznym środku między dwoma odległymi miejscami.
     * Drugie zapytanie płaci WYŁĄCZNIE ten, kto już ma odległy wyjazd —
     * typowy, zwarty zasięg (jedna osoba, cała społeczność w jednym kraju)
     * kończy się na pierwszym zapytaniu, jak dotychczas.
     *
     * @return array{south:float,west:float,north:float,east:float}|null
     *         null, gdy nie ma ani jednego pola — wtedy nie ma czego kadrować
     *         i mapa zostaje przy widoku domyślnym.
     */
    public static function boundsFor(?int $userId = null): ?array
    {
        $db = Database::connection();

        if ($userId === null) {
            $from = 'FROM discovery_cell_totals';
            $where = '';
            $params = [];
            $rExpr = 'cell_r';
            $sExpr = '(2 * cell_q + cell_r)';
        } else {
            $r = DiscoveryGrid::sqlR('cell_id');
            $q = DiscoveryGrid::sqlQ('cell_id');
            $from = 'FROM discovery_cells';
            $where = ' WHERE user_id = :user_id';
            $params = ['user_id' => $userId];
            $rExpr = $r;
            $sExpr = "(2 * $q + $r)";
        }

        $row = self::axialExtremes($db, $from, $where, $params, $rExpr, $sExpr);
        if ($row === null) {
            return null;
        }

        $size = DiscoveryGrid::sizeM(DiscoveryGrid::RES_CELL);
        // Metry realne z rozpiętości osiowej — te same wzory co w
        // `boundsFromAxialExtremes` (y zależy WYŁĄCZNIE od r, x WYŁĄCZNIE od s).
        $spanNS = $size * 1.5 * ($row['r_max'] - $row['r_min']);
        $spanEW = $size * sqrt(3) / 2 * ($row['s_max'] - $row['s_min']);

        if (max($spanNS, $spanEW) > self::CLUSTER_TRIGGER_KM * 1000) {
            $bucketR = max(1, (int) round(self::CLUSTER_BUCKET_KM * 1000 / ($size * 1.5)));
            $bucketS = max(1, (int) round(self::CLUSTER_BUCKET_KM * 1000 / ($size * sqrt(3) / 2)));

            $bucketStmt = $db->prepare("
                SELECT FLOOR($rExpr / :bucketR) AS rb, FLOOR($sExpr / :bucketS) AS sb, COUNT(*) AS n
                  $from
                  $where
                 GROUP BY rb, sb
                 ORDER BY n DESC
                 LIMIT 1
            ");
            $bucketStmt->execute($params + ['bucketR' => $bucketR, 'bucketS' => $bucketS]);
            $dominant = $bucketStmt->fetch();

            if ($dominant !== false) {
                $rb = (int) $dominant['rb'];
                $sb = (int) $dominant['sb'];
                $clustered = self::axialExtremes(
                    $db,
                    $from,
                    $where . ($where === '' ? ' WHERE ' : ' AND ')
                        . "FLOOR($rExpr / :bucketR2) BETWEEN :rbMin AND :rbMax
                            AND FLOOR($sExpr / :bucketS2) BETWEEN :sbMin AND :sbMax",
                    $params + [
                        'bucketR2' => $bucketR, 'bucketS2' => $bucketS,
                        'rbMin' => $rb - 1, 'rbMax' => $rb + 1,
                        'sbMin' => $sb - 1, 'sbMax' => $sb + 1,
                    ],
                    $rExpr,
                    $sExpr
                );
                // Puste tylko w teorii (bucket wzięty z GROUP BY tych samych
                // wierszy), ale zostawiamy naiwny prostokąt jako siatkę
                // bezpieczeństwa zamiast oddawać pustkę.
                if ($clustered !== null) {
                    $row = $clustered;
                }
            }
        }

        return DiscoveryGrid::boundsFromAxialExtremes(
            $row['r_min'],
            $row['r_max'],
            $row['s_min'],
            $row['s_max']
        );
    }

    /**
     * Skrajne współrzędne osiowe (`r`, `s = 2q + r`) dla podanego WHERE —
     * wspólne dla naiwnego prostokąta i dla wersji zawężonej do jednego
     * kubełka w `boundsFor()`, żeby zapytanie MIN/MAX istniało w kodzie raz.
     *
     * @param array<string,mixed> $params
     * @return array{r_min:int,r_max:int,s_min:int,s_max:int}|null
     */
    private static function axialExtremes(
        \PDO $db,
        string $from,
        string $where,
        array $params,
        string $rExpr,
        string $sExpr
    ): ?array {
        $stmt = $db->prepare("
            SELECT MIN($rExpr) AS r_min, MAX($rExpr) AS r_max,
                   MIN($sExpr) AS s_min, MAX($sExpr) AS s_max
              $from $where
        ");
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false || $row['r_min'] === null) {
            return null;
        }

        return [
            'r_min' => (int) $row['r_min'], 'r_max' => (int) $row['r_max'],
            's_min' => (int) $row['s_min'], 's_max' => (int) $row['s_max'],
        ];
    }

    /** Ostatnie przejazdy z liczbami — "co dał mi ten wyjazd" (§38). */
    /**
     * OSTATNIA AKTYWNOŚĆ — jedna oś czasu dla OBU map (2026-08-24).
     *
     * PRZEJAZDY **I ZNALEZIONE SKARBY**. Ta druga połowa wypadła przy pierwszej
     * wersji tej listy i user zgłosił to natychmiast („usunąłeś zdobycia skarbów
     * z aktywności?!") — poprzedni panel czytał cały rejestr naliczeń, więc
     * pokazywał jedno i drugie. Skarb bywa zdobyty BEZ przejazdu (zeskanowana
     * wlepka), więc nie jest dopiskiem do przejazdu, tylko własnym zdarzeniem.
     *
     * Zgłoszenie usera: sekcja „Co dały ostatnie wyjazdy" pod mapą powtarzała
     * to, co i tak stoi w panelu „Ostatnia aktywność", a sam panel pokazywał
     * wyłącznie punkty — bez daty, bez odkrytych pól i bez możliwości kliknięcia.
     * Zamiast dwóch niepełnych widoków tej samej rzeczy jest jeden pełny.
     *
     * `$userId === null` = mapa społeczności: te same przejazdy, tylko wszystkich
     * i z informacją KTO. To jest cała różnica między zakładkami, więc jedno
     * zapytanie z gałęzią, a nie dwa prawie identyczne.
     *
     * ŹRÓDŁO (`source_code`) WYCHODZI NA WIERZCH, bo „wspólny wyjazd" i „runda
     * solo" to dla czytającego dwie różne rzeczy — a do tej pory jedno i drugie
     * nazywało się tak samo („Przejazd").
     *
     * @return list<array<string,mixed>>
     */
    public static function recentActivity(?int $userId, int $limit = 24, ?int $viewerId = null): array
    {
        $mine = $userId !== null;
        $stmt = Database::connection()->prepare('
            SELECT a.id, a.edition_id, a.ride_date, a.started_at, a.distance_km, a.elevation_gain_m,
                   a.cells_new, a.cells_touched, a.source_code, a.gpx_url,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = a.id), 0) AS points_total,
                   e.slug AS event_slug, e.title AS event_title,
                   reg.name AS region_label,
                   u.id AS user_id, u.name AS user_name,
                   u.public_slug AS user_slug, u.avatar_url AS user_avatar
              FROM rider_activities a
              JOIN users u ON u.id = a.user_id
              LEFT JOIN event_editions ed ON ed.id = a.edition_id
              LEFT JOIN events e ON e.id = ed.event_id
              LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ", ") AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
             ' . ($mine ? 'WHERE a.user_id = :user_id' : 'WHERE u.blocked_at IS NULL') . '
             ORDER BY a.ride_date DESC, a.id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($mine ? ['user_id' => $userId] : []);

        $rides = self::withMapBounds($stmt->fetchAll());
        foreach ($rides as $i => $ride) {
            $rides[$i]['kind'] = 'ride';
        }

        // SCALENIE PO DACIE, nie doklejenie jednej listy do drugiej: „ostatnia
        // aktywność" ma być chronologią, a nie dwoma workami pod sobą.
        // Pobieramy WIĘCEJ znalezisk, niż zmieści się wierszy: grupowanie po dniach
        // dopiero je skróci, a limit liczony przed nim uciąłby całe dni.
        // $viewerId decyduje, czy skarby tej osoby wychodzą w pełni (właściciel
        // — /odkrycia i własny profil), czy z maską jak na mapie społeczności
        // (publiczny profil cudzy — patrz recentTreasureFinds).
        $wpisy = array_merge($rides, self::recentTreasureFinds($userId, $limit * 5, $viewerId));
        usort($wpisy, static fn(array $a, array $b): int
            => [$b['ride_date'], $b['kind']] <=> [$a['ride_date'], $a['kind']]);

        return array_slice($wpisy, 0, $limit);
    }

    /**
     * ZNALEZIONE SKARBY do tej samej osi czasu co przejazdy.
     *
     * BŁĄD, KTÓRY TO NAPRAWIA (zgłoszenie usera 2026-08-24: „usunąłeś zdobycia
     * skarbów z aktywności?!"). Panel „Ostatnia aktywność" czytał wcześniej CAŁY
     * rejestr naliczeń (`PointLedger::recentForUser`), więc obok przejazdów
     * pokazywał też znalezione skarby. Przebudowa na listę przejazdów wyrzuciła
     * je bez słowa. Skarb bywa zdobyty BEZ przejazdu (zeskanowana wlepka,
     * potwierdzona lokalizacja), więc nie da się go pokazać jako dopisku do
     * przejazdu — musi być własnym zdarzeniem na osi czasu.
     *
     * PUNKTY BIERZEMY Z REJESTRU, nie z `treasures.points`: rejestr jest
     * niezmienny, a wartość skarbu wolno później zmienić — wpis ma mówić, ile
     * NAPRAWDĘ wtedy zapłacono.
     *
     * UJAWNIENIE NA MAPIE SPOŁECZNOŚCI: nazwa i dokładna pozycja wychodzą
     * WYŁĄCZNIE dla skarbów jawnych (`reveal_level = 2`), dla samego
     * znalazcy ($viewerId = właściciel osi czasu) albo na jego własnej mapie.
     * Inaczej publiczna oś czasu byłaby najprostszym sposobem na obejście
     * całej zagadki — wystarczyłoby poczekać, aż ktoś znajdzie.
     *
     * @return list<array<string,mixed>>
     */
    private static function recentTreasureFinds(?int $userId, int $limit, ?int $viewerId = null): array
    {
        $mine = $userId !== null;
        $stmt = Database::connection()->prepare('
            SELECT f.treasure_id, f.claimed_at, f.method,
                   t.name, t.lat, t.lon, t.cell_id, t.reveal_level,
                   COALESCE((SELECT pt.points FROM point_transactions pt
                              WHERE pt.user_id = f.user_id
                                AND pt.source = :source
                                AND pt.source_id = CAST(f.treasure_id AS CHAR)
                              LIMIT 1), f.points_awarded) AS points_total,
                   u.id AS user_id, u.name AS user_name,
                   u.public_slug AS user_slug, u.avatar_url AS user_avatar
              FROM treasure_finds f
              JOIN treasures t ON t.id = f.treasure_id
              JOIN users u ON u.id = f.user_id
             ' . ($mine ? 'WHERE f.user_id = :user_id' : 'WHERE u.blocked_at IS NULL') . '
             ORDER BY f.claimed_at DESC
             LIMIT ' . max(1, $limit)
        );
        $params = ['source' => PointLedger::SOURCE_TREASURE_FOUND];
        if ($mine) {
            $params['user_id'] = $userId;
        }
        $stmt->execute($params);

        // GRUPUJEMY ZNALEZISKA Z JEDNEGO DNIA (poprawka tego samego dnia, po
        // zobaczeniu wyniku na żywo: siedem skarbów zdobytych podczas jednego
        // przejazdu dawało siedem wierszy i wypychało z listy same przejazdy).
        // Jedno znalezisko = wiersz z nazwą, kilka = jeden wiersz z liczbą,
        // sumą punktów i wspólnym prostokątem — kliknięcie pokazuje je razem
        // na mapie, a tam każdy ma już swoją pinezkę.
        $dni = [];
        foreach ($stmt->fetchAll() as $row) {
            // Znalazca widzi swój skarb w pełni — kto tam był, nie ma czego
            // ukrywać (ta sama zasada co w Models\Treasure::reveal). Na osi
            // czasu CUDZEGO rowerzysty (publiczny profil) maska działa jak na
            // mapie społeczności, mimo że $mine jest prawdą — stąd porównanie
            // z $viewerId, nie samo $mine.
            $jawny = ($mine && $viewerId !== null && $viewerId === (int) $row['user_id'])
                || (int) $row['reveal_level'] >= 2;
            [$lat, $lon] = $jawny
                ? [(float) $row['lat'], (float) $row['lon']]
                : DiscoveryGrid::cellCenter((int) $row['cell_id']);

            // Klucz grupy to (osoba, dzień) — na mapie społeczności dwa
            // znaleziska różnych osób tego samego dnia zostają osobno, bo to
            // dwie różne historie.
            $klucz = $row['user_id'] . '|' . substr((string) $row['claimed_at'], 0, 10);
            if (!isset($dni[$klucz])) {
                $dni[$klucz] = [
                    'kind'        => 'treasure',
                    'id'          => (int) $row['treasure_id'],
                    'ride_date'   => substr((string) $row['claimed_at'], 0, 10),
                    'title'       => $jawny ? (string) $row['name'] : __('Ukryty skarb'),
                    'count'       => 1,
                    'points_total' => (int) $row['points_total'],
                    'user_id'     => (int) $row['user_id'],
                    'user_name'   => (string) $row['user_name'],
                    // Pojedynczy punkt, więc „prostokąt" jest tu punktem — mapa
                    // i tak dostaje z niego `maxZoom`, a wspólny kształt danych
                    // pozwala widokowi obsłużyć oba rodzaje wierszy tak samo.
                    'bounds'      => ['south' => $lat, 'north' => $lat, 'west' => $lon, 'east' => $lon],
                ];
                continue;
            }

            $g = &$dni[$klucz];
            $g['count']++;
            $g['points_total'] += (int) $row['points_total'];
            $g['bounds']['south'] = min($g['bounds']['south'], $lat);
            $g['bounds']['north'] = max($g['bounds']['north'], $lat);
            $g['bounds']['west']  = min($g['bounds']['west'], $lon);
            $g['bounds']['east']  = max($g['bounds']['east'], $lon);
            unset($g);
        }

        return array_values($dni);
    }



    /**
     * Prostokąt na mapie dla KAŻDEGO przejazdu — żeby kliknięcie w wiersz
     * potrafiło pokazać, gdzie to było.
     *
     * JEDNO ZAPYTANIE NA CAŁĄ LISTĘ, nie jedno na przejazd: przy dwudziestu
     * czterech wierszach to różnica między jednym round-tripem a dwudziestoma
     * czterema. Pola dekodujemy w PHP (`DiscoveryGrid::decode` to arytmetyka
     * bitowa), bo w bazie leży spakowany identyfikator, po którym nie da się
     * liczyć min/max współrzędnych.
     *
     * @param list<array<string,mixed>> $rides
     * @return list<array<string,mixed>>
     */
    private static function withMapBounds(array $rides): array
    {
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rides);
        if (!$ids) {
            return [];
        }

        $stmt = Database::connection()->query(
            'SELECT activity_id, cell_id FROM rider_activity_cells
              WHERE activity_id IN (' . implode(',', $ids) . ')'
        );

        $zakresy = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$activityId, $cellId]) {
            [$lat, $lon] = DiscoveryGrid::cellCenter((int) $cellId);
            $id = (int) $activityId;
            if (!isset($zakresy[$id])) {
                $zakresy[$id] = ['south' => $lat, 'north' => $lat, 'west' => $lon, 'east' => $lon];
                continue;
            }
            $zakresy[$id]['south'] = min($zakresy[$id]['south'], $lat);
            $zakresy[$id]['north'] = max($zakresy[$id]['north'], $lat);
            $zakresy[$id]['west']  = min($zakresy[$id]['west'], $lon);
            $zakresy[$id]['east']  = max($zakresy[$id]['east'], $lon);
        }

        foreach ($rides as $i => $ride) {
            // null = przejazd bez zapisanych pól (nie powinno się zdarzyć, ale
            // wiersz ma się wtedy pokazać BEZ kliknięcia, a nie zniknąć).
            $rides[$i]['bounds'] = $zakresy[(int) $ride['id']] ?? null;
        }

        return $rides;
    }

    public static function recentRidesForUser(int $userId, int $limit = 10): array
    {
        // Punkty dosumowane z rejestru podzapytaniem — jedna liczba na
        // przejazd, bez wyciągania każdej transakcji z osobna.
        $stmt = Database::connection()->prepare('
            SELECT a.id, a.ride_date, a.distance_km, a.elevation_gain_m, a.cells_new,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = a.id), 0) AS points_total,
                   e.slug AS event_slug, e.title AS event_title,
                   reg.name AS region_label
              FROM rider_activities a
              LEFT JOIN event_editions ed ON ed.id = a.edition_id
              LEFT JOIN events e ON e.id = ed.event_id
              LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ", ") AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
             WHERE a.user_id = :user_id
             ORDER BY a.ride_date DESC, a.id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Podsumowanie JEDNEGO przejazdu — ekran „co dziś odkryłeś" po turnusie.
     * Do liczb o polach dokłada `points` (suma z rejestru) i `pointsBySource`
     * (rozbicie), więc widok może pokazać zarówno jedną liczbę, jak i za co.
     */
    public static function rideForRsvp(int $rsvpId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT id, cells_new, cells_touched, distance_km, elevation_gain_m
              FROM rider_activities WHERE rsvp_id = :rsvp_id
        ');
        $stmt->execute(['rsvp_id' => $rsvpId]);
        $row = $stmt->fetch();
        return $row ? self::withPoints($row) : null;
    }

    /**
     * To samo, ale po parze (użytkownik, turnus) — kronika zna te dwie rzeczy,
     * a nie zna identyfikatora zapisu.
     */
    public static function rideForUserOnEdition(int $userId, int $editionId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT id, cells_new, cells_touched, distance_km, elevation_gain_m
              FROM rider_activities
             WHERE user_id = :user_id AND edition_id = :edition_id
             LIMIT 1
        ');
        $stmt->execute(['user_id' => $userId, 'edition_id' => $editionId]);
        $row = $stmt->fetch();
        return $row ? self::withPoints($row) : null;
    }

    /**
     * Liczby przejazdów tej osoby dla WIELU turnusów naraz, w tablicy
     * `edition_id => ['cells_new' => int, 'points' => int]`.
     *
     * Istnieje po to, żeby kafel wyjazdu na profilu mógł powiedzieć to samo, co
     * kafel na /odkrycia („co dał mi ten wyjazd"), bez zapytania na kafel.
     * Turnusy bez przejazdu po prostu nie mają klucza — widok pokazuje wtedy
     * sam wyjazd, bez liczb.
     *
     * @param int[] $editionIds
     */
    public static function statsForEditions(int $userId, array $editionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $editionIds))));
        if (!$ids) {
            return [];
        }

        // Identyfikatory wstawiane wprost (są już intami), bo lista jest
        // zmiennej długości — ten sam wzorzec co w sharedCellCounts.
        $stmt = Database::connection()->prepare('
            SELECT a.edition_id, a.cells_new,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = a.id), 0) AS points
              FROM rider_activities a
             WHERE a.user_id = :user_id AND a.edition_id IN (' . implode(',', $ids) . ')
        ');
        $stmt->execute(['user_id' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['edition_id']] = [
                'cells_new' => (int) $row['cells_new'],
                'points'    => (int) $row['points'],
            ];
        }
        return $out;
    }

    /**
     * Dokłada do wiersza przejazdu punkty z rejestru: sumę i rozbicie na
     * źródła. Wydzielone, bo dwie metody wyżej różnią się wyłącznie tym, po
     * czym szukają przejazdu.
     */
    private static function withPoints(array $row): array
    {
        $activityId = (int) $row['id'];
        $row['points'] = PointLedger::sumForActivity($activityId);

        $bySource = [];
        foreach (PointLedger::forActivity($activityId) as $t) {
            $bySource[$t['source']] = ($bySource[$t['source']] ?? 0) + (int) $t['points'];
        }
        $row['pointsBySource'] = $bySource;

        return $row;
    }

    // ---------------------------------------------------------------
    // Ridemore Discovery — wspólna mapa (§6.2)
    // ---------------------------------------------------------------

    /**
     * Pola widoczne w prostokącie mapy, zagregowane do poziomu $res.
     *
     * Agregacja odbywa się DWUETAPOWO, bo prawdziwego rodzica heksagonu nie da
     * się policzyć w SQL-u (wymaga projekcji): najpierw baza grupuje po
     * dzieleniu całkowitym współrzędnych osiowych — to nie są heksagony, tylko
     * romby o zbliżonym rozmiarze, ale redukuje liczbę wierszy o tyle samo —
     * potem PHP zamienia reprezentanta każdej grupy na PRAWDZIWE pole
     * grubszego poziomu i scala grupy, które wskazały to samo. Artefakt:
     * pojedyncze pole przy granicy rombu może trafić do sąsiedniego
     * heksagonu. Przy oddaleniu, na którym w ogóle włącza się agregacja, jest
     * to niewidoczne, a poziom RES_CELL (pełne oddalenie wyłączone) jest
     * dokładny co do jednego pola.
     *
     * Zwraca DWIE niezależne intensywności, po jednej na warstwę mapy:
     *  - `riders` — ILU LUDZI tu było (warstwa mgły; dla jednej osoby: gęstość
     *    pokrycia, czyli ile jej pól mieści się w zagregowanym heksagonie),
     *  - `passes` — ILE RAZY ktokolwiek tędy przejechał, z powtórzeniami
     *    (warstwa heatmapy; dla jednej osoby: ile razy przejechała TĘDY ONA).
     *
     * Rozdzielenie jest sednem obu warstw: lokalna pętla przejechana 50 razy
     * przez 5 osób ma być gorąca, choć odkrywców ma tylu co odległy szlak
     * przejechany przez 5 osób po razie.
     *
     * @return array<int,array{cellId:int, riders:int, passes:int, cells:int}>
     */
    public static function communityCells(array $bounds, int $res, ?int $onlyUserId = null): array
    {
        $range = DiscoveryGrid::axialRangeForBounds(
            $bounds['south'],
            $bounds['west'],
            $bounds['north'],
            $bounds['east'],
            DiscoveryGrid::RES_CELL
        );

        $db = Database::connection();

        // KLUCZ AGREGACJI — realny heksagon nadrzędny z kolumny (migr. 047),
        // a nie prostokąt `FLOOR(cell_q / krok)` jak do 2026-08-13.
        //
        // Tamto grupowanie było przybliżeniem, które się nie zgadzało: heksagony
        // poziomu wyższego nie układają się w prostokąty siatki poziomu niższego,
        // więc jedna grupa prostokątna zawierała pola należące do kilku różnych
        // rodziców (zmierzone: 199 z 274 grup przy res=3). Kod brał MIN(cell_id)
        // jako reprezentanta i przypisywał całą grupę JEDNEMU z nich — pozostałe
        // heksagony nie dostawały nic i znikały z mapy przy zmianie skali.
        //
        // Przy pełnym przybliżeniu pole jest swoim własnym rodzicem i grupowanie
        // w ogóle nie zachodzi.
        $isFinest = $res === DiscoveryGrid::RES_CELL;
        $parentCol = 't.parent_res' . $res;
        $groupKey = $isFinest ? 't.cell_id' : $parentCol;

        // Mapa osobista i wspólna to ten sam kod z inną tabelą źródłową —
        // gdyby się rozjechały, jedna z nich prędzej czy później pokazałaby
        // pola w innym miejscu niż druga.
        if ($onlyUserId !== null) {
            // Mapa JEDNEJ osoby nie ma „ilu ludzi tędy jechało" — wszędzie
            // byłby to ten sam jeden człowiek. Intensywnością jest tu GĘSTOŚĆ
            // POKRYCIA: ile pól tej osoby mieści się w zagregowanym
            // heksagonie. Przy pełnym przybliżeniu wychodzi wszędzie 1 (mapa
            // pokrycia, jednolity ton), przy oddaleniu pokazuje, gdzie ta
            // osoba jeździ gęsto, a gdzie tylko przejechała. Dlatego `riders`
            // i `cells` są tu tą samą liczbą — inaczej scalanie kubełków
            // agregacji wyprodukowałoby „intensywność", która nie znaczy nic.
            // `passes` liczone Z WŁASNYCH przejazdów tej osoby, nie z licznika
            // globalnego — na mapie jednego rowerzysty „gorąco" ma znaczyć
            // „TU jeżdżę najczęściej", a nie „tędy jeździ pół serwisu".
            // Podwójny LEFT JOIN, a nie warunek w WHERE: filtr po user_id musi
            // siedzieć w ON, inaczej pola bez pasujących przejazdów wypadłyby
            // z wyniku zamiast dostać zero.
            $sql = '
                SELECT ' . $groupKey . ' AS rep,
                       COUNT(DISTINCT dc.cell_id) AS cells,
                       COUNT(DISTINCT dc.cell_id) AS riders,
                       COUNT(ra.id) AS passes
                  FROM discovery_cells dc
                  JOIN discovery_cell_totals t ON t.cell_id = dc.cell_id
                  LEFT JOIN rider_activity_cells rac ON rac.cell_id = dc.cell_id
                  LEFT JOIN rider_activities ra ON ra.id = rac.activity_id AND ra.user_id = :uid_pass
                 WHERE dc.user_id = :uid_own
                   AND t.cell_r BETWEEN :r_min AND :r_max
                   AND t.cell_q BETWEEN :q_min AND :q_max
                   ' . ($isFinest ? '' : 'AND ' . $parentCol . ' IS NOT NULL') . '
                 GROUP BY ' . $groupKey . '
                 LIMIT ' . self::MAX_CELLS_PER_VIEW;
            // Ten sam identyfikator pod dwiema nazwami — przy
            // EMULATE_PREPARES=false nie wolno powtórzyć nazwanego placeholdera.
            $params = ['uid_own' => $onlyUserId, 'uid_pass' => $onlyUserId];
        } else {
            $sql = '
                SELECT ' . $groupKey . ' AS rep, COUNT(*) AS cells,
                       SUM(t.riders_count) AS riders,
                       SUM(t.passes_count) AS passes
                  FROM discovery_cell_totals t
                 WHERE t.cell_r BETWEEN :r_min AND :r_max
                   AND t.cell_q BETWEEN :q_min AND :q_max
                   ' . ($isFinest ? '' : 'AND ' . $parentCol . ' IS NOT NULL') . '
                 GROUP BY ' . $groupKey . '
                 LIMIT ' . self::MAX_CELLS_PER_VIEW;
            $params = [];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params + [
            'r_min'  => $range['rMin'],
            'r_max'  => $range['rMax'],
            'q_min'  => $range['qMin'],
            'q_max'  => $range['qMax'],
        ]);

        // Klucz grupy JEST identyfikatorem rysowanego pola — nie ma już etapu
        // „zgadnij rodzica reprezentanta", w którym gubiły się heksagony.
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $cellId = (int) $row['rep'];
            $out[] = [
                'cellId' => $cellId,
                'riders' => (int) $row['riders'],
                'passes' => (int) $row['passes'],
                'cells'  => (int) $row['cells'],
            ];
        }
        return $out;
    }

    /** Liczby pod nagłówek wspólnej mapy. */
    public static function communityStats(int $recentDays = 7): array
    {
        $db = Database::connection();

        $cells = (int) $db->query('SELECT COUNT(*) FROM discovery_cell_totals')->fetchColumn();
        $riders = (int) $db->query('SELECT COUNT(DISTINCT user_id) FROM discovery_cells')->fetchColumn();

        $stmt = $db->prepare('
            SELECT COUNT(*) FROM discovery_cell_totals
             WHERE first_seen_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ');
        $stmt->execute(['days' => $recentDays]);

        return [
            'cells'      => $cells,
            'riders'     => $riders,
            'cellsRecent'=> (int) $stmt->fetchColumn(),
            'recentDays' => $recentDays,
        ];
    }

    /**
     * Pola DOTKNIĘTE przez przejazd na tym turnusie, z podziałem na te odkryte
     * TYM przejazdem i te, które ktoś już wcześniej miał — pod warstwę hex na
     * mapie kroniki.
     *
     * Rozróżnienie jest sednem tej mapy: przez teren już odkryty jeździ się
     * stale (dojazd z domu, ulubiona pętla), więc „207 pól na trasie" i „207
     * nowych" to dwie różne liczby, których bez kolorów nie da się przypisać
     * do konkretnych fragmentów trasy.
     *
     * $userId = null → widok zbiorowy (gość, ktoś spoza składu): pole jest
     * „nowe", jeśli KTOKOLWIEK z tego turnusu odkrył je właśnie wtedy.
     *
     * @return array{cells: list<array{a:float,o:float,n:int}>, sizeM: float}
     */
    public static function rideCellsForEdition(int $editionId, ?int $userId): array
    {
        // is_new: odkrycie tego pola przez tę osobę pochodzi Z TEGO przejazdu
        // (discovery_cells.activity_id wskazuje na niego). LEFT JOIN, bo pole
        // mogło zostać odkryte wcześniej innym wyjazdem — wtedy dc jest NULL
        // albo wskazuje inny przejazd i w obu razach wychodzi 0.
        $sql = '
            SELECT rac.cell_id,
                   MAX(CASE WHEN dc.activity_id = a.id THEN 1 ELSE 0 END) AS is_new
              FROM rider_activities a
              JOIN rider_activity_cells rac ON rac.activity_id = a.id
              LEFT JOIN discovery_cells dc
                     ON dc.cell_id = rac.cell_id AND dc.user_id = a.user_id
             WHERE a.edition_id = :edition_id
        ';
        $params = ['edition_id' => $editionId];
        if ($userId !== null) {
            $sql .= ' AND a.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' GROUP BY rac.cell_id LIMIT ' . self::MAX_RIDE_CELLS;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        $cells = [];
        foreach ($stmt->fetchAll() as $row) {
            [$lat, $lon] = DiscoveryGrid::cellCenter((int) $row['cell_id']);
            // Klucze jednoliterowe i 5 miejsc po przecinku — ta lista jedzie
            // w HTML-u strony, nie osobnym żądaniem.
            $cells[] = ['a' => round($lat, 5), 'o' => round($lon, 5), 'n' => (int) $row['is_new']];
        }

        return ['cells' => $cells, 'sizeM' => DiscoveryGrid::sizeM(DiscoveryGrid::RES_CELL)];
    }

    /**
     * Nowe pola odkryte przez skład danego turnusu — linijka do Kroniki (§20).
     * Liczy POLA, nie ludzi: odkrywanie jako fakt zbiorowy, dokładnie tak jak
     * istniejące „dla N osób to był pierwszy raz w regionie".
     */
    public static function newCellsForEdition(int $editionId): int
    {
        return self::newCellsForEditions([$editionId])[$editionId] ?? 0;
    }

    /**
     * To samo dla wielu turnusów naraz — Puls buduje listę kilkudziesięciu
     * wpisów i nie może odpytywać bazy raz na wpis.
     *
     * @param int[] $editionIds
     * @return array<int,int>
     */
    public static function newCellsForEditions(array $editionIds): array
    {
        $editionIds = array_values(array_filter(array_map('intval', $editionIds)));
        if (empty($editionIds)) {
            return [];
        }
        $stmt = Database::connection()->query('
            SELECT a.edition_id, COUNT(DISTINCT dc.cell_id) AS cells
              FROM rider_activities a
              JOIN discovery_cells dc ON dc.activity_id = a.id
             WHERE a.edition_id IN (' . implode(',', $editionIds) . ')
             GROUP BY a.edition_id
        ');

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['edition_id']] = (int) $row['cells'];
        }
        return $out;
    }

    /**
     * Ile pól dwie osoby odkryły OBIE — wzbogacenie Peletonu (§19).
     * Zwraca mapę drugiego użytkownika => liczba wspólnych pól, policzoną
     * jednym zapytaniem dla całego peletonu naraz.
     *
     * @param int[] $otherUserIds
     * @return array<int,int>
     */
    public static function sharedCellCounts(int $userId, array $otherUserIds): array
    {
        $otherUserIds = array_values(array_unique(array_map('intval', $otherUserIds)));
        if (empty($otherUserIds)) {
            return [];
        }
        $list = implode(',', $otherUserIds);

        $stmt = Database::connection()->prepare('
            SELECT other.user_id, COUNT(*) AS shared
              FROM discovery_cells mine
              JOIN discovery_cells other ON other.cell_id = mine.cell_id
             WHERE mine.user_id = :user_id
               AND other.user_id IN (' . $list . ')
             GROUP BY other.user_id
        ');
        $stmt->execute(['user_id' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['user_id']] = (int) $row['shared'];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Utrzymanie agregatu
    // ---------------------------------------------------------------

    /**
     * Przelicza wiersze agregatu dla WSKAZANYCH pól — po wycofaniu obecności.
     * Pole, którego nikt już nie ma, znika z tabeli zamiast zostawać z zerem
     * (zero rowerzystów to nie jest odkryte pole).
     *
     * @param int[] $cellIds
     */
    public static function refreshTotalsFor(array $cellIds): void
    {
        if (empty($cellIds)) {
            return;
        }
        $db = Database::connection();
        foreach (array_chunk($cellIds, 500) as $chunk) {
            $list = implode(',', array_map('intval', $chunk));
            $db->exec('
                UPDATE discovery_cell_totals t
                   SET t.riders_count = (
                        SELECT COUNT(*) FROM discovery_cells dc WHERE dc.cell_id = t.cell_id
                   ),
                       t.passes_count = (
                        SELECT COUNT(*) FROM rider_activity_cells rac WHERE rac.cell_id = t.cell_id
                   )
                 WHERE t.cell_id IN (' . $list . ')
            ');
            $db->exec('DELETE FROM discovery_cell_totals WHERE riders_count = 0 AND cell_id IN (' . $list . ')');
        }
    }

    /**
     * Odtworzenie CAŁEGO agregatu z discovery_cells — dowód, że
     * discovery_cell_totals nie jest źródłem prawdy, tylko jego skrótem
     * (ten sam wzorzec i ta sama gwarancja co RiderConnection::rebuildAll).
     */
    public static function rebuildTotals(): int
    {
        $db = Database::connection();
        $db->exec('DELETE FROM discovery_cell_totals');

        // Agregat liczony w całości w bazie; cell_q/cell_r odtwarzane w PHP,
        // bo rozpakowanie identyfikatora wymaga rozszerzenia znaku, którego
        // nie da się czytelnie zapisać w SQL-u.
        //
        // Rozstrzygnięcie remisu przez activity_id, a NIE przez user_id, jest
        // tu istotne: discovered_at ma dokładność sekundy, więc przy backfillu
        // wszystkie odkrycia mają ten sam znacznik czasu i "kto był pierwszy"
        // rozstrzygałby przypadkowy numer konta. activity_id rośnie w
        // kolejności faktycznego przetwarzania, czyli dokładnie tak, jak
        // przyznawał bonus tryb przyrostowy — i tylko dzięki temu odtworzenie
        // daje wynik IDENTYCZNY z tym, co zbudowało się na bieżąco (jest to
        // sprawdzane, patrz test spójności).
        //
        // GROUP_CONCAT bywa ucinany przy group_concat_max_len, ale bierzemy
        // wyłącznie PIERWSZY element, a ten nigdy nie pada ofiarą obcięcia.
        // passes_count odtwarzany z rider_activity_cells (migr. 041) — to jest
        // powód, dla którego tamta tabela w ogóle istnieje: bez niej licznik
        // przejazdów byłby jedyną kopią tej informacji i ta metoda musiałaby
        // go wyzerować, cicho psując warstwę heatmapy.
        $rows = $db->query('
            SELECT dc.cell_id,
                   COUNT(*) AS riders,
                   MIN(dc.discovered_at) AS first_seen,
                   MAX(dc.discovered_at) AS last_seen,
                   SUBSTRING_INDEX(
                       GROUP_CONCAT(dc.user_id ORDER BY dc.discovered_at ASC, dc.activity_id ASC), ",", 1
                   ) AS first_user,
                   (SELECT COUNT(*) FROM rider_activity_cells rac WHERE rac.cell_id = dc.cell_id) AS passes
              FROM discovery_cells dc
             GROUP BY dc.cell_id
        ')->fetchAll();

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $cellId = (int) $row['cell_id'];
                [, $q, $r] = DiscoveryGrid::decode($cellId);
                // Rodzice na każdym poziomie agregacji (migr. 047) — tą samą
                // metodą co tor przyrostowy w RiderActivity::bumpTotals, bo
                // odtworzenie musi dać wynik IDENTYCZNY z budowaniem na bieżąco.
                $p = DiscoveryGrid::parentsFor($cellId);
                $values[] = sprintf(
                    "(%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%s,%s)",
                    $cellId,
                    $q,
                    $r,
                    $p[3],
                    $p[2],
                    $p[1],
                    $p[0],
                    (int) $row['riders'],
                    (int) $row['passes'],
                    (int) $row['first_user'],
                    $db->quote($row['first_seen']),
                    $db->quote($row['last_seen'])
                );
            }
            $db->exec('
                INSERT INTO discovery_cell_totals
                    (cell_id, cell_q, cell_r, parent_res3, parent_res2, parent_res1, parent_res0,
                     riders_count, passes_count, first_user_id, first_seen_at, last_seen_at)
                VALUES ' . implode(',', $values)
            );
            $inserted += count($chunk);
        }
        return $inserted;
    }
}
