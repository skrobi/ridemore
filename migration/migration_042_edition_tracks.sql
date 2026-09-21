-- migration_042_edition_tracks.sql
-- Etap 8 — ŚLAD Z ODBYTEGO WYJAZDU. Zaostrzenie reguły, co liczy się jako
-- odkryte pole (decyzja usera 2026-08-12).
--
-- CO SIĘ ZMIENIA
-- --------------
-- Do tej pory Discovery liczyło pola z TRASY PLANOWANEJ — pliku GPX, który
-- organizator wgrywa przy tworzeniu wydarzenia (event_stages.gpx_url,
-- event_route_variants.gpx_url). To jest zapowiedź, nie dowód: kto skrócił
-- trasę, kto zawrócił w połowie i kto pojechał zupełnie inaczej, odkrywał
-- dokładnie to samo, co ten, kto przejechał wszystko. Był to znany, opisany
-- kompromis pierwszej wersji.
--
-- Od teraz pole liczy się WYŁĄCZNIE, gdy istnieje ślad z tego, co faktycznie
-- się wydarzyło:
--   1. organizator wgrał ślad Z IMPREZY po jej zakończeniu (user_id IS NULL) —
--      liczy się każdemu, kto potwierdził obecność na tym turnusie,
--   2. uczestnik wgrał WŁASNY ślad z tego wyjazdu (user_id ustawione) —
--      liczy się tylko jemu.
-- Ślad własny ma pierwszeństwo: jest prawdą o TEJ osobie, więc gdy istnieje,
-- ślad zbiorowy jej nie dotyczy. Inaczej wracałby problem, który ta zmiana
-- rozwiązuje.
--
-- Trasa planowana zostaje nietknięta i dalej robi to, co dotąd (mapa na
-- stronie wydarzenia, dopasowania, profil wysokości) — przestaje wyłącznie
-- zasilać Discovery.
--
-- PER TURNUS, NIE PER WYDARZENIE
-- ------------------------------
-- Trasa planowana wisi na wydarzeniu, bo jest jedna dla wszystkich terminów.
-- Ślad rzeczywisty jest z definicji inny w lipcu i w sierpniu — objazd,
-- skrócenie z powodu pogody, inna pętla przy tej samej nazwie. Dlatego
-- edition_id, tak jak obecność, kronika i peleton.
--
-- SKUTEK WDROŻENIA: dopóki nikt nie wgra ani jednego śladu rzeczywistego,
-- mapa odkryć jest PUSTA. To jest poprawne — wcześniejsze pola pochodziły z
-- zapowiedzi, nie z przejazdów.
--
-- Lokalnie: wgraj wprost do ridemorebike2. Na produkcji: run_migrations.php,
-- a po nim backfill_discovery.php --rebuild.
-- mysql -u USER -p ridemorebike2 < migration_042_edition_tracks.sql

SET NAMES utf8mb4;

CREATE TABLE edition_tracks (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id         BIGINT UNSIGNED NOT NULL,
  -- NULL = ślad Z IMPREZY (organizator; dotyczy wszystkich uczestników turnusu).
  -- Ustawione = ślad JEDNEJ osoby (dotyczy wyłącznie jej).
  -- Jedna tabela zamiast dwóch, bo poza tym rozróżnieniem obsługa jest
  -- identyczna: ten sam upload, ten sam parser, to samo liczenie pól.
  user_id            BIGINT UNSIGNED NULL,
  gpx_url            VARCHAR(500) NOT NULL,
  label              VARCHAR(150) NULL,             -- np. "Dzień 2" przy wielodniówce
  distance_km        DECIMAL(7,2) NOT NULL DEFAULT 0,
  uploaded_by_user_id BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Bez klucza unikalnego na (edition_id, user_id): wielodniówka ma osobny
  -- plik na każdy dzień, więc wiele wierszy na tę samą parę jest normą.
  KEY idx_et_edition_user (edition_id, user_id),
  CONSTRAINT fk_et_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id) ON DELETE CASCADE,
  CONSTRAINT fk_et_user    FOREIGN KEY (user_id)    REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_et_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
