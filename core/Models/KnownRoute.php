<?php
// core/Models/KnownRoute.php
// Etap 8 — ZNANE TRASY (§10, §11). Velo Czorsztyn, Green Velo, Szlak Orlich
// Gniazd, trasy zawodów, lokalne klasyki.
//
// Trasa jest DANYMI, nigdy kodem: dodaje się ją w panelu admina, wgrywając
// plik GPX, dokładnie tak jak trasę wydarzenia. W kodzie nie ma i nie może
// pojawić się ani jedna nazwa konkretnej trasy.
//
// POSTĘPU NIE MA W ŻADNEJ TABELI. Jest przecięciem pól trasy z polami, które
// dana osoba już odkryła — liczonym na żądanie. Ten serwis ma już dwa duże
// byty zbudowane tak samo (Kronika i Puls nie mają własnych tabel) i za każdym
// razem wygrywało to samo: nie istnieje stan "postęp rozjechał się z faktami",
// nie ma crona, który mógłby nie zadziałać, i nie da się mieć 80% trasy bez
// przejechania 80% jej pól.
//
// Wyjątkiem są PUNKTY za progi — te muszą zostać zapamiętane, i siedzą
// w point_transactions (Etap 8A) pod source_id „trasa:próg". Klucz unikalny
// rejestru pilnuje, żeby każdy próg zapłacił raz; syncProgress() liczy stan
// obecny i nie musi pamiętać, co było wcześniej.
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;
use Utils\Format;
use Utils\Gpx;
use Utils\RoadSurfaceDetector;
use Utils\TileGrid;
use Utils\TrackPalette;

class KnownRoute
{
    /**
     * Kolumny, które wolno zmienić z panelu (patrz `update()`).
     *
     * Nie ma tu `slug` i to jest decyzja, nie przeoczenie: adres `/trasy/{slug}`
     * raz gdzieś poszedł i ma dalej działać (ta sama zasada co przy slugach
     * rowerzystów). Nie ma też `gpx_url`, `distance_km` ani `cells_total` —
     * te trzy są WYPROWADZANE z pliku i zmienia je wyłącznie `replaceGpx()`,
     * bo ręczna korekta liczby pól rozjechałaby procent postępu z faktami.
     * Nie ma `is_active`: włącza i wyłącza wyłącznie `setActive()`, wołane
     * osobnym przyciskiem — jedna kolumna, jedna droga zapisu.
     */
    private const EDITABLE = [
        'name', 'description', 'cover_photo_url',
        'bonus_enabled', 'bonus_points', 'completion_bonus',
        // Emblemat za całość (migr. 087) — samo PRZYPISANIE, definicja mieszka
        // w `emblems`. NULL zdejmuje emblemat z trasy; zdobytych egzemplarzy
        // to nie rusza, bo emblematu nie da się stracić (patrz Models\Emblem).
        'emblem_id',
    ];

    /** Ile tras na stronę panelu. */
    public const PER_PAGE = 25;

    /**
     * PALETA TRAS (migr. 064, zgłoszenie usera 2026-08-20).
     *
     * Do tej daty każdy szlak rysował się kolorem `route` z Models\TileSource
     * (#2C6B4F). Na mapie odkryć, gdzie linie idą po szarej mgle i często tym
     * samym korytarzem, dwie trasy nakładające się na siebie były nie do
     * rozróżnienia — jedna zielona plama zamiast kilku szlaków.
     *
     * OD 2026-08-27 PALETA NIE MIESZKA JUŻ TUTAJ. Przeniesiona do
     * `Utils\TrackPalette`, gdy ten sam problem wrócił w większej skali:
     * kolory per obiekt miały WYŁĄCZNIE znane trasy, a wszystkie przejazdy
     * (solo i z wyjazdów) szły dalej jedną grupą w jednym kolorze — zgłoszenie
     * usera: „mam jedną wielką zieloną plamę". Skoro ta sama paleta obsługuje
     * teraz i szlaki, i ślady, nie może być własnością jednego z nich.
     *
     * Stała ZOSTAJE jako alias, bo „kolor znanej trasy" to pojęcie, którego
     * używa pół serwisu (dymek na mapie, karta trasy, wykres profilu) i nie ma
     * powodu, żeby każde z tych miejsc wiedziało, gdzie fizycznie leży paleta.
     */
    public const COLORS = TrackPalette::COLORS;

    /**
     * Promień sąsiedztwa w POLACH siatki odkryć przy doborze koloru.
     *
     * Pole ma ok. 500 m, więc 2 to „bliżej niż jakiś kilometr" — mniej więcej
     * tyle, ile trzeba, żeby dwie linie na mapie w praktycznym powiększeniu
     * (12–14) leżały na sobie albo tuż obok. Większy promień robiłby sąsiadami
     * szlaki, które nigdzie się nie spotykają, i wyczerpywał paletę bez powodu;
     * mniejszy przepuszczałby trasy biegnące równolegle dwiema stronami tej
     * samej doliny.
     */
    private const COLOR_NEIGHBOUR_RADIUS = 2;

    /**
     * Kolor trasy z zapisanego indeksu — jedyne miejsce, które zamienia numer
     * z bazy na wartość HEX. Woła to i renderer kafli, i dymek na mapie, i
     * karty tras, bo „ta trasa jest niebieska" musi znaczyć to samo wszędzie.
     *
     * NULL (trasa sprzed migracji 064 albo sprzed backfillu) daje kolor bazowy,
     * czyli dokładnie to, co serwis rysował wcześniej. Brak przydziału nie ma
     * prawa niczego zepsuć — ma tylko nie poprawiać.
     */
    public static function colorOf($index): string
    {
        return TrackPalette::colorOf($index);
    }

    /**
     * Przydział koloru JEDNEJ trasie — po wgraniu jej pliku GPX.
     *
     * ZASADA: bierzemy kolor, którego nie ma żadna trasa leżąca w pobliżu;
     * przy remisie ten najrzadziej użyty w całym katalogu (żeby mapa nie
     * skręcała w jedną barwę tam, gdzie tras jest mało). Gdyby sąsiedzi zajęli
     * całą paletę — co przy sześciu kolorach znaczy sześć szlaków w tym samym
     * korytarzu — wygrywa kolor NAJRZADSZY WŚRÓD SĄSIADÓW, więc kolizja ląduje
     * tam, gdzie jest jej najmniej widać, zamiast trafiać w pierwszy z brzegu.
     *
     * KOLORÓW SĄSIADÓW NIE RUSZAMY i to jest sedno całego rozwiązania. Kafle
     * są plikami na dysku, unieważnianymi po ŚLADZIE trasy (TileCache::
     * invalidateTrack) — przemalowanie sąsiada wymagałoby skasowania kafli
     * także na jego przebiegu, a przemalowanie sąsiada sąsiada lawinowo dalej.
     * Przydział przyrostowy daje ten sam efekt tam, gdzie on jest potrzebny
     * (nowa trasa różni się od tego, co już leży obok), bez ruszania niczego,
     * co jest już narysowane.
     */
    public static function assignColor(int $routeId): void
    {
        $used = self::neighbourColors($routeId);
        self::setColor($routeId, self::pickColor($used, self::colorUsage()));
    }

    /**
     * Pokolorowanie CAŁEGO katalogu — backfill po migracji 064.
     *
     * Idzie od tras o NAJWIĘKSZEJ liczbie sąsiadów. Zachłanne kolorowanie grafu
     * tą kolejnością wyczerpuje paletę najrzadziej: gdyby zacząć od szlaków
     * samotnych, te z zatłoczonego korytarza dostawałyby resztki.
     *
     * Rusza WYŁĄCZNIE trasy bez koloru, więc powtórne uruchomienie niczego nie
     * przemalowuje — a to znaczy, że nie unieważnia kafli, których nikt nie
     * kazał unieważniać (ta sama zasada idempotencji co przy backfillu 062).
     * Kafle KAŻDEJ pokolorowanej trasy lecą natomiast od razu: bez tego zmiana
     * siedziałaby w bazie, a mapa dalej pokazywałaby stare, zielone linie.
     *
     * @return array{colored:int, skipped:int}
     */
    public static function assignAllColors(?callable $log = null): array
    {
        $db = Database::connection();
        $sasiedzi = self::neighbourGraph();

        $rows = $db->query(
            'SELECT id, gpx_url, color_index FROM known_routes WHERE is_active = 1 ORDER BY id ASC'
        )->fetchAll();

        $kolory = [];
        $doPokolorowania = [];
        $pliki = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $pliki[$id] = $row['gpx_url'];
            if ($row['color_index'] !== null) {
                $kolory[$id] = (int) $row['color_index'];
                continue;
            }
            $doPokolorowania[] = $id;
        }

        // Sortowanie po liczbie sąsiadów malejąco; przy remisie po id, żeby
        // wynik backfillu był powtarzalny (dwa przebiegi na tej samej bazie
        // muszą dać te same kolory).
        usort($doPokolorowania, static function (int $a, int $b) use ($sasiedzi): int {
            $ra = count($sasiedzi[$a] ?? []);
            $rb = count($sasiedzi[$b] ?? []);
            return $ra === $rb ? $a <=> $b : $rb <=> $ra;
        });

        $uzycie = array_count_values($kolory);
        $pokolorowane = 0;
        foreach ($doPokolorowania as $id) {
            $zajete = [];
            foreach ($sasiedzi[$id] ?? [] as $sasiad) {
                if (isset($kolory[$sasiad])) {
                    $zajete[] = $kolory[$sasiad];
                }
            }
            $kolor = self::pickColor($zajete, $uzycie);
            self::setColor($id, $kolor);
            $kolory[$id] = $kolor;
            $uzycie[$kolor] = ($uzycie[$kolor] ?? 0) + 1;
            $pokolorowane++;

            // KAFLE MUSZĄ PÓJŚĆ RAZEM Z KOLOREM. Backfill zmienia to, jak
            // trasa WYGLĄDA, a kafle sprzed niego leżą na dysku z nagłówkiem
            // `immutable` — bez tego szlak zostałby zielony u wszystkich, którzy
            // kiedykolwiek zajrzeli na mapę, i zmiana byłaby niewidoczna
            // dokładnie tam, gdzie ktoś patrzy najczęściej.
            self::invalidateTiles($pliki[$id] ?? null, $id);
            if ($log) {
                $log(sprintf(
                    '  trasa #%d -> %s (sąsiadów: %d)',
                    $id,
                    self::COLORS[$kolor],
                    count($sasiedzi[$id] ?? [])
                ));
            }
        }

        return ['colored' => $pokolorowane, 'skipped' => count($kolory) - $pokolorowane];
    }

    /**
     * Wybór koloru — algorytm mieszka w `Utils\TrackPalette::pick()` od
     * 2026-08-27, odkąd ten sam przydział obsługuje także zwykłe ślady
     * (`GpxGeometry::assignColor`). Metoda zostaje jako nazwa lokalna, żeby
     * czytelnik `assignColor()`/`assignAllColors()` nie musiał wychodzić poza
     * ten plik, żeby zrozumieć, co się dzieje.
     *
     * @param int[] $zajete indeksy kolorów sąsiadów (mogą się powtarzać)
     * @param array<int,int> $uzycie indeks koloru => ile tras go ma
     */
    private static function pickColor(array $zajete, array $uzycie): int
    {
        return TrackPalette::pick($zajete, $uzycie);
    }

    private static function setColor(int $routeId, int $colorIndex): void
    {
        Database::connection()
            ->prepare('UPDATE known_routes SET color_index = :c WHERE id = :id')
            ->execute(['c' => $colorIndex, 'id' => $routeId]);
    }

    /** Ile tras ma który kolor — do rozkładania palety po katalogu. */
    private static function colorUsage(): array
    {
        $rows = Database::connection()->query(
            'SELECT color_index, COUNT(*) AS ile FROM known_routes
              WHERE color_index IS NOT NULL AND is_active = 1
              GROUP BY color_index'
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['color_index']] = (int) $row['ile'];
        }
        return $out;
    }

    /**
     * Kolory tras leżących w pobliżu danej trasy.
     *
     * Sąsiedztwo liczymy na WSPÓŁRZĘDNYCH OSIOWYCH pól (migr. 061), a nie na
     * geometrii z plików GPX: pola trasa i tak ma policzone, leżą w tabeli
     * z indeksem prostokątnym (`idx_krc_bbox`) i to ten sam zbiór, po którym
     * mapa filtruje kadr. Liczenie odległości z punktów GPX oznaczałoby
     * parsowanie plików przy każdym wgraniu trasy tylko po to, żeby dobrać
     * kolor.
     *
     * @return int[] indeksy kolorów (z powtórzeniami — powtórzenie jest treścią:
     *               kolor, który ma trzech sąsiadów, jest gorszym wyborem niż
     *               ten, który ma jednego)
     */
    private static function neighbourColors(int $routeId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT o.id, o.color_index
              FROM known_routes o
             WHERE o.id <> :id
               AND o.is_active = 1
               AND o.color_index IS NOT NULL
               AND EXISTS (
                   SELECT 1
                     FROM known_route_cells a
                     JOIN known_route_cells b
                       ON b.route_id = o.id
                      AND b.cell_r BETWEEN a.cell_r - :r1 AND a.cell_r + :r2
                      AND b.cell_q BETWEEN a.cell_q - :r3 AND a.cell_q + :r4
                    WHERE a.route_id = :id2
               )
        ');
        $stmt->execute([
            'id'  => $routeId,
            'id2' => $routeId,
            'r1'  => self::COLOR_NEIGHBOUR_RADIUS, 'r2' => self::COLOR_NEIGHBOUR_RADIUS,
            'r3'  => self::COLOR_NEIGHBOUR_RADIUS, 'r4' => self::COLOR_NEIGHBOUR_RADIUS,
        ]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 1));
    }

    /**
     * Graf sąsiedztwa CAŁEGO katalogu — jednym zapytaniem, bo backfill
     * potrzebuje wszystkich par naraz, a pytanie o sąsiadów osobno dla każdej
     * trasy dawałoby N przejść po tej samej tabeli.
     *
     * @return array<int,int[]> id trasy => id sąsiadów
     */
    private static function neighbourGraph(): array
    {
        $rows = Database::connection()->query(
            'SELECT DISTINCT a.route_id AS a, b.route_id AS b
               FROM known_route_cells a
               JOIN known_route_cells b
                 ON b.route_id <> a.route_id
                AND b.cell_r BETWEEN a.cell_r - ' . self::COLOR_NEIGHBOUR_RADIUS
                                . ' AND a.cell_r + ' . self::COLOR_NEIGHBOUR_RADIUS . '
                AND b.cell_q BETWEEN a.cell_q - ' . self::COLOR_NEIGHBOUR_RADIUS
                                . ' AND a.cell_q + ' . self::COLOR_NEIGHBOUR_RADIUS
        )->fetchAll();

        $graf = [];
        foreach ($rows as $row) {
            $graf[(int) $row['a']][] = (int) $row['b'];
        }
        return $graf;
    }

    /**
     * Lista tras do PANELU — szukanie, filtr, sortowanie, stronicowanie.
     *
     * Osobno od `all()`, bo to dwa różne pytania: `all()` odpowiada „jakie są
     * trasy" (katalog publiczny, komplet), a to — „gdzie jest TA jedna trasa,
     * którą mam poprawić". Przy katalogu, który ma dorosnąć do dwustu szlaków,
     * lista bez wyszukiwarki nie jest listą, tylko ścianą.
     *
     * Kształt wyniku i nazwy kluczy są celowo takie same jak w
     * `UserAdmin::search()` — to ten sam rodzaj ekranu i widok stronicuje się
     * w obu tym samym kawałkiem kodu.
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

        // Trasa może mieć więcej niż jeden region (migr. 074, przecina granicę
        // województw) — `reg.name` jest tu listą po przecinku, a `reg.code`
        // pierwszym z nich (po sort_order), bo jedynym miejscem, które go dziś
        // czyta, jest link „zobacz wydarzenia w tym regionie" na stronie trasy.
        $from = '
              FROM known_routes kr
              LEFT JOIN (
                    SELECT krr.route_id,
                           GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name,
                           SUBSTRING_INDEX(GROUP_CONCAT(reg2.code ORDER BY reg2.sort_order SEPARATOR ","), ",", 1) AS code
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id
        ';

        $where = [];
        $params = [];
        if ($szukaj !== '') {
            // Nazwa ALBO slug ALBO region. Slug, bo admin przychodzi tu często
            // z adresu, który mu ktoś podesłał; region, bo „pokaż mi te
            // bieszczadzkie" jest naturalnym pytaniem przy dużym katalogu.
            // Trzy osobne placeholdery — EMULATE_PREPARES jest wyłączone,
            // więc tej samej nazwy nie da się użyć dwa razy.
            $where[] = '(kr.name LIKE :q1 OR kr.slug LIKE :q2 OR reg.name LIKE :q3)';
            $params['q1'] = '%' . $szukaj . '%';
            $params['q2'] = '%' . $szukaj . '%';
            $params['q3'] = '%' . $szukaj . '%';
        }

        $where[] = match ($filtr) {
            'aktywne'   => 'kr.is_active = 1',
            'wylaczone' => 'kr.is_active = 0',
            // „Do przeliczenia" to jedyny filtr, który pokazuje USTERKĘ: trasa
            // z polami bez pozycji wzdłuż śladu nie rysuje się na mapie
            // (migr. 048). Przy dwustu trasach nie da się tego wypatrzeć okiem.
            'do-przeliczenia' => 'EXISTS (SELECT 1 FROM known_route_cells krc
                                           WHERE krc.route_id = kr.id AND krc.sort_order IS NULL)',
            'bez-punktow' => 'kr.bonus_enabled = 0',
            default       => '1 = 1',
        };

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);

        // Sortowanie z zamkniętej listy — nigdy z wejścia. Nazwa domyślnie, bo
        // to jedyny porządek, w którym da się czegoś SZUKAĆ wzrokiem.
        $orderBy = match ($sort) {
            'najnowsze' => 'kr.created_at DESC, kr.id DESC',
            'dystans'   => 'kr.distance_km DESC, kr.name ASC',
            'pola'      => 'kr.cells_total DESC, kr.name ASC',
            // Trasy bez regionu na koniec — pusty region to brak informacji,
            // a nie kategoria, w której coś się szuka.
            'region'    => 'reg.name IS NULL ASC, reg.name ASC, kr.name ASC',
            default     => 'kr.name ASC',
        };

        $db = Database::connection();
        $count = $db->prepare('SELECT COUNT(*) ' . $from . $sqlWhere);
        $count->execute($params);
        $ile = (int) $count->fetchColumn();

        $offset = ($strona - 1) * self::PER_PAGE;
        $stmt = $db->prepare('
            SELECT kr.*, reg.name AS region_label, reg.code AS region_code
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

    /** Liczby do pasków nad listą panelu — jedno zapytanie, nie cztery. */
    public static function counters(): array
    {
        $row = Database::connection()->query('
            SELECT COUNT(*) AS wszystkie,
                   SUM(kr.is_active = 1) AS aktywne,
                   SUM(kr.is_active = 0) AS wylaczone,
                   SUM(EXISTS (SELECT 1 FROM known_route_cells krc
                                WHERE krc.route_id = kr.id AND krc.sort_order IS NULL)) AS do_przeliczenia
              FROM known_routes kr
        ')->fetch() ?: [];

        return [
            'wszystkie'       => (int) ($row['wszystkie'] ?? 0),
            'aktywne'         => (int) ($row['aktywne'] ?? 0),
            'wylaczone'       => (int) ($row['wylaczone'] ?? 0),
            'do_przeliczenia' => (int) ($row['do_przeliczenia'] ?? 0),
        ];
    }

    /** Lista tras do katalogu publicznego i do strony odkryć. */
    /**
     * Identyfikatory aktywnych tras przechodzących przez region
     * (`known_route_regions`, 2026-09-14, strona regionu). Same ID, bo karty
     * budują się z tych samych wierszy co katalog `/trasy` (`all()` albo
     * `progressForUser()`) — druga lista kolumn trasy rozjechałaby się
     * z kartą. Po ID, nie po nazwie regionu: `search()` szuka tekstem
     * i „śląskie" łapie tam też „dolnośląskie".
     *
     * @return int[]
     */
    public static function idsInRegion(int $regionItemId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT krr.route_id
              FROM known_route_regions krr
              JOIN known_routes kr ON kr.id = krr.route_id AND kr.is_active = 1
             WHERE krr.region_item_id = :region
        ');
        $stmt->execute(['region' => $regionItemId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function all(bool $activeOnly = true): array
    {
        // region_code obok region_label, bo formularze tego serwisu posyłają
        // KOD słownikowy, nie id (patrz Dictionary::groupedLeaves) — bez niego
        // ekran edycji nie miałby czym zaznaczyć obecnego regionu.
        $sql = '
            SELECT kr.*, reg.name AS region_label, reg.code AS region_code
              FROM known_routes kr
              LEFT JOIN (
                    SELECT krr.route_id,
                           GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name,
                           SUBSTRING_INDEX(GROUP_CONCAT(reg2.code ORDER BY reg2.sort_order SEPARATOR ","), ",", 1) AS code
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id
        ';
        if ($activeOnly) {
            $sql .= ' WHERE kr.is_active = 1';
        }
        $sql .= ' ORDER BY kr.name ASC';
        return Database::connection()->query($sql)->fetchAll();
    }

    /**
     * Trasa dnia — kandydatka dla strony głównej, gdy żadne nadchodzące
     * wydarzenie się nie kwalifikuje (`Event::firstUpcomingWithElevationProfile()`
     * ma pierwszeństwo — patrz `HomeController::buildRouteOfDay()`, 2026-09-05,
     * prośba usera: „dołożyłbym też znane trasy, rozszerzają one zakres Trasa
     * dnia — nie musi być to tylko event").
     *
     * WYBÓR JEST STABILNY W OBRĘBIE DNIA, nie losowy przy każdym wejściu —
     * „trasa DNIA" ma znaczyć to samo dla każdego, kto trafi tu tego samego
     * dnia. Dzień roku modulo liczba kandydatek rotuje pozycję bez potrzeby
     * zapamiętywania czegokolwiek — ten sam styl „bez własnej tabeli", co
     * Kronika i Puls gdzie indziej w serwisie.
     *
     * Kwalifikuje się trasa AKTYWNA, z plikiem GPX i ZNANYM przewyższeniem
     * (`elevation_gain_m` NULL = trasa sprzed migr. 063, jeszcze
     * nieprzeliczona) — bez tego karta nie miałaby czym wypełnić wiersza
     * metadanych.
     */
    public static function routeOfDay(): ?array
    {
        $rows = Database::connection()->query('
            SELECT kr.id, kr.slug, kr.name, kr.description, kr.cover_photo_url,
                   kr.distance_km, kr.elevation_gain_m,
                   reg.name AS region_label
              FROM known_routes kr
              LEFT JOIN (
                    SELECT krr.route_id,
                           GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id
             WHERE kr.is_active = 1 AND kr.gpx_url IS NOT NULL AND kr.elevation_gain_m IS NOT NULL
               AND kr.distance_km > 0
             ORDER BY kr.id ASC
        ')->fetchAll();

        if (!$rows) {
            return null;
        }

        return $rows[(int) date('z') % count($rows)];
    }

    /**
     * Pola siatki JEDNEJ trasy jako współrzędne osiowe, W KOLEJNOŚCI WZDŁUŻ
     * ŚLADU (migr. 048) — pod „ślad z kafli, bez mapy" na karcie „Trasa dnia"
     * (2026-09-05). Trasa dodana przed migr. 048 (`sort_order` NULL) oddaje
     * pustą listę — lepiej brak wizualizacji niż zygzak po kolejności
     * wstawiania do bazy (ta sama zasada co przy rysowaniu tras na mapie,
     * patrz `onActivity()` wyżej w tym pliku).
     *
     * @return list<array{q:int,r:int}>
     */
    public static function axialCellsInOrder(int $routeId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT cell_q AS q, cell_r AS r
              FROM known_route_cells
             WHERE route_id = :id AND sort_order IS NOT NULL
             ORDER BY sort_order ASC
        ');
        $stmt->execute(['id' => $routeId]);
        return array_map(
            static fn(array $r): array => ['q' => (int) $r['q'], 'r' => (int) $r['r']],
            $stmt->fetchAll()
        );
    }

    /**
     * Trasa po id — z regionem, tak samo jak `findBySlug()` i `search()`.
     * `region_code` (nie samo id) dlatego, że formularze tego serwisu posyłają
     * KOD słownikowy; bez niego ekran edycji nie miałby czym zaznaczyć regionu.
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT kr.*, reg.name AS region_label, reg.code AS region_code
              FROM known_routes kr
              LEFT JOIN (
                    SELECT krr.route_id,
                           GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name,
                           SUBSTRING_INDEX(GROUP_CONCAT(reg2.code ORDER BY reg2.sort_order SEPARATOR ","), ",", 1) AS code
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id
             WHERE kr.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Trasa pod PUBLICZNY adres /trasy/{slug}.
     *
     * Wyłączona trasa daje null tak samo jak nieistniejąca — wyłączenie ma
     * zdejmować szlak z serwisu, a nie tylko z listy.
     */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT kr.*, reg.name AS region_label, reg.code AS region_code
              FROM known_routes kr
              LEFT JOIN (
                    SELECT krr.route_id,
                           GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name,
                           SUBSTRING_INDEX(GROUP_CONCAT(reg2.code ORDER BY reg2.sort_order SEPARATOR ","), ",", 1) AS code
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id
             WHERE kr.slug = :slug AND kr.is_active = 1
        ');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Postęp JEDNEJ osoby na JEDNEJ trasie — `[matched, total, pct, isComplete]`.
     * Ta sama arytmetyka co w `progressForUser()`, tylko bez listy: strona trasy
     * pyta o jeden szlak i nie ma po co liczyć wszystkich.
     */
    /**
     * ZNANE TRASY, PO KTÓRYCH PROWADZIŁ TEN PRZEJAZD (2026-09-03, strona
     * `/przejazd/{id}`) — przecięcie pól trasy z polami przejazdu.
     *
     * Odpowiada na pytanie „czy ja przypadkiem nie jechałem Velo Czorsztyn",
     * czyli na to samo, co warstwa „Znane trasy" pokazuje na mapie — tylko
     * nazwami zamiast kreskami. `pct` liczy się WZGLĘDEM TRASY, nie przejazdu:
     * interesuje nas, ile z niej ten jeden przejazd objął.
     *
     * NIE MYLIĆ Z POSTĘPEM OSOBY (`progressForUserOnRoute`): tamten liczy się
     * ze WSZYSTKICH przejazdów i to on decyduje o punktach. Ten opisuje jeden
     * przejazd i nikomu niczego nie nalicza.
     *
     * @return list<array{id:int,name:string,slug:string,cells_total:int,matched:int,pct:int}>
     */
    public static function onActivity(int $activityId, int $limit = 6): array
    {
        $stmt = Database::connection()->prepare('
            SELECT kr.id, kr.name, kr.slug, kr.cells_total, COUNT(*) AS matched
              FROM known_route_cells krc
              JOIN rider_activity_cells rac ON rac.cell_id = krc.cell_id
                                           AND rac.activity_id = :id
              JOIN known_routes kr ON kr.id = krc.route_id AND kr.is_active = 1
             GROUP BY kr.id, kr.name, kr.slug, kr.cells_total
             ORDER BY matched DESC, kr.name
             LIMIT ' . max(1, $limit) . '
        ');
        $stmt->execute(['id' => $activityId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $total = max(1, (int) $row['cells_total']);
            $out[] = [
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'],
                'slug'        => (string) $row['slug'],
                'cells_total' => (int) $row['cells_total'],
                'matched'     => (int) $row['matched'],
                'pct'         => (int) round(100 * (int) $row['matched'] / $total),
            ];
        }
        return $out;
    }

    public static function progressForUserOnRoute(int $userId, int $routeId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) FROM known_route_cells krc
              JOIN discovery_cells dc ON dc.cell_id = krc.cell_id AND dc.user_id = :user_id
             WHERE krc.route_id = :route_id
        ');
        $stmt->execute(['user_id' => $userId, 'route_id' => $routeId]);
        $matched = (int) $stmt->fetchColumn();

        $stmt = Database::connection()->prepare('SELECT cells_total FROM known_routes WHERE id = :id');
        $stmt->execute(['id' => $routeId]);
        $total = max(1, (int) $stmt->fetchColumn());

        $pct = (int) round(100 * $matched / $total);
        return ['matched' => $matched, 'total' => $total, 'pct' => $pct, 'isComplete' => $pct >= 100];
    }

    /**
     * Przebiegi tras wchodzących w kadr — pod warstwę „Znane trasy" na mapie
     * odkryć.
     *
     * Punkty biorą się ze ŚRODKÓW PÓL trasy, nie z pliku GPX. Trzy powody:
     * pliki leżą na dysku i parsowanie kilkunastu na każde przesunięcie mapy
     * byłoby absurdem; pola i tak są tym, czym trasa jest dla tego modułu;
     * a linia narysowana ze środków pól pokrywa się dokładnie z tym, co trasa
     * zalicza — czyli mówi prawdę o mechanice, a nie tylko o geografii.
     *
     * Kolejność punktów odtwarzamy z `known_route_cells.sort_order`, jeśli
     * kolumna istnieje; bez niej linia byłaby zygzakiem po kolejności wstawiania.
     *
     * ZAWĘŻENIE DO KADRU IDZIE PO WŁASNYCH POLACH TRASY (migr. 061). Do
     * 2026-08-19 szło przez JOIN z `discovery_cell_totals`, czyli przez tabelę
     * pól ODKRYTYCH PRZEZ KOGOKOLWIEK — JOIN miał dostarczyć wyłącznie
     * współrzędnych, a przy okazji działał jak warunek istnienia i **wycinał
     * każdą trasę, której nikt jeszcze nie przejechał**. Świeżo wgrany szlak
     * nie pokazywał się na mapie w ogóle, a to jest dokładnie ten szlak, który
     * ma zapraszać („co jeszcze możesz zaliczyć").
     *
     * @param array{north:float,south:float,east:float,west:float} $bounds
     */
    public static function geometryInBounds(array $bounds, int $maxRoutes = 25): array
    {
        $db = Database::connection();

        // Które trasy w ogóle dotykają kadru — najpierw zawężenie po
        // prostokącie na zdenormalizowanych współrzędnych pól, dopiero potem
        // wyciąganie geometrii. Bez tego przy pełnym oddaleniu ciągnęlibyśmy
        // wszystkie punkty wszystkich tras.
        $range = DiscoveryGrid::axialRangeForBounds(
            $bounds['south'],
            $bounds['west'],
            $bounds['north'],
            $bounds['east'],
            DiscoveryGrid::RES_CELL
        );

        $stmt = $db->prepare('
            SELECT DISTINCT kr.id, kr.slug, kr.name, kr.distance_km, kr.elevation_gain_m
              FROM known_routes kr
              JOIN known_route_cells krc ON krc.route_id = kr.id
             WHERE kr.is_active = 1
               AND krc.sort_order IS NOT NULL
               AND krc.cell_r BETWEEN :r_min AND :r_max
               AND krc.cell_q BETWEEN :q_min AND :q_max
             LIMIT ' . max(1, $maxRoutes)
        );
        $stmt->execute([
            'r_min' => $range['rMin'], 'r_max' => $range['rMax'],
            'q_min' => $range['qMin'], 'q_max' => $range['qMax'],
        ]);
        $routes = $stmt->fetchAll();
        if (!$routes) {
            return [];
        }

        $ids = implode(',', array_map('intval', array_column($routes, 'id')));
        // Kolejność WZDŁUŻ ŚLADU (migr. 048). Trasy dodane wcześniej mają
        // sort_order NULL i wypadają — lepiej nie narysować linii, niż narysować
        // zygzak po kolejności wstawiania do bazy i nazwać go szlakiem.
        $cells = $db->query('
            SELECT route_id, cell_id FROM known_route_cells
             WHERE route_id IN (' . $ids . ') AND sort_order IS NOT NULL
             ORDER BY route_id ASC, sort_order ASC
        ')->fetchAll();

        $pointsByRoute = [];
        foreach ($cells as $c) {
            [$lat, $lon] = DiscoveryGrid::cellCenter((int) $c['cell_id']);
            // Zaokrąglenie do 5 miejsc (~1 m) — dalsza precyzja to same bajty.
            $pointsByRoute[(int) $c['route_id']][] = [round($lat, 5), round($lon, 5)];
        }

        $out = [];
        foreach ($routes as $r) {
            $id = (int) $r['id'];
            if (empty($pointsByRoute[$id])) {
                continue;
            }
            $out[] = [
                'slug'   => $r['slug'],
                'name'   => $r['name'],
                // Liczby do dymka po kliknięciu w szlak (2026-08-19). Idą razem
                // z geometrią, bo to ten sam wiersz i to samo zapytanie —
                // osobny endpoint „opowiedz o tej trasie" byłby drugim
                // żądaniem po dane, które już mamy w ręku.
                // `elevation` bywa null: przewyższenie NIEZNANE (trasa sprzed
                // migr. 063) to co innego niż trasa płaska, więc dymek musi
                // umieć te dwa stany rozróżnić.
                'distanceKm' => (float) $r['distance_km'],
                'elevation'  => $r['elevation_gain_m'] === null ? null : (int) $r['elevation_gain_m'],
                'points' => $pointsByRoute[$id],
            ];
        }
        return $out;
    }

    /**
     * Hashe geometrii WSZYSTKICH aktywnych znanych tras z nazwami — źródło
     * „Znane trasy" w planerze (2026-09-18). Ten sam zbiór co warstwa kafli
     * `kr` (TileSource::tracks('kr'): is_active = 1 i jest plik), tylko z nazwą,
     * której kafle nie potrzebują, a planer tak — pokazuje, po czym jedzie.
     *
     * @return array<string,string> hash => nazwa trasy
     */
    public static function activeGeometryHashes(): array
    {
        return array_map(static fn(array $info): string => $info['name'], self::activeGeometryInfo());
    }

    /**
     * To samo co activeGeometryHashes(), plus % nawierzchni trasy (NULL =
     * nieznane) — warstwa routingu Ridemore dopasowuje po nich korytarz do
     * profilu roweru (szosa / gravel / MTB).
     *
     * @return array<string,array{name:string,asphalt:?int,gravel:?int,trail:?int}> hash => dane
     */
    public static function activeGeometryInfo(): array
    {
        $rows = Database::connection()->query(
            'SELECT name, gpx_url, surface_asphalt_pct, surface_gravel_pct, surface_trail_pct FROM known_routes
              WHERE is_active = 1 AND gpx_url IS NOT NULL
              ORDER BY id ASC'
        )->fetchAll();

        $pct = static fn($v): ?int => $v !== null ? (int) $v : null;
        $out = [];
        foreach ($rows as $r) {
            $hash = GpxGeometry::ensure(TileSource::absolutePath($r['gpx_url']));
            if ($hash !== null && !isset($out[$hash])) {
                $out[$hash] = [
                    'name'    => (string) $r['name'],
                    'asphalt' => $pct($r['surface_asphalt_pct']),
                    'gravel'  => $pct($r['surface_gravel_pct']),
                    'trail'   => $pct($r['surface_trail_pct']),
                ];
            }
        }
        return $out;
    }

    /**
     * ROUTE PLANNER, ETAP 1 — pełna geometria JEDNEJ trasy jako [lat,lon]
     * wzdłuż realnego śladu z pliku GPX. Celowo NIE środki pól jak
     * `geometryInBounds()` (tamto to świadomy kompromis wydajności dla
     * WARSTWY MAPY — dziesiątki tras na raz) — tu chodzi o precyzyjne
     * dopasowanie „czy ta jedna trasa przebiega blisko punktu X", więc
     * potrzebna jest prawdziwa linia, nie zygzak z centrów heksów.
     *
     * Wołane WYŁĄCZNIE dla garstki kandydatów (kilka tras zwróconych przez
     * `geometryInBounds()` dla okolicy planowanej trasy), nigdy dla
     * wszystkich tras w kadrze — koszt jednego rozpakowania pliku jest
     * akceptowalny tylko przy tej skali.
     *
     * @param  array $route wiersz z `find()`/`findBySlug()` (potrzebuje `gpx_url`)
     * @return list<array{0:float,1:float}>
     */
    public static function linePoints(array $route): array
    {
        $gpxUrl = $route['gpx_url'] ?? null;
        if (!$gpxUrl) {
            return [];
        }
        $absolute = TileSource::absolutePath($gpxUrl);
        $hash = GpxGeometry::ensure($absolute);
        if ($hash === null) {
            return [];
        }
        $geom = GpxGeometry::load([$hash])[$hash] ?? null;
        if ($geom === null || empty($geom['pts'])) {
            return [];
        }

        $points = [];
        $pts = $geom['pts'];
        for ($i = 0; $i < count($pts); $i += 2) {
            $points[] = TileGrid::toLatLon((int) $pts[$i], (int) $pts[$i + 1]);
        }
        return $points;
    }

    /**
     * Trasa pod WSKAZANYM PUNKTEM — odpowiedź na kliknięcie w mapę.
     *
     * PO CO OSOBNE TRAFIENIE PO STRONIE SERWERA. Od 2026-08-20 szlaki rysują
     * się kaflami, a kafel to obrazek: nie ma w co kliknąć. Wracając do
     * wektorowej warstwy trafień, cofnęlibyśmy dokładnie to, po co kafle
     * weszły — geometria znowu jechałaby do przeglądarki przy każdym
     * przesunięciu mapy, i to ta ze ŚRODKÓW PÓL, czyli przesunięta względem
     * narysowanej linii (klik w widoczny szlak potrafiłby chybić).
     *
     * Zamiast tego przeglądarka posyła punkt, a serwer sprawdza, czyje POLA
     * są w jego pobliżu. To jest zresztą uczciwsza definicja trafienia w tym
     * module: trasa JEST swoim zbiorem pól — dokładnie tych, które zalicza.
     *
     * TOLERANCJA ZALEŻY OD POWIĘKSZENIA, a nie jest stałą w metrach: przy
     * oddaleniu jeden piksel to setki metrów i wymaganie precyzji byłoby
     * wymaganiem niemożliwego. Bierzemy ok. 12 px przeliczonych na metry
     * (`TileGrid::metersPerPixel`), z dolnym ograniczeniem, żeby przy dużym
     * przybliżeniu dało się jeszcze w cokolwiek trafić.
     *
     * `$viewerId` dokłada POSTĘP tej osoby — dymek ma mówić to samo, co karta
     * trasy w sekcji „Znane trasy" pod mapą, a tamta odpowiada nie tylko „co to
     * za szlak", ale i „ile z niego mam". Dwa ekrany opisujące ten sam byt
     * dwoma różnymi zestawami liczb czytają się jak dwa różne byty.
     *
     * `$onlyRouteId` ZAWĘŻA ODPOWIEDŹ DO JEDNEJ TRASY (2026-09-10) — pod
     * stronę `/trasy/{slug}`, na której warstwa „Znane trasy" rysuje wyłącznie
     * tę jedną trasę. Filtr należy do ZAPYTANIA, nie do wyniku: odsianie po
     * fakcie potrafiłoby oddać pustkę tam, gdzie trasa jest, tylko wypadła
     * z `LIMIT`-u zajętego przez sąsiadów.
     *
     * @return array<int,array{id:int,slug:string,name:string,region:?string,
     *     distanceKm:float,elevation:?int,matched:?int,cellsTotal:int,pct:?int,isComplete:bool}>
     */
    public static function atPoint(
        float $lat,
        float $lon,
        int $zoom,
        ?int $viewerId = null,
        int $limit = 3,
        ?int $onlyRouteId = null
    ): array {
        $tolerancja = max(120.0, TileGrid::metersPerPixel($zoom, $lat) * 12);

        // Prostokąt tolerancji w stopniach — poprawka na południk zbiegający
        // się ku biegunom, ta sama, którą robi każde zapytanie „w promieniu".
        $dLat = $tolerancja / 111320.0;
        $dLon = $tolerancja / max(1.0, 111320.0 * cos(deg2rad($lat)));

        $range = DiscoveryGrid::axialRangeForBounds(
            $lat - $dLat,
            $lon - $dLon,
            $lat + $dLat,
            $lon + $dLon,
            DiscoveryGrid::RES_CELL
        );

        // Filtr po zdenormalizowanych współrzędnych osiowych (migr. 061) —
        // ten sam indeks, z którego korzysta warstwa mapy. Postęp doklejany
        // podzapytaniem tą samą arytmetyką co `progressForUser()`: przecięcie
        // pól trasy z polami widza, liczone na żądanie (postępu nie ma
        // w żadnej tabeli — patrz nota nad klasą).
        $params = [
            'r_min' => $range['rMin'], 'r_max' => $range['rMax'],
            'q_min' => $range['qMin'], 'q_max' => $range['qMax'],
        ];
        $postep = 'NULL AS matched';
        if ($viewerId !== null) {
            $postep = '(SELECT COUNT(*) FROM known_route_cells k
                          JOIN discovery_cells dc ON dc.cell_id = k.cell_id AND dc.user_id = :viewer
                         WHERE k.route_id = kr.id) AS matched';
            $params['viewer'] = $viewerId;
        }

        $tylkoTa = '';
        if ($onlyRouteId !== null) {
            $tylkoTa = ' AND kr.id = :only_route';
            $params['only_route'] = $onlyRouteId;
        }

        $stmt = Database::connection()->prepare('
            SELECT DISTINCT kr.id, kr.slug, kr.name, kr.distance_km, kr.elevation_gain_m,
                   kr.cells_total, kr.color_index, reg.name AS region_label, ' . $postep . '
              FROM known_routes kr
              JOIN known_route_cells krc ON krc.route_id = kr.id
              LEFT JOIN (
                    SELECT krr.route_id, GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id
             WHERE kr.is_active = 1' . $tylkoTa . '
               AND krc.cell_r BETWEEN :r_min AND :r_max
               AND krc.cell_q BETWEEN :q_min AND :q_max
             ORDER BY kr.name ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);

        return array_map(static function (array $r): array {
            $total = max(1, (int) $r['cells_total']);
            $matched = $r['matched'] === null ? null : (int) $r['matched'];
            $pct = $matched === null ? null : (int) round(100 * $matched / $total);

            return [
                'id'         => (int) $r['id'],
                'slug'       => $r['slug'],
                'name'       => $r['name'],
                'region'     => $r['region_label'] ?: null,
                // Kolor linii, którą widać na mapie — dymek musi go powtórzyć,
                // bo to jedyne miejsce, gdzie „ta kreska" spotyka się z nazwą.
                'color'      => self::colorOf($r['color_index']),
                'distanceKm' => (float) $r['distance_km'],
                'elevation'  => $r['elevation_gain_m'] === null ? null : (int) $r['elevation_gain_m'],
                'matched'    => $matched,
                'cellsTotal' => (int) $r['cells_total'],
                'pct'        => $pct,
                'isComplete' => $pct !== null && $pct >= 100,
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Kto ma tę trasę zaliczoną w całości — „nie jesteś tu pierwszy".
     *
     * PRYWATNOŚĆ: wyłącznie osoby widoczne na listach (`roster_visible`, migr.
     * 037) i wyłącznie te z publicznym profilem. Licznik idzie osobno i obejmuje
     * WSZYSTKICH — ukrycie dotyczy tożsamości, nie faktu (ta sama zasada co
     * przy składzie wyjazdu).
     *
     * @return array{people:array<int,array{name:string,public_slug:?string,avatar_url:?string}>, total:int}
     */
    public static function finishersFor(int $routeId, int $limit = 12): array
    {
        $db = Database::connection();

        // Ukończył = ma odkryte WSZYSTKIE pola trasy. Porównujemy liczbę
        // trafionych pól z cells_total zamiast szukać różnicy zbiorów —
        // przy kilkuset polach na trasę to jedno przejście po indeksie.
        $sql = '
            SELECT u.id, u.name, u.email, u.public_slug, u.avatar_url
              FROM users u
              JOIN discovery_cells dc ON dc.user_id = u.id
              JOIN known_route_cells krc ON krc.cell_id = dc.cell_id AND krc.route_id = :route_id
             WHERE %s
             GROUP BY u.id
            HAVING COUNT(DISTINCT krc.cell_id) >= (SELECT cells_total FROM known_routes WHERE id = :route_total)
        ';

        $stmt = $db->prepare(sprintf($sql, 'u.roster_visible = 1 AND u.public_slug IS NOT NULL')
            . ' ORDER BY u.name ASC LIMIT ' . max(1, $limit));
        $stmt->execute(['route_id' => $routeId, 'route_total' => $routeId]);
        $people = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT COUNT(*) FROM (' . sprintf($sql, '1 = 1') . ') t');
        $stmt->execute(['route_id' => $routeId, 'route_total' => $routeId]);
        $total = (int) $stmt->fetchColumn();

        return ['people' => $people, 'total' => $total];
    }

    /**
     * INNE ZNANE TRASY W OKOLICY TEJ JEDNEJ — pod sekcję „W okolicy" na
     * stronie trasy (2026-09-10, prośba usera). Od tej daty warstwa „Znane
     * trasy" na stronie trasy pokazuje WYŁĄCZNIE tę jedną trasę, o której
     * jest strona (patrz `TrailController::show`), więc sąsiedzi, którzy
     * wcześniej rysowali się na mapie jako tło, muszą mieć gdzie się podziać
     * — i lista mówi o nich więcej niż kreska bez nazwy.
     *
     * SĄSIEDZTWO LICZY SIĘ TAK SAMO, JAK PRZY DOBIERANIU KOLORÓW TRAS
     * (`neighbourColors()` wyżej): pole trasy B leży najwyżej
     * `COLOR_NEIGHBOUR_RADIUS` pól od pola trasy A. Ta definicja „w okolicy"
     * jest w serwisie już w użyciu i nie ma powodu, żeby tu znaczyła co innego
     * — z tym samym indeksem `idx_krc_bbox` w tle.
     *
     * `shared` (te SAME pola) sortuje przed `near`: trasa, która się z tą
     * KRZYŻUJE, jest bliżej niż taka, która ją tylko mija.
     *
     * Kształt wiersza celowo taki sam jak w `progressForUser()` — kartę
     * rysuje ten sam partial (`views/web/partials/trail-card.php`).
     */
    public static function nearby(int $routeId, ?int $viewerId = null, int $limit = 6): array
    {
        // Postęp widza doklejany tylko wtedy, gdy jest kogo pytać — gość
        // dostaje same fakty o szlaku, dokładnie jak w `TrailController::index`.
        $params = [
            'id' => $routeId,
            'r1' => self::COLOR_NEIGHBOUR_RADIUS, 'r2' => self::COLOR_NEIGHBOUR_RADIUS,
            'r3' => self::COLOR_NEIGHBOUR_RADIUS, 'r4' => self::COLOR_NEIGHBOUR_RADIUS,
        ];
        $postep = 'NULL AS matched';
        $joinPostep = '';
        if ($viewerId !== null) {
            $postep = 'COALESCE(m.matched, 0) AS matched';
            $joinPostep = '
              LEFT JOIN (
                    SELECT krc.route_id, COUNT(*) AS matched
                      FROM known_route_cells krc
                      JOIN discovery_cells dc
                        ON dc.cell_id = krc.cell_id AND dc.user_id = :user_id
                     GROUP BY krc.route_id
              ) m ON m.route_id = kr.id';
            $params['user_id'] = $viewerId;
        }

        $stmt = Database::connection()->prepare('
            SELECT kr.id, kr.slug, kr.name, kr.distance_km, kr.cells_total,
                   kr.cover_photo_url, kr.color_index,
                   reg.name AS region_label,
                   n.shared_cells, n.near_cells, ' . $postep . '
              FROM (
                    SELECT b.route_id,
                           COUNT(DISTINCT CASE WHEN b.cell_q = a.cell_q AND b.cell_r = a.cell_r
                                               THEN b.cell_id END) AS shared_cells,
                           COUNT(DISTINCT b.cell_id) AS near_cells
                      FROM known_route_cells a
                      JOIN known_route_cells b
                        ON b.route_id <> a.route_id
                       AND b.cell_r BETWEEN a.cell_r - :r1 AND a.cell_r + :r2
                       AND b.cell_q BETWEEN a.cell_q - :r3 AND a.cell_q + :r4
                     WHERE a.route_id = :id
                     GROUP BY b.route_id
              ) n
              JOIN known_routes kr ON kr.id = n.route_id AND kr.is_active = 1
              LEFT JOIN (
                    SELECT krr.route_id, GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id' . $joinPostep . '
             ORDER BY n.shared_cells DESC, n.near_cells DESC, kr.name ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);

        return array_map(static function (array $row): array {
            $total = max(1, (int) $row['cells_total']);
            $row['matched'] = (int) $row['matched'];
            $row['pct'] = (int) round(100 * $row['matched'] / $total);
            $row['isComplete'] = $row['pct'] >= 100;
            // „Krzyżuje się" kontra „biegnie obok" — widok pisze z tego podpis
            // karty, więc rozstrzygnięcie zostaje przy danych, nie w szablonie.
            $row['crosses'] = (int) $row['shared_cells'] > 0;
            return $row;
        }, $stmt->fetchAll());
    }


    /**
     * Postęp osoby na wszystkich aktywnych trasach — pod listę „Znane trasy"
     * na stronie odkryć. Trasy nietknięte też są na liście: to one mówią
     * „co jeszcze możesz zaliczyć" (§42).
     */
    public static function progressForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT kr.id, kr.slug, kr.name, kr.description, kr.distance_km, kr.cells_total,
                   kr.cover_photo_url, kr.color_index, kr.emblem_id,
                   reg.name AS region_label,
                   COALESCE(m.matched, 0) AS matched
              FROM known_routes kr
              LEFT JOIN (
                    SELECT krr.route_id, GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name
                      FROM known_route_regions krr
                      JOIN dictionary_items reg2 ON reg2.id = krr.region_item_id
                     GROUP BY krr.route_id
              ) reg ON reg.route_id = kr.id
              LEFT JOIN (
                    SELECT krc.route_id, COUNT(*) AS matched
                      FROM known_route_cells krc
                      JOIN discovery_cells dc
                        ON dc.cell_id = krc.cell_id AND dc.user_id = :user_id
                     GROUP BY krc.route_id
              ) m ON m.route_id = kr.id
             WHERE kr.is_active = 1
             ORDER BY (COALESCE(m.matched, 0) > 0) DESC, m.matched DESC, kr.name ASC
        ');
        $stmt->execute(['user_id' => $userId]);

        return array_map(function (array $row) {
            $total = max(1, (int) $row['cells_total']);
            $row['pct'] = (int) round(100 * (int) $row['matched'] / $total);
            $row['isComplete'] = $row['pct'] >= 100;
            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * Doprowadza rejestr punktów do zgodności z FAKTYCZNYM pokryciem tras
     * przez tego użytkownika: dopisuje progi osiągnięte, zdejmuje te, które
     * przestały być pokryte.
     *
     * ZASTĄPIŁA awardProgress() z Etapu 8. Tamta porównywała procent sprzed
     * przejazdu z procentem po nim, żeby nie zapłacić drugi raz — i była przez
     * to NIEIDEMPOTENTNA (opisane wtedy jako pułapka: powtórne wywołanie
     * liczyło te same progi ponownie). Od Etapu 8A pilnuje tego klucz unikalny
     * w point_transactions, więc wystarczy policzyć stan OBECNY i przyznać
     * wszystko, co się należy. Powtórzenie jest wtedy bezkosztowe z definicji,
     * a nie „bezpieczne, dopóki nikt nie zawoła tego dwa razy".
     *
     * ZDEJMOWANIE jest tu równie ważne jak przyznawanie: usunięty ślad albo
     * cofnięta obecność potrafią zabrać pola, a bonus za trasę, której się już
     * nie pokrywa, byłby wynikiem bez pokrycia w faktach. Punkty przy
     * przejeździe kasuje kaskada, ale progi trasy nie wiszą na jednym
     * przejeździe — ten sam próg mógł zostać osiągnięty innym wyjazdem.
     *
     * @return array{points:int, routes:array<int,array{name:string, slug:string, pct:int, reached:int[]}>}
     */
    public static function syncProgress(int $userId, ?int $activityId = null, ?string $rideDate = null): array
    {
        // Trasy z wyłączonym bonusem w ogóle nie wchodzą do gry (§8: „czy
        // daje bonus"). LEFT JOIN po polach, bo trasa bez ani jednego
        // wspólnego pola też musi tu trafić — inaczej nie miałby kto zdjąć
        // progów po tym, jak pokrycie spadło do zera.
        $stmt = Database::connection()->prepare('
            SELECT kr.id, kr.name, kr.slug, kr.cells_total, kr.distance_km,
                   kr.bonus_points, kr.completion_bonus,
                   COUNT(dc.cell_id) AS matched,
                   -- CZY TEN PRZEJAZD RUSZYŁ TĘ TRASĘ (Etap 1a, 2026-09-11).
                   -- Bez tego ekran wyniku nie umiał odróżnić „zbliżyłeś się
                   -- dzisiaj" od „masz to od miesiąca" — a to jest różnica
                   -- między zdarzeniem a stanem, czyli cała zasada tego
                   -- programu. `:activity_id` bywa NULL (przeliczenie bez
                   -- przejazdu) i wtedy warunek jest po prostu fałszem.
                   EXISTS (
                       SELECT 1 FROM rider_activity_cells rac
                        WHERE rac.activity_id = :activity_id
                          AND rac.cell_id IN (
                              SELECT k4.cell_id FROM known_route_cells k4 WHERE k4.route_id = kr.id
                          )
                   ) AS touched_now
              FROM known_routes kr
              LEFT JOIN known_route_cells krc ON krc.route_id = kr.id
              LEFT JOIN discovery_cells dc
                     ON dc.cell_id = krc.cell_id AND dc.user_id = :user_id
             WHERE kr.is_active = 1 AND kr.bonus_enabled = 1
             GROUP BY kr.id, kr.name, kr.slug, kr.cells_total, kr.distance_km, kr.bonus_points, kr.completion_bonus
        ');
        $stmt->execute(['user_id' => $userId, 'activity_id' => $activityId]);

        $points = 0;
        $routes = [];
        foreach ($stmt->fetchAll() as $row) {
            $routeId = (int) $row['id'];
            $total = max(1, (int) $row['cells_total']);
            $pct = 100 * (int) $row['matched'] / $total;

            // Wartość trasy: nadpisanie z panelu, a w jego braku sugestia
            // z długości — nie płaska wartość domyślna (patrz uzasadnienie
            // przy DiscoveryScoring::trailValueFor()).
            $override = DiscoveryScoring::legacyRouteOverrideTotal(
                $row['bonus_points'] === null ? null : (int) $row['bonus_points'],
                $row['completion_bonus'] === null ? null : (int) $row['completion_bonus']
            );
            $routeValue = DiscoveryScoring::trailValueFor((float) $row['distance_km'], $override);
            $awards = DiscoveryScoring::trailAwards($pct, $routeValue['total']);

            $reached = [];
            foreach ($awards as $threshold => $value) {
                // Ukończenie to osobne źródło, nie kolejny próg — brief (§2)
                // wyraźnie je rozdziela, a osobna nazwa pozwala pokazać
                // „Ukończona trasa" zamiast „Znana trasa 100%".
                $source = $threshold >= 100
                    ? PointLedger::SOURCE_TRAIL_COMPLETION
                    : PointLedger::SOURCE_TRAIL_THRESHOLD;
                $sourceId = $threshold >= 100 ? (string) $routeId : $routeId . ':' . $threshold;

                if (PointLedger::award(
                    $userId, $source, $sourceId, $value, $activityId, $rideDate,
                    $row['name'] . ($threshold >= 100 ? ' — ukończona' : ' — ' . $threshold . __('% trasy'))
                )) {
                    $points += $value;
                    $reached[] = $threshold;
                }
            }

            // Progi, których już nie ma — zdejmujemy niezależnie od tego, czy
            // kiedykolwiek zostały przyznane (revoke kasuje tylko istniejące).
            $lost = [];
            $lostCompletion = [];
            foreach (DiscoveryScoring::trailThresholds() as $threshold) {
                if (isset($awards[$threshold])) {
                    continue;
                }
                if ($threshold >= 100) {
                    $lostCompletion[] = (string) $routeId;
                } else {
                    $lost[] = $routeId . ':' . $threshold;
                }
            }
            PointLedger::revoke($userId, PointLedger::SOURCE_TRAIL_THRESHOLD, $lost);
            PointLedger::revoke($userId, PointLedger::SOURCE_TRAIL_COMPLETION, $lostCompletion);

            // EKRAN WYNIKU JAZDY POKAZUJE TEŻ „ZBLIŻYŁEŚ SIĘ" (Etap 1a, 2026-09-11):
            // trasa trafia na listę także wtedy, gdy TEN przejazd przesunął
            // postęp, choć żadnego progu jeszcze nie przebił. Wcześniej taki
            // przejazd nie zostawiał po sobie ani słowa — a to jest moment
            // uwagi, w którym zachęta nic nie kosztuje i nikomu nie przerywa
            // dnia, bo człowiek sam patrzy na wynik.
            //
            // `brakuje` to LICZBA PÓL, nie procent: „zostały 3 pola" mówi coś
            // o wysiłku, „97%" nie mówi nic.
            if ($reached || (!empty($row['touched_now']) && $pct > 0 && $pct < 100)) {
                $routes[] = [
                    'name'    => $row['name'],
                    'slug'    => $row['slug'],
                    'pct'     => (int) round($pct),
                    'reached' => $reached,
                    'brakuje' => max(0, $total - (int) $row['matched']),
                    'ruszona' => !empty($row['touched_now']),
                ];
            }
        }

        return ['points' => $points, 'routes' => $routes];
    }

    /**
     * Dodanie trasy z pliku GPX. Pola liczone tą samą funkcją co przejazdy —
     * gdyby trasa i przejazd używały różnych siatek, postęp nigdy nie doszedłby
     * do 100%.
     *
     * @return array{id:int, cells:int, distanceKm:float}
     */
    /**
     * Ilu rowerzystów TKNĘŁO tę trasę i ilu ją DOMKNĘŁO (2026-09-12, przebudowa
     * ekranu edycji).
     *
     * Panel edycji pokazywał do tej daty wyłącznie fakty o PLIKU (dystans,
     * liczba pól) — a pierwsze pytanie przy każdej decyzji o trasie brzmi
     * „czy ktokolwiek nią jeździ". Bez tej liczby wyłączenie albo usunięcie
     * trasy jest strzałem w ciemno.
     *
     * JEDNO ZAPYTANIE, nie dwa: „zaczął" i „ukończył" to ta sama agregacja
     * z różnym progiem, więc liczymy ją raz i rozstrzygamy w SELECT-cie.
     * `cells_total` bierzemy z wiersza trasy (to samo źródło, którym posługuje
     * się `Emblem::sync`), a nie z `COUNT(known_route_cells)` — inaczej trasa
     * w trakcie przeliczania potrafiłaby pokazać „ukończyło" większe niż
     * „zaczęło".
     *
     * @return array{started:int, completed:int}
     */
    public static function ridersProgress(int $routeId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) AS zaczelo,
                   COALESCE(SUM(t.mam >= t.total), 0) AS ukonczylo
              FROM (
                    SELECT dc.user_id,
                           COUNT(DISTINCT dc.cell_id) AS mam,
                           (SELECT kr.cells_total FROM known_routes kr WHERE kr.id = :route_a) AS total
                      FROM discovery_cells dc
                      JOIN known_route_cells krc ON krc.cell_id = dc.cell_id AND krc.route_id = :route_b
                     GROUP BY dc.user_id
                   ) t
        ');
        // Ten sam identyfikator dwa razy pod dwiema nazwami — EMULATE_PREPARES
        // jest wyłączone, więc nazwanego placeholdera nie wolno powtórzyć.
        $stmt->execute(['route_a' => $routeId, 'route_b' => $routeId]);
        $row = $stmt->fetch() ?: [];

        return [
            'started'   => (int) ($row['zaczelo'] ?? 0),
            'completed' => (int) ($row['ukonczylo'] ?? 0),
        ];
    }

    /**
     * NAWIERZCHNIA Z PRZEBIEGU — asfalt / gravel / ścieżka w procentach długości
     * (migr. 086, prośba usera 2026-09-11: „podczas uploadu powinna się również
     * pobierać nawierzchnia, tak jak przy dodawaniu eventu").
     *
     * ISTNIEJĄCY DETEKTOR, nie nowy: `Utils\RoadSurfaceDetector` to ten sam
     * kod, którym liczy nawierzchnię `/api/gpx/parse` w kreatorze wydarzenia.
     * Znana trasa jest dla niego tym samym bytem co etap — jednym plikiem GPX —
     * więc nie ma tu czego pisać od nowa, jest tylko drugie wywołanie.
     *
     * NIE MOŻE PRZEWRÓCIĆ ZAPISU TRASY. Detektor chodzi do Overpass API: bywa
     * niedostępny, ma limity i nie pokrywa każdej drogi. Trasa ma powstać także
     * wtedy — a nawierzchnia jest dodatkiem do niej, nie warunkiem. Stąd
     * `catch (\Throwable)` i trzy NULL-e jako prawidłowy wynik „nie wiadomo".
     *
     * WOŁANA Z WARSTWY HTTP (KnownRouteController) I Z CLI (backfill), NIE
     * STĄD — i to jest istotne. Overpass ma budżet 70 s na trasę
     * (MAX_ANALYSIS_SECONDS), a `createFromGpx()` jest też jedyną drogą
     * dodania trasy w testach: gdyby detekcja siedziała w środku zapisu, każdy
     * test tworzący trasę czekałby na zewnętrzny serwis (złapane żywym
     * uruchomieniem `tests/run.php` — zestaw przestał się kończyć). To ten sam
     * podział, który od początku obowiązuje przy wydarzeniu: Overpass odpytuje
     * `/api/gpx/parse` z podniesionym limitem czasu, a model dostaje gotowe
     * procenty.
     *
     * @return array{0:?int,1:?int,2:?int} asfalt, gravel, ścieżka (procenty)
     */
    public static function detectSurface(array $points, float $distanceKm): array
    {
        try {
            $surface = RoadSurfaceDetector::analyze($points, $distanceKm);
        } catch (\Throwable $e) {
            error_log('KnownRoute: detekcja nawierzchni nieudana — ' . $e->getMessage());
            return [null, null, null];
        }
        if (!is_array($surface) || !isset($surface['asphaltPct'])) {
            return [null, null, null];
        }
        return [
            (int) $surface['asphaltPct'],
            (int) $surface['gravelPct'],
            (int) $surface['trailPct'],
        ];
    }

    /**
     * @param ?array{0:?int,1:?int,2:?int} $surface procenty nawierzchni
     *        (asfalt, gravel, ścieżka) z `detectSurface()`. null = nie liczono
     *        albo się nie powiodło; kolumny zostają puste, a strona trasy po
     *        prostu nie pokazuje paska. Podaje je WOŁAJĄCY — patrz nota przy
     *        `detectSurface()`, dlaczego nie liczymy ich tutaj.
     */
    public static function createFromGpx(
        string $name,
        ?string $description,
        string $gpxPath,
        string $gpxUrl,
        ?string $coverPhotoUrl = null,
        ?array $surface = null,
        ?int $emblemId = null
    ): array {
        $parsed = Gpx::parse($gpxPath, Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
        $cells = DiscoveryGrid::cellsForTrack($parsed['points']);

        $db = Database::connection();
        $slug = Format::uniqueSlug($name, 'trasa', function (string $candidate) use ($db) {
            $stmt = $db->prepare('SELECT 1 FROM known_routes WHERE slug = :slug');
            $stmt->execute(['slug' => $candidate]);
            return $stmt->fetchColumn() !== false;
        });

        [$asfalt, $gravel, $sciezka] = $surface ?? [null, null, null];

        $stmt = $db->prepare('
            INSERT INTO known_routes
                (slug, name, description, gpx_url, cover_photo_url,
                 distance_km, elevation_gain_m, elevation_profile, cells_total,
                 surface_asphalt_pct, surface_gravel_pct, surface_trail_pct, emblem_id)
            VALUES (:slug, :name, :description, :gpx_url, :cover,
                    :distance, :elevation, :profile, :cells,
                    :asfalt, :gravel, :sciezka, :emblem)
        ');
        $stmt->execute([
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'gpx_url'     => $gpxUrl,
            'cover'       => $coverPhotoUrl,
            'distance'    => $parsed['distanceKm'],
            // Profil wysokości (migr. 065) — ten sam kształt i to samo źródło
            // co w etapach wydarzeń: ok. 50 próbek z `Gpx::sampleProfile()`.
            // Szczyty liczą się z niego NA ŻĄDANIE, więc nie ma tu drugiej
            // kolumny na to samo.
            'profile'     => self::encodeProfile($parsed['elevationProfile'] ?? null),
            // Przewyższenie (migr. 063) liczy i tak `Gpx::parse()` przy każdym
            // wgraniu — do tej pory wynik był wyrzucany, bo nie miał gdzie
            // wylądować. Dymek trasy na mapie właśnie o to pyta.
            'elevation'   => (int) $parsed['elevationGainM'],
            'cells'       => count($cells),
            'asfalt'      => $asfalt,
            'gravel'      => $gravel,
            'sciezka'     => $sciezka,
            'emblem'      => $emblemId,
        ]);
        $routeId = (int) $db->lastInsertId();

        self::writeCells($routeId, $cells);
        self::syncRegions($routeId);

        $rewarded = self::awardBacklog($routeId, count($cells));

        // KOLOR PO POLACH, NIE PRZED (migr. 064). Dobór patrzy na to, co leży
        // w pobliżu, a „w pobliżu" liczy się na polach trasy — więc musi paść
        // po `writeCells()`, inaczej każda nowa trasa wyglądałaby jak samotna
        // i dostawała ten sam kolor.
        self::assignColor($routeId);

        // Nowa trasa wchodzi do warstwy „Trasy" (klucz kafli `kr`) — kafle na
        // jej przebiegu muszą się odbudować, inaczej pojawi się dopiero komuś,
        // kto nigdy tam nie zaglądał.
        self::invalidateTiles($gpxUrl, $routeId);

        return [
            'id'          => $routeId,
            'cells'       => count($cells),
            'distanceKm'  => (float) $parsed['distanceKm'],
            'rewarded'    => $rewarded,
        ];
    }

    /**
     * Podmiana PRZEBIEGU istniejącej trasy — poprawka źle wgranego pliku bez
     * kasowania szlaku.
     *
     * DLACZEGO NIE „usuń i dodaj od nowa": nowa trasa to nowe id, a progi
     * zapisane w `point_transactions` pod `trasa:próg` wskazywałyby wtedy na
     * byt, którego już nie ma — wszyscy uczestnicy straciliby postęp, a adres
     * `/trasy/{slug}` przestałby działać. Tutaj id i slug zostają, zmienia się
     * wyłącznie zbiór pól, dystans i plik.
     *
     * PUNKTY DOPROWADZANE DO ZGODNOŚCI W OBIE STRONY. Zbiór pól po podmianie
     * jest inny, więc czyjeś pokrycie może zarówno urosnąć (nowy próg do
     * przyznania), jak i spaść (próg do zdjęcia). Dlatego osoby liczymy PRZED
     * i PO wymianie pól i bierzemy sumę tych dwóch zbiorów: ktoś, kto miał
     * pola starego przebiegu, a nie ma ani jednego nowego, nie wyszedłby
     * z zapytania „po" — i zostałby z progiem bez pokrycia w faktach.
     *
     * @return array{cells:int, distanceKm:float, resynced:int}
     */
    /** @param ?array{0:?int,1:?int,2:?int} $surface — jak w createFromGpx(). */
    public static function replaceGpx(int $routeId, string $gpxPath, string $gpxUrl, ?array $surface = null): array
    {
        $parsed = Gpx::parse($gpxPath, Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
        $cells = DiscoveryGrid::cellsForTrack($parsed['points']);
        if (!$cells) {
            throw new \RuntimeException(__('Plik GPX nie dał ani jednego pola trasy.'));
        }

        $db = Database::connection();
        $before = self::ridersTouchingRoute($routeId);
        // Stary plik trzeba unieważnić TAK SAMO jak nowy: bez tego kafle
        // dalej rysowałyby poprzedni przebieg tam, gdzie tylko on przechodził.
        $poprzedniGpx = self::gpxUrlOf($routeId);

        $db->prepare('DELETE FROM known_route_cells WHERE route_id = :id')->execute(['id' => $routeId]);
        self::writeCells($routeId, $cells);
        self::syncRegions($routeId);

        // Nawierzchnia MUSI iść razem z plikiem — z tego samego powodu co profil
        // wysokości niżej: zostawiona stara opisywałaby przebieg, którego już nie
        // ma. Nieudana (albo niepoliczona) detekcja nadpisuje NULL-ami i tak jest
        // dobrze: „nie wiadomo" jest uczciwsze niż procenty z poprzedniego śladu.
        [$asfalt, $gravel, $sciezka] = $surface ?? [null, null, null];

        $db->prepare('
            UPDATE known_routes
               SET gpx_url = :gpx_url, distance_km = :distance,
                   elevation_gain_m = :elevation, elevation_profile = :profile,
                   cells_total = :cells,
                   surface_asphalt_pct = :asfalt, surface_gravel_pct = :gravel,
                   surface_trail_pct = :sciezka
             WHERE id = :id
        ')->execute([
            'gpx_url'   => $gpxUrl,
            'distance'  => $parsed['distanceKm'],
            'elevation' => (int) $parsed['elevationGainM'],
            // Profil MUSI iść razem z plikiem (migr. 065): zostawiony stary
            // pokazywałby podjazdy z przebiegu, którego już nie ma.
            'profile'   => self::encodeProfile($parsed['elevationProfile'] ?? null),
            'cells'     => count($cells),
            'asfalt'    => $asfalt,
            'gravel'    => $gravel,
            'sciezka'   => $sciezka,
            'id'        => $routeId,
        ]);

        // Nowy przebieg = nowe sąsiedztwo, więc kolor dobieramy od nowa. Wolno
        // to zrobić WYŁĄCZNIE dlatego, że zaraz potem lecą oba unieważnienia:
        // kafle na starym i na nowym śladzie. Przemalowanie trasy bez skasowania
        // jej kafli zostawiłoby ją w dwóch kolorach naraz.
        self::assignColor($routeId);

        self::invalidateTiles($poprzedniGpx, $routeId);
        self::invalidateTiles($gpxUrl, $routeId);

        // Suma zbiorów: klucze z „po" mają pierwszeństwo (świeższy przejazd),
        // ale nikt z „przed" nie może wypaść — patrz nota wyżej.
        $riders = self::ridersTouchingRoute($routeId) + $before;
        foreach ($riders as $userId => $activityId) {
            self::syncProgress((int) $userId, $activityId);
        }

        return [
            'cells'      => count($cells),
            'distanceKm' => (float) $parsed['distanceKm'],
            'resynced'   => count($riders),
        ];
    }

    /**
     * Zmiana danych opisowych trasy. Whitelist kolumn siedzi w self::EDITABLE
     * — pola wyprowadzane z pliku GPX i slug są z niej świadomie wyłączone
     * (powody przy stałej).
     *
     * @param array<string,mixed> $fields
     */
    public static function update(int $id, array $fields): void
    {
        $set = [];
        $params = ['id' => $id];
        foreach (self::EDITABLE as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = $column . ' = :' . $column;
                $params[$column] = $fields[$column];
            }
        }
        if (!$set) {
            return;
        }

        Database::connection()
            ->prepare('UPDATE known_routes SET ' . implode(', ', $set) . ' WHERE id = :id')
            ->execute($params);
    }

    /**
     * Ile pól trasy nie ma jeszcze pozycji wzdłuż śladu — per trasa, jednym
     * zapytaniem, pod ostrzeżenie w panelu.
     *
     * Trasy dodane przed migracją 048 mają `sort_order` NULL i warstwa mapy je
     * pomija (lepiej nie narysować linii, niż narysować zygzak po kolejności
     * wstawiania). Bez tej liczby admin nie miał jak się dowiedzieć, dlaczego
     * jego trasa nie rysuje się na mapie — naprawą jest „Przelicz pola".
     *
     * `$routeIds` zawęża rachunek do tras z WIDOCZNEJ STRONY listy — przy
     * dwustu trasach nie ma powodu liczyć tego dla wszystkich, skoro na ekranie
     * jest dwadzieścia pięć.
     *
     * @param int[] $routeIds pusta tablica = wszystkie trasy
     * @return array<int,int> route_id => liczba pól bez kolejności
     */
    public static function unorderedCounts(array $routeIds = []): array
    {
        $only = $routeIds === []
            ? ''
            : ' AND route_id IN (' . implode(',', array_map('intval', $routeIds)) . ')';

        $rows = Database::connection()->query('
            SELECT route_id, COUNT(*) AS missing
              FROM known_route_cells
             WHERE sort_order IS NULL' . $only . '
             GROUP BY route_id
        ')->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['route_id']] = (int) $row['missing'];
        }
        return $out;
    }

    /**
     * Zapis pól trasy — JEDYNE miejsce, które wstawia wiersze do
     * known_route_cells (dodanie trasy i podmiana przebiegu).
     *
     * Trzy rzeczy naraz, i wszystkie trzy muszą tu być razem:
     *  - `sort_order` (migr. 048) — pozycja pola WZDŁUŻ ŚLADU. cellsForTrack()
     *    zwraca pola w kolejności przebiegu; do liczenia postępu ta kolejność
     *    jest zbędna (postęp to przecięcie zbiorów), ale warstwa mapy łączy
     *    środki pól w linię i bez niej byłby to zygzak.
     *  - `cell_q`/`cell_r` (migr. 061) — współrzędne osiowe rozpakowane
     *    z identyfikatora, po nich idzie filtr kadru mapy. Rozjazd między
     *    zapisem a filtrem oznacza trasę niewidoczną na mapie, więc jedno
     *    miejsce zapisu jest tu warte więcej niż oszczędność kilku linii.
     *
     * @param int[] $cells identyfikatory pól W KOLEJNOŚCI ŚLADU
     */
    private static function writeCells(int $routeId, array $cells): void
    {
        $db = Database::connection();
        $order = 0;
        foreach (array_chunk($cells, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $cellId) {
                [, $q, $r] = DiscoveryGrid::decode((int) $cellId);
                $values[] = '(' . $routeId . ',' . (int) $cellId . ',' . $q . ',' . $r . ',' . $order++ . ')';
            }
            $db->exec(
                'INSERT IGNORE INTO known_route_cells (route_id, cell_id, cell_q, cell_r, sort_order) VALUES '
                . implode(',', $values)
            );
        }
    }

    /**
     * Regiony trasy WYPROWADZONE z jej własnego przebiegu, nie wybrane ręcznie
     * (migr. 074) — złączenie pól trasy z `region_cells` (migr. 070, heks →
     * województwo). Trasa przecinająca granicę województw dostaje więcej niż
     * jeden wiersz, co jest tu normą, w odróżnieniu od `region_cells`, gdzie
     * jeden heks ma dokładnie jeden region.
     *
     * Wołane PO `writeCells()`, bo czyta świeżo zapisane `known_route_cells`.
     * Usuwa i wstawia od nowa — trasa nie ma zbyt wielu regionów, żeby to
     * bolało, a unika ręcznego liczenia różnicy zbiorów.
     */
    private static function syncRegions(int $routeId): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM known_route_regions WHERE route_id = :id')->execute(['id' => $routeId]);
        $db->prepare('
            INSERT IGNORE INTO known_route_regions (route_id, region_item_id)
            SELECT DISTINCT :id1, rc.region_item_id
              FROM known_route_cells krc
              JOIN region_cells rc ON rc.cell_id = krc.cell_id
             WHERE krc.route_id = :id2
        ')->execute(['id1' => $routeId, 'id2' => $routeId]);
    }

    /**
     * Kto ma choć jedno pole tej trasy i przez który przejazd — pod przeliczenie
     * punktów. Punkty wiążemy z NAJPÓŹNIEJSZYM przejazdem, który dotknął trasy:
     * to ten, którym dana osoba faktycznie doszła do swojego wyniku, więc
     * niezmiennik „punkty mieszkają przy przejeździe" zostaje nienaruszony.
     *
     * @return array<int,int> user_id => activity_id
     */
    private static function ridersTouchingRoute(int $routeId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT dc.user_id, MAX(dc.activity_id) AS last_activity
              FROM known_route_cells krc
              JOIN discovery_cells dc ON dc.cell_id = krc.cell_id
             WHERE krc.route_id = :route_id
               AND dc.activity_id IS NOT NULL
             GROUP BY dc.user_id
        ');
        $stmt->execute(['route_id' => $routeId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['user_id']] = (int) $row['last_activity'];
        }
        return $out;
    }

    /**
     * Punkty dla tych, którzy przejechali trasę ZANIM ktokolwiek dodał ją do
     * serwisu. Wisi wewnątrz createFromGpx(), a nie w kontrolerze, żeby nie
     * dało się o tym zapomnieć przy kolejnym sposobie dodawania tras.
     *
     * Bez tego pierwsza partia dodanych szlaków byłaby dla obecnych
     * użytkowników bezwartościowa i — co gorsza — wewnętrznie sprzeczna:
     * strona pokazywałaby „Velo Czorsztyn 100%" obok zera punktów za trasy,
     * bo procent jest wyprowadzany z faktów, a punkty naliczane w chwili
     * przekroczenia progu.
     *
     * Punkty dopisujemy do NAJPÓŹNIEJSZEGO przejazdu, który dotknął tej trasy
     * — to ten, którym dana osoba faktycznie doszła do swojego wyniku, więc
     * niezmiennik „punkty mieszkają przy przejeździe" zostaje nienaruszony.
     *
     * @return int ilu osobom coś przyznano
     */
    private static function awardBacklog(int $routeId, int $cellsTotal): int
    {
        if ($cellsTotal < 1) {
            return 0;
        }

        // Kto ma choć jedno pole tej trasy — ridersTouchingRoute(), to samo
        // zapytanie, którego używa podmiana przebiegu. Reszta roboty należy do
        // syncProgress(): liczy pokrycie, przyznaje osiągnięte progi i wiąże
        // je z ostatnim przejazdem, który dotknął tej trasy. Dawniej ta metoda
        // miała własną kopię arytmetyki progów i własny UPDATE kolumny —
        // dwie implementacje tej samej reguły, które musiały pozostać zgodne.
        $rewarded = 0;
        foreach (self::ridersTouchingRoute($routeId) as $userId => $activityId) {
            $result = self::syncProgress((int) $userId, (int) $activityId);
            if ($result['points'] > 0) {
                $rewarded++;
            }
        }
        return $rewarded;
    }

    // USUNIĘTE 2026-08-19: setCoverPhoto() — zdjęcie jest zwykłym polem
    // formularza edycji i idzie tą samą drogą co nazwa czy opis, przez
    // update(). Osobna metoda i osobna akcja HTTP miały sens, gdy panel nie
    // umiał edytować trasy w ogóle (migr. 046: trasy sprzed niej nie miały jak
    // dostać fotografii). Dwie ścieżki zapisu tej samej kolumny nie mają.

    public static function setActive(int $id, bool $active): void
    {
        Database::connection()
            ->prepare('UPDATE known_routes SET is_active = :active WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'id' => $id]);

        // Warstwa „Trasy" pokazuje WYŁĄCZNIE aktywne (klucz `kr`), więc
        // przełącznik zmienia obraz kafli tak samo jak podmiana pliku.
        self::invalidateTiles(self::gpxUrlOf($id), $id);
    }

    public static function delete(int $id): void
    {
        // Hash liczymy PRZED skasowaniem wiersza — po nim nie ma z czego.
        $gpxUrl = self::gpxUrlOf($id);
        Database::connection()->prepare('DELETE FROM known_routes WHERE id = :id')->execute(['id' => $id]);
        self::invalidateTiles($gpxUrl, $id);
    }

    /**
     * Kafle warstwy „Trasy" do odbudowy — po każdej zmianie, która zmienia to,
     * co ta warstwa rysuje.
     *
     * DO 2026-08-20 NIE BYŁO TEGO WCALE i nie bolało, bo trasy szły na mapę
     * wektorowo (świeże przy każdym przesunięciu). Odkąd rysują się kaflami,
     * brak unieważnienia znaczy „zmiana nie dociera do nikogo, kto ma kafel
     * w cache'u" — czyli praktycznie do nikogo.
     *
     * `invalidateTrack` kasuje tylko te kafle, przez które ten ślad
     * przechodzi, i podbija epokę (`?v=` w adresie), więc przeglądarki
     * przestają pokazywać stare. Reszta kafli zostaje nietknięta.
     *
     * Klucz `kr-{id}` leci razem z `kr` mimo że dziś nikt go nie żąda: jest
     * wspierany przez TileSource, a kafel zbudowany kiedyś pod nim zostałby
     * nieodświeżony na zawsze.
     *
     * `kd-{slug}`/`kn-{slug}` DOCHODZĄ TU RAZEM Z `kr` (Etap 3 warstw mapy,
     * 2026-08-26, i migr. 078, 2026-08-29 — `tasks/done/warstwy-mapy.md) —
     * geometria/`cells_total` tej trasy mogły się zmienić, więc ktoś, kto był
     * „ukończył" mógł nim przestać być (i odwrotnie). Zawężone do osób, które
     * MAJĄ tu choć jedno odkryte pole (`known_route_cells ∩ discovery_cells`)
     * — tylko im ta zmiana mogła ruszyć wynik; reszta serwisu i tak nie ma tej
     * trasy na swoim kaflu (ANI na `kd-`, ANI na `kn-`, obu naraz).
     *
     * ŚWIADOMA LUKA, tej samej rodziny co przy `u-{slug}` (patrz
     * `TileCache::invalidateForEdition`): NOWA JAZDA, która dopiero DOPROWADZA
     * trasę do 100%, nie odświeża tu niczego — kafel geograficznie już
     * pokryty wcześniejszą jazdą zostaje bez zmian, dopóki coś innego go nie
     * tknie (edycja trasy, jak tutaj, albo naturalne wygaśnięcie z przycinania
     * `MAX_TILES`). Naprawa wymagałaby unieważniania przy KAŻDEJ jeździe, co
     * jest kosztem nieproporcjonalnym do tego zadania — zostawione jako znany,
     * opisany gap, nie cichy błąd.
     */
    private static function invalidateTiles(?string $gpxUrl, int $routeId): void
    {
        if ($gpxUrl === null || $gpxUrl === '') {
            return;
        }
        $hash = GpxGeometry::ensure(TileSource::absolutePath($gpxUrl));
        if ($hash === null) {
            return;
        }
        $keys = ['kr', 'kr-' . $routeId];
        $stmt = Database::connection()->prepare('
            SELECT DISTINCT u.public_slug
              FROM known_route_cells krc
              JOIN discovery_cells dc ON dc.cell_id = krc.cell_id
              JOIN users u ON u.id = dc.user_id
             WHERE krc.route_id = :id AND u.public_slug IS NOT NULL
        ');
        $stmt->execute(['id' => $routeId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $slug) {
            $keys[] = 'kd-' . $slug;
            // `kn-{slug}` (migr. 078) DOKŁADNE LUSTRO `kd-{slug}` — ta sama
            // zmiana, która mogła kogoś dopisać do „ukończonych", mogła też
            // zdjąć go z „nieukończonych", więc kasujemy oba klucze naraz.
            $keys[] = 'kn-' . $slug;
        }
        TileCache::invalidateTrack($hash, $keys);
    }

    /** Profil wysokości do zapisu — pusty jest NULL-em, nie „[]" (migr. 065). */
    private static function encodeProfile(?array $profile): ?string
    {
        return $profile ? json_encode($profile) : null;
    }

    /**
     * Profil wysokości trasy gotowy dla widoku: próbki + policzone z nich
     * szczyty, tak jak `StageResource` podaje je dla etapu wydarzenia.
     *
     * SZCZYTY LICZONE NA ŻĄDANIE, nie trzymane w bazie — to jest ta sama
     * decyzja co przy etapach: `detectPeaks()` jest czystą funkcją tych samych
     * 50 próbek, więc druga kolumna byłaby drugą kopią tej samej prawdy, którą
     * dałoby się rozjechać podmianą pliku.
     *
     * @return array{profile:array,peaks:array}|null null, gdy trasa nie ma profilu
     */
    public static function elevationProfile(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $profile = json_decode($json, true);
        if (!is_array($profile) || count($profile) < 2) {
            return null;
        }
        $peaks = array_map(static function (array $peak): array {
            $peak['categoryColor'] = $peak['category'] !== null
                ? \Utils\ClimbCategory::color($peak['category'])
                : null;
            return $peak;
        }, Gpx::detectPeaks($profile));

        return ['profile' => $profile, 'peaks' => $peaks];
    }

    private static function gpxUrlOf(int $id): ?string
    {
        $stmt = Database::connection()->prepare('SELECT gpx_url FROM known_routes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $url = $stmt->fetchColumn();
        return $url === false || $url === null ? null : (string) $url;
    }

    /**
     * Dopisanie przewyższenia trasom, które dostały je dopiero migracją 063.
     *
     * Parsuje PLIK, który trasa już ma na dysku — ten sam, z którego liczyły
     * się jej pola. Nie rusza niczego poza `elevation_gain_m`: zbiór pól,
     * dystans i postęp uczestników zostają nietknięte (do przeliczenia pól
     * służy `replaceGpx()`, a to jest zupełnie inna operacja).
     *
     * Wołane z `run_migrations.php` zaraz po migracji — tak samo jak backfill
     * slugów organizatorów po migracji 004. Osobny skrypt byłby kolejnym
     * krokiem wdrożenia, o którym da się zapomnieć, a wtedy każda istniejąca
     * trasa pokazywałaby w dymku „brak danych" mimo posiadanego pliku.
     *
     * @param callable|null $log wywoływane z komunikatem na każdą trasę
     * @return array{updated:int, skipped:int}
     */
    public static function backfillElevation(?callable $log = null): array
    {
        $db = Database::connection();

        // KOLUMNA PROFILU (migr. 065) MOŻE JESZCZE NIE ISTNIEĆ, i to nie jest
        // sytuacja wyjątkowa, tylko normalny przebieg na czystej bazie: runner
        // woła tę metodę RAZ po migracji 063, czyli zanim wykona plik 065.
        // Pytamy więc o kolumnę, zamiast zakładać — inaczej pierwszy przebieg
        // wywaliłby się na backfillu przewyższeń, który z profilem nie ma nic
        // wspólnego, i zostawił trasy bez ani jednej z tych dwóch wartości.
        $hasProfile = $db->query("SHOW COLUMNS FROM known_routes LIKE 'elevation_profile'")
            ->fetchColumn() !== false;

        $rows = $db->query('
            SELECT id, name, gpx_url FROM known_routes
             WHERE (elevation_gain_m IS NULL' . ($hasProfile ? ' OR elevation_profile IS NULL' : '') . ')
               AND gpx_url IS NOT NULL
        ')->fetchAll();

        $stmt = $db->prepare($hasProfile
            ? 'UPDATE known_routes SET elevation_gain_m = :m, elevation_profile = :p WHERE id = :id'
            : 'UPDATE known_routes SET elevation_gain_m = :m WHERE id = :id');

        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $path = CORE_PATH . '/..' . $row['gpx_url'];
            try {
                if (!is_file($path)) {
                    throw new \RuntimeException('brak pliku ' . $row['gpx_url']);
                }
                $parsed = Gpx::parse($path, Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
                $params = ['m' => (int) $parsed['elevationGainM'], 'id' => (int) $row['id']];
                if ($hasProfile) {
                    $params['p'] = self::encodeProfile($parsed['elevationProfile'] ?? null);
                }
                $stmt->execute($params);
                $updated++;
                if ($log) {
                    $log('  ' . $row['name'] . ' → ' . (int) $parsed['elevationGainM'] . ' m'
                        . ($hasProfile ? ', profil: ' . count($parsed['elevationProfile'] ?? []) . ' pkt' : ''));
                }
            } catch (\Throwable $e) {
                // Uszkodzony albo brakujący plik nie może zatrzymać wdrożenia:
                // trasa zostaje z NULL-em, czyli z uczciwym „nie wiem", i da się
                // ją naprawić z panelu („Przelicz pola") po podmianie pliku.
                $skipped++;
                if ($log) {
                    $log('  POMINIĘTO ' . $row['name'] . ': ' . $e->getMessage());
                }
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Prostokąt obejmujący trasę — pod kadr mapy na jej stronie.
     *
     * Ze ŚRODKÓW PÓL, tak samo jak `geometryInBounds()`. Alternatywą było
     * `GpxGeometry::boundsFor()`, ale ono zna wyłącznie ślady już zindeksowane
     * w `gpx_geometry`, a plik znanej trasy nie musi tam być — mapa dostawałaby
     * wtedy raz kadr, raz całą Polskę, zależnie od historii pliku.
     *
     * Kadr USTAWIANY Z SERWERA, a nie dociągany po wczytaniu GPX-a: mapa pyta
     * o pola dla widocznego prostokąta zaraz po starcie, więc bez tego pierwsze
     * żądanie leciałoby dla całego kraju, a obraz przeskakiwałby po dojściu
     * pliku.
     *
     * @return array{south:float,west:float,north:float,east:float}|null
     */
    public static function boundsFor(int $routeId): ?array
    {
        $cells = self::cellIds($routeId);
        if (!$cells) {
            return null;
        }

        $lats = [];
        $lons = [];
        foreach ($cells as $cellId) {
            [$lat, $lon] = DiscoveryGrid::cellCenter($cellId);
            $lats[] = $lat;
            $lons[] = $lon;
        }

        // Zapas o pół pola: linia śladu wychodzi poza środki skrajnych pól,
        // a nie chcemy jej przyciąć krawędzią kadru.
        $pad = 0.005;
        return [
            'south' => min($lats) - $pad, 'west' => min($lons) - $pad,
            'north' => max($lats) + $pad, 'east' => max($lons) + $pad,
        ];
    }

    /** @return int[] pola trasy — pod narysowanie jej na mapie odkryć */
    public static function cellIds(int $routeId): array
    {
        $stmt = Database::connection()->prepare('SELECT cell_id FROM known_route_cells WHERE route_id = :id');
        $stmt->execute(['id' => $routeId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * BRAKUJĄCE POLA TRAS W OKOLICY — pod alerty w apce (Etap 1a, 2026-09-11).
     *
     * Zwraca pola tras, których ten człowiek JESZCZE nie ma, leżące w podanym
     * prostokącie, wyłącznie z tras, które **już zaczął**.
     *
     * ============================================================================
     * DLACZEGO TYLKO ZACZĘTE TRASY — TO NIE JEST OPTYMALIZACJA, TYLKO ZASADA
     * ============================================================================
     * „Nie prosimy o rzecz nierozsądną" (reguła usera z 2026-09-11). Pole trasy,
     * której ktoś nigdy nie tknął, jest po prostu polem na mapie — zachęta do
     * niego znaczyłaby „nadłóż drogi, żeby zacząć coś, o czym nie wiesz".
     * Pole BRAKUJĄCE w trasie, którą ktoś już w połowie ma, jest czym innym:
     * jest okazją, bo nadłożenie jest małe, a domknięcie realne.
     *
     * Zwracamy WSPÓŁRZĘDNE ŚRODKA POLA — apka liczy od nich odległość tą samą
     * matematyką co przy skarbach. Pole to ~500 m, więc środek jest dokładny
     * dokładnie na tyle, na ile ma być: mówimy „tędy", nie „tu".
     *
     * @return array<int,array{route_id:int,slug:string,name:string,cell_id:int,lat:float,lon:float,brakuje:int}>
     */
    public static function gapsNearbyForUser(int $userId, array $bounds, int $limit = 40): array
    {
        $zakres = \Utils\DiscoveryGrid::axialRangeForBounds(
            $bounds['south'],
            $bounds['west'],
            $bounds['north'],
            $bounds['east']
        );

        // Filtr po `cell_q`/`cell_r` zamiast po współrzędnych: tabela trzyma
        // osie heksów, a nie lat/lon, więc prostokąt przeliczamy na zakres osi
        // (`axialRangeForBounds`) i porównujemy liczby całkowite. Tańsze i —
        // co ważniejsze — nie wymaga trzymania drugiej reprezentacji tego
        // samego położenia.
        $stmt = Database::connection()->prepare('
            SELECT krc.route_id, krc.cell_id, kr.slug, kr.name,
                   (SELECT COUNT(*) FROM known_route_cells k2
                     WHERE k2.route_id = krc.route_id
                       AND NOT EXISTS (SELECT 1 FROM discovery_cells d2
                                        WHERE d2.user_id = :uid_brak AND d2.cell_id = k2.cell_id)
                   ) AS brakuje
              FROM known_route_cells krc
              JOIN known_routes kr ON kr.id = krc.route_id AND kr.is_active = 1
             WHERE krc.cell_q BETWEEN :q_min AND :q_max
               AND krc.cell_r BETWEEN :r_min AND :r_max
               -- TEGO pola user nie ma...
               AND NOT EXISTS (
                   SELECT 1 FROM discovery_cells d
                    WHERE d.user_id = :uid_moje AND d.cell_id = krc.cell_id
               )
               -- ...ale trasę już zaczął (ma w niej choć jedno pole).
               AND EXISTS (
                   SELECT 1 FROM known_route_cells k3
                     JOIN discovery_cells d3 ON d3.cell_id = k3.cell_id AND d3.user_id = :uid_start
                    WHERE k3.route_id = krc.route_id
               )
             LIMIT ' . (int) $limit . '
        ');
        $stmt->execute([
            'uid_brak'  => $userId,
            'uid_moje'  => $userId,
            'uid_start' => $userId,
            'q_min'     => $zakres['qMin'],
            'q_max'     => $zakres['qMax'],
            'r_min'     => $zakres['rMin'],
            'r_max'     => $zakres['rMax'],
        ]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            [$lat, $lon] = \Utils\DiscoveryGrid::cellCenter((int) $row['cell_id']);
            $out[] = [
                'route_id' => (int) $row['route_id'],
                'slug'     => (string) $row['slug'],
                'name'     => (string) $row['name'],
                'cell_id'  => (int) $row['cell_id'],
                'lat'      => $lat,
                'lon'      => $lon,
                'brakuje'  => (int) $row['brakuje'],
            ];
        }
        return $out;
    }

}
