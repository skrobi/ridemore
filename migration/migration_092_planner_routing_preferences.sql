-- migration_092_planner_routing_preferences.sql
-- Nazwane preferencje routingu POD istniejącym słownikiem `bike_type`.
-- To nie jest drugi system profili roweru: każdy wpis wskazuje istniejący
-- dictionary_items.id i przechowuje tylko charakter/override użytkownika.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS planner_routing_configs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  bike_type_item_id INT UNSIGNED NOT NULL,
  name              VARCHAR(80) NOT NULL,
  character_code    VARCHAR(16) NOT NULL DEFAULT 'balanced',
  preferences_json  JSON NOT NULL,
  -- NULL zamiast 0 pozwala unikalnemu indeksowi przechowywać wiele
  -- niedomyślnych wpisów, ale najwyżej jeden wpis z wartością 1.
  is_default        TINYINT UNSIGNED NULL DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_planner_routing_name (user_id, name),
  UNIQUE KEY uq_planner_routing_default (user_id, bike_type_item_id, is_default),
  KEY idx_planner_routing_profile (user_id, bike_type_item_id, updated_at),
  CONSTRAINT fk_planner_routing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_planner_routing_bike FOREIGN KEY (bike_type_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE planned_routes
  ADD COLUMN routing_config_id BIGINT UNSIGNED NULL AFTER profile,
  ADD COLUMN routing_preferences_json JSON NULL AFTER routing_config_id,
  ADD KEY idx_planned_route_routing_config (routing_config_id),
  ADD CONSTRAINT fk_planned_route_routing_config
    FOREIGN KEY (routing_config_id) REFERENCES planner_routing_configs(id) ON DELETE SET NULL;
