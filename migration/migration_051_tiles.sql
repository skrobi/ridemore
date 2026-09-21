-- migration_051_tiles.sql
-- KAFLE RASTROWE — mapy przestają ładować pliki GPX do przeglądarki.
--
-- POWÓD (user 2026-08-14): profil rowerzysty z 600 śladami pobierał 600 plików
-- GPX na jedno wejście. Zmierzone na prawdziwych plikach z assets/uploads/gpx
-- (mediana 180 kB / 2107 punktów): ok. 108 MB pobierania, ok. 25 s do pierwszego
-- obrazu, ok. 2,7 s zwiechy na każdy krok zoomu. A to strona PUBLICZNA, więc
-- koszt płaci każdy odwiedzający.
--
-- Kafel kosztuje tyle samo przy 6 śladach co przy 6000: zmierzone 16–30 ms
-- w GD, potem plik leży na dysku i serwuje go Apache bez budzenia PHP
-- (reguła `RewriteCond %{REQUEST_FILENAME} !-f` w .htaccess już to robi).
--
-- CZTERY TABELE, KAŻDA NA INNE PYTANIE:
--   gpx_geometry  — CZYM rysować (punkty pliku, raz sparsowane)
--   gpx_tiles     — GDZIE tego szukać (który plik przechodzi przez który kafel)
--   tile_epochs   — CZY to, co w przeglądarce, jest jeszcze aktualne
--   tile_cache    — CO leży na dysku (pod sprzątanie i limit i-węzłów)


-- 1. GEOMETRIA PLIKU, RAZ SPARSOWANA -----------------------------------------
--
-- Klucz to HASH ZAWARTOŚCI, dokładnie jak w gpx_route_cells (migr. 050) i z tych
-- samych powodów: ten sam plik bywa podpięty w kilku miejscach (etap, wariant,
-- znana trasa), a plik jest niezmienny — podmiana daje nową nazwę i nowy hash.
--
-- WSPÓŁRZĘDNE TRZYMAMY W PIKSELACH ŚWIATA NA ZOOMIE 18, nie w stopniach. To jest
-- decyzja, która przenosi całą trygonometrię z czasu rysowania do czasu zapisu:
--   * kafel dowolnego zoomu z <= 18 dostaje się z tych liczb PRZESUNIĘCIEM
--     BITOWYM (px >> (18 - z)), bez jednego sinusa,
--   * przy 2^18 * 256 = 67 108 864 cały świat mieści się w 27 bitach, więc
--     INT ze znakiem wystarcza z zapasem,
--   * rozdzielczość na 52°N to ok. 0,37 m na piksel — poniżej dokładności GPS,
--     więc nic sensownego nie tracimy.
-- Zmierzone: plik 179 864 B GPX -> 16 856 B tej geometrii (11x mniej).
--
-- points to MEDIUMBLOB par INT (pack('l*')), a nie tabela punktów: 600 śladów
-- to 1,26 mln punktów, czyli tyle samo wierszy do przeczytania przy każdym
-- kaflu. Blob czyta się jednym I/O i rozpakowuje jednym unpack().
CREATE TABLE gpx_geometry (
  gpx_hash    CHAR(64) NOT NULL PRIMARY KEY,
  point_count INT UNSIGNED NOT NULL DEFAULT 0,
  -- Prostokąt otaczający, też w pikselach z18 — pierwszy, najtańszy odsiew
  -- przy rysowaniu kafla i przy trafieniach.
  min_px      INT NOT NULL,
  min_py      INT NOT NULL,
  max_px      INT NOT NULL,
  max_py      INT NOT NULL,
  points      MEDIUMBLOB NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 2. INDEKS KAFLI ------------------------------------------------------------
--
-- „Które ślady przechodzą przez ten kafel" — bez tego kafel musiałby przejrzeć
-- geometrię wszystkich śladów w bazie.
--
-- JEDEN POZIOM WYSTARCZA NA WSZYSTKIE ZOOMY i to jest różnica względem siatki
-- heksagonalnej, gdzie trzeba było dołożyć parent_res0..3 (migr. 047). Kafle są
-- zagnieżdżone przez samo przesunięcie bitowe — rodzic kafla (x, y) to
-- (x >> 1, y >> 1) — więc zapytanie o kafel zoomu z sprowadza się do zakresu
-- na tych samych kolumnach: tx BETWEEN x << (14 - z) AND ((x + 1) << (14 - z)) - 1.
-- Prostokątne grupowanie nie kłóci się tu z geometrią pola, bo pole JEST
-- prostokątem.
--
-- Poziom bazowy 14, bo tam kończy się generowanie (wyżej Leaflet skaluje obraz):
-- z15 to 804 000 kafli na samą Polskę i 15 GB, co przy limitach i-węzłów na
-- współdzielonym hostingu jest nie do przyjęcia.
CREATE TABLE gpx_tiles (
  gpx_hash CHAR(64) NOT NULL,
  tx       INT UNSIGNED NOT NULL,
  ty       INT UNSIGNED NOT NULL,
  PRIMARY KEY (gpx_hash, tx, ty),
  KEY idx_gpx_tiles_xy (tx, ty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 3. EPOKI — UNIEWAŻNIANIE PO STRONIE PRZEGLĄDARKI ---------------------------
--
-- Skasowanie pliku z dysku załatwia serwer, ale NIE załatwia przeglądarek: na
-- *.png leci `Cache-Control: immutable, max-age=31536000` (.htaccess), więc raz
-- pobrany kafel zostaje na rok. Dlatego adres kafla niesie ?v=<epoka>, a zmiana
-- danych podbija epokę — dokładnie ten sam wzorzec co ?v=<filemtime>
-- w Utils\View::asset(), tylko liczony dla zbioru plików zamiast jednego.
--
-- Klucz jest podwójny (warstwa + zakres), bo dodanie śladu unieważnia mapę
-- społeczności, mapę autora i mapę tego turnusu, ale NIE mapy pozostałych osób.
CREATE TABLE tile_epochs (
  layer      VARCHAR(24) NOT NULL,
  cache_key  VARCHAR(48) NOT NULL,
  epoch      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (layer, cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 4. REJESTR WYGENEROWANYCH KAFLI --------------------------------------------
--
-- Po co rejestr, skoro pliki są na dysku: bo policzenie ich na dysku wymaga
-- przejścia drzewa katalogów, a przycięcie „najstarszych" — odczytania mtime
-- każdego z osobna. Przy setkach tysięcy plików to nie jest operacja, którą
-- można zrobić w trakcie żądania.
--
-- Kafle są w PEŁNI ODTWARZALNE — skasowanie tej tabeli i całego katalogu niczego
-- nie psuje, kolejne wejście wygeneruje je od nowa. Dlatego bez kluczy obcych.
--
-- last_used_at (nie created_at) jest tym, po czym przycinamy: kafel oglądany co
-- tydzień od roku jest warty trzymania, świeżo wygenerowany kafel pustkowia nie.
CREATE TABLE tile_cache (
  layer        VARCHAR(24) NOT NULL,
  cache_key    VARCHAR(48) NOT NULL,
  z            TINYINT UNSIGNED NOT NULL,
  x            INT UNSIGNED NOT NULL,
  y            INT UNSIGNED NOT NULL,
  bytes        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (layer, cache_key, z, x, y),
  KEY idx_tile_cache_age (last_used_at),
  KEY idx_tile_cache_key (layer, cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
