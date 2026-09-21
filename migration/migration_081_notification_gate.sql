-- migration_081_notification_gate.sql
-- BRAMKA POWIADOMIEŃ (Etap 0 programu zachęt, 2026-09-11).
--
-- ============================================================================
-- PO CO TO JEST — problem, który psuje TO, CO JUŻ DZIAŁA
-- ============================================================================
-- Push w apce istnieje od Etapu 8 (migr. 077) i ma dziś JEDNĄ zgodę na
-- wszystko: aktywny wiersz w `push_devices` = zgoda na każde powiadomienie.
-- Dopóki wysyłaliśmy wyłącznie rzeczy transakcyjne (nowa wiadomość, ktoś
-- dołączył do Twojego wyjazdu), to wystarczało — nikt nie wyłącza
-- powiadomienia o wiadomości, na którą czeka.
--
-- Program zachęt („nowa trasa w Twojej okolicy") to zmienia. Jedno
-- powiadomienie uznane za natrętne wyłącza przełącznik — a razem z nim
-- GINĄ WIADOMOŚCI od organizatora wyjazdu. Zmierzone w badaniach branżowych:
-- przy ponad sześciu powiadomieniach tygodniowo ludzie są 3,4× bardziej
-- skłonni odinstalować apkę w ciągu 30 dni niż przy jednym–dwóch, a 46%
-- wyłącza je przy 2–5 tygodniowo, jeśli nie widzą w nich sensu. Dlatego
-- zgoda per typ i twardy budżet powstają PRZED pierwszą zachętą, nie po
-- pierwszej skardze.
--
-- ============================================================================
-- DWIE RZECZY, KTÓRE TA MIGRACJA DODAJE
-- ============================================================================
-- 1. TRZY FLAGI ZGODY w `user_preferences` — tam, gdzie już mieszka
--    `notify_matches` (zgoda na maile o dopasowaniach). Osobna tabela na trzy
--    boolean-y byłaby drugim miejscem, do którego trzeba pamiętać zajrzeć.
--    DOMYŚLNIE WŁĄCZONE, i to jest decyzja, nie przeoczenie: nadrzędny
--    przełącznik push jest już świadomym opt-inem (człowiek sam go włącza
--    w koncie i sam przyznaje uprawnienie systemowe), więc pytanie go drugi
--    raz o to samo byłoby pytaniem o zgodę na zgodę. Wyłączenie zostaje
--    zawsze o jedno dotknięcie.
--
-- 2. `notification_log` — DZIENNIK WYSYŁEK, który robi trzy rzeczy naraz:
--    - DEDUPLIKACJA: `UNIQUE (user_id, type, dedupe_key)`. To nie jest indeks
--      pod szybkość, tylko GWARANCJA — „tego samego nie wyślemy dwa razy"
--      pilnuje baza, a nie czyjaś pamięć przy pisaniu kolejnego typu.
--      Klucz identyfikuje RZECZ, o której mowa (`kr:42`, `msg:1987`), więc
--      powiadomienie o nowej trasie nie ma jak przyjść drugi raz.
--      Typ, który MA prawo się powtarzać cyklicznie, wpisuje okres do klucza
--      (np. `tyg:2026-W37`) — i wtedy unikalność dalej znaczy to samo.
--    - BUDŻET: `(user_id, sent_at)` pod liczenie „ile w tym tygodniu".
--    - POMIAR: `opened_at` odpowiada na jedyne pytanie, które się liczy —
--      czy ktokolwiek to otwiera. Bez tego strojenie programu zachęt jest
--      zgadywaniem.
--
-- `users.id` to BIGINT UNSIGNED — klucz obcy z INT UNSIGNED nie powstanie
-- (errno 150), to już raz w tym projekcie kosztowało czas.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_081_notification_gate.sql

SET NAMES utf8mb4;

ALTER TABLE user_preferences
  ADD COLUMN push_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_matches,
  ADD COLUMN push_nearby   TINYINT(1) NOT NULL DEFAULT 1 AFTER push_messages,
  ADD COLUMN push_progress TINYINT(1) NOT NULL DEFAULT 1 AFTER push_nearby;

CREATE TABLE IF NOT EXISTS notification_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  -- Wartości trzyma `Models\NotificationGate::TYPY` — kolumna jest celowo
  -- tekstowa, nie ENUM: nowy typ powiadomienia nie ma wymagać ALTER TABLE
  -- na tabeli, która urośnie najszybciej ze wszystkich w tej bazie.
  type       VARCHAR(40) NOT NULL,
  -- Pusty łańcuch zamiast NULL — w MySQL NULL nie jest równy NULL, więc
  -- UNIQUE przepuściłby dowolną liczbę wierszy z NULL-em i deduplikacja
  -- przestałaby cokolwiek gwarantować dokładnie tam, gdzie jej najbardziej
  -- potrzeba (typ bez naturalnego klucza obiektu).
  dedupe_key VARCHAR(120) NOT NULL DEFAULT '',
  sent_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opened_at  TIMESTAMP NULL,
  UNIQUE KEY uniq_notification (user_id, type, dedupe_key),
  KEY idx_notification_budget (user_id, sent_at),
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
