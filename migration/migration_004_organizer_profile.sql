-- migration_004_organizer_profile.sql
-- Publiczny profil organizatora: slug (adres profilu) + bio (opis "o nas").
-- slug zostaje NULLable na tym etapie — backfill dla istniejących wierszy
-- robimy przez PHP (Format::slugify(), spójnie z generowaniem slugów eventów),
-- nie ręcznym SQL. Po backfillu odpalić osobny ALTER na NOT NULL (patrz
-- migration_004b_organizer_slug_not_null.sql).
--
-- mysql -u USER -p ridemorebike2 < migration_004_organizer_profile.sql

SET NAMES utf8mb4;

ALTER TABLE organizer_profiles
  ADD COLUMN slug VARCHAR(160) NULL UNIQUE AFTER user_id,
  ADD COLUMN bio TEXT NULL AFTER tourism_register_number;
