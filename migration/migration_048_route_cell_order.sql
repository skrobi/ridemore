-- migration_048_route_cell_order.sql
-- Kolejność pól wzdłuż znanej trasy.
--
-- POWÓD: `known_route_cells` ma tylko parę (route_id, cell_id) i ŻADNEJ
-- informacji o przebiegu. Do liczenia postępu to wystarczało — postęp jest
-- przecięciem zbiorów i kolejność go nie obchodzi. Nie wystarcza od chwili,
-- w której trasa ma się RYSOWAĆ: warstwa „Znane trasy" na mapie odkryć łączy
-- środki pól w linię, a bez kolejności byłby to zygzak po kolejności wstawiania
-- do bazy, a nie przebieg szlaku.
--
-- Wartość bierze się z Utils\DiscoveryGrid::cellsForTrack(), które i tak zwraca
-- pola W KOLEJNOŚCI ŚLADU — wystarczyło przestać ją wyrzucać.
--
-- NULL dla tras dodanych wcześniej; ich linia rysuje się dopiero po ponownym
-- wgraniu GPX-a (backfill_known_route_order.php robi to bez dotykania postępu
-- uczestników — przelicza wyłącznie kolejność, nie zbiór pól).

ALTER TABLE known_route_cells
    ADD COLUMN sort_order INT UNSIGNED NULL AFTER cell_id;

CREATE INDEX idx_krc_order ON known_route_cells (route_id, sort_order);
