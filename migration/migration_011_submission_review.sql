-- migration_011_submission_review.sql
-- Zgłaszanie wydarzeń "w czyimś imieniu" (przez niezalogowanego gościa albo
-- zalogowanego użytkownika bez uprawnień do danego organizatora) — trafiają
-- do kolejki weryfikacji zamiast być od razu widoczne. Zatwierdza organizator
-- (jeśli ma już aktywne konto) albo administrator — patrz EventPermission::canEdit(),
-- reużyte 1:1 jako bramka zatwierdzania/odrzucania.
--
-- mysql -u USER -p ridemorebike2 < migration_011_submission_review.sql

SET NAMES utf8mb4;

-- Nowy status w już istniejącej grupie event_status (draft/published/full/
-- cancelled/completed — patrz seed.sql, na tej instalacji już zaseedowane).
INSERT INTO dictionary_items (dictionary_id, code, name, sort_order)
VALUES ((SELECT id FROM dictionaries WHERE code = 'event_status'), 'oczekuje_weryfikacji', 'Oczekuje weryfikacji', 6);

-- Kontakt do osoby zgłaszającej (gdy nie jest zalogowana — nie ma users.id
-- do przypisania) - do wglądu przez admina/organizatora przy zatwierdzaniu.
ALTER TABLE events
  ADD COLUMN submitter_name  VARCHAR(150) NULL AFTER organizer_id,
  ADD COLUMN submitter_email VARCHAR(255) NULL AFTER submitter_name;

-- Link "przejmij profil" (dla organizatora założonego przez kogoś innego) może
-- czekać tygodniami zanim ktoś go kliknie — w odróżnieniu od linku aktywacyjnego
-- przy zwykłej rejestracji (60 minut, klik "od razu"). NULL = bez limitu czasu.
ALTER TABLE account_activation_tokens
  MODIFY COLUMN expires_at DATETIME NULL;
