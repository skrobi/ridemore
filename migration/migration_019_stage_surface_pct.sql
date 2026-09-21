-- migration_019_stage_surface_pct.sql
-- Dziś nawierzchnia etapu to jeden wybór ze słownika (Asfalt/Gravel/Singletrack/
-- Szuter) — realna trasa często miesza wszystkie trzy. Gdy organizator wgrywa
-- GPX, RoadSurfaceDetector (core/Utils/RoadSurfaceDetector.php, Overpass API)
-- liczy rzeczywisty podział km/% i to on jest źródłem prawdy do wyświetlenia;
-- surface_item_id zostaje jako RĘCZNY fallback, gdy nie ma GPX albo detekcja
-- się nie powiedzie (stąd też nowa opcja 'mix' w słowniku poniżej).

ALTER TABLE event_stages
  ADD COLUMN surface_asphalt_pct SMALLINT UNSIGNED NULL AFTER surface_item_id,
  ADD COLUMN surface_gravel_pct  SMALLINT UNSIGNED NULL AFTER surface_asphalt_pct,
  ADD COLUMN surface_trail_pct   SMALLINT UNSIGNED NULL AFTER surface_gravel_pct;

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code='surface_type'), 'mix', 'Mix (różne nawierzchnie)', 5);
