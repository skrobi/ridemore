-- migration_006_user_billing_profile.sql
-- Dane adresowe/do faktury dla kupujących (uczestników płatnych wydarzeń) —
-- osobna tabela od organizer_billing_profiles (ta jest dla organizatora jako
-- wystawcy faktur/odbiorcy wypłat, ta nowa dla usera jako odbiorcy faktury).
-- Bez bank_account — kupujący nie dostaje wypłat.
--
-- mysql -u USER -p ridemorebike2 < migration_006_user_billing_profile.sql

SET NAMES utf8mb4;

CREATE TABLE user_billing_profiles (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL UNIQUE,
  legal_name    VARCHAR(200) NOT NULL,
  address       VARCHAR(255) NOT NULL,
  tax_id        VARCHAR(50) NULL,       -- NIP — opcjonalny, dotyczy faktur na firmę
  completed_at  TIMESTAMP NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_billing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
