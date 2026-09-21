-- migration_043_point_transactions.sql
-- Etap 8A — RIDEMORE POINTS. Rejestr naliczeń punktów.
--
-- PO CO REJESTR, SKORO PUNKTY JUŻ SĄ
-- ----------------------------------
-- Do tej pory punkty żyły jako trzy kolumny sum na rider_activities
-- (points_discovery / points_exploration / points_trails). To wystarczało,
-- dopóki jedynym pytaniem było „ile mam", i przestaje wystarczać przy pytaniu
-- „za co konkretnie", które musi umieć odpowiedzieć i użytkownik, i admin.
-- Suma bez historii jest nierozstrzygalna: nie da się z niej odtworzyć, czy
-- 500 punktów to jeden bonus za ukończenie trasy, czy pięć drobnych naliczeń.
--
-- Od tej migracji ŹRÓDŁEM PRAWDY JEST TEN REJESTR. Kolumny points_* zostają
-- na razie jako cache do odczytu (usuwane albo przeliczane w kroku 8A/7), ale
-- nic nie ma prawa ich już zwiększać z pominięciem rejestru.
--
-- IDEMPOTENCJA JEST KLUCZEM UNIKALNYM, NIE WARUNKIEM W KODZIE
-- -----------------------------------------------------------
-- uq_point_event (user_id, source, source_id) sprawia, że powtórne
-- przetworzenie tego samego zdarzenia odbija się o bazę, a nie o czyjąś
-- pamięć, żeby dopisać sprawdzenie. To jest ta sama zasada, na której stoi
-- „pierwszy raz i tylko pierwszy raz" w discovery_cells — i ona już raz
-- uratowała ten moduł.
--
-- Dobór source_id decyduje, CO wolno powtórzyć:
--   RIDE              -> id przejazdu     : nowy przejazd = nowe punkty (tak ma być)
--   DISCOVERY         -> id przejazdu     : jw., ale pola i tak liczą się raz
--   EXPLORATION       -> id przejazdu     : jw.
--   TRAIL_THRESHOLD   -> "trasa:próg"     : każdy próg płaci RAZ W ŻYCIU
--   TRAIL_COMPLETION  -> "trasa"          : ukończenie płaci RAZ W ŻYCIU
--   EVENT             -> id turnusu       : raz na turnus
-- Dzięki temu zasada FIRST DISCOVERY > REPEAT VISIT jest własnością schematu,
-- a nie umową między funkcjami.
--
-- Lokalnie: wgraj wprost do ridemorebike2. Na produkcji: run_migrations.php.
-- mysql -u USER -p ridemorebike2 < migration_043_point_transactions.sql

SET NAMES utf8mb4;

CREATE TABLE point_transactions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  -- Nazwa źródła, nie nazwa nagrody: 'TRAIL_COMPLETION', nigdy
  -- 'Velo Czorsztyn'. Wartości i nazwy własne żyją w konfiguracji i w
  -- danych trasy, żeby dało się je zmienić bez ruszania logiki.
  source      VARCHAR(32) NOT NULL,
  -- Identyfikator zdarzenia W OBRĘBIE źródła. VARCHAR, nie BIGINT, bo progi
  -- tras potrzebują pary ("12:50" = trasa 12, próg 50%) — jedna kolumna
  -- obsługująca wszystkie źródła jest prostsza niż druga, prawie zawsze pusta.
  source_id   VARCHAR(64) NOT NULL,
  -- ZE ZNAKIEM. Korekta i cofnięcie muszą być możliwe jako WPIS, nie jako
  -- usunięcie historii — inaczej rejestr audytowalny przestaje być audytowalny
  -- dokładnie w tych sytuacjach, w których jest potrzebny.
  points      INT NOT NULL,
  -- Przejazd, z którego naliczenie wynika. NULL dopuszczony, bo przyszłe
  -- źródła (misje, kolekcje, wyzwania społeczności) nie muszą mieć przejazdu.
  activity_id BIGINT UNSIGNED NULL,
  -- Data ZDARZENIA W ŚWIECIE, nie zapisu w bazie. Przejazd z lipca policzony
  -- w sierpniu (backfill, ślad wgrany po czasie) ma trafić na oś czasu
  -- w lipcu — created_at odpowiada na inne pytanie.
  ride_date   DATE NULL,
  -- Gotowy podpis dla człowieka („Velo Czorsztyn — 50% trasy"). Trzymany
  -- razem z naliczeniem, bo nazwa trasy może się później zmienić, a wpis
  -- w historii ma zostać taki, jaki był w chwili przyznania.
  description VARCHAR(190) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_point_event (user_id, source, source_id),
  -- Pod listę „ostatnie punkty" i pod sumy okresowe.
  KEY idx_pt_user_date (user_id, ride_date),
  KEY idx_pt_activity (activity_id),
  CONSTRAINT fk_pt_user     FOREIGN KEY (user_id)     REFERENCES users(id)            ON DELETE CASCADE,
  -- CASCADE: wycofanie obecności kasuje przejazd, więc jego punkty znikają
  -- razem z nim. Ta sama zasada co discovery_cells — nie zostaje po nich
  -- wynik bez pokrycia w faktach.
  CONSTRAINT fk_pt_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Przewyższenie przejazdu — Utils\Gpx::parse() liczy je przy każdym wczytaniu
-- śladu i do tej pory wyrzucaliśmy tę liczbę. Kolumna wchodzi TERAZ, bo Ride
-- Points mają ją uwzględniać, a dołożenie jej później oznaczałoby ponowne
-- sparsowanie wszystkich plików GPX tylko po to, żeby ją odzyskać.
ALTER TABLE rider_activities
  ADD COLUMN elevation_gain_m INT UNSIGNED NOT NULL DEFAULT 0 AFTER distance_km;

-- Bonus za znaną trasę PER TRASA (§8 briefu). NULL = użyj wartości globalnej
-- z core/discovery.php, czyli dotychczasowego zachowania — dzięki temu
-- migracja niczego nie zmienia istniejącym trasom, a daje możliwość
-- wyróżnienia pojedynczej (Green Velo warte więcej niż lokalna pętla).
ALTER TABLE known_routes
  ADD COLUMN bonus_enabled    TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
  ADD COLUMN bonus_points     INT UNSIGNED NULL AFTER bonus_enabled,
  ADD COLUMN completion_bonus INT UNSIGNED NULL AFTER bonus_points;
