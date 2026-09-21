-- migration_055_treasures.sql
-- WLEPKI STAJĄ SIĘ SKARBAMI — nazewnictwo i pola pod pełny model (Etap 8D).
--
-- POWÓD: migr. 054 nazwała to „wlepkami", bo tak brzmiał pierwszy opis. Docelowy
-- model (user, 2026-08-14) jest szerszy: wlepka to tylko JEDEN ze sposobów
-- potwierdzenia, a sam byt to SKARB — ma rzadkość, pochodzenie, status i poziom
-- ujawnienia. Nazwa „sticker" opisywałaby kartkę papieru, a nie to, co niesie.
--
-- ROBIMY TO TERAZ, BO JEST ZA DARMO. W tabelach nie ma ani jednego wiersza
-- produkcyjnego. Po pilotażu (10–20 skarbów w Mielcu, Bieszczadach, Czorsztynie)
-- ta sama zmiana znaczyłaby migrację danych, przepisanie source w rejestrze
-- punktów i poprawianie adresów, które zdążyły komuś wylądować w zakładkach.
--
-- CO Z TEGO ZOSTAJE NA PÓŹNIEJ: same tabele i pola. Interfejsu, generowania
-- kodów QR ani mechaniki ujawniania (ukryty → trop → znaleziony) TA migracja nie
-- dotyczy — to jest Etap 8D. Tutaj chodzi o to, żeby silnik punktów i schemat
-- były gotowe, zanim zacznie się ich używać.

RENAME TABLE stickers TO treasures;
RENAME TABLE sticker_claims TO treasure_finds;

-- POCHODZENIE — kto skarb postawił. To nie jest to samo co `created_by`
-- (konkretne konto): tu chodzi o RANGĘ, która decyduje, jak skarb wygląda na
-- mapie i czy w ogóle wymaga zatwierdzenia.
ALTER TABLE treasures
  ADD COLUMN origin ENUM('OFFICIAL','ORGANIZER','PARTNER','COMMUNITY')
      NOT NULL DEFAULT 'COMMUNITY' AFTER description;

-- RZADKOŚĆ — nadaje mapie „życie" (383 hexy, 12 tras, 7 skarbów, w tym 2 rare
-- i 1 legendary). Wartości punktowe zostają w kolumnie `points`, bo rzadkość
-- jest ETYKIETĄ, a nie wzorem: skarb legendarny w płaskim terenie może być wart
-- mniej niż epicki na przełęczy.
ALTER TABLE treasures
  ADD COLUMN rarity ENUM('COMMON','RARE','EPIC','LEGENDARY')
      NOT NULL DEFAULT 'COMMON' AFTER origin;

-- STATUS — skarb społecznościowy NIE trafia od razu na mapę.
--   PROPOSED — zgłoszony, czeka na potwierdzenia albo na admina,
--   ACTIVE   — widoczny i do zdobycia,
--   RETIRED  — zdjęty (naklejka zniknęła, miejsce przestało istnieć).
-- Skasowanie byłoby złe: znalezienia mają zostać w historii tych, którzy
-- zdążyli, a rejestr punktów jest niezmienny.
ALTER TABLE treasures
  ADD COLUMN status ENUM('PROPOSED','ACTIVE','RETIRED') NOT NULL DEFAULT 'PROPOSED' AFTER rarity;

-- POZIOM UJAWNIENIA (§ „nie wszystkie skarby od razu widoczne"):
--   0 — na mapie tylko znak zapytania w polu: „coś tu jest",
--   1 — po odkryciu pola pokazuje się TROP (kolumna `hint`),
--   2 — dokładna lokalizacja widoczna od razu.
-- Trzymamy to per skarb, bo pilotaż i skarby wydarzeń będą chciały być jawne,
-- a te „kolekcjonerskie" — ukryte.
ALTER TABLE treasures
  ADD COLUMN reveal_level TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER status,
  ADD COLUMN hint VARCHAR(300) NULL AFTER reveal_level;

-- Wydarzenie, z którego skarb pochodzi — „Warta 800" stawia 5 skarbów na
-- trasie i żyją one dalej po zakończeniu imprezy.
ALTER TABLE treasures
  ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER region_item_id,
  ADD CONSTRAINT fk_treasure_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL;

ALTER TABLE treasures ADD KEY idx_treasure_status (status, is_active);

-- POTWIERDZENIA SPOŁECZNOŚCI — skarb zgłoszony przez użytkownika zyskuje na
-- wiarygodności, gdy potwierdzają go kolejne osoby, i po przekroczeniu progu
-- wchodzi na mapę sam (próg w scoring_settings, więc admin go stroi).
--
-- Osobna tabela, nie licznik w kolumnie: licznik nie powstrzymałby jednej osoby
-- przed dodaniem dziesięciu potwierdzeń, a UNIQUE(treasure_id, user_id)
-- powstrzymuje — tak samo jak przy znalezieniach.
CREATE TABLE treasure_confirmations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  treasure_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_confirm_once (treasure_id, user_id),
  CONSTRAINT fk_confirm_treasure FOREIGN KEY (treasure_id) REFERENCES treasures (id) ON DELETE CASCADE,
  CONSTRAINT fk_confirm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SPOSÓB ZNALEZIENIA — bo są trzy i mają różną wagę dowodową:
--   QR  — zeskanowana wlepka: dowód, że tam byłeś i patrzyłeś,
--   GPS — potwierdzenie położenia w aplikacji: byłeś w pobliżu,
--   GPX — ślad przejazdu przeszedł obok: przejechałeś tamtędy.
-- Kolumna istnieje od teraz, żeby późniejsze różnicowanie stawek nie wymagało
-- migracji na tabeli, która zdąży urosnąć.
ALTER TABLE treasure_finds
  ADD COLUMN method ENUM('QR','GPS','GPX') NOT NULL DEFAULT 'QR' AFTER user_id;

-- Kategorie zyskują ikonę — mapa ma pokazywać 💎, a rodzaj skarbu rozpoznawać
-- po symbolu kategorii, nie po kolorze pinezki.
ALTER TABLE dictionary_items ADD COLUMN icon VARCHAR(16) NULL;

UPDATE dictionary_items SET icon = CASE code
    WHEN 'punkt_widokowy' THEN '🌄'
    WHEN 'przelecz'       THEN '⛰️'
    WHEN 'schronisko'     THEN '🏕️'
    WHEN 'zrodlo'         THEN '💧'
    WHEN 'zabytek'        THEN '🏰'
    WHEN 'ciekawostka'    THEN '🌲'
    WHEN 'serwis'         THEN '☕'
    ELSE icon END
 WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'sticker_category');

UPDATE dictionaries SET code = 'treasure_category', name = 'Kategoria skarbu'
 WHERE code = 'sticker_category';
