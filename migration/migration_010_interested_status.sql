-- migration_010_interested_status.sql
-- Nowy, najlżejszy status RSVP 'zainteresowany' — czysta deklaracja "obserwuję
-- to wydarzenie", zero zobowiązania. Nie liczy się do puli miejsc (join()/
-- confirmed_count/confirmedParticipants() filtrują wyłącznie 'potwierdzony',
-- ten status jest z definicji poza tym — zero zmian w tamtej logice). Zero
-- nowych kolumn potrzebnych — event_rsvps.status_item_id już wystarcza.
--
-- mysql -u USER -p ridemorebike2 < migration_010_interested_status.sql

SET NAMES utf8mb4;

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code = 'rsvp_status'), 'zainteresowany', 'Zainteresowany', 6);
