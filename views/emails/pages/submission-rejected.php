<?php
// views/emails/pages/submission-rejected.php
// Oczekuje: $recipientName (?string), $eventTitle (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Zgłoszone przez Ciebie wydarzenie') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('nie zostało zatwierdzone i nie pojawi się na ridemore.bike.') ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;"><?= __('Jeśli uważasz, że to pomyłka, odpowiedz na tego maila — organizator lub administrator wyjaśni szczegóły.') ?></p>
