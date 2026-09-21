<?php
// core/Models/DerivedPreference.php
// Etap 3 (preferencje) §4 — warstwa wynikająca. Odtwarzana W CAŁOŚCI przy
// każdym przeliczeniu (DELETE+INSERT per user), nigdy modyfikowana
// przyrostowo — dzięki temu zmiana wag/reguł w tym pliku nie wymaga migracji
// danych, tylko ponownego uruchomienia recomputeAll() (docs/etap3 §4).
namespace Models;

use Core\Database;
use PDO;

class DerivedPreference
{
    // Połowa wartości po ~12 miesiącach, okno 24 miesiące — docs/etap3 §4.
    private const HALF_LIFE_DAYS = 365;
    private const WINDOW_MONTHS = 24;

    // Wagi zdarzeń, w kolejności siły dowodu — docs/etap3 §4, tabela źródeł
    // sygnału. Anulowany (`anulowany`) świadomie pominięty (brak wagi w ogóle).
    private const WEIGHT_CREATED = 1.2;
    private const WEIGHT_ATTENDANCE = 1.0;
    private const WEIGHT_CONFIRMED = 0.8;
    private const WEIGHT_INTERESTED = 0.3;
    private const BONUS_RECAP = 0.4;
    private const BONUS_REVIEW = 0.3;
    private const BONUS_COMMENT = 0.2;
    private const REVIEW_MIN_RATING = 4;

    // Odrzucenie sugestii (§6) — dwustopniowe: słabe za pojedyncze
    // odrzucenie z powodem, pełne dopiero przy 3. odrzuceniu z TYM SAMYM
    // powodem w ciągu 90 dni (liczone z recommendation_dismissals).
    private const DISMISSAL_WEAK_PENALTY = 0.1;
    private const DISMISSAL_STRONG_PENALTY = 0.5;
    private const DISMISSAL_STREAK_THRESHOLD = 3;
    private const DISMISSAL_STREAK_WINDOW_DAYS = 90;

    // Tylko te dwa z czterech powodów odrzucenia mapują się na konkretną oś
    // (pozycję słownikową) w tym modelu danych — "zły termin" i "po prostu
    // nie" nie mają naturalnego odpowiednika w user_preference_signals
    // (daty nie są pozycją słownika), zostają zapisane w dzienniku wyłącznie
    // do analizy (docs/etap3 §8), bez wpływu na wagi.
    private const REASON_AXIS = ['nie_moje_tempo' => 'pace', 'za_daleko' => 'region'];

    // Przelicza warstwę wynikającą dla KAŻDEGO usera, który ma choć jedno
    // zdarzenie źródłowe w oknie 24 miesięcy. Wołane z cron.php. Zwraca
    // listę user_id przeliczonych (do zalogowania liczby w cron.php).
    public static function recomputeAll(): array
    {
        $pdo = Database::connection();
        $windowStart = date('Y-m-d', strtotime('-' . self::WINDOW_MONTHS . ' months'));

        $userIds = $pdo->prepare("
            SELECT DISTINCT user_id FROM (
                SELECT organizer_id AS user_id FROM events WHERE start_date >= :w1
                UNION
                SELECT r.user_id FROM event_rsvps r
                  JOIN event_editions ed ON ed.id = r.edition_id
                  JOIN events e ON e.id = ed.event_id
                 WHERE e.start_date >= :w2
                UNION
                SELECT rc.author_user_id AS user_id FROM event_recaps rc
                  JOIN events e ON e.id = rc.event_id WHERE e.start_date >= :w3
                UNION
                SELECT rv.reviewer_user_id AS user_id FROM event_reviews rv
                  JOIN events e ON e.id = rv.event_id WHERE e.start_date >= :w4
                UNION
                SELECT c.user_id FROM event_comments c
                  JOIN events e ON e.id = c.event_id WHERE e.start_date >= :w5
            ) t
        ");
        $userIds->execute(['w1' => $windowStart, 'w2' => $windowStart, 'w3' => $windowStart, 'w4' => $windowStart, 'w5' => $windowStart]);
        $ids = $userIds->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $userId) {
            self::recomputeForUser((int) $userId, $windowStart);
        }
        return array_map('intval', $ids);
    }

    private static function recomputeForUser(int $userId, string $windowStart): void
    {
        $pdo = Database::connection();
        $eventWeights = self::gatherEventWeights($pdo, $userId, $windowStart);
        if (empty($eventWeights)) {
            self::persist($userId, [], null, null, 0.0, 0);
            return;
        }

        $meta = self::eventMeta($pdo, array_keys($eventWeights));

        $signals = [];
        $totalWeight = 0.0;
        $distanceWeightedSum = 0.0;
        $distanceWeightSum = 0.0;
        $groupSizeWeightedSum = 0.0;

        foreach ($eventWeights as $eventId => $weight) {
            $m = $meta[$eventId] ?? null;
            if ($m === null) {
                continue;
            }
            foreach ($m['bikeTypeItemIds'] as $itemId) {
                $signals[$itemId] = ($signals[$itemId] ?? 0.0) + $weight;
            }
            if ($m['paceGroupItemId'] !== null) {
                $signals[$m['paceGroupItemId']] = ($signals[$m['paceGroupItemId']] ?? 0.0) + $weight;
            }
            if ($m['difficultyItemId'] !== null) {
                $signals[$m['difficultyItemId']] = ($signals[$m['difficultyItemId']] ?? 0.0) + $weight;
            }
            // Event może mieć kilka regionów (migr. 074) — każdy dostaje pełną
            // wagę, tym samym wzorcem co bikeTypeItemIds wyżej.
            foreach ($m['regionItemIds'] as $regionItemId) {
                $signals[$regionItemId] = ($signals[$regionItemId] ?? 0.0) + $weight;
            }
            if ($m['distanceKm'] > 0) {
                $distanceWeightedSum += $m['distanceKm'] * $weight;
                $distanceWeightSum += $weight;
            }
            $groupSizeWeightedSum += ($m['confirmedTotal'] + 1) * $weight;
            $totalWeight += $weight;
        }

        self::applyDismissalPenalties($pdo, $userId, $meta, $signals);
        $normalized = self::normalizePerDictionary($pdo, $signals);

        $avgDistance = $distanceWeightSum > 0 ? round($distanceWeightedSum / $distanceWeightSum, 2) : null;
        $avgGroupSize = $totalWeight > 0 ? round($groupSizeWeightedSum / $totalWeight, 2) : null;

        self::persist($userId, $normalized, $avgDistance, $avgGroupSize, $totalWeight, count($eventWeights));
    }

    // Zbiera wagę BAZOWĄ (0 jeśli brak sygnału) per wydarzenie, biorąc
    // NAJSILNIEJSZY zastosowany rodzaj dowodu (nie sumę — stworzenie i
    // potwierdzony zapis tego samego wydarzenia to ten sam fakt, nie dwa
    // niezależne), a potem DODAJE bonusy (relacja/opinia/komentarz), bo te są
    // odrębnymi śladami zaangażowania ponad sam udział. Na końcu mnoży przez
    // wygaszanie liczone RAZ dla całego wydarzenia (ten sam czynnik dla
    // wszystkich składników sumy — matematycznie równoważne wygaszaniu
    // każdego z osobna).
    private static function gatherEventWeights(\PDO $pdo, int $userId, string $windowStart): array
    {
        $base = [];
        $apply = function (string $sql, float $weight) use ($pdo, $userId, $windowStart, &$base) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['user_id' => $userId, 'window_start' => $windowStart]);
            foreach ($stmt->fetchAll() as $row) {
                $eventId = (int) $row['event_id'];
                $base[$eventId] = max($base[$eventId] ?? 0.0, $weight);
            }
        };

        $apply('SELECT id AS event_id FROM events WHERE organizer_id = :user_id AND start_date >= :window_start', self::WEIGHT_CREATED);
        $apply("
            SELECT e.id AS event_id FROM event_rsvps r
              JOIN event_editions ed ON ed.id = r.edition_id
              JOIN events e ON e.id = ed.event_id
              JOIN dictionary_items di ON di.id = r.status_item_id AND di.code = 'potwierdzony'
             WHERE r.user_id = :user_id AND e.start_date >= :window_start
        ", self::WEIGHT_CONFIRMED);
        $apply("
            SELECT e.id AS event_id FROM event_attendance a
              JOIN event_rsvps r ON r.id = a.rsvp_id
              JOIN event_editions ed ON ed.id = r.edition_id
              JOIN events e ON e.id = ed.event_id
             WHERE r.user_id = :user_id AND e.start_date >= :window_start
        ", self::WEIGHT_ATTENDANCE);
        $apply("
            SELECT e.id AS event_id FROM event_rsvps r
              JOIN event_editions ed ON ed.id = r.edition_id
              JOIN events e ON e.id = ed.event_id
              JOIN dictionary_items di ON di.id = r.status_item_id AND di.code = 'zainteresowany'
             WHERE r.user_id = :user_id AND e.start_date >= :window_start
        ", self::WEIGHT_INTERESTED);

        $bonus = [];
        $applyBonus = function (string $sql, float $weight) use ($pdo, $userId, $windowStart, &$bonus) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['user_id' => $userId, 'window_start' => $windowStart]);
            foreach ($stmt->fetchAll() as $row) {
                $eventId = (int) $row['event_id'];
                $bonus[$eventId] = ($bonus[$eventId] ?? 0.0) + $weight;
            }
        };
        $applyBonus('
            SELECT e.id AS event_id FROM event_recaps rc
              JOIN events e ON e.id = rc.event_id
             WHERE rc.author_user_id = :user_id AND e.start_date >= :window_start
        ', self::BONUS_RECAP);
        $applyBonus('
            SELECT e.id AS event_id FROM event_reviews rv
              JOIN events e ON e.id = rv.event_id
             WHERE rv.reviewer_user_id = :user_id AND rv.rating >= ' . self::REVIEW_MIN_RATING . '
               AND e.start_date >= :window_start
        ', self::BONUS_REVIEW);
        $applyBonus('
            SELECT e.id AS event_id FROM event_comments c
              JOIN events e ON e.id = c.event_id
             WHERE c.user_id = :user_id AND e.start_date >= :window_start
        ', self::BONUS_COMMENT);

        $startDates = [];
        if (!empty($base) || !empty($bonus)) {
            // array_values() KONIECZNE: array_unique() zachowuje klucze, więc po
            // usunięciu choćby jednego duplikatu tablica przestaje być listą
            // (0,1,2...). PDOStatement::execute() dostaje wtedy tablicę
            // asocjacyjną i próbuje wiązać klucze jako NAZWY parametrów —
            // "SQLSTATE[HY093]: Invalid parameter number" i nocne przeliczanie
            // profili wywala się w całości. Duplikat pojawia się zawsze, gdy ten
            // sam event jest i w $base, i w $bonus (np. ktoś na nim był ORAZ
            // napisał relację) — czyli w najzwyklejszym scenariuszu.
            $eventIds = array_values(array_unique(array_merge(array_keys($base), array_keys($bonus))));
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $stmt = $pdo->prepare("SELECT id, start_date FROM events WHERE id IN ($placeholders)");
            $stmt->execute($eventIds);
            foreach ($stmt->fetchAll() as $row) {
                $startDates[(int) $row['id']] = $row['start_date'];
            }
        }

        $final = [];
        foreach (array_unique(array_merge(array_keys($base), array_keys($bonus))) as $eventId) {
            if (!isset($startDates[$eventId])) {
                continue;
            }
            $raw = ($base[$eventId] ?? 0.0) + ($bonus[$eventId] ?? 0.0);
            if ($raw <= 0.0) {
                continue;
            }
            $daysAgo = max(0, (int) floor((time() - strtotime($startDates[$eventId])) / 86400));
            $decay = 0.5 ** ($daysAgo / self::HALF_LIFE_DAYS);
            $final[$eventId] = $raw * $decay;
        }
        return $final;
    }

    private static function eventMeta(\PDO $pdo, array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $pdo->prepare("
            SELECT e.id, e.pace_group_item_id, e.difficulty_item_id,
                   (SELECT GROUP_CONCAT(er.region_item_id) FROM event_regions er WHERE er.event_id = e.id) AS region_item_ids,
                   COALESCE(tot.total_distance_km, 0) AS distance_km,
                   (SELECT COUNT(*) FROM event_rsvps r2
                      JOIN event_editions ed2 ON ed2.id = r2.edition_id
                      JOIN dictionary_items di2 ON di2.id = r2.status_item_id AND di2.code = 'potwierdzony'
                     WHERE ed2.event_id = e.id) AS confirmed_total
            FROM events e
            LEFT JOIN event_totals tot ON tot.event_id = e.id
            WHERE e.id IN ($placeholders)
        ");
        $stmt->execute($eventIds);
        $meta = [];
        foreach ($stmt->fetchAll() as $row) {
            $meta[(int) $row['id']] = [
                'paceGroupItemId'  => $row['pace_group_item_id'] !== null ? (int) $row['pace_group_item_id'] : null,
                'difficultyItemId' => $row['difficulty_item_id'] !== null ? (int) $row['difficulty_item_id'] : null,
                'regionItemIds'    => !empty($row['region_item_ids']) ? array_map('intval', explode(',', $row['region_item_ids'])) : [],
                'distanceKm'       => (float) $row['distance_km'],
                'confirmedTotal'   => (int) $row['confirmed_total'],
                'bikeTypeItemIds'  => [],
            ];
        }

        $stmt = $pdo->prepare("
            SELECT event_id, bike_type_item_id FROM event_bike_types WHERE event_id IN ($placeholders)
        ");
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll() as $row) {
            $eventId = (int) $row['event_id'];
            if (isset($meta[$eventId])) {
                $meta[$eventId]['bikeTypeItemIds'][] = (int) $row['bike_type_item_id'];
            }
        }
        return $meta;
    }

    // Odrzucenia z podanym powodem (stopień 2/3 z §6) obniżają wagę OSI
    // odpowiadającej temu wydarzeniu, PRZED normalizacją — słabo za
    // pojedyncze odrzucenie, pełną mocą dopiero gdy to już trzecie (albo
    // kolejne) odrzucenie z TYM SAMYM powodem w ciągu poprzedzających 90 dni.
    private static function applyDismissalPenalties(\PDO $pdo, int $userId, array $meta, array &$signals): void
    {
        $stmt = $pdo->prepare("
            SELECT event_id, reason, dismissed_at FROM recommendation_dismissals
            WHERE user_id = :user_id AND reason IS NOT NULL
            ORDER BY dismissed_at ASC
        ");
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        $byReason = [];
        foreach ($rows as $row) {
            $byReason[$row['reason']][] = $row;
        }

        foreach ($byReason as $reason => $reasonRows) {
            $axis = self::REASON_AXIS[$reason] ?? null;
            if ($axis === null) {
                continue;
            }
            foreach ($reasonRows as $i => $row) {
                $windowStart = strtotime($row['dismissed_at']) - self::DISMISSAL_STREAK_WINDOW_DAYS * 86400;
                $countInWindow = 0;
                foreach ($reasonRows as $other) {
                    if (strtotime($other['dismissed_at']) >= $windowStart && strtotime($other['dismissed_at']) <= strtotime($row['dismissed_at'])) {
                        $countInWindow++;
                    }
                }
                $penalty = $countInWindow >= self::DISMISSAL_STREAK_THRESHOLD ? self::DISMISSAL_STRONG_PENALTY : self::DISMISSAL_WEAK_PENALTY;

                $eventMeta = $meta[(int) $row['event_id']] ?? null;
                // Region może być kilkoma pozycjami naraz (migr. 074) — kara
                // trafia w każdą z nich, tym samym wzorcem co sygnał dodatni
                // wyżej (gatherEventWeights).
                $itemIds = [];
                if ($eventMeta !== null) {
                    $itemIds = $axis === 'pace'
                        ? ($eventMeta['paceGroupItemId'] !== null ? [$eventMeta['paceGroupItemId']] : [])
                        : $eventMeta['regionItemIds'];
                }
                foreach ($itemIds as $itemId) {
                    $signals[$itemId] = ($signals[$itemId] ?? 0.0) - $penalty;
                }
            }
        }
    }

    // Normalizacja W OBRĘBIE KAŻDEGO SŁOWNIKA OSOBNO (docs/etap3 §4) — dzieli
    // przez wartość największą w TYM słowniku, żeby słownik o większej
    // liczbie pozycji (np. region, 5 pozycji) nie zdominował słownika o
    // mniejszej (np. tempo, 3 pozycje) tylko przez liczbę zebranych sygnałów.
    // Wagi ujemne (po karach za odrzucenie) są przycinane do zera — kolumna
    // przechowuje wyłącznie 0..1.
    private static function normalizePerDictionary(\PDO $pdo, array $signals): array
    {
        if (empty($signals)) {
            return [];
        }
        $itemIds = array_keys($signals);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $pdo->prepare("SELECT id, dictionary_id FROM dictionary_items WHERE id IN ($placeholders)");
        $stmt->execute($itemIds);
        $dictOf = [];
        foreach ($stmt->fetchAll() as $row) {
            $dictOf[(int) $row['id']] = (int) $row['dictionary_id'];
        }

        $clamped = [];
        $maxByDict = [];
        foreach ($signals as $itemId => $w) {
            $w = max(0.0, $w);
            $clamped[$itemId] = $w;
            $dict = $dictOf[$itemId] ?? null;
            if ($dict !== null) {
                $maxByDict[$dict] = max($maxByDict[$dict] ?? 0.0, $w);
            }
        }

        $normalized = [];
        foreach ($clamped as $itemId => $w) {
            $dict = $dictOf[$itemId] ?? null;
            $max = $dict !== null ? ($maxByDict[$dict] ?? 0.0) : 0.0;
            if ($max > 0.0) {
                $normalized[$itemId] = round($w / $max, 4);
            }
        }
        return $normalized;
    }

    private static function persist(int $userId, array $normalizedSignals, ?float $avgDistance, ?float $avgGroupSize, float $signalStrength, int $eventCount): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM user_preference_signals WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        if (!empty($normalizedSignals)) {
            $insert = $pdo->prepare('
                INSERT INTO user_preference_signals (user_id, dictionary_item_id, weight) VALUES (:user_id, :item_id, :weight)
            ');
            foreach ($normalizedSignals as $itemId => $weight) {
                if ($weight <= 0.0) {
                    continue;
                }
                $insert->execute(['user_id' => $userId, 'item_id' => $itemId, 'weight' => $weight]);
            }
        }

        $pdo->prepare('
            INSERT INTO user_preference_stats (user_id, avg_distance_km, avg_group_size, signal_strength, event_count, computed_at)
            VALUES (:user_id, :avg_distance, :avg_group_size, :signal_strength, :event_count, NOW())
            ON DUPLICATE KEY UPDATE
              avg_distance_km = VALUES(avg_distance_km),
              avg_group_size = VALUES(avg_group_size),
              signal_strength = VALUES(signal_strength),
              event_count = VALUES(event_count),
              computed_at = NOW()
        ')->execute([
            'user_id'        => $userId,
            'avg_distance'   => $avgDistance,
            'avg_group_size' => $avgGroupSize,
            'signal_strength' => round($signalStrength, 2),
            'event_count'    => $eventCount,
        ]);
    }
}
