-- migration_004b_organizer_slug_not_null.sql
-- Uruchamiać PO backfillu slugów dla istniejących organizer_profiles (przez PHP,
-- Models\Organizer::generateUniqueSlug() — nie ręcznym SQL, żeby nie rozjechać
-- się z Format::slugify()). Patrz migration_004_organizer_profile.sql.
--
-- mysql -u USER -p ridemorebike2 < migration_004b_organizer_slug_not_null.sql

SET NAMES utf8mb4;

ALTER TABLE organizer_profiles MODIFY slug VARCHAR(160) NOT NULL;
