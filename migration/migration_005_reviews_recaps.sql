-- migration_005_reviews_recaps.sql
-- Opinie (zapis, dziś tylko odczyt), relacje z wyjazdu (tekst/zdjęcia/YouTube)
-- i galeria zdjęć eventu (event_photos istniało w schemacie od dawna, ale było
-- kompletnie martwe — zero uploadu, zero wierszy).
--
-- mysql -u USER -p ridemorebike2 < migration_005_reviews_recaps.sql

SET NAMES utf8mb4;

-- Strażnik: bez tego processCompletions() wysyłałoby zaproszenia do opinii
-- ponownie przy każdym uruchomieniu (oportunistycznie na stronie głównej).
ALTER TABLE events
  ADD COLUMN review_invites_sent_at TIMESTAMP NULL AFTER published_at;

-- "Relacja" z wyjazdu — wiele wpisów na event, jeden na osobę (edytowalny).
CREATE TABLE event_recaps (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id         BIGINT UNSIGNED NOT NULL,
  author_user_id   BIGINT UNSIGNED NOT NULL,
  body             TEXT NULL,
  youtube_url      VARCHAR(500) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_recap_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_recap_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_recap_author (event_id, author_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zdjęcie z opinii ma review_id, z relacji recap_id, wgrane bezpośrednio przez
-- organizatora do galerii — oba NULL. Galeria eventu = wszystkie event_photos
-- dla event_id, niezależnie od źródła.
ALTER TABLE event_photos
  ADD COLUMN review_id BIGINT UNSIGNED NULL AFTER event_id,
  ADD COLUMN recap_id BIGINT UNSIGNED NULL AFTER review_id,
  ADD CONSTRAINT fk_photo_review FOREIGN KEY (review_id) REFERENCES event_reviews(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_photo_recap FOREIGN KEY (recap_id) REFERENCES event_recaps(id) ON DELETE CASCADE;
