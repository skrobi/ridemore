-- migration_080_rider_activity_name.sql
-- WŁASNA NAZWA PRZEJAZDU SOLO (2026-09-05).
--
-- Zgłoszenie usera po imporcie z Garmina: nazwa aktywności z licznika
-- (`device_activities.activity_name`) już i tak wygrywa z generycznym
-- „Przejazd solo" wszędzie, gdzie jest dostępna (RideController::rideName()
-- i pochodne) — ale nie dało się jej PODMIENIĆ. Ta kolumna to WARSTWA NAD
-- nią, nie zamiennik: gdy jest ustawiona, wygrywa z nazwą z licznika; gdy
-- NULL (domyślnie, także dla ręcznie wgranych plików bez licznika), priorytet
-- zostaje bez zmian.
--
-- TYLKO PRZEJAZDY SOLO mają sens do nazwania własnym tytułem — przejazd
-- z wyjazdu bierze nazwę wydarzenia, a to ma swój własny ekran edycji.
-- Reguła pilnowana w kodzie (RiderActivity::rename()), nie w schemacie: nie
-- ma tu żadnego CHECK na source_code, żeby nie duplikować logiki dwa razy.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_080_rider_activity_name.sql

SET NAMES utf8mb4;

ALTER TABLE rider_activities
  ADD COLUMN name VARCHAR(190) NULL AFTER gpx_hash;
