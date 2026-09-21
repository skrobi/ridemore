-- migration_086_known_route_surface.sql
-- NAWIERZCHNIA ZNANEJ TRASY (2026-09-11, zgłoszenie usera: „na profilu znanej
-- trasy brakuje informacji o nawierzchni; podczas uploadu powinna się również
-- pobierać, tak jak przy dodawaniu eventu").
--
-- TE SAME TRZY KOLUMNY, CO PRZY ETAPIE WYDARZENIA I WARIANCIE TRASY
-- (`event_stages`, `event_route_variants`) — ten sam detektor
-- (`Utils\RoadSurfaceDetector`, Overpass API), ta sama jednostka (procent
-- długości), ten sam pasek w widoku. Znana trasa jest z punktu widzenia
-- nawierzchni dokładnie tym samym bytem co etap: jednym plikiem GPX.
--
-- BEZ `surface_item_id`, w odróżnieniu od tamtych dwóch. Tam kolumna jest
-- ręcznym awaryjnym wyborem ze słownika `surface_type` dla wydarzenia BEZ
-- pliku GPX — a znana trasa bez GPX-a nie istnieje (`KnownRoute::createFromGpx`
-- to jedyna droga jej powstania). Dokładanie kolumny, której nie da się
-- wypełnić, byłoby martwym polem w schemacie.
--
-- NULL = NIE WIADOMO, nie „zero asfaltu": Overpass bywa niedostępny, ma limity
-- i nie pokrywa każdej drogi. Trasa sprzed tej migracji ma NULL do czasu
-- backfillu (`backfill_known_route_surface.php`), a widok wtedy po prostu nie
-- pokazuje paska — tak samo jak etap bez detekcji.
ALTER TABLE known_routes
  ADD COLUMN surface_asphalt_pct SMALLINT UNSIGNED NULL AFTER elevation_gain_m,
  ADD COLUMN surface_gravel_pct  SMALLINT UNSIGNED NULL AFTER surface_asphalt_pct,
  ADD COLUMN surface_trail_pct   SMALLINT UNSIGNED NULL AFTER surface_gravel_pct;
