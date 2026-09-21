-- migration_044_event_point_bonus.sql
-- Etap 8A — EVENT BONUS. Możliwość przyznania punktów za sam udział
-- w konkretnym wydarzeniu.
--
-- Brief (§2) mówi wprost: „Nie oznacza to, że każde wydarzenie musi mieć
-- bonus. Model powinien tylko umożliwiać taką funkcję." Stąd JEDNA kolumna
-- dopuszczająca NULL i zero UI — wartość ustawia się dziś wprost w bazie,
-- ekran w panelu dojdzie razem z resztą zarządzania punktami.
--
-- NULL, a nie 0, jako „brak bonusu": zero jest wartością, którą ktoś mógł
-- wpisać świadomie, i wtedy chcemy wiedzieć, że decyzja zapadła. Rejestr
-- punktów i tak pomija naliczenia zerowe, więc oba zachowują się tak samo
-- w skutkach — różnią się tym, co mówią czytającemu bazę.
--
-- Lokalnie: wgraj wprost do ridemorebike2. Na produkcji: run_migrations.php.
-- mysql -u USER -p ridemorebike2 < migration_044_event_point_bonus.sql

SET NAMES utf8mb4;

ALTER TABLE events
  ADD COLUMN point_bonus INT UNSIGNED NULL AFTER max_participants;
