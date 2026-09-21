<?php
// views/emails/pages/activation.php
// Oczekuje: $link (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= __('Cześć,') ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Kliknij, aby dokończyć rejestrację w ridemore.bike:') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($link, __('Dokończ rejestrację →')) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;"><?= __('Link jest ważny 60 minut. Jeśli to nie Ty — zignoruj tę wiadomość.') ?></p>
