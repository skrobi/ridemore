-- migration_022_organizer_contact_email.sql
-- Publiczny e-mail kontaktowy organizatora, niezależny od users.email (adresu
-- logowania, którego i tak nie da się dziś samodzielnie zmienić). Gdy
-- ustawiony: (1) pokazuje się publicznie na profilu organizatora obok
-- telefonu/social media, (2) zastępuje adres logowania jako odbiorca
-- powiadomień o wydarzeniach organizatora (nowy zapis, płatność, pytanie,
-- opinia, relacja) — patrz Organizer::notificationEmail().

ALTER TABLE organizer_profiles
  ADD COLUMN contact_email VARCHAR(190) NULL AFTER languages;
