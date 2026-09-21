-- migration_018_payment_ledger.sql
-- Zamiast sztywnego wyboru "zaliczka albo całość", organizator wpisuje
-- FAKTYCZNIE otrzymaną kwotę — uczestnik może wpłacić więcej niż zaliczkę,
-- ale mniej niż całość, albo dopłacać w kilku ratach. Rejestr wpłat
-- (event_rsvp_payments) trzyma każdą potwierdzoną wpłatę osobno, żeby dało
-- się później zweryfikować sumę wpłat i zrobić bilans per uczestnik.
--
-- deposit_confirmed_at/by (migracja 017) traciły dokładne znaczenie "to była
-- zaliczka" — teraz to po prostu "ostatnia częściowa wpłata", więc zmieniamy
-- nazwę na last_payment_confirmed_at/by.

CREATE TABLE event_rsvp_payments (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rsvp_id               BIGINT UNSIGNED NOT NULL,
  amount                DECIMAL(10,2) NOT NULL,
  confirmed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_by_user_id  BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_rsvp_payment_rsvp FOREIGN KEY (rsvp_id) REFERENCES event_rsvps(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsvp_payment_confirmed_by2 FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE event_rsvps
  CHANGE COLUMN deposit_confirmed_at last_payment_confirmed_at TIMESTAMP NULL,
  CHANGE COLUMN deposit_confirmed_by_user_id last_payment_confirmed_by_user_id BIGINT UNSIGNED NULL;

-- Backfill: RSVP-y już potwierdzone (pełna kwota) PRZED tą migracją nie mają
-- żadnego wpisu w rejestrze — dopisujemy jedną wpłatę na pełną cenę, żeby
-- bilans się zgadzał także dla wcześniejszych potwierdzeń.
INSERT INTO event_rsvp_payments (rsvp_id, amount, confirmed_at, confirmed_by_user_id)
SELECT r.id, ep.price_amount, r.payment_confirmed_at, r.payment_confirmed_by_user_id
FROM event_rsvps r
JOIN dictionary_items di ON di.id = r.status_item_id AND di.code = 'potwierdzony'
JOIN event_pricing ep ON ep.event_id = r.event_id AND ep.price_amount > 0
WHERE r.payment_confirmed_at IS NOT NULL AND r.payment_confirmed_by_user_id IS NOT NULL;
