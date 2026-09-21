-- migration_089_multilingual.sql
-- WIELOJĘZYCZNOŚĆ (2026-09-16, tasks/active/wielojezycznosc.md).
--
-- Cała zmiana schematu dla wersji językowych to TA JEDNA migracja i jest
-- wyłącznie ADDYTYWNA — żadna tabela treści (events, known_routes, treasures,
-- organizer_profiles, emblems, dictionary_items) nie dostaje kolumn językowych.
-- Oryginał zostaje tam, gdzie był, w języku, w którym napisał go autor.
--
-- 1. `users.lang` — język, w którym człowiek dostaje maile i pushe, i do
--    którego prowadzi go wejście na stronę główną. NULL = nigdy nie wybierał
--    (= polski, jak przed zmianą). Ustawiany przełącznikiem i przy rejestracji.
--
-- 2. `content_translations` — tłumaczenia treści z bazy, tworzone LENIWIE przy
--    pierwszym wyświetleniu w danym języku (Models\ContentTranslation).
--    Kluczem jest HASH TEKSTU ŹRÓDŁOWEGO + język docelowy, nie (tabela, id, pole):
--      * edycja oryginału zmienia hash, więc stare tłumaczenie samo przestaje
--        pasować — nie ma czego unieważniać ręcznie,
--      * turnusy/edycje z tym samym opisem dzielą jedno tłumaczenie,
--      * nie trzeba dotykać ani jednej ścieżki zapisu treści.
--    `context` ('event:123:description') to PODPOWIEDŹ, nie klucz: formularz
--    korekty pokazuje po nim poprzednią ręczną wersję, a sprzątanie w cronie
--    wie, skąd wiersz pochodzi.
--    `origin`: machine (silnik), human (poprawka człowieka — wygrywa zawsze),
--    same (silnik orzekł, że tekst już jest w języku docelowym; zapamiętane,
--    żeby nie pytać drugi raz).
--    pending (2026-09-17, driver `ai`): tekst czeka w kolejce na tłumacza
--    w tle (`cron.php tlumaczenia`) — model językowy odpowiada sekundami, więc
--    nie tłumaczymy w trakcie renderowania. Tylko dla takich wierszy
--    `source_text` trzyma oryginał (po przetłumaczeniu jest zerowany), bo
--    z samego hasha cron nie odtworzy tekstu.

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN lang VARCHAR(5) NULL AFTER avatar_url;

CREATE TABLE IF NOT EXISTS content_translations (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_hash     CHAR(40) NOT NULL,
  target_lang     VARCHAR(5) NOT NULL,
  source_lang     VARCHAR(5) NULL,
  translated_text MEDIUMTEXT NULL,
  origin          ENUM('machine','human','same','pending') NOT NULL,
  source_text     MEDIUMTEXT NULL,
  engine          VARCHAR(32) NULL,
  context         VARCHAR(64) NULL,
  updated_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ct_hash_lang (source_hash, target_lang),
  KEY idx_ct_context (context),
  KEY idx_ct_origin (origin),
  CONSTRAINT fk_ct_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
