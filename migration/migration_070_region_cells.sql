-- migration_070_region_cells.sql
-- REGIONY ADMINISTRACYJNE Z POKRYCIEM HEKSOWYM (2026-08-25, decyzje usera).
--
-- Do tej pory region w tym serwisie to pozycja słownika BEZ geometrii — przez
-- co procent odkrycia nie miał mianownika („liczba bez pokrycia w danych jest
-- gorsza niż jej brak", patrz nagłówek discovery.php), a cel gry dało się
-- wskazać tylko trasą. Decyzje, które tę sytuację zamykają:
--
--   1. Regionami stają się WOJEWÓDZTWA (granice administracyjne, GeoJSON w
--      data/wojewodztwa.geojson). Autorskie pasma (Bieszczady, Tatry...)
--      schodzą — moduł zarządzania regionami powstanie później.
--   2. Liście pokrywają CAŁĄ Polskę; kontener „Polska" zostaje rodzicem
--      (hierarchia słownikowa działa od migr. 013 i liczniki i tak czytają
--      wyłącznie liście).
--   3. KAŻDY heks należy do DOKŁADNIE JEDNEGO regionu. Poligony województw są
--      rozłączne, więc regułę realizuje sam test środka heksu; klucz główny
--      (region_item_id, cell_id) blokuje podwójne przypisanie fizycznie.
--      Styki siatki dosypuje backfill_regions.php („nie musi być idealnie,
--      ważne, żeby nie brakło kafli").
--   4. „Odkryty region" = masz w nim ≥1 odkryty heks (Models\Discovery::
--      regionsForUser). Wydarzenia, na które byłeś, dają dalej punkty — ale
--      już NIE definiują regionu: region wydarzenia ≠ teren faktycznie
--      przejechany, a po heksach da się to rozstrzygnąć naprawdę.
--
-- Tabela jest MATERIALIZACJĄ geometrii (wzorzec known_route_cells): członkostwo
-- liczone raz przez backfill_regions.php, nie w locie. Zmiana SIZES_M w
-- Utils\DiscoveryGrid unieważnia ją TAKŻE — patrz ostrzeżenie przy SIZES_M.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_070_region_cells.sql
-- Na produkcji: run_migrations.php, potem backfill_regions.php.

SET NAMES utf8mb4;

CREATE TABLE region_cells (
  -- Pozycja słownika 'region' (liść-województwo). ON DELETE CASCADE:
  -- bez pozycji słownika członkostwo nie ma sensu, a twarde kasowanie pozycji
  -- i tak nie istnieje w UI (Dictionary::setActive()).
  region_item_id INT UNSIGNED NOT NULL,
  -- Ten sam typ co discovery_cells.cell_id (BIGINT ZE ZNAKIEM — identyfikator
  -- pola mieści się w zakresie dodatnim, patrz DiscoveryGrid::encode()).
  cell_id        BIGINT        NOT NULL,
  PRIMARY KEY (region_item_id, cell_id),
  -- NIEZMIENNIK „JEDEN HEKS = JEDEN REGION". Złożony klucz główny powyżej
  -- chroni tylko przed dublem tej SAMEJ pary — pozwoliłby przypisać jeden
  -- heks dwóm województwom naraz, a na tym opiera się procent pokrycia.
  -- Dlatego wyłączność stoi na osobnym unikalnym indeksie po samym cell_id;
  -- ten sam indeks obsługuje JOIN-y od strony skarbów i tras.
  UNIQUE KEY uq_rcell_cell (cell_id),
  CONSTRAINT fk_rcell_item FOREIGN KEY (region_item_id)
      REFERENCES dictionary_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 16 województw jako liście pod istniejącym kontenerem „Polska".
-- INSERT IGNORE + UNIQUE(dictionary_id, code): gdy środowisko ma już któryś
-- kod (prod potrafił dojść do własnych województw), pozycja zostaje SWOJA —
-- remap i deaktywacja poniżej i tak rozwiązują się po KODZIE, nie po id.
-- ---------------------------------------------------------
SET @region_dict = (SELECT id FROM dictionaries WHERE code = 'region');
SET @polska = (
  SELECT id FROM (
    SELECT id FROM dictionary_items
     WHERE dictionary_id = @region_dict AND code = 'polska'
  ) AS p
);

INSERT IGNORE INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, is_active) VALUES
  (@region_dict, @polska, 'dolnoslaskie',         'dolnośląskie',         1, 1),
  (@region_dict, @polska, 'kujawsko-pomorskie',   'kujawsko-pomorskie',   2, 1),
  (@region_dict, @polska, 'lubelskie',            'lubelskie',            3, 1),
  (@region_dict, @polska, 'lubuskie',             'lubuskie',             4, 1),
  (@region_dict, @polska, 'lodzkie',              'łódzkie',              5, 1),
  (@region_dict, @polska, 'malopolskie',          'małopolskie',          6, 1),
  (@region_dict, @polska, 'mazowieckie',          'mazowieckie',          7, 1),
  (@region_dict, @polska, 'opolskie',             'opolskie',             8, 1),
  (@region_dict, @polska, 'podkarpackie',         'podkarpackie',         9, 1),
  (@region_dict, @polska, 'podlaskie',            'podlaskie',           10, 1),
  (@region_dict, @polska, 'pomorskie',            'pomorskie',           11, 1),
  (@region_dict, @polska, 'slaskie',              'śląskie',             12, 1),
  (@region_dict, @polska, 'swietokrzyskie',       'świętokrzyskie',      13, 1),
  (@region_dict, @polska, 'warminsko-mazurskie',  'warmińsko-mazurskie', 14, 1),
  (@region_dict, @polska, 'wielkopolskie',        'wielkopolskie',       15, 1),
  (@region_dict, @polska, 'zachodniopomorskie',   'zachodniopomorskie',  16, 1);

-- ---------------------------------------------------------
-- REMAP REFERENCJI ze starych pasm na województwa — PRZED deaktywacją.
-- Mapowanie ręczne, po KODZIE starej pozycji (id różnią się między środowiskami).
-- CASE z ELSE NULL nie znajdzie dopasowania → JOIN nie łapie wiersza →
-- referencja zostaje na starej (nieaktywnej za chwilę) pozycji: nazwa dalej
-- się wyświetla (joiny są po id), liczników po liściach ta pozycja nie zasila.
-- Takie pozostałości raportuje backfill_regions.php — decyzję o nich zostawiamy
-- człowiekowi, nie CASE-owi.
--
-- Uzasadnienia mapowania: Bieszczady i Podkarpacie → podkarpackie; polska
-- część Tatr → małopolskie; Beskidy → śląskie (Beskid Śląski/Żywiecki);
-- Mazury → warmińsko-mazurskie.
-- ---------------------------------------------------------
UPDATE events e
JOIN dictionary_items old ON old.id = e.region_item_id
JOIN dictionary_items nw
  ON nw.dictionary_id = old.dictionary_id
 AND nw.parent_id = @polska
 AND nw.is_active = 1
 AND nw.code = CASE old.code
       WHEN 'bieszczady'  THEN 'podkarpackie'
       WHEN 'podkarpacie' THEN 'podkarpackie'
       WHEN 'tatry'       THEN 'malopolskie'
       WHEN 'beskidy'     THEN 'slaskie'
       WHEN 'mazury'      THEN 'warminsko-mazurskie'
       ELSE NULL END
SET e.region_item_id = nw.id;

UPDATE known_routes kr
JOIN dictionary_items old ON old.id = kr.region_item_id
JOIN dictionary_items nw
  ON nw.dictionary_id = old.dictionary_id
 AND nw.parent_id = @polska
 AND nw.is_active = 1
 AND nw.code = CASE old.code
       WHEN 'bieszczady'  THEN 'podkarpackie'
       WHEN 'podkarpacie' THEN 'podkarpackie'
       WHEN 'tatry'       THEN 'malopolskie'
       WHEN 'beskidy'     THEN 'slaskie'
       WHEN 'mazury'      THEN 'warminsko-mazurskie'
       ELSE NULL END
SET kr.region_item_id = nw.id;

UPDATE treasures t
JOIN dictionary_items old ON old.id = t.region_item_id
JOIN dictionary_items nw
  ON nw.dictionary_id = old.dictionary_id
 AND nw.parent_id = @polska
 AND nw.is_active = 1
 AND nw.code = CASE old.code
       WHEN 'bieszczady'  THEN 'podkarpackie'
       WHEN 'podkarpacie' THEN 'podkarpackie'
       WHEN 'tatry'       THEN 'malopolskie'
       WHEN 'beskidy'     THEN 'slaskie'
       WHEN 'mazury'      THEN 'warminsko-mazurskie'
       ELSE NULL END
SET t.region_item_id = nw.id;

UPDATE organizer_profiles op
JOIN dictionary_items old ON old.id = op.region_item_id
JOIN dictionary_items nw
  ON nw.dictionary_id = old.dictionary_id
 AND nw.parent_id = @polska
 AND nw.is_active = 1
 AND nw.code = CASE old.code
       WHEN 'bieszczady'  THEN 'podkarpackie'
       WHEN 'podkarpacie' THEN 'podkarpackie'
       WHEN 'tatry'       THEN 'malopolskie'
       WHEN 'beskidy'     THEN 'slaskie'
       WHEN 'mazury'      THEN 'warminsko-mazurskie'
       ELSE NULL END
SET op.region_item_id = nw.id;

-- ---------------------------------------------------------
-- Deaktywacja wszystkich pozostałych (autorskich) dzieci „Polski".
-- Nie kasujemy: Dictionary::setActive() to jedyne wyjście i tak, a referencje
-- niesremapowane muszą umieć się jeszcze wyświetlić.
-- ---------------------------------------------------------
UPDATE dictionary_items
   SET is_active = 0
 WHERE dictionary_id = @region_dict
   AND parent_id = @polska
   AND is_active = 1
   AND code NOT IN (
       'dolnoslaskie','kujawsko-pomorskie','lubelskie','lubuskie','lodzkie',
       'malopolskie','mazowieckie','opolskie','podkarpackie','podlaskie',
       'pomorskie','slaskie','swietokrzyskie','warminsko-mazurskie',
       'wielkopolskie','zachodniopomorskie');

-- Kontrola na oko po migracji: ma zostać dokładnie 16 aktywnych liści.
SELECT COUNT(*) AS aktywne_liscie_regionu
  FROM dictionary_items
 WHERE dictionary_id = @region_dict
   AND is_active = 1
   AND NOT EXISTS (
       SELECT 1 FROM dictionary_items child
        WHERE child.parent_id = dictionary_items.id AND child.is_active = 1);
