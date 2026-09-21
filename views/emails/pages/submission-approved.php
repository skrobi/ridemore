<?php
// views/emails/pages/submission-approved.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $eventLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Zgłoszone przez Ciebie wydarzenie') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('zostało zatwierdzone i jest już publicznie widoczne na ridemore.bike.') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz wydarzenie →')) ?></p>
