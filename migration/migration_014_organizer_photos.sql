-- migration_014_organizer_photos.sql
-- Własne zdjęcia organizatora do mozaiki hero na /organizatorzy/{slug}
-- (dotąd tylko automatycznie z cover_photo_url ostatnich eventów, patrz
-- Organizer::recentCoverPhotos()) — teraz organizator może dodać własne,
-- mają pierwszeństwo przed automatycznymi. Przechowywane jako JSON, bo to
-- prosta, uporządkowana lista URL-i bez potrzeby osobnej tabeli.
--
-- mysql -u USER -p ridemorebike2 < migration_014_organizer_photos.sql

SET NAMES utf8mb4;

ALTER TABLE organizer_profiles
  ADD COLUMN hero_photo_urls JSON NULL AFTER bio;
