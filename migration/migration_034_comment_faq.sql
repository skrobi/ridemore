-- migration_034_comment_faq.sql
-- Q&A (FAQ) w dyskusji pod wydarzeniem — zgłoszenie usera 2026-08-09:
-- organizator wkleja parę pytanie+odpowiedź, pytanie pokazuje się ANONIMOWO
-- (jakby zadał je uczestnik), odpowiedź jako odpowiedź organizatora.
--
-- ŚWIADOMIE bez osobnej tabeli: para FAQ to dokładnie to samo co istniejący
-- wątek w event_comments (pytanie top-level + odpowiedź z is_organizer_reply),
-- więc reużywamy całej infrastruktury — wyświetlanie, wątkowanie, kasowanie
-- (ON DELETE CASCADE na parent_comment_id), moderację. Jedyna różnica to
-- SPOSÓB PREZENTACJI autora pytania: przy is_faq=TRUE widok nie pokazuje
-- nazwiska (bo formalnie autorem wiersza jest organizator, który je wpisał),
-- tylko neutralne "Pytanie uczestnika".
--
-- Kolumna dotyczy WYŁĄCZNIE wiersza pytania (top-level). Odpowiedź zostaje
-- zwykłą odpowiedzią organizatora (is_organizer_reply=TRUE), bo nią JEST.
ALTER TABLE event_comments
  ADD COLUMN is_faq BOOLEAN NOT NULL DEFAULT FALSE AFTER is_organizer_reply;
