-- migration_033_wyscig_event_type.sql
-- Czwarty typ wydarzenia: "Wyścig" (zawody). Wyłącznie nowa pozycja w
-- słowniku event_type — sam typ nie ma własnej logiki biznesowej (żadnego
-- odpowiednika Event::validatePokrecZKims()), formularz jednodniowy
-- identyczny jak 'ustawka' (patrz kod: EventFormInput/EventFormResource
-- whitelist, wizard TYPE_META/STEP_MIN/visibleSteps() w script.php, sekcja
-- "Plan wyjazdu"/warianty w event-form.php) — tam gdzie 'ustawka' było
-- jedynym typem wpuszczanym do jednodniowej gałęzi UI, dopisano obok niego
-- 'wyscig'. sort_order=4 (za istniejącą trójką, kolejność z schema.sql).
INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code='event_type'), 'wyscig', 'Wyścig', 4);
