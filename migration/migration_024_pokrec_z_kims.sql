-- migration_024_pokrec_z_kims.sql
-- Trzeci typ wydarzenia: "Pokręcę z kimś" (dict event_type, kod
-- pokrec_z_kims) — luźny wyjazd zgłaszany przez zwykłego rowerzystę, próg
-- wejścia cztery pola (zakres dat, region, tytuł, opis) zamiast pełnego
-- formularza organizatora. Etap 1 specyfikacji, Etap 2 (dopasowania) i
-- Etap 3 (preferencje) świadomie poza zakresem tej migracji.
--
-- Model dat: NIE dokładamy end_date/date_is_flexible/start_time do `events`.
-- Turnusy (migration_023_event_editions.sql) już przeniosły faktyczne źródło
-- prawdy dla terminu pojedynczej instancji wydarzenia do `event_editions`
-- (events.start_date zostaje tylko zdenormalizowanym "najwcześniejszym
-- turnusem", patrz Models\Event::upcoming()). Dla ustawki/wycieczki
-- wielodniowej długość trwania już dziś wynika z event_totals.duration_days
-- (COUNT(event_stages)), a koniec turnusu liczy się jako
-- edition.start_date + (duration_days - 1) — dokładanie tam osobnej kolumny
-- end_date byłoby drugim, konkurencyjnym źródłem tej samej informacji.
-- Dla pokrec_z_kims ta kalkulacja nie działa (zawsze DOKŁADNIE jeden wiersz
-- w event_stages z przyczyn czysto technicznych, patrz Models\Event::save()),
-- więc jego zakres dat trzeba przechować wprost — stąd end_date/
-- date_is_flexible/start_time trafiają na event_editions: to ten sam wiersz,
-- który już i tak jest jedynym źródłem prawdy dla start_date pojedynczej
-- instancji. Odczyt w całej aplikacji: end_date ?? (start_date +
-- duration_days - 1 dzień) — jedna formuła, jedno miejsce.
--
-- mysql -u USER -p ridemorebike2 < migration_024_pokrec_z_kims.sql

SET NAMES utf8mb4;

-- Nowa pozycja w już istniejącym słowniku event_type (ustawka/
-- wycieczka_wielodniowa, patrz schema.sql). Identyfikator słownika
-- rozwiązywany po jego kodzie, nie liczbą — ten sam wzorzec co
-- migration_011_submission_review.sql.
INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code = 'event_type'), 'pokrec_z_kims', 'Pokręcę z kimś', 3);

-- end_date: data zakończenia okna/wyjazdu. NULL dla ustawki/wycieczki
-- wielodniowej (zachowanie bez zmian — patrz komentarz wyżej), wymagana
-- wyłącznie na poziomie aplikacji dla pokrec_z_kims (Models\Event::save()).
-- date_is_flexible: sztywny termin vs okno dostępności — ma znaczenie tylko
-- dla pokrec_z_kims, domyślnie wyłączone (0) dla wszystkiego pozostałego.
-- start_time: godzina rozpoczęcia, dotyczy wszystkich trzech typów (dla
-- wielodniowych = start pierwszego dnia), opcjonalna wszędzie — świadomie
-- BEZ wymagania NOT NULL dla ustawki/wycieczki (poza zakresem tego etapu).
ALTER TABLE event_editions
  ADD COLUMN end_date DATE NULL AFTER start_date,
  ADD COLUMN date_is_flexible TINYINT(1) NOT NULL DEFAULT 0 AFTER end_date,
  ADD COLUMN start_time TIME NULL AFTER date_is_flexible;

-- Pod przyszłe zapytania Etapu 2 (dopasowania po oknie/terminie) — is_cancelled
-- jako odpowiednik "statusu" na poziomie pojedynczego turnusu (events.status_item_id
-- rządzi całym wydarzeniem, ale to end_date/start_date z TEGO wiersza są
-- potrzebne w zapytaniach zakresowych). Zakładany teraz, wykorzystany dopiero
-- w Etapie 2, żeby nie wracać do migracji.
ALTER TABLE event_editions
  ADD INDEX idx_edition_dates (is_cancelled, start_date, end_date);
