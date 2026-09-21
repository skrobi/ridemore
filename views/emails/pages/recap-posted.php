<?php
// views/emails/pages/recap-posted.php
// Do organizatora, gdy uczestnik doda relację z wyjazdu. Oczekuje:
// $recipientName (?string), $authorName (string), $eventTitle (string), $link (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><b><?= htmlspecialchars($authorName) ?></b> <?= __('dodał(a) relację z wydarzenia') ?> <b><?= htmlspecialchars($eventTitle) ?></b>.</p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($link, __('Zobacz relację →')) ?></p>
