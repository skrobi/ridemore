<?php
// views/emails/pages/payment-confirmed.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $eventLink (string), $discussionLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Twoja wpłata za wydarzenie') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('została potwierdzona — miejsce jest Twoje!') ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 20px;"><?= __('O kolejnych etapach organizator poinformuje mailowo albo w dyskusji pod wydarzeniem.') ?></p>
<p style="margin:0 0 6px;"><?= MailTemplate::button($discussionLink, __('Zobacz dyskusję →')) ?></p>
