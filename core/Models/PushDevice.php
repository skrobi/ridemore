<?php

namespace Models;

use Core\Database;

/**
 * URZĄDZENIA ZAREJESTROWANE POD PUSH (Etap 8 przebudowy apki, migr. 077,
 * 2026-08-28) — nazwa CELOWO NIE `user_devices`: serwis ma już inne
 * „urządzenia" (Models\DeviceConnection — liczniki rowerowe Garmin/Polar/
 * Wahoo), więc ta sama nazwa kolidowałaby pojęciowo z czymś innym.
 *
 * ZGODA = OBECNOŚĆ AKTYWNEGO WIERSZA. Rejestracja tokenu jest zgodą;
 * wyłączenie w koncie (`deactivateForUser`) cofa ją bez kasowania wiersza —
 * ponowne włączenie (`activateForUser`) nie wymaga nowej rejestracji tokenu
 * z apki, jeśli token jeszcze żyje. Jedno źródło prawdy zamiast osobnej
 * kolumny zgody obok kolumny aktywności.
 */
class PushDevice
{
    /**
     * Rejestracja/odświeżenie tokenu. UPSERT po `token` (globalnie unikalny —
     * identyfikuje ZAINSTALOWANIE apki, nie konto): na współdzielonym
     * telefonie token „idzie" za tym, kto ostatnio się zalogował, tak jak
     * w każdym typowym systemie push. Rejestracja ZAWSZE włącza z powrotem
     * `is_active` — user, który świadomie wyłączył powiadomienia, a potem
     * przelogował się na inne konto na tym samym telefonie, nie powinien
     * dostać cichej ciszy na nowym koncie z powodu starego wyłączenia.
     */
    public static function register(int $userId, string $platform, string $token): void
    {
        if (!in_array($platform, ['android', 'ios'], true) || $token === '') {
            return;
        }
        Database::connection()->prepare('
            INSERT INTO push_devices (user_id, platform, token, is_active, last_seen_at)
            VALUES (:user_id, :platform, :token, 1, NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                platform = VALUES(platform),
                is_active = 1,
                last_seen_at = NOW()
        ')->execute(['user_id' => $userId, 'platform' => $platform, 'token' => $token]);
    }

    /** Wyłączenie zgody — przełącznik w koncie. Wiersz zostaje, tylko gaśnie. */
    public static function deactivateForUser(int $userId): void
    {
        Database::connection()
            ->prepare('UPDATE push_devices SET is_active = 0 WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    /** Ponowne włączenie — bez nowej rejestracji tokenu z apki. */
    public static function activateForUser(int $userId): void
    {
        Database::connection()
            ->prepare('UPDATE push_devices SET is_active = 1 WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    /** Czy user ma choć jedno aktywne urządzenie — stan przełącznika w koncie. */
    public static function hasActiveForUser(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM push_devices WHERE user_id = :user_id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Aktywne tokeny tego usera, pogrupowane po platformie — dokładnie w
     * kształcie, jakiego potrzebuje `Core\Push::sendToUser()` (FCM i APNs
     * to dwa różne wysyłacze).
     *
     * @return array{android: string[], ios: string[]}
     */
    public static function activeForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT platform, token FROM push_devices WHERE user_id = :user_id AND is_active = 1'
        );
        $stmt->execute(['user_id' => $userId]);

        $out = ['android' => [], 'ios' => []];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['platform']][] = $row['token'];
        }
        return $out;
    }
}
