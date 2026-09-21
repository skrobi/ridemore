-- migration_074_multi_region.sql
--
-- Region trasy/wydarzenia/przejazdu solo jako ZBIÓR, nie pojedyncza kolumna —
-- realny ślad (GPX) często przechodzi przez więcej niż jedno województwo, a
-- dotychczasowy `region_item_id` (pojedynczy ręczny wybór) nie miał jak tego
-- wyrazić. Region_cells (migr. 070) już wie, do którego województwa należy
-- każdy heks — wystarczy złączyć go z heksami, które dany byt faktycznie
-- dotyka (known_route_cells / rider_activity_cells), a dla wydarzeń policzyć
-- heksy z GPX etapów/wariantów przez Models\RoutePreview::cellsForGpx()
-- (już cache'owane po hashu pliku, migr. 050).
--
-- Wzorzec tabel łączących jest identyczny jak known_route_cells/region_cells:
-- materializacja, nie liczenie w locie, żeby filtry i listy nie skanowały
-- GPX-ów przy każdym wejściu. Backfill: backfill_multi_regions.php.
--
-- Region eventu liczy się PER event_id (nie per turnus/edition) — trasy
-- (event_stages, event_route_variants) są dziś współdzielone między
-- terminami tego samego wydarzenia, więc region też jest współdzielony.

CREATE TABLE known_route_regions (
  route_id       BIGINT UNSIGNED NOT NULL,
  region_item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (route_id, region_item_id),
  KEY idx_krr_region (region_item_id),
  CONSTRAINT fk_krr_route  FOREIGN KEY (route_id) REFERENCES known_routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_krr_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_regions (
  event_id       BIGINT UNSIGNED NOT NULL,
  region_item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (event_id, region_item_id),
  KEY idx_evr_region (region_item_id),
  CONSTRAINT fk_evr_event  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_evr_region FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rider_activity_regions (
  activity_id    BIGINT UNSIGNED NOT NULL,
  region_item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (activity_id, region_item_id),
  KEY idx_rar_region (region_item_id),
  CONSTRAINT fk_rar_activity FOREIGN KEY (activity_id) REFERENCES rider_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_rar_region   FOREIGN KEY (region_item_id) REFERENCES dictionary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
