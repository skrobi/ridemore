-- seed_002_more_events.sql
-- Dodatkowe wydarzenia (poza seed.sql) do testowania filtrów listy —
-- różne regiony, poziomy trudności, typy roweru, ceny i daty.
-- Wymaga wcześniej uruchomionych: schema.sql, seed.sql, migration_002_region.sql.
--
-- mysql -u USER -p ridemorebike2 < seed_002_more_events.sql

SET NAMES utf8mb4;

SET @organizer_peer_id = (SELECT id FROM users WHERE email='marek.kowalski@example.com');
SET @organizer_pro_id  = (SELECT id FROM users WHERE email='kontakt@tatrybiketours.example.com');

-- 1) Darmowa ustawka MTB w Bieszczadach, w ten weekend, łatwa
INSERT INTO events
  (organizer_id, event_type_item_id, status_item_id, title, slug, description,
   difficulty_item_id, pace_group_item_id, region_item_id, min_participants, max_participants,
   meeting_point_address, meeting_point_lat, meeting_point_lng, start_date, published_at)
VALUES
  (@organizer_peer_id,
   (SELECT id FROM dictionary_items WHERE code='ustawka'),
   (SELECT id FROM dictionary_items WHERE code='published' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='event_status')),
   'Leniwa niedziela w Bieszczadach', 'leniwa-niedziela-bieszczady',
   'Krótka, łatwa przejażdżka MTB dla początkujących — bez większych podjazdów.',
   (SELECT id FROM dictionary_items WHERE code='latwa' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='difficulty_level')),
   (SELECT id FROM dictionary_items WHERE code='wolne' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='pace_group')),
   (SELECT id FROM dictionary_items WHERE code='bieszczady' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='region')),
   4, 15, 'Rynek w Lesku', 49.4713, 22.3335, DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE()) + 7) % 7 DAY), NOW());

SET @event_c_id = LAST_INSERT_ID();
INSERT INTO event_bike_types (event_id, bike_type_item_id) VALUES
  (@event_c_id, (SELECT id FROM dictionary_items WHERE code='mtb'));
INSERT INTO event_stages (event_id, day_number, stage_date, start_point, end_point, distance_km, elevation_gain_m) VALUES
  (@event_c_id, 1, DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE()) + 7) % 7 DAY), 'Rynek w Lesku', 'Rynek w Lesku', 28.00, 210);

-- 2) Płatna 2-dniowa wycieczka w Beskidach, później w tym miesiącu, średnia trudność, szosowy
INSERT INTO events
  (organizer_id, event_type_item_id, status_item_id, title, slug, description,
   difficulty_item_id, pace_group_item_id, region_item_id, min_participants, max_participants,
   meeting_point_address, meeting_point_lat, meeting_point_lng, start_date, published_at)
VALUES
  (@organizer_pro_id,
   (SELECT id FROM dictionary_items WHERE code='wycieczka_wielodniowa'),
   (SELECT id FROM dictionary_items WHERE code='published' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='event_status')),
   'Weekend szosowy w Beskidach', 'weekend-szosowy-beskidy',
   'Dwa dni szosowej jazdy po beskidzkich przełęczach z noclegiem w schronisku.',
   (SELECT id FROM dictionary_items WHERE code='srednia' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='difficulty_level')),
   (SELECT id FROM dictionary_items WHERE code='srednie' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='pace_group')),
   (SELECT id FROM dictionary_items WHERE code='beskidy' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='region')),
   6, 16, 'Dworzec PKP Bielsko-Biała', 49.8224, 19.0444, DATE_ADD(CURDATE(), INTERVAL 18 DAY), NOW());

SET @event_d_id = LAST_INSERT_ID();
INSERT INTO event_bike_types (event_id, bike_type_item_id) VALUES
  (@event_d_id, (SELECT id FROM dictionary_items WHERE code='szosowy'));
INSERT INTO event_stages (event_id, day_number, stage_date, start_point, end_point, distance_km, elevation_gain_m) VALUES
  (@event_d_id, 1, DATE_ADD(CURDATE(), INTERVAL 18 DAY), 'Bielsko-Biała', 'Szczyrk', 62.00, 980),
  (@event_d_id, 2, DATE_ADD(CURDATE(), INTERVAL 19 DAY), 'Szczyrk', 'Bielsko-Biała', 58.00, 870);
INSERT INTO event_pricing (event_id, price_amount, currency_item_id, price_unit_item_id) VALUES
  (@event_d_id, 620.00,
   (SELECT id FROM dictionary_items WHERE code='PLN'),
   (SELECT id FROM dictionary_items WHERE code='per_person' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='price_unit')));

-- 3) Darmowa, trudna ustawka gravel w Podkarpaciu, przyszły miesiąc
INSERT INTO events
  (organizer_id, event_type_item_id, status_item_id, title, slug, description,
   difficulty_item_id, pace_group_item_id, region_item_id, min_participants, max_participants,
   meeting_point_address, meeting_point_lat, meeting_point_lng, start_date, published_at)
VALUES
  (@organizer_peer_id,
   (SELECT id FROM dictionary_items WHERE code='ustawka'),
   (SELECT id FROM dictionary_items WHERE code='published' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='event_status')),
   'Ostra jazda gravelem po Podkarpaciu', 'ostra-jazda-gravel-podkarpacie',
   'Wymagająca, szybka trasa gravelowa — tylko dla ogarniętych.',
   (SELECT id FROM dictionary_items WHERE code='trudna' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='difficulty_level')),
   (SELECT id FROM dictionary_items WHERE code='szybkie' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='pace_group')),
   (SELECT id FROM dictionary_items WHERE code='podkarpacie' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='region')),
   4, 12, 'Rynek w Rzeszowie', 50.0412, 21.9991, DATE_ADD(CURDATE(), INTERVAL 40 DAY), NOW());

SET @event_e_id = LAST_INSERT_ID();
INSERT INTO event_bike_types (event_id, bike_type_item_id) VALUES
  (@event_e_id, (SELECT id FROM dictionary_items WHERE code='gravel' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='bike_type')));
INSERT INTO event_stages (event_id, day_number, stage_date, start_point, end_point, distance_km, elevation_gain_m) VALUES
  (@event_e_id, 1, DATE_ADD(CURDATE(), INTERVAL 40 DAY), 'Rynek w Rzeszowie', 'Rynek w Rzeszowie', 95.00, 1100);
