-- migration_026_user_preferences.sql
-- Etap 3 (preferencje użytkownika) — warstwa deklarowana, warstwa wynikająca
-- i dziennik rekomendacji w jednej migracji (trzy niezależne funkcjonalnie
-- zestawy tabel, ale wszystkie potrzebne zanim cokolwiek zacznie zbierać
-- dane — dziennik w szczególności ma ruszyć jak najwcześniej, patrz
-- docs/etap3 §11 "Kolejność prac", krok 2).
--
-- mysql -u USER -p ridemorebike2 < migration_026_user_preferences.sql

SET NAMES utf8mb4;

-- Warstwa deklarowana, wybory wielokrotne (typy roweru/tempo/trudność/region
-- operacyjny/region i typ wydarzenia aspiracyjny) — wszystkie w jednej
-- tabeli, bo dictionary_items samo wie, do którego słownika należy pozycja
-- (docs/etap3 §3). `kind` w kluczu głównym, bo ta sama pozycja (region) może
-- być prawdziwa jednocześnie jako operacyjna i aspiracyjna.
CREATE TABLE user_preference_items (
  user_id            BIGINT UNSIGNED NOT NULL,
  dictionary_item_id INT UNSIGNED NOT NULL,
  kind               ENUM('operational','aspirational') NOT NULL DEFAULT 'operational',
  PRIMARY KEY (user_id, dictionary_item_id, kind),
  CONSTRAINT fk_upi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upi_item FOREIGN KEY (dictionary_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warstwa deklarowana, wartości liczbowe i przełączniki — jeden wiersz na
-- użytkownika. declared_updated_at (osobno od updated_at, które ticka też
-- przy przeliczeniach niezwiązanych z samą deklaracją) napędza osłabienie
-- rampy o połowę na 30 dni po edycji (docs/etap3 §5).
CREATE TABLE user_preferences (
  user_id             BIGINT UNSIGNED PRIMARY KEY,
  distance_min_km     SMALLINT UNSIGNED NULL,
  distance_max_km     SMALLINT UNSIGNED NULL,
  elevation_max_m     MEDIUMINT UNSIGNED NULL,
  group_size_pref     ENUM('small','any','large') NOT NULL DEFAULT 'any',
  notify_matches      TINYINT(1) NOT NULL DEFAULT 0,
  declared_updated_at TIMESTAMP NULL,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warstwa wynikająca — odtwarzana W CAŁOŚCI przy każdym przeliczeniu
-- (DELETE+INSERT per user w zadaniu nocnym), nigdy modyfikowana przyrostowo
-- (docs/etap3 §4) — dzięki temu zmiana wag w kodzie nie wymaga migracji,
-- tylko ponownego przeliczenia.
CREATE TABLE user_preference_signals (
  user_id            BIGINT UNSIGNED NOT NULL,
  dictionary_item_id INT UNSIGNED NOT NULL,
  weight             DECIMAL(5,4) NOT NULL,
  PRIMARY KEY (user_id, dictionary_item_id),
  CONSTRAINT fk_ups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ups_item FOREIGN KEY (dictionary_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_preference_stats (
  user_id          BIGINT UNSIGNED PRIMARY KEY,
  avg_distance_km  DECIMAL(6,2) NULL,
  avg_group_size   DECIMAL(5,2) NULL,
  -- Suma wag WSZYSTKICH zdarzeń przed normalizacją per-słownik — wejście do
  -- rampy (signal_strength / 20, pułap 0.5), NIE przełącznik wł/wył profilu.
  signal_strength  DECIMAL(6,2) NOT NULL DEFAULT 0,
  event_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  computed_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_upst_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dziennik rekomendacji (docs/etap3 §8) — jedno wystąpienie na KAŻDE
-- pokazanie rekomendacji (ten sam event może mieć wiele wierszy dla tego
-- samego usera w różnych kontekstach/dniach), nie stan. Jedyny element tego
-- etapu opisany w docu jako nieodwracalny — dane, których nie zbierzemy
-- teraz, nie da się odtworzyć później.
CREATE TABLE recommendation_log (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          BIGINT UNSIGNED NULL,
  event_id         BIGINT UNSIGNED NOT NULL,
  context          ENUM('form','event_page','notification','list') NOT NULL,
  score            DECIMAL(6,4) NOT NULL,
  position         TINYINT UNSIGNED NOT NULL,
  is_exploration   TINYINT(1) NOT NULL DEFAULT 0,
  is_aspirational  TINYINT(1) NOT NULL DEFAULT 0,
  derived_share    DECIMAL(4,3) NOT NULL DEFAULT 0,
  outcome          ENUM('ignored','clicked','joined','dismissed') NULL,
  outcome_reason   VARCHAR(32) NULL,
  shown_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  outcome_at       TIMESTAMP NULL,
  CONSTRAINT fk_rl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rl_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_rl_user_shown (user_id, shown_at),
  INDEX idx_rl_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stan odrzucenia per (user, event) — stopień 1 z docs/etap3 §6 działa
-- WYŁĄCZNIE na to wydarzenie (event znika z rekomendacji dla tego usera),
-- więc klucz główny na (user_id, event_id) wystarcza jako "czy odrzucone".
-- `reason` (stopień 2, opcjonalny) razem z PRIMARY KEY daje też "ile razy
-- ten user podał ten powód w ostatnich 90 dniach" przez COUNT(*) GROUP BY
-- (user_id, reason) — bez osobnej tabeli licznikowej, bo każdy wiersz tu to
-- z definicji inne wydarzenie.
CREATE TABLE recommendation_dismissals (
  user_id       BIGINT UNSIGNED NOT NULL,
  event_id      BIGINT UNSIGNED NOT NULL,
  reason        VARCHAR(32) NULL,
  dismissed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, event_id),
  CONSTRAINT fk_rd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rd_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_rd_user_reason (user_id, reason, dismissed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
