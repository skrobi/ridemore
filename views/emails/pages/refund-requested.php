<?php
// views/emails/pages/refund-requested.php
// Oczekuje: $recipientName (?string, organizator), $participantName (string),
// $eventTitle (string), $amountLabel (string), $participantsLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><b><?= htmlspecialchars($participantName) ?></b> <?= __('zrezygnował(a) z udziału w') ?> <b><?= htmlspecialchars($eventTitle) ?></b><?= __('. Uczestnik opłacił') ?> <b><?= htmlspecialchars($amountLabel) ?></b> <?= __('— wykonaj zwrot i potwierdź go w panelu uczestników.') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($participantsLink, __('Zobacz uczestników →')) ?></p>
