-- migration_054_stickers.sql
-- WLEPKI — poszukiwanie skarbów w terenie (moduł „tyry").
--
-- POMYSŁ (user 2026-08-14): w konkretnych miejscach na trasach wisi naklejka
-- z kodem QR; kto ją znajdzie i zeskanuje, dostaje dodatkowe punkty. Wlepka ma
-- dokładną lokalizację, opis i kategorię („punkt widokowy" itd.).
--
-- DLACZEGO TO NIE JEST KOLEJNA WARSTWA DISCOVERY
-- ----------------------------------------------
-- Pole Discovery ma ok. 500 m i zalicza się SAMO, ze śladu GPS — nagradza
-- przejechanie terenu. Wlepka jest przeciwieństwem: ma współrzędne z dokładnością
-- do metrów, wymaga ZATRZYMANIA SIĘ, zejścia z roweru i rozejrzenia. To dwie
-- różne czynności i dlatego dwie różne tabele, mimo że jedna i druga kończy się
-- punktami.
--
-- Hex jest tu WYŁĄCZNIE indeksem do rysowania (kolumna cell_id, liczona przy
-- zapisie jak wszędzie indziej) — mapa ma pokazać ikonkę w polu, ale sama
-- lokalizacja nigdy nie jest zaokrąglana do pola.
--
-- KOD JEST SEKRETEM, NIE IDENTYFIKATOREM
-- --------------------------------------
-- W adresie QR siedzi `code`, a nie `id`. Dwa powody, oba istotne:
--   1. po id dałoby się zgadnąć wszystkie wlepki, wpisując kolejne liczby —
--      czyli zebrać całą grę bez wyjścia z domu,
--   2. kod jest ODDZIELNY od wiersza, więc przy wycieku (ktoś opublikuje zdjęcie
--      naklejki w internecie) wymienia się sam kod, zostawiając historię zaliczeń.
--
-- Zabezpieczenie NIE JEST doskonałe i nie udajemy, że jest: zdjęcie kodu da się
-- wysłać znajomemu. Dlatego zaliczenie sprawdza dodatkowo POŁOŻENIE
-- skanującego (patrz Models\Sticker::claim) — GPS też da się oszukać, ale
-- trzeba już chcieć, a nagroda jest niewielka.

CREATE TABLE stickers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  -- Sekret z adresu QR. Osobny UNIQUE, żeby dało się go wymienić bez ruszania id.
  code VARCHAR(32) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  -- Kategoria ze słownika ('sticker_category': punkt widokowy, przełęcz,
  -- schronisko…). Słownik, nie ENUM — kategorie będą przybywać, a w tym
  -- projekcie każda taka lista żyje w dictionary_items.
  category_item_id INT UNSIGNED NULL,
  region_item_id INT UNSIGNED NULL,
  -- LOKALIZACJA DOKŁADNA. DECIMAL(10,7) to ok. 1 cm — z zapasem ponad to,
  -- co potrafi telefon, ale float dałby błędy zaokrągleń przy porównywaniu
  -- odległości, a to jest tu warunek przyznania punktów.
  lat DECIMAL(10,7) NOT NULL,
  lon DECIMAL(10,7) NOT NULL,
  -- Pole siatki Discovery — WYŁĄCZNIE po to, żeby mapa mogła narysować ikonkę
  -- w polu bez liczenia tego przy każdym żądaniu. Liczone przy zapisie, tak jak
  -- parent_res* w discovery_cell_totals (migr. 047).
  cell_id BIGINT NULL,
  points INT UNSIGNED NOT NULL DEFAULT 50,
  -- Promień, w jakim trzeba stać, żeby zaliczyć. Per wlepka, bo punkt widokowy
  -- na przełęczy to co innego niż tabliczka przy ścieżce w lesie.
  claim_radius_m SMALLINT UNSIGNED NOT NULL DEFAULT 150,
  photo_url VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  -- Wlepka bywa sezonowa (zdejmowana na zimę) albo postawiona na czas imprezy.
  active_from DATE NULL,
  active_to DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sticker_code (code),
  KEY idx_sticker_cell (cell_id),
  KEY idx_sticker_active (is_active),
  CONSTRAINT fk_sticker_category FOREIGN KEY (category_item_id) REFERENCES dictionary_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_sticker_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_sticker_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ZALICZENIA. Klucz UNIQUE(sticker_id, user_id) jest tu MECHANIZMEM, nie
-- ozdobą: to on gwarantuje, że tej samej wlepki nie da się zaliczyć dwa razy,
-- także przy dwóch równoczesnych żądaniach. Ta sama zasada co w całym module
-- punktów — idempotencja jest własnością BAZY, nigdy sprawdzeniem w kodzie
-- („czy już mam?" + INSERT to wyścig, nie zabezpieczenie).
CREATE TABLE sticker_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sticker_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  -- Gdzie stał skanujący. Zapisujemy, bo to jedyny ślad pozwalający później
  -- ocenić, czy ktoś zalicza wlepki z kanapy — dane do decyzji, nie do kary.
  claimed_lat DECIMAL(10,7) NULL,
  claimed_lon DECIMAL(10,7) NULL,
  distance_m INT UNSIGNED NULL,
  points_awarded INT UNSIGNED NOT NULL DEFAULT 0,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_claim_once (sticker_id, user_id),
  KEY idx_claim_user (user_id),
  CONSTRAINT fk_claim_sticker FOREIGN KEY (sticker_id) REFERENCES stickers (id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kategorie wlepek jako słownik — dokładnie tak, jak regiony i typy wydarzeń.
INSERT INTO dictionaries (code, name) VALUES ('sticker_category', 'Kategoria wlepki');
INSERT INTO dictionary_items (dictionary_id, code, name, sort_order) VALUES
  ((SELECT id FROM dictionaries WHERE code='sticker_category'), 'punkt_widokowy', 'Punkt widokowy', 1),
  ((SELECT id FROM dictionaries WHERE code='sticker_category'), 'przelecz', 'Przełęcz', 2),
  ((SELECT id FROM dictionaries WHERE code='sticker_category'), 'schronisko', 'Schronisko / bacówka', 3),
  ((SELECT id FROM dictionaries WHERE code='sticker_category'), 'zrodlo', 'Źródło / woda', 4),
  ((SELECT id FROM dictionaries WHERE code='sticker_category'), 'zabytek', 'Zabytek', 5),
  ((SELECT id FROM dictionaries WHERE code='sticker_category'), 'ciekawostka', 'Ciekawostka', 6),
  ((SELECT id FROM dictionaries WHERE code='sticker_category'), 'serwis', 'Serwis / kawiarnia rowerowa', 7);
