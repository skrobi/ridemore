<?php
// core/Models/EditionTrack.php
// Ślad z ODBYTEGO wyjazdu (migr. 042) — jedyne źródło, z którego Discovery
// liczy odkryte pola.
//
// Dwa rodzaje w jednej tabeli, bo poza tym, kogo dotyczą, obsługa jest
// identyczna (ten sam upload, parser i liczenie pól):
//   - ŚLAD Z IMPREZY (user_id = null) — wgrywa organizator po wyjeździe,
//     liczy się każdemu, kto potwierdził obecność na tym turnusie,
//   - ŚLAD WŁASNY (user_id ustawione) — wgrywa uczestnik, liczy się tylko jemu
//     i MA PIERWSZEŃSTWO przed śladem zbiorowym.
//
// Pierwszeństwo śladu własnego jest sednem całej zmiany: gdyby liczyły się
// oba, ktoś, kto skrócił trasę i uczciwie wgrał swój krótszy ślad, i tak
// dostałby całą trasę ze śladu organizatora — czyli wracałby problem, dla
// którego ta tabela powstała.
namespace Models;

use Core\Database;
use Models\TileCache;
use Utils\DiscoveryGrid;
use Utils\Gpx;

class EditionTrack
{
    /** Wszystkie ślady turnusu — pod ekran organizatora. */
    public static function forEdition(int $editionId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT t.*, u.name AS owner_name
              FROM edition_tracks t
              LEFT JOIN users u ON u.id = t.user_id
             WHERE t.edition_id = :edition_id
             ORDER BY (t.user_id IS NOT NULL), t.id ASC
        ');
        $stmt->execute(['edition_id' => $editionId]);
        return $stmt->fetchAll();
    }

    /** Ślady wgrane przez konkretną osobę na tym turnusie. */
    public static function forEditionAndUser(int $editionId, int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT * FROM edition_tracks
             WHERE edition_id = :edition_id AND user_id = :user_id
             ORDER BY id ASC
        ');
        $stmt->execute(['edition_id' => $editionId, 'user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Ślady, które opisują przejazd TEJ osoby na TYM turnusie — czyli to, z
     * czego Discovery policzy jej pola.
     *
     * Zwraca własne ślady, jeśli je wgrała; w przeciwnym razie ślad z imprezy.
     * Pusta tablica = nie ma czym potwierdzić przejazdu i pól się nie nalicza.
     */
    public static function effectiveFor(int $editionId, int $userId): array
    {
        $own = self::forEditionAndUser($editionId, $userId);
        if ($own) {
            return $own;
        }
        $stmt = Database::connection()->prepare('
            SELECT * FROM edition_tracks
             WHERE edition_id = :edition_id AND user_id IS NULL
             ORDER BY id ASC
        ');
        $stmt->execute(['edition_id' => $editionId]);
        return $stmt->fetchAll();
    }

    /**
     * To samo pytanie co `effectiveFor`, tylko zadane o CAŁĄ historię jednej
     * osoby: które ślady opisują wszystkie jej przejazdy. Jedno zapytanie
     * zamiast pętli po turnusach — profil rysuje z tego mapę.
     *
     * Pierwszeństwo śladu własnego jest tu wyrażone przez NOT EXISTS, czyli
     * dokładnie tak samo jak w `effectiveFor` (tam: „jak są własne, bierz
     * własne"). Gdyby te dwie reguły się rozjechały, mapa na profilu
     * pokazywałaby inny przebieg niż ten, z którego naliczono pola — a to jest
     * właśnie ta niespójność, dla której ta metoda powstała.
     */
    public static function effectiveForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT t.id, t.edition_id, t.gpx_url, t.label, t.distance_km, t.user_id,
                   ed.start_date, e.slug AS event_slug, e.title AS event_title
              FROM event_attendance a
              JOIN event_rsvps r ON r.id = a.rsvp_id
              JOIN event_editions ed ON ed.id = r.edition_id
              JOIN events e ON e.id = ed.event_id
              JOIN edition_tracks t ON t.edition_id = ed.id AND (
                        t.user_id = r.user_id
                     OR (t.user_id IS NULL AND NOT EXISTS (
                            SELECT 1 FROM edition_tracks o
                             WHERE o.edition_id = ed.id AND o.user_id = r.user_id))
                   )
             WHERE r.user_id = :user_id AND a.attended = 1
             ORDER BY ed.start_date DESC, t.id ASC
        ');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM edition_tracks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Czy turnus ma w ogóle ślad z imprezy (pod komunikaty w widoku). */
    public static function hasEventTrack(int $editionId): bool
    {
        $stmt = Database::connection()->prepare('
            SELECT 1 FROM edition_tracks WHERE edition_id = :edition_id AND user_id IS NULL LIMIT 1
        ');
        $stmt->execute(['edition_id' => $editionId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zapis śladu + NATYCHMIASTOWE przeliczenie odkryć tych, których dotyczy.
     *
     * Przeliczanie wisi TUTAJ, nie w kontrolerze, z tego samego powodu co
     * peleton w EventAttendance::declare(): ślad wgrany po tym, jak ludzie już
     * potwierdzili obecność, to normalna kolejność zdarzeń, a nie wyjątek —
     * i nie może zależeć od tego, czy ktoś pamiętał dopisać wywołanie przy
     * kolejnym sposobie dodawania pliku.
     *
     * @return int id wgranego śladu
     */
    public static function attach(
        int $editionId,
        ?int $userId,
        string $gpxUrl,
        ?string $label,
        float $distanceKm,
        ?int $uploadedByUserId
    ): int {
        $db = Database::connection();
        $stmt = $db->prepare('
            INSERT INTO edition_tracks (edition_id, user_id, gpx_url, label, distance_km, uploaded_by_user_id)
            VALUES (:edition_id, :user_id, :gpx_url, :label, :distance, :uploaded_by)
        ');
        $stmt->execute([
            'edition_id'  => $editionId,
            'user_id'     => $userId,
            'gpx_url'     => $gpxUrl,
            'label'       => $label,
            'distance'    => $distanceKm,
            'uploaded_by' => $uploadedByUserId,
        ]);
        $id = (int) $db->lastInsertId();

        // Ślad własny zmienia sytuację jednej osoby; ślad z imprezy — wszystkich
        // obecnych na turnusie (i to nie tylko przez dołożenie pól: komu ten
        // ślad dotąd zastępował własny, ten musi zostać przeliczony od nowa).
        RiderActivity::resyncForEdition($editionId, $userId);

        // KAFLE (migr. 051) — nowy ślad musi zniknąć ze wszystkich map, na
        // których się pojawia. Zaczep wisi TUTAJ, a nie w kontrolerze, z tego
        // samego powodu co przeliczanie odkryć linijkę wyżej: wgranie pliku
        // dzieje się na kilka sposobów (organizator, uczestnik, import), a
        // unieważnienie nie może zależeć od tego, czy ktoś pamiętał je dopisać
        // przy kolejnym z nich.
        TileCache::invalidateForEdition($editionId, $gpxUrl);

        return $id;
    }

    /**
     * Czy wgrany ślad pokrywa się z trasą ZAPOWIADANĄ tego turnusu.
     *
     * Porównanie idzie po tej samej siatce heksów, z której liczą się odkrycia
     * (Utils\DiscoveryGrid) — nie po odległości punkt-punkt. Pola są tu
     * naturalną jednostką tolerancji: ~500 m to dokładnie tyle, ile wynosi
     * rozsądny margines na objazd, GPS-owy szum i inne miejsce startu.
     *
     * Zwraca `null`, gdy turnus NIE MA trasy referencyjnej — nie ma wtedy czego
     * porównywać i ślad jest jedynym źródłem prawdy o przebiegu.
     *
     * @return ?array{pct:int, matched:int, total:int}
     */
    public static function coverageAgainstPlanned(int $editionId, string $absoluteGpxPath): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT s.gpx_url FROM event_stages s
              JOIN event_editions ed ON ed.event_id = s.event_id
             WHERE ed.id = :edition_id AND s.gpx_url IS NOT NULL
             UNION
            SELECT v.gpx_url FROM event_route_variants v
              JOIN event_editions ed2 ON ed2.event_id = v.event_id
             WHERE ed2.id = :edition_id2 AND v.gpx_url IS NOT NULL
        ");
        $stmt->execute(['edition_id' => $editionId, 'edition_id2' => $editionId]);
        $plannedUrls = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!$plannedUrls) {
            return null;
        }

        // Suma pól WSZYSTKICH tras planowanych turnusu: wielodniówka ma etap na
        // dzień, a wydarzenie z wariantami kilka pętli — uczestnik mógł jechać
        // dowolną z nich i każda jest zgodna z zapowiedzią.
        $plannedCells = [];
        foreach ($plannedUrls as $url) {
            $path = CORE_PATH . '/..' . $url;
            if (!is_file($path)) {
                continue;
            }
            try {
                foreach (DiscoveryGrid::cellsForTrack(Gpx::parse($path)['points']) as $cell) {
                    $plannedCells[$cell] = true;
                }
            } catch (\Throwable $e) {
                // Nieczytelna trasa planowana nie może blokować uczestnika —
                // traktujemy ją tak, jakby jej nie było.
                continue;
            }
        }
        if (!$plannedCells) {
            return null;
        }

        $uploadedCells = DiscoveryGrid::cellsForTrack(Gpx::parse($absoluteGpxPath)['points']);
        if (!$uploadedCells) {
            return ['pct' => 0, 'matched' => 0, 'total' => count($plannedCells)];
        }

        // Mianownikiem jest WGRANY ślad, nie planowany: pytamy „ile z tego, co
        // przejechałeś, leży na zapowiedzianej trasie", a nie „ile zapowiedzi
        // pokryłeś". Ktoś, kto uczciwie przejechał połowę trasy i zawrócił, ma
        // 100% zgodności — bo wszystko, co zrobił, było NA trasie. Odwrotny
        // mianownik karałby za skrócenie wyjazdu, czego ta walidacja nie dotyczy.
        $matched = 0;
        foreach ($uploadedCells as $cell) {
            if (isset($plannedCells[$cell])) {
                $matched++;
            }
        }

        return [
            'pct'     => (int) round(100 * $matched / count($uploadedCells)),
            'matched' => $matched,
            'total'   => count($uploadedCells),
        ];
    }

    /**
     * Próg zgodności, od którego ślad sam potwierdza obecność.
     *
     * 60%, a nie 90%: ślad zaczyna się pod domem i kończy pod domem, więc dojazd
     * na miejsce zbiórki i powrót to normalna, uczciwa część pliku, która NIGDY
     * nie leży na trasie wydarzenia. Wysoki próg karałby dokładnie tych, którzy
     * wgrywają surowy plik z licznika, zamiast go przycinać.
     */
    public const AUTO_ATTENDANCE_MIN_PCT = 60;

    /** Usunięcie śladu + przeliczenie odkryć, które z niego wynikały. */
    public static function remove(int $id): void
    {
        $track = self::find($id);
        if ($track === null) {
            return;
        }
        Database::connection()->prepare('DELETE FROM edition_tracks WHERE id = :id')->execute(['id' => $id]);

        // Kafle unieważniamy PRZED skasowaniem pliku: Models\GpxGeometry liczy
        // hash z zawartości, a po unlink() nie byłoby z czego. Sama geometria
        // w bazie zostaje — jest cache'em pliku, nie kopią śladu, i gdyby ten
        // sam plik wrócił, policzyłaby się drugi raz bez potrzeby.
        TileCache::invalidateForEdition((int) $track['edition_id'], (string) $track['gpx_url']);

        $path = CORE_PATH . '/..' . $track['gpx_url'];
        if (is_file($path)) {
            unlink($path);
        }

        RiderActivity::resyncForEdition(
            (int) $track['edition_id'],
            $track['user_id'] === null ? null : (int) $track['user_id']
        );
    }

    /**
     * Dystans ze śladu — bierzemy go z pliku, nie od wgrywającego. Liczba ma
     * opisywać to, co jest w GPX-ie, a nie to, co ktoś wpisał.
     * Zwraca 0 dla pliku, którego nie da się odczytać (sam upload i tak został
     * już zwalidowany przez Utils\Upload).
     */
    public static function distanceFromFile(string $absolutePath): float
    {
        try {
            return (float) Gpx::parse($absolutePath)['distanceKm'];
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
