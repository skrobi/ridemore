-- migration_031_route_variants.sql
-- Warianty trasy: jeden (jednodniowy) event może mieć kilka alternatywnych
-- tras ("pętli") — np. WATAHA ULTRA: 300 km / 200 km / 100 km — każda z WŁASNYM
-- GPX, ceną i limitem miejsc. Uczestnik wybiera JEDEN wariant przy zapisie.
--
-- Warstwa OPCJONALNA: event bez wierszy w event_route_variants działa dokładnie
-- jak dotąd (jedna trasa w event_stages + jeden event_pricing). Gdy warianty
-- istnieją, ZASTĘPUJĄ pojedynczą trasę+cenę — trasa/cena/limit brane są z
-- wybranego wariantu. Waluta/jednostka/terminy płatności zostają wspólne w
-- event_pricing (wariant nadpisuje tylko kwotę i zaliczkę). Na razie tylko typy
-- jednodniowe (ustawka) — wielodniowe zostają przy jednej trasie.

CREATE TABLE event_route_variants (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id            BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(150) NOT NULL,               -- np. "Pętla 300 km"
  sort_order          SMALLINT NOT NULL DEFAULT 0,
  -- Trasa — ten sam kształt co event_stages (jedna trasa na wariant); GPX
  -- promowany z tmp i profil/nawierzchnia liczone tak samo jak dla etapu.
  distance_km         DECIMAL(6,2) NOT NULL DEFAULT 0,
  elevation_gain_m    INT UNSIGNED NOT NULL DEFAULT 0,
  surface_item_id     INT UNSIGNED NULL,                   -- dict: surface_type (ręczny fallback)
  surface_asphalt_pct SMALLINT UNSIGNED NULL,              -- z RoadSurfaceDetector przy wgraniu GPX
  surface_gravel_pct  SMALLINT UNSIGNED NULL,
  surface_trail_pct   SMALLINT UNSIGNED NULL,
  gpx_url             VARCHAR(500) NULL,
  elevation_profile   JSON NULL,                           -- punkty pod wykres, jak w event_stages
  -- Cena własna per wariant (gdy event płatny). NULL price_amount = wariant bez
  -- opłaty (darmowy event). Waluta/jednostka/terminy — wspólne z event_pricing.
  price_amount        DECIMAL(10,2) NULL,
  deposit_amount      DECIMAL(10,2) NULL,
  -- Limit miejsc PER WARIANT (decyzja produktowa): zapełnienie jednej pętli nie
  -- blokuje innych. Liczone per (edition_id, variant_id) w event_rsvps. NULL = bez limitu.
  max_participants    SMALLINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_variant_event   FOREIGN KEY (event_id)        REFERENCES events(id)          ON DELETE CASCADE,
  CONSTRAINT fk_variant_surface FOREIGN KEY (surface_item_id) REFERENCES dictionary_items(id) ON DELETE SET NULL,
  INDEX idx_variant_event (event_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Który wariant wybrał uczestnik. NULL = event bez wariantów (dotychczasowe
-- zachowanie) albo zapis sprzed wprowadzenia wariantów. ON DELETE SET NULL:
-- skasowanie wariantu NIE kasuje zapisu (uczestnik i tak istnieje — w
-- odróżnieniu od edition_id, które kasuje kaskadowo cały turnus), tylko zrywa
-- powiązanie z konkretną pętlą.
ALTER TABLE event_rsvps
  ADD COLUMN variant_id BIGINT UNSIGNED NULL AFTER edition_id,
  ADD CONSTRAINT fk_rsvp_variant FOREIGN KEY (variant_id) REFERENCES event_route_variants(id) ON DELETE SET NULL,
  ADD INDEX idx_rsvp_variant (variant_id);
