-- migration_002_region.sql
-- Dodaje słownik regionów geograficznych i kolumnę events.region_item_id,
-- pod filtrowanie na liście wydarzeń.
--
-- mysql -u USER -p ridemorebike2 < migration_002_region.sql

ALTER TABLE events
  ADD COLUMN region_item_id INT UNSIGNED NULL AFTER pace_group_item_id,
  ADD CONSTRAINT fk_ev_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  ADD INDEX idx_events_region (region_item_id);

INSERT INTO dictionaries (code, name) VALUES ('region', 'Region');

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order) VALUES
  ((SELECT id FROM dictionaries WHERE code='region'), 'bieszczady', 'Bieszczady', 1),
  ((SELECT id FROM dictionaries WHERE code='region'), 'tatry', 'Tatry', 2),
  ((SELECT id FROM dictionaries WHERE code='region'), 'beskidy', 'Beskidy', 3),
  ((SELECT id FROM dictionaries WHERE code='region'), 'mazury', 'Mazury', 4),
  ((SELECT id FROM dictionaries WHERE code='region'), 'podkarpacie', 'Podkarpacie', 5);
