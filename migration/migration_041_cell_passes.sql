-- migration_041_cell_passes.sql
-- Etap 8, warstwa HEATMAPA — ile RAZY przez dane pole ktoś przejechał.
--
-- PO CO OSOBNY LICZNIK
-- --------------------
-- discovery_cell_totals.riders_count mówi, ILU LUDZI kiedykolwiek odkryło dane
-- pole — i tylko tyle, bo discovery_cells z założenia zapisuje WYŁĄCZNIE
-- pierwszy przejazd danej osoby (§5: „odkrywanie jest ważniejsze od
-- powtarzania", pilnowane kluczem głównym). Powtórne przejazdy nie zostawiały
-- dotąd żadnego śladu.
--
-- Heatmapa zbudowana na riders_count pokazywałaby dokładnie to samo, co warstwa
-- mgły, tylko w innym kolorze. „Gorące" pole to takie, przez które JEŻDZI SIĘ
-- CZĘSTO — lokalna pętla przejechana 50 razy przez 5 osób ma być cieplejsza niż
-- odległy szlak, przez który 5 osób przejechało po razie. Ta różnica wymaga
-- liczenia przejazdów, nie odkrywców.
--
-- Punktacja tego NIE dotyka: powtórzenia nadal nie dają ani jednego punktu
-- (§5 i §25 bez zmian). passes_count jest liczbą wyłącznie do pokazania.
--
-- DLACZEGO TABELA, A NIE SAM LICZNIK
-- ----------------------------------
-- rider_activity_cells trzyma, które pola dotknął KAŻDY przejazd — także taki,
-- który nie odkrył niczego nowego. Bez tego passes_count byłby jedyną kopią
-- tej informacji i przestałby być odtwarzalny, a cały moduł stoi na zasadzie
-- „agregat da się zbudować od zera ze źródła" (tak działa rider_connections i
-- tak zachowuje się discovery_cell_totals — jest to sprawdzane testem).
--
-- Koszt: wiersz na (przejazd × pole). Przy 500-metrowym polu to ok. 2 wiersze
-- na kilometr trasy — najszybciej rosnąca tabela w bazie, ale wąska (16 bajtów
-- danych) i bez żadnych zapytań poza agregacją.
--
-- Lokalnie: wgraj wprost do ridemorebike2. Na produkcji: run_migrations.php,
-- a po nim backfill_discovery.php --rebuild (inaczej passes_count zostanie
-- zerowy dla historii sprzed tej migracji).
-- mysql -u USER -p ridemorebike2 < migration_041_cell_passes.sql

SET NAMES utf8mb4;

CREATE TABLE rider_activity_cells (
  activity_id BIGINT UNSIGNED NOT NULL,
  cell_id     BIGINT NOT NULL,
  PRIMARY KEY (activity_id, cell_id),
  -- Pod agregację „ile przejazdów przez to pole" bez pełnego skanu.
  KEY idx_rac_cell (cell_id),
  CONSTRAINT fk_rac_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zmaterializowana suma z tabeli wyżej — mapa nie może liczyć COUNT(*) po
-- wszystkich przejazdach wszystkich ludzi przy każdym przesunięciu kadru (§32).
ALTER TABLE discovery_cell_totals
  ADD COLUMN passes_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER riders_count;
