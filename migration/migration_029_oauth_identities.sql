-- migration_029_oauth_identities.sql
-- Logowanie społecznościowe (Google / Strava) przez league/oauth2-client.
-- Tożsamości OAuth trzymane OSOBNO od users, żeby jeden user mógł mieć podpięte
-- oba providery (i logować się dowolnym), a konto nadal identyfikował e-mail.
-- provider_user_id = 'sub' z Google / id atlety ze Stravy (Strava NIE zwraca
-- e-maila, więc dopasowanie po tym id jest jedyną drogą przy kolejnych logowaniach).

CREATE TABLE user_oauth_identities (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  provider          VARCHAR(20) NOT NULL,      -- 'google' | 'strava'
  provider_user_id  VARCHAR(190) NOT NULL,     -- stały identyfikator usera u providera
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Jedna tożsamość u providera = jedno konto (brak przejęć przez duplikat id).
  UNIQUE KEY uq_provider_identity (provider, provider_user_id),
  -- Jeden link danego providera na konto (nie da się podpiąć dwóch Google do jednego usera).
  UNIQUE KEY uq_user_provider (user_id, provider),
  CONSTRAINT fk_oauth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
