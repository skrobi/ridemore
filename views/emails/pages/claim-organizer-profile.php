<?php
// views/emails/pages/claim-organizer-profile.php
// Oczekuje: $link (string), $organizerName (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= __('Cześć,') ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Ktoś wskazał, że profil organizatora „{nazwa}” na ridemore.bike należy do Ciebie. Jeśli to Ty, kliknij poniżej, żeby dokończyć rejestrację i przejąć ten profil:', ['nazwa' => htmlspecialchars($organizerName)]) ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($link, __('Dokończ rejestrację i przejmij profil →')) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;"><?= __('Link jest ważny bezterminowo, do pierwszego użycia. Jeśli to nie Ty — zignoruj tę wiadomość, nic się nie stanie.') ?></p>
