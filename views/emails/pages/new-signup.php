<?php
// views/emails/pages/new-signup.php
// Do organizatora, gdy ktoś zapisze się/zarezerwuje udział w jego wydarzeniu.
// Oczekuje: $recipientName (?string), $participantName (string), $eventTitle
// (string), $statusLabel (string, np. "potwierdzony udział" / "wstępna
// rezerwacja, oczekuje na wpłatę"), $participantsLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><b><?= htmlspecialchars($participantName) ?></b> <?= __('zapisał(a) się na') ?> <b><?= htmlspecialchars($eventTitle) ?></b> — <?= htmlspecialchars($statusLabel) ?>.</p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($participantsLink, __('Zobacz listę uczestników →')) ?></p>
