-- migration_021_organizer_active_index.sql
-- organizer_profiles.is_active jest filtrowana w Organizer::search(),
-- Organizer::allForAdmin() i Organizer::allSlugsForSitemap(), ale (w
-- odróżnieniu od kolumn z FK, InnoDB nie indeksuje ich automatycznie) nie
-- miała żadnego indeksu — przy większej liczbie organizatorów to pełny
-- skan tabeli na każde z tych zapytań.

ALTER TABLE organizer_profiles
  ADD INDEX idx_org_active (is_active);
