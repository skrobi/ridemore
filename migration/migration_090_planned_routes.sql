-- migration_090_planned_routes.sql
-- ROUTE PLANNER, ETAP 1 — MVP (2026-09-17, tasks/active/route-planner.md).
--
-- Jedna, minimalna tabela na prywatne, robocze trasy usera z plannera.
-- Świadomie NIE jest to `known_routes` (tamta to dane community/admina,
-- publiczne z definicji) — planowana trasa to szkicownik jednego usera,
-- bez widoczności/publicznego linku/wariantów (odłożone przez panel
-- architektoniczny, patrz tasks/active/route-planner.md).
--
-- `waypoints_json` = surowe punkty klikane przez usera (do ponownej edycji
-- trasy), `geometry_json` = finalna, wyliczona linia GeoJSON LineString
-- (do wyświetlenia i eksportu GPX) — dwa osobne pola, bo trasa wyliczona
-- z waypointów nie jest tym samym co same waypointy (routing dokłada punkty
-- pośrednie wzdłuż dróg).
--
-- `engine`/`profile` (nie enum w kodzie, proste VARCHAR — nie ma tu jeszcze
-- słownika, znane trasy też go nie mają) trzymają PROWENIENCJĘ wyliczenia:
-- którym silnikiem i dla jakiego roweru policzono tę trasę. Bez tego, przy
-- ewentualnej zmianie silnika routingu w przyszłości, nie dałoby się odróżnić
-- starych zapisanych tras od nowych bez migracji — tanie teraz (dwie kolumny
-- z sensownym DEFAULT), kosztowne jako dopisywana później migracja.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS planned_routes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(200) NOT NULL,
  waypoints_json MEDIUMTEXT NOT NULL,
  geometry_json  MEDIUMTEXT NOT NULL,
  distance_km    DECIMAL(7,2) NOT NULL,
  ascent_m       INT UNSIGNED NULL,
  descent_m      INT UNSIGNED NULL,
  duration_min   INT UNSIGNED NULL,
  engine         VARCHAR(20) NOT NULL DEFAULT 'osrm-public',
  profile        VARCHAR(20) NOT NULL DEFAULT 'cycling',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_planned_routes_user (user_id, updated_at),
  CONSTRAINT fk_planned_routes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
