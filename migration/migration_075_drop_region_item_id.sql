-- migration_075_drop_region_item_id.sql
--
-- Cutover: region_item_id na events/known_routes zastąpiony w całości przez
-- event_regions/known_route_regions (migr. 074, wypełnione przez
-- backfill_multi_regions.php). Kolumna usuwana w OSOBNYM pliku od 074, żeby
-- awaria w trakcie uruchamiania migracji nie mogła po cichu pominąć backfillu
-- (patrz ostrzeżenie przy migr. 062 w md/database.md).
--
-- UWAGA WDROŻENIOWA: przed odpaleniem tego pliku na danym środowisku MUSI
-- przejść `php backfill_multi_regions.php` — inaczej usunięcie kolumny
-- skasuje jedyne miejsce, gdzie region był zapisany, bez zastąpienia go
-- danymi w nowych tabelach.
--
-- treasures.region_item_id ZOSTAJE — skarb to punkt, nie trasa, więc nie
-- potrzebuje wielu regionów; zmienia się tylko SPOSÓB jego wyliczenia
-- (Models\Treasure::guessRegion, bezpośredni lookup przez treasures.cell_id
-- zamiast szukania najbliższej znanej trasy).
-- organizer_profiles.region_item_id ZOSTAJE — to deklarowany region
-- organizatora jako osoby, nie geometria trasy/wydarzenia.

ALTER TABLE known_routes
  DROP FOREIGN KEY fk_kr_region,
  DROP COLUMN region_item_id;

ALTER TABLE events
  DROP FOREIGN KEY fk_ev_region,
  DROP INDEX idx_events_region,
  DROP COLUMN region_item_id;
