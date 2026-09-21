-- migration_047_cell_parents.sql
-- Rodzice pola na każdym poziomie agregacji mapy odkryć.
--
-- POWÓD (błąd zgłoszony 2026-08-13: „przy skalowaniu znikają hexagony"):
-- Discovery::communityCells() grupowało pola po PROSTOKĄCIE we współrzędnych
-- osiowych — GROUP BY FLOOR(cell_q / step), FLOOR(cell_r / step) — a potem
-- brało MIN(cell_id) jako reprezentanta grupy i pytało DiscoveryGrid::parentCell()
-- o jego heksagon nadrzędny. To dwa różne parkietaże: heksagony poziomu wyższego
-- NIE układają się w prostokąty siatki poziomu niższego.
--
-- Zmierzone na danych deweloperskich (1029 pól): przy res=3 aż 199 z 274 grup
-- prostokątnych zawierało pola należące do RÓŻNYCH heksagonów nadrzędnych (do 4
-- na grupę). Cała grupa lądowała wtedy pod jednym z nich, a pozostałe nie
-- dostawały nic i po prostu się nie rysowały. Przy res=0 wychodziło 5 realnych
-- rodziców i 4 grupy — jeden heksagon był nieosiągalny z definicji.
--
-- Rozwiązanie: rodzic liczony RAZ, przy zapisie, przez Utils\DiscoveryGrid (dalej
-- jedyny właściciel geometrii siatki) i zapisany w kolumnie. Zapytanie grupuje
-- wtedy po realnym heksagonie, a nie po jego prostokątnym przybliżeniu.
-- Ta sama zasada, co przy cell_q/cell_r z migr. 040: dane zdenormalizowane pod
-- konkretne zapytanie mapy, odtwarzalne w całości z pól źródłowych.
--
-- Kolumny są NULL-owalne, bo backfill idzie osobnym przebiegiem
-- (backfill_discovery.php --rebuild) — do czasu jego uruchomienia zapytanie
-- pomija pola bez wyliczonego rodzica zamiast rysować je w złym miejscu.
--
-- res 4 nie ma kolumny: przy pełnym przybliżeniu pole JEST swoim własnym
-- rodzicem i grupowanie w ogóle nie zachodzi.

ALTER TABLE discovery_cell_totals
    ADD COLUMN parent_res3 BIGINT NULL AFTER cell_r,
    ADD COLUMN parent_res2 BIGINT NULL AFTER parent_res3,
    ADD COLUMN parent_res1 BIGINT NULL AFTER parent_res2,
    ADD COLUMN parent_res0 BIGINT NULL AFTER parent_res1;

-- Indeksy pod GROUP BY z ograniczeniem prostokątnym po cell_q/cell_r:
-- zapytanie mapy zawsze najpierw zawęża kadr, potem grupuje.
CREATE INDEX idx_dct_parent3 ON discovery_cell_totals (parent_res3);
CREATE INDEX idx_dct_parent2 ON discovery_cell_totals (parent_res2);
CREATE INDEX idx_dct_parent1 ON discovery_cell_totals (parent_res1);
CREATE INDEX idx_dct_parent0 ON discovery_cell_totals (parent_res0);
