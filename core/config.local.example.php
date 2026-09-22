<?php
// Skopiuj ten plik jako `core/config.local.php` i uzupełnij lokalnie.
// `config.local.php` jest ignorowany przez Git. Alternatywnie ustaw te same
// wartości jako zmienne środowiskowe opisane w `core/config.php`.
//
// Nie wpisuj prawdziwych sekretów do tego pliku przykładowego.

// Baza deweloperska.
$configs['dev']['db']['pass'] = '';

// SMTP. Na dev domyślny driver to `log`; włącz `smtp` tylko do świadomego testu.
// $configs['dev']['mail']['driver'] = 'smtp';
$configs['dev']['mail']['host'] = 'smtp.example.test';
$configs['dev']['mail']['password'] = '';

// OAuth i liczniki.
$configs['dev']['oauth']['google']['clientSecret'] = '';
$configs['dev']['devices']['token_key'] = '';
$configs['dev']['devices']['polar']['clientSecret'] = '';
$configs['dev']['garmin']['token_key'] = '';

// Stabilny, losowy klucz HMAC. Jego zmiana unieważnia wysłane wcześniej linki.
$configs['dev']['notifications']['unsubscribe_key'] = '';

// FCM: plik konta serwisowego ma leżeć poza DocumentRoot.
$configs['dev']['push']['fcm']['project_id'] = '';
$configs['dev']['push']['fcm']['service_account_path'] = '';

// Produkcję konfiguruj przez sekrety środowiska/cPanel. Poniższe wpisy są
// jedynie mapą wymaganych wartości dla instalacji bez obsługi env.
$configs['prod']['db']['pass'] = '';
$configs['prod']['mail']['host'] = 'smtp.example.test';
$configs['prod']['mail']['password'] = '';
$configs['prod']['oauth']['google']['clientSecret'] = '';
$configs['prod']['devices']['token_key'] = '';
$configs['prod']['devices']['polar']['clientSecret'] = '';
$configs['prod']['garmin']['token_key'] = '';
$configs['prod']['notifications']['unsubscribe_key'] = '';
$configs['prod']['push']['fcm']['project_id'] = '';
$configs['prod']['push']['fcm']['service_account_path'] = '';
// $configs['prod']['push']['driver'] = 'fcm';
