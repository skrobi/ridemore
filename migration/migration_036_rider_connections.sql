-- migration_036_rider_connections.sql
--
-- PELETON - relacja "jezdzilismy razem", wyprowadzona z FAKTYCZNEJ
-- wspolnej obecnosci na tym samym turnusie (event_attendance, migr. 035).
-- Nie ma tu zadnego "obserwuj": do czyjegos peletonu wchodzi sie
-- wylacznie wsiadajac na rower i jadac razem.
--
-- Tabela jest ZMATERIALIZOWANYM agregatem, nie zrodlem prawdy. Zrodlem
-- prawdy zostaje event_attendance; te wiersze da sie w kazdej chwili
-- odtworzyc od zera (RiderConnection::recomputeForEdition). Materializacja
-- jest po to, zeby "kto z mojego peletonu jedzie na to wydarzenie" bylo
-- jednym JOIN-em na stronie wydarzenia, a nie samozlaczeniem po calej
-- historii obecnosci przy kazdym wejsciu.
--
-- Para jest KANONICZNA: zawsze user_a_id < user_b_id, jeden wiersz na
-- pare. Dzieki temu relacja jest symetryczna z definicji - u obu osob ta
-- sama liczba wspolnych wyjazdow i ten sam wspolny dystans. To jest
-- roznica wobec Stravy, gdzie wykrywanie wspolnej jazdy po bliskosci GPS
-- bywa asymetryczne (dolaczasz na ostatnie 5 km czyjejs setki i system
-- mowi, ze TY z nim jechales, ale nie ze on z toba).
--
-- shared_km liczone z widoku event_totals (suma dystansu etapow), wiec
-- wydarzenie bez wpisanego dystansu dorzuca 0 - liczba wspolnych
-- wyjazdow jest zawsze prawdziwa, dystans bywa niepelny i tak ma byc
-- prezentowany (nie udajemy licznika kilometrow jak w aplikacji treningowej).

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
