-- migration_030_external_registration_contacts.sql
-- Zapisy zewnętrzne mogą mieć teraz nie tylko link, ale też telefon i/lub e-mail
-- jako alternatywne formy zapisu (organizator prowadzi zapisy poza platformą).
-- Wszystkie trzy pola opcjonalne; "external" = podano JAKĄKOLWIEK z tych form.

ALTER TABLE events
  ADD COLUMN external_registration_phone VARCHAR(30)  NULL AFTER external_registration_url,
  ADD COLUMN external_registration_email VARCHAR(190) NULL AFTER external_registration_phone;
