-- migration_040_discovery_grid.sql
-- Etap 8 — Discovery Grid. Heksagonalne "pola" odkrywane przez rzeczywistą
-- jazdę, osobna mapa osobista i wspólna mapa społeczności, znane trasy.
--
-- KLUCZOWE ROZSTRZYGNIĘCIE, KTÓRE WIDAĆ W TYCH TABELACH
-- -----------------------------------------------------
-- Serwis NIE MA i nigdy nie miał śladów GPS użytkowników — każdy plik GPX w
-- bazie należy do WYDARZENIA (event_stages / event_route_variants) i jest
-- trasą zaplanowaną przez organizatora. Kolumny event_attendance.gps_verified
-- / tracked_distance_km / source_item_id stoją puste od pierwszego dnia
-- (0 wierszy), bo nigdy nie powstał mechanizm, który by je wypełniał.
--
-- Dlatego "przejazd" dostaje WŁASNĄ tabelę (rider_activities) z wymiennym
-- źródłem, mimo że dziś istnieje dokładnie jedno: obecność potwierdzona na
-- turnusie + trasa tego wydarzenia. Gdy pojawi się własny GPX rowerzysty albo
-- import ze Stravy, będzie to NOWE ŹRÓDŁO WIERSZY w tej samej tabeli, a nie
-- przebudowa Discovery. To jest cały powód, dla którego ta tabela wygląda na
-- nadmiarową przy jednym źródle.
--
-- Lokalnie: wgraj wprost do ridemorebike2. Na produkcji: run_migrations.php.
-- mysql -u USER -p ridemorebike2 < migration_040_discovery_grid.sql

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- PRZEJAZD (byt abstrakcyjny, źródło wymienne)
-- ---------------------------------------------------------
CREATE TABLE rider_activities (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            BIGINT UNSIGNED NOT NULL,
  -- 'event_route' = wyprowadzony z potwierdzonej obecności i trasy wydarzenia
  -- (jedyne źródło na dziś), 'gpx' = ślad wgrany przez rowerzystę,
  -- 'strava' = import. Świadomie NIE słownik: dictionaries opisują pojęcia
  -- POKAZYWANE użytkownikowi (typ roweru, trudność, region), a to jest
  -- wewnętrzny znacznik, który mechanizm zapisał wiersz. Istniejący słownik
  -- attendance_source mówi o czym innym — jak zweryfikowano OBECNOŚĆ.
  source_code        VARCHAR(20) NOT NULL,
  edition_id         BIGINT UNSIGNED NULL,
  -- Klucz idempotencji dla źródła 'event_route': jeden przejazd na zapis.
  -- UNIQUE dopuszcza wiele NULL-i w MySQL, więc ślady wgrane ręcznie
  -- (rsvp_id = NULL) nie są tym ograniczone — dokładnie o to chodzi.
  rsvp_id            BIGINT UNSIGNED NULL,
  ride_date          DATE NULL,
  distance_km        DECIMAL(7,2) NOT NULL DEFAULT 0,
  cells_touched      INT UNSIGNED NOT NULL DEFAULT 0,
  cells_new          INT UNSIGNED NOT NULL DEFAULT 0,
  -- Trzy kategorie z §13 rozdzielone, bo podsumowanie po przejeździe (§38) ma
  -- pokazać, ZA CO przyznano punkty, a nie jedną nierozkładalną liczbę.
  points_discovery   INT UNSIGNED NOT NULL DEFAULT 0,
  points_exploration INT UNSIGNED NOT NULL DEFAULT 0,
  points_trails      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_activity_rsvp (rsvp_id),
  KEY idx_ra_user_date (user_id, ride_date),
  KEY idx_ra_edition (edition_id),
  CONSTRAINT fk_ra_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
  CONSTRAINT fk_ra_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id)  ON DELETE SET NULL,
  CONSTRAINT fk_ra_rsvp    FOREIGN KEY (rsvp_id)    REFERENCES event_rsvps(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- MOJE DISCOVERY (§6.1)
-- ---------------------------------------------------------
-- Zasada "odkrywanie jest ważniejsze od powtarzania" (§5) jest tu
-- NIEZMIENNIKIEM BAZY, nie regułą w kodzie: klucz główny (user_id, cell_id)
-- sprawia, że drugi przejazd tą samą drogą nie ma jak dopisać drugiego
-- wiersza. Zapis idzie przez INSERT IGNORE, a liczba faktycznie wstawionych
-- wierszy JEST liczbą nowych odkryć — nie trzeba jej nigdzie liczyć osobno
-- ani pilnować w aplikacji.
--
-- cell_id to spakowany identyfikator z Utils\DiscoveryGrid (3 bity poziomu +
-- 2 × 30 bitów współrzędnych osiowych). Wszystko tutaj jest na poziomie
-- DiscoveryGrid::RES_CELL; grubsze poziomy liczone są w locie pod mapę.
CREATE TABLE discovery_cells (
  user_id       BIGINT UNSIGNED NOT NULL,
  cell_id       BIGINT NOT NULL,
  activity_id   BIGINT UNSIGNED NULL,
  discovered_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, cell_id),
  -- "Kto odkrył to pole" — przeliczanie agregatu społeczności i bonus
  -- eksploracyjny (§9: pole nietknięte wcześniej przez nikogo).
  KEY idx_dc_cell (cell_id),
  CONSTRAINT fk_dc_user     FOREIGN KEY (user_id)     REFERENCES users(id)             ON DELETE CASCADE,
  CONSTRAINT fk_dc_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- RIDEMORE DISCOVERY — wspólna mapa (§6.2)
-- ---------------------------------------------------------
-- Zmaterializowany agregat, NIE źródło prawdy — da się odtworzyć w całości z
-- discovery_cells (Models\Discovery::rebuildTotals). Ten sam wzorzec co
-- rider_connections w Peletonie i z tego samego powodu: mapa społeczności to
-- najgorętsze zapytanie w tym module (§32), a GROUP BY po wszystkich
-- odkryciach wszystkich ludzi przy każdym przesunięciu mapy skończyłby się
-- dokładnie tak, jak brzmi.
--
-- cell_q / cell_r są zdenormalizowane z cell_id CELOWO: identyfikator jest
-- spakowany, więc nie da się po nim zrobić zapytania prostokątnego. Zapytanie
-- mapy filtruje po (cell_r, cell_q) — obie współrzędne są monotoniczne
-- względem osi Mercatora, więc widoczny prostokąt mapy przekłada się na zwykły
-- range scan po indeksie.
--
-- Prywatność (§27): tu nie ma i nie może być listy ludzi na polu. first_user_id
-- służy WYŁĄCZNIE bonusowi za pierwsze odkrycie w społeczności i nigdy nie
-- jest pokazywany na mapie — inaczej pojedyncze pole przy czyimś domu
-- zdradzałoby, kto tam mieszka.
CREATE TABLE discovery_cell_totals (
  cell_id       BIGINT NOT NULL PRIMARY KEY,
  cell_q        INT NOT NULL,
  cell_r        INT NOT NULL,
  riders_count  INT UNSIGNED NOT NULL DEFAULT 0,
  first_user_id BIGINT UNSIGNED NULL,
  first_seen_at DATETIME NULL,
  last_seen_at  DATETIME NULL,
  KEY idx_dct_bbox (cell_r, cell_q),
  CONSTRAINT fk_dct_first_user FOREIGN KEY (first_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- ZNANE TRASY (§10, §11)
-- ---------------------------------------------------------
-- "Nie należy hardcodować tych tras w kodzie" — więc Velo Czorsztyn, Green
-- Velo czy Szlak Orlich Gniazd to WIERSZE, dodawane przez panel admina z
-- pliku GPX, dokładnie tak jak trasa wydarzenia.
--
-- POSTĘPU UŻYTKOWNIKA NA TRASIE NIE MA W ŻADNEJ TABELI. Jest przecięciem
-- known_route_cells z discovery_cells i liczy się jednym zapytaniem — ten
-- serwis ma już dwa duże byty zbudowane tak samo (Kronika i Puls nie mają
-- własnych tabel) i za każdym razem wygrywało to, że nie istnieje stan
-- "postęp się rozjechał z faktami". Punkty za progi są jedyną rzeczą, którą
-- trzeba zapamiętać, i siedzą w rider_activities.points_trails przy tym
-- przejeździe, który próg przekroczył.
CREATE TABLE known_routes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(190) NOT NULL,
  name           VARCHAR(200) NOT NULL,
  description    TEXT NULL,
  region_item_id INT UNSIGNED NULL,          -- dict: region
  gpx_url        VARCHAR(500) NULL,
  distance_km    DECIMAL(7,2) NOT NULL DEFAULT 0,
  cells_total    INT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_known_route_slug (slug),
  KEY idx_kr_active (is_active),
  CONSTRAINT fk_kr_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE known_route_cells (
  route_id BIGINT UNSIGNED NOT NULL,
  cell_id  BIGINT NOT NULL,
  PRIMARY KEY (route_id, cell_id),
  KEY idx_krc_cell (cell_id),
  CONSTRAINT fk_krc_route FOREIGN KEY (route_id) REFERENCES known_routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
