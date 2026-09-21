-- migration_007_paid_rsvp_reservations.sql
-- Zapis na płatne wydarzenie z ręcznym potwierdzeniem wpłaty. Nowy status
-- RSVP 'oczekuje_platnosci' (wstępna rezerwacja, NIE liczy się do puli —
-- join()/confirmedParticipants()/confirmed_count już filtrują wyłącznie
-- status.code='potwierdzony', więc żadna istniejąca logika nie wymaga zmian)
-- + kolumny audytowe kto/kiedy ręcznie potwierdził wpłatę.
--
-- mysql -u USER -p ridemorebike2 < migration_007_paid_rsvp_reservations.sql

SET NAMES utf8mb4;

ALTER TABLE event_rsvps
  ADD COLUMN payment_confirmed_at TIMESTAMP NULL AFTER joined_at,
  ADD COLUMN payment_confirmed_by_user_id BIGINT UNSIGNED NULL AFTER payment_confirmed_at,
  ADD CONSTRAINT fk_rsvp_payment_confirmed_by FOREIGN KEY (payment_confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code = 'rsvp_status'), 'oczekuje_platnosci', 'Oczekuje na płatność', 2);

UPDATE dictionary_items SET sort_order = 3 WHERE code = 'lista_rezerwowa' AND dictionary_id = (SELECT id FROM dictionaries WHERE code = 'rsvp_status');
UPDATE dictionary_items SET sort_order = 4 WHERE code = 'anulowany' AND dictionary_id = (SELECT id FROM dictionaries WHERE code = 'rsvp_status');
