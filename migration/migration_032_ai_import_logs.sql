-- migration_032_ai_import_logs.sql
-- "Pamięć" silnika AI (rozszerzenie Chrome "AI-Engine (Groq)" + most
-- core/Utils/AiEngineBridge.php + ai-engine/analyze.py) — dziennik ekstrakcji
-- pod przegląd jakości, NIE pełny zrzut wejścia (patrz Models\AiImportLog:
-- świadomie bez elementów DOM/HTML strony źródłowej, tylko fingerprint).
-- Narzędzie lokalne/dev — patrz md/features.md i gate APP_ENV==='dev' w
-- api/routes.php (/api/ai/engine-analyze) — ale tabela żyje w normalnym
-- schemacie, jak każda inna (bez niej AiImportLog::record() nie miałby gdzie
-- pisać nawet lokalnie).

CREATE TABLE ai_import_logs (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  url            VARCHAR(500) NOT NULL,
  source_domain  VARCHAR(190) NULL,
  element_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  confidence     VARCHAR(10) NULL,          -- 'low'|'medium'|'high', jak zwraca analyze.py
  result_json    JSON NULL,                 -- ustrukturyzowany wynik Groq PO walidacji enumów
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_import_domain (source_domain),
  INDEX idx_ai_import_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
