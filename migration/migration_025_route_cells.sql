-- migration_025_route_cells.sql
-- Etap 2 (dopasowania) — krok 1: tabela komórek trasy pod wykrywanie
-- nakładania się śladów GPX. Siatka o boku ~200m (uzasadnienie progu w
-- docs/etap2 — 50m daje fałszywe negatywy przy szumie GPS, 800m zlewa różne
-- drogi w tej samej dolinie). Komórka liczona per ETAP (event_stages), nie
-- per wydarzenie, bo wielodniowa wycieczka ma osobny GPX na każdy dzień —
-- porównanie tras odbywa się na poziomie najlepiej pasującej pary etapów
-- (patrz Models\MatchEngine), nie na zsumowanym wydarzeniu.
--
-- Źródło punktów: event_stages.elevation_profile (JSON, ~50 próbek na etap,
-- co ok. 1,66 km przy typowej 83 km trasie) zawiera lat/lon od czasu
-- backfill_elevation_profiles.php. Zbyt rzadkie na siatkę 200m wprost —
-- Utils\RouteCells interpoluje liniowo między kolejnymi próbkami przed
-- przypisaniem komórek (patrz komentarz w tej klasie). Ten sam kod ścieżki
-- obsługuje nowe uploady i backfill istniejących wydarzeń — jedno źródło
-- prawdy zamiast dwóch rozjeżdżających się implementacji.
--
-- Etapy bez elevation_profile (brak GPX) po prostu nie mają wierszy tutaj —
-- dopasowanie działa dla nich na pozostałych osiach (patrz MatchEngine).
--
-- mysql -u USER -p ridemorebike2 < migration_025_route_cells.sql

SET NAMES utf8mb4;

CREATE TABLE event_stage_cells (
  event_stage_id BIGINT UNSIGNED NOT NULL,
  cell_x         INT NOT NULL,
  cell_y         INT NOT NULL,
  PRIMARY KEY (event_stage_id, cell_x, cell_y),
  CONSTRAINT fk_esc_stage FOREIGN KEY (event_stage_id) REFERENCES event_stages(id) ON DELETE CASCADE,
  -- Wspiera zapytanie "ile komórek etapu A pokrywa się z etapem B" przez
  -- self-join po (cell_x, cell_y) bez pełnego skanu.
  INDEX idx_esc_cell (cell_x, cell_y)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dedupe powiadomień nocnego zadania konsolidującego (krok 5 Etapu 2) — bez
-- tego ten sam mniejszy wyjazd dostawałby maila o tym samym większym
-- dopasowaniu co noc, dopóki oba istnieją. Kierunkowe (small -> large), bo
-- to mniejszy wyjazd jest informowany o większym, nigdy odwrotnie (patrz
-- hierarchia wartości dopasowania w docs/etap2).
CREATE TABLE event_match_notifications (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id            BIGINT UNSIGNED NOT NULL,
  matched_event_id    BIGINT UNSIGNED NOT NULL,
  notified_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_emn_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_emn_matched_event FOREIGN KEY (matched_event_id) REFERENCES events(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_event_pair (event_id, matched_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
