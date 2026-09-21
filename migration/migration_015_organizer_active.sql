-- migration_015_organizer_active.sql
-- Dezaktywacja profilu organizatora z panelu admina (/admin/organizatorzy) —
-- świadomie NIE usuwanie: events.organizer_id ma ON DELETE RESTRICT (prawie
-- każdy realny organizator ma już wydarzenia, więc twardy DELETE i tak by się
-- wysypał błędem FK), a nawet dla organizatora bez wydarzeń kaskadowe usunięcie
-- users skasowałoby też jego opinie/zapisy jako uczestnika gdzie indziej.
-- Dezaktywowany profil znika z publicznej listy/wyszukiwania i sitemapy, ale
-- dane (eventy, opinie) zostają nietknięte, a sam organizator nadal widzi i
-- edytuje swój profil w self-service (/admin/profil-rozliczeniowy) — ukrycie
-- dotyczy tylko widoku publicznego.
--
-- mysql -u USER -p ridemorebike2 < migration/migration_015_organizer_active.sql

SET NAMES utf8mb4;

ALTER TABLE organizer_profiles
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER verification_status_item_id;
