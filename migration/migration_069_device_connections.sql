-- migration_069_device_connections.sql
-- LICZNIKI, NIE TYLKO GARMIN (2026-08-23, decyzja usera: „ok zatem dodaj"
-- — Upload/Garmin/Polar/Wahoo/Coros/Suunto jako zakladki).
--
-- Migracja 068 zbudowala `garmin_connections` i `garmin_activities` pod JEDEN
-- serwis. Przy drugim dostawcy nazwy zaczely by klamac (token Polara w tabeli
-- „garmin"), a kolumny i tak sa te same: kto, z czym polaczony, ktory token,
-- co juz sciagnelismy. Dlatego uogolnienie, a nie druga para tabel: jeden
-- rejestr „to juz mamy" dziala wtedy dla wszystkich zrodel naraz.
--
-- KOPIUJEMY DANE I KASUJEMY STARE TABELE. Migracja 068 ma jeden dzien, nie
-- byla nigdy uruchomiona na produkcji (patrz md/database.md), a `run_migrations`
-- wykonuje pliki po kolei — wiec w kazdym srodowisku 068 istnieje, zanim
-- dojdzie tutaj. Zostawienie starych tabel obok nowych byloby gorsze niz ich
-- skasowanie: dwie prawdy o tym samym to gwarantowany blad przy nastepnej zmianie.
--
-- CO SIE ZMIENIA POZA NAZWA:
--   * `provider` — 'garmin' | 'polar' | 'wahoo' | 'coros' | 'suunto'. Klucze
--     unikalne ida na PARE (user_id, provider): jedna osoba moze miec podpiete
--     dwa liczniki naraz i to jest normalny przypadek, nie wyjatek.
--   * `activity_id` jako VARCHAR, nie BIGINT — Garmin numeruje aktywnosci
--     liczba, ale Polar oddaje ID zahaszowane tekstem. Rejestr musi umiec
--     zapisac jedno i drugie.
--   * `external_user_id` — Polar wymaga zarejestrowania uzytkownika u siebie
--     (POST /v3/users) i pozniej adresuje go WLASNYM identyfikatorem; bez
--     zapamietania go kazde kolejne pobranie zaczynaloby od rejestracji.
--   * `token_cipher` trzyma teraz CALY komplet OAuth (access + refresh + czas
--     waznosci) jako zaszyfrowany JSON — dlatego bez nowych kolumn: to jest
--     jeden sekret, nie trzy, i ma zyc i ginac razem.
--
-- Hasel dalej nigdzie tu nie ma. Garmin (logowanie haslem przez most Pythona)
-- zostawia token sesji, OAuth zostawia token dostepu — w obu przypadkach
-- zaszyfrowany AES-256-GCM kluczem z konfiguracji.

CREATE TABLE IF NOT EXISTS device_connections (
    user_id          BIGINT UNSIGNED NOT NULL,
    provider         VARCHAR(20)     NOT NULL,
    account_label    VARCHAR(190)    NULL,       -- e-mail albo nazwa konta u dostawcy
    external_user_id VARCHAR(64)     NULL,       -- id nadane przez dostawce (Polar)
    token_cipher     TEXT            NOT NULL,   -- base64(iv || tag || ciphertext)
    display_name     VARCHAR(190)    NULL,
    connected_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sync_at     DATETIME        NULL,
    PRIMARY KEY (user_id, provider),
    CONSTRAINT fk_devconn_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_activities (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    provider          VARCHAR(20)     NOT NULL,
    activity_id       VARCHAR(64)     NOT NULL,
    -- 'imported' | 'duplicate' | 'rejected' — patrz nota w migracji 068:
    -- rejestr zapisuje TAKZE to, czego nie udalo sie policzyc, inaczej te same
    -- aktywnosci wracalyby jako „nowe" po kazdym odswiezeniu.
    status            VARCHAR(20)     NOT NULL,
    rider_activity_id BIGINT UNSIGNED NULL,
    activity_name     VARCHAR(190)    NULL,
    started_at        DATETIME        NULL,
    distance_km       DECIMAL(7,2)    NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devact (user_id, provider, activity_id),
    KEY idx_devact_user (user_id, created_at),
    CONSTRAINT fk_devact_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    -- ON DELETE SET NULL, nie CASCADE: skasowanie przejazdu nie moze skasowac
    -- wpisu rejestru, bo aktywnosc wrocilaby jako „nowa" i dala sie policzyc
    -- drugi raz.
    CONSTRAINT fk_devact_ride FOREIGN KEY (rider_activity_id)
        REFERENCES rider_activities (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Przeniesienie tego, co zdazylo powstac pod migracja 068.
INSERT IGNORE INTO device_connections
    (user_id, provider, account_label, token_cipher, display_name, connected_at, last_sync_at)
SELECT user_id, 'garmin', garmin_email, token_cipher, display_name, connected_at, last_sync_at
  FROM garmin_connections;

INSERT IGNORE INTO device_activities
    (user_id, provider, activity_id, status, rider_activity_id, activity_name, started_at, distance_km, created_at)
SELECT user_id, 'garmin', CAST(activity_id AS CHAR), status, rider_activity_id,
       activity_name, started_at, distance_km, created_at
  FROM garmin_activities;

DROP TABLE IF EXISTS garmin_activities;
DROP TABLE IF EXISTS garmin_connections;
