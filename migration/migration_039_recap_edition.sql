-- migration_039_recap_edition.sql
--
-- KRONIKA WYJAZDU (Etap 4) - relacje przypiete do TURNUSU, nie do calego
-- wydarzenia.
--
-- Kronika jest per-turnus, bo obecnosc jest per-turnus (event_attendance
-- wisi na event_rsvps.edition_id, migr. 035). Przy wydarzeniu cyklicznym
-- - a takie sa na ridemore od migr. 023 - jedna wspolna kronika mieszalaby
-- rozne terminy: inni ludzie, inna data, czesto inna trasa. Relacja z
-- lipcowego turnusu nie ma czego szukac pod sierpniowym.
--
-- event_recaps mial dotad tylko event_id, wiec dokladamy edition_id.
-- NULL dozwolony i backfill ustawia najwczesniejszy turnus wydarzenia:
-- relacje sprzed tej migracji powstaly, gdy turnus w ogole nie byl brany
-- pod uwage, wiec przypisanie ich do pierwszego terminu jest jedynym
-- sensownym domyslem (i tak w praktyce dotyczy wydarzen jednoterminowych).
--
-- ON DELETE SET NULL, nie CASCADE: skasowanie turnusu nie moze wymazac
-- czyjejs relacji z przejechanego wyjazdu. Zostaje przy wydarzeniu.
--
-- ZDJEC ta migracja NIE dotyka - event_photos wisza na recap_id, wiec
-- zdjecia kroniki wynikaja z relacji tego turnusu bez zadnej dodatkowej
-- kolumny.

ALTER TABLE event_recaps
  ADD COLUMN edition_id BIGINT UNSIGNED NULL AFTER event_id,
  ADD CONSTRAINT fk_recap_edition FOREIGN KEY (edition_id)
      REFERENCES event_editions(id) ON DELETE SET NULL;

UPDATE event_recaps r
  JOIN (
    SELECT event_id, MIN(id) AS first_edition_id
    FROM event_editions
    GROUP BY event_id
  ) fe ON fe.event_id = r.event_id
  SET r.edition_id = fe.first_edition_id
  WHERE r.edition_id IS NULL;

CREATE INDEX idx_recap_edition ON event_recaps (edition_id);

-- Klucz unikalny przenosimy z (event_id, author_user_id) na
-- (edition_id, author_user_id). Stary blokowalby to, po co ta migracja
-- w ogole powstala: przy wydarzeniu cyklicznym ktos, kto pojechal
-- w lipcu i w sierpniu, musi moc dorzucic relacje do OBU kronik, a nie
-- tylko do pierwszej. Jedna relacja na osobe na turnus zostaje - to nadal
-- kronika wyjazdu, nie sciana postow.
-- Osobny indeks na event_id MUSI powstac PRZED zdjeciem starego klucza:
-- uniq_recap_author (event_id, author_user_id) obsluguje dzis takze klucz
-- obcy fk_recap_event, wiec proba samego DROP INDEX konczy sie bledem
-- 1553 "needed in a foreign key constraint".
CREATE INDEX idx_recap_event ON event_recaps (event_id);

ALTER TABLE event_recaps
  DROP INDEX uniq_recap_author,
  ADD UNIQUE KEY uniq_recap_author_edition (edition_id, author_user_id);
