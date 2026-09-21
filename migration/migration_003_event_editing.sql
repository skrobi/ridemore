-- migration_003_event_editing.sql
-- Fundament pod formularz dodawania/edycji wydarzeń: rola admina, współpracownicy
-- organizatora, zapisy zewnętrzne, profil rozliczeniowy, współrzędne per etap,
-- sprzęt jako wolny tekst zamiast zamkniętego słownika.
--
-- mysql -u USER -p ridemorebike2 < migration_003_event_editing.sql

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1. ROLA ADMINA
-- ---------------------------------------------------------

ALTER TABLE users
  ADD COLUMN is_admin BOOLEAN NOT NULL DEFAULT FALSE AFTER email_verified_at;

-- ---------------------------------------------------------
-- 2. WSPÓŁPRACOWNICY ORGANIZATORA
-- Dostęp na poziomie organizatora — do WSZYSTKICH jego wydarzeń, nie ad-hoc
-- per event. organizer_user_id = events.organizer_id (właściciel).
-- ---------------------------------------------------------

CREATE TABLE organizer_collaborators (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_user_id  BIGINT UNSIGNED NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_collab_organizer FOREIGN KEY (organizer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_collab_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_collab (organizer_user_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 3. PROFIL ROZLICZENIOWY ORGANIZATORA
-- Wymagany przed publikacją płatnego eventu z zapisami wewnętrznymi
-- (płatność idzie przez ridemore.bike, więc potrzebne dane do faktur/wypłat).
-- completed_at = NULL oznacza niekompletny profil.
-- ---------------------------------------------------------

CREATE TABLE organizer_billing_profiles (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_user_id  BIGINT UNSIGNED NOT NULL UNIQUE,
  legal_name         VARCHAR(200) NOT NULL,
  address            VARCHAR(255) NOT NULL,
  tax_id             VARCHAR(50) NULL,       -- NIP — nie dotyczy osób fizycznych
  bank_account       VARCHAR(34) NOT NULL,   -- IBAN
  completed_at       TIMESTAMP NULL,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_billing_user FOREIGN KEY (organizer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 4. ZAPISY WEWNĘTRZNE / ZEWNĘTRZNE
-- NULL w registration_type_item_id = internal (spójnie z resztą schematu:
-- brak wiersza/wartości = stan domyślny, bez dodatkowej flagi).
-- ---------------------------------------------------------

ALTER TABLE events
  ADD COLUMN registration_type_item_id INT UNSIGNED NULL AFTER status_item_id,
  ADD COLUMN external_registration_url VARCHAR(500) NULL AFTER registration_type_item_id,
  ADD CONSTRAINT fk_ev_registration_type FOREIGN KEY (registration_type_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL;

-- ---------------------------------------------------------
-- 5. WSPÓŁRZĘDNE START/META PER ETAP
-- Dotąd tylko tekstowe start_point/end_point — formularz chce pinezki na mapie.
-- ---------------------------------------------------------

ALTER TABLE event_stages
  ADD COLUMN start_lat DECIMAL(9,6) NULL AFTER start_point,
  ADD COLUMN start_lng DECIMAL(9,6) NULL AFTER start_lat,
  ADD COLUMN end_lat DECIMAL(9,6) NULL AFTER end_point,
  ADD COLUMN end_lng DECIMAL(9,6) NULL AFTER end_lat;

-- ---------------------------------------------------------
-- 6. SPRZĘT JAKO WOLNY TEKST
-- Prototyp każe wpisywać dowolną nazwę ("np. kask, oświetlenie"), nie wybierać
-- ze słownika. equipment_item_id zostaje jako opcjonalne pole na przyszłość
-- (np. pod autouzupełnianie), ale nie jest już wymagane.
-- ---------------------------------------------------------

ALTER TABLE event_equipment
  MODIFY equipment_item_id INT UNSIGNED NULL,
  ADD COLUMN name VARCHAR(150) NOT NULL AFTER equipment_item_id;

-- ---------------------------------------------------------
-- 7. BRAKUJĄCE POZYCJE SŁOWNIKOWE
-- ---------------------------------------------------------

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order) VALUES
  ((SELECT id FROM dictionaries WHERE code='surface_type'), 'gravel', 'Gravel', 4),
  ((SELECT id FROM dictionaries WHERE code='accommodation_type'), 'hotel', 'Hotel', 3),
  ((SELECT id FROM dictionaries WHERE code='accommodation_type'), 'camping', 'Camping', 4),
  ((SELECT id FROM dictionaries WHERE code='currency'), 'EUR', 'Euro', 2);

INSERT INTO dictionaries (code, name) VALUES
  ('registration_type', 'Sposób zapisów');

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order) VALUES
  ((SELECT id FROM dictionaries WHERE code='registration_type'), 'internal', 'Na ridemore.bike', 1),
  ((SELECT id FROM dictionaries WHERE code='registration_type'), 'external', 'Link zewnętrzny', 2);
