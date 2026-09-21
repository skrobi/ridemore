-- migration_016_billing_account_holder.sql
-- Dane do faktury (legal_name/address/tax_id) i konto do wypłat (bank_account)
-- dotąd zakładały tego samego właściciela — legal_name było niejawnie też
-- "posiadaczem konta". W praktyce organizator z zarejestrowaną firmą (legal_name,
-- NIP) może rozliczać się prywatnym kontem osoby fizycznej, nie firmowym.
-- Nowa kolumna nazywa wprost posiadacza konta, niezależnie od danych firmy.
--
-- mysql -u USER -p ridemorebike2 < migration/migration_016_billing_account_holder.sql

SET NAMES utf8mb4;

ALTER TABLE organizer_billing_profiles
  ADD COLUMN bank_account_holder VARCHAR(200) NULL AFTER tax_id;
