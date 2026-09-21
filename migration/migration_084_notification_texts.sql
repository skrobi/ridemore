-- migration_084_notification_texts.sql
-- TREŚCI POWIADOMIEŃ DO EDYCJI W PANELU (2026-09-11).
--
-- ============================================================================
-- PO CO OSOBNA TABELA, SKORO JEST `notification_settings`
-- ============================================================================
-- Tamta trzyma `setting_value DECIMAL(12,4) NOT NULL` — liczby. Dopchnięcie
-- tekstu wymagałoby dodania kolumny, która w 17 istniejących wierszach byłaby
-- zawsze pusta, i poluzowania `NOT NULL` na kolumnie liczbowej, czyli zepsucia
-- gwarancji, która dziś działa. Dwie tabele o tym samym wzorcu i różnych typach
-- wartości są tańsze niż jedna z dwiema połowami, z których zawsze jedna kłamie.
--
-- Wzorzec jest ten sam co w migr. 053 i 083: kod trzyma treści domyślne,
-- tabela wyłącznie te, które admin realnie zmienił. Pusta tabela = teksty
-- dokładnie takie, jakie wychodziły przed tą migracją.
--
-- ============================================================================
-- DLACZEGO TO JEST BEZPIECZNE, CHOĆ TREŚĆ IDZIE Z BAZY
-- ============================================================================
-- Powiadomienie o skarbie NIE MOŻE zdradzić nazwy skarbu, którego odbiorca
-- jeszcze nie odkrył (`Treasure::reveal()`, poziom ujawnienia). Gdyby istniał
-- JEDEN edytowalny tekst z opcjonalnym znacznikiem `{nazwa}`, admin mógłby
-- wpisać go w wariant dla nieodkrytych i wyciek byłby gotowy — cicho, bo nikt
-- by tego nie zauważył poza osobą, która dostała podpowiedź.
--
-- Dlatego treść jest rozbita na WARIANTY PER POZIOM UJAWNIENIA, a każdy wariant
-- ma WŁASNĄ BIAŁĄ LISTĘ ZNACZNIKÓW (`Models\NotificationTexts::TEKSTY`).
-- W wariancie dla skarbu ukrytego znacznika `{nazwa}` po prostu NIE MA na
-- liście — wpisany ręcznie zostanie usunięty przy zapisie i nie podstawiłby się
-- przy wysyłce. To jest zabezpieczenie w dwóch miejscach naraz, bo pierwsze
-- z nich (zapis) da się ominąć, wchodząc do bazy wprost.
--
-- `text_value TEXT` bez limitu długości w kolumnie — limit jest w modelu
-- (`MAX_DLUGOSC`), bo różni się per pole: tytuł pusha ma inne ograniczenia niż
-- akapit maila, a systemy powiadomień i tak przycinają zbyt długie teksty.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_084_notification_texts.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notification_texts (
  text_key VARCHAR(64) NOT NULL PRIMARY KEY,
  text_value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Kto zmienił treść. Powiadomienie idzie do ludzi w imieniu serwisu, więc
  -- zmiana jego brzmienia jest decyzją redakcyjną, a nie ustawieniem.
  updated_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_notif_texts_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
