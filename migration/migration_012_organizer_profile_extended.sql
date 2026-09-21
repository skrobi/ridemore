-- migration_012_organizer_profile_extended.sql
-- Rozszerzenie publicznego profilu organizatora (nowa makieta): lokalizacja,
-- rok założenia, języki, linki społecznościowe i flagi bezpieczeństwa
-- pokazywane na /organizatorzy/{slug} i edytowalne w /admin/profil-rozliczeniowy.
--
-- mysql -u USER -p ridemorebike2 < migration_012_organizer_profile_extended.sql

SET NAMES utf8mb4;

ALTER TABLE organizer_profiles
  ADD COLUMN city                       VARCHAR(120) NULL AFTER bio,
  -- Reużywa istniejący słownik 'region' (bieszczady/tatry/beskidy/mazury/
  -- podkarpacie) — dokładnie ten sam, którego już używa events.region_item_id.
  ADD COLUMN region_item_id             INT UNSIGNED NULL AFTER city,
  ADD COLUMN founded_year               SMALLINT UNSIGNED NULL AFTER region_item_id,
  ADD COLUMN languages                  VARCHAR(200) NULL AFTER founded_year,
  ADD COLUMN website_url                VARCHAR(255) NULL AFTER languages,
  ADD COLUMN facebook_url               VARCHAR(255) NULL AFTER website_url,
  ADD COLUMN instagram_url              VARCHAR(255) NULL AFTER facebook_url,
  ADD COLUMN strava_url                 VARCHAR(255) NULL AFTER instagram_url,
  ADD COLUMN safety_route_known         TINYINT(1) NOT NULL DEFAULT 0 AFTER strava_url,
  ADD COLUMN safety_first_aid_kit       TINYINT(1) NOT NULL DEFAULT 0 AFTER safety_route_known,
  ADD COLUMN safety_sweep_rider         TINYINT(1) NOT NULL DEFAULT 0 AFTER safety_first_aid_kit,
  ADD COLUMN safety_support_vehicle     TINYINT(1) NOT NULL DEFAULT 0 AFTER safety_sweep_rider,
  ADD COLUMN safety_first_aid_certified TINYINT(1) NOT NULL DEFAULT 0 AFTER safety_support_vehicle,
  ADD COLUMN safety_liability_insurance TINYINT(1) NOT NULL DEFAULT 0 AFTER safety_first_aid_certified,
  ADD CONSTRAINT fk_org_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL;
