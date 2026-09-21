-- migration_023_event_editions.sql
-- Turnusy (event_editions): jedno wydarzenie (trasa/opis/cena/organizator)
-- może mieć wiele terminów wyjazdu (np. cykliczna wycieczka do Toskanii w
-- kilku turnusach). Każdy turnus ma WŁASNĄ datę startu, WŁASNY limit miejsc
-- i WŁASNYCH uczestników — jeden termin może się zapełnić, inny zostać
-- otwarty. Itinerarz (event_stages: trasa/dystans/GPX/nocleg per dzień)
-- zostaje wspólnym SZABLONEM dla wszystkich turnusów — dzień 1, dzień 2...
-- bez własnej absolutnej daty; faktyczna data dnia liczy się jako
-- edition.start_date + (day_number - 1), patrz Models\EventEdition.

CREATE TABLE event_editions (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id           BIGINT UNSIGNED NOT NULL,
  start_date         DATE NOT NULL,
  is_cancelled       TINYINT(1) NOT NULL DEFAULT 0,   -- odwołanie POJEDYNCZEGO turnusu, niezależnie od reszty (events.status_item_id dalej rządzi całym wydarzeniem: szkic/publikacja/odwołanie wszystkiego/zakończenie)
  max_participants   SMALLINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_edition_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_event_start (event_id, start_date),
  INDEX idx_edition_start_date (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: każde istniejące wydarzenie dostaje dokładnie jeden turnus =
-- jego dotychczasowa start_date/max_participants — transparentnie, bez
-- utraty danych, bez zmiany widocznego zachowania dla już opublikowanych wydarzeń.
INSERT INTO event_editions (event_id, start_date, max_participants)
SELECT id, start_date, max_participants FROM events;

-- event_rsvps: zapis dotyczy teraz KONKRETNEGO turnusu, nie całego wydarzenia
-- (ten sam user może zapisać się na różne terminy tego samego wydarzenia).
-- event_id zostaje (denormalizacja pod zapytania po całym evencie, np.
-- Message::canMessage()/EventReview — te sprawdzają relację z wydarzeniem
-- jako takim, niezależnie od konkretnego terminu).
ALTER TABLE event_rsvps ADD COLUMN edition_id BIGINT UNSIGNED NULL AFTER event_id;

UPDATE event_rsvps r
JOIN event_editions ed ON ed.event_id = r.event_id
SET r.edition_id = ed.id;

ALTER TABLE event_rsvps
  MODIFY COLUMN edition_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_rsvp_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id) ON DELETE CASCADE,
  DROP INDEX uniq_event_user,
  ADD UNIQUE KEY uniq_edition_user (edition_id, user_id),
  ADD INDEX idx_rsvp_event (event_id);
