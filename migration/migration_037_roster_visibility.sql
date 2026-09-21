-- migration_037_roster_visibility.sql
--
-- Wylacznik "nie pokazuj mnie na listach uczestnikow i w peletonie".
-- Decyzja uzytkownika z 2026-08-11, podjeta przed Etapem 3 (profil) -
-- to pierwszy moment, w ktorym ludzie staja sie dla siebie widoczni na
-- stale, a nie tylko na jednej stronie wydarzenia.
--
-- Nazwa POZYTYWNA (roster_visible zamiast hide_from_rosters), zeby
-- warunki w SQL czytalo sie wprost: WHERE u.roster_visible = 1.
-- Pole formularza jest odwrotnoscia tej wartosci (checkbox "ukryj mnie"),
-- zamiana nastepuje w AccountController::updatePreferences.
--
-- DEFAULT TRUE - domyslnie widoczny. Profil ukryty domyslnie zabilby
-- efekt sieciowy calego kierunku "Peleton"; kto chce sie schowac, robi to
-- swiadomie.
--
-- WAZNE co do semantyki: ukryta osoba nadal LICZY SIE do skladu
-- ("8 osob jedzie"), znika tylko jej twarz i imie. Inaczej pomniejszalaby
-- liczbe uczestnikow, co byloby klamstwem wobec ogladajacego i psuloby
-- dowod spoleczny dla organizatora.

ALTER TABLE users
  ADD COLUMN roster_visible BOOLEAN NOT NULL DEFAULT TRUE AFTER is_admin;
