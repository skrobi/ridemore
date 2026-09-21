-- migration_056_treasure_finds_column.sql
-- DOKOŃCZENIE ZMIANY NAZWY Z migr. 055.
--
-- Tamta migracja przemianowała TABELE (stickers -> treasures, sticker_claims ->
-- treasure_finds), ale zostawiła w treasure_finds kolumnę `sticker_id`. Efekt:
-- treasure_confirmations ma `treasure_id`, a treasure_finds `sticker_id` — dwie
-- nazwy tego samego klucza obcego w dwóch tabelach obok siebie.
--
-- Poprawiamy TERAZ z tego samego powodu co poprzednio: wierszy nie ma, więc to
-- jedna migracja schematu. Po pilotażu byłaby to migracja danych plus poprawki
-- w kodzie pisanym w międzyczasie przeciw starej nazwie.

-- Nazwy kluczy pochodzą sprzed zmiany nazwy tabeli (migr. 054), więc `claim`
-- i `sticker` — zdejmujemy je razem z kolumną.
ALTER TABLE treasure_finds DROP FOREIGN KEY fk_claim_sticker;
ALTER TABLE treasure_finds DROP INDEX uniq_claim_once;
ALTER TABLE treasure_finds CHANGE COLUMN sticker_id treasure_id BIGINT UNSIGNED NOT NULL;
-- UNIQUE odtwarzamy pod nową nazwą kolumny. To NIE jest kosmetyka: ten klucz
-- jest całym zabezpieczeniem przed dwukrotnym naliczeniem punktów za ten sam
-- skarb (Models\Treasure::claim opiera się na INSERT IGNORE + rowCount).
ALTER TABLE treasure_finds
  ADD UNIQUE KEY uniq_find_once (treasure_id, user_id),
  ADD CONSTRAINT fk_find_treasure FOREIGN KEY (treasure_id) REFERENCES treasures (id) ON DELETE CASCADE;
