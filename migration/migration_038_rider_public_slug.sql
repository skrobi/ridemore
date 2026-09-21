-- migration_038_rider_public_slug.sql
--
-- Publiczny profil rowerzysty (Etap 3) - adres /rowerzysta/{slug}.
--
-- Do tej pory uzytkownik NIE istnial w serwisie jako byt publiczny:
-- organizator mial slug, profil, sitemape, weryfikacje i oceny, a
-- rowerzysta tylko adres e-mail i ekran ustawien. Nie dalo sie na niego
-- wskazac linkiem, wiec sklad wyjazdu byl lista imion, a nie ludzi.
--
-- Slug osobny od id, zeby adres nie zdradzal liczby kont ani kolejnosci
-- rejestracji. NULL dozwolony: konta sprzed tej migracji dostaja slug
-- skryptem backfill_rider_slugs.php, a gdyby ktores zostalo pominiete,
-- po prostu nie jest linkowane - zamiast psuc strone wydarzenia.
--
-- Sama obecnosc sluga NIE czyni profilu publicznym. Widocznosc rozstrzyga
-- Controllers\RiderController::show: profil istnieje tylko dla kogos, kto
-- (1) nie wylaczyl sie z list (users.roster_visible, migr. 037) oraz
-- (2) ma co najmniej jeden POTWIERDZONY przejazd. Profil bez ani jednego
-- wyjazdu to pusty profil - a tych w tym produkcie swiadomie nie ma.

ALTER TABLE users
  ADD COLUMN public_slug VARCHAR(160) NULL UNIQUE AFTER roster_visible;
