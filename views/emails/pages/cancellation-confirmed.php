<?php
// views/emails/pages/cancellation-confirmed.php
// Do UCZESTNIKA, gdy organizator anuluje jego udział na wniosek zgłoszony poza
// platformą (telefon, mail). Oczekuje: $recipientName (?string), $eventTitle
// (string), $refundPending (bool — true gdy było opłacone, zwrot w toku),
// $amountLabel (?string, tylko gdy $refundPending).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Twoja rezygnacja z udziału w') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('została przyjęta przez organizatora.') ?></p>
<?php if ($refundPending): ?>
<p style="font-size:15px;line-height:1.6;"><?= __('Opłacona kwota (') ?><b><?= htmlspecialchars($amountLabel) ?></b><?= __(') zostanie zwrócona — organizator ma już to zgłoszenie w swoim panelu.') ?></p>
<?php endif; ?>
