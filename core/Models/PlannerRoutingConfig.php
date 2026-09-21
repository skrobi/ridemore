<?php
// core/Models/PlannerRoutingConfig.php
namespace Models;

use Core\Database;
use Utils\RoutingPreferences;

/** Nazwane preferencje routingu użytkownika pod istniejącym BikeType. */
final class PlannerRoutingConfig
{
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.name, c.character_code, c.preferences_json, c.is_default,
                    c.bike_type_item_id, di.code AS bike_profile
               FROM planner_routing_configs c
               JOIN dictionary_items di ON di.id = c.bike_type_item_id
              WHERE c.user_id = :user_id
              ORDER BY di.sort_order, c.is_default DESC, c.name'
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, di.code AS bike_profile
               FROM planner_routing_configs c
               JOIN dictionary_items di ON di.id = c.bike_type_item_id
              WHERE c.id = :id AND c.user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ? self::present($row) : null;
    }

    public static function defaultForUser(int $userId, int $bikeTypeId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, di.code AS bike_profile
               FROM planner_routing_configs c
               JOIN dictionary_items di ON di.id = c.bike_type_item_id
              WHERE c.user_id = :user_id AND c.bike_type_item_id = :bike AND c.is_default = 1
              LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'bike' => $bikeTypeId]);
        $row = $stmt->fetch();
        return $row ? self::present($row) : null;
    }

    /** Tworzy lub edytuje konfigurację; $id musi należeć do usera. */
    public static function save(int $userId, array $input, ?int $id = null): ?array
    {
        $type = BikeType::byCode(is_string($input['bikeProfile'] ?? null) ? $input['bikeProfile'] : null);
        $name = trim((string) ($input['name'] ?? ''));
        if ($type === null || $name === '') {
            return null;
        }
        $name = mb_substr($name, 0, 80);
        $character = RoutingPreferences::character($input['character'] ?? 'balanced');
        $preferences = RoutingPreferences::resolve($type['code'], $character, $input['preferences'] ?? []);
        $json = json_encode($preferences, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $db = Database::connection();

        if ($id !== null) {
            $existing = self::findForUser($id, $userId);
            if ($existing === null || $existing['bikeTypeId'] !== (int) $type['id']) {
                return null;
            }
            $stmt = $db->prepare(
                'UPDATE planner_routing_configs
                    SET name = :name, bike_type_item_id = :bike, character_code = :character,
                        preferences_json = :preferences
                  WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'name' => $name, 'bike' => $type['id'], 'character' => $character,
                'preferences' => $json, 'id' => $id, 'user_id' => $userId,
            ]);
            return self::findForUser($id, $userId);
        }

        $stmt = $db->prepare(
            'INSERT INTO planner_routing_configs
                (user_id, bike_type_item_id, name, character_code, preferences_json)
             VALUES (:user_id, :bike, :name, :character, :preferences)'
        );
        $stmt->execute([
            'user_id' => $userId, 'bike' => $type['id'], 'name' => $name,
            'character' => $character, 'preferences' => $json,
        ]);
        return self::findForUser((int) $db->lastInsertId(), $userId);
    }

    public static function delete(int $id, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM planner_routing_configs WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function setDefault(int $id, int $userId): bool
    {
        $config = self::findForUser($id, $userId);
        if ($config === null) {
            return false;
        }
        $db = Database::connection();
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $db->prepare(
                'UPDATE planner_routing_configs SET is_default = NULL
                  WHERE user_id = :user_id AND bike_type_item_id = :bike'
            )->execute(['user_id' => $userId, 'bike' => $config['bikeTypeId']]);
            $stmt = $db->prepare(
                'UPDATE planner_routing_configs SET is_default = 1
                  WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute(['id' => $id, 'user_id' => $userId]);
            if ($own) {
                $db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function present(array $row): array
    {
        $preferences = json_decode((string) ($row['preferences_json'] ?? ''), true);
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'bikeProfile' => (string) $row['bike_profile'],
            'bikeTypeId' => (int) $row['bike_type_item_id'],
            'character' => RoutingPreferences::character($row['character_code'] ?? null),
            'preferences' => is_array($preferences) ? $preferences : [],
            'isDefault' => (bool) $row['is_default'],
        ];
    }
}
