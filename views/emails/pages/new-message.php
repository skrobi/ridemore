<?php
// views/emails/pages/new-message.php
// Do drugiej strony konwersacji, po każdej nowej wiadomości. Oczekuje:
// $recipientName (?string), $senderName (string), $body (string), $link (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Masz nową wiadomość od') ?> <b><?= htmlspecialchars($senderName) ?></b> <?= __('na ridemore.bike:') ?></p>
<p style="font-size:14px;line-height:1.6;background:#F7F5EF;border-left:3px solid #D14E1E;padding:10px 14px;border-radius:0 8px 8px 0;"><?= nl2br(htmlspecialchars($body)) ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($link, __('Odpowiedz →')) ?></p>
