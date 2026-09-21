-- migration_076_gpx_geometry_trimmed.sql
-- PRZYCIĘTA GEOMETRIA SOLO — heatmapa społeczności widzi też przejazdy solo (2026-08-28).
--
-- Zgłoszenie usera: był przekonany, że warstwa „Ślady"/heatmapa na mapie
-- społeczności liczy WSZYSTKIE przejazdy, nie tylko te przypisane do wyjazdów
-- (`edition_tracks`) — a solo to WIĘKSZOŚĆ tego, co ludzie realnie jeżdżą.
-- Efekt: odkryte pole (liczone ze WSZYSTKICH przejazdów) nie miało pod sobą
-- śladu na mapie (liczonej tylko z wyjazdów) — rozjazd między dwiema
-- warstwami tej samej strony.
--
-- DLACZEGO OSOBNA PARA TABEL, A NIE `gpx_geometry`/`gpx_tiles` WPROST
-- --------------------------------------------------------------------
-- Plik solo jest surowy i zaczyna/kończy się pod domem rowerzysty (§27) —
-- `gpx_geometry` niesie PEŁNĄ geometrię, bo jej klucze (`me`, znane trasy,
-- kronika) mają prawo widzieć cały przejazd. Klucz `all` (mapa społeczności)
-- leży PUBLICZNIE na dysku pod adresem do zgadnięcia i tego prawa nie ma.
-- Stąd DRUGA, PRZYCIĘTA wersja geometrii tego samego pliku (`DiscoveryGrid::
-- trimEnds`, ten sam promień co przy polach odkryć, `DiscoveryScoring::
-- homeTrimRadiusM()`) — w osobnych tabelach, żeby żadna gałąź kodu nie mogła
-- przez pomyłkę wziąć PEŁNEJ geometrii tam, gdzie ma iść WYŁĄCZNIE przycięta.
--
-- KLUCZ TO TEN SAM `gpx_hash` co w `gpx_geometry` (hash SUROWEGO pliku) —
-- to nie kolizja nazw, to świadoma relacja 1:1 „przycięty wariant tego
-- pliku". Kolor NIE jest tu potrzebny: heatmapa community maluje wszystko
-- jednym stylem (`TileSource::STYLES['heat']`), nie paletą per ślad.
--
-- Kształt kolumn kopiuje `gpx_geometry`/`gpx_tiles` (migr. 051) celowo —
-- to ta sama logika odczytu/zapisu (`Models\GpxGeometry`), tylko druga
-- para tabel, żeby dało się jej użyć bez zmiany semantyki oryginału.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_076_gpx_geometry_trimmed.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gpx_geometry_trimmed (
  gpx_hash    CHAR(64) NOT NULL PRIMARY KEY,
  point_count INT UNSIGNED NOT NULL DEFAULT 0,
  min_px      INT NOT NULL,
  min_py      INT NOT NULL,
  max_px      INT NOT NULL,
  max_py      INT NOT NULL,
  points      MEDIUMBLOB NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gpx_tiles_trimmed (
  gpx_hash CHAR(64) NOT NULL,
  tx       INT UNSIGNED NOT NULL,
  ty       INT UNSIGNED NOT NULL,
  PRIMARY KEY (gpx_hash, tx, ty),
  KEY idx_gpx_tiles_trimmed_xy (tx, ty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
