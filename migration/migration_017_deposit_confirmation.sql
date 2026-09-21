-- migration_017_deposit_confirmation.sql
-- Rozdzielenie potwierdzenia zaliczki od potwierdzenia pełnej kwoty. Do tej pory
-- EventRsvp::confirmPayment() był jednym, niepodzielnym przełącznikiem
-- oczekuje_platnosci -> potwierdzony — organizator nie mógł oznaczyć "wpłynęła
-- tylko zaliczka, czekamy na resztę", a uczestnik dostawał mail "miejsce jest
-- Twoje" nawet gdy wpłacił tylko zaliczkę.

ALTER TABLE event_rsvps
  ADD COLUMN deposit_confirmed_at TIMESTAMP NULL AFTER payment_confirmed_by_user_id,
  ADD COLUMN deposit_confirmed_by_user_id BIGINT UNSIGNED NULL AFTER deposit_confirmed_at,
  ADD CONSTRAINT fk_rsvp_deposit_confirmed_by FOREIGN KEY (deposit_confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'oczekuje_doplaty', 'Oczekuje dopłaty', 7);
