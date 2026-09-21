<?php
// core/Core/Database.php
namespace Core;

class Database
{
    private static ?\PDO $instance = null;

    public static function connection(): \PDO
    {
        if (self::$instance === null) {
            $db = APP_CONFIG['db'];

            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']);
            self::$instance = new \PDO($dsn, $db['user'], $db['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$instance;
    }
}
