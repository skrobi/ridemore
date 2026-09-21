-- migration_067_app_login_tokens.sql
-- MOST LOGOWANIA SPOLECZNOSCIOWEGO DLA APLIKACJI MOBILNEJ (Etap 3 apki).
--
-- POWOD (zgloszenie usera 2026-08-23): „logowanie nie dziala poprawnie
-- w aplikacji (googla). przenosi mnie do strony www autoryzacja sie nie
-- powiodla i zostaje juz na WWW".
--
-- DIAGNOZA: to nie byl blad w kodzie logowania — ten zadzialal poprawnie.
-- Google blokuje OAuth w WebView, wiec Capacitor otwiera accounts.google.com
-- w SYSTEMOWEJ PRZEGLADARCE. Ta ma WLASNE ciasteczka, wiec `oauth2state`
-- zapisany w sesji WebView nie istnieje w sesji przegladarki i weryfikacja
-- CSRF na callbacku slusznie odmawia. Zalogowanie sie w przegladarce nie
-- loguje aplikacji — to dwa osobne swiaty i nic ich nie laczy.
--
-- TA TABELA JEST TYM POLACZENIEM. Po udanym OAuth w przegladarce serwer nie
-- loguje tam nikogo: wystawia JEDNORAZOWY token, przekierowuje na deep link
-- aplikacji, a aplikacja wymienia token na sesje juz w swoim WebView.
--
-- DLACZEGO NIE PROSCIEJ: rozwazane bylo „w apce tylko e-mail i haslo".
-- Odpadlo, bo konta zalozone przez Google NIE MAJA hasla, a konta ze Stravy
-- nie maja nawet adresu e-mail (API Stravy go nie zwraca) — czesc istniejacych
-- uzytkownikow nie miala by JAK wejsc do aplikacji.
--
-- BEZPIECZENSTWO — to jest pelnoprawny mechanizm logowania, wiec:
--   * przechowujemy HASH tokenu (SHA-256), nie sam token. Wyciek bazy nie daje
--     nikomu gotowych kluczy, dokladnie jak przy `password_reset_tokens`.
--   * token zyje MINUTY (`expires_at`), bo jedyne, co ma zdazyc, to przeskok
--     z przegladarki do aplikacji na tym samym telefonie.
--   * jest JEDNORAZOWY — wiersz znika przy uzyciu. Deep link potrafi trafic
--     do logow systemowych Androida, wiec token przechwycony po fakcie musi
--     byc juz bezuzyteczny.
--   * kasuje sie razem z kontem (ON DELETE CASCADE).
--
-- Idempotentna jak pozostale migracje (patrz md/database.md).

CREATE TABLE IF NOT EXISTS app_login_tokens (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash  CHAR(64)     NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    expires_at  DATETIME     NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_login_token (token_hash),
    KEY idx_app_login_expires (expires_at),
    CONSTRAINT fk_app_login_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
