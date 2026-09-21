-- migration_085_ustawka_label.sql
-- ZMIANA NAZWY FORMATU: „Ustawka społecznościowa" -> „Zorganizowane wydarzenie"
-- (2026-09-11, decyzja usera: „zmieniłbym «Wspólna jazda» na «Zorganizowane
-- wydarzenie» w całej aplikacji").
--
-- KOD POZYCJI (`ustawka`) ZOSTAJE BEZ ZMIAN i to jest istotne: wisi na nim
-- wszystko — filtry w adresach (`?eventTypes[]=ustawka`, także te w stopce
-- i w zapisanych linkach), gałęzie kreatora (`TYPE_META`, `STEP_MIN`,
-- `visibleSteps()`), warianty trasy, rozgraniczenie jednodniowe/wielodniowe.
-- Zmieniamy wyłącznie ETYKIETĘ, czyli to, co czyta człowiek.
--
-- WARUNEK NA STAREJ WARTOŚCI zamiast bezwarunkowego UPDATE — migracja ma być
-- idempotentna (zasada z md/database.md), a przy okazji nie nadpisze etykiety,
-- którą ktoś zmieniłby ręcznie w słowniku po tej dacie.
UPDATE dictionary_items di
   JOIN dictionaries d ON d.id = di.dictionary_id
   SET di.name = 'Zorganizowane wydarzenie'
 WHERE d.code = 'event_type'
   AND di.code = 'ustawka'
   AND di.name = 'Ustawka społecznościowa';
