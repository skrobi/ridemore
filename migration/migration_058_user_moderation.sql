-- migration_058_user_moderation.sql
-- BLOKADA KONTA — narzedzie moderacyjne dla admina.
--
-- DLACZEGO BLOKADA, A NIE KASOWANIE. Klucze obce na `users` kaskaduja do 25
-- tabel: event_rsvps, event_recaps, event_comments, point_transactions,
-- discovery_cells, rider_activities i tak dalej. Skasowanie konta, ktore
-- cokolwiek robilo, wyciera wiec nie tylko jego dane, ale i historie CUDZYCH
-- wyjazdow: ze skladu znika uczestnik, z kroniki jego wpis, a z niezmiennego
-- rejestru punktow — transakcje, ktore mialy nigdy nie zniknac. Cztery kolejne
-- tabele (events, conversations, messages, event_rsvp_payments) maja RESTRICT,
-- wiec kasowanie organizatora i tak by sie nie udalo, tylko wywalilo bledem.
--
-- Blokada zalatwia oba przypadki z tego samego ekranu: „klnie" (konto zostaje,
-- czlowiek nie wchodzi) i „fejk z historia" (nie da sie skasowac bez szkod).
-- Twarde kasowanie zostaje wylacznie dla kont PUSTYCH — sprawdza to
-- Models\UserAdmin::contentSummary, a nie ten plik.
--
-- POWOD JEST OBOWIAZKOWY (kolumna NOT NULL przy zablokowaniu wymuszona w
-- kodzie): za pol roku nikt nie pamieta, czemu konto jest wylaczone, a bez
-- powodu odblokowanie jest zgadywaniem.

ALTER TABLE users
  ADD COLUMN blocked_at     TIMESTAMP NULL DEFAULT NULL AFTER is_admin,
  ADD COLUMN blocked_reason VARCHAR(255) NULL DEFAULT NULL AFTER blocked_at,
  ADD COLUMN blocked_by     BIGINT UNSIGNED NULL DEFAULT NULL AFTER blocked_reason;

-- ON DELETE SET NULL: skasowanie konta admina, ktory kogos zablokowal, nie ma
-- prawa odblokowac zablokowanego ani wywalic sie bledem.
ALTER TABLE users
  ADD CONSTRAINT fk_users_blocked_by FOREIGN KEY (blocked_by)
    REFERENCES users (id) ON DELETE SET NULL;

-- Lista moderacyjna filtruje po tej kolumnie i sortuje po dacie rejestracji
-- („czy ktos nowy sie zarejestrowal") — jeden indeks obsluguje oba widoki.
CREATE INDEX idx_users_blocked ON users (blocked_at);
CREATE INDEX idx_users_created ON users (created_at);
