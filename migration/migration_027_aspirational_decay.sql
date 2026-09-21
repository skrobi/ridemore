-- migration_027_aspirational_decay.sql
-- Etap 3 (preferencje) — wygaszanie deklaracji aspiracyjnych przez
-- zignorowane okazje (docs/etap3 §5, "Wygaszanie aspiracji"): po 5
-- zignorowanych powiadomieniach zgodnych z daną deklaracją waga spada o
-- połowę, po 10 deklaracja przestaje wywoływać powiadomienia (ale zostaje
-- widoczna/edytowalna). Licznik żyje NA POZYCJI deklaracji
-- (user_preference_items), nie globalnie — różne deklaracje aspiracyjne tego
-- samego usera gasną niezależnie od siebie. Osobna migracja (nie ALTER w
-- migration_026), bo 026 jest już zastosowana lokalnie — ten sam wzorzec
-- co reszta katalogu migration/ (nigdy nie edytuj już zastosowanej migracji).
--
-- mysql -u USER -p ridemorebike2 < migration_027_aspirational_decay.sql

ALTER TABLE user_preference_items
  ADD COLUMN ignored_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER kind;
