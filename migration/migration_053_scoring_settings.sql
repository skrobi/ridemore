-- migration_053_scoring_settings.sql
-- PUNKTACJA WYCHODZI Z PLIKU PHP DO TABELI — admin zmienia ją bez wdrożenia.
--
-- POWÓD (user 2026-08-14): „przenieśmy konfigurację punktową za każde pole
-- i inne rzeczy do tabeli (…) standardowa punktacja do tabeli do edycji admina".
--
-- Dotąd wszystko siedziało w core/discovery.php — pliku, który da się zmienić
-- wyłącznie wdrożeniem. To było dobre, dopóki wartości ustalało się raz przy
-- projektowaniu gry; przestaje być, gdy trzeba je kalibrować na żywym ruchu.
--
-- TABELA NIE ZASTĘPUJE PLIKU, TYLKO GO NADPISUJE
-- ----------------------------------------------
-- Plik zostaje jako ŹRÓDŁO WARTOŚCI DOMYŚLNYCH i — co ważniejsze —
-- uzasadnień: to tam stoi sprawdzian proporcji („trasa 180 km daje ok. 1800 pkt
-- Discovery i 700 pkt Trails, ścieżka odkrywcza ma zostać silniejsza"), którego
-- nie da się zapisać w kolumnie INT. Tabela trzyma wyłącznie te klucze, które
-- admin realnie zmienił.
--
-- Skutki tej decyzji, wszystkie pożądane:
--   * pusta tabela = zachowanie identyczne jak przed migracją,
--   * skasowanie wiersza = powrót do wartości domyślnej, bez znajomości liczby,
--   * nowy klucz dodany w pliku działa od razu, bez migracji.
--
-- KLUCZ JEST ŚCIEŻKĄ W KONFIGURACJI: 'discovery.cell_points',
-- 'trails.thresholds.100'. Płaska tabela zamiast kolumny na każdy parametr,
-- bo parametrów jest kilkanaście i będą przybywać — kolumna na każdy znaczyłaby
-- migrację przy każdym nowym.
--
-- HISTORII PUNKTÓW TO NIE RUSZA. Rejestr (point_transactions, migr. 043) jest
-- niezmienny: zmiana stawki działa na przyszłość, nigdy wstecz. Ta zasada jest
-- starsza od tej migracji i migracja jej nie podważa.

CREATE TABLE scoring_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  -- DECIMAL, nie INT: część stawek jest ułamkowa (punkty za kilometr, mnożniki
  -- przewyższenia), a trzymanie ich jako tekstu wymagałoby rzutowania przy
  -- każdym odczycie i otwierało drogę do wpisania tam czegokolwiek.
  setting_value DECIMAL(12,4) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Kto zmienił — punktacja jest regułą gry, więc zmiana stawki jest decyzją,
  -- a nie ustawieniem. Przy dwóch adminach bez tego nie da się odtworzyć, skąd
  -- się wzięła bieżąca wartość.
  updated_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_scoring_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
