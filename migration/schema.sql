-- database/schema.sql
-- =========================================================
-- ridemore.bike — schemat bazy danych (MariaDB / MySQL 8+)
-- Filozofia: słowniki generyczne (dictionaries/dictionary_items)
-- zamiast osobnej tabeli na każdy enum. Jeden model
-- events + event_stages obsługuje zarówno jednodniowe ustawki,
-- jak i wielodniowe płatne wycieczki — bez rozgałęzień w kodzie.
-- =========================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1. SŁOWNIKI (generyczny wzorzec, rozszerzalny bez migracji)
-- ---------------------------------------------------------

CREATE TABLE dictionaries (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(64) NOT NULL UNIQUE,   -- np. 'event_type', 'difficulty_level'
  name          VARCHAR(128) NOT NULL,
  description   VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dictionary_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dictionary_id   INT UNSIGNED NOT NULL,
  -- Samo-referencyjna hierarchia, generyczna dla wszystkich słowników
  -- (dowolna głębokość) — na start pod region: kraj -> regiony w jego obrębie.
  -- ON DELETE RESTRICT: nie da się skasować rodzica dopóki ma dzieci.
  parent_id       INT UNSIGNED NULL,
  code            VARCHAR(64) NOT NULL,        -- np. 'gravel', 'trudna', 'nocleg'
  name            VARCHAR(128) NOT NULL,       -- etykieta do wyświetlenia
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  meta            JSON NULL,                   -- np. {"speed_min":22,"speed_max":26} dla tempa
  -- KLUCZ ikony z Utils\Icon (np. 'tre-viewpoint'), NIE znak. Migracja 055
  -- wstawiala tu emoji i skonczylo sie to uszkodzonymi bajtami po przejsciu
  -- przez konsole Windows w cp852 — patrz migracja 057. Klucz jest ASCII, wiec
  -- ten sam blad nie ma jak sie powtorzyc, a ksztalt ikony zmienia sie w kodzie,
  -- bez ruszania bazy.
  icon            VARCHAR(32) NULL,
  CONSTRAINT fk_ditems_dict FOREIGN KEY (dictionary_id)
    REFERENCES dictionaries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ditems_parent FOREIGN KEY (parent_id)
    REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  UNIQUE KEY uniq_dict_item (dictionary_id, code),
  INDEX idx_ditems_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Uwaga: MySQL/MariaDB nie wymusi natywnie, że dana kolumna FK
-- wskazuje item z właściwego słownika (np. że event_type_item_id
-- wskazuje pozycję ze słownika 'event_type', a nie 'currency').
-- To trzeba walidować w warstwie aplikacji przy zapisie, albo
-- dopisać trigger BEFORE INSERT/UPDATE jeśli zależy Ci na twardej
-- gwarancji w bazie. Świadomy kompromis na rzecz elastyczności.

-- ---------------------------------------------------------
-- 2. UŻYTKOWNICY I ORGANIZATORZY
-- ---------------------------------------------------------

CREATE TABLE users (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(150) NULL,       -- puste do czasu uzupełnienia profilu; e-mail wystarcza do rejestracji
  email               VARCHAR(190) NOT NULL UNIQUE,
  password_hash       VARCHAR(255) NULL,       -- NULL = rejestracja niedokończona (e-mail bez hasła)
  email_verified_at   TIMESTAMP NULL,          -- ustawiane razem z hasłem, po kliknięciu linku aktywacyjnego
  is_admin            BOOLEAN NOT NULL DEFAULT FALSE,  -- pełna władza nad każdym wydarzeniem, niezależnie od właściciela
  -- MODERACJA (migr. 058). Blokada, a NIE kasowanie: klucze obce na tej tabeli
  -- kaskaduja do 25 innych, wiec skasowanie konta z historia wyciera rowniez
  -- cudze wyjazdy, w ktorych bralo udzial (sklad, kronika, rejestr punktow).
  -- Powod jest wymuszony w kodzie — bez niego po pol roku nikt nie wie, czemu
  -- konto jest wylaczone, a odblokowanie staje sie zgadywaniem.
  blocked_at          TIMESTAMP NULL DEFAULT NULL,
  blocked_reason      VARCHAR(255) NULL,
  blocked_by          BIGINT UNSIGNED NULL,
  -- Samoobsługowe kasowanie konta w apce (Etap 9, migr. 079, 2026-08-29).
  -- Odróżnia PROŚBĘ O USUNIĘCIE od zwykłej blokady moderacyjnej — obie idą
  -- przez te same blocked_*, ale admin musi wiedzieć, którą właśnie widzi.
  deletion_requested_at TIMESTAMP NULL,
  -- Widoczność na listach uczestników i w peletonie (migr. 037). Domyślnie TAK
  -- (ukryty domyślnie zabiłby efekt sieciowy). Ukryta osoba nadal LICZY SIĘ do
  -- składu ("8 osób jedzie") — znika tylko jej twarz i imię.
  roster_visible      BOOLEAN NOT NULL DEFAULT TRUE,
  -- Adres publicznego profilu rowerzysty /rowerzysta/{slug} (migr. 038). NULL =
  -- konto sprzed migracji, którego backfill nie objął — wtedy po prostu nie jest
  -- linkowane. Sam slug NIE czyni profilu publicznym, patrz RiderController::show.
  public_slug         VARCHAR(160) NULL UNIQUE,
  phone               VARCHAR(32) NULL,
  avatar_url          VARCHAR(500) NULL,
  -- Język maili, pushy i wejścia na stronę główną (migr. 089). NULL = polski.
  lang                VARCHAR(5) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokeny linków "dokończ rejestrację" wysyłanych e-mailem. Trzymamy hash
-- tokenu (nie sam token), żeby wyciek bazy nie dawał gotowych linków aktywacyjnych.
CREATE TABLE account_activation_tokens (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL UNIQUE,     -- sha256 surowego tokenu z linku
  expires_at    DATETIME NULL,        -- NULL = bez limitu (linki "przejmij profil organizatora")
  used_at       DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tożsamości logowania społecznościowego (patrz migration_029_oauth_identities.sql)
-- — Google/Strava przez league/oauth2-client. Osobno od users, bo jeden user
-- może mieć podpięte oba providery. provider_user_id = 'sub' Google / id atlety Strava.
CREATE TABLE user_oauth_identities (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  provider          VARCHAR(20) NOT NULL,
  provider_user_id  VARCHAR(190) NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provider_identity (provider, provider_user_id),
  UNIQUE KEY uq_user_provider (user_id, provider),
  CONSTRAINT fk_oauth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE organizer_profiles (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                     BIGINT UNSIGNED NOT NULL UNIQUE,
  slug                        VARCHAR(160) NOT NULL UNIQUE,  -- adres publicznego profilu, generowany raz przy ensureProfile()
  organizer_type_item_id      INT UNSIGNED NOT NULL,   -- dict: organizer_type (peer / professional_operator)
  verification_status_item_id INT UNSIGNED NOT NULL,   -- dict: verification_status
  is_active                   TINYINT(1) NOT NULL DEFAULT 1,  -- dezaktywacja z /admin/organizatorzy — ukrywa profil publicznie, nie kasuje danych
  tourism_register_number     VARCHAR(100) NULL,       -- wypełniane tylko dla professional_operator
  bio                         TEXT NULL,               -- "o nas" na publicznym profilu, edytowalne w Moje konto
  hero_photo_urls             JSON NULL,               -- własne zdjęcia hero (mają pierwszeństwo przed automatycznymi z eventów, patrz Organizer::recentCoverPhotos())
  city                        VARCHAR(120) NULL,
  region_item_id              INT UNSIGNED NULL,       -- dict: region (deklarowany region organizatora jako osoby — NIE to samo co event_regions, migr. 074)
  founded_year                SMALLINT UNSIGNED NULL,
  languages                   VARCHAR(200) NULL,       -- wolny tekst CSV, np. "polski, angielski"
  contact_email               VARCHAR(190) NULL,       -- publiczny e-mail kontaktowy, niezależny od users.email (loginu) — gdy ustawiony, na niego idą powiadomienia o wydarzeniach organizatora (patrz Organizer::notificationEmail())
  website_url                 VARCHAR(255) NULL,
  facebook_url                VARCHAR(255) NULL,
  instagram_url               VARCHAR(255) NULL,
  strava_url                  VARCHAR(255) NULL,
  safety_route_known          TINYINT(1) NOT NULL DEFAULT 0,
  safety_first_aid_kit        TINYINT(1) NOT NULL DEFAULT 0,
  safety_sweep_rider          TINYINT(1) NOT NULL DEFAULT 0,
  safety_support_vehicle      TINYINT(1) NOT NULL DEFAULT 0,
  safety_first_aid_certified  TINYINT(1) NOT NULL DEFAULT 0,
  safety_liability_insurance  TINYINT(1) NOT NULL DEFAULT 0,
  rating_avg                  DECIMAL(3,2) NOT NULL DEFAULT 0,
  events_organized_count      INT UNSIGNED NOT NULL DEFAULT 0,
  attendance_confirmed_rate   DECIMAL(5,2) NOT NULL DEFAULT 0,  -- % GPS-potwierdzonej frekwencji
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_org_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_org_type FOREIGN KEY (organizer_type_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_org_verif FOREIGN KEY (verification_status_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_org_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  INDEX idx_org_active (is_active)  -- filtrowane w Organizer::search()/allForAdmin()/allSlugsForSitemap(), bez FK więc bez auto-indeksu
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dostęp do WSZYSTKICH wydarzeń danego organizatora dla dodatkowej osoby
-- (np. pracownika operatora turystycznego) — nie ad-hoc per pojedynczy event.
CREATE TABLE organizer_collaborators (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_user_id  BIGINT UNSIGNED NOT NULL,   -- = events.organizer_id (właściciel)
  user_id            BIGINT UNSIGNED NOT NULL,   -- upoważniona osoba
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_collab_organizer FOREIGN KEY (organizer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_collab_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_collab (organizer_user_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dane do faktur/wypłat — wymagane przed publikacją płatnego wydarzenia
-- z zapisami wewnętrznymi (płatność idzie przez ridemore.bike).
-- completed_at = NULL oznacza niekompletny profil.
CREATE TABLE organizer_billing_profiles (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_user_id  BIGINT UNSIGNED NOT NULL UNIQUE,
  legal_name         VARCHAR(200) NOT NULL,
  address            VARCHAR(255) NOT NULL,
  tax_id             VARCHAR(50) NULL,       -- NIP — nie dotyczy osób fizycznych
  bank_account_holder VARCHAR(200) NULL,     -- posiadacz konta — MOŻE różnić się od legal_name (np. firma rozlicza się prywatnym kontem właściciela)
  bank_account       VARCHAR(34) NOT NULL,   -- IBAN
  completed_at       TIMESTAMP NULL,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_billing_user FOREIGN KEY (organizer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dane adresowe/do faktury dla KUPUJĄCEGO (uczestnika płatnego wydarzenia) —
-- osobna tabela od organizer_billing_profiles (tamta jest dla organizatora
-- jako wystawcy faktur/odbiorcy wypłat). Bez bank_account — kupujący nie
-- dostaje wypłat. Uzupełniane w Moje konto, docelowo też przy pierwszym
-- zakupie płatnego wydarzenia, jeśli puste (sam zakup — poza zakresem na razie).
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

-- ---------------------------------------------------------
-- 3. WYDARZENIA (rdzeń — jeden model dla ustawki i wycieczki)
-- ---------------------------------------------------------

CREATE TABLE events (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organizer_id                BIGINT UNSIGNED NOT NULL,
  submitter_name              VARCHAR(150) NULL,   -- kontakt do zgłaszającego "w czyimś imieniu", gdy nie jest zalogowany
  submitter_email             VARCHAR(255) NULL,
  event_type_item_id          INT UNSIGNED NOT NULL,   -- dict: event_type (ustawka / wycieczka_wielodniowa / ...)
  status_item_id              INT UNSIGNED NOT NULL,   -- dict: event_status (draft/published/full/cancelled/completed/oczekuje_weryfikacji)
  registration_type_item_id   INT UNSIGNED NULL,       -- dict: registration_type; NULL = internal (domyślne)
  external_registration_url   VARCHAR(500) NULL,       -- tylko gdy registration_type = external
  external_registration_phone VARCHAR(30)  NULL,       -- alternatywna forma zapisu zewnętrznego (patrz migration_030)
  external_registration_email VARCHAR(190) NULL,       -- alternatywna forma zapisu zewnętrznego
  title                  VARCHAR(200) NOT NULL,
  slug                   VARCHAR(220) NOT NULL UNIQUE,
  description            TEXT NULL,
  cover_photo_url        VARCHAR(500) NULL,
  difficulty_item_id     INT UNSIGNED NULL,       -- dict: difficulty_level
  pace_group_item_id     INT UNSIGNED NULL,       -- dict: pace_group
  -- region_item_id USUNIĘTE migr. 075 — region jest teraz zbiorem w
  -- `event_regions` (migr. 074): deklaracja organizatora + to, co wynika
  -- z GPX etapów/wariantów, patrz Models\Event::syncRegions.
  min_participants       SMALLINT UNSIGNED NULL,
  max_participants       SMALLINT UNSIGNED NULL,
  -- EVENT BONUS (Etap 8A, migr. 044): punkty za sam udział w tym wydarzeniu.
  -- NULL = brak bonusu i tak jest domyślnie — brief chce, żeby model UMOŻLIWIAŁ
  -- taką nagrodę, nie żeby dostawało ją każde wydarzenie. NULL, a nie 0, bo zero
  -- ktoś mógł wpisać świadomie i wtedy chcemy widzieć, że decyzja zapadła.
  point_bonus            INT UNSIGNED NULL,
  meeting_point_address  VARCHAR(255) NULL,
  meeting_point_lat      DECIMAL(9,6) NULL,
  meeting_point_lng      DECIMAL(9,6) NULL,
  start_date             DATE NOT NULL,           -- data 1. etapu, zdenormalizowane pod listowanie/filtry
  custom_attributes      JSON NULL,               -- "furtka" na nowe pola zanim dostaną własną kolumnę
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_at           TIMESTAMP NULL,
  review_invites_sent_at TIMESTAMP NULL,     -- strażnik: Event::processCompletions() wysyła zaproszenia do opinii raz
  CONSTRAINT fk_ev_organizer FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ev_type FOREIGN KEY (event_type_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ev_status FOREIGN KEY (status_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ev_difficulty FOREIGN KEY (difficulty_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_ev_pace FOREIGN KEY (pace_group_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_ev_registration_type FOREIGN KEY (registration_type_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  INDEX idx_events_status_type (status_item_id, event_type_item_id),
  INDEX idx_events_start_date (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Turnusy: jedno wydarzenie (trasa/opis/cena/organizator) może mieć wiele
-- terminów wyjazdu (np. cykliczna wycieczka w kilku turnusach). Każdy turnus
-- ma WŁASNĄ datę startu, WŁASNY limit miejsc i WŁASNYCH uczestników (patrz
-- event_rsvps.edition_id) — jeden termin może się zapełnić, inny zostać
-- otwarty. Itinerarz (event_stages niżej) to wspólny SZABLON dla wszystkich
-- turnusów — dzień 1, dzień 2... bez własnej absolutnej daty; faktyczna data
-- dnia = edition.start_date + (day_number - 1), patrz Models\EventEdition.
CREATE TABLE event_editions (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id           BIGINT UNSIGNED NOT NULL,
  start_date         DATE NOT NULL,
  -- end_date: NULL dla ustawka/wycieczka_wielodniowa (koniec turnusu liczy się
  -- jako start_date + event_totals.duration_days - 1, patrz Models\Event) —
  -- wypełniana wprost wyłącznie dla event_type='pokrec_z_kims', bo ten typ ma
  -- zawsze dokładnie JEDEN wiersz w event_stages (technicznie, pod
  -- event_totals) i duration_days z niego byłoby błędne. Patrz
  -- migration_024_pokrec_z_kims.sql.
  end_date           DATE NULL,
  -- Sztywny termin vs okno dostępności — ma znaczenie tylko dla pokrec_z_kims.
  date_is_flexible   TINYINT(1) NOT NULL DEFAULT 0,
  -- Godzina rozpoczęcia, dotyczy wszystkich typów (dla wielodniowych = start
  -- pierwszego dnia); opcjonalna wszędzie, nigdy wymagana na poziomie bazy.
  start_time         TIME NULL,
  is_cancelled       TINYINT(1) NOT NULL DEFAULT 0,   -- odwołanie POJEDYNCZEGO turnusu; events.status_item_id dalej rządzi całym wydarzeniem
  max_participants   SMALLINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_edition_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_event_start (event_id, start_date),
  INDEX idx_edition_start_date (start_date),
  INDEX idx_edition_dates (is_cancelled, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_bike_types (
  event_id          BIGINT UNSIGNED NOT NULL,
  bike_type_item_id INT UNSIGNED NOT NULL,        -- dict: bike_type
  PRIMARY KEY (event_id, bike_type_item_id),
  CONSTRAINT fk_ebt_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ebt_item FOREIGN KEY (bike_type_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sprzęt to wolny tekst organizatora ("np. kask, oświetlenie"), nie zamknięty
-- słownik — zbyt otwarty zbiór na wzorzec dictionaries/dictionary_items.
-- equipment_item_id zostaje jako opcjonalne pole na przyszłość (np. autouzupełnianie).
CREATE TABLE event_equipment (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id            BIGINT UNSIGNED NOT NULL,
  equipment_item_id   INT UNSIGNED NULL,          -- dict: equipment_item (opcjonalnie)
  name                VARCHAR(150) NOT NULL,      -- nazwa wpisana przez organizatora
  is_mandatory        BOOLEAN NOT NULL DEFAULT TRUE,
  note                VARCHAR(255) NULL,
  CONSTRAINT fk_eq_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_eq_item FOREIGN KEY (equipment_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 4. ETAPY / TRASA
-- Kluczowa decyzja: jednodniowa ustawka = 1 wiersz w event_stages,
-- wielodniowa wycieczka = N wierszy. Nie ma osobnej ścieżki kodu
-- ani osobnego pola "czy wielodniowy" — to policzalne z liczby
-- etapów (patrz VIEW event_totals niżej).
-- ---------------------------------------------------------

CREATE TABLE event_stages (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id            BIGINT UNSIGNED NOT NULL,
  day_number          SMALLINT UNSIGNED NOT NULL,     -- 1 dla ustawki, 1..N dla wycieczki
  stage_date          DATE NULL,
  title               VARCHAR(200) NULL,               -- np. "Czarny Dunajec → Ždiar"
  start_point         VARCHAR(200) NULL,
  start_lat           DECIMAL(9,6) NULL,               -- pinezka z mapy (opcjonalna — nie każde miejsce ma adres)
  start_lng           DECIMAL(9,6) NULL,
  end_point           VARCHAR(200) NULL,
  end_lat             DECIMAL(9,6) NULL,
  end_lng             DECIMAL(9,6) NULL,
  distance_km         DECIMAL(6,2) NOT NULL DEFAULT 0,
  elevation_gain_m    INT UNSIGNED NOT NULL DEFAULT 0,
  surface_item_id     INT UNSIGNED NULL,               -- dict: surface_type — ręczny fallback gdy brak GPX/detekcja się nie powiedzie (patrz kolumny niżej)
  surface_asphalt_pct SMALLINT UNSIGNED NULL,           -- % trasy asfalt — z RoadSurfaceDetector (Overpass API), liczone przy wgraniu GPX
  surface_gravel_pct  SMALLINT UNSIGNED NULL,           -- % trasy gravel/szuter
  surface_trail_pct   SMALLINT UNSIGNED NULL,           -- % trasy ścieżka/single
  gpx_url             VARCHAR(500) NULL,
  elevation_profile   JSON NULL,                       -- punkty [{d_km, elev_m}, ...] pod wykres w hero
  notes               TEXT NULL,
  CONSTRAINT fk_stage_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_stage_surface FOREIGN KEY (surface_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_event_day (event_id, day_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_stage_accommodations (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stage_id                    BIGINT UNSIGNED NOT NULL UNIQUE,
  accommodation_type_item_id  INT UNSIGNED NOT NULL,   -- dict: accommodation_type
  name                        VARCHAR(200) NULL,
  address                     VARCHAR(255) NULL,
  CONSTRAINT fk_acc_stage FOREIGN KEY (stage_id) REFERENCES event_stages(id) ON DELETE CASCADE,
  CONSTRAINT fk_acc_type FOREIGN KEY (accommodation_type_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_stage_meals (
  stage_id            BIGINT UNSIGNED NOT NULL,
  meal_type_item_id   INT UNSIGNED NOT NULL,            -- dict: meal_type
  is_included          BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (stage_id, meal_type_item_id),
  CONSTRAINT fk_meal_stage FOREIGN KEY (stage_id) REFERENCES event_stages(id) ON DELETE CASCADE,
  CONSTRAINT fk_meal_item FOREIGN KEY (meal_type_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5. CENNIK (istnieje tylko gdy event jest płatny — brak wiersza
--    w event_pricing = darmowa ustawka; obecność = pokazuje się
--    price-forward CTA)
-- ---------------------------------------------------------

CREATE TABLE event_pricing (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id                        BIGINT UNSIGNED NOT NULL UNIQUE,
  price_amount                    DECIMAL(10,2) NOT NULL,
  currency_item_id                INT UNSIGNED NOT NULL,      -- dict: currency
  price_unit_item_id              INT UNSIGNED NOT NULL,      -- dict: price_unit (per_person/per_team)
  deposit_amount                  DECIMAL(10,2) NULL,
  payment_deadline_days_before    SMALLINT UNSIGNED NULL,
  cancellation_deadline_days_before SMALLINT UNSIGNED NULL, -- NULL = bez ograniczenia; egzekwowane w EventRsvp/web/routes.php, nie tylko wyświetlane
  cancellation_policy             TEXT NULL,
  CONSTRAINT fk_price_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_currency FOREIGN KEY (currency_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_price_unit FOREIGN KEY (price_unit_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_price_items (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id                      BIGINT UNSIGNED NOT NULL,
  inclusion_category_item_id    INT UNSIGNED NULL,        -- dict: inclusion_category (nocleg/wyzywienie/transport/...)
  description                   VARCHAR(255) NOT NULL,    -- np. "2 noclegi ze śniadaniem"
  is_included                   BOOLEAN NOT NULL,         -- TRUE = "w cenie", FALSE = "nie w cenie"
  sort_order                    SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_pi_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_category FOREIGN KEY (inclusion_category_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Warianty trasy: jeden (jednodniowy) event może mieć kilka alternatywnych
-- tras ("pętli", np. 300/200/100 km), każda z WŁASNYM GPX, ceną i limitem.
-- Warstwa opcjonalna — brak wierszy = jedna trasa (event_stages) + jeden
-- event_pricing, jak dotąd. Gdy istnieją, zastępują trasę+cenę: uczestnik
-- wybiera wariant przy zapisie (event_rsvps.variant_id). Waluta/terminy wspólne
-- z event_pricing; wariant nadpisuje kwotę/zaliczkę. Patrz migration_031.
CREATE TABLE event_route_variants (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id            BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(150) NOT NULL,               -- np. "Pętla 300 km"
  sort_order          SMALLINT NOT NULL DEFAULT 0,
  distance_km         DECIMAL(6,2) NOT NULL DEFAULT 0,
  elevation_gain_m    INT UNSIGNED NOT NULL DEFAULT 0,
  surface_item_id     INT UNSIGNED NULL,                   -- dict: surface_type (ręczny fallback)
  surface_asphalt_pct SMALLINT UNSIGNED NULL,
  surface_gravel_pct  SMALLINT UNSIGNED NULL,
  surface_trail_pct   SMALLINT UNSIGNED NULL,
  gpx_url             VARCHAR(500) NULL,
  elevation_profile   JSON NULL,
  price_amount        DECIMAL(10,2) NULL,                  -- NULL = wariant bez opłaty
  deposit_amount      DECIMAL(10,2) NULL,
  max_participants    SMALLINT UNSIGNED NULL,              -- limit per wariant (per edition)
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_variant_event   FOREIGN KEY (event_id)        REFERENCES events(id)          ON DELETE CASCADE,
  CONSTRAINT fk_variant_surface FOREIGN KEY (surface_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  INDEX idx_variant_event (event_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 6. ZDJĘCIA
-- ---------------------------------------------------------

-- "Relacja" z wyjazdu — tekst/zdjęcia/YouTube. Wiele wpisów na event, jeden na
-- osobę (edytowalny) — może dodać każdy potwierdzony uczestnik ORAZ organizator.
-- Wpisy dziennika kroniki wyjazdu. Kronika jest per TURNUS (migr. 039), bo
-- obecność też jest per turnus — przy wydarzeniu cyklicznym jedna wspólna
-- relacja mieszałaby różne terminy, składy i trasy.
CREATE TABLE event_recaps (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id         BIGINT UNSIGNED NOT NULL,
  -- ON DELETE SET NULL, nie CASCADE: skasowanie terminu nie może wymazać
  -- czyjejś relacji z przejechanego wyjazdu.
  edition_id       BIGINT UNSIGNED NULL,
  author_user_id   BIGINT UNSIGNED NOT NULL,
  body             TEXT NULL,
  youtube_url      VARCHAR(500) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_recap_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_recap_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id) ON DELETE SET NULL,
  CONSTRAINT fk_recap_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE,
  -- Jedna relacja na osobę na TURNUS (nie na wydarzenie) — kto pojechał
  -- w lipcu i w sierpniu, dorzuca do obu kronik.
  -- BEZ UNIQUE (migr. 052): kronika przyjmuje wiele wpisow od jednej osoby,
  -- bo jest DZIENNIKIEM pisanym w trakcie wyjazdu ("jestesmy na zbiorce",
  -- "prawie na miejscu"), a nie jednym podsumowaniem po powrocie.
  KEY idx_recap_edition_author (edition_id, author_user_id),
  KEY idx_recap_event (event_id),
  KEY idx_recap_edition (edition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- event_photos zdefiniowane niżej (koniec sekcji 8) — ma FK do event_reviews,
-- która musi już istnieć w momencie CREATE TABLE na świeżej instalacji.

-- ---------------------------------------------------------
-- 7. ZAPISY I FREKWENCJA (weryfikacja GPS)
-- ---------------------------------------------------------

CREATE TABLE event_rsvps (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id                      BIGINT UNSIGNED NOT NULL,   -- denormalizacja z edition_id.event_id — pod zapytania o relację z CAŁYM wydarzeniem (Message::canMessage(), uprawnienia), niezależnie od konkretnego turnusu
  edition_id                    BIGINT UNSIGNED NOT NULL,   -- KONKRETNY turnus (patrz event_editions) — ten sam user może mieć osobny zapis na każdy termin tego samego wydarzenia
  variant_id                    BIGINT UNSIGNED NULL,       -- wybrany wariant trasy (event_route_variants); NULL = event bez wariantów. Cena/limit z wariantu. Patrz migration_031.
  user_id                       BIGINT UNSIGNED NOT NULL,
  status_item_id                INT UNSIGNED NOT NULL,   -- dict: rsvp_status (potwierdzony/oczekuje_platnosci/oczekuje_doplaty/oczekuje_zwrotu/lista_rezerwowa/anulowany/zainteresowany)
  joined_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payment_confirmed_at          TIMESTAMP NULL,          -- ustawiane gdy SUMA wpłat (event_rsvp_payments) pokryje pełną cenę — rozliczenie zamknięte
  payment_confirmed_by_user_id  BIGINT UNSIGNED NULL,
  last_payment_confirmed_at     TIMESTAMP NULL,          -- ostatnia częściowa wpłata (status -> oczekuje_doplaty) — pod sortowanie kolejki, dokładne kwoty w event_rsvp_payments
  last_payment_confirmed_by_user_id BIGINT UNSIGNED NULL,
  refund_requested_at           TIMESTAMP NULL,          -- ustawiane przez uczestnika (EventRsvp::requestCancellation())
  refund_processed_at           TIMESTAMP NULL,          -- ustawiane ręcznie przez organizatora — zwrot zawsze potwierdza tylko on
  refund_processed_by_user_id   BIGINT UNSIGNED NULL,
  CONSTRAINT fk_rsvp_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsvp_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsvp_variant FOREIGN KEY (variant_id) REFERENCES event_route_variants(id) ON DELETE SET NULL,
  CONSTRAINT fk_rsvp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsvp_status FOREIGN KEY (status_item_id) REFERENCES dictionary_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rsvp_payment_confirmed_by FOREIGN KEY (payment_confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_rsvp_deposit_confirmed_by FOREIGN KEY (last_payment_confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_rsvp_refund_processed_by FOREIGN KEY (refund_processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_edition_user (edition_id, user_id),
  INDEX idx_rsvp_event (event_id),
  INDEX idx_rsvp_variant (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rejestr pojedynczych wpłat na poczet rezerwacji (event_rsvps) — organizator
-- wpisuje faktycznie otrzymaną kwotę przy KAŻDYM potwierdzeniu (niekoniecznie
-- równą zaliczce ani całej cenie), SUMA decyduje o statusie
-- (oczekuje_doplaty dopóki suma < cena, potwierdzony gdy suma >= cena).
-- Pozwala później zweryfikować, czy wszystkie wpłaty zostały odnotowane
-- (bilans: SUM(amount) per rsvp_id vs event_pricing.price_amount).
CREATE TABLE event_rsvp_payments (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rsvp_id               BIGINT UNSIGNED NOT NULL,
  amount                DECIMAL(10,2) NOT NULL,
  confirmed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_by_user_id  BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_rsvp_payment_rsvp FOREIGN KEY (rsvp_id) REFERENCES event_rsvps(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsvp_payment_confirmed_by2 FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Byłem" — potwierdzenie faktycznej obecności (migr. 035). BRAK wiersza =
-- "jeszcze nie odpowiedział"; attended=FALSE = "odpowiedział, że nie dojechał".
-- Te dwa stany NIE są tym samym — patrz komentarz w migration_035.
-- Kolumny gps_* są z pierwotnego projektu i czekają na weryfikację śladem.
CREATE TABLE event_attendance (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rsvp_id                BIGINT UNSIGNED NOT NULL UNIQUE,
  attended               BOOLEAN NOT NULL DEFAULT TRUE,
  declared_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  declared_by_user_id    BIGINT UNSIGNED NULL,       -- uczestnik SAM albo organizator z listy uczestników
  confirmed_by_organizer BOOLEAN NOT NULL DEFAULT FALSE,
  gps_verified           BOOLEAN NOT NULL DEFAULT FALSE,
  verified_at            DATETIME NULL,
  tracked_distance_km    DECIMAL(6,2) NULL,
  source_item_id         INT UNSIGNED NULL,          -- dict: attendance_source (owntracks/strava/manual)
  CONSTRAINT fk_att_rsvp FOREIGN KEY (rsvp_id) REFERENCES event_rsvps(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_declared_by FOREIGN KEY (declared_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_att_source FOREIGN KEY (source_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  INDEX idx_att_attended (attended)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PELETON (migr. 036) — relacja "jeździliśmy razem", wyprowadzona z
-- FAKTYCZNEJ wspólnej obecności (event_attendance), nie z zapisów i nie z
-- żadnego "obserwuj". Zmaterializowany agregat, NIE źródło prawdy — da się
-- odtworzyć w całości z event_attendance (RiderConnection::recomputeForEdition).
-- Para kanoniczna: zawsze user_a_id < user_b_id, jeden wiersz na parę, dzięki
-- czemu relacja jest symetryczna z definicji.
CREATE TABLE rider_connections (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_a_id      BIGINT UNSIGNED NOT NULL,
  user_b_id      BIGINT UNSIGNED NOT NULL,
  rides_count    INT UNSIGNED NOT NULL DEFAULT 0,
  shared_km      DECIMAL(9,2) NOT NULL DEFAULT 0,
  first_ride_at  DATE NULL,
  last_ride_at   DATE NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rider_pair (user_a_id, user_b_id),
  KEY idx_rc_a (user_a_id),
  KEY idx_rc_b (user_b_id),
  CONSTRAINT fk_rc_user_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_user_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 8. REPUTACJA (dwustronna — uczestnik ocenia organizatora i odwrotnie)
-- ---------------------------------------------------------

CREATE TABLE event_reviews (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id            BIGINT UNSIGNED NOT NULL,
  reviewer_user_id    BIGINT UNSIGNED NOT NULL,
  target_user_id      BIGINT UNSIGNED NOT NULL,
  rating              TINYINT UNSIGNED NOT NULL,
  comment             TEXT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rev_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_review (event_id, reviewer_user_id, target_user_id),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Galeria eventu = wszystkie zdjęcia dla event_id, niezależnie od źródła:
-- review_id ustawione = zdjęcie dołączone do opinii, recap_id = do relacji,
-- oba NULL = wgrane bezpośrednio przez organizatora do galerii. Zdefiniowane
-- tutaj (nie w sekcji 6, ZDJĘCIA) bo FK do event_reviews musi już istnieć.
CREATE TABLE event_photos (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id      BIGINT UNSIGNED NOT NULL,
  review_id     BIGINT UNSIGNED NULL,
  recap_id      BIGINT UNSIGNED NULL,
  url           VARCHAR(500) NOT NULL,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  is_cover      BOOLEAN NOT NULL DEFAULT FALSE,
  uploaded_by   BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_photo_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_photo_review FOREIGN KEY (review_id) REFERENCES event_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_photo_recap FOREIGN KEY (recap_id) REFERENCES event_recaps(id) ON DELETE CASCADE,
  CONSTRAINT fk_photo_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 9. DYSKUSJA (komentarze z jednopoziomowymi odpowiedziami)
-- ---------------------------------------------------------

CREATE TABLE event_comments (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id             BIGINT UNSIGNED NOT NULL,
  user_id              BIGINT UNSIGNED NOT NULL,
  parent_comment_id    BIGINT UNSIGNED NULL,
  body                 TEXT NOT NULL,
  is_organizer_reply   BOOLEAN NOT NULL DEFAULT FALSE,
  -- Para Q&A wpisana przez organizatora (migr. 034) — pytanie pokazywane
  -- anonimowo, mimo że wierszem formalnie włada organizator. Patrz komentarz
  -- w migration_034_comment_faq.sql.
  is_faq               BOOLEAN NOT NULL DEFAULT FALSE,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NULL,
  CONSTRAINT fk_com_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_com_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_com_parent FOREIGN KEY (parent_comment_id) REFERENCES event_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prosty moduł wiadomości 1:1 (patrz migration_020_messages.sql) — jedna
-- konwersacja NA PARĘ userów (user_low_id < user_high_id), nie per wydarzenie.
-- Dostęp do rozpoczęcia konwersacji bramkowany relacją "wspólne wydarzenie"
-- (Models\Message::canMessage), nie otwarte DM dla dowolnej pary userów.
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
-- kolumna, bo konwersacja jest zawsze dokładnie dwuosobowa.
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

-- Kanał grupowy wydarzenia (patrz migration_028_event_group_conversations.sql)
-- — prywatna dyskusja CAŁEJ grupy PER TURNUS (edition_id), odrębna od modułu 1:1
-- wyżej i od publicznego Q&A (event_comments). Członkostwo liczone na żywo
-- (bez tabeli członków): organizator/współpracownik LUB realny zapis na turnus,
-- patrz Models\EventGroupConversation::canAccess.
CREATE TABLE event_group_conversations (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id       BIGINT UNSIGNED NOT NULL,
  event_id         BIGINT UNSIGNED NOT NULL,
  last_message_at  TIMESTAMP NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_group_edition (edition_id),
  CONSTRAINT fk_grpconv_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id) ON DELETE CASCADE,
  CONSTRAINT fk_grpconv_event   FOREIGN KEY (event_id)   REFERENCES events(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- is_organizer utrwalane przy zapisie — plakietka "Organizator" + reguła maili.
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

-- Per-user znacznik przeczytania (kanał wieloosobowy — read_at z messages nie wystarcza).
CREATE TABLE event_group_reads (
  group_conversation_id  BIGINT UNSIGNED NOT NULL,
  user_id                BIGINT UNSIGNED NOT NULL,
  last_read_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_conversation_id, user_id),
  CONSTRAINT fk_grpread_conv FOREIGN KEY (group_conversation_id) REFERENCES event_group_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_grpread_user FOREIGN KEY (user_id)               REFERENCES users(id)                     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 9b. SILNIK AI (rozszerzenie "AI-Engine (Groq)" — dziennik ekstrakcji,
-- NIE pełny zrzut wejścia; patrz Models\AiImportLog i migration_032)
-- ---------------------------------------------------------

CREATE TABLE ai_import_logs (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  url            VARCHAR(500) NOT NULL,
  source_domain  VARCHAR(190) NULL,
  element_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  confidence     VARCHAR(10) NULL,
  result_json    JSON NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_import_domain (source_domain),
  INDEX idx_ai_import_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 9c. DISCOVERY GRID (Etap 8, migr. 040) — heksagonalne "pola" odkrywane
-- przez rzeczywistą jazdę; mapa osobista, wspólna mapa społeczności,
-- znane trasy. Pełne uzasadnienie każdej decyzji w migration_040.
-- ---------------------------------------------------------

-- Przejazd jako byt ABSTRAKCYJNY, ze źródłem wymiennym. Dziś istnieje jedno
-- ('event_route' — potwierdzona obecność + trasa wydarzenia), bo serwis nie ma
-- śladów GPS użytkowników; własny GPX i Strava dołożą tu wiersze, nie nową
-- architekturę.
CREATE TABLE rider_activities (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            BIGINT UNSIGNED NOT NULL,
  source_code        VARCHAR(20) NOT NULL,       -- 'event_track' | 'own_track' | 'solo' (migr. 049) | 'strava'
  -- TYP ROWERU (migr. 091) — słownik `bike_type`; NULL = nieznany (neutralny
  -- dla planera). Z importu (aliasy w meta typu) albo wybrany ręcznie.
  bike_type_item_id  INT UNSIGNED NULL,
  edition_id         BIGINT UNSIGNED NULL,
  rsvp_id            BIGINT UNSIGNED NULL,
  -- PRZEJAZD SOLO (migr. 049) - slad bez wydarzenia. Ta sama tabela, bo solo to
  -- kolejne ZRODLO przejazdu, nie nowy rodzaj bytu. Idempotencje daje klucz
  -- unikalny (user_id, gpx_hash): ten sam plik wgrany drugi raz odbija sie
  -- o baze, zamiast naliczyc punkty ponownie.
  gpx_url            VARCHAR(500) NULL,
  gpx_hash           CHAR(64) NULL,
  -- WŁASNA NAZWA (migr. 080) — nadpisuje nazwę z licznika (device_activities.
  -- activity_name), gdy właściciel ją ustawi; NULL = użyj nazwy z licznika,
  -- a gdy i tej nie ma, generycznego opisu. Tylko przejazdy solo (pilnowane
  -- w kodzie, nie w schemacie — patrz RiderActivity::rename()).
  name               VARCHAR(190) NULL,
  ride_date          DATE NULL,
  -- Ze znacznikow czasu w GPX (Utils\Gpx::parse). PUNKTACJA ICH NIE UZYWA i nie
  -- ma uzywac - sa po to, zeby przejazd solo mial kiedy sie odbyc (bez turnusu
  -- nie ma skad wziac daty) i zeby odroznic zapis z licznika od trasy z planera.
  started_at         DATETIME NULL,
  moving_seconds     INT UNSIGNED NULL,
  distance_km        DECIMAL(7,2) NOT NULL DEFAULT 0,
  elevation_gain_m   INT UNSIGNED NOT NULL DEFAULT 0,   -- migr. 043; Gpx::parse i tak je liczy, dołożenie później = ponowne parsowanie wszystkich plików
  cells_touched      INT UNSIGNED NOT NULL DEFAULT 0,
  cells_new          INT UNSIGNED NOT NULL DEFAULT 0,
  -- PUNKTÓW TU NIE MA (usunięte migr. 045). Mieszkają wyłącznie w
  -- point_transactions — sumy na przejeździe nie umiały odpowiedzieć „za co"
  -- i nie miały gdzie pomieścić punktów za jazdę ani za wydarzenie.
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_activity_rsvp (rsvp_id),
  -- Klucz na PARZE, nie na samym hashu: dwie osoby moga uczciwie wgrac ten sam
  -- plik (wspolny wyjazd, jeden licznik na dwoje) i obie maja prawo do swoich
  -- pol. Powtorka blokowana jest w obrebie JEDNEGO konta.
  UNIQUE KEY idx_ra_user_gpx (user_id, gpx_hash),
  KEY idx_ra_user_date (user_id, ride_date),
  KEY idx_ra_edition (edition_id),
  KEY idx_ra_bike_type (bike_type_item_id),
  CONSTRAINT fk_ra_bike_type FOREIGN KEY (bike_type_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_ra_user    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE,
  CONSTRAINT fk_ra_edition FOREIGN KEY (edition_id) REFERENCES event_editions(id)  ON DELETE SET NULL,
  CONSTRAINT fk_ra_rsvp    FOREIGN KEY (rsvp_id)    REFERENCES event_rsvps(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Odkrywanie jest ważniejsze od powtarzania" jako NIEZMIENNIK BAZY: klucz
-- główny (user_id, cell_id) sprawia, że drugi przejazd tą samą drogą nie ma
-- jak dopisać wiersza. INSERT IGNORE, a liczba wstawionych wierszy JEST liczbą
-- nowych odkryć.
CREATE TABLE discovery_cells (
  user_id       BIGINT UNSIGNED NOT NULL,
  cell_id       BIGINT NOT NULL,              -- spakowane (res|q|r), patrz Utils\DiscoveryGrid
  activity_id   BIGINT UNSIGNED NULL,
  discovered_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, cell_id),
  KEY idx_dc_cell (cell_id),
  CONSTRAINT fk_dc_user     FOREIGN KEY (user_id)     REFERENCES users(id)             ON DELETE CASCADE,
  CONSTRAINT fk_dc_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wspólna mapa. Zmaterializowany agregat, NIE źródło prawdy — odtwarzalny w
-- całości z discovery_cells (ten sam wzorzec co rider_connections).
-- cell_q/cell_r zdenormalizowane, bo po spakowanym cell_id nie da się zrobić
-- zapytania prostokątnego pod widoczny obszar mapy.
CREATE TABLE discovery_cell_totals (
  cell_id       BIGINT NOT NULL PRIMARY KEY,
  cell_q        INT NOT NULL,
  cell_r        INT NOT NULL,
  -- Heksagon nadrzędny na każdym poziomie oddalenia mapy (migr. 047). Liczony
  -- przez Utils\DiscoveryGrid::parentsFor() PRZY ZAPISIE, bo przynależność
  -- rozstrzyga geometria siatki, której nie da się wyrazić w SQL-u. Wcześniej
  -- mapa grupowała po prostokącie FLOOR(cell_q / krok) i heksagony gubiły się
  -- przy skalowaniu — prostokąt siatki drobnej nie pokrywa się z heksagonem
  -- siatki grubej. Res 4 nie ma kolumny: pole jest wtedy swoim rodzicem.
  parent_res3   BIGINT NULL,
  parent_res2   BIGINT NULL,
  parent_res1   BIGINT NULL,
  parent_res0   BIGINT NULL,
  riders_count  INT UNSIGNED NOT NULL DEFAULT 0,  -- ILU LUDZI odkryło (warstwa mgły)
  passes_count  INT UNSIGNED NOT NULL DEFAULT 0,  -- ILE RAZY ktokolwiek przejechał, z powtórzeniami (warstwa heatmapy, migr. 041)
  first_user_id BIGINT UNSIGNED NULL,          -- tylko pod bonus eksploracyjny; NIGDY nie trafia na mapę (§27)
  first_seen_at DATETIME NULL,
  last_seen_at  DATETIME NULL,
  KEY idx_dct_bbox (cell_r, cell_q),
  KEY idx_dct_parent3 (parent_res3),
  KEY idx_dct_parent2 (parent_res2),
  KEY idx_dct_parent1 (parent_res1),
  KEY idx_dct_parent0 (parent_res0),
  CONSTRAINT fk_dct_first_user FOREIGN KEY (first_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ŚLAD Z ODBYTEGO WYJAZDU (migr. 042) — jedyne źródło, z którego Discovery
-- liczy odkryte pola. Trasa PLANOWANA (event_stages.gpx_url) jest zapowiedzią,
-- nie dowodem: kto skrócił trasę albo zawrócił, odkrywałby na niej dokładnie
-- to samo, co ten, kto przejechał wszystko.
--   user_id IS NULL  = ślad z imprezy wgrany przez organizatora po wyjeździe;
--                      liczy się każdemu, kto potwierdził obecność,
--   user_id ustawione = własny ślad uczestnika; liczy się tylko jemu i MA
--                      PIERWSZEŃSTWO przed śladem zbiorowym (jest prawdą o tej
--                      konkretnej osobie).
-- Per TURNUS, nie per wydarzenie: rzeczywisty przebieg różni się między
-- terminami, w odróżnieniu od trasy planowanej.
CREATE TABLE edition_tracks (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id          BIGINT UNSIGNED NOT NULL,
  user_id             BIGINT UNSIGNED NULL,
  gpx_url             VARCHAR(500) NOT NULL,
  label               VARCHAR(150) NULL,            -- np. "Dzień 2" przy wielodniówce
  distance_km         DECIMAL(7,2) NOT NULL DEFAULT 0,
  uploaded_by_user_id BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Bez klucza unikalnego na (edition_id, user_id): wielodniówka ma osobny
  -- plik na każdy dzień, więc wiele wierszy na tę samą parę jest normą.
  KEY idx_et_edition_user (edition_id, user_id),
  CONSTRAINT fk_et_edition  FOREIGN KEY (edition_id)          REFERENCES event_editions(id) ON DELETE CASCADE,
  CONSTRAINT fk_et_user     FOREIGN KEY (user_id)             REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_et_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Które pola dotknął KAŻDY przejazd — także taki, który nie odkrył niczego
-- nowego (migr. 041). Źródło prawdy dla passes_count: bez tego licznik byłby
-- jedyną kopią tej informacji i przestałby być odtwarzalny, a cały moduł stoi
-- na zasadzie „agregat da się zbudować od zera ze źródła".
-- Punktacji to NIE dotyczy — powtórzenia nadal nie dają ani jednego punktu
-- (§5), passes_count jest liczbą wyłącznie do pokazania na mapie.
CREATE TABLE rider_activity_cells (
  activity_id BIGINT UNSIGNED NOT NULL,
  cell_id     BIGINT NOT NULL,
  PRIMARY KEY (activity_id, cell_id),
  KEY idx_rac_cell (cell_id),
  CONSTRAINT fk_rac_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Znane trasy (Velo Czorsztyn, Green Velo, Szlak Orlich Gniazd...) jako DANE,
-- nigdy jako kod. POSTĘPU UŻYTKOWNIKA NIE MA W ŻADNEJ TABELI — jest
-- przecięciem known_route_cells z discovery_cells, liczonym jednym zapytaniem
-- (jak Kronika i Puls, które też nie mają własnych tabel).
CREATE TABLE known_routes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(190) NOT NULL,
  name           VARCHAR(200) NOT NULL,
  description    TEXT NULL,
  -- region_item_id USUNIĘTE migr. 075 — region jest wyprowadzany automatycznie
  -- z przebiegu (`known_route_regions`, migr. 074: known_route_cells ⋈
  -- region_cells), nie wybierany ręcznie. Patrz Models\KnownRoute::syncRegions.
  gpx_url        VARCHAR(500) NULL,
  cover_photo_url VARCHAR(500) NULL,           -- migr. 046; ta sama ścieżka co okładka wydarzenia (Upload::saveCoverPhoto)
  distance_km    DECIMAL(7,2) NOT NULL DEFAULT 0,
  -- migr. 063. NULL = przewyzszenie NIEZNANE (trasa sprzed migracji, jeszcze
  -- nieprzeliczona), 0 = trasa faktycznie plaska. Dlatego kolumna jest
  -- nullowalna, a nie DEFAULT 0: zero jest tu poprawna wartoscia.
  elevation_gain_m INT UNSIGNED NULL,
  -- Nawierzchnia (migr. 086) — te same trzy kolumny i ten sam detektor
  -- (Utils\RoadSurfaceDetector, Overpass) co przy `event_stages`. Bez
  -- `surface_item_id`: ręczny awaryjny wybór ze słownika ma sens dla wydarzenia
  -- bez GPX-a, a znana trasa bez GPX-a nie istnieje. NULL = nie wiadomo
  -- (Overpass niedostępny / trasa sprzed migracji), nie „zero asfaltu".
  surface_asphalt_pct SMALLINT UNSIGNED NULL,
  surface_gravel_pct  SMALLINT UNSIGNED NULL,
  surface_trail_pct   SMALLINT UNSIGNED NULL,
  cells_total    INT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  -- Bonus PER TRASA (migr. 043). NULL = użyj wartości globalnej z
  -- core/discovery.php, czyli zachowanie sprzed tej kolumny — Green Velo może
  -- być warte więcej niż lokalna pętla, ale nie musi.
  bonus_enabled    TINYINT(1) NOT NULL DEFAULT 1,
  bonus_points     INT UNSIGNED NULL,
  completion_bonus INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_known_route_slug (slug),
  KEY idx_kr_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CACHE POL SIATKI ODKRYC DLA PLIKU GPX (migr. 050) - klucz to HASH ZAWARTOSCI,
-- nie sciezka ani id etapu. Ten sam slad bywa podpiety w kilku miejscach (etap,
-- wariant, znana trasa) i liczy sie raz dla wszystkich; nie ma tez dwoch
-- nullowalnych kluczy obcych do rozstrzygania przy kazdym odczycie.
--
-- Zywi funkcje "co mi ta trasa da" na stronie wydarzenia: bez cache'u kazde
-- wejscie parsowaloby GPX-y od nowa (zmierzone: 14-31 ms na trase, wielodniowka
-- ma plik na dzien). Plik jest niezmienny, wiec cache jest wazny bezterminowo.
--
-- ODTWARZALNE W CALOSCI - skasowanie niczego nie psuje, kolejny odczyt policzy
-- ponownie. Stad bez kluczy obcych i bez sprzatania.
CREATE TABLE gpx_route_cells (
  gpx_hash CHAR(64) NOT NULL,
  cell_id  BIGINT NOT NULL,
  PRIMARY KEY (gpx_hash, cell_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Znacznik "ten plik juz policzony" - osobno, bo plik, ktorego slad nie dotknal
-- ani jednego pola (uszkodzony, za krotki), nie ma wierszy wyzej i bez tego
-- bylby parsowany przy kazdym wyswietleniu strony.
CREATE TABLE gpx_route_cell_runs (
  gpx_hash    CHAR(64) NOT NULL PRIMARY KEY,
  cells_count INT UNSIGNED NOT NULL DEFAULT 0,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- KAFLE RASTROWE (migr. 051) - mapy nie laduja juz plikow GPX do przegladarki.
-- Powod: profil z 600 sladami pobieral 600 plikow (zmierzone ok. 108 MB, ok. 25 s
-- do pierwszego obrazu, ok. 2,7 s na kazdy krok zoomu), a to strona publiczna.
--
-- GEOMETRIA W PIKSELACH SWIATA NA ZOOMIE 18, nie w stopniach: kafel dowolnego
-- zoomu dostaje sie z tych liczb przesunieciem bitowym, bez trygonometrii przy
-- rysowaniu. 2^18 * 256 = 67 mln miesci sie w 27 bitach, a 0,37 m na piksel jest
-- ponizej dokladnosci GPS. Zmierzone: 179 864 B GPX -> 16 856 B geometrii.
CREATE TABLE gpx_geometry (
  gpx_hash    CHAR(64) NOT NULL PRIMARY KEY,
  point_count INT UNSIGNED NOT NULL DEFAULT 0,
  -- Kolor sladu na kaflu (migr. 073) — indeks w `Utils\TrackPalette::COLORS`.
  -- Slady lezace blisko siebie dostaja rozne kolory, zeby dalo sie je odroznic
  -- tam, gdzie sie nakladaja (Models\GpxGeometry::assignColor; sasiedztwo po
  -- wspolnych kaflach `gpx_tiles`). NULL = jeszcze nieprzydzielony: renderer
  -- spada wtedy na kolor stylu, czyli rysuje tak jak przed migracja.
  color_index TINYINT UNSIGNED NULL DEFAULT NULL,
  min_px      INT NOT NULL,
  min_py      INT NOT NULL,
  max_px      INT NOT NULL,
  max_py      INT NOT NULL,
  points      MEDIUMBLOB NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Ktore slady przechodza przez ten kafel". JEDEN POZIOM (z14) WYSTARCZA NA
-- WSZYSTKIE ZOOMY i to jest roznica wzgledem siatki heksagonalnej, gdzie trzeba
-- bylo dolozyc parent_res0..3 (migr. 047): kafle sa zagniezdzone przez samo
-- przesuniecie bitowe, wiec pytanie o kafel zoomu z to zakres na tych samych
-- kolumnach. Poziom 14 to sufit generowania - z15 to 804 000 kafli na sama
-- Polske i 15 GB, czyli limit i-wezlow na wspoldzielonym hostingu.
CREATE TABLE gpx_tiles (
  gpx_hash CHAR(64) NOT NULL,
  tx       INT UNSIGNED NOT NULL,
  ty       INT UNSIGNED NOT NULL,
  PRIMARY KEY (gpx_hash, tx, ty),
  KEY idx_gpx_tiles_xy (tx, ty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PRZYCIETA geometria solo (migr. 076) — druga para tabel obok gpx_geometry/
-- gpx_tiles, kluczowana TYM SAMYM gpx_hash (hash surowego pliku), relacja 1:1
-- "przycieta wersja tego pliku". Slad solo zaczyna/konczy sie pod domem, a
-- klucz `all` (mapa spolecznosci) leszy publicznie na dysku pod adresem do
-- zgadniecia — dlatego solo NIGDY nie wchodzi do gpx_geometry/gpx_tiles wprost,
-- tylko tu, po przycieciu (DiscoveryGrid::trimEnds, ten sam promien co przy
-- polach odkryc). Bez koloru — heatmapa community maluje wszystko jednym
-- stylem (TileSource::STYLES['heat']), nie paleta per slad.
CREATE TABLE gpx_geometry_trimmed (
  gpx_hash    CHAR(64) NOT NULL PRIMARY KEY,
  point_count INT UNSIGNED NOT NULL DEFAULT 0,
  min_px      INT NOT NULL,
  min_py      INT NOT NULL,
  max_px      INT NOT NULL,
  max_py      INT NOT NULL,
  points      MEDIUMBLOB NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gpx_tiles_trimmed (
  gpx_hash CHAR(64) NOT NULL,
  tx       INT UNSIGNED NOT NULL,
  ty       INT UNSIGNED NOT NULL,
  PRIMARY KEY (gpx_hash, tx, ty),
  KEY idx_gpx_tiles_trimmed_xy (tx, ty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Skasowanie pliku z dysku zalatwia serwer, ale nie przegladarki: na *.png leci
-- Cache-Control immutable na rok (.htaccess). Dlatego adres kafla niesie
-- ?v=<epoka>, a zmiana danych ja podbija - ten sam wzorzec co ?v=<filemtime>
-- w Utils\View::asset(), tylko dla zbioru plikow zamiast jednego.
CREATE TABLE tile_epochs (
  layer      VARCHAR(24) NOT NULL,
  cache_key  VARCHAR(48) NOT NULL,
  epoch      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (layer, cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rejestr tego, co lezy na dysku. Po co, skoro pliki sa na dysku: policzenie ich
-- wymaga przejscia drzewa katalogow, a przyciecie "najstarszych" - odczytu mtime
-- kazdego z osobna; przy setkach tysiecy plikow to nie jest operacja na czas
-- zadania. Kafle sa w pelni odtwarzalne, wiec bez kluczy obcych.
CREATE TABLE tile_cache (
  layer        VARCHAR(24) NOT NULL,
  cache_key    VARCHAR(48) NOT NULL,
  z            TINYINT UNSIGNED NOT NULL,
  x            INT UNSIGNED NOT NULL,
  y            INT UNSIGNED NOT NULL,
  bytes        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (layer, cache_key, z, x, y),
  KEY idx_tile_cache_age (last_used_at),
  KEY idx_tile_cache_key (layer, cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE known_route_cells (
  route_id BIGINT UNSIGNED NOT NULL,
  cell_id  BIGINT NOT NULL,
  -- Wspolrzedne osiowe zdenormalizowane z cell_id (migr. 061), dokladnie jak na
  -- discovery_cell_totals: po spakowanym identyfikatorze nie da sie filtrowac
  -- prostokatem kadru. Do migr. 061 warstwa mapy braa je z JOIN-a z
  -- discovery_cell_totals i przez to POKAZYWALA WYLACZNIE trasy, ktore ktos juz
  -- przejechal — swiezo dodana trasa byla niewidoczna.
  cell_q INT NULL,
  cell_r INT NULL,
  -- Pozycja pola WZDLUZ SLADU (migr. 048). Do liczenia postepu zbedna (postep
  -- to przeciecie zbiorow), niezbedna do RYSOWANIA trasy jako linii na mapie:
  -- bez niej byl to zygzak po kolejnosci wstawiania. NULL = trasa sprzed
  -- migracji, warstwa mapy ja pomija (naprawa: „Przelicz pola" w panelu).
  sort_order INT UNSIGNED NULL,
  PRIMARY KEY (route_id, cell_id),
  KEY idx_krc_cell (cell_id),
  KEY idx_krc_order (route_id, sort_order),
  KEY idx_krc_bbox (cell_r, cell_q),
  CONSTRAINT fk_krc_route FOREIGN KEY (route_id) REFERENCES known_routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 9d. RIDEMORE POINTS (Etap 8A, migr. 043) — rejestr naliczeń punktów.
-- ŹRÓDŁO PRAWDY dla wyniku użytkownika; kolumny points_* na rider_activities
-- są od tej migracji najwyżej cache'em do odczytu i nic nie ma prawa ich
-- zwiększać z pominięciem rejestru.
-- ---------------------------------------------------------

-- Suma bez historii jest nierozstrzygalna: nie da się z niej odtworzyć, czy
-- 500 punktów to jeden bonus za ukończenie trasy, czy pięć drobnych naliczeń.
-- Stąd osobny wpis na każde zdarzenie punktowe.
--
-- IDEMPOTENCJA JEST KLUCZEM UNIKALNYM, nie warunkiem w kodzie: powtórne
-- przetworzenie tego samego zdarzenia odbija się o uq_point_event. Dobór
-- source_id decyduje, CO wolno powtórzyć:
--   RIDE / DISCOVERY / EXPLORATION -> id przejazdu  (nowy przejazd = nowe punkty)
--   TRAIL_THRESHOLD                -> "trasa:próg"  (RAZ W ŻYCIU)
--   TRAIL_COMPLETION               -> "trasa"       (RAZ W ŻYCIU)
--   EVENT                          -> id turnusu    (raz na turnus)
-- Dzięki temu FIRST DISCOVERY > REPEAT VISIT jest własnością schematu.
CREATE TABLE point_transactions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  source      VARCHAR(32) NOT NULL,   -- nazwa ŹRÓDŁA, nigdy nazwa własna nagrody
  source_id   VARCHAR(64) NOT NULL,   -- VARCHAR, bo progi tras potrzebują pary "12:50"
  points      INT NOT NULL,           -- ZE ZNAKIEM: korekta musi być WPISEM, nie usunięciem historii
  activity_id BIGINT UNSIGNED NULL,   -- NULL dla przyszłych źródeł bez przejazdu (misje, kolekcje)
  ride_date   DATE NULL,              -- data ZDARZENIA W ŚWIECIE, nie zapisu (backfill!)
  description VARCHAR(190) NULL,      -- podpis dla człowieka, zamrożony w chwili przyznania
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_point_event (user_id, source, source_id),
  KEY idx_pt_user_date (user_id, ride_date),
  KEY idx_pt_activity (activity_id),
  CONSTRAINT fk_pt_user     FOREIGN KEY (user_id)     REFERENCES users(id)            ON DELETE CASCADE,
  -- CASCADE: wycofanie obecności kasuje przejazd, więc jego punkty znikają
  -- razem z nim — nie zostaje wynik bez pokrycia w faktach.
  CONSTRAINT fk_pt_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 10. WIDOKI POMOCNICZE
-- ---------------------------------------------------------

-- Sumy trasy i liczba dni liczone z etapów — jedno źródło prawdy,
-- zero redundancji. duration_days > 1  ==  "to jest wycieczka wielodniowa".
CREATE VIEW event_totals AS
SELECT
  event_id,
  COUNT(*)                AS duration_days,
  SUM(distance_km)        AS total_distance_km,
  SUM(elevation_gain_m)   AS total_elevation_m
FROM event_stages
GROUP BY event_id;

-- ---------------------------------------------------------
-- 11. PRZYKŁADOWE DANE SŁOWNIKOWE (seed)
-- Dodanie nowego typu eventu (np. "zawody" albo "warsztat serwisowy")
-- w przyszłości = jeden INSERT do dictionary_items. Zero migracji.
-- ---------------------------------------------------------

INSERT INTO dictionaries (code, name) VALUES
  ('event_type', 'Typ wydarzenia'),
  ('event_status', 'Status wydarzenia'),
  ('difficulty_level', 'Poziom trudności'),
  ('bike_type', 'Typ roweru'),
  ('pace_group', 'Grupa tempa'),
  ('surface_type', 'Typ nawierzchni'),
  ('equipment_item', 'Wymagany sprzęt'),
  ('meal_type', 'Typ posiłku'),
  ('accommodation_type', 'Typ noclegu'),
  ('organizer_type', 'Typ organizatora'),
  ('verification_status', 'Status weryfikacji'),
  ('currency', 'Waluta'),
  ('price_unit', 'Jednostka ceny'),
  ('inclusion_category', 'Kategoria pozycji cennika'),
  ('rsvp_status', 'Status zapisu'),
  ('attendance_source', 'Źródło weryfikacji obecności'),
  ('region', 'Region'),
  ('registration_type', 'Sposób zapisów');

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order) VALUES
  ((SELECT id FROM dictionaries WHERE code='event_type'), 'ustawka', 'Zorganizowane wydarzenie', 1),
  ((SELECT id FROM dictionaries WHERE code='event_type'), 'wycieczka_wielodniowa', 'Wycieczka wielodniowa', 2),
  ((SELECT id FROM dictionaries WHERE code='event_type'), 'pokrec_z_kims', 'Pokręcę z kimś', 3),
  -- Wyścig (migr. 033) — formularz jednodniowy identyczny z 'ustawka', patrz komentarz w tamtej migracji.
  ((SELECT id FROM dictionaries WHERE code='event_type'), 'wyscig', 'Wyścig', 4),
  ((SELECT id FROM dictionaries WHERE code='event_status'), 'draft', 'Szkic', 1),
  ((SELECT id FROM dictionaries WHERE code='event_status'), 'published', 'Opublikowane', 2),
  ((SELECT id FROM dictionaries WHERE code='event_status'), 'full', 'Komplet', 3),
  ((SELECT id FROM dictionaries WHERE code='event_status'), 'cancelled', 'Odwołane', 4),
  ((SELECT id FROM dictionaries WHERE code='event_status'), 'completed', 'Zakończone', 5),
  ((SELECT id FROM dictionaries WHERE code='event_status'), 'oczekuje_weryfikacji', 'Oczekuje weryfikacji', 6),
  ((SELECT id FROM dictionaries WHERE code='organizer_type'), 'peer', 'Organizator społecznościowy', 1),
  ((SELECT id FROM dictionaries WHERE code='organizer_type'), 'professional_operator', 'Operator turystyczny', 2),
  ((SELECT id FROM dictionaries WHERE code='bike_type'), 'szosowy', 'Szosowy', 1),
  ((SELECT id FROM dictionaries WHERE code='bike_type'), 'gravel', 'Gravel', 2),
  ((SELECT id FROM dictionaries WHERE code='bike_type'), 'mtb', 'MTB', 3),
  ((SELECT id FROM dictionaries WHERE code='bike_type'), 'ebike', 'E-bike', 4),
  ((SELECT id FROM dictionaries WHERE code='inclusion_category'), 'nocleg', 'Nocleg', 1),
  ((SELECT id FROM dictionaries WHERE code='inclusion_category'), 'wyzywienie', 'Wyżywienie', 2),
  ((SELECT id FROM dictionaries WHERE code='inclusion_category'), 'transport_bagazu', 'Transport bagażu', 3),
  ((SELECT id FROM dictionaries WHERE code='inclusion_category'), 'przewodnictwo', 'Przewodnictwo', 4),
  ((SELECT id FROM dictionaries WHERE code='inclusion_category'), 'ubezpieczenie', 'Ubezpieczenie', 5),
  -- Element najwyższego poziomu (kraj) — regiony poniżej stają się jego
  -- dziećmi przez UPDATE zaraz po tym INSERT-cie. Docelowo pod niego dojdą
  -- kolejne kraje (Niemcy, Włochy...) z ich własnymi regionami.
  -- Od migr. 070 regionami są WOJEWÓDZTWA (granice administracyjne + pokrycie
  -- heksowe w tabeli region_cells); autorskie pasma schodzą — patrz tamta
  -- migracja, która też umie je zremapować ze starych kodów.
  ((SELECT id FROM dictionaries WHERE code='region'), 'polska', 'Polska', 0),
  ((SELECT id FROM dictionaries WHERE code='region'), 'dolnoslaskie', 'dolnośląskie', 1),
  ((SELECT id FROM dictionaries WHERE code='region'), 'kujawsko-pomorskie', 'kujawsko-pomorskie', 2),
  ((SELECT id FROM dictionaries WHERE code='region'), 'lubelskie', 'lubelskie', 3),
  ((SELECT id FROM dictionaries WHERE code='region'), 'lubuskie', 'lubuskie', 4),
  ((SELECT id FROM dictionaries WHERE code='region'), 'lodzkie', 'łódzkie', 5),
  ((SELECT id FROM dictionaries WHERE code='region'), 'malopolskie', 'małopolskie', 6),
  ((SELECT id FROM dictionaries WHERE code='region'), 'mazowieckie', 'mazowieckie', 7),
  ((SELECT id FROM dictionaries WHERE code='region'), 'opolskie', 'opolskie', 8),
  ((SELECT id FROM dictionaries WHERE code='region'), 'podkarpackie', 'podkarpackie', 9),
  ((SELECT id FROM dictionaries WHERE code='region'), 'podlaskie', 'podlaskie', 10),
  ((SELECT id FROM dictionaries WHERE code='region'), 'pomorskie', 'pomorskie', 11),
  ((SELECT id FROM dictionaries WHERE code='region'), 'slaskie', 'śląskie', 12),
  ((SELECT id FROM dictionaries WHERE code='region'), 'swietokrzyskie', 'świętokrzyskie', 13),
  ((SELECT id FROM dictionaries WHERE code='region'), 'warminsko-mazurskie', 'warmińsko-mazurskie', 14),
  ((SELECT id FROM dictionaries WHERE code='region'), 'wielkopolskie', 'wielkopolskie', 15),
  ((SELECT id FROM dictionaries WHERE code='region'), 'zachodniopomorskie', 'zachodniopomorskie', 16),
  ((SELECT id FROM dictionaries WHERE code='registration_type'), 'internal', 'Na ridemore.bike', 1),
  ((SELECT id FROM dictionaries WHERE code='registration_type'), 'external', 'Link zewnętrzny', 2),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'potwierdzony', 'Potwierdzony', 1),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'oczekuje_platnosci', 'Oczekuje na płatność', 2),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'lista_rezerwowa', 'Lista rezerwowa', 3),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'anulowany', 'Anulowany', 4),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'oczekuje_zwrotu', 'Oczekuje zwrotu', 5),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'zainteresowany', 'Zainteresowany', 6),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'oczekuje_doplaty', 'Oczekuje dopłaty', 7);

-- Podpięcie województw pod kontener "Polska" (patrz INSERT wyżej). Podzapytanie
-- owinięte w dodatkowy SELECT (alias `polska`) bo MySQL nie pozwala w UPDATE-ie
-- referencjonować wprost tej samej tabeli, którą się aktualizuje.
UPDATE dictionary_items
SET parent_id = (
  SELECT id FROM (
    SELECT id FROM dictionary_items
    WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'region') AND code = 'polska'
  ) AS polska
)
WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'region')
  AND code IN ('dolnoslaskie', 'kujawsko-pomorskie', 'lubelskie', 'lubuskie', 'lodzkie',
               'malopolskie', 'mazowieckie', 'opolskie', 'podkarpackie', 'podlaskie',
               'pomorskie', 'slaskie', 'swietokrzyskie', 'warminsko-mazurskie',
               'wielkopolskie', 'zachodniopomorskie');

-- Przykład: nowy typ eventu "zawody" w przyszłości to wyłącznie:
-- INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
-- VALUES ((SELECT id FROM dictionaries WHERE code='event_type'), 'zawody', 'Zawody', 3);

-- STAWKI PUNKTOWE NADPISANE PRZEZ ADMINA (migr. 053).
-- Tabela NIE zastepuje core/discovery.php, tylko go nadpisuje: plik zostaje
-- zrodlem wartosci domyslnych i uzasadnien (stoi tam sprawdzian proporcji miedzy
-- Discovery a Trails, ktorego nie da sie zapisac w kolumnie INT), a tu leza
-- wylacznie klucze realnie zmienione. Pusta tabela = zachowanie sprzed migracji,
-- skasowany wiersz = powrot do domyslnej bez znajomosci liczby.
-- Klucz jest SCIEZKA w konfiguracji ('discovery.points_per_new_cell').
-- Historii punktow to nie rusza - point_transactions (migr. 043) sa niezmienne,
-- wiec zmiana stawki dziala na przyszlosc, nigdy wstecz.
CREATE TABLE scoring_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value DECIMAL(12,4) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_scoring_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SKARBY (migr. 054, 055, 056) - punkty w terenie do znalezienia.
-- Przeciwienstwo pola Discovery: pole ma ok. 500 m i zalicza sie samo ze sladu
-- (nagradza PRZEJECHANIE terenu), skarb ma wspolrzedne z dokladnoscia do metrow
-- i wymaga zatrzymania sie oraz rozejrzenia. Hex (cell_id) sluzy WYLACZNIE do
-- rysowania ikonki na mapie i jest liczony przy zapisie.
-- `code` jest SEKRETEM z adresu QR, osobnym od id: po id daloby sie zgadnac
-- wszystkie skarby, a osobna kolumna pozwala wymienic kod po wycieku zdjecia
-- naklejki, zostawiajac historie znalezien.
CREATE TABLE treasures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  origin ENUM('OFFICIAL','ORGANIZER','PARTNER','COMMUNITY') NOT NULL DEFAULT 'COMMUNITY',
  rarity ENUM('COMMON','RARE','EPIC','LEGENDARY') NOT NULL DEFAULT 'COMMON',
  status ENUM('PROPOSED','ACTIVE','RETIRED') NOT NULL DEFAULT 'PROPOSED',
  reveal_level TINYINT UNSIGNED NOT NULL DEFAULT 2,
  hint VARCHAR(300) NULL,
  category_item_id INT UNSIGNED NULL,
  region_item_id INT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NULL,
  lat DECIMAL(10,7) NOT NULL,
  lon DECIMAL(10,7) NOT NULL,
  cell_id BIGINT NULL,
  points INT UNSIGNED NOT NULL DEFAULT 50,
  claim_radius_m SMALLINT UNSIGNED NOT NULL DEFAULT 150,
  photo_url VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  active_from DATE NULL,
  active_to DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_treasure_code (code),
  KEY idx_treasure_cell (cell_id),
  KEY idx_treasure_status (status, is_active),
  CONSTRAINT fk_treasure_category FOREIGN KEY (category_item_id) REFERENCES dictionary_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_treasure_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_treasure_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL,
  CONSTRAINT fk_treasure_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ZNALEZIENIA. uniq_find_once to CALE zabezpieczenie przed podwojnym
-- naliczeniem punktow - Models\Treasure::claim opiera sie na INSERT IGNORE
-- i rowCount, wiec wyscig rozstrzyga BAZA, a nie sprawdzenie w kodzie.
-- `method` bo drogi sa trzy i maja rozna wage dowodowa: QR (bylem i patrzylem),
-- GPS (bylem w poblizu), GPX (przejechalem obok).
CREATE TABLE treasure_finds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  treasure_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  method ENUM('QR','GPS','GPX') NOT NULL DEFAULT 'QR',
  claimed_lat DECIMAL(10,7) NULL,
  claimed_lon DECIMAL(10,7) NULL,
  distance_m INT UNSIGNED NULL,
  points_awarded INT UNSIGNED NOT NULL DEFAULT 0,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_find_once (treasure_id, user_id),
  KEY idx_find_user (user_id),
  CONSTRAINT fk_find_treasure FOREIGN KEY (treasure_id) REFERENCES treasures (id) ON DELETE CASCADE,
  CONSTRAINT fk_find_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GALERIA ZDJEC SKARBU (migr. 066). Odwzorowana z `event_photos` — ten sam
-- problem, ten sam ksztalt. Zdjecie GLOWNE zostaje w `treasures.photo_url`.
-- Bez kolumny moderacji: zdjecia z relacji tez sa publiczne od razu, a dwa
-- obiegi tej samej rzeczy w jednym serwisie byly by gorsze niz brak kolejki.
-- O WIDOCZNOSCI decyduje wylacznie Models\Treasure::reveal() — to samo miejsce,
-- ktore zeruje photo_url dla Tropu i Ukrytego.
CREATE TABLE treasure_photos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  treasure_id   BIGINT UNSIGNED NOT NULL,
  url           VARCHAR(500) NOT NULL,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  uploaded_by   BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tphoto_treasure (treasure_id, sort_order),
  KEY idx_tphoto_user (uploaded_by, treasure_id),
  CONSTRAINT fk_tphoto_treasure FOREIGN KEY (treasure_id) REFERENCES treasures (id) ON DELETE CASCADE,
  CONSTRAINT fk_tphoto_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Skarb zgloszony przez uzytkownika wchodzi na mape po progu potwierdzen.
-- Osobna tabela, nie licznik: licznik nie powstrzymalby jednej osoby przed
-- dodaniem dziesieciu potwierdzen, UNIQUE powstrzymuje.
CREATE TABLE treasure_confirmations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  treasure_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_confirm_once (treasure_id, user_id),
  CONSTRAINT fk_confirm_treasure FOREIGN KEY (treasure_id) REFERENCES treasures (id) ON DELETE CASCADE,
  CONSTRAINT fk_confirm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- REGIONY Z POKRYCIEM HEKSOWYM (migr. 070). Region = wojewodztwo, kazdy heks
-- nalezy do DOKLADNIE jednego regionu (PK blokuje podwojne przypisanie).
-- Materializacja czlonkostwa, nie liczenie w locie — wzorzec known_route_cells:
-- wypelnia go backfill_regions.php z data/wojewodztwa.geojson. Zmiana SIZES_M
-- w Utils\DiscoveryGrid uniewaznia te tabele razem z discovery_cells.
-- „Odkryty region" od migr. 070 = >=1 odkryty heks w regionie
-- (Models\Discovery::regionsForUser); wydarzenia daja punkty, ale regionu
-- juz nie definiuja.
CREATE TABLE region_cells (
  region_item_id INT UNSIGNED NOT NULL,
  cell_id BIGINT NOT NULL,
  PRIMARY KEY (region_item_id, cell_id),
  -- Niezmiennik „jeden heks = jeden region" — złożony PK sam go nie daje.
  UNIQUE KEY uq_rcell_cell (cell_id),
  CONSTRAINT fk_rcell_item FOREIGN KEY (region_item_id) REFERENCES dictionary_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Materializowany mianownik pokrycia (migr. 071): ile heksów ma każdy region.
-- Liczenie w locie = skan 1,5 mln wierszy na każde wejście na stronę
-- (zmierzone). Pusta tabela = model liczy z region_cells (środowiska sprzed
-- importu). Pisarze DWA: backfill_regions.php (import CAŁYCH regionów) i
-- Models\RegionOutline::assignCell() (poprawka pojedynczego pola z
-- /admin/regiony-mapa, 2026-09-10, świadoma decyzja usera) — oba trzymają tę
-- tabelę w zgodzie z region_cells przy KAŻDYM zapisie.
CREATE TABLE region_cell_counts (
  region_item_id INT UNSIGNED NOT NULL,
  cells_total INT UNSIGNED NOT NULL,
  PRIMARY KEY (region_item_id),
  CONSTRAINT fk_rcc_item FOREIGN KEY (region_item_id) REFERENCES dictionary_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WARSTWY MAPY W SŁOWNIKU (migr. 072, Etap 2 — tasks/done/warstwy-mapy.md).
-- Żadnej nowej tabeli: dictionary_items ma juz parent_id + meta JSON. Wiersz
-- mowi CO to za warstwa i GDZIE stoi w drzewie; meta.kind wskazuje renderer
-- w discovery-map.js. meta.contexts.{all,me,rider} niesie stan domyslny i
-- podpis PER KONTEKST (Etap 1b: "Slady" na profilu = slady TEJ osoby, na
-- wspolnej mapie = wszystkich). Dzieci "Skarbow" (treasuresFound/New) sa
-- FILTREM zlozonym z zapalonych dzieci, nie osobna warstwa (patrz kod
-- Models\MapLayer) — pelne uzasadnienie w migration_072_map_layers.sql.
INSERT INTO dictionaries (code, name, description) VALUES
  ('map_layer', 'Warstwa mapy', 'Struktura i konfiguracja kontrolki warstw (Etap 2, warstwy-mapy.md)');

INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, meta) VALUES
  ((SELECT id FROM dictionaries WHERE code='map_layer'), NULL, 'cells', 'Odkrycia', 1, JSON_OBJECT(
     'kind', 'cells', 'contexts', JSON_OBJECT(
       'all',   JSON_OBJECT('on', TRUE, 'hint', 'gdzie bywa społeczność'),
       'me',    JSON_OBJECT('on', TRUE, 'hint', 'gdzie już byłeś'),
       'rider', JSON_OBJECT('on', TRUE, 'hint', 'gdzie już był')))),
  ((SELECT id FROM dictionaries WHERE code='map_layer'), NULL, 'heat', 'Heatmapa', 2, JSON_OBJECT(
     'kind', 'cells', 'contexts', JSON_OBJECT(
       'all',   JSON_OBJECT('on', FALSE, 'hint', 'jak często tędy jeżdżą'),
       'me',    JSON_OBJECT('on', FALSE, 'hint', 'jak często tędy jeździsz'),
       'rider', JSON_OBJECT('on', FALSE, 'hint', 'jak często tędy jeździ')))),
  ((SELECT id FROM dictionaries WHERE code='map_layer'), NULL, 'slady', 'Ślady', 3, JSON_OBJECT(
     'kind', 'tiles', 'contexts', JSON_OBJECT(
       'all',   JSON_OBJECT('on', TRUE, 'source', 'community',       'hint', 'przejechane trasy społeczności'),
       'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-private', 'hint', 'twoje przejechane trasy'),
       'rider', JSON_OBJECT('on', TRUE, 'source', 'subject',         'hint', 'przejechane trasy tej osoby')))),
  ((SELECT id FROM dictionaries WHERE code='map_layer'), NULL, 'trails', 'Znane trasy', 4, JSON_OBJECT(
     'kind', 'tiles', 'contexts', JSON_OBJECT(
       'all',   JSON_OBJECT('on', TRUE, 'source', 'known-routes', 'hint', 'przebiegi szlaków'),
       'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyłeś'),
       'rider', JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyła')))),
  ((SELECT id FROM dictionaries WHERE code='map_layer'), NULL, 'treasures', 'Skarby', 5, JSON_OBJECT(
     'kind', 'markers', 'filterParam', 'stan', 'contexts', JSON_OBJECT(
       'all',   JSON_OBJECT('on', TRUE, 'hint', 'do znalezienia w terenie'),
       'me',    JSON_OBJECT('on', TRUE, 'hint', 'do znalezienia w terenie'),
       'rider', JSON_OBJECT('on', TRUE, 'hint', 'znalezione przez tę osobę'))));

INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, meta) VALUES
  ((SELECT id FROM dictionaries WHERE code='map_layer'),
   (SELECT id FROM dictionary_items WHERE dictionary_id=(SELECT id FROM dictionaries WHERE code='map_layer') AND code='treasures'),
   'treasuresFound', 'Skarby zdobyte', 1, JSON_OBJECT(
     'filterValue', 'moje', 'requiresLogin', TRUE, 'contexts', JSON_OBJECT(
       'all',   JSON_OBJECT('on', TRUE, 'hint', 'te, które już masz'),
       'me',    JSON_OBJECT('on', TRUE, 'hint', 'te, które już masz'),
       'rider', JSON_OBJECT('shown', FALSE)))),
  ((SELECT id FROM dictionaries WHERE code='map_layer'),
   (SELECT id FROM dictionary_items WHERE dictionary_id=(SELECT id FROM dictionaries WHERE code='map_layer') AND code='treasures'),
   'treasuresNew', 'Skarby nieodkryte', 2, JSON_OBJECT(
     'filterValue', 'nowe', 'requiresLogin', TRUE, 'contexts', JSON_OBJECT(
       'all',   JSON_OBJECT('on', TRUE, 'hint', 'jeszcze nieznalezione'),
       'me',    JSON_OBJECT('on', TRUE, 'hint', 'jeszcze nieznalezione'),
       'rider', JSON_OBJECT('shown', FALSE))));

-- REGION JAKO ZBIÓR (migr. 074/075, 2026-08-27) — zastępuje pojedynczy
-- `region_item_id` na events/known_routes. Prośba usera: przy uploadzie tras
-- region ma się wyliczać sam, a event/trasa/przejazd solo mają móc przebiegać
-- przez kilka regionów naraz, nie tylko jeden zadeklarowany ręcznie.
--
-- known_routes: PEŁNA automatyzacja — trasa zawsze ma GPX (wymagane przy
-- uploadzie), więc region to WYŁĄCZNIE `known_route_cells ⋈ region_cells`
-- (Models\KnownRoute::syncRegions, wołane po każdym zapisie/podmianie
-- przebiegu). Formularz stracił pole wyboru regionu.
--
-- events: HYBRYDA, nie czysta automatyzacja — lokalnie tylko 7 z 49 wydarzeń
-- miało w ogóle plik GPX, więc samo wyprowadzenie z geometrii zostawiłoby
-- większość wydarzeń bez regionu (utrata filtrów/dopasowań/powiadomień).
-- `event_regions` to więc SUMA: (1) ręczna deklaracja z formularza (pole
-- "region" zostaje, teraz pisze do tabeli łączącej zamiast do kolumny) i
-- (2) wszystko, co wynika z GPX etapów/wariantów (Models\Event::syncRegions,
-- przez Models\RoutePreview::cellsForGpx — ta sama trasa co "co mi to da").
--
-- rider_activities (przejazdy solo): region z `rider_activity_cells ⋈
-- region_cells`, per przejazd — ta tabela już istniała (migr. 041) jako
-- źródło prawdy dla warstwy heatmapy, więc dociągnięcie regionu jest prostym
-- JOIN-em bez nowego mechanizmu liczenia (RiderActivity::recordTouchedCells).
--
-- treasures.region_item_id ZOSTAJE jako pojedyncza kolumna — skarb to punkt,
-- nie trasa, i normalnie leży w DOKŁADNIE jednym heksie/regionie. Zmienił się
-- tylko sposób jej wyliczenia: Treasure::guessRegion() robi dziś bezpośredni
-- lookup `region_cells` po własnym `cell_id` skarbu, zamiast szukać
-- najbliższej znanej trasy z przypisanym regionem (ta metoda nie miała skąd
-- brać regionu bezpośrednio, zanim istniała `region_cells`).
-- organizer_profiles.region_item_id ZOSTAJE bez zmian — to deklarowany region
-- organizatora jako OSOBY, niezwiązany z żadną trasą/wydarzeniem.
--
-- Backfill (jednorazowy, przed migr. 075): backfill_multi_regions.php.
CREATE TABLE known_route_regions (
  route_id       BIGINT UNSIGNED NOT NULL,
  region_item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (route_id, region_item_id),
  KEY idx_krr_region (region_item_id),
  CONSTRAINT fk_krr_route  FOREIGN KEY (route_id) REFERENCES known_routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_krr_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_regions (
  event_id       BIGINT UNSIGNED NOT NULL,
  region_item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (event_id, region_item_id),
  KEY idx_evr_region (region_item_id),
  CONSTRAINT fk_evr_event  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_evr_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rider_activity_regions (
  activity_id    BIGINT UNSIGNED NOT NULL,
  region_item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (activity_id, region_item_id),
  KEY idx_rar_region (region_item_id),
  CONSTRAINT fk_rar_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_rar_region   FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PUSH DO APKI MOBILNEJ (Etap 8, migr. 077, 2026-08-28). Nazwa CELOWO NIE
-- `user_devices` — koliduje pojęciowo z Models\DeviceConnection (liczniki
-- rowerowe). Zgoda = obecność aktywnego wiersza, nie osobna kolumna;
-- token unikalny GLOBALNIE (identyfikuje zainstalowanie, nie konto).
CREATE TABLE push_devices (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  platform     ENUM('android','ios') NOT NULL,
  token        VARCHAR(255) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_push_token (token),
  KEY idx_push_devices_user (user_id, is_active),
  CONSTRAINT fk_push_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TŁUMACZENIA TREŚCI Z BAZY (migr. 089, 2026-09-16). Tworzone leniwie przy
-- pierwszym wyświetleniu w danym języku; kluczem hash tekstu źródłowego +
-- język docelowy (edycja oryginału = nowy hash = stare tłumaczenie samo
-- przestaje pasować). `context` to podpowiedź, nie klucz. Patrz
-- Models\ContentTranslation.
CREATE TABLE content_translations (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_hash     CHAR(40) NOT NULL,
  target_lang     VARCHAR(5) NOT NULL,
  source_lang     VARCHAR(5) NULL,
  translated_text MEDIUMTEXT NULL,
  origin          ENUM('machine','human','same','pending') NOT NULL,
  source_text     MEDIUMTEXT NULL,
  engine          VARCHAR(32) NULL,
  context         VARCHAR(64) NULL,
  updated_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ct_hash_lang (source_hash, target_lang),
  KEY idx_ct_context (context),
  KEY idx_ct_origin (origin),
  CONSTRAINT fk_ct_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NAZWANE PREFERENCJE ROUTINGU użytkownika (migr. 092, 2026-09-21).
-- Każdy wpis rozszerza istniejący słownik `bike_type`; nie jest drugim
-- systemem profili roweru. NULL w is_default pozwala mieć wiele wpisów
-- niedomyślnych i najwyżej jeden domyślny dla pary user + typ roweru.
CREATE TABLE planner_routing_configs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  bike_type_item_id INT UNSIGNED NOT NULL,
  name              VARCHAR(80) NOT NULL,
  character_code    VARCHAR(16) NOT NULL DEFAULT 'balanced',
  preferences_json  JSON NOT NULL,
  is_default        TINYINT UNSIGNED NULL DEFAULT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_planner_routing_name (user_id, name),
  UNIQUE KEY uq_planner_routing_default (user_id, bike_type_item_id, is_default),
  KEY idx_planner_routing_profile (user_id, bike_type_item_id, updated_at),
  CONSTRAINT fk_planner_routing_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_planner_routing_bike FOREIGN KEY (bike_type_item_id) REFERENCES dictionary_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ROUTE PLANNER, prywatne robocze trasy usera (migr. 090–092, 2026-09-17).
-- `engine`/`profile` = prowenencja wyliczenia (którym silnikiem/dla jakiego
-- roweru), na wypadek przyszłej zmiany silnika routingu.
CREATE TABLE planned_routes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(200) NOT NULL,
  waypoints_json MEDIUMTEXT NOT NULL,
  geometry_json  MEDIUMTEXT NOT NULL,
  distance_km    DECIMAL(7,2) NOT NULL,
  ascent_m       INT UNSIGNED NULL,
  descent_m      INT UNSIGNED NULL,
  duration_min   INT UNSIGNED NULL,
  engine         VARCHAR(20) NOT NULL DEFAULT 'osrm-public',
  profile        VARCHAR(64) NOT NULL DEFAULT 'cycling',   -- kod typu roweru (migr. 091)
  routing_config_id BIGINT UNSIGNED NULL,
  routing_preferences_json JSON NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_planned_routes_user (user_id, updated_at),
  KEY idx_planned_route_routing_config (routing_config_id),
  CONSTRAINT fk_planned_routes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_planned_route_routing_config FOREIGN KEY (routing_config_id) REFERENCES planner_routing_configs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
