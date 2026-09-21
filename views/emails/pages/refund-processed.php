<?php
// views/emails/pages/refund-processed.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $amountLabel (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Twój zwrot za') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('w wysokości') ?> <b><?= htmlspecialchars($amountLabel) ?></b> <?= __('został zrealizowany przez organizatora.') ?></p>
