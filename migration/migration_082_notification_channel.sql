-- migration_082_notification_channel.sql
-- KANAŁ E-MAIL W BRAMCE POWIADOMIEŃ (Etap 1c programu zachęt, 2026-09-11).
--
-- ============================================================================
-- PO CO TO JEST
-- ============================================================================
-- Do tej migracji zachęta mogła dotrzeć WYŁĄCZNIE pushem, a push ma tylko ten,
-- kto zainstalował apkę i włączył powiadomienia. `PushNotifier` zaczynał
-- zapytanie od `FROM push_devices ... WHERE is_active = 1`, więc program
-- zachęt fizycznie nie widział nikogo poza tym jednym, już zaangażowanym
-- ułamkiem bazy. E-mail ma każde konto — i to jest cała stawka tej zmiany.
--
-- ============================================================================
-- CO TA MIGRACJA DODAJE I DLACZEGO TAK
-- ============================================================================
-- 1. `notification_log.channel` WCHODZI DO KLUCZA UNIKALNOŚCI. Decyzja usera:
--    push i mail idą RÓWNOLEGLE, nie jako fallback — ta sama zachęta może
--    pójść obydwoma kanałami. Bez kanału w kluczu pierwszy kanał „zużyłby"
--    zdarzenie (`INSERT IGNORE` odrzuciłby drugi wiersz) i druga rura nigdy
--    by nie ruszyła. Kanał w kluczu jest więc warunkiem działania, nie
--    dekoracją.
--
-- 2. INDEKS BUDŻETU DOSTAJE KANAŁ NA DRUGIM MIEJSCU. Druga decyzja usera:
--    limity są LICZONE OSOBNO DLA KAŻDEGO KANAŁU (2/tydzień, 1/dobę per
--    kanał), więc licznik pyta teraz „ile MAILI w tym tygodniu", a nie „ile
--    powiadomień". Kolejność kolumn (user_id, channel, sent_at) odpowiada
--    dokładnie temu zapytaniu.
--
-- 3. TRZY ZGODY MAILOWE, NIEZALEŻNE OD PUSHOWYCH. Decyzja usera z 2026-09-11:
--    zgoda jest per kanał, nie wspólna. Można chcieć nowości o okolicy mailem,
--    a nie chcieć ich pushem — i odwrotnie.
--
--    DOMYŚLNIE WŁĄCZONE, tak samo jak pushowe, i z tego samego powodu:
--    dziennik ma mówić o człowieku prawdę, a brak wiersza w `user_preferences`
--    znaczy w tym systemie „nie dotykałem ustawień", nie „odmawiam".
--    Zabezpieczeniem nie jest tu domyślna odmowa, tylko trzy rzeczy razem:
--    twardy budżet (2/tydzień), wypis jednym kliknięciem w każdym mailu
--    i nagłówek `List-Unsubscribe`, dzięki któremu przycisk „Wypisz się"
--    pojawia się w Gmailu obok nadawcy. `notify_matches` (zgoda na maile
--    o dopasowaniach, domyślnie 0) zostaje nietknięta — jest starsza,
--    świadomie zebrana i nikt jej nam nie odwoła w migracji.
--
-- `DEFAULT 'push'` na kolumnie `channel` nie jest wygodą: wszystkie wiersze,
-- które powstały do dziś, opisują wyłącznie push, więc domyślna wartość mówi
-- o nich prawdę.
--
-- KOLEJNOŚĆ NA PRODUKCJI: 081 MUSI PÓJŚĆ PRZED 082. Migracja 081 nie była
-- dotąd uruchomiona nigdzie poza maszyną deweloperską.
--
-- Lokalnie: mysql -u USER -p ridemorebike2 < migration_082_notification_channel.sql

SET NAMES utf8mb4;

ALTER TABLE user_preferences
  ADD COLUMN mail_messages TINYINT(1) NOT NULL DEFAULT 1 AFTER push_progress,
  ADD COLUMN mail_nearby   TINYINT(1) NOT NULL DEFAULT 1 AFTER mail_messages,
  ADD COLUMN mail_progress TINYINT(1) NOT NULL DEFAULT 1 AFTER mail_nearby;

ALTER TABLE notification_log
  ADD COLUMN channel VARCHAR(10) NOT NULL DEFAULT 'push' AFTER type;

-- Klucz unikalności i indeks budżetu przebudowane, a nie dołożone obok:
-- stary `uniq_notification` bez kanału blokowałby drugi kanał tego samego
-- zdarzenia, czyli dokładnie to, co ta migracja ma umożliwić.
ALTER TABLE notification_log
  DROP INDEX uniq_notification,
  ADD UNIQUE KEY uniq_notification (user_id, type, dedupe_key, channel);

ALTER TABLE notification_log
  DROP INDEX idx_notification_budget,
  ADD KEY idx_notification_budget (user_id, channel, sent_at);
