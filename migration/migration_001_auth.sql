-- migration_001_auth.sql
-- Dogania istniejącą bazę do aktualnego schema.sql: hasło + weryfikacja e-mail
-- dla users, tabela tokenów aktywacyjnych. Uruchom raz na bazie, która już
-- ma wgrany stary schema.sql (+ ewentualnie seed.sql).
--
-- mysql -u USER -p ridemorebike2 < migration_001_auth.sql

ALTER TABLE users
  MODIFY name VARCHAR(150) NULL,
  ADD COLUMN password_hash     VARCHAR(255) NULL AFTER email,
  ADD COLUMN email_verified_at TIMESTAMP    NULL AFTER password_hash;

CREATE TABLE account_activation_tokens (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL UNIQUE,
  expires_at    DATETIME NOT NULL,
  used_at       DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
