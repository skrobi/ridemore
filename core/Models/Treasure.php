<?php
// core/Models/Treasure.php
// SKARBY — punkt w terenie do znalezienia (migr. 054, 055, 056).
//
// RELACJA DO DISCOVERY: to jest DRUGA, przeciwstawna czynność. Pole odkryć ma
// ok. 500 m i zalicza się samo ze śladu GPS — nagradza PRZEJECHANIE terenu.
// Skarb wymaga zatrzymania się, zejścia z roweru i rozejrzenia; ma
// współrzędne z dokładnością do metrów. Stąd osobna tabela i osobne źródło
// punktów, mimo że jedno i drugie kończy się liczbą przy nazwisku.
//
// Hex służy WYŁĄCZNIE do rysowania ikonki na mapie (kolumna `cell_id`, liczona
// przy zapisie). Sama lokalizacja nigdy nie jest do pola zaokrąglana — inaczej
// „punkt widokowy" znaczyłby „gdzieś w tym pół kilometra".
//
// PUNKTY IDĄ PRZEZ Models\PointLedger, jak wszystko inne. To jest jedyne
// wejście do punktów w tym serwisie i wlepki nie robią wyjątku: dzięki temu
// zaliczenie widać w „Ostatniej aktywności", w historii profilu i w panelu
// admina bez dopisywania czegokolwiek w tych trzech miejscach.
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;

class Treasure
{
    /**
     * Sufit wierszy branych do grupowania w pęczki.
     *
     * 2000 nie jest liczbą magiczną: przy widoku całej Polski to i tak
     * kilkadziesiąt pęczków, a odczyt tylu wierszy z indeksu po lat/lon jest
     * tańszy niż jedno zapytanie o geometrię trasy. Powyżej tego progu
     * grupowanie trzeba przenieść do SQL — patrz clustersInBounds.
     */
    private const MAX_CLUSTER_SCAN = 2000;

    /** Kod z adresu QR — długi na tyle, żeby nie dało się go zgadnąć. */
    private const CODE_BYTES = 8;   // 16 znaków hex

    /**
     * Zaliczenie skarbu.
     *
     * @return array{ok:bool, reason:?string, points:int, treasure:?array}
     */
    public static function claim(string $code, int $userId, ?float $lat, ?float $lon, string $method = 'QR'): array
    {
        $treasure = self::findByCode($code);
        if ($treasure === null) {
            // Ten sam komunikat dla „nie ma takiego kodu" i „skarb wycofany":
            // rozróżnienie zdradzałoby, które kody istnieją, a to jedyna
            // informacja, jaką da się z tego endpointu wyciągnąć hurtem.
            return ['ok' => false, 'reason' => 'nieznana', 'points' => 0, 'treasure' => null];
        }
        if (!self::isActiveNow($treasure)) {
            return ['ok' => false, 'reason' => 'nieaktywna', 'points' => 0, 'treasure' => $treasure];
        }

        // POŁOŻENIE SKANUJĄCEGO. Kod da się sfotografować i wysłać znajomemu —
        // to jest znane ograniczenie każdej gry opartej o QR i nie udajemy, że
        // go nie ma. Sprawdzenie odległości podnosi poprzeczkę: żeby oszukać,
        // trzeba już świadomie podrobić GPS, a nagroda jest niewielka.
        //
        // Brak zgody na lokalizację NIE blokuje zaliczenia — blokowałby też
        // wszystkich, którzy mają wyłączony GPS w przeglądarce, a to większa
        // szkoda niż pożytek. Odległość zapisujemy zawsze, gdy jest znana:
        // to dane do późniejszej oceny, nie do natychmiastowej kary.
        $distance = null;
        if ($lat !== null && $lon !== null) {
            $distance = (int) round(self::distanceM(
                (float) $treasure['lat'], (float) $treasure['lon'], $lat, $lon
            ));
            if ($distance > (int) $treasure['claim_radius_m']) {
                return ['ok' => false, 'reason' => 'za_daleko', 'points' => 0, 'treasure' => $treasure];
            }
        }

        return self::award($treasure, $userId, $lat, $lon, $distance, $method);
    }

    /**
     * Zapis znalezienia + punkty. Wspolne dla wszystkich trzech drog (SKA/6).
     *
     * PUNKTY SA IDENTYCZNE NIEZALEZNIE OD METODY i to jest swiadoma decyzja.
     * Kusi, zeby skan wlepki placic lepiej, bo „lepiej dowodzi obecnosci" — ale
     * to karaloby ludzi za rzeczy, na ktore nie maja wplywu: zerwana wlepke,
     * telefon bez zasiegu pod lasem, zimowa rekawiczke na ekranie. Metode
     * ZAPISUJEMY (kolumna `method`), wiec gdyby ktora sciezka okazala sie
     * nadużywana, widac to bedzie w danych i wtedy bedzie o czym rozmawiac.
     */
    private static function award(array $treasure, int $userId, ?float $lat, ?float $lon, ?int $distance, string $method): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO treasure_finds
                (treasure_id, user_id, method, claimed_lat, claimed_lon, distance_m, points_awarded)
             VALUES (:s, :u, :m, :la, :lo, :d, :p)'
        );
        $stmt->execute([
            's'  => $treasure['id'],
            'u'  => $userId,
            'm'  => in_array($method, ['QR', 'GPS', 'GPX'], true) ? $method : 'QR',
            'la' => $lat,
            'lo' => $lon,
            'd'  => $distance,
            'p'  => (int) $treasure['points'],
        ]);

        // INSERT IGNORE + rowCount to CAŁE zabezpieczenie przed podwójnym
        // naliczeniem — klucz UNIQUE(treasure_id, user_id) rozstrzyga wyścig
        // w bazie, a nie w kodzie. Sprawdzenie „czy już mam?" przed insertem
        // byłoby wyścigiem, nie zabezpieczeniem (ta sama zasada co w Discovery).
        // Dotyczy to TAKŻE wyścigu między metodami: skan wlepki i wgrany tego
        // samego wieczoru ślad GPX z tej samej trasy trafiają w ten sam klucz.
        if ($stmt->rowCount() === 0) {
            // ROZRÓŻNIAMY „mam już to" od „zapis się nie udał". INSERT IGNORE
            // wycisza wszystko, także naruszenie klucza obcego — bez tego
            // sprawdzenia każdy taki błąd wracał do użytkownika jako „już
            // zaliczona", czyli komunikat, który go okłamuje i po którym nikt
            // nie zgłosi problemu. Jedno dodatkowe zapytanie tylko na ścieżce
            // błędu; przy poprawnym zaliczeniu nie wykonuje się nigdy.
            $exists = Database::connection()->prepare(
                'SELECT 1 FROM treasure_finds WHERE treasure_id = :t AND user_id = :u'
            );
            $exists->execute(['t' => $treasure['id'], 'u' => $userId]);

            return [
                'ok'       => false,
                'reason'   => $exists->fetchColumn() ? 'juz_zaliczona' : 'blad_zapisu',
                'points'   => 0,
                'treasure' => $treasure,
            ];
        }

        PointLedger::award(
            $userId,
            PointLedger::SOURCE_TREASURE_FOUND,
            (string) $treasure['id'],
            (int) $treasure['points'],
            null,
            date('Y-m-d'),
            'Skarb: ' . $treasure['name']
        );

        return ['ok' => true, 'reason' => null, 'points' => (int) $treasure['points'], 'treasure' => $treasure];
    }

    /**
     * Zaliczenie Z LOKALIZACJI, bez wlepki (SKA/6).
     *
     * Zwraca WSZYSTKIE skarby zaliczone tym jednym potwierdzeniem — na
     * przelęczy potrafią stać dwa obok siebie i proszenie o dwa osobne
     * kliknięcia byłoby udawaniem, że system czegoś nie wie.
     *
     * Kazdy skarb ma WLASNY promien, wiec warunek odleglosci liczymy per
     * wiersz, a nie jednym globalnym progiem: punkt w gestym lesie moze miec
     * 250 m, a taki przy szosie 80 m, zeby nie dalo sie go zaliczyc z auta.
     *
     * @return array{claimed:list<array>, already:int}
     */
    public static function claimNearby(int $userId, float $lat, float $lon): array
    {
        $claimed = [];
        $already = 0;

        foreach (self::nearby($lat, $lon) as $treasure) {
            $distance = (int) round(self::distanceM(
                (float) $treasure['lat'], (float) $treasure['lon'], $lat, $lon
            ));
            $result = self::award($treasure, $userId, $lat, $lon, $distance, 'GPS');
            if ($result['ok']) {
                $claimed[] = ['treasure' => $treasure, 'points' => $result['points']];
            } else {
                $already++;
            }
        }

        return ['claimed' => $claimed, 'already' => $already];
    }

    /**
     * Skarby w zasięgu podanego punktu — wspólne dla GPS i dla podglądu.
     *
     * Wstępne okno prostokątne (±0,01 stopnia to ~1,1 km) zawęża zbiór na
     * indeksie, dopiero potem liczymy prawdziwą odległość. Bez tego każde
     * kliknięcie „jestem tutaj" liczyłoby haversine dla całej bazy.
     */
    private static function nearby(float $lat, float $lon): array
    {
        $stmt = Database::connection()->prepare('
            SELECT t.*, c.name AS category_label
              FROM treasures t
              LEFT JOIN dictionary_items c ON c.id = t.category_item_id
             WHERE t.is_active = 1 AND t.status = "ACTIVE"
               AND t.lat BETWEEN :la1 AND :la2
               AND t.lon BETWEEN :lo1 AND :lo2
        ');
        $stmt->execute([
            'la1' => $lat - 0.01, 'la2' => $lat + 0.01,
            'lo1' => $lon - 0.02, 'lo2' => $lon + 0.02,
        ]);

        $out = [];
        foreach ($stmt->fetchAll() as $t) {
            if (!self::isActiveNow($t)) {
                continue;
            }
            $d = self::distanceM($lat, $lon, (float) $t['lat'], (float) $t['lon']);
            if ($d <= (int) $t['claim_radius_m']) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Zaliczenie ZE SLADU GPX (SKA/6) — wolane po wgraniu przejazdu.
     *
     * KANDYDATOW ZAWEZAMY PO POLACH SIATKI, a dopiero potem liczymy odleglosc
     * do punktow sladu. Pole ma ~500 m, promien zaliczenia zwykle 150 m, wiec
     * samo pole to za malo, zeby zaliczyc — ale w zupelnosci wystarczy, zeby
     * odsiac cala reszte bazy jednym zapytaniem po indeksie. Przy typowym
     * przejezdzie kandydatow jest zero i tablica punktow nie jest w ogole
     * przegladana.
     *
     * @param int[] $cellIds pola, przez ktore przeszedl slad
     * @param list<array{0:float,1:float}|array{lat:float,lon:float}> $points
     * @return list<array> zaliczone skarby (puste, gdy nic po drodze)
     */
    public static function claimAlongTrack(int $userId, array $cellIds, array $points): array
    {
        if (!$cellIds || !$points) {
            return [];
        }

        $in = implode(',', array_map('intval', $cellIds));
        $candidates = Database::connection()->query('
            SELECT * FROM treasures
             WHERE is_active = 1 AND status = "ACTIVE" AND cell_id IN (' . $in . ')
        ')->fetchAll();

        $claimed = [];
        foreach ($candidates as $treasure) {
            if (!self::isActiveNow($treasure)) {
                continue;
            }
            $best = null;
            foreach ($points as $p) {
                $pLat = (float) ($p['lat'] ?? $p[0] ?? 0);
                $pLon = (float) ($p['lon'] ?? $p[1] ?? 0);
                $d = self::distanceM((float) $treasure['lat'], (float) $treasure['lon'], $pLat, $pLon);
                if ($best === null || $d < $best) {
                    $best = $d;
                }
                // Wystarczy JEDEN punkt w promieniu — dalsze liczenie niczego
                // by nie zmienilo, a slad potrafi miec dziesiatki tysiecy
                // punktow.
                if ($best <= (int) $treasure['claim_radius_m']) {
                    break;
                }
            }
            if ($best !== null && $best <= (int) $treasure['claim_radius_m']) {
                $result = self::award($treasure, $userId, null, null, (int) round($best), 'GPX');
                if ($result['ok']) {
                    $claimed[] = ['treasure' => $treasure, 'points' => $result['points']];
                }
            }
        }

        return $claimed;
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT s.*, c.name AS category_label, r.name AS region_label
              FROM treasures s
              LEFT JOIN dictionary_items c ON c.id = s.category_item_id
              LEFT JOIN dictionary_items r ON r.id = s.region_item_id
             WHERE s.code = :code AND s.is_active = 1
        ');
        $stmt->execute(['code' => $code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Skarby w widocznym prostokącie — pod warstwę ikonek na mapie.
     *
     * Zwraca też, czy PYTAJĄCY ma już dany skarb: mapa ma pokazywać znalezione
     * inaczej niż nieznalezione, bo bez tego gra nie ma widocznego postępu.
     * NIE zdradza natomiast, kto jeszcze ją znalazł — to byłoby wskazanie,
     * kto gdzie bywa.
     */
    /**
     * @param ?int $viewerId KTO PATRZY — decyduje o poziomie ujawnienia.
     * @param ?int $foundBy  CZYJE znalezienia pokazujemy (mapa profilu rowerzysty).
     *
     * TE DWA PARAMETRY SĄ NIEZALEŻNE i mylenie ich byłoby wyciekiem. `foundBy`
     * zawęża ZBIÓR („skarby, które znalazł Marek"), `viewerId` decyduje, ile
     * z każdego widać („czy JA go znalazłem"). Oglądając cudzy profil dostaję
     * więc listę jego znalezisk, ale ukryte punkty z tej listy nadal widzę jako
     * „Coś tu jest" ze środkiem pola — bo to ja tam nie byłem, nie on.
     */
    /**
     * Warunek „tylko moje" / „tylko nieznalezione" do zapytań o skarby.
     *
     * ROZRÓŻNIENIE ROBI SERWER, NIE PRZEGLĄDARKA (2026-08-20, zgłoszenie usera
     * o osobnych warstwach). Kusi, żeby odfiltrować to w JS-ie na gotowej
     * odpowiedzi — ale przy oddaleniu serwer oddaje PĘCZKI, czyli same liczby,
     * i z pęczka „2 skarby, masz 1" nie da się już zrobić pojedynczej pinezki
     * po zgaszeniu jednej z warstw. Filtr przed grupowaniem daje poprawny
     * wynik w każdym stanie przełączników — łącznie z regułą „pęczek zaczyna
     * się od dwóch".
     *
     * Dla gościa (`$viewerId === null`) filtr WZGLĘDEM WIDZA nie ma sensu
     * i jest pomijany: nikt niezalogowany nie ma ani jednego znaleziska.
     * Filtr WZGLĘDEM SPOŁECZNOŚCI (`$scope === 'community'`) działa też dla
     * gościa — pyta o istnienie JAKIEGOKOLWIEK znalazcy, nie o konkretną
     * osobę, więc konto pytającego jest tu bez znaczenia.
     *
     * `$scope` (Etap 3, `tasks/done/warstwy-mapy.md`, 2026-08-26): „Skarby
     * odkryte/nieodkryte" na mapie SPOŁECZNOŚCI mają znaczyć „przez
     * KOGOKOLWIEK", nie „przez pytającego" — to samo pytanie co §27 zadaje
     * dziś publiczny licznik `finders` w `inBounds()` (liczba, nigdy tożsamość),
     * więc żadna nowa informacja tu nie wycieka. `'viewer'` (domyślny) to
     * dotychczasowe zachowanie — mapa osobista i profil pytają o SIEBIE.
     */
    private static function mineCondition(?int $viewerId, ?string $mine, string $scope = 'viewer'): string
    {
        if ($mine !== 'only' && $mine !== 'not') {
            return '';
        }
        if ($scope === 'community') {
            return ($mine === 'only' ? 'AND EXISTS' : 'AND NOT EXISTS')
                . ' (SELECT 1 FROM treasure_finds tfc WHERE tfc.treasure_id = s.id)';
        }
        if (!$viewerId) {
            return '';
        }
        // WŁASNY PLACEHOLDER, nie `:uid` z JOIN-a wyżej: EMULATE_PREPARES jest
        // wyłączone, więc tej samej nazwy nie da się użyć w zapytaniu dwa razy
        // (ta sama pułapka co przy trzech `:q` w KnownRoute::search).
        return ($mine === 'only' ? 'AND EXISTS' : 'AND NOT EXISTS')
            . ' (SELECT 1 FROM treasure_finds tfm
                    WHERE tfm.treasure_id = s.id AND tfm.user_id = :uid_mine)';
    }

    public static function inBounds(
        array $bounds,
        ?int $viewerId,
        int $limit = 300,
        ?int $foundBy = null,
        ?string $mine = null,
        string $mineScope = 'viewer'
    ): array {
        $db = Database::connection();
        $mineSql = self::mineCondition($viewerId, $mine, $mineScope);
        $sql = '
            SELECT s.id, s.name, s.lat, s.lon, s.points, s.photo_url, s.cell_id,
                   s.reveal_level, s.hint, s.status,
                   -- OPIS, RZADKOSC, PROMIEN I LICZBA ZNALAZCOW (2026-08-20) —
                   -- pod rozbudowany dymek na mapie. Wszystkie cztery to rzeczy,
                   -- ktore skarb JUZ MA; do tej pory dymek pokazywal z nich samo
                   -- „+punkty". `reveal()` nizej zdejmuje je punktom ukrytym,
                   -- razem z nazwa i zdjeciem — opis „stary mlyn nad rzeka" jest
                   -- takim samym tropem jak nazwa.
                   s.description, s.rarity, s.claim_radius_m,
                   (SELECT COUNT(*) FROM treasure_confirmations tc WHERE tc.treasure_id = s.id) AS confirmations,
                   (SELECT COUNT(*) FROM treasure_finds fc WHERE fc.treasure_id = s.id) AS finders,
                   c.code AS category_code, c.name AS category_label, c.icon AS category_icon,
                   reg.name AS region_label,
                   ' . ($viewerId ? '(cl.id IS NOT NULL)' : '0') . ' AS mine
              FROM treasures s
              LEFT JOIN dictionary_items c ON c.id = s.category_item_id
              LEFT JOIN dictionary_items reg ON reg.id = s.region_item_id
              ' . ($viewerId ? 'LEFT JOIN treasure_finds cl ON cl.treasure_id = s.id AND cl.user_id = :uid' : '') . '
             WHERE s.is_active = 1
               AND s.status IN ("ACTIVE", "PROPOSED")
               ' . ($foundBy !== null
                    ? 'AND EXISTS (SELECT 1 FROM treasure_finds tf
                                    WHERE tf.treasure_id = s.id AND tf.user_id = :found_by)'
                    : '') . '
               ' . $mineSql . '
               AND (s.active_from IS NULL OR s.active_from <= CURDATE())
               AND (s.active_to IS NULL OR s.active_to >= CURDATE())
               AND s.lat BETWEEN :south AND :north
               AND s.lon BETWEEN :west AND :east
             LIMIT ' . (int) $limit;

        $params = [
            'south' => $bounds['south'], 'north' => $bounds['north'],
            'west'  => $bounds['west'],  'east'  => $bounds['east'],
        ];
        if ($viewerId) {
            $params['uid'] = $viewerId;
        }
        if ($foundBy !== null) {
            $params['found_by'] = $foundBy;
        }
        // TYLKO gdy SQL naprawdę niesie ten placeholder: wariant `community`
        // pyta o samo ISTNIENIE znalazcy, bez kolumny użytkownika w ogóle —
        // związanie tu nieużywanego parametru wywaliłoby zapytanie
        // (EMULATE_PREPARES=false, patrz md/database.md).
        if (str_contains($mineSql, ':uid_mine')) {
            $params['uid_mine'] = $viewerId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Pola, ktore PYTAJACY ma juz odkryte — potrzebne tylko wtedy, gdy w
        // kadrze w ogole jest cos ukrytego. Przy zwyklym kadrze z samymi jawnymi
        // skarbami to zapytanie nie leci wcale.
        $hiddenCells = [];
        foreach ($rows as $row) {
            if ((int) $row['reveal_level'] < 2 && !$row['mine']) {
                $hiddenCells[] = (int) $row['cell_id'];
            }
        }
        $known = ($viewerId && $hiddenCells) ? self::knownCells($viewerId, $hiddenCells) : [];

        // LICZNIKI ZDJEC (SKA/14) — JEDNO zapytanie na cala widoczna mape,
        // nie jedno na skarb: `inBounds` oddaje do 300 punktow, wiec petla
        // z zapytaniem w srodku bylaby 300 round-tripami na kazde przesuniecie
        // mapy. Sama GALERIA doczytuje sie dopiero po kliknieciu, przez
        // `/api/treasures/{id}` — dymek potrzebuje wylacznie liczby.
        //
        // Liczymy PRZED `reveal()`, bo to `reveal()` ma zdecydowac, komu ta
        // liczba w ogole wyjdzie: przy skarbie ukrytym samo „3 zdjecia" mowi
        // juz, ze cos tam na pewno jest i ze ktos to sfotografowal.
        $photoCounts = TreasurePhoto::countsFor(array_column($rows, 'id'));

        $out = [];
        foreach ($rows as $row) {
            $row['photos_count'] = (int) ($photoCounts[(int) $row['id']] ?? 0);
            $revealed = self::reveal($row, $known);
            if ($revealed !== null) {
                $out[] = $revealed;
            }
        }
        return $out;
    }

    /**
     * Skarby w kadrze POD ALERT „ZBLIŻASZ SIĘ" w apce (Etap 5b przebudowy apki,
     * tasks/active/apka-mobilna.md, decyzja usera 2026-08-28) — jedyne miejsce,
     * które CELOWO omija bramkę `knownCells` z `inBounds()`/`reveal()` wyżej.
     *
     * PO CO TEN WYJĄTEK: apka liczy odległość do skarbów lokalnie, na
     * urządzeniu, gdy jest zwinięta w tle (pozycja nie może wtedy wychodzić na
     * serwer — patrz kontrakt). Żeby to policzyć, urządzenie musi dostać
     * współrzędne WCZEŚNIEJ, także skarbów, których pole gracz jeszcze nie
     * odkrył jazdą — inaczej alert nigdy by się nie odpalił dla nich, a to
     * właśnie te skarby user chciał tu ostrzegać.
     *
     * PRECYZJA ZOSTAJE TAKA SAMA, jaką `reveal()` przyznaje PO odkryciu —
     * `$allKnown` niżej sztucznie oznacza każde pole jako znane, więc
     * `reveal()` liczy poziom 0/1 dokładnie tak samo (środek pola, generyczna
     * nazwa/trop), tylko wcześniej. Skarb już zdobyty przez pytającego odpada
     * przed `reveal()` — nie ma czego alarmować.
     *
     * WĄSKI ZESTAW KOLUMN CELOWO: to nie jest dymek na mapie (`inBounds()`),
     * tylko dane pod powiadomienie/pulsującą kropkę — bez zdjęć, opisu,
     * rzadkości i liczby znalazców, których ten widok nie pokazuje.
     *
     * @return list<array<string,mixed>>
     */
    public static function nearbyForAlerts(array $bounds, int $viewerId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT s.id, s.name, s.lat, s.lon, s.cell_id, s.reveal_level, s.hint,
                   s.claim_radius_m,
                   -- OPIS DO NOTKI „PRZEWODNIKA" W TRAKCIE JAZDY (2026-08-29).
                   -- Bezpieczny bez dodatkowego warunku, bo `reveal()` zeruje
                   -- `description` dokładnie tam, gdzie zeruje zdjęcie i rzadkość
                   -- — czyli skarb ukryty i tropiony nie wyniesie opisu do
                   -- telefonu, a jawny (i tak opisany publicznie na mapie) tak.
                   -- Punktów tu ŚWIADOMIE NIE MA: idą w parze z rzadkością,
                   -- którą tamta bramka zdejmuje, a wracają w odpowiedzi na
                   -- zaliczenie, gdy skarb jest już znaleziony.
                   s.description,
                   c.name AS category_label, c.icon AS category_icon,
                   (cl.id IS NOT NULL) AS mine
              FROM treasures s
              LEFT JOIN dictionary_items c ON c.id = s.category_item_id
              LEFT JOIN treasure_finds cl ON cl.treasure_id = s.id AND cl.user_id = :uid
             WHERE s.is_active = 1 AND s.status = "ACTIVE"
               AND (s.active_from IS NULL OR s.active_from <= CURDATE())
               AND (s.active_to IS NULL OR s.active_to >= CURDATE())
               AND s.lat BETWEEN :south AND :north
               AND s.lon BETWEEN :west AND :east
             LIMIT 500
        ');
        $stmt->execute([
            'uid'   => $viewerId,
            'south' => $bounds['south'], 'north' => $bounds['north'],
            'west'  => $bounds['west'],  'east'  => $bounds['east'],
        ]);
        $rows = $stmt->fetchAll();

        $allKnown = array_fill_keys(array_map(static fn(array $r): int => (int) $r['cell_id'], $rows), true);

        $out = [];
        foreach ($rows as $row) {
            if ((bool) $row['mine']) {
                continue;
            }
            $revealed = self::reveal($row, $allKnown);
            if ($revealed !== null) {
                $out[] = $revealed;
            }
        }
        return $out;
    }

    /**
     * Ktore z podanych pol ten rowerzysta ma juz odkryte.
     *
     * @param int[] $cellIds
     * @return array<int,true> mapa cell_id => true, zeby sprawdzenie bylo O(1)
     */
    private static function knownCells(int $userId, array $cellIds): array
    {
        $in = implode(',', array_map('intval', array_unique($cellIds)));
        $rows = Database::connection()->query(
            'SELECT cell_id FROM discovery_cells
              WHERE user_id = ' . (int) $userId . ' AND cell_id IN (' . $in . ')'
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_fill_keys(array_map('intval', $rows), true);
    }

    /**
     * Ile z tego skarbu widzi PYTAJACY — poziomy ujawnienia (SKA/7).
     *
     * ODCINAMY DANE TUTAJ, W MODELU, A NIE W WIDOKU. To nie jest kwestia stylu:
     * gdyby dokladne wspolrzedne skarbu ukrytego wyjechaly do przegladarki i
     * dopiero JavaScript decydowal, czy je narysowac, kazdy podglad zrodla
     * odpowiedzi API zdejmowalby cala zagadke. Ukrycie musi byc po stronie,
     * ktorej uzytkownik nie kontroluje.
     *
     * ZNALAZCA WIDZI WSZYSTKO. Kto tam byl, nie ma czego ukrywac — a mapa musi
     * pokazywac jego kolekcje w pelni, bo inaczej postep jest niewidoczny.
     *
     * Poziomy:
     *   2 MIEJSCE  — dokladny punkt, nazwa, kategoria (domyslny)
     *   1 TROP     — srodek pola siatki + wskazowka; „gdzies tutaj, szukaj"
     *   0 UKRYTY   — srodek pola siatki, zero opisu; „cos tu jest"
     */
    private static function reveal(array $row, array $knownCells = []): ?array
    {
        $level = (int) ($row['reveal_level'] ?? 2);
        $mine  = (bool) ($row['mine'] ?? false);

        // ZGLOSZONY, JESZCZE NIEPOTWIERDZONY (SKA/3) — pokazujemy go na mapie
        // ZAWSZE i zawsze dokladnie, bo inaczej nikt nie mialby jak go
        // potwierdzic. Punkty pokazujemy jako zero: dopoki nie jest aktywny,
        // nic nie placi, a obiecywanie stawki byloby klamstwem.
        if (($row['status'] ?? 'ACTIVE') === 'PROPOSED') {
            $row['reveal'] = 'proposed';
            $row['points'] = 0;
            $row['confirmations'] = (int) ($row['confirmations'] ?? 0);
            $row['needed'] = self::confirmationsNeeded();
            unset($row['hint']);
            return $row;
        }

        // UKRYTY SKARB POJAWIA SIE DOPIERO W ODKRYTYM POLU — tak, jak obiecuje
        // opis w panelu („po odkryciu pola pokazuje sie wskazowka"). Bez tego
        // warunku ukrycie bylo tylko kosmetyka: kazdy widzial znak zapytania
        // dokladnie tam, gdzie cos stoi, i wystarczylo pojechac pod niego.
        //
        // Teraz ukryty skarb jest NAGRODA ZA JEZDZENIE, a nie celem sam w sobie:
        // najpierw przejedziesz przez teren, potem dowiesz sie, ze cos tam bylo.
        if (!$mine && $level < 2 && !isset($knownCells[(int) $row['cell_id']])) {
            return null;
        }

        // Wskazowka jest tresc a nie metadana — nie wysylamy jej przy poziomie
        // 0, bo sama jej dlugosc cos by juz mowila.
        $hint = ($level === 1 && !$mine) ? ($row['hint'] ?? null) : null;
        unset($row['hint']);

        if ($mine || $level >= 2) {
            $row['reveal'] = 'exact';
            $row['hint'] = $mine ? null : $hint;
            return $row;
        }

        [$lat, $lon] = DiscoveryGrid::cellCenter((int) $row['cell_id']);
        $row['lat'] = $lat;
        $row['lon'] = $lon;
        $row['reveal'] = $level === 1 ? 'hint' : 'hidden';
        $row['hint'] = $hint;

        if ($level === 0) {
            // Nazwa i kategoria same w sobie sa tropem („Punkt widokowy" zawezá
            // poszukiwania do grani), wiec na tym poziomie nie wychodza wcale.
            $row['name'] = __('Coś tu jest');
            $row['category_label'] = null;
            $row['category_code'] = null;
            $row['category_icon'] = null;
        } else {
            $row['name'] = $row['category_label'] ?: __('Skarb');
        }
        $row['photo_url'] = null;
        // GALERIA SCHODZI RAZEM ZE ZDJECIEM GLOWNYM (SKA/14) i musi to byc
        // TA SAMA LINIA kodu, nie osobny warunek gdzie indziej — zdjecie jest
        // najmocniejszym spoilerem, jaki ma ten modul: pokazuje dokladnie
        // czego szukac. `null`, nie `0`: zero znaczyloby „sprawdzilem, nie ma
        // zdjec", a my nie mowimy nawet tyle.
        $row['photos_count'] = null;
        // Opis, rzadkosc i liczba znalazcow schodza razem ze zdjeciem: kazde
        // z nich zaweza poszukiwania tak samo jak nazwa, a caly sens poziomu
        // ujawnienia polega na tym, ze najpierw trzeba tam dojechac.
        $row['description'] = null;
        $row['rarity'] = null;
        $row['finders'] = null;

        return $row;
    }

    /**
     * Postęp w kolekcji REGIONU — „1 / 10 Bieszczadzkich Skarbów".
     *
     * Region, a nie kategoria: tak brzmi cel w terenie („jadę w Bieszczady
     * pozbierać skarby"), a kategoria opisuje POJEDYNCZY punkt, nie wyprawę.
     *
     * @return array{found:int,total:int,label:?string}
     */
    public static function collectionProgress(int $userId, ?int $regionItemId): array
    {
        if ($regionItemId === null) {
            return ['found' => 0, 'total' => 0, 'label' => null];
        }
        $stmt = Database::connection()->prepare('
            SELECT r.name AS label,
                   COUNT(*) AS total,
                   SUM(f.id IS NOT NULL) AS found
              FROM treasures t
              LEFT JOIN dictionary_items r ON r.id = t.region_item_id
              LEFT JOIN treasure_finds f ON f.treasure_id = t.id AND f.user_id = :uid
             WHERE t.region_item_id = :region AND t.is_active = 1 AND t.status = "ACTIVE"
        ');
        $stmt->execute(['uid' => $userId, 'region' => $regionItemId]);
        $row = $stmt->fetch();
        return [
            'found' => (int) ($row['found'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
            'label' => $row['label'] ?? null,
        ];
    }

    /**
     * Kolekcja rowerzysty — pod profil (SKA/10).
     *
     * DZIELIMY PO KATEGORII, NIE PO REGIONIE, i to jest tu jedyna nieoczywista
     * decyzja. Region skarbu wyprowadzamy z pobliskich tras referencyjnych
     * (patrz guessRegion), wiec skarb postawiony z dala od katalogu zostaje bez
     * regionu — kolekcja regionowa bylaby dziurawa z powodu, ktorego user nigdy
     * nie zobaczy. Kategoria przychodzi wprost z formularza, wiec jest zawsze.
     * Przy okazji „wszystkie punkty widokowe" jest lepszym celem do zebrania
     * niz „wszystko w Beskidach": kolekcja ma miec KONIEC.
     *
     * POKAZUJEMY WYLACZNIE KATEGORIE, W KTORYCH COS ZNALAZL. Pelna lista na
     * profilu poczatkujacego to sciana „0 z 12" w miejscu, ktore ma zachecac.
     * Kategoria pojawia sie, gdy jest sie czym pochwalic — i od razu pokazuje
     * TEZ ile zostalo, wiec sama z siebie zaprasza dalej.
     *
     * @return list<array{id:int,label:string,icon:?string,found:int,total:int,rarityFound:array<string,int>}>
     */
    public static function collectionsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT c.id, c.name AS label, c.icon,
                   COUNT(*) AS total,
                   SUM(f.id IS NOT NULL) AS found
              FROM treasures t
              JOIN dictionary_items c ON c.id = t.category_item_id
              LEFT JOIN treasure_finds f ON f.treasure_id = t.id AND f.user_id = :uid
             WHERE t.is_active = 1 AND t.status = "ACTIVE"
             GROUP BY c.id, c.name, c.icon
            HAVING found > 0
             ORDER BY found DESC, total DESC, label ASC
        ');
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        // ROZBICIE PO RZADKOSCI (2026-08-27, zgloszenie usera: „3 z 8" nie mowi,
        // czy te trzy sa zwykle czy legendarne). Osobne zapytanie, nie kolumna
        // dolozona do pierwszego: to pierwsze grupuje PO KATEGORII i liczy
        // WSZYSTKIE punkty (znalezione + do znalezienia), a rzadkosc ma sens
        // liczyc tylko dla ZNALEZIONYCH tej osoby — inny GROUP BY, inny JOIN.
        $rarityByCategory = [];
        if ($rows) {
            $stmtR = Database::connection()->prepare('
                SELECT t.category_item_id AS cat, t.rarity, COUNT(*) AS found
                  FROM treasures t
                  JOIN treasure_finds f ON f.treasure_id = t.id AND f.user_id = :uid
                 WHERE t.is_active = 1 AND t.status = "ACTIVE"
                 GROUP BY t.category_item_id, t.rarity
            ');
            $stmtR->execute(['uid' => $userId]);
            foreach ($stmtR->fetchAll() as $r) {
                $rarityByCategory[(int) $r['cat']][(string) $r['rarity']] = (int) $r['found'];
            }
        }

        return array_map(static function (array $r) use ($rarityByCategory): array {
            $breakdown = $rarityByCategory[(int) $r['id']] ?? [];
            // Kolejnosc ZAWSZE od najpospolitszej do najrzadszej, niezaleznie
            // od tego, w jakiej kolejnosci SQL zwrocil wiersze — pasek w
            // profilu ma czytac sie od lewej jak skala.
            $rarityFound = [];
            foreach (['COMMON', 'RARE', 'EPIC', 'LEGENDARY'] as $poziom) {
                if (!empty($breakdown[$poziom])) {
                    $rarityFound[$poziom] = $breakdown[$poziom];
                }
            }
            return [
                'id'          => (int) $r['id'],
                'label'       => (string) $r['label'],
                'icon'        => $r['icon'] !== '' ? $r['icon'] : null,
                'found'       => (int) $r['found'],
                'total'       => (int) $r['total'],
                'rarityFound' => $rarityFound,
            ];
        }, $rows);
    }

    /**
     * Skarby, ktorych jeszcze nikt nie znalazl — panel mapy spolecznosci (SKA/13).
     *
     * TO JEST GLOWNA STATYSTYKA SPOLECZNOSCIOWA TEGO MODULU, a nie ranking
     * najczesciej znajdowanych. Lista „najpopularniejszych" mowi spoznionemu, ze
     * wszyscy juz tam byli, i kieruje caly ruch w te same kilka miejsc.
     * Lista czekajacych mowi mu, gdzie jeszcze nikogo nie bylo — a to jest
     * jedyna rzecz w tym module, ktorej NIE DA SIE odebrac komus, kto dolaczyl
     * pozniej: pierwsze znalezienie kazdego skarbu wciaz czeka na swojego
     * pierwszego.
     *
     * Sortujemy od NAJDLUZEJ czekajacych, bo „stoi tam od trzech tygodni i
     * nikt nie podjechal" jest mocniejszym zaproszeniem niz swiezynka.
     *
     * @return list<array{name:string,icon:?string,category:?string,region:?string,days:int,photo:?string}>
     */
    public static function waitingList(int $limit = 6): array
    {
        $stmt = Database::connection()->prepare('
            SELECT t.id, t.name, t.created_at, t.photo_url,
                   c.name AS category_label, c.icon AS category_icon,
                   r.name AS region_label,
                   DATEDIFF(NOW(), t.created_at) AS days
              FROM treasures t
              LEFT JOIN dictionary_items c ON c.id = t.category_item_id
              LEFT JOIN dictionary_items r ON r.id = t.region_item_id
              LEFT JOIN treasure_finds f ON f.treasure_id = t.id
             WHERE t.is_active = 1 AND t.status = "ACTIVE"
               AND f.id IS NULL
             ORDER BY t.created_at ASC
             LIMIT ' . max(1, min(20, $limit)) . '
        ');
        $stmt->execute();

        return array_map(static fn(array $r): array => [
            // ID, a NIE współrzędne (2026-08-20): panel obok mapy ma być
            // klikalny, ale położenie musi przejść przez bramkę ujawnienia —
            // dlatego strona pyta o nie osobno, przez `/api/treasures/{id}`.
            // Wstawienie tu lat/lon zdradziłoby dokładne miejsce skarbów
            // UKRYTYCH, które warstwa mapy celowo przycina do środka pola.
            // ZDJĘCIE nie zdradza pozycji (to fotografia obiektu, nie mapa),
            // więc idzie tu bez wyjątku — karta „Następny cel" (2026-09-05)
            // pokazuje je w heksie tak samo jak okładkę trasy.
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'icon'     => ($r['category_icon'] ?? '') !== '' ? $r['category_icon'] : null,
            'category' => $r['category_label'],
            'region'   => $r['region_label'],
            'days'     => max(0, (int) $r['days']),
            'photo'    => ($r['photo_url'] ?? '') !== '' ? $r['photo_url'] : null,
        ], $stmt->fetchAll());
    }

    /**
     * Najczesciej znajdowany skarb — JEDEN, nie ranking (SKA/13).
     *
     * Swiadomie ograniczone do jednej pozycji. Pelna tabela wynikow zamienilaby
     * mape w liste przebojow i skierowala wszystkich w te same miejsca; jeden
     * wiersz wystarczy, zeby pokazac, ze inni faktycznie w to graja — a o to
     * chodzi w social proof. Reszte miejsca w panelu zajmuja czekajace.
     */
    public static function mostFound(): ?array
    {
        $row = Database::connection()->query('
            SELECT t.name, c.icon AS category_icon, COUNT(*) AS finders
              FROM treasure_finds f
              JOIN treasures t ON t.id = f.treasure_id
              LEFT JOIN dictionary_items c ON c.id = t.category_item_id
             WHERE t.is_active = 1 AND t.status = "ACTIVE"
             GROUP BY f.treasure_id, t.name, c.icon
             ORDER BY finders DESC, t.name ASC
             LIMIT 1
        ')->fetch();

        return $row === false ? null : [
            'name'    => (string) $row['name'],
            'icon'    => ($row['category_icon'] ?? '') !== '' ? $row['category_icon'] : null,
            'finders' => (int) $row['finders'],
        ];
    }

    /** Ile potwierdzeń aktywuje zgłoszony punkt — konfigurowalne (SKA/3). */
    public static function confirmationsNeeded(): int
    {
        $n = (int) (DiscoveryScoring::config()['treasures']['confirmations_needed'] ?? 3);
        return max(1, $n);
    }

    /**
     * POTWIERDZENIE SPOLECZNOSCI (SKA/3) — „bylem tam, to miejsce istnieje".
     *
     * Model wybrany przez usera: punkt zaczyna od ZERA i zyskuje na wartosci,
     * gdy potwierdzaja go kolejne osoby. Zamiast admina decydujacego o kazdym
     * zgloszeniu, decyduje ruch w terenie.
     *
     * AKTYWACJA JEST SKUTKIEM UBOCZNYM POLICZENIA GLOSOW, NIE OSOBNA AKCJA.
     * Kusi, zeby po insercie zrobic `if (licznik >= prog) UPDATE status`, ale
     * dwa rownoczesne potwierdzenia przeczytalyby wtedy ten sam licznik i
     * aktywowaly punkt dwa razy. Warunek siedzi wiec w samym UPDATE (`status =
     * PROPOSED`), wiec baza rozstrzyga wyscig — ta sama zasada, co przy
     * INSERT IGNORE w zaliczeniach.
     *
     * @return array{ok:bool, reason:?string, count:int, needed:int, activated:bool}
     */
    public static function confirm(int $treasureId, int $userId): array
    {
        $db = Database::connection();
        $treasure = self::find($treasureId);
        $needed = self::confirmationsNeeded();

        if ($treasure === null) {
            return ['ok' => false, 'reason' => 'nieznany', 'count' => 0, 'needed' => $needed, 'activated' => false];
        }
        if ($treasure['status'] !== 'PROPOSED') {
            // Punkt juz aktywny albo wycofany — glosowanie nie ma czego zmienic.
            return ['ok' => false, 'reason' => 'nie_do_glosowania', 'count' => 0, 'needed' => $needed, 'activated' => false];
        }
        // ZGLASZAJACY NIE POTWIERDZA WLASNEGO PUNKTU. Bez tego prog 3 znaczylby
        // realnie 2 obce osoby, a przy progu 1 kazdy aktywowalby sobie sam.
        if ((int) ($treasure['created_by'] ?? 0) === $userId) {
            return ['ok' => false, 'reason' => 'wlasny', 'count' => 0, 'needed' => $needed, 'activated' => false];
        }

        $stmt = $db->prepare(
            'INSERT IGNORE INTO treasure_confirmations (treasure_id, user_id) VALUES (:t, :u)'
        );
        $stmt->execute(['t' => $treasureId, 'u' => $userId]);
        $fresh = $stmt->rowCount() > 0;

        $count = self::confirmationCount($treasureId);
        $activated = false;

        if ($count >= $needed) {
            $up = $db->prepare(
                'UPDATE treasures SET status = "ACTIVE" WHERE id = :id AND status = "PROPOSED"'
            );
            $up->execute(['id' => $treasureId]);
            $activated = $up->rowCount() > 0;
        }

        return [
            'ok'        => $fresh,
            'reason'    => $fresh ? null : 'juz_potwierdzone',
            'count'     => $count,
            'needed'    => $needed,
            'activated' => $activated,
        ];
    }

    /**
     * Potwierdzenie Z WERYFIKACJA POLOZENIA — wejscie z mapy (SKA/3).
     *
     * Sprawdzenie odleglosci jest TUTAJ, a nie w confirm(), bo confirm() musi
     * zostac dostepne dla panelu admina, ktory potwierdza zza biurka i ma do
     * tego prawo. Rowerzysta takiego prawa nie ma — jego glos znaczy cos tylko
     * dlatego, ze byl na miejscu.
     */
    public static function confirmNearby(int $treasureId, int $userId, float $lat, float $lon): array
    {
        $treasure = self::find($treasureId);
        $needed = self::confirmationsNeeded();

        if ($treasure === null) {
            return ['ok' => false, 'reason' => 'nieznany', 'count' => 0, 'needed' => $needed, 'activated' => false];
        }

        $distance = self::distanceM((float) $treasure['lat'], (float) $treasure['lon'], $lat, $lon);
        if ($distance > (int) $treasure['claim_radius_m']) {
            return ['ok' => false, 'reason' => 'za_daleko', 'count' => self::confirmationCount($treasureId),
                    'needed' => $needed, 'activated' => false];
        }

        return self::confirm($treasureId, $userId);
    }

    /** Decyzja admina o statusie zgloszonego punktu (SKA/3). */
    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['PROPOSED', 'ACTIVE', 'RETIRED'], true)) {
            return;
        }
        Database::connection()
            ->prepare('UPDATE treasures SET status = :s WHERE id = :id')
            ->execute(['s' => $status, 'id' => $id]);
    }

    /**
     * Otwarte zgloszenia jednej osoby (SKA/4) — pod limit i pod liste „moje".
     *
     * Tylko PROPOSED: punkt, ktory sie potwierdzil, przestaje byc zgloszeniem
     * i nie blokuje juz limitu. Dzieki temu ktos, kto zglasza dobre miejsca,
     * moze zglaszac dalej, a ktos, kto zglasza smieci, zatrzymuje sie sam.
     */
    public static function proposedBy(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT t.*, c.name AS category_label,
                   (SELECT COUNT(*) FROM treasure_confirmations tc WHERE tc.treasure_id = t.id) AS confirmations
              FROM treasures t
              LEFT JOIN dictionary_items c ON c.id = t.category_item_id
             WHERE t.created_by = :uid AND t.status = "PROPOSED"
             ORDER BY t.created_at DESC
        ');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    public static function confirmationCount(int $treasureId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM treasure_confirmations WHERE treasure_id = :id'
        );
        $stmt->execute(['id' => $treasureId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Punkty czekajace na potwierdzenia — poczekalnia moderacji (SKA/3).
     *
     * Sortowane od NAJBLIZSZYCH progu, a nie od najnowszych: lista ma
     * podpowiadac, gdzie jeden glos zalatwi sprawe, zamiast pokazywac sciane
     * zgloszen w kolejnosci przypadkowej.
     */
    public static function pending(int $limit = 100): array
    {
        $rows = Database::connection()->query('
            SELECT t.*, c.name AS category_label, r.name AS region_label,
                   u.name AS author_name,
                   (SELECT COUNT(*) FROM treasure_confirmations tc WHERE tc.treasure_id = t.id) AS confirmations
              FROM treasures t
              LEFT JOIN dictionary_items c ON c.id = t.category_item_id
              LEFT JOIN dictionary_items r ON r.id = t.region_item_id
              LEFT JOIN users u ON u.id = t.created_by
             WHERE t.status = "PROPOSED"
             ORDER BY confirmations DESC, t.created_at ASC
             LIMIT ' . max(1, min(500, $limit))
        )->fetchAll();

        $needed = self::confirmationsNeeded();
        foreach ($rows as $i => $row) {
            $rows[$i]['confirmations'] = (int) $row['confirmations'];
            $rows[$i]['needed'] = $needed;
        }
        return $rows;
    }

    /**
     * SKARBY ZGRUPOWANE W PĘCZKI — przy oddaleniu mapy (uwaga usera 2026-08-15).
     *
     * Pojedyncze pinezki mają sens dopiero wtedy, gdy da się je od siebie
     * odróżnić. Przy widoku całej Polski tysiąc skarbów to nie tysiąc
     * informacji, tylko jedna plama — a do przeglądarki i tak leciałby wtedy
     * komplet współrzędnych, z limitem 300 ucinającym resztę PO CICHU.
     *
     * GRUPUJEMY PO TEJ SAMEJ SIATCE, CO ODKRYCIA, a nie po osobnym algorytmie
     * klastrowania. Skarb ma już policzone `cell_id` (przy zapisie), a
     * DiscoveryGrid::parentCell agreguje je do dowolnego poziomu — ten sam
     * mechanizm, który zwija heksy na mapie odkryć. Dzięki temu pęczek skarbów
     * pokrywa się z polem, które użytkownik i tak widzi pod spodem, zamiast
     * pływać w losowym miejscu między pinezkami.
     *
     * GRUPOWANIE JEST W PHP, NIE W SQL, i to jest świadome ograniczenie: przy
     * `MAX_CLUSTER_SCAN` wierszach koszt jest żaden, a kod zostaje jeden dla
     * wszystkich poziomów. Gdy skarbów będzie tyle, że ten sufit zacznie
     * ucinać, trzeba dołożyć kolumny `parent_res*` do `treasures` (tak jak ma
     * je discovery_cell_totals) i przenieść GROUP BY do bazy.
     *
     * ZWRACA DWIE LISTY, nie jedną: pola z JEDNYM skarbem nie są pęczkiem
     * i wracają jako zwykłe skarby (`singles`), gotowe do narysowania pinezką.
     * Pole z dwoma i więcej wraca jako pęczek (`clusters`). Powód przy samym
     * podziale, niżej w kodzie.
     *
     * @return array{clusters:list<array{lat:float,lon:float,count:int,found:int}>,
     *               singles:list<array>}
     */
    public static function clustersInBounds(
        array $bounds,
        int $res,
        ?int $viewerId,
        ?int $foundBy = null,
        ?string $mine = null,
        string $mineScope = 'viewer'
    ): array {
        $rows = self::inBounds($bounds, $viewerId, self::MAX_CLUSTER_SCAN, $foundBy, $mine, $mineScope);

        $grupy = [];
        foreach ($rows as $row) {
            $rodzic = DiscoveryGrid::parentCell((int) $row['cell_id'], $res);
            if (!isset($grupy[$rodzic])) {
                $grupy[$rodzic] = ['count' => 0, 'found' => 0, 'rows' => []];
            }
            $grupy[$rodzic]['count']++;
            $grupy[$rodzic]['rows'][] = $row;
            if (!empty($row['mine'])) {
                $grupy[$rodzic]['found']++;
            }
        }

        $clusters = [];
        $singles = [];
        foreach ($grupy as $cellId => $dane) {
            // PĘCZEK ZACZYNA SIĘ OD DWÓCH (zgłoszenie usera 2026-08-20:
            // „pomimo tego że jest jeden w grupie, pokazuje 1 — wystarczy
            // ikonka skarbu"). Kółko z jedynką nie niosło żadnej informacji
            // ponad tę, którą niesie sama pinezka, a przy okazji kłamało
            // o położeniu: pęczek stoi na ŚRODKU POLA, skarb stoi tam, gdzie
            // stoi. Jedyny skarb w polu wraca więc jako zwykły skarb —
            // z prawdziwą pozycją, nazwą i dymkiem, przyciętymi tak samo jak
            // przy zbliżeniu (reveal_level załatwia `inBounds`).
            if ($dane['count'] === 1) {
                $singles[] = $dane['rows'][0];
                continue;
            }

            // ŚRODEK POLA, nie średnia współrzędnych skarbów. Średnia bywa
            // poza polem przy nierównym rozkładzie, a wtedy pęczek wskazuje
            // miejsce, w którym nic nie ma — i po przybliżeniu „ucieka".
            [$lat, $lon] = DiscoveryGrid::cellCenter((int) $cellId);
            $clusters[] = [
                'lat'   => $lat,
                'lon'   => $lon,
                'count' => $dane['count'],
                'found' => $dane['found'],
            ];
        }

        return ['clusters' => $clusters, 'singles' => $singles];
    }

    /** Ilu riderów znalazło ten skarb — social proof, bez nazwisk (§27). */
    /**
     * Czy TA osoba znalazla TEN skarb (SKA/14).
     *
     * Osobna metoda, mimo ze `inBounds` liczy to samo w kolumnie `mine`: tamta
     * odpowiada na pytanie o CALY KADR jednym JOIN-em, a ekran spod QR pyta
     * o jeden skarb i nie ma po co budowac ramki wokol punktu tylko po to.
     *
     * To jest bramka dwoch rzeczy naraz: kto WIDZI galerie skarbu ukrytego
     * i kto moze do niej DORZUCIC zdjecie.
     */
    /**
     * ZDJECIA ZNALEZIONYCH SKARBOW — pasek w sekcji „Kolekcja" na profilu (SKA/14).
     *
     * BRAMKA JEST W SAMYM WARUNKU: bierzemy WYLACZNIE skarby, ktore ta osoba
     * znalazla (JOIN po `treasure_finds`), a znalazca widzi wszystko niezaleznie
     * od poziomu ujawnienia — dokladnie ta sama regula co w `reveal()`. Dlatego
     * to zapytanie nie potrzebuje `reveal_level` i nie wolno go rozszerzac
     * o skarby „z okolicy" ani „z tej samej kategorii”: pierwszy taki dopisek
     * wypuscilby zdjecie skarbu, ktorego pytajacy jeszcze nie znalazl.
     *
     * Zdjecie glowne i galeria w jednej liscie — na profilu to jest jeden pasek
     * pamiatek, a nie dwa rodzaje zdjec.
     *
     * @return list<array{url:string,name:string,code:string}>
     */
    public static function foundPhotosForUser(int $userId, int $limit = 12): array
    {
        $stmt = Database::connection()->prepare('
            SELECT zdj.url, t.name, t.code, zdj.kiedy
              FROM (
                    SELECT tp.treasure_id, tp.url, tp.created_at AS kiedy
                      FROM treasure_photos tp
                     UNION ALL
                    SELECT t2.id, t2.photo_url, t2.created_at
                      FROM treasures t2
                     WHERE t2.photo_url IS NOT NULL AND t2.photo_url <> ""
                   ) zdj
              JOIN treasures t ON t.id = zdj.treasure_id
              JOIN treasure_finds f ON f.treasure_id = t.id AND f.user_id = :uid
             ORDER BY f.claimed_at DESC, zdj.kiedy DESC
             LIMIT ' . max(1, $limit) . '
        ');
        $stmt->execute(['uid' => $userId]);

        return array_map(static fn(array $r): array => [
            'url'  => (string) $r['url'],
            'name' => (string) $r['name'],
            'code' => (string) $r['code'],
        ], $stmt->fetchAll());
    }

    /**
     * GABLOTA NA PROFILU ROWERZYSTY (2026-09-13) — znaleziska tej osoby od
     * najrzadszych, ze zdjęciem.
     *
     * TAJEMNICA ZOSTAJE TAJEMNICĄ DLA OGLĄDAJĄCEGO. Znalazca widzi swoje skarby
     * w pełni, ale profil ogląda też ktoś, kto tego skarbu jeszcze NIE znalazł —
     * a dla niego Trop i Ukryty mają zostać zagadką (patrz `reveal()` i tabela
     * w md/features.md: „każda droga do zdjęć omijająca reveal() jest błędem").
     * Dlatego maskujemy tu, w modelu, zanim cokolwiek trafi do widoku:
     * oglądający, który sam go nie znalazł, dostaje `masked = true`, bez nazwy,
     * zdjęcia i rzadkości; kategorię tylko przy Tropie (tak jak `reveal()`).
     * `foundPhotosForUser()` wyżej tej bramki nie ma — gablota ją zastępuje.
     *
     * SKALA (2026-09-13, pytanie usera: „a co, jeśli zdobędę 300 skarbów?").
     * Strona bierze po kawałku (`$limit`/`$offset`) i może zawęzić listę do
     * rzadkości albo kategorii. FILTR NIE MOŻE ZDRADZIĆ ZAGADKI: przy filtrze
     * rzadkości zamaskowane wiersze wypadają z wyniku (inaczej „legendarne: 1"
     * mówiłoby, jaki jest ukryty skarb), a przy filtrze kategorii wypadają
     * zamaskowane Ukryte (Trop kategorię i tak pokazuje).
     *
     * @return list<array{id:int,name:?string,rarity:?string,category:?string,icon:?string,photo:?string,foundAt:string,masked:bool}>
     */
    public static function showcaseForUser(int $userId, ?int $viewerId, int $limit = 12, int $offset = 0,
                                           ?string $rarity = null, ?int $categoryId = null): array
    {
        [$where, $params] = self::showcaseWhere($userId, $viewerId, $rarity, $categoryId);
        $stmt = Database::connection()->prepare('
            SELECT t.id, t.name, t.rarity, t.reveal_level, t.photo_url,
                   c.name AS category, c.icon,
                   f.claimed_at,
                   (SELECT tp.url FROM treasure_photos tp
                     WHERE tp.treasure_id = t.id ORDER BY tp.sort_order, tp.id LIMIT 1) AS gallery_url,
                   EXISTS (SELECT 1 FROM treasure_finds v
                            WHERE v.treasure_id = t.id AND v.user_id = :viewer) AS viewer_found
              FROM treasure_finds f
              JOIN treasures t ON t.id = f.treasure_id
              LEFT JOIN dictionary_items c ON c.id = t.category_item_id
             WHERE ' . $where . '
             ORDER BY FIELD(t.rarity, "LEGENDARY", "EPIC", "RARE", "COMMON"), f.claimed_at DESC, t.id DESC
             LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset) . '
        ');
        $stmt->execute($params + ['viewer' => $viewerId ?? 0]);

        return array_map(static function (array $r) use ($userId, $viewerId): array {
            $level = (int) $r['reveal_level'];
            $masked = $level < 2 && $viewerId !== $userId && !(bool) $r['viewer_found'];
            $photo = $r['photo_url'] !== null && $r['photo_url'] !== '' ? $r['photo_url'] : $r['gallery_url'];
            return [
                'id'       => (int) $r['id'],
                'name'     => $masked ? null : (string) $r['name'],
                'rarity'   => $masked ? null : (string) $r['rarity'],
                'category' => $masked && $level === 0 ? null : ($r['category'] !== null ? (string) $r['category'] : null),
                'icon'     => $masked && $level === 0 ? null : ($r['icon'] !== '' ? $r['icon'] : null),
                'photo'    => $masked ? null : ($photo !== null && $photo !== '' ? (string) $photo : null),
                'foundAt'  => (string) $r['claimed_at'],
                'masked'   => $masked,
            ];
        }, $stmt->fetchAll());
    }

    /** Ile wierszy da `showcaseForUser()` przy tych samych filtrach — pod stronicowanie. */
    public static function countShowcaseForUser(int $userId, ?int $viewerId, ?string $rarity = null, ?int $categoryId = null): int
    {
        [$where, $params] = self::showcaseWhere($userId, $viewerId, $rarity, $categoryId);
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) FROM treasure_finds f JOIN treasures t ON t.id = f.treasure_id WHERE ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Liczniki pod przyciskami filtra rzadkości. Zamaskowane dla oglądającego
     * NIE wchodzą do żadnej rzadkości — liczą się tylko do „Wszystkie".
     *
     * @return array{ALL:int,LEGENDARY:int,EPIC:int,RARE:int,COMMON:int}
     */
    public static function showcaseRarityCounts(int $userId, ?int $viewerId): array
    {
        $out = ['ALL' => self::countShowcaseForUser($userId, $viewerId),
                'LEGENDARY' => 0, 'EPIC' => 0, 'RARE' => 0, 'COMMON' => 0];
        [$where, $params] = self::showcaseWhere($userId, $viewerId, 'ANY', null);
        $stmt = Database::connection()->prepare('
            SELECT t.rarity, COUNT(*) AS n FROM treasure_finds f JOIN treasures t ON t.id = f.treasure_id
             WHERE ' . $where . ' GROUP BY t.rarity
        ');
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($out[$r['rarity']])) { $out[$r['rarity']] = (int) $r['n']; }
        }
        return $out;
    }

    /**
     * Wspólny WHERE gablotowych zapytań. `$rarity = 'ANY'` = bez zawężenia
     * rzadkości, ale Z WYKLUCZENIEM zamaskowanych (liczniki per rzadkość).
     * Osobne nazwy placeholderów, bo EMULATE_PREPARES=false nie pozwala
     * powtórzyć nazwanego.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function showcaseWhere(int $userId, ?int $viewerId, ?string $rarity, ?int $categoryId): array
    {
        $where = 'f.user_id = :uid';
        $params = ['uid' => $userId];
        $isOwner = $viewerId !== null && $viewerId === $userId;
        // Zamaskowany dla oglądającego = Trop/Ukryty, którego on sam nie znalazł.
        $maskedSql = static function (string $suffix) use (&$params, $viewerId): string {
            $params['mv' . $suffix] = $viewerId ?? 0;
            return '(t.reveal_level < 2 AND NOT EXISTS (SELECT 1 FROM treasure_finds mv' . $suffix
                . ' WHERE mv' . $suffix . '.treasure_id = t.id AND mv' . $suffix . '.user_id = :mv' . $suffix . '))';
        };
        if ($rarity !== null) {
            if ($rarity !== 'ANY') {
                $where .= ' AND t.rarity = :rarity';
                $params['rarity'] = $rarity;
            }
            if (!$isOwner) {
                $where .= ' AND NOT ' . $maskedSql('r');
            }
        }
        if ($categoryId !== null) {
            $where .= ' AND t.category_item_id = :cat';
            $params['cat'] = $categoryId;
            if (!$isOwner) {
                $where .= ' AND NOT (t.reveal_level = 0 AND ' . $maskedSql('c') . ')';
            }
        }
        return [$where, $params];
    }

    /**
     * Ile aktywnych skarbów każdej rzadkości stoi w terenie — do pustego miejsca
     * w gablocie („legendarnych jest w Polsce 2"). Liczba publiczna: to samo
     * widać na mapie społeczności.
     *
     * @return array<string,int>
     */
    public static function rarityTotals(): array
    {
        $out = ['COMMON' => 0, 'RARE' => 0, 'EPIC' => 0, 'LEGENDARY' => 0];
        $rows = Database::connection()->query('
            SELECT rarity, COUNT(*) AS n FROM treasures
             WHERE is_active = 1 AND status = "ACTIVE"
             GROUP BY rarity
        ')->fetchAll();
        foreach ($rows as $r) {
            if (isset($out[$r['rarity']])) { $out[$r['rarity']] = (int) $r['n']; }
        }
        return $out;
    }

    /**
     * Tropy i ukryte skarby, które ta osoba MA JUŻ NA MAPIE (stoją w polu, które
     * odkryła), a których jeszcze nie znalazła — podpowiedź „coś czeka" na
     * własnym profilu. Ten sam warunek widoczności co `reveal()`: ukryty skarb
     * pojawia się dopiero w odkrytym polu.
     */
    public static function openTrailsForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*)
              FROM treasures t
              JOIN discovery_cells dc ON dc.cell_id = t.cell_id AND dc.user_id = :uid
             WHERE t.is_active = 1 AND t.status = "ACTIVE" AND t.reveal_level < 2
               AND NOT EXISTS (SELECT 1 FROM treasure_finds f WHERE f.treasure_id = t.id AND f.user_id = :uid2)
        ');
        $stmt->execute(['uid' => $userId, 'uid2' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function foundBy(int $treasureId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM treasure_finds WHERE treasure_id = :t AND user_id = :u LIMIT 1'
        );
        $stmt->execute(['t' => $treasureId, 'u' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function findersCount(int $treasureId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM treasure_finds WHERE treasure_id = :id'
        );
        $stmt->execute(['id' => $treasureId]);
        return (int) $stmt->fetchColumn();
    }

    /** Ile skarbów ta osoba znalazła i ile ich w ogóle jest — pod profil i /odkrycia. */
    public static function statsForUser(int $userId): array
    {
        $db = Database::connection();
        $found = (int) $db->query(
            'SELECT COUNT(*) FROM treasure_finds WHERE user_id = ' . (int) $userId
        )->fetchColumn();
        $total = (int) $db->query(
            'SELECT COUNT(*) FROM treasures WHERE is_active = 1'
        )->fetchColumn();
        return ['found' => $found, 'total' => $total];
    }

    /**
     * Skarby na wspolnej mapie.
     *
     * ROZBICIE JEST CELOWE i to jest jedyna nieoczywista rzecz w tej metodzie:
     * eksponujemy NIEZNALEZIONE, a nie znalezione. Licznik „znaleziono 40"
     * mowi spoznionemu, ze wszystko juz rozebrano; „czeka 12" mowi mu, ze ma
     * po co wyjechac. Ta sama zasada, co przy pierwszym odkryciu pola.
     */
    /**
     * Skarby leżące NA ZNANEJ TRASIE — ile ich jest, ile punktów niosą i ile
     * z nich ma już widz (zgłoszenie usera 2026-08-20: „brakuje statystyk,
     * jakie skarby są do zdobycia na trasie").
     *
     * DEFINICJA „NA TRASIE" TO POLA TRASY, nie promień od linii, i to nie jest
     * uproszczenie — to DOKŁADNIE ta sama reguła, którą zaliczanie posługuje
     * się naprawdę: `claimAlongTrack()` bierze pola przejechanego śladu i pyta
     * o skarby o tym samym `cell_id`. Liczba na stronie trasy odpowiada więc na
     * pytanie „ile z nich podniosę, jeśli tę trasę przejadę", a nie na jakieś
     * inne, podobnie brzmiące. (Sam moment zaliczenia sprawdza jeszcze
     * `claim_radius_m` od punktu śladu — pole ma ok. 500 m, więc skarb schowany
     * w rogu pola potrafi wymagać zjechania kilkadziesiąt metrów z trasy.)
     *
     * @return array{total:int, points:int, pointsLeft:int, found:?int}
     *         `found` to null dla gościa — „0 znalezionych" nie jest informacją
     *         o kimś, kto nie ma konta (ta sama zasada co przy postępie trasy).
     *         `pointsLeft` to punkty ze skarbów, których pytający JESZCZE nie ma
     *         (dla gościa równe `points`) — „+150 pkt" obok „1 z 3 znalezionych"
     *         czytałoby się jako obietnica, a połowa tej sumy jest już wzięta.
     */
    public static function onRoute(int $routeId, ?int $viewerId): array
    {
        return self::onCells('known_route_cells', 'route_id', $routeId, $viewerId);
    }

    /**
     * TO SAMO O JEDNYM PRZEJEŹDZIE (2026-09-03, strona `/przejazd/{id}`):
     * skarby leżące na polach, które ten przejazd dotknął.
     *
     * `rider_activity_cells` zamiast `known_route_cells` — i to jest CAŁA
     * różnica, więc obie metody chodzą tym samym zapytaniem zamiast żyć jako
     * dwie kopie. Dla przejazdu solo pola są już PRZYCIĘTE o okolice domu
     * (§27, `RiderActivity::recordSolo`), więc lista skarbów spod domu nie
     * powstaje nawet dla właściciela — i nie musi tego pilnować widok.
     */
    public static function onActivity(int $activityId, ?int $viewerId): array
    {
        return self::onCells('rider_activity_cells', 'activity_id', $activityId, $viewerId);
    }

    /**
     * TO SAMO O CAŁYM REGIONIE (2026-09-14, strona regionu). Skarb należy do
     * regionu, jeśli jego pole leży w `region_cells` — ta sama reguła „pole
     * w zbiorze pól", co dla trasy i przejazdu, więc trzecie ciało zapytania
     * nie jest potrzebne.
     */
    public static function onRegion(int $regionItemId, ?int $viewerId): array
    {
        return self::onCells('region_cells', 'region_item_id', $regionItemId, $viewerId);
    }

    /** Lista skarbów regionu — ten sam `reveal()` co na trasie. */
    public static function listInRegion(int $regionItemId, ?int $viewerId, int $limit = 60): array
    {
        return self::listOnCells('region_cells', 'region_item_id', $regionItemId, $viewerId, $limit);
    }

    /**
     * Wspólne ciało obu metod wyżej. `$table`/`$keyColumn` są NAZWAMI Z KODU,
     * nie z żądania — jedyne dwa wywołania stoją kilka linijek wyżej.
     */
    private static function onCells(string $table, string $keyColumn, int $keyValue, ?int $viewerId): array
    {
        $sql = '
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(t.points), 0) AS points,
                   COALESCE(SUM(' . ($viewerId !== null
                        ? 'CASE WHEN f.treasure_id IS NULL THEN t.points ELSE 0 END'
                        : 't.points') . '), 0) AS points_left,
                   ' . ($viewerId !== null ? 'COUNT(f.treasure_id)' : 'NULL') . ' AS found
              FROM treasures t
              JOIN ' . $table . ' src ON src.cell_id = t.cell_id AND src.' . $keyColumn . ' = :src
            ' . ($viewerId !== null
                ? 'LEFT JOIN treasure_finds f ON f.treasure_id = t.id AND f.user_id = :viewer'
                : '') . '
             WHERE t.is_active = 1 AND t.status = "ACTIVE"
               AND (t.active_from IS NULL OR t.active_from <= CURDATE())
               AND (t.active_to IS NULL OR t.active_to >= CURDATE())';

        $params = ['src' => $keyValue];
        if ($viewerId !== null) {
            $params['viewer'] = $viewerId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'points'     => (int) ($row['points'] ?? 0),
            'pointsLeft' => (int) ($row['points_left'] ?? 0),
            'found'      => $viewerId === null ? null : (int) ($row['found'] ?? 0),
        ];
    }

    /**
     * SKARBY NA TRASIE — LISTA, nie licznik (zgłoszenie usera 2026-08-23:
     * „chciałbym zobaczyć, jakie skarby (w domyśle atrakcje) zobaczę na trasie").
     *
     * `onRoute()` wyżej odpowiada „ile ich jest i ile punktów dają"; to jest
     * pytanie o coś innego — CO to za miejsca. Dlatego osobna metoda, a nie
     * dodatkowa kolumna w tamtym zapytaniu: tamto liczy agregaty jednym
     * wierszem i ma zostać tanie.
     *
     * KOLEJNOŚĆ WZDŁUŻ ŚLADU (`krc.sort_order`, migr. 048), nie po nazwie ani
     * punktach: człowiek czyta tę listę jak plan wyprawy — „najpierw minę to,
     * potem tamto". Trasy sprzed tamtej migracji nie mają kolejności (NULL)
     * i lądują na końcu, w kolejności identyfikatorów; to ta sama usterka,
     * którą panel admina nazywa „do przeliczenia".
     *
     * UJAWNIENIE PRZECHODZI PRZEZ `reveal()`, tak samo jak na mapie. Skarb
     * ukryty w polu, którego pytający nie odkrył, NIE WYCHODZI stąd wcale —
     * ani nazwą, ani zdjęciem, ani współrzędnymi. Publiczna strona trasy jest
     * najłatwiejszym miejscem, w którym dałoby się obejść całą zagadkę
     * („wejdź na trasę, przeczytaj listę, jedź prosto pod punkty"), więc
     * warunek musi być TEN SAM co wszędzie indziej — stąd wspólny `reveal()`,
     * a nie własne sprawdzenie w tym zapytaniu.
     *
     * @return array{items:list<array<string,mixed>>, hidden:int}
     *         `hidden` = ile skarbów tej trasy pytający na razie nie widzi.
     *         Liczba jest potrzebna, żeby lista zgadzała się z licznikiem
     *         w kaflach — bez niej „12 skarbów" nad listą pięciu pozycji
     *         wygląda jak błąd, a nie jak zaproszenie do jazdy.
     */
    public static function listOnRoute(int $routeId, ?int $viewerId, int $limit = 60): array
    {
        return self::listOnCells('known_route_cells', 'route_id', $routeId, $viewerId, $limit);
    }

    /**
     * LISTA SKARBÓW JEDNEGO PRZEJAZDU (2026-09-03) — bliźniak `onActivity()`
     * wobec `onRoute()`: to samo zapytanie, inna tabela pól. Kolejności wzdłuż
     * śladu tu nie ma (`rider_activity_cells` to sam zbiór pól, bez
     * `sort_order`), więc lista idzie po identyfikatorach — przejazd i tak
     * opowiada się datą, a nie planem „najpierw minę to".
     */
    public static function listOnActivity(int $activityId, ?int $viewerId, int $limit = 60): array
    {
        return self::listOnCells('rider_activity_cells', 'activity_id', $activityId, $viewerId, $limit);
    }

    /** Wspólne ciało obu list — patrz nota o nazwach tabel przy `onCells()`. */
    private static function listOnCells(
        string $table,
        string $keyColumn,
        int $keyValue,
        ?int $viewerId,
        int $limit = 60
    ): array {
        $db = Database::connection();
        // Kolejność wzdłuż śladu ma wyłącznie katalog tras (migr. 048).
        $sortowanie = $table === 'known_route_cells';
        $sql = '
            SELECT s.id, s.name, s.lat, s.lon, s.points, s.photo_url, s.cell_id,
                   s.reveal_level, s.hint, s.status, s.description, s.rarity,
                   s.claim_radius_m,
                   (SELECT COUNT(*) FROM treasure_confirmations tc WHERE tc.treasure_id = s.id) AS confirmations,
                   (SELECT COUNT(*) FROM treasure_finds fc WHERE fc.treasure_id = s.id) AS finders,
                   c.code AS category_code, c.name AS category_label, c.icon AS category_icon,
                   reg.name AS region_label,
                   ' . ($sortowanie ? 'src.sort_order,' : 'NULL AS sort_order,') . '
                   ' . ($viewerId ? '(cl.id IS NOT NULL)' : '0') . ' AS mine
              FROM treasures s
              JOIN ' . $table . ' src ON src.cell_id = s.cell_id AND src.' . $keyColumn . ' = :src
              LEFT JOIN dictionary_items c ON c.id = s.category_item_id
              LEFT JOIN dictionary_items reg ON reg.id = s.region_item_id
              ' . ($viewerId ? 'LEFT JOIN treasure_finds cl ON cl.treasure_id = s.id AND cl.user_id = :uid' : '') . '
             WHERE s.is_active = 1
               AND s.status = "ACTIVE"
               AND (s.active_from IS NULL OR s.active_from <= CURDATE())
               AND (s.active_to IS NULL OR s.active_to >= CURDATE())
             ORDER BY ' . ($sortowanie ? 'src.sort_order IS NULL, src.sort_order, ' : '') . 's.id
             LIMIT ' . (int) $limit;

        $params = ['src' => $keyValue];
        if ($viewerId) {
            $params['uid'] = $viewerId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Pola, które pytający ma odkryte — pytamy tylko wtedy, gdy na trasie
        // w ogóle jest coś ukrytego (ta sama oszczędność co w `inBounds`).
        $hiddenCells = [];
        foreach ($rows as $row) {
            if ((int) $row['reveal_level'] < 2 && !$row['mine']) {
                $hiddenCells[] = (int) $row['cell_id'];
            }
        }
        $known = ($viewerId && $hiddenCells) ? self::knownCells($viewerId, $hiddenCells) : [];

        // Liczniki zdjęć jednym zapytaniem na całą listę, nie jednym na skarb —
        // i PRZED `reveal()`, bo to ono decyduje, komu ta liczba wyjdzie.
        $photoCounts = TreasurePhoto::countsFor(array_column($rows, 'id'));

        $items = [];
        $hidden = 0;
        foreach ($rows as $row) {
            $row['photos_count'] = (int) ($photoCounts[(int) $row['id']] ?? 0);
            $revealed = self::reveal($row, $known);
            if ($revealed === null) {
                $hidden++;
                continue;
            }
            $items[] = $revealed;
        }

        return ['items' => $items, 'hidden' => $hidden];
    }

    public static function statsCommunity(): array
    {
        $db = Database::connection();
        $row = $db->query('
            SELECT COUNT(*) AS total,
                   COUNT(f.treasure_id) AS taken
              FROM treasures t
              LEFT JOIN (SELECT DISTINCT treasure_id FROM treasure_finds) f
                     ON f.treasure_id = t.id
             WHERE t.is_active = 1 AND t.status = "ACTIVE"
        ')->fetch() ?: ['total' => 0, 'taken' => 0];

        $total = (int) $row['total'];
        $taken = (int) $row['taken'];
        return [
            'total'   => $total,
            'taken'   => $taken,
            'waiting' => max(0, $total - $taken),
            'finds'   => (int) $db->query('SELECT COUNT(*) FROM treasure_finds')->fetchColumn(),
        ];
    }

    /**
     * Zapis skarbu z panelu. Pole siatki liczymy TUTAJ, przy zapisie — mapa
     * dostaje je gotowe, tak jak parent_res* w discovery_cell_totals (migr. 047).
     */
    public static function save(?int $id, array $data, ?int $adminId): int
    {
        $cellId = DiscoveryGrid::pointToCell((float) $data['lat'], (float) $data['lon']);
        $db = Database::connection();

        if ($id === null) {
            $stmt = $db->prepare('
                INSERT INTO treasures (code, name, description, hint, origin, rarity, status,
                                      reveal_level, category_item_id, region_item_id,
                                      lat, lon, cell_id, points, claim_radius_m, photo_url,
                                      is_active, active_from, active_to, created_by)
                VALUES (:code, :name, :descr, :hint, :origin, :rarity, :status, :reveal,
                        :cat, :region, :lat, :lon, :cell, :points,
                        :radius, :photo, :active, :from, :to, :author)
            ');
            $stmt->execute([
                'code'   => self::newCode(),
                'name'   => $data['name'],
                'descr'  => $data['description'] ?? null,
                'hint'   => $data['hint'] ?? null,
                'origin' => $data['origin'] ?? 'OFFICIAL',
                'rarity' => $data['rarity'] ?? 'COMMON',
                'status' => $data['status'] ?? 'ACTIVE',
                'reveal' => (int) ($data['reveal_level'] ?? 2),
                'cat'    => $data['category_item_id'] ?? null,
                'region' => $data['region_item_id'] ?? null,
                'lat'    => $data['lat'],
                'lon'    => $data['lon'],
                'cell'   => $cellId,
                // Domyślne wartości z konfiguracji, nie z palca (migr. 053):
                // admin zmienia je w panelu stawek, a nie w kodzie. Stawka
                // zależy od rzadkości (SKA/15): wpisana wartość wygrywa, bez
                // niej skarb jest wart tyle, ile wyjściowo jego rzadkość.
                'points' => (int) ($data['points'] ?? self::defaultPointsFor($data['rarity'] ?? 'COMMON')),
                'radius' => (int) ($data['claim_radius_m'] ?? self::defaultRadius()),
                'photo'  => $data['photo_url'] ?? null,
                // Brak klucza = widoczny od razu (DEFAULT kolumny i domyślnie
                // zaznaczony checkbox w formularzu); przekazany jawnie 0/1
                // wygrywa. Bez tego skarb postawiony z minimalnymi danymi
                // rodził się niewidzialny.
                'active' => array_key_exists('is_active', $data) ? (empty($data['is_active']) ? 0 : 1) : 1,
                'from'   => $data['active_from'] ?? null,
                'to'     => $data['active_to'] ?? null,
                'author' => $adminId,
            ]);
            return (int) $db->lastInsertId();
        }

        $stmt = $db->prepare('
            UPDATE treasures SET name = :name, description = :descr, hint = :hint,
                   origin = :origin, rarity = :rarity, status = :status, reveal_level = :reveal,
                   category_item_id = :cat,
                   region_item_id = :region, lat = :lat, lon = :lon, cell_id = :cell,
                   points = :points, claim_radius_m = :radius, photo_url = :photo,
                   is_active = :active, active_from = :from, active_to = :to
             WHERE id = :id
        ');
        $stmt->execute([
            'id'     => $id,
            'name'   => $data['name'],
            'descr'  => $data['description'] ?? null,
            'hint'   => $data['hint'] ?? null,
            'origin' => $data['origin'] ?? 'OFFICIAL',
            'rarity' => $data['rarity'] ?? 'COMMON',
            'status' => $data['status'] ?? 'ACTIVE',
            'reveal' => (int) ($data['reveal_level'] ?? 2),
            'cat'    => $data['category_item_id'] ?? null,
            'region' => $data['region_item_id'] ?? null,
            'lat'    => $data['lat'],
            'lon'    => $data['lon'],
            'cell'   => $cellId,
            'points' => (int) ($data['points'] ?? self::defaultPointsFor($data['rarity'] ?? 'COMMON')),
            'radius' => (int) ($data['claim_radius_m'] ?? self::defaultRadius()),
            'photo'  => $data['photo_url'] ?? null,
            'active' => array_key_exists('is_active', $data) ? (empty($data['is_active']) ? 0 : 1) : 1,
            'from'   => $data['active_from'] ?? null,
            'to'     => $data['active_to'] ?? null,
        ]);
        return $id;
    }

    /**
     * Nowy kod dla skarbu. Osobno od id, więc przy wycieku (ktoś publikuje
     * zdjęcie naklejki w internecie) wymienia się sam kod, a historia zaliczeń
     * zostaje.
     */
    /** Punkty domyślne — z panelu stawek, z wartością z pliku jako zapasem. */
    public static function defaultPoints(): int
    {
        return (int) (DiscoveryScoring::config()['treasures']['default_points'] ?? 50);
    }

    /**
     * Punkty wyjsciowe dla danej rzadkosci (SKA/15).
     *
     * Uzywane TYLKO wtedy, gdy zakladajacy skarb nie wpisal wlasnej wartosci.
     * Rzadkosc nie jest mnoznikiem — patrz komentarz w ScoringSettings::EDITABLE.
     * Brak wpisu w konfiguracji cofa sie do wartosci domyslnej, wiec system
     * dziala tez zanim admin cokolwiek ustawi.
     */
    public static function defaultPointsFor(string $rarity): int
    {
        $cfg = DiscoveryScoring::config()['treasures']['points'] ?? [];
        $value = (int) ($cfg[$rarity] ?? 0);
        return $value > 0 ? $value : self::defaultPoints();
    }

    /**
     * Polska nazwa rzadkosci — JEDYNE miejsce w PHP, ktore tlumaczy ENUM z bazy
     * (odpowiednik `RZADKOSC` w discovery-map.js; dwie kopie, bo jedna zyje w
     * JS pod dymek na mapie, druga w PHP pod widoki renderowane po stronie
     * serwera — Puls, profil). Nieznana wartosc wraca jako null, nie wyjatek:
     * widoki maja po prostu pominac chip rzadkosci, a nie wywrocic strone.
     */
    public static function rarityLabel(?string $rarity): ?string
    {
        return match ($rarity) {
            'COMMON'    => 'zwykły',
            'RARE'      => 'rzadki',
            'EPIC'      => 'epicki',
            'LEGENDARY' => 'legendarny',
            default     => null,
        };
    }

    /** Promień domyślny — j.w. */
    public static function defaultRadius(): int
    {
        return (int) (DiscoveryScoring::config()['treasures']['default_radius_m'] ?? 150);
    }

    public static function newCode(): string
    {
        return bin2hex(random_bytes(self::CODE_BYTES));
    }

    /** Jeden skarb po id — pod edycję w panelu. */
    public static function find(int $id): ?array
    {
        // `claims` i `region_label` doklejone 2026-08-20: panel musi wiedzieć,
        // czy ten punkt ktoś już znalazł, ZANIM pokaże przycisk „Skasuj" —
        // inaczej proponowałby operację, której serwer i tak odmówi (patrz
        // `deleteIfUnfound`). Jedno podzapytanie na jeden wiersz.
        $stmt = Database::connection()->prepare('
            SELECT s.*, r.name AS region_label,
                   (SELECT COUNT(*) FROM treasure_finds cl WHERE cl.treasure_id = s.id) AS claims
              FROM treasures s
              LEFT JOIN dictionary_items r ON r.id = s.region_item_id
             WHERE s.id = :id
        ');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Nowy kod dla istniejącego skarbu — stara naklejka przestaje działać.
     *
     * Historia znalezień zostaje nietknięta, bo wiersz jest ten sam. To jedyny
     * powód, dla którego `code` nie jest identyfikatorem (migr. 054).
     */
    /**
     * Region wyprowadzony z LOKALIZACJI, nie z listy w formularzu.
     *
     * Uwaga usera (2026-08-14): „po co region w menu, jak wiadomo po lokalizacji".
     * Racja — człowiek stawiający pinezkę widzi, gdzie ona jest. Region jest
     * jednak potrzebny, bo po nim grupują się kolekcje („1 / 10 Bieszczadzkich
     * Skarbów"), więc bierzemy go z NAJBLIŻSZEJ znanej trasy.
     *
     * Dlaczego trasa, a nie granica administracyjna: regiony w tym serwisie to
     * pozycje słownika, a nie geometria — nie ma czego przeciąć punktem. Znana
     * trasa ma region nadany ręcznie i leży tam, gdzie ludzie jeżdżą, więc jest
     * najlepszym dostępnym przybliżeniem.
     *
     * ZWRACA NULL, GDY NIC NIE JEST BLISKO. Skarb bez regionu po prostu nie
     * należy do kolekcji — to uczciwsze niż przypisanie go do Bieszczadów,
     * bo akurat one były najbliżej z odległości 200 km.
     */
    public static function guessRegion(float $lat, float $lon, int $maxKm = 40): ?int
    {
        // Od migr. 074/070: region_cells zna już DOKŁADNIE, do którego
        // województwa należy heks skarbu — nie trzeba już zgadywać po
        // najbliższej znanej trasie. $maxKm zostaje w sygnaturze (wołający
        // kod go przekazuje), ale przestał mieć znaczenie: albo heks ma
        // bezpośrednie pokrycie w region_cells, albo skarb zostaje bez
        // regionu (tak jak wcześniej „nic nie jest blisko").
        $cellId = DiscoveryGrid::pointToCell($lat, $lon);
        $stmt = Database::connection()->prepare('SELECT region_item_id FROM region_cells WHERE cell_id = :cell');
        $stmt->execute(['cell' => $cellId]);
        $region = $stmt->fetchColumn();
        return $region === false ? null : (int) $region;
    }

    public static function rotateCode(int $id): void
    {
        Database::connection()
            ->prepare('UPDATE treasures SET code = :code WHERE id = :id')
            ->execute(['code' => self::newCode(), 'id' => $id]);
    }

    /** Ile skarbów na stronę panelu — tyle samo co przy trasach. */
    public const PER_PAGE = 25;

    /**
     * Lista skarbów do PANELU — szukanie, filtr, sortowanie, stronicowanie.
     *
     * Osobno od `all()`, i to nie dla wygody: `all()` ładuje CAŁĄ tabelę, a
     * panel ma dorosnąć do tysiąca punktów (pytanie usera 2026-08-20: „co
     * w przypadku 1000 punktów?"). Przy tysiącu wierszy lista bez wyszukiwarki
     * nie jest listą, tylko ścianą — ten sam wniosek, który przy dwustu trasach
     * dał `KnownRoute::search()`. Kształt wyniku i nazwy kluczy są celowo takie
     * same, bo widok stronicuje się tym samym kawałkiem kodu.
     *
     * @param array{szukaj?:string, filtr?:string, sort?:string, strona?:int} $opcje
     * @return array{items:array, total:int, strona:int, stron:int, szukaj:string, filtr:string, sort:string}
     */
    public static function search(array $opcje = []): array
    {
        $szukaj = trim((string) ($opcje['szukaj'] ?? ''));
        $filtr  = (string) ($opcje['filtr'] ?? '');
        $sort   = (string) ($opcje['sort'] ?? '');
        $strona = max(1, (int) ($opcje['strona'] ?? 1));

        $from = '
              FROM treasures s
              LEFT JOIN dictionary_items c ON c.id = s.category_item_id
              LEFT JOIN dictionary_items r ON r.id = s.region_item_id
        ';

        $where = [];
        $params = [];
        if ($szukaj !== '') {
            // Nazwa ALBO kod ALBO region ALBO kategoria. Kod, bo admin
            // przychodzi tu z naklejką w ręku; region i kategoria, bo „pokaż
            // bieszczadzkie" i „pokaż wszystkie kapliczki" to naturalne pytania
            // przy dużym zbiorze. Cztery osobne placeholdery — EMULATE_PREPARES
            // jest wyłączone, więc tej samej nazwy nie da się użyć dwa razy.
            $where[] = '(s.name LIKE :q1 OR s.code LIKE :q2 OR r.name LIKE :q3 OR c.name LIKE :q4)';
            foreach (['q1', 'q2', 'q3', 'q4'] as $klucz) {
                $params[$klucz] = '%' . $szukaj . '%';
            }
        }

        $where[] = match ($filtr) {
            'aktywne'   => 's.status = "ACTIVE" AND s.is_active = 1',
            'zgloszone' => 's.status = "PROPOSED"',
            'wycofane'  => '(s.status = "RETIRED" OR s.is_active = 0)',
            // „Nieznalezione" to jedyny filtr, który pokazuje coś DO ZROBIENIA:
            // punkt, po który nikt nie pojechał, może stać w złym miejscu albo
            // mieć zerwaną naklejkę. Przy tysiącu punktów nie da się tego
            // wypatrzeć okiem.
            'nieznalezione' => 'NOT EXISTS (SELECT 1 FROM treasure_finds f WHERE f.treasure_id = s.id)',
            default     => '1 = 1',
        };

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);

        // Sortowanie z ZAMKNIĘTEJ listy — wartość idzie z adresu.
        $orderBy = match ($sort) {
            'najnowsze'   => 's.created_at DESC, s.id DESC',
            'punkty'      => 's.points DESC, s.name ASC',
            'znalezienia' => 'claims DESC, s.name ASC',
            'region'      => 'r.name IS NULL ASC, r.name ASC, s.name ASC',
            default       => 's.name ASC',
        };

        $db = Database::connection();
        $count = $db->prepare('SELECT COUNT(*) ' . $from . $sqlWhere);
        $count->execute($params);
        $ile = (int) $count->fetchColumn();

        $offset = ($strona - 1) * self::PER_PAGE;
        $stmt = $db->prepare('
            SELECT s.*, c.name AS category_label, r.name AS region_label,
                   (SELECT COUNT(*) FROM treasure_finds cl WHERE cl.treasure_id = s.id) AS claims
            ' . $from . $sqlWhere . '
             ORDER BY ' . $orderBy . '
             LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset . '
        ');
        $stmt->execute($params);

        return [
            'items'  => $stmt->fetchAll(),
            'total'  => $ile,
            'strona' => $strona,
            'stron'  => max(1, (int) ceil($ile / self::PER_PAGE)),
            'szukaj' => $szukaj,
            'filtr'  => $filtr,
            'sort'   => $sort,
        ];
    }

    /** Kafle nad listą panelu — te same nazwy filtrów co w `search()`. */
    public static function counters(): array
    {
        $row = Database::connection()->query('
            SELECT COUNT(*) AS wszystkie,
                   SUM(s.status = "ACTIVE" AND s.is_active = 1) AS aktywne,
                   SUM(s.status = "PROPOSED") AS zgloszone,
                   SUM(s.status = "RETIRED" OR s.is_active = 0) AS wycofane,
                   SUM(NOT EXISTS (SELECT 1 FROM treasure_finds f WHERE f.treasure_id = s.id)) AS nieznalezione
              FROM treasures s
        ')->fetch() ?: [];

        return [
            'wszystkie'     => (int) ($row['wszystkie'] ?? 0),
            'aktywne'       => (int) ($row['aktywne'] ?? 0),
            'zgloszone'     => (int) ($row['zgloszone'] ?? 0),
            'wycofane'      => (int) ($row['wycofane'] ?? 0),
            'nieznalezione' => (int) ($row['nieznalezione'] ?? 0),
        ];
    }

    /**
     * Skarby w KADRZE MAPY PANELU — pełna precyzja, bez przycinania ujawnieniem.
     *
     * Osobno od `inBounds()`, bo to dwa różne pytania. Tamto odpowiada graczowi
     * („co wolno CI zobaczyć") i dlatego przycina pozycję skarbu ukrytego do
     * środka pola; panel odpowiada autorowi („gdzie to naprawdę stoi") i musi
     * pokazać prawdziwy punkt — inaczej przeciągnięcie pinezki przesuwałoby
     * skarb w miejsce, którego nikt nie wskazywał.
     *
     * PYTAMY O KADR, NIE O CAŁOŚĆ (2026-08-20): do tej daty widok wsypywał
     * WSZYSTKIE skarby do HTML-a jako JSON i rysował je co do jednego. Przy
     * tysiącu punktów to kilkaset kilobajtów na każde wejście do panelu,
     * niezależnie od tego, na co admin patrzy.
     *
     * @return list<array>
     */
    public static function inBoundsAdmin(array $bounds, int $limit = 500): array
    {
        $stmt = Database::connection()->prepare('
            SELECT s.*, c.name AS category_label, r.name AS region_label,
                   (SELECT COUNT(*) FROM treasure_finds cl WHERE cl.treasure_id = s.id) AS claims
              FROM treasures s
              LEFT JOIN dictionary_items c ON c.id = s.category_item_id
              LEFT JOIN dictionary_items r ON r.id = s.region_item_id
             WHERE s.lat BETWEEN :south AND :north
               AND s.lon BETWEEN :west AND :east
             ORDER BY s.id DESC
             LIMIT ' . max(1, min(2000, $limit)));
        $stmt->execute([
            'south' => $bounds['south'], 'north' => $bounds['north'],
            'west'  => $bounds['west'],  'east'  => $bounds['east'],
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Skasowanie skarbu — WYŁĄCZNIE takiego, którego nikt nie znalazł.
     *
     * Ta sama zasada co przy kasowaniu kont (`UserAdmin::deleteIfEmpty`) i z tego
     * samego powodu: znalezienie zapłaciło punktami, a `point_transactions` to
     * rejestr NIEZMIENNY — skasowany skarb zostawiłby w nim wiersze wskazujące
     * na byt, którego nie ma, i zabrałby ludziom punkty z historii. Punkt, po
     * który ktoś pojechał, ma więc jedną drogę zejścia ze sceny: status
     * `RETIRED` („Wycofaj"), który zdejmuje go z mapy, a historię zostawia.
     *
     * @return array{ok:bool, reason:?string, finds:int}
     */
    public static function deleteIfUnfound(int $id): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM treasure_finds WHERE treasure_id = :id');
        $stmt->execute(['id' => $id]);
        $finds = (int) $stmt->fetchColumn();

        if ($finds > 0) {
            return ['ok' => false, 'reason' => 'znaleziony', 'finds' => $finds];
        }

        // Potwierdzenia społeczności (SKA/3) lecą razem ze skarbem — nie są
        // niczyim dorobkiem, tylko głosami nad tym jednym punktem.
        $db->prepare('DELETE FROM treasure_confirmations WHERE treasure_id = :id')->execute(['id' => $id]);
        $del = $db->prepare('DELETE FROM treasures WHERE id = :id');
        $del->execute(['id' => $id]);
        $ok = $del->rowCount() > 0;

        return ['ok' => $ok, 'reason' => $ok ? null : 'brak', 'finds' => 0];
    }

    public static function all(bool $onlyActive = false): array
    {
        return Database::connection()->query('
            SELECT s.*, c.name AS category_label, r.name AS region_label,
                   (SELECT COUNT(*) FROM treasure_finds cl WHERE cl.treasure_id = s.id) AS claims
              FROM treasures s
              LEFT JOIN dictionary_items c ON c.id = s.category_item_id
              LEFT JOIN dictionary_items r ON r.id = s.region_item_id
             ' . ($onlyActive ? 'WHERE s.is_active = 1' : '') . '
             ORDER BY s.created_at DESC
        ')->fetchAll();
    }

    private static function isActiveNow(array $treasure): bool
    {
        // STATUS SPRAWDZAMY TUTAJ, a nie w każdym zapytaniu z osobna — przez tę
        // metodę przechodzą wszystkie trzy drogi zaliczenia (QR, GPS, GPX).
        // Bez tego skarb zgłoszony przez społeczność, ale jeszcze nie
        // potwierdzony, dawał się zaliczyć kodem z naklejki i płacił punktami
        // za coś, czego nikt nie zweryfikował (SKA/3).
        if (($treasure['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            return false;
        }

        $today = date('Y-m-d');
        if ($treasure['active_from'] !== null && $treasure['active_from'] > $today) {
            return false;
        }
        if ($treasure['active_to'] !== null && $treasure['active_to'] < $today) {
            return false;
        }
        return true;
    }

    /** Haversine — ta sama funkcja co w Utils\Gpx, ale tu bez ładowania śladu. */
    private static function distanceM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
