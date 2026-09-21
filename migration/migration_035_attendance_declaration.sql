-- migration_035_attendance_declaration.sql
--
-- "Bylem" - potwierdzenie FAKTYCZNEJ obecnosci na wyjezdzie.
-- Fundament pod Peleton i Kronike: relacja "jezdzilismy razem" ma
-- wynikac z tego, ze ktos REALNIE pojechal, a nie z samego zapisu
-- (ludzie zapisuja sie i nie przyjezdzaja).
--
-- Tabela event_attendance ISTNIEJE od poczatku projektu (schema.sql,
-- sekcja 7) z mysla o weryfikacji GPS: gps_verified, verified_at,
-- tracked_distance_km, source_item_id (owntracks/strava/manual).
-- Nigdy jednak nie miala sciezki ZAPISU - jedyne miejsce, ktore jej
-- dotykalo, to DerivedPreference.php, czyli odczyt z zawsze pustej
-- tabeli. Ta migracja dokladana brakujace kolumny deklaracji i tym
-- samym uruchamia tabele. Kolumny GPS zostaja nietkniete na pozniej:
-- weryfikacja sladem to osobny temat, a na start wystarczy deklaracja
-- uczestnika plus zatwierdzenie organizatora - nagroda za oszustwo
-- jest tu zbyt niska, zeby sie oplacalo.
--
-- rsvp_id jest juz UNIQUE, wiec BRAK WIERSZA = "jeszcze nie
-- odpowiedzial", a attended = FALSE = "odpowiedzial, ze nie dojechal".
-- To rozroznienie jest istotne: brak deklaracji NIE moze byc liczony
-- jako nieobecnosc, inaczej peleton gubilby ludzi, ktorzy po prostu
-- nie kliknely.
--
-- declared_by_user_id celowo osobno od rsvp.user_id - obecnosc moze
-- zaznaczyc sam uczestnik (z maila po wydarzeniu albo ze strony
-- wydarzenia) ALBO organizator z listy uczestnikow.
-- confirmed_by_organizer odroznia deklaracje wlasna od potwierdzonej
-- przez prowadzacego (silniejszy sygnal przy spornych przypadkach).

ALTER TABLE event_attendance
  ADD COLUMN attended               BOOLEAN NOT NULL DEFAULT TRUE AFTER rsvp_id,
  ADD COLUMN declared_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attended,
  ADD COLUMN declared_by_user_id    BIGINT UNSIGNED NULL AFTER declared_at,
  ADD COLUMN confirmed_by_organizer BOOLEAN NOT NULL DEFAULT FALSE AFTER declared_by_user_id,
  ADD CONSTRAINT fk_att_declared_by FOREIGN KEY (declared_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_att_attended ON event_attendance (attended);
