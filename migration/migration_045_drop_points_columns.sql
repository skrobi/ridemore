-- migration_045_drop_points_columns.sql
-- Etap 8A/7 — usunięcie sum punktów z rider_activities.
--
-- Kolumny points_discovery / points_exploration / points_trails powstały
-- w Etapie 8, gdy punkty nie miały gdzie mieszkać. Od migracji 043 źródłem
-- prawdy jest point_transactions i przez dwa kroki (8A/4–8A/6) kolumny były
-- utrzymywane jako cache, żeby widoki nie regresowały w trakcie przebudowy.
-- Teraz odczyty idą już do rejestru i cache nie ma ani jednego czytelnika.
--
-- Zostawienie ich byłoby gorsze niż usunięcie: to trzy kolumny, których nic
-- nie aktualizuje i nic nie czyta, a które wyglądają na aktualny stan punktów.
-- Pierwsza osoba, która na nie trafi, policzy z nich wynik — i dostanie liczbę
-- sprzed przebudowy, bez punktów za jazdę i za wydarzenie, których te kolumny
-- nigdy nie umiały pomieścić. To jest dokładnie ten „user.points = 15342 bez
-- wiadomo skąd", przed którym ostrzega brief.
--
-- Danych nie tracimy: każde naliczenie, które kiedykolwiek trafiło do tych
-- kolumn, ma swój wpis w point_transactions.
--
-- Lokalnie: wgraj wprost do ridemorebike2. Na produkcji: run_migrations.php
-- PO 043 i 044.
-- mysql -u USER -p ridemorebike2 < migration_045_drop_points_columns.sql

SET NAMES utf8mb4;

ALTER TABLE rider_activities
  DROP COLUMN points_discovery,
  DROP COLUMN points_exploration,
  DROP COLUMN points_trails;
