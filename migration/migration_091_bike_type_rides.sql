-- migration_091_bike_type_rides.sql
-- TYP ROWERU PRZEJAZDU + KONFIGURACJA PLANERA PER TYP (2026-09-19,
-- tasks/active/warstwa-routingu-ridemore.md, Etap 3).
--
-- 1) `rider_activities.bike_type_item_id` — na czym przejechano (słownik
--    `bike_type`, ten sam co w wydarzeniach i preferencjach). NULL = nieznany:
--    import bez informacji o sprzęcie albo przejazd sprzed tej migracji.
--    Nieznany typ jest dla planera NEUTRALNY (liczy się jak każdy przejazd).
--    ON DELETE SET NULL — skasowanie pozycji słownika nie może blokować ani
--    kasować przejazdów (panel i tak tylko dezaktywuje).
--
-- 2) Domyślna konfiguracja czterech typów w `dictionary_items.meta` (wzorzec
--    `map_layer`, migr. 072): `planner` — zasady warstwy routingu Ridemore
--    i profil silnika routingu; `aliases` — nazwy rodzaju aktywności
--    z importów (Garmin typeKey, Polar/Wahoo, znacznik <type> w GPX),
--    porównywane po normalizacji (małe litery, spacje/myślniki → „_").
--    Zmieniane potem w panelu: /admin/taksonomia?dict=bike_type → „Planer".
--    Wpis TYLKO tam, gdzie `meta` jest puste — ponowne uruchomienie nie nadpisze
--    konfiguracji ustawionej w panelu.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_091_bike_type_rides.sql

SET NAMES utf8mb4;

ALTER TABLE rider_activities
  ADD COLUMN bike_type_item_id INT UNSIGNED NULL AFTER source_code,
  ADD KEY idx_ra_bike_type (bike_type_item_id),
  ADD CONSTRAINT fk_ra_bike_type FOREIGN KEY (bike_type_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL;

UPDATE dictionary_items di
  JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'bike_type'
   SET di.meta = '{"planner":{"speedKmh":25,"minAsphaltPct":70,"legalRatio":1.3,"trailBonus":0,"countsRidesOf":["szosowy","ebike"],"engines":{"osrm":{"profile":"cycling","baseUrl":""}}},"aliases":["road_biking","road_cycling","road_bike","roadbike","road","track_cycling"]}'
 WHERE di.code = 'szosowy' AND di.meta IS NULL;

UPDATE dictionary_items di
  JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'bike_type'
   SET di.meta = '{"planner":{"speedKmh":20,"minAsphaltPct":null,"legalRatio":1.5,"trailBonus":0,"countsRidesOf":["szosowy","gravel","ebike"],"engines":{"osrm":{"profile":"cycling","baseUrl":""}}},"aliases":["gravel_cycling","gravel","gravel_ride","gravelride","cyclocross","cyclo_cross"]}'
 WHERE di.code = 'gravel' AND di.meta IS NULL;

UPDATE dictionary_items di
  JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'bike_type'
   SET di.meta = '{"planner":{"speedKmh":15,"minAsphaltPct":null,"legalRatio":1.8,"trailBonus":0.2,"countsRidesOf":["szosowy","gravel","mtb","ebike"],"engines":{"osrm":{"profile":"cycling","baseUrl":""}}},"aliases":["mountain_biking","mountain_bike","mountainbikeride","mtb","downhill_biking","e_bike_mountain","emountainbikeride"]}'
 WHERE di.code = 'mtb' AND di.meta IS NULL;

UPDATE dictionary_items di
  JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'bike_type'
   SET di.meta = '{"planner":{"speedKmh":22,"minAsphaltPct":null,"legalRatio":1.5,"trailBonus":0,"countsRidesOf":["szosowy","gravel","ebike"],"engines":{"osrm":{"profile":"cycling","baseUrl":""}}},"aliases":["e_bike_fitness","e_bike_commuting","e_bike","ebike","ebikeride","electric_bike"]}'
 WHERE di.code = 'ebike' AND di.meta IS NULL;

-- 3) Zapisana trasa planera trzyma KOD typu roweru, z którym ją liczono
--    (PlannerController::save) — kody słownika mają do 64 znaków.
ALTER TABLE planned_routes
  MODIFY profile VARCHAR(64) NOT NULL DEFAULT 'cycling';
