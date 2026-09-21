-- migration_087_emblems.sql
-- EMBLEMATY ZA UKOŃCZENIE TRASY (2026-09-11, prośba usera: „dodając znaną
-- trasę mogę wprowadzić emblemat wirtualny za zdobycie tej trasy; na profilu
-- rowerzysty karta ze zdobytymi emblematami za każdą trasę lub wyjazd, który
-- ma taki emblemat wskazany").
--
-- OSOBNA TABELA, NIE KOLUMNY PRZY TRASIE — decyzja podjęta ze względu na
-- zapowiedź usera: „na przyszłość zrobimy rzeczywiste ordery za przejechanie
-- trasy". Fizyczny order to byt z własnym życiem (nakład, status wysyłki,
-- adres), więc komplet kolumn zdublowany na `known_routes` I `events`
-- trzeba by wtedy migrować w dwóch miejscach naraz. Przy okazji JEDEN emblemat
-- może obsłużyć serię tras („Korona Beskidów"), co przy kolumnach byłoby
-- niemożliwe.
CREATE TABLE emblems (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  description VARCHAR(400) NULL,
  -- Obrazek tą samą drogą co okładka wydarzenia (Upload::saveCoverPhoto):
  -- walidacja typu, limit rozmiaru i skalowanie są już tam. NULL = emblemat
  -- bez grafiki; widok rysuje wtedy pusty heks z ikoną, tak jak cel odkryć
  -- bez zdjęcia (`.disc-goal__photo--empty`).
  image_url   VARCHAR(500) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PRZYPISANIE: trasa albo wydarzenie WSKAZUJE emblemat, nie odwrotnie.
-- ON DELETE SET NULL — skasowanie emblematu nie może skasować trasy; trasa
-- po prostu przestaje go dawać. Zdobyte egzemplarze i tak zostają
-- (`emblem_awards` kasuje się kaskadą razem z definicją, patrz niżej).
ALTER TABLE known_routes
  ADD COLUMN emblem_id BIGINT UNSIGNED NULL AFTER cover_photo_url,
  ADD CONSTRAINT fk_kr_emblem FOREIGN KEY (emblem_id) REFERENCES emblems(id) ON DELETE SET NULL;

ALTER TABLE events
  ADD COLUMN emblem_id BIGINT UNSIGNED NULL AFTER point_bonus,
  ADD CONSTRAINT fk_ev_emblem FOREIGN KEY (emblem_id) REFERENCES emblems(id) ON DELETE SET NULL;

-- ZDOBYTE EGZEMPLARZE — REJESTR CHWILI, NIE LUSTRO STANU.
--
-- To jest jedyny powód, dla którego ta tabela w ogóle istnieje. Cała reszta
-- serwisu wyprowadza takie rzeczy w locie („derive, don't store" — Kronika,
-- Puls), a pokrycie trasy też dałoby się policzyć przy każdym wyświetleniu
-- profilu. ALE: `point_transactions` ODBIERA `TRAIL_COMPLETION`, gdy pokrycie
-- spadnie poniżej 100% — a spada np. wtedy, gdy admin podmieni plik GPX trasy
-- na dłuższy. Decyzja usera (2026-09-11): „raz zdobyty emblemat zostaje na
-- zawsze". Wyprowadzanie go ze stanu znaczyłoby, że człowiek traci emblemat
-- bez własnej winy, przez cudzą edycję. Dlatego zapisujemy CHWILĘ.
--
-- UNIKAT NA (user_id, emblem_id), nie na źródle: emblemat jest rzeczą, którą
-- się MA. Gdy ten sam emblemat wisi na pięciu trasach serii, pierwsza
-- ukończona go daje, a kolejne nie dokładają duplikatu. `source`/`source_id`
-- mówią, CO go przyniosło — pod podpis na profilu („za trasę X").
--
-- ON DELETE CASCADE przy emblemacie: skasowanie DEFINICJI kasuje egzemplarze.
-- Alternatywa (osierocone wiersze wskazujące na nieistniejący emblemat)
-- znaczyłaby kartę na profilu z pustymi kafelkami. Emblemat wycofuje się
-- przez `is_active = 0`, nie przez DELETE — i tak działa panel.
CREATE TABLE emblem_awards (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  emblem_id  BIGINT UNSIGNED NOT NULL,
  source     VARCHAR(16) NOT NULL,          -- 'route' | 'event'
  source_id  BIGINT UNSIGNED NOT NULL,      -- known_routes.id albo events.id
  awarded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emblem_award (user_id, emblem_id),
  KEY idx_ea_user (user_id, awarded_at),
  CONSTRAINT fk_ea_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_ea_emblem FOREIGN KEY (emblem_id) REFERENCES emblems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
