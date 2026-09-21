-- migration_071_region_cell_counts.sql
-- MATERIALIZOWANY MIANOWNIK POKRYCIA REGIONÓW (2026-08-25).
--
-- `Discovery::regionProgress()` liczy społeczność i osobę od strony MAŁYCH
-- zbiorów (discovery_cell_totals / discovery_cells użytkownika), ale MIANOWNIK
-- — ile heksów ma każde województwo — zmienia się wyłącznie przy imporcie
-- geometrii (backfill_regions.php), a policzenie go w locie to skan grupujący
-- po 1,5 mln wierszy pokrycia (zmierzone na dev: ~1,5 s na każde wejście
-- na stronę). Tabela trzyma te 16 liczb; uzupełnia je ZAWSZE backfill
-- (jedyny pisarz — tak jak tile_epochs są własnością renderera kafli).
--
-- Pusta tabela NIE blokuje aplikacji: model spada wtedy na liczenie z
-- region_cells (środowiska sprzed pierwszego importu).
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_071_region_cell_counts.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS region_cell_counts (
  region_item_id INT UNSIGNED NOT NULL,
  cells_total    INT UNSIGNED NOT NULL,
  PRIMARY KEY (region_item_id),
  CONSTRAINT fk_rcc_item FOREIGN KEY (region_item_id)
      REFERENCES dictionary_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Start: przenosimy stan z istniejącego pokrycia (jeśli import już był).
INSERT INTO region_cell_counts (region_item_id, cells_total)
SELECT region_item_id, COUNT(*)
  FROM region_cells
 GROUP BY region_item_id;
