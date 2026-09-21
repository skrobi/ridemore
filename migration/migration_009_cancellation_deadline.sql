-- migration_009_cancellation_deadline.sql
-- Strukturalny termin anulowania (dni przed startem), analogiczny do już
-- istniejącego payment_deadline_days_before — pozwala faktycznie EGZEKWOWAĆ
-- politykę anulowania (dotąd cancellation_policy było tylko wolnym tekstem
-- do wyświetlenia, nigdzie nieparsowanym). NULL = brak ograniczenia (zawsze
-- można anulować samoobsługowo) — bezpieczny domyślny stan, nic nie blokuje
-- dopóki organizator świadomie nie ustawi liczby dni.
--
-- mysql -u USER -p ridemorebike2 < migration_009_cancellation_deadline.sql

SET NAMES utf8mb4;

ALTER TABLE event_pricing
  ADD COLUMN cancellation_deadline_days_before SMALLINT UNSIGNED NULL AFTER payment_deadline_days_before;
