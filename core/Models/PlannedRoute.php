<?php

namespace Models;

use Core\Database;

/**
 * ROUTE PLANNER, ETAP 1 — trasy robocze/prywatne usera (migr. 090).
 *
 * Celowo NIE `known_routes` (community/admin, publiczne z definicji) — to
 * jest szkicownik jednego usera. `waypoints` to punkty, które user faktycznie
 * klikał (pod ponowną edycję), `geometry` to finalna, wyliczona linia
 * (routing dokłada punkty pośrednie wzdłuż dróg) — dwa różne kształty danych,
 * dwie kolumny.
 */
class PlannedRoute
{
    /** Nowa trasa dla usera. Zwraca id. */
    public static function save(int $userId, array $input): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            INSERT INTO planned_routes
                (user_id, name, waypoints_json, geometry_json, distance_km, ascent_m, descent_m, duration_min, engine, profile)
            VALUES
                (:user_id, :name, :waypoints_json, :geometry_json, :distance_km, :ascent_m, :descent_m, :duration_min, :engine, :profile)
        ');
        $stmt->execute(self::bindParams($userId, $input));
        return (int) $pdo->lastInsertId();
    }

    /** Nadpisanie istniejącej trasy — tylko gdy naprawdę należy do tego usera. */
    public static function update(int $id, int $userId, array $input): bool
    {
        $stmt = Database::connection()->prepare('
            UPDATE planned_routes SET
                name = :name,
                waypoints_json = :waypoints_json,
                geometry_json = :geometry_json,
                distance_km = :distance_km,
                ascent_m = :ascent_m,
                descent_m = :descent_m,
                duration_min = :duration_min,
                engine = :engine,
                profile = :profile
            WHERE id = :id AND user_id = :user_id
        ');
        $params = self::bindParams($userId, $input);
        $params['id'] = $id;
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    private static function bindParams(int $userId, array $input): array
    {
        return [
            'user_id'        => $userId,
            'name'           => (string) $input['name'],
            'waypoints_json' => (string) $input['waypoints_json'],
            'geometry_json'  => (string) $input['geometry_json'],
            'distance_km'    => (float) $input['distance_km'],
            'ascent_m'       => $input['ascent_m'] !== null ? (int) $input['ascent_m'] : null,
            'descent_m'      => $input['descent_m'] !== null ? (int) $input['descent_m'] : null,
            'duration_min'   => $input['duration_min'] !== null ? (int) $input['duration_min'] : null,
            'engine'         => (string) ($input['engine'] ?? 'osrm-public'),
            'profile'        => (string) ($input['profile'] ?? 'cycling'),
        ];
    }

    /** Trasa TEGO usera — null, jeśli nie istnieje albo należy do kogoś innego (IDOR guard w SQL, nie w kodzie). */
    public static function findForUser(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM planned_routes WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Lista tras usera, najnowsze pierwsze — pod przyszłą listę „Moje trasy". */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, name, distance_km, ascent_m, duration_min, updated_at
               FROM planned_routes WHERE user_id = :user_id ORDER BY updated_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }
}
