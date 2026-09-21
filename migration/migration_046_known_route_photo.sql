-- migration_046_known_route_photo.sql
-- Etap 8A/8d — zdjęcie znanej trasy.
--
-- Karty tras w projekcie grafika mają fotografię: „Velo Czorsztyn" obok zdjęcia
-- doliny znaczy dla człowieka coś innego niż sama nazwa i pasek procentu.
-- To jedyna rzecz z tamtych kart, której baza jeszcze nie miała — nazwa,
-- dystans, region i postęp były już policzone.
--
-- Ta sama ścieżka co okładka wydarzenia: Utils\Upload::saveCoverPhoto()
-- (walidacja typu, limit rozmiaru, auto-skalowanie zbyt dużych plików,
-- katalog assets/uploads/covers). Zero nowego mechanizmu uploadu.
--
-- NULL = brak zdjęcia i tak zostaje dla większości tras — kafelek pokazuje
-- wtedy spokojne tło zamiast dziury, więc trasa bez fotografii nie wygląda
-- na zepsutą.
--
-- Lokalnie: wgraj wprost do ridemorebike2. Na produkcji: run_migrations.php.
-- mysql -u USER -p ridemorebike2 < migration_046_known_route_photo.sql

SET NAMES utf8mb4;

ALTER TABLE known_routes
  ADD COLUMN cover_photo_url VARCHAR(500) NULL AFTER gpx_url;
