<?php
// core/config.local.php — SEKRETY I USTAWIENIA POZA GITEM (AUDYT.md §11.1).
// Ten plik NIGDY nie trafia do repozytorium; na prod wgrywany ręcznie.
//
// ============================================================================
// PUSH PRZEZ FCM (2026-09-11)
// ============================================================================
// Klucz konta serwisowego leży POZA katalogiem serwowanym przez Apache:
// DocumentRoot to `C:\xampp\htdocs`, więc `C:\xampp\secrets` jest poza jego
// zasięgiem (sprawdzone żądaniem HTTP — 404, nie zawartość). Klucz w katalogu
// serwowanym broniłby się wyłącznie plikiem .htaccess, czyli jedną linijką
// konfiguracji dzielącą prywatny klucz RS256 od internetu.
//
// DEV ZOSTAJE NA 'log' — I TO NIE JEST OSTROŻNOŚĆ, TYLKO WYMÓG.
// Przy 'fcm' na dev pięć istniejących testów push przestaje przechodzić:
// sprawdzają one, że `sendToUser` dopisuje do `storage/push.log`, a przy
// prawdziwym driverze każdy z nich strzela do Google. Zmierzone 2026-09-11:
// 481/489 zamiast 485/488. Testy pilnują więc, żeby maszyna deweloperska
// nie wysyłała nikomu prawdziwych powiadomień — i dobrze.
//
// ŻEBY SPRAWDZIĆ PRAWDZIWĄ WYSYŁKĘ NA DEV: odkomentuj linię `driver` niżej
// na czas jednego sprawdzenia i zakomentuj ją z powrotem. Poświadczenia
// zostają wpięte zawsze, więc samo przełączenie wystarczy.
$configs['dev']['push']['fcm']['project_id'] = 'ridemorebike-f1ec4';
$configs['dev']['push']['fcm']['service_account_path'] = 'C:/xampp/secrets/firebase-ridemore.json';
// $configs['dev']['push']['driver'] = 'fcm';

// PROD — tu wysyłka ma być prawdziwa. Ścieżka do klucza na serwerze
// (NIE w public_html), wgranego ręcznie przez SFTP.
$configs['prod']['push']['driver'] = 'fcm';
$configs['prod']['push']['fcm']['project_id'] = 'ridemorebike-f1ec4';
$configs['prod']['push']['fcm']['service_account_path'] = '/home/ZMIEN_MNIE/secrets/firebase-ridemore.json';
