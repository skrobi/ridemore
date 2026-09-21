-- migration_068_garmin_connect.sql
-- IMPORT PRZEJAZDOW Z GARMIN CONNECT (zgloszenie usera 2026-08-23:
-- „jak dodac wgrywanie solo tras z Garmin").
--
-- KONTEKST DECYZJI. Garmin nie ma publicznego API dla kont osobistych
-- (Connect Developer Program to program partnerski dla firm), a Strava od
-- 11.11.2024 ZABRANIA pokazywania danych uzytkownika komukolwiek poza nim
-- samym — czyli wyklucza mape odkryc, peleton i kronike, czyli rdzen tego
-- serwisu. Zostaje logowanie jak w aplikacji mobilnej (biblioteka
-- `garminconnect`, patrz ai-engine/garmin.py). Jest NIEOFICJALNE i moze
-- przestac dzialac po zmianie po stronie Garmina — dlatego caly ten mechanizm
-- jest DODATKIEM do wgrywania plikow, nigdy jedyna droga.
--
-- DWIE TABELE, BO TO DWIE ROZNE ODPOWIEDZIALNOSCI:
--
-- 1. `garmin_connections` — POLACZENIE konta. Jeden wiersz na uzytkownika.
--    HASLA TU NIE MA I NIGDY NIE BEDZIE: przechowujemy wylacznie token sesji
--    zwrocony przez Garmina, zaszyfrowany AES-256-GCM kluczem z konfiguracji
--    (patrz Models\GarminAccount). Wyciek samej bazy nie daje wiec dostepu do
--    czyjegokolwiek konta Garmin, a haslo nie istnieje w zadnej formie —
--    przechodzi przez pamiec procesu Pythona przy logowaniu i znika.
--
-- 2. `garmin_activities` — REJESTR TEGO, CO JUZ WIDZIELISMY. To jest
--    odpowiedz na „sprawdzaj czy juz takich nie ma": po pobraniu listy
--    z Garmina odsiewamy identyfikatory, ktore tu sa, wiec na liscie
--    pokazuja sie WYLACZNIE nowe przejazdy.
--
--    DLACZEGO OSOBNA TABELA, A NIE KOLUMNA W `rider_activities`. Bo wiersz
--    musi powstac takze wtedy, gdy przejazd NIE zostal dodany: duplikat
--    (ten sam slad wgrany wczesniej recznie — odbija sie o UNIQUE
--    (user_id, gpx_hash)) albo odrzucenie (aktywnosc bez sladu GPS, np. z
--    trenazera). Bez tego rekordu takie aktywnosci wracalyby na liste „nowych"
--    po kazdym odswiezeniu, w kolko, i nie dalo by sie ich nigdy zamknac.
--
-- Idempotentna jak pozostale migracje (patrz md/database.md).

CREATE TABLE IF NOT EXISTS garmin_connections (
    user_id       BIGINT UNSIGNED NOT NULL,
    garmin_email  VARCHAR(190)    NOT NULL,
    -- base64(iv || tag || ciphertext) — patrz Models\GarminAccount::encrypt().
    token_cipher  TEXT            NOT NULL,
    display_name  VARCHAR(190)    NULL,
    connected_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sync_at  DATETIME        NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_garmin_conn_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS garmin_activities (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    -- Identyfikator aktywnosci z Garmina. Trzymany jako BIGINT, bo taki jest
    -- u zrodla; klucz unikalny stoi na PARZE (user_id, activity_id), nie na
    -- samym identyfikatorze — dwie osoby moga miec ten sam przejazd na swoich
    -- kontach Garmina tylko wtedy, gdy kazda ma go u siebie, i obie maja do
    -- niego prawo (ta sama zasada co przy idx_ra_user_gpx).
    activity_id       BIGINT UNSIGNED NOT NULL,
    -- 'imported'  — przejazd powstal, `rider_activity_id` wskazuje ktory
    -- 'duplicate' — ten slad byl juz policzony (odbicie o gpx_hash)
    -- 'rejected'  — Garmin oddal plik, ale nie da sie z niego zrobic przejazdu
    status            VARCHAR(20)     NOT NULL,
    rider_activity_id BIGINT UNSIGNED NULL,
    activity_name     VARCHAR(190)    NULL,
    started_at        DATETIME        NULL,
    distance_km       DECIMAL(7,2)    NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_garmin_user_activity (user_id, activity_id),
    KEY idx_garmin_act_user (user_id, created_at),
    CONSTRAINT fk_garmin_act_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    -- Skasowanie przejazdu (np. czyszczenie mapy odkryc) NIE moze skasowac
    -- wiersza rejestru — inaczej ta sama aktywnosc wrocilaby jako „nowa"
    -- i dalo by sie ja policzyc drugi raz. Zostaje wpis bez wskazania.
    CONSTRAINT fk_garmin_act_ride FOREIGN KEY (rider_activity_id)
        REFERENCES rider_activities (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
