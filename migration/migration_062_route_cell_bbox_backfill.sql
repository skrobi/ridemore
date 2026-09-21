-- migration_062_route_cell_bbox_backfill.sql
-- Wypelnienie kolumn dodanych przez migracje 061.
--
-- IDEMPOTENTNA — liczy wartosci z `cell_id`, wiec powtorne uruchomienie zapisuje
-- dokladnie to samo. Dlatego stoi w osobnym pliku: gdyby wywalila sie razem
-- z ALTER-em z 061, runner przy ponownym przebiegu zobaczylby „Duplicate column"
-- i zarejestrowal cala migracje jako zastosowana — z pustymi kolumnami i trasami
-- dalej niewidocznymi na mapie.
--
-- SKAD TA ARYTMETYKA: identyfikator pola jest spakowany (Utils\DiscoveryGrid::encode)
--   cell_id = (res << 60) | ((q & 0x3FFFFFFF) << 30) | (r & 0x3FFFFFFF)
-- Odzyskanie znaku: wartosc >= 0x20000000 jest ujemna i trzeba od niej odjac
-- 0x40000000. CAST(... AS SIGNED) jest KONIECZNY — operatory bitowe MySQL-a
-- zwracaja BIGINT UNSIGNED i samo odejmowanie skonczyloby sie bledem zakresu.
--
-- Zweryfikowane wiersz po wierszu wzgledem DiscoveryGrid::decode() (zero
-- rozjazdow), lacznie z ujemnymi wspolrzednymi: polkula zachodnia i poludniowa
-- (Nowy Jork, Sydney, Rio) — tam wlasnie sign-extend ma szanse sie wylozyc.

UPDATE known_route_cells
   SET cell_q = CAST((cell_id >> 30) & 0x3FFFFFFF AS SIGNED)
              - IF(((cell_id >> 30) & 0x3FFFFFFF) >= 0x20000000, 1073741824, 0),
       cell_r = CAST(cell_id & 0x3FFFFFFF AS SIGNED)
              - IF((cell_id & 0x3FFFFFFF) >= 0x20000000, 1073741824, 0);
