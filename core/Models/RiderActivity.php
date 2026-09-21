<?php
// core/Models/RiderActivity.php
// Etap 8 — PRZEJAZD. Jedyne miejsce, w którym cokolwiek trafia do
// discovery_cells.
//
// CO LICZY SIĘ JAKO PRZEJAZD (zaostrzone migracją 042)
// -----------------------------------------------------
// Potwierdzona obecność na turnusie (event_attendance) PLUS ślad z tego, co
// faktycznie się wydarzyło (edition_tracks): własny ślad uczestnika, a gdy go
// nie ma — ślad z imprezy wgrany przez organizatora po wyjeździe.
//
// Trasa PLANOWANA (event_stages.gpx_url, event_route_variants.gpx_url)
// świadomie NIE jest brana pod uwagę, mimo że jest pod ręką i była źródłem
// pierwszej wersji. To zapowiedź, nie dowód: kto skrócił trasę, kto zawrócił
// w połowie i kto pojechał zupełnie inaczej, odkrywałby na niej dokładnie
// tyle samo, co ten, kto przejechał wszystko.
//
// Skutek uboczny, świadomie zaakceptowany: turnus bez wgranego śladu nie daje
// nikomu ani jednego pola, choćby wszyscy potwierdzili obecność. Lepiej mieć
// mapę mniejszą i prawdziwą niż większą i zmyśloną.
//
// Pozostałe ograniczenie: samotny przejazd (poza wydarzeniem) nadal nie
// odkrywa nic. Zniknie, gdy dojdzie źródło niezwiązane z turnusem ('strava'
// albo wolny upload) — dopisze wiersze do tej samej tabeli, nie ruszając
// reszty modułu.
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;
use Utils\Gpx;
use Utils\TileGrid;
use Utils\TrackPalette;

class RiderActivity
{
    // Czym potwierdzono przejazd. 'event_route' (trasa PLANOWANA) było źródłem
    // pierwszej wersji i zostało wycofane migracją 042 — zapowiedź nie jest
    // dowodem przejechania. Stara wartość może jeszcze siedzieć w bazie na
    // wierszach sprzed przeliczenia; nowe przejazdy jej nie dostają.
    public const SOURCE_EVENT_TRACK = 'event_track';  // ślad z imprezy (organizator)
    public const SOURCE_OWN_TRACK   = 'own_track';    // własny ślad uczestnika
    // Przejazd BEZ WYDARZENIA (migr. 049) — codzienna runda po okolicy. To samo
    // źródło prawdy co dwa wyżej (plik GPX), tylko bez turnusu, do którego
    // można by je przypiąć.
    public const SOURCE_SOLO        = 'solo';
    // Ile pól siatki muszą mieć wspólnych dwa przejazdy, żeby uznać, że szły
    // TĘDY SAMO (strona przejazdu, `otherRidesAlong`). Pole ma ok. 500 m.
    private const MIN_SHARED_CELLS  = 5;

    // Identyfikatory pól są liczbami całkowitymi wyliczonymi przez
    // DiscoveryGrid, nigdy danymi z żądania — wklejamy je wprost do zapytania
    // (po intval), zamiast generować kilkaset placeholderów na porcję.
    private const CHUNK = 500;

    /** Ile przejazdów solo na jednej stronie listy (2026-08-26, szukanie/paginacja). */
    public const SOLO_PER_PAGE = 20;

    // REGION PRZEJAZDU SOLO (2026-08-27, zgłoszenie usera: „brak widoczności
    // z jakiego regionu są przejazdy" na /admin/moje-przejazdy). Przejazd solo
    // dotyka pól tej samej siatki co wyjazd, więc te same pola już wskazują
    // region — `rider_activity_regions` (wypełniana w recordTouchedCells())
    // to jedyne źródło prawdy. Wzorzec 1:1 z `KnownRoute::find()`: GROUP_CONCAT
    // po nazwach, bo przejazd bywa na styku kilku regionów.
    private const REGION_JOIN = '
              LEFT JOIN (
                    SELECT rar.activity_id,
                           GROUP_CONCAT(reg2.name ORDER BY reg2.sort_order SEPARATOR ", ") AS name
                      FROM rider_activity_regions rar
                      JOIN dictionary_items reg2 ON reg2.id = rar.region_item_id
                     GROUP BY rar.activity_id
              ) reg ON reg.activity_id = a.id
    ';

    /**
     * Wejście z EventAttendance::declare(). Idempotentne w obie strony:
     * potwierdzenie obecności zapisuje przejazd raz, wycofanie go usuwa razem
     * z odkryciami, które tylko z niego wynikały.
     */
    public static function syncForRsvp(int $rsvpId, bool $attended): void
    {
        if (!$attended) {
            self::removeForRsvp($rsvpId);
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM rider_activities WHERE rsvp_id = :rsvp_id');
        $stmt->execute(['rsvp_id' => $rsvpId]);
        if ($stmt->fetchColumn() !== false) {
            return; // już policzone
        }

        $rsvp = self::rsvpContext($rsvpId);
        if ($rsvp === null) {
            return;
        }

        $tracks = EditionTrack::effectiveFor((int) $rsvp['edition_id'], (int) $rsvp['user_id']);
        if (empty($tracks)) {
            // Turnus bez śladu z odbytego wyjazdu — nie ma czym potwierdzić, że
            // ta osoba tamtędy jechała. Świadomie NIE zapisujemy pustego
            // przejazdu: brak wiersza znaczy „jeszcze nie policzone", więc gdy
            // ślad pojawi się później, przejazd policzy się normalnie. Pusty
            // wiersz zamknąłby tę drogę na zawsze.
            return;
        }

        // Źródło opisuje, CZYM potwierdzono przejazd: własnym śladem uczestnika
        // czy zbiorowym śladem z imprezy. Rozróżnienie jest istotne przy ocenie
        // wiarygodności danych — ślad własny mówi o jednej osobie, ślad z
        // imprezy zakłada, że wszyscy jechali razem.
        $ownTrack = ((int) ($tracks[0]['user_id'] ?? 0)) === (int) $rsvp['user_id'];

        $cells = [];
        $trackPoints = [];
        $distanceKm = 0.0;
        $elevationGainM = 0;
        $root = CORE_PATH . '/..';
        foreach ($tracks as $track) {
            $path = $root . $track['gpx_url'];
            if (!is_file($path)) {
                continue;
            }
            try {
                $parsed = Gpx::parse($path);
            } catch (\Throwable $e) {
                // Uszkodzony albo pusty plik nie może wywalić potwierdzania
                // obecności — Discovery jest warstwą DODATKOWĄ i nie wolno mu
                // przewrócić czynności, przy której wisi.
                continue;
            }
            $distanceKm += (float) $parsed['distanceKm'];
            $elevationGainM += (int) $parsed['elevationGainM'];
            foreach (DiscoveryGrid::cellsForTrack($parsed['points']) as $cellId) {
                $cells[$cellId] = true;
            }
            // Punkty śladu zbieramy RÓWNOLEGLE do pól, bo skarby (SKA/6)
            // potrzebują prawdziwej odległości, a pole siatki ma ~500 m —
            // za grubo, żeby na nim oprzeć zaliczenie.
            foreach ($parsed['points'] as $pt) {
                $trackPoints[] = $pt;
            }
        }
        $cells = array_keys($cells);
        if (empty($cells)) {
            return;
        }

        self::record(
            (int) $rsvp['user_id'],
            $cells,
            $ownTrack ? self::SOURCE_OWN_TRACK : self::SOURCE_EVENT_TRACK,
            (int) $rsvp['edition_id'],
            $rsvpId,
            $rsvp['start_date'],
            // Dystans z FAKTYCZNIE przejechanego śladu, nie z zapowiedzianej
            // długości wydarzenia — inaczej „skrócony" przejazd raportowałby
            // pełny dystans mimo krótszego śladu. To samo dotyczy przewyższenia.
            round($distanceKm, 2),
            $elevationGainM,
            ['trackPoints' => $trackPoints]
        );
    }

    /**
     * PRZEJAZD SOLO — ślad bez wydarzenia (migr. 049).
     *
     * Codzienna runda po okolicy nie miała dotąd jak trafić na mapę: przejazd
     * powstawał wyłącznie z potwierdzonej obecności na turnusie. To znaczyło,
     * że WIĘKSZOŚĆ tego, co ludzie realnie jeżdżą, dla tego modułu nie
     * istniała.
     *
     * IDEMPOTENCJA jest kluczem unikalnym `(user_id, gpx_hash)`, nie
     * sprawdzeniem tutaj: ten sam plik wgrany drugi raz odbija się o bazę.
     * Metoda zwraca `null`, gdy plik już był — wywołujący pokazuje wtedy
     * komunikat zamiast naliczać cokolwiek po raz drugi.
     *
     * @return ?array wynik record() albo null, gdy ten plik już jest policzony
     */
    public static function recordSolo(int $userId, string $absoluteGpxPath, string $gpxUrl): ?array
    {
        $hash = hash_file('sha256', $absoluteGpxPath);
        if ($hash === false) {
            return null;
        }

        // Sprawdzenie PRZED parsowaniem — parsowanie 5000 punktów i liczenie pól
        // tylko po to, żeby odbić się o klucz, byłoby marnotrawstwem. Sam klucz
        // i tak zostaje ostatnią linią obrony przy wyścigu dwóch żądań.
        $db = Database::connection();
        $stmt = $db->prepare('SELECT 1 FROM rider_activities WHERE user_id = :u AND gpx_hash = :h LIMIT 1');
        $stmt->execute(['u' => $userId, 'h' => $hash]);
        if ($stmt->fetchColumn() !== false) {
            return null;
        }

        $parsed = Gpx::parse($absoluteGpxPath);

        // PRYWATNOŚĆ (§27): ślad solo zaczyna się i kończy pod domem, a mapa
        // odkryć jest publiczna. Przycinamy końce ZANIM policzymy pola — pole,
        // które nie powstało, nie wycieknie ani mapą, ani heatmapą, ani żadnym
        // przyszłym eksportem, o którym ktoś zapomni.
        //
        // Dystans i przewyższenie liczymy z PEŁNEGO śladu: przejechane
        // kilometry to fakt o człowieku, nie o jego adresie, i nie ma powodu
        // zabierać mu punktów za dojazd z domu.
        $points = DiscoveryGrid::trimEnds($parsed['points'], DiscoveryScoring::homeTrimRadiusM());
        $cells = DiscoveryGrid::cellsForTrack($points);
        if (empty($cells)) {
            return null;
        }

        // HEATMAPA SPOŁECZNOŚCI (migr. 076, 2026-08-28) — punkty tuż wyżej są
        // JUŻ przycięte pod pola odkryć; ten sam, gotowy zestaw wystarcza też
        // pod TileSource::tracks('all'), więc zapisujemy go od razu, zamiast
        // każąc pierwszemu kaflowi, który dotknie tego śladu, parsować plik
        // od nowa. Zero kosztu — dokładnie ta sama zasada co przy kolorze
        // śladów ("kolor przydziela się SAM przy liczeniu geometrii").
        GpxGeometry::ensureTrimmedFromPoints($hash, $points);

        // PLIK NA DYSKU ZOSTAJE SUROWY — I TO JEST DECYZJA (2026-08-26).
        // Przez jeden dzień stało tu `Gpx::saveTrimmed($absoluteGpxPath, ...)`,
        // czyli nadpisanie kanonicznego pliku wersją bez okolic domu. Zdjęte,
        // bo płaciły za to KILOMETRY: powiązanie przejazdu solo z turnusem
        // przelicza dystans Z PLIKU (EditionTrack::distanceFromFile), więc
        // przycięty oryginał zabierał człowiekowi dojazd z domu — a dojazd
        // z domu to przejechane kilometry, dokładnie jak mówi akapit wyżej.
        //
        // Zamiast czyścić plik, NIE POKAZUJEMY GO PUBLICZNIE: ślad solo nie
        // wchodzi do wspólnej warstwy „Ślady" (TileSource::tracks, klucz
        // `all`), a jego adres dostaje z feedu wyłącznie właściciel
        // (Support::trackUrlsForFeed). Przycięcie zostaje tam, gdzie było od
        // początku i gdzie nic nie kosztuje — na PUNKTACH przed liczeniem pól,
        // bo pole raz zapisane wychodzi już KAŻDĄ publiczną drogą, a plik
        // wychodzi tylko tymi, które mu na to pozwolimy.

        // Data przejazdu ze znacznika czasu w pliku (8A/10), a gdy go nie ma —
        // dzisiejsza. Przejazd solo nie ma turnusu, z którego mógłby wziąć datę,
        // a `ride_date` jest potrzebna punktacji (dzienny limit) i porządkowi
        // na liście „Ostatnie punkty".
        $rideDate = $parsed['startedAt'] !== null
            ? substr($parsed['startedAt'], 0, 10)
            : date('Y-m-d');

        try {
            $result = self::record(
                $userId,
                $cells,
                self::SOURCE_SOLO,
                null,
                null,
                $rideDate,
                (float) $parsed['distanceKm'],
                (int) $parsed['elevationGainM'],
                [
                    'gpxUrl'        => $gpxUrl,
                    'gpxHash'       => $hash,
                    'startedAt'     => $parsed['startedAt'],
                    'movingSeconds' => $parsed['elapsedSeconds'],
                    // Punkty PO przycięciu okolic domu (§27) — skarb pod
                    // własnym blokiem nie zaliczy się z GPX-a, bo tych punktów
                    // w ogóle tu nie ma. Wychodzi to samo, co przy odkryciach.
                    'trackPoints'   => $points,
                ]
            );
            // Bez podbijania epoki warstwy `all`: przejazd solo nie jest jej
            // częścią, więc nowy ślad nie zmienia ani jednego kafla
            // społeczności. Kafle własne rowerzysty (`u-{slug}`/`me`) rysują
            // ślady wyjazdów, nie solo — te też zostają nietknięte.
            //
            // Dystans/przewyższenie dokładone TU, nie w record(): tamten zwraca
            // to samo dla wszystkich źródeł (RSVP, solo, przyszła Strava) i nie
            // ma ich pod ręką — tu mamy $parsed z parsowania pliku. Dodatkowe
            // klucze w tablicy, zero zmiany kontraktu dla pozostałych wołających.
            $result['distanceKm']      = (float) $parsed['distanceKm'];
            $result['elevationGainM']  = (int) $parsed['elevationGainM'];
            return $result;
        } catch (\PDOException $e) {
            // Wyścig dwóch równoległych wgrań tego samego pliku — klucz unikalny
            // zadziałał, i o to chodziło.
            if (str_contains($e->getMessage(), 'idx_ra_user_gpx')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Zapisuje przejazd i wynikające z niego odkrycia. Wydzielone z
     * syncForRsvp(), bo to jest właśnie ten punkt, w który wepnie się przyszły
     * upload własnego GPX albo import ze Stravy — dostaje gotową listę pól i
     * nie wie, skąd pochodzą.
     *
     * @param int[] $cellIds
     */
    /**
     * @param array $meta Dane pliku dla przejazdu SOLO (migr. 049):
     *        `gpxUrl`, `gpxHash`, `startedAt`, `movingSeconds`. Dla przejazdów
     *        z wydarzenia puste — tam plik wisi na `edition_tracks`, a nie na
     *        samym przejeździe.
     */
    public static function record(
        int $userId,
        array $cellIds,
        string $sourceCode,
        ?int $editionId,
        ?int $rsvpId,
        ?string $rideDate,
        float $distanceKm,
        int $elevationGainM = 0,
        array $meta = []
    ): array {
        $db = Database::connection();

        $newCells = self::filterUndiscovered($userId, $cellIds);
        // §25 — bezpiecznik przed jednym przejazdem przez pół kontynentu
        // (błędny GPX). Obcinamy same odkrycia, nie cały przejazd.
        $limit = DiscoveryScoring::maxNewCellsPerActivity();
        if (count($newCells) > $limit) {
            $newCells = array_slice($newCells, 0, $limit);
        }

        // Ilu rowerzystów miało te pola PRZED nami — liczone zanim cokolwiek
        // wstawimy, inaczej każde pole miałoby już co najmniej nas samych.
        $ridersBefore = self::ridersBefore($newCells);

        $stmt = $db->prepare('
            INSERT INTO rider_activities
                (user_id, source_code, edition_id, rsvp_id, gpx_url, gpx_hash,
                 ride_date, started_at, moving_seconds,
                 distance_km, elevation_gain_m, cells_touched, cells_new)
            VALUES (:user_id, :source, :edition_id, :rsvp_id, :gpx_url, :gpx_hash,
                    :ride_date, :started_at, :moving,
                    :distance, :elevation, :touched, :new)
        ');
        $stmt->execute([
            'user_id'    => $userId,
            'source'     => $sourceCode,
            'edition_id' => $editionId,
            'rsvp_id'    => $rsvpId,
            'gpx_url'    => $meta['gpxUrl'] ?? null,
            'gpx_hash'   => $meta['gpxHash'] ?? null,
            'ride_date'  => $rideDate,
            'started_at' => $meta['startedAt'] ?? null,
            'moving'     => $meta['movingSeconds'] ?? null,
            'distance'   => $distanceKm,
            'elevation'  => max(0, $elevationGainM),
            'touched'    => count($cellIds),
            'new'        => count($newCells),
        ]);
        $activityId = (int) $db->lastInsertId();

        // Ślad po WSZYSTKICH dotkniętych polach, także tych przejechanych po
        // raz kolejny — to jest jedyne miejsce, gdzie powtórzenie zostawia
        // cokolwiek w bazie. Nie daje punktów (§5 bez zmian), zasila wyłącznie
        // warstwę heatmapy: pole „gorące" to takie, przez które JEŻDZI SIĘ
        // często, a nie takie, które odkryło wielu.
        self::recordTouchedCells($activityId, $cellIds);

        if (!empty($newCells)) {
            self::insertDiscoveries($userId, $newCells, $activityId);
        }
        // Agregat podbijany dla wszystkich dotkniętych pól: passes zawsze,
        // riders tylko tam, gdzie odkrycie było nowe.
        self::bumpTotals($userId, $cellIds, $newCells);

        $points = DiscoveryScoring::forDiscoveries(array_values($ridersBefore));
        $trails = KnownRoute::syncProgress($userId, $activityId, $rideDate);

        // EMBLEMATY (migr. 087) — ZARAZ PO POSTĘPIE TRAS i celowo nie w środku
        // `syncProgress()`: tamto liczy PUNKTY i umie je odebrać, a emblematu
        // odebrać nie wolno (decyzja usera). Dwie różne reguły życia nagrody
        // nie mieszczą się w jednej metodzie bez cichego rozjazdu.
        //
        // Zawężone do TEGO użytkownika — ten sam kod bez zawężenia przelatuje
        // w cronie po wszystkich (patrz Models\Emblem::sync).
        Emblem::sync($userId);

        // PUNKTY IDĄ DO REJESTRU (Etap 8A), nie do kolumn. source_id = numer
        // przejazdu, więc nowy przejazd naliczy się normalnie, a powtórne
        // przetworzenie TEGO SAMEGO odbije się o klucz unikalny w
        // point_transactions — bez ani jednego sprawdzenia w tym kodzie.
        $ridePoints = self::cappedRidePoints($userId, $rideDate, $distanceKm, $elevationGainM);

        // Opisy bez odmiany przez liczbę — model nie ma helpera od polskiej
        // liczby mnogiej i nie jest to miejsce, żeby go zaczynać. „12 nowych
        // pól" czyta się poprawnie przy każdej wartości.
        //
        // NAJWAŻNIEJSZE SŁOWO NIŻEJ TO „Z NICH" (uwaga usera 2026-08-13).
        // Odkrycia i Nowy teren dotyczą TYCH SAMYCH pól, policzonych dwiema
        // stawkami — poprzednie opisy podawały tę samą liczbę dwa razy pod
        // różnymi nazwami („Nowe pola: 656" / „Białe plamy zamalowane: 656")
        // i czytało się to jak podwójne naliczenie.
        //
        // Stawka bierze się z FAKTYCZNIE przyznanych punktów, nie z konfiguracji:
        // rejestr jest niezmienny, więc wpis ma opisywać to, co wtedy policzono,
        // także po zmianie wartości w core/discovery.php.
        $newCellCount = count($newCells);
        $perCell = $newCellCount > 0 ? (int) round($points['discovery'] / $newCellCount) : 0;

        PointLedger::award(
            $userId, PointLedger::SOURCE_RIDE, (string) $activityId, $ridePoints,
            $activityId, $rideDate,
            number_format($distanceKm, 1, ',', ' ') . ' km'
                . ($elevationGainM > 0 ? ' · ' . $elevationGainM . ' m w górę' : '')
        );
        PointLedger::award(
            $userId, PointLedger::SOURCE_DISCOVERY, (string) $activityId, $points['discovery'],
            $activityId, $rideDate,
            $newCellCount . ' nowych pól · ' . $perCell . ' pkt za każde'
        );

        // „Powiększyło wspólną mapę", nie „zamalowane białe plamy": ten sam
        // fakt opisany jako WKŁAD, a nie jako zajęcie terenu. Poprzednie
        // sformułowanie było grą o sumie zerowej („moje pole to takie, którego
        // ty już nie zdobędziesz") i kłóciło się z „RAZEM ODKRYLIŚMY" na tej
        // samej stronie. Oba progi mogą wystąpić w jednym przejeździe, więc
        // opis składa się z tego, co faktycznie było.
        $explorationBits = [];
        if ($points['firstInCommunity'] > 0) {
            $explorationBits[] = $points['firstInCommunity'] . ' z nich powiększyło wspólną mapę';
        }
        if ($points['rare'] > 0) {
            $explorationBits[] = $points['rare'] . ' z nich to teren rzadko odwiedzany';
        }
        PointLedger::award(
            $userId, PointLedger::SOURCE_EXPLORATION, (string) $activityId, $points['exploration'],
            $activityId, $rideDate,
            $explorationBits ? implode(' · ', $explorationBits) : __('Teren mało uczęszczany')
        );

        $eventBonus = self::awardEventBonus($userId, $editionId, $activityId, $rideDate);

        // Kolumn points_* już nie ma (migr. 045). Były denormalizacją, która
        // nie umiała odpowiedzieć „za co", a przy okazji nie miała gdzie
        // pomieścić punktów za jazdę ani za wydarzenie. Jedynym źródłem prawdy
        // jest rejestr; wszystko, co niżej, jest tylko podsumowaniem dla
        // wołającego, nie stanem zapisanym w bazie.
        // SKARBY PO DRODZE (SKA/6). Świadomie NA KOŃCU i w osobnym try —
        // znalezienie skarbu jest miłym dodatkiem do przejazdu, a nie jego
        // warunkiem, więc awaria tej gałęzi nie może cofnąć zapisanego już
        // przejazdu, odkryć ani punktów. Ta sama zasada, którą Discovery
        // stosuje wobec potwierdzania obecności.
        $treasures = [];
        try {
            $treasures = Treasure::claimAlongTrack($userId, $cellIds, $meta['trackPoints'] ?? []);
        } catch (\Throwable $e) {
            $treasures = [];
        }

        return [
            'activityId'       => $activityId,
            'treasures'        => $treasures,
            'cellsTouched'     => count($cellIds),
            'cellsNew'         => count($newCells),
            'pointsRide'       => $ridePoints,
            'pointsEvent'      => $eventBonus,
            'pointsDiscovery'  => $points['discovery'],
            'pointsExploration'=> $points['exploration'],
            'pointsTrails'     => $trails['points'],
            'firstInCommunity' => $points['firstInCommunity'],
            'trailsReached'    => $trails['routes'],
        ];
    }

    /**
     * Wycofanie obecności. Kasuje przejazd i WYŁĄCZNIE te odkrycia, których był
     * pierwszym źródłem (activity_id) — pole odkryte wcześniej innym wyjazdem
     * zostaje, bo tamten przejazd nadal się odbył.
     */
    public static function removeForRsvp(int $rsvpId): void
    {
        $stmt = Database::connection()->prepare('SELECT id, user_id FROM rider_activities WHERE rsvp_id = :rsvp_id');
        $stmt->execute(['rsvp_id' => $rsvpId]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }

        self::deleteActivity((int) $row['id'], (int) $row['user_id']);
    }

    /**
     * Skasowanie JEDNEGO przejazdu razem ze wszystkim, co z niego wynikło.
     *
     * Wyciągnięte z removeForRsvp(), gdy pojawiła się druga droga kasowania
     * (powiązanie przejazdu solo z wyjazdem — ten sam ślad nie może być
     * policzony dwa razy). Kolejność operacji jest tu istotna i była już raz
     * przemyślana; dwie kopie tego kodu rozjechałyby się przy pierwszej zmianie.
     */
    private static function deleteActivity(int $activityId, int $userId): void
    {
        $db = Database::connection();

        // WSZYSTKIE pola dotknięte tym przejazdem, nie tylko odkryte przez
        // niego — bo cofnąć trzeba też jego wkład do licznika przejazdów.
        // Zbierane PRZED usunięciem: rider_activity_cells wisi na kluczu obcym
        // z ON DELETE CASCADE, więc po skasowaniu przejazdu nie ma już czego
        // czytać.
        $touched = $db->prepare('SELECT cell_id FROM rider_activity_cells WHERE activity_id = :activity_id');
        $touched->execute(['activity_id' => $activityId]);
        $cellIds = array_map('intval', $touched->fetchAll(\PDO::FETCH_COLUMN));

        $db->prepare('DELETE FROM discovery_cells WHERE activity_id = :activity_id')
            ->execute(['activity_id' => $activityId]);
        // Kasuje też rider_activity_cells i punkty tego przejazdu (ON DELETE CASCADE).
        $db->prepare('DELETE FROM rider_activities WHERE id = :id')->execute(['id' => $activityId]);

        Discovery::refreshTotalsFor($cellIds);

        // Bonusy za trasy NIE wiszą na przejeździe — ten sam próg mógł zostać
        // osiągnięty innym wyjazdem, więc kaskada ich nie ruszy. Po zabraniu
        // pól pokrycie mogło spaść poniżej progu i wtedy trzeba je zdjąć.
        // syncProgress robi obie rzeczy naraz, więc wystarczy je zawołać.
        KnownRoute::syncProgress($userId);

        // EMBLEMATÓW TU NIE RUSZAMY, i to jest świadome. Skasowanie przejazdu
        // może zdjąć pokrycie poniżej 100%, ale emblemat raz zdobyty zostaje
        // (decyzja usera 2026-09-11) — `Emblem` nie ma odpowiednika `revoke()`.
        // Gdyby kiedyś miał, jego miejsce byłoby dokładnie tutaj.
    }

    // ---------------------------------------------------------------
    // Przejazdy solo: przegląd i powiązanie z wyjazdem
    // ---------------------------------------------------------------

    /**
     * WSZYSTKIE przejazdy solo tej osoby — druga zakładka „Moich przejazdów".
     *
     * Zgłoszenie usera (2026-08-23, po imporcie z Garmina): „działa, ale co
     * z tego, jak nawet nie mogę zobaczyć ich, jak zaczytałem". Do tej pory
     * ślad bez wydarzenia znikał w statystykach mapy odkryć i nie było ekranu,
     * na którym dałoby się go ZOBACZYĆ jako pozycję — a bez tego nie da się
     * z nim nic zrobić.
     *
     * Nazwa z licznika (`device_activities.activity_name`, migr. 069) dołączana
     * LEFT JOIN-em, bo dla wgranego pliku jej po prostu nie ma. To jedyna rzecz,
     * która odróżnia „poranną rundę" od „wypadu w Bieszczady" na liście samych
     * dat i kilometrów. Bez `provider` w warunku: nazwa jest nazwą niezależnie
     * od tego, z którego serwisu przyszła, a wiersz i tak wskazuje JEDEN przejazd.
     */
    public static function soloForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT a.id, a.ride_date, a.started_at, a.distance_km, a.elevation_gain_m,
                   a.cells_new, a.cells_touched, a.gpx_url, a.name,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = a.id), 0) AS points_total,
                   g.activity_name AS garmin_name,
                   reg.name AS region_label
              FROM rider_activities a
              LEFT JOIN device_activities g ON g.rider_activity_id = a.id
              " . self::REGION_JOIN . "
             WHERE a.user_id = :user_id AND a.source_code = :source
             ORDER BY a.ride_date DESC, a.id DESC
        ");
        $stmt->execute(['user_id' => $userId, 'source' => self::SOURCE_SOLO]);

        return $stmt->fetchAll();
    }

    /**
     * TA SAMA lista co soloForUser(), ale ze szukaniem i stronicowaniem —
     * druga zakładka „Moich przejazdów" (2026-08-26, zgłoszenie usera: „jedyna
     * akcja jaka może zostać podjęta to przypisanie trasy z wyjazdem, powinienem
     * mieć więcej możliwości"). Wzorzec 1:1 z `KnownRoute::search()`: to samo
     * zestawienie liczb (`total`/`strona`/`stron`), więc widok czyta je tak samo.
     *
     * `soloForUser()` ZOSTAJE nietknięta — modal „Powiąż z wyjazdem" (z zakładki
     * Wyjazdy) musi widzieć WSZYSTKIE przejazdy solo, nie tylko bieżącą stronę
     * wyników, inaczej człowiek nie znalazłby w nim przejazdu, który akurat
     * odfiltrowało szukanie.
     *
     * @return array{items:list<array<string,mixed>>,total:int,strona:int,stron:int,szukaj:string}
     */
    public static function searchSoloForUser(int $userId, array $opcje = []): array
    {
        $szukaj = trim((string) ($opcje['szukaj'] ?? ''));
        $strona = max(1, (int) ($opcje['strona'] ?? 1));

        $from = '
              FROM rider_activities a
              LEFT JOIN device_activities g ON g.rider_activity_id = a.id
        ';
        $where = 'a.user_id = :user_id AND a.source_code = :source';
        $params = ['user_id' => $userId, 'source' => self::SOURCE_SOLO];

        if ($szukaj !== '') {
            // Własna nazwa (migr. 080), nazwa z licznika, ALBO data — przejazd
            // solo bez żadnej z tych dwóch pierwszych nie ma ani tytułu, ani
            // opisu, ani miejsca zapisanego osobno od pliku GPX. Data jest
            // surowym `YYYY-MM-DD`, więc „2026-08" trafia cały miesiąc.
            $where .= ' AND (a.name LIKE :q0 OR g.activity_name LIKE :q1 OR a.ride_date LIKE :q2)';
            $params['q0'] = '%' . $szukaj . '%';
            $params['q1'] = '%' . $szukaj . '%';
            $params['q2'] = '%' . $szukaj . '%';
        }

        $db = Database::connection();
        $count = $db->prepare('SELECT COUNT(*) ' . $from . ' WHERE ' . $where);
        $count->execute($params);
        $ile = (int) $count->fetchColumn();

        $offset = ($strona - 1) * self::SOLO_PER_PAGE;
        $stmt = $db->prepare('
            SELECT a.id, a.ride_date, a.started_at, a.distance_km, a.elevation_gain_m,
                   a.cells_new, a.cells_touched, a.gpx_url, a.name,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = a.id), 0) AS points_total,
                   g.activity_name AS garmin_name,
                   reg.name AS region_label
            ' . $from . self::REGION_JOIN . '
             WHERE ' . $where . '
             ORDER BY a.ride_date DESC, a.id DESC
             LIMIT ' . self::SOLO_PER_PAGE . ' OFFSET ' . $offset . '
        ');
        $stmt->execute($params);

        return [
            'items'  => $stmt->fetchAll(),
            'total'  => $ile,
            'strona' => $strona,
            'stron'  => max(1, (int) ceil($ile / self::SOLO_PER_PAGE)),
            'szukaj' => $szukaj,
        ];
    }

    /**
     * PRZEJAZD POD WSKAZANYM PUNKTEM — odpowiedź na kliknięcie w mapę
     * (2026-08-27, prośba usera: „dodajmy możliwość klikania na solo ślady tak
     * samo jak na znane trasy").
     *
     * WYŁĄCZNIE WŁASNE PRZEJAZDY i to nie jest ostrożność na wyrost, tylko ta
     * sama reguła, która trzyma cały §27: plik solo jest surowy i zaczyna się
     * pod czyimś domem. Warstwa „Ślady" z przejazdami solo istnieje wyłącznie
     * pod prywatnym kluczem `me` (patrz `TileSource::tracks`), więc odpowiedź
     * na klik ma dokładnie ten sam zasięg — `user_id` z sesji, nigdy z żądania.
     *
     * TRAFIENIE LICZYMY NA GEOMETRII, NIE NA POLACH — i to jest jedyna
     * świadoma różnica względem `KnownRoute::atPoint()`, które pyta o pola
     * siatki. Dwa powody, oba praktyczne:
     *
     *  1. POLE MA OK. 500 M, a wokół domu przejazdy leżą jeden na drugim
     *     (zmierzone na koncie zgłaszającego: 45 śladów w jednym kaflu z14).
     *     Trafienie „po polach" zwróciłoby tam wszystkie naraz — czyli byłoby
     *     bezużyteczne dokładnie tam, gdzie ma pomóc. Kolory rozdzieliły te
     *     linie wizualnie (migr. 073); klik musi umieć rozdzielić je tak samo.
     *  2. PRZEJAZD SOLO MA PRZYCIĘTE KOŃCE W POLACH (§27), ale rysuje się
     *     z CAŁEGO pliku. Trafienie po polach nie odpowiadałoby więc na
     *     kliknięcie w widoczną linię przy domu — a to jest linia, którą widać.
     *
     * Kandydatów zawęża indeks kafli (`gpx_tiles`), więc po punktach chodzimy
     * wyłącznie dla śladów, które w ogóle są w okolicy. Zmierzone na
     * najgorszym realnym przypadku (45 śladów, 262 tys. punktów w jednym
     * kaflu): ok. 90 ms na kliknięcie. To jest koszt JEDNEGO kliknięcia,
     * nie przesuwania mapy.
     *
     * @return array<int,array{id:int,name:string,date:?string,color:string,
     *     distanceKm:float,elevation:?int,cellsNew:int,cellsTouched:int,
     *     points:int,isSolo:bool}>
     */
    public static function atPoint(float $lat, float $lon, int $zoom, int $userId, int $limit = 3): array
    {
        // Tolerancja jak przy znanych trasach: ok. 12 px przeliczonych na metry,
        // bo przy oddaleniu jeden piksel to setki metrów i wymaganie precyzji
        // byłoby wymaganiem niemożliwego. Dolne ograniczenie niższe niż tam
        // (60 m zamiast 120 m), bo tu trafiamy w LINIĘ, a nie w pole o boku
        // pół kilometra — przy dużym przybliżeniu da się i trzeba celować.
        $tolerancjaM = max(60.0, TileGrid::metersPerPixel($zoom, $lat) * 12);

        [$px, $py] = TileGrid::toPixel($lat, $lon);
        // Ta sama tolerancja wyrażona w pikselach świata (STORE_Z), bo geometria
        // leży właśnie w nich — zamiana raz, zamiast przy każdym punkcie.
        $tolerancjaPx = $tolerancjaM / TileGrid::metersPerPixel(TileGrid::STORE_Z, $lat);

        // Kandydaci z indeksu kafli. Bierzemy kafel punktu I JEGO SĄSIADÓW:
        // klik tuż przy krawędzi kafla może trafić w ślad, który biegnie już
        // po drugiej stronie, a ten jest zapisany pod innym (tx, ty).
        [$tx, $ty] = TileGrid::tileOf($lat, $lon, TileGrid::INDEX_Z);

        $stmt = Database::connection()->prepare('
            SELECT DISTINCT ra.id, ra.gpx_hash
              FROM gpx_tiles t
              JOIN rider_activities ra ON ra.gpx_hash = t.gpx_hash AND ra.user_id = :uid
             WHERE t.tx BETWEEN :tx1 AND :tx2 AND t.ty BETWEEN :ty1 AND :ty2
        ');
        $stmt->execute([
            'uid' => $userId,
            'tx1' => $tx - 1, 'tx2' => $tx + 1,
            'ty1' => $ty - 1, 'ty2' => $ty + 1,
        ]);
        $kandydaci = $stmt->fetchAll();
        if (!$kandydaci) {
            return [];
        }

        $poHashu = [];
        foreach ($kandydaci as $row) {
            $poHashu[$row['gpx_hash']][] = (int) $row['id'];
        }

        $geom = GpxGeometry::load(array_keys($poHashu));
        $trafienia = [];
        foreach ($geom as $hash => $g) {
            $d = self::distanceToTrack($g['pts'], $px, $py, $tolerancjaPx);
            if ($d === null) {
                continue;
            }
            // Ten sam plik bywa przejazdem więcej niż raz (ta sama trasa wgrana
            // pod dwoma wpisami) — bierzemy każdy, a kolejność rozstrzyga
            // odległość od kliknięcia, potem id.
            foreach ($poHashu[$hash] as $id) {
                $trafienia[$id] = $d;
            }
        }
        if (!$trafienia) {
            return [];
        }

        asort($trafienia);
        $ids = array_slice(array_keys($trafienia), 0, max(1, $limit), true);

        return self::describeForPopup($ids, $userId);
    }

    /**
     * BLIŹNIAK `atPoint()` DLA PUBLICZNEGO PROFILU — „klik w solo obcej
     * osoby" (2026-09-10, zgłoszenie usera: „na swoim profilu każdy przejazd
     * jest klikalny, na czyimś powinno być tak samo" — dokładnie to samo
     * zgłoszenie, które w tym samym dniu dało kolor per ślad na `u-{slug}`
     * i klikalność w panelu „Ostatnia aktywność" dla obcych; TO jest trzecia,
     * ostatnia z tych trzech dróg).
     *
     * DWIE RÓŻNICE względem `atPoint()`, obie z tego samego powodu (§27, plik
     * solo zaczyna się pod czyimś domem, a `u-{slug}` leży na dysku pod
     * adresem do zgadnięcia):
     *   1. `$targetUserId` to WŁAŚCICIEL PROFILU, nie pytający — bramkę „czy
     *      wolno w ogóle o niego pytać" liczy WOŁAJĄCY (endpoint), TAK SAMO
     *      jak `Support::strangerTrackPath()`/`trackUrlsForFeed()` — jedna
     *      reguła, nie druga jej kopia w tej metodzie.
     *   2. Trafienie liczy się na geometrii PRZYCIĘTEJ (`gpx_tiles_trimmed`/
     *      `GpxGeometry::loadTrimmed()`), nie pełnej — okolice domu nie mają
     *      prawa być trafialne kliknięciem obcego, tak samo jak nie mają
     *      prawa być NARYSOWANE (`TileSource::tracks('u-{slug}')`).
     *
     * WYŁĄCZNIE SOLO (`source_code = SOURCE_SOLO`) — ślady z wyjazdów mają tu
     * ZAWSZE zasięg właściciela sprzed tej naprawy (owner-only), bo cała ta
     * funkcja („kliknij w linię na mapie, dostań dymek") nigdy wcześniej nie
     * działała na publicznym profilu dla NIKOGO, solo czy nie — user zgłosił
     * konkretnie solo, więc na tym kończy się dzisiejszy zakres; rozszerzenie
     * na turnusowe to osobna decyzja (mają już pełną, publiczną geometrię
     * przez `Support::rideGpxPath()`, więc technicznie byłoby prostsze, ale
     * nikt o to nie prosił).
     *
     * @return array<int,array{id:int,name:string,date:?string,color:string,
     *     distanceKm:float,elevation:?int,cellsNew:int,cellsTouched:int,
     *     points:int,isSolo:bool}>
     */
    public static function atPointForRider(float $lat, float $lon, int $zoom, int $targetUserId, int $limit = 3): array
    {
        $tolerancjaM = max(60.0, TileGrid::metersPerPixel($zoom, $lat) * 12);

        [$px, $py] = TileGrid::toPixel($lat, $lon);
        $tolerancjaPx = $tolerancjaM / TileGrid::metersPerPixel(TileGrid::STORE_Z, $lat);

        [$tx, $ty] = TileGrid::tileOf($lat, $lon, TileGrid::INDEX_Z);

        $stmt = Database::connection()->prepare('
            SELECT DISTINCT ra.id, ra.gpx_hash
              FROM gpx_tiles_trimmed t
              JOIN rider_activities ra ON ra.gpx_hash = t.gpx_hash
                                       AND ra.user_id = :uid AND ra.source_code = :src
             WHERE t.tx BETWEEN :tx1 AND :tx2 AND t.ty BETWEEN :ty1 AND :ty2
        ');
        $stmt->execute([
            'uid' => $targetUserId,
            'src' => self::SOURCE_SOLO,
            'tx1' => $tx - 1, 'tx2' => $tx + 1,
            'ty1' => $ty - 1, 'ty2' => $ty + 1,
        ]);
        $kandydaci = $stmt->fetchAll();
        if (!$kandydaci) {
            return [];
        }

        $poHashu = [];
        foreach ($kandydaci as $row) {
            $poHashu[$row['gpx_hash']][] = (int) $row['id'];
        }

        $geom = GpxGeometry::loadTrimmed(array_keys($poHashu));
        $trafienia = [];
        foreach ($geom as $hash => $g) {
            $d = self::distanceToTrack($g['pts'], $px, $py, $tolerancjaPx);
            if ($d === null) {
                continue;
            }
            foreach ($poHashu[$hash] as $id) {
                $trafienia[$id] = $d;
            }
        }
        if (!$trafienia) {
            return [];
        }

        asort($trafienia);
        $ids = array_slice(array_keys($trafienia), 0, max(1, $limit), true);

        return self::describeForPopup($ids, $targetUserId);
    }

    /**
     * Najmniejsza odległość punktu od łamanej, w pikselach — albo null, gdy
     * ślad nie mieści się w tolerancji.
     *
     * ODLEGŁOŚĆ OD ODCINKA, NIE OD PUNKTU: punkty GPS bywają rzadkie (zjazd
     * z górki co 10 s to ponad 200 m), więc mierzenie do najbliższego PUNKTU
     * gubiłoby trafienia w środku długiego odcinka — dokładnie tam, gdzie
     * linia na mapie jest najbardziej prosta i najłatwiej w nią kliknąć.
     * Ten sam powód, dla którego `GpxGeometry::tileSet()` uzupełnia przerwy
     * przy indeksowaniu.
     *
     * Wychodzimy NATYCHMIAST po pierwszym trafieniu w tolerancję — nie
     * potrzebujemy prawdziwego minimum, tylko odpowiedzi „czy i jak blisko".
     * Przy śladzie 17 tys. punktów to różnica między 1 ms a 20 ms.
     *
     * @param array<int,int> $pts płaska tablica [px, py, px, py, ...]
     */
    private static function distanceToTrack(array $pts, int $px, int $py, float $tolerancja): ?float
    {
        $n = count($pts);
        if ($n < 2) {
            return null;
        }

        $limit = $tolerancja * $tolerancja;
        $best = null;

        for ($i = 0; $i + 3 < $n; $i += 2) {
            $ax = $pts[$i];     $ay = $pts[$i + 1];
            $bx = $pts[$i + 2]; $by = $pts[$i + 3];

            // Rzut punktu na odcinek AB, zacisniety do <0,1> — klasyczna
            // odległość punkt-odcinek bez pierwiastkowania (porównujemy
            // kwadraty, pierwiastek liczymy raz, na końcu).
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            if ($len2 == 0) {
                $qx = $ax; $qy = $ay;
            } else {
                $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2;
                $t = $t < 0 ? 0 : ($t > 1 ? 1 : $t);
                $qx = $ax + $t * $dx;
                $qy = $ay + $t * $dy;
            }

            $odl2 = ($px - $qx) * ($px - $qx) + ($py - $qy) * ($py - $qy);
            if ($odl2 <= $limit) {
                return sqrt($odl2);
            }
            if ($best === null || $odl2 < $best) {
                $best = $odl2;
            }
        }

        return null;
    }

    /**
     * Opis przejazdów do dymka na mapie — te same liczby, które pokazuje
     * zakładka „Przejazdy solo" w „Moich przejazdach".
     *
     * Dwa ekrany opisujące ten sam byt dwoma różnymi zestawami liczb czytają
     * się jak dwa różne byty — ta sama zasada, którą kieruje się dymek znanej
     * trasy wobec karty trasy.
     *
     * @param array<int,int> $ids w kolejności trafienia (najbliższy pierwszy)
     */
    private static function describeForPopup(array $ids, int $userId): array
    {
        $in = implode(',', array_map('intval', $ids));
        $stmt = Database::connection()->prepare("
            SELECT a.id, a.source_code, a.ride_date, a.started_at, a.distance_km,
                   a.elevation_gain_m, a.cells_new, a.cells_touched, a.name,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = a.id), 0) AS points_total,
                   g.activity_name AS device_name,
                   geo.color_index,
                   ev.title AS event_title, ev.slug AS event_slug
              FROM rider_activities a
              LEFT JOIN device_activities g ON g.rider_activity_id = a.id
              LEFT JOIN gpx_geometry geo ON geo.gpx_hash = a.gpx_hash
              LEFT JOIN event_editions ed ON ed.id = a.edition_id
              LEFT JOIN events ev ON ev.id = ed.event_id
             WHERE a.id IN ($in) AND a.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);

        $poId = [];
        foreach ($stmt->fetchAll() as $r) {
            $poId[(int) $r['id']] = $r;
        }

        $out = [];
        foreach ($ids as $id) {
            if (!isset($poId[$id])) {
                continue;
            }
            $r = $poId[$id];
            $solo = $r['source_code'] === self::SOURCE_SOLO;

            $out[] = [
                'id'   => (int) $r['id'],
                // Własna nazwa (migr. 080) > nazwa z licznika > generyczny opis.
                // Przy przejazdach solo to jedyne, co odróżnia od siebie wiersze
                // z samych dat i kilometrów. Przejazd z wyjazdu ma tytuł
                // wydarzenia, bo o to się pyta, klikając w ślad z turnusu.
                'name' => $r['name']
                    ?: ($r['device_name']
                        ?: ($solo ? __('Przejazd solo') : ($r['event_title'] ?: __('Przejazd z wyjazdu')))),
                'date'         => $r['ride_date'],
                // KOLOR LINII, KTÓRĄ WIDAĆ NA MAPIE — dymek musi go powtórzyć,
                // bo to jedyne miejsce, gdzie „ta kreska" spotyka się ze swoim
                // opisem (ta sama decyzja i ta sama kropka co przy znanych
                // trasach). Bez niego przy trzech śladach w jednym dymku dalej
                // nie wiadomo, który jest który.
                'color'        => TrackPalette::colorOf($r['color_index']),
                'distanceKm'   => (float) $r['distance_km'],
                'elevation'    => $r['elevation_gain_m'] === null ? null : (int) $r['elevation_gain_m'],
                'cellsNew'     => (int) $r['cells_new'],
                'cellsTouched' => (int) $r['cells_touched'],
                'points'       => (int) $r['points_total'],
                'isSolo'       => $solo,
                'eventSlug'    => $solo ? null : ($r['event_slug'] ?: null),
            ];
        }

        return $out;
    }

    /**
     * Wiersz przejazdu w zakresie potrzebnym do ODPOWIEDZI O JEGO ŚLAD
     * (2026-09-02, endpoint `/api/rides/{id}/track`).
     *
     * NIE JEST to bramka uprawnień i celowo nie udaje takiej: pyta wyłącznie
     * „czym jest ten przejazd", a o to, czy pytający może zobaczyć jego ślad,
     * odpowiada `Controllers\Support::rideGpxPath()` — jedno miejsce dla feedu
     * i dla endpointu. Dlatego zwraca dokładnie te cztery pola, których ta
     * bramka używa, i ani jednego więcej.
     */
    public static function findForTrack(int $activityId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, edition_id, gpx_url FROM rider_activities WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * WSZYSTKO O JEDNYM PRZEJEŹDZIE — pod stronę `/przejazd/{id}`
     * (2026-09-03, tasks/done/strona-przejazdu.md).
     *
     * Bierze wiersz razem z tym, co strona i tak musiałaby dociągnąć osobno:
     * nazwą z licznika, kolorem linii, autorem i wydarzeniem. Jedno zapytanie
     * zamiast pięciu — te same złączenia, których używa dymek na mapie
     * (`describeForPopup`), bo to jest ten sam byt opisany szerzej.
     *
     * NIE JEST BRAMKĄ UPRAWNIEŃ (jak `findForTrack`): mówi, czym jest przejazd,
     * a o tym, komu wolno go zobaczyć, rozstrzyga `Controllers\Support`
     * (`rideGpxPath` + `strangerTrackPath`). Jedna reguła, jedno miejsce.
     */
    public static function findForPage(int $activityId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT a.*,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = a.id), 0) AS points_total,
                   d.activity_name AS device_name,
                   d.provider      AS device_provider,
                   geo.color_index,
                   ed.start_date, ed.end_date,
                   ev.id AS event_id, ev.title AS event_title, ev.slug AS event_slug,
                   u.name AS user_name, u.public_slug AS user_slug, u.avatar_url AS user_avatar
              FROM rider_activities a
              JOIN users u ON u.id = a.user_id
              LEFT JOIN device_activities d ON d.rider_activity_id = a.id
              LEFT JOIN gpx_geometry geo ON geo.gpx_hash = a.gpx_hash
              LEFT JOIN event_editions ed ON ed.id = a.edition_id
              LEFT JOIN events ev ON ev.id = ed.event_id
             WHERE a.id = :id
             LIMIT 1
        ');
        $stmt->execute(['id' => $activityId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * KTO JEŹDZI W REGIONIE (2026-09-14, strona regionu, sekcja „Kto tu jeździ").
     *
     * Ta sama zasada co „Mają ją całą" na stronie trasy
     * (`KnownRoute::finishersFor`): LICZBA obejmuje wszystkich, LISTA tylko
     * rowerzystów z publicznym profilem — ukrycie dotyczy tożsamości, nie
     * faktu. Warunek widoczności to ten sam, którego pilnuje
     * `Support::visibleRider()` (`roster_visible` + adres publiczny).
     * Region wyciągamy z `rider_activity_regions`, czyli dla przejazdu solo
     * z pól LICZONYCH po przycięciu okolic domu (§27) — region nie zdradza
     * więc więcej niż mapa odkryć.
     *
     * Kolejność: ostatnio jeżdżący pierwsi — strona ma pokazywać, że tu się
     * jeździ TERAZ, nie ranking stażu.
     *
     * @return array{people:list<array>, total:int}
     */
    public static function ridersInRegion(int $regionItemId, int $limit = 16): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT u.id, u.name, u.email, u.public_slug, u.avatar_url,
                   COUNT(DISTINCT ra.id) AS rides, MAX(ra.ride_date) AS last_ride
              FROM rider_activity_regions rar
              JOIN rider_activities ra ON ra.id = rar.activity_id
              JOIN users u ON u.id = ra.user_id
             WHERE rar.region_item_id = :region
               AND u.roster_visible = 1 AND u.public_slug IS NOT NULL AND u.blocked_at IS NULL
             GROUP BY u.id
             ORDER BY last_ride DESC, rides DESC
             LIMIT ' . max(1, $limit));
        $stmt->execute(['region' => $regionItemId]);
        $people = $stmt->fetchAll();

        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT ra.user_id)
              FROM rider_activity_regions rar
              JOIN rider_activities ra ON ra.id = rar.activity_id
             WHERE rar.region_item_id = :region
        ');
        $stmt->execute(['region' => $regionItemId]);

        return ['people' => $people, 'total' => (int) $stmt->fetchColumn()];
    }

    /**
     * Województwa, przez które prowadził przejazd — `rider_activity_regions`
     * (wypełniane przy liczeniu pól, patrz `recordTouchedCells`).
     *
     * @return list<string> nazwy regionów, alfabetycznie
     */
    public static function regionsFor(int $activityId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT reg.name
              FROM rider_activity_regions rar
              JOIN dictionary_items reg ON reg.id = rar.region_item_id
             WHERE rar.activity_id = :id
             ORDER BY reg.name
        ');
        $stmt->execute(['id' => $activityId]);

        return array_column($stmt->fetchAll(), 'name');
    }

    /**
     * INNE PRZEJAZDY TEJ SAMEJ OSOBY, KTÓRE SZŁY TĘDY (2026-09-03).
     *
     * Wspólny teren liczymy na POLACH SIATKI (`rider_activity_cells`), nie na
     * indeksie kafli (`gpx_tiles`). Kafle byłyby dokładniejsze, ale są liczone
     * LENIWIE — zmierzone przy wdrożeniu tej strony: 77 hashy w `gpx_tiles`
     * na 90 śladów przejazdów, więc sekcja bywałaby pusta bez żadnego powodu
     * widocznego dla człowieka. Pola ma KAŻDY przejazd, bo bez nich nie
     * policzyłyby się odkrycia.
     *
     * PRÓG WSPÓLNYCH PÓL, nie „choćby jedno": pole ma ok. 500 m, więc jedno
     * wspólne to skrzyżowanie po drodze, a nie ta sama trasa. Pięć to już
     * ponad dwa kilometry wspólnego terenu.
     *
     * WYŁĄCZNIE WŁASNE PRZEJAZDY — `user_id` przychodzi od wołającego z sesji,
     * nigdy z żądania. Cudze przejazdy tą samą drogą byłyby odpowiedzią na
     * pytanie „kto tędy jeździ", którego ten serwis świadomie nie zadaje (§27).
     *
     * @return list<array<string,mixed>>
     */
    public static function otherRidesAlong(int $activityId, int $userId, int $limit = 6): array
    {
        $stmt = Database::connection()->prepare('
            SELECT a.id, a.source_code, a.ride_date, a.distance_km, a.cells_new,
                   a.name,
                   d.activity_name AS device_name,
                   ev.title AS event_title,
                   COUNT(*) AS wspolne
              FROM rider_activity_cells c0
              JOIN rider_activity_cells c ON c.cell_id = c0.cell_id
                                         AND c.activity_id <> c0.activity_id
              JOIN rider_activities a ON a.id = c.activity_id AND a.user_id = :uid
              LEFT JOIN device_activities d ON d.rider_activity_id = a.id
              LEFT JOIN event_editions ed ON ed.id = a.edition_id
              LEFT JOIN events ev ON ev.id = ed.event_id
             WHERE c0.activity_id = :id
             GROUP BY a.id, a.source_code, a.ride_date, a.distance_km, a.cells_new,
                      a.name, d.activity_name, ev.title
            HAVING wspolne >= ' . self::MIN_SHARED_CELLS . '
             ORDER BY wspolne DESC, a.ride_date DESC
             LIMIT ' . max(1, $limit) . '
        ');
        $stmt->execute(['uid' => $userId, 'id' => $activityId]);

        return $stmt->fetchAll() ?: [];
    }

    /** Jeden przejazd solo, ale TYLKO jeśli należy do pytającego (anty-IDOR). */
    public static function findSoloForUser(int $activityId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, gpx_url, ride_date, distance_km, name
               FROM rider_activities
              WHERE id = :id AND user_id = :user_id AND source_code = :source
              LIMIT 1'
        );
        $stmt->execute(['id' => $activityId, 'user_id' => $userId, 'source' => self::SOURCE_SOLO]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Kasowanie WŁASNEGO przejazdu solo — z bazy I z dysku.
     *
     * Zgłoszenie usera (2026-08-26): lista przejazdów solo dawała tylko
     * powiązanie z wyjazdem, żadnej innej akcji. Anty-IDOR przez ten sam
     * strażnik co przy powiązaniu (`findSoloForUser`) — samo id z formularza
     * nie wystarcza.
     *
     * PLIK KASOWANY Z DYSKU — w odróżnieniu od `linkSoloToEdition()`, gdzie
     * ślad PRZECHODZI do `edition_tracks` i musi zostać. Tu nic go nie
     * przejmuje, więc zostawienie osieroconego pliku w `gpx/` byłoby czystym
     * śmieciem. Rejestr licznika (`device_activities`, gdy przejazd przyszedł
     * z Garmina/Polara/Wahoo) ZOSTAJE — `ON DELETE SET NULL` na
     * `rider_activity_id` (migr. 069) tylko odczepia wskaźnik, żeby ta sama
     * aktywność nie wróciła jako „nowa" na liście do pobrania.
     */
    public static function deleteSoloForUser(int $activityId, int $userId): bool
    {
        $activity = self::findSoloForUser($activityId, $userId);
        if ($activity === null) {
            return false;
        }

        self::deleteActivity($activityId, $userId);

        if (!empty($activity['gpx_url'])) {
            @unlink(CORE_PATH . '/..' . $activity['gpx_url']);
        }

        return true;
    }

    /** Najdłuższa dopuszczalna własna nazwa (migr. 080) — margines pod kolumnę VARCHAR(190). */
    public const NAME_MAX_LENGTH = 190;

    /**
     * Własna nazwa przejazdu solo (migr. 080, zgłoszenie usera: „chciałem mieć
     * to samo co przychodzi z Garmina, a później dodatkowo mieć możliwość
     * zmiany"). Anty-IDOR przez ten sam strażnik co kasowanie
     * (`findSoloForUser` — tylko WŁASNY i tylko SOLO: przejazd z wyjazdu bierze
     * nazwę wydarzenia, które ma własny ekran edycji).
     *
     * Pusty string CZYŚCI własną nazwę (wraca się do nazwy z licznika, gdy
     * jest, albo do generycznego opisu) — to jest zamierzone wyjście, nie błąd,
     * więc `null` w kolumnie, nie pusty string, żeby priorytet w rideName()
     * (`?:`) zadziałał tak samo dla „nigdy nie ustawione" i „wyczyszczone".
     *
     * @return bool false = przejazd nie istnieje, nie należy do usera, albo nie jest solo
     */
    public static function rename(int $activityId, int $userId, string $name): bool
    {
        if (self::findSoloForUser($activityId, $userId) === null) {
            return false;
        }

        $name = trim($name);
        $stmt = Database::connection()->prepare(
            'UPDATE rider_activities SET name = :name WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'name'    => $name !== '' ? mb_substr($name, 0, self::NAME_MAX_LENGTH) : null,
            'id'      => $activityId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * POWIĄZANIE PRZEJAZDU SOLO Z WYJAZDEM (zgłoszenie usera: „a nóż widelec
     * można połączyć jakiś z eventem").
     *
     * Co się tu naprawdę dzieje: ten sam plik GPX przestaje być przejazdem
     * „znikąd", a staje się WŁASNYM ŚLADEM uczestnika na tym turnusie — czyli
     * dokładnie tym, co powstaje przy wgrywaniu pliku na stronie wyjazdu
     * (`TrackController::upload`). Dlatego droga jest ta sama:
     * `EditionTrack::attach()` + `EventAttendance::declare()`, a nie własny,
     * równoległy zapis.
     *
     * DLACZEGO PRZEJAZD SOLO ZNIKA. Gdyby został, ten sam ślad liczyłby się
     * DWA RAZY: raz jako solo, raz jako przejazd z turnusu (kilometry i punkty
     * za jazdę; pola i tak są odkrywane raz, ale suma dystansu byłaby
     * nieprawdziwa). Kasujemy PRZED podpięciem śladu, żeby odkrycia policzyły
     * się na nowo od czystego stanu — inaczej nowy przejazd zobaczyłby własne
     * pola jako „już odkryte" i pokazałby zero nowych.
     *
     * PLIK ZOSTAJE NIETKNIĘTY na dysku — przejmuje go `edition_tracks`.
     *
     * @return string kod wyniku: ok | brak-przejazdu | brak-zapisu | nie-bylem | brak-pliku
     */
    public static function linkSoloToEdition(int $activityId, int $userId, int $editionId): string
    {
        $activity = self::findSoloForUser($activityId, $userId);
        if ($activity === null || empty($activity['gpx_url'])) {
            return 'brak-przejazdu';
        }

        // Ten sam warunek co przy wgrywaniu pliku na stronie wyjazdu:
        // POTWIERDZONY ZAPIS. Ślad do cudzego wyjazdu, na którym się nie było,
        // nie ma sensu — i nie wolno go dorobić przez ten ekran.
        $state = EventAttendance::forEditionAndUser($editionId, $userId);
        if ($state === null) {
            return 'brak-zapisu';
        }

        // Świadome „nie dojechałem" jest odpowiedzią człowieka i nie kasujemy
        // jej po cichu. Bez potwierdzonej obecności przejazd z turnusu w ogóle
        // nie powstanie, więc powiązanie zabrałoby odkrycia i nie dało nic
        // w zamian — lepiej odmówić i powiedzieć dlaczego.
        if ($state['attended'] === false) {
            return 'nie-bylem';
        }

        $path = CORE_PATH . '/..' . $activity['gpx_url'];
        if (!is_file($path)) {
            return 'brak-pliku';
        }

        $distance = EditionTrack::distanceFromFile($path);
        self::deleteActivity((int) $activity['id'], $userId);

        EditionTrack::attach(
            $editionId,
            $userId,
            (string) $activity['gpx_url'],
            // Własna nazwa (migr. 080), gdy ją nadał — inaczej generyczny opis
            // jak dotąd. Bez tego powiązanie z wyjazdem gubiłoby nazwę, którą
            // ktoś świadomie ustawił.
            (string) ($activity['name'] ?: ('Przejazd solo z ' . (string) $activity['ride_date'])),
            $distance,
            $userId
        );

        // Powiązanie JEST deklaracją obecności — mocniejszą niż kliknięcie
        // „byłem", bo popartą śladem. Świadomej odpowiedzi nie nadpisujemy
        // (przypadek `false` odpadł wyżej), a przy `true` przejazd został już
        // przeliczony wewnątrz attach() → resyncForEdition().
        if ($state['attended'] === null) {
            EventAttendance::declare($state['rsvpId'], true, $userId, false);
        }

        return 'ok';
    }

    // ---------------------------------------------------------------
    // Kroki zapisu
    // ---------------------------------------------------------------

    /** @param int[] $cellIds @return int[] pola, których ten user jeszcze nie ma */
    private static function filterUndiscovered(int $userId, array $cellIds): array
    {
        $db = Database::connection();
        $known = [];
        foreach (array_chunk($cellIds, self::CHUNK) as $chunk) {
            $list = implode(',', array_map('intval', $chunk));
            $stmt = $db->query(
                'SELECT cell_id FROM discovery_cells
                  WHERE user_id = ' . $userId . ' AND cell_id IN (' . $list . ')'
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $known[(int) $id] = true;
            }
        }
        return array_values(array_filter($cellIds, fn($id) => !isset($known[$id])));
    }

    /** @param int[] $cellIds @return array<int,int> cell_id => ilu rowerzystów przed nami */
    private static function ridersBefore(array $cellIds): array
    {
        $db = Database::connection();
        $counts = array_fill_keys($cellIds, 0);
        foreach (array_chunk($cellIds, self::CHUNK) as $chunk) {
            $list = implode(',', array_map('intval', $chunk));
            $stmt = $db->query(
                'SELECT cell_id, riders_count FROM discovery_cell_totals WHERE cell_id IN (' . $list . ')'
            );
            foreach ($stmt->fetchAll() as $row) {
                $counts[(int) $row['cell_id']] = (int) $row['riders_count'];
            }
        }
        return $counts;
    }

    private static function insertDiscoveries(int $userId, array $cellIds, int $activityId): void
    {
        $db = Database::connection();
        foreach (array_chunk($cellIds, self::CHUNK) as $chunk) {
            $values = [];
            foreach ($chunk as $cellId) {
                $values[] = '(' . $userId . ',' . (int) $cellId . ',' . $activityId . ',NOW())';
            }
            // INSERT IGNORE, a nie sprawdzanie w kodzie: klucz główny
            // (user_id, cell_id) JEST regułą "pierwszy raz i tylko pierwszy raz".
            $db->exec(
                'INSERT IGNORE INTO discovery_cells (user_id, cell_id, activity_id, discovered_at)
                 VALUES ' . implode(',', $values)
            );
        }
    }

    /**
     * Bonus za udział w konkretnym wydarzeniu (migr. 044). Wydarzenie bez
     * ustawionego `point_bonus` nie daje nic — brief chce, żeby model
     * UMOŻLIWIAŁ taką nagrodę, nie żeby dostawało ją każde wydarzenie.
     *
     * source_id = TURNUS, nie wydarzenie. Wybór świadomy: cykliczny wyścig
     * przejechany trzy lata z rzędu to trzy prawdziwe starty i ma zapłacić
     * trzy razy. Gdyby przy jakimś wydarzeniu okazało się to farmieniem,
     * zmiana na identyfikator wydarzenia jest jedną linijką — ale wtedy
     * pierwszy start zabiera nagrodę na zawsze, więc domyślnie tak nie robimy.
     * Prostszą dźwignią jest po prostu nieustawianie bonusu na cyklicznym
     * wydarzeniu.
     */
    private static function awardEventBonus(int $userId, ?int $editionId, int $activityId, ?string $rideDate): int
    {
        if ($editionId === null) {
            return 0;
        }
        $stmt = Database::connection()->prepare('
            SELECT e.point_bonus, e.title
              FROM event_editions ed
              JOIN events e ON e.id = ed.event_id
             WHERE ed.id = :edition_id
        ');
        $stmt->execute(['edition_id' => $editionId]);
        $row = $stmt->fetch();
        if (!$row || $row['point_bonus'] === null) {
            return 0;
        }

        $bonus = (int) $row['point_bonus'];
        PointLedger::award(
            $userId, PointLedger::SOURCE_EVENT, (string) $editionId, $bonus,
            $activityId, $rideDate, $row['title']
        );
        return $bonus;
    }

    /**
     * Punkty za przejazd po zastosowaniu dziennego limitu.
     *
     * Limit jest domyślnie WYŁĄCZONY (core/discovery.php, 'daily_cap' => null)
     * i taki ma zostać, dopóki nie zobaczymy realnego nadużycia — brief
     * dopuszcza powtarzalne punkty za prawdziwą jazdę, a limit „na wszelki
     * wypadek" karałby przede wszystkim ludzi, którzy po prostu dużo jeżdżą.
     *
     * Liczony po DACIE PRZEJAZDU, nie po dacie zapisu: dziesięć śladów
     * wgranych jednego wieczoru, ale z dziesięciu różnych dni, to dziesięć
     * uczciwych przejazdów, a nie farmienie.
     */
    private static function cappedRidePoints(int $userId, ?string $rideDate, float $distanceKm, int $elevationGainM): int
    {
        $points = DiscoveryScoring::forRide($distanceKm, $elevationGainM);
        $cap = DiscoveryScoring::dailyRideCap();
        if ($cap === null || $rideDate === null || $points <= 0) {
            return $points;
        }

        $stmt = Database::connection()->prepare('
            SELECT COALESCE(SUM(points), 0)
              FROM point_transactions
             WHERE user_id = :user_id AND source = :source AND ride_date = :ride_date
        ');
        $stmt->execute([
            'user_id'   => $userId,
            'source'    => PointLedger::SOURCE_RIDE,
            'ride_date' => $rideDate,
        ]);
        $already = (int) $stmt->fetchColumn();

        return max(0, min($points, $cap - $already));
    }

    /** Które pola dotknął ten przejazd — pełna lista, z powtórzeniami włącznie. */
    private static function recordTouchedCells(int $activityId, array $cellIds): void
    {
        $db = Database::connection();
        foreach (array_chunk($cellIds, self::CHUNK) as $chunk) {
            $values = [];
            foreach ($chunk as $cellId) {
                $values[] = '(' . $activityId . ',' . (int) $cellId . ')';
            }
            $db->exec(
                'INSERT IGNORE INTO rider_activity_cells (activity_id, cell_id) VALUES ' . implode(',', $values)
            );
        }

        // Regiony przejazdu (migr. 074) — wyprowadzone z tych samych pól, tym
        // samym wzorcem co KnownRoute::syncRegions. Ten przejazd nie miał ich
        // wcześniej (activity_id jest nowe), więc bez DELETE — INSERT IGNORE
        // wystarczy.
        $db->prepare('
            INSERT IGNORE INTO rider_activity_regions (activity_id, region_item_id)
            SELECT DISTINCT :id1, rc.region_item_id
              FROM rider_activity_cells rac
              JOIN region_cells rc ON rc.cell_id = rac.cell_id
             WHERE rac.activity_id = :id2
        ')->execute(['id1' => $activityId, 'id2' => $activityId]);
    }

    /**
     * Agregat wspólnej mapy. Dwa liczniki rosną w różnym rytmie i to jest
     * sedno różnicy między warstwami:
     *   passes_count — przy KAŻDYM przejeździe przez pole,
     *   riders_count — tylko gdy to pole jest dla tej osoby nowe.
     *
     * @param int[] $cellIds wszystkie pola dotknięte przejazdem
     * @param int[] $newCells podzbiór odkryty po raz pierwszy przez tę osobę
     */
    private static function bumpTotals(int $userId, array $cellIds, array $newCells): void
    {
        $db = Database::connection();
        $isNew = array_fill_keys($newCells, true);

        foreach (array_chunk($cellIds, self::CHUNK) as $chunk) {
            $values = [];
            foreach ($chunk as $cellId) {
                [, $q, $r] = DiscoveryGrid::decode((int) $cellId);
                // Rodzice na każdym poziomie agregacji (migr. 047) — liczone
                // TUTAJ, przy zapisie, bo przynależność do heksagonu nadrzędnego
                // rozstrzyga geometria siatki, a tej nie da się wyrazić w SQL-u
                // (patrz DiscoveryGrid::parentsFor).
                $p = DiscoveryGrid::parentsFor((int) $cellId);
                $riders = isset($isNew[$cellId]) ? 1 : 0;
                // first_user_id ma sens tylko dla pola faktycznie odkrytego —
                // przy przejeździe przez cudzy teren wiersz i tak już istnieje,
                // a gdyby nie istniał, to pole JEST nowe.
                $values[] = '(' . (int) $cellId . ',' . $q . ',' . $r . ','
                    . $p[3] . ',' . $p[2] . ',' . $p[1] . ',' . $p[0] . ','
                    . $riders . ',1,' . $userId . ',NOW(),NOW())';
            }
            // first_user_id i first_seen_at celowo NIE są w klauzuli UPDATE —
            // "kto tu był pierwszy" ustawia się przy powstaniu wiersza i nigdy
            // później. Kolumny parent_* też nie: rodzic pola jest niezmienny,
            // dopóki nie zmieni się rozmiar siatki — a wtedy i tak wszystkie
            // identyfikatory pól tracą ważność.
            $db->exec(
                'INSERT INTO discovery_cell_totals
                    (cell_id, cell_q, cell_r, parent_res3, parent_res2, parent_res1, parent_res0,
                     riders_count, passes_count, first_user_id, first_seen_at, last_seen_at)
                 VALUES ' . implode(',', $values) . '
                 ON DUPLICATE KEY UPDATE
                    riders_count = riders_count + VALUES(riders_count),
                    passes_count = passes_count + 1,
                    last_seen_at = VALUES(last_seen_at)'
            );
        }
    }

    // ---------------------------------------------------------------
    // Kontekst zapisu
    // ---------------------------------------------------------------

    private static function rsvpContext(int $rsvpId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT r.user_id, r.event_id, r.edition_id, ed.start_date
              FROM event_rsvps r
              JOIN event_editions ed ON ed.id = r.edition_id
             WHERE r.id = :rsvp_id
        ');
        $stmt->execute(['rsvp_id' => $rsvpId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }


    /**
     * Przelicza od nowa odkrycia na turnusie — po wgraniu albo usunięciu śladu.
     *
     * $userId = null oznacza ślad Z IMPREZY, więc dotyczy WSZYSTKICH, którzy
     * potwierdzili obecność. Ustawione — tylko tej osoby.
     *
     * Zawsze przez usunięcie i ponowne policzenie, nigdy przez dokładanie:
     * ślad własny wgrany po fakcie ZASTĘPUJE ślad z imprezy, a nie dokłada się
     * do niego, więc pola policzone wcześniej mogą być teraz nieprawdziwe.
     * Punkty przeliczają się przy okazji, bo powstają razem z przejazdem.
     */
    public static function resyncForEdition(int $editionId, ?int $userId): void
    {
        $sql = "
            SELECT r.id
              FROM event_rsvps r
              JOIN event_attendance a ON a.rsvp_id = r.id AND a.attended = 1
             WHERE r.edition_id = :edition_id
        ";
        $params = ['edition_id' => $editionId];
        if ($userId !== null) {
            $sql .= ' AND r.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $rsvpId) {
            self::removeForRsvp((int) $rsvpId);
            self::syncForRsvp((int) $rsvpId, true);
        }
    }
}
