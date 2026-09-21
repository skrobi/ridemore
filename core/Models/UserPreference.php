<?php
// core/Models/UserPreference.php
// Etap 3 (preferencje użytkownika) — warstwa deklarowana. Nigdy proszona przy
// rejestracji (docs/etap3 §3); wyłącznie w ustawieniach konta na razie (patrz
// Controllers\Admin\AccountController) — momenty "po pierwszym dołączeniu" i
// "pierwsze wejście na ekran powiadomień" z docu są świadomie poza tym pass'em
// (nie ma jeszcze ekranu powiadomień, do którego by się to pięło).
namespace Models;

use Core\Database;

class UserPreference
{
    // Zawsze zwraca pełną strukturę, nigdy null — brak zapisanych preferencji
    // (najczęstszy stan) to poprawne dane wejściowe dla MatchEngine, nie błąd.
    public static function forUser(int $userId): array
    {
        $pdo = Database::connection();

        $itemsStmt = $pdo->prepare('
            SELECT upi.kind, d.code AS dict_code, di.code AS item_code
            FROM user_preference_items upi
            JOIN dictionary_items di ON di.id = upi.dictionary_item_id
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE upi.user_id = :user_id
        ');
        $itemsStmt->execute(['user_id' => $userId]);

        $operational = ['bike_type' => [], 'pace_group' => [], 'difficulty_level' => [], 'region' => []];
        $aspirational = ['region' => [], 'event_type' => []];
        foreach ($itemsStmt->fetchAll() as $row) {
            if ($row['kind'] === 'aspirational') {
                if (isset($aspirational[$row['dict_code']])) {
                    $aspirational[$row['dict_code']][] = $row['item_code'];
                }
            } elseif (isset($operational[$row['dict_code']])) {
                $operational[$row['dict_code']][] = $row['item_code'];
            }
        }

        $prefsStmt = $pdo->prepare('SELECT * FROM user_preferences WHERE user_id = :user_id');
        $prefsStmt->execute(['user_id' => $userId]);
        $row = $prefsStmt->fetch() ?: [];

        return [
            'operational'       => $operational,
            'aspirational'      => $aspirational,
            'distanceMinKm'     => isset($row['distance_min_km']) ? (int) $row['distance_min_km'] : null,
            'distanceMaxKm'     => isset($row['distance_max_km']) ? (int) $row['distance_max_km'] : null,
            'elevationMaxM'     => isset($row['elevation_max_m']) ? (int) $row['elevation_max_m'] : null,
            'groupSizePref'     => $row['group_size_pref'] ?? 'any',
            'notifyMatches'     => !empty($row['notify_matches']),
            'declaredUpdatedAt' => $row['declared_updated_at'] ?? null,
        ];
    }

    // $input: bikeTypes/paces/difficulties/regions (operacyjne, string[] kodów
    // słownikowych), aspirationalRegions/aspirationalEventTypes (string[]),
    // distanceMinKm/distanceMaxKm/elevationMaxM (int|null), groupSizePref
    // ('small'/'any'/'large'), notifyMatches (bool). Zawsze nadpisuje CAŁOŚĆ
    // (usuń-i-wstaw-od-nowa dla pozycji wielokrotnych, ten sam wzorzec co
    // Event::save() dla event_bike_types) — formularz ustawień wysyła zawsze
    // pełny stan zaznaczeń, nie diff.
    public static function save(int $userId, array $input): void
    {
        $pdo = Database::connection();

        $pdo->prepare('DELETE FROM user_preference_items WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $insertItem = $pdo->prepare('
            INSERT IGNORE INTO user_preference_items (user_id, dictionary_item_id, kind)
            VALUES (:user_id, :item_id, :kind)
        ');
        $addItems = function (string $dictCode, array $codes, string $kind) use ($insertItem, $userId) {
            foreach ($codes as $code) {
                $itemId = Dictionary::id($dictCode, is_string($code) ? $code : '');
                if ($itemId !== null) {
                    $insertItem->execute(['user_id' => $userId, 'item_id' => $itemId, 'kind' => $kind]);
                }
            }
        };
        $addItems('bike_type', $input['bikeTypes'] ?? [], 'operational');
        $addItems('pace_group', $input['paces'] ?? [], 'operational');
        $addItems('difficulty_level', $input['difficulties'] ?? [], 'operational');
        $addItems('region', $input['regions'] ?? [], 'operational');
        // Ta sama pozycja słownikowa (region) może trafić do OBU wywołań
        // powyżej i poniżej — to zamierzone (docs/etap3 §3): ktoś jeżdżący
        // po Bieszczadach i marzący o Mazurach ma obie deklaracje naraz.
        $addItems('region', $input['aspirationalRegions'] ?? [], 'aspirational');
        $addItems('event_type', $input['aspirationalEventTypes'] ?? [], 'aspirational');

        $groupPref = $input['groupSizePref'] ?? 'any';
        if (!in_array($groupPref, ['small', 'any', 'large'], true)) {
            $groupPref = 'any';
        }

        $pdo->prepare('
            INSERT INTO user_preferences
              (user_id, distance_min_km, distance_max_km, elevation_max_m, group_size_pref, notify_matches, declared_updated_at)
            VALUES (:user_id, :dmin, :dmax, :emax, :group_pref, :notify, NOW())
            ON DUPLICATE KEY UPDATE
              distance_min_km = VALUES(distance_min_km),
              distance_max_km = VALUES(distance_max_km),
              elevation_max_m = VALUES(elevation_max_m),
              group_size_pref = VALUES(group_size_pref),
              notify_matches = VALUES(notify_matches),
              -- Dotyka się TYLKO gdy user faktycznie zapisuje formularz
              -- preferencji (ta metoda) — napędza osłabienie rampy o połowę
              -- na kolejne 30 dni (docs/etap3 §5), więc nie może ruszać się
              -- przy okazji innych, niezwiązanych zapisów na koncie.
              declared_updated_at = NOW()
        ')->execute([
            'user_id'    => $userId,
            'dmin'       => $input['distanceMinKm'] ?? null,
            'dmax'       => $input['distanceMaxKm'] ?? null,
            'emax'       => $input['elevationMaxM'] ?? null,
            'group_pref' => $groupPref,
            'notify'     => !empty($input['notifyMatches']) ? 1 : 0,
        ]);
    }

    public static function hasAnyDeclared(int $userId): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT 1 FROM user_preference_items WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM user_preferences WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }
}
