-- migration_078_trails_progress_layers.sql
-- „Znane trasy" NA MAPIE OSOBISTEJ DOSTAJE DWA DZIECI: ukończone / nieukończone.
--
-- ZGŁOSZENIE USERA (2026-08-29, ekran /odkrycia w kontekście 'me' obok
-- /odkrycia?mapa=spolecznosc): mapa osobista pokazywała WYŁĄCZNIE trasy, które
-- widz UKOŃCZYŁ (`meta.contexts.me.source = 'subject-done'`, Etap 3 warstw
-- mapy) — reszta katalogu w ogóle nie istniała na tej mapie, mimo że na mapie
-- społeczności widać KOMPLET (`source = 'known-routes'`). Efekt: użytkownik,
-- który jeszcze żadnej trasy nie ukończył, nie widział na własnej mapie ani
-- jednej znanej trasy i to wyglądało jak błąd renderowania.
--
-- ROZWIĄZANIE — TEN SAM WZORZEC CO „SKARBY" (migr. 072): węzeł `trails` w
-- kontekstach 'me'/'rider' traci WŁASNE `source` (przestaje sam być warstwą
-- kafli) i dostaje dwoje dzieci, każde ze swoim `source`:
--   `trailsDone`      -> `subject-done`      (bez zmian co do znaczenia)
--   `trailsRemaining` -> `subject-remaining` (NOWY token, `Models\MapLayer::
--                         tileKeyFor`, klucz kafla `kn-{slug}`/`kn-me` —
--                         DOKŁADNE lustro `kd-{slug}`/`kd-me`, patrz
--                         `Models\TileSource::tracks()`)
-- Kontekst 'all' (mapa społeczności) ZOSTAJE BEZ ZMIAN — węzeł `trails` tam
-- nadal sam niesie `source: 'known-routes'` i nie ma dzieci (dzieci w ogóle
-- nie mają klucza 'all' w `contexts`, więc `MapLayer::buildNode` ich tam nie
-- pokaże — ten sam mechanizm, który dziś chowa `treasuresFound`/`treasuresNew`
-- na profilu rowerzysty).
--
-- Strony, które PATCHUJĄ węzeł `trails` z powrotem na `known-routes` mimo
-- kontekstu 'me'/'rider' (strona trasy, panel dnia wydarzenia — `only`
-- + jawny patch w kontrolerze, Etap 2/3) tego pliku NIE dotyczy: patchują
-- węzeł PO zbudowaniu drzewa, nadpisując `trackKey` wprost, więc obecność czy
-- brak dzieci w słowniku jest im obojętna.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_078_trails_progress_layers.sql

SET NAMES utf8mb4;

-- Rodzic traci WŁASNE `source` w 'me'/'rider' (zostaje w 'all') — inaczej
-- węzeł renderowałby TRZY warstwy kafli naraz (katalog + dwoje dzieci) w tych
-- kontekstach, a rodzic i tak dalej działa jako zbiorczy przełącznik obojga
-- dzieci (ten sam mechanizm CSS/JS, co przy „Skarbach").
UPDATE dictionary_items
   SET meta = JSON_OBJECT(
         'kind', 'tiles',
         'contexts', JSON_OBJECT(
           'all',   JSON_OBJECT('on', TRUE, 'source', 'known-routes', 'hint', 'przebiegi szlaków'),
           'me',    JSON_OBJECT('on', TRUE, 'hint', 'przebiegi szlaków'),
           'rider', JSON_OBJECT('on', TRUE, 'hint', 'przebiegi szlaków')
         )
       )
 WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = 'trails';

INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, meta)
SELECT (SELECT id FROM dictionaries WHERE code = 'map_layer'),
       (SELECT id FROM dictionary_items
         WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = 'trails'),
       v.code, v.name, v.sort_order, v.meta
  FROM (
    SELECT 'trailsDone' AS code, 'Ukończone' AS name, 1 AS sort_order, JSON_OBJECT(
             'kind', 'tiles',
             'contexts', JSON_OBJECT(
               'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyłeś'),
               'rider', JSON_OBJECT('on', TRUE, 'source', 'subject-done', 'hint', 'trasy, które ukończyła')
             )
           ) AS meta
    UNION ALL
    SELECT 'trailsRemaining', 'Nieukończone', 2, JSON_OBJECT(
             'kind', 'tiles',
             'contexts', JSON_OBJECT(
               'me',    JSON_OBJECT('on', TRUE, 'source', 'subject-remaining', 'hint', 'trasy, których jeszcze nie ukończyłeś'),
               'rider', JSON_OBJECT('on', TRUE, 'source', 'subject-remaining', 'hint', 'trasy, których jeszcze nie ukończyła')
             )
           )
  ) v
 WHERE NOT EXISTS (
     SELECT 1 FROM dictionary_items
      WHERE dictionary_id = (SELECT id FROM dictionaries WHERE code = 'map_layer') AND code = v.code
 );
