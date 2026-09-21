-- migration_020_messages.sql
-- Prosty moduł wiadomości 1:1 — bez grupowych wątków, bez czatu na żywo
-- (odświeżenie strony wystarcza, tak jak w Dyskusji/Komentarzach). Dostęp do
-- rozpoczęcia konwersacji jest bramkowany relacją "wspólne wydarzenie" (patrz
-- Models\Message::canMessage) — organizator <-> uczestnik oraz uczestnicy
-- tego samego wydarzenia między sobą, żeby nie było to otwarte DM dla
-- przypadkowych par userów (ryzyko spamu bez żadnego mechanizmu moderacji).
--
-- Jedna konwersacja NA PARĘ userów (user_low_id < user_high_id wymusza brak
-- duplikatów niezależnie od kolejności) — nie per wydarzenie, żeby ta sama
-- relacja organizator/uczestnik nie fragmentowała się na osobne wątki przy
-- każdym kolejnym wspólnym evencie.
CREATE TABLE conversations (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_low_id      BIGINT UNSIGNED NOT NULL,
  user_high_id     BIGINT UNSIGNED NOT NULL,
  last_message_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conversation_pair (user_low_id, user_high_id),
  FOREIGN KEY (user_low_id) REFERENCES users(id),
  FOREIGN KEY (user_high_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- read_at = kiedy DRUGA strona (nie nadawca) przeczytała — wystarcza jedna
-- kolumna, bo konwersacja jest zawsze dokładnie dwuosobowa (inaczej niż przy
-- grupowym czacie, gdzie trzeba by osobnej tabeli "przeczytane przez kogo").
CREATE TABLE messages (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id  BIGINT UNSIGNED NOT NULL,
  sender_id        BIGINT UNSIGNED NOT NULL,
  body             TEXT NOT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at          TIMESTAMP NULL,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id),
  FOREIGN KEY (sender_id) REFERENCES users(id),
  INDEX idx_conversation_created (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
