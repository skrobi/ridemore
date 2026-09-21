-- migration_028_event_group_conversations.sql
-- Kanał grupowy wydarzenia — prywatna dyskusja CAŁEJ grupy turnusu (edition),
-- w odróżnieniu od modułu 1:1 (conversations/messages, migration_020) i od
-- publicznego Q&A pod eventem (event_comments). Powód: przy zebranej grupie
-- pisanie 1:1 nie nadaje się do "podać tę samą wiadomość do wszystkich" ani do
-- wspólnej dyskusji — dotychczasowa "wysyłka masowa" organizatora rozbijała
-- broadcast na N osobnych wątków 1:1 (adresaci nie widzieli się nawzajem).
--
-- Kanał jest PER TURNUS (edition_id), nie per wydarzenie — bo zapis
-- (event_rsvps) jest per turnus, więc grupa listopadowa i wrześniowa to osobne
-- kanały. CZŁONKOSTWO liczone na żywo (bez tabeli członków): organizator/
-- współpracownik eventu LUB realny zapis na TEN turnus (status inny niż
-- 'anulowany'/'zainteresowany') — patrz Models\EventGroupConversation::canAccess.
-- Dostęp znika automatycznie po anulowaniu zapisu, spójnie z Message::canMessage.

CREATE TABLE event_group_conversations (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Klucz kanału = turnus. event_id zdenormalizowane pod tytuł/link/uprawnienia
  -- (unikamy JOIN-a do event_editions przy każdym wierszu skrzynki).
  edition_id       BIGINT UNSIGNED NOT NULL,
  event_id         BIGINT UNSIGNED NOT NULL,
  last_message_at  TIMESTAMP NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_group_edition (edition_id),
  CONSTRAINT fk_grpconv_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id) ON DELETE CASCADE,
  CONSTRAINT fk_grpconv_event   FOREIGN KEY (event_id)   REFERENCES events(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- is_organizer = czy nadawca był w chwili wysyłki organizatorem/współpracownikiem
-- (utrwalone przy zapisie, nie liczone na żywo) — pod plakietkę "Organizator" i
-- pod regułę powiadomień (mail do wszystkich tylko dla wiadomości organizatora).
CREATE TABLE event_group_messages (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_conversation_id  BIGINT UNSIGNED NOT NULL,
  sender_id              BIGINT UNSIGNED NOT NULL,
  body                   TEXT NOT NULL,
  is_organizer           BOOLEAN NOT NULL DEFAULT FALSE,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_grpmsg_conv   FOREIGN KEY (group_conversation_id) REFERENCES event_group_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_grpmsg_sender FOREIGN KEY (sender_id)             REFERENCES users(id)                     ON DELETE CASCADE,
  INDEX idx_grpmsg_conv_created (group_conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user znacznik przeczytania — kanał jest wieloosobowy, więc pojedyncze
-- messages.read_at z modułu 1:1 nie wystarcza. Nieprzeczytane = wiadomości w
-- kanale nowsze niż last_read_at usera i nie jego autorstwa.
CREATE TABLE event_group_reads (
  group_conversation_id  BIGINT UNSIGNED NOT NULL,
  user_id                BIGINT UNSIGNED NOT NULL,
  last_read_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_conversation_id, user_id),
  CONSTRAINT fk_grpread_conv FOREIGN KEY (group_conversation_id) REFERENCES event_group_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_grpread_user FOREIGN KEY (user_id)               REFERENCES users(id)                     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
