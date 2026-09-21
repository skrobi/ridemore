-- seed.sql
-- Przykładowe dane demonstracyjne, zgodne ze schema.sql.
-- Uruchom po schema.sql: mysql -u USER -p ridemorebike2 < seed.sql

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1. BRAKUJĄCE POZYCJE SŁOWNIKOWE (schema.sql seeduje tylko część)
-- ---------------------------------------------------------

INSERT INTO dictionary_items (dictionary_id, code, name, sort_order) VALUES
  ((SELECT id FROM dictionaries WHERE code='verification_status'), 'unverified', 'Niezweryfikowany', 1),
  ((SELECT id FROM dictionaries WHERE code='verification_status'), 'verified', 'Zweryfikowany', 2),

  ((SELECT id FROM dictionaries WHERE code='currency'), 'PLN', 'Złoty polski', 1),
  ((SELECT id FROM dictionaries WHERE code='currency'), 'EUR', 'Euro', 2),

  ((SELECT id FROM dictionaries WHERE code='price_unit'), 'per_person', 'Za osobę', 1),
  ((SELECT id FROM dictionaries WHERE code='price_unit'), 'per_team', 'Za grupę', 2),

  ((SELECT id FROM dictionaries WHERE code='difficulty_level'), 'latwa', 'Łatwa', 1),
  ((SELECT id FROM dictionaries WHERE code='difficulty_level'), 'srednia', 'Średnia', 2),
  ((SELECT id FROM dictionaries WHERE code='difficulty_level'), 'trudna', 'Trudna', 3),

  ((SELECT id FROM dictionaries WHERE code='pace_group'), 'wolne', 'Wolne (<20 km/h)', 1),
  ((SELECT id FROM dictionaries WHERE code='pace_group'), 'srednie', 'Średnie (22-26 km/h)', 2),
  ((SELECT id FROM dictionaries WHERE code='pace_group'), 'szybkie', 'Szybkie (>28 km/h)', 3),

  ((SELECT id FROM dictionaries WHERE code='surface_type'), 'asfalt', 'Asfalt', 1),
  ((SELECT id FROM dictionaries WHERE code='surface_type'), 'szuter', 'Szuter', 2),
  ((SELECT id FROM dictionaries WHERE code='surface_type'), 'single', 'Singletrack', 3),
  ((SELECT id FROM dictionaries WHERE code='surface_type'), 'gravel', 'Gravel', 4),

  ((SELECT id FROM dictionaries WHERE code='meal_type'), 'sniadanie', 'Śniadanie', 1),
  ((SELECT id FROM dictionaries WHERE code='meal_type'), 'obiad', 'Obiad', 2),
  ((SELECT id FROM dictionaries WHERE code='meal_type'), 'kolacja', 'Kolacja', 3),

  ((SELECT id FROM dictionaries WHERE code='accommodation_type'), 'schronisko', 'Schronisko', 1),
  ((SELECT id FROM dictionaries WHERE code='accommodation_type'), 'pensjonat', 'Pensjonat', 2),
  ((SELECT id FROM dictionaries WHERE code='accommodation_type'), 'hotel', 'Hotel', 3),
  ((SELECT id FROM dictionaries WHERE code='accommodation_type'), 'camping', 'Camping', 4),

  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'potwierdzony', 'Potwierdzony', 1),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'lista_rezerwowa', 'Lista rezerwowa', 2),
  ((SELECT id FROM dictionaries WHERE code='rsvp_status'), 'anulowany', 'Anulowany', 3),

  ((SELECT id FROM dictionaries WHERE code='attendance_source'), 'manual', 'Ręcznie', 1),
  ((SELECT id FROM dictionaries WHERE code='attendance_source'), 'strava', 'Strava', 2);

-- ---------------------------------------------------------
-- 2. UŻYTKOWNICY I ORGANIZATORZY
-- ---------------------------------------------------------

INSERT INTO users (name, email, phone, avatar_url) VALUES
  ('Marek Kowalski', 'marek.kowalski@example.com', '+48600100200', NULL),
  ('Tatry Bike Tours', 'kontakt@tatrybiketours.example.com', '+48600300400', NULL),
  ('Ola Nowak', 'ola.nowak@example.com', NULL, NULL),
  ('Piotr Zieliński', 'piotr.zielinski@example.com', NULL, NULL),
  ('Kasia Wiśniewska', 'kasia.wisniewska@example.com', NULL, NULL);

SET @organizer_peer_id  = (SELECT id FROM users WHERE email='marek.kowalski@example.com');
SET @organizer_pro_id   = (SELECT id FROM users WHERE email='kontakt@tatrybiketours.example.com');
SET @participant1_id    = (SELECT id FROM users WHERE email='ola.nowak@example.com');
SET @participant2_id    = (SELECT id FROM users WHERE email='piotr.zielinski@example.com');
SET @participant3_id    = (SELECT id FROM users WHERE email='kasia.wisniewska@example.com');

INSERT INTO organizer_profiles
  (user_id, slug, organizer_type_item_id, verification_status_item_id, tourism_register_number,
   rating_avg, events_organized_count, attendance_confirmed_rate)
VALUES
  (@organizer_peer_id, 'marek-kowalski',
   (SELECT id FROM dictionary_items WHERE code='peer' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='organizer_type')),
   (SELECT id FROM dictionary_items WHERE code='verified' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='verification_status')),
   NULL, 4.80, 12, 91.50),
  (@organizer_pro_id, 'tatry-bike-tours',
   (SELECT id FROM dictionary_items WHERE code='professional_operator' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='organizer_type')),
   (SELECT id FROM dictionary_items WHERE code='verified' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='verification_status')),
   'TO/2024/00123', 4.95, 47, 97.20);

-- ---------------------------------------------------------
-- 3. WYDARZENIE A — darmowa jednodniowa ustawka
-- ---------------------------------------------------------

INSERT INTO events
  (organizer_id, event_type_item_id, status_item_id, title, slug, description,
   difficulty_item_id, pace_group_item_id, min_participants, max_participants,
   meeting_point_address, meeting_point_lat, meeting_point_lng, start_date, published_at)
VALUES
  (@organizer_peer_id,
   (SELECT id FROM dictionary_items WHERE code='ustawka'),
   (SELECT id FROM dictionary_items WHERE code='published' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='event_status')),
   'Niedzielna ustawka gravel', 'niedzielna-ustawka-gravel',
   'Spokojna, towarzyska przejażdżka gravelowa wokół miasta. Tempo dostosowane do najsłabszego ogniwa, bez ścigania się.',
   (SELECT id FROM dictionary_items WHERE code='srednia' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='difficulty_level')),
   (SELECT id FROM dictionary_items WHERE code='srednie' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='pace_group')),
   5, 20, 'Parking przy hali sportowej, ul. Sportowa 1', 52.229700, 21.012200,
   '2026-08-02', '2026-07-10 09:00:00');

SET @event_a_id = LAST_INSERT_ID();

INSERT INTO event_bike_types (event_id, bike_type_item_id) VALUES
  (@event_a_id, (SELECT id FROM dictionary_items WHERE code='gravel' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='bike_type'))),
  (@event_a_id, (SELECT id FROM dictionary_items WHERE code='mtb' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='bike_type')));

INSERT INTO event_stages
  (event_id, day_number, stage_date, title, start_point, end_point,
   distance_km, elevation_gain_m, surface_item_id, notes)
VALUES
  (@event_a_id, 1, '2026-08-02', NULL, 'Parking przy hali sportowej', 'Parking przy hali sportowej',
   45.30, 380,
   (SELECT id FROM dictionary_items WHERE code='szuter' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='surface_type')),
   'Jedna przerwa na kawę w połowie trasy.');

INSERT INTO event_rsvps (event_id, user_id, status_item_id) VALUES
  (@event_a_id, @participant1_id, (SELECT id FROM dictionary_items WHERE code='potwierdzony' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='rsvp_status'))),
  (@event_a_id, @participant2_id, (SELECT id FROM dictionary_items WHERE code='potwierdzony' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='rsvp_status')));

-- ---------------------------------------------------------
-- 4. WYDARZENIE B — płatna wycieczka wielodniowa
-- ---------------------------------------------------------

INSERT INTO events
  (organizer_id, event_type_item_id, status_item_id, title, slug, description,
   difficulty_item_id, pace_group_item_id, min_participants, max_participants,
   meeting_point_address, meeting_point_lat, meeting_point_lng, start_date, published_at)
VALUES
  (@organizer_pro_id,
   (SELECT id FROM dictionary_items WHERE code='wycieczka_wielodniowa'),
   (SELECT id FROM dictionary_items WHERE code='published' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='event_status')),
   'Wycieczka rowerowa po Tatrach — 3 dni', 'wycieczka-tatry-3-dni',
   'Trzydniowa wyprawa gravelowa dookoła Tatr z noclegami w schroniskach i pełnym wsparciem logistycznym.',
   (SELECT id FROM dictionary_items WHERE code='trudna' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='difficulty_level')),
   (SELECT id FROM dictionary_items WHERE code='srednie' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='pace_group')),
   6, 14, 'Dworzec PKP Zakopane', 49.299900, 19.949000,
   '2026-09-05', '2026-07-01 12:00:00');

SET @event_b_id = LAST_INSERT_ID();

INSERT INTO event_bike_types (event_id, bike_type_item_id) VALUES
  (@event_b_id, (SELECT id FROM dictionary_items WHERE code='gravel' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='bike_type'))),
  (@event_b_id, (SELECT id FROM dictionary_items WHERE code='mtb' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='bike_type')));

INSERT INTO event_stages
  (event_id, day_number, stage_date, title, start_point, end_point,
   distance_km, elevation_gain_m, surface_item_id, notes)
VALUES
  (@event_b_id, 1, '2026-09-05', 'Zakopane → Ždiar', 'Zakopane', 'Ždiar',
   58.40, 1120,
   (SELECT id FROM dictionary_items WHERE code='szuter' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='surface_type')),
   'Przekroczenie granicy przez Łysą Polanę.'),
  (@event_b_id, 2, '2026-09-06', 'Ždiar → Zuberec', 'Ždiar', 'Zuberec',
   72.10, 1450,
   (SELECT id FROM dictionary_items WHERE code='szuter' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='surface_type')),
   'Najdłuższy i najbardziej wymagający etap.'),
  (@event_b_id, 3, '2026-09-07', 'Zuberec → Zakopane', 'Zuberec', 'Zakopane',
   49.80, 690,
   (SELECT id FROM dictionary_items WHERE code='asfalt' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='surface_type')),
   'Powrót doliną, głównie asfalt.');

SET @stage_b1_id = (SELECT id FROM event_stages WHERE event_id=@event_b_id AND day_number=1);
SET @stage_b2_id = (SELECT id FROM event_stages WHERE event_id=@event_b_id AND day_number=2);

INSERT INTO event_stage_accommodations (stage_id, accommodation_type_item_id, name, address) VALUES
  (@stage_b1_id,
   (SELECT id FROM dictionary_items WHERE code='pensjonat' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='accommodation_type')),
   'Penzión pod Muráňom', 'Ždiar 245'),
  (@stage_b2_id,
   (SELECT id FROM dictionary_items WHERE code='schronisko' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='accommodation_type')),
   'Chata Zverovka', 'Zuberec, Zverovka');

INSERT INTO event_stage_meals (stage_id, meal_type_item_id, is_included) VALUES
  (@stage_b1_id, (SELECT id FROM dictionary_items WHERE code='sniadanie' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='meal_type')), TRUE),
  (@stage_b1_id, (SELECT id FROM dictionary_items WHERE code='kolacja' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='meal_type')), TRUE),
  (@stage_b2_id, (SELECT id FROM dictionary_items WHERE code='sniadanie' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='meal_type')), TRUE),
  (@stage_b2_id, (SELECT id FROM dictionary_items WHERE code='kolacja' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='meal_type')), TRUE);

INSERT INTO event_pricing
  (event_id, price_amount, currency_item_id, price_unit_item_id, deposit_amount,
   payment_deadline_days_before, cancellation_policy)
VALUES
  (@event_b_id, 1890.00,
   (SELECT id FROM dictionary_items WHERE code='PLN'),
   (SELECT id FROM dictionary_items WHERE code='per_person' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='price_unit')),
   400.00, 14,
   'Bezzwrotny zadatek. Pełny zwrot pozostałej kwoty przy rezygnacji do 14 dni przed startem.');

INSERT INTO event_price_items (event_id, inclusion_category_item_id, description, is_included, sort_order) VALUES
  (@event_b_id, (SELECT id FROM dictionary_items WHERE code='nocleg'), '2 noclegi ze śniadaniem', TRUE, 1),
  (@event_b_id, (SELECT id FROM dictionary_items WHERE code='wyzywienie'), 'Kolacje w dniach 1-2', TRUE, 2),
  (@event_b_id, (SELECT id FROM dictionary_items WHERE code='transport_bagazu'), 'Transport bagażu między noclegami', TRUE, 3),
  (@event_b_id, (SELECT id FROM dictionary_items WHERE code='przewodnictwo'), 'Przewodnik na całej trasie', TRUE, 4),
  (@event_b_id, (SELECT id FROM dictionary_items WHERE code='ubezpieczenie'), 'Ubezpieczenie NNW/KL', FALSE, 5),
  (@event_b_id, NULL, 'Wypożyczenie roweru', FALSE, 6);

INSERT INTO event_rsvps (event_id, user_id, status_item_id) VALUES
  (@event_b_id, @participant1_id, (SELECT id FROM dictionary_items WHERE code='potwierdzony' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='rsvp_status'))),
  (@event_b_id, @participant3_id, (SELECT id FROM dictionary_items WHERE code='lista_rezerwowa' AND dictionary_id=(SELECT id FROM dictionaries WHERE code='rsvp_status')));

-- ---------------------------------------------------------
-- 5. OPINIE I KOMENTARZE (przykładowe)
-- ---------------------------------------------------------

INSERT INTO event_reviews (event_id, reviewer_user_id, target_user_id, rating, comment) VALUES
  (@event_a_id, @participant1_id, @organizer_peer_id, 5, 'Super tempo, na pewno wracam na kolejną ustawkę.');

INSERT INTO event_comments (event_id, user_id, parent_comment_id, body, is_organizer_reply) VALUES
  (@event_b_id, @participant1_id, NULL, 'Czy trasa jest przejezdna na oponach 40mm?', FALSE);

SET @comment1_id = LAST_INSERT_ID();

INSERT INTO event_comments (event_id, user_id, parent_comment_id, body, is_organizer_reply) VALUES
  (@event_b_id, @organizer_pro_id, @comment1_id, 'Tak, 40mm w zupełności wystarczy, zalecamy jednak oponę bez agresywnego bieżnika na 3. etapie (asfalt).', TRUE);
