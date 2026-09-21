-- migration_057_treasure_icons.sql
-- IKONY KATEGORII SKARBOW: emoji -> klucz do Utils\Icon.
--
-- POWOD JEST PODWOJNY.
--
-- 1. TO, CO WIDAC. Migracja 055 wstawiala emoji jako surowe bajty UTF-8
--    ('\xF0\x9F\x8C\x84' dla punktu widokowego). Przy uruchomieniu przez
--    konsole Windows te bajty zostaly odczytane jako cp852, czyli jako CZTERY
--    osobne znaki, i tak trafily do bazy. HEX kolumny pokazywal
--    C2AD C48D C3AE E29693 zamiast F09F8CB2 — uzytkownik zglosil to jako
--    "dziwne slaczki zamiast nazwy".
--
-- 2. TO, CZEGO NIE WIDAC. Nawet zapisane poprawnie emoji bylo bledem: cala
--    reszta serwisu rysuje ikony przez Utils\Icon (inline SVG, currentColor),
--    a naglowek tamtego pliku od poczatku mowi, ze powstal WLASNIE po to, zeby
--    nie uzywac emoji — bo renderuje sie inaczej na kazdym systemie i nie da
--    sie go pokolorowac razem z tekstem.
--
-- Po tej migracji kolumna trzyma KLUCZ ('tre-viewpoint'), a ksztalt siedzi
-- w kodzie. Zmiana wygladu ikony nie wymaga juz ruszania bazy.
--
-- Kolumne poszerzamy z 16 na 32 znaki: najdluzszy klucz ma dzis 13, ale przy
-- 16 kazdy kolejny dluzszy klucz konczylby sie ucieciem w polowie i cicha
-- utrata ikony.

ALTER TABLE dictionary_items MODIFY COLUMN icon VARCHAR(32) NULL;

-- Dopasowanie po `code`, nie po `icon` — stara wartosc jest uszkodzona i nie
-- da sie jej wiarygodnie porownac.
UPDATE dictionary_items di
   JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'treasure_category'
    SET di.icon = CASE di.code
        WHEN 'punkt_widokowy' THEN 'tre-viewpoint'
        WHEN 'przelecz'       THEN 'tre-pass'
        WHEN 'schronisko'     THEN 'tre-hut'
        WHEN 'zrodlo'         THEN 'tre-water'
        WHEN 'zabytek'        THEN 'tre-monument'
        WHEN 'serwis'         THEN 'tre-service'
        WHEN 'ciekawostka'    THEN 'tre-curiosity'
        ELSE NULL
    END;

-- Sprzatanie po tym samym bledzie w innych slownikach, gdyby gdzies jeszcze
-- siedzial: wszystko, co nie jest znanym kluczem, czyscimy do NULL. Utils\Icon
-- ::maybe i tak zwrocilby dla tego pusty string, ale w bazie nie ma powodu
-- trzymac smieci.
UPDATE dictionary_items
   SET icon = NULL
 WHERE icon IS NOT NULL
   AND icon NOT LIKE 'tre-%'
   AND icon <> '';
