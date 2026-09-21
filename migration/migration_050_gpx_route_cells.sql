-- migration_050_gpx_route_cells.sql
-- Pola siatki odkryć dla DOWOLNEGO pliku GPX — cache liczony raz na plik.
--
-- POWÓD: strona wydarzenia ma pokazywać przy każdej trasie, ile NOWYCH pól
-- i punktów wniosłaby dla oglądającego („może się okazać, że podobną trasę już
-- odkrywałem i niewiele to wniesie" — user 2026-08-14). Odpowiedź wymaga pól
-- trasy PLANOWANEJ, a te trzeba wyliczyć z pliku.
--
-- Bez cache'u byłoby to parsowanie kilku plików GPX przy KAŻDYM wejściu na
-- stronę wydarzenia (zmierzone: ok. 100 ms na trasę 160 km; wielodniówka ma
-- plik na dzień). Pola pliku nie zmieniają się nigdy — plik jest niezmienny,
-- a przy podmianie dostaje nową nazwę — więc to idealny kandydat na cache
-- liczony raz i ważny bezterminowo.
--
-- KLUCZ TO HASH ZAWARTOŚCI, nie ścieżka ani id etapu. Trzy powody:
--   1. ten sam plik bywa podpięty w kilku miejscach (etap, wariant, znana
--      trasa) — liczymy go raz dla wszystkich,
--   2. nie ma dwóch nullowalnych kluczy obcych (stage_id / variant_id), które
--      trzeba by rozstrzygać przy każdym odczycie,
--   3. cache nie wie nic o tym, do czego plik jest podpięty, więc kolejne
--      zastosowanie (np. weryfikacja zgodności śladu w TrackController) dostaje
--      go za darmo.
--
-- Tabela jest ODTWARZALNA w całości: skasowanie jej niczego nie psuje, kolejne
-- odczyty policzą pola ponownie. Dlatego bez kluczy obcych i bez sprzątania.

CREATE TABLE gpx_route_cells (
  gpx_hash CHAR(64) NOT NULL,
  cell_id  BIGINT NOT NULL,
  PRIMARY KEY (gpx_hash, cell_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Znacznik „ten plik jest już policzony", potrzebny osobno: plik, którego ślad
-- nie dotknął ani jednego pola (uszkodzony, za krótki), nie ma wierszy wyżej
-- i bez tej tabeli byłby liczony od nowa przy każdym wejściu.
CREATE TABLE gpx_route_cell_runs (
  gpx_hash    CHAR(64) NOT NULL PRIMARY KEY,
  cells_count INT UNSIGNED NOT NULL DEFAULT 0,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
