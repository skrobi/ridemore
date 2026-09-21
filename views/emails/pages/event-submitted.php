<?php
// views/emails/pages/event-submitted.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $organizerName
// (string), $contactName (string), $contactEmail (?string), $eventLink
// (string), $panelLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Ktoś zgłosił nowe wydarzenie {tytul} w imieniu {organizator} — zanim będzie publicznie widoczne, wymaga zatwierdzenia.', ['tytul' => '<b>' . htmlspecialchars($eventTitle) . '</b>', 'organizator' => '<b>' . htmlspecialchars($organizerName) . '</b>']) ?></p>
<p style="font-size:14px;line-height:1.6;color:#6B6A64;"><?= __('Zgłaszający:') ?> <?= htmlspecialchars($contactName) ?><?= $contactEmail ? ' (' . htmlspecialchars($contactEmail) . ')' : '' ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz zgłoszenie →')) ?></p>
<p style="margin:6px 0;"><?= MailTemplate::button($panelLink, __('Przejdź do panelu, żeby zatwierdzić'), false) ?></p>
