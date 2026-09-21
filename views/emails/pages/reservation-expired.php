<?php
// views/emails/pages/reservation-expired.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $eventLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Termin płatności za wstępną rezerwację na') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('minął, więc rezerwacja wygasła, a miejsce zostało zwolnione.') ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;"><?= __('Jeśli nadal chcesz jechać, zapisz się ponownie — jeśli są jeszcze wolne miejsca.') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz wydarzenie →')) ?></p>
