-- migration_061_route_cell_bbox.sql
-- Wspolrzedne osiowe pol znanej trasy — pod filtr kadru mapy.
--
-- POWOD (blad zgloszony po wdrozeniu 2026-08-19): swiezo wgrana trasa nie
-- pokazywala sie na mapie odkryc. Zapytanie warstwy „Znane trasy"
-- (KnownRoute::geometryInBounds) zawezalo trasy do kadru przez
-- JOIN discovery_cell_totals — a to jest tabela pol ODKRYTYCH PRZEZ KOGOKOLWIEK.
-- Trasa poprowadzona przez teren, po ktorym nikt jeszcze nie jechal, nie ma tam
-- ani jednego wiersza, wiec wypadala z wyniku w calosci. Zmierzone przed
-- naprawa: trasa z 9 polami, is_active=1, komplet sort_order → 0 tras w
-- odpowiedzi dla kadru dokladnie ja obejmujacego.
--
-- Sedno bledu: JOIN mial dostarczyc WYLACZNIE wspolrzednych (cell_q, cell_r),
-- bo po spakowanym cell_id nie da sie filtrowac prostokatem — a przy okazji,
-- niechcacy, dzialal jak warunek istnienia. Trasa musi byc widoczna zanim
-- ktokolwiek ja przejedzie; to jest caly sens warstwy „co jeszcze mozesz
-- zaliczyc".
--
-- ROZWIAZANIE: te same dwie kolumny co na discovery_cell_totals (migr. 040),
-- tyle ze na wlasnej tabeli trasy. Zdenormalizowane z cell_id, w calosci z
-- niego odtwarzalne, indeksowane pod ten jeden filtr prostokatny.
--
-- BACKFILL IDZIE W OSOBNYM PLIKU (migration_062), ale W TYM SAMYM PRZEBIEGU
-- `php run_migrations.php` — nie w recznym skrypcie jak przy migr. 047. To jest
-- migracja NAPRAWCZA: gdyby kolumny zostaly puste do czasu osobnego przebiegu,
-- trasy na produkcji nadal byloby niewidoczne, czyli blad zostalby otwarty.
--
-- Dlaczego DWA pliki, skoro to jedna zmiana: runner rejestruje migracje CALYMI
-- plikami, a przy bledzie w srodku pliku nie rejestruje niczego. Gdyby ALTER
-- i UPDATE siedzialy razem i UPDATE sie wywalil, ponowne uruchomienie
-- zobaczyloby „Duplicate column" z ALTER-a, uznaloby migracje za zastosowana
-- wczesniej i zarejestrowalo ja retroaktywnie — BEZ BACKFILLU, po cichu.
-- Rozdzielone: 061 dodaje kolumny, 062 je wypelnia i jest idempotentny.
--
-- URUCHOMIENIE (prod): php run_migrations.php
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_061_route_cell_bbox.sql

ALTER TABLE known_route_cells
    ADD COLUMN cell_q INT NULL AFTER cell_id,
    ADD COLUMN cell_r INT NULL AFTER cell_q;

-- Kolejnosc kolumn jak w idx_dct_bbox: r pierwsze, bo zalezy wylacznie od osi Y
-- i jego zakres jest dokladny; q jest nadzbiorem i dociska wynik.
CREATE INDEX idx_krc_bbox ON known_route_cells (cell_r, cell_q);
