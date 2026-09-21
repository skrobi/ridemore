<?php
// views/emails/pages/event-cancelled.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $eventDateLabel (string),
// $eventLink (string), $amountLabel (?string — podane tylko gdy event był płatny,
// wtedy uczestnik miał opłacony/potwierdzony udział i dostaje info o zwrocie).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
$amountLabel = $amountLabel ?? null;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Wydarzenie {tytul} ({data}), na które byłeś/aś zapisany/a, zostało {odwolane} przez organizatora.', ['tytul' => '<b>' . htmlspecialchars($eventTitle) . '</b>', 'data' => htmlspecialchars($eventDateLabel), 'odwolane' => '<b style="color:#D14E1E;">' . __('odwołane') . '</b>']) ?></p>
<?php if ($amountLabel): ?>
<p style="font-size:15px;line-height:1.6;"><?= __('Twoja wpłata ({kwota}) zostanie zwrócona — organizator ma już to zgłoszenie w swoim panelu.', ['kwota' => '<b>' . htmlspecialchars($amountLabel) . '</b>']) ?></p>
<?php endif; ?>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 20px;"><?= __('Przepraszamy za niedogodności.') ?></p>
<p style="margin:0 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz stronę wydarzenia →'), false) ?></p>
