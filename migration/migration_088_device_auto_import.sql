-- migration_088_device_auto_import.sql
-- AUTOMATYCZNY IMPORT Z LICZNIKA PRZEZ WEBHOOKI (2026-09-14).
--
-- Zgłoszenie usera: „mając podpięty Garmin, czy jest możliwość, aby z automatu
-- pobierały się trasy z Garmina, Polara i innych?". Decyzje usera z tej rozmowy:
--   * TYLKO OFICJALNE KANAŁY — Polar AccessLink i Wahoo Cloud API mają
--     webhooki („nowy trening"). Garmin bez programu partnerskiego ich nie ma,
--     a odpytywanie go z crona nieoficjalną biblioteką zostało odrzucone.
--   * AUTOMAT Z PRZEŁĄCZNIKIEM, DOMYŚLNIE WYŁĄCZONY — nie „skrzynka do
--     akceptacji" i nie „podłączenie = zgoda". Każdy ślad to punkty i publiczne
--     pola na mapie odkryć, więc wciąganie przejazdów bez pytania musi być
--     świadomą decyzją człowieka, podjętą raz przy połączonym koncie.
--   * POWIADOMIENIE PUSH + MAIL o przejeździe dodanym automatem — przez bramkę
--     powiadomień, z własną zgodą per kanał.
--
-- CO TA MIGRACJA DODAJE:
--
-- 1. `device_connections.auto_import` — przełącznik per para (użytkownik,
--    dostawca). DEFAULT 0: istniejące połączenia NIE zaczynają nagle wciągać
--    przejazdów po wdrożeniu. Odłączenie konta kasuje wiersz, więc ponowne
--    podłączenie zaczyna znowu od „wyłączone" — i to jest zamierzone.
--
-- 2. Indeks `(provider, external_user_id)` — webhook nie mówi, KTO z naszych
--    użytkowników to jest, tylko podaje identyfikator nadany przez dostawcę
--    (Polar `user_id`, Wahoo `user.id`). Bez indeksu każde powiadomienie
--    przeglądałoby całą tabelę połączeń.
--
-- 3. `user_preferences.push_rides` / `mail_rides` — zgody na nowy typ
--    powiadomienia (`NotificationGate::PRZEJAZD_Z_LICZNIKA`). DEFAULT 1, tak jak
--    pozostałe zgody z migr. 081/082: brak wiersza znaczy w tym systemie „nie
--    dotykałem ustawień", a do powiadomienia i tak dochodzi wyłącznie ktoś, kto
--    SAM włączył automat. Osobne flagi, a nie `*_messages`: wiadomość od
--    człowieka i raport o własnym przejeździe to różne decyzje — wyłączenie
--    jednego nie może zgasić drugiego.
--
-- `migration/schema.sql` nie zawiera dziś ani `device_connections`, ani
-- `user_preferences` z kolumnami powiadomień (migr. 069, 081–084 też tam nie
-- trafiły), więc nie ma czego tam dopisać bez odtwarzania całych tabel.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_088_device_auto_import.sql

SET NAMES utf8mb4;

ALTER TABLE device_connections
  ADD COLUMN auto_import TINYINT(1) NOT NULL DEFAULT 0 AFTER external_user_id,
  ADD KEY idx_devconn_external (provider, external_user_id);

ALTER TABLE user_preferences
  ADD COLUMN push_rides TINYINT(1) NOT NULL DEFAULT 1 AFTER mail_progress,
  ADD COLUMN mail_rides TINYINT(1) NOT NULL DEFAULT 1 AFTER push_rides;
