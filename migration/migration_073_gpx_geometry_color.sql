-- migration_073_gpx_geometry_color.sql
-- KOLOR ŚLADU NA KAFLU (2026-08-27).
--
-- Zgłoszenie usera: „wszystkie te trasy są wygenerowane w kolorze zielonym,
-- niezależnie od tego czy to solo przejazdy czy referencyjne (…) mam jedną
-- wielką zieloną plamę". Do tej pory kolor per obiekt miały WYŁĄCZNIE znane
-- trasy (`known_routes.color_index`, migr. 064); wszystkie przejazdy — solo
-- i z wyjazdów — szły jedną grupą w jednym kolorze `#2C6B4F`.
--
-- DLACZEGO KOLOR SIEDZI PRZY GEOMETRII, A NIE PRZY PRZEJEŹDZIE
-- ------------------------------------------------------------
-- `gpx_geometry` jest kluczowana HASHEM ZAWARTOŚCI pliku i jest DOKŁADNIE tym,
-- co renderer rysuje jako jedną linię. Ten sam plik bywa podpięty w kilku
-- miejscach naraz (przejazd solo, ślad turnusu, etap wydarzenia) — kolor przy
-- geometrii znaczy więc „ten ślad ma ten sam kolor wszędzie, gdzie się
-- pojawia", a nie „ten sam ślad jest zielony na jednej mapie i czerwony na
-- drugiej". Przy kolumnie w `rider_activities` to drugie byłoby stanem
-- domyślnym, bo ten sam plik ma tam osobny wiersz dla każdego właściciela.
--
-- Indeks, nie HEX: paleta żyje w `Utils\TrackPalette` (kod), więc zmiana
-- odcienia nie wymaga UPDATE'u na tabeli, a kolumna zajmuje jeden bajt.
--
-- NULL = „jeszcze nieprzydzielony" i jest w pełni bezpieczny: renderer spada
-- wtedy na kolor stylu, czyli rysuje dokładnie to, co przed tą migracją.
-- Przydział robi `Models\GpxGeometry::assignColor()` przy liczeniu geometrii
-- nowego pliku oraz `backfillColors()` dla tego, co już leży w bazie
-- (panel /admin/kafle albo `php tiles.php colors`).
--
-- PO TEJ MIGRACJI TRZEBA SKASOWAĆ KAFLE WARSTWY `slady` — to jest zmiana
-- WYGLĄDU, a kafle leżą na dysku z `Cache-Control: immutable` i same się
-- o niej nie dowiedzą. Panel /admin/kafle -> „Reset kafli" -> warstwa Ślady,
-- albo `php tiles.php purge slady`. Backfill kolorów robi to sam.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_073_gpx_geometry_color.sql

SET NAMES utf8mb4;

ALTER TABLE gpx_geometry
    ADD COLUMN color_index TINYINT UNSIGNED NULL DEFAULT NULL AFTER point_count;
