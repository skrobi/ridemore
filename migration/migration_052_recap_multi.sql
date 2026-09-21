-- migration_052_recap_multi.sql
-- KRONIKA PRZYJMUJE WIĘCEJ NIŻ JEDEN WPIS OD JEDNEJ OSOBY.
--
-- POWÓD (user 2026-08-14): „powinienem móc wpisać się do kroniki kilkukrotnie,
-- a nie tylko raz — pozwoli to przekazywać np. »jedziemy już prawie na miejscu«
-- albo »meldujemy się na zbiórce« i zdjęcia".
--
-- To zmienia to, CZYM jest kronika. Dotąd wpis był PODSUMOWANIEM wyjazdu —
-- jedna osoba, jedna relacja, pisana po powrocie; stąd UNIQUE i stąd przycisk
-- „Edytuj swój wpis". Teraz kronika jest DZIENNIKIEM pisanym w trakcie:
-- kolejny wpis to kolejna godzina tego samego dnia, a nie poprawka poprzedniego.
--
-- Nazwa sekcji („Dziennik wyjazdu") i jej porządek chronologiczny były zresztą
-- pod to gotowe od Etapu 4 — brakowało wyłącznie zgody bazy.
--
-- CO Z TEGO WYNIKA W KODZIE (bo samo zdjęcie klucza to nie wszystko):
--   * formularz relacji domyślnie TWORZY nowy wpis zamiast nadpisywać stary,
--   * edycja konkretnego wpisu wymaga jego id (`?wpis=ID`), bo „mój wpis" nie
--     jest już jednoznaczne,
--   * ze strony wydarzenia znika „Edytuj swój wpis" — nie ma czego edytować,
--     jest co dopisać.
--
-- ODWRACALNA WYŁĄCZNIE PRZY CZYSTYCH DANYCH: przywrócenie klucza uda się tylko
-- wtedy, gdy nikt nie zdążył dodać drugiego wpisu do tego samego turnusu.

ALTER TABLE event_recaps DROP INDEX uniq_recap_author_edition;

-- Klucz zdjęty, ale kolumny nadal odpytujemy parą (czyje wpisy w tym turnusie)
-- — przy każdym wyświetleniu kroniki, żeby wiedzieć, przy których wpisach
-- pokazać ołówek. Bez indeksu zostałoby skanowanie tabeli, która rośnie
-- najszybciej z całej kroniki.
CREATE INDEX idx_recap_edition_author ON event_recaps (edition_id, author_user_id);
