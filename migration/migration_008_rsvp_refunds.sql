-- migration_008_rsvp_refunds.sql
-- Anulowanie opłaconego zapisu — nowy status RSVP 'oczekuje_zwrotu' (uczestnik
-- zrezygnował, czeka na ręczne potwierdzenie zwrotu przez organizatora) +
-- kolumny audytowe, analogicznie do payment_confirmed_at/by z migracji 007.
--
-- mysql -u USER -p ridemorebike2 < migration_008_rsvp_refunds.sql

SET NAMES utf8mb4;

ALTER TABLE event_rsvps
  ADD COLUMN refund_requested_at TIMESTAMP NULL AFTER payment_confirmed_by_user_id,
  ADD COLUMN refund_processed_at TIMESTAMP NULL AFTER refund_requested_at,
  ADD COLUMN refund_processed_by_user_id BIGINT UNSIGNED NULL AFTER refund_processed_at,
  ADD CONSTRAINT fk_rsvp_refund_processed_by FOREIGN KEY (refund_processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code = 'rsvp_status'), 'oczekuje_zwrotu', 'Oczekuje zwrotu', 5);
