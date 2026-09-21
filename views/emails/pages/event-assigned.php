<?php
// views/emails/pages/event-assigned.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $adminName
// (string), $eventLink (string), $panelLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><b><?= htmlspecialchars($adminName) ?></b> <?= __('dodał(a) dla Ciebie nowe wydarzenie') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('na ridemore.bike. Jest już opublikowane — możesz je edytować w każdej chwili z panelu.') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz wydarzenie →')) ?></p>
<p style="margin:6px 0;"><?= MailTemplate::button($panelLink, __('Przejdź do panelu'), false) ?></p>
