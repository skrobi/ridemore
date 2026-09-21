<?php
// views/emails/pages/discussion-question.php
// Do organizatora, gdy ktoś zada NOWE (nie odpowiedź) pytanie pod jego wydarzeniem.
// Oczekuje: $recipientName (?string), $askerName (string), $eventTitle (string),
// $body (string), $link (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><b><?= htmlspecialchars($askerName) ?></b> <?= __('zadał(a) pytanie w dyskusji pod Twoim wydarzeniem') ?> <b><?= htmlspecialchars($eventTitle) ?></b>:</p>
<p style="font-size:14px;line-height:1.6;background:#F7F5EF;border-left:3px solid #D14E1E;padding:10px 14px;border-radius:0 8px 8px 0;"><?= nl2br(htmlspecialchars($body)) ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($link, __('Odpowiedz →')) ?></p>
