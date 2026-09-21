-- migration_013_dictionary_hierarchy.sql
-- Hierarchia w słownikach (dictionaries/dictionary_items): parent_id
-- samo-referencyjny, nullable — generyczny dla wszystkich słowników, dowolna
-- głębokość. Na start używany pod region: nowy element najwyższego poziomu
-- "Polska", pod którym wiszą dotychczasowe płaskie regiony (Bieszczady,
-- Tatry, Beskidy, Mazury, Podkarpacie) — pod docelową ekspansję o kolejne
-- państwa europejskie z ich własnymi regionami.
--
-- ON DELETE RESTRICT: nie da się skasować rodzica dopóki ma dzieci — i tak
-- nie ma w UI twardego kasowania pozycji słownika, patrz Dictionary::setActive().
--
-- mysql -u USER -p ridemorebike2 < migration_013_dictionary_hierarchy.sql

SET NAMES utf8mb4;

ALTER TABLE dictionary_items
  ADD COLUMN parent_id INT UNSIGNED NULL AFTER dictionary_id,
  ADD CONSTRAINT fk_ditems_parent FOREIGN KEY (parent_id)
      REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  ADD INDEX idx_ditems_parent (parent_id);

-- Nowa pozycja najwyższego poziomu "Polska" w słowniku region. sort_order=0
-- żeby wypadła przed dotychczasowymi (choć i tak nie jest zwracana przez
-- Dictionary::items() — items() zwraca tylko liście, więc kontener "Polska"
-- jest tam niewidoczny, a dotychczasowi konsumenci selecta regionu (formularz
-- eventu, filtry na stronie głównej) nie wymagają żadnej zmiany).
INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, is_active)
VALUES ((SELECT id FROM dictionaries WHERE code = 'region'), NULL, 'polska', 'Polska', 0, 1);

-- Istniejące 5 regionów staje się dziećmi "Polski". Podzapytanie owinięte w
-- dodatkowy SELECT (alias `polska`) bo MySQL nie pozwala w UPDATE-ie
-- referencjonować wprost tej samej tabeli, którą się aktualizuje.
UPDATE dictionary_items
SET parent_id = (
  SELECT id FROM (
    SELECT id FROM dictionary_items
    WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'region') AND code = 'polska'
  ) AS polska
)
WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'region')
  AND code IN ('bieszczady', 'tatry', 'beskidy', 'mazury', 'podkarpacie');
