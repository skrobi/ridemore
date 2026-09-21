<?php
// core/Models/PointLedger.php
// Etap 8A — RIDEMORE POINTS. Jedyne wejście do punktów.
//
// Każde naliczenie jest osobnym wpisem w point_transactions (migr. 043).
// Nic w aplikacji nie ma prawa zwiększać wyniku użytkownika inaczej niż przez
// award() — suma bez historii nie odpowiada na pytanie „za co", a to pytanie
// zadaje i użytkownik, i admin.
//
// IDEMPOTENCJA NIE JEST TU NAPISANA
// ---------------------------------
// award() nie sprawdza, czy naliczenie już istnieje. Robi INSERT IGNORE i
// pozwala odbić się o klucz unikalny (user_id, source, source_id). To
// świadome: sprawdzenie w kodzie działa dopóki wszyscy pamiętają je wykonać,
// klucz działa zawsze. Ten sam wzorzec co „pierwszy raz i tylko pierwszy raz"
// w discovery_cells — i to właśnie on pozwolił uprościć naliczanie progów
// tras: KnownRoute::syncProgress() liczy dziś stan OBECNY i przyznaje
// wszystko, co się należy, zamiast porównywać procent sprzed i po przejeździe
// (poprzednia wersja była przez to nieidempotentna).
//
// CO WOLNO POWTÓRZYĆ, DECYDUJE source_id
// --------------------------------------
//   RIDE / DISCOVERY / EXPLORATION -> id przejazdu : nowy przejazd = nowe punkty,
//   TRAIL_THRESHOLD                -> "trasa:próg" : raz w życiu,
//   TRAIL_COMPLETION               -> "trasa"      : raz w życiu,
//   EVENT                          -> id turnusu   : raz na turnus.
// Dlatego codzienna runda po tej samej pętli daje punkty za jazdę (bo to
// prawdziwy przejazd), ale nigdy drugi raz za odkrycie ani za trasę.
//
// SEPARACJA ŹRÓDŁA OD WARTOŚCI
// ----------------------------
// Tu nie ma i nie może pojawić się żadnej nazwy własnej ani liczby punktów.
// Ile jest warte dane zdarzenie, decyduje Models\DiscoveryScoring czytający
// core/discovery.php; ta klasa wyłącznie zapisuje gotowy wynik.
namespace Models;

use Core\Database;

class PointLedger
{
    public const SOURCE_RIDE             = 'RIDE';
    public const SOURCE_DISCOVERY        = 'DISCOVERY';
    public const SOURCE_EXPLORATION      = 'EXPLORATION';
    public const SOURCE_TRAIL_THRESHOLD  = 'TRAIL_THRESHOLD';
    public const SOURCE_TRAIL_COMPLETION = 'TRAIL_COMPLETION';
    public const SOURCE_EVENT            = 'EVENT';
    // SKARBY (migr. 054/055) — znalezienie punktu w terenie: zeskanowana
    // wlepka, potwierdzona lokalizacja albo ślad GPX przechodzący obok.
    // source_id to id wlepki, więc UNIQUE(user_id, source, source_id) sam
    // pilnuje, że tej samej nie da się zaliczyć dwa razy.
    public const SOURCE_TREASURE_FOUND   = 'TREASURE_FOUND';

    // Podpisy dla człowieka — w JEDNYM miejscu, żeby trzy widoki nie trzymały
    // trzech własnych tłumaczeń tych samych stałych.
    //
    // NAZWY ROZJEŻDŻAJĄ SIĘ ZE STAŁYMI I TAK MA BYĆ: stała `EXPLORATION` siedzi
    // w kluczu unikalnym point_transactions i w całej historii naliczeń, więc
    // jest niezmienna; podpis „Nowy teren" jest decyzją produktową i wolno go
    // zmieniać bez ruszania ani jednego wiersza w bazie. To jest cały powód,
    // dla którego ta mapa istnieje osobno od stałych.
    private const LABELS = [
        self::SOURCE_RIDE             => 'Przejazd',
        self::SOURCE_DISCOVERY        => 'Odkrycia',
        self::SOURCE_EXPLORATION      => 'Nowy teren',
        self::SOURCE_TRAIL_THRESHOLD  => 'Znana trasa',
        self::SOURCE_TREASURE_FOUND   => 'Skarb',
        self::SOURCE_TRAIL_COMPLETION => 'Ukończona trasa',
        self::SOURCE_EVENT            => 'Wydarzenie',
    ];

    public static function label(string $source): string
    {
        return __(self::LABELS[$source] ?? $source);
    }

    /**
     * Zapisuje naliczenie. Zwraca true, gdy wpis faktycznie powstał, i false,
     * gdy takie zdarzenie było już policzone — wołający może na tym oprzeć
     * komunikat, ale NIE MUSI niczego sprawdzać przed wywołaniem.
     *
     * $sourceId to identyfikator zdarzenia w obrębie źródła (patrz nagłówek).
     * $description jest zamrażany w chwili przyznania: nazwa trasy może się
     * później zmienić, a wpis w historii ma zostać taki, jaki był.
     */
    public static function award(
        int $userId,
        string $source,
        string $sourceId,
        int $points,
        ?int $activityId = null,
        ?string $rideDate = null,
        ?string $description = null
    ): bool {
        // Zero punktów to nie zdarzenie. Próg wart 0 albo przejazd bez
        // dystansu nie mają zostawiać wiersza, który w historii wygląda jak
        // usterka („+0 Przejazd").
        if ($points === 0) {
            return false;
        }

        $stmt = Database::connection()->prepare('
            INSERT IGNORE INTO point_transactions
                (user_id, source, source_id, points, activity_id, ride_date, description)
            VALUES (:user_id, :source, :source_id, :points, :activity_id, :ride_date, :description)
        ');
        $stmt->execute([
            'user_id'     => $userId,
            'source'      => $source,
            'source_id'   => $sourceId,
            'points'      => $points,
            'activity_id' => $activityId,
            'ride_date'   => $rideDate,
            'description' => $description === null ? null : mb_substr($description, 0, 190),
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Wynik użytkownika — suma wszystkiego, co kiedykolwiek naliczono. */
    public static function totalForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(points), 0) FROM point_transactions WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Rozbicie wyniku na źródła — „za co konkretnie".
     * @return array<string,int> source => suma punktów, malejąco
     */
    public static function breakdownForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT source, SUM(points) AS total
              FROM point_transactions
             WHERE user_id = :user_id
             GROUP BY source
             ORDER BY total DESC
        ');
        $stmt->execute(['user_id' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['source']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * Historia naliczeń — lista „ostatnie punkty".
     *
     * Sortowanie po ride_date, nie po created_at: przejazd sprzed miesiąca
     * policzony wczoraj (ślad wgrany po czasie) należy do tamtego miesiąca.
     * W obrębie JEDNEGO przejazdu kolejność rosnąca po id, czyli taka, w jakiej
     * punkty się naliczyły: najpierw sama jazda, potem odkryte pola, na końcu
     * premia za nowy teren. To jest kolejność PRZYCZYNOWA — premia czyta się
     * jako dopisek do odkryć, a nie jako osobna, największa pozycja na górze
     * (uwaga usera 2026-08-13: „nie bardzo rozumiem tę statystykę").
     * Przejazdy między sobą dalej od najnowszego.
     */
    public static function recentForUser(int $userId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare('
            SELECT source, source_id, points, activity_id, ride_date, description, created_at
              FROM point_transactions
             WHERE user_id = :user_id
             ORDER BY COALESCE(ride_date, DATE(created_at)) DESC, activity_id DESC, id ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Suma naliczeń z jednego dnia — stopka „+240 pkt łącznie dzisiaj"
     * z panelu aktywności na mapie. Po dacie PRZEJAZDU, nie zapisu, tak jak
     * cała reszta odczytów tego rejestru.
     */
    public static function pointsOnDate(int $userId, string $date): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COALESCE(SUM(points), 0)
              FROM point_transactions
             WHERE user_id = :user_id AND ride_date = :ride_date
        ');
        $stmt->execute(['user_id' => $userId, 'ride_date' => $date]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Punkty zdobyte w ostatnich N dniach — podpis „+1 250 w tym tygodniu"
     * pod kaflem wyniku.
     */
    public static function pointsSince(int $userId, int $days): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COALESCE(SUM(points), 0)
              FROM point_transactions
             WHERE user_id = :user_id
               AND COALESCE(ride_date, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
        ');
        $stmt->execute(['user_id' => $userId, 'days' => $days]);
        return (int) $stmt->fetchColumn();
    }

    /** Naliczenia jednego przejazdu — pod podsumowanie po wyjeździe. */
    public static function forActivity(int $activityId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT source, points, description
              FROM point_transactions
             WHERE activity_id = :activity_id
             ORDER BY id ASC
        ');
        $stmt->execute(['activity_id' => $activityId]);
        return $stmt->fetchAll();
    }

    public static function sumForActivity(int $activityId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(points), 0) FROM point_transactions WHERE activity_id = :activity_id'
        );
        $stmt->execute(['activity_id' => $activityId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Kasuje naliczenia zdarzeń, których warunek przestał być spełniony —
     * dziś: progi trasy, gdy pola przestały ją pokrywać (usunięty ślad,
     * cofnięta obecność).
     *
     * Punkty przy PRZEJEŹDZIE kasują się same przez ON DELETE CASCADE i nie
     * potrzebują tej metody. Naliczenia progów trasy nie wiszą na przejeździe
     * w sposób, który dałoby się skasować kaskadą — ten sam próg mógł zostać
     * osiągnięty innym wyjazdem — więc muszą być zdejmowane jawnie.
     *
     * @param string[] $sourceIds
     */
    public static function revoke(int $userId, string $source, array $sourceIds): int
    {
        if (empty($sourceIds)) {
            return 0;
        }
        $db = Database::connection();
        $placeholders = [];
        $params = ['user_id' => $userId, 'source' => $source];
        foreach (array_values($sourceIds) as $i => $sid) {
            $placeholders[] = ':sid' . $i;
            $params['sid' . $i] = $sid;
        }

        $stmt = $db->prepare('
            DELETE FROM point_transactions
             WHERE user_id = :user_id AND source = :source
               AND source_id IN (' . implode(',', $placeholders) . ')
        ');
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
