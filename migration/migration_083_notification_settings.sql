-- migration_083_notification_settings.sql
-- USTAWIENIA POWIADOMIEŃ WYCHODZĄ ZE STAŁYCH DO TABELI (2026-09-11).
--
-- ============================================================================
-- PO CO
-- ============================================================================
-- Zgłoszenie usera: „czy jest gdzieś miejsce do zarządzania powiadomieniami —
-- jakie, w jakim czasie, jak często". Nie było. Budżet (2/tydzień, 1/dobę),
-- cisza nocna (22:00–6:59), okno świeżości i sam fakt, że dany typ w ogóle
-- wychodzi, siedziały w stałych `Models\NotificationGate` — czyli zmiana
-- częstotliwości wymagała wdrożenia, a zmiana PORY wysyłki nawet nie tego,
-- tylko dostępu do crontaba na serwerze.
--
-- ============================================================================
-- TEN SAM WZORZEC CO `scoring_settings` (migr. 053), CELOWO
-- ============================================================================
-- Kod zostaje ŹRÓDŁEM WARTOŚCI DOMYŚLNYCH i uzasadnień (w `NotificationGate`
-- stoi wyliczenie, skąd wzięły się akurat 2 zachęty tygodniowo — tego nie da
-- się zapisać w kolumnie), a tabela trzyma WYŁĄCZNIE klucze realnie zmienione.
-- Wynikają z tego trzy własności, na których zależy najbardziej:
--   * pusta tabela = zachowanie identyczne jak przed migracją,
--   * skasowanie wiersza = powrót do domyślnej BEZ znajomości liczby,
--   * nowy parametr dodany w kodzie działa od razu, bez migracji.
--
-- DLACZEGO OSOBNA TABELA, A NIE WIERSZE `notifications.*` W `scoring_settings`:
-- tamta nazywa się „ustawienia punktacji" i to jest część jej dokumentacji.
-- Punktacja jest regułą GRY, powiadomienia są regułą KOMUNIKACJI — wspólny
-- jest tylko mechanizm klucz→wartość-z-nadpisaniem, nie domena. Scalenie obu
-- pod jedną, ogólną nazwą wymagałoby ruszenia działającej punktacji, czego to
-- zadanie nie potrzebuje. Kształt kolumn jest identyczny świadomie: gdyby
-- kiedyś powstała jedna tabela ustawień aplikacji, przeniesienie to `INSERT
-- … SELECT`, nie przepisywanie.
--
-- `setting_value DECIMAL(12,4)` mimo że wszystkie dzisiejsze parametry są
-- całkowite (godziny, sztuki, przełączniki 0/1) — trzymamy kształt kolumny
-- zgodny z `scoring_settings`, a liczba ułamkowa nie zaszkodzi żadnemu
-- z odczytów (kod rzutuje na int tam, gdzie to ma znaczenie).
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_083_notification_settings.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notification_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value DECIMAL(12,4) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Kto zmienił. Wyłączenie całego rodzaju powiadomień albo zwężenie okna
  -- wysyłki to decyzja, nie ustawienie — przy dwóch adminach bez tego nie da
  -- się odtworzyć, dlaczego od wtorku nic nie wychodzi.
  updated_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_notif_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
