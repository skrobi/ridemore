<?php
// views/emails/pages/password-reset.php
// Oczekuje: $link (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= __('Cześć,') ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Dostaliśmy prośbę o reset hasła do Twojego konta na ridemore.bike. Kliknij, aby ustawić nowe:') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($link, __('Ustaw nowe hasło →')) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;"><?= __('Link jest ważny 60 minut. Jeśli to nie Ty — zignoruj tę wiadomość, hasło pozostanie bez zmian.') ?></p>
