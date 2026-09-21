-- migration_049_solo_rides.sql
-- PRZEJAZD SOLO — ślad bez wydarzenia (Etap 8A/11).
--
-- Do tej pory każdy przejazd musiał wisieć na turnusie: `rider_activities`
-- powstawało wyłącznie z potwierdzonej obecności na wyjeździe. To znaczyło, że
-- codzienna runda po okolicy — czyli WIĘKSZOŚĆ tego, co ludzie realnie jeżdżą —
-- nie istniała dla mapy odkryć. Decyzja usera z 2026-08-12: „to dorzućmy
-- możliwość przejazdów solo".
--
-- Tabela ZOSTAJE TA SAMA. `rider_activities` było od początku pomyślane jako
-- PRZEJAZD jako byt abstrakcyjny ze swapowalnym `source_code` — solo to po
-- prostu kolejne źródło ('solo'), a nie nowy rodzaj bytu. Osobna tabela
-- oznaczałaby dwie ścieżki naliczania punktów i dwa miejsca do zapomnienia
-- przy każdej kolejnej zmianie.
--
-- IDEMPOTENCJA: `gpx_hash` + UNIQUE(user_id, gpx_hash). Ten sam plik wgrany
-- drugi raz odbija się o klucz, zamiast naliczyć punkty jeszcze raz — ta sama
-- zasada co wszędzie w tym module: niezmiennik bazy, nie warunek w kodzie.
-- Hash liczony z ZAWARTOŚCI pliku, nie z nazwy.
--
-- `started_at` i `moving_seconds` pochodzą ze znaczników czasu w GPX
-- (Utils\Gpx::parse od 8A/10). Punktacja ich NIE UŻYWA i nie ma używać —
-- core/discovery.php nie zna ani tempa, ani czasu. Są tu po to, żeby przejazd
-- solo miał kiedy się odbyć (ride_date bez wydarzenia nie ma skąd wziąć daty)
-- i żeby dało się odróżnić zapis z licznika od trasy narysowanej w planerze.

ALTER TABLE rider_activities
    ADD COLUMN gpx_url VARCHAR(500) NULL AFTER rsvp_id,
    ADD COLUMN gpx_hash CHAR(64) NULL AFTER gpx_url,
    ADD COLUMN started_at DATETIME NULL AFTER ride_date,
    ADD COLUMN moving_seconds INT UNSIGNED NULL AFTER started_at;

-- Klucz na PARZE, nie na samym hashu: dwie osoby mogą uczciwie wgrać ten sam
-- plik (wspólny wyjazd, jeden licznik na dwoje) i obie mają prawo do swoich pól.
-- Powtórka blokowana jest w obrębie JEDNEGO konta.
CREATE UNIQUE INDEX idx_ra_user_gpx ON rider_activities (user_id, gpx_hash);
