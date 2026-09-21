-- migration_077_push_devices.sql
-- PUSH DO APKI MOBILNEJ (Etap 8 przebudowy apki, tasks/active/apka-mobilna.md, 2026-08-28).
--
-- NAZWA CELOWO NIE `user_devices`, jak sugerował szkic kontraktu — serwis
-- MA JUŻ inne „urządzenia": Models\DeviceConnection/Utils\DeviceApi to
-- liczniki rowerowe (Garmin/Polar/Wahoo, sekcja „Moje przejazdy"), a klucz
-- configu `devices` w core/config.php należy do TAMTEGO systemu. Nazwa
-- `user_devices` kolidowałaby pojęciowo z czymś, co już istnieje.
--
-- ZGODA = OBECNOŚĆ AKTYWNEGO WIERSZA, nie osobna kolumna gdzie indziej.
-- Rejestracja tokenu (POST /api/devices/register) JEST zgodą; wyłączenie
-- w koncie (POST /api/devices/unregister) cofa ją ustawiając is_active=0,
-- bez kasowania wiersza — ponowne włączenie nie wymaga nowej rejestracji
-- tokenu z apki, jeśli token jeszcze żyje. Jedno źródło prawdy zamiast
-- zdublowanego stanu (kolumna zgody + osobna kolumna aktywności).
--
-- token UNIQUE GLOBALNIE, nie per user: token FCM/APNs identyfikuje
-- ZAINSTALOWANIE apki, nie konto. Rejestracja robi UPSERT (ON DUPLICATE KEY
-- UPDATE user_id=...) — na współdzielonym telefonie token „idzie" za tym,
-- kto ostatnio się zalogował i zarejestrował, tak jak w każdym typowym
-- systemie push.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_077_push_devices.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS push_devices (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  platform     ENUM('android','ios') NOT NULL,
  token        VARCHAR(255) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_push_token (token),
  KEY idx_push_devices_user (user_id, is_active),
  CONSTRAINT fk_push_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
