-- migration_079_deletion_requested.sql
-- SAMOOBSŁUGOWE KASOWANIE KONTA W APCE (Etap 9 przebudowy apki, 2026-08-29).
--
-- Apple wymaga, żeby usuwanie konta dało się zainicjować WPROST w apce, nie
-- „napisz do nas" (dziś: link mailto w /admin/moje-konto — obietnica w
-- treści, „zapisy zostaną zanonimizowane", której ŻADEN kod nie realizuje).
-- Twardego kasowania kont z treścią świadomie nie budujemy — ten sam powód,
-- który już stoi w users-admin.php: „kasowanie zabrałoby też cudze wyjazdy,
-- w których brali udział" (RSVP, komentarze, punkty). Decyzja usera
-- 2026-08-29: samoobsługowy przycisk w apce NATYCHMIAST blokuje konto
-- (istniejący mechanizm `users.blocked_*`, migr. 058) i zgłasza je do
-- ręcznego dokasowania przez admina.
--
-- `deletion_requested_at` ODRÓŻNIA prośbę o usunięcie od zwykłej blokady
-- moderacyjnej — obie idą przez te same `blocked_at`/`blocked_by`
-- (Auth::user() już wylogowuje zablokowane konta, za darmo), ale admin musi
-- umieć odróżnić „ten człowiek chce odejść" od „ten człowiek naruszył
-- regulamin", żeby nie potraktować prośby jak przypadku moderacji.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_079_deletion_requested.sql

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN deletion_requested_at TIMESTAMP NULL AFTER blocked_by;
