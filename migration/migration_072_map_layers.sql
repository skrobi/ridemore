-- migration_072_map_layers.sql
-- WARSTWY MAPY W SŁOWNIKU (Etap 2, tasks/done/warstwy-mapy.md).
--
-- Do tej daty każda strona z mapą wpisywała listę warstw wprost w PHP
-- (`$mlLayers = [...]`) — pięć kopii tej samej listy, z drobnymi różnicami,
-- które musiały być pamiętane ręcznie (rider-profile nie ma „Skarbów
-- zdobytych", trail.php nie ma „Śladów"). User (2026-08-26): „czy możemy
-- warstwy i ich konfigurację przenieść do dictionary (…) żeby można było
-- hierarchizować".
--
-- ŻADNEJ NOWEJ TABELI. `dictionary_items` ma już wszystko, czego to
-- potrzebuje: `parent_id` (hierarchia), `meta JSON` (konfiguracja),
-- `sort_order`, `is_active`. Ten plik tylko WYPEŁNIA istniejący mechanizm.
--
-- GRANICA DANE/MECHANIZM (decyzja architektoniczna, nie wracamy do niej):
-- wiersz mówi CO to za warstwa i GDZIE stoi w drzewie; `meta.kind` wskazuje
-- RENDERER w `assets/js/discovery-map.js` (`cells` = API pól + wielokąty,
-- `tiles` = rastrowe kafle, `markers` = piny z filtrem). Nowa warstwa
-- istniejącego rodzaju = jeden wiersz tutaj. Nowy rodzaj = kod.
--
-- KONTEKST (`meta.contexts.{all,me,rider}`) — jedno pojęcie zasięgu dla
-- całego serwisu (Etap 1), teraz jako dane: `on` (stan domyślny), `hint`
-- (podpis pod nazwą — TREŚĆ NALEŻY DO KONTEKSTU: profil mówi o kimś "gdzie
-- już był", własna mapa do widza "gdzie już byłeś"), `shown` (czy w ogóle
-- ma tu być — brak klucza kontekstu w ogóle też znaczy "nie pokazuj tu").
-- `source` (tylko `kind=tiles`) to ABSTRAKCYJNY token, nie klucz kafla:
-- `community` = wszyscy (`TileSource` klucz `all`), `subject` = OSOBA,
-- której dotyczy ta strona (`u-{slug}`/`me` — user 2026-08-26: „zmienia się
-- kontekst strony i za tym idą warstwy"), `known-routes` = wszystkie aktywne
-- znane trasy (klucz `kr`). Tłumaczenie tokenu na klucz kafla to MECHANIZM,
-- więc siedzi w kodzie (`Models\MapLayer::tileKeyFor`), nie tutaj.
--
-- SKARBY: `treasures` niesie `filterParam` (nazwa parametru zapytania do
-- API), a dzieci `treasuresFound`/`treasuresNew` niosą `filterValue`
-- (wartość TEGO parametru, gdy TYLKO to dziecko jest zapalone). Renderer
-- składa: oba dzieci zapalone = brak filtra (komplet), jedno = jego
-- `filterValue`, żadne = nie pytamy serwera wcale. `requiresLogin` na obu
-- dzieciach gasi je dla gościa NIEZALEŻNIE od kontekstu — bo bez konta nie
-- ma ani jednego znaleziska, więc przełącznik nic by nie zmieniał
-- (ta sama zasada co przy dawnym `$isLoggedIn` w widokach).
--
-- CZEGO TA MIGRACJA NIE ZAŁATWIA (Etap 3, ŚWIADOMIE POZA ZAKRESEM): dla
-- kontekstu `all` `treasuresFound`/`treasuresNew` nadal filtrują względem
-- OGLĄDAJĄCEGO (`Treasure::mineCondition` nie zna trybu „ktokolwiek").
-- Wiersze już tu stoją — Etap 3 dokłada zdolność serwera, nie zmienia
-- struktury. Tak samo `trails` ma dziś jeden `source` na wszystkie
-- konteksty (`known-routes`) — „trasy tylko moje" to też Etap 3.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_072_map_layers.sql

SET NAMES utf8mb4;

INSERT INTO dictionaries (code, name, description)
SELECT 'map_layer', 'Warstwa mapy', 'Struktura i konfiguracja kontrolki warstw (Etap 2, warstwy-mapy.md)'
 WHERE NOT EXISTS (SELECT 1 FROM dictionaries WHERE code = 'map_layer');

-- Rodzice (top-level), sort_order = kolejność w kontrolce.
INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, meta)
SELECT (SELECT id FROM dictionaries WHERE code = 'map_layer'), NULL, v.code, v.name, v.sort_order,
       v.meta
  FROM (
    SELECT 'cells' AS code, 'Odkrycia' AS name, 1 AS sort_order, JSON_OBJECT(
             'kind', 'cells',
             'contexts', JSON_OBJECT(
               'all',   JSON_OBJECT('on', TRUE, 'hint', 'gdzie bywa społeczność'),
               'me',    JSON_OBJECT('on', TRUE, 'hint', 'gdzie już byłeś'),
               'rider', JSON_OBJECT('on', TRUE, 'hint', 'gdzie już był')
             )
           ) AS meta
    UNION ALL
    SELECT 'heat', 'Heatmapa', 2, JSON_OBJECT(
             'kind', 'cells',
             'contexts', JSON_OBJECT(
               'all',   JSON_OBJECT('on', FALSE, 'hint', 'jak często tędy jeżdżą'),
               'me',    JSON_OBJECT('on', FALSE, 'hint', 'jak często tędy jeździsz'),
               'rider', JSON_OBJECT('on', FALSE, 'hint', 'jak często tędy jeździ')
             )
           )
    UNION ALL
    -- Podpis idzie za kontekstem (Etap 1b): na własnej i cudzej mapie ta
    -- warstwa pokazuje ślady TEJ osoby, nie społeczności.
    --
    -- `me.source = 'subject-private'`, NIE `'subject'` (naprawa błędu
    -- 2026-08-26: „ślady, które wgrałem, nie pojawiają się na mapie" —
    -- solo w ogóle nie wchodziło na `TileSource::tracks()`, a jedyne
    -- bezpieczne miejsce na surowy plik solo, §27, to klucz PRYWATNY `me").
    -- `rider.source` zostaje `'subject'` — to WCIĄŻ publiczny profil, nawet
    -- gdy patrzy właściciel. Pełne uzasadnienie: Models\MapLayer::tileKeyFor.
    SELECT 'slady', 'Ślady', 3, JSON_OBJECT(
             'kind', 'tiles',
             'contexts', JSON_OBJECT(
               'all',   JSON_OBJECT('on', TRUE, 'source', 'community',       'hint', 'przejechane trasy społeczności'),
               'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-private', 'hint', 'twoje przejechane trasy'),
               'rider', JSON_OBJECT('on', TRUE, 'source', 'subject',         'hint', 'przejechane trasy tej osoby')
             )
           )
    UNION ALL
    -- „TRASY" IDZIE ZA KONTEKSTEM JAK „ŚLADY" (Etap 3, 2026-08-26 — user:
    -- „Trasy również w kontekście społeczności dla całości, a usera to tylko
    -- usera"): społeczność widzi CAŁY katalog (`known-routes` → klucz `kr`),
    -- własna mapa i profil — WYŁĄCZNIE trasy UKOŃCZONE przez tę osobę
    -- (`subject-done` → klucz `kd-{slug}`/`kd-me`, patrz Models\MapLayer::
    -- tileKeyFor i Models\TileSource). Strony, które chcą KATALOGU mimo
    -- kontekstu `me`/`all` (strona trasy — kontekst wokół NIEJ, nie postęp
    -- widza; panel dnia wydarzenia — trasy szlaków po drodze, nie czyjeś
    -- ukończenia) PATCHUJĄ węzeł z powrotem na `known-routes` w kontrolerze,
    -- tą samą zasadą co patch węzła `cells` tam — to wiedza o UKŁADZIE
    -- KONKRETNEJ STRONY, nie o tej warstwie.
    SELECT 'trails', 'Znane trasy', 4, JSON_OBJECT(
             'kind', 'tiles',
             'contexts', JSON_OBJECT(
               'all',   JSON_OBJECT('on', TRUE, 'source', 'known-routes', 'hint', 'przebiegi szlaków'),
               'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyłeś'),
               'rider', JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyła')
             )
           )
    UNION ALL
    SELECT 'treasures', 'Skarby', 5, JSON_OBJECT(
             'kind', 'markers',
             'filterParam', 'stan',
             'contexts', JSON_OBJECT(
               'all',   JSON_OBJECT('on', TRUE, 'hint', 'do znalezienia w terenie'),
               'me',    JSON_OBJECT('on', TRUE, 'hint', 'do znalezienia w terenie'),
               'rider', JSON_OBJECT('on', TRUE, 'hint', 'znalezione przez tę osobę')
             )
           )
  ) v
 WHERE NOT EXISTS (
     SELECT 1 FROM dictionary_items
      WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = v.code
 );

-- SAMOKOREKTA `trails` (Etap 3, 2026-08-26): jeżeli ta migracja była już
-- wykonana WCZEŚNIEJSZĄ wersją tego pliku (klucz `subject-done` doszedł po
-- pierwszym uruchomieniu na tym środowisku), doprowadza istniejący wiersz do
-- tego samego stanu, jaki dostaje świeża instalacja. UPDATE bezwarunkowy jest
-- tu idempotentny z definicji — dwa uruchomienia ustawiają tę samą wartość.
UPDATE dictionary_items
   SET meta = JSON_OBJECT(
         'kind', 'tiles',
         'contexts', JSON_OBJECT(
           'all',   JSON_OBJECT('on', TRUE, 'source', 'known-routes', 'hint', 'przebiegi szlaków'),
           'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyłeś'),
           'rider', JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyła')
         )
       )
 WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = 'trails';

-- SAMOKOREKTA `slady` (naprawa błędu, 2026-08-26): `me.source` zmienione
-- z 'subject' na 'subject-private' — sam ten UPDATE, ta sama zasada co przy
-- `trails` wyżej.
UPDATE dictionary_items
   SET meta = JSON_OBJECT(
         'kind', 'tiles',
         'contexts', JSON_OBJECT(
           'all',   JSON_OBJECT('on', TRUE, 'source', 'community',       'hint', 'przejechane trasy społeczności'),
           'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-private', 'hint', 'twoje przejechane trasy'),
           'rider', JSON_OBJECT('on', TRUE, 'source', 'subject',         'hint', 'przejechane trasy tej osoby')
         )
       )
 WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = 'slady';

-- Dzieci „Skarbów" — filtr, nie osobna warstwa (decyzja architektoniczna 4).
-- `rider.shown = FALSE`: profil pokazuje KOLEKCJĘ tej osoby (zawężenie robi
-- `foundBy` na endpointzie, nie `stan`), więc rozbicie na
-- odkryte/nieodkryte nie ma tam o czym mówić — tak było od zawsze
-- (rider-profile.php nigdy nie miał tego przełącznika).
INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, meta)
SELECT (SELECT id FROM dictionaries WHERE code = 'map_layer'),
       (SELECT id FROM dictionary_items
         WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = 'treasures'),
       v.code, v.name, v.sort_order, v.meta
  FROM (
    SELECT 'treasuresFound' AS code, 'Skarby zdobyte' AS name, 1 AS sort_order, JSON_OBJECT(
             'filterValue', 'moje',
             'requiresLogin', TRUE,
             'contexts', JSON_OBJECT(
               'all',   JSON_OBJECT('on', TRUE, 'hint', 'te, które już masz'),
               'me',    JSON_OBJECT('on', TRUE, 'hint', 'te, które już masz'),
               'rider', JSON_OBJECT('shown', FALSE)
             )
           ) AS meta
    UNION ALL
    SELECT 'treasuresNew', 'Skarby nieodkryte', 2, JSON_OBJECT(
             'filterValue', 'nowe',
             'requiresLogin', TRUE,
             'contexts', JSON_OBJECT(
               'all',   JSON_OBJECT('on', TRUE, 'hint', 'jeszcze nieznalezione'),
               'me',    JSON_OBJECT('on', TRUE, 'hint', 'jeszcze nieznalezione'),
               'rider', JSON_OBJECT('shown', FALSE)
             )
           )
  ) v
 WHERE NOT EXISTS (
     SELECT 1 FROM dictionary_items
      WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = v.code
 );
