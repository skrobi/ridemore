-- migration_063_route_elevation.sql
-- Przewyzszenie znanej trasy.
--
-- POWOD: dymek trasy na mapie (klikniecie w szlak) ma powiedziec to, czego
-- czlowiek szuka przed wyjazdem — co to za trasa, ile ma kilometrow i ile
-- metrow w gore. Dwie pierwsze rzeczy baza znala, trzeciej nie: `Gpx::parse()`
-- liczy `elevationGainM` przy kazdym wgraniu pliku, ale `known_routes` nie
-- mialo gdzie tego zapisac, wiec wartosc byla wyrzucana.
--
-- DLACZEGO KOLUMNA, A NIE LICZENIE W LOCIE: geometria warstwy „Znane trasy"
-- bierze sie z POL trasy wlasnie po to, zeby nie parsowac plikow GPX przy
-- kazdym przesunieciu mapy (patrz nota w KnownRoute::geometryInBounds).
-- Przewyzszenie liczone na zadanie mialoby ten sam koszt, ktoremu tamta
-- decyzja zapobiega.
--
-- NULL ZNACZY „NIE WIEM", NIE „PLASKO". Trasa moze miec 0 m przewyzszenia
-- zupelnie legalnie (plaska petla po Zulawach), wiec zero nie moze byc
-- wartoscia domyslna dla tras sprzed tej migracji — dymek pokazywalby wtedy
-- „0 m" jako fakt, a nie jako brak danych.
--
-- BACKFILL ROBI `run_migrations.php` zaraz po tej migracji (tak samo jak
-- slugi organizatorow po migracji 004): parsuje pliki GPX, ktore trasy juz
-- maja na dysku. Nie da sie tego zrobic SQL-em, a osobny skrypt to kolejny
-- krok wdrozenia do zapomnienia.
--
-- URUCHOMIENIE (prod): php run_migrations.php

ALTER TABLE known_routes
    ADD COLUMN elevation_gain_m INT UNSIGNED NULL AFTER distance_km;
