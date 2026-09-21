-- migration_066_treasure_photos.sql
-- Galeria zdjec skarbu (SKA/14).
--
-- POWOD (zgloszenie usera 2026-08-22): „dla wszystkich skarbow dodalbym zdjecie
-- glowne jak rowniez mozliwosc zbudowania galerii zdjec".
--
-- ZDJECIE GLOWNE JUZ ISTNIEJE i NIE RUSZAMY GO. Kolumna `treasures.photo_url`
-- jest w schemacie od poczatku modulu, `Treasure::save()` ja zapisuje,
-- `inBounds()` wybiera, `reveal()` zeruje dla Tropu i Ukrytego, a dymek na
-- mapie ja renderuje. Jedyne, czego brakowalo, to POLE W FORMULARZU — stad
-- 0 ze 102 skarbow mialo zdjecie. Przeniesienie tej kolumny do galerii jako
-- `is_cover` kazaloby przepisac cztery dzialajace miejsca i nic by nie kupilo.
--
-- TA TABELA JEST ODWZOROWANA Z `event_photos` (te same kolumny, te same
-- kaskady), bo to ten sam problem: kilka zdjec przypietych do jednego bytu,
-- z kolejnoscia i z autorem. Nie ma powodu wymyslac drugiego ksztaltu.
--
-- BEZ KOLUMNY MODERACJI — swiadomie. Zdjecia z relacji z wyjazdu (`event_photos`)
-- wgrywaja dzis uzytkownicy i sa publiczne od razu, bez kolejki. Wprowadzenie
-- moderacji WYLACZNIE dla skarbow znaczyloby dwa rozne obiegi tej samej rzeczy
-- w jednym serwisie. Admin kasuje pojedyncze zdjecie, tak jak kasuje relacje.
--
-- `uploaded_by` NIE jest ozdoba: na nim opiera sie limit na osobe (patrz
-- Models\TreasurePhoto::countForUser) i uprawnienie do skasowania wlasnego
-- zdjecia. ON DELETE SET NULL, bo skasowanie konta nie moze zabrac zdjecia,
-- ktore ogladaja inni — tak samo jak przy relacjach.
--
-- WIDOCZNOSC NIE JEST W TEJ TABELI i to jest wazne przy edycji: o tym, czy
-- galeria w ogole wyjdzie do widoku, decyduje WYLACZNIE `Models\Treasure::reveal()`
-- — to samo miejsce, ktore zeruje `photo_url`, opis i rzadkosc dla Tropu
-- i Ukrytego. Kazda droga do zdjec omijajaca `reveal()` wycieka zagadke.
--
-- URUCHOMIENIE (prod): php run_migrations.php
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_066_treasure_photos.sql

CREATE TABLE treasure_photos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  treasure_id   BIGINT UNSIGNED NOT NULL,
  url           VARCHAR(500) NOT NULL,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  uploaded_by   BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Kolejnosc czytamy zawsze razem ze skarbem, wiec indeks zlozony, nie dwa.
  KEY idx_tphoto_treasure (treasure_id, sort_order),
  -- Pod limit „ile zdjec dorzucil ten czlowiek do tego skarbu".
  KEY idx_tphoto_user (uploaded_by, treasure_id),
  CONSTRAINT fk_tphoto_treasure FOREIGN KEY (treasure_id) REFERENCES treasures (id) ON DELETE CASCADE,
  CONSTRAINT fk_tphoto_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
