-- migration_059_treasure_category_names.sql
-- NAZWY KATEGORII SKARBOW: naprawa uszkodzonych polskich znakow.
--
-- Ten sam blad co przy ikonach (migracja 057), tylko w kolumnie obok i o jeden
-- krok pozniej zauwazony. Migracja 054 wstawiala nazwy z polskimi znakami jako
-- literaly w pliku SQL; przy uruchomieniu przez konsole Windows bajty UTF-8
-- zostaly odczytane jako cp852 i tak trafily do bazy. HEX pokazywal:
--
--   'Przelecz'  -> 50727A65 E294BC C3A9 E29480 C396 637A   ("Prze┼é─Öcz")
--   'Zrodlo'    -> E294BC E295A3 72 ...                     ("┼╣r├│d┼éo / woda")
--
-- Uszkodzone byly DOKLADNIE trzy wiersze — te z polskimi znakami. Kategorie
-- czysto ASCII ('Ciekawostka', 'Zabytek') przeszly bez szkody, dlatego bledu
-- nie bylo widac w pierwszym przegladzie slownika.
--
-- WARTOSCI PODAJEMY PRZEZ UNHEX, NIE JAKO TEKST, i to jest tu najwazniejsze.
-- Gdyby stalo tu 'Przelecz' z polskimi znakami, ta migracja mogla by ulec
-- dokladnie temu samemu uszkodzeniu, ktore naprawia — wystarczy, ze ktos
-- uruchomi ja tym samym kanalem co 054. UNHEX jest odporny na kodowanie pliku,
-- klienta i konsoli: bajty sa bajtami.
--
--   50727A65C582C499637A                    = Przelecz (z l i e z ogonkiem)
--   536368726F6E69736B6F202F20626163C3B3776B61 = Schronisko / bacowka (z o kreskowanym)
--   C5B972C3B364C5826F202F20776F6461        = Zrodlo / woda (Z z kropka, o kresk., l)
--
-- Bezwarunkowo, bo jest idempotentna: na bazie, gdzie 054 poszla poprawnie,
-- wpisuje te same wartosci, ktore juz tam sa.

UPDATE dictionary_items di
   JOIN dictionaries d ON d.id = di.dictionary_id AND d.code = 'treasure_category'
    SET di.name = CASE di.code
        WHEN 'przelecz'   THEN UNHEX('50727A65C582C499637A')
        WHEN 'schronisko' THEN UNHEX('536368726F6E69736B6F202F20626163C3B3776B61')
        WHEN 'zrodlo'     THEN UNHEX('C5B972C3B364C5826F202F20776F6461')
        ELSE di.name
    END
 WHERE di.code IN ('przelecz', 'schronisko', 'zrodlo');
